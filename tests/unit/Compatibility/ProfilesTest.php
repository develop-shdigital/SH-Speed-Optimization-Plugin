<?php
/**
 * Tests for representative compatibility profiles.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Compatibility\Profiles\AbstractProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\CommunityProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\DiviProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\ElementorProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\FormsProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\MembershipProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\MultilingualProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\WooCommerceProfile;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Context;

final class ProfilesTest extends TestCase {

	private static function penalty( Rules $rules, string $id ): int {
		$total = 0;
		foreach ( $rules->penalties( $id ) as $penalty ) {
			$total += $penalty[0];
		}
		return $total;
	}

	public function test_elementor(): void {
		$env     = new FakeEnvironment();
		$profile = new ElementorProfile( $env );
		$this->assertFalse( $profile->applies() );

		$env->constants = array( 'ELEMENTOR_VERSION' => '3.20.0' );
		$this->assertTrue( $profile->applies() );

		$rules = new Rules();
		$profile->register( $rules );

		foreach ( array( 'elementor-frontend', 'elementor-webpack-runtime', 'elementor-frontend-modules', 'elementor-waypoints', 'swiper', 'share-link', 'elementor-dialog', 'e-sticky' ) as $handle ) {
			$this->assertTrue( $rules->matches( 'js_no_defer', array( $handle ) ), $handle );
			$this->assertTrue( $rules->matches( 'js_no_delay', array( $handle ) ), $handle );
		}
		$this->assertTrue( $rules->matches( 'js_no_delay', array( '', 'https://example.test/wp-content/plugins/elementor/assets/js/frontend.min.js' ) ) );
		$this->assertFalse( $rules->matches( 'css_no_optimize', array( 'elementor-post-12', 'https://example.test/wp-content/uploads/elementor/css/post-12.css' ) ), 'Page CSS copies may still be minified.' );

		$this->assertNotNull( $rules->disabled_reason( 'js_delay_all' ) );
		$this->assertSame( 15, self::penalty( $rules, 'js_defer' ) );
		$this->assertSame( 5, self::penalty( $rules, 'js_delay_third_party' ) );
		$this->assertSame( 30, self::penalty( $rules, 'critical_css' ) );
		$this->assertSame( 5, self::penalty( $rules, 'video_facade' ) );
		$this->assertContains( 'swiper-lazy', $rules->get( 'lazy_exclude' ) );
		$this->assertContains( 'elementor-video', $rules->get( 'facade_exclude' ) );
	}

	private function woo_env( array $options = array() ): FakeEnvironment {
		$env          = new FakeEnvironment();
		$env->classes = array( 'WooCommerce' );
		$env->options = array_merge(
			array(
				'permalink_structure'          => '/%postname%/',
				'woocommerce_cart_page_id'     => 10,
				'woocommerce_checkout_page_id' => 11,
				'woocommerce_myaccount_page_id' => 12,
				'woocommerce_shop_page_id'     => 13,
			),
			$options
		);
		$env->uris    = array(
			10 => array( 'cart', 'warenkorb' ),
			11 => array( 'checkout' ),
			12 => array( 'customer/my-account' ),
			13 => array( 'shop' ),
		);
		return $env;
	}

	public function test_woocommerce_pages_scripts_and_cookies(): void {
		$profile = new WooCommerceProfile( $this->woo_env() );
		$this->assertTrue( $profile->applies() );

		$rules = new Rules();
		$profile->register( $rules );

		$urls = $rules->get( 'cache_exclude_urls' );
		foreach ( array( '/cart/', '/warenkorb/', '/checkout/', '/customer/my-account/', '?add-to-cart=', 'wc-ajax=', '/wc-api/' ) as $expected ) {
			$this->assertContains( $expected, $urls );
		}
		$this->assertNotContains( '/shop/', $urls, 'Shop pages are cached unless prices are geolocated.' );
		$this->assertTrue( Context::url_matches( $urls, '/customer/my-account/orders/' ), 'Account endpoints are below the account page.' );
		$this->assertTrue( Context::url_matches( $urls, '/de/warenkorb/' ), 'Translated pages are excluded too.' );
		$this->assertFalse( Context::url_matches( $urls, '/product/blue-shirt/' ) );

		foreach ( array( 'wc-cart-fragments', 'wc-add-to-cart-variation', 'wc-checkout', 'selectWoo', 'wc-single-product', 'photoswipe-ui-default', 'wc-blocks-checkout', 'jquery-blockui', 'sourcebuster-js', 'wc-order-attribution' ) as $handle ) {
			$this->assertTrue( $rules->matches( 'js_no_delay', array( $handle ) ), $handle );
			$this->assertTrue( $rules->matches( 'js_no_defer', array( $handle ) ), $handle );
		}
		$this->assertTrue( $rules->matches( 'js_no_defer', array( '', 'https://example.test/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js' ) ) );

		$this->assertContains( 'wp_woocommerce_session_', $rules->get( 'cache_exclude_cookies' ) );
		$this->assertContains( 'woocommerce_recently_viewed', $rules->get( 'cache_exclude_cookies' ) );
		foreach ( array( 'woocs_current_currency', 'aelia_cs_selected_currency', 'wmc_current_currency', 'yith_wcmcs_currency', 'wcml_client_currency' ) as $cookie ) {
			$this->assertContains( $cookie, $rules->get( 'cache_vary_cookies' ) );
		}

		$this->assertSame( 0, self::penalty( $rules, 'page_cache' ) );
		$this->assertSame( 15, self::penalty( $rules, 'js_defer' ) );
		$this->assertSame( 20, self::penalty( $rules, 'critical_css' ) );
	}

	public function test_woocommerce_geolocation_excludes_catalog(): void {
		$profile = new WooCommerceProfile(
			$this->woo_env(
				array(
					'woocommerce_default_customer_address' => 'geolocation',
					'woocommerce_permalinks'               => array(
						'product_base'  => '/shop/%product_cat%',
						'category_base' => 'kategorie',
						'tag_base'      => '',
					),
				)
			)
		);

		$rules = new Rules();
		$profile->register( $rules );
		$urls = $rules->get( 'cache_exclude_urls' );

		$this->assertContains( '/shop/', $urls );
		$this->assertContains( '/kategorie/', $urls );
		$this->assertContains( '/product-tag/', $urls );
		$this->assertSame( 10, self::penalty( $rules, 'page_cache' ) );

		// Geolocation with page caching support (AJAX) keeps catalog pages cacheable.
		$ajax = new Rules();
		( new WooCommerceProfile( $this->woo_env( array( 'woocommerce_default_customer_address' => 'geolocation_ajax' ) ) ) )->register( $ajax );
		$this->assertSame( 0, self::penalty( $ajax, 'page_cache' ) );
		$this->assertNotContains( '/product/', $ajax->get( 'cache_exclude_urls' ) );
	}

	public function test_woocommerce_default_catalog_bases_and_plain_permalinks(): void {
		$profile = new WooCommerceProfile( $this->woo_env( array( 'woocommerce_default_customer_address' => 'geolocation' ) ) );
		$this->assertContains( '/product/', $profile->catalog_patterns() );
		$this->assertContains( '/product-category/', $profile->catalog_patterns() );

		$plain = new WooCommerceProfile( $this->woo_env( array( 'permalink_structure' => '' ) ) );
		$rules = new Rules();
		$plain->register( $rules );
		$this->assertTrue( Context::url_matches( $rules->get( 'cache_exclude_urls' ), '/?page_id=10' ) );
		$this->assertFalse( Context::url_matches( $rules->get( 'cache_exclude_urls' ), '/?page_id=100' ) );
	}

	public function test_front_page_is_never_excluded(): void {
		$env                             = $this->woo_env( array( 'permalink_structure' => '' ) );
		$env->options['show_on_front']   = 'page';
		$env->options['page_on_front']   = 10;
		$rules                           = new Rules();
		( new WooCommerceProfile( $env ) )->register( $rules );
		$this->assertFalse( Context::url_matches( $rules->get( 'cache_exclude_urls' ), '/?page_id=10' ) );
	}

	public function test_path_pattern_without_trailing_slash(): void {
		$pattern = AbstractProfile::path_pattern( 'shop/cart', false );
		$this->assertTrue( Context::url_matches( array( $pattern ), '/shop/cart' ) );
		$this->assertTrue( Context::url_matches( array( $pattern ), '/shop/cart/' ) );
		$this->assertTrue( Context::url_matches( array( $pattern ), '/shop/cart?x=1' ) );
		$this->assertFalse( Context::url_matches( array( $pattern ), '/shop/cartoons' ) );
		$this->assertSame( '/cart/', AbstractProfile::path_pattern( '/cart/', true ) );
	}

	public function test_multilingual_home_redirect_and_lang_parameter(): void {
		$env            = new FakeEnvironment();
		$env->constants = array( 'ICL_SITEPRESS_VERSION' => '4.6' );
		$env->options   = array(
			'icl_sitepress_settings' => array(
				'language_negotiation_type' => 3,
				'automatic_redirect'        => 1,
			),
		);
		$profile        = new MultilingualProfile( $env );
		$this->assertTrue( $profile->applies() );

		$rules = new Rules();
		$profile->register( $rules );
		$this->assertContains( 'lang', $rules->get( 'cache_query_keep' ) );

		$urls = $rules->get( 'cache_exclude_urls' );
		$this->assertTrue( Context::url_matches( $urls, '/' ) );
		$this->assertTrue( Context::url_matches( $urls, 'https://example.test/?utm_source=x' ) );
		$this->assertFalse( Context::url_matches( $urls, '/about/' ) );

		$pattern = MultilingualProfile::home_pattern( '/blog/' );
		$this->assertTrue( Context::url_matches( array( $pattern ), '/blog/' ) );
		$this->assertTrue( Context::url_matches( array( $pattern ), '/blog' ) );
		$this->assertFalse( Context::url_matches( array( $pattern ), '/blog/post/' ) );

		// Polylang without browser detection needs no exclusion.
		$pll          = new FakeEnvironment();
		$pll->plugins = array( 'polylang' );
		$pll->options = array( 'polylang' => array( 'browser' => 0 ) );
		$rules        = new Rules();
		( new MultilingualProfile( $pll ) )->register( $rules );
		$this->assertSame( array(), $rules->get( 'cache_exclude_urls' ) );
	}

	public function test_forms_are_never_delayed(): void {
		$env          = new FakeEnvironment();
		$env->plugins = array( 'contact-form-7', 'wpforms-lite' );
		$profile      = new FormsProfile( $env );
		$this->assertTrue( $profile->applies() );
		$this->assertStringContainsString( 'Contact Form 7', $profile->name() );

		$rules = new Rules();
		$profile->register( $rules );
		$this->assertTrue( $rules->matches( 'js_no_delay', array( 'contact-form-7' ) ) );
		$this->assertTrue( $rules->matches( 'js_no_delay', array( 'wpforms' ) ) );
		$this->assertFalse( $rules->matches( 'js_no_defer', array( 'contact-form-7' ) ) );
		$this->assertSame( 10, self::penalty( $rules, 'js_defer' ) );
	}

	public function test_divi_theme_detection_and_community_heartbeat(): void {
		$env        = new FakeEnvironment();
		$env->theme = 'Divi';
		$this->assertTrue( ( new DiviProfile( $env ) )->applies() );

		$community          = new FakeEnvironment();
		$community->plugins = array( 'bbpress' );
		$profile            = new CommunityProfile( $community );
		$this->assertTrue( $profile->applies() );
		$rules = new Rules();
		$profile->register( $rules );
		$this->assertNotNull( $rules->disabled_reason( 'heartbeat_frontend' ) );
	}

	public function test_membership_pages(): void {
		$env          = new FakeEnvironment();
		$env->plugins = array( 'paid-memberships-pro' );
		$env->options = array(
			'permalink_structure'   => '/%postname%',
			'pmpro_account_page_id' => 5,
			'pmpro_login_page_id'   => 6,
		);
		$env->uris    = array(
			5 => array( 'membership-account' ),
			6 => array( 'login' ),
		);
		$profile      = new MembershipProfile( $env );
		$this->assertTrue( $profile->applies() );

		$rules = new Rules();
		$profile->register( $rules );
		$urls = $rules->get( 'cache_exclude_urls' );
		$this->assertTrue( Context::url_matches( $urls, '/membership-account' ) );
		$this->assertTrue( Context::url_matches( $urls, '/login' ) );
		$this->assertFalse( Context::url_matches( $urls, '/login-help' ) );
		$this->assertSame( 5, self::penalty( $rules, 'page_cache' ) );
	}
}
