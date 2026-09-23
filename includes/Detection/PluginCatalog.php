<?php
/**
 * Known optimization/caching plugins and the features they provide.
 *
 * Feature keys: page_cache, browser_cache, minify_css, minify_js, defer_js,
 * delay_js, lazy_load, webp, critical_css, font_optimization, preload, cdn,
 * image_optimization, heartbeat, cleanup (any WordPress cleanup tweak), plus
 * the finer cleanup keys emojis, embeds, head_cleanup, jquery_migrate and
 * database_cleanup.
 *
 * Many plugins let the owner switch features off. Where the setting can be
 * read cheaply (an option or a global), the feature list is refined to what
 * is actually enabled; otherwise the feature is assumed enabled, because
 * double optimization is worse than a missed optimization.
 *
 * Refinement paths:
 *   "option_name"              option value
 *   "option_name[key][sub]"    array (or JSON string) option; a missing key counts as "off"
 *                              (checkbox settings are not stored when unchecked)
 *   "option_name[key]?"        same, but a missing key counts as "unknown"
 *   "global:name"              $GLOBALS value
 *   "w3tc:key"                 W3 Total Cache configuration flag
 * A feature is kept when any of its paths is on, or when none of them can be read.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Conflict catalog.
 */
final class PluginCatalog {

