<?php
/**
 * Remove all CheckoutFlow data when the plugin is deleted.
 *
 * Define CHECKOUTFLOW_KEEP_DATA in wp-config.php to keep contacts and history.
 *
 * @package CheckoutFlow
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'CHECKOUTFLOW_KEEP_DATA' ) && CHECKOUTFLOW_KEEP_DATA ) {
	return;
}

global $wpdb;
foreach ( array( 'carts', 'contacts', 'campaigns', 'automations', 'queue' ) as $cf_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cf_{$cf_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
foreach ( array( 'checkoutflow_settings', 'checkoutflow_db_version', 'checkoutflow_daily', 'checkoutflow_import' ) as $cf_option ) {
	delete_option( $cf_option );
}
wp_clear_scheduled_hook( 'checkoutflow_tick' );
