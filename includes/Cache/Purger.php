<?php
/**
 * Automatic cache purging.
 *
 * Content changes queue the affected URLs; the actual purge runs once at the
 * end of the request (one save → one purge, however many hooks fire). Site
 * wide changes (menus, widgets, theme, plugins, permalinks, core options)
 * purge everything. Nothing is ever purged during a regular visitor page view.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

use SH\SpeedOptimizer\Core\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Purge hooks.
 */
final class Purger {

	/**
	 * More queued posts than this in one request purge the whole site instead.
	 */
	private const BULK_THRESHOLD = 50;

	/**
	 * Core options whose change affects every page.
	 */
	private const SITE_OPTIONS = array(
		'blogname',
		'blogdescription',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'date_format',
		'time_format',
		'WPLANG',
		'siteurl',
		'home',
		'permalink_structure',
		'category_base',
		'tag_base',
		'site_icon',
		'timezone_string',
		'gmt_offset',
		'start_of_week',
	);

	/**
	 * Post types whose content is shown on many pages.
	 */
	private const SITE_WIDE_TYPES = array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block', 'elementor_library', 'et_pb_layout', 'bricks_template', 'ct_template', 'fl-builder-template' );

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Queued URLs (url => true).
	 *
	 * @var array<string,bool>
	 */
	private array $urls = array();

	/**
	 * Queued post ids (id => true).
	 *
	 * @var array<int,bool>
	 */
	private array $posts = array();

	/**
	 * Reason when everything must be purged ('' = no full purge).
	 *
	 * @var string
	 */
	private string $all = '';

	/**
	 * Whether the shutdown flush is hooked.
	 *
	 * @var bool
	 */
	private bool $hooked = false;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register the purge hooks (all request contexts).
	 */
	public function register(): void {
		// Posts.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_post_id' ) );
		add_action( 'before_delete_post', array( $this, 'on_post_id' ) );
		add_action( 'trashed_post', array( $this, 'on_post_id' ) );
		add_action( 'deleted_post', array( $this, 'on_post_id' ) );
		add_action( 'clean_post_cache', array( $this, 'on_clean_post_cache' ), 10, 2 );

		// WooCommerce stock.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );

		// Comments.
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
		add_action( 'edit_comment', array( $this, 'on_edit_comment' ) );
		add_action( 'delete_comment', array( $this, 'on_delete_comment' ), 10, 2 );

		// Terms.
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );

		// Site wide changes.
		add_action( 'wp_update_nav_menu', array( $this, 'on_menu' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'on_widgets' ) );
		add_action( 'updated_option', array( $this, 'on_updated_option' ) );
		add_action( 'customize_save_after', array( $this, 'on_customizer' ) );
		add_action( 'switch_theme', array( $this, 'on_theme' ) );
		add_action( 'activated_plugin', array( $this, 'on_plugins' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_plugins' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ) );
		add_action( 'elementor/core/files/clear_cache', array( $this, 'on_builder_css' ) );
		foreach ( self::SITE_OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'on_site_option' ) );
		}
		add_action( 'update_option_' . \SH\SpeedOptimizer\Core\State::OPTION, array( $this, 'on_state_changed' ), 10, 2 );

