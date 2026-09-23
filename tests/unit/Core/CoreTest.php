<?php
/**
 * Core services: signer, settings, context helpers, jobs, filesystem guard, health score.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Core;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Core\Jobs\Job;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Diagnostics\HealthScore;
use SH\SpeedOptimizer\Security\Signer;

final class CoreTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
	}

	public function test_signer_roundtrip_and_tamper_detection(): void {
		$token   = Signer::sign( array( 'm' => 'candidate', 'o' => array( 'js_defer' ) ), 60 );
		$payload = Signer::verify( $token );
		$this->assertSame( 'candidate', $payload['m'] );
		$this->assertSame( array( 'js_defer' ), $payload['o'] );

		list( $body, $sig ) = explode( '.', $token );
		$forged              = rtrim( strtr( base64_encode( json_encode( array( 'm' => 'candidate', 'o' => array( 'all' ), 'e' => time() + 60 ) ) ), '+/', '-_' ), '=' ) . '.' . $sig;
		$this->assertNull( Signer::verify( $forged ) );
		$this->assertNull( Signer::verify( $body . '.x' . $sig ) );
		$this->assertNull( Signer::verify( 'garbage' ) );
	}

	public function test_signer_rejects_expired_tokens(): void {
		$secret = Signer::ensure_secret();
		$body   = rtrim( strtr( base64_encode( json_encode( array( 'm' => 'baseline', 'e' => time() - 5 ) ) ), '+/', '-_' ), '=' );
		$sig    = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $body, hash( 'sha256', $secret ), true ) ), '+/', '-_' ), '=' );
		$this->assertNull( Signer::verify( $body . '.' . $sig ) );
	}

	public function test_settings_sanitize(): void {
		$clean = Settings::sanitize(
			array(
				'cache_lifespan'  => '9999',
				'cache_mobile'    => 'sometimes',
				'safe_mode'       => 'yes',
				'psi_api_key'     => 'AIza<script>123',
				'overrides'       => array( 'js_defer' => 'on', 'Bad Id!' => 'maybe', 'css_minify' => 'off' ),
				'exclude_urls'    => "/checkout/\n\n/cart/\n<script>alert(1)</script>\n/cart/",
				'exclude_cookies' => array( 'my_cookie', 'bad cookie;' ),
			)
		);

		$this->assertSame( 720, $clean['cache_lifespan'] );
		$this->assertSame( 'auto', $clean['cache_mobile'] );
		$this->assertTrue( $clean['safe_mode'] );
		$this->assertSame( 'AIzascript123', $clean['psi_api_key'] );
		$this->assertSame( array( 'js_defer' => 'on', 'css_minify' => 'off' ), $clean['overrides'] );
		$this->assertSame( array( '/checkout/', '/cart/' ), $clean['exclude_urls'], 'Markup is stripped entirely (like wp_strip_all_tags).' );
		$this->assertSame( array( 'my_cookie', 'badcookie' ), $clean['exclude_cookies'] );
	}

	public function test_settings_defaults_are_safe(): void {
		$defaults = Settings::defaults();
		$this->assertFalse( $defaults['advanced_optimizations'] );
		$this->assertFalse( $defaults['allow_server_config'] );
		$this->assertFalse( $defaults['localize_fonts'] );
		$this->assertFalse( $defaults['rum'] );
		$this->assertLessThanOrEqual( 12, $defaults['cache_lifespan'], 'Must stay below the nonce lifetime.' );
	}

	public function test_url_matching(): void {
		$this->assertTrue( Context::url_matches( array( '/checkout/' ), '/checkout/step/' ) );
		$this->assertTrue( Context::url_matches( array( '/landing/*' ), '/landing/summer/' ) );
		$this->assertTrue( Context::url_matches( array( '#^/p/\d+$#' ), '/p/42' ) );
		$this->assertFalse( Context::url_matches( array( '#invalid(#' ), '/anything' ) );
		$this->assertFalse( Context::url_matches( array( '', '/cart/' ), '/shop/' ) );
	}

	public function test_job_steps_and_progress(): void {
		$job = new Job(
			array(
				'id'     => 'j1',
				'type'   => 'scan',
				'status' => Job::RUNNING,
				'steps'  => array( 'a', 'b', 'c' ),
			)
		);
		$this->assertSame( 'a', $job->current_step() );
		$job->add_steps( array( 'a1', 'a2' ) );
		$job->advance();
		$this->assertSame( 'a1', $job->current_step() );
		$this->assertSame( array( 'a1', 'a2', 'b', 'c' ), $job->remaining_steps() );
		$this->assertSame( 20, $job->progress() );

		$job->request_browser( array( array( 'key' => 'k' ) ) );
		$this->assertSame( Job::AWAIT_BROWSER, $job->status() );
		$job->receive_browser( array( 'k' => array( 'dom' => array() ) ) );
		$this->assertSame( Job::RUNNING, $job->status() );
		$this->assertArrayHasKey( 'k', $job->browser_results() );
	}

	public function test_filesystem_refuses_paths_outside_roots_and_executables(): void {
		$fs   = new Filesystem();
		$root = Filesystem::cache_root();
		@mkdir( $root, 0777, true );

		$this->assertTrue( $fs->is_allowed_path( $root . 'pages/example.test/index.html' ) );
		$this->assertFalse( $fs->is_allowed_path( $root . '../../wp-config.php' ) );
		$this->assertFalse( $fs->is_allowed_path( ABSPATH . 'wp-config.php' ) );
		$this->assertFalse( $fs->is_allowed_path( "{$root}a\0b" ) );

		$this->assertFalse( $fs->write( $root . 'evil.php', '<?php echo 1;' ) );
		$this->assertFalse( $fs->write( $root . 'evil.phtml', 'x' ) );
		$this->assertTrue( $fs->write( $root . 'test/ok.css', 'a{}' ) );
		$this->assertSame( 'a{}', file_get_contents( $root . 'test/ok.css' ) );

		// Symlink escaping the root is not followed.
		$outside = sys_get_temp_dir() . '/shso-outside-' . getmypid();
		@mkdir( $outside );
		@symlink( $outside, $root . 'link' );
		if ( is_link( $root . 'link' ) ) {
			$this->assertFalse( $fs->is_allowed_path( $root . 'link/file.css' ) );
			@unlink( $root . 'link' );
		}
		@rmdir( $outside );

		$this->assertGreaterThan( 0, $fs->delete_tree( $root . 'test' ) );
		$this->assertFileDoesNotExist( $root . 'test/ok.css' );
	}

	public function test_health_score(): void {
		$this->assertNull( HealthScore::calculate( array() )['score'] );

		$good = HealthScore::calculate( array( array( 'severity' => 'good', 'category' => 'cache' ) ) );
		$this->assertSame( 100, $good['score'] );
		$this->assertSame( 'excellent', $good['status'] );

		$findings = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$findings[] = array( 'severity' => 'critical', 'category' => 'images' );
		}
		$capped = HealthScore::calculate( $findings );
		$this->assertSame( 70, $capped['score'], 'One category cannot deduct more than 30 points.' );
		$this->assertSame( 10, $capped['improvements'] );
		$this->assertSame( 'attention', HealthScore::status( 60 ) );
		$this->assertSame( 'required', HealthScore::status( 49 ) );
	}
}
