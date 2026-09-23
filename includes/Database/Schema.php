<?php
/**
 * Core table whitelist for database backups and restores.
 *
 * Backups never store physical table names. They store the logical name of a
 * WordPress core table ("posts", "postmeta" …) which is mapped back to the
 * current site's table through the `$wpdb` properties on restore. Only the
 * columns of the WordPress core schema are backed up and restored, so a
 * crafted backup file can never write into another table or column.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Table definitions.
 */
final class Schema {

	/**
	 * Logical table => primary key columns, core columns and integer columns.
	 */
	public const TABLES = array(
		'posts'              => array(
			'pk'      => array( 'ID' ),
			'columns' => array( 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' ),
			'int'     => array( 'ID', 'post_author', 'post_parent', 'menu_order', 'comment_count' ),
		),
		'postmeta'           => array(
			'pk'      => array( 'meta_id' ),
			'columns' => array( 'meta_id', 'post_id', 'meta_key', 'meta_value' ),
			'int'     => array( 'meta_id', 'post_id' ),
		),
		'comments'           => array(
			'pk'      => array( 'comment_ID' ),
			'columns' => array( 'comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_date_gmt', 'comment_content', 'comment_karma', 'comment_approved', 'comment_agent', 'comment_type', 'comment_parent', 'user_id' ),
			'int'     => array( 'comment_ID', 'comment_post_ID', 'comment_karma', 'comment_parent', 'user_id' ),
		),
		'commentmeta'        => array(
			'pk'      => array( 'meta_id' ),
			'columns' => array( 'meta_id', 'comment_id', 'meta_key', 'meta_value' ),
			'int'     => array( 'meta_id', 'comment_id' ),
		),
		'termmeta'           => array(
			'pk'      => array( 'meta_id' ),
			'columns' => array( 'meta_id', 'term_id', 'meta_key', 'meta_value' ),
			'int'     => array( 'meta_id', 'term_id' ),
		),
		'term_relationships' => array(
			'pk'      => array( 'object_id', 'term_taxonomy_id' ),
			'columns' => array( 'object_id', 'term_taxonomy_id', 'term_order' ),
			'int'     => array( 'object_id', 'term_taxonomy_id', 'term_order' ),
		),
		'options'            => array(
			'pk'      => array( 'option_id' ),
			'columns' => array( 'option_id', 'option_name', 'option_value', 'autoload' ),
			'int'     => array( 'option_id' ),
		),
		'sitemeta'           => array(
			'pk'      => array( 'meta_id' ),
			'columns' => array( 'meta_id', 'site_id', 'meta_key', 'meta_value' ),
			'int'     => array( 'meta_id', 'site_id' ),
		),
	);

	/**
	 * Parent columns that WordPress rewrites when a row is deleted
	 * (children are re-pointed to the deleted row's parent). Backups record
	 * these links so a restore can point the children back.
	 */
	public const LINKS = array(
		'posts'    => 'post_parent',
		'comments' => 'comment_parent',
	);

	/**
	 * Order in which tables are restored.
	 */
	public const RESTORE_ORDER = array( 'posts', 'comments', 'postmeta', 'commentmeta', 'term_relationships', 'termmeta', 'options', 'sitemeta' );

	/**
	 * Logical tables that may appear in a backup of this site.
	 *
	 * @param bool $multisite Whether the network tables exist.
	 * @return string[]
	 */
	public static function allowed_tables( bool $multisite ): array {
		$tables = array_keys( self::TABLES );
		if ( ! $multisite ) {
			$tables = array_values( array_diff( $tables, array( 'sitemeta' ) ) );
		}
		return $tables;
	}

	/**
	 * Whether a logical table is part of the whitelist.
	 *
	 * @param string $table     Logical table.
	 * @param bool   $multisite Multisite.
	 */
	public static function is_allowed( string $table, bool $multisite ): bool {
		return in_array( $table, self::allowed_tables( $multisite ), true );
	}

	/**
	 * Physical table name of a whitelisted logical table.
	 *
	 * @param object $wpdb  Database object.
	 * @param string $table Logical table.
	 */
	public static function physical( object $wpdb, string $table ): ?string {
		if ( ! isset( self::TABLES[ $table ] ) || empty( $wpdb->{$table} ) || ! is_string( $wpdb->{$table} ) ) {
			return null;
		}
		return $wpdb->{$table};
	}

	/**
	 * Core columns.
	 *
	 * @param string $table Logical table.
	 * @return string[]
	 */
	public static function columns( string $table ): array {
		return self::TABLES[ $table ]['columns'] ?? array();
	}

	/**
	 * Primary key columns.
	 *
	 * @param string $table Logical table.
	 * @return string[]
	 */
	public static function primary_key( string $table ): array {
		return self::TABLES[ $table ]['pk'] ?? array();
	}

	/**
	 * Column list for SELECT statements (quoted, core columns only).
	 *
	 * @param string $table Logical table.
	 * @param string $alias Optional table alias.
	 */
	public static function select_list( string $table, string $alias = '' ): string {
		$prefix = '' === $alias ? '' : $alias . '.';
		return implode(
			', ',
			array_map(
				static fn( string $column ): string => $prefix . $column,
				self::columns( $table )
			)
		);
	}

	/**
	 * `$wpdb->insert()` formats for a row.
	 *
	 * @param string              $table Logical table.
	 * @param array<string,mixed> $row   Row.
	 * @return string[]
	 */
	public static function formats( string $table, array $row ): array {
		$ints    = self::TABLES[ $table ]['int'] ?? array();
		$formats = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, $ints, true ) ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Primary key string of a row ("12" or "12:7" for composite keys), null when incomplete.
	 *
	 * @param string              $table Logical table.
	 * @param array<string,mixed> $row   Row.
	 */
	public static function key_of( string $table, array $row ): ?string {
		$parts = array();
		foreach ( self::primary_key( $table ) as $column ) {
			$value = $row[ $column ] ?? null;
			if ( ! is_scalar( $value ) || ! preg_match( '/^[0-9]{1,19}$/', (string) $value ) ) {
				return null;
			}
			$normalized = ltrim( (string) $value, '0' );
			$parts[]    = '' === $normalized ? '0' : $normalized;
		}
		return empty( $parts ) ? null : implode( ':', $parts );
	}
}
