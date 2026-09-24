<?php
/**
 * Creates WebP (optionally AVIF) derivatives of JPEG/PNG attachments.
 *
 * Thin WordPress adapter around WP_Image_Editor. Originals are only read,
 * never modified or deleted. A derivative is kept only when it is at least
 * 5% smaller than its original.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Images;

use SH\SpeedOptimizer\Core\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Image converter.
 */
final class ImageConverter {

	public const META_KEY = '_shso_webp';

	public const STATUS_CONVERTED   = 'converted';
	public const STATUS_LARGER      = 'skipped: larger';
	public const STATUS_MISSING     = 'missing';
	public const STATUS_TOO_LARGE   = 'skipped: too large';
	public const STATUS_ERROR       = 'error';
	public const STATUS_UNSUPPORTED = 'unsupported';

	/**
	 * Minimum saving for a derivative to be kept.
	 */
	public const MIN_SAVING = 0.05;

	/**
	 * Filesystem.
	 *
	 * @var Filesystem
	 */
	private Filesystem $fs;

	/**
	 * Path mapper.
	 *
	 * @var DerivativeMap
	 */
	private DerivativeMap $map;

	/**
	 * Quality 1–100.
	 *
	 * @var int
	 */
	private int $quality;

	/**
	 * Also create AVIF derivatives.
	 *
	 * @var bool
	 */
	private bool $avif;

	/**
	 * Constructor.
	 *
	 * @param Filesystem    $fs      Filesystem.
	 * @param DerivativeMap $map     Path mapper.
	 * @param int           $quality Quality.
	 * @param bool          $avif    Create AVIF too.
	 */
	public function __construct( Filesystem $fs, DerivativeMap $map, int $quality = 82, bool $avif = false ) {
		$this->fs      = $fs;
		$this->map     = $map;
		$this->quality = max( 1, min( 100, $quality ) );
		$this->avif    = $avif;
	}

	/**
	 * Convert every file of an attachment (main file and registered sub-sizes) and store the
	 * result in post meta.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{sizes:array<string,array{bytes_original:int,bytes_webp:int,status:string}>,updated:int}
	 */
	public function convert_attachment( int $attachment_id ): array {
		$record = array(
			'sizes'   => array(),
			'updated' => time(),
		);

		$mime = (string) get_post_mime_type( $attachment_id );
		$file = (string) get_attached_file( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || '' === $file ) {
			$record['status'] = self::STATUS_UNSUPPORTED;
			update_post_meta( $attachment_id, self::META_KEY, $record );
			return $record;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		foreach ( self::files_for( wp_normalize_path( $file ), is_array( $meta ) ? $meta : array() ) as $path ) {
			$relative = $this->map->relative_from_file( $path );
			if ( null === $relative ) {
				continue;
			}
			$record['sizes'][ basename( $path ) ] = $this->convert_file( $path, $relative );
		}

		update_post_meta( $attachment_id, self::META_KEY, $record );
		return $record;
	}

	/**
	 * Convert one file.
	 *
	 * @param string $source   Absolute source path.
	 * @param string $relative Uploads-relative path.
	 * @return array{bytes_original:int,bytes_webp:int,status:string}
	 */
	public function convert_file( string $source, string $relative ): array {
		$result = array(
			'bytes_original' => 0,
			'bytes_webp'     => 0,
			'status'         => self::STATUS_ERROR,
		);

		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			$result['status'] = self::STATUS_MISSING;
			return $result;
		}
		$result['bytes_original'] = (int) filesize( $source );

		$formats = $this->avif ? array( 'webp', 'avif' ) : array( 'webp' );
		foreach ( $formats as $format ) {
			$dest = $this->map->derivative_path( $relative, $format );
			if ( null === $dest || ! $this->fs->is_allowed_path( $dest ) ) {
				return $result;
			}

			$status = $this->make( $source, $dest, $format, $result['bytes_original'] );
			if ( 'webp' === $format ) {
				$result['status']     = $status;
				$result['bytes_webp'] = self::STATUS_CONVERTED === $status ? (int) filesize( $dest ) : 0;
				if ( self::STATUS_CONVERTED !== $status ) {
					break; // No AVIF without WebP (the delivery needs both).
				}
			}
		}

		return $result;
	}

	/**
	 * Create one derivative.
	 *
	 * @param string $source         Source path.
	 * @param string $dest           Destination path.
	 * @param string $format         webp|avif.
	 * @param int    $bytes_original Source size.
	 */
	private function make( string $source, string $dest, string $format, int $bytes_original ): string {
		clearstatcache( true, $dest );
		if ( is_file( $dest ) && filemtime( $dest ) >= filemtime( $source ) ) {
			return self::STATUS_CONVERTED; // Up to date.
		}

		if ( ! self::has_memory_for( $source ) ) {
			return self::STATUS_TOO_LARGE;
		}

		if ( ! is_dir( dirname( $dest ) ) && ! wp_mkdir_p( dirname( $dest ) ) ) {
			return self::STATUS_ERROR;
		}

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return self::STATUS_ERROR;
		}

