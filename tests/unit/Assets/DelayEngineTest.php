<?php
/**
 * Tests for the delay engine (HTML conversion, loader and stubs).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\AssetRewriter;
use SH\SpeedOptimizer\Assets\DelayEngine;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\ScriptGraph;
use SH\SpeedOptimizer\Modules\AssetOptimization\HeadInjector;

final class DelayEngineTest extends TestCase {

	protected function setUp(): void {
		AssetRewriter::reset();
		DelayEngine::reset();
	}

	/**
	 * Page markup.
	 *
	 * @param string $head Head content.
	 * @param string $body Body content.
	 */
	private static function page( string $head, string $body = '' ): HtmlDocument {
		return new HtmlDocument( "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width\">" . $head . '</head><body><p>Hi</p>' . $body . '</body></html>' );
	}

	/**
	 * Exclusion callback that excludes nothing.
	 */
	private static function none(): callable {
		return static function (): bool {
			return false;
		};
	}

	public function test_third_party_scripts_are_converted_with_attributes_preserved(): void {
		$doc = self::page(
			'<script async id="gtag-js" nonce="abc123" data-cfasync="true" src="https://www.googletagmanager.com/gtag/js?id=G-1&amp;l=dataLayer"></script>'
			. "<script id=\"gtag-js-after\" nonce=\"abc123\">window.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\ngtag('js', new Date());\ngtag('config', 'G-1');</script>"
		);

		$result = DelayEngine::delay_third_party( $doc, self::none() );
		$html   = $doc->html();

		$this->assertSame( 2, $result['delayed'] );
		$this->assertSame( array( 'google_analytics' ), $result['ids'] );
		$this->assertStringContainsString( '<script async id="gtag-js" nonce="abc123" data-cfasync="true" type="shso/delay" data-shso-src="https://www.googletagmanager.com/gtag/js?id=G-1&amp;l=dataLayer" data-shso-delay="tp"></script>', $html );
		$this->assertStringContainsString( '<script id="gtag-js-after" nonce="abc123" type="shso/delay" data-shso-delay="tp">window.dataLayer', $html );
		$this->assertLessThan( strpos( $html, 'id="gtag-js-after"' ), strpos( $html, 'id="gtag-js"' ), 'Document order is preserved.' );
	}

	public function test_never_touch_and_consent_managed_scripts_stay_untouched(): void {
		$untouched = array(
			'<script>gtag("consent", "default", { ad_storage: "denied" });</script>',
			'<script src="https://consent.cookiebot.com/uc.js" data-cbid="1"></script>',
			'<script src="https://cdn-cookieyes.com/client_data/1/script.js"></script>',
			'<script src="https://www.google.com/recaptcha/api.js"></script>',
			'<script src="https://js.stripe.com/v3/"></script>',
			'<script src="https://www.paypal.com/sdk/js?client-id=1"></script>',
			'<script src="https://maps.googleapis.com/maps/api/js?key=1"></script>',
			'<script src="https://www.googleoptimize.com/optimize.js?id=OPT-1"></script>',
			'<script>(function(a,s,y,n,c,h,i,d,e){s.className+=" "+y;})(window,document.documentElement,"async-hide","dataLayer",4000,{});</script>',
			'<script type="text/plain" data-cookieconsent="statistics" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>',
			'<script data-cookieconsent="statistics" src="https://www.googletagmanager.com/gtag/js?id=G-2"></script>',
			'<script type="text/plain" class="cmplz-script" data-category="marketing">fbq("init","1");</script>',
			'<script data-no-optimize="1" src="https://connect.facebook.net/en_US/fbevents.js"></script>',
			'<script data-cfasync="false" src="https://static.hotjar.com/c/hotjar-1.js"></script>',
			'<script type="application/ld+json">{"@type":"Organization","sameAs":"gtag("}</script>',
			'<script src="https://example.test/wp-content/plugins/p/app.js" id="p-js"></script>',
			'<script src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>',
			'<script>document.write(\'<script src="https://static.hotjar.com/x.js"><\/script>\');</script>',
		);
		$doc       = self::page( implode( "\n", $untouched ) );
		$before    = $doc->html();
		$result    = DelayEngine::delay_third_party( $doc, self::none() );

		$this->assertSame( 0, $result['delayed'] );
		$this->assertSame( $before, $doc->html() );
		$this->assertFalse( DelayEngine::finalize( $doc ), 'No loader without delayed scripts.' );
	}

	public function test_exclusions_are_respected(): void {
		$doc      = self::page( '<script src="https://static.hotjar.com/c/hotjar-1.js" id="hotjar-js"></script><script>fbq("track","Lead");</script>' );
		$excluded = static function ( string $handle, string $src, string $code ): bool {
			return 'hotjar' === $handle || false !== strpos( $code, 'Lead' );
		};
		$this->assertSame( 0, DelayEngine::delay_third_party( $doc, $excluded )['delayed'] );
	}

	public function test_rewritten_local_copies_are_matched_by_original_url(): void {
		AssetRewriter::remember( '/wp-content/cache/sh-speed-optimizer/assets/other-matomo-abc.min.js', '/wp-content/uploads/matomo/matomo.js' );
		$doc = self::page( '<script src="/wp-content/cache/sh-speed-optimizer/assets/other-matomo-abc.min.js"></script>' );
		$this->assertSame( 1, DelayEngine::delay_third_party( $doc, self::none() )['delayed'] );
	}

	public function test_module_type_is_preserved(): void {
		$doc = self::page( '<script type="module" src="https://widget.intercom.io/widget/abc"></script>' );
		DelayEngine::delay_third_party( $doc, self::none() );
		$this->assertStringContainsString( '<script type="shso/delay" data-shso-type="module" data-shso-src="https://widget.intercom.io/widget/abc" data-shso-delay="tp"></script>', $doc->html() );
	}

	public function test_duplicate_attributes_do_not_break_conversion(): void {
		$doc = self::page( '<script async async id="h" id="h2" src="https://static.hotjar.com/c/hotjar-1.js"></script>' );
		DelayEngine::delay_third_party( $doc, self::none() );
		$this->assertStringContainsString( '<script async id="h" type="shso/delay" data-shso-src="https://static.hotjar.com/c/hotjar-1.js" data-shso-delay="tp"></script>', $doc->html() );
	}

	public function test_loader_and_stubs_are_injected_once_after_meta_charset(): void {
		$doc = self::page(
			'<script nonce="n0nce">var early = 1;</script>'
			. '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
			. "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){};}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','1');</script>"
			. "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var j=d.createElement(s);j.src='https://www.googletagmanager.com/gtm.js?id='+i;})(window,document,'script','dataLayer','GTM-1');</script>"
		);

		DelayEngine::delay_third_party( $doc, self::none() );
		$this->assertTrue( DelayEngine::finalize( $doc, array( 'timeout' => 8000, 'timeout_all' => 0, 'loader' => 'LOADER();' ) ) );
		$this->assertFalse( DelayEngine::finalize( $doc, array( 'loader' => 'LOADER();' ) ), 'Injected only once.' );

		$html = $doc->html();
		$this->assertSame( 1, substr_count( $html, 'id="shso-delay-loader"' ) );
		$this->assertSame( 1, substr_count( $html, 'id="shso-delay-stubs"' ) );
		$this->assertStringContainsString( '<meta charset="UTF-8"><script id="shso-delay-loader" data-timeout="8000" data-timeout-all="0" data-replay="0" nonce="n0nce">LOADER();</script><script id="shso-delay-stubs" nonce="n0nce">', $html );
		$this->assertSame( 1, substr_count( $html, 'window.gtag=window.gtag||function(){dataLayer.push(arguments)};' ), 'Shared gtag stub only once.' );
		$this->assertStringContainsString( 'f.fbq=function(){n.callMethod?', $html );
		$this->assertLessThan( strpos( $html, 'var early' ), strpos( $html, 'shso-delay-stubs' ), 'Stubs come before any other script.' );
	}

	public function test_call_only_snippets_do_not_inject_library_stubs(): void {
		$doc = self::page( '<script>fbq("track","Purchase");</script>' );
		DelayEngine::delay_third_party( $doc, self::none() );
		DelayEngine::finalize( $doc, array( 'loader' => 'L();' ) );
		$this->assertStringContainsString( 'shso-delay-loader', $doc->html() );
		$this->assertStringNotContainsString( 'shso-delay-stubs', $doc->html() );
	}

	public function test_replay_flag_for_inline_late_listeners(): void {
		$doc = self::page( '', '<script>document.addEventListener("DOMContentLoaded", function(){ fbq("track","ViewContent"); });</script>' );
		DelayEngine::delay_third_party( $doc, self::none() );
		DelayEngine::finalize( $doc, array( 'loader' => 'L();' ) );
		$this->assertStringContainsString( 'data-replay="1"', $doc->html() );
	}

	public function test_real_loader_code_is_available_and_safe_to_inline(): void {
		$code = DelayEngine::loader_code();
		$this->assertNotSame( '', $code );
		$this->assertStringContainsString( 'SHSODelay', $code );
		$this->assertStringContainsString( 'shso:delay-done', $code );
		$this->assertStringNotContainsString( '</script', $code );
		$this->assertLessThan( 4096, strlen( $code ) );
	}

	public function test_convert_keeps_inline_code_and_type(): void {
		$tag  = \SH\SpeedOptimizer\Assets\Tag::parse( '<script type="text/javascript" id="x">' );
		$html = DelayEngine::convert( $tag, 'a();', DelayEngine::GROUP_ALL );
		$this->assertSame( '<script type="shso/delay" id="x" data-shso-type="text/javascript" data-shso-delay="all">a();</script>', $html );
	}

	public function test_handle_from_id(): void {
		$this->assertSame( 'contact-form-7', DelayEngine::handle_from_id( 'contact-form-7-js' ) );
		$this->assertSame( 'gtag', DelayEngine::handle_from_id( 'gtag-js-after' ) );
		$this->assertSame( '', DelayEngine::handle_from_id( 'random' ) );
		$this->assertSame( '', DelayEngine::handle_from_id( null ) );
	}

	// ---------------------------------------------------------------------
	// Delay all (first-party).
	// ---------------------------------------------------------------------

	public function test_delay_first_party_rules(): void {
		$scripts = array(
			'jquery-core' => array( 'deps' => array() ),
			'jquery'      => array( 'deps' => array( 'jquery-core' ) ),
			'slider'      => array( 'deps' => array( 'jquery' ) ),
			'base-lib'    => array( 'deps' => array() ),
			'stays'       => array( 'deps' => array( 'base-lib' ) ),
			'widget'      => array( 'deps' => array() ),
		);
		$doc     = self::page(
			'<script src="/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>'
			. '<script id="slider-js-extra">var sliderConfig = {"speed":3};</script>'
			. '<script src="/wp-content/plugins/slider/slider.js" id="slider-js"></script>'
			. '<script id="slider-js-after">initSliderPlugin();</script>'
			. '<script src="/wp-content/plugins/base/base.js" id="base-lib-js"></script>'
			. '<script src="/wp-content/plugins/stays/stays.js" id="stays-js"></script>'
			. '<script src="/wp-content/plugins/widget/widget.js" id="widget-js"></script>'
			. '<script src="https://static.hotjar.com/c/hotjar-1.js"></script>'
			. '<script src="/wp-content/plugins/woo-stripe/stripe-checkout.js" id="wc-stripe-js"></script>',
			'<script>new Widget(".w");</script>'
		);

		$excluded = static function ( string $handle ): bool {
			return 'stays' === $handle;
		};
		$is_local = static function ( string $src ): bool {
			return '/' === $src[0] && '/' !== ( $src[1] ?? '' );
		};

		$result = DelayEngine::delay_first_party( $doc, $scripts, $excluded, $is_local );
		$html   = $doc->html();

		$this->assertSame( array( 'slider' ), $result['handles'] );
		$this->assertStringContainsString( '<script src="/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>', $html, 'jQuery is never delayed.' );
		$this->assertStringContainsString( '<script id="slider-js-extra" type="shso/delay" data-shso-delay="all">var sliderConfig', $html, 'Inline data moves with its handle.' );
		$this->assertStringContainsString( 'id="slider-js" type="shso/delay" data-shso-src="/wp-content/plugins/slider/slider.js" data-shso-delay="all"', $html );
		$this->assertStringContainsString( '<script id="slider-js-after" type="shso/delay" data-shso-delay="all">', $html );
		$this->assertStringContainsString( '<script src="/wp-content/plugins/base/base.js" id="base-lib-js"></script>', $html, 'A non-delayed script depends on it.' );
		$this->assertStringContainsString( '<script src="/wp-content/plugins/widget/widget.js" id="widget-js"></script>', $html, 'Inline code uses its global.' );
		$this->assertStringContainsString( '<script src="https://static.hotjar.com/c/hotjar-1.js"></script>', $html, 'Third-party scripts are left to the third-party delay.' );
		$this->assertStringContainsString( '<script src="/wp-content/plugins/woo-stripe/stripe-checkout.js" id="wc-stripe-js"></script>', $html, 'Payment scripts are never delayed.' );
		$this->assertSame( 3, $result['delayed'] );
	}

	// ---------------------------------------------------------------------
	// Head injection.
	// ---------------------------------------------------------------------

	public function test_head_injector_positions(): void {
		$doc = new HtmlDocument( '<html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="x"><title>t</title><link rel="stylesheet" href="a.css"><meta charset="late"></head><body></body></html>' );
		$this->assertTrue( HeadInjector::insert_early( $doc, '<X>' ) );
		$this->assertStringContainsString( '<meta http-equiv="Content-Security-Policy" content="x"><X><title>', $doc->html() );

		$doc = new HtmlDocument( '<html><head data-x="a>b"><script>1</script><meta charset="utf-8"></head><body></body></html>' );
		HeadInjector::insert_early( $doc, '<X>' );
		$this->assertStringContainsString( '<head data-x="a>b"><X><script>1</script>', $doc->html(), 'Never after a script.' );

		$doc = new HtmlDocument( '<html><body></body></html>' );
		$this->assertFalse( HeadInjector::insert_early( $doc, '<X>' ) );
	}

	public function test_tags_description_used_by_delay(): void {
		$doc  = self::page( '<script type="text/javascript; charset=utf-8" id="a-js-translations">wp.i18n.setLocaleData({});</script>' );
		$tags = ScriptGraph::tags_from_document( $doc );
		$this->assertSame( 'a', $tags[0]['handle'] );
		$this->assertSame( 'translations', $tags[0]['role'] );
		$this->assertTrue( $tags[0]['js'] );
	}
}
