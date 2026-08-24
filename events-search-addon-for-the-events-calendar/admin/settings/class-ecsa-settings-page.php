<?php
/**
 * Settings page controller — a single-editor admin panel built over the shared
 * Events Addons dashboard chrome.
 *
 * One two-column editor: the LEFT column holds four subtabs of controls
 * (Compose · Bar design · Filters · Results), rendered FROM `Settings::schema()`
 * so the panel can never drift from the data layer; the RIGHT column is a
 * browser-frame LIVE PREVIEW plus the generated shortcode, a Copy button and a
 * "Save as default" submit. The SAME controls both drive the client-side
 * shortcode generator/preview AND — because they carry real `ecsa[…]` field
 * names — persist as the site-wide defaults when the form is submitted.
 *
 * Everything posts to ONE form under ONE nonce + `manage_options` gate; the raw
 * `ecsa[]` subtree goes straight to `Settings::save()` (which sanitizes,
 * validates and persists), so the sanitisation logic is never duplicated here.
 * Dispatch is on the `ecsa_action` submit value (save · clear_cache), so every
 * button shares that one gate.
 *
 * Security is the point of this screen: one nonce + capability gate covers every
 * write; every reflected value is `esc_attr`'d so a stored-then-echoed
 * `"><img onerror=…>` label is inert in the field value; assets enqueue only on
 * this exact screen (hook-suffix match).
 *
 * ---------------------------------------------------------------------------
 * CONTROL CONTRACT — how the live-preview JS reads this panel
 * ---------------------------------------------------------------------------
 * Every control exposes `data-ecsa-ctrl="<config key>"` on its wrapper (or on
 * the input itself for `toggle`/`text`/`number`/`select`) plus a
 * `data-ecsa-type` naming HOW to read it. The reader must handle exactly these:
 *
 *   segmented   Radio group wrapper. Value = `wrapper.querySelector('input:checked').value`.
 *   cards       Radio cards wrapper (visually richer segmented). Read identically
 *               to `segmented` — `input:checked`.value. One cards group is a
 *               CLIENT-ONLY shaper: `bar_parts` ("What to display?") saves nothing
 *               and names its radios `ecsa_bar_parts`, outside the `ecsa[…]`
 *               subtree, because a radio group still needs a shared name to be
 *               mutually exclusive.
 *   toggle      A single checkbox element. Value = `el.checked` (boolean). A saved
 *               bool has a hidden companion input with value "0" before it, so an
 *               unchecked box still submits a real 0.
 *               (An `enumtoggle` type — a checkbox standing in for a two-value
 *               enum — was here for `typeahead`. That control is retired: the
 *               value is derived from the results placement, and the type had no
 *               other user, so both ends of the contract were deleted. The two
 *               nameless client-only toggles `show_search` / `show_filters` were
 *               also read this way until they were folded into the
 *               one `bar_parts` cards group above.)
 *   text        An `<input type="text">`. Value = `el.value`.
 *   number      An `<input type="number">`. Value = `el.value` (string; cast as needed).
 *               NO FIELD USES IT — `results_page_id` was its only one. The reader
 *               stays because it is the JS half of a two-sided contract and the
 *               PHP half (`field_number()`) can come back; a reader that has to be
 *               re-derived is how the two sides drift.
 *   select      A `<select>`. Value = `el.value`.
 *   color       Wrapper holding a visible `input[type=color][data-ecsa-color-picker]`
 *               and a hidden `input[data-ecsa-color-value]` carrying the real
 *               submitted value. Value = the hidden input's value. Always-on:
 *               there is no "custom" or "transparent" checkbox any more.
 *   slider      Wrapper holding `input.ecsa-slider__range`. Value = that input's
 *               value. The wrapper MAY carry `data-ecsa-suffix` ("%") for the
 *               readout; the unit is also printed as its own `.ecsa-slider__unit`
 *               span so overwriting `.ecsa-slider__out` textContent is safe.
 *   chips       Checkbox set wrapper (rendered either as pills or as a 2-column
 *               grid — same contract). Value = array of checked checkbox values.
 *   sortable    Ordered checkbox list. Value = DOM-ordered `data-ecsa-facet` of
 *               every `.ecsa-sortable__item` whose checkbox is checked. NOTE:
 *               `search` has no row — a hidden input always submits it, and the
 *               Setup "What to display?" choice owns whether the bar shows it.
 *
 * Conditional regions carry `data-ecsa-reveal-when="<ctrl key>=<value>"` and are
 * shown only while that control reads exactly that value.
 *
 * SECTION + CHOICE AVAILABILITY is a second, coarser axis and uses its own
 * attribute, `data-ecsa-when="<condition>"` (a leading `!` negates). It appears
 * on the subtab nav BUTTONS and on individual choice cards, and names a
 * condition from `panel_state()` rather than a control/value pair — "are filters
 * on", "is there a results block anywhere" — because those answers are derived
 * from several controls at once. The server evaluates it for the first paint and
 * the panel JS maintains it live; `condition_met()` and its JS twin
 * `conditionMet()` are the only evaluators, so the two halves cannot drift.
 *
 * An unavailable section is HIDDEN, never omitted. Its inputs stay in the DOM
 * and keep submitting, because `Settings::save()` walks the schema and
 * `sanitize_field()` reads an absent key as the SHIPPED DEFAULT — dropping a
 * tab's markup would silently reset every setting on it.
 *
 * The theme-preset strip (`[data-ecsa-presets]`) is NOT a control: it reads as
 * nothing and submits nothing. Each `<button type="button" data-ecsa-preset>`
 * carries the triple on `data-ecsa-preset-accent|-text|-bg`; clicking one writes
 * those into the three colour controls and lets the editor's own `input`
 * listener repaint. `aria-pressed` is recomputed from the three current colours,
 * never remembered.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Admin\Settings;

use CoolPlugins\EventsSearch\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CoolPlugins\EventsSearch\Admin\Settings\Settings_Page' ) ) {

	/**
	 * Registers the "Events Search" submenu under the shared Events Addons
	 * dashboard and renders its single-editor settings screen.
	 *
	 * Nothing runs at include time: `init()` is invoked by the Plugin bootstrap,
	 * admin-side only.
	 *
	 * @since 2.0.0
	 */
	final class Settings_Page {

		/**
		 * Menu slug. Part of the deep-link contract shared with the ECA dashboard
		 * and the plugins-list Settings action link, so it is treated as immutable
		 * once shipped.
		 */
		const MENU_SLUG = 'ecsa-settings';

		/**
		 * The one nonce action guarding every write posted from this screen.
		 */
		const NONCE_ACTION = 'ecsa_save_settings';

		/**
		 * The shared cross-plugin consent option. Byte-exact key:
		 * sibling addons and the usage cron read the same literal. Only ever
		 * written here from an explicit checkbox, behind the nonce + capability
		 * gate, and only when the CPFM consent notice has already run (a value is
		 * present) — this screen never introduces a fresh consent surface.
		 */
		const CONSENT_OPTION = 'cpfm_opt_in_choice_cool_events';

		/**
		 * Cross-request flag the TEC List-view integration sets when the active
		 * theme's List template ships no filter-bar slot, so the bar was injected
		 * above the list as a fallback. Read once here to surface a heads-up
		 * notice, then cleared. Byte-exact key: the integration writes the same
		 * literal.
		 */
		const SLOT_MISSING_TRANSIENT = 'ecsa_tec_slot_missing';

		/**
		 * The shared Events Addons dashboard menu slug — the parent this plugin's
		 * submenu hangs off, matched (with our own slug) when scoping notices.
		 */
		const DASHBOARD_MENU_SLUG = 'cool-plugins-events-addon';

		/**
		 * The subtab every fallback lands on. Setup carries no availability
		 * condition, so it is the one section that is always reachable — which is
		 * what makes it a safe destination when the section the panel WANTED to
		 * open turns out to be unavailable.
		 *
		 * @since 2.0.0
		 */
		const SUBTAB_FALLBACK = 'compose';

		/* --------------------------------------------------------------------- *
		 * "What to display?" — the one 2-way shaper
		 *
		 * TWO VALUES, AND THE BAR ALWAYS CARRIES THE SEARCH BOX. This started as
		 * two independent booleans (`show_search` + `show_filters`), whose product
		 * allowed a state nothing could render: NEITHER — a bar with nothing in it.
		 * Folding them into a radio group deleted that; deleting the third choice
		 * deletes the other end of the same argument.
		 *
		 * WHY THERE IS NO "FILTERS ONLY" CHOICE. A filter bar with no search box
		 * has no use case anyone could name: the plugin is a SEARCH bar, the
		 * dropdown navigator is driven by the keyword field, and a facet row on its
		 * own is a narrower version of what TEC's own views already do. It is
		 * removed rather than hidden, so `search` is now true for BOTH values and
		 * nothing downstream has to ask whether a bar has a keyword field.
		 *
		 * CLIENT-ONLY, exactly as the two booleans were. Nothing here is a schema
		 * key: the saved composition is `ecsa[facets]` (edited on the Filters tab),
		 * and this control is initialised FROM that set and shapes only the emitted
		 * shortcode and the preview. `BAR_PARTS_NAME` therefore sits OUTSIDE the
		 * `ecsa[…]` subtree — a radio group needs a shared `name` to be mutually
		 * exclusive at all, and a name inside `ecsa[]` would post a key
		 * `Settings::save()` would have to discard on every submit.
		 *
		 * It IS read on submit, though — see `reduce_facets_to_displayed()`. The
		 * control shapes what renders, so it must also shape what is stored, or a
		 * bar that displays no filters saves a filter list and the coherence rule
		 * in `Settings::validate()` rejects the save over filters the admin just
		 * said not to show.
		 * --------------------------------------------------------------------- */

		/** Search box, no filters. */
		const BAR_PARTS_SEARCH = 'search';

		/** Search box AND the filter bar. */
		const BAR_PARTS_BOTH = 'search_filters';

		/*
		 * NOTE: `BAR_PARTS_FILTERS = 'filters'` — the filter bar with no search box
		 * — was declared here. Deleted, not deprecated: it is a client-only shaper
		 * with no stored value of its own, so there is no row to migrate and no
		 * mapping to keep. A stored facet set that holds filters but not `search`
		 * (the shape the retired choice produced) reads as "search box with
		 * filters" — see `bar_parts_value()`.
		 */

		/**
		 * The radio group's `name`. Deliberately not `ecsa[…]`: see above.
		 */
		const BAR_PARTS_NAME = 'ecsa_bar_parts';

		/**
		 * Hook suffix returned by `add_submenu_page()`, captured so the asset
		 * enqueue can scope itself to exactly this screen.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private static $hook_suffix = '';

		/**
		 * Notices to print at the top of the page after a POST. Each carries a
		 * `type` and a `message`.
		 *
		 * @since 2.0.0
		 * @var array<int, array{type:string, message:string}>
		 */
		private static $notices = array();

		/**
		 * Per-field error messages (field key => message), surfaced inline next to
		 * the offending field.
		 *
		 * @since 2.0.0
		 * @var array<string, string>
		 */
		private static $field_errors = array();

		/**
		 * The values the editor renders FROM: the raw submitted subtree after a
		 * rejected save (so the user never loses their entries), otherwise the
		 * merged persisted settings.
		 *
		 * @since 2.0.0
		 * @var array<string, mixed>|null
		 */
		private static $form_values = null;

		/**
		 * The subtab a submitted error belongs to, so the JS can auto-open it.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private static $error_subtab = '';

		/* --------------------------------------------------------------------- *
		 * Bootstrap
		 * --------------------------------------------------------------------- */

		/**
		 * Register hooks.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 50 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 10 );
			add_action( 'admin_notices', array( __CLASS__, 'maybe_render_slot_missing_notice' ), 10 );
			// Shared action name (Countdown parity). Priority 5 so we can claim
			// ECSA-tagged posts before a sibling's handler wp_die()s the request.
			add_action( 'wp_ajax_cpfm_save_usage_data_sharing', array( __CLASS__, 'save_usage_data_sharing' ), 5 );
		}

		/**
		 * True on our settings page in any locale.
		 *
		 * WP prefixes the screen id with the translated parent title, so a hard-coded
		 * English screen id does not match. The submenu slug is stable.
		 *
		 * @since 2.0.0
		 * @param string $hook Optional hook_suffix from admin_enqueue_scripts.
		 * @return bool
		 */
		public static function is_settings_screen( $hook = '' ) {
			$needle = '_page_' . self::MENU_SLUG;
			if ( is_string( $hook ) && substr( $hook, -strlen( $needle ) ) === $needle ) {
				return true;
			}

			if ( '' !== self::$hook_suffix && is_string( $hook ) && $hook === self::$hook_suffix ) {
				return true;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, $needle ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Add the submenu entry to the shared Events Addons dashboard.
		 *
		 * Priority 50 lands after the ECA dashboard's parent-menu registration
		 * (`admin_menu` 9) and the mid-generation suppression (`admin_menu` 8), so
		 * the parent is guaranteed to exist. The returned hook suffix is captured
		 * so `enqueue_assets()` matches on it exactly.
		 *
		 * The MENU LABEL alone reads "Search & Filter Bar". It
		 * is the only string that changes: the page title, the menu slug
		 * (`ecsa-settings`), the ECA dashboard's `host_slug` (`search`) and the
		 * plugin directory are all part of shipped contracts — the dashboard's
		 * install allowlist keys off the directory and its resolver looks the
		 * addon up by `host_slug` — so renaming any of them would unregister this
		 * plugin from the shared dashboard rather than relabel a menu row.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function register_menu() {
			self::$hook_suffix = (string) add_submenu_page(
				'cool-plugins-events-addon',
				__( 'Events Search & Filter Bar Settings', 'events-search-addon-for-the-events-calendar' ),
				__( 'Search & Filter Bar', 'events-search-addon-for-the-events-calendar' ),
				'manage_options',
				self::MENU_SLUG,
				array( __CLASS__, 'render_page' ),
				50
			);
		}

		/**
		 * Enqueue the screen-scoped admin CSS/JS + the shared dashboard chrome —
		 * only on this page.
		 *
		 * The shared `eca-base.css` is ECSA's OWN byte-identical vendored copy;
		 * it supplies the header/hero/button chrome. Assets are
		 * matched on the exact hook suffix captured at registration.
		 *
		 * @since 2.0.0
		 * @param string $hook_suffix Current admin page hook suffix.
		 * @return void
		 */
		public static function enqueue_assets( $hook_suffix ) {
			if ( '' === self::$hook_suffix || $hook_suffix !== self::$hook_suffix ) {
				return;
			}

			wp_enqueue_style( 'dashicons' );

			wp_enqueue_style(
				'ecsa-eca-base',
				ECSA_URL . 'admin/eca-dashboard/assets/css/eca-base.css',
				array(),
				ECSA_VERSION
			);

			// The real front-end stylesheet — it is scoped entirely to `.ecsa*`
			// classes, so loading it here paints the live-preview mock (which uses
			// those same classes AND the design-token vars/modifier classes)
			// identically to the real bar without touching the rest of wp-admin.
			wp_enqueue_style(
				'ecsa-frontend-preview',
				ECSA_URL . 'assets/css/v2/ecsa-frontend.css',
				array(),
				ECSA_VERSION
			);

			wp_enqueue_style(
				'ecsa-admin-settings',
				ECSA_URL . 'admin/settings/assets/ecsa-admin-settings.css',
				array( 'ecsa-eca-base', 'ecsa-frontend-preview' ),
				ECSA_VERSION
			);

			wp_enqueue_script(
				'ecsa-admin-settings',
				ECSA_URL . 'admin/settings/assets/ecsa-admin-settings.js',
				array( 'wp-i18n' ),
				ECSA_VERSION,
				true
			);

			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations(
					'ecsa-admin-settings',
					'events-search-addon-for-the-events-calendar',
					ECSA_PATH . 'languages'
				);
			}

			// Data (not strings) the JS needs to diff a control against the saved
			// site default (so the shortcode emits only what changed) and to
			// re-render the live bar preview. Strings stay in the JS via wp.i18n.
			wp_localize_script(
				'ecsa-admin-settings',
				'ecsaAdminSettings',
				array(
					'siteDefaults' => self::preview_defaults(),
					// The live preview is rendered by the REAL Renderer through an
					// admin-only route, so it can never drift from the front end.
					// `wp_rest` + the route's own `manage_options` check are the
					// gate; the route only ever renders, never writes.
					'previewUrl'   => esc_url_raw( rest_url( 'ecsa/v1/preview' ) ),
					'restNonce'    => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		/**
		 * The site-wide defaults, shaped for the client (booleans as 1/0), so the
		 * generator can diff a control against the value it would inherit and emit
		 * only the difference, and the preview can resolve any unset token to its
		 * inherited value.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		private static function preview_defaults() {
			$s = Settings::get();

			return array(
				/*
				 * DERIVED FROM THE PLACEMENT, not read from a stored key — there is
				 * no stored key any more. It still travels to the client because the
				 * diff-only generator needs something to compare a bar's effective
				 * type-ahead against: without it every emitted shortcode would carry
				 * a `typeahead="…"` the site already implies.
				 *
				 * This is the SAVED placement's derivation. The panel re-derives from
				 * the LIVE cards on every edit, so a placement changed but not yet
				 * saved still emits the attribute that makes the shortcode behave as
				 * the panel is showing it.
				 */
				'typeahead'            => Settings::derive_typeahead( $s['results_mode'] ),
				// WHERE the filters live: bar_inline · expanded. STRUCTURAL — each
				// one moves the markup — so the preview re-FETCHES rather than
				// re-stamping a class.
				'filters_visibility'   => (string) $s['filters_visibility'],
				// NOTE: `filter_columns` rode here. RETIRED with the axis.
				// HOW every filter TRIGGER looks: text. One vocabulary for every
				// filter, date included — the retired per-filter override map is
				// gone. It still travels even though the panel renders NO control
				// for it, because the generator diffs a shortcode's effective value
				// against the saved one and needs something to compare.
				'filter_style'         => (string) $s['filter_style'],
				// NOTE: `filters_button_style` rode here. WITHDRAWN with the axis —
				// there is no stored value for the generator to diff against, and
				// nothing left for the preview to re-stamp.
				// NOTE: `location_field` rode here. RETIRED with the control.
				'placeholder'          => (string) $s['placeholder'],
				'facets'               => array_values( (array) $s['facets'] ),
				'search_fields'        => array_values( (array) $s['search_fields'] ),
				'view'                 => (string) $s['view'],
				'columns'              => (int) $s['columns'],
				'card_size'            => (int) $s['card_size'],
				'card_fields'          => array_values( (array) $s['card_fields'] ),
				// HOW a card's date line is written. It changes rendered TEXT, so
				// the generator must diff it and the preview must re-FETCH on it —
				// there is no class or custom property that could re-stamp a date.
				'date_format'          => (string) $s['date_format'],
				// The card template. STRUCTURAL like `date_format`: a template swap
				// changes ELEMENTS, so the preview has to re-FETCH; no class or
				// custom property could stamp one in.
				'template'             => (string) $s['template'],
				'per_page'             => (int) $s['per_page'],
				'sort'                 => (string) $s['sort'],
				'time'                 => (string) $s['time'],
				'recurrence'           => (string) $s['recurrence'],
				'results_mode'         => (string) $s['results_mode'],
				// Design tokens (Bar design subtab). The three colours are the
				// only colour inputs; every other shade is derived in CSS.
				'bar_template'         => (string) $s['bar_template'],
				'accent_color'         => (string) $s['accent_color'],
				'text_color'           => (string) $s['text_color'],
				'bg_color'             => (string) $s['bg_color'],
				'button_style'         => (string) $s['button_style'],
				'control_size'         => (int) $s['control_size'],
				'corner_radius'        => (int) $s['corner_radius'],
			);
		}

		/* --------------------------------------------------------------------- *
		 * Page render
		 * --------------------------------------------------------------------- */

		/**
		 * Render the settings screen.
		 *
		 * The capability check is not redundant with the one on `add_submenu_page()`
		 * (that only governs the menu item): the callback is reachable directly, so
		 * it re-checks before emitting anything and before processing any write.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' ) ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Events Search & Filter Bar for The Events Calendar', 'events-search-addon-for-the-events-calendar' ) . '</h1>';
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The settings data layer could not be loaded. Try reinstalling the plugin.', 'events-search-addon-for-the-events-calendar' ) . '</p></div></div>';
				return;
			}

			self::maybe_handle_post();
			?>
			<?php
			/*
			 * The errored subtab, COERCED to one that is available: a save can be
			 * rejected against a field whose section the same configuration has
			 * just made unavailable (`ecsa_facets_need_target` maps to `facets`,
			 * which lives on the Filters tab). Naming a hidden section here would
			 * hand the JS a destination it must refuse.
			 */
			$ecsa_error_subtab = ( '' !== self::$error_subtab ) ? self::open_subtab( self::panel_state() ) : '';
			?>
			<?php
			/*
			 * The shared ECA admin shell (same header/footer/background as the
			 * dashboard and the sibling settings screens). This is the ONLY
			 * header on the screen: the page used to draw a second
			 * `.eca-admin-header` of its own, which was the same brand bar
			 * twice. The context argument is what that copy existed for — the
			 * shell renders it as the `→ <screen>` line under the brand.
			 */
			if ( class_exists( 'ECA_Dashboard_Page' ) ) {
				\ECA_Dashboard_Page::render_admin_header( 'settings', __( 'Events Search & Filter Bar', 'events-search-addon-for-the-events-calendar' ) );
			}
			?>
			<div class="wrap eca-admin-wrap ecsa-settings" data-ecsa-error-subtab="<?php echo esc_attr( $ecsa_error_subtab ); ?>">
				<div class="eca-admin-page">

					<main class="eca-admin-main ecsa-settings__shell">

						<?php
						/*
						 * Review ask, placed here rather than left to `admin_notices`.
						 * That hook prints at the very top of #wpbody-content, which on
						 * this full-bleed panel is ABOVE our own header. The screen is
						 * listed in the framework's notice.defer_screens, so the hook
						 * stays quiet and this explicit call owns the placement — no
						 * $wp_filter surgery, which this plugin does not do.
						 */
						// Leading backslash required: this file is namespaced, so an
						// unqualified static call resolves to
						// CoolPlugins\EventsSearch\Admin\Settings\CPFM_Review_Notice.
						if ( class_exists( 'CPFM_Welcome_Notice' ) ) {
							\CPFM_Welcome_Notice::cpfm_maybe_render( false );
						}
						if ( class_exists( 'CPFM_Review_Notice' ) ) {
							\CPFM_Review_Notice::cpfm_maybe_render( false );
						}
						?>

						<?php
						/*
						 * THE NOTICE ANCHOR — this is why notices used to land under the
						 * hero title.
						 *
						 * wp-admin/js/common.js relocates every notice that is not
						 * `.inline` on DOM-ready:
						 *
						 *     if ( ! $headerEnd.length ) {
						 *         $headerEnd = $( '.wrap h1, .wrap h2' ).first();
						 *     }
						 *     $( 'div.updated, div.error, div.notice' )
						 *         .not( '.inline, .below-h2' ).insertAfter( $headerEnd );
						 *
						 * The selector is DOCUMENT-WIDE, so without a `.wp-header-end`
						 * every notice on the screen — ours, core's, and any third
						 * party's — was dragged to sit directly after our hero <h1>.
						 * Moving these print calls alone would not have fixed it; JS put
						 * them straight back. Note also that the fallback matches `h1` OR
						 * `h2`, so demoting the hero heading would change nothing.
						 *
						 * `.wp-header-end` is the element core provides for exactly this.
						 * With it present, notices land HERE — above the hero — and that
						 * includes third-party notices we cannot add `.inline` to.
						 *
						 * The ECA shell header wrapped around this screen carries the
						 * single `.wp-header-end` for the page — core moves every
						 * notice up there, above the shell nav and the hero alike.
						 * Do not add a second marker here: duplicate markers split
						 * where notices land.
						 */
						?>
						<div class="eca-notices-wrapper ecsa-notices">
								<?php
							/*
							 * Shared-ecosystem notices slot. The ACTION is
							 * a cross-plugin contract — never rename it, never wrap it in
							 * a prefixed hook.
							 *
							 * Fired through Notices::render_shared_notices() rather than
							 * as a bare `do_action`, because that method owns the
							 * ECT_ADMIN_NOTICE_RENDERED once-guard. A bare call here had
							 * no guard, so on this screen the bridge fired twice — once
							 * from `admin_notices` and once from this page body — and
							 * every sibling's notice printed twice, since sibling
							 * handlers do not deduplicate. This screen is now listed in
							 * Notices::SIBLING_NOTICE_PAGES so the hook stands down and
							 * this call is the single owner.
							 */
							if ( class_exists( 'CoolPlugins\EventsSearch\Admin\Notices' ) ) {
								\CoolPlugins\EventsSearch\Admin\Notices::render_shared_notices();
							}
							?>

							<?php self::render_notices(); ?>
						</div>

						<section class="eca-hero eca-hero--compact ecsa-hero">
							<div class="eca-hero__inner ecsa-hero__inner">
								<div class="ecsa-hero__lead">
									<h1><?php esc_html_e( 'Events Search & Filter Bar', 'events-search-addon-for-the-events-calendar' ); ?></h1>
									<p><?php esc_html_e( 'Customize how visitors search and filter events, choose where results appear, then generate shortcodes to display them anywhere on your site.', 'events-search-addon-for-the-events-calendar' ); ?></p>
								</div>
								<?php self::render_hero_card(); ?>
							</div>
						</section>

						<?php
						/*
						 * No-JS fallback. With JS the inactive subpanels are hidden so
						 * only the active one paints; without JS nothing un-hides them,
						 * so reveal every panel stacked — all fields stay reachable and
						 * the single Save button still submits.
						 */
						?>
						<noscript><style>.ecsa-settings .ecsa-subtab-panel[hidden]{display:block !important;}</style></noscript>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="ecsa-settings__form">
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>

							<?php self::render_editor(); ?>

							<?php self::render_footer(); ?>
						</form>

					</main>
				</div>
			</div>
			<?php
			if ( class_exists( 'ECA_Dashboard_Page' ) ) {
				\ECA_Dashboard_Page::render_admin_footer();
			}
		}

		/**
		 * The hero "how to add it" card.
		 *
		 * One shape for everyone, with no editor detection in it. The shortcode
		 * generator on this screen is the only placement surface the plugin ships
		 * (plus the classic widget), and a shortcode drops into every editor and
		 * page builder, so there is nothing left to branch on — the card says the
		 * one true thing and offers the one action beside it.
		 *
		 * TEXT LEFT, ACTION RIGHT. The decorative 38px shortcode glyph that opened
		 * the card is DELETED, not moved: it illustrated the sentence next to it
		 * and nothing else, and the row it occupied is what the button now uses.
		 * `.ecsa-hero__card-icon` went with it — it was declared for this one
		 * element and has no other user in either tree.
		 *
		 * THE BUTTON IS THE EDITION DIFFERENCE. Free sells Pro here; Pro, which has
		 * nothing to sell, points at its documentation instead. That is the whole
		 * divergence — same card, same copy, one different action — and it is the
		 * reason this method is allowlisted as an edition difference rather than
		 * expected to match byte for byte.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function render_hero_card() {
			?>
			<aside class="ecsa-hero__card" aria-label="<?php esc_attr_e( 'How to add the search bar', 'events-search-addon-for-the-events-calendar' ); ?>">
				<div class="ecsa-hero__card-body">
					<h2 class="ecsa-hero__card-title"><?php esc_html_e( 'Add advanced search bar anywhere', 'events-search-addon-for-the-events-calendar' ); ?></h2>
					<p class="ecsa-hero__card-text"><?php esc_html_e( 'Generate shortcode, then paste it in any page.', 'events-search-addon-for-the-events-calendar' ); ?></p>
				</div>
				<?php
				/*
				 * FREE EDITION: the card's action is the upsell, painted in the
				 * same tokens as the locked cards' Pro badge (`.ecsa-pro-badge`)
				 * so the whole screen sells in one colour. `pro_url()` is the same
				 * helper every locked card links through, so the destination and
				 * its campaign tag are declared once — and it degrades the same
				 * way: with no constant there is no link rather than an anchor
				 * pointing back at this screen.
				 */
				$ecsa_pro_url = self::pro_url();
				if ( '' !== $ecsa_pro_url ) :
					?>
				<a class="ecsa-hero__card-cta ecsa-hero__card-cta--pro" href="<?php echo esc_url( $ecsa_pro_url ); ?>" target="_blank" rel="noopener">
					<?php
					/*
					 * `star-filled` rather than a lock or a cart: it is the glyph the
					 * shared Events Addons dashboard already uses for its own
					 * "Activate Pro" action, so the two upsell surfaces a site owner
					 * meets look like one product rather than two teams' guesses.
					 * Decorative — the link text carries the meaning.
					 */
					?>
					<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
					<?php esc_html_e( 'Get Pro', 'events-search-addon-for-the-events-calendar' ); ?>
				</a>
					<?php
				endif;
				?>
			</aside>
			<?php
		}

		/* --------------------------------------------------------------------- *
		 * Admin notices
		 * --------------------------------------------------------------------- */

		/**
		 * Warn when the theme's Events List template dropped our filter-bar slot.
		 *
		 * The TEC List-view integration renders the bar into an injection point
		 * the theme's `list.php` is expected to include. When a theme overrides
		 * that template without the slot, the integration falls back to injecting
		 * the bar above the list and records `SLOT_MISSING_TRANSIENT`. This reads
		 * that flag on the plugin's own admin screens and surfaces a single,
		 * dismissible heads-up — never site-wide, and only to users who could act.
		 *
		 * The flag is one-shot: it is cleared as soon as it is read on a relevant
		 * screen, so the warning does not persist after acknowledgement. The
		 * integration re-arms it on the next front-end List render if the theme
		 * still ships no slot, so a genuinely unresolved condition returns.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function maybe_render_slot_missing_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! self::is_ecsa_admin_screen() ) {
				return;
			}

			if ( false === get_transient( self::SLOT_MISSING_TRANSIENT ) ) {
				return;
			}

			// One-shot: clear now so the warning does not nag after this view.
			delete_transient( self::SLOT_MISSING_TRANSIENT );

			/*
			 * Stale-flag guard. The slot only exists in `header_swap`, which is the
			 * only mode that renders INTO the theme's List template; `replace` never
			 * lets that template run and `off` does nothing at all. So on any other
			 * mode "we injected above the list" no longer describes anything, and a
			 * flag left over from a previous configuration must not be shown.
			 */
			if ( class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' )
				&& Settings::TEC_VIEWS_HEADER_SWAP !== Settings::tec_views_mode() ) {
				return;
			}

			echo '<div class="notice notice-warning is-dismissible ecsa-notice ecsa-notice-slot-missing"><p>';
			echo esc_html__( 'Your theme’s Events List template does not include the filter-bar area, so Events Search injected the bar above the list instead. It still works; a theme update may be needed for perfect placement.', 'events-search-addon-for-the-events-calendar' );
			echo '</p></div>';
		}

		/**
		 * Whether the current admin screen is one this plugin owns (its settings
		 * page or the shared Events Addons dashboard).
		 *
		 * Submenu screen IDs are `{sanitized-parent}_page_{slug}`, and a sibling
		 * can rename the parent, so the menu slug is matched as an ID suffix
		 * rather than compared against a guessed full screen ID.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private static function is_ecsa_admin_screen() {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();
			if ( ! $screen instanceof \WP_Screen ) {
				return false;
			}

			$screen_id = (string) $screen->id;

			foreach ( array( self::MENU_SLUG, self::DASHBOARD_MENU_SLUG ) as $slug ) {
				$needle = '_page_' . $slug;
				$length = strlen( $needle );
				if ( strlen( $screen_id ) >= $length && substr( $screen_id, -$length ) === $needle ) {
					return true;
				}
			}

			return false;
		}

		/* --------------------------------------------------------------------- *
		 * Save handling
		 * --------------------------------------------------------------------- */

		/**
		 * Process a POST to this screen, if any.
		 *
		 * A single nonce + capability gate covers every write. Dispatch is on the
		 * `ecsa_action` submit-button value so the buttons (save, clear cache)
		 * share one form and one nonce.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function maybe_handle_post() {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
			if ( 'POST' !== $method ) {
				return;
			}

			// Verifies the `_wpnonce` field; dies via wp_nonce_ays() on failure.
			check_admin_referer( self::NONCE_ACTION );

			// Re-check the capability at the write, not only at menu render.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to change these settings.', 'events-search-addon-for-the-events-calendar' ) );
			}

			$action = isset( $_POST['ecsa_action'] ) ? sanitize_key( wp_unslash( $_POST['ecsa_action'] ) ) : '';

			if ( 'clear_cache' === $action ) {
				self::handle_clear_cache();
				return;
			}

			/*
			 * RETIRED: the `enable_v2_scope` / `keep_legacy_scope` pair, which
			 * answered the one-time v1.3.6-scope offer. The install-age gate is
			 * gone — the shipped defaults now render the same search-only bar for
			 * an upgrader and a fresh install alike, so there was nothing left to
			 * offer. Both actions are simply unhandled now, and an old form POST
			 * carrying one falls through to the `save` test below and does nothing.
			 */

			if ( 'save' === $action ) {
				self::handle_save();
			}
		}

		/**
		 * Persist the settings subtree and the shared consent choice.
		 *
		 * `Settings::save()` already sanitizes, cross-validates and persists — this
		 * only unslashes the raw `ecsa[]` subtree, feeds it in, and reflects the
		 * result. On a WP_Error the submitted values are held so the form re-renders
		 * with the user's entries, and each error code is mapped to its field for an
		 * inline marker.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function handle_save() {
			// The nonce + capability gate is enforced in maybe_handle_post() before
			// this runs; the ignores below are the per-function annotation for that.
			$input = isset( $_POST['ecsa'] ) ? wp_unslash( $_POST['ecsa'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitized.InputNotSanitized -- nonce verified upstream; Settings::save() sanitizes every value against the schema.
			if ( ! is_array( $input ) ) {
				$input = array();
			}

			$result = Settings::save( self::reduce_facets_to_displayed( $input ) );

			if ( is_wp_error( $result ) ) {
				self::$form_values = self::hydrate_from_submitted( $input );
				self::surface_errors( $result );
				return;
			}

			self::$notices[] = array(
				'type'    => 'success',
				'message' => __( 'Saved as your site default. Next, add the bar to a page: copy a shortcode from the panel on the right and paste it into any page, post, or widget.', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * Make the submitted facet set say the same thing the bar DISPLAYS.
		 *
		 * THE BUG THIS FIXES. "What to display?" is a client-only shaper: it gates
		 * rendering and the generated shortcode, and it never touched the stored
		 * facet list. So choosing "Search box only" still posted
		 * `ecsa[facets] = search,date` — the hidden `search` input plus whatever was
		 * ticked on the Filters tab — and `Settings::validate()`, which asks "are
		 * there facets beyond `search` with nowhere to send their input?", rejected
		 * the save. The admin was told to give their filters a results surface
		 * immediately after saying they did not want filters shown at all.
		 *
		 * WHY HERE AND NOT IN `validate()`. The alternative fix teaches the rule
		 * about the displayed parts and leaves the stored set alone. It was
		 * rejected because `bar_parts` HAS NO STORAGE OF ITS OWN — the facet set is
		 * its storage, and `bar_parts_control()` derives the checked card straight
		 * back out of it. Leave a filter in the row and the choice does not survive
		 * the reload that follows the save: the admin picks "Search box only", the
		 * screen returns showing "Search box with filters", and the only thing the
		 * looser rule bought is that the rejection is silent instead of loud.
		 * Making the submission consistent is what makes the choice round-trip.
		 *
		 * THE COST, STATED PLAINLY. Saving with "Search box only" DISCARDS the
		 * stored filter list; switching filters back on afterwards re-seeds `date`
		 * rather than restoring what was there. That is the price of a shaper with
		 * no storage, and it is paid only on save: within a session the Filters
		 * tab's checkboxes are never touched by this — they are simply not rendered
		 * while `filters_on` is false — so toggling the choice off and on again
		 * before saving preserves the selection exactly.
		 *
		 * ABSENT MEANS "BOTH", mirroring `BAR_PARTS_FALLBACK` in the panel JS: a
		 * POST with no `ecsa_bar_parts` (a future compact panel, a hand-rolled
		 * form) is not reduced, so this can only ever narrow a set the admin
		 * explicitly asked to narrow.
		 *
		 * @since 2.9.0
		 * @param array<string, mixed> $input Raw, unslashed `ecsa[…]` subtree.
		 * @return array<string, mixed> The same subtree, facets reduced if applicable.
		 */
		private static function reduce_facets_to_displayed( array $input ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce + capability verified in maybe_handle_post() before handle_save() runs.
			$parts = isset( $_POST[ self::BAR_PARTS_NAME ] ) ? sanitize_key( wp_unslash( $_POST[ self::BAR_PARTS_NAME ] ) ) : '';

			if ( self::BAR_PARTS_SEARCH !== $parts ) {
				return $input;
			}

			$input['facets'] = array( 'search' );

			return $input;
		}

		/**
		 * Usage-data sharing AJAX (parity with Event Countdown: cap + nonce +
		 * immediate schedule on opt-in, clear on opt-out).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function save_usage_data_sharing() {
			// Shared action name across Events Addons — ignore siblings' posts.
			$plugin = isset( $_POST['plugin'] ) ? sanitize_key( wp_unslash( $_POST['plugin'] ) ) : '';
			if ( 'ecsa' !== $plugin ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'You do not have sufficient permissions to access this page.', 'events-search-addon-for-the-events-calendar' ) );
			}

			check_ajax_referer( 'cpfm_nonce_action', 'nonce' );

			$choice = isset( $_POST['opt_in'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['opt_in'] ) ) ? 'yes' : 'no';

			// Writes OUR override only — touching the shared master would turn
			// this checkbox into a kill switch for every Events Addon.
			update_option( \CoolPlugins\EventsSearch\Plugin::CONSENT_LOCAL, $choice );

			if ( 'yes' === $choice ) {
				if ( ! wp_next_scheduled( 'ecsa_extra_data_update' ) ) {
					wp_schedule_single_event( time(), 'ecsa_extra_data_update' );
				}
			} elseif ( wp_next_scheduled( 'ecsa_extra_data_update' ) ) {
				wp_clear_scheduled_hook( 'ecsa_extra_data_update' );
			}

			wp_send_json_success( 'Saved' );
		}

		/**
		 * Map a WP_Error from `Settings::save()` onto inline field markers, the
		 * errored subtab and top-of-page notices.
		 *
		 * @since 2.0.0
		 * @param \WP_Error $result The rejected save result.
		 * @return void
		 */
		private static function surface_errors( \WP_Error $result ) {
			$field_map = self::error_field_map();

			foreach ( $result->get_error_codes() as $code ) {
				$messages = $result->get_error_messages( $code );
				$message  = ! empty( $messages ) ? (string) $messages[0] : '';

				if ( isset( $field_map[ $code ] ) ) {
					$field = $field_map[ $code ];
					self::$field_errors[ $field ] = $message;
					if ( '' === self::$error_subtab ) {
						self::$error_subtab = self::subtab_of( $field );
					}
				}

				self::$notices[] = array(
					'type'    => 'error',
					'message' => $message,
				);
			}
		}

		/**
		 * Clear the search cache (footer action).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function handle_clear_cache() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Cache' ) ) {
				\CoolPlugins\EventsSearch\Query\Cache::flush();

				self::$notices[] = array(
					'type'    => 'success',
					'message' => __( 'Search cache cleared.', 'events-search-addon-for-the-events-calendar' ),
				);

				return;
			}

			self::$notices[] = array(
				'type'    => 'error',
				'message' => __( 'The search cache component is not available.', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * Build a display value array from a rejected submission so the form
		 * re-renders with the user's entries. Values are still escaped on output.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $input Raw submitted `ecsa[]` subtree.
		 * @return array<string, mixed>
		 */
		private static function hydrate_from_submitted( array $input ) {
			$values = Settings::defaults();

			foreach ( Settings::fields() as $key => $def ) {
				unset( $def );
				if ( array_key_exists( $key, $input ) ) {
					$values[ $key ] = $input[ $key ];
				}
			}

			return $values;
		}

		/**
		 * Map each surfaced WP_Error code to the field it belongs against.
		 *
		 * @since 2.0.0
		 * @return array<string, string>
		 */
		private static function error_field_map() {
			return array(
				/*
				 * `ecsa_results_page_required` was mapped here, back when a
				 * `results_mode = page` save had to point at a published page.
				 * There is no page placement, so the rule and its code are gone.
				 *
				 * `ecsa_tec_views_conflict` was mapped here too, back when
				 * `tec_views` was a bool that could not be combined with a separate
				 * results page. That rule is deleted as well (see
				 * `Settings::validate()`): the two keys answer different questions
				 * and `header_swap` forces its own local placement, so there is no
				 * conflict left to route.
				 */
				'ecsa_facets_need_target' => 'facets',
			);
		}

		/* --------------------------------------------------------------------- *
		 * Conditional panel structure
		 *
		 * Three rules, one vocabulary. A section — or a placement card — declares
		 * the CONDITION it depends on; `condition_met()` is the only thing that
		 * evaluates one, here and (as `conditionMet()`) in the admin JS. Adding a
		 * future condition means adding one entry to `panel_state()` and one
		 * attribute in the markup, never touching three functions.
		 *
		 * VISIBILITY IS PRESENTATIONAL, ALWAYS. An unavailable section keeps its
		 * inputs in the DOM and keeps submitting them: `Settings::save()` walks the
		 * schema and `sanitize_field()` turns an ABSENT key into the shipped
		 * default, so omitting a section's markup would silently RESET every
		 * setting it holds on the next save. Nothing here ever omits a control.
		 * --------------------------------------------------------------------- */

		/**
		 * What the current configuration MEANS, in the two terms the panel's
		 * conditions are written in.
		 *
		 *   filters_on      the Setup tab's "What to display?" choice carries the
		 *                   filter bar — i.e. the saved facet set holds something
		 *                   besides the keyword search. This is the existing
		 *                   control, not a parallel flag: the choice itself is
		 *                   initialised from exactly this test.
		 *   results_placed  the placement is not "Dropdown suggestions only", so a
		 *                   results block exists somewhere.
		 *
		 * NOTE: a third term, `filters_inline`, lived here — "the filters render ON
		 * the bar's own row". Its only consumer was the round-4 item-6 withdrawal
		 * of the `select` TRIGGER style under that placement, and `filter_style`
		 * has one value with no control, so nothing evaluates it. The JS
		 * `Availability` map dropped the same key at the same time; the two must
		 * carry the identical term list or a `data-ecsa-when` the server honoured
		 * would be ignored the moment the runtime took over.
		 *
		 * `results_mode` is returned NARROWED (item 8): with filters on, "Dropdown
		 * suggestions only" is not offered, so a stored `none` reads as the first
		 * choice that IS offered. That keeps the server's first paint identical to
		 * what the JS computes a moment later, and it means `results_placed` can
		 * never contradict the cards the visitor can actually see.
		 *
		 * @since 2.0.0
		 * @return array{filters_on:bool, results_mode:string, results_placed:bool}
		 */
		private static function panel_state() {
			$facets     = array_map( 'strval', (array) self::value( 'facets' ) );
			$filters_on = array() !== array_diff( $facets, array( 'search' ) );
			$where      = (string) self::value_for_enum( 'results_mode', self::field_def( 'results_mode' ) );

			if ( false === self::placement_offered( $where, $filters_on ) ) {
				$where = self::first_offered_placement( $filters_on );
			}

			return array(
				'filters_on'     => $filters_on,
				'results_mode'   => $where,
				'results_placed' => ( 'none' !== $where ),
			);
		}

		/**
		 * Whether a results placement is offered at all (item 8).
		 *
		 * With filters on there must be a results surface, so "Dropdown suggestions
		 * only" is withdrawn — which is what stops an admin composing filters that
		 * have nowhere to send their input.
		 *
		 * @since 2.0.0
		 * @param string $choice     Placement key.
		 * @param bool   $filters_on Whether filters are enabled.
		 * @return bool
		 */
		private static function placement_offered( $choice, $filters_on ) {
			return ! ( true === $filters_on && 'none' === (string) $choice );
		}

		/**
		 * The first placement a configuration still offers — where a withdrawn
		 * selection moves to.
		 *
		 * Read off the schema's own choice order (`none · inline`) rather than
		 * hardcoded, so the server and the JS land on the same card: with filters
		 * on the first offered choice is `inline`, "Below the bar".
		 *
		 * @since 2.0.0
		 * @param bool $filters_on Whether filters are enabled.
		 * @return string
		 */
		private static function first_offered_placement( $filters_on ) {
			$def     = self::field_def( 'results_mode' );
			$choices = isset( $def['choices'] ) ? (array) $def['choices'] : array();

			foreach ( $choices as $choice ) {
				if ( true === self::placement_offered( (string) $choice, $filters_on ) ) {
					return (string) $choice;
				}
			}

			return '';
		}

		/**
		 * Which condition each subtab's availability depends on.
		 *
		 * An empty string means "always available". A leading `!` negates, so one
		 * attribute covers both directions — the same grammar the JS reads off
		 * `data-ecsa-when`.
		 *
		 * @since 2.0.0
		 * @return array<string, string>
		 */
		private static function subtab_conditions() {
			return array(
				'compose' => '',
				// Item 7. Nothing on this tab configures anything while the bar
				// carries no filters.
				'filters' => 'filters_on',
				'bar'     => '',
				// Item 12. "Dropdown suggestions only" renders no results block, so
				// every control here would describe something that does not exist.
				'results' => 'results_placed',
			);
		}

		/**
		 * Evaluate one `data-ecsa-when` condition against the panel state.
		 *
		 * The single evaluator: the nav buttons, the placement cards and the
		 * open-on-load choice all come through here, so they cannot drift apart. An
		 * unknown condition reads as AVAILABLE — a typo must never hide a section
		 * (and silently strand the settings inside it) — and the JS twin makes the
		 * same choice.
		 *
		 * @since 2.0.0
		 * @param string               $spec  Condition, optionally `!`-negated.
		 * @param array<string, mixed> $state Panel state from `panel_state()`.
		 * @return bool
		 */
		private static function condition_met( $spec, array $state ) {
			$spec = (string) $spec;
			if ( '' === $spec ) {
				return true;
			}

			$negate = ( 0 === strpos( $spec, '!' ) );
			$key    = $negate ? substr( $spec, 1 ) : $spec;

			if ( ! array_key_exists( $key, $state ) ) {
				return true;
			}

			$value = ! empty( $state[ $key ] );

			return $negate ? ! $value : $value;
		}

		/**
		 * The subtab the editor opens on: the errored one after a rejected save,
		 * otherwise Setup — coerced to a section that is actually AVAILABLE.
		 *
		 * `error_field_map()` routes `ecsa_facets_need_target` to `facets`, which
		 * lives on the Filters tab; that tab can be unavailable at exactly the
		 * moment the error fires. Without this coercion the panel would open on a
		 * hidden section and the visitor would face an editor with no visible
		 * panel and an error message they could not act on.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $state Panel state from `panel_state()`.
		 * @return string
		 */
		private static function open_subtab( array $state ) {
			$open = ( '' !== self::$error_subtab ) ? self::$error_subtab : self::SUBTAB_FALLBACK;
			$when = self::subtab_conditions();

			if ( ! isset( $when[ $open ] ) || false === self::condition_met( $when[ $open ], $state ) ) {
				return self::SUBTAB_FALLBACK;
			}

			return $open;
		}

		/* --------------------------------------------------------------------- *
		 * The single editor (Compose · Bar design · Filters · Results)
		 * --------------------------------------------------------------------- */

		/**
		 * Render the two-column editor: left = four subtabs of controls; right
		 * (sticky) = a browser-frame live preview + the generated shortcode with
		 * Copy and "Save as default".
		 *
		 * Every control carries a real `ecsa[…]` field name, so submitting the form
		 * persists them as the site defaults; the JS reads the same controls to
		 * drive the preview and to emit only the attributes that differ from the
		 * saved defaults into the shortcode.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function render_editor() {
			$mode    = 'defaults';
			$subtabs = array(
				'compose' => __( 'Setup', 'events-search-addon-for-the-events-calendar' ),
				'filters' => __( 'Filters', 'events-search-addon-for-the-events-calendar' ),
				'bar'     => __( 'Bar design', 'events-search-addon-for-the-events-calendar' ),
				'results' => __( 'Results', 'events-search-addon-for-the-events-calendar' ),
			);

			/*
			 * AVAILABILITY IS SERVER-RENDERED, then maintained by the JS from the
			 * same declared conditions. Rendering it here is what makes the first
			 * paint correct — a JS-only pass would flash every section for one
			 * frame and then remove two of them.
			 */
			$state = self::panel_state();
			$when  = self::subtab_conditions();
			$open  = self::open_subtab( $state );

			printf(
				'<div class="ecsa-editor" data-ecsa-mode="%s" data-ecsa-open-subtab="%s">',
				esc_attr( $mode ),
				esc_attr( $open )
			);
			echo '<div class="ecsa-editor__columns">';

			// --- Left: subtab nav + panels. ---
			echo '<div class="ecsa-editor__main">';
			printf( '<nav class="ecsa-subtab-nav" role="tablist" aria-label="%s">', esc_attr__( 'Editor sections', 'events-search-addon-for-the-events-calendar' ) );
			$subtab_icons = array(
				'compose' => 'screenoptions',
				'filters' => 'filter',
				'bar'     => 'admin-appearance',
				'results' => 'grid-view',
			);
			foreach ( $subtabs as $sub_key => $sub_label ) {
				$spec  = isset( $when[ $sub_key ] ) ? $when[ $sub_key ] : '';
				$avail = self::condition_met( $spec, $state );
				// `$open` is guaranteed available, so an unavailable tab is never
				// the selected one and `aria-selected` stays truthful.
				$is_open = ( $sub_key === $open );

				/*
				 * `hidden` takes the tab OUT OF THE TAB ORDER and out of the
				 * accessibility tree rather than merely dimming it — a tablist
				 * whose tabs cannot be reached is worse than one that is shorter.
				 * `tabindex="-1"` rides along so a stylesheet that ever un-hides
				 * the button still cannot put it back in the sequence.
				 */
				printf(
					'<button type="button" role="tab" id="ecsa-subtab-%1$s" aria-controls="ecsa-subpanel-%1$s" aria-selected="%2$s" class="ecsa-subtab-button%3$s" data-ecsa-subtab="%1$s" data-ecsa-when="%4$s"%5$s><span class="dashicons dashicons-%6$s" aria-hidden="true"></span><span>%7$s</span></button>',
					esc_attr( $sub_key ),
					$is_open ? 'true' : 'false',
					$is_open ? ' is-active' : '',
					esc_attr( $spec ),
					$avail ? '' : ' tabindex="-1" hidden',
					esc_attr( isset( $subtab_icons[ $sub_key ] ) ? $subtab_icons[ $sub_key ] : 'admin-generic' ),
					esc_html( $sub_label )
				);
			}
			echo '</nav>';

			/*
			 * EVERY panel is rendered, including the unavailable ones. They are
			 * `hidden` (as any inactive panel is), never omitted: a `hidden` form
			 * control still submits, so the settings inside an unavailable section
			 * survive a save untouched. Omitting the markup instead would post
			 * nothing for those keys, and `sanitize_field()` reads an absent key as
			 * the SHIPPED DEFAULT — a silent reset of everything on the tab.
			 */
			foreach ( array_keys( $subtabs ) as $sub_key ) {
				printf(
					'<div class="ecsa-subtab-panel%1$s" id="ecsa-subpanel-%2$s" data-ecsa-subtab-panel="%2$s" role="tabpanel" aria-labelledby="ecsa-subtab-%2$s"%3$s>',
					$sub_key === $open ? ' is-active' : '',
					esc_attr( $sub_key ),
					$sub_key === $open ? '' : ' hidden'
				);

				switch ( $sub_key ) {
					case 'compose':
						self::subtab_compose( $mode );
						break;
					case 'bar':
						self::subtab_bar( $mode );
						break;
					case 'filters':
						self::subtab_filters( $mode );
						break;
					case 'results':
						self::subtab_results( $mode );
						break;
				}

				echo '</div>';
			}
			echo '</div>'; // .ecsa-editor__main

			/*
			 * --- Right: browser-frame preview + shortcode + save. ---
			 *
			 * SIDE BY SIDE, deliberately. A full-width row under the editor would
			 * buy the preview more width, but stacked, the controls
			 * and the picture of their result stop being visible together, which is
			 * the entire value of a live preview. Width is not worth that.
			 *
			 * The width problem the move was chasing is real but belongs to the
			 * stylesheet, not the markup: `.ecsa-bar--inline` is a size container
			 * and the inline filter strip needs 560px. See `.ecsa-editor__columns`,
			 * where the left track was narrowed to give the preview the difference.
			 */
			echo '<div class="ecsa-editor__side">';
			self::render_preview_frame();
			echo '</div>';

			echo '</div>'; // .ecsa-editor__columns
			echo '</div>'; // .ecsa-editor
		}

		/**
		 * Compose subtab: what to include, where results appear, search scope, and
		 * the Events-Page (`tec_views`) behaviour.
		 *
		 * "What to display?" is the one per-placement shaping control left here — it
		 * carries no `ecsa[…]` field name and only drives the shortcode + preview.
		 * Everything else on this subtab is a real saved setting, INCLUDING "Where
		 * results appear", which used to be a shaper too.
		 *
		 * THE TYPE-AHEAD TOGGLE IS GONE. It is derived from the placement
		 * (`Settings::derive_typeahead()`), so there is nothing left to toggle: the
		 * two settings were never independent, and the only combination the toggle
		 * added was "no dropdown and no results", a bar that does nothing.
		 *
		 * @since 2.0.0
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function subtab_compose( $mode ) {
			// What to display? — one 2-way choice.
			self::bar_parts_control();

			// Where results appear (SAVED — radio cards writing
			// `ecsa[results_mode]` directly).
			self::results_placement_control();

			// Search scope (saved).
			self::field_chips( 'search_fields', $mode, true );

			/*
			 * A saved `location_field` toggle sat here, switching on an in-bar
			 * searchable place box. That capability is not part of this plugin — no
			 * schema key, no `location` criteria, no `/places` route, no venue-meta
			 * scan. What renders now is the ADVERTISEMENT for it, and nothing else.
			 */
			self::pro_locked_control();

			// Events Page — filter TEC's own List view in place (saved behaviour).
			self::events_page_section( $mode );
		}

		/**
		 * The one WHOLE control that is locked rather than partly locked.
		 *
		 * Every other Pro option in this panel is a value inside a control free also
		 * has values for. The in-bar location box has no free half at all, so there
		 * is nothing to render live beside it — the control itself is the offer.
		 *
		 * THIS INPUT HAS NO `name`, and that is the difference that matters. Every
		 * other locked input carries its real field name, because that is what makes
		 * the two layers demonstrable: the browser omits it (disabled), and the
		 * schema's `choices` would reject it anyway. There is no field here for a
		 * name to belong to — `location_field` is not a key `Settings::schema()`
		 * declares — so giving it one would invent a POST key the sanitizer has
		 * never heard of. Nameless is the stronger guarantee, and it keeps the panel
		 * honest: the string `ecsa[location_field]` appears nowhere in this tree.
		 *
		 * @since 2.8.0
		 * @return void
		 */
		private static function pro_locked_control() {
			$label = __( 'Add location search to the bar', 'events-search-addon-for-the-events-calendar' );
			$id    = 'ecsa-pro-location-label';

			echo '<div class="ecsa-ctrl ecsa-ctrl--pro" data-ecsa-pro="1">';
			echo '<div class="ecsa-ctrl__head"><span class="ecsa-ctrl__label" id="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</span></div>';
			printf(
				'<div class="ecsa-pro-toggle"><input type="checkbox" disabled aria-disabled="true" tabindex="-1" aria-labelledby="%1$s" /><span class="ecsa-toggle__track" aria-hidden="true"></span>%2$s</div>',
				esc_attr( $id ),
				self::pro_lock( self::pro_control_name( $label ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by pro_lock(), which escapes every part.
			);
			echo '</div>';
		}

		/**
		 * "What to display?" — ONE 2-way radio-card group.
		 *
		 * WHAT IT REPLACED, AND WHY THAT IS THE POINT. Two independent switches
		 * ("Search box", "Filters") offered four states, and the fourth — both off —
		 * was a bar with nothing in it. Every consumer had to cope with it:
		 * `Instance` guaranteed at least `search` so the renderer could not emit an
		 * empty shell, and the generator carried a branch for "no bar at all". A
		 * radio group cannot express NEITHER, so the state is not defended against,
		 * it is unrepresentable — and the branch that handled it is deleted.
		 *
		 * "FILTERS ONLY" IS GONE TOO, for the reason recorded on the constants: a
		 * filter bar with no search box has no use case. Both surviving values
		 * carry the keyword field, so `has_filters` is now the ONLY question this
		 * control answers, and it is asked once.
		 *
		 * IT IS STILL A CLIENT-ONLY SHAPER. It reads as `cards` (identical contract
		 * to "Where results appear"), submits nothing into `ecsa[…]`, and is
		 * initialised from the SAVED facet set so the first paint matches the saved
		 * default.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function bar_parts_control() {
			$facets      = array_map( 'strval', (array) self::value( 'facets' ) );
			$has_filters = array() !== array_diff( $facets, array( 'search' ) );
			$current     = self::bar_parts_value( $has_filters );

			echo '<div class="ecsa-ctrl" data-ecsa-field="bar_parts">';
			echo '<div class="ecsa-ctrl__head"><span class="ecsa-ctrl__label" id="ecsa-bar-parts-label">' . esc_html__( 'What to display?', 'events-search-addon-for-the-events-calendar' ) . '</span></div>';

			// The same wide two-track grid "Bar frame" uses. It was the three-track
			// variant while there were three cards; with two, `--3up` would leave a
			// visibly empty column beside them.
			echo '<div class="ecsa-cards-choice ecsa-cards-choice--wide2" data-ecsa-ctrl="bar_parts" data-ecsa-type="cards" role="radiogroup" aria-labelledby="ecsa-bar-parts-label">';
			foreach ( self::bar_parts_choices() as $choice => $label ) {
				self::choice_card( self::BAR_PARTS_NAME, (string) $choice, $label, 'parts-' . $choice, (string) $choice === $current );
			}
			echo '</div>';

			echo '<p class="ecsa-ctrl__help description">' . esc_html__( 'Show the search box on its own, or with filters beside it.', 'events-search-addon-for-the-events-calendar' ) . '</p>';
			echo '</div>';
		}

		/**
		 * The two "What to display?" choices, in the order they escalate.
		 *
		 * A third entry, "Filters only" (`BAR_PARTS_FILTERS`), sat at the end. It
		 * is deleted with the constant — see the constants block.
		 *
		 * @since 2.0.0
		 * @return array<string, string> value => label.
		 */
		private static function bar_parts_choices() {
			return array(
				self::BAR_PARTS_SEARCH => __( 'Search box only', 'events-search-addon-for-the-events-calendar' ),
				self::BAR_PARTS_BOTH   => __( 'Search box with filters', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * Which card a stored facet set lands on.
		 *
		 * TOTAL BY CONSTRUCTION over the TWO declared values, and it takes one
		 * argument because there is only one question left: the keyword field is
		 * part of both choices, so "does the set contain `search`?" no longer
		 * selects anything.
		 *
		 * THAT IS ALSO THE ANSWER FOR A LEGACY "FILTERS ONLY" ROW. A stored set
		 * holding filters but not `search` — the only shape the retired third card
		 * could produce — reads as "Search box with filters": the admin's filter
		 * choices are kept intact and the keyword field returns. Resolving it the
		 * other way ("Search box only") would have silently discarded the filters
		 * they had configured, which is the more destructive of the two readings.
		 *
		 * @since 2.0.0
		 * @param bool $has_filters Whether the bar carries any filter.
		 * @return string One of BAR_PARTS_SEARCH | BAR_PARTS_BOTH.
		 */
		private static function bar_parts_value( $has_filters ) {
			return $has_filters ? self::BAR_PARTS_BOTH : self::BAR_PARTS_SEARCH;
		}

		/**
		 * "Where results appear" — THREE radio cards in one row, and a real saved
		 * setting.
		 *
		 * WHAT CHANGED, AND WHY IT MATTERS. These cards used to carry no field name
		 * at all: they were a client-side shaper called `results_where` with FOUR
		 * choices, and `syncResultsMode()` collapsed them into a hidden
		 * `ecsa[results_mode]` mirror holding only `inline` or `page`. Three of the
		 * four choices therefore saved the same value, and "dropdown suggestions
		 * only" could not survive a reload. The cards now submit
		 * `ecsa[results_mode]` themselves, so the placement round-trips through
		 * `Settings::save()`/`get()` like any other enum, there is no shaper and no
		 * mirror input, and the reveal regions key off the real field.
		 *
		 * TWO CHOICES, in the order they escalate — no results at all, then results
		 * on this page:
		 *
		 *   none    Dropdown suggestions only.
		 *   inline  Below the bar — the panel emits the matched PAIR.
		 *
		 * A third card ("A separate page", plus the page-ID field it revealed) is
		 * not part of this plugin.
		 *
		 * "Elsewhere on this page" is RETIRED, with no mapping (2.0 never shipped).
		 * "Below the bar" already covers it: the results shortcode is a separate tag
		 * the author pastes wherever they like — directly under the bar, or at the
		 * bottom of the page. THE PLUGIN NEVER ENFORCED THE POSITION; only the paste
		 * location did, so the two cards were one card wearing two labels.
		 *
		 * ONE contextual notice sits under the group and swaps with the selection —
		 * one `data-ecsa-reveal-when` line per choice inside a single box, so the
		 * panel never grows a stack of always-visible explanations.
		 *
		 * THE CHOICES NARROW. With filters enabled there must be
		 * somewhere for a filtered query to land, so "Dropdown suggestions only" is
		 * withdrawn and the group renders two cards. The withdrawn card carries
		 * `data-ecsa-when="!filters_on"` so the JS maintains the same rule live, and
		 * — this is the part that is easy to get wrong — the SELECTION MOVES with
		 * it: `readCheckedRadio()` uses `querySelector('input:checked')` and does
		 * not care that an element is `hidden`, so a hidden-but-checked card would
		 * go on silently driving the whole configuration.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function results_placement_control() {
			$def = self::field_def( 'results_mode' );
			// The NARROWED placement (item 8), from the one helper the nav and the
			// JS also read, so the checked card and the tab availability agree.
			$state = self::panel_state();
			$where = (string) $state['results_mode'];
			$err   = isset( self::$field_errors['results_mode'] );

			printf( '<div class="ecsa-ctrl%s" data-ecsa-field="results_mode">', $err ? ' ecsa-ctrl--error' : '' );
			echo '<div class="ecsa-ctrl__head"><span class="ecsa-ctrl__label" id="ecsa-results-where-label">' . esc_html__( 'Where should search results appear?', 'events-search-addon-for-the-events-calendar' ) . '</span></div>';

			/*
			 * THREE-UP, kept as the variant even though two cards render. The
			 * stylesheet already drops this group to two tracks whenever a card is
			 * withdrawn (`!filters_on` withdraws "none"), which is exactly the
			 * layout two cards need — so the variant that KNOWS how to narrow is
			 * the right one, and a `--wide2` here would have no withdrawal rule.
			 */
			echo '<div class="ecsa-cards-choice ecsa-cards-choice--3up" data-ecsa-ctrl="results_mode" data-ecsa-type="cards" role="radiogroup" aria-labelledby="ecsa-results-where-label">';
			foreach ( (array) $def['choices'] as $choice ) {
				$choice = (string) $choice;
				self::choice_card(
					'ecsa[results_mode]',
					$choice,
					self::choice_label( 'results_mode', $choice ),
					'where-' . $choice,
					$choice === $where,
					// Item 8: suggestions-only exists only while filters are off.
					'none' === $choice ? '!filters_on' : '',
					$state
				);
			}
			/*
			 * The Pro placement, LAST. The ordering is load-bearing here more than
			 * anywhere else in the panel: this is the one group that still WITHDRAWS
			 * a card (`none`, once filters are on), and `syncChoiceCards()` moves the
			 * selection to the first card that is not hidden. A locked card ahead of
			 * `inline` would become that landing spot — a disabled radio driving the
			 * whole configuration, which is the exact failure the withdrawal
			 * machinery exists to prevent. It renders after every real choice, and
			 * the JS additionally refuses to land on a disabled input.
			 */
			self::pro_choice_cards( 'results_mode', 'ecsa[results_mode]', __( 'Where should search results appear?', 'events-search-addon-for-the-events-calendar' ) );
			echo '</div>';

			if ( $err ) {
				printf( '<p class="ecsa-ctrl__error" role="alert">%s</p>', esc_html( self::$field_errors['results_mode'] ) );
			}

			/*
			 * ONE contextual notice: exactly one line is visible at a time.
			 *
			 * No static help line under this box: a line describing every choice
			 * at once, directly under a line describing the one actually made,
			 * would say the same thing twice. Each note states the derived
			 * suggestions behaviour for its OWN choice instead.
			 */
			echo '<div class="ecsa-contextnote">';
			$notes = array(
				'none'   => __( 'Show live event suggestions in a dropdown instantly as visitors type in the search bar.', 'events-search-addon-for-the-events-calendar' ),
				// Picking this one turns the typing dropdown OFF, and this is the
				// only place the panel says so. Without it an admin reads the
				// change as a bug.
				'inline' => __( 'Show search results by placing the results shortcode below the search bar or anywhere on the same page.', 'events-search-addon-for-the-events-calendar' ),
			);
			foreach ( $notes as $choice => $note ) {
				printf(
					'<p class="ecsa-contextnote__line" data-ecsa-reveal-when="results_mode=%1$s"%2$s>%3$s</p>',
					esc_attr( $choice ),
					$choice === $where ? '' : ' hidden',
					esc_html( $note )
				);
			}
			echo '</div>';

			/*
			 * NOTE: a `data-ecsa-reveal-when="results_mode=page"` region sat here,
			 * revealing a plain page-ID field for the dedicated results page. There
			 * is no page placement and no `results_page_id`, so there is nothing to
			 * reveal.
			 */

			echo '</div>';
		}

		/**
		 * Events Page section (Compose subtab).
		 *
		 * `tec_views` — WHAT WE DO TO THE EVENTS CALENDAR'S OWN /events/ PAGE.
		 * Two radio cards rather than an on/off toggle:
		 *
		 *   off          leave the events page exactly as TEC renders it.
		 *   header_swap  keep TEC's results; swap only its header controls (the
		 *                search form, the List/Month/Day nav and the date nav) for
		 *                our bar. The page heading, breadcrumbs and TEC's own
		 *                messages stay exactly where they are.
		 *
		 * A third card — our bar AND our results INSTEAD of TEC's whole list — is
		 * not part of this plugin.
		 *
		 * IT IS NOT MUTUALLY EXCLUSIVE WITH THE RESULTS PLACEMENT, and the warning
		 * that said so is gone with the rule. The two settings answer different
		 * questions — "where do the results for a bar I PLACE appear" versus "what
		 * happens on the events page I did not place" — and `header_swap` forces
		 * the local placement it needs regardless, so the combination is coherent.
		 * See `Settings::validate()` for the full note.
		 *
		 * @since 2.0.0
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function events_page_section( $mode ) {
			echo '<h3 class="ecsa-subhead">' . esc_html__( 'Customize Default Events Page? - /events/', 'events-search-addon-for-the-events-calendar' ) . '</h3>';

			// Laid out on the same grid "Where results appear" uses — this is the
			// other structural choice on the tab, so it reads as one. `tec-`
			// namespaces the wireframes (`card_art()`).
			self::field_choice_cards( 'tec_views', $mode, 'ecsa-cards-choice--3up', 'tec-' );

			/*
			 * ONE contextual note, one visible line at a time — the same pattern the
			 * placement cards use. It says what each mode DOES rather than repeating
			 * the labels, because "replace" and "header swap" are exactly the two
			 * words an admin cannot infer the behaviour from.
			 *
			 * `tec_views` carries no static help line (no `help` in `field_meta()`).
			 * The warning it would hold — LIST VIEW ONLY, so setting this on a
			 * Month-view site does nothing and nothing on the page says why — lives
			 * in the two notes it actually applies to. `off` does not need it,
			 * which is the advantage of a note that follows the selection.
			 */
			$current = (string) self::value_for_enum( 'tec_views', self::field_def( 'tec_views' ) );
			$notes   = array(
				'off'         => __( 'Keep the default events page unchanged. You can use search bar on other pages via shortcode.', 'events-search-addon-for-the-events-calendar' ),
				// The List-view-only caveat is why this setting appears to do
				// nothing on a site whose events page opens on Month, and this
				// note is the only place the panel says so.
				'header_swap' => __( 'Replace only the default search and filter bar. Event results below will continue using the default layout.', 'events-search-addon-for-the-events-calendar' ),
			);

			echo '<div class="ecsa-contextnote">';
			foreach ( $notes as $choice => $note ) {
				printf(
					'<p class="ecsa-contextnote__line" data-ecsa-reveal-when="tec_views=%1$s"%2$s>%3$s</p>',
					esc_attr( $choice ),
					$choice === $current ? '' : ' hidden',
					esc_html( $note )
				);
			}
			echo '</div>';

			/*
			 * THE OWNER'S OWN HEADING, revealed with the mode it belongs to.
			 *
			 * `data-ecsa-reveal-when` is the same mechanism the contextual notes
			 * above use, so the field appears exactly when the setting it depends
			 * on is in play. Hiding it is a courtesy, not the guarantee: the
			 * renderer checks the mode itself, because a hidden control whose
			 * value still applies is how a setting comes back from the dead.
			 */
			printf(
				'<div class="ecsa-reveal" data-ecsa-reveal-when="tec_views=%1$s"%2$s>',
				esc_attr( Settings::TEC_VIEWS_HEADER_SWAP ),
				Settings::TEC_VIEWS_HEADER_SWAP === $current ? '' : ' hidden'
			);
			self::field_text( 'tec_page_title', $mode, __( 'e.g. Upcoming Events', 'events-search-addon-for-the-events-calendar' ) );
			echo '</div>';
		}

		/**
		 * Bar design subtab: placeholder · frame · the three colours · button
		 * style · bar size · corner rounding.
		 *
		 * Six controls, no sub-choices: the frame is two cards, the colours are one
		 * always-on row, and size/rounding are sliders. Everything else (border,
		 * hover, muted text, soft fill, on-accent text) is derived in CSS from the
		 * three colours, so there is nothing more to configure.
		 *
		 * @since 2.0.0
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function subtab_bar( $mode ) {
			self::field_text( 'placeholder', $mode );

			self::field_choice_cards( 'bar_template', $mode, 'ecsa-cards-choice--wide2' );
			self::color_row( $mode );
			self::field_segmented( 'button_style', $mode );
			self::field_segmented( 'design_sizing', $mode );

			/*
			 * HIDDEN, never omitted. The sliders keep submitting while the gate is
			 * off, so a stored 130 survives being switched off and comes back
			 * untouched when it is switched on again — the panel contract.
			 */
			$sizing_on = ( 'on' === (string) self::value_for_enum( 'design_sizing', self::field_def( 'design_sizing' ) ) );

			echo '<div class="ecsa-reveal" data-ecsa-reveal-when="design_sizing=on"' . ( $sizing_on ? '' : ' hidden' ) . '>';
			self::field_slider( 'control_size', $mode, 5, '%' );
			self::field_slider( 'corner_radius', $mode, 1, 'px' );
			echo '</div>';
		}

		/**
		 * Filters subtab: which filters (and their order) · where they sit · how
		 * the in-bar Filters toggle looks.
		 *
		 * The keyword search has NO row in the sortable list — the Setup tab's
		 * "What to display?" choice owns it — but `search` is still submitted by a hidden
		 * input inside `field_sortable()` so the stored set is unchanged.
		 *
		 * TWO axes, and they are deliberately independent:
		 *
		 *   `filters_visibility` = WHERE the filters live. Two meanings: on the
		 *   bar's own row (`bar_inline`), or no triggers at all with every filter
		 *   permanently open (`expanded`). Two more — behind a Filters button, and
		 *   under the bar — are Pro, and render as LOCKED cards.
		 *
		 * A `filters_button_style` control ("Filters button style", four cards in a
		 * `--quad` row behind a `filters_visibility=bar_inline` reveal) sat here.
		 * WITHDRAWN, and the reason is worth keeping because the control looked
		 * perfectly reasonable: the button it styled renders ONLY in `bar_inline`'s
		 * COLLAPSED state, which the bar enters below 560px, and the admin preview
		 * beside this panel is roughly 740px wide. So the cards were reachable,
		 * legible and changed nothing an admin could see while choosing between
		 * them. The button keeps the shipped look.
		 *
		 * `filter_style` (HOW one trigger looks) HAS NO CONTROL HERE, deliberately.
		 * It is still a stored setting, still read by the renderer and still
		 * carried by REST and the shortcode — but it has ONE value, so a control
		 * would be a card group with nothing to choose between: an axis that looks
		 * broken rather than one that looks settled. The field is not `hidden` in
		 * the markup, it is simply not rendered.
		 *
		 * Because the trigger is the bar's visual language it is global: there is
		 * no per-filter style control either. What each panel CONTAINS still
		 * differs per filter, but that follows from the filter's own nature (a date
		 * needs presets) and is never a setting.
		 *
		 * @since 2.0.0
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function subtab_filters( $mode ) {
			self::field_sortable( 'facets', $mode );

			// WHERE the filters sit — a placement question, so radio cards with
			// wireframes (the same shape as "Where results appear").
			self::field_choice_cards( 'filters_visibility', $mode, 'ecsa-cards-choice--wide2', 'plc-' );

			// NOTE: a "Filters per row" slider (`filter_columns`) sat here.
			// RETIRED — the filters flow one after another with tight spacing
			// instead of being forced onto an N-column grid, which crushed long
			// trigger labels and stranded short ones in half-empty cells.

			/*
			 * NOTE: a "How filters look" card group for `filter_style` sat here —
			 * four wireframes of the trigger itself, with `select` withdrawn under
			 * `bar_inline` (the one pairing the renderer cannot express) and the
			 * whole control revealed only when the placement actually draws
			 * triggers. `filter_style` has ONE value now, so there is nothing to
			 * choose between and the control is NOT RENDERED AT ALL. The stored
			 * value is untouched, the renderer still reads it, and the generator
			 * still diffs it — this is a missing control, not a missing setting.
			 */

			/*
			 * NOTE: a "Filters button style" card group sat here, inside an
			 * `.ecsa-reveal` keyed on `filters_visibility=bar_inline`. BOTH are
			 * withdrawn — the control AND its wrapper, because a reveal region with
			 * nothing inside it is a region that can only ever flicker.
			 *
			 * The reveal condition is the whole story. The in-bar Filters button
			 * exists only while `bar_inline` has COLLAPSED, which is a
			 * container-query state below 560px; the live preview beside this panel
			 * renders at roughly 740px. So the reveal was honest — the control did
			 * belong to `bar_inline` — and still useless, because the one width
			 * where the setting is visible is a width the admin never sees while
			 * setting it. Rather than reveal it under a narrower condition nobody
			 * could satisfy, the axis is gone and the button keeps its shipped look.
			 */
		}

		/**
		 * Results subtab: view · columns (grid only) · card size · items to show ·
		 * per page · sort · which events · recurrence.
		 *
		 * @since 2.0.0
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function subtab_results( $mode ) {
			self::field_choice_cards( 'view', $mode );
			/*
			 * WHICH card template. Sits directly under `view` because the two
			 * together are "what a result looks like"; the glyph prefix keeps its
			 * wireframes away from `bar_template`'s, which frame the SEARCH BOX and
			 * happen to share the word.
			 */
			self::field_choice_cards( 'template', $mode, '', 'card-' );

			$is_grid = ( 'grid' === (string) self::value_for_enum( 'view', self::field_def( 'view' ) ) );
			echo '<div class="ecsa-reveal" data-ecsa-reveal-when="view=grid"' . ( $is_grid ? '' : ' hidden' ) . '>';
			self::field_slider( 'columns', $mode );
			echo '</div>';

			self::field_slider( 'card_size', $mode, 5, '%' );
			self::field_chips( 'card_fields', $mode, true );
			// HOW the date on a card is written. Its choice labels ARE worked
			// examples (see choice_labels()), so an admin never has to decode
			// `F j, Y` to know what they are picking.
			self::field_select( 'date_format', $mode );
			self::field_slider( 'per_page', $mode );
			self::field_select( 'sort', $mode );
			self::field_segmented( 'time', $mode );
			self::field_segmented( 'recurrence', $mode );
		}

		/* --------------------------------------------------------------------- *
		 * Right column: browser-frame preview + shortcode + footer
		 * --------------------------------------------------------------------- */

		/**
		 * The browser-frame live preview + the generated shortcode.
		 *
		 * The frame chrome (traffic-light dots + an animated "Live preview" dot) is
		 * decorative. The bar and results INSIDE it are rendered by the real
		 * `Renderer`, fetched from the admin-only `ecsa/v1/preview` route — so the
		 * preview is markup-identical to the front end by construction rather than
		 * by a JS mock kept in sync by hand. `[data-ecsa-preview]` is the element
		 * the response turns into the `.ecsa` wrapper.
		 *
		 * It is `aria-hidden` because it duplicates controls that already exist in
		 * the editor beside it; a screen-reader user configures via the real form,
		 * not this picture.
		 *
		 * Below the frame: the generated shortcode (filled by JS with textContent,
		 * never innerHTML), a Copy button and a "Save as site default" submit that
		 * posts the whole editor.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function render_preview_frame() {
			echo '<div class="ecsa-preview-frame">';

			echo '<div class="ecsa-preview-topbar">';
			echo '<span class="ecsa-preview-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
			/*
			 * Say that this is a demo, right in the chrome — and briefly: the
			 * control that acts on it sits immediately to the right, so the
			 * sentence only has to name what the region IS.
			 *
			 * The length is a constraint rather than a style note: this bar is
			 * ~28px tall and carries the copy control, which may not be pushed
			 * out or wrapped. The
			 * stylesheet gives this line `flex: 1 1 auto` + `min-width: 0` and an
			 * ellipsis and everything after it `flex: 0 0 auto`, so the DEMO line
			 * is what gives way at narrow widths — never the actions.
			 */
			echo '<span class="ecsa-preview-demo">' . esc_html__( 'Demo preview with dummy sample events', 'events-search-addon-for-the-events-calendar' ) . '</span>';
			/*
			 * COPY WHAT IS ON SCREEN, one control. It reads the SAME visible-box
			 * set `[data-ecsa-copy-both]` reads — one tag or two, whatever the
			 * current configuration emits — through the panel's one copy helper,
			 * so the insecure-origin fallback, the "Copied" swap and the live-region
			 * announcement are inherited rather than reimplemented.
			 *
			 * It is NOT `data-ecsa-copy`: that attribute means "copy the box I am
			 * inside", and this button is inside no box. The distinct hook is what
			 * keeps `initCopy()`'s per-box loop from binding it to the first
			 * shortcode on the page.
			 *
			 * `aria-label` names the whole action; the visible label is swapped to
			 * "Copied" in place, and the announcement goes through the panel's one
			 * polite live region rather than through this button.
			 */
			printf(
				'<button type="button" class="ecsa-preview-copy" data-ecsa-copy-visible aria-label="%1$s"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="ecsa-preview-copy__label" data-ecsa-copy-label>%2$s</span></button>',
				esc_attr__( 'Copy the shortcodes shown below the preview', 'events-search-addon-for-the-events-calendar' ),
				esc_html__( 'Copy Shortcode for Real View', 'events-search-addon-for-the-events-calendar' )
			);
			/*
			 * NO "Live preview" badge, and no status dot. Both claimed the region
			 * was live, and it is not: it draws SAMPLE events, never the site's
			 * own. The demo line to the left already names what this is, so the
			 * badge was a second, less accurate answer to the same question.
			 * Removing it also leaves the copy control last, where the eye lands
			 * after reading the line that describes the region.
			 */
			echo '</div>';

			echo '<div class="ecsa-preview-viewport">';
			// A sizer between the viewport and the stage: `transform` leaves layout
			// alone, so without it the wrapper reserves the UNSCALED height and
			// leaves a gap under a scaled-down preview.
			echo '<div class="ecsa-preview-sizer" data-ecsa-preview-sizer>';
			echo '<div class="ecsa-preview-stage" data-ecsa-preview-stage>';
			echo '<div class="ecsa-preview" data-ecsa-preview aria-hidden="true"></div>';
			/*
			 * The in-stage `.ecsa-preview-note` ("Sample events — your real events
			 * appear on the page itself.") is DELETED with item 10, not moved. The
			 * chrome line above now says the same thing a few pixels higher, and it
			 * says it where the frame already announces what this region IS — two
			 * disclaimers inside one frame is the same duplication item 3 removed
			 * from the control rows. It also carried no stylesheet rule of its own,
			 * so it printed as an unstyled paragraph under the mock.
			 */
			echo '</div>'; // .ecsa-preview-stage
			echo '</div>'; // .ecsa-preview-sizer
			echo '</div>'; // .ecsa-preview-viewport

			echo '</div>'; // .ecsa-preview-frame

			/*
			 * --- Shortcode + actions. ---
			 *
			 * TWO SLOTS, EACH INDEPENDENTLY COPYABLE. "Below the bar" and "A
			 * separate page" both produce a PAIR — a bar tag and a results tag —
			 * and the two go in different places, often on different pages. One
			 * code block with both lines in it and a single Copy button made the
			 * author separate them by hand, which is exactly where a shared
			 * `target` gets dropped and the pair silently stops behaving as one
			 * app.
			 *
			 * The results slot starts hidden and the JS reveals it whenever a
			 * results tag is emitted, so the suggestions-only placement shows one
			 * box and one button, as it should.
			 */
			echo '<div class="ecsa-shortcode-panel">';
			echo '<h2 class="ecsa-side-heading">' . esc_html__( 'Shortcode', 'events-search-addon-for-the-events-calendar' ) . '</h2>';

			self::shortcode_slot(
				'bar',
				__( 'Search bar shortcode', 'events-search-addon-for-the-events-calendar' ),
				__( 'Copy the search bar shortcode', 'events-search-addon-for-the-events-calendar' ),
				false
			);
			self::shortcode_slot(
				'results',
				__( 'Results shortcode', 'events-search-addon-for-the-events-calendar' ),
				__( 'Copy the results shortcode', 'events-search-addon-for-the-events-calendar' ),
				true
			);

			echo '<p class="ecsa-shortcode-hint description" data-ecsa-pair-note hidden>' . esc_html__( 'Paste the search bar on a page that should search, then add the results shortcode below it.', 'events-search-addon-for-the-events-calendar' ) . '</p>';
			

			/*
			 * "Copy both" sits AFTER Save and is secondary to it, because the pair is
			 * the case where copying one line at a time is the mistake: the two tags
			 * share a link id, and an author who copies the bar, navigates away and
			 * comes back for the results is the author who ends up with two
			 * shortcodes that do not talk to each other.
			 *
			 * It starts `hidden` and the JS reveals it only when TWO tags are
			 * actually emitted. Suggestions-only emits just the bar, and a "Copy
			 * both" that copies one thing is a lie — so rather than disable it or
			 * quietly copy a single line under a plural label, it is not there at
			 * all. The per-box control right above it already covers that case, so
			 * nothing is lost and there is no puzzle about a dead button.
			 */
			echo '<div class="ecsa-copy-row">';
			printf( '<button type="submit" name="ecsa_action" value="save" class="button button-primary ecsa-save-default"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span>%s</span></button>', esc_html__( 'Save as default settings', 'events-search-addon-for-the-events-calendar' ) );
			printf(
				'<button type="button" class="button ecsa-copy-both" data-ecsa-copy-both hidden><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span data-ecsa-copy-label>%s</span></button>',
				esc_html__( 'Copy both shortcodes', 'events-search-addon-for-the-events-calendar' )
			);
			echo '<span class="ecsa-copy-feedback" role="status" aria-live="polite" data-ecsa-copy-status></span>';
			echo '</div>';

			// NOTE: a second `data-ecsa-page-note` hint sat here, for the page
			// placement ("paste the results shortcode on your results page").
			// Both tags land on the same page now, so the pair note says it all.
			echo '<p class="ecsa-shortcode-hint description"><span class="dashicons dashicons-info" aria-hidden="true"></span>' . esc_html__( ' Save as default to apply these settings to the default events listing page and use them as the default values whenever search & filter bar shortcodes are used without attributes.', 'events-search-addon-for-the-events-calendar' ) . '</p>';

			echo '</div>';
		}

		/**
		 * One copyable shortcode box: a label, the generated tag, and its own Copy
		 * button.
		 *
		 * The `<code>` carries `data-ecsa-shortcode` — the same hook the generator
		 * has always written to — and the wrapper carries
		 * `data-ecsa-shortcode-item`, which is what lets the JS route each emitted
		 * line to the right box by TAG (never by index: with the search box and
		 * filters both off, the only line emitted is a results tag, and an
		 * index-based fill would have printed it under "Search bar").
		 *
		 * @since 2.5.0
		 * @param string $key        Slot key — `bar` | `results`.
		 * @param string $label      Visible label above the box.
		 * @param string $copy_label Accessible name for this box's Copy button.
		 * @param bool   $hidden     Whether the slot starts hidden.
		 * @return void
		 */
		private static function shortcode_slot( $key, $label, $copy_label, $hidden ) {
			printf(
				'<div class="ecsa-shortcode-item" data-ecsa-shortcode-item="%1$s"%2$s>',
				esc_attr( $key ),
				$hidden ? ' hidden' : ''
			);
			printf( '<span class="ecsa-shortcode-item__label">%s</span>', esc_html( $label ) );

			/*
			 * The row is the POSITIONING CONTEXT for the copy control, which now
			 * floats over the code instead of sitting beside it — the box is full
			 * width, so a button in the flow would have cost a column of it on
			 * every line.
			 *
			 * The button stays AFTER the <code> in source order on purpose. Visually
			 * it is top-right, but a keyboard user should meet the code before the
			 * control that acts on it, and reversing the DOM to match the paint
			 * would put "Copy" before the thing it copies.
			 */
			echo '<div class="ecsa-shortcode-item__row">';
			echo '<code class="ecsa-shortcode-output" data-ecsa-shortcode></code>';

			/*
			 * `aria-label` carries the slot ("Copy the results shortcode") so the two
			 * buttons are distinguishable out of context, while the visible label is
			 * the short "Copy" the design wants. The label span is swapped to
			 * "Copied" in place by the JS; the announcement goes through the panel's
			 * one polite live region rather than through this button, because
			 * changing an element's own text while it holds focus is announced
			 * inconsistently across screen readers.
			 */
			printf(
				'<button type="button" class="ecsa-shortcode-item__copy" data-ecsa-copy aria-label="%1$s"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span data-ecsa-copy-label>%2$s</span></button>',
				esc_attr( $copy_label ),
				esc_html__( 'Copy', 'events-search-addon-for-the-events-calendar' )
			);
			echo '</div>';
			echo '</div>';
		}

		/**
		 * The page footer: a small "Clear search cache" action, then the shared
		 * data-sharing consent block (only when consent has already been collected).
		 *
		 * THE ORDER IS DELIBERATE. The action is what an admin comes to the
		 * footer for; the consent row is a standing preference they read once.
		 * Actions first, standing preference last, full width.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function render_footer() {
			echo '<footer class="ecsa-page-footer">';

			echo '<div class="ecsa-footer-actions">';
			printf(
				'<button type="submit" name="ecsa_action" value="clear_cache" class="button-link ecsa-clear-cache">%s</button>',
				esc_html__( 'Clear search cache', 'events-search-addon-for-the-events-calendar' )
			);
			echo '<span class="ecsa-footer-hint description">' . esc_html__( 'Deletes cached results and filter counts. Safe any time — the cache rebuilds on the next search.', 'events-search-addon-for-the-events-calendar' ) . '</span>';
			echo '</div>';

			self::render_consent_block();

			echo '</footer>';
		}

		/**
		 * The CPFM-style data-sharing consent block.
		 *
		 * Rendered only when a consent value already exists (the shared CPFM notice
		 * has run). Toggling the checkbox saves immediately via the shared CPFM
		 * AJAX action (parity with Event Countdown) — it is not part of the
		 * settings form POST.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function render_consent_block() {
			/*
			 * Two-level consent: the shared corner popup is the MASTER for every
			 * Events Addon, and this checkbox is OUR override — unticking it stops
			 * our data and nobody else's. Hidden until the popup has been answered,
			 * and inherits it until this plugin has a choice of its own.
			 */
			if ( ! \CoolPlugins\EventsSearch\Plugin::consent_answered() ) {
				return;
			}
			$on = \CoolPlugins\EventsSearch\Plugin::data_sharing_enabled();
			?>
			<div class="ecsa-data-sharing">
				<label class="ecsa-data-sharing__opt">
					<input type="checkbox" class="ecsa-data-sharing__box" id="ecsa-cpfm-data-sharing" <?php checked( $on ); ?> />
					<span class="ecsa-data-sharing__text"><?php esc_html_e( 'Help us make this plugin more compatible with your site by sharing non-sensitive site data. ', 'events-search-addon-for-the-events-calendar' ); ?></span>
				</label>
				<a href="#" class="cpfm-see-terms ecsa-see-terms">[<?php esc_html_e( 'See terms', 'events-search-addon-for-the-events-calendar' ); ?>]</a>
				<p class="description ecsa-data-sharing__scope"><?php esc_html_e( 'This setting controls usage data sharing for Events Search & Filterbar only. Other Events Addons keep their own choice.', 'events-search-addon-for-the-events-calendar' ); ?></p>
				<div class="ecsa-terms-box ecsa-data-sharing__body" style="display: none;">
					<p><?php esc_html_e( "Opt in to receive email updates about security improvements, new features, helpful tutorials, and occasional special offers. We'll collect:", 'events-search-addon-for-the-events-calendar' ); ?><a href="https://my.coolplugins.net/terms/usage-tracking/" target="_blank" rel="noopener"> <?php esc_html_e( 'Click Here', 'events-search-addon-for-the-events-calendar' ); ?></a></p>
					<ul>
						<li><?php esc_html_e( 'Your website home URL and WordPress admin email.', 'events-search-addon-for-the-events-calendar' ); ?></li>
						<li><?php esc_html_e( 'To check plugin compatibility, we will collect the following: list of active plugins and themes, PHP, MySQL and WordPress versions, memory limit, whether the site is multisite, and the site language. ', 'events-search-addon-for-the-events-calendar' ); ?></li>
					</ul>
				</div>
			</div>
			<?php
		}

		/* --------------------------------------------------------------------- *
		 * Modern control renderers (schema-driven)
		 * --------------------------------------------------------------------- */

		/**
		 * Open a control row, tagging errors so the offending field is flagged.
		 *
		 * @since 2.0.0
		 * @param string $key   Field key.
		 * @param string $mode  Editor mode.
		 * @param string $label Visible label.
		 * @return void
		 */
		private static function ctrl_open( $key, $mode, $label ) {
			$err = ( isset( self::$field_errors[ $key ] ) ) ? ' ecsa-ctrl--error' : '';
			printf( '<div class="ecsa-ctrl%1$s" data-ecsa-field="%2$s">', esc_attr( $err ), esc_attr( $key ) );
			echo '<div class="ecsa-ctrl__head">';
			printf( '<span class="ecsa-ctrl__label" id="%1$s">%2$s</span>', esc_attr( self::ctrl_id( $key, $mode ) . '-label' ), esc_html( $label ) );
			echo '</div>';
		}

		/**
		 * Close a control row, printing its inline error and help text.
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function ctrl_close( $key, $mode ) {
			unset( $mode );
			if ( isset( self::$field_errors[ $key ] ) ) {
				printf( '<p class="ecsa-ctrl__error" role="alert">%s</p>', esc_html( self::$field_errors[ $key ] ) );
			}

			$meta = self::field_meta( $key );
			if ( '' !== $meta['help'] ) {
				printf( '<p class="ecsa-ctrl__help description">%s</p>', esc_html( $meta['help'] ) );
			}

			echo '</div>';
		}

		/**
		 * A single segmented option (styled radio).
		 *
		 * @since 2.0.0
		 * @param string $name    Radio group name ('' for none).
		 * @param string $value   Option value.
		 * @param string $label   Visible label.
		 * @param bool   $checked Whether checked.
		 * @param bool   $is_default_opt Unused legacy flag; kept for signature stability.
		 * @return void
		 */
		private static function seg_opt( $name, $value, $label, $checked, $is_default_opt ) {
			unset( $is_default_opt );
			printf(
				'<label class="ecsa-seg__opt"><input type="radio"%1$s value="%2$s"%3$s> <span>%4$s</span></label>',
				'' !== $name ? ' name="' . esc_attr( $name ) . '"' : '',
				esc_attr( $value ),
				$checked ? ' checked' : '',
				esc_html( $label )
			);
		}

		/**
		 * Enum field as a segmented selector.
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function field_segmented( $key, $mode ) {
			$def     = self::field_def( $key );
			$choices = isset( $def['choices'] ) ? (array) $def['choices'] : array( '0', '1' );

			if ( 'bool' === $def['type'] ) {
				$choices = array( '0', '1' );
			}

			$name    = 'ecsa[' . $key . ']';
			$meta    = self::field_meta( $key );
			$current = (string) self::value_for_enum( $key, $def );

			self::ctrl_open( $key, $mode, $meta['label'] );
			printf(
				'<div class="ecsa-seg" data-ecsa-ctrl="%1$s" data-ecsa-type="segmented" role="radiogroup" aria-labelledby="%2$s">',
				esc_attr( $key ),
				esc_attr( self::ctrl_id( $key, $mode ) . '-label' )
			);

			foreach ( $choices as $choice ) {
				$choice = (string) $choice;
				self::seg_opt( $name, $choice, self::choice_label( $key, $choice ), $current === $choice, false );
			}

			echo '</div>';
			self::ctrl_close( $key, $mode );
		}

		/*
		 * `field_toggle()` LIVED HERE — a bool field as a two-state switch, with a
		 * hidden companion input so an unchecked box still submitted a real `0`.
		 * `location_field` was the schema's last boolean, so the renderer has no
		 * caller and `Settings::sanitize_field()` has no `bool` branch either. A
		 * future boolean must re-add all three together (the renderer, the hidden
		 * companion, and the sanitizer branch), or an unchecked box will silently
		 * mean "keep the previous value".
		 */

		/*
		 * `compose_toggle()` LIVED HERE — the client-only switch that rendered
		 * `show_search` and `show_filters`. Both control keys are RETIRED with
		 * it: "What to display?" is one radio group now, so a
		 * two-checkbox renderer has no caller, and leaving it would leave the
		 * fourth state buildable by anything that called it again. The matching
		 * `.ecsa-compose-toggles` rule and the JS's two `boolVal()` reads went at
		 * the same time.
		 */

		/**
		 * Text field (placeholder).
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function field_text( $key, $mode, $hint = null ) {
			$def   = self::field_def( $key );
			$meta  = self::field_meta( $key );
			$id    = self::ctrl_id( $key, $mode );
			$max   = isset( $def['max_length'] ) ? (int) $def['max_length'] : 200;
			$value = (string) self::value( $key );

			// The input's own placeholder. Defaulted rather than required so the
			// original caller reads exactly as it did; a second text field would
			// otherwise inherit a hint written for the search box.
			$hint = ( null === $hint ) ? __( 'Search events', 'events-search-addon-for-the-events-calendar' ) : $hint;

			self::ctrl_open( $key, $mode, $meta['label'] );
			printf(
				'<input type="text" id="%1$s" name="ecsa[%2$s]" value="%3$s" maxlength="%4$s" class="regular-text" data-ecsa-ctrl="%2$s" data-ecsa-type="text" placeholder="%5$s" />',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( (string) $max ),
				esc_attr( $hint )
			);
			self::ctrl_close( $key, $mode );
		}

		/**
		 * The three design colours, always on, in one row.
		 *
		 * There is no "Custom" checkbox and no Transparent option any more: each
		 * swatch simply shows its real current value, so setting a colour is one
		 * click instead of two. Every other shade the bar needs is derived from
		 * these three in CSS with `color-mix()`.
		 *
		 * The theme presets sit INSIDE this row, above the pickers, rather than
		 * in a section of their own: a preset is a head start on these three
		 * values, not a separate decision, and giving it its own labelled block
		 * would read as a fourth thing to configure.
		 *
		 * @since 2.0.0
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function color_row( $mode ) {
			$keys = array( 'accent_color', 'text_color', 'bg_color' );
			$err  = '';
			foreach ( $keys as $key ) {
				if ( isset( self::$field_errors[ $key ] ) ) {
					$err = self::$field_errors[ $key ];
					break;
				}
			}

			printf( '<div class="ecsa-ctrl%s" data-ecsa-field="colors">', '' !== $err ? ' ecsa-ctrl--error' : '' );
			echo '<div class="ecsa-ctrl__head"><span class="ecsa-ctrl__label">' . esc_html__( 'Colours', 'events-search-addon-for-the-events-calendar' ) . '</span></div>';

			self::color_presets( $mode );

			echo '<div class="ecsa-color-row">';
			foreach ( $keys as $key ) {
				self::color_swatch( $key, $mode );
			}
			echo '</div>';

			if ( '' !== $err ) {
				printf( '<p class="ecsa-ctrl__error" role="alert">%s</p>', esc_html( $err ) );
			}

			echo '<p class="ecsa-ctrl__help description">' . esc_html__( 'Customize these colors used across the search bar and results.', 'events-search-addon-for-the-events-calendar' ) . '</p>';
			echo '</div>';
		}

		/**
		 * The theme-preset swatches: six chips, each painted in its OWN three
		 * colours, that write those colours into the pickers below.
		 *
		 * Each chip is a real `<button type="button">` — the `type` matters, this
		 * strip lives inside the settings form and a bare `<button>` would submit
		 * it — carrying the triple on data attributes and its theme name as its
		 * visible, accessible label. The chip itself is the preview: a tile in
		 * the preset's background, a bar in its accent and an "Aa" in its text
		 * colour, so the theme is readable before anything is clicked.
		 *
		 * There is NO stored "preset" setting. `aria-pressed` is computed from
		 * the three current colours on every render (and recomputed client-side
		 * on every colour change), so the marking is a truthful readback rather
		 * than a remembered mode: nudge one colour and no chip is marked.
		 *
		 * @since 2.1.0
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function color_presets( $mode ) {
			$presets = Settings::color_presets();
			if ( empty( $presets ) ) {
				return;
			}

			$labels   = self::preset_labels();
			$current  = self::current_preset_key( $presets );
			$label_id = self::ctrl_id( 'color_presets', $mode ) . '-label';

			printf( '<div class="ecsa-presets" role="group" aria-labelledby="%s" data-ecsa-presets>', esc_attr( $label_id ) );
			printf(
				'<span class="ecsa-presets__label" id="%1$s">%2$s</span>',
				esc_attr( $label_id ),
				esc_html__( 'Theme presets', 'events-search-addon-for-the-events-calendar' )
			);

			echo '<div class="ecsa-presets__row">';
			foreach ( $presets as $key => $colors ) {
				$name = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
				$is_on = ( $key === $current );

				// Every value below comes from the hard-coded preset table, never
				// from input, so the custom properties can only be #hex.
				$style = '--ecsa-preset-accent:' . $colors['accent_color']
					. ';--ecsa-preset-text:' . $colors['text_color']
					. ';--ecsa-preset-bg:' . $colors['bg_color'];

				printf(
					'<button type="button" class="ecsa-preset%1$s" data-ecsa-preset="%2$s" data-ecsa-preset-accent="%3$s" data-ecsa-preset-text="%4$s" data-ecsa-preset-bg="%5$s" aria-pressed="%6$s"><span class="ecsa-preset__chip" style="%7$s" aria-hidden="true"><span class="ecsa-preset__glyph">Aa</span><span class="ecsa-preset__bar"></span></span><span class="ecsa-preset__name">%8$s</span></button>',
					$is_on ? ' is-current' : '',
					esc_attr( $key ),
					esc_attr( $colors['accent_color'] ),
					esc_attr( $colors['text_color'] ),
					esc_attr( $colors['bg_color'] ),
					$is_on ? 'true' : 'false',
					esc_attr( $style ),
					esc_html( $name )
				);
			}
			echo '</div>';

			echo '</div>';
		}

		/**
		 * Which preset (if any) the three current colours are EXACTLY equal to.
		 *
		 * Comparison is on normalised hex, so `#FFF` and `#ffffff` are correctly
		 * the same colour. Any difference at all means no preset is current —
		 * the swatches read back the state, they never claim a mode.
		 *
		 * @since 2.1.0
		 * @param array<string, array<string, string>> $presets Preset table.
		 * @return string Preset key, or '' when the colours match none of them.
		 */
		private static function current_preset_key( array $presets ) {
			$now = array(
				'accent_color' => self::normalize_hex( self::current_hex( 'accent_color' ) ),
				'text_color'   => self::normalize_hex( self::current_hex( 'text_color' ) ),
				'bg_color'     => self::normalize_hex( self::current_hex( 'bg_color' ) ),
			);

			foreach ( $presets as $key => $colors ) {
				$match = true;
				foreach ( $now as $field => $value ) {
					if ( ! isset( $colors[ $field ] ) || self::normalize_hex( $colors[ $field ] ) !== $value ) {
						$match = false;
						break;
					}
				}
				if ( true === $match ) {
					return (string) $key;
				}
			}

			return '';
		}

		/**
		 * The concrete `#hex` a colour field is currently showing.
		 *
		 * Only a real hex can drive a native colour input, so a legacy value that
		 * is no longer offered — an empty "inherit", or `transparent` — resolves
		 * to the shipped default rather than leaving a blank "pick me first"
		 * state.
		 *
		 * @since 2.1.0
		 * @param string $key Field key (accent_color|text_color|bg_color).
		 * @return string
		 */
		private static function current_hex( $key ) {
			$def      = self::field_def( $key );
			$fallback = isset( $def['default'] ) ? (string) $def['default'] : '#ffffff';
			$saved    = (string) self::value( $key );

			return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $saved ) ? $saved : $fallback;
		}

		/**
		 * Lower-case a `#hex` and expand the 3-digit form, so two spellings of
		 * one colour compare equal.
		 *
		 * @since 2.1.0
		 * @param string $hex Colour.
		 * @return string
		 */
		private static function normalize_hex( $hex ) {
			$hex = strtolower( trim( (string) $hex ) );

			if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $hex, $m ) ) {
				return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
			}

			return $hex;
		}

		/**
		 * One always-on colour swatch inside the colour row.
		 *
		 * A visible native picker plus a hidden input carrying the value that is
		 * actually submitted (the JS mirrors the picker into it, so the control
		 * degrades to the picker's own value without JS). Legacy values that are no
		 * longer offered — an empty "inherit" or `transparent` — resolve to the
		 * shipped default so the swatch is never a blank "pick me first" state.
		 *
		 * @since 2.0.0
		 * @param string $key  Field key (accent_color|text_color|bg_color).
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function color_swatch( $key, $mode ) {
			$id = self::ctrl_id( $key, $mode );

			// Only a real #hex can drive a native colour input. Shared with the
			// preset readback so both read the field the same way.
			$value = self::current_hex( $key );

			printf(
				'<div class="ecsa-color-field" data-ecsa-ctrl="%1$s" data-ecsa-type="color">',
				esc_attr( $key )
			);
			printf(
				'<input type="color" id="%1$s" class="ecsa-color-field__picker" data-ecsa-color-picker value="%2$s" aria-labelledby="%3$s" />',
				esc_attr( $id ),
				esc_attr( $value ),
				esc_attr( $id . '-label' )
			);
			printf(
				'<span class="ecsa-color-field__name" id="%1$s">%2$s</span>',
				esc_attr( $id . '-label' ),
				esc_html( self::field_meta( $key )['label'] )
			);
			printf(
				'<input type="hidden" name="ecsa[%1$s]" value="%2$s" data-ecsa-color-value />',
				esc_attr( $key ),
				esc_attr( $value )
			);
			echo '</div>';
		}

		/**
		 * Enum field as a plain labelled select (used for `sort`).
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function field_select( $key, $mode ) {
			$def     = self::field_def( $key );
			$choices = isset( $def['choices'] ) ? (array) $def['choices'] : array();
			$meta    = self::field_meta( $key );
			$id      = self::ctrl_id( $key, $mode );
			$current = (string) self::value_for_enum( $key, $def );

			self::ctrl_open( $key, $mode, $meta['label'] );
			printf(
				'<select id="%1$s" name="ecsa[%2$s]" class="regular-text" data-ecsa-ctrl="%2$s" data-ecsa-type="select" aria-labelledby="%3$s">',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_attr( $id . '-label' )
			);

			foreach ( $choices as $choice ) {
				$choice = (string) $choice;
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $choice ),
					$current === $choice ? ' selected' : '',
					esc_html( self::choice_label( $key, $choice ) )
				);
			}

			echo '</select>';
			self::ctrl_close( $key, $mode );
		}

		/*
		 * `field_number()` LIVED HERE — an int field as a plain number input,
		 * deliberately a number rather than a page dropdown (a site with thousands
		 * of pages made that select enormous). `results_page_id` was its only
		 * caller.
		 */

		/**
		 * Int field as a slider with a numeric readout.
		 *
		 * The unit is printed as its OWN span next to the `<output>`, never baked
		 * into the output's text, so the JS can overwrite the readout with the raw
		 * range value and the "%" still shows.
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @param int    $step Slider step (default 1).
		 * @param string $unit Unit suffix shown after the readout ('' for none).
		 * @return void
		 */
		private static function field_slider( $key, $mode, $step = 1, $unit = '' ) {
			$def   = self::field_def( $key );
			$meta  = self::field_meta( $key );
			$id    = self::ctrl_id( $key, $mode );
			$min   = isset( $def['min'] ) ? (int) $def['min'] : 1;
			$max   = isset( $def['max'] ) ? (int) $def['max'] : 50;
			$step  = max( 1, (int) $step );
			$value = (int) self::value( $key );
			$value = max( $min, min( $max, $value ) );

			self::ctrl_open( $key, $mode, $meta['label'] );
			printf(
				'<div class="ecsa-slider" data-ecsa-ctrl="%1$s" data-ecsa-type="slider" data-ecsa-suffix="%2$s">',
				esc_attr( $key ),
				esc_attr( $unit )
			);
			printf(
				'<div class="ecsa-slider__row"><input type="range" id="%1$s" name="ecsa[%2$s]" min="%3$s" max="%4$s" step="%5$s" value="%6$s" class="ecsa-slider__range" aria-labelledby="%7$s" /><span class="ecsa-slider__value"><output class="ecsa-slider__out" for="%1$s">%6$s</output>%8$s</span></div>',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_attr( (string) $min ),
				esc_attr( (string) $max ),
				esc_attr( (string) $step ),
				esc_attr( (string) $value ),
				esc_attr( $id . '-label' ),
				'' !== $unit ? '<span class="ecsa-slider__unit">' . esc_html( $unit ) . '</span>' : ''
			);
			echo '</div>';
			self::ctrl_close( $key, $mode );
		}

		/*
		 * `field_enum_toggle()` LIVED HERE — a two-value enum rendered as an on/off
		 * switch, with a hidden companion carrying the off value. `typeahead` was
		 * its only caller ever, and that control is retired: the value is derived
		 * from the results placement now. A private helper with no callers is dead
		 * code, so it is deleted rather than left for the next reader to wonder
		 * about; the matching `enumtoggle` branch in the panel JS goes with it.
		 */

		/**
		 * Enum field as choice-cards (`view`: Grid / List, `bar_template`: the two
		 * bar frames, `filters_visibility`: the four placements, `filter_style`:
		 * the four trigger looks).
		 *
		 * `$glyph_prefix` namespaces the wireframe lookup in `card_art()`, so two
		 * fields can share a VALUE and still get different pictures. It earned its
		 * keep on `bar_template=soft` vs `filter_style=soft`; the bar frame has
		 * since dropped `soft`, but the trigger style kept it and the namespacing
		 * is what guarantees the retirement of one could not disturb the other.
		 *
		 * A choice may be WITHDRAWN by a configuration that cannot express it —
		 * `$when_map` names the condition each choice depends on, in the same
		 * grammar a subtab uses. Two things then have to happen together, and the
		 * second is the one that is easy to forget: the card is hidden, AND the
		 * selection MOVES off it if it was the checked one. A hidden radio is still
		 * a checked radio — `readCheckedRadio()` asks for `input:checked` and has no
		 * opinion about `hidden` — so a withdrawn-but-checked card would go on
		 * driving the preview, the generated shortcode and the next save from behind
		 * an invisible control. The move lands on the first choice still offered,
		 * read off the schema's own order so this and its JS twin (`syncChoiceCards`,
		 * which walks the cards in DOM order) can only ever agree.
		 *
		 * @since 2.0.0
		 * @param string                $key          Field key.
		 * @param string                $mode         Editor mode.
		 * @param string                $variant      Extra class on the group (e.g. a column layout).
		 * @param string                $glyph_prefix Prefix for the card_art() key ('' for none).
		 * @param array<string, string> $when_map     choice => condition ('' or absent = always offered).
		 * @return void
		 */
		private static function field_choice_cards( $key, $mode, $variant = '', $glyph_prefix = '', array $when_map = array() ) {
			$def     = self::field_def( $key );
			$choices = isset( $def['choices'] ) ? (array) $def['choices'] : array();
			$meta    = self::field_meta( $key );
			$name    = 'ecsa[' . $key . ']';
			$current = (string) self::value_for_enum( $key, $def );
			$state   = array() !== $when_map ? self::panel_state() : array();

			if ( array() !== $when_map ) {
				$current = self::first_offered_choice( $current, $choices, $when_map, $state );
			}

			self::ctrl_open( $key, $mode, $meta['label'] );
			printf(
				'<div class="ecsa-cards-choice%1$s" data-ecsa-ctrl="%2$s" data-ecsa-type="cards" role="radiogroup" aria-labelledby="%3$s">',
				'' !== $variant ? ' ' . esc_attr( $variant ) : '',
				esc_attr( $key ),
				esc_attr( self::ctrl_id( $key, $mode ) . '-label' )
			);

			foreach ( $choices as $choice ) {
				$choice = (string) $choice;
				$when   = isset( $when_map[ $choice ] ) ? (string) $when_map[ $choice ] : '';
				self::choice_card( $name, $choice, self::choice_label( $key, $choice ), $glyph_prefix . $choice, $current === $choice, $when, $state );
			}

			// The Pro options this control advertises, always LAST and never
			// checked, so nothing above can land on one.
			self::pro_choice_cards( $key, $name, $meta['label'] );

			echo '</div>';
			self::ctrl_close( $key, $mode );
		}

		/**
		 * The choice a narrowed group should render as checked.
		 *
		 * Returns `$current` untouched while it is still offered — a withdrawal must
		 * never disturb a selection it does not affect. Otherwise it returns the
		 * first choice the configuration DOES offer, in the schema's declared order,
		 * which is the same landing spot `first_offered_placement()` computes for the
		 * placement cards and the same one the JS reaches by walking the rendered
		 * cards.
		 *
		 * @since 2.0.0
		 * @param string                $current  Currently stored (coerced) choice.
		 * @param array                 $choices  The field's choices, in schema order.
		 * @param array<string, string> $when_map choice => condition.
		 * @param array<string, mixed>  $state    Panel state from `panel_state()`.
		 * @return string
		 */
		private static function first_offered_choice( $current, array $choices, array $when_map, array $state ) {
			$offered = static function ( $choice ) use ( $when_map, $state ) {
				$when = isset( $when_map[ $choice ] ) ? (string) $when_map[ $choice ] : '';

				return self::condition_met( $when, $state );
			};

			if ( true === $offered( $current ) ) {
				return (string) $current;
			}

			foreach ( $choices as $choice ) {
				if ( true === $offered( (string) $choice ) ) {
					return (string) $choice;
				}
			}

			return (string) $current;
		}

		/**
		 * One choice-card (styled radio with a small mini-preview thumb).
		 *
		 * The thumb is either an inline SVG wireframe (see `card_art()`) or, for the
		 * older grid/list glyphs, a CSS background — both are purely decorative and
		 * carry `aria-hidden`, so the accessible name is always the card label.
		 *
		 * A card may declare the CONDITION under which it is offered at all
		 * (`$when`, same grammar as a subtab's). It is evaluated here against the
		 * caller's `$state` rather than by the caller, so the attribute the JS
		 * maintains and the `hidden` the server writes come from one expression and
		 * cannot disagree. A withdrawn card is only ever HIDDEN — its radio stays in
		 * the DOM so the group can offer it again the moment the condition flips.
		 *
		 * @since 2.0.0
		 * @param string               $name    Radio group name.
		 * @param string               $value   Option value.
		 * @param string               $label   Visible label.
		 * @param string               $glyph   Thumb modifier (grid|list|plain|bordered|where-*).
		 * @param bool                 $checked Whether selected.
		 * @param string               $when    Availability condition ('' = always).
		 * @param array<string, mixed> $state   Panel state to evaluate `$when` against.
		 * @return void
		 */
		private static function choice_card( $name, $value, $label, $glyph, $checked, $when = '', array $state = array() ) {
			$offered = self::condition_met( $when, $state );

			/*
			 * The selected look is driven ONLY by the radio's own `checked` state
			 * (`:has(input:checked)` in the stylesheet). It deliberately does NOT
			 * also emit a static `is-selected` class: that class was rendered once
			 * on page load and nothing ever removed it, so picking a different
			 * card left the ORIGINAL card highlighted too and two cards looked
			 * selected at once. Radio state is the single source of truth, so it
			 * cannot go stale.
			 */
			printf(
				'<label class="ecsa-choice-card"%7$s%8$s><input type="radio" name="%1$s" value="%2$s"%3$s /><span class="ecsa-choice-thumb ecsa-choice-thumb--%4$s" aria-hidden="true">%5$s</span><span class="ecsa-choice-title">%6$s</span></label>',
				esc_attr( $name ),
				esc_attr( $value ),
				$checked ? ' checked' : '',
				esc_attr( $glyph ),
				self::card_art( $glyph ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a hardcoded literal SVG from the allowlist below; no dynamic data reaches it.
				esc_html( $label ),
				'' !== (string) $when ? ' data-ecsa-when="' . esc_attr( $when ) . '"' : '',
				$offered ? '' : ' hidden'
			);
		}

		/* --------------------------------------------------------------------- *
		 * LOCKED PRO OPTIONS — marketing, not gated code
		 *
		 * WHAT THIS IS, AND WHAT IT IS NOT. The capabilities named below are ABSENT
		 * from this plugin: no constant, no schema choice, no REST enum, no renderer
		 * branch. Nothing here re-enables one. These renderers advertise them —
		 * a visible, badged, disabled control that links to the Pro page — which is
		 * ordinary practice on wp.org and is why FREE-PRO-PLAN §2.2 permits it while
		 * §2.1 forbids shipping the functionality itself.
		 *
		 * TWO LAYERS, AND NEITHER IS THE ANSWER ALONE.
		 *
		 *   1. `disabled` on the input. A disabled control is not focusable, cannot
		 *      be checked, and — the part that matters — is NOT SUBMITTED by the
		 *      browser. That is the courtesy layer: it makes the honest path
		 *      impossible.
		 *   2. The ALLOWLIST. Free's `Instance` constants and `Settings::schema()`
		 *      `choices` simply do not contain these values, so `sanitize_field()`
		 *      answers a hand-crafted POST — a stale tab, another plugin calling
		 *      `Settings::save()`, curl — with the shipped default. That is the
		 *      enforcement layer, and it is the only one that holds when the markup
		 *      is bypassed entirely.
		 *
		 * Layer 1 without layer 2 is theatre (edit the DOM, remove the attribute,
		 * post it). Layer 2 without layer 1 is a control that appears to work and
		 * then silently discards the admin's choice on save. Together they
		 * guarantee a locked value is never submitted and never stored.
		 *
		 * NOT `data-ecsa-when`. That attribute is the WITHDRAWAL mechanism: it hides
		 * a card AND MOVES the checked selection off it (`syncChoiceCards()`). A
		 * locked card must do neither — it stays visible, stays in place, and must
		 * never disturb what the admin has already chosen. The two mechanisms are
		 * deliberately disjoint, and no locked element carries a `data-ecsa-when`.
		 * --------------------------------------------------------------------- */

		/**
		 * WHICH VALUES ARE PRO, declared once for the whole panel.
		 *
		 * The single table every locked renderer reads, so the Pro vocabulary
		 * appears in this file exactly once instead of being sprinkled through four
		 * control renderers. Order is the order they render in, always AFTER the
		 * free choices — a locked card that came first could become the landing spot
		 * for a withdrawal's selection move, which is precisely the accident the
		 * "stay put" rule exists to prevent.
		 *
		 * A control is listed here only when it renders at all. Two do not, and the
		 * rule that decides it is: a choice group needs something to CHOOSE between.
		 * `filter_style` (one free value, three Pro ones) and `filters_button_style`
		 * (withdrawn outright) render no control, so they advertise nothing —
		 * showing three locked cards and one live one reads as "everything is
		 * locked", which is the review risk FREE-PRO-PLAN §5 names.
		 *
		 * @since 2.8.0
		 * @return array<string, string[]> Field key => Pro-only values.
		 */
		private static function pro_choices() {
			return array(
				// WHERE the filters live. Free has two placements; behind a Filters
				// button, and permanently under the bar, are Pro.
				'filters_visibility' => array( 'bar_button', 'under_bar' ),
				// WHICH card template. Free ships Clean; the date-pill treatment is Pro.
				'template'           => array( 'modern' ),
				// WHERE results appear. A dedicated results PAGE is Pro.
				'results_mode'       => array( 'page' ),
				// WHAT WE DO to TEC's own events page. Taking the view over is Pro.
				'tec_views'          => array( 'replace' ),
				// WHICH filters the bar can show. Free has the keyword box and Date.
				'facets'             => array( 'category', 'tag', 'venue', 'organizer', 'location' ),
				// WHICH fields a keyword matches. Free matches the title.
				'search_fields'      => array( 'content', 'venue', 'organizer' ),
			);
		}

		/**
		 * Labels for the Pro-only values.
		 *
		 * A TABLE OF ITS OWN, deliberately not folded into `choice_labels()`. That
		 * one answers "what do I call a value this site has STORED", and a free site
		 * can never store any of these — so mixing them in would put marketing
		 * strings on a lookup path used by the shortcode generator. Kept apart, the
		 * free vocabulary and the advertised vocabulary cannot be confused for each
		 * other by a future reader or a future caller.
		 *
		 * @since 2.8.0
		 * @return array<string, array<string, string>> Field key => value => label.
		 */
		private static function pro_choice_labels() {
			return array(
				'filters_visibility' => array(
					'bar_button' => __( 'Behind a Filters button', 'events-search-addon-for-the-events-calendar' ),
					'under_bar'  => __( 'Under the bar', 'events-search-addon-for-the-events-calendar' ),
				),
				'template'           => array(
					'modern' => __( 'Modern', 'events-search-addon-for-the-events-calendar' ),
				),
				'results_mode'       => array(
					'page' => __( 'Separate results page', 'events-search-addon-for-the-events-calendar' ),
				),
				'tec_views'          => array(
					'replace' => __( 'Replace all - filters & results', 'events-search-addon-for-the-events-calendar' ),
				),
				'facets'             => array(
					'category'  => __( 'Category', 'events-search-addon-for-the-events-calendar' ),
					'tag'       => __( 'Tag', 'events-search-addon-for-the-events-calendar' ),
					'venue'     => __( 'Venue', 'events-search-addon-for-the-events-calendar' ),
					'organizer' => __( 'Organizer', 'events-search-addon-for-the-events-calendar' ),
					'location'  => __( 'Location', 'events-search-addon-for-the-events-calendar' ),
				),
				'search_fields'      => array(
					'content'   => __( 'Description', 'events-search-addon-for-the-events-calendar' ),
					'venue'     => __( 'Venue name', 'events-search-addon-for-the-events-calendar' ),
					'organizer' => __( 'Organizer name', 'events-search-addon-for-the-events-calendar' ),
				),
			);
		}

		/**
		 * One Pro value's label, falling back to a readable spelling of the key.
		 *
		 * @since 2.8.0
		 * @param string $field Field key.
		 * @param string $value Pro-only value.
		 * @return string
		 */
		private static function pro_choice_label( $field, $value ) {
			$labels = self::pro_choice_labels();

			if ( isset( $labels[ $field ][ $value ] ) ) {
				return $labels[ $field ][ $value ];
			}

			return ucwords( str_replace( array( '_', '-' ), ' ', $value ) );
		}

		/**
		 * Where an upgrade prompt points.
		 *
		 * GUARDED, like every other constant this tree reads. When the
		 * constant is missing — a partially deployed tree — the badge still renders
		 * and the option is still visibly locked; only the link stands down. A
		 * broken upsell must never be a fatal admin screen.
		 *
		 * @since 2.8.0
		 * @return string URL, or '' when it is not declared.
		 */
		private static function pro_url() {
			return defined( 'ECSA_PRO_URL' ) ? (string) ECSA_PRO_URL : '';
		}

		/**
		 * The accessible name for a locked OPTION inside a control.
		 *
		 * It has to answer two questions a bare "Pro" badge cannot: WHICH option
		 * this is (the badge sits beside a dozen others) and WHY it is inert. The
		 * visible badge is one word; this is the sentence that word stands for.
		 *
		 * @since 2.8.0
		 * @param string $option  The locked option's label.
		 * @param string $control The label of the control it belongs to.
		 * @return string
		 */
		private static function pro_option_name( $option, $control ) {
			return sprintf(
				/* translators: 1: the name of a locked option, e.g. "Modern". 2: the name of the setting it belongs to, e.g. "Card design". */
				__( '%1$s (%2$s) — available in Pro. Opens the pricing page in a new tab.', 'events-search-addon-for-the-events-calendar' ),
				$option,
				$control
			);
		}

		/**
		 * The accessible name for a whole locked CONTROL.
		 *
		 * @since 2.8.0
		 * @param string $control The control's label.
		 * @return string
		 */
		private static function pro_control_name( $control ) {
			return sprintf(
				/* translators: %s: the name of a locked setting, e.g. "Location search in the bar". */
				__( '%s — available in Pro. Opens the pricing page in a new tab.', 'events-search-addon-for-the-events-calendar' ),
				$control
			);
		}

		/**
		 * The lock affordance: a visible "Pro" badge that IS the link.
		 *
		 * ONE ELEMENT, reused by every control shape, and it is the reason the four
		 * locked renderers below stay short. The anchor is stretched over its
		 * positioned parent in CSS (`.ecsa-pro-lock::after { inset: 0 }`), so the
		 * WHOLE card / row / chip is clickable while the DOM stays valid — an `<a>`
		 * may not contain a form control, and the disabled input has to live beside
		 * it rather than inside it.
		 *
		 * `aria-label` carries the sentence; the badge text is `aria-hidden` so the
		 * name is not read as "Pro Pro". `rel="noopener"` because it opens a new tab.
		 *
		 * @since 2.8.0
		 * @param string $accessible_name Full accessible name for the link.
		 * @return string
		 */
		private static function pro_lock( $accessible_name ) {
			$url = self::pro_url();

			/* translators: short badge shown on options that require the Pro plugin. */
			$badge = '<span class="ecsa-pro-badge" aria-hidden="true">' . esc_html__( 'Pro', 'events-search-addon-for-the-events-calendar' ) . '</span>';

			if ( '' === $url ) {
				return $badge;
			}

			return sprintf(
				'<a class="ecsa-pro-lock" href="%1$s" target="_blank" rel="noopener" aria-label="%2$s">%3$s</a>',
				esc_url( $url ),
				esc_attr( $accessible_name ),
				$badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built immediately above from esc_html__().
			);
		}

		/**
		 * A locked choice CARD.
		 *
		 * Same box, same track, same position it would occupy if it worked — the
		 * point is that the option looks real and desirable, not that it looks
		 * broken. Differences from `choice_card()`, all deliberate:
		 *
		 *   - a `<div>`, not a `<label>`: a label whose control is disabled does
		 *     nothing when clicked, which is a worse affordance than no label at
		 *     all. The stretched `.ecsa-pro-lock` owns the click instead.
		 *   - `disabled` + `aria-disabled` + `tabindex="-1"` on the radio, so it is
		 *     never checked, never focused and never submitted.
		 *   - NO `data-ecsa-when`: see the section header. A locked card is not a
		 *     withdrawn one and must never move the selection.
		 *   - ONE shared padlock wireframe rather than a picture of the Pro layout.
		 *     The layout wireframes were deleted with their values, and re-adding
		 *     them would put drawings of capabilities this tree does not have back
		 *     into it — the padlock says "there is more here" without illustrating
		 *     something we cannot render.
		 *
		 * @since 2.8.0
		 * @param string $name    Radio group name (the real one, so the `disabled`
		 *                        attribute is what stops the submission).
		 * @param string $field   Field key, for the label lookup.
		 * @param string $value   Pro-only value.
		 * @param string $control The control's visible label.
		 * @return void
		 */
		private static function pro_choice_card( $name, $field, $value, $control ) {
			$label = self::pro_choice_label( $field, $value );

			printf(
				'<div class="ecsa-choice-card ecsa-choice-card--pro" data-ecsa-pro="1"><input type="radio" name="%1$s" value="%2$s" disabled aria-disabled="true" tabindex="-1" /><span class="ecsa-choice-thumb ecsa-choice-thumb--pro-lock" aria-hidden="true">%3$s</span><span class="ecsa-choice-title">%4$s</span>%5$s</div>',
				esc_attr( $name ),
				esc_attr( $value ),
				self::card_art( 'pro-lock' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a hardcoded literal SVG from card_art()'s allowlist.
				esc_html( $label ),
				self::pro_lock( self::pro_option_name( $label, $control ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by pro_lock(), which escapes every part.
			);
		}

		/**
		 * Every locked card for one field, in `pro_choices()` order.
		 *
		 * Called from INSIDE the group container so the cards land on the same grid
		 * as the live ones, and always after the loop over the real choices.
		 *
		 * @since 2.8.0
		 * @param string $field   Field key.
		 * @param string $name    Radio group name.
		 * @param string $control The control's visible label.
		 * @return void
		 */
		private static function pro_choice_cards( $field, $name, $control ) {
			$map = self::pro_choices();

			if ( ! isset( $map[ $field ] ) ) {
				return;
			}

			foreach ( $map[ $field ] as $value ) {
				self::pro_choice_card( $name, $field, (string) $value, $control );
			}
		}

		/**
		 * The inline-SVG wireframe for a choice card, or '' when the thumb is a
		 * CSS-only glyph.
		 *
		 * Every string here is a hardcoded literal — nothing dynamic is
		 * interpolated — so the markup is safe to echo verbatim. `currentColor` lets
		 * the selected card tint its own wireframe from CSS.
		 *
		 * @since 2.0.0
		 * @param string $glyph Thumb modifier.
		 * @return string
		 */
		private static function card_art( $glyph ) {
			$open  = '<svg class="ecsa-choice-art" viewBox="0 0 72 44" xmlns="http://www.w3.org/2000/svg" focusable="false">';
			$close = '</svg>';

			$art = array(
				/*
				 * THE LOCKED-CARD THUMB, shared by every Pro option in the panel.
				 *
				 * ONE drawing for all of them, and not a wireframe of the layout the
				 * option would produce. Those wireframes were deleted with their
				 * values, and re-adding them would put pictures of capabilities this
				 * tree does not have back into it. A padlock is honest about what the
				 * card is: an option that exists, in a plugin this is not.
				 */
				'pro-lock'             => $open . '<rect x="27" y="20" width="18" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M31 20v-3.5a5 5 0 0 1 10 0V20" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="36" cy="26.5" r="1.8" fill="currentColor"/>' . $close,
				/*
				 * "What to display?". ONE GRAMMAR across the two,
				 * borrowed from the placement wireframes below: a stroked box is the
				 * search shell, a filled block is the search submit, and a stroked
				 * pill is a filter. So the two cards differ only in whether the pill
				 * row is drawn — which is exactly what the setting decides.
				 *
				 * NOTE: `parts-filters` — three pills and no search shell — was the
				 * third drawing here. Deleted with its value: a wireframe of a
				 * layout the control cannot produce is the crumb a "put it back"
				 * grows from, exactly as the orphaned `card-modern` thumb would have
				 * been.
				 */
				'parts-search'         => $open . '<rect x="4" y="14" width="64" height="16" rx="4" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="20" width="30" height="4" rx="2" fill="currentColor" opacity="0.35"/><rect x="55" y="18" width="9" height="8" rx="2" fill="currentColor"/>' . $close,
				'parts-search_filters' => $open . '<rect x="4" y="5" width="64" height="16" rx="4" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="11" width="30" height="4" rx="2" fill="currentColor" opacity="0.35"/><rect x="55" y="9" width="9" height="8" rx="2" fill="currentColor"/><rect x="4" y="27" width="18" height="9" rx="4.5" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="26" y="27" width="18" height="9" rx="4.5" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="48" y="27" width="18" height="9" rx="4.5" fill="none" stroke="currentColor" stroke-width="1.5"/>' . $close,
				/*
				 * Filter PLACEMENT — where the filters live relative to the bar.
				 * Each card draws the BAR OUTLINE as a stroked box so the picture
				 * can answer the one question that separates them: is the filter row
				 * inside that outline or outside it?
				 *
				 * The `plc-bar_button` and `plc-under_bar` wireframes are DELETED
				 * with their values, not left as orphans.
				 */
				// Everything on one row, INSIDE the outline: input, filters, button.
				'plc-bar_inline'  => $open . '<rect x="4" y="13" width="64" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="20" width="16" height="4" rx="2" fill="currentColor" opacity="0.35"/><rect x="29" y="17" width="13" height="10" rx="5" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="45" y="17" width="13" height="10" rx="5" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="60" y="18" width="7" height="8" rx="2" fill="currentColor"/>' . $close,
				'plc-expanded'    => $open . '<rect x="6" y="4" width="60" height="9" rx="3" fill="currentColor"/><rect x="6" y="19" width="60" height="21" rx="3" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="26" y1="19" x2="26" y2="40" stroke="currentColor" stroke-width="1.5"/><line x1="46" y1="19" x2="46" y2="40" stroke="currentColor" stroke-width="1.5"/><rect x="10" y="24" width="12" height="2.5" rx="1.25" fill="currentColor" opacity="0.6"/><rect x="10" y="30" width="9" height="2.5" rx="1.25" fill="currentColor" opacity="0.4"/><rect x="30" y="24" width="12" height="2.5" rx="1.25" fill="currentColor" opacity="0.6"/><rect x="30" y="30" width="9" height="2.5" rx="1.25" fill="currentColor" opacity="0.4"/><rect x="50" y="24" width="12" height="2.5" rx="1.25" fill="currentColor" opacity="0.6"/><rect x="50" y="30" width="9" height="2.5" rx="1.25" fill="currentColor" opacity="0.4"/>' . $close,
				/*
				 * NOTE: the `trg-*` family (filter TRIGGER geometry — text, pill,
				 * select, soft) lived here. `filter_style` has one value and the
				 * panel renders no control for it, so there is no card to illustrate
				 * and the wireframes are DELETED rather than left as orphans.
				 */
				/*
				 * NOTE: the `fbtn-*` family (four looks for the in-bar FILTERS
				 * TOGGLE) lived here. DELETED with the axis rather than left as
				 * orphans — the same treatment `trg-*` got. There is no control, so
				 * there is no card to illustrate.
				 */
				/*
				 * Bar FRAME — a wireframe of how the box and the button are bound:
				 * apart, or one frame around both. The `capsule` (Pill) and `soft`
				 * (Filled) wireframes are RETIRED with their values — Corner
				 * rounding and Background colour already say both, so the two cards
				 * were pictures of settings the admin already had.
				 */
				'detached'        => $open . '<rect x="4" y="14" width="40" height="16" rx="3" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="48" y="14" width="20" height="16" rx="3" fill="currentColor"/>' . $close,
				'unified'         => $open . '<rect x="4" y="14" width="64" height="16" rx="4" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="46" y1="14" x2="46" y2="30" stroke="currentColor" stroke-width="1.5"/><rect x="50" y="19" width="12" height="6" rx="2" fill="currentColor"/>' . $close,
				/*
				 * The card TEMPLATE — image band, title, meta line. The
				 * `card-modern` wireframe (the same card with a date badge on the
				 * image and the meta line shortened to a time) is DELETED with its
				 * value.
				 */
				'card-clean'      => $open . '<rect x="14" y="5" width="44" height="16" rx="3" fill="currentColor" opacity="0.28"/><rect x="14" y="26" width="30" height="4" rx="2" fill="currentColor"/><rect x="14" y="34" width="38" height="3" rx="1.5" fill="currentColor" opacity="0.5"/>' . $close,
				// Where results appear.
				'where-inline'    => $open . '<rect x="6" y="5" width="60" height="10" rx="3" fill="currentColor"/><rect x="6" y="21" width="28" height="17" rx="3" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="38" y="21" width="28" height="17" rx="3" fill="none" stroke="currentColor" stroke-width="1.5"/>' . $close,
				/*
				 * `where-separate` — "elsewhere on this page" — is RETIRED with its
				 * card. It drew a bar, a dashed gap and a results block, which is
				 * the same picture `where-inline` tells: the results shortcode goes
				 * wherever the author pastes it, and the plugin never enforced the
				 * gap. `where-page` — two pages with an arrow between them — is
				 * DELETED with its value for the same reason it is not an option.
				 */
				'where-none'      => $open . '<rect x="6" y="5" width="60" height="10" rx="3" fill="currentColor"/><rect x="6" y="19" width="44" height="19" rx="3" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="10" y="24" width="30" height="3" rx="1.5" fill="currentColor" opacity="0.55"/><rect x="10" y="31" width="22" height="3" rx="1.5" fill="currentColor" opacity="0.55"/>' . $close,
				/*
				 * The events-page modes. ONE VISUAL GRAMMAR across both: STROKED
				 * means "The Events Calendar renders it", FILLED means "we do". So
				 * the two cards differ only in which regions are filled, which is
				 * exactly what the setting changes — `tec-header_swap` is `tec-off`
				 * with the control strip filled. (`tec-replace`, the whole view
				 * filled, is DELETED with its value.)
				 */
				'tec-off'         => $open . '<rect x="4" y="4" width="26" height="4" rx="2" fill="currentColor" opacity="0.55"/><rect x="4" y="12" width="64" height="10" rx="3" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="8" y="16" width="18" height="3" rx="1.5" fill="currentColor" opacity="0.35"/><rect x="46" y="16" width="18" height="3" rx="1.5" fill="currentColor" opacity="0.35"/><rect x="4" y="26" width="64" height="6" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="4" y="35" width="64" height="6" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/>' . $close,
				'tec-header_swap' => $open . '<rect x="4" y="4" width="26" height="4" rx="2" fill="currentColor" opacity="0.55"/><rect x="4" y="12" width="64" height="10" rx="3" fill="currentColor"/><rect x="4" y="26" width="64" height="6" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="4" y="35" width="64" height="6" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/>' . $close,
			);

			return isset( $art[ $glyph ] ) ? $art[ $glyph ] : '';
		}

		/**
		 * Set field as a multi-select checkbox group (`search_fields`,
		 * `card_fields`).
		 *
		 * Real checkboxes submitting `ecsa[<key>][]`; submitting none is legal and
		 * the schema maps an empty set back to "everything". `$grid` swaps the
		 * pill row for a two-column grid, which is what the four/five-item lists
		 * want — the read contract (`chips`) is identical either way.
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @param bool   $grid Render as a 2-column checkbox grid.
		 * @return void
		 */
		private static function field_chips( $key, $mode, $grid = false ) {
			$def     = self::field_def( $key );
			$choices = isset( $def['choices'] ) ? array_map( 'strval', (array) $def['choices'] ) : array();
			$meta    = self::field_meta( $key );
			$current = array_map( 'strval', (array) self::value( $key ) );
			$default = array_map( 'strval', (array) Settings::get( $key ) );

			self::ctrl_open( $key, $mode, $meta['label'] );
			printf(
				'<div class="%1$s" data-ecsa-ctrl="%2$s" data-ecsa-type="chips" data-ecsa-default="%3$s" role="group" aria-labelledby="%4$s">',
				$grid ? 'ecsa-checkgrid' : 'ecsa-chipset',
				esc_attr( $key ),
				esc_attr( implode( ',', $default ) ),
				esc_attr( self::ctrl_id( $key, $mode ) . '-label' )
			);

			$item_class = $grid ? 'ecsa-checkgrid__item' : 'ecsa-chip-toggle';

			foreach ( $choices as $choice ) {
				$cid     = self::ctrl_id( $key, $mode ) . '-' . $choice;
				$checked = in_array( $choice, $current, true );
				printf(
					'<label class="%1$s" for="%2$s"><input type="checkbox" id="%2$s" name="ecsa[%3$s][]" value="%4$s"%5$s /> <span>%6$s</span></label>',
					esc_attr( $item_class ),
					esc_attr( $cid ),
					esc_attr( $key ),
					esc_attr( $choice ),
					$checked ? ' checked' : '',
					esc_html( self::choice_label( $key, $choice ) )
				);
			}

			/*
			 * The Pro members of this set, LOCKED. A checkbox group is not a radio
			 * group, so the treatment differs from a card's: the box keeps the same
			 * chip/grid shape it would have if it worked, and only the `<label>`
			 * becomes a `<div>` — a label pointing at a disabled box is a click
			 * target that does nothing, which is worse than no label. The stretched
			 * `.ecsa-pro-lock` owns the click.
			 *
			 * `readCheckedList()` reads `input[type="checkbox"]:checked`, so an
			 * unchecked disabled box is invisible to the preview and the generator
			 * as well as to the form serializer.
			 */
			$pro = self::pro_choices();
			if ( isset( $pro[ $key ] ) ) {
				foreach ( $pro[ $key ] as $choice ) {
					$choice = (string) $choice;
					$label  = self::pro_choice_label( $key, $choice );
					printf(
						'<div class="%1$s %1$s--pro" data-ecsa-pro="1"><input type="checkbox" name="ecsa[%2$s][]" value="%3$s" disabled aria-disabled="true" tabindex="-1" /> <span>%4$s</span>%5$s</div>',
						esc_attr( $item_class ),
						esc_attr( $key ),
						esc_attr( $choice ),
						esc_html( $label ),
						self::pro_lock( self::pro_option_name( $label, $meta['label'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by pro_lock(), which escapes every part.
					);
				}
			}

			echo '</div>';
			self::ctrl_close( $key, $mode );
		}

		/**
		 * Ordered-set field (facets) as a drag-sortable list of rows, each a drag
		 * handle + an on/off toggle. Keyboard up/down buttons and plain checkboxes
		 * are the no-JS / a11y fallback (DOM order == submit order).
		 *
		 * The keyword search has NO row: it is not a filter an admin reorders, and
		 * showing it twice (here and in Setup's "What to display?") was the whole
		 * confusion. It is still part of the stored set — a hidden input submits it
		 * first, ahead of every visible row — so nothing downstream changes.
		 *
		 * `location` has no row either, for the same reason in reverse: the three
		 * dependent Country / State / City selects it used to render below the bar
		 * are replaced by ONE searchable box inside the bar (Setup -> "Location
		 * search in the bar"), so it is no longer a filter an admin orders
		 * alongside Date and Category.
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @return void
		 */
		private static function field_sortable( $key, $mode ) {
			$def     = self::field_def( $key );
			$choices = isset( $def['choices'] ) ? array_map( 'strval', (array) $def['choices'] ) : array();
			$meta    = self::field_meta( $key );

			/*
			 * `search` is deliberately NOT offered as a row: it is owned by the
			 * Setup tab's "What to display?" choice, and is still submitted (a
			 * hidden input below) so the stored set keeps it.
			 *
			 * (A `location` exclusion sat here too, back when `location` was a
			 * facet key the registry still accepted but the panel no longer
			 * offered. It is not in the registry at all now, so `$choices` never
			 * contains it and there is nothing to subtract.)
			 */
			$choices = array_values( array_diff( $choices, array( 'search' ) ) );

			// Saved/selected order first, then the rest unchecked.
			$saved   = array_map( 'strval', (array) self::value( $key ) );
			$default = array_map( 'strval', (array) Settings::get( $key ) );

			$current = array();
			foreach ( $saved as $v ) {
				if ( in_array( $v, $choices, true ) && ! in_array( $v, $current, true ) ) {
					$current[] = $v;
				}
			}
			$rest = array();
			foreach ( $choices as $choice ) {
				if ( ! in_array( $choice, $current, true ) ) {
					$rest[] = $choice;
				}
			}
			$ordered = array_merge( $current, $rest );

			self::ctrl_open( $key, $mode, $meta['label'] );
			printf(
				'<div class="ecsa-sortable" data-ecsa-ctrl="%1$s" data-ecsa-type="sortable" data-ecsa-default="%2$s">',
				esc_attr( $key ),
				esc_attr( implode( ',', $default ) )
			);

			// Always submitted, always first: the stored set keeps `search`.
			printf( '<input type="hidden" name="ecsa[%s][]" value="search" />', esc_attr( $key ) );

			echo '<ul class="ecsa-sortable__list">';

			foreach ( $ordered as $choice ) {
				$cid     = self::ctrl_id( $key, $mode ) . '-' . $choice;
				$label   = self::choice_label( $key, $choice );
				$checked = in_array( $choice, $current, true );

				echo '<li class="ecsa-sortable__item" data-ecsa-facet="' . esc_attr( $choice ) . '">';
				echo '<span class="ecsa-sortable__handle" aria-hidden="true"><span class="dashicons dashicons-menu"></span></span>';
				printf(
					'<label class="ecsa-sortable__toggle" for="%1$s"><input type="checkbox" id="%1$s" name="ecsa[%2$s][]" value="%3$s"%4$s /><span class="ecsa-toggle__track" aria-hidden="true"></span><span class="ecsa-sortable__name">%5$s</span></label>',
					esc_attr( $cid ),
					esc_attr( $key ),
					esc_attr( $choice ),
					$checked ? ' checked' : '',
					esc_html( $label )
				);
				echo '<span class="ecsa-sortable__move" hidden>';
				printf(
					'<button type="button" class="button ecsa-sortable__up" aria-label="%s"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>',
					/* translators: %s: filter name. */
					esc_attr( sprintf( __( 'Move up: %s', 'events-search-addon-for-the-events-calendar' ), $label ) )
				);
				printf(
					'<button type="button" class="button ecsa-sortable__down" aria-label="%s"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>',
					/* translators: %s: filter name. */
					esc_attr( sprintf( __( 'Move down: %s', 'events-search-addon-for-the-events-calendar' ), $label ) )
				);
				echo '</span>';
				echo '</li>';
			}

			/*
			 * The Pro filters, LOCKED, at the bottom of the list.
			 *
			 * A sortable row is neither a card nor a chip, so it gets its own
			 * treatment — but the three guarantees are the same shape:
			 *
			 *   - the toggle is a disabled checkbox carrying the REAL field name, so
			 *     the browser omits it and a tampered POST still meets the schema's
			 *     `choices` allowlist;
			 *   - NO `data-ecsa-facet`. That attribute is what `readSortable()` maps
			 *     a row to, so a locked row cannot enter the facet set even if
			 *     something contrived to check its box. It is also what
			 *     `seedFacetsOnEnable()` iterates, so switching filters on can never
			 *     seed a Pro facet;
			 *   - no drag handle affordance and no move buttons: the row is not
			 *     orderable, and `initSortables()` skips `[data-ecsa-pro]` rather
			 *     than marking it `draggable`.
			 *
			 * They render after every free row rather than interleaved, so dragging
			 * a real filter can never have to cross one.
			 */
			$pro = self::pro_choices();
			if ( isset( $pro[ $key ] ) ) {
				foreach ( $pro[ $key ] as $choice ) {
					$choice = (string) $choice;
					$label  = self::pro_choice_label( $key, $choice );
					printf(
						'<li class="ecsa-sortable__item ecsa-sortable__item--pro" data-ecsa-pro="1"><span class="ecsa-sortable__handle" aria-hidden="true"><span class="dashicons dashicons-menu"></span></span><span class="ecsa-sortable__toggle"><input type="checkbox" name="ecsa[%1$s][]" value="%2$s" disabled aria-disabled="true" tabindex="-1" /><span class="ecsa-toggle__track" aria-hidden="true"></span><span class="ecsa-sortable__name">%3$s</span></span>%4$s</li>',
						esc_attr( $key ),
						esc_attr( $choice ),
						esc_html( $label ),
						self::pro_lock( self::pro_option_name( $label, $meta['label'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by pro_lock(), which escapes every part.
					);
				}
			}

			echo '</ul>';
			echo '</div>';
			self::ctrl_close( $key, $mode );
		}

		/*
		 * NOTE: there was a `field_facet_styles()` map control here (a per-filter
		 * style selector revealed while `filter_style = custom`). It is gone with
		 * the field itself: trigger style is the bar's visual language, so it is
		 * global. Rendering it now would fatal on the missing schema definition.
		 */

		/* --------------------------------------------------------------------- *
		 * Shared render helpers
		 * --------------------------------------------------------------------- */

		/**
		 * Print top-of-page notices accumulated during POST handling.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function render_notices() {
			foreach ( self::$notices as $notice ) {
				$class = ( 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';

				printf(
					'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $class ),
					esc_html( $notice['message'] )
				);
			}
		}

		/**
		 * A DOM-unique control id.
		 *
		 * @since 2.0.0
		 * @param string $key  Field key.
		 * @param string $mode Editor mode.
		 * @return string
		 */
		private static function ctrl_id( $key, $mode ) {
			return 'ecsa-' . $mode . '-' . $key;
		}

		/**
		 * The field definition from the schema (empty array if unknown).
		 *
		 * @since 2.0.0
		 * @param string $key Field key.
		 * @return array<string, mixed>
		 */
		private static function field_def( $key ) {
			$fields = Settings::fields();
			return isset( $fields[ $key ] ) ? $fields[ $key ] : array();
		}

		/**
		 * Which subtab a field belongs to (for auto-opening the errored subtab).
		 *
		 * @since 2.0.0
		 * @param string $key Field key.
		 * @return string
		 */
		private static function subtab_of( $key ) {
			$map = array(
				// Setup.
				'results_mode'         => 'compose',
				'tec_views'            => 'compose',
				'search_fields'        => 'compose',
				// Filters.
				'facets'               => 'filters',
				'filters_visibility'   => 'filters',
				// NOTE: `filter_columns` was mapped here. RETIRED with the axis.
				// NOTE: `filters_button_style` was mapped here. WITHDRAWN — there is
				// no field, so no error can be routed to a tab for it.
				'filter_style'         => 'filters',
				// Bar design.
				'placeholder'          => 'bar',
				'bar_template'         => 'bar',
				'accent_color'         => 'bar',
				'text_color'           => 'bar',
				'bg_color'             => 'bar',
				'button_style'         => 'bar',
				'control_size'         => 'bar',
				'corner_radius'        => 'bar',
				// Results.
				'view'                 => 'results',
				// The card TEMPLATE. Free ships `clean` alone; `modern` is Pro. On
				// Results with the other card
				// knobs, never on Bar design beside `bar_template` — that one frames
				// the search box and shares nothing but the word.
				'template'             => 'results',
				'columns'              => 'results',
				'card_size'            => 'results',
				'card_fields'          => 'results',
				'date_format'          => 'results',
				'per_page'             => 'results',
				'sort'                 => 'results',
				'time'                 => 'results',
				'recurrence'           => 'results',
			);

			return isset( $map[ $key ] ) ? $map[ $key ] : 'compose';
		}

		/* --------------------------------------------------------------------- *
		 * Value access
		 * --------------------------------------------------------------------- */

		/**
		 * The values the form renders from (submitted-on-error, else persisted).
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		private static function form_values() {
			if ( null === self::$form_values ) {
				self::$form_values = Settings::get();
			}

			return self::$form_values;
		}

		/**
		 * Read one field's current display value.
		 *
		 * @since 2.0.0
		 * @param string $key Field key.
		 * @return mixed
		 */
		private static function value( $key ) {
			$values = self::form_values();

			return array_key_exists( $key, $values ) ? $values[ $key ] : null;
		}

		/**
		 * Read an enum value coerced against its choices, falling back to the schema
		 * default (a rejected submission may hold an out-of-range raw value).
		 *
		 * @since 2.0.0
		 * @param string               $key Field key.
		 * @param array<string, mixed> $def Field definition.
		 * @return string
		 */
		private static function value_for_enum( $key, array $def ) {
			$raw     = self::value( $key );
			$choices = isset( $def['choices'] ) ? array_map( 'strval', (array) $def['choices'] ) : array();

			// bool surfaced as segmented '0'/'1'.
			if ( isset( $def['type'] ) && 'bool' === $def['type'] ) {
				return self::is_truthy( $raw ) ? '1' : '0';
			}

			$raw = is_scalar( $raw ) ? (string) $raw : '';
			if ( in_array( $raw, $choices, true ) ) {
				return $raw;
			}

			return isset( $def['default'] ) ? (string) $def['default'] : ( isset( $choices[0] ) ? $choices[0] : '' );
		}

		/**
		 * Interpret a value as a boolean.
		 *
		 * @since 2.0.0
		 * @param mixed $value Value.
		 * @return bool
		 */
		private static function is_truthy( $value ) {
			return ( true === $value || 1 === $value || '1' === $value || 'on' === $value || 'true' === $value );
		}

		/* --------------------------------------------------------------------- *
		 * Copy (labels/help/choices live here, not in the schema)
		 * --------------------------------------------------------------------- */

		/**
		 * Label + help text for a field.
		 *
		 * HELP IS OPTIONAL, and one field genuinely has none. `tec_views` renders a
		 * `.ecsa-contextnote` that changes with the selection, and
		 * a control with a selection-based note does not also get a static line —
		 * so its entry declares a `label` only, and the normaliser below is what
		 * lets it. Callers may keep reading `['help']` unconditionally.
		 *
		 * The help copy is deliberately terse: say what the control is FOR, drop
		 * everything the label and the choice labels already say, and KEEP every
		 * sentence that prevents a mistake (each is annotated `KEPT:` at its
		 * site).
		 *
		 * @since 2.0.0
		 * @param string $key Field key.
		 * @return array{label:string, help:string}
		 */
		private static function field_meta( $key ) {
			$meta = array(
				'tec_page_title'       => array(
					'label' => __( 'Events Page Title', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Optional heading shown above the search bar on the events page. Leave blank to show none.', 'events-search-addon-for-the-events-calendar' ),
				),
				'placeholder'          => array(
					'label' => __( 'Placeholder text', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: "leave blank" — an empty box is not obviously a default.
					'help'  => __( 'Shown in the empty search box. Blank uses the default.', 'events-search-addon-for-the-events-calendar' ),
				),
				// `typeahead` had a label + help here, for the retired site-wide
				// toggle. The setting is derived from the results placement now, and
				// the placement control carries the one sentence that explains it.
				'search_fields'        => array(
					'label' => __( 'Match search keyword in', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: what ticking nothing does — the control cannot show it.
					'help'  => __( 'Choose where to match the visitor search keyword to find events.', 'events-search-addon-for-the-events-calendar' ),
				),
				'facets'               => array(
					'label' => __( 'Choose filters to show in the bar', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: where the filter that is NOT in this list lives.
					'help'  => __( 'Drag to reorder and toggle which filters appear in the bar.', 'events-search-addon-for-the-events-calendar' ),
				),
				'filters_visibility'   => array(
					'label' => __( 'Where should filters appear?', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: the sticky-header warning — a dropdown that opens behind
					// the theme header looks like a broken filter, not a layout choice.
					'help'  => __( 'Choose where and how visitors will see the event filters.', 'events-search-addon-for-the-events-calendar' ),
				),
				// `filter_style` KEEPS ITS LABEL AND LOSES ITS HELP. The panel renders
				// no control for it (one value), so a help line would explain a
				// sentence nobody is reading — but the label is still what
				// `field_meta()` answers with, and callers other than the control
				// (a future summary, the generator's diff) ask for it by key.
				'filter_style'         => array(
					'label' => __( 'Filter controls style', 'events-search-addon-for-the-events-calendar' ),
				),
				// NOTE: `filters_button_style` had a label + help here. WITHDRAWN
				// with the control — a help line for a control nothing renders
				// documents nothing.
				'bar_template'         => array(
					'label' => __( 'Search bar frame', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Choose whether to show the search button inside or outside.', 'events-search-addon-for-the-events-calendar' ),
				),
				'accent_color'         => array(
					'label' => __( 'Main colour', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'The search button and active filters.', 'events-search-addon-for-the-events-calendar' ),
				),
				'text_color'           => array(
					'label' => __( 'Text colour', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Labels and typed text in the bar.', 'events-search-addon-for-the-events-calendar' ),
				),
				'bg_color'             => array(
					'label' => __( 'Background', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'The bar surface and its dropdown panels.', 'events-search-addon-for-the-events-calendar' ),
				),
				'button_style'         => array(
					'label' => __( 'Search button style', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: “None” leaves no visible way to search — say what replaces it.
					'help'  => __( '“None” removes the button — visitors press Enter instead.', 'events-search-addon-for-the-events-calendar' ),
				),
				'design_sizing'        => array(
					'label' => __( 'Custom size and rounding', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Off uses the built-in sizes and corners. On lets you set the sliders below.', 'events-search-addon-for-the-events-calendar' ),
				),
				'control_size'         => array(
					'label' => __( 'Bar size', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Scales the whole bar: height, text and spacing.', 'events-search-addon-for-the-events-calendar' ),
				),
				'corner_radius'        => array(
					'label' => __( 'Corner rounding', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Applies to the box, buttons and result cards.', 'events-search-addon-for-the-events-calendar' ),
				),
				// NOTE: `results_page_id` had a label + help here.
				'view'                 => array(
					'label' => __( 'Results view', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'The view results open in. Visitors can switch it.', 'events-search-addon-for-the-events-calendar' ),
				),
				'template'             => array(
					'label' => __( 'Card design', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Clean keeps the full date on the card’s text line.', 'events-search-addon-for-the-events-calendar' ),
				),
				'columns'              => array(
					'label' => __( 'Columns', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Cards side by side on a wide screen. Narrow screens use fewer.', 'events-search-addon-for-the-events-calendar' ),
				),
				'card_size'            => array(
					'label' => __( 'Card size', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Scales text and spacing inside each card.', 'events-search-addon-for-the-events-calendar' ),
				),
				'card_fields'          => array(
					'label' => __( 'Items to show inside card', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: what ticking nothing does.
					'help'  => __( 'Choose which event details to show on each result card.', 'events-search-addon-for-the-events-calendar' ),
				),
				'date_format'          => array(
					'label' => __( 'Date format', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: which WP screen “Site default” actually reads.
					'help'  => __( '“Site default” follows Settings → General.', 'events-search-addon-for-the-events-calendar' ),
				),
				'per_page'             => array(
					'label' => __( 'Results per page', 'events-search-addon-for-the-events-calendar' ),
					// KEPT: the accepted range — outside it the save is rejected.
					'help'  => __( 'How many events load at a time. 1 to 50.', 'events-search-addon-for-the-events-calendar' ),
				),
				'sort'                 => array(
					'label' => __( 'Default sort', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'The starting order. Visitors can change it.', 'events-search-addon-for-the-events-calendar' ),
				),
				'time'                 => array(
					'label' => __( 'Events to show', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'Upcoming events, past events, or all.', 'events-search-addon-for-the-events-calendar' ),
				),
				'recurrence'           => array(
					'label' => __( 'Recurring events', 'events-search-addon-for-the-events-calendar' ),
					'help'  => __( 'For a repeating event: its next date only, or every match.', 'events-search-addon-for-the-events-calendar' ),
				),
				/*
				 * NO `help`, and it is the ONLY entry without one: this control
				 * renders a `.ecsa-contextnote` that follows the
				 * selection, so a static line above it would describe all three modes
				 * directly over the line describing the chosen one. The "List view
				 * only" warning that line carried was NOT dropped — it moved into the
				 * two notes it applies to (see `events_page_section()`), which is
				 * strictly better placement: `off` never needed it.
				 */
				'tec_views'            => array(
					'label' => __( 'Do you want to customize the default events page search & filter bar and event results?', 'events-search-addon-for-the-events-calendar' ),
				),
			);

			/*
			 * NORMALISED, never returned raw. `ctrl_close()` reads `['help']`
			 * unconditionally, so an entry declaring only a label must still come
			 * back with an empty help rather than an undefined index — that is what
			 * makes "this control has no static help" expressible in the table
			 * instead of faked with an empty string.
			 */
			if ( isset( $meta[ $key ] ) ) {
				return array(
					'label' => isset( $meta[ $key ]['label'] ) ? (string) $meta[ $key ]['label'] : ucwords( str_replace( '_', ' ', $key ) ),
					'help'  => isset( $meta[ $key ]['help'] ) ? (string) $meta[ $key ]['help'] : '',
				);
			}

			return array(
				'label' => ucwords( str_replace( '_', ' ', $key ) ),
				'help'  => '',
			);
		}

		/**
		 * Theme-preset names, keyed to `Settings::color_presets()`.
		 *
		 * Lives here with the rest of the copy, not in the data layer: the
		 * preset table is colour data, and the schema carries no strings.
		 *
		 * @since 2.1.0
		 * @return array<string, string>
		 */
		private static function preset_labels() {
			return array(
				'ink_blue'   => __( 'Ink blue', 'events-search-addon-for-the-events-calendar' ),
				'deep_teal'  => __( 'Deep teal', 'events-search-addon-for-the-events-calendar' ),
				'crimson'    => __( 'Crimson', 'events-search-addon-for-the-events-calendar' ),
				'violet'     => __( 'Violet', 'events-search-addon-for-the-events-calendar' ),
				/* translators: preset name; “dark” means the bar sits on a dark background. */
				'lime_dark'  => __( 'Lime (dark)', 'events-search-addon-for-the-events-calendar' ),
				/* translators: preset name; “dark” means the bar sits on a dark background. */
				'amber_dark' => __( 'Amber (dark)', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * Choice labels, keyed by field then value.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, string>>
		 */
		private static function choice_labels() {
			return array(
				// `typeahead` had a label pair here. The site-wide control is gone
				// (the value is derived from the placement), so no surface in this
				// panel names its two values any more.
				'search_fields'        => array(
					'title' => __( 'Title', 'events-search-addon-for-the-events-calendar' ),
				),
				'facets'               => array(
					'search' => __( 'Keyword search', 'events-search-addon-for-the-events-calendar' ),
					'date'   => __( 'Date', 'events-search-addon-for-the-events-calendar' ),
				),
				// TWO meanings, two value spellings that say what they do. The old
				// `below | inbar | button` are deleted, not mapped: `below` had
				// already been relabelled once and would now have to mean the
				// opposite of what it says, and 2.0 never shipped, so the keys were
				// free to fix.
				'filters_visibility'   => array(
					'bar_inline' => __( 'Inside the bar', 'events-search-addon-for-the-events-calendar' ),
					'expanded'   => __( 'Always expanded', 'events-search-addon-for-the-events-calendar' ),
				),
				// TWO frames. "Pill" (`capsule`) and "Filled" (`soft`) are retired:
				// Corner rounding and Background colour already say both.
				'bar_template'         => array(
					'detached' => __( 'Separate', 'events-search-addon-for-the-events-calendar' ),
					'unified'  => __( 'Joined', 'events-search-addon-for-the-events-calendar' ),
				),
				'design_sizing'        => array(
					'off' => __( 'Off', 'events-search-addon-for-the-events-calendar' ),
					'on'  => __( 'On', 'events-search-addon-for-the-events-calendar' ),
				),
				'button_style'         => array(
					'solid'      => __( 'Solid', 'events-search-addon-for-the-events-calendar' ),
					'solid_icon' => __( 'Solid icon', 'events-search-addon-for-the-events-calendar' ),
					'outline'    => __( 'Outline', 'events-search-addon-for-the-events-calendar' ),
					'icon'       => __( 'Icon', 'events-search-addon-for-the-events-calendar' ),
					'text'       => __( 'Text', 'events-search-addon-for-the-events-calendar' ),
					'none'       => __( 'None', 'events-search-addon-for-the-events-calendar' ),
				),
				// ONE value, and the panel renders no control for it — but the label
				// stays, because `choice_label()` is also what the generator and any
				// future summary line read to NAME a stored value.
				'filter_style'         => array(
					'text' => __( 'Text', 'events-search-addon-for-the-events-calendar' ),
				),
				/*
				 * NOTE: four `filters_button_style` labels sat here. WITHDRAWN with
				 * the axis: nothing stores the key, so nothing needs to NAME a
				 * stored value for it — which is the job this table does.
				 */
				'card_fields'          => array(
					'image' => __( 'Image', 'events-search-addon-for-the-events-calendar' ),
					'title' => __( 'Title', 'events-search-addon-for-the-events-calendar' ),
					'date'  => __( 'Date', 'events-search-addon-for-the-events-calendar' ),
					'venue' => __( 'Venue', 'events-search-addon-for-the-events-calendar' ),
					'cost'  => __( 'Cost', 'events-search-addon-for-the-events-calendar' ),
				),
				/*
				 * The two placements. `results_where` — the client-only shaper
				 * these labels used to belong to — is gone, and so is its
				 * `separate` ("Elsewhere on this page") choice: the results
				 * shortcode can be pasted under the bar or anywhere else on the
				 * page, so "Below the bar" always covered both. No mapping is left
				 * behind for the retired value; 2.0 never shipped.
				 */
				'results_mode'         => array(
					'none'   => __( 'Typing dropdown suggestions', 'events-search-addon-for-the-events-calendar' ),
					'inline' => __( 'Below search bar', 'events-search-addon-for-the-events-calendar' ),
				),
				/*
				 * The two events-page modes. `Off` first, then the thing we can do.
				 * The label names the RESULT rather than the mechanism, because the
				 * mechanism is what the contextual note explains.
				 */
				'tec_views'            => array(
					'off'         => __( 'Don\'t change anything', 'events-search-addon-for-the-events-calendar' ),
					'header_swap' => __( 'Replace search & filters only', 'events-search-addon-for-the-events-calendar' ),
				),
				'view'                 => array(
					'grid' => __( 'Grid', 'events-search-addon-for-the-events-calendar' ),
					'list' => __( 'List', 'events-search-addon-for-the-events-calendar' ),
				),
				'template'             => array(
					'clean' => __( 'Clean', 'events-search-addon-for-the-events-calendar' ),
				),
				'sort'                 => array(
					'date_asc'  => __( 'Date (soonest first)', 'events-search-addon-for-the-events-calendar' ),
					'date_desc' => __( 'Date (latest first)', 'events-search-addon-for-the-events-calendar' ),
					'title'     => __( 'Title (A–Z)', 'events-search-addon-for-the-events-calendar' ),
				),
				'time'                 => array(
					'upcoming' => __( 'Upcoming', 'events-search-addon-for-the-events-calendar' ),
					'past'     => __( 'Past', 'events-search-addon-for-the-events-calendar' ),
					'all'      => __( 'All', 'events-search-addon-for-the-events-calendar' ),
				),
				'recurrence'           => array(
					'next_only'       => __( 'Next date only', 'events-search-addon-for-the-events-calendar' ),
					'all_occurrences' => __( 'All dates', 'events-search-addon-for-the-events-calendar' ),
				),
				/*
				 * The date-format labels ARE worked examples, so the admin picks a
				 * date they can read rather than a pattern they have to decode.
				 * They are rendered by `Renderer::date_format_examples()` from one
				 * fixed sample moment through `wp_date()`, which means they are
				 * localized, timezone-correct and — because they run the same code
				 * the cards do — incapable of drifting from what actually prints.
				 */
				'date_format'          => self::date_format_labels(),
			);
		}

		/**
		 * Date-format choice labels: the site-default row, then one worked example
		 * per shipped pattern.
		 *
		 * @since 2.2.0
		 * @return array<string, string>
		 */
		private static function date_format_labels() {
			$out = array();

			if ( ! class_exists( 'CoolPlugins\EventsSearch\Render\Renderer' ) ) {
				return array( 'site' => __( 'Site default', 'events-search-addon-for-the-events-calendar' ) );
			}

			foreach ( \CoolPlugins\EventsSearch\Render\Renderer::date_format_examples() as $key => $example ) {
				if ( 'site' === $key ) {
					$out[ $key ] = sprintf(
						/* translators: %s: an example date rendered with the site's own date/time format. */
						__( 'Site default — %s', 'events-search-addon-for-the-events-calendar' ),
						$example
					);
					continue;
				}

				$out[ $key ] = $example;
			}

			return $out;
		}

		/**
		 * Resolve a single choice label.
		 *
		 * @since 2.0.0
		 * @param string $field Field key.
		 * @param string $value Choice value.
		 * @return string
		 */
		private static function choice_label( $field, $value ) {
			$labels = self::choice_labels();

			if ( isset( $labels[ $field ][ $value ] ) ) {
				return $labels[ $field ][ $value ];
			}

			return ucwords( str_replace( array( '_', '-' ), ' ', $value ) );
		}
	}
}