		/**
		 * Filters the quality of generated WebP/AVIF images (1–100).
		 *
		 * @param int    $quality Quality, default 82.
		 * @param string $format  webp|avif.
		 * @param string $source  Source file path.
		 */
		$quality = (int) apply_filters( 'shso_webp_quality', $this->quality, $format, $source );
		$editor->set_quality( max( 1, min( 100, $quality ) ) );

		$saved = $editor->save( $dest, 'image/' . $format );
		unset( $editor );

		if ( is_wp_error( $saved ) || ! is_array( $saved ) ) {
			return self::STATUS_ERROR;
		}

		$saved_path = wp_normalize_path( (string) ( $saved['path'] ?? '' ) );
		if ( wp_normalize_path( $dest ) !== $saved_path ) {
			if ( '' !== $saved_path ) {
				$this->fs->delete( $saved_path );
			}
			return self::STATUS_ERROR;
		}

		clearstatcache( true, $dest );
		$bytes = is_file( $dest ) ? (int) filesize( $dest ) : 0;
		if ( $bytes <= 0 ) {
			$this->fs->delete( $dest );
			return self::STATUS_ERROR;
		}
		if ( $bytes > $bytes_original * ( 1 - self::MIN_SAVING ) ) {
			$this->fs->delete( $dest );
			return self::STATUS_LARGER;
		}

		return self::STATUS_CONVERTED;
	}

	/**
	 * Delete the derivatives of an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return int Deleted files.
	 */
	public function delete_attachment( int $attachment_id ): int {
		$file = (string) get_attached_file( $attachment_id, true );
		if ( '' === $file ) {
			return 0;
		}
		$meta    = wp_get_attachment_metadata( $attachment_id, true );
		$deleted = 0;
		foreach ( self::files_for( wp_normalize_path( $file ), is_array( $meta ) ? $meta : array() ) as $path ) {
			$relative = $this->map->relative_from_file( $path );
			if ( null === $relative ) {
				continue;
			}
			foreach ( array( 'webp', 'avif' ) as $format ) {
				$dest = $this->map->derivative_path( $relative, $format );
				if ( null !== $dest && $this->fs->delete( $dest ) ) {
					++$deleted;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Files of an attachment: the main (possibly "-scaled") file plus every sub-size in the same
	 * directory. Pure.
	 *
	 * @param string              $main_file Absolute path of the attached file.
	 * @param array<string,mixed> $meta      Attachment metadata.
	 * @return string[]
	 */
	public static function files_for( string $main_file, array $meta ): array {
		$files = array( $main_file );
		$dir   = dirname( $main_file );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}
			$name = basename( (string) $size['file'] );
			if ( '' === $name || '.' === $name[0] || ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), DerivativeMap::SOURCE_EXTENSIONS, true ) ) {
				continue;
			}
			$files[] = $dir . '/' . $name;
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Totals of a stored record (for statistics).
	 *
	 * @param mixed $record Post meta value.
	 * @return array{converted:int,skipped:int,errors:int,bytes_original:int,bytes_webp:int}
	 */
	public static function totals( $record ): array {
		$totals = array(
			'converted'      => 0,
			'skipped'        => 0,
			'errors'         => 0,
			'bytes_original' => 0,
			'bytes_webp'     => 0,
		);
		if ( ! is_array( $record ) || ! is_array( $record['sizes'] ?? null ) ) {
			return $totals;
		}
		foreach ( $record['sizes'] as $size ) {
			$status = (string) ( $size['status'] ?? '' );
			if ( self::STATUS_CONVERTED === $status ) {
				++$totals['converted'];
				$totals['bytes_original'] += (int) ( $size['bytes_original'] ?? 0 );
				$totals['bytes_webp']     += (int) ( $size['bytes_webp'] ?? 0 );
			} elseif ( self::STATUS_ERROR === $status ) {
				++$totals['errors'];
			} else {
				++$totals['skipped'];
			}
		}
		return $totals;
	}

	/**
	 * Whether there is enough memory to decode an image (GD/Imagick need roughly 5 bytes per
	 * pixel plus overhead). Raises the limit the way WordPress does for image work first.
	 *
	 * @param string $source Source path.
	 */
	private static function has_memory_for( string $source ): bool {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$size = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt files must not emit warnings.
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}
		$limit = self::memory_limit();
		if ( $limit <= 0 ) {
			return true;
		}
		$needed = (int) ( $size[0] * $size[1] * 5 * 1.8 );
		return memory_get_usage( true ) + $needed < $limit;
	}

	/**
	 * PHP memory limit in bytes (0 = unlimited).
	 */
	public static function memory_limit(): int {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}
		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $raw );
		}
		$value = (int) $raw;
		switch ( strtolower( substr( $raw, -1 ) ) ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
		}
		return $value;
	}
}
