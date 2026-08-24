<?php
/**
 * Server-side renderer — the first paint the JS runtime hydrates.
 *
 * The markup here is the authority for the DOM contract.
 * Every interactive control lives inside a real
 * `<form method="get">`, so the bar works with JavaScript disabled: submitting
 * navigates to a URL this same renderer paints. The JS calls preventDefault()
 * and enhances in place.
 *
 * Escaping discipline: facet and card text is PLAIN TEXT authored by admins or
 * event editors — esc_html / esc_attr on the way out, never wp_kses. URLs via
 * esc_url. The seed state is wp_json_encode'd into a data attribute and read by
 * JS with JSON.parse, never innerHTML.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Render;

use CoolPlugins\EventsSearch\Query\Criteria;
use CoolPlugins\EventsSearch\Query\Url_State;
use CoolPlugins\EventsSearch\Query\Query_Engine;
use CoolPlugins\EventsSearch\Query\Date_Presets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Renderer' ) ) {

	/**
	 * Renders the bar, the results region and every §1.7 state.
	 *
	 * @since 2.0.0
	 */
	final class Renderer {

		/**
		 * Per-request instance counter, so every bar on a page gets a distinct
		 * id and no two share a DOM id (the v1.3.6 "only the first instance
		 * works" debt).
		 *
		 * @var int
		 */
		private static $counter = 0;

		/**
		 * Whether some instance on this page has already claimed URL ownership.
		 *
		 * @var bool
		 */
		private static $url_owner_claimed = false;

		/**
		 * Date presets offered in the bar, in display order.
		 *
		 * @var string[]
		 */
		const DATE_CHIPS = array( 'any', 'today', 'tomorrow', 'this_weekend', 'this_week', 'next_week', 'this_month', 'next_month' );

		/**
		 * The custom-range preset. Deliberately NOT a member of `DATE_CHIPS`: it is
		 * not a preset row like the other eight, it is the row that REVEALS two date
		 * inputs, and it is the reason date is the only filter in the bar carrying an
		 * Apply button — a half-picked range is not a query.
		 *
		 * @var string
		 */
		const DATE_CUSTOM = 'custom';

		/**
		 * Option count above which a panel earns its own in-panel search box
		 * (handoff: "shown when the list exceeds ~10 options").
		 *
		 * @var int
		 */
		const PANEL_SEARCH_MIN = 10;

		/*
		 * NOTE: `SINGLE_FACETS` (`venue`, `organizer`) lived here, naming the id
		 * facets whose panels rendered RADIOS rather than checkboxes because their
		 * cardinality is one. There are no id facets in this plugin, so there is
		 * no cardinality question left to answer.
		 */

		/**
		 * Render a complete instance: wrapper + bar and/or results.
		 *
		 * @since 2.0.0
		 * @param array                     $config   Instance config (see DOM contract §1).
		 * @param array<string, mixed>|null $criteria Seed criteria; parsed from the URL when null.
		 * @return string
		 */
		public static function instance( array $config, $criteria = null ) {
			$config = self::normalize_config( $config );


			/*
			 * Claimed BEFORE the criteria are resolved, because whether this
			 * instance owns the URL decides whether `?ecsa_view` is addressed to
			 * it (see below). The claim is order-sensitive — first qualifying
			 * instance on the page wins — and moving it earlier inside this one
			 * method does not change the order instances reach it in.
			 */
			$is_owner = self::claim_url_owner( $config );

			if ( null === $criteria ) {
				$criteria = self::criteria_from_request( $config );

				/*
				 * GRID/LIST IS A VISITOR PREFERENCE, and `ecsa_view` has always been
				 * a URL param — but nothing read it back into the render, so a
				 * shared `?ecsa_view=list` link painted the admin's default and only
				 * flipped once JavaScript ran. `criteria_from_request()` SEEDS the
				 * view from this very config and lets the URL overlay it, so the
				 * owner branch is a no-op unless the URL actually carried one; when
				 * it did, the visitor's choice wins for the whole render (the cards,
				 * the toolbar toggle and the no-JS form alike).
				 *
				 * ONLY FOR THE URL OWNER, and this is not a nicety. Every instance
				 * on the page runs `criteria_from_request()` against the same
				 * `$_GET`, so without the else branch a page with TWO results
				 * placements — say an "Upcoming" grid and a "Featured" list — would
				 * have one visitor's toggle repaint BOTH, and the untouched one's
				 * toggle would show a pressed state its admin never chose and its
				 * visitor never asked for. A layout preference belongs to the region
				 * the visitor was actually looking at; a second region never claimed
				 * the URL, so `?ecsa_view` is not addressed to it and it keeps the
				 * layout its own configuration asked for. The runtime already draws
				 * the same line — `Instance.start()` returns early for a non-owner —
				 * so this keeps the SSR paint and the client in agreement.
				 *
				 * Only on the request-derived path: a caller that hands us an
				 * explicit criteria set (the settings preview) is stating its own
				 * intent, and its criteria must not silently redefine the config.
				 */
				/*
				 * AND THE SAME GOES FOR THE REST OF THEM.
				 *
				 * Everything above is about `view`, and everything above is equally
				 * true of the other region-owned params. `?ecsa_time=all` — which the
				 * "browse past events" recovery link writes into the address bar —
				 * dragged EVERY results region on the page out of its configured
				 * window on the next load, including ones the visitor never touched.
				 * `?ecsa_per_page` and `?ecsa_sort` had the same reach.
				 *
				 * The list mirrors `REGION_OWNED` in `assets/js/v2/ecsa-frontend.js`
				 * on purpose: that is the runtime's statement of which keys belong to
				 * a region rather than to the bar driving it, and the SSR has to draw
				 * the same line or the first interaction silently re-renders the page
				 * differently from how it was served. `search_fields` is in that list
				 * too but is admin-only and never a URL param, so it cannot be
				 * overlaid and needs no branch.
				 *
				 * `sort` has no config key: the toolbar owns it at runtime and
				 * nothing configures it, so a non-owner falls back to the criteria
				 * default rather than to a setting that does not exist.
				 */
				$defaults = Criteria::defaults();

				foreach ( array( 'view', 'time', 'per_page', 'sort' ) as $owned ) {
					if ( $is_owner ) {
						if ( isset( $config[ $owned ] ) ) {
							$config[ $owned ] = $criteria[ $owned ];
						}
						continue;
					}

					$criteria[ $owned ] = isset( $config[ $owned ] )
						? $config[ $owned ]
						: $defaults[ $owned ];
				}
			} else {
				$criteria = Criteria::normalize( $criteria );
			}

			$instance_id = self::next_instance_id();

			/*
			 * ONE role renders results, and it is the only one: `results`. A
			 * SEARCH BAR NEVER APPENDS RESULTS — they come from the results
			 * results shortcode, linked to the bar by a shared
			 * `target` id. The retired `bar-results` used to be the second arm of
			 * this test; deleting it here is what actually makes the bar bar-only,
			 * and it must stay collapsed to a single comparison so no future value
			 * can quietly rejoin it.
			 */
			$renders_results = ( 'results' === $config['role'] );

			$result = $renders_results ? self::run_engine( $criteria ) : array();

			$seed = wp_json_encode( $criteria );
			$hash = Criteria::hash( $criteria );

			$design = self::design_attrs( $config );

			$attrs = array(
				'class'                    => $design['class'],
				'data-ecsa-instance'       => $instance_id,
				'data-ecsa-role'           => $config['role'],
				'data-ecsa-typeahead'      => $config['typeahead'],
				// How many rows THIS bar's dropdown asks for. Carried in the DOM
				// rather than localized once, because two bars on one page may
				// legitimately want different counts (a 1.3.6 `show-events="5"`
				// beside a modern one). Already clamped; the REST route clamps it
				// again, since it arrives there as a query parameter.
				'data-ecsa-suggest-limit'  => (string) (int) $config['suggest_limit'],
				'data-ecsa-results-mode'   => $config['results_mode'],
				'data-ecsa-target'         => $config['target'],
				'data-ecsa-url-owner'      => $is_owner ? '1' : '0',
				'data-ecsa-criteria'       => $hash,
				'data-ecsa-state'          => (string) $seed,
			);

			if ( '' !== $design['style'] ) {
				$attrs['style'] = $design['style'];
			}

			$html = '<div' . self::attrs( $attrs ) . '>';

			if ( 'results' !== $config['role'] ) {
				$html .= self::bar( $instance_id, $config, $criteria );
			}

			if ( $renders_results ) {
				$html .= self::results( $instance_id, $config, $criteria, $result );
			}

			$html .= '</div>';

			return $html;
		}

		/**
		 * Render the search bar form.
		 *
		 * @since 2.0.0
		 * @param string $instance_id Instance id.
		 * @param array  $config      Config.
		 * @param array  $criteria    Normalized criteria.
		 * @return string
		 */
		public static function bar( $instance_id, array $config, array $criteria ) {
			// bar() is public; a direct caller may hand a config that never passed
			// through normalize_config(). Re-clean the display keys so the facet
			// methods below can trust them.
			$config = self::normalize_display_config( $config );

			$input_id   = $instance_id . '__input';
			$listbox_id = $instance_id . '__listbox';
			$destination = self::bar_action( $config );

			/*
			 * AN EMPTY ACTION IS NOT "NOWHERE" IN HTML — it means the current URL,
			 * which is precisely the reload this change exists to stop. So the
			 * no-destination case keeps a working action for the no-JS path (where
			 * reloading is pointless but harmless, and at least keeps the URL
			 * honest) and is MARKED, so the runtime can intercept and answer from
			 * the suggestions instead of navigating.
			 */
			$has_destination = ( '' !== $destination );
			$action          = esc_url( $has_destination ? $destination : self::current_url_base() );

			$placeholder = '' !== $config['placeholder']
				? $config['placeholder']
				: __( 'Search events', 'events-search-addon-for-the-events-calendar' );

			// Facets, in the configured order. Each is a real form control so a
			// no-JS submit carries it. The chosen presentation (trigger geometry,
			// placement) only wraps these SAME controls — their name/value contract
			// is identical across every style, so the query and the no-JS submit
			// never change. Built BEFORE the form markup because the in-bar Filters
			// trigger (emitted inside the plate, before the Search button) renders
			// only when there is a non-empty filters region for it to toggle, so it
			// needs the assembled facet markup in hand.
			//
			// EVERY filter is a TRIGGER + a PANEL — that is the whole model — with
			// exactly one exception: `filters_visibility = expanded`, which is the
			// PLACEMENT that shows every filter's options at once. There the
			// triggers and panels are replaced by plain columns of the same
			// controls, so the options-visible layout the old `pills` style used to
			// provide is not lost, it simply moved axes.
			$expanded = ( 'expanded' === $config['filters_visibility'] );

			/*
			 * The admin preview must show what a VISITOR sees, and a visitor sees the
			 * JS-enhanced bar: styled triggers, with the native <select> substrate
			 * hidden. Rendered naively the preview shows the opposite — the raw
			 * substrate, because the trigger ships `hidden` and only the front-end
			 * runtime unhides it (assets/js/v2/ecsa-frontend.js, which hides the
			 * substrate and reveals the trigger).
			 *
			 * So the preview emits the POST-enhancement state directly: no JS runs on
			 * it, no request is made, and it still matches the front end exactly. This
			 * is computed here rather than at the <form> below because the facets are
			 * built first.
			 */
			$preview_bar = ! empty( $config['preview_mode'] );

			$facets_html = '';
			foreach ( $config['facets'] as $facet ) {
				if ( 'search' === $facet ) {
					continue;
				}
				if ( $expanded ) {
					$facets_html .= self::expanded_column( $instance_id, $facet, $criteria );
				} elseif ( 'date' === $facet ) {
					$facets_html .= self::date_facet( $instance_id, $criteria, $preview_bar );
				}
				/*
				 * NOTE: two further arms ran here — `location`, which drew the
				 * three dependent Country/State/City selects, and a catch-all
				 * `id_facet()` for category / tag / venue / organizer. Neither
				 * facet is part of this plugin, and there is deliberately NO `else`
				 * left: an unrecognised facet key renders nothing rather than
				 * falling into a generic renderer, so a tampered `facets` value
				 * cannot conjure a control the registry does not declare.
				 */
			}

			// The keyword input is a FACET too: it renders only when `search` is
			// in the facet set. Dropping it yields a filters-only bar (the user's
			// "filters only, no search box" composition). A bar with neither
			// search nor any other facet cannot occur — Instance guarantees at
			// least `search`.
			$has_search = in_array( 'search', $config['facets'], true );

			/*
			 * The bar is a real GET <form> so it works with JavaScript disabled.
			 *
			 * EXCEPT in preview mode: the settings-panel preview is injected
			 * INSIDE the settings screen's own <form>, and HTML forbids nested
			 * forms — the parser silently drops the inner <form> tag and keeps
			 * its children, which quietly removed `.ecsa-bar` itself and with it
			 * the entire bar frame (surface, padding, template border). A preview
			 * bar is an inert, aria-hidden picture that is never submitted, so it
			 * renders as a <div> carrying the identical class; all styling is
			 * class-based, so it paints exactly the same.
			 */
			$preview = ! empty( $config['preview_mode'] );

			/*
			 * THE INNER SHELL — the one structural change item 9 rests on.
			 *
			 * `.ecsa-bar` used to be BOTH the <form> and the painted plate, so any
			 * filter markup inside the form was inside the plate's border,
			 * background and padding. "Filters below the bar, outside its outline"
			 * was therefore not expressible at all.
			 *
			 * Now `.ecsa-bar` is only the form (a layout container that paints
			 * nothing) and `.ecsa-bar__shell` is the plate, wrapping ONLY the
			 * keyword row: the keyword input, the in-bar
			 * Filters trigger and the Search submit. Every filters region is a
			 * SIBLING of that shell — inside the form (so a no-JS submit still
			 * carries every control) and outside the outline.
			 *
			 * The submit is a child of the SHELL rather than of `.ecsa-combobox`,
			 * which is what lets `bar_inline` order the strip between the input and
			 * the button without the strip having to live inside the combobox.
			 *
			 * (The shell used to wrap a location field as well; that control is not
			 * part of this plugin, so the keyword row is the input, the in-bar
			 * Filters trigger and the Search submit.)
			 *
			 * `bar_inline` adds ONE more wrapper, `.ecsa-bar__row`, holding the
			 * shell and the inline strip. It exists for a mechanical reason: a
			 * container query cannot restyle its own container, and the wide state
			 * has to move the frame off the shell and onto the element that
			 * encloses both. That is the `.ecsa-bar--filters-inline` mechanism,
			 * reconciled — there is exactly ONE framing mechanism, `.ecsa-bar__shell`
			 * by default and `.ecsa-bar__row` in the inline row, never two
			 * competing ones.
			 *
			 * The frame moves by RE-POINTING PAINT, never by clipping: `overflow:
			 * hidden` on any ancestor of the keyword input is what the runtime reads
			 * as a broken dropdown, after which it self-heals the type-ahead away
			 * for good.
			 */
			/*
			 * IS THERE A KEYWORD ROW AT ALL?
			 *
			 * The shell is the painted frame around that row; the combobox is
			 * the row. A bar built from filters alone has neither, and drawing
			 * them anyway produced an empty box with a Search button beside it
			 * that had nothing to search. Only reachable by hand — the settings
			 * panel offers "search" or "search + filters", never filters alone.
			 */
			$has_shell = $has_search;

			/*
			 * AND THE INLINE ROW NEEDS ONE TO EXIST.
			 *
			 * `.ecsa-bar--inline` is what creates the size container, and its
			 * query collapses the strip and reveals the Filters button instead.
			 * That button lives in the plate. With no plate the strip would be
			 * hidden by the query and nothing could bring it back, so a
			 * filters-only bar keeps the strip plainly below — which is what
			 * the collapsed state falls back to anyway.
			 */
			$inline_filters = ( 'bar_inline' === $config['filters_visibility'] && '' !== $facets_html && $has_shell );
			$bar_class      = 'ecsa-bar' . ( $inline_filters ? ' ecsa-bar--inline' : '' );

			$html  = $preview
				? '<div class="' . esc_attr( $bar_class ) . '" role="search">'
				: '<form class="' . esc_attr( $bar_class ) . '" role="search" method="get" action="' . $action . '"'
					. ( $has_destination ? '' : ' data-ecsa-no-destination="1"' )
					. '>';

			if ( $inline_filters ) {
				$html .= '<div class="ecsa-bar__row">';
			}

			if ( $has_shell ) {
				$html .= '<div class="ecsa-bar__shell">';
				$html .= '<div class="ecsa-combobox">';

			// NOTE: a searchable location field was emitted here, BEFORE the
			// keyword field, as a bar-shell control rather than a facet. It is not
			// part of this plugin, so the combobox holds the keyword input alone.

			if ( $has_search ) {
				$html .= '<label class="screen-reader-text" for="' . esc_attr( $input_id ) . '">'
					. esc_html__( 'Search events', 'events-search-addon-for-the-events-calendar' ) . '</label>';

				$combo = ( 'dropdown' === $config['typeahead'] );

				$html .= '<input type="search" id="' . esc_attr( $input_id ) . '" name="ecsa_q"'
					. ' class="ecsa-combobox__input" value="' . esc_attr( $criteria['q'] ) . '"'
					. ' placeholder="' . esc_attr( $placeholder ) . '"'
					. ' autocomplete="off" enterkeyhint="search" autocorrect="off" autocapitalize="none" spellcheck="false"';

				if ( $combo ) {
					$html .= ' role="combobox" aria-autocomplete="list" aria-expanded="false"'
						. ' aria-controls="' . esc_attr( $listbox_id ) . '" aria-activedescendant=""';
				}

				$html .= '>';
			}

				$html .= '</div>';

				// The in-bar Filters trigger: a real button inside the plate, BEFORE
			// the Search submit, that reveals the filters region below the bar.
			// Only `bar_inline` renders it, because it is that placement's own
			// collapsed state rather than a placement of its own. Returns '' for
			// `expanded` (and when there are no facets), so a search-only bar is
			// untouched.
				$html .= self::inbar_trigger( $instance_id, $config, $criteria, $facets_html );

			/*
			 * `button_style = none` submits on Enter only. The <button> is still
			 * emitted (hidden by CSS) so the form keeps a submit control for
			 * assistive tech and for the no-JS/implicit-submission path — removing
			 * it would break Enter in some browsers when the form has several
			 * fields. A small "↵ enter" hint keeps the affordance discoverable;
			 * it is decorative, hence aria-hidden.
			 */
				$html .= '<button type="submit" class="ecsa-bar__submit">'
					. esc_html__( 'Search', 'events-search-addon-for-the-events-calendar' ) . '</button>';

			// `bar()` is public and documents that it re-cleans its input, but
			// normalize_display_config() only fills the DISPLAY keys — so a direct
			// caller with a partial config would warn here. Guarded rather than
			// assumed, like every other read in this method.
			$submit_style = isset( $config['button_style'] ) ? $config['button_style'] : 'solid_icon';

				if ( $has_search && 'none' === $submit_style ) {
					$html .= '<span class="ecsa-bar__enter" aria-hidden="true">'
						. '<span class="ecsa-bar__enter-key">&#8629;</span> '
						. esc_html__( 'enter', 'events-search-addon-for-the-events-calendar' )
						. '</span>';
				}

				$html .= '</div>';
			}

			// How the filter row learns whether a keyword row was drawn above it.
			// Through the config rather than a fifth argument: `filters_region()`
			// has more than one caller.
			$config['__has_shell'] = $has_shell;
			$config['__preview']   = $preview;

			$html .= self::filters_region( $instance_id, $config, $criteria, $facets_html );

			if ( $inline_filters ) {
				$html .= '</div>';
			}

			$html .= $preview ? '</div>' : '</form>';

			return $html;
		}

		/**
		 * Wrap the assembled facets for the chosen PLACEMENT. Both values are
		 * handled here, and each returns markup that `bar()` emits as a SIBLING of
		 * `.ecsa-bar__shell` — i.e. outside the painted plate. That is the whole
		 * point of the inner shell:
		 *
		 * - `bar_inline` — the controls are emitted as a bare strip that the
		 *   stylesheet lays into the keyword row (`[input] [filters] [search]`).
		 *   When the bar runs out of room a container query collapses the strip and
		 *   reveals the in-bar button, so the strip folds away without a second
		 *   DOM: one set of controls, one container changing state. No `<details>`
		 *   here — a closed `<details>` cannot be re-opened by CSS in every engine,
		 *   so the wide state would be at the mercy of the `open` attribute.
		 * - `expanded` — no disclosure and no triggers: every filter's options
		 *   render at once in columns (see `expanded_column()`).
		 *
		 * NOTE: two further placements were handled here. `bar_button` collapsed
		 * the triggers behind a `Filters (N)` `<details>` whose visible control was
		 * the in-bar button, and `under_bar` sat them below the bar with no button
		 * at all. Neither is part of this plugin. The `<details>/<summary>`
		 * substrate `bar_button` needed went with it — `bar_inline`'s strip is a
		 * plain `<div>`, so nothing here emits a native disclosure any more.
		 *
		 * An unrecognised placement falls through to the `bar_inline` strip rather
		 * than to a disclosure: a visible, usable control beats one that a missing
		 * runtime could leave unopenable.
		 *
		 * @since 2.0.0
		 * @param string $instance_id Instance id.
		 * @param array  $config      Normalized config.
		 * @param array  $criteria    Normalized criteria.
		 * @param string $facets_html The assembled facet markup.
		 * @return string
		 */
		private static function filters_region( $instance_id, array $config, array $criteria, $facets_html ) {
			unset( $criteria );

			if ( '' === $facets_html ) {
				return '';
			}

			$visibility = isset( $config['filters_visibility'] ) ? $config['filters_visibility'] : 'expanded';
			$region_id  = $instance_id . '__filters';
			$keyword_row = ! empty( $config['__has_shell'] );

			/*
			 * A COMMIT CONTROL, BUT ONLY FOR A VISITOR WHO NEEDS ONE.
			 *
			 * With the runtime up every filter commits on change, and this bar drives
			 * its results region directly — a button would be a second way to do what
			 * the click already did. Without it nothing intercepts the change, and a
			 * form with no submit and no text field does not submit on Enter either:
			 * the filter row would be inert with the results sitting right below it.
			 *
			 * The bar's Search button used to cover this. A filters-only bar has none,
			 * so the row carries its own, inside <noscript> — the way the sort control
			 * already does, so a scripted visitor never meets it.
			 *
			 * `Apply` is what the date panel and the expanded range already call this,
			 * so it is not new copy.
			 */
			$commit = ( ! $keyword_row && empty( $config['__preview'] ) )
				? '<noscript><button type="submit" class="ecsa-filters__apply">'
					. esc_html__( 'Apply', 'events-search-addon-for-the-events-calendar' ) . '</button></noscript>'
				: '';

			// Every filter open at once. No disclosure, no trigger, no JS: the
			// columns hold the real, enabled controls, so this placement is also the
			// safe answer on a theme whose sticky header would win a z-index fight
			// with a popover panel.
			if ( 'expanded' === $visibility ) {
				/*
				 * A DATE-ONLY EXPANDED ROW IS NOT A PANEL.
				 *
				 * The card — surface, border, radius, padding — exists to hold
				 * SEVERAL filter columns together and separate them from the page.
				 * With one control in it, and that control already a row of chips
				 * that reads as a unit, the card is a box drawn around a box: the
				 * chips get an outline they do not need and the bar above gets
				 * pushed away from its own filters.
				 *
				 * So a date-only FILTER ROW drops the chrome and sits directly
				 * under the bar. Date beside another filter keeps it, because then
				 * the card is doing its job again.
				 *
				 * `search` IS NOT A FILTER CONTROL and is excluded from the count.
				 * It is the keyword box in the bar itself, and it never renders a
				 * column in this row — so counting it made `search,date` look like
				 * two filters and kept the card. That pairing is the DEFAULT, and
				 * the only one this rule can actually reach: the settings panel
				 * offers no way to drop search and keep date, so testing the raw
				 * list meant the bare state was unreachable from the UI.
				 */
				$facet_list = isset( $config['facets'] ) ? (array) $config['facets'] : array();
				$filters    = array_values( array_diff( $facet_list, array( 'search' ) ) );

				/*
				 * ANY single filter, not just date. The card groups columns; with
				 * one column there is nothing to group, whichever facet it is.
				 * `search` is excluded above because it renders no column here — it
				 * is the keyword box in the bar itself.
				 */
				$bare       = ( 1 === count( $filters ) );

				return '<div class="ecsa-filters-expanded'
					. ( $bare ? ' ecsa-filters-expanded--bare' : '' )
					. '" id="' . esc_attr( $region_id ) . '">'
					. $facets_html
					. $commit
					. '</div>';
			}

			/*
			 * The inline strip. A plain <div> and NOT a <details>, deliberately:
			 * the wide state needs the controls visible with no `open` attribute to
			 * depend on, and modern engines render a closed <details>'s contents
			 * through a slot that CSS cannot reliably un-hide. So the collapse is
			 * driven by the container query plus, once the runtime is up, one
			 * `data-ecsa-filters-open` flag the in-bar button toggles.
			 *
			 * WITHOUT JavaScript on a narrow bar the strip simply stays visible
			 * below the plate. That is the correct degradation: a control the
			 * visitor can use beats a button that cannot open anything.
			 */
			return '<div class="ecsa-filters-inline" id="' . esc_attr( $region_id ) . '">'
				. $facets_html
				. $commit
				. '</div>';
		}

		/**
		 * The in-bar Filters trigger — a real `<button>` placed inside the painted
		 * plate, BEFORE the Search submit, that reveals the `__filters` region
		 * `filters_region()` emits below the bar (outside the plate).
		 *
		 * Renders for `filters_visibility = bar_inline` ONLY, and only when there is
		 * a non-empty filters region to control; every other case returns '' so a
		 * search-only bar — and the whole `expanded` placement — is byte-identical
		 * without it.
		 *
		 * IT IS NOT A PLACEMENT, IT IS `bar_inline`'S NARROW STATE. The strip and
		 * the button are one set of controls in one DOM: a container query
		 * collapses the strip and reveals this button, so the visitor never meets
		 * two "Filters" controls and assistive tech never announces the region
		 * twice.
		 *
		 * Details of the contract:
		 *
		 * - `type="button"`, so it never submits the surrounding search form.
		 * - `aria-controls` points at the region id, and `aria-expanded` mirrors
		 *   that region's initial state, so the two agree on the first frame.
		 * - No-JS: the `bar_inline` strip is simply visible, so the filters remain
		 *   reachable with JavaScript disabled and this button is the enhancement
		 *   layered on top. It is inert until the front-end runtime wires it.
		 * - Its badge reuses the `.ecsa-filters-count` class the runtime's
		 *   `updateFilterCount()` already keeps live (it reads the FIRST match in the
		 *   form, which — because this button precedes the summary — is this one),
		 *   and `active_filter_count()` for the number, matching the summary badge.
		 *
		 * HOW IT LOOKS IS NO LONGER A SETTING. A `filters_button_style` axis chose
		 * between four treatments here; it is withdrawn, and `design_attrs()` now
		 * stamps the one shipped look (`ecsa--fbtn-icon-text-bordered`) as a
		 * literal. The markup is unchanged and unconditional — funnel, label and
		 * badge are always all emitted, and §9d paints them — which is what kept the
		 * accessible name intact under the old icon-only value and what keeps this
		 * method free of any look-dependent branch.
		 *
		 * @since 2.0.0
		 * @param string $instance_id Instance id.
		 * @param array  $config      Normalized config.
		 * @param array  $criteria    Normalized criteria.
		 * @param string $facets_html The assembled facet markup (empty = nothing to toggle).
		 * @return string
		 */
		private static function inbar_trigger( $instance_id, array $config, array $criteria, $facets_html ) {
			$visibility = isset( $config['filters_visibility'] ) ? $config['filters_visibility'] : 'expanded';

			if ( '' === $facets_html || 'bar_inline' !== $visibility ) {
				return '';
			}

			$region_id = $instance_id . '__filters';
			$active    = self::active_filter_count( $criteria );
			$count     = $active > 0 ? '(' . $active . ')' : '';
			$expanded  = $active > 0 ? 'true' : 'false';

			/*
			 * Hidden in the SSR so a no-JS visitor never meets a dead button — the
			 * `bar_inline` strip is simply visible instead. The runtime unhides
			 * this button when the container query collapses the strip, so exactly
			 * one trigger is ever active. `data-ecsa-inbar` is the JS hook and
			 * `data-ecsa-inline` tells the runtime which region it is opening.
			 *
			 * IT STAYS HIDDEN IN THE ADMIN PREVIEW. The preview's picture is the
			 * UNCOLLAPSED row, where this button is not painted at all, so
			 * revealing it would show a control beside the very filters it exists
			 * to replace. (The preview does emit the post-enhancement state for the
			 * facet triggers themselves — see `bar()` — because those ARE painted
			 * in the state it is showing.)
			 */
			$inline = ' data-ecsa-inline="1"';

			$html  = '<button type="button" class="ecsa-filters-inbar" hidden'
				. ' data-ecsa-inbar="' . esc_attr( $region_id ) . '"' . $inline
				. ' aria-expanded="' . esc_attr( $expanded ) . '"'
				. ' aria-controls="' . esc_attr( $region_id ) . '">';
			$html .= '<svg class="ecsa-filters-inbar__icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">'
				. '<path fill="currentColor" d="M1.5 2h13a.5.5 0 0 1 .38.82L10 8.7v4.3a.5.5 0 0 1-.72.45l-2-1A.5.5 0 0 1 7 12V8.7L1.12 2.82A.5.5 0 0 1 1.5 2z"/>'
				. '</svg>';
			$html .= '<span class="ecsa-filters-inbar__label">'
				. esc_html__( 'Filters', 'events-search-addon-for-the-events-calendar' ) . '</span>';
			$html .= ' <span class="ecsa-filters-count">' . esc_html( $count ) . '</span>';
			/*
			 * NOTE: a `.ecsa-filters-inbar__caret` chevron was emitted here, for the
			 * retired `text_caret` value. RETIRED with it — no value of the axis
			 * draws a caret now, so the span was markup no stylesheet rule could
			 * ever paint. The panel's own disclosure is what says "open"; a chevron
			 * beside it spent bar width repeating that.
			 */
			$html .= '</button>';

			return $html;
		}

		/**
		 * Render the date-preset facet as a trigger + panel.
		 *
		 * There is no longer a per-filter style choice: `filter_style` is the
		 * TRIGGER geometry and it is global (a wrapper class — see
		 * `design_attrs()`), so every filter takes this one path. The radios are the
		 * same either way (`name="ecsa_date"`, the same preset values); they live
		 * behind a `When ▾` trigger that JS opens as a portal popover, with a native
		 * `<select name="ecsa_date">` as the no-JS substrate. Radios, not buttons:
		 * the presets are mutually exclusive, so a radiogroup is the correct
		 * semantics and a no-JS submit carries the choice.
		 *
		 * The one placement that shows the presets inline is
		 * `filters_visibility = expanded`, which never reaches here — `bar()` routes
		 * it to `expanded_column()`.
		 *
		 * @since 2.0.0
		 * @param string $instance_id Instance id (for the popover panel id).
		 * @param array  $criteria    Normalized criteria.
		 * @return string
		 */
		private static function date_facet( $instance_id, array $criteria, $preview = false ) {
			$labels = self::date_labels();
			$active = isset( $criteria['date_preset'] ) ? $criteria['date_preset'] : 'any';

			// `custom` is a first-class row now — it is the one that reveals the two
			// range inputs — so it no longer degrades to "any". Anything else
			// unrecognised still does.
			$known          = array_merge( self::DATE_CHIPS, array( self::DATE_CUSTOM ) );
			$checked_preset = in_array( $active, $known, true ) ? $active : 'any';
			$legend         = __( 'When', 'events-search-addon-for-the-events-calendar' );

			// Criteria keeps `date_from`/`date_to` ONLY for the custom preset, so
			// reading them under any other preset would paint a range that is not
			// part of the query.
			$is_custom = ( self::DATE_CUSTOM === $checked_preset );
			$from      = ( $is_custom && isset( $criteria['date_from'] ) ) ? (string) $criteria['date_from'] : '';
			$to        = ( $is_custom && isset( $criteria['date_to'] ) ) ? (string) $criteria['date_to'] : '';

			return self::date_facet_dropdown( $instance_id, $checked_preset, $labels, $legend, $from, $to, $preview );
		}

		/**
		 * The date radiogroup — the shared inner markup for the chips style and
		 * the dropdown popover panel.
		 *
		 * @since 2.0.0
		 * @param string $checked_preset The active preset.
		 * @param array  $labels         Preset labels.
		 * @param string $aria_label     The group's accessible name.
		 * @param bool   $disabled       Emit the radios disabled (popover panel:
		 *                               JS enables them; the native `<select>` is
		 *                               the no-JS control until then).
		 * @param bool   $with_custom    Append the "Custom range" row. True for the
		 *                               popover panel (which also renders the two
		 *                               range inputs it reveals); false for the
		 *                               `expanded` column, whose markup stays as it
		 *                               was.
		 * @return string
		 */
		private static function date_chips_inner( $checked_preset, array $labels, $aria_label, $disabled, $with_custom = false ) {
			$dis    = $disabled ? ' disabled' : '';
			$chips  = self::DATE_CHIPS;

			if ( $with_custom ) {
				$chips[] = self::DATE_CUSTOM;
			}

			$html = '<div class="ecsa-chips" role="radiogroup" aria-label="' . esc_attr( $aria_label ) . '">';

			foreach ( $chips as $preset ) {
				$checked = ( $preset === $checked_preset ) ? ' checked' : '';
				// The custom row is the only one that DOES something besides select
				// itself, so it is the only one carrying a modifier.
				$modifier = ( self::DATE_CUSTOM === $preset ) ? ' ecsa-chip--custom' : '';

				$html .= '<label class="ecsa-chip' . $modifier . '">'
					. '<input type="radio" name="ecsa_date" value="' . esc_attr( $preset ) . '"' . $checked . $dis . '> '
					. esc_html( isset( $labels[ $preset ] ) ? $labels[ $preset ] : $preset )
					. '</label>';
			}

			$html .= '</div>';

			return $html;
		}

		/**
		 * The custom range's two date inputs — the only place in the bar where a
		 * filter is COMPOSED rather than picked, which is exactly why date is the
		 * only filter with an Apply button.
		 *
		 * Contract notes, all load-bearing:
		 *
		 * - The names are `ecsa_from` / `ecsa_to`, the exact params
		 *   `Url_State::PARAM_MAP` binds to `date_from` / `date_to`. Criteria reads
		 *   them ONLY when the preset is `custom`, so a stale value under another
		 *   preset is dropped rather than silently narrowing the query.
		 * - `type="date"` gives every modern browser a real calendar, keyboard
		 *   support and locale formatting for free, while submitting the `Y-m-d`
		 *   string `Criteria::clean_date()` accepts. A hand-rolled grid can be
		 *   layered on top later without changing what is submitted.
		 * - They are emitted ENABLED even though the surrounding panel's radios are
		 *   disabled: their names are unique (nothing else submits `ecsa_from`), so
		 *   there is no double-submit to prevent, and keeping them live means a
		 *   shared custom-range URL survives a no-JS re-submit.
		 * - The wrapper is `hidden` unless a custom range is actually active, so the
		 *   calendar stays collapsed until "Custom range" is chosen.
		 *
		 * @since 2.3.0
		 * @param string $instance_id Instance id (namespaces the input ids).
		 * @param string $from        Current `Y-m-d` start, or ''.
		 * @param string $to          Current `Y-m-d` end, or ''.
		 * @param bool   $active      Whether the custom preset is the active one.
		 * @return string
		 */
		private static function date_range_inputs( $instance_id, $from, $to, $active ) {
			$from_id = $instance_id . '__date-from';
			$to_id   = $instance_id . '__date-to';

			$html  = '<div class="ecsa-daterange" data-ecsa-range="date"' . ( $active ? '' : ' hidden' ) . '>';

			$html .= '<span class="ecsa-daterange__field">'
				. '<label class="ecsa-daterange__label" for="' . esc_attr( $from_id ) . '">'
				. esc_html__( 'From', 'events-search-addon-for-the-events-calendar' ) . '</label>'
				. '<input type="date" class="ecsa-daterange__input" id="' . esc_attr( $from_id ) . '"'
				. ' name="ecsa_from" value="' . esc_attr( (string) $from ) . '">'
				. '</span>';

			$html .= '<span class="ecsa-daterange__field">'
				. '<label class="ecsa-daterange__label" for="' . esc_attr( $to_id ) . '">'
				. esc_html__( 'To', 'events-search-addon-for-the-events-calendar' ) . '</label>'
				. '<input type="date" class="ecsa-daterange__input" id="' . esc_attr( $to_id ) . '"'
				. ' name="ecsa_to" value="' . esc_attr( (string) $to ) . '">'
				. '</span>';

			$html .= '</div>';

			return $html;
		}

		/**
		 * The date facet as a `When ▾` trigger + popover of the same radios, with a
		 * native `<select>` no-JS substrate.
		 *
		 * The trigger's LOOK is a wrapper modifier (`ecsa--trg-*`), never a class
		 * here, so this DOM is identical for all four trigger styles and the popover
		 * JS is never forked. `.ecsa-facet--dropdown` is the JS hook.
		 *
		 * @since 2.0.0
		 * @param string $instance_id    Instance id.
		 * @param string $checked_preset Active preset.
		 * @param array  $labels         Preset labels.
		 * @param string $legend         Group label.
		 * @param string $from           Custom-range start (`Y-m-d`), or ''.
		 * @param string $to             Custom-range end (`Y-m-d`), or ''.
		 * @return string
		 */
		private static function date_facet_dropdown( $instance_id, $checked_preset, array $labels, $legend, $from = '', $to = '', $preview = false ) {
			$panel_id    = $instance_id . '__date-panel';
			$value_label = isset( $labels[ $checked_preset ] ) ? $labels[ $checked_preset ] : $checked_preset;
			$is_custom   = ( self::DATE_CUSTOM === $checked_preset );

			// The ACTIVE state is derived in CSS from a NON-EMPTY count element, not
			// from a server-rendered `is-selected` class (a stale one of those was a
			// real bug in this project). So this span carries the count only when the
			// filter actually constrains the query — date is single-select, so that
			// is exactly one value, and `any` is no constraint at all.
			$count = ( 'any' !== $checked_preset ) ? '(1)' : '';

			$html = '<div class="ecsa-facet ecsa-facet--date ecsa-facet--dropdown" data-ecsa-facet="date">';

			// No-JS substrate: a native <select> that submits ecsa_date. JS
			// disables + hides it and drives the radio popover instead, so exactly
			// one control named ecsa_date is ever submittable.
			// In the preview the runtime is not there to hide the substrate, so the
			// server hides it — otherwise the preview shows a raw <select> where the
			// front end shows a styled trigger.
			$html .= '<label class="ecsa-facet__nojs"' . ( $preview ? ' hidden' : '' ) . '>';
			$html .= '<span class="ecsa-facet__legend">' . esc_html( $legend ) . '</span> ';
			$html .= '<select name="ecsa_date" class="ecsa-facet__select">';
			foreach ( self::DATE_CHIPS as $preset ) {
				$html .= '<option value="' . esc_attr( $preset ) . '"' . selected( $preset, $checked_preset, false ) . '>'
					. esc_html( isset( $labels[ $preset ] ) ? $labels[ $preset ] : $preset )
					. '</option>';
			}
			/*
			 * The custom row is a JS affordance — choosing it REVEALS two inputs,
			 * which a bare <select> cannot do — so the no-JS substrate carries it
			 * only when it is already the active preset. Without that, a shared
			 * custom-range URL would render a control reading "Any time" while the
			 * criteria said otherwise, and a no-JS re-submit would silently discard
			 * the range. With it (plus the range inputs, which stay enabled) the
			 * whole state round-trips with JavaScript off.
			 */
			if ( $is_custom ) {
				$html .= '<option value="' . esc_attr( self::DATE_CUSTOM ) . '" selected>'
					. esc_html( $value_label ) . '</option>';
			}
			$html .= '</select></label>';

			// JS-only trigger (hidden until JS reveals it).
			$html .= '<button type="button" class="ecsa-facet-trigger" aria-haspopup="true" aria-expanded="false"'
				. ' aria-controls="' . esc_attr( $panel_id ) . '"' . ( $preview ? '' : ' hidden' ) . '>'
				. '<span class="ecsa-facet-trigger__label">' . esc_html( $legend ) . '</span> '
				. '<span class="ecsa-facet-trigger__value">' . esc_html( $value_label ) . '</span> '
				. '<span class="ecsa-facet-trigger__count">' . esc_html( $count ) . '</span> '
				// A real chevron, not `&#9662;`. The text glyph is font-dependent (missing
				// from some Android/Linux stacks, and a fallback font shifts the baseline),
				// cannot be sized independently of the label's font metrics, and renders
				// blurrily under the rotate-on-open transform. Stroke sits on the <svg>
				// itself so `currentColor` inherits from the trigger.
				. '<span class="ecsa-facet-trigger__caret" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor"'
				. ' stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
				. '<polyline points="6 9.5 12 15.5 18 9.5"/>'
				. '</svg></span>'
				. '</button>';

			// The real radios, in a proper fieldset/legend/radiogroup even inside
			// the popover. Disabled + hidden in the SSR; JS enables them and shows
			// the panel as a portal popover.
			$html .= '<fieldset class="ecsa-facet__panel ecsa-facet--date" id="' . esc_attr( $panel_id ) . '" hidden>';
			$html .= '<legend class="ecsa-facet__legend">' . esc_html( $legend ) . '</legend>';
			$html .= self::date_chips_inner( $checked_preset, $labels, $legend, true, true );
			$html .= self::date_range_inputs( $instance_id, $from, $to, $is_custom );

			/*
			 * Apply — the ONE submit affordance any filter panel is allowed. Every
			 * other filter applies instantly on change because a click IS the whole
			 * intent; a range is only intent once BOTH ends are chosen, so date (and
			 * only date) gets a commit button. It is a real `type="submit"`, so it
			 * commits through the same path as the bar's Search button: the runtime's
			 * form-submit handler intercepts it when there is an in-place target, and
			 * without JavaScript it performs the native GET the SSR renders for.
			 */
			$apply = '<button type="submit" class="ecsa-facet__apply" data-ecsa-apply="date">'
				. esc_html__( 'Apply', 'events-search-addon-for-the-events-calendar' ) . '</button>';

			$html .= self::panel_footer( 'date', ( 'any' !== $checked_preset ) ? 1 : 0, $apply );
			$html .= '</fieldset>';

			$html .= '</div>';

			return $html;
		}

		/*
		 * NOTE: the ID-FACET render surface lived here — eleven methods serving the
		 * `category`, `tag`, `venue` and `organizer` filters:
		 *
		 *   id_facet_map()        the param / criteria-key / legend triple each one
		 *                         submitted under (`ecsa_cat[]`, `ecsa_tag[]`,
		 *                         `ecsa_venue[]`, `ecsa_org[]`).
		 *   id_facet()            the trigger + panel entry point.
		 *   facet_is_single()     which of them were radios rather than checkboxes.
		 *   clamp_selected()      the single-select guard, so `?ecsa_venue=3,7`
		 *                         could not paint two checked radios.
		 *   any_label()           the "Anywhere" / "Anyone" clear-row wording.
		 *   trigger_value_label() the selected-value summary on the trigger.
		 *   id_facet_inner()      the option rows, shared with the expanded column.
		 *   single_row()          one radio row (+ organizer avatar, venue city).
		 *   any_row()             the clear row that submitted an empty value.
		 *   avatar()              the 26px circular initial for an organizer row.
		 *   avatar_initials()     the initials it drew.
		 *   venue_subtitles()     one primed `_VenueCity` lookup for a whole panel.
		 *   panel_wants_search()  whether a long option list earned an in-panel
		 *                         search box, plus panel_search() and
		 *                         panel_search_label().
		 *
		 * None of those facets is part of this plugin, so none of this markup has a
		 * caller. `panel_footer()` below is deliberately NOT among them: the date
		 * facet's panel uses it, and it is the one place the footer contract lives.
		 */

		/**
		 * The shared panel footer: "N selected" on the left, a Reset that clears
		 * just this filter on the right, plus whatever the filter adds (date's
		 * Apply, and only date's).
		 *
		 * The count span is emitted even when empty, for the same reason the
		 * trigger's `__count` is: the runtime keeps it live by writing
		 * `textContent`, and a node that only sometimes exists is a node the runtime
		 * has to create — which is how server and client renders drift apart.
		 *
		 * Reset is `type="button"` so it can never submit the surrounding search
		 * form by accident; it is an enhancement over the always-available "Clear
		 * all" link, not a replacement for it.
		 *
		 * @since 2.3.0
		 * @param string $facet Facet key (the JS hook's value).
		 * @param int    $n     How many values this filter currently constrains by.
		 * @param string $extra Extra footer markup, already escaped.
		 * @return string
		 */
		private static function panel_footer( $facet, $n, $extra = '' ) {
			$n = max( 0, (int) $n );

			$label = $n > 0
				? sprintf(
					/* translators: %d: number of selected filter options. */
					_n( '%d selected', '%d selected', $n, 'events-search-addon-for-the-events-calendar' ),
					$n
				)
				: '';

			return '<div class="ecsa-facet__footer">'
				. '<span class="ecsa-facet__selected" data-ecsa-selected="' . esc_attr( $facet ) . '">'
				. esc_html( $label ) . '</span>'
				. '<button type="button" class="ecsa-facet__reset" data-ecsa-reset="' . esc_attr( $facet ) . '">'
				. esc_html__( 'Reset', 'events-search-addon-for-the-events-calendar' ) . '</button>'
				. $extra
				. '</div>';
		}

		/*
		 * NOTE: `id_facet_dropdown()` lived here — the trigger + portal panel an id
		 * facet rendered as, with its in-panel search, its option rows and its
		 * footer. It went with `id_facet()` and the rest of the id-facet surface.
		 */

		/*
		 * NOTE: the whole LOCATION render surface lived here — five methods.
		 *
		 *   location_facet()   the Tier-1 facet: up to three native <select>
		 *                      controls (Country / State / City) wrapped by
		 *                      facet_group().
		 *   location_field()   the in-bar searchable place input that replaced it,
		 *                      sitting before the keyword field inside the shell.
		 *   location_inner()   the controls themselves, shared by both.
		 *   location_select()  one labelled <select> with an "Any …" first option.
		 *   location_options() the published option lists, read from
		 *                      Query\Facets::location_options() (a bounded distinct
		 *                      scan of _VenueCity / _VenueStateProvince /
		 *                      _VenueCountry) or from an injected preview bag.
		 *
		 * None of it is part of this plugin. There is no `location` facet key, no
		 * `location` criteria, no `ecsa_city`/`ecsa_state`/`ecsa_country` URL
		 * parameters, no `/places` route and no venue-meta scan for any of them to
		 * read — so the controls are deleted rather than left to render an empty
		 * `<div class="ecsa-location">`.
		 */

		/**
		 * One column of the `expanded` placement — the design handoff's expanded
		 * panel, where there are no triggers and no popovers at all and every
		 * filter's options are visible at once.
		 *
		 * This is where the "show all options" layout lives now: it is a PLACEMENT,
		 * not a trigger style. Each column is a labelled group holding the EXACT
		 * same controls the popover panel carries — reusing `date_chips_inner()` —
		 * so the name/value contract, the query and the no-JS submit are
		 * byte-identical to the other placement. The controls are emitted ENABLED
		 * and there is no `<select>` substrate, because nothing here needs
		 * JavaScript: exactly one control per field is submittable either way.
		 *
		 * The column is a `role="group"` labelled by its own visible heading rather
		 * than a `<fieldset>/<legend>`: it gives the same grouping semantics without
		 * the layout quirks a fieldset brings to a CSS grid item.
		 *
		 * @since 2.2.0
		 * @param string $instance_id Instance id (namespaces the heading id).
		 * @param string $facet       Facet key.
		 * @param array  $criteria    Normalized criteria.
		 * @return string '' when this facet has nothing to show.
		 */
		private static function expanded_column( $instance_id, $facet, array $criteria ) {
			$facet = is_scalar( $facet ) ? sanitize_key( (string) $facet ) : '';

			/*
			 * `date` is the only facet with a column, and the test is an equality
			 * rather than a lookup on purpose: an unrecognised key returns '' here
			 * exactly as it renders nothing in `bar()`, so the two agree and a
			 * tampered `facets` value cannot produce a column either.
			 *
			 * NOTE: a `location` arm and an `id_facet_map()`-driven catch-all ran
			 * here, drawing the location selects and the category / tag / venue /
			 * organizer option lists from the same inner helpers their popover
			 * panels used. Both went with those facets.
			 */
			if ( 'date' !== $facet ) {
				return '';
			}

			$labels  = self::date_labels();
			$active  = isset( $criteria['date_preset'] ) ? $criteria['date_preset'] : 'any';
			/*
			 * `custom` COUNTS HERE TOO. This tested `DATE_CHIPS`, which deliberately
			 * excludes `custom`, so a saved custom range degraded to "Any time" the
			 * moment the filters were placed in the expanded row — the visitor's own
			 * dates vanished from the placement meant to show everything.
			 */
			$known   = array_merge( self::DATE_CHIPS, array( self::DATE_CUSTOM ) );
			$checked = in_array( $active, $known, true ) ? $active : 'any';
			$legend  = __( 'When', 'events-search-addon-for-the-events-calendar' );

			/*
			 * THE RANGE, AND AN APPLY TO COMMIT IT.
			 *
			 * This column COMMITS ON CLICK — it is the no-JS placement, so choosing a
			 * preset submits there and then. A range cannot work that way: it is two
			 * inputs that mean nothing until both are filled, and auto-committing on
			 * selection would submit an empty range. That is why the custom row was
			 * excluded here, and the exclusion was right as far as it went.
			 *
			 * So the column gets its own Apply, revealed with the range. One rule
			 * still governs the column — everything commits on click — and the single
			 * exception carries a visible reason for being one.
			 *
			 * The range is emitted UNHIDDEN and revealed by CSS. `FacetPanel`, which
			 * drives the popover's range, is scoped to `.ecsa-facet__panel` and never
			 * runs for this column; and since this is the placement that exists for
			 * visitors without JS, a JS-driven reveal would be precisely the wrong
			 * dependency. `:has()` does it, behind `@supports`, so an engine that
			 * cannot evaluate it shows the range always rather than never.
			 */
			$is_custom = ( self::DATE_CUSTOM === $checked );
			$from      = ( $is_custom && isset( $criteria['date_from'] ) ) ? (string) $criteria['date_from'] : '';
			$to        = ( $is_custom && isset( $criteria['date_to'] ) ) ? (string) $criteria['date_to'] : '';

			$apply = '<button type="submit" class="ecsa-facet__apply ecsa-expanded-apply" data-ecsa-apply="date">'
				. esc_html__( 'Apply', 'events-search-addon-for-the-events-calendar' ) . '</button>';

			$inner = self::date_chips_inner( $checked, $labels, $legend, false, true )
				. '<div class="ecsa-expanded-range" data-ecsa-expanded-range>'
					. self::date_range_inputs( $instance_id, $from, $to, true )
					. $apply
				. '</div>';

			if ( '' === $inner ) {
				return '';
			}

			$head_id = $instance_id . '__' . $facet . '-head';

			return '<div class="ecsa-filters-expanded__col" role="group" aria-labelledby="' . esc_attr( $head_id ) . '">'
				. '<span class="ecsa-filters-expanded__head" id="' . esc_attr( $head_id ) . '">' . esc_html( $legend ) . '</span>'
				. $inner
				. '</div>';
		}

		/*
		 * NOTE: `facet_group()` lived here — a plain `<fieldset>`/`<legend>` wrapper
		 * for a facet's inner markup. Its last caller was `location_facet()`, the
		 * three-select control, so it is gone with it. Every filter this plugin
		 * renders is a trigger + panel (`date_facet()`, which builds its own
		 * fieldset) or an expanded column (`expanded_column()`, which uses a
		 * `role="group"` because a fieldset misbehaves as a grid item).
		 */

		/*
		 * NOTE: a `group_has_active()` helper lived here. Its only caller was the
		 * `<details … open>` branch of facet_group() — it decided whether a
		 * collapsed group should open on first paint because it already carried an
		 * active selection. With `groups_collapsed` retired there is no collapsed
		 * group left to open, so the helper is unreachable and gone. The
		 * active-filter arithmetic that IS still needed lives in
		 * active_filter_count() directly below.
		 */

		/**
		 * Count the active filters, for the `Filters (N)` toggle badge.
		 *
		 * One for a non-`any` date preset. The keyword is deliberately excluded (it
		 * lives in the always-visible search input, not the collapsed facets). Kept
		 * byte-parity with the JS `activeFilterCount()` so the SSR badge and the
		 * hydrated badge agree.
		 *
		 * NOTE: selected category / tag / venue / organizer ids each counted one,
		 * and each present location sub-key counted one. Neither facet is part of
		 * this plugin, so the date preset is the whole sum — but the badge STAYS,
		 * because `bar_inline`'s collapsed state still has to say whether anything
		 * is filtering behind it.
		 *
		 * @since 2.0.0
		 * @param array $criteria Normalized criteria.
		 * @return int
		 */
		private static function active_filter_count( array $criteria ) {
			$n = 0;

			if ( isset( $criteria['date_preset'] ) && 'any' !== $criteria['date_preset'] ) {
				++$n;
			}

			return $n;
		}

		/**
		 * Render the results region.
		 *
		 * @since 2.0.0
		 * @param string $instance_id Instance id.
		 * @param array  $config      Config.
		 * @param array  $criteria    Normalized criteria.
		 * @param array  $result      Query_Engine result.
		 * @return string
		 */
		public static function results( $instance_id, array $config, array $criteria, array $result ) {
			$region_id = '' !== $config['target'] ? $config['target'] : $instance_id . '__results';
			$items     = isset( $result['items'] ) ? $result['items'] : array();
			$total     = isset( $result['total'] ) ? $result['total'] : null;
			$has_more  = ! empty( $result['has_more'] );
			$view      = in_array( $config['view'], array( 'grid', 'list' ), true ) ? $config['view'] : 'grid';
			$per_page  = (int) $config['per_page'];
			$page      = (int) $criteria['page'];
			// results() is public; re-resolve the card field set rather than trust
			// a caller that never met normalize_config().
			$card_fields = self::card_fields( $config );
			$date_format = self::clean_date_format( isset( $config['date_format'] ) ? $config['date_format'] : 'site' );
			$template    = self::clean_template( isset( $config['template'] ) ? $config['template'] : 'clean' );
			$preview     = ! empty( $config['preview_mode'] );

			$html  = '<div class="ecsa-results" id="' . esc_attr( $region_id ) . '"'
				. ' data-ecsa-results="1" data-ecsa-instance="' . esc_attr( $instance_id ) . '"'
				// Published for the runtime: a live update rebuilds the cards in
				// JavaScript, and without this it would re-format every date in the
				// browser's own idea of a date and silently undo the setting.
				. ' data-ecsa-date-format="' . esc_attr( $date_format ) . '"'
				// The two RESULTS-SHAPE keys this region publishes:
				//
				//   data-ecsa-view      which layout is on screen RIGHT NOW. The
				//                       visitor toggle swaps this (and the list's
				//                       modifier class) and nothing else — grid and
				//                       list are the same cards under different
				//                       layout tokens, so switching must never
				//                       cost a request. The runtime reads it.
				//   data-ecsa-template  which card template is on screen. ONE value
				//                       today, so nothing reads it: the runtime's
				//                       `template()` accessor was deleted with the
				//                       retired template, and a card rebuilt in
				//                       JavaScript is built the one way. Still
				//                       PUBLISHED — it is a shape contract this
				//                       region's markup states, for a theme or an
				//                       integration that has to know which card DOM
				//                       it is looking at.
				. ' data-ecsa-view="' . esc_attr( $view ) . '"'
				. ' data-ecsa-template="' . esc_attr( $template ) . '"'
				. ' role="region" aria-label="' . esc_attr__( 'Search results', 'events-search-addon-for-the-events-calendar' ) . '" aria-busy="false">';

			$html .= '<div class="ecsa-results__bar">';
			// The NUMBERS carry the information, so they are emitted as <strong>
			// at the text colour while the connective words stay quiet. That is
			// what keeps the count legible on the tray without asking the reader
			// to parse a wall of one colour.
			$html .= '<p class="ecsa-results__count" role="status" aria-live="polite">'
				. self::count_html( $items, $total, $page, $per_page ) . '</p>';
			// The handoff's RIGHT CLUSTER: view toggle, then the "Sort" label and
			// its trigger, at a single 11px gap. One wrapper so the cluster stays a
			// cluster when the toolbar wraps on a narrow screen — with the two as
			// bare siblings of the count, `space-between` scattered them.
			$html .= '<div class="ecsa-results__tools">';
			$html .= self::view_toggle( $criteria, $view, $preview );
			$html .= self::sort_control( $instance_id, $criteria, $preview );
			$html .= '</div>';
			$html .= '</div>';

			$html .= self::active_filters( $criteria );

			// The cards, or the appropriate empty/error state.
			if ( ! empty( $items ) ) {
				$html .= '<ul class="ecsa-cards ecsa-cards--' . esc_attr( $view ) . '">';
				foreach ( $items as $item ) {
					$html .= self::card( $item, $card_fields, $date_format, $template );
				}
				$html .= '</ul>';

				if ( $has_more ) {
					$next = Url_State::to_url( array_merge( $criteria, array( 'page' => $page + 1 ) ), self::current_url_base() );
					$html .= '<a class="ecsa-load-more" href="' . esc_url( $next ) . '" rel="next">'
						. esc_html__( 'Load more events', 'events-search-addon-for-the-events-calendar' ) . '</a>';
				}
			}

			$html .= self::state_blocks( $criteria, $items );

			$html .= '</div>';

			return $html;
		}

		/**
		 * The visitor-facing grid/list toggle (handoff "Results toolbar", item 1).
		 *
		 * WHY THE ACTIVE OPTION IS NOT A BUTTON. It is a STATE, not an action:
		 * pressing "Grid view" while already in grid does nothing, and offering it
		 * as a button invites exactly that dead press. So the active option renders
		 * as a `<span role="button" aria-disabled="true" aria-pressed="true">` —
		 * still a named, pressed member of the group for assistive technology, but
		 * not an action, and not in the tab order. `tabindex="-1"` keeps it
		 * PROGRAMMATICALLY focusable so the runtime can move focus onto it after a
		 * switch instead of dropping focus to `<body>`.
		 *
		 * WHY IT IS A GET FORM. Same progressive-enhancement contract the sort
		 * control keeps: with JavaScript off, the inactive option is a real submit
		 * that re-requests the page with `ecsa_view` set, and the server honours it
		 * (see `instance()`). With JavaScript on, the runtime intercepts the click
		 * and does a pure token swap — grid and list are the SAME cards under
		 * different layout tokens, so a switch must never issue a request.
		 *
		 * The hidden inputs reproduce every other criterion EXCEPT `ecsa_view`
		 * (the button supplies that). `ecsa_page` deliberately rides along: changing
		 * layout is not a new query, so it must not teleport the visitor to page 1.
		 *
		 * @since 2.4.0
		 * @param array  $criteria Normalized criteria.
		 * @param string $view     The layout currently on screen.
		 * @param bool   $preview  Render inert (the settings preview, which lives
		 *                         inside another form and runs no runtime).
		 * @return string
		 */
		private static function view_toggle( array $criteria, $view, $preview = false ) {
			$labels = array(
				'grid' => __( 'Grid view', 'events-search-addon-for-the-events-calendar' ),
				'list' => __( 'List view', 'events-search-addon-for-the-events-calendar' ),
			);

			$group = __( 'Results view', 'events-search-addon-for-the-events-calendar' );
			$view  = isset( $labels[ $view ] ) ? $view : 'grid';

			// See sort_control(): a preview renders inside the settings screen's own
			// form, and the HTML parser drops a nested <form>.
			$html = $preview
				? '<div class="ecsa-view-toggle" role="group" aria-label="' . esc_attr( $group ) . '">'
				: '<form class="ecsa-view-toggle" method="get" action="' . esc_url( self::current_url_base() ) . '"'
					. ' role="group" aria-label="' . esc_attr( $group ) . '" data-ecsa-view-submit="1">';

			if ( ! $preview ) {
				$html .= self::hidden_criteria_inputs( $criteria, array( 'ecsa_view' ) );
			}

			foreach ( array( 'grid', 'list' ) as $key ) {
				if ( $key === $view ) {
					$html .= '<span class="ecsa-view-toggle__option is-active" role="button"'
						. ' aria-disabled="true" aria-pressed="true" tabindex="-1"'
						. ' data-ecsa-view="' . esc_attr( $key ) . '"'
						. ' aria-label="' . esc_attr( $labels[ $key ] ) . '">'
						. self::view_icon( $key ) . '</span>';
					continue;
				}

				$html .= '<button class="ecsa-view-toggle__option"'
					. ( $preview
						? ' type="button"'
						: ' type="submit" name="ecsa_view" value="' . esc_attr( $key ) . '"' )
					. ' aria-pressed="false" data-ecsa-view="' . esc_attr( $key ) . '"'
					. ' aria-label="' . esc_attr( $labels[ $key ] ) . '">'
					. self::view_icon( $key ) . '</button>';
			}

			$html .= $preview ? '</div>' : '</form>';

			return $html;
		}

		/**
		 * The 15px grid / list glyph, from the handoff's own sprite geometry.
		 *
		 * Inlined rather than referenced through `<use>`: the handoff warns that a
		 * stylesheet rule targeting a symbol's internals does not reliably reach
		 * `<use>` shadow content, so `fill`/`stroke` are set on the element itself.
		 * Every string is a hardcoded literal — nothing dynamic is interpolated.
		 *
		 * @since 2.4.0
		 * @param string $key `grid` or `list`.
		 * @return string
		 */
		private static function view_icon( $key ) {
			$open = '<svg class="ecsa-view-toggle__icon" viewBox="0 0 24 24" width="15" height="15"'
				. ' fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
				. ' stroke-linejoin="round" focusable="false" aria-hidden="true">';

			if ( 'list' === $key ) {
				return $open
					. '<rect x="3.5" y="4.5" width="17" height="5.5" rx="1.6"/>'
					. '<rect x="3.5" y="14" width="17" height="5.5" rx="1.6"/></svg>';
			}

			return $open
				. '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/>'
				. '<rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/>'
				. '<rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/>'
				. '<rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/></svg>';
		}

		/**
		 * Render one event card, emitting only the admin-selected pieces.
		 *
		 * `card_fields` is the whole card vocabulary — image, title, date, venue,
		 * cost — and governs both the grid and the list layout; the old always-on
		 * excerpt is gone. A selected piece with no value still renders nothing (an
		 * event with no venue must not leave an empty line), and if that leaves the
		 * card with no content at all it falls back to the title, so a card is never
		 * a blank clickable box and the link always has an accessible name.
		 *
		 * All fields are plain text / a trusted permalink. event_id is emitted
		 * for the JS; occurrence_id is informational only and never accepted as
		 * input.
		 *
		 * Each meta line opens with a decorative glyph — see `card_icon()`, which
		 * owns the whole contract (aria-hidden, no tab stop, `currentColor`, and
		 * the calendar/clock choice that follows what the line actually says).
		 * The runtime paints the identical span in `buildCard()`, so a card
		 * rebuilt by a live update keeps its icons.
		 *
		 * NOTE: a second template drew a DATE PILL on the image and dropped the
		 * text line to the time alone. It is not part of this plugin, so the date
		 * always lives on the text line and `$template` has exactly one value —
		 * the parameter is kept because `card()` is called with it from two places
		 * and the axis is still stored, carried and allowlisted.
		 *
		 * @since 2.0.0
		 * @param array    $item        Payload item from Query_Engine.
		 * @param string[] $card_fields Allowlisted, non-empty card field set.
		 * @param string   $date_format `date_format` key.
		 * @param string   $template    `clean`.
		 * @return string
		 */
		private static function card( array $item, array $card_fields, $date_format = 'site', $template = 'clean' ) {
			unset( $template );

			$title   = isset( $item['title'] ) ? (string) $item['title'] : '';
			$url     = isset( $item['url'] ) ? (string) $item['url'] : '';
			$start   = isset( $item['start'] ) ? (string) $item['start'] : '';
			$end     = isset( $item['end'] ) ? (string) $item['end'] : '';
			$all_day = ! empty( $item['all_day'] );
			$venue   = isset( $item['venue'] ) ? (string) $item['venue'] : '';
			$thumb   = isset( $item['thumbnail'] ) ? (string) $item['thumbnail'] : '';
			$eid     = isset( $item['event_id'] ) ? (int) $item['event_id'] : 0;

			$has_thumb  = in_array( 'image', $card_fields, true ) && '' !== $thumb;
			$wants_date = in_array( 'date', $card_fields, true ) && '' !== $start;

			// The thumb is a direct child of the link; everything textual lives in
			// a `__body` wrapper. That separation is what lets the LIST view lay
			// the card out as [thumb | body] with the body's own lines stacked —
			// with a flat child list, `flex-direction:row` put the title, date,
			// venue and cost side by side on one line, which read as a jumble.
			$body = '';

			if ( in_array( 'title', $card_fields, true ) && '' !== $title ) {
				$body .= '<h3 class="ecsa-card__title">' . esc_html( $title ) . '</h3>';
			}
			if ( $wants_date ) {
				$line = self::format_date( $start, $date_format, $end, $all_day );

				if ( '' !== $line ) {
					// THE GLYPH FOLLOWS THE LINE. This line opens with a date, so
					// it takes the calendar. (It took the CLOCK under the retired
					// pill template, where the line was a bare time and a calendar
					// would have named something that was not on it.)
					$body .= '<p class="ecsa-card__date">'
						. self::card_icon( 'date' )
						. esc_html( $line ) . '</p>';
				}
			}
			if ( in_array( 'venue', $card_fields, true ) && '' !== $venue ) {
				$body .= '<p class="ecsa-card__venue">' . self::card_icon( 'venue' ) . esc_html( $venue ) . '</p>';
			}
			if ( in_array( 'cost', $card_fields, true ) ) {
				// Prefer the cost the payload already carries — that is the value
				// the JS paints after a live update, so honouring it here keeps
				// the server render, the settings preview and the live-updated
				// card identical. Fall back to a TEC lookup for callers that
				// hand us an item without one.
				$cost = isset( $item['cost'] ) && is_scalar( $item['cost'] ) ? trim( (string) $item['cost'] ) : '';

				if ( '' === $cost ) {
					$cost = self::event_cost( $eid );
				}

				if ( '' !== $cost ) {
					$body .= '<p class="ecsa-card__cost">' . self::card_icon( 'cost' ) . esc_html( $cost ) . '</p>';
				}
			}

			// Never render an empty clickable box: if every selected field turned
			// out blank, fall back to the title (or a generic label).
			if ( '' === $body && ! $has_thumb ) {
				$fallback = ( '' !== $title ) ? $title : __( 'View event', 'events-search-addon-for-the-events-calendar' );
				$body     = '<h3 class="ecsa-card__title">' . esc_html( $fallback ) . '</h3>';
			}

			$inner = '';
			if ( $has_thumb ) {
				$inner .= '<img class="ecsa-card__thumb" src="' . esc_url( $thumb ) . '" alt="" loading="lazy">';
			}
			if ( '' !== $body ) {
				$inner .= '<div class="ecsa-card__body">' . $body . '</div>';
			}

			$html  = '<li class="ecsa-card" data-ecsa-event="' . esc_attr( (string) $eid ) . '">';
			$html .= '<a class="ecsa-card__link" href="' . esc_url( $url ) . '">';
			$html .= $inner;
			$html .= '</a></li>';

			return $html;
		}

		/**
		 * The leading glyph for one card meta line.
		 *
		 * DECORATIVE, AND THAT IS A CONTRACT. The line's own text already says
		 * the date, the time, the venue and the price, so the glyph is wrapped in
		 * an `aria-hidden` span and the `<svg>` carries `focusable="false"`:
		 * nothing here is announced (it would double every meta line) and nothing
		 * here becomes a second tab stop inside the card's link.
		 *
		 * THE COLOUR COMES FROM THE LINE. `stroke="currentColor"` and no colour
		 * of its own is the whole mechanism — the venue glyph stays muted grey,
		 * the cost glyph stays accent, the date glyph stays text — so the four
		 * icons cost ONE rule rather than one rule per line, and a glyph can
		 * never disagree with the words beside it. Same reasoning as the facet
		 * caret and the "near me" crosshair.
		 *
		 * `time` IS STILL IN THE MAP AND HAS NO PHP CALLER. It is not dead: the
		 * front-end runtime's `buildCard()` paints the identical span, the
		 * two glyph maps are compared key for key, and the
		 * two must not diverge. The rule that decides between them is unchanged —
		 * the glyph follows what the LINE says, never what the template is called —
		 * and every card this plugin renders puts a date on that line.
		 *
		 * NOT A CURRENCY SYMBOL FOR COST. TEC formats the price, so the value can
		 * be "₹500", "$25" or "Free"; a hardcoded ₹ or $ would contradict two of
		 * those three. A price TAG is true for all of them.
		 *
		 * Sized in `em` by the stylesheet against the line's own font-size, so
		 * one declaration tracks `--ecsa-card-scale` and no glyph needs a
		 * per-size rule; the `width`/`height` attributes are only the no-CSS
		 * fallback, exactly as the facet caret documents. Every string below is a
		 * hardcoded literal — nothing dynamic is interpolated.
		 *
		 * @since 2.5.0
		 * @param string $key `date` | `time` | `venue` | `cost`.
		 * @return string Markup, or '' for an unknown key.
		 */
		private static function card_icon( $key ) {
			$glyphs = array(
				// Calendar: page, header rule, two bindings.
				'date'  => '<rect x="3" y="4.5" width="18" height="17" rx="2.5"/>'
					. '<path d="M3 10h18"/><path d="M8 2.5v4"/><path d="M16 2.5v4"/>',
				// Clock: dial, hour hand, minute hand.
				'time'  => '<circle cx="12" cy="12" r="9"/><polyline points="12 6.5 12 12.25 16 14.25"/>',
				// Map pin: teardrop + hole.
				'venue' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0Z"/>'
					. '<circle cx="12" cy="10" r="3"/>',
				// Price tag: body + punch hole (a hair of a stroke with a round
				// cap, which reads cleaner at 13px than a stroked circle).
				'cost'  => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1 -2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/>'
					. '<path d="M7.5 7.5h.01"/>',
			);

			if ( ! isset( $glyphs[ $key ] ) ) {
				return '';
			}

			return '<span class="ecsa-card__icon" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"'
				. ' stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
				. $glyphs[ $key ]
				. '</svg></span>';
		}

		/**
		 * The event's cost, as TEC formats it (with the site's currency symbol).
		 *
		 * Hard-gated: The Events Calendar owns cost formatting (symbol position,
		 * ranges, the "Free" string), so we never re-derive it from `_EventCost`
		 * meta, and we render nothing at all when TEC is absent or the event has no
		 * cost — a stray empty price line is worse than no price line.
		 *
		 * @since 2.1.0
		 * @param int $event_id Event post id.
		 * @return string Plain-text cost, or '' when there is none.
		 */
		private static function event_cost( $event_id ) {
			$event_id = (int) $event_id;

			if ( $event_id <= 0 || ! function_exists( 'tribe_get_cost' ) ) {
				return '';
			}

			$cost = tribe_get_cost( $event_id, true );

			if ( ! is_scalar( $cost ) ) {
				return '';
			}

			// TEC may wrap the symbol in markup; the card renders plain text, so
			// flatten it here and escape at the point of echo.
			return trim( wp_strip_all_tags( (string) $cost ) );
		}

		/**
		 * The allowlisted card field set, re-cleaned so `results()` (public) can
		 * trust it. An empty or all-invalid selection means "show everything", so a
		 * card is never blank.
		 *
		 * @since 2.1.0
		 * @param array $config Instance config.
		 * @return string[] Non-empty subset of image/title/date/venue/cost.
		 */
		private static function card_fields( array $config ) {
			$allowed = array( 'image', 'title', 'date', 'venue', 'cost' );
			$raw     = isset( $config['card_fields'] ) && is_array( $config['card_fields'] ) ? $config['card_fields'] : array();
			$out     = array();

			foreach ( $raw as $field ) {
				$field = is_scalar( $field ) ? sanitize_key( (string) $field ) : '';

				if ( in_array( $field, $allowed, true ) && ! in_array( $field, $out, true ) ) {
					$out[] = $field;
				}
			}

			return $out ? $out : $allowed;
		}

		/**
		 * Allowlist a `date_format` key, defaulting to the site's own settings.
		 *
		 * @since 2.2.0
		 * @param mixed $value Raw key.
		 * @return string
		 */
		private static function clean_date_format( $value ) {
			$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

			return array_key_exists( $value, self::date_formats() ) ? $value : 'site';
		}

		/**
		 * Allowlist the card `template` key.
		 *
		 * @since 2.4.0
		 * @param mixed $value Raw key.
		 * @return string 'clean'
		 */
		private static function clean_template( $value ) {
			$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

			return in_array( $value, array( 'clean' ), true ) ? $value : 'clean';
		}

		/*
		 * NOTE: `date_pill()` and `pill_parts()` lived here — the solid accent
		 * plate the retired second template drew on a card's image, with its
		 * derived on-accent ink, its load-bearing 1px rim, and the
		 * visually-hidden full date that kept it READ rather than merely looked at.
		 * That template is not part of this plugin, so nothing calls them.
		 *
		 * `format_date_only()` and `format_time()` went with them — see the note
		 * where they lived, beside `format_date()`.
		 */

		/**
		 * Render every §1.7 state block. Exactly the applicable one is visible.
		 *
		 * @since 2.0.0
		 * @param array $criteria Normalized criteria.
		 * @param array $items    Items rendered (empty triggers a visible state).
		 * @return string
		 */
		private static function state_blocks( array $criteria, array $items ) {
			$has_items = ! empty( $items );
			$filtered  = Criteria::is_filtered( $criteria );
			$keyword   = '' !== $criteria['q'];
			// The resolved window, named once beside the link it gates.
			$time_now  = isset( $criteria['time'] ) ? (string) $criteria['time'] : 'upcoming';

			// Which empty state is the live one on this first paint.
			$show_no_query    = ( ! $has_items && $keyword );
			$show_no_filters  = ( ! $has_items && $filtered && ! $keyword );
			$show_no_upcoming = ( ! $has_items && ! $filtered && 'upcoming' === $criteria['time'] );
			$show_empty       = ( ! $has_items && ! $filtered && 'upcoming' !== $criteria['time'] );

			$hide = function ( $active ) {
				return $active ? '' : ' hidden';
			};

			$html = '';

			$q = esc_html( $criteria['q'] );

			// Real, no-JS-safe recovery links. "Clear" points at the unfiltered
			// base page (indexable — no SEO downside); "Search past" flips the
			// time window. JS intercepts both; without JS they still navigate.
			$clear_url = esc_url( self::current_url_base() );
			/*
			 * `all`, not `past`. The link says past events TOO, and `past`
			 * swaps the window rather than widening it — a visitor who asked
			 * to see more lost every upcoming match. `all` is the value the
			 * sentence already promised, and the chip added above is what
			 * makes the widening visible and reversible.
			 */
			/*
			 * `sort` TRAVELS WITH THE WIDENING, and it has to.
			 *
			 * `all` is a genuine no-op window, but sort is an independent axis
			 * defaulting to soonest-first. On a calendar with years of history that
			 * meant this link returned the OLDEST matches on page one — events from
			 * a decade ago — with anything attendable pages away, under a chip
			 * reading "Past and upcoming". The visitor asked to see more and got the
			 * least useful end of it.
			 *
			 * Newest-first is what "past events too" means in practice. Nothing is
			 * hidden by doing it here: the sort control shows the new value and the
			 * time chip shows the widened window, so both halves are visible and
			 * both are reversible.
			 */
			$past_href = esc_url(
				Url_State::to_url(
					array_merge( $criteria, array( 'time' => 'all', 'sort' => 'date_desc' ) ),
					self::current_url_base()
				)
			);

			// No results for a keyword.
			$html .= '<div class="ecsa-state ecsa-state--no-query"' . $hide( $show_no_query ) . ' role="status">'
				. '<p>' . sprintf(
					/* translators: %s: the search keyword. */
					esc_html__( 'No events match “%s”.', 'events-search-addon-for-the-events-calendar' ),
					$q
				) . '</p>'
				. '<a class="ecsa-clear-all" href="' . $clear_url . '">' . esc_html__( 'Clear search', 'events-search-addon-for-the-events-calendar' ) . '</a> '
				/*
				 * OFFERED ONLY WHILE IT WOULD DO SOMETHING. Once the window is
				 * already widened this link points at the state the visitor is
				 * standing in, so clicking it re-renders the same empty result —
				 * and it is the ONE visible recovery affordance in this state, so a
				 * visitor who tries it and sees nothing change has been told the
				 * search is broken. The time chip is what offers the way back now.
				 *
				 * EMITTED ALWAYS, HIDDEN WHEN IT DOES NOT APPLY — like every other
				 * block here, and unlike the omit-or-emit this used to be. The
				 * runtime re-shows this block on the next empty search without
				 * ever revisiting its CONTENTS (`toggleStates()` flips `[hidden]`
				 * on blocks and nothing else), so a link that was omitted at paint
				 * can never come back, and a link that was present can never leave.
				 * Present-and-hidden gives the client something to toggle.
				 *
				 * It stays a real `<a href>`, so the no-JS path is byte-identical
				 * to before at every `time`: shown at `upcoming`, inert-and-hidden
				 * otherwise. The one new thing on the page is a hidden href on a
				 * widened view, pointing near enough at itself.
				 */
				. '<a class="ecsa-search-past" href="' . $past_href . '"' . $hide( 'upcoming' === $time_now ) . '>'
					. esc_html__( 'Search past events too', 'events-search-addon-for-the-events-calendar' ) . '</a>'
				. '</div>';

			/*
			 * No results after filters.
			 *
			 * NOTE: this state used to NAME the filter most likely responsible —
			 * `narrowest_filter()` ranked the active id facets by the events each
			 * one's removal would recover, and `clear_facet_link()` offered a
			 * one-click "Clear the Venue filter". Those facets are not part of this
			 * plugin, so there is nothing to rank: the date range is the only
			 * filter that can produce this state, and it is already named in the
			 * body copy. The generic, honest line is what remains.
			 */
			$html .= '<div class="ecsa-state ecsa-state--no-filters"' . $hide( $show_no_filters ) . ' role="status">'
				. self::empty_state_icon()
				. '<h3 class="ecsa-state__title">' . esc_html__( 'No events match those filters', 'events-search-addon-for-the-events-calendar' ) . '</h3>'
				. self::no_filters_body( $criteria )
				. '<a class="ecsa-clear-all" href="' . $clear_url . '">' . esc_html__( 'Clear all filters', 'events-search-addon-for-the-events-calendar' ) . '</a>'
				. '</div>';

			// No upcoming events site-wide (the most likely real case).
			/*
			 * `sort` travels with the window here too. `past` maps to
			 * `ends_before now` while sort keeps its soonest-first default, so
			 * without this the browse link opened on the OLDEST event the site has
			 * ever held — a decade back on a mature calendar — and buried anything
			 * recent pages away. Newest-first is what "past events" means to
			 * someone who just failed to find something.
			 */
			$past_url = esc_url(
				Url_State::to_url(
					array_merge( $criteria, array( 'time' => 'past', 'sort' => 'date_desc' ) ),
					self::current_url_base()
				)
			);
			$html    .= '<div class="ecsa-state ecsa-state--no-upcoming"' . $hide( $show_no_upcoming ) . ' role="status">'
				. '<p>' . esc_html__( 'There are no upcoming events.', 'events-search-addon-for-the-events-calendar' ) . '</p>'
				. '<a class="ecsa-past-link" href="' . $past_url . '">' . esc_html__( 'Browse past events', 'events-search-addon-for-the-events-calendar' ) . '</a>'
				. '</div>';

			// No events at all — editor-only guidance.
			$create = '';
			if ( current_user_can( 'edit_posts' ) && post_type_exists( 'tribe_events' ) ) {
				$create = '<a class="ecsa-create" href="' . esc_url( admin_url( 'post-new.php?post_type=tribe_events' ) ) . '">'
					. esc_html__( 'Create your first event', 'events-search-addon-for-the-events-calendar' ) . '</a>';
			}
			$html .= '<div class="ecsa-state ecsa-state--empty"' . $hide( $show_empty ) . ' role="status">'
				. '<p>' . esc_html__( 'No events found.', 'events-search-addon-for-the-events-calendar' ) . '</p>'
				. $create
				. '</div>';

			// Error state — hidden on first paint; JS reveals it and KEEPS the
			// SSR results on screen underneath.
			$html .= '<div class="ecsa-state ecsa-state--error" hidden role="alert">'
				. '<p>' . esc_html__( 'We couldn’t load events. Please try again.', 'events-search-addon-for-the-events-calendar' ) . '</p>'
				. '<button type="button" class="ecsa-retry">' . esc_html__( 'Try again', 'events-search-addon-for-the-events-calendar' ) . '</button>'
				. '</div>';

			return $html;
		}

		/*
		 * NOTE: `facet_legend()`, `narrowest_filter()` and `clear_facet_link()`
		 * lived here — the machinery that NAMED the filter most likely responsible
		 * for an empty result. `narrowest_filter()` ranked the active id facets by
		 * each group's own option-count total (which `Facets` computed against the
		 * set filtered by every OTHER facet, making it a real measurement of what
		 * clearing that one would recover), and `clear_facet_link()` turned the
		 * winner into a one-click no-JS link. All three read `id_facet_map()`, and
		 * all three went with the facets they ranked.
		 */

		/**
		 * The body copy for the "no events match those filters" state.
		 *
		 * NOTE: this took a `$culprit` facet key and, when one could be named,
		 * offered to clear it by name. Nothing can be named now — the date range is
		 * the only filter that reaches this state — so the copy is the honest
		 * generic line, which is what the old code fell back to whenever the
		 * measurement declined to pick.
		 *
		 * @since 2.2.0
		 * @param array $criteria Normalized criteria.
		 * @return string
		 */
		private static function no_filters_body( array $criteria ) {
			$has_date = isset( $criteria['date_preset'] ) && 'any' !== $criteria['date_preset'];

			$text = $has_date
				? __( 'Try widening the date range, or removing one of the filters.', 'events-search-addon-for-the-events-calendar' )
				: __( 'Try removing one of the filters.', 'events-search-addon-for-the-events-calendar' );

			return '<p class="ecsa-state__body">' . esc_html( $text ) . '</p>';
		}

		/**
		 * The handoff's empty-state glyph: a 64px circle holding a 28px magnifier.
		 *
		 * The stroke/fill are set on the `<svg>` itself, not on its children, so
		 * `currentColor` resolves from the circle's own colour.
		 *
		 * @since 2.2.0
		 * @return string
		 */
		private static function empty_state_icon() {
			return '<span class="ecsa-state__icon" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor"'
				. ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
				. '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>'
				. '</svg></span>';
		}

		/**
		 * Active-filter chips (removable) plus Clear all — only when filtered.
		 *
		 * @since 2.0.0
		 * @param array $criteria Normalized criteria.
		 * @return string
		 */
		private static function active_filters( array $criteria ) {
			$chips = array();

			/*
			 * NOTE: one chip per selected category / tag / venue / organizer id was
			 * built here (resolving the id to its label through the facet option
			 * bag, falling back to `#<id>`), and one per present location sub-key.
			 * Neither facet is part of this plugin, so the date preset is the only
			 * thing that can produce a chip — which is also why this method no
			 * longer needs the facet bag at all.
			 */
			if ( 'any' !== $criteria['date_preset'] ) {
				$labels = self::date_labels();
				$chips[] = array(
					'token' => 'date:' . $criteria['date_preset'],
					'label' => isset( $labels[ $criteria['date_preset'] ] ) ? $labels[ $criteria['date_preset'] ] : $criteria['date_preset'],
				);
			}

			/*
			 * THE TIME WINDOW, which had no chip and therefore no way back.
			 *
			 * The empty state offers to widen the search past `upcoming`, and
			 * that choice then rides every later search in the URL. Without a
			 * chip nothing on the page said so and nothing could undo it — the
			 * visitor had to edit the address bar. `upcoming` is the default, so
			 * only a deliberate widening earns a chip.
			 */
			if ( ! empty( $criteria['time'] ) && 'upcoming' !== $criteria['time'] ) {
				$windows = self::time_labels();
				$chips[] = array(
					'token' => 'time:' . $criteria['time'],
					'label' => isset( $windows[ $criteria['time'] ] ) ? $windows[ $criteria['time'] ] : $criteria['time'],
				);
			}

			if ( empty( $chips ) ) {
				return '';
			}

			$html = '<div class="ecsa-active-filters">';
			foreach ( $chips as $chip ) {
				$html .= '<button type="button" class="ecsa-active-filter" data-ecsa-remove="' . esc_attr( $chip['token'] ) . '"'
					. ' aria-label="' . esc_attr( sprintf(
						/* translators: %s: the filter label being removed. */
						__( 'Remove filter: %s', 'events-search-addon-for-the-events-calendar' ),
						$chip['label']
					) ) . '">'
					. esc_html( $chip['label'] ) . ' <span aria-hidden="true">&times;</span></button>';
			}
			// Clear-all is a real link to the unfiltered page (works without JS;
			// JS intercepts). The per-chip remove controls stay <button>s: each
			// removal yields a filtered URL that §7 keeps out of the crawlable
			// set, so they are deliberately JS-only, with Clear-all and the bar
			// form as the no-JS recovery path.
			$html .= '<a class="ecsa-clear-all" href="' . esc_url( self::current_url_base() ) . '">' . esc_html__( 'Clear all', 'events-search-addon-for-the-events-calendar' ) . '</a>';
			$html .= '</div>';

			return $html;
		}

		/**
		 * The translated sort options, in menu order.
		 *
		 * One list, read by the no-JS `<select>`, by the enhanced trigger's value
		 * readout and (through the select's own options) by the JS menu — so the
		 * three can never name the same sort differently.
		 *
		 * @since 2.2.0
		 * @return array<string, string> Sort key => label.
		 */
		private static function sort_options() {
			return array(
				'date_asc'  => __( 'Date (soonest first)', 'events-search-addon-for-the-events-calendar' ),
				'date_desc' => __( 'Date (latest first)', 'events-search-addon-for-the-events-calendar' ),
				'title'     => __( 'Title (A–Z)', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * The sort control: a native `<select>` substrate plus the enhanced
		 * trigger the runtime upgrades to.
		 *
		 * Progressive enhancement, exactly as `id_facet_dropdown()` does it. The
		 * `<select name="ecsa_sort">` is the REAL control and stays: it lives in a
		 * GET form carrying the rest of the criteria as hidden inputs, and a
		 * `<noscript>` submit applies it, so sorting works with JavaScript off. The
		 * runtime hides that substrate, unhides the `<button>` trigger, and drives
		 * the select programmatically — one source of truth either way.
		 *
		 * WHY THE TRIGGER IS NOT COSMETIC. `.ecsa-results` is the tray
		 * (`--ecsa-color-soft`), and every derived token is mixed against
		 * `--ecsa-color-surface`, so a token colour may only be used on a surface we
		 * painted. A native `<select>` had to be given a plate of its own as a
		 * stop-gap; the trigger IS a painted plate, so its 13px/500 label sits on
		 * `--ecsa-color-surface` in the TEXT colour (13.52–17.74:1 across all seven
		 * shipped palettes) instead of a muted colour on an unpainted tray
		 * (4.49:1 on the warm-beige preset — under the AA floor). It also removes the
		 * last native control on the results side that depended on `color-scheme`
		 * to look right on a dark theme.
		 *
		 * @since 2.0.0
		 * @param string $instance_id Instance id (namespaces the menu id).
		 * @param array  $criteria    Normalized criteria.
		 * @param bool   $preview     Render the POST-enhancement state (no runtime).
		 * @return string
		 */
		private static function sort_control( $instance_id, array $criteria, $preview = false ) {
			$options     = self::sort_options();
			$current     = isset( $options[ $criteria['sort'] ] ) ? $criteria['sort'] : 'date_asc';
			$menu_id     = $instance_id . '__sort-menu';
			$label_id    = $instance_id . '__sort-label';
			$trigger_id  = $instance_id . '__sort-trigger';
			$legend      = __( 'Sort', 'events-search-addon-for-the-events-calendar' );

			// See bar(): a preview renders inside the settings screen's own form,
			// and a nested <form> is dropped by the HTML parser.
			$html  = $preview
				? '<div class="ecsa-results__sort">'
				: '<form class="ecsa-results__sort" method="get" action="' . esc_url( self::current_url_base() ) . '">';
			$html .= $preview ? '' : self::hidden_criteria_inputs( $criteria, array( 'ecsa_sort', 'ecsa_page' ) );

			// --- The no-JS substrate. Hidden in the preview because there is no
			// runtime there to hide it, and the preview must show what a visitor
			// actually sees (the same rule id_facet_dropdown() follows).
			$html .= '<label class="ecsa-sort__nojs"' . ( $preview ? ' hidden' : '' ) . '>';
			$html .= '<span class="ecsa-sort__legend">' . esc_html( $legend ) . '</span> ';
			$html .= '<select name="ecsa_sort" class="ecsa-sort">';

			foreach ( $options as $value => $label ) {
				$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>'
					. esc_html( $label ) . '</option>';
			}

			$html .= '</select></label>';

			if ( ! $preview ) {
				$html .= '<noscript><button type="submit" class="ecsa-sort__go">' . esc_html( $legend ) . '</button></noscript>';
			}

			// --- The enhanced control. One wrapper so the runtime unhides a single
			// node rather than tracking two.
			$html .= '<span class="ecsa-sort__ui"' . ( $preview ? '' : ' hidden' ) . '>';
			$html .= '<span class="ecsa-sort__label" id="' . esc_attr( $label_id ) . '">' . esc_html( $legend ) . '</span>';
			// The button's own text is the VALUE ("Date (soonest first)"), which on
			// its own never says what is being sorted. `aria-labelledby` prepends
			// the visible "Sort" label, so the accessible name is "Sort, Date
			// (soonest first)" — and it keeps working below 480px where the label
			// is display:none, because a referenced hidden element still
			// contributes to the accessible-name computation.
			$html .= '<button type="button" class="ecsa-sort-trigger" id="' . esc_attr( $trigger_id ) . '"'
				. ' aria-haspopup="menu" aria-expanded="false"'
				. ' aria-labelledby="' . esc_attr( $label_id . ' ' . $trigger_id ) . '"'
				. ' aria-controls="' . esc_attr( $menu_id ) . '">'
				. '<span class="ecsa-sort-trigger__value">' . esc_html( $options[ $current ] ) . '</span>'
				// The same inline chevron the facet triggers use. Never `&#9662;`:
				// the text glyph is font-dependent, cannot be sized against the
				// label's metrics, and blurs under the rotate-on-open transform.
				. '<span class="ecsa-sort-trigger__caret" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"'
				. ' stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
				. '<polyline points="6 9.5 12 15.5 18 9.5"/>'
				. '</svg></span>'
				. '</button>';
			$html .= '</span>';

			$html .= $preview ? '</div>' : '</form>';

			return $html;
		}

		/**
		 * Emit hidden `<input>`s reproducing a criteria set as `ecsa_*` params.
		 *
		 * Derived from the canonical serializer so the no-JS submit reconstructs
		 * exactly the URL the JS path would build. Given params are omitted (the
		 * form provides its own value for them).
		 *
		 * @since 2.0.0
		 * @param array    $criteria Normalized criteria.
		 * @param string[] $except   Param names to skip (e.g. the one the form sets).
		 * @return string
		 */
		private static function hidden_criteria_inputs( array $criteria, array $except = array() ) {
			$query = Url_State::serialize( $criteria );

			if ( '' === $query ) {
				return '';
			}

			$pairs = explode( '&', $query );
			$html  = '';

			foreach ( $pairs as $pair ) {
				$parts = explode( '=', $pair, 2 );
				$name  = rawurldecode( $parts[0] );

				if ( in_array( $name, $except, true ) ) {
					continue;
				}

				$value = isset( $parts[1] ) ? rawurldecode( $parts[1] ) : '';
				$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}

			return $html;
		}

		/**
		 * The count with its NUMBERS wrapped in `<strong>`.
		 *
		 * The tray is `--ecsa-color-soft`, so the connective words are painted in a
		 * tray-relative muted colour while the numbers stay at `--ecsa-color-text`
		 * at weight 600 — the handoff's treatment, and the reason the count stays
		 * legible without a plate of its own. The runtime builds the identical
		 * structure in `Results.updateCount()`, with the same translated pattern,
		 * so a live update cannot flatten it back to one colour.
		 *
		 * Both the pattern and every substitution are escaped here; the only markup
		 * is the `<strong>` this method itself writes.
		 *
		 * @since 2.2.0
		 * @param array    $items    Items on this page.
		 * @param int|null $total    Total, or null when not computed.
		 * @param int      $page     Current page.
		 * @param int      $per_page Page size.
		 * @return string Escaped HTML.
		 */
		private static function count_html( array $items, $total, $page, $per_page ) {
			$count = count( $items );

			if ( 0 === $count ) {
				return esc_html__( 'No events found', 'events-search-addon-for-the-events-calendar' );
			}

			$first = ( ( $page - 1 ) * $per_page ) + 1;
			$last  = $first + $count - 1;

			$strong = function ( $value ) {
				return '<strong>' . esc_html( (string) $value ) . '</strong>';
			};

			if ( null !== $total ) {
				$shown = ( $total > Query_Engine::TOTAL_DISPLAY_CAP ) ? ( Query_Engine::TOTAL_DISPLAY_CAP . '+' ) : (string) $total;

				return sprintf(
					/* translators: 1: first result number, 2: last result number, 3: total. All three are emphasised. */
					esc_html__( 'Showing %1$s–%2$s of %3$s', 'events-search-addon-for-the-events-calendar' ),
					$strong( $first ),
					$strong( $last ),
					$strong( $shown )
				);
			}

			return sprintf(
				/* translators: 1: first result number, 2: last result number. Both are emphasised. */
				esc_html__( 'Showing %1$s–%2$s', 'events-search-addon-for-the-events-calendar' ),
				$strong( $first ),
				$strong( $last )
			);
		}

		/**
		 * Run the query engine, degrading to an empty result on any failure.
		 *
		 * @since 2.0.0
		 * @param array $criteria Normalized criteria.
		 * @return array
		 */
		private static function run_engine( array $criteria ) {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Query\Query_Engine' ) ) {
				return array( 'items' => array(), 'total' => 0, 'has_more' => false );
			}

			$engine = new Query_Engine( $criteria );

			return $engine->get_results();
		}

		/*
		 * NOTE: `facet_data()` lived here, asking `Query\Facets::for_criteria()`
		 * for the option lists and counts the category / tag / venue / organizer
		 * panels rendered from. None of those facets is part of this plugin, and
		 * the two that are — the keyword box and the date filter — have no option
		 * list to fetch: the date presets are a fixed, translated vocabulary
		 * (`date_labels()`), not data.
		 *
		 * So the whole `$facets` bag is gone rather than passed around empty. It
		 * was a parameter on `bar()`, `results()`, `expanded_column()`,
		 * `state_blocks()` and `active_filters()`; every one of those signatures
		 * lost it, and so did the three external callers (`Display\Tec_Views`,
		 * `Rest\Preview_Controller` and the harnesses). An always-empty array
		 * threaded through five methods is not a smaller API, it is the same API
		 * lying about what it needs.
		 */

		/*
		 * NOTE: `bar_has_facets()` lived here — "does this bar carry any facet
		 * beyond the keyword?". Its one caller was the branch in `instance()` that
		 * fetched option lists for a bar driving a SEPARATE results region; with no
		 * option lists to fetch, the question has no consumer.
		 */

		/**
		 * Normalize an instance config against the shipped defaults.
		 *
		 * @since 2.0.0
		 * @param array $config Raw config.
		 * @return array
		 */
		private static function normalize_config( array $config ) {
			/*
			 * THE SECOND HALF OF THE SHIPPED-DEFAULT CONTRACT. Every value here
			 * must equal its twin on `Render\Instance` — this array is what a
			 * DIRECT `Renderer::instance()` caller gets, and Instance is what the
			 * shortcode front door gets, so a disagreement means
			 * one entry point renders a different bar from the same intent.
			 * The two are asserted against each
			 * OTHER rather than against copied literals, which is what caught the
			 * two pre-existing disagreements fixed here: `bar_template` said
			 * `plain` (not a value the enum has accepted since the design tokens
			 * landed, so every direct caller silently got the `unified` fallback
			 * below) and `filters_button_style` was missing entirely. That second
			 * key is now withdrawn from BOTH sides, so the pair still agrees — by
			 * neither of them declaring it.
			 */
			$defaults = array(
				// A bar, never a bar with results glued underneath it.
				'role'               => 'bar',
				'typeahead'          => 'dropdown',
				// WHICH slice of the calendar this instance opens on. A real
				// config key since Stage G: v1.3.6's `disable-past-events` maps
				// onto it, and before that the seed below hard-coded `upcoming`.
				'time'               => 'upcoming',
				// How many rows the type-ahead asks the public route for.
				'suggest_limit'      => 8,
				// Suggestions only. A bar has never drawn results itself, so the
				// last-resort default is the placement that asks for none —
				// matching Instance::RESULTS_MODE_DEFAULT and the shipped setting.
				'results_mode'       => 'none',
				// Search box only. Filters are opt-in, so a bare instance can
				// never grow a filter row onto a page nobody edited.
				'facets'             => array( 'search' ),
				'view'               => 'grid',
				'placeholder'        => '',
				'target'             => '',
				'per_page'           => Criteria::PER_PAGE_DEFAULT,
				/*
				 * Title-only matching. `Criteria::SEARCH_FIELDS` is the ALLOWLIST,
				 * not the default — using it here was what made a direct caller
				 * widen its own search without being asked. The two coincide at one
				 * choice today; keeping them separate is what stops the default
				 * following the allowlist if it ever grows again.
				 *
				 * A LITERAL, deliberately, and not `Instance::SEARCH_FIELDS_DEFAULT`:
				 * `Shortcode::v2_config()` falls back to handing the Renderer a raw
				 * bag precisely WHEN the Instance file is missing from a partial
				 * deploy, so a class reference here would turn that designed
				 * degradation into the fatal it exists to avoid; the
				 * two constants are asserted equal instead.
				 */
				'search_fields'      => array( 'title' ),
				// WHERE the filters live. The vocabulary is `bar_inline | expanded`;
				// the old `below | inbar | button` spellings are DELETED, not
				// mapped, and so are `bar_button` and `under_bar`.
				'filters_visibility' => 'expanded',
				'filter_style'       => 'text',
				// NOTE: `filters_button_style` was declared here. WITHDRAWN with the
				// axis — `design_attrs()` stamps the shipped Filters-toggle class
				// unconditionally now, so there is no key for a default to seed.
				'columns'            => 3,
				'card_size'          => 100,
				'card_fields'        => array( 'image', 'title', 'date', 'venue', 'cost' ),
				'date_format'        => 'site',
				'template'           => 'clean',
				// Was `plain`, which the enum below has not accepted since the
				// curated design tokens landed — so this default was DEAD and the
				// coercion's own fallback silently supplied the real one.
				'bar_template'       => 'unified',
				'accent_color'       => '',
				'text_color'         => '',
				'bg_color'           => '',
				'button_style'       => 'solid_icon',
				'control_size'       => 100,
				'corner_radius'      => 8,
			);

			$config = array_merge( $defaults, $config );

			// Design tokens — coerce defensively (a direct Renderer::instance()
			// caller may bypass Instance::from_atts()). Colors: '' | 'transparent'
			// | #hex only; never arbitrary CSS.
			// TWO bar frames. `capsule` (Pill) and `soft` (Filled) are RETIRED —
			// a background colour and the radius slider already say both, and
			// `capsule` additionally overrode that slider from another control.
			// NOTE `soft` survives as a `filter_style`; only this axis loses it.
			$config['bar_template']  = in_array( $config['bar_template'], array( 'detached', 'unified' ), true ) ? $config['bar_template'] : 'unified';
			$config['button_style']  = in_array( $config['button_style'], array( 'solid', 'solid_icon', 'outline', 'icon', 'text', 'none' ), true ) ? $config['button_style'] : 'solid_icon';
			// `filter_style` + `filters_visibility` are cleaned by
			// normalize_display_config() at the tail of this method, so they are
			// coerced exactly once.
			$config['control_size']  = max( 80, min( 140, (int) $config['control_size'] ) );
			$config['card_size']     = max( 80, min( 140, (int) $config['card_size'] ) );
			$config['columns']       = max( 2, min( 5, (int) $config['columns'] ) );
			// NOTE: `filter_columns` was clamped here. RETIRED — filters flow one
			// after another with tight spacing instead of sitting on an N-column
			// grid, so there is no count to clamp.
			$config['corner_radius'] = max( 0, min( 24, (int) $config['corner_radius'] ) );
			$config['accent_color']  = self::clean_css_color( $config['accent_color'], false );
			$config['text_color']    = self::clean_css_color( $config['text_color'], false );
			$config['bg_color']      = self::clean_css_color( $config['bg_color'], true );

			// Card fields: allowlisted, empty means "show everything".
			$config['card_fields'] = self::card_fields( $config );
			// How a card's date line is written. Allowlisted here too, because
			// normalize_config() must never trust its caller.
			$config['date_format'] = self::clean_date_format( $config['date_format'] );
			// The card template, same rule.
			$config['template']    = self::clean_template( $config['template'] );

			/*
			 * ALLOWLIST #2 OF TWO. `Instance::ROLES` is #1. They must be edited
			 * together: this one is what a DIRECT `Renderer::instance()` caller
			 * meets, so leaving `bar-results` here after retiring it there would
			 * keep the retired value fully reachable — a bar that still appended
			 * results, through a door nobody was looking at.
			 */
			$config['role']         = in_array( $config['role'], array( 'bar', 'results' ), true ) ? $config['role'] : 'bar';
			$config['typeahead']    = in_array( $config['typeahead'], array( 'off', 'dropdown' ), true ) ? $config['typeahead'] : 'dropdown';
			// Re-validated here as well as in Instance: normalize_config() is
			// reached by direct callers that never passed through the front door.
			$config['time']          = in_array( $config['time'], Criteria::TIMES, true ) ? $config['time'] : 'upcoming';
			$config['suggest_limit'] = max( 1, min( 20, (int) $config['suggest_limit'] ) );
			/*
			 * ALLOWLIST #2 OF TWO for the placement. `Instance::RESULTS_MODES` is
			 * #1; they must be edited together, or a value the front door accepts
			 * is silently rewritten here for every direct caller. `none` — the
			 * suggestions-only placement — renders exactly as `inline` does; what
			 * it changes is the derived type-ahead and whether a second shortcode
			 * exists at all.
			 */
			$config['results_mode']    = in_array( $config['results_mode'], array( 'none', 'inline' ), true ) ? $config['results_mode'] : 'none';
			$config['view']            = in_array( $config['view'], array( 'grid', 'list' ), true ) ? $config['view'] : 'grid';
			$config['placeholder']  = sanitize_text_field( (string) $config['placeholder'] );
			$config['target']       = sanitize_html_class( (string) $config['target'] );
			$config['per_page']     = min( Criteria::PER_PAGE_MAX, max( 1, (int) $config['per_page'] ) );

			$facets = array();
			foreach ( (array) $config['facets'] as $facet ) {
				$facet = sanitize_key( $facet );
				if ( in_array( $facet, Criteria::FACET_KEYS, true ) && ! in_array( $facet, $facets, true ) ) {
					$facets[] = $facet;
				}
			}
			$config['facets'] = $facets ? $facets : array( 'search' );

			// The admin-only keyword-search scope. Independently re-cleaned here —
			// normalize_config() must never trust its caller — and emitted in the
			// fixed Criteria::SEARCH_FIELDS order so the scope hashes stably. An
			// empty/all-invalid scope maps back to the SHIPPED default (title), so
			// it is never un-searchable and never silently WIDER than what the
			// admin last chose. Mirrors Instance::clean_search_fields().
			$requested = array();
			foreach ( (array) $config['search_fields'] as $field ) {
				$requested[ sanitize_key( (string) $field ) ] = true;
			}
			$search_fields = array();
			foreach ( Criteria::SEARCH_FIELDS as $field ) {
				if ( isset( $requested[ $field ] ) ) {
					$search_fields[] = $field;
				}
			}
			$config['search_fields'] = $search_fields ? $search_fields : array( 'title' );

			return self::normalize_display_config( $config );
		}

		/**
		 * Re-clean the render-only filter-display keys against their allowlists.
		 *
		 * Split out and callable from `bar()` too, because `bar()` is public and a
		 * direct caller (a test, a partial deploy) may hand it a config that never
		 * passed through `normalize_config()`. Idempotent — a config already
		 * normalized here is unchanged — and it never trusts its input: an unknown
		 * value falls back to the shipped default so the un-configured bar is
		 * byte-identical to today. The keys: `filters_visibility` and
		 * `filter_style`.
		 *
		 * Retired keys are dropped outright: `date_style`/`facet_style` (the
		 * original pair), the per-filter `facet_styles` map — gone because the
		 * trigger geometry is the bar's visual language and therefore GLOBAL — and
		 * `groups_collapsed`, gone because every filter is already a trigger + a
		 * panel, so a second disclosure around the group expressed nothing. A stale
		 * caller may still pass any of them; none reaches the render path. The
		 * stored-option equivalent of this mapping lives in
		 * `Settings::map_legacy()`.
		 *
		 * @since 2.0.0
		 * @param array $config Config (may lack the display keys).
		 * @return array
		 */
		private static function normalize_display_config( array $config ) {
			/*
			 * WHERE the filters live. TWO meanings, one per value:
			 *
			 *   bar_inline  filters always visible inside the bar's keyword row;
			 *               folds behind the in-bar Filters toggle when the row
			 *               runs out of room (a container-query state, one DOM,
			 *               not a second placement).
			 *   expanded    no triggers at all; every filter's options at once.
			 *
			 * The old `below | inbar | button` spellings are DELETED, not mapped —
			 * see Instance::FILTERS_VISIBILITIES for why — and so are `bar_button`
			 * and `under_bar`. Anything unknown falls to the shipped default, so a
			 * stale value cannot silently mean something else.
			 */
			$visibility = isset( $config['filters_visibility'] ) && is_scalar( $config['filters_visibility'] )
				? sanitize_key( (string) $config['filters_visibility'] )
				: 'expanded';
			$config['filters_visibility'] = in_array( $visibility, array( 'bar_inline', 'expanded' ), true ) ? $visibility : 'expanded';

			// HOW a trigger looks — one global vocabulary for every filter, date
			// included. It is emitted as a wrapper modifier class by design_attrs(),
			// never as a per-facet class. ONE value, so the settings panel renders
			// no control for it; the allowlist stays because this method must never
			// trust its caller.
			$filter_style = isset( $config['filter_style'] ) && is_scalar( $config['filter_style'] )
				? sanitize_key( (string) $config['filter_style'] )
				: 'text';
			$config['filter_style'] = in_array( $filter_style, array( 'text' ), true ) ? $filter_style : 'text';

			// The retired keys never survive normalization, so nothing downstream
			// can read a value the render path no longer honours. `filter_columns`
			// joins them: the filter row FLOWS now, so a column count has no
			// meaning and must not linger in a config other code could read.
			unset( $config['date_style'], $config['facet_style'], $config['facet_styles'], $config['groups_collapsed'], $config['filter_columns'] );

			return $config;
		}

		/**
		 * Parse the request URL into seed criteria, but only for the URL owner.
		 *
		 * A non-owner instance seeds from its own config defaults, so two bars
		 * on one page do not both read the same `$_GET`.
		 *
		 * @since 2.0.0
		 * @param array $config Config.
		 * @return array Normalized criteria.
		 */
		private static function criteria_from_request( array $config ) {
			// Config-only fields form the SEED; the URL overlays only the params
			// it actually carried. Using Url_State::parse() (which fills in ALL
			// defaults) would clobber the configured per_page/view with the
			// shipped defaults whenever the URL omitted them — the exact
			// SSR-vs-seed divergence the DOM contract exists to prevent, since a
			// clean visit has no ecsa_* params at all.
			$seed = array(
				// The instance's OWN window, not a hard-coded one. This is the
				// other half of wiring v1.3.6's `disable-past-events`: the map has
				// always computed `time`, but the seed pinned `upcoming`
				// here, so neither the legacy attribute nor the admin's saved
				// setting could ever reach the query. The URL still overlays it,
				// so a visitor's `?ecsa_time=past` continues to win.
				'time'          => isset( $config['time'] ) ? $config['time'] : 'upcoming',
				'per_page'      => (int) $config['per_page'],
				'view'          => $config['view'],
				// Admin-only scope: never a URL param, so it lives in the seed and
				// survives Url_State::overlay() (which only overwrites URL-carried
				// keys) — the SSR query then honours the same scope REST will.
				'search_fields' => isset( $config['search_fields'] ) ? $config['search_fields'] : Criteria::SEARCH_FIELDS,
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only faceted GET, no state change.
			$criteria = Url_State::overlay( $seed, wp_unslash( $_GET ) );

			/*
			 * per_page=0 is the REST count-only mode, not a render mode. Reached
			 * only by hand-editing the URL, it would make the engine return zero
			 * items and the SSR paint a misleading "no events" state over a
			 * non-zero total. The SSR always renders a page of results, so fall
			 * back to the configured size.
			 */
			if ( (int) $criteria['per_page'] < 1 ) {
				$criteria['per_page'] = (int) $config['per_page'];
			}

			return $criteria;
		}

		/**
		 * Claim URL ownership for this instance if appropriate.
		 *
		 * A results instance owns the URL; a dropdown-only search bar never does.
		 * First qualifying instance on the page wins (plan §1.1).
		 *
		 * NOTE: `results_mode = page` also qualified — a bar that submitted to a
		 * dedicated results page owned the URL it was about to write. There is no
		 * such placement, so the role is the whole test.
		 *
		 * @since 2.0.0
		 * @param array $config Config.
		 * @return bool
		 */
		private static function claim_url_owner( array $config ) {
			if ( self::$url_owner_claimed ) {
				return false;
			}

			$qualifies = ( 'results' === $config['role'] );

			if ( $qualifies ) {
				self::$url_owner_claimed = true;
				return true;
			}

			return false;
		}

		/**
		 * The next per-request instance id.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		private static function next_instance_id() {
			self::$counter++;
			return 'ecsa-i' . self::$counter;
		}

		/**
		 * The current page URL with any ecsa_* params stripped, as a form action.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		private static function current_url_base() {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			if ( '' === $path ) {
				return home_url( '/' );
			}

			$home  = home_url( '/' );
			$parts = wp_parse_url( $home );
			$origin = isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] : '';

			$split = explode( '?', $origin . $path, 2 );
			$query = isset( $split[1] ) ? $split[1] : '';

			$kept = array();
			if ( '' !== $query ) {
				parse_str( $query, $params );
				foreach ( $params as $k => $v ) {
					if ( 0 !== strpos( (string) $k, 'ecsa_' ) ) {
						$kept[ $k ] = $v;
					}
				}
			}

			$url = $split[0];
			if ( $kept ) {
				$url .= '?' . http_build_query( $kept, '', '&', PHP_QUERY_RFC3986 );
			}

			return $url;
		}

		/**
		 * The bar's form action — the URL a no-JS submit navigates to.
		 *
		 * Always the CURRENT URL (minus any stale `ecsa_*` params), so the bar
		 * always works and a submit lands on the page the visitor is already on,
		 * where the results region lives. The JS overlays live REST updates on top.
		 *
		 * NOTE: it used to fork on `results_mode = page`, returning a configured
		 * results page's permalink when that page existed and was PUBLISHED, and
		 * falling back to the current URL otherwise so a mis-configured page
		 * degraded to an in-place submit rather than a dead link. There is no page
		 * placement and no `results_page_id`, so the fallback is the whole rule.
		 *
		 * Kept as a method rather than inlined at its one call site: it is where
		 * the form-action contract is stated, and a future placement would fork
		 * here.
		 *
		 * @since 2.0.0
		 * @param array $config Normalized instance config.
		 * @return string
		 */
		private static function bar_action( array $config ) {
			/*
			 * WHERE A SEARCH CAN ACTUALLY BE ANSWERED.
			 *
			 * This used to discard its config and always return the current page,
			 * which is correct only when something on that page renders results.
			 * With `results_mode = none` — a dropdown-only placement — it reloaded
			 * the page with `?ecsa_q=…` onto a page that shows nothing: the visitor
			 * lost their place and got no answer, which reads as a broken search
			 * rather than an empty one.
			 *
			 * Resolved in order, and the order matters:
			 *
			 *  1. Results on this page (`inline`, or Pro's `page`) — post here.
			 *  2. No results here, but the events-page integration is ON — post to
			 *     the events archive, where our params genuinely drive TEC's query.
			 *  3. Neither — nowhere to post. Returns '' and the bar is marked so
			 *     the runtime intercepts instead of submitting.
			 *
			 * STEP 2 IS CONDITIONAL, and that is the whole point: `ecsa_q` only
			 * drives the events page when `Tec_Views` has registered its repository
			 * filter, which happens only in header_swap/replace. With the
			 * integration off the keyword is ignored there, so sending the visitor
			 * would MOVE the dead end rather than remove it, and cost them their
			 * original page as well.
			 *
			 * The namespace is derived rather than written out, so this file stays
			 * byte-identical across the two editions.
			 */
			$mode = isset( $config['results_mode'] ) ? (string) $config['results_mode'] : 'none';

			if ( 'none' !== $mode && '' !== $mode ) {
				return self::current_url_base();
			}

			$sep  = '\\';
			$base = substr( __NAMESPACE__, 0, (int) strrpos( __NAMESPACE__, $sep ) );

			$views = $base . $sep . 'Display' . $sep . 'Tec_Views';
			$tec   = $base . $sep . 'Tec' . $sep . 'Tec';

			if ( ! class_exists( $views ) || ! method_exists( $views, 'mode' ) ) {
				return '';
			}

			$tec_mode = (string) call_user_func( array( $views, 'mode' ) );

			if ( '' === $tec_mode || 'off' === $tec_mode ) {
				return '';
			}

			if ( ! class_exists( $tec ) || ! method_exists( $tec, 'events_archive_url' ) ) {
				return '';
			}

			$archive = (string) call_user_func( array( $tec, 'events_archive_url' ) );

			return '' !== $archive ? $archive : '';
		}

		/**
		 * The wrapper class + inline CSS-var style for the curated design tokens.
		 *
		 * Only the three LAYOUT-shaped tokens become modifier CLASSES —
		 * `bar_template` (`ecsa--tpl-detached|unified`, which decides whether the
		 * border sits on the input or wraps the whole group),
		 * `button_style` (`ecsa--btn-solid|solid-icon|outline|icon|text|none`, the
		 * SEARCH submit) and `filter_style` (`ecsa--trg-text`, the filter TRIGGER
		 * geometry). Everything numeric or colored is a CSS custom property, so one
		 * slider scales the whole bar without a class per step: `--ecsa-scale`,
		 * `--ecsa-card-scale`, `--ecsa-columns`,
		 * `--ecsa-radius`, and the three colors. Every value is already sanitized (enum / #hex / int), so nothing
		 * arbitrary reaches the style attribute — themes cannot bleed into the bar,
		 * and the bar cannot inject CSS.
		 *
		 * A FOURTH class, `ecsa--fbtn-icon-text-bordered`, is emitted as a LITERAL.
		 * It used to carry `filters_button_style`; that axis is withdrawn, so the
		 * class no longer varies and is stamped rather than resolved. It stays on
		 * the wrapper because the stylesheet's Filters-toggle rules are written
		 * against it, and a constant hook is cheaper than rewriting every one of
		 * them — but nothing reads a setting to produce it, which is the point.
		 *
		 * The trigger style is a WRAPPER class rather than a per-facet one for two
		 * reasons: it is the bar's visual language (global by definition, which is
		 * why the per-filter `facet_styles` map is gone), and it keeps the settings
		 * preview able to swap it client-side with zero requests.
		 *
		 * @since 2.0.0
		 * @param array $config Normalized config.
		 * @return array{class:string,style:string}
		 */
		public static function design_attrs( array $config ) {
			// This method is PUBLIC (the TEC injection and the settings preview
			// call it directly), so it re-validates rather than trusting its
			// caller — the numerics below are clamped for exactly the same reason.
			// Without this a direct caller could mint an `ecsa--tpl-bogus` class:
			// harmless after sanitize_html_class(), but it would silently paint an
			// unstyled bar instead of falling back to the default look.
			$style = isset( $config['button_style'] ) ? $config['button_style'] : 'solid_icon';
			$tpl   = isset( $config['bar_template'] ) ? $config['bar_template'] : 'unified';

			// A direct caller may still be speaking an older vocabulary, so the
			// trigger style is read forward exactly as normalize_display_config()
			// would before it is allowlisted.
			$trigger = isset( $config['filter_style'] ) && is_scalar( $config['filter_style'] )
				? sanitize_key( (string) $config['filter_style'] )
				: 'text';

			if ( ! in_array( $tpl, array( 'detached', 'unified' ), true ) ) {
				$tpl = 'unified';
			}
			if ( ! in_array( $style, array( 'solid', 'solid_icon', 'outline', 'icon', 'text', 'none' ), true ) ) {
				$style = 'solid_icon';
			}
			if ( ! in_array( $trigger, array( 'text' ), true ) ) {
				$trigger = 'text';
			}

			// Only the LAYOUT-shaped tokens stay modifier classes; everything
			// numeric or colored is a custom property, so one slider can scale the
			// whole bar without a class per step.
			//
			// `ecsa--fbtn-icon-text-bordered` IS A CONSTANT, not a resolved value.
			// The axis behind it is withdrawn, so there is nothing to read and
			// nothing to allowlist — the class is emitted so the stylesheet's
			// Filters-toggle rules keep a hook rather than every one of them having
			// to be rewritten to an unqualified selector.
			$class = 'ecsa ecsa--tpl-' . sanitize_html_class( $tpl )
				. ' ecsa--btn-' . sanitize_html_class( str_replace( '_', '-', $style ) )
				. ' ecsa--trg-' . sanitize_html_class( $trigger )
				. ' ecsa--fbtn-icon-text-bordered';

			$scale      = max( 80, min( 140, (int) ( isset( $config['control_size'] ) ? $config['control_size'] : 100 ) ) );
			$card_scale = max( 80, min( 140, (int) ( isset( $config['card_size'] ) ? $config['card_size'] : 100 ) ) );
			$columns    = max( 2, min( 5, (int) ( isset( $config['columns'] ) ? $config['columns'] : 3 ) ) );
			// NOTE: `--ecsa-filter-columns` was emitted here from the retired
			// `filter_columns` axis. Gone: the filter row FLOWS, so there is no
			// track count to publish.
			$radius     = max( 0, min( 24, (int) ( isset( $config['corner_radius'] ) ? $config['corner_radius'] : 8 ) ) );

			/*
			 * THE GATE, RE-CHECKED. `Instance` has already pinned the sliders, but
			 * this method is PUBLIC and is called directly by the events-page
			 * injection and the settings preview. A caller handing over a partial
			 * config must not be able to leak a stored size onto a bar whose admin
			 * never opted in.
			 */
			$sizing = isset( $config['design_sizing'] ) && 'on' === $config['design_sizing'];

			if ( ! $sizing ) {
				$scale      = 100;
				$card_scale = 100;
				$radius     = 8;
			}

			// The radius is the SLIDER'S, always. `capsule` used to force `999px`
			// here — one control silently overriding another — and it went with the
			// value: an admin who wants a pill bar sets the slider to its maximum.
			$vars = array(
				'--ecsa-radius:' . $radius . 'px',
				// Unitless multipliers: every size token is derived from these.
				'--ecsa-scale:' . self::scale_ratio( $scale ),
				'--ecsa-card-scale:' . self::scale_ratio( $card_scale ),
				'--ecsa-columns:' . $columns,
			);

			/*
			 * THE ROUNDNESS RATIO. `--ecsa-radius` keeps its old meaning (the corner
			 * in absolute px); this is the same number as a multiple of the shipped
			 * 8px, and the stylesheet's whole derived ladder multiplies by it. CSS
			 * cannot divide a length by a length, which is why the ratio is computed
			 * here rather than in the sheet. THREE decimals because the slider steps
			 * by 1px and 1/8 = 0.125 has to round-trip exactly.
			 *
			 * EMITTED ONLY WHEN IT IS NOT THE NEUTRAL 1.000, so a default site's
			 * style attribute is byte-for-byte the string it was before this feature
			 * existed — and so is a site that turned sizing on but left the corner
			 * slider alone.
			 */
			$rr = number_format( $radius / 8, 3, '.', '' );

			if ( '1.000' !== $rr ) {
				$vars[] = '--ecsa-rr:' . $rr;
			}

			/*
			 * THE CAPSULE SWITCH, deliberately narrower than the gate itself.
			 *
			 * The capsule tier computes `min( rr, 1 ) * h/2`, which SATURATES at 1 —
			 * so from corner_radius 8 upward it paints exactly what the shipped
			 * `999px` paints. Emitting the switch there would buy nothing and would
			 * cost the one thing `999px` does better than any computed value: a chip
			 * whose label WRAPS taller than its declared height still self-corrects,
			 * because the engine clamps an over-large radius but cannot grow an
			 * under-large one.
			 *
			 * So the switch is emitted only below 8, the sole range where the tier
			 * can differ from the literal — and the range where the admin has
			 * explicitly asked for LESS rounding, which is exactly when a squared-off
			 * wrapped chip is the intended answer rather than a regression.
			 *
			 * Never expose this to a filter: `var()` fallbacks do not apply to a
			 * property that is set but invalid, so a garbage value here would drop
			 * five capsule declarations through the cascade armour's zero floor.
			 */
			if ( $sizing && $radius < 8 ) {
				$vars[] = '--ecsa-sizing:1';
			}

			/*
			 * Colors. Only the three INPUTS are emitted — the stylesheet derives
			 * border/muted/soft/tint/ring from them with color-mix(), which keeps
			 * this attribute short and lets the browser re-derive on the fly.
			 *
			 * TWO are the exception, both because they are luminance searches CSS
			 * has no function for (see Render\Tokens):
			 *
			 *  - `--ecsa-on-accent`, white vs near-black for text ON the accent.
			 *    Without it a light brand accent renders white-on-light at ~1.4:1.
			 *  - `--ecsa-accent-text`, the accent used AS text. Without it the accent
			 *    is asked to be legible ink on our own tint, which it is not on a
			 *    warm light palette (the warm-beige preset measured 3.48:1).
			 */
			$accent = self::clean_css_color( isset( $config['accent_color'] ) ? $config['accent_color'] : '', false );
			$text   = self::clean_css_color( isset( $config['text_color'] ) ? $config['text_color'] : '', false );
			$bg     = self::clean_css_color( isset( $config['bg_color'] ) ? $config['bg_color'] : '', true );

			if ( '' !== $accent ) {
				$vars[] = '--ecsa-accent:' . $accent;
			}
			if ( '' !== $text ) {
				$vars[] = '--ecsa-text:' . $text;
			}
			if ( '' !== $bg ) {
				$vars[] = '--ecsa-bg:' . $bg;
			}

			if ( class_exists( __NAMESPACE__ . '\Tokens' ) ) {
				// 'transparent' is a legal background but not a colour we can take a
				// luminance of; the text colour is the honest stand-in for what the
				// accent will actually sit against.
				$on_base = ( '' !== $accent ) ? $accent : Tokens::ACCENT_DEFAULT;
				$vars[]  = '--ecsa-on-accent:' . Tokens::on_color( $on_base );

				/*
				 * The accent, made safe to use as INK. Needs all three colours, so
				 * each unset one falls back to the same shipped default the
				 * stylesheet's own `var()` fallback uses — otherwise this would be
				 * measured against a surface the browser is not going to paint.
				 *
				 * `transparent` is a legal background but has no luminance, so it
				 * takes the default too. That is the honest limit of the derivation
				 * rather than a bug: a transparent bar sits on the THEME's colour,
				 * which no server-side computation can see.
				 */
				$vars[] = '--ecsa-accent-text:' . Tokens::accent_text(
					$on_base,
					( '' !== $text ) ? $text : Tokens::TEXT_DEFAULT,
					( '' !== $bg && 'transparent' !== $bg ) ? $bg : Tokens::BG_DEFAULT
				);

				/*
				 * `color-scheme` is the only way to tell the browser to paint NATIVE
				 * controls dark — the sort <select> and its drop-down, the range
				 * panel's <input type="date"> and its picker, panel scrollbars, and
				 * the UA focus ring. Without it a dark bar renders a stark white
				 * select and a white date picker, which is the single most obvious
				 * "this plugin doesn't really support dark" tell.
				 *
				 * The light/dark decision REUSES on_color() rather than introducing a
				 * second threshold: if white is the colour that wins contrast on this
				 * background, the background is dark. One source of truth, so the two
				 * can never disagree.
				 *
				 * Only emitted when the bar actually sets a background — otherwise the
				 * bar is transparent and the PAGE's scheme is the correct answer.
				 *
				 * It is a MODIFIER CLASS, not an inline declaration, and that is
				 * deliberate. `color-scheme` is absent from WordPress's
				 * `safe_style_css` allowlist, while `--*` custom properties are
				 * explicitly passed through and `class` is an allowed attribute. So
				 * had this stayed inline, any downstream integration that ran our
				 * output through `wp_kses_post()` — a page builder, or a theme being
				 * "safe" with shortcode output — would have silently eaten exactly
				 * this one declaration while every other token survived. The symptom
				 * would be "dark bars have white date pickers again", with no error
				 * anywhere to explain it. A class cannot fail that way.
				 */
				if ( '' !== $bg && 'transparent' !== $bg ) {
					$class .= ( Tokens::ON_LIGHT === Tokens::on_color( $bg ) )
						? ' ecsa--scheme-dark'
						: ' ecsa--scheme-light';
				}
			}

			return array(
				'class' => $class,
				'style' => implode( ';', $vars ),
			);
		}

		/**
		 * Turn a percent scale (80–140) into a CSS-safe unitless ratio string.
		 *
		 * Formatted with one decimal and a hard-coded '.' so a locale that uses a
		 * comma decimal separator can never emit invalid CSS.
		 *
		 * @since 2.1.0
		 * @param int $percent Scale percent.
		 * @return string
		 */
		private static function scale_ratio( $percent ) {
			return number_format( (int) $percent / 100, 2, '.', '' );
		}

		/**
		 * Sanitize a design color to '' | 'transparent' | '#hex'. Defensive twin
		 * of Instance::clean_color() for direct Renderer callers.
		 *
		 * @since 2.0.0
		 * @param mixed $value             Raw color.
		 * @param bool  $allow_transparent Whether 'transparent' is permitted.
		 * @return string
		 */
		private static function clean_css_color( $value, $allow_transparent = false ) {
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
		 * The `date_format` vocabulary: how a card's date line is written.
		 *
		 * `site` is the default and the honest one — it uses WordPress's own
		 * `date_format` / `time_format` options, which is what most site owners
		 * have already configured and what `wp_date()` gives us for free. The four
		 * explicit patterns exist so an admin who wants day-first or ISO does not
		 * have to change a site-wide setting to get it.
		 *
		 * Each entry carries the pieces the renderer needs:
		 *
		 *   date   the whole date, for a single-day event
		 *   time   the clock, appended after the separator (omitted for all-day)
		 *   head   the leading half of a same-month range ("August 1")
		 *   tail   the trailing half of a same-month range ("3, 2026")
		 *
		 * `head`/`tail` are absent for `site` and `iso`: an arbitrary admin pattern
		 * cannot be decomposed, and an ISO date has no compact range form. Those
		 * two fall back to "full – full", which is longer but never wrong. Nothing
		 * here is guessed.
		 *
		 * @since 2.2.0
		 * @return array<string, array<string, string>>
		 */
		private static function date_formats() {
			return array(
				'long'   => array( 'date' => 'F j, Y', 'time' => 'g:i a', 'head' => 'F j', 'tail' => 'j, Y' ),
				'medium' => array( 'date' => 'M j, Y', 'time' => 'g:i a', 'head' => 'M j', 'tail' => 'j, Y' ),
				'dmy'    => array( 'date' => 'j F Y', 'time' => 'H:i', 'head' => 'j', 'tail' => 'j F Y' ),
				'iso'    => array( 'date' => 'Y-m-d', 'time' => 'H:i' ),
			);
		}

		/**
		 * One worked example per `date_format` key, for the admin-facing choosers.
		 *
		 * Rendered with `wp_date()` from ONE fixed sample moment, so every screen
		 * that offers this setting shows the same localized, timezone-correct
		 * string the cards will actually print — rather than a hand-written label
		 * that drifts the first time a pattern changes.
		 *
		 * The sample is a Friday evening in a month whose short and long names
		 * differ ("August" / "Aug"), so `long` and `medium` are visibly distinct.
		 *
		 * @since 2.2.0
		 * @return array<string, string> Format key => example string. `site` first.
		 */
		public static function date_format_examples() {
			$tz = wp_timezone();

			// Same naive-string trap as format_date(): the sample must be
			// INTERPRETED as site-local, or every example the admin reads would be
			// shifted by the site's UTC offset and they would pick a format based
			// on a wrong preview.
			$sample = self::local_timestamp( '2026-08-14 20:00:00', $tz );
			$out    = array();

			foreach ( array_merge( array( 'site' ), array_keys( self::date_formats() ) ) as $key ) {
				$parts = self::date_format_parts( $key );
				$out[ $key ] = wp_date( $parts['date'], $sample, $tz ) . ' · ' . wp_date( $parts['time'], $sample, $tz );
			}

			return $out;
		}

		/**
		 * The date/time patterns for one `date_format` key.
		 *
		 * @since 2.2.0
		 * @param string $key Format key.
		 * @return array<string, string>
		 */
		private static function date_format_parts( $key ) {
			$formats = self::date_formats();

			if ( isset( $formats[ $key ] ) ) {
				return $formats[ $key ];
			}

			// `site` (and anything unrecognised) reads WordPress's own settings.
			return array(
				'date' => (string) get_option( 'date_format', 'F j, Y' ),
				'time' => (string) get_option( 'time_format', 'g:i a' ),
			);
		}

		/**
		 * Format a card's date line.
		 *
		 * A multi-day event reads as a RANGE ("August 1–3, 2026 · 9:00 am"), never
		 * as two separate dates: the handoff is explicit about it, and two dates
		 * stacked on one line reads as two events.
		 *
		 * ALWAYS `wp_date()`, never `date()`/`gmdate()`. The bare functions run in
		 * PHP's default timezone, which WordPress forces to UTC — that is the exact
		 * 12-hour-bug class this rewrite exists to retire,
		 * and it is why no format string here contains `T` or a bare `g`.
		 *
		 * @since 2.0.0
		 * @param string $start   Site-local 'Y-m-d H:i:s' start.
		 * @param string $key     `date_format` key.
		 * @param string $end     Site-local 'Y-m-d H:i:s' end, when known.
		 * @param bool   $all_day Whether the event is all-day (no clock).
		 * @return string
		 */

		/**
		 * Turn a NAIVE site-local datetime string into a real UTC timestamp.
		 *
		 * The timezone is passed to the constructor so PHP INTERPRETS the digits as
		 * site-local rather than converting them from UTC. `strtotime()` cannot do
		 * this — it resolves naive strings in PHP's default timezone, which
		 * WordPress pins to UTC, silently shifting every displayed time by the
		 * site's offset.
		 *
		 * @since 2.1.0
		 * @param string        $value Naive 'Y-m-d H:i:s' (or any format PHP parses).
		 * @param \DateTimeZone $tz    The site timezone.
		 * @return int|null UTC timestamp, or null when the value is empty/unparseable.
		 */
		private static function local_timestamp( $value, $tz ) {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				return null;
			}

			try {
				$dt = new \DateTimeImmutable( $value, $tz );
			} catch ( \Exception $e ) {
				return null;
			}

			return $dt->getTimestamp();
		}

		private static function format_date( $start, $key = 'site', $end = '', $all_day = false ) {
			$tz = wp_timezone();

			/*
			 * `strtotime()` is NOT usable here, and using `wp_date()` afterwards does
			 * not save it. TEC hands us a NAIVE site-local string ('Y-m-d H:i:s'),
			 * and `strtotime()` resolves a naive string in PHP's DEFAULT timezone —
			 * which WordPress forces to UTC in wp-settings.php. So the wall-clock
			 * digits get anchored as if they were UTC, and `wp_date()` then faithfully
			 * converts that wrong instant INTO the site timezone: the displayed time
			 * shifts by the site's UTC offset (8:00 pm renders as 4:00 pm at UTC-4).
			 *
			 * The corruption happens BEFORE `wp_date()` is ever called, which is why
			 * "always use wp_date()" is necessary but not sufficient. Constructing
			 * with the timezone tells PHP to INTERPRET the digits as site-local
			 * instead of converting them — the idiom Date_Presets::from_date()
			 * already uses. This is the legacy 12-hour defect class exactly.
			 */
			$ts = self::local_timestamp( $start, $tz );

			if ( null === $ts ) {
				return (string) $start;
			}

			$parts = self::date_format_parts( is_scalar( $key ) ? (string) $key : 'site' );
			$date  = self::format_date_span( $ts, self::local_timestamp( $end, $tz ), $parts, $tz );

			if ( $all_day || '' === $parts['time'] ) {
				return $date;
			}

			// The handoff's separator: a middle dot with hair space either side,
			// which reads as one line rather than two facts jammed together.
			return $date . ' · ' . wp_date( $parts['time'], $ts, $tz );
		}

		/*
		 * NOTE: `format_date_only()` and `format_time()` lived here — the date half
		 * with no clock (the retired pill's accessible name) and the time half
		 * alone (the card line that pill left behind). Both had exactly one caller
		 * each, in the retired template, and are gone with it. `format_date()`
		 * above remains the one card-line formatter, and `format_date_span()`
		 * below is still shared by it.
		 */

		/**
		 * The DATE half of a card's line — one day, or a compact range.
		 *
		 * @since 2.2.0
		 * @param int                   $start_ts Start timestamp.
		 * @param int|false             $end_ts   End timestamp, or false.
		 * @param array<string, string> $parts    Pattern pieces from date_format_parts().
		 * @param \DateTimeZone         $tz       Site timezone.
		 * @return string
		 */
		private static function format_date_span( $start_ts, $end_ts, array $parts, $tz ) {
			$single = wp_date( $parts['date'], $start_ts, $tz );

			if ( ! $end_ts || $end_ts <= $start_ts ) {
				return $single;
			}

			// Same calendar day: not a range at all.
			if ( wp_date( 'Y-m-d', $start_ts, $tz ) === wp_date( 'Y-m-d', $end_ts, $tz ) ) {
				return $single;
			}

			// A compact range is only possible inside one month of one year, and
			// only for a pattern we authored (see date_formats()).
			$same_month = ( wp_date( 'Y-m', $start_ts, $tz ) === wp_date( 'Y-m', $end_ts, $tz ) );

			if ( $same_month && isset( $parts['head'], $parts['tail'] ) ) {
				return wp_date( $parts['head'], $start_ts, $tz ) . '–' . wp_date( $parts['tail'], $end_ts, $tz );
			}

			return $single . ' – ' . wp_date( $parts['date'], $end_ts, $tz );
		}

		/**
		 * Translated date-preset labels.
		 *
		 * @since 2.0.0
		 * @return array<string, string>
		 */
		public static function time_labels() {
			return array(
				'past' => __( 'Past events', 'events-search-addon-for-the-events-calendar' ),
				'all'  => __( 'Past and upcoming', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * Labels for the date presets.
		 *
		 * @since 2.0.0
		 * @return array<string, string>
		 */
		private static function date_labels() {
			return array(
				'any'          => __( 'Any time', 'events-search-addon-for-the-events-calendar' ),
				'today'        => __( 'Today', 'events-search-addon-for-the-events-calendar' ),
				'tomorrow'     => __( 'Tomorrow', 'events-search-addon-for-the-events-calendar' ),
				'this_weekend' => __( 'This weekend', 'events-search-addon-for-the-events-calendar' ),
				'this_week'    => __( 'This week', 'events-search-addon-for-the-events-calendar' ),
				'next_week'    => __( 'Next week', 'events-search-addon-for-the-events-calendar' ),
				'this_month'   => __( 'This month', 'events-search-addon-for-the-events-calendar' ),
				'next_month'   => __( 'Next month', 'events-search-addon-for-the-events-calendar' ),
				// Not a preset row like the eight above (it reveals the two range
				// inputs), but it needs a label wherever a preset is named: the
				// panel's own row, the trigger's value, and the active-filter chip.
				self::DATE_CUSTOM => __( 'Custom range', 'events-search-addon-for-the-events-calendar' ),
			);
		}

		/**
		 * Build an HTML attribute string from a map, escaping every value.
		 *
		 * @since 2.0.0
		 * @param array<string, string> $attrs Attribute map.
		 * @return string Leading-space-prefixed attribute string.
		 */
		private static function attrs( array $attrs ) {
			$out = '';
			foreach ( $attrs as $key => $value ) {
				$out .= ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $value ) . '"';
			}
			return $out;
		}

		/**
		 * Reset per-request state. Test seam only.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function reset() {
			self::$counter           = 0;
			self::$url_owner_claimed = false;
		}
	}
}
