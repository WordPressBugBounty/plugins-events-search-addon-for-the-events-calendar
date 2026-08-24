<?php
/**
 * Admin page controller for the shared ECA dashboard.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECA_Dashboard_Page' ) ) {

	/**
	 * Registers menus and enqueues dashboard assets.
	 */
	final class ECA_Dashboard_Page {

		const PAGE_SLUG = 'cool-plugins-events-addon';

		/** @var array<int, array<string, mixed>> */
		private static $registered_addons = array();

		/** @var string */
		private static $host_slug = 'esb';

		/**
		 * Which addon is hosting this screen.
		 *
		 * Exposed so shared views can attribute their outbound links to the
		 * plugin the user is actually looking at, instead of guessing from a
		 * fixed list. `views/admin-header.php` is the caller: without this it
		 * walked a fixed order and `divi` always won, so every sibling's Docs
		 * and Support links reported themselves as the Divi addon.
		 *
		 * @return string
		 */
		public static function host_slug() {
			return self::$host_slug;
		}

		/** @var bool */
		private static $admin_header_rendered = false;

		/** @var bool */
		private static $admin_shell_open = false;

		/**
		 * @param array<int, array<string, mixed>> $addons Registered addon configs.
		 */
		public static function register_menus( $addons ) {
			self::$registered_addons = $addons;

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$menu = array(
				'slug'     => self::PAGE_SLUG,
				'position' => 9,
			);

			foreach ( $addons as $addon ) {
				if ( ! empty( $addon['menu'] ) && is_array( $addon['menu'] ) ) {
					$menu = array_merge( $menu, $addon['menu'] );
				}
				if ( ! empty( $addon['host_slug'] ) ) {
					self::$host_slug = sanitize_key( $addon['host_slug'] );
				}
			}

			// Translate on admin_menu (after init) -- never in early boot_admin().
			$menu['page_title'] = ECA_Dashboard_I18n::__( 'Events Addons' );
			$menu['menu_title'] = ECA_Dashboard_I18n::__( 'Events Addons' );

			$position = isset( $menu['position'] ) ? (int) $menu['position'] : 9;

			add_menu_page(
				$menu['page_title'],
				$menu['menu_title'],
				'manage_options',
				$menu['slug'],
				array( __CLASS__, 'render' ),
				'dashicons-calendar-alt',
				$position
			);

			// Ensure first submenu is labeled "Dashboard" (WP defaults to parent title).
			add_submenu_page(
				$menu['slug'],
				ECA_Dashboard_I18n::__( 'Dashboard' ),
				ECA_Dashboard_I18n::__( 'Dashboard' ),
				'manage_options',
				$menu['slug'],
				array( __CLASS__, 'render' )
			);
		}

		/**
		 * Render dashboard shell.
		 */
		public static function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( ECA_Dashboard_I18n::esc_html__( 'You do not have permission to access this page.' ) );
			}

			$view = ECA_Dashboard_Registry::get_winner_path() . 'views/dashboard-shell.php';
			if ( file_exists( $view ) ) {
				include $view;
			}
		}

		/**
		 * Admin pages that reuse the ECA header chrome (not the full dashboard app).
		 * Keep this list identical across every plugin that ships eca-dashboard --
		 * only the first-loaded ECA_Dashboard_Page class wins (class_exists guard).
		 *
		 * @return string[]
		 */
		private static function shell_page_hooks() {
			return array(
				'cool-events-registration',
				'tribe-events-shortcode-template-settings',
				'tribe_events-events-template-settings',
				'esas-speaker-sponsor-settings',
				'countdown_for_the_events_calendar',
				'edit-epta',
				'edit-ewpe',
				'edit-esas_speaker',
				'edit-esas_sponsor',
			);
		}

		/**
		 * CPT screens that reuse the ECA header chrome (edit/new/list).
		 *
		 * @return string[]
		 */
		private static function shell_post_types() {
			return array( 'epta', 'ewpe', 'esas_speaker', 'esas_sponsor' );
		}

		/**
		 * @param string $hook_suffix Current admin page hook.
		 */
		public static function enqueue( $hook_suffix ) {
			$hook = (string) $hook_suffix;
			foreach ( self::shell_page_hooks() as $shell_hook ) {
				if ( false !== strpos( $hook, $shell_hook ) ) {
					self::enqueue_shell_styles();
					return;
				}
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && ! empty( $screen->post_type ) && in_array( $screen->post_type, self::shell_post_types(), true ) ) {
				self::enqueue_shell_styles();
				return;
			}

			if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
				return;
			}

			$base = ECA_Dashboard_Registry::get_winner_path();
			$ver  = ECA_Dashboard_Registry::get_winner_version();
			if ( ! $base || ! $ver ) {
				return;
			}

			$assets = ECA_Dashboard_Registry::asset_base_url() . 'assets/';
			$css    = $assets . 'css/';
			$js     = $assets . 'js/';

			wp_enqueue_style( 'dashicons' );
			wp_enqueue_style( 'eca-dashboard-base', $css . 'eca-base.css', array(), $ver );
			wp_enqueue_style( 'eca-dashboard', $css . 'eca-dashboard.css', array( 'eca-dashboard-base' ), $ver );

			wp_enqueue_script( 'eca-dashboard-env', $js . 'eca-dashboard-env.js', array(), $ver, true );
			wp_enqueue_script( 'eca-dashboard-resolver', $js . 'eca-dashboard-resolver.js', array(), $ver, true );
			wp_enqueue_script( 'eca-dashboard-render', $js . 'eca-dashboard-render.js', array(), $ver, true );
			wp_enqueue_script(
				'eca-dashboard',
				$js . 'eca-dashboard.js',
				array( 'eca-dashboard-env', 'eca-dashboard-resolver', 'eca-dashboard-render' ),
				$ver,
				true
			);

			$manifest = ECA_Dashboard_Registry::get_merged_manifest();
			$env      = ECA_Dashboard_Environment::build( self::$host_slug );

			wp_localize_script( 'eca-dashboard', 'ECA_ENV', $env );
			wp_localize_script( 'eca-dashboard', 'ECA_MANIFEST', $manifest );
			$admin_urls = ECA_Dashboard_Registry::admin_urls();
			wp_localize_script( 'eca-dashboard', 'ECA_ADMIN_URLS', $admin_urls );
			wp_localize_script(
				'eca-dashboard',
				'ECA_DASHBOARD',
				array(
					'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
					'nonceInstall'     => wp_create_nonce( 'updates' ),
					'noncePlugin'      => wp_create_nonce( 'eca_dashboard_plugin' ),
					'nonceDismiss'     => wp_create_nonce( 'eca_dashboard_review_dismiss' ),
					'adminUrls'        => $admin_urls,
					'dashboardUrl'     => $admin_urls['dashboard'] ?? admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
					'licenseUrl'       => $admin_urls['license'] ?? '',
					'canManagePlugins' => current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ),
					'getStarted'       => array(
						'esb'  => admin_url( 'admin.php?page=ect-onboarding' ),
						'divi' => admin_url( 'admin.php?page=ecmd-onboarding' ),
					),
					'labels'           => array(
						'installing'         => ECA_Dashboard_I18n::__( 'Installing & activating...' ),
						'activating'         => ECA_Dashboard_I18n::__( 'Activating...' ),
						'failed'             => ECA_Dashboard_I18n::__( 'Action failed. Please try again from Plugins.' ),
						'failedRetry'        => ECA_Dashboard_I18n::__( 'Failed -- retry' ),
						'activatePro'        => ECA_Dashboard_I18n::__( 'Activate Pro' ),
						'proOwnedNote'       => ECA_Dashboard_I18n::__( 'You already own Pro -- just activate it.' ),
						'getStarted'         => ECA_Dashboard_I18n::__( 'Get Started' ),
						'doneInstall'        => ECA_Dashboard_I18n::__( 'Installed & activated.' ),
						'doneActivate'       => ECA_Dashboard_I18n::__( 'Activated.' ),
						'donePro'            => ECA_Dashboard_I18n::__( 'Pro activated.' ),
						'recommendedTitle'   => ECA_Dashboard_I18n::__( 'Recommended Add-ons' ),
						'recommendedDesc'    => ECA_Dashboard_I18n::__( 'Install the recommended add-on plugins to get the complete experience.' ),
						'recommendedCta'     => ECA_Dashboard_I18n::__( 'Install Recommended Add-ons' ),
						'recommendedDone'    => ECA_Dashboard_I18n::__( 'All recommended add-ons have been installed and activated successfully.' ),
						'modalOk'            => ECA_Dashboard_I18n::__( 'OK' ),
						'modalRetry'         => ECA_Dashboard_I18n::__( 'Retry' ),
						'statusPending'      => ECA_Dashboard_I18n::__( 'Waiting' ),
						'statusInstalling'   => ECA_Dashboard_I18n::__( 'Installing...' ),
						'statusActivating'   => ECA_Dashboard_I18n::__( 'Activating...' ),
						'statusActivated'    => ECA_Dashboard_I18n::__( 'Activated' ),
						'statusSkipped'      => ECA_Dashboard_I18n::__( 'Already active' ),
						'statusFailed'       => ECA_Dashboard_I18n::__( 'Failed' ),
					),
				)
			);
		}

		/**
		 * AJAX: verify free-tier addon states after install/activate operations.
		 */
		public static function ajax_verify_addons() {
			check_ajax_referer( 'eca_dashboard_plugin', 'security' );

			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( array( 'message' => ECA_Dashboard_I18n::__( 'Permission denied.' ) ) );
			}

			$raw = isset( $_POST['addons'] ) ? wp_unslash( $_POST['addons'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ids = array();
			if ( is_array( $raw ) ) {
				$ids = array_map( 'sanitize_key', $raw );
			} elseif ( is_string( $raw ) && '' !== $raw ) {
				$ids = array_map( 'sanitize_key', explode( ',', $raw ) );
			}

			$allowed = ECA_Addon_Map::env_keys();
			$status  = array();
			foreach ( $ids as $id ) {
				if ( ! in_array( $id, $allowed, true ) ) {
					continue;
				}
				$status[ $id ] = array(
					'freeStatus' => ECA_Addon_Map::tier_status( $id, 'free' ),
					'freeActive' => ECA_Addon_Map::is_tier_active( $id, 'free' ),
					'freeInit'   => ECA_Addon_Map::tier_init( $id, 'free' ) ?: ECA_Addon_Map::expected_tier_init( $id, 'free' ),
					'freeSlug'   => ECA_Addon_Map::tier_slug( $id, 'free' ),
				);
			}

			wp_send_json_success(
				array(
					'addons' => $status,
					'env'    => ECA_Dashboard_Environment::build( self::$host_slug ),
				)
			);
		}

		/**
		 * AJAX: activate a mapped ECA addon after install or when already present.
		 */
		public static function ajax_plugin_activate() {
			check_ajax_referer( 'eca_dashboard_plugin', 'security' );

			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( array( 'message' => ECA_Dashboard_I18n::__( 'Permission denied.' ) ) );
			}

			$init = isset( $_POST['init'] ) ? sanitize_text_field( wp_unslash( $_POST['init'] ) ) : '';
			if ( '' === $init || ! ECA_Addon_Map::is_allowed_plugin_init( $init ) ) {
				wp_send_json_error( array( 'message' => ECA_Dashboard_I18n::__( 'Plugin not allowed.' ) ) );
			}

			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$result = activate_plugin( $init );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'message' => ECA_Dashboard_I18n::__( 'Plugin activated successfully.' ),
					'env'     => ECA_Dashboard_Environment::build( self::$host_slug ),
				)
			);
		}

		/**
		 * Validate dashboard install requests before Core's installer runs.
		 */
		public static function validate_plugin_install_slug() {
			check_ajax_referer( 'updates', '_ajax_nonce' );

			if ( ! current_user_can( 'install_plugins' ) ) {
				wp_send_json_error(
					array( 'message' => ECA_Dashboard_I18n::__( 'You do not have permission to install plugins.' ) ),
					403
				);
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			if ( '' === $slug || ! ECA_Addon_Map::is_allowed_install_slug( $slug ) ) {
				wp_send_json_error(
					array( 'message' => ECA_Dashboard_I18n::__( 'This plugin cannot be installed from the Events Addons dashboard.' ) ),
					400
				);
			}
		}

		/**
		 * AJAX: persist review dismiss per user so it follows them beyond localStorage.
		 */
		public static function ajax_review_dismiss() {
			check_ajax_referer( 'eca_dashboard_review_dismiss', 'security' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => ECA_Dashboard_I18n::__( 'Permission denied.' ) ) );
			}

			$addon = isset( $_POST['addon'] ) ? sanitize_key( wp_unslash( $_POST['addon'] ) ) : '';
			if ( '' === $addon || ! in_array( $addon, ECA_Addon_Map::env_keys(), true ) ) {
				wp_send_json_error( array( 'message' => ECA_Dashboard_I18n::__( 'Invalid addon.' ) ) );
			}

			$dismissed = get_user_meta( get_current_user_id(), ECA_Dashboard_Environment::REVIEW_META_KEY, true );
			if ( ! is_array( $dismissed ) ) {
				$dismissed = array();
			}
			if ( ! in_array( $addon, $dismissed, true ) ) {
				$dismissed[] = $addon;
			}

			update_user_meta( get_current_user_id(), ECA_Dashboard_Environment::REVIEW_META_KEY, array_values( array_unique( $dismissed ) ) );

			wp_send_json_success( array( 'dismissed' => $dismissed ) );
		}

		/**
		 * Enqueue shared shell styles (header chrome) without dashboard JS.
		 * Safe to call from secondary admin pages like License.
		 */
		public static function enqueue_shell_styles() {
			$base = class_exists( 'ECA_Dashboard_Registry' ) ? ECA_Dashboard_Registry::get_winner_path() : '';
			$ver  = class_exists( 'ECA_Dashboard_Registry' ) ? ECA_Dashboard_Registry::get_winner_version() : '';
			if ( ! $base || ! $ver ) {
				return;
			}

			$assets = ( class_exists( 'ECA_Dashboard_Registry' ) ? ECA_Dashboard_Registry::asset_base_url() : '' ) . 'assets/';
			$css    = $assets . 'css/';

			wp_enqueue_style( 'dashicons' );
			wp_enqueue_style( 'eca-dashboard-base', $css . 'eca-base.css', array(), $ver );
		}

		/**
		 * Resolve the plugin label shown under the brand on settings / CPT screens.
		 *
		 * Empty on Dashboard and License. Uses the merged manifest addon names when possible.
		 *
		 * @param string $plugin_context Optional explicit label. Pass empty string to force none.
		 * @return string
		 */
		public static function resolve_header_plugin_context( $plugin_context = null ) {
			if ( null !== $plugin_context ) {
				return sanitize_text_field( (string) $plugin_context );
			}

			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $page, array( self::PAGE_SLUG, 'cool-events-registration' ), true ) ) {
				return '';
			}

			$addon_key = '';
			$page_map  = array(
				'tribe-events-shortcode-template-settings' => 'esb',
				'tribe_events-events-template-settings'    => 'esb',
				'esas-speaker-sponsor-settings'            => 'speakers',
				'countdown_for_the_events_calendar'        => 'countdown',
			);

			if ( $page && isset( $page_map[ $page ] ) ) {
				$addon_key = $page_map[ $page ];
			} else {
				$post_type = '';
				if ( isset( $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				} elseif ( function_exists( 'get_current_screen' ) ) {
					$screen = get_current_screen();
					if ( $screen && ! empty( $screen->post_type ) ) {
						$post_type = sanitize_key( (string) $screen->post_type );
					}
				}

				$post_type_map = array(
					'epta'         => 'spb',
					'ewpe'         => 'widgets',
					'esas_speaker' => 'speakers',
					'esas_sponsor' => 'speakers',
				);
				if ( $post_type && isset( $post_type_map[ $post_type ] ) ) {
					$addon_key = $post_type_map[ $post_type ];
				}
			}

			/**
			 * Filter the addon key used for the header plugin context label.
			 *
			 * @param string $addon_key Detected addon key (esb, speakers, …) or empty.
			 * @param string $page      Current admin page slug.
			 */
			$addon_key = sanitize_key(
				(string) apply_filters( 'eca_dashboard_header_plugin_context_key', $addon_key, $page ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			);

			if ( '' === $addon_key ) {
				return '';
			}

			$label = '';
			if ( class_exists( 'ECA_Dashboard_Registry' ) ) {
				$manifest = ECA_Dashboard_Registry::get_merged_manifest();
				$addon    = isset( $manifest['addons'][ $addon_key ] ) && is_array( $manifest['addons'][ $addon_key ] )
					? $manifest['addons'][ $addon_key ]
					: array();

				$use_pro = class_exists( 'ECA_Addon_Map' ) && ECA_Addon_Map::is_tier_active( $addon_key, 'pro' );
				if ( $use_pro && ! empty( $addon['proName'] ) ) {
					$label = (string) $addon['proName'];
				} elseif ( ! empty( $addon['name'] ) ) {
					$label = (string) $addon['name'];
				}
			}

			$fallbacks = array(
				'esb'       => 'Events Shortcodes & Blocks',
				'widgets'   => 'Events Widgets for Elementor',
				'divi'      => 'Events Calendar Modules for Divi',
				'spb'       => 'Event Single Page Builder',
				'speakers'  => 'Events Speakers & Sponsors',
				'search'    => 'Events Search & Filter',
				'countdown' => 'Event Countdown',
			);
			if ( '' === $label && isset( $fallbacks[ $addon_key ] ) ) {
				$label = $fallbacks[ $addon_key ];
			}

			/**
			 * Filter the final header plugin context label.
			 *
			 * @param string $label     Display name under the brand.
			 * @param string $addon_key Addon key used to resolve the label.
			 */
			return sanitize_text_field(
				(string) apply_filters( 'eca_dashboard_header_plugin_context', $label, $addon_key ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			);
		}

		/**
		 * Open the shared ECA admin shell (header + main).
		 *
		 * @param string      $active_nav      Active nav key: dashboard|license (or any other key with no nav highlight).
		 * @param string|null $plugin_context  Optional plugin label under the brand. Null = auto-detect; '' = hide.
		 * @return bool Whether the shell was opened by this call.
		 */
		public static function render_admin_header( $active_nav = 'dashboard', $plugin_context = null ) {
			if ( self::$admin_header_rendered ) {
				return false;
			}

			$eca_active_nav      = sanitize_key( (string) $active_nav );
			$eca_plugin_context  = self::resolve_header_plugin_context( $plugin_context );
			$view                = ( class_exists( 'ECA_Dashboard_Registry' ) ? ECA_Dashboard_Registry::get_winner_path() : '' ) . 'views/admin-header.php';
			if ( file_exists( $view ) ) {
				include $view;
				self::$admin_header_rendered = true;
				self::$admin_shell_open      = true;
				return true;
			}

			return false;
		}

		/**
		 * Close the shared ECA admin shell.
		 */
		public static function render_admin_footer() {
			if ( ! self::$admin_shell_open ) {
				return;
			}

			$view = ( class_exists( 'ECA_Dashboard_Registry' ) ? ECA_Dashboard_Registry::get_winner_path() : '' ) . 'views/admin-footer.php';
			if ( file_exists( $view ) ) {
				include $view;
				self::$admin_shell_open = false;
			}
		}

		/**
		 * License page notices slot — below product tabs, above license form content.
		 *
		 * Safe for mixed installs: if the dedicated view is missing, falls back to an
		 * inline notices wrapper so ect_display_admin_notices still fires once.
		 *
		 * @return bool Whether the notices slot was rendered.
		 */
		public static function render_license_notices() {
			$view = ( class_exists( 'ECA_Dashboard_Registry' ) ? ECA_Dashboard_Registry::get_winner_path() : '' ) . 'views/license-notices.php';
			if ( file_exists( $view ) ) {
				include $view;
				return true;
			}

			// Fallback when an older Registry winner path lacks license-notices.php.
			echo '<div class="eca-notices-wrapper eca-license-notices-wrapper">';
			echo '<hr class="wp-header-end">';
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			if ( ! defined( 'ECA_ADMIN_NOTICES_RENDERED' ) ) {
				define( 'ECA_ADMIN_NOTICES_RENDERED', true );
				do_action( 'ect_display_admin_notices' );
			}
			// phpcs:enable
			echo '</div>';

			return true;
		}

		/**
		 * Hidden license pages (null parent) may leave global $title unset before admin-header.php.
		 */
		public static function ensure_license_admin_page_title() {
			if ( ! isset( $_GET['page'] ) ) {
				return;
			}
			if ( 'cool-events-registration' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
				return;
			}
			global $title;
			if ( null === $title || '' === $title ) {
				$title = ECA_Dashboard_I18n::__( 'License' );
			}
		}

		/**
		 * Hook enqueue on admin.
		 */
		public static function init_hooks() {
			add_action( 'admin_init', array( __CLASS__, 'ensure_license_admin_page_title' ), 1 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			add_action( 'wp_ajax_eca_dashboard_plugin_install', array( __CLASS__, 'validate_plugin_install_slug' ), 1 );
			add_action( 'wp_ajax_eca_dashboard_plugin_install', 'wp_ajax_install_plugin' );
			add_action( 'wp_ajax_eca_dashboard_plugin_activate', array( __CLASS__, 'ajax_plugin_activate' ) );
			add_action( 'wp_ajax_eca_dashboard_verify_addons', array( __CLASS__, 'ajax_verify_addons' ) );
			add_action( 'wp_ajax_eca_dashboard_review_dismiss', array( __CLASS__, 'ajax_review_dismiss' ) );
		}
	}

	ECA_Dashboard_Page::init_hooks();
}
