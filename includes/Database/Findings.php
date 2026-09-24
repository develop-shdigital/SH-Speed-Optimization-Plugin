<?php
/**
 * Turns a database analysis into plain-language findings.
 *
 * Pure: takes the array produced by {@see Analyzer::analyze()} and returns
 * finding arrays for the diagnostics engine. Items whose data is unavailable
 * (null) produce no finding rather than a guess.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Database findings.
 */
final class Findings {

	/**
	 * Autoloaded settings above this size are a warning (800 KB).
	 */
	public const AUTOLOAD_WARNING = 819200;

	/**
	 * Autoloaded settings above this size are critical (2 MB).
	 */
	public const AUTOLOAD_CRITICAL = 2097152;

	/**
	 * Unused space in MyISAM tables worth mentioning (50 MB).
	 */
	public const OVERHEAD_NOTICE = 52428800;

	/**
	 * Count thresholds per item: [ notice, warning ] (null = never that severe).
	 */
	public const THRESHOLDS = array(
		'revisions'          => array( 500, 5000 ),
		'auto_drafts'        => array( 1000, null ),
		'trashed_posts'      => array( 1000, null ),
		'spam_comments'      => array( 1000, 10000 ),
		'trashed_comments'   => array( 1000, null ),
		'expired_transients' => array( 500, 5000 ),
		'orphaned_meta'      => array( 5000, 50000 ),
	);

	/**
	 * Build findings.
	 *
	 * @param array<string,mixed> $analysis Analysis.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build( array $analysis ): array {
		$cleanup = array_filter(
			array(
				self::revisions( $analysis['revisions'] ?? null ),
				self::auto_drafts( $analysis['auto_drafts'] ?? null ),
				self::trashed_posts( $analysis['trashed_posts'] ?? null ),
				self::spam_comments( $analysis['spam_comments'] ?? null ),
				self::trashed_comments( $analysis['trashed_comments'] ?? null ),
				self::expired_transients( $analysis['expired_transients'] ?? null ),
				self::orphaned_meta( $analysis ),
			)
		);

		$findings = array_values( $cleanup );

		$tidy = self::tidy( $analysis, $findings );
		if ( null !== $tidy ) {
			$findings[] = $tidy;
		}

		foreach ( array( self::relationships( $analysis['orphaned_relationships'] ?? null ), self::autoload( $analysis['autoload'] ?? null ) ) as $finding ) {
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}

		foreach ( self::tables( $analysis['tables'] ?? null ) as $finding ) {
			$findings[] = $finding;
		}

		return $findings;
	}

	/**
	 * Severity for a count.
	 *
	 * @param int                     $count      Count.
	 * @param array{0:int,1:int|null} $thresholds [ notice, warning ].
	 */
	public static function severity_for_count( int $count, array $thresholds ): string {
		if ( null !== $thresholds[1] && $count >= $thresholds[1] ) {
			return 'warning';
		}
		if ( $count >= $thresholds[0] ) {
			return 'notice';
		}
		return 'info';
	}

	/**
	 * Severity of the autoloaded settings size.
	 *
	 * @param int $bytes Bytes.
	 */
	public static function autoload_severity( int $bytes ): string {
		if ( $bytes > self::AUTOLOAD_CRITICAL ) {
			return 'critical';
		}
		if ( $bytes > self::AUTOLOAD_WARNING ) {
			return 'warning';
		}
		return 'good';
	}

