<?php
/**
 * Script dependency analysis: which external scripts can be deferred without
 * changing the order in which dependent code runs.
 *
 * Inputs:
 *  - `$scripts`: WordPress data for printed handles (see capture()):
 *    handle => [ src, deps[], in_head, has_inline_after, has_inline_before, strategy (''|defer|async), module ]
 *  - `$tags`: every <script> element of the page in document order (see tags_from_document()):
 *    [ index, handle|null, role (main|extra|before|after|translations|module|''), src|null, code, inline,
 *      js (executable type), async, defer, module ]
 *  - options: is_excluded callable( handle, src ): bool, inline_globals [ handle => globals[] ],
 *    is_local callable( src ): bool (defaults to "relative URL").
 *
 * A handle may be deferred only when (a) it is not excluded, (b) it has no inline
 * "after" script, (c) every script depending on it (transitively) is deferred too,
 * (d) no non-deferred inline script printed after it references a global it defines,
 * (e) it is not async/module already and (f) it has a src. Handles that define globals
 * (jQuery, wp.*, anything in the inline_globals rules, jQuery plugins) additionally need
 * every later external script to be deferred, because undeclared dependencies on such
 * globals are common. Later inline scripts mentioning a name derived from the handle or
 * file name (e.g. "Swiper" for handle "swiper") also block deferral, and so do later
 * hard-coded local scripts whose dependencies are unknown. Scripts without a known
 * handle are only deferred when the third-party catalog marks them defer-safe. The rules
 * are applied until nothing changes.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Script graph.
 */
final class ScriptGraph {

	/**
	 * Globals defined by well-known handles.
	 */
	private const DEFAULT_GLOBALS = array(
		'jquery-core'    => array( 'jQuery', '$' ),
		'jquery'         => array( 'jQuery', '$' ),
		'jquery-migrate' => array( 'jQuery', '$' ),
		'underscore'     => array( '_' ),
		'lodash'         => array( 'lodash', '_' ),
		'backbone'       => array( 'Backbone' ),
		'react'          => array( 'React' ),
		'react-dom'      => array( 'ReactDOM' ),
		'moment'         => array( 'moment' ),
		'imagesloaded'   => array( 'imagesLoaded' ),
		'masonry'        => array( 'Masonry' ),
		'wp-polyfill'    => array( 'regeneratorRuntime', 'wp' ),
	);

	/**
	 * Dependencies WordPress does not declare.
	 */
	private const IMPLICIT_DEPS = array(
		'jquery-migrate' => array( 'jquery-core' ),
	);

	/**
	 * Executable script types.
	 */
	private const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript', 'text/jscript', 'module' );

	/**
	 * WordPress script data.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $scripts;

	/**
	 * Document tags.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $tags;

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Computed result: tag index => handle|'' for deferrable tags.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $scripts WordPress data per handle.
	 * @param array<int,array<string,mixed>>    $tags    Document tags in order.
	 * @param array<string,mixed>               $options is_excluded (callable), inline_globals (array).
	 */
	public function __construct( array $scripts, array $tags, array $options = array() ) {
		$this->scripts = $scripts;
		$this->tags    = array_values( $tags );
		$this->options = $options;
	}

	/**
	 * Handles that may be deferred (document order).
	 *
	 * @return string[]
	 */
	public function deferrable(): array {
		return array_values( array_filter( array_unique( $this->compute() ) ) );
	}

	/**
	 * Tag indexes (as reported by HtmlDocument::replace_scripts()) that may get `defer`.
	 *
	 * @return int[]
	 */
	public function deferrable_tags(): array {
		$indexes = array();
		foreach ( array_keys( $this->compute() ) as $position ) {
			$indexes[] = (int) $this->tags[ $position ]['index'];
		}
		return $indexes;
	}

	/**
	 * Scripts loaded more than once (same URL ignoring scheme and the "ver" parameter).
	 * Report only — nothing is removed.
	 *
	 * @return array<string,array<int,array{index:int,handle:string|null,src:string}>> Normalized URL => occurrences.
	 */
	public function duplicates(): array {
		$seen = array();
		foreach ( $this->tags as $tag ) {
			if ( empty( $tag['src'] ) || empty( $tag['js'] ) ) {
				continue;
			}
			$key            = self::normalize_src( (string) $tag['src'] );
			$seen[ $key ][] = array(
				'index'  => (int) $tag['index'],
				'handle' => $tag['handle'],
				'src'    => (string) $tag['src'],
			);
		}
		return array_filter(
			$seen,
			static function ( $occurrences ) {
				return count( $occurrences ) > 1;
			}
		);
	}

