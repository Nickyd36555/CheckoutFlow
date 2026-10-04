/* CheckoutFlow – checkout field editor. Vanilla JS, no build step. */
( function () {
	'use strict';

	var C = window.checkoutflowFields;
	var app = document.getElementById( 'cf-fields-app' );
	var form = document.getElementById( 'cf-fields-form' );
	if ( ! C || ! app || ! form ) {
		return;
	}
	var cfg = JSON.parse( JSON.stringify( C.config ) );
	var open = null;      // key of the field whose editor is open
	var drag = null;      // key being dragged
	var dirty = false;

	function el( tag, attrs, children ) {
		var e = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( k === 'text' ) {
				e.textContent = v;
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

	function changed() {
		dirty = true;
		render();
	}

	function fieldsIn( section ) {
		return cfg.fields.filter( function ( f ) {
			return f.section === section;
		} );
	}

	function find( key ) {
		for ( var i = 0; i < cfg.fields.length; i++ ) {
			if ( cfg.fields[ i ].key === key ) {
				return cfg.fields[ i ];
			}
		}
		return null;
	}

	function uniqueKey( base ) {
		var slug = 'cf_' + ( base || 'field' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ).slice( 0, 40 );
		if ( slug === 'cf_' ) {
			slug = 'cf_field';
		}
		var key = slug;
		var n = 2;
		while ( find( key ) ) {
			key = slug + '_' + n++;
		}
		return key;
	}

	function isLocked( f ) {
		return C.locked.indexOf( f.key ) !== -1;
	}

	/* ---------- layout ---------- */

	function sectionOrder() {
		var list = [ { id: 'contact', builtin: true } ];
		var add = function ( pos ) {
			cfg.sections.forEach( function ( s ) {
				if ( s.position === pos ) {
					list.push( s );
				}
			} );
		};
		add( 'after_contact' );
		list.push( { id: 'address', builtin: true } );
		add( 'after_address' );
		list.push( { id: 'notes', builtin: true } );
		add( 'before_payment' );
		return list;
	}

	function render() {
		app.innerHTML = '';
		sectionOrder().forEach( function ( s ) {
			app.appendChild( sectionCard( s ) );
		} );
		app.appendChild( el( 'button', { type: 'button', class: 'button cf-add-section', onclick: addSection }, [ '+ Add section' ] ) );
	}

	function sectionCard( s ) {
		var card = el( 'div', { class: 'cf-card cf-fsec' + ( s.builtin ? '' : ' is-custom' ), 'data-section': s.id } );
		var head = el( 'div', { class: 'cf-fsec-head' } );
		if ( s.builtin ) {
			head.appendChild( el( 'h2', { text: C.builtin[ s.id ] } ) );
		} else {
			var title = el( 'input', { type: 'text', class: 'cf-fsec-title', value: s.title, placeholder: 'Section title', 'aria-label': 'Section title' } );
			title.addEventListener( 'input', function () {
				s.title = title.value;
				dirty = true;
			} );
			var pos = el( 'select', { 'aria-label': 'Position' } );
			Object.keys( C.positions ).forEach( function ( p ) {
				var o = el( 'option', { value: p, text: C.positions[ p ] } );
				o.selected = s.position === p;
				pos.appendChild( o );
			} );
			pos.addEventListener( 'change', function () {
				s.position = pos.value;
				changed();
			} );
			head.appendChild( title );
			head.appendChild( pos );
			head.appendChild( el( 'button', { type: 'button', class: 'button-link cf-danger', text: 'Delete section', onclick: function () {
				var inside = fieldsIn( s.id );
				if ( inside.length && ! window.confirm( 'Delete this section and its ' + inside.length + ' field(s)?' ) ) {
					return;
				}
				cfg.fields = cfg.fields.filter( function ( f ) {
					return f.section !== s.id;
				} );
				cfg.sections = cfg.sections.filter( function ( x ) {
					return x !== s;
				} );
				changed();
			} } ) );
		}
		card.appendChild( head );

		var list = el( 'ul', { class: 'cf-flist', 'data-section': s.id } );
		var rows = fieldsIn( s.id );
		rows.forEach( function ( f ) {
			list.appendChild( fieldRow( f ) );
		} );
		if ( ! rows.length ) {
			list.appendChild( el( 'li', { class: 'cf-fempty', text: 'No fields yet. Add one below, or drag a custom field here.' } ) );
		}
		list.addEventListener( 'dragover', function ( e ) {
			var f = drag && find( drag );
			if ( ! f || ( ! f.custom && f.section !== s.id ) ) {
				return;
			}
			e.preventDefault();
			var row = e.target.closest( '.cf-frow' );
			clearMarks();
			if ( row && row.dataset.key !== drag ) {
				var r = row.getBoundingClientRect();
				row.classList.add( e.clientY < r.top + r.height / 2 ? 'drop-before' : 'drop-after' );
			} else if ( ! row ) {
				list.classList.add( 'drop-into' );
			}
		} );
		list.addEventListener( 'drop', function ( e ) {
			var f = drag && find( drag );
			if ( ! f ) {
				return;
			}
			e.preventDefault();
			var row = list.querySelector( '.drop-before, .drop-after' );
			var before = row && row.classList.contains( 'drop-before' );
			var target = row ? find( row.dataset.key ) : null;
			clearMarks();
			if ( ! f.custom && f.section !== s.id ) {
				return;
			}
			cfg.fields.splice( cfg.fields.indexOf( f ), 1 );
			f.section = s.id;
			if ( target ) {
				cfg.fields.splice( cfg.fields.indexOf( target ) + ( before ? 0 : 1 ), 0, f );
			} else {
				cfg.fields.push( f );
			}
			drag = null;
			changed();
		} );
		card.appendChild( list );

		var picker = el( 'select', { class: 'cf-add-type', 'aria-label': 'Field type' } );
		picker.appendChild( el( 'option', { value: '', text: '+ Add field…' } ) );
		Object.keys( C.types ).forEach( function ( t ) {
			picker.appendChild( el( 'option', { value: t, text: C.types[ t ] } ) );
		} );
		picker.addEventListener( 'change', function () {
			if ( picker.value ) {
				addField( s.id, picker.value );
			}
		} );
		card.appendChild( el( 'div', { class: 'cf-fsec-foot' }, [ picker ] ) );
		return card;
	}

	function clearMarks() {
		app.querySelectorAll( '.drop-before, .drop-after, .drop-into' ).forEach( function ( n ) {
			n.classList.remove( 'drop-before', 'drop-after', 'drop-into' );
		} );
	}

	function badges( f ) {
		var b = [];
		if ( f.custom ) {
			b.push( [ C.types[ f.type ] ? C.types[ f.type ].replace( / \(.*\)$/, '' ) : f.type, '' ] );
		}
		if ( ! f.enabled ) {
			b.push( [ 'Hidden', 'is-off' ] );
		} else if ( f.required ) {
			b.push( [ 'Required', 'is-req' ] );
		} else if ( f.type !== 'paragraph' ) {
			b.push( [ 'Optional', '' ] );
		}
		if ( f.width === 'half' ) {
			b.push( [ 'Half width', '' ] );
		}
		return b.map( function ( x ) {
			return el( 'span', { class: 'cf-fbadge ' + x[ 1 ], text: x[ 0 ] } );
		} );
	}

	function rowName( f ) {
		if ( f.type === 'paragraph' ) {
			return f.content ? f.content.replace( /<[^>]+>/g, '' ).slice( 0, 60 ) : 'Text block';
		}
		return f.label || C.defaults[ f.key ] || f.key;
	}

	function fieldRow( f ) {
		var name = rowName( f );
		var li = el( 'li', { class: 'cf-frow' + ( f.enabled ? '' : ' is-hidden' ) + ( open === f.key ? ' is-open' : '' ), 'data-key': f.key } );
		var bar = el( 'div', { class: 'cf-frow-bar', draggable: 'true' }, [
			el( 'span', { class: 'cf-fgrip dashicons dashicons-move', 'aria-hidden': 'true' } ),
			el( 'button', { type: 'button', class: 'cf-fname', 'aria-expanded': open === f.key ? 'true' : 'false', onclick: function () {
				open = open === f.key ? null : f.key;
				render();
			} }, [ el( 'strong', { text: name } ), el( 'code', { text: f.custom ? f.key : ( f.key === 'email' || f.key === 'order_comments' ? f.key : 'billing/shipping_' + f.key ) } ) ] ),
			el( 'span', { class: 'cf-fbadges' }, badges( f ) )
		] );
		bar.addEventListener( 'dragstart', function ( e ) {
			drag = f.key;
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', f.key );
			li.classList.add( 'is-dragging' );
		} );
		bar.addEventListener( 'dragend', function () {
			drag = null;
			li.classList.remove( 'is-dragging' );
			clearMarks();
		} );
		li.appendChild( bar );
		if ( open === f.key ) {
			li.appendChild( editor( f ) );
		}
		return li;
	}

	/* ---------- field editor ---------- */

	function row( label, control, hint ) {
		return el( 'label', { class: 'cf-fedit-row' }, [ el( 'span', { text: label } ), control, hint ? el( 'small', { class: 'cf-muted', text: hint } ) : null ] );
	}

	function input( f, prop, attrs, onChange ) {
		var i = el( attrs && attrs.rows ? 'textarea' : 'input', Object.assign( { type: 'text' }, attrs || {} ) );
		i.value = f[ prop ] === undefined || f[ prop ] === null ? '' : f[ prop ];
		i.addEventListener( 'input', function () {
			var li = i.closest( '.cf-frow' );
			f[ prop ] = attrs && attrs.type === 'number' ? Number( i.value ) : i.value;
			dirty = true;
			if ( onChange ) {
				onChange();
			}
			// Update the row header in place: re-rendering would steal focus from the next field.
			if ( li ) {
				li.dataset.key = f.key;
				li.querySelector( '.cf-fname strong' ).textContent = rowName( f );
				if ( f.custom ) {
					li.querySelector( '.cf-fname code' ).textContent = f.key;
				}
			}
		} );
		return i;
	}

	function check( f, prop, label, disabled ) {
		var c = el( 'input', { type: 'checkbox', disabled: disabled } );
		c.checked = !! f[ prop ];
		c.addEventListener( 'change', function () {
			f[ prop ] = c.checked;
			changed();
		} );
		return el( 'label', { class: 'cf-fcheck' }, [ c, ' ' + label ] );
	}

	function editor( f ) {
		var box = el( 'div', { class: 'cf-fedit' } );
		var text = f.type === 'paragraph';
		if ( text ) {
			box.appendChild( row( 'Text', input( f, 'content', { rows: 4 } ), 'Shown as-is on the checkout. Basic HTML allowed (bold, links).' ) );
		} else {
			box.appendChild( row( 'Label', input( f, 'label', {}, function () {
				if ( f._new ) {
					var k = f.key;
					f.key = 'cf_tmp';
					f.key = uniqueKey( f.label );
					if ( open === k ) {
						open = f.key;
					}
				}
			} ), f.custom ? null : 'Default: ' + ( C.defaults[ f.key ] || f.key ) ) );
			if ( [ 'checkbox', 'radio' ].indexOf( f.type ) === -1 ) {
				box.appendChild( row( 'Placeholder', input( f, 'placeholder' ) ) );
			}
			if ( f.type === 'select' || f.type === 'radio' ) {
				box.appendChild( row( 'Options', input( f, 'options', { rows: 4 } ), 'One per line.' ) );
			}
			if ( f.type === 'date' ) {
				box.appendChild( row( 'Minimum age', input( f, 'min_age', { type: 'number', min: 0, max: 120 } ), 'For a date of birth: block orders from anyone younger. 0 = no check.' ) );
			}
		}
		var opts = el( 'div', { class: 'cf-fopts' } );
		if ( ! text ) {
			opts.appendChild( check( f, 'required', 'Required', f.key === 'email' || f.key === 'country' ) );
		}
		opts.appendChild( check( f, 'enabled', 'Show on checkout', isLocked( f ) ) );
		if ( [ 'paragraph', 'textarea' ].indexOf( f.type ) === -1 && f.key !== 'email' && f.key !== 'phone' && f.key !== 'order_comments' ) {
			var w = el( 'select', { 'aria-label': 'Width' } );
			[ [ 'full', 'Full width' ], [ 'half', 'Half width' ] ].forEach( function ( o ) {
				var op = el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
				op.selected = ( f.width || 'full' ) === o[ 0 ];
				w.appendChild( op );
			} );
			w.addEventListener( 'change', function () {
				f.width = w.value;
				changed();
			} );
			opts.appendChild( w );
		}
		if ( f.custom ) {
			opts.appendChild( el( 'button', { type: 'button', class: 'button-link cf-danger', text: 'Delete field', onclick: function () {
				if ( ! f._new && ! window.confirm( 'Delete this field? Answers already saved on orders are kept.' ) ) {
					return;
				}
				cfg.fields.splice( cfg.fields.indexOf( f ), 1 );
				open = null;
				changed();
			} } ) );
		}
		box.appendChild( opts );
		if ( ! f.custom && f.key !== 'email' && f.key !== 'order_comments' ) {
			box.appendChild( el( 'p', { class: 'cf-muted cf-fnote', text: 'Applies to the shipping and billing address.' } ) );
		}
		return box;
	}

	/* ---------- adding ---------- */

	function addField( section, type ) {
		var label = type === 'date' ? 'Date of birth' : type === 'checkbox' ? 'I agree to the terms' : type === 'paragraph' ? '' : 'New field';
		var f = {
			key: uniqueKey( type === 'paragraph' ? 'text_block' : label ),
			label: label,
			placeholder: '',
			required: type === 'date' || type === 'checkbox',
			enabled: true,
			width: 'full',
			section: section,
			type: type,
			options: type === 'select' || type === 'radio' ? 'Option 1\nOption 2' : '',
			content: type === 'paragraph' ? 'Write your text here.' : '',
			min_age: type === 'date' ? 21 : 0,
			custom: true,
			_new: true
		};
		cfg.fields.push( f );
		open = f.key;
		changed();
		var ed = app.querySelector( '.cf-frow.is-open input, .cf-frow.is-open textarea' );
		if ( ed ) {
			ed.focus();
			ed.select();
		}
	}

	function addSection() {
		var id = 'sec_' + Date.now().toString( 36 );
		cfg.sections.push( { id: id, title: 'New section', position: 'after_address' } );
		changed();
		var t = app.querySelector( '[data-section="' + id + '"] .cf-fsec-title' );
		if ( t ) {
			t.focus();
			t.select();
		}
	}

	form.addEventListener( 'submit', function () {
		dirty = false;
		document.getElementById( 'cf-fields-config' ).value = JSON.stringify( {
			sections: cfg.sections,
			fields: cfg.fields.map( function ( f ) {
				var o = Object.assign( {}, f );
				delete o._new;
				return o;
			} )
		} );
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	render();
	window.cfxFields = { config: function () {
		return cfg;
	} };
}() );
