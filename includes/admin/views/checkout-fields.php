<?php
/**
 * Checkout field editor (UI in assets/js/fields.js).
 *
 * @package CheckoutFlow
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cf-dash-head">
	<h1><?php esc_html_e( 'Checkout Fields', 'checkoutflow' ); ?></h1>
	<a class="button" href="<?php echo esc_url( wc_get_checkout_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View checkout', 'checkoutflow' ); ?></a>
</div>
<p class="description"><?php esc_html_e( 'Drag to reorder, click a field to edit it. Address fields apply to both shipping and billing. Answers to your own fields are saved on the order and shown in the order screen and order emails.', 'checkoutflow' ); ?></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cf-fields-form">
	<input type="hidden" name="action" value="cf_save_fields">
	<input type="hidden" name="config" id="cf-fields-config" value="">
	<?php wp_nonce_field( 'cf_save_fields' ); ?>
	<div id="cf-fields-app" class="cf-fields-app"></div>
	<p class="cf-fields-actions">
		<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save fields', 'checkoutflow' ); ?></button>
		<button type="submit" name="reset" value="1" class="button-link cf-fields-reset" onclick="return window.confirm('<?php echo esc_js( __( 'Reset every field to WooCommerce\'s defaults and remove your custom fields and sections?', 'checkoutflow' ) ); ?>');"><?php esc_html_e( 'Reset to defaults', 'checkoutflow' ); ?></button>
	</p>
</form>
<noscript><p><?php esc_html_e( 'The field editor needs JavaScript.', 'checkoutflow' ); ?></p></noscript>
