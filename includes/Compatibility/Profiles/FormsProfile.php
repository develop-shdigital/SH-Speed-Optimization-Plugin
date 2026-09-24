<?php
/**
 * Form plugins.
 *
 * Forms must be ready before a visitor types: validation, conditional logic,
 * multi-step navigation and spam protection run in the form scripts. They are
 * never delayed until interaction; deferring is left to the dependency-aware
 * defer optimization with a small confidence penalty per form plugin.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Forms profile.
 */
final class FormsProfile extends AbstractProfile {

	/**
	 * Plugin slug => [ name, detection constants, script needles ].
	 */
	public const PLUGINS = array(
		'contact-form-7' => array( 'Contact Form 7', array( 'WPCF7_VERSION' ), array( 'contact-form-7', 'wpcf7', 'swv' ) ),
		'gravityforms'   => array( 'Gravity Forms', array( 'GF_MIN_WP_VERSION', 'RG_CURRENT_PAGE' ), array( 'gform_', 'gforms_', 'gravityforms' ) ),
		'wpforms'        => array( 'WPForms', array( 'WPFORMS_VERSION' ), array( 'wpforms' ) ),
		'ninja-forms'    => array( 'Ninja Forms', array(), array( 'nf-front-end', 'ninja-forms', 'backbone', 'underscore' ) ),
		'fluentform'     => array( 'Fluent Forms', array( 'FLUENTFORM_VERSION' ), array( 'fluent-form', 'fluentform' ) ),
		'formidable'     => array( 'Formidable Forms', array(), array( 'formidable', 'frm_' ) ),
		'forminator'     => array( 'Forminator', array( 'FORMINATOR_VERSION' ), array( 'forminator' ) ),
		'everest-forms'  => array( 'Everest Forms', array( 'EVF_VERSION' ), array( 'everest-forms', 'everest_forms' ) ),
	);

	/**
	 * Additional plugin slugs per entry (lite/pro editions).
	 */
	private const ALIASES = array(
		'wpforms'    => array( 'wpforms-lite' ),
		'fluentform' => array( 'fluentformpro' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		$names = array();
		foreach ( $this->active() as $slug ) {
			$names[] = self::PLUGINS[ $slug ][0];
		}
		/* translators: %s: comma-separated list of form plugins */
		return empty( $names ) ? __( 'Form plugins', 'sh-speed-optimizer' ) : sprintf( __( 'Forms (%s)', 'sh-speed-optimizer' ), implode( ', ', $names ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return ! empty( $this->active() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		foreach ( $this->active() as $slug ) {
			list( $name, , $needles ) = self::PLUGINS[ $slug ];
			$this->protect_scripts( $rules, $needles, false, true );
			/* translators: %s: form plugin name */
			$rules->penalize( 'js_defer', 5, sprintf( __( '%s forms rely on their scripts for validation and submission.', 'sh-speed-optimizer' ), $name ) );
		}
	}

	/**
	 * Active form plugins.
	 *
	 * @return string[]
	 */
	private function active(): array {
		$active = array();
		foreach ( self::PLUGINS as $slug => $definition ) {
			$slugs = array_merge( array( $slug ), self::ALIASES[ $slug ] ?? array() );
			if ( $this->any_plugin( ...$slugs ) || $this->any_defined( ...$definition[1] ) ) {
				$active[] = $slug;
			}
		}
		return $active;
	}
}
