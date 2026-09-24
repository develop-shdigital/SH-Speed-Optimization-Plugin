<?php
/**
 * Critical CSS: inline the styles needed for the first screen and load the
 * full stylesheets without blocking rendering (experimental).
 *
 * The CSS itself is generated in the administrator's browser by
 * assets/js/critical-css.js (driven by the optimization engine) and stored per
 * page template with store(). At runtime it is only used for templates whose
 * entry has a confidence of at least 90 and at most 60 KB of CSS.
 *
 * Option `shso_critical_css` (not autoloaded):
 *   [ template_key => [ css, confidence (0–100), generated_at, viewport_widths[], source_hash ] ]
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\CssOptimization;

use SH\SpeedOptimizer\Assets\AssetRewriter;
use SH\SpeedOptimizer\Assets\AssetSource;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;
use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Modules\AssetOptimization\Exclusions;
use SH\SpeedOptimizer\Modules\AssetOptimization\HeadInjector;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Critical CSS optimization.
 */
final class CriticalCssOptimization extends AbstractOptimization {

	public const OPTION         = 'shso_critical_css';
	public const MAX_BYTES      = 61440;
	public const MIN_CONFIDENCE = 90;
	public const MAX_TEMPLATES  = 30;
	public const STYLE_ID       = 'shso-critical-css';

	/**
	 * Safety net: turn remaining preloads into stylesheets when onload never fired
	 * (or the browser does not support preload).
	 */
	private const FALLBACK_SCRIPT = '<script id="shso-critical-css-fallback">(function(){try{var f=function(){var l=document.querySelectorAll(\'link[data-shso-async][rel="preload"]\');for(var i=0;i<l.length;i++){l[i].rel="stylesheet"}},r=document.createElement("link").relList;if(!r||!r.supports||!r.supports("preload")){f()}else{window.addEventListener("load",f)}}catch(e){}})();</script>';

