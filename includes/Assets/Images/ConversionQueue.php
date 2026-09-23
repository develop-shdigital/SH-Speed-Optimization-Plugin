<?php
/**
 * Background queue for WebP conversion.
 *
 * Walks the media library with an ID cursor (never loads all attachment IDs
 * at once) and processes new uploads first. State lives in one
 * non-autoloaded option. The batch loop respects a time budget and stops
 * early when memory gets tight. A crash while converting one attachment
 * (fatal error in an image library) is detected on the next run and that
 * attachment is skipped instead of being retried forever.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Images;

defined( 'ABSPATH' ) || exit;

/**
 * Conversion queue.
 */
final class ConversionQueue {

	public const OPTION = 'shso_webp_queue';

	/**
	 * Maximum number of queued new uploads.
	 */
	public const MAX_PENDING = 1000;

	/**
	 * Fetcher: function( int $after_id, int $limit ): int[] (ascending attachment IDs).
	 *
	 * @var callable
	 */
	private $fetch;

	/**
	 * Persisted state.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $state = null;

	/**
	 * Constructor.
	 *
	 * @param callable|null $fetch Fetcher; defaults to a prepared $wpdb query.
	 */
	public function __construct( ?callable $fetch = null ) {
		$this->fetch = $fetch ?? array( self::class, 'fetch_from_database' );
	}

