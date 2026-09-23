<?php
/**
 * Catalog of well-known third-party scripts.
 *
 * Pure data plus fast matching; used by the delay engine, the defer analysis
 * and the scanner. Entries flagged `never_touch` (consent managers, A/B testing
 * and anti-flicker snippets, CAPTCHAs, payment SDKs, maps) are listed first so
 * they always win when a snippet matches several entries.
 *
 * Entry keys:
 *  - id, name, category (analytics|tag_manager|ads|social|video|maps|chat|captcha|payment|
 *    consent|ab_testing|heatmap|reviews|other)
 *  - url[]:    lower-case host/path fragments matched against script URLs
 *  - inline[]: lower-case code fragments matched against inline scripts (whitespace
 *              around punctuation ignored; fragments starting with a letter must start a word)
 *  - delayable:   may be delayed until the first interaction,
 *  - defer_safe:  loads asynchronously by design and may be deferred when hard-coded,
 *  - never_touch: must never be delayed, deferred or otherwise changed,
 *  - stub:        official queue stub defined before delayed scripts so early calls are kept.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Third-party script catalog.
 */
final class ThirdPartyCatalog {

	/**
	 * Maximum inline code length inspected (larger inline scripts are data, not snippets).
	 */
	private const INLINE_LIMIT = 200000;

	/**
	 * Normalized entries (built once).
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private static ?array $entries = null;

	/**
	 * All entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		if ( null === self::$entries ) {
			$entries = array();
			foreach ( self::definitions() as $definition ) {
				$entry           = array_merge(
					array(
						'url'         => array(),
						'inline'      => array(),
						'delayable'   => false,
						'defer_safe'  => false,
						'never_touch' => false,
						'stub'        => null,
					),
					$definition
				);
				$entry['url']    = array_map( 'strtolower', $entry['url'] );
				$entry['inline'] = array_map( array( self::class, 'normalize_code' ), $entry['inline'] );
				if ( $entry['never_touch'] ) {
					$entry['delayable']  = false;
					$entry['defer_safe'] = false;
				}
				$entries[] = $entry;
			}
			// Never-touch entries first so they win over generic matches.
			usort(
				$entries,
				static function ( $a, $b ) {
					return (int) $b['never_touch'] <=> (int) $a['never_touch'];
				}
			);
			self::$entries = $entries;
		}
		return self::$entries;
	}

	/**
	 * Entry by id.
	 *
	 * @param string $id Entry id.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Match a script URL.
	 *
	 * @param string $url Script URL (absolute, protocol-relative or relative).
	 * @return array<string,mixed>|null
	 */
	public static function match_url( string $url ): ?array {
		$url = strtolower( trim( $url ) );
		if ( '' === $url ) {
			return null;
		}
		$url = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*:#', '', $url );

		foreach ( self::all() as $entry ) {
			foreach ( $entry['url'] as $fragment ) {
				if ( '' !== $fragment && false !== strpos( $url, $fragment ) ) {
					return $entry;
				}
			}
		}
		return null;
	}

	/**
	 * Match inline script code.
	 *
	 * @param string $code Inline code.
	 * @return array<string,mixed>|null
	 */
	public static function match_inline( string $code ): ?array {
		if ( '' === trim( $code ) ) {
			return null;
		}
		$normalized = self::normalize_code( substr( $code, 0, self::INLINE_LIMIT ) );

		foreach ( self::all() as $entry ) {
			foreach ( $entry['inline'] as $fragment ) {
				if ( self::contains_token( $normalized, $fragment ) ) {
					return $entry;
				}
			}
			// Loader snippets contain the library URL.
			foreach ( $entry['url'] as $fragment ) {
				if ( strlen( $fragment ) >= 8 && false !== strpos( $normalized, $fragment ) ) {
					return $entry;
				}
			}
		}
		return null;
	}

