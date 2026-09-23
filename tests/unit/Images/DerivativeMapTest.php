<?php
/**
 * Tests for the WebP derivative path mapping.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Images;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Images\DerivativeMap;

final class DerivativeMapTest extends TestCase {

	private function map(): DerivativeMap {
		return new DerivativeMap( '/srv/wp-content/uploads', 'https://example.test/wp-content/uploads', '/srv/wp-content/uploads/sh-speed-optimizer/webp' );
	}

	public function test_maps_upload_urls_to_derivatives(): void {
		$map = $this->map();

		$this->assertSame(
			'https://example.test/wp-content/uploads/sh-speed-optimizer/webp/2024/05/photo-800x600.jpg.webp',
			$map->derivative_url( 'https://example.test/wp-content/uploads/2024/05/photo-800x600.jpg' )
		);
		$this->assertSame(
			'//example.test/wp-content/uploads/sh-speed-optimizer/webp/2024/05/a%20b.png.avif',
			$map->derivative_url( '//example.test/wp-content/uploads/2024/05/a%20b.png', 'avif' )
		);
		$this->assertSame(
			'/wp-content/uploads/sh-speed-optimizer/webp/a.jpeg.webp',
			$map->derivative_url( '/wp-content/uploads/a.jpeg', 'webp', 'example.test' )
		);

		$info = $map->from_url( 'http://example.test/wp-content/uploads/2024/05/a%20b.png' );
		$this->assertSame( '2024/05/a b.png', $info['rel'] );
		$this->assertSame( '/srv/wp-content/uploads/sh-speed-optimizer/webp/2024/05/a b.png.webp', $map->derivative_path( $info['rel'] ) );
	}

	public function test_refuses_unsafe_or_foreign_urls(): void {
		$map = $this->map();

		$this->assertNull( $map->from_url( 'https://example.test/wp-content/uploads/../wp-config.php' ) );
		$this->assertNull( $map->from_url( 'https://example.test/wp-content/uploads/%2E%2E/%2E%2E/wp-config.jpg' ) );
		$this->assertNull( $map->from_url( 'https://example.test/wp-content/uploads/2024/a.gif' ) );
		$this->assertNull( $map->from_url( 'https://example.test/wp-content/uploads/2024/a.php' ) );
		$this->assertNull( $map->from_url( 'https://example.test/wp-content/uploads/a.jpg?resize=300' ) );
		$this->assertNull( $map->from_url( 'https://example.test/wp-content/uploads/sh-speed-optimizer/webp/a.jpg' ) );
		$this->assertNull( $map->from_url( 'https://cdn.test/wp-content/uploads/a.jpg' ) );
		$this->assertNull( $map->from_url( 'https://example.test/wp-content/themes/x/a.jpg' ) );
		$this->assertNull( $map->from_url( '/wp-content/uploads/a.jpg', 'other.test' ) );
		$this->assertNull( $map->derivative_path( '../../etc/passwd.jpg' ) );
		$this->assertNull( $map->derivative_path( '2024/a.jpg', 'php' ) );
	}

	public function test_relative_from_file_stays_inside_uploads(): void {
		$map = $this->map();
		$this->assertSame( '2024/05/a.jpg', $map->relative_from_file( '/srv/wp-content/uploads/2024/05/a.jpg' ) );
		$this->assertNull( $map->relative_from_file( '/srv/wp-content/uploads/../a.jpg' ) );
		$this->assertNull( $map->relative_from_file( '/etc/a.jpg' ) );
		$this->assertNull( $map->relative_from_file( '/srv/wp-content/uploads/sh-speed-optimizer/webp/a.jpg' ) );
	}
}
