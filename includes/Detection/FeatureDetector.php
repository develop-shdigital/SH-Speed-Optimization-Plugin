<?php
/**
 * Site features (shop, forms, memberships …) and page builders.
 *
 * Plugin lists use plugin directory slugs as published on WordPress.org (or
 * by the vendor for commercial plugins). Matching is case-insensitive.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Feature and builder detection (pure functions over plugin slugs and facts).
 */
final class FeatureDetector {

	public const WOOCOMMERCE = array( 'woocommerce' );

	public const EDD = array( 'easy-digital-downloads', 'easy-digital-downloads-pro' );

	public const ACF = array( 'advanced-custom-fields', 'advanced-custom-fields-pro', 'secure-custom-fields' );

	/**
	 * Multilingual plugin id => slugs (first match wins).
	 */
	public const MULTILINGUAL = array(
		'wpml'           => array( 'sitepress-multilingual-cms' ),
		'polylang'       => array( 'polylang', 'polylang-pro' ),
		'translatepress' => array( 'translatepress-multilingual' ),
		'weglot'         => array( 'weglot' ),
		'gtranslate'     => array( 'gtranslate' ),
	);

	public const MEMBERSHIP = array(
		'memberpress',
		'paid-memberships-pro',
		'restrict-content-pro',
		'restrict-content',
		'woocommerce-memberships',
		'woocommerce-subscriptions',
		'ultimate-member',
		's2member',
		'wishlist-member',
		'wishlist-member-x',
	);

	public const FORMS = array(
		'contact-form-7',
		'gravityforms',
		'wpforms-lite',
		'wpforms',
		'ninja-forms',
		'fluentform',
		'fluentformpro',
		'formidable',
		'elementor-pro',
		'forminator',
		'everest-forms',
	);

	public const BOOKING = array(
		'ameliabooking',
		'bookly-responsive-appointment-booking-tool',
		'woocommerce-bookings',
		'simply-schedule-appointments',
		'birchschedule',
		'the-events-calendar',
		'event-tickets',
	);

	public const LMS = array(
		'sfwd-lms',
		'lifterlms',
		'tutor',
		'sensei-lms',
		'woothemes-sensei',
		'learnpress',
		'masterstudy-lms-learning-management-system',
	);

	public const SLIDERS = array(
		'revslider',
		'smart-slider-3',
		'nextend-smart-slider3-pro',
		'layerslider',
		'ml-slider',
		'soliloquy-lite',
		'soliloquy',
	);

	public const CAPTCHA = array(
		'google-captcha',
		'advanced-google-recaptcha',
		'invisible-recaptcha',
		'wpcf7-recaptcha',
		'hcaptcha-for-forms-and-more',
		'simple-cloudflare-turnstile',
		'recaptcha-woo',
	);

	public const MAPS = array(
		'wp-google-maps',
		'wp-google-maps-pro',
		'leaflet-maps-marker',
		'maps-marker-pro',
		'wp-google-map-plugin',
	);

	public const CONSENT = array(
		'cookiebot',
		'cookie-law-info',
		'webtoffee-gdpr-cookie-consent',
		'complianz-gdpr',
		'complianz-gdpr-premium',
		'borlabs-cookie',
		'real-cookie-banner',
		'real-cookie-banner-pro',
		'iubenda-cookie-law-solution',
		'gdpr-cookie-compliance',
		'cookie-notice',
		'uk-cookie-consent',
	);

	public const SEO = array(
		'wordpress-seo',
		'wordpress-seo-premium',
		'seo-by-rank-math',
		'seo-by-rank-math-pro',
		'all-in-one-seo-pack',
		'all-in-one-seo-pack-pro',
		'wp-seopress',
		'wp-seopress-pro',
	);

	public const AJAX_HEAVY = array(
		'facetwp',
		'searchwp-live-ajax-search',
		'woocommerce-products-filter',
		'yith-woocommerce-ajax-navigation',
		'yith-woocommerce-ajax-product-filter-premium',
		'filter-everything',
		'filter-everything-pro',
		'buddypress',
		'buddyboss-platform',
		'bbpress',
		'ajax-load-more',
		'catch-infinite-scroll',
	);

