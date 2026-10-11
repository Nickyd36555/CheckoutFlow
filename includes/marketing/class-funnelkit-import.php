<?php
/**
 * Import contacts from FunnelKit Automations (Autonami). FunnelKit keeps its contacts in
 * its own tables even after the plugin is deactivated, so this reads them directly:
 *
 *  - {prefix}bwf_contact                email, names, phone, country, tags/lists, status
 *  - {prefix}bwfan_message_unsubscribe  unsubscribes (always respected)
 *  - {prefix}bwfan_terms                tag (type 1) and list (type 2) names
 *
 * Runs in the background in batches (Action Scheduler) and can be re-run safely: contacts
 * are matched by email, nobody is re-subscribed, and unsubscribes always win.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Marketing;

use CheckoutFlow\DB;

defined( 'ABSPATH' ) || exit;

class FunnelKit_Import {

	const HOOK   = 'checkoutflow_funnelkit_import';
	const OPTION = 'checkoutflow_funnelkit_import';
	const BATCH  = 500;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'batch' ) );
	}

	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	public static function available() {
		global $wpdb;
		$t = self::table( 'bwf_contact' );
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Contacts waiting in FunnelKit (with a valid-looking email).
	 */
	public static function count() {
		global $wpdb;
		if ( ! self::available() ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'bwf_contact' ) . " WHERE email LIKE '%@%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function state() {
		$s = get_option( self::OPTION );
		return is_array( $s ) ? $s : array();
	}

	public static function start() {
		update_option(
			self::OPTION,
			array(
				'running'      => true,
				'last_id'      => 0,
				'total'        => self::count(),
				'done'         => 0,
				'subscribed'   => 0,
				'unsubscribed' => 0,
				'started'      => time(),
				'finished'     => 0,
			),
			false
		);
		self::schedule();
	}

	private static function schedule() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'checkoutflow' );
		} else {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Import the next batch and queue the one after it.
	 *
	 * @return array State.
	 */
	public static function batch() {
		global $wpdb;
		if ( doing_action( self::HOOK ) ) {
			if ( get_transient( 'checkoutflow_fk_lock' ) ) {
				self::schedule(); // The Contacts page is running a batch: try again shortly.
				return self::state();
			}
			set_transient( 'checkoutflow_fk_lock', 1, 90 );
			$state = self::run();
			delete_transient( 'checkoutflow_fk_lock' );
			return $state;
		}
		return self::run();
	}

	private static function run() {
		global $wpdb;
		$state = self::state();
		if ( empty( $state['running'] ) || ! self::available() ) {
			return $state;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, wpid, email, f_name, l_name, contact_no, country, tags, lists, status, creation_date FROM ' . self::table( 'bwf_contact' ) . ' WHERE id > %d ORDER BY id ASC LIMIT %d',
				(int) $state['last_id'],
				self::BATCH
			),
			ARRAY_A
		);

		if ( $rows ) {
			$terms  = self::terms();
			$emails = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', wp_list_pluck( $rows, 'email' ) ) ), 'is_email' ) );
			$unsubs = array();
			if ( $emails && self::table_exists( 'bwfan_message_unsubscribe' ) ) {
				$in = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT LOWER(recipient) AS email, MIN(c_date) AS c_date FROM ' . self::table( 'bwfan_message_unsubscribe' ) . " WHERE mode = 1 AND LOWER(recipient) IN ({$in}) GROUP BY LOWER(recipient)", $emails ), ARRAY_A ) as $u ) {
					$unsubs[ $u['email'] ] = $u['c_date'];
				}
			}
			// phpcs:enable

			foreach ( $rows as $r ) {
				$state['last_id'] = (int) $r['id'];
				$email            = strtolower( trim( (string) $r['email'] ) );
				if ( ! is_email( $email ) ) {
					continue;
				}
				$result = self::import_one( $r, $email, isset( $unsubs[ $email ] ) ? $unsubs[ $email ] : null, $terms );
				++$state['done'];
				if ( 'subscribed' === $result ) {
					++$state['subscribed'];
				} elseif ( 'unsubscribed' === $result ) {
					++$state['unsubscribed'];
				}
			}
		}

		if ( count( $rows ) < self::BATCH ) {
			$state['running']  = false;
			$state['finished'] = time();
		}
		update_option( self::OPTION, $state, false );
		if ( ! empty( $state['running'] ) ) {
			self::schedule();
		}
		return $state;
	}

	private static function table_exists( $name ) {
		global $wpdb;
		$t = self::table( $name );
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * FunnelKit tag and list names by ID.
	 */
	private static function terms() {
		global $wpdb;
		if ( ! self::table_exists( 'bwfan_terms' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT ID, name FROM ' . self::table( 'bwfan_terms' ), ARRAY_A ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$out[ (string) $t['ID'] ] = $t['name'];
		}
		return $out;
	}

	/**
	 * @param array       $r       FunnelKit contact row.
	 * @param string      $email   Normalised email.
	 * @param string|null $unsub   Unsubscribe date, if unsubscribed in FunnelKit.
	 * @param array       $terms   Term names by ID.
	 * @return string Resulting status.
	 */
	private static function import_one( $r, $email, $unsub, $terms ) {
		$is_new = ! Contacts::get_by_email( $email );
		$c      = Contacts::upsert(
			$email,
			array(
				'first_name' => (string) $r['f_name'],
				'last_name'  => (string) $r['l_name'],
				'user_id'    => (int) $r['wpid'],
				'phone'      => (string) $r['contact_no'],
				'country'    => strtoupper( substr( (string) $r['country'], 0, 2 ) ),
			),
			'funnelkit',
			true // Only fill blanks: data CheckoutFlow already has (e.g. from orders) wins.
		);
		if ( ! $c ) {
			return '';
		}
		if ( $is_new && ! empty( $r['creation_date'] ) && '0000-00-00 00:00:00' !== $r['creation_date'] ) {
			DB::update( 'contacts', $c['id'], array( 'created_at' => $r['creation_date'] ) );
		}

		$names = array();
		foreach ( array( 'tags', 'lists' ) as $col ) {
			$ids = json_decode( (string) $r[ $col ], true );
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				if ( isset( $terms[ (string) $id ] ) ) {
					$names[] = $terms[ (string) $id ];
				}
			}
		}

		$status = (int) $r['status'];
		if ( null !== $unsub || in_array( $status, array( 2, 4, 5 ), true ) ) {
			// Unsubscribed, bounced or complained in FunnelKit: never email them.
			if ( 'unsubscribed' !== $c['status'] ) {
				$when = $unsub && '0000-00-00 00:00:00' !== $unsub ? $unsub : DB::now();
				DB::update( 'contacts', $c['id'], array( 'status' => 'unsubscribed', 'unsubscribed_at' => $when, 'updated_at' => DB::now() ) );
			}
			if ( null === $unsub ) {
				$names[] = 2 === $status ? 'bounced' : ( 4 === $status ? 'soft bounced' : 'complaint' );
			}
			Contacts::add_tags( $email, $names );
			return 'unsubscribed';
		}

		if ( 1 === $status && 'none' === $c['status'] ) {
			DB::update( 'contacts', $c['id'], array( 'status' => 'subscribed', 'unsubscribed_at' => null, 'updated_at' => DB::now() ) );
			$c['status'] = 'subscribed';
		}
		Contacts::add_tags( $email, $names );
		return $c['status'];
	}
}
