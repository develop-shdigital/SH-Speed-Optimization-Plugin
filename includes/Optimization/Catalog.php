<?php
/**
 * Built-in optimization catalog.
 *
 * Only ids and class names live here so the frontend can instantiate just
 * the active optimizations.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog of optimization classes.
 */
final class Catalog {

	/**
	 * Id → class map.
	 *
	 * @return array<string,string>
	 */
	public static function map(): array {
		return array(
			// WordPress cleanup.
			'disable_emojis'           => \SH\SpeedOptimizer\Modules\Emojis\DisableEmojisOptimization::class,
			'head_cleanup'             => \SH\SpeedOptimizer\Modules\WordpressCleanup\HeadCleanupOptimization::class,
			'disable_oembed_discovery' => \SH\SpeedOptimizer\Modules\Oembed\OembedDiscoveryOptimization::class,
			'disable_embeds_script'    => \SH\SpeedOptimizer\Modules\Embeds\EmbedScriptOptimization::class,
			'disable_self_pingbacks'   => \SH\SpeedOptimizer\Modules\WordpressCleanup\PingbackOptimization::class,
			'heartbeat_frontend'       => \SH\SpeedOptimizer\Modules\Heartbeat\HeartbeatOptimization::class,
			'remove_jquery_migrate'    => \SH\SpeedOptimizer\Modules\WordpressCleanup\JqueryMigrateOptimization::class,

			// Caching.
			'page_cache'               => \SH\SpeedOptimizer\Modules\PageCache\PageCacheOptimization::class,
			'browser_cache'            => \SH\SpeedOptimizer\Modules\BrowserCache\BrowserCacheOptimization::class,

			// Images.
			'lazy_load_images'         => \SH\SpeedOptimizer\Modules\LazyLoading\LazyImagesOptimization::class,
			'lazy_load_iframes'        => \SH\SpeedOptimizer\Modules\LazyLoading\LazyIframesOptimization::class,
			'image_dimensions'         => \SH\SpeedOptimizer\Modules\ImageOptimization\ImageDimensionsOptimization::class,
			'webp_images'              => \SH\SpeedOptimizer\Modules\ImageOptimization\WebpOptimization::class,
			'lcp_priority'             => \SH\SpeedOptimizer\Modules\Preload\LcpPriorityOptimization::class,

			// Fonts.
			'font_display_swap'        => \SH\SpeedOptimizer\Modules\FontOptimization\FontDisplayOptimization::class,
			'font_preload'             => \SH\SpeedOptimizer\Modules\FontOptimization\FontPreloadOptimization::class,
			'localize_google_fonts'    => \SH\SpeedOptimizer\Modules\FontOptimization\LocalFontsOptimization::class,

			// CSS.
			'css_minify'               => \SH\SpeedOptimizer\Modules\CssOptimization\CssMinifyOptimization::class,
			'critical_css'             => \SH\SpeedOptimizer\Modules\CssOptimization\CriticalCssOptimization::class,

			// JavaScript.
			'js_minify'                => \SH\SpeedOptimizer\Modules\JavascriptOptimization\JsMinifyOptimization::class,
			'js_defer'                 => \SH\SpeedOptimizer\Modules\JavascriptOptimization\JsDeferOptimization::class,
			'js_delay_all'             => \SH\SpeedOptimizer\Modules\JavascriptOptimization\JsDelayOptimization::class,

			// Third-party content and resource hints.
			'preconnect'               => \SH\SpeedOptimizer\Modules\Preload\PreconnectOptimization::class,
			'js_delay_third_party'     => \SH\SpeedOptimizer\Modules\ThirdPartyOptimization\ThirdPartyDelayOptimization::class,
			'video_facade'             => \SH\SpeedOptimizer\Modules\ThirdPartyOptimization\VideoFacadeOptimization::class,
			'map_facade'               => \SH\SpeedOptimizer\Modules\ThirdPartyOptimization\MapFacadeOptimization::class,
			'speculative_prefetch'     => \SH\SpeedOptimizer\Modules\Preload\SpeculativePrefetchOptimization::class,
		);
	}
}