	/**
	 * Whether inline code loads the entry's library (contains one of its URL fragments).
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @param string              $code  Inline code.
	 */
	public static function inline_loads_library( array $entry, string $code ): bool {
		$normalized = strtolower( substr( $code, 0, self::INLINE_LIMIT ) );
		foreach ( (array) $entry['url'] as $fragment ) {
			if ( strlen( (string) $fragment ) >= 8 && false !== strpos( $normalized, (string) $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize code for matching: lower-case, no whitespace around punctuation.
	 *
	 * @param string $code Code.
	 */
	public static function normalize_code( string $code ): string {
		$code = strtolower( $code );
		$code = (string) preg_replace( '/\s*([^\w\s$])\s*/', '$1', $code );
		return (string) preg_replace( '/\s+/', ' ', $code );
	}

	/**
	 * Substring search where fragments starting with an identifier character must start a word.
	 *
	 * @param string $haystack Normalized code.
	 * @param string $needle   Normalized fragment.
	 */
	private static function contains_token( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}
		$word   = (bool) preg_match( '/^[\w$]/', $needle );
		$offset = 0;
		while ( false !== ( $pos = strpos( $haystack, $needle, $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! $word || 0 === $pos || ! preg_match( '/[\w$]/', $haystack[ $pos - 1 ] ) ) {
				return true;
			}
			$offset = $pos + 1;
		}
		return false;
	}

	/**
	 * Raw definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function definitions(): array {
		$gtag_stub  = 'window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments)};';
		$fbq_stub   = '!function(f,d){if(f.fbq)return;var n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];n.shso=1;(f.SHSODelayAfter=f.SHSODelayAfter||[]).push(function(){if(f.fbq===n&&!n.callMethod&&!d.querySelector(\'script[src*="fbevents"]\')){var t=d.createElement("script");t.async=!0;t.src="https://connect.facebook.net/en_US/fbevents.js";d.head.appendChild(t)}})}(window,document);';
		$queue_stub = static function ( string $name ): string {
			return 'window.' . $name . '=window.' . $name . '||[];';
		};
		$fn_stub    = static function ( string $name ): string {
			return 'window.' . $name . '=window.' . $name . '||function(){(window.' . $name . '.q=window.' . $name . '.q||[]).push(arguments)};';
		};

		return array(
			// ---------------------------------------------------------------
			// Never touch: consent managers.
			// ---------------------------------------------------------------
			array(
				'id'          => 'consent_mode',
				'name'        => 'Google Consent Mode',
				'category'    => 'consent',
				'inline'      => array( "gtag('consent'", 'gtag("consent"', "'consent','default'", '"consent","default"', "'consent','update'", '"consent","update"' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'cookiebot',
				'name'        => 'Cookiebot',
				'category'    => 'consent',
				'url'         => array( 'consent.cookiebot.com', 'consentcdn.cookiebot.com', 'cookiebot' ),
				'inline'      => array( 'cookiebot', 'cookieconsent' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'cookieyes',
				'name'        => 'CookieYes',
				'category'    => 'consent',
				'url'         => array( 'cdn-cookieyes.com', 'cookieyes.com', 'cookie-law-info' ),
				'inline'      => array( 'cookieyes', '_ckyconfig', 'cookielawinfo' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'complianz',
				'name'        => 'Complianz',
				'category'    => 'consent',
				'url'         => array( 'complianz', 'cmplz' ),
				'inline'      => array( 'complianz', 'cmplz' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'onetrust',
				'name'        => 'OneTrust',
				'category'    => 'consent',
				'url'         => array( 'cdn.cookielaw.org', 'cookielaw.org', 'optanon', 'onetrust.com', 'cookiepro.com' ),
				'inline'      => array( 'optanonwrapper', 'onetrust', 'optanon' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'borlabs',
				'name'        => 'Borlabs Cookie',
				'category'    => 'consent',
				'url'         => array( 'borlabs-cookie', 'borlabs' ),
				'inline'      => array( 'borlabscookie', 'borlabs' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'iubenda',
				'name'        => 'iubenda',
				'category'    => 'consent',
				'url'         => array( 'iubenda.com' ),
				'inline'      => array( '_iub', 'iubenda' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'usercentrics',
				'name'        => 'Usercentrics',
				'category'    => 'consent',
				'url'         => array( 'usercentrics' ),
				'inline'      => array( 'usercentrics', 'uc_ui' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'termly',
				'name'        => 'Termly',
				'category'    => 'consent',
				'url'         => array( 'termly.io' ),
				'inline'      => array( 'termly' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'osano',
				'name'        => 'Osano',
				'category'    => 'consent',
				'url'         => array( 'cmp.osano.com', 'osano.com' ),
				'inline'      => array( 'osano' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'tcf_cmp',
				'name'        => 'IAB TCF consent platform',
				'category'    => 'consent',
				'url'         => array( 'quantcast.mgr.consensu.org', 'cmp.quantcast.com', 'consensu.org', 'sdk.privacy-center.org', 'consentmanager.net', 'static.axept.io', 'consent.trustarc.com', 'truste.com' ),
				'inline'      => array( '__tcfapi', '__cmp(', 'didomi', 'axeptiosettings', 'cmp_id' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'wp_consent_plugins',
				'name'        => 'WordPress cookie consent plugin',
				'category'    => 'consent',
				'url'         => array( 'cookie-notice', 'hu-manity.co', 'gdpr-cookie-compliance', 'moove_gdpr', 'real-cookie-banner', 'cookie-consent', 'gdpr-cookie', 'wp-consent-api', 'js.hs-banner.com' ),
				'inline'      => array( 'cnargs', 'moove_gdpr', 'realcookiebanner', 'wp_consent_type', 'wp_set_consent', 'huoptions' ),
				'never_touch' => true,
			),

			// ---------------------------------------------------------------
			// Never touch: A/B testing and anti-flicker snippets.
			// ---------------------------------------------------------------
			array(
				'id'          => 'google_optimize',
				'name'        => 'Google Optimize',
				'category'    => 'ab_testing',
				'url'         => array( 'googleoptimize.com', 'optimize.google.com' ),
				'inline'      => array( 'async-hide', 'googleoptimize', 'optimize_id' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'vwo',
				'name'        => 'VWO',
				'category'    => 'ab_testing',
				'url'         => array( 'visualwebsiteoptimizer.com', 'dev.vwo.com' ),
				'inline'      => array( '_vwo_code', 'visualwebsiteoptimizer', '_vwo_' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'optimizely',
				'name'        => 'Optimizely',
				'category'    => 'ab_testing',
				'url'         => array( 'optimizely.com' ),
				'inline'      => array( 'optimizely' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'ab_tasty',
				'name'        => 'AB Tasty',
				'category'    => 'ab_testing',
				'url'         => array( 'abtasty.com' ),
				'inline'      => array( 'abtasty' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'convert',
				'name'        => 'Convert Experiences',
				'category'    => 'ab_testing',
				'url'         => array( 'convertexperiments.com' ),
				'inline'      => array( 'convertexperiments', '_conv_' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'kameleoon',
				'name'        => 'Kameleoon',
				'category'    => 'ab_testing',
				'url'         => array( 'kameleoon.eu', 'kameleoon.io' ),
				'inline'      => array( 'kameleoon' ),
				'never_touch' => true,
			),

			// ---------------------------------------------------------------
			// Never touch: CAPTCHA.
			// ---------------------------------------------------------------
			array(
				'id'          => 'recaptcha',
				'name'        => 'Google reCAPTCHA',
				'category'    => 'captcha',
				'url'         => array( 'google.com/recaptcha', 'gstatic.com/recaptcha', 'recaptcha.net' ),
				'inline'      => array( 'grecaptcha', 'g-recaptcha' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'hcaptcha',
				'name'        => 'hCaptcha',
				'category'    => 'captcha',
				'url'         => array( 'hcaptcha.com' ),
				'inline'      => array( 'hcaptcha' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'turnstile',
				'name'        => 'Cloudflare Turnstile',
				'category'    => 'captcha',
				'url'         => array( 'challenges.cloudflare.com' ),
				'inline'      => array( 'turnstile.render', 'cf-turnstile' ),
				'never_touch' => true,
			),

			// ---------------------------------------------------------------
			// Never touch: payments.
			// ---------------------------------------------------------------
			array(
				'id'          => 'stripe',
				'name'        => 'Stripe',
				'category'    => 'payment',
				'url'         => array( 'js.stripe.com', 'm.stripe.network', 'checkout.stripe.com' ),
				'inline'      => array( 'stripe(', 'js.stripe.com' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'paypal',
				'name'        => 'PayPal',
				'category'    => 'payment',
				'url'         => array( 'paypal.com/sdk', 'paypalobjects.com', 'paypal.com/tagmanager' ),
				'inline'      => array( 'paypal.buttons', 'paypal.com/sdk', 'paypalobjects.com' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'braintree',
				'name'        => 'Braintree',
				'category'    => 'payment',
				'url'         => array( 'braintreegateway.com', 'braintree-api.com' ),
				'inline'      => array( 'braintree.' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'square',
				'name'        => 'Square',
				'category'    => 'payment',
				'url'         => array( 'js.squareup.com', 'squarecdn.com', 'squareupsandbox.com' ),
				'inline'      => array( 'square.payments', 'squarecdn.com' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'klarna',
				'name'        => 'Klarna',
				'category'    => 'payment',
				'url'         => array( 'klarna.com', 'klarnaservices.com', 'klarnacdn.net' ),
				'inline'      => array( 'klarna.' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'mollie',
				'name'        => 'Mollie',
				'category'    => 'payment',
				'url'         => array( 'js.mollie.com' ),
				'inline'      => array( 'mollie(' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'adyen',
				'name'        => 'Adyen',
				'category'    => 'payment',
				'url'         => array( 'adyen.com/checkoutshopper', 'checkoutshopper-live.adyen.com', 'checkoutshopper-test.adyen.com', 'adyen.com' ),
				'inline'      => array( 'adyencheckout' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'amazon_pay',
				'name'        => 'Amazon Pay',
				'category'    => 'payment',
				'url'         => array( 'payments-amazon.com', 'amazonpay' ),
				'inline'      => array( 'amazon.pay', 'offamazonpayments' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'apple_pay',
				'name'        => 'Apple Pay',
				'category'    => 'payment',
				'url'         => array( 'applepay.cdn-apple.com' ),
				'inline'      => array( 'applepaysession' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'google_pay',
				'name'        => 'Google Pay',
				'category'    => 'payment',
				'url'         => array( 'pay.google.com' ),
				'inline'      => array( 'google.payments.api' ),
				'never_touch' => true,
			),

			// ---------------------------------------------------------------
			// Never touch: maps.
			// ---------------------------------------------------------------
			array(
				'id'          => 'google_maps',
				'name'        => 'Google Maps',
				'category'    => 'maps',
				'url'         => array( 'maps.googleapis.com', 'maps.google.com/maps/api', 'maps.gstatic.com' ),
				'inline'      => array( 'google.maps.', 'maps.googleapis.com' ),
				'never_touch' => true,
			),
			array(
				'id'          => 'other_maps',
				'name'        => 'Map library',
				'category'    => 'maps',
				'url'         => array( 'api.mapbox.com', 'api.tiles.mapbox.com', 'bing.com/api/maps', 'unpkg.com/leaflet', 'npm/leaflet', 'api-maps.yandex' ),
				'inline'      => array( 'mapboxgl.', 'l.map(' ),
				'never_touch' => true,
			),

			// ---------------------------------------------------------------
			// Analytics and tag managers.
			// ---------------------------------------------------------------
			array(
				'id'         => 'google_tag_manager',
				'name'       => 'Google Tag Manager',
				'category'   => 'tag_manager',
				'url'        => array( 'googletagmanager.com/gtm.js' ),
				'inline'     => array( "'gtm.start'", '"gtm.start"', 'googletagmanager.com/gtm.js' ),
				'delayable'  => true,
				'defer_safe' => true,
				'stub'       => $gtag_stub,
			),
			array(
				'id'         => 'google_analytics',
				'name'       => 'Google Analytics',
				'category'   => 'analytics',
				'url'        => array( 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', 'google-analytics.com/ga.js', 'google-analytics.com/urchin.js', 'stats.g.doubleclick.net/dc.js' ),
				'inline'     => array( 'gtag(', 'googleanalyticsobject', "ga('create'", 'ga("create"', "ga('send'", 'ga("send"', '_gaq.push' ),
				'delayable'  => true,
				'defer_safe' => true,
				'stub'       => $gtag_stub,
			),
			array(
				'id'         => 'matomo',
				'name'       => 'Matomo',
				'category'   => 'analytics',
				'url'        => array( 'matomo.js', 'piwik.js', 'matomo.cloud' ),
				'inline'     => array( '_paq.push', 'settrackerurl' ),
				'delayable'  => true,
				'defer_safe' => true,
				'stub'       => $queue_stub( '_paq' ),
			),
			array(
				'id'       => 'plausible',
				'name'     => 'Plausible Analytics',
				'category' => 'analytics',
				'url'      => array( 'plausible.io/js' ),
				'inline'   => array( 'window.plausible' ),
			),
			array(
				'id'       => 'fathom',
				'name'     => 'Fathom Analytics',
				'category' => 'analytics',
				'url'      => array( 'cdn.usefathom.com', 'usefathom.com/script.js' ),
			),
			array(
				'id'       => 'simple_analytics',
				'name'     => 'Simple Analytics',
				'category' => 'analytics',
				'url'      => array( 'simpleanalyticscdn.com' ),
			),
			array(
				'id'       => 'cloudflare_insights',
				'name'     => 'Cloudflare Web Analytics',
				'category' => 'analytics',
				'url'      => array( 'static.cloudflareinsights.com' ),
			),
			array(
				'id'        => 'mixpanel',
				'name'      => 'Mixpanel',
				'category'  => 'analytics',
				'url'       => array( 'cdn.mxpnl.com', 'cdn4.mxpnl.com', 'mixpanel.com/libs' ),
				'inline'    => array( 'mixpanel.init', 'mixpanel_lib_url', 'mixpanel.track' ),
				'delayable' => true,
			),
			array(
				'id'        => 'segment',
				'name'      => 'Segment',
				'category'  => 'analytics',
				'url'       => array( 'cdn.segment.com', 'cdn.segment.io' ),
				'inline'    => array( 'analytics.load(', 'cdn.segment.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'amplitude',
				'name'      => 'Amplitude',
				'category'  => 'analytics',
				'url'       => array( 'cdn.amplitude.com', 'amplitude.com/libs', 'cdn.amplitude.io' ),
				'inline'    => array( 'amplitude.getinstance', 'amplitude.init' ),
				'delayable' => true,
			),
			array(
				'id'        => 'heap',
				'name'      => 'Heap',
				'category'  => 'analytics',
				'url'       => array( 'cdn.heapanalytics.com', 'heapanalytics.com/js' ),
				'inline'    => array( 'heap.load(' ),
				'delayable' => true,
			),
			array(
				'id'        => 'yandex_metrica',
				'name'      => 'Yandex Metrica',
				'category'  => 'analytics',
				'url'       => array( 'mc.yandex.ru/metrika', 'mc.yandex.com/metrika' ),
				'inline'    => array( 'mc.yandex.ru/metrika', 'ym(' ),
				'delayable' => true,
			),
			array(
				'id'        => 'jetpack_stats',
				'name'      => 'Jetpack Stats',
				'category'  => 'analytics',
				'url'       => array( 'stats.wp.com' ),
				'inline'    => array( '_stq.push', '_stq=' ),
				'delayable' => true,
				'stub'      => $queue_stub( '_stq' ),
			),
			array(
				'id'        => 'pardot',
				'name'      => 'Salesforce Pardot',
				'category'  => 'analytics',
				'url'       => array( 'pi.pardot.com', 'pardot.com/pd.js' ),
				'inline'    => array( 'piaid', 'pardot.com' ),
				'delayable' => true,
			),

			// ---------------------------------------------------------------
			// Heatmaps and session recording.
			// ---------------------------------------------------------------
			array(
				'id'        => 'hotjar',
				'name'      => 'Hotjar',
				'category'  => 'heatmap',
				'url'       => array( 'static.hotjar.com', 'script.hotjar.com' ),
				'inline'    => array( '_hjsettings', 'hj(' ),
				'delayable' => true,
				'stub'      => $fn_stub( 'hj' ),
			),
			array(
				'id'        => 'clarity',
				'name'      => 'Microsoft Clarity',
				'category'  => 'heatmap',
				'url'       => array( 'clarity.ms/tag', 'www.clarity.ms' ),
				'inline'    => array( 'clarity(', 'clarity.ms/tag' ),
				'delayable' => true,
				'stub'      => $fn_stub( 'clarity' ),
			),
			array(
				'id'        => 'fullstory',
				'name'      => 'FullStory',
				'category'  => 'heatmap',
				'url'       => array( 'edge.fullstory.com', 'fullstory.com/s/fs.js' ),
				'inline'    => array( '_fs_org', 'fullstory.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'mouseflow',
				'name'      => 'Mouseflow',
				'category'  => 'heatmap',
				'url'       => array( 'cdn.mouseflow.com' ),
				'inline'    => array( '_mfq', 'cdn.mouseflow.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'lucky_orange',
				'name'      => 'Lucky Orange',
				'category'  => 'heatmap',
				'url'       => array( 'luckyorange.com', 'luckyorange.net' ),
				'inline'    => array( '__lo_site_id', 'luckyorange' ),
				'delayable' => true,
			),
			array(
				'id'        => 'crazy_egg',
				'name'      => 'Crazy Egg',
				'category'  => 'heatmap',
				'url'       => array( 'script.crazyegg.com', 'crazyegg.com/pages' ),
				'inline'    => array( 'crazyegg.com' ),
				'delayable' => true,
			),

			// ---------------------------------------------------------------
			// Advertising, conversion tracking and pixels.
			// ---------------------------------------------------------------
			array(
				'id'       => 'google_adsense',
				'name'     => 'Google AdSense',
				'category' => 'ads',
				'url'      => array( 'pagead2.googlesyndication.com', 'googlesyndication.com', 'adsbygoogle.js' ),
				'inline'   => array( 'adsbygoogle' ),
			),
			array(
				'id'       => 'google_publisher_tag',
				'name'     => 'Google Publisher Tag',
				'category' => 'ads',
				'url'      => array( 'securepubads.g.doubleclick.net', 'googletagservices.com/tag/js/gpt.js' ),
				'inline'   => array( 'googletag.cmd', 'googletag.defineslot' ),
			),
			array(
				'id'        => 'google_ads',
				'name'      => 'Google Ads conversion tracking',
				'category'  => 'ads',
				'url'       => array( 'googleadservices.com', 'googleads.g.doubleclick.net' ),
				'inline'    => array( 'google_conversion_id', 'googleadservices.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'doubleclick',
				'name'      => 'Google Marketing Platform (DoubleClick)',
				'category'  => 'ads',
				'url'       => array( 'doubleclick.net' ),
				'inline'    => array( 'fls.doubleclick.net' ),
				'delayable' => true,
			),
			array(
				'id'        => 'meta_pixel',
				'name'      => 'Meta (Facebook) Pixel',
				'category'  => 'ads',
				'url'       => array( 'connect.facebook.net/en_us/fbevents.js', 'fbevents.js', 'connect.facebook.net/signals' ),
				'inline'    => array( 'fbq(' ),
				'delayable' => true,
				'stub'      => $fbq_stub,
			),
			array(
				'id'        => 'facebook_sdk',
				'name'      => 'Facebook SDK',
				'category'  => 'social',
				'url'       => array( 'connect.facebook.net' ),
				'inline'    => array( 'facebook-jssdk', 'fb.init', 'fbasyncinit' ),
				'delayable' => true,
			),
			array(
				'id'        => 'tiktok_embed',
				'name'      => 'TikTok embed',
				'category'  => 'social',
				'url'       => array( 'tiktok.com/embed.js' ),
				'delayable' => true,
			),
			array(
				'id'        => 'tiktok_pixel',
				'name'      => 'TikTok Pixel',
				'category'  => 'ads',
				'url'       => array( 'analytics.tiktok.com' ),
				'inline'    => array( 'ttq.', 'tiktokanalyticsobject' ),
				'delayable' => true,
			),
			array(
				'id'        => 'linkedin_insight',
				'name'      => 'LinkedIn Insight Tag',
				'category'  => 'ads',
				'url'       => array( 'snap.licdn.com', 'px.ads.linkedin.com' ),
				'inline'    => array( '_linkedin_partner_id', 'lintrk(' ),
				'delayable' => true,
			),
			array(
				'id'        => 'pinterest_tag',
				'name'      => 'Pinterest Tag',
				'category'  => 'ads',
				'url'       => array( 's.pinimg.com/ct/', 'ct.pinterest.com' ),
				'inline'    => array( 'pintrk(' ),
				'delayable' => true,
			),
			array(
				'id'        => 'pinterest_widgets',
				'name'      => 'Pinterest widgets',
				'category'  => 'social',
				'url'       => array( 'assets.pinterest.com/js/pinit' ),
				'delayable' => true,
			),
			array(
				'id'        => 'twitter_pixel',
				'name'      => 'X (Twitter) Pixel',
				'category'  => 'ads',
				'url'       => array( 'static.ads-twitter.com', 'analytics.twitter.com' ),
				'inline'    => array( 'twq(' ),
				'delayable' => true,
			),
			array(
				'id'        => 'twitter_widgets',
				'name'      => 'X (Twitter) widgets',
				'category'  => 'social',
				'url'       => array( 'platform.twitter.com/widgets.js', 'platform.x.com/widgets.js' ),
				'inline'    => array( 'twitter-wjs' ),
				'delayable' => true,
			),
			array(
				'id'        => 'snap_pixel',
				'name'      => 'Snap Pixel',
				'category'  => 'ads',
				'url'       => array( 'sc-static.net/scevent', 'tr.snapchat.com' ),
				'inline'    => array( 'snaptr(' ),
				'delayable' => true,
			),
			array(
				'id'        => 'microsoft_uet',
				'name'      => 'Microsoft Advertising (UET)',
				'category'  => 'ads',
				'url'       => array( 'bat.bing.com' ),
				'inline'    => array( 'uetq', 'bat.bing.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'reddit_pixel',
				'name'      => 'Reddit Pixel',
				'category'  => 'ads',
				'url'       => array( 'redditstatic.com/ads' ),
				'inline'    => array( 'rdt(' ),
				'delayable' => true,
			),
			array(
				'id'        => 'taboola',
				'name'      => 'Taboola',
				'category'  => 'ads',
				'url'       => array( 'cdn.taboola.com' ),
				'inline'    => array( '_taboola', 'cdn.taboola.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'outbrain',
				'name'      => 'Outbrain',
				'category'  => 'ads',
				'url'       => array( 'widgets.outbrain.com', 'outbrain.com/outbrain.js' ),
				'inline'    => array( 'obapi', 'outbrain.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'criteo',
				'name'      => 'Criteo',
				'category'  => 'ads',
				'url'       => array( 'static.criteo.net/js/ld/ld.js', 'dynamic.criteo.com', 'sslwidget.criteo.com' ),
				'inline'    => array( 'criteo_q' ),
				'delayable' => true,
			),
			array(
				'id'       => 'amazon_ads',
				'name'     => 'Amazon Ads',
				'category' => 'ads',
				'url'      => array( 'amazon-adsystem.com' ),
				'inline'   => array( 'apstag' ),
			),

			// ---------------------------------------------------------------
			// Chat and marketing automation.
			// ---------------------------------------------------------------
			array(
				'id'        => 'hubspot',
				'name'      => 'HubSpot',
				'category'  => 'analytics',
				'url'       => array( 'js.hs-scripts.com', 'js.hs-analytics.net', 'js.hsadspixel.net', 'js.usemessages.com', 'js.hscollectedforms.net' ),
				'inline'    => array( '_hsq', 'hs-script-loader' ),
				'delayable' => true,
				'stub'      => $queue_stub( '_hsq' ),
			),
			array(
				'id'       => 'hubspot_forms',
				'name'     => 'HubSpot forms',
				'category' => 'other',
				'url'      => array( 'js.hsforms.net', 'hsforms.com' ),
				'inline'   => array( 'hbspt.forms.create' ),
			),
			array(
				'id'        => 'klaviyo',
				'name'      => 'Klaviyo',
				'category'  => 'analytics',
				'url'       => array( 'static.klaviyo.com/onsite' ),
				'inline'    => array( '_learnq' ),
				'delayable' => true,
			),
			array(
				'id'        => 'mailchimp',
				'name'      => 'Mailchimp',
				'category'  => 'analytics',
				'url'       => array( 'chimpstatic.com/mcjs-connected' ),
				'delayable' => true,
			),
			array(
				'id'        => 'intercom',
				'name'      => 'Intercom',
				'category'  => 'chat',
				'url'       => array( 'widget.intercom.io', 'js.intercomcdn.com' ),
				'inline'    => array( 'intercomsettings', 'widget.intercom.io' ),
				'delayable' => true,
			),
			array(
				'id'        => 'drift',
				'name'      => 'Drift',
				'category'  => 'chat',
				'url'       => array( 'js.driftt.com', 'drift.com/include' ),
				'inline'    => array( 'drift.load', 'driftt.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'crisp',
				'name'      => 'Crisp',
				'category'  => 'chat',
				'url'       => array( 'client.crisp.chat' ),
				'inline'    => array( 'crisp_website_id' ),
				'delayable' => true,
			),
			array(
				'id'        => 'tawk',
				'name'      => 'Tawk.to',
				'category'  => 'chat',
				'url'       => array( 'embed.tawk.to' ),
				'inline'    => array( 'tawk_api' ),
				'delayable' => true,
			),
			array(
				'id'        => 'zendesk',
				'name'      => 'Zendesk Chat',
				'category'  => 'chat',
				'url'       => array( 'static.zdassets.com', 'zopim.com' ),
				'inline'    => array( 'ze-snippet', 'zopim' ),
				'delayable' => true,
			),
			array(
				'id'        => 'livechat',
				'name'      => 'LiveChat',
				'category'  => 'chat',
				'url'       => array( 'cdn.livechatinc.com', 'livechatinc.com' ),
				'inline'    => array( '__lc.license', 'livechatinc.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'tidio',
				'name'      => 'Tidio',
				'category'  => 'chat',
				'url'       => array( 'code.tidio.co' ),
				'delayable' => true,
			),
			array(
				'id'        => 'olark',
				'name'      => 'Olark',
				'category'  => 'chat',
				'url'       => array( 'static.olark.com' ),
				'inline'    => array( 'olark.identify', "olark('api" ),
				'delayable' => true,
			),
			array(
				'id'        => 'freshchat',
				'name'      => 'Freshchat',
				'category'  => 'chat',
				'url'       => array( 'wchat.freshchat.com', 'snippets.freshchat.com', 'fw-cdn.com' ),
				'inline'    => array( 'fcwidget' ),
				'delayable' => true,
			),

			// ---------------------------------------------------------------
			// Social embeds and reviews.
			// ---------------------------------------------------------------
			array(
				'id'        => 'instagram_embed',
				'name'      => 'Instagram embed',
				'category'  => 'social',
				'url'       => array( 'instagram.com/embed.js', 'platform.instagram.com' ),
				'inline'    => array( 'instgrm.embeds' ),
				'delayable' => true,
			),
			array(
				'id'        => 'share_buttons',
				'name'      => 'Share buttons (AddThis/ShareThis)',
				'category'  => 'social',
				'url'       => array( 's7.addthis.com', 'addthis.com/js', 'platform-api.sharethis.com', 'sharethis.com' ),
				'delayable' => true,
			),
			array(
				'id'        => 'disqus',
				'name'      => 'Disqus',
				'category'  => 'social',
				'url'       => array( 'disqus.com/embed.js', 'disqus.com/count.js' ),
				'inline'    => array( 'disqus_config' ),
				'delayable' => true,
			),
			array(
				'id'        => 'trustpilot',
				'name'      => 'Trustpilot',
				'category'  => 'reviews',
				'url'       => array( 'widget.trustpilot.com' ),
				'delayable' => true,
			),

			// ---------------------------------------------------------------
			// Video players (facades handle these; not delayed).
			// ---------------------------------------------------------------
			array(
				'id'       => 'youtube_api',
				'name'     => 'YouTube player API',
				'category' => 'video',
				'url'      => array( 'youtube.com/iframe_api', 'youtube.com/player_api', 'www-widgetapi' ),
				'inline'   => array( 'onyoutubeiframeapiready' ),
			),
			array(
				'id'       => 'vimeo_player',
				'name'     => 'Vimeo player API',
				'category' => 'video',
				'url'      => array( 'player.vimeo.com/api/player.js' ),
			),
			array(
				'id'       => 'wistia',
				'name'     => 'Wistia',
				'category' => 'video',
				'url'      => array( 'fast.wistia.com', 'fast.wistia.net' ),
			),
		);
	}
}
