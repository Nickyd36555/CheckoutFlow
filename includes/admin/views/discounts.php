<?php
/**
 * Discount rules list.
 *
 * @package CheckoutFlow
 * @var array $rules
 */

use CheckoutFlow\Admin\Admin;
use CheckoutFlow\Discounts;

defined( 'ABSPATH' ) || exit;

$cf_kinds = array(
	'product'     => __( 'Product price', 'checkoutflow' ),
	'cart_qty'    => __( 'Bulk (cart quantity)', 'checkoutflow' ),
	'cart_amount' => __( 'Spend (cart amount)', 'checkoutflow' ),
);
$cf_types = array(
	'percent_decrease' => '−%s%%',
	'fixed_decrease'   => '−%s',
	'fixed_price'      => '= %s',
	'percent_increase' => '+%s%%',
	'fixed_increase'   => '+%s',
);
?>
<h1 class="wp-heading-inline"><?php esc_html_e( 'Discounts', 'checkoutflow' ); ?></h1>
<a href="<?php echo esc_url( Admin::url( 'discounts', array( 'rule' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add rule', 'checkoutflow' ); ?></a>
<hr class="wp-header-end">
<p class="cf-muted"><?php esc_html_e( 'Rules are checked top to bottom: the first product rule that fits a cart line sets its price, and the first bulk/spend rule that fits the cart adds its discount.', 'checkoutflow' ); ?></p>

<?php if ( Discounts::addify_active() ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Addify "Product Dynamic Pricing and Discounts" is still active, so these rules are paused to avoid discounting twice. Deactivate Addify to switch over.', 'checkoutflow' ); ?></p></div>
<?php endif; ?>

<table class="widefat striped cf-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Rule', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Type', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Applies to', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Tiers', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Schedule', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th>
			<th></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $rules ) : ?>
		<tr><td colspan="7"><?php esc_html_e( 'No discount rules yet.', 'checkoutflow' ); ?></td></tr>
	<?php endif; ?>
	<?php
	foreach ( $rules as $cf_i => $cf_r ) :
		$cf_scope = array();
		if ( 'all' === $cf_r['scope'] ) {
			$cf_scope[] = __( 'All products', 'checkoutflow' );
		} else {
			foreach ( $cf_r['categories'] as $cf_cat ) {
				$cf_term    = get_term( $cf_cat, 'product_cat' );
				$cf_scope[] = $cf_term && ! is_wp_error( $cf_term ) ? $cf_term->name : '#' . $cf_cat;
			}
			if ( $cf_r['products'] ) {
				/* translators: %d: number of products */
				$cf_scope[] = sprintf( _n( '%d product', '%d products', count( $cf_r['products'] ), 'checkoutflow' ), count( $cf_r['products'] ) );
			}
			if ( $cf_r['brands'] ) {
				/* translators: %d: number of brands */
				$cf_scope[] = sprintf( _n( '%d brand', '%d brands', count( $cf_r['brands'] ), 'checkoutflow' ), count( $cf_r['brands'] ) );
			}
		}
		$cf_tiers = array();
		foreach ( $cf_r['tiers'] as $cf_t ) {
			$cf_range   = wc_format_localized_decimal( $cf_t['min'] ) . ( '' === $cf_t['max'] ? '+' : '–' . wc_format_localized_decimal( $cf_t['max'] ) );
			$cf_tiers[] = $cf_range . ': ' . sprintf( $cf_types[ $cf_t['type'] ], wc_format_localized_decimal( $cf_t['value'] ) ) . ( 'all' !== $cf_t['role'] ? ' (' . $cf_t['role'] . ')' : '' );
		}
		$cf_sched = array();
		if ( $cf_r['start'] ) {
			/* translators: %s: date */
			$cf_sched[] = sprintf( __( 'from %s', 'checkoutflow' ), $cf_r['start'] );
		}
		if ( $cf_r['end'] ) {
			/* translators: %s: date */
			$cf_sched[] = sprintf( __( 'until %s', 'checkoutflow' ), $cf_r['end'] );
		}
		if ( $cf_r['days'] ) {
			$cf_sched[] = implode( ', ', array_map( static function ( $d ) { return substr( $d, 0, 3 ); }, $cf_r['days'] ) );
		}
		?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( Admin::url( 'discounts', array( 'rule' => $cf_r['id'] ) ) ); ?>"><?php echo esc_html( $cf_r['title'] ? $cf_r['title'] : __( '(untitled)', 'checkoutflow' ) ); ?></a></strong>
				<?php if ( $cf_r['note'] ) : ?><br><small class="cf-muted"><?php echo esc_html( $cf_r['note'] ); ?></small><?php endif; ?>
			</td>
			<td><?php echo esc_html( $cf_kinds[ $cf_r['kind'] ] ); ?></td>
			<td><?php echo esc_html( implode( ', ', $cf_scope ) ); ?></td>
			<td><?php echo esc_html( implode( ' · ', $cf_tiers ) ); ?></td>
			<td><?php echo esc_html( $cf_sched ? implode( ' ', $cf_sched ) : __( 'Always', 'checkoutflow' ) ); ?></td>
			<td><span class="cf-badge <?php echo $cf_r['enabled'] ? 'cf-badge-active' : ''; ?>"><?php echo $cf_r['enabled'] ? esc_html__( 'on', 'checkoutflow' ) : esc_html__( 'off', 'checkoutflow' ); ?></span></td>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-row-actions">
					<input type="hidden" name="action" value="cf_discount_action">
					<input type="hidden" name="rule" value="<?php echo esc_attr( $cf_r['id'] ); ?>">
					<?php wp_nonce_field( 'cf_discount_action' ); ?>
					<button name="do" value="toggle" class="button button-small"><?php echo $cf_r['enabled'] ? esc_html__( 'Turn off', 'checkoutflow' ) : esc_html__( 'Turn on', 'checkoutflow' ); ?></button>
					<button name="do" value="up" class="button-link" <?php disabled( 0 === $cf_i ); ?> aria-label="<?php esc_attr_e( 'Move up', 'checkoutflow' ); ?>">↑</button>
					<button name="do" value="down" class="button-link" <?php disabled( count( $rules ) - 1 === $cf_i ); ?> aria-label="<?php esc_attr_e( 'Move down', 'checkoutflow' ); ?>">↓</button>
					<button name="do" value="duplicate" class="button-link"><?php esc_html_e( 'Duplicate', 'checkoutflow' ); ?></button>
					<button name="do" value="delete" class="button-link cf-delete" data-confirm="<?php esc_attr_e( 'Delete this rule?', 'checkoutflow' ); ?>"><?php esc_html_e( 'Delete', 'checkoutflow' ); ?></button>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
