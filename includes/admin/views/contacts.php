<?php
/**
 * Contacts list, add, import/export.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\DB;
use CheckoutFlow\Admin\Admin;

defined( 'ABSPATH' ) || exit;

global $wpdb;
// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
// phpcs:enable
$per_page = 50;
$table    = DB::t( 'contacts' );

$where = array( '1=1' );
if ( in_array( $status, array( 'subscribed', 'none', 'unsubscribed' ), true ) ) {
	$where[] = $wpdb->prepare( 'status = %s', $status );
}
if ( 'customers' === $status ) {
	$where[] = 'orders > 0';
}
if ( '' !== $search ) {
	$like    = '%' . $wpdb->esc_like( $search ) . '%';
	$where[] = $wpdb->prepare( '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)', $like, $like, $like );
}
$where_sql = implode( ' AND ', $where );
$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$counts    = $wpdb->get_row( "SELECT COUNT(*) total, SUM(status='subscribed') subscribed, SUM(status='none') none, SUM(status='unsubscribed') unsubscribed, SUM(orders>0) customers FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$import    = get_option( 'checkoutflow_import' );

$filters = array(
	''             => array( __( 'All', 'checkoutflow' ), $counts['total'] ),
	'subscribed'   => array( __( 'Subscribed', 'checkoutflow' ), $counts['subscribed'] ),
	'none'         => array( __( 'No consent', 'checkoutflow' ), $counts['none'] ),
	'customers'    => array( __( 'Customers', 'checkoutflow' ), $counts['customers'] ),
	'unsubscribed' => array( __( 'Unsubscribed', 'checkoutflow' ), $counts['unsubscribed'] ),
);
$labels  = array(
	'subscribed'   => __( 'Subscribed', 'checkoutflow' ),
	'none'         => __( 'No consent', 'checkoutflow' ),
	'unsubscribed' => __( 'Unsubscribed', 'checkoutflow' ),
);
?>
<h1><?php esc_html_e( 'Contacts', 'checkoutflow' ); ?></h1>

<?php if ( ! empty( $import['running'] ) ) : ?>
	<div class="notice notice-info inline"><p>
		<?php
		/* translators: %d: orders processed */
		echo esc_html( sprintf( __( 'Importing customers from orders… %d orders processed so far.', 'checkoutflow' ), (int) $import['done'] ) );
		?>
	</p></div>
<?php endif; ?>

<ul class="subsubsub">
	<?php
	$links = array();
	foreach ( $filters as $key => $f ) {
		$links[] = sprintf( '<li><a href="%s" class="%s">%s <span class="count">(%s)</span></a>', esc_url( Admin::url( 'contacts', $key ? array( 'status' => $key ) : array() ) ), $key === $status ? 'current' : '', esc_html( $f[0] ), esc_html( number_format_i18n( (int) $f[1] ) ) );
	}
	echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	?>
