<?php
/**
 * Modern checkout form: Contact → Shipping address → Shipping method → Payment, with the
 * order summary on the right. Keeps every WooCommerce hook so gateways, shipping and
 * fee plugins (e.g. Route, store credit) keep working.
 *
 * Override at yourtheme/checkoutflow/checkout/form-checkout.php.
 *
 * @package CheckoutFlow
 * @var WC_Checkout $checkout
 */

use CheckoutFlow\Settings;
use CheckoutFlow\Checkout;

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

$cf_billing    = $checkout->get_checkout_fields( 'billing' );
$cf_shipping   = $checkout->get_checkout_fields( 'shipping' );
$cf_ship_first = Checkout::shipping_first();
$cf_needs_ship = WC()->cart->needs_shipping_address();
$cf_banner     = Settings::get( 'checkout_banner' );

// Split billing fields: email (contact), address (own box), the rest (phone, plugin fields).
$cf_email   = array();
$cf_address = array();
$cf_extra   = array();
foreach ( $cf_billing as $cf_key => $cf_field ) {
	if ( 'billing_email' === $cf_key ) {
		$cf_email[ $cf_key ] = $cf_field;
	} elseif ( in_array( substr( $cf_key, 8 ), Checkout::ADDRESS_KEYS, true ) ) {
		$cf_address[ $cf_key ] = $cf_field;
	} else {
		$cf_extra[ $cf_key ] = $cf_field;
	}
}
$cf_render = static function ( $fields ) use ( $checkout ) {
	foreach ( $fields as $key => $field ) {
		woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
	}
};
$cf_different_billing = false;

