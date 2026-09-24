<?php
/**
 * Cookie consent plugins.
 *
 * Consent banners must appear immediately and decide which other scripts may
 * run, so they are never deferred or delayed. Delaying analytics remains fine
 * as long as the consent script itself runs normally, hence no penalties.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Consent profile.
 */
final class ConsentProfile extends AbstractProfile {

	/**
	 * Plugin slug => script needles.
	 */
	public const PLUGINS = array(
		'cookiebot'                     => array( 'cookiebot', 'consent.cookiebot.com' ),
		'cookie-law-info'               => array( 'cookie-law-info', 'cookieyes' ),
		'webtoffee-gdpr-cookie-consent' => array( 'cookie-law-info', 'webtoffee' ),
		'complianz-gdpr'                => array( 'cmplz', 'complianz' ),
		'complianz-gdpr-premium'        => array( 'cmplz', 'complianz' ),
		'borlabs-cookie'                => array( 'borlabs-cookie', 'borlabs' ),
		'real-cookie-banner'            => array( 'real-cookie-banner' ),
		'real-cookie-banner-pro'        => array( 'real-cookie-banner' ),
		'iubenda-cookie-law-solution'   => array( 'iubenda' ),
		'gdpr-cookie-compliance'        => array( 'moove_gdpr', 'gdpr-cookie-compliance' ),
		'cookie-notice'                 => array( 'cookie-notice', 'cookie_notice' ),
		'uk-cookie-consent'             => array( 'uk-cookie-consent', 'ctcc' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'consent';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Cookie consent plugins', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_plugin( ...array_keys( self::PLUGINS ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		foreach ( self::PLUGINS as $slug => $needles ) {
			if ( $this->any_plugin( $slug ) ) {
				$this->protect_scripts( $rules, $needles );
			}
		}
	}
}
