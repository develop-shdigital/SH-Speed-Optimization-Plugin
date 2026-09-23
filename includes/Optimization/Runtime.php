<?php
/**
 * Per-request runtime: decides which optimizations are effectively active
 * and gives them a place to register hooks and transformers.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\HtmlPipeline;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime service.
 */
final class Runtime {

	public const PAGE_DATA_OPTION = 'shso_page_data';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Effective active ids (null until computed).
	 *
	 * @var string[]|null
	 */
	private ?array $active = null;

	/**
	 * HTML pipeline.
	 *
	 * @var HtmlPipeline|null
	 */
	private ?HtmlPipeline $html = null;

	/**
	 * CSS content transforms: [ priority, id, callable ].
	 *
	 * @var array<int,array{0:int,1:string,2:callable}>
	 */
	private array $css_transforms = array();

	/**
	 * JS content transforms: [ priority, id, callable ].
	 *
	 * @var array<int,array{0:int,1:string,2:callable}>
	 */
	private array $js_transforms = array();

	/**
	 * Page data cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $page_data = null;

	/**
	 * Booted optimizations.
	 *
	 * @var array<string,OptimizationInterface>
	 */
	private array $booted = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Instantiate active optimizations and let them register their hooks.
	 */
	public function boot(): void {
		foreach ( $this->active_ids() as $id ) {
			$optimization = $this->plugin->registry()->get( $id );
			if ( null === $optimization ) {
				continue;
			}
			try {
				$optimization->register_runtime( $this );
				$this->booted[ $id ] = $optimization;
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error(
					'Optimization failed to register; it is skipped for this request.',
					array(
						'optimization' => $id,
						'error'        => $e->getMessage(),
					),
					'engine'
				);
			}
		}

		if ( $this->plugin->context()->is_frontend_request() ) {
			add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 1000 );
		}
	}

	/**
	 * Re-read the active set after the engine changed it during this request
	 * (background jobs) and register newly active optimizations, so services
	 * such as asset generation see the same transforms as future page views.
	 */
	public function refresh(): void {
		$this->active = null;
		$this->plugin->state()->flush();
		foreach ( $this->active_ids() as $id ) {
			if ( isset( $this->booted[ $id ] ) ) {
				continue;
			}
			$optimization = $this->plugin->registry()->get( $id );
			if ( null === $optimization ) {
				continue;
			}
			try {
				$optimization->register_runtime( $this );
				$this->booted[ $id ] = $optimization;
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error(
					'Optimization failed to register.',
					array(
						'optimization' => $id,
						'error'        => $e->getMessage(),
					),
					'engine'
				);
			}
		}
	}

	/**
	 * Effective active optimization ids for this request.
	 *
	 * @return string[]
	 */
	public function active_ids(): array {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$context = $this->plugin->context();

		if ( Context::is_emergency_safe_mode() ) {
			$this->active = array();
			return $this->active;
		}

		$verification = $context->verification();
		if ( null !== $verification ) {
			$ids          = 'candidate' === $verification['m'] ? (array) $verification['o'] : array();
			$this->active = array_values( array_filter( $ids, array( $this->plugin->registry(), 'has' ) ) );
			return $this->active;
		}

		$ids = $this->plugin->state()->active_ids();

		if ( $context->is_safe_mode() ) {
			$ids = array_values(
				array_filter(
					$ids,
					function ( $id ) {
						$optimization = $this->plugin->registry()->get( $id );
						return null !== $optimization && $optimization->safe_mode_compatible();
					}
				)
			);
		}

		/**
		 * Filters the optimizations active for the current request.
		 *
		 * @param string[] $ids Active ids.
		 */
		$this->active = array_values( array_unique( (array) apply_filters( 'shso_active_optimizations', $ids ) ) );
		return $this->active;
	}

	/**
	 * Whether an optimization is effectively active for this request.
	 *
	 * @param string $id Id.
	 */
	public function is_active( string $id ): bool {
		return in_array( $id, $this->active_ids(), true );
	}

	/**
	 * Whether an optimization may transform the current page: active, not excluded for this
	 * template/URL, and the URL is not excluded by the user. Valid after the main query ran.
	 *
	 * @param string $id Id.
	 */
	public function is_active_on_page( string $id ): bool {
		if ( ! $this->is_active( $id ) ) {
			return false;
		}

		$path = Context::request_path();

		if ( Context::url_matches( (array) $this->plugin->settings()->get( 'exclude_urls', array() ), $path ) ) {
			return false;
		}

		// Verification requests test exactly the requested set, ignoring page exclusions.
		if ( $this->plugin->context()->is_verification() ) {
			return true;
		}

		$exclusions = $this->plugin->state()->page_exclusions( $id );
		if ( in_array( 'tpl:' . Context::template_key(), $exclusions, true ) || in_array( 'url:' . $path, $exclusions, true ) ) {
			return false;
		}

		/**
		 * Filters whether an optimization runs on the current page.
		 *
		 * @param bool   $active Whether it runs.
		 * @param string $id     Optimization id.
		 */
		return (bool) apply_filters( 'shso_optimization_active_on_page', true, $id );
	}

	/**
	 * Register an HTML transformer for an optimization. It only runs on pages where the optimization
	 * is active (page/template exclusions respected).
	 *
	 * @param string   $id          Optimization id.
	 * @param callable $transformer function( HtmlDocument $doc ): void.
	 * @param int      $priority    See HtmlPipeline::add().
	 */
	public function add_html_transform( string $id, callable $transformer, int $priority = 50 ): void {
		$this->html()->add(
			$id,
			function ( HtmlDocument $doc ) use ( $id, $transformer ) {
				if ( $this->is_active_on_page( $id ) ) {
					$transformer( $doc );
				}
			},
			$priority
		);
	}

	/**
	 * Register a CSS content transform applied when optimized stylesheet copies are generated.
	 *
	 * @param string   $id        Optimization id.
	 * @param callable $transform function( string $css, string $source_url ): string.
	 * @param int      $priority  Lower runs first.
	 */
	public function add_css_transform( string $id, callable $transform, int $priority = 50 ): void {
		$this->css_transforms[] = array( $priority, $id, $transform );
	}

	/**
	 * Register a JS content transform applied when optimized script copies are generated.
	 *
	 * @param string   $id        Optimization id.
	 * @param callable $transform function( string $js, string $source_url ): string.
	 * @param int      $priority  Lower runs first.
	 */
	public function add_js_transform( string $id, callable $transform, int $priority = 50 ): void {
		$this->js_transforms[] = array( $priority, $id, $transform );
	}

	/**
	 * CSS transforms sorted by priority: id => callable.
	 *
	 * @return array<string,callable>
	 */
	public function css_transforms(): array {
		return self::sorted( array_filter( $this->css_transforms, fn( $item ) => $this->is_active( $item[1] ) ) );
	}

	/**
	 * JS transforms sorted by priority: id => callable.
	 *
	 * @return array<string,callable>
	 */
	public function js_transforms(): array {
		return self::sorted( array_filter( $this->js_transforms, fn( $item ) => $this->is_active( $item[1] ) ) );
	}

	/**
	 * HTML pipeline.
	 */
	public function html(): HtmlPipeline {
		if ( null === $this->html ) {
			$this->html = new HtmlPipeline( $this->plugin->logger() );
		}
		return $this->html;
	}

	/**
	 * Compatibility rules for this site.
	 */
	public function rules(): Rules {
		return $this->plugin->compatibility()->rules();
	}

	/**
	 * Plugin container.
	 */
	public function plugin(): Plugin {
		return $this->plugin;
	}

	/**
	 * Browser-measured data for the current template (or a given one):
	 * lcp{url,type,selector,confidence}, above_fold_images[], fonts_preload[], …
	 *
	 * @param string|null $template_key Template key; defaults to the current one.
	 * @return array<string,mixed>
	 */
	public function page_data( ?string $template_key = null ): array {
		if ( null === $this->page_data ) {
			$raw             = get_option( self::PAGE_DATA_OPTION, array() );
			$this->page_data = is_array( $raw ) ? $raw : array();
		}
		$key = $template_key ?? Context::template_key();
		return isset( $this->page_data[ $key ] ) && is_array( $this->page_data[ $key ] ) ? $this->page_data[ $key ] : array();
	}

	/**
	 * Start the output buffer when the request qualifies.
	 */
	public function maybe_start_buffer(): void {
		if ( ! $this->html()->has_transformers() || ! $this->should_transform() ) {
			return;
		}
		$this->html()->start();
	}

	/**
	 * Whether HTML transformations may run on this request.
	 */
	public function should_transform(): bool {
		$context = $this->plugin->context();

		if ( Context::is_emergency_safe_mode() || ! $context->is_frontend_request() || $context->is_editor_preview() ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return false;
		}
		if ( defined( 'SHSO_DONOTOPTIMIZE' ) && SHSO_DONOTOPTIMIZE ) {
			return false;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}
		// Editors see the unmodified site so builders and previews work exactly as authored.
		if ( ! $context->is_verification() && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false;
		}
		if ( Context::url_matches( (array) $this->plugin->settings()->get( 'exclude_urls', array() ), Context::request_path() ) ) {
			return false;
		}

		/**
		 * Filters whether SH Speed Optimizer transforms the current page.
		 *
		 * @param bool $transform Default true.
		 */
		return (bool) apply_filters( 'shso_should_transform', true );
	}

	/**
	 * Sort a transform list.
	 *
	 * @param array<int,array{0:int,1:string,2:callable}> $list List.
	 * @return array<string,callable>
	 */
	private static function sorted( array $list ): array {
		usort(
			$list,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		$out = array();
		foreach ( $list as $item ) {
			$out[ $item[1] ] = $item[2];
		}
		return $out;
	}
}
