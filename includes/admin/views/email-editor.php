<?php
/**
 * Email editor (subject, preheader, drag-and-drop builder, live preview, test send).
 *
 * @package CheckoutFlow
 * @var array $target
 */

use CheckoutFlow\Mail\Renderer;

defined( 'ABSPATH' ) || exit;

$email  = array_merge( array( 'subject' => '', 'preheader' => '', 'design' => array() ), (array) $target['email'] );
$design = Renderer::sanitize( $email['design'] );

$hints = array(
	'cart_abandoned'  => __( 'Tip: use the "Cart items" block and a button linking to {recovery_url}. It restores the cart and opens checkout.', 'checkoutflow' ),
	'order_paid'      => __( 'Tip: order tags like {order_number} and the "Order items" block are available.', 'checkoutflow' ),
	'order_completed' => __( 'Tip: link a button to {review_url} to collect product reviews.', 'checkoutflow' ),
	'winback'         => __( 'Tip: a Coupon block gives each customer a unique, single-use code.', 'checkoutflow' ),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cf-email-form">
	<input type="hidden" name="action" value="cf_save_email">
	<input type="hidden" name="type" value="<?php echo esc_attr( $target['type'] ); ?>">
	<input type="hidden" name="id" value="<?php echo esc_attr( $target['id'] ); ?>">
	<input type="hidden" name="step" value="<?php echo esc_attr( $target['step'] ); ?>">
	<input type="hidden" name="design" id="cf-design" value="">
	<?php wp_nonce_field( 'cf_save_email' ); ?>

	<div class="cfe-top">
		<a href="<?php echo esc_url( $target['back'] ); ?>" class="cfe-back">&larr; <?php esc_html_e( 'Back', 'checkoutflow' ); ?></a>
		<h1><?php echo esc_html( $target['title'] ); ?></h1>
		<?php if ( ! $target['locked'] ) : ?>
			<button type="submit" name="stay" value="1" class="button"><?php esc_html_e( 'Save', 'checkoutflow' ); ?></button>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save & close', 'checkoutflow' ); ?></button>
		<?php endif; ?>
	</div>

	<?php if ( $target['locked'] ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'This campaign has been sent, so its email is read-only. Duplicate the campaign to reuse it.', 'checkoutflow' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $hints[ $target['trigger'] ] ) ) : ?>
		<p class="cf-hint"><?php echo esc_html( $hints[ $target['trigger'] ] ); ?></p>
	<?php endif; ?>

	<div class="cfe-meta">
		<label><?php esc_html_e( 'Subject', 'checkoutflow' ); ?>
			<input type="text" name="subject" id="cf-subject" value="<?php echo esc_attr( $email['subject'] ); ?>" required placeholder="<?php esc_attr_e( 'e.g. {first_name}, your cart is waiting', 'checkoutflow' ); ?>">
		</label>
		<label><?php esc_html_e( 'Preview text', 'checkoutflow' ); ?> <span class="cf-muted">(<?php esc_html_e( 'shown after the subject in the inbox', 'checkoutflow' ); ?>)</span>
			<input type="text" name="preheader" id="cf-preheader" value="<?php echo esc_attr( $email['preheader'] ); ?>">
		</label>
	</div>

	<div id="cf-builder" class="cfb<?php echo $target['locked'] ? ' is-locked' : ''; ?>" data-design="<?php echo esc_attr( wp_json_encode( $design ) ); ?>"></div>

	<div class="cfe-test">
		<label for="cf-test-to"><?php esc_html_e( 'Send a test to', 'checkoutflow' ); ?></label>
		<input type="email" id="cf-test-to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
		<button type="button" class="button" id="cf-test-send"><?php esc_html_e( 'Send test', 'checkoutflow' ); ?></button>
		<span id="cf-test-result" role="status"></span>
		<p class="description"><?php esc_html_e( 'Tests use sample cart/order data and a sample coupon code.', 'checkoutflow' ); ?></p>
	</div>
</form>
