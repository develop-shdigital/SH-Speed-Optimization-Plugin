<?php
/**
 * WebP derivatives of JPEG/PNG uploads, created in the background and
 * delivered by rewriting image URLs when the derivative exists.
 *
 * Originals are never modified or deleted. Rollback stops the queue, stops
 * the rewriting and deletes the (regenerable) derivatives and their meta.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ImageOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\ConversionQueue;
use SH\SpeedOptimizer\Assets\Images\DerivativeMap;
use SH\SpeedOptimizer\Assets\Images\ImageConverter;
use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Core\Scheduler;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * WebP images.
 */
final class WebpOptimization extends AbstractOptimization {

	public const CRON_HOOK = 'shso_webp_batch';

	private const LOCK = 'shso_webp_lock';

	/**
	 * Conversion pauses below this much free disk space.
	 */
	public const MIN_FREE_BYTES = 524288000;

	/**
	 * Default cap for the total size of derivatives.
	 */
	public const DEFAULT_MAX_BYTES = 5368709120;

	/**
	 * Attachments queued during this request (saved once at shutdown).
	 *
	 * @var int[]
	 */
	private array $queued = array();

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'webp_images';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Serve images as WebP', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Creates smaller WebP copies of your JPEG and PNG images in the background and shows those copies to visitors. Your original images are never changed.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::IMAGES;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::LOW;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_SMART;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		if ( ! self::server_supports( 'image/webp' ) ) {
			$assessment          = Assessment::make( false, 100, Assessment::BENEFIT_NONE );
			$assessment->blocked = __( 'Your server cannot create WebP images.', 'sh-speed-optimizer' );
			$assessment->note( $assessment->blocked );
			return $this->finalize( $assessment, $context, 'webp' );
		}

		$count = self::library_count();
		if ( null !== $count && 0 === $count ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'Your media library has no JPEG or PNG images.', 'sh-speed-optimizer' ) ),
				$context,
				'webp'
			);
		}

		$benefit = Assessment::BENEFIT_LOW;
		if ( null === $count || $count > 50 ) {
			$benefit = null === $count ? Assessment::BENEFIT_MEDIUM : Assessment::BENEFIT_HIGH;
		} elseif ( $count > 5 ) {
			$benefit = Assessment::BENEFIT_MEDIUM;
		}

		$assessment       = Assessment::make( true, 85, $benefit );
		$assessment->data = array(
			'jpeg_png_attachments' => $count,
			'avif_supported'       => self::server_supports( 'image/avif' ),
		);
		if ( null !== $count ) {
			$assessment->note(
				sprintf(
					/* translators: %d: number of images */
					_n( 'Your media library has %d JPEG or PNG image that can get a smaller WebP copy.', 'Your media library has %d JPEG or PNG images that can get smaller WebP copies.', $count, 'sh-speed-optimizer' ),
					$count
				)
			);
		}
		$assessment->note( __( 'Copies are created in the background; your original images are never changed.', 'sh-speed-optimizer' ) );

		$free = self::free_space();
		if ( null !== $free && $free < self::MIN_FREE_BYTES ) {
			$assessment->penalize( 20, __( 'There is little free disk space; creating copies will pause until more space is available.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'webp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply() {
		( new ConversionQueue() )->start();
		Scheduler::async( self::CRON_HOOK, array(), 15 );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, array(), Scheduler::GROUP );
		}
		( new ConversionQueue() )->reset();
		delete_transient( self::LOCK );

		$this->plugin->filesystem()->delete_tree( Filesystem::uploads_root( false ) . 'webp' );
		if ( function_exists( 'delete_post_meta_by_key' ) ) {
			delete_post_meta_by_key( ImageConverter::META_KEY );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		add_filter( 'shso_cron_hooks', array( self::class, 'cron_hooks' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'queue_attachment' ), 20, 2 );
		add_filter( 'wp_update_attachment_metadata', array( $this, 'queue_attachment' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_attachment' ), 10, 1 );

		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) use ( $runtime ) {
				if ( ! $doc->contains( '<img' ) && ! $doc->contains( 'url(' ) && ! $doc->contains( '<source' ) ) {
					return;
				}
				$rewriter = new WebpRewriter(
					DerivativeMap::from_wordpress(),
					'is_file',
					array_map( 'strval', (array) $runtime->rules()->get( 'image_no_webp' ) ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST ),
					self::avif_delivery()
				);
				$rewriter->transform( $doc );
			},
			20
		);
	}

	/**
	 * Register the cron hook for cleanup on deactivation.
	 *
	 * @param string[] $hooks Hooks.
	 * @return string[]
	 */
	public static function cron_hooks( $hooks ): array {
		$hooks   = (array) $hooks;
		$hooks[] = self::CRON_HOOK;
		return $hooks;
	}

	/**
	 * Queue new or regenerated JPEG/PNG attachments. Returns the metadata unchanged.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param mixed $attachment_id Attachment id.
	 * @return mixed
	 */
	public function queue_attachment( $metadata, $attachment_id = 0 ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id > 0 && is_array( $metadata ) && in_array( (string) get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			if ( empty( $this->queued ) ) {
				add_action( 'shutdown', array( $this, 'flush_queue' ) );
			}
			$this->queued[ $attachment_id ] = $attachment_id;
		}
		return $metadata;
	}

	/**
	 * Save queued attachments once per request and schedule processing.
	 */
	public function flush_queue(): void {
		if ( empty( $this->queued ) ) {
			return;
		}
		( new ConversionQueue() )->enqueue( array_values( $this->queued ) );
		$this->queued = array();
		Scheduler::async( self::CRON_HOOK, array(), 30 );
	}

	/**
	 * Delete derivatives when an attachment is deleted.
	 *
	 * @param mixed $post_id Attachment id.
	 */
	public function delete_attachment( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! in_array( (string) get_post_mime_type( $post_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return;
		}
		$this->converter()->delete_attachment( $post_id );

		$queue = new ConversionQueue();
		$queue->subtract( get_post_meta( $post_id, ImageConverter::META_KEY, true ) );
		$queue->forget( $post_id );
		unset( $this->queued[ $post_id ] );
	}

	/**
	 * Cron: convert a batch of attachments and reschedule while work remains.
	 */
	public function run_batch(): void {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, time(), 2 * MINUTE_IN_SECONDS );

		try {
			$queue = new ConversionQueue();
			if ( ! $this->storage_ok( $queue ) ) {
				Scheduler::async( self::CRON_HOOK, array(), 6 * HOUR_IN_SECONDS );
				return;
			}
			if ( '' !== (string) $queue->state()['paused'] ) {
				$queue->pause( '' );
			}

			// Create the derivative directory with its protection files (no directory listing).
			$this->plugin->filesystem()->uploads_dir( 'webp', true );
			$converter = $this->converter();
			$processed = $queue->run(
				static function ( int $id ) use ( $converter ) {
					return $converter->convert_attachment( $id );
				},
				static function ( int $id ) {
					$meta = get_post_meta( $id, ImageConverter::META_KEY, true );
					return is_array( $meta ) ? $meta : null;
				},
				5,
				20.0,
				function () use ( $queue ) {
					return self::memory_tight() || ! $this->storage_ok( $queue );
				}
			);

			$this->plugin->logger()->debug( 'WebP batch processed.', array( 'attachments' => $processed ), 'images' );

			if ( $queue->has_work() ) {
				$delay = 0 === $processed ? 5 * MINUTE_IN_SECONDS : 30;
				if ( '' !== (string) $queue->state()['paused'] ) {
					$delay = 6 * HOUR_IN_SECONDS;
				}
				Scheduler::async( self::CRON_HOOK, array(), $delay );
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'WebP conversion batch failed.', array( 'error' => $e->getMessage() ), 'images' );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		$queue = new ConversionQueue();
		$state = $queue->state();
		$stats = $state['stats'];

		return array(
			'converted'      => (int) $stats['converted'],
			'attachments'    => (int) $stats['attachments'],
			'pending'        => $queue->remaining(),
			'skipped'        => (int) $stats['skipped'],
			'errors'         => (int) $stats['errors'],
			'bytes_original' => (int) $stats['bytes_original'],
			'bytes_webp'     => (int) $stats['bytes_webp'],
			'bytes_saved'    => max( 0, (int) $stats['bytes_original'] - (int) $stats['bytes_webp'] ),
			'avif_available' => self::server_supports( 'image/avif' ),
			'avif_delivery'  => self::avif_delivery(),
			'paused'         => '' !== (string) $state['paused'],
			'status'         => '' !== (string) $state['note'] ? (string) $state['note'] : ( ! empty( $state['finished'] ) ? __( 'All images are processed.', 'sh-speed-optimizer' ) : __( 'Creating WebP copies in the background.', 'sh-speed-optimizer' ) ),
		);
	}

	/**
	 * Converter for this site.
	 */
	private function converter(): ImageConverter {
		return new ImageConverter(
			$this->plugin->filesystem(),
			DerivativeMap::from_wordpress(),
			82,
			self::avif_delivery() && self::server_supports( 'image/avif' )
		);
	}

	/**
	 * Whether AVIF copies are created and delivered through <picture> (off by default:
	 * wrapping images in <picture> can affect theme CSS).
	 */
	public static function avif_delivery(): bool {
		/**
		 * Filters whether AVIF copies are created and served through <picture> elements.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'shso_avif_delivery', false );
	}

	/**
	 * Whether the image editor can write a MIME type.
	 *
	 * @param string $mime MIME type.
	 */
	public static function server_supports( string $mime ): bool {
		return function_exists( 'wp_image_editor_supports' ) && (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
	}

	/**
	 * Number of JPEG/PNG attachments (null when unavailable).
	 */
	private static function library_count(): ?int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single COUNT during a background scan.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_mime_type IN ( %s, %s )",
				'attachment',
				'image/jpeg',
				'image/png'
			)
		);
		return null === $count ? null : (int) $count;
	}

	/**
	 * Free disk space of the uploads directory (null when unknown).
	 */
	private static function free_space(): ?float {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = (string) $uploads['basedir'];
		while ( '' !== $dir && ! is_dir( $dir ) && dirname( $dir ) !== $dir ) {
			$dir = dirname( $dir );
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled or restricted by open_basedir.
		return false === $free ? null : (float) $free;
	}

	/**
	 * Check disk space and the derivative size cap; pauses the queue with a note when exceeded.
	 *
	 * @param ConversionQueue $queue Queue.
	 */
	private function storage_ok( ConversionQueue $queue ): bool {
		$free = self::free_space();
		if ( null !== $free && $free < self::MIN_FREE_BYTES ) {
			$queue->pause( 'disk', __( 'Paused: less than 500 MB of free disk space. Creating copies continues automatically when space is available.', 'sh-speed-optimizer' ) );
			return false;
		}

		/**
		 * Filters the maximum total size of WebP/AVIF copies in bytes (0 = no limit).
		 *
		 * @param int $bytes Default 5 GB.
		 */
		$cap = (int) apply_filters( 'shso_webp_max_bytes', self::DEFAULT_MAX_BYTES );
		if ( $cap > 0 && (int) $queue->state()['stats']['bytes_webp'] >= $cap ) {
			$queue->pause( 'cap', __( 'Paused: the WebP copies reached the configured storage limit.', 'sh-speed-optimizer' ) );
			return false;
		}

		return true;
	}

	/**
	 * Whether memory usage exceeds 80% of the limit.
	 */
	private static function memory_tight(): bool {
		$limit = ImageConverter::memory_limit();
		return $limit > 0 && memory_get_usage( true ) > 0.8 * $limit;
	}
}
