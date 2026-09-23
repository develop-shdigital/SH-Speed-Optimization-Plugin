<?php
/**
 * Multisite network defaults page.
 *
 * A classic form (no JavaScript) in the network admin that sets defaults for
 * every site and optionally locks them.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Admin;

use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Network admin controller.
 */
final class NetworkAdmin {

	public const PAGE_SLUG = 'shso-network';
	public const ACTION    = 'shso_network_save';
	public const NONCE     = 'shso_network_save';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Wire hooks.
	 */
	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * Settings keys managed on the network page and their value type.
	 *
	 * @return array<string,string> key => bool|int
	 */
	public static function field_types(): array {
		return array(
			'auto_optimize'          => 'bool',
			'safe_mode'              => 'bool',
			'page_cache'             => 'bool',
			'cache_lifespan'         => 'int',
			'safe_optimizations'     => 'bool',
			'advanced_optimizations' => 'bool',
			'allow_server_config'    => 'bool',
		);
	}

	/**
	 * Labels and help texts of the fields.
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function field_labels(): array {
		return array(
			'auto_optimize'          => array(
				'label' => __( 'Automatic Optimization', 'sh-speed-optimizer' ),
				'help'  => __( 'Safe optimizations are detected and applied automatically.', 'sh-speed-optimizer' ),
			),
			'safe_mode'              => array(
				'label' => __( 'Safe Mode', 'sh-speed-optimizer' ),
				'help'  => __( 'Only the safest optimizations run. Useful while troubleshooting.', 'sh-speed-optimizer' ),
			),
			'page_cache'             => array(
				'label' => __( 'Page Cache', 'sh-speed-optimizer' ),
				'help'  => __( 'Stores ready-made copies of pages so visitors get them faster.', 'sh-speed-optimizer' ),
			),
			'cache_lifespan'         => array(
				'label' => __( 'Cache Lifespan (hours)', 'sh-speed-optimizer' ),
				'help'  => __( 'How long a cached page is kept before it is rebuilt (1–720).', 'sh-speed-optimizer' ),
			),
			'safe_optimizations'     => array(
				'label' => __( 'Safe Optimizations', 'sh-speed-optimizer' ),
				'help'  => __( 'Allow optimizations that were tested to be safe for each site.', 'sh-speed-optimizer' ),
			),
			'advanced_optimizations' => array(
				'label' => __( 'Advanced Optimizations', 'sh-speed-optimizer' ),
				'help'  => __( 'Enables experimental optimizations that are never applied automatically.', 'sh-speed-optimizer' ),
			),
			'allow_server_config'    => array(
				'label' => __( 'Allow browser caching rules in .htaccess', 'sh-speed-optimizer' ),
				'help'  => __( 'Lets SH Speed add browser caching rules to the server configuration. They can be undone anytime.', 'sh-speed-optimizer' ),
			),
		);
	}

	/**
	 * Register the network admin page.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'SH Speed Network Defaults', 'sh-speed-optimizer' ),
			__( 'SH Speed', 'sh-speed-optimizer' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-performance',
			80
		);
	}

	/**
	 * Render the form.
	 */
	public function render(): void {
		if ( ! Capabilities::can_manage_network() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'sh-speed-optimizer' ), '', array( 'response' => 403 ) );
		}

		$network = $this->plugin->settings()->network_defaults();
		$values  = array_merge( Settings::defaults(), $network['defaults'] );

		$view = array(
			'fields'     => self::field_types(),
			'labels'     => self::field_labels(),
			'values'     => $values,
			'locked'     => $network['locked'],
			'lockable'   => Settings::network_lockable(),
			'action_url' => admin_url( 'admin-post.php' ),
			'action'     => self::ACTION,
			'nonce'      => self::NONCE,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag set by our own redirect.
			'updated'    => isset( $_GET['updated'] ),
		);

		$template = SHSO_DIR . 'templates/admin/network.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
	}

	/**
	 * Handle the form submission.
	 */
	public function save(): void {
		if ( ! Capabilities::can_manage_network() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change network settings.', 'sh-speed-optimizer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is validated in parse_form().
		$input  = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$parsed = self::parse_form( is_array( $input ) ? $input : array() );

		$this->plugin->settings()->update_network_defaults( $parsed['defaults'], $parsed['locked'] );

		wp_safe_redirect( add_query_arg( 'updated', '1', network_admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Turn the submitted form into network defaults and locked keys.
	 *
	 * Expects `shso_defaults[key]` and `shso_locked[key]` fields. Unknown
	 * keys are ignored; only keys from Settings::network_lockable() can be
	 * locked.
	 *
	 * @param array<string,mixed> $input Unslashed request data.
	 * @return array{defaults: array<string,mixed>, locked: string[]}
	 */
	public static function parse_form( array $input ): array {
		$values   = isset( $input['shso_defaults'] ) && is_array( $input['shso_defaults'] ) ? $input['shso_defaults'] : array();
		$locks    = isset( $input['shso_locked'] ) && is_array( $input['shso_locked'] ) ? $input['shso_locked'] : array();
		$lockable = Settings::network_lockable();
		$factory  = Settings::defaults();

		$defaults = array();
		$locked   = array();

		foreach ( self::field_types() as $key => $type ) {
			if ( 'int' === $type ) {
				$raw              = isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? $values[ $key ] : $factory[ $key ];
				$defaults[ $key ] = max( 1, min( 720, (int) $raw ) );
			} else {
				$raw              = isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
				$defaults[ $key ] = in_array( strtolower( $raw ), array( '1', 'on', 'yes', 'true' ), true );
			}

			if ( in_array( $key, $lockable, true ) && ! empty( $locks[ $key ] ) ) {
				$locked[] = $key;
			}
		}

		return array(
			'defaults' => $defaults,
			'locked'   => $locked,
		);
	}
}
