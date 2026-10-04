<?php
/**
 * Thank-you (order received) page. Override at yourtheme/checkoutflow/checkout/thankyou.php.
 *
 * The page is built from blocks in CheckoutFlow → Thank You Page. WooCommerce's hooks
 * (woocommerce_before_thankyou, woocommerce_thankyou_{gateway} via the Payment block,
 * woocommerce_thankyou) still run, so payment gateways and tracking scripts work as before.
 *
 * @package CheckoutFlow
 * @var WC_Order|false $order
 */

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

			<?php echo Thank_You::render( Thank_You::design(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- blocks escape their own output ?>

		<?php endif; ?>

		<?php if ( $order->has_status( 'failed' ) ) : ?>
			<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		<?php endif; ?>
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
