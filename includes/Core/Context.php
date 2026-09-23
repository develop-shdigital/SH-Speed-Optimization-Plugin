<?php
/**
 * Request context.
 *
 * Answers "what kind of request is this and may we optimize it?" in one place
 * so that every module applies the same rules.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

use SH\SpeedOptimizer\Security\Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Request context service.
 */
final class Context {

	public const VERIFY_PARAM = 'shso_verify';

	/**
	 * Parsed verification token payload (false = not parsed yet).
	 *
	 * @var array<string,mixed>|null|false
	 */
	private $verification = false;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Emergency safe mode: `define( 'SHSO_SAFE_MODE', true );` in wp-config.php.
	 * Bypasses every transformation and the page cache.
	 */
	public static function is_emergency_safe_mode(): bool {
		return defined( 'SHSO_SAFE_MODE' ) && SHSO_SAFE_MODE;
	}

	/**
	 * Safe mode (toggle or emergency constant).
	 */
	public function is_safe_mode(): bool {
		return self::is_emergency_safe_mode() || (bool) $this->settings->get( 'safe_mode' );
	}

	/**
	 * Development mode: `define( 'SHSO_DEBUG', true );` or the debug setting.
	 */
	public function is_debug(): bool {
		return ( defined( 'SHSO_DEBUG' ) && SHSO_DEBUG ) || (bool) $this->settings->get( 'debug' );
	}

	/**
	 * Whether this is a WP-CLI request.
	 */
	public static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Whether this is a REST request. Works before `parse_request` too.
	 */
	public static function is_rest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return false !== strpos( $uri, '/' . $prefix . '/' ) || str_ends_with( strtok( $uri, '?' ) ?: '', '/' . $prefix );
	}

	/**
	 * Whether the request is a regular frontend page view that may be optimized.
	 */
	public function is_frontend_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || self::is_cli() || self::is_rest() ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) || defined( 'IFRAME_REQUEST' ) || defined( 'WP_INSTALLING' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
		if ( in_array( $script, array( 'wp-login.php', 'wp-signup.php', 'wp-activate.php', 'wp-trackback.php', 'xmlrpc.php', 'wp-cron.php' ), true ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Whether a page builder, customizer or preview is rendering the page.
	 * Optimizations never run there: editors need the unmodified markup.
	 */
	public function is_editor_preview(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$params = array(
			'elementor-preview',
			'elementor_library',
			'et_fb',
			'et_pb_preview',
			'fl_builder',
			'fl_builder_preview',
			'ct_builder',
			'oxygen_iframe',
			'bricks',
			'brizy-edit',
			'brizy-edit-iframe',
			'vc_editable',
			'vc_action',
			'tve',
			'tb-preview',
			'fb-edit',
			'customize_changeset_uuid',
			'customize_theme',
			'preview',
			'preview_id',
			'wp_customize',
			'siteorigin_panels_live_editor',
			'op3editor',
			'zionbuilder-preview',
			'cornerstone_preview',
			'mailpoet_router',
			'uxb_iframe',
			'spectra_preview',
			'kubio-preview',
		);
		foreach ( $params as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return true;
			}
		}
		// phpcs:enable

		if ( function_exists( 'is_customize_preview' ) && did_action( 'setup_theme' ) && is_customize_preview() ) {
			return true;
		}
		if ( did_action( 'wp' ) && ( is_preview() || ( function_exists( 'is_embed' ) && is_embed() ) ) ) {
			return true;
		}

		/**
		 * Filters whether the current request is an editor/preview request.
		 *
		 * @param bool $is_preview Default false.
		 */
		return (bool) apply_filters( 'shso_is_editor_preview', false );
	}

	/**
	 * Verified token payload of a verification/analysis request, or null.
	 *
	 * Payload keys: m (baseline|candidate), o (optimization ids for candidate),
	 * p (1 = inject browser probe), a (1 = server analysis), j (job id).
	 *
	 * @return array<string,mixed>|null
	 */
	public function verification(): ?array {
		if ( false !== $this->verification ) {
			return $this->verification;
		}

		$this->verification = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by HMAC signature instead of a nonce.
		if ( isset( $_GET[ self::VERIFY_PARAM ] ) && is_string( $_GET[ self::VERIFY_PARAM ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$payload = Signer::verify( preg_replace( '/[^A-Za-z0-9\-_\.]/', '', (string) $_GET[ self::VERIFY_PARAM ] ) );
			if ( null !== $payload && in_array( $payload['m'] ?? '', array( 'baseline', 'candidate' ), true ) ) {
				$payload['o']       = array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['o'] ?? array() ) ) ) );
				$this->verification = $payload;
			}
		}

		return $this->verification;
	}

	/**
	 * Whether the current request is a signed verification request.
	 */
	public function is_verification(): bool {
		return null !== $this->verification();
	}

	/**
	 * Template key of the current frontend request (valid after the main query ran).
	 *
	 * Examples: front_page, home, page, single-post, single-product, archive-product,
	 * tax-product_cat, category, tag, author, date, search, 404, archive-{post_type}.
	 */
	public static function template_key(): string {
		if ( ! did_action( 'wp' ) ) {
			return 'unknown';
		}
		if ( is_front_page() ) {
			return 'front_page';
		}
		if ( is_home() ) {
			return 'home';
		}
		if ( is_404() ) {
			return '404';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'archive-product';
		}
		if ( is_singular() ) {
			$type = get_post_type();
			return 'page' === $type ? 'page' : 'single-' . sanitize_key( (string) $type );
		}
		if ( is_category() ) {
			return 'category';
		}
		if ( is_tag() ) {
			return 'tag';
		}
		if ( is_tax() ) {
			$term = get_queried_object();
			return 'tax-' . sanitize_key( $term->taxonomy ?? 'term' );
		}
		if ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			return 'archive-' . sanitize_key( is_array( $type ) ? (string) reset( $type ) : (string) $type );
		}
		if ( is_author() ) {
			return 'author';
		}
		if ( is_date() ) {
			return 'date';
		}
		return 'archive';
	}

	/**
	 * Path of the current request (no query string), e.g. "/shop/".
	 */
	public static function request_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return '' === $path ? '/' : $path;
	}

	/**
	 * Whether the current URL matches one of the user's URL exclusions.
	 *
	 * Patterns: plain substrings ("/checkout/"), wildcards ("/landing/*") or
	 * regular expressions wrapped in "#…#".
	 *
	 * @param string[] $patterns Patterns.
	 * @param string   $url      URL or path to test.
	 */
	public static function url_matches( array $patterns, string $url ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( strlen( $pattern ) > 2 && '#' === $pattern[0] && '#' === substr( $pattern, -1 ) ) {
				if ( @preg_match( $pattern, $url ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- User supplied regex may be invalid.
					return true;
				}
				continue;
			}
			if ( false !== strpos( $pattern, '*' ) ) {
				$regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
				if ( preg_match( $regex, $url ) ) {
					return true;
				}
				continue;
			}
			if ( false !== stripos( $url, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
