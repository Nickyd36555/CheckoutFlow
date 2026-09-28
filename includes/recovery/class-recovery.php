<?php
/**
 * Abandoned cart capture, cart restore links, and recovery attribution.
 *
 * Flow:
 *  1. Checkout JS posts the email (and name/phone) once typed -> cart row "active".
 *  2. Cart changes keep the row's items in sync (one write per request, at shutdown).
 *  3. No activity for N minutes -> "abandoned" -> `checkoutflow_cart_abandoned` (automations enroll).
 *  4. Order paid -> row "recovered" (if an email went out / it was restored) or deleted.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Recovery;

use CheckoutFlow\DB;
use CheckoutFlow\Settings;
use CheckoutFlow\Mail\Queue;
use CheckoutFlow\Marketing\Contacts;

defined( 'ABSPATH' ) || exit;

class Recovery {

	const SESSION_KEY = 'cf_cart_id';

	/** @var bool Cart changed during this request. */
	private $dirty = false;

	/**
	 * @param bool $capture Whether capturing new carts is enabled.
	 */
	public function __construct( $capture ) {
		// Always on: close out existing carts when orders come in, and restore links keep working.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'order_created' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_paid' ), 20, 4 );
		add_action( 'wp_loaded', array( $this, 'maybe_restore' ), 30 );

		if ( ! $capture ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wc_ajax_cf_capture', array( $this, 'ajax_capture' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'consent_note' ), 30 );

		foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_emptied' ) as $hook ) {
			add_action( $hook, array( $this, 'mark_dirty' ) );
		}
		add_action( 'shutdown', array( $this, 'sync_items' ) );
	}

	public function assets() {
		if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		list( $js, $ver ) = \CheckoutFlow\asset( 'js/recovery.js' );
		wp_enqueue_script( 'checkoutflow-recovery', $js, array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( 'checkoutflow-recovery', 'checkoutflowRecovery', array( 'url' => \WC_AJAX::get_endpoint( 'cf_capture' ) ) );
	}

	public function consent_note( $fields ) {
		$text = Settings::get( 'recovery_consent_text' );
		if ( $text && isset( $fields['billing']['billing_email'] ) ) {
			$fields['billing']['billing_email']['description'] = esc_html( $text );
		}
		return $fields;
	}

	/**
	 * Snapshot of the current cart.
	 *
	 * @return array
	 */
	private function cart_snapshot() {
		$cart  = WC()->cart;
		$items = array();
		foreach ( $cart->get_cart() as $item ) {
			$items[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'variation'    => isset( $item['variation'] ) ? $item['variation'] : array(),
				'quantity'     => (float) $item['quantity'],
				'line_total'   => (float) $item['line_total'],
				'line_tax'     => (float) $item['line_tax'],
			);
		}
		return array(
			'items'    => wp_json_encode( $items ),
			'coupons'  => wp_json_encode( $cart->get_applied_coupons() ),
			'total'    => (float) $cart->get_total( 'edit' ),
			'currency' => get_woocommerce_currency(),
		);
	}

	public function ajax_capture() {
		// phpcs:disable WordPress.Security.NonceVerification -- low-risk write scoped to the visitor's own session cart.
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_success( array( 'captured' => false ) );
		}

		$fields = array();
		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			foreach ( wp_unslash( $_POST['fields'] ) as $k => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( preg_match( '/^(billing|shipping)_[a-z0-9_]+$/', $k ) && is_scalar( $v ) ) {
					$fields[ $k ] = sanitize_text_field( $v );
				}
			}
		}
		// phpcs:enable

		$data = array_merge(
			$this->cart_snapshot(),
			array(
				'email'      => strtolower( $email ),
				'user_id'    => get_current_user_id(),
				'first_name' => isset( $fields['billing_first_name'] ) ? substr( $fields['billing_first_name'], 0, 100 ) : '',
				'last_name'  => isset( $fields['billing_last_name'] ) ? substr( $fields['billing_last_name'], 0, 100 ) : '',
				'phone'      => isset( $fields['billing_phone'] ) ? substr( $fields['billing_phone'], 0, 40 ) : '',
				'fields'     => wp_json_encode( (object) $fields ),
				'updated_at' => DB::now(),
			)
		);

		$id  = (int) WC()->session->get( self::SESSION_KEY );
		$row = $id ? DB::get( 'carts', $id ) : null;

		if ( $row && in_array( $row['status'], array( 'active', 'abandoned' ), true ) ) {
			if ( $row['email'] !== $data['email'] && 'abandoned' === $row['status'] ) {
				// Different person on the same session: start a fresh cart.
				$row = null;
			} else {
				DB::update( 'carts', $row['id'], $data );
			}
		} else {
			$row = null;
		}

		if ( ! $row ) {
			$id = DB::insert(
				'carts',
				array_merge(
					$data,
					array(
						'token'      => bin2hex( random_bytes( 16 ) ),
						'status'     => 'active',
						'created_at' => DB::now(),
					)
				)
			);
			WC()->session->set( self::SESSION_KEY, $id );
		} else {
			$id = (int) $row['id'];
		}

		// Duplicate carts for one email (two devices) are handled at enrollment: one
		// recovery sequence per shopper per week. Other sessions' carts are never touched
		// here, since this endpoint is unauthenticated.
		Contacts::upsert( $email, array( 'first_name' => $data['first_name'], 'last_name' => $data['last_name'] ), 'cart', true );
		wp_send_json_success( array( 'captured' => true ) );
	}

	public function mark_dirty() {
		$this->dirty = true;
	}

	/**
	 * Keep the captured cart's contents in sync (one DB write per request at most).
	 */
	public function sync_items() {
		if ( ! $this->dirty || ! WC()->session || ! WC()->cart ) {
			return;
		}
		$id = (int) WC()->session->get( self::SESSION_KEY );
		if ( ! $id ) {
			return;
		}
		$row = DB::get( 'carts', $id );
		if ( ! $row || ! in_array( $row['status'], array( 'active', 'abandoned' ), true ) ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			if ( 'active' === $row['status'] ) {
				DB::delete( 'carts', $id );
				WC()->session->set( self::SESSION_KEY, null );
			}
			return;
		}
		WC()->cart->calculate_totals();
		DB::update( 'carts', $id, array_merge( $this->cart_snapshot(), array( 'updated_at' => DB::now() ) ) );
	}

	/* ---------- restore ---------- */

	public function maybe_restore() {
		if ( empty( $_GET['cf-recover'] ) || wp_doing_ajax() || is_admin() || ! WC()->cart ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['cf-recover'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$row   = preg_match( '/^[a-f0-9]{32}$/', $token ) ? $this->get_by_token( $token ) : null;

		if ( ! $row ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		if ( 'recovered' === $row['status'] ) {
			wc_add_notice( __( 'This order has already been placed. Thank you!', 'checkoutflow' ), 'notice' );
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}

		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		WC()->cart->empty_cart();
		$added = 0;
		foreach ( DB::json( $row['items'] ) as $item ) {
			try {
				if ( WC()->cart->add_to_cart( (int) $item['product_id'], (float) $item['quantity'], (int) $item['variation_id'], (array) $item['variation'] ) ) {
					++$added;
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}
		foreach ( DB::json( $row['coupons'] ) as $code ) {
			if ( ! WC()->cart->has_discount( $code ) ) {
				WC()->cart->apply_coupon( $code );
			}
		}
		$this->prefill_customer( $row );

		// Restoring rewrote the cart; keep status as-is but point this session at the row.
		DB::update( 'carts', $row['id'], array( 'restored' => 1 ) );
		WC()->session->set( self::SESSION_KEY, (int) $row['id'] );
		$this->dirty = false;

		if ( ! $added ) {
			wc_add_notice( __( 'Sorry, the items in your saved cart are no longer available.', 'checkoutflow' ), 'error' );
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	private function get_by_token( $token ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'carts' ) . ' WHERE token = %s', $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Pre-fill checkout (classic and block) through the customer object.
	 */
	private function prefill_customer( $row ) {
		$customer = WC()->customer;
		// Only prefill guest sessions: a link must never rewrite a logged-in account's details.
		if ( ! $customer || is_user_logged_in() ) {
			return;
		}
		$fields = DB::json( $row['fields'] );
		$fields['billing_email'] = $row['email'];
		foreach ( $fields as $key => $value ) {
			$setter = 'set_' . $key;
			if ( '' !== $value && is_callable( array( $customer, $setter ) ) ) {
				$customer->$setter( $value );
			}
		}
		$customer->save();
	}

	/* ---------- orders ---------- */

	/**
	 * Link the session's captured cart to the new order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function order_created( $order ) {
		if ( ! $order instanceof \WC_Order || ! WC()->session ) {
			return;
		}
		$id = (int) WC()->session->get( self::SESSION_KEY );
		if ( $id ) {
			$order->update_meta_data( '_cf_cart_id', $id );
			$order->save_meta_data();
			DB::update( 'carts', $id, array( 'order_id' => $order->get_id() ) );
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * When the order is paid, close out every open cart for this customer.
	 */
	public function order_paid( $order_id, $from, $to, $order ) {
		if ( ! $order instanceof \WC_Order || ! in_array( $to, self::placed_statuses(), true ) || $order->get_meta( '_cf_cart_closed' ) ) {
			return;
		}
		global $wpdb;
		$table   = DB::t( 'carts' );
		$cart_id = (int) $order->get_meta( '_cf_cart_id' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ( id = %d OR email = %s ) AND status IN ('active','abandoned')", $cart_id, strtolower( $order->get_billing_email() ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$credited = false;
		foreach ( $rows as $row ) {
			Queue::cancel_pending( 'cart:' . $row['id'], null, 'ordered' );
			$emailed = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . DB::t( 'queue' ) . " WHERE ref = %s AND status = 'sent' LIMIT 1", 'cart:' . $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $credited && 'abandoned' === $row['status'] && ( $emailed || $row['restored'] ) ) {
				DB::update( 'carts', $row['id'], array( 'status' => 'recovered', 'order_id' => $order->get_id(), 'total' => (float) $order->get_total(), 'updated_at' => DB::now() ) );
				$credited = true;
			} else {
				DB::delete( 'carts', $row['id'] );
			}
		}
		$order->update_meta_data( '_cf_cart_closed', 1 );
		$order->save_meta_data();
	}

	/**
	 * Statuses meaning the customer completed checkout (incl. on-hold for BACS/cheque).
	 *
	 * @return string[]
	 */
	public static function placed_statuses() {
		return array_merge( wc_get_is_paid_statuses(), array( 'on-hold' ) );
	}

	/**
	 * Did the order linked to this cart go through?
	 *
	 * @param array $cart Cart row.
	 * @return bool
	 */
	public static function cart_ordered( $cart ) {
		if ( empty( $cart['order_id'] ) ) {
			return false;
		}
		$order = wc_get_order( $cart['order_id'] );
		return $order && in_array( $order->get_status(), self::placed_statuses(), true );
	}

	/* ---------- scheduled ---------- */

	/**
	 * Mark stale active carts as abandoned and fire the automation trigger.
	 */
	public static function mark_abandoned() {
		global $wpdb;
		$table   = DB::t( 'carts' );
		$minutes = max( 5, (int) Settings::get( 'recovery_abandon_after' ) );
		$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'active' AND updated_at < %s LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $ids as $id ) {
			$row = DB::get( 'carts', $id );
			if ( $row && self::cart_ordered( $row ) ) {
				DB::delete( 'carts', $id );
				continue;
			}
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'abandoned', abandoned_at = %s WHERE id = %d AND status = 'active'", DB::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $claimed ) {
				do_action( 'checkoutflow_cart_abandoned', DB::get( 'carts', $id ) );
			}
		}
	}

	public static function cleanup() {
		global $wpdb;
		$days = max( 1, (int) Settings::get( 'recovery_retention_days' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . DB::t( 'carts' ) . " WHERE status IN ('active','abandoned') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
