<?php
/**
 * Sales analytics for the dashboard: orders, revenue, daily series, and where
 * orders came from (WooCommerce order attribution: UTM tags, referrers).
 *
 * Reads the order tables directly (HPOS or legacy posts) so a 90-day report is a
 * couple of queries, and caches each report for 10 minutes.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Admin;

use CheckoutFlow\DB;

defined( 'ABSPATH' ) || exit;

class Analytics {

	const CACHE = 'checkoutflow_sales_';

	/** Attribution meta keys (WooCommerce 8.5+). */
	const ATTR = array( 'source_type', 'utm_source', 'utm_medium', 'utm_campaign', 'referrer' );

	public static function periods() {
		return array(
			'today' => __( 'Today', 'checkoutflow' ),
			'7'     => __( 'Last 7 days', 'checkoutflow' ),
			'30'    => __( 'Last 30 days', 'checkoutflow' ),
			'90'    => __( 'Last 90 days', 'checkoutflow' ),
			'365'   => __( 'Last 12 months', 'checkoutflow' ),
		);
	}

	/**
	 * Counted statuses: paid plus on-hold (manual payments awaiting confirmation).
	 */
	public static function statuses() {
		$s = array_merge( wc_get_is_paid_statuses(), array( 'on-hold' ) );
		return array_values( array_unique( apply_filters( 'checkoutflow_analytics_statuses', $s ) ) );
	}

	/**
	 * Local-time range for a period, and the same-length range before it.
	 *
	 * @param string $period Key from periods().
	 * @return array { start, end, prev_start, prev_end } DateTimeImmutable (site timezone), bucket day|month
	 */
	public static function range( $period ) {
		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'today', $tz );
		$days  = 'today' === $period ? 1 : max( 1, (int) $period );
		$end   = $today->modify( '+1 day' );
		$start = 365 === $days ? $today->modify( 'first day of this month' )->modify( '-11 months' ) : $today->modify( '-' . ( $days - 1 ) . ' days' );
		$len   = $end->getTimestamp() - $start->getTimestamp();
		return array(
			'start'      => $start,
			'end'        => $end,
			'prev_start' => $start->setTimestamp( $start->getTimestamp() - $len ),
			'prev_end'   => $start,
			'bucket'     => 365 === $days ? 'month' : 'day',
		);
	}

	/**
	 * Full report for a period (cached).
	 *
	 * @param string $period Key from periods().
	 * @return array
	 */
	public static function report( $period ) {
		$period = isset( self::periods()[ $period ] ) ? $period : '30';
		$key    = self::CACHE . md5( $period . '|' . wp_date( 'Y-m-d' ) . '|' . implode( ',', self::statuses() ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$r      = self::range( $period );
		$orders = self::orders( $r['start'], $r['end'] );
		$prev   = self::orders( $r['prev_start'], $r['prev_end'], false );

		$report = array(
			'period'    => $period,
			'bucket'    => $r['bucket'],
			'totals'    => self::totals( $orders ),
			'prev'      => self::totals( $prev ),
			'series'    => self::series( $orders, $r ),
			'sources'   => self::group( $orders, array( __CLASS__, 'source_label' ) ),
			'campaigns' => self::group(
				$orders,
				function ( $o ) {
					return '' !== $o['utm_campaign'] ? $o['utm_campaign'] : null;
				},
				true
			),
			'referrers' => self::group(
				$orders,
				function ( $o ) {
					$host = $o['referrer'] ? wp_parse_url( $o['referrer'], PHP_URL_HOST ) : '';
					return $host ? preg_replace( '/^www\./', '', strtolower( $host ) ) : null;
				}
			),
			'recent'    => array_slice( array_reverse( $orders ), 0, 15 ),
			'email'     => self::email_stats( $r['start'], $r['end'] ),
			'has_attr'  => (bool) array_filter( wp_list_pluck( $orders, 'source_type' ) ),
		);
		set_transient( $key, $report, 10 * MINUTE_IN_SECONDS );
		return $report;
	}

	public static function flush() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . self::CACHE . '%', '_transient_timeout_' . self::CACHE . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Orders created in [start, end), oldest first.
	 *
	 * @param \DateTimeImmutable $start Start.
	 * @param \DateTimeImmutable $end   End (exclusive).
	 * @param bool               $attr  Load attribution and customer details.
	 * @return array[] id, ts (unix), total, status, email, name, + attribution fields
	 */
	public static function orders( $start, $end, $attr = true ) {
		global $wpdb;
		$from     = gmdate( 'Y-m-d H:i:s', $start->getTimestamp() );
		$to       = gmdate( 'Y-m-d H:i:s', $end->getTimestamp() );
		$statuses = array_map(
			function ( $s ) {
				return 'wc-' . $s;
			},
			self::statuses()
		);
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
		if ( self::hpos() ) {
			$o    = $wpdb->prefix . 'wc_orders';
			$a    = $wpdb->prefix . 'wc_order_addresses';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.id, o.date_created_gmt AS created, o.total_amount AS total, o.status, o.billing_email AS email, a.first_name, a.last_name
					FROM {$o} o LEFT JOIN {$a} a ON a.order_id = o.id AND a.address_type = 'billing'
					WHERE o.type = 'shop_order' AND o.status IN ({$in}) AND o.date_created_gmt >= %s AND o.date_created_gmt < %s
					ORDER BY o.date_created_gmt ASC",
					array_merge( $statuses, array( $from, $to ) )
				),
				ARRAY_A
			);
			$meta_table = $wpdb->prefix . 'wc_orders_meta';
			$meta_id    = 'order_id';
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS id, p.post_date_gmt AS created, p.post_status AS status,
						MAX(CASE WHEN m.meta_key = '_order_total' THEN m.meta_value END) AS total,
						MAX(CASE WHEN m.meta_key = '_billing_email' THEN m.meta_value END) AS email,
						MAX(CASE WHEN m.meta_key = '_billing_first_name' THEN m.meta_value END) AS first_name,
						MAX(CASE WHEN m.meta_key = '_billing_last_name' THEN m.meta_value END) AS last_name
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_order_total','_billing_email','_billing_first_name','_billing_last_name')
					WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND p.post_date_gmt >= %s AND p.post_date_gmt < %s
					GROUP BY p.ID ORDER BY p.post_date_gmt ASC",
					array_merge( $statuses, array( $from, $to ) )
				),
				ARRAY_A
			);
			$meta_table = $wpdb->postmeta;
			$meta_id    = 'post_id';
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$item = array(
				'id'     => (int) $row['id'],
				'ts'     => strtotime( $row['created'] . ' UTC' ),
				'total'  => (float) $row['total'],
				'status' => preg_replace( '/^wc-/', '', $row['status'] ),
				'email'  => strtolower( (string) $row['email'] ),
				'name'   => trim( $row['first_name'] . ' ' . $row['last_name'] ),
			);
			foreach ( self::ATTR as $f ) {
				$item[ $f ] = '';
			}
			$out[ $item['id'] ] = $item;
		}

		if ( $attr && $out ) {
			$keys = array_map(
				function ( $f ) {
					return '_wc_order_attribution_' . $f;
				},
				self::ATTR
			);
			$kin  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			foreach ( array_chunk( array_keys( $out ), 500 ) as $chunk ) {
				$ids  = implode( ',', array_map( 'intval', $chunk ) );
				$meta = $wpdb->get_results( $wpdb->prepare( "SELECT {$meta_id} AS id, meta_key, meta_value FROM {$meta_table} WHERE {$meta_id} IN ({$ids}) AND meta_key IN ({$kin})", $keys ), ARRAY_A );
				foreach ( (array) $meta as $m ) {
					$f = substr( $m['meta_key'], strlen( '_wc_order_attribution_' ) );
					if ( isset( $out[ (int) $m['id'] ] ) ) {
						$out[ (int) $m['id'] ][ $f ] = (string) $m['meta_value'];
					}
				}
			}
		}
		// phpcs:enable

		return array_values( $out );
	}

	private static function totals( $orders ) {
		$revenue = array_sum( wp_list_pluck( $orders, 'total' ) );
		$count   = count( $orders );
		return array(
			'revenue'   => $revenue,
			'orders'    => $count,
			'aov'       => $count ? $revenue / $count : 0,
			'customers' => count( array_unique( array_filter( wp_list_pluck( $orders, 'email' ) ) ) ),
		);
	}

	/**
	 * Revenue and orders per day (or month), zero-filled.
	 */
	private static function series( $orders, $r ) {
		$fmt  = 'month' === $r['bucket'] ? 'Y-m' : 'Y-m-d';
		$step = 'month' === $r['bucket'] ? '+1 month' : '+1 day';
		$out  = array();
		for ( $d = $r['start']; $d < $r['end']; $d = $d->modify( $step ) ) {
			$out[ $d->format( $fmt ) ] = array(
				'label'   => 'month' === $r['bucket'] ? wp_date( 'M Y', $d->getTimestamp() ) : wp_date( 'M j', $d->getTimestamp() ),
				'revenue' => 0.0,
				'orders'  => 0,
			);
		}
		foreach ( $orders as $o ) {
			$k = wp_date( $fmt, $o['ts'] );
			if ( isset( $out[ $k ] ) ) {
				$out[ $k ]['revenue'] += $o['total'];
				$out[ $k ]['orders']++;
			}
		}
		return array_values( $out );
	}

	/**
	 * WooCommerce's own wording for an order's origin.
	 *
	 * @param array $o Order row.
	 * @return string
	 */
	public static function source_label( $o ) {
		$src = $o['utm_source'];
		switch ( $o['source_type'] ) {
			case 'utm':
				return $src ? ucfirst( $src ) . ( $o['utm_medium'] ? ' / ' . $o['utm_medium'] : '' ) : __( 'Campaign', 'checkoutflow' );
			case 'organic':
				/* translators: %s: search engine */
				return sprintf( __( 'Organic: %s', 'checkoutflow' ), $src ? ucfirst( $src ) : __( 'search', 'checkoutflow' ) );
			case 'referral':
				/* translators: %s: referring site */
				return sprintf( __( 'Referral: %s', 'checkoutflow' ), $src ? $src : __( 'other site', 'checkoutflow' ) );
			case 'typein':
				return __( 'Direct', 'checkoutflow' );
			case 'admin':
				return __( 'Web admin', 'checkoutflow' );
			case 'mobile_app':
				return __( 'Mobile app', 'checkoutflow' );
			case 'pos':
				return __( 'Point of sale', 'checkoutflow' );
		}
		return __( 'Unknown', 'checkoutflow' );
	}

	/**
	 * Group orders by a label: orders, revenue, share; sorted by revenue.
	 *
	 * @param array    $orders Orders.
	 * @param callable $label  Order => label (null to skip).
	 * @param bool     $medium Also collect the UTM source/medium for each group.
	 * @return array[]
	 */
	private static function group( $orders, $label, $medium = false ) {
		$groups = array();
		$total  = array_sum( wp_list_pluck( $orders, 'total' ) );
		foreach ( $orders as $o ) {
			$k = call_user_func( $label, $o );
			if ( null === $k || '' === $k ) {
				continue;
			}
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = array( 'label' => $k, 'orders' => 0, 'revenue' => 0.0, 'via' => array() );
			}
			$groups[ $k ]['orders']++;
			$groups[ $k ]['revenue'] += $o['total'];
			if ( $medium && $o['utm_source'] ) {
				$groups[ $k ]['via'][ $o['utm_source'] . ( $o['utm_medium'] ? ' / ' . $o['utm_medium'] : '' ) ] = true;
			}
		}
		foreach ( $groups as &$g ) {
			$g['share'] = $total > 0 ? $g['revenue'] / $total : 0;
			$g['via']   = implode( ', ', array_keys( $g['via'] ) );
		}
		unset( $g );
		usort(
			$groups,
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);
		return array_slice( $groups, 0, 15 );
	}

	/**
	 * Revenue CheckoutFlow drove in the period: email-attributed and recovered carts.
	 */
	private static function email_stats( $start, $end ) {
		global $wpdb;
		$from = gmdate( 'Y-m-d H:i:s', $start->getTimestamp() );
		$to   = gmdate( 'Y-m-d H:i:s', $end->getTimestamp() );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$email     = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(revenue),0) FROM ' . DB::t( 'queue' ) . ' WHERE sent_at >= %s AND sent_at < %s', $from, $to ) );
		$recovered = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(total),0) FROM ' . DB::t( 'carts' ) . " WHERE status = 'recovered' AND created_at >= %s AND created_at < %s", $from, $to ) );
		// phpcs:enable
		return array( 'email' => $email, 'recovered' => $recovered );
	}
}
