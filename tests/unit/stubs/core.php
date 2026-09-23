<?php
/**
 * Additional stubs for core/engine tests.
 *
 * @package SH\SpeedOptimizer\Tests
 */

// phpcs:ignoreFile

if ( ! defined( 'KB_IN_BYTES' ) ) {
	define( 'KB_IN_BYTES', 1024 );
}
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}
if ( ! defined( 'GB_IN_BYTES' ) ) {
	define( 'GB_IN_BYTES', 1024 * 1024 * 1024 );
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$units = array( 'GB' => GB_IN_BYTES, 'MB' => MB_IN_BYTES, 'KB' => KB_IN_BYTES, 'B' => 1 );
		foreach ( $units as $unit => $size ) {
			if ( (float) $bytes >= $size ) {
				return number_format( $bytes / $size, $decimals ) . ' ' . $unit;
			}
		}
		return '0 B';
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, $decimals );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, null === $timestamp ? time() : $timestamp );
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		return array_map(
			static function ( $item ) use ( $field ) {
				return is_array( $item ) ? ( $item[ $field ] ?? null ) : ( $item->$field ?? null );
			},
			(array) $list
		);
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = $args[1] ?? '';
		} else {
			$params = array( $args[0] => $args[1] );
			$url    = $args[2] ?? '';
		}
		$sep = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $sep . http_build_query( $params );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
