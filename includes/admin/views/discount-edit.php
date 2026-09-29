<?php
/**
 * Discount rule editor.
 *
 * @package CheckoutFlow
 * @var array $rule
 * @var bool  $is_new
 */

use CheckoutFlow\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$cf_roles = array(
	'all'   => __( 'Everyone', 'checkoutflow' ),
	'guest' => __( 'Guests (not logged in)', 'checkoutflow' ),
);
foreach ( wp_roles()->get_names() as $cf_slug => $cf_name ) {
	$cf_roles[ $cf_slug ] = translate_user_role( $cf_name );
}
$cf_types = array(
	'percent_decrease' => __( '% off', 'checkoutflow' ),
	'fixed_decrease'   => __( 'Amount off', 'checkoutflow' ),
	'fixed_price'      => __( 'Fixed price', 'checkoutflow' ),
	'percent_increase' => __( '% more', 'checkoutflow' ),
	'fixed_increase'   => __( 'Amount more', 'checkoutflow' ),
);
$cf_cats   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
$cf_brands = taxonomy_exists( 'product_brand' ) ? get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) ) : array();
$cf_tiers  = $rule['tiers'] ? $rule['tiers'] : array( array( 'role' => 'all', 'min' => 1, 'max' => '', 'type' => 'percent_decrease', 'value' => '' ) );
$cf_days   = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