	/**
	 * Slug => [ name, features, refinement map (feature => paths), opt-in map (feature => paths) ].
	 *
	 * @return array<string,array{0:string,1:string[],2:array<string,string[]>,3:array<string,string[]>}>
	 */
	public static function definitions(): array {
		static $definitions = null;
		if ( null !== $definitions ) {
			return $definitions;
		}

		$rocket = 'wp_rocket_settings';
		$ls     = 'litespeed.conf.';
		$sg     = 'siteground_optimizer_';
		$pm     = 'perfmatters_options';
		$wpfc   = 'WpFastestCache';
		$ao_x   = 'autoptimize_extra_settings';
		$hb     = 'wphb_settings';

		$hummingbird = array(
			'Hummingbird',
			array( 'page_cache', 'minify_css', 'minify_js', 'defer_js', 'browser_cache', 'lazy_load', 'cleanup', 'emojis' ),
			array(
				'page_cache' => array( $hb . '[page_cache][enabled]?' ),
				'minify_css' => array( $hb . '[minify][enabled]?' ),
				'minify_js'  => array( $hb . '[minify][enabled]?' ),
				'defer_js'   => array( $hb . '[minify][enabled]?' ),
				'lazy_load'  => array( $hb . '[lazy_load][enabled]?' ),
				'emojis'     => array( $hb . '[advanced][emoji]?' ),
				'cleanup'    => array( $hb . '[advanced][emoji]?', $hb . '[advanced][query_string]?' ),
			),
			array(),
		);

		$swift = array(
			'Swift Performance',
			array( 'page_cache', 'browser_cache', 'minify_css', 'minify_js', 'defer_js', 'delay_js', 'lazy_load', 'critical_css', 'cdn', 'font_optimization', 'webp', 'image_optimization', 'cleanup', 'heartbeat' ),
			array(),
			array(),
		);

		$definitions = array(
			'wp-rocket'                   => array(
				'WP Rocket',
				array( 'page_cache', 'browser_cache', 'minify_css', 'minify_js', 'defer_js', 'delay_js', 'lazy_load', 'critical_css', 'preload', 'font_optimization', 'cdn', 'heartbeat', 'cleanup', 'emojis', 'embeds' ),
				array(
					'minify_css'   => array( $rocket . '[minify_css]' ),
					'minify_js'    => array( $rocket . '[minify_js]' ),
					'defer_js'     => array( $rocket . '[defer_all_js]' ),
					'delay_js'     => array( $rocket . '[delay_js]' ),
					'lazy_load'    => array( $rocket . '[lazyload]', $rocket . '[lazyload_iframes]' ),
					'critical_css' => array( $rocket . '[async_css]', $rocket . '[remove_unused_css]' ),
					'preload'      => array( $rocket . '[manual_preload]?', $rocket . '[preload_links]?' ),
					'cdn'          => array( $rocket . '[cdn]' ),
					'heartbeat'    => array( $rocket . '[control_heartbeat]' ),
					'emojis'       => array( $rocket . '[emoji]' ),
					'embeds'       => array( $rocket . '[embeds]' ),
					'cleanup'      => array( $rocket . '[emoji]', $rocket . '[embeds]' ),
				),
				array(),
			),
			'litespeed-cache'             => array(
				'LiteSpeed Cache',
				array( 'page_cache', 'minify_css', 'minify_js', 'defer_js', 'delay_js', 'lazy_load', 'webp', 'critical_css', 'font_optimization', 'browser_cache', 'cleanup', 'emojis', 'heartbeat' ),
				array(
					'minify_css'        => array( $ls . 'optm-css_min', $ls . 'optm-css_comb' ),
					'minify_js'         => array( $ls . 'optm-js_min', $ls . 'optm-js_comb' ),
					'lazy_load'         => array( $ls . 'media-lazy', $ls . 'media-iframe_lazy' ),
					'webp'              => array( $ls . 'img_optm-webp' ),
					'critical_css'      => array( $ls . 'optm-ccss_async', $ls . 'optm-ucss' ),
					'font_optimization' => array( $ls . 'optm-ggfonts_async', $ls . 'optm-ggfonts_rm' ),
					'browser_cache'     => array( $ls . 'cache-browser' ),
					'emojis'            => array( $ls . 'optm-emoji_rm' ),
					'cleanup'           => array( $ls . 'optm-emoji_rm', $ls . 'optm-qs_rm' ),
					'heartbeat'         => array( $ls . 'misc-heartbeat_front' ),
				),
				array(),
			),
			'w3-total-cache'              => array(
				'W3 Total Cache',
				array( 'page_cache', 'browser_cache', 'minify_css', 'minify_js', 'lazy_load', 'cdn' ),
				array(
					'page_cache'    => array( 'w3tc:pgcache.enabled' ),
					'browser_cache' => array( 'w3tc:browsercache.enabled' ),
					'lazy_load'     => array( 'w3tc:lazyload.enabled' ),
					'cdn'           => array( 'w3tc:cdn.enabled' ),
				),
				array(),
			),
			'wp-super-cache'              => array(
				'WP Super Cache',
				array( 'page_cache' ),
				array( 'page_cache' => array( 'global:cache_enabled' ) ),
				array(),
			),
			'wp-fastest-cache'            => array(
				'WP Fastest Cache',
				array( 'page_cache', 'minify_css', 'minify_js', 'browser_cache', 'lazy_load' ),
				array(
					'page_cache'    => array( $wpfc . '[wpFastestCacheStatus]' ),
					'minify_css'    => array( $wpfc . '[wpFastestCacheMinifyCss]', $wpfc . '[wpFastestCacheCombineCss]' ),
					'minify_js'     => array( $wpfc . '[wpFastestCacheMinifyJs]', $wpfc . '[wpFastestCacheCombineJs]', $wpfc . '[wpFastestCacheCombineJsPowerFul]' ),
					'browser_cache' => array( $wpfc . '[wpFastestCacheLBC]' ),
					'lazy_load'     => array( $wpfc . '[wpFastestCacheLazyLoad]' ),
				),
				array( 'emojis' => array( $wpfc . '[wpFastestCacheDisableEmojis]' ) ),
			),
			'autoptimize'                 => array(
				'Autoptimize',
				array( 'minify_css', 'minify_js', 'defer_js', 'critical_css', 'lazy_load', 'font_optimization', 'cleanup', 'emojis' ),
				array(
					'minify_js'    => array( 'autoptimize_js' ),
					'defer_js'     => array( 'autoptimize_js' ),
					'minify_css'   => array( 'autoptimize_css' ),
					'critical_css' => array( 'autoptimize_css_defer' ),
					'lazy_load'    => array( 'autoptimize_imgopt_settings[autoptimize_imgopt_checkbox_field_3]' ),
					'emojis'       => array( $ao_x . '[autoptimize_extra_checkbox_field_1]' ),
					'cleanup'      => array( $ao_x . '[autoptimize_extra_checkbox_field_1]', $ao_x . '[autoptimize_extra_checkbox_field_0]' ),
				),
				array(),
			),
			'flying-press'                => array(
				'FlyingPress',
				array( 'page_cache', 'minify_css', 'minify_js', 'defer_js', 'delay_js', 'lazy_load', 'critical_css', 'font_optimization', 'preload', 'cdn' ),
				array(),
				array(),
			),
			'nitropack'                   => array(
				'NitroPack',
				array( 'page_cache', 'browser_cache', 'webp', 'lazy_load', 'minify_css', 'minify_js', 'defer_js', 'delay_js', 'critical_css', 'cdn', 'font_optimization', 'image_optimization', 'preload' ),
				array(),
				array(),
			),
			'sg-cachepress'               => array(
				'SiteGround Optimizer',
				array( 'page_cache', 'minify_css', 'minify_js', 'defer_js', 'lazy_load', 'webp', 'font_optimization', 'heartbeat', 'cleanup', 'emojis' ),
				array(
					'page_cache'        => array( $sg . 'enable_cache', $sg . 'file_caching' ),
					'minify_css'        => array( $sg . 'optimize_css', $sg . 'combine_css' ),
					'minify_js'         => array( $sg . 'optimize_javascript', $sg . 'combine_javascript' ),
					'defer_js'          => array( $sg . 'optimize_javascript_async' ),
					'lazy_load'         => array( $sg . 'lazyload_images' ),
					'webp'              => array( $sg . 'webp_support' ),
					'font_optimization' => array( $sg . 'optimize_web_fonts', $sg . 'combine_google_fonts' ),
					'heartbeat'         => array( $sg . 'heartbeat_control' ),
					'emojis'            => array( $sg . 'disable_emojis' ),
					'cleanup'           => array( $sg . 'disable_emojis', $sg . 'remove_query_strings' ),
				),
				array(),
			),
			'tenweb-speed-optimizer'      => array(
				'10Web Booster',
				array( 'page_cache', 'minify_css', 'minify_js', 'defer_js', 'delay_js', 'critical_css', 'lazy_load', 'webp', 'font_optimization' ),
				array(),
				array(),
			),
			'hummingbird-performance'     => $hummingbird,
			'wp-hummingbird'              => $hummingbird,
			'breeze'                      => array(
				'Breeze',
				array( 'page_cache', 'minify_css', 'minify_js', 'browser_cache', 'lazy_load', 'heartbeat' ),
				array(
					'page_cache'    => array( 'breeze_basic_settings[breeze-active]?' ),
					'minify_css'    => array( 'breeze_file_settings[breeze-minify-css]?' ),
					'minify_js'     => array( 'breeze_file_settings[breeze-minify-js]?' ),
					'browser_cache' => array( 'breeze_basic_settings[breeze-browser-cache]?' ),
					'lazy_load'     => array( 'breeze_advanced_settings[breeze-lazy-load]?' ),
				),
				array(),
			),
			'cache-enabler'               => array( 'Cache Enabler', array( 'page_cache' ), array(), array() ),
			'comet-cache'                 => array( 'Comet Cache', array( 'page_cache' ), array(), array() ),
			'comet-cache-pro'             => array( 'Comet Cache Pro', array( 'page_cache' ), array(), array() ),
			'wp-cloudflare-page-cache'    => array( 'Super Page Cache', array( 'page_cache' ), array(), array() ),
			'powered-cache'               => array( 'Powered Cache', array( 'page_cache', 'minify_css', 'minify_js' ), array(), array() ),
			'swift-performance-lite'      => $swift,
			'swift-performance'           => $swift,
			'perfmatters'                 => array(
				'Perfmatters',
				array( 'cleanup', 'emojis', 'embeds', 'jquery_migrate', 'head_cleanup', 'heartbeat', 'delay_js', 'defer_js', 'lazy_load', 'font_optimization', 'preload' ),
				array(
					'emojis'            => array( $pm . '[disable_emojis]' ),
					'embeds'            => array( $pm . '[disable_embeds]' ),
					'jquery_migrate'    => array( $pm . '[remove_jquery_migrate]' ),
					'head_cleanup'      => array( $pm . '[hide_wp_version]', $pm . '[remove_rsd_link]', $pm . '[remove_shortlink]', $pm . '[remove_wlwmanifest_link]' ),
					'cleanup'           => array( $pm . '[disable_emojis]', $pm . '[disable_embeds]', $pm . '[remove_jquery_migrate]', $pm . '[hide_wp_version]', $pm . '[remove_rsd_link]', $pm . '[remove_shortlink]', $pm . '[remove_wlwmanifest_link]', $pm . '[remove_query_strings]', $pm . '[disable_self_pingbacks]', $pm . '[remove_comment_urls]' ),
					'heartbeat'         => array( $pm . '[disable_heartbeat]', $pm . '[heartbeat_frequency]' ),
					'delay_js'          => array( $pm . '[assets][delay_js]' ),
					'defer_js'          => array( $pm . '[assets][defer_js]' ),
					'lazy_load'         => array( $pm . '[lazyload][lazy_loading]', $pm . '[lazyload][lazy_loading_iframes]' ),
					'font_optimization' => array( $pm . '[fonts][disable_google_fonts]', $pm . '[fonts][display_swap]', $pm . '[fonts][local_google_fonts]' ),
					'preload'           => array( $pm . '[preload][instant_page]', $pm . '[preload][preload]?' ),
				),
				array( 'cdn' => array( $pm . '[cdn][enable_cdn]' ) ),
			),
			'jetpack-boost'               => array(
				'Jetpack Boost',
				array( 'critical_css', 'defer_js', 'image_optimization' ),
				array(
					'critical_css'       => array( 'jetpack_boost_status_critical-css', 'jetpack_boost_status_cloud-css' ),
					'defer_js'           => array( 'jetpack_boost_status_render-blocking-js' ),
					'image_optimization' => array( 'jetpack_boost_status_image-cdn' ),
				),
				array(
					'page_cache' => array( 'jetpack_boost_status_page-cache' ),
					'minify_css' => array( 'jetpack_boost_status_minify-css' ),
					'minify_js'  => array( 'jetpack_boost_status_minify-js' ),
				),
			),
			'wp-optimize'                 => array(
				'WP-Optimize',
				array( 'page_cache', 'minify_css', 'minify_js', 'webp', 'database_cleanup' ),
				array(
					'page_cache' => array( 'wpo_cache_config[enable_page_caching]?' ),
					'webp'       => array( 'wp-optimize-webp_conversion' ),
				),
				array(),
			),
			'clearfy'                     => array( 'Clearfy', array( 'cleanup', 'heartbeat' ), array(), array() ),
			'wp-asset-clean-up'           => array(
				'Asset CleanUp',
				array( 'minify_css', 'minify_js', 'defer_js', 'cleanup' ),
				array(
					'minify_css' => array( 'wpassetcleanup_settings[minify_loaded_css]?' ),
					'minify_js'  => array( 'wpassetcleanup_settings[minify_loaded_js]?' ),
				),
				array( 'emojis' => array( 'wpassetcleanup_settings[disable_emojis]?' ) ),
			),
			'wp-asset-clean-up-pro'       => array(
				'Asset CleanUp Pro',
				array( 'minify_css', 'minify_js', 'defer_js', 'cleanup' ),
				array(
					'minify_css' => array( 'wpassetcleanup_settings[minify_loaded_css]?' ),
					'minify_js'  => array( 'wpassetcleanup_settings[minify_loaded_js]?' ),
				),
				array( 'emojis' => array( 'wpassetcleanup_settings[disable_emojis]?' ) ),
			),
			'flying-scripts'              => array( 'Flying Scripts', array( 'delay_js' ), array(), array() ),
			'flying-pages'                => array( 'Flying Pages', array( 'preload' ), array(), array() ),
			'a3-lazy-load'                => array( 'a3 Lazy Load', array( 'lazy_load' ), array(), array() ),
			'rocket-lazy-load'            => array( 'Lazy Load by WP Rocket', array( 'lazy_load' ), array(), array() ),
			'wp-smushit'                  => array(
				'Smush',
				array( 'lazy_load', 'image_optimization' ),
				array( 'lazy_load' => array( 'wp-smush-settings[lazy_load]?' ) ),
				array( 'webp' => array( 'wp-smush-settings[webp_mod]?' ) ),
			),
			'wp-smush-pro'                => array(
				'Smush Pro',
				array( 'lazy_load', 'image_optimization', 'webp' ),
				array(
					'lazy_load' => array( 'wp-smush-settings[lazy_load]?' ),
					'webp'      => array( 'wp-smush-settings[webp_mod]?' ),
				),
				array(),
			),
			'ewww-image-optimizer'        => array(
				'EWWW Image Optimizer',
				array( 'webp', 'image_optimization', 'lazy_load' ),
				array(
					'webp'      => array( 'ewww_image_optimizer_webp' ),
					'lazy_load' => array( 'ewww_image_optimizer_lazy_load' ),
				),
				array(),
			),
			'shortpixel-image-optimiser'  => array( 'ShortPixel Image Optimizer', array( 'webp', 'image_optimization' ), array(), array() ),
			'shortpixel-adaptive-images'  => array( 'ShortPixel Adaptive Images', array( 'webp', 'lazy_load', 'cdn', 'image_optimization' ), array(), array() ),
			'imagify'                     => array(
				'Imagify',
				array( 'webp', 'image_optimization' ),
				array( 'webp' => array( 'imagify_settings[display_nextgen]?', 'imagify_settings[display_webp]?' ) ),
				array(),
			),
			'optimole-wp'                 => array( 'Optimole', array( 'webp', 'lazy_load', 'image_optimization', 'cdn' ), array(), array() ),
			'webp-express'                => array( 'WebP Express', array( 'webp' ), array(), array() ),
			'webp-converter-for-media'    => array( 'Converter for Media', array( 'webp' ), array(), array() ),
			'webp-uploads'                => array( 'Modern Image Formats', array( 'webp' ), array(), array() ),
			'tiny-compress-images'        => array( 'TinyPNG', array( 'image_optimization' ), array(), array() ),
			'heartbeat-control'           => array( 'Heartbeat Control', array( 'heartbeat' ), array(), array() ),
			'host-webfonts-local'         => array( 'OMGF', array( 'font_optimization' ), array(), array() ),
			'local-google-fonts'          => array( 'Local Google Fonts', array( 'font_optimization' ), array(), array() ),
			'cloudflare'                  => array( 'Cloudflare', array( 'page_cache' ), array(), array() ),
			'varnish-http-purge'          => array( 'Proxy Cache Purge', array(), array(), array() ),
		);

		return $definitions;
	}

