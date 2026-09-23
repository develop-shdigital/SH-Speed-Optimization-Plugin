<?php
/**
 * Downloads Google Fonts to this server (background job only).
 *
 * For each Google Fonts stylesheet seen on the site, the CSS is fetched with a
 * modern browser User-Agent (to get WOFF2), validated (only fonts.googleapis.com
 * CSS referencing fonts.gstatic.com font files, at most 50 files and 5 MB,
 * font signatures checked) and stored with its font files in the public cache
 * directory. The mapping (original URL → local CSS) lives in one small option.
 * Anything unexpected marks the entry as failed and the original links stay.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Fonts;

use SH\SpeedOptimizer\Core\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Font localizer.
 */
final class FontLocalizer {

	public const OPTION = 'shso_local_fonts';

	public const STATUS_PENDING = 'pending';
	public const STATUS_OK      = 'ok';
	public const STATUS_FAILED  = 'failed';

	/**
	 * Maximum remembered stylesheets.
	 */
	public const MAX_ENTRIES = 20;

	/**
	 * Maximum font files per stylesheet.
	 */
	public const MAX_FILES = 50;

	/**
	 * Maximum total bytes of font files per stylesheet.
	 */
	public const MAX_BYTES = 5242880;

	/**
	 * Maximum stylesheet size.
	 */
	public const MAX_CSS_BYTES = 524288;

	/**
	 * Maximum download attempts per stylesheet.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * User-Agent of a current desktop browser (Google serves WOFF2 to it).
	 */
	public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	/**
	 * Allowed font file extensions.
	 */
	private const FONT_EXTENSIONS = array( 'woff2', 'woff', 'ttf', 'otf' );

	/**
	 * Filesystem.
	 *
	 * @var Filesystem
	 */
	private Filesystem $fs;

	/**
	 * HTTP GET: function( string $url, array $args ): array{status:int,body:string,type:string,error:string}.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param Filesystem    $fs   Filesystem.
	 * @param callable|null $http HTTP client (defaults to wp_safe_remote_get()).
	 */
	public function __construct( Filesystem $fs, ?callable $http = null ) {
		$this->fs   = $fs;
		$this->http = $http ?? array( self::class, 'http_get' );
	}

	// ---------------------------------------------------------------------
	// Mapping store.
	// ---------------------------------------------------------------------

	/**
	 * All entries: md5(key) => entry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function mapping(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Entry id of a stylesheet URL.
	 *
	 * @param string $url URL.
	 */
	public static function id( string $url ): string {
		return md5( GoogleFonts::key( $url ) );
	}

