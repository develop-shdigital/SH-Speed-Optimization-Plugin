<?php
/**
 * Tests for the third-party script catalog.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\ThirdPartyCatalog;

final class ThirdPartyCatalogTest extends TestCase {

	/**
	 * URL => [ id, delayable, never_touch ].
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:bool}>
	 */
	public static function urls(): array {
		return array(
			'gtag'            => array( 'https://www.googletagmanager.com/gtag/js?id=G-ABC123', 'google_analytics', true, false ),
			'analytics.js'    => array( '//www.google-analytics.com/analytics.js', 'google_analytics', true, false ),
			'ga.js'           => array( 'https://ssl.google-analytics.com/ga.js', 'google_analytics', true, false ),
			'gtm'             => array( 'https://www.googletagmanager.com/gtm.js?id=GTM-XYZ', 'google_tag_manager', true, false ),
			'adsense'         => array( 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1', 'google_adsense', false, false ),
			'gpt'             => array( 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', 'google_publisher_tag', false, false ),
			'floodlight'      => array( 'https://12345.fls.doubleclick.net/activityi', 'doubleclick', true, false ),
			'meta pixel'      => array( 'https://connect.facebook.net/en_US/fbevents.js', 'meta_pixel', true, false ),
			'facebook sdk'    => array( 'https://connect.facebook.net/de_DE/sdk.js#xfbml=1', 'facebook_sdk', true, false ),
			'hotjar'          => array( 'https://static.hotjar.com/c/hotjar-123.js?sv=6', 'hotjar', true, false ),
			'clarity'         => array( 'https://www.clarity.ms/tag/abc', 'clarity', true, false ),
			'tiktok pixel'    => array( 'https://analytics.tiktok.com/i18n/pixel/events.js', 'tiktok_pixel', true, false ),
			'tiktok embed'    => array( 'https://www.tiktok.com/embed.js', 'tiktok_embed', true, false ),
			'linkedin'        => array( 'https://snap.licdn.com/li.lms-analytics/insight.min.js', 'linkedin_insight', true, false ),
			'pinterest'       => array( 'https://s.pinimg.com/ct/core.js', 'pinterest_tag', true, false ),
			'twitter widgets' => array( 'https://platform.twitter.com/widgets.js', 'twitter_widgets', true, false ),
			'twitter pixel'   => array( 'https://static.ads-twitter.com/uwt.js', 'twitter_pixel', true, false ),
			'snap'            => array( 'https://sc-static.net/scevent.min.js', 'snap_pixel', true, false ),
			'hubspot'         => array( '//js.hs-scripts.com/123.js', 'hubspot', true, false ),
			'hubspot forms'   => array( '//js.hsforms.net/forms/embed/v2.js', 'hubspot_forms', false, false ),
			'intercom'        => array( 'https://widget.intercom.io/widget/abc', 'intercom', true, false ),
			'drift'           => array( 'https://js.driftt.com/include/123/abc.js', 'drift', true, false ),
			'crisp'           => array( 'https://client.crisp.chat/l.js', 'crisp', true, false ),
			'tawk'            => array( 'https://embed.tawk.to/abc/default', 'tawk', true, false ),
			'zendesk'         => array( 'https://static.zdassets.com/ekr/snippet.js?key=1', 'zendesk', true, false ),
			'livechat'        => array( 'https://cdn.livechatinc.com/tracking.js', 'livechat', true, false ),
			'tidio'           => array( '//code.tidio.co/abc.js', 'tidio', true, false ),
			'olark'           => array( 'https://static.olark.com/jsclient/loader.js', 'olark', true, false ),
			'freshchat'       => array( 'https://wchat.freshchat.com/js/widget.js', 'freshchat', true, false ),
			'matomo cloud'    => array( 'https://cdn.matomo.cloud/example.matomo.cloud/matomo.js', 'matomo', true, false ),
			'matomo self'     => array( 'https://example.test/wp-content/uploads/matomo/matomo.js', 'matomo', true, false ),
			'piwik'           => array( 'https://stats.example.test/piwik.js', 'matomo', true, false ),
			'plausible'       => array( 'https://plausible.io/js/script.js', 'plausible', false, false ),
			'fathom'          => array( 'https://cdn.usefathom.com/script.js', 'fathom', false, false ),
			'simple'          => array( 'https://scripts.simpleanalyticscdn.com/latest.js', 'simple_analytics', false, false ),
			'mixpanel'        => array( 'https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js', 'mixpanel', true, false ),
			'segment'         => array( 'https://cdn.segment.com/analytics.js/v1/abc/analytics.min.js', 'segment', true, false ),
			'amplitude'       => array( 'https://cdn.amplitude.com/libs/amplitude-8.min.js', 'amplitude', true, false ),
			'heap'            => array( 'https://cdn.heapanalytics.com/js/heap-1.js', 'heap', true, false ),
			'fullstory'       => array( 'https://edge.fullstory.com/s/fs.js', 'fullstory', true, false ),
			'mouseflow'       => array( '//cdn.mouseflow.com/projects/abc.js', 'mouseflow', true, false ),
			'lucky orange'    => array( 'https://tools.luckyorange.com/core/lo.js?site-id=1', 'lucky_orange', true, false ),
			'crazy egg'       => array( 'https://script.crazyegg.com/pages/scripts/0001/0001.js', 'crazy_egg', true, false ),
			'taboola'         => array( '//cdn.taboola.com/libtrc/site/loader.js', 'taboola', true, false ),
			'outbrain'        => array( 'https://widgets.outbrain.com/outbrain.js', 'outbrain', true, false ),
			'criteo'          => array( '//static.criteo.net/js/ld/ld.js', 'criteo', true, false ),
			'amazon ads'      => array( 'https://c.amazon-adsystem.com/aax2/apstag.js', 'amazon_ads', false, false ),
			'trustpilot'      => array( '//widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js', 'trustpilot', true, false ),
			'youtube api'     => array( 'https://www.youtube.com/iframe_api', 'youtube_api', false, false ),
			'vimeo'           => array( 'https://player.vimeo.com/api/player.js', 'vimeo_player', false, false ),
			'google maps'     => array( 'https://maps.googleapis.com/maps/api/js?key=abc&callback=init', 'google_maps', false, true ),
			'recaptcha'       => array( 'https://www.google.com/recaptcha/api.js?render=abc', 'recaptcha', false, true ),
			'recaptcha gstatic' => array( 'https://www.gstatic.com/recaptcha/releases/abc/recaptcha__en.js', 'recaptcha', false, true ),
			'hcaptcha'        => array( 'https://js.hcaptcha.com/1/api.js', 'hcaptcha', false, true ),
			'turnstile'       => array( 'https://challenges.cloudflare.com/turnstile/v0/api.js', 'turnstile', false, true ),
			'stripe'          => array( 'https://js.stripe.com/v3/', 'stripe', false, true ),
			'paypal'          => array( 'https://www.paypal.com/sdk/js?client-id=abc', 'paypal', false, true ),
			'braintree'       => array( 'https://js.braintreegateway.com/web/3.97.2/js/client.min.js', 'braintree', false, true ),
			'square'          => array( 'https://web.squarecdn.com/v1/square.js', 'square', false, true ),
			'klarna'          => array( 'https://x.klarnacdn.net/kp/lib/v1/api.js', 'klarna', false, true ),
			'mollie'          => array( 'https://js.mollie.com/v1/mollie.js', 'mollie', false, true ),
			'adyen'           => array( 'https://checkoutshopper-live.adyen.com/checkoutshopper/sdk/5.0.0/adyen.js', 'adyen', false, true ),
			'amazon pay'      => array( 'https://static-eu.payments-amazon.com/checkout.js', 'amazon_pay', false, true ),
			'cookiebot'       => array( 'https://consent.cookiebot.com/uc.js', 'cookiebot', false, true ),
			'cookieyes'       => array( 'https://cdn-cookieyes.com/client_data/abc/script.js', 'cookieyes', false, true ),
			'complianz local' => array( 'https://example.test/wp-content/plugins/complianz-gdpr/cookiebanner/js/complianz.min.js', 'complianz', false, true ),
			'onetrust'        => array( 'https://cdn.cookielaw.org/scripttemplates/otSDKStub.js', 'onetrust', false, true ),
			'borlabs'         => array( 'https://example.test/wp-content/plugins/borlabs-cookie/assets/javascript/borlabs-cookie.min.js', 'borlabs', false, true ),
			'iubenda'         => array( 'https://cdn.iubenda.com/cs/iubenda_cs.js', 'iubenda', false, true ),
			'usercentrics'    => array( 'https://app.usercentrics.eu/browser-ui/latest/loader.js', 'usercentrics', false, true ),
			'termly'          => array( 'https://app.termly.io/embed.min.js', 'termly', false, true ),
			'optimize'        => array( 'https://www.googleoptimize.com/optimize.js?id=OPT-1', 'google_optimize', false, true ),
			'vwo'             => array( 'https://dev.visualwebsiteoptimizer.com/j.php?a=1', 'vwo', false, true ),
			'optimizely'      => array( 'https://cdn.optimizely.com/js/123.js', 'optimizely', false, true ),
			'ab tasty'        => array( 'https://try.abtasty.com/abc.js', 'ab_tasty', false, true ),
			'convert'         => array( 'https://cdn-4.convertexperiments.com/js/1-2.js', 'convert', false, true ),
			'instagram embed' => array( '//www.instagram.com/embed.js', 'instagram_embed', true, false ),
		);
	}

	#[DataProvider( 'urls' )]
	public function test_match_url( string $url, string $id, bool $delayable, bool $never_touch ): void {
		$entry = ThirdPartyCatalog::match_url( $url );
		$this->assertNotNull( $entry, $url );
		$this->assertSame( $id, $entry['id'] );
		$this->assertSame( $delayable, $entry['delayable'] );
		$this->assertSame( $never_touch, $entry['never_touch'] );
	}

	public function test_unknown_urls_do_not_match(): void {
		$this->assertNull( ThirdPartyCatalog::match_url( 'https://example.test/wp-content/plugins/contact-form-7/includes/js/index.js' ) );
		$this->assertNull( ThirdPartyCatalog::match_url( 'https://example.test/wp-includes/js/jquery/jquery.min.js' ) );
		$this->assertNull( ThirdPartyCatalog::match_url( '' ) );
	}

	/**
	 * Inline code => id.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function snippets(): array {
		return array(
			'gtag config'     => array( "window.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\ngtag('js', new Date());\ngtag('config', 'G-ABC');", 'google_analytics' ),
			'consent default' => array( "gtag( 'consent', 'default', { ad_storage: 'denied' } );\ngtag('js', new Date());", 'consent_mode' ),
			'gtm'             => array( "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-X');", 'google_tag_manager' ),
			'analytics.js'    => array( "(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;})(window,document,'script','https://www.google-analytics.com/analytics.js','ga');\nga('create', 'UA-1', 'auto');", 'google_analytics' ),
			'meta pixel'      => array( "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){};}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');\nfbq('init', '123');\nfbq('track', 'PageView');", 'meta_pixel' ),
			'fbq call'        => array( "fbq( 'track', 'ViewContent' );", 'meta_pixel' ),
			'hotjar'          => array( "(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};h._hjSettings={hjid:1,hjsv:6};})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');", 'hotjar' ),
			'clarity'         => array( '(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};})(window, document, "clarity", "script", "abc");', 'clarity' ),
			'hsq'             => array( "var _hsq = window._hsq = window._hsq || [];\n_hsq.push(['setPath', '/']);", 'hubspot' ),
			'matomo'          => array( "var _paq = window._paq = window._paq || [];\n_paq.push(['trackPageView']);", 'matomo' ),
			'ttq'             => array( "ttq.track('ViewContent');", 'tiktok_pixel' ),
			'twq'             => array( "twq('event', 'tw-1', {});", 'twitter_pixel' ),
			'lintrk'          => array( "window.lintrk('track', { conversion_id: 1 });", 'linkedin_insight' ),
			'pintrk'          => array( "pintrk('track', 'checkout');", 'pinterest_tag' ),
			'snaptr'          => array( "snaptr('track','PAGE_VIEW');", 'snap_pixel' ),
			'optimize hide'   => array( "(function(a,s,y,n,c,h,i,d,e){s.className+=' '+y;})(window,document.documentElement,'async-hide','dataLayer',4000,{'GTM-1':true});", 'google_optimize' ),
			'recaptcha'       => array( "grecaptcha.ready(function(){ grecaptcha.execute('key'); });", 'recaptcha' ),
			'stripe'          => array( "var stripe = Stripe('pk_test');", 'stripe' ),
			'cookiebot'       => array( "window.addEventListener('CookiebotOnAccept', function () { if (Cookiebot.consent.marketing) {} });", 'cookiebot' ),
			'maps'            => array( 'var map = new google.maps.Map(el, {});', 'google_maps' ),
		);
	}

	#[DataProvider( 'snippets' )]
	public function test_match_inline( string $code, string $id ): void {
		$entry = ThirdPartyCatalog::match_inline( $code );
		$this->assertNotNull( $entry, $code );
		$this->assertSame( $id, $entry['id'] );
	}

	public function test_inline_word_boundaries(): void {
		$this->assertNull( ThirdPartyCatalog::match_inline( 'myfbq( 1 ); xgtag(2); function thj(){}' ) );
		$this->assertNull( ThirdPartyCatalog::match_inline( "jQuery( '.menu' ).on( 'click', toggle );" ) );
		$this->assertNull( ThirdPartyCatalog::match_inline( 'var trustedTypes = window.trustedTypes;' ) );
		$this->assertNull( ThirdPartyCatalog::match_inline( '' ) );
	}

	public function test_never_touch_wins_over_delayable_in_the_same_snippet(): void {
		$code  = "gtag('consent', 'update', { analytics_storage: 'granted' }); fbq('track', 'Lead');";
		$entry = ThirdPartyCatalog::match_inline( $code );
		$this->assertTrue( $entry['never_touch'] );
	}

	public function test_all_entries_are_well_formed(): void {
		$ids        = array();
		$categories = array( 'analytics', 'tag_manager', 'ads', 'social', 'video', 'maps', 'chat', 'captcha', 'payment', 'consent', 'ab_testing', 'heatmap', 'reviews', 'other' );
		$seen_delay = false;
		foreach ( ThirdPartyCatalog::all() as $entry ) {
			$this->assertNotContains( $entry['id'], $ids, 'Unique ids.' );
			$ids[] = $entry['id'];
			$this->assertContains( $entry['category'], $categories, $entry['id'] );
			$this->assertNotEmpty( $entry['name'] );
			$this->assertTrue( ! empty( $entry['url'] ) || ! empty( $entry['inline'] ), $entry['id'] );
			if ( $entry['never_touch'] ) {
				$this->assertFalse( $entry['delayable'], $entry['id'] );
				$this->assertFalse( $seen_delay, 'Never-touch entries come first.' );
			} else {
				$seen_delay = true;
			}
			if ( in_array( $entry['category'], array( 'consent', 'captcha', 'payment', 'maps', 'ab_testing' ), true ) ) {
				$this->assertTrue( $entry['never_touch'], $entry['id'] );
			}
		}
		$this->assertNotNull( ThirdPartyCatalog::get( 'meta_pixel' )['stub'] );
		$this->assertNull( ThirdPartyCatalog::get( 'nope' ) );
	}

	public function test_inline_loads_library(): void {
		$gtm = ThirdPartyCatalog::get( 'google_tag_manager' );
		$this->assertTrue( ThirdPartyCatalog::inline_loads_library( $gtm, "j.src='https://www.googletagmanager.com/gtm.js?id='+i;" ) );
		$this->assertFalse( ThirdPartyCatalog::inline_loads_library( ThirdPartyCatalog::get( 'meta_pixel' ), "fbq('track','Lead');" ) );
	}
}
