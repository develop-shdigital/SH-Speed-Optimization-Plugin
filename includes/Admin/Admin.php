<?php
/**
 * Admin interface: menus, assets, page shells and small admin integrations.
 *
 * The pages are rendered as a light server-side shell (headings, containers,
 * a <noscript> note). Every number is loaded by admin.js from the REST API;
 * nothing is measured or computed here.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Admin;

use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Admin pages controller.
 */
final class Admin {

	public const MENU_SLUG          = 'shso';
	public const SCRIPT_HANDLE      = 'shso-admin';
	public const STYLE_HANDLE       = 'shso-admin';
	public const HARNESS_HANDLE     = 'shso-harness';
	public const REDIRECT_TRANSIENT = 'shso_activation_redirect';
	public const REST_NAMESPACE     = 'shso/v1';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Hook suffixes of the plugin's admin pages (hook suffix => page id).
	 *
	 * @var array<string,string>
	 */
	private array $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Wire admin hooks (called on every is_admin() request).
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		// Priority 20 so the harness script (registered by the core at the default priority) exists.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_action( 'admin_notices', array( $this, 'emergency_notice' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHSO_FILE ), array( $this, 'action_links' ) );

		if ( is_multisite() ) {
			( new NetworkAdmin( $this->plugin ) )->register();
		}
	}

	/**
	 * Admin pages (page id => slug, menu label, page title).
	 *
	 * @return array<string,array{slug:string,menu:string,title:string}>
	 */
	public static function pages(): array {
		return array(
			'overview'     => array(
				'slug'  => self::MENU_SLUG,
				'menu'  => __( 'Overview', 'sh-speed-optimizer' ),
				'title' => __( 'SH Speed Optimizer', 'sh-speed-optimizer' ),
			),
			'optimization' => array(
				'slug'  => 'shso-optimization',
				'menu'  => __( 'Optimization', 'sh-speed-optimizer' ),
				'title' => __( 'Optimization', 'sh-speed-optimizer' ),
			),
			'diagnostics'  => array(
				'slug'  => 'shso-diagnostics',
				'menu'  => __( 'Diagnostics', 'sh-speed-optimizer' ),
				'title' => __( 'Diagnostics', 'sh-speed-optimizer' ),
			),
			'cache'        => array(
				'slug'  => 'shso-cache',
				'menu'  => __( 'Cache', 'sh-speed-optimizer' ),
				'title' => __( 'Cache', 'sh-speed-optimizer' ),
			),
			'settings'     => array(
				'slug'  => 'shso-settings',
				'menu'  => __( 'Settings', 'sh-speed-optimizer' ),
				'title' => __( 'Settings', 'sh-speed-optimizer' ),
			),
		);
	}

	/**
	 * Page id for an admin page slug.
	 *
	 * @param string $slug Page slug (the `page` query argument).
	 * @return string|null
	 */
	public static function page_id_for_slug( string $slug ): ?string {
		foreach ( self::pages() as $id => $page ) {
			if ( $page['slug'] === $slug ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * URL of an admin page.
	 *
	 * @param string $page_id Page id (overview, optimization, diagnostics, cache, settings).
	 */
	public static function page_url( string $page_id ): string {
		$pages = self::pages();
		$slug  = isset( $pages[ $page_id ] ) ? $pages[ $page_id ]['slug'] : self::MENU_SLUG;
		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Register the menu and submenus.
	 */
	public function add_menus(): void {
		$cap   = Capabilities::manage_cap();
		$pages = self::pages();

		$hook = add_menu_page(
			$pages['overview']['title'],
			__( 'SH Speed', 'sh-speed-optimizer' ),
			$cap,
			self::MENU_SLUG,
			function () {
				$this->render( 'overview' );
			},
			'dashicons-performance',
			80
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			$this->hooks[ $hook ] = 'overview';
		}

		foreach ( $pages as $id => $page ) {
			$title = 'overview' === $id ? $page['title'] : sprintf(
				/* translators: %s: admin page name, e.g. "Cache". */
				__( '%s ‹ SH Speed Optimizer', 'sh-speed-optimizer' ),
				$page['title']
			);

			$hook = add_submenu_page(
				self::MENU_SLUG,
				$title,
				$page['menu'],
				$cap,
				$page['slug'],
				function () use ( $id ) {
					$this->render( $id );
				}
			);
			if ( is_string( $hook ) && '' !== $hook ) {
				$this->hooks[ $hook ] = $id;
			}
		}
	}

	/**
	 * Whether a hook suffix / screen id belongs to one of the plugin's pages.
	 *
	 * @param string $hook_suffix Hook suffix or screen id.
	 */
	public function is_plugin_screen( string $hook_suffix ): bool {
		return isset( $this->hooks[ $hook_suffix ] );
	}

	/**
	 * Enqueue CSS/JS on the plugin's own pages only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( $hook_suffix ): void {
		$hook_suffix = (string) $hook_suffix;
		if ( ! $this->is_plugin_screen( $hook_suffix ) || ! Capabilities::can_manage() ) {
			return;
		}

		$page_id = $this->hooks[ $hook_suffix ];
		$version = defined( 'SHSO_VERSION' ) ? SHSO_VERSION : false;

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/admin.css', SHSO_FILE ),
			array(),
			$version
		);

		$deps    = array( 'wp-api-fetch', 'wp-i18n' );
		$harness = wp_script_is( self::HARNESS_HANDLE, 'registered' );
		if ( $harness ) {
			$deps[] = self::HARNESS_HANDLE;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/admin.js', SHSO_FILE ),
			$deps,
			$version,
			true
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.shsoAdmin = ' . wp_json_encode( $this->script_config( $page_id, $harness ) ) . ';',
			'before'
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'sh-speed-optimizer', SHSO_DIR . 'languages' );
	}

	/**
	 * Boot configuration for admin.js. Contains no measurements: the script
	 * loads all data from the REST API.
	 *
	 * @param string $page_id Current page id.
	 * @param bool   $harness Whether the browser test harness is available.
	 * @return array<string,mixed>
	 */
	private function script_config( string $page_id, bool $harness ): array {
		$urls = array();
		foreach ( array_keys( self::pages() ) as $id ) {
			$urls[ $id ] = self::page_url( $id );
		}

		return array(
			'page'          => $page_id,
			'restNamespace' => self::REST_NAMESPACE,
			'restUrl'       => esc_url_raw( rest_url( self::REST_NAMESPACE . '/' ) ),
			'adminUrls'     => $urls,
			'harness'       => $harness,
			'emergency'     => Context::is_emergency_safe_mode(),
			'multisite'     => is_multisite(),
			'version'       => defined( 'SHSO_VERSION' ) ? (string) SHSO_VERSION : '',
			'siteUrl'       => esc_url_raw( home_url( '/' ) ),
		);
	}

	/**
	 * Render a page shell.
	 *
	 * @param string $page_id Page id.
	 */
	private function render( string $page_id ): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'sh-speed-optimizer' ), '', array( 'response' => 403 ) );
		}

		$pages = self::pages();
		if ( ! isset( $pages[ $page_id ] ) ) {
			return;
		}

		$urls = array();
		foreach ( array_keys( $pages ) as $id ) {
			$urls[ $id ] = self::page_url( $id );
		}

		$view = array(
			'page'      => $page_id,
			'title'     => $pages[ $page_id ]['title'],
			'pages'     => $pages,
			'urls'      => $urls,
			'emergency' => Context::is_emergency_safe_mode(),
		);

		$template = SHSO_DIR . 'templates/admin/' . $page_id . '.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
	}

	/**
	 * Redirect once to the Overview after a single-site activation.
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}
		delete_transient( self::REDIRECT_TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the core activation screen.
		$bulk = isset( $_GET['activate-multi'] );
		if ( $bulk || wp_doing_ajax() || is_network_admin() || ! Capabilities::can_manage() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		wp_safe_redirect( self::page_url( 'overview' ) );
		exit;
	}

	/**
	 * Privacy policy suggestion (Settings → Privacy → Policy guide).
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$paragraphs = array(
			__( 'SH Speed Optimizer does not set cookies and does not collect personal data about your visitors by default.', 'sh-speed-optimizer' ),
			__( 'Real-user Core Web Vitals (optional, off by default): when the site administrator enables this feature, a small script measures how fast pages load and respond for a small random sample of visitors and sends these measurements anonymously to this website. No cookies are set and no names, email addresses, IP addresses or page addresses are stored. Only the measurement values and the type of page (for example "homepage" or "blog post") are kept, for at most 90 days. To prevent abuse, a salted one-way hash of the visitor\'s IP address is kept for up to one hour to limit the number of measurements per visitor; it cannot be turned back into the IP address.', 'sh-speed-optimizer' ),
			__( 'Google PageSpeed Insights (optional): when the site administrator connects a PageSpeed Insights API key and runs a test, the public address of the tested page is sent to Google\'s PageSpeed Insights service. No visitor data is sent. Google\'s privacy policy applies: https://policies.google.com/privacy', 'sh-speed-optimizer' ),
			__( 'Google Fonts (optional): when the site administrator allows it, Google Fonts used by the site are downloaded once and served from this website, so visitors\' browsers no longer contact Google\'s font servers for them.', 'sh-speed-optimizer' ),
		);

		$content = '';
		foreach ( $paragraphs as $paragraph ) {
			$content .= '<p class="privacy-policy-tutorial">' . esc_html( $paragraph ) . '</p>';
		}

		wp_add_privacy_policy_content( __( 'SH Speed Optimizer', 'sh-speed-optimizer' ), wp_kses_post( $content ) );
	}

	/**
	 * Global notice on non-plugin screens while the emergency constant is set.
	 */
	public function emergency_notice(): void {
		if ( ! Context::is_emergency_safe_mode() || ! Capabilities::can_manage() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $this->is_plugin_screen( (string) $screen->id ) ) {
			return; // The plugin's pages show their own banner.
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'SH Speed Optimizer:', 'sh-speed-optimizer' ),
			esc_html__( 'Emergency Safe Mode is active (SHSO_SAFE_MODE in wp-config.php). All optimizations are bypassed.', 'sh-speed-optimizer' ),
			esc_url( self::page_url( 'overview' ) ),
			esc_html__( 'Open SH Speed', 'sh-speed-optimizer' )
		);
	}

	/**
	 * Add a class to the body of the plugin's pages.
	 *
	 * @param string $classes Space separated classes.
	 */
	public function body_class( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $this->is_plugin_screen( (string) $screen->id ) ) {
			$classes .= ' shso-admin-screen';
		}
		return (string) $classes;
	}

	/**
	 * "Settings" link in the plugins list.
	 *
	 * @param array<int|string,string> $links Action links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		if ( ! Capabilities::can_manage() ) {
			return $links;
		}

		$settings = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::page_url( 'settings' ) ),
			esc_html__( 'Settings', 'sh-speed-optimizer' )
		);

		return array_merge( array( 'shso-settings' => $settings ), $links );
	}
}
