<?php
/**
 * Campaigns (broadcasts): audience segmentation and dispatch into the queue.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Marketing;

use CheckoutFlow\DB;
use CheckoutFlow\Mail\Renderer;

defined( 'ABSPATH' ) || exit;

class Campaigns {

	public static function default_audience() {
		return array(
			'consent'          => 'subscribed',
			'segment'          => 'all',
			'min_orders'       => 0,
			'min_spent'        => 0,
			'inactive_days'    => 0,
			'active_days'      => 0,
			'bought_products'  => '',
		);
	}

	public static function sanitize_audience( $raw ) {
		$raw = is_array( $raw ) ? wp_unslash( $raw ) : array();
		$a   = array_merge( self::default_audience(), $raw );
		return array(
			'consent'         => 'all' === $a['consent'] ? 'all' : 'subscribed',
			'segment'         => in_array( $a['segment'], array( 'all', 'customers', 'non_customers' ), true ) ? $a['segment'] : 'all',
			'min_orders'      => max( 0, (int) $a['min_orders'] ),
			'min_spent'       => max( 0, (float) $a['min_spent'] ),
			'inactive_days'   => max( 0, (int) $a['inactive_days'] ),
			'active_days'     => max( 0, (int) $a['active_days'] ),
			'bought_products' => implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $a['bought_products'] ) ) ) ),
		);
	}

	/**
	 * SQL WHERE clause (for the contacts table aliased as c) matching an audience.
	 *
	 * @param array $a Audience.
	 * @return string Prepared SQL fragment.
	 */
	public static function where( $a ) {
		global $wpdb;
		$a     = self::sanitize_audience( $a );
		$where = array( "c.status <> 'unsubscribed'" );

		if ( 'subscribed' === $a['consent'] ) {
			$where[] = "c.status = 'subscribed'";
		}
		if ( 'customers' === $a['segment'] ) {
			$where[] = 'c.orders > 0';
		} elseif ( 'non_customers' === $a['segment'] ) {
			$where[] = 'c.orders = 0';
		}
		if ( $a['min_orders'] > 0 ) {
			$where[] = $wpdb->prepare( 'c.orders >= %d', $a['min_orders'] );
		}
		if ( $a['min_spent'] > 0 ) {
			$where[] = $wpdb->prepare( 'c.spent >= %f', $a['min_spent'] );
		}
		if ( $a['inactive_days'] > 0 ) {
			$where[] = $wpdb->prepare( 'c.last_order_at IS NOT NULL AND c.last_order_at <= %s', gmdate( 'Y-m-d H:i:s', time() - $a['inactive_days'] * DAY_IN_SECONDS ) );
		}
		if ( $a['active_days'] > 0 ) {
			$where[] = $wpdb->prepare( 'c.last_order_at >= %s', gmdate( 'Y-m-d H:i:s', time() - $a['active_days'] * DAY_IN_SECONDS ) );
		}
		if ( $a['bought_products'] ) {
			$ids     = implode( ',', array_map( 'absint', explode( ',', $a['bought_products'] ) ) );
			$where[] = "c.email IN ( SELECT LOWER(cl.email) FROM {$wpdb->prefix}wc_customer_lookup cl INNER JOIN {$wpdb->prefix}wc_order_product_lookup opl ON opl.customer_id = cl.customer_id WHERE opl.product_id IN ({$ids}) OR opl.variation_id IN ({$ids}) )";
		}
		return implode( ' AND ', $where );
	}

	public static function count( $audience ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::t( 'contacts' ) . ' c WHERE ' . self::where( $audience ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function create() {
		$now = DB::now();
		return DB::insert(
			'campaigns',
			array(
				'name'       => __( 'Untitled campaign', 'checkoutflow' ),
				'email'      => wp_json_encode(
					array(
						'subject'   => '',
						'preheader' => '',
						'design'    => Renderer::design(
							array(
								array( 'heading', array( 'text' => __( 'Big news from {site_name}', 'checkoutflow' ), 'align' => 'center' ) ),
								array( 'image' ),
								array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there},', 'checkoutflow' ) . '</p><p>' . __( 'Tell your customers what\'s new.', 'checkoutflow' ) . '</p>' ) ),
								array( 'button', array( 'text' => __( 'Shop now', 'checkoutflow' ), 'url' => '{shop_url}' ) ),
							)
						),
					)
				),
				'audience'   => wp_json_encode( self::default_audience() ),
				'status'     => 'draft',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
	}

	/**
	 * Move due scheduled campaigns into the queue, and close finished ones.
	 */
	public static function dispatch_due() {
		global $wpdb;
		$ct = DB::t( 'campaigns' );
		$qt = DB::t( 'queue' );

		$due = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ct} WHERE status = 'scheduled' AND scheduled_at <= %s", DB::now() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $due as $campaign ) {
			// Claim first so two overlapping runs can't both enqueue recipients.
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET status = 'sending' WHERE id = %d AND status = 'scheduled'", $campaign['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $claimed ) {
				continue;
			}
			$where = self::where( DB::json( $campaign['audience'] ) );
			$now   = DB::now();
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"INSERT INTO {$qt} (email, contact_id, source, source_id, step, ref, context, status, subject, send_at, created_at, error)
					SELECT c.email, c.id, 'campaign', %d, 0, '', '{}', 'pending', '', %s, %s, '' FROM " . DB::t( 'contacts' ) . " c WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$campaign['id'],
					$now,
					$now
				)
			);
			DB::update( 'campaigns', $campaign['id'], array( 'recipients' => (int) $wpdb->rows_affected, 'updated_at' => $now ) );
		}

		// Sending campaigns with nothing left in the queue are done.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$ct} SET status = 'sent', sent_at = %s WHERE status = 'sending' AND NOT EXISTS ( SELECT 1 FROM {$qt} q WHERE q.source = 'campaign' AND q.source_id = {$ct}.id AND q.status IN ('pending','sending') )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				DB::now()
			)
		);
	}

	/**
	 * Aggregate stats for a source (campaign/automation).
	 *
	 * @return array sent, opened, clicked, revenue, orders, failed, pending
	 */
	public static function stats( $source, $source_id = null, $since = null ) {
		global $wpdb;
		$where = $wpdb->prepare( 'source = %s', $source );
		if ( null !== $source_id ) {
			$where .= $wpdb->prepare( ' AND source_id = %d', $source_id );
		}
		if ( null !== $since ) {
			$where .= $wpdb->prepare( ' AND created_at >= %s', $since );
		}
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT
				SUM(status = 'sent') AS sent,
				SUM(opened_at IS NOT NULL) AS opened,
				SUM(clicked_at IS NOT NULL) AS clicked,
				SUM(order_id > 0) AS orders,
				COALESCE(SUM(revenue), 0) AS revenue,
				SUM(status = 'failed') AS failed,
				SUM(status IN ('pending','sending')) AS pending
			FROM " . DB::t( 'queue' ) . " WHERE {$where}",
			ARRAY_A
		);
		return array_map( 'floatval', $row ? $row : array() );
	}
}
