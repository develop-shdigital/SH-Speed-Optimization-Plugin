<?php
/**
 * Rules that apply to every site.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Base compatibility rules.
 */
final class BaseRules {

	/**
	 * jQuery core: inline scripts everywhere call jQuery() immediately.
	 */
	public const JQUERY = array( 'jquery-core', 'jquery.min.js', 'jquery.js' );

	/**
	 * CAPTCHA widgets must be ready before a visitor submits a form.
	 */
	public const CAPTCHA = array( 'recaptcha', 'hcaptcha', 'turnstile', 'challenges.cloudflare.com', 'friendlycaptcha', 'captcha' );

	/**
	 * Payment SDKs: checkout buttons and fraud detection must load normally.
	 */
	public const PAYMENT = array(
		'js.stripe.com',
		'stripe',
		'paypal.com/sdk',
		'paypalobjects',
		'ppcp',
		'braintree',
		'squareup',
		'squarecdn',
		'klarna',
		'mollie',
		'adyen',
		'checkoutshopper',
		'pay.google.com',
		'apple-pay',
		'applepay',
		'payments-amazon',
		'afterpay',
		'clearpay',
		'affirm.com',
		'razorpay',
		'paystack',
		'authorize.net',
		'sezzle',
	);

	/**
	 * Consent managers: delaying them would load tracking before consent or hide the banner.
	 */
	public const CONSENT = array(
		'consent.cookiebot.com',
		'cookiebot',
		'cookieyes',
		'cookie-law-info',
		'cmplz',
		'complianz',
		'borlabs',
		'real-cookie-banner',
		'iubenda',
		'otsdkstub',
		'cdn.cookielaw.org',
		'optanon',
		'onetrust',
		'usercentrics',
		'termly',
		'cookie-notice',
		'moove_gdpr',
		'gdpr-cookie-compliance',
		'klaro',
		'osano',
		'didomi',
		'consensu.org',
		'cookiefirst',
		'cookie-script.com',
		'consentmanager',
		'axeptio',
		'tarteaucitron',
	);

	/**
	 * A/B testing tools must run before the page renders to avoid flicker and wrong variants.
	 */
	public const AB_TESTING = array(
		'optimizely',
		'visualwebsiteoptimizer',
		'_vwo_code',
		'googleoptimize',
		'google-optimize',
		'abtasty',
		'convertexperiments',
		'kameleoon',
		'omniconvert',
		'nelio-ab-testing',
	);

	/**
	 * Scripts that are not classic JavaScript or must stay exactly where they are.
	 */
	public const STRUCTURAL = array( 'wp-importmap', 'importmap', 'wp-script-module-data', 'speculationrules', '@wordpress/interactivity' );

	/**
	 * JavaScript lazy loaders: delaying them keeps images invisible until the first interaction.
	 */
	public const LAZY_LOADERS = array( 'lazysizes', 'lazyload', 'lazy-load', 'lazy_load', 'lozad' );

	/**
	 * Tracking parameters that never change page content.
	 */
	public const TRACKING_PARAMETERS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
		'utm_source_platform',
		'utm_creative_format',
		'utm_marketing_tactic',
		'gclid',
		'gclsrc',
		'gbraid',
		'wbraid',
		'dclid',
		'fbclid',
		'msclkid',
		'twclid',
		'ttclid',
		'li_fat_id',
		'igshid',
		'mc_cid',
		'mc_eid',
		'_ga',
		'_gl',
		'_ke',
		'yclid',
		'srsltid',
		'gad_source',
		'gad_campaignid',
		'epik',
		's_kwcid',
		'ef_id',
		'pk_campaign',
		'pk_kwd',
		'pk_source',
		'pk_medium',
		'pk_content',
		'mtm_campaign',
		'mtm_keyword',
		'mtm_source',
		'mtm_medium',
		'mtm_content',
		'mtm_cid',
		'mtm_group',
		'mtm_placement',
		'mtm_*',
		'_hsenc',
		'_hsmi',
		'mkt_tok',
	);

	/**
	 * Apply the base rules.
	 *
	 * @param Rules $rules Rules.
	 */
	public static function apply( Rules $rules ): void {
		$never_touch = array_merge( self::JQUERY, self::CAPTCHA, self::PAYMENT, self::CONSENT, self::AB_TESTING, self::STRUCTURAL, array( 'maps.googleapis.com', 'maps.google.com', 'document.write' ) );

		$rules->add( 'js_no_defer', $never_touch );
		$rules->add( 'js_no_delay', array_merge( $never_touch, self::LAZY_LOADERS ) );
		$rules->add( 'js_no_minify', self::STRUCTURAL );

		$rules->add(
			'inline_globals',
			array(
				'jquery-core'         => array( 'jQuery', '$' ),
				'jquery'              => array( 'jQuery', '$' ),
				'underscore'          => array( '_' ),
				'backbone'            => array( 'Backbone' ),
				'lodash'              => array( 'lodash', '_' ),
				'react'               => array( 'React' ),
				'react-dom'           => array( 'ReactDOM' ),
				'moment'              => array( 'moment' ),
				'regenerator-runtime' => array( 'regeneratorRuntime' ),
				// Wildcard entry: every wp-* package handle defines a member of the global "wp" object.
				'wp-*'                => array( 'wp.' ),
			)
		);

		$rules->add( 'cache_query_ignore', self::TRACKING_PARAMETERS );

		$rules->add( 'lazy_exclude', array( 'skip-lazy', 'no-lazy', 'data-no-lazy', 'data-skip-lazy', 'lazyload-skip', 'custom-logo', 'site-logo', 'logo' ) );

		$rules->add(
			'cache_exclude_urls',
			array(
				'/wp-admin',
				'/wp-login.php',
				'/xmlrpc.php',
				'/wp-json',
				'/wp-cron.php',
				'/wp-comments-post.php',
				'/feed/',
				'/cart/',
				'/checkout/',
				'/my-account/',
				'/add-to-cart',
				'/logout',
			)
		);

		$rules->add(
			'cache_exclude_cookies',
			array(
				'wordpress_logged_in_',
				'wp-postpass_',
				'comment_author_',
				'woocommerce_items_in_cart',
				'woocommerce_cart_hash',
				'wp_woocommerce_session_',
				'edd_items_in_cart',
			)
		);

		$rules->add( 'cache_safe_cookies', array( 'pll_language', 'wp-wpml_current_language', '_icl_current_language' ) );
	}
}
