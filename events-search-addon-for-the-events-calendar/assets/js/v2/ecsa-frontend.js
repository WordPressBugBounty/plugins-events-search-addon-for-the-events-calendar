
( function ( window, document ) {
	'use strict';

	var ECSA = window.ECSA = window.ECSA || {};
	var UrlState = ECSA.UrlState;
	var Core = ECSA.Core;

	if ( ! UrlState || ! Core ) {
		if ( window.console && window.console.warn ) {
			window.console.warn( 'ECSA: ecsa-frontend.js requires ecsa-url-state.js and ecsa-core.js; not initialising.' );
		}
		return;
	}

	var CFG = window.ECSA_CFG || {};
	var DEBUG = ! ! CFG.debug;
	var DOMAIN = 'events-search-addon-for-the-events-calendar';

	var KEYWORD_DEBOUNCE = 150;

	var DISCRETE_WINDOW = 200;

	var LOADING_DELAY = 150;
	var REQUEST_TIMEOUT = 8000;
	var ANNOUNCE_THROTTLE = 600;

	var SUGGEST_DEFAULT = 8;
	var SUGGEST_MIN = 1;

	var suggestMemoKeys = [];
	var suggestMemoStore = {};
	var SUGGEST_MEMO_CAP = 60;

	function suggestMemoGet( key ) {
		return Object.prototype.hasOwnProperty.call( suggestMemoStore, key ) ? suggestMemoStore[ key ] : null;
	}

	function suggestMemoSet( key, data ) {
		if ( ! Object.prototype.hasOwnProperty.call( suggestMemoStore, key ) ) {
			suggestMemoKeys.push( key );
			if ( suggestMemoKeys.length > SUGGEST_MEMO_CAP ) {
				delete suggestMemoStore[ suggestMemoKeys.shift() ];
			}
		}
		suggestMemoStore[ key ] = data;
	}
	var SUGGEST_CAP = 20;

	var SUGGEST_FALLBACK_BELOW = 3;

	var SUGGEST_FALLBACK_MAX = 4;
	var TOTAL_DISPLAY_CAP = 300;
	var DROPDOWN_Z_FALLBACK = 999990;

	var COUNT_MARK = String.fromCharCode( 1 );

	var VIEW_STORAGE_KEY = 'ecsa-view';
	var VIEWS = [ 'grid', 'list' ];

	function readStoredView() {
		try {
			var stored = window.localStorage.getItem( VIEW_STORAGE_KEY );
			return ( VIEWS.indexOf( stored ) !== -1 ) ? stored : '';
		} catch ( e ) {
			return '';
		}
	}

	function writeStoredView( view ) {
		try {
			window.localStorage.setItem( VIEW_STORAGE_KEY, view );
		} catch ( e ) {}
	}

	var wpI18n = ( window.wp && window.wp.i18n ) ? window.wp.i18n : {};
	var __ = ( typeof wpI18n.__ === 'function' ) ? wpI18n.__ : function ( text ) {
		return text;
	};
	var _n = ( typeof wpI18n._n === 'function' ) ? wpI18n._n : function ( single, plural, number ) {
		return 1 === Number( number ) ? single : plural;
	};
	var sprintf = ( typeof wpI18n.sprintf === 'function' ) ? wpI18n.sprintf : simpleSprintf;

	function simpleSprintf( fmt ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var auto = 0;
		return String( fmt ).replace( /%(?:(\d+)\$)?([sd])/g, function ( match, pos, type ) {
			var idx = pos ? ( parseInt( pos, 10 ) - 1 ) : auto++;
			var value = args[ idx ];
			if ( 'd' === type ) {
				value = parseInt( value, 10 );
				if ( isNaN( value ) ) {
					value = 0;
				}
			}
			return String( undefined === value ? '' : value );
		} );
	}

	function rawSpeak( message ) {
		if ( ! message ) {
			return;
		}
		if ( window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function' ) {
			window.wp.a11y.speak( message );
		}
	}
	var announce = Core.throttle( rawSpeak, ANNOUNCE_THROTTLE );

	var assign = Object.assign || function ( target ) {
		for ( var i = 1; i < arguments.length; i++ ) {
			var src = arguments[ i ];
			if ( src ) {
				for ( var k in src ) {
					if ( Object.prototype.hasOwnProperty.call( src, k ) ) {
						target[ k ] = src[ k ];
					}
				}
			}
		}
		return target;
	};

	function qsa( selector, context ) {
		return Array.prototype.slice.call( ( context || document ).querySelectorAll( selector ) );
	}

	function qs( selector, context ) {
		return ( context || document ).querySelector( selector );
	}

	function closest( el, selector ) {
		if ( el && typeof el.closest === 'function' ) {
			return el.closest( selector );
		}
		while ( el && 1 === el.nodeType ) {
			if ( el.matches && el.matches( selector ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function el( tag, className ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		return node;
	}

	function empty( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	var DESIGN_VARS = [
		'--ecsa-accent',
		'--ecsa-text',
		'--ecsa-bg',
		'--ecsa-radius',
		'--ecsa-scale',

		'--ecsa-rr',
		'--ecsa-sizing',

		'--ecsa-on-accent',

		'--ecsa-accent-text',
	];

	var DESIGN_CLASSES = [ 'ecsa--scheme-dark', 'ecsa--scheme-light' ];

	function mirrorDesignVars( from, to ) {
		if ( ! from || ! to || ! from.style || ! to.style || ! to.style.setProperty ) {
			return;
		}
		for ( var i = 0; i < DESIGN_VARS.length; i++ ) {
			var name = DESIGN_VARS[ i ];
			var value = from.style.getPropertyValue( name );
			if ( value ) {
				to.style.setProperty( name, value.trim() );
			}
		}
		for ( var j = 0; j < DESIGN_CLASSES.length; j++ ) {
			var cls = DESIGN_CLASSES[ j ];
			if ( from.classList && to.classList ) {
				to.classList.toggle( cls, from.classList.contains( cls ) );
			}
		}
	}

	function warn() {
		if ( DEBUG && window.console && window.console.warn ) {
			window.console.warn.apply( window.console, arguments );
		}
	}

	function nextFrame( fn ) {
		var raf = window.requestAnimationFrame || function ( cb ) {
			return window.setTimeout( cb, 16 );
		};
		raf( fn );
	}

	function createPortal( tag, className, source ) {
		var node = el( tag, className );
		node.style.position = 'fixed';
		node.style.margin = '0';

		if ( source ) {
			mirrorDesignVars( source, node );
		}

		var usePopover = ( 'popover' in node );
		if ( usePopover ) {
			try {
				node.setAttribute( 'popover', 'manual' );
			} catch ( e ) {
				usePopover = false;
			}
		}
		if ( ! usePopover ) {
			node.style.zIndex = String( DROPDOWN_Z_FALLBACK );
			node.style.display = 'none';
		}

		document.body.appendChild( node );

		return { el: node, usePopover: usePopover };
	}

	function showPortal( node, usePopover ) {
		if ( usePopover ) {
			try {
				node.showPopover();
				return true;
			} catch ( e ) {
				usePopover = false;
			}
		}
		node.style.zIndex = String( DROPDOWN_Z_FALLBACK );
		node.style.display = 'block';
		return usePopover;
	}

	function hidePortal( node, usePopover ) {
		if ( usePopover ) {
			try {
				node.hidePopover();
			} catch ( e ) {}
			return;
		}
		node.style.display = 'none';
	}

	function positionPortal( anchor, panel, sizing, align ) {
		var rect = anchor.getBoundingClientRect();
		var vh = window.innerHeight || document.documentElement.clientHeight;

		if ( rect.bottom < 0 || rect.top > vh ) {
			return false;
		}

		var height = panel.offsetHeight || 0;
		var roomBelow = vh - rect.bottom;
		var roomAbove = rect.top;
		var top;

		if ( height > 0 && height > roomBelow && roomAbove > roomBelow ) {
			top = Math.max( 0, Math.round( rect.top - height ) );
			panel.setAttribute( 'data-ecsa-flip', 'up' );
		} else {
			top = Math.round( rect.bottom );
			panel.removeAttribute( 'data-ecsa-flip' );
		}

		var left = Math.round( rect.left );

		if ( 'end' === align ) {
			var vw = window.innerWidth || document.documentElement.clientWidth || 0;
			var width = panel.offsetWidth || 0;

			left = Math.max( 0, Math.round( rect.right - width ) );
			if ( vw > 0 && width > 0 && left + width > vw ) {
				left = Math.max( 0, vw - width );
			}
		}

		panel.style.left = left + 'px';
		panel.style.top = top + 'px';

		if ( 'none' === sizing ) {
			return true;
		}

		if ( 'min' === sizing ) {
			panel.style.minWidth = Math.round( rect.width ) + 'px';
		} else {
			panel.style.width = Math.round( rect.width ) + 'px';
		}

		return true;
	}

	function portalClipped( anchor, panel, usePopover ) {
		var rect = panel.getBoundingClientRect();

		if ( 0 === rect.height ) {
			return true;
		}

		return ! usePopover && hasClippingAncestor( anchor );
	}

	function fireChange( node ) {
		var event;
		try {
			event = new window.Event( 'change', { bubbles: true } );
		} catch ( e ) {
			event = document.createEvent( 'HTMLEvents' );
			event.initEvent( 'change', true, false );
		}
		node.dispatchEvent( event );
	}

	function toUrl( base, criteria ) {
		var qsStr = UrlState.serialize( criteria );
		if ( ! qsStr ) {
			return base;
		}
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + qsStr;
	}

	function restUrl( route, query ) {
		var url = String( CFG.restRoot || '' ) + route;
		if ( query ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
		}
		return url;
	}

	function buildRestQuery( criteria ) {
		var parts = [];
		function add( key, value ) {
			parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
		}

		if ( criteria.q ) {
			add( 'q', criteria.q );
		}
		if ( criteria.date_preset && 'any' !== criteria.date_preset ) {
			add( 'date_preset', criteria.date_preset );
		}
		if ( 'custom' === criteria.date_preset ) {
			if ( criteria.date_from ) {
				add( 'date_from', criteria.date_from );
			}
			if ( criteria.date_to ) {
				add( 'date_to', criteria.date_to );
			}
		}
		if ( criteria.time && 'upcoming' !== criteria.time ) {
			add( 'time', criteria.time );
		}
		if ( criteria.sort && 'date_asc' !== criteria.sort ) {
			add( 'sort', criteria.sort );
		}
		if ( criteria.recurrence && 'next_only' !== criteria.recurrence ) {
			add( 'recurrence', criteria.recurrence );
		}
		if ( criteria.count_mode && 'events' !== criteria.count_mode ) {
			add( 'count_mode', criteria.count_mode );
		}
		if ( criteria.view && 'grid' !== criteria.view ) {
			add( 'view', criteria.view );
		}

		if ( Array.isArray( criteria.search_fields ) && criteria.search_fields.length ) {
			add( 'search_fields', criteria.search_fields.join( ',' ) );
		}
		if ( criteria.page && criteria.page > 1 ) {
			add( 'page', criteria.page );
		}
		if ( null != criteria.per_page ) {
			add( 'per_page', criteria.per_page );
		}
		return parts.join( '&' );
	}

	function requestJson( url, token, cb ) {
		function attempt( remaining, nonceOff ) {
			if ( false === window.navigator.onLine ) {
				cb( { type: 'offline' }, null );
				return;
			}

			var controller = ( typeof window.AbortController === 'function' ) ? new window.AbortController() : null;
			token.controller = controller;
			var timedOut = false;
			var timer = window.setTimeout( function () {
				timedOut = true;
				if ( controller ) {
					controller.abort();
				}
			}, REQUEST_TIMEOUT );

			var headers = { Accept: 'application/json' };

			if ( CFG.restNonce && ! nonceOff ) {
				headers[ 'X-WP-Nonce' ] = CFG.restNonce;
			}

			window.fetch( url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: headers,
				signal: controller ? controller.signal : undefined
			} ).then( function ( response ) {
				window.clearTimeout( timer );
				if ( token.superseded ) {
					return;
				}
				if ( ! response.ok ) {

					if ( 403 === response.status && ! nonceOff && CFG.restNonce ) {
						attempt( remaining, true );
						return;
					}

					cb( { type: 'http', status: response.status }, null );
					return;
				}
				return response.json().then( function ( data ) {
					if ( ! token.superseded ) {
						cb( null, data );
					}
				} );
			} ).catch( function ( error ) {
				window.clearTimeout( timer );
				if ( token.superseded ) {
					return;
				}
				var network = timedOut || ( error && ( 'TypeError' === error.name || 'AbortError' === error.name ) );
				if ( remaining > 0 && network ) {
					attempt( remaining - 1, nonceOff );
					return;
				}
				cb( { type: timedOut ? 'timeout' : 'network' }, null );
			} );
		}

		attempt( 1 );
	}

	var DATE_FORMAT_OPTS = {
		long: { month: 'long', order: 'mdy', hour12: true },
		medium: { month: 'short', order: 'mdy', hour12: true },
		dmy: { month: 'long', order: 'dmy', hour12: false },
		iso: { month: 'iso', order: 'iso', hour12: false }
	};

	function parseLocalStamp( value ) {
		var m = String( value || '' ).match( /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/ );
		if ( ! m ) {
			return null;
		}
		var d = new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ], 0 );
		return isNaN( d.getTime() ) ? null : { date: d, parts: m };
	}

	function localeDateParts( date, style ) {
		try {
			var bits = new Intl.DateTimeFormat( undefined, { month: style, day: 'numeric', year: 'numeric' } ).formatToParts( date );
			var out = {};
			bits.forEach( function ( part ) {
				if ( 'month' === part.type || 'day' === part.type || 'year' === part.type ) {
					out[ part.type ] = part.value;
				}
			} );
			return ( out.month && out.day && out.year ) ? out : null;
		} catch ( e ) {
			return null;
		}
	}

	function formatClock( date, hour12 ) {
		try {
			return new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit', hour12: hour12 } ).format( date );
		} catch ( e ) {
			return '';
		}
	}

	function two( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function formatDatePart( date, spec ) {
		if ( 'iso' === spec.order ) {
			return date.getFullYear() + '-' + two( date.getMonth() + 1 ) + '-' + two( date.getDate() );
		}
		var p = localeDateParts( date, spec.month );
		if ( ! p ) {
			return date.getFullYear() + '-' + two( date.getMonth() + 1 ) + '-' + two( date.getDate() );
		}
		return ( 'dmy' === spec.order )
			? ( p.day + ' ' + p.month + ' ' + p.year )
			: ( p.month + ' ' + p.day + ', ' + p.year );
	}

	function formatDate( start, allDay, format, end ) {
		if ( ! start ) {
			return '';
		}
		var from = parseLocalStamp( start );
		if ( ! from ) {
			return String( start );
		}

		var spec = DATE_FORMAT_OPTS[ format ];
		var to = parseLocalStamp( end );
		var sameDay = ! to || from.parts[ 0 ].slice( 0, 10 ) === to.parts[ 0 ].slice( 0, 10 ) || to.date <= from.date;

		if ( ! spec ) {

			try {
				var opts = allDay
					? { year: 'numeric', month: 'long', day: 'numeric' }
					: { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' };
				var whole = new Intl.DateTimeFormat( undefined, opts );
				if ( sameDay ) {
					return whole.format( from.date );
				}
				var dateOnly = new Intl.DateTimeFormat( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
				return dateOnly.format( from.date ) + ' – ' + whole.format( to.date );
			} catch ( e ) {
				return from.parts[ 1 ] + '-' + from.parts[ 2 ] + '-' + from.parts[ 3 ];
			}
		}

		var text;

		if ( sameDay ) {
			text = formatDatePart( from.date, spec );
		} else if ( 'iso' === spec.order || from.date.getFullYear() !== to.date.getFullYear() || from.date.getMonth() !== to.date.getMonth() ) {
			text = formatDatePart( from.date, spec ) + ' – ' + formatDatePart( to.date, spec );
		} else {

			var a = localeDateParts( from.date, spec.month );
			var b = localeDateParts( to.date, spec.month );
			if ( ! a || ! b ) {
				text = formatDatePart( from.date, spec ) + ' – ' + formatDatePart( to.date, spec );
			} else if ( 'dmy' === spec.order ) {
				text = a.day + '–' + b.day + ' ' + b.month + ' ' + b.year;
			} else {
				text = a.month + ' ' + a.day + '–' + b.day + ', ' + b.year;
			}
		}

		if ( allDay ) {
			return text;
		}

		var clock = formatClock( from.date, spec.hour12 );

		return clock ? ( text + ' · ' + clock ) : text;
	}

	var App = {
		instances: [],
		resultsById: {},
		urlOwnerClaimed: false,

		boot: function () {

			var roots = qsa( '.ecsa[data-ecsa-instance]' ).filter( function ( root ) {
				return '1' !== root.getAttribute( 'data-ecsa-booted' );
			} );
			if ( ! roots.length ) {
				return;
			}

			roots.forEach( function ( root ) {
				root.setAttribute( 'data-ecsa-booted', '1' );
				var instance = new Instance( root );
				App.instances.push( instance );
				if ( instance.results ) {
					App.resultsById[ instance.results.id ] = instance.results;
				}
			} );

			App.instances.forEach( function ( instance ) {
				instance.link();
			} );

			App.instances.forEach( function ( instance ) {
				instance.start();
			} );

			if ( App.popstateBound ) {
				return;
			}
			App.popstateBound = true;
			window.addEventListener( 'popstate', function () {
				var owner = App.owner;
				if ( ! owner ) {
					return;
				}

				var criteria = UrlState.overlay( owner.configSeed, window.location.search );
				owner.apply( criteria, { push: false, writeHistory: false, announce: true } );
			} );
		},

		resolveTarget: function ( instance ) {
			if ( instance.config.target ) {
				return App.resultsById[ instance.config.target ] || null;
			}
			return instance.results || null;
		}
	};

	function Instance( root ) {
		this.root = root;
		this.config = readConfig( root );
		this.criteria = seedCriteria( root );
		this.action = null;

		this.form = qs( '.ecsa-bar', root );
		this.input = this.form ? qs( '.ecsa-combobox__input', this.form ) : null;
		if ( this.form ) {
			this.action = this.form.getAttribute( 'action' ) || stripEcsa( window.location.href );
		}

		this.resultsEl = qs( '.ecsa-results[data-ecsa-results]', root );
		this.results = this.resultsEl ? new Results( this.resultsEl, this ) : null;

		this.typeahead = ( 'dropdown' === this.config.typeahead ) && ! ! this.input;
		this.listbox = null;
		this.usePopover = false;
		this.open = false;
		this.activeIndex = -1;
		this.options = [];
		this.suggestSeq = Core.createSequencer();
		this.suggestToken = { superseded: false };
		this.repositionScheduled = false;
		this.resizeObserver = null;
		this.healChecked = false;
		this.escapeArmed = false;

		this.suggestDismissed = false;
		this.suggestLoadingTimer = null;
		this.pointerFocusArmed = false;

		this.zeroQuery = false;

		this.target = null;

		this.buildLabelMaps();
	}

	Instance.prototype.link = function () {
		this.target = App.resolveTarget( this );

		if ( '1' === this.config.urlOwner ) {
			if ( App.urlOwnerClaimed ) {
				warn( 'ECSA: more than one instance claims data-ecsa-url-owner="1"; ignoring the extra.' );
			} else {
				App.urlOwnerClaimed = true;
				var ownerResults = this.target || this.results;
				if ( ownerResults ) {
					ownerResults.ownsUrl = true;
					App.owner = ownerResults;
				}
			}
		}

		if ( this.target ) {
			this.target.addBar( this );
		}

		if ( this.form ) {
			this.initBar();
			this.initFacetControls();
		}
		if ( this.typeahead ) {
			this.initCombobox();
		}
	};

	Instance.prototype.start = function () {
		if ( '1' !== this.config.urlOwner || ! this.target ) {
			return;
		}

		var effective = UrlState.overlay( this.target.configSeed, window.location.search );

		if ( undefined === UrlState.parsePresent( window.location.search ).view ) {
			var stored = readStoredView();
			if ( stored ) {
				effective.view = stored;
			}
		}

		if ( effective.view !== this.target.criteria.view ) {
			this.target.setView( effective.view, { persist: false, announce: false } );
		}

		var changed = Core.criteriaChanged(
			UrlState.serialize( effective ),
			UrlState.serialize( this.target.criteria )
		);
		if ( changed ) {
			this.target.apply( effective, { push: false, writeHistory: false, announce: false } );
		}
	};

	Instance.prototype.showNoDestinationNotice = function () {
		if ( ! this.form ) {
			return;
		}

		var note = qs( '.ecsa-bar__notice', this.form );

		if ( ! note ) {
			note = document.createElement( 'p' );
			note.className = 'ecsa-bar__notice';
			note.setAttribute( 'role', 'status' );
			note.setAttribute( 'aria-live', 'polite' );

			var shell = qs( '.ecsa-bar__shell', this.form );
			if ( shell && shell.parentNode ) {
				shell.parentNode.insertBefore( note, shell.nextSibling );
			} else {
				this.form.appendChild( note );
			}
		}

		note.textContent = __( 'No matching event. Try a different search.', 'events-search-addon-for-the-events-calendar' );
		note.hidden = false;
	};

	Instance.prototype.clearNoDestinationNotice = function () {
		var note = this.form ? qs( '.ecsa-bar__notice', this.form ) : null;

		if ( note ) {
			note.hidden = true;
			note.textContent = '';
		}
	};

	Instance.prototype.initBar = function () {
		var self = this;

		this.commitDiscrete = Core.throttle( function () {
			self.commit( { push: true, discrete: true } );
		}, DISCRETE_WINDOW );

		this.typeBurst = false;
		this.lastQLen = 0;

		this.onType = Core.debounce( function () {
			self.typeBurst = false;
			self.handleKeyword();
		}, KEYWORD_DEBOUNCE );

		this.form.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( ! t || ! t.name ) {
				return;
			}

			if ( ( 'ecsa_from' === t.name || 'ecsa_to' === t.name ) && ! closest( t, '.ecsa-facet__panel' ) ) {
				var rangeWrap = closest( t, '[data-ecsa-expanded-range]' ) || self.form;
				var fromEl = qs( 'input[name="ecsa_from"]', rangeWrap );
				var toEl   = qs( 'input[name="ecsa_to"]', rangeWrap );

				self.criteria.date_preset = 'custom';
				self.criteria.date_from   = fromEl ? fromEl.value : '';
				self.criteria.date_to     = toEl ? toEl.value : '';

				self.updateFilterCount();
				return;
			}

			if ( 'ecsa_date' === t.name && 'radio' === t.type ) {

				if ( 'custom' === t.value ) {

					self.criteria.date_preset = 'custom';
					self.updateFilterCount();
					return;
				}
				self.criteria.date_preset = t.value;
				self.criteria.date_from = '';
				self.criteria.date_to = '';
				self.criteria.page = 1;
				self.commitDiscrete();
			}

			self.updateFilterCount();
		} );

		this.form.addEventListener( 'input', function ( e ) {
				if ( e.target === self.input ) {

				self.suggestDismissed = false;

				var qNow = String( self.input.value || '' ).trim().length;
				var crossed = qNow >= 2 && self.lastQLen < 2;
				self.lastQLen = qNow;

				if ( ( ! self.typeBurst || crossed ) && self.typeahead ) {
					self.typeBurst = true;
					self.fetchSuggest( self.input.value );
				}
				self.onType();
			}
		} );

		this.form.addEventListener( 'submit', function ( e ) {

			if ( self.form.hasAttribute( 'data-ecsa-no-destination' ) ) {
				e.preventDefault();

				if ( self.open && self.activeIndex >= 0 ) {
					self.activateOption( self.activeIndex );
					return;
				}

				if ( self.typeahead && self.listbox ) {
					self.suggestDismissed = false;

					if ( self.input ) {
						try {
							self.input.focus();
						} catch ( err ) {}
					}

					if ( self.options.length ) {
						self.openDropdown();
					}

					self.fetchSuggest( self.input ? self.input.value : '' );
					return;
				}

				self.showNoDestinationNotice();
				return;
			}

			if ( ! self.target ) {
				return;
			}
			e.preventDefault();
			if ( self.open && self.activeIndex >= 0 ) {
				self.activateOption( self.activeIndex );
				return;
			}
			self.closeDropdown();
			self.criteria.q = self.input ? self.input.value : self.criteria.q;
			self.criteria.page = 1;
			self.commit( { push: true, discrete: true } );
		} );
	};

	Instance.prototype.handleKeyword = function () {

		this.clearNoDestinationNotice();

		var value = this.input ? this.input.value : '';
		this.criteria.q = value;

		if ( this.typeahead ) {
			this.fetchSuggest( value );
		}

		if ( this.target ) {
			this.criteria.page = 1;
			this.commit( { push: false, discrete: false } );
		}
	};

	Instance.prototype.commit = function ( opts ) {
		var normalized = UrlState.normalize( this.criteria );
		this.criteria = normalized;

		if ( this.target ) {
			this.target.apply( normalized, { push: ! ! opts.push, source: this, announce: true } );
			return;
		}

		if ( opts.discrete ) {
			window.location.href = toUrl( this.action || stripEcsa( window.location.href ), normalized );
		}
	};

	Instance.prototype.syncControls = function ( criteria ) {
		this.criteria = assign( {}, criteria );

		if ( ! this.form ) {
			return;
		}

		var self = this;

		if ( this.input && document.activeElement !== this.input ) {
			this.input.value = criteria.q || '';
		}

		qsa( 'input[name="ecsa_date"]', this.form ).forEach( function ( radio ) {
			radio.checked = ( radio.value === criteria.date_preset ) ||
				( 'any' === radio.value && ( ! criteria.date_preset || 'any' === criteria.date_preset ) );
		} );

		[ [ 'ecsa_from', 'date_from' ], [ 'ecsa_to', 'date_to' ] ].forEach( function ( pair ) {
			qsa( 'input[name="' + pair[ 0 ] + '"]', self.form ).forEach( function ( input ) {
				if ( document.activeElement !== input ) {
					input.value = criteria[ pair[ 1 ] ] || '';
				}
			} );
		} );

		var self2 = this;
		qsa( '.ecsa-facet--dropdown[data-ecsa-facet]', this.form ).forEach( function ( wrap ) {
			self2.updateFacetTrigger( wrap );
		} );

		( this.facetPanels || [] ).forEach( function ( panel ) {
			panel.sync();
		} );

		this.updateFilterCount();
	};

	Instance.prototype.buildLabelMaps = function () {
		this.labels = { date: {} };
		if ( ! this.form ) {
			return;
		}
		var self = this;

		qsa( 'input[name="ecsa_date"]', this.form ).forEach( function ( radio ) {
			var label = closest( radio, 'label' );
			if ( label ) {
				self.labels.date[ radio.value ] = labelText( label );
			}
		} );
	};

	Instance.prototype.initFacetControls = function () {
		if ( ! this.form ) {
			return;
		}
		var self = this;

		this.filtersCounts = qsa( '.ecsa-filters-count', this.form );

		this.initInbar();

		this.updateFilterCount();

		this.facetPopovers = [];
		this.facetPanels = [];
		qsa( '.ecsa-facet--dropdown[data-ecsa-facet]', this.form ).forEach( function ( wrap ) {
			var pop = self.buildFacetPopover( wrap );
			if ( pop ) {
				self.facetPopovers.push( pop );
			}
		} );
	};

	Instance.prototype.initInbar = function () {
		var btn = qs( '.ecsa-filters-inbar[data-ecsa-inbar]', this.form );
		if ( ! btn ) {
			return;
		}
		var region = document.getElementById( btn.getAttribute( 'data-ecsa-inbar' ) );
		if ( ! region ) {
			return;
		}

		btn.hidden = false;

		if ( '1' === btn.getAttribute( 'data-ecsa-inline' ) ) {
			this.initInlineFilters( btn );
			return;
		}

		var summary = qs( 'summary', region );
		if ( summary ) {
			summary.hidden = true;
		}

		function sync() {
			btn.setAttribute( 'aria-expanded', region.open ? 'true' : 'false' );
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			region.open = ! region.open;
			sync();
		} );

		region.addEventListener( 'toggle', sync );
		sync();
	};

	Instance.prototype.initInlineFilters = function ( btn ) {
		var form = this.form;
		if ( ! form ) {
			return;
		}

		form.setAttribute( 'data-ecsa-collapsible', '1' );

		function sync() {
			btn.setAttribute( 'aria-expanded', form.hasAttribute( 'data-ecsa-filters-open' ) ? 'true' : 'false' );
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( form.hasAttribute( 'data-ecsa-filters-open' ) ) {
				form.removeAttribute( 'data-ecsa-filters-open' );
			} else {
				form.setAttribute( 'data-ecsa-filters-open', '1' );
			}
			sync();
		} );

		if ( 'true' === btn.getAttribute( 'aria-expanded' ) ) {
			form.setAttribute( 'data-ecsa-filters-open', '1' );
		}

		sync();
	};

	Instance.prototype.buildFacetPopover = function ( wrap ) {
		var self = this;
		var trigger = qs( '.ecsa-facet-trigger', wrap );
		var panel = qs( '.ecsa-facet__panel', wrap );
		if ( ! trigger || ! panel ) {
			return null;
		}

		var select = qs( '.ecsa-facet__select', wrap );
		if ( select ) {
			select.disabled = true;
			var nojs = closest( select, '.ecsa-facet__nojs' );
			( nojs || select ).setAttribute( 'hidden', 'hidden' );
		}
		qsa( 'input', panel ).forEach( function ( input ) {
			input.disabled = false;
		} );
		trigger.removeAttribute( 'hidden' );

		var pop = new FacetPopover( trigger, panel, this );

		var facetPanel = new FacetPanel( wrap, pop, this );
		if ( facetPanel.init() ) {
			this.facetPanels = this.facetPanels || [];
			this.facetPanels.push( facetPanel );
		}

		panel.addEventListener( 'change', function ( e ) {
			self.updateFacetTrigger( wrap );
			self.updateFilterCount();
			facetPanel.onChange( e.target );
		} );
		this.updateFacetTrigger( wrap );

		return pop;
	};

	Instance.prototype.updateFacetTrigger = function ( wrap ) {
		var trigger = qs( '.ecsa-facet-trigger', wrap );
		if ( ! trigger ) {
			return;
		}

		var countEl = qs( '.ecsa-facet-trigger__count', trigger );
		var valueEl = qs( '.ecsa-facet-trigger__value', trigger );
		var checked = qs( 'input[type="radio"]:checked', wrap );

		if ( valueEl && checked ) {
			valueEl.textContent = optionLabelText(
				closest( checked, PANEL_ROW_SEL ) || closest( checked, 'label' ) || checked.parentNode
			);
		}

		if ( 'date' === wrap.getAttribute( 'data-ecsa-facet' ) ) {
			var preset = checked ? String( checked.value || '' ) : '';
			var active = isActiveValue( preset );

			if ( 'custom' === preset ) {
				var from = qs( 'input[name="ecsa_from"]', wrap );
				var to   = qs( 'input[name="ecsa_to"]', wrap );
				active   = ! ! ( ( from && from.value ) || ( to && to.value ) );
			}

			if ( countEl ) {
				countEl.textContent = active ? '(1)' : '';
			}
		} else {

			var n = countFacetSelections( wrap );
			if ( countEl ) {
				countEl.textContent = n > 0 ? ( '(' + n + ')' ) : '';
			}

			if ( valueEl && ! checked ) {
				if ( 0 === n ) {
					valueEl.textContent = __( 'All', 'events-search-addon-for-the-events-calendar' );
				} else if ( 1 === n ) {
					var one = qs( 'input[type="checkbox"]:checked', wrap );
					valueEl.textContent = one
						? optionLabelText( closest( one, PANEL_ROW_SEL ) || closest( one, 'label' ) || one.parentNode )
						: '';
				} else {
					valueEl.textContent = sprintf(

						_n( '%d selected', '%d selected', n, 'events-search-addon-for-the-events-calendar' ),
						n
					);
				}
			}
		}
	};

	Instance.prototype.updateFilterCount = function () {
		if ( ! this.filtersCounts || ! this.filtersCounts.length ) {
			return;
		}
		var n = this.activeFilterCount();
		var label = n > 0 ? ( '(' + n + ')' ) : '';
		this.filtersCounts.forEach( function ( el ) {
			el.textContent = label;
		} );
	};

	Instance.prototype.activeFilterCount = function () {
		var c = this.criteria || {};
		return ( c.date_preset && 'any' !== c.date_preset ) ? 1 : 0;
	};

	Instance.prototype.initCombobox = function () {
		var self = this;
		var input = this.input;

		var portal = createPortal( 'ul', 'ecsa-portal ecsa-dropdown', this.root );
		var listbox = portal.el;
		this.usePopover = portal.usePopover;
		listbox.id = this.config.instance + '__listbox';
		listbox.setAttribute( 'role', 'listbox' );
		listbox.setAttribute( 'aria-label', __( 'Event suggestions', 'events-search-addon-for-the-events-calendar' ) );
		this.listbox = listbox;

		input.addEventListener( 'keydown', function ( e ) {
			self.onKeydown( e );
		} );

		function armPointer() {

			if ( document.activeElement === input ) {
				self.maybeZeroQuery();
				return;
			}
			self.pointerFocusArmed = true;
		}

		input.addEventListener( 'mousedown', armPointer );
		input.addEventListener( 'touchstart', armPointer, { passive: true } );

		input.addEventListener( 'focus', function () {

			var armed = self.pointerFocusArmed;
			self.pointerFocusArmed = false;
			if ( armed ) {
				self.maybeZeroQuery();
			}
		} );

		input.addEventListener( 'focusout', function ( e ) {
			var to = e.relatedTarget;
			if ( to && ( self.comboboxRoot().contains( to ) || listbox.contains( to ) ) ) {
				return;
			}
			self.closeDropdown();

			self.suggestDismissed = false;

			self.pointerFocusArmed = false;
		} );

		this._onDocDown = function ( e ) {
			if ( ! self.open ) {
				return;
			}
			if ( self.comboboxRoot().contains( e.target ) || listbox.contains( e.target ) ) {
				return;
			}
			self.closeDropdown();
		};
		document.addEventListener( 'mousedown', this._onDocDown, true );

		this._onReposition = function () {
			self.scheduleReposition();
		};
		window.addEventListener( 'scroll', this._onReposition, true );
		window.addEventListener( 'resize', this._onReposition );
		if ( typeof window.ResizeObserver === 'function' ) {
			this.resizeObserver = new window.ResizeObserver( this._onReposition );
			this.resizeObserver.observe( input );
		}
	};

	Instance.prototype.comboboxRoot = function () {
		return closest( this.input, '.ecsa-combobox' ) || this.form || this.root;
	};

	Instance.prototype.onKeydown = function ( e ) {

		if ( ! this.typeahead || ! this.listbox ) {
			return;
		}
		var key = e.key;

		if ( 'ArrowDown' === key || 'ArrowUp' === key ) {
			e.preventDefault();

			this.suggestDismissed = false;
			if ( e.altKey && 'ArrowDown' === key ) {

				this.openDropdown();
				return;
			}
			if ( ! this.open ) {
				if ( this.options.length ) {
					this.openDropdown();

					this.setActive( 'ArrowUp' === key ? this.options.length - 1 : 0 );
				} else {

					this.fetchSuggest( this.input ? this.input.value : '' );
				}
				return;
			}
			this.setActive( Core.nextIndex( this.activeIndex, key, this.options.length ) );
			return;
		}

		if ( 'Home' === key || 'End' === key ) {
			if ( this.open && this.options.length ) {
				e.preventDefault();
				this.setActive( Core.nextIndex( this.activeIndex, key, this.options.length ) );
			}
			return;
		}

		if ( 'Enter' === key ) {
			if ( this.open && this.activeIndex >= 0 ) {
				e.preventDefault();
				this.activateOption( this.activeIndex );
			}

			return;
		}

		if ( 'Escape' === key ) {
			if ( this.open ) {
				e.preventDefault();
				e.stopPropagation();
				this.closeDropdown();
				this.escapeArmed = true;
				this.suggestDismissed = true;
				return;
			}
			if ( this.escapeArmed || ( this.input && this.input.value ) ) {
				e.preventDefault();
				e.stopPropagation();
				this.clearInput();
				this.escapeArmed = false;
			}
			return;
		}

		if ( 'Tab' === key ) {
			this.closeDropdown();

			this.suggestDismissed = true;
			return;
		}

		this.escapeArmed = false;
	};

	Instance.prototype.clearInput = function () {
		if ( this.input ) {
			this.input.value = '';
			this.criteria.q = '';
		}
		this.closeDropdown();
	};

	Instance.prototype.fetchSuggest = function ( value ) {

		if ( ! this.typeahead || ! this.listbox ) {
			return;
		}
		var self = this;
		var q = String( value || '' ).trim();
		var zero = ( 0 === q.length );

		if ( ! zero && ! keywordRestReady( q ) ) {

			if ( ! this.zeroQuery ) {

				this.renderSuggestNotice( __( 'Keep typing to search events…', 'events-search-addon-for-the-events-calendar' ) );
			}
			return;
		}

		var limit = zero ? SUGGEST_FALLBACK_MAX : ( this.config.suggestLimit || SUGGEST_DEFAULT );
		var criteria = zero
			? assign( {}, this.criteria, { q: '', time: 'upcoming', sort: 'date_asc', page: 1, per_page: limit } )
			: assign( {}, this.criteria, { q: q, page: 1, per_page: limit } );

		var query = buildRestQuery( criteria )
			+ ( zero ? '&q=' : '' )
			+ '&limit=' + encodeURIComponent( limit );
		var url = restUrl( 'suggest', query );

		if ( this.suggestUrl === url && this.suggestToken && ! this.suggestToken.superseded && ! this.suggestToken.settled ) {

			if ( ! this.open ) {
				if ( this.options.length ) {
					this.openDropdown();
					this.setSuggestBusy( true );
				} else {
					this.renderSuggestNotice( __( 'Searching…', 'events-search-addon-for-the-events-calendar' ) );
				}
			}
			return;
		}
		this.suggestUrl = url;

		var seqId = this.suggestSeq.issue();
		this.suggestToken.superseded = true;
		if ( this.suggestToken.controller ) {
			try {
				this.suggestToken.controller.abort();
			} catch ( e ) {}
		}

		var memoHit = suggestMemoGet( url );
		if ( memoHit ) {
			this.suggestSeq.accept( seqId );
			this.renderSuggest( memoHit, q, criteria );
			return;
		}

		if ( this.options.length ) {
			this.setSuggestBusy( true );
		} else {
			this.renderSuggestNotice( __( 'Searching…', 'events-search-addon-for-the-events-calendar' ) );
		}

		var token = { superseded: false };
		this.suggestToken = token;

		requestJson( url, token, function ( err, data ) {
			token.settled = true;

			if ( ! self.suggestSeq.accept( seqId ) ) {
				return;
			}
			if ( err ) {

				self.setSuggestBusy( false );
				self.closeDropdown();
				return;
			}
			suggestMemoSet( url, data );
			self.renderSuggest( data, q, criteria );
		} );
	};

	Instance.prototype.maybeZeroQuery = function () {
		if ( ! this.typeahead || ! this.listbox || ! this.input ) {
			return;
		}
		if ( this.suggestDismissed || this.open ) {
			return;
		}
		if ( String( this.input.value || '' ).trim().length ) {
			return;
		}
		this.fetchSuggest( '' );
	};

	Instance.prototype.suggestBusy = function () {
		return ! ! this.listbox && 'true' === this.listbox.getAttribute( 'aria-busy' );
	};

	Instance.prototype.setSuggestBusy = function ( busy ) {
		var self = this;
		if ( ! this.listbox ) {
			return;
		}
		if ( busy ) {
			this.listbox.setAttribute( 'aria-busy', 'true' );
			if ( null === this.suggestLoadingTimer ) {
				this.suggestLoadingTimer = window.setTimeout( function () {
					self.suggestLoadingTimer = null;
					if ( self.listbox && 'true' === self.listbox.getAttribute( 'aria-busy' ) ) {
						self.listbox.classList.add( 'ecsa-is-loading' );
					}
				}, LOADING_DELAY );
			}
		} else {
			if ( null !== this.suggestLoadingTimer ) {
				window.clearTimeout( this.suggestLoadingTimer );
				this.suggestLoadingTimer = null;
			}
			this.listbox.setAttribute( 'aria-busy', 'false' );
			this.listbox.classList.remove( 'ecsa-is-loading' );
		}
	};

	Instance.prototype.renderSuggest = function ( data, q, criteria ) {
		var self = this;

		this.setSuggestBusy( false );
		var limit = this.config.suggestLimit || SUGGEST_DEFAULT;
		var items = ( data && Array.isArray( data.items ) ) ? data.items.slice( 0, limit ) : [];
		var hasMore = ! ! ( data && data.has_more );

		var zeroQuery = ( '' === String( q || '' ) );
		this.zeroQuery = zeroQuery;

		if ( zeroQuery ) {
			items = items.map( function ( row ) {
				return assign( {}, row, { group: 'next' } );
			} );
		}

		var upcoming = ( data && Array.isArray( data.upcoming ) ) ? data.upcoming : [];
		var matchCount = zeroQuery ? 0 : items.length;

		if ( upcoming.length && matchCount < SUGGEST_FALLBACK_BELOW ) {

			var tagged = upcoming.slice( 0, SUGGEST_FALLBACK_MAX ).map( function ( row ) {
				var copy = {};
				var k;
				for ( k in row ) {
					if ( Object.prototype.hasOwnProperty.call( row, k ) ) {
						copy[ k ] = row[ k ];
					}
				}
				copy.group = 'upcoming';
				return copy;
			} );

			items = items.map( function ( row ) {
				if ( row && ! row.group ) {
					var copy = {};
					var k;
					for ( k in row ) {
						if ( Object.prototype.hasOwnProperty.call( row, k ) ) {
							copy[ k ] = row[ k ];
						}
					}
					copy.group = 'events';
					return copy;
				}
				return row;
			} ).concat( tagged );
		}

		empty( this.listbox );
		this.options = [];

		var groups = groupSuggest( items );
		var optionCount = 0;

		if ( zeroQuery && items.length ) {
			var promptRow = el( 'li', 'ecsa-dropdown__empty ecsa-dropdown__prompt' );
			promptRow.setAttribute( 'role', 'presentation' );
			promptRow.textContent = __( 'Type to search events…', 'events-search-addon-for-the-events-calendar' );
			this.listbox.appendChild( promptRow );
		}

		if ( ! zeroQuery && ! matchCount && items.length ) {
			var missRow = el( 'li', 'ecsa-dropdown__empty ecsa-dropdown__miss' );
			missRow.setAttribute( 'role', 'presentation' );
			missRow.textContent = sprintf(

				__( 'No events match “%s”.', 'events-search-addon-for-the-events-calendar' ),
				q
			);
			this.listbox.appendChild( missRow );
		}

		if ( ! items.length ) {
			var emptyRow = el( 'li', 'ecsa-dropdown__empty' );
			emptyRow.setAttribute( 'role', 'presentation' );

			var emptyMsg = zeroQuery
				? __( 'There are no upcoming events.', 'events-search-addon-for-the-events-calendar' )
				: sprintf(

					__( 'No events match “%s”.', 'events-search-addon-for-the-events-calendar' ),
					q
				);
			emptyRow.textContent = emptyMsg;
			this.listbox.appendChild( emptyRow );

			announce( emptyMsg );
		} else if ( groups.labelled ) {
			groups.order.forEach( function ( key ) {
				var groupEl = el( 'li', 'ecsa-dropdown__group' );
				groupEl.setAttribute( 'role', 'presentation' );

				var groupList = el( 'ul', 'ecsa-dropdown__grouplist' );
				groupList.setAttribute( 'role', 'group' );

				var headingId = self.config.instance + '__grp-' + key;
				var heading = el( 'li', 'ecsa-dropdown__heading' );
				heading.id = headingId;
				heading.setAttribute( 'role', 'presentation' );
				heading.textContent = groupLabel( key );
				groupList.setAttribute( 'aria-labelledby', headingId );

				groupList.appendChild( heading );
				groups.map[ key ].forEach( function ( item ) {
					var opt = self.buildOption( item, optionCount++ );
					groupList.appendChild( opt );
				} );

				groupEl.appendChild( groupList );
				self.listbox.appendChild( groupEl );
			} );
		} else {
			items.forEach( function ( item ) {
				self.listbox.appendChild( self.buildOption( item, optionCount++ ) );
			} );
		}

		if ( ! zeroQuery && items.length && ! matchCount ) {
			announce( sprintf(

				_n(
					'No events match “%1$s”. Showing %2$d upcoming event instead.',
					'No events match “%1$s”. Showing %2$d upcoming events instead.',
					items.length,
					'events-search-addon-for-the-events-calendar'
				),
				q,
				items.length
			) );
		}

		if ( ! zeroQuery && this.hasResultsExit() ) {

			this.listbox.appendChild( this.buildSeeAll( q, criteria, matchCount, hasMore ) );
		}

		this.activeIndex = -1;
		this.input.setAttribute( 'aria-activedescendant', '' );

		if ( document.activeElement === this.input && ! this.suggestDismissed ) {
			this.openDropdown();
		}
	};

	Instance.prototype.renderSuggestNotice = function ( message ) {
		if ( ! this.listbox || ! this.input ) {
			return;
		}

		this.zeroQuery = false;

		empty( this.listbox );
		this.options = [];

		var row = el( 'li', 'ecsa-dropdown__empty ecsa-dropdown__notice' );
		row.setAttribute( 'role', 'presentation' );
		row.textContent = message;
		this.listbox.appendChild( row );

		this.activeIndex = -1;
		this.input.setAttribute( 'aria-activedescendant', '' );

		if ( document.activeElement === this.input && ! this.suggestDismissed ) {
			this.openDropdown();
		}
	};

	Instance.prototype.buildOption = function ( item, index ) {
		var self = this;
		var li = el( 'li', 'ecsa-dropdown__option ecsa-suggestion' );
		li.id = this.config.instance + '__opt-' + index;
		li.setAttribute( 'role', 'option' );
		li.setAttribute( 'aria-selected', 'false' );

		var url = ( item && item.url ) ? String( item.url ) : '';
		li.setAttribute( 'data-ecsa-url', url );
		if ( item && null != item.event_id ) {
			li.setAttribute( 'data-ecsa-event', String( item.event_id ) );
		}

		var title = el( 'span', 'ecsa-dropdown__title ecsa-suggestion__title' );
		title.textContent = ( item && item.title ) ? String( item.title ) : '';
		li.appendChild( title );

		var metaBits = [];
		if ( item && item.start ) {
			var when = formatDate( item.start, item.all_day );
			if ( when ) {
				metaBits.push( when );
			}
		}
		if ( item && item.venue ) {
			var where = String( item.venue ).trim();
			if ( where ) {
				metaBits.push( where );
			}
		}
		if ( metaBits.length ) {
			var meta = el( 'span', 'ecsa-dropdown__meta ecsa-suggestion__meta' );
			meta.textContent = metaBits.join( ' · ' );

			li.appendChild( document.createTextNode( ' ' ) );
			li.appendChild( meta );
		}

		li.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
			if ( url ) {
				window.location.href = url;
			}
		} );

		this.options.push( { el: li, url: url } );
		return li;
	};

	Instance.prototype.hasResultsExit = function () {
		if ( 'bar' !== this.config.role ) {
			return true;
		}
		if ( 'page' === this.config.resultsMode ) {
			return true;
		}
		return !! this.target;
	};

	Instance.prototype.buildSeeAll = function ( q, criteria, shown, hasMore ) {
		var self = this;
		var li = el( 'li', 'ecsa-dropdown__option ecsa-dropdown__see-all' );
		li.id = this.config.instance + '__opt-see-all';
		li.setAttribute( 'role', 'option' );
		li.setAttribute( 'aria-selected', 'false' );

		var url = toUrl( this.action || stripEcsa( window.location.href ), assign( {}, criteria, { page: 1 } ) );
		li.setAttribute( 'data-ecsa-url', url );

		var link = el( 'a', 'ecsa-dropdown__see-all-link' );
		link.setAttribute( 'href', url );
		link.setAttribute( 'tabindex', '-1' );
		if ( hasMore || ! shown ) {
			link.textContent = sprintf(

				__( 'See all results for “%s”', 'events-search-addon-for-the-events-calendar' ),
				q
			);
		} else {
			link.textContent = sprintf(

				_n( 'See all %1$d result for “%2$s”', 'See all %1$d results for “%2$s”', shown, 'events-search-addon-for-the-events-calendar' ),
				shown,
				q
			);
		}
		li.appendChild( link );

		li.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
			window.location.href = url;
		} );

		this.options.push( { el: li, url: url } );
		return li;
	};

	Instance.prototype.setActive = function ( index ) {
		var count = this.options.length;
		if ( index < -1 || index >= count ) {
			index = -1;
		}
		this.activeIndex = index;

		for ( var i = 0; i < count; i++ ) {
			this.options[ i ].el.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
		}

		if ( index >= 0 ) {
			var active = this.options[ index ].el;
			this.input.setAttribute( 'aria-activedescendant', active.id );
			if ( active.scrollIntoView ) {
				active.scrollIntoView( { block: 'nearest' } );
			}
		} else {
			this.input.setAttribute( 'aria-activedescendant', '' );
		}
	};

	Instance.prototype.activateOption = function ( index ) {
		var opt = this.options[ index ];
		if ( opt && opt.url ) {
			window.location.href = opt.url;
		}
	};

	Instance.prototype.openDropdown = function () {
		if ( ! this.listbox || ( ! this.options.length && ! this.listbox.firstChild ) ) {
			return;
		}
		this.open = true;
		this.input.setAttribute( 'aria-expanded', 'true' );

		this.usePopover = showPortal( this.listbox, this.usePopover );
		this.position();
		this.selfHeal();
	};

	Instance.prototype.closeDropdown = function () {
		if ( ! this.listbox ) {
			return;
		}

		this.setSuggestBusy( false );
		this.open = false;
		this.activeIndex = -1;
		if ( this.input ) {
			this.input.setAttribute( 'aria-expanded', 'false' );
			this.input.setAttribute( 'aria-activedescendant', '' );
		}
		hidePortal( this.listbox, this.usePopover );
	};

	Instance.prototype.scheduleReposition = function () {
		if ( this.repositionScheduled || ! this.open ) {
			return;
		}
		this.repositionScheduled = true;
		var self = this;
		nextFrame( function () {
			self.repositionScheduled = false;
			if ( self.open ) {
				self.position();
			}
		} );
	};

	Instance.prototype.position = function () {

		if ( ! positionPortal( this.input, this.listbox, 'exact' ) ) {
			this.closeDropdown();
		}
	};

	Instance.prototype.selfHeal = function () {
		if ( this.healChecked ) {
			return;
		}

		if ( this.zeroQuery ) {
			return;
		}
		this.healChecked = true;

		var self = this;
		nextFrame( function () {
			if ( ! self.open || ! self.listbox ) {
				return;
			}

			if ( portalClipped( self.input, self.listbox, self.usePopover ) ) {
				self.degradeTypeahead();
			}
		} );
	};

	Instance.prototype.degradeTypeahead = function () {
		warn( 'ECSA: dropdown appeared clipped by the theme; falling back to inline results for', this.config.instance );
		this.typeahead = false;
		this.closeDropdown();
		if ( this.input ) {

			this.input.removeAttribute( 'role' );
			this.input.removeAttribute( 'aria-autocomplete' );
			this.input.removeAttribute( 'aria-controls' );
			this.input.removeAttribute( 'aria-expanded' );
			this.input.removeAttribute( 'aria-activedescendant' );
		}
		this.teardownDropdown();
	};

	Instance.prototype.teardownDropdown = function () {
		if ( this._onDocDown ) {
			document.removeEventListener( 'mousedown', this._onDocDown, true );
		}
		if ( this._onReposition ) {
			window.removeEventListener( 'scroll', this._onReposition, true );
			window.removeEventListener( 'resize', this._onReposition );
		}
		if ( this.resizeObserver ) {
			try {
				this.resizeObserver.disconnect();
			} catch ( e ) {}
			this.resizeObserver = null;
		}
		if ( this.listbox && this.listbox.parentNode ) {
			this.listbox.parentNode.removeChild( this.listbox );
		}
		this.listbox = null;
		this.options = [];
	};

	function FacetPopover( trigger, panel, instance ) {
		this.trigger = trigger;
		this.panel = panel;
		this.instance = instance;
		this.open = false;
		this.usePopover = false;
		this.repositionScheduled = false;
		this.healChecked = false;
		this.init();
	}

	FacetPopover.prototype.init = function () {
		var self = this;
		var panel = this.panel;
		var trigger = this.trigger;

		panel.classList.add( 'ecsa-facet-popover' );
		panel.style.position = 'fixed';
		panel.style.margin = '0';

		this.usePopover = ( 'popover' in panel );
		if ( this.usePopover ) {
			try {
				panel.setAttribute( 'popover', 'manual' );
			} catch ( e ) {
				this.usePopover = false;
			}
		}
		if ( this.usePopover ) {

			panel.removeAttribute( 'hidden' );
		} else {
			panel.style.zIndex = String( DROPDOWN_Z_FALLBACK );
			panel.setAttribute( 'hidden', 'hidden' );
		}

		trigger.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			self.toggle();
		} );
		trigger.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key || 'Down' === e.key ) {
				e.preventDefault();
				self.show();
				self.focusFirst();
			}
		} );

		this._onDocDown = function ( e ) {
			if ( ! self.open ) {
				return;
			}
			if ( trigger.contains( e.target ) || panel.contains( e.target ) ) {
				return;
			}
			self.hide();
		};
		document.addEventListener( 'mousedown', this._onDocDown, true );

		this._onKey = function ( e ) {
			if ( ! self.open ) {
				return;
			}
			if ( 'Escape' === e.key || 'Esc' === e.key ) {
				e.preventDefault();
				e.stopPropagation();
				self.hide();
				try {
					trigger.focus();
				} catch ( err ) {}
			}
		};
		document.addEventListener( 'keydown', this._onKey, true );

		panel.addEventListener( 'focusout', function ( e ) {
			if ( ! self.open ) {
				return;
			}
			var to = e.relatedTarget;
			if ( ! to ) {
				return;
			}
			if ( panel.contains( to ) || trigger.contains( to ) ) {
				return;
			}
			self.hide();
		} );

		this._onReposition = function () {
			self.scheduleReposition();
		};
		window.addEventListener( 'scroll', this._onReposition, true );
		window.addEventListener( 'resize', this._onReposition );

		if ( typeof window.ResizeObserver === 'function' ) {
			this.resizeObserver = new window.ResizeObserver( this._onReposition );
			this.resizeObserver.observe( trigger );
		}
	};

	FacetPopover.prototype.toggle = function () {
		if ( this.open ) {
			this.hide();
		} else {
			this.show();
		}
	};

	FacetPopover.prototype.show = function () {
		if ( this.open ) {
			return;
		}
		this.open = true;
		this.trigger.setAttribute( 'aria-expanded', 'true' );
		if ( this.usePopover ) {
			try {
				this.panel.showPopover();
			} catch ( e ) {
				this.usePopover = false;
				this.panel.removeAttribute( 'hidden' );
			}
		} else {
			this.panel.removeAttribute( 'hidden' );
		}
		this.position();
		this.selfHeal();
	};

	FacetPopover.prototype.hide = function () {
		if ( ! this.open ) {
			return;
		}
		this.open = false;
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		if ( this.usePopover ) {
			try {
				this.panel.hidePopover();
			} catch ( e ) {}
		} else {
			this.panel.setAttribute( 'hidden', 'hidden' );
		}
	};

	FacetPopover.prototype.focusFirst = function () {
		var first = qs( 'input:not([disabled]), button:not([disabled]), select:not([disabled]), [tabindex]', this.panel );
		if ( first ) {
			try {
				first.focus();
			} catch ( e ) {}
		}
	};

	FacetPopover.prototype.position = function () {
		if ( ! positionPortal( this.trigger, this.panel, 'min' ) ) {
			this.hide();
		}
	};

	FacetPopover.prototype.scheduleReposition = function () {
		if ( this.repositionScheduled || ! this.open ) {
			return;
		}
		this.repositionScheduled = true;
		var self = this;
		nextFrame( function () {
			self.repositionScheduled = false;
			if ( self.open ) {
				self.position();
			}
		} );
	};

	FacetPopover.prototype.selfHeal = function () {
		if ( this.healChecked ) {
			return;
		}
		this.healChecked = true;

		var self = this;
		nextFrame( function () {
			if ( ! self.open || ! self.panel ) {
				return;
			}

			var clipped = portalClipped( self.trigger, self.panel, self.usePopover ) ||
				( ! self.usePopover && hasContainingBlockAncestor( self.trigger ) );

			if ( clipped ) {
				self.degradeInline();
			}
		} );
	};

	FacetPopover.prototype.degradeInline = function () {
		warn( 'ECSA: facet popover appeared clipped by the theme; falling back to an inline panel.' );
		this.teardown();
		if ( this.usePopover ) {
			try {
				this.panel.hidePopover();
			} catch ( e ) {}
			this.panel.removeAttribute( 'popover' );
		}
		this.panel.classList.remove( 'ecsa-facet-popover' );
		this.panel.classList.add( 'ecsa-facet__panel--inline' );
		this.panel.style.position = '';
		this.panel.style.top = '';
		this.panel.style.left = '';
		this.panel.style.minWidth = '';
		this.panel.style.zIndex = '';
		this.panel.removeAttribute( 'data-ecsa-flip' );
		this.panel.removeAttribute( 'hidden' );
		this.trigger.setAttribute( 'hidden', 'hidden' );
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		this.open = false;
	};

	FacetPopover.prototype.teardown = function () {
		if ( this._onDocDown ) {
			document.removeEventListener( 'mousedown', this._onDocDown, true );
		}
		if ( this._onKey ) {
			document.removeEventListener( 'keydown', this._onKey, true );
		}
		if ( this._onReposition ) {
			window.removeEventListener( 'scroll', this._onReposition, true );
			window.removeEventListener( 'resize', this._onReposition );
		}
		if ( this.resizeObserver ) {
			try {
				this.resizeObserver.disconnect();
			} catch ( e ) {}
			this.resizeObserver = null;
		}
	};

	var PANEL_SEARCH_SEL   = '[data-ecsa-facet-search], [data-ecsa-panel-search], .ecsa-facet__search-input, .ecsa-facet__search input, .ecsa-facet__filter-input';

	var PANEL_EMPTY_SEL    = '[data-ecsa-nomatch], .ecsa-facet__empty, .ecsa-facet__no-matches';
	var PANEL_RESET_SEL    = '[data-ecsa-reset], .ecsa-facet__reset';
	var PANEL_SELECTED_SEL = '[data-ecsa-selected], .ecsa-facet__selected, .ecsa-facet__footer-count';
	var PANEL_APPLY_SEL    = '[data-ecsa-apply], .ecsa-facet__apply';
	var PANEL_RANGE_SEL    = '[data-ecsa-range], .ecsa-facet__range, .ecsa-facet__custom';
	var PANEL_ROW_SEL      = '[data-ecsa-row], .ecsa-facet__row, .ecsa-check, .ecsa-pill, .ecsa-chip';
	var PANEL_LIST_SEL     = '[data-ecsa-options], .ecsa-facet__options, .ecsa-pills, .ecsa-chips';

	function FacetPanel( wrap, popover, instance ) {
		this.wrap = wrap;
		this.popover = popover || null;
		this.instance = instance;
		this.panel = popover ? popover.panel : qs( '.ecsa-facet__panel', wrap );
		this.trigger = popover ? popover.trigger : qs( '.ecsa-facet-trigger', wrap );
		this.facet = wrap.getAttribute( 'data-ecsa-facet' ) || '';
		this.isDate = ( 'date' === this.facet );

		this.controls = [];
		this.rows = [];
		this.texts = [];
		this.single = false;

		this.search = null;
		this.emptyEl = null;
		this.resetBtn = null;
		this.selectedEl = null;
		this.rangeEl = null;
		this.fromInput = null;
		this.toInput = null;
		this.applyBtn = null;

		this.suppressClose = false;
	}

	FacetPanel.prototype.init = function () {
		var self = this;

		if ( ! this.panel ) {
			return false;
		}

		this.collectOptions();
		this.initSearch();
		this.initFooter();
		this.initRange();

		this.panel.addEventListener( 'keydown', function ( e ) {
			self.onKeydown( e );
		} );

		this.sync();

		return true;
	};

	FacetPanel.prototype.collectOptions = function () {
		this.controls = qsa( 'input[type="checkbox"], input[type="radio"]', this.panel ).filter( function ( input ) {
			return ! ! input.name;
		} );

		this.rows = this.controls.map( function ( input ) {
			return closest( input, PANEL_ROW_SEL ) || closest( input, 'label' ) || input.parentNode;
		} );

		this.texts = this.rows.map( function ( row ) {
			return rowSearchText( row );
		} );

		this.single = ! ! this.controls.length && 'radio' === this.controls[ 0 ].type;

		this.listEl = qs( PANEL_LIST_SEL, this.panel ) ||
			( this.rows.length ? this.rows[ 0 ].parentNode : this.panel );
	};

	FacetPanel.prototype.initSearch = function () {
		var self = this;
		var input = qs( PANEL_SEARCH_SEL, this.panel );

		if ( ! input ) {

			input = qsa( 'input', this.panel ).filter( function ( node ) {
				var type = String( node.type || 'text' ).toLowerCase();
				if ( 'search' !== type && 'text' !== type ) {
					return false;
				}
				return ! isCriteriaControlName( node.name );
			} )[ 0 ] || null;
		}

		if ( ! input ) {
			return;
		}

		this.search = input;
		input.setAttribute( 'autocomplete', 'off' );

		input.addEventListener( 'input', function () {
			self.filter( input.value );
		} );

		input.addEventListener( 'change', function ( e ) {
			e.stopPropagation();
		} );

		this.emptyEl = qs( PANEL_EMPTY_SEL, this.panel );
		if ( this.emptyEl && this.rows.indexOf( this.emptyEl ) !== -1 ) {
			this.emptyEl = null;
		}
		if ( ! this.emptyEl ) {
			this.emptyEl = el( 'p', 'ecsa-facet__empty' );
			this.emptyEl.setAttribute( 'data-ecsa-nomatch', '' );
			this.listEl.appendChild( this.emptyEl );
		}
		if ( ! String( this.emptyEl.textContent || '' ).trim() ) {
			this.emptyEl.textContent = __( 'No matches', 'events-search-addon-for-the-events-calendar' );
		}
		setNodeVisible( this.emptyEl, false );

		if ( input.value ) {
			this.filter( input.value );
		}
	};

	FacetPanel.prototype.filter = function ( query ) {
		var q = String( query || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
		var shown = 0;

		for ( var i = 0; i < this.rows.length; i++ ) {
			var match = ( '' === q ) || ( this.texts[ i ].indexOf( q ) !== -1 );
			setNodeVisible( this.rows[ i ], match );
			if ( match ) {
				shown++;
			}
		}

		if ( this.emptyEl ) {
			setNodeVisible( this.emptyEl, 0 === shown && this.rows.length > 0 );
		}
	};

	FacetPanel.prototype.initFooter = function () {
		var self = this;

		this.selectedEl = qs( PANEL_SELECTED_SEL, this.panel );
		this.resetBtn = qs( PANEL_RESET_SEL, this.panel );

		if ( ! this.resetBtn ) {
			return;
		}

		if ( 'BUTTON' === this.resetBtn.tagName ) {
			this.resetBtn.type = 'button';
		}

		this.resetBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			self.reset();
		} );
	};

	FacetPanel.prototype.reset = function () {
		var fired = null;
		var blank = null;

		this.suppressClose = true;

		for ( var i = 0; i < this.controls.length; i++ ) {
			var control = this.controls[ i ];
			var isBlank = ! isActiveValue( control.value );
			control.checked = false;
			if ( isBlank && ! blank ) {
				blank = control;
			}
		}

		if ( blank && ( this.single || this.isDate ) ) {
			blank.checked = true;
		}

		if ( this.fromInput ) {
			this.fromInput.value = '';
		}
		if ( this.toInput ) {
			this.toInput.value = '';
		}

		fired = blank || this.controls[ 0 ] || null;

		this.syncRange( false );

		if ( fired ) {
			fireChange( fired );
		} else {
			this.instance.updateFacetTrigger( this.wrap );
			this.instance.updateFilterCount();
			this.updateSelectedReadback();
		}

		this.suppressClose = false;
	};

	FacetPanel.prototype.initRange = function () {
		var self = this;

		this.fromInput = qs( 'input[name="ecsa_from"]', this.panel );
		this.toInput = qs( 'input[name="ecsa_to"]', this.panel );
		this.rangeEl = qs( PANEL_RANGE_SEL, this.panel );

		if ( ! this.rangeEl && this.fromInput ) {

			var candidate = closest( this.fromInput, 'fieldset, div, p' );
			if ( candidate && candidate !== this.panel && ! qs( 'input[name="ecsa_date"]', candidate ) ) {
				this.rangeEl = candidate;
			}
		}

		this.applyBtn = qs( PANEL_APPLY_SEL, this.panel );
		if ( ! this.applyBtn ) {
			return;
		}

		if ( 'BUTTON' === this.applyBtn.tagName ) {

			this.applyBtn.type = 'button';
		}

		this.applyBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			self.applyRange();
		} );
	};

	FacetPanel.prototype.applyRange = function () {
		var instance = this.instance;
		var checked = qs( 'input[name="ecsa_date"]:checked', this.panel );
		var preset = checked ? String( checked.value || '' ) : 'any';
		var custom = ( 'custom' === preset );

		instance.criteria.date_preset = preset;

		instance.criteria.date_from = ( custom && this.fromInput ) ? this.fromInput.value : '';
		instance.criteria.date_to = ( custom && this.toInput ) ? this.toInput.value : '';
		instance.criteria.page = 1;
		instance.commit( { push: true, discrete: true } );

		instance.updateFacetTrigger( this.wrap );
		instance.updateFilterCount();
		this.close();
	};

	FacetPanel.prototype.syncRange = function ( focusRange ) {
		if ( ! this.isDate || ( ! this.rangeEl && ! this.fromInput && ! this.toInput ) ) {
			return;
		}

		var checked = qs( 'input[name="ecsa_date"]:checked', this.panel );
		var custom = ! ! checked && 'custom' === String( checked.value || '' );

		if ( this.rangeEl ) {
			setNodeVisible( this.rangeEl, custom );
		}

		if ( this.fromInput ) {
			this.fromInput.disabled = ! custom;
		}
		if ( this.toInput ) {
			this.toInput.disabled = ! custom;
		}

		if ( custom && focusRange && this.fromInput ) {
			try {
				this.fromInput.focus();
			} catch ( e ) {}
		}
	};

	FacetPanel.prototype.sync = function () {
		this.syncRange( false );
		this.updateSelectedReadback();
	};

	FacetPanel.prototype.updateSelectedReadback = function () {
		if ( ! this.selectedEl ) {
			return;
		}
		var n = countFacetSelections( this.panel );
		this.selectedEl.textContent = n > 0
			? sprintf(

				_n( '%d selected', '%d selected', n, 'events-search-addon-for-the-events-calendar' ),
				n
			)
			: '';
	};

	FacetPanel.prototype.onChange = function ( target ) {
		this.updateSelectedReadback();

		if ( ! target || ( 'radio' !== target.type && 'checkbox' !== target.type ) ) {
			return;
		}

		if ( this.isDate ) {
			this.syncRange( true );
			if ( 'custom' === String( target.value || '' ) ) {
				return;
			}
		}

		if ( this.suppressClose || ! ( this.single || this.isDate ) ) {
			return;
		}

		this.close();
	};

	FacetPanel.prototype.close = function () {
		if ( ! this.popover || ! this.popover.open ) {
			return;
		}
		this.popover.hide();
		if ( this.trigger ) {
			try {
				this.trigger.focus();
			} catch ( e ) {}
		}
	};

	FacetPanel.prototype.visibleControls = function () {
		var self = this;
		return this.controls.filter( function ( control, i ) {
			return ! control.disabled && ! isNodeHidden( self.rows[ i ] );
		} );
	};

	FacetPanel.prototype.focusOption = function ( index ) {
		var options = this.visibleControls();
		if ( ! options.length ) {
			return;
		}
		var target = options[ Math.max( 0, Math.min( options.length - 1, index ) ) ];
		try {
			target.focus();
		} catch ( e ) {}
	};

	FacetPanel.prototype.onKeydown = function ( e ) {
		var key = normalizeKey( e.key );

		if ( this.search && e.target === this.search ) {

			if ( 'Enter' === key ) {
				e.preventDefault();
				this.focusOption( 0 );
				return;
			}
			if ( 'ArrowDown' === key ) {
				e.preventDefault();
				this.focusOption( 0 );
			}
			return;
		}

		var options = this.visibleControls();
		var index = options.indexOf( e.target );

		if ( index === -1 ) {
			return;
		}

		if ( 'Enter' === key ) {
			e.preventDefault();
			e.target.click();
			return;
		}

		if ( 'ArrowDown' !== key && 'ArrowUp' !== key && 'Home' !== key && 'End' !== key ) {
			return;
		}

		e.preventDefault();

		var next = Core.nextIndex( index, key, options.length );

		if ( next < 0 ) {
			if ( this.search ) {
				try {
					this.search.focus();
				} catch ( err ) {}
				return;
			}
			next = ( 'ArrowUp' === key ) ? ( options.length - 1 ) : 0;
		}

		this.focusOption( next );
	};

	function SortMenu( root, instance ) {
		this.root = root;
		this.instance = instance;
		this.select = qs( '.ecsa-sort', root );
		this.nojs = qs( '.ecsa-sort__nojs', root );
		this.ui = qs( '.ecsa-sort__ui', root );
		this.trigger = qs( '.ecsa-sort-trigger', root );
		this.valueEl = qs( '.ecsa-sort-trigger__value', root );

		this.menu = null;
		this.rows = [];
		this.usePopover = false;
		this.open = false;
		this.repositionScheduled = false;
		this.healChecked = false;
		this.resizeObserver = null;
	}

	SortMenu.prototype.init = function () {
		var self = this;

		if ( ! this.select || ! this.trigger || ! this.ui || ! this.valueEl ) {
			return false;
		}

		this.buildMenu();

		if ( ! this.rows.length ) {

			this.teardown();
			return false;
		}

		if ( this.nojs ) {
			setNodeVisible( this.nojs, false );
		}
		setNodeVisible( this.ui, true );

		this.trigger.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			self.toggle();
		} );

		this.trigger.addEventListener( 'keydown', function ( e ) {
			var key = normalizeKey( e.key );
			if ( 'ArrowDown' === key || 'ArrowUp' === key ) {
				e.preventDefault();
				self.show();
				self.focusRow( 'ArrowUp' === key ? ( self.rows.length - 1 ) : self.selectedIndex() );
			}
		} );

		this._onDocDown = function ( e ) {
			if ( ! self.open ) {
				return;
			}
			if ( self.root.contains( e.target ) || ( self.menu && self.menu.contains( e.target ) ) ) {
				return;
			}
			self.hide();
		};
		document.addEventListener( 'mousedown', this._onDocDown, true );

		this._onKey = function ( e ) {
			if ( ! self.open ) {
				return;
			}
			if ( 'Escape' !== normalizeKey( e.key ) ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			self.hide();
			self.focusTrigger();
		};
		document.addEventListener( 'keydown', this._onKey, true );

		this._onReposition = function () {
			self.scheduleReposition();
		};
		window.addEventListener( 'scroll', this._onReposition, true );
		window.addEventListener( 'resize', this._onReposition );
		if ( typeof window.ResizeObserver === 'function' ) {
			this.resizeObserver = new window.ResizeObserver( this._onReposition );
			this.resizeObserver.observe( this.trigger );
		}

		this.select.addEventListener( 'change', function () {
			self.sync();
		} );

		this.sync();

		return true;
	};

	SortMenu.prototype.buildMenu = function () {
		var self = this;
		var portal = createPortal( 'div', 'ecsa-portal ecsa-sort__menu', this.instance ? this.instance.root : null );

		this.menu = portal.el;
		this.usePopover = portal.usePopover;
		this.menu.setAttribute( 'role', 'menu' );
		this.menu.setAttribute( 'tabindex', '-1' );

		var id = this.trigger.getAttribute( 'aria-controls' );
		if ( id ) {
			this.menu.id = id;
		}

		var label = qs( '.ecsa-sort__label', this.root );
		if ( label && label.textContent ) {
			this.menu.setAttribute( 'aria-label', label.textContent );
		}

		this.rows = [];

		Array.prototype.slice.call( this.select.options ).forEach( function ( option ) {
			var row = el( 'button', 'ecsa-sort__option' );
			row.setAttribute( 'type', 'button' );
			row.setAttribute( 'role', 'menuitemradio' );
			row.setAttribute( 'aria-checked', 'false' );
			row.setAttribute( 'data-ecsa-sort', option.value );
			row.textContent = option.textContent;

			row.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				self.choose( option.value );
			} );

			self.menu.appendChild( row );
			self.rows.push( row );
		} );

		this.menu.addEventListener( 'keydown', function ( e ) {
			self.onMenuKeydown( e );
		} );

		this.menu.addEventListener( 'focusout', function ( e ) {
			if ( ! self.open ) {
				return;
			}
			var to = e.relatedTarget;
			if ( ! to ) {
				return;
			}
			if ( self.menu.contains( to ) || self.root.contains( to ) ) {
				return;
			}
			self.hide();
		} );
	};

	SortMenu.prototype.selectedIndex = function () {
		var value = this.select ? this.select.value : '';
		for ( var i = 0; i < this.rows.length; i++ ) {
			if ( this.rows[ i ].getAttribute( 'data-ecsa-sort' ) === value ) {
				return i;
			}
		}
		return 0;
	};

	SortMenu.prototype.sync = function () {
		var value = this.select ? this.select.value : '';

		this.rows.forEach( function ( row ) {
			row.setAttribute( 'aria-checked', row.getAttribute( 'data-ecsa-sort' ) === value ? 'true' : 'false' );
		} );

		var chosen = this.rows[ this.selectedIndex() ];
		if ( chosen && this.valueEl ) {
			this.valueEl.textContent = chosen.textContent;
		}
	};

	SortMenu.prototype.choose = function ( value ) {
		if ( this.select && this.select.value !== value ) {
			setSelectValue( this.select, value );
			fireChange( this.select );
		}
		this.sync();
		this.hide();
		this.focusTrigger();
	};

	SortMenu.prototype.toggle = function () {
		if ( this.open ) {
			this.hide();
		} else {
			this.show();
		}
	};

	SortMenu.prototype.show = function () {
		if ( this.open || ! this.menu ) {
			return;
		}
		this.open = true;
		this.trigger.setAttribute( 'aria-expanded', 'true' );
		this.usePopover = showPortal( this.menu, this.usePopover );
		this.position();
		this.selfHeal();
	};

	SortMenu.prototype.hide = function () {
		if ( ! this.open || ! this.menu ) {
			return;
		}
		this.open = false;
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		hidePortal( this.menu, this.usePopover );
	};

	SortMenu.prototype.focusTrigger = function () {
		try {
			this.trigger.focus();
		} catch ( e ) {}
	};

	SortMenu.prototype.focusRow = function ( index ) {
		if ( ! this.rows.length ) {
			return;
		}
		var n = this.rows.length;
		var i = ( ( index % n ) + n ) % n;
		try {
			this.rows[ i ].focus();
		} catch ( e ) {}
	};

	SortMenu.prototype.onMenuKeydown = function ( e ) {
		var key = normalizeKey( e.key );
		var index = this.rows.indexOf( e.target );

		if ( 'ArrowDown' === key ) {
			e.preventDefault();
			this.focusRow( index + 1 );
			return;
		}
		if ( 'ArrowUp' === key ) {
			e.preventDefault();
			this.focusRow( index - 1 );
			return;
		}
		if ( 'Home' === key ) {
			e.preventDefault();
			this.focusRow( 0 );
			return;
		}
		if ( 'End' === key ) {
			e.preventDefault();
			this.focusRow( this.rows.length - 1 );
			return;
		}
		if ( 'Tab' === key ) {
			this.hide();
		}
	};

	SortMenu.prototype.position = function () {

		if ( ! positionPortal( this.trigger, this.menu, 'none', 'end' ) ) {
			this.hide();
		}
	};

	SortMenu.prototype.scheduleReposition = function () {
		if ( this.repositionScheduled || ! this.open ) {
			return;
		}
		this.repositionScheduled = true;
		var self = this;
		nextFrame( function () {
			self.repositionScheduled = false;
			if ( self.open ) {
				self.position();
			}
		} );
	};

	SortMenu.prototype.selfHeal = function () {
		if ( this.healChecked ) {
			return;
		}
		this.healChecked = true;

		var self = this;
		nextFrame( function () {
			if ( ! self.open || ! self.menu ) {
				return;
			}
			if ( portalClipped( self.trigger, self.menu, self.usePopover ) ) {
				self.degrade();
			}
		} );
	};

	SortMenu.prototype.degrade = function () {
		warn( 'ECSA: the sort menu could not be shown; falling back to the native select.' );
		this.hide();
		this.teardown();
		setNodeVisible( this.ui, false );
		if ( this.nojs ) {
			setNodeVisible( this.nojs, true );
		}
	};

	SortMenu.prototype.teardown = function () {
		if ( this._onDocDown ) {
			document.removeEventListener( 'mousedown', this._onDocDown, true );
		}
		if ( this._onKey ) {
			document.removeEventListener( 'keydown', this._onKey, true );
		}
		if ( this._onReposition ) {
			window.removeEventListener( 'scroll', this._onReposition, true );
			window.removeEventListener( 'resize', this._onReposition );
		}
		if ( this.resizeObserver ) {
			try {
				this.resizeObserver.disconnect();
			} catch ( e ) {}
			this.resizeObserver = null;
		}
		if ( this.menu && this.menu.parentNode ) {
			this.menu.parentNode.removeChild( this.menu );
		}
		this.menu = null;
		this.rows = [];
	};

	function Results( region, instance ) {
		this.region = region;
		this.id = region.id;
		this.instance = instance;
		this.criteria = instance.criteria;
		this.ownsUrl = false;
		this.bars = [];

		this.configSeed = {
			per_page: instance.criteria.per_page,
			view: instance.criteria.view,

			search_fields: instance.criteria.search_fields,

			time: instance.criteria.time
		};

		this.seq = Core.createSequencer();
		this.token = { superseded: false };
		this.loadToken = { superseded: false };
		this.loadSeq = Core.createSequencer();

		this.loadingTimer = null;
		this.consecutiveFailures = 0;
		this.degradeToForm = false;
		this.focusIntent = null;

		this.firstShown = ( ( this.criteria.page || 1 ) - 1 ) * ( this.criteria.per_page || 12 ) + 1;
		this.shownCount = qsa( '.ecsa-card', region ).length;

		this.countEl = qs( '.ecsa-results__count', region );

		this.sortMenu = null;
		var sortRoot = qs( '.ecsa-results__sort', region );
		if ( sortRoot ) {
			var menu = new SortMenu( sortRoot, instance );
			if ( menu.init() ) {
				this.sortMenu = menu;
			}
		}

		this.neutralizeLiveRegions();
		this.bindEvents();
	}

	Results.prototype.addBar = function ( instance ) {
		if ( this.bars.indexOf( instance ) === -1 ) {
			this.bars.push( instance );
		}
	};

	Results.prototype.neutralizeLiveRegions = function () {
		if ( this.countEl ) {
			this.countEl.setAttribute( 'aria-live', 'off' );
		}

		qsa( '.ecsa-state', this.region ).forEach( function ( block ) {
			block.setAttribute( 'aria-live', 'off' );
		} );
	};

	Results.prototype.bindEvents = function () {
		var self = this;

		this.region.addEventListener( 'click', function ( e ) {

			var viewOption = closest( e.target, '.ecsa-view-toggle__option' );
			if ( viewOption ) {
				e.preventDefault();
				self.setView( viewOption.getAttribute( 'data-ecsa-view' ), { push: true } );
				return;
			}
			var loadMore = closest( e.target, '.ecsa-load-more' );
			if ( loadMore ) {
				e.preventDefault();
				self.loadMore( loadMore );
				return;
			}
			var chip = closest( e.target, '.ecsa-active-filter' );
			if ( chip ) {
				e.preventDefault();
				self.removeFilter( chip );
				return;
			}
			if ( closest( e.target, '.ecsa-clear-all' ) ) {
				e.preventDefault();
				self.clearAll();
				return;
			}
			if ( closest( e.target, '.ecsa-retry' ) ) {
				e.preventDefault();
				self.retry();
				return;
			}
			var widen = closest( e.target, '.ecsa-search-past' );
			if ( widen ) {
				e.preventDefault();

				if ( 'upcoming' !== self.criteria.time ) {
					return;
				}

				self.focusStatus();
				widen.hidden = true;

				self.applyPatch( { time: 'all', sort: 'date_desc' }, { push: true } );
				return;
			}
			var pastLink = closest( e.target, '.ecsa-past-link' );
			if ( pastLink ) {
				e.preventDefault();

				self.applyPatch( { time: 'past', sort: 'date_desc' }, { push: true } );
			}
		} );

		this.region.addEventListener( 'change', function ( e ) {
			if ( e.target && 'ecsa_sort' === e.target.name ) {
				self.applyPatch( { sort: e.target.value }, { push: true } );
			}
		} );

		this.region.addEventListener( 'submit', function ( e ) {
			if ( closest( e.target, '.ecsa-view-toggle' ) ) {
				e.preventDefault();
			}
		} );

		this.paintView( this.criteria.view );
	};

	Results.prototype.setView = function ( view, opts ) {
		opts = opts || {};
		view = ( 'list' === view ) ? 'list' : 'grid';

		if ( view === this.criteria.view ) {

			this.paintView( view );
			return;
		}

		this.criteria = assign( {}, this.criteria, { view: view } );
		this.paintView( view );

		if ( false !== opts.persist ) {
			writeStoredView( view );
		}

		if ( this.ownsUrl && false !== opts.writeHistory && window.history && window.history.replaceState ) {
			var url = toUrl( this.baseUrl(), this.criteria );
			try {
				if ( opts.push ) {
					window.history.pushState( { ecsa: 1 }, '', url );
				} else {
					window.history.replaceState( { ecsa: 1 }, '', url );
				}
			} catch ( e ) {}
		}

		if ( false !== opts.announce ) {

			var option = this.viewOption( view );
			if ( option && option.getAttribute( 'aria-label' ) ) {
				announce( option.getAttribute( 'aria-label' ) );
			}
		}
	};

	Results.prototype.paintView = function ( view ) {
		view = ( 'list' === view ) ? 'list' : 'grid';

		if ( this.region ) {
			this.region.setAttribute( 'data-ecsa-view', view );
		}

		var list = qs( '.ecsa-cards', this.region );
		if ( list ) {
			list.className = 'ecsa-cards ecsa-cards--' + view;
		}

		this.syncViewToggle( view );
	};

	Results.prototype.viewOption = function ( view ) {
		var toggle = qs( '.ecsa-view-toggle', this.region );
		return toggle ? qs( '[data-ecsa-view="' + view + '"]', toggle ) : null;
	};

	Results.prototype.syncViewToggle = function ( view ) {
		var toggle = qs( '.ecsa-view-toggle', this.region );
		if ( ! toggle ) {
			return;
		}

		var submits = '1' === toggle.getAttribute( 'data-ecsa-view-submit' );
		var self = this;

		qsa( '[data-ecsa-view]', toggle ).forEach( function ( option ) {
			var key = option.getAttribute( 'data-ecsa-view' );
			var wantActive = ( key === view );

			if ( wantActive === ( 'BUTTON' !== option.tagName ) ) {
				option.setAttribute( 'aria-pressed', wantActive ? 'true' : 'false' );
				return;
			}

			self.swapViewOption( toggle, option, key, wantActive, submits );
		} );
	};

	Results.prototype.swapViewOption = function ( toggle, option, key, active, submits ) {
		var doc = option.ownerDocument || document;
		var hadFocus = ( doc.activeElement === option );
		var next = doc.createElement( active ? 'span' : 'button' );
		var label = option.getAttribute( 'aria-label' );

		next.className = 'ecsa-view-toggle__option' + ( active ? ' is-active' : '' );
		next.setAttribute( 'data-ecsa-view', key );
		next.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		if ( label ) {
			next.setAttribute( 'aria-label', label );
		}

		if ( active ) {
			next.setAttribute( 'role', 'button' );
			next.setAttribute( 'aria-disabled', 'true' );
			next.setAttribute( 'tabindex', '-1' );
		} else if ( submits ) {

			next.setAttribute( 'type', 'submit' );
			next.setAttribute( 'name', 'ecsa_view' );
			next.setAttribute( 'value', key );
		} else {
			next.setAttribute( 'type', 'button' );
		}

		while ( option.firstChild ) {
			next.appendChild( option.firstChild );
		}

		toggle.replaceChild( next, option );

		if ( hadFocus ) {
			try {
				next.focus();
			} catch ( e ) {}
		}
	};

	Results.prototype.applyPatch = function ( patch, opts ) {
		opts = opts || {};
		var next = assign( {}, this.criteria, patch );
		if ( ! opts.keepPage ) {
			next.page = 1;
		}
		this.apply( UrlState.normalize( next ), { push: ! ! opts.push, announce: true } );
	};

	Results.prototype.apply = function ( criteria, opts ) {
		opts = opts || {};

		this.criteria = opts.source ? this.mergeFromBar( criteria ) : criteria;
		criteria      = this.criteria;

		var source = opts.source || null;
		this.bars.forEach( function ( bar ) {
			if ( bar !== source ) {
				bar.syncControls( criteria );
			}
		} );

		this.syncSort( criteria );

		if ( this.degradeToForm ) {
			window.location.href = toUrl( this.baseUrl(), criteria );
			return;
		}

		if ( this.ownsUrl && opts.writeHistory !== false && window.history && window.history.replaceState ) {
			var url = toUrl( this.baseUrl(), criteria );
			try {
				if ( opts.push ) {
					window.history.pushState( { ecsa: 1 }, '', url );
				} else {
					window.history.replaceState( { ecsa: 1 }, '', url );
				}
			} catch ( e ) {}
		}

		this.fetch( criteria, { announce: opts.announce !== false } );
	};

	var REGION_OWNED = [ 'per_page', 'view', 'search_fields', 'time', 'sort' ];

	Results.prototype.mergeFromBar = function ( criteria ) {
		var merged = assign( {}, criteria );
		var mine = this.criteria || {};

		REGION_OWNED.forEach( function ( key ) {
			if ( undefined !== mine[ key ] ) {
				merged[ key ] = mine[ key ];
			}
		} );

		return merged;
	};

	Results.prototype.baseUrl = function () {
		if ( this.bars.length && this.bars[ 0 ].action ) {
			return this.bars[ 0 ].action;
		}
		if ( this.instance && this.instance.action ) {
			return this.instance.action;
		}
		return stripEcsa( window.location.href );
	};

	Results.prototype.searchInput = function () {
		for ( var i = 0; i < this.bars.length; i++ ) {
			if ( this.bars[ i ].input ) {
				return this.bars[ i ].input;
			}
		}
		return null;
	};

	Results.prototype.fetch = function ( criteria, opts ) {
		var self = this;
		var q = String( criteria.q || '' ).trim();

		if ( q && ! keywordRestReady( q ) ) {
			this.criteria = criteria;
			this.setBusy( false );
			this.hideError();
			return;
		}

		var seqId = this.seq.issue();

		this.token.superseded = true;
		if ( this.token.controller ) {
			try {
				this.token.controller.abort();
			} catch ( e ) {}
		}
		var token = { superseded: false };
		this.token = token;

		this.setBusy( true );

		var url = restUrl( 'events', buildRestQuery( criteria ) );
		requestJson( url, token, function ( err, data ) {
			if ( ! self.seq.accept( seqId ) ) {
				return;
			}
			self.setBusy( false );
			if ( err ) {
				self.showError();
				return;
			}
			self.consecutiveFailures = 0;
			self.degradeToForm = false;
			self.renderFull( criteria, data, opts || {} );
		} );
	};

	Results.prototype.setBusy = function ( busy ) {
		var self = this;
		if ( busy ) {
			this.region.setAttribute( 'aria-busy', 'true' );
			if ( null === this.loadingTimer ) {
				this.loadingTimer = window.setTimeout( function () {
					self.loadingTimer = null;
					if ( self.region.getAttribute( 'aria-busy' ) === 'true' ) {
						self.region.classList.add( 'ecsa-is-loading' );
					}
				}, LOADING_DELAY );
			}
		} else {
			if ( null !== this.loadingTimer ) {
				window.clearTimeout( this.loadingTimer );
				this.loadingTimer = null;
			}
			this.region.setAttribute( 'aria-busy', 'false' );
			this.region.classList.remove( 'ecsa-is-loading' );
		}
	};

	Results.prototype.renderFull = function ( criteria, data, opts ) {
		var items = ( data && Array.isArray( data.items ) ) ? data.items : [];
		this.criteria = criteria;
		this.firstShown = ( ( criteria.page || 1 ) - 1 ) * ( criteria.per_page || 12 ) + 1;
		this.shownCount = items.length;

		this.hideError();

		if ( items.length ) {
			this.buildCards( items, criteria, false );
			this.updateLoadMore( criteria, data );
		} else {
			this.clearCards();
			this.removeLoadMore();
		}

		this.paintView( criteria.view );

		this.updateCount( data );
		this.rebuildActiveFilters( criteria );
		this.syncNoQueryCopy( criteria );
		this.toggleStates( items.length ? 'cards' : Core.classifyState( items, criteria ) );

		this.syncRecoveryLink( criteria );

		this.applyFocusIntent();

		if ( false !== opts.announce ) {
			if ( items.length ) {
				this.announceResults( data );
			}

		}
	};

	Results.prototype.cardsList = function ( createIfMissing, view ) {
		var list = qs( '.ecsa-cards', this.region );
		if ( ! list && createIfMissing ) {
			list = el( 'ul', 'ecsa-cards ecsa-cards--' + ( view || 'grid' ) );
			var anchor = qs( '.ecsa-state', this.region ) || qs( '.ecsa-load-more', this.region );
			if ( anchor ) {
				this.region.insertBefore( list, anchor );
			} else {
				this.region.appendChild( list );
			}
		}
		if ( list && view ) {
			list.className = 'ecsa-cards ecsa-cards--' + view;
		}
		return list;
	};

	Results.prototype.clearCards = function () {
		var list = qs( '.ecsa-cards', this.region );
		if ( list && list.parentNode ) {
			list.parentNode.removeChild( list );
		}
	};

	Results.prototype.buildCards = function ( items, criteria, append ) {
		var view = ( 'list' === criteria.view ) ? 'list' : 'grid';
		var list = this.cardsList( true, view );

		if ( ! append ) {
			empty( list );
		}

		var self = this;
		items.forEach( function ( item, i ) {
			var li = self.buildCard( item );
			if ( append && 0 === i ) {
				li.setAttribute( 'tabindex', '-1' );
				li.setAttribute( 'data-ecsa-new', '1' );
			}
			list.appendChild( li );
		} );
	};

	var CARD_ICONS = {
		date: [
			[ 'rect', { x: '3', y: '4.5', width: '18', height: '17', rx: '2.5' } ],
			[ 'path', { d: 'M3 10h18' } ],
			[ 'path', { d: 'M8 2.5v4' } ],
			[ 'path', { d: 'M16 2.5v4' } ]
		],
		time: [
			[ 'circle', { cx: '12', cy: '12', r: '9' } ],
			[ 'polyline', { points: '12 6.5 12 12.25 16 14.25' } ]
		],
		venue: [
			[ 'path', { d: 'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0Z' } ],
			[ 'circle', { cx: '12', cy: '10', r: '3' } ]
		],
		cost: [
			[ 'path', { d: 'M20.6 13.4 13.4 20.6a2 2 0 0 1 -2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z' } ],
			[ 'path', { d: 'M7.5 7.5h.01' } ]
		]
	};

	function cardIcon( key ) {
		var parts = Object.prototype.hasOwnProperty.call( CARD_ICONS, key ) ? CARD_ICONS[ key ] : null;

		if ( ! parts || ! document.createElementNS ) {
			return null;
		}

		var NS = 'http://www.w3.org/2000/svg';

		function node( name, attrs ) {
			var svgNode = document.createElementNS( NS, name );
			for ( var attr in attrs ) {
				if ( Object.prototype.hasOwnProperty.call( attrs, attr ) ) {
					svgNode.setAttribute( attr, attrs[ attr ] );
				}
			}
			return svgNode;
		}

		var svg = node( 'svg', {
			viewBox: '0 0 24 24',
			width: '13',
			height: '13',
			fill: 'none',
			stroke: 'currentColor',
			'stroke-width': '1.9',
			'stroke-linecap': 'round',
			'stroke-linejoin': 'round',
			focusable: 'false'
		} );

		parts.forEach( function ( part ) {
			svg.appendChild( node( part[ 0 ], part[ 1 ] ) );
		} );

		var span = el( 'span', 'ecsa-card__icon' );
		span.setAttribute( 'aria-hidden', 'true' );
		span.appendChild( svg );

		return span;
	}

	function prependCardIcon( line, key ) {
		var icon = cardIcon( key );

		if ( icon ) {
			line.insertBefore( icon, line.firstChild );
		}
	}

	Results.prototype.buildCard = function ( item ) {
		var fields = this.cardFields();
		var li = el( 'li', 'ecsa-card' );
		if ( item && null != item.event_id ) {
			li.setAttribute( 'data-ecsa-event', String( item.event_id ) );
		}

		var link = el( 'a', 'ecsa-card__link' );
		link.setAttribute( 'href', ( item && item.url ) ? String( item.url ) : '#' );

		var hasThumb = !! ( item && item.thumbnail && fields.image );
		var wantsDate = !! ( item && item.start && fields.date );

		if ( hasThumb ) {
			var img = el( 'img', 'ecsa-card__thumb' );
			img.setAttribute( 'src', String( item.thumbnail ) );
			img.setAttribute( 'alt', '' );
			img.setAttribute( 'loading', 'lazy' );
			link.appendChild( img );
		}

		var body = el( 'div', 'ecsa-card__body' );

		if ( fields.title ) {
			var title = el( 'h3', 'ecsa-card__title' );
			title.textContent = ( item && item.title ) ? String( item.title ) : '';
			body.appendChild( title );
		}

		if ( wantsDate ) {

			var line = formatDate( item.start, item.all_day, this.dateFormat(), item.end );

			if ( line ) {
				var date = el( 'p', 'ecsa-card__date' );
				date.textContent = line;

				prependCardIcon( date, 'date' );
				body.appendChild( date );
			}
		}
		if ( item && item.venue && fields.venue ) {
			var venue = el( 'p', 'ecsa-card__venue' );
			venue.textContent = String( item.venue );
			prependCardIcon( venue, 'venue' );
			body.appendChild( venue );
		}
		if ( item && item.cost && fields.cost ) {
			var cost = el( 'p', 'ecsa-card__cost' );
			cost.textContent = String( item.cost );
			prependCardIcon( cost, 'cost' );
			body.appendChild( cost );
		}

		if ( body.firstChild ) {
			link.appendChild( body );
		}

		li.appendChild( link );
		return li;
	};

	Results.prototype.dateFormat = function () {
		if ( this._dateFormat ) {
			return this._dateFormat;
		}

		var raw = this.region ? this.region.getAttribute( 'data-ecsa-date-format' ) : null;

		this._dateFormat = ( raw && Object.prototype.hasOwnProperty.call( DATE_FORMAT_OPTS, raw ) ) ? raw : 'site';

		return this._dateFormat;
	};

	Results.prototype.cardFields = function () {
		if ( this._cardFields ) {
			return this._cardFields;
		}

		var all = { image: true, title: true, date: true, venue: true, cost: true };
		var raw = this.region ? this.region.getAttribute( 'data-ecsa-card-fields' ) : null;

		if ( ! raw ) {
			this._cardFields = all;
			return all;
		}

		var map = { image: false, title: false, date: false, venue: false, cost: false };
		raw.split( ',' ).forEach( function ( key ) {
			var name = key.replace( /\s+/g, '' );
			if ( Object.prototype.hasOwnProperty.call( map, name ) ) {
				map[ name ] = true;
			}
		} );

		this._cardFields = map;
		return map;
	};

	Results.prototype.loadMore = function ( linkEl ) {
		var self = this;
		var nextPage = ( this.criteria.page || 1 ) + 1;
		var criteria = UrlState.normalize( assign( {}, this.criteria, { page: nextPage } ) );
		var q = String( criteria.q || '' ).trim();

		if ( q && ! keywordRestReady( q ) ) {
			linkEl.removeAttribute( 'aria-disabled' );
			this.setBusy( false );
			this.hideError();
			return;
		}

		var seqId = this.loadSeq.issue();
		this.loadToken.superseded = true;
		if ( this.loadToken.controller ) {
			try {
				this.loadToken.controller.abort();
			} catch ( e ) {}
		}
		var token = { superseded: false };
		this.loadToken = token;

		linkEl.setAttribute( 'aria-disabled', 'true' );
		this.setBusy( true );

		var url = restUrl( 'events', buildRestQuery( criteria ) );
		requestJson( url, token, function ( err, data ) {
			if ( ! self.loadSeq.accept( seqId ) ) {
				return;
			}
			self.setBusy( false );
			linkEl.removeAttribute( 'aria-disabled' );
			if ( err ) {
				self.showError();
				return;
			}

			var items = ( data && Array.isArray( data.items ) ) ? data.items : [];
			self.criteria = criteria;
			self.shownCount += items.length;

			if ( self.ownsUrl && window.history && window.history.pushState ) {
				try {
					window.history.pushState( { ecsa: 1 }, '', toUrl( self.baseUrl(), criteria ) );
				} catch ( e ) {}
			}

			self.appendBatch( items, criteria, nextPage );
			self.updateCount( data );

			if ( data && data.has_more ) {
				self.updateLoadMore( criteria, data );
				if ( linkEl.parentNode ) {
					try {
						linkEl.focus();
					} catch ( e ) {}
				}
			} else {

				self.focusStatus();
				self.removeLoadMore();
			}

			self.announceLoadMore( items.length, data );
		} );
	};

	Results.prototype.appendBatch = function ( items, criteria, page ) {
		if ( ! items.length ) {
			return;
		}
		var view = ( 'list' === criteria.view ) ? 'list' : 'grid';
		var list = this.cardsList( true, view );

		var heading = el( 'li', 'ecsa-cards__batch-heading screen-reader-text' );
		heading.setAttribute( 'role', 'presentation' );
		var h = el( 'h3' );
		h.setAttribute( 'tabindex', '-1' );
		h.textContent = sprintf(

			__( 'Page %d', 'events-search-addon-for-the-events-calendar' ),
			page
		);
		heading.appendChild( h );
		list.appendChild( heading );

		var self = this;
		items.forEach( function ( item, i ) {
			var li = self.buildCard( item );
			if ( 0 === i ) {
				li.setAttribute( 'tabindex', '-1' );
			}
			list.appendChild( li );
		} );
	};

	Results.prototype.updateLoadMore = function ( criteria, data ) {
		var hasMore = ! ! ( data && data.has_more );
		var link = qs( '.ecsa-load-more', this.region );

		if ( ! hasMore ) {
			this.removeLoadMore();
			return;
		}

		if ( ! link ) {
			link = el( 'a', 'ecsa-load-more' );
			link.setAttribute( 'rel', 'next' );
			link.textContent = __( 'Load more events', 'events-search-addon-for-the-events-calendar' );
			var anchor = qs( '.ecsa-state', this.region );
			if ( anchor ) {
				this.region.insertBefore( link, anchor );
			} else {
				this.region.appendChild( link );
			}
		}
		var next = assign( {}, criteria, { page: ( criteria.page || 1 ) + 1 } );
		link.setAttribute( 'href', toUrl( this.baseUrl(), next ) );
	};

	Results.prototype.removeLoadMore = function () {
		var link = qs( '.ecsa-load-more', this.region );
		if ( link && link.parentNode ) {
			link.parentNode.removeChild( link );
		}
	};

	Results.prototype.focusStatus = function () {
		if ( this.countEl ) {
			this.countEl.setAttribute( 'tabindex', '-1' );
			try {
				this.countEl.focus();
			} catch ( e ) {}
		}
	};

	function paintMarkedNumbers( target, text ) {
		empty( target );

		String( text ).split( COUNT_MARK ).forEach( function ( part, i ) {
			if ( '' === part ) {
				return;
			}
			if ( i % 2 ) {
				var strong = document.createElement( 'strong' );
				strong.textContent = part;
				target.appendChild( strong );
				return;
			}
			target.appendChild( document.createTextNode( part ) );
		} );
	}

	function markNumber( value ) {
		return COUNT_MARK + String( value ) + COUNT_MARK;
	}

	Results.prototype.updateCount = function ( data ) {
		if ( ! this.countEl ) {
			return;
		}
		var total = ( data && null != data.total ) ? parseInt( data.total, 10 ) : null;
		var capped = ! ! ( data && data.total_capped );
		var first = this.firstShown;
		var last = this.firstShown + this.shownCount - 1;

		if ( this.shownCount <= 0 ) {
			this.countEl.textContent = __( 'No events found', 'events-search-addon-for-the-events-calendar' );
			return;
		}

		if ( null !== total ) {
			var display = ( capped || total > TOTAL_DISPLAY_CAP ) ? ( TOTAL_DISPLAY_CAP + '+' ) : String( total );
			paintMarkedNumbers( this.countEl, sprintf(

				__( 'Showing %1$s–%2$s of %3$s', 'events-search-addon-for-the-events-calendar' ),
				markNumber( first ),
				markNumber( last ),
				markNumber( display )
			) );
		} else {
			paintMarkedNumbers( this.countEl, sprintf(

				__( 'Showing %1$s–%2$s', 'events-search-addon-for-the-events-calendar' ),
				markNumber( first ),
				markNumber( last )
			) );
		}
	};

	function timeLabel( value ) {
		if ( 'past' === value ) {
			return __( 'Past events', 'events-search-addon-for-the-events-calendar' );
		}
		if ( 'all' === value ) {
			return __( 'Past and upcoming', 'events-search-addon-for-the-events-calendar' );
		}
		return String( value );
	}

	Results.prototype.rebuildActiveFilters = function ( criteria ) {
		var container = qs( '.ecsa-active-filters', this.region );
		var chips = [];

		if ( criteria.date_preset && 'any' !== criteria.date_preset ) {
			chips.push( {
				token: 'date:' + criteria.date_preset,
				label: this.resolveLabel( 'date', criteria.date_preset )
			} );
		}

		if ( criteria.time && 'upcoming' !== criteria.time ) {
			chips.push( { token: 'time:' + criteria.time, label: timeLabel( criteria.time ) } );
		}

		if ( ! chips.length ) {
			if ( container && container.parentNode ) {
				container.parentNode.removeChild( container );
			}
			return;
		}

		if ( ! container ) {
			container = el( 'div', 'ecsa-active-filters' );
			var bar = qs( '.ecsa-results__bar', this.region );

			this.region.insertBefore( container, bar ? bar.nextSibling : this.region.firstChild );
		} else {
			empty( container );
		}

		chips.forEach( function ( chip ) {
			var btn = el( 'button', 'ecsa-active-filter' );
			btn.setAttribute( 'type', 'button' );
			btn.setAttribute( 'data-ecsa-remove', chip.token );
			btn.setAttribute( 'aria-label', sprintf(

				__( 'Remove filter: %s', 'events-search-addon-for-the-events-calendar' ),
				chip.label
			) );
			btn.appendChild( document.createTextNode( chip.label + ' ' ) );
			var x = el( 'span' );
			x.setAttribute( 'aria-hidden', 'true' );
			x.textContent = '×';
			btn.appendChild( x );
			container.appendChild( btn );
		} );

		var clearBtn = el( 'button', 'ecsa-clear-all' );
		clearBtn.setAttribute( 'type', 'button' );
		clearBtn.textContent = __( 'Clear all', 'events-search-addon-for-the-events-calendar' );
		container.appendChild( clearBtn );
	};

	Results.prototype.resolveLabel = function ( facet, key ) {
		for ( var i = 0; i < this.bars.length; i++ ) {
			var maps = this.bars[ i ].labels;
			if ( maps && maps[ facet ] && maps[ facet ][ String( key ) ] ) {
				return maps[ facet ][ String( key ) ];
			}
		}
		return ( 'date' === facet ) ? String( key ) : ( '#' + key );
	};

	Results.prototype.syncNoQueryCopy = function ( criteria ) {
		var block = qs( '.ecsa-state--no-query', this.region );
		if ( ! block ) {
			return;
		}

		var p = qs( 'p', block );
		if ( ! p ) {
			return;
		}

		p.textContent = sprintf(

			__( 'No events match “%s”.', 'events-search-addon-for-the-events-calendar' ),
			String( ( criteria && criteria.q ) || '' )
		);
	};

	Results.prototype.syncRecoveryLink = function ( criteria ) {
		var link = qs( '.ecsa-search-past', this.region );
		if ( ! link ) {
			return;
		}

		var applies = ( 'upcoming' === ( ( criteria && criteria.time ) || 'upcoming' ) );

		if ( ! applies && document.activeElement === link ) {
			this.focusStatus();
		}

		link.hidden = ! applies;
	};

	Results.prototype.toggleStates = function ( state ) {
		var self = this;
		[ 'no-query', 'no-filters', 'no-upcoming', 'empty' ].forEach( function ( key ) {
			var block = qs( '.ecsa-state--' + key, self.region );
			if ( block ) {
				if ( state === key ) {
					block.hidden = false;
				} else {
					block.hidden = true;
				}
			}
		} );

		if ( 'cards' !== state && 'error' !== state ) {

			var block = qs( '.ecsa-state--' + state, this.region );
			var active = block ? qsa( 'p', block ).filter( function ( p ) {
				return ! isNodeHidden( p );
			} )[ 0 ] : null;

			if ( active ) {
				announce( active.textContent );
			}
		}
	};

	Results.prototype.showError = function () {

		this.focusIntent = null;

		var block = qs( '.ecsa-state--error', this.region );
		if ( block ) {
			block.hidden = false;
			var msg = qs( 'p', block );
			if ( msg ) {
				announce( msg.textContent );
			}
		}
		this.consecutiveFailures += 1;

		if ( this.consecutiveFailures >= 2 ) {
			this.degradeToForm = true;
		}
	};

	Results.prototype.hideError = function () {
		var block = qs( '.ecsa-state--error', this.region );
		if ( block ) {
			block.hidden = true;
		}
	};

	Results.prototype.retry = function () {
		this.hideError();
		this.degradeToForm = false;
		this.fetch( this.criteria, { announce: true } );
	};

	Results.prototype.removeFilter = function ( chip ) {
		var chips = qsa( '.ecsa-active-filter', this.region );
		this.focusIntent = { type: 'chip', idx: chips.indexOf( chip ) };

		var token = chip.getAttribute( 'data-ecsa-remove' ) || '';
		var sep = token.indexOf( ':' );
		var prefix = sep === -1 ? token : token.slice( 0, sep );

		var patch = {};
		if ( 'date' === prefix ) {
			patch.date_preset = 'any';
			patch.date_from = '';
			patch.date_to = '';
		} else if ( 'time' === prefix ) {

			patch.time = this.seedTime();
		}

		this.applyPatch( patch, { push: true } );
	};

	Results.prototype.seedTime = function () {

		return 'upcoming';
	};

	Results.prototype.syncSort = function ( criteria ) {
		if ( ! criteria || ! criteria.sort ) {
			return;
		}

		var select = this.region ? this.region.querySelector( '.ecsa-sort' ) : null;

		if ( select && select.value !== criteria.sort ) {

			var has = Array.prototype.some.call( select.options, function ( o ) {
				return o.value === criteria.sort;
			} );
			if ( has ) {
				select.value = criteria.sort;
			}
		}

		if ( this.sortMenu ) {
			this.sortMenu.sync();
		}
	};

	Results.prototype.clearAll = function () {
		this.focusIntent = { type: 'input' };
		this.applyPatch( {
			q: '',
			date_preset: 'any',
			date_from: '',
			date_to: '',

			time: this.seedTime()
		}, { push: true } );
	};

	Results.prototype.applyFocusIntent = function () {
		var intent = this.focusIntent;
		this.focusIntent = null;
		if ( ! intent ) {
			return;
		}

		var target = null;
		if ( 'chip' === intent.type ) {
			var chips = qsa( '.ecsa-active-filter', this.region );
			target = chips[ intent.idx ] || chips[ intent.idx - 1 ] || qs( '.ecsa-clear-all', this.region ) || this.searchInput();
		} else if ( 'input' === intent.type ) {
			target = this.searchInput();
		} else if ( 'status' === intent.type ) {

			this.focusStatus();
			return;
		}

		if ( target ) {
			try {
				target.focus();
			} catch ( e ) {}
		}
	};

	Results.prototype.announceResults = function ( data ) {
		var total = ( data && null != data.total ) ? parseInt( data.total, 10 ) : null;
		var capped = ! ! ( data && data.total_capped );

		if ( null !== total && ( capped || total > TOTAL_DISPLAY_CAP ) ) {
			announce( sprintf(

				__( 'More than %d results available.', 'events-search-addon-for-the-events-calendar' ),
				TOTAL_DISPLAY_CAP
			) );
			return;
		}

		var n = ( null !== total ) ? total : this.shownCount;
		announce( sprintf(

			_n( '%d result available.', '%d results available.', n, 'events-search-addon-for-the-events-calendar' ),
			n
		) );
	};

	Results.prototype.announceLoadMore = function ( added, data ) {
		var total = ( data && null != data.total ) ? parseInt( data.total, 10 ) : null;
		var totalDisplay = ( null !== total )
			? ( ( ( data && data.total_capped ) || total > TOTAL_DISPLAY_CAP ) ? ( TOTAL_DISPLAY_CAP + '+' ) : String( total ) )
			: String( this.shownCount );

		var loaded = sprintf(

			_n( '%d more event loaded.', '%d more events loaded.', added, 'events-search-addon-for-the-events-calendar' ),
			added
		);
		var shown = sprintf(

			__( '%1$d of %2$s shown.', 'events-search-addon-for-the-events-calendar' ),
			this.shownCount,
			totalDisplay
		);
		announce( loaded + ' ' + shown );
	};

	function setSelectValue( select, value ) {
		select.value = value;
		if ( select.value === value ) {
			return;
		}
		var option = document.createElement( 'option' );
		option.value = value;
		option.textContent = value;
		select.appendChild( option );
		select.value = value;
	}

	function readConfig( root ) {
		return {
			instance: root.getAttribute( 'data-ecsa-instance' ) || '',

			role: root.getAttribute( 'data-ecsa-role' ) || 'bar',
			typeahead: root.getAttribute( 'data-ecsa-typeahead' ) || 'off',
			resultsMode: root.getAttribute( 'data-ecsa-results-mode' ) || 'inline',
			target: root.getAttribute( 'data-ecsa-target' ) || '',
			urlOwner: root.getAttribute( 'data-ecsa-url-owner' ) || '0',
			criteria: root.getAttribute( 'data-ecsa-criteria' ) || '',

			suggestLimit: clampSuggestLimit( root.getAttribute( 'data-ecsa-suggest-limit' ) )
		};
	}

	function clampSuggestLimit( raw ) {
		var n = parseInt( raw, 10 );
		if ( isNaN( n ) ) {
			return SUGGEST_DEFAULT;
		}
		return Math.max( SUGGEST_MIN, Math.min( SUGGEST_CAP, n ) );
	}

	function seedCriteria( root ) {
		var seed = {};
		try {
			seed = JSON.parse( root.getAttribute( 'data-ecsa-state' ) || '{}' );
		} catch ( e ) {
			seed = {};
		}
		if ( ! seed || typeof seed !== 'object' || Array.isArray( seed ) ) {
			seed = {};
		}
		return UrlState.normalize( seed );
	}

	function labelText( label ) {
		var text = '';
		for ( var i = 0; i < label.childNodes.length; i++ ) {
			var node = label.childNodes[ i ];
			if ( 3 === node.nodeType ) {
				text += node.nodeValue;
			} else if ( 1 === node.nodeType && node.className.indexOf( 'ecsa-count' ) === -1 && 'INPUT' !== node.tagName ) {
				text += node.textContent;
			}
		}
		return text.replace( /\s+/g, ' ' ).trim();
	}

	function isCriteriaControlName( name ) {
		var key = String( name || '' ).replace( /\[\]$/, '' );

		if ( ! key ) {
			return false;
		}

		var map = UrlState.PARAM_MAP || [];
		for ( var i = 0; i < map.length; i++ ) {
			if ( map[ i ][ 1 ] === key ) {
				return true;
			}
		}
		return false;
	}

	function isActiveValue( value ) {
		var v = String( ( value === undefined || null === value ) ? '' : value ).trim();
		return '' !== v && 'any' !== v && '0' !== v;
	}

	function countFacetSelections( scope ) {
		var n = 0;
		qsa( 'input[type="checkbox"]:checked, input[type="radio"]:checked', scope ).forEach( function ( control ) {
			if ( isActiveValue( control.value ) ) {
				n++;
			}
		} );
		return n;
	}

	var ROW_DECOR_SEL = '[data-ecsa-count], .ecsa-count, [aria-hidden="true"]';

	var ROW_SUB_SEL = '.ecsa-check__sub, .ecsa-facet__sub, .ecsa-option__sub, [data-ecsa-sub]';

	var ROW_LABEL_SEL = '[data-ecsa-label], .ecsa-check__label, .ecsa-facet__label, .ecsa-facet__row-label, .ecsa-option__label';

	function textWithout( node, selector ) {
		if ( ! node || 1 !== node.nodeType || ! node.cloneNode ) {
			return '';
		}
		var clone = node.cloneNode( true );
		qsa( selector, clone ).forEach( function ( drop ) {
			if ( drop.parentNode ) {
				drop.parentNode.removeChild( drop );
			}
		} );

		qsa( '*', clone ).forEach( function ( child ) {
			if ( child.parentNode ) {
				child.parentNode.insertBefore( document.createTextNode( ' ' ), child.nextSibling );
			}
		} );
		return String( clone.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function optionLabelText( row ) {
		if ( ! row || 1 !== row.nodeType ) {
			return '';
		}
		var main = qs( ROW_LABEL_SEL, row );
		if ( main ) {
			return textWithout( main, ROW_DECOR_SEL );
		}
		return textWithout( row, ROW_DECOR_SEL + ', ' + ROW_SUB_SEL );
	}

	function rowSearchText( row ) {
		return textWithout( row, ROW_DECOR_SEL ).toLowerCase();
	}

	function setNodeVisible( node, visible ) {
		if ( ! node || 1 !== node.nodeType ) {
			return;
		}
		if ( visible ) {
			node.hidden = false;
			if ( 'none' === node.style.display ) {
				node.style.display = '';
			}
			return;
		}
		node.hidden = true;
		node.style.display = 'none';
	}

	function isNodeHidden( node ) {
		if ( ! node || 1 !== node.nodeType ) {
			return true;
		}
		return ! ! node.hidden || 'none' === node.style.display;
	}

	function normalizeKey( key ) {
		switch ( key ) {
			case 'Down':
				return 'ArrowDown';
			case 'Up':
				return 'ArrowUp';
			case 'Left':
				return 'ArrowLeft';
			case 'Right':
				return 'ArrowRight';
			case 'Esc':
				return 'Escape';
			case 'Spacebar':
				return ' ';
			default:
				return key;
		}
	}

	function stripEcsa( href ) {
		var hashIdx = href.indexOf( '#' );
		if ( hashIdx !== -1 ) {
			href = href.slice( 0, hashIdx );
		}
		var qIdx = href.indexOf( '?' );
		if ( qIdx === -1 ) {
			return href;
		}
		var base = href.slice( 0, qIdx );
		var kept = href.slice( qIdx + 1 ).split( '&' ).filter( function ( pair ) {
			return pair && pair.indexOf( 'ecsa_' ) !== 0;
		} );
		return kept.length ? ( base + '?' + kept.join( '&' ) ) : base;
	}

	function hasCjk( value ) {
		return /[぀-ヿ㐀-䶿一-鿿가-힯豈-﫿]/.test( value );
	}

	/**
	 * Mirrors `Criteria::validate()` on the keyword: empty is fine, otherwise
	 * require min length and at least one letter or number before REST.
	 */
	function keywordRestReady( value ) {
		var q = String( value || '' ).trim();

		if ( '' === q ) {
			return true;
		}

		var len = Array.from( q ).length;
		var min = hasCjk( q ) ? 1 : 2;

		if ( len < min || len > 60 ) {
			return false;
		}

		return /[\p{L}\p{N}]/u.test( q );
	}

	function groupSuggest( items ) {
		var order = [];
		var map = {};
		items.forEach( function ( item ) {
			var key = ( item && ( item.group || item.kind ) ) ? String( item.group || item.kind ) : 'events';
			if ( ! map[ key ] ) {
				map[ key ] = [];
				order.push( key );
			}
			map[ key ].push( item );
		} );

		var labelled = order.length > 1 || ( 1 === order.length && 'events' !== order[ 0 ] );

		return { multi: order.length > 1, labelled: labelled, order: order, map: map };
	}

	function groupLabel( key ) {

		switch ( key ) {

			case 'upcoming':
			case 'next':
				return __( 'Other Upcoming Events', 'events-search-addon-for-the-events-calendar' );
			default:
				return __( 'Events', 'events-search-addon-for-the-events-calendar' );
		}
	}

	function hasClippingAncestor( node ) {
		var element = node ? node.parentNode : null;
		while ( element && 1 === element.nodeType && element !== document.body && element !== document.documentElement ) {
			var style = window.getComputedStyle ? window.getComputedStyle( element ) : null;
			if ( style ) {
				var ox = style.overflowX;
				var oy = style.overflowY;
				if ( 'hidden' === ox || 'clip' === ox || 'hidden' === oy || 'clip' === oy ) {
					return true;
				}
			}
			element = element.parentNode;
		}
		return false;
	}

	function hasContainingBlockAncestor( node ) {
		var element = node ? node.parentNode : null;
		while ( element && 1 === element.nodeType && element !== document.body && element !== document.documentElement ) {
			var style = window.getComputedStyle ? window.getComputedStyle( element ) : null;
			if ( style ) {
				if ( style.transform && 'none' !== style.transform ) {
					return true;
				}
				if ( style.perspective && 'none' !== style.perspective ) {
					return true;
				}
				if ( style.filter && 'none' !== style.filter ) {
					return true;
				}
				var wc = style.willChange || '';
				if ( wc.indexOf( 'transform' ) !== -1 || wc.indexOf( 'filter' ) !== -1 || wc.indexOf( 'perspective' ) !== -1 ) {
					return true;
				}
				var contain = style.contain || '';
				if ( contain.indexOf( 'layout' ) !== -1 || contain.indexOf( 'paint' ) !== -1 || contain.indexOf( 'strict' ) !== -1 || contain.indexOf( 'content' ) !== -1 ) {
					return true;
				}

				var ctype = style.containerType || '';
				if ( '' !== ctype && 'normal' !== ctype ) {
					return true;
				}
			}
			element = element.parentNode;
		}
		return false;
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			App.boot();
		} );
	} else {
		App.boot();
	}

	ECSA.frontend = {
		App: App,
		boot: function () {
			App.boot();
		},
		_internals: {
			buildRestQuery: buildRestQuery,
			toUrl: toUrl,
			restUrl: restUrl,
			stripEcsa: stripEcsa,
			hasCjk: hasCjk,
			groupSuggest: groupSuggest,
			seedCriteria: seedCriteria,
			formatDate: formatDate,
			simpleSprintf: simpleSprintf,
			setSelectValue: setSelectValue,
			positionPortal: positionPortal,
			isActiveValue: isActiveValue,
			countFacetSelections: countFacetSelections,
			rowSearchText: rowSearchText,
			normalizeKey: normalizeKey,
			FacetPopover: FacetPopover,
			FacetPanel: FacetPanel,
			SortMenu: SortMenu
		}
	};

}( window, document ) );
