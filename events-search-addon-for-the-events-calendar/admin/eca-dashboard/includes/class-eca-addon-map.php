<?php
/**
 * Canonical map: resolver env keys to WordPress plugin directory slugs.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECA_Addon_Map' ) ) {

	/**
	 * Addon slug registry for environment detection.
	 */
	final class ECA_Addon_Map {

		/**
		 * @return array<string, array{free?: string, pro?: string, free_main?: string, pro_main?: string}>
		 */
		public static function definitions() {
			return array(
				'esb'       => array(
					'free'      => 'template-events-calendar',
					'free_main' => 'events-calendar-templates.php',
					'pro'       => 'the-events-calendar-templates-and-shortcode',
				),
				'widgets'   => array(
					'free' => 'events-widgets-for-elementor-and-the-events-calendar',
					'pro'  => 'events-widgets-pro',
				),
				'divi'      => array(
					'free'      => 'events-calendar-modules-for-divi',
					'free_main' => 'events-calendar-modules-for-divi.php',
					'pro'       => 'cp-events-calendar-modules-for-divi-pro',
					'pro_main'  => 'cp-events-calendar-modules-for-divi-pro.php',
					'pro_alts'  => array( 'events-calendar-modules-for-divi-pro' ),
				),
				'spb'       => array(
					'free'      => 'event-page-templates-addon-for-the-events-calendar',
					'free_main' => 'the-events-calendar-event-details-page-templates.php',
					'pro'       => 'event-single-page-builder-pro',
					'pro_main'  => 'event-single-page-builder-pro.php',
				),
				'speakers'  => array(
					'pro' => 'events-speakers-and-sponsors',
				),
				'search'    => array(
					'free'      => 'events-search-addon-for-the-events-calendar',
					'free_main' => 'events-calendar-search-addon.php',
					/*
					 * THE SLUG THE PRO PLUGIN ACTUALLY SHIPS UNDER.
					 *
					 * The reference copy of this file names
					 * `events-search-and-filters-pro`, and lists three more
					 * spellings under `pro_alts` - none of which is the real one.
					 * The plugin on disk is `events-search-filter-bar-pro`, so a
					 * copy carrying the reference's list cannot see Pro at all: a
					 * customer who has already bought it is shown "not installed"
					 * and sold it again.
					 *
					 * This is the one deliberate divergence in this folder. The
					 * alternatives are worse in both directions: the reference is
					 * not ours to edit, and matching it would break the detection
					 * the whole card depends on. It goes away when the same
					 * correction reaches the other copies.
					 */
					'pro'       => 'events-search-filter-bar-pro',
					'pro_main'  => 'events-search-filter-bar-pro.php',
				),
				'countdown' => array(
					'free'      => 'countdown-for-the-events-calendar',
					'free_main' => 'countdown-for-events-calendar.php',
				),
			);
		}

		/**
		 * @return string[]
		 */
		public static function env_keys() {
			return array_keys( self::definitions() );
		}

		/**
		 * @param string $dir_slug Plugin directory slug.
		 * @return string|null Env key or null.
		 */
		public static function env_key_from_dir_slug( $dir_slug ) {
			foreach ( self::definitions() as $key => $def ) {
				if ( ! empty( $def['free'] ) && $def['free'] === $dir_slug ) {
					return $key;
				}
				if ( ! empty( $def['pro'] ) && $def['pro'] === $dir_slug ) {
					return $key;
				}
				foreach ( array( 'free_alts', 'pro_alts' ) as $alts_key ) {
					if ( empty( $def[ $alts_key ] ) || ! is_array( $def[ $alts_key ] ) ) {
						continue;
					}
					if ( in_array( $dir_slug, $def[ $alts_key ], true ) ) {
						return $key;
					}
				}
			}
			return null;
		}

		/**
		 * @param string $env_key Addon env key.
		 * @param string $tier    free|pro.
		 * @return bool
		 */
		public static function is_tier_active( $env_key, $tier ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$def = self::definitions()[ $env_key ] ?? null;
			if ( ! $def || empty( $def[ $tier ] ) ) {
				return false;
			}

			$main = self::tier_init( $env_key, $tier );
			if ( ! $main ) {
				return false;
			}

			return is_plugin_active( $main );
		}

		/**
		 * @param string $env_key Addon env key.
		 * @param string $tier    free|pro.
		 * @return string absent|inactive|active
		 */
		public static function tier_status( $env_key, $tier ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$main = self::tier_init( $env_key, $tier );
			if ( ! $main ) {
				return 'absent';
			}

			return is_plugin_active( $main ) ? 'active' : 'inactive';
		}

		/**
		 * @param string $env_key Addon env key.
		 * @param string $tier    free|pro.
		 * @return string Plugin basename relative to plugins dir, or empty.
		 */
		public static function tier_init( $env_key, $tier ) {
			$def = self::definitions()[ $env_key ] ?? null;
			if ( ! $def || empty( $def[ $tier ] ) ) {
				return '';
			}

			$found = self::find_plugin_basename( (string) $def[ $tier ] );
			if ( $found ) {
				return $found;
			}

			$alts_key = $tier . '_alts';
			if ( ! empty( $def[ $alts_key ] ) && is_array( $def[ $alts_key ] ) ) {
				foreach ( $def[ $alts_key ] as $alt ) {
					$found = self::find_plugin_basename( (string) $alt );
					if ( $found ) {
						return $found;
					}
				}
			}

			return '';
		}

		/**
		 * Expected plugin basename, even before the plugin is installed.
		 *
		 * @param string $env_key Addon env key.
		 * @param string $tier    free|pro.
		 * @return string Plugin basename relative to plugins dir, or empty.
		 */
		public static function expected_tier_init( $env_key, $tier ) {
			$def = self::definitions()[ $env_key ] ?? null;
			if ( ! $def || empty( $def[ $tier ] ) ) {
				return '';
			}

			$installed = self::tier_init( $env_key, $tier );
			if ( $installed ) {
				return $installed;
			}

			$slug = (string) $def[ $tier ];
			$main = ! empty( $def[ $tier . '_main' ] ) ? (string) $def[ $tier . '_main' ] : $slug . '.php';

			return $slug . '/' . $main;
		}

		/**
		 * @param string $env_key Addon env key.
		 * @param string $tier    free|pro.
		 * @return string WordPress.org/plugin directory slug, or empty.
		 */
		public static function tier_slug( $env_key, $tier ) {
			$def = self::definitions()[ $env_key ] ?? null;

			return ( $def && ! empty( $def[ $tier ] ) ) ? (string) $def[ $tier ] : '';
		}

		/**
		 * WordPress.org slugs that the dashboard may install.
		 *
		 * Only canonical free-tier plugins are installable. Pro and alternate
		 * slugs may be activated when present, but are never passed to Core's
		 * public plugin installer.
		 *
		 * @return string[]
		 */
		public static function allowed_install_slugs() {
			$allowed = array();
			foreach ( self::definitions() as $def ) {
				if ( ! empty( $def['free'] ) ) {
					$allowed[] = sanitize_key( (string) $def['free'] );
				}
			}

			return array_values( array_unique( $allowed ) );
		}

		/**
		 * @param string $slug WordPress.org plugin slug.
		 * @return bool
		 */
		public static function is_allowed_install_slug( $slug ) {
			return in_array( sanitize_key( $slug ), self::allowed_install_slugs(), true );
		}

		/**
		 * Allowed basenames for dashboard activation.
		 *
		 * @return string[]
		 */
		public static function allowed_plugin_inits() {
			$allowed = array();
			foreach ( self::definitions() as $def ) {
				foreach ( array( 'free', 'pro' ) as $tier ) {
					if ( empty( $def[ $tier ] ) ) {
						continue;
					}
					$dirs = array( (string) $def[ $tier ] );
					$alts_key = $tier . '_alts';
					if ( ! empty( $def[ $alts_key ] ) && is_array( $def[ $alts_key ] ) ) {
						foreach ( $def[ $alts_key ] as $alt ) {
							$dirs[] = (string) $alt;
						}
					}
					foreach ( $dirs as $slug ) {
						$file = self::find_plugin_basename( $slug );
						if ( $file ) {
							$allowed[] = $file;
						}
						$main = ! empty( $def[ $tier . '_main' ] ) ? (string) $def[ $tier . '_main' ] : $slug . '.php';
						// Primary slug uses mapped main; alts fall back to slug.php convention.
						if ( $slug === (string) $def[ $tier ] ) {
							$allowed[] = $slug . '/' . $main;
						} else {
							$allowed[] = $slug . '/' . $slug . '.php';
						}
					}
				}
			}

			return array_values( array_unique( $allowed ) );
		}

		/**
		 * Validate an activation target against known ECA addon directories.
		 *
		 * Core's installer returns the real plugin basename, which can differ from
		 * the common slug/slug.php convention. Keep the allowlist strict by
		 * accepting only basenames inside mapped addon directories.
		 *
		 * @param string $init Plugin basename relative to plugins dir.
		 * @return bool
		 */
		public static function is_allowed_plugin_init( $init ) {
			$init = plugin_basename( $init );
			if ( in_array( $init, self::allowed_plugin_inits(), true ) ) {
				return true;
			}

			$dir = dirname( $init );
			if ( '.' === $dir || '' === $dir ) {
				return false;
			}

			foreach ( self::definitions() as $def ) {
				foreach ( array( 'free', 'pro' ) as $tier ) {
					if ( ! empty( $def[ $tier ] ) && $dir === (string) $def[ $tier ] ) {
						return true;
					}
					$alts_key = $tier . '_alts';
					if ( ! empty( $def[ $alts_key ] ) && is_array( $def[ $alts_key ] ) && in_array( $dir, $def[ $alts_key ], true ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Whether a free/pro tier addon exists on disk (active or inactive).
		 *
		 * @param string $env_key Addon env key.
		 * @param string $tier    free|pro.
		 * @return bool
		 */
		public static function is_tier_present( $env_key, $tier ) {
			return 'absent' !== self::tier_status( $env_key, $tier );
		}

		/**
		 * True when any related Pro addon is installed (active or inactive).
		 *
		 * Used by the Free Shortcodes host to hide recommended free-addon CTA
		 * whenever a Pro line already exists in the ecosystem.
		 *
		 * @return bool
		 */
		public static function any_related_pro_present() {
			foreach ( self::definitions() as $key => $def ) {
				if ( empty( $def['pro'] ) ) {
					continue;
				}
				if ( self::is_tier_present( $key, 'pro' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * @param string $dir_slug Plugin directory slug under wp-content/plugins.
		 * @return string Plugin basename relative to plugins dir, or empty.
		 */
		public static function find_plugin_basename( $dir_slug ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . $dir_slug;
			if ( ! is_dir( $plugin_dir ) ) {
				return '';
			}

			$slug     = basename( $plugin_dir );
			$candidate = $slug . '/' . $slug . '.php';
			if ( file_exists( WP_PLUGIN_DIR . '/' . $candidate ) ) {
				return $candidate;
			}

			$files = glob( $plugin_dir . '/*.php' );
			if ( empty( $files ) ) {
				return '';
			}

			foreach ( $files as $file ) {
				$data = get_file_data(
					$file,
					array( 'PluginName' => 'Plugin Name' )
				);
				if ( ! empty( $data['PluginName'] ) ) {
					return plugin_basename( $file );
				}
			}

			return plugin_basename( $files[0] );
		}
	}
}
