<?php
/**
 * Campaign editor: audience, schedule, send.
 *
 * @package CheckoutFlow
 * @var array $campaign
 */

use CheckoutFlow\DB;
use CheckoutFlow\Admin\Admin;
use CheckoutFlow\Marketing\Campaigns;

defined( 'ABSPATH' ) || exit;

$audience = array_merge( Campaigns::default_audience(), DB::json( $campaign['audience'] ) );
$email    = DB::json( $campaign['email'] );
$editable = in_array( $campaign['status'], array( 'draft', 'scheduled' ), true );
$stats    = Campaigns::stats( 'campaign', $campaign['id'] );
$edit_url = add_query_arg( array( 'page' => 'checkoutflow-email', 'type' => 'campaign', 'id' => $campaign['id'] ), admin_url( 'admin.php' ) );
$local_scheduled = $campaign['scheduled_at'] ? get_date_from_gmt( $campaign['scheduled_at'], 'Y-m-d\TH:i' ) : '';
?>
<p><a href="<?php echo esc_url( Admin::url( 'campaigns' ) ); ?>">&larr; <?php esc_html_e( 'All campaigns', 'checkoutflow' ); ?></a></p>
<h1><?php echo esc_html( $campaign['name'] ); ?> <span class="cf-badge cf-badge-<?php echo esc_attr( $campaign['status'] ); ?>"><?php echo esc_html( $campaign['status'] ); ?></span></h1>

