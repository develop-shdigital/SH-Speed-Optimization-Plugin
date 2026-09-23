<?php
/**
 * Shared admin page header: brand, page title, navigation and banners.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data (page, title, pages, urls, emergency).
 */

defined( 'ABSPATH' ) || exit;

$shso_current = (string) $view['page'];
?>
<div class="wrap shso-wrap" data-shso-page="<?php echo esc_attr( $shso_current ); ?>">
	<header class="shso-header">
		<div class="shso-header__brand">
			<svg class="shso-logo" width="36" height="36" viewBox="0 0 36 36" aria-hidden="true" focusable="false">
				<rect width="36" height="36" rx="9" fill="currentColor"/>
				<path d="M8.5 23.5a9.5 9.5 0 1 1 19 0" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
				<path d="M18 23.5l5.2-7.2" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
				<circle cx="18" cy="23.5" r="2.2" fill="#fff"/>
			</svg>
			<div class="shso-header__titles">
				<?php if ( 'overview' !== $shso_current ) : ?>
					<span class="shso-header__eyebrow"><?php esc_html_e( 'SH Speed Optimizer', 'sh-speed-optimizer' ); ?></span>
				<?php endif; ?>
				<h1 class="shso-header__title"><?php echo esc_html( (string) $view['title'] ); ?></h1>
			</div>
		</div>
		<nav class="shso-nav" aria-label="<?php esc_attr_e( 'SH Speed Optimizer pages', 'sh-speed-optimizer' ); ?>">
			<?php foreach ( (array) $view['pages'] as $shso_id => $shso_page ) : ?>
				<a class="shso-nav__link<?php echo $shso_id === $shso_current ? ' is-current' : ''; ?>" href="<?php echo esc_url( (string) $view['urls'][ $shso_id ] ); ?>"<?php echo $shso_id === $shso_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $shso_page['menu'] ); ?></a>
			<?php endforeach; ?>
		</nav>
	</header>
	<hr class="wp-header-end">

	<?php if ( ! empty( $view['emergency'] ) ) : ?>
		<div id="shso-emergency-banner" class="shso-banner shso-banner--poor" role="alert">
			<span class="shso-banner__icon" aria-hidden="true">!</span>
			<p class="shso-banner__text"><?php esc_html_e( 'Emergency Safe Mode is active (SHSO_SAFE_MODE in wp-config.php). All optimizations are bypassed.', 'sh-speed-optimizer' ); ?></p>
		</div>
	<?php endif; ?>

	<noscript>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'SH Speed Optimizer needs JavaScript to show your site\'s status. Please enable JavaScript in your browser and reload this page.', 'sh-speed-optimizer' ); ?></p>
		</div>
	</noscript>

	<div id="shso-notices" class="shso-notices" aria-live="polite"></div>
