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

/* Contacts: drive the FunnelKit import while the page is open and show progress. */
( function () {
	var box = document.querySelector( '.cf-fk-import[data-running="1"]' );
	var A = window.checkoutflowAdmin;
	if ( ! box || ! A ) {
		return;
	}
	var status = box.querySelector( '.cf-fk-status' );
	var bar = box.querySelector( '.cf-progress span' );
	function step() {
		var fd = new FormData();
		fd.append( 'action', 'cf_fk_step' );
		fd.append( 'nonce', A.nonce );
		fetch( A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				return setTimeout( step, 5000 );
			}
			var s = r.data;
			if ( status ) {
				status.textContent = 'Importing… ' + Number( s.done ).toLocaleString() + ' of ' + Number( s.total ).toLocaleString() + ' contacts';
			}
			if ( bar && s.total ) {
				bar.style.width = Math.min( 100, Math.round( s.done / s.total * 100 ) ) + '%';
			}
			if ( s.running ) {
				setTimeout( step, 400 );
			} else {
				window.location.reload();
			}
		} ).catch( function () {
			setTimeout( step, 5000 );
		} );
	}
	step();
}() );

/* Campaigns: live sending progress. While a campaign is sending and this page is open,
   each poll also sends the next small batch (always within the per-minute limit). */
( function () {
	var A = window.checkoutflowAdmin;
	var big = document.querySelector( '.cf-send-progress' );
	var rows = Array.prototype.slice.call( document.querySelectorAll( '.cf-row-progress' ) );
	if ( ! A || ( ! big && ! rows.length ) ) {
		return;
	}
	var fmt = function ( n ) {
		return Number( n || 0 ).toLocaleString();
	};
	var eta = function ( m ) {
		if ( m <= 1 ) {
			return 'less than a minute';
		}
		var h = Math.floor( m / 60 );
		return ( h ? h + 'h ' : '' ) + ( m % 60 ) + 'm';
	};
	function text( p ) {
		if ( p.status === 'sent' ) {
			return 'Finished: ' + fmt( p.sent ) + ' sent' + ( p.failed ? ', ' + fmt( p.failed ) + ' failed' : '' ) + '.';
		}
		if ( p.status === 'cancelled' ) {
			return 'Stopped: ' + fmt( p.sent ) + ' sent, ' + fmt( p.cancelled ) + ' not sent.';
		}
		var s = 'Sent ' + fmt( p.sent ) + ' of ' + fmt( p.total ) + ( p.failed ? ' · ' + fmt( p.failed ) + ' failed' : '' ) + ' · ' + fmt( p.remaining ) + ' left';
		if ( p.status === 'paused' ) {
			return s + ' · Paused';
		}
		if ( p.remaining ) {
			s += ' · about ' + eta( p.eta_min ) + ' to go (up to ' + fmt( p.rate ) + '/min' + ( p.per_min ? ', now ' + p.per_min + '/min' : '' ) + ')';
		}
		return s;
	}
	function paint( p ) {
		if ( big && String( big.dataset.id ) === String( p.id ) ) {
			big.querySelector( '.cf-progress span' ).style.width = p.percent + '%';
			big.querySelector( '.cf-p-percent' ).textContent = p.percent + '%';
			big.querySelector( '.cf-p-text' ).textContent = text( p );
		}
		rows.forEach( function ( r ) {
			if ( String( r.dataset.id ) === String( p.id ) ) {
				r.querySelector( '.cf-progress span' ).style.width = p.percent + '%';
				r.querySelector( '.cf-p-short' ).textContent = fmt( p.done ) + ' / ' + fmt( p.total ) + ( p.status === 'paused' ? ' · paused' : '' );
			}
		} );
	}
	if ( big ) {
		var first = JSON.parse( big.querySelector( '.cf-p-data' ).textContent || '{}' );
		first.id = big.dataset.id;
		paint( first );
	}
	var ids = ( big ? [ big ] : [] ).concat( rows ).filter( function ( n ) {
		return n.dataset.status === 'sending';
	} ).map( function ( n ) {
		return n.dataset.id;
	} );
	if ( ! ids.length ) {
		return;
	}
	function poll() {
		var fd = new FormData();
		fd.append( 'action', 'cf_campaign_progress' );
		fd.append( 'nonce', A.nonce );
		fd.append( 'ids', ids.join( ',' ) );
		fd.append( 'drive', document.hidden ? '' : '1' );
		fetch( A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				return setTimeout( poll, 10000 );
			}
			var still = false;
			Object.keys( r.data ).forEach( function ( id ) {
				var p = r.data[ id ];
				p.id = id;
				paint( p );
				if ( p.status === 'sending' ) {
					still = true;
				}
			} );
			if ( still ) {
				setTimeout( poll, 3000 );
			} else {
				window.location.reload(); // finished: refresh stats
			}
		} ).catch( function () {
			setTimeout( poll, 10000 );
		} );
	}
	setTimeout( poll, 1500 );
}() );
