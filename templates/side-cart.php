<?php
/**
 * Side cart shell (no cart data – safe for page caching).
 * Override at yourtheme/checkoutflow/side-cart.php.
 *
 * @package CheckoutFlow
 * @var string $content Placeholder content element.
 */

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

$cf_position = 'bottom-left' === Settings::get( 'cart_icon_position' ) ? 'is-left' : 'is-right';
?>
<div id="cf-cart" class="cf-cart" hidden>
	<div class="cfc-overlay" data-cf-close></div>
	<aside class="cfc-drawer" role="dialog" aria-modal="true" aria-labelledby="cfc-title" tabindex="-1">
		<header class="cfc-head">
			<h2 id="cfc-title" class="cfc-title"><?php echo esc_html( Settings::get( 'cart_heading' ) ); ?> <span class="cf-cart-count" data-count="0">0</span></h2>
			<button type="button" class="cfc-close" data-cf-close aria-label="<?php esc_attr_e( 'Close cart', 'checkoutflow' ); ?>">
				<svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
			</button>
		</header>
		<div class="cfc-notices" role="status" aria-live="polite"></div>
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</aside>
</div>
<?php if ( Settings::get( 'cart_floating_icon' ) ) : ?>
	<button type="button" class="cf-open-cart cfc-fab <?php echo esc_attr( $cf_position ); ?><?php echo Settings::get( 'cart_hide_empty_icon' ) ? ' hide-empty' : ''; ?>" aria-label="<?php esc_attr_e( 'Open cart', 'checkoutflow' ); ?>">
		<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
		<span class="cf-cart-count" data-count="0">0</span>
	</button>
<?php endif; ?>