	/**
	 * Builder id => [ plugin slugs, version constant ].
	 */
	private const BUILDER_PLUGINS = array(
		'elementor'        => array( array( 'elementor' ), 'ELEMENTOR_VERSION' ),
		'elementor_pro'    => array( array( 'elementor-pro' ), 'ELEMENTOR_PRO_VERSION' ),
		'divi'             => array( array( 'divi-builder' ), 'ET_BUILDER_VERSION' ),
		'wpbakery'         => array( array( 'js_composer' ), 'WPB_VC_VERSION' ),
		'beaver_builder'   => array( array( 'bb-plugin', 'beaver-builder-lite-version' ), 'FL_BUILDER_VERSION' ),
		'oxygen'           => array( array( 'oxygen' ), 'CT_VERSION' ),
		'breakdance'       => array( array( 'breakdance' ), '__BREAKDANCE_VERSION' ),
		'brizy'            => array( array( 'brizy', 'brizy-pro' ), 'BRIZY_VERSION' ),
		'thrive_architect' => array( array( 'thrive-visual-editor' ), 'TVE_VERSION' ),
		'visual_composer'  => array( array( 'visualcomposer' ), 'VCV_VERSION' ),
		'siteorigin'       => array( array( 'siteorigin-panels' ), 'SITEORIGIN_PANELS_VERSION' ),
		'kadence_blocks'   => array( array( 'kadence-blocks' ), 'KADENCE_BLOCKS_VERSION' ),
		'spectra'          => array( array( 'ultimate-addons-for-gutenberg' ), 'UAGB_VER' ),
		'generateblocks'   => array( array( 'generateblocks' ), 'GENERATEBLOCKS_VERSION' ),
	);

	/**
	 * Builder id => theme templates (lowercase) that provide it.
	 */
	private const BUILDER_THEMES = array(
		'divi'             => array( 'divi', 'extra' ),
		'bricks'           => array( 'bricks' ),
		'beaver_builder'   => array( 'bb-theme' ),
		'thrive_architect' => array( 'thrive-theme' ),
	);

	/**
	 * Features of the site.
	 *
	 * @param string[] $slugs  Active plugin slugs.
	 * @param callable $lookup Fact lookup (see Facts).
	 * @return array<string,mixed>
	 */
	public static function features( array $slugs, callable $lookup ): array {
		$set = self::slug_set( $slugs );

		$multilingual = null;
		foreach ( self::MULTILINGUAL as $id => $candidates ) {
			if ( ! empty( self::matching( $set, $candidates ) ) ) {
				$multilingual = $id;
				break;
			}
		}
		if ( null === $multilingual && null !== $lookup( 'const', 'ICL_SITEPRESS_VERSION' ) ) {
			$multilingual = 'wpml';
		} elseif ( null === $multilingual && null !== $lookup( 'const', 'POLYLANG_VERSION' ) ) {
			$multilingual = 'polylang';
		}

		$captcha = self::matching( $set, self::CAPTCHA );
		if ( isset( $set['contact-form-7'] ) ) {
			$wpcf7 = $lookup( 'option', 'wpcf7' );
			if ( is_array( $wpcf7 ) && ! empty( $wpcf7['recaptcha'] ) ) {
				$captcha[] = 'cf7_recaptcha';
			}
		}

		$ajax = self::matching( $set, self::AJAX_HEAVY );

		return array(
			'woocommerce'  => ! empty( self::matching( $set, self::WOOCOMMERCE ) ) || ! empty( $lookup( 'class', 'WooCommerce' ) ),
			'edd'          => ! empty( self::matching( $set, self::EDD ) ) || ! empty( $lookup( 'class', 'Easy_Digital_Downloads' ) ),
			'acf'          => ! empty( self::matching( $set, self::ACF ) ) || ! empty( $lookup( 'class', 'ACF' ) ),
			'multilingual' => $multilingual,
			'membership'   => self::matching( $set, self::MEMBERSHIP ),
			'forms'        => self::matching( $set, self::FORMS ),
			'booking'      => self::matching( $set, self::BOOKING ),
			'lms'          => self::matching( $set, self::LMS ),
			'sliders'      => self::matching( $set, self::SLIDERS ),
			'captcha'      => $captcha,
			'maps'         => self::matching( $set, self::MAPS ),
			'consent'      => self::matching( $set, self::CONSENT ),
			'seo'          => self::matching( $set, self::SEO ),
			'ajax_heavy'   => ! empty( $ajax ),
			'ajax_plugins' => $ajax,
		);
	}

