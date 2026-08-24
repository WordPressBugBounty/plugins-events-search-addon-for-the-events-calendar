
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.ECSA = root.ECSA || {};
		root.ECSA.Core = api;
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var REAL_CLOCK = {
		now: ( typeof Date.now === 'function' ) ? Date.now : function () {
			return new Date().getTime();
		},
		setTimeout: function ( fn, ms ) {
			return setTimeout( fn, ms );
		},
		clearTimeout: function ( id ) {
			return clearTimeout( id );
		}
	};

	function nextIndex( current, key, count ) {
		count = count | 0;

		if ( count <= 0 ) {
			return -1;
		}

		var last = count - 1;

		if ( null === current || undefined === current ) {
			current = -1;
		}
		current = current | 0;

		switch ( key ) {
			case 'ArrowDown':

				return current >= last ? -1 : current + 1;
			case 'ArrowUp':

				return current <= -1 ? last : current - 1;
			case 'Home':
				return 0;
			case 'End':
				return last;
			default:
				return current;
		}
	}

	function createSequencer() {
		var latest = 0;

		return {
			issue: function () {
				latest += 1;
				return latest;
			},
			accept: function ( id ) {
				return id === latest;
			}
		};
	}

	function criteriaChanged( hashA, hashB ) {
		return hashA !== hashB;
	}

	function debounce( fn, wait, clock ) {
		clock = clock || REAL_CLOCK;

		var timer = null;
		var lastArgs = null;
		var lastThis = null;

		function fire() {
			timer = null;
			var args = lastArgs;
			var ctx = lastThis;
			lastArgs = null;
			lastThis = null;
			if ( args ) {
				fn.apply( ctx, args );
			}
		}

		function debounced() {
			lastArgs = arguments;
			lastThis = this;
			if ( null !== timer ) {
				clock.clearTimeout( timer );
			}
			timer = clock.setTimeout( fire, wait );
		}

		debounced.cancel = function () {
			if ( null !== timer ) {
				clock.clearTimeout( timer );
				timer = null;
			}
			lastArgs = null;
			lastThis = null;
		};

		debounced.flush = function () {
			if ( null !== timer ) {
				clock.clearTimeout( timer );
				fire();
			}
		};

		debounced.pending = function () {
			return null !== timer;
		};

		return debounced;
	}

	function throttle( fn, wait, clock ) {
		clock = clock || REAL_CLOCK;

		var last = null;
		var timer = null;
		var lastArgs = null;
		var lastThis = null;

		function invoke( at ) {
			last = at;
			var args = lastArgs;
			var ctx = lastThis;
			lastArgs = null;
			lastThis = null;
			if ( args ) {
				fn.apply( ctx, args );
			}
		}

		function trailing() {
			timer = null;
			invoke( clock.now() );
		}

		function throttled() {
			var now = clock.now();
			lastArgs = arguments;
			lastThis = this;

			if ( null === last || ( now - last ) >= wait ) {
				if ( null !== timer ) {
					clock.clearTimeout( timer );
					timer = null;
				}
				invoke( now );
			} else if ( null === timer ) {
				var remaining = wait - ( now - last );
				timer = clock.setTimeout( trailing, remaining > 0 ? remaining : 0 );
			}
		}

		throttled.cancel = function () {
			if ( null !== timer ) {
				clock.clearTimeout( timer );
				timer = null;
			}
			last = null;
			lastArgs = null;
			lastThis = null;
		};

		throttled.flush = function () {
			if ( null !== timer ) {
				clock.clearTimeout( timer );
				timer = null;
				invoke( clock.now() );
			}
		};

		return throttled;
	}

	function isFiltered( c ) {
		c = c || {};

		if ( c.date_preset && 'any' !== c.date_preset ) {
			return true;
		}

		return ( null != c.q && '' !== String( c.q ) );
	}

	function classifyState( itemsOrResult, criteria ) {
		criteria = criteria || {};

		var items;
		var hasError = false;

		if ( Array.isArray( itemsOrResult ) ) {
			items = itemsOrResult;
		} else if ( itemsOrResult && typeof itemsOrResult === 'object' ) {
			items = ( Array.isArray( itemsOrResult.items ) ) ? itemsOrResult.items : [];
			hasError = ! ! itemsOrResult.error;
		} else {
			items = [];
		}

		if ( hasError ) {
			return 'error';
		}

		if ( items.length > 0 ) {
			return 'cards';
		}

		var keyword = ( null != criteria.q && '' !== String( criteria.q ) );
		if ( keyword ) {
			return 'no-query';
		}

		if ( isFiltered( criteria ) ) {

			return 'no-filters';
		}

		var time = criteria.time || 'upcoming';
		if ( 'upcoming' === time ) {
			return 'no-upcoming';
		}

		return 'empty';
	}

	return {
		nextIndex: nextIndex,
		createSequencer: createSequencer,
		criteriaChanged: criteriaChanged,
		debounce: debounce,
		throttle: throttle,
		classifyState: classifyState,
		isFiltered: isFiltered
	};
} ) );
