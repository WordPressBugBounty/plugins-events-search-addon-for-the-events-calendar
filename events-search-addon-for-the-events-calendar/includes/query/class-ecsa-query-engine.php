<?php
/**
 * Query_Engine — the ONLY code in this plugin allowed to build an event query.
 *
 * Every entry point (REST, shortcode, classic widget, TEC view
 * integration) funnels a normalized Criteria array through this class. Nothing
 * else may touch `tribe_events()`.
 *
 * The eight hard rules of plan §1.2 live here, each retiring a defect verified
 * against live TEC 6.17 source:
 *
 *  1. The keyword path NEVER calls `->search()` on the final repository.
 *     `Repository::search()` sets `query_args['s']` and `Repository::in()` sets
 *     `post__in`; WP_Query ANDs them, so `->search($q)->in($ids)` would AND two
 *     different statements of the same restriction and quietly narrow the
 *     result — invisible to any test that only asserts a non-empty result. The
 *     candidate ID set is built by its own bounded pass and handed to the final
 *     repository as ONE `->in()`. (Punch-list B4.)
 *  2. ONE ID accumulator. `Repository::add_args()` is
 *     `$this->query_args[$key] = (array) $value` — a straight overwrite
 *     despite the name — so `->in()` is last-write-wins and the keyword set and
 *     the occurrence dedup would clobber each other. All producers call
 *     `restrict_to_ids()`; exactly one `->in()` is issued, at build time, and
 *     never with an empty array. (Punch-list B5.)
 *  3. Password-protected events are excluded on EVERY path — the events query,
 *     the keyword candidate pass and `/suggest` — and hard dropped again before
 *     serialization. `post_status = 'publish'` does not exclude them and `s`
 *     matches `post_content`, which turns an unfiltered public search into a
 *     content oracle. Card text comes from `get_the_excerpt()` inside
 *     `setup_postdata()`, never raw `post_content`. (Punch-list B8.)
 *  4. Ranges use `date_overlaps`, never `starts_between` — see
 *     `apply_dates()` for why `on_date` is deliberately not used either. Day
 *     boundaries come from `Tec::beginning_of_day()`/`end_of_day()`, which
 *     pass an explicit site-local `Y-m-d` string. (Punch-list B3.)
 *  5. Occurrence identity: the payload carries BOTH `event_id` (always the
 *     real `wp_posts.ID`) and `occurrence_id`, and only `event_id` is ever
 *     accepted as input. (Punch-list B6.)
 *  6. No `->all()`/`->get_ids()` anywhere without a preceding `->per_page()` —
 *     the repository default is `-1`. (Punch-list B9.)
 *  7. Every sort is a TOTAL order, or Load More duplicates and skips rows.
 *     (Punch-list I11.)
 *  8. `Repository::found()` is never called. It re-runs the whole query with
 *     `SQL_CALC_FOUND_ROWS` over a leading-wildcard LIKE plus a large `IN()` —
 *     the most expensive query in the request. Totals are derived from a
 *     bounded, ordered ID window instead, and are honestly reported as `null`
 *     when they cannot be established cheaply. (Punch-list I8.)
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Query;

use CoolPlugins\EventsSearch\Tec\Tec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Query_Engine' ) ) {

	/**
	 * Resolves normalized criteria into events.
	 *
	 * Instantiable rather than static because the ID accumulator (hard rule 2)
	 * is per-query state: a static accumulator would leak restrictions between
	 * the results query, the facet base set and the TEC view integration inside
	 * a single request.
	 *
	 * @since 2.0.0
	 */
	final class Query_Engine {

		/**
		 * Soft cap on the keyword ID set. Beyond this the set is sliced and
		 * flagged partial, so a two-letter keyword can never hand the final
		 * repository an unbounded `IN ()` list.
		 *
		 * NOTE: a HARD ceiling (2000) sat beside this, for the case where the
		 * keyword set was a UNION of three candidate passes and could exceed any
		 * single pass's cap. There is one pass now and it is bounded by
		 * `CANDIDATE_CAP`, so the ceiling was unreachable arithmetic and is gone.
		 */
		const UNION_SOFT_CAP = 500;

		/**
		 * Hard cap on the keyword `post__in` set handed to TEC's own List view
		 *. `keyword_ids()` can return up to `UNION_SOFT_CAP` rows, and
		 * that lands in a WP_Query `post__in` that TEC ANDs into its native query
		 * — so it is bounded again here to keep the injected `IN ()` list sane. A
		 * cap event is logged, never silent.
		 */
		const TEC_INJECTION_MAX = 500;

		/**
		 * Cap on the keyword candidate pass.
		 */
		const CANDIDATE_CAP = 2000;

		/**
		 * Cap on the bounded ordered-row window, and therefore on the facet
		 * base ID set (plan §4). `Criteria::PAGINATION_DEPTH_MAX` is 1000, so
		 * this comfortably covers every reachable page.
		 */
		const ROW_WINDOW_CAP = 2000;

		/**
		 * Above this, the UI shows "300+" rather than an exact figure.
		 */
		const TOTAL_DISPLAY_CAP = 300;

		/**
		 * Over-fetch for `/suggest`, before dedup by `event_id`.
		 */
		const SUGGEST_OVERFETCH = 24;

		/**
		 * Multiplier applied to the row window when occurrences are collapsed,
		 * so a page of a weekly recurring event still fills.
		 */
		const NEXT_ONLY_OVERFETCH = 3;

		/**
		 * Normalized criteria.
		 *
		 * @since 2.0.0
		 * @var array<string, mixed>
		 */
		private $criteria;

		/**
		 * THE ID accumulator (hard rule 2). `null` means "unrestricted".
		 *
		 * @since 2.0.0
		 * @var int[]|null
		 */
		private $post_in = null;

		/**
		 * True once a producer has yielded an empty ID set. A void engine never
		 * runs a query at all.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private $void = false;

		/**
		 * True when the two-pass degraded or the union was truncated.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private $partial = false;

		/**
		 * Whether `resolve()` has already run.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private $resolved = false;

		/**
		 * Memoized `get_results()` payload.
		 *
		 * @since 2.0.0
		 * @var array<string, mixed>|null
		 */
		private $results = null;

		/**
		 * Ordered raw row IDs from the last window fetch.
		 *
		 * @since 2.0.0
		 * @var int[]|null
		 */
		private $rows = null;

		/**
		 * Row window bookkeeping: the limit used, and whether it truncated.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private $rows_limit = 0;

		/**
		 * Whether the last row window hit its limit.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private $rows_capped = false;

		/**
		 * Per-request memo of resolved ID sets.
		 *
		 * TEC's Month view calls the repository-args filter three times per
		 * render (`Month_View` lines 109, 194, 278), so without this the whole
		 * two-pass resolution runs three times for one page. (Punch-list I26.)
		 *
		 * @since 2.0.0
		 * @var array<string, array<string, mixed>>
		 */
		private static $memo = array();

		/**
		 * Per-request memo of resolved TEC List-view injection fragments, keyed
		 * by `Criteria::hash( $criteria, 'tec_inject' )`.
		 *
		 * TEC resolves the repository args once per render, and the Latest-Past
		 * sub-view can re-fire the `..._view_repository_args` filter, so without
		 * this the whole two-pass keyword resolution would run more than once for
		 * one page. (Punch-list I26.)
		 *
		 * @since 2.0.0
		 * @var array<string, array<string, mixed>>
		 */
		private static $injection_memo = array();

		/**
		 * Whether the scoped `posts_where` password guard is currently attached.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static $password_guard = false;

		/**
		 * Build an engine for a set of criteria.
		 *
		 * The input is re-normalized here rather than trusted: this class is
		 * the last line before the database, and a caller that skipped
		 * `Criteria::normalize()` must not be able to reach the repository with
		 * a partial array.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $criteria Criteria (normalized or raw).
		 * @return void
		 */
		public function __construct( array $criteria ) {
			if ( class_exists( __NAMESPACE__ . '\Criteria' ) ) {
				$this->criteria = Criteria::normalize( $criteria );

				return;
			}

			/*
			 * Criteria is autoloaded, and the autoloader skips a missing file
			 * rather than fatalling. Every method below indexes the criteria
			 * array freely, so the shape is guaranteed here instead of guarded
			 * at forty call sites.
			 */
			$this->criteria = array_merge(
				array(
					'q'             => '',
					'date_preset'   => 'any',
					'date_from'     => '',
					'date_to'       => '',
					'time'          => 'upcoming',
					'sort'          => 'date_asc',
					'recurrence'    => 'next_only',
					'count_mode'    => 'events',
					'page'          => 1,
					'per_page'      => 12,
					'view'          => 'grid',
					'search_fields' => array( 'title' ),
				),
				$criteria
			);
		}

		/**
		 * The normalized criteria this engine was built with.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		public function get_criteria() {
			return $this->criteria;
		}

		/**
		 * Resolve criteria into a page of results.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed> {
		 *     @type array[] $items        Item arrays; see `item_from_row()`.
		 *     @type bool    $has_more     Derived from the per_page+1 over-fetch.
		 *     @type int|null $total       Null when not cheaply establishable.
		 *     @type bool    $total_capped True when the display should read "300+".
		 *     @type bool    $partial      True when the two-pass degraded.
		 *     @type bool    $void         True when a producer yielded no IDs.
		 * }
		 */
		public function get_results() {
			if ( null !== $this->results ) {
				return $this->results;
			}

			$this->results = $this->run();

			return $this->results;
		}

		/**
		 * Event IDs in result order.
		 *
		 * Always real `wp_posts.ID` values, deduplicated, bounded by
		 * `ROW_WINDOW_CAP`. This is the base set every facet group counts
		 * against, and the set the TEC-views
		 * integration intersects into `post__in`.
		 *
		 * @since 2.0.0
		 * @return int[]
		 */
		public function get_ids() {
			if ( ! self::tec_ready() ) {
				return array();
			}

			$this->resolve();

			if ( $this->void ) {
				return array();
			}

			$rows = $this->ordered_rows( self::ROW_WINDOW_CAP );
			$out  = array();

			foreach ( $rows as $row_id ) {
				$event_id = self::normalize_event_id( $row_id );

				if ( $event_id > 0 ) {
					$out[ $event_id ] = $event_id;
				}
			}

			return array_values( $out );
		}

		/**
		 * The result total, or null when it cannot be established cheaply.
		 *
		 * Hard rule 8: `Repository::found()` is never called. The total is the
		 * size of the bounded ordered window, which is EXACT whenever the
		 * window was not truncated. When it was truncated the figure is a floor
		 * and `total_capped` is set, so the UI renders "300+" rather than a
		 * confident lie.
		 *
		 * `count_mode` decides what is being counted: `events` counts distinct
		 * `event_id`s (so a weekly recurring event is one), `occurrences`
		 * counts rows.
		 *
		 * @since 2.0.0
		 * @return int|null
		 */
		public function get_total() {
			if ( ! self::tec_ready() ) {
				return null;
			}

			$this->resolve();

			if ( $this->void ) {
				return 0;
			}

			$limit = $this->should_compute_total()
				? self::ROW_WINDOW_CAP
				: $this->window_for_page();

			$rows = $this->ordered_rows( $limit );

			if ( $this->rows_capped && ! $this->should_compute_total() ) {
				// A truncated window under a keyword query is not a total.
				return null;
			}

			/*
			 * count_mode decides WHAT is counted, independently of what items
			 * are returned:
			 *  - occurrences: every row in the window (a weekly event counts
			 *    once per occurrence in range);
			 *  - events: distinct real wp_posts.ID values (a weekly event is
			 *    one), derived by normalizing each row through TEC's
			 *    occurrence-id filter so provisional ECP ids collapse correctly.
			 * Under free TEC the two are identical (one row per event), which is
			 * why this must be exercised against ECP before it can be trusted —
			 * but the branch is real so the public contract is not a lie.
			 */
			if ( 'occurrences' === $this->criteria['count_mode'] ) {
				return count( $rows );
			}

			$distinct = array();

			foreach ( $rows as $row_id ) {
				$event_id = self::normalize_event_id( $row_id );

				if ( $event_id > 0 ) {
					$distinct[ $event_id ] = true;
				}
			}

			return count( $distinct );
		}

		/**
		 * Distinct-event suggestions for the type-ahead.
		 *
		 * Distinct-event semantics are mandatory, not cosmetic: the Custom
		 * Tables query groups by `occurrence_id`, so an 8-slot dropdown can
		 * otherwise show one weekly event eight times. Over-fetch
		 * 24, dedup by `event_id`, slice, and report `has_more` — never a total.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $criteria Criteria (normalized or raw).
		 * @param int                  $limit    Maximum suggestions to return.
		 * @return array<string, mixed> {
		 *     @type array[] $items    Item arrays.
		 *     @type bool    $has_more Whether more matches exist.
		 *     @type bool    $partial  True when the two-pass degraded.
		 *     @type bool    $void     True when nothing can match.
		 * }
		 */
		public static function suggest( array $criteria, $limit = 8 ) {
			$limit = max( 1, min( 20, (int) $limit ) );

			if ( ! self::tec_ready() ) {
				return array(
					'items'    => array(),
					'has_more' => false,
					'partial'  => false,
					'void'     => false,
				);
			}

			if ( class_exists( __NAMESPACE__ . '\Criteria' ) ) {
				$criteria = Criteria::normalize( $criteria );
			}

			// Suggestions are always distinct events, one page, date ascending.
			$criteria['recurrence'] = 'next_only';
			$criteria['count_mode'] = 'events';
			$criteria['page']       = 1;
			$criteria['per_page']   = self::SUGGEST_OVERFETCH;

			$engine  = new self( $criteria );
			$results = $engine->get_results();
			$items   = isset( $results['items'] ) ? $results['items'] : array();

			return array(
				'items'    => array_slice( $items, 0, $limit ),
				'has_more' => count( $items ) > $limit || ! empty( $results['has_more'] ),
				'partial'  => ! empty( $results['partial'] ),
				'void'     => ! empty( $results['void'] ),
			);
		}

		/**
		 * Drop the per-request memo.
		 *
		 * The memo is request-scoped by nature, so the front end never needs
		 * this. It exists for the two callers that outlive a request: a
		 * long-running WP-CLI process, which would otherwise accumulate one
		 * entry per distinct criteria shape, and tests that change the
		 * underlying data between assertions while keeping the criteria
		 * identical.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function flush_memo() {
			self::$memo           = array();
			self::$injection_memo = array();
		}

		/* ---------------------------------------------------------------------
		 * TEC List-view injection
		 * ------------------------------------------------------------------ */

		/**
		 * Build the repository-args fragment that drives TEC's own List view.
		 *
		 * This is the integration's spine. Rather than re-running the whole engine
		 * query and forcing every match through `post__in` (which would fight
		 * TEC's `+1` has-next-page trick and offset override), it mirrors the REST/engine semantics as NATIVE repository
		 * args that TEC ANDs together:
		 *
		 *  - the date facet becomes `date_overlaps` for a bounded range, or an
		 *    open-ended `ends_after` (the same logic as `apply_dates()`; day
		 *    boundaries still come from `Date_Presets`, never a bare
		 *    `tribe_beginning_of_day( null )`);
		 *  - ONLY the keyword collapses to a `post__in` id set — the bounded,
		 *    password-excluded set from `keyword_ids()` (hard rules 1, 3, 6),
		 *    re-capped at `TEC_INJECTION_MAX`.
		 *
		 * TEC then ANDs `post__in` with the native date keys, exactly as the
		 * engine intersects the keyword set with the date restriction — so a List
		 * view driven by our bar returns the same set the REST endpoint would for
		 * the same criteria.
		 *
		 * The `[0]` sentinel: a keyword that matches nothing yields
		 * `post__in => [0]`, never `[]` — WP_Query IGNORES an empty `post__in`,
		 * which would silently turn "no matches" into "everything" (hard rule 2
		 * at the repository-args layer). The consuming filter intersects this
		 * fragment's `post__in` with any pre-existing one and re-applies the same
		 * sentinel.
		 *
		 * Memoized per request on the criteria hash. Returns an empty array when
		 * TEC is unavailable or nothing is filtered, so an unfiltered List view
		 * is handed back untouched.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $criteria Criteria (normalized or raw).
		 * @return array<string, mixed> Allowlisted repository-args fragment.
		 */
		public static function for_tec_injection( array $criteria ) {
			if ( ! self::tec_ready() ) {
				return array();
			}

			if ( class_exists( __NAMESPACE__ . '\Criteria' ) ) {
				$criteria = Criteria::normalize( $criteria );

				// An unfiltered view is never touched: hand TEC's args straight
				// back so the List view behaves as if we were not loaded.
				if ( ! Criteria::is_filtered( $criteria ) ) {
					return array();
				}

				$key = Criteria::hash( $criteria, 'tec_inject' );
			} else {
				$key = substr( hash( 'sha256', wp_json_encode( $criteria ) . 'tec_inject' ), 0, 32 );
			}

			if ( isset( self::$injection_memo[ $key ] ) ) {
				return self::$injection_memo[ $key ];
			}

			$engine   = new self( $criteria );
			$fragment = $engine->build_injection_fragment();

			self::$injection_memo[ $key ] = $fragment;

			return $fragment;
		}

		/**
		 * Assemble one injection fragment from this engine's criteria.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		private function build_injection_fragment() {
			// Every filtered injection excludes password-protected events, so the
			// injected List view never leaks protected content the way our REST/SSR
			// surfaces never do. `has_password => false` is a native
			// TEC repository modifier -> WP_Query `AND post_password = ''`, so it
			// applies to facet-only queries too (which carry no post__in). This is
			// the ONLY fragment key that is always present when we inject, so the
			// fragment is never empty for a filtered view.
			$fragment = array( 'has_password' => false );

			/*
			 * NOTE: a facet map ran here, translating `categories`/`tags`/
			 * `venues`/`organizers` selections into TEC's native
			 * `event_category`/`tag`/`venue`/`organizer` repository args (the
			 * mirror of `apply_facets()`). Those facets are not part of this
			 * plugin, so there is nothing to translate — the only facets a bar can
			 * carry are the keyword and the date, and both are handled below.
			 */

			// Date facet -> date_overlaps / ends_after (mirrors apply_dates()).
			foreach ( $this->injection_date() as $arg => $value ) {
				$fragment[ $arg ] = $value;
			}

			/*
			 * The keyword is the one constraint with NO native TEC repository arg
			 * (TEC's own `s` would re-run the search against its full column set),
			 * so it restricts by event id. TEC ANDs `post__in` with the native date
			 * keys above, mirroring how the engine's accumulator intersects the
			 * same producers. An empty set collapses to the [0] sentinel, never []
			 * — WP_Query IGNORES an empty `post__in`, which would turn "no matches"
			 * into "everything".
			 */
			$keyword = isset( $this->criteria['q'] ) ? (string) $this->criteria['q'] : '';

			if ( '' !== $keyword ) {
				$ids = $this->keyword_ids( $keyword );
				$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

				if ( count( $ids ) > self::TEC_INJECTION_MAX ) {
					$ids = array_slice( $ids, 0, self::TEC_INJECTION_MAX );

					// A silent cap is a lie about completeness; record it.
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded diagnostic.
						sprintf(
							'ECSA: TEC List-view keyword id set exceeded %d ids; capped.',
							self::TEC_INJECTION_MAX
						)
					);
				}

				$fragment['post__in'] = $ids ? $ids : array( 0 );
			}

			return $fragment;
		}

		/**
		 * The date facet expressed as native repository args.
		 *
		 * A bounded range is `date_overlaps` (start, end, site timezone) — never
		 * `starts_between`/`on_date`, hard rule 4. An open-ended future range is
		 * `ends_after`. An open-ended PAST range (end only) has no allowlisted
		 * native key, so it rides no date arg — `post__in`/facets still constrain
		 * the query, and the injected bar never emits that shape anyway (it
		 * offers presets, all of which are bounded, plus `any`).
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		private function injection_date() {
			$preset = isset( $this->criteria['date_preset'] ) ? $this->criteria['date_preset'] : 'any';

			if ( 'any' === $preset || ! class_exists( __NAMESPACE__ . '\Date_Presets' ) ) {
				return array();
			}

			$range = Date_Presets::range( $preset, $this->criteria );

			if ( ! is_array( $range ) || 2 > count( $range ) ) {
				return array();
			}

			$range = array_values( $range );
			$start = isset( $range[0] ) ? (string) $range[0] : '';
			$end   = isset( $range[1] ) ? (string) $range[1] : '';

			if ( '' !== $start && '' !== $end ) {
				return array( 'date_overlaps' => array( $start, $end, self::injection_timezone() ) );
			}

			if ( '' !== $start ) {
				return array( 'ends_after' => $start );
			}

			return array();
		}

		/**
		 * The site timezone name for the `date_overlaps` third argument.
		 *
		 * `Date_Presets` returns site-local `Y-m-d H:i:s` strings, so the range
		 * must be interpreted in the site timezone — never a bare
		 * offset-less parse that WordPress would read as UTC.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		private static function injection_timezone() {
			if ( function_exists( 'wp_timezone' ) ) {
				$tz = wp_timezone();

				if ( $tz instanceof \DateTimeZone ) {
					return $tz->getName();
				}
			}

			return 'UTC';
		}

		/* ---------------------------------------------------------------------
		 * Resolution
		 * ------------------------------------------------------------------ */

		/**
		 * Run every ID producer once, populating the accumulator.
		 *
		 * Idempotent: `get_results()`, `get_ids()` and `get_total()` may each
		 * call it, and the Month view will call all three per render.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function resolve() {
			if ( $this->resolved ) {
				return;
			}

			$this->resolved = true;

			$keyword = isset( $this->criteria['q'] ) ? (string) $this->criteria['q'] : '';

			if ( '' !== $keyword ) {
				$this->restrict_to_ids( $this->keyword_ids( $keyword ) );
			}

			/*
			 * NOTE: a Tier-1 LOCATION producer folded in here after the keyword,
			 * through `restrict_to_ids()` like every other producer. It resolved a
			 * {city,state,country} map to published venue IDs (`meta_equals` on
			 * `_VenueCity`/`_VenueStateProvince`/`_VenueCountry`, bounded), then to
			 * the events held at those venues, and voided the query when the place
			 * matched nothing.
			 *
			 * That whole path — `apply_location()`, `location_event_ids()`,
			 * `location_venue_ids()` and the venue cap they shared — is not part of
			 * this plugin. There is no `location` criteria key for one to read, and
			 * the accumulator now has exactly one producer.
			 */
		}

		/**
		 * THE ID accumulator (hard rule 2).
		 *
		 * `Repository::in()` overwrites rather than intersects, so every
		 * producer of an ID restriction folds into this single set and exactly
		 * one `->in()` is ever issued.
		 *
		 * @since 2.0.0
		 * @param int[] $ids Candidate IDs.
		 * @return void
		 */
		private function restrict_to_ids( array $ids ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

			if ( ! $ids ) {
				$this->void = true;

				return;
			}

			$this->post_in = ( null === $this->post_in )
				? $ids
				: array_values( array_intersect( $this->post_in, $ids ) );

			if ( ! $this->post_in ) {
				$this->void = true;
			}
		}

		/**
		 * Build the keyword ID set: events whose own title matches.
		 *
		 * Hard rule 1 in full. This is an independent, bounded candidate query;
		 * the final repository then restricts by ID only and never sees `s` at
		 * all, so a keyword restriction is stated exactly once.
		 *
		 * THE COLUMN SCOPING IS LOAD-BEARING, not an optimisation. TEC's
		 * `->search()` matches title + excerpt + content by default, so an
		 * unscoped pass would silently make a keyword match any event that merely
		 * MENTIONS the word. `text_search_columns()` resolves the admin-only
		 * `search_fields` scope to a WP `search_columns` list, and with `title` the
		 * only scope this plugin has, that list is always `array('post_title')`.
		 * `Criteria` guarantees the scope is never empty, so a bar is always
		 * searchable.
		 *
		 * NOTE: two further passes ran here — `tribe_venues()` and
		 * `tribe_organizers()` searched by name, then resolved to the events
		 * linked to them, and merged into a three-way union. That two-pass name
		 * search is not part of this plugin, and neither is the truncation
		 * bookkeeping it needed (a pass that returned exactly its cap had to
		 * degrade to title-only and flag the payload `partial`). The `partial`
		 * flag itself STAYS: the soft cap below can still slice a very broad
		 * keyword, and that is still a completeness claim we must not make
		 * silently.
		 *
		 * Memoized on the keyword AND the scope: two scopes that share a keyword
		 * are different sets, so the memo key must separate them or the first
		 * scope's result would be served to the second within one request. The
		 * Month view's three filter passes still resolve any one scope once.
		 *
		 * @since 2.0.0
		 * @param string $keyword Sanitized keyword.
		 * @return int[] Event IDs. Empty means "nothing can match".
		 */
		private function keyword_ids( $keyword ) {
			$fields  = $this->search_fields();
			$columns = $this->text_search_columns( $fields );

			$key = $this->memo_key( array( 'q' => $keyword, 'sf' => $fields ), 'kw' );

			if ( isset( self::$memo[ $key ] ) ) {
				$this->partial = $this->partial || ! empty( self::$memo[ $key ]['partial'] );

				return self::$memo[ $key ]['ids'];
			}

			$partial = false;

			// Events whose own title matches. Skipped entirely when the scope
			// resolves to no column at all; otherwise the resolved column set
			// narrows WP_Query's `s` to just those columns.
			$ids = ( null === $columns )
				? array()
				: $this->fetch_ids( $this->searching_repo( $this->public_events_repo(), $keyword, self::CANDIDATE_CAP, $columns ) );

			$ids = array_values( array_unique( $ids ) );

			if ( count( $ids ) > self::UNION_SOFT_CAP ) {
				$ids     = array_slice( $ids, 0, self::UNION_SOFT_CAP );
				$partial = true;
			}

			self::$memo[ $key ] = array(
				'ids'     => $ids,
				'partial' => $partial,
			);

			$this->partial = $this->partial || $partial;

			return $ids;
		}

		/* ---------------------------------------------------------------------
		 * Query building
		 * ------------------------------------------------------------------ */

		/**
		 * A `tribe_events()` repository carrying the public visibility contract.
		 *
		 * Every event query in this plugin — results, the keyword candidate pass
		 * and `/suggest` — starts here, so the password and status exclusions
		 * cannot be forgotten on one path.
		 *
		 * @since 2.0.0
		 * @return mixed Repository instance, or null when TEC is unavailable.
		 */
		private function public_events_repo() {
			if ( ! self::tec_ready() ) {
				return null;
			}

			$repo = tribe_events();

			if ( ! is_object( $repo ) ) {
				return null;
			}

			$repo->where( 'post_status', 'publish' );
			$repo->where( 'hidden_from_upcoming', false );
			self::apply_password_exclusion( $repo );

			return $repo;
		}

		/*
		 * NOTE: `public_posts_repo()` lived here — a `tribe_venues()` /
		 * `tribe_organizers()` factory carrying the same publish + non-password
		 * visibility contract as the events repository. Its two callers were the
		 * two-pass name search and the Tier-1 location venue lookup, neither of
		 * which is part of this plugin. Nothing in this tree opens a venue or
		 * organizer repository any more, which is why the class docblock's rule 6
		 * no longer has to name their `-1` default.
		 */

		/**
		 * Apply a keyword search and a page cap to a candidate repository.
		 *
		 * `->search()` is legitimate HERE and only here: these are the candidate
		 * passes whose IDs feed the union. It must never reach the final
		 * repository built by `build_repository()` (hard rule 1).
		 *
		 * When `$columns` is a non-empty array the WP `search_columns` argument
		 * (WP 6.2+) is forwarded through the ORM to scope `s` to just those columns
		 * — `array('post_title')` matches titles only, `array('post_content')`
		 * bodies only. The base repository treats an unknown two-argument key as a
		 * raw query var (verified on TEC 6.17 / WP 7.0.2), so this reaches WP_Query
		 * intact. A null/empty `$columns` leaves `->search()` matching TEC's full
		 * title+excerpt+content default, exactly as before.
		 *
		 * @since 2.0.0
		 * @param mixed         $repo     Repository instance, or null.
		 * @param string        $keyword  Sanitized keyword.
		 * @param int           $per_page Hard cap on rows (hard rule 6).
		 * @param string[]|null $columns  WP search_columns to scope to, or null.
		 * @return mixed Repository instance, or null.
		 */
		private function searching_repo( $repo, $keyword, $per_page, $columns = null ) {
			if ( ! is_object( $repo ) ) {
				return null;
			}

			$repo->search( $keyword )->per_page( (int) $per_page );

			if ( is_array( $columns ) && ! empty( $columns ) && method_exists( $repo, 'by' ) ) {
				$repo->by( 'search_columns', $columns );
			}

			return $repo;
		}

		/**
		 * The admin-only keyword-search scope for this query.
		 *
		 * Always a non-empty subset of `Criteria::SEARCH_FIELDS`: `Criteria`
		 * normalizes an empty scope back to the whole allowlist, but a caller that
		 * bypassed normalization (the degraded no-Criteria constructor path) is
		 * defended here too so `text_search_columns()` can index freely.
		 *
		 * The literal is the second copy of `Criteria::SEARCH_FIELDS` — it exists
		 * because `Criteria` may not have loaded — and the two must stay equal.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		private function search_fields() {
			$all = array( 'title' );

			$fields = isset( $this->criteria['search_fields'] ) ? $this->criteria['search_fields'] : $all;

			if ( ! is_array( $fields ) ) {
				return $all;
			}

			$fields = array_values( array_intersect( $all, $fields ) );

			return $fields ? $fields : $all;
		}

		/**
		 * Resolve the keyword pass's WP `search_columns` from the scope.
		 *
		 * Two outcomes:
		 *   - title   -> array('post_title')   scoped to titles
		 *   - neither -> null                  skip the keyword pass entirely
		 *
		 * IT MUST NEVER RETURN AN EMPTY ARRAY. `searching_repo()` reads an empty
		 * `$columns` as "leave `->search()` unscoped", and TEC's unscoped search
		 * matches title + excerpt + CONTENT — which is precisely the wider scope
		 * this plugin does not have. The null return is the skip signal; a
		 * `post_title` list is the only column set this method may produce.
		 *
		 * @since 2.0.0
		 * @param string[] $fields Resolved scope.
		 * @return string[]|null Column list, or null to skip the pass.
		 */
		private function text_search_columns( array $fields ) {
			return in_array( 'title', $fields, true ) ? array( 'post_title' ) : null;
		}

		/*
		 * NOTE: `linked_repo()` lived here — an events repository restricted to
		 * the events held at a set of venue or organizer IDs, bounded by
		 * `CANDIDATE_CAP`. Its callers were the two-pass name search and the
		 * location lookup, so it went with them.
		 */

		/**
		 * Build the final events repository: dates, time window, the single
		 * `->in()` and the total-order sort.
		 *
		 * @since 2.0.0
		 * @return mixed Repository instance, or null when it cannot be built.
		 */
		private function build_repository() {
			$repo = $this->public_events_repo();

			if ( null === $repo ) {
				return null;
			}

			$this->apply_time( $repo );
			$this->apply_dates( $repo );

			/*
			 * THE one and only `->in()` call in this plugin. Guarded against the
			 * empty array, which would produce `post__in => []` — a restriction
			 * WP_Query ignores entirely, turning "no matches" into "everything".
			 */
			if ( null !== $this->post_in && ! empty( $this->post_in ) ) {
				$repo->in( $this->post_in );
			}

			$repo->order_by( self::order_map( $this->criteria['sort'] ) );

			return $repo;
		}

		/*
		 * NOTE: `apply_facets()` lived here, translating `categories`/`tags`/
		 * `venues`/`organizers` selections into TEC's native `event_category`/
		 * `tag`/`venue`/`organizer` repository args. Those facets are not part of
		 * this plugin, so the final repository is now constrained by the date
		 * range, the time window and the one `->in()` alone. Its mirror in
		 * `build_injection_fragment()` went at the same time — the two had to
		 * agree, and the only way to guarantee that is for neither to exist.
		 */

		/**
		 * Apply the upcoming/past/all window.
		 *
		 * `ends_after` rather than `starts_after`: an event that began this
		 * morning and runs until tonight is still upcoming to a visitor, and
		 * `starts_after` would hide it.
		 *
		 * @since 2.0.0
		 * @param mixed $repo Repository instance.
		 * @return void
		 */
		private function apply_time( $repo ) {
			$time = isset( $this->criteria['time'] ) ? $this->criteria['time'] : 'upcoming';

			if ( 'upcoming' === $time ) {
				$repo->where( 'ends_after', 'now' );
			} elseif ( 'past' === $time ) {
				$repo->where( 'ends_before', 'now' );
			}
		}

		/**
		 * Apply the date range (hard rule 4).
		 *
		 * `date_overlaps` for every bounded range, including single days.
		 *
		 * `starts_between` is never used: it drops any multi-day event that
		 * began before the range and is still running inside it — the exact
		 * class of bug this rewrite retires. `on_date` is ALSO not used, even
		 * though it reads like the natural single-day helper, because TEC
		 * implements it as `starts_between` internally
		 * (`src/Tribe/Repositories/Event.php::filter_by_on_date()`, verified on
		 * 6.17) and so carries the same defect.
		 *
		 * Boundaries come from `Date_Presets`, which derives them through
		 * `Tec::beginning_of_day()`/`end_of_day()` — never a null argument, which
		 * would resolve "today" in UTC.
		 *
		 * @since 2.0.0
		 * @param mixed $repo Repository instance.
		 * @return void
		 */
		private function apply_dates( $repo ) {
			$preset = isset( $this->criteria['date_preset'] ) ? $this->criteria['date_preset'] : 'any';

			if ( 'any' === $preset || ! class_exists( __NAMESPACE__ . '\Date_Presets' ) ) {
				return;
			}

			$range = Date_Presets::range( $preset, $this->criteria );

			if ( ! is_array( $range ) || 2 > count( $range ) ) {
				return;
			}

			$range = array_values( $range );
			$start = isset( $range[0] ) ? (string) $range[0] : '';
			$end   = isset( $range[1] ) ? (string) $range[1] : '';

			if ( '' !== $start && '' !== $end ) {
				$repo->where( 'date_overlaps', $start, $end );
			} elseif ( '' !== $start ) {
				$repo->where( 'ends_after', $start );
			} elseif ( '' !== $end ) {
				$repo->where( 'starts_before', $end );
			}
		}

		/**
		 * The TOTAL order for a sort key (hard rule 7).
		 *
		 * `event_date` alone is not a total order: events sharing a start time
		 * come back in whatever order MySQL read them, so a row can appear on
		 * both page 1 and page 2 of a Load More sequence while another is never
		 * shown. TEC hits the same problem in `By_Day_View` and solves it the
		 * same way. `ID` is the final, guaranteed-unique tiebreaker.
		 *
		 * @since 2.0.0
		 * @param string $sort Sort key from the Criteria allowlist.
		 * @return array<string, string> `orderby => order` map.
		 */
		private static function order_map( $sort ) {
			if ( 'date_desc' === $sort ) {
				return array(
					'event_date'     => 'DESC',
					'event_duration' => 'DESC',
					'ID'             => 'DESC',
				);
			}

			if ( 'title' === $sort ) {
				return array(
					'title' => 'ASC',
					'ID'    => 'ASC',
				);
			}

			return array(
				'event_date'     => 'ASC',
				'event_duration' => 'ASC',
				'ID'             => 'ASC',
			);
		}

		/* ---------------------------------------------------------------------
		 * Execution
		 * ------------------------------------------------------------------ */

		/**
		 * Resolve and page the results.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		private function run() {
			if ( ! self::tec_ready() ) {
				return self::empty_result();
			}

			$this->resolve();

			if ( $this->void ) {
				$result          = self::empty_result();
				$result['void']  = true;
				$result['total'] = 0;

				return $result;
			}

			$per_page = isset( $this->criteria['per_page'] ) ? (int) $this->criteria['per_page'] : 0;
			$page     = isset( $this->criteria['page'] ) ? max( 1, (int) $this->criteria['page'] ) : 1;

			// per_page 0 is the count-only mode behind the drawer's "Show N events".
			if ( 0 === $per_page ) {
				$total = $this->get_total();

				return array(
					'items'        => array(),
					'has_more'     => false,
					'total'        => $total,
					'total_capped' => $this->is_total_capped( $total ),
					'partial'      => $this->partial,
					'void'         => false,
				);
			}

			$limit = $this->should_compute_total() ? self::ROW_WINDOW_CAP : $this->window_for_page();
			$rows  = $this->ordered_rows( $limit );

			if ( ! $rows ) {
				return array(
					'items'        => array(),
					'has_more'     => false,
					'total'        => $this->rows_capped ? null : 0,
					'total_capped' => false,
					'partial'      => $this->partial,
					'void'         => false,
				);
			}

			$ordered = $this->collapse( $rows );
			$offset  = ( $page - 1 ) * $per_page;
			$slice   = array_slice( $ordered, $offset, $per_page + 1 );
			$has_more = count( $slice ) > $per_page || ( $this->rows_capped && count( $ordered ) <= $offset + $per_page );
			$items    = $this->hydrate( array_slice( $slice, 0, $per_page ) );

			/*
			 * The total follows count_mode, which is NOT the same as the item
			 * count: items are collapsed per `recurrence`, while the total
			 * counts events or occurrences per `count_mode`. Delegating to
			 * get_total() keeps one source of truth, so this payload's total
			 * always agrees with the per_page=0 count-only endpoint. ordered_rows()
			 * is memoized, so the re-fetch inside get_total() is free.
			 */
			$total = ( $this->rows_capped && ! $this->should_compute_total() ) ? null : $this->get_total();

			return array(
				'items'        => $items,
				'has_more'     => (bool) $has_more,
				'total'        => $total,
				'total_capped' => $this->is_total_capped( $total ),
				'partial'      => $this->partial,
				'void'         => false,
			);
		}

		/**
		 * Fetch the bounded, ordered window of raw row IDs.
		 *
		 * One ids-only query with `no_found_rows`. Memoized per request on the
		 * criteria that actually affect it (page size and view do not), so the
		 * Month view's three filter passes cost one query rather than three.
		 *
		 * @since 2.0.0
		 * @param int $limit Maximum rows to fetch.
		 * @return int[] Raw row IDs in query order.
		 */
		private function ordered_rows( $limit ) {
			$limit = max( 1, min( self::ROW_WINDOW_CAP, (int) $limit ) );

			if ( null !== $this->rows && $this->rows_limit >= $limit ) {
				return $this->rows;
			}

			$shape               = $this->criteria;
			$shape['page']       = 1;
			$shape['per_page']   = 0;
			$shape['view']       = '';
			$shape['count_mode'] = '';
			$key                 = $this->memo_key( $shape, 'rows' . $limit );

			if ( isset( self::$memo[ $key ] ) ) {
				$this->rows        = self::$memo[ $key ]['ids'];
				$this->rows_limit  = self::$memo[ $key ]['limit'];
				$this->rows_capped = self::$memo[ $key ]['capped'];

				return $this->rows;
			}

			$repo = $this->build_repository();
			$ids  = ( null === $repo ) ? array() : $this->fetch_ids( $repo->per_page( $limit ) );

			$this->rows        = $ids;
			$this->rows_limit  = $limit;
			$this->rows_capped = count( $ids ) >= $limit;

			self::$memo[ $key ] = array(
				'ids'    => $ids,
				'limit'  => $limit,
				'capped' => $this->rows_capped,
			);

			return $ids;
		}

		/**
		 * Collapse occurrence rows per the `recurrence` policy (hard rule 5).
		 *
		 * `next_only` keeps the first row seen for each `event_id`. Because the
		 * window is date-ordered, "first" is the earliest occurrence inside the
		 * range — which is precisely the definition. Collapsing on the ordered
		 * window rather than page-by-page is what makes Load More stable: a
		 * page is a slice of an already-deduplicated list, so no event can
		 * appear on two pages and none can be skipped between them.
		 *
		 * @since 2.0.0
		 * @param int[] $rows Raw row IDs in query order.
		 * @return int[] Row IDs to page over.
		 */
		private function collapse( array $rows ) {
			if ( 'next_only' !== $this->criteria['recurrence'] ) {
				return $rows;
			}

			/*
			 * "next_only" means the EARLIEST occurrence in range — a date
			 * property, independent of how the results are being displayed.
			 *
			 * Keeping simply the first row per event_id would inherit the
			 * display order, so `sort=date_desc` would represent a weekly
			 * series by its FURTHEST-FUTURE occurrence and the card would show
			 * a date months away instead of the next one. Choose by date, then
			 * restore the requested display order.
			 *
			 * Under free TEC every occurrence row of an event is the same
			 * wp_posts.ID with the same start (the CT1 select-field filter is
			 * unhooked), so this reduces to the cheap dedupe.
			 * It only diverges with Events Calendar Pro, which is exactly the
			 * case that would otherwise ship broken and untested.
			 */
			$chosen = array();

			foreach ( $rows as $index => $row_id ) {
				$event_id = self::normalize_event_id( $row_id );

				if ( $event_id <= 0 ) {
					continue;
				}

				$start = self::row_start_stamp( $row_id );

				if ( ! isset( $chosen[ $event_id ] ) || $start < $chosen[ $event_id ]['start'] ) {
					$chosen[ $event_id ] = array(
						'row'   => $row_id,
						'start' => $start,
						'order' => $index,
					);
				}
			}

			// Restore the display order the window arrived in.
			uasort(
				$chosen,
				function ( $a, $b ) {
					return $a['order'] - $b['order'];
				}
			);

			$out = array();

			foreach ( $chosen as $entry ) {
				$out[] = $entry['row'];
			}

			return $out;
		}

		/**
		 * Comparable start stamp for a result row.
		 *
		 * Used only to choose which occurrence represents an event under
		 * `next_only`. Returns PHP_INT_MAX when the start cannot be resolved so
		 * an undated row never wins the "earliest" comparison.
		 *
		 * @since 2.0.0
		 * @param int $row_id Row (occurrence or post) ID.
		 * @return int Unix timestamp, or PHP_INT_MAX.
		 */
		private static function row_start_stamp( $row_id ) {
			$event_id = self::normalize_event_id( $row_id );

			if ( $event_id <= 0 ) {
				return PHP_INT_MAX;
			}

			$utc = get_post_meta( $event_id, '_EventStartDateUTC', true );

			if ( ! $utc ) {
				$utc = get_post_meta( $event_id, '_EventStartDate', true );
			}

			if ( ! $utc ) {
				return PHP_INT_MAX;
			}

			try {
				$dt = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
			} catch ( \Exception $e ) {
				return PHP_INT_MAX;
			}

			return $dt->getTimestamp();
		}

		/**
		 * Run a repository to IDs, never unbounded, never fatal.
		 *
		 * The `->per_page()` call is the CALLER's responsibility and every call
		 * site above makes it — hard rule 6. This method only guarantees that a
		 * repository failure degrades to an empty set instead of a fatal.
		 *
		 * @since 2.0.0
		 * @param mixed $repo Repository instance (or null).
		 * @return int[]
		 */
		private function fetch_ids( $repo ) {
			if ( ! is_object( $repo ) || ! method_exists( $repo, 'all' ) ) {
				return array();
			}

			$guard = self::$password_guard;

			try {
				$repo->fields( 'ids' );

				if ( method_exists( $repo, 'set_found_rows' ) ) {
					$repo->set_found_rows( false );
				}

				$ids = $repo->all();
			} catch ( \Throwable $e ) {
				$ids = array();
			}

			if ( $guard ) {
				// Never leave a global filter attached past its query.
				self::remove_password_guard();
			}

			if ( ! is_array( $ids ) ) {
				return array();
			}

			return array_values( array_filter( array_map( 'absint', $ids ) ) );
		}

		/* ---------------------------------------------------------------------
		 * Hydration
		 * ------------------------------------------------------------------ */

		/**
		 * Turn row IDs into payload items.
		 *
		 * @since 2.0.0
		 * @param int[] $row_ids Row IDs for this page.
		 * @return array[] Item arrays.
		 */
		private function hydrate( array $row_ids ) {
			$items = array();

			if ( ! $row_ids ) {
				return $items;
			}

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			$original = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

			/*
			 * Prime the post + meta caches for the whole page in ONE query, so the
			 * per-row get_post()/get_post_meta() below are cache hits instead of an
			 * N+1 (up to PER_PAGE_MAX rows). TEC's fields('ids') repository returns
			 * bare IDs and — unlike WP_Query — primes nothing. Bounded by the cap.
			 * Under free TEC row_id IS the event post ID; a provisional occurrence
			 * ID under ECP simply misses here (harmless) and its event is fetched
			 * per-row as before.
			 */
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( array_map( 'absint', $row_ids ), false, true );
			}

			foreach ( $row_ids as $row_id ) {
				$item = $this->item_from_row( $row_id );

				if ( null !== $item ) {
					$items[] = $item;
				}
			}

			wp_reset_postdata();

			/*
			 * Restore UNCONDITIONALLY, including back to null.
			 *
			 * wp_reset_postdata() is a no-op unless $GLOBALS['wp_query']->post
			 * is set, and on a REST request the main query has no post — so
			 * without this, $GLOBALS['post'] stays pointing at the last event we
			 * hydrated for the rest of the request. Any later filter (ours or
			 * another plugin's rest_post_dispatch callback) calling get_the_ID()
			 * would then silently pick up an event ID.
			 */
			$GLOBALS['post'] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the caller's post, including null.

			return $items;
		}

		/**
		 * Build one payload item.
		 *
		 * `event_id` is ALWAYS the real `wp_posts.ID`, obtained through TEC's
		 * own normalization filter. Under Events Calendar Pro the repository
		 * hands back provisional occurrence IDs which are NOT post IDs: an
		 * un-normalized ID leaving here and coming back through `->in()` would
		 * match nothing — dev-clean, production-broken.
		 *
		 * `Tec::visible()` is the final password/status drop (hard rule 3), and
		 * the excerpt comes from `get_the_excerpt()` inside `setup_postdata()`
		 * so core's own protection filters run — never raw `post_content`.
		 *
		 * @since 2.0.0
		 * @param int $row_id Raw row ID from the repository.
		 * @return array<string, mixed>|null Null when the row must not be shown.
		 */
		private function item_from_row( $row_id ) {
			$row_id = absint( $row_id );

			if ( ! $row_id ) {
				return null;
			}

			$event_id = self::normalize_event_id( $row_id );

			if ( ! $event_id ) {
				return null;
			}

			$event = get_post( $event_id );

			if ( ! Tec::visible( $event ) ) {
				return null;
			}

			// The occurrence row carries this instance's dates; fall back to the
			// event post itself on free TEC, where the two are the same thing.
			$occurrence = ( $row_id !== $event_id ) ? get_post( $row_id ) : $event;

			if ( ! $occurrence instanceof \WP_Post ) {
				$occurrence = $event;
			}

			$GLOBALS['post'] = $event; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset in hydrate().
			setup_postdata( $event );

			$title   = (string) get_the_title( $event );
			$excerpt = (string) get_the_excerpt( $event );

			$url = get_permalink( $occurrence );

			if ( ! is_string( $url ) || '' === $url ) {
				$url = (string) get_permalink( $event );
			}

			$thumbnail = get_the_post_thumbnail_url( $event_id, 'medium_large' );
			$venue     = self::venue_name( $occurrence );

			return array(
				'event_id'      => (int) $event_id,
				'occurrence_id' => ( $row_id !== $event_id ) ? (int) $row_id : null,
				'title'         => $title,
				'url'           => (string) $url,
				'start'         => self::event_date( $occurrence, 'start' ),
				'end'           => self::event_date( $occurrence, 'end' ),
				'all_day'       => self::is_all_day( $occurrence ),
				'venue'         => ( '' === $venue ) ? null : $venue,
				'thumbnail'     => ( is_string( $thumbnail ) && '' !== $thumbnail ) ? $thumbnail : null,
				'excerpt'       => $excerpt,
				// Cost travels WITH the item so a live REST update can paint the
				// same card the server rendered. Looking it up only at SSR time
				// would make the price vanish the moment a visitor touched a
				// filter and the JS rebuilt the cards from this payload.
				'cost'          => self::event_cost( (int) $event_id ),
			);
		}

		/**
		 * The display cost of an event, or null when it has none.
		 *
		 * Hard-gated on TEC's template tag: a site whose TEC is
		 * deactivated mid-request must degrade to "no cost", never fatal. The
		 * value is a display string ("Free", "$15", "£10 – £25"), so it is
		 * flattened to plain text here and escaped at render.
		 *
		 * @since 2.1.0
		 * @param int $event_id Event post id.
		 * @return string|null
		 */
		private static function event_cost( $event_id ) {
			if ( $event_id <= 0 || ! function_exists( 'tribe_get_cost' ) ) {
				return null;
			}

			$cost = tribe_get_cost( $event_id, true );
			$cost = is_scalar( $cost ) ? trim( wp_strip_all_tags( (string) $cost ) ) : '';

			return ( '' === $cost ) ? null : $cost;
		}

		/**
		 * Site-local `Y-m-d H:i:s` boundary for an event row.
		 *
		 * Goes through TEC's template tags so occurrence dates and timezone
		 * conversion are TEC's business, not ours; falls back to the meta keys
		 * only when the tags are unavailable.
		 *
		 * @since 2.0.0
		 * @param \WP_Post $post  Event or occurrence post.
		 * @param string   $which `start` or `end`.
		 * @return string
		 */
		private static function event_date( $post, $which ) {
			$format = 'Y-m-d H:i:s';

			if ( 'end' === $which ) {
				if ( function_exists( 'tribe_get_end_date' ) ) {
					return (string) tribe_get_end_date( $post, true, $format );
				}

				return (string) get_post_meta( $post->ID, '_EventEndDate', true );
			}

			if ( function_exists( 'tribe_get_start_date' ) ) {
				return (string) tribe_get_start_date( $post, true, $format );
			}

			return (string) get_post_meta( $post->ID, '_EventStartDate', true );
		}

		/**
		 * Whether an event row is all-day.
		 *
		 * @since 2.0.0
		 * @param \WP_Post $post Event or occurrence post.
		 * @return bool
		 */
		private static function is_all_day( $post ) {
			if ( function_exists( 'tribe_event_is_all_day' ) ) {
				return (bool) tribe_event_is_all_day( $post );
			}

			return 'yes' === get_post_meta( $post->ID, '_EventAllDay', true );
		}

		/**
		 * The venue NAME for an event row — not its address.
		 *
		 * @since 2.0.0
		 * @param \WP_Post $post Event or occurrence post.
		 * @return string Empty when there is no venue.
		 */
		private static function venue_name( $post ) {
			if ( ! function_exists( 'tribe_get_venue' ) ) {
				return '';
			}

			$name = tribe_get_venue( $post );

			return is_string( $name ) ? trim( $name ) : '';
		}

		/**
		 * Resolve any row ID to the real `wp_posts.ID` (hard rule 5).
		 *
		 * @since 2.0.0
		 * @param int $row_id Raw row ID.
		 * @return int
		 */
		private static function normalize_event_id( $row_id ) {
			$row_id = absint( $row_id );

			if ( ! $row_id ) {
				return 0;
			}

			$normalized = absint( apply_filters( 'tec_events_custom_tables_v1_normalize_occurrence_id', $row_id ) );

			return $normalized ? $normalized : $row_id;
		}

		/* ---------------------------------------------------------------------
		 * Password exclusion (hard rule 3)
		 * ------------------------------------------------------------------ */

		/**
		 * Exclude password-protected posts from a repository.
		 *
		 * Preferred form is the repository's own `post_password` argument, which
		 * WP_Query turns into a prepared `AND post_password = %s`. When a
		 * repository cannot take it, a narrowly-scoped `posts_where` filter is
		 * attached instead and removed the moment the query has run — a global
		 * filter is never left hanging.
		 *
		 * @since 2.0.0
		 * @param mixed $repo Repository instance.
		 * @return bool True when applied through the repository.
		 */
		private static function apply_password_exclusion( $repo ) {
			if ( is_object( $repo ) && method_exists( $repo, 'where' ) ) {
				$repo->where( 'post_password', '' );

				return true;
			}

			self::add_password_guard();

			return false;
		}

		/**
		 * Attach the scoped `posts_where` password guard.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function add_password_guard() {
			if ( self::$password_guard ) {
				return;
			}

			self::$password_guard = true;

			add_filter( 'posts_where', array( __CLASS__, 'filter_posts_where_password' ), 999, 1 );
		}

		/**
		 * Detach the scoped `posts_where` password guard.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function remove_password_guard() {
			if ( ! self::$password_guard ) {
				return;
			}

			self::$password_guard = false;

			remove_filter( 'posts_where', array( __CLASS__, 'filter_posts_where_password' ), 999 );
		}

		/**
		 * Append the password exclusion to a WHERE clause.
		 *
		 * Public only because it is a filter callback; it is attached for the
		 * duration of a single query and never left in place.
		 *
		 * @since 2.0.0
		 * @param string $where Existing WHERE clause.
		 * @return string
		 */
		public static function filter_posts_where_password( $where ) {
			global $wpdb;

			return $where . " AND {$wpdb->posts}.post_password = ''";
		}

		/* ---------------------------------------------------------------------
		 * Helpers
		 * ------------------------------------------------------------------ */

		/**
		 * Whether TEC is loadable AND available.
		 *
		 * The `class_exists()` half matters as much as `Tec::available()`: the
		 * autoloader silently skips a missing file, so a partially deployed tree
		 * would otherwise turn a missing feature into a fatal at the call site.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private static function tec_ready() {
			return class_exists( 'CoolPlugins\EventsSearch\Tec\Tec' ) && Tec::available();
		}

		/**
		 * The empty result payload — the single shape every failure path returns.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		private static function empty_result() {
			return array(
				'items'        => array(),
				'has_more'     => false,
				'total'        => null,
				'total_capped' => false,
				'partial'      => false,
				'void'         => false,
			);
		}

		/**
		 * Whether an exact total is worth computing for these criteria.
		 *
		 * Hard rule 8: a keyword query is the expensive case, so it gets
		 * `has_more` and no total unless the caller explicitly asked for a count
		 * (`per_page = 0`, the drawer's "Show N events" button).
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private function should_compute_total() {
			$keyword  = isset( $this->criteria['q'] ) ? (string) $this->criteria['q'] : '';
			$per_page = isset( $this->criteria['per_page'] ) ? (int) $this->criteria['per_page'] : 0;

			return '' === $keyword || 0 === $per_page;
		}

		/**
		 * How many rows this page needs, before any cap.
		 *
		 * Collapsing occurrences shrinks the list, so `next_only` over-fetches.
		 *
		 * @since 2.0.0
		 * @return int
		 */
		private function window_for_page() {
			$per_page = max( 1, (int) $this->criteria['per_page'] );
			$page     = max( 1, (int) $this->criteria['page'] );
			$needed   = ( $page * $per_page ) + 1;

			if ( 'next_only' === $this->criteria['recurrence'] ) {
				$needed *= self::NEXT_ONLY_OVERFETCH;
			}

			return min( self::ROW_WINDOW_CAP, $needed );
		}

		/**
		 * Whether the display should read "300+" rather than the figure.
		 *
		 * @since 2.0.0
		 * @param int|null $total Computed total.
		 * @return bool
		 */
		private function is_total_capped( $total ) {
			if ( null === $total ) {
				return false;
			}

			return $this->rows_capped || $total > self::TOTAL_DISPLAY_CAP;
		}

		/**
		 * Fixed-length memo key for a criteria shape.
		 *
		 * Fixed length is a security property inherited from `Criteria::hash()`:
		 * `q` is free text, and an unbounded key is a write primitive against
		 * `wp_options` the moment these keys reach a transient.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $shape  Criteria-shaped array.
		 * @param string               $suffix Namespace for the key.
		 * @return string
		 */
		private function memo_key( array $shape, $suffix ) {
			if ( class_exists( __NAMESPACE__ . '\Criteria' ) ) {
				return Criteria::hash( $shape, $suffix );
			}

			return substr( hash( 'sha256', wp_json_encode( $shape ) . $suffix ), 0, 32 );
		}
	}
}
