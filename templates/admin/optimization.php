<?php
/**
 * Optimization page shell: categories, database and history.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
?>
<div id="shso-app" class="shso-app" data-shso-view="optimization">
	<div class="shso-toolbar">
		<p class="shso-toolbar__intro"><?php esc_html_e( 'SH Speed Optimizer detects what your site needs and applies only what is safe. Open a card to see the details.', 'sh-speed-optimizer' ); ?></p>
		<label class="shso-switch" for="shso-advanced-toggle">
			<input type="checkbox" id="shso-advanced-toggle" class="shso-switch__input" role="switch" disabled>
			<span class="shso-switch__track" aria-hidden="true"></span>
			<span class="shso-switch__label"><?php esc_html_e( 'Show advanced controls', 'sh-speed-optimizer' ); ?></span>
		</label>
	</div>

	<section class="shso-section" aria-labelledby="shso-h-optimizations">
		<h2 id="shso-h-optimizations" class="shso-section__title"><?php esc_html_e( 'Optimizations', 'sh-speed-optimizer' ); ?></h2>
		<div id="shso-optimizations" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
	</section>

	<section class="shso-section" aria-labelledby="shso-h-database">
		<h2 id="shso-h-database" class="shso-section__title"><?php esc_html_e( 'Database', 'sh-speed-optimizer' ); ?></h2>
		<div id="shso-database" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
	</section>

	<section class="shso-section" aria-labelledby="shso-h-history">
		<h2 id="shso-h-history" class="shso-section__title"><?php esc_html_e( 'Optimization History', 'sh-speed-optimizer' ); ?></h2>
		<div id="shso-history" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
	</section>
</div>
<?php
require __DIR__ . '/partials/footer.php';
