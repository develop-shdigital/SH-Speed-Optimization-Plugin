<?php
/**
 * PSR-4 style autoloader.
 *
 * `SH\SpeedOptimizer\Foo\Bar`             → includes/Foo/Bar.php
 * `SH\SpeedOptimizer\Modules\PageCache\X` → modules/page-cache/X.php
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Class autoloader without a Composer runtime dependency.
 */
final class Autoloader {

	private const PREFIX = 'SH\\SpeedOptimizer\\';

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Resolve a class name to a file path.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function path( string $class_name ): ?string {
		if ( 0 !== strncmp( $class_name, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return null;
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::PREFIX ) ) );
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $part ) ) {
				return null;
			}
		}

		$root = dirname( __DIR__ ) . '/';

		if ( 'Modules' === $parts[0] && count( $parts ) >= 3 ) {
			$dir = strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $parts[1] ) );
			return $root . 'modules/' . $dir . '/' . implode( '/', array_slice( $parts, 2 ) ) . '.php';
		}

		return $root . 'includes/' . implode( '/', $parts ) . '.php';
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$file = self::path( $class_name );
		if ( null !== $file && is_readable( $file ) ) {
			require $file;
		}
	}
}
