/* CheckoutFlow – checkout enhancements: summary coupon, quantity controls, address helpers. */
( function ( $ ) {
	'use strict';
	if ( typeof wc_checkout_params === 'undefined' ) {
		return;
	}

	var T = window.checkoutflowCheckout || {};
	var $body = $( document.body );

	function endpoint( name ) {
		return wc_checkout_params.wc_ajax_url.toString().replace( '%%endpoint%%', name );
	}

	/* ---------- coupon in the summary ---------- */

	$body.on( 'click', '.cf-coupon-toggle', function ( e ) {
		e.preventDefault();
		var $box = $( this ).siblings( '.cf-coupon-box' );
		$box.prop( 'hidden', ! $box.prop( 'hidden' ) );
		if ( ! $box.prop( 'hidden' ) ) {
			$box.find( '.cf-coupon-input' ).trigger( 'focus' );
		}
	} );

	// The summary is re-rendered on every checkout update, so the coupon message is kept here
	// and put back under the coupon box each time.
	var couponMsg = null;

	function noticeText( html ) {
		var $n = $( '<div>' ).html( html );
		$n.find( 'a.button, button, script, style' ).remove();
		return $.trim( $n.text().replace( /\s+/g, ' ' ) );
	}

	function showCouponMsg() {
		var $wrap = $( '.cf-summary .cf-coupon' );
		$wrap.find( '.cf-coupon-msg' ).remove();
		if ( ! couponMsg || ! $wrap.length ) {
			return;
		}
		var $m = $( '<p class="cf-coupon-msg" role="status"></p>' ).addClass( couponMsg.ok ? 'is-success' : 'is-error' );
		$m.append( couponMsg.ok
			? '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 16 16"><circle cx="8" cy="8" r="8" fill="currentColor"/><path d="M4.5 8.2l2.2 2.2 4.8-4.8" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>'
			: '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 16 16"><circle cx="8" cy="8" r="8" fill="currentColor"/><path d="M8 4.2v4.6M8 11.3v.2" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>' );
		$m.append( $( '<span class="cf-coupon-msg-text"></span>' ).text( couponMsg.text ) );
		if ( couponMsg.ok && couponMsg.code ) {
			$m.append( ' ' ).append(
				$( '<a href="#" class="woocommerce-remove-coupon cf-coupon-msg-remove"></a>' ).attr( 'data-coupon', couponMsg.code ).text( T.removeCoupon || 'Remove' )
			);
		}
		$wrap.append( $m );
	}

	function applyCoupon( $box ) {
		var code = $.trim( $box.find( '.cf-coupon-input' ).val() );
		if ( ! code ) {
			return;
		}
		var $btn = $box.find( '.cf-coupon-apply' ).prop( 'disabled', true );

		$.post( endpoint( 'apply_coupon' ), {
			security: wc_checkout_params.apply_coupon_nonce,
			coupon_code: code
		} ).done( function ( html ) {
			$( '.woocommerce-error, .woocommerce-message, .woocommerce-info, .is-error, .is-success' ).not( '.cf-coupon-msg' ).remove();
			var failed = /woocommerce-error|is-error/.test( html );
			couponMsg = {
				ok: ! failed,
				code: failed ? '' : code.toLowerCase(),
				text: failed ? ( noticeText( html ) || T.couponError ) : ( T.couponApplied || noticeText( html ) )
			};
			showCouponMsg();
			if ( ! failed ) {
				$box.find( '.cf-coupon-input' ).val( '' );
				$body.trigger( 'applied_coupon_in_checkout', [ code ] );
			}
			$body.trigger( 'update_checkout', { update_shipping_method: false } );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	}

	// WooCommerce removes the coupon (summary row or our message link) and prints its notice
	// above the form; keep the feedback next to the coupon box instead.
	$body.on( 'removed_coupon_in_checkout', function () {
		$( 'form.checkout' ).prevAll( '.woocommerce-message, .woocommerce-error, .woocommerce-info, .wc-block-components-notice-banner' ).remove();
		$( '.woocommerce-notices-wrapper' ).empty();
		couponMsg = { ok: true, code: '', text: T.couponRemoved || 'Coupon removed.' };
		showCouponMsg();
	} );
	$body.on( 'input', '.cf-coupon-input', function () {
		// The Apply button lights up once something is typed.
		$( this ).closest( '.cf-coupon-box' ).toggleClass( 'has-code', $.trim( this.value ) !== '' );
		if ( couponMsg && ! couponMsg.ok ) {
			couponMsg = null;
			showCouponMsg();
		}
	} );

	$body.on( 'click', '.cf-coupon-apply', function ( e ) {
		e.preventDefault();
		applyCoupon( $( this ).closest( '.cf-coupon-box' ) );
	} );

	// Enter inside the coupon input must not submit the whole checkout form.
	$body.on( 'keydown', '.cf-coupon-input', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			applyCoupon( $( this ).closest( '.cf-coupon-box' ) );
		}
	} );

	/* ---------- quantity / remove in the summary (modern layout) ---------- */

	var qtyTimer = null;

	function setQty( $row, qty ) {
		clearTimeout( qtyTimer );
		qtyTimer = setTimeout( function () {
			var $summary = $row.closest( '.cf-summary' );
			$summary.addClass( 'is-updating' );
			$.post( endpoint( 'cf_checkout_qty' ), {
				nonce: $summary.data( 'nonce' ),
				key: $row.data( 'key' ),
				qty: qty
			} ).done( function ( res ) {
				if ( ! res || ! res.success || res.data.empty ) {
					window.location.reload();
					return;
				}
				$body.trigger( 'update_checkout' );
			} ).fail( function () {
				window.location.reload();
			} );
		}, 400 );
	}

	$body.on( 'click', '.cf-summary .cf-qty-btn', function () {
		var $row = $( this ).closest( 'tr' );
		var $input = $row.find( '.cf-qty-input' );
		var max = parseInt( $input.attr( 'max' ) || '0', 10 );
		var v = Math.max( 0, ( parseInt( $input.val(), 10 ) || 0 ) + parseInt( $( this ).data( 'step' ), 10 ) );
		if ( max > 0 ) {
			v = Math.min( max, v );
		}
		$input.val( v );
		$row.find( '.cf-item-qty-badge' ).text( v );
		setQty( $row, v );
	} );

	$body.on( 'change', '.cf-summary .cf-qty-input', function () {
		setQty( $( this ).closest( 'tr' ), Math.max( 0, parseInt( $( this ).val(), 10 ) || 0 ) );
	} );

	$body.on( 'click', '.cf-summary .cf-item-remove', function () {
		var $row = $( this ).closest( 'tr' ).css( 'opacity', 0.4 );
		setQty( $row, 0 );
	} );

	/* ---------- "+ Add apartment" link ---------- */
	// A class (not .hide()) because WooCommerce's address-i18n script re-shows fields on country change.

	function collapseAddress2() {
		$( '#billing_address_2_field, #shipping_address_2_field' ).each( function () {
			var $field = $( this );
			if ( $field.data( 'cfCollapsed' ) || $field.find( 'input' ).val() ) {
				return;
			}
			$field.data( 'cfCollapsed', true ).addClass( 'cf-collapsed' );
			$( '<p class="form-row form-row-wide cf-add-address2"><a href="#"></a></p>' )
				.find( 'a' ).text( T.addAddress2 || '+ Add apartment, suite, unit, etc.' ).end()
				.insertBefore( $field )
				.on( 'click', 'a', function ( e ) {
					e.preventDefault();
					$( this ).closest( '.cf-add-address2' ).remove();
					$field.removeClass( 'cf-collapsed' ).find( 'input' ).trigger( 'focus' );
				} );
		} );
	}

	/* ---------- shipping-first: billing box + keep billing in sync ---------- */

	var $different = $( '#cf-different-billing' );
	var syncing = false;

	function differentBilling() {
		return $different.length && $different.is( ':checked' );
	}

	function syncBilling() {
		if ( ! $different.length || differentBilling() || syncing ) {
			return;
		}
		syncing = true;
		// Country first: WooCommerce rebuilds the state field when it changes.
		var country = $( '#shipping_country' ).val();
		if ( $( '#billing_country' ).length && $( '#billing_country' ).val() !== country ) {
			$( '#billing_country' ).val( country ).trigger( 'change' );
		}
		[ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode' ].forEach( function ( k ) {
			var $b = $( '#billing_' + k );
			var $s = $( '#shipping_' + k );
			if ( $b.length && $s.length && $b.val() !== $s.val() ) {
				$b.val( $s.val() );
				if ( $b.is( 'select' ) ) {
					$b.trigger( 'change.select2' );
				}
			}
		} );
		syncing = false;
	}

	$different.on( 'change', function () {
		$( '.cf-billing-address' ).prop( 'hidden', ! differentBilling() );
		syncBilling();
		$body.trigger( 'update_checkout' );
	} );
	$( 'form.checkout' ).on( 'change input', '.woocommerce-shipping-fields :input', syncBilling );

	/* ---------- mobile summary toggle ---------- */

	$body.on( 'click', '.cf-summary-toggle', function () {
		var $aside = $( this ).closest( '.cf-col-summary' ).toggleClass( 'is-open' );
		var open = $aside.hasClass( 'is-open' );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' )
			.find( '.cf-summary-toggle-label' ).text( open ? T.hideSummary : T.showSummary );
	} );

	/* ---------- selected shipping rate highlight ---------- */

	$body.on( 'change', '.cf-rates input.shipping_method', function () {
		$( this ).closest( '.cf-rates' ).find( '.cf-rate' ).removeClass( 'is-selected' );
		$( this ).closest( '.cf-rate' ).addClass( 'is-selected' );
	} );

	/* ---------- floating labels ---------- */

	function floatLabels() {
		$( 'form.cf-modern .form-row' ).each( function () {
			var $row = $( this );
			var $label = $row.children( 'label' ).not( '.checkbox, .woocommerce-form__label-for-checkbox, .screen-reader-text' );
			var $input = $row.find( '.woocommerce-input-wrapper' ).children( 'input.input-text, select, textarea' ).first();
			if ( ! $label.length || ! $input.length || $input.is( '[type="hidden"]' ) ) {
				return;
			}
			$row.addClass( 'cf-float' ).toggleClass( 'cf-textarea', $input.is( 'textarea' ) );
			$row.toggleClass( 'cf-filled', $input.is( 'select' ) || $.trim( $input.val() || '' ) !== '' );
		} );
	}
	$( document ).on( 'input change blur', 'form.cf-modern .form-row :input', function () {
		var $row = $( this ).closest( '.form-row.cf-float' );
		if ( $row.length ) {
			$row.toggleClass( 'cf-filled', $( this ).is( 'select' ) || $.trim( $( this ).val() || '' ) !== '' );
		}
	} );

	/* ---------- Route widget: left aligned like the rest of the form ---------- */

	function alignRoute() {
		document.querySelectorAll( '.cf-route route-protect-widget' ).forEach( function ( w ) {
			if ( w.getAttribute( 'alignment' ) !== 'left' ) {
				w.setAttribute( 'alignment', 'left' );
			}
		} );
	}

	/* ---------- store credit / add-ons above the payment methods: yellow stars, green amounts ---------- */

	function decorateAddons() {
		// The add-on box under "Payment Information", plus any summary row with stars (store credit).
		var boxes = Array.prototype.slice.call( document.querySelectorAll( '.cf-before-payment' ) );
		document.querySelectorAll( '.cf-totals tr, .cf-totals .cf-row, #order_review tr, .woocommerce-checkout-review-order-table tr' ).forEach( function ( row ) {
			if ( /[★☆]/.test( row.textContent ) && boxes.indexOf( row ) < 0 ) {
				row.classList.add( 'cf-star-row' );
				boxes.push( row );
			}
		} );
		boxes.forEach( function ( box ) {
			var walker = document.createTreeWalker( box, NodeFilter.SHOW_TEXT );
			var nodes = [];
			while ( walker.nextNode() ) {
				var n = walker.currentNode;
				if ( /[★☆]|[$€£]\s?\d/.test( n.nodeValue ) && ! n.parentNode.closest( '.cf-star, .cf-money, .woocommerce-Price-amount, script, style' ) ) {
					nodes.push( n );
				}
			}
			nodes.forEach( function ( n ) {
				var html = n.nodeValue.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
					.replace( /[★☆]/g, '<span class="cf-star">$&</span>' )
					.replace( /[$€£]\s?\d[\d,]*(?:\.\d+)?/g, '<span class="cf-money">$&</span>' );
				var span = document.createElement( 'span' );
				span.innerHTML = html;
				n.parentNode.replaceChild( span, n );
			} );
		} );
	}

	$( function () {
		decorateAddons();
		collapseAddress2();
		syncBilling();
		floatLabels();
		alignRoute();
		// Browser autofill doesn't fire input events.
		setTimeout( floatLabels, 600 );
	} );
	$body.on( 'updated_checkout', showCouponMsg );
	$body.on( 'updated_checkout country_to_state_changed', function () {
		decorateAddons();
		collapseAddress2();
		floatLabels();
		alignRoute();
	} );
} )( jQuery );
