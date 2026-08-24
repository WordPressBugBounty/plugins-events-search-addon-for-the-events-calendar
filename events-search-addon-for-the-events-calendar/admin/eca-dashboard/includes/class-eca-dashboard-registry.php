<?php
/**
 * Multi-addon dashboard registry — newest embedded copy wins.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECA_Dashboard_Registry' ) ) {

	/**
	 * Registers vendored dashboard copies and boots the winner.
	 */
	final class ECA_Dashboard_Registry {

		/** @var array<int, array{version: string, path: string}> */
		private static $submissions = array();

		/** @var array<int, array<string, mixed>> */
		private static $addons = array();

		/** @var string|null */
		private static $winner_path = null;

		/** @var string|null */
		private static $winner_version = null;

		/** @var bool */
		private static $booted = false;

		/**
		 * @param string $version Semver of vendored module.
		 * @param string $path    Absolute path to admin/eca-dashboard/.
		 */
		public static function submit( $version, $path ) {
			self::$submissions[] = array(
				'version' => (string) $version,
				'path'    => trailingslashit( $path ),
			);
		}

		/**
		 * @param array<string, mixed> $config slug, host_slug, text_domain?, menu?, manifest_fragment?, manifest_version?.
		 */
		public static function register_addon( $config ) {
			self::$addons[] = $config;
		}

		/**
		 * @return array<string, mixed>
		 */
		public static function get_addon_configs() {
			return self::$addons;
		}

		/**
		 * @return string
		 */
		public static function asset_base_url() {
			// Prefer the winning copy's directory (not whichever host registered first).
			if ( self::$winner_path ) {
				$marker = self::$winner_path . 'eca-dashboard-manifest.json';
				if ( file_exists( $marker ) ) {
					return trailingslashit( plugins_url( '', $marker ) );
				}
			}

			foreach ( self::$addons as $addon ) {
				if ( ! empty( $addon['dashboard_url'] ) ) {
					return trailingslashit( (string) $addon['dashboard_url'] );
				}
			}

			return defined( 'ECT_PLUGIN_URL' ) ? ECT_PLUGIN_URL . 'admin/eca-dashboard/' : '';
		}

		/**
		 * Admin deep-links for Settings CTAs.
		 *
		 * Keys are either:
		 * - addon/driver ids (More Addons cards: speakers, countdown, esb, …)
		 * - workflow method-tab ids (listings/single-page "Open settings": shortcode, …)
		 *
		 * Plugins without a dedicated settings page resolve to the shared dashboard.
		 * Workflow tabs only show "Open settings" when their tab id has a real URL here
		 * (lookup is by tab id, not driver — so Block Editor can share the esb driver
		 * without inheriting the Shortcode settings CTA).
		 *
		 * @return array<string, string>
		 */
		public static function admin_urls() {
			$dashboard = admin_url( 'admin.php?page=' . ( class_exists( 'ECA_Dashboard_Page' ) ? ECA_Dashboard_Page::PAGE_SLUG : 'cool-plugins-events-addon' ) );
			$urls      = array(
				'dashboard' => $dashboard,
			);

			foreach ( self::$addons as $addon ) {
				if ( ! empty( $addon['admin_urls'] ) && is_array( $addon['admin_urls'] ) ) {
					foreach ( $addon['admin_urls'] as $key => $url ) {
						if ( is_string( $key ) && is_string( $url ) && '' !== $url ) {
							$urls[ sanitize_key( $key ) ] = $url;
						}
					}
				}
			}

			// Only fill missing keys — do not overwrite URLs already provided by a host or bridge.
			foreach ( array( 'divi', 'widgets', 'spb', 'search' ) as $no_settings_key ) {
				if ( empty( $urls[ $no_settings_key ] ) ) {
					$urls[ $no_settings_key ] = $dashboard;
				}
			}

			// Single-page Divi Templates → Theme Builder only when Divi Pro + Divi 5 are active.
			$divi5 = function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled();
			if (
				empty( $urls['divitpl'] )
				&& $divi5
				&& class_exists( 'ECA_Addon_Map' )
				&& ECA_Addon_Map::is_tier_active( 'divi', 'pro' )
			) {
				$urls['divitpl'] = admin_url( 'admin.php?page=et_theme_builder' );
			} elseif ( ! $divi5 ) {
				unset( $urls['divitpl'] );
			}

			return $urls;
		}

		/**
		 * Boot winner on plugins_loaded priority 20.
		 * Menu registration + legacy suppression run on admin_menu@9
		 * (same timing as the REF Cool_Events_Dashboard_Registry).
		 */
		public static function boot() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			if ( empty( self::$submissions ) ) {
				return;
			}

			$winner = self::$submissions[0];
			foreach ( self::$submissions as $submission ) {
				if ( version_compare( $submission['version'], $winner['version'], '>' ) ) {
					$winner = $submission;
				}
			}

			self::$winner_path    = $winner['path'];
			self::$winner_version = $winner['version'];

			self::load_includes( self::$winner_path );

			if ( class_exists( 'ECA_Compatibility_Resolver' ) ) {
				ECA_Compatibility_Resolver::init();
			}

			// Priority 9: before legacy cool_plugins_events_addons menu callback at priority 10.
			add_action( 'admin_menu', array( __CLASS__, 'boot_menus' ), 9 );
		}

		/**
		 * Suppress the legacy shared dashboard, then register the single new menu.
		 *
		 * @return void
		 */
		public static function boot_menus() {
			self::suppress_legacy_dashboard();

			if ( class_exists( 'ECA_Dashboard_Page' ) ) {
				ECA_Dashboard_Page::register_menus( self::$addons );
			}
		}

		/**
		 * Remove the legacy shared dashboard menu + enqueue so an un-updated
		 * sibling addon (old free/pro copy) cannot render a second/old dashboard.
		 *
		 * Each sibling calls show_plugins() and re-adds admin_menu on the shared
		 * singleton, so hooks must be removed in a loop. Method name also differs
		 * across copies (dashboard vs historical "dasboard" typo).
		 *
		 * @return void
		 */
		private static function suppress_legacy_dashboard() {
			if ( ! class_exists( 'cool_plugins_events_addons' ) || ! method_exists( 'cool_plugins_events_addons', 'init' ) ) {
				return;
			}

			$legacy = cool_plugins_events_addons::init();
			if ( ! is_object( $legacy ) ) {
				return;
			}

			$menu_methods = array(
				'init_plugins_dashboard_page',
				'init_plugins_dasboard_page',
			);

			foreach ( $menu_methods as $method ) {
				while ( remove_action( 'admin_menu', array( $legacy, $method ), 10 ) ) {
					// Keep removing until none remain.
				}
			}

			while ( remove_action( 'admin_enqueue_scripts', array( $legacy, 'enqueue_required_scripts' ), 10 ) ) {
				// Keep removing until none remain.
			}

			while ( remove_action( 'wp_ajax_ect_dashboard_install_plugin', array( $legacy, 'ect_dashboard_install_plugin' ), 10 ) ) {
				// Keep removing until none remain.
			}
		}

		/**
		 * @return string|null
		 */
		public static function get_winner_path() {
			return self::$winner_path;
		}

		/**
		 * @return string|null
		 */
		public static function get_winner_version() {
			return self::$winner_version;
		}

		/**
		 * Base URL for prototype editor/builder PNGs (helping-folder/*.png).
		 *
		 * @return string
		 */
		public static function editor_images_base_url() {
			return self::asset_base_url() . 'assets/images/';
		}

		/**
		 * Editor/builder icon URLs — same PNGs as the HTML prototype.
		 *
		 * @return array<string, string>
		 */
		public static function editor_icon_urls() {
			$base = self::editor_images_base_url();

			return array(
				'block'     => $base . 'gutenberg-icon.png',
				'shortcode' => $base . 'shortcode-icon.png',
				'elementor' => $base . 'elementor-icon.png',
				'divi'      => $base . 'divi-icon.png',
				'bricks'    => $base . 'bricks-icon.png',
				'wpbakery'  => $base . 'wpbakery-icon.png',
			);
		}

		/**
		 * @return array<string, mixed>
		 */
		public static function get_merged_manifest() {
			if ( ! self::$booted && ! empty( self::$submissions ) ) {
				self::boot();
			}

			$base_path = self::$winner_path;
			if ( ! $base_path ) {
				return array();
			}

			$file = $base_path . 'eca-dashboard-manifest.json';
			if ( ! file_exists( $file ) ) {
				return array();
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$manifest = json_decode( file_get_contents( $file ), true );
			if ( ! is_array( $manifest ) ) {
				return array();
			}

			$base_version = $manifest['manifestVersion'] ?? '0';

			foreach ( self::$addons as $addon ) {
				if ( empty( $addon['manifest_fragment'] ) || ! is_array( $addon['manifest_fragment'] ) ) {
					continue;
				}
				$fragment_version = $addon['manifest_version'] ?? ( $addon['manifest_fragment']['manifestVersion'] ?? '0' );
				if ( version_compare( $fragment_version, $base_version, '>=' ) ) {
					$manifest = array_replace_recursive( $manifest, $addon['manifest_fragment'] );
				}
			}

			return self::rewrite_manifest_asset_urls( $manifest, $base_path );
		}

		/**
		 * @param array<string, mixed> $manifest Manifest array.
		 * @param string               $base_path Winner module path.
		 * @return array<string, mixed>
		 */
		public static function rewrite_manifest_asset_urls( $manifest, $base_path ) {
			unset( $base_path );
			$icons_base  = self::asset_base_url() . 'assets/icons/';
			$editor_base = self::editor_images_base_url();

			// Longest / most-specific keys first so partial prefixes do not win early.
			$replacements = array(
				'helping-folder/gutenberg-icon.png' => $editor_base . 'gutenberg-icon.png',
				'helping-folder/shortcode-icon.png' => $editor_base . 'shortcode-icon.png',
				'helping-folder/elementor-icon.png' => $editor_base . 'elementor-icon.png',
				'helping-folder/divi-icon.png'      => $editor_base . 'divi-icon.png',
				'helping-folder/bricks-icon.png'    => $editor_base . 'bricks-icon.png',
				'helping-folder/wpbakery-icon.png'  => $editor_base . 'wpbakery-icon.png',
				'events-addons-icons/'              => $icons_base,
			);

			return self::deep_replace_manifest_strings( $manifest, $replacements );
		}

		/**
		 * Recursively rewrite prototype asset paths in manifest string values.
		 *
		 * Avoids wp_json_encode + str_replace — JSON escapes slashes as \/ which
		 * breaks naive string replacement on encoded manifest blobs.
		 *
		 * @param mixed                $data         Manifest node.
		 * @param array<string,string> $replacements From => to path map.
		 * @return mixed
		 */
		private static function deep_replace_manifest_strings( $data, $replacements ) {
			if ( is_string( $data ) ) {
				foreach ( $replacements as $from => $to ) {
					if ( '' !== $from && false !== strpos( $data, $from ) ) {
						$data = str_replace( $from, $to, $data );
					}
				}
				return $data;
			}

			if ( is_array( $data ) ) {
				foreach ( $data as $key => $value ) {
					$data[ $key ] = self::deep_replace_manifest_strings( $value, $replacements );
				}
			}

			return $data;
		}

		/**
		 * @param string $path Module path.
		 */
		private static function load_includes( $path ) {
			$includes = array(
				'class-eca-addon-map.php',
				'class-eca-dashboard-environment.php',
				'class-eca-dashboard-i18n.php',
				'class-eca-dashboard-page.php',
				'class-eca-compatibility-resolver.php',
			);

			foreach ( $includes as $file ) {
				$full = $path . 'includes/' . $file;
				if ( file_exists( $full ) ) {
					require_once $full;
				}
			}
		}
	}
}
