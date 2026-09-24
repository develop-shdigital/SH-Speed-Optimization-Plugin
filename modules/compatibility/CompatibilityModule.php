<?php
/**
 * Page builder editor detection.
 *
 * Core's Context::is_editor_preview() recognises builder previews by their
 * query parameters. Some builders render their editor canvas without a
 * distinctive parameter, or expose a reliable API for it; this module plugs
 * those checks into the `shso_is_editor_preview` filter so no optimization
 * (and no page cache) ever touches an editor canvas.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility module.
 */
final class CompatibilityModule {

	/**
	 * Query parameters of editors not covered by Context::is_editor_preview().
	 */
	public const EDITOR_PARAMETERS = array(
		'trp-edit-translation', // TranslatePress visual editor and its preview iframe.
		'vcv-editable',         // Visual Composer (new) editor iframe.
		'vcv-action',
		'breakdance',           // Breakdance builder (?breakdance=builder).
		'breakdance_iframe',
	);

	/**
	 * Whether the filter was added.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Add the editor detection filter. Called by the core at `plugins_loaded`.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'shso_is_editor_preview', array( self::class, 'filter_editor_preview' ), 10, 1 );
	}

	/**
	 * Filter callback for `shso_is_editor_preview`.
	 *
	 * @param mixed $is_preview Current value.
	 */
	public static function filter_editor_preview( $is_preview ): bool {
		if ( $is_preview ) {
			return true;
		}
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presence check.
			return self::has_editor_parameter( array_keys( $_GET ) ) || self::builder_reports_editing();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether any of the query parameter names belongs to an editor.
	 *
	 * @param array<int|string> $names Query parameter names.
	 */
	public static function has_editor_parameter( array $names ): bool {
		foreach ( $names as $name ) {
			if ( in_array( (string) $name, self::EDITOR_PARAMETERS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ask the builders themselves whether their editor renders this request.
	 */
	public static function builder_reports_editing(): bool {
		// Oxygen.
		if ( defined( 'SHOW_CT_BUILDER' ) || defined( 'OXYGEN_IFRAME' ) ) {
			return true;
		}

		// The builder APIs below check the current user; never trigger that before `init`.
		if ( ! did_action( 'init' ) ) {
			return false;
		}

		// Elementor (editor and preview iframe).
		if ( class_exists( '\Elementor\Plugin', false ) && isset( \Elementor\Plugin::$instance ) ) {
			$elementor = \Elementor\Plugin::$instance;
			if ( isset( $elementor->preview ) && is_object( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
				return true;
			}
			if ( isset( $elementor->editor ) && is_object( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
				return true;
			}
		}

		// Divi Visual Builder.
		if ( ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) || ( function_exists( 'et_fb_is_enabled' ) && et_fb_is_enabled() ) ) {
			return true;
		}

		// Bricks.
		foreach ( array( 'bricks_is_builder', 'bricks_is_builder_iframe', 'bricks_is_builder_call' ) as $function ) {
			if ( function_exists( $function ) && call_user_func( $function ) ) {
				return true;
			}
		}

		// Beaver Builder.
		if ( class_exists( 'FLBuilderModel', false ) && method_exists( 'FLBuilderModel', 'is_builder_active' ) && \FLBuilderModel::is_builder_active() ) {
			return true;
		}

		// Breakdance.
		foreach ( array( 'Breakdance\\isRequestFromBuilderIframe', 'Breakdance\\isRequestFromBuilderSsr' ) as $function ) {
			if ( function_exists( $function ) && call_user_func( $function ) ) {
				return true;
			}
		}

		// Thrive Architect.
		if ( function_exists( 'is_editor_page_raw' ) && is_editor_page_raw() ) {
			return true;
		}

		// WPBakery frontend editor.
		if ( ( function_exists( 'vc_is_inline' ) && vc_is_inline() ) || ( function_exists( 'vc_is_page_editable' ) && vc_is_page_editable() ) ) {
			return true;
		}

		return false;
	}
}
