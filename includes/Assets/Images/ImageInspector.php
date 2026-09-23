<?php
/**
 * Media library statistics for the scanner and diagnostics.
 *
 * All numbers are real counts from the database. Queries are prepared and
 * bounded: metadata is inspected in ID-ordered chunks up to a fixed limit and
 * the result says when it was truncated.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Images;

defined( 'ABSPATH' ) || exit;

/**
 * Media library inspector.
 */
final class ImageInspector {

	/**
	 * Originals wider/taller than this are "oversized".
	 */
	public const OVERSIZED_DIMENSION = 2560;

	/**
	 * Originals heavier than this are "oversized".
	 */
	public const OVERSIZED_BYTES = 1048576;

	/**
	 * Maximum attachments whose metadata is inspected.
	 */
	public const MAX_INSPECTED = 5000;

	/**
	 * Chunk size for metadata reads.
	 */
	private const CHUNK = 250;

	/**
	 * Media library statistics.
	 *
	 * @return array{attachments:int,images:array<string,int>,oversized_originals:array{count:int,inspected:int,truncated:bool,top:array<int,array{id:int,file:string,width:int,height:int,bytes:int}>},missing_alt:int,webp_coverage:array{converted:int,total:int}}
	 */
	public static function media_library_stats(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Background diagnostics with bounded, prepared queries.
		$attachments = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'attachment' )
		);

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_mime_type AS mime, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = %s AND post_mime_type LIKE %s GROUP BY post_mime_type",
				'attachment',
				'image/%'
			),
			ARRAY_A
		);

		$missing_alt = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_mime_type LIKE %s AND ( m.meta_value IS NULL OR m.meta_value = '' )",
				'_wp_attachment_image_alt',
				'attachment',
				'image/%'
			)
		);

		$converted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				ImageConverter::META_KEY,
				'%' . $wpdb->esc_like( '"' . ImageConverter::STATUS_CONVERTED . '"' ) . '%'
			)
		);
		// phpcs:enable

		$images = self::mime_counts( $rows );

		return array(
			'attachments'         => $attachments,
			'images'              => $images,
			'oversized_originals' => self::oversized(),
			'missing_alt'         => $missing_alt,
			'webp_coverage'       => array(
				'converted' => $converted,
				'total'     => $images['jpeg'] + $images['png'],
			),
		);
	}

	/**
	 * Group MIME counts. Pure.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of mime/total.
	 * @return array<string,int> total, jpeg, png, gif, webp, avif, other.
	 */
	public static function mime_counts( array $rows ): array {
		$out = array(
			'total' => 0,
			'jpeg'  => 0,
			'png'   => 0,
			'gif'   => 0,
			'webp'  => 0,
			'avif'  => 0,
			'other' => 0,
		);
		foreach ( $rows as $row ) {
			$mime  = strtolower( (string) ( $row['mime'] ?? '' ) );
			$count = (int) ( $row['total'] ?? 0 );
			$key   = array(
				'image/jpeg' => 'jpeg',
				'image/jpg'  => 'jpeg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
				'image/avif' => 'avif',
			)[ $mime ] ?? 'other';

			$out[ $key ]  += $count;
			$out['total'] += $count;
		}
		return $out;
	}

	/**
	 * Evaluate one attachment's metadata. Pure.
	 *
	 * @param int                 $id    Attachment id.
	 * @param array<string,mixed> $meta  Attachment metadata.
	 * @param int|null            $bytes File size when metadata lacks it.
	 * @return array{id:int,file:string,width:int,height:int,bytes:int}|null Details when oversized.
	 */
	public static function oversized_entry( int $id, array $meta, ?int $bytes = null ): ?array {
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		$size   = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : (int) $bytes;
		$file   = (string) ( $meta['file'] ?? '' ); // The file WordPress serves (the "-scaled" copy when one exists).

		if ( $width > self::OVERSIZED_DIMENSION || $height > self::OVERSIZED_DIMENSION || $size > self::OVERSIZED_BYTES ) {
			return array(
				'id'     => $id,
				'file'   => $file,
				'width'  => $width,
				'height' => $height,
				'bytes'  => $size,
			);
		}
		return null;
	}

	/**
	 * Oversized originals (bounded scan of attachment metadata).
	 *
	 * @return array{count:int,inspected:int,truncated:bool,top:array<int,array{id:int,file:string,width:int,height:int,bytes:int}>}
	 */
	private static function oversized(): array {
		global $wpdb;

		$count     = 0;
		$inspected = 0;
		$top       = array();
		$cursor    = 0;
		$truncated = false;

		while ( $inspected < self::MAX_INSPECTED ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded background scan.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS id, m.meta_value AS meta FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_mime_type LIKE %s AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
					'_wp_attachment_metadata',
					'attachment',
					'image/%',
					$cursor,
					self::CHUNK
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				++$inspected;
				$raw  = (string) $row['meta'];
				$meta = is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Classes disallowed.
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$entry = self::oversized_entry( $cursor, $meta );
				if ( null === $entry ) {
					continue;
				}
				++$count;
				$top[] = $entry;
				if ( count( $top ) > 20 ) {
					$top = self::top( $top );
				}
			}

			if ( count( $rows ) < self::CHUNK ) {
				break;
			}
			if ( $inspected >= self::MAX_INSPECTED ) {
				$truncated = true;
			}
		}

		return array(
			'count'     => $count,
			'inspected' => $inspected,
			'truncated' => $truncated,
			'top'       => self::top( $top ),
		);
	}

	/**
	 * Ten largest entries (by bytes, then pixels).
	 *
	 * @param array<int,array{id:int,file:string,width:int,height:int,bytes:int}> $entries Entries.
	 * @return array<int,array{id:int,file:string,width:int,height:int,bytes:int}>
	 */
	public static function top( array $entries ): array {
		usort(
			$entries,
			static function ( $a, $b ) {
				return array( $b['bytes'], $b['width'] * $b['height'] ) <=> array( $a['bytes'], $a['width'] * $a['height'] );
			}
		);
		return array_slice( $entries, 0, 10 );
	}
}
