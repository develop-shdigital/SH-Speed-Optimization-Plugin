<?php
/**
 * Determines the intrinsic size of local images cheaply and safely.
 *
 * Order: attachment metadata (wp-image-{id} class) → WordPress size suffix in
 * the file name → srcset width + sibling size ratio → getimagesize() on the
 * local file (at most a few probes per request, results cached in a bounded,
 * non-autoloaded option). External images are never probed.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Images;

defined( 'ABSPATH' ) || exit;

/**
 * Intrinsic size resolver.
 */
final class DimensionResolver {

	public const OPTION = 'shso_image_dimensions';

	/**
	 * Maximum cached entries.
	 */
	public const MAX_ENTRIES = 2000;

	/**
	 * Maximum getimagesize() calls per request (the rest is resolved on later requests).
	 */
	public const MAX_PROBES = 20;

	/**
	 * URL prefix => directory.
	 *
	 * @var array<string,string>
	 */
	private array $prefixes;

	/**
	 * Site host.
	 *
	 * @var string
	 */
	private string $home_host;

	/**
	 * Metadata reader: function( int $id ): array|null.
	 *
	 * @var callable|null
	 */
	private $metadata;

	/**
	 * Size probe: function( string $path ): array{0:int,1:int}|null.
	 *
	 * @var callable|null
	 */
	private $probe;

	/**
	 * Cache: md5(path) => [ width, height ] ([0,0] = unknown).
	 *
	 * @var array<string,array{0:int,1:int}>|null
	 */
	private ?array $cache;

	/**
	 * Whether the cache changed.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/**
	 * Probes done in this request.
	 *
	 * @var int
	 */
	private int $probes = 0;

	/**
	 * Metadata memo.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private array $meta_memo = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,string>                  $prefixes  URL prefix => directory of local image locations.
	 * @param string                                $home_host Site host.
	 * @param callable|null                         $metadata  Attachment metadata reader.
	 * @param callable|null                         $probe     Size probe for local files.
	 * @param array<string,array{0:int,1:int}>|null $cache     Initial cache (null = load from the option on demand).
	 */
	public function __construct( array $prefixes, string $home_host, ?callable $metadata = null, ?callable $probe = null, ?array $cache = null ) {
		$this->prefixes  = $prefixes;
		$this->home_host = $home_host;
		$this->metadata  = $metadata;
		$this->probe     = $probe;
		$this->cache     = $cache;
	}

