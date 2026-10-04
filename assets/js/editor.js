/* CheckoutFlow – full-screen drag-and-drop editor (emails and the thank-you page).
 * The canvas is rendered server-side by the same code that sends/shows the result, so what
 * you see is what customers get. Vanilla JS, no build step. */
( function () {
	'use strict';

	var B = window.checkoutflowEditor;
	var A = window.checkoutflowAdmin;
	var mount = document.getElementById( 'cf-editor' );
	if ( ! B || ! A || ! mount ) {
		return;
	}
	var L = B.i18n.labels || {};
	var T = B.i18n;
	var locked = !! B.locked;
	var design = B.design && typeof B.design === 'object' ? JSON.parse( JSON.stringify( B.design ) ) : JSON.parse( mount.dataset.design || '{}' );
	design.settings = design.settings || {};
	design.blocks = design.blocks || [];

	var sel = null;         // selected block path ("3", "2.1.0")
	var drag = null;        // { kind: 'new'|'section'|'columns'|'move', ... }
	var editing = false;    // inline text editing in the canvas
	var tab = locked ? 'design' : 'blocks';

	/* ---------- helpers ---------- */

	function el( tag, attrs, children ) {
		var e = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( k === 'text' ) {
				e.textContent = v;
			} else if ( k === 'html' ) {
				e.innerHTML = v;
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				e.addEventListener( k.slice( 2 ), v );
			} else if ( v !== false && v !== null && v !== undefined ) {
				e.setAttribute( k, v === true ? '' : v );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				e.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return e;
	}

	function clone( o ) {
		return JSON.parse( JSON.stringify( o ) );
	}

	function icon( name ) {
		return el( 'span', { class: 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );
	}

	var ICONS = {
		heading: 'heading', text: 'editor-paragraph', logo: 'store', list: 'editor-ul', button: 'button',
		image: 'format-image', divider: 'minus', spacer: 'image-flip-vertical', menu: 'menu', social: 'share',
		html: 'editor-code', footer: 'align-wide', products: 'products', coupon: 'tickets-alt', cart_items: 'cart',
		order_items: 'list-view', columns: 'columns', overview: 'grid-view', items: 'list-view', payment: 'money-alt',
		customer: 'id-alt', support: 'phone'
	};

	function typeOf( b ) {
		return B.types[ b.type ] || { label: b.type, props: {} };
	}

	/* ---------- paths ---------- */
	// Root blocks: "i". Blocks in a column: "i.c.j". Containers: "" (root) or "i.c".

	function split( path ) {
		var p = String( path ).split( '.' ).map( Number );
		return p.length === 3 ? { container: p[ 0 ] + '.' + p[ 1 ], index: p[ 2 ] } : { container: '', index: p[ 0 ] };
	}

	function listOf( container ) {
		if ( container === '' ) {
			return design.blocks;
		}
		var p = container.split( '.' ).map( Number );
		var row = design.blocks[ p[ 0 ] ];
		row.cols = row.cols || [];
		row.cols[ p[ 1 ] ] = row.cols[ p[ 1 ] ] || [];
		return row.cols[ p[ 1 ] ];
	}

	function get( path ) {
		if ( path === null || path === undefined ) {
			return null;
		}
		var s = split( path );
		var list;
		try {
			list = listOf( s.container );
		} catch ( e ) {
			return null;
		}
		return list[ s.index ] || null;
	}

	function joinPath( container, index ) {
		return container === '' ? String( index ) : container + '.' + index;
	}

	function hasColumns( blocks ) {
		return blocks.some( function ( b ) {
			return b.type === 'columns';
		} );
	}

	function newBlock( type ) {
		var b = clone( B.types[ type ].props );
		b.type = type;
		if ( type === 'columns' ) {
			b.cols = ( B.layouts[ b.layout ] || [ 50, 50 ] ).map( function () {
				return [];
			} );
		}
		return b;
	}

	/* ---------- history (undo / redo) ---------- */

	var past = [];
	var future = [];
	var snapshot = JSON.stringify( design.blocks );
	var typingTimer = null;

	function record( typing ) {
		var now = JSON.stringify( design.blocks ) + JSON.stringify( design.settings );
		if ( typing ) {
			clearTimeout( typingTimer );
			typingTimer = setTimeout( function () {
				record( false );
			}, 700 );
			return;
		}
		if ( now === snapshot ) {
			return;
		}
		past.push( snapshot );
		if ( past.length > 80 ) {
			past.shift();
		}
		future = [];
		snapshot = now;
		updateUndo();
	}

	function restore( state ) {
		var parts = splitState( state );
		design.blocks = parts.blocks;
		design.settings = parts.settings || design.settings;
		snapshot = state;
		if ( sel !== null && ! get( sel ) ) {
			sel = null;
		}
		changed( { record: false, preview: true } );
		renderSide();
		updateUndo();
	}

	function splitState( state ) {
		// state = JSON(blocks) + JSON(settings)
		var depth = 0;
		for ( var i = 0; i < state.length; i++ ) {
			var ch = state[ i ];
			if ( ch === '"' ) {
				i = skipString( state, i );
				continue;
			}
			if ( ch === '[' || ch === '{' ) {
				depth++;
			} else if ( ch === ']' || ch === '}' ) {
				depth--;
				if ( depth === 0 ) {
					return { blocks: JSON.parse( state.slice( 0, i + 1 ) ), settings: state.length > i + 1 ? JSON.parse( state.slice( i + 1 ) ) : null };
				}
			}
		}
		return { blocks: JSON.parse( state ), settings: null };
	}

	function skipString( s, i ) {
		for ( var j = i + 1; j < s.length; j++ ) {
			if ( s[ j ] === '\\' ) {
				j++;
			} else if ( s[ j ] === '"' ) {
				return j;
			}
		}
		return s.length;
	}

	snapshot = JSON.stringify( design.blocks ) + JSON.stringify( design.settings );

	function undo() {
		if ( ! past.length || locked ) {
			return;
		}
		future.push( snapshot );
		restore( past.pop() );
	}

	function redo() {
		if ( ! future.length || locked ) {
			return;
		}
		past.push( snapshot );
		restore( future.pop() );
	}

	/* ---------- layout ---------- */

	document.body.classList.add( 'cfx-open' );
	var root = el( 'div', { class: 'cfx' + ( locked ? ' is-locked' : '' ) } );
	var top = el( 'header', { class: 'cfx-top' } );
	var side = el( 'aside', { class: 'cfx-side' } );
	var stage = el( 'main', { class: 'cfx-stage' } );
	var frame = el( 'iframe', { class: 'cfx-frame', title: T.preview || 'Preview' } );
	var frameWrap = el( 'div', { class: 'cfx-frame-wrap' }, [ frame ] );
	stage.appendChild( frameWrap );
	root.appendChild( top );
	root.appendChild( el( 'div', { class: 'cfx-main' }, [ side, stage ] ) );
	mount.appendChild( root );

	// Top bar.
	var back = el( 'a', { class: 'cfx-back', href: B.backUrl || '#', title: T.back || 'Back', 'aria-label': T.back || 'Back' }, [ icon( 'arrow-left-alt' ) ] );
	var titleBox = el( 'div', { class: 'cfx-title' } );
	var subject = null;
	var preheader = null;
	if ( B.mode === 'email' ) {
		subject = el( 'input', { type: 'text', class: 'cfx-subject', value: B.subject || '', placeholder: T.subject || 'Add a subject', 'aria-label': T.subject || 'Subject', disabled: locked } );
		preheader = el( 'input', { type: 'text', class: 'cfx-preheader', value: B.preheader || '', placeholder: T.preheader || 'Add preview text', 'aria-label': T.preheader || 'Preview text', disabled: locked } );
		[ subject, preheader ].forEach( function ( i ) {
			i.addEventListener( 'input', function () {
				changed( { record: false, preview: i === preheader } );
			} );
		} );
		titleBox.appendChild( subject );
		titleBox.appendChild( preheader );
	} else {
		titleBox.appendChild( el( 'strong', { class: 'cfx-page-title', text: B.title || '' } ) );
	}

	var status = el( 'span', { class: 'cfx-status', 'aria-live': 'polite' } );
	var undoBtn = el( 'button', { type: 'button', class: 'cfx-icon-btn', title: T.undo || 'Undo', 'aria-label': T.undo || 'Undo', onclick: undo }, [ icon( 'undo' ) ] );
	var redoBtn = el( 'button', { type: 'button', class: 'cfx-icon-btn', title: T.redo || 'Redo', 'aria-label': T.redo || 'Redo', onclick: redo }, [ icon( 'redo' ) ] );
	var devices = el( 'div', { class: 'cfx-devices', role: 'group' } );
	[ [ 'desktop', 'desktop' ], [ 'mobile', 'smartphone' ] ].forEach( function ( d, i ) {
		devices.appendChild( el( 'button', {
			type: 'button',
			class: 'cfx-icon-btn' + ( i === 0 ? ' is-active' : '' ),
			title: d[ 0 ] === 'desktop' ? ( T.desktop || 'Desktop' ) : ( T.mobile || 'Mobile' ),
			'aria-label': d[ 0 ] === 'desktop' ? ( T.desktop || 'Desktop' ) : ( T.mobile || 'Mobile' ),
			onclick: function ( e ) {
				devices.querySelectorAll( 'button' ).forEach( function ( b ) {
					b.classList.remove( 'is-active' );
				} );
				e.currentTarget.classList.add( 'is-active' );
				frameWrap.classList.toggle( 'is-mobile', d[ 0 ] === 'mobile' );
				positionToolbar();
			}
		}, [ icon( d[ 1 ] ) ] ) );
	} );

	var actions = el( 'div', { class: 'cfx-actions' } );
	if ( ! locked && B.mode === 'email' && B.ai ) {
		actions.appendChild( el( 'button', { type: 'button', class: 'button cfx-ai-btn', onclick: openAI }, [ icon( 'lightbulb' ), el( 'span', { text: T.aiWrite || 'Write with AI' } ) ] ) );
	}
	if ( ! locked && B.templates && B.templates.length ) {
		actions.appendChild( el( 'button', { type: 'button', class: 'button', text: T.templates || 'Templates', onclick: openTemplates } ) );
	}
	if ( B.mode === 'email' ) {
		actions.appendChild( el( 'button', { type: 'button', class: 'button', text: T.sendTest || 'Send test', onclick: toggleTest } ) );
	}
	var toggle = null;
	if ( B.toggle && ! locked ) {
		toggle = el( 'input', { type: 'checkbox' } );
		toggle.checked = !! B.toggle.checked;
		toggle.addEventListener( 'change', function () {
			changed( { record: false, preview: false } );
		} );
		actions.appendChild( el( 'label', { class: 'cfx-toggle' }, [ toggle, el( 'span', { text: B.toggle.label } ) ] ) );
	}
	if ( B.viewUrl ) {
		actions.appendChild( el( 'a', { class: 'button', href: B.viewUrl, target: '_blank', rel: 'noopener', text: T.view || 'View' } ) );
	}
	if ( ! locked ) {
		actions.appendChild( el( 'button', { type: 'button', class: 'button button-primary', text: T.save || 'Save', onclick: function () {
			save( true );
		} } ) );
	}

	top.appendChild( back );
	top.appendChild( titleBox );
	top.appendChild( status );
	top.appendChild( el( 'div', { class: 'cfx-spacer' } ) );
	if ( ! locked ) {
		top.appendChild( undoBtn );
		top.appendChild( redoBtn );
	}
	top.appendChild( devices );
	top.appendChild( actions );

	function updateUndo() {
		undoBtn.disabled = ! past.length;
		redoBtn.disabled = ! future.length;
	}
	updateUndo();

	/* ---------- send test ---------- */

	var testBox = null;
	function toggleTest() {
		if ( testBox ) {
			testBox.remove();
			testBox = null;
			return;
		}
		var to = el( 'input', { type: 'email', value: B.testTo || '', 'aria-label': T.sendTo || 'Send to' } );
		var out = el( 'p', { class: 'cfx-test-out', role: 'status' } );
		var btn = el( 'button', { type: 'button', class: 'button button-primary', text: T.send || 'Send' } );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			out.textContent = '…';
			post( 'cf_send_test', { to: to.value, subject: subject ? subject.value : '', preheader: preheader ? preheader.value : '', design: JSON.stringify( design ) } )
				.then( function ( res ) {
					out.textContent = res.data && res.data.message ? res.data.message : '';
					out.className = 'cfx-test-out ' + ( res.success ? 'is-ok' : 'is-error' );
				} )
				.catch( function () {
					out.textContent = T.failed || 'Request failed';
					out.className = 'cfx-test-out is-error';
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
		testBox = el( 'div', { class: 'cfx-popover' }, [ el( 'label', { text: T.sendTo || 'Send a test to' } ), el( 'div', { class: 'cfx-row' }, [ to, btn ] ), out, el( 'p', { class: 'description', text: T.testNote || '' } ) ] );
		top.appendChild( testBox );
		to.focus();
	}

	/* ---------- templates ---------- */

	function openTemplates() {
		var modal = el( 'div', { class: 'cfx-modal', role: 'dialog', 'aria-modal': 'true' } );
		var close = function () {
			modal.remove();
		};
		var grid = el( 'div', { class: 'cfx-templates' } );
		B.templates.forEach( function ( t ) {
			grid.appendChild( el( 'button', {
				type: 'button',
				class: 'cfx-template',
				onclick: function () {
					if ( design.blocks.length && ! window.confirm( T.replaceConfirm || 'Replace the current content with this template?' ) ) {
						return;
					}
					design.blocks = clone( t.blocks );
					sel = null;
					close();
					changed( { record: true, preview: true } );
					renderSide();
				}
			}, [ el( 'strong', { text: t.label } ), el( 'span', { text: t.desc || '' } ) ] ) );
		} );
		modal.appendChild( el( 'div', { class: 'cfx-modal-box' }, [
			el( 'div', { class: 'cfx-modal-head' }, [ el( 'h2', { text: T.templates || 'Templates' } ), el( 'button', { type: 'button', class: 'cfx-icon-btn', 'aria-label': T.close || 'Close', onclick: close }, [ icon( 'no-alt' ) ] ) ] ),
			grid
		] ) );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				close();
			}
		} );
		root.appendChild( modal );
	}

	/* ---------- AI writer ---------- */

	var aiBrief = '';
	function openAI() {
		var modal = el( 'div', { class: 'cfx-modal', role: 'dialog', 'aria-modal': 'true' } );
		var busy = false;
		var close = function () {
			if ( ! busy ) {
				modal.remove();
			}
		};
		var head = el( 'div', { class: 'cfx-modal-head' }, [ el( 'h2', { text: T.aiWrite || 'Write with AI' } ), el( 'button', { type: 'button', class: 'cfx-icon-btn', 'aria-label': T.close || 'Close', onclick: close }, [ icon( 'no-alt' ) ] ) ] );
		var box = el( 'div', { class: 'cfx-modal-box cfx-ai' }, [ head ] );
		modal.appendChild( box );

		if ( ! B.ai.enabled ) {
			box.appendChild( el( 'p', { text: T.aiNoKey || 'To use the AI writer, add your Anthropic API key in Settings → Email & SMTP.' } ) );
			box.appendChild( el( 'p', {}, [ el( 'a', { class: 'button button-primary', href: B.ai.settingsUrl, target: '_blank', rel: 'noopener', text: T.aiOpenSettings || 'Open settings' } ) ] ) );
		} else {
			var brief = el( 'textarea', { class: 'cfx-ai-brief', rows: '5', placeholder: T.aiPlaceholder || 'Describe the email: the occasion, the offer, products to feature, tone… e.g. "Weekend flash sale, 15% off everything with a coupon, feature our 3 best sellers, mention bulk pricing"' } );
			brief.value = aiBrief;
			var ideas = el( 'div', { class: 'cfx-ai-ideas' } );
			( T.aiIdeas || [ 'Flash sale: 15% off sitewide for 48 hours, with a coupon', 'New arrivals: feature our 3 newest products', 'Win back customers who haven\'t ordered in 60 days', 'Monthly newsletter with best sellers and a bulk pricing reminder' ] ).forEach( function ( idea ) {
				ideas.appendChild( el( 'button', { type: 'button', class: 'cfx-ai-idea', text: idea, onclick: function () {
					brief.value = idea;
					brief.focus();
				} } ) );
			} );
			var modeName = 'cfx-ai-mode';
			var modes = el( 'div', { class: 'cfx-ai-modes' } );
			[ [ 'replace', T.aiReplace || 'Write a new email (replaces current content)' ], [ 'append', T.aiAppend || 'Add a section to this email' ] ].forEach( function ( m, i ) {
				var r = el( 'input', { type: 'radio', name: modeName, value: m[ 0 ] } );
				r.checked = design.blocks.length ? i === 1 : i === 0;
				modes.appendChild( el( 'label', {}, [ r, el( 'span', { text: m[ 1 ] } ) ] ) );
			} );
			var msg = el( 'p', { class: 'cfx-ai-msg', 'aria-live': 'polite' } );
			var go = el( 'button', { type: 'button', class: 'button button-primary cfx-ai-go', text: T.aiGenerate || 'Generate' } );
			go.addEventListener( 'click', function () {
				var text = brief.value.trim();
				if ( ! text ) {
					brief.focus();
					return;
				}
				var mode = modes.querySelector( 'input:checked' ).value;
				aiBrief = text;
				busy = true;
				go.disabled = true;
				box.classList.add( 'is-busy' );
				msg.className = 'cfx-ai-msg';
				msg.textContent = T.aiWorking || 'Writing your email… this usually takes 20–60 seconds.';
				var fail = function ( m ) {
					busy = false;
					go.disabled = false;
					box.classList.remove( 'is-busy' );
					msg.className = 'cfx-ai-msg is-error';
					msg.textContent = m || T.aiFailed || 'Something went wrong. Please try again.';
				};
				var started = Date.now();
				var ranHere = false;
				var poll = function ( job ) {
					var data = { job: job };
					// The background request never picked it up (host blocks loopbacks): run it in this request.
					if ( ! ranHere && Date.now() - started > 12000 ) {
						ranHere = true;
						data.run = '1';
					}
					aiPost( 'cf_ai_status', data ).then( function ( r ) {
						if ( ! r.success ) {
							return fail( r.data && r.data.message );
						}
						if ( r.data.status !== 'done' ) {
							if ( Date.now() - started > 240000 ) {
								return fail( T.aiTimeout || 'The AI took too long to answer. Please try again.' );
							}
							return setTimeout( function () {
								poll( job );
							}, 3000 );
						}
						busy = false;
						go.disabled = false;
						box.classList.remove( 'is-busy' );
						applyAI( r.data.email, mode );
						aiBrief = '';
						close();
					} ).catch( function ( e ) {
						fail( e && e.message );
					} );
				};
				aiPost( B.ai.action, {
					brief: text,
					mode: mode,
					subject: subject ? subject.value : '',
					design: JSON.stringify( design )
				} ).then( function ( r ) {
					if ( ! r.success ) {
						return fail( r.data && r.data.message );
					}
					poll( r.data.job );
				} ).catch( function ( e ) {
					fail( e && e.message );
				} );
			} );
			brief.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' && ( e.metaKey || e.ctrlKey ) ) {
					go.click();
				}
			} );
			box.appendChild( brief );
			box.appendChild( ideas );
			box.appendChild( modes );
			box.appendChild( el( 'div', { class: 'cfx-ai-foot' }, [ msg, go ] ) );
			box.appendChild( el( 'p', { class: 'cfx-tip', text: T.aiNote || 'AI writes a draft; review the copy, links and products before sending. Undo restores your previous email.' } ) );
			setTimeout( function () {
				brief.focus();
			}, 0 );
		}
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				close();
			}
		} );
		root.appendChild( modal );
	}

	// Like post(), but turns a non-JSON reply (a host's timeout or error page) into a readable error.
	function aiPost( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', A.nonce );
		Object.keys( data ).forEach( function ( k ) {
			fd.append( k, data[ k ] );
		} );
		return fetch( A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) {
			return r.text().then( function ( body ) {
				var json;
				try {
					json = JSON.parse( body );
				} catch ( e ) {
					json = null;
				}
				if ( json && typeof json === 'object' ) {
					return json;
				}
				throw new Error( ( T.aiServer || 'The server returned an error' ) + ' (HTTP ' + r.status + ( r.status === 504 || r.status === 524 ? ', timed out' : '' ) + '). ' + ( body === '0' || body === '-1' ? 'Please reload the page and try again.' : '' ) );
			} );
		} );
	}

	function applyAI( out, mode ) {
		var blocks = Array.isArray( out.blocks ) ? out.blocks : [];
		if ( mode === 'append' ) {
			if ( blocks.length ) {
				insertBlocks( blocks );
			}
		} else {
			design.blocks = clone( blocks );
			sel = null;
			changed( { record: true, preview: true } );
			renderSide();
		}
		if ( subject && out.subject && ( mode === 'replace' || ! subject.value ) ) {
			subject.value = out.subject;
		}
		if ( preheader && out.preheader && ( mode === 'replace' || ! preheader.value ) ) {
			preheader.value = out.preheader;
		}
		changed( { record: false, preview: true } );
	}

	/* ---------- side panel ---------- */

	function renderSide() {
		side.innerHTML = '';
		var b = get( sel );
		if ( b ) {
			side.appendChild( settingsPanel( b, sel ) );
			return;
		}
		sel = null;
		var tabs = el( 'div', { class: 'cfx-tabs', role: 'tablist' } );
		var list = locked ? [ [ 'design', T.design || 'Design' ] ] : [ [ 'blocks', T.blocks || 'Blocks' ], [ 'structure', T.structure || 'Structure' ], [ 'layouts', T.layouts || 'Layouts' ], [ 'design', T.design || 'Design' ] ];
		list.forEach( function ( t ) {
			tabs.appendChild( el( 'button', {
				type: 'button',
				role: 'tab',
				class: 'cfx-tab' + ( tab === t[ 0 ] ? ' is-active' : '' ),
				'aria-selected': tab === t[ 0 ] ? 'true' : 'false',
				text: t[ 1 ],
				onclick: function () {
					tab = t[ 0 ];
					renderSide();
				}
			} ) );
		} );
		side.appendChild( tabs );
		var body = el( 'div', { class: 'cfx-side-body' } );
		side.appendChild( body );
		if ( tab === 'blocks' ) {
			blocksTab( body );
		} else if ( tab === 'structure' ) {
			structureTab( body );
		} else if ( tab === 'layouts' ) {
			layoutsTab( body );
		} else {
			designTab( body );
		}
	}

	function paletteItem( label, iconName, onInsert, dragData ) {
		var item = el( 'button', {
			type: 'button',
			class: 'cfx-tile',
			draggable: 'true',
			title: label,
			onclick: onInsert
		}, [ icon( iconName ), el( 'span', { text: label } ) ] );
		item.addEventListener( 'dragstart', function ( e ) {
			drag = dragData;
			e.dataTransfer.effectAllowed = 'copy';
			e.dataTransfer.setData( 'text/plain', label );
		} );
		item.addEventListener( 'dragend', function () {
			drag = null;
			clearCanvasDrop();
		} );
		return item;
	}

	// Where a click-to-add goes: after the selected block (same container), else at the end.
	function insertTarget( blocksToAdd ) {
		if ( sel !== null ) {
			var s = split( sel );
			if ( s.container !== '' && hasColumns( blocksToAdd ) ) {
				return { container: '', index: Number( s.container.split( '.' )[ 0 ] ) + 1 };
			}
			return { container: s.container, index: s.index + 1 };
		}
		return { container: '', index: design.blocks.length };
	}

	function insertBlocks( blocksToAdd, target ) {
		target = target || insertTarget( blocksToAdd );
		if ( target.container !== '' && hasColumns( blocksToAdd ) ) {
			target = { container: '', index: Number( target.container.split( '.' )[ 0 ] ) + 1 };
		}
		var list = listOf( target.container );
		Array.prototype.splice.apply( list, [ target.index, 0 ].concat( clone( blocksToAdd ) ) );
		sel = joinPath( target.container, target.index );
		changed( { record: true, preview: true } );
		renderSide();
	}

	function blocksTab( body ) {
		var groups = B.groups || { general: T.general || 'General' };
		Object.keys( groups ).forEach( function ( g ) {
			var types = Object.keys( B.types ).filter( function ( t ) {
				return ( B.types[ t ].group || 'general' ) === g && t !== 'columns' && ( t !== 'html' || B.canHtml );
			} );
			if ( ! types.length ) {
				return;
			}
			body.appendChild( el( 'h3', { class: 'cfx-group', text: groups[ g ] } ) );
			var grid = el( 'div', { class: 'cfx-tiles' } );
			types.forEach( function ( t ) {
				grid.appendChild( paletteItem( B.types[ t ].label, ICONS[ t ] || 'marker', function () {
					insertBlocks( [ newBlock( t ) ] );
				}, { kind: 'new', blocks: [ newBlock( t ) ] } ) );
			} );
			body.appendChild( grid );
		} );
		body.appendChild( el( 'p', { class: 'cfx-tip', text: T.dragTip || '' } ) );
	}

	function layoutPreview( widths ) {
		return el( 'span', { class: 'cfx-layout-preview' }, widths.map( function ( w ) {
			return el( 'i', { style: 'flex:' + w } );
		} ) );
	}

	function structureTab( body ) {
		body.appendChild( el( 'p', { class: 'cfx-tip', text: T.structureTip || '' } ) );
		var grid = el( 'div', { class: 'cfx-structures' } );
		Object.keys( B.layouts ).forEach( function ( key ) {
			var row = newBlock( 'columns' );
			row.layout = key;
			row.cols = B.layouts[ key ].map( function () {
				return [];
			} );
			var item = paletteItem( B.layoutLabels && B.layoutLabels[ key ] ? B.layoutLabels[ key ] : key.replace( /-/g, ' / ' ), 'columns', function () {
				insertBlocks( [ row ] );
			}, { kind: 'new', blocks: [ row ] } );
			item.classList.add( 'cfx-structure' );
			item.replaceChild( layoutPreview( B.layouts[ key ] ), item.firstChild );
			grid.appendChild( item );
		} );
		body.appendChild( grid );
	}

	function layoutsTab( body ) {
		body.appendChild( el( 'p', { class: 'cfx-tip', text: T.layoutsTip || '' } ) );
		( B.sections || [] ).forEach( function ( sec ) {
			var item = paletteItem( sec.label, 'layout', function () {
				insertBlocks( sec.blocks );
			}, { kind: 'new', blocks: sec.blocks } );
			item.classList.add( 'cfx-section-tile' );
			body.appendChild( item );
		} );
	}

	function designTab( body ) {
		( B.globals || [] ).forEach( function ( g ) {
			var k = g[ 0 ];
			var input;
			if ( g[ 1 ] === 'color' ) {
				body.appendChild( colorField( L[ k ] || k, design.settings[ k ] || '', function ( v ) {
					design.settings[ k ] = v;
					changed( { record: true, preview: true } );
				}, false ) );
				return;
			}
			if ( g[ 1 ] === 'font' ) {
				input = el( 'select', { disabled: locked }, ( B.fonts || [] ).map( function ( f ) {
					return el( 'option', { value: f, text: f.split( ',' )[ 0 ].replace( /"/g, '' ), selected: design.settings.font === f } );
				} ) );
			} else if ( g[ 1 ] === 'number' ) {
				input = el( 'input', { type: 'number', value: design.settings[ k ], disabled: locked } );
			} else {
				input = el( 'input', { type: 'text', value: design.settings[ k ] || '', disabled: locked } );
			}
			input.addEventListener( 'change', function () {
				design.settings[ k ] = g[ 1 ] === 'number' ? parseInt( input.value, 10 ) || 0 : input.value;
				changed( { record: true, preview: true } );
			} );
			var f = field( L[ k ] || k, input );
			if ( g[ 1 ] === 'image' ) {
				var mb = mediaButton( input );
				if ( mb ) {
					f.appendChild( mb );
				}
			}
			body.appendChild( f );
		} );
		if ( B.mergeTags ) {
			var det = el( 'details', { class: 'cfx-tagref' }, [ el( 'summary', { text: T.mergeTags || 'Merge tags' } ) ] );
			var ul = el( 'ul' );
			Object.keys( B.mergeTags ).forEach( function ( k ) {
				ul.appendChild( el( 'li', {}, [ el( 'code', { text: '{' + k + '}' } ), ' ' + B.mergeTags[ k ] ] ) );
			} );
			det.appendChild( ul );
			body.appendChild( det );
		}
	}

	/* ---------- block settings ---------- */

	function field( label, input, cls ) {
		return el( 'label', { class: 'cfx-field' + ( cls ? ' ' + cls : '' ) }, [ el( 'span', { text: label } ), input ] );
	}

	function colorField( label, value, onChange, allowEmpty ) {
		var text = el( 'input', { type: 'text', class: 'cfx-color-text', value: value || '', placeholder: allowEmpty ? ( T.colorDefault || 'Default' ) : '', disabled: locked } );
		var pick = el( 'input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test( value ) ? value : '#ffffff', disabled: locked } );
		pick.addEventListener( 'input', function () {
			text.value = pick.value;
			onChange( pick.value );
		} );
		text.addEventListener( 'change', function () {
			var v = text.value.trim();
			if ( v && ! /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test( v ) ) {
				text.value = value || '';
				return;
			}
			if ( /^#[0-9a-f]{6}$/i.test( v ) ) {
				pick.value = v;
			}
			onChange( v );
		} );
		return el( 'div', { class: 'cfx-field' }, [ el( 'span', { text: label } ), el( 'div', { class: 'cfx-color' }, [ pick, text ] ) ] );
	}

	function mediaButton( input ) {
		if ( ! window.wp || ! window.wp.media || locked ) {
			return null;
		}
		return el( 'button', {
			type: 'button',
			class: 'button',
			text: T.chooseImage || 'Choose image',
			onclick: function () {
				var m = window.wp.media( { library: { type: 'image' }, multiple: false } );
				m.on( 'select', function () {
					input.value = m.state().get( 'selection' ).first().toJSON().url;
					input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
				m.open();
			}
		} );
	}

	var LONG = [ 'items', 'links' ];
	var URLISH = [ 'facebook', 'instagram', 'x', 'youtube', 'tiktok', 'linkedin' ];

	function settingsPanel( b, path ) {
		var t = typeOf( b );
		var wrap = el( 'div', { class: 'cfx-settings' } );
		var head = el( 'div', { class: 'cfx-settings-head' }, [
			el( 'button', { type: 'button', class: 'cfx-icon-btn', title: T.back || 'Back', 'aria-label': T.back || 'Back', onclick: function () {
				select( null );
			} }, [ icon( 'arrow-left-alt2' ) ] ),
			icon( ICONS[ b.type ] || 'marker' ),
			el( 'strong', { text: t.label } )
		] );
		wrap.appendChild( head );
		var body = el( 'div', { class: 'cfx-side-body' } );
		wrap.appendChild( body );

		if ( t.dynamic ) {
			body.appendChild( el( 'p', { class: 'cfx-tip', text: T.dynamic || '' } ) );
		}

		var opts = t.options || {};
		Object.keys( t.props ).forEach( function ( key ) {
			if ( key === 'cols' ) {
				return;
			}
			var def = t.props[ key ];
			var v = b[ key ];
			var input;
			var update = function ( val, preview ) {
				b[ key ] = val;
				changed( { record: true, preview: preview !== false, typing: true } );
			};

			if ( key === 'layout' && b.type === 'columns' ) {
				var lg = el( 'div', { class: 'cfx-structures is-compact' } );
				Object.keys( B.layouts ).forEach( function ( lk ) {
					lg.appendChild( el( 'button', {
						type: 'button',
						class: 'cfx-tile cfx-structure' + ( b.layout === lk ? ' is-active' : '' ),
						disabled: locked,
						title: lk,
						onclick: function () {
							setLayout( b, lk );
							changed( { record: true, preview: true } );
							renderSide();
						}
					}, [ layoutPreview( B.layouts[ lk ] ) ] ) );
				} );
				body.appendChild( el( 'div', { class: 'cfx-field' }, [ el( 'span', { text: L.layout || 'Columns' } ), lg ] ) );
				return;
			}
			if ( key === 'html' && ( b.type === 'text' || b.type === 'footer' ) ) {
				body.appendChild( richText( b, key ) );
				return;
			}
			if ( key === 'color' ) {
				body.appendChild( colorField( L.color || 'Color', v, function ( val ) {
					update( val );
				}, true ) );
				return;
			}
			var o = key === 'align' ? ( B.align || { left: 'Left', center: 'Center', right: 'Right' } ) : opts[ key ];
			if ( o ) {
				input = el( 'select', { disabled: locked }, Object.keys( o ).map( function ( ok ) {
					return el( 'option', { value: ok, text: o[ ok ], selected: v === ok } );
				} ) );
				input.addEventListener( 'change', function () {
					update( input.value );
				} );
			} else if ( typeof def === 'boolean' ) {
				input = el( 'input', { type: 'checkbox', disabled: locked } );
				input.checked = !! v;
				input.addEventListener( 'change', function () {
					update( input.checked );
				} );
				body.appendChild( el( 'label', { class: 'cfx-field cfx-check' }, [ input, el( 'span', { text: L[ key ] || key } ) ] ) );
				return;
			} else if ( typeof def === 'number' ) {
				input = el( 'input', { type: 'number', value: v, disabled: locked } );
				input.addEventListener( 'input', function () {
					update( input.value === '' ? 0 : parseFloat( input.value ) );
				} );
			} else if ( LONG.indexOf( key ) !== -1 || key === 'html' ) {
				input = el( 'textarea', { rows: key === 'html' ? 8 : 5, class: key === 'html' ? 'code' : '', disabled: locked } );
				input.value = v || '';
				input.addEventListener( 'input', function () {
					update( input.value );
				} );
			} else {
				input = el( 'input', { type: URLISH.indexOf( key ) !== -1 ? 'url' : 'text', value: v || '', disabled: locked, placeholder: URLISH.indexOf( key ) !== -1 ? 'https://' : '' } );
				input.addEventListener( 'input', function () {
					update( input.value );
				} );
			}
			input.dataset.key = key;
			var f = field( L[ key ] || key, input );
			if ( key === 'src' ) {
				var mb = mediaButton( input );
				if ( mb ) {
					f.appendChild( mb );
				}
			}
			if ( L[ key + '_help' ] ) {
				f.appendChild( el( 'small', { class: 'cfx-help', text: L[ key + '_help' ] } ) );
			}
			body.appendChild( f );
		} );

		// Spacing & background (every block).
		var st = el( 'details', { class: 'cfx-style', open: !! ( b._bg || b._pt !== undefined || b._pb !== undefined ) } );
		st.appendChild( el( 'summary', { text: T.blockStyle || 'Spacing & background' } ) );
		st.appendChild( colorField( L._bg || 'Background', b._bg || '', function ( val ) {
			if ( val ) {
				b._bg = val;
			} else {
				delete b._bg;
			}
			changed( { record: true, preview: true } );
		}, true ) );
		[ '_pt', '_pb' ].forEach( function ( k ) {
			var n = el( 'input', { type: 'number', min: 0, max: 120, value: b[ k ] !== undefined ? b[ k ] : '', placeholder: '0', disabled: locked } );
			n.addEventListener( 'input', function () {
				if ( n.value === '' ) {
					delete b[ k ];
				} else {
					b[ k ] = Math.max( 0, Math.min( 120, parseInt( n.value, 10 ) || 0 ) );
				}
				changed( { record: true, preview: true, typing: true } );
			} );
			st.appendChild( field( L[ k ] || k, n ) );
		} );
		body.appendChild( st );

		if ( ! locked ) {
			var acts = el( 'div', { class: 'cfx-block-actions' } );
			acts.appendChild( el( 'button', { type: 'button', class: 'button', text: T.duplicate || 'Duplicate', onclick: function () {
				duplicate( path );
			} } ) );
			acts.appendChild( el( 'button', { type: 'button', class: 'button cfx-danger', text: T.remove || 'Delete', onclick: function () {
				removeAt( path );
			} } ) );
			body.appendChild( acts );
		}
		return wrap;
	}

	function setLayout( b, lk ) {
		var n = B.layouts[ lk ].length;
		var cols = b.cols || [];
		// Fewer columns: move the extra columns' blocks into the last kept column.
		while ( cols.length > n ) {
			var extra = cols.pop();
			cols[ cols.length - 1 ] = ( cols[ cols.length - 1 ] || [] ).concat( extra );
		}
		while ( cols.length < n ) {
			cols.push( [] );
		}
		b.cols = cols;
		b.layout = lk;
	}

	function richText( b, key ) {
		var area = el( 'div', { class: 'cfx-rte', contenteditable: locked ? 'false' : 'true', html: b[ key ] || '' } );
		area.dataset.key = key;
		var sync = function () {
			b[ key ] = cleanHtml( area.innerHTML );
			changed( { record: true, preview: true, typing: true } );
		};
		area.addEventListener( 'input', sync );
		area.addEventListener( 'paste', function ( e ) {
			e.preventDefault();
			document.execCommand( 'insertText', false, ( e.clipboardData || window.clipboardData ).getData( 'text/plain' ) );
		} );
		var tb = el( 'div', { class: 'cfx-toolbar' } );
		[ [ 'editor-bold', 'bold' ], [ 'editor-italic', 'italic' ], [ 'editor-underline', 'underline' ], [ 'editor-ul', 'insertUnorderedList' ], [ 'admin-links', 'createLink' ], [ 'editor-removeformatting', 'removeFormat' ] ].forEach( function ( t ) {
			tb.appendChild( el( 'button', {
				type: 'button',
				class: 'cfx-icon-btn',
				title: t[ 1 ],
				onmousedown: function ( e ) {
					e.preventDefault();
				},
				onclick: function () {
					if ( t[ 1 ] === 'createLink' ) {
						var url = window.prompt( T.linkPrompt || 'Link URL', 'https://' );
						if ( url ) {
							document.execCommand( 'createLink', false, url );
						}
					} else {
						document.execCommand( t[ 1 ], false, null );
					}
					sync();
				}
			}, [ icon( t[ 0 ] ) ] ) );
		} );
		var tags = el( 'select', { class: 'cfx-tag-insert', 'aria-label': T.insertTag || 'Insert tag' }, [ el( 'option', { value: '', text: T.insertTag || '{ } Insert tag' } ) ] );
		Object.keys( B.mergeTags || {} ).forEach( function ( k ) {
			tags.appendChild( el( 'option', { value: k, text: '{' + k + '}' } ) );
		} );
		tags.addEventListener( 'change', function () {
			if ( tags.value ) {
				area.focus();
				document.execCommand( 'insertText', false, '{' + tags.value + '}' );
				tags.value = '';
				sync();
			}
		} );
		tb.appendChild( tags );
		return el( 'div', { class: 'cfx-field' }, [ el( 'span', { text: L[ key ] || 'Content' } ), locked ? null : tb, area ] );
	}

	// Inline editing produces browser markup; keep it simple and predictable.
	function cleanHtml( html ) {
		var d = document.createElement( 'div' );
		d.innerHTML = html;
		d.querySelectorAll( '[style]' ).forEach( function ( n ) {
			n.removeAttribute( 'style' );
		} );
		d.querySelectorAll( 'div' ).forEach( function ( n ) {
			var p = document.createElement( 'p' );
			while ( n.firstChild ) {
				p.appendChild( n.firstChild );
			}
			n.parentNode.replaceChild( p, n );
		} );
		return d.innerHTML.replace( /<p><\/p>/g, '' );
	}

	/* ---------- block operations ---------- */

	function select( path ) {
		sel = path === null || path === undefined ? null : String( path );
		renderSide();
		highlight();
	}

	function removeAt( path ) {
		var s = split( path );
		listOf( s.container ).splice( s.index, 1 );
		sel = null;
		changed( { record: true, preview: true } );
		renderSide();
	}

	function duplicate( path ) {
		var s = split( path );
		var list = listOf( s.container );
		list.splice( s.index + 1, 0, clone( list[ s.index ] ) );
		sel = joinPath( s.container, s.index + 1 );
		changed( { record: true, preview: true } );
		renderSide();
	}

	function moveBy( path, delta ) {
		var s = split( path );
		var list = listOf( s.container );
		var to = s.index + delta;
		if ( to < 0 || to >= list.length ) {
			return;
		}
		var b = list.splice( s.index, 1 )[ 0 ];
		list.splice( to, 0, b );
		sel = joinPath( s.container, to );
		changed( { record: true, preview: true } );
		renderSide();
	}

	// Move a block to container/index (index counted before removal).
	function moveTo( from, target ) {
		var b = get( from );
		if ( ! b ) {
			return;
		}
		if ( target.container !== '' && b.type === 'columns' ) {
			target = { container: '', index: Number( target.container.split( '.' )[ 0 ] ) + 1 };
		}
		var s = split( from );
		// Dropping a row into one of its own columns is meaningless.
		if ( target.container !== '' && s.container === '' && Number( target.container.split( '.' )[ 0 ] ) === s.index ) {
			return;
		}
		var srcList = listOf( s.container );
		var dstList = listOf( target.container );
		var index = target.index;
		if ( srcList === dstList && index > s.index ) {
			index--;
		}
		// Removing a root block before a column container shifts that container's row index.
		var container = target.container;
		if ( s.container === '' && container !== '' ) {
			var row = Number( container.split( '.' )[ 0 ] );
			if ( row > s.index ) {
				container = ( row - 1 ) + '.' + container.split( '.' )[ 1 ];
			}
		}
		srcList.splice( s.index, 1 );
		dstList = listOf( container );
		if ( srcList === dstList && index === s.index && s.container === container ) {
			srcList.splice( s.index, 0, b );
			return;
		}
		dstList.splice( index, 0, b );
		// The source column row's index can shift when moving out of a column above it.
		sel = joinPath( container, index );
		changed( { record: true, preview: true } );
		renderSide();
	}

	/* ---------- change tracking, preview, autosave ---------- */

	var previewTimer = null;
	var saveTimer = null;
	var dirty = false;
	var savedAt = null;

	function changed( o ) {
		o = o || {};
		if ( o.record !== false ) {
			record( !! o.typing );
		}
		if ( o.preview !== false && ! editing ) {
			schedulePreview();
		}
		if ( ! locked ) {
			dirty = true;
			setStatus( T.unsaved || 'Unsaved changes' );
			clearTimeout( saveTimer );
			saveTimer = setTimeout( function () {
				save( false );
			}, 2500 );
		}
	}

	function setStatus( text ) {
		status.textContent = text;
	}

	function ago() {
		if ( ! savedAt || dirty ) {
			return;
		}
		var s = Math.round( ( Date.now() - savedAt ) / 1000 );
		var txt = s < 10 ? ( T.savedNow || 'Saved just now' ) : s < 60 ? ( T.savedSecs || 'Last saved %d secs ago' ).replace( '%d', s ) : ( T.savedMins || 'Last saved %d min ago' ).replace( '%d', Math.round( s / 60 ) );
		setStatus( txt );
	}
	setInterval( ago, 10000 );

	function post( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', A.nonce );
		Object.keys( data ).forEach( function ( k ) {
			fd.append( k, data[ k ] );
		} );
		return fetch( A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	var saving = null;
	function save( manual ) {
		if ( locked || ! B.saveAction ) {
			return Promise.resolve();
		}
		clearTimeout( saveTimer );
		if ( saving ) {
			return saving.then( function () {
				return dirty ? save( manual ) : null;
			} );
		}
		dirty = false;
		setStatus( T.saving || 'Saving…' );
		var data = Object.assign( {}, B.saveData || {}, { design: JSON.stringify( design ) } );
		if ( toggle ) {
			data[ B.toggle.name ] = toggle.checked ? '1' : '';
		}
		if ( subject ) {
			data.subject = subject.value;
			data.preheader = preheader.value;
		}
		saving = post( B.saveAction, data ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				throw new Error( res && res.data && res.data.message ? res.data.message : 'save' );
			}
			savedAt = Date.now();
			if ( ! dirty ) {
				ago();
			}
		} ).catch( function ( e ) {
			dirty = true;
			setStatus( ( T.saveFailed || 'Not saved' ) + ( e && e.message && e.message !== 'save' ? ': ' + e.message : '' ) );
		} ).finally( function () {
			saving = null;
		} );
		return saving;
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty && ! locked ) {
			save( false );
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	back.addEventListener( 'click', function ( e ) {
		if ( dirty && ! locked ) {
			e.preventDefault();
			save( true ).then( function () {
				window.location.href = back.href;
			} );
		}
	} );

	function schedulePreview() {
		clearTimeout( previewTimer );
		previewTimer = setTimeout( preview, 300 );
	}

	var scrollY = 0;
	function preview() {
		stage.classList.add( 'is-loading' );
		try {
			scrollY = frame.contentWindow ? frame.contentWindow.scrollY : 0;
		} catch ( e ) {
			scrollY = 0;
		}
		post( B.previewAction, { design: JSON.stringify( design ), preheader: preheader ? preheader.value : '' } )
			.then( function ( res ) {
				if ( res.success ) {
					frame.srcdoc = res.data.html;
				}
			} )
			.finally( function () {
				stage.classList.remove( 'is-loading' );
			} );
	}

	/* ---------- canvas ---------- */

	var CANVAS_CSS =
		'[data-cfb]{cursor:pointer}' +
		'[data-cfb]:hover>td,div[data-cfb]:hover{outline:1px dashed #7aa7d6;outline-offset:-1px}' +
		'[data-cfb].cfx-sel>td,div[data-cfb].cfx-sel{outline:2px solid #2271b1 !important;outline-offset:-2px}' +
		'[data-cfb].cfx-before>td,div[data-cfb].cfx-before{box-shadow:inset 0 4px 0 #2271b1}' +
		'[data-cfb].cfx-after>td,div[data-cfb].cfx-after{box-shadow:inset 0 -4px 0 #2271b1}' +
		'[data-cfb-col].cfx-col-drop{outline:2px dashed #2271b1;outline-offset:-2px;background:rgba(34,113,177,.06)}' +
		'body.cfx-end-drop:after{content:"";display:block;height:4px;background:#2271b1;margin:0 auto;max-width:600px}' +
		'[data-cfb-edit]{outline:none;cursor:text}' +
		'[data-cfb-edit][contenteditable="true"]{box-shadow:0 0 0 1px #c3d9ee;border-radius:2px}' +
		'[data-cfb-edit] p{margin:0 0 14px}' +
		'.cfx-tb{position:absolute;z-index:99;display:flex;gap:2px;padding:3px;background:#2271b1;border-radius:4px;box-shadow:0 2px 6px rgba(0,0,0,.2);font:12px/1 -apple-system,sans-serif}' +
		'.cfx-tb button,.cfx-tb .cfx-grip{all:unset;color:#fff;width:24px;height:22px;display:flex;align-items:center;justify-content:center;border-radius:3px;cursor:pointer;font-size:13px}' +
		'.cfx-tb button:hover,.cfx-tb .cfx-grip:hover{background:rgba(255,255,255,.2)}' +
		'.cfx-tb .cfx-grip{cursor:grab;-webkit-user-drag:element;user-select:none}' +
		'.cfx-empty{margin:40px auto;max-width:520px;border:2px dashed #b6c2cf;border-radius:8px;padding:48px 24px;text-align:center;color:#6b7280;font:15px/1.5 -apple-system,sans-serif}';

	function doc() {
		try {
			return frame.contentDocument;
		} catch ( e ) {
			return null;
		}
	}

	function nodeFor( path ) {
		var d = doc();
		return d && path !== null ? d.querySelector( '[data-cfb="' + path + '"]' ) : null;
	}

	var toolbar = null;

	function highlight() {
		var d = doc();
		if ( ! d ) {
			return;
		}
		d.querySelectorAll( '.cfx-sel' ).forEach( function ( n ) {
			n.classList.remove( 'cfx-sel' );
		} );
		d.querySelectorAll( '[data-cfb-edit][contenteditable]' ).forEach( function ( n ) {
			n.removeAttribute( 'contenteditable' );
		} );
		var n = nodeFor( sel );
		if ( n ) {
			n.classList.add( 'cfx-sel' );
		}
		positionToolbar();
	}

	function positionToolbar() {
		var d = doc();
		if ( toolbar && toolbar.parentNode ) {
			toolbar.parentNode.removeChild( toolbar );
		}
		toolbar = null;
		var n = nodeFor( sel );
		if ( ! d || ! n || locked ) {
			return;
		}
		var r = n.getBoundingClientRect();
		var w = d.defaultView;
		toolbar = d.createElement( 'div' );
		toolbar.className = 'cfx-tb';
		var btn = function ( label, title, fn, cls ) {
			var b = d.createElement( 'button' );
			b.type = 'button';
			b.textContent = label;
			b.title = title;
			if ( cls ) {
				b.className = cls;
			}
			if ( fn ) {
				b.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					fn();
				} );
			}
			return b;
		};
		// A span, not a button: Chrome won't start a drag from a <button>.
		var grip = d.createElement( 'span' );
		grip.className = 'cfx-grip';
		grip.textContent = '⠿';
		grip.title = T.dragToMove || 'Drag to move';
		grip.setAttribute( 'draggable', 'true' );
		grip.addEventListener( 'dragstart', function ( e ) {
			drag = { kind: 'move', from: sel };
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', String( sel ) );
		} );
		grip.addEventListener( 'dragend', function () {
			drag = null;
			clearCanvasDrop();
		} );
		toolbar.appendChild( grip );
		var p = sel;
		toolbar.appendChild( btn( '↑', T.moveUp || 'Move up', function () {
			moveBy( p, -1 );
		} ) );
		toolbar.appendChild( btn( '↓', T.moveDown || 'Move down', function () {
			moveBy( p, 1 );
		} ) );
		toolbar.appendChild( btn( '⧉', T.duplicate || 'Duplicate', function () {
			duplicate( p );
		} ) );
		toolbar.appendChild( btn( '✕', T.remove || 'Delete', function () {
			removeAt( p );
		} ) );
		d.body.appendChild( toolbar );
		var tw = toolbar.offsetWidth;
		toolbar.style.top = Math.max( 0, r.top + w.scrollY - 28 ) + 'px';
		toolbar.style.left = Math.max( 0, r.right + w.scrollX - tw ) + 'px';
	}

	function clearCanvasDrop() {
		var d = doc();
		if ( ! d ) {
			return;
		}
		d.querySelectorAll( '.cfx-before, .cfx-after, .cfx-col-drop' ).forEach( function ( n ) {
			n.classList.remove( 'cfx-before', 'cfx-after', 'cfx-col-drop' );
		} );
		if ( d.body ) {
			d.body.classList.remove( 'cfx-end-drop' );
		}
	}

	function dragHasColumns() {
		if ( ! drag ) {
			return false;
		}
		if ( drag.kind === 'move' ) {
			var b = get( drag.from );
			return !! b && b.type === 'columns';
		}
		return hasColumns( drag.blocks || [] );
	}

	// Work out where a drop at this point lands: { container, index, node, after, col }.
	function dropTarget( d, e ) {
		var t = e.target && e.target.closest ? e.target : null;
		var blk = t ? t.closest( '[data-cfb]' ) : null;
		var col = t ? t.closest( '[data-cfb-col]' ) : null;
		var noCols = dragHasColumns();

		if ( col && ( ! blk || ! col.contains( blk ) ) && ! noCols ) {
			var cpath = col.getAttribute( 'data-cfb-col' );
			return { container: cpath, index: listOf( cpath ).length, col: col };
		}
		if ( blk ) {
			var path = blk.getAttribute( 'data-cfb' );
			var s = split( path );
			if ( s.container !== '' && noCols ) {
				blk = d.querySelector( '[data-cfb="' + s.container.split( '.' )[ 0 ] + '"]' );
				path = blk.getAttribute( 'data-cfb' );
				s = split( path );
			}
			var r = blk.getBoundingClientRect();
			var after = e.clientY > r.top + r.height / 2;
			return { container: s.container, index: after ? s.index + 1 : s.index, node: blk, after: after };
		}
		// Outside any block: nearest top-level block by vertical position.
		var rows = Array.prototype.filter.call( d.querySelectorAll( '[data-cfb]' ), function ( n ) {
			return n.getAttribute( 'data-cfb' ).indexOf( '.' ) === -1;
		} );
		for ( var k = 0; k < rows.length; k++ ) {
			var rr = rows[ k ].getBoundingClientRect();
			if ( e.clientY < rr.top + rr.height / 2 ) {
				return { container: '', index: k, node: rows[ k ], after: false };
			}
		}
		return { container: '', index: design.blocks.length, node: null, after: true };
	}

	function startInlineEdit( n, path, e ) {
		var target = n.querySelector( '[data-cfb-edit]' );
		if ( ! target || locked ) {
			return false;
		}
		if ( target.getAttribute( 'contenteditable' ) === 'true' ) {
			return true;
		}
		target.setAttribute( 'contenteditable', 'true' );
		target.focus();
		var d = doc();
		// Put the caret where the user clicked.
		if ( e && d.caretRangeFromPoint ) {
			var range = d.caretRangeFromPoint( e.clientX, e.clientY );
			if ( range ) {
				var s = d.defaultView.getSelection();
				s.removeAllRanges();
				s.addRange( range );
			}
		}
		editing = true;
		var kind = target.getAttribute( 'data-cfb-edit' );
		var b = get( path );
		var onInput = function () {
			if ( ! b ) {
				return;
			}
			if ( kind === 'text' ) {
				b.text = target.textContent.replace( /\s+/g, ' ' );
			} else {
				b.html = cleanHtml( target.innerHTML );
			}
			// Keep the settings panel in sync without re-rendering it.
			var input = side.querySelector( '[data-key="' + ( kind === 'text' ? 'text' : 'html' ) + '"]' );
			if ( input ) {
				if ( input.classList.contains( 'cfx-rte' ) ) {
					input.innerHTML = b.html;
				} else {
					input.value = b.text;
				}
			}
			changed( { record: true, preview: false, typing: true } );
		};
		target.addEventListener( 'input', onInput );
		target.addEventListener( 'keydown', function ( ev ) {
			if ( kind === 'text' && ev.key === 'Enter' ) {
				ev.preventDefault();
			}
			if ( ev.key === 'Escape' ) {
				target.blur();
			}
		} );
		target.addEventListener( 'paste', function ( ev ) {
			ev.preventDefault();
			d.execCommand( 'insertText', false, ( ev.clipboardData || window.clipboardData ).getData( 'text/plain' ) );
		} );
		target.addEventListener( 'blur', function () {
			target.removeAttribute( 'contenteditable' );
			editing = false;
			schedulePreview();
		}, { once: true } );
		return true;
	}

	frame.addEventListener( 'load', function () {
		var d = doc();
		if ( ! d || ! d.body ) {
			return;
		}
		var st = d.createElement( 'style' );
		st.textContent = CANVAS_CSS;
		d.head.appendChild( st );
		try {
			d.defaultView.scrollTo( 0, scrollY );
		} catch ( e ) {}

		if ( ! design.blocks.length && ! locked ) {
			var hint = d.createElement( 'div' );
			hint.className = 'cfx-empty';
			hint.textContent = T.empty || 'Drag blocks here.';
			d.body.insertBefore( hint, d.body.firstChild );
		}
		// Links and images would start their own native drags or navigate away.
		d.querySelectorAll( 'a, img' ).forEach( function ( n ) {
			n.setAttribute( 'draggable', 'false' );
		} );
		highlight();

		d.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.cfx-tb' ) ) {
				return;
			}
			if ( ! e.target.closest( '[contenteditable="true"]' ) ) {
				e.preventDefault();
			}
			var n = e.target.closest( '[data-cfb]' );
			if ( ! n ) {
				select( null );
				return;
			}
			var path = n.getAttribute( 'data-cfb' );
			if ( path !== sel ) {
				select( path );
			}
			// A click on text in the selected heading/text block edits it in place.
			if ( e.target.closest( '[data-cfb-edit]' ) && e.target.closest( '[data-cfb]' ) === n ) {
				startInlineEdit( n, path, e );
			}
		} );
		d.addEventListener( 'scroll', positionToolbar );
		d.defaultView.addEventListener( 'resize', positionToolbar );
		d.addEventListener( 'keydown', keys );

		if ( locked ) {
			return;
		}
		d.addEventListener( 'dragover', function ( e ) {
			if ( ! drag ) {
				return;
			}
			e.preventDefault();
			clearCanvasDrop();
			var t = dropTarget( d, e );
			if ( t.col ) {
				t.col.classList.add( 'cfx-col-drop' );
			} else if ( t.node ) {
				t.node.classList.add( t.after ? 'cfx-after' : 'cfx-before' );
			} else {
				d.body.classList.add( 'cfx-end-drop' );
			}
		} );
		d.addEventListener( 'dragleave', function ( e ) {
			if ( ! e.relatedTarget ) {
				clearCanvasDrop();
			}
		} );
		d.addEventListener( 'drop', function ( e ) {
			if ( ! drag ) {
				return;
			}
			e.preventDefault();
			var t = dropTarget( d, e );
			clearCanvasDrop();
			var dr = drag;
			drag = null;
			if ( dr.kind === 'move' ) {
				moveTo( dr.from, { container: t.container, index: t.index } );
			} else {
				insertBlocks( dr.blocks, { container: t.container, index: t.index } );
			}
		} );
	} );

	/* ---------- keyboard ---------- */

	function keys( e ) {
		var tag = ( e.target.tagName || '' ).toLowerCase();
		var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
		var mod = e.metaKey || e.ctrlKey;
		if ( mod && e.key.toLowerCase() === 's' ) {
			e.preventDefault();
			save( true );
			return;
		}
		if ( typing ) {
			return;
		}
		if ( mod && e.key.toLowerCase() === 'z' ) {
			e.preventDefault();
			if ( e.shiftKey ) {
				redo();
			} else {
				undo();
			}
		} else if ( mod && e.key.toLowerCase() === 'y' ) {
			e.preventDefault();
			redo();
		} else if ( ( e.key === 'Delete' || e.key === 'Backspace' ) && sel !== null && ! locked ) {
			e.preventDefault();
			removeAt( sel );
		} else if ( e.key === 'Escape' ) {
			select( null );
		}
	}
	document.addEventListener( 'keydown', keys );

	/* ---------- go ---------- */

	if ( locked && T.lockedNote ) {
		top.appendChild( el( 'span', { class: 'cfx-locked', text: T.lockedNote } ) );
	}
	setStatus( locked ? '' : ( T.savedState || '' ) );
	renderSide();
	preview();

	// Test hooks (used by the plugin's browser tests).
	window.cfxEditor = { design: function () {
		return design;
	}, select: select, save: save };
} )();
