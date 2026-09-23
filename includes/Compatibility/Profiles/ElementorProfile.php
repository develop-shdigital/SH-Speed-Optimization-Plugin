<?php
/**
 * Elementor compatibility.
 *
 * Elementor's frontend is a webpack runtime plus handlers that initialise every
 * widget on DOMContentLoaded/`elementor/frontend/init`. Running those scripts
 * out of order, or only after the first interaction, leaves sliders, tabs,
 * accordions, entrance animations and popups broken or invisible.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor profile.
 */
final class ElementorProfile extends AbstractProfile {

	/**
	 * Elementor core frontend handles and asset paths.
	 */
	public const SCRIPTS = array(
		'elementor-frontend',
		'elementor-webpack-runtime',
		'elementor-frontend-modules',
		'elementor-waypoints',
		'elementor-dialog',
		'swiper',
		'share-link',
		'e-sticky',
		'plugins/elementor/assets/js/',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'ELEMENTOR_VERSION' ) || $this->env->class_exists( '\Elementor\Plugin' ) || $this->any_plugin( 'elementor' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );

		$rules->add(
			'inline_globals',
			array(
				'elementor-frontend'         => array( 'elementorFrontend' ),
				'elementor-frontend-modules' => array( 'elementorModules' ),
				'swiper'                     => array( 'Swiper' ),
			)
		);

		// Carousel images managed by Swiper's own lazy loading.
		$rules->add( 'lazy_exclude', array( 'swiper-lazy' ) );

		// Elementor's video widget builds its player with JavaScript; only raw iframes get facades.
		$rules->add( 'facade_exclude', array( 'elementor-video', 'elementor-background-video' ) );

		$rules->penalize( 'js_defer', 15, __( 'Elementor widgets rely on their scripts running in the original order.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'js_delay_third_party', 5, __( 'Elementor pages often embed third-party widgets that need to start on page load.', 'sh-speed-optimizer' ) );
		$rules->disable( 'js_delay_all', __( 'Elementor widgets initialise on page load; delaying all scripts would leave sliders, menus and animations broken until the visitor interacts.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'critical_css', 30, __( 'Elementor generates page-specific CSS, which makes critical CSS unreliable.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'video_facade', 5, __( 'Elementor video widgets use their own player; only plain embedded videos are replaced.', 'sh-speed-optimizer' ) );
	}
}