	/**
	 * Conflicts for a set of active plugins: slug => [ name, features[] ].
	 *
	 * @param string[] $slugs  Active plugin slugs.
	 * @param callable $lookup Fact lookup (see Facts).
	 * @return array<string,array{name:string,features:string[]}>
	 */
	public static function conflicts( array $slugs, callable $lookup ): array {
		$definitions = self::definitions();
		$index       = array();
		foreach ( array_keys( $definitions ) as $slug ) {
			$index[ strtolower( $slug ) ] = $slug;
		}

		$out = array();
		foreach ( $slugs as $slug ) {
			$key = strtolower( (string) $slug );
			if ( ! isset( $index[ $key ] ) ) {
				continue;
			}
			$definition = $definitions[ $index[ $key ] ];
			$out[ (string) $slug ] = array(
				'name'     => $definition[0],
				'features' => self::refine( $index[ $key ], $definition[1], $lookup ),
			);
		}
		return $out;
	}

	/**
	 * Refine a plugin's feature list to what is actually enabled.
	 *
	 * @param string   $slug     Catalog slug.
	 * @param string[] $features Default features.
	 * @param callable $lookup   Fact lookup.
	 * @return string[]
	 */
	public static function refine( string $slug, array $features, callable $lookup ): array {
		$definition = self::definitions()[ $slug ] ?? null;
		if ( null === $definition ) {
			return array_values( $features );
		}

		$check = static function ( string $path ) use ( $lookup ): ?bool {
			return self::resolve( $lookup, $path );
		};

		$features = self::apply_map( $features, $definition[2], $check );

		foreach ( $definition[3] as $feature => $paths ) {
			foreach ( $paths as $path ) {
				if ( true === $check( $path ) ) {
					$features[] = $feature;
					break;
				}
			}
		}

		switch ( $slug ) {
			case 'litespeed-cache':
				$features = self::refine_litespeed( $features, $lookup );
				break;
			case 'w3-total-cache':
				$minify = self::to_flag( $lookup( 'w3tc', 'minify.enabled' ) );
				if ( false === $minify ) {
					$features = array_diff( $features, array( 'minify_css', 'minify_js' ) );
				} elseif ( true === $minify ) {
					if ( false === self::to_flag( $lookup( 'w3tc', 'minify.css.enable' ) ) ) {
						$features = array_diff( $features, array( 'minify_css' ) );
					}
					if ( false === self::to_flag( $lookup( 'w3tc', 'minify.js.enable' ) ) ) {
						$features = array_diff( $features, array( 'minify_js' ) );
					}
				}
				break;
			case 'autoptimize':
				// 1 (the default) = leave Google Fonts as they are; 2–5 = remove, combine or load them asynchronously.
				$fonts = self::resolve_value( $lookup, 'autoptimize_extra_settings[autoptimize_extra_radio_field_4]' );
				if ( self::MISSING === $fonts || ( is_scalar( $fonts ) && '1' === (string) $fonts ) ) {
					$features = array_diff( $features, array( 'font_optimization' ) );
				}
				break;
			case 'wp-optimize':
				$minify = self::resolve_value( $lookup, 'wp-optimize-minify' );
				if ( is_array( $minify ) ) {
					$enabled = self::to_flag( $minify['enabled'] ?? null );
					if ( false === $enabled ) {
						$features = array_diff( $features, array( 'minify_css', 'minify_js' ) );
					} else {
						if ( false === self::to_flag( $minify['enable_css_minification'] ?? null ) ) {
							$features = array_diff( $features, array( 'minify_css' ) );
						}
						if ( false === self::to_flag( $minify['enable_js_minification'] ?? null ) ) {
							$features = array_diff( $features, array( 'minify_js' ) );
						}
					}
				}
				break;
			case 'cloudflare':
				// Only Automatic Platform Optimization (APO) caches pages; the plugin alone does not.
				$apo = $lookup( 'option', 'automatic_platform_optimization' );
				if ( is_array( $apo ) ) {
					$apo = $apo['value'] ?? ( $apo['enabled'] ?? null );
				}
				if ( true !== self::to_flag( $apo ) ) {
					$features = array_diff( $features, array( 'page_cache' ) );
				}
				break;
		}

		return array_values( array_unique( $features ) );
	}