	/**
	 * Human readable size ("820 KB", "1.3 MB").
	 *
	 * @param int $bytes Bytes.
	 */
	public static function format_bytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$value = (float) max( 0, $bytes );
		$unit  = 0;
		$last  = count( $units ) - 1;
		while ( $value >= 1024 && $unit < $last ) {
			$value /= 1024;
			++$unit;
		}
		$decimals = ( $unit >= 2 && $value < 100 ) ? 1 : 0;
		$number   = function_exists( 'number_format_i18n' ) ? number_format_i18n( $value, $decimals ) : number_format( $value, $decimals );
		return $number . ' ' . $units[ $unit ];
	}

	/**
	 * Localized integer.
	 *
	 * @param int $number Number.
	 */
	public static function number( int $number ): string {
		return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( $number ) : number_format( $number );
	}

	/**
	 * Old revisions.
	 *
	 * @param mixed $stats Revision statistics.
	 * @return array<string,mixed>|null
	 */
	private static function revisions( $stats ): ?array {
		if ( ! is_array( $stats ) || null === ( $stats['removable'] ?? null ) || (int) $stats['removable'] <= 0 ) {
			return null;
		}

		$total     = (int) $stats['total'];
		$removable = (int) $stats['removable'];
		$posts     = (int) ( $stats['posts_over_keep'] ?? 0 );
		$keep      = (int) ( $stats['keep'] ?? 5 );

		$description = sprintf(
			/* translators: 1: number of posts, 2: number of kept revisions */
			_n(
				'WordPress keeps a copy every time content is saved. %1$s post has more than %2$d saved versions.',
				'WordPress keeps a copy every time content is saved. %1$s posts have more than %2$d saved versions.',
				$posts,
				'sh-speed-optimizer'
			),
			self::number( $posts ),
			$keep
		) . ' ' . sprintf(
			/* translators: %s: number of revisions */
			_n(
				'%s old copy can be removed. Old copies make the database larger and backups slower.',
				'%s old copies can be removed. Old copies make the database larger and backups slower.',
				$removable,
				'sh-speed-optimizer'
			),
			self::number( $removable )
		);

		return self::finding(
			'db_revisions',
			self::severity_for_count( $removable, self::THRESHOLDS['revisions'] ),
			sprintf(
				/* translators: %s: number of revisions */
				_n( 'Your database stores %s old post revision.', 'Your database stores %s old post revisions.', $total, 'sh-speed-optimizer' ),
				self::number( $total )
			),
			$description,
			sprintf(
				/* translators: %d: number of revisions kept per post */
				_n(
					'Clean up old revisions (the %d most recent per post is kept). A backup is created first.',
					'Clean up old revisions (the %d most recent per post are kept). A backup is created first.',
					$keep,
					'sh-speed-optimizer'
				),
				$keep
			),
			array(
				'total'           => $total,
				'removable'       => $removable,
				'posts_over_keep' => $posts,
				'autosaves'       => $stats['autosaves'] ?? null,
				'keep'            => $keep,
				'clean_items'     => array( 'revisions' ),
			)
		);
	}

	/**
	 * Unused automatic drafts.
	 *
	 * @param mixed $count Count.
	 * @return array<string,mixed>|null
	 */
	private static function auto_drafts( $count ): ?array {
		if ( ! is_int( $count ) || $count <= 0 ) {
			return null;
		}
		return self::finding(
			'db_auto_drafts',
			self::severity_for_count( $count, self::THRESHOLDS['auto_drafts'] ),
			sprintf(
				/* translators: %s: number of drafts */
				_n( '%s unused automatic draft was found.', '%s unused automatic drafts were found.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			__( 'WordPress creates an automatic draft whenever the editor is opened for new content. Drafts older than two days that were never saved are no longer needed.', 'sh-speed-optimizer' ),
			__( 'Remove unused automatic drafts. A backup is created first.', 'sh-speed-optimizer' ),
			array(
				'count'         => $count,
				'min_age_hours' => (int) ( Criteria::AUTO_DRAFT_MIN_AGE / 3600 ),
				'clean_items'   => array( 'auto_drafts' ),
			)
		);
	}

	/**
	 * Trashed posts.
	 *
	 * @param mixed $count Count.
	 * @return array<string,mixed>|null
	 */
	private static function trashed_posts( $count ): ?array {
		if ( ! is_int( $count ) || $count <= 0 ) {
			return null;
		}
		return self::finding(
			'db_trashed_posts',
			self::severity_for_count( $count, self::THRESHOLDS['trashed_posts'] ),
			sprintf(
				/* translators: %s: number of items */
				_n( '%s item is in the trash.', '%s items are in the trash.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			__( 'Trashed posts and pages stay in the database, together with their custom fields and comments, until the trash is emptied.', 'sh-speed-optimizer' ),
			__( 'Empty the trash permanently. A backup is created first, so the items can be restored. Media files and shop orders are never deleted by this cleanup.', 'sh-speed-optimizer' ),
			array(
				'count'       => $count,
				'clean_items' => array( 'trashed_posts' ),
			)
		);
	}

	/**
	 * Spam comments.
	 *
	 * @param mixed $count Count.
	 * @return array<string,mixed>|null
	 */
	private static function spam_comments( $count ): ?array {
		if ( ! is_int( $count ) || $count <= 0 ) {
			return null;
		}
		return self::finding(
			'db_spam_comments',
			self::severity_for_count( $count, self::THRESHOLDS['spam_comments'] ),
			sprintf(
				/* translators: %s: number of comments */
				_n( '%s spam comment is stored.', '%s spam comments are stored.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			__( 'Comments marked as spam are kept in the database until they are deleted. They are never shown to visitors but make the comments table larger.', 'sh-speed-optimizer' ),
			__( 'Delete spam comments permanently. A backup is created first.', 'sh-speed-optimizer' ),
			array(
				'count'       => $count,
				'clean_items' => array( 'spam_comments' ),
			)
		);
	}

	/**
	 * Trashed comments.
	 *
	 * @param mixed $count Count.
	 * @return array<string,mixed>|null
	 */
	private static function trashed_comments( $count ): ?array {
		if ( ! is_int( $count ) || $count <= 0 ) {
			return null;
		}
		return self::finding(
			'db_trashed_comments',
			self::severity_for_count( $count, self::THRESHOLDS['trashed_comments'] ),
			sprintf(
				/* translators: %s: number of comments */
				_n( '%s comment is in the trash.', '%s comments are in the trash.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			__( 'Trashed comments stay in the database until the trash is emptied.', 'sh-speed-optimizer' ),
			__( 'Delete trashed comments permanently. A backup is created first.', 'sh-speed-optimizer' ),
			array(
				'count'       => $count,
				'clean_items' => array( 'trashed_comments' ),
			)
		);
	}

	/**
	 * Expired transients.
	 *
	 * @param mixed $count Count.
	 * @return array<string,mixed>|null
	 */
	private static function expired_transients( $count ): ?array {
		if ( ! is_int( $count ) || $count <= 0 ) {
			return null;
		}
		return self::finding(
			'db_expired_transients',
			self::severity_for_count( $count, self::THRESHOLDS['expired_transients'] ),
			sprintf(
				/* translators: %s: number of entries */
				_n( 'Your database holds %s expired temporary cache entry.', 'Your database holds %s expired temporary cache entries.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			__( 'Plugins store temporary data (called transients) with an expiry date. These entries have expired but are still stored.', 'sh-speed-optimizer' ),
			__( 'Remove expired temporary data. Entries that are still valid are not touched, and a backup is created first.', 'sh-speed-optimizer' ),
			array(
				'count'       => $count,
				'clean_items' => array( 'expired_transients' ),
			)
		);
	}

	/**
	 * Orphaned post/comment/term meta.
	 *
	 * @param array<string,mixed> $analysis Analysis.
	 * @return array<string,mixed>|null
	 */
	private static function orphaned_meta( array $analysis ): ?array {
		$parts = array();
		foreach ( array( 'orphaned_postmeta', 'orphaned_commentmeta', 'orphaned_termmeta' ) as $key ) {
			if ( is_int( $analysis[ $key ] ?? null ) && $analysis[ $key ] > 0 ) {
				$parts[ $key ] = $analysis[ $key ];
			}
		}
		if ( empty( $parts ) ) {
			return null;
		}

		$total = array_sum( $parts );
		$list  = array();
		$names = array(
			/* translators: %s: number of entries */
			'orphaned_postmeta'    => __( '%s for posts', 'sh-speed-optimizer' ),
			/* translators: %s: number of entries */
			'orphaned_commentmeta' => __( '%s for comments', 'sh-speed-optimizer' ),
			/* translators: %s: number of entries */
			'orphaned_termmeta'    => __( '%s for categories and tags', 'sh-speed-optimizer' ),
		);
		foreach ( $parts as $key => $count ) {
			$list[] = sprintf( $names[ $key ], self::number( $count ) );
		}

		return self::finding(
			'db_orphaned_meta',
			self::severity_for_count( $total, self::THRESHOLDS['orphaned_meta'] ),
			sprintf(
				/* translators: %s: number of entries */
				_n( '%s stored detail belongs to content that no longer exists.', '%s stored details belong to content that no longer exists.', $total, 'sh-speed-optimizer' ),
				self::number( $total )
			),
			sprintf(
				/* translators: %s: list such as "120 for posts, 4 for comments" */
				__( 'These custom field entries (%s) point to items that were deleted. They are left behind by plugins or interrupted deletions and are never used again.', 'sh-speed-optimizer' ),
				implode( ', ', $list )
			),
			__( 'Remove the leftover entries. A backup is created first.', 'sh-speed-optimizer' ),
			array(
				'count'       => $total,
				'postmeta'    => $analysis['orphaned_postmeta'] ?? null,
				'commentmeta' => $analysis['orphaned_commentmeta'] ?? null,
				'termmeta'    => $analysis['orphaned_termmeta'] ?? null,
				'clean_items' => array_keys( $parts ),
			)
		);
	}

	/**
	 * Relationship candidates (report only).
	 *
	 * @param mixed $count Count.
	 * @return array<string,mixed>|null
	 */
	private static function relationships( $count ): ?array {
		if ( ! is_int( $count ) || $count <= 0 ) {
			return null;
		}
		return self::finding(
			'db_orphaned_relationships',
			'info',
			sprintf(
				/* translators: %s: number of links */
				_n( '%s category or tag link may point to content that no longer exists.', '%s category or tag links may point to content that no longer exists.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			__( 'These links connect categories or tags to items that could not be found among your posts and pages.', 'sh-speed-optimizer' ),
			__( 'No action is needed. They are shown for information only and are never cleaned automatically, because some plugins connect categories to other kinds of content.', 'sh-speed-optimizer' ),
			array(
				'candidates'  => $count,
				'clean_items' => array(),
			)
		);
	}

	/**
	 * "Database is tidy" when no cleanup item is more than informational.
	 *
	 * @param array<string,mixed>            $analysis Analysis.
	 * @param array<int,array<string,mixed>> $cleanup  Cleanup findings.
	 * @return array<string,mixed>|null
	 */
	private static function tidy( array $analysis, array $cleanup ): ?array {
		$counts = array(
			$analysis['revisions']['removable'] ?? null,
			$analysis['auto_drafts'] ?? null,
			$analysis['trashed_posts'] ?? null,
			$analysis['spam_comments'] ?? null,
			$analysis['trashed_comments'] ?? null,
			$analysis['expired_transients'] ?? null,
			$analysis['orphaned_postmeta'] ?? null,
			$analysis['orphaned_commentmeta'] ?? null,
			$analysis['orphaned_termmeta'] ?? null,
		);
		$known  = array_filter( $counts, 'is_int' );
		if ( empty( $known ) ) {
			return null; // Nothing could be measured.
		}
		foreach ( $cleanup as $finding ) {
			if ( 'info' !== $finding['severity'] ) {
				return null;
			}
		}

		$total = array_sum( $known );
		return self::finding(
			'db_tidy',
			'good',
			__( 'Your database is tidy.', 'sh-speed-optimizer' ),
			0 === $total
				? __( 'No old revisions, spam, trash or expired temporary data were found.', 'sh-speed-optimizer' )
				: sprintf(
					/* translators: %s: number of entries */
					_n( 'Only a small amount of old data was found (%s entry).', 'Only a small amount of old data was found (%s entries).', $total, 'sh-speed-optimizer' ),
					self::number( $total )
				),
			'',
			array( 'leftovers' => $total )
		);
	}

	/**
	 * Autoloaded settings.
	 *
	 * @param mixed $autoload Autoload data.
	 * @return array<string,mixed>|null
	 */
	private static function autoload( $autoload ): ?array {
		if ( ! is_array( $autoload ) || ! isset( $autoload['total_bytes'] ) ) {
			return null;
		}

		$bytes    = (int) $autoload['total_bytes'];
		$count    = (int) ( $autoload['count'] ?? 0 );
		$severity = self::autoload_severity( $bytes );
		$data     = array(
			'total_bytes' => $bytes,
			'count'       => $count,
			'top'         => array_values( (array) ( $autoload['top'] ?? array() ) ),
		);

		if ( 'good' === $severity ) {
			return self::finding(
				'db_autoload',
				'good',
				sprintf(
					/* translators: %s: size such as "320 KB" */
					__( 'Settings loaded on every page are small (%s).', 'sh-speed-optimizer' ),
					self::format_bytes( $bytes )
				),
				sprintf(
					/* translators: 1: number of settings, 2: size */
					_n( 'WordPress reads %1$s setting (%2$s) on every page load, which is a healthy amount.', 'WordPress reads %1$s settings (%2$s) on every page load, which is a healthy amount.', $count, 'sh-speed-optimizer' ),
					self::number( $count ),
					self::format_bytes( $bytes )
				),
				'',
				$data
			);
		}

		$largest = array();
		foreach ( array_slice( $data['top'], 0, 5 ) as $option ) {
			$source    = (string) ( $option['source'] ?? '' );
			$largest[] = '' === $source
				? sprintf( '%1$s (%2$s)', (string) $option['name'], self::format_bytes( (int) $option['bytes'] ) )
				: sprintf( '%1$s (%2$s, %3$s)', (string) $option['name'], $source, self::format_bytes( (int) $option['bytes'] ) );
		}

		$description = __( 'WordPress loads these "autoloaded" settings from the database on every request, even when a page does not need them. This slows down every uncached page view.', 'sh-speed-optimizer' );
		if ( ! empty( $largest ) ) {
			$description .= ' ' . sprintf(
				/* translators: %s: list of settings with owner and size */
				__( 'The largest entries are: %s.', 'sh-speed-optimizer' ),
				implode( '; ', $largest )
			);
		}

		return self::finding(
			'db_autoload',
			$severity,
			sprintf(
				/* translators: %s: size such as "1.3 MB" */
				__( 'Every page load reads %s of settings.', 'sh-speed-optimizer' ),
				self::format_bytes( $bytes )
			),
			$description,
			__( 'Check the plugins behind the largest entries: many have an option to clean up their stored data, and leftovers of removed plugins can often be deleted with their own clean-up tool. Settings are never deleted automatically because that could break a plugin.', 'sh-speed-optimizer' ),
			$data
		);
	}

	/**
	 * Table size findings.
	 *
	 * @param mixed $tables Tables data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function tables( $tables ): array {
		if ( ! is_array( $tables ) || empty( $tables['available'] ) || ! is_int( $tables['total_bytes'] ?? null ) ) {
			return array();
		}

		$findings = array();
		$largest  = array();
		foreach ( array_slice( (array) ( $tables['largest'] ?? array() ), 0, 3 ) as $table ) {
			$largest[] = sprintf( '%1$s (%2$s)', (string) $table['name'], self::format_bytes( (int) $table['bytes'] ) );
		}

		$findings[] = self::finding(
			'db_size',
			'info',
			sprintf(
				/* translators: %s: size such as "245 MB" */
				__( 'Your database uses %s.', 'sh-speed-optimizer' ),
				self::format_bytes( $tables['total_bytes'] )
			),
			empty( $largest ) ? '' : sprintf(
				/* translators: %s: list of tables with sizes */
				__( 'The largest tables are %s.', 'sh-speed-optimizer' ),
				implode( ', ', $largest )
			),
			'',
			array(
				'total_bytes' => $tables['total_bytes'],
				'count'       => $tables['count'] ?? null,
				'largest'     => $tables['largest'] ?? array(),
				'engines'     => $tables['engines'] ?? array(),
			)
		);

		$overhead = $tables['overhead_bytes'] ?? null;
		if ( is_int( $overhead ) && $overhead >= self::OVERHEAD_NOTICE ) {
			$findings[] = self::finding(
				'db_overhead',
				'notice',
				sprintf(
					/* translators: %s: size */
					__( '%s of unused space could be reclaimed in your database tables.', 'sh-speed-optimizer' ),
					self::format_bytes( $overhead )
				),
				__( 'Tables in the older MyISAM format keep the space of deleted rows until they are optimized.', 'sh-speed-optimizer' ),
				__( 'Your hosting control panel (for example phpMyAdmin) can "optimize" these tables. It is safe but locks each table for a moment, so do it at a quiet time.', 'sh-speed-optimizer' ),
				array( 'overhead_bytes' => $overhead )
			);
		}

		$myisam = 0;
		foreach ( (array) ( $tables['engines'] ?? array() ) as $engine => $count ) {
			if ( in_array( strtolower( (string) $engine ), array( 'myisam', 'aria' ), true ) ) {
				$myisam += (int) $count;
			}
		}
		if ( $myisam > 0 ) {
			$findings[] = self::finding(
				'db_myisam',
				'info',
				sprintf(
					/* translators: %d: number of tables */
					_n( '%d database table uses the older MyISAM format.', '%d database tables use the older MyISAM format.', $myisam, 'sh-speed-optimizer' ),
					$myisam
				),
				__( 'MyISAM tables cannot undo a change that was interrupted and are more easily damaged by crashes. Database cleanups still create a backup first and delete in small batches.', 'sh-speed-optimizer' ),
				__( 'Ask your host to convert these tables to InnoDB, which is faster and more robust.', 'sh-speed-optimizer' ),
				array( 'tables' => $myisam )
			);
		}

		return $findings;
	}

	/**
	 * Finding array in the shape the diagnostics engine merges.
	 *
	 * @param string              $id             Id.
	 * @param string              $severity       good|info|notice|warning|critical.
	 * @param string              $title          Title.
	 * @param string              $description    Description.
	 * @param string              $recommendation Recommendation.
	 * @param array<string,mixed> $data           Data.
	 * @return array<string,mixed>
	 */
	private static function finding( string $id, string $severity, string $title, string $description, string $recommendation, array $data ): array {
		return array(
			'id'             => $id,
			'category'       => 'database',
			'severity'       => $severity,
			'title'          => $title,
			'description'    => $description,
			'recommendation' => $recommendation,
			'optimization'   => null,
			'data'           => $data,
			'source'         => 'scan',
		);
	}
}
