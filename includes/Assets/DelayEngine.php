<?php
/**
 * Delays scripts until the first user interaction (or an idle timeout).
 *
 * Delayable scripts are rewritten to inert placeholders that keep every
 * attribute:
 *
 *   <script src="x.js" id="a">        →  <script id="a" type="shso/delay" data-shso-src="x.js" data-shso-delay="tp">
 *   <script type="module">code</script> →  <script type="shso/delay" data-shso-type="module" data-shso-delay="all">code</script>
 *
 * A tiny inline loader (assets/js/delay.min.js) placed near the start of <head>
 * executes them strictly in document order. For queue-based libraries the
 * official stubs (gtag/dataLayer, fbq …) are defined before anything else so
 * early calls from non-delayed code are kept. Consent managers, A/B testing,
 * CAPTCHA, payment and map scripts are never touched, nor are scripts managed
 * by a consent tool (type="text/plain", data-cookieconsent …).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

use SH\SpeedOptimizer\Modules\AssetOptimization\HeadInjector;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Delay engine.
 */
final class DelayEngine {

	public const TYPE        = 'shso/delay';
	public const GROUP_THIRD = 'tp';
	public const GROUP_ALL   = 'all';
	public const LOADER_ID   = 'shso-delay-loader';
	public const STUBS_ID    = 'shso-delay-stubs';
	public const PRIORITY    = 65;

	/**
	 * Executable types that may be delayed.
	 */
	private const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript', 'module' );

	/**
	 * Attributes that opt a script out of delaying.
	 */
	private const SKIP_ATTRIBUTES = array( 'data-no-optimize', 'data-no-delay', 'data-no-defer', 'data-noptimize', 'data-pagespeed-no-defer', 'data-shso-skip', 'nowprocket', 'data-rocket-no-delay' );

	/**
	 * Attribute prefixes used by consent tools to manage scripts.
	 */
	private const CONSENT_ATTRIBUTE_PREFIXES = array( 'data-cookieconsent', 'data-cookie-consent', 'data-cookiecategory', 'data-cookie-category', 'data-cmplz', 'data-category', 'data-borlabs', 'data-usercentrics', 'data-consent', 'data-service', 'data-ot-', 'data-iub', 'data-suppressedsrc', 'data-cookieyes', 'data-cli-', 'data-cky' );

	/**
	 * First-party handles/URL fragments never delayed by "delay all".
	 */
	private const FIRST_PARTY_NEVER = array( 'jquery-core', 'jquery-migrate', '/wp-includes/js/jquery/jquery.', 'jquery-migrate.', 'stripe', 'paypal', 'braintree', 'square', 'klarna', 'mollie', 'adyen', 'amazon-pay', 'recaptcha', 'hcaptcha', 'turnstile', 'captcha', 'cookie', 'consent', 'gdpr', 'complianz', 'cmplz', 'borlabs', 'wc-checkout', 'checkout' );

	/**
	 * Inline code patterns registering DOMContentLoaded/load listeners.
	 */
	private const LATE_LISTENER_PATTERN = '/DOMContentLoaded|addEventListener\(\s*["\']load["\']|\bonload\s*=|readystatechange/';

	/**
	 * Runtimes the loader was registered with.
	 *
	 * @var array<int,bool>
	 */
	private static array $registered = array();

	/**
	 * Register the loader injection (priority 65) once per runtime.
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public static function register_loader( Runtime $runtime ): void {
		$key = spl_object_id( $runtime );
		if ( isset( self::$registered[ $key ] ) ) {
			return;
		}
		self::$registered[ $key ] = true;

		$runtime->html()->add(
			'shso_delay_loader',
			static function ( HtmlDocument $doc ) {
				self::finalize(
					$doc,
					array(
						'timeout'     => self::timeout(),
						'timeout_all' => self::timeout_all(),
					)
				);
			},
			self::PRIORITY
		);
	}

	/**
	 * Idle timeout for third-party scripts, in milliseconds.
	 */
	public static function timeout(): int {
		/**
		 * Filters how long delayed third-party scripts wait (after the page finished loading)
		 * when the visitor does not interact. 0 disables the timeout (interaction only).
		 *
		 * @param int $seconds Seconds. Default 8.
		 */
		$seconds = (float) apply_filters( 'shso_delay_timeout', 8 );
		return (int) round( max( 0.0, min( 60.0, $seconds ) ) * 1000 );
	}

