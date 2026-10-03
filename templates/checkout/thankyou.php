<?php
/**
 * Thank-you (order received) page. Override at yourtheme/checkoutflow/checkout/thankyou.php.
 *
 * Keeps WooCommerce's hooks (woocommerce_before_thankyou, woocommerce_thankyou_{gateway},
 * woocommerce_thankyou) so payment gateways and tracking scripts work as before. The order
 * details WooCommerce prints on woocommerce_thankyou are replaced by CheckoutFlow\Thank_You::details().
 *
 * @package CheckoutFlow
 * @var WC_Order|false $order
 */

use CheckoutFlow\Settings;
use CheckoutFlow\Thank_You;

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce-order cf-ty">

	<?php
	if ( $order ) :

		do_action( 'woocommerce_before_thankyou', $order->get_id() );

		if ( $order->has_status( 'failed' ) ) :
			?>
			<div class="cf-ty-hero is-failed">
				<span class="cf-ty-check" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 7v6M12 17h.01"/></svg></span>
				<div>
					<h1 class="cf-ty-title"><?php esc_html_e( 'Payment failed', 'checkoutflow' ); ?></h1>
					<p class="cf-ty-sub"><?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?></p>
					<p class="cf-ty-actions">
						<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="cf-ty-btn"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
						<?php if ( is_user_logged_in() ) : ?>
							<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="cf-ty-btn is-ghost"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</div>
		<?php else : ?>

			<div class="cf-ty-hero">
				<span class="cf-ty-check" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
				<div>
					<h1 class="cf-ty-title"><?php echo esc_html( Thank_You::text( 'ty_heading', $order ) ); ?></h1>
					<?php $cf_sub = Thank_You::text( 'ty_subheading', $order ); ?>
					<?php if ( '' !== $cf_sub ) : ?>
						<p class="cf-ty-sub"><?php echo esc_html( $cf_sub ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( Settings::get( 'ty_show_overview' ) ) : ?>
				<ul class="cf-ty-overview woocommerce-order-overview woocommerce-thankyou-order-details order_details">
					<li class="woocommerce-order-overview__order order">
						<span><?php esc_html_e( 'Order number', 'checkoutflow' ); ?></span>
						<strong><?php echo esc_html( $order->get_order_number() ); ?></strong>
					</li>
					<li class="woocommerce-order-overview__date date">
						<span><?php esc_html_e( 'Date', 'checkoutflow' ); ?></span>
						<strong><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></strong>
					</li>
					<li class="woocommerce-order-overview__total total">
						<span><?php esc_html_e( 'Total', 'checkoutflow' ); ?></span>
						<strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
					</li>
					<?php if ( $order->get_payment_method_title() ) : ?>
						<li class="woocommerce-order-overview__payment-method method">
							<span><?php esc_html_e( 'Payment method', 'checkoutflow' ); ?></span>
							<strong><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></strong>
						</li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>

		<?php endif; ?>

		<div class="cf-ty-gateway">
			<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		</div>
		<?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>

	<?php else : ?>

		<div class="cf-ty-hero">
			<span class="cf-ty-check" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
			<div>
				<?php wc_get_template( 'checkout/order-received.php', array( 'order' => false ) ); ?>
			</div>
		</div>

	<?php endif; ?>

</div>