$cf_tier_row = static function ( $i, $t ) use ( $cf_roles, $cf_types ) {
	ob_start();
	?>
	<tr class="cf-tier">
		<td><input type="number" step="any" min="0" class="small-text" name="rule[tiers][<?php echo esc_attr( $i ); ?>][min]" value="<?php echo esc_attr( $t['min'] ); ?>"></td>
		<td><input type="number" step="any" min="0" class="small-text" name="rule[tiers][<?php echo esc_attr( $i ); ?>][max]" value="<?php echo esc_attr( $t['max'] ); ?>" placeholder="∞"></td>
		<td>
			<select name="rule[tiers][<?php echo esc_attr( $i ); ?>][type]">
				<?php foreach ( $cf_types as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $t['type'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><input type="number" step="any" min="0" class="small-text" name="rule[tiers][<?php echo esc_attr( $i ); ?>][value]" value="<?php echo esc_attr( $t['value'] ); ?>" required></td>
		<td>
			<select name="rule[tiers][<?php echo esc_attr( $i ); ?>][role]">
				<?php foreach ( $cf_roles as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $t['role'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><button type="button" class="button-link cf-delete cf-tier-remove" aria-label="<?php esc_attr_e( 'Remove tier', 'checkoutflow' ); ?>">✕</button></td>
	</tr>
	<?php
	return ob_get_clean();
};
?>
<p><a href="<?php echo esc_url( Admin::url( 'discounts' ) ); ?>">&larr; <?php esc_html_e( 'All discounts', 'checkoutflow' ); ?></a></p>
<h1><?php echo $is_new ? esc_html__( 'New discount rule', 'checkoutflow' ) : esc_html( $rule['title'] ); ?></h1>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-discount-form">
	<input type="hidden" name="action" value="cf_save_discount">
	<input type="hidden" name="rule[id]" value="<?php echo esc_attr( $rule['id'] ); ?>">
	<?php wp_nonce_field( 'cf_save_discount' ); ?>

	<div class="cf-card">
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="cf-d-title"><?php esc_html_e( 'Name', 'checkoutflow' ); ?></label></th>
				<td><input type="text" id="cf-d-title" name="rule[title]" class="regular-text" value="<?php echo esc_attr( $rule['title'] ); ?>" required></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th>
				<td><label><input type="checkbox" name="rule[enabled]" value="1" <?php checked( $rule['enabled'] ); ?>> <?php esc_html_e( 'Active', 'checkoutflow' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Type', 'checkoutflow' ); ?></th>
				<td class="cf-kind">
					<label><input type="radio" name="rule[kind]" value="cart_qty" <?php checked( $rule['kind'], 'cart_qty' ); ?>> <strong><?php esc_html_e( 'Bulk discount', 'checkoutflow' ); ?></strong> — <?php esc_html_e( 'add up the quantity of all matching items in the cart; the tier\'s discount comes off their total.', 'checkoutflow' ); ?></label><br>
					<label><input type="radio" name="rule[kind]" value="cart_amount" <?php checked( $rule['kind'], 'cart_amount' ); ?>> <strong><?php esc_html_e( 'Spend discount', 'checkoutflow' ); ?></strong> — <?php esc_html_e( 'same, but tiers are based on how much is spent on matching items.', 'checkoutflow' ); ?></label><br>
					<label><input type="radio" name="rule[kind]" value="product" <?php checked( $rule['kind'], 'product' ); ?>> <strong><?php esc_html_e( 'Product price', 'checkoutflow' ); ?></strong> — <?php esc_html_e( 'changes each matching product\'s price based on the quantity of that item. Tiers starting at 1 also show the new price in the shop.', 'checkoutflow' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="cf-d-label"><?php esc_html_e( 'Label in cart', 'checkoutflow' ); ?></label></th>
				<td><input type="text" id="cf-d-label" name="rule[label]" class="regular-text" value="<?php echo esc_attr( $rule['label'] ); ?>" placeholder="<?php esc_attr_e( 'Defaults to the rule name', 'checkoutflow' ); ?>"><p class="description"><?php esc_html_e( 'Bulk and spend discounts appear as a line with this name in the cart and checkout totals.', 'checkoutflow' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Applies to', 'checkoutflow' ); ?></th>
				<td>
					<label><input type="radio" name="rule[scope]" value="all" <?php checked( $rule['scope'], 'all' ); ?>> <?php esc_html_e( 'All products', 'checkoutflow' ); ?></label>
					<label style="margin-left:16px"><input type="radio" name="rule[scope]" value="specific" <?php checked( $rule['scope'], 'specific' ); ?>> <?php esc_html_e( 'Specific categories / products', 'checkoutflow' ); ?></label>
					<div class="cf-scope" <?php echo 'all' === $rule['scope'] ? 'hidden' : ''; ?>>
						<?php if ( $cf_cats && ! is_wp_error( $cf_cats ) ) : ?>
							<p><strong><?php esc_html_e( 'Categories', 'checkoutflow' ); ?></strong></p>
							<div class="cf-checklist">
								<?php foreach ( $cf_cats as $cf_cat ) : ?>
									<label><input type="checkbox" name="rule[categories][]" value="<?php echo esc_attr( $cf_cat->term_id ); ?>" <?php checked( in_array( (int) $cf_cat->term_id, $rule['categories'], true ) ); ?>> <?php echo esc_html( $cf_cat->name ); ?> <span class="cf-muted">(<?php echo esc_html( $cf_cat->count ); ?>)</span></label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php if ( $cf_brands && ! is_wp_error( $cf_brands ) ) : ?>
							<p><strong><?php esc_html_e( 'Brands', 'checkoutflow' ); ?></strong></p>
							<div class="cf-checklist">
								<?php foreach ( $cf_brands as $cf_b ) : ?>
									<label><input type="checkbox" name="rule[brands][]" value="<?php echo esc_attr( $cf_b->term_id ); ?>" <?php checked( in_array( (int) $cf_b->term_id, $rule['brands'], true ) ); ?>> <?php echo esc_html( $cf_b->name ); ?></label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<p><label><strong><?php esc_html_e( 'Product IDs', 'checkoutflow' ); ?></strong><br><input type="text" class="regular-text" name="rule[products]" value="<?php echo esc_attr( implode( ', ', $rule['products'] ) ); ?>" placeholder="12, 34"></label></p>
					</div>
				</td>
			</tr>
		</table>
	</div>

	<div class="cf-card">
		<h2><?php esc_html_e( 'Tiers', 'checkoutflow' ); ?></h2>
		<p class="description"><?php esc_html_e( 'From / To are item quantities (for spend discounts: amounts). Leave "To" empty for no upper limit. The first tier that fits the shopper is used.', 'checkoutflow' ); ?></p>
		<table class="widefat cf-tiers">
			<thead><tr><th><?php esc_html_e( 'From', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'To', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Discount', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Value', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Who', 'checkoutflow' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php
				foreach ( $cf_tiers as $cf_i => $cf_t ) {
					echo $cf_tier_row( $cf_i, $cf_t ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</tbody>
		</table>
		<template id="cf-tier-template"><?php echo $cf_tier_row( '__i__', array( 'role' => 'all', 'min' => '', 'max' => '', 'type' => 'percent_decrease', 'value' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>
		<p><button type="button" class="button cf-tier-add"><?php esc_html_e( '+ Add tier', 'checkoutflow' ); ?></button></p>
	</div>

	<div class="cf-card">
		<h2><?php esc_html_e( 'Schedule', 'checkoutflow' ); ?> <span class="cf-muted">(<?php esc_html_e( 'optional', 'checkoutflow' ); ?>)</span></h2>
		<p>
			<label><?php esc_html_e( 'Starts', 'checkoutflow' ); ?> <input type="date" name="rule[start]" value="<?php echo esc_attr( $rule['start'] ); ?>"></label>
			<label style="margin-left:16px"><?php esc_html_e( 'Ends', 'checkoutflow' ); ?> <input type="date" name="rule[end]" value="<?php echo esc_attr( $rule['end'] ); ?>"></label>
		</p>
		<p><?php esc_html_e( 'Only on:', 'checkoutflow' ); ?>
			<?php foreach ( $cf_days as $cf_d ) : ?>
				<label style="margin-right:10px"><input type="checkbox" name="rule[days][]" value="<?php echo esc_attr( $cf_d ); ?>" <?php checked( in_array( $cf_d, $rule['days'], true ) ); ?>> <?php echo esc_html( date_i18n( 'D', strtotime( 'next ' . $cf_d ) ) ); ?></label>
			<?php endforeach; ?>
			<span class="description"><?php esc_html_e( '(none ticked = every day)', 'checkoutflow' ); ?></span>
		</p>
	</div>

	<?php submit_button( __( 'Save rule', 'checkoutflow' ) ); ?>
</form>
