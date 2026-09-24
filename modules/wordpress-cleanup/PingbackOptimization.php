<?php
/**
 * Stop self pingbacks and the unused X-Pingback header.
 *
 * WordPress pings every linked URL when a post is published, including the
 * site's own posts, which creates pointless "pingback" comments and requests
 * to itself. The X-Pingback header is removed only when pingbacks are closed
 * for new posts. XML-RPC itself stays available (Jetpack and the mobile apps
 * use it).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\WordpressCleanup;

use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Self pingbacks.
 */
class PingbackOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'disable_self_pingbacks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Stop self pingbacks', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Stops WordPress from notifying your own site when you link to your own posts, which avoids unnecessary requests and "pingback" comments.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CLEANUP;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::SAFE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_SAFE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function safe_mode_compatible(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$assessment = Assessment::make( true, 99, Assessment::BENEFIT_LOW );
		$assessment->note( __( 'Pingbacks from other websites and XML-RPC keep working.', 'sh-speed-optimizer' ) );
		return $this->finalize( $assessment, $context );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		// Pings are sent from cron, the editor and REST requests: filter them in every context.
		add_action( 'pre_ping', array( $this, 'filter_pre_ping' ), 10, 1 );

		if ( $this->plugin->context()->is_frontend_request() ) {
			add_filter( 'wp_headers', array( $this, 'filter_headers' ) );
		}
	}

	/**
	 * `pre_ping` callback: the links array is passed by reference.
	 *
	 * @param array<int,string> $links Links to ping.
	 */
	public function filter_pre_ping( &$links ): void {
		if ( is_array( $links ) ) {
			$links = self::remove_self_links( $links, (string) home_url() );
		}
	}

	/**
	 * `wp_headers` callback.
	 *
	 * @param mixed $headers Headers.
	 * @return mixed
	 */
	public function filter_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}
		return self::strip_pingback_header( $headers, (string) get_option( 'default_ping_status', 'open' ) );
	}

	/**
	 * Remove links pointing to this site (any scheme, "www." variant included); external links stay.
	 *
	 * @param array<int,string> $links Links.
	 * @param string            $home  Home URL.
	 * @return array<int,string>
	 */
	public static function remove_self_links( array $links, string $home ): array {
		$own = self::normalize( $home );
		if ( '' === $own ) {
			return array_values( $links );
		}

		$out = array();
		foreach ( $links as $link ) {
			$candidate = self::normalize( (string) $link );
			$is_own    = 0 === strpos( $candidate, $own )
				&& ( strlen( $candidate ) === strlen( $own ) || in_array( $candidate[ strlen( $own ) ], array( '/', '?', '#' ), true ) );
			if ( ! $is_own ) {
				$out[] = $link;
			}
		}
		return $out;
	}

	/**
	 * Remove the X-Pingback header when pingbacks are closed for new posts.
	 *
	 * @param array<string,string> $headers             Headers.
	 * @param string               $default_ping_status Option `default_ping_status` (open|closed).
	 * @return array<string,string>
	 */
	public static function strip_pingback_header( array $headers, string $default_ping_status ): array {
		if ( 'closed' !== $default_ping_status ) {
			return $headers;
		}
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'x-pingback' === strtolower( (string) $name ) ) {
				unset( $headers[ $name ] );
			}
		}
		return $headers;
	}

	/**
	 * Scheme-less, lowercase host + path without trailing slash, "www." removed.
	 *
	 * @param string $url URL.
	 */
	private static function normalize( string $url ): string {
		$url = strtolower( trim( $url ) );
		$url = (string) preg_replace( '#^(?:https?:)?//#', '', $url );
		$url = (string) preg_replace( '#^www\.#', '', $url );
		return rtrim( $url, '/' );
	}
}
