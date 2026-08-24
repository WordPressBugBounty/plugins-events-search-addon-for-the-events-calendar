<?php
/**
 * Settings data layer — the one schema for the `ecsa_settings` option.
 *
 * This is the single settings contract: every setting is declared once here with its
 * type, default, sanitizer and bounds. The admin panel renders fields from this
 * schema; the front-end reads merged values as the site-wide defaults a bar
 * falls back to when a shortcode attribute is absent; the save handler
 * walks the schema so **unknown keys are discarded, never persisted** (a naive
 * `array_merge($existing,$submitted)` is an unbounded-growth bug, I3).
 *
 * Lives outside the `Admin\` namespace on purpose: the front-end reads it on
 * every rendered bar, and only the *page* (`Admin\Settings\Settings_Page`) is
 * admin-only. No option write happens at read time and nothing runs at include
 * time.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Settings;

use CoolPlugins\EventsSearch\Query\Criteria;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Settings' ) ) {

	/**
	 * Schema, retrieval, and schema-driven save for `ecsa_settings`.
	 *
	 * @since 2.0.0
	 */
	final class Settings {

		/**
		 * The single option name (non-autoloaded, seeded on activation).
		 */
		const OPTION = 'ecsa_settings';

		/**
		 * `tec_views` — the integration stands down entirely.
		 */
		const TEC_VIEWS_OFF = 'off';

		/**
		 * `tec_views` — TEC's results stay; only its header CONTROLS are swapped
		 * for ours.
		 */
		const TEC_VIEWS_HEADER_SWAP = 'header_swap';

		/**
		 * The two `tec_views` modes, in the order the panel offers them.
		 *
		 * Declared as constants (rather than a literal inside `schema()`) because
		 * three separate layers have to agree on the exact spellings: the schema's
		 * enum, the front-end integration that switches its hook registration on
		 * them, and the settings panel that names them. A literal repeated three
		 * times is a typo away from a mode that saves but never registers a hook.
		 *
		 * A THIRD mode — short-circuiting TEC's view bootstrap so OUR bar and OUR
		 * results render instead of its whole List view — is not part of this
		 * plugin. `header_swap` leaves TEC's results exactly where they are and
		 * only swaps the header controls, which is the one integration this plugin
		 * performs.
		 *
		 * @var string[]
		 */
		const TEC_VIEWS_MODES = array( self::TEC_VIEWS_OFF, self::TEC_VIEWS_HEADER_SWAP );

		/**
		 * In-request memo of the merged settings so repeated `get()` calls on a
		 * page render do not re-read and re-merge.
		 *
		 * @var array<string, mixed>|null
		 */
		private static $memo = null;

		/**
		 * The field schema, grouped by tab.
		 *
		 * Each field: type, default, and type-specific metadata (`choices` for
		 * enum/set, `min`/`max` for int, `max_length` for text). `label` and
		 * `help` are translated at render time by the page, not here, so this
		 * method is pure and callable before `init`.
		 *
		 * `type` is one of: text | int | color | enum | set | ordered_set.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, array<string, mixed>>>
		 */
		public static function schema() {
			return array(

				// --- Search Bar -------------------------------------------
				'search_bar' => array(
					'placeholder'   => array( 'type' => 'text', 'default' => '', 'max_length' => 80 ),
					/*
					 * `typeahead` WAS HERE, and is deliberately NOT a stored setting
					 * any more. It is DERIVED from `results_mode` — see
					 * `derive_typeahead()` — because the two were never independent:
					 *
					 *   none    the dropdown IS the experience, so it must be on
					 *   page    the bar is a launcher, so a preview of what it will
					 *           launch is exactly what the dropdown is
					 *   inline  the results block below the bar is the answer, and a
					 *           dropdown over it competes with it
					 *
					 * Leaving it stored would have meant a row that `save()` rewrites
					 * to the shipped default on every submit (the schema walk turns an
					 * absent key into its default) while nothing read it — a value in
					 * the database that lies about what the site does.
					 *
					 * The `typeahead` ATTRIBUTE is untouched and still reaches the
					 * shortcode, so an author can still
					 * override the derived value for ONE placement. What is gone is
					 * the site-wide toggle, and with it the ability to configure a
					 * bar that shows neither a dropdown nor results.
					 */
					/*
					 * WHICH fields a keyword matches.
					 *
					 * SHIPPED DEFAULT, AND THE WHOLE ALLOWLIST: the title, and only
					 * the title. It is what v1.3.6 matched, it is one `LIKE`
					 * against `post_title`, and it is the only scope whose results
					 * a visitor can explain to themselves. Matching `content`, and
					 * the two-pass venue-name / organizer-name resolution, are not
					 * part of this plugin — there is no second pass in the engine
					 * for a wider scope to reach.
					 *
					 * `empty_is_default` (an emptied set stores the shipped
					 * default) rather than `empty_is_all`: the two coincide at one
					 * choice, but `empty_is_all` states the rule that WIDENS, and
					 * that is the rule this field must never carry. `Instance` and
					 * `Renderer` independently resolve an empty scope the same way,
					 * so a hand-written `search-fields=""` cannot widen it either.
					 */
					'search_fields' => array( 'type' => 'set', 'default' => array( 'title' ), 'choices' => array( 'title' ), 'empty_is_default' => true ),
				),

				// --- Filters ----------------------------------------------
				'filters' => array(
					/*
					 * WHICH filters the bar shows, in order.
					 *
					 * SHIPPED DEFAULT: `search` alone — a keyword box and nothing
					 * else. Filters are the plugin's headline feature and are one
					 * checkbox away, but they are opt-in: this default is what
					 * guarantees an update never restructures a page nobody edited,
					 * and it is the same bar an upgrader from 1.3.6 already had.
					 * (The install-age gate that used to make that promise is
					 * deleted — this default makes it unconditionally.)
					 */
					'facets'               => array( 'type' => 'ordered_set', 'default' => array( 'search' ), 'choices' => self::facet_keys() ),
					/*
					 * WHERE the filters live. TWO meanings, one per value:
					 *
					 *   bar_inline  the filters are always visible INSIDE the bar,
					 *               on the keyword row. When the row runs out of
					 *               room it collapses behind the in-bar Filters
					 *               toggle — a container-query state change, not a
					 *               second saveable placement.
					 *   expanded    no triggers at all; every filter's options are
					 *               open. Also the safest choice on themes with a
					 *               sticky header, which a dropdown can lose a
					 *               z-index fight to.
					 *
					 * A placement that keeps the filters permanently behind a
					 * Filters button, and one that keeps them permanently BELOW the
					 * bar, are not part of this plugin.
					 *
					 * SHIPPED DEFAULT `expanded`, and the reason is the collapsed
					 * state above: `bar_inline` shares one row with the keyword
					 * input and the submit, so on a narrow container its filters
					 * fold behind the toggle. `expanded` renders identically at
					 * every width, which is what a fresh install should do.
					 *
					 * The old `below | inbar | button` spellings are DELETED, not
					 * mapped — 2.0 never shipped, and `below` had already been
					 * relabelled once, so reusing it for the OPPOSITE placement
					 * would have made the key lie about its behaviour.
					 */
					'filters_visibility'   => array( 'type' => 'enum', 'default' => 'expanded', 'choices' => array( 'bar_inline', 'expanded' ) ),
					/*
					 * NOTE: `filter_columns` was here — an N-column grid for the
					 * filter row. RETIRED. Filters flow one after another with
					 * tight spacing, which is what a row of variable-width triggers
					 * wants: an equal-track grid crushed long labels and stranded
					 * short ones in half-empty cells. Retired like `facet_styles`:
					 * the read path stops looking at the key, stored rows are never
					 * rewritten, so a downgrade stays lossless.
					 */
					/*
					 * NOTE: `groups_collapsed` was here. It turned each facet group
					 * into its own <details> disclosure back when a filter's options
					 * rendered inline. Under the handoff's trigger + panel model the
					 * options are ALREADY behind a disclosure, so the only renderer
					 * still routed through `facet_group()` was the retired `location`
					 * facet — the setting had become a control that changed nothing.
					 * Retired like `facet_styles`: the read path stops looking at the
					 * key, stored rows are never rewritten, so a downgrade is lossless.
					 */
					/*
					 * HOW a filter trigger looks. One vocabulary for every filter,
					 * date included.
					 *
					 * The model is always a trigger
					 * + a panel, with the style governing the trigger's geometry:
					 * six filters' worth of always-visible options is
					 * a wall of controls, and "show everything at once" is a
					 * PLACEMENT question, answered by filters_visibility=expanded.
					 *
					 * Because the trigger is the bar's visual language it is global;
					 * the retired per-filter `facet_styles` map is gone. What each
					 * panel CONTAINS still differs per filter, but that follows from
					 * the filter's own nature and is never a setting.
					 *
					 * ONE VALUE — a bare label + caret, no chrome. The chip,
					 * form-control and filled-chip treatments are not part of this
					 * plugin, so the axis has nothing to choose between and the
					 * PANEL RENDERS NO CONTROL FOR IT (`Settings_Page` skips the
					 * field entirely). The field stays in the schema because it is
					 * still stored, still read by the renderer, and still the
					 * allowlist that rejects a tampered POST — a control with one
					 * option would merely look broken.
					 */
					'filter_style'         => array( 'type' => 'enum', 'default' => 'text', 'choices' => array( 'text' ) ),
					/*
					 * NOTE: `filters_button_style` was here — a four-value axis for
					 * the in-bar "Filters" TOGGLE. THE WHOLE FIELD IS WITHDRAWN, not
					 * narrowed to one value the way `filter_style` was.
					 *
					 * The difference is worth stating, because the two look alike.
					 * `filter_style` still STORES something the renderer reads, so a
					 * one-member allowlist is doing real work. This key had no such
					 * job: the toggle it painted renders only in `bar_inline`'s
					 * COLLAPSED state (a container-query state below 560px) and the
					 * admin preview sits at roughly 740px, so the control changed
					 * nothing visible at the only place it could be set. The button
					 * keeps the shipped look, stamped unconditionally by
					 * `Renderer::design_attrs()`.
					 *
					 * Retired like `facet_styles`: the read path stops looking at the
					 * key, stored rows are never rewritten, so a downgrade stays
					 * lossless.
					 */
					/*
					 * NOTE: `location_field` was here — an in-bar searchable place
					 * input matching city / region / country against venue meta and
					 * grouping its suggestions by scale. That control is not part
					 * of this plugin: there is no `location` facet, no `location`
					 * criteria key, no `/places` route and no venue-meta scan for
					 * one to read. Retired like `facet_styles`: the read path stops
					 * looking at the key, stored rows are never rewritten, so a
					 * downgrade stays lossless.
					 */
				),

				// --- Results ----------------------------------------------
				'results' => array(
					/*
					 * WHERE RESULTS APPEAR. Two placements, and this key is the
					 * ONE place either of them is recorded.
					 *
					 *   none    Dropdown suggestions only. No results block anywhere;
					 *           the type-ahead IS the experience.
					 *   inline  Below the bar. The panel emits TWO shortcodes — the
					 *           bar and the results — sharing a `target`.
					 *
					 * A third placement — a separate results PAGE the bar submits to
					 * — is not part of this plugin, and neither is the
					 * `results_page_id` page picker that pointed at one.
					 *
					 * `none` IS NEW, AND IT IS WHY THIS KEY WIDENED RATHER THAN
					 * GAINING A SIBLING. The panel used to shape the placement
					 * client-side: a nameless `results_where` radio group whose
					 * choices were collapsed by `syncResultsMode()` into one value
					 * before a hidden mirror input carried it to the server. So
					 * several choices saved as the SAME value and "dropdown
					 * suggestions only" could not survive a reload. A second stored
					 * key would have left two things claiming to answer one question;
					 * widening this one keeps the answer singular, keeps every
					 * existing surface (the `results-mode` shortcode attribute)
					 * speaking the same vocabulary, and lets the cards submit
					 * `ecsa[results_mode]`
					 * directly with no shaper and no mirror.
					 *
					 * SHIPPED DEFAULT `none`: a fresh install is a search box with
					 * suggestions and nothing else —
					 * exactly what a 1.3.6 upgrader already had. It is also what makes the
					 * derived `typeahead` coherent out of the box — with `inline` as
					 * the default a bare bar would derive suggestions OFF and, with no
					 * results block placed, show nothing at all.
					 *
					 * "Elsewhere on this page" (the client-only `separate`) is RETIRED
					 * and gets no mapping — 2.0 never shipped. "Below the bar" already
					 * covers it: the results shortcode can be pasted directly under
					 * the bar or anywhere else on the page. The plugin never enforced
					 * that position; only where the author pasted the tag did.
					 */
					'results_mode'    => array( 'type' => 'enum', 'default' => 'none', 'choices' => array( 'none', 'inline' ) ),
					'view'            => array( 'type' => 'enum', 'default' => 'grid', 'choices' => array( 'grid', 'list' ) ),
					// Grid column count (grid view only) and a single scale knob
					// for the card internals, so one slider resizes the whole card.
					'columns'         => array( 'type' => 'int', 'default' => 3, 'min' => 2, 'max' => 5 ),
					'card_size'       => array( 'type' => 'int', 'default' => 100, 'min' => 80, 'max' => 140 ),
					// Which pieces a result card shows, in both grid and list view.
					'card_fields'     => array(
						'type'         => 'set',
						'default'      => array( 'image', 'title', 'date', 'venue', 'cost' ),
						'choices'      => array( 'image', 'title', 'date', 'venue', 'cost' ),
						'empty_is_all' => true,
					),
					/*
					 * HOW a card's date line is written.
					 *
					 * `site` is the default and deliberately so: it uses
					 * WordPress's own `date_format` / `time_format` options, which
					 * the site owner has usually already set and which `wp_date()`
					 * honours for free. The four explicit patterns exist so an
					 * admin who wants day-first or ISO on THIS component does not
					 * have to change a site-wide setting to get it.
					 */
					'date_format'     => array( 'type' => 'enum', 'default' => 'site', 'choices' => array( 'site', 'long', 'medium', 'dmy', 'iso' ) ),
					/*
					 * WHICH card template renders.
					 *
					 *   clean   the date lives on the card's text line
					 *
					 * The alternative treatment — a date pill on the image, with the
					 * text line reduced to the time — is not part of this plugin.
					 * The field stays in the schema because it is stored, read by
					 * the renderer and carried by REST and the shortcode; a
					 * one-member allowlist is what rejects a tampered value.
					 *
					 * STRUCTURAL, like `date_format`: a template swap changes the
					 * MARKUP and the rendered TEXT, so no class or custom property
					 * could re-stamp it on the client.
					 */
					'template'        => array( 'type' => 'enum', 'default' => 'clean', 'choices' => array( 'clean' ) ),
					'per_page'        => array( 'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 50 ),
					'sort'            => array( 'type' => 'enum', 'default' => 'date_asc', 'choices' => array( 'date_asc', 'date_desc', 'title' ) ),
					'time'            => array( 'type' => 'enum', 'default' => 'upcoming', 'choices' => array( 'upcoming', 'past', 'all' ) ),
				),

				// --- Design (curated, theme-proof tokens) -----------------
				// Rendered as CSS custom properties + modifier classes on the
				// `.ecsa` wrapper (see Renderer::design_attrs()), so a theme can
				// never bleed into the bar. The THREE colors below are the only
				// color inputs; every other shade (border, hover, muted, soft fill,
				// on-accent text) is derived from them with color-mix() in CSS.
				// They carry real defaults, so a color control is never an empty
				// "pick me first" state; clearing one falls back to that default.
				'design' => array(
					// How the input and the search button are bound together. TWO
				// choices: `detached` keeps them apart, `unified` wraps one frame
				// around both and tucks the button inside the shell (§9b).
				//
				// `capsule` ("Pill") and `soft` ("Filled") are RETIRED, not mapped:
				// the background colour and the corner-radius slider already
				// express both, and `capsule` additionally force-set the radius to
				// 999px — one control silently overriding another.
				//
				// CAREFUL: `soft` is ALSO a `filter_style` value and that one
				// STAYS. Only this axis loses the spelling.
				'bar_template'  => array( 'type' => 'enum', 'default' => 'unified', 'choices' => array( 'detached', 'unified' ) ),
					'accent_color'  => array( 'type' => 'color', 'default' => '#2563eb' ),
					'text_color'    => array( 'type' => 'color', 'default' => '#1f2937' ),
					'bg_color'      => array( 'type' => 'color', 'default' => '#ffffff', 'allow_transparent' => true ),
					// `none` is behaviour, not just decoration: it removes the button
				// and submits on Enter, showing a small "↵ enter" hint instead.
				//
				// SHIPPED DEFAULT `solid_icon`: it reads as the page's primary
				// action (solid) without spending bar width on a word every
				// visitor already reads off the magnifier.
				'button_style'  => array( 'type' => 'enum', 'default' => 'solid_icon', 'choices' => array( 'solid', 'solid_icon', 'outline', 'icon', 'text', 'none' ) ),
					/*
					 * MASTER GATE for the numeric design sliders.
					 *
					 * `off` — the shipped default — does not merely hide them.
					 * `Instance::from_atts()` pins the sliders to their SHIPPED
					 * values, so `Renderer::design_attrs()` emits the byte-for-byte
					 * string a default install has always emitted. A site that never
					 * touches this control cannot look different because of it.
					 *
					 * AN ENUM, NOT A BOOL, ON PURPOSE. `sanitize_field()` has no bool
					 * branch — it was removed together with the hidden companion
					 * input and the panel JS that read it, and each of those carries a
					 * note saying they must return together. A two-value enum needs
					 * none of them, reuses `field_segmented()`, and answers the
					 * existing `data-ecsa-reveal-when` grammar unchanged.
					 */
					'design_sizing' => array( 'type' => 'enum', 'default' => 'off', 'choices' => array( 'off', 'on' ) ),
					// One numeric scale (percent) for the whole bar, replacing the
					// old sm/md/lg trio: every size token derives from it.
					'control_size'  => array( 'type' => 'int', 'default' => 100, 'min' => 80, 'max' => 140 ),
					'corner_radius' => array( 'type' => 'int', 'default' => 8, 'min' => 0, 'max' => 24 ),
				),

				// --- Behavior ---------------------------------------------
				'behavior' => array(
					'recurrence' => array( 'type' => 'enum', 'default' => 'next_only', 'choices' => array( 'next_only', 'all_occurrences' ) ),
					/*
					 * WHAT WE DO TO THE EVENTS CALENDAR'S OWN EVENTS PAGE. Two
					 * modes, and this key is the ONE place either is recorded:
					 *
					 *   off          nothing happens. NOT a callback that returns
					 *                early — `Display\Tec_Views::init()` registers
					 *                NO hook at all, so the List view behaves
					 *                exactly as if this addon were not installed.
					 *   header_swap  TEC's results are left exactly as they are;
					 *                only the two header CONTROLS it ships are
					 *                suppressed — the events bar (search form +
					 *                List/Month/Day selector) and the List top bar
					 *                (date nav) — and our bar takes their place.
					 *                The header SHELL stays, so the page keeps its
					 *                `<h1>`, its breadcrumbs and TEC's own
					 *                messages/empty-state region.
					 *
					 * A mode that short-circuits TEC's view bootstrap and renders
					 * OUR bar and OUR results instead of its whole List view is not
					 * part of this plugin. `header_swap` never takes the page over:
					 * TEC's own results keep rendering, and our bar drives them
					 * through `tribe_events_views_v2_view_repository_args`.
					 *
					 * IT WAS A BOOL. `true` meant one fixed behaviour — inject our
					 * bar, blank TEC's search sub-components, keep its results —
					 * which is exactly `header_swap`. Widening the SAME key rather
					 * than adding a second one keeps "what happens on /events/" a
					 * single question with a single answer, and
					 * `Schema::upgrade_to_5()` moves an existing bool row across
					 * exactly once.
					 */
					'tec_views'  => array( 'type' => 'enum', 'default' => self::TEC_VIEWS_OFF, 'choices' => self::TEC_VIEWS_MODES ),

					/*
					 * AN OPTIONAL HEADING ABOVE THE INJECTED BAR.
					 *
					 * The events archive is not a WordPress page, so there is nowhere
					 * obvious for an owner to put a heading on it — which is the whole
					 * reason this exists. One line of plain text, rendered above the
					 * bar.
					 *
					 * BLANK IS THE DEFAULT AND BLANK RENDERS NOTHING. Almost every site
					 * will never set it, and those sites must get byte-for-byte the
					 * markup they get today — not an empty heading standing in for one.
					 *
					 * It answers a different question from `tec_views` and so it is a
					 * different key, but it is meaningless without it: the renderer
					 * checks the mode before emitting anything, and the panel hides the
					 * field while the mode is `off`.
					 */
					'tec_page_title' => array( 'type' => 'text', 'default' => '', 'max_length' => 120 ),
				),

				/*
				 * "Clear search cache" is an ACTION, not a stored value, so it is
				 * not a schema field — the settings page handles it directly. (The
				 * legacy render-engine escape hatch was removed with the v1.3.6
				 * engine; v2 is now the only render path.)
				 */
			);
		}

		/**
		 * Flat map of every field key to its definition.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, mixed>>
		 */
		public static function fields() {
			$flat = array();
			foreach ( self::schema() as $tab_fields ) {
				foreach ( $tab_fields as $key => $def ) {
					$flat[ $key ] = $def;
				}
			}
			return $flat;
		}

		/**
		 * The shipped defaults, derived from the schema.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		public static function defaults() {
			$out = array();
			foreach ( self::fields() as $key => $def ) {
				$out[ $key ] = $def['default'];
			}
			return $out;
		}

		/**
		 * The shipped theme presets: one click that sets the three colours.
		 *
		 * NOT a schema field, and deliberately so. A preset is a SHORTCUT that
		 * writes `accent_color` / `text_color` / `bg_color` — nothing records
		 * which one was clicked, because the three colours stay the only source
		 * of truth. An admin who then nudges one of them is not "off-preset";
		 * they simply have their own colours. That keeps a preset a one-click
		 * head start instead of a fourth thing to configure (and keeps a
		 * downgrade lossless — there is no extra row to leave behind).
		 *
		 * Data only, no strings: the page owns the translated names, exactly as
		 * it owns every other label, so this method stays pure and callable
		 * before `init`.
		 *
		 * Every triple is verified against `Render\Tokens`, which derives all
		 * six side by side — that is what makes the two DARK themes safe:
		 * `on-accent` flips to near-black under lime/amber, and the `soft`
		 * surface mixes LIGHTER than its background rather than vanishing.
		 *
		 * @since 2.1.0
		 * @return array<string, array<string, string>> Preset key => the three colour values.
		 */
		public static function color_presets() {
			return array(
				'ink_blue'   => array( 'accent_color' => '#2f54eb', 'text_color' => '#111827', 'bg_color' => '#ffffff' ),
				'deep_teal'  => array( 'accent_color' => '#0f766e', 'text_color' => '#14201e', 'bg_color' => '#faf9f5' ),
				'crimson'    => array( 'accent_color' => '#c0322b', 'text_color' => '#1c1512', 'bg_color' => '#fffbf7' ),
				'violet'     => array( 'accent_color' => '#6d28d9', 'text_color' => '#1a1626', 'bg_color' => '#fbfaff' ),
				'lime_dark'  => array( 'accent_color' => '#b9f24d', 'text_color' => '#e9ecef', 'bg_color' => '#101215' ),
				'amber_dark' => array( 'accent_color' => '#f0a72a', 'text_color' => '#eceef2', 'bg_color' => '#181b21' ),
			);
		}

		/**
		 * Retrieve the merged settings, or a single field.
		 *
		 * Merge order: shipped defaults <- persisted `ecsa_settings`. Only keys
		 * that exist in the schema are returned, so a stale key left in the DB by
		 * an older version is ignored (and dropped on the next save).
		 *
		 * NO FORWARD MAPPING. A `map_legacy()` pass ran here, translating one
		 * v2.0-internal spelling into a later v2.0-internal spelling
		 * (plain/bordered/card/shadow, pills/dropdown/compact/custom,
		 * inline/toggle, sm/md/lg, simple, bar_bg). v2.0 NEVER SHIPPED, so no
		 * `ecsa_settings` row on any install can hold one of those values, and
		 * the map only made the vocabulary look bigger than it is. The one real
		 * legacy surface is `Compat\Legacy_Map`, which speaks v1.3.6 — whose
		 * option rows are separate options, never keys of `ecsa_settings`.
		 *
		 * @since 2.0.0
		 * @param string|null $key Field key, or null for the whole array.
		 * @return mixed
		 */
		public static function get( $key = null ) {
			if ( null === self::$memo ) {
				$defaults = self::defaults();
				$saved    = get_option( self::OPTION, array() );
				$saved    = is_array( $saved ) ? $saved : array();

				$merged = $defaults;
				foreach ( $defaults as $k => $default ) {
					if ( array_key_exists( $k, $saved ) ) {
						$merged[ $k ] = $saved[ $k ];
					}
				}

				self::$memo = $merged;
			}

			if ( null === $key ) {
				return self::$memo;
			}

			return array_key_exists( $key, self::$memo ) ? self::$memo[ $key ] : null;
		}

		/**
		 * Drop the in-request memo. Called after a save and by tests.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function flush() {
			self::$memo = null;
		}

		/**
		 * The instance-config subset, for the shortcode and widget adapters.
		 *
		 * Returns the site-wide defaults an instance falls back to when an
		 * attribute is absent, in the shape `Render\Instance::from_atts()`
		 * consumes. Presentation-only keys (filter display styles) travel with
		 * it so a bar with no attributes inherits the admin's chosen look.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		public static function instance_defaults() {
			$s = self::get();

			return array(
				// DERIVED, never stored. The site-wide toggle is gone; the placement
				// answers this question on its own (see `derive_typeahead()`). A
				// shortcode `typeahead` attribute still wins over
				// this value, so a single placement can still say otherwise.
				'typeahead'            => self::derive_typeahead( $s['results_mode'] ),
				// WHICH slice of the calendar a bar opens on. Threaded in Stage G
				// alongside v1.3.6's `disable-past-events`: the schema has carried
				// `time` from the start and the settings panel has always offered
				// it, but nothing passed it to an instance, so the render seed's
				// hard-coded `upcoming` won and the control did nothing.
				'time'                 => $s['time'],
				'results_mode'         => $s['results_mode'],
				'facets'               => $s['facets'],
				'view'                 => $s['view'],
				'per_page'             => $s['per_page'],
				'placeholder'          => $s['placeholder'],
				'search_fields'        => $s['search_fields'],
				'filters_visibility'   => $s['filters_visibility'],
				'filter_style'         => $s['filter_style'],
				// NOTE: `filters_button_style` rode here. WITHDRAWN with the axis —
				// there is no stored key for an instance to inherit.
				'columns'              => $s['columns'],
				'card_size'            => $s['card_size'],
				'card_fields'          => $s['card_fields'],
				'date_format'          => $s['date_format'],
				// The card template. `clean` is free's only value — `modern` and its
				// date pill are Pro. Rides here so a bare shortcode or widget
				// inherits the admin's choice.
				'template'             => $s['template'],
				'bar_template'         => $s['bar_template'],
				'accent_color'         => $s['accent_color'],
				'text_color'           => $s['text_color'],
				'bg_color'             => $s['bg_color'],
				'button_style'         => $s['button_style'],
				'design_sizing'        => $s['design_sizing'],
				'control_size'         => $s['control_size'],
				'corner_radius'        => $s['corner_radius'],
			);
		}

		/**
		 * The type-ahead mode a placement implies.
		 *
		 * THE ONE STATEMENT OF THE RULE, so the data layer, the settings panel's
		 * localized defaults all read the same one rather than
		 * three copies that can drift:
		 *
		 *   none    ON  — the dropdown is the entire experience.
		 *   inline  OFF — the results block below the bar IS the answer, and a
		 *                 dropdown floating over it competes with it.
		 *
		 * Anything unrecognised derives ON: a bar with neither a dropdown nor a
		 * results surface is the one configuration that does nothing at all, so it
		 * is never the answer to a value we cannot read.
		 *
		 * @since 2.5.0
		 * @param string $results_mode `none` | `inline`.
		 * @return string `off` | `dropdown`.
		 */
		public static function derive_typeahead( $results_mode ) {
			return ( 'inline' === (string) $results_mode ) ? 'off' : 'dropdown';
		}

		/**
		 * The `tec_views` mode, coerced to one of the three declared spellings.
		 *
		 * THE ONE COERCION, so the front-end integration and the settings panel
		 * cannot disagree about what an unreadable value means. It is
		 * belt-and-braces rather than decoration: `save()` already allowlists the
		 * enum, but a hand-edited option row — or a row an upgrade step has not
		 * reached yet (the pre-round-3 BOOL) — can hold anything, and
		 * `Settings::get()` returns what it finds without re-validating.
		 *
		 * ANYTHING UNRECOGNISED IS `off`, deliberately: the modes rewrite someone
		 * else's page, so a value we cannot read must never be interpreted as
		 * permission to do that. A legacy `true` therefore reads as `off` here —
		 * `Schema::upgrade_to_5()` is what turns it into `header_swap`, once, at
		 * the moment the site upgrades.
		 *
		 * @since 2.6.0
		 * @param mixed $raw Explicit value to coerce, or null to read the setting.
		 * @return string One of `off` | `replace` | `header_swap`.
		 */
		public static function tec_views_mode( $raw = null ) {
			$value = ( null === $raw ) ? self::get( 'tec_views' ) : $raw;
			$value = is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : '';

			return in_array( $value, self::TEC_VIEWS_MODES, true ) ? $value : self::TEC_VIEWS_OFF;
		}

		/**
		 * Sanitize a raw submitted array against the schema and persist it.
		 *
		 * Walks the SCHEMA, never the input, so unknown keys can never be
		 * written. Each value is sanitized by type; labels/placeholders are
		 * plain text (`sanitize_text_field` + length cap), never `wp_kses_post`
		 * (I23). Legacy v1.3.6 option rows are untouched (they are separate
		 * options, not keys of `ecsa_settings`), so a downgrade stays lossless.
		 *
		 * @since 2.0.0
		 * @param array $input Raw `$_POST` subtree (already `wp_unslash`ed by the caller).
		 * @return true|\WP_Error True on success, WP_Error with per-field messages otherwise.
		 */
		public static function save( array $input ) {
			$clean = array();

			foreach ( self::fields() as $key => $def ) {
				$raw            = array_key_exists( $key, $input ) ? $input[ $key ] : null;
				$clean[ $key ] = self::sanitize_field( $def, $raw );
			}

			$valid = self::validate( $clean );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			// Non-autoloaded, explicit — never let this ride `alloptions`.
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, $clean, '', 'no' );
			} else {
				update_option( self::OPTION, $clean, false );
			}

			self::flush();

			return true;
		}

		/**
		 * Sanitize one field value by its declared type.
		 *
		 * A null/absent value yields the field default. For a checkbox that would
		 * mean "unchecked" only when the form reliably submits the key, which is
		 * why the panel emits a hidden companion input before every bool — but
		 * see the `bool` NOTE below: `location_field` was the schema's last
		 * boolean, so this tree renders no checkbox and has no companion to keep
		 * honest. A future boolean must restore both halves together.
		 *
		 * @since 2.0.0
		 * @param array $def Field definition.
		 * @param mixed $raw Raw value or null.
		 * @return mixed
		 */
		private static function sanitize_field( array $def, $raw ) {
			switch ( $def['type'] ) {

				case 'text':
					$value = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
					$max   = isset( $def['max_length'] ) ? (int) $def['max_length'] : 200;
					if ( function_exists( 'mb_substr' ) ) {
						return mb_substr( $value, 0, $max, 'UTF-8' );
					}
					return substr( $value, 0, $max );

				case 'int':
					$n   = is_scalar( $raw ) ? (int) $raw : (int) $def['default'];
					$min = isset( $def['min'] ) ? (int) $def['min'] : PHP_INT_MIN;
					$max = isset( $def['max'] ) ? (int) $def['max'] : PHP_INT_MAX;
					return max( $min, min( $max, $n ) );

				/*
				 * NOTE: a `bool` branch and a `page` branch lived here.
				 * `location_field` was the schema's only boolean and
				 * `results_page_id` its only `page`, so both branches became
				 * unreachable when those fields went and are deleted with them. A
				 * future boolean must re-add the branch AND keep it agreeing with
				 * `Render\Instance`'s `to_bool()`, or a value saved by the panel
				 * would not round-trip through a shortcode.
				 */

				case 'color':
					// Empty = inherit our neutral default. Optionally 'transparent'.
					// Otherwise a strict #hex (sanitize_hex_color rejects anything
					// that is not a valid hex, so no CSS injection can slip in).
					$val = is_scalar( $raw ) ? trim( (string) $raw ) : '';
					if ( '' === $val ) {
						return '';
					}
					if ( ! empty( $def['allow_transparent'] ) && 'transparent' === strtolower( $val ) ) {
						return 'transparent';
					}
					$hex = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $val ) : ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $val ) ? $val : '' );
					return is_string( $hex ) ? $hex : '';

				case 'enum':
					$choices = isset( $def['choices'] ) ? $def['choices'] : array();
					return in_array( $raw, $choices, true ) ? $raw : $def['default'];

				case 'set':
					$choices = isset( $def['choices'] ) ? $def['choices'] : array();
					$in      = is_array( $raw ) ? $raw : array();
					$out     = array();
					foreach ( $in as $v ) {
						$v = is_scalar( $v ) ? sanitize_key( (string) $v ) : '';
						if ( in_array( $v, $choices, true ) && ! in_array( $v, $out, true ) ) {
							$out[] = $v;
						}
					}
					if ( empty( $out ) ) {
						/*
						 * TWO different meanings for "the admin cleared every box",
						 * and a set field must declare which one it has:
						 *
						 *   empty_is_all      show EVERYTHING. Right for
						 *                     `card_fields`, where the alternative
						 *                     is a blank card.
						 *   empty_is_default  fall back to the SHIPPED default.
						 *                     Right for `search_fields`, where
						 *                     "everything" would silently WIDEN the
						 *                     scope past both the admin's last
						 *                     choice and the shipped one.
						 *
						 * A set that declares neither stores the empty array, which
						 * is the honest answer when empty is a legitimate state.
						 */
						if ( ! empty( $def['empty_is_all'] ) ) {
							return $choices;
						}
						if ( ! empty( $def['empty_is_default'] ) ) {
							return $def['default'];
						}
					}
					return $out;

				case 'ordered_set':
					// Order is meaningful (facet display order) and preserved.
					$choices = isset( $def['choices'] ) ? $def['choices'] : array();
					$in      = is_array( $raw ) ? $raw : array();
					$out     = array();
					foreach ( $in as $v ) {
						$v = is_scalar( $v ) ? sanitize_key( (string) $v ) : '';
						if ( in_array( $v, $choices, true ) && ! in_array( $v, $out, true ) ) {
							$out[] = $v;
						}
					}
					return $out ? $out : $def['default'];

				/*
				 * NOTE: a `map` branch (an associative { key => value } override
				 * map) lived here for the per-filter `facet_styles` field. That was
				 * the ONLY map-typed field the schema ever had, so the branch became
				 * unreachable when the field was retired and is gone with it. A
				 * future map field must re-add both together.
				 */
				default:
					return $def['default'];
			}
		}

		/**
		 * Cross-field validation (the coherence rules that block an incoherent save).
		 *
		 * @since 2.0.0
		 * @param array $s Sanitized settings.
		 * @return true|\WP_Error
		 */
		private static function validate( array $s ) {
			$error = new \WP_Error();

			/*
			 * NOTE: a `results_mode = page` rule ran here, refusing the save
			 * unless `results_page_id` pointed at a PUBLISHED page. There is no
			 * `page` placement and no `results_page_id`, so there is nothing left
			 * to be incoherent about. `ecsa_results_page_required` is deleted with
			 * it, not mapped: 2.0 never shipped, so no stored state refers to it.
			 */

			/*
			 * THE `tec_views` + `results_mode` EXCLUSION IS GONE, and this is the
			 * reasoning, recorded so it is not re-added.
			 *
			 * It existed while `tec_views` was a bool that meant "the events page
			 * is where filtered results appear", which did read as a second answer
			 * to the question `results_mode` already answers. The two keys are
			 * plainly different questions:
			 *
			 *   results_mode  where the results for a bar YOU place — a shortcode
			 *                 or a widget — appear.
			 *   tec_views     what happens on The Events Calendar's OWN /events/
			 *                 page, a surface nobody places.
			 *
			 * `header_swap` does not consult `results_mode`, and that is the point:
			 * it drives TEC's own results in place and forces the local placement
			 * it needs (`Tec_Views` sets `results_mode => inline` on the configs it
			 * renders), so a site-wide placement cannot make it bounce a visitor
			 * off the very page they are filtering. `ecsa_tec_views_conflict` is
			 * deleted with it, not mapped: 2.0 never shipped, so no stored state
			 * refers to it.
			 */

			/*
			 * Filters need somewhere to send results.
			 *
			 * THE ROLE ARM OF THIS RULE IS GONE, because the role no longer answers
			 * the question. Until now `role = 'bar'` meant "renders the bar and
			 * nothing else, so filters would collect input nothing consumes" —
			 * `bar-results` was the role that carried its own results. There is no
			 * `bar-results` any more: EVERY bar is `bar`, and every bar drives a
			 * results region that lives somewhere else (the results shortcode).
			 * Keeping the old test would have rejected every save that enabled a
			 * single filter.
			 *
			 * What is left is the honest question, and `results_mode` is the only
			 * thing that answers it: `inline` (a results region is expected on the
			 * page). Anything else is nowhere. Whether the author actually PLACED
			 * the results block is not something the settings screen can know; that
			 * is the pairing's job, which is why the panel emits a matched bar +
			 * results pair with a shared `target`.
			 *
			 * IT CANNOT FIRE FOR A BAR THAT DISPLAYS NO FILTERS, and that is a
			 * property of the INPUT rather than an exception carved out here. The
			 * rule used to reject "Search box only" + "Dropdown suggestions only",
			 * a pairing with no filters in it at all: the panel's "What to display?"
			 * choice gated rendering but never touched the stored facet list, so
			 * the submission still carried `date` and this test still saw it.
			 * `Settings_Page::reduce_facets_to_displayed()` now reduces the set to
			 * `['search']` on exactly that submission, so `$extra_facets` is empty
			 * and the rule stands down — which is the correct answer, not a
			 * suppressed one. The rule itself is unchanged and still fires for a
			 * bar that really does display filters with nowhere to send them.
			 */
			$extra_facets = array_diff( (array) $s['facets'], array( 'search' ) );

			if ( ! empty( $extra_facets ) && ! self::has_results_surface( $s['results_mode'] ) ) {
				$error->add(
					'ecsa_facets_need_target',
					__( 'Filters need somewhere to show results. Set “Where should search results appear?” to “Below search bar”.', 'events-search-addon-for-the-events-calendar' )
				);
			}

			return $error->has_errors() ? $error : true;
		}

		/**
		 * Whether a given results mode has anywhere for filtered results to go.
		 *
		 * The single statement of that rule, so the settings guard and
		 * any future per-instance coherence check read the same one.
		 *
		 * The instance ROLE used to be the second half of this test, back when
		 * `bar-results` was a role and `bar` therefore meant "no results anywhere".
		 * With the bar/results split every bar is `bar` and drives a results region
		 * placed separately, so the role says nothing about whether a surface
		 * exists and asking it would reject every filtered configuration. The
		 * parameter is removed rather than defaulted, so no caller can pass a role
		 * and believe it was consulted.
		 *
		 * `none` is a DECLARED placement rather than merely an unrecognised value,
		 * and it is the one that answers "no surface" on purpose: the dropdown is a
		 * navigator, not a results region, so a filter set to it would still be
		 * collecting input nothing consumes.
		 *
		 * The list is kept as an `in_array()` allowlist rather than collapsing to
		 * `'inline' === $results_mode`, so that the rule stays a statement about
		 * WHICH placements carry a surface rather than about the one that currently
		 * does.
		 *
		 * @since 2.3.0
		 * @param string $results_mode `inline` (`none` — and anything else — is no surface).
		 * @return bool
		 */
		public static function has_results_surface( $results_mode ) {
			return in_array( (string) $results_mode, array( 'inline' ), true );
		}

		/**
		 * The facet allowlist, degrading to a literal when Criteria is absent.
		 *
		 * THE LITERAL MUST EQUAL `Criteria::FACET_KEYS`. This is the duplicate copy
		 * of the registry that a strip is most likely to miss: it is the one the
		 * schema uses on any request where the `class_exists` guard fails, so a
		 * stale list here offers `choices` the registry does not have — keys that
		 * save through `sanitize_field()` and then render nothing. The two are locked EQUAL rather than each
		 * individually correct.
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
	}
}
