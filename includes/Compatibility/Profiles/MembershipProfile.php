<?php
/**
 * Membership and LMS plugins.
 *
 * Logged-in members always bypass the page cache, which covers restricted
 * content, course progress and dashboards. This profile additionally keeps
 * the login, registration, account and checkout pages of these plugins out
 * of the cache for anonymous visitors (nonces, redirects, error messages).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Membership / LMS profile.
 */
final class MembershipProfile extends AbstractProfile {

	/**
	 * Plugin slugs covered.
	 */
	public const PLUGINS = array(
		'memberpress',
		'paid-memberships-pro',
		'restrict-content-pro',
		'restrict-content',
		'ultimate-member',
		's2member',
		'wishlist-member',
		'wishlist-member-x',
		'sfwd-lms',
		'lifterlms',
		'tutor',
		'sensei-lms',
		'woothemes-sensei',
		'learnpress',
		'masterstudy-lms-learning-management-system',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'membership';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Membership and course plugins', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_plugin( ...self::PLUGINS ) || $this->any_defined( 'MEPR_VERSION', 'PMPRO_VERSION', 'LEARNDASH_VERSION', 'LLMS_VERSION', 'TUTOR_VERSION', 'LEARNPRESS_VERSION', 'ULTIMATEMEMBER_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$ids = array();

		// MemberPress.
		$mepr = $this->env->option( 'mepr_options', array() );
		if ( is_array( $mepr ) ) {
			foreach ( array( 'account_page_id', 'login_page_id', 'thankyou_page_id' ) as $key ) {
				$ids[] = (int) ( $mepr[ $key ] ?? 0 );
			}
		}

		// Paid Memberships Pro.
		foreach ( array( 'account', 'login', 'checkout', 'confirmation', 'billing', 'cancel', 'member_profile_edit' ) as $page ) {
			$ids[] = (int) $this->env->option( 'pmpro_' . $page . '_page_id', 0 );
		}

		// Restrict Content Pro.
		$rcp = $this->env->option( 'rcp_settings', array() );
		if ( is_array( $rcp ) ) {
			foreach ( array( 'account_page', 'registration_page', 'redirect', 'edit_profile', 'update_card' ) as $key ) {
				$ids[] = (int) ( $rcp[ $key ] ?? 0 );
			}
		}

		// Ultimate Member.
		$um = $this->env->option( 'um_options', array() );
		if ( is_array( $um ) ) {
			foreach ( array( 'core_account', 'core_login', 'core_register', 'core_logout', 'core_password-reset' ) as $key ) {
				$ids[] = (int) ( $um[ $key ] ?? 0 );
			}
		}

		// LifterLMS, LearnPress.
		foreach ( array( 'lifterlms_myaccount_page_id', 'lifterlms_checkout_page_id', 'learn_press_profile_page_id', 'learn_press_checkout_page_id' ) as $option ) {
			$ids[] = (int) $this->env->option( $option, 0 );
		}

		// Tutor LMS.
		$tutor = $this->env->option( 'tutor_option', array() );
		if ( is_array( $tutor ) ) {
			foreach ( array( 'tutor_dashboard_page_id', 'student_register_page', 'instructor_register_page' ) as $key ) {
				$ids[] = (int) ( $tutor[ $key ] ?? 0 );
			}
		}

		$urls = array();
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$urls = array_merge( $urls, $this->page_patterns( $id ) );
		}
		$rules->add( 'cache_exclude_urls', $urls );

		$rules->penalize( 'page_cache', 5, __( 'Membership sites show different content to members; logged-in visitors always bypass the cache.', 'sh-speed-optimizer' ) );
	}
}
