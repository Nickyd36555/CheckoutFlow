<?php
/**
 * Thank-you (order received) page: restyled to match the checkout, with editable text.
 * Loaded only on the order-received endpoint.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Thank_You {

	public static function enabled() {
		return (bool) Settings::get( 'ty_enabled' );
	}

	/**
	 * Called on `wp` once we know this is the order-received page.
	 */
	public static function setup() {
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_template' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		// Our own order details replace WooCommerce's table; other woocommerce_thankyou callbacks still run.
		remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'details' ), 10 );
	}

	public static function locate_template( $template, $name ) {
		if ( 'checkout/thankyou.php' === $name ) {
			$theme = locate_template( 'checkoutflow/' . $name );
			return $theme ? $theme : CHECKOUTFLOW_DIR . 'templates/' . $name;
		}
		return $template;
	}

	public static function assets() {
		list( $css, $ver ) = asset( 'css/thankyou.css' );
		wp_enqueue_style( 'checkoutflow-thankyou', $css, array(), $ver );
		wp_add_inline_style( 'checkoutflow-thankyou', 'body.cf-thankyou-page{--cf-accent:' . Settings::get( 'checkout_accent_color' ) . ';}' . design_css() );
	}

	public static function body_class( $classes ) {
		$classes[] = 'cf-thankyou-page';
		return $classes;
	}

	/**
	 * A text setting with the order's tags filled in.
	 *
	 * @param string    $key   Setting key.
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function text( $key, $order ) {
		$first = $order->get_billing_first_name() ? $order->get_billing_first_name() : $order->get_shipping_first_name();
		$text  = strtr(
			(string) Settings::get( $key ),
			array(
				'{first_name}'   => $first,
				'{order_number}' => $order->get_order_number(),
				'{email}'        => $order->get_billing_email(),
				'{total}'        => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			)
		);
		// "Thank you, !" when the name is missing.
		return trim( preg_replace( '/\s+([,!.])/', '$1', preg_replace( '/,\s*!/', '!', $text ) ) );
	}

	/**
	 * Order items, totals, addresses and "what happens next".
	 *
	 * @param int $order_id Order ID.
	 */
	public static function details( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$show_images = (bool) Settings::get( 'ty_show_images' );
		$totals      = $order->get_order_item_totals();
		if ( Settings::get( 'ty_show_overview' ) ) {
			unset( $totals['payment_method'] ); // Already in the overview cards.
		}
		?>
		<div class="cf-ty-grid">
			<section class="cf-ty-card cf-ty-order">
				<h2 class="cf-ty-h"><?php esc_html_e( 'Order details', 'checkoutflow' ); ?></h2>
				<ul class="cf-ty-items">
					<?php
					foreach ( $order->get_items() as $item_id => $item ) :
						if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
							continue;
						}
						$product = $item->get_product();
						$qty     = $item->get_quantity();
						?>
						<li class="cf-ty-item">
							<?php if ( $show_images ) : ?>
								<span class="cf-ty-thumb">
									<?php echo $product ? wp_kses_post( $product->get_image( 'woocommerce_gallery_thumbnail' ) ) : ''; ?>
									<span class="cf-ty-qty"><?php echo esc_html( $qty ); ?></span>
								</span>
							<?php endif; ?>
							<span class="cf-ty-name">
								<?php echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) ); ?>
								<?php if ( ! $show_images && $qty > 1 ) : ?>
									<span class="cf-ty-times">&times; <?php echo esc_html( $qty ); ?></span>
								<?php endif; ?>
								<?php
								do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );
								wc_display_item_meta( $item );
								do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
								?>
							</span>
							<span class="cf-ty-price"><?php echo wp_kses_post( self::line_total( $order, $item, $product ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( $totals ) : ?>
					<dl class="cf-ty-totals">
						<?php foreach ( $totals as $key => $total ) : ?>
							<div class="cf-ty-total-row is-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>">
								<dt><?php echo esc_html( rtrim( wp_strip_all_tags( $total['label'] ), ':' ) ); ?></dt>
								<dd><?php echo wp_kses_post( $total['value'] ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>

				<?php if ( $order->get_customer_note() ) : ?>
					<p class="cf-ty-note"><strong><?php esc_html_e( 'Note:', 'woocommerce' ); ?></strong> <?php echo wp_kses( nl2br( wptexturize( $order->get_customer_note() ) ), array( 'br' => array() ) ); ?></p>
				<?php endif; ?>

				<?php do_action( 'woocommerce_order_details_after_order_table', $order ); ?>
			</section>

			<div class="cf-ty-side">
				<?php self::addresses( $order ); ?>
				<?php self::next_steps(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Line total, with the list price struck through when the line was discounted
	 * (e.g. a store-wide sale or a CheckoutFlow discount rule).
	 */
	private static function line_total( $order, $item, $product ) {
		$html = $order->get_formatted_line_subtotal( $item );
		if ( ! $product ) {
			return $html;
		}
		$list = (float) $product->get_regular_price() * $item->get_quantity();
		if ( $list > 0 && $list - (float) $item->get_subtotal() > 0.009 ) {
			$was = 'incl' === get_option( 'woocommerce_tax_display_cart' ) ? wc_get_price_including_tax( $product, array( 'qty' => $item->get_quantity(), 'price' => $product->get_regular_price() ) ) : $list;
			return '<del aria-hidden="true">' . wc_price( $was, array( 'currency' => $order->get_currency() ) ) . '</del> <ins>' . $html . '</ins>';
		}
		return $html;
	}

	private static function addresses( $order ) {
		$shipping = $order->needs_shipping_address() ? $order->get_formatted_shipping_address() : '';
		$billing  = $order->get_formatted_billing_address();
		$mode     = Settings::get( 'ty_billing' );
		$same     = $shipping && wp_strip_all_tags( $shipping ) === wp_strip_all_tags( $billing );
		$show_bil = $billing && ( 'always' === $mode || ( 'different' === $mode && ( ! $same || ! $shipping ) ) );
		if ( ! $shipping && ! $show_bil ) {
			return;
		}
		?>
		<section class="cf-ty-card cf-ty-addresses">
			<?php if ( $shipping ) : ?>
				<div class="cf-ty-address">
					<h2 class="cf-ty-h"><?php esc_html_e( 'Shipping to', 'checkoutflow' ); ?></h2>
					<address><?php echo wp_kses_post( $shipping ); ?></address>
					<?php if ( $order->get_shipping_phone() || $order->get_billing_phone() ) : ?>
						<p class="cf-ty-contact"><?php echo esc_html( $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() ); ?></p>
					<?php endif; ?>
					<?php if ( $order->get_shipping_method() ) : ?>
						<p class="cf-ty-contact"><?php echo esc_html( $order->get_shipping_method() ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $show_bil ) : ?>
				<div class="cf-ty-address">
					<h2 class="cf-ty-h"><?php esc_html_e( 'Billing address', 'checkoutflow' ); ?></h2>
					<address><?php echo wp_kses_post( $billing ); ?></address>
					<?php if ( ! $shipping && $order->get_billing_phone() ) : ?>
						<p class="cf-ty-contact"><?php echo esc_html( $order->get_billing_phone() ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php do_action( 'woocommerce_order_details_after_customer_details', $order ); ?>
		</section>
		<?php
	}

	private static function next_steps() {
		$html = trim( (string) Settings::get( 'ty_next_html' ) );
		if ( '' === $html ) {
			$html = trim( (string) Settings::get( 'checkout_summary_html' ) );
		}
		$support = trim( (string) Settings::get( 'ty_support_html' ) );
		$buttons = array();
		foreach ( array( 1, 2 ) as $n ) {
			$label = trim( (string) Settings::get( 'ty_button' . $n . '_text' ) );
			$url   = $label ? self::button_url( $n ) : '';
			if ( $label && $url ) {
				$buttons[] = array( $label, $url );
			}
		}
		if ( '' === $html && '' === $support && ! $buttons ) {
			return;
		}
		?>
		<section class="cf-ty-card cf-ty-next">
			<?php if ( '' !== $html ) : ?>
				<h2 class="cf-ty-h"><?php echo esc_html( Settings::get( 'ty_next_heading' ) ); ?></h2>
				<div class="cf-ty-next-text"><?php echo wp_kses_post( wpautop( $html ) ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $support ) : ?>
				<div class="cf-ty-support"><?php echo wp_kses_post( wpautop( $support ) ); ?></div>
			<?php endif; ?>
			<?php if ( $buttons ) : ?>
				<p class="cf-ty-actions">
					<?php foreach ( $buttons as $i => $b ) : ?>
						<a class="cf-ty-btn<?php echo $i ? ' is-ghost' : ''; ?>" href="<?php echo esc_url( $b[1] ); ?>"><?php echo esc_html( $b[0] ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param int $n Button number.
	 * @return string
	 */
	private static function button_url( $n ) {
		$url = (string) Settings::get( 'ty_button' . $n . '_url' );
		if ( '' !== $url ) {
			return $url;
		}
		if ( 2 === $n ) {
			return continue_shopping_url();
		}
		foreach ( array( 'track-your-order', 'order-tracking', 'track-order' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				return get_permalink( $page );
			}
		}
		return is_user_logged_in() ? wc_get_account_endpoint_url( 'orders' ) : '';
	}
}