</ul>
<form method="get" class="search-form cf-search">
	<input type="hidden" name="page" value="checkoutflow-contacts">
	<?php if ( $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><?php endif; ?>
	<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search contacts', 'checkoutflow' ); ?>">
	<button class="button"><?php esc_html_e( 'Search', 'checkoutflow' ); ?></button>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="cf_contact_action">
	<?php wp_nonce_field( 'cf_contact_action' ); ?>
	<div class="tablenav top">
		<select name="do">
			<option value=""><?php esc_html_e( 'Bulk actions', 'checkoutflow' ); ?></option>
			<option value="subscribe"><?php esc_html_e( 'Mark subscribed', 'checkoutflow' ); ?></option>
			<option value="unsubscribe"><?php esc_html_e( 'Unsubscribe', 'checkoutflow' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete', 'checkoutflow' ); ?></option>
		</select>
		<button class="button"><?php esc_html_e( 'Apply', 'checkoutflow' ); ?></button>
		<?php
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div>';
		}
		?>
	</div>
	<table class="widefat striped cf-table">
		<thead>
			<tr>
				<td class="check-column"><input type="checkbox" class="cf-check-all"></td>
				<th><?php esc_html_e( 'Email', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Name', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Orders', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Spent', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Last order', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Source', 'checkoutflow' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No contacts found.', 'checkoutflow' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $c ) : ?>
			<tr>
				<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $c['id'] ); ?>"></th>
				<td><?php echo esc_html( $c['email'] ); ?></td>
				<td><?php echo esc_html( trim( $c['first_name'] . ' ' . $c['last_name'] ) ); ?></td>
				<td><span class="cf-badge cf-badge-<?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( isset( $labels[ $c['status'] ] ) ? $labels[ $c['status'] ] : $c['status'] ); ?></span></td>
				<td><?php echo esc_html( $c['orders'] ); ?></td>
				<td><?php echo wp_kses_post( wc_price( $c['spent'] ) ); ?></td>
				<td><?php echo esc_html( $c['last_order_at'] ? get_date_from_gmt( $c['last_order_at'], get_option( 'date_format' ) ) : '–' ); ?></td>
				<td><?php echo esc_html( $c['source'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</form>

<div class="cf-grid">
	<div class="cf-card">
		<h2><?php esc_html_e( 'Add contact', 'checkoutflow' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cf_contact_action">
			<input type="hidden" name="do" value="add">
			<?php wp_nonce_field( 'cf_contact_action' ); ?>
			<p><input type="email" name="email" placeholder="<?php esc_attr_e( 'Email', 'checkoutflow' ); ?>" class="regular-text" required></p>
			<p><input type="text" name="first_name" placeholder="<?php esc_attr_e( 'First name', 'checkoutflow' ); ?>"> <input type="text" name="last_name" placeholder="<?php esc_attr_e( 'Last name', 'checkoutflow' ); ?>"></p>
			<p><label><input type="checkbox" name="subscribed" value="1" checked> <?php esc_html_e( 'This person agreed to receive marketing email', 'checkoutflow' ); ?></label></p>
			<button class="button button-primary"><?php esc_html_e( 'Add contact', 'checkoutflow' ); ?></button>
		</form>
	</div>

	<div class="cf-card">
		<h2><?php esc_html_e( 'Import', 'checkoutflow' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cf_contact_action">
			<input type="hidden" name="do" value="sync_orders">
			<?php wp_nonce_field( 'cf_contact_action' ); ?>
			<p><?php esc_html_e( 'Add every customer from past paid orders (with order count and total spent). Safe to run more than once.', 'checkoutflow' ); ?></p>
			<button class="button"><?php esc_html_e( 'Import customers from orders', 'checkoutflow' ); ?></button>
		</form>
		<hr>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="cf_import_contacts">
			<?php wp_nonce_field( 'cf_import_contacts' ); ?>
			<p><?php esc_html_e( 'CSV with a header row: email, first_name, last_name. (Export your FunnelKit contacts and import them here.)', 'checkoutflow' ); ?></p>
			<p><input type="file" name="csv" accept=".csv,text/csv" required></p>
			<p><label><input type="checkbox" name="consent" value="1"> <?php esc_html_e( 'These contacts opted in to marketing email (mark as subscribed)', 'checkoutflow' ); ?></label></p>
			<button class="button"><?php esc_html_e( 'Import CSV', 'checkoutflow' ); ?></button>
		</form>
	</div>

	<div class="cf-card">
		<h2><?php esc_html_e( 'Export', 'checkoutflow' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cf_export_contacts">
			<?php wp_nonce_field( 'cf_export_contacts' ); ?>
			<p><?php esc_html_e( 'Download all contacts as CSV.', 'checkoutflow' ); ?></p>
			<button class="button"><?php esc_html_e( 'Export CSV', 'checkoutflow' ); ?></button>
		</form>
	</div>
</div>
