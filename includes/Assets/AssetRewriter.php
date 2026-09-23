<?php
/**
 * Points local <link rel="stylesheet"> and <script src> tags to their
 * optimized copies.
 *
 * Only tags whose copy exists (or can be generated within the request budget)
 * are changed; everything else keeps loading the original. All attributes are
 * preserved. Tags are skipped when they carry `integrity` (the hash would no
 * longer match), `data-no-optimize`, `data-shso-skip`, or match an exclusion
 * (handle = id attribute without the "-css"/"-js" suffix, or the URL).
 *
 * The original URL of every rewritten tag is remembered for the request so that
 * later transformers (critical CSS, delay, defer) can match exclusions against it.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Asset tag rewriter.
 */
final class AssetRewriter {

	public const PRIORITY_CSS = 40;
	public const PRIORITY_JS  = 50;

	/**
	 * Executable script types a copy may be served for (modules are excluded: relative imports).
	 */
	private const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript' );

	/**
	 * Copy URL => original URL for the current request.
	 *
	 * @var array<string,string>
	 */
	private static array $originals = array();

	/**
	 * Runtimes the rewriter was registered with (spl object ids).
	 *
	 * @var array<int,bool>
	 */
	private static array $registered = array();

	/**
	 * Resolver: function( string $url, string $type ): ?string (copy URL or null).
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * Exclusion check: function( string $type, string $handle, string $url ): bool.
	 *
	 * @var callable
	 */
	private $is_excluded;

	/**
	 * Constructor.
	 *
	 * @param callable $resolver    function( string $url, string $type ): ?string.
	 * @param callable $is_excluded function( string $type, string $handle, string $url ): bool.
	 */
	public function __construct( callable $resolver, callable $is_excluded ) {
		$this->resolver    = $resolver;
		$this->is_excluded = $is_excluded;
	}

