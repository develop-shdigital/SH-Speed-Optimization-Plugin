<?php
/**
 * Tests for the WordPress cleanup optimizations.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cleanup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Modules\Embeds\EmbedScriptOptimization;
use SH\SpeedOptimizer\Modules\Emojis\DisableEmojisOptimization;
use SH\SpeedOptimizer\Modules\Heartbeat\HeartbeatOptimization;
use SH\SpeedOptimizer\Modules\Oembed\OembedDiscoveryOptimization;
use SH\SpeedOptimizer\Modules\WordpressCleanup\HeadCleanupOptimization;
use SH\SpeedOptimizer\Modules\WordpressCleanup\JqueryMigrateOptimization;
use SH\SpeedOptimizer\Modules\WordpressCleanup\PingbackOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Catalog;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\OptimizationInterface;
use SH\SpeedOptimizer\Optimization\Risk;

final class CleanupOptimizationsTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
		Plugin::reset();
	}

	private static function context( array $profile = array(), ?Rules $rules = null, array $pages = array(), array $browser = array() ): AssessmentContext {
		return new AssessmentContext( new SiteProfile( $profile ), $rules ?? new Rules(), new Settings(), $pages, $browser );
	}

	public static function metadata_provider(): array {
		return array(
			array( 'disable_emojis', DisableEmojisOptimization::class, Risk::SAFE, Risk::LEVEL_SAFE, true ),
			array( 'head_cleanup', HeadCleanupOptimization::class, Risk::SAFE, Risk::LEVEL_SAFE, true ),
			array( 'disable_oembed_discovery', OembedDiscoveryOptimization::class, Risk::SAFE, Risk::LEVEL_SAFE, true ),
			array( 'disable_embeds_script', EmbedScriptOptimization::class, Risk::SAFE, Risk::LEVEL_SAFE, true ),
			array( 'disable_self_pingbacks', PingbackOptimization::class, Risk::SAFE, Risk::LEVEL_SAFE, true ),
			array( 'heartbeat_frontend', HeartbeatOptimization::class, Risk::SAFE, Risk::LEVEL_SAFE, true ),
			array( 'remove_jquery_migrate', JqueryMigrateOptimization::class, Risk::HIGH, Risk::LEVEL_EXPERIMENTAL, false ),
		);
	}

	#[DataProvider( 'metadata_provider' )]
	public function test_metadata( string $id, string $class, string $risk, string $level, bool $safe_mode ): void {
		$this->assertSame( $class, Catalog::map()[ $id ], 'Catalog maps the id to this class.' );

		$optimization = new $class( Plugin::instance() );
		$this->assertInstanceOf( OptimizationInterface::class, $optimization );
		$this->assertSame( $id, $optimization->id() );
		$this->assertSame( Category::CLEANUP, $optimization->category() );
		$this->assertSame( $risk, $optimization->risk() );
		$this->assertSame( $level, $optimization->level() );
		$this->assertSame( $safe_mode, $optimization->safe_mode_compatible() );
		$this->assertSame( Risk::LEVEL_SAFE === $level, $optimization->default_enabled() );
		$this->assertNotSame( '', $optimization->name() );
		$this->assertNotSame( '', $optimization->description() );
		$this->assertTrue( $optimization->is_reversible() );
		$this->assertSame( $id, $optimization->to_array()['id'] );
	}

	public function test_registry_instantiates_all_cleanup_optimizations(): void {
		$registry = Plugin::instance()->registry();
		foreach ( array( 'disable_emojis', 'head_cleanup', 'disable_oembed_discovery', 'disable_embeds_script', 'disable_self_pingbacks', 'heartbeat_frontend', 'remove_jquery_migrate' ) as $id ) {
			$this->assertNotNull( $registry->get( $id ), $id );
		}
	}

	public function test_jquery_migrate_requires_browser_verification(): void {
		$optimization = new JqueryMigrateOptimization( Plugin::instance() );
		$this->assertSame( array( OptimizationInterface::REQ_BROWSER ), $optimization->requirements() );
		$this->assertSame( array( 'jquery-core' ), JqueryMigrateOptimization::strip_migrate( array( 'jquery-core', 'jquery-migrate' ) ) );
	}

	public function test_jquery_migrate_assessment(): void {
		$optimization = new JqueryMigrateOptimization( Plugin::instance() );

		$page = array(
			'status'  => 200,
			'scripts' => array( array( 'handle' => 'jquery-core', 'src' => '/wp-includes/js/jquery/jquery.min.js' ) ),
		);
		$this->assertFalse( $optimization->assess( self::context( array(), null, array( '/' => $page ) ) )->applicable );

		$page['scripts'][] = array( 'handle' => 'jquery-migrate', 'src' => '/wp-includes/js/jquery/jquery-migrate.min.js' );
		$assessment        = $optimization->assess(
			self::context(
				array( 'builders' => array( 'elementor' => '3.20' ) ),
				null,
				array( '/' => $page ),
				array( 'front_page' => array( 'warnings' => array( 'JQMIGRATE: jQuery.fn.size() is deprecated' ) ) )
			)
		);
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 5, $assessment->confidence );
	}

	public function test_self_ping_filter_keeps_external_urls(): void {
		$links = array(
			'https://example.test/hello-world/',
			'http://example.test/other-post/',
			'https://www.example.test/third/',
			'https://example.test',
			'https://example.test.evil.com/post/',
			'https://wordpress.org/news/',
			'https://another-site.test/example.test/',
		);

		$this->assertSame(
			array( 'https://example.test.evil.com/post/', 'https://wordpress.org/news/', 'https://another-site.test/example.test/' ),
			PingbackOptimization::remove_self_links( $links, 'https://example.test' )
		);
	}

	public function test_self_ping_filter_respects_subdirectory_installs(): void {
		$this->assertSame(
			array( 'https://example.test/blog-archive/post/', 'https://example.test/shop/' ),
			PingbackOptimization::remove_self_links(
				array( 'https://example.test/blog/post/', 'https://example.test/blog-archive/post/', 'https://example.test/shop/' ),
				'https://example.test/blog/'
			)
		);
	}

	public function test_pre_ping_callback_modifies_by_reference(): void {
		$optimization = new PingbackOptimization( Plugin::instance() );
		$links        = array( 'https://example.test/a/', 'https://elsewhere.test/' );
		$optimization->filter_pre_ping( $links );
		$this->assertSame( array( 'https://elsewhere.test/' ), $links );
	}

	public function test_pingback_header_only_removed_when_pings_closed(): void {
		$headers = array(
			'X-Pingback'   => 'https://example.test/xmlrpc.php',
			'Content-Type' => 'text/html',
		);
		$this->assertSame( $headers, PingbackOptimization::strip_pingback_header( $headers, 'open' ) );
		$this->assertSame( array( 'Content-Type' => 'text/html' ), PingbackOptimization::strip_pingback_header( $headers, 'closed' ) );
	}

	public function test_head_cleanup_feed_rule_and_protected_tags(): void {
		$this->assertTrue( HeadCleanupOptimization::hide_comments_feed_link( 'closed' ) );
		$this->assertFalse( HeadCleanupOptimization::hide_comments_feed_link( 'open' ) );

		$removed = array_column( HeadCleanupOptimization::REMOVED, 1 );
		foreach ( array( 'rel_canonical', 'rest_output_link_wp_head', 'wp_resource_hints', 'feed_links', 'feed_links_extra' ) as $kept ) {
			$this->assertNotContains( $kept, $removed );
		}
		$this->assertContains( 'wp_generator', $removed );
		$this->assertContains( 'wp_shortlink_header', $removed );
	}

	public function test_head_cleanup_assessment_uses_hook_state(): void {
		$plugin = Plugin::instance();

		$hooked = new class( $plugin ) extends HeadCleanupOptimization {
			protected function hooked( string $hook, string $callback ): bool {
				return 'wp_generator' === $callback;
			}
		};
		$assessment = $hooked->assess( self::context() );
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 1, $assessment->data['removable'] );

		$clean = new class( $plugin ) extends HeadCleanupOptimization {
			protected function hooked( string $hook, string $callback ): bool {
				return false;
			}
		};
		$this->assertFalse( $clean->assess( self::context() )->applicable );
	}

	public function test_emoji_assessment(): void {
		$plugin = Plugin::instance();

		$disabled = new class( $plugin ) extends DisableEmojisOptimization {
			protected function emoji_script_hooked(): bool {
				return false;
			}
		};
		$this->assertFalse( $disabled->assess( self::context() )->applicable );

		$enabled = new class( $plugin ) extends DisableEmojisOptimization {
			protected function emoji_script_hooked(): bool {
				return true;
			}
		};
		$assessment = $enabled->assess( self::context() );
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 99, $assessment->confidence );
		$this->assertSame( Assessment::BENEFIT_LOW, $assessment->benefit );
		$this->assertNull( $assessment->handled_by );

		$handled = $enabled->assess(
			self::context(
				array(
					'conflicts' => array(
						'perfmatters' => array(
							'name'     => 'Perfmatters',
							'features' => array( 'emojis', 'cleanup' ),
						),
					),
				)
			)
		);
		$this->assertSame( 'Perfmatters', $handled->handled_by );
	}

	public function test_emoji_resource_hint_filter(): void {
		$urls = array(
			'https://s.w.org/images/core/emoji/15.0.3/svg/',
			array( 'href' => 'https://s.w.org/images/core/emoji/15.0.3/72x72/' ),
			'https://fonts.googleapis.com',
		);
		$this->assertSame( array( 'https://fonts.googleapis.com' ), DisableEmojisOptimization::filter_resource_hints( $urls, 'dns-prefetch' ) );
		$this->assertSame( $urls, DisableEmojisOptimization::filter_resource_hints( $urls, 'preload' ) );
		$this->assertSame( array( 'wordpress', 'wplink' ), DisableEmojisOptimization::filter_tinymce_plugins( array( 'wordpress', 'wpemoji', 'wplink' ) ) );
	}

	public function test_embed_script_assessment_by_version(): void {
		$optimization = new EmbedScriptOptimization( Plugin::instance() );
		$this->assertFalse( $optimization->assess( self::context( array( 'wp' => array( 'version' => '6.6.2' ) ) ) )->applicable );
		$this->assertFalse( $optimization->assess( self::context( array( 'wp' => array( 'version' => '6.4' ) ) ) )->applicable );
		$this->assertTrue( $optimization->assess( self::context( array( 'wp' => array( 'version' => '6.3.5' ) ) ) )->applicable );
	}

	public function test_embed_content_detection_is_conservative(): void {
		$this->assertFalse( EmbedScriptOptimization::content_may_embed( '' ) );
		$this->assertFalse( EmbedScriptOptimization::content_may_embed( '<p>Read <a href="https://example.test/">this</a>.</p>' ) );
		$this->assertTrue( EmbedScriptOptimization::content_may_embed( "Intro\n\nhttps://other-site.test/post/\n\nOutro" ) );
		$this->assertTrue( EmbedScriptOptimization::content_may_embed( '<p>https://other-site.test/post/</p>' ) );
		$this->assertTrue( EmbedScriptOptimization::content_may_embed( '<!-- wp:embed {"url":"https://x.test"} -->' ) );
		$this->assertTrue( EmbedScriptOptimization::content_may_embed( '[embed]https://x.test[/embed]' ) );
		$this->assertTrue( EmbedScriptOptimization::content_may_embed( '<blockquote class="wp-embedded-content">' ) );
	}

	public function test_heartbeat(): void {
		$this->assertSame( array( 'interval' => 120 ), HeartbeatOptimization::apply_interval( array(), 120 ) );
		$this->assertSame( array( 'interval' => 120 ), HeartbeatOptimization::apply_interval( array( 'interval' => 15 ), 120 ) );
		$this->assertSame( array( 'interval' => 300 ), HeartbeatOptimization::apply_interval( array( 'interval' => 300 ), 120 ), 'A longer interval set elsewhere is kept.' );

		$optimization = new HeartbeatOptimization( Plugin::instance() );
		$assessment   = $optimization->assess( self::context() );
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 95, $assessment->confidence );

		$rules = ( new Rules() )->disable( 'heartbeat_frontend', 'Community features need Heartbeat.' );
		$this->assertSame( 'Community features need Heartbeat.', $optimization->assess( self::context( array(), $rules ) )->blocked );

		$handled = $optimization->assess(
			self::context(
				array(
					'conflicts' => array(
						'heartbeat-control' => array(
							'name'     => 'Heartbeat Control',
							'features' => array( 'heartbeat' ),
						),
					),
				)
			)
		);
		$this->assertSame( 'Heartbeat Control', $handled->handled_by );
	}

	public function test_oembed_assessment(): void {
		$plugin  = Plugin::instance();
		$removed = new class( $plugin ) extends OembedDiscoveryOptimization {
			protected function discovery_hooked(): bool {
				return false;
			}
		};
		$this->assertFalse( $removed->assess( self::context() )->applicable );

		$present = new class( $plugin ) extends OembedDiscoveryOptimization {
			protected function discovery_hooked(): bool {
				return true;
			}
		};
		$this->assertTrue( $present->assess( self::context() )->applicable );
	}

	public function test_pingback_assessment(): void {
		$assessment = ( new PingbackOptimization( Plugin::instance() ) )->assess( self::context() );
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 99, $assessment->confidence );
	}
}