	/**
	 * Timeout for scripts delayed by "delay all", in milliseconds (0 = interaction only).
	 */
	public static function timeout_all(): int {
		/**
		 * Filters how long scripts delayed by "Delay all JavaScript" wait when the visitor does
		 * not interact. 0 (default) means they only run after an interaction.
		 *
		 * @param int $seconds Seconds. Default 0.
		 */
		$seconds = (float) apply_filters( 'shso_delay_all_timeout', 0 );
		return (int) round( max( 0.0, min( 120.0, $seconds ) ) * 1000 );
	}

	/**
	 * Delay known third-party analytics/marketing/chat/social scripts and their inline snippets.
	 *
	 * @param HtmlDocument $doc         Document.
	 * @param callable     $is_excluded function( string $handle, string $src, string $code ): bool.
	 * @return array{delayed:int,ids:string[]}
	 */
	public static function delay_third_party( HtmlDocument $doc, callable $is_excluded ): array {
		$ids   = array();
		$count = $doc->replace_scripts(
			static function ( Tag $tag, string $code ) use ( $is_excluded, &$ids ) {
				if ( ! self::is_candidate( $tag ) ) {
					return null;
				}
				$handle = self::handle_from_id( $tag->get( 'id' ) );
				$src    = trim( (string) $tag->get( 'src' ) );

				if ( '' !== $src ) {
					$original = AssetRewriter::original_url( $src );
					$entry    = ThirdPartyCatalog::match_url( $original );
					if ( null === $entry || empty( $entry['delayable'] ) || $is_excluded( $handle, $original, '' ) ) {
						return null;
					}
				} else {
					if ( '' === trim( $code ) || false !== stripos( $code, 'document.write' ) ) {
						return null;
					}
					$entry = ThirdPartyCatalog::match_inline( $code );
					if ( null === $entry || empty( $entry['delayable'] ) || $is_excluded( $handle, '', $code ) ) {
						return null;
					}
				}

				$ids[ $entry['id'] ] = true;
				return self::convert( $tag, $code, self::GROUP_THIRD );
			}
		);

		return array(
			'delayed' => $count,
			'ids'     => array_keys( $ids ),
		);
	}

