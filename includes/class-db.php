<?php
/**
 * Database schema and small query helpers.
 *
 * Tables (all prefixed with {$wpdb->prefix}cf_):
 *  - carts        captured checkouts for abandoned cart recovery
 *  - contacts     marketing contacts (customers, subscribers, cart abandoners)
 *  - campaigns    one-off broadcasts
 *  - automations  trigger + ordered email steps
 *  - queue        every email to send/sent (doubles as the message log for stats)
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class DB {

	const DB_VERSION     = '2';
	const VERSION_OPTION = 'checkoutflow_db_version';

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'cf_' . $name;
	}

	public static function now() {
		return current_time( 'mysql', true );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::t( 'carts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			email varchar(190) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			items longtext NOT NULL,
			coupons text NOT NULL,
			fields longtext NOT NULL,
			total decimal(19,4) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			restored tinyint(1) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			abandoned_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY email (email),
			KEY status_updated (status,updated_at)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::t( 'contacts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'none',
			source varchar(30) NOT NULL DEFAULT '',
			orders int(10) unsigned NOT NULL DEFAULT 0,
			spent decimal(19,4) NOT NULL DEFAULT 0,
			last_order_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			unsubscribed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status),
			KEY last_order_at (last_order_at)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::t( 'campaigns' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			email longtext NOT NULL,
			audience text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			scheduled_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			recipients int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_scheduled (status,scheduled_at)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::t( 'automations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			trigger_type varchar(40) NOT NULL DEFAULT '',
			trigger_settings text NOT NULL,
			steps longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'paused',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY trigger_status (trigger_type,status)
			) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::t( 'queue' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			step smallint(5) unsigned NOT NULL DEFAULT 0,
			ref varchar(64) NOT NULL DEFAULT '',
			context text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			subject varchar(255) NOT NULL DEFAULT '',
			send_at datetime NOT NULL,
			sent_at datetime DEFAULT NULL,
			opened_at datetime DEFAULT NULL,
			clicked_at datetime DEFAULT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			revenue decimal(19,4) NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_send (status,send_at),
			KEY source_ref (source,source_id,ref),
			KEY email (email),
			KEY sent_at (sent_at)
			) {$c};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
	}

	public static function drop_all() {
		global $wpdb;
		foreach ( array( 'carts', 'contacts', 'campaigns', 'automations', 'queue', 'unsubscribes' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::t( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( self::VERSION_OPTION );
	}

	/* ---------- generic helpers ---------- */

	public static function get( $table, $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( $table ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	public static function insert( $table, $data ) {
		global $wpdb;
		$wpdb->insert( self::t( $table ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( $table, $id, $data ) {
		global $wpdb;
		return $wpdb->update( self::t( $table ), $data, array( 'id' => (int) $id ) );
	}

	public static function delete( $table, $id ) {
		global $wpdb;
		return $wpdb->delete( self::t( $table ), array( 'id' => (int) $id ) );
	}

	/**
	 * Decode a JSON column into an array.
	 *
	 * @param string $json JSON.
	 * @return array
	 */
	public static function json( $json ) {
		$v = json_decode( (string) $json, true );
		return is_array( $v ) ? $v : array();
	}
}
