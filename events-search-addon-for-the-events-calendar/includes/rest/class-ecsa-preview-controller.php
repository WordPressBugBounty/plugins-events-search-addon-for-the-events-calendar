<?php
/**
 * Admin-only settings preview route.
 *
 * The settings panel used to hand-build a *sketch* of the bar in JavaScript.
 * That sketch could only ever approximate the real markup, so it drifted the
 * moment the renderer changed — the panel showed a summary-less `<details>`,
 * a fabricated Location facet, four of eight date presets, pills without their
 * counts, and triggers missing their value segment. Every one of those was a
 * design that "did not work in the live preview".
 *
 * This route removes that whole class of bug: the preview is rendered by the
 * REAL `Renderer`, so it is markup-identical to the front end by construction
 * and stays that way for free whenever the renderer changes.
 *
 * It is CHEAP and SAFE despite rendering the real component:
 * - `Renderer::bar()` and `Renderer::results()` both take their data as
 *   parameters, so the handler injects a fixed sample set and runs NO event
 *   query, no engine and no facet count SQL.
 * - The route is admin-only (`manage_options`) plus the standard REST nonce,
 *   and it only ever renders; it never writes.
 * - The config bag is funnelled through `Instance::from_atts()`, the same
 *   allowlist the shortcode and blocks use, so an unknown or hostile key can
 *   no more reach the renderer here than it can from a shortcode.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.1.0
 */

