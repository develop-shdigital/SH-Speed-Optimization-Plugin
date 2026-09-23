<?php
/**
 * Server-side page analysis.
 *
 * Two halves:
 *  1. During a signed analysis request (loopback from the scanner) WordPress'
 *     own script/style registry, generation time and database queries are
 *     captured and stored in a short-lived transient.
 *  2. The scanner then parses the returned HTML and merges both into the page
 *     analysis consumed by the optimizations' detection logic.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;
use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Page analyzer.
 */
final class PageAnalyzer {

	public const TRANSIENT_PREFIX = 'shso_analysis_';

	/**
	 * Captured data for the current analysis request.
	 *
	 * @var array<string,mixed>
	 */
	private static array $captured = array();

	/**
	 * Start capturing (called at plugin load for signed analysis requests).
	 *
	 * @param Plugin              $plugin  Plugin.
	 * @param array<string,mixed> $payload Verified token payload.
	 */
	public static function start_capture( Plugin $plugin, array $payload ): void {
		unset( $plugin );
		$nonce = preg_replace( '/[^a-f0-9]/', '', (string) ( $payload['n'] ?? '' ) );
		if ( '' === $nonce ) {
			return;
		}

		if ( class_exists( '\SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector' ) ) {
			\SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector::start();
		}

		add_action( 'wp_print_footer_scripts', array( self::class, 'capture_assets' ), PHP_INT_MAX );
		add_action(
			'shutdown',
			static function () use ( $nonce ) {
				self::finish_capture( $nonce );
			},
			0
		);
	}

	/**
	 * Capture WordPress' script and style registries after everything was printed.
	 */
	public static function capture_assets(): void {
		$scripts = array();
		$styles  = array();

		$wp_scripts = wp_scripts();
		foreach ( (array) $wp_scripts->done as $handle ) {
			$dep = $wp_scripts->registered[ $handle ] ?? null;
			if ( ! $dep ) {
				continue;
			}
			$scripts[ $handle ] = array(
				'src'           => is_string( $dep->src ) ? $dep->src : '',
				'deps'          => array_values( (array) $dep->deps ),
				'footer'        => 1 === (int) $wp_scripts->get_data( $handle, 'group' ) || in_array( $handle, (array) $wp_scripts->in_footer, true ),
				'inline_after'  => ! empty( $wp_scripts->get_data( $handle, 'after' ) ),
				'inline_before' => ! empty( $wp_scripts->get_data( $handle, 'before' ) ) || ! empty( $wp_scripts->get_data( $handle, 'data' ) ),
				'strategy'      => (string) $wp_scripts->get_data( $handle, 'strategy' ),
			);
		}

		$wp_styles = wp_styles();
		foreach ( (array) $wp_styles->done as $handle ) {
			$dep = $wp_styles->registered[ $handle ] ?? null;
			if ( ! $dep ) {
				continue;
			}
			$styles[ $handle ] = array(
				'src'   => is_string( $dep->src ) ? $dep->src : '',
				'deps'  => array_values( (array) $dep->deps ),
				'media' => (string) $dep->args,
			);
		}

		self::$captured['scripts'] = $scripts;
		self::$captured['styles']  = $styles;
	}

