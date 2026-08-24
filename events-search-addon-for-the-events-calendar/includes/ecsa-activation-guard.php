<?php
/**
 * Free/Pro coexistence guard — the free edition's half.
 *
 * Events Search & Filter Bar Pro is a standalone plugin, not an add-on: a site
 * runs one edition or the other, never both. The public surface is shared on
 * purpose so upgrading loses nothing — shortcode tags, the `ecsa_settings`
 * option, the `ecsa/v1` REST namespace, URL parameters and asset handles are
 * identical in both. Registering any of those twice is undefined behaviour,
 * so when Pro is present this edition steps aside.
 *
 * Detection is inert by design: a `defined()` test plus one read of core's
 * `active_plugins` option. No feature is gated on it, no licence is consulted
 * and no remote call is made — the only possible outcome is declining to boot,
 * with an explanatory admin notice.
 *
 * A refused activation spans two requests, because a plugin cannot deactivate
 * itself inside its own activation hook (core writes `active_plugins` after
 * the hook runs) and cannot speak once inactive:
 *
 *   1. The Activate click — `on_activation()` sees Pro and records one option
 *      row. `Plugin::activate()` is not called, so the site's configuration is
 *      left exactly as it was found.
 *   2. The redirect back — the guard stands the engine down, deactivates this
 *      plugin silently at `admin_init`, and prints the notice on the screen
 *      the user lands on.
 *
 * If both editions are ever active without an activation request behind it,
 * `boot()` stands the engine down and shows a persistent notice instead —
 * never a fatal, never a white screen.
 *
 * This plugin's directory sorts ahead of Pro's, so this file runs before Pro
 * defines anything; every constant check is therefore made at `plugins_loaded`
 * or later. The class lives in the global namespace and is loaded directly, so
 * it works even when the autoloader has not been registered.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECSA_Activation_Guard' ) ) {

	/**
	 * Keeps the free and Pro editions from ever running at the same time.
	 *
	 * @since 2.0.0
	 */
	final class ECSA_Activation_Guard {

		/**
		 * The constant Pro defines at file scope in its main plugin file.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const PRO_CONSTANT = 'ECSAP_VERSION';

		/**
		 * Pro's main filename, matched by name so a renamed directory still counts.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const PRO_MAIN_FILE = 'events-search-filter-bar-pro.php';

		/**
		 * One-shot flag holding the Unix timestamp of a refused activation.
		 *
		 * Written non-autoloaded, read once, deleted on use. This is the
		 * guard's own row; the shared `ecsa_settings` configuration is never
		 * read or written from this file.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const REFUSED_OPTION = 'ecsa_pro_active_refusal';

		/**
		 * How long a recorded refusal stays actionable, in seconds.
		 *
		 * The refusal only needs to survive one redirect; the bound exists so a
		 * stranded row (Pro removed before any admin screen loaded) can never
		 * deactivate a working plugin months later. An expired row is discarded
		 * on sight.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		const REFUSAL_TTL = 900;

		/**
		 * Absolute path to this plugin's main file.
		 *
		 * Everything that needs a plugin basename derives it from this with
		 * `plugin_basename()`, so a renamed plugin directory is handled without
		 * a hardcoded path anywhere in this file.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private static $plugin_file = '';

		/**
		 * Did this request refuse an activation?
		 *
		 * Request-scoped on purpose: the deactivation and its notice happen in
		 * the same request, so a stale option row can never speak for a later
		 * one.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static $refused = false;

		/**
		 * Wire up the guard.
		 *
		 * Owns activation, deactivation and boot, since each behaves differently
		 * when Pro is present. Both lifecycle hooks are registered before any
		 * early return, so a stood-down site stays recoverable from the plugins
		 * screen.
		 *
		 * @since 2.0.0
		 * @param string $plugin_file Absolute path to this plugin's main file.
		 * @return void
		 */
		public static function init( $plugin_file ) {
			self::$plugin_file = (string) $plugin_file;

			register_activation_hook( $plugin_file, array( __CLASS__, 'on_activation' ) );
			register_deactivation_hook( $plugin_file, array( __CLASS__, 'on_deactivation' ) );

			add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 5 );
		}

		/**
		 * Is Pro loaded in this request?
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function pro_is_loaded() {
			if ( defined( self::PRO_CONSTANT ) ) {
				return true;
			}

			/*
			 * The option row as well as the constant: `active_plugins` is
			 * readable before Pro's file has been included, so this edition can
			 * step aside first and Pro — the superset — deterministically wins a
			 * both-active site in either load order. Matched on the filename so
			 * a renamed plugin directory still counts.
			 */
			$active = get_option( 'active_plugins' );

			if ( ! is_array( $active ) ) {
				return false;
			}

			foreach ( $active as $basename ) {
				if ( self::PRO_MAIN_FILE === basename( (string) $basename ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * This plugin's basename, tolerating a renamed directory.
		 *
		 * @since 2.0.0
		 * @return string Basename, or '' when the guard was never initialised.
		 */
		public static function plugin_basename() {
			if ( '' === self::$plugin_file || ! function_exists( 'plugin_basename' ) ) {
				return '';
			}

			return plugin_basename( self::$plugin_file );
		}

		/**
		 * Activation routine.
		 *
		 * With Pro absent this is a pass-through to the engine's own activation.
		 * With Pro present it records the refusal and does NOTHING ELSE — in
		 * particular it does not call `Plugin::activate()`, so none of the
		 * option rows that routine seeds are written and no cron is armed. The
		 * site's configuration is left exactly as it was found.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function on_activation() {
			if ( ! self::pro_is_loaded() ) {
				/*
				 * Activating with Pro gone settles the question: any refusal
				 * still on the books belongs to a situation that no longer
				 * exists and must not be able to resurface later.
				 */
				delete_option( self::REFUSED_OPTION );

				if ( class_exists( 'CoolPlugins\EventsSearch\Plugin' ) ) {
					\CoolPlugins\EventsSearch\Plugin::activate();
				}
				return;
			}

			update_option( self::REFUSED_OPTION, (string) time(), false );
		}

		/**
		 * Deactivation routine.
		 *
		 * `Plugin::deactivate()` clears the `ecsa_extra_data_update` schedule,
		 * and that hook name is shared with Pro. When Pro is running the
		 * schedule belongs to Pro — this copy never armed it and must not
		 * unwind it on the way out.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function on_deactivation() {
			if ( self::pro_is_loaded() ) {
				return;
			}

			if ( class_exists( 'CoolPlugins\EventsSearch\Plugin' ) ) {
				\CoolPlugins\EventsSearch\Plugin::deactivate();
			}
		}

		/**
		 * Boot the engine, or stand down.
		 *
		 * Runs on `plugins_loaded` 5, the earliest point at which Pro is
		 * guaranteed to have loaded regardless of directory sort order.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function boot() {
			if ( self::pro_is_loaded() ) {
				self::stand_down();
				return;
			}

			if ( class_exists( 'CoolPlugins\EventsSearch\Plugin' ) ) {
				\CoolPlugins\EventsSearch\Plugin::instance();
			}
		}

		/**
		 * Refuse to run, visibly.
		 *
		 * The engine is never instantiated, so the shortcodes, the REST
		 * namespace, the asset handles, the widget, the cron handler and the
		 * settings page are all left to Pro — nothing is registered twice.
		 * Nothing here can fatal: no autoloaded class is touched, no TEC call is
		 * made, and on the front end it returns without registering anything.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function stand_down() {
			/*
			 * Public signal that this copy loaded but is not serving. Set on the
			 * front end too, so code that saw `ECSA_VERSION` defined can still
			 * tell "loaded" apart from "running".
			 */
			if ( ! defined( 'ECSA_STOOD_DOWN' ) ) {
				define( 'ECSA_STOOD_DOWN', true );
			}

			/*
			 * Front end: nothing further. Every string this file can print is
			 * behind `is_admin()`, so a stood-down copy costs a visitor exactly
			 * one `defined()` call per request and registers no hooks.
			 */
			if ( ! is_admin() ) {
				return;
			}

			/*
			 * The engine's own `load_textdomain()` never runs in this state, and
			 * the notices below are the only strings the user will see from this
			 * plugin. WP 6.7+ would just-in-time load the domain anyway; 6.3–6.6
			 * are inside our `Requires at least` range and would not.
			 */
			add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );

			add_action( 'admin_init', array( __CLASS__, 'maybe_refuse' ), 0 );
			add_action( 'load-plugins.php', array( __CLASS__, 'suppress_activated_message' ), 0 );

			add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 10 );
			add_action( 'network_admin_notices', array( __CLASS__, 'render_notice' ), 10 );
		}

		/**
		 * Load translations for the notice strings.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function load_textdomain() {
			if ( '' === self::$plugin_file || ! function_exists( 'load_plugin_textdomain' ) ) {
				return;
			}

			load_plugin_textdomain(
				'events-search-addon-for-the-events-calendar',
				false,
				dirname( plugin_basename( self::$plugin_file ) ) . '/languages/'
			); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- retained for non-wp.org installs.
		}

		/**
		 * Complete a refused activation: deactivate this plugin, then say so.
		 *
		 * Runs on `admin_init` 0 of the request after the activation click —
		 * the one moment this plugin is both active and loaded, so it can
		 * remove itself from `active_plugins` and still print a notice. The
		 * plugins table is built later in the same request and already reflects
		 * the deactivation.
		 *
		 * `deactivate_plugins()` is called silently so this plugin's own
		 * deactivation hook cannot clear the shared `ecsa_extra_data_update`
		 * schedule that Pro owns while it runs. Gated on `activate_plugins`,
		 * and skipped for AJAX: `admin-ajax.php` never renders notices, so a
		 * Heartbeat tick from a second tab would otherwise consume the refusal
		 * without anyone being told why.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function maybe_refuse() {
			if ( wp_doing_ajax() ) {
				return;
			}

			$refused_at = (int) get_option( self::REFUSED_OPTION );

			if ( $refused_at <= 0 ) {
				return;
			}

			if ( ( time() - $refused_at ) > self::REFUSAL_TTL ) {
				delete_option( self::REFUSED_OPTION );
				return;
			}

			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			$basename = self::plugin_basename();

			if ( '' === $basename ) {
				return;
			}

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				return;
			}

			delete_option( self::REFUSED_OPTION );

			deactivate_plugins( $basename, true );

			self::$refused = true;
		}

		/**
		 * Drop core's "Plugin activated." message on a refused activation.
		 *
		 * The message is printed purely from `$_GET['activate']` and would sit
		 * directly above a notice saying the opposite. Only the single-plugin
		 * key is unset — a bulk activation's message is still true of the other
		 * plugins in the batch. Scoped to the request that actually refused.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function suppress_activated_message() {
			if ( ! self::$refused ) {
				return;
			}

			unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- removing a core display flag, not reading input.
		}

		/**
		 * Render whichever notice this state calls for.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function render_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( self::$refused ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html__( 'You are already using Events Search & Filter Bar Pro, which includes everything the free version does. The free plugin was not activated — there is no need to run both.', 'events-search-addon-for-the-events-calendar' )
				);

				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Events Search & Filter Bar Pro is already active, so the free Events Search & Filter Bar is not running — both use the same shortcodes and settings, and only one can run at a time. You can safely deactivate the free plugin.', 'events-search-addon-for-the-events-calendar' )
			);
		}
	}
}
