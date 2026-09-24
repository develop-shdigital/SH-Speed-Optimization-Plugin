<?php
/**
 * Cache page shell: page cache, browser cache and object cache.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
?>
<div id="shso-app" class="shso-app" data-shso-view="cache">
	<div class="shso-grid shso-grid--wide">
		<section class="shso-card shso-card--span" aria-labelledby="shso-h-page-cache">
			<h2 id="shso-h-page-cache" class="shso-card__title"><?php esc_html_e( 'Page Cache', 'sh-speed-optimizer' ); ?></h2>
			<div id="shso-cache-page" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
		</section>
		<section class="shso-card" aria-labelledby="shso-h-browser-cache">
			<h2 id="shso-h-browser-cache" class="shso-card__title"><?php esc_html_e( 'Browser Cache', 'sh-speed-optimizer' ); ?></h2>
			<div id="shso-cache-browser" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
		</section>
		<section class="shso-card" aria-labelledby="shso-h-object-cache">
			<h2 id="shso-h-object-cache" class="shso-card__title"><?php esc_html_e( 'Object Cache', 'sh-speed-optimizer' ); ?></h2>
			<div id="shso-cache-object" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
		</section>
	</div>
</div>
<?php
require __DIR__ . '/partials/footer.php';
