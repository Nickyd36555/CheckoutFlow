<?php
/**
 * Contacts: synced from orders, checkout opt-in, unsubscribes, privacy tools.
 *
 * Status:
 *  - subscribed   explicitly opted in (checkout checkbox, import with consent, manual)
 *  - none         known customer / cart abandoner without explicit marketing consent
 *  - unsubscribed never email again
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Marketing;

use CheckoutFlow\DB;
use CheckoutFlow\Settings;
use CheckoutFlow\Mail\Queue;

defined( 'ABSPATH' ) || exit;

class Contacts {

	const IMPORT_HOOK = 'checkoutflow_import_contacts';

	public static function init() {
		if ( Settings::flag( 'optin_enabled' ) ) {
			add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'optin_field' ) );
			add_action( 'woocommerce_init', array( __CLASS__, 'register_block_optin' ) );
		}
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_created' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_paid' ), 10, 4 );
		add_action( self::IMPORT_HOOK, array( __CLASS__, 'import_batch' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function get_by_email( $email ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::t( 'contacts' ) . ' WHERE email = %s', strtolower( trim( $email ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Create or update a contact. Never downgrades a status; use subscribe()/unsubscribe() for that.
	 *
	 * @param string $email  Email.
	 * @param array  $data   first_name, last_name, user_id.
	 * @param string $source    Where it came from (order, cart, import, manual).
	 * @param bool   $fill_only Only fill empty fields (for unverified input like cart capture).
	 * @return array|null Contact row.
	 */
	public static function upsert( $email, $data = array(), $source = '', $fill_only = false ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return null;
		}
		$existing = self::get_by_email( $email );
		$now      = DB::now();
		$fields   = array_intersect_key( $data, array_flip( array( 'first_name', 'last_name', 'user_id' ) ) );
		$fields   = array_filter( $fields );

		if ( ! $existing ) {
			DB::insert(
				'contacts',
				array_merge(
					array(
						'email'      => $email,
						'status'     => 'none',
						'source'     => $source,
						'created_at' => $now,
						'updated_at' => $now,
					),
					$fields
				)
			);
		} else {
			if ( $fill_only ) {
				foreach ( $fields as $k => $v ) {
					if ( ! empty( $existing[ $k ] ) ) {
						unset( $fields[ $k ] );
					}
				}
			}
			if ( ! $fields ) {
				return $existing;
			}
			DB::update( 'contacts', $existing['id'], array_merge( $fields, array( 'updated_at' => $now ) ) );
		}
		return self::get_by_email( $email );
	}

	/**
	 * @param string $email  Email.
	 * @param bool   $force  Re-subscribe an unsubscribed contact (only for explicit new consent).
	 */
	public static function subscribe( $email, $force = false ) {
		$c = self::upsert( $email );
		if ( ! $c || 'subscribed' === $c['status'] || ( 'unsubscribed' === $c['status'] && ! $force ) ) {
			return;
		}
		DB::update( 'contacts', $c['id'], array( 'status' => 'subscribed', 'unsubscribed_at' => null, 'updated_at' => DB::now() ) );
		do_action( 'checkoutflow_contact_subscribed', $c['email'] );
	}

	public static function unsubscribe( $email ) {
		$c = self::upsert( $email );
		if ( ! $c ) {
			return;
		}
		if ( 'unsubscribed' !== $c['status'] ) {
			DB::update( 'contacts', $c['id'], array( 'status' => 'unsubscribed', 'unsubscribed_at' => DB::now(), 'updated_at' => DB::now() ) );
		}
		Queue::cancel_pending( null, $c['email'], 'unsubscribed' );
	}

	/* ---------- checkout opt-in ---------- */

	public static function optin_field() {
		$checked = Settings::get( 'optin_checked' );
		if ( is_user_logged_in() ) {
			$c = self::get_by_email( wp_get_current_user()->user_email );
			if ( $c && 'subscribed' === $c['status'] ) {
				return; // Already subscribed – don't ask again.
			}
		}
		printf(
			'<p class="form-row cf-optin"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="cf_optin" value="1" %s> <span>%s</span></label></p>',
			checked( $checked, true, false ),
			esc_html( Settings::get( 'optin_label' ) )
		);
	}

	/**
	 * Block checkout support via the Additional Checkout Fields API (WooCommerce 8.9+).
	 */
	public static function register_block_optin() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'checkoutflow/optin',
				'label'    => Settings::get( 'optin_label' ),
				'location' => 'order',
				'type'     => 'checkbox',
			)
		);
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	public static function order_created( $order ) {
		if ( ! $order instanceof \WC_Order || ! $order->get_billing_email() ) {
			return;
		}
		self::upsert(
			$order->get_billing_email(),
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'user_id'    => $order->get_customer_id(),
			),
			'order'
		);

		$optin = ! empty( $_POST['cf_optin'] ) // phpcs:ignore WordPress.Security.NonceVerification -- checkout nonce already verified by WooCommerce.
			|| wc_string_to_bool( $order->get_meta( '_wc_other/checkoutflow/optin' ) );
		if ( $optin ) {
			$order->update_meta_data( '_cf_optin', 1 );
			$order->save_meta_data();
			self::subscribe( $order->get_billing_email(), true );
		}
	}

	/**
	 * Update contact stats once per paid order.
	 */
	public static function order_paid( $order_id, $from, $to, $order ) {
		if ( ! $order instanceof \WC_Order || ! in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			return;
		}
		self::record_order( $order );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @param bool      $live  False when importing history: stats are updated but no
	 *                         automations fire (no emails about years-old orders).
	 * @return bool Whether it was counted now.
	 */
	public static function record_order( $order, $live = true ) {
		if ( $order->get_meta( '_cf_contact_synced' ) || ! $order->get_billing_email() || $order->get_parent_id() ) {
			return false;
		}
		$c = self::upsert(
			$order->get_billing_email(),
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'user_id'    => $order->get_customer_id(),
			),
			'order'
		);
		if ( ! $c ) {
			return false;
		}
		global $wpdb;
		$created = $order->get_date_created() ? gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() ) : DB::now();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . DB::t( 'contacts' ) . ' SET orders = orders + 1, spent = spent + %f, last_order_at = GREATEST(COALESCE(last_order_at, %s), %s), updated_at = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(float) $order->get_total() - (float) $order->get_total_refunded(),
				$created,
				$created,
				DB::now(),
				$c['id']
			)
		);
		$order->update_meta_data( '_cf_contact_synced', 1 );
		$order->save_meta_data();

		if ( $live ) {
			do_action( 'checkoutflow_order_recorded', $order, $c );
		}
		return true;
	}

	/* ---------- import existing customers (background) ---------- */

	public static function start_import() {
		update_option( 'checkoutflow_import', array( 'page' => 1, 'done' => 0, 'running' => true ), false );
		as_enqueue_async_action( self::IMPORT_HOOK, array( 1 ), 'checkoutflow' );
	}

	public static function import_batch( $page ) {
		$result = wc_get_orders(
			array(
				'status'   => wc_get_is_paid_statuses(),
				'type'     => 'shop_order',
				'limit'    => 100,
				'page'     => (int) $page,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'paginate' => true,
			)
		);
		$state = get_option( 'checkoutflow_import', array( 'done' => 0 ) );
		foreach ( $result->orders as $order ) {
			if ( self::record_order( $order, false ) ) {
				++$state['done'];
			}
		}
		if ( $page < $result->max_num_pages ) {
			$state['page'] = $page + 1;
			as_enqueue_async_action( self::IMPORT_HOOK, array( $page + 1 ), 'checkoutflow' );
		} else {
			$state['running'] = false;
		}
		update_option( 'checkoutflow_import', $state, false );
	}

	/**
	 * Import contacts from a CSV (header row with at least "email").
	 *
	 * @param string $file      Path.
	 * @param bool   $subscribe Mark as subscribed (user confirms they have consent).
	 * @return int Number of contacts imported.
	 */
	public static function import_csv( $file, $subscribe ) {
		$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return 0;
		}
		$header = fgetcsv( $fh, 0, ',', '"', '\\' );
		$header = array_map(
			static function ( $h ) {
				return strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
			},
			(array) $header
		);
		$map = array(
			'email'      => array_search( 'email', $header, true ),
			'first_name' => array_search( 'first_name', $header, true ),
			'last_name'  => array_search( 'last_name', $header, true ),
		);
		if ( false === $map['email'] ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 0;
		}
		$count = 0;
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$email = isset( $row[ $map['email'] ] ) ? sanitize_email( $row[ $map['email'] ] ) : '';
			if ( ! $email ) {
				continue;
			}
			$c = self::upsert(
				$email,
				array(
					'first_name' => false !== $map['first_name'] && isset( $row[ $map['first_name'] ] ) ? sanitize_text_field( $row[ $map['first_name'] ] ) : '',
					'last_name'  => false !== $map['last_name'] && isset( $row[ $map['last_name'] ] ) ? sanitize_text_field( $row[ $map['last_name'] ] ) : '',
				),
				'import'
			);
			if ( $c && $subscribe ) {
				self::subscribe( $email );
			}
			$count += $c ? 1 : 0;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $count;
	}

	/* ---------- privacy ---------- */

	public static function register_exporter( $exporters ) {
		$exporters['checkoutflow'] = array(
			'exporter_friendly_name' => __( 'CheckoutFlow marketing data', 'checkoutflow' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['checkoutflow'] = array(
			'eraser_friendly_name' => __( 'CheckoutFlow marketing data', 'checkoutflow' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email ) {
		$c    = self::get_by_email( $email );
		$data = array();
		if ( $c ) {
			$data[] = array(
				'group_id'    => 'checkoutflow',
				'group_label' => __( 'Marketing contact', 'checkoutflow' ),
				'item_id'     => 'cf-contact-' . $c['id'],
				'data'        => array(
					array( 'name' => __( 'Email', 'checkoutflow' ), 'value' => $c['email'] ),
					array( 'name' => __( 'Name', 'checkoutflow' ), 'value' => trim( $c['first_name'] . ' ' . $c['last_name'] ) ),
					array( 'name' => __( 'Marketing status', 'checkoutflow' ), 'value' => $c['status'] ),
					array( 'name' => __( 'Created', 'checkoutflow' ), 'value' => $c['created_at'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( $email ) {
		global $wpdb;
		$email   = strtolower( $email );
		$removed = 0;
		foreach ( array( 'contacts', 'carts', 'queue' ) as $t ) {
			$removed += (int) $wpdb->delete( DB::t( $t ), array( 'email' => $email ) );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