		/**
		 * Purge the whole page cache of the current site, e.g. do_action( 'shso_purge_all' ).
		 *
		 * @param string $reason Optional reason (logged in debug mode).
		 */
		add_action( 'shso_purge_all', array( $this, 'on_purge_all_action' ) );
	}

	// ---------------------------------------------------------------------
	// Posts.
	// ---------------------------------------------------------------------

	/**
	 * Status transitions: publish, unpublish, schedule → publish.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( 'publish' === $old_status && 'publish' !== $new_status && is_object( $post ) ) {
			// Unpublished: purge the URL it had while it was public.
			$public              = clone $post;
			$public->post_status = 'publish';
			$this->queue_post( $public );
			return;
		}
		$this->queue_post( $post );
	}

	/**
	 * Saves of published posts.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( is_object( $post ) && 'publish' === $post->post_status ) {
			$this->queue_post( $post );
		}
	}

	/**
	 * Trash/delete hooks (URLs are computed before the post disappears).
	 *
	 * @param int $post_id Post id.
	 */
	public function on_post_id( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && in_array( $post->post_status, array( 'publish', 'trash' ), true ) ) {
			if ( 'trash' === $post->post_status ) {
				$post              = clone $post;
				$post->post_status = 'publish';
				$post->post_name   = (string) preg_replace( '/__trashed(-\d+)?$/', '', (string) $post->post_name );
			}
			$this->queue_post( $post );
		}
	}

	/**
	 * Programmatic updates.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_clean_post_cache( $post_id, $post = null ): void {
		if ( is_object( $post ) && 'publish' === $post->post_status ) {
			$this->queue_post( $post );
		}
	}

	/**
	 * WooCommerce stock change of a product or variation.
	 *
	 * @param object $product WC_Product.
	 */
	public function on_product( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
		$this->queue_post( $id );
	}

	/**
	 * WooCommerce stock status change.
	 *
	 * @param int    $product_id Product id.
	 * @param string $status     Stock status.
	 * @param object $product    WC_Product.
	 */
	public function on_stock_status( $product_id, $status = '', $product = null ): void {
		if ( is_object( $product ) ) {
			$this->on_product( $product );
			return;
		}
		$this->queue_post( (int) $product_id );
	}

	/**
	 * Queue a post and its related URLs.
	 *
	 * @param \WP_Post|int $post Post or id.
	 */
	public function queue_post( $post ): void {
		if ( $this->is_visitor_request() ) {
			return;
		}
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post );
		if ( ! $post instanceof \WP_Post || isset( $this->posts[ (int) $post->ID ] ) ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || in_array( $post->post_type, array( 'nav_menu_item', 'revision', 'customize_changeset', 'oembed_cache' ), true ) ) {
			return;
		}
		// Templates, template parts, global styles, block menus, synced patterns and builder
		// templates (headers/footers) appear on many pages: purge everything.
		if ( in_array( $post->post_type, self::SITE_WIDE_TYPES, true ) ) {
			$this->queue_all( 'post_type:' . $post->post_type );
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$this->posts[ (int) $post->ID ] = true;
		$this->hook_flush();

		if ( '' !== $this->all ) {
			return;
		}
		if ( count( $this->posts ) > self::BULK_THRESHOLD ) {
			$this->all  = 'bulk';
			$this->urls = array();
			return;
		}
		foreach ( $this->cache->post_urls( $post ) as $url ) {
			$this->urls[ $url ] = true;
		}
	}

	// ---------------------------------------------------------------------
	// Comments and terms.
	// ---------------------------------------------------------------------

	/**
	 * New comment.
	 *
	 * @param int        $comment_id Comment id.
	 * @param int|string $approved   1, 0 or 'spam'.
	 */
	public function on_comment_post( $comment_id, $approved ): void {
		if ( 1 === (int) $approved ) {
			$this->queue_comment( $comment_id );
		}
	}

	/**
	 * Comment status change.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 */
	public function on_comment_status( $new_status, $old_status, $comment ): void {
		if ( 'approved' === $new_status || 'approved' === $old_status ) {
			$this->queue_comment( $comment );
		}
	}

	/**
	 * Comment edited.
	 *
	 * @param int $comment_id Comment id.
	 */
	public function on_edit_comment( $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		if ( is_object( $comment ) && '1' === (string) $comment->comment_approved ) {
			$this->queue_comment( $comment );
		}
	}

	/**
	 * Comment deleted.
	 *
	 * @param int         $comment_id Comment id.
	 * @param \WP_Comment $comment    Comment.
	 */
	public function on_delete_comment( $comment_id, $comment = null ): void {
		$comment = is_object( $comment ) ? $comment : get_comment( (int) $comment_id );
		if ( is_object( $comment ) && '1' === (string) $comment->comment_approved ) {
			$this->queue_comment( $comment );
		}
	}

	/**
	 * Queue the post of a comment.
	 *
	 * @param \WP_Comment|int $comment Comment.
	 */
	private function queue_comment( $comment ): void {
		$comment = is_object( $comment ) ? $comment : get_comment( (int) $comment );
		if ( is_object( $comment ) && ! empty( $comment->comment_post_ID ) ) {
			$this->queue_post( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Term edited.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ): void {
		$this->queue_term( (int) $term_id, (string) $taxonomy );
	}

	/**
	 * Term about to be deleted (its link is computed while it still exists).
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_pre_delete_term( $term_id, $taxonomy ): void {
		$this->queue_term( (int) $term_id, (string) $taxonomy );
	}

	/**
	 * Queue a term archive.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	private function queue_term( int $term_id, string $taxonomy ): void {
		if ( $this->is_visitor_request() || '' !== $this->all || in_array( $taxonomy, array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' ), true ) ) {
			return;
		}
		foreach ( $this->cache->term_urls( $term_id, $taxonomy ) as $url ) {
			$this->urls[ $url ] = true;
		}
		$this->hook_flush();
	}

	// ---------------------------------------------------------------------
	// Site wide changes.
	// ---------------------------------------------------------------------

	/**
	 * Navigation menu updated.
	 */
	public function on_menu(): void {
		$this->queue_all( 'menu' );
	}

	/**
	 * Widgets updated.
	 */
	public function on_widgets(): void {
		$this->queue_all( 'widgets' );
	}

	/**
	 * Widget settings changed. (Theme modifications are covered by the Customizer
	 * save hook; some themes rewrite theme_mods on every admin page view.)
	 *
	 * @param string $option Option name.
	 */
	public function on_updated_option( $option ): void {
		$option = (string) $option;
		if ( 0 === strpos( $option, 'widget_' ) ) {
			$this->queue_all( 'option:' . $option );
		}
	}

	/**
	 * Customizer saved.
	 */
	public function on_customizer(): void {
		$this->queue_all( 'customizer' );
	}

	/**
	 * Theme switched.
	 */
	public function on_theme(): void {
		$this->queue_all( 'theme' );
	}

	/**
	 * Plugin activated or deactivated.
	 */
	public function on_plugins(): void {
		$this->queue_all( 'plugins' );
	}

	/**
	 * Core, plugin or theme updated.
	 */
	public function on_upgrade(): void {
		$this->queue_all( 'upgrade' );
	}

	/**
	 * Elementor regenerated its CSS files.
	 */
	public function on_builder_css(): void {
		$this->queue_all( 'elementor_css' );
	}

	/**
	 * A core option affecting every page changed.
	 */
	public function on_site_option(): void {
		$this->queue_all( 'option:' . (string) current_action() );
	}

	/**
	 * The set of active optimizations (or their page exclusions) changed: cached pages are outdated.
	 *
	 * @param mixed $old_value Previous state.
	 * @param mixed $value     New state.
	 */
	public function on_state_changed( $old_value, $value ): void {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$value     = is_array( $value ) ? $value : array();
		$old_ids   = array_keys( (array) ( $old_value['active'] ?? array() ) );
		$new_ids   = array_keys( (array) ( $value['active'] ?? array() ) );
		sort( $old_ids );
		sort( $new_ids );
		if ( $old_ids !== $new_ids || ( $old_value['page_exclusions'] ?? array() ) !== ( $value['page_exclusions'] ?? array() ) ) {
			$this->queue_all( 'optimizations' );
		}
	}

	/**
	 * Explicit purge request (`do_action( 'shso_purge_all' )`): runs immediately.
	 *
	 * @param mixed $reason Reason.
	 */
	public function on_purge_all_action( $reason = '' ): void {
		$this->cache->purge_all( is_string( $reason ) && '' !== $reason ? $reason : 'shso_purge_all' );
	}

	/**
	 * Queue a full purge.
	 *
	 * @param string $reason Reason.
	 */
	public function queue_all( string $reason ): void {
		if ( $this->is_visitor_request() ) {
			return;
		}
		if ( '' === $this->all ) {
			$this->all = $reason;
		}
		$this->urls = array();
		$this->hook_flush();
	}

	// ---------------------------------------------------------------------
	// Flush.
	// ---------------------------------------------------------------------

	/**
	 * Hook the end-of-request flush once.
	 */
	private function hook_flush(): void {
		if ( ! $this->hooked ) {
			$this->hooked = true;
			add_action( 'shutdown', array( $this, 'flush' ), 0 );
		}
	}

	/**
	 * Purge everything that was queued during this request.
	 */
	public function flush(): void {
		$all        = $this->all;
		$urls       = array_keys( $this->urls );
		$this->all  = '';
		$this->urls = array();

		try {
			if ( '' !== $all ) {
				$this->cache->purge_all( $all );
			} elseif ( ! empty( $urls ) ) {
				$this->cache->purge_urls( $urls, 'post' );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Purging must never break the request that triggered it.
		}
	}

	/**
	 * Queued URLs (tests/diagnostics).
	 *
	 * @return array{all:string,urls:string[],posts:int[]}
	 */
	public function queued(): array {
		return array(
			'all'   => $this->all,
			'urls'  => array_keys( $this->urls ),
			'posts' => array_keys( $this->posts ),
		);
	}

	/**
	 * Whether this is a regular visitor page view (never purge there).
	 */
	private function is_visitor_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || Context::is_cli() || Context::is_rest() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}
}