	/**
	 * Delay first-party scripts ("delay all", experimental).
	 *
	 * Never delayed: jQuery core, excluded scripts, catalog never-touch scripts, payment/
	 * CAPTCHA/consent/checkout scripts, third-party scripts (handled separately), scripts
	 * that non-delayed scripts depend on, and scripts whose globals non-delayed inline code
	 * uses. Inline scripts WordPress prints for a handle (-js-extra/-before/-after/
	 * -translations) move together with it.
	 *
	 * @param HtmlDocument $doc         Document.
	 * @param array        $scripts     WordPress data (ScriptGraph::capture()).
	 * @param callable     $is_excluded function( string $handle, string $src, string $code ): bool.
	 * @param callable     $is_local    function( string $src ): bool.
	 * @param array        $globals     Rules inline_globals ( handle => globals ).
	 * @return array{delayed:int,handles:string[]}
	 */
	public static function delay_first_party( HtmlDocument $doc, array $scripts, callable $is_excluded, callable $is_local, array $globals = array() ): array {
		// Pass 1: describe every script.
		$items = array();
		$doc->replace_scripts(
			static function ( Tag $tag, string $code, array $info ) use ( &$items ) {
				$item              = ScriptGraph::describe_tag( $tag, $code, (int) $info['index'], (bool) $info['in_head'] );
				$item['candidate'] = self::is_candidate( $tag );
				$items[]           = $item;
				return null;
			}
		);

		// Main tag position per handle.
		$main = array();
		foreach ( $items as $position => $item ) {
			if ( null !== $item['handle'] && 'main' === $item['role'] && ! empty( $item['src'] ) && ! isset( $main[ $item['handle'] ] ) ) {
				$main[ $item['handle'] ] = $position;
			}
		}

		// Candidates: executable local external scripts.
		$set = array(); // position => handle ('' when unknown).
		foreach ( $items as $position => $item ) {
			if ( empty( $item['candidate'] ) || empty( $item['js'] ) || empty( $item['src'] ) ) {
				continue;
			}
			$src    = AssetRewriter::original_url( (string) $item['src'] );
			$handle = (string) $item['handle'];
			if ( ! $is_local( $src ) || self::never_first_party( $handle, $src ) ) {
				continue;
			}
			$entry = ThirdPartyCatalog::match_url( $src );
			if ( null !== $entry && ! empty( $entry['never_touch'] ) ) {
				continue;
			}
			if ( $is_excluded( $handle, $src, '' ) ) {
				continue;
			}
			$set[ $position ] = ( '' !== $handle && isset( $main[ $handle ] ) && $main[ $handle ] === $position ) ? $handle : '';
		}

		// Reverse dependencies (including alias handles).
		$reverse = array();
		foreach ( $scripts as $handle => $data ) {
			foreach ( (array) ( $data['deps'] ?? array() ) as $dep ) {
				$reverse[ (string) $dep ][] = (string) $handle;
			}
		}

		do {
			$changed = false;
			$delayed = array_flip( array_filter( $set ) );
			foreach ( $set as $position => $handle ) {
				if ( '' === $handle ) {
					continue;
				}
				$keep = true;

				// Scripts that stay in place must not depend on delayed ones.
				$stack = $reverse[ $handle ] ?? array();
				$seen  = array();
				while ( $keep && ! empty( $stack ) ) {
					$dependent = array_pop( $stack );
					if ( isset( $seen[ $dependent ] ) ) {
						continue;
					}
					$seen[ $dependent ] = true;
					if ( isset( $main[ $dependent ] ) && ! isset( $delayed[ $dependent ] ) ) {
						$keep = false;
					}
					foreach ( $reverse[ $dependent ] ?? array() as $next ) {
						$stack[] = $next;
					}
				}

				// Non-delayed inline code after it must not use its globals.
				if ( $keep ) {
					$names   = ScriptGraph::globals_for( $handle, $globals );
					$pattern = ScriptGraph::name_hints( $handle, (string) $items[ $position ]['src'] );
					$count   = count( $items );
					for ( $i = $position + 1; $i < $count && $keep; $i++ ) {
						$item = $items[ $i ];
						if ( empty( $item['inline'] ) || empty( $item['js'] ) || '' === trim( (string) $item['code'] ) ) {
							continue;
						}
						if ( null !== $item['handle'] && isset( $delayed[ $item['handle'] ] ) ) {
							continue; // Moves with its (delayed) handle.
						}
						foreach ( $names as $name ) {
							if ( ScriptGraph::references_global( (string) $item['code'], $name ) ) {
								$keep = false;
								break;
							}
						}
						if ( $keep && ! empty( $pattern ) && preg_match( '/(?<![a-z0-9_$])(?:' . implode( '|', array_map( 'preg_quote', $pattern ) ) . ')/i', (string) $item['code'] ) ) {
							$keep = false;
						}
					}
				}

				if ( ! $keep ) {
					unset( $set[ $position ] );
					$changed = true;
				}
			}
		} while ( $changed );

		// Inline scripts printed for delayed handles move with them.
		$delayed_handles = array_flip( array_filter( $set ) );
		$targets         = array();
		foreach ( $items as $position => $item ) {
			if ( isset( $set[ $position ] ) ) {
				$targets[ (int) $item['index'] ] = true;
			} elseif ( ! empty( $item['inline'] ) && ! empty( $item['js'] ) && ! empty( $item['candidate'] ) && null !== $item['handle'] && isset( $delayed_handles[ $item['handle'] ] ) && in_array( $item['role'], array( 'extra', 'before', 'after', 'translations' ), true ) ) {
				$targets[ (int) $item['index'] ] = true;
			}
		}

		if ( empty( $targets ) ) {
			return array(
				'delayed' => 0,
				'handles' => array(),
			);
		}

		$count = $doc->replace_scripts(
			static function ( Tag $tag, string $code, array $info ) use ( $targets ) {
				return isset( $targets[ (int) $info['index'] ] ) ? self::convert( $tag, $code, self::GROUP_ALL ) : null;
			}
		);

		return array(
			'delayed' => $count,
			'handles' => array_keys( $delayed_handles ),
		);
	}

