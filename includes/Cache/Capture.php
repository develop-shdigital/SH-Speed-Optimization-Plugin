<?php
/**
 * Captures rendered pages into the cache.
 *
 * The buffer starts at `plugins_loaded` (before the HTML optimization
 * pipeline at `template_redirect`), so it receives the final, optimized HTML.
 * A page is stored only when every rule in store_decision() agrees; anything
 * personal, uncacheable or unusual is passed through untouched.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Diagnostics\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Output capture.
 */
final class Capture {

	/**
	 * Transient holding why the last loopback request was not stored (verification messages).
	 */
	public const LAST_SKIP_TRANSIENT = 'shso_cache_last_skip';

	/**
	 * Maximum replayed headers.
	 */
	private const MAX_HEADERS = 30;

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Delivery decision of this request.
	 *
	 * @var array<string,mixed>
	 */
	private array $decision;

	/**
	 * Collected output.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Whether the buffer was finalized (or discarded).
	 *
	 * @var bool
	 */
	private bool $done = false;

	/**
	 * Constructor.
	 *
	 * @param Plugin              $plugin   Plugin.
	 * @param CacheManager        $cache    Cache manager.
	 * @param array<string,mixed> $decision Delivery decision (action "cache").
	 */
	public function __construct( Plugin $plugin, CacheManager $cache, array $decision ) {
		$this->plugin   = $plugin;
		$this->cache    = $cache;
		$this->decision = $decision;
	}

	/**
	 * Start buffering.
	 */
	public function start(): void {
		ob_start( array( $this, 'handle' ) );
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $chunk Chunk.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* bitmask.
	 */
	public function handle( string $chunk, int $phase ): string {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			// ob_clean()/ob_end_clean(): the output was discarded (file download, redirect …).
			$this->buffer = '';
			if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
				$this->done = true;
			}
			return '';
		}