	/**
	 * LiteSpeed Cache: the page cache only works on LiteSpeed servers; defer/delay share one setting.
	 *
	 * @param string[] $features Features.
	 * @param callable $lookup   Lookup.
	 * @return string[]
	 */
	private static function refine_litespeed( array $features, callable $lookup ): array {
		$server = (string) $lookup( 'server_software', '' );
		$cache  = self::to_flag( $lookup( 'option', 'litespeed.conf.cache' ) );
		if ( ! in_array( $server, array( 'litespeed', 'openlitespeed' ), true ) || false === $cache ) {
			$features = array_diff( $features, array( 'page_cache' ) );
		}

		// optm-js_defer: 0 = off, 1 = deferred, 2 = delayed until user interaction.
		$defer = $lookup( 'option', 'litespeed.conf.optm-js_defer' );
		if ( null !== $defer && is_scalar( $defer ) ) {
			$mode = (int) $defer;
			if ( 0 === $mode ) {
				$features = array_diff( $features, array( 'defer_js', 'delay_js' ) );
			} elseif ( 1 === $mode ) {
				$features = array_diff( $features, array( 'delay_js' ) );
			}
		}

		return $features;
	}

	/**
	 * Keep a feature when any path is on, or when none can be read; drop it when all readable paths are off.
	 *
	 * @param string[]                $features Features.
	 * @param array<string,string[]>  $map      Feature => paths.
	 * @param callable                $check    function( string $path ): ?bool.
	 * @return string[]
	 */
	private static function apply_map( array $features, array $map, callable $check ): array {
		$out = array();
		foreach ( $features as $feature ) {
			if ( ! isset( $map[ $feature ] ) ) {
				$out[] = $feature;
				continue;
			}
			$known = false;
			$on    = false;
			foreach ( $map[ $feature ] as $path ) {
				$flag = $check( $path );
				if ( true === $flag ) {
					$on = true;
					break;
				}
				if ( false === $flag ) {
					$known = true;
				}
			}
			if ( $on || ! $known ) {
				$out[] = $feature;
			}
		}
		return $out;
	}