	/**
	 * Request cache of the stored entries.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static ?array $entries = null;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'critical_css';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Critical CSS (experimental)', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Puts the styles needed for the first screen directly into the page and loads the remaining styles in the background, so pages appear sooner. The styles are generated and checked in your browser for each type of page.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CSS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::HIGH;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_EXPERIMENTAL;
	}

	/**
	 * {@inheritDoc}
	 */
	public function requirements(): array {
		return array( self::REQ_BROWSER );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_enabled(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function expected_changes(): array {
		return array( 'stylesheets' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$excluded = Exclusions::from_settings( $context->rules, 'css_no_optimize', $context->settings->all(), 'exclude_css' );
		$blocking = array();
		$bytes    = 0;

		foreach ( $context->collect( 'styles' ) as $style ) {
			$href  = trim( (string) ( $style['href'] ?? '' ) );
			$media = strtolower( trim( (string) ( $style['media'] ?? '' ) ) );
			if ( '' === $href || empty( $style['in_head'] ) || empty( $style['local'] ) || ! in_array( $media, array( '', 'all', 'screen' ), true ) ) {
				continue;
			}
			if ( $excluded( (string) ( $style['handle'] ?? '' ), $href ) ) {
				continue;
			}
			$key = (string) preg_replace( '/[?#].*$/s', '', $href );
			if ( ! isset( $blocking[ $key ] ) ) {
				$blocking[ $key ] = true;
				$bytes           += (int) ( $style['bytes'] ?? 0 );
			}
		}

		$count = count( $blocking );
		if ( 0 === $count ) {
			return $this->finalize( Assessment::not_applicable( __( 'No render-blocking stylesheets of your own were found.', 'sh-speed-optimizer' ) ), $context, 'critical_css' );
		}

		if ( $count >= 4 || $bytes > 100 * 1024 ) {
			$benefit = Assessment::BENEFIT_HIGH;
		} elseif ( $count >= 2 ) {
			$benefit = Assessment::BENEFIT_MEDIUM;
		} else {
			$benefit = Assessment::BENEFIT_LOW;
		}

		$assessment       = Assessment::make( true, 60, $benefit );
		$assessment->data = array(
			'blocking_stylesheets' => $count,
			'blocking_bytes'       => $bytes,
			'templates_ready'      => count( self::entries() ),
		);
		$assessment->note( __( 'Experimental: needs a browser test for every page type and is never enabled automatically.', 'sh-speed-optimizer' ) );
		if ( ! $context->has_browser_data() ) {
			$assessment->note( __( 'No measurements from your browser yet; critical CSS can only be generated there.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'critical_css' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( ! $this->plugin->context()->is_frontend_request() ) {
			return;
		}
		$runtime->add_html_transform( $this->id(), array( $this, 'transform' ), 45 );
	}

	/**
	 * HTML transform.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	public function transform( HtmlDocument $doc ): void {
		// Inline event handlers (onload) are blocked by strict Content Security Policies (nonces).
		if ( preg_match( '#<script\b[^>]*\snonce\s*=#i', $doc->html() ) ) {
			return;
		}

		$entry = self::entry( Context::template_key() );
		if ( null === $entry ) {
			return;
		}

		$excluded    = Exclusions::matcher( $this->plugin->runtime()->rules(), 'css_no_optimize', (array) $this->plugin->settings()->get( 'exclude_css', array() ) );
		$convertible = static function ( Tag $tag ) use ( $excluded ): bool {
			return self::is_convertible( $tag, $excluded, array( AssetSource::class, 'is_local_url' ) );
		};

		if ( 0 === strpos( (string) $entry['source_hash'], 'v1:' ) && ! self::hash_matches( $doc, (string) $entry['source_hash'], $convertible ) ) {
			return; // The page's stylesheets changed since the critical CSS was generated.
		}

		self::apply_to_document( $doc, (string) $entry['css'], $convertible );
	}

	/**
	 * Inline critical CSS and load convertible <head> stylesheets asynchronously (pure).
	 *
	 * @param HtmlDocument $doc         Document.
	 * @param string       $css         Critical CSS.
	 * @param callable     $convertible function( Tag $tag ): bool.
	 * @return int Number of converted stylesheet links (0 = document unchanged).
	 */
	public static function apply_to_document( HtmlDocument $doc, string $css, callable $convertible ): int {
		$css = self::sanitize_css( $css );
		if ( '' === trim( $css ) ) {
			return 0;
		}

		$converted = $doc->replace_tags(
			'link',
			static function ( Tag $tag, array $info ) use ( $convertible ) {
				if ( empty( $info['in_head'] ) || ! $convertible( $tag ) ) {
					return null;
				}
				$original = $tag->to_html();
				$preload  = Tag::parse( $original );
				if ( null === $preload ) {
					return null;
				}
				$preload->set( 'rel', 'preload' );
				$preload->set( 'as', 'style' );
				$preload->set( 'data-shso-async', null );
				$preload->set( 'onload', "this.onload=null;this.rel='stylesheet'" );
				return $preload->to_html() . '<noscript>' . $original . '</noscript>';
			}
		);

		if ( 0 === $converted ) {
			return 0;
		}

		HeadInjector::insert_early( $doc, '<style id="' . self::STYLE_ID . '">' . $css . '</style>' );
		$doc->insert_before_body_end( self::FALLBACK_SCRIPT );

		return $converted;
	}

	/**
	 * Whether a <link> is a same-site render-blocking stylesheet that may load asynchronously.
	 *
	 * @param Tag      $tag      Link tag.
	 * @param callable $excluded function( string $handle, string $url ): bool.
	 * @param callable $is_local function( string $url ): bool.
	 */
	public static function is_convertible( Tag $tag, callable $excluded, callable $is_local ): bool {
		if ( 'stylesheet' !== strtolower( trim( (string) $tag->get( 'rel' ) ) ) ) {
			return false;
		}
		$href = trim( (string) $tag->get( 'href' ) );
		if ( '' === $href ) {
			return false;
		}
		foreach ( array( 'disabled', 'onload', 'integrity', 'data-no-optimize', 'data-shso-skip', 'data-noptimize' ) as $attribute ) {
			if ( $tag->has( $attribute ) ) {
				return false;
			}
		}
		$media = strtolower( trim( (string) $tag->get( 'media' ) ) );
		if ( ! in_array( $media, array( '', 'all', 'screen' ), true ) ) {
			return false;
		}
		$original = AssetRewriter::original_url( $href );
		if ( ! $is_local( $original ) ) {
			return false; // Cross-origin stylesheets stay untouched.
		}
		return ! $excluded( AssetRewriter::handle( $tag, 'css' ), $original );
	}

	/**
	 * Hash identifying the set of stylesheets critical CSS was generated for. The engine
	 * computes it from the stylesheet URLs it saw; the runtime compares it with the page's
	 * current stylesheets (as rendered, or their originals) and skips stale entries.
	 *
	 * @param string[] $urls Stylesheet URLs (absolute, protocol- or root-relative).
	 */
	public static function source_hash( array $urls ): string {
		$paths = array();
		foreach ( $urls as $url ) {
			$path = (string) preg_replace( '#^(?:https?:)?//[^/]+#i', '', html_entity_decode( trim( (string) $url ), ENT_QUOTES ) );
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}
		$paths = array_values( array_unique( $paths ) );
		sort( $paths );
		return 'v1:' . md5( implode( "\n", $paths ) );
	}

	/**
	 * Store (or replace) the critical CSS of a template. Called by the engine after the CSS
	 * was generated and verified in the browser.
	 *
	 * @param string              $template   Template key (Context::template_key()).
	 * @param string              $css        Critical CSS.
	 * @param int                 $confidence Confidence 0–100 from the visual verification.
	 * @param array<string,mixed> $meta       generated_at (int), viewport_widths (int[]), source_hash (string).
	 */
	public static function store( string $template, string $css, int $confidence, array $meta = array() ): void {
		$template = self::sanitize_template( $template );
		$css      = self::sanitize_css( $css );
		if ( '' === $template || '' === trim( $css ) || strlen( $css ) > self::MAX_BYTES ) {
			return;
		}

		$widths = array();
		foreach ( (array) ( $meta['viewport_widths'] ?? array() ) as $width ) {
			$width = (int) $width;
			if ( $width > 0 && $width < 10000 ) {
				$widths[] = $width;
			}
		}

		$entry = array(
			'css'             => $css,
			'confidence'      => max( 0, min( 100, $confidence ) ),
			'generated_at'    => isset( $meta['generated_at'] ) ? (int) $meta['generated_at'] : time(),
			'viewport_widths' => array_values( array_unique( $widths ) ),
			'source_hash'     => substr( (string) preg_replace( '/[^a-zA-Z0-9:_\-]/', '', (string) ( $meta['source_hash'] ?? '' ) ), 0, 64 ),
		);

		$entries              = self::entries();
		$entries[ $template ] = $entry;

		if ( count( $entries ) > self::MAX_TEMPLATES ) {
			uasort(
				$entries,
				static function ( $a, $b ) {
					return (int) $b['generated_at'] <=> (int) $a['generated_at'];
				}
			);
			$entries = array_slice( $entries, 0, self::MAX_TEMPLATES, true );
		}

		update_option( self::OPTION, $entries, false );
		self::$entries = $entries;

		/**
		 * Fires after critical CSS was stored for a template.
		 *
		 * @param string              $template Template key.
		 * @param array<string,mixed> $entry    Stored entry (css, confidence, generated_at, viewport_widths, source_hash).
		 */
		do_action( 'shso_critical_css_stored', $template, $entry );
	}

	/**
	 * Remove the critical CSS of a template.
	 *
	 * @param string $template Template key.
	 */
	public static function remove( string $template ): void {
		$template = self::sanitize_template( $template );
		$entries  = self::entries();
		if ( ! isset( $entries[ $template ] ) ) {
			return;
		}
		unset( $entries[ $template ] );
		if ( empty( $entries ) ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $entries, false );
		}
		self::$entries = $entries;
	}

	/**
	 * All stored entries (validated).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function entries(): array {
		if ( null !== self::$entries ) {
			return self::$entries;
		}
		$raw     = get_option( self::OPTION, array() );
		$entries = array();
		foreach ( is_array( $raw ) ? $raw : array() as $template => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['css'] ) || ! is_string( $entry['css'] ) ) {
				continue;
			}
			$entries[ (string) $template ] = array(
				'css'             => $entry['css'],
				'confidence'      => (int) ( $entry['confidence'] ?? 0 ),
				'generated_at'    => (int) ( $entry['generated_at'] ?? 0 ),
				'viewport_widths' => array_map( 'intval', (array) ( $entry['viewport_widths'] ?? array() ) ),
				'source_hash'     => (string) ( $entry['source_hash'] ?? '' ),
			);
		}
		self::$entries = $entries;
		return $entries;
	}

	/**
	 * The entry usable at runtime for a template (confidence ≥ 90, non-empty, ≤ 60 KB), or null.
	 *
	 * @param string $template Template key.
	 * @return array<string,mixed>|null
	 */
	public static function entry( string $template ): ?array {
		$entry = self::entries()[ self::sanitize_template( $template ) ] ?? null;
		if ( null === $entry || $entry['confidence'] < self::MIN_CONFIDENCE || '' === trim( $entry['css'] ) || strlen( $entry['css'] ) > self::MAX_BYTES ) {
			return null;
		}
		return $entry;
	}

	/**
	 * Forget the request cache (tests).
	 */
	public static function flush_cache(): void {
		self::$entries = null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		delete_option( self::OPTION );
		self::$entries = null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		$templates = array();
		foreach ( self::entries() as $template => $entry ) {
			$templates[] = array(
				'template'     => $template,
				'confidence'   => $entry['confidence'],
				'bytes'        => strlen( $entry['css'] ),
				'generated_at' => $entry['generated_at'],
				'used'         => $entry['confidence'] >= self::MIN_CONFIDENCE,
			);
		}
		return array(
			'templates' => $templates,
			'label'     => sprintf(
				/* translators: %d: number of page types */
				_n( 'Critical CSS ready for %d page type', 'Critical CSS ready for %d page types', count( $templates ), 'sh-speed-optimizer' ),
				count( $templates )
			),
		);
	}

	/**
	 * Whether the stored source hash matches the page's current stylesheets.
	 *
	 * @param HtmlDocument $doc         Document.
	 * @param string       $hash        Stored hash.
	 * @param callable     $convertible Link filter.
	 */
	private static function hash_matches( HtmlDocument $doc, string $hash, callable $convertible ): bool {
		$rendered  = array();
		$originals = array();
		$doc->replace_tags(
			'link',
			static function ( Tag $tag, array $info ) use ( $convertible, &$rendered, &$originals ) {
				if ( ! empty( $info['in_head'] ) && $convertible( $tag ) ) {
					$href        = trim( (string) $tag->get( 'href' ) );
					$rendered[]  = $href;
					$originals[] = AssetRewriter::original_url( $href );
				}
				return null;
			}
		);
		return self::source_hash( $rendered ) === $hash || self::source_hash( $originals ) === $hash;
	}

	/**
	 * Make CSS safe to embed in a <style> element.
	 *
	 * @param string $css CSS.
	 */
	private static function sanitize_css( string $css ): string {
		$css = str_replace( "\0", '', $css );
		return (string) preg_replace( '#</(style)#i', '<\\/$1', $css );
	}

	/**
	 * Sanitize a template key.
	 *
	 * @param string $template Template key.
	 */
	private static function sanitize_template( string $template ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $template ) ), 0, 100 );
	}
}
