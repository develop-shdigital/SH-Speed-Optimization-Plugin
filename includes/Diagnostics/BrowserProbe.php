<?php
/**
 * Injects the verification probe into signed verification pages.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Browser probe injector.
 */
final class BrowserProbe {

	/**
	 * Attach the probe to the HTML pipeline of this (verification) request.
	 *
	 * @param Plugin              $plugin  Plugin.
	 * @param array<string,mixed> $payload Verified token payload.
	 */
	public static function attach( Plugin $plugin, array $payload ): void {
		$config = array(
			'nonce'   => preg_replace( '/[^a-f0-9]/', '', (string) ( $payload['n'] ?? '' ) ),
			'mode'    => 'candidate' === ( $payload['m'] ?? '' ) ? 'candidate' : 'baseline',
			'purpose' => in_array( $payload['u'] ?? '', array( 'analyze', 'verify', 'critical_css' ), true ) ? $payload['u'] : 'verify',
		);

		$plugin->runtime()->html()->add(
			'probe',
			static function ( HtmlDocument $doc ) use ( $config ) {
				$file = SHSO_DIR . 'assets/js/probe.js';
				if ( ! is_readable( $file ) ) {
					return;
				}
				$config['template'] = Context::template_key();
				$code               = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$code               = str_ireplace( '</script', '<\/script', $code );
				$doc->insert_in_head(
					'<script id="shso-probe-config">window.__shsoProbeConfig=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script><script id="shso-probe">' . $code . '</script>',
					true
				);
			},
			100000 // Last, so its insertion at the start of <head> precedes everything else.
		);
	}
}