	/**
	 * Resolve a refinement path to a flag (null = unknown).
	 *
	 * @param callable $lookup Lookup.
	 * @param string   $path   Path.
	 */
	public static function resolve( callable $lookup, string $path ): ?bool {
		$value = self::resolve_value( $lookup, $path );
		if ( self::MISSING === $value ) {
			return false;
		}
		return self::to_flag( $value );
	}

	/**
	 * Marker for "array option exists but key is missing".
	 */
	private const MISSING = "\0missing";

	/**
	 * Resolve a refinement path to a raw value.
	 *
	 * @param callable $lookup Lookup.
	 * @param string   $path   Path.
	 * @return mixed Value, null when unknown, or self::MISSING for a missing checkbox key.
	 */
	private static function resolve_value( callable $lookup, string $path ) {
		$lenient = '?' === substr( $path, -1 );
		if ( $lenient ) {
			$path = substr( $path, 0, -1 );
		}

		if ( 0 === strpos( $path, 'global:' ) ) {
			return $lookup( 'global', substr( $path, 7 ) );
		}
		if ( 0 === strpos( $path, 'w3tc:' ) ) {
			return $lookup( 'w3tc', substr( $path, 5 ) );
		}

		$bracket = strpos( $path, '[' );
		$name    = false === $bracket ? $path : substr( $path, 0, $bracket );
		$keys    = array();
		if ( false !== $bracket && preg_match_all( '/\[([^\]]*)\]/', substr( $path, $bracket ), $m ) ) {
			$keys = $m[1];
		}

		$value = $lookup( 'option', $name );
		foreach ( $keys as $key ) {
			if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : $value;
			}
			if ( is_object( $value ) ) {
				$value = get_object_vars( $value );
			}
			if ( null === $value ) {
				return null;
			}
			if ( ! is_array( $value ) ) {
				return null;
			}
			if ( ! array_key_exists( $key, $value ) ) {
				return $lenient ? null : self::MISSING;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Interpret a stored setting as on/off (null = unknown).
	 *
	 * @param mixed $value Value.
	 */
	public static function to_flag( $value ): ?bool {
		if ( null === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Int or float.
		}
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'off', 'no', 'false', 'disabled', 'disable' ), true );
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		return (bool) $value;
	}
}
