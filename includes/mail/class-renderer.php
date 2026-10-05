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
				'group' => 'general',
				'props' => array( 'text' => __( 'Your heading', 'checkoutflow' ), 'size' => 26, 'align' => 'left', 'color' => '' ),
			),
			'text'        => array(
				'label' => __( 'Text', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'html' => '<p>' . __( 'Write something great.', 'checkoutflow' ) . '</p>', 'align' => 'left' ),
			),
			'logo'        => array(
				'label' => __( 'Site logo', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'src' => '', 'width' => 180, 'align' => 'center', 'url' => '{site_url}' ),
			),
			'list'        => array(
				'label'   => __( 'List', 'checkoutflow' ),
				'group'   => 'general',
				'props'   => array( 'items' => __( "First point\nSecond point\nThird point", 'checkoutflow' ), 'style' => 'bullet', 'align' => 'left' ),
				'options' => array(
					'style' => array(
						'bullet' => __( 'Bullets', 'checkoutflow' ),
						'number' => __( 'Numbers', 'checkoutflow' ),
						'check'  => __( 'Check marks', 'checkoutflow' ),
					),
				),
			),
			'button'      => array(
				'label' => __( 'Button', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'text' => __( 'Shop now', 'checkoutflow' ), 'url' => '{shop_url}', 'align' => 'center', 'color' => '' ),
			),
			'image'       => array(
				'label' => __( 'Image', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'src' => '', 'alt' => '', 'url' => '', 'width' => 100, 'align' => 'center' ),
			),
			'divider'     => array(
				'label' => __( 'Divider', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'color' => '#e5e7eb' ),
			),
			'spacer'      => array(
				'label' => __( 'Spacer', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'height' => 24 ),
			),
			'menu'        => array(
				'label' => __( 'Menu', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'links' => __( "Shop|{shop_url}\nHome|{site_url}", 'checkoutflow' ), 'align' => 'center', 'color' => '' ),
			),
			'social'      => array(
				'label' => __( 'Social', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'facebook' => '', 'instagram' => '', 'x' => '', 'youtube' => '', 'tiktok' => '', 'linkedin' => '', 'align' => 'center' ),
			),
			'html'        => array(
				'label' => __( 'Custom HTML', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'html' => '' ),
			),
			'footer'      => array(
				'label' => __( 'Footer', 'checkoutflow' ),
				'group' => 'general',
				'props' => array( 'html' => '{site_name}<br>{store_address}', 'align' => 'center' ),
			),
			'products'    => array(
				'label' => __( 'Products', 'checkoutflow' ),
				'group' => 'woo',
				'props' => array( 'ids' => '', 'columns' => 2, 'button_text' => __( 'Buy now', 'checkoutflow' ) ),
			),
			'coupon'      => array(
				'label' => __( 'Coupon', 'checkoutflow' ),
				'group' => 'woo',
				'props' => array(
					'discount_type' => 'percent',
					'amount'        => 10,
					'days'          => 7,
					'free_shipping' => false,
					'text'          => __( 'Use this code at checkout:', 'checkoutflow' ),
				),
			),
			'cart_items'  => array(
				'label'   => __( 'Cart items (abandoned cart)', 'checkoutflow' ),
				'group'   => 'woo',
				'dynamic' => true,
				'props'   => array(),
			),
			'order_items' => array(
				'label'   => __( 'Order items', 'checkoutflow' ),
				'group'   => 'woo',
				'dynamic' => true,
				'props'   => array(),
			),
			'columns'     => array(
				'label' => __( 'Columns', 'checkoutflow' ),
				'group' => 'structure',
				'props' => array( 'layout' => '50-50', 'cols' => array() ),
			),
		);
	}

	/**
	 * Column layouts (percent widths).
	 *
	 * @return array layout => widths
	 */
	public static function layouts() {
		return array(
			'100'         => array( 100 ),
			'50-50'       => array( 50, 50 ),
			'33-67'       => array( 33.33, 66.67 ),
			'67-33'       => array( 66.67, 33.33 ),
			'33-33-33'    => array( 33.33, 33.33, 33.34 ),
			'25-25-25-25' => array( 25, 25, 25, 25 ),
		);
	}

	/**
	 * Per-block style shared by every block: background and vertical padding.
	 *
	 * @param array $raw Raw block.
	 * @return array
	 */
	public static function sanitize_style( $raw ) {
		$out = array();
		if ( ! empty( $raw['_bg'] ) && sanitize_hex_color( (string) $raw['_bg'] ) ) {
			$out['_bg'] = sanitize_hex_color( (string) $raw['_bg'] );
		}
		foreach ( array( '_pt', '_pb' ) as $k ) {
			if ( isset( $raw[ $k ] ) && '' !== (string) $raw[ $k ] ) {
				$out[ $k ] = max( 0, min( 120, (int) $raw[ $k ] ) );
			}
		}
		return $out;
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

		return array(
			'settings' => $clean_settings,
			'blocks'   => self::sanitize_blocks( isset( $design['blocks'] ) && is_array( $design['blocks'] ) ? $design['blocks'] : array(), $trusted, true ),
		);
	}

	/**
	 * @param array $list        Raw blocks.
	 * @param bool  $trusted     Stored by a user who could post unfiltered HTML.
	 * @param bool  $allow_cols  Columns allowed here (not inside another column).
	 * @return array
	 */
	private static function sanitize_blocks( $list, $trusted, $allow_cols ) {
		$types  = self::block_types();
		$blocks = array();
		foreach ( $list as $block ) {
			$type = is_array( $block ) && isset( $block['type'] ) ? $block['type'] : '';
			if ( ! isset( $types[ $type ] ) || ( 'columns' === $type && ! $allow_cols ) ) {
				continue;
			}
			if ( 'columns' === $type ) {
				$layouts = self::layouts();
				$layout  = isset( $block['layout'], $layouts[ $block['layout'] ] ) ? $block['layout'] : '50-50';
				$cols    = array();
				foreach ( array_keys( $layouts[ $layout ] ) as $c ) {
					$raw    = isset( $block['cols'][ $c ] ) && is_array( $block['cols'][ $c ] ) ? $block['cols'][ $c ] : array();
					$cols[] = self::sanitize_blocks( $raw, $trusted, false );
				}
				$blocks[] = array_merge( array( 'type' => 'columns', 'layout' => $layout, 'cols' => $cols ), self::sanitize_style( $block ) );
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
					case 'facebook':
					case 'instagram':
					case 'x':
					case 'youtube':
					case 'tiktok':
					case 'linkedin':
						$b[ $key ] = esc_url_raw( (string) $v );
						break;
					case 'color':
						$b[ $key ] = sanitize_hex_color( (string) $v ) ? sanitize_hex_color( (string) $v ) : '';
						break;
					case 'align':
						$b[ $key ] = in_array( $v, array( 'left', 'center', 'right' ), true ) ? $v : $default;
						break;
					case 'style':
						$b[ $key ] = isset( $types[ $type ]['options']['style'][ $v ] ) ? $v : $default;
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
					case 'items':
					case 'links':
						$b[ $key ] = sanitize_textarea_field( (string) $v );
						break;
					default:
						$b[ $key ] = is_numeric( $default ) ? ( is_numeric( $v ) ? $v + 0 : $default ) : sanitize_text_field( (string) $v );
				}
			}
			$blocks[] = array_merge( $b, self::sanitize_style( $block ) );
		}
		return $blocks;
	}

	/**
	 * @param string $hex Color like #0b3d2e.
	 * @return bool Dark enough that white text reads better.
	 */
	public static function is_dark( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return false;
		}
		list( $r, $g, $b ) = array_map( 'hexdec', str_split( $hex, 2 ) );
		return ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) < 140;
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
		$walk = static function ( $blocks ) use ( &$walk ) {
			foreach ( (array) $blocks as $b ) {
				if ( isset( $b['type'] ) && 'coupon' === $b['type'] ) {
					return $b;
				}
				if ( isset( $b['type'] ) && 'columns' === $b['type'] && ! empty( $b['cols'] ) ) {
					foreach ( $b['cols'] as $col ) {
						$found = $walk( $col );
						if ( $found ) {
							return $found;
						}
					}
				}
			}
			return null;
		};
		return $walk( isset( $design['blocks'] ) ? $design['blocks'] : array() );
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

		$body = self::render_blocks( $design['blocks'], $s, $tags, $ctx, '', false );

		$preheader = isset( $ctx['preheader'] ) ? Merge_Tags::apply( $ctx['preheader'], $tags, 'text' ) : '';
		// A Footer block in the design replaces the default footer text (the unsubscribe link always stays).
		$footer    = self::has_block( $design['blocks'], 'footer' ) ? '' : Merge_Tags::apply( wp_kses_post( Settings::get( 'email_footer' ) ), $tags, 'html' );
		$unsub     = isset( $ctx['unsubscribe_url'] ) ? $ctx['unsubscribe_url'] : '#';
		$canvas    = ! empty( $ctx['canvas'] );

		ob_start();
		include CHECKOUTFLOW_DIR . 'templates/email/layout.php';
		return ob_get_clean();
	}

	private static function has_block( $blocks, $type ) {
		foreach ( $blocks as $b ) {
			if ( $type === $b['type'] || ( 'columns' === $b['type'] && array_filter( (array) $b['cols'], static function ( $col ) use ( $type ) {
				return self::has_block( $col, $type );
			} ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render a list of blocks as table rows. In the builder canvas each block's row is tagged
	 * with its path ("3", or "2.1.0" = block 0 in column 1 of block 2) for click/drag/drop.
	 */
	private static function render_blocks( $blocks, $s, $tags, $ctx, $prefix, $in_col ) {
		$canvas = ! empty( $ctx['canvas'] );
		$types  = self::block_types();
		$out    = '';
		foreach ( $blocks as $i => $b ) {
			$path = $prefix . $i;
			$html = 'columns' === $b['type'] ? self::columns( $b, $s, $tags, $ctx, $path ) : self::block( $b, $s, $tags, $ctx, $in_col );
			if ( '' === $html ) {
				if ( ! $canvas ) {
					continue;
				}
				$html = '<tr><td style="padding:8px;text-align:center;color:#9ca3af;font-size:13px;"><div style="border:2px dashed #d1d5db;padding:16px;">' . esc_html( $types[ $b['type'] ]['label'] ) . '</div></td></tr>';
			}
			$html = self::wrap_style( $b, $html );
			if ( $canvas ) {
				$html = preg_replace( '/^\s*<tr\b/', '<tr data-cfb="' . esc_attr( $path ) . '"', $html, 1 );
			}
			$out .= $html;
		}
		return $out;
	}

	/**
	 * Background / vertical padding set on any block.
	 */
	private static function wrap_style( $b, $html ) {
		if ( empty( $b['_bg'] ) && ! isset( $b['_pt'] ) && ! isset( $b['_pb'] ) ) {
			return $html;
		}
		$style = ( ! empty( $b['_bg'] ) ? 'background:' . $b['_bg'] . ';' : '' )
			. 'padding:' . ( isset( $b['_pt'] ) ? (int) $b['_pt'] : 0 ) . 'px 0 ' . ( isset( $b['_pb'] ) ? (int) $b['_pb'] : 0 ) . 'px 0;';
		return '<tr><td style="' . esc_attr( $style ) . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $html . '</table></td></tr>';
	}

	/**
	 * A row of columns (stacks on phones via .cf-col in the layout's media query).
	 */
	private static function columns( $b, $s, $tags, $ctx, $path ) {
		$canvas  = ! empty( $ctx['canvas'] );
		$layouts = self::layouts();
		$widths  = isset( $layouts[ $b['layout'] ] ) ? $layouts[ $b['layout'] ] : $layouts['50-50'];
		$cells   = '';
		foreach ( $widths as $c => $w ) {
			$inner = self::render_blocks( isset( $b['cols'][ $c ] ) ? $b['cols'][ $c ] : array(), $s, $tags, $ctx, $path . '.' . $c . '.', true );
			if ( '' === $inner ) {
				$inner = $canvas
					? '<tr><td style="padding:6px;"><div class="cfb-col-empty" style="border:2px dashed #cbd5e1;border-radius:6px;padding:26px 8px;text-align:center;color:#94a3b8;font-size:12px;font-family:sans-serif;">' . esc_html__( 'Drop blocks here', 'checkoutflow' ) . '</div></td></tr>'
					: '<tr><td style="font-size:0;line-height:0;">&nbsp;</td></tr>';
			}
			$cells .= sprintf(
				'<td class="cf-col" width="%1$s%%" valign="top" style="width:%1$s%%;vertical-align:top;"%2$s><table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0">%3$s</table></td>',
				esc_attr( $w ),
				$canvas ? ' data-cfb-col="' . esc_attr( $path . '.' . $c ) . '"' : '',
				$inner
			);
		}
		return '<tr><td style="padding:4px 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table></td></tr>';
	}

	/**
	 * @param array $b      Block.
	 * @param array $s      Design settings.
	 * @param array $tags   Merge tag values.
	 * @param array $ctx    Context.
	 * @param bool  $in_col Inside a column (tighter side padding).
	 * @return string
	 */
	private static function block( $b, $s, $tags, $ctx, $in_col = false ) {
		$px     = $in_col ? 8 : 32;
		$pad    = 'padding:8px ' . $px . 'px;';
		$align  = isset( $b['align'] ) ? $b['align'] : 'left';
		$canvas = ! empty( $ctx['canvas'] );

		switch ( $b['type'] ) {
			case 'heading':
				// In the builder canvas the text is editable in place, so show the raw text with its tags.
				return sprintf(
					'<tr><td style="%1$stext-align:%2$s;"><h1 style="margin:8px 0;font-size:%3$dpx;line-height:1.25;font-weight:700;color:%4$s;"%6$s>%5$s</h1></td></tr>',
					$pad,
					esc_attr( $align ),
					max( 14, min( 48, (int) $b['size'] ) ),
					esc_attr( $b['color'] ? $b['color'] : $s['text_color'] ),
					$canvas ? esc_html( $b['text'] ) : Merge_Tags::apply( esc_html( $b['text'] ), $tags, 'html' ),
					$canvas ? ' data-cfb-edit="text"' : ''
				);

			case 'text':
				if ( $canvas ) {
					return sprintf( '<tr><td class="cf-text" style="%1$stext-align:%2$s;font-size:16px;line-height:1.6;color:%3$s;"><div data-cfb-edit="html">%4$s</div></td></tr>', $pad, esc_attr( $align ), esc_attr( $s['text_color'] ), $b['html'] );
				}
				$html = Merge_Tags::apply( $b['html'], $tags, 'html' );
				$html = preg_replace( '#<p(\s[^>]*)?>#i', '<p style="margin:0 0 14px;">', $html );
				return sprintf( '<tr><td class="cf-text" style="%1$stext-align:%2$s;font-size:16px;line-height:1.6;color:%3$s;">%4$s</td></tr>', $pad, esc_attr( $align ), esc_attr( $s['text_color'] ), $html );

			case 'logo':
				$src = $b['src'] ? $b['src'] : ( $s['logo'] ? $s['logo'] : self::site_logo() );
				if ( ! $src ) {
					// Store name instead of a logo: keep it readable on a dark band.
					$color = ! empty( $b['_bg'] ) && self::is_dark( $b['_bg'] ) ? '#ffffff' : $s['text_color'];
					return sprintf( '<tr><td style="padding:16px %1$dpx;text-align:%2$s;font-size:24px;font-weight:700;color:%3$s;">%4$s</td></tr>', $px, esc_attr( $align ), esc_attr( $color ), esc_html( get_bloginfo( 'name' ) ) );
				}
				$w   = max( 40, min( 600, (int) $b['width'] ) );
				$img = sprintf( '<img src="%1$s" alt="%2$s" width="%3$d" style="display:inline-block;width:%3$dpx;max-width:100%%;height:auto;border:0;">', esc_url( $src ), esc_attr( get_bloginfo( 'name' ) ), $w );
				$url = Merge_Tags::apply( $b['url'], $tags, 'url' );
				if ( $url ) {
					$img = '<a href="' . esc_url( $url ) . '">' . $img . '</a>';
				}
				return sprintf( '<tr><td style="padding:16px %1$dpx;text-align:%2$s;">%3$s</td></tr>', $px, esc_attr( $align ), $img );

			case 'list':
				$items = array_filter( array_map( 'trim', explode( "\n", (string) $b['items'] ) ), 'strlen' );
				if ( ! $items ) {
					return '';
				}
				$lis = '';
				foreach ( $items as $item ) {
					$text = Merge_Tags::apply( esc_html( $item ), $tags, 'html' );
					$lis .= 'check' === $b['style']
						? '<div style="margin:0 0 6px;"><span style="color:' . esc_attr( $s['accent'] ) . ';font-weight:700;">&#10003;</span>&nbsp; ' . $text . '</div>'
						: '<li style="margin:0 0 6px;">' . $text . '</li>';
				}
				if ( 'check' !== $b['style'] ) {
					$tag  = 'number' === $b['style'] ? 'ol' : 'ul';
					$lis  = '<' . $tag . ' style="margin:0;padding-left:22px;' . ( 'left' !== $align ? 'display:inline-block;text-align:left;' : '' ) . '">' . $lis . '</' . $tag . '>';
				}
				return sprintf( '<tr><td class="cf-text" style="%1$stext-align:%2$s;font-size:16px;line-height:1.6;color:%3$s;">%4$s</td></tr>', $pad, esc_attr( $align ), esc_attr( $s['text_color'] ), $lis );

			case 'button':
				$url   = Merge_Tags::apply( $b['url'], $tags, 'url' );
				$color = $b['color'] ? $b['color'] : $s['accent'];
				return sprintf( '<tr><td style="padding:16px %1$dpx;text-align:%2$s;">%3$s</td></tr>', $px, esc_attr( $align ), self::button( $url, Merge_Tags::apply( esc_html( $b['text'] ), $tags, 'html' ), $color ) );

			case 'image':
				if ( ! $b['src'] ) {
					return ! empty( $ctx['preview'] ) ? '<tr><td style="' . $pad . 'text-align:center;color:#9ca3af;font-size:13px;"><div style="border:2px dashed #d1d5db;padding:32px;">' . esc_html__( 'Image – set a URL in the block settings', 'checkoutflow' ) . '</div></td></tr>' : '';
				}
				$width = max( 10, min( 100, (int) $b['width'] ) );
				$px_w  = (int) round( ( $s['width'] - 64 ) * $width / 100 );
				$img   = sprintf( '<img src="%1$s" alt="%2$s" width="%3$d" style="display:inline-block;width:100%%;max-width:%3$dpx;height:auto;border:0;">', esc_url( $b['src'] ), esc_attr( $b['alt'] ), $px_w );
				if ( $b['url'] ) {
					$img = '<a href="' . esc_url( Merge_Tags::apply( $b['url'], $tags, 'url' ) ) . '">' . $img . '</a>';
				}
				return sprintf( '<tr><td style="%1$stext-align:%2$s;">%3$s</td></tr>', $pad, esc_attr( $align ), $img );

			case 'menu':
				$links = array();
				foreach ( array_filter( array_map( 'trim', explode( "\n", (string) $b['links'] ) ), 'strlen' ) as $line ) {
					$parts = array_map( 'trim', explode( '|', $line, 2 ) );
					$url   = isset( $parts[1] ) ? Merge_Tags::apply( $parts[1], $tags, 'url' ) : '';
					if ( '' === $parts[0] || ! $url ) {
						continue;
					}
					$links[] = '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $b['color'] ? $b['color'] : $s['text_color'] ) . ';text-decoration:none;font-weight:600;">' . esc_html( $parts[0] ) . '</a>';
				}
				if ( ! $links ) {
					return '';
				}
				return sprintf( '<tr><td style="%1$stext-align:%2$s;font-size:15px;">%3$s</td></tr>', $pad, esc_attr( $align ), implode( '<span style="color:#cbd5e1;">&nbsp;&nbsp;|&nbsp;&nbsp;</span>', $links ) );

			case 'social':
				$icons = '';
				foreach ( self::social_networks() as $key => $n ) {
					if ( empty( $b[ $key ] ) ) {
						continue;
					}
					$icons .= sprintf(
						'<a href="%1$s" title="%2$s" style="display:inline-block;width:36px;height:36px;line-height:36px;margin:0 4px;border-radius:50%%;background:%3$s;color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:700;text-align:center;text-decoration:none;">%4$s</a>',
						esc_url( $b[ $key ] ),
						esc_attr( $n[0] ),
						esc_attr( $n[1] ),
						$n[2]
					);
				}
				if ( '' === $icons ) {
					return $canvas ? '<tr><td style="' . $pad . 'text-align:center;color:#9ca3af;font-size:13px;"><div style="border:2px dashed #d1d5db;padding:16px;">' . esc_html__( 'Social – add your profile links in the block settings', 'checkoutflow' ) . '</div></td></tr>' : '';
				}
				return sprintf( '<tr><td style="padding:12px %1$dpx;text-align:%2$s;">%3$s</td></tr>', $px, esc_attr( $align ), $icons );

			case 'footer':
				return sprintf( '<tr><td class="cf-text" style="padding:12px %1$dpx;text-align:%2$s;font-size:12px;line-height:1.6;color:#6b7280;">%3$s</td></tr>', $px, esc_attr( $align ), Merge_Tags::apply( $b['html'], $tags, 'html' ) );

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
				return sprintf( '<tr><td style="padding:12px %1$dpx;"><div style="border-top:1px solid %2$s;font-size:0;line-height:0;">&nbsp;</div></td></tr>', $px, esc_attr( $b['color'] ? $b['color'] : '#e5e7eb' ) );

			case 'spacer':
				$h = max( 4, min( 120, (int) $b['height'] ) );
				return sprintf( '<tr><td style="height:%1$dpx;font-size:0;line-height:0;">&nbsp;</td></tr>', $h );

			case 'html':
				return '<tr><td style="' . $pad . '">' . Merge_Tags::apply( $b['html'], $tags, 'html' ) . '</td></tr>';
		}
		return '';
	}

	/**
	 * Social networks: key => [ name, brand color, badge label ]. Text badges render in every
	 * email client (many strip SVG, and remote icon images are often blocked).
	 */
	public static function social_networks() {
		return array(
			'facebook'  => array( 'Facebook', '#1877f2', 'f' ),
			'instagram' => array( 'Instagram', '#e1306c', 'IG' ),
			'x'         => array( 'X', '#000000', 'X' ),
			'youtube'   => array( 'YouTube', '#ff0000', '&#9654;' ),
			'tiktok'    => array( 'TikTok', '#111111', '&#9834;' ),
			'linkedin'  => array( 'LinkedIn', '#0a66c2', 'in' ),
		);
	}

	/**
	 * The theme's custom logo URL, if any.
	 */
	public static function site_logo() {
		$id = (int) get_theme_mod( 'custom_logo' );
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		return $url ? $url : '';
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
