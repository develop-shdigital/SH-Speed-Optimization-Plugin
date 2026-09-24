<?php
/**
 * Slider plugins (Slider Revolution, Smart Slider 3, LayerSlider, MetaSlider, Soliloquy).
 *
 * Sliders are usually above the fold: their scripts must not wait for an
 * interaction, and their slide backgrounds are managed by the slider itself.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Sliders profile.
 */
final class SlidersProfile extends AbstractProfile {

	/**
	 * Id => [ plugin slugs, script needles, lazy-load exclusions ].
	 */
	public const SLIDERS = array(
		'revslider'    => array( array( 'revslider' ), array( 'revmin', 'tp-tools', 'revslider', 'rs6', 'sr7', 'rbtools' ), array( 'rev-slidebg', 'rs-lazyload', 'tp-rs-img' ) ),
		'smart_slider' => array( array( 'smart-slider-3', 'nextend-smart-slider3-pro' ), array( 'smartslider', 'n2-ss-', 'n2.min.js', 'nextend' ), array( 'n2-ss-slide-background', 'n2-ss-slide-background-image' ) ),
		'layerslider'  => array( array( 'layerslider' ), array( 'layerslider', 'greensock' ), array( 'ls-bg' ) ),
		'metaslider'   => array( array( 'ml-slider', 'ml-slider-pro' ), array( 'metaslider', 'ml-slider' ), array( 'msDefaultImage' ) ),
		'soliloquy'    => array( array( 'soliloquy-lite', 'soliloquy' ), array( 'soliloquy' ), array( 'soliloquy-image' ) ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'sliders';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Slider plugins', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		foreach ( self::SLIDERS as $definition ) {
			if ( $this->any_plugin( ...$definition[0] ) ) {
				return true;
			}
		}
		return $this->any_defined( 'RS_REVISION', 'LS_PLUGIN_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		foreach ( self::SLIDERS as $id => $definition ) {
			$active = $this->any_plugin( ...$definition[0] )
				|| ( 'revslider' === $id && $this->any_defined( 'RS_REVISION' ) )
				|| ( 'layerslider' === $id && $this->any_defined( 'LS_PLUGIN_VERSION' ) );
			if ( ! $active ) {
				continue;
			}
			$this->protect_scripts( $rules, $definition[1], false, true );
			$rules->add( 'lazy_exclude', $definition[2] );
		}
		$rules->penalize( 'js_defer', 10, __( 'Sliders are often the first thing visitors see and depend on their scripts starting early.', 'sh-speed-optimizer' ) );
	}
}
