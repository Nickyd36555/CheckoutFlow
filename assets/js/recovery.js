/* CheckoutFlow – capture checkout email for abandoned cart recovery (classic + block checkout). */
( function () {
	'use strict';
	var P = window.checkoutflowRecovery;
	if ( ! P || ! window.fetch ) {
		return;
	}

	var EMAIL = [ '#billing_email', '#email', 'input[name="billing_email"]', 'input[type="email"][autocomplete="email"]' ];
	// Block checkout uses ids like "billing-first_name"; classic uses "billing_first_name".
	var FIELDS = [ 'first_name', 'last_name', 'phone', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];
	var timer = null;
	var last = '';

	function val( sel ) {
		var el = document.querySelector( sel );
		return el && typeof el.value === 'string' ? el.value.trim() : '';
	}

	function email() {
		for ( var i = 0; i < EMAIL.length; i++ ) {
			var v = val( EMAIL[ i ] );
			if ( v ) {
				return v;
			}
		}
		return '';
	}

	function collect() {
		var data = new FormData();
		data.append( 'email', email() );
		[ 'billing', 'shipping' ].forEach( function ( group ) {
			FIELDS.forEach( function ( f ) {
				var v = val( '#' + group + '_' + f ) || val( '#' + group + '-' + f );
				if ( v ) {
					data.append( 'fields[' + group + '_' + f + ']', v );
				}
			} );
		} );
		return data;
	}

	function send() {
		var e = email();
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( e ) ) {
			return;
		}
		var data = collect();
		var sig = Array.from( data.entries() ).join( '|' );
		if ( sig === last ) {
			return;
		}
		last = sig;
		fetch( P.url, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true } ).catch( function () {} );
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( send, 900 );
	}

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.closest && e.target.closest( 'form.checkout, .wc-block-checkout' ) ) {
			schedule();
		}
	}, true );
	document.addEventListener( 'focusout', function ( e ) {
		if ( e.target.closest && e.target.closest( 'form.checkout, .wc-block-checkout' ) ) {
			schedule();
		}
	}, true );
	// Leaving the page: flush immediately.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			send();
		}
	} );
	// Prefilled (logged-in / returning) customers.
	window.addEventListener( 'load', function () {
		setTimeout( send, 1500 );
	} );
} )();
