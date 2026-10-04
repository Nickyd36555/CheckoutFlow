/* CheckoutFlow – drag-and-drop email builder. Vanilla JS; the preview is rendered server-side by the same code that sends. */
( function () {
	'use strict';

	var B = window.checkoutflowBuilder;
	var A = window.checkoutflowAdmin;
	var root = document.getElementById( 'cf-builder' );
	if ( ! B || ! root ) {
		return;
	}
	var L = B.i18n.labels;
	var locked = root.classList.contains( 'is-locked' );
	var design = JSON.parse( root.dataset.design || '{}' );
	design.settings = design.settings || {};
	design.blocks = design.blocks || [];
	var selected = design.blocks.length ? 0 : -1;
	var dirty = false;
	var previewTimer = null;
	var dragFrom = null; // index of a block being dragged, or "new:type"

	// Dashicons ship with wp-admin (emoji would be swapped for images by WordPress).
	var ICONS = {
		heading: 'heading', text: 'editor-paragraph', button: 'button', image: 'format-image',
		products: 'products', cart_items: 'cart', order_items: 'list-view', coupon: 'tickets-alt',
		divider: 'minus', spacer: 'image-flip-vertical', html: 'editor-code',
		overview: 'grid-view', items: 'list-view', payment: 'money-alt', customer: 'id-alt', support: 'phone'
	};

	function icon( type ) {
		return el( 'span', { class: 'cfb-icon dashicons dashicons-' + ( ICONS[ type ] || 'marker' ), 'aria-hidden': 'true' } );
	}
	var DYNAMIC = [ 'cart_items', 'order_items' ];

	function isDynamic( type ) {
		return DYNAMIC.indexOf( type ) !== -1 || !! ( B.types[ type ] && B.types[ type ].dynamic );
	}

	/* ---------- helpers ---------- */

	function el( tag, attrs, children ) {
		var e = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				e.textContent = attrs[ k ];
			} else if ( k === 'html' ) {
				e.innerHTML = attrs[ k ];
			} else if ( k.indexOf( 'on' ) === 0 ) {
				e.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( attrs[ k ] !== false && attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				e.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
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

	function changed( rerender ) {
		dirty = true;
		if ( rerender ) {
			render();
		}
		schedulePreview();
	}

	function summary( b ) {
		var s = '';
		if ( b.text ) {
			s = b.text;
		} else if ( b.html ) {
			var d = document.createElement( 'div' );
			d.innerHTML = b.html;
			s = d.textContent;
		} else if ( b.type === 'coupon' ) {
			s = b.amount + ( b.discount_type === 'percent' ? '%' : '' ) + ' · ' + b.days + 'd';
		} else if ( b.type === 'image' ) {
			s = b.src ? b.src.split( '/' ).pop() : '';
		}
		return s.length > 48 ? s.slice( 0, 48 ) + '…' : s;
	}

	/* ---------- layout ---------- */

	var side = el( 'div', { class: 'cfb-side' } );
	var palette = el( 'div', { class: 'cfb-palette', role: 'toolbar', 'aria-label': 'Blocks' } );
	var list = el( 'div', { class: 'cfb-list' } );
	var global = el( 'details', { class: 'cfb-panel' }, [ el( 'summary', { text: 'Design' } ) ] );
	var tags = el( 'details', { class: 'cfb-panel' }, [ el( 'summary', { text: 'Merge tags' } ) ] );
	var previewWrap = el( 'div', { class: 'cfb-preview' } );
	var frame = el( 'iframe', { class: 'cfb-frame', title: 'Email preview' } );
	var devices = el( 'div', { class: 'cfb-devices' } );

	[ [ 'desktop', 'Desktop' ], [ 'mobile', 'Mobile' ] ].forEach( function ( d, i ) {
		devices.appendChild( el( 'button', {
			type: 'button',
			class: 'button' + ( i === 0 ? ' is-active' : '' ),
			text: d[ 1 ],
			onclick: function ( e ) {
				devices.querySelectorAll( 'button' ).forEach( function ( b ) {
					b.classList.remove( 'is-active' );
				} );
				e.target.classList.add( 'is-active' );
				frame.classList.toggle( 'is-mobile', d[ 0 ] === 'mobile' );
			}
		} ) );
	} );
	previewWrap.appendChild( devices );
	previewWrap.appendChild( el( 'div', { class: 'cfb-frame-wrap' }, [ frame ] ) );

	if ( ! locked ) {
		side.appendChild( palette );
	}
	side.appendChild( list );
	side.appendChild( global );
	side.appendChild( tags );
	root.appendChild( side );
	root.appendChild( previewWrap );

	/* ---------- palette ---------- */

	Object.keys( B.types ).forEach( function ( type ) {
		if ( type === 'html' && ! B.canHtml ) {
			return;
		}
		var btn = el( 'button', {
			type: 'button',
			class: 'cfb-add',
			draggable: 'true',
			title: B.types[ type ].label,
			onclick: function () {
				insert( type, selected >= 0 ? selected + 1 : design.blocks.length );
			},
			ondragstart: function ( e ) {
				dragFrom = 'new:' + type;
				e.dataTransfer.effectAllowed = 'copy';
				e.dataTransfer.setData( 'text/plain', type );
			}
		}, [ icon( type ), B.types[ type ].label ] );
		palette.appendChild( btn );
	} );

	function insert( type, at ) {
		var b = clone( B.types[ type ].props );
		b.type = type;
		design.blocks.splice( at, 0, b );
		selected = at;
		changed( true );
	}

	/* ---------- block list ---------- */

	function render() {
		list.innerHTML = '';
		if ( ! design.blocks.length ) {
			list.appendChild( el( 'p', { class: 'cfb-empty', text: B.i18n.empty } ) );
		}
		design.blocks.forEach( function ( b, i ) {
			list.appendChild( card( b, i ) );
		} );
		highlight();
	}

	function card( b, i ) {
		var isSel = i === selected;
		var c = el( 'div', { class: 'cfb-block' + ( isSel ? ' is-selected' : '' ), 'data-i': i } );
		var handle = el( 'span', { class: 'cfb-handle', 'aria-hidden': 'true', text: '⋮⋮' } );
		var head = el( 'div', {
			class: 'cfb-head',
			onclick: function ( e ) {
				if ( e.target.closest( '.cfb-actions' ) ) {
					return;
				}
				selected = isSel ? -1 : i;
				render();
			}
		}, [
			locked ? null : handle,
			icon( b.type ),
			el( 'strong', { text: B.types[ b.type ].label } ),
			el( 'span', { class: 'cfb-summary', text: summary( b ) } )
		] );

		if ( ! locked ) {
			var actions = el( 'span', { class: 'cfb-actions' } );
			[
				[ '↑', B.i18n.moveUp, function () { move( i, i - 1 ); }, i === 0 ],
				[ '↓', B.i18n.moveDown, function () { move( i, i + 1 ); }, i === design.blocks.length - 1 ],
				[ '⧉', B.i18n.duplicate, function () {
					design.blocks.splice( i + 1, 0, clone( b ) );
					selected = i + 1;
					changed( true );
				} ],
				[ '✕', B.i18n.remove, function () {
					design.blocks.splice( i, 1 );
					selected = Math.min( selected, design.blocks.length - 1 );
					changed( true );
				} ]
			].forEach( function ( a ) {
				actions.appendChild( el( 'button', { type: 'button', class: 'cfb-act', title: a[ 1 ], 'aria-label': a[ 1 ], text: a[ 0 ], disabled: a[ 3 ] ? true : false, onclick: a[ 2 ] } ) );
			} );
			head.appendChild( actions );

			// Drag only from the handle, so text selection in inputs still works.
			handle.addEventListener( 'mousedown', function () {
				c.setAttribute( 'draggable', 'true' );
			} );
			c.addEventListener( 'dragstart', function ( e ) {
				dragFrom = i;
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', String( i ) );
				c.classList.add( 'is-dragging' );
			} );
			c.addEventListener( 'dragend', function () {
				c.removeAttribute( 'draggable' );
				c.classList.remove( 'is-dragging' );
				clearDrop();
			} );
		}

		c.appendChild( head );
		if ( isSel ) {
			c.appendChild( editor( b ) );
		}
		return c;
	}

	function move( from, to ) {
		if ( to < 0 || to >= design.blocks.length ) {
			return;
		}
		var b = design.blocks.splice( from, 1 )[ 0 ];
		design.blocks.splice( to, 0, b );
		selected = to;
		changed( true );
	}

	/* ---------- drag & drop ---------- */

	function clearDrop() {
		list.querySelectorAll( '.drop-before, .drop-after' ).forEach( function ( n ) {
			n.classList.remove( 'drop-before', 'drop-after' );
		} );
	}

	function dropIndex( e ) {
		var target = e.target.closest( '.cfb-block' );
		if ( ! target ) {
			return { index: design.blocks.length, node: null, after: true };
		}
		var r = target.getBoundingClientRect();
		var after = e.clientY > r.top + r.height / 2;
		var i = parseInt( target.dataset.i, 10 );
		return { index: after ? i + 1 : i, node: target, after: after };
	}

	list.addEventListener( 'dragover', function ( e ) {
		if ( dragFrom === null || locked ) {
			return;
		}
		e.preventDefault();
		clearDrop();
		var d = dropIndex( e );
		if ( d.node ) {
			d.node.classList.add( d.after ? 'drop-after' : 'drop-before' );
		}
	} );
	list.addEventListener( 'dragleave', function ( e ) {
		if ( ! list.contains( e.relatedTarget ) ) {
			clearDrop();
		}
	} );
	list.addEventListener( 'drop', function ( e ) {
		if ( dragFrom === null || locked ) {
			return;
		}
		e.preventDefault();
		var at = dropIndex( e ).index;
		clearDrop();
		dropAt( at );
	} );
	document.addEventListener( 'dragend', function () {
		dragFrom = null;
	} );
	// A click on the handle that doesn't turn into a drag must not leave the card draggable
	// (inputs inside a draggable element can't be selected or edited properly).
	document.addEventListener( 'mouseup', function () {
		list.querySelectorAll( '.cfb-block[draggable]' ).forEach( function ( n ) {
			if ( ! n.classList.contains( 'is-dragging' ) ) {
				n.removeAttribute( 'draggable' );
			}
		} );
	} );

	function dropAt( at ) {
		if ( typeof dragFrom === 'string' ) {
			insert( dragFrom.slice( 4 ), at );
		} else if ( dragFrom !== null ) {
			var from = dragFrom;
			if ( at > from ) {
				at--;
			}
			if ( at !== from ) {
				move( from, at );
			}
		}
		dragFrom = null;
	}

	/* ---------- block settings ---------- */

	function field( label, input ) {
		return el( 'label', { class: 'cfb-field' }, [ el( 'span', { text: label } ), input ] );
	}

	function bind( input, obj, key, rerenderList ) {
		var ev = input.type === 'checkbox' || input.tagName === 'SELECT' || input.type === 'color' ? 'change' : 'input';
		input.addEventListener( ev, function () {
			var v = input.type === 'checkbox' ? input.checked : input.value;
			if ( input.type === 'number' ) {
				v = input.value === '' ? 0 : parseFloat( input.value );
			}
			obj[ key ] = v;
			if ( rerenderList ) {
				var s = list.querySelector( '.is-selected .cfb-summary' );
				if ( s ) {
					s.textContent = summary( obj );
				}
			}
			changed( false );
		} );
		input.disabled = locked;
		return input;
	}

	function mediaButton( input ) {
		if ( ! window.wp || ! wp.media || locked ) {
			return null;
		}
		return el( 'button', {
			type: 'button',
			class: 'button',
			text: B.i18n.chooseImage,
			onclick: function () {
				var frameM = wp.media( { library: { type: 'image' }, multiple: false } );
				frameM.on( 'select', function () {
					var att = frameM.state().get( 'selection' ).first().toJSON();
					input.value = att.url;
					input.dispatchEvent( new Event( 'input' ) );
				} );
				frameM.open();
			}
		} );
	}

	function richText( b ) {
		var area = el( 'div', { class: 'cfb-rte', contenteditable: locked ? 'false' : 'true', html: b.html } );
		var sync = function () {
			b.html = area.innerHTML;
			var s = list.querySelector( '.is-selected .cfb-summary' );
			if ( s ) {
				s.textContent = summary( b );
			}
			changed( false );
		};
		area.addEventListener( 'input', sync );
		area.addEventListener( 'paste', function ( e ) {
			e.preventDefault();
			var t = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' );
			document.execCommand( 'insertText', false, t );
		} );

		var tb = el( 'div', { class: 'cfb-toolbar' } );
		[ [ 'B', 'bold' ], [ 'I', 'italic' ], [ 'U', 'underline' ], [ '• List', 'insertUnorderedList' ], [ 'Link', 'createLink' ], [ 'Clear', 'removeFormat' ] ].forEach( function ( t ) {
			tb.appendChild( el( 'button', {
				type: 'button',
				class: 'button button-small',
				text: t[ 0 ],
				onmousedown: function ( e ) {
					e.preventDefault(); // keep the selection
				},
				onclick: function () {
					if ( t[ 1 ] === 'createLink' ) {
						var url = window.prompt( B.i18n.linkPrompt, 'https://' );
						if ( url ) {
							document.execCommand( 'createLink', false, url );
						}
					} else {
						document.execCommand( t[ 1 ], false, null );
					}
					sync();
				}
			} ) );
		} );
		var tagSel = el( 'select', { class: 'cfb-tag-insert' }, [ el( 'option', { value: '', text: '{ } Insert tag' } ) ] );
		Object.keys( B.mergeTags ).forEach( function ( k ) {
			tagSel.appendChild( el( 'option', { value: k, text: '{' + k + '}' } ) );
		} );
		tagSel.addEventListener( 'change', function () {
			if ( tagSel.value ) {
				area.focus();
				document.execCommand( 'insertText', false, '{' + tagSel.value + '}' );
				tagSel.value = '';
				sync();
			}
		} );
		tb.appendChild( tagSel );
		return el( 'div', { class: 'cfb-field' }, [ el( 'span', { text: L.html } ), locked ? null : tb, area ] );
	}

	function editor( b ) {
		var box = el( 'div', { class: 'cfb-body' } );
		if ( isDynamic( b.type ) ) {
			box.appendChild( el( 'p', { class: 'description', text: B.i18n.dynamic } ) );
		}
		Object.keys( B.types[ b.type ].props ).forEach( function ( key ) {
			var v = b[ key ];
			var input;
			var def = B.types[ b.type ].props[ key ];
			var opts = B.types[ b.type ].options && B.types[ b.type ].options[ key ];
			if ( opts ) {
				input = el( 'select', {}, Object.keys( opts ).map( function ( o ) {
					return el( 'option', { value: o, text: opts[ o ], selected: v === o } );
				} ) );
				box.appendChild( field( L[ key ] || key, bind( input, b, key ) ) );
				return;
			}
			if ( typeof def === 'boolean' ) {
				input = el( 'input', { type: 'checkbox' } );
				input.checked = !! v;
				box.appendChild( el( 'label', { class: 'cfb-field cfb-check' }, [ bind( input, b, key ), el( 'span', { text: L[ key ] || key } ) ] ) );
				return;
			}
			switch ( key ) {
				case 'html':
					if ( b.type === 'text' ) {
						box.appendChild( richText( b ) );
					} else {
						input = el( 'textarea', { rows: 8, class: 'code' } );
						input.value = v;
						box.appendChild( field( L.html, bind( input, b, key, true ) ) );
					}
					return;
				case 'align':
					input = el( 'select', {}, [ 'left', 'center', 'right' ].map( function ( o ) {
						return el( 'option', { value: o, text: o, selected: v === o } );
					} ) );
					break;
				case 'discount_type':
					input = el( 'select', {}, [ [ 'percent', '%' ], [ 'fixed_cart', 'Fixed amount' ] ].map( function ( o ) {
						return el( 'option', { value: o[ 0 ], text: o[ 1 ], selected: v === o[ 0 ] } );
					} ) );
					break;
				case 'free_shipping':
					input = el( 'input', { type: 'checkbox' } );
					input.checked = !! v;
					break;
				case 'color':
					input = el( 'input', { type: 'text', class: 'cfb-color-text', placeholder: b.type === 'button' ? 'accent' : '#e5e7eb', value: v || '' } );
					break;
				default:
					input = el( 'input', { type: typeof B.types[ b.type ].props[ key ] === 'number' ? 'number' : 'text', value: v } );
					if ( key === 'columns' ) {
						input.min = 1;
						input.max = 3;
					}
			}
			var f = field( L[ key ] || key, bind( input, b, key, [ 'text', 'src' ].indexOf( key ) !== -1 ) );
			if ( key === 'src' ) {
				var mb = mediaButton( input );
				if ( mb ) {
					f.appendChild( mb );
				}
			}
			box.appendChild( f );
		} );
		return box;
	}

	/* ---------- global design ---------- */

	var FONTS = B.fonts || [
		'Helvetica, Arial, sans-serif',
		'Georgia, "Times New Roman", serif',
		'"Trebuchet MS", Tahoma, sans-serif',
		'Verdana, Geneva, sans-serif',
		'"Courier New", monospace'
	];
	var GLOBALS = B.globals || [ [ 'bg', 'color' ], [ 'content_bg', 'color' ], [ 'accent', 'color' ], [ 'text_color', 'color' ], [ 'font', 'font' ], [ 'logo', 'image' ] ];
	GLOBALS.forEach( function ( g ) {
		var k = g[ 0 ];
		var input;
		if ( g[ 1 ] === 'color' ) {
			input = el( 'input', { type: 'color', value: design.settings[ k ] || '#ffffff' } );
		} else if ( g[ 1 ] === 'font' ) {
			input = el( 'select', {}, FONTS.map( function ( f ) {
				return el( 'option', { value: f, text: f.split( ',' )[ 0 ].replace( /"/g, '' ), selected: design.settings.font === f } );
			} ) );
		} else if ( g[ 1 ] === 'number' ) {
			input = el( 'input', { type: 'number', value: design.settings[ k ] } );
		} else {
			input = el( 'input', { type: 'text', value: design.settings[ k ] || '' } );
		}
		var f = field( L[ k ] || k, bind( input, design.settings, k ) );
		if ( g[ 1 ] === 'image' ) {
			var mb = mediaButton( input );
			if ( mb ) {
				f.appendChild( mb );
			}
		}
		global.appendChild( f );
	} );

	/* ---------- merge tag reference ---------- */

	var tagList = el( 'ul', { class: 'cfb-tags' } );
	Object.keys( B.mergeTags ).forEach( function ( k ) {
		tagList.appendChild( el( 'li', {}, [
			el( 'button', {
				type: 'button',
				class: 'cfb-tag',
				text: '{' + k + '}',
				onclick: function ( e ) {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( '{' + k + '}' );
						var t = e.target;
						t.dataset.label = t.textContent;
						t.textContent = B.i18n.copied;
						setTimeout( function () {
							t.textContent = t.dataset.label;
						}, 900 );
					}
				}
			} ),
			el( 'span', { text: ' ' + B.mergeTags[ k ] } )
		] ) );
	} );
	if ( ! B.noFallback ) {
		tags.appendChild( el( 'p', { class: 'description', text: 'Fallback: {first_name|there}' } ) );
	}
	tags.appendChild( tagList );


	/* ---------- canvas: click, drag and drop directly on the preview ---------- */

	var CANVAS_CSS =
		'[data-cfb]{cursor:pointer}' +
		'[data-cfb]:hover>td{box-shadow:inset 0 0 0 1px #9ec2e6}' +
		'[data-cfb].cfb-sel>td{box-shadow:inset 0 0 0 2px #2271b1}' +
		'[data-cfb].cfb-drop-before>td{box-shadow:inset 0 4px 0 #2271b1}' +
		'[data-cfb].cfb-drop-after>td{box-shadow:inset 0 -4px 0 #2271b1}' +
		'div[data-cfb]{position:relative}' +
		'div[data-cfb]:hover{outline:1px dashed #7aa7d6;outline-offset:3px}' +
		'div[data-cfb].cfb-sel{outline:2px solid #2271b1;outline-offset:3px}' +
		'div[data-cfb].cfb-drop-before{box-shadow:0 -6px 0 -2px #2271b1}' +
		'div[data-cfb].cfb-drop-after{box-shadow:0 6px 0 -2px #2271b1}' +
		'body.cfb-drop-end .cfb-end{display:block}' +
		'.cfb-end{display:none;height:4px;background:#2271b1;margin:0 auto;max-width:600px}' +
		'.cfb-empty-drop{margin:24px auto;max-width:560px;border:2px dashed #b6c2cf;border-radius:6px;padding:40px;text-align:center;color:#6b7280;font:14px/1.4 sans-serif}';

	function doc() {
		try {
			return frame.contentDocument;
		} catch ( e ) {
			return null;
		}
	}

	function highlight() {
		var d = doc();
		if ( ! d ) {
			return;
		}
		d.querySelectorAll( '[data-cfb]' ).forEach( function ( r ) {
			r.classList.toggle( 'cfb-sel', parseInt( r.getAttribute( 'data-cfb' ), 10 ) === selected );
		} );
	}

	function canvasClear( d ) {
		d.querySelectorAll( '.cfb-drop-before, .cfb-drop-after' ).forEach( function ( n ) {
			n.classList.remove( 'cfb-drop-before', 'cfb-drop-after' );
		} );
		d.body.classList.remove( 'cfb-drop-end' );
	}

	function canvasTarget( d, e ) {
		var row = e.target && e.target.closest ? e.target.closest( '[data-cfb]' ) : null;
		if ( ! row ) {
			// Between or outside blocks: the nearest block by vertical position.
			var rows = d.querySelectorAll( '[data-cfb]' );
			for ( var k = 0; k < rows.length; k++ ) {
				if ( e.clientY < rows[ k ].getBoundingClientRect().bottom ) {
					row = rows[ k ];
					break;
				}
			}
		}
		if ( ! row ) {
			return { index: design.blocks.length, node: null, after: true };
		}
		var r = row.getBoundingClientRect();
		var after = e.clientY > r.top + r.height / 2;
		var i = parseInt( row.getAttribute( 'data-cfb' ), 10 );
		return { index: after ? i + 1 : i, node: row, after: after };
	}

	frame.addEventListener( 'load', function () {
		var d = doc();
		if ( ! d || ! d.body ) {
			return;
		}
		var st = d.createElement( 'style' );
		st.textContent = CANVAS_CSS;
		d.head.appendChild( st );
		var end = d.createElement( 'div' );
		end.className = 'cfb-end';
		d.body.appendChild( end );
		if ( ! design.blocks.length && ! locked ) {
			var hint = d.createElement( 'div' );
			hint.className = 'cfb-empty-drop';
			hint.textContent = B.i18n.empty;
			d.body.insertBefore( hint, d.body.firstChild );
		}

		d.querySelectorAll( '[data-cfb]' ).forEach( function ( row ) {
			if ( ! locked ) {
				row.setAttribute( 'draggable', 'true' );
			}
		} );
		// Links and images would start their own native drag.
		d.querySelectorAll( '[data-cfb] a, [data-cfb] img' ).forEach( function ( n ) {
			n.setAttribute( 'draggable', 'false' );
		} );
		highlight();

		d.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( '[data-cfb]' );
			e.preventDefault(); // never follow links in the preview
			if ( ! row ) {
				return;
			}
			selected = parseInt( row.getAttribute( 'data-cfb' ), 10 );
			render();
			var c = list.querySelector( '.cfb-block.is-selected' );
			if ( c && c.scrollIntoView ) {
				c.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			}
		} );

		if ( locked ) {
			return;
		}
		d.addEventListener( 'dragstart', function ( e ) {
			var row = e.target.closest ? e.target.closest( '[data-cfb]' ) : null;
			if ( ! row ) {
				return;
			}
			dragFrom = parseInt( row.getAttribute( 'data-cfb' ), 10 );
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', String( dragFrom ) );
		} );
		d.addEventListener( 'dragover', function ( e ) {
			if ( dragFrom === null ) {
				return;
			}
			e.preventDefault();
			canvasClear( d );
			var t = canvasTarget( d, e );
			if ( t.node ) {
				t.node.classList.add( t.after ? 'cfb-drop-after' : 'cfb-drop-before' );
			} else {
				d.body.classList.add( 'cfb-drop-end' );
			}
		} );
		d.addEventListener( 'dragleave', function ( e ) {
			if ( ! e.relatedTarget ) {
				canvasClear( d );
			}
		} );
		d.addEventListener( 'drop', function ( e ) {
			if ( dragFrom === null ) {
				return;
			}
			e.preventDefault();
			var at = canvasTarget( d, e ).index;
			canvasClear( d );
			dropAt( at );
		} );
		d.addEventListener( 'dragend', function () {
			canvasClear( d );
			dragFrom = null;
		} );
	} );

	/* ---------- preview ---------- */

	var preheader = document.getElementById( 'cf-preheader' );
	var subject = document.getElementById( 'cf-subject' );

	function schedulePreview() {
		clearTimeout( previewTimer );
		previewTimer = setTimeout( preview, 350 );
	}

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

	function preview() {
		previewWrap.classList.add( 'is-loading' );
		post( B.previewAction || 'cf_preview', { design: JSON.stringify( design ), preheader: preheader ? preheader.value : '' } )
			.then( function ( res ) {
				if ( res.success ) {
					frame.srcdoc = res.data.html;
				}
			} )
			.finally( function () {
				previewWrap.classList.remove( 'is-loading' );
			} );
	}

	[ preheader, subject ].forEach( function ( i ) {
		if ( i ) {
			i.addEventListener( 'input', function () {
				dirty = true;
			} );
		}
	} );

	/* ---------- save / test ---------- */

	var form = document.getElementById( 'cf-email-form' ) || root.closest( 'form' );
	form.addEventListener( 'submit', function () {
		document.getElementById( 'cf-design' ).value = JSON.stringify( design );
		dirty = false;
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty && ! locked ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	var testBtn = document.getElementById( 'cf-test-send' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'cf-test-result' );
			testBtn.disabled = true;
			out.textContent = '…';
			post( 'cf_send_test', {
				to: document.getElementById( 'cf-test-to' ).value,
				subject: subject.value,
				preheader: preheader.value,
				design: JSON.stringify( design )
			} ).then( function ( res ) {
				out.textContent = res.data && res.data.message ? res.data.message : '';
				out.className = res.success ? 'cf-ok' : 'cf-error';
			} ).catch( function () {
				out.textContent = 'Request failed';
				out.className = 'cf-error';
			} ).finally( function () {
				testBtn.disabled = false;
			} );
		} );
	}

	render();
	preview();
} )();
