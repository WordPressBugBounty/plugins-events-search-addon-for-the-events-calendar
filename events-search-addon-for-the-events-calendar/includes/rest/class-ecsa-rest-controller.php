<?php
/**
 * The `ecsa/v1` REST surface.
 *
 * Our own namespace, deliberately: TEC's `tribe/events/v1` can be switched off
 * from its settings, and the public path must never depend on a toggle we do
 * not own. It must equally never use `admin-ajax.php` — that is the v1.3.6 path
 * this rewrite retires.
 *
 * These routes are `permission_callback => '__return_true'`. That is only
 * defensible in combination with the full plan §4 complexity budget, which is
 * enforced here in a fixed order on every route:
 *
 *   1. `Rate_Limit::check()`   — 429 before any work is done.
 *   2. `Tec::available()`      — 503, never an empty 200.
 *   3. argument schema         — type/enum/min/max, structural rejection.
 *   4. `Criteria::normalize()` — total function, always a complete array.
 *   5. `Criteria::validate()`  — the semantic budget; its WP_Error is returned
 *                                verbatim so the 400 keeps its machine-readable
 *                                code (`ecsa_pagination_too_deep`, …).
 *
 * The split between (3) and (5) is intentional. The schema is a cheap
 * structural guard that rejects malformed payloads before they reach any of our
 * logic; `Criteria` remains the single authority on what a valid query means,
 * so the shortcode, the block and REST cannot drift apart.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Rest;

use CoolPlugins\EventsSearch\Query\Criteria;
use CoolPlugins\EventsSearch\Tec\Tec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Rest_Controller' ) ) {

	/**
	 * Registers and serves the public search routes.
	 *
	 * @since 2.0.0
	 */
	final class Rest_Controller {

		/**
		 * REST namespace.
		 */
		const REST_NAMESPACE = 'ecsa/v1';

		/**
		 * Fully-qualified name of the query engine.
		 */
		const ENGINE = 'CoolPlugins\EventsSearch\Query\Query_Engine';

		/**
		 * Fully-qualified name of the date-preset resolver.
		 */
		const DATE_PRESETS = 'CoolPlugins\EventsSearch\Query\Date_Presets';

		/**
		 * Suggestions returned to the client when the caller names no limit
		 * (distinct events).
		 */
		const SUGGEST_LIMIT = 8;

		/**
		/**
		 * How many upcoming events may ride along as a fallback.
		 *
		 * Deliberately small and separate from `limit`: this is garnish for an
		 * empty or thin panel, and it must never be able to push real matches out
		 * of a dropdown capped at 8 rows.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		const SUGGEST_FALLBACK = 4;

		/**
		 * HARD ceiling on suggestions, whatever an instance asks for.
		 *
		 * `SUGGEST_LIMIT` used to be the only number here, which made it
		 * impossible to honour v1.3.6's `show-events` — that attribute governed
		 * the suggestion count, since 1.3.6 had no results grid at all. The limit
		 * is now per-instance, and this is the cap that keeps it safe: the value
		 * reaches the server as a public query parameter, so the same hard cap
		 * applies as for `per_page`. The instance-side clamps in
		 * `Legacy_Map` and `Instance` are convenience; THIS one is the guarantee.
		 */
		const SUGGEST_LIMIT_MAX = 20;

		/**
		 * Floor on suggestions. Zero rows is a broken dropdown, not a setting.
		 */
		const SUGGEST_LIMIT_MIN = 1;

		/*
		 * NOTE: `MAX_FACET_GROUPS` and `DEFAULT_FACET_GROUPS` lived here, bounding
		 * how many counted facet groups one request could ask for and naming the
		 * cheap ones (`category`, `tag`) that `/facets` computed by default. There
		 * is no counted facet in this plugin — the two facets it has are a keyword
		 * box and a date filter, neither of which has an option list to count — so
		 * the `/facets` route, its schema and both constants are gone.
		 */

		/**
		 * Above this, the UI renders "300+" instead of an exact figure.
		 *
		 * Exact totals are optional by design — `found()` is the
		 * most expensive query in the request, so `total` is frequently `null`
		 * and the client must render from `has_more`.
		 */
		const TOTAL_DISPLAY_CAP = 300;

		/**
		 * Structural ceiling on a raw keyword, before `Criteria` truncates it.
		 *
		 * Exists purely to reject megabyte payloads at the door; the semantic
		 * 2–60 rule lives in `Criteria::validate()`.
		 */
		const Q_RAW_MAX = 200;

		/**
		 * Headers the browser may read cross-origin.
		 */
		const EXPOSED_HEADERS = 'X-ECSA-Total, X-ECSA-TotalPages';

		/**
		 * Register hooks. Called from `Plugin`; nothing runs at include time.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 10 );
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_response_headers' ), 10, 3 );
		}

		/**
		 * The REST root for this namespace.
		 *
		 * Handed to the front end from PHP rather than assembling
		 * `/wp-json/ecsa/v1/` in JavaScript: on a site with plain permalinks
		 * `rest_url()` returns the `?rest_route=` form, and a hardcoded path
		 * 404s there. Also honours `rest_url_prefix` filters.
		 *
		 * @since 2.0.0
		 * @return string Absolute URL with a trailing slash.
		 */
		public static function rest_root() {
			return rest_url( self::REST_NAMESPACE . '/' );
		}

		/**
		 * Register the two public routes.
		 *
		 * Registration is skipped entirely when the classes the handlers depend
		 * on are not loadable — a route that can only 500 is worse than one that
		 * 404s, and the autoloader treats a missing file as a silent no-op.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function register_routes() {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) || ! class_exists( 'CoolPlugins\EventsSearch\Tec\Tec' ) ) {
				return;
			}

			register_rest_route(
				self::REST_NAMESPACE,
				'/events',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_events' ),
						'permission_callback' => '__return_true',
						'args'                => self::events_args(),
					),
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				'/suggest',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_suggest' ),
						'permission_callback' => '__return_true',
						'args'                => self::suggest_args(),
					),
				)
			);

			/*
			 * NOTE: `/facets` and `/places` were registered here.
			 *
			 * `/facets` served option lists and counts for the taxonomy and
			 * linked-post facets; `/places` served grouped city / region / country
			 * suggestions for the in-bar location field. Neither facet nor that
			 * field is part of this plugin, so both routes — and their handlers,
			 * schemas and validators — are gone rather than registered to return an
			 * empty payload. A route that can only answer "nothing" is a route that
			 * invites a client to keep asking.
			 */
		}

		/* ---------------------------------------------------------------------
		 * Handlers
		 * ------------------------------------------------------------------- */

		/**
		 * `GET /events` — the paginated result set.
		 *
		 * With `per_page=0` the response carries `total` and NO items: that is
		 * the count-only mode behind the mobile drawer's "Show N events" button,
		 * which needs a number while the user is still choosing filters.
		 *
		 * @since 2.0.0
		 * @param \WP_REST_Request $request Incoming request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function get_events( $request ) {
			$criteria = self::gate( $request, '/events' );

			if ( is_wp_error( $criteria ) ) {
				return $criteria;
			}

			$engine = self::ENGINE;

			if ( ! class_exists( $engine ) ) {
				return self::unavailable();
			}

			$per_page = (int) $criteria['per_page'];

			/*
			 * Cache the payload at the REST boundary, not inside Query_Engine.
			 *
			 * The engine stays pure — the harnesses and the integration tests
			 * exercise it uncached and deterministic — while the public path
			 * still gets the transient tier the plan (§1.2) asks for. Only the
			 * cacheable shape is memoized: Cache::is_cacheable() returns false
			 * for a keyword longer than 20 chars, so attacker-controlled free
			 * text never becomes an unbounded set of wp_options rows. The count
			 * -only (per_page=0) branch is included — the mobile drawer polls it
			 * on every chip toggle, so it is the single most repeated request.
			 */
			$cache     = 'CoolPlugins\EventsSearch\Query\Cache';
			$cacheable = class_exists( $cache ) && $cache::is_cacheable( $criteria );
			$produce   = function () use ( $engine, $criteria, $per_page ) {
				$instance = new $engine( $criteria );
				$result   = $instance->get_results();
				$result   = is_array( $result ) ? $result : array();

				if ( 0 === $per_page && ! isset( $result['total'] ) ) {
					$result['total'] = $instance->get_total();
				}

				return $result;
			};

			if ( $cacheable ) {
				$key    = Criteria::hash( $criteria, 'events' );
				$ttl    = $cache::ttl_for( $criteria );
				$result = $cache::remember( $key, $ttl, $produce );
			} else {
				$result = $produce();
			}

			$result = is_array( $result ) ? $result : array();

			$total = ( isset( $result['total'] ) && null !== $result['total'] ) ? max( 0, (int) $result['total'] ) : null;
			$pages = ( null !== $total && $per_page > 0 ) ? (int) ceil( $total / $per_page ) : null;

			if ( 0 === $per_page ) {
				// Count-only: deliberately no `items` key at all, so a client
				// that forgets to branch fails loudly instead of rendering an
				// empty list as "no events".
				return self::respond( array( 'total' => $total ), $total, $pages );
			}

			$body = array(
				'items'        => isset( $result['items'] ) && is_array( $result['items'] ) ? array_values( $result['items'] ) : array(),
				'total'        => $total,
				'total_capped' => isset( $result['total_capped'] )
					? (bool) $result['total_capped']
					: ( null !== $total && $total > self::TOTAL_DISPLAY_CAP ),
				'has_more'     => ! empty( $result['has_more'] ),
				'page'         => (int) $criteria['page'],
				'per_page'     => $per_page,
				// True when the keyword candidate pass was sliced at its soft cap:
				// the UI must say so rather than present a truncated set as
				// complete (plan §4).
				'partial'      => ! empty( $result['partial'] ),
			);

			return self::respond( $body, $total, $pages );
		}

		/**
		 * `GET /suggest` — the type-ahead payload.
		 *
		 * No total: this is a navigator, not a result set, and paying for an
		 * exact count per keystroke is precisely the cost this API rules
		 * out. `recurrence` is forced to `next_only` so a weekly event cannot
		 * occupy all eight slots.
		 *
		 * @since 2.0.0
		 * @param \WP_REST_Request $request Incoming request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function get_suggest( $request ) {
			$criteria = self::gate( $request, '/suggest' );

			if ( is_wp_error( $criteria ) ) {
				return $criteria;
			}

			/*
			 * Per-instance row count, clamped HERE regardless of what the schema
			 * already allowed — `limit` arrives from the browser, and a public
			 * route caps its own work rather than trusting a declared range to
			 * have been applied.
			 */
			$limit = self::suggest_limit( $request );

			$criteria['recurrence'] = 'next_only';
			$criteria['page']       = 1;
			$criteria['per_page']   = $limit;

			$engine = self::ENGINE;

			if ( ! class_exists( $engine ) || ! method_exists( $engine, 'suggest' ) ) {
				return self::unavailable();
			}

			/*
			 * PASS THE LIMIT. `Query_Engine::suggest()` defaults to 8 and clamps
			 * to 20 itself, so omitting it silently capped every instance at 8:
			 * a bar configured for more asked for more, was told 8, and the slice
			 * below then had nothing left to trim. The cap the visitor sees was
			 * the default rather than the setting.
			 */
			$result = $engine::suggest( $criteria, $limit );
			$result = is_array( $result ) ? $result : array();

			$items = isset( $result['items'] ) && is_array( $result['items'] ) ? array_values( $result['items'] ) : array();

			/*
			 * The engine already dedupes and slices. Repeating it here is not
			 * redundancy for its own sake: this is the public boundary, and the
			 * "at most `limit`, distinct by event_id" promise is the one the
			 * dropdown markup is built against — a regression upstream should
			 * degrade the list, never break the component.
			 */
			$deduped  = self::dedupe_by_event_id( $items );
			$has_more = ! empty( $result['has_more'] ) || count( $deduped ) > $limit;

			$matches = array_slice( $deduped, 0, $limit );

			/*
			 * A FEW UPCOMING EVENTS, so the dropdown is never blank.
			 *
			 * An empty box, a query still being typed, or a thin result set all
			 * leave a panel with nothing in it at the moment the visitor is most
			 * engaged. These fill it, clearly separated so nothing here is ever
			 * read as a match.
			 *
			 * ITS OWN ARRAY, not merged into `items`: the count line has to be
			 * able to say how many events MATCHED, and `has_more` plus the
			 * distinct-by-event_id promise describe the matches only. Merging
			 * would quietly make both of those untrue.
			 *
			 * KEYWORD-FREE, which is the point. That is precisely the payload
			 * this plugin is allowed to cache (attacker-influenced keywords never
			 * mint cache rows), so the fallback is the cheap query rather than a
			 * second expensive one on every keystroke. The client decides whether
			 * to show it; the server just makes it available.
			 *
			 * Capped hard and separately from `limit`: this is garnish, and it
			 * must not be able to push the real matches out of an 8-row panel.
			 */
			/*
			 * ONLY WHEN THERE IS A KEYWORD TO FALL BACK FROM.
			 *
			 * With an empty `q` the fallback query IS the match query, so every
			 * row it returns is deduped away against the matches and the array
			 * comes back empty regardless. Running it was a second full engine
			 * query per request to produce nothing — and the zero-query panel
			 * makes that the request a visitor triggers just by clicking the
			 * field. The response is byte-identical either way.
			 */
			$upcoming = array();
			if ( isset( $criteria['q'] ) && '' !== (string) $criteria['q'] ) {
				$fallback = $criteria;
				$fallback['q']            = '';
				$fallback['time']         = 'upcoming';
				$fallback['sort']         = 'date_asc';
				$fallback['recurrence']   = 'next_only';
				$fallback['page']         = 1;
				$fallback['per_page']     = self::SUGGEST_FALLBACK;

				$fb = $engine::suggest( $fallback, self::SUGGEST_FALLBACK );
				$fb = is_array( $fb ) && isset( $fb['items'] ) && is_array( $fb['items'] ) ? array_values( $fb['items'] ) : array();

				if ( $fb ) {
					/*
					 * Never repeat a row the visitor is already looking at. Without
					 * this, a thin result set shows the same event twice under two
					 * different headings, which reads as a bug in the search.
					 */
					$seen = array();
					foreach ( $matches as $m ) {
						if ( isset( $m['event_id'] ) ) {
							$seen[ (string) $m['event_id'] ] = true;
						}
					}

					foreach ( self::dedupe_by_event_id( $fb ) as $row ) {
						$id = isset( $row['event_id'] ) ? (string) $row['event_id'] : '';

						if ( '' !== $id && isset( $seen[ $id ] ) ) {
							continue;
						}

						$upcoming[] = $row;
					}

					$upcoming = array_slice( $upcoming, 0, self::SUGGEST_FALLBACK );
				}
			}

			return self::respond(
				array(
					'items'    => $matches,
					'has_more' => $has_more,
					'upcoming' => $upcoming,
				),
				null,
				null
			);
		}

		/**
		 * Resolve and clamp the `/suggest` row count for one request.
		 *
		 * @since 2.3.0
		 * @param \WP_REST_Request $request Incoming request.
		 * @return int Between SUGGEST_LIMIT_MIN and SUGGEST_LIMIT_MAX.
		 */
		private static function suggest_limit( $request ) {
			$raw = is_object( $request ) && method_exists( $request, 'get_param' )
				? $request->get_param( 'limit' )
				: null;

			if ( null === $raw || '' === $raw || ! is_numeric( $raw ) ) {
				return self::SUGGEST_LIMIT;
			}

			return (int) max( self::SUGGEST_LIMIT_MIN, min( self::SUGGEST_LIMIT_MAX, (int) $raw ) );
		}

		/*
		 * NOTE: `get_facets()`, `get_places()`, `places_args()` and
		 * `validate_place_query()` lived here, serving the two retired routes.
		 * They are deleted rather than left unregistered — an unreachable public
		 * handler is still code a reviewer has to read and a future refactor can
		 * re-expose.
		 */

		/* ---------------------------------------------------------------------
		 * Pipeline
		 * ------------------------------------------------------------------- */

		/**
		 * Run the complexity budget and produce normalized criteria.
		 *
		 * @since 2.0.0
		 * @param \WP_REST_Request $request Incoming request.
		 * @param string           $route   Route identifier for the throttle.
		 * @return array<string, mixed>|\WP_Error Normalized criteria, or the
		 *                                        first failure verbatim.
		 */
		private static function gate( $request, $route ) {
			if ( class_exists( __NAMESPACE__ . '\Rate_Limit' ) ) {
				$allowed = Rate_Limit::check( $route );

				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}
			}

			if ( ! Tec::available() ) {
				return self::tec_unavailable();
			}

			$raw = self::raw_from_request( $request );

			/*
			 * Validate the keyword's RAW length before normalization.
			 *
			 * `Criteria::clean_keyword()` truncates to Q_MAX (60), so by the
			 * time `Criteria::validate()` runs, its `$len > Q_MAX` branch is
			 * dead and `ecsa_query_too_long` can never be returned. A visitor
			 * pasting an 80-character event name would silently get results for
			 * the first 60 characters with no signal that their query was
			 * rewritten. The plan's contract is a 400, so answer 400.
			 */
			if ( isset( $raw['q'] ) && is_string( $raw['q'] ) ) {
				$raw_q   = trim( wp_unslash( $raw['q'] ) );
				$raw_len = function_exists( 'mb_strlen' ) ? mb_strlen( $raw_q, 'UTF-8' ) : strlen( $raw_q );

				if ( $raw_len > Criteria::Q_MAX ) {
					return new \WP_Error(
						'ecsa_query_too_long',
						__( 'Search terms must be 60 characters or fewer.', 'events-search-addon-for-the-events-calendar' ),
						array( 'status' => 400 )
					);
				}
			}

			$criteria = Criteria::normalize( $raw );

			$valid = Criteria::validate( $criteria );

			if ( is_wp_error( $valid ) ) {
				// Verbatim: the client branches on `ecsa_pagination_too_deep`
				// and friends to choose a message, so re-wrapping would erase
				// the only machine-readable part of the 400.
				return $valid;
			}

			return $criteria;
		}

		/**
		 * Collect declared parameters into a raw criteria array.
		 *
		 * Only keys DECLARED on the matched route are read.
		 * `WP_REST_Request::get_param()` searches every parameter source
		 * regardless of the route's schema, so an undeclared `?sort=…` would
		 * otherwise arrive completely unsanitized. `Criteria::normalize()` would
		 * still allowlist it, but relying on that would make the schema
		 * decorative.
		 *
		 * @since 2.0.0
		 * @param \WP_REST_Request $request Incoming request.
		 * @return array<string, mixed> Raw (schema-sanitized) input.
		 */
		private static function raw_from_request( $request ) {
			$attributes = $request->get_attributes();
			$declared   = ( isset( $attributes['args'] ) && is_array( $attributes['args'] ) ) ? $attributes['args'] : array();

			$keys = array(
				'q',
				'date_preset',
				'date_from',
				'date_to',
				'time',
				'sort',
				'recurrence',
				'count_mode',
				'page',
				'per_page',
				'view',
				'search_fields',
			);

			$raw = array();

			foreach ( $keys as $key ) {
				if ( ! isset( $declared[ $key ] ) ) {
					continue;
				}

				$value = $request->get_param( $key );

				if ( null === $value ) {
					continue;
				}

				$raw[ $key ] = $value;
			}

			return $raw;
		}

		/*
		 * NOTE: `build_facets()` lived here, resolving the requested facet groups
		 * and handing them to `Query\Facets::for_criteria()` for option lists and
		 * counts. `/events` no longer carries a `facets` key in its response and
		 * `facets[]` is no longer a declared parameter, because the two facets
		 * this plugin has — the keyword box and the date filter — have no option
		 * list to count against.
		 */

		/**
		 * Reduce suggestion rows to one per event.
		 *
		 * Keys on `event_id` — the real `wp_posts.ID` — and never on
		 * `occurrence_id`, which is the same post ID repeated N times in free
		 * TEC and an ECP-only provisional ID otherwise.
		 *
		 * @since 2.0.0
		 * @param array<int, mixed> $items Suggestion rows.
		 * @return array<int, mixed> Deduped rows, original order preserved.
		 */
		private static function dedupe_by_event_id( array $items ) {
			$seen = array();
			$out  = array();

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['event_id'] ) ) {
					continue;
				}

				$id = (int) $item['event_id'];

				if ( $id <= 0 || isset( $seen[ $id ] ) ) {
					continue;
				}

				$seen[ $id ] = true;
				$out[]       = $item;
			}

			return $out;
		}

		/* ---------------------------------------------------------------------
		 * Responses
		 * ------------------------------------------------------------------- */

		/**
		 * Wrap a body in a 200 response, mirroring totals into headers.
		 *
		 * The totals are sent BOTH ways on purpose. A response header is
		 * invisible to cross-origin JavaScript unless it is named in
		 * `Access-Control-Expose-Headers` (added on `rest_post_dispatch`), and
		 * plenty of security plugins strip unknown `X-` headers outright — so
		 * the body field is the contract and the header is the convenience for
		 * proxies and cache rules.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $body  Response body.
		 * @param int|null             $total Exact total, or null when unknown.
		 * @param int|null             $pages Total pages, or null.
		 * @return \WP_REST_Response
		 */
		private static function respond( array $body, $total, $pages ) {
			$response = new \WP_REST_Response( $body, 200 );

			// Omitted rather than sent empty when unknown: an absent header is
			// unambiguous, `X-ECSA-Total: 0` would not be.
			if ( null !== $total ) {
				$response->header( 'X-ECSA-Total', (string) (int) $total );
			}

			if ( null !== $pages ) {
				$response->header( 'X-ECSA-TotalPages', (string) (int) $pages );
			}

			return $response;
		}

		/**
		 * The 503 returned when The Events Calendar is missing or too old.
		 *
		 * Explicitly NOT an empty 200. Per the plan's states contract, "no
		 * events" and "the thing that finds events is not installed" render
		 * identically to a visitor, and conflating them is the single biggest
		 * support driver this rewrite is trying to remove.
		 *
		 * @since 2.0.0
		 * @return \WP_Error
		 */
		private static function tec_unavailable() {
			return new \WP_Error(
				'ecsa_tec_unavailable',
				__( 'The Events Calendar is not active, so events cannot be searched.', 'events-search-addon-for-the-events-calendar' ),
				array( 'status' => 503 )
			);
		}

		/**
		 * The 503 returned when the query engine itself is not loadable.
		 *
		 * Reachable only from a partially deployed tree — the autoloader skips a
		 * missing file rather than fatalling, so this is what that degradation
		 * looks like from outside.
		 *
		 * @since 2.0.0
		 * @return \WP_Error
		 */
		private static function unavailable() {
			return new \WP_Error(
				'ecsa_engine_unavailable',
				__( 'Event search is temporarily unavailable.', 'events-search-addon-for-the-events-calendar' ),
				array( 'status' => 503 )
			);
		}

		/**
		 * Apply CORS, cache and throttle headers to our own responses.
		 *
		 * WordPress sends `Cache-Control: no-cache, must-revalidate, max-age=0`
		 * on EVERY REST response by default (`rest_send_nocache_headers`), so a
		 * "CDN-friendly" endpoint simply does not exist unless it is overridden
		 * here.
		 *
		 * The override is narrow — our namespace, unauthenticated, GET, 200 —
		 * because each condition guards a real leak: a logged-in response can
		 * contain draft-adjacent data and must never enter a shared cache, a
		 * non-200 must never be cached at all, and a write must never be.
		 *
		 * @since 2.0.0
		 * @param mixed            $response Dispatch result.
		 * @param mixed            $server   REST server instance.
		 * @param \WP_REST_Request $request  Request being served.
		 * @return mixed The response, unchanged in identity.
		 */
		public static function filter_response_headers( $response, $server, $request ) {
			unset( $server );

			if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
				return $response;
			}

			$route = (string) $request->get_route();

			if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE . '/' ) ) {
				return $response;
			}

			$response->header( 'Access-Control-Expose-Headers', self::EXPOSED_HEADERS );

			$status = (int) $response->get_status();

			if ( 429 === $status ) {
				$response->header( 'Retry-After', (string) self::retry_after( $response ) );

				return $response;
			}

			if ( 200 !== $status || is_user_logged_in() || 'GET' !== strtoupper( (string) $request->get_method() ) ) {
				return $response;
			}

			$max_age = self::max_age( $request );

			/*
			 * s-maxage is the CDN lease and defaults to 300 (plan §1.3).
			 *
			 * Clamp it to max_age ONLY for a relative preset, where the response
			 * genuinely expires at local midnight and a shared cache must not
			 * outlive that boundary. Applying the clamp unconditionally — as an
			 * earlier revision did — cut every ordinary response's CDN lease
			 * from 300s to 60s, losing 5x the shared-cache benefit for a safety
			 * property that only relative presets need.
			 */
			$preset      = $request->get_param( 'date_preset' );
			$is_relative = is_string( $preset ) && Criteria::is_relative_preset( $preset );
			$shared      = $is_relative ? min( 300, $max_age ) : 300;

			$response->header( 'Cache-Control', sprintf( 'public, max-age=%d, s-maxage=%d', $max_age, $shared ) );

			/*
			 * Appended, not replaced. The response is public but was chosen on
			 * the basis of the request being logged-out, so a shared cache must
			 * not hand it to a logged-in user whose response would have been
			 * `no-cache`.
			 */
			$response->header( 'Vary', 'Cookie', false );

			return $response;
		}

		/**
		 * Seconds this response may be cached.
		 *
		 * A relative preset ("today", "this week") is only true until the site's
		 * next local midnight. Serving it for a flat minute is fine; letting a
		 * CDN hold it for five would render yesterday's "today" to real
		 * visitors.
		 *
		 * @since 2.0.0
		 * @param \WP_REST_Request $request Request being served.
		 * @return int Seconds, at least 60.
		 */
		private static function max_age( $request ) {
			$preset = $request->get_param( 'date_preset' );

			if ( ! is_string( $preset ) || ! Criteria::is_relative_preset( $preset ) ) {
				return 60;
			}

			$presets = self::DATE_PRESETS;

			if ( class_exists( $presets ) && method_exists( $presets, 'seconds_to_midnight' ) ) {
				$seconds = (int) $presets::seconds_to_midnight();

				if ( $seconds > 0 ) {
					/*
					 * Floored at 1, NOT at a comfortable minimum.
					 *
					 * `max( 60, … )` would hand a CDN a 60-second lease on a
					 * "today" response minted 20 seconds before midnight, so it
					 * would serve yesterday's results into tomorrow. When eleven
					 * seconds remain before the boundary, eleven seconds is the
					 * correct answer — this matches Cache::ttl_for(), which
					 * already floors at 1 for exactly this reason.
					 *
					 * seconds_to_midnight() already applies the 3600 cap.
					 */
					return max( 1, $seconds );
				}
			}

			// No resolver: fall back to the shortest sane TTL rather than
			// guessing a boundary. Being slightly less cacheable is recoverable;
			// serving a stale "today" is not.
			return 60;
		}

		/**
		 * Read `retry_after` off a throttled response.
		 *
		 * @since 2.0.0
		 * @param \WP_HTTP_Response $response Response being sent.
		 * @return int Seconds, at least 1.
		 */
		private static function retry_after( $response ) {
			$data = $response->get_data();

			if ( is_array( $data ) && isset( $data['data']['retry_after'] ) ) {
				return max( 1, (int) $data['data']['retry_after'] );
			}

			// Reachable only if something other than our own limiter produced a
			// 429 on this namespace, so it must not assume Rate_Limit is loaded.
			return class_exists( __NAMESPACE__ . '\Rate_Limit' ) ? Rate_Limit::WINDOW : 60;
		}

		/* ---------------------------------------------------------------------
		 * Argument schemas
		 * ------------------------------------------------------------------- */

		/**
		 * Parameters shared by every route.
		 *
		 * Every enum is read from a `Criteria` constant rather than retyped: a
		 * literal copy here would be a second source of truth that silently
		 * stops matching the first.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, mixed>>
		 */
		private static function filter_args() {
			return array(
				'q'           => array(
					'description'       => __( 'Search keyword.', 'events-search-addon-for-the-events-calendar' ),
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => array( __CLASS__, 'validate_keyword' ),
				),
				/*
				 * NOTE: `categories`, `tags`, `venues` and `organizers` were
				 * declared here as bounded ID lists, and `location` as a
				 * {city,state,country} object. None of those facets is part of this
				 * plugin, so none of them is a parameter — an undeclared key is
				 * dropped by `raw_from_request()` before `Criteria` ever sees it.
				 */
				'date_preset' => array(
					'description'       => __( 'Date range preset.', 'events-search-addon-for-the-events-calendar' ),
					'type'              => 'string',
					'default'           => 'any',
					'enum'              => Criteria::DATE_PRESETS,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'date_from'   => self::date_arg( __( 'Range start, YYYY-MM-DD. Used only with the custom preset.', 'events-search-addon-for-the-events-calendar' ) ),
				'date_to'     => self::date_arg( __( 'Range end, YYYY-MM-DD. Used only with the custom preset.', 'events-search-addon-for-the-events-calendar' ) ),
				'time'        => array(
					'description'       => __( 'Time window.', 'events-search-addon-for-the-events-calendar' ),
					'type'              => 'string',
					'default'           => 'upcoming',
					'enum'              => Criteria::TIMES,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => 'rest_validate_request_arg',
				),
				/*
				 * The admin-only keyword-search scope. The JS refetches on every
				 * interaction, so REST must apply the SAME scope the SSR paint did
				 * or the first post-interaction render would silently widen/narrow
				 * the search. Declared as an array; `rest_is_array()` also accepts
				 * the comma-separated form `buildRestQuery()` emits, so
				 * `?search_fields=title,venue` and `?search_fields[]=title&…` behave
				 * identically. An absent/empty value means the whole allowlist
				 * (Criteria maps the empty set back to every allowed field).
				 */
				'search_fields' => array(
					'description'       => __( 'Which fields a keyword search matches.', 'events-search-addon-for-the-events-calendar' ),
					'type'              => 'array',
					'default'           => array(),
					'maxItems'          => count( Criteria::SEARCH_FIELDS ),
					'items'             => array(
						'type' => 'string',
						'enum' => Criteria::SEARCH_FIELDS,
					),
					'sanitize_callback' => array( __CLASS__, 'sanitize_key_list' ),
					'validate_callback' => 'rest_validate_request_arg',
				),
			);
		}

		/**
		 * `/events` parameters.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, mixed>>
		 */
		private static function events_args() {
			return array_merge(
				self::filter_args(),
				array(
					'sort'       => array(
						'description'       => __( 'Result order.', 'events-search-addon-for-the-events-calendar' ),
						'type'              => 'string',
						'default'           => 'date_asc',
						'enum'              => Criteria::SORTS,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'recurrence' => array(
						'description'       => __( 'Whether recurring events collapse to their next occurrence.', 'events-search-addon-for-the-events-calendar' ),
						'type'              => 'string',
						'default'           => 'next_only',
						'enum'              => Criteria::RECURRENCES,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'count_mode' => array(
						'description'       => __( 'Whether totals count events or occurrences.', 'events-search-addon-for-the-events-calendar' ),
						'type'              => 'string',
						'default'           => 'events',
						'enum'              => Criteria::COUNT_MODES,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'view'       => array(
						'description'       => __( 'Result layout.', 'events-search-addon-for-the-events-calendar' ),
						'type'              => 'string',
						'default'           => 'grid',
						'enum'              => Criteria::VIEWS,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'page'       => array(
						'description'       => __( 'Page number.', 'events-search-addon-for-the-events-calendar' ),
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'per_page'   => array(
						'description'       => __( 'Results per page. Zero returns only the total.', 'events-search-addon-for-the-events-calendar' ),
						'type'              => 'integer',
						'default'           => Criteria::PER_PAGE_DEFAULT,
						'minimum'           => 0,
						'maximum'           => Criteria::PER_PAGE_MAX,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				)
			);
		}

		/**
		 * `/suggest` parameters.
		 *
		 * No pagination, sort or view: the dropdown is a navigator over a fixed
		 * eight rows, and offering it a page cursor would invite exactly the
		 * deep-offset traversal the budget forbids.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, mixed>>
		 */
		private static function suggest_args() {
			$args = self::filter_args();

			$args['q']['required']    = true;
			$args['q']['description'] = __( 'Search keyword. Required.', 'events-search-addon-for-the-events-calendar' );

			/*
			 * How many rows to return. Per-instance because v1.3.6's
			 * `show-events` governed exactly this, and a page may carry an old bar
			 * asking for 5 beside a modern one asking for 8. Declared with an
			 * explicit range so an out-of-band value is rejected at the schema
			 * before the handler ever runs; `suggest_limit()` clamps again anyway.
			 */
			$args['limit'] = array(
				'description'       => __( 'How many suggestions to return.', 'events-search-addon-for-the-events-calendar' ),
				'type'              => 'integer',
				'default'           => self::SUGGEST_LIMIT,
				'minimum'           => self::SUGGEST_LIMIT_MIN,
				'maximum'           => self::SUGGEST_LIMIT_MAX,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			);

			return $args;
		}

		/*
		 * NOTE: `facets_args()`, `facets_arg()` and `id_list_arg()` lived here —
		 * the `/facets` route's schema, the `facets[]` group selector and the
		 * bounded ID-list shape the four retired facet parameters shared. All
		 * three went with the routes and parameters they described.
		 */

		/**
		 * Schema for a `Y-m-d` date parameter.
		 *
		 * @since 2.0.0
		 * @param string $description Human-readable description.
		 * @return array<string, mixed>
		 */
		private static function date_arg( $description ) {
			return array(
				'description'       => $description,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( __CLASS__, 'validate_date' ),
			);
		}

		/* ---------------------------------------------------------------------
		 * Callbacks
		 * ------------------------------------------------------------------- */

		/**
		 * Structural check on the raw keyword.
		 *
		 * Only rejects what `Criteria` cannot see: a payload so large that
		 * sanitizing it is itself the attack. The 2–60 character rule and the
		 * all-punctuation rejection stay in `Criteria::validate()`, which owns
		 * their error codes.
		 *
		 * @since 2.0.0
		 * @param mixed             $value   Submitted value.
		 * @param \WP_REST_Request  $request Request (unused).
		 * @param string            $param   Parameter name (unused).
		 * @return true|\WP_Error
		 */
		public static function validate_keyword( $value, $request = null, $param = '' ) {
			unset( $request, $param );

			if ( ! is_string( $value ) ) {
				return new \WP_Error(
					'ecsa_invalid_query',
					__( 'The search keyword must be text.', 'events-search-addon-for-the-events-calendar' ),
					array( 'status' => 400 )
				);
			}

			if ( strlen( $value ) > self::Q_RAW_MAX ) {
				return new \WP_Error(
					'ecsa_query_too_long',
					__( 'Search terms must be 60 characters or fewer.', 'events-search-addon-for-the-events-calendar' ),
					array( 'status' => 400 )
				);
			}

			return true;
		}

		/**
		 * Validate a `Y-m-d` date parameter.
		 *
		 * `checkdate()` is the point of this: `2026-02-31` passes a regex and
		 * `strtotime()` happily resolves it to 3 March, silently moving the
		 * range the visitor asked for.
		 *
		 * @since 2.0.0
		 * @param mixed            $value   Submitted value.
		 * @param \WP_REST_Request $request Request (unused).
		 * @param string           $param   Parameter name (unused).
		 * @return true|\WP_Error
		 */
		public static function validate_date( $value, $request = null, $param = '' ) {
			unset( $request, $param );

			if ( null === $value || '' === $value ) {
				return true;
			}

			if ( is_string( $value ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches )
				&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
				return true;
			}

			return new \WP_Error(
				'ecsa_invalid_date',
				__( 'Dates must be a real calendar date in YYYY-MM-DD format.', 'events-search-addon-for-the-events-calendar' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * NOTE: `sanitize_id_list()` lived here, coercing an ID-list parameter to
		 * positive integers. Its only callers were the four retired facet
		 * parameters.
		 */

		/**
		 * Sanitize a list of allowlist keys.
		 *
		 * @since 2.0.0
		 * @param mixed $value Submitted value.
		 * @return string[]
		 */
		public static function sanitize_key_list( $value ) {
			if ( is_string( $value ) ) {
				$value = explode( ',', $value );
			}

			if ( ! is_array( $value ) ) {
				return array();
			}

			return array_values( array_unique( array_map( 'sanitize_key', array_filter( $value, 'is_scalar' ) ) ) );
		}

		/*
		 * NOTE: `sanitize_location()` lived here, narrowing a submitted location
		 * object to the {city,state,country} shape `Criteria` expected. It went
		 * with the `location` parameter.
		 */
	}
}
