<?php
/**
 * Optimization categories (one dashboard card each).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

defined( 'ABSPATH' ) || exit;

/**
 * Category vocabulary.
 */
final class Category {

	public const CACHE       = 'cache';
	public const IMAGES      = 'images';
	public const CSS         = 'css';
	public const JAVASCRIPT  = 'javascript';
	public const FONTS       = 'fonts';
	public const THIRD_PARTY = 'third_party';
	public const CLEANUP     = 'cleanup';
	public const DATABASE    = 'database';

	/**
	 * Categories in the order optimizations are applied (least risky group first).
	 *
	 * @return string[]
	 */
	public static function apply_order(): array {
		return array( self::CLEANUP, self::CACHE, self::IMAGES, self::FONTS, self::CSS, self::JAVASCRIPT, self::THIRD_PARTY, self::DATABASE );
	}

	/**
	 * Translated labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::CACHE       => __( 'Caching', 'sh-speed-optimizer' ),
			self::IMAGES      => __( 'Images', 'sh-speed-optimizer' ),
			self::CSS         => __( 'CSS', 'sh-speed-optimizer' ),
			self::JAVASCRIPT  => __( 'JavaScript', 'sh-speed-optimizer' ),
			self::FONTS       => __( 'Fonts', 'sh-speed-optimizer' ),
			self::THIRD_PARTY => __( 'Third-party content', 'sh-speed-optimizer' ),
			self::CLEANUP     => __( 'WordPress cleanup', 'sh-speed-optimizer' ),
			self::DATABASE    => __( 'Database', 'sh-speed-optimizer' ),
		);
	}

	/**
	 * Translated label.
	 *
	 * @param string $category Category.
	 */
	public static function label( string $category ): string {
		return self::labels()[ $category ] ?? $category;
	}
}