	/**
	 * Remember stylesheet URLs for localization (bounded). Returns the number of new entries.
	 *
	 * @param string[] $urls URLs.
	 */
	public static function remember( array $urls ): int {
		$map   = self::mapping();
		$added = 0;
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( ! in_array( GoogleFonts::api( $url ), array( 'css', 'css2', 'icon' ), true ) || strlen( $url ) > 2000 ) {
				continue;
			}
			$id = self::id( $url );
			if ( isset( $map[ $id ] ) || count( $map ) >= self::MAX_ENTRIES ) {
				continue;
			}
			$map[ $id ] = array(
				'url'      => $url,
				'status'   => self::STATUS_PENDING,
				'css'      => '',
				'files'    => 0,
				'bytes'    => 0,
				'attempts' => 0,
				'error'    => '',
				'updated'  => time(),
				'next_try' => 0,
			);
			++$added;
		}
		if ( $added > 0 ) {
			update_option( self::OPTION, $map, true );
		}
		return $added;
	}

	/**
	 * Mark entries for a new download (their local files disappeared).
	 *
	 * @param string[] $ids Entry ids.
	 */
	public static function reset( array $ids ): void {
		$map     = self::mapping();
		$changed = false;
		foreach ( $ids as $id ) {
			if ( isset( $map[ $id ] ) && self::STATUS_OK === $map[ $id ]['status'] ) {
				$map[ $id ]['status']   = self::STATUS_PENDING;
				$map[ $id ]['attempts'] = 0;
				$changed                = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $map, true );
		}
	}

	/**
	 * Entries due for a download attempt.
	 *
	 * @param array<string,array<string,mixed>> $map Mapping.
	 * @param int                               $now Timestamp.
	 * @return string[] Entry ids.
	 */
	public static function due( array $map, int $now ): array {
		$due = array();
		foreach ( $map as $id => $entry ) {
			$status = (string) ( $entry['status'] ?? '' );
			if ( self::STATUS_PENDING === $status || ( self::STATUS_FAILED === $status && (int) ( $entry['attempts'] ?? 0 ) < self::MAX_ATTEMPTS && (int) ( $entry['next_try'] ?? 0 ) <= $now ) ) {
				$due[] = (string) $id;
			}
		}
		return $due;
	}

	// ---------------------------------------------------------------------
	// Background work.
	// ---------------------------------------------------------------------

	/**
	 * Process due entries.
	 *
	 * @param int $max Maximum stylesheets in this run.
	 * @return int Remaining due entries.
	 */
	public function run( int $max = 3 ): int {
		$map  = self::mapping();
		$done = 0;
		foreach ( self::due( $map, time() ) as $id ) {
			if ( $done >= $max ) {
				break;
			}
			$map[ $id ] = $this->localize( $id, $map[ $id ] );
			++$done;
			update_option( self::OPTION, $map, true );
		}
		return count( self::due( self::mapping(), time() ) );
	}

	/**
	 * Download one stylesheet and its fonts.
	 *
	 * @param string              $id    Entry id.
	 * @param array<string,mixed> $entry Entry.
	 * @return array<string,mixed> Updated entry.
	 */
	public function localize( string $id, array $entry ): array {
		$entry['updated'] = time();
		$dir_name         = substr( $id, 0, 12 );
		$root             = $this->fs->cache_dir( 'fonts', true );
		$dir              = $root . $dir_name . '/';

		try {
			$files = $this->download( (string) $entry['url'], $dir );
		} catch ( \RuntimeException $e ) {
			$this->fs->delete_tree( $dir );
			$entry['status']   = self::STATUS_FAILED;
			$entry['error']    = substr( $e->getMessage(), 0, 200 );
			$entry['attempts'] = (int) ( $entry['attempts'] ?? 0 ) + 1;
			$entry['next_try'] = time() + $entry['attempts'] * 6 * 3600;
			$entry['css']      = '';
			return $entry;
		}

		$entry['status']   = self::STATUS_OK;
		$entry['css']      = $dir_name . '/fonts.css';
		$entry['files']    = $files['files'];
		$entry['bytes']    = $files['bytes'];
		$entry['error']    = '';
		$entry['attempts'] = 0;
		return $entry;
	}

	/**
	 * Download and store. Throws on any validation failure.
	 *
	 * @param string $url Stylesheet URL.
	 * @param string $dir Target directory.
	 * @return array{files:int,bytes:int}
	 * @throws \RuntimeException On failure.
	 */
	private function download( string $url, string $dir ): array {
		if ( ! in_array( GoogleFonts::api( $url ), array( 'css', 'css2', 'icon' ), true ) ) {
			throw new \RuntimeException( 'Not a Google Fonts stylesheet.' );
		}
		$css_url  = 'https:' . preg_replace( '#^https?:#i', '', trim( $url ) );
		$response = $this->get( $css_url, self::MAX_CSS_BYTES );
		if ( '' !== $response['type'] && false === stripos( $response['type'], 'text/css' ) ) {
			throw new \RuntimeException( 'Unexpected stylesheet type.' );
		}
		$css  = $response['body'];
		$urls = self::extract_urls( $css );
		if ( empty( $urls ) ) {
			throw new \RuntimeException( 'The stylesheet references no fonts.' );
		}
		if ( count( $urls ) > self::MAX_FILES ) {
			throw new \RuntimeException( 'The stylesheet references too many font files.' );
		}
		foreach ( $urls as $font_url ) {
			if ( ! self::is_allowed_font_url( $font_url ) ) {
				throw new \RuntimeException( 'The stylesheet references a file outside fonts.gstatic.com.' );
			}
		}

		$this->fs->delete_tree( $dir );
		$map   = array();
		$bytes = 0;
		$names = array();
		foreach ( $urls as $font_url ) {
			$font  = $this->get( 'https:' . preg_replace( '#^https?:#i', '', $font_url ), self::MAX_BYTES - $bytes );
			$body  = $font['body'];
			$ext   = self::font_type( $body );
			$bytes = $bytes + strlen( $body );
			if ( '' === $ext ) {
				throw new \RuntimeException( 'A downloaded file is not a font.' );
			}
			if ( $bytes > self::MAX_BYTES ) {
				throw new \RuntimeException( 'The fonts are larger than allowed.' );
			}
			$name = self::local_name( $font_url, $ext );
			if ( isset( $names[ $name ] ) ) {
				$name = substr( md5( $font_url ), 0, 12 ) . '.' . $ext;
			}
			$names[ $name ] = true;
			if ( ! $this->fs->write( $dir . $name, $body ) ) {
				throw new \RuntimeException( 'A font file could not be saved.' );
			}
			$map[ $font_url ] = $name;
		}

		$local = self::rewrite_css( $css, $map );
		$query = GoogleFonts::parse( $url );
		if ( null === $query['display'] || 'swap' === strtolower( (string) $query['display'] ) ) {
			$local = FontFaceTransformer::add_swap( $local );
		}
		if ( ! $this->fs->write( $dir . 'fonts.css', $local ) ) {
			throw new \RuntimeException( 'The stylesheet could not be saved.' );
		}

		return array(
			'files' => count( $map ),
			'bytes' => $bytes,
		);
	}

	/**
	 * GET with validation of the status code and size.
	 *
	 * @param string $url       URL.
	 * @param int    $max_bytes Maximum body size.
	 * @return array{status:int,body:string,type:string,error:string}
	 * @throws \RuntimeException On failure.
	 */
	private function get( string $url, int $max_bytes ): array {
		if ( $max_bytes <= 0 ) {
			throw new \RuntimeException( 'The fonts are larger than allowed.' );
		}
		$response = (array) ( $this->http )(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'user-agent'          => self::USER_AGENT,
				'limit_response_size' => $max_bytes + 1,
			)
		);
		$response = array_merge(
			array(
				'status' => 0,
				'body'   => '',
				'type'   => '',
				'error'  => '',
			),
			$response
		);
		if ( 200 !== (int) $response['status'] ) {
			throw new \RuntimeException( '' !== $response['error'] ? 'Download failed: ' . $response['error'] : 'Download failed with HTTP status ' . (int) $response['status'] . '.' );
		}
		if ( strlen( (string) $response['body'] ) > $max_bytes || '' === (string) $response['body'] ) {
			throw new \RuntimeException( 'Unexpected download size.' );
		}
		return $response;
	}

	/**
	 * Default HTTP client.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Arguments.
	 * @return array{status:int,body:string,type:string,error:string}
	 */
	public static function http_get( string $url, array $args ): array {
		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'type'   => '',
				'error'  => $response->get_error_message(),
			);
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'type'   => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'error'  => '',
		);
	}

	// ---------------------------------------------------------------------
	// Pure helpers.
	// ---------------------------------------------------------------------

	/**
	 * Unique url() references in CSS (comments ignored).
	 *
	 * @param string $css CSS.
	 * @return string[]
	 */
	public static function extract_urls( string $css ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( ! preg_match_all( '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $css, $m ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'trim', $m[2] ) ) );
	}

	/**
	 * Replace url() references according to a map (original URL => replacement).
	 *
	 * @param string               $css CSS.
	 * @param array<string,string> $map Map.
	 */
	public static function rewrite_css( string $css, array $map ): string {
		return (string) preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
			static function ( $m ) use ( $map ) {
				$url = trim( $m[2] );
				return isset( $map[ $url ] ) ? 'url(' . $map[ $url ] . ')' : $m[0];
			},
			$css
		);
	}

	/**
	 * Whether a URL is a font file on fonts.gstatic.com.
	 *
	 * @param string $url URL.
	 */
	public static function is_allowed_font_url( string $url ): bool {
		return (bool) preg_match( '#^(?:https:)?//fonts\.gstatic\.com/[A-Za-z0-9/_\-.~%?=&]+$#', trim( $url ) ) && false === strpos( $url, '..' );
	}

	/**
	 * Font type from the file signature ('' when not a font).
	 *
	 * @param string $body File contents.
	 */
	public static function font_type( string $body ): string {
		$magic = substr( $body, 0, 4 );
		switch ( $magic ) {
			case 'wOF2':
				return 'woff2';
			case 'wOFF':
				return 'woff';
			case "\x00\x01\x00\x00":
			case 'true':
				return 'ttf';
			case 'OTTO':
				return 'otf';
		}
		return '';
	}

	/**
	 * Safe local file name for a font URL.
	 *
	 * @param string $url URL.
	 * @param string $ext Detected extension.
	 */
	public static function local_name( string $url, string $ext ): string {
		$ext  = in_array( $ext, self::FONT_EXTENSIONS, true ) ? $ext : 'woff2';
		$path = (string) preg_replace( '#^(?:https?:)?//[^/]+#i', '', (string) preg_replace( '/[?#].*$/s', '', $url ) );
		$path = (string) preg_replace( '#^/s/#', '', $path );
		$name = (string) preg_replace( '/\.(?:woff2?|ttf|otf|eot)$/i', '', $path );
		$name = trim( (string) preg_replace( '/[^A-Za-z0-9_\-]+/', '-', $name ), '-' );
		if ( '' === $name || strlen( $name ) > 100 || false !== strpos( $url, '?' ) ) {
			$name = ( '' === $name ? 'font' : substr( $name, 0, 40 ) ) . '-' . substr( md5( $url ), 0, 10 );
		}
		return $name . '.' . $ext;
	}
}
