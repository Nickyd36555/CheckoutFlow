/* CheckoutFlow – side cart. No dependencies; hooks into jQuery events when present. */
( function () {
	'use strict';

	var P = window.checkoutflowCart;
	var root = document.getElementById( 'cf-cart' );
	if ( ! P || ! root ) {
		return;
	}
	var drawer = root.querySelector( '.cfc-drawer' );
	var notices = root.querySelector( '.cfc-notices' );
	var lastFocus = null;
	var $ = window.jQuery;

	function content() {
		return root.querySelector( '.cf-cart-content' );
	}

	/* ---------- open / close ---------- */

	function open() {
		if ( root.classList.contains( 'is-open' ) ) {
			return;
		}
		lastFocus = document.activeElement;
		root.hidden = false;
		document.documentElement.classList.add( 'cfc-locked' );
		// Force reflow so the transition runs after un-hiding.
		void root.offsetWidth;
		root.classList.add( 'is-open' );
		drawer.focus();
		if ( ! content().dataset.nonce ) {
			refresh();
		}
	}

	function close() {
		root.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'cfc-locked' );
		setTimeout( function () {
			if ( ! root.classList.contains( 'is-open' ) ) {
				root.hidden = true;
			}
		}, 300 );
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var opener = e.target.closest( '.cf-open-cart, .fkcart-mini-open' );
		if ( opener ) {
			e.preventDefault();
			open();
			return;
		}
		if ( e.target.closest( '[data-cf-close]' ) ) {
			e.preventDefault();
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! root.classList.contains( 'is-open' ) ) {
			return;
		}
		if ( e.key === 'Escape' ) {
			close();
		} else if ( e.key === 'Tab' ) {
			// Keep focus inside the dialog.
			var f = drawer.querySelectorAll( 'a[href], button:not([disabled]), input, summary, [tabindex]:not([tabindex="-1"])' );
			if ( ! f.length ) {
				return;
			}
			var first = f[ 0 ], last = f[ f.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	} );

	/* ---------- server calls ---------- */

	function applyFragments( fragments ) {
		Object.keys( fragments || {} ).forEach( function ( sel ) {
			document.querySelectorAll( sel ).forEach( function ( el ) {
				var tpl = document.createElement( 'template' );
				tpl.innerHTML = fragments[ sel ].trim();
				if ( tpl.content.firstElementChild ) {
					el.replaceWith( tpl.content.firstElementChild );
				}
			} );
		} );
		syncCount();
	}

	function syncCount() {
		var c = content();
		var n = c ? parseInt( c.dataset.count || '0', 10 ) : 0;
		document.querySelectorAll( '.cfc-fab' ).forEach( function ( b ) {
			b.classList.toggle( 'is-empty', n === 0 );
		} );
	}

	function showNotices( list ) {
		notices.innerHTML = '';
		( list || [] ).forEach( function ( n ) {
			var p = document.createElement( 'p' );
			p.className = 'cfc-notice' + ( n.type === 'error' ? ' is-error' : '' );
			p.textContent = n.text;
			notices.appendChild( p );
		} );
	}

	function request( endpoint, data ) {
		var body = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) ) {
			Object.keys( data || {} ).forEach( function ( k ) {
				body.append( k, data[ k ] );
			} );
		}
		var c = content();
		if ( c && c.dataset.nonce ) {
			body.append( 'nonce', c.dataset.nonce );
		}
		if ( c ) {
			c.classList.add( 'is-loading' );
		}
		return fetch( P.ajaxUrl.replace( '%%endpoint%%', endpoint ), {
			method: 'POST',
			body: body,
			credentials: 'same-origin'
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				applyFragments( res.fragments );
				showNotices( res.notices );
				return res;
			} )
			.catch( function () {
				showNotices( [ { type: 'error', text: P.i18n.error } ] );
				var cc = content();
				if ( cc ) {
					cc.classList.remove( 'is-loading' );
				}
				return { success: false };
			} );
	}

	var announcing = false;

	/** Tell WooCommerce (cart-fragments, block mini-cart, analytics plugins) the cart changed. */
	function announce( res, eventName, $button ) {
		if ( $ && res && res.fragments ) {
			announcing = true;
			$( document.body ).trigger( eventName, [ res.fragments, res.cart_hash, $button || $() ] );
			announcing = false;
		}
	}

	function refresh() {
		return request( 'cf_cart_get', {} );
	}

	/* ---------- cart actions ---------- */

	var qtyTimers = {};

	function setQty( item, qty ) {
		var key = item.dataset.key;
		clearTimeout( qtyTimers[ key ] );
		qtyTimers[ key ] = setTimeout( function () {
			request( 'cf_cart_qty', { key: key, qty: qty } ).then( function ( res ) {
				announce( res, 'removed_from_cart' );
			} );
		}, 350 );
	}

	root.addEventListener( 'click', function ( e ) {
		var t;
		if ( ( t = e.target.closest( '.cfc-qty-btn' ) ) ) {
			var item = t.closest( '.cfc-item' );
			var input = item.querySelector( '.cfc-qty-input' );
			var max = parseInt( input.max || '0', 10 );
			var v = Math.max( 0, ( parseInt( input.value, 10 ) || 0 ) + parseInt( t.dataset.step, 10 ) );
			if ( max > 0 ) {
				v = Math.min( max, v );
			}
			input.value = v;
			setQty( item, v );
		} else if ( ( t = e.target.closest( '.cfc-remove' ) ) ) {
			var li = t.closest( '.cfc-item' );
			li.style.opacity = '.4';
			request( 'cf_cart_remove', { key: li.dataset.key } ).then( function ( res ) {
				announce( res, 'removed_from_cart' );
			} );
		} else if ( ( t = e.target.closest( '.cfc-remove-coupon' ) ) ) {
			request( 'cf_cart_remove_coupon', { code: t.dataset.code } ).then( function ( res ) {
				announce( res, 'removed_from_cart' );
			} );
		} else if ( ( t = e.target.closest( '.cfc-add' ) ) ) {
			t.disabled = true;
			var fd = new FormData();
			fd.append( 'cf-add-to-cart', t.dataset.product );
			fd.append( 'quantity', '1' );
			request( 'cf_cart_add', fd ).then( function ( res ) {
				announce( res, 'added_to_cart' );
			} );
		}
	} );

	root.addEventListener( 'change', function ( e ) {
		if ( e.target.classList.contains( 'cfc-qty-input' ) ) {
			setQty( e.target.closest( '.cfc-item' ), Math.max( 0, parseInt( e.target.value, 10 ) || 0 ) );
		}
	} );

	root.addEventListener( 'submit', function ( e ) {
		if ( e.target.classList.contains( 'cfc-coupon-form' ) ) {
			e.preventDefault();
			request( 'cf_cart_coupon', { code: e.target.elements.code.value } ).then( function ( res ) {
				announce( res, 'removed_from_cart' );
			} );
		}
	} );

	/* ---------- AJAX add to cart on single product pages ---------- */

	if ( P.ajaxSingle ) {
		document.addEventListener( 'submit', function ( e ) {
			var form = e.target;
			if ( ! form.matches( 'form.cart' ) || form.closest( '.cf-cart' ) || form.hasAttribute( 'data-cf-no-ajax' ) || e.defaultPrevented ) {
				return;
			}
			var submitter = e.submitter || form.querySelector( '[type="submit"][name="add-to-cart"]' );
			var fd;
			try {
				fd = new FormData( form, submitter || undefined );
			} catch ( err ) {
				fd = new FormData( form );
			}
			var id = fd.get( 'add-to-cart' ) || ( submitter && submitter.name === 'add-to-cart' ? submitter.value : '' );
			if ( ! id ) {
				return; // External/affiliate products etc. – let the browser handle it.
			}
			e.preventDefault();
			fd.delete( 'add-to-cart' );
			fd.append( 'cf-add-to-cart', id );

			var btn = form.querySelector( '.single_add_to_cart_button' );
			if ( btn ) {
				btn.classList.add( 'loading' );
				btn.disabled = true;
			}
			request( 'cf_cart_add', fd ).then( function ( res ) {
				if ( btn ) {
					btn.classList.remove( 'loading' );
					btn.disabled = false;
				}
				if ( res.success ) {
					announce( res, 'added_to_cart', $ && btn ? $( btn ) : null );
					// Without jQuery the added_to_cart listener below never fires.
					if ( ! $ && P.autoOpen ) {
						open();
					}
				} else if ( res.notices && res.notices.length ) {
					open();
				}
			} );
		} );
	}

	/* ---------- WooCommerce integration ---------- */

	if ( $ ) {
		// Archive-page AJAX add to cart (WooCommerce's add-to-cart.js) has already
		// applied our fragments by the time this fires.
		$( document.body ).on( 'added_to_cart', function () {
			syncCount();
			if ( P.autoOpen ) {
				open();
			}
		} );
		$( document.body ).on( 'wc_fragments_loaded wc_fragments_refreshed', syncCount );
	}

	// Block themes: product buttons add via the Store API and fire native events instead.
	document.body.addEventListener( 'wc-blocks_added_to_cart', function () {
		if ( announcing ) {
			return;
		}
		refresh().then( function () {
			if ( P.autoOpen ) {
				open();
			}
		} );
	} );
	document.body.addEventListener( 'wc-blocks_removed_from_cart', function () {
		if ( ! announcing ) {
			refresh();
		}
	} );

	// Populate count on load, but only when the cart cookie says there's something
	// to show, and only if WooCommerce's cart-fragments isn't already doing it.
	function boot() {
		var hasItems = /(?:^|;\s*)woocommerce_items_in_cart=1/.test( document.cookie );
		if ( hasItems && typeof window.wc_cart_fragments_params === 'undefined' ) {
			refresh();
		}
		syncCount();
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
