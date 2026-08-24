<?php
/**
 * Boots the shared ECA dashboard module for this edition.
 *
 * Mirrors the finalized sibling integrations (the Events Widgets pair is the
 * reference): the vendored module in admin/eca-dashboard/ is the shared
 * source and the registry picks the newest submitted copy across all sibling
 * addons. The Pro host also owns the shared license screen
 * (cool-events-registration), rendered inside the ECA admin shell; the
 * settings screen wears the same shell in both editions.
 *
 * This class lives in the global namespace: it is `require_once`d directly by
 * the Plugin bootstrap, not autoloaded.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECSA_ECA_Integration' ) ) {

	/**
	 * Single entry point for ECA dashboard integration for this edition;
	 * the license surface exists only in the Pro host.
	 *
	 * @since 2.0.0
	 */
	final class ECSA_ECA_Integration {

		const DASHBOARD_PAGE_SLUG = 'cool-plugins-events-addon';
		const DASHBOARD_VERSION   = '1.0.0';

		/**
		 * Admin page slugs used by CPFM notices and notice-hiding rules.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function admin_page_slugs() {
			return array(
				self::DASHBOARD_PAGE_SLUG,
				self::settings_slug(),
			);
		}

		/**
		 * Resolve the settings-page slug through the plugin's single accessor.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		private static function settings_slug() {
			if ( class_exists( '\CoolPlugins\EventsSearch\Plugin' ) ) {
				return \CoolPlugins\EventsSearch\Plugin::settings_slug();
			}

			return 'ecsa-settings';
		}

		/**
		 * Load dashboard classes and register this addon as the Search host.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function boot_admin() {
			if ( ! defined( 'ECA_DASHBOARD_VERSION' ) ) {
				define( 'ECA_DASHBOARD_VERSION', self::DASHBOARD_VERSION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			}

			require_once ECSA_PATH . 'admin/eca-dashboard/includes/class-eca-addon-map.php';
			require_once ECSA_PATH . 'admin/eca-dashboard/includes/class-eca-dashboard-environment.php';
			require_once ECSA_PATH . 'admin/eca-dashboard/includes/class-eca-dashboard-registry.php';
			require_once ECSA_PATH . 'admin/eca-dashboard/includes/class-eca-dashboard-i18n.php';
			require_once ECSA_PATH . 'admin/eca-dashboard/includes/class-eca-dashboard-page.php';

			ECA_Dashboard_Registry::submit( ECA_DASHBOARD_VERSION, ECSA_PATH . 'admin/eca-dashboard/' );
			ECA_Dashboard_Registry::register_addon(
				array(
					'slug'          => 'search',
					'host_slug'     => 'search',
					'text_domain'   => 'events-search-addon-for-the-events-calendar',
					'dashboard_url' => ECSA_URL . 'admin/eca-dashboard/',
					'admin_urls'    => array(
						// Driver key -> More Addons "Open settings" deep-link.
						'search'    => admin_url( 'admin.php?page=' . self::settings_slug() ),
						'divi'      => admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE_SLUG ),
						'widgets'   => admin_url( 'edit.php?post_type=ewpe' ),
						'esb'       => admin_url( 'admin.php?page=tribe-events-shortcode-template-settings' ),
						'ect'       => admin_url( 'admin.php?page=tribe-events-shortcode-template-settings' ),
						'spb'       => admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE_SLUG ),
						'speakers'  => admin_url( 'admin.php?page=esas-speaker-sponsor-settings' ),
						'countdown' => admin_url( 'admin.php?page=countdown_for_the_events_calendar' ),
						'shortcode' => admin_url( 'admin.php?page=tribe-events-shortcode-template-settings' ),
					),
					'menu'          => array(
						'slug'     => self::DASHBOARD_PAGE_SLUG,
						// Titles translated in ECA_Dashboard_Page::register_menus() on admin_menu (WP 6.7+).
						'position' => 9,
					),
				)
			);

			add_action( 'plugins_loaded', array( 'ECA_Dashboard_Registry', 'boot' ), 20 );
			add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_settings_shell_styles' ) );
		}

		/**
		 * Unified ECA body classes on the shell-wrapped screens.
		 *
		 * @since 2.0.0
		 * @param string $classes Space-separated admin body classes.
		 * @return string
		 */
		public static function admin_body_class( $classes ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( in_array( $page, array( self::DASHBOARD_PAGE_SLUG, self::settings_slug() ), true ) ) {
				$classes .= ' eca-admin-unified';
			}

			// Settings only: the stylesheet keys the non-sticky header off this.
			if ( self::settings_slug() === $page ) {
				$classes .= ' ecsa-settings-screen';
			}

			return $classes;
		}

		/**
		 * Shell CSS on the settings screen, so the ECA header/footer wrap paints.
		 *
		 * @since 2.0.0
		 * @param string $hook_suffix Current admin hook.
		 * @return void
		 */
		public static function enqueue_settings_shell_styles( $hook_suffix = '' ) {
			if ( false !== strpos( (string) $hook_suffix, self::settings_slug() ) && class_exists( 'ECA_Dashboard_Page' ) ) {
				ECA_Dashboard_Page::enqueue_shell_styles();
			}
		}
	}
}
