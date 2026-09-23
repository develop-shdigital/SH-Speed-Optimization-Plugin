<?php
/**
 * Jetpack compatibility.
 *
 * Statistics (stats.wp.com) may be delayed like any analytics script. Gallery
 * layouts and Jetpack's own lazy images must not wait for an interaction.
 * Overlapping optimizations (image CDN, lazy images) are handled by the
 * conflict catalog.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Jetpack profile.
 */
final class JetpackProfile extends AbstractProfile {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'jetpack';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Jetpack';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'JETPACK__VERSION' ) || $this->any_plugin( 'jetpack' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, array( 'jetpack-lazy-images', 'tiled-gallery', 'jetpack-carousel', 'jetpack-slideshow' ), false, true );
	}
}
