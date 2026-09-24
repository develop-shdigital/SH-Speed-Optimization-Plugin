<?php
/**
 * Active theme.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Theme detection.
 */
final class ThemeDetector {

	/**
	 * Collect the theme section: name, slug, template, version, is_block_theme, is_child,
	 * parent_name, parent_version.
	 *
	 * @return array<string,mixed>
	 */
	public static function collect(): array {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return array();
		}

		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$stylesheet = (string) $theme->get_stylesheet();
		$template   = (string) $theme->get_template();

		$is_block = false;
		try {
			if ( method_exists( $theme, 'is_block_theme' ) ) {
				$is_block = (bool) $theme->is_block_theme();
			} elseif ( function_exists( 'wp_is_block_theme' ) ) {
				$is_block = (bool) wp_is_block_theme();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return array(
			'name'           => (string) $theme->get( 'Name' ),
			'slug'           => $stylesheet,
			'template'       => $template,
			'version'        => (string) $theme->get( 'Version' ),
			'is_block_theme' => $is_block,
			'is_child'       => $stylesheet !== $template,
			'parent_name'    => $parent ? (string) $parent->get( 'Name' ) : '',
			'parent_version' => $parent ? (string) $parent->get( 'Version' ) : '',
		);
	}
}
