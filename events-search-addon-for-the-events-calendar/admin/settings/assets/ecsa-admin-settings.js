
( function () {
	'use strict';

	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	var CFG = window.ecsaAdminSettings || {};

	var FILTER_STYLES       = [ 'text' ];
	var FILTER_STYLE_DEF    = 'text';

	var FILTERS_VISIBILITIES   = [ 'bar_inline', 'expanded' ];
	var FILTERS_VISIBILITY_DEF = 'expanded';

	var RESULTS_MODES    = [ 'none', 'inline' ];
	var RESULTS_MODE_DEF = 'none';

	var BAR_TEMPLATES    = [ 'detached', 'unified' ];
	var BAR_TEMPLATE_DEF = 'unified';
	var BUTTON_STYLES    = [ 'solid', 'solid_icon', 'outline', 'icon', 'text', 'none' ];

	var BUTTON_STYLE_DEF = 'solid_icon';
	var CARD_FIELDS      = [ 'image', 'title', 'date', 'venue', 'cost' ];

	var DATE_FORMATS    = [ 'site', 'long', 'medium', 'dmy', 'iso' ];
	var DATE_FORMAT_DEF = 'site';

	var TEMPLATES    = [ 'clean' ];
	var TEMPLATE_DEF = 'clean';

	var BAR_PARTS         = [ 'search', 'search_filters' ];
	var BAR_PARTS_FALLBACK = 'search_filters';

	var SCALE_MIN = 80;
	var SCALE_MAX = 140;
	var COLUMNS_MIN = 2;
	var COLUMNS_MAX = 5;

	var RADIUS_MAX = 24;
	var PER_PAGE_MIN = 1;
	var PER_PAGE_MAX = 50;

	var COLOR_DEFAULTS = {
		accent_color: '#2563eb',
		text_color: '#1f2937',
		bg_color: '#ffffff'
	};

	var ON_LIGHT = '#ffffff';
	var ON_DARK  = '#0b0b0c';

	var ACCENT_TEXT_TARGET = 4.55;

	function warn( message ) {
		if ( window.console && window.console.warn ) {
			window.console.warn( message );
		}
	}

	var SD = normalizeDefaults( CFG.siteDefaults || {} );

	var subtabs = null;

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.querySelector( '.ecsa-settings' );
		if ( ! root ) {
			return;
		}

		initSubmitGuard( root );
		initConsentTerms( root );

		var editor = root.querySelector( '.ecsa-editor' );
		if ( ! editor ) {
			return;
		}

		subtabs = initSubtabs( editor );
		initSortables( editor );
		initSliders( editor );
		initColorFields( editor );
		initColorPresets( editor );
		initCopy( editor );

		editor.addEventListener( 'change', function () { updateEditor( editor ); } );
		editor.addEventListener( 'input', function () { updateEditor( editor ); } );

		updateEditor( editor );
	} );

	function initSubmitGuard( root ) {
		slice( root.querySelectorAll( '.ecsa-settings__form' ) ).forEach( function ( form ) {
			form.addEventListener( 'submit', function () {
				window.setTimeout( function () {
					slice( form.querySelectorAll( 'button[type="submit"]' ) ).forEach( function ( b ) {
						b.disabled = true;
					} );
				}, 0 );
			} );
		} );
	}

	function initConsentTerms( root ) {
		var link = root.querySelector( '[data-ecsa-see-terms]' );
		var box = root.querySelector( '[data-ecsa-terms]' );
		if ( ! link || ! box ) {
			return;
		}
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			box.hidden = ! box.hidden;
		} );
	}

	function slice( nodeList ) {
		return Array.prototype.slice.call( nodeList || [] );
	}

	function byKey( editor, key ) {
		if ( ! editor || ! key ) {
			return null;
		}
		return editor.querySelector( '[data-ecsa-ctrl="' + key + '"]' );
	}

	function has( list, value ) {
		return !! list && -1 !== list.indexOf( value );
	}

	function inList( value, allowed, fallback ) {
		return has( allowed, value ) ? value : fallback;
	}

	function clampInt( value, min, max, fallback ) {
		var n = parseInt( value, 10 );
		if ( isNaN( n ) ) {
			n = fallback;
		}
		return Math.max( min, Math.min( max, n ) );
	}

	function cleanColor( value, allowTransparent ) {
		var v = ( null === value || undefined === value ) ? '' : String( value ).trim();
		if ( '' === v ) {
			return '';
		}
		if ( allowTransparent && 'transparent' === v.toLowerCase() ) {
			return 'transparent';
		}
		return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test( v ) ? v.toLowerCase() : '';
	}

	function classPart( value, allowed, fallback ) {
		return inList( String( value || '' ), allowed, fallback ).replace( /_/g, '-' );
	}

	function scaleRatio( value ) {
		return ( clampInt( value, SCALE_MIN, SCALE_MAX, 100 ) / 100 ).toFixed( 2 );
	}

	function normalizeDefaults( raw ) {
		var sd = {};
		var key;

		for ( key in raw ) {
			if ( Object.prototype.hasOwnProperty.call( raw, key ) ) {
				sd[ key ] = raw[ key ];
			}
		}

		sd.results_mode         = inList( String( sd.results_mode || '' ), RESULTS_MODES, RESULTS_MODE_DEF );

		sd.typeahead            = inList( String( sd.typeahead || '' ), [ 'off', 'dropdown' ], deriveTypeahead( sd.results_mode ) );
		sd.filters_visibility   = inList( String( sd.filters_visibility || '' ), FILTERS_VISIBILITIES, FILTERS_VISIBILITY_DEF );
		sd.view                 = inList( String( sd.view || '' ), [ 'grid', 'list' ], 'grid' );
		sd.filter_style         = inList( String( sd.filter_style || '' ), FILTER_STYLES, FILTER_STYLE_DEF );

		sd.bar_template         = inList( String( sd.bar_template || '' ), BAR_TEMPLATES, BAR_TEMPLATE_DEF );
		sd.button_style         = inList( String( sd.button_style || '' ), BUTTON_STYLES, BUTTON_STYLE_DEF );
		sd.date_format          = inList( String( sd.date_format || '' ), DATE_FORMATS, DATE_FORMAT_DEF );
		sd.template             = inList( String( sd.template || '' ), TEMPLATES, TEMPLATE_DEF );

		sd.design_sizing = ( 'on' === String( sd.design_sizing || '' ) ) ? 'on' : 'off';
		sd.control_size  = clampInt( sd.control_size, SCALE_MIN, SCALE_MAX, 100 );
		sd.card_size     = clampInt( sd.card_size, SCALE_MIN, SCALE_MAX, 100 );
		sd.columns       = clampInt( sd.columns, COLUMNS_MIN, COLUMNS_MAX, 3 );
		sd.corner_radius = clampInt( sd.corner_radius, 0, RADIUS_MAX, 8 );
		sd.per_page      = clampInt( sd.per_page, PER_PAGE_MIN, PER_PAGE_MAX, 12 );

		sd.accent_color = cleanColor( sd.accent_color, false ) || COLOR_DEFAULTS.accent_color;
		sd.text_color   = cleanColor( sd.text_color, false ) || COLOR_DEFAULTS.text_color;
		sd.bg_color     = cleanColor( sd.bg_color, true ) || COLOR_DEFAULTS.bg_color;

		sd.card_fields = cleanSet( sd.card_fields, CARD_FIELDS, CARD_FIELDS );
		sd.facets      = ( sd.facets && sd.facets.length ) ? slice( sd.facets ) : [];

		return sd;
	}

	function cleanSet( value, allowed, fallback ) {
		var raw = ( value && value.length ) ? slice( value ).map( String ) : [];
		var out = [];
		allowed.forEach( function ( item ) {
			if ( has( raw, item ) ) {
				out.push( item );
			}
		} );
		return out.length ? out : slice( fallback );
	}

	var SUBTAB_FALLBACK = 'compose';

	var PANEL_CONDITIONS = {
		filters_on: function ( editor ) {
			return barParts( editor ).filters;
		},
		results_placed: function ( editor ) {
			return 'none' !== enumValue( editor, 'results_mode', RESULTS_MODES, SD.results_mode || RESULTS_MODE_DEF );
		}
	};

	function conditionMet( editor, spec ) {
		var s = String( spec || '' );
		if ( '' === s ) {
			return true;
		}

		var negate = ( 0 === s.indexOf( '!' ) );
		var key = negate ? s.slice( 1 ) : s;
		var fn = PANEL_CONDITIONS[ key ];
		if ( ! fn ) {
			return true;
		}

		var value = !! fn( editor );

		return negate ? ! value : value;
	}

	var FILTERS_ON_SEED = [ 'date' ];

	function seedFacetsOnEnable( editor ) {
		var on = barParts( editor ).filters;
		var was = '1' === editor.getAttribute( 'data-ecsa-filters-were-on' );

		editor.setAttribute( 'data-ecsa-filters-were-on', on ? '1' : '0' );

		if ( ! on || was ) {
			return;
		}

		var items = slice( editor.querySelectorAll( '.ecsa-sortable__item[data-ecsa-facet]' ) );
		var anyOn = items.some( function ( li ) {
			var box = li.querySelector( 'input[type="checkbox"]' );
			return box && box.checked;
		} );

		if ( anyOn ) {
			return;
		}

		items.forEach( function ( li ) {
			if ( -1 === FILTERS_ON_SEED.indexOf( li.getAttribute( 'data-ecsa-facet' ) ) ) {
				return;
			}
			var box = li.querySelector( 'input[type="checkbox"]' );
			if ( box && ! box.checked ) {
				box.checked = true;
			}
		} );
	}

	function syncChoiceCards( editor ) {
		slice( editor.querySelectorAll( '.ecsa-cards-choice' ) ).forEach( function ( group ) {
			var cards = slice( group.querySelectorAll( '.ecsa-choice-card' ) );
			var conditional = cards.filter( function ( card ) {
				return card.hasAttribute( 'data-ecsa-when' );
			} );
			if ( ! conditional.length ) {
				return;
			}

			var moved = false;

			conditional.forEach( function ( card ) {
				var ok = conditionMet( editor, card.getAttribute( 'data-ecsa-when' ) );
				card.hidden = ! ok;

				var input = card.querySelector( 'input' );
				if ( input && ! ok && input.checked ) {
					input.checked = false;
					moved = true;
				}
			} );

			if ( ! moved ) {
				return;
			}

			for ( var i = 0; i < cards.length; i++ ) {
				if ( ! cards[ i ].hidden ) {
					var next = cards[ i ].querySelector( 'input' );

					if ( next && ! next.disabled ) {
						next.checked = true;
						return;
					}
				}
			}
		} );
	}

	function initSubtabs( editor ) {
		var buttons = slice( editor.querySelectorAll( '.ecsa-subtab-button' ) );
		var panels = slice( editor.querySelectorAll( '.ecsa-subtab-panel' ) );
		if ( ! buttons.length ) {
			return null;
		}

		var current = '';

		function keyOf( button ) {
			return button.getAttribute( 'data-ecsa-subtab' ) || '';
		}

		function buttonFor( key ) {
			for ( var i = 0; i < buttons.length; i++ ) {
				if ( keyOf( buttons[ i ] ) === key ) {
					return buttons[ i ];
				}
			}
			return null;
		}

		function available( key ) {
			var button = buttonFor( key );
			return !! button && conditionMet( editor, button.getAttribute( 'data-ecsa-when' ) );
		}

		function firstAvailable() {
			for ( var i = 0; i < buttons.length; i++ ) {
				if ( conditionMet( editor, buttons[ i ].getAttribute( 'data-ecsa-when' ) ) ) {
					return keyOf( buttons[ i ] );
				}
			}
			return '';
		}

		function activate( key ) {

			buttons.forEach( function ( b ) {
				var ok = conditionMet( editor, b.getAttribute( 'data-ecsa-when' ) );
				b.hidden = ! ok;
				if ( ok ) {
					b.removeAttribute( 'tabindex' );
				} else {
					b.setAttribute( 'tabindex', '-1' );
				}
			} );

			if ( ! available( key ) ) {
				key = available( SUBTAB_FALLBACK ) ? SUBTAB_FALLBACK : firstAvailable();
			}
			current = key;

			buttons.forEach( function ( b ) {

				var on = ( keyOf( b ) === key ) && ! b.hidden;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			panels.forEach( function ( p ) {
				var on = ( p.getAttribute( 'data-ecsa-subtab-panel' ) === key );
				p.hidden = ! on;
				p.classList.toggle( 'is-active', on );
			} );
		}

		buttons.forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				activate( keyOf( b ) );
			} );
		} );

		activate( editor.getAttribute( 'data-ecsa-open-subtab' ) || SUBTAB_FALLBACK );

		return {
			refresh: function () {
				activate( current );
			}
		};
	}

	function initSortables( editor ) {
		slice( editor.querySelectorAll( '.ecsa-sortable' ) ).forEach( function ( wrap ) {
			var list = wrap.querySelector( '.ecsa-sortable__list' );
			if ( ! list ) {
				return;
			}

			slice( wrap.querySelectorAll( '.ecsa-sortable__move' ) ).forEach( function ( m ) {
				m.hidden = false;
			} );

			slice( list.querySelectorAll( '.ecsa-sortable__item' ) ).forEach( function ( item ) {
				if ( item.hasAttribute( 'data-ecsa-pro' ) ) {
					return;
				}
				item.setAttribute( 'draggable', 'true' );
			} );

			var dragItem = null;

			list.addEventListener( 'dragstart', function ( e ) {
				var item = e.target.closest ? e.target.closest( '.ecsa-sortable__item' ) : null;
				if ( ! item || item.hasAttribute( 'data-ecsa-pro' ) ) {
					return;
				}
				dragItem = item;
				item.classList.add( 'is-dragging' );
				if ( e.dataTransfer ) {
					e.dataTransfer.effectAllowed = 'move';
				}
			} );

			list.addEventListener( 'dragover', function ( e ) {
				if ( ! dragItem ) {
					return;
				}
				e.preventDefault();
				var over = e.target.closest ? e.target.closest( '.ecsa-sortable__item' ) : null;

				if ( ! over || over === dragItem || over.hasAttribute( 'data-ecsa-pro' ) ) {
					return;
				}
				var rect = over.getBoundingClientRect();
				var after = ( e.clientY - rect.top ) / rect.height > 0.5;
				list.insertBefore( dragItem, after ? over.nextSibling : over );
			} );

			list.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
			} );

			list.addEventListener( 'dragend', function () {
				if ( dragItem ) {
					dragItem.classList.remove( 'is-dragging' );
					dragItem = null;
					updateEditor( editor );
				}
			} );

			wrap.addEventListener( 'click', function ( e ) {
				var up = e.target.closest ? e.target.closest( '.ecsa-sortable__up' ) : null;
				var down = e.target.closest ? e.target.closest( '.ecsa-sortable__down' ) : null;
				if ( ! up && ! down ) {
					return;
				}
				e.preventDefault();
				var item = e.target.closest( '.ecsa-sortable__item' );
				if ( ! item || ! item.parentNode || item.hasAttribute( 'data-ecsa-pro' ) ) {
					return;
				}

				var swap = up ? item.previousElementSibling : item.nextElementSibling;
				if ( ! swap || swap.hasAttribute( 'data-ecsa-pro' ) ) {
					return;
				}
				if ( up ) {
					item.parentNode.insertBefore( item, swap );
				} else {
					item.parentNode.insertBefore( swap, item );
				}
				updateEditor( editor );
			} );
		} );
	}

	function initSliders( editor ) {
		slice( editor.querySelectorAll( '.ecsa-slider' ) ).forEach( syncSlider );
	}

	function syncSlider( wrap ) {
		var range = wrap.querySelector( '.ecsa-slider__range' ) || wrap.querySelector( 'input[type="range"]' );
		var out = wrap.querySelector( '.ecsa-slider__out' ) || wrap.querySelector( 'output' );
		if ( ! range || ! out ) {
			return;
		}
		var unit = wrap.querySelector( '.ecsa-slider__unit' );
		var suffix = unit ? '' : ( wrap.getAttribute( 'data-ecsa-suffix' ) || '' );
		out.textContent = range.value + suffix;
	}

	function initColorFields( editor ) {
		slice( editor.querySelectorAll( '.ecsa-color-field' ) ).forEach( syncColorField );
	}

	function syncColorField( el ) {
		if ( ! el || ! el.querySelector ) {
			return;
		}

		var custom = el.querySelector( '[data-ecsa-color-custom]' );
		var trans = el.querySelector( '[data-ecsa-color-transparent]' );
		var picker = el.querySelector( '[data-ecsa-color-picker]' ) || el.querySelector( 'input[type="color"]' );
		var hidden = el.querySelector( '[data-ecsa-color-value]' );

		if ( ! hidden ) {

			return;
		}

		var on = custom ? custom.checked : true;
		var isTrans = trans ? trans.checked : false;

		if ( ! on ) {
			hidden.value = '';
			if ( picker ) { picker.disabled = true; }
			if ( trans ) { trans.disabled = true; }
		} else if ( isTrans ) {
			hidden.value = 'transparent';
			if ( picker ) { picker.disabled = true; }
			if ( trans ) { trans.disabled = false; }
		} else {
			hidden.value = picker ? picker.value : hidden.value;
			if ( picker ) { picker.disabled = false; }
			if ( trans ) { trans.disabled = false; }
		}
	}

	function initColorPresets( editor ) {
		var group = editor.querySelector( '[data-ecsa-presets]' );
		if ( ! group ) {
			return;
		}

		group.addEventListener( 'click', function ( e ) {
			var btn = e.target && e.target.closest ? e.target.closest( '[data-ecsa-preset]' ) : null;
			if ( ! btn || ! group.contains( btn ) ) {
				return;
			}
			applyPreset( editor, btn );
		} );
	}

	function applyPreset( editor, btn ) {
		var wrote = setColorControl( editor, 'accent_color', btn.getAttribute( 'data-ecsa-preset-accent' ) );
		wrote = setColorControl( editor, 'text_color', btn.getAttribute( 'data-ecsa-preset-text' ) ) || wrote;
		wrote = setColorControl( editor, 'bg_color', btn.getAttribute( 'data-ecsa-preset-bg' ) ) || wrote;

		if ( ! wrote ) {
			return;
		}

		if ( ! fireInput( btn ) ) {

			updateEditor( editor );
		}
	}

	function setColorControl( editor, key, hex ) {
		var el = byKey( editor, key );
		var value = cleanColor( hex, false );

		if ( ! el || ! value || ! el.querySelector ) {
			return false;
		}

		var picker = el.querySelector( '[data-ecsa-color-picker]' ) || el.querySelector( 'input[type="color"]' );
		if ( picker ) {
			picker.value = value;
		}

		var hidden = el.querySelector( '[data-ecsa-color-value]' );
		if ( hidden ) {
			hidden.value = value;
		}

		return !! ( picker || hidden );
	}

	function fireInput( el ) {
		var evt = null;

		try {
			evt = new window.Event( 'input', { bubbles: true } );
		} catch ( e ) {
			if ( document.createEvent ) {
				evt = document.createEvent( 'Event' );
				evt.initEvent( 'input', true, false );
			}
		}

		if ( ! evt ) {
			return false;
		}

		el.dispatchEvent( evt );

		return true;
	}

	function syncPresets( editor, cfg ) {
		var group = editor.querySelector( '[data-ecsa-presets]' );
		if ( ! group ) {
			return;
		}

		var now = [
			normHex( cfg.accent_color ),
			normHex( cfg.text_color ),
			normHex( cfg.bg_color )
		];

		slice( group.querySelectorAll( '[data-ecsa-preset]' ) ).forEach( function ( btn ) {
			var on = now[ 0 ] === normHex( btn.getAttribute( 'data-ecsa-preset-accent' ) ) &&
				now[ 1 ] === normHex( btn.getAttribute( 'data-ecsa-preset-text' ) ) &&
				now[ 2 ] === normHex( btn.getAttribute( 'data-ecsa-preset-bg' ) );

			btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );

			if ( on ) {
				btn.classList.add( 'is-current' );
			} else {
				btn.classList.remove( 'is-current' );
			}
		} );
	}

	function normHex( value ) {
		var v = cleanColor( value, false );
		var m = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/.exec( v );

		if ( m ) {
			return '#' + m[ 1 ] + m[ 1 ] + m[ 2 ] + m[ 2 ] + m[ 3 ] + m[ 3 ];
		}

		return v;
	}

	function readControl( el ) {
		if ( ! el ) {
			return null;
		}

		var out = { type: '', value: '', list: [], map: {} };

		try {
			out.type = el.getAttribute ? ( el.getAttribute( 'data-ecsa-type' ) || '' ) : '';

			switch ( out.type ) {
				case 'segmented':
				case 'cards':
				case 'radiocards':
				case 'radio':
					out.value = readCheckedRadio( el );
					break;

				case 'select':
				case 'text':
				case 'number':

					out.value = readFieldValue( el );
					break;

				case 'color':
					out.value = readColorValue( el );
					break;

				case 'toggle':
					out.value = readToggle( el );
					break;

				case 'slider':
					out.value = readRange( el );
					break;

				case 'chips':
				case 'checkgrid':
					out.list = readCheckedList( el );
					break;

				case 'sortable':
					out.list = readSortable( el );
					break;

				default:
					inferControl( el, out );
					break;
			}
		} catch ( e ) {

		}

		return out;
	}

	function readCheckedRadio( el ) {
		if ( el.querySelector ) {
			var checked = el.querySelector( 'input:checked' );
			if ( checked ) {
				return checked.value;
			}
		}
		return '';
	}

	function readFieldValue( el ) {
		if ( undefined !== el.value && ! el.querySelector ) {
			return el.value;
		}
		if ( 'INPUT' === el.tagName || 'SELECT' === el.tagName || 'TEXTAREA' === el.tagName ) {
			return el.value;
		}
		var field = el.querySelector ? el.querySelector( 'input, select, textarea' ) : null;
		return field ? field.value : '';
	}

	function readColorValue( el ) {
		if ( 'INPUT' === el.tagName ) {
			return el.value;
		}
		if ( ! el.querySelector ) {
			return '';
		}
		var hidden = el.querySelector( '[data-ecsa-color-value]' );
		if ( hidden ) {
			return hidden.value;
		}
		var picker = el.querySelector( '[data-ecsa-color-picker]' ) || el.querySelector( 'input[type="color"]' ) || el.querySelector( 'input' );
		return picker ? picker.value : '';
	}

	function readToggle( el ) {
		if ( 'INPUT' === el.tagName ) {
			return !! el.checked;
		}
		var box = el.querySelector ? el.querySelector( 'input[type="checkbox"]' ) : null;
		return box ? !! box.checked : false;
	}

	function readRange( el ) {
		if ( 'INPUT' === el.tagName ) {
			return el.value;
		}
		var range = el.querySelector ? ( el.querySelector( '.ecsa-slider__range' ) || el.querySelector( 'input[type="range"]' ) ) : null;
		return range ? range.value : '';
	}

	function readCheckedList( el ) {
		if ( ! el.querySelector ) {
			return [];
		}
		return slice( el.querySelectorAll( 'input[type="checkbox"]:checked' ) ).map( function ( b ) {
			return b.value;
		} );
	}

	function readSortable( el ) {
		if ( ! el.querySelector ) {
			return [];
		}
		return slice( el.querySelectorAll( '.ecsa-sortable__item' ) ).filter( function ( item ) {
			var cb = item.querySelector( 'input[type="checkbox"]' );
			return cb && cb.checked;
		} ).map( function ( item ) {
			return item.getAttribute( 'data-ecsa-facet' ) || '';
		} ).filter( function ( v ) {
			return '' !== v;
		} );
	}

	function inferControl( el, out ) {
		var tag = el.tagName || '';

		if ( 'INPUT' === tag || 'SELECT' === tag || 'TEXTAREA' === tag ) {
			out.value = ( 'checkbox' === el.type || 'radio' === el.type ) ? !! el.checked : el.value;
			return;
		}

		if ( ! el.querySelector ) {
			return;
		}

		var range = el.querySelector( 'input[type="range"]' );
		if ( range ) {
			out.value = range.value;
			return;
		}

		var radios = el.querySelectorAll( 'input[type="radio"]' );
		if ( radios.length ) {
			var checked = el.querySelector( 'input[type="radio"]:checked' );
			out.value = checked ? checked.value : '';
			return;
		}

		var boxes = slice( el.querySelectorAll( 'input[type="checkbox"]' ) );
		if ( boxes.length > 1 ) {
			out.list = readCheckedList( el );
			return;
		}
		if ( 1 === boxes.length ) {
			out.value = !! boxes[ 0 ].checked;
			return;
		}

		var hidden = el.querySelector( '[data-ecsa-color-value]' );
		if ( hidden ) {
			out.value = hidden.value;
			return;
		}

		var field = el.querySelector( 'input, select, textarea' );
		out.value = field ? field.value : '';
	}

	function ctrlValue( editor, key, fallback ) {
		var c = readControl( byKey( editor, key ) );
		if ( ! c ) {
			return fallback;
		}
		return ( '' === c.value && undefined !== fallback ) ? fallback : c.value;
	}

	function enumValue( editor, key, allowed, fallback ) {
		return inList( String( ctrlValue( editor, key, fallback ) ), allowed, fallback );
	}

	function intValue( editor, key, min, max, fallback ) {
		return clampInt( ctrlValue( editor, key, fallback ), min, max, fallback );
	}

	function colorValue( editor, key, allowTransparent, fallback ) {
		var el = byKey( editor, key );
		if ( ! el ) {
			return fallback;
		}
		var c = readControl( el );
		var v = cleanColor( c ? c.value : '', allowTransparent );
		return v || fallback;
	}

	function barParts( editor ) {
		var v = enumValue( editor, 'bar_parts', BAR_PARTS, BAR_PARTS_FALLBACK );

		return {
			search: true,
			filters: 'search' !== v
		};
	}

	function setValue( editor, key, allowed, sdList ) {
		var el = byKey( editor, key );
		if ( ! el ) {
			return cleanSet( sdList, allowed, allowed );
		}
		var c = readControl( el );
		return cleanSet( c ? c.list : [], allowed, allowed );
	}

	function collectConfig( editor ) {
		var cfg = {};

		var where = enumValue( editor, 'results_mode', RESULTS_MODES, SD.results_mode || RESULTS_MODE_DEF );
		cfg.results_mode = where;

		cfg.typeahead = deriveTypeahead( where );

		cfg.filters_visibility = enumValue( editor, 'filters_visibility', FILTERS_VISIBILITIES, SD.filters_visibility || FILTERS_VISIBILITY_DEF );

		cfg.view = enumValue( editor, 'view', [ 'grid', 'list' ], SD.view || 'grid' );

		var ph = readControl( byKey( editor, 'placeholder' ) );
		cfg.placeholder = ( ph && '' !== String( ph.value ).trim() ) ? String( ph.value ) : ( SD.placeholder || '' );

		cfg.filter_style = enumValue( editor, 'filter_style', FILTER_STYLES, SD.filter_style || FILTER_STYLE_DEF );

		cfg.bar_template = enumValue( editor, 'bar_template', BAR_TEMPLATES, SD.bar_template || BAR_TEMPLATE_DEF );
		cfg.button_style = enumValue( editor, 'button_style', BUTTON_STYLES, SD.button_style || BUTTON_STYLE_DEF );
		cfg.accent_color = colorValue( editor, 'accent_color', false, SD.accent_color );
		cfg.text_color = colorValue( editor, 'text_color', false, SD.text_color );
		cfg.bg_color = colorValue( editor, 'bg_color', true, SD.bg_color );
		cfg.design_sizing = enumValue( editor, 'design_sizing', [ 'off', 'on' ], SD.design_sizing || 'off' );
		cfg.control_size = intValue( editor, 'control_size', SCALE_MIN, SCALE_MAX, SD.control_size );
		cfg.corner_radius = intValue( editor, 'corner_radius', 0, RADIUS_MAX, SD.corner_radius );

		cfg.columns = intValue( editor, 'columns', COLUMNS_MIN, COLUMNS_MAX, SD.columns );
		cfg.card_size = intValue( editor, 'card_size', SCALE_MIN, SCALE_MAX, SD.card_size );
		cfg.card_fields = setValue( editor, 'card_fields', CARD_FIELDS, SD.card_fields );

		cfg.date_format = enumValue( editor, 'date_format', DATE_FORMATS, SD.date_format || DATE_FORMAT_DEF );

		cfg.template = enumValue( editor, 'template', TEMPLATES, SD.template || TEMPLATE_DEF );
		cfg.per_page = intValue( editor, 'per_page', PER_PAGE_MIN, PER_PAGE_MAX, SD.per_page );

		var sortC = readControl( byKey( editor, 'facets' ) );
		var sortFacets = sortC ? sortC.list.slice() : slice( SD.facets );
		var parts = barParts( editor );
		var filtersOn = parts.filters;

		sortFacets = sortFacets.filter( function ( f ) { return 'search' !== f; } );

		cfg.facets = [ 'search' ];
		if ( filtersOn ) {
			cfg.facets = cfg.facets.concat( sortFacets );
		}

		cfg.role = 'bar';
		cfg.preview_results = ( 'none' !== where || filtersOn );

		return cfg;
	}

	function deriveTypeahead( resultsMode ) {
		return ( 'inline' === String( resultsMode ) ) ? 'off' : 'dropdown';
	}

	function updateEditor( editor ) {

		seedFacetsOnEnable( editor );
		syncChoiceCards( editor );
		if ( subtabs ) {
			subtabs.refresh();
		}

		slice( editor.querySelectorAll( '.ecsa-slider' ) ).forEach( syncSlider );
		slice( editor.querySelectorAll( '.ecsa-color-field' ) ).forEach( syncColorField );
		updateReveals( editor );

		var cfg = collectConfig( editor );
		syncPresets( editor, cfg );
		renderPreview( editor, cfg );
		updateShortcode( editor, cfg );
	}

	function designClass( cfg ) {
		var cls = 'ecsa ecsa--tpl-' + classPart( cfg.bar_template, BAR_TEMPLATES, BAR_TEMPLATE_DEF ) +
			' ecsa--btn-' + classPart( cfg.button_style, BUTTON_STYLES, BUTTON_STYLE_DEF ) +
			' ecsa--trg-' + classPart( cfg.filter_style, FILTER_STYLES, FILTER_STYLE_DEF ) +
			' ecsa--fbtn-icon-text-bordered';

		var bg = ( cfg.bg_color || '' ).trim();
		if ( bg && 'transparent' !== bg ) {
			cls += ( ON_LIGHT === onAccent( bg ) ) ? ' ecsa--scheme-dark' : ' ecsa--scheme-light';
		}

		return cls;
	}

	function designStyle( cfg ) {
		var parts = [];

		var sizing = ( 'on' === cfg.design_sizing );
		var radius = sizing ? clampInt( cfg.corner_radius, 0, RADIUS_MAX, 8 ) : 8;

		parts.push( '--ecsa-radius:' + radius + 'px' );
		parts.push( '--ecsa-scale:' + ( sizing ? scaleRatio( cfg.control_size ) : scaleRatio( 100 ) ) );
		parts.push( '--ecsa-card-scale:' + ( sizing ? scaleRatio( cfg.card_size ) : scaleRatio( 100 ) ) );

		parts.push( '--ecsa-columns:' + clampInt( cfg.columns, COLUMNS_MIN, COLUMNS_MAX, 3 ) );

		var rr = ( radius / 8 ).toFixed( 3 );

		if ( '1.000' !== rr ) {
			parts.push( '--ecsa-rr:' + rr );
		}

		if ( sizing && radius < 8 ) {
			parts.push( '--ecsa-sizing:1' );
		}

		var accent = cleanColor( cfg.accent_color, false );
		if ( accent ) {
			parts.push( '--ecsa-accent:' + accent );
		}
		var text = cleanColor( cfg.text_color, false );
		if ( text ) {
			parts.push( '--ecsa-text:' + text );
		}
		var bg = cleanColor( cfg.bg_color, true );
		if ( bg ) {
			parts.push( '--ecsa-bg:' + bg );
		}

		parts.push( '--ecsa-on-accent:' + onAccent( accent || COLOR_DEFAULTS.accent_color ) );

		parts.push(
			'--ecsa-accent-text:' + accentText(
				accent || COLOR_DEFAULTS.accent_color,
				text || COLOR_DEFAULTS.text_color,
				( bg && 'transparent' !== bg ) ? bg : COLOR_DEFAULTS.bg_color
			)
		);

		return parts.join( ';' );
	}

	function hexToRgb( hex ) {
		var h = String( hex || '' ).replace( /^#/, '' );

		if ( 3 === h.length ) {
			h = h.charAt( 0 ) + h.charAt( 0 ) + h.charAt( 1 ) + h.charAt( 1 ) + h.charAt( 2 ) + h.charAt( 2 );
		}
		if ( ! /^[0-9a-f]{6}$/i.test( h ) ) {
			return [ 0, 0, 0 ];
		}

		return [
			parseInt( h.substr( 0, 2 ), 16 ),
			parseInt( h.substr( 2, 2 ), 16 ),
			parseInt( h.substr( 4, 2 ), 16 )
		];
	}

	function luminance( hex ) {
		var rgb = hexToRgb( hex );
		var lin = [];

		for ( var i = 0; i < 3; i++ ) {
			var c = rgb[ i ] / 255;
			lin.push( c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
		}

		return ( 0.2126 * lin[ 0 ] ) + ( 0.7152 * lin[ 1 ] ) + ( 0.0722 * lin[ 2 ] );
	}

	function contrastRatio( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );

		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function onAccent( hex ) {
		return contrastRatio( hex, ON_LIGHT ) >= contrastRatio( hex, ON_DARK ) ? ON_LIGHT : ON_DARK;
	}

	function mixColor( a, b, t ) {
		var ra = hexToRgb( a );
		var rb = hexToRgb( b );
		var out = '#';

		for ( var i = 0; i < 3; i++ ) {
			var c = Math.max( 0, Math.min( 255, Math.round( ( ra[ i ] * t ) + ( rb[ i ] * ( 1 - t ) ) ) ) );
			out += ( c < 16 ? '0' : '' ) + c.toString( 16 );
		}

		return out;
	}

	function accentText( accent, text, bg ) {
		var backdrops = [
			bg,
			mixColor( accent, bg, 0.08 ),
			mixColor( accent, bg, 0.14 ),
			mixColor( text, bg, 0.05 ),
			mixColor( text, bg, 0.09 )
		];

		for ( var step = 100; step >= 0; step-- ) {
			var candidate = mixColor( accent, text, step / 100 );
			var clears = true;

			for ( var i = 0; i < backdrops.length; i++ ) {
				if ( contrastRatio( candidate, backdrops[ i ] ) < ACCENT_TEXT_TARGET ) {
					clears = false;
					break;
				}
			}

			if ( clears ) {
				return candidate;
			}
		}

		return mixColor( accent, text, 0 );
	}

	var preview = {

		seq: 0,
		timer: null,

		painted: false,
		structSig: null,

		cls: 'ecsa',
		style: ''
	};

	function renderPreview( editor, cfg ) {
		var stage = editor.querySelector( '.ecsa-preview-stage' );
		var host = editor.querySelector( '[data-ecsa-preview]' );

		preview.cls = designClass( cfg );
		preview.style = designStyle( cfg );

		if ( stage ) {
			stage.setAttribute( 'style', preview.style );
		}

		stampPreview( host );

		var structSig = structureSignature( cfg );
		if ( structSig === preview.structSig && preview.painted ) {

			return;
		}

		preview.structSig = structSig;

		if ( preview.timer ) {
			window.clearTimeout( preview.timer );
		}
		preview.timer = window.setTimeout( function () {
			fetchPreview( editor, cfg );
		}, 250 );
	}

	function structureSignature( cfg ) {
		return JSON.stringify( [

			cfg.preview_results,
			cfg.results_mode,
			cfg.typeahead,
			cfg.filters_visibility,
			cfg.view,
			cfg.placeholder,
			cfg.per_page,

			cfg.date_format,

			cfg.template,
			slice( cfg.facets ),
			slice( cfg.card_fields )
		] );
	}

	function stampPreview( host ) {
		if ( ! host ) {
			return;
		}
		host.className = preview.cls;
		if ( preview.style ) {
			host.setAttribute( 'style', preview.style );
		} else {
			host.removeAttribute( 'style' );
		}
	}

	function fetchPreview( editor, cfg ) {
		var host = editor.querySelector( '[data-ecsa-preview]' );
		var settings = window.ecsaAdminSettings || {};

		if ( ! host || ! settings.previewUrl || ! window.fetch ) {
			return;
		}

		var seq = ++preview.seq;
		host.setAttribute( 'aria-busy', 'true' );

		window.fetch( settings.previewUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.restNonce || ''
			},

			body: JSON.stringify( { config: cfg, preview_results: !! cfg.preview_results } )
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {

					warn( 'ECSA: the settings preview was rejected (HTTP ' + response.status + '); showing the last good render.' );
					return null;
				}
				return response.json();
			} )
			.then( function ( data ) {

				if ( seq !== preview.seq || ! data || ! data.html ) {
					return;
				}
				paintPreview( host, data );
				preview.painted = true;
			} )
			.catch( function () {

			} )
			.then( function () {
				if ( seq === preview.seq ) {
					host.removeAttribute( 'aria-busy' );
				}
			} );
	}

	function paintPreview( host, data ) {

		host.setAttribute( 'data-ecsa-preview-results', data.results ? '1' : '0' );

		host.className = data.class || 'ecsa';
		if ( data.style ) {
			host.setAttribute( 'style', data.style );
		} else {
			host.removeAttribute( 'style' );
		}
		host.innerHTML = data.html;

		if ( host.className !== preview.cls || ( host.getAttribute( 'style' ) || '' ) !== preview.style ) {
			stampPreview( host );
		}
	}

	function updateReveals( editor ) {
		slice( editor.querySelectorAll( '[data-ecsa-reveal-when]' ) ).forEach( function ( el ) {
			var spec = String( el.getAttribute( 'data-ecsa-reveal-when' ) || '' ).split( '=' );
			var key = spec[ 0 ];
			var wants = String( spec.length > 1 ? spec[ 1 ] : '' ).split( '|' );
			var val = ctrlValue( editor, key, '' );
			el.hidden = ! revealMatches( val, wants );
		} );
	}

	function revealMatches( value, wants ) {
		var s;
		if ( true === value ) {
			s = '1';
		} else if ( false === value ) {
			s = '0';
		} else {
			s = String( value );
		}

		for ( var i = 0; i < wants.length; i++ ) {
			var want = wants[ i ];
			if ( want === s ) {
				return true;
			}
			if ( '1' === s && ( 'true' === want || 'yes' === want || 'on' === want ) ) {
				return true;
			}
			if ( '0' === s && ( 'false' === want || 'no' === want || 'off' === want ) ) {
				return true;
			}
		}

		return false;
	}

	function sanitizeAttr( value ) {
		return String( ( undefined === value || null === value ) ? '' : value ).replace( /["\[\]]/g, '' ).trim();
	}

	function diffAttr( bucket, attrName, value, sdValue ) {
		if ( undefined === value || null === value || '' === value ) {
			return;
		}
		if ( String( value ) === String( sdValue ) ) {
			return;
		}
		var clean = sanitizeAttr( value );
		if ( '' === clean ) {
			return;
		}
		bucket.push( attrName + '="' + clean + '"' );
	}

	function csv( list ) {
		return slice( list ).join( ',' );
	}

	function updateShortcode( editor, cfg ) {
		var barAttrs = [];
		var resultsAttrs = [];

		diffAttr( barAttrs, 'results-mode', cfg.results_mode, SD.results_mode );
		diffAttr( barAttrs, 'typeahead', cfg.typeahead, SD.typeahead );
		diffAttr( barAttrs, 'filters-visibility', cfg.filters_visibility, SD.filters_visibility );

		diffAttr( barAttrs, 'filter-style', cfg.filter_style, SD.filter_style );

		diffAttr( barAttrs, 'bar-template', cfg.bar_template, SD.bar_template );
		diffAttr( barAttrs, 'accent-color', cfg.accent_color, SD.accent_color );
		diffAttr( barAttrs, 'text-color', cfg.text_color, SD.text_color );
		diffAttr( barAttrs, 'bg-color', cfg.bg_color, SD.bg_color );
		diffAttr( barAttrs, 'button-style', cfg.button_style, SD.button_style );
		diffAttr( barAttrs, 'design-sizing', cfg.design_sizing, SD.design_sizing );

		if ( 'on' === String( cfg.design_sizing ) ) {
			diffAttr( barAttrs, 'control-size', cfg.control_size, SD.control_size );
			diffAttr( barAttrs, 'corner-radius', cfg.corner_radius, SD.corner_radius );
		}

		var ph = readControl( byKey( editor, 'placeholder' ) );
		if ( ph && String( ph.value ).trim() !== String( SD.placeholder || '' ).trim() ) {
			var pv = sanitizeAttr( ph.value );
			if ( pv ) {
				barAttrs.push( 'placeholder="' + pv + '"' );
			}
		}

		var sf = readControl( byKey( editor, 'search_fields' ) );
		if ( sf && sf.list.length ) {
			var sfCsv = csv( sf.list.slice().sort() );
			var sdSf = csv( slice( SD.search_fields ).sort() );
			if ( sfCsv !== sdSf ) {
				barAttrs.push( 'search-fields="' + sanitizeAttr( csv( sf.list ) ) + '"' );
			}
		}

		diffAttr( resultsAttrs, 'view', cfg.view, SD.view );

		var pp = readControl( byKey( editor, 'per_page' ) );
		if ( pp && '' !== pp.value ) {
			var n = parseInt( pp.value, 10 );
			if ( ! isNaN( n ) && n > 0 && n !== parseInt( SD.per_page, 10 ) ) {
				resultsAttrs.push( 'per-page="' + n + '"' );
			}
		}

		if ( 'grid' === cfg.view ) {
			diffAttr( resultsAttrs, 'columns', cfg.columns, SD.columns );
		}
		diffAttr( resultsAttrs, 'card-size', cfg.card_size, SD.card_size );

		var cardCsv = csv( cfg.card_fields );
		if ( cardCsv !== csv( SD.card_fields ) ) {
			resultsAttrs.push( 'card-fields="' + sanitizeAttr( cardCsv ) + '"' );
		}

		diffAttr( resultsAttrs, 'date-format', cfg.date_format, SD.date_format );

		diffAttr( resultsAttrs, 'template', cfg.template, SD.template );

		diffAttr( resultsAttrs, 'accent-color', cfg.accent_color, SD.accent_color );
		diffAttr( resultsAttrs, 'text-color', cfg.text_color, SD.text_color );
		diffAttr( resultsAttrs, 'bg-color', cfg.bg_color, SD.bg_color );

		if ( 'on' === String( cfg.design_sizing ) ) {
			diffAttr( resultsAttrs, 'control-size', cfg.control_size, SD.control_size );
			diffAttr( resultsAttrs, 'corner-radius', cfg.corner_radius, SD.corner_radius );
		}

		var parts = barParts( editor );
		var filtersOn = parts.filters;

		var where = inList( String( cfg.results_mode || '' ), RESULTS_MODES, RESULTS_MODE_DEF );
		var resultsOn = ( 'none' !== where );

		var facetsCsv = csv( cfg.facets );
		var defFacets = csv( SD.facets );
		if ( facetsCsv !== defFacets ) {
			barAttrs.push( 'facets="' + sanitizeAttr( facetsCsv || 'search' ) + '"' );
		}

		var lines = [];
		var isPair = false;
		var target = 'ecsa-results-1';

		var needsPair = ( resultsOn || filtersOn );

		if ( needsPair ) {
			isPair = true;
			lines.push( assemble( 'events-calendar-search', [ 'target="' + target + '"' ].concat( barAttrs ) ) );
			lines.push( assemble( 'events-calendar-search-results', [ 'target="' + target + '"' ].concat( resultsAttrs ) ) );
		} else {

			lines.push( assemble( 'events-calendar-search', barAttrs ) );
		}

		paintShortcodes( editor, lines );

		var note = editor.querySelector( '[data-ecsa-pair-note]' );
		if ( note ) {
			note.hidden = ! isPair;
		}

	}

	function paintShortcodes( editor, lines ) {
		var boxes = slice( editor.querySelectorAll( '[data-ecsa-shortcode]' ) );

		if ( ! boxes.length ) {
			return;
		}

		var both = editor.querySelector( '[data-ecsa-copy-both]' );
		if ( both ) {
			both.hidden = ( lines.length < 2 );
		}

		if ( 1 === boxes.length ) {
			paintCode( boxes[ 0 ], lines.join( '\n' ) );
			return;
		}

		var byTag = {
			results: lines.filter( isResultsTag )[ 0 ] || '',
			bar: lines.filter( function ( line ) { return ! isResultsTag( line ); } )[ 0 ] || ''
		};

		boxes.forEach( function ( box ) {
			var item = closestItem( box );
			var key = item ? ( item.getAttribute( 'data-ecsa-shortcode-item' ) || '' ) : '';
			var line = Object.prototype.hasOwnProperty.call( byTag, key ) ? byTag[ key ] : '';

			paintCode( box, line );
			if ( item ) {
				item.hidden = ( '' === line );
			}
		} );
	}

	function scSpan( cls, text ) {
		var el = document.createElement( 'span' );
		el.className = cls;
		el.appendChild( document.createTextNode( text ) );
		return el;
	}

	function paintCode( box, line ) {
		while ( box.firstChild ) {
			box.removeChild( box.firstChild );
		}

		if ( ! line ) {
			return;
		}

		var parts = String( line ).split( '\n' );
		parts.forEach( function ( part, i ) {
			if ( i ) {
				box.appendChild( document.createTextNode( '\n' ) );
			}
			paintOne( box, part );
		} );
	}

	function paintOne( box, line ) {
		var head = /^(\[)([A-Za-z0-9_-]+)/.exec( line );

		if ( ! head ) {
			box.appendChild( document.createTextNode( line ) );
			return;
		}

		box.appendChild( scSpan( 'ecsa-sc-punct', head[ 1 ] ) );
		box.appendChild( scSpan( 'ecsa-sc-tag', head[ 2 ] ) );

		var rest = line.slice( head[ 0 ].length );
		var trailing = '';
		if ( ']' === rest.charAt( rest.length - 1 ) ) {
			trailing = ']';
			rest = rest.slice( 0, -1 );
		}

		var re = /(\s+)([A-Za-z0-9_-]+)(=)(")([^"]*)(")/g;
		var last = 0;
		var m;

		while ( null !== ( m = re.exec( rest ) ) ) {
			if ( m.index > last ) {
				box.appendChild( document.createTextNode( rest.slice( last, m.index ) ) );
			}
			box.appendChild( document.createTextNode( m[ 1 ] ) );
			box.appendChild( scSpan( 'ecsa-sc-attr', m[ 2 ] ) );
			box.appendChild( scSpan( 'ecsa-sc-punct', m[ 3 ] ) );
			box.appendChild( scSpan( 'ecsa-sc-quote', m[ 4 ] ) );
			box.appendChild( scSpan( 'ecsa-sc-val', m[ 5 ] ) );
			box.appendChild( scSpan( 'ecsa-sc-quote', m[ 6 ] ) );
			last = m.index + m[ 0 ].length;
		}

		if ( last < rest.length ) {
			box.appendChild( document.createTextNode( rest.slice( last ) ) );
		}

		if ( trailing ) {
			box.appendChild( scSpan( 'ecsa-sc-punct', trailing ) );
		}
	}

	function isResultsTag( line ) {
		return 0 === String( line ).indexOf( '[events-calendar-search-results' );
	}

	function closestItem( el ) {
		var node = el;
		while ( node && node.getAttribute ) {
			if ( null !== node.getAttribute( 'data-ecsa-shortcode-item' ) ) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function assemble( tag, attrs ) {
		return '[' + tag + ( attrs.length ? ' ' + attrs.join( ' ' ) : '' ) + ']';
	}

	function initCopy( editor ) {
		var status = editor.querySelector( '[data-ecsa-copy-status]' );

		slice( editor.querySelectorAll( '[data-ecsa-copy]' ) ).forEach( function ( button ) {
			var item = closestItem( button );
			var output = item
				? item.querySelector( '[data-ecsa-shortcode]' )
				: editor.querySelector( '[data-ecsa-shortcode]' );

			if ( output ) {
				bindCopy( button, function () {
					return output.textContent || '';
				}, status );
			}
		} );

		var both = editor.querySelector( '[data-ecsa-copy-both]' );
		if ( both ) {
			bindCopy( both, function () { return visibleShortcodes( editor ); }, status );
		}

		var previewCopy = editor.querySelector( '[data-ecsa-copy-visible]' );
		if ( previewCopy ) {
			bindCopy( previewCopy, function () { return visibleShortcodes( editor ); }, status );
		}
	}

	function visibleShortcodes( editor ) {
		return slice( editor.querySelectorAll( '[data-ecsa-shortcode]' ) )
			.filter( function ( box ) {
				var item = closestItem( box );
				return ( ! item || ! item.hidden ) && '' !== ( box.textContent || '' );
			} )
			.map( function ( box ) { return box.textContent; } )
			.join( '\n\n' );
	}

	function bindCopy( button, read, status ) {
		var label = button.querySelector( '[data-ecsa-copy-label]' );
		var original = label ? label.textContent : '';
		var timer = null;

		function announce() {
			if ( label ) {
				label.textContent = __( 'Copied', 'events-search-addon-for-the-events-calendar' );
				button.className += ' is-copied';
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					label.textContent = original;
					button.className = button.className.replace( / ?is-copied/, '' );
				}, 2000 );
			}
			if ( status ) {
				status.textContent = __( 'Copied to clipboard.', 'events-search-addon-for-the-events-calendar' );
				window.setTimeout( function () { status.textContent = ''; }, 2500 );
			}
		}

		function fail() {
			if ( status ) {
				status.textContent = __( 'Could not copy — select the shortcode and copy it manually.', 'events-search-addon-for-the-events-calendar' );
				window.setTimeout( function () { status.textContent = ''; }, 4000 );
			}
		}

		function fallbackCopy( text ) {
			var ta = document.createElement( 'textarea' );
			var ok = false;
			ta.value = text;
			ta.setAttribute( 'readonly', 'readonly' );
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( ta );
			return ok;
		}

		button.addEventListener( 'click', function () {
			var text = read();

			if ( ! text ) {
				return;
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( announce, function () {
					if ( fallbackCopy( text ) ) {
						announce();
					} else {
						fail();
					}
				} );
				return;
			}

			if ( fallbackCopy( text ) ) {
				announce();
			} else {
				fail();
			}
		} );
	}

	var PV_WIDTH = 1100;
	var PV_MIN = 0.6;

	function fitPreviewStage() {
		var sizer = document.querySelector( '[data-ecsa-preview-sizer]' );
		var stage = document.querySelector( '[data-ecsa-preview-stage]' );
		if ( ! sizer || ! stage ) {
			return;
		}

		var host = sizer.parentNode;
		if ( ! host ) {
			return;
		}

		var hostCs = window.getComputedStyle( host );
		var pane = host.clientWidth
			- ( parseFloat( hostCs.paddingLeft ) || 0 )
			- ( parseFloat( hostCs.paddingRight ) || 0 );

		if ( pane <= 0 ) {
			return;
		}

		var scale = Math.min( 1, pane / PV_WIDTH );
		if ( scale < PV_MIN ) {
			scale = PV_MIN;
		}

		stage.style.setProperty( '--ecsa-pv-w', PV_WIDTH + 'px' );
		stage.style.setProperty( '--ecsa-pv-scale', String( scale ) );

		sizer.style.setProperty( '--ecsa-pv-h', ( stage.offsetHeight * scale ) + 'px' );

	}

	if ( window.ResizeObserver ) {
		var pvRo = new ResizeObserver( function () {
			fitPreviewStage();
		} );
		var pvSizer = document.querySelector( '[data-ecsa-preview-sizer]' );
		var pvStage = document.querySelector( '[data-ecsa-preview-stage]' );
		if ( pvSizer && pvSizer.parentNode ) {
			pvRo.observe( pvSizer.parentNode );
		}

		if ( pvStage ) {
			pvRo.observe( pvStage );
		}
	} else {
		window.addEventListener( 'resize', fitPreviewStage );
	}

	fitPreviewStage();

} )();