namespace CoolPlugins\EventsSearch\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Preview_Controller' ) ) {

	/**
	 * Renders the settings-panel preview through the real Renderer.
	 *
	 * @since 2.1.0
	 */
	final class Preview_Controller {

		/**
		 * Route namespace, shared with the public controller.
		 */
		const REST_NAMESPACE = 'ecsa/v1';

		/**
		 * The instance id every preview render uses. Fixed (not unique) because
		 * the preview is a single, transient node that is replaced wholesale.
		 */
		const INSTANCE_ID = 'ecsa-preview';

		/**
		 * Hook the route.
		 *
		 * @since 2.1.0
		 * @return void
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Register `POST /preview`.
		 *
		 * Skipped when the renderer is not loadable, so a partial deploy 404s
		 * rather than fatals.
		 *
		 * @since 2.1.0
		 * @return void
		 */
		public static function register_routes() {
			if ( ! class_exists( 'CoolPlugins\EventsSearch\Render\Renderer' ) ) {
				return;
			}

			register_rest_route(
				self::REST_NAMESPACE,
				'/preview',
				array(
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'render' ),
						'permission_callback' => array( __CLASS__, 'can_preview' ),
						'args'                => array(
							'config'          => array(
								'required' => true,
								'type'     => 'object',
							),
							/*
							 * WHETHER THE PREVIEW SHOWS A RESULTS BLOCK — stated,
							 * never inferred.
							 *
							 * It used to be derived from `config.role`: the panel
							 * sent `bar-results` when the placement was "below the
							 * bar" and the handler read that back. With the bar/
							 * results split there is no combined role left to
							 * carry the intent, and the panel legitimately wants
							 * to show BOTH halves for a placement that emits two
							 * separate shortcodes — something no single role can
							 * express.
							 *
							 * Its own boolean, so the panel says what it wants
							 * instead of encoding it in a value that means
							 * something else. And because it is a declared,
							 * REQUIRED parameter, a panel that fails to send it
							 * gets a 400 naming the missing field — where the old
							 * derivation just silently rendered no results and
							 * left the admin to guess whether that was the setting
							 * or the bug.
							 */
							'preview_results' => array(
								'required' => true,
								'type'     => 'boolean',
							),
						),
					),
				)
			);
		}

		/**
		 * Only a settings-capable user may render a preview.
		 *
		 * @since 2.1.0
		 * @return bool
		 */
		public static function can_preview() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Render the bar (and, when the placement shows one, the results block)
		 * for an UNSAVED config.
		 *
		 * @since 2.1.0
		 * @param \WP_REST_Request $request Incoming request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public static function render( $request ) {
			$raw = $request->get_param( 'config' );
			$raw = is_array( $raw ) ? $raw : array();

			if ( ! class_exists( 'CoolPlugins\EventsSearch\Render\Instance' ) ) {
				return new \WP_Error( 'ecsa_preview_unavailable', __( 'Preview is unavailable.', 'events-search-addon-for-the-events-calendar' ), array( 'status' => 503 ) );
			}

			// The SAME allowlist the shortcode and the blocks go through, so the
			// preview can never render a config the front end could not.
			$config = \CoolPlugins\EventsSearch\Render\Instance::from_atts( $raw, array() );

			/*
			 * The panel's explicit "show the results half too" answer. A real
			 * parameter rather than something read back off the role, because the
			 * placement the panel is previewing — a bar plus a SEPARATE results
			 * block, sharing a `target` — is two instances on the page, and the
			 * preview draws them as one picture. No single role describes that.
			 *
			 * `rest_sanitize_boolean` has already coerced it (the arg declares
			 * `type => boolean`, and it is required, so an absent value 400s rather
			 * than defaulting silently).
			 */
			$preview_results = (bool) $request->get_param( 'preview_results' );

			// Render the bar and the sort control as <div>s rather than <form>s.
			// The preview is injected inside the settings screen's own <form>,
			// and HTML forbids nested forms: the parser drops the inner <form>
			// tag but keeps its children, which silently removed `.ecsa-bar`
			// itself — taking the whole bar frame with it. The preview is inert
			// and aria-hidden, so it never needed to be submittable.
			$config['preview_mode'] = true;

			$criteria = self::sample_criteria();

			$renderer = 'CoolPlugins\EventsSearch\Render\Renderer';
			$design   = $renderer::design_attrs( $config );

			$html = $renderer::bar( self::INSTANCE_ID, $config, $criteria );

			/*
			 * Results ride along only when the panel ASKED for them.
			 * `Renderer::results()` is called directly — exactly as
			 * `Renderer::bar()` is above — so the preview can draw a bar and a
			 * detached results region as the single picture the admin is
			 * configuring, without any role having to mean "both".
			 *
			 * NOTE: a second condition excluded `results_mode = page`, whose
			 * results are by definition not under this bar. There is no page
			 * placement, so the panel's request is the whole test.
			 */
			$shows_results = $preview_results;

			if ( $shows_results ) {
				$html .= $renderer::results( self::INSTANCE_ID, $config, $criteria, self::sample_result( $config ) );
			}

			return rest_ensure_response(
				array(
					'class'   => $design['class'],
					'style'   => $design['style'],
					'html'    => $html,
					/*
					 * Echoed back so the panel can TELL whether the results half is
					 * missing because it was not asked for, or missing because
					 * something went wrong. The old derivation had no such signal:
					 * it degraded silently, and a preview that quietly stops drawing
					 * half of itself is indistinguishable from a setting that turned
					 * that half off.
					 */
					'results' => $shows_results,
				)
			);
		}

		/**
		 * A neutral criteria set: nothing selected, first page.
		 *
		 * @since 2.1.0
		 * @return array
		 */
		private static function sample_criteria() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Query\Criteria' ) ) {
				return \CoolPlugins\EventsSearch\Query\Criteria::normalize( array() );
			}

			return array(
				'q'           => '',
				'date_preset' => 'any',
				'page'        => 1,
				'sort'        => 'date_asc',
				'time'        => 'upcoming',
			);
		}

		/*
		 * NOTE: `sample_facets()` lived here, injecting illustrative category /
		 * tag / venue / organizer option lists (and a scale-keyed sample of
		 * cities, regions and countries for the in-bar location field) so the
		 * panel could preview every filter STYLE on a brand-new site without ever
		 * paying for facet-count SQL. None of those filters is part of this
		 * plugin, and `Renderer::bar()` no longer takes a facet bag, so the
		 * zero-query promise in DESIGN-HANDOFF-PLAN §0b now holds trivially: the
		 * only filter the preview draws is the date one, whose presets are a fixed
		 * translated vocabulary rather than data.
		 */

		/**
		 * Illustrative result items, honouring the configured page size so the
		 * grid/column/card controls preview against a realistic number of cards.
		 *
		 * @since 2.1.0
		 * @param array $config Normalized config.
		 * @return array
		 */
		private static function sample_result( array $config ) {
			$titles = array(
				__( 'Summer Jazz Night', 'events-search-addon-for-the-events-calendar' ),
				__( 'Beginner Pottery Workshop', 'events-search-addon-for-the-events-calendar' ),
				__( 'City Marathon', 'events-search-addon-for-the-events-calendar' ),
				__( 'Open-Air Film Screening', 'events-search-addon-for-the-events-calendar' ),
				__( 'Farmers Market', 'events-search-addon-for-the-events-calendar' ),
				__( 'Community Book Club', 'events-search-addon-for-the-events-calendar' ),
			);
			$venues = array(
				__( 'Main Hall', 'events-search-addon-for-the-events-calendar' ),
				__( 'Studio B', 'events-search-addon-for-the-events-calendar' ),
				__( 'The Park', 'events-search-addon-for-the-events-calendar' ),
			);
			$costs  = array( '', '$15', __( 'Free', 'events-search-addon-for-the-events-calendar' ) );

			$per_page = isset( $config['per_page'] ) ? (int) $config['per_page'] : 6;
			$count    = max( 1, min( count( $titles ), $per_page ) );

			/*
			 * THE SAMPLE IMAGE, AND WHY IT IS NOT ''.
			 *
			 * This was the empty string, and that single value hid TWO settings at
			 * once. `Renderer::card()` gates the `<img>` on
			 * `in_array( 'image', $card_fields ) && '' !== $thumbnail`, so no sample
			 * item could ever draw one — ticking "Image" in Items to show changed
			 * nothing. And because the Modern date badge is drawn ON the image (it
			 * is absolutely positioned against the link, whose top-left corner IS
			 * the image's), the same empty string ALSO suppressed the badge — so
			 * Clean and Modern previewed byte-identically and the Card design
			 * control looked dead too.
			 *
			 * Giving the samples a thumbnail fixes both through the renderer's OWN
			 * path: no preview-only branch exists, and none is wanted — the whole
			 * point of this route is that the preview cannot drift from the front
			 * end. Untick "Image" and the renderer drops the `<img>` and the badge
			 * with it, exactly as it does on a real card with no featured image.
			 */
			$thumbnail = self::sample_thumbnail();

			// Site-local "today" (never bare date()/strtotime()).
			$now = new \DateTimeImmutable( 'now', wp_timezone() );

			$items = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$start = $now->modify( '+' . ( ( $i + 1 ) * 3 ) . ' days' )->setTime( 19, 0 );
				// Every third sample runs over two extra days, so the Date format
				// setting can actually be judged: a multi-day event reads as a
				// RANGE, and a preview that only ever showed single days would hide
				// the half of the setting most likely to look wrong.
				$end = ( 0 === $i % 3 ) ? $start->modify( '+2 days' )->setTime( 22, 0 ) : $start->setTime( 22, 0 );

				$items[] = array(
					'event_id'  => 9000 + $i,
					'title'     => $titles[ $i % count( $titles ) ],
					'url'       => '#',
					'start'     => $start->format( 'Y-m-d H:i:s' ),
					'end'       => $end->format( 'Y-m-d H:i:s' ),
					'all_day'   => false,
					'venue'     => $venues[ $i % count( $venues ) ],
					'thumbnail' => $thumbnail,
					'cost'      => $costs[ $i % count( $costs ) ],
				);
			}

			return array(
				'items'    => $items,
				'total'    => count( $items ),
				'has_more' => false,
			);
		}

		/**
		 * The sample card image: a plugin-local, obviously-illustrative SVG.
		 *
		 * SELF-CONTAINED BY CONSTRUCTION — a file inside the plugin, so the preview
		 * makes no outbound request, needs no placeholder service and works on an
		 * air-gapped install.
		 *
		 * NOT A `data:` URI, and that is not a preference. `Renderer::card()` emits
		 * the thumbnail through `esc_url()`, whose protocol allowlist is
		 * `wp_allowed_protocols()` — `data` is not in it, so esc_url() returns ''
		 * for a data URI whether raw or base64 (and its character filter would have
		 * shredded an un-encoded SVG first). An inline placeholder would therefore
		 * have rendered `src=""`, and the only way to make one work is to widen the
		 * renderer's escaping for every caller, including the public
		 * `Renderer::results()`. A local file costs nothing and keeps that boundary
		 * exactly where it is.
		 *
		 * Guarded on the constant so a partial deploy degrades to the pre-existing
		 * "no image" preview rather than emitting a broken relative URL.
		 *
		 * @since 2.6.0
		 * @return string Absolute URL, or '' when the plugin URL is unknown.
		 */
		private static function sample_thumbnail() {
			return defined( 'ECSA_URL' ) ? ECSA_URL . 'assets/images/ecsa-preview-card.svg' : '';
		}
	}
}