	/**
	 * Collect the WordPress data needed for the analysis from a WP_Scripts instance.
	 *
	 * @param object|null $wp_scripts WP_Scripts (or compatible object).
	 * @return array<string,array<string,mixed>>
	 */
	public static function capture( $wp_scripts ): array {
		if ( ! is_object( $wp_scripts ) || ! isset( $wp_scripts->registered ) || ! is_array( $wp_scripts->registered ) ) {
			return array();
		}

		$done   = isset( $wp_scripts->done ) && is_array( $wp_scripts->done ) ? $wp_scripts->done : array();
		$groups = isset( $wp_scripts->groups ) && is_array( $wp_scripts->groups ) ? $wp_scripts->groups : array();
		$data   = array();

		foreach ( $wp_scripts->registered as $handle => $dependency ) {
			if ( ! is_object( $dependency ) ) {
				continue;
			}
			$handle = (string) $handle;
			$extra  = isset( $dependency->extra ) && is_array( $dependency->extra ) ? $dependency->extra : array();
			$after  = $extra['after'] ?? array();
			$before = $extra['before'] ?? array();

			$data[ $handle ] = array(
				'src'               => is_string( $dependency->src ?? null ) ? $dependency->src : '',
				'deps'              => array_values( array_map( 'strval', (array) ( $dependency->deps ?? array() ) ) ),
				'in_head'           => empty( $groups[ $handle ] ) && empty( $extra['group'] ),
				'has_inline_after'  => ! empty( array_filter( (array) $after ) ),
				'has_inline_before' => ! empty( array_filter( (array) $before ) ),
				'strategy'          => in_array( $extra['strategy'] ?? '', array( 'defer', 'async' ), true ) ? $extra['strategy'] : '',
				'module'            => false,
				'printed'           => in_array( $handle, $done, true ),
			);
		}

		return $data;
	}

	/**
	 * Build the tag list from a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return array<int,array<string,mixed>>
	 */
	public static function tags_from_document( HtmlDocument $doc ): array {
		$tags = array();
		$doc->replace_scripts(
			static function ( Tag $tag, string $code, array $info ) use ( &$tags ) {
				$tags[] = self::describe_tag( $tag, $code, (int) $info['index'], (bool) $info['in_head'] );
				return null;
			}
		);
		return $tags;
	}

	/**
	 * Describe one script element.
	 *
	 * @param Tag    $tag     Opening tag.
	 * @param string $code    Inline code.
	 * @param int    $index   Index among script elements.
	 * @param bool   $in_head Whether it is in <head>.
	 * @return array<string,mixed>
	 */
	public static function describe_tag( Tag $tag, string $code, int $index, bool $in_head = false ): array {
		$type   = self::normalized_type( $tag->get( 'type' ) );
		$src    = $tag->get( 'src' );
		$id     = (string) $tag->get( 'id' );
		$handle = null;
		$role   = '';

		if ( preg_match( '/^(.+)-js(?:-(extra|before|after|translations|module))?$/', $id, $m ) ) {
			$handle = $m[1];
			$role   = $m[2] ?? '';
			$role   = '' === $role ? 'main' : $role;
		}

		return array(
			'index'   => $index,
			'handle'  => $handle,
			'role'    => $role,
			'src'     => null !== $src && '' !== trim( $src ) ? trim( $src ) : null,
			'code'    => $code,
			'inline'  => null === $src || '' === trim( $src ),
			'js'      => in_array( $type, self::JS_TYPES, true ),
			'async'   => $tag->has( 'async' ),
			'defer'   => $tag->has( 'defer' ),
			'module'  => 'module' === $type,
			'in_head' => $in_head,
		);
	}

	/**
	 * Normalized script type ("" for classic scripts).
	 *
	 * @param string|null $type Raw type attribute.
	 */
	public static function normalized_type( ?string $type ): string {
		$type = strtolower( trim( (string) $type ) );
		$semi = strpos( $type, ';' );
		if ( false !== $semi ) {
			$type = trim( substr( $type, 0, $semi ) );
		}
		return 'text/javascript' === $type ? '' : $type;
	}

