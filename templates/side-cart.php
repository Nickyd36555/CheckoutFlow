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
		<svg aria-hidden="true" width="30" height="30" viewBox="0 0 48 48" fill="currentColor"><path d="m24 19.3-2.1-2.1 3.7-3.7h-9.1v-3h9.1l-3.7-3.7L24 4.7l7.3 7.3ZM14.5 44q-1.5 0-2.55-1.05-1.05-1.05-1.05-2.55 0-1.5 1.05-2.55Q13 36.8 14.5 36.8q1.5 0 2.55 1.05 1.05 1.05 1.05 2.55 0 1.5-1.05 2.55Q16 44 14.5 44Zm20.2 0q-1.5 0-2.55-1.05-1.05-1.05-1.05-2.55 0-1.5 1.05-2.55 1.05-1.05 2.55-1.05 1.5 0 2.55 1.05 1.05 1.05 1.05 2.55 0 1.5-1.05 2.55Q36.2 44 34.7 44ZM3.1 7V4h5.8l8.5 18.2h14.4l8-14h3.35l-8.1 15.15q-.55.95-1.425 1.525t-1.925.575H16.55l-2.8 5.2H38.3v3H14.2q-1.9 0-2.875-1.5-.975-1.5-.125-3.05l3.2-5.9L7 7Z"/></svg>
		<span class="cf-cart-count" data-count="0">0</span>
	</button>
<?php endif; ?>