	/**
	 * Inject the loader (and needed stubs) once, when the page contains delayed scripts.
	 *
	 * @param HtmlDocument        $doc    Document.
	 * @param array<string,mixed> $config timeout (ms), timeout_all (ms), loader (code; default delay.min.js).
	 * @return bool Whether the loader was injected.
	 */
	public static function finalize( HtmlDocument $doc, array $config = array() ): bool {
		if ( ! $doc->contains( self::TYPE ) || $doc->contains( 'id="' . self::LOADER_ID . '"' ) ) {
			return false;
		}

		$count  = 0;
		$stubs  = array();
		$replay = false;
		$nonce  = null;

		$doc->replace_scripts(
			static function ( Tag $tag, string $code ) use ( &$count, &$stubs, &$replay, &$nonce ) {
				$tag_nonce = $tag->get( 'nonce' );
				if ( null === $nonce && null !== $tag_nonce && '' !== $tag_nonce ) {
					$nonce = $tag_nonce;
				}
				if ( self::TYPE !== strtolower( trim( (string) $tag->get( 'type' ) ) ) ) {
					return null;
				}
				++$count;
				if ( self::GROUP_ALL === $tag->get( 'data-shso-delay' ) ) {
					$replay = true;
				}
				$src = trim( (string) $tag->get( 'data-shso-src' ) );
				if ( '' !== $src ) {
					$entry = ThirdPartyCatalog::match_url( AssetRewriter::original_url( $src ) );
				} else {
					if ( preg_match( self::LATE_LISTENER_PATTERN, $code ) ) {
						$replay = true;
					}
					$entry = ThirdPartyCatalog::match_inline( $code );
					if ( null !== $entry && ! ThirdPartyCatalog::inline_loads_library( $entry, $code ) ) {
						$entry = null; // Only calls: the library itself is loaded elsewhere.
					}
				}
				if ( null !== $entry && ! empty( $entry['stub'] ) && ! empty( $entry['delayable'] ) ) {
					$stubs[ (string) $entry['stub'] ] = true;
				}
				return null;
			}
		);

		if ( 0 === $count ) {
			return false;
		}

		$loader = isset( $config['loader'] ) ? (string) $config['loader'] : self::loader_code();
		if ( '' === $loader ) {
			return false;
		}

		$nonce_attr = null !== $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';
		$markup     = '<script id="' . self::LOADER_ID . '" data-timeout="' . max( 0, (int) ( $config['timeout'] ?? 8000 ) ) . '" data-timeout-all="' . max( 0, (int) ( $config['timeout_all'] ?? 0 ) ) . '" data-replay="' . ( $replay ? '1' : '0' ) . '"' . $nonce_attr . '>' . $loader . '</script>';
		if ( ! empty( $stubs ) ) {
			$markup .= '<script id="' . self::STUBS_ID . '"' . $nonce_attr . '>' . implode( '', array_keys( $stubs ) ) . '</script>';
		}

		return HeadInjector::insert_early( $doc, $markup );
	}

