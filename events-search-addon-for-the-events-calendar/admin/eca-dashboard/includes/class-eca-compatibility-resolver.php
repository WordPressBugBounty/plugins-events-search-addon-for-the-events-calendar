<?php
/**
 * Compatibility resolver — bridges un-updated sibling Events Addons onto the ECA shell.
 *
 * Lives inside the shared eca-dashboard module so every host that ships this copy
 * gets the same old-vs-new behaviour. Plugin booters (ECT_ECA_Integration, …)
 * should not duplicate this logic.
 *
 * Covers legacy patterns found across the Cool Plugins suite (master / pre-ECA):
 * - Shared cool_plugins_events_addons menu (dashboard vs dasboard typo; multi show_plugins)
 * - License: cool_plugins_events_registration_Settings_New + ECMDDiviLicenseManager
 * - CPT headers: *_display_header on all_admin_notices / admin_notices
 * - Settings chrome: inline dashboard-header.php + CSF / ectcsf_options_before injectors
 *
 * Loaded only from the winning eca-dashboard copy (Registry::load_includes), not via
 * early require in product booters — so the newest resolver always wins.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECA_Compatibility_Resolver' ) ) {

	/**
	 * Detects legacy chrome and replaces it with ECA_Dashboard_Page.
	 */
	final class ECA_Compatibility_Resolver {

		const DASHBOARD_PAGE_SLUG = 'cool-plugins-events-addon';
		const LICENSE_PAGE_SLUG   = 'cool-events-registration';

		/** @var bool */
		private static $booted = false;

		/** @var bool */
		private static $shell_header_rendered = false;

		/**
		 * Page slugs where this request is actively bridging legacy chrome.
		 * Hide/compact CSS must not run when a new product owns the page.
		 *
		 * @var array<string, true>
		 */
		private static $active_bridge_pages = array();

		/**
		 * CPT slugs where this request is actively bridging legacy chrome.
		 *
		 * @var array<string, true>
		 */
		private static $active_bridge_cpts = array();

		/**
		 * Whether license chrome is being bridged this request.
		 *
		 * @var bool
		 */
		private static $active_license_bridge = false;

		/**
		 * Whether the Divi-only license shell was opened and still needs closing.
		 *
		 * @var bool
		 */
		private static $divi_license_shell_open = false;

		/**
		 * Schedule boot after all product ECA integrations have registered.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 30 );
		}

		/**
		 * @return void
		 */
		public static function boot() {
			if ( self::$booted || ! is_admin() || ! class_exists( 'ECA_Dashboard_Page' ) ) {
				return;
			}
			self::$booted = true;

			self::maybe_bridge_license();
			self::maybe_register_cpt_bridges();
			self::maybe_register_settings_bridges();

			add_action( 'admin_menu', array( __CLASS__, 'dedupe_shared_admin_menus' ), 9999 );
			add_action( 'admin_menu', array( __CLASS__, 'strip_legacy_dashboard_page_callbacks' ), 9999 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_legacy_header_hide_styles' ), 100 );
		}

		/**
		 * Classes that already claim cool-events-registration via ECA.
		 *
		 * Default: any declared *ECA_Integration with register_license_admin_page(),
		 * plus known host names for copies that load after this boot.
		 *
		 * @return string[]
		 */
		private static function license_owner_classes() {
			$classes = array(
				'EWPE_ECA_Integration',
				'ESAS_ECA_Integration',
				'EPTA_ECA_Integration',
				'ESPBP_ECA_Integration',
				'ECT_Pro_ECA_Integration',
				'ECMD_ECA_Integration',
				'ECMD_Pro_ECA_Integration',
			);

			foreach ( get_declared_classes() as $class ) {
				if ( ! is_string( $class ) || ! preg_match( '/_ECA_Integration$/', $class ) ) {
					continue;
				}
				if ( method_exists( $class, 'register_license_admin_page' ) ) {
					$classes[] = $class;
				}
			}

			/**
			 * Filter which classes already claim cool-events-registration via ECA.
			 *
			 * @param string[] $classes Class names with register_license_admin_page().
			 */
			return array_values(
				array_unique(
					apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						'eca_compatibility_license_owner_classes',
						$classes
					)
				)
			);
		}

		/**
		 * CPT → product map for legacy header replacement.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function cpt_bridge_map() {
			$map = array(
				'ewpe'         => array(
					'owner_classes' => array( 'EWPE_ECA_Integration', 'ECTBE_ECA_Integration' ),
					'admin_url_key' => 'widgets',
					'admin_url'     => admin_url( 'edit.php?post_type=ewpe' ),
					'detect'        => array( 'EWPE_Regi_Post_Type' ),
				),
				'epta'         => array(
					'owner_classes' => array( 'EPTA_ECA_Integration', 'ESPBP_ECA_Integration' ),
					'admin_url_key' => 'spb',
					'admin_url'     => admin_url( 'edit.php?post_type=epta' ),
					'detect'        => array( 'ESPBP_Regi_Post_Type', 'EventPageTemplatesAddon' ),
				),
				'esas_speaker' => array(
					'owner_classes' => array( 'ESAS_ECA_Integration' ),
					'admin_url_key' => 'speakers',
					'admin_url'     => admin_url( 'edit.php?post_type=esas_speaker' ),
					'detect'        => array( 'ESAS_Regi_Post_Type' ),
				),
				'esas_sponsor' => array(
					'owner_classes' => array( 'ESAS_ECA_Integration' ),
					'admin_url_key' => 'speakers',
					'admin_url'     => admin_url( 'edit.php?post_type=esas_sponsor' ),
					'detect'        => array( 'ESAS_Regi_Post_Type' ),
				),
			);

			/**
			 * Filter CPT bridge definitions.
			 *
			 * @param array<string, array<string, mixed>> $map Post type => config.
			 */
			return apply_filters( 'eca_compatibility_cpt_bridges', $map ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		/**
		 * Admin page slugs that still print legacy dashboard-header.php inline
		 * or via CSF injectors.
		 *
		 * Modes:
		 * - `inline`  — paint ECA on notices + hide legacy .ect-top-header
		 * - `csf`     — replace CSF / ectcsf before/after header injectors
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function settings_bridge_map() {
			$map = array(
				'countdown_for_the_events_calendar'        => array(
					'owner_classes' => array( 'TECC_ECA_Integration', 'ECTC_ECA_Integration' ),
					'mode'          => 'inline',
					'detect'        => array( 'TECC_PLUGIN_DIR' ),
					'admin_url_key' => 'countdown',
				),
				'esas-speaker-sponsor-settings'            => array(
					'owner_classes' => array( 'ESAS_ECA_Integration' ),
					'mode'          => 'inline',
					'detect'        => array( 'ESAS_Regi_Post_Type' ),
					'admin_url_key' => 'speakers',
				),
				'tribe-events-shortcode-template-settings' => array(
					'owner_classes' => array( 'ECT_Pro_ECA_Integration' ),
					'mode'          => 'csf',
					'detect'        => array( 'ECT_PRO_PLUGIN_DIR' ),
				),
				'tribe_events-events-template-settings'    => array(
					// New free shortcodes already paints via ECTSettings + ECA — skip when host is new.
					'owner_classes' => array( 'ECT_ECA_Integration' ),
					'mode'          => 'csf',
					'detect'        => array( 'ECTSettings' ),
					'skip_if_owner' => true,
				),
			);

			/**
			 * Filter settings-page bridge definitions.
			 *
			 * @param array<string, array<string, mixed>> $map Page slug => config.
			 */
			return apply_filters( 'eca_compatibility_settings_bridges', $map ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		/**
		 * @param string[] $classes Class names.
		 * @return bool
		 */
		private static function any_class_exists( array $classes ) {
			foreach ( $classes as $class ) {
				if ( is_string( $class ) && '' !== $class && class_exists( $class ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @param string[] $tokens Class names and/or defined() constant names.
		 * @return bool
		 */
		private static function any_detect_token( array $tokens ) {
			foreach ( $tokens as $token ) {
				if ( ! is_string( $token ) || '' === $token ) {
					continue;
				}
				if ( class_exists( $token ) || defined( $token ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @return bool
		 */
		private static function license_owned_by_new_host() {
			foreach ( self::license_owner_classes() as $class ) {
				if ( class_exists( $class ) && method_exists( $class, 'register_license_admin_page' ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Shared Settings_New license UI (Widgets Pro, Speakers, SPB Pro, ECT Pro, …).
		 *
		 * @return bool
		 */
		private static function legacy_settings_new_license_present() {
			return class_exists( 'cool_plugins_events_registration_Settings_New' )
				&& ! self::license_owned_by_new_host();
		}

		/**
		 * Divi Pro-only license host when Settings_New is absent.
		 *
		 * @return bool
		 */
		private static function divi_only_license_present() {
			return class_exists( 'ECMDDiviLicenseManager' )
				&& ! class_exists( 'cool_plugins_events_registration_Settings_New' )
				&& ! self::license_owned_by_new_host();
		}

		/**
		 * @return void
		 */
		private static function maybe_bridge_license() {
			$settings_new = self::legacy_settings_new_license_present();
			$divi_only    = self::divi_only_license_present();

			if ( ! $settings_new && ! $divi_only ) {
				return;
			}

			self::$active_license_bridge = true;

			if ( class_exists( 'ECA_Dashboard_Registry' ) ) {
				ECA_Dashboard_Registry::register_addon(
					array(
						'slug'       => 'eca-compat-license-bridge',
						'admin_urls' => array(
							'license' => admin_url( 'admin.php?page=' . self::LICENSE_PAGE_SLUG ),
						),
					)
				);
			}

			add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_license_shell_styles' ) );

			if ( $settings_new ) {
				// Reclaim page and wrap the shared Settings_New form (new Widgets Pro pattern).
				add_action( 'admin_menu', array( __CLASS__, 'register_license_admin_page' ), 999 );
			} else {
				// Divi-only legacy (old Divi Pro + new free host): keep show_Page form,
				// paint ECA chrome around it via admin notices (shell stays open for the form).
				add_action( 'current_screen', array( __CLASS__, 'bridge_divi_license_chrome' ) );
			}
		}

		/**
		 * @return void
		 */
		private static function maybe_register_cpt_bridges() {
			$map            = self::cpt_bridge_map();
			$needs_screen   = false;
			$admin_url_bits = array();

			foreach ( $map as $post_type => $config ) {
				$owners = isset( $config['owner_classes'] ) && is_array( $config['owner_classes'] )
					? $config['owner_classes']
					: array();

				if ( self::any_class_exists( $owners ) ) {
					continue;
				}

				$detect = isset( $config['detect'] ) && is_array( $config['detect'] ) ? $config['detect'] : array();
				$legacy = post_type_exists( $post_type ) || self::any_detect_token( $detect );
				if ( ! $legacy ) {
					continue;
				}

				$needs_screen = true;
				self::$active_bridge_cpts[ sanitize_key( (string) $post_type ) ] = true;

				if ( ! empty( $config['admin_url_key'] ) && ! empty( $config['admin_url'] ) ) {
					$admin_url_bits[ sanitize_key( (string) $config['admin_url_key'] ) ] = (string) $config['admin_url'];
				}
			}

			if ( ! empty( $admin_url_bits ) && class_exists( 'ECA_Dashboard_Registry' ) ) {
				ECA_Dashboard_Registry::register_addon(
					array(
						'slug'       => 'eca-compat-cpt-bridge',
						'admin_urls' => $admin_url_bits,
					)
				);
			}

			if ( $needs_screen ) {
				add_action( 'current_screen', array( __CLASS__, 'bridge_legacy_cpt_header' ) );
			}
		}

		/**
		 * @return void
		 */
		private static function maybe_register_settings_bridges() {
			$map            = self::settings_bridge_map();
			$needs_screen   = false;
			$admin_url_bits = array();

			foreach ( $map as $page => $config ) {
				$owners = isset( $config['owner_classes'] ) && is_array( $config['owner_classes'] )
					? $config['owner_classes']
					: array();

				$skip_if_owner = ! isset( $config['skip_if_owner'] ) || $config['skip_if_owner'];
				if ( $skip_if_owner && self::any_class_exists( $owners ) ) {
					continue;
				}

				$detect = isset( $config['detect'] ) && is_array( $config['detect'] ) ? $config['detect'] : array();
				if ( ! empty( $detect ) && ! self::any_detect_token( $detect ) ) {
					continue;
				}

				$needs_screen = true;
				self::$active_bridge_pages[ sanitize_key( (string) $page ) ] = true;

				if ( ! empty( $config['admin_url_key'] ) ) {
					$admin_url_bits[ sanitize_key( (string) $config['admin_url_key'] ) ] = admin_url( 'admin.php?page=' . $page );
				}
			}

			if ( ! empty( $admin_url_bits ) && class_exists( 'ECA_Dashboard_Registry' ) ) {
				ECA_Dashboard_Registry::register_addon(
					array(
						'slug'       => 'eca-compat-settings-bridge',
						'admin_urls' => $admin_url_bits,
					)
				);
			}

			if ( $needs_screen ) {
				add_action( 'current_screen', array( __CLASS__, 'bridge_legacy_settings_chrome' ) );
			}
		}

		/**
		 * @return void
		 */
		public static function dedupe_shared_admin_menus() {
			global $menu, $submenu;

			$slug = self::DASHBOARD_PAGE_SLUG;

			if ( is_array( $menu ) ) {
				$seen_top = false;
				foreach ( $menu as $index => $item ) {
					if ( ! isset( $item[2] ) || $slug !== $item[2] ) {
						continue;
					}
					if ( $seen_top ) {
						unset( $menu[ $index ] );
					} else {
						$seen_top = true;
					}
				}
			}

			if ( ! isset( $submenu[ $slug ] ) || ! is_array( $submenu[ $slug ] ) ) {
				return;
			}

			$seen_slugs = array();
			foreach ( $submenu[ $slug ] as $index => $item ) {
				if ( ! isset( $item[2] ) ) {
					continue;
				}
				$key = (string) $item[2];
				if ( isset( $seen_slugs[ $key ] ) ) {
					unset( $submenu[ $slug ][ $index ] );
				} else {
					$seen_slugs[ $key ] = true;
				}
			}
		}

		/**
		 * @return void
		 */
		public static function strip_legacy_dashboard_page_callbacks() {
			if ( ! class_exists( 'cool_plugins_events_addons' ) ) {
				return;
			}

			$hooks = array( 'toplevel_page_' . self::DASHBOARD_PAGE_SLUG );
			if ( function_exists( 'get_plugin_page_hookname' ) ) {
				$hooks[] = get_plugin_page_hookname( self::DASHBOARD_PAGE_SLUG, '' );
			}

			foreach ( array_unique( array_filter( $hooks ) ) as $hook ) {
				self::remove_object_callbacks_matching( $hook, 'cool_plugins_events_addons', 'displayPluginAdminDashboard' );
			}
		}

		/**
		 * @param string $hook   Action name.
		 * @param string $class  Object class.
		 * @param string $method Method name.
		 * @return void
		 */
		private static function remove_object_callbacks_matching( $hook, $class, $method ) {
			global $wp_filter;

			if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
				return;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( empty( $callback['function'] ) || ! is_array( $callback['function'] ) ) {
						continue;
					}
					$obj = $callback['function'][0];
					$fn  = isset( $callback['function'][1] ) ? (string) $callback['function'][1] : '';
					if ( is_object( $obj ) && is_a( $obj, $class ) && $method === $fn ) {
						remove_action( $hook, $callback['function'], (int) $priority );
					}
				}
			}
		}

		/**
		 * @param string $classes Space-separated admin body classes.
		 * @return string
		 */
		public static function admin_body_class( $classes ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( in_array( $page, array( self::DASHBOARD_PAGE_SLUG, self::LICENSE_PAGE_SLUG ), true ) ) {
				$classes .= ' eca-admin-unified events-addons_page_cool-events-registration';
			}

			return $classes;
		}

		/**
		 * @return void
		 */
		public static function register_license_admin_page() {
			if ( ! current_user_can( 'manage_options' ) || ! self::legacy_settings_new_license_present() ) {
				return;
			}

			self::strip_license_submenu_entry();
			self::clear_duplicate_license_page_callbacks();

			add_submenu_page(
				null,
				ECA_Dashboard_I18n::__( 'License' ),
				ECA_Dashboard_I18n::__( 'License' ),
				'manage_options',
				self::LICENSE_PAGE_SLUG,
				array( __CLASS__, 'render_license_page' )
			);
		}

		/**
		 * @return void
		 */
		private static function clear_duplicate_license_page_callbacks() {
			$hooks = array( 'admin_page_' . self::LICENSE_PAGE_SLUG );
			if ( function_exists( 'get_plugin_page_hookname' ) ) {
				$hooks[] = get_plugin_page_hookname( self::LICENSE_PAGE_SLUG, self::DASHBOARD_PAGE_SLUG );
			}
			foreach ( array_unique( array_filter( $hooks ) ) as $hook ) {
				remove_all_actions( $hook );
			}
		}

		/**
		 * @return void
		 */
		private static function strip_license_submenu_entry() {
			remove_submenu_page( self::DASHBOARD_PAGE_SLUG, self::LICENSE_PAGE_SLUG );

			global $submenu;
			if ( ! isset( $submenu[ self::DASHBOARD_PAGE_SLUG ] ) || ! is_array( $submenu[ self::DASHBOARD_PAGE_SLUG ] ) ) {
				return;
			}

			foreach ( $submenu[ self::DASHBOARD_PAGE_SLUG ] as $index => $item ) {
				if ( isset( $item[2] ) && self::LICENSE_PAGE_SLUG === $item[2] ) {
					unset( $submenu[ self::DASHBOARD_PAGE_SLUG ][ $index ] );
				}
			}
		}

		/**
		 * @return void
		 */
		public static function render_license_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( ECA_Dashboard_I18n::esc_html__( 'You do not have permission to access this page.' ) );
			}

			// Prefer shared package when any Pro ships events-license-ui.
			if ( class_exists( 'Coolplugins_Events_License_Page' ) ) {
				Coolplugins_Events_License_Page::render();
				return;
			}

			// Legacy Pros (no package): keep rendering via Settings_New + License_Helper.
			// Needed when a newer free ECA bridge owns this callback at @999.
			if ( ! class_exists( 'cool_plugins_events_registration_Settings_New' ) ) {
				wp_die( ECA_Dashboard_I18n::esc_html__( 'License settings are unavailable.' ) );
			}

			$registration = \cool_plugins_events_registration_Settings_New::init();

			ECA_Dashboard_Page::render_admin_header( 'license' );

			if ( is_object( $registration ) && method_exists( $registration, 'show_navigation' ) ) {
				$registration->show_navigation();
			}

			if ( class_exists( 'ECA_Dashboard_Page' ) && method_exists( 'ECA_Dashboard_Page', 'render_license_notices' ) ) {
				ECA_Dashboard_Page::render_license_notices();
			}

			if ( is_object( $registration ) && ! empty( $registration->license_helper_obj )
				&& is_object( $registration->license_helper_obj )
				&& method_exists( $registration->license_helper_obj, 'show_modern_form' )
				&& method_exists( $registration, 'get_all_sections' ) ) {
				$registration->license_helper_obj->show_modern_form( $registration->get_all_sections() );
			}

			ECA_Dashboard_Page::render_admin_footer();
		}

		/**
		 * @param string $hook_suffix Current admin hook.
		 * @return void
		 */
		public static function enqueue_license_shell_styles( $hook_suffix = '' ) {
			if ( ! self::$active_license_bridge ) {
				return;
			}

			wp_add_inline_style(
				'common',
				'#toplevel_page_cool-plugins-events-addon .wp-submenu a[href*="page=cool-events-registration"]{display:none!important;}'
				. '#toplevel_page_cool-plugins-events-addon .wp-submenu li:has(> a[href*="page=cool-events-registration"]){display:none!important;}'
			);

			$hook = (string) $hook_suffix;
			if ( false !== strpos( $hook, self::LICENSE_PAGE_SLUG ) ) {
				ECA_Dashboard_Page::enqueue_shell_styles();
			}
		}

		/**
		 * Hide leftover legacy header chrome when ECA already painted the shell.
		 * Only runs for pages/CPTs this resolver is actively bridging.
		 *
		 * @param string $hook_suffix Current admin hook.
		 * @return void
		 */
		public static function enqueue_legacy_header_hide_styles( $hook_suffix = '' ) {
			unset( $hook_suffix );

			$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$cpt    = ( $screen && ! empty( $screen->post_type ) ) ? sanitize_key( (string) $screen->post_type ) : '';

			$bridging_page    = '' !== $page && isset( self::$active_bridge_pages[ $page ] );
			$bridging_cpt     = '' !== $cpt && isset( self::$active_bridge_cpts[ $cpt ] );
			$bridging_license = self::$active_license_bridge && self::LICENSE_PAGE_SLUG === $page;

			if ( ! $bridging_page && ! $bridging_cpt && ! $bridging_license ) {
				return;
			}

			$css = 'body.wp-admin header.ect-top-header{display:none!important;}';

			// Compact/hide empty .eca-admin-main only when settings content lives
			// *outside* the shell (inline countdown/ESAS). License form stays inside
			// <main> for both Settings_New reclaim and Divi-only chrome bridge.
			$compact_pages = array();
			if ( $bridging_page && in_array( $page, array( 'esas-speaker-sponsor-settings', 'countdown_for_the_events_calendar' ), true ) ) {
				$compact_pages[] = $page;
			}

			foreach ( array_unique( $compact_pages ) as $compact_page ) {
				$body = 'body.events-addons_page_' . $compact_page;
				$css .= $body . ' .eca-admin-page{min-height:0;}'
					. $body . ' .eca-admin-main{display:none;}';
			}

			wp_add_inline_style( 'common', $css );
		}

		/**
		 * @param WP_Screen $screen Current screen.
		 * @return void
		 */
		public static function bridge_divi_license_chrome( $screen ) {
			unset( $screen );
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( self::LICENSE_PAGE_SLUG !== $page || ! self::divi_only_license_present() ) {
				return;
			}

			add_action( 'admin_print_scripts', array( __CLASS__, 'rebind_inline_eca_header_after_notice_strip' ), PHP_INT_MAX );
		}

		/**
		 * @param WP_Screen $screen Current screen.
		 * @return void
		 */
		public static function bridge_legacy_cpt_header( $screen ) {
			if ( ! $screen || empty( $screen->post_type ) ) {
				return;
			}

			$post_type = sanitize_key( (string) $screen->post_type );
			if ( ! isset( self::$active_bridge_cpts[ $post_type ] ) ) {
				return;
			}

			$map = self::cpt_bridge_map();
			if ( ! isset( $map[ $post_type ] ) ) {
				return;
			}

			$config = $map[ $post_type ];
			$owners = isset( $config['owner_classes'] ) && is_array( $config['owner_classes'] )
				? $config['owner_classes']
				: array();

			if ( self::any_class_exists( $owners ) ) {
				return;
			}

			self::remove_legacy_display_header_callbacks();
			add_action( 'all_admin_notices', array( __CLASS__, 'render_cpt_eca_header' ), 1 );
			add_action( 'admin_notices', array( __CLASS__, 'render_cpt_eca_header' ), 1 );
			// Some products strip notices on admin_print_scripts even on CPT screens — rebind late.
			add_action( 'admin_print_scripts', array( __CLASS__, 'rebind_inline_eca_header_after_notice_strip' ), PHP_INT_MAX );
		}

		/**
		 * @param WP_Screen $screen Current screen.
		 * @return void
		 */
		public static function bridge_legacy_settings_chrome( $screen ) {
			unset( $screen );
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( '' === $page || ! isset( self::$active_bridge_pages[ $page ] ) ) {
				return;
			}

			$map = self::settings_bridge_map();
			if ( ! isset( $map[ $page ] ) ) {
				return;
			}

			$config = $map[ $page ];
			$owners = isset( $config['owner_classes'] ) && is_array( $config['owner_classes'] )
				? $config['owner_classes']
				: array();

			$skip_if_owner = ! isset( $config['skip_if_owner'] ) || $config['skip_if_owner'];
			if ( $skip_if_owner && self::any_class_exists( $owners ) ) {
				return;
			}

			$mode = isset( $config['mode'] ) ? (string) $config['mode'] : 'inline';

			if ( 'csf' === $mode ) {
				self::replace_csf_legacy_header_hooks();
				return;
			}

			// Inline dashboard-header.php (countdown, ESAS settings, …).
			// Sibling plugins strip all_admin_notices on these pages during admin_print_scripts —
			// re-bind the ECA header after that strip so chrome still paints.
			add_action( 'admin_print_scripts', array( __CLASS__, 'rebind_inline_eca_header_after_notice_strip' ), PHP_INT_MAX );
		}

		/**
		 * Re-attach ECA header after legacy plugins empty all_admin_notices / admin_notices.
		 *
		 * @return void
		 */
		public static function rebind_inline_eca_header_after_notice_strip() {
			add_action( 'all_admin_notices', array( __CLASS__, 'render_cpt_eca_header' ), 1 );
			add_action( 'admin_notices', array( __CLASS__, 'render_cpt_eca_header' ), 1 );
		}

		/**
		 * Swap CSF / ectcsf legacy header injectors for ECA shell open/close.
		 *
		 * @return void
		 */
		private static function replace_csf_legacy_header_hooks() {
			$before_hooks = array( 'ectcsf_options_before', 'csf_options_before' );
			$after_hooks  = array( 'ectcsf_options_after', 'csf_options_after' );

			foreach ( $before_hooks as $hook ) {
				self::remove_callbacks_matching_method_pattern( $hook, '/header|dashboard/i' );
				add_action( $hook, array( __CLASS__, 'render_csf_eca_header' ), 1 );
			}

			foreach ( $after_hooks as $hook ) {
				self::remove_callbacks_matching_method_pattern( $hook, '/footer|header|dashboard/i' );
				add_action( $hook, array( __CLASS__, 'render_csf_eca_footer' ), 999 );
			}
		}

		/**
		 * @param string $hook    Action name.
		 * @param string $pattern Regex against method name.
		 * @return void
		 */
		private static function remove_callbacks_matching_method_pattern( $hook, $pattern ) {
			global $wp_filter;

			if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
				return;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( empty( $callback['function'] ) || ! is_array( $callback['function'] ) ) {
						continue;
					}
					$method = isset( $callback['function'][1] ) ? (string) $callback['function'][1] : '';
					if ( '' === $method || ! preg_match( $pattern, $method ) ) {
						continue;
					}
					// Only strip Cool Plugins injectors — keep framework internals.
					if ( ! preg_match( '/inject|events_addon|addon_header|addon_footer|dashboard/i', $method ) ) {
						continue;
					}
					remove_action( $hook, $callback['function'], (int) $priority );
				}
			}
		}

		/**
		 * @return void
		 */
		private static function remove_legacy_display_header_callbacks() {
			foreach ( array( 'all_admin_notices', 'admin_notices' ) as $hook_name ) {
				global $wp_filter;

				if ( empty( $wp_filter[ $hook_name ] ) || ! ( $wp_filter[ $hook_name ] instanceof WP_Hook ) ) {
					continue;
				}

				foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $callback ) {
						if ( empty( $callback['function'] ) || ! is_array( $callback['function'] ) ) {
							continue;
						}
						$obj    = $callback['function'][0];
						$method = isset( $callback['function'][1] ) ? (string) $callback['function'][1] : '';
						if ( ! is_object( $obj ) || '' === $method ) {
							continue;
						}
						if ( ! preg_match( '/_display_header$/', $method ) && 'display_header' !== $method ) {
							continue;
						}
						remove_action( $hook_name, $callback['function'], (int) $priority );
					}
				}
			}
		}

		/**
		 * @return void
		 */
		public static function render_cpt_eca_header() {
			if ( self::$shell_header_rendered ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cpt    = ( $screen && ! empty( $screen->post_type ) ) ? sanitize_key( (string) $screen->post_type ) : '';

			$is_cpt      = '' !== $cpt && isset( self::$active_bridge_cpts[ $cpt ] );
			$is_settings = '' !== $page && isset( self::$active_bridge_pages[ $page ] );
			$is_license  = self::$active_license_bridge && self::LICENSE_PAGE_SLUG === $page && self::divi_only_license_present();

			if ( ! $is_cpt && ! $is_settings && ! $is_license ) {
				return;
			}

			self::$shell_header_rendered = true;

			if ( $is_license ) {
				// Leave <main> open — ECMDDiviLicenseManager::show_Page() prints the form next.
				// Works for any new ECA host (Shortcodes/Widgets/Divi free, Speakers, etc.)
				// paired with old Divi Pro; new license owners skip this path entirely.
				ECA_Dashboard_Page::render_admin_header( 'license' );
				// Paint notices here so old Divi show_Page() builds still get them after the
				// deferred license header (new show_Page() may also call this — guarded).
				if ( method_exists( 'ECA_Dashboard_Page', 'render_license_notices' ) ) {
					ECA_Dashboard_Page::render_license_notices();
				}
				self::$divi_license_shell_open = true;
				add_action( 'admin_footer', array( __CLASS__, 'close_divi_license_shell' ), 1 );
				return;
			}

			ECA_Dashboard_Page::render_admin_header( 'none' );
			ECA_Dashboard_Page::render_admin_footer();
		}

		/**
		 * Close the Divi-only license shell after show_Page() has printed the form.
		 *
		 * @return void
		 */
		public static function close_divi_license_shell() {
			if ( ! self::$divi_license_shell_open ) {
				return;
			}
			self::$divi_license_shell_open = false;
			ECA_Dashboard_Page::render_admin_footer();
		}

		/**
		 * @return void
		 */
		public static function render_csf_eca_header() {
			if ( self::$shell_header_rendered ) {
				return;
			}
			self::$shell_header_rendered = true;
			ECA_Dashboard_Page::render_admin_header( 'none' );
		}

		/**
		 * @return void
		 */
		public static function render_csf_eca_footer() {
			ECA_Dashboard_Page::render_admin_footer();
		}
	}
}
