<?php
/**
 * Email builder renderer: design JSON (settings + blocks) -> email-client-safe HTML.
 *
 * Design format:
 * {
 *   "settings": { "bg", "content_bg", "accent", "text_color", "font", "logo", "width" },
 *   "blocks":   [ { "type": "heading", ...props }, ... ]
 * }
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Mail;

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

class Renderer {

	/**
	 * Block types available in the builder, with their default props.
	 *
	 * @return array
	 */
	public static function block_types() {
		return array(
			'heading'     => array(
				'label' => __( 'Heading', 'checkoutflow' ),
				'props' => array( 'text' => __( 'Your heading', 'checkoutflow' ), 'size' => 26, 'align' => 'left' ),
			),
			'text'        => array(
				'label' => __( 'Text', 'checkoutflow' ),
				'props' => array( 'html' => '<p>' . __( 'Write something great.', 'checkoutflow' ) . '</p>', 'align' => 'left' ),
			),
			'button'      => array(
				'label' => __( 'Button', 'checkoutflow' ),
				'props' => array( 'text' => __( 'Shop now', 'checkoutflow' ), 'url' => '{shop_url}', 'align' => 'center', 'color' => '' ),
			),
			'image'       => array(
				'label' => __( 'Image', 'checkoutflow' ),
				'props' => array( 'src' => '', 'alt' => '', 'url' => '', 'width' => 100, 'align' => 'center' ),
			),
			'products'    => array(
				'label' => __( 'Products', 'checkoutflow' ),
				'props' => array( 'ids' => '', 'columns' => 2, 'button_text' => __( 'Buy now', 'checkoutflow' ) ),
			),
			'cart_items'  => array(
				'label' => __( 'Cart items (abandoned cart)', 'checkoutflow' ),
				'props' => array(),
			),
			'order_items' => array(
				'label' => __( 'Order items', 'checkoutflow' ),
				'props' => array(),
			),
			'coupon'      => array(
				'label' => __( 'Coupon', 'checkoutflow' ),
				'props' => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'days'          => 7,
					'free_shipping' => false,
					'text'          => __( 'Use this code at checkout:', 'checkoutflow' ),
				),
			),
			'divider'     => array(
				'label' => __( 'Divider', 'checkoutflow' ),
				'props' => array( 'color' => '#e5e7eb' ),
			),
			'spacer'      => array(
				'label' => __( 'Spacer', 'checkoutflow' ),
				'props' => array( 'height' => 24 ),
			),
			'html'        => array(
				'label' => __( 'Custom HTML', 'checkoutflow' ),
				'props' => array( 'html' => '' ),
			),
		);
	}

	/**
	 * @return array Global design settings with defaults from the Email settings tab.
	 */
	public static function default_settings() {
		return array(
			'bg'         => Settings::get( 'email_bg' ),
			'content_bg' => '#ffffff',
			'accent'     => Settings::get( 'email_accent' ),
			'text_color' => '#1f2328',
			'font'       => 'Helvetica, Arial, sans-serif',
			'logo'       => Settings::get( 'email_logo' ),
			'width'      => 600,
		);
	}

	/**
	 * Build a design from a list of [type, props] pairs.
	 *
	 * @param array $blocks List of array( type, props ).
	 * @return array
	 */
	public static function design( $blocks ) {
		$types = self::block_types();
		$out   = array();
		foreach ( $blocks as $b ) {
			$out[] = array_merge( array( 'type' => $b[0] ), $types[ $b[0] ]['props'], isset( $b[1] ) ? $b[1] : array() );
		}
		return array(
			'settings' => self::default_settings(),
			'blocks'   => $out,
		);
	}

	/**
	 * Sanitize a design coming from the builder.
	 *
	 * @param mixed $design Decoded JSON.
	 * @return array
	 */
	public static function sanitize( $design, $trusted = false ) {
		$design   = is_array( $design ) ? $design : array();
		$settings = array_merge( self::default_settings(), isset( $design['settings'] ) && is_array( $design['settings'] ) ? $design['settings'] : array() );

		$clean_settings = array(
			'bg'         => sanitize_hex_color( $settings['bg'] ) ? sanitize_hex_color( $settings['bg'] ) : '#f3f4f6',
			'content_bg' => sanitize_hex_color( $settings['content_bg'] ) ? sanitize_hex_color( $settings['content_bg'] ) : '#ffffff',
			'accent'     => sanitize_hex_color( $settings['accent'] ) ? sanitize_hex_color( $settings['accent'] ) : '#1f6feb',
			'text_color' => sanitize_hex_color( $settings['text_color'] ) ? sanitize_hex_color( $settings['text_color'] ) : '#1f2328',
			'font'       => preg_replace( '/[^a-zA-Z0-9 ,\-\'"]/', '', (string) $settings['font'] ),
			'logo'       => esc_url_raw( (string) $settings['logo'] ),
			'width'      => max( 480, min( 800, (int) $settings['width'] ) ),
		);

		$types  = self::block_types();
		$blocks = array();
		foreach ( ( isset( $design['blocks'] ) && is_array( $design['blocks'] ) ? $design['blocks'] : array() ) as $block ) {
			$type = isset( $block['type'] ) ? $block['type'] : '';
			if ( ! isset( $types[ $type ] ) ) {
				continue;
			}
			$b = array( 'type' => $type );
			foreach ( $types[ $type ]['props'] as $key => $default ) {
				$v = isset( $block[ $key ] ) ? $block[ $key ] : $default;
				switch ( $key ) {
					case 'html':
						$b[ $key ] = ( 'html' === $type && ( $trusted || current_user_can( 'unfiltered_html' ) ) ) ? (string) $v : wp_kses( (string) $v, self::allowed_html() );
						break;
					case 'url':
					case 'src':
						// Keep merge tags like {recovery_url}; esc_url is applied after merging.
						$b[ $key ] = preg_match( '/^\{[a-z_]+\}$/', (string) $v ) ? (string) $v : esc_url_raw( (string) $v );
						break;
					case 'color':
						$b[ $key ] = sanitize_hex_color( (string) $v ) ? sanitize_hex_color( (string) $v ) : '';
						break;
					case 'align':
						$b[ $key ] = in_array( $v, array( 'left', 'center', 'right' ), true ) ? $v : $default;
						break;
					case 'discount_type':
						$b[ $key ] = in_array( $v, array( 'percent', 'fixed_cart' ), true ) ? $v : 'percent';
						break;
					case 'free_shipping':
						$b[ $key ] = ! empty( $v ) && 'false' !== $v;
						break;
					case 'ids':
						$b[ $key ] = implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $v ) ) ) );
						break;
					default:
						$b[ $key ] = is_numeric( $default ) ? ( is_numeric( $v ) ? $v + 0 : $default ) : sanitize_text_field( (string) $v );
				}
			}
			$blocks[] = $b;
		}

		return array(
			'settings' => $clean_settings,
			'blocks'   => $blocks,
		);
	}

	public static function allowed_html() {
		return array(
			'p'      => array( 'style' => true ),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			's'      => array(),
			'a'      => array( 'href' => true, 'style' => true, 'target' => true ),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'span'   => array( 'style' => true ),
			'div'    => array( 'style' => true ),
			'h1'     => array(),
			'h2'     => array(),
			'h3'     => array(),
		);
	}

	/**
	 * Does the design need a coupon generated at send time?
	 *
	 * @param array $design Design.
	 * @return array|null Coupon block props, or null.
	 */
	public static function coupon_block( $design ) {
		foreach ( isset( $design['blocks'] ) ? $design['blocks'] : array() as $b ) {
			if ( 'coupon' === $b['type'] ) {
				return $b;
			}
		}
		return null;
	}

	/**
	 * Render a full email document.
	 *
	 * @param array $design  Design.
	 * @param array $ctx     Context: contact, cart, order, coupon, preheader, unsubscribe_url, preview.
	 * @param bool  $trusted Design comes from storage (already sanitized with the editor's capabilities on save).
	 * @return string
	 */
	public static function render( $design, $ctx = array(), $trusted = false ) {
		$design = self::sanitize( $design, $trusted );
		$s      = $design['settings'];
		$tags   = Merge_Tags::values( array_merge( $ctx, array( 'accent' => $s['accent'] ) ) );

		$body = '';
		foreach ( $design['blocks'] as $i => $block ) {
			$html = self::block( $block, $s, $tags, $ctx );
			// Builder canvas: tag each block's row so the preview can be clicked, dragged and dropped on.
			if ( ! empty( $ctx['canvas'] ) ) {
				if ( '' === $html ) {
					$types = self::block_types();
					$html  = '<tr><td style="padding:8px 32px;text-align:center;color:#9ca3af;font-size:13px;"><div style="border:2px dashed #d1d5db;padding:16px;">' . esc_html( $types[ $block['type'] ]['label'] ) . '</div></td></tr>';
				}
				$html = preg_replace( '/^\s*<tr\b/', '<tr data-cfb="' . (int) $i . '"', $html, 1 );
			}
			$body .= $html;
		}

		$preheader = isset( $ctx['preheader'] ) ? Merge_Tags::apply( $ctx['preheader'], $tags, 'text' ) : '';
		$footer    = Merge_Tags::apply( wp_kses_post( Settings::get( 'email_footer' ) ), $tags, 'html' );
		$unsub     = isset( $ctx['unsubscribe_url'] ) ? $ctx['unsubscribe_url'] : '#';

		ob_start();
		include CHECKOUTFLOW_DIR . 'templates/email/layout.php';
		return ob_get_clean();
	}

	/**
	 * @param array $b    Block.
	 * @param array $s    Design settings.
	 * @param array $tags Merge tag values.
	 * @param array $ctx  Context.
	 * @return string
	 */
	private static function block( $b, $s, $tags, $ctx ) {
		$pad   = 'padding:8px 32px;';
		$align = isset( $b['align'] ) ? $b['align'] : 'left';

		switch ( $b['type'] ) {
			case 'heading':
				return sprintf(
					'<tr><td style="%1$stext-align:%2$s;"><h1 style="margin:8px 0;font-size:%3$dpx;line-height:1.25;font-weight:700;color:%4$s;">%5$s</h1></td></tr>',
					$pad,
					esc_attr( $align ),
					max( 14, min( 48, (int) $b['size'] ) ),
					esc_attr( $s['text_color'] ),
					Merge_Tags::apply( esc_html( $b['text'] ), $tags, 'html' )
				);

			case 'text':
				$html = Merge_Tags::apply( $b['html'], $tags, 'html' );
				$html = preg_replace( '#<p(\s[^>]*)?>#i', '<p style="margin:0 0 14px;">', $html );
				return sprintf( '<tr><td class="cf-text" style="%1$stext-align:%2$s;font-size:16px;line-height:1.6;color:%3$s;">%4$s</td></tr>', $pad, esc_attr( $align ), esc_attr( $s['text_color'] ), $html );

			case 'button':
				$url   = Merge_Tags::apply( $b['url'], $tags, 'url' );
				$color = $b['color'] ? $b['color'] : $s['accent'];
				return sprintf( '<tr><td style="padding:16px 32px;text-align:%1$s;">%2$s</td></tr>', esc_attr( $align ), self::button( $url, Merge_Tags::apply( esc_html( $b['text'] ), $tags, 'html' ), $color ) );

			case 'image':
				if ( ! $b['src'] ) {
					return ! empty( $ctx['preview'] ) ? '<tr><td style="' . $pad . 'text-align:center;color:#9ca3af;font-size:13px;"><div style="border:2px dashed #d1d5db;padding:32px;">' . esc_html__( 'Image – set a URL in the block settings', 'checkoutflow' ) . '</div></td></tr>' : '';
				}
				$width = max( 10, min( 100, (int) $b['width'] ) );
				$px    = (int) round( ( $s['width'] - 64 ) * $width / 100 );
				$img   = sprintf( '<img src="%1$s" alt="%2$s" width="%3$d" style="display:inline-block;width:100%%;max-width:%3$dpx;height:auto;border:0;">', esc_url( $b['src'] ), esc_attr( $b['alt'] ), $px );
				if ( $b['url'] ) {
					$img = '<a href="' . esc_url( Merge_Tags::apply( $b['url'], $tags, 'url' ) ) . '">' . $img . '</a>';
				}
				return sprintf( '<tr><td style="%1$stext-align:%2$s;">%3$s</td></tr>', $pad, esc_attr( $align ), $img );

			case 'products':
				return self::products( $b, $s );

			case 'cart_items':
				return '<tr><td style="' . $pad . '">' . $tags['cart_items'] . '</td></tr>';

			case 'order_items':
				return '<tr><td style="' . $pad . '">' . $tags['order_items'] . '</td></tr>';

			case 'coupon':
				if ( empty( $ctx['coupon']['code'] ) ) {
					return '';
				}
				return sprintf(
					'<tr><td style="%1$stext-align:center;"><p style="margin:0 0 8px;font-size:15px;color:%2$s;">%3$s</p><div style="display:inline-block;border:2px dashed %4$s;border-radius:6px;padding:12px 28px;font-size:22px;font-weight:700;letter-spacing:2px;color:%4$s;font-family:Menlo,Consolas,monospace;">%5$s</div><p style="margin:8px 0 0;font-size:13px;color:#6b7280;">%6$s</p></td></tr>',
					$pad,
					esc_attr( $s['text_color'] ),
					esc_html( $b['text'] ),
					esc_attr( $s['accent'] ),
					esc_html( strtoupper( $ctx['coupon']['code'] ) ),
					/* translators: 1: discount amount, 2: expiry date */
					esc_html( sprintf( __( '%1$s off · expires %2$s', 'checkoutflow' ), $ctx['coupon']['amount'], $ctx['coupon']['expiry'] ) )
				);

			case 'divider':
				return sprintf( '<tr><td style="padding:12px 32px;"><div style="border-top:1px solid %s;font-size:0;line-height:0;">&nbsp;</div></td></tr>', esc_attr( $b['color'] ? $b['color'] : '#e5e7eb' ) );

			case 'spacer':
				$h = max( 4, min( 120, (int) $b['height'] ) );
				return sprintf( '<tr><td style="height:%1$dpx;font-size:0;line-height:0;">&nbsp;</td></tr>', $h );

			case 'html':
				return '<tr><td style="' . $pad . '">' . Merge_Tags::apply( $b['html'], $tags, 'html' ) . '</td></tr>';
		}
		return '';
	}

	/**
	 * Bulletproof button (table-based so Outlook renders it).
	 */
	public static function button( $url, $label_html, $color ) {
		return sprintf(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;"><tr><td style="border-radius:6px;background:%1$s;"><a href="%2$s" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;">%3$s</a></td></tr></table>',
			esc_attr( $color ),
			esc_url( $url ),
			$label_html
		);
	}

	private static function products( $b, $s ) {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $b['ids'] ) ) );
		if ( ! $ids ) {
			$ids = wc_get_products( array( 'limit' => (int) $b['columns'], 'status' => 'publish', 'orderby' => 'popularity', 'return' => 'ids' ) );
		}
		$cols  = max( 1, min( 3, (int) $b['columns'] ) );
		$width = (int) floor( 100 / $cols );
		$cells = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! $p->is_visible() ) {
				continue;
			}
			$img_id  = $p->get_image_id();
			$img     = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
			$cells[] = sprintf(
				'<td valign="top" width="%1$d%%" style="padding:8px;text-align:center;"><a href="%2$s"><img src="%3$s" alt="%4$s" width="160" style="width:100%%;max-width:220px;height:auto;border:0;border-radius:6px;"></a><div style="margin:8px 0 4px;font-size:15px;font-weight:700;color:%5$s;">%4$s</div><div style="margin:0 0 10px;font-size:14px;color:#6b7280;">%6$s</div>%7$s</td>',
				$width,
				esc_url( $p->get_permalink() ),
				esc_url( $img ),
				esc_html( $p->get_name() ),
				esc_attr( $s['text_color'] ),
				wp_strip_all_tags( wc_price( wc_get_price_to_display( $p ) ) ),
				$b['button_text'] ? self::button( $p->get_permalink(), esc_html( $b['button_text'] ), $s['accent'] ) : ''
			);
		}
		if ( ! $cells ) {
			return '';
		}
		$rows = '';
		foreach ( array_chunk( $cells, $cols ) as $chunk ) {
			$rows .= '<tr>' . implode( '', $chunk ) . str_repeat( '<td width="' . $width . '%"></td>', $cols - count( $chunk ) ) . '</tr>';
		}
		return '<tr><td style="padding:8px 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></td></tr>';
	}

	/**
	 * Items table used by {cart_items} and {order_items}.
	 *
	 * @param array $items List of [ name, qty, total (formatted), image ].
	 * @return string
	 */
	public static function items_table( $items ) {
		if ( ! $items ) {
			return '';
		}
		$rows = '';
		foreach ( $items as $item ) {
			$rows .= sprintf(
				'<tr><td width="64" style="padding:10px 12px 10px 0;border-bottom:1px solid #e5e7eb;"><img src="%1$s" alt="" width="56" height="56" style="display:block;width:56px;height:56px;object-fit:cover;border-radius:6px;border:0;"></td><td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-size:15px;">%2$s<br><span style="color:#6b7280;font-size:13px;">&times; %3$s</span></td><td align="right" style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-size:15px;font-weight:700;white-space:nowrap;">%4$s</td></tr>',
				esc_url( $item['image'] ),
				esc_html( $item['name'] ),
				esc_html( $item['qty'] ),
				esc_html( $item['total'] )
			);
		}
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . $rows . '</table>';
	}
}