<?php if ( ! $editable ) : ?>
	<div class="cf-kpis">
		<div class="cf-kpi"><span><?php esc_html_e( 'Recipients', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $campaign['recipients'] ) ); ?></strong></div>
		<div class="cf-kpi"><span><?php esc_html_e( 'Sent', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $stats['sent'] ) ); ?></strong></div>
		<div class="cf-kpi"><span><?php esc_html_e( 'Queued', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $stats['pending'] ) ); ?></strong></div>
		<div class="cf-kpi"><span><?php esc_html_e( 'Opened', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $stats['opened'] ) ); ?></strong></div>
		<div class="cf-kpi"><span><?php esc_html_e( 'Clicked', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $stats['clicked'] ) ); ?></strong></div>
		<div class="cf-kpi"><span><?php esc_html_e( 'Orders', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $stats['orders'] ) ); ?></strong></div>
		<div class="cf-kpi"><span><?php esc_html_e( 'Revenue', 'checkoutflow' ); ?></span><strong><?php echo wp_kses_post( wc_price( $stats['revenue'] ) ); ?></strong></div>
		<div class="cf-kpi"><span><?php esc_html_e( 'Failed', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $stats['failed'] ) ); ?></strong></div>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cf-campaign-form">
	<input type="hidden" name="action" value="cf_save_campaign">
	<input type="hidden" name="id" value="<?php echo esc_attr( $campaign['id'] ); ?>">
	<?php wp_nonce_field( 'cf_save_campaign' ); ?>
	<fieldset <?php disabled( ! $editable ); ?>>

	<div class="cf-card">
		<h2><?php esc_html_e( '1. Details', 'checkoutflow' ); ?></h2>
		<p><label><?php esc_html_e( 'Campaign name (internal)', 'checkoutflow' ); ?><br><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $campaign['name'] ); ?>" required></label></p>
	</div>

	<div class="cf-card">
		<h2><?php esc_html_e( '2. Email', 'checkoutflow' ); ?></h2>
		<p>
			<strong><?php esc_html_e( 'Subject:', 'checkoutflow' ); ?></strong>
			<?php echo $email['subject'] ? esc_html( $email['subject'] ) : '<em class="cf-error">' . esc_html__( 'not set', 'checkoutflow' ) . '</em>'; ?>
		</p>
		<a class="button button-primary" href="<?php echo esc_url( $edit_url ); ?>"><?php echo $editable ? esc_html__( 'Design email', 'checkoutflow' ) : esc_html__( 'View email', 'checkoutflow' ); ?></a>
		<?php if ( $editable ) : ?>
			<span class="description"><?php esc_html_e( 'Save changes on this page first; the email editor has its own Save button.', 'checkoutflow' ); ?></span>
		<?php endif; ?>
	</div>

	<div class="cf-card cf-audience">
		<h2><?php esc_html_e( '3. Audience', 'checkoutflow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Consent', 'checkoutflow' ); ?></th>
				<td>
					<label><input type="radio" name="audience[consent]" value="subscribed" <?php checked( $audience['consent'], 'subscribed' ); ?>> <?php esc_html_e( 'Only contacts who opted in (recommended)', 'checkoutflow' ); ?></label><br>
					<label><input type="radio" name="audience[consent]" value="all" <?php checked( $audience['consent'], 'all' ); ?>> <?php esc_html_e( 'All contacts except unsubscribed (make sure you have a lawful basis, e.g. existing customers)', 'checkoutflow' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Who', 'checkoutflow' ); ?></th>
				<td>
					<select name="audience[segment]">
						<option value="all" <?php selected( $audience['segment'], 'all' ); ?>><?php esc_html_e( 'Everyone', 'checkoutflow' ); ?></option>
						<option value="customers" <?php selected( $audience['segment'], 'customers' ); ?>><?php esc_html_e( 'Customers (ordered at least once)', 'checkoutflow' ); ?></option>
						<option value="non_customers" <?php selected( $audience['segment'], 'non_customers' ); ?>><?php esc_html_e( 'Never ordered (subscribers & cart abandoners)', 'checkoutflow' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Filters', 'checkoutflow' ); ?> <span class="cf-muted">(<?php esc_html_e( 'optional', 'checkoutflow' ); ?>)</span></th>
				<td class="cf-filters">
					<label><?php esc_html_e( 'At least', 'checkoutflow' ); ?> <input type="number" min="0" class="small-text" name="audience[min_orders]" value="<?php echo esc_attr( $audience['min_orders'] ); ?>"> <?php esc_html_e( 'orders', 'checkoutflow' ); ?></label>
					<label><?php esc_html_e( 'Spent at least', 'checkoutflow' ); ?> <input type="number" min="0" step="0.01" class="small-text" name="audience[min_spent]" value="<?php echo esc_attr( $audience['min_spent'] ); ?>"> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?></label>
					<label><?php esc_html_e( 'Ordered in the last', 'checkoutflow' ); ?> <input type="number" min="0" class="small-text" name="audience[active_days]" value="<?php echo esc_attr( $audience['active_days'] ); ?>"> <?php esc_html_e( 'days', 'checkoutflow' ); ?></label>
					<label><?php esc_html_e( 'No order in the last', 'checkoutflow' ); ?> <input type="number" min="0" class="small-text" name="audience[inactive_days]" value="<?php echo esc_attr( $audience['inactive_days'] ); ?>"> <?php esc_html_e( 'days', 'checkoutflow' ); ?></label>
					<label><?php esc_html_e( 'Bought product IDs', 'checkoutflow' ); ?> <input type="text" class="regular-text" name="audience[bought_products]" value="<?php echo esc_attr( $audience['bought_products'] ); ?>" placeholder="12, 34"></label>
					<p class="description"><?php esc_html_e( '0 or empty = no filter.', 'checkoutflow' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="cf-count"><?php esc_html_e( 'Matching contacts:', 'checkoutflow' ); ?> <strong id="cf-audience-count"><?php echo esc_html( number_format_i18n( Campaigns::count( $audience ) ) ); ?></strong></p>
	</div>

	<?php if ( $editable ) : ?>
	<div class="cf-card">
		<h2><?php esc_html_e( '4. Send', 'checkoutflow' ); ?></h2>
		<?php if ( 'scheduled' === $campaign['status'] ) : ?>
			<p>
				<?php
				/* translators: %s: date */
				echo esc_html( sprintf( __( 'Scheduled for %s.', 'checkoutflow' ), get_date_from_gmt( $campaign['scheduled_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) );
				?>
				<button type="submit" name="do" value="unschedule" class="button-link"><?php esc_html_e( 'Unschedule', 'checkoutflow' ); ?></button>
			</p>
		<?php endif; ?>
		<p class="cf-actions">
			<button type="submit" name="do" value="save" class="button"><?php esc_html_e( 'Save draft', 'checkoutflow' ); ?></button>
			<button type="submit" name="do" value="send_now" class="button button-primary" data-confirm="<?php esc_attr_e( 'Send this campaign to all matching contacts now?', 'checkoutflow' ); ?>"><?php esc_html_e( 'Send now', 'checkoutflow' ); ?></button>
			<span class="cf-or"><?php esc_html_e( 'or schedule for', 'checkoutflow' ); ?></span>
			<input type="datetime-local" name="scheduled_at" value="<?php echo esc_attr( $local_scheduled ); ?>">
			<button type="submit" name="do" value="schedule" class="button"><?php esc_html_e( 'Schedule', 'checkoutflow' ); ?></button>
		</p>
		<p class="description">
			<?php
			/* translators: %s: timezone */
			echo esc_html( sprintf( __( 'Times are in your site timezone (%s).', 'checkoutflow' ), wp_timezone_string() ) );
			?>
		</p>
	</div>
	<?php endif; ?>
	</fieldset>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-danger">
	<input type="hidden" name="action" value="cf_campaign_action">
	<input type="hidden" name="id" value="<?php echo esc_attr( $campaign['id'] ); ?>">
	<?php wp_nonce_field( 'cf_campaign_action' ); ?>
	<button type="submit" name="do" value="duplicate" class="button"><?php esc_html_e( 'Duplicate', 'checkoutflow' ); ?></button>
	<?php if ( 'sending' === $campaign['status'] ) : ?>
		<button type="submit" name="do" value="cancel" class="button" data-confirm="<?php esc_attr_e( 'Stop sending the remaining emails?', 'checkoutflow' ); ?>"><?php esc_html_e( 'Stop sending', 'checkoutflow' ); ?></button>
	<?php endif; ?>
	<button type="submit" name="do" value="delete" class="button-link cf-delete" data-confirm="<?php esc_attr_e( 'Delete this campaign?', 'checkoutflow' ); ?>"><?php esc_html_e( 'Delete', 'checkoutflow' ); ?></button>
</form>
