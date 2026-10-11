<?php
/**
 * Adds people to Contacts wherever they leave an email (orders and checkout carts are
 * handled in Contacts and Recovery):
 *
 *  - new WordPress / WooCommerce accounts
 *  - form plugins: Contact Form 7, WPForms, Elementor Pro, Gravity Forms, Fluent Forms
 *  - a webhook for chat widgets and other tools (Apollo, Zapier, Make…):
 *      POST /wp-json/checkoutflow/v1/contacts   (header X-CheckoutFlow-Key: <key>)
 *      { "email": "...", "first_name": "...", "last_name": "...", "phone": "...",
 *        "tags": "chat, vip", "subscribed": true, "source": "apollo" }
 *
 * Captured people are added as contacts without marketing consent ("Not subscribed")
 * unless the webhook says they opted in, so subscriber-only campaigns stay compliant.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Marketing;

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

class Capture {

	const KEY_OPTION = 'checkoutflow_webhook_key';

	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'user_registered' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		if ( Settings::flag( 'capture_forms' ) ) {
			add_action( 'wpcf7_mail_sent', array( __CLASS__, 'cf7' ) );
			add_action( 'wpforms_process_complete', array( __CLASS__, 'wpforms' ), 10, 3 );
			add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'elementor' ), 10, 2 );
			add_action( 'gform_after_submission', array( __CLASS__, 'gravity' ), 10, 2 );
			add_action( 'fluentform/submission_inserted', array( __CLASS__, 'fluent' ), 10, 3 );
		}
	}

	/**
	 * Add someone. Never changes an unsubscribe; only subscribes on explicit consent.
	 *
	 * @param string $email   Email.
	 * @param array  $data    first_name, last_name, phone, user_id.
	 * @param string $source  Source label.
	 * @param array  $tags    Tags.
	 * @param bool   $consent Opted in to marketing.
	 * @return array|null Contact.
	 */
	public static function add( $email, $data, $source, $tags = array(), $consent = false ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return null;
		}
		if ( ! empty( $data['name'] ) && empty( $data['first_name'] ) ) {
			$parts              = preg_split( '/\s+/', trim( (string) $data['name'] ), 2 );
			$data['first_name'] = $parts[0];
			$data['last_name']  = isset( $parts[1] ) ? $parts[1] : '';
		}
		$c = Contacts::upsert( $email, $data, sanitize_key( $source ), true );
		if ( ! $c ) {
			return null;
		}
		if ( $consent ) {
			Contacts::subscribe( $email );
		}
		Contacts::add_tags( $email, $tags );
		do_action( 'checkoutflow_contact_captured', $email, $source );
		return Contacts::get_by_email( $email );
	}

	/* ---------- accounts ---------- */

	public static function user_registered( $user_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return;
		}
		self::add(
			$u->user_email,
			array(
				'first_name' => get_user_meta( $user_id, 'first_name', true ),
				'last_name'  => get_user_meta( $user_id, 'last_name', true ),
				'user_id'    => $user_id,
			),
			'account',
			array( 'account' )
		);
	}

	/* ---------- form plugins ---------- */

	/**
	 * Pull email, name and phone out of a flat label/key => value list.
	 *
	 * @param array  $pairs  [ [ key, value, type ], … ].
	 * @param string $form   Form name, used as a tag.
	 * @param string $source Source.
	 */
	private static function from_pairs( $pairs, $form, $source ) {
		$data  = array();
		$email = '';
		foreach ( $pairs as $p ) {
			list( $key, $value, $type ) = array_pad( $p, 3, '' );
			$key   = strtolower( (string) $key );
			$value = is_array( $value ) ? implode( ' ', array_map( 'strval', $value ) ) : trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( '' === $email && ( 'email' === $type || false !== strpos( $key, 'email' ) ) && is_email( $value ) ) {
				$email = $value;
			} elseif ( empty( $data['first_name'] ) && preg_match( '/first.?name|fname|given/', $key ) ) {
				$data['first_name'] = $value;
			} elseif ( empty( $data['last_name'] ) && preg_match( '/last.?name|lname|surname|family/', $key ) ) {
				$data['last_name'] = $value;
			} elseif ( empty( $data['name'] ) && ( 'name' === $type || preg_match( '/(^|[^a-z])(your-)?name$/', $key ) ) ) {
				$data['name'] = $value;
			} elseif ( empty( $data['phone'] ) && ( in_array( $type, array( 'tel', 'phone' ), true ) || preg_match( '/phone|tel|mobile/', $key ) ) ) {
				$data['phone'] = $value;
			}
		}
		if ( '' === $email ) {
			foreach ( $pairs as $p ) {
				if ( is_string( $p[1] ) && is_email( trim( $p[1] ) ) ) {
					$email = trim( $p[1] );
					break;
				}
			}
		}
		if ( '' !== $email ) {
			self::add( $email, $data, $source, array_filter( array( 'form', $form ) ) );
		}
	}

	public static function cf7( $form ) {
		if ( ! class_exists( '\WPCF7_Submission' ) ) {
			return;
		}
		$sub = \WPCF7_Submission::get_instance();
		if ( ! $sub ) {
			return;
		}
		$pairs = array();
		foreach ( (array) $sub->get_posted_data() as $k => $v ) {
			$pairs[] = array( $k, $v, '' );
		}
		self::from_pairs( $pairs, method_exists( $form, 'title' ) ? $form->title() : '', 'form' );
	}

	public static function wpforms( $fields, $entry, $form_data ) {
		$pairs = array();
		foreach ( (array) $fields as $f ) {
			$pairs[] = array( isset( $f['name'] ) ? $f['name'] : '', isset( $f['value'] ) ? $f['value'] : '', isset( $f['type'] ) ? $f['type'] : '' );
			if ( isset( $f['type'] ) && 'name' === $f['type'] && ! empty( $f['first'] ) ) {
				$pairs[] = array( 'first_name', $f['first'], '' );
				$pairs[] = array( 'last_name', isset( $f['last'] ) ? $f['last'] : '', '' );
			}
		}
		self::from_pairs( $pairs, isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : '', 'form' );
	}

	public static function elementor( $record, $handler ) {
		$pairs = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $f ) {
			$pairs[] = array( isset( $f['title'] ) && $f['title'] ? $f['title'] : $id, isset( $f['value'] ) ? $f['value'] : '', isset( $f['type'] ) ? $f['type'] : '' );
		}
		self::from_pairs( $pairs, (string) $record->get_form_settings( 'form_name' ), 'form' );
	}

	public static function gravity( $entry, $form ) {
		$pairs = array();
		foreach ( (array) ( isset( $form['fields'] ) ? $form['fields'] : array() ) as $field ) {
			$type = is_object( $field ) ? $field->type : '';
			$id   = is_object( $field ) ? $field->id : 0;
			if ( 'name' === $type ) {
				$pairs[] = array( 'first_name', rgar( $entry, $id . '.3' ), '' );
				$pairs[] = array( 'last_name', rgar( $entry, $id . '.6' ), '' );
				continue;
			}
			$pairs[] = array( is_object( $field ) ? $field->label : '', rgar( $entry, (string) $id ), $type );
		}
		self::from_pairs( $pairs, isset( $form['title'] ) ? $form['title'] : '', 'form' );
	}

	public static function fluent( $entry_id, $form_data, $form ) {
		$pairs = array();
		foreach ( (array) $form_data as $k => $v ) {
			if ( is_array( $v ) && isset( $v['first_name'] ) ) {
				$pairs[] = array( 'first_name', $v['first_name'], '' );
				$pairs[] = array( 'last_name', isset( $v['last_name'] ) ? $v['last_name'] : '', '' );
				continue;
			}
			$pairs[] = array( $k, $v, '' );
		}
		self::from_pairs( $pairs, is_object( $form ) && isset( $form->title ) ? $form->title : '', 'form' );
	}

	/* ---------- webhook ---------- */

	public static function key( $regenerate = false ) {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( '' === $key || $regenerate ) {
			$key = wp_generate_password( 32, false );
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	public static function url() {
		return rest_url( 'checkoutflow/v1/contacts' );
	}

	public static function routes() {
		register_rest_route(
			'checkoutflow/v1',
			'/contacts',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => array( __CLASS__, 'webhook_allowed' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $req Request.
	 */
	public static function webhook_allowed( $req ) {
		$given = (string) $req->get_header( 'x-checkoutflow-key' );
		if ( '' === $given ) {
			$given = (string) $req->get_param( 'key' );
		}
		$key = (string) get_option( self::KEY_OPTION, '' );
		return '' !== $key && '' !== $given && hash_equals( $key, $given );
	}

	/**
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function webhook( $req ) {
		$p     = $req->get_params();
		$email = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'cf_invalid_email', 'A valid "email" is required.', array( 'status' => 400 ) );
		}
		$tags = isset( $p['tags'] ) ? ( is_array( $p['tags'] ) ? $p['tags'] : explode( ',', (string) $p['tags'] ) ) : array();
		$src  = isset( $p['source'] ) && '' !== $p['source'] ? (string) $p['source'] : 'webhook';
		$tags = array_merge( array( $src ), $tags );
		$c    = self::add(
			$email,
			array(
				'first_name' => isset( $p['first_name'] ) ? (string) $p['first_name'] : '',
				'last_name'  => isset( $p['last_name'] ) ? (string) $p['last_name'] : '',
				'name'       => isset( $p['name'] ) ? (string) $p['name'] : '',
				'phone'      => isset( $p['phone'] ) ? (string) $p['phone'] : '',
			),
			$src,
			$tags,
			! empty( $p['subscribed'] ) && 'false' !== $p['subscribed']
		);
		return rest_ensure_response( array( 'ok' => true, 'id' => $c ? (int) $c['id'] : 0, 'status' => $c ? $c['status'] : '' ) );
	}
}