// Newer WooCommerce adds a shipping phone field; in the shipping-first form it replaces the
// billing phone (copied over on submit) and sits after the billing checkbox, not mid-address.
$cf_phone = array();
if ( $cf_ship_first && isset( $cf_shipping['shipping_phone'] ) ) {
	$cf_phone = array( 'shipping_phone' => $cf_shipping['shipping_phone'] );
	unset( $cf_shipping['shipping_phone'], $cf_extra['billing_phone'] );
}
?>
<form name="checkout" method="post" class="checkout woocommerce-checkout cf-modern" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">
	<div class="cf-layout">
		<div class="cf-col-main">
			<?php if ( $cf_banner ) : ?>
				<div class="cf-banner"><img src="<?php echo esc_url( $cf_banner ); ?>" alt=""></div>
			<?php endif; ?>

			<?php if ( $checkout->get_checkout_fields() ) : ?>
				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>
				<div id="customer_details" class="cf-customer">

					<section class="cf-section cf-contact">
						<h3 class="cf-section-title"><?php esc_html_e( 'Contact Information', 'checkoutflow' ); ?></h3>
						<?php do_action( 'woocommerce_before_checkout_billing_form', $checkout ); ?>
						<div class="cf-fields"><?php $cf_render( $cf_email ); ?></div>

						<?php if ( ! is_user_logged_in() && $checkout->is_registration_enabled() ) : ?>
							<div class="woocommerce-account-fields">
								<?php if ( ! $checkout->is_registration_required() ) : ?>
									<p class="form-row form-row-wide create-account">
										<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
											<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ); ?> type="checkbox" name="createaccount" value="1" /> <span><?php esc_html_e( 'Create an account?', 'woocommerce' ); ?></span>
										</label>
									</p>
								<?php endif; ?>
								<?php do_action( 'woocommerce_before_checkout_registration_form', $checkout ); ?>
								<?php if ( $checkout->get_checkout_fields( 'account' ) ) : ?>
									<div class="create-account"><?php $cf_render( $checkout->get_checkout_fields( 'account' ) ); ?><div class="clear"></div></div>
								<?php endif; ?>
								<?php do_action( 'woocommerce_after_checkout_registration_form', $checkout ); ?>
							</div>
						<?php endif; ?>
					</section>

					<?php if ( $cf_ship_first ) : ?>
						<section class="cf-section cf-address">
							<h3 class="cf-section-title"><?php esc_html_e( 'Shipping Address', 'checkoutflow' ); ?></h3>
							<input type="hidden" name="cf_shipping_first" value="1">
							<div id="ship-to-different-address" hidden>
								<input id="ship-to-different-address-checkbox" type="checkbox" name="ship_to_different_address" value="1" checked>
							</div>
							<div class="woocommerce-shipping-fields">
								<?php do_action( 'woocommerce_before_checkout_shipping_form', $checkout ); ?>
								<div class="woocommerce-shipping-fields__field-wrapper"><?php $cf_render( $cf_shipping ); ?></div>
								<?php do_action( 'woocommerce_after_checkout_shipping_form', $checkout ); ?>
							</div>

							<p class="form-row cf-different-billing">
								<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
									<input type="checkbox" class="woocommerce-form__input-checkbox input-checkbox" id="cf-different-billing" name="cf_different_billing" value="1" <?php checked( $cf_different_billing ); ?>>
									<span><?php esc_html_e( 'Use a different billing address', 'checkoutflow' ); ?> <span class="optional"><?php esc_html_e( '(optional)', 'woocommerce' ); ?></span></span>
								</label>
							</p>
							<div class="woocommerce-billing-fields cf-billing-address" <?php echo $cf_different_billing ? '' : 'hidden'; ?>>
								<h4 class="cf-subtitle"><?php esc_html_e( 'Billing Address', 'checkoutflow' ); ?></h4>
								<div class="woocommerce-billing-fields__field-wrapper"><?php $cf_render( $cf_address ); ?></div>
							</div>
							<div class="cf-fields cf-billing-extra"><?php $cf_render( $cf_phone ); ?><?php $cf_render( $cf_extra ); ?></div>
							<?php do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>
						</section>
					<?php else : ?>
						<section class="cf-section cf-address">
							<h3 class="cf-section-title">
								<?php
								echo esc_html( wc_ship_to_billing_address_only() && WC()->cart->needs_shipping() ? __( 'Billing & Shipping Address', 'checkoutflow' ) : __( 'Billing Address', 'checkoutflow' ) );
								?>
							</h3>
							<div class="woocommerce-billing-fields">
								<div class="woocommerce-billing-fields__field-wrapper"><?php $cf_render( $cf_address ); ?></div>
							</div>
							<div class="cf-fields cf-billing-extra"><?php $cf_render( $cf_extra ); ?></div>
							<?php do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>

							<?php if ( $cf_needs_ship ) : ?>
								<div class="woocommerce-shipping-fields">
									<p id="ship-to-different-address" class="form-row">
										<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
											<input id="ship-to-different-address-checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" <?php checked( apply_filters( 'woocommerce_ship_to_different_address_checked', 'shipping' === get_option( 'woocommerce_ship_to_destination' ) ? 1 : 0 ), 1 ); ?> type="checkbox" name="ship_to_different_address" value="1" /> <span><?php esc_html_e( 'Ship to a different address?', 'woocommerce' ); ?></span>
										</label>
									</p>
									<div class="shipping_address">
										<?php do_action( 'woocommerce_before_checkout_shipping_form', $checkout ); ?>
										<div class="woocommerce-shipping-fields__field-wrapper"><?php $cf_render( $cf_shipping ); ?></div>
										<?php do_action( 'woocommerce_after_checkout_shipping_form', $checkout ); ?>
									</div>
								</div>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<div class="woocommerce-additional-fields">
						<?php do_action( 'woocommerce_before_order_notes', $checkout ); ?>
						<?php if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' === get_option( 'woocommerce_enable_order_comments', 'yes' ) ) ) : ?>
							<div class="woocommerce-additional-fields__field-wrapper"><?php $cf_render( $checkout->get_checkout_fields( 'order' ) ); ?></div>
						<?php endif; ?>
						<?php do_action( 'woocommerce_after_order_notes', $checkout ); ?>
					</div>
				</div>
				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
			<?php endif; ?>

			<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
				<section class="cf-section cf-shipping">
					<h3 class="cf-section-title"><?php esc_html_e( 'Shipping Method', 'checkoutflow' ); ?></h3>
					<?php echo Checkout::shipping_methods_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</section>
			<?php endif; ?>

			<?php if ( has_action( 'checkoutflow_route_widget' ) ) : ?>
				<section class="cf-section cf-route">
					<h3 class="cf-section-title"><?php esc_html_e( 'Route Package Protection', 'checkoutflow' ); ?></h3>
					<?php do_action( 'checkoutflow_route_widget' ); ?>
				</section>
			<?php endif; ?>

			<?php do_action( 'woocommerce_review_order_before_payment' ); ?>
			<section class="cf-section cf-payment">
				<h3 class="cf-section-title"><?php esc_html_e( 'Payment Information', 'checkoutflow' ); ?></h3>
				<?php woocommerce_checkout_payment(); ?>
			</section>
			<?php do_action( 'woocommerce_review_order_after_payment' ); ?>
		</div>

		<aside class="cf-col-summary">
			<button type="button" class="cf-summary-toggle" aria-expanded="false" aria-controls="cf-summary-body">
				<span class="cf-summary-toggle-label"><?php esc_html_e( 'Show order summary', 'checkoutflow' ); ?></span>
				<span class="cf-summary-toggle-total"><?php wc_cart_totals_order_total_html(); ?></span>
			</button>
			<div class="cf-summary-body" id="cf-summary-body">
				<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
				<h3 id="order_review_heading" class="screen-reader-text"><?php esc_html_e( 'Your order', 'woocommerce' ); ?></h3>
				<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
				<div id="order_review" class="woocommerce-checkout-review-order">
					<?php
					// Payment lives in the left column in this layout.
					remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
					do_action( 'woocommerce_checkout_order_review' );
					?>
				</div>
				<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
				<?php echo Checkout::badges_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( '' !== trim( (string) Settings::get( 'checkout_summary_html' ) ) ) : ?>
					<div class="cf-summary-note"><?php echo wp_kses_post( wpautop( Settings::get( 'checkout_summary_html' ) ) ); ?></div>
				<?php endif; ?>
			</div>
		</aside>
	</div>
</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
