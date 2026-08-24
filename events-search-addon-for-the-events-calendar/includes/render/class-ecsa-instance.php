<?php
/**
 * Instance config front door — the one place a raw attribute bag becomes a
 * Renderer config.
 *
 * The bar shortcode, the results shortcode, the classic widget and the TEC
 * List-view integration all speak different attribute vocabularies, but every
 * one of them must hand the Renderer the SAME normalized config shape or two
 * entry points would render subtly different bars from the same intent. This
 * class is that shared front door: a pure, side-effect-free translator that
 * allowlists each value against the `Criteria` constants.
 *
 * It deliberately mirrors `Renderer::normalize_config()`. The Renderer keeps its
 * own guard (it is called directly by some paths and must never trust its
 * caller), so `from_atts()` output is idempotent under that guard — feeding a
 * config produced here back through the Renderer's normalizer is a no-op.
 *
 * Pure by design: no option reads, no globals, no hooks, no output. The only
 * WordPress dependencies are `sanitize_text_field()`, `sanitize_html_class()`
 * and `sanitize_key()`, so the class can be exercised in isolation by stubbing
 * those three functions and defining the `Criteria` constants.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Render;

use CoolPlugins\EventsSearch\Query\Criteria;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Instance' ) ) {

	/**
	 * Turns a raw attribute bag into the Renderer's config array.
	 *
	 * @since 2.0.0
	 */
	final class Instance {

		/**
		 * Allowed instance roles.
		 *
		 * `bar` renders only the search form, `results` only the results region.
		 * There is deliberately NO combined role: a search bar NEVER appends
		 * results. Results come from the results shortcode, and the two halves
		 * are linked by a shared `target` id.
		 *
		 * The retired `bar-results` is DELETED, not mapped — 2.0 never shipped, so
		 * no published shortcode or stored widget anywhere can carry
		 * it (the one frozen vocabulary is v1.3.6's, in `Compat\Legacy_Map`). It is
		 * simply not in the allowlist, so it resolves to `ROLE_DEFAULT` like any
		 * other unknown value.
		 *
		 * TWO allowlists state this — here and in `Renderer::normalize_config()`,
		 * which never trusts its caller. They must move together or the retired
		 * value stays reachable through the Renderer's own front door.
		 *
		 * The one surface that legitimately shows a bar AND results together is
		 * the TEC view injection, which is not a shortcode: it calls the renderer
		 * twice. That is why no combined role has to exist for it.
		 */
		const ROLES = array( 'bar', 'results' );

		/**
		 * Default role when the input is absent or unrecognised.
		 */
		const ROLE_DEFAULT = 'bar';

		/**
		 * Allowed type-ahead modes. The dropdown is a navigator layered over a
		 * results target, never a results mode of its own.
		 */
		const TYPEAHEADS = array( 'off', 'dropdown' );

		/**
		 * Default type-ahead mode.
		 */
		const TYPEAHEAD_DEFAULT = 'dropdown';

		/**
		 * Allowed results modes — WHERE this bar's results appear.
		 *
		 *   none    nowhere. The type-ahead dropdown is the whole experience.
		 *   inline  a results region on this page, placed separately and joined to
		 *           the bar by a shared `target` id.
		 *
		 * `none` renders exactly as `inline` does — the difference is authorial,
		 * not structural, since a bar has never drawn results itself. What it
		 * decides is the derived type-ahead (see `Settings::derive_typeahead()`)
		 * and whether the panel emits a second shortcode at all.
		 *
		 * A THIRD placement — a dedicated results PAGE the bar submits to — is not
		 * part of this plugin. There is no `results_page_id`, no page picker and
		 * no cross-page submit target anywhere in the tree, so a bar always drives
		 * a results region on the page it is placed on.
		 *
		 * ALLOWLIST #1 OF TWO. `Renderer::normalize_config()` holds #2, for direct
		 * callers that never pass through this front door; they move together.
		 */
		const RESULTS_MODES = array( 'none', 'inline' );

		/**
		 * Default results mode: suggestions only, matching the shipped settings
		 * default. A bare bar with no attributes and no saved settings is a search
		 * box with a dropdown — never a bar pointing at a results region nobody
		 * placed.
		 */
		const RESULTS_MODE_DEFAULT = 'none';

		/**
		 * Default facet order when the input is absent entirely (as opposed to
		 * present-but-empty, which yields the same minimal `search`-only bar).
		 *
		 * SHIPPED DEFAULT: search box only, no filters. A fresh install's bare
		 * `[events-calendar-search]` renders a keyword field and nothing else;
		 * filters are something the admin turns on, never something an update
		 * grows onto a page nobody edited.
		 */
		const FACETS_DEFAULT = array( 'search' );

		/**
		 * Default keyword-search SCOPE — the admin-only `search_fields` axis.
		 *
		 * SHIPPED DEFAULT: title only. It is the scope v1.3.6 had, the one that
		 * costs a single `LIKE` against `post_title`, and the one whose results a
		 * visitor can explain to themselves. It is also the ONLY scope this plugin
		 * has: `content` matching and the two-pass venue-name / organizer-name
		 * resolution are not part of it, so the allowlist and the default are the
		 * same one-member list.
		 *
		 * A named constant rather than the inline literal it used to be, so the
		 * shortcode, the settings schema and the Renderer
		 * all read ONE value. The ALLOWLIST is still Criteria's (`search_field_keys()`);
		 * this is only which subset of it ships on.
		 *
		 * @since 2.4.0
		 * @var string[]
		 */
		const SEARCH_FIELDS_DEFAULT = array( 'title' );

		/**
		 * Default result layout.
		 */
		const VIEW_DEFAULT = 'grid';

		/**
		 * Which slice of the calendar an instance starts on.
		 *
		 * Wired here because v1.3.6's `disable-past-events` attribute maps onto it
		 * (`'true'` -> `upcoming`, anything else -> `all`) and, until Stage G, the
		 * render seed hard-coded `upcoming` — so the attribute parsed cleanly and
		 * then did nothing at all. It IS query state, so unlike the display keys
		 * it reaches Criteria; the URL can still overlay it per visit.
		 *
		 * The ALLOWLIST is Criteria's, read through `time_keys()` exactly as
		 * `facets` and `view` are. A second literal copy here would be free to
		 * drift from the one the query layer actually enforces.
		 */
		const TIME_DEFAULT = 'upcoming';

		/**
		 * How many rows this instance's type-ahead dropdown asks for.
		 *
		 * v1.3.6's `show-events` is what this maps from: 1.3.6 had no results grid
		 * at all, only the suggestion dropdown, so the count it exposed was the
		 * suggestion count. Per-instance, but bounded at BOTH ends here and again
		 * at the REST boundary — the value travels to a public route as a query
		 * parameter, and a public route caps its own work regardless of what the
		 * markup asked for.
		 */
		const SUGGEST_LIMIT_DEFAULT = 8;
		const SUGGEST_LIMIT_MIN     = 1;
		const SUGGEST_LIMIT_MAX     = 20;

		/**
		 * WHERE the filter controls live (render-only presentation, never a query
		 * input). TWO meanings:
		 *
		 *   bar_inline  the filters are always visible INSIDE the bar, on the
		 *               keyword row: `[input] [filters] [search]`. When the row
		 *               runs out of room it COLLAPSES behind the in-bar Filters
		 *               toggle — one DOM, one set of controls, a container-query
		 *               state change, NOT a second selectable placement.
		 *   expanded    no triggers at all; every filter's options open at once.
		 *
		 * A placement that puts the filters permanently behind a Filters button,
		 * and one that puts them permanently BELOW the bar, are not part of this
		 * plugin. The collapsed state above is the responsive behaviour of
		 * `bar_inline` and is reachable only by narrowing the container — never by
		 * saving a value.
		 *
		 * The old `below | inbar | button` spellings are DELETED, not mapped: 2.0
		 * has never shipped, so no published shortcode or stored widget can carry
		 * them, and an unknown value simply falls to
		 * `FILTERS_VISIBILITY_DEFAULT` like any other. The one frozen vocabulary
		 * remains v1.3.6's, in `Compat\Legacy_Map`.
		 */
		const FILTERS_VISIBILITIES = array( 'bar_inline', 'expanded' );

		/**
		 * Default placement — every filter's options open at once, below the bar.
		 *
		 * NOT `bar_inline`, and the reason is the narrow-width state rather than
		 * taste: with the keyword input, the triggers and the submit competing for
		 * one row, `bar_inline` spends much of its life collapsed behind the
		 * in-bar Filters toggle. `expanded` has no collapsed state at all, so what
		 * a fresh install renders is the same at every width — and it is the
		 * honest successor to what the old default did (always visible, nothing to
		 * click) without the width pressure.
		 */
		const FILTERS_VISIBILITY_DEFAULT = 'expanded';

		/**
		 * The placement whose filters live INSIDE the bar's keyword row, and the
		 * one whose narrow-width state collapses behind the Filters toggle.
		 */
		const INLINE_VISIBILITY = 'bar_inline';

		/**
		 * Placements that render every filter's options at once instead of behind
		 * a trigger. Also the placement to reach for on a theme whose sticky
		 * header would win a z-index fight with a dropdown panel.
		 */
		const EXPANDED_VISIBILITY = 'expanded';

		/**
		 * HOW a filter trigger looks — one vocabulary for every filter, date
		 * included, taken from the design handoff's trigger table:
		 *
		 *   text    a bare label + caret, no chrome at all
		 *
		 * ONE value, so this is an axis with nothing to choose between and the
		 * settings panel renders NO control for it (see `Settings_Page`). The
		 * chip, form-control and filled-chip trigger treatments are not part of
		 * this plugin. The constant stays a LIST rather than collapsing to a
		 * scalar because every consumer — `pick_enum()`, the schema's `choices`,
		 * REST's `enum` — speaks the list form, and a one-member allowlist is
		 * still the enforcement that rejects a tampered POST.
		 *
		 * Every filter is a TRIGGER + a PANEL; this governs only the trigger.
		 * Because that is the bar's visual language it is GLOBAL — the retired
		 * per-filter `facet_styles` map is gone. What each panel CONTAINS still
		 * differs per filter, but that follows from the filter's own nature and
		 * is never a setting.
		 */
		const FILTER_STYLES = array( 'text' );

		/**
		 * Default trigger style — and the only one.
		 */
		const FILTER_STYLE_DEFAULT = 'text';

		/*
		 * NOTE: `FILTERS_BUTTON_STYLES` / `FILTERS_BUTTON_STYLE_DEFAULT` lived here
		 * — a four-value axis (`icon_text_bordered | icon_text | icon | text`) for
		 * the in-bar "Filters" TOGGLE. THE WHOLE AXIS IS WITHDRAWN, constant,
		 * resolution, shortcode attribute, schema field and panel control alike.
		 *
		 * WHY, because "it was configurable and now it is not" deserves a reason.
		 * The toggle only renders in ONE state: `filters_visibility=bar_inline`
		 * COLLAPSED, which is a container-query state the bar enters below 560px.
		 * The admin preview renders at roughly 740px, so the control sat on the
		 * Filters tab changing nothing an admin could see while configuring it —
		 * a setting whose effect is invisible at the only place it can be set.
		 *
		 * The collapsed button is not gone: it renders with the shipped look
		 * (`ecsa--fbtn-icon-text-bordered`, stamped unconditionally by
		 * `Renderer::design_attrs()`), which is exactly what the default produced.
		 * There is no key to resolve, so no attribute, no stored row and no POST
		 * can reach it.
		 */

		/**
		 * Curated design tokens (theme-proof). TWO bar frames, six button
		 * treatments, and numeric scale/radius bounds. The three colors are free
		 * #hex (empty = fall back to the shipped default, never the theme's).
		 *
		 *   detached  the field carries the border; the button stands apart.
		 *   unified   one frame around the whole group, button inset.
		 *
		 * `capsule` ("Pill") and `soft` ("Filled") are RETIRED, not mapped: the
		 * background colour and the corner-radius slider already express both, so
		 * they were two settings restating knobs the admin already had — and
		 * `capsule` additionally force-set the radius to 999px, overriding that
		 * slider from a different control. Deleted with them.
		 *
		 * CAREFUL: `soft` is ALSO a `filter_style` value (a filled trigger chip)
		 * and that one STAYS. Only the `bar_template` spelling is retired.
		 */
		const BAR_TEMPLATES         = array( 'detached', 'unified' );
		const BAR_TEMPLATE_DEFAULT  = 'unified';
		const BUTTON_STYLES         = array( 'solid', 'solid_icon', 'outline', 'icon', 'text', 'none' );
		/**
		 * SHIPPED DEFAULT: a solid button carrying the magnifier glyph. It reads
		 * as the primary action (solid) without spending bar width on a word that
		 * every visitor already knows the icon for.
		 */
		const BUTTON_STYLE_DEFAULT  = 'solid_icon';

		/**
		 * Field styles whose shell owns the border/background and tucks the button
		 * inside it (the handoff's "inset" shells). `detached` keeps the input and
		 * button as separate siblings. `capsule`/`soft` were the other two; they
		 * are retired with the rest of their vocabulary.
		 */
		const INSET_TEMPLATES = array( 'unified' );
		const CONTROL_SIZE_DEFAULT  = 100;
		const CONTROL_SIZE_MIN      = 80;
		const CONTROL_SIZE_MAX      = 140;
		const CORNER_RADIUS_DEFAULT = 8;
		const CORNER_RADIUS_MAX     = 24;

		/**
		 * Results-card knobs: grid column count, a single card scale, and which
		 * pieces of a card render.
		 */
		const COLUMNS_DEFAULT   = 3;
		const COLUMNS_MIN       = 2;
		const COLUMNS_MAX       = 5;
		const CARD_SIZE_DEFAULT = 100;
		const CARD_FIELDS       = array( 'image', 'title', 'date', 'venue', 'cost' );

		/**
		 * HOW a card's date line is written.
		 *
		 *   site    WordPress's own `date_format` / `time_format` options
		 *   long    August 14, 2026 · 8:00 pm
		 *   medium  Aug 14, 2026 · 8:00 pm
		 *   dmy     14 August 2026 · 20:00
		 *   iso     2026-08-14 · 20:00
		 *
		 * It changes rendered TEXT, so it is a structural key (the server has to
		 * re-render), never a design token the client can re-stamp.
		 */
		const DATE_FORMATS       = array( 'site', 'long', 'medium', 'dmy', 'iso' );
		const DATE_FORMAT_DEFAULT = 'site';

		/**
		 * WHICH card template renders — the design handoff's `template` axis.
		 *
		 *   clean   the date lives on the card's text line
		 *
		 * The alternative treatment — a date pill sitting on the image, with the
		 * text line reduced to the time — is not part of this plugin. The axis
		 * stays declared (one member) rather than being deleted outright, because
		 * `template` is a stored setting, a shortcode attribute and a REST enum,
		 * and a one-member allowlist is what rejects a tampered value.
		 *
		 * Structural, never a design token: a template swap changes ELEMENTS, so
		 * the server has to re-render — a class swap could not produce it.
		 */
		const TEMPLATES        = array( 'clean' );
		const TEMPLATE_DEFAULT = 'clean';

		/**
		 * Translate a raw attribute bag into a Renderer config.
		 *
		 * Every value is allowlisted; unknown keys are discarded, so the config
		 * only ever carries keys the Renderer understands. Empty or invalid
		 * values fall back to the shipped default rather than being clamped to a
		 * boundary (an empty `per_page` means "no intent", not "one result").
		 *
		 * @since 2.0.0
		 * @param array $atts          Raw attribute bag. Recognised keys: `role`,
		 *                             `typeahead`, `results_mode`, `facets`
		 *                             (array or comma-separated string), `view`,
		 *                             `placeholder`, `target`, `per_page`.
		 * @param array $site_defaults Site-wide defaults (from `Settings::instance_defaults()`)
		 *                             used as the middle tier: an absent attribute
		 *                             falls back to the admin's saved setting, then
		 *                             to the shipped constant. Empty = shipped
		 *                             constants only (pure, unchanged legacy
		 *                             behaviour). Kept as a PARAM so this class
		 *                             reads no option and stays unit-testable.
		 * @return array{role:string, typeahead:string, time:string, suggest_limit:int, results_mode:string, facets:string[], view:string, placeholder:string, target:string, per_page:int, search_fields:string[], filters_visibility:string, filter_style:string, columns:int, card_size:int, card_fields:string[], date_format:string, template:string, bar_template:string, accent_color:string, text_color:string, bg_color:string, button_style:string, control_size:int, corner_radius:int}
		 */
		public static function from_atts( array $atts, array $site_defaults = array() ) {
			$role = self::pick_enum( $atts, 'role', $site_defaults, self::ROLES, self::ROLE_DEFAULT );
			// `role` and `target` are per-INSTANCE, never a site default.
			if ( ! in_array( $role, self::ROLES, true ) ) {
				$role = self::ROLE_DEFAULT;
			}

			$results_mode = self::pick_enum( $atts, 'results_mode', $site_defaults, self::RESULTS_MODES, self::RESULTS_MODE_DEFAULT );

			/*
			 * TYPE-AHEAD IS DERIVED FROM *THIS INSTANCE'S* PLACEMENT.
			 *
			 * It used to come through `pick_enum()` like everything else, which
			 * silently made it a three-tier field — attribute, then SITE default,
			 * then constant. But the site default is itself a derivation:
			 * `Settings::instance_defaults()` computes it from the SAVED
			 * `results_mode`. So a shortcode that overrode the placement got a
			 * type-ahead derived from a placement it was not using.
			 *
			 * That produced the one state the whole derivation exists to prevent.
			 * With the site saved as `inline`, `[events-calendar-search
			 * results-mode="none"]` resolved to placement `none` (no results block
			 * of its own) and type-ahead `off` (inherited from the site's `inline`)
			 * — a bar showing neither suggestions NOR results. The docblock on the
			 * retired site-wide toggle claims that is unreachable; it was reachable
			 * from any page whose placement differed from the site's.
			 *
			 * So the site tier is dropped: type-ahead is never stored, only
			 * derived, and it must derive from the placement THIS bar actually has.
			 * An explicit `typeahead` attribute still wins, which is the documented
			 * escape hatch.
			 *
			 * The rule is duplicated from `Settings::derive_typeahead()` rather than
			 * called, because `Instance` deliberately depends on `Criteria` alone and
			 * must resolve on a request where the settings layer has not loaded.
			 * The two spellings are locked equal so they cannot drift.
			 */
			$typeahead_att = isset( $atts['typeahead'] ) ? (string) $atts['typeahead'] : '';
			$typeahead     = in_array( $typeahead_att, self::TYPEAHEADS, true )
				? $typeahead_att
				: ( 'inline' === $results_mode ? 'off' : 'dropdown' );

			$view = self::pick_enum( $atts, 'view', $site_defaults, self::view_keys(), self::VIEW_DEFAULT );

			/*
			 * NOTE: `results_page_id` was resolved here — the page a
			 * `results_mode=page` bar submitted to, accepted as a bare id or a
			 * permalink. There is no `page` placement, so there is no destination
			 * to record: a bar always drives a results region on its own page. A
			 * stale attribute is simply not read, so it can never reach the config.
			 */

			$placeholder = isset( $atts['placeholder'] ) && '' !== $atts['placeholder']
				? sanitize_text_field( (string) $atts['placeholder'] )
				: ( isset( $site_defaults['placeholder'] ) ? sanitize_text_field( (string) $site_defaults['placeholder'] ) : '' );

			$target = isset( $atts['target'] ) ? sanitize_html_class( (string) $atts['target'] ) : '';

			$facets = array_key_exists( 'facets', $atts )
				? self::clean_facets( $atts['facets'] )
				: ( isset( $site_defaults['facets'] ) ? self::clean_facets( $site_defaults['facets'] ) : self::FACETS_DEFAULT );

			$per_page = array_key_exists( 'per_page', $atts ) && '' !== $atts['per_page'] && null !== $atts['per_page']
				? self::clean_per_page( $atts['per_page'] )
				: ( isset( $site_defaults['per_page'] ) ? self::clean_per_page( $site_defaults['per_page'] ) : self::per_page_default() );

			// The admin-only keyword-search scope. An absent OR empty attribute
			// falls to the site default, then to the SHIPPED default (title only) —
			// clean_search_fields() maps an empty set to that same shipped default,
			// never to "all four", so an emptied scope cannot silently widen the
			// search. Mirrors per_page's empty-is-absent handling.
			$search_fields = array_key_exists( 'search_fields', $atts ) && '' !== $atts['search_fields'] && null !== $atts['search_fields']
				? self::clean_search_fields( $atts['search_fields'] )
				: ( isset( $site_defaults['search_fields'] ) ? self::clean_search_fields( $site_defaults['search_fields'] ) : self::SEARCH_FIELDS_DEFAULT );

			/*
			 * WHICH slice of the calendar this instance opens on. Query state, not
			 * presentation, so it DOES enter Criteria — but it is resolved here
			 * with the same three tiers as everything else so a v1.3.6
			 * `disable-past-events` attribute, the admin's saved default and the
			 * shipped constant all meet in one place.
			 */
			$time = self::pick_enum( $atts, 'time', $site_defaults, self::time_keys(), self::TIME_DEFAULT );

			/*
			 * Filter-DISPLAY options (render-only presentation): they change how
			 * facets are laid out, never what is queried, so they do NOT enter
			 * Criteria. Same three-tier resolution as every other field.
			 *
			 * NOTE: a `map_legacy_display()` pass ran here, translating one
			 * v2.0-internal spelling into a later v2.0-internal spelling
			 * (pills/dropdown/compact/custom, inline/toggle). v2.0 NEVER SHIPPED,
			 * so no install can hold those values and the map only made the
			 * vocabulary look bigger than it is. It is deleted, along with its
			 * twins in Settings and Shortcode. The one real legacy surface is
			 * `Compat\Legacy_Map`, which speaks v1.3.6 and nothing else.
			 */
			$filters_visibility = self::pick_enum( $atts, 'filters_visibility', $site_defaults, self::FILTERS_VISIBILITIES, self::FILTERS_VISIBILITY_DEFAULT );
			/*
			 * NOTE: `filter_columns` was resolved here — an N-column grid for the
			 * filter row. RETIRED. Filters now FLOW one after another with tight
			 * spacing, which is what a row of variable-width triggers actually
			 * wants; forcing them onto equal tracks left long labels crushed and
			 * short ones stranded in half-empty cells. A stale attribute is simply
			 * not read, so it can never reach the config.
			 */
			$filter_style       = self::pick_enum( $atts, 'filter_style', $site_defaults, self::FILTER_STYLES, self::FILTER_STYLE_DEFAULT );
			/*
			 * NOTE: `filters_button_style` was resolved here — HOW the in-bar
			 * Filters TOGGLE looks. WITHDRAWN: the toggle only exists in
			 * `bar_inline`'s COLLAPSED state (below 560px), which the ~740px admin
			 * preview never enters, so the control changed nothing visible where it
			 * was set. The button now renders with the shipped look, stamped
			 * unconditionally by `Renderer::design_attrs()`. A stale attribute or a
			 * stale site default is simply not read, so it can never reach the
			 * config.
			 */
			// NOTE: `groups_collapsed` was resolved here. It is RETIRED — see
			// Settings::schema(). A stale attribute (a published shortcode, a
			// stored widget) is simply not read, so it can never
			// reach the config and nothing downstream can act on it.
			//
			// NOTE: `location_field` was resolved here — an in-bar place input
			// matching city / region / country against venue meta. That control,
			// its `/places` lookup and its whole query path are not part of this
			// plugin, so there is no key to resolve and no `location` criteria for
			// one to feed. A stale attribute is simply not read.

			// Curated, theme-proof design tokens. Colors default to '' (= inherit
			// our neutral default, never the theme's); the renderer turns these
			// into CSS custom properties + modifier classes on the .ecsa wrapper.
			$bar_template  = self::pick_enum( $atts, 'bar_template', $site_defaults, self::BAR_TEMPLATES, self::BAR_TEMPLATE_DEFAULT );
			$accent_color  = self::clean_color( self::pick_raw( $atts, 'accent_color', $site_defaults, '' ), false );
			$text_color    = self::clean_color( self::pick_raw( $atts, 'text_color', $site_defaults, '' ), false );
			$bg_color      = self::clean_color( self::pick_raw( $atts, 'bg_color', $site_defaults, '' ), true );
			$button_style  = self::pick_enum( $atts, 'button_style', $site_defaults, self::BUTTON_STYLES, self::BUTTON_STYLE_DEFAULT );
			$control_size  = self::clean_scale( self::pick_raw( $atts, 'control_size', $site_defaults, self::CONTROL_SIZE_DEFAULT ), self::CONTROL_SIZE_DEFAULT );
			$corner_radius = self::clean_radius( self::pick_raw( $atts, 'corner_radius', $site_defaults, self::CORNER_RADIUS_DEFAULT ) );

			/*
			 * THE SIZING GATE. Resolved here rather than in `design_attrs()` so the
			 * renderer, the REST preview route, the events-page injection and the
			 * settings panel all receive already-neutralised numbers — one decision,
			 * made once, instead of four callers each remembering to make it.
			 */
			$design_sizing = self::pick_enum( $atts, 'design_sizing', $site_defaults, array( 'off', 'on' ), 'off' );

			/*
			 * A SHORTCODE THAT NAMES A NUMBER MEANS IT.
			 *
			 * Any tag supplying one of the sliders turns sizing on FOR THAT INSTANCE
			 * whatever the site setting says. Two things depend on this: a published
			 * `control-size="130"` keeps rendering as written, and `Compat\Legacy_Map`
			 * — which turns v1.3.6's `layout="large"` into a `control_size` value on
			 * this very bag — keeps working without a second gate of its own. Without
			 * it, every v1.x shortcode on every upgrading site would silently lose its
			 * size the moment this feature shipped.
			 *
			 * The test is on the ATTRIBUTE BAG, never on the resolved value: a
			 * resolved 100 is indistinguishable from a default, and reading it that
			 * way would make an explicit `control-size="100"` mean "off".
			 *
			 * AN EXPLICIT `design_sizing` BEATS THIS. The inference exists for callers
			 * that predate the gate and cannot have an opinion about it; a caller that
			 * states one is not guessed at. This is load-bearing rather than tidy: the
			 * settings preview POSTs the whole config through this method, so
			 * `control_size` is ALWAYS present there. Without the guard the opt-in
			 * fires on every preview request and the panel renders a scaled bar while
			 * the control reads off — the preview lying in the opposite direction to
			 * the one this feature was built to fix.
			 */
			$inferred_sizing = false;

			/*
			 * ONLY EVER AN INFERENCE. The `'on' !== $design_sizing` test is what
			 * makes that true: without it this fired on a site whose gate was
			 * ALREADY on, marking the resolution inferred and so pinning the two
			 * sliders the shortcode did not name — discarding settings the admin
			 * had deliberately chosen. The classic widget made that reachable on
			 * every install: it always supplies `layout`, which `Legacy_Map` turns
			 * into a `control_size`, so a sidebar bar would have painted shipped
			 * corners beside a shortcode bar painting the admin's.
			 */
			if ( 'on' !== $design_sizing
				&& ! isset( $atts['design_sizing'] )
				&& ( isset( $atts['control_size'] ) || isset( $atts['corner_radius'] ) || isset( $atts['card_size'] ) )
			) {
				$design_sizing   = 'on';
				$inferred_sizing = true;
			}

			if ( 'on' !== $design_sizing ) {
				$control_size  = self::CONTROL_SIZE_DEFAULT;
				$corner_radius = self::CORNER_RADIUS_DEFAULT;
			} elseif ( $inferred_sizing ) {
				/*
				 * AN INFERENCE EXTENDS ONLY TO WHAT WAS NAMED.
				 *
				 * The tag said one number, so it gets one number. Every slider it
				 * did NOT name falls to the shipped constant rather than to the
				 * admin's stored value — because the stored value belongs to a
				 * control the admin switched OFF, and honouring it here would let a
				 * shortcode re-enable a setting its author never mentioned and its
				 * site owner had declined.
				 *
				 * Concretely, without this a tag carrying `corner-radius="8"` — the
				 * SHIPPED DEFAULT, asking for nothing unusual — would have pulled a
				 * stored 130%% bar scale and 120%% cards onto the page. The v1.3.6
				 * path was worse because nothing was typed at all: `Legacy_Map` turns
				 * `layout="large"` into a `control_size`, so every upgraded legacy
				 * shortcode would have inherited the whole stored set.
				 *
				 * The named slider is read from `$atts` and so is unaffected; only
				 * the silent ones are pinned. An EXPLICIT `design_sizing` never
				 * reaches this branch, so a site that really wants all three keeps
				 * all three.
				 */
				if ( ! isset( $atts['control_size'] ) ) {
					$control_size = self::CONTROL_SIZE_DEFAULT;
				}
				if ( ! isset( $atts['corner_radius'] ) ) {
					$corner_radius = self::CORNER_RADIUS_DEFAULT;
				}
			}

			// Results-card knobs.
			$columns     = self::clean_columns( self::pick_raw( $atts, 'columns', $site_defaults, self::COLUMNS_DEFAULT ) );
			// Sizing off, or inferred-on without this slider being named: shipped
			// value. See the inference note above.
			$card_size   = ( 'on' === $design_sizing && ( ! $inferred_sizing || isset( $atts['card_size'] ) ) )
				? self::clean_scale( self::pick_raw( $atts, 'card_size', $site_defaults, self::CARD_SIZE_DEFAULT ), self::CARD_SIZE_DEFAULT )
				: self::CARD_SIZE_DEFAULT;
			$card_fields = self::clean_card_fields(
				array_key_exists( 'card_fields', $atts ) && '' !== $atts['card_fields'] && null !== $atts['card_fields']
					? $atts['card_fields']
					: ( isset( $site_defaults['card_fields'] ) ? $site_defaults['card_fields'] : self::CARD_FIELDS )
			);
			// How a card's date line is written. Three-tier like every other enum.
			$date_format = self::pick_enum( $atts, 'date_format', $site_defaults, self::DATE_FORMATS, self::DATE_FORMAT_DEFAULT );
			// `clean` is the only value free ships; `modern` and its date pill are
			// Pro. Still resolved three-tier like every other enum, so an inherited
			// or attribute-supplied `modern` is REJECTED by the allowlist rather
			// than silently honoured.
			$template    = self::pick_enum( $atts, 'template', $site_defaults, self::TEMPLATES, self::TEMPLATE_DEFAULT );

			// How many rows this bar's type-ahead asks for. Clamped, never trusted.
			$suggest_limit = self::clean_suggest_limit( self::pick_raw( $atts, 'suggest_limit', $site_defaults, self::SUGGEST_LIMIT_DEFAULT ) );

			return array(
				'role'                 => $role,
				'typeahead'            => $typeahead,
				'time'                 => $time,
				'suggest_limit'        => $suggest_limit,
				'results_mode'         => $results_mode,
				'facets'               => $facets,
				'view'                 => $view,
				'placeholder'          => $placeholder,
				'target'               => $target,
				'per_page'             => $per_page,
				'search_fields'        => $search_fields,
				'filters_visibility'   => $filters_visibility,
				'filter_style'         => $filter_style,
				'columns'              => $columns,
				'card_size'            => $card_size,
				'card_fields'          => $card_fields,
				'date_format'          => $date_format,
				'template'             => $template,
				'bar_template'         => $bar_template,
				'accent_color'         => $accent_color,
				'text_color'           => $text_color,
				'bg_color'             => $bg_color,
				'button_style'         => $button_style,
				'design_sizing'        => $design_sizing,
				'control_size'         => $control_size,
				'corner_radius'        => $corner_radius,
			);
		}

		/**
		 * Clamp the per-instance type-ahead suggestion count.
		 *
		 * Its own helper rather than `clean_scale()`: this is a ROW COUNT with a
		 * hard public-route ceiling, not a percent scale, and folding it into a
		 * shared clamp would quietly hand it the 80-140 design bounds.
		 *
		 * @since 2.3.0
		 * @param mixed $value Raw limit.
		 * @return int
		 */
		private static function clean_suggest_limit( $value ) {
			if ( ! is_numeric( $value ) ) {
				return self::SUGGEST_LIMIT_DEFAULT;
			}

			return max( self::SUGGEST_LIMIT_MIN, min( self::SUGGEST_LIMIT_MAX, (int) $value ) );
		}

		/**
		 * Read a raw three-tier value: attribute (when present and non-empty),
		 * else the site default, else the shipped fallback.
		 *
		 * Shared by the numeric/color tokens, whose own `clean_*` helper does the
		 * validating — this only picks WHICH tier's raw value to validate. The
		 * `array_key_exists` + '' test is what makes an absent OR blank attribute
		 * inherit rather than clamp to a boundary.
		 *
		 * @since 2.1.0
		 * @param array  $atts          Attribute bag.
		 * @param string $key           Field key.
		 * @param array  $site_defaults Site defaults.
		 * @param mixed  $fallback      Shipped default.
		 * @return mixed
		 */
		private static function pick_raw( array $atts, $key, array $site_defaults, $fallback ) {
			if ( array_key_exists( $key, $atts ) && '' !== $atts[ $key ] && null !== $atts[ $key ] ) {
				return $atts[ $key ];
			}
			if ( isset( $site_defaults[ $key ] ) && '' !== $site_defaults[ $key ] ) {
				return $site_defaults[ $key ];
			}
			return $fallback;
		}

		/**
		 * Clamp a percent scale (control size / card size) to its bounds.
		 *
		 * @since 2.1.0
		 * @param mixed $value   Raw scale.
		 * @param int   $default Shipped default, used when non-numeric.
		 * @return int
		 */
		private static function clean_scale( $value, $default ) {
			if ( ! is_numeric( $value ) ) {
				return (int) $default;
			}
			return max( self::CONTROL_SIZE_MIN, min( self::CONTROL_SIZE_MAX, (int) $value ) );
		}

		/**
		 * Clamp the grid column count.
		 *
		 * @since 2.1.0
		 * @param mixed $value Raw column count.
		 * @return int
		 */
		private static function clean_columns( $value ) {
			if ( ! is_numeric( $value ) ) {
				return self::COLUMNS_DEFAULT;
			}
			return max( self::COLUMNS_MIN, min( self::COLUMNS_MAX, (int) $value ) );
		}

		/*
		 * NOTE: `clean_filter_columns()` lived here, clamping the retired
		 * `filter_columns` axis to 1-4. Gone with the setting.
		 */

		/**
		 * Sanitize the result-card field set. Accepts an array or a comma string;
		 * an empty selection means "show everything", so a card is never blank.
		 *
		 * @since 2.1.0
		 * @param mixed $value Raw set.
		 * @return string[]
		 */
		private static function clean_card_fields( $value ) {
			if ( is_string( $value ) ) {
				$value = explode( ',', $value );
			}
			if ( ! is_array( $value ) ) {
				return self::CARD_FIELDS;
			}

			$out = array();
			foreach ( $value as $field ) {
				$field = is_scalar( $field ) ? sanitize_key( trim( (string) $field ) ) : '';
				if ( in_array( $field, self::CARD_FIELDS, true ) && ! in_array( $field, $out, true ) ) {
					$out[] = $field;
				}
			}

			return $out ? $out : self::CARD_FIELDS;
		}

		/**
		 * Resolve an enum field across the three tiers: attribute, site default,
		 * shipped constant.
		 *
		 * @since 2.0.0
		 * @param array    $atts          Attribute bag.
		 * @param string   $key           Field key.
		 * @param array    $site_defaults Site defaults.
		 * @param string[] $allowed       Allowed values.
		 * @param string   $fallback      Shipped default.
		 * @return string
		 */
		private static function pick_enum( array $atts, $key, array $site_defaults, array $allowed, $fallback ) {
			$att = isset( $atts[ $key ] ) ? (string) $atts[ $key ] : '';
			if ( in_array( $att, $allowed, true ) ) {
				return $att;
			}
			if ( isset( $site_defaults[ $key ] ) && in_array( $site_defaults[ $key ], $allowed, true ) ) {
				return $site_defaults[ $key ];
			}
			return $fallback;
		}

		/*
		 * NOTE: `pick_bool()` and its `to_bool()` coercion lived here, resolving a
		 * boolean field across the three tiers. `location_field` was the only
		 * boolean in the config, so both went with it. A future boolean must
		 * re-add them together — and must keep `to_bool()` agreeing with
		 * `Settings::sanitize_field()`'s `bool` branch, or a value saved by the
		 * panel would not round-trip through a shortcode.
		 */

		/**
		 * Normalize a facet list against the `Criteria` allowlist.
		 *
		 * Accepts an array or a comma-separated string
		 * (shortcode). Order is preserved and duplicates are dropped, so the bar
		 * renders facets in the author's chosen order. A `null` value means the
		 * key was absent, which restores the full default order; a present but
		 * empty value collapses to the minimal `search`-only bar so the input
		 * always resolves to at least one facet.
		 *
		 * @since 2.0.0
		 * @param mixed $value Raw facet value, or null when absent.
		 * @return string[] Allowlisted facet keys, never empty.
		 */
		private static function clean_facets( $value ) {
			if ( null === $value ) {
				return self::FACETS_DEFAULT;
			}

			if ( is_string( $value ) ) {
				$value = explode( ',', $value );
			}

			if ( ! is_array( $value ) ) {
				return array( 'search' );
			}

			$out = array();
			foreach ( $value as $facet ) {
				if ( ! is_scalar( $facet ) ) {
					continue;
				}

				$facet = sanitize_key( (string) $facet );

				if ( in_array( $facet, self::facet_keys(), true ) && ! in_array( $facet, $out, true ) ) {
					$out[] = $facet;
				}
			}

			return $out ? $out : array( 'search' );
		}

		/**
		 * Normalize the admin-only keyword-search scope against the allowlist.
		 *
		 * Mirrors `clean_facets()`: accepts an array or a
		 * comma-separated string (shortcode), allowlists each member against
		 * `Criteria::SEARCH_FIELDS`, and emits in that fixed order so two spellings
		 * of one scope stay byte-identical.
		 *
		 * AN EMPTY OR ALL-INVALID VALUE RESOLVES TO THE SHIPPED DEFAULT
		 * (`SEARCH_FIELDS_DEFAULT` = title), never to "every field". A keyword box
		 * that matches nothing is still a silent dead end, so the fallback must be
		 * non-empty — but falling back to ALL FOUR would mean an emptied scope
		 * silently WIDENED the search past what the admin last chose, and past what
		 * the plugin ships. Title-only is the narrow, explicable answer, and it is
		 * the same answer `Settings::schema()` records for an emptied checkbox set.
		 *
		 * @since 2.0.0
		 * @param mixed $value Raw scope value (array or comma-separated string).
		 * @return string[] A non-empty subset of the search-field allowlist.
		 */
		private static function clean_search_fields( $value ) {
			$allowed = self::search_field_keys();

			if ( is_string( $value ) ) {
				$value = explode( ',', $value );
			}

			if ( ! is_array( $value ) ) {
				return self::SEARCH_FIELDS_DEFAULT;
			}

			$wanted = array();
			foreach ( $value as $field ) {
				if ( ! is_scalar( $field ) ) {
					continue;
				}

				$field = sanitize_key( (string) $field );

				if ( in_array( $field, $allowed, true ) ) {
					$wanted[ $field ] = true;
				}
			}

			$out = array();
			foreach ( $allowed as $field ) {
				if ( isset( $wanted[ $field ] ) ) {
					$out[] = $field;
				}
			}

			return $out ? $out : self::SEARCH_FIELDS_DEFAULT;
		}

		/**
		 * Clamp a `per_page` value into the Criteria bounds.
		 *
		 * An absent, empty, non-numeric or non-positive value carries no user
		 * intent and falls back to the default page size rather than being
		 * clamped up to 1, which would silently mean "one result per page".
		 *
		 * @since 2.0.0
		 * @param mixed $value Raw value, or null when absent.
		 * @return int
		 */
		private static function clean_per_page( $value ) {
			if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
				return self::per_page_default();
			}

			$n = (int) $value;

			if ( $n < 1 ) {
				return self::per_page_default();
			}

			return min( self::per_page_max(), $n );
		}

		/*
		 * NOTE: `clean_page_id()` lived here, normalizing a results-page reference
		 * (a bare id or a permalink, via `url_to_postid()`) to a post id. It had
		 * exactly one caller — the retired `results_page_id` field — and is gone
		 * with it.
		 */

		/**
		 * Normalize a design color: empty (= inherit our default), an optional
		 * `transparent`, or a strict `#hex`. Never emits arbitrary CSS.
		 *
		 * @since 2.0.0
		 * @param mixed $value           Raw color.
		 * @param bool  $allow_transparent Whether 'transparent' is permitted.
		 * @return string '' | 'transparent' | '#rrggbb'
		 */
		private static function clean_color( $value, $allow_transparent = false ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( '' === $value ) {
				return '';
			}

			if ( $allow_transparent && 'transparent' === strtolower( $value ) ) {
				return 'transparent';
			}

			if ( function_exists( 'sanitize_hex_color' ) ) {
				$hex = sanitize_hex_color( $value );

				return is_string( $hex ) ? $hex : '';
			}

			return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : '';
		}

		/**
		 * Clamp the corner radius to its bound.
		 *
		 * @since 2.0.0
		 * @param mixed $value Raw radius.
		 * @return int
		 */
		private static function clean_radius( $value ) {
			if ( ! is_numeric( $value ) ) {
				return self::CORNER_RADIUS_DEFAULT;
			}

			return max( 0, min( self::CORNER_RADIUS_MAX, (int) $value ) );
		}

		/**
		 * The facet allowlist, degrading to a literal when Criteria is absent.
		 *
		 * THE LITERAL MUST EQUAL `Criteria::FACET_KEYS`. It is one of THREE copies
		 * of the registry (here, `Settings::facet_keys()`, and the constant
		 * itself); all three are locked EQUAL rather than each
		 * individually correct, because a fallback that offers a key the registry
		 * does not have is a key that resolves and then renders nothing.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		private static function facet_keys() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return Criteria::FACET_KEYS;
			}

			return array( 'search', 'date' );
		}

		/**
		 * The search-field ALLOWLIST (every scope an admin may pick), degrading to
		 * a literal when Criteria is absent. Which subset SHIPS ON is a separate
		 * question, answered once by `SEARCH_FIELDS_DEFAULT`.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		private static function search_field_keys() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return Criteria::SEARCH_FIELDS;
			}

			return array( 'title' );
		}

		/**
		 * The time-window allowlist, degrading to a literal when Criteria is
		 * absent.
		 *
		 * @since 2.3.0
		 * @return string[]
		 */
		private static function time_keys() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return Criteria::TIMES;
			}

			return array( 'upcoming', 'past', 'all' );
		}

		/**
		 * The result-layout allowlist, degrading to a literal when Criteria is
		 * absent.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		private static function view_keys() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return Criteria::VIEWS;
			}

			return array( 'grid', 'list' );
		}

		/**
		 * The default page size, degrading to a literal when Criteria is absent.
		 *
		 * @since 2.0.0
		 * @return int
		 */
		private static function per_page_default() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return Criteria::PER_PAGE_DEFAULT;
			}

			return 12;
		}

		/**
		 * The page-size ceiling, degrading to a literal when Criteria is absent.
		 *
		 * @since 2.0.0
		 * @return int
		 */
		private static function per_page_max() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return Criteria::PER_PAGE_MAX;
			}

			return 50;
		}
	}
}
