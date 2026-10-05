<?php
/**
 * Checkout field editor: relabel, reorder, resize, require or hide WooCommerce's
 * fields, add custom fields (text, date of birth, dropdowns, checkboxes…), text-only
 * blocks and extra sections. Custom answers are saved on the order and shown in the
 * order screen and emails.
 *
 * Stored in the `checkoutflow_fields` option:
 * {
 *   "fields":   [ { key, label, placeholder, required, enabled, width, section, type, options, content, min_age, custom }, … ],
 *   "sections": [ { id, title, position } ]
 * }
 * Built-in address fields (first_name, city, …) apply to billing and shipping alike.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Checkout_Fields {

	const OPTION = 'checkoutflow_fields';
	const META   = '_checkoutflow_fields';

	/** Built-in address fields shared by billing and shipping, in default order. */
	const ADDRESS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'phone' );

	/** Fields that cannot be hidden (WooCommerce needs them). */
	const LOCKED = array( 'email', 'country' );

	const TYPES = array( 'text', 'textarea', 'email', 'tel', 'number', 'date', 'select', 'radio', 'checkbox', 'paragraph' );

	const POSITIONS = array( 'after_contact', 'after_address', 'before_payment' );

	/** @var array|null */
	private static $config = null;

	/** @var bool Building WooCommerce's untouched defaults. */
	private static $raw = false;

	public function __construct() {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'checkout_fields' ), 30 );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'address_fields' ), 30 );
		add_filter( 'woocommerce_get_country_locale', array( $this, 'country_locale' ), 30 );
		add_filter( 'woocommerce_form_field_cf_paragraph', array( $this, 'paragraph_html' ), 10, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_display' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_fields' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'customer_display' ) );
	}

	/* ---------- config ---------- */

	/**
	 * Saved config, or one built from WooCommerce's fields and the older per-field settings.
	 *
	 * @return array
	 */
	public static function config() {
		if ( null !== self::$config ) {
			return self::$config;
		}
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) && ! empty( $saved['fields'] ) ) {
			self::$config = self::sanitize( $saved );
		} else {
			self::$config = self::initial();
		}
		return self::$config;
	}

	public static function is_saved() {
		$saved = get_option( self::OPTION );
		return is_array( $saved ) && ! empty( $saved['fields'] );
	}

	public static function reset_cache() {
		self::$config = null;
	}

	/**
	 * WooCommerce's own field definitions, without this editor's changes.
	 *
	 * @return array { address: key => field, email: field, order_comments: field }
	 */
	public static function originals() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		// Only the default address fields: get_address_fields() would build (and cache) the
		// country locale while this editor's locale filter is switched off.
		self::$raw = true;
		$address   = WC()->countries->get_default_address_fields();
		self::$raw = false;
		$address['phone'] = array(
			'label'    => __( 'Phone', 'woocommerce' ),
			'required' => 'required' === get_option( 'woocommerce_checkout_phone_field', 'required' ),
		);
		$cache = array(
			'address'        => $address,
			'email'          => array( 'label' => __( 'Email address', 'woocommerce' ), 'required' => true ),
			'order_comments' => array(
				'label'       => __( 'Order notes', 'woocommerce' ),
				'placeholder' => esc_attr__( 'Notes about your order, e.g. special notes for delivery.', 'woocommerce' ),
			),
		);
		return $cache;
	}

	/**
	 * First-run config: WooCommerce's fields plus the older Company / Address 2 / Phone /
	 * Order notes settings, so nothing changes until the editor is saved.
	 */
	private static function initial() {
		$orig   = self::originals();
		$legacy = get_option( Settings::OPTION, array() );
		$state  = function ( $key, $default ) use ( $legacy ) {
			return isset( $legacy[ $key ] ) ? $legacy[ $key ] : $default;
		};
		$fields = array();
		$row    = function ( $key, $f, $section, $extra = array() ) {
			return array_merge(
				array(
					'key'         => $key,
					'label'       => isset( $f['label'] ) ? wp_strip_all_tags( $f['label'] ) : $key,
					'placeholder' => isset( $f['placeholder'] ) ? (string) $f['placeholder'] : '',
					'required'    => ! empty( $f['required'] ),
					'enabled'     => true,
					'width'       => in_array( 'form-row-first', (array) ( isset( $f['class'] ) ? $f['class'] : array() ), true ) || in_array( 'form-row-last', (array) ( isset( $f['class'] ) ? $f['class'] : array() ), true ) ? 'half' : 'full',
					'section'     => $section,
					'type'        => 'text',
					'custom'      => false,
				),
				$extra
			);
		};

		$fields[] = $row( 'email', $orig['email'], 'contact', array( 'required' => true, 'width' => 'full' ) );

		$widths = array( 'first_name' => 'half', 'last_name' => 'half', 'city' => 'half', 'postcode' => 'half', 'country' => 'half', 'state' => 'half' );
		foreach ( self::ADDRESS as $k ) {
			$f     = isset( $orig['address'][ $k ] ) ? $orig['address'][ $k ] : array( 'label' => $k );
			$extra = array( 'width' => isset( $widths[ $k ] ) ? $widths[ $k ] : 'full' );
			$map   = array( 'company' => 'field_billing_company', 'address_2' => 'field_billing_address_2', 'phone' => 'field_billing_phone' );
			if ( isset( $map[ $k ] ) ) {
				$s                 = $state( $map[ $k ], 'company' === $k ? 'hidden' : 'optional' );
				$extra['enabled']  = 'hidden' !== $s;
				$extra['required'] = 'required' === $s;
			}
			if ( 'company' === $k && ! empty( $legacy['field_company_label'] ) ) {
				$extra['label'] = $legacy['field_company_label'];
			}
			$fields[] = $row( $k, $f, 'address', $extra );
		}

		$fields[] = $row( 'order_comments', $orig['order_comments'], 'notes', array( 'enabled' => 'hidden' !== $state( 'field_order_comments', 'hidden' ), 'type' => 'textarea' ) );

		return array( 'fields' => $fields, 'sections' => array() );
	}

	/**
	 * @param mixed $raw Decoded config.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$sections = array();
		foreach ( (array) ( isset( $raw['sections'] ) ? $raw['sections'] : array() ) as $s ) {
			$id = isset( $s['id'] ) ? sanitize_key( $s['id'] ) : '';
			if ( '' === $id || in_array( $id, array( 'contact', 'address', 'notes' ), true ) ) {
				continue;
			}
			$sections[ $id ] = array(
				'id'       => $id,
				'title'    => sanitize_text_field( isset( $s['title'] ) ? $s['title'] : '' ),
				'position' => isset( $s['position'] ) && in_array( $s['position'], self::POSITIONS, true ) ? $s['position'] : 'after_address',
			);
		}
		$valid_sections = array_merge( array( 'contact', 'address', 'notes' ), array_keys( $sections ) );
		$builtin        = array_merge( array( 'email', 'order_comments' ), self::ADDRESS );

		$fields = array();
		$seen   = array();
		foreach ( (array) ( isset( $raw['fields'] ) ? $raw['fields'] : array() ) as $f ) {
			$custom = ! empty( $f['custom'] );
			$key    = isset( $f['key'] ) ? sanitize_key( $f['key'] ) : '';
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( $custom ) {
				// Custom keys live in their own namespace so they never collide with WooCommerce's.
				$key = 0 === strpos( $key, 'cf_' ) ? $key : 'cf_' . $key;
			} elseif ( ! in_array( $key, $builtin, true ) ) {
				continue;
			}
			$type = $custom && isset( $f['type'] ) && in_array( $f['type'], self::TYPES, true ) ? $f['type'] : ( 'order_comments' === $key ? 'textarea' : 'text' );
			if ( $custom ) {
				$section = isset( $f['section'] ) && in_array( $f['section'], $valid_sections, true ) ? $f['section'] : 'address';
			} else {
				$section = 'email' === $key ? 'contact' : ( 'order_comments' === $key ? 'notes' : 'address' );
			}
			$seen[ $key ] = true;
			$fields[]     = array(
				'key'         => $key,
				'label'       => sanitize_text_field( isset( $f['label'] ) ? $f['label'] : '' ),
				'placeholder' => sanitize_text_field( isset( $f['placeholder'] ) ? $f['placeholder'] : '' ),
				'required'    => 'paragraph' !== $type && ( 'email' === $key || ! empty( $f['required'] ) ),
				'enabled'     => in_array( $key, self::LOCKED, true ) || ! empty( $f['enabled'] ),
				'width'       => isset( $f['width'] ) && 'half' === $f['width'] && ! in_array( $type, array( 'paragraph', 'textarea' ), true ) ? 'half' : 'full',
				'section'     => $section,
				'type'        => $type,
				'options'     => sanitize_textarea_field( isset( $f['options'] ) ? $f['options'] : '' ),
				'content'     => wp_kses_post( isset( $f['content'] ) ? $f['content'] : '' ),
				'min_age'     => isset( $f['min_age'] ) ? max( 0, min( 120, (int) $f['min_age'] ) ) : 0,
				'custom'      => $custom,
			);
		}
		// Built-ins can't be deleted: put back any that are missing.
		$have = wp_list_pluck( $fields, 'key' );
		foreach ( self::initial()['fields'] as $f ) {
			if ( ! in_array( $f['key'], $have, true ) ) {
				$fields[] = $f;
			}
		}
		return array( 'fields' => $fields, 'sections' => array_values( $sections ) );
	}

	public static function save_config( $raw ) {
		$clean = self::sanitize( $raw );
		update_option( self::OPTION, $clean, false );
		self::$config = $clean;
		return $clean;
	}

	/**
	 * @param string $key Field key.
	 * @return array|null
	 */
	private static function field( $key ) {
		foreach ( self::config()['fields'] as $f ) {
			if ( $f['key'] === $key ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Custom input fields (no text blocks).
	 */
	public static function custom_inputs() {
		return array_filter(
			self::config()['fields'],
			function ( $f ) {
				return $f['custom'] && $f['enabled'] && 'paragraph' !== $f['type'];
			}
		);
	}

	/* ---------- applying to WooCommerce ---------- */

	/**
	 * Width classes for an ordered list of fields: halves pair up first/last.
	 *
	 * @param array $rows Config rows in display order.
	 * @return array key => class
	 */
	private static function width_classes( $rows ) {
		$out  = array();
		$open = false;
		foreach ( $rows as $f ) {
			if ( ! $f['enabled'] ) {
				continue;
			}
			if ( 'half' === $f['width'] ) {
				$out[ $f['key'] ] = $open ? 'form-row-last' : 'form-row-first';
				$open             = ! $open;
			} else {
				$out[ $f['key'] ] = 'form-row-wide';
				$open             = false;
			}
		}
		return $out;
	}

	private static function set_class( $field, $class ) {
		$classes = array_diff( (array) ( isset( $field['class'] ) ? $field['class'] : array() ), array( 'form-row-wide', 'form-row-first', 'form-row-last' ) );
		array_unshift( $classes, $class );
		$field['class'] = array_values( $classes );
		return $field;
	}

	private static function apply_props( $field, $f ) {
		if ( '' !== $f['label'] ) {
			$field['label'] = $f['label'];
		}
		$field['placeholder'] = $f['placeholder'];
		if ( 'country' !== $f['key'] ) {
			$field['required'] = $f['required'];
		}
		return $field;
	}

	/**
	 * Address rows (and their positions) in the address section's order, phone excluded.
	 */
	private static function address_rows() {
		return array_values(
			array_filter(
				self::config()['fields'],
				function ( $f ) {
					return ! $f['custom'] && in_array( $f['key'], self::ADDRESS, true ) && 'phone' !== $f['key'];
				}
			)
		);
	}

	/**
	 * Locale defaults (address-i18n.js falls back to these when the country changes).
	 *
	 * @param array $fields Default address fields.
	 * @return array
	 */
	public function address_fields( $fields ) {
		if ( self::$raw ) {
			return $fields;
		}
		$rows    = self::address_rows();
		$classes = self::width_classes( $rows );
		foreach ( $rows as $i => $f ) {
			if ( ! isset( $fields[ $f['key'] ] ) ) {
				continue;
			}
			if ( ! $f['enabled'] ) {
				unset( $fields[ $f['key'] ] );
				continue;
			}
			$fields[ $f['key'] ]             = self::apply_props( $fields[ $f['key'] ], $f );
			$fields[ $f['key'] ]['priority'] = ( $i + 1 ) * 10;
			$fields[ $f['key'] ]             = self::set_class( $fields[ $f['key'] ], $classes[ $f['key'] ] );
		}
		// Newer WooCommerce lists phone here too, and its address script resets the phone's
		// required state from this default whenever the country loads.
		$phone = self::field( 'phone' );
		if ( $phone && isset( $fields['phone'] ) ) {
			if ( ! $phone['enabled'] ) {
				unset( $fields['phone'] );
			} else {
				$fields['phone'] = self::apply_props( $fields['phone'], $phone );
			}
		}
		return $fields;
	}

	/**
	 * Field wrapper IDs that must show as required on the checkout, for the script that
	 * restores the red asterisk after WooCommerce's address script runs.
	 *
	 * @return string[]
	 */
	public static function required_ids() {
		if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) {
			return array();
		}
		// Country-specific rules (state, postcode…) stay with WooCommerce's script.
		$locale = array( 'address_1', 'address_2', 'state', 'postcode', 'city', 'country' );
		$ids    = array();
		foreach ( WC()->checkout()->get_checkout_fields() as $group => $fields ) {
			foreach ( (array) $fields as $key => $field ) {
				$base = preg_replace( '/^(billing|shipping)_/', '', $key );
				if ( ! empty( $field['required'] ) && ! in_array( $base, $locale, true ) && ( ! isset( $field['type'] ) || 'cf_paragraph' !== $field['type'] ) ) {
					$ids[] = $key . '_field';
				}
			}
		}
		return $ids;
	}

	/**
	 * Country rules (e.g. "ZIP Code", state required) win over defaults in WooCommerce's
	 * script; drop them for fields whose label or required state was changed here.
	 *
	 * @param array $locales Country => field => props.
	 * @return array
	 */
	public function country_locale( $locales ) {
		if ( self::$raw || ! self::is_saved() ) {
			return $locales;
		}
		$orig = self::originals()['address'];
		$rows = self::address_rows();
		$ph   = self::field( 'phone' );
		if ( $ph ) {
			$rows[] = $ph;
		}
		foreach ( $rows as $f ) {
			$o       = isset( $orig[ $f['key'] ] ) ? $orig[ $f['key'] ] : array();
			$label   = '' !== $f['label'] && ( ! isset( $o['label'] ) || wp_strip_all_tags( $o['label'] ) !== $f['label'] );
			$req     = 'country' !== $f['key'] && ( ! empty( $o['required'] ) ) !== $f['required'];
			$ph      = ( isset( $o['placeholder'] ) ? $o['placeholder'] : '' ) !== $f['placeholder'];
			foreach ( $locales as $country => $rules ) {
				if ( ! isset( $rules[ $f['key'] ] ) || ! is_array( $rules[ $f['key'] ] ) ) {
					continue;
				}
				if ( $label ) {
					unset( $locales[ $country ][ $f['key'] ]['label'] );
				}
				if ( $req ) {
					unset( $locales[ $country ][ $f['key'] ]['required'] );
				}
				if ( $ph ) {
					unset( $locales[ $country ][ $f['key'] ]['placeholder'] );
				}
			}
		}
		return $locales;
	}

	/**
	 * @param array $fields Checkout fields by group.
	 * @return array
	 */
	public function checkout_fields( $fields ) {
		$cfg = self::config();

		// Email.
		$email = self::field( 'email' );
		if ( $email && isset( $fields['billing']['billing_email'] ) && '' !== $email['label'] ) {
			$fields['billing']['billing_email']['label']       = $email['label'];
			$fields['billing']['billing_email']['placeholder'] = $email['placeholder'];
		}

		// Address fields + phone, for billing and shipping.
		$rows    = self::address_rows();
		$classes = self::width_classes( $rows );
		$phone   = self::field( 'phone' );
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( empty( $fields[ $group ] ) ) {
				continue;
			}
			foreach ( $rows as $i => $f ) {
				$k = $group . '_' . $f['key'];
				if ( ! isset( $fields[ $group ][ $k ] ) ) {
					continue;
				}
				if ( ! $f['enabled'] ) {
					unset( $fields[ $group ][ $k ] );
					continue;
				}
				$fields[ $group ][ $k ]             = self::apply_props( $fields[ $group ][ $k ], $f );
				$fields[ $group ][ $k ]['priority'] = ( $i + 1 ) * 10;
				$fields[ $group ][ $k ]             = self::set_class( $fields[ $group ][ $k ], $classes[ $f['key'] ] );
			}
			$pk = $group . '_phone';
			if ( $phone && isset( $fields[ $group ][ $pk ] ) ) {
				if ( ! $phone['enabled'] ) {
					unset( $fields[ $group ][ $pk ] );
				} else {
					$fields[ $group ][ $pk ] = self::set_class( self::apply_props( $fields[ $group ][ $pk ], $phone ), 'form-row-wide' );
				}
			}
			uasort(
				$fields[ $group ],
				function ( $a, $b ) {
					return ( isset( $a['priority'] ) ? (int) $a['priority'] : 999 ) - ( isset( $b['priority'] ) ? (int) $b['priority'] : 999 );
				}
			);
		}

		// Order notes.
		$notes = self::field( 'order_comments' );
		if ( $notes && isset( $fields['order']['order_comments'] ) ) {
			if ( ! $notes['enabled'] ) {
				unset( $fields['order']['order_comments'] );
			} else {
				$fields['order']['order_comments'] = self::apply_props( $fields['order']['order_comments'], $notes );
			}
		}

		// Custom fields and text blocks go in the "order" group, tagged with their section.
		$by_section = array();
		foreach ( $cfg['fields'] as $f ) {
			if ( $f['custom'] && $f['enabled'] ) {
				$by_section[ $f['section'] ][] = $f;
			}
		}
		$n = 0;
		foreach ( $by_section as $section => $list ) {
			$classes = self::width_classes( $list );
			foreach ( $list as $f ) {
				$field = array(
					'type'        => 'paragraph' === $f['type'] ? 'cf_paragraph' : $f['type'],
					'label'       => $f['label'],
					'placeholder' => $f['placeholder'],
					'required'    => $f['required'],
					'class'       => array( $classes[ $f['key'] ], 'cf-custom-field' ),
					'priority'    => 200 + ( ++$n ),
					'cf_section'  => $section,
					'cf_content'  => $f['content'],
				);
				if ( in_array( $f['type'], array( 'select', 'radio' ), true ) ) {
					$opts = array_values( array_filter( array_map( 'trim', explode( "\n", $f['options'] ) ), 'strlen' ) );
					$field['options'] = array_combine( $opts, $opts );
					if ( 'select' === $f['type'] ) {
						$field['options'] = array( '' => $f['placeholder'] ? $f['placeholder'] : __( 'Choose an option', 'checkoutflow' ) ) + $field['options'];
					}
				}
				if ( 'email' === $f['type'] ) {
					$field['validate'] = array( 'email' );
				} elseif ( 'tel' === $f['type'] ) {
					$field['validate'] = array( 'phone' );
				} elseif ( 'date' === $f['type'] ) {
					$field['custom_attributes'] = array( 'max' => wp_date( 'Y-m-d' ) );
				}
				$fields['order'][ $f['key'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Order-group fields grouped by the section they render in.
	 *
	 * @param array $order_fields Fields from $checkout->get_checkout_fields( 'order' ).
	 * @return array section => key => field ('notes' holds order notes and anything untagged)
	 */
	public static function split( $order_fields ) {
		$out = array( 'notes' => array() );
		foreach ( (array) $order_fields as $key => $field ) {
			$section                 = isset( $field['cf_section'] ) ? $field['cf_section'] : 'notes';
			$out[ $section ][ $key ] = $field;
		}
		return $out;
	}

	public static function sections_at( $position ) {
		return array_filter(
			self::config()['sections'],
			function ( $s ) use ( $position ) {
				return $s['position'] === $position;
			}
		);
	}

	public function paragraph_html( $html, $key, $args, $value ) {
		$class = implode( ' ', array_map( 'sanitize_html_class', (array) $args['class'] ) );
		return '<div class="form-row cf-text-block ' . esc_attr( $class ) . '" id="' . esc_attr( $key ) . '_field">' . wp_kses_post( wpautop( isset( $args['cf_content'] ) ? $args['cf_content'] : '' ) ) . '</div>';
	}

	/* ---------- checkout submit ---------- */

	/**
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Errors.
	 */
	public function validate( $data, $errors ) {
		foreach ( self::custom_inputs() as $f ) {
			if ( 'date' !== $f['type'] || empty( $data[ $f['key'] ] ) ) {
				continue;
			}
			$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $data[ $f['key'] ], wp_timezone() );
			if ( ! $d ) {
				/* translators: %s: field label */
				$errors->add( $f['key'] . '_validation', sprintf( __( '%s is not a valid date.', 'checkoutflow' ), '<strong>' . esc_html( $f['label'] ) . '</strong>' ) );
				continue;
			}
			if ( $f['min_age'] > 0 ) {
				$age = $d->diff( new \DateTimeImmutable( 'today', wp_timezone() ) )->y;
				if ( $d > new \DateTimeImmutable( 'today', wp_timezone() ) || $age < $f['min_age'] ) {
					/* translators: %d: minimum age */
					$errors->add( $f['key'] . '_validation', sprintf( __( 'You must be at least %d years old to place an order.', 'checkoutflow' ), $f['min_age'] ) );
				}
			}
		}
	}

	/**
	 * Store answers on the order, with labels as they were at checkout.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Posted data.
	 */
	public function save( $order, $data ) {
		$saved = array();
		foreach ( self::custom_inputs() as $f ) {
			if ( ! isset( $data[ $f['key'] ] ) || '' === (string) $data[ $f['key'] ] ) {
				continue;
			}
			$value = 'checkbox' === $f['type'] ? __( 'Yes', 'checkoutflow' ) : (string) $data[ $f['key'] ];
			if ( 'date' === $f['type'] && ( $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$value = $d->format( 'Y-m-d' );
			}
			$order->update_meta_data( '_' . $f['key'], $value );
			$saved[] = array( 'key' => $f['key'], 'label' => $f['label'], 'value' => $value, 'type' => $f['type'] );
		}
		if ( $saved ) {
			$order->update_meta_data( self::META, $saved );
		}
	}

	/* ---------- showing answers ---------- */

	/**
	 * @param \WC_Order $order Order.
	 * @return array[] label, value
	 */
	public static function answers( $order ) {
		$rows = $order ? $order->get_meta( self::META ) : array();
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$value = (string) $r['value'];
			if ( isset( $r['type'] ) && 'date' === $r['type'] && strtotime( $value ) ) {
				$value = date_i18n( get_option( 'date_format' ), strtotime( $value ) );
			}
			$out[] = array( 'label' => (string) $r['label'], 'value' => $value );
		}
		return $out;
	}

	public function admin_display( $order ) {
		$rows = self::answers( $order );
		if ( ! $rows ) {
			return;
		}
		echo '<div class="cf-order-fields"><h3>' . esc_html__( 'Checkout fields', 'checkoutflow' ) . '</h3>';
		foreach ( $rows as $r ) {
			echo '<p><strong>' . esc_html( $r['label'] ) . ':</strong> ' . esc_html( $r['value'] ) . '</p>';
		}
		echo '</div>';
	}

	public function email_fields( $fields, $sent_to_admin, $order ) {
		foreach ( self::answers( $order ) as $i => $r ) {
			$fields[ 'cf_field_' . $i ] = array( 'label' => $r['label'], 'value' => $r['value'] );
		}
		return $fields;
	}

	public function customer_display( $order ) {
		$rows = self::answers( $order );
		if ( ! $rows ) {
			return;
		}
		echo '<ul class="cf-order-fields">';
		foreach ( $rows as $r ) {
			echo '<li><strong>' . esc_html( $r['label'] ) . ':</strong> ' . esc_html( $r['value'] ) . '</li>';
		}
		echo '</ul>';
	}
}
