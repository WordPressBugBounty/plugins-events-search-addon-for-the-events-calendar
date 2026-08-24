
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.ECSA = root.ECSA || {};
		root.ECSA.UrlState = api;
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var PARAM_MAP = [
		[ 'q', 'ecsa_q' ],
		[ 'date_preset', 'ecsa_date' ],
		[ 'date_from', 'ecsa_from' ],
		[ 'date_to', 'ecsa_to' ],
		[ 'time', 'ecsa_time' ],
		[ 'sort', 'ecsa_sort' ],
		[ 'view', 'ecsa_view' ],
		[ 'page', 'ecsa_page' ],
		[ 'per_page', 'ecsa_per_page' ]
	];

	var DATE_PRESETS = [ 'any', 'today', 'tomorrow', 'this_weekend', 'this_week', 'next_week', 'this_month', 'next_month', 'custom' ];
	var TIMES = [ 'upcoming', 'past', 'all' ];
	var SORTS = [ 'date_asc', 'date_desc', 'title' ];
	var VIEWS = [ 'grid', 'list' ];

	var SEARCH_FIELDS = [ 'title' ];

	var PER_PAGE_MAX = 50;
	var PER_PAGE_DEFAULT = 12;
	var Q_MAX = 60;

	function defaults() {
		return {
			q: '',
			date_preset: 'any',
			date_from: '',
			date_to: '',
			time: 'upcoming',
			sort: 'date_asc',
			recurrence: 'next_only',
			count_mode: 'events',
			page: 1,
			per_page: PER_PAGE_DEFAULT,
			view: 'grid',
			search_fields: SEARCH_FIELDS.slice()
		};
	}

	function cleanSearchFields( value ) {
		if ( typeof value === 'string' ) {
			value = value.split( ',' );
		}
		if ( ! Array.isArray( value ) ) {
			return SEARCH_FIELDS.slice();
		}
		var out = [];
		for ( var i = 0; i < value.length; i++ ) {
			var v = String( value[ i ] ).trim().toLowerCase();
			if ( SEARCH_FIELDS.indexOf( v ) !== -1 && out.indexOf( v ) === -1 ) {
				out.push( v );
			}
		}
		return out.length ? out : SEARCH_FIELDS.slice();
	}

	function rawurlencode( str ) {
		return encodeURIComponent( String( str ) )
			.replace( /!/g, '%21' )
			.replace( /\*/g, '%2A' )
			.replace( /'/g, '%27' )
			.replace( /\(/g, '%28' )
			.replace( /\)/g, '%29' );
	}

	function rawurldecode( str ) {
		return decodeURIComponent( String( str ).replace( /\+/g, '%20' ) );
	}

	function chars( str ) {
		return Array.from( String( str ) );
	}

	function cleanKeyword( value ) {
		if ( typeof value !== 'string' ) {
			return '';
		}

		var v = value.replace( /<[^>]*>/g, '' );
		v = v.replace( /[%_]/g, ' ' );
		v = v.replace( /\s+/g, ' ' );
		v = v.trim();
		var cp = chars( v );
		return cp.length > Q_MAX ? cp.slice( 0, Q_MAX ).join( '' ) : v;
	}

	function cleanDate( value ) {
		if ( typeof value !== 'string' ) {
			return '';
		}
		var m = value.trim().match( /^(\d{4})-(\d{2})-(\d{2})$/ );
		if ( ! m ) {
			return '';
		}
		var y = parseInt( m[ 1 ], 10 );
		var mo = parseInt( m[ 2 ], 10 );
		var d = parseInt( m[ 3 ], 10 );

		if ( mo < 1 || mo > 12 || d < 1 || d > 31 ) {
			return '';
		}
		var dim = [ 31, ( ( y % 4 === 0 && y % 100 !== 0 ) || y % 400 === 0 ) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		if ( d > dim[ mo - 1 ] ) {
			return '';
		}
		return m[ 0 ];
	}

	function inList( value, list ) {
		return list.indexOf( value ) !== -1;
	}

	function normalize( raw ) {
		raw = ( raw && typeof raw === 'object' ) ? raw : {};
		var out = defaults();

		if ( raw.q !== undefined ) {
			out.q = cleanKeyword( raw.q );
		}

		if ( raw.date_preset !== undefined && inList( raw.date_preset, DATE_PRESETS ) ) {
			out.date_preset = raw.date_preset;
		}

		if ( out.date_preset === 'custom' ) {
			var from = raw.date_from !== undefined ? cleanDate( raw.date_from ) : '';
			var to = raw.date_to !== undefined ? cleanDate( raw.date_to ) : '';
			if ( from !== '' && to !== '' && from > to ) {
				var swap = from;
				from = to;
				to = swap;
			}
			out.date_from = from;
			out.date_to = to;
			if ( from === '' && to === '' ) {
				out.date_preset = 'any';
			}
		}

		var enums = { time: TIMES, sort: SORTS, view: VIEWS };
		for ( var key in enums ) {
			if ( enums.hasOwnProperty( key ) && raw[ key ] !== undefined && inList( raw[ key ], enums[ key ] ) ) {
				out[ key ] = raw[ key ];
			}
		}

		if ( raw.page !== undefined ) {
			out.page = Math.max( 1, parseInt( raw.page, 10 ) || 0 );
		}
		if ( raw.per_page !== undefined ) {
			out.per_page = Math.min( PER_PAGE_MAX, Math.max( 0, parseInt( raw.per_page, 10 ) || 0 ) );
		}

		if ( raw.search_fields !== undefined ) {
			out.search_fields = cleanSearchFields( raw.search_fields );
		}

		return out;
	}

	function serialize( criteria ) {
		var c = normalize( criteria );
		var def = defaults();
		var parts = [];

		for ( var i = 0; i < PARAM_MAP.length; i++ ) {
			var key = PARAM_MAP[ i ][ 0 ];
			var param = PARAM_MAP[ i ][ 1 ];
			var value = c[ key ];

			if ( value === undefined || value === null ) {
				continue;
			}

			if ( def[ key ] !== undefined && String( def[ key ] ) === String( value ) ) {
				continue;
			}
			if ( String( value ) === '' ) {
				continue;
			}

			parts.push( param + '=' + rawurlencode( value ) );
		}

		return parts.join( '&' );
	}

	function toParams( input ) {
		var params = {};
		if ( typeof input === 'string' ) {
			var qs = input.replace( /^\??/, '' );
			var pairs = qs.split( '&' );
			for ( var i = 0; i < pairs.length; i++ ) {
				if ( pairs[ i ] === '' ) {
					continue;
				}
				var eq = pairs[ i ].indexOf( '=' );
				var k = eq === -1 ? pairs[ i ] : pairs[ i ].slice( 0, eq );
				var v = eq === -1 ? '' : pairs[ i ].slice( eq + 1 );
				params[ rawurldecode( k ) ] = rawurldecode( v );
			}
		} else if ( input && typeof input === 'object' ) {
			params = input;
		}
		return params;
	}

	function parsePresent( input ) {
		var params = toParams( input );
		var raw = {};

		for ( var a = 0; a < PARAM_MAP.length; a++ ) {
			var pk = PARAM_MAP[ a ][ 0 ];
			var pp = PARAM_MAP[ a ][ 1 ];
			if ( params[ pp ] !== undefined ) {
				raw[ pk ] = params[ pp ];
			}
		}

		return raw;
	}

	function parse( input ) {
		return normalize( parsePresent( input ) );
	}

	function overlay( seed, input ) {
		var merged = {};
		var key;
		seed = ( seed && typeof seed === 'object' ) ? seed : {};
		for ( key in seed ) {
			if ( seed.hasOwnProperty( key ) ) {
				merged[ key ] = seed[ key ];
			}
		}
		var present = parsePresent( input );
		for ( key in present ) {
			if ( present.hasOwnProperty( key ) ) {
				merged[ key ] = present[ key ];
			}
		}
		return normalize( merged );
	}

	return {
		defaults: defaults,
		normalize: normalize,
		serialize: serialize,
		parse: parse,
		parsePresent: parsePresent,
		overlay: overlay,
		PARAM_MAP: PARAM_MAP
	};
} ) );
