<?php
/**
 * Builds the env object consumed by the dashboard resolver.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECA_Dashboard_Environment' ) ) {

	/**
	 * Server-side environment builder.
	 */
	final class ECA_Dashboard_Environment {

		const REVIEW_META_KEY = 'eca_review_dismissed';

		/**
		 * @param string $host_addon_slug Resolver host slug (esb, widgets, …).
		 * @return array<string, mixed>
		 */
		public static function build( $host_addon_slug = 'esb' ) {
			$addons = array();
			foreach ( ECA_Addon_Map::env_keys() as $key ) {
				if ( 'speakers' === $key ) {
					$addons[ $key ] = array(
						'pro'       => ECA_Addon_Map::is_tier_active( $key, 'pro' ),
						'proStatus' => ECA_Addon_Map::tier_status( $key, 'pro' ),
						'proInit'   => ECA_Addon_Map::expected_tier_init( $key, 'pro' ),
						'proSlug'   => ECA_Addon_Map::tier_slug( $key, 'pro' ),
					);
				} elseif ( 'countdown' === $key ) {
					$addons[ $key ] = array(
						'free'       => ECA_Addon_Map::is_tier_active( $key, 'free' ),
						'freeStatus' => ECA_Addon_Map::tier_status( $key, 'free' ),
						'freeInit'   => ECA_Addon_Map::expected_tier_init( $key, 'free' ),
						'freeSlug'   => ECA_Addon_Map::tier_slug( $key, 'free' ),
					);
				} else {
					// Dual-tier addons (esb, widgets, divi, spb, search, …) — same shape.
					$addons[ $key ] = array(
						'free'       => ECA_Addon_Map::is_tier_active( $key, 'free' ),
						'pro'        => ECA_Addon_Map::is_tier_active( $key, 'pro' ),
						'freeStatus' => ECA_Addon_Map::tier_status( $key, 'free' ),
						'proStatus'  => ECA_Addon_Map::tier_status( $key, 'pro' ),
						'freeInit'   => ECA_Addon_Map::expected_tier_init( $key, 'free' ),
						'proInit'    => ECA_Addon_Map::expected_tier_init( $key, 'pro' ),
						'freeSlug'   => ECA_Addon_Map::tier_slug( $key, 'free' ),
						'proSlug'    => ECA_Addon_Map::tier_slug( $key, 'pro' ),
					);
				}
			}

			$dismissed = get_user_meta( get_current_user_id(), self::REVIEW_META_KEY, true );
			if ( ! is_array( $dismissed ) ) {
				$dismissed = array();
			}

			$editors = self::detect_editors();

			// Impossible stacks: widgets need Elementor; Divi widgets need Divi.
			if ( empty( $editors['elementor'] ) ) {
				$addons['widgets']['free'] = false;
				$addons['widgets']['pro']  = false;
			}
			if ( empty( $editors['divi'] ) ) {
				$addons['divi']['free'] = false;
				$addons['divi']['pro']  = false;
			}

			return array(
				'addons'               => $addons,
				'editors'              => $editors,
				'dismissed'            => $dismissed,
				'hostAddonSlug'        => sanitize_key( $host_addon_slug ),
				'canManagePlugins'     => current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ),
				'anyRelatedProPresent' => ECA_Addon_Map::any_related_pro_present(),
			);
		}

		/**
		 * @return array{elementor: bool, divi: bool, divi5: bool, bricks: bool, wpbakery: bool, blockEditor: bool, classicEditor: bool}
		 */
		public static function detect_editors() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$classic = is_plugin_active( 'classic-editor/classic-editor.php' );
			$divi    = defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' );
			// Same flag Divi / our onboarding use — Divi 4 and Divi 5-with-D5-off stay false.
			$divi5   = $divi && function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled();

			return array(
				'elementor'     => class_exists( '\Elementor\Plugin' ),
				'divi'          => $divi,
				'divi5'         => $divi5,
				'bricks'        => function_exists( 'bricks_is_builder' ) || defined( 'BRICKS_VERSION' ),
				'wpbakery'      => defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' ),
				'classicEditor' => $classic,
				'blockEditor'   => ! $classic,
			);
		}

		/**
		 * Map live stack to ECT wizard preview state.
		 *
		 * @return array{preview_state: string, default_editor: string}
		 */
		public static function wizard_preview_state() {
			$env      = self::build( 'esb' );
			$editors  = $env['editors'];
			$addons   = $env['addons'];
			$e        = ! empty( $editors['elementor'] );
			$d        = ! empty( $editors['divi'] );
			$b        = ! empty( $editors['bricks'] );
			$k        = ! empty( $editors['wpbakery'] );
			$w        = $e && ( ! empty( $addons['widgets']['free'] ) || ! empty( $addons['widgets']['pro'] ) );
			$m        = $d && ( ! empty( $addons['divi']['free'] ) || ! empty( $addons['divi']['pro'] ) );
			$any      = $e || $d || $b || $k;

			if ( ! $any ) {
				return array(
					'preview_state'  => 'no-builder',
					'default_editor' => 'shortcode',
				);
			}

			// Partial sibling installs in multi-builder stacks map to the
			// NON-installed multi-builder state so every detected editor
			// option stays visible (e.g. EDW → elementor-divi, not elementor).
			// K = WPBakery (no sibling addon flag).
			$key   = ( $e ? 'E' : '' ) . ( $d ? 'D' : '' ) . ( $b ? 'B' : '' ) . ( $k ? 'K' : '' ) . ( $w ? 'W' : '' ) . ( $m ? 'M' : '' );
			$table = array(
				'E'     => 'elementor',
				'EW'    => 'elementor-installed',
				'D'     => 'divi',
				'DM'    => 'divi-installed',
				'B'     => 'bricks',
				'K'     => 'wpbakery',
				'ED'    => 'elementor-divi',
				'EDW'   => 'elementor-divi',              // partial → keep both editors
				'EDM'   => 'elementor-divi',              // partial → keep both editors
				'EDWM'  => 'elementor-divi-installed',
				'EB'    => 'all-builders',
				'EBW'   => 'all-builders',
				'EK'    => 'all-builders',
				'EKW'   => 'all-builders',
				'DB'    => 'all-builders',
				'DBM'   => 'all-builders',
				'DK'    => 'all-builders',
				'DKM'   => 'all-builders',
				'BK'    => 'all-builders',
				'EDB'   => 'all-builders',
				'EDBW'  => 'all-builders',                // partial → keep all editors
				'EDBM'  => 'all-builders',                // partial → keep all editors
				'EDK'   => 'all-builders',
				'EDKW'  => 'all-builders',
				'EDKM'  => 'all-builders',
				'EDKWM' => 'all-builders',
				'EBK'   => 'all-builders',
				'EBKW'  => 'all-builders',
				'DBK'   => 'all-builders',
				'DBKM'  => 'all-builders',
				'EDBK'  => 'all-builders',
				'EDBKW' => 'all-builders',
				'EDBKM' => 'all-builders',
				'EDBWM' => 'all-installed',
				'EDBKWM'=> 'all-installed',
			);

			if ( isset( $table[ $key ] ) ) {
				$preview = $table[ $key ];
			} elseif ( $e && $d ) {
				$preview = 'elementor-divi';
			} elseif ( $e ) {
				$preview = 'elementor';
			} elseif ( $d ) {
				$preview = 'divi';
			} elseif ( $b ) {
				$preview = 'bricks';
			} elseif ( $k ) {
				$preview = 'wpbakery';
			} else {
				$preview = 'bricks';
			}

			$editor = 'shortcode';
			if ( $e && ! $d && ! $b && ! $k ) {
				$editor = 'elementor';
			} elseif ( $d && ! $e && ! $b && ! $k ) {
				$editor = 'divi';
			} elseif ( $b && ! $e && ! $d && ! $k ) {
				$editor = 'bricks';
			} elseif ( $k && ! $e && ! $d && ! $b ) {
				$editor = 'wpbakery';
			} elseif ( $e ) {
				$editor = 'elementor';
			}

			return array(
				'preview_state'  => $preview,
				'default_editor' => $editor,
			);
		}
	}
}