	/**
	 * Page builders: builder id => version ('' when unknown).
	 *
	 * @param array<string,array<string,mixed>> $plugins      Plugins section (slug => data).
	 * @param array<string,mixed>               $theme        Theme section.
	 * @param callable                          $lookup       Fact lookup.
	 * @param bool|null                         $block_editor Whether the block editor edits posts (null = derive from plugins).
	 * @return array<string,string>
	 */
	public static function builders( array $plugins, array $theme, callable $lookup, ?bool $block_editor = null ): array {
		$versions = array();
		foreach ( $plugins as $slug => $data ) {
			$versions[ strtolower( (string) $slug ) ] = (string) ( $data['version'] ?? '' );
		}

		$template = strtolower( (string) ( $theme['template'] ?? '' ) );
		$builders = array();

		foreach ( self::BUILDER_PLUGINS as $id => $definition ) {
			list( $slugs, $constant ) = $definition;
			$constant_value           = $lookup( 'const', $constant );
			foreach ( $slugs as $slug ) {
				if ( isset( $versions[ $slug ] ) ) {
					$builders[ $id ] = null !== $constant_value ? (string) $constant_value : $versions[ $slug ];
					break;
				}
			}
			if ( ! isset( $builders[ $id ] ) && null !== $constant_value && is_scalar( $constant_value ) ) {
				$builders[ $id ] = (string) $constant_value;
			}
		}

		foreach ( self::BUILDER_THEMES as $id => $templates ) {
			if ( ! isset( $builders[ $id ] ) && in_array( $template, $templates, true ) ) {
				$version         = ! empty( $theme['is_child'] ) ? (string) ( $theme['parent_version'] ?? '' ) : (string) ( $theme['version'] ?? '' );
				$constant        = 'bricks' === $id ? $lookup( 'const', 'BRICKS_VERSION' ) : null;
				$builders[ $id ] = null !== $constant ? (string) $constant : $version;
			}
		}

		$classic = isset( $versions['classic-editor'] ) || isset( $versions['disable-gutenberg'] );
		if ( $classic ) {
			$builders['classic_editor'] = $versions['classic-editor'] ?? ( $versions['disable-gutenberg'] ?? '' );
		}

		if ( null === $block_editor ) {
			$block_editor = true;
			if ( isset( $versions['disable-gutenberg'] ) ) {
				$block_editor = false;
			} elseif ( isset( $versions['classic-editor'] ) ) {
				$replace      = (string) $lookup( 'option', 'classic-editor-replace' );
				$allow_users  = (string) $lookup( 'option', 'classic-editor-allow-users' );
				$block_editor = 'block' === $replace || 'allow' === $allow_users;
			}
		}

		if ( $block_editor || ! empty( $theme['is_block_theme'] ) || isset( $versions['gutenberg'] ) ) {
			$gutenberg             = $lookup( 'const', 'GUTENBERG_VERSION' );
			$builders['gutenberg'] = null !== $gutenberg ? (string) $gutenberg : (string) ( $lookup( 'global', 'wp_version' ) ?? '' );
		}

		return $builders;
	}

	/**
	 * Lowercase slug set.
	 *
	 * @param string[] $slugs Slugs.
	 * @return array<string,bool>
	 */
	private static function slug_set( array $slugs ): array {
		$set = array();
		foreach ( $slugs as $slug ) {
			$set[ strtolower( (string) $slug ) ] = true;
		}
		return $set;
	}

	/**
	 * Candidates that are active.
	 *
	 * @param array<string,bool> $set        Active slug set.
	 * @param string[]           $candidates Candidate slugs.
	 * @return string[]
	 */
	private static function matching( array $set, array $candidates ): array {
		$out = array();
		foreach ( $candidates as $candidate ) {
			if ( isset( $set[ strtolower( $candidate ) ] ) ) {
				$out[] = $candidate;
			}
		}
		return $out;
	}
}
