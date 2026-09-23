<?php
/**
 * Admin toolbar menu: SH Speed → Purge cache / Safe Mode.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Admin;

use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Diagnostics\Loopback;
use SH\SpeedOptimizer\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Admin bar integration.
 */
final class AdminBar {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Add nodes.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function render( $bar ): void {
		if ( ! is_user_logged_in() || ! Capabilities::can_purge() || ! is_object( $bar ) ) {
			return;
		}

		$manage    = Capabilities::can_manage();
		$emergency = Context::is_emergency_safe_mode();
		$safe      = $emergency || (bool) $this->plugin->settings()->get( 'safe_mode' );

		$title = esc_html__( 'SH Speed', 'sh-speed-optimizer' );
		if ( $safe ) {
			$title .= ' <span style="background:#d63638;color:#fff;border-radius:9px;padding:0 6px;font-size:11px;">' . esc_html__( 'Safe Mode', 'sh-speed-optimizer' ) . '</span>';
		}

		$bar->add_node(
			array(
				'id'    => 'shso',
				'title' => $title,
				'href'  => $manage ? admin_url( 'admin.php?page=shso' ) : false,
			)
		);

		if ( $manage ) {
			$bar->add_node(
				array(
					'parent' => 'shso',
					'id'     => 'shso-dashboard',
					'title'  => esc_html__( 'Dashboard', 'sh-speed-optimizer' ),
					'href'   => admin_url( 'admin.php?page=shso' ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'shso',
				'id'     => 'shso-purge-all',
				'title'  => esc_html__( 'Purge entire cache', 'sh-speed-optimizer' ),
				'href'   => $this->action_url( 'purge_all' ),
			)
		);

		if ( ! is_admin() ) {
			$current = home_url( Context::request_path() );
			$bar->add_node(
				array(
					'parent' => 'shso',
					'id'     => 'shso-purge-page',
					'title'  => esc_html__( 'Purge this page', 'sh-speed-optimizer' ),
					'href'   => $this->action_url( 'purge_url', array( 'url' => rawurlencode( $current ) ) ),
				)
			);
		}

		if ( $manage ) {
			if ( $emergency ) {
				$bar->add_node(
					array(
						'parent' => 'shso',
						'id'     => 'shso-safe-mode',
						'title'  => esc_html__( 'Emergency Safe Mode (set in wp-config.php)', 'sh-speed-optimizer' ),
					)
				);
			} else {
				$bar->add_node(
					array(
						'parent' => 'shso',
						'id'     => 'shso-safe-mode',
						'title'  => $safe ? esc_html__( 'Safe Mode: turn off', 'sh-speed-optimizer' ) : esc_html__( 'Safe Mode: turn on', 'sh-speed-optimizer' ),
						'href'   => $this->action_url( $safe ? 'safe_mode_off' : 'safe_mode_on' ),
						'meta'   => array( 'title' => esc_attr__( 'Safe Mode keeps only functionality-neutral optimizations (caching, cleanup) and bypasses all CSS/JS/HTML transformations.', 'sh-speed-optimizer' ) ),
					)
				);
			}
		}
	}

	/**
	 * Handle an admin bar action (admin-post.php).
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below with an action-specific nonce.
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		check_admin_referer( 'shso_bar_' . $do );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url();

		switch ( $do ) {
			case 'purge_all':
				if ( ! Capabilities::can_purge() ) {
					wp_die( esc_html__( 'You are not allowed to do this.', 'sh-speed-optimizer' ), 403 );
				}
				$this->plugin->cache()->purge_all( 'admin_bar' );
				break;

			case 'purge_url':
				if ( ! Capabilities::can_purge() ) {
					wp_die( esc_html__( 'You are not allowed to do this.', 'sh-speed-optimizer' ), 403 );
				}
				$url = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( '' !== $url && Loopback::is_own_url( $url ) ) {
					$this->plugin->cache()->purge_url( $url );
					$redirect = $url;
				}
				break;

			case 'safe_mode_on':
			case 'safe_mode_off':
				if ( ! Capabilities::can_manage() || $this->plugin->settings()->is_locked( 'safe_mode' ) ) {
					wp_die( esc_html__( 'You are not allowed to do this.', 'sh-speed-optimizer' ), 403 );
				}
				$enabled = 'safe_mode_on' === $do;
				$this->plugin->settings()->update( array( 'safe_mode' => $enabled ) );
				$this->plugin->logger()->event( $enabled ? 'safe_mode_on' : 'safe_mode_off', $enabled ? __( 'Safe Mode turned on.', 'sh-speed-optimizer' ) : __( 'Safe Mode turned off.', 'sh-speed-optimizer' ) );
				break;

			default:
				wp_die( esc_html__( 'Unknown action.', 'sh-speed-optimizer' ), 400 );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Nonced action URL.
	 *
	 * @param string               $do   Action.
	 * @param array<string,string> $args Extra args.
	 */
	private function action_url( string $do, array $args = array() ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action' => 'shso_bar_action',
						'do'     => $do,
					),
					$args
				),
				admin_url( 'admin-post.php' )
			),
			'shso_bar_' . $do
		);
	}
}
