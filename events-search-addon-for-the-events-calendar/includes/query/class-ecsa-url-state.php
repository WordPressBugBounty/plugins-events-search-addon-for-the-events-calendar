<?php
/**
 * URL state — the canonical criteria <-> query-string codec.
 *
 * This is ONE of two implementations that must agree byte-for-byte: the other
 * is the JavaScript twin used by the front end. If they drift, the
 * server-rendered first paint and the client's idea of the current state
 * disagree, and the page either re-fetches on every load or renders one state
 * while the URL claims another. One shared fixture set feeds
 * both implementations and asserts identical output — keep them in step.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Url_State' ) ) {

	/**
	 * Serializes criteria to a query string and back.
	 *
	 * @since 2.0.0
	 */
	final class Url_State {

		/**
		 * Criteria key => URL parameter, in CANONICAL EMISSION ORDER.
		 *
		 * The order is part of the contract, not cosmetic: two URLs describing
		 * the same state must be byte-identical so they hash the same, cache
		 * the same, and compare equal against `data-ecsa-criteria`.
		 *
		 * @var array<string, string>
		 */
		const PARAM_MAP = array(
			'q'           => 'ecsa_q',
			'date_preset' => 'ecsa_date',
			'date_from'   => 'ecsa_from',
			'date_to'     => 'ecsa_to',
			'time'        => 'ecsa_time',
			'sort'        => 'ecsa_sort',
			'view'        => 'ecsa_view',
			'page'        => 'ecsa_page',
			'per_page'    => 'ecsa_per_page',
		);

		/*
		 * NOTE: `ecsa_cat`, `ecsa_tag`, `ecsa_venue` and `ecsa_org` sat between
		 * `ecsa_q` and `ecsa_date` above, and a `LOCATION_MAP` (`ecsa_city`,
		 * `ecsa_state`, `ecsa_country`) was emitted after the scalar block. Those
		 * facets are not part of this plugin, so no URL it produces can carry
		 * them and no URL it reads is allowed to mean anything by them.
		 *
		 * REMOVING THEM FROM THE MAP IS WHAT MAKES THEM INERT, not just absent: an
		 * `?ecsa_cat=3` a visitor pastes in is now an unrecognised parameter that
		 * `parse_present()` never looks for, so it cannot reach `Criteria` and
		 * cannot silently filter anything. The JS twin's map must be cut to match,
		 * or PHP/JS parity breaks.
		 */

		/**
		 * Serialize normalized criteria to a canonical query string.
		 *
		 * Values equal to the shipped default are OMITTED, so an unfiltered
		 * view has a clean, canonical URL and `is_filtered()` can be read
		 * straight off the query string.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $c Normalized criteria.
		 * @return string Query string without a leading `?`; '' when default.
		 */
		public static function serialize( array $c ) {
			$c        = Criteria::normalize( $c );
			$defaults = Criteria::defaults();
			$parts    = array();

			foreach ( self::PARAM_MAP as $key => $param ) {
				$value = isset( $c[ $key ] ) ? $c[ $key ] : null;

				if ( null === $value ) {
					continue;
				}

				/*
				 * NOTE: an ARRAY branch ran here, comma-joining an int list into one
				 * param (`ecsa_cat=3,7`). Every array-valued criteria field was an id
				 * facet, so no key in `PARAM_MAP` is array-typed any more and the
				 * branch was unreachable. Deleted rather than kept "just in case":
				 * the JS twin lost the identical branch, and two unreachable paths
				 * are exactly where parity checking stops being able to tell you
				 * they have diverged.
				 */

				// Omit anything that matches the shipped default.
				if ( isset( $defaults[ $key ] ) && (string) $defaults[ $key ] === (string) $value ) {
					continue;
				}

				if ( '' === (string) $value ) {
					continue;
				}

				$parts[] = $param . '=' . rawurlencode( (string) $value );
			}

			return implode( '&', $parts );
		}

		/**
		 * Extract ONLY the criteria fields the query string actually carried.
		 *
		 * Unlike parse(), this does not fill in defaults. Callers overlay the
		 * result onto an instance seed so config-only fields the URL cannot
		 * express (per_page, view) survive. Byte-mirrors the JS
		 * `UrlState.parsePresent`.
		 *
		 * @since 2.0.0
		 * @param string|array<string, mixed> $input Query string or param array.
		 * @return array<string, mixed> Raw present criteria fields (unnormalized).
		 */
		public static function parse_present( $input ) {
			$params = array();

			if ( is_string( $input ) ) {
				$input = ltrim( $input, '?' );
				parse_str( $input, $params );
			} elseif ( is_array( $input ) ) {
				$params = $input;
			}

			$raw = array();

			foreach ( self::PARAM_MAP as $key => $param ) {
				if ( isset( $params[ $param ] ) ) {
					$raw[ $key ] = $params[ $param ];
				}
			}

			return $raw;
		}

		/**
		 * Parse a query string (or `$_GET`-shaped array) into normalized criteria.
		 *
		 * @since 2.0.0
		 * @param string|array<string, mixed> $input Query string or param array.
		 * @return array<string, mixed> Normalized criteria.
		 */
		public static function parse( $input ) {
			return Criteria::normalize( self::parse_present( $input ) );
		}

		/**
		 * Overlay a URL's present fields onto a seed, then normalize.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed>        $seed  Instance seed criteria.
		 * @param string|array<string, mixed> $input Query string or param array.
		 * @return array<string, mixed> Normalized effective criteria.
		 */
		public static function overlay( array $seed, $input ) {
			return Criteria::normalize( array_merge( $seed, self::parse_present( $input ) ) );
		}

		/**
		 * Build a full URL for the given criteria against a base URL.
		 *
		 * Any pre-existing `ecsa_*` parameters on the base are stripped first,
		 * so repeated calls do not accumulate stale state.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $c    Normalized criteria.
		 * @param string               $base Base URL. Defaults to the current permalink.
		 * @return string
		 */
		public static function to_url( array $c, $base = '' ) {
			if ( '' === $base ) {
				$base = home_url( add_query_arg( array() ) );
			}

			/*
			 * Split the fragment off FIRST. A base of `/events?x=1#agenda`
			 * otherwise parses `1#agenda` as the value of `x`, and the new query
			 * is appended after the fragment where no browser will read it.
			 */
			$fragment = '';
			$hash_at  = strpos( $base, '#' );

			if ( false !== $hash_at ) {
				$fragment = substr( $base, $hash_at );
				$base     = substr( $base, 0, $hash_at );
			}

			$split = explode( '?', $base, 2 );
			$path  = $split[0];
			$kept  = array();

			if ( isset( $split[1] ) ) {
				parse_str( $split[1], $existing );

				$carry = array();

				foreach ( $existing as $key => $value ) {
					// Ours are rebuilt from $c; anything else is the site's and
					// must survive untouched.
					if ( 0 !== strpos( (string) $key, 'ecsa_' ) ) {
						$carry[ $key ] = $value;
					}
				}

				if ( $carry ) {
					/*
					 * http_build_query, not manual concatenation: a base param
					 * can legitimately be an array (`?tag[]=a&tag[]=b`), and
					 * casting one to string is both an "Array to string
					 * conversion" warning and silent data loss.
					 */
					$kept[] = http_build_query( $carry, '', '&', PHP_QUERY_RFC3986 );
				}
			}

			$query = self::serialize( $c );
			$all   = array_filter( array_merge( $kept, array( $query ) ) );

			return ( $all ? $path . '?' . implode( '&', $all ) : $path ) . $fragment;
		}
	}
}
