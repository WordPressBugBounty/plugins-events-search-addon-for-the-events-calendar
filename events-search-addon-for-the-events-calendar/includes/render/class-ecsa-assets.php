<?php
/**
 * Front-end asset registration for the v2 render path.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Render;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Assets' ) ) {

	/**
	 * Registers — never globally enqueues — the v2 front-end assets.
	 *
	 * Registration is inert; the actual enqueue happens in
	 * `ensure_v2_localized()` only when a bar or results region is really
	 * rendered, so a page with no search bar ships none of these bytes. The
	 * retired 2017-era jQuery/typeahead.js/Handlebars stack has been deleted
	 * outright.
	 *
	 * Nothing in this class runs at include time; `init()` is invoked by
	 * `Plugin::boot_render_path()`.
	 *
	 * @since 2.0.0
	 */
	final class Assets {

		/**
		 * Whether the v2 front-end config has already been localized this request.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static $v2_localized = false;

		/**
		 * Hook asset registration onto the front end.
		 *
		 * Registration only. Enqueueing is the render path's job — see
		 * `ensure_v2_localized()`.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ), 10 );
		}

		/**
		 * Register the v2 styles and scripts.
		 *
		 * Idempotent: safe to call again from `ensure_v2_localized()` when a bar
		 * renders on a request that never reached `wp_enqueue_scripts` (a
		 * REST-driven render, for instance). Guards on the `ecsa-frontend` handle
		 * so a second call is a no-op.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function register() {
			if ( wp_style_is( 'ecsa-frontend', 'registered' ) ) {
				return;
			}

			self::register_v2();
		}

		/**
		 * Register the v2 front-end style and scripts.
		 *
		 * The v2 runtime is vanilla JS with no jQuery, no typeahead.js and no
		 * Handlebars (the 2017-era libraries the rewrite retired). Registration
		 * only — nothing enqueues here. `ensure_v2_localized()` performs the
		 * enqueue at render time so a page with no bar ships none of it.
		 *
		 * `ecsa-url-state` is the URL codec that must stay byte-parity with the
		 * PHP `Url_State`; `ecsa-core` holds shared runtime helpers; `ecsa-frontend`
		 * is the bar/results controller and depends on both plus WordPress's
		 * `wp-a11y` (the singleton `wp.a11y.speak()` announcer) and `wp-i18n`.
		 *
		 * The style declares `rtl => replace` so `wp_enqueue_style()` swaps in
		 * `ecsa-frontend-rtl.css` on RTL locales.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function register_v2() {
			wp_register_style(
				'ecsa-frontend',
				ECSA_URL . 'assets/css/v2/ecsa-frontend.css',
				array(),
				ECSA_VERSION
			);
			wp_style_add_data( 'ecsa-frontend', 'rtl', 'replace' );

			wp_register_script(
				'ecsa-url-state',
				ECSA_URL . 'assets/js/v2/ecsa-url-state.js',
				array(),
				ECSA_VERSION,
				true
			);

			wp_register_script(
				'ecsa-core',
				ECSA_URL . 'assets/js/v2/ecsa-core.js',
				array(),
				ECSA_VERSION,
				true
			);

			wp_register_script(
				'ecsa-frontend',
				ECSA_URL . 'assets/js/v2/ecsa-frontend.js',
				array( 'ecsa-url-state', 'ecsa-core', 'wp-a11y', 'wp-i18n' ),
				ECSA_VERSION,
				true
			);
		}

		/**
		 * Enqueue and localize the v2 front-end assets — at render time, once.
		 *
		 * Called from the shortcode/widget render path only when a bar
		 * or results region is actually emitted, so a page without one ships none
		 * of these bytes. Guarded by a per-request static so two bars on one page
		 * localize `ECSA_CFG` exactly once.
		 *
		 * `restRoot` comes from `rest_url()`, never a hardcoded `/wp-json/`, so a
		 * site on plain permalinks (where the API lives at `?rest_route=/ecsa/v1/`)
		 * still resolves. `restNonce` is a logged-in convenience — it lets an
		 * authenticated request read draft/private context the same way the admin
		 * would — but the routes are public and never depend on it, so a cached
		 * page serving a stale nonce degrades to the anonymous view rather than
		 * breaking (the exact failure mode the legacy `ajax-nonce` suffered).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function ensure_v2_localized() {
			if ( true === self::$v2_localized ) {
				return;
			}

			self::$v2_localized = true;

			// Defensive: a render can be reached on a request that never fired
			// `wp_enqueue_scripts` (a REST-driven render), and enqueuing
			// an unregistered handle is a silent no-op.
			self::register();

			wp_enqueue_style( 'ecsa-frontend' );
			wp_enqueue_script( 'ecsa-frontend' );

			/*
			 * The nonce is emitted ONLY for logged-in users.
			 *
			 * The ecsa/v1 routes are public, so anonymous visitors need no nonce
			 * at all. Baking a `wp_rest` nonce into a page served to anonymous
			 * visitors is the retired v1.3.6 defect wearing new clothes: once a
			 * full-page cache serves that HTML past the ~24h nonce window, WP
			 * core's cookie check 403s every request BEFORE the public route runs
			 * — search breaks site-wide for logged-out visitors. Logged-in users
			 * still get a fresh nonce (their pages are rarely full-page cached),
			 * and the client drops even that on a 403 and retries without it.
			 */
			$rest_nonce = is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '';

			wp_localize_script(
				'ecsa-frontend',
				'ECSA_CFG',
				array(
					'restRoot'  => esc_url_raw( rest_url( 'ecsa/v1/' ) ),
					'restNonce' => $rest_nonce,
					'debug'     => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				)
			);

			// Every user-visible string in the v2 runtime goes through wp.i18n;
			// this points those calls at the shipped JSON translation files.
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations(
					'ecsa-frontend',
					'events-search-addon-for-the-events-calendar',
					ECSA_PATH . 'languages'
				);
			}
		}
	}
}
