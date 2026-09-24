<?php
/**
 * Selection criteria of every cleanup item.
 *
 * The analyzer counts and the cleaner selects rows with exactly the same
 * conditions, so the number shown to the administrator is the number of rows
 * a cleanup touches. Every fragment is a prepared-statement template plus its
 * arguments; table names only ever come from `$wpdb`.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Criteria builder.
 */
final class Criteria {

	/**
	 * Auto-drafts younger than this (seconds) may belong to an editor that is
	 * open right now and are never touched. Two days also absorb any
	 * difference between the local `post_date` and UTC.
	 */
	public const AUTO_DRAFT_MIN_AGE = 172800;

	/**
	 * Autosave revisions contain unsaved editor changes and are never cleaned.
	 */
	public const AUTOSAVE_PATTERN = '-autosave-v';

	/**
	 * Post types that are never deleted by the trash cleanup: attachments
	 * (deleting them removes the files, which a database backup cannot bring
	 * back) and shop orders (their line items live in plugin tables outside
	 * the backup).
	 */
	public const PROTECTED_POST_TYPES = array( 'attachment', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'edd_payment' );

	/**
	 * Post types excluded from the trash cleanup.
	 *
	 * @return string[]
	 */
	public static function excluded_post_types(): array {
		/**
		 * Filters the post types that the database cleanup never deletes from the trash.
		 *
		 * Add post types whose data lives partly outside the WordPress core
		 * tables (a restore could not bring that data back).
		 *
		 * @param string[] $post_types Post types.
		 */
		$types = (array) apply_filters( 'shso_db_clean_excluded_post_types', self::PROTECTED_POST_TYPES );
		$types = array_map( static fn( $type ) => substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $type ) ), 0, 20 ), $types );
		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * Revisions (excluding autosaves) on the posts table.
	 *
	 * @param object $wpdb Database object.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public static function revisions( object $wpdb ): array {
		return array(
			'post_type = %s AND post_name NOT LIKE %s',
			array( 'revision', '%' . self::esc_like( $wpdb, self::AUTOSAVE_PATTERN ) . '%' ),
		);
	}

	/**
	 * Auto-drafts older than {@see AUTO_DRAFT_MIN_AGE}.
	 *
	 * @param int $now Current timestamp.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public static function auto_drafts( int $now ): array {
		return array(
			'post_status = %s AND post_date < %s',
			array( 'auto-draft', gmdate( 'Y-m-d H:i:s', $now - self::AUTO_DRAFT_MIN_AGE ) ),
		);
	}

	/**
	 * Posts in the trash (except protected post types).
	 *
	 * @param string[] $excluded Excluded post types.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public static function trashed_posts( array $excluded ): array {
		if ( empty( $excluded ) ) {
			return array( 'post_status = %s', array( 'trash' ) );
		}
		$placeholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );
		return array(
			"post_status = %s AND post_type NOT IN ({$placeholders})",
			array_merge( array( 'trash' ), array_values( $excluded ) ),
		);
	}

	/**
	 * Comments with a given status (spam|trash).
	 *
	 * @param string $status Comment status.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public static function comments( string $status ): array {
		return array( 'comment_approved = %s', array( 'spam' === $status ? 'spam' : 'trash' ) );
	}

	/**
	 * Expired transient timeouts in the options table.
	 *
	 * Mirrors WordPress' own `delete_expired_transients()` comparison.
	 *
	 * @param object $wpdb         Database object.
	 * @param int    $now          Current timestamp.
	 * @param bool   $include_site Also site transients (single site: stored in options).
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public static function transient_timeouts( object $wpdb, int $now, bool $include_site ): array {
		$args = array( self::esc_like( $wpdb, '_transient_timeout_' ) . '%' );
		$sql  = 'option_name LIKE %s';
		if ( $include_site ) {
			$sql    = '(option_name LIKE %s OR option_name LIKE %s)';
			$args[] = self::esc_like( $wpdb, '_site_transient_timeout_' ) . '%';
		}
		$args[] = $now;
		return array( $sql . ' AND option_value < %d', $args );
	}

	/**
	 * Expired site transient timeouts in the network's sitemeta table.
	 *
	 * @param object $wpdb    Database object.
	 * @param int    $now     Current timestamp.
	 * @param int    $site_id Network id.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public static function site_transient_timeouts( object $wpdb, int $now, int $site_id ): array {
		return array(
			'site_id = %d AND meta_key LIKE %s AND meta_value < %d',
			array( $site_id, self::esc_like( $wpdb, '_site_transient_timeout_' ) . '%', $now ),
		);
	}

	/**
	 * Orphaned meta definition: meta table, object table and join columns.
	 *
	 * @param object $wpdb Database object.
	 * @param string $type post|comment|term.
	 * @return array{meta:string,object:string,meta_fk:string,object_pk:string,logical:string,cache:string}|null
	 */
	public static function orphaned_meta( object $wpdb, string $type ): ?array {
		$map = array(
			'post'    => array( 'postmeta', 'posts', 'post_id', 'ID', 'post_meta' ),
			'comment' => array( 'commentmeta', 'comments', 'comment_id', 'comment_ID', 'comment_meta' ),
			'term'    => array( 'termmeta', 'terms', 'term_id', 'term_id', 'term_meta' ),
		);
		if ( ! isset( $map[ $type ] ) ) {
			return null;
		}
		list( $meta, $object, $fk, $pk, $cache ) = $map[ $type ];
		if ( empty( $wpdb->{$meta} ) || empty( $wpdb->{$object} ) ) {
			return null;
		}
		return array(
			'meta'      => (string) $wpdb->{$meta},
			'object'    => (string) $wpdb->{$object},
			'meta_fk'   => $fk,
			'object_pk' => $pk,
			'logical'   => $meta,
			'cache'     => $cache,
		);
	}

	/**
	 * Placeholder list for an IN clause.
	 *
	 * @param int    $count  Number of values.
	 * @param string $format Placeholder.
	 */
	public static function placeholders( int $count, string $format = '%d' ): string {
		return implode( ', ', array_fill( 0, max( 1, $count ), $format ) );
	}

	/**
	 * Escape a LIKE pattern.
	 *
	 * @param object $wpdb Database object.
	 * @param string $text Text.
	 */
	private static function esc_like( object $wpdb, string $text ): string {
		if ( method_exists( $wpdb, 'esc_like' ) ) {
			return (string) $wpdb->esc_like( $text );
		}
		return addcslashes( $text, '_%\\' );
	}
}
