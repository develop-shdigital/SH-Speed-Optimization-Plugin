<?php
/**
 * Turns scan data into plain-language findings.
 *
 * Every finding is backed by a real measurement or count from the scan. No
 * invented savings: where an exact number is unknown the wording says
 * "potential improvement" without a figure.
 *
 * Finding shape: id, category, severity (good|info|notice|warning|critical),
 * title, description, recommendation, optimization (id|null), data, source.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Optimization\Decision;

defined( 'ABSPATH' ) || exit;

/**
 * Findings builder.
 */
final class FindingsBuilder {

	/**
	 * Collected findings.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $findings = array();

	/**
	 * Build findings from a scan.
	 *
	 * @param array<string,mixed> $scan Scan data: profile, pages, browser, database_findings, query_findings,
	 *                                  media, decisions, cache, active (ids).
	 * @return array<int,array<string,mixed>>
	 */
	public function build( array $scan ): array {
		$this->findings = array();

		$profile   = (array) ( $scan['profile'] ?? array() );
		$pages     = array_filter( (array) ( $scan['pages'] ?? array() ), static fn( $p ) => is_array( $p ) && 200 === (int) ( $p['status'] ?? 0 ) );
		$decisions = (array) ( $scan['decisions'] ?? array() );
		$active    = (array) ( $scan['active'] ?? array() );

		$this->server( $profile, $pages );
		$this->conflicts( $profile );
		$this->cache( (array) ( $scan['cache'] ?? array() ), $profile, $active );
		$this->pages( $pages, $active );
		$this->plugins_assets( $pages, $profile );
		$this->media( (array) ( $scan['media'] ?? array() ), $active );
		$this->browser( (array) ( $scan['browser'] ?? array() ) );

		foreach ( array_merge( (array) ( $scan['database_findings'] ?? array() ), (array) ( $scan['query_findings'] ?? array() ) ) as $finding ) {
			if ( is_array( $finding ) && ! empty( $finding['id'] ) ) {
				$this->findings[ (string) $finding['id'] ] = self::normalize( $finding );
			}
		}

		$this->recommendations( $decisions );

		return array_values( $this->findings );
	}

