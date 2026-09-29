<?php
/**
 * Side cart contents. Override at yourtheme/checkoutflow/side-cart-content.php.
 *
 * @package CheckoutFlow
 * @var WC_Cart      $cart
 * @var array|null   $shipping { percent, text }
 * @var WC_Product[] $upsells
 * @var string       $nonce
 */

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

$cf_count = $cart->get_cart_contents_count();
?>
<div class="cf-cart-content" data-count="<?php echo esc_attr( $cf_count ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
<?php if ( $cart->is_empty() ) : ?>
	<div class="cfc-empty">
		<svg aria-hidden="true" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
		<p><?php echo esc_html( Settings::get( 'cart_empty_text' ) ); ?></p>
		<a class="cfc-btn" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php echo esc_html( Settings::get( 'cart_continue_text' ) ); ?></a>
	</div>
<?php else : ?>
	<div class="cfc-body">
		<?php if ( $shipping ) : ?>
			<div class="cfc-shipping<?php echo 100 <= $shipping['percent'] ? ' is-done' : ''; ?>">
				<p><?php echo wp_kses_post( $shipping['text'] ); ?></p>
				<div class="cfc-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $shipping['percent'] ); ?>"><span style="width:<?php echo esc_attr( $shipping['percent'] ); ?>%"></span></div>
			</div>
		<?php endif; ?>

		<ul class="cfc-items">
			<?php
			foreach ( $cart->get_cart() as $cf_key => $cf_item ) :
				/** @var WC_Product $cf_product */
				$cf_product = apply_filters( 'woocommerce_cart_item_product', $cf_item['data'], $cf_item, $cf_key );
				if ( ! $cf_product || ! $cf_product->exists() || $cf_item['quantity'] <= 0 || ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $cf_item, $cf_key ) ) {
					continue;
				}
				$cf_link = apply_filters( 'woocommerce_cart_item_permalink', $cf_product->is_visible() ? $cf_product->get_permalink( $cf_item ) : '', $cf_item, $cf_key );
				$cf_name = apply_filters( 'woocommerce_cart_item_name', $cf_product->get_name(), $cf_item, $cf_key );
				$cf_max  = $cf_product->is_sold_individually() ? 1 : $cf_product->get_max_purchase_quantity();
				?>
				<li class="cfc-item" data-key="<?php echo esc_attr( $cf_key ); ?>">
					<div class="cfc-thumb">
						<?php echo $cf_product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<div class="cfc-info">
						<div class="cfc-name">
							<?php if ( $cf_link ) : ?>
								<a href="<?php echo esc_url( $cf_link ); ?>"><?php echo wp_kses_post( $cf_name ); ?></a>
							<?php else : ?>
								<?php echo wp_kses_post( $cf_name ); ?>
							<?php endif; ?>
						</div>
						<div class="cfc-meta"><?php echo wc_get_formatted_cart_item_data( $cf_item ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
						<div class="cfc-row">
							<?php if ( 1 === $cf_max ) : ?>
								<span class="cfc-qty-fixed"><?php esc_html_e( 'Qty: 1', 'checkoutflow' ); ?></span>
							<?php else : ?>
								<div class="cfc-qty">
									<button type="button" class="cfc-qty-btn" data-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'checkoutflow' ); ?>">&minus;</button>
									<input type="number" class="cfc-qty-input" value="<?php echo esc_attr( $cf_item['quantity'] ); ?>" min="0" <?php echo $cf_max > 0 ? 'max="' . esc_attr( $cf_max ) . '"' : ''; ?> inputmode="numeric" aria-label="<?php esc_attr_e( 'Quantity', 'checkoutflow' ); ?>">
									<button type="button" class="cfc-qty-btn" data-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'checkoutflow' ); ?>">+</button>
								</div>
							<?php endif; ?>
							<span class="cfc-price"><?php echo apply_filters( 'woocommerce_cart_item_subtotal', $cart->get_product_subtotal( $cf_product, $cf_item['quantity'] ), $cf_item, $cf_key ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</div>
					</div>
					<button type="button" class="cfc-remove" aria-label="<?php /* translators: %s: product name */ echo esc_attr( sprintf( __( 'Remove %s from cart', 'checkoutflow' ), wp_strip_all_tags( $cf_name ) ) ); ?>">
						<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $upsells ) : ?>
			<div class="cfc-upsells">
				<h3><?php echo esc_html( Settings::get( 'cart_upsell_heading' ) ); ?></h3>
				<ul>
					<?php foreach ( $upsells as $cf_upsell ) : ?>
						<li class="cfc-upsell">
							<a class="cfc-thumb" href="<?php echo esc_url( $cf_upsell->get_permalink() ); ?>"><?php echo $cf_upsell->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
							<div class="cfc-info">
								<a class="cfc-name" href="<?php echo esc_url( $cf_upsell->get_permalink() ); ?>"><?php echo esc_html( $cf_upsell->get_name() ); ?></a>
								<span class="cfc-price"><?php echo $cf_upsell->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							</div>
							<?php if ( $cf_upsell->is_type( 'simple' ) ) : ?>
								<button type="button" class="cfc-btn cfc-btn-sm cfc-add" data-product="<?php echo esc_attr( $cf_upsell->get_id() ); ?>"><?php esc_html_e( 'Add', 'checkoutflow' ); ?></button>
							<?php else : ?>
								<a class="cfc-btn cfc-btn-sm cfc-btn-ghost" href="<?php echo esc_url( $cf_upsell->get_permalink() ); ?>"><?php esc_html_e( 'Options', 'checkoutflow' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>

	<footer class="cfc-foot">
		<?php if ( Settings::get( 'cart_show_coupon' ) && wc_coupons_enabled() ) : ?>
			<details class="cfc-coupon">
				<summary><?php esc_html_e( 'Have a coupon code?', 'checkoutflow' ); ?></summary>
				<form class="cfc-coupon-form">
					<input type="text" name="code" placeholder="<?php esc_attr_e( 'Coupon code', 'checkoutflow' ); ?>" aria-label="<?php esc_attr_e( 'Coupon code', 'checkoutflow' ); ?>" autocomplete="off" required>
					<button type="submit" class="cfc-btn cfc-btn-sm"><?php esc_html_e( 'Apply', 'checkoutflow' ); ?></button>
				</form>
			</details>
		<?php endif; ?>

		<dl class="cfc-totals">
			<?php foreach ( $cart->get_coupons() as $cf_code => $cf_coupon ) : ?>
				<div class="cfc-total-row cfc-discount">
					<dt>
						<?php
						/* translators: %s: coupon code */
						echo esc_html( sprintf( __( 'Coupon: %s', 'checkoutflow' ), wc_format_coupon_code( $cf_code ) ) );
						?>
						<button type="button" class="cfc-remove-coupon" data-code="<?php echo esc_attr( $cf_code ); ?>"><?php esc_html_e( 'Remove', 'checkoutflow' ); ?></button>
					</dt>
					<dd>&minus;<?php echo wc_price( $cart->get_coupon_discount_amount( $cf_code, $cart->display_cart_ex_tax ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
				</div>
			<?php endforeach; ?>
			<?php foreach ( \CheckoutFlow\cart_discount_fees() as $cf_fee ) : ?>
				<div class="cfc-total-row cfc-discount">
					<dt><?php echo esc_html( $cf_fee->name ); ?></dt>
					<dd>&minus;<?php echo wc_price( abs( (float) $cf_fee->amount ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
				</div>
			<?php endforeach; ?>
			<div class="cfc-total-row cfc-subtotal">
				<dt><?php esc_html_e( 'Subtotal', 'checkoutflow' ); ?></dt>
				<dd><?php echo wc_price( \CheckoutFlow\cart_display_total() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			</div>
		</dl>
		<p class="cfc-note"><?php esc_html_e( 'Shipping and taxes calculated at checkout.', 'checkoutflow' ); ?></p>

		<a class="cfc-btn cfc-checkout" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">
			<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
			<?php echo esc_html( Settings::get( 'cart_checkout_text' ) ); ?>
		</a>
		<button type="button" class="cfc-continue" data-cf-close><?php echo esc_html( Settings::get( 'cart_continue_text' ) ); ?></button>
	</footer>
<?php endif; ?>
</div>
