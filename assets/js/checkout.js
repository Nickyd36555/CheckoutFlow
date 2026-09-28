/* CheckoutFlow – coupon field inside the order summary. */
( function ( $ ) {
	'use strict';
	if ( typeof wc_checkout_params === 'undefined' ) {
		return;
	}

	var $body = $( document.body );

	$body.on( 'click', '.cf-coupon-toggle', function ( e ) {
		e.preventDefault();
		var $box = $( this ).siblings( '.cf-coupon-box' );
		$box.prop( 'hidden', ! $box.prop( 'hidden' ) );
		if ( ! $box.prop( 'hidden' ) ) {
			$box.find( '.cf-coupon-input' ).trigger( 'focus' );
		}
	} );

	function apply( $box ) {
		var code = $.trim( $box.find( '.cf-coupon-input' ).val() );
		if ( ! code ) {
			return;
		}
		var $btn = $box.find( '.cf-coupon-apply' ).prop( 'disabled', true );

		$.post( wc_checkout_params.wc_ajax_url.toString().replace( '%%endpoint%%', 'apply_coupon' ), {
			security: wc_checkout_params.apply_coupon_nonce,
			coupon_code: code
		} ).done( function ( html ) {
			$( '.woocommerce-error, .woocommerce-message, .woocommerce-info, .is-error, .is-success' ).remove();
			$( 'form.checkout' ).before( html );
			$body.trigger( 'applied_coupon_in_checkout', [ code ] );
			$body.trigger( 'update_checkout', { update_shipping_method: false } );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	}

	$body.on( 'click', '.cf-coupon-apply', function ( e ) {
		e.preventDefault();
		apply( $( this ).closest( '.cf-coupon-box' ) );
	} );

	// Enter inside the coupon input must not submit the whole checkout form.
	$body.on( 'keydown', '.cf-coupon-input', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			apply( $( this ).closest( '.cf-coupon-box' ) );
		}
	} );
} )( jQuery );