	/**
	 * Rewrite one script element into a delayed placeholder (all attributes kept).
	 *
	 * @param Tag    $tag   Opening tag (modified).
	 * @param string $code  Inline code.
	 * @param string $group tp|all.
	 */
	public static function convert( Tag $tag, string $code, string $group ): string {
		$type = $tag->get( 'type' );
		if ( null !== $type && '' !== trim( $type ) ) {
			$tag->set( 'data-shso-type', trim( $type ) );
		}
		$tag->set( 'type', self::TYPE );
		$src = $tag->get( 'src' );
		if ( null !== $src ) {
			$tag->remove( 'src' );
			$tag->set( 'data-shso-src', $src );
		}
		$tag->set( 'data-shso-delay', self::GROUP_ALL === $group ? self::GROUP_ALL : self::GROUP_THIRD );
		return $tag->to_html() . $code . '</script>';
	}

	/**
	 * Whether a script element may be delayed at all (executable, not opted out, not managed
	 * by a consent tool, not ours).
	 *
	 * @param Tag $tag Opening tag.
	 */
	public static function is_candidate( Tag $tag ): bool {
		if ( ! in_array( ScriptGraph::normalized_type( $tag->get( 'type' ) ), self::JS_TYPES, true ) ) {
			return false;
		}
		if ( 0 === strpos( (string) $tag->get( 'id' ), 'shso-' ) ) {
			return false;
		}
		foreach ( self::SKIP_ATTRIBUTES as $attribute ) {
			if ( $tag->has( $attribute ) ) {
				return false;
			}
		}
		if ( 'false' === strtolower( trim( (string) $tag->get( 'data-cfasync' ) ) ) ) {
			return false;
		}
		foreach ( array_keys( $tag->attributes() ) as $name ) {
			foreach ( self::CONSENT_ATTRIBUTE_PREFIXES as $prefix ) {
				if ( 0 === strpos( (string) $name, $prefix ) ) {
					return false;
				}
			}
		}
		return ! preg_match( '/(^|\s)(cmplz|optanon-category|_iub_cs|cookieconsent|borlabs|cookielawinfo)/i', (string) $tag->get( 'class' ) );
	}

	/**
	 * Handle from a script id ("{handle}-js", "{handle}-js-after" …).
	 *
	 * @param string|null $id Id attribute.
	 */
	public static function handle_from_id( ?string $id ): string {
		if ( null !== $id && preg_match( '/^(.+)-js(?:-(?:extra|before|after|translations|module))?$/', $id, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * The minified loader code.
	 */
	public static function loader_code(): string {
		static $code = null;
		if ( null !== $code ) {
			return $code;
		}
		$dir  = defined( 'SHSO_DIR' ) ? SHSO_DIR : dirname( __DIR__, 2 ) . '/';
		$file = $dir . 'assets/js/delay.min.js';
		$code = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin file.
		if ( '' === trim( $code ) && is_readable( $dir . 'assets/js/delay.js' ) ) {
			$code = Minify\JsMinifier::minify( (string) file_get_contents( $dir . 'assets/js/delay.js' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin file.
		}
		$code = trim( str_ireplace( '</script', '<\/script', $code ) );
		return $code;
	}

	/**
	 * Forget request state (tests).
	 */
	public static function reset(): void {
		self::$registered = array();
	}

	/**
	 * Whether a first-party script must never be delayed.
	 *
	 * @param string $handle Handle.
	 * @param string $src    URL.
	 */
	private static function never_first_party( string $handle, string $src ): bool {
		$haystack = strtolower( $handle . ' ' . $src );
		foreach ( self::FIRST_PARTY_NEVER as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return in_array( $handle, array( 'jquery', 'jquery-core', 'jquery-migrate' ), true );
	}
}
