<?php
/**
 * Tests for the page cache and browser cache optimizations (metadata and detection logic).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\Dropin;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Modules\BrowserCache\BrowserCacheOptimization;
use SH\SpeedOptimizer\Modules\PageCache\PageCacheOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\OptimizationInterface;
use SH\SpeedOptimizer\Optimization\Risk;

final class CacheOptimizationsTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
		@unlink( Dropin::path() );
	}

	protected function tearDown(): void {
		@unlink( Dropin::path() );
		shso_test_reset();
	}

	/**
	 * Assessment context.
	 *
	 * @param array<string,mixed>               $profile  Profile data.
	 * @param array<string,mixed>               $settings Stored settings.
	 * @param array<string,array<string,mixed>> $pages    Page analyses.
	 * @param Rules|null                        $rules    Rules.
	 */
	private function context( array $profile = array(), array $settings = array(), array $pages = array(), ?Rules $rules = null ): AssessmentContext {
		update_option( Settings::OPTION, $settings );
		return new AssessmentContext( new SiteProfile( $profile ), $rules ?? new Rules(), new Settings(), $pages );
	}

	public function test_metadata(): void {
		$page = new PageCacheOptimization( Plugin::instance() );
		$this->assertSame( 'page_cache', $page->id() );
		$this->assertSame( Category::CACHE, $page->category() );
		$this->assertSame( Risk::LOW, $page->risk() );
		$this->assertSame( Risk::LEVEL_SAFE, $page->level() );
		$this->assertTrue( $page->safe_mode_compatible() );
		$this->assertSame( array( OptimizationInterface::REQ_LOOPBACK ), $page->requirements() );

		$browser = new BrowserCacheOptimization( Plugin::instance() );
		$this->assertSame( 'browser_cache', $browser->id() );
		$this->assertSame( Category::CACHE, $browser->category() );
		$this->assertSame( Risk::LOW, $browser->risk() );
		$this->assertSame( Risk::LEVEL_SAFE, $browser->level() );
		$this->assertTrue( $browser->safe_mode_compatible() );
		$this->assertSame( array( OptimizationInterface::REQ_SERVER_CONFIG ), $browser->requirements() );
	}

	public function test_page_cache_turned_off_in_settings_is_blocked(): void {
		$assessment = ( new PageCacheOptimization( Plugin::instance() ) )->assess( $this->context( array(), array( 'page_cache' => false ) ) );
		$this->assertFalse( $assessment->applicable );
		$this->assertSame( 'Page cache is turned off in Settings.', $assessment->blocked );
	}

	public function test_page_cache_benefit_follows_generation_time(): void {
		$optimization = new PageCacheOptimization( Plugin::instance() );

		$slow = $optimization->assess(
			$this->context(
				array(),
				array(),
				array(
					'https://example.test/'       => array(
						'status'        => 200,
						'generation_ms' => 800,
					),
					'https://example.test/about/' => array(
						'status'  => 200,
						'ttfb_ms' => 400,
					),
				)
			)
		);
		$this->assertTrue( $slow->applicable );
		$this->assertSame( Assessment::BENEFIT_HIGH, $slow->benefit );
		$this->assertSame( 600, $slow->data['avg_generation_ms'] );
		$this->assertSame( 90, $slow->confidence );
		$this->assertNull( $slow->handled_by );

		$fast = $optimization->assess(
			$this->context(
				array(),
				array(),
				array(
					'https://example.test/' => array(
						'status'        => 200,
						'generation_ms' => 120,
					),
				)
			)
		);
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $fast->benefit );

		$unknown = $optimization->assess( $this->context() );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $unknown->benefit );
	}

	public function test_page_cache_handled_elsewhere(): void {
		$optimization = new PageCacheOptimization( Plugin::instance() );

		$plugin = $optimization->assess(
			$this->context(
				array(
					'conflicts' => array(
						'wp-rocket' => array(
							'name'     => 'WP Rocket',
							'features' => array( 'page_cache', 'minify_css' ),
						),
					),
				)
			)
		);
		$this->assertSame( 'WP Rocket', $plugin->handled_by );

		$dropin = $optimization->assess(
			$this->context(
				array(
					'cache' => array(
						'advanced_cache'    => array(
							'exists' => true,
							'ours'   => false,
							'owner'  => 'W3 Total Cache',
						),
						'wp_cache_constant' => true,
					),
				)
			)
		);
		$this->assertSame( 'W3 Total Cache', $dropin->handled_by );

		// An inactive leftover drop-in (WP_CACHE off) is noted, not treated as an active cache.
		$leftover = $optimization->assess(
			$this->context(
				array(
					'cache' => array(
						'advanced_cache'    => array(
							'exists' => true,
							'ours'   => false,
							'owner'  => 'WP Super Cache',
						),
						'wp_cache_constant' => false,
					),
				)
			)
		);
		$this->assertNull( $leftover->handled_by );
		$this->assertTrue( $leftover->applicable );

		// Without scan data the file itself is inspected.
		file_put_contents( Dropin::path(), "<?php\n// Cache Enabler advanced cache\n" );
		$file = $optimization->assess( $this->context( array( 'cache' => array( 'wp_cache_constant' => true ) ) ) );
		$this->assertSame( 'Cache Enabler', $file->handled_by );
	}

	public function test_page_cache_compatibility_penalties(): void {
		update_option( 'woocommerce_default_customer_address', 'geolocation' );
		$rules = new Rules();
		$rules->add( 'cache_vary_cookies', array( 'pll_language' ) );

		$assessment = ( new PageCacheOptimization( Plugin::instance() ) )->assess(
			$this->context(
				array(
					'features' => array(
						'woocommerce'  => true,
						'membership'   => array( 'memberpress' ),
						'multilingual' => 'polylang',
					),
				),
				array(),
				array(),
				$rules
			)
		);

		$this->assertSame( 70, $assessment->confidence, '−10 geolocation, −5 membership, −5 language cookie.' );
		$this->assertTrue( $assessment->applicable );
	}

	public function test_browser_cache_not_applicable_on_nginx_or_when_configured(): void {
		$optimization = new BrowserCacheOptimization( Plugin::instance() );

		$nginx = $optimization->assess( $this->context( array( 'server' => array( 'software' => 'nginx' ) ) ) );
		$this->assertFalse( $nginx->applicable );
		$this->assertTrue( $nginx->data['nginx_snippet'] );

		$configured = $optimization->assess(
			$this->context(
				array(
					'server'        => array( 'software' => 'apache' ),
					'browser_cache' => array( 'configured' => true ),
				)
			)
		);
		$this->assertFalse( $configured->applicable );

		$needed = $optimization->assess(
			$this->context(
				array(
					'server'        => array(
						'software'          => 'apache',
						'htaccess_writable' => true,
					),
					'browser_cache' => array( 'configured' => false ),
				)
			)
		);
		$this->assertTrue( $needed->applicable );
		$this->assertNull( $needed->blocked );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $needed->benefit );

		$locked = $optimization->assess(
			$this->context(
				array(
					'server'        => array(
						'software'          => 'litespeed',
						'htaccess_writable' => false,
					),
					'browser_cache' => array( 'configured' => false ),
				)
			)
		);
		$this->assertNotNull( $locked->blocked );

		$other = $optimization->assess(
			$this->context(
				array(
					'server'        => array(
						'software'          => 'apache',
						'htaccess_writable' => true,
					),
					'browser_cache' => array( 'configured' => false ),
					'conflicts'     => array(
						'litespeed-cache' => array(
							'name'     => 'LiteSpeed Cache',
							'features' => array( 'browser_cache' ),
						),
					),
				)
			)
		);
		$this->assertSame( 'LiteSpeed Cache', $other->handled_by );
	}

	public function test_browser_cache_apply_requires_permission(): void {
		update_option( Settings::OPTION, array( 'allow_server_config' => false ) );
		Plugin::instance()->settings()->flush();
		$result = ( new BrowserCacheOptimization( Plugin::instance() ) )->apply();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'shso_browser_cache_permission', $result->get_error_code() );
	}

	public function test_reason_labels_are_plain_language(): void {
		$this->assertStringContainsString( 'cookie', PageCacheOptimization::reason_label( 'set_cookie' ) );
		$this->assertNotSame( '', PageCacheOptimization::reason_label( 'anything-else' ) );
	}
}
