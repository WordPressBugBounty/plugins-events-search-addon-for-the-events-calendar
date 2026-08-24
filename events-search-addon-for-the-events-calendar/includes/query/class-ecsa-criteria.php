<?php
/**
 * Criteria — the one input schema.
 *
 * Every entry point (shortcode, classic widget, REST, TEC view
 * integration) funnels through this class, so there is exactly one place that
 * decides what a valid query looks like.
 *
 * Normalization NEVER throws and never returns a partial array: `normalize()`
 * always yields every key with a safe value, so downstream code can index
 * freely. Rejection is a separate step — `validate()` — because the REST layer
 * must answer 400 with a machine-readable code while a shortcode must silently
 * fall back to defaults for the same bad input.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Criteria' ) ) {

	/**
	 * Normalizes, validates and hashes query criteria.
	 *
	 * @since 2.0.0
	 */
	final class Criteria {

		/**
		 * Allowed date presets. `custom` is the only one that reads
		 * `date_from`/`date_to`.
		 */
		const DATE_PRESETS = array(
			'any',
			'today',
			'tomorrow',
			'this_weekend',
			'this_week',
			'next_week',
			'this_month',
			'next_month',
			'custom',
		);

		/**
		 * Presets whose meaning depends on "now" and therefore cannot be
		 * cached past the site's next local midnight.
		 */
		const RELATIVE_PRESETS = array(
			'today',
			'tomorrow',
			'this_weekend',
			'this_week',
			'next_week',
			'this_month',
			'next_month',
		);

		/**
		 * Allowed time windows.
		 */
		const TIMES = array( 'upcoming', 'past', 'all' );

		/**
		 * Allowed sorts. Each maps to a TOTAL order in Query_Engine — a
		 * non-total order duplicates and skips rows across Load More.
		 */
		const SORTS = array( 'date_asc', 'date_desc', 'title' );

		/**
		 * Occurrence collapse policy.
		 */
		const RECURRENCES = array( 'next_only', 'all_occurrences' );

		/**
		 * What a total counts.
		 */
		const COUNT_MODES = array( 'events', 'occurrences' );

		/**
		 * Result layouts.
		 */
		const VIEWS = array( 'grid', 'list' );

		/**
		 * The facet keys. Used as an allowlist for `facets[]` on REST and for the
		 * settings filter-order array.
		 *
		 * TWO keys, and that is the whole registry: a keyword box and a date
		 * filter. The taxonomy (`category`, `tag`), linked-post (`venue`,
		 * `organizer`) and `location` facets are NOT part of this plugin — they
		 * are not declared here, no option list is computed for them, no renderer
		 * draws them and no query path reads them.
		 *
		 * THIS LIST IS DECLARED TWICE. `Settings::facet_keys()` carries a literal
		 * copy for the request where this class has not loaded; the two are
		 * locked EQUAL rather than each individually correct —
		 * duplicated constants drift, and a fallback that offers a key the
		 * registry does not have is a key that saves and then renders nothing.
		 */
		const FACET_KEYS = array( 'search', 'date' );

		/**
		 * Which fields a keyword search may match — the admin-only search scope
		 * (`search_fields`).
		 *
		 * ONE field: the event title. A single `LIKE` against `post_title`, the
		 * scope v1.3.6 matched, and the only scope whose results a visitor can
		 * explain to themselves. `content`, and the two-pass venue-name /
		 * organizer-name resolution, are not part of this plugin: the engine has
		 * no second pass, so there is nothing here to widen the scope onto.
		 *
		 * Kept as an ARRAY rather than collapsed to a scalar because it is a SET
		 * by contract — REST, the shortcode and the settings schema all speak the
		 * list form, and the axis is a set with one member, not a boolean.
		 */
		const SEARCH_FIELDS = array( 'title' );

		/**
		 * Hard ceiling on `per_page`. `0` is legal and means count-only.
		 */
		const PER_PAGE_MAX = 50;

		/**
		 * Default page size.
		 */
		const PER_PAGE_DEFAULT = 12;

		/**
		 * `page * per_page` may not exceed this. Without it,
		 * `?ecsa_page=2000000` becomes `LIMIT 50 OFFSET 100000000`.
		 */
		const PAGINATION_DEPTH_MAX = 1000;

		/**
		 * Keyword length bounds. CJK gets a lower floor because a single
		 * character is a meaningful query in those scripts.
		 */
		const Q_MIN     = 2;
		const Q_MIN_CJK = 1;
		const Q_MAX     = 60;

		/**
		 * The shipped default criteria.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		public static function defaults() {
			return array(
				'q'             => '',
				'date_preset'   => 'any',
				'date_from'     => '',
				'date_to'       => '',
				'time'          => 'upcoming',
				'sort'          => 'date_asc',
				'recurrence'    => 'next_only',
				'count_mode'    => 'events',
				'page'          => 1,
				'per_page'      => self::PER_PAGE_DEFAULT,
				'view'          => 'grid',
				'search_fields' => self::SEARCH_FIELDS,
			);
		}

		/**
		 * Normalize arbitrary input into a complete, safe criteria array.
		 *
		 * Total function: never throws, never returns a partial array, and
		 * discards unknown keys rather than carrying them through.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $raw Untrusted input.
		 * @return array<string, mixed> Normalized criteria.
		 */
		public static function normalize( array $raw ) {
			$out = self::defaults();

			if ( isset( $raw['q'] ) ) {
				$out['q'] = self::clean_keyword( $raw['q'] );
			}

			if ( isset( $raw['date_preset'] ) && in_array( $raw['date_preset'], self::DATE_PRESETS, true ) ) {
				$out['date_preset'] = $raw['date_preset'];
			}

			/*
			 * date_from/date_to exist ONLY for the custom preset. Keeping them
			 * on a non-custom preset would make two different criteria arrays
			 * that mean the same query hash differently, producing avoidable
			 * cache misses and breaking SSR/REST hash comparison.
			 */
			if ( 'custom' === $out['date_preset'] ) {
				$from = isset( $raw['date_from'] ) ? self::clean_date( $raw['date_from'] ) : '';
				$to   = isset( $raw['date_to'] ) ? self::clean_date( $raw['date_to'] ) : '';

				// A reversed range is a user error, not an empty result set.
				if ( '' !== $from && '' !== $to && $from > $to ) {
					$swap = $from;
					$from = $to;
					$to   = $swap;
				}

				$out['date_from'] = $from;
				$out['date_to']   = $to;

				// A custom preset with no usable bound is just "any".
				if ( '' === $from && '' === $to ) {
					$out['date_preset'] = 'any';
				}
			}

			if ( isset( $raw['search_fields'] ) ) {
				$out['search_fields'] = self::clean_search_fields( $raw['search_fields'] );
			}

			foreach ( array(
				'time'       => self::TIMES,
				'sort'       => self::SORTS,
				'recurrence' => self::RECURRENCES,
				'count_mode' => self::COUNT_MODES,
				'view'       => self::VIEWS,
			) as $key => $allowed ) {
				if ( isset( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ) {
					$out[ $key ] = $raw[ $key ];
				}
			}

			if ( isset( $raw['page'] ) ) {
				$out['page'] = max( 1, (int) $raw['page'] );
			}

			if ( isset( $raw['per_page'] ) ) {
				// 0 is legal: it is the count-only mode that powers the mobile
				// drawer's "Show N events" button.
				$out['per_page'] = min( self::PER_PAGE_MAX, max( 0, (int) $raw['per_page'] ) );
			}

			return $out;
		}

		/**
		 * Check a NORMALIZED criteria array against the complexity budget.
		 *
		 * Split from normalization because the two callers need different
		 * outcomes for the same input: REST must return 400 with a code, a
		 * shortcode must degrade quietly.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $c Normalized criteria.
		 * @return true|\WP_Error True when acceptable.
		 */
		public static function validate( array $c ) {
			$q = isset( $c['q'] ) ? (string) $c['q'] : '';

			if ( '' !== $q ) {
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $q, 'UTF-8' ) : strlen( $q );
				$min = self::has_cjk( $q ) ? self::Q_MIN_CJK : self::Q_MIN;

				if ( $len < $min ) {
					return self::error( 'ecsa_query_too_short', __( 'Search terms must be at least two characters.', 'events-search-addon-for-the-events-calendar' ) );
				}

				if ( $len > self::Q_MAX ) {
					return self::error( 'ecsa_query_too_long', __( 'Search terms must be 60 characters or fewer.', 'events-search-addon-for-the-events-calendar' ) );
				}

				/*
				 * An all-punctuation term is a leading-wildcard LIKE over every
				 * post_title and post_content on the site with no selectivity
				 * whatsoever — the cheapest way to make the database do the most
				 * work. Reject rather than serve.
				 */
				if ( ! preg_match( '/[\p{L}\p{N}]/u', $q ) ) {
					return self::error( 'ecsa_query_not_searchable', __( 'Please include at least one letter or number in your search.', 'events-search-addon-for-the-events-calendar' ) );
				}
			}

			$page     = isset( $c['page'] ) ? (int) $c['page'] : 1;
			$per_page = isset( $c['per_page'] ) ? (int) $c['per_page'] : self::PER_PAGE_DEFAULT;

			if ( $per_page > 0 && ( $page * $per_page ) > self::PAGINATION_DEPTH_MAX ) {
				return self::error(
					'ecsa_pagination_too_deep',
					__( 'That page is too far into the results. Please narrow your filters instead.', 'events-search-addon-for-the-events-calendar' )
				);
			}

			if ( 'custom' === $c['date_preset'] && '' === $c['date_from'] && '' === $c['date_to'] ) {
				return self::error( 'ecsa_invalid_date_range', __( 'A custom date range needs a start or an end date.', 'events-search-addon-for-the-events-calendar' ) );
			}

			return true;
		}

		/**
		 * Stable, fixed-length hash of a normalized criteria array.
		 *
		 * Fixed length is a security property, not tidiness: `q` is free text,
		 * so a hash-derived cache key is the only thing standing between a
		 * public endpoint and an attacker-controlled write primitive against
		 * `wp_options`. Includes ECSA_VERSION so an upgrade invalidates
		 * everything from the previous shape.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $c      Normalized criteria.
		 * @param string               $suffix Optional namespace (e.g. 'facets').
		 * @return string 32 hex characters.
		 */
		public static function hash( array $c, $suffix = '' ) {
			ksort( $c );

			$version = defined( 'ECSA_VERSION' ) ? ECSA_VERSION : '0';

			return substr( hash( 'sha256', wp_json_encode( $c ) . $version . $suffix ), 0, 32 );
		}

		/**
		 * Criteria minus the keyword.
		 *
		 * Facet counts are keyed on this: keyword-narrowed counts are the least
		 * valuable and most expensive thing we could compute, and recomputing
		 * them per keystroke is the difference between a cached facet payload
		 * and a guaranteed cold miss on every request.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $c Normalized criteria.
		 * @return array<string, mixed>
		 */
		public static function without_keyword( array $c ) {
			$c['q'] = '';

			return $c;
		}

		/**
		 * Criteria with one facet field cleared.
		 *
		 * A facet's own counts must be computed against the set filtered by all
		 * OTHER facets, or selecting one option makes every sibling read 0 and the
		 * UI becomes a dead end. `date` is the only clearable facet field this
		 * plugin has — `search` is the keyword, which `without_keyword()` owns.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $c     Normalized criteria.
		 * @param string               $field Field to clear.
		 * @return array<string, mixed>
		 */
		public static function without_field( array $c, $field ) {
			if ( 'date' === $field ) {
				$c['date_preset'] = 'any';
				$c['date_from']   = '';
				$c['date_to']     = '';
			}

			return $c;
		}

		/**
		 * Whether a preset's meaning depends on the current time.
		 *
		 * @since 2.0.0
		 * @param string $preset Preset key.
		 * @return bool
		 */
		public static function is_relative_preset( $preset ) {
			return in_array( $preset, self::RELATIVE_PRESETS, true );
		}

		/**
		 * Whether any facet beyond the keyword is active.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $c Normalized criteria.
		 * @return bool
		 */
		public static function is_filtered( array $c ) {
			return ( 'any' !== $c['date_preset'] || '' !== $c['q'] );
		}

		/**
		 * Sanitize a keyword.
		 *
		 * Strips `%` and `_` because both are LIKE wildcards: left in, a query
		 * of `%` matches every row on the site.
		 *
		 * @since 2.0.0
		 * @param mixed $value Raw value.
		 * @return string
		 */
		private static function clean_keyword( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = sanitize_text_field( wp_unslash( $value ) );
			$value = str_replace( array( '%', '_' ), ' ', $value );
			$value = preg_replace( '/\s+/u', ' ', $value );

			$value = trim( (string) $value );

			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $value, 0, self::Q_MAX, 'UTF-8' );
			}

			return substr( $value, 0, self::Q_MAX );
		}

		/**
		 * Sanitize a `Y-m-d` date, rejecting anything else.
		 *
		 * `checkdate()` matters: `2026-02-31` parses happily through
		 * `strtotime()` into 3 March, silently shifting a user's range.
		 *
		 * @since 2.0.0
		 * @param mixed $value Raw value.
		 * @return string `Y-m-d` or ''.
		 */
		private static function clean_date( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = trim( $value );

			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
				return '';
			}

			if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return '';
			}

			return $value;
		}

		/**
		 * Sanitize the admin-only keyword-search scope (`search_fields`).
		 *
		 * Accepts an array or a comma-separated string (shortcode). Members are
		 * allowlisted against `SEARCH_FIELDS` and emitted in that fixed order, so
		 * two spellings of one scope hash identically.
		 *
		 * An EMPTY or all-invalid value returns every allowed field, never the
		 * empty set: a bar whose keyword box matches nothing is a silent dead end,
		 * so the one safe fallback is "search everything this plugin can search".
		 * With a one-member allowlist that is the same value either way, and the
		 * rule is kept in its general form because the CALLERS still pass sets.
		 * `sanitize_key()` is deliberately NOT used here — Criteria has no other
		 * dependency on it, and a plain lowercase/trim keeps this class
		 * exercisable in isolation.
		 *
		 * @since 2.0.0
		 * @param mixed $value Raw value (array or comma-separated string).
		 * @return string[] A non-empty subset of `SEARCH_FIELDS`, in canonical order.
		 */
		private static function clean_search_fields( $value ) {
			if ( is_string( $value ) ) {
				$value = explode( ',', $value );
			}

			if ( ! is_array( $value ) ) {
				return self::SEARCH_FIELDS;
			}

			$wanted = array();

			foreach ( $value as $field ) {
				if ( ! is_scalar( $field ) ) {
					continue;
				}

				$field = strtolower( trim( (string) $field ) );

				if ( in_array( $field, self::SEARCH_FIELDS, true ) ) {
					$wanted[ $field ] = true;
				}
			}

			$out = array();

			foreach ( self::SEARCH_FIELDS as $field ) {
				if ( isset( $wanted[ $field ] ) ) {
					$out[] = $field;
				}
			}

			// EMPTY -> the whole allowlist, so a bar is never left un-searchable.
			return $out ? $out : self::SEARCH_FIELDS;
		}

		/**
		 * Whether a string contains CJK/Hangul characters.
		 *
		 * @since 2.0.0
		 * @param string $value Value to test.
		 * @return bool
		 */
		private static function has_cjk( $value ) {
			return (bool) preg_match( '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u', $value );
		}

		/**
		 * Build a WP_Error, or a lightweight stand-in outside WordPress.
		 *
		 * The stand-in keeps validate() usable without
		 * booting WordPress.
		 *
		 * @since 2.0.0
		 * @param string $code    Error code.
		 * @param string $message Human-readable message.
		 * @return \WP_Error
		 */
		private static function error( $code, $message ) {
			return new \WP_Error( $code, $message, array( 'status' => 400 ) );
		}
	}
}