	/**
	 * Split findings into the report sections.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return array{good:array,attention:array,improvements:array}
	 */
	public static function report( array $findings ): array {
		$good         = array();
		$attention    = array();
		$improvements = array();
		$order        = array(
			'critical' => 0,
			'warning'  => 1,
			'notice'   => 2,
			'info'     => 3,
		);

		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'info' );
			if ( 'good' === $severity ) {
				$good[] = $finding;
			} elseif ( in_array( $severity, array( 'critical', 'warning', 'notice' ), true ) ) {
				$attention[] = $finding;
			}
			if ( 'good' !== $severity && '' !== (string) ( $finding['recommendation'] ?? '' ) && ( ! empty( $finding['optimization'] ) || 'info' !== $severity ) ) {
				$improvements[] = $finding;
			}
		}

		$sort = static function ( $a, $b ) use ( $order ) {
			return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
		};
		usort( $attention, $sort );
		usort( $improvements, $sort );

		return array(
			'good'         => $good,
			'attention'    => $attention,
			'improvements' => array_slice( $improvements, 0, 12 ),
		);
	}

	/**
	 * Create a finding array.
	 *
	 * @param string              $id             Id.
	 * @param string              $category       Category.
	 * @param string              $severity       Severity.
	 * @param string              $title          Title.
	 * @param string              $description    Description.
	 * @param string              $recommendation Recommendation.
	 * @param string|null         $optimization   Related optimization.
	 * @param array<string,mixed> $data           Data.
	 * @param string              $source         Source.
	 * @return array<string,mixed>
	 */
	public static function make( string $id, string $category, string $severity, string $title, string $description = '', string $recommendation = '', ?string $optimization = null, array $data = array(), string $source = 'scan' ): array {
		return array(
			'id'             => $id,
			'category'       => $category,
			'severity'       => $severity,
			'title'          => $title,
			'description'    => $description,
			'recommendation' => $recommendation,
			'optimization'   => $optimization,
			'data'           => $data,
			'source'         => $source,
		);
	}

	/**
	 * Add a finding.
	 *
	 * @param array<string,mixed> $finding Finding.
	 */
	private function add( array $finding ): void {
		$this->findings[ (string) $finding['id'] ] = $finding;
	}

	/**
	 * Normalize an external finding.
	 *
	 * @param array<string,mixed> $finding Finding.
	 * @return array<string,mixed>
	 */
	private static function normalize( array $finding ): array {
		return self::make(
			(string) $finding['id'],
			(string) ( $finding['category'] ?? 'other' ),
			in_array( $finding['severity'] ?? '', array( 'good', 'info', 'notice', 'warning', 'critical' ), true ) ? (string) $finding['severity'] : 'info',
			(string) ( $finding['title'] ?? '' ),
			(string) ( $finding['description'] ?? '' ),
			(string) ( $finding['recommendation'] ?? '' ),
			isset( $finding['optimization'] ) ? (string) $finding['optimization'] : null,
			(array) ( $finding['data'] ?? array() ),
			(string) ( $finding['source'] ?? 'scan' )
		);
	}

	/**
	 * Human label for a page.
	 *
	 * @param array<string,mixed> $page Page analysis.
	 */
	private static function page_label( array $page ): string {
		if ( 'front_page' === ( $page['template'] ?? '' ) || '/' === (string) wp_parse_url( (string) $page['url'], PHP_URL_PATH ) ) {
			return __( 'your homepage', 'sh-speed-optimizer' );
		}
		$path = (string) wp_parse_url( (string) $page['url'], PHP_URL_PATH );
		/* translators: %s: page path such as /shop/ */
		return sprintf( __( 'the page %s', 'sh-speed-optimizer' ), $path );
	}

	/**
	 * Format bytes.
	 *
	 * @param int $bytes Bytes.
	 */
	private static function bytes( int $bytes ): string {
		return size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 );
	}

	/**
	 * Format milliseconds.
	 *
	 * @param int $ms Milliseconds.
	 */
	private static function ms( int $ms ): string {
		if ( $ms >= 1000 ) {
			/* translators: %s: seconds */
			return sprintf( __( '%s s', 'sh-speed-optimizer' ), number_format_i18n( $ms / 1000, 1 ) );
		}
		/* translators: %d: milliseconds */
		return sprintf( __( '%d ms', 'sh-speed-optimizer' ), $ms );
	}

	/**
	 * Server and WordPress environment.
	 *
	 * @param array<string,mixed>             $profile Profile.
	 * @param array<int|string,array<string,mixed>> $pages   Pages.
	 */
	private function server( array $profile, array $pages ): void {
		$loopback = (array) ( $profile['loopback'] ?? array() );

		if ( isset( $loopback['ok'] ) && ! $loopback['ok'] ) {
			$this->add(
				self::make(
					'loopback_failed',
					'server',
					'warning',
					__( 'Your server blocks requests to itself', 'sh-speed-optimizer' ),
					__( 'SH Speed Optimizer checks your pages by requesting them from your own server. That request failed, so automatic verification is limited and some optimizations stay switched off for safety.', 'sh-speed-optimizer' ),
					__( 'Ask your hosting provider to allow "loopback requests" (WordPress Site Health reports the same issue).', 'sh-speed-optimizer' ),
					null,
					array( 'error' => (string) ( $loopback['error'] ?? '' ) )
				)
			);
		}

		$ttfb = (int) ( $loopback['ttfb_ms'] ?? 0 );
		if ( $ttfb > 0 ) {
			if ( $ttfb > 1800 ) {
				$severity = 'critical';
			} elseif ( $ttfb > 800 ) {
				$severity = 'warning';
			} else {
				$severity = 'good';
			}
			$this->add(
				self::make(
					'server_response',
					'server',
					$severity,
					'good' === $severity
						/* translators: %s: duration */
						? sprintf( __( 'Your server responds quickly (%s)', 'sh-speed-optimizer' ), self::ms( $ttfb ) )
						/* translators: %s: duration */
						: sprintf( __( 'Your server takes %s to start sending your homepage', 'sh-speed-optimizer' ), self::ms( $ttfb ) ),
					__( 'Measured from your own server (network time to visitors is not included).', 'sh-speed-optimizer' ),
					'good' === $severity ? '' : __( 'Page caching usually fixes this. If it is already active, a faster hosting plan or an object cache may help.', 'sh-speed-optimizer' ),
					'good' === $severity ? null : 'page_cache',
					array( 'ttfb_ms' => $ttfb )
				)
			);
		}

		$slowest = null;
		foreach ( $pages as $page ) {
			if ( null !== ( $page['generation_ms'] ?? null ) && ( null === $slowest || $page['generation_ms'] > $slowest['generation_ms'] ) ) {
				$slowest = $page;
			}
		}
		if ( null !== $slowest && (int) $slowest['generation_ms'] > 1000 ) {
			$this->add(
				self::make(
					'slow_generation',
					'server',
					(int) $slowest['generation_ms'] > 2500 ? 'warning' : 'notice',
					/* translators: 1: duration, 2: page label */
					sprintf( __( 'WordPress needs %1$s to build %2$s', 'sh-speed-optimizer' ), self::ms( (int) $slowest['generation_ms'] ), self::page_label( $slowest ) ),
					__( 'This is the time before caching. Visitors who are logged in or have items in their cart always wait this long.', 'sh-speed-optimizer' ),
					__( 'See the database query analysis below for plugins that slow down page generation.', 'sh-speed-optimizer' ),
					null,
					array( 'generation_ms' => (int) $slowest['generation_ms'] )
				)
			);
		}

		$server = (array) ( $profile['server'] ?? array() );
		if ( isset( $server['opcache'] ) && ! $server['opcache'] ) {
			$this->add(
				self::make(
					'opcache_off',
					'server',
					'warning',
					__( 'PHP OPcache is not enabled', 'sh-speed-optimizer' ),
					__( 'Without OPcache, PHP recompiles WordPress on every request.', 'sh-speed-optimizer' ),
					__( 'Ask your hosting provider to enable OPcache. It is a standard, safe setting.', 'sh-speed-optimizer' )
				)
			);
		}
		if ( ! empty( $server['php_version'] ) && version_compare( (string) $server['php_version'], '8.2', '<' ) ) {
			$this->add(
				self::make(
					'php_version',
					'server',
					'notice',
					/* translators: %s: PHP version */
					sprintf( __( 'Your site runs on PHP %s', 'sh-speed-optimizer' ), (string) $server['php_version'] ),
					__( 'Newer PHP versions are faster and still receive security updates.', 'sh-speed-optimizer' ),
					__( 'Ask your host to switch to a current PHP version after checking plugin compatibility.', 'sh-speed-optimizer' )
				)
			);
		}
		if ( ! empty( $server['memory_limit'] ) && (int) $server['memory_limit'] > 0 && (int) $server['memory_limit'] < 128 * MB_IN_BYTES ) {
			$this->add(
				self::make(
					'memory_limit',
					'server',
					'notice',
					/* translators: %s: memory size */
					sprintf( __( 'PHP memory limit is only %s', 'sh-speed-optimizer' ), self::bytes( (int) $server['memory_limit'] ) ),
					__( 'Complex pages and background tasks may run out of memory.', 'sh-speed-optimizer' ),
					__( 'A memory limit of 256 MB is recommended for WordPress with page builders or WooCommerce.', 'sh-speed-optimizer' )
				)
			);
		}
		if ( isset( $server['compression'] ) && 'none' === $server['compression'] ) {
			$this->add(
				self::make(
					'no_compression',
					'server',
					'warning',
					__( 'Pages are sent without compression', 'sh-speed-optimizer' ),
					__( 'Compressed (gzip or Brotli) pages are typically several times smaller.', 'sh-speed-optimizer' ),
					__( 'Ask your hosting provider to enable gzip or Brotli compression. When page caching is active, SH Speed Optimizer already serves compressed cached pages.', 'sh-speed-optimizer' )
				)
			);
		}

		$wp = (array) ( $profile['wp'] ?? array() );
		if ( isset( $wp['object_cache'] ) && ! $wp['object_cache'] ) {
			$this->add(
				self::make(
					'object_cache',
					'server',
					'info',
					__( 'No persistent object cache', 'sh-speed-optimizer' ),
					__( 'An object cache (such as Redis) keeps database results in memory. It mainly helps logged-in users, WooCommerce carts and the dashboard.', 'sh-speed-optimizer' ),
					__( 'If your host offers Redis or Memcached, enabling it can reduce database load.', 'sh-speed-optimizer' )
				)
			);
		} elseif ( ! empty( $wp['object_cache'] ) ) {
			$this->add( self::make( 'object_cache', 'server', 'good', __( 'A persistent object cache is active', 'sh-speed-optimizer' ) ) );
		}
	}

	/**
	 * Other optimization systems.
	 *
	 * @param array<string,mixed> $profile Profile.
	 */
	private function conflicts( array $profile ): void {
		$conflicts   = (array) ( $profile['conflicts'] ?? array() );
		$page_caches = array();

		foreach ( $conflicts as $slug => $conflict ) {
			$features = (array) ( $conflict['features'] ?? array() );
			if ( in_array( 'page_cache', $features, true ) ) {
				$page_caches[] = (string) $conflict['name'];
			}
			$this->add(
				self::make(
					'conflict_' . sanitize_key( (string) $slug ),
					'plugins',
					'info',
					/* translators: %s: plugin name */
					sprintf( __( 'Another optimization system is active: %s', 'sh-speed-optimizer' ), (string) $conflict['name'] ),
					__( 'SH Speed Optimizer detected it and skips the features it already provides, so nothing is optimized twice.', 'sh-speed-optimizer' ),
					'',
					null,
					array( 'features' => $features )
				)
			);
		}

		if ( count( $page_caches ) > 1 ) {
			$this->add(
				self::make(
					'multiple_page_caches',
					'plugins',
					'warning',
					__( 'Several page caching plugins are active at the same time', 'sh-speed-optimizer' ),
					/* translators: %s: plugin names */
					sprintf( __( 'Active: %s. Multiple page caches can serve outdated pages and make problems hard to find.', 'sh-speed-optimizer' ), implode( ', ', $page_caches ) ),
					__( 'Keep only one page caching plugin active. SH Speed Optimizer never deactivates plugins for you.', 'sh-speed-optimizer' )
				)
			);
		}
	}

	/**
	 * Cache state.
	 *
	 * @param array<string,mixed> $cache   Cache status.
	 * @param array<string,mixed> $profile Profile.
	 * @param string[]            $active  Active optimization ids.
	 */
	private function cache( array $cache, array $profile, array $active ): void {
		$page = (array) ( $cache['page_cache'] ?? array() );
		if ( ! empty( $page['handled_by'] ) ) {
			$this->add(
				self::make(
					'page_cache',
					'cache',
					'good',
					/* translators: %s: name of plugin or host */
					sprintf( __( 'Page caching is handled by %s', 'sh-speed-optimizer' ), (string) $page['handled_by'] )
				)
			);
		} elseif ( in_array( 'page_cache', $active, true ) ) {
			$this->add( self::make( 'page_cache', 'cache', 'good', __( 'Page cache active', 'sh-speed-optimizer' ) ) );
		} else {
			$this->add(
				self::make(
					'page_cache',
					'cache',
					'warning',
					__( 'Pages are generated from scratch for every visitor', 'sh-speed-optimizer' ),
					__( 'A page cache stores finished pages so they can be delivered almost instantly.', 'sh-speed-optimizer' ),
					__( 'Enable page caching (part of the safe optimizations).', 'sh-speed-optimizer' ),
					'page_cache'
				)
			);
		}

		$browser = (array) ( $profile['browser_cache'] ?? array() );
		if ( ! empty( $browser['configured'] ) || in_array( 'browser_cache', $active, true ) ) {
			$this->add( self::make( 'browser_cache', 'cache', 'good', __( 'Browser caching enabled', 'sh-speed-optimizer' ) ) );
		} elseif ( isset( $browser['configured'] ) ) {
			$this->add(
				self::make(
					'browser_cache',
					'cache',
					'notice',
					__( 'Browsers re-check your images, CSS and JavaScript too often', 'sh-speed-optimizer' ),
					__( 'Without long-lived cache headers, returning visitors download unchanged files again.', 'sh-speed-optimizer' ),
					__( 'Allow SH Speed Optimizer to add browser caching rules (Cache page), or add the shown configuration on Nginx servers.', 'sh-speed-optimizer' ),
					'browser_cache'
				)
			);
		}
	}

	/**
	 * Page-level findings.
	 *
	 * @param array<int|string,array<string,mixed>> $pages  Pages.
	 * @param string[]                              $active Active optimization ids.
	 */
	private function pages( array $pages, array $active ): void {
		if ( empty( $pages ) ) {
			return;
		}

		$home = null;
		foreach ( $pages as $page ) {
			if ( 'front_page' === ( $page['template'] ?? '' ) ) {
				$home = $page;
				break;
			}
		}
		$home = $home ?? reset( $pages );

		// Render-blocking scripts.
		$blocking = array_filter( (array) $home['scripts'], static fn( $s ) => ! empty( $s['render_blocking'] ) );
		if ( count( $blocking ) > 0 && ! in_array( 'js_defer', $active, true ) ) {
			$this->add(
				self::make(
					'render_blocking_js',
					'javascript',
					count( $blocking ) >= 5 ? 'warning' : 'notice',
					/* translators: 1: number of files, 2: page label */
					sprintf( _n( '%1$d JavaScript file blocks the first display of %2$s', '%1$d JavaScript files block the first display of %2$s', count( $blocking ), 'sh-speed-optimizer' ), count( $blocking ), self::page_label( $home ) ),
					__( 'The browser has to download and run these files before it can show the page.', 'sh-speed-optimizer' ),
					__( 'Load non-critical scripts after the page is displayed (deferred), with dependency checks.', 'sh-speed-optimizer' ),
					'js_defer',
					array( 'files' => array_values( array_map( static fn( $s ) => $s['src'], $blocking ) ) )
				)
			);
		}

		$blocking_css = array_filter( (array) $home['styles'], static fn( $s ) => ! empty( $s['render_blocking'] ) );
		if ( count( $blocking_css ) >= 8 ) {
			$this->add(
				self::make(
					'render_blocking_css',
					'css',
					'notice',
					/* translators: 1: number of files, 2: page label */
					sprintf( __( '%1$d stylesheets must load before %2$s appears', 'sh-speed-optimizer' ), count( $blocking_css ), self::page_label( $home ) ),
					__( 'Each stylesheet delays the first display. Plugins often add their own stylesheet to every page.', 'sh-speed-optimizer' ),
					__( 'Check whether all plugins need their styles on every page. Minified copies are generated automatically.', 'sh-speed-optimizer' ),
					'css_minify'
				)
			);
		}

		// Third-party scripts before interaction.
		$delayable_categories = array( 'analytics', 'tag_manager', 'ads', 'social', 'chat', 'heatmap', 'reviews' );
		$third                = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['third_party'] ?? array() ) as $tp ) {
				if ( in_array( $tp['category'] ?? '', $delayable_categories, true ) ) {
					$third[ (string) $tp['id'] ] = (string) $tp['name'];
				}
			}
		}
		if ( ! empty( $third ) && ! in_array( 'js_delay_third_party', $active, true ) ) {
			$this->add(
				self::make(
					'third_party_early',
					'third_party',
					count( $third ) >= 3 ? 'warning' : 'notice',
					/* translators: %d: number of scripts */
					sprintf( _n( '%d third-party script loads before visitors interact', '%d third-party scripts load before visitors interact', count( $third ), 'sh-speed-optimizer' ), count( $third ) ),
					/* translators: %s: list of services */
					sprintf( __( 'Found: %s. These compete with your own content for bandwidth and processing time.', 'sh-speed-optimizer' ), implode( ', ', array_values( $third ) ) ),
					__( 'Load them after the first interaction (or after a few seconds). Tracking keeps working — nothing is removed.', 'sh-speed-optimizer' ),
					'js_delay_third_party',
					array( 'services' => array_values( $third ) )
				)
			);
		}

		// Maps and videos.
		$maps   = array();
		$videos = 0;
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['iframes'] ?? array() ) as $iframe ) {
				if ( 'google_maps' === $iframe['kind'] ) {
					$maps[] = self::page_label( $page );
				} elseif ( in_array( $iframe['kind'], array( 'youtube', 'vimeo' ), true ) ) {
					++$videos;
				}
			}
		}
		if ( ! empty( $maps ) && ! in_array( 'map_facade', $active, true ) ) {
			$this->add(
				self::make(
					'maps_immediate',
					'third_party',
					'notice',
					/* translators: %s: page label */
					sprintf( __( 'Google Maps loads immediately on %s', 'sh-speed-optimizer' ), implode( ', ', array_unique( $maps ) ) ),
					__( 'An embedded map loads a lot of code even if the visitor never looks at it.', 'sh-speed-optimizer' ),
					__( 'Show a lightweight placeholder with a "Load map" button instead.', 'sh-speed-optimizer' ),
					'map_facade'
				)
			);
		}
		if ( $videos > 0 && ! in_array( 'video_facade', $active, true ) ) {
			$this->add(
				self::make(
					'videos_immediate',
					'third_party',
					'notice',
					/* translators: %d: number of videos */
					sprintf( _n( '%d embedded video loads the full player immediately', '%d embedded videos load the full player immediately', $videos, 'sh-speed-optimizer' ), $videos ),
					__( 'Video players load a large amount of code before anyone presses play.', 'sh-speed-optimizer' ),
					__( 'Show the video thumbnail and load the player only when it is clicked.', 'sh-speed-optimizer' ),
					'video_facade'
				)
			);
		}

		// Images.
		$largest = null;
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['images']['largest'] ?? array() ) as $img ) {
				if ( null === $largest || $img['bytes'] > $largest['bytes'] ) {
					$largest         = $img;
					$largest['page'] = self::page_label( $page );
				}
			}
		}
		if ( null !== $largest && $largest['bytes'] > 400 * KB_IN_BYTES ) {
			$this->add(
				self::make(
					'large_image',
					'images',
					$largest['bytes'] > MB_IN_BYTES ? 'warning' : 'notice',
					/* translators: 1: page label, 2: file size */
					sprintf( __( '%1$s loads a %2$s image', 'sh-speed-optimizer' ), ucfirst( $largest['page'] ), self::bytes( (int) $largest['bytes'] ) ),
					/* translators: %s: file name */
					sprintf( __( 'File: %s', 'sh-speed-optimizer' ), basename( (string) wp_parse_url( (string) $largest['src'], PHP_URL_PATH ) ) ),
					__( 'Use a smaller version of this image. WebP copies are generated automatically when image optimization is active.', 'sh-speed-optimizer' ),
					'webp_images',
					array( 'src' => $largest['src'] )
				)
			);
		}

		$missing_dims = (int) ( $home['images']['missing_dimensions'] ?? 0 );
		if ( $missing_dims >= 3 && ! in_array( 'image_dimensions', $active, true ) ) {
			$this->add(
				self::make(
					'missing_dimensions',
					'cls',
					'notice',
					/* translators: %d: number of images */
					sprintf( __( '%d images have no width and height', 'sh-speed-optimizer' ), $missing_dims ),
					__( 'The page can jump while these images load (layout shift).', 'sh-speed-optimizer' ),
					__( 'Add the image dimensions automatically.', 'sh-speed-optimizer' ),
					'image_dimensions'
				)
			);
		}

		$not_lazy = max( 0, (int) ( $home['images']['count'] ?? 0 ) - (int) ( $home['images']['lazy'] ?? 0 ) - 3 );
		if ( $not_lazy >= 5 && ! in_array( 'lazy_load_images', $active, true ) ) {
			$this->add(
				self::make(
					'lazy_images',
					'images',
					'notice',
					/* translators: %d: number of images */
					sprintf( __( '%d images load before they are needed', 'sh-speed-optimizer' ), $not_lazy ),
					__( 'Images further down the page are downloaded immediately.', 'sh-speed-optimizer' ),
					__( 'Load images below the visible area only when the visitor scrolls near them.', 'sh-speed-optimizer' ),
					'lazy_load_images'
				)
			);
		}

		// Fonts.
		$weights  = 0;
		$families = array();
		$display  = 0;
		foreach ( (array) ( $home['fonts']['google'] ?? array() ) as $font ) {
			if ( empty( $font['display'] ) ) {
				++$display;
			}
			foreach ( (array) ( $font['families'] ?? array() ) as $family => $variants ) {
				$families[ (string) $family ] = true;
				$weights                     += max( 1, count( (array) $variants ) );
			}
		}
		if ( $weights >= 6 ) {
			$this->add(
				self::make(
					'font_weights',
					'fonts',
					'notice',
					/* translators: 1: number of font styles, 2: number of families */
					sprintf( __( '%1$d font styles from %2$d font families are loaded', 'sh-speed-optimizer' ), $weights, count( $families ) ),
					__( 'Each font style is a separate download.', 'sh-speed-optimizer' ),
					__( 'In your theme or page builder settings, only load the font weights you actually use.', 'sh-speed-optimizer' )
				)
			);
		}
		if ( $display > 0 && ! in_array( 'font_display_swap', $active, true ) ) {
			$this->add(
				self::make(
					'font_display',
					'fonts',
					'notice',
					__( 'Text can stay invisible while fonts load', 'sh-speed-optimizer' ),
					__( 'Google Fonts are loaded without the "swap" display setting.', 'sh-speed-optimizer' ),
					__( 'Show text immediately with a fallback font until the web font is ready.', 'sh-speed-optimizer' ),
					'font_display_swap'
				)
			);
		}

		// Page weight.
		if ( (int) $home['html_bytes'] > 500 * KB_IN_BYTES ) {
			$this->add(
				self::make(
					'html_size',
					'server',
					'notice',
					/* translators: 1: page label, 2: size */
					sprintf( __( 'The HTML of %1$s is %2$s', 'sh-speed-optimizer' ), self::page_label( $home ), self::bytes( (int) $home['html_bytes'] ) ),
					__( 'Very large pages take longer to download and render, especially on phones.', 'sh-speed-optimizer' ),
					__( 'Reduce the number of elements on the page, e.g. long sliders, mega menus or hidden duplicate content for mobile.', 'sh-speed-optimizer' )
				)
			);
		}

		$requests = (int) ( $home['requests']['scripts'] ?? 0 ) + (int) ( $home['requests']['styles'] ?? 0 ) + (int) ( $home['requests']['images'] ?? 0 );
		if ( $requests > 80 ) {
			$this->add(
				self::make(
					'many_requests',
					'server',
					'notice',
					/* translators: 1: page label, 2: number of files */
					sprintf( __( '%1$s references %2$d scripts, stylesheets and images', 'sh-speed-optimizer' ), ucfirst( self::page_label( $home ) ), $requests ),
					__( 'Every file is a separate request.', 'sh-speed-optimizer' ),
					__( 'Remove unused plugins and widgets; lazy loading keeps images from loading until needed.', 'sh-speed-optimizer' )
				)
			);
		}

		$js_bytes = (int) ( $home['bytes']['js'] ?? 0 );
		if ( $js_bytes > MB_IN_BYTES ) {
			$this->add(
				self::make(
					'js_weight',
					'inp',
					'notice',
					/* translators: 1: size, 2: page label */
					sprintf( __( '%1$s of JavaScript from your own site runs on %2$s', 'sh-speed-optimizer' ), self::bytes( $js_bytes ), self::page_label( $home ) ),
					__( 'Large amounts of JavaScript make pages slow to react to taps and clicks (INP).', 'sh-speed-optimizer' ),
					__( 'Check which plugins add scripts to every page.', 'sh-speed-optimizer' )
				)
			);
		}
	}

	/**
	 * Plugins that load assets on pages that do not use them.
	 *
	 * @param array<int|string,array<string,mixed>> $pages   Pages.
	 * @param array<string,mixed>                   $profile Profile.
	 */
	private function plugins_assets( array $pages, array $profile ): void {
		if ( count( $pages ) < 2 ) {
			return;
		}

		$loaded = array();
		$used   = array();
		foreach ( $pages as $page ) {
			$slugs = array();
			foreach ( array_merge( (array) $page['scripts'], (array) $page['styles'] ) as $asset ) {
				if ( 'plugin' === ( $asset['source']['type'] ?? '' ) ) {
					$slugs[ (string) $asset['source']['slug'] ] = true;
				}
			}
			foreach ( array_keys( $slugs ) as $slug ) {
				$loaded[ $slug ] = ( $loaded[ $slug ] ?? 0 ) + 1;
				if ( ! empty( $page['signatures'][ $slug ] ) ) {
					$used[ $slug ] = ( $used[ $slug ] ?? 0 ) + 1;
				}
			}
		}

		$total = count( $pages );
		foreach ( $loaded as $slug => $count ) {
			if ( $count < $total || ! array_key_exists( $slug, PageAnalyzer::markup_signatures() ) ) {
				continue;
			}
			$in_use = (int) ( $used[ $slug ] ?? 0 );
			if ( $in_use >= $total ) {
				continue;
			}
			$name = (string) ( $profile['plugins'][ $slug ]['name'] ?? $slug );
			$this->add(
				self::make(
					'global_assets_' . sanitize_key( $slug ),
					'plugins',
					'info',
					/* translators: 1: plugin name, 2: pages used, 3: pages checked */
					sprintf( __( '%1$s loads its files on every page, but is used on %2$d of %3$d checked pages', 'sh-speed-optimizer' ), $name, $in_use, $total ),
					__( 'Its CSS and JavaScript are downloaded even where nothing of the plugin is shown.', 'sh-speed-optimizer' ),
					__( 'Check the plugin settings for an option to load its files only where needed. SH Speed Optimizer does not remove plugin files automatically.', 'sh-speed-optimizer' ),
					null,
					array(
						'plugin' => $slug,
						'used'   => $in_use,
						'pages'  => $total,
					)
				)
			);
		}
	}

	/**
	 * Media library findings.
	 *
	 * @param array<string,mixed> $media  Media stats.
	 * @param string[]            $active Active optimizations.
	 */
	private function media( array $media, array $active ): void {
		if ( empty( $media ) ) {
			return;
		}

		$oversized = (int) ( $media['oversized_originals']['count'] ?? $media['oversized_originals'] ?? 0 );
		if ( $oversized > 0 ) {
			$this->add(
				self::make(
					'oversized_originals',
					'images',
					$oversized > 20 ? 'notice' : 'info',
					/* translators: %d: number of images */
					sprintf( _n( '%d image in your media library is very large', '%d images in your media library are very large', $oversized, 'sh-speed-optimizer' ), $oversized ),
					__( 'Larger than 2560 pixels or 1 MB. They are fine as originals, but should not be shown at full size on pages.', 'sh-speed-optimizer' ),
					__( 'Use a smaller image size (e.g. "Large") when inserting these images.', 'sh-speed-optimizer' ),
					null,
					array( 'top' => $media['oversized_originals']['top'] ?? array() )
				)
			);
		}

		$missing_alt = (int) ( $media['missing_alt'] ?? 0 );
		if ( $missing_alt > 0 ) {
			$this->add(
				self::make(
					'missing_alt',
					'images',
					'info',
					/* translators: %d: number of images */
					sprintf( _n( '%d image has no alternative text', '%d images have no alternative text', $missing_alt, 'sh-speed-optimizer' ), $missing_alt ),
					__( 'Alternative text helps visitors using screen readers and search engines. It does not affect speed.', 'sh-speed-optimizer' ),
					__( 'Add a short description in the media library.', 'sh-speed-optimizer' )
				)
			);
		}

		$coverage = $media['webp_coverage'] ?? null;
		if ( is_array( $coverage ) && ! empty( $coverage['total'] ) && in_array( 'webp_images', $active, true ) ) {
			$ratio = (int) $coverage['converted'] / max( 1, (int) $coverage['total'] );
			$this->add(
				self::make(
					'webp_coverage',
					'images',
					$ratio >= 0.9 ? 'good' : 'info',
					/* translators: 1: converted images, 2: total images */
					sprintf( __( 'WebP versions available for %1$d of %2$d images', 'sh-speed-optimizer' ), (int) $coverage['converted'], (int) $coverage['total'] ),
					$ratio < 0.9 ? __( 'The remaining images are converted in the background.', 'sh-speed-optimizer' ) : ''
				)
			);
		}
	}

	/**
	 * Findings from measurements in the administrator's browser.
	 *
	 * @param array<string,array<string,mixed>> $browser Per template results.
	 */
	private function browser( array $browser ): void {
		foreach ( $browser as $template => $result ) {
			if ( ! is_array( $result ) || empty( $result['dom'] ) ) {
				continue;
			}
			$label = 'front_page' === $template ? __( 'your homepage', 'sh-speed-optimizer' ) : (string) $template;
			$lab   = __( 'Measured in your browser during the last scan (lab data, not real visitors).', 'sh-speed-optimizer' );

			$lcp = (int) ( $result['lcp']['ms'] ?? 0 );
			if ( $lcp > 4000 ) {
				$this->add(
					self::make(
						'lab_lcp_' . sanitize_key( (string) $template ),
						'lcp',
						'warning',
						/* translators: 1: page, 2: duration */
						sprintf( __( 'The main content of %1$s appeared after %2$s', 'sh-speed-optimizer' ), $label, self::ms( $lcp ) ),
						$lab,
						__( 'Prioritize the main image and reduce render-blocking files.', 'sh-speed-optimizer' ),
						'lcp_priority',
						array( 'lcp' => $result['lcp'] ),
						'browser'
					)
				);
			}

			$cls = (float) ( $result['cls'] ?? 0 );
			if ( $cls > 0.1 ) {
				$this->add(
					self::make(
						'lab_cls_' . sanitize_key( (string) $template ),
						'cls',
						$cls > 0.25 ? 'warning' : 'notice',
						/* translators: %s: page */
						sprintf( __( 'Content on %s moves while loading', 'sh-speed-optimizer' ), $label ),
						/* translators: %s: layout shift score */
						sprintf( __( 'Layout shift score %s. ', 'sh-speed-optimizer' ), number_format_i18n( $cls, 2 ) ) . $lab,
						__( 'Give images, embeds and ads fixed dimensions.', 'sh-speed-optimizer' ),
						'image_dimensions',
						array( 'cls' => $cls ),
						'browser'
					)
				);
			}

			$oversized = (array) ( $result['images']['oversized'] ?? array() );
			if ( count( $oversized ) > 0 ) {
				$first = $oversized[0];
				$this->add(
					self::make(
						'lab_oversized_' . sanitize_key( (string) $template ),
						'images',
						'notice',
						/* translators: 1: number, 2: page */
						sprintf( _n( '%1$d image on %2$s is much larger than it is displayed', '%1$d images on %2$s are much larger than they are displayed', count( $oversized ), 'sh-speed-optimizer' ), count( $oversized ), $label ),
						sprintf(
							/* translators: 1: displayed width, 2: original width */
							__( 'For example: shown %1$d px wide, but the file is %2$d px wide.', 'sh-speed-optimizer' ),
							(int) ( $first['rendered'][0] ?? 0 ),
							(int) ( $first['natural'][0] ?? 0 )
						),
						__( 'Insert a smaller image size or let WordPress provide responsive images (srcset).', 'sh-speed-optimizer' ),
						null,
						array( 'images' => $oversized ),
						'browser'
					)
				);
			}

			foreach ( (array) ( $result['css']['sheets'] ?? array() ) as $sheet ) {
				$rules = (int) ( $sheet['rules'] ?? 0 );
				$used  = (int) ( $sheet['used'] ?? 0 );
				if ( $rules >= 500 && $used / max( 1, $rules ) < 0.25 ) {
					$this->add(
						self::make(
							'unused_css_' . substr( md5( (string) $sheet['href'] ), 0, 8 ),
							'css',
							'info',
							/* translators: 1: file, 2: percent, 3: page */
							sprintf( __( 'Only %2$d%% of %1$s is used on %3$s', 'sh-speed-optimizer' ), basename( (string) wp_parse_url( (string) $sheet['href'], PHP_URL_PATH ) ), (int) round( 100 * $used / $rules ), $label ),
							__( 'Candidate for cleanup. Styles for menus, pop-ups or other pages may still be needed, so SH Speed Optimizer never removes CSS automatically.', 'sh-speed-optimizer' ),
							'',
							null,
							array(
								'href'  => $sheet['href'],
								'rules' => $rules,
								'used'  => $used,
							),
							'browser'
						)
					);
				}
			}

			$errors = array_filter( (array) ( $result['errors'] ?? array() ), static fn( $e ) => is_array( $e ) && empty( $e['probe'] ) );
			if ( ! empty( $errors ) ) {
				$this->add(
					self::make(
						'baseline_js_errors_' . sanitize_key( (string) $template ),
						'javascript',
						'notice',
						/* translators: 1: number of errors, 2: page */
						sprintf( _n( '%2$s already shows %1$d JavaScript error without optimization', '%2$s already shows %1$d JavaScript errors without optimization', count( $errors ), 'sh-speed-optimizer' ), count( $errors ), ucfirst( $label ) ),
						(string) ( reset( $errors )['msg'] ?? '' ),
						__( 'These errors come from your theme or plugins. Ask their developers to fix them; SH Speed Optimizer makes sure it does not add new ones.', 'sh-speed-optimizer' ),
						null,
						array( 'errors' => array_values( $errors ) ),
						'browser'
					)
				);
			}
		}
	}

	/**
	 * Recommendations from decisions that were not applied automatically.
	 *
	 * @param array<string,array<string,mixed>> $decisions Decisions keyed by optimization id.
	 */
	private function recommendations( array $decisions ): void {
		foreach ( $decisions as $id => $entry ) {
			$decision = (array) ( $entry['decision'] ?? array() );
			if ( Decision::RECOMMEND !== ( $decision['action'] ?? '' ) || ! in_array( $decision['benefit'] ?? '', array( 'medium', 'high' ), true ) ) {
				continue;
			}
			// Do not duplicate a finding that already points to this optimization.
			foreach ( $this->findings as $finding ) {
				if ( $id === ( $finding['optimization'] ?? null ) ) {
					continue 2;
				}
			}
			$this->add(
				self::make(
					'recommend_' . $id,
					(string) ( $entry['category'] ?? 'other' ),
					'info',
					/* translators: %s: optimization name */
					sprintf( __( 'Potential improvement: %s', 'sh-speed-optimizer' ), (string) ( $entry['name'] ?? $id ) ),
					(string) ( $decision['summary'] ?? '' ),
					(string) ( $entry['description'] ?? '' ),
					(string) $id
				)
			);
		}
	}
}
