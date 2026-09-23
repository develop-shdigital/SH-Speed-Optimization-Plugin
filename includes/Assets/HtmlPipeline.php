<?php
/**
 * Output buffer pipeline for frontend HTML.
 *
 * Collects the full HTML document of an eligible frontend request and runs the
 * registered transformers in priority order. Each transformer is isolated:
 * an exception or a result that no longer looks like a complete document
 * discards that transformer's changes only.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

use SH\SpeedOptimizer\Diagnostics\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * HTML pipeline.
 */
final class HtmlPipeline {

	/**
	 * Transformers: [ priority, optimization id, callable ].
	 *
	 * @var array<int,array{0:int,1:string,2:callable}>
	 */
	private array $transformers = array();

	/**
	 * Buffered chunks when output was flushed early.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Whether buffering started.
	 *
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * Ids of transformers that changed the document during this request.
	 *
	 * @var string[]
	 */
	private array $applied = array();

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register a transformer.
	 *
	 * Callable signature: function( HtmlDocument $doc ): void — modify the document in place.
	 * Suggested priorities: 10 cleanup, 20 images, 30 fonts, 40 CSS, 50 JS, 60 third-party, 90 resource hints/preloads.
	 *
	 * @param string   $optimization_id Owning optimization id (for isolation and debugging).
	 * @param callable $transformer     Transformer.
	 * @param int      $priority        Lower runs first.
	 */
	public function add( string $optimization_id, callable $transformer, int $priority = 50 ): void {
		$this->transformers[] = array( $priority, $optimization_id, $transformer );
	}

	/**
	 * Whether any transformer is registered.
	 */
	public function has_transformers(): bool {
		return ! empty( $this->transformers );
	}

	/**
	 * Start buffering.
	 */
	public function start(): void {
		if ( $this->started || ! $this->has_transformers() ) {
			return;
		}
		$this->started = true;
		ob_start( array( $this, 'handle' ) );
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $chunk Chunk.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* bitmask.
	 */
	public function handle( string $chunk, int $phase ): string {
		$this->buffer .= $chunk;

		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return ''; // Hold output until the document is complete.
		}

		$html         = $this->buffer;
		$this->buffer = '';

		return $this->process( $html );
	}

	/**
	 * Transform a complete document.
	 *
	 * @param string $html Markup.
	 */
	public function process( string $html ): string {
		if ( ! $this->is_transformable( $html ) ) {
			return $html;
		}

		usort(
			$this->transformers,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);

		$doc = new HtmlDocument( $html );

		foreach ( $this->transformers as $transformer ) {
			list( , $id, $callable ) = $transformer;
			$before                  = $doc->html();

			try {
				$callable( $doc );
			} catch ( \Throwable $e ) {
				$doc->set_html( $before );
				$this->logger->error(
					'HTML transformer failed; its changes were discarded.',
					array(
						'optimization' => $id,
						'error'        => $e->getMessage(),
						'file'         => basename( $e->getFile() ) . ':' . $e->getLine(),
					),
					'assets'
				);
				continue;
			}

			$after = $doc->html();
			if ( $after === $before ) {
				continue;
			}
			if ( ! $this->still_complete( $before, $after ) ) {
				$doc->set_html( $before );
				$this->logger->error( 'HTML transformer produced an incomplete document; its changes were discarded.', array( 'optimization' => $id ), 'assets' );
				continue;
			}
			$this->applied[] = $id;
		}

		/**
		 * Filters the optimized HTML right before it is sent (and cached).
		 *
		 * @param string   $html    Markup.
		 * @param string[] $applied Ids of optimizations that changed the markup.
		 */
		return (string) apply_filters( 'shso_optimized_html', $doc->html(), $this->applied );
	}

	/**
	 * Ids of optimizations that changed the current document.
	 *
	 * @return string[]
	 */
	public function applied(): array {
		return $this->applied;
	}

	/**
	 * Whether the response is a complete HTML page we may transform.
	 *
	 * @param string $html Markup.
	 */
	private function is_transformable( string $html ): bool {
		if ( strlen( $html ) < 255 || http_response_code() >= 300 && http_response_code() !== 404 ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}

		$doc = new HtmlDocument( $html );
		if ( ! $doc->is_html_page() || false === stripos( $html, '</html>' ) ) {
			return false;
		}

		// AMP pages have strict validation rules.
		if ( preg_match( '#<html[^>]+(\bamp\b|⚡)#i', substr( $html, 0, 2048 ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanity check after a transformer ran.
	 *
	 * @param string $before Markup before.
	 * @param string $after  Markup after.
	 */
	private function still_complete( string $before, string $after ): bool {
		if ( strlen( $after ) < strlen( $before ) * 0.5 ) {
			return false;
		}
		foreach ( array( '</head>', '<body', '</body>', '</html>' ) as $marker ) {
			if ( false !== stripos( $before, $marker ) && false === stripos( $after, $marker ) ) {
				return false;
			}
		}
		return true;
	}
}
