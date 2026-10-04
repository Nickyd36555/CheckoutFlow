/* CheckoutFlow – small admin behaviours. */
( function () {
	'use strict';
	var A = window.checkoutflowAdmin || {};

	// Confirm destructive buttons.
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-confirm]' );
		if ( b && ! window.confirm( b.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// Select-all checkboxes.
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.classList.contains( 'cf-check-all' ) ) {
			e.target.closest( 'table' ).querySelectorAll( 'tbody input[type="checkbox"]' ).forEach( function ( c ) {
				c.checked = e.target.checked;
			} );
		}
	} );

	// Automation trigger: show the matching settings (and only submit those).
	var trigger = document.getElementById( 'cf-trigger' );
	if ( trigger ) {
		trigger.addEventListener( 'change', function () {
			document.querySelectorAll( '.cf-trigger-settings' ).forEach( function ( box ) {
				var on = box.dataset.trigger === trigger.value;
				box.hidden = ! on;
				box.querySelectorAll( 'input, select' ).forEach( function ( i ) {
					i.disabled = ! on;
				} );
			} );
		} );
	}

	// Discount rule editor: tiers + scope.
	var tiersBody = document.querySelector( '.cf-tiers tbody' );
	var tierTpl = document.getElementById( 'cf-tier-template' );
	if ( tiersBody && tierTpl ) {
		var n = tiersBody.children.length + 100;
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.cf-tier-add' ) ) {
				var tpl = document.createElement( 'template' );
				tpl.innerHTML = tierTpl.innerHTML.replace( /__i__/g, String( n++ ) ).trim();
				tiersBody.appendChild( tpl.content.firstElementChild );
			} else if ( e.target.closest( '.cf-tier-remove' ) && tiersBody.children.length > 1 ) {
				e.target.closest( 'tr' ).remove();
			}
		} );
		document.querySelectorAll( 'input[name="rule[scope]"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', function () {
				document.querySelector( '.cf-scope' ).hidden = r.value !== 'specific' || ! r.checked;
			} );
		} );
	}

	// Campaign audience: live count.
	var form = document.getElementById( 'cf-campaign-form' );
	var count = document.getElementById( 'cf-audience-count' );
	if ( form && count ) {
		var t = null;
		form.addEventListener( 'input', function ( e ) {
			if ( ! e.target.name || e.target.name.indexOf( 'audience[' ) !== 0 ) {
				return;
			}
			clearTimeout( t );
			t = setTimeout( function () {
				var fd = new FormData();
				fd.append( 'action', 'cf_audience_count' );
				fd.append( 'nonce', A.nonce );
				new FormData( form ).forEach( function ( v, k ) {
					if ( k.indexOf( 'audience[' ) === 0 ) {
						fd.append( k, v );
					}
				} );
				count.textContent = '…';
				fetch( A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						count.textContent = res.success ? Number( res.data.count ).toLocaleString() : '?';
					} );
			}, 300 );
		} );
	}
} )();

/* Dashboard chart: metric toggle and hover tooltip. */
( function () {
	document.querySelectorAll( '.cf-chart' ).forEach( function ( card ) {
		var tip = card.querySelector( '.cf-tip' );
		card.querySelectorAll( '.cf-seg button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				card.querySelectorAll( '.cf-seg button' ).forEach( function ( b ) {
					b.classList.toggle( 'is-active', b === btn );
				} );
				card.querySelectorAll( '[data-plot]' ).forEach( function ( p ) {
					p.hidden = p.getAttribute( 'data-plot' ) !== btn.getAttribute( 'data-show' );
				} );
			} );
		} );
		card.addEventListener( 'mousemove', function ( e ) {
			var bar = e.target.closest && e.target.closest( '.cf-bar' );
			if ( ! bar ) {
				tip.hidden = true;
				return;
			}
			var box = card.getBoundingClientRect();
			var r = bar.getBoundingClientRect();
			tip.textContent = bar.getAttribute( 'data-tip' );
			tip.hidden = false;
			var x = Math.min( Math.max( r.left + r.width / 2 - box.left, tip.offsetWidth / 2 ), box.width - tip.offsetWidth / 2 );
			tip.style.left = x + 'px';
			tip.style.top = ( e.clientY - box.top - 10 ) + 'px';
		} );
		card.addEventListener( 'mouseleave', function () {
			tip.hidden = true;
		} );
	} );
} )();