	/**
	 * Blank state.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank(): array {
		return array(
			'cursor'    => 0,
			'scan_done' => false,
			'pending'   => array(),
			'current'   => 0,
			'paused'    => '',
			'note'      => '',
			'stats'     => array(
				'attachments'    => 0,
				'converted'      => 0,
				'skipped'        => 0,
				'errors'         => 0,
				'bytes_original' => 0,
				'bytes_webp'     => 0,
			),
			'started'   => 0,
			'updated'   => 0,
			'finished'  => 0,
		);
	}

	/**
	 * Current state.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		if ( null === $this->state ) {
			$raw         = get_option( self::OPTION, array() );
			$raw         = is_array( $raw ) ? $raw : array();
			$this->state = array_merge( self::blank(), $raw );

			$this->state['stats'] = array_merge( self::blank()['stats'], is_array( $raw['stats'] ?? null ) ? $raw['stats'] : array() );
		}
		return $this->state;
	}

	/**
	 * Persist the state.
	 *
	 * @param array<string,mixed> $state State.
	 */
	public function save( array $state ): void {
		$state['updated'] = time();
		$this->state      = $state;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Start (or restart) a full pass over the media library. Statistics are kept.
	 */
	public function start(): void {
		$state              = $this->state();
		$state['cursor']    = 0;
		$state['scan_done'] = false;
		$state['current']   = 0;
		$state['paused']    = '';
		$state['note']      = '';
		$state['started']   = time();
		$state['finished']  = 0;
		$this->save( $state );
	}

	/**
	 * Forget everything.
	 */
	public function reset(): void {
		$this->state = null;
		delete_option( self::OPTION );
	}

	/**
	 * Queue attachments (new uploads, regenerated metadata).
	 *
	 * @param int[] $ids Attachment ids.
	 */
	public function enqueue( array $ids ): void {
		$state   = $this->state();
		$pending = array_map( 'intval', (array) $state['pending'] );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $pending, true ) ) {
				$pending[] = $id;
			}
		}
		$state['pending']  = array_slice( $pending, -self::MAX_PENDING );
		$state['finished'] = 0;
		$this->save( $state );
	}

	/**
	 * Remove an attachment from the pending list.
	 *
	 * @param int $id Attachment id.
	 */
	public function forget( int $id ): void {
		$state            = $this->state();
		$state['pending'] = array_values( array_diff( array_map( 'intval', (array) $state['pending'] ), array( $id ) ) );
		$this->save( $state );
	}

	/**
	 * Pause with a reason code and a translated note.
	 *
	 * @param string $code Code (disk|cap|…), '' to resume.
	 * @param string $note Note.
	 */
	public function pause( string $code, string $note = '' ): void {
		$state           = $this->state();
		$state['paused'] = $code;
		$state['note']   = $note;
		$this->save( $state );
	}

	/**
	 * Whether work remains.
	 */
	public function has_work(): bool {
		$state = $this->state();
		return ! empty( $state['pending'] ) || ! $state['scan_done'];
	}

	/**
	 * Process a batch.
	 *
	 * @param callable $convert     function( int $id ): array — returns the new meta record.
	 * @param callable $previous    function( int $id ): mixed — returns the previous meta record (for statistics).
	 * @param int      $batch       Maximum attachments.
	 * @param float    $budget      Seconds.
	 * @param callable $should_stop function(): bool — memory/disk guard checked before each attachment.
	 * @return int Processed attachments.
	 */
	public function run( callable $convert, callable $previous, int $batch = 5, float $budget = 20.0, ?callable $should_stop = null ): int {
		$start = microtime( true );
		$state = $this->state();

		// The previous run died while converting this attachment: skip it.
		if ( ! empty( $state['current'] ) ) {
			$crashed          = (int) $state['current'];
			$state['current'] = 0;
			$state['pending'] = array_values( array_diff( array_map( 'intval', (array) $state['pending'] ), array( $crashed ) ) );
			$state['cursor']  = max( (int) $state['cursor'], $crashed );
			++$state['stats']['errors'];
			$this->save( $state );
		}

		$processed = 0;
		while ( $processed < $batch && ( microtime( true ) - $start ) < $budget ) {
			if ( null !== $should_stop && $should_stop() ) {
				break;
			}

			list( $id, $from_scan ) = $this->next( $state );
			if ( 0 === $id ) {
				break;
			}

			// Mark as in progress before touching the image library.
			$state['current'] = $id;
			$this->save( $state );

			$old = $previous( $id );
			$new = (array) $convert( $id );

			$state            = $this->state();
			$state['current'] = 0;
			if ( $from_scan ) {
				$state['cursor'] = max( (int) $state['cursor'], $id );
			}
			$state['stats'] = self::apply_delta( $state['stats'], ImageConverter::totals( $old ), ImageConverter::totals( $new ), ! is_array( $old ) );
			$this->save( $state );

			++$processed;
		}

		if ( ! $this->has_work() && empty( $this->state()['finished'] ) ) {
			$state             = $this->state();
			$state['finished'] = time();
			$this->save( $state );
		}

		return $processed;
	}

	/**
	 * Remove an attachment's numbers from the statistics (deleted attachment).
	 *
	 * @param mixed $record Previous meta record.
	 */
	public function subtract( $record ): void {
		if ( ! is_array( $record ) ) {
			return;
		}
		$state          = $this->state();
		$state['stats'] = self::apply_delta( $state['stats'], ImageConverter::totals( $record ), ImageConverter::totals( null ), false );
		$state['stats']['attachments'] = max( 0, (int) $state['stats']['attachments'] - 1 );
		$this->save( $state );
	}

	/**
	 * Next attachment: pending uploads first, then the library cursor.
	 *
	 * @param array<string,mixed> $state State (updated in place when the pending list is shifted).
	 * @return array{0:int,1:bool} Id (0 = none) and whether it comes from the library pass.
	 */
	private function next( array &$state ): array {
		$pending = array_map( 'intval', (array) $state['pending'] );
		if ( ! empty( $pending ) ) {
			$id               = (int) array_shift( $pending );
			$state['pending'] = $pending;
			$this->save( $state );
			return array( $id, false );
		}

		if ( $state['scan_done'] ) {
			return array( 0, false );
		}

		$ids = array_map( 'intval', (array) ( $this->fetch )( (int) $state['cursor'], 1 ) );
		if ( empty( $ids ) ) {
			$state['scan_done'] = true;
			$this->save( $state );
			return array( 0, false );
		}

		return array( (int) $ids[0], true );
	}

	/**
	 * Apply a statistics delta.
	 *
	 * @param array<string,int> $stats   Stats.
	 * @param array<string,int> $old     Old totals.
	 * @param array<string,int> $new     New totals.
	 * @param bool              $is_new  Attachment processed for the first time.
	 * @return array<string,int>
	 */
	private static function apply_delta( array $stats, array $old, array $new, bool $is_new ): array {
		foreach ( array( 'converted', 'skipped', 'errors', 'bytes_original', 'bytes_webp' ) as $key ) {
			$stats[ $key ] = max( 0, (int) ( $stats[ $key ] ?? 0 ) - (int) $old[ $key ] + (int) $new[ $key ] );
		}
		if ( $is_new ) {
			$stats['attachments'] = (int) ( $stats['attachments'] ?? 0 ) + 1;
		}
		return $stats;
	}

	/**
	 * Default fetcher: next JPEG/PNG attachment IDs after a cursor.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Limit.
	 * @return int[]
	 */
	public static function fetch_from_database( int $after_id, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor paging over the media library in a background job.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ( 'image/jpeg', 'image/png' ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				max( 1, $limit )
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of library attachments after the cursor (plus pending uploads).
	 */
	public function remaining(): int {
		global $wpdb;
		$state   = $this->state();
		$pending = count( (array) $state['pending'] );
		if ( $state['scan_done'] || ! isset( $wpdb ) ) {
			return $pending;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard status count.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ( 'image/jpeg', 'image/png' ) AND ID > %d",
				(int) $state['cursor']
			)
		);
		return $pending + $count;
	}
}
