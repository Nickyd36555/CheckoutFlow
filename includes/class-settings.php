<?php
/**
 * Settings schema, defaults and access.
 *
 * Every option lives in a single `checkoutflow_settings` array so a page load
 * costs one (autoloaded) option read.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'checkoutflow_settings';

	/** @var array|null */
	private static $cache = null;

	/** @var array|null */
	private static $schema = null;

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * On/off switches read while plugins load (before `init`), with their
	 * defaults. Reading these never builds the translated schema.
	 */
	const BOOT_FLAGS = array(
		'checkout_enabled' => true,
		'cart_enabled'     => true,
		'recovery_enabled' => true,
		'optin_enabled'    => true,
		'ty_enabled'       => true,
		'smtp_enabled'     => false,
		'smtp_all_mail'    => true,
		'checkout_style'   => 'modern',
	);

	/**
	 * @param string $key One of BOOT_FLAGS.
	 * @return bool
	 */
	public static function flag( $key ) {
		if ( null !== self::$cache ) {
			return (bool) self::$cache[ $key ];
		}
		$saved = get_option( self::OPTION, array() );
		return (bool) ( is_array( $saved ) && array_key_exists( $key, $saved ) ? $saved[ $key ] : self::BOOT_FLAGS[ $key ] );
	}

	/**
	 * Raw boot-time value (see BOOT_FLAGS) without building the translated schema.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function boot( $key ) {
		if ( null !== self::$cache ) {
			return self::$cache[ $key ];
		}
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) && array_key_exists( $key, $saved ) ? $saved[ $key ] : self::BOOT_FLAGS[ $key ];
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * One-time import of FunnelKit Cart's settings (they stay in the database after
	 * FunnelKit is deactivated), so the header cart appears where it was without setup.
	 */
	/**
	 * FunnelKit Cart's "Frequently Bought Together" settings (fkcart_settings).
	 */
	private static function import_funnelkit_upsells() {
		// Untouched old defaults become the new (FunnelKit-style) ones.
		$values = self::all();
		$olds   = array(
			'cart_heading'        => array( 'Your Cart', __( 'Review Your Cart', 'checkoutflow' ) ),
			'cart_upsell_heading' => array( 'You may also like', __( 'Frequently Bought Together', 'checkoutflow' ) ),
		);
		foreach ( $olds as $key => $pair ) {
			if ( isset( $values[ $key ] ) && $pair[0] === $values[ $key ] ) {
				$values[ $key ] = $pair[1];
			}
		}
		if ( isset( $values['cart_upsell_limit'] ) && 3 === (int) $values['cart_upsell_limit'] ) {
			$values['cart_upsell_limit'] = 5;
		}
		self::save( $values );

		$fk = get_option( 'fkcart_settings' );
		if ( ! is_array( $fk ) || ! $fk ) {
			return;
		}
		if ( isset( $fk['enable_upsells'] ) ) {
			$values['cart_upsells'] = wc_string_to_bool( $fk['enable_upsells'] );
		}
		if ( ! empty( $fk['upsell_heading'] ) ) {
			$values['cart_upsell_heading'] = sanitize_text_field( $fk['upsell_heading'] );
		}
		if ( ! empty( $fk['upsell_max_count'] ) ) {
			$values['cart_upsell_limit'] = max( 1, min( 10, (int) $fk['upsell_max_count'] ) );
		}
		$types = array( 'both' => 'both', 'upsell' => 'upsell', 'upsells' => 'upsell', 'crosssell' => 'crosssell', 'cross_sell' => 'crosssell', 'crosssells' => 'crosssell' );
		if ( ! empty( $fk['upsell_type'] ) && isset( $types[ $fk['upsell_type'] ] ) ) {
			$values['cart_upsell_type'] = $types[ $fk['upsell_type'] ];
		}
		if ( isset( $fk['show_default_upsell'] ) ) {
			$values['cart_upsell_always_defaults'] = wc_string_to_bool( $fk['show_default_upsell'] );
		}
		self::save( $values );
	}

	public static function import_funnelkit() {
		$done = (int) get_option( 'checkoutflow_fk_imported' );
		if ( $done >= 4 ) {
			return;
		}
		update_option( 'checkoutflow_fk_imported', 4, false );

		// Step 4: FunnelKit Cart upsell settings.
		self::import_funnelkit_upsells();
		if ( $done >= 3 ) {
			return;
		}

		// Step 3: FunnelKit checkout designs (Elementor) – banner, badges, notes, button.
		if ( $done < 3 ) {
			self::import_funnelkit_checkout_design();
		}
		if ( $done >= 2 ) {
			return;
		}

		// Step 2: FunnelKit checkouts keep the site header, so match that.
		if ( $done < 2 && ( get_option( 'fkcart_settings' ) || get_posts( array( 'post_type' => 'wfacp_checkout', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) ) ) ) {
			$values                      = self::all();
			$values['checkout_template'] = 'theme';
			self::save( $values );
		}
		if ( $done >= 1 ) {
			return;
		}

		$fk = get_option( 'fkcart_settings' );
		if ( ! is_array( $fk ) || ! $fk ) {
			return;
		}
		$values = self::all();

		$menus = array_filter( array_map( 'intval', (array) ( isset( $fk['cart_append_menu'] ) ? $fk['cart_append_menu'] : array() ) ) );
		// A menu beats a theme location: Elementor & other builder headers don't use theme locations.
		if ( $menus && 0 !== strpos( (string) $values['cart_menu_location'], 'menu:' ) ) {
			$values['cart_menu_location'] = 'menu:' . reset( $menus );
		}
		if ( isset( $fk['display_menu_total'] ) ) {
			$values['cart_menu_total'] = wc_string_to_bool( $fk['display_menu_total'] );
		}
		if ( ! empty( $fk['cart_menu_icon_size'] ) ) {
			$values['cart_menu_icon_size'] = max( 16, min( 60, (int) $fk['cart_menu_icon_size'] ) );
		}
		if ( isset( $fk['cart_icon_position'] ) && in_array( $fk['cart_icon_position'], array( 'bottom-left', 'bottom-right' ), true ) ) {
			$values['cart_icon_position'] = $fk['cart_icon_position'];
		}
		// Custom CSS commonly recolors the menu item: li.menu-item.fkcart-custom-menu-link { color: #979797 }.
		if ( ! empty( $fk['custom_css'] ) && preg_match( '/fkcart-custom-menu-link[^{]*\{[^}]*?(?<![-\w])color\s*:\s*(#[0-9a-fA-F]{3,6})/', (string) $fk['custom_css'], $m ) ) {
			$values['cart_menu_color'] = sanitize_hex_color( $m[1] );
		}
		self::save( $values );
	}

	/**
	 * Read the published FunnelKit checkout page's Elementor layout and carry over what
	 * CheckoutFlow can show: banner image, trust badges (icon + two headings), text
	 * blocks, place-order text and primary color. Only fills settings still at default.
	 */
	private static function import_funnelkit_checkout_design() {
		$ids = get_posts( array( 'post_type' => 'wfacp_checkout', 'post_status' => 'publish', 'numberposts' => 5, 'fields' => 'ids', 'orderby' => 'modified' ) );
		$values = self::all();
		$defaults = self::defaults();
		$changed = false;

		// Old default note under the email field isn't part of FunnelKit's design.
		if ( $ids && 'We save your cart so you can pick up where you left off.' === $values['recovery_consent_text'] ) {
			$values['recovery_consent_text'] = '';
			$changed                         = true;
		}

		foreach ( $ids as $id ) {
			$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$widgets = array();
			$walk    = static function ( $elements ) use ( &$walk, &$widgets ) {
				foreach ( (array) $elements as $el ) {
					if ( isset( $el['elType'] ) && 'widget' === $el['elType'] ) {
						$widgets[] = $el;
					}
					if ( ! empty( $el['elements'] ) ) {
						$walk( $el['elements'] );
					}
				}
			};
			$walk( $data );
			if ( ! $widgets ) {
				continue;
			}

			$banner = '';
			$badges = array();
			$texts  = array();
			$count  = count( $widgets );
			for ( $i = 0; $i < $count; $i++ ) {
				$w    = $widgets[ $i ];
				$type = isset( $w['widgetType'] ) ? $w['widgetType'] : '';
				$set  = isset( $w['settings'] ) ? $w['settings'] : array();

				if ( 'image' === $type && ! empty( $set['image']['url'] ) ) {
					$url = $set['image']['url'];
					if ( preg_match( '/\.svg(\?|$)/i', $url ) ) {
						// Badge: icon followed by a title heading and a text heading.
						$h = array();
						for ( $j = $i + 1; $j < $count && count( $h ) < 2 && isset( $widgets[ $j ]['widgetType'] ) && 'heading' === $widgets[ $j ]['widgetType']; $j++ ) {
							$h[] = trim( wp_strip_all_tags( (string) $widgets[ $j ]['settings']['title'] ) );
						}
						if ( $h ) {
							// Pick an icon from the icon file name + title first, then the description.
							$icon = '';
							foreach ( array( strtolower( $url . ' ' . $h[0] ), strtolower( isset( $h[1] ) ? $h[1] : '' ) ) as $hay ) {
								foreach ( array( 'route' => 'clipboard', 'protect' => 'clipboard', 'guarant' => 'award', 'gurant' => 'award', 'secure' => 'lock', 'ship' => 'truck', 'deliver' => 'truck', 'return' => 'refresh', 'support' => 'chat' ) as $needle => $name ) {
									if ( ! $icon && false !== strpos( $hay, $needle ) ) {
										$icon = $name;
									}
								}
							}
							$icon = $icon ? $icon : 'check';
							$badges[] = $h[0] . ' | ' . ( isset( $h[1] ) ? $h[1] : '' ) . ' | ' . $icon;
							$i        = $j - 1;
						}
					} elseif ( '' === $banner ) {
						$banner = esc_url_raw( $url );
					}
				} elseif ( 'text-editor' === $type && ! empty( $set['editor'] ) ) {
					$texts[] = wp_kses_post( $set['editor'] );
				}

				// FunnelKit form widget settings.
				if ( false !== strpos( $type, 'wfacp' ) || isset( $set['wfacp_payment_place_order_text'] ) ) {
					if ( ! empty( $set['wfacp_payment_place_order_text'] ) && '' === (string) $values['checkout_button_text'] ) {
						$values['checkout_button_text'] = sanitize_text_field( $set['wfacp_payment_place_order_text'] );
						$changed                        = true;
					}
					$color = ! empty( $set['default_primary_color'] ) ? sanitize_hex_color( $set['default_primary_color'] ) : '';
					foreach ( $set as $key => $val ) {
						if ( ! $color && is_string( $val ) && preg_match( '/(place_order|payment_button|btn).*(bg|background)/', $key ) && sanitize_hex_color( $val ) ) {
							$color = sanitize_hex_color( $val );
						}
					}
					if ( $color && $values['checkout_accent_color'] === $defaults['checkout_accent_color'] ) {
						$values['checkout_accent_color'] = $color;
						$changed                         = true;
					}
				}
			}

			if ( $banner && '' === (string) $values['checkout_banner'] ) {
				$values['checkout_banner'] = $banner;
				$changed                   = true;
			}
			if ( $badges && $values['checkout_badges'] === $defaults['checkout_badges'] ) {
				$values['checkout_badges'] = implode( "\n", $badges );
				$changed                   = true;
			}
			if ( $texts && '' === trim( (string) $values['checkout_summary_html'] ) ) {
				$values['checkout_summary_html'] = implode( "\n", $texts );
				$changed                         = true;
			}
			if ( '' === (string) $values['checkout_button_text'] ) {
				$values['checkout_button_text'] = 'Place Order Now'; // FunnelKit's default label.
				$changed                        = true;
			}
			break;
		}
		if ( $changed ) {
			self::save( $values );
		}
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * @return array
	 */
	public static function defaults() {
		$defaults = array();
		foreach ( self::schema() as $tab ) {
			foreach ( $tab['fields'] as $key => $field ) {
				if ( isset( $field['default'] ) ) {
					$defaults[ $key ] = $field['default'];
				}
			}
		}
		return $defaults;
	}

	/**
	 * Sanitize raw POSTed values against the schema.
	 *
	 * @param array  $input Raw input.
	 * @param string $tab   Tab being saved; fields from other tabs are kept as-is.
	 * @return array
	 */
	public static function sanitize( $input, $tab ) {
		$schema = self::schema();
		$values = self::all();
		if ( empty( $schema[ $tab ] ) ) {
			return $values;
		}

		foreach ( $schema[ $tab ]['fields'] as $key => $field ) {
			if ( 'heading' === $field['type'] || 'link' === $field['type'] ) {
				continue;
			}
			$raw = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$values[ $key ] = ! empty( $raw );
					break;
				case 'number':
					$num = is_numeric( $raw ) ? $raw + 0 : $field['default'];
					if ( isset( $field['min'] ) ) {
						$num = max( $field['min'], $num );
					}
					if ( isset( $field['max'] ) ) {
						$num = min( $field['max'], $num );
					}
					$values[ $key ] = $num;
					break;
				case 'color':
					$values[ $key ] = sanitize_hex_color( (string) $raw ) ? sanitize_hex_color( (string) $raw ) : $field['default'];
					break;
				case 'select':
					$values[ $key ] = isset( $field['options'][ $raw ] ) ? $raw : $field['default'];
					break;
				case 'url':
					$values[ $key ] = esc_url_raw( (string) $raw );
					break;
				case 'html':
					$values[ $key ] = wp_kses_post( (string) $raw );
					break;
				case 'password':
					if ( '' !== (string) $raw ) {
						$values[ $key ] = self::encrypt( (string) $raw );
					}
					break;
				case 'textarea':
					$values[ $key ] = sanitize_textarea_field( (string) $raw );
					break;
				case 'css':
					// Plain CSS only: no tags, and nothing that could close the <style> element.
					$values[ $key ] = str_replace( '</', '', wp_strip_all_tags( (string) $raw ) );
					break;
				default:
					$values[ $key ] = sanitize_text_field( (string) $raw );
			}
		}

		return $values;
	}

	/**
	 * Theme menu locations for the "Add cart to menu" setting.
	 *
	 * @return array
	 */
	private static function menu_locations() {
		$out = array( '' => __( 'Don\'t add', 'checkoutflow' ) );
		// Menus by name: works for theme headers and page-builder (Elementor, Divi…) menu widgets.
		foreach ( wp_get_nav_menus() as $menu ) {
			/* translators: %s: menu name */
			$out[ 'menu:' . $menu->term_id ] = sprintf( __( 'Menu: %s', 'checkoutflow' ), $menu->name );
		}
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			/* translators: %s: theme menu location */
			$out[ $slug ] = sprintf( __( 'Theme location: %s', 'checkoutflow' ), $label );
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$out['__block_navigation'] = __( 'Header navigation block (block themes)', 'checkoutflow' );
		}
		return $out;
	}

	/**
	 * Encrypt a secret with a key derived from the site's auth salts.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		$iv = random_bytes( 16 );
		return 'enc:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * @param string $stored Value produced by encrypt().
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'checkoutflow', true );
	}

	/**
	 * Save a sanitized settings array.
	 *
	 * @param array $values Values.
	 */
	public static function save( $values ) {
		update_option( self::OPTION, $values, true );
		self::flush();
	}

	/**
	 * The full settings schema, grouped by admin tab.
	 *
	 * @return array
	 */
	public static function schema() {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$field_states = array(
			'optional' => __( 'Optional', 'checkoutflow' ),
			'required' => __( 'Required', 'checkoutflow' ),
			'hidden'   => __( 'Hidden', 'checkoutflow' ),
		);

		$checkout = array(
			'checkout_enabled'       => array(
				'type'    => 'checkbox',
				'label'   => __( 'Enable checkout optimizations', 'checkoutflow' ),
				'default' => true,
			),
			'checkout_style'         => array(
				'type'    => 'select',
				'label'   => __( 'Checkout form style', 'checkoutflow' ),
				'options' => array(
					'modern'  => __( 'Modern (sectioned form, rich order summary)', 'checkoutflow' ),
					'classic' => __( 'Classic (WooCommerce form, two-column layout)', 'checkoutflow' ),
				),
				'default' => 'modern',
				'desc'    => __( 'Modern: Contact / Shipping Address / Shipping Method / Payment sections, product images and quantity controls in the summary.', 'checkoutflow' ),
			),
			'checkout_shipping_first' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Ask for the shipping address first', 'checkoutflow' ),
				'default' => true,
				'desc'    => __( 'Modern style: billing address is copied from shipping unless the customer ticks "Use a different billing address".', 'checkoutflow' ),
			),
			'checkout_template'      => array(
				'type'    => 'select',
				'label'   => __( 'Checkout layout', 'checkoutflow' ),
				'options' => array(
					'focused' => __( 'Focused (distraction-free: no theme header, footer or sidebar)', 'checkoutflow' ),
					'theme'   => __( 'Theme (keep your theme header and footer)', 'checkoutflow' ),
				),
				'default' => 'focused',
				'desc'    => __( 'Applies to the classic [woocommerce_checkout] checkout page.', 'checkoutflow' ),
			),
			'checkout_logo'          => array(
				'type'    => 'url',
				'label'   => __( 'Logo URL', 'checkoutflow' ),
				'default' => '',
				'desc'    => __( 'Used by the focused layout. Leave empty to use the site logo or site name.', 'checkoutflow' ),
			),
			'checkout_header_text'   => array(
				'type'    => 'text',
				'label'   => __( 'Header badge text', 'checkoutflow' ),
				'default' => __( 'Secure Checkout', 'checkoutflow' ),
			),
			'checkout_accent_color'  => array(
				'type'    => 'color',
				'label'   => __( 'Accent color', 'checkoutflow' ),
				'default' => '#1f6feb',
			),
			'checkout_banner'        => array(
				'type'    => 'url',
				'label'   => __( 'Banner image above the form', 'checkoutflow' ),
				'default' => '',
			),
			'checkout_button_total'  => array(
				'type'    => 'checkbox',
				'label'   => __( 'Show the order total on the place order button', 'checkoutflow' ),
				'default' => true,
			),
			'checkout_button_text'   => array(
				'type'    => 'text',
				'label'   => __( 'Place order button text', 'checkoutflow' ),
				'default' => '',
				'desc'    => __( 'Leave empty to keep the WooCommerce default.', 'checkoutflow' ),
			),
			'checkout_skip_cart'     => array(
				'type'    => 'checkbox',
				'label'   => __( 'Skip the cart page (send customers straight to checkout)', 'checkoutflow' ),
				'default' => false,
			),
			'checkout_coupon'        => array(
				'type'    => 'select',
				'label'   => __( 'Coupon field', 'checkoutflow' ),
				'options' => array(
					'inline'  => __( 'In the order summary (always visible)', 'checkoutflow' ),
					'summary' => __( 'In the order summary (collapsed link)', 'checkoutflow' ),
					'default' => __( 'WooCommerce default (above the form)', 'checkoutflow' ),
					'hidden'  => __( 'Hidden', 'checkoutflow' ),
				),
				'default' => 'inline',
			),
			'checkout_badges'        => array(
				'type'    => 'textarea',
				'label'   => __( 'Trust badges (order summary)', 'checkoutflow' ),
				'default' => __( "Satisfaction Guarantee | 100% Money Back Guarantee | shield\nFast Shipping | Your choice of shipping speed | truck\nSecure Checkout | Your information is protected | lock", 'checkoutflow' ),
				'rows'    => 4,
				'desc'    => __( 'One per line: Title | Text | icon. Icons: shield, truck, lock, box, star, refresh, chat, check. Leave empty to hide.', 'checkoutflow' ),
			),
			'checkout_summary_html'  => array(
				'type'    => 'html',
				'label'   => __( 'Text under the order summary', 'checkoutflow' ),
				'default' => '',
				'rows'    => 6,
				'desc'    => __( 'E.g. shipping policy or processing times. Basic HTML allowed.', 'checkoutflow' ),
			),
			'checkout_trust_text'    => array(
				'type'    => 'html',
				'label'   => __( 'Trust / guarantee text', 'checkoutflow' ),
				'default' => __( '<strong>30-day money-back guarantee.</strong> Your payment information is processed securely.', 'checkoutflow' ),
				'desc'    => __( 'Shown below the place order button. Basic HTML allowed. Leave empty to hide.', 'checkoutflow' ),
			),
			'fields_heading'         => array(
				'type'  => 'heading',
				'label' => __( 'Checkout fields', 'checkoutflow' ),
				'desc'  => __( 'Fewer fields means higher conversion. Hide what you do not need.', 'checkoutflow' ),
			),
			'field_billing_company'  => array(
				'type'    => 'select',
				'label'   => __( 'Company name', 'checkoutflow' ),
				'options' => $field_states,
				'default' => 'hidden',
			),
			'field_company_label'    => array(
				'type'    => 'text',
				'label'   => __( 'Company field label', 'checkoutflow' ),
				'default' => '',
				'desc'    => __( 'Rename the company field (e.g. "Lab Name"). Leave empty for the default.', 'checkoutflow' ),
			),
			'field_billing_address_2' => array(
				'type'    => 'select',
				'label'   => __( 'Address line 2', 'checkoutflow' ),
				'options' => $field_states,
				'default' => 'optional',
			),
			'field_billing_phone'    => array(
				'type'    => 'select',
				'label'   => __( 'Phone', 'checkoutflow' ),
				'options' => $field_states,
				'default' => 'optional',
			),
			'field_order_comments'   => array(
				'type'    => 'select',
				'label'   => __( 'Order notes', 'checkoutflow' ),
				'options' => array(
					'optional' => __( 'Shown', 'checkoutflow' ),
					'hidden'   => __( 'Hidden', 'checkoutflow' ),
				),
				'default' => 'hidden',
			),
			'checkout_email_first'   => array(
				'type'    => 'checkbox',
				'label'   => __( 'Move email to the top of the form', 'checkoutflow' ),
				'default' => true,
				'desc'    => __( 'Collecting the email first also lets cart recovery capture more carts.', 'checkoutflow' ),
			),
		);

		$cart = array(
			'cart_enabled'              => array(
				'type'    => 'checkbox',
				'label'   => __( 'Enable side cart', 'checkoutflow' ),
				'default' => true,
			),
			'cart_floating_icon'        => array(
				'type'    => 'checkbox',
				'label'   => __( 'Show floating cart button', 'checkoutflow' ),
				'default' => true,
				'desc'    => __( 'Any element with the CSS class "cf-open-cart" (e.g. a menu item) also opens the cart. Shortcode: [checkoutflow_cart_icon]', 'checkoutflow' ),
			),
			'cart_icon_position'        => array(
				'type'    => 'select',
				'label'   => __( 'Floating button position', 'checkoutflow' ),
				'options' => array(
					'bottom-right' => __( 'Bottom right', 'checkoutflow' ),
					'bottom-left'  => __( 'Bottom left', 'checkoutflow' ),
				),
				'default' => 'bottom-right',
			),
			'cart_hide_empty_icon'      => array(
				'type'    => 'checkbox',
				'label'   => __( 'Hide floating button when the cart is empty', 'checkoutflow' ),
				'default' => false,
			),
			'cart_menu_location'        => array(
				'type'    => 'select',
				'label'   => __( 'Add cart to menu', 'checkoutflow' ),
				'options' => self::menu_locations(),
				'default' => '',
				'desc'    => __( 'Pick the menu used in your header. Shows a cart icon with item count and total that opens the side cart. Not listed? Use the shortcode [checkoutflow_cart_icon].', 'checkoutflow' ),
			),
			'cart_menu_total'           => array(
				'type'    => 'checkbox',
				'label'   => __( 'Show cart total next to the menu icon', 'checkoutflow' ),
				'default' => true,
			),
			'cart_menu_color'           => array(
				'type'    => 'text',
				'label'   => __( 'Menu cart icon & total color', 'checkoutflow' ),
				'default' => '',
				'placeholder' => '#979797',
				'desc'    => __( 'Hex color, e.g. #979797. Leave empty to use your menu\'s text color.', 'checkoutflow' ),
			),
			'cart_menu_icon_size'       => array(
				'type'    => 'number',
				'label'   => __( 'Menu cart icon size (px)', 'checkoutflow' ),
				'default' => 28,
				'min'     => 16,
				'max'     => 60,
			),
			'cart_auto_open'            => array(
				'type'    => 'checkbox',
				'label'   => __( 'Open the cart when a product is added', 'checkoutflow' ),
				'default' => true,
			),
			'cart_ajax_single'          => array(
				'type'    => 'checkbox',
				'label'   => __( 'AJAX add to cart on product pages', 'checkoutflow' ),
				'default' => true,
				'desc'    => __( 'Add to cart without a page reload, then open the side cart.', 'checkoutflow' ),
			),
			'cart_heading'              => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'checkoutflow' ),
				'default' => __( 'Review Your Cart', 'checkoutflow' ),
			),
			'cart_show_coupon'          => array(
				'type'    => 'checkbox',
				'label'   => __( 'Show coupon field', 'checkoutflow' ),
				'default' => true,
			),
			'cart_checkout_text'        => array(
				'type'    => 'text',
				'label'   => __( 'Checkout button text', 'checkoutflow' ),
				'default' => __( 'Checkout', 'checkoutflow' ),
			),
			'cart_continue_text'        => array(
				'type'    => 'text',
				'label'   => __( 'Continue shopping text', 'checkoutflow' ),
				'default' => __( 'Continue shopping', 'checkoutflow' ),
			),
			'cart_continue_url'         => array(
				'type'    => 'url',
				'label'   => __( 'Continue shopping link', 'checkoutflow' ),
				'default' => '',
				'desc'    => __( 'Leave empty to use the first product category in the header cart menu (e.g. Peptides), or the shop page if there is none.', 'checkoutflow' ),
			),
			'cart_empty_text'           => array(
				'type'    => 'text',
				'label'   => __( 'Empty cart text', 'checkoutflow' ),
				'default' => __( 'Your cart is empty.', 'checkoutflow' ),
			),
			'cart_shipping_heading'     => array(
				'type'  => 'heading',
				'label' => __( 'Free shipping bar', 'checkoutflow' ),
			),
			'cart_free_shipping_bar'    => array(
				'type'    => 'checkbox',
				'label'   => __( 'Show free shipping progress bar', 'checkoutflow' ),
				'default' => true,
			),
			'cart_free_shipping_amount' => array(
				'type'    => 'number',
				'label'   => __( 'Free shipping threshold', 'checkoutflow' ),
				'default' => 0,
				'min'     => 0,
				'step'    => '0.01',
				'desc'    => __( 'Set to 0 to use the minimum amount from your "Free shipping" shipping method.', 'checkoutflow' ),
			),
			'cart_free_shipping_text'   => array(
				'type'    => 'text',
				'label'   => __( 'Progress text', 'checkoutflow' ),
				'default' => __( 'You are {amount} away from free shipping!', 'checkoutflow' ),
				'desc'    => __( '{amount} is replaced with the remaining amount.', 'checkoutflow' ),
			),
			'cart_free_shipping_done'   => array(
				'type'    => 'text',
				'label'   => __( 'Unlocked text', 'checkoutflow' ),
				'default' => __( 'You have unlocked free shipping!', 'checkoutflow' ),
			),
			'cart_upsell_heading_sec'   => array(
				'type'  => 'heading',
				'label' => __( 'Recommendations', 'checkoutflow' ),
			),
			'cart_upsells'              => array(
				'type'    => 'checkbox',
				'label'   => __( 'Show product recommendations', 'checkoutflow' ),
				'default' => true,
				'desc'    => __( 'Uses the Upsells / Cross-sells set on each product (Product data > Linked Products).', 'checkoutflow' ),
			),
			'cart_upsell_type'          => array(
				'type'    => 'select',
				'label'   => __( 'Recommend', 'checkoutflow' ),
				'options' => array(
					'both'      => __( 'Upsells and cross-sells', 'checkoutflow' ),
					'upsell'    => __( 'Upsells only', 'checkoutflow' ),
					'crosssell' => __( 'Cross-sells only', 'checkoutflow' ),
				),
				'default' => 'both',
			),
			'cart_upsell_defaults'      => array(
				'type'        => 'text',
				'label'       => __( 'Default products', 'checkoutflow' ),
				'default'     => '',
				'placeholder' => '123, 456',
				'desc'        => __( 'Product IDs (comma separated) shown when the cart has no linked products.', 'checkoutflow' ),
			),
			'cart_upsell_always_defaults' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Always show default products', 'checkoutflow' ),
				'default' => false,
			),
			'cart_upsell_heading'       => array(
				'type'    => 'text',
				'label'   => __( 'Recommendations heading', 'checkoutflow' ),
				'default' => __( 'Frequently Bought Together', 'checkoutflow' ),
			),
			'cart_upsell_limit'         => array(
				'type'    => 'number',
				'label'   => __( 'Max recommendations', 'checkoutflow' ),
				'default' => 5,
				'min'     => 1,
				'max'     => 10,
			),
			'cart_style_heading'        => array(
				'type'  => 'heading',
				'label' => __( 'Appearance & performance', 'checkoutflow' ),
			),
			'cart_accent_color'         => array(
				'type'    => 'color',
				'label'   => __( 'Accent color', 'checkoutflow' ),
				'default' => '#1f6feb',
			),
			'cart_width'                => array(
				'type'    => 'number',
				'label'   => __( 'Drawer width (px, desktop)', 'checkoutflow' ),
				'default' => 420,
				'min'     => 300,
				'max'     => 700,
			),
			'cart_disable_fragments'    => array(
				'type'    => 'checkbox',
				'label'   => __( 'Disable WooCommerce cart fragments', 'checkoutflow' ),
				'default' => false,
				'desc'    => __( 'Speeds up every page by removing the wc-cart-fragments request. Only enable if your theme\'s header cart count is not needed (the side cart keeps its own count).', 'checkoutflow' ),
			),
		);

		$recovery = array(
			'recovery_enabled'        => array(
				'type'    => 'checkbox',
				'label'   => __( 'Enable abandoned cart recovery', 'checkoutflow' ),
				'default' => true,
			),
			'recovery_abandon_after'  => array(
				'type'    => 'number',
				'label'   => __( 'Mark cart abandoned after (minutes)', 'checkoutflow' ),
				'default' => 15,
				'min'     => 5,
				'max'     => 1440,
			),
			'recovery_consent_text'   => array(
				'type'    => 'text',
				'label'   => __( 'Notice under email field', 'checkoutflow' ),
				'default' => '',
				'placeholder' => __( 'e.g. We save your cart so you can pick up where you left off.', 'checkoutflow' ),
				'desc'    => __( 'Leave empty to hide.', 'checkoutflow' ),
			),
			'recovery_retention_days' => array(
				'type'    => 'number',
				'label'   => __( 'Delete unrecovered carts after (days)', 'checkoutflow' ),
				'default' => 60,
				'min'     => 1,
				'max'     => 3650,
			),
		);

		$email = array(
			'sender_heading'  => array(
				'type'  => 'heading',
				'label' => __( 'Sender', 'checkoutflow' ),
			),
			'from_name'       => array(
				'type'    => 'text',
				'label'   => __( 'From name', 'checkoutflow' ),
				'default' => get_bloginfo( 'name' ),
			),
			'from_email'      => array(
				'type'    => 'text',
				'label'   => __( 'From email', 'checkoutflow' ),
				'default' => get_option( 'admin_email' ),
				'desc'    => __( 'Use an address on your own domain that your SMTP account is allowed to send from.', 'checkoutflow' ),
			),
			'reply_to'        => array(
				'type'    => 'text',
				'label'   => __( 'Reply-to email', 'checkoutflow' ),
				'default' => '',
			),
			'smtp_heading'    => array(
				'type'  => 'heading',
				'label' => __( 'SMTP', 'checkoutflow' ),
				'desc'  => __( 'Works with any SMTP provider: Amazon SES, Brevo, Mailgun, Postmark, SendGrid, Google Workspace, Microsoft 365, your host\'s mail server, etc.', 'checkoutflow' ),
			),
			'smtp_enabled'    => array(
				'type'    => 'checkbox',
				'label'   => __( 'Send email through SMTP', 'checkoutflow' ),
				'default' => false,
			),
			'smtp_host'       => array(
				'type'    => 'text',
				'label'   => __( 'SMTP host', 'checkoutflow' ),
				'default' => '',
				'placeholder' => 'smtp.example.com',
			),
			'smtp_port'       => array(
				'type'    => 'number',
				'label'   => __( 'SMTP port', 'checkoutflow' ),
				'default' => 587,
				'min'     => 1,
				'max'     => 65535,
			),
			'smtp_encryption' => array(
				'type'    => 'select',
				'label'   => __( 'Encryption', 'checkoutflow' ),
				'options' => array(
					'tls'  => __( 'TLS / STARTTLS (usually port 587)', 'checkoutflow' ),
					'ssl'  => __( 'SSL (usually port 465)', 'checkoutflow' ),
					'none' => __( 'None (not recommended)', 'checkoutflow' ),
				),
				'default' => 'tls',
			),
			'smtp_auth'       => array(
				'type'    => 'checkbox',
				'label'   => __( 'Use authentication', 'checkoutflow' ),
				'default' => true,
			),
			'smtp_username'   => array(
				'type'    => 'text',
				'label'   => __( 'Username', 'checkoutflow' ),
				'default' => '',
			),
			'smtp_password'   => array(
				'type'    => 'password',
				'label'   => __( 'Password', 'checkoutflow' ),
				'default' => '',
				'desc'    => __( 'Stored encrypted. Or define CHECKOUTFLOW_SMTP_PASSWORD in wp-config.php to keep it out of the database.', 'checkoutflow' ),
			),
			'smtp_all_mail'   => array(
				'type'    => 'checkbox',
				'label'   => __( 'Use SMTP for all WordPress & WooCommerce emails', 'checkoutflow' ),
				'default' => true,
				'desc'    => __( 'Recommended: order confirmations and password resets also get reliable delivery. Uncheck to use SMTP for CheckoutFlow emails only.', 'checkoutflow' ),
			),
			'sending_heading' => array(
				'type'  => 'heading',
				'label' => __( 'Sending', 'checkoutflow' ),
			),
			'email_rate'      => array(
				'type'    => 'number',
				'label'   => __( 'Max emails per minute', 'checkoutflow' ),
				'default' => 60,
				'min'     => 1,
				'max'     => 1000,
				'desc'    => __( 'Keep this within your SMTP provider\'s rate limit.', 'checkoutflow' ),
			),
			'email_tracking'  => array(
				'type'    => 'checkbox',
				'label'   => __( 'Track opens and clicks', 'checkoutflow' ),
				'default' => true,
			),
			'brand_heading'   => array(
				'type'  => 'heading',
				'label' => __( 'Default email design', 'checkoutflow' ),
				'desc'  => __( 'Starting point for new emails. Each email can override these in the builder.', 'checkoutflow' ),
			),
			'email_logo'      => array(
				'type'    => 'url',
				'label'   => __( 'Logo URL', 'checkoutflow' ),
				'default' => '',
			),
			'email_accent'    => array(
				'type'    => 'color',
				'label'   => __( 'Button / accent color', 'checkoutflow' ),
				'default' => '#1f6feb',
			),
			'email_bg'        => array(
				'type'    => 'color',
				'label'   => __( 'Background color', 'checkoutflow' ),
				'default' => '#f3f4f6',
			),
			'email_footer'    => array(
				'type'    => 'html',
				'label'   => __( 'Footer text', 'checkoutflow' ),
				'default' => '{site_name}<br>{store_address}',
				'desc'    => __( 'Anti-spam laws (CAN-SPAM, GDPR) require your physical address. An unsubscribe link is always added automatically.', 'checkoutflow' ),
			),
		);

		$marketing = array(
			'optin_enabled' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Show marketing opt-in checkbox at checkout', 'checkoutflow' ),
				'default' => true,
			),
			'optin_label'   => array(
				'type'    => 'text',
				'label'   => __( 'Checkbox label', 'checkoutflow' ),
				'default' => __( 'Email me with news and exclusive offers', 'checkoutflow' ),
			),
			'optin_checked' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Pre-check the box', 'checkoutflow' ),
				'default' => false,
				'desc'    => __( 'Pre-checked boxes are not valid consent under GDPR. Leave unchecked if you sell to the EU/UK.', 'checkoutflow' ),
			),
		);

		$thankyou = array(
			'ty_enabled' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Use the CheckoutFlow thank-you page', 'checkoutflow' ),
				'default' => true,
				'desc'    => __( 'Replaces the WooCommerce "Order received" page (and FunnelKit\'s thank-you page) with the one you design in the builder. Payment-gateway boxes and tracking scripts keep working.', 'checkoutflow' ),
			),
			'ty_builder' => array(
				'type'  => 'link',
				'label' => __( 'Page layout', 'checkoutflow' ),
				'text'  => __( 'Open the thank-you page builder', 'checkoutflow' ),
				'url'   => admin_url( 'admin.php?page=checkoutflow-thankyou' ),
			),
		);

		$fonts = array(
			'inherit'                                                => __( 'Same as my theme', 'checkoutflow' ),
			'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif' => __( 'System (clean sans-serif)', 'checkoutflow' ),
			'Helvetica, Arial, sans-serif'                           => 'Helvetica / Arial',
			'Georgia, "Times New Roman", serif'                      => 'Georgia (serif)',
			'"Courier New", Courier, monospace'                      => 'Courier (typewriter)',
		);

		$design = array(
			'design_intro'        => array(
				'type'  => 'heading',
				'label' => __( 'Look & feel', 'checkoutflow' ),
				'desc'  => __( 'Applies to the checkout, the thank-you page and the side cart. Button colors are set on the Checkout and Side Cart tabs.', 'checkoutflow' ),
			),
			'design_font'         => array(
				'type'    => 'select',
				'label'   => __( 'Font', 'checkoutflow' ),
				'options' => $fonts,
				'default' => 'inherit',
			),
			'design_font_size'    => array(
				'type'    => 'number',
				'label'   => __( 'Text size (px)', 'checkoutflow' ),
				'default' => 15,
				'min'     => 12,
				'max'     => 20,
			),
			'design_text_color'   => array(
				'type'    => 'color',
				'label'   => __( 'Text color', 'checkoutflow' ),
				'default' => '#111111',
			),
			'design_heading_color' => array(
				'type'    => 'color',
				'label'   => __( 'Heading color', 'checkoutflow' ),
				'default' => '#111111',
			),
			'design_label_color'  => array(
				'type'    => 'color',
				'label'   => __( 'Field label color', 'checkoutflow' ),
				'default' => '#4a4f57',
			),
			'design_border_color' => array(
				'type'    => 'color',
				'label'   => __( 'Field border color', 'checkoutflow' ),
				'default' => '#9aa0a6',
			),
			'design_summary_bg'   => array(
				'type'    => 'color',
				'label'   => __( 'Order summary background', 'checkoutflow' ),
				'default' => '#f7f7f7',
			),
			'design_radius'       => array(
				'type'    => 'number',
				'label'   => __( 'Corner rounding (px)', 'checkoutflow' ),
				'default' => 4,
				'min'     => 0,
				'max'     => 20,
			),
			'design_css_sec'      => array(
				'type'  => 'heading',
				'label' => __( 'Custom CSS', 'checkoutflow' ),
			),
			'design_custom_css'   => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'checkoutflow' ),
				'default' => '',
				'rows'    => 10,
				'desc'    => __( 'Loaded on the checkout, thank-you page and side cart only. Page body classes: .cf-checkout, .cf-thankyou-page; side cart: .cf-cart.', 'checkoutflow' ),
			),
		);

		self::$schema = array(
			'checkout' => array(
				'label'  => __( 'Checkout', 'checkoutflow' ),
				'fields' => $checkout,
			),
			'thankyou' => array(
				'label'  => __( 'Thank You Page', 'checkoutflow' ),
				'fields' => $thankyou,
			),
			'cart'     => array(
				'label'  => __( 'Side Cart', 'checkoutflow' ),
				'fields' => $cart,
			),
			'design'   => array(
				'label'  => __( 'Design', 'checkoutflow' ),
				'fields' => $design,
			),
			'recovery' => array(
				'label'  => __( 'Cart Recovery', 'checkoutflow' ),
				'fields' => $recovery,
			),
			'marketing' => array(
				'label'  => __( 'Marketing', 'checkoutflow' ),
				'fields' => $marketing,
			),
			'email'    => array(
				'label'  => __( 'Email & SMTP', 'checkoutflow' ),
				'fields' => $email,
			),
		);

		return self::$schema;
	}
}