	/**
	 * Register the CSS (priority 40) and JS (priority 50) HTML transforms once per runtime.
	 * They only act when at least one CSS/JS transform or minification is active on the page.
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public static function register( Runtime $runtime ): void {
		$key = spl_object_id( $runtime );
		if ( isset( self::$registered[ $key ] ) ) {
			return;
		}
		self::$registered[ $key ] = true;

		$runtime->html()->add(
			'asset_copies_css',
			static function ( HtmlDocument $doc ) use ( $runtime ) {
				self::run( $runtime, $doc, 'css' );
			},
			self::PRIORITY_CSS
		);
		$runtime->html()->add(
			'asset_copies_js',
			static function ( HtmlDocument $doc ) use ( $runtime ) {
				self::run( $runtime, $doc, 'js' );
			},
			self::PRIORITY_JS
		);
	}

	/**
	 * Transform ids active on the current page for a type (minification + content transforms).
	 *
	 * @param Runtime $runtime Runtime.
	 * @param string  $type    css|js.
	 * @return string[]
	 */
	public static function active_ids( Runtime $runtime, string $type ): array {
		$minify = 'css' === $type ? AssetCopies::MINIFY_CSS : AssetCopies::MINIFY_JS;
		$ids    = array();
		if ( $runtime->is_active_on_page( $minify ) ) {
			$ids[] = $minify;
		}
		$transforms = 'css' === $type ? $runtime->css_transforms() : $runtime->js_transforms();
		foreach ( array_keys( $transforms ) as $id ) {
			if ( $runtime->is_active_on_page( (string) $id ) ) {
				$ids[] = (string) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Run one transform for the current request.
	 *
	 * @param Runtime      $runtime Runtime.
	 * @param HtmlDocument $doc     Document.
	 * @param string       $type    css|js.
	 */
	private static function run( Runtime $runtime, HtmlDocument $doc, string $type ): void {
		$ids = self::active_ids( $runtime, $type );
		if ( empty( $ids ) ) {
			return;
		}

		$plugin   = $runtime->plugin();
		$copies   = AssetCopies::instance();
		$rules    = $runtime->rules();
		$excludes = (array) $plugin->settings()->get( 'css' === $type ? 'exclude_css' : 'exclude_js', array() );
		$list     = 'css' === $type ? 'css_no_optimize' : 'js_no_minify';

		if ( $plugin->context()->is_verification() ) {
			// Signed verification renders must show the real result: allow more synchronous work.
			$copies->set_limits(
				array(
					'sync_max_bytes' => 2097152,
					'sync_max_count' => 40,
					'sync_budget_ms' => 5000.0,
				)
			);
		}

		$rewriter = new self(
			static function ( string $url, string $asset_type ) use ( $copies, $ids ): ?string {
				return $copies->copy_url( $url, $asset_type, $ids, 'frontend' );
			},
			static function ( string $asset_type, string $handle, string $url ) use ( $rules, $excludes, $list ): bool {
				unset( $asset_type );
				return $rules->matches( $list, array( $handle, $url ) )
					|| \SH\SpeedOptimizer\Core\Context::url_matches( $excludes, $url )
					|| ( '' !== $handle && \SH\SpeedOptimizer\Core\Context::url_matches( $excludes, $handle ) );
			}
		);

		try {
			if ( 'css' === $type ) {
				$rewriter->rewrite_styles( $doc );
			} else {
				$rewriter->rewrite_scripts( $doc );
			}
		} finally {
			$copies->flush_queue();
		}
	}

	/**
	 * Rewrite stylesheet links.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Number of rewritten tags.
	 */
	public function rewrite_styles( HtmlDocument $doc ): int {
		return $doc->replace_tags(
			'link',
			function ( Tag $tag ) {
				$rel = strtolower( trim( (string) $tag->get( 'rel' ) ) );
				if ( 'stylesheet' !== $rel ) {
					return null;
				}
				$href = trim( (string) $tag->get( 'href' ) );
				if ( '' === $href || self::skip_tag( $tag ) ) {
					return null;
				}
				$handle = self::handle( $tag, 'css' );
				if ( ( $this->is_excluded )( 'css', $handle, $href ) ) {
					return null;
				}
				$copy = ( $this->resolver )( $href, 'css' );
				if ( null === $copy || '' === $copy ) {
					return null;
				}
				self::remember( $copy, $href );
				return $tag->set( 'href', $copy );
			}
		);
	}

	/**
	 * Rewrite external scripts.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Number of rewritten tags.
	 */
	public function rewrite_scripts( HtmlDocument $doc ): int {
		return $doc->replace_scripts(
			function ( Tag $tag, string $code ) {
				$attribute = 'src';
				$type      = ScriptGraph::normalized_type( $tag->get( 'type' ) );
				if ( DelayEngine::TYPE === $type ) {
					// Already delayed: its real type is kept in data-shso-type.
					$attribute = 'data-shso-src';
					$type      = ScriptGraph::normalized_type( $tag->get( 'data-shso-type' ) );
				}
				if ( ! in_array( $type, self::JS_TYPES, true ) ) {
					return null;
				}
				$src = trim( (string) $tag->get( $attribute ) );
				if ( '' === $src || self::skip_tag( $tag ) ) {
					return null;
				}
				$handle = self::handle( $tag, 'js' );
				if ( ( $this->is_excluded )( 'js', $handle, $src ) ) {
					return null;
				}
				$copy = ( $this->resolver )( $src, 'js' );
				if ( null === $copy || '' === $copy ) {
					return null;
				}
				self::remember( $copy, $src );
				$tag->set( $attribute, $copy );
				return $tag->to_html() . $code . '</script>';
			}
		);
	}

	/**
	 * Original URL of a (possibly rewritten) asset URL.
	 *
	 * @param string $url URL found in the page.
	 */
	public static function original_url( string $url ): string {
		return self::$originals[ $url ] ?? $url;
	}

	/**
	 * Remember a rewrite.
	 *
	 * @param string $copy     Copy URL.
	 * @param string $original Original URL.
	 */
	public static function remember( string $copy, string $original ): void {
		if ( count( self::$originals ) < 1000 ) {
			self::$originals[ $copy ] = $original;
		}
	}

	/**
	 * Forget request state (tests).
	 */
	public static function reset(): void {
		self::$originals  = array();
		self::$registered = array();
	}

	/**
	 * Handle from the id attribute WordPress prints ("{handle}-css" / "{handle}-js").
	 *
	 * @param Tag    $tag  Tag.
	 * @param string $type css|js.
	 */
	public static function handle( Tag $tag, string $type ): string {
		$id     = (string) $tag->get( 'id' );
		$suffix = '-' . $type;
		if ( strlen( $id ) > strlen( $suffix ) && substr( $id, -strlen( $suffix ) ) === $suffix ) {
			return substr( $id, 0, -strlen( $suffix ) );
		}
		return '';
	}

	/**
	 * Whether a tag opts out of optimization.
	 *
	 * @param Tag $tag Tag.
	 */
	private static function skip_tag( Tag $tag ): bool {
		foreach ( array( 'integrity', 'data-no-optimize', 'data-shso-skip', 'data-noptimize', 'data-no-minify' ) as $attribute ) {
			if ( $tag->has( $attribute ) ) {
				return true;
			}
		}
		return false;
	}
}