	/**
	 * Globals a handle defines (defaults + rules + conventions).
	 *
	 * @param string                 $handle         Handle.
	 * @param array<string,string[]> $inline_globals Rules ( handle => globals ).
	 * @param array<string,bool>     $jquery_based   Handles depending (transitively) on jQuery.
	 * @return string[]
	 */
	public static function globals_for( string $handle, array $inline_globals = array(), array $jquery_based = array() ): array {
		$globals = self::DEFAULT_GLOBALS[ $handle ] ?? array();
		if ( isset( $inline_globals[ $handle ] ) ) {
			$globals = array_merge( $globals, (array) $inline_globals[ $handle ] );
		}
		if ( 0 === strpos( $handle, 'wp-' ) ) {
			$globals[] = 'wp';
		}
		if ( 0 === strpos( $handle, 'jquery-' ) || ! empty( $jquery_based[ $handle ] ) ) {
			// jQuery plugins extend jQuery/$ — inline code calling them references those globals.
			$globals[] = 'jQuery';
			$globals[] = '$';
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $globals ) ) ) );
	}

	/**
	 * Whether code references a global.
	 *
	 * @param string $code Code.
	 * @param string $name Global name ("jQuery", "$", "_", "wp" …).
	 */
	public static function references_global( string $code, string $name ): bool {
		if ( '' === $name || false === strpos( $code, $name ) ) {
			return false;
		}
		if ( '$' === $name ) {
			return (bool) preg_match( '/(?<![\w$])\$\s*[(.\[]/', $code );
		}
		if ( '_' === $name ) {
			return (bool) preg_match( '/(?<![\w$])_\s*[(.]/', $code );
		}
		if ( 'wp' === $name ) {
			return (bool) preg_match( '/(?<![\w$])wp\s*[.\[]/', $code );
		}
		return (bool) preg_match( '/(?<![\w$])' . preg_quote( $name, '/' ) . '(?![\w$])/', $code );
	}

	/**
	 * Normalize a script URL for duplicate detection.
	 *
	 * @param string $src URL.
	 */
	public static function normalize_src( string $src ): string {
		$src = (string) preg_replace( '#^(https?:)?//#i', '//', trim( html_entity_decode( $src, ENT_QUOTES ) ) );
		$src = (string) preg_replace( '/([?&])ver=[^&#]*(&|$)/i', '$1', $src );
		$src = rtrim( $src, '?&' );
		$pos = strpos( $src, '//' );
		if ( 0 === $pos ) {
			$slash = strpos( $src, '/', 2 );
			if ( false !== $slash ) {
				$src = strtolower( substr( $src, 0, $slash ) ) . substr( $src, $slash );
			}
		}
		return $src;
	}

	/**
	 * Run the analysis.
	 *
	 * @return array<int,string> Tag position => handle ('' for handle-less third-party scripts).
	 */
	private function compute(): array {
		if ( null !== $this->result ) {
			return $this->result;
		}

		$is_excluded    = $this->options['is_excluded'] ?? null;
		$inline_globals = (array) ( $this->options['inline_globals'] ?? array() );
		$reverse        = $this->reverse_dependencies();
		$jquery_based   = $this->jquery_based();

		// Classify main tags.
		$main      = array(); // handle => tag position (first occurrence).
		$state     = array(); // position => deferred|blocking|async|candidate.
		$externals = array(); // positions of executable external scripts.
		foreach ( $this->tags as $position => $tag ) {
			if ( empty( $tag['js'] ) || empty( $tag['src'] ) ) {
				continue;
			}
			$externals[] = $position;
			if ( ! empty( $tag['module'] ) || ! empty( $tag['defer'] ) ) {
				$state[ $position ] = 'deferred';
			} elseif ( ! empty( $tag['async'] ) ) {
				$state[ $position ] = 'async';
			} else {
				$state[ $position ] = 'blocking';
			}
			$handle = $tag['handle'];
			if ( null !== $handle && 'main' === $tag['role'] && isset( $this->scripts[ $handle ] ) && ! isset( $main[ $handle ] ) ) {
				$main[ $handle ] = $position;
			}
		}

		// Inline "after" scripts present in the document.
		$after_in_doc = array();
		foreach ( $this->tags as $tag ) {
			if ( null !== $tag['handle'] && 'after' === $tag['role'] ) {
				$after_in_doc[ $tag['handle'] ] = true;
			}
		}

		// Candidates.
		$candidates = array(); // position => handle|''.
		$globals    = array(); // position => globals[].
		foreach ( $externals as $position ) {
			if ( 'blocking' !== $state[ $position ] ) {
				continue;
			}
			$tag    = $this->tags[ $position ];
			$handle = $tag['handle'];
			$src    = (string) $tag['src'];

			if ( null !== $handle && isset( $main[ $handle ] ) && $main[ $handle ] === $position ) {
				$data = $this->scripts[ $handle ];
				if ( ! empty( $data['has_inline_after'] ) || isset( $after_in_doc[ $handle ] ) ) {
					continue;
				}
				if ( in_array( $data['strategy'] ?? '', array( 'async' ), true ) ) {
					continue;
				}
				if ( is_callable( $is_excluded ) && $is_excluded( $handle, $src ) ) {
					continue;
				}
				$candidates[ $position ] = $handle;
				$globals[ $position ]    = self::globals_for( $handle, $inline_globals, $jquery_based );
				continue;
			}

			// Hard-coded script without a known handle: only catalog defer-safe SDKs.
			$entry = ThirdPartyCatalog::match_url( $src );
			if ( null === $entry || empty( $entry['defer_safe'] ) ) {
				continue;
			}
			if ( is_callable( $is_excluded ) && $is_excluded( (string) $handle, $src ) ) {
				continue;
			}
			if ( $this->later_inline_matches_entry( $position, $entry ) ) {
				continue;
			}
			$candidates[ $position ] = '';
			$globals[ $position ]    = array();
		}

		// Rule (d): inline scripts after the candidate referencing its globals (or its name).
		foreach ( $candidates as $position => $handle ) {
			if ( ! empty( $globals[ $position ] ) && $this->later_inline_references( $position, $globals[ $position ] ) ) {
				unset( $candidates[ $position ] );
				continue;
			}
			if ( '' !== $handle && $this->later_inline_mentions( $position, self::name_hints( $handle, (string) $this->tags[ $position ]['src'] ) ) ) {
				unset( $candidates[ $position ] );
			}
		}

		$is_local = $this->options['is_local'] ?? static function ( string $src ): bool {
			return '' !== $src && '/' === $src[0] && ( ! isset( $src[1] ) || '/' !== $src[1] );
		};

		// Fixed point: dependents and (for global-defining handles) every later external script.
		do {
			$changed = false;
			foreach ( $candidates as $position => $handle ) {
				$keep = true;

				if ( '' !== $handle ) {
					foreach ( $this->transitive_dependents( $handle, $reverse ) as $dependent ) {
						if ( ! isset( $main[ $dependent ] ) ) {
							continue; // Not printed as an external script.
						}
						if ( ! $this->is_deferred( $main[ $dependent ], $state, $candidates ) ) {
							$keep = false;
							break;
						}
					}
				}

				if ( $keep ) {
					foreach ( $externals as $other ) {
						if ( $other <= $position || $this->is_deferred( $other, $state, $candidates ) ) {
							continue;
						}
						$other_src = (string) $this->tags[ $other ]['src'];
						if ( ! empty( $globals[ $position ] ) ) {
							if ( 'async' === $state[ $other ] && null !== ThirdPartyCatalog::match_url( $other_src ) ) {
								continue; // Known third-party async scripts do not use first-party globals.
							}
							$keep = false;
							break;
						}
						// Hard-coded local blocking script with unknown dependencies.
						$other_handle = $this->tags[ $other ]['handle'];
						if ( 'blocking' === $state[ $other ] && ( null === $other_handle || ! isset( $main[ $other_handle ] ) ) && null === ThirdPartyCatalog::match_url( $other_src ) && $is_local( $other_src ) ) {
							$keep = false;
							break;
						}
					}
				}

				if ( ! $keep ) {
					unset( $candidates[ $position ] );
					$changed = true;
				}
			}
		} while ( $changed );

		ksort( $candidates );
		$this->result = $candidates;
		return $this->result;
	}

	/**
	 * Whether a tag position ends up deferred.
	 *
	 * @param int               $position   Position.
	 * @param array<int,string> $state      States.
	 * @param array<int,string> $candidates Current candidates.
	 */
	private function is_deferred( int $position, array $state, array $candidates ): bool {
		return 'deferred' === ( $state[ $position ] ?? '' ) || array_key_exists( $position, $candidates );
	}

	/**
	 * Whether an executable inline script after a position references one of the globals.
	 *
	 * @param int      $position Position.
	 * @param string[] $globals  Globals.
	 */
	private function later_inline_references( int $position, array $globals ): bool {
		$count = count( $this->tags );
		for ( $i = $position + 1; $i < $count; $i++ ) {
			$tag = $this->tags[ $i ];
			if ( empty( $tag['inline'] ) || empty( $tag['js'] ) || ! empty( $tag['module'] ) || '' === trim( (string) $tag['code'] ) ) {
				continue;
			}
			foreach ( $globals as $global ) {
				if ( self::references_global( (string) $tag['code'], $global ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether an executable inline script after a position mentions one of the names
	 * (case-insensitive, at a word start).
	 *
	 * @param int      $position Position.
	 * @param string[] $names    Lower-case names.
	 */
	private function later_inline_mentions( int $position, array $names ): bool {
		if ( empty( $names ) ) {
			return false;
		}
		$pattern = '/(?<![a-z0-9_$])(?:' . implode( '|', array_map( 'preg_quote', $names ) ) . ')/i';
		$count   = count( $this->tags );
		for ( $i = $position + 1; $i < $count; $i++ ) {
			$tag = $this->tags[ $i ];
			if ( empty( $tag['inline'] ) || empty( $tag['js'] ) || ! empty( $tag['module'] ) || '' === trim( (string) $tag['code'] ) ) {
				continue;
			}
			if ( preg_match( $pattern, (string) $tag['code'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Names a script probably exposes, derived from its handle and file name
	 * ("swiper" → swiper, "jquery-slick" → slick). Generic words are ignored.
	 *
	 * @param string $handle Handle.
	 * @param string $src    URL.
	 * @return string[]
	 */
	public static function name_hints( string $handle, string $src ): array {
		$generic = array( 'script', 'scripts', 'main', 'frontend', 'front', 'public', 'bundle', 'app', 'core', 'init', 'vendor', 'vendors', 'plugin', 'plugins', 'custom', 'theme', 'common', 'site', 'global', 'index', 'jquery', 'js', 'min', 'dist', 'build', 'assets', 'block', 'blocks', 'view', 'style', 'polyfill', 'runtime', 'chunk', 'lib', 'libs', 'module', 'modules', 'admin', 'wordpress', 'utils', 'util', 'helper', 'helpers', 'functions' );
		$base    = (string) preg_replace( '/(\.min)?\.js$/i', '', basename( (string) preg_replace( '/[?#].*$/s', '', $src ) ) );
		$words   = preg_split( '/[^a-z0-9]+/', strtolower( $handle . ' ' . $base ) );
		$words   = is_array( $words ) ? $words : array();
		$names   = array();
		foreach ( $words as $word ) {
			if ( strlen( $word ) >= 4 && ! ctype_digit( $word ) && ! in_array( $word, $generic, true ) ) {
				$names[] = $word;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Whether a later inline script uses a catalog entry directly (its patterns match).
	 *
	 * @param int                 $position Position.
	 * @param array<string,mixed> $entry    Catalog entry.
	 */
	private function later_inline_matches_entry( int $position, array $entry ): bool {
		$count = count( $this->tags );
		for ( $i = $position + 1; $i < $count; $i++ ) {
			$tag = $this->tags[ $i ];
			if ( empty( $tag['inline'] ) || empty( $tag['js'] ) || '' === trim( (string) $tag['code'] ) ) {
				continue;
			}
			$match = ThirdPartyCatalog::match_inline( (string) $tag['code'] );
			if ( null !== $match && $match['id'] === $entry['id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reverse dependency map (dependency => dependents), including implicit dependencies.
	 *
	 * @return array<string,string[]>
	 */
	private function reverse_dependencies(): array {
		$reverse = array();
		foreach ( $this->scripts as $handle => $data ) {
			$deps = (array) ( $data['deps'] ?? array() );
			if ( isset( self::IMPLICIT_DEPS[ $handle ] ) ) {
				$deps = array_merge( $deps, self::IMPLICIT_DEPS[ $handle ] );
			}
			foreach ( $deps as $dep ) {
				$reverse[ (string) $dep ][] = (string) $handle;
			}
		}
		return $reverse;
	}

	/**
	 * All handles depending on a handle, directly or through other handles (aliases included).
	 *
	 * @param string                 $handle  Handle.
	 * @param array<string,string[]> $reverse Reverse map.
	 * @return string[]
	 */
	private function transitive_dependents( string $handle, array $reverse ): array {
		$seen  = array();
		$stack = $reverse[ $handle ] ?? array();
		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			if ( isset( $seen[ $current ] ) || $current === $handle ) {
				continue;
			}
			$seen[ $current ] = true;
			foreach ( $reverse[ $current ] ?? array() as $next ) {
				$stack[] = $next;
			}
		}
		return array_keys( $seen );
	}

	/**
	 * Handles that depend (transitively) on jQuery.
	 *
	 * @return array<string,bool>
	 */
	private function jquery_based(): array {
		$reverse = $this->reverse_dependencies();
		$out     = array();
		foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $root ) {
			foreach ( $this->transitive_dependents( $root, $reverse ) as $handle ) {
				$out[ $handle ] = true;
			}
		}
		return $out;
	}
}
