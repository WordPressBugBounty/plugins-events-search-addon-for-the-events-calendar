<?php
/**
 * TEC List-view integration — put our filter bar on the native /events/ List
 * view, in one of TWO modes the admin chooses.
 *
 * This is the headline free-gap feature: TEC's front-end Filter Bar is a PAID
 * add-on, and TEC ships a render-empty injection slot
 * (`views/v2/components/filter-bar.php`, included from `list.php`) plus the
 * `tribe_events_views_v2_view_repository_args` filter. Together they let a free
 * addon paint a bar into the List view and rewrite the repository args behind
 * it.
 *
 * WE DO NOT PROBE FOR — OR STAND DOWN FOR — THAT PAID ADD-ON. No
 * hook-registration lookup, no main-file constant, no class
 * name: the admin's `tec_views` setting is the ONLY thing that decides whether
 * we run, so this feature has no dependency on another product's bootstrap
 * internals and can never silently disable itself when one changes.
 *
 * The accepted consequence, recorded rather than buried: on a site running both,
 * `header_swap` appends our bar into the same slot theirs fills (two bars
 * stacked — the param namespaces are disjoint, `ecsa_*` vs `tribe_*`, so neither
 * reads the other's state), and if a visitor drives both at once the LAST writer
 * of a shared repository key wins.
 *
 * THE TWO MODES (`Settings::tec_views_mode()`), and what each one registers.
 * Nothing is "enabled then gated inside a callback": a mode's hooks are the only
 * hooks that exist for it, so an unused surface costs nothing and cannot fire.
 *
 *   off          NOTHING is registered. Not one filter, not one action. The
 *                events page behaves exactly as if this addon were not installed.
 *
 *   header_swap  TEC keeps rendering its own results; we replace the two header
 *                CONTROLS it ships and drive its query from our facets:
 *                  · `tribe_context_locations`                         (10)
 *                  · `tribe_events_views_v2_view_repository_args`       (10, 3)
 *                  · `tribe_events_views_v2_url_query_args`             (15, 3)
 *                  · `tribe_events_views_v2_view_url_query_args`        (10, 3)
 *                  · `tribe_template_include_html:…/components/filter-bar` (10, 4)
 *                  · `tribe_template_include_html:events/v2/list`       (10, 4)
 *                  · `tribe_events_views_v2_view_list_display_events_bar` (10, 2)
 *                  · `tribe_template_done`                              (10, 4)
 *                  · `wp_enqueue_scripts`                               (20)
 *
 * A THIRD MODE — short-circuiting `tribe_events_views_v2_bootstrap_pre_get_view_html`
 * so OUR bar and OUR results render instead of TEC's whole List view — is not
 * part of this plugin. `header_swap` never takes the page over: TEC's own results
 * keep rendering, and we drive them.
 *
 * WHAT `header_swap` REMOVES, AND WHAT IT DELIBERATELY KEEPS. `tribe-events-header`
 * is NOT just the search bar and the view nav: `components/header.php` also renders
 * `components/content-title` (the page `<h1>`), `components/backlink` /
 * `components/breadcrumbs`, and `components/messages` — TEC's empty-state and
 * error region. And `tribe-events-c-top-bar` is INSIDE that header, not a sibling
 * of it. So removing "the header" would delete the page heading, the breadcrumbs
 * and every TEC message: an accessibility and SEO regression nobody asked for.
 * We remove exactly two children — `components/events-bar`
 * and `<view>/top-bar` — and keep the shell.
 *
 * AND IT REMOVES THEM WITH FILTERS, not CSS and not a template override. The
 * filter prevents the markup being GENERATED; `display:none` would still ship it
 * and still let TEC's `events-bar.js` initialise against a hidden form, leaving
 * two search UIs writing the same view state. A template override is worse: it
 * freezes at copy time, and TEC actively restructures this header (its own
 * changelog: "@since 6.15.16 Moved the `content-title` template higher up",
 * "Conditionally order title templates"), so a copy misses exactly those fixes.
 *
 * Nothing here re-implements the bar or the query. The bar is the SAME form
 * `Renderer::bar()` already paints (its `ecsa_*` control names ARE the URL
 * contract), and the query fragment is `Query_Engine::for_tec_injection()`.
 *
 * Every invariant that matters here is enforced by the collaborators, not
 * re-derived: the repository-args ALLOWLIST and the `[0]` sentinel live in
 * `merge_repository_args()`; the bounded, password-excluded keyword union lives
 * in `Query_Engine`; `Renderer` owns escaping. This file adds the timing rules:
 * context locations MUST be registered before the first `Context::get_locations()`
 * (TEC applies that filter once, then `remove_all_filters()`s it), and the whole
 * feature stands down as one unit when the gate fails.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Display;

use CoolPlugins\EventsSearch\Tec\Tec;
use CoolPlugins\EventsSearch\Query\Criteria;
use CoolPlugins\EventsSearch\Query\Url_State;
use CoolPlugins\EventsSearch\Query\Query_Engine;
use CoolPlugins\EventsSearch\Render\Renderer;
use CoolPlugins\EventsSearch\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Tec_Views' ) ) {

	/**
	 * Wires our bar and facets into TEC's native List view.
	 *
	 * @since 2.0.0
	 */
	final class Tec_Views {

		/**
		 * The view slug we integrate with. v1 is List view ONLY (product
		 * decision, plan §Product): month/day carry the same slot but are out of
		 * scope, so every callback gates on this exact slug.
		 */
		const VIEW_SLUG = 'list';

		/*
		 * NOTE: `BOOTSTRAP_PRIORITY` lived here — priority 20 for the `replace`
		 * mode's `…_bootstrap_pre_get_view_html` short-circuit, chosen ABOVE 10
		 * because TEC's own `Hide_End_Time_Provider` registers void ACTIONS on
		 * that FILTER and WordPress feeds each callback's return to the next, so a
		 * short-circuit at 10 or below had its HTML nulled back out. That mode is
		 * not part of this plugin and nothing here registers on a bootstrap filter,
		 * so the hazard — and the constant that documented it — are gone.
		 */

		/**
		 * The `ecsa_*` request params registered as TEC context locations.
		 *
		 * ONLY the params the injected bar can emit AND the injected query can
		 * honour on TEC's native List view: the keyword and the date range.
		 * Deliberately excluded:
		 *  - `ecsa_view`/`ecsa_page`/`ecsa_per_page` are OURS — they must never
		 *    steer TEC's own view or paging;
		 *  - `ecsa_time`/`ecsa_sort` have no injectable representation on the List
		 *    view (TEC owns the upcoming-window and the ordering), so registering
		 *    them would carry a param through pagination that the query silently
		 *    drops — a lie about what is applied.
		 *
		 * NOTE: `ecsa_cat`, `ecsa_tag`, `ecsa_venue`, `ecsa_org` and the Tier-1
		 * location trio (`ecsa_city`/`ecsa_state`/`ecsa_country`) were registered
		 * here too. Those facets are not part of this plugin, so the bar cannot
		 * emit them and the injection fragment has nothing to resolve them to —
		 * registering them would be the same "carries a param the query drops" lie
		 * the exclusions above avoid.
		 *
		 * This list is the whole URL vocabulary this surface accepts.
		 */
		const CONTEXT_VARS = array(
			'ecsa_q',
			'ecsa_date',
			'ecsa_from',
			'ecsa_to',
		);

		/**
		 * The transient that flags a theme having dropped the injection slot, so
		 * an admin notice can point the administrator at the fix.
		 *
		 * BYTE-EXACT CONTRACT: this is the key `Settings_Page::SLOT_MISSING_TRANSIENT`
		 * reads. This front-end class WRITES it; the admin settings page (loaded
		 * only in wp-admin) is the single, screen-scoped READER. The literal is
		 * duplicated (not referenced) because `Settings_Page` is not loaded on the
		 * front-end where this transient is set — so the two MUST be kept in sync.
		 */
		const DROPPED_SLOT_TRANSIENT = 'ecsa_tec_slot_missing';

		/**
		 * Guard so `init()` wires the hooks at most once per request.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * The mode this request booted in — `off` until `init()` resolves it.
		 *
		 * Resolved ONCE, at registration time, and never re-read: a callback that
		 * asked the setting again could disagree with the hooks that are actually
		 * registered (an option filter, another plugin's `update_option` mid-request),
		 * and half of one mode running inside the other is the failure this avoids.
		 *
		 * @var string
		 */
		private static $mode = 'off';

		/**
		 * Per-render flag: did `inject_bar()` fill the slot during THIS List
		 * render? Read (and reset) by `verify_injection()` at the end of the
		 * render to decide whether the theme dropped the slot.
		 *
		 * @var bool
		 */
		private static $did_inject = false;

		/**
		 * Request-scoped guard so the dropped-slot transient is written at most
		 * once even when several List views render on one page.
		 *
		 * @var bool
		 */
		private static $flagged = false;

		/**
		 * Register every hook, once, if the feature gate passes.
		 *
		 * MUST be called on `init` (or `plugins_loaded`) and BEFORE the first
		 * `Context::get_locations()`: TEC applies `tribe_context_locations` a
		 * single time then removes every callback, so a late registration is a
		 * silent no-op. `init` is early enough — the front-end views resolve
		 * their context at render time (`template_redirect`+), long after — and
		 * late enough for the TEC-availability probe to be meaningful (it settles
		 * during `plugins_loaded`).
		 *
		 * When the gate fails — or the mode is `off` — NOTHING is registered: no
		 * context vars, no repository-args filter, no slot injection, no
		 * short-circuit. The List view then behaves exactly as if this addon were
		 * not installed.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init() {
			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			/*
			 * Settings FIRST, and as a hard stop rather than a fallback. It is what
			 * declares the mode vocabulary, so on a partially deployed tree there is
			 * no mode to be in — and every `Settings::TEC_VIEWS_*` reference below
			 * (here and in the callbacks this registers) is only reachable past this
			 * line, which is what keeps them from fatalling.
			 */
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' ) ) {
				return;
			}

			self::$mode = self::resolve_mode();

			if ( Settings::TEC_VIEWS_OFF === self::$mode ) {
				return;
			}

			self::register_header_swap_hooks();
		}

		/*
		 * NOTE: `register_replace_hooks()` lived here — the `replace` mode's two
		 * bootstrap filters (`tribe_events_views_v2_bootstrap_pre_get_view_html`
		 * at `BOOTSTRAP_PRIORITY`, plus `tribe_events_views_v2_bootstrap_html` as
		 * the net beneath it for the case where `resort_active_iterations()` skips
		 * the first) and its own asset enqueue.
		 *
		 * That mode is not part of this plugin. `BOOTSTRAP_PRIORITY` and the
		 * priority reasoning it recorded went with it — nothing here registers on
		 * a bootstrap filter any more, so there is no ordering hazard left to
		 * document.
		 */

		/**
		 * `header_swap` — TEC keeps its results; we swap its header controls and
		 * drive its query.
		 *
		 * @since 2.6.0
		 * @return void
		 */
		private static function register_header_swap_hooks() {
			// 1. Context locations — FIRST, so they exist before get_locations().
			add_filter( 'tribe_context_locations', array( __CLASS__, 'register_context_locations' ) );

			// 2. Drive TEC's repository from our facets.
			add_filter( 'tribe_events_views_v2_view_repository_args', array( __CLASS__, 'filter_repository_args' ), 10, 3 );

			// 3. Carry the ecsa_* params through pagination + view-switch URLs.
			add_filter( 'tribe_events_views_v2_url_query_args', array( __CLASS__, 'filter_url_query_args' ), 15, 3 );
			add_filter( 'tribe_events_views_v2_view_url_query_args', array( __CLASS__, 'filter_view_url_query_args' ), 10, 3 );

			// 4. Paint the bar into the slot, and verify the theme kept the slot.
			add_filter( 'tribe_template_include_html:events/v2/components/filter-bar', array( __CLASS__, 'inject_bar' ), 10, 4 );
			add_filter( 'tribe_template_include_html:events/v2/list', array( __CLASS__, 'verify_injection' ), 10, 4 );

			/*
			 * 4b. THE HEADER SWAP ITSELF — two children removed, and
			 * only those two.
			 *
			 * `components/events-bar` carries TEC's search form AND its
			 * List/Month/Day selector; `tribe_events_views_v2_view_list_display_events_bar`
			 * is the purpose-built filter for it, and the template early-returns on
			 * `if ( empty( $display_events_bar ) )`, so returning false means the
			 * markup is never generated — no DOM, no `events-bar.js` initialising
			 * against a hidden form, no second search UI writing the same view state.
			 * The filter name is already view-scoped (TEC interpolates the view slug),
			 * so Month and Day keep their native bar.
			 *
			 * `<view>/top-bar` — the date nav — has no dedicated filter, so it goes
			 * through `tribe_template_done`, the supported "disable this template
			 * before it renders" hook. That one is GLOBAL (every Tribe template
			 * include passes through it), which is why `suppress_top_bar()` is a
			 * cheap name test gated on the current view being List.
			 *
			 * This REPLACED a pair of `tribe_template_html:` callbacks that blanked
			 * the events bar's search sub-components after they had been built. Those
			 * left the view selector in place, which defeats the swap, and
			 * they blanked HTML that TEC had already generated (and whose JS had
			 * already been enqueued) rather than preventing it.
			 */
			add_filter( 'tribe_events_views_v2_view_list_display_events_bar', array( __CLASS__, 'suppress_events_bar' ), 10, 2 );
			add_filter( 'tribe_template_done', array( __CLASS__, 'suppress_top_bar' ), 10, 4 );

			// 5. Style AND hydrate the injected bar. Enqueued in the head (priority
			// 20, after Render\Assets::register at 10) so the always-present bar is
			// styled without the the_content->footer FOUC the v1.x plugin shipped.
			// The dropped-slot admin NOTICE has a single, screen-scoped reader in
			// Settings_Page (admin-only); this class is only the writer.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		}

		/**
		 * The feature gate, resolved to the mode this request will run in.
		 *
		 * `off` unless BOTH hold:
		 *
		 *  - a compatible The Events Calendar is available;
		 *  - the admin chose a mode other than `off`.
		 *
		 * THAT IS THE WHOLE GATE. There is deliberately no third condition
		 * probing for TEC's paid Filter Bar:
		 * our bar always runs when the admin asked for it, so this feature has no
		 * dependency on another product's bootstrap internals and cannot silently
		 * disable itself because a sibling plugin changed a hook name. See the
		 * file docblock for the collision that trade-off accepts.
		 *
		 * Only ever called from `init()`, which has already established that
		 * `Settings` is loadable; the TEC reference is `class_exists`-guarded here,
		 * so a partially deployed tree degrades to `off` rather than fatalling
		 * on a half-copied deploy.
		 *
		 * @since 2.6.0
		 * @return string `off` | `header_swap`.
		 */
		private static function resolve_mode() {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Tec\Tec' ) || ! Tec::available() ) {
				return Settings::TEC_VIEWS_OFF;
			}

			return Settings::tec_views_mode();
		}

		/**
		 * The mode this request booted in (read-only; `off` before `init()`).
		 *
		 * @since 2.6.0
		 * @return string `off` | `header_swap`.
		 */
		public static function mode() {
			return self::$mode;
		}

		/* -----------------------------------------------------------------
		 * 0. Front-end assets
		 * -------------------------------------------------------------- */

		/**
		 * Enqueue the front-end runtime on any request that will render TEC's List
		 * view — the SAME assets a shortcode pair gets, in both live modes.
		 *
		 *   header_swap  style + script: the
		 *                injected bar must look and behave like the shortcode bar
		 *                — styled filter triggers, popovers, the admin's chosen
		 *                `filter_style` honoured, `bar_inline` collapsing at its
		 *                threshold. All of that is JS-driven: the server renders
		 *                the native `<select>` substrate visible and the styled
		 *                trigger `hidden`, and only the runtime swaps them. So the
		 *                stylesheet ALONE (what this mode used to ship) guaranteed
		 *                the events-page bar looked unlike every other surface.
		 *                The no-JS contract is untouched — see `render_bar()`.
		 *
		 * Runs on `wp_enqueue_scripts` (head) rather than at render time, which is
		 * what keeps the always-present bar out of the `the_content` -> footer FOUC
		 * the v1.x plugin shipped.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function enqueue_assets() {
			/*
			 * The `'off'` literal is a GUARD, not a second copy of the vocabulary:
			 * it is `$mode`'s initial value, and the only value it can hold when
			 * `Settings` never loaded — so testing it here is what keeps this
			 * public method safe for a third party to call directly.
			 */
			if ( 'off' === self::$mode || ! self::is_events_list_context() ) {
				return;
			}

			self::ensure_runtime_assets();
		}

		/**
		 * Enqueue + localize the full front-end runtime (style, script, config).
		 *
		 * One `class_exists`-guarded call site, so a partially deployed tree
		 * degrades to an unstyled bar rather than a fatal.
		 *
		 * @since 2.6.0
		 * @return void
		 */
		private static function ensure_runtime_assets() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Render\Assets' ) ) {
				\CoolPlugins\EventsSearch\Render\Assets::ensure_v2_localized();
			}
		}

		/**
		 * Whether the current main query will render TEC's List view (the events
		 * archive or a TEC taxonomy archive), where our bar is injected.
		 *
		 * Evaluated at `wp_enqueue_scripts`, after the main query is parsed. Covers
		 * the events archive, category/tag archives, and the events "home".
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private static function is_events_list_context() {
			if ( function_exists( 'tribe_is_event_query' ) && tribe_is_event_query() ) {
				return true;
			}

			if ( function_exists( 'tribe_is_events_home' ) && tribe_is_events_home() ) {
				return true;
			}

			if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'tribe_events' ) ) {
				return true;
			}

			if ( function_exists( 'is_tax' ) && is_tax( array( 'tribe_events_cat', 'post_tag' ) ) ) {
				return true;
			}

			return false;
		}

		/* -----------------------------------------------------------------
		 * 1. Context locations
		 * -------------------------------------------------------------- */

		/**
		 * Register every `ecsa_*` param as a TEC context location.
		 *
		 * Each var reads from BOTH the request var and the query var, and writes
		 * to both — so a value survives whether it arrives as `$_GET` on a direct
		 * hit or is rebuilt from the posted URL on a REST-driven pagination
		 * click (`View::make_for_rest()`).
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $locations Current context locations.
		 * @return array<string, mixed>
		 */
		public static function register_context_locations( $locations ) {
			if ( ! is_array( $locations ) || ! class_exists( 'Tribe__Context' ) ) {
				return $locations;
			}

			$request = \Tribe__Context::REQUEST_VAR;
			$query   = \Tribe__Context::QUERY_VAR;

			foreach ( self::CONTEXT_VARS as $var ) {
				// Do not clobber a location another plugin already registered
				// under the same key.
				if ( isset( $locations[ $var ] ) ) {
					continue;
				}

				$locations[ $var ] = array(
					'read'  => array(
						$request => array( $var ),
						$query   => array( $var ),
					),
					'write' => array(
						$request => $var,
						$query   => $var,
					),
				);
			}

			return $locations;
		}

		/* -----------------------------------------------------------------
		 * 2. Repository args
		 * -------------------------------------------------------------- */

		/**
		 * Merge our facet-driven repository-args fragment into TEC's, on the List
		 * view only.
		 *
		 * Idempotent: the Latest-Past sub-view (and any re-resolution) can re-fire
		 * this filter, and re-merging the same fragment is a no-op.
		 *
		 * @since 2.0.0
		 * @param mixed $repository_args Incoming args from TEC.
		 * @param mixed $context         The render Context (may be null).
		 * @param mixed $view            The View instance.
		 * @return mixed
		 */
		public static function filter_repository_args( $repository_args, $context = null, $view = null ) {
			if ( ! is_array( $repository_args ) ) {
				return $repository_args;
			}

			if ( ! self::is_target_view( $view ) ) {
				return $repository_args;
			}

			$ctx = self::context_from( $context, $view );

			$criteria = self::criteria_from_context( $ctx );

			if ( empty( $criteria ) || ! class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) || ! Criteria::is_filtered( $criteria ) ) {
				return $repository_args;
			}

			if ( ! class_exists( 'CoolPlugins\EventsSearch\Query\Query_Engine' ) ) {
				return $repository_args;
			}

			$fragment = Query_Engine::for_tec_injection( $criteria );

			if ( empty( $fragment ) ) {
				return $repository_args;
			}

			return self::merge_repository_args( $repository_args, $fragment );
		}

		/**
		 * The allowlisted merge .
		 *
		 * ONLY these keys are ever written: `post__in`, `date_overlaps`,
		 * `ends_after`, `has_password`. (`event_category`, `tag`, `venue` and
		 * `organizer` were on that list too, written from the id facets; those
		 * facets are not part of this plugin, so nothing produces them.)
		 * TEC's own `post__not_in`, `posts_per_page`, `paged`, `offset`,
		 * `view_override_offset` and `hidden_from_upcoming` are NEVER touched —
		 * writing any of them un-hides hidden events or breaks the `+1`
		 * has-next-page trick and the offset override.
		 *
		 * `has_password => false` (a native TEC repository modifier that becomes
		 * WP_Query's `AND post_password = ''`) excludes password-protected events
		 * from EVERY filtered injection — facet-only included — matching the
		 * REST/SSR engine so the injected List view never becomes a content oracle
		 *
		 * `post__in` is INTERSECTED with any pre-existing restriction (never
		 * replaced) and can never become `[]`: an empty result collapses to the
		 * `[0]` sentinel, because WP_Query ignores an empty `post__in` and would
		 * turn "no matches" into "everything".
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $repository_args Incoming args.
		 * @param array<string, mixed> $fragment        Injection fragment.
		 * @return array<string, mixed>
		 */
		private static function merge_repository_args( array $repository_args, array $fragment ) {
			// Native date + password-exclusion keys: written straight through. On a
			// TEC taxonomy archive (also slug `list`) an explicit date range
			// OVERWRITES that archive's own scope for the SAME key — the visitor's
			// explicit choice wins — while anything the visitor did NOT set is left
			// as the archive supplied it (only `post__in` intersects). Documented v1
			// behaviour, not a bug.
			foreach ( array( 'date_overlaps', 'ends_after', 'has_password' ) as $key ) {
				if ( array_key_exists( $key, $fragment ) ) {
					$repository_args[ $key ] = $fragment[ $key ];
				}
			}

			// post__in: intersect + sentinel. Only when the fragment carries one
			// (i.e. a keyword was present); a date-only filter leaves TEC's own
			// post__in — and its native paging — untouched.
			if ( array_key_exists( 'post__in', $fragment ) ) {
				$repository_args['post__in'] = self::intersect_post_in(
					isset( $repository_args['post__in'] ) ? $repository_args['post__in'] : null,
					$fragment['post__in']
				);
			}

			return $repository_args;
		}

		/**
		 * Intersect an incoming `post__in` with the injected one, never empty.
		 *
		 * @since 2.0.0
		 * @param mixed $existing Incoming post__in (array or absent).
		 * @param mixed $inject   Injected post__in (array; may be the [0] sentinel).
		 * @return int[] Never empty.
		 */
		private static function intersect_post_in( $existing, $inject ) {
			$inject = array_values( array_filter( array_map( 'absint', (array) $inject ) ) );

			// The fragment's own [0] sentinel drops to [] under absint; restore it.
			if ( empty( $inject ) ) {
				return array( 0 );
			}

			if ( empty( $existing ) || ! is_array( $existing ) ) {
				return $inject;
			}

			$prior = array_values( array_filter( array_map( 'absint', $existing ) ) );

			if ( empty( $prior ) ) {
				return $inject;
			}

			// Preserve the EXISTING restriction order (TEC set it deliberately).
			$intersect = array_values( array_intersect( $prior, $inject ) );

			return $intersect ? $intersect : array( 0 );
		}

		/* -----------------------------------------------------------------
		 * 3. URL query args (pagination + view-switch carry)
		 * -------------------------------------------------------------- */

		/**
		 * Add the active `ecsa_*` params to a View URL's query args.
		 *
		 * Signature: `( $query_args, $view, $canonical )`.
		 *
		 * @since 2.0.0
		 * @param mixed $query_args Incoming query args.
		 * @param mixed $view       The View instance.
		 * @param mixed $canonical  Whether the URL is canonical (unused).
		 * @return mixed
		 */
		public static function filter_url_query_args( $query_args, $view = null, $canonical = false ) {
			return self::merge_query_args( $query_args, $view );
		}

		/**
		 * Add the active `ecsa_*` params to the per-view URL query args.
		 *
		 * Signature: `( $query_args, $view_slug, $view )` — note the DIFFERENT arg
		 * order from `filter_url_query_args()`; the View instance is the third arg
		 * here, the second there.
		 *
		 * @since 2.0.0
		 * @param mixed $query_args Incoming query args (starts as `[]`).
		 * @param mixed $view_slug  The view slug (string; unused).
		 * @param mixed $view       The View instance.
		 * @return mixed
		 */
		public static function filter_view_url_query_args( $query_args, $view_slug = '', $view = null ) {
			return self::merge_query_args( $query_args, $view );
		}

		/**
		 * Fold every non-empty `ecsa_*` context value into a URL's query args, so
		 * filters ride along on pagination and view-switch links.
		 *
		 * NOT gated to the List view: carrying the params onto a month/day URL is
		 * harmless (those views ignore them) and lets a switch BACK to List
		 * restore the filters.
		 *
		 * @since 2.0.0
		 * @param mixed $query_args Incoming query args.
		 * @param mixed $view       The View instance.
		 * @return mixed
		 */
		private static function merge_query_args( $query_args, $view ) {
			if ( ! is_array( $query_args ) ) {
				return $query_args;
			}

			$ctx    = self::context_from( null, $view );
			$params = self::context_params( $ctx );

			foreach ( $params as $key => $value ) {
				$query_args[ $key ] = $value;
			}

			return $query_args;
		}

		/* -----------------------------------------------------------------
		 * 4. Slot injection + verify/fallback
		 * -------------------------------------------------------------- */

		/**
		 * Fill the render-empty `components/filter-bar` slot with our bar — List
		 * view only.
		 *
		 * @since 2.0.0
		 * @param mixed $html     The slot HTML (empty for the stock component).
		 * @param mixed $file     The template file (unused).
		 * @param mixed $name     The template name (unused).
		 * @param mixed $template The View's Tribe__Template instance.
		 * @return mixed
		 */
		public static function inject_bar( $html, $file = '', $name = array(), $template = null ) {
			if ( self::VIEW_SLUG !== self::current_view_slug( $template ) ) {
				return $html;
			}

			$bar = self::render_bar( $template );

			if ( '' === $bar ) {
				return $html;
			}

			self::$did_inject = true;

			// The stock slot is empty, so this simply becomes the slot content.
			return $html . $bar;
		}

		/**
		 * Verify the slot was actually filled; if a theme dropped it, prepend the
		 * bar and flag an admin notice — List view only.
		 *
		 * Fires at the END of the List render (after the nested slot include), so
		 * `$did_inject` truthfully reports whether `inject_bar()` ran. It is reset
		 * here for the next render on the page. This is the ONLY reliable verify
		 * point in TEC 6.17: `tribe_events_views_v2_view_before_html` does not
		 * exist, and `after_make_view` fires pre-render (and initial-path only).
		 *
		 * @since 2.0.0
		 * @param mixed $html     The full List HTML.
		 * @param mixed $file     The template file (unused).
		 * @param mixed $name     The template name (unused).
		 * @param mixed $template The View's Tribe__Template instance.
		 * @return mixed
		 */
		public static function verify_injection( $html, $file = '', $name = array(), $template = null ) {
			if ( self::VIEW_SLUG !== self::current_view_slug( $template ) ) {
				return $html;
			}

			$injected = self::$did_inject;

			// Reset for the next List render in this request (Latest-Past, a
			// second [tribe_events] shortcode, etc.).
			self::$did_inject = false;

			if ( $injected ) {
				return $html;
			}

			// The theme's list.php override dropped the components/filter-bar
			// include. Prepend the bar so the feature still works, and warn.
			$bar = self::render_bar( $template );

			if ( '' === $bar ) {
				return $html;
			}

			self::flag_dropped_slot();

			return $bar . $html;
		}

		/* -----------------------------------------------------------------
		 * 4b. The header swap (`header_swap` mode)
		 * -------------------------------------------------------------- */

		/**
		 * Suppress TEC's events bar on the List view — its search form AND its
		 * List/Month/Day selector, which live in that one component.
		 *
		 * Hooked on `tribe_events_views_v2_view_list_display_events_bar`, the
		 * purpose-built filter TEC interpolates the view slug into
		 * (`View::filter_display_events_bar()`), so it is ALREADY scoped to List:
		 * Month and Day never reach this callback and keep their native bar. The
		 * template's first statement is `if ( empty( $display_events_bar ) ) {
		 * return; }`, so returning false means the markup is never generated —
		 * nothing to hide, nothing for `events-bar.js` to bind to.
		 *
		 * It does NOT touch `components/header` itself. That shell also renders the
		 * page `<h1>` (`content-title`), the backlink/breadcrumbs and
		 * `components/messages` — TEC's empty-state and error region — none of
		 * which this integration may take away.
		 *
		 * @since 2.6.0
		 * @param mixed $display Whether TEC would display its events bar.
		 * @param mixed $view    The View instance (unused).
		 * @return bool Always false — the filter is already List-scoped.
		 */
		public static function suppress_events_bar( $display = true, $view = null ) {
			unset( $display, $view );

			return false;
		}

		/**
		 * Suppress the List view's top bar (the date nav) before it renders.
		 *
		 * `tribe_template_done` is the supported "do not render this template" hook
		 * (`Tribe__Template::template()` returns false the moment it sees a
		 * non-null value), and it is the only public hook that reaches
		 * `<view>/top-bar`, which has no filter of its own. It is also GLOBAL —
		 * every Tribe template include in every Tribe plugin passes through it — so
		 * this callback is written to be as cheap and as narrow as possible: a name
		 * test first, and only then the current-view test.
		 *
		 * The name arrives exactly as `header.php` passed it,
		 * `[ $this->get_view_slug(), 'top-bar' ]`, so both the array and the
		 * string spelling are matched and normalised before comparison. The
		 * current-view gate means a `list/top-bar` include from some other context
		 * is left alone.
		 *
		 * @since 2.6.0
		 * @param mixed $done    Null to continue rendering; anything else stops it.
		 * @param mixed $name    Template name (string or path segments).
		 * @param mixed $context Template context (unused).
		 * @param mixed $echo    Whether the template would be printed (unused).
		 * @return mixed '' for the List top bar; the incoming $done otherwise.
		 */
		public static function suppress_top_bar( $done = null, $name = '', $context = array(), $echo = true ) {
			unset( $context, $echo );

			// Another callback already stopped this template: leave its answer.
			if ( null !== $done ) {
				return $done;
			}

			if ( is_array( $name ) ) {
				// Scalar-only, so a nested array can never reach strval() and
				// raise an "Array to string conversion" notice on a hook that
				// fires for every Tribe template include on the site.
				foreach ( $name as $part ) {
					if ( ! is_scalar( $part ) ) {
						return $done;
					}
				}

				$name = implode( '/', array_map( 'strval', $name ) );
			}

			if ( ! is_string( $name ) || self::VIEW_SLUG . '/top-bar' !== $name ) {
				return $done;
			}

			if ( self::VIEW_SLUG !== self::current_view_slug( null ) ) {
				return $done;
			}

			return '';
		}

		/*
		 * NOTE: the whole `replace` mode lived here — five methods, section 4c.
		 *
		 *   replace_view_html()       the short-circuit on
		 *                             `…_bootstrap_pre_get_view_html`, with its
		 *                             three gates (resolved slug is `list`, not a
		 *                             single-event request, renderer loadable).
		 *   replace_bootstrap_html()  the net beneath it, for the dispatch-skip
		 *                             `WP_Hook::resort_active_iterations()` can
		 *                             cause.
		 *   resolve_view_slug()       mirrored `View::make()`'s own resolution one
		 *                             step early, because the bootstrap filters are
		 *                             handed the RAW `event_display` (`default` on
		 *                             the canonical `/events/` permalink), not a
		 *                             view slug.
		 *   view_manager()            the try/catch-wrapped `tribe()` lookup that
		 *                             resolution needed.
		 *   replacement_config()      one half of the bar + results PAIR the mode
		 *                             composed from two `Renderer::instance()`
		 *                             calls sharing a `target`.
		 *   is_single_event_request() the gate that stopped a single event page
		 *                             being replaced by a search grid.
		 *
		 * That mode is not part of this plugin, and none of those helpers has
		 * another caller: `header_swap` reads `current_view_slug()` (the RESOLVED
		 * view, which is why it never had the `default`-slug bug), builds its bar
		 * from `render_bar()`, and never touches TEC's bootstrap.
		 */

		/**
		 * Render the injected bar: a bar-only, pre-filled copy of the SITE-DEFAULT
		 * bar — the same one `[events-calendar-search]` paints, down to the
		 * wrapper class, the CSS custom properties and the filter placement.
		 *
		 * It reuses `Renderer::bar()` verbatim — the bar is already a no-JS GET
		 * form whose `ecsa_*` controls submit to the current URL (which, on the
		 * List view, IS `/events/`), so a submit lands back on the List view and
		 * the context/repository-args wiring above does the rest.
		 *
		 * ONLY TWO KEYS ARE OVERRIDDEN, and both are facts about this surface
		 * rather than opinions about how it should look:
		 * `role` (a bar, never results) and `results_mode` (`inline` — the results
		 * ARE on this page; they are TEC's). Everything else — the filter
		 * placement, the trigger style, the Filters-button style, the palette, the
		 * sizes — is the admin's saved choice, inherited exactly as every other
		 * surface inherits it.
		 *
		 * WHAT WAS REMOVED, so it is not "restored" as a fix: this method used to
		 * hard-force `filters_visibility = bar_button`. That single line was the
		 * whole of "the events page ignores my settings" — an admin who chose
		 * filters under the bar, inline in the bar, or expanded got a Filters
		 * disclosure anyway, i.e. a completely different filters DOM from the one
		 * the settings preview had just shown them.
		 *
		 * TYPE-AHEAD IS OFF, AND THAT IS NOW A DECISION WITH A REASON (item 9b.4).
		 * It is derived from the placement through `Settings::derive_typeahead()`,
		 * the single statement of that rule, exactly as `replacement_config()`
		 * derives it — not asserted separately here. `inline` implies `off`, and
		 * on this surface that is the right answer for three independent reasons:
		 *   1. TEC's own server-rendered results sit DIRECTLY below this bar, so a
		 *      dropdown would float over the very list it is a shortcut to;
		 *   2. the dropdown ships only WITH its "See all N results" exit row
		 *      (product decision 4), and `Instance.hasResultsExit()` is false for
		 *      exactly this shape — role `bar`, placement `inline`, no target — so
		 *      the dropdown could not ship its required exit even if we asked;
		 *   3. it keeps `/suggest` traffic off every events page load.
		 * The runtime being present (see `enqueue_assets()`) does not change any
		 * of that; it is what makes the FILTERS behave like the shortcode bar's.
		 *
		 * @since 2.0.0
		 * @param mixed $template The View's Tribe__Template instance (for context).
		 * @return string Bar HTML, or '' when it cannot be built.
		 */
		private static function render_bar( $template ) {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Render\Renderer' )
				|| ! class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' ) ) {
				return '';
			}

			$config = Settings::instance_defaults();

			$config['role']         = 'bar';
			$config['results_mode'] = 'inline';

			// DERIVED from that placement, never a second opinion about it — see
			// the docblock for why `off` is the right answer on this surface.
			$config['typeahead'] = Settings::derive_typeahead( $config['results_mode'] );

			$criteria = self::criteria_from_context( self::context_from( null, self::current_view() ) );

			if ( empty( $criteria ) ) {
				$criteria = class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ? Criteria::normalize( array() ) : array();
			}

			// A per-render UNIQUE instance id: every DOM id in the
			// bar derives from it, so a fixed literal would collide if a page ever
			// carried two List renders. wp_unique_id() is request-unique.
			$instance_id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ecsa-tec-' ) : 'ecsa-tec-' . uniqid();

			$bar = Renderer::bar( $instance_id, $config, $criteria );

			/*
			 * THE WRAPPER IS THE SAME WRAPPER `Renderer::instance()` EMITS.
			 *
			 * It carries the `.ecsa` design tokens (template/accent/button/size/
			 * radius via `Renderer::design_attrs()`) AND the full `data-ecsa-*`
			 * contract the front-end runtime reads, `data-ecsa-instance` first
			 * among them — `App.boot()` selects `.ecsa[data-ecsa-instance]`, so
			 * omitting it (as this method used to) meant the runtime never adopted
			 * the injected bar and the filters stayed raw `<select>` substrate
			 * while every other surface got styled triggers and popovers.
			 *
			 * WITH NO `data-ecsa-target`, THE NO-JS CONTRACT IS PRESERVED, NOT
			 * REPLACED. `Instance.commit()` falls back to a real navigation when
			 * there is no results region to update in place, and the submit
			 * handler returns early so the browser's own GET submit runs. Either
			 * way the visitor lands back on `/events/` carrying `ecsa_*` params,
			 * which is precisely what the context-locations and repository-args
			 * wiring above consumes. JS only upgrades the CONTROLS.
			 *
			 * `data-ecsa-url-owner` is `0`: TEC owns this page's URL and its
			 * pagination. `data-ecsa-suggest-limit` is omitted because the
			 * type-ahead is off here, so there is no suggestion count to govern.
			 * `data-ecsa-tec` stays as the marker for the CSS hook and for anyone
			 * asking which surface this is.
			 */
			$design = Renderer::design_attrs( $config );

			$attrs = ' class="' . esc_attr( $design['class'] ) . ' ecsa-tec-bar"'
				. ' data-ecsa-tec="1"'
				. ' data-ecsa-instance="' . esc_attr( $instance_id ) . '"'
				. ' data-ecsa-role="bar"'
				. ' data-ecsa-typeahead="' . esc_attr( $config['typeahead'] ) . '"'
				. ' data-ecsa-results-mode="' . esc_attr( $config['results_mode'] ) . '"'
				. ' data-ecsa-target=""'
				. ' data-ecsa-url-owner="0"'
				. ' data-ecsa-criteria="' . esc_attr( self::criteria_hash( $criteria ) ) . '"'
				. ' data-ecsa-state="' . esc_attr( (string) wp_json_encode( $criteria ) ) . '"';

			if ( '' !== $design['style'] ) {
				$attrs .= ' style="' . esc_attr( $design['style'] ) . '"';
			}

			return '<div' . $attrs . '>' . self::page_title() . $bar . self::active_filter_links( $criteria, array() ) . '</div>';
		}

		/**
		 * The owner's optional heading for the events page.
		 *
		 * WHY THIS EXISTS. The events archive is not a WordPress page, so there is
		 * nowhere obvious to put a heading on it, and owners were resorting to
		 * template edits to get one.
		 *
		 * IT IS AN <h2>, and that is not a style preference. `header_swap` keeps
		 * TEC's header shell precisely so the page keeps its own `<h1>`, its
		 * breadcrumbs and its messages region — so an `<h1>` here would be a
		 * SECOND one. Two `<h1>`s is an SEO and screen-reader defect that is
		 * invisible on whichever theme happens to be in front of you, which is the
		 * worst kind. An `<h2>` reads as the page heading where the theme prints
		 * nothing, and reads correctly as a sub-heading where it does.
		 *
		 * BLANK RENDERS NOTHING, not an empty heading: the default is blank and
		 * almost every site will leave it that way, so those sites must get exactly
		 * the markup they got before this existed.
		 *
		 * THE MODE IS CHECKED HERE TOO, even though `Tec_Views::init()` registers no
		 * hook at all when it is `off` and this method cannot currently be reached.
		 * Hiding a control in the panel while its value still applies is how a
		 * setting comes back from the dead — it happened to the design sliders,
		 * which went on feeding the shortcode after their toggle was switched off.
		 * A structural gate plus an explicit one costs a function call.
		 *
		 * @since 2.0.0
		 * @return string The heading markup, or '' when there is nothing to show.
		 */
		private static function page_title() {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' ) ) {
				return '';
			}

			if ( Settings::TEC_VIEWS_HEADER_SWAP !== Settings::tec_views_mode() ) {
				return '';
			}

			$title = trim( (string) Settings::get( 'tec_page_title' ) );

			if ( '' === $title ) {
				return '';
			}

			return '<h2 class="ecsa-tec-title">' . esc_html( $title ) . '</h2>';
		}


		/**
		 * Active-filter chips for the injected bar.
		 *
		 * WHY THIS EXISTS. In `header_swap` the results under the bar are TEC's,
		 * so the bar was the only thing on the page that knew what had been
		 * applied — and it shows the CONTROLS, not the state. A visitor who had
		 * narrowed the view got no summary and, worse, no way to undo one part
		 * of it. `ecsa_time` is the case that bites: the empty state's "search
		 * past events too" link sets it, nothing afterwards surfaces it, and
		 * every later search stays in that window.
		 *
		 * WHY LINKS, NOT THE RESULTS VIEW'S BUTTONS. There the runtime owns the
		 * URL and can re-fetch in place. Here it explicitly does not
		 * (`data-ecsa-url-owner="0"`) — TEC does — so each chip is an ordinary
		 * link to the same page with one key dropped, and the row keeps working
		 * with JavaScript off, which is the whole no-JS contract this surface
		 * already honours.
		 *
		 * The chip set is derived from the criteria that are actually present,
		 * so this needs no free/Pro branch: an edition that cannot produce a
		 * facet never has one to name.
		 *
		 * @since 2.9.0
		 * `$facets` is deliberately untyped: `facet_data()` answers NULL on a
		 * surface that offers no facets at all (free's events page, and any
		 * partial deploy where the facet layer did not load), and a chip row
		 * must degrade to naming ids rather than fataling a template filter.
		 *
		 * @param array<string, mixed> $criteria Normalized criteria.
		 * @param mixed                $facets   Facet data for id -> label, or null.
		 * @return string HTML, or '' when nothing is applied.
		 */
		private static function active_filter_links( array $criteria, $facets ) {
			$facets = is_array( $facets ) ? $facets : array();
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Query\Url_State' ) ) {
				return '';
			}

			$base = self::events_page_base_url();
			$chips = array();

			/**
			 * One chip: the label, and the criteria that remain once it is gone.
			 *
			 * @param string               $label Human label.
			 * @param array<string, mixed> $drop  Criteria overrides that clear it.
			 * @return void
			 */
			$add = function ( $label, array $drop ) use ( &$chips, $criteria, $base ) {
				$next = array_merge( $criteria, $drop );
				// Page 1: removing a narrowing filter widens the set, so holding
				// a deep page would land the visitor past the end of it.
				$next['page'] = 1;

				$chips[] = array(
					'label' => $label,
					'url'   => Url_State::to_url( $next, $base ),
				);
			};

			if ( ! empty( $criteria['q'] ) ) {
				$add(
					sprintf(
						/* translators: %s: the search keyword. */
						__( 'Search: %s', 'events-search-addon-for-the-events-calendar' ),
						(string) $criteria['q']
					),
					array( 'q' => '' )
				);
			}

			if ( ! empty( $criteria['date_preset'] ) && 'any' !== $criteria['date_preset'] ) {
				$labels = self::date_preset_labels();
				$key    = (string) $criteria['date_preset'];
				$add(
					isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
					array( 'date_preset' => 'any', 'date_from' => '', 'date_to' => '' )
				);
			}

			// The sticky one. `upcoming` is the default window, so only a
			// deliberate widening gets a chip — and therefore a way out.
			if ( ! empty( $criteria['time'] ) && 'upcoming' !== $criteria['time'] ) {
				// ONE source for these two labels. They were spelled out here as
				// well as in `Renderer::time_labels()`, and the JS twin makes a
				// third copy unavoidable - but a second PHP copy is not, and it
				// would drift the first time either string was reworded.
				$windows = \CoolPlugins\EventsSearch\Render\Renderer::time_labels();
				$key = (string) $criteria['time'];
				$add(
					isset( $windows[ $key ] ) ? $windows[ $key ] : $key,
					array( 'time' => 'upcoming' )
				);
			}

			// Id-based facets (Pro). Absent in free, so the loop simply finds
			// nothing rather than needing to know which edition it is in.
			$id_facets = array(
				'categories' => 'category',
				'tags'       => 'tag',
				'venues'     => 'venue',
				'organizers' => 'organizer',
			);

			foreach ( $id_facets as $crit_key => $facet_key ) {
				if ( empty( $criteria[ $crit_key ] ) || ! is_array( $criteria[ $crit_key ] ) ) {
					continue;
				}

				$labels = array();
				if ( ! empty( $facets[ $facet_key ]['options'] ) && is_array( $facets[ $facet_key ]['options'] ) ) {
					foreach ( $facets[ $facet_key ]['options'] as $opt ) {
						if ( isset( $opt['id'], $opt['label'] ) ) {
							$labels[ (int) $opt['id'] ] = (string) $opt['label'];
						}
					}
				}

				foreach ( $criteria[ $crit_key ] as $id ) {
					$id        = (int) $id;
					$remaining = array_values( array_diff( array_map( 'intval', $criteria[ $crit_key ] ), array( $id ) ) );
					$add(
						isset( $labels[ $id ] ) ? $labels[ $id ] : '#' . $id,
						array( $crit_key => $remaining )
					);
				}
			}

			// Tier-1 location (Pro): the stored value IS the label.
			if ( ! empty( $criteria['location'] ) && is_array( $criteria['location'] ) ) {
				foreach ( array( 'city', 'state', 'country' ) as $scale ) {
					if ( empty( $criteria['location'][ $scale ] ) ) {
						continue;
					}
					$next_loc = $criteria['location'];
					$next_loc[ $scale ] = '';
					$add( (string) $criteria['location'][ $scale ], array( 'location' => $next_loc ) );
				}
			}

			if ( ! $chips ) {
				return '';
			}

			$html = '<div class="ecsa-tec-active" role="group" aria-label="'
				. esc_attr__( 'Active filters', 'events-search-addon-for-the-events-calendar' ) . '">';

			foreach ( $chips as $chip ) {
				$html .= '<a class="ecsa-tec-active__chip" href="' . esc_url( $chip['url'] ) . '"'
					. ' aria-label="' . esc_attr( sprintf(
						/* translators: %s: the filter being removed. */
						__( 'Remove filter: %s', 'events-search-addon-for-the-events-calendar' ),
						$chip['label']
					) ) . '">'
					. '<span class="ecsa-tec-active__label">' . esc_html( $chip['label'] ) . '</span>'
					. '<span class="ecsa-tec-active__x" aria-hidden="true">&times;</span>'
					. '</a>';
			}

			$html .= '<a class="ecsa-tec-active__clear" href="' . esc_url( $base ) . '">'
				. esc_html__( 'Clear all', 'events-search-addon-for-the-events-calendar' ) . '</a>';
			$html .= '</div>';

			return $html;
		}

		/**
		 * The events page with every one of our parameters dropped.
		 *
		 * Built by REMOVING our keys from the current request rather than by
		 * asking TEC for a canonical link: the visitor may be on a view or a
		 * paged URL that is TEC's business, and this must clear our filters
		 * without also throwing away where they are.
		 *
		 * @since 2.9.0
		 * @return string
		 */
		private static function events_page_base_url() {
			$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$url     = home_url( $request );

			$ours = self::CONTEXT_VARS;
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Url_State' ) && defined( 'CoolPlugins\EventsSearch\Query\Url_State::PARAM_MAP' ) ) {
				$ours = array_merge( $ours, array_values( Url_State::PARAM_MAP ) );
			}

			return remove_query_arg( array_unique( $ours ), $url );
		}

		/**
		 * Date-preset labels for the chips.
		 *
		 * Kept beside the chips rather than reached for across classes: the
		 * renderer's own copy is private to it, and this surface must degrade to
		 * the raw key rather than fatal on a partial deploy.
		 *
		 * @since 2.9.0
		 * @return array<string, string>
		 */
		private static function date_preset_labels() {
			return array(
				'today'        => __( 'Today', 'events-search-addon-for-the-events-calendar' ),
				'tomorrow'     => __( 'Tomorrow', 'events-search-addon-for-the-events-calendar' ),
				'this_weekend' => __( 'This weekend', 'events-search-addon-for-the-events-calendar' ),
				'this_week'    => __( 'This week', 'events-search-addon-for-the-events-calendar' ),
				'next_week'    => __( 'Next week', 'events-search-addon-for-the-events-calendar' ),
				'this_month'   => __( 'This month', 'events-search-addon-for-the-events-calendar' ),
				'next_month'   => __( 'Next month', 'events-search-addon-for-the-events-calendar' ),
				'custom'       => __( 'Custom dates', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * The criteria fingerprint the runtime compares its own state against.
		 *
		 * `class_exists`-guarded rather than assumed: `render_bar()` already
		 * degrades to an empty criteria array on a partial deploy, and an empty
		 * hash is a value the runtime handles (it simply never matches), so a
		 * missing Criteria class must not fatal a template filter.
		 *
		 * @since 2.7.0
		 * @param array<string, mixed> $criteria Normalized criteria.
		 * @return string
		 */
		private static function criteria_hash( array $criteria ) {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return '';
			}

			return (string) Criteria::hash( $criteria );
		}

		/*
		 * NOTE: `facet_data()` lived here, building the option lists the bar's
		 * id-facet panels rendered from, exactly as `Renderer::instance()` sourced
		 * them. Those facets are not part of this plugin and `Renderer::bar()` no
		 * longer takes a facet bag at all, so there is nothing to build.
		 */

		/* -----------------------------------------------------------------
		 * Dropped-slot admin notice
		 * -------------------------------------------------------------- */

		/**
		 * Record (once per request) that a theme dropped the injection slot.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function flag_dropped_slot() {
			if ( self::$flagged || ! function_exists( 'set_transient' ) ) {
				return;
			}

			self::$flagged = true;

			set_transient( self::DROPPED_SLOT_TRANSIENT, 1, defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
		}

		/* -----------------------------------------------------------------
		 * Context helpers
		 * -------------------------------------------------------------- */

		/**
		 * Whether a View instance is the List view we integrate with.
		 *
		 * Uses the STATIC `get_view_slug()` (`get_slug()` is deprecated 6.0.7) so
		 * the Latest-Past sub-view (slug `latest-past`) is correctly excluded.
		 *
		 * @since 2.0.0
		 * @param mixed $view Candidate view.
		 * @return bool
		 */
		private static function is_target_view( $view ) {
			if ( ! ( $view instanceof \Tribe\Events\Views\V2\View ) ) {
				return false;
			}

			return self::VIEW_SLUG === (string) $view::get_view_slug();
		}

		/**
		 * The slug of the view currently rendering, for the template callbacks.
		 *
		 * Authoritative source is the current View (via the
		 * `tec_events_get_current_view` filter); the `slug` value the View set on
		 * its own template during setup is the fallback, so the gate still holds
		 * if the current-view filter is somehow unavailable.
		 *
		 * @since 2.0.0
		 * @param mixed $template The View's Tribe__Template instance.
		 * @return string
		 */
		private static function current_view_slug( $template ) {
			$view = self::current_view();

			if ( is_object( $view ) && method_exists( $view, 'get_view_slug' ) ) {
				return (string) $view::get_view_slug();
			}

			if ( is_object( $template ) && method_exists( $template, 'get' ) ) {
				$slug = $template->get( 'slug' );

				if ( is_string( $slug ) && '' !== $slug ) {
					return $slug;
				}
			}

			return '';
		}

		/**
		 * The View instance currently rendering.
		 *
		 * Sourced from the `tec_events_get_current_view` filter the View registers
		 * on itself for the duration of `get_html()` — active during both the
		 * slot include and the outer List include.
		 *
		 * @since 2.0.0
		 * @return mixed View instance or null.
		 */
		private static function current_view() {
			if ( function_exists( 'apply_filters' ) ) {
				$view = apply_filters( 'tec_events_get_current_view', null );

				if ( $view instanceof \Tribe\Events\Views\V2\View ) {
					return $view;
				}
			}

			return null;
		}

		/**
		 * Resolve the Context to read `ecsa_*` from, preferring the one passed.
		 *
		 * @since 2.0.0
		 * @param mixed $context A Context passed to a filter, or null.
		 * @param mixed $view    A View instance, or null.
		 * @return mixed Tribe__Context or null.
		 */
		private static function context_from( $context = null, $view = null ) {
			if ( $context instanceof \Tribe__Context ) {
				return $context;
			}

			if ( $view instanceof \Tribe\Events\Views\V2\View ) {
				$ctx = $view->get_context();

				if ( $ctx instanceof \Tribe__Context ) {
					return $ctx;
				}
			}

			if ( function_exists( 'tribe_context' ) ) {
				$ctx = tribe_context();

				if ( $ctx instanceof \Tribe__Context ) {
					return $ctx;
				}
			}

			return null;
		}

		/**
		 * Read every present `ecsa_*` param out of a Context.
		 *
		 * Returns a map keyed by the `ecsa_*` param name — the exact shape
		 * `Url_State::parse_present()` consumes and TEC's URL builder accepts.
		 * Absent, empty-string and empty-array values are skipped.
		 *
		 * @since 2.0.0
		 * @param mixed $context Tribe__Context or null.
		 * @return array<string, mixed>
		 */
		private static function context_params( $context ) {
			if ( ! is_object( $context ) || ! method_exists( $context, 'get' ) ) {
				return array();
			}

			$out = array();

			foreach ( self::CONTEXT_VARS as $var ) {
				$value = $context->get( $var, null );

				if ( null === $value || '' === $value || array() === $value ) {
					continue;
				}

				$out[ $var ] = $value;
			}

			return $out;
		}

		/**
		 * Turn a Context's `ecsa_*` params into normalized criteria.
		 *
		 * Seeds the admin-only `search_fields` scope from Settings (it is never a
		 * URL param, so it lives in the seed and survives the overlay) so the
		 * injected query honours the same keyword scope the REST/SSR paths do.
		 *
		 * @since 2.0.0
		 * @param mixed $context Tribe__Context or null.
		 * @return array<string, mixed> Normalized criteria, or empty when unusable.
		 */
		private static function criteria_from_context( $context ) {
			$params = self::context_params( $context );

			$seed = array();

			if ( class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' ) ) {
				$scope = Settings::get( 'search_fields' );

				if ( is_array( $scope ) && ! empty( $scope ) ) {
					$seed['search_fields'] = $scope;
				}
			}

			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Url_State' ) ) {
				return Url_State::overlay( $seed, $params );
			}

			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return Criteria::normalize( $seed );
			}

			return array();
		}
	}
}
