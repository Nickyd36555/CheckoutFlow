<?php
/**
 * Modern checkout order summary: product images, quantity controls, inline coupon, totals.
 * Re-rendered by WooCommerce on every checkout update (fragment: .woocommerce-checkout-review-order-table).
 *
 * Override at yourtheme/checkoutflow/checkout/review-order.php.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

$cf_coupon = Settings::get( 'checkout_coupon' );
?>
<div class="woocommerce-checkout-review-order-table cf-summary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cf-checkout' ) ); ?>">
	<table class="shop_table cf-items">
		<tbody>
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			if ( ! $_product instanceof WC_Product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				continue;
			}
			$cf_max = $_product->is_sold_individually() ? 1 : $_product->get_max_purchase_quantity();
			?>
			<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>" data-key="<?php echo esc_attr( $cart_item_key ); ?>">
				<td class="cf-item-thumb">
					<?php echo $_product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="cf-item-qty-badge"><?php echo esc_html( wc_stock_amount( $cart_item['quantity'] ) ); ?></span>
				</td>
				<td class="product-name cf-item-info">
					<div class="cf-item-name"><?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key ) ); ?></div>
					<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php if ( 1 !== $cf_max ) : ?>
						<div class="cf-qty">
							<button type="button" class="cf-qty-btn" data-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'checkoutflow' ); ?>">&minus;</button>
							<input type="number" class="cf-qty-input" value="<?php echo esc_attr( $cart_item['quantity'] ); ?>" min="0" <?php echo $cf_max > 0 ? 'max="' . esc_attr( $cf_max ) . '"' : ''; ?> inputmode="numeric" aria-label="<?php esc_attr_e( 'Quantity', 'checkoutflow' ); ?>">
							<button type="button" class="cf-qty-btn" data-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'checkoutflow' ); ?>">+</button>
						</div>
					<?php endif; ?>
				</td>
				<td class="product-total cf-item-total">
					<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<button type="button" class="cf-item-remove" aria-label="<?php /* translators: %s: product name */ echo esc_attr( sprintf( __( 'Remove %s', 'checkoutflow' ), wp_strip_all_tags( $_product->get_name() ) ) ); ?>">
						<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php do_action( 'woocommerce_review_order_after_cart_contents' ); ?>
		</tbody>
	</table>

	<?php if ( 'hidden' !== $cf_coupon && 'default' !== $cf_coupon && wc_coupons_enabled() ) : ?>
		<div class="cf-coupon">
			<?php if ( 'summary' === $cf_coupon ) : ?>
				<a href="#" class="cf-coupon-toggle"><?php esc_html_e( 'Have a coupon code?', 'checkoutflow' ); ?></a>
			<?php endif; ?>
			<div class="cf-coupon-box" <?php echo 'summary' === $cf_coupon ? 'hidden' : ''; ?>>
				<span class="cf-coupon-field">
					<input type="text" id="cf-coupon-code" class="input-text cf-coupon-input" placeholder=" " autocomplete="off">
					<label for="cf-coupon-code" class="cf-coupon-label"><?php esc_html_e( 'Coupon code', 'checkoutflow' ); ?></label>
				</span>
				<button type="button" class="button cf-coupon-apply"><?php esc_html_e( 'Apply', 'checkoutflow' ); ?></button>
			</div>
		</div>
	<?php endif; ?>

	<table class="shop_table cf-totals">
		<tbody>
			<tr class="cart-subtotal">
				<th><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
				<td><?php wc_cart_totals_subtotal_html(); ?></td>
			</tr>

			<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
				<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
					<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
					<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
				</tr>
			<?php endforeach; ?>

			<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
				<tr class="fee<?php echo (float) $fee->amount < 0 ? ' cf-discount-fee' : ''; ?>">
					<th><?php echo esc_html( $fee->name ); ?></th>
					<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
				</tr>
			<?php endforeach; ?>

			<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
				<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>
				<tr class="shipping cf-shipping-total">
					<th><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></th>
					<td>
						<?php
						$cf_chosen = WC()->session->get( 'chosen_shipping_methods' );
						echo ! empty( $cf_chosen ) && array_filter( (array) $cf_chosen ) ? wp_kses_post( WC()->cart->get_cart_shipping_total() ) : '<span class="cf-muted">' . esc_html__( 'Enter address', 'checkoutflow' ) . '</span>';
						?>
					</td>
				</tr>
				<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>
			<?php endif; ?>

			<?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() && (float) WC()->cart->get_taxes_total() > 0 ) : ?>
				<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
					<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride ?>
						<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
							<th><?php echo esc_html( $tax->label ); ?></th>
							<td><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr class="tax-total">
						<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
						<td><?php wc_cart_totals_taxes_total_html(); ?></td>
					</tr>
				<?php endif; ?>
			<?php endif; ?>

			<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>
			<tr class="order-total">
				<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
				<td><?php wc_cart_totals_order_total_html(); ?></td>
			</tr>
			<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
		</tbody>
	</table>
</div>
