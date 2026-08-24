<?php
/**
 * The `[events-calendar-search]` shortcode dispatcher.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CoolPlugins\EventsSearch\Shortcode\Shortcode' ) ) {

	/**
	 * Registers the shortcode tag and renders it through the v2 engine.
	 *
	 * v2 is the only render engine — the v1.3.6 render path has been deleted.
	 * Legacy shortcode attributes are still accepted and mapped to v2 config
	 * defaults through `Compat\Legacy_Map`, so an old `[events-calendar-search
	 * placeholder="…" show-events="6"]` in published content keeps working.
	 *
	 * @since 2.0.0
	 */
	final class Shortcode {

		/**
		 * The bar shortcode tag. Immutable — it is in published content.
		 */
		const TAG = 'events-calendar-search';

		/**
		 * The standalone results-region shortcode tag.
		 *
		 * Lets an author place a bar in one region (a header) and its results in
		 * another (the page body) — the two share URL state through the id in the
		 * bar's `target` attribute. New in v2, so there is no legacy behaviour to
		 * preserve for this tag.
		 */
		const TAG_RESULTS = 'events-calendar-search-results';

		/**
		 * Register the shortcodes.
		 *
		 * Called from `Plugin::boot_render_path()` AFTER the legacy render
		 * files are required, so this registration owns the tag —
		 * `add_shortcode()` overwrites an existing handler for the same tag,
		 * and last registration wins.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init() {
			add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
			add_shortcode( self::TAG_RESULTS, array( __CLASS__, 'render_results' ) );
		}

		/**
		 * Shortcode callback.
		 *
		 * @since 2.0.0
		 * @param mixed  $atts    Raw shortcode attributes; WordPress passes an
		 *                        empty string when the tag carries none.
		 * @param string $content Enclosed content. Unused — the tag is
		 *                        self-closing.
		 * @return string Rendered markup, or an empty string. Never echoes.
		 */
		public static function render( $atts, $content = null ) {
			unset( $content );

			$atts = is_array( $atts ) ? $atts : array();

			/*
			 * v1.3.6 passed 'ecsa' as the third argument while the tag is
			 * 'events-calendar-search', so the documented `shortcode_atts_ecsa`
			 * filter never matched anything a user could actually hook. The
			 * tag is the correct value; the filter is now `shortcode_atts_
			 * events-calendar-search`.
			 *
			 * The v2 attributes are ADDED to — never in place of — the legacy
			 * ones: an existing `[events-calendar-search placeholder="…"
			 * show-events="6"]` in published content keeps meaning exactly what
			 * it did, and its values become the placeholder/per_page defaults for
			 * the v2 bar (see `v2_config()`).
			 */
			$atts = shortcode_atts(
				array(
					/*
					 * v1.3.6 ATTRIBUTES. These are in ~3,000 installs' published
					 * content, so all five stay declared forever. Four of them now
					 * actually reach the config through `Compat\Legacy_Map` —
					 * `placeholder`, `show-events` (page size AND the type-ahead
					 * row count), `disable-past-events` (-> `time`) and `layout`
					 * (-> `control_size`). Until Stage G the last three were
					 * parsed and thrown away, so `layout="large"` did nothing.
					 *
					 * `content-type` is DECLARED AND IGNORED: the
					 * basic/advance card split is out of scope, but a published
					 * shortcode carrying it must not start behaving as though it
					 * had an unknown attribute.
					 */
					'placeholder'          => '',
					'show-events'          => '',
					'disable-past-events'  => '',
					'content-type'         => '',
					'layout'               => '',
					/*
					 * v2 attributes.
					 *
					 * `mode` is DECLARED AND IGNORED, exactly like `content-type`
					 * above. THE SEARCH BAR SHORTCODE NEVER RENDERS RESULTS — see
					 * `role()` — so there is no mode left to choose, and the
					 * retired `bar-results` has no mapping (2.0 never shipped, so
					 * nothing published can carry it). It stays declared purely so
					 * a shortcode the settings panel generated with `mode="bar"`
					 * keeps rendering a normal bar rather than tripping over an
					 * attribute the tag no longer recognises.
					 */
					'mode'                 => '',
					/*
					 * Type-ahead. EMPTY BY DEFAULT — deliberately, and this is a
					 * change: it used to declare `dropdown`, which meant every bar
					 * carried an explicit value and the site-wide setting could
					 * never reach a shortcode at all. That was survivable while the
					 * site value and the shortcode default agreed; it is not now
					 * that the site value is DERIVED from where results appear
					 * (`Settings::derive_typeahead()`), because the derivation would
					 * have been silently overridden on every published bar.
					 *
					 * An author who writes `typeahead="off"` or `typeahead="dropdown"`
					 * still wins outright — `Instance::pick_enum()` prefers a present
					 * attribute over the site default — so a per-placement override
					 * survives this change intact. Absent means inherit.
					 */
					'typeahead'            => '',
					// Empty default => a bare shortcode INHERITS the admin's
					// Site-Defaults facets (three-tier); v2_config() only adds
					// `facets` to the bag when non-empty.
					'facets'               => '',
					'search-fields'        => '',
					'view'                 => '',
					'per-page'             => '',
					'target'               => '',
					// Results destination: `results-mode` none|inline — suggestions
					// only, or a results block on this page. Absent => inherit the
					// site default.
					//
					// NOTE: a `results-page` attribute (the page id/permalink a
					// `page`-mode bar submitted to) was declared beside it. There is
					// no page placement, so it is not declared even as an ignored
					// attribute: `shortcode_atts()` drops unknown keys silently, so
					// a stale one can never reach the config.
					'results-mode'         => '',
					// Filter-display styles (per-instance override of the
					// site-wide settings; absent => inherit the admin's choice).
					// WHERE the filters live — bar_inline|expanded.
					//   bar_inline  filters visible inside the bar's keyword row;
					//               they fold behind the in-bar Filters toggle when
					//               the row runs out of room.
					//   expanded    every filter's options at once, no triggers.
					// The old below|inbar|button spellings are DELETED, not mapped,
					// and so are bar_button and under_bar.
					'filters-visibility'   => '',
					// NOTE: `filter-columns` was declared here (1-4 tracks for the
					// filter row). RETIRED — filters flow one after another with
					// tight spacing now, so there is no count to accept. It is not
					// even declared as an ignored attribute: `shortcode_atts()`
					// drops unknown keys silently, and a stale one can therefore
					// never reach the config.
					// How each filter TRIGGER looks — text. Every filter is a
					// trigger plus its own panel, so this is the bar's visual
					// language and applies to all of them. One value today, so the
					// settings panel offers no control for it; the attribute stays
					// declared because it is still allowlisted per instance.
					'filter-style'         => '',
					// NOTE: a `filters-button-style` attribute chose between four
					// looks for the in-bar "Filters" TOGGLE. WITHDRAWN: that button
					// only renders in `bar_inline`'s COLLAPSED state, below 560px,
					// which the admin preview never reaches — so the setting changed
					// nothing visible where it was configured. The button keeps the
					// shipped look. The attribute is not declared even as an ignored
					// one: `shortcode_atts()` drops unknown keys silently, so a
					// published shortcode still carrying it can never reach the
					// config.
					// NOTE: a `location-field` attribute toggled a searchable place
					// box inside the bar shell. That control is not part of this
					// plugin, so the attribute is not declared and a stale one is
					// dropped by shortcode_atts().
					// Curated design tokens (per-placement override of the site
					// "house style"; absent => inherit the admin's default).
					'bar-template'         => '',
					'accent-color'         => '',
					'text-color'           => '',
					'bg-color'             => '',
					'button-style'         => '',
					'control-size'         => '',
					'corner-radius'        => '',
					// Results-card knobs.
					'columns'              => '',
					'card-size'            => '',
					'card-fields'          => '',
					// HOW a card's date line is written —
					// site|long|medium|dmy|iso. `site` (the default) uses
					// WordPress's own date/time formats. Empty => inherit the
					// admin's choice.
					'date-format'          => '',
					// WHICH card template — clean. Deliberately NOT `bar-template`,
					// which frames the SEARCH BOX; this one shapes a result CARD.
					'template'             => '',
					/*
					 * RETIRED, accepted and ignored. Both are declared here purely
					 * so an old `[events-calendar-search …]` in published content
					 * keeps rendering a normal bar instead of tripping over an
					 * unknown attribute. Neither is read by `v2_config()`.
					 *
					 * `facet-styles` carried a per-filter
					 * "filter:style,filter:style" map back when `filter-style`
					 * described how a filter's OPTIONS rendered. It now describes
					 * the TRIGGER, which is the bar's visual language and therefore
					 * global, so the per-filter map has no meaning left to express.
					 *
					 * `groups-collapsed` wrapped each filter group in its own
					 * <details> disclosure. Under the trigger + panel model a
					 * filter's options are already behind a disclosure, so the
					 * setting had become a control that changed nothing.
					 */
					'facet-styles'         => '',
					'groups-collapsed'     => '',
				),
				$atts,
				self::TAG
			);

			if ( ! self::can_render() ) {
				return '';
			}

			return self::render_v2( $atts );
		}

		/**
		 * Standalone results-region shortcode callback.
		 *
		 * Renders only a results region (role `results`), which shares URL state
		 * with a bar placed elsewhere on the page via the `target` id. Always the
		 * v2 engine — this tag never existed in 1.3.6, so there is no legacy path.
		 *
		 * @since 2.0.0
		 * @param mixed  $atts    Raw shortcode attributes.
		 * @param string $content Enclosed content. Unused.
		 * @return string Rendered markup, or an empty string. Never echoes.
		 */
		public static function render_results( $atts, $content = null ) {
			unset( $content );

			$atts = is_array( $atts ) ? $atts : array();

			$atts = shortcode_atts(
				array(
					'target'        => '',
					'view'          => '',
					'per-page'      => '',
					// Empty => inherit the admin's Site-Defaults facets (three-tier),
					// like the bar shortcode.
					'facets'        => '',
					'search-fields' => '',
					// Results-card knobs.
					'columns'       => '',
					'card-size'     => '',
					'card-fields'   => '',
					'date-format'   => '',
					// The card template. A card knob, so it is offered on the
					// standalone results tag too.
					'template'      => '',
					/*
					 * COLOUR AND GEOMETRY — the same five tokens the bar tag takes,
					 * with the same empty-means-inherit rule and the same validation
					 * (both tags funnel through the one `$design_atts` map in
					 * `v2_config()` and then through `Instance`, so neither can accept
					 * a value the other rejects).
					 *
					 * These were deliberately WITHHELD, on the reasoning that a
					 * detached results region should inherit the site house style so a
					 * page never carries two conflicting looks. The goal was right and
					 * the mechanism was wrong: `shortcode_atts()` DROPS an undeclared
					 * attribute silently, so `[events-calendar-search-results
					 * accent-color="#b91c1c"]` was not "inherited", it was discarded
					 * with no feedback — and the moment the generator diffed a palette
					 * onto the BAR (which it does for any colour that differs from the
					 * saved row) the pair repainted by halves.
					 *
					 * Inheritance is now expressed by the ABSENCE of the attribute,
					 * which is what "inherit" already means everywhere else in this
					 * file: absent => the site palette, byte-identical to before;
					 * present => this placement overrides. The generator only ever
					 * emits one when the panel differs from the saved row, so a page
					 * still cannot grow two looks by accident.
					 *
					 * `bar-template` and `button-style` stay off this tag on their own
					 * merits, not by inheritance policy: one frames the SEARCH BOX and
					 * the other paints the SEARCH SUBMIT, and a results region has
					 * neither to paint.
					 */
					'accent-color'  => '',
					'text-color'    => '',
					'bg-color'      => '',
					'control-size'  => '',
					'corner-radius' => '',
				),
				$atts,
				self::TAG_RESULTS
			);

			if ( ! self::can_render() ) {
				return '';
			}

			return self::render_v2( $atts, 'results' );
		}

		/**
		 * Render through the v2 engine.
		 *
		 * The Renderer call is `class_exists`-guarded so a partially deployed tree
		 * (this file present, the renderer not yet uploaded) degrades to an empty
		 * string instead of a fatal. Assets are enqueued only here,
		 * inside the render, so a page with no bar ships none of them.
		 *
		 * @since 2.0.0
		 * @param array  $atts          Merged shortcode attributes.
		 * @param string $role_override Force a role (the results shortcode passes
		 *                              `results`); '' reads the `mode` attribute.
		 * @return string
		 */
		private static function render_v2( array $atts, $role_override = '' ) {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Render\Renderer' ) ) {
				return '';
			}

			if ( class_exists( 'CoolPlugins\EventsSearch\Render\Assets' ) ) {
				\CoolPlugins\EventsSearch\Render\Assets::ensure_v2_localized();
			}

			$config = self::v2_config( $atts, $role_override );

			return (string) \CoolPlugins\EventsSearch\Render\Renderer::instance( $config );
		}

		/**
		 * Build the Renderer config from merged shortcode attributes.
		 *
		 * v2 attributes take precedence; the v1.3.6 attributes supply DEFAULTS
		 * through `Legacy_Map::from_shortcode()`, so an old shortcode keeps
		 * working — and, since Stage G, actually keeps working in full: the map
		 * has always computed `time`, a size and a card density, but only
		 * `placeholder` and `per_page` were ever read off it, so `layout="large"`
		 * and `disable-past-events="true"` silently did nothing.
		 *
		 * The map is SPARSE: it emits only the keys whose 1.3.6 attribute was
		 * actually supplied. That is what lets an absent attribute inherit the
		 * admin's saved default instead of being pinned to this class's fallback.
		 *
		 * Allowlisting each value against the Criteria constants is delegated to
		 * `Render\Instance` — the shared front door every placement surface
		 * funnels through so they can never drift into
		 * different configs.
		 *
		 * @since 2.0.0
		 * @param array  $atts          Merged shortcode attributes.
		 * @param string $role_override Force a role, or '' to read the `mode` attr.
		 * @return array Renderer config.
		 */
		private static function v2_config( array $atts, $role_override = '' ) {
			$legacy = array();

			if ( class_exists( 'CoolPlugins\EventsSearch\Compat\Legacy_Map' ) ) {
				$legacy = \CoolPlugins\EventsSearch\Compat\Legacy_Map::from_shortcode( $atts );
			}

			/**
			 * Read a v1.3.6-derived default for a v2 key.
			 *
			 * @param string $key v2 config key.
			 * @return string '' when the legacy attribute was absent.
			 */
			$legacy_val = static function ( $key ) use ( $legacy ) {
				return isset( $legacy[ $key ] ) ? (string) $legacy[ $key ] : '';
			};

			$placeholder = self::att( $atts, 'placeholder' );
			if ( '' === $placeholder ) {
				$placeholder = $legacy_val( 'placeholder' );
			}

			$per_page = self::att( $atts, 'per-page' );
			if ( '' === $per_page ) {
				$per_page = $legacy_val( 'per_page' );
			}

			$bag = array(
				'role'          => self::role( $role_override ),
				// '' => not set, so Instance falls to the site default (derived from
				// the placement) and then to the shipped constant. Never given a
				// non-empty fallback here: that is what used to pin every bar to
				// `dropdown` and make the site setting unreachable.
				'typeahead'     => self::att( $atts, 'typeahead' ),
				// `facets` is added CONDITIONALLY below: an
				// absent attribute must stay absent from the bag so
				// Instance::from_atts() inherits the admin's Site-Defaults facets,
				// instead of a hardcoded default silently overriding them.
				// Empty when the attribute is absent: Instance::from_atts() treats an
				// empty string as "not set" and falls to the site default, then to
				// all fields. Never given a non-empty shortcode default, so the
				// admin's saved search scope is inherited by a bare shortcode.
				'search_fields' => self::att( $atts, 'search-fields' ),
				'view'          => self::att( $atts, 'view' ),
				'target'        => self::att( $atts, 'target' ),
				'placeholder'   => $placeholder,
				'per_page'      => $per_page,
				// Results destination. Empty => inherit the site default results
				// mode.
				'results_mode'    => self::att( $atts, 'results-mode' ),
				// Filter-display styles. Empty => Instance::from_atts() inherits
				// the site setting, then the shipped default.
				'filters_visibility' => self::att( $atts, 'filters-visibility' ),
				/*
				 * WHICH slice of the calendar this bar opens on. v1.3.6's
				 * `disable-past-events="true"` lands here as `upcoming`; anything
				 * else it carried lands as `all`. Empty => inherit the admin's
				 * saved window. Before Stage G there was no `time` key on the bag
				 * at all and the render seed hard-coded `upcoming`, so the legacy
				 * attribute — and the site setting — were both inert.
				 */
				'time'               => $legacy_val( 'time' ),
				/*
				 * How many rows the type-ahead asks for. v1.3.6's `show-events`
				 * governed exactly this (it had no results grid), so it maps here
				 * as well as to `per_page`. Clamped by `Legacy_Map`, again by
				 * `Instance`, and again at the REST boundary.
				 */
				'suggest_limit'      => $legacy_val( 'suggest_limit' ),
			);

			/*
			 * `facets` added ONLY when the attribute is non-empty, so a bare
			 * shortcode inherits the admin's Site-Defaults facets. A
			 * present-but-empty facets would otherwise resolve
			 * to a search-only bar rather than the inherited set.
			 *
			 * A `$legacy_scope` branch pinned this to `search` for pre-2.0 installs.
			 * It is GONE with the rest of the install-age gate: the shipped default
			 * IS search-only now (`Instance::FACETS_DEFAULT`), so a 1.3.6 upgrader
			 * and a fresh install already render the same search-only bar and the
			 * gate had nothing left to decide.
			 */
			$facets_att = self::att( $atts, 'facets' );
			if ( '' !== $facets_att ) {
				$bag['facets'] = $facets_att;
			}

			// Design + card tokens — added to the bag ONLY when the shortcode
			// specifies them, so an absent attribute inherits the admin's saved
			// "house style" (Instance::from_atts keys the three-tier resolution on
			// array_key_exists). Instance validates and clamps every value.
			//
			// BOTH TAGS come through here, which is what guarantees the results tag
			// can never accept a colour, radius or size the bar tag would reject:
			// the parsing, the numeric guard below and the `Instance` allowlists are
			// one code path, not two. Which of these keys a tag OFFERS is decided in
			// its own `shortcode_atts()` call; an undeclared one simply never arrives.
			$design_atts = array(
				'filter-style'         => 'filter_style',
				// NOTE: `filter-columns` was threaded here. RETIRED with the axis.
				// NOTE: `filters-button-style` was threaded here. WITHDRAWN with the
				// axis — nothing maps it, so a stale attribute reaches no config key.
				'bar-template'         => 'bar_template',
				'accent-color'         => 'accent_color',
				'text-color'           => 'text_color',
				'bg-color'             => 'bg_color',
				'button-style'         => 'button_style',
				'design-sizing'        => 'design_sizing',
				'control-size'         => 'control_size',
				'corner-radius'        => 'corner_radius',
				'columns'              => 'columns',
				'card-size'            => 'card_size',
				'card-fields'          => 'card_fields',
				// HOW the card's date line is written. Threaded here so an absent
				// attribute inherits the admin's choice; Instance allowlists it.
				'date-format'          => 'date_format',
				// WHICH card template. Threaded for the same reason,
				// and kept next to `card-fields` because it IS a card knob; the
				// similarly-named `bar-template` above frames the search box and
				// has nothing to do with it.
				'template'             => 'template',
			);
			/*
			 * The numeric tokens. A NON-NUMERIC value here carries no intent at
			 * all — `[events-calendar-search control-size="gigantic"]` is a typo,
			 * not a request for the shipped 100 — so the key is dropped and the
			 * admin's saved value inherits. Without this the `clean_*` helpers in
			 * `Instance` would see a present-but-junk value and return the shipped
			 * default, silently overriding the site setting.
			 */
			$numeric_keys = array( 'control_size', 'corner_radius', 'columns', 'card_size' );

			foreach ( $design_atts as $att => $key ) {
				$val = self::att( $atts, $att );

				if ( '' === $val ) {
					continue;
				}

				if ( in_array( $key, $numeric_keys, true ) && ! is_numeric( $val ) ) {
					continue;
				}

				$bag[ $key ] = $val;
			}

			/*
			 * v1.3.6 `layout` -> `control_size`, as a DEFAULT only. Runs after the
			 * loop above and is guarded on the key being absent, so an explicit
			 * `control-size="115"` always beats an old `layout="large"` when a
			 * shortcode carries both.
			 *
			 * NOTE: a `map_deprecated()` pass also ran here, translating one
			 * v2.0-internal spelling into a later v2.0-internal spelling
			 * (bar-bg, date-style/facet-style, plain/bordered/card/shadow,
			 * sm/md/lg, simple, inline/toggle, pills/dropdown/compact/custom).
			 * v2.0 NEVER SHIPPED, so no published shortcode anywhere can carry one
			 * of those values. It is deleted, along with its twins in Settings and
			 * Instance; `Compat\Legacy_Map` is the one legacy surface left, and it
			 * speaks v1.3.6 only.
			 */
			if ( ! isset( $bag['control_size'] ) && '' !== $legacy_val( 'control_size' ) ) {
				$bag['control_size'] = $legacy_val( 'control_size' );
			}

			if ( class_exists( 'CoolPlugins\EventsSearch\Render\Instance' ) ) {
				// Site-wide settings are the middle tier between shipped
				// constants and per-shortcode attributes: a bar with no
				// attributes inherits the admin's saved defaults.
				$site_defaults = class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' )
					? \CoolPlugins\EventsSearch\Settings\Settings::instance_defaults()
					: array();

				return \CoolPlugins\EventsSearch\Render\Instance::from_atts( $bag, $site_defaults );
			}

			// Degraded partial deploy (Instance file missing, Renderer present):
			// hand a best-effort bag straight to the Renderer, which re-normalizes.
			// Facets must be an array on that path, and empty scalars are dropped so
			// the Renderer's own defaults apply rather than clamping to a boundary.
			return self::fallback_config( $bag );
		}

		/**
		 * Read a scalar shortcode attribute, falling back to a default.
		 *
		 * Attributes are always strings after `shortcode_atts()`, but a stray
		 * array (a hand-built `do_shortcode` call) must not trip a type error.
		 *
		 * @since 2.0.0
		 * @param array  $atts    Attribute array.
		 * @param string $key     Key to read.
		 * @param string $default Default when absent, non-scalar or empty string.
		 * @return string
		 */
		private static function att( array $atts, $key, $default = '' ) {
			if ( ! isset( $atts[ $key ] ) || ! is_scalar( $atts[ $key ] ) ) {
				return $default;
			}

			$value = (string) $atts[ $key ];

			return '' !== $value ? $value : $default;
		}

		/**
		 * Resolve the instance role.
		 *
		 * TWO tiers, and the second one is a constant:
		 *
		 *   1. `$role_override` — the results shortcode forcing `results`.
		 *   2. `bar`. Always. A SEARCH BAR NEVER RENDERS RESULTS.
		 *
		 * The `mode="…"` attribute is no longer read at all. It used to select
		 * between `bar` and the retired `bar-results`, which is precisely the
		 * choice this change removes: results come from
		 * `[events-calendar-search-results]`, and it finds its bar through a
		 * shared `target` id.
		 * The attribute stays DECLARED (see `render()`) so a published shortcode
		 * carrying it still renders; it simply decides nothing.
		 *
		 * Nothing reads the install age here any more either. The v1.3.6 scope
		 * gate — `Schema::legacy_scope()`, `has_v2_intent()` and the all-or-nothing
		 * `LEGACY_ATTS` rule — is deleted: it existed to hand upgraders a bar-only,
		 * search-only bar while fresh installs got a fuller one, and now BOTH get
		 * the bar-only, search-only one, so both of its branches produced identical
		 * output. `Compat\Legacy_Map` is untouched — the 1.3.6 ATTRIBUTE vocabulary
		 * (`placeholder`, `show-events`, `disable-past-events`, `layout`,
		 * `content-type`) is frozen and still maps in full.
		 *
		 * @since 2.3.0
		 * @param string $role_override Forced role, or ''.
		 * @return string
		 */
		private static function role( $role_override ) {
			return '' !== $role_override ? $role_override : 'bar';
		}

		/**
		 * Best-effort config when the `Instance` front door is unavailable.
		 *
		 * Only reached on a broken partial deploy. Splits the facet CSV to an
		 * array (the shape the Renderer's own normalizer expects) and omits empty
		 * scalars so the Renderer applies its documented defaults.
		 *
		 * @since 2.0.0
		 * @param array $bag Canonical config bag.
		 * @return array
		 */
		private static function fallback_config( array $bag ) {
			$facets = isset( $bag['facets'] ) ? $bag['facets'] : array();
			if ( is_string( $facets ) ) {
				$facets = array_values( array_filter( array_map( 'trim', explode( ',', $facets ) ) ) );
			}

			$config = array( 'facets' => is_array( $facets ) && $facets ? $facets : array( 'search' ) );

			foreach ( array( 'role', 'typeahead', 'time', 'view', 'target', 'placeholder' ) as $key ) {
				if ( isset( $bag[ $key ] ) && '' !== (string) $bag[ $key ] ) {
					$config[ $key ] = (string) $bag[ $key ];
				}
			}

			if ( isset( $bag['per_page'] ) && is_numeric( $bag['per_page'] ) && (int) $bag['per_page'] > 0 ) {
				$config['per_page'] = (int) $bag['per_page'];
			}

			return $config;
		}

		/**
		 * Decide whether this shortcode can render at all.
		 *
		 * When TEC is missing the front end renders nothing at all — the admin
		 * notice carries the explanation, because a front-end error message
		 * would leak plugin state to visitors and cannot be actioned by them.
		 *
		 * Both checks are `class_exists`/`function_exists` guarded so a
		 * partially deployed tree degrades to an empty string instead of
		 * fatalling on update.
		 *
		 * @since 2.0.0
		 * @return bool True when rendering may proceed.
		 */
		private static function can_render() {
			// Only TEC availability gates rendering: on a site where The Events
			// Calendar is inactive the front end renders nothing at all, and the
			// admin notice carries the explanation.
			if ( class_exists( 'CoolPlugins\EventsSearch\Tec\Tec' ) && ! \CoolPlugins\EventsSearch\Tec\Tec::available() ) {
				return false;
			}

			return true;
		}
	}
}