	/**
	 * Store the captured data.
	 *
	 * @param string $nonce Token nonce.
	 */
	private static function finish_capture( string $nonce ): void {
		$data = self::$captured;

		$data['template']      = Context::template_key();
		$data['generation_ms'] = function_exists( 'timer_float' ) ? (int) round( 1000 * timer_float() ) : null;
		$data['memory_peak']   = memory_get_peak_usage( true );
		$data['queries']       = null;

		if ( class_exists( '\SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector' ) ) {
			try {
				$data['queries'] = \SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector::report();
			} catch ( \Throwable $e ) {
				$data['queries'] = null;
			}
		}

		set_transient( self::TRANSIENT_PREFIX . $nonce, $data, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Fetch (and delete) captured data for a token nonce.
	 *
	 * @param string $nonce Token nonce.
	 * @return array<string,mixed>
	 */
	public static function captured( string $nonce ): array {
		$key  = self::TRANSIENT_PREFIX . preg_replace( '/[^a-f0-9]/', '', $nonce );
		$data = get_transient( $key );
		delete_transient( $key );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build the page analysis from HTML plus captured WordPress data.
	 *
	 * @param string              $html     Markup.
	 * @param string              $url      URL.
	 * @param array<string,mixed> $captured Captured data (may be empty).
	 * @param array<string,mixed> $response Loopback response meta (status, ttfb_ms, time_ms).
	 * @return array<string,mixed>
	 */
	public static function analyze( string $html, string $url, array $captured, array $response ): array {
		$doc = new HtmlDocument( $html );

		$analysis = array(
			'url'           => $url,
			'template'      => (string) ( $captured['template'] ?? 'unknown' ),
			'status'        => (int) ( $response['status'] ?? 0 ),
			'ttfb_ms'       => (int) ( $response['ttfb_ms'] ?? 0 ),
			'time_ms'       => (int) ( $response['time_ms'] ?? 0 ),
			'generation_ms' => isset( $captured['generation_ms'] ) ? (int) $captured['generation_ms'] : null,
			'html_bytes'    => strlen( $html ),
			'scripts'       => array(),
			'styles'        => array(),
			'inline'        => array(
				'scripts'      => 0,
				'script_bytes' => 0,
				'styles'       => 0,
				'style_bytes'  => 0,
				'globals'      => array(),
			),
			'images'        => array(
				'count'              => 0,
				'lazy'               => 0,
				'eager'              => 0,
				'missing_dimensions' => 0,
				'missing_alt'        => 0,
				'fetchpriority_high' => 0,
				'local'              => 0,
				'external'           => 0,
				'largest'            => array(),
				'first'              => null,
			),
			'iframes'       => array(),
			'third_party'   => array(),
			'fonts'         => array(
				'google'               => array(),
				'adobe'                => false,
				'preloads'             => 0,
				'font_faces'           => 0,
				'font_display_missing' => 0,
			),
			'queries'       => $captured['queries'] ?? null,
			'markers'       => Verifier::markers( $html ),
			'hints'         => array(
				'preconnect' => 0,
				'preload'    => 0,
			),
		);

		// Markup signatures of plugins that often load assets everywhere (for "loaded but unused" hints).
		$analysis['signatures'] = array();
		foreach ( self::markup_signatures() as $slug => $needle ) {
			$analysis['signatures'][ $slug ] = false !== stripos( $html, $needle );
		}

		self::analyze_scripts( $doc, $captured, $analysis );
		self::analyze_styles( $doc, $captured, $analysis );
		self::analyze_images( $doc, $analysis );
		self::analyze_iframes( $doc, $analysis );
		self::analyze_hints( $doc, $analysis );

		$analysis['requests'] = array(
			'scripts'     => count( array_filter( $analysis['scripts'], static fn( $s ) => '' !== (string) $s['src'] ) ),
			'styles'      => count( $analysis['styles'] ),
			'images'      => $analysis['images']['count'],
			'iframes'     => count( $analysis['iframes'] ),
			'third_party' => count( $analysis['third_party'] ),
		);
		$analysis['bytes']    = array(
			'css' => array_sum( array_map( static fn( $s ) => (int) ( $s['bytes'] ?? 0 ), $analysis['styles'] ) ),
			'js'  => array_sum( array_map( static fn( $s ) => (int) ( $s['bytes'] ?? 0 ), $analysis['scripts'] ) ),
		);

		return $analysis;
	}

	/**
	 * Scripts, inline scripts and third-party detection.
	 *
	 * @param HtmlDocument        $doc      Document.
	 * @param array<string,mixed> $captured Captured WordPress data.
	 * @param array<string,mixed> $analysis Analysis (by reference).
	 */
	private static function analyze_scripts( HtmlDocument $doc, array $captured, array &$analysis ): void {
		$registry = (array) ( $captured['scripts'] ?? array() );
		$seen_tp  = array();
		$globals  = array();

		$doc->replace_scripts(
			static function ( Tag $tag, string $code, array $info ) use ( $registry, &$analysis, &$seen_tp, &$globals ) {
				$type = strtolower( (string) $tag->get( 'type' ) );
				if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module', 'text/ecmascript' ), true ) ) {
					return null; // JSON, templates, speculation rules …
				}

				$src = (string) $tag->get( 'src' );
				if ( '' === $src ) {
					++$analysis['inline']['scripts'];
					$analysis['inline']['script_bytes'] += strlen( $code );
					foreach ( array( 'jQuery', 'elementorFrontend', 'wc_add_to_cart_params', 'wp.', 'gtag', 'fbq', 'dataLayer' ) as $global ) {
						if ( false !== strpos( $code, $global ) ) {
							$globals[ $global ] = true;
						}
					}
					if ( preg_match( '/(^|[^\w.$])\$\(/', $code ) ) {
						$globals['$'] = true;
					}
					$match = self::third_party_inline( $code );
					if ( null !== $match && ! isset( $seen_tp[ $match['id'] ] ) ) {
						$seen_tp[ $match['id'] ]     = true;
						$analysis['third_party'][] = array_merge(
							$match,
							array(
								'src'      => null,
								'inline'   => true,
								'blocking' => true,
							)
						);
					}
					return null;
				}

				$handle = null;
				$id     = (string) $tag->get( 'id' );
				if ( preg_match( '/^(.+)-js$/', $id, $m ) && isset( $registry[ $m[1] ] ) ) {
					$handle = $m[1];
				}

				$local    = self::is_local( $src );
				$resolved = $local ? self::resolve_local( $src ) : null;
				$async    = $tag->has( 'async' );
				$defer    = $tag->has( 'defer' );
				$module   = 'module' === $type;

				$analysis['scripts'][] = array(
					'handle'       => $handle,
					'src'          => $src,
					'local'        => $local,
					'in_head'      => (bool) $info['in_head'],
					'async'        => $async,
					'defer'        => $defer,
					'module'       => $module,
					'bytes'        => $resolved['bytes'] ?? null,
					'minified'     => $resolved['minified'] ?? ( false !== strpos( $src, '.min.js' ) ),
					'source'       => array(
						'type' => $resolved['source_type'] ?? ( $local ? 'other' : 'external' ),
						'slug' => $resolved['source_slug'] ?? (string) wp_parse_url( $src, PHP_URL_HOST ),
						'name' => $resolved['source_name'] ?? (string) wp_parse_url( $src, PHP_URL_HOST ),
					),
					'deps'         => null !== $handle ? (array) $registry[ $handle ]['deps'] : array(),
					'inline_after' => null !== $handle && ! empty( $registry[ $handle ]['inline_after'] ),
					'render_blocking' => (bool) $info['in_head'] && ! $async && ! $defer && ! $module,
				);

				if ( ! $local ) {
					$match = self::third_party_url( $src );
					if ( null !== $match && ! isset( $seen_tp[ $match['id'] ] ) ) {
						$seen_tp[ $match['id'] ]     = true;
						$analysis['third_party'][] = array_merge(
							$match,
							array(
								'src'      => $src,
								'inline'   => false,
								'blocking' => ! $async && ! $defer,
							)
						);
					}
				}

				return null;
			}
		);

		$analysis['inline']['globals'] = array_keys( $globals );
	}

	/**
	 * Stylesheets and fonts.
	 *
	 * @param HtmlDocument        $doc      Document.
	 * @param array<string,mixed> $captured Captured data.
	 * @param array<string,mixed> $analysis Analysis (by reference).
	 */
	private static function analyze_styles( HtmlDocument $doc, array $captured, array &$analysis ): void {
		$registry = (array) ( $captured['styles'] ?? array() );

		$doc->replace_tags(
			'link',
			static function ( Tag $tag, array $info ) use ( $registry, &$analysis ) {
				$rel  = strtolower( (string) $tag->get( 'rel' ) );
				$href = (string) $tag->get( 'href' );
				if ( '' === $href ) {
					return null;
				}

				if ( false !== strpos( $href, 'fonts.googleapis.com' ) && ( false !== strpos( $rel, 'stylesheet' ) || 'preload' === $rel ) ) {
					$families = array();
					if ( class_exists( '\SH\SpeedOptimizer\Assets\Fonts\GoogleFonts' ) ) {
						try {
							$parsed   = \SH\SpeedOptimizer\Assets\Fonts\GoogleFonts::parse( $href );
							$families = (array) ( $parsed['families'] ?? $parsed );
						} catch ( \Throwable $e ) {
							$families = array();
						}
					}
					$query                        = (string) wp_parse_url( $href, PHP_URL_QUERY );
					$analysis['fonts']['google'][] = array(
						'url'      => $href,
						'families' => $families,
						'display'  => (bool) preg_match( '/(^|&)display=/', $query ),
					);
				}
				if ( false !== strpos( $href, 'use.typekit.net' ) || false !== strpos( $href, 'p.typekit.net' ) ) {
					$analysis['fonts']['adobe'] = true;
				}
				if ( 'preload' === $rel && 'font' === strtolower( (string) $tag->get( 'as' ) ) ) {
					++$analysis['fonts']['preloads'];
				}
				if ( false === strpos( $rel, 'stylesheet' ) ) {
					return null;
				}

				$handle = null;
				$id     = (string) $tag->get( 'id' );
				if ( preg_match( '/^(.+)-css$/', $id, $m ) && isset( $registry[ $m[1] ] ) ) {
					$handle = $m[1];
				}
				$local    = self::is_local( $href );
				$resolved = $local ? self::resolve_local( $href ) : null;
				$media    = strtolower( (string) ( $tag->get( 'media' ) ?? 'all' ) );

				$analysis['styles'][] = array(
					'handle'          => $handle,
					'href'            => $href,
					'local'           => $local,
					'media'           => '' === $media ? 'all' : $media,
					'in_head'         => (bool) $info['in_head'],
					'bytes'           => $resolved['bytes'] ?? null,
					'minified'        => $resolved['minified'] ?? ( false !== strpos( $href, '.min.css' ) ),
					'source'          => array(
						'type' => $resolved['source_type'] ?? ( $local ? 'other' : 'external' ),
						'slug' => $resolved['source_slug'] ?? (string) wp_parse_url( $href, PHP_URL_HOST ),
						'name' => $resolved['source_name'] ?? (string) wp_parse_url( $href, PHP_URL_HOST ),
					),
					'render_blocking' => (bool) $info['in_head'] && in_array( $media, array( '', 'all', 'screen' ), true ),
				);
				return null;
			}
		);

		$doc->replace_styles(
			static function ( Tag $tag, string $css ) use ( &$analysis ) {
				++$analysis['inline']['styles'];
				$analysis['inline']['style_bytes'] += strlen( $css );
				if ( preg_match_all( '/@font-face\s*\{([^}]*)\}/i', $css, $m ) ) {
					foreach ( $m[1] as $body ) {
						++$analysis['fonts']['font_faces'];
						if ( false === stripos( $body, 'font-display' ) ) {
							++$analysis['fonts']['font_display_missing'];
						}
					}
				}
				if ( false !== strpos( $css, 'fonts.googleapis.com' ) && preg_match_all( '#@import\s+url\(\s*["\']?([^"\')]+fonts\.googleapis\.com[^"\')]+)#i', $css, $imports ) ) {
					foreach ( $imports[1] as $href ) {
						$analysis['fonts']['google'][] = array(
							'url'      => $href,
							'families' => array(),
							'display'  => false !== strpos( $href, 'display=' ),
						);
					}
				}
				return null;
			}
		);
	}

	/**
	 * Images.
	 *
	 * @param HtmlDocument        $doc      Document.
	 * @param array<string,mixed> $analysis Analysis (by reference).
	 */
	private static function analyze_images( HtmlDocument $doc, array &$analysis ): void {
		$sizes = array();

		$doc->replace_tags(
			'img',
			static function ( Tag $tag, array $info ) use ( &$analysis, &$sizes ) {
				$img = &$analysis['images'];
				++$img['count'];

				$src     = (string) ( $tag->get( 'src' ) ?? '' );
				$loading = strtolower( (string) $tag->get( 'loading' ) );
				if ( 'lazy' === $loading ) {
					++$img['lazy'];
				} elseif ( 'eager' === $loading ) {
					++$img['eager'];
				}
				if ( ! $tag->has( 'width' ) || ! $tag->has( 'height' ) ) {
					if ( false === stripos( $src, '.svg' ) && ! str_starts_with( $src, 'data:' ) ) {
						++$img['missing_dimensions'];
					}
				}
				if ( ! $tag->has( 'alt' ) ) {
					++$img['missing_alt'];
				}
				if ( 'high' === strtolower( (string) $tag->get( 'fetchpriority' ) ) ) {
					++$img['fetchpriority_high'];
				}

				$local = '' !== $src && self::is_local( $src );
				if ( $local ) {
					++$img['local'];
					if ( count( $sizes ) < 60 ) {
						$path = self::local_path( $src );
						if ( null !== $path ) {
							$sizes[ $src ] = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						}
					}
				} elseif ( '' !== $src && ! str_starts_with( $src, 'data:' ) ) {
					++$img['external'];
				}

				if ( null === $img['first'] && '' !== $src && ! str_starts_with( $src, 'data:' ) ) {
					$img['first'] = array(
						'src'     => $src,
						'lazy'    => 'lazy' === $loading,
						'index'   => (int) $info['index'],
						'in_head' => (bool) $info['in_head'],
					);
				}
				return null;
			}
		);

		arsort( $sizes );
		foreach ( array_slice( $sizes, 0, 5, true ) as $src => $bytes ) {
			if ( $bytes > 0 ) {
				$analysis['images']['largest'][] = array(
					'src'   => $src,
					'bytes' => $bytes,
				);
			}
		}
	}

	/**
	 * Iframes.
	 *
	 * @param HtmlDocument        $doc      Document.
	 * @param array<string,mixed> $analysis Analysis (by reference).
	 */
	private static function analyze_iframes( HtmlDocument $doc, array &$analysis ): void {
		$doc->replace_tags(
			'iframe',
			static function ( Tag $tag, array $info ) use ( &$analysis ) {
				$src  = (string) ( $tag->get( 'src' ) ?? $tag->get( 'data-src' ) ?? '' );
				$kind = 'other';
				if ( preg_match( '#(youtube\.com|youtube-nocookie\.com)/embed/#i', $src ) ) {
					$kind = 'youtube';
				} elseif ( false !== stripos( $src, 'player.vimeo.com/video/' ) ) {
					$kind = 'vimeo';
				} elseif ( preg_match( '#google\.[a-z.]+/maps|maps\.google\.#i', $src ) ) {
					$kind = 'google_maps';
				}
				$analysis['iframes'][] = array(
					'src'      => $src,
					'kind'     => $kind,
					'lazy'     => 'lazy' === strtolower( (string) $tag->get( 'loading' ) ),
					'position' => (int) $info['index'],
				);
				return null;
			}
		);
	}

	/**
	 * Resource hints.
	 *
	 * @param HtmlDocument        $doc      Document.
	 * @param array<string,mixed> $analysis Analysis (by reference).
	 */
	private static function analyze_hints( HtmlDocument $doc, array &$analysis ): void {
		$doc->replace_tags(
			'link',
			static function ( Tag $tag ) use ( &$analysis ) {
				$rel = strtolower( (string) $tag->get( 'rel' ) );
				if ( 'preconnect' === $rel || 'dns-prefetch' === $rel ) {
					++$analysis['hints']['preconnect'];
				} elseif ( 'preload' === $rel ) {
					++$analysis['hints']['preload'];
				}
				return null;
			}
		);
	}

	/**
	 * Plugin slug → markup that proves the plugin's feature is used on a page.
	 *
	 * @return array<string,string>
	 */
	public static function markup_signatures(): array {
		/**
		 * Filters the plugin markup signatures used to spot assets loaded on pages that don't use them.
		 *
		 * @param array<string,string> $signatures Plugin slug => HTML fragment.
		 */
		return (array) apply_filters(
			'shso_markup_signatures',
			array(
				'contact-form-7'    => 'wpcf7',
				'wpforms-lite'      => 'wpforms-container',
				'wpforms'           => 'wpforms-container',
				'gravityforms'      => 'gform_wrapper',
				'ninja-forms'       => 'nf-form-cont',
				'fluentform'        => 'fluentform',
				'formidable'        => 'frm_forms',
				'forminator'        => 'forminator-custom-form',
				'mailchimp-for-wp'  => 'mc4wp-form',
				'revslider'         => 'rs-module',
				'smart-slider-3'    => 'n2-section-smartslider',
				'LayerSlider'       => 'ls-container',
				'ml-slider'         => 'metaslider',
				'wp-google-maps'    => 'wpgmza_map',
				'tablepress'        => 'tablepress',
				'the-events-calendar' => 'tribe-events',
				'bbpress'           => 'bbpress-forums',
			)
		);
	}

	/**
	 * Third-party match for a script URL.
	 *
	 * @param string $url URL.
	 * @return array{id:string,name:string,category:string}|null
	 */
	private static function third_party_url( string $url ): ?array {
		if ( class_exists( '\SH\SpeedOptimizer\Assets\ThirdPartyCatalog' ) ) {
			$match = \SH\SpeedOptimizer\Assets\ThirdPartyCatalog::match_url( $url );
			if ( is_array( $match ) ) {
				return array(
					'id'       => (string) $match['id'],
					'name'     => (string) $match['name'],
					'category' => (string) ( $match['category'] ?? 'other' ),
				);
			}
			return null;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' === $host ? null : array(
			'id'       => sanitize_key( $host ),
			'name'     => $host,
			'category' => 'other',
		);
	}

	/**
	 * Third-party match for inline code.
	 *
	 * @param string $code Code.
	 * @return array{id:string,name:string,category:string}|null
	 */
	private static function third_party_inline( string $code ): ?array {
		if ( ! class_exists( '\SH\SpeedOptimizer\Assets\ThirdPartyCatalog' ) ) {
			return null;
		}
		$match = \SH\SpeedOptimizer\Assets\ThirdPartyCatalog::match_inline( $code );
		if ( ! is_array( $match ) ) {
			return null;
		}
		return array(
			'id'       => (string) $match['id'],
			'name'     => (string) $match['name'],
			'category' => (string) ( $match['category'] ?? 'other' ),
		);
	}

	/**
	 * Whether a URL points to this site.
	 *
	 * @param string $url URL.
	 */
	public static function is_local( string $url ): bool {
		if ( str_starts_with( $url, 'data:' ) ) {
			return false;
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return true; // Relative URL.
		}
		$hosts = array_unique(
			array(
				strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( content_url(), PHP_URL_HOST ) ),
			)
		);
		return in_array( strtolower( $host ), $hosts, true );
	}

	/**
	 * Resolve a local CSS/JS URL (size, minified flag, source attribution).
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>|null
	 */
	private static function resolve_local( string $url ): ?array {
		if ( class_exists( '\SH\SpeedOptimizer\Assets\AssetSource' ) ) {
			try {
				$resolved = \SH\SpeedOptimizer\Assets\AssetSource::resolve( $url );
				return is_array( $resolved ) ? $resolved : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		$path = self::local_path( $url );
		return null === $path ? null : array(
			'path'     => $path,
			'bytes'    => (int) filesize( $path ),
			'minified' => (bool) preg_match( '/\.min\.(css|js)$/', $path ),
		);
	}

	/**
	 * Map a local URL to a readable file inside ABSPATH/WP_CONTENT_DIR.
	 *
	 * @param string $url URL.
	 */
	public static function local_path( string $url ): ?string {
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return null;
		}

		$candidates = array();
		$content    = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		if ( '' !== $content && str_starts_with( $path, $content . '/' ) ) {
			$candidates[] = WP_CONTENT_DIR . substr( $path, strlen( $content ) );
		}
		$home = rtrim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );
		if ( '' === $home || str_starts_with( $path, $home . '/' ) ) {
			$candidates[] = rtrim( ABSPATH, '/' ) . substr( $path, strlen( $home ) );
		}

		$roots = array_filter( array( realpath( ABSPATH ), realpath( WP_CONTENT_DIR ) ) );
		foreach ( $candidates as $candidate ) {
			$real = realpath( $candidate );
			if ( false === $real || ! is_file( $real ) ) {
				continue;
			}
			foreach ( $roots as $root ) {
				if ( str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
					return $real;
				}
			}
		}
		return null;
	}
}
