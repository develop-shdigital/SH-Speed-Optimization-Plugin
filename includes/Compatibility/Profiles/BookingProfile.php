<?php
/**
 * Booking and appointment plugins (Amelia, Bookly, Simply Schedule Appointments, WooCommerce Bookings).
 *
 * Booking widgets are JavaScript applications that load availability on page
 * load; delaying or reordering them leaves an empty booking form.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Booking profile.
 */
final class BookingProfile extends AbstractProfile {

	/**
	 * Plugin slug => [ name, script needles ].
	 */
	public const PLUGINS = array(
		'ameliabooking'                              => array( 'Amelia', array( 'amelia' ) ),
		'bookly-responsive-appointment-booking-tool' => array( 'Bookly', array( 'bookly' ) ),
		'simply-schedule-appointments'               => array( 'Simply Schedule Appointments', array( 'ssa-booking', 'ssa_', 'simply-schedule-appointments' ) ),
		'woocommerce-bookings'                       => array( 'WooCommerce Bookings', array( 'wc-bookings', 'woocommerce-bookings' ) ),
		'birchschedule'                              => array( 'BirchPress', array( 'birchschedule', 'birchpress' ) ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'booking';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Booking plugins', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_plugin( ...array_keys( self::PLUGINS ) ) || $this->any_defined( 'AMELIA_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		foreach ( self::PLUGINS as $slug => $definition ) {
			if ( $this->any_plugin( $slug ) || ( 'ameliabooking' === $slug && $this->any_defined( 'AMELIA_VERSION' ) ) ) {
				$this->protect_scripts( $rules, $definition[1] );
			}
		}
		$rules->penalize( 'js_defer', 10, __( 'Booking forms are JavaScript applications that load availability when the page opens.', 'sh-speed-optimizer' ) );
	}
}
