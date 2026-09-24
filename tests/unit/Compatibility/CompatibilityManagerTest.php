<?php
/**
 * Tests for rule merging in the compatibility manager.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Compatibility\CompatibilityManager;
use SH\SpeedOptimizer\Compatibility\ProfileInterface;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Plugin;

final class CompatibilityManagerTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
		Plugin::reset();
	}

	protected function tearDown(): void {
		shso_test_reset();
		Plugin::reset();
	}

	private function manager( ?FakeEnvironment $env = null ): CompatibilityManager {
		return new CompatibilityManager( Plugin::instance(), $env ?? new FakeEnvironment() );
	}

	public function test_base_rules_always_apply(): void {
		$rules = $this->manager()->rules();

		$this->assertTrue( $rules->matches( 'js_no_defer', array( 'jquery-core', 'https://example.test/wp-includes/js/jquery/jquery.min.js' ) ) );
		$this->assertTrue( $rules->matches( 'js_no_delay', array( '', 'https://js.stripe.com/v3/' ) ) );
		$this->assertTrue( $rules->matches( 'js_no_delay', array( '', 'https://www.google.com/recaptcha/api.js' ) ) );
		$this->assertTrue( $rules->matches( 'js_no_delay', array( '', '', 'document.write("<p>")' ) ) );
		$this->assertTrue( $rules->matches( 'js_no_delay', array( 'cookiebot', 'https://consent.cookiebot.com/uc.js' ) ) );
		$this->assertFalse( $rules->matches( 'js_no_delay', array( 'google-analytics', 'https://www.googletagmanager.com/gtag/js?id=G-1' ) ) );

		$this->assertContains( 'utm_source', $rules->get( 'cache_query_ignore' ) );
		$this->assertContains( 'fbclid', $rules->get( 'cache_query_ignore' ) );
		$this->assertContains( 'wordpress_logged_in_', $rules->get( 'cache_exclude_cookies' ) );
		$this->assertContains( '/wp-admin', $rules->get( 'cache_exclude_urls' ) );
		$this->assertContains( 'pll_language', $rules->get( 'cache_safe_cookies' ) );
		$this->assertSame( array( 'jQuery', '$' ), $rules->get( 'inline_globals' )['jquery-core'] );
		$this->assertContains( 'skip-lazy', $rules->get( 'lazy_exclude' ) );

		// The core profile always applies; nothing else on a bare site.
		$this->assertSame( array( 'WordPress core scripts' ), $rules->profiles() );
		$this->assertNull( $rules->disabled_reason( 'js_delay_all' ) );
	}

	public function test_settings_exclusions_are_merged(): void {
		update_option(
			'shso_settings',
			array(
				'exclude_js'      => array( 'my-slider.js' ),
				'exclude_css'     => array( 'critical-theme.css' ),
				'exclude_urls'    => array( '/landing/*' ),
				'exclude_cookies' => array( 'my_session' ),
			)
		);

		$rules = $this->manager()->rules();

		$this->assertContains( 'my-slider.js', $rules->get( 'js_no_defer' ) );
		$this->assertContains( 'my-slider.js', $rules->get( 'js_no_delay' ) );
		$this->assertContains( 'my-slider.js', $rules->get( 'js_no_minify' ) );
		$this->assertContains( 'critical-theme.css', $rules->get( 'css_no_optimize' ) );
		$this->assertContains( '/landing/*', $rules->get( 'cache_exclude_urls' ) );
		$this->assertContains( 'my_session', $rules->get( 'cache_exclude_cookies' ) );
	}

	public function test_apply_settings_accepts_newline_strings(): void {
		$rules = new Rules();
		CompatibilityManager::apply_settings( $rules, array( 'exclude_js' => "a.js\n\nb.js" ) );
		$this->assertSame( array( 'a.js', 'b.js' ), $rules->get( 'js_no_delay' ) );
	}

	public function test_profiles_from_environment_and_filter(): void {
		$env            = new FakeEnvironment();
		$env->constants = array( 'ELEMENTOR_VERSION' => '3.20.0' );
		$env->classes   = array( 'WooCommerce' );

		add_filter(
			'shso_compatibility_rules',
			static function ( Rules $rules ) {
				return $rules->add( 'js_no_delay', array( 'added-by-filter' ) );
			}
		);

		$manager = $this->manager( $env );
		$rules   = $manager->rules();

		$ids = array_map(
			static function ( ProfileInterface $profile ) {
				return $profile->id();
			},
			$manager->profiles()
		);
		$this->assertSame( array( 'gutenberg', 'elementor', 'woocommerce' ), $ids );
		$this->assertContains( 'Elementor', $rules->profiles() );
		$this->assertContains( 'WooCommerce', $rules->profiles() );
		$this->assertContains( 'added-by-filter', $rules->get( 'js_no_delay' ) );
		$this->assertNotNull( $rules->disabled_reason( 'js_delay_all' ) );

		$diagnostics = $manager->diagnostics();
		$this->assertCount( count( CompatibilityManager::PROFILES ), $diagnostics['profiles'] );
		$this->assertArrayHasKey( 'lists', $diagnostics['rules'] );
	}

	public function test_failing_profile_is_skipped(): void {
		add_filter(
			'shso_compatibility_profiles',
			static function ( array $profiles ) {
				$profiles[] = new class() implements ProfileInterface {
					public function id(): string {
						return 'broken';
					}
					public function name(): string {
						return 'Broken';
					}
					public function applies(): bool {
						return true;
					}
					public function register( Rules $rules ): void {
						throw new \RuntimeException( 'broken profile' );
					}
				};
				return $profiles;
			}
		);

		$rules = $this->manager()->rules();
		$this->assertNotContains( 'Broken', $rules->profiles() );
		$this->assertContains( 'jquery-core', $rules->get( 'js_no_defer' ) );
	}

	public function test_rules_are_memoized_and_rebuilt_once_after_theme_setup(): void {
		$manager = $this->manager();
		$first   = $manager->rules();
		$this->assertSame( $first, $manager->rules(), 'Memoized before the theme is loaded.' );

		do_action( 'after_setup_theme' );
		$second = $manager->rules();
		$this->assertNotSame( $first, $second, 'Rebuilt once so theme filters are included.' );
		$this->assertSame( $second, $manager->rules() );
	}
}
