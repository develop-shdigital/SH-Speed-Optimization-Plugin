<?php
/**
 * Test double for the compatibility profile environment.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Compatibility;

use SH\SpeedOptimizer\Compatibility\Profiles\Environment;

/**
 * Environment backed by plain arrays.
 */
class FakeEnvironment extends Environment {

	/** @var array<string,mixed> */
	public array $constants = array();

	/** @var string[] */
	public array $classes = array();

	/** @var array<string,mixed> */
	public array $options = array();

	/** @var string[] */
	public array $plugins = array();

	/** @var string */
	public string $theme = '';

	/** @var array<int,string[]> */
	public array $uris = array();

	/** @var string */
	public string $home = '/';

	public function defined( string $name ): bool {
		return array_key_exists( $name, $this->constants );
	}

	public function class_exists( string $name ): bool {
		return in_array( ltrim( $name, '\\' ), $this->classes, true );
	}

	public function function_exists( string $name ): bool {
		return false;
	}

	public function option( string $name, $fallback = false ) {
		return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
	}

	public function plugin_active( string $slug ): bool {
		return in_array( strtolower( $slug ), array_map( 'strtolower', $this->plugins ), true );
	}

	public function template(): string {
		return $this->theme;
	}

	public function stylesheet(): string {
		return $this->theme;
	}

	public function home_path(): string {
		return $this->home;
	}

	public function page_uris( int $page_id ): array {
		return $this->uris[ $page_id ] ?? array();
	}
}