		$this->buffer .= $chunk;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return ''; // Hold the output until the document is complete.
		}

		$html         = $this->buffer;
		$this->buffer = '';
		if ( $this->done ) {
			return $html;
		}
		$this->done = true;

		try {
			$this->finish( $html );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'Page cache capture failed; the page was not cached.', array( 'error' => $e->getMessage() ), 'cache' );
		}

		return $html;
	}

	/**
	 * Decide and store.
	 *
	 * @param string $html Final HTML.
	 */
	private function finish( string $html ): void {
		$url    = (string) $this->decision['url'];
		$site   = (array) $this->decision['site'];
		$facts  = $this->facts( $html, $site );
		$reason = self::store_decision( $facts );

		/**
		 * Filters whether a rendered page may be stored in the page cache.
		 *
		 * Only called for pages that passed every built-in rule.
		 *
		 * @param bool   $store Default true.
		 * @param string $url   Page URL.
		 */
		if ( '' === $reason && ! apply_filters( 'shso_cache_should_store', true, $url ) ) {
			$reason = 'filter';
		}

		if ( '' === $reason ) {
			$body = $html;
			if ( defined( 'SHSO_DEBUG' ) && SHSO_DEBUG ) {
				$body .= "\n<!-- Cached by SH Speed Optimizer: " . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';
			}

			/**
			 * Filters the lifespan of a cached page in seconds (also called with an
			 * empty URL for the site default). Cannot extend beyond the site lifespan.
			 *
			 * @param int    $seconds Lifespan.
			 * @param string $url     Page URL ('' for the site default).
			 */
			$lifespan = (int) apply_filters( 'shso_cache_lifespan', (int) ( $site['lifespan'] ?? 36000 ), $url );

			$file = $this->cache->storage()->store(
				$this->decision,
				$body,
				self::replayable_headers( $facts['headers'] ),
				$facts['content_type'],
				$lifespan,
				! empty( $site['gzip'] ),
				time()
			);

			if ( null !== $file ) {
				if ( ! headers_sent() ) {
					header( Delivery::HEADER . ': MISS', true );
				}

				/**
				 * Fires after a page was stored in the page cache.
				 *
				 * @param string $url  Page URL.
				 * @param string $file Cache file.
				 */
				do_action( 'shso_cache_generated', $url, $file );
				return;
			}
			$reason = 'write_failed';
		}

		if ( ! headers_sent() ) {
			header( Delivery::HEADER . ': BYPASS', true );
		}
		$this->note_skip( $url, $reason );
	}

	/**
	 * Facts about the finished response.
	 *
	 * @param string              $html HTML.
	 * @param array<string,mixed> $site Site configuration.
	 * @return array<string,mixed>
	 */
	private function facts( string $html, array $site ): array {
		$status  = http_response_code();
		$headers = headers_list();
		$ran     = did_action( 'wp' ) > 0;
		$context = $this->plugin->context();

		return array(
			'status'       => false === $status ? 200 : (int) $status,
			'headers'      => $headers,
			'content_type' => self::content_type( $headers, (string) get_option( 'blog_charset', 'UTF-8' ) ),
			'html'         => $html,
			'query_ran'    => $ran,
			'donotcache'   => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			'logged_in'    => is_user_logged_in(),
			'is_404'       => $ran && is_404(),
			'is_search'    => $ran && is_search(),
			'is_feed'      => $ran && is_feed(),
			'is_preview'   => $ran && is_preview(),
			'is_trackback' => $ran && is_trackback(),
			'is_robots'    => $ran && is_robots(),
			'is_embed'     => $ran && function_exists( 'is_embed' ) && is_embed(),
			'password'     => $ran && is_singular() && post_password_required(),
			'editor'       => $context->is_editor_preview(),
			'woocommerce'  => $ran && function_exists( 'is_cart' ) && ( is_cart() || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ),
			'edd'          => $ran && function_exists( 'edd_is_checkout' ) && edd_is_checkout(),
			'verification' => $context->is_verification(),
			'safe_cookies' => (array) ( $site['safe_cookies'] ?? array() ),
		);
	}

	/**
	 * Whether a response may be stored (pure). Returns '' when it may, otherwise a reason code.
	 *
	 * @param array<string,mixed> $facts Facts (see facts()).
	 */
	public static function store_decision( array $facts ): string {
		$html = (string) ( $facts['html'] ?? '' );

		$checks = array(
			'verification'   => ! empty( $facts['verification'] ),
			'status'         => 200 !== (int) ( $facts['status'] ?? 0 ),
			'content_type'   => false === stripos( (string) ( $facts['content_type'] ?? 'text/html' ), 'text/html' ),
			'too_small'      => strlen( $html ) <= 255,
			'incomplete'     => false === stripos( $html, '</html>' ),
			'no_query'       => empty( $facts['query_ran'] ),
			'donotcachepage' => ! empty( $facts['donotcache'] ),
			'logged_in'      => ! empty( $facts['logged_in'] ),
			'404'            => ! empty( $facts['is_404'] ),
			'search'         => ! empty( $facts['is_search'] ),
			'feed'           => ! empty( $facts['is_feed'] ),
			'preview'        => ! empty( $facts['is_preview'] ),
			'trackback'      => ! empty( $facts['is_trackback'] ),
			'robots'         => ! empty( $facts['is_robots'] ),
			'embed'          => ! empty( $facts['is_embed'] ),
			'password'       => ! empty( $facts['password'] ),
			'editor'         => ! empty( $facts['editor'] ),
			'woocommerce'    => ! empty( $facts['woocommerce'] ),
			'edd'            => ! empty( $facts['edd'] ),
		);
		foreach ( $checks as $reason => $failed ) {
			if ( $failed ) {
				return $reason;
			}
		}

		$headers = array_map( 'strval', (array) ( $facts['headers'] ?? array() ) );
		if ( Delivery::has_unsafe_set_cookie( $headers, (array) ( $facts['safe_cookies'] ?? array() ) ) ) {
			return 'set_cookie';
		}
		foreach ( $headers as $line ) {
			if ( 0 === stripos( $line, 'cache-control:' ) && preg_match( '/no-store|private|no-cache/i', substr( $line, 14 ) ) ) {
				return 'cache_control';
			}
		}

		return '';
	}

	/**
	 * Header lines that are stored and replayed with the cached page (pure).
	 *
	 * @param string[] $headers headers_list() output.
	 * @return string[]
	 */
	public static function replayable_headers( array $headers ): array {
		$out = array();
		foreach ( $headers as $line ) {
			$line = (string) $line;
			if ( Delivery::is_replayable_header( $line ) ) {
				$out[] = $line;
			}
			if ( count( $out ) >= self::MAX_HEADERS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Content type of the response (PHP's default when none was sent).
	 *
	 * @param string[] $headers Header lines.
	 * @param string   $charset Blog charset.
	 */
	public static function content_type( array $headers, string $charset = 'UTF-8' ): string {
		$type = '';
		foreach ( $headers as $line ) {
			if ( 0 === stripos( (string) $line, 'content-type:' ) ) {
				$type = trim( substr( (string) $line, 13 ) );
			}
		}
		if ( '' === $type ) {
			$type = 'text/html; charset=' . ( '' === $charset ? 'UTF-8' : $charset );
		}
		return $type;
	}

	/**
	 * Report why a page was not stored.
	 *
	 * @param string $url    URL.
	 * @param string $reason Reason code.
	 */
	private function note_skip( string $url, string $reason ): void {
		/**
		 * Fires when a page was rendered but not stored in the page cache.
		 *
		 * @param string $url    Page URL.
		 * @param string $reason Reason code (logged_in, set_cookie, cache_control, 404, woocommerce …).
		 */
		do_action( 'shso_url_excluded', $url, $reason );

		$this->plugin->logger()->debug(
			'Page not cached.',
			array(
				'url'    => $url,
				'reason' => $reason,
			),
			'cache'
		);

		// Our own verification loopbacks remember the reason so the dashboard can explain a failed check.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( Loopback::USER_AGENT === $agent ) {
			set_transient( self::LAST_SKIP_TRANSIENT, sanitize_key( $reason ), 10 * MINUTE_IN_SECONDS );
		}
	}
}
