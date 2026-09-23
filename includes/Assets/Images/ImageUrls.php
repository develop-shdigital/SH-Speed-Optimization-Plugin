<?php
/**
 * Pure URL helpers for images.
 *
 * Canonical comparison of image URLs (browser measurements report absolute
 * URLs, markup may use relative or protocol-relative ones, cache-busting
 * `ver` parameters, or our own WebP derivative URLs), srcset parsing and
 * serialization, intrinsic sizes from WordPress file names and srcset, and
 * mapping of local URLs to file paths.
 *
 * No WordPress functions are used here so everything is unit testable.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Images;

defined( 'ABSPATH' ) || exit;

/**
 * Image URL helpers.
 */
final class ImageUrls {

	/**
	 * Path segment of WebP/AVIF derivatives inside the uploads directory.
	 */
	public const DERIVATIVE_SEGMENT = '/sh-speed-optimizer/webp/';

	/**
	 * Image file extensions we consider raster images.
	 */
	public const RASTER_EXTENSIONS = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp' );

	/**
	 * Canonical form of a URL for comparisons: scheme-less "host/path?query",
	 * lower-case host, decoded path, `ver` parameter and fragment removed, and
	 * WebP/AVIF derivative URLs mapped back to their original image.
	 *
	 * Returns '' for data:/blob: URLs and unparsable input. Relative URLs without
	 * a leading slash cannot be resolved and are returned lower-cased.
	 *
	 * @param string $url       URL (decoded attribute value).
	 * @param string $home_host Host used to resolve root-relative URLs ("example.com").
	 */
	public static function canonical( string $url, string $home_host = '' ): string {
		$url = trim( $url );
		if ( '' === $url || preg_match( '#^(data|blob|javascript|about):#i', $url ) ) {
			return '';
		}

		$url = (string) preg_replace( '#^https?:#i', '', $url );

		if ( 0 !== strpos( $url, '//' ) ) {
			if ( '/' === $url[0] && '' !== $home_host ) {
				$url = '//' . strtolower( $home_host ) . $url;
			} else {
				return strtolower( $url );
			}
		}

		$parts = parse_url( 'https:' . $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, no WordPress available in tests.
		if ( false === $parts || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path = rawurldecode( $parts['path'] ?? '/' );
		$path = self::original_path( $path );

		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$keep = array();
			foreach ( explode( '&', $parts['query'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$name = strtolower( rawurldecode( explode( '=', $pair, 2 )[0] ) );
				if ( 'ver' !== $name ) {
					$keep[] = $pair;
				}
			}
			if ( ! empty( $keep ) ) {
				$query = '?' . implode( '&', $keep );
			}
		}

		return $host . $path . $query;
	}

	/**
	 * Map a derivative path (".../uploads/sh-speed-optimizer/webp/2024/05/a.jpg.webp") back to
	 * the original (".../uploads/2024/05/a.jpg"). Other paths are returned unchanged.
	 *
	 * @param string $path URL path.
	 */
	public static function original_path( string $path ): string {
		$pos = strpos( $path, self::DERIVATIVE_SEGMENT );
		if ( false === $pos || ! preg_match( '#\.(?:jpe?g|png)\.(?:webp|avif)$#i', $path ) ) {
			return $path;
		}
		$path = substr( $path, 0, $pos ) . '/' . substr( $path, $pos + strlen( self::DERIVATIVE_SEGMENT ) );
		return (string) preg_replace( '#\.(?:webp|avif)$#i', '', $path );
	}

	/**
	 * Map a derivative URL back to its original URL (scheme and host kept). Other URLs are
	 * returned unchanged.
	 *
	 * @param string $url URL.
	 */
	public static function original_url( string $url ): string {
		if ( false === strpos( $url, self::DERIVATIVE_SEGMENT ) ) {
			return $url;
		}
		$suffix = '';
		if ( preg_match( '/[?#].*$/s', $url, $m ) ) {
			$suffix = $m[0];
			$url    = substr( $url, 0, -strlen( $suffix ) );
		}
		if ( ! preg_match( '#\.(?:jpe?g|png)\.(?:webp|avif)$#i', $url ) ) {
			return $url . $suffix;
		}
		$pos = (int) strpos( $url, self::DERIVATIVE_SEGMENT );
		$url = substr( $url, 0, $pos ) . '/' . substr( $url, $pos + strlen( self::DERIVATIVE_SEGMENT ) );
		return (string) preg_replace( '#\.(?:webp|avif)$#i', '', $url ) . $suffix;
	}

	/**
	 * Whether two URLs point to the same image.
	 *
	 * @param string $a         URL.
	 * @param string $b         URL.
	 * @param string $home_host Home host.
	 */
	public static function same( string $a, string $b, string $home_host = '' ): bool {
		$ca = self::canonical( $a, $home_host );
		return '' !== $ca && self::canonical( $b, $home_host ) === $ca;
	}

	/**
	 * Parse a srcset attribute (HTML spec algorithm, simplified).
	 *
	 * URLs may contain commas (e.g. image CDNs: "w_300,h_200"); a candidate URL ends at
	 * whitespace, trailing commas are stripped, descriptors run until the next comma.
	 *
	 * @param string $srcset Decoded srcset value.
	 * @return array<int,array{url:string,descriptor:string}>
	 */
	public static function parse_srcset( string $srcset ): array {
		$out = array();
		$len = strlen( $srcset );
		$i   = 0;

		while ( $i < $len ) {
			while ( $i < $len && ( ctype_space( $srcset[ $i ] ) || ',' === $srcset[ $i ] ) ) {
				++$i;
			}
			if ( $i >= $len ) {
				break;
			}

			$start = $i;
			while ( $i < $len && ! ctype_space( $srcset[ $i ] ) ) {
				++$i;
			}
			$url        = substr( $srcset, $start, $i - $start );
			$descriptor = '';

			if ( ',' === substr( $url, -1 ) ) {
				$url = rtrim( $url, ',' );
			} else {
				$d_start = $i;
				$depth   = 0;
				while ( $i < $len ) {
					$char = $srcset[ $i ];
					if ( '(' === $char ) {
						++$depth;
					} elseif ( ')' === $char ) {
						$depth = max( 0, $depth - 1 );
					} elseif ( ',' === $char && 0 === $depth ) {
						break;
					}
					++$i;
				}
				$descriptor = trim( (string) preg_replace( '/\s+/', ' ', substr( $srcset, $d_start, $i - $d_start ) ) );
				++$i;
			}

			if ( '' !== $url ) {
				$out[] = array(
					'url'        => $url,
					'descriptor' => $descriptor,
				);
			}
		}

		return $out;
	}

	/**
	 * Serialize srcset candidates.
	 *
	 * @param array<int,array{url:string,descriptor:string}> $candidates Candidates.
	 */
	public static function build_srcset( array $candidates ): string {
		$parts = array();
		foreach ( $candidates as $candidate ) {
			$parts[] = trim( $candidate['url'] . ' ' . $candidate['descriptor'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * URLs of an image tag's candidates: src plus every srcset candidate.
	 *
	 * @param string|null $src    src value.
	 * @param string|null $srcset srcset value.
	 * @return string[]
	 */
	public static function candidates( ?string $src, ?string $srcset ): array {
		$urls = array();
		if ( null !== $src && '' !== trim( $src ) ) {
			$urls[] = trim( $src );
		}
		if ( null !== $srcset && '' !== trim( $srcset ) ) {
			foreach ( self::parse_srcset( $srcset ) as $candidate ) {
				$urls[] = $candidate['url'];
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Intrinsic size from a WordPress sub-size file name ("photo-800x600.jpg").
	 * Derivative names ("photo-800x600.jpg.webp") are understood as well.
	 *
	 * @param string $url URL or path.
	 * @return array{0:int,1:int}|null
	 */
	public static function size_from_filename( string $url ): ?array {
		$path = self::path_of( $url );
		$path = (string) preg_replace( '#\.(?:jpe?g|png)\K\.(?:webp|avif)$#i', '', $path );

		if ( preg_match( '#-(\d{1,5})x(\d{1,5})\.(?:jpe?g|jpe|png|gif|webp|avif|bmp)$#i', $path, $m ) ) {
			$width  = (int) $m[1];
			$height = (int) $m[2];
			if ( $width > 0 && $height > 0 && $width <= 12000 && $height <= 12000 ) {
				return array( $width, $height );
			}
		}
		return null;
	}

	/**
	 * Intrinsic size derived from srcset: the src candidate's width descriptor combined with the
	 * aspect ratio of a sibling sub-size file name of the same image (WordPress only lists sizes
	 * with the original's aspect ratio in srcset).
	 *
	 * @param string $src    src value.
	 * @param string $srcset srcset value.
	 * @return array{0:int,1:int}|null
	 */
	public static function size_from_srcset( string $src, string $srcset ): ?array {
		$src_path = self::path_of( $src );
		if ( '' === $src_path || '' === trim( $srcset ) ) {
			return null;
		}

		$candidates = self::parse_srcset( $srcset );
		$src_width  = 0;
		foreach ( $candidates as $candidate ) {
			if ( self::path_of( $candidate['url'] ) === $src_path && preg_match( '/^(\d{1,5})w$/', $candidate['descriptor'], $m ) ) {
				$src_width = (int) $m[1];
				break;
			}
		}
		if ( $src_width <= 0 ) {
			return null;
		}

		$stem = self::stem( $src_path, true );
		$best = null;
		foreach ( $candidates as $candidate ) {
			$size = self::size_from_filename( $candidate['url'] );
			if ( null === $size || self::stem( self::path_of( $candidate['url'] ), false ) !== $stem ) {
				continue;
			}
			if ( null === $best || $size[0] > $best[0] ) {
				$best = $size;
			}
		}
		if ( null === $best ) {
			return null;
		}

		$height = (int) round( $src_width * $best[1] / $best[0] );
		return $height > 0 ? array( $src_width, $height ) : null;
	}

	/**
	 * Map a URL to a local file path using URL prefix => directory pairs.
	 *
	 * The query string is ignored, the remaining path is URL-decoded and refused when it
	 * contains traversal segments, NUL bytes or backslashes. The caller must still verify the
	 * resolved path (realpath) before reading it.
	 *
	 * @param string               $url       URL.
	 * @param array<string,string> $prefixes  URL prefix (with trailing slash) => directory (with trailing slash).
	 * @param string               $home_host Host for root-relative URLs.
	 */
	public static function local_path( string $url, array $prefixes, string $home_host = '' ): ?string {
		$url = trim( $url );
		if ( '' === $url || preg_match( '#^(data|blob|javascript|about):#i', $url ) ) {
			return null;
		}
		$url = (string) preg_replace( '/[?#].*$/s', '', $url );
		$url = (string) preg_replace( '#^https?:#i', '', $url );
		if ( 0 !== strpos( $url, '//' ) ) {
			if ( '' === $url || '/' !== $url[0] || '' === $home_host ) {
				return null;
			}
			$url = '//' . strtolower( $home_host ) . $url;
		}

		foreach ( $prefixes as $prefix => $dir ) {
			$prefix = (string) preg_replace( '#^https?:#i', '', (string) $prefix );
			if ( '' === $prefix || 0 !== strncasecmp( $url, $prefix, strlen( $prefix ) ) ) {
				continue;
			}
			$rest = rawurldecode( substr( $url, strlen( $prefix ) ) );
			if ( ! self::is_safe_relative( $rest ) ) {
				return null;
			}
			return rtrim( (string) $dir, '/' ) . '/' . $rest;
		}

		return null;
	}

	/**
	 * Whether a relative path is safe to append to a directory.
	 *
	 * @param string $relative Relative path (decoded).
	 */
	public static function is_safe_relative( string $relative ): bool {
		if ( '' === $relative || '/' === $relative[0] || false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '\\' ) ) {
			return false;
		}
		if ( false !== strpos( $relative, '//' ) || preg_match( '#(^|/)\.\.?(/|$)#', $relative ) ) {
			return false;
		}
		return (bool) preg_match( '#^[^\x00-\x1F\x7F]+$#', $relative );
	}

	/**
	 * Lower-case file extension of a URL's path ('' when none).
	 *
	 * @param string $url URL.
	 */
	public static function extension( string $url ): string {
		return strtolower( pathinfo( self::path_of( $url ), PATHINFO_EXTENSION ) );
	}

	/**
	 * Whether a URL is an SVG image.
	 *
	 * @param string $url URL.
	 */
	public static function is_svg( string $url ): bool {
		return 0 === stripos( trim( $url ), 'data:image/svg' ) || 'svg' === self::extension( $url ) || 'svgz' === self::extension( $url );
	}

	/**
	 * Host of the URL in lower case ('' for relative URLs).
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): string {
		$url = trim( $url );
		if ( ! preg_match( '#^(?:https?:)?//([^/?\#:]+)#i', $url, $m ) ) {
			return '';
		}
		return strtolower( $m[1] );
	}

	/**
	 * Decoded path of a URL without query and fragment.
	 *
	 * @param string $url URL.
	 */
	private static function path_of( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return '';
		}
		$url = (string) preg_replace( '/[?#].*$/s', '', $url );
		$url = (string) preg_replace( '#^(?:https?:)?//[^/]*#i', '', $url );
		return rawurldecode( $url );
	}

	/**
	 * File stem for comparing sibling sub-sizes: path without extension, without a "-WxH" size
	 * suffix and (for originals) without "-scaled"/"-rotated".
	 *
	 * @param string $path          Decoded path.
	 * @param bool   $strip_scaled  Strip -scaled/-rotated (originals).
	 */
	private static function stem( string $path, bool $strip_scaled ): string {
		$path = (string) preg_replace( '#\.(?:jpe?g|png)\K\.(?:webp|avif)$#i', '', $path );
		$path = (string) preg_replace( '#\.[a-z0-9]{2,5}$#i', '', $path );
		$path = (string) preg_replace( '#-\d{1,5}x\d{1,5}$#', '', $path );
		if ( $strip_scaled ) {
			$path = (string) preg_replace( '#-(?:scaled|rotated)$#', '', $path );
		}
		return $path;
	}
}
