<?php
/**
 * Admin notices.
 *
 * Replaces v1.3.6's `ect_hide_unrelated_notices()`, which walked the raw
 * `$wp_filter` global and unset every other plugin's `admin_notices`
 * callbacks on a hard-coded list of "events pages". That blanket mutation is
 * retired and must not return in any form — this class never touches
 * `$wp_filter`. Only the cross-plugin notice bridge survives from it.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Notices' ) ) {

	/**
	 * Owns every notice this plugin prints, plus the shared Events Addons
	 * notice bridge.
	 *
	 * Nothing here runs at include time; `init()` is invoked by the bootstrap
	 * from within `is_admin()`.
	 *
	 * @since 2.0.0
	 */
	final class Notices {

		/**
		 * Settings page menu slug, used to scope the missing-TEC notice.
		 *
		 * Duplicated from `Admin\Settings\Settings_Page::MENU_SLUG` as a
		 * fallback only — the constant is preferred when that class is
		 * loadable, so a partial deploy cannot fatal here.
		 */
		const FALLBACK_MENU_SLUG = 'ecsa-settings';

		/**
		 * Shared Events Addons dashboard menu slug (cross-plugin, byte-exact).
		 */
		const DASHBOARD_MENU_SLUG = 'cool-plugins-events-addon';

		/**
		 * The Events Calendar's wp.org slug.
		 */
		const TEC_SLUG = 'the-events-calendar';

		/**
		 * Register hooks.
		 *
		 * `admin_print_scripts` is the v1.3.6 timing for claiming the shared
		 * lock; it is preserved so sibling addons that still race for it keep
		 * their existing ordering.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_print_scripts', array( __CLASS__, 'hook_shared_notices' ), 10 );
			add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_tec_notice' ), 10 );
		}

		/**
		 * Admin pages that render the shared notice bridge themselves.
		 *
		 * Several sibling addons call a bare `do_action( 'ect_display_admin_notices' )`
		 * inline in their own dashboard markup, unguarded by
		 * `ECT_ADMIN_NOTICE_RENDERED`. On those screens the bridge must NOT be
		 * claimed here, or the notices render twice — once from our
		 * PHP_INT_MAX `admin_notices` callback and again from the page body.
		 *
		 * MEMBERSHIP TEST: a page belongs here only if something on it fires the
		 * bridge INLINE. It is not "every events-related screen".
		 *
		 * `ecsa-settings` IS listed because its page body renders the bridge
		 * INLINE. Without this entry the bridge fires TWICE on that screen —
		 * once from `render_shared_notices()` at `admin_notices` PHP_INT_MAX and
		 * again from the page body. Sibling handlers carry no once-guard of their
		 * own, so every sibling's notice
		 * printed twice. That is precisely the double-render this constant exists
		 * to prevent.
		 *
		 * Listing the page does NOT suppress our review notice: that is rendered
		 * by a direct `CPFM_Review_Notice::cpfm_maybe_render( false )` call and never
		 * travels through this bridge.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		const SIBLING_NOTICE_PAGES = array(
			'ecsa-settings',
			'cool-plugins-events-addon',
			'cool-events-registration',
			'tribe-events-shortcode-template-settings',
			'tribe_events-events-template-settings',
			'countdown_for_the_events_calendar',
			'esas-speaker-sponsor-settings',
			'esas_speaker',
			'esas_sponsor',
			'ewpe',
			'epta',
		);

		/**
		 * Post types whose screens belong to sibling addons.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		const SIBLING_POST_TYPES = array( 'esas_speaker', 'esas_sponsor', 'epta', 'ewpe' );

		/**
		 * Claim the ecosystem-wide notice bridge if no sibling claimed it yet.
		 *
		 * The `ECT_ADMIN_NOTICE_*` constants and the `ect_display_admin_notices`
		 * action are shared by ALL Cool Plugins Events Addons. Renaming or
		 * re-prefixing any of them makes every sibling render its notices a
		 * second time, so they stay exactly as-is.
		 *
		 * The screen gate is equally part of the contract. v1.3.6 and every
		 * live sibling claim this lock ONLY when the current screen is not an
		 * events/addon page, precisely because those pages fire the bridge
		 * themselves. Claiming it unconditionally would double-render every
		 * sibling's licence errors, review prompts and cross-sell notices on
		 * their own settings screens — a regression invisible on a site where
		 * this plugin is the only addon installed.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function hook_shared_notices() {
			if ( defined( 'ECT_ADMIN_NOTICE_HOOKED' ) ) {
				return;
			}

			if ( self::is_sibling_owned_screen() ) {
				return;
			}

			define( 'ECT_ADMIN_NOTICE_HOOKED', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared ecosystem lock, cross-plugin contract.

			add_action( 'admin_notices', array( __CLASS__, 'render_shared_notices' ), PHP_INT_MAX );
		}

		/**
		 * Whether the current screen belongs to an events addon that renders
		 * the shared notice bridge inline itself.
		 *
		 * @since 2.0.0
		 * @return bool True when this screen must not claim the bridge.
		 */
		private static function is_sibling_owned_screen() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection, no data is processed.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

			if ( '' !== $page && in_array( $page, self::SIBLING_NOTICE_PAGES, true ) ) {
				return true;
			}

			if ( ! function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();

			return ( $screen instanceof \WP_Screen
				&& ! empty( $screen->post_type )
				&& in_array( $screen->post_type, self::SIBLING_POST_TYPES, true ) );
		}

		/**
		 * Fire the shared notice action exactly once per request.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function render_shared_notices() {
			/*
			 * ONE SLOT PER REQUEST, UNDER EITHER LOCK. The bridge now has two
			 * legitimate owners: this method (the historical `ECT_` lock) and
			 * the shared ECA shell header, which fires the same action behind
			 * its own `ECA_ADMIN_NOTICES_RENDERED`. Two locks that cannot see
			 * each other are not a lock: with the shell wrapped around our
			 * settings screen the header fired first, this call fired again,
			 * and every sibling's notice printed twice — sibling handlers do
			 * not deduplicate.
			 *
			 * So the guard reads BOTH names and sets BOTH. Whichever slot
			 * renders first owns the request; the other stands down.
			 */
			if ( defined( 'ECT_ADMIN_NOTICE_RENDERED' ) || defined( 'ECA_ADMIN_NOTICES_RENDERED' ) ) {
				return;
			}

			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared ecosystem locks, cross-plugin contract.
			define( 'ECT_ADMIN_NOTICE_RENDERED', true );
			define( 'ECA_ADMIN_NOTICES_RENDERED', true );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

			do_action( 'ect_display_admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- shared ecosystem action consumed by sibling addons.
		}

		/**
		 * Render the "The Events Calendar is missing" notice.
		 *
		 * This is the admin half of the plan's no-TEC state: the front end
		 * renders nothing at all, and the admin gets exactly one notice — shown
		 * only on this plugin's own screens and the plugins list, never
		 * site-wide, and only to users who could act on it.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function maybe_render_missing_tec_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( ! self::is_own_screen() ) {
				return;
			}

			// Guarded so a partially deployed tree degrades to silence rather
			// than fatalling on a class that has not shipped yet.
			if ( ! class_exists( '\CoolPlugins\EventsSearch\Tec\Tec' ) ) {
				return;
			}

			if ( \CoolPlugins\EventsSearch\Tec\Tec::available() ) {
				return;
			}

			$install_url = add_query_arg(
				array(
					'tab'       => 'plugin-information',
					'plugin'    => self::TEC_SLUG,
					'TB_iframe' => 'true',
				),
				admin_url( 'plugin-install.php' )
			);

			$min_version = defined( 'ECSA_MIN_TEC_VERSION' ) ? ECSA_MIN_TEC_VERSION : '';

			echo '<div class="notice notice-warning ecsa-notice ecsa-notice-missing-tec"><p>';

			if ( '' !== $min_version ) {
				printf(
					/* translators: 1: minimum required The Events Calendar version number, 2: opening anchor tag linking to the plugin installer, 3: closing anchor tag. */
					esc_html__( 'Events Search &amp; Filterbar for The Events Calendar needs The Events Calendar %1$s or later to be installed and active. Nothing is displayed on your site until then. %2$sInstall The Events Calendar%3$s', 'events-search-addon-for-the-events-calendar' ),
					esc_html( $min_version ),
					'<a href="' . esc_url( $install_url ) . '">',
					'</a>'
				);
			} else {
				printf(
					/* translators: 1: opening anchor tag linking to the plugin installer, 2: closing anchor tag. */
					esc_html__( 'Events Search &amp; Filterbar for The Events Calendar needs The Events Calendar to be installed and active. Nothing is displayed on your site until then. %1$sInstall The Events Calendar%2$s', 'events-search-addon-for-the-events-calendar' ),
					'<a href="' . esc_url( $install_url ) . '">',
					'</a>'
				);
			}

			echo '</p></div>';
		}

		/**
		 * Whether the current admin screen is one this plugin owns.
		 *
		 * Matches the plugins list plus this plugin's settings screen and the
		 * shared Events Addons dashboard. Submenu screen IDs are built from the
		 * *parent menu title*, which siblings can change, so the menu slug is
		 * matched as a suffix rather than compared against a guessed full ID.
		 *
		 * @since 2.0.0
		 * @return bool True when the notice may be shown on this screen.
		 */
		private static function is_own_screen() {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();

			if ( ! $screen instanceof \WP_Screen ) {
				return false;
			}

			if ( 'plugins' === $screen->id || 'plugins-network' === $screen->id ) {
				return true;
			}

			$menu_slug = self::FALLBACK_MENU_SLUG;
			if ( class_exists( '\CoolPlugins\EventsSearch\Admin\Settings\Settings_Page' ) && defined( '\CoolPlugins\EventsSearch\Admin\Settings\Settings_Page::MENU_SLUG' ) ) {
				$menu_slug = \CoolPlugins\EventsSearch\Admin\Settings\Settings_Page::MENU_SLUG;
			}

			$slugs = array( $menu_slug, self::DASHBOARD_MENU_SLUG );

			foreach ( $slugs as $slug ) {
				if ( self::screen_id_matches_slug( (string) $screen->id, $slug ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether an admin screen ID belongs to a given menu slug.
		 *
		 * WordPress builds admin page screen IDs as `{sanitized-parent}_page_{slug}`
		 * (or `toplevel_page_{slug}`), so a suffix test is both sufficient and
		 * immune to parent-title changes.
		 *
		 * @since 2.0.0
		 * @param string $screen_id Current screen ID.
		 * @param string $slug      Menu slug to test for.
		 * @return bool True when the screen ID resolves to that menu slug.
		 */
		private static function screen_id_matches_slug( $screen_id, $slug ) {
			if ( '' === $slug || '' === $screen_id ) {
				return false;
			}

			$needle = '_page_' . $slug;
			$length = strlen( $needle );

			return ( strlen( $screen_id ) >= $length && substr( $screen_id, -$length ) === $needle );
		}
	}
}
