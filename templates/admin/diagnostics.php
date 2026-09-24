<?php
/**
 * Diagnostics page shell: performance report, PageSpeed Insights, developer diagnostics.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
?>
<div id="shso-app" class="shso-app" data-shso-view="diagnostics">
	<section class="shso-section" aria-labelledby="shso-h-report">
		<h2 id="shso-h-report" class="shso-section__title"><?php esc_html_e( 'Performance Report', 'sh-speed-optimizer' ); ?></h2>
		<div id="shso-report" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
	</section>

	<section class="shso-section" aria-labelledby="shso-h-pagespeed">
		<h2 id="shso-h-pagespeed" class="shso-section__title"><?php esc_html_e( 'PageSpeed Insights', 'sh-speed-optimizer' ); ?></h2>
		<div id="shso-pagespeed" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
	</section>

	<section class="shso-section">
		<details id="shso-developer" class="shso-details shso-developer">
			<summary class="shso-details__summary">
				<span class="shso-details__title"><?php esc_html_e( 'Developer Diagnostics', 'sh-speed-optimizer' ); ?></span>
				<span class="shso-details__hint"><?php esc_html_e( 'Technical details for developers and support.', 'sh-speed-optimizer' ); ?></span>
			</summary>
			<div id="shso-developer-body" class="shso-details__body"></div>
		</details>
	</section>
</div>
<?php
require __DIR__ . '/partials/footer.php';
