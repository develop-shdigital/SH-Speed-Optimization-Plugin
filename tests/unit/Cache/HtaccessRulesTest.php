<?php
/**
 * Tests for the browser caching .htaccess rules.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Modules\BrowserCache\HtaccessRules;

final class HtaccessRulesTest extends TestCase {

	public function test_every_directive_is_inside_an_ifmodule_guard(): void {
		$depth = 0;
		foreach ( explode( "\n", HtaccessRules::rules( true ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( 0 === strpos( $line, '<IfModule' ) ) {
				++$depth;
				continue;
			}
			if ( '</IfModule>' === $line ) {
				--$depth;
				continue;
			}
			$this->assertGreaterThan( 0, $depth, 'Unguarded directive: ' . $line );
		}
		$this->assertSame( 0, $depth, 'IfModule blocks are balanced.' );
	}

	public function test_html_is_never_cached_by_browsers(): void {
		$rules = HtaccessRules::rules( true );

		$this->assertStringNotContainsString( 'ExpiresByType text/html', $rules );
		$this->assertStringNotContainsString( 'ExpiresDefault "access plus 1 year"' . "\n</IfModule>", $rules, 'No global default expiry.' );

		foreach ( explode( "\n", $rules ) as $line ) {
			if ( false !== stripos( $line, 'html' ) ) {
				$this->assertMatchesRegularExpression( '/^(#|\s*AddOutputFilterByType)/', $line, 'HTML only appears in comments and compression: ' . $line );
			}
			if ( false !== strpos( $line, 'FilesMatch "' ) ) {
				$this->assertDoesNotMatchRegularExpression( '/html?|php/i', $line );
			}
		}

		preg_match_all( '/<FilesMatch "([^"]+)">\s*Header set Cache-Control "([^"]+)"/', $rules, $matches, PREG_SET_ORDER );
		$this->assertNotEmpty( $matches );
		foreach ( $matches as $match ) {
			if ( false !== strpos( $match[2], 'immutable' ) ) {
				$this->assertStringContainsString( 'css|js', $match[1], 'Only versioned code and fonts are immutable.' );
			}
			$this->assertStringContainsString( 'public, max-age=', $match[2] );
		}
		$this->assertStringContainsString( 'max-age=31536000, immutable', $rules );
	}

	public function test_mime_types_and_compression(): void {
		$rules = HtaccessRules::rules( true );
		foreach ( array( 'AddType image/webp .webp', 'AddType image/avif .avif', 'AddType font/woff2 .woff2' ) as $directive ) {
			$this->assertStringContainsString( $directive, $rules );
		}
		$this->assertStringContainsString( 'AddOutputFilterByType DEFLATE', $rules );
		$this->assertStringContainsString( 'AddOutputFilterByType BROTLI_COMPRESS', $rules );
		$this->assertStringContainsString( '<IfModule mod_filter.c>', $rules );

		$without = HtaccessRules::rules( false );
		$this->assertStringNotContainsString( 'DEFLATE', $without );
		$this->assertStringNotContainsString( 'BROTLI', $without );
		$this->assertStringContainsString( HtaccessRules::SIGNATURE, $without );
	}

	public function test_nginx_snippet_is_display_only_and_skips_html(): void {
		$snippet = HtaccessRules::nginx_snippet();
		$this->assertStringContainsString( 'expires 1y;', $snippet );
		$this->assertStringContainsString( 'immutable', $snippet );
		$this->assertStringNotContainsString( 'html', strtolower( str_replace( 'text/html', '', $snippet ) ) );
	}

	public function test_block_detection_and_removal(): void {
		$wordpress = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$ours      = "# BEGIN SH Speed Optimizer\n# The directives (lines) between \"BEGIN SH Speed Optimizer\" and \"END SH Speed Optimizer\" are\n" . HtaccessRules::rules( false ) . "\n# END SH Speed Optimizer\n";
		$contents  = $ours . "\n" . $wordpress;

		$this->assertTrue( HtaccessRules::contains_rules( $contents ) );
		$this->assertFalse( HtaccessRules::contains_rules( $wordpress ) );
		$this->assertFalse( HtaccessRules::contains_rules( "# BEGIN SH Speed Optimizer\n# END SH Speed Optimizer\n" ), 'Empty markers are not installed rules.' );

		$stripped = HtaccessRules::strip_block( $contents );
		$this->assertStringNotContainsString( 'SH Speed Optimizer', $stripped );
		$this->assertStringContainsString( $wordpress, $stripped );
		$this->assertSame( $wordpress, HtaccessRules::strip_block( $wordpress ) );
	}

	public function test_max_age_parsing(): void {
		$this->assertSame( 31536000, HtaccessRules::max_age_from_headers( array( 'cache-control' => 'public, max-age=31536000, immutable' ) ) );
		$this->assertSame( 600, HtaccessRules::max_age_from_headers( array( 'Cache-Control' => 'max-age=600' ) ) );
		$this->assertSame(
			86400,
			HtaccessRules::max_age_from_headers(
				array(
					'expires' => 'Wed, 02 Jan 2030 00:00:00 GMT',
					'date'    => 'Tue, 01 Jan 2030 00:00:00 GMT',
				)
			)
		);
		$this->assertNull( HtaccessRules::max_age_from_headers( array( 'cache-control' => 'no-store, max-age=600' ) ) );
		$this->assertNull( HtaccessRules::max_age_from_headers( array() ) );
	}

	public function test_server_detection(): void {
		$this->assertSame( 'apache', HtaccessRules::server( 'Apache/2.4.57 (Debian)' ) );
		$this->assertSame( 'litespeed', HtaccessRules::server( 'LiteSpeed' ) );
		$this->assertSame( 'nginx', HtaccessRules::server( 'nginx/1.25.3' ) );
		$this->assertSame( 'nginx', HtaccessRules::server( 'openresty' ) );
		$this->assertSame( 'iis', HtaccessRules::server( 'Microsoft-IIS/10.0' ) );
		$this->assertSame( 'unknown', HtaccessRules::server( '' ) );
	}
}