	/**
	 * Resolver configured for this site: uploads, themes and plugins directories.
	 */
	public static function from_wordpress(): self {
		$uploads  = wp_upload_dir( null, false );
		$prefixes = array(
			trailingslashit( (string) $uploads['baseurl'] ) => trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ),
			trailingslashit( get_theme_root_uri() ) => trailingslashit( wp_normalize_path( get_theme_root() ) ),
			trailingslashit( plugins_url() )        => trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ),
		);

		$roots = array_values( $prefixes );

		return new self(
			$prefixes,
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			static function ( int $id ) {
				$meta = wp_get_attachment_metadata( $id );
				return is_array( $meta ) ? $meta : null;
			},
			static function ( string $path ) use ( $roots ) {
				return self::probe_file( $path, $roots );
			}
		);
	}

	/**
	 * Intrinsic size of an image, or null when unknown/external/SVG.
	 *
	 * @param string   $src     src value.
	 * @param string   $srcset  srcset value.
	 * @param string[] $classes Class names.
	 * @return array{0:int,1:int}|null
	 */
	public function resolve( string $src, string $srcset = '', array $classes = array() ): ?array {
		$src = ImageUrls::original_url( trim( $src ) );
		if ( '' === $src || ImageUrls::is_svg( $src ) || ! in_array( ImageUrls::extension( $src ), ImageUrls::RASTER_EXTENSIONS, true ) ) {
			return null;
		}

		$path = ImageUrls::local_path( $src, $this->prefixes, $this->home_host );
		if ( null === $path ) {
			return null; // External or not below an allowed directory.
		}

		$size = $this->from_metadata( basename( $path ), $classes )
			?? ImageUrls::size_from_filename( $src )
			?? ImageUrls::size_from_srcset( $src, $srcset );

		return $size ?? $this->probe( $path );
	}

	/**
	 * Size from attachment metadata when the image carries a wp-image-{id} class.
	 *
	 * @param string   $basename File name.
	 * @param string[] $classes  Class names.
	 * @return array{0:int,1:int}|null
	 */
	private function from_metadata( string $basename, array $classes ): ?array {
		if ( null === $this->metadata ) {
			return null;
		}
		foreach ( $classes as $class ) {
			if ( ! preg_match( '/^wp-image-(\d+)$/', $class, $m ) ) {
				continue;
			}
			$id = (int) $m[1];
			if ( ! array_key_exists( $id, $this->meta_memo ) ) {
				$meta                   = ( $this->metadata )( $id );
				$this->meta_memo[ $id ] = is_array( $meta ) ? $meta : null;
			}
			$size = self::size_in_metadata( $basename, $this->meta_memo[ $id ] ?? array() );
			if ( null !== $size ) {
				return $size;
			}
		}
		return null;
	}

	/**
	 * Find a file's size in attachment metadata. Pure.
	 *
	 * @param string              $basename File name.
	 * @param array<string,mixed> $meta     Metadata.
	 * @return array{0:int,1:int}|null
	 */
	public static function size_in_metadata( string $basename, array $meta ): ?array {
		if ( ! empty( $meta['file'] ) && basename( (string) $meta['file'] ) === $basename && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return array( (int) $meta['width'], (int) $meta['height'] );
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ( $size['file'] ?? '' ) === $basename && ! empty( $size['width'] ) && ! empty( $size['height'] ) ) {
				return array( (int) $size['width'], (int) $size['height'] );
			}
		}
		return null;
	}

	/**
	 * Cached getimagesize().
	 *
	 * @param string $path Local path (unverified).
	 * @return array{0:int,1:int}|null
	 */
	private function probe( string $path ): ?array {
		if ( null === $this->probe ) {
			return null;
		}
		$this->load_cache();
		$key = md5( $path );
		if ( isset( $this->cache[ $key ] ) ) {
			$size = $this->cache[ $key ];
			return $size[0] > 0 && $size[1] > 0 ? array( (int) $size[0], (int) $size[1] ) : null;
		}
		if ( $this->probes >= self::MAX_PROBES ) {
			return null;
		}
		++$this->probes;

		$size                = ( $this->probe )( $path );
		$this->cache[ $key ] = is_array( $size ) ? array( (int) $size[0], (int) $size[1] ) : array( 0, 0 );
		$this->dirty         = true;

		return is_array( $size ) ? array( (int) $size[0], (int) $size[1] ) : null;
	}

	/**
	 * Persist new cache entries (bounded).
	 */
	public function save(): void {
		if ( ! $this->dirty || null === $this->cache ) {
			return;
		}
		$this->cache = self::bound( $this->cache, self::MAX_ENTRIES );
		update_option( self::OPTION, $this->cache, false );
		$this->dirty = false;
	}

	/**
	 * Keep the newest entries when a map grows too large. Pure.
	 *
	 * @param array<string,mixed> $map Map (insertion ordered).
	 * @param int                 $max Maximum.
	 * @return array<string,mixed>
	 */
	public static function bound( array $map, int $max ): array {
		if ( count( $map ) <= $max ) {
			return $map;
		}
		return array_slice( $map, - (int) floor( $max * 0.75 ), null, true );
	}

	/**
	 * Load the cache option.
	 */
	private function load_cache(): void {
		if ( null !== $this->cache ) {
			return;
		}
		$raw         = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$this->cache = is_array( $raw ) ? $raw : array();
	}

	/**
	 * Measure a file with getimagesize(); it must resolve inside one of the allowed roots.
	 *
	 * @param string   $path  Path.
	 * @param string[] $roots Allowed directories.
	 * @return array{0:int,1:int}|null
	 */
	public static function probe_file( string $path, array $roots ): ?array {
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) ) {
			return null;
		}
		$real    = str_replace( '\\', '/', $real );
		$allowed = false;
		foreach ( $roots as $root ) {
			$root_real = realpath( $root );
			if ( false !== $root_real && 0 === strpos( $real, rtrim( str_replace( '\\', '/', $root_real ), '/' ) . '/' ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return null;
		}
		$size = @getimagesize( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt files must not emit warnings.
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return null;
		}
		return array( (int) $size[0], (int) $size[1] );
	}
}
