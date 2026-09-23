<?php
/**
 * WordPress core scripts and block editor (Gutenberg) compatibility.
 *
 * Script modules (Interactivity API, type="module") are never touched by the
 * JavaScript optimizations; the import map and module data scripts are
 * protected in the base rules.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Core / block editor profile.
 */
final class GutenbergProfile extends AbstractProfile {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'gutenberg';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'WordPress core scripts', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$rules->add(
			'inline_globals',
			array(
				'wp-hooks'       => array( 'wp.hooks' ),
				'wp-i18n'        => array( 'wp.i18n' ),
				'wp-a11y'        => array( 'wp.a11y' ),
				'wp-dom-ready'   => array( 'wp.domReady' ),
				'wp-api-fetch'   => array( 'wp.apiFetch' ),
				'wp-url'         => array( 'wp.url' ),
				'wp-element'     => array( 'wp.element' ),
				'wp-data'        => array( 'wp.data' ),
				'wp-util'        => array( 'wp.template', 'wp.ajax' ),
				'wp-backbone'    => array( 'wp.Backbone' ),
				'wp-escape-html' => array( 'wp.escapeHtml' ),
				'wp-polyfill'    => array( 'wp.polyfill' ),
				'mediaelement-core' => array( 'mejs', 'MediaElementPlayer' ),
				'wp-mediaelement'   => array( 'wp.mediaelement' ),
			)
		);
	}
}
