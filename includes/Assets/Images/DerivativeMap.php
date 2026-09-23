<?php
/**
 * Mapping between original upload files/URLs and their WebP/AVIF derivatives.
 *
 * Derivatives mirror the uploads-relative path with the new extension appended:
 * uploads/2024/05/photo-800x600.jpg → uploads/sh-speed-optimizer/webp/2024/05/photo-800x600.jpg.webp
 *
 * Every mapping is validated: only JPEG/PNG files below the uploads directory
 * (never our own derivative directory), no traversal segments, no NUL bytes.
 * Pure (no WordPress calls) except {@see from_wordpress()}.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Images;

use SH\SpeedOptimizer\Core\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Derivative path mapper.
 */
final class DerivativeMap {

	/**
	 * Source extensions that get derivatives.
	 */
	public const SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'jpe', 'png' );

	/**
	 * Uploads base directory (normalized, trailing slash).
	 *
	 * @var string
	 */
	private string $uploads_dir;

	/**
	 * Uploads base URL without scheme ("//example.com/wp-content/uploads/").
	 *
	 * @var string
	 */
	private string $uploads_url;

	/**
	 * Uploads URL path ("/wp-content/uploads/").
	 *
	 * @var string
	 */
	private string $uploads_path;

	/**
	 * Uploads host.
	 *
	 * @var string
	 */
	private string $uploads_host;

	/**
	 * Derivative directory (normalized, trailing slash).
	 *
	 * @var string
	 */
	private string $target_dir;

	/**
	 * Derivative directory relative to the uploads directory ("sh-speed-optimizer/webp/").
	 *
	 * @var string
	 */
	private string $target_relative;

	/**
	 * Constructor.
	 *
	 * @param string $uploads_dir Uploads base directory.
	 * @param string $uploads_url Uploads base URL.
	 * @param string $target_dir  Derivative directory (must be inside the uploads directory).
	 */
	public function __construct( string $uploads_dir, string $uploads_url, string $target_dir ) {
		$this->uploads_dir  = rtrim( str_replace( '\\', '/', $uploads_dir ), '/' ) . '/';
		$this->target_dir   = rtrim( str_replace( '\\', '/', $target_dir ), '/' ) . '/';
		$url                = rtrim( (string) preg_replace( '#^https?:#i', '', trim( $uploads_url ) ), '/' ) . '/';
		$this->uploads_url  = $url;
		$this->uploads_host = ImageUrls::host( $url );
		$this->uploads_path = (string) preg_replace( '#^//[^/]*#', '', $url );

		$this->target_relative = 0 === strpos( $this->target_dir, $this->uploads_dir )
			? substr( $this->target_dir, strlen( $this->uploads_dir ) )
			: 'sh-speed-optimizer/webp/';
	}

	/**
	 * Build the map for this site.
	 */
	public static function from_wordpress(): self {
		$uploads = wp_upload_dir( null, false );
		return new self(
			wp_normalize_path( (string) $uploads['basedir'] ),
			(string) $uploads['baseurl'],
			Filesystem::uploads_root( false ) . 'webp/'
		);
	}

	/**
	 * Derivative directory.
	 */
	public function target_dir(): string {
		return $this->target_dir;
	}

	/**
	 * Parse an image URL below the uploads directory.
	 *
	 * URLs with a query string are ignored (dynamic images), as are derivatives themselves.
	 *
	 * @param string $url       URL (decoded attribute value).
	 * @param string $home_host Host for root-relative URLs.
	 * @return array{rel:string,prefix:string,raw:string}|null rel = decoded uploads-relative path,
	 *         prefix = original URL up to and including the uploads base, raw = original relative part.
	 */
	public function from_url( string $url, string $home_host = '' ): ?array {
		$url = trim( $url );
		if ( '' === $url || false !== strpbrk( $url, "?#\0 \t\r\n" ) ) {
			return null;
		}

		if ( preg_match( '#^(?:https?:)?//#i', $url ) ) {
			$scheme_less = (string) preg_replace( '#^https?:#i', '', $url );
			if ( 0 !== strncasecmp( $scheme_less, $this->uploads_url, strlen( $this->uploads_url ) ) ) {
				return null;
			}
			$offset = strlen( $url ) - strlen( $scheme_less ) + strlen( $this->uploads_url );
		} elseif ( '/' === $url[0] ) {
			if ( '' !== $home_host && '' !== $this->uploads_host && strtolower( $home_host ) !== $this->uploads_host ) {
				return null;
			}
			if ( 0 !== strncmp( $url, $this->uploads_path, strlen( $this->uploads_path ) ) ) {
				return null;
			}
			$offset = strlen( $this->uploads_path );
		} else {
			return null;
		}

		$raw = substr( $url, $offset );
		$rel = rawurldecode( $raw );
		if ( ! $this->is_source_relative( $rel ) ) {
			return null;
		}

		return array(
			'rel'    => $rel,
			'prefix' => substr( $url, 0, $offset ),
			'raw'    => $raw,
		);
	}

	/**
	 * Derivative URL for an image URL (no existence check).
	 *
	 * @param string $url       Original URL.
	 * @param string $format    webp|avif.
	 * @param string $home_host Host for root-relative URLs.
	 */
	public function derivative_url( string $url, string $format = 'webp', string $home_host = '' ): ?string {
		$info = $this->from_url( $url, $home_host );
		if ( null === $info || ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			return null;
		}
		return $info['prefix'] . $this->target_relative . $info['raw'] . '.' . $format;
	}

	/**
	 * Derivative file path for an uploads-relative source path.
	 *
	 * @param string $relative Decoded uploads-relative path.
	 * @param string $format   webp|avif.
	 */
	public function derivative_path( string $relative, string $format = 'webp' ): ?string {
		if ( ! $this->is_source_relative( $relative ) || ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			return null;
		}
		return $this->target_dir . $relative . '.' . $format;
	}

	/**
	 * Uploads-relative path of an absolute source file path.
	 *
	 * @param string $file Absolute path.
	 */
	public function relative_from_file( string $file ): ?string {
		$file = str_replace( '\\', '/', $file );
		if ( 0 !== strpos( $file, $this->uploads_dir ) ) {
			return null;
		}
		$relative = substr( $file, strlen( $this->uploads_dir ) );
		return $this->is_source_relative( $relative ) ? $relative : null;
	}

	/**
	 * Whether a relative path is a valid derivative source.
	 *
	 * @param string $relative Decoded uploads-relative path.
	 */
	public function is_source_relative( string $relative ): bool {
		if ( ! ImageUrls::is_safe_relative( $relative ) ) {
			return false;
		}
		if ( 0 === strpos( $relative, 'sh-speed-optimizer/' ) || 0 === strpos( $relative, $this->target_relative ) ) {
			return false;
		}
		return in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), self::SOURCE_EXTENSIONS, true );
	}
}
