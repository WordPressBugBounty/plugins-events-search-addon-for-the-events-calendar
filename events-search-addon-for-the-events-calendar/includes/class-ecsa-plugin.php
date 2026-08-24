<?php
/**
 * Plugin bootstrap.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Plugin' ) ) {

	/**
	 * Single entry point. Instantiated on `plugins_loaded` priority 5.
	 *
	 * Nothing in this class runs at include time. TEC availability is NOT
	 * gated here — it is pushed down into `Tec::available()` and checked at the
	 * top of every method that touches TEC, so a site without (or with a
	 * too-old) The Events Calendar degrades to rendering nothing rather than
	 * fatalling. The `Requires Plugins:` header enforces it declaratively.
	 *
	 * @since 2.0.0
	 */
	final class Plugin {

		/**
		 * Singleton instance.
		 *
		 * @var Plugin|null
		 */
		private static $instance = null;

		/**
		 * Retrieve (and on first call, build) the instance.
		 *
		 * @since 2.0.0
		 * @return Plugin
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Require a plugin file only if it is actually present.
		 *
		 * Guardrail 1 is "no fatals on update". A `require_once` on a missing
		 * file is an E_COMPILE_ERROR, not a warning: if an interrupted update
		 * or a partial FTP upload leaves one file behind, the site white-screens
		 * on EVERY request including wp-admin, so the user cannot even reach the
		 * plugins list to deactivate and needs FTP to recover. Degrading to a
		 * missing feature is always better than that.
		 *
		 * @since 2.0.0
		 * @param string $relative Path relative to the plugin root.
		 * @return bool True when the file was present.
		 */
		private static function require_if_exists( $relative ) {
			$path = ECSA_PATH . $relative;

			if ( ! file_exists( $path ) ) {
				return false;
			}

			require_once $path;

			return true;
		}

		private function __construct() {
			/*
			 * The autoloader skips a missing file rather than requiring it,
			 * which turns a compile error into an equally fatal
			 * "class not found" Error at the call site. Every autoloaded class
			 * used on this always-executed boot path is therefore guarded.
			 */
			if ( class_exists( __NAMESPACE__ . '\Schema' ) ) {
				Schema::maybe_upgrade();
			}

			/*
			 * Priority 0, not 10.
			 *
			 * `ECSA_ECA_Integration::register_addon()` runs at `init` 1 (it must
			 * be deferred to init at all because WP 6.7+ warns when a text
			 * domain is used before then) and its menu strings are translated.
			 * Loading the domain at 10 would leave those strings untranslated on
			 * every request on WP 6.3–6.6, which predate just-in-time
			 * translation loading and are inside our `Requires at least` range.
			 */
			add_action( 'init', array( $this, 'load_textdomain' ), 0 );

			$this->boot_render_path();
			$this->boot_query_spine();
			$this->boot_tec_views();

			/*
			 * Re-hooking plugins_loaded at 10 from inside plugins_loaded 5:
			 * the shared CPFM modules are class_exists-guarded and first-loader
			 * wins, so every sibling addon must attempt the load at the same
			 * priority or the winner changes with plugin activation order.
			 */
			add_action( 'plugins_loaded', array( $this, 'boot_shared_modules' ), 10 );

			// Cool Timeline / Event Countdown pattern: load CPFM on every request
			// so CPFM_Usage_Cron exists when wp-cron.php fires (not is_admin()).
			self::boot_cpfm_framework();

			add_action( 'widgets_init', array( $this, 'register_widget' ), 10 );
			// One-shot post-activation redirect; see maybe_activation_redirect().
			add_action( 'admin_init', array( __CLASS__, 'maybe_activation_redirect' ) );

			if ( is_admin() ) {
				$this->boot_admin();
			}

			// Register usage cron once (static-guarded), admin + WP-Cron.
			add_action( 'init', array( $this, 'register_cpfm_usage_cron' ), 5 );
		}

		/**
		 * Shared, ecosystem-wide consent flag. Written ONLY by the corner popup
		 * (and any onboarding step) — never by this plugin's own checkbox.
		 */
		const CONSENT_MASTER = 'cpfm_opt_in_choice_cool_events';

		/**
		 * This plugin's own override. Written ONLY by the settings checkbox.
		 */
		const CONSENT_LOCAL = 'ecsa-cpfm-data-sharing';

		/**
		 * Has the shared popup been answered either way?
		 *
		 * The inline checkbox stays hidden until it has: before that there is no
		 * meaningful default to show it.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function consent_answered() {
			return in_array( get_option( self::CONSENT_MASTER ), array( 'yes', 'no' ), true );
		}

		/**
		 * Should THIS plugin share usage data?
		 *
		 * Two levels, matching the rest of the Events Addons family: the shared
		 * popup sets the master for every addon at once, and this plugin's own
		 * checkbox may override it — turning that off stops OUR data and nobody
		 * else's. With no local choice recorded the master is inherited, so an
		 * addon installed after the user opted in is on, which is what it is.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function data_sharing_enabled() {
			$local = get_option( self::CONSENT_LOCAL );

			if ( in_array( $local, array( 'yes', 'no' ), true ) ) {
				return ( 'yes' === $local );
			}

			return ( 'yes' === get_option( self::CONSENT_MASTER ) );
		}

		/**
		 * Fallback settings-page slug.
		 *
		 * Mirrors `Admin\Settings\Settings_Page::MENU_SLUG`; used only when that
		 * class is not loadable.
		 */
		const FALLBACK_SETTINGS_SLUG = 'ecsa-settings';

		/**
		 * The settings-page menu slug.
		 *
		 * Single accessor for a value that is part of a deep-link contract with
		 * the shared ECA dashboard, the plugins-list Settings action and the
		 * CPFM notice page list. Reading it from one place means changing
		 * `Settings_Page::MENU_SLUG` moves every consumer together instead of
		 * leaving the dashboard's "Open settings" CTA pointing at a dead page.
		 *
		 * @since 2.0.0
		 * @return string
		 */
		public static function settings_slug() {
			if ( class_exists( __NAMESPACE__ . '\Admin\Settings\Settings_Page' ) ) {
				return Admin\Settings\Settings_Page::MENU_SLUG;
			}

			return self::FALLBACK_SETTINGS_SLUG;
		}

		/**
		 * Register the shortcode, the widget wrapper's dependencies and assets.
		 *
		 * v2 is now the only render engine. The retired v1.3.6 render shim
		 * (`ecsa-functions.php`) and its unbounded, unauthenticated
		 * `admin-ajax.php?action=ecsa_search_data` catalogue-dump handler are
		 * deleted outright — no page on a v2 site ever called them, and keeping
		 * the AJAX action registered was a standing DoS / catalogue-dump vector.
		 * The classic widget wrapper is still required: it renders the v2 bar.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function boot_render_path() {
			self::require_if_exists( 'includes/ecsa-widget.php' );

			if ( class_exists( __NAMESPACE__ . '\Render\Assets' ) ) {
				Render\Assets::init();
			}

			if ( class_exists( __NAMESPACE__ . '\Shortcode\Shortcode' ) ) {
				Shortcode\Shortcode::init();
			}
		}

		/**
		 * Boot the query spine: REST routes and cache invalidation.
		 *
		 * Deliberately NOT inside `is_admin()`. `rest_api_init` fires on
		 * requests where `is_admin()` is false, so registering routes from the
		 * admin branch would make the entire public API 404 — and the cache
		 * invalidation hooks must fire wherever a post is saved, including
		 * during a REST or WP-CLI write.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function boot_query_spine() {
			if ( class_exists( __NAMESPACE__ . '\Rest\Rest_Controller' ) ) {
				Rest\Rest_Controller::init();
			}

			// Admin-only settings preview. Registered here (not in the admin
			// branch) for the same reason as the public routes: `rest_api_init`
			// fires on requests where `is_admin()` is false. The route itself is
			// gated on `manage_options`.
			if ( class_exists( __NAMESPACE__ . '\Rest\Preview_Controller' ) ) {
				Rest\Preview_Controller::init();
			}

			if ( class_exists( __NAMESPACE__ . '\Query\Cache' ) ) {
				Query\Cache::init();
			}
		}

		/**
		 * Boot the TEC List-view filter-bar integration.
		 *
		 * Deferred to `init` for two reasons: the feature gate reads the
		 * paid-Filter-Bar signal and `Tec::available()`, both of which only
		 * settle after `plugins_loaded`; and `Tec_Views::init()` registers the
		 * `tribe_context_locations` filter, which MUST be in place before the
		 * first `Context::get_locations()` (TEC applies that filter once, then
		 * removes every callback). `init` satisfies both — it is after all
		 * plugins have loaded, and well before the front-end views resolve their
		 * context at render time.
		 *
		 * Guarded with `require_if_exists` + `class_exists` so a partially
		 * deployed tree degrades to "no integration" rather than fatalling
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function boot_tec_views() {
			add_action( 'init', array( $this, 'boot_tec_views_on_init' ), 9 );
		}

		/**
		 * Load and initialise the TEC List-view integration on `init`.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function boot_tec_views_on_init() {
			self::require_if_exists( 'includes/display/class-ecsa-tec-views.php' );

			if ( class_exists( __NAMESPACE__ . '\Display\Tec_Views' ) ) {
				Display\Tec_Views::init();
			}
		}

		/**
		 * Require every cpfm-feedback module's class definition, once.
		 *
		 * Loaded on every request (Cool Timeline pattern) so CPFM_Usage_Cron
		 * exists when wp-cron.php fires. Also used from activate() before
		 * plugins_loaded. Safe to call more than once — CPFM_Loader is
		 * idempotent.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function boot_cpfm_framework() {
			if ( ! class_exists( 'CPFM_Loader' ) ) {
				$file = ECSA_PATH . 'admin/cpfm-feedback/class-cpfm-loader.php';
				if ( ! file_exists( $file ) ) {
					return;
				}
				require_once $file;
			}
			if ( class_exists( 'CPFM_Loader' ) ) {
				\CPFM_Loader::load();
			}
		}

		/**
		 * Register the shared usage-data cron (admin + WP-Cron).
		 *
		 * Cool Timeline pattern: static once-guard so repeated init / opt-in
		 * / activation paths never double-attach the hook.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_cpfm_usage_cron() {
			static $usage_cron_registered = false;

			if ( $usage_cron_registered || ! class_exists( 'CPFM_Usage_Cron' ) ) {
				return;
			}

			$usage_cron_registered = true;

			\CPFM_Usage_Cron::cpfm_register( self::usage_cron_config() );
		}

		/**
		 * This plugin's own config for the shared usage cron.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		private static function usage_cron_config() {
			return array(
				'id'                      => 'ecsa',
				/*
				 * DO NOT change this string when the plugin is renamed. It is the
				 * key the receiving endpoint aggregates on; renaming it would
				 * split this plugin's reporting history into two unrelated series.
				 */
				'plugin_name'             => 'The Events Calendar Search Addon',
				'version'                 => ECSA_VERSION,
				'api'                     => ECSA_FEEDBACK_API,
				'cron_hook'               => 'ecsa_extra_data_update',
				'consent_master_option'   => self::CONSENT_MASTER,
				'consent_override_option' => self::CONSENT_LOCAL,
				'install_date_option'     => 'ecsa-install-date',
				'initial_version_option'  => 'ecsa_initial_save_version',
				'site_key'                => '29',
			);
		}

		/**
		 * Register the legacy classic widget.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_widget() {
			if ( class_exists( 'EventsCalendarSearchAddonWidget' ) ) {
				register_widget( 'EventsCalendarSearchAddonWidget' );
			}
		}

		/**
		 * Load the shared Cool Plugins feedback modules.
		 *
		 * Runs on `plugins_loaded` 10. Every contract touched here is
		 * cross-plugin and must stay byte-exact: the `cool_events` category,
		 * the `cpfm_opt_in_choice_cool_events` option and the
		 * `cpfm_after_opt_in_ecsa` hook are shared with the sibling addons.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function boot_shared_modules() {
			// Shared deactivation-feedback modal. Deferred to init 1: config
			// carries translated strings (WP 6.7 JIT-textdomain notice).
			if ( is_admin() ) {
				add_action( 'init', array( $this, 'register_deactivation_feedback' ), 1 );
			}

			add_action( 'cpfm_register_notice', array( $this, 'register_cpfm_notice' ), 10 );
			add_action( 'cpfm_after_opt_in_ecsa', array( $this, 'send_data_after_opt_in' ), 10, 1 );
			add_action( 'cpfm_after_opt_out_ecsa', array( $this, 'clear_data_after_opt_out' ), 10, 1 );
		}

		/**
		 * Register this plugin's deactivation survey with the shared framework.
		 *
		 * Hooked to `init` 1. Every string below is passed ALREADY TRANSLATED:
		 * the module never calls __() on our behalf, because a shared module that
		 * did would bind these strings to whichever plugin happened to vendor it.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_deactivation_feedback() {
			if ( ! class_exists( 'CPFM_Deactivation_Feedback' ) ) {
				return;
			}

			\CPFM_Deactivation_Feedback::cpfm_register(
				array(
					'id'          => 'ecsa',
					'slug'        => 'events-search-addon-for-the-events-calendar',
					'plugin_name' => 'Events Search & Filterbar',
					'version'     => ECSA_VERSION,
					'api'         => ECSA_FEEDBACK_API,
					'site_key'    => 29,

					// OUR option names — the shared module must not guess them.
					'install_date_option'    => 'ecsa-install-date',
					'initial_version_option' => 'ecsa_initial_save_version',

					'reasons'                => array(
						'not_working'  => array(
							'title'       => __( "The plugin isn't working", 'events-search-addon-for-the-events-calendar' ),
							'placeholder' => __( 'Which problem did you run into? We read every reply.', 'events-search-addon-for-the-events-calendar' ),
						),
						'not_expected' => array(
							'title'       => __( "It didn't do what I expected", 'events-search-addon-for-the-events-calendar' ),
							'placeholder' => __( 'What were you hoping it would do?', 'events-search-addon-for-the-events-calendar' ),
						),
						'found_better' => array(
							'title'       => __( 'I found a better plugin', 'events-search-addon-for-the-events-calendar' ),
							'placeholder' => __( 'Mind sharing which one?', 'events-search-addon-for-the-events-calendar' ),
						),
						'temporary'    => array(
							'title'       => __( "It's a temporary deactivation", 'events-search-addon-for-the-events-calendar' ),
							'placeholder' => '',
						),
						'other'        => array(
							'title'       => __( 'Another reason', 'events-search-addon-for-the-events-calendar' ),
							'placeholder' => __( 'Please tell us more', 'events-search-addon-for-the-events-calendar' ),
						),
					),

					'i18n'                   => array(
						'title'        => __( 'Before you go…', 'events-search-addon-for-the-events-calendar' ),
						/* translators: %s: plugin name (bold). */
						'intro'        => __( 'What made you deactivate %s? Your answer helps us fix it.', 'events-search-addon-for-the-events-calendar' ),
						'submit'       => __( 'Submit & Deactivate', 'events-search-addon-for-the-events-calendar' ),
						'skip'         => __( 'Skip & Deactivate', 'events-search-addon-for-the-events-calendar' ),
						'deactivating' => __( 'Deactivating…', 'events-search-addon-for-the-events-calendar' ),
						'pick_reason'  => __( 'Please choose a reason.', 'events-search-addon-for-the-events-calendar' ),
						'close_label'  => __( 'Close', 'events-search-addon-for-the-events-calendar' ),
						/* translators: %s: company name. */
						'byline'       => __( 'A plugin by %s', 'events-search-addon-for-the-events-calendar' ),
						// Must describe the FULL payload: submitting is deliberate
						// and is not consent-gated, so this line IS the disclosure.
						'consent'      => __( 'Submitting shares your reason plus your site URL, admin email and basic environment details (PHP, WordPress, active plugins). Skip & Deactivate sends nothing.', 'events-search-addon-for-the-events-calendar' ),
					),
				)
			);
		}

		/**
		 * Register the one-time "2.0 is here" notice with the shared framework.
		 *
		 * The gate option is set by Schema::maybe_upgrade() only when it detects
		 * legacy rows (an existing `ecsa-v`), so a fresh install never sees it.
		 *
		 * defer_screens covers our own panel: it renders the notice itself, just
		 * as it does the review ask, because letting admin_notices place it would
		 * drop it above our header.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_welcome_notice() {
			if ( ! class_exists( 'CPFM_Welcome_Notice' ) ) {
				return;
			}

			\CPFM_Welcome_Notice::cpfm_register(
				array(
					'id'             => 'ecsa',
					'option'         => Schema::WELCOME_OPTION,
					'settings_url'   => admin_url( 'admin.php?page=' . self::settings_slug() ),
					'screens'        => array( 'plugins', 'events-addons_page_ecsa-settings' ),
					'inline_screens' => array( 'events-addons_page_ecsa-settings' ),
					'defer_screens'  => array( 'events-addons_page_ecsa-settings' ),
					'i18n'           => array(
						'headline'    => __( 'Events Search & Filterbar 2.0 major update is here.', 'events-search-addon-for-the-events-calendar' ),
						'body'        => __( 'The plugin has been rewritten completely, with new features and designs.', 'events-search-addon-for-the-events-calendar' ),
						'cta'         => __( 'See new settings', 'events-search-addon-for-the-events-calendar' ),
						'dismiss'     => __( 'Dismiss', 'events-search-addon-for-the-events-calendar' ),
						'close_label' => __( 'Close', 'events-search-addon-for-the-events-calendar' ),
					),
				)
			);
		}

		/**
		 * Register this plugin with the shared review framework.
		 *
		 * Hooked to `init` 1, for the same translation-timing reason as above.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_review_ask() {
			if ( ! class_exists( 'CPFM_Review' ) ) {
				return;
			}

			$name = 'Events Search & Filterbar';

			\CPFM_Review::cpfm_register(
				array(
					'id'          => 'ecsa',
					'plugin_file' => ECSA_FILE,
					'plugin_name' => $name,
					'review_url'  => 'https://wordpress.org/support/plugin/events-search-addon-for-the-events-calendar/reviews/#new-post',
					'capability'  => 'activate_plugins',

					/*
					 * How long THIS plugin waits after a sibling addon showed its ask.
					 * Read from the config of the plugin that wants to ask, not the one
					 * that claimed the window, so keep it the same across the family or
					 * they will wait different amounts.
					 *
					 * The clock starts when the first notice RENDERS, not when it is
					 * answered - dismissing one addon does not release the window early,
					 * which is deliberate: someone who just declined a review request is
					 * the worst audience for another one straight after.
					 *
					 * Does not apply on own_screens (below): a plugin can always ask on
					 * its own settings page. 0 disables the throttle entirely.
					 */
					'quiet_days'  => 7,

					// Our own panel: exempt from the cross-plugin quiet period, so a
					// sibling's ask elsewhere never silences ours in our own UI.
					'own_screens' => array( 'events-addons_page_ecsa-settings' ),

					'trigger'     => array(
						'type'  => 'install_age',
						'hours' => 24,
					),

					'notice'      => array(
						'enabled'        => true,
						'template'       => 'two_step',
						'screens'        => array(
							'plugins',
							'edit-tribe_events',
							'events-addons_page_ecsa-settings',
						),
						// Only our own settings panel opts out of core's notice
						// relocation; on standard screens that relocation is right.
						'inline_screens' => array( 'events-addons_page_ecsa-settings' ),
						// This panel renders the notice itself, inside its own chrome
						// (see Settings_Page). Without this the admin_notices hook drops
						// it at the top of #wpbody-content, ABOVE our header.
						'defer_screens'  => array( 'events-addons_page_ecsa-settings' ),
					),
					'row'         => array( 'enabled' => true ),

					/*
					 * Legacy keys. ONLY a recorded dismissal silences the ask — a
					 * missing key, or one holding 'no', means the user never
					 * answered and is still a valid person to ask.
					 *
					 * ecsa-ratingDiv       the legacy flag. Matched on VALUE, not
					 *                      existence: it is seeded 'no' at install
					 *                      and set 'yes' only on dismissal, so
					 *                      accepting 'no' would silence everyone.
					 * eca_review_dismissed the shared dashboard's per-USER map,
					 *                      keyed by addon env key ('search').
					 * install dates        camelCase is the review clock; the
					 *                      hyphenated one is the feedback site_id
					 *                      source but stores a real date, so it is
					 *                      a sound fallback.
					 */
					'legacy'      => array(
						'done_options'   => array(
							'ecsa-ratingDiv' => 'yes',
						),
						'done_user_meta' => array(
							'eca_review_dismissed' => array( 'in_key' => 'search' ),
						),
						'install_dates'  => array( 'ecsa-installDate', 'ecsa-install-date' ),
						'mirror_write'   => array( 'ecsa-ratingDiv' => 'yes' ),
					),

					'i18n'        => array(
						'like_question' => sprintf(
							/* translators: %s: plugin name. */
							__( 'Do you like the %s plugin?', 'events-search-addon-for-the-events-calendar' ),
							$name
						),
						'yes_button'    => __( 'Yes, I like it', 'events-search-addon-for-the-events-calendar' ),
						'dismiss_link'  => __( 'Not good, dismiss', 'events-search-addon-for-the-events-calendar' ),
						'later_link'    => __( 'Ask me later', 'events-search-addon-for-the-events-calendar' ),
						'thanks_line'   => __( 'That is great to hear! A quick review on WordPress.org would really help us.', 'events-search-addon-for-the-events-calendar' ),
						'submit_button' => __( 'Submit review', 'events-search-addon-for-the-events-calendar' ),
						'no_link'       => __( 'I do not like it, dismiss', 'events-search-addon-for-the-events-calendar' ),
						'row_question'  => __( 'Do you like this plugin?', 'events-search-addon-for-the-events-calendar' ),
						'inline_title'  => sprintf(
							/* translators: %s: plugin name. */
							__( 'Enjoying %s?', 'events-search-addon-for-the-events-calendar' ),
							$name
						),
						'inline_text'   => __( 'A short review helps other event organisers find it.', 'events-search-addon-for-the-events-calendar' ),
						'close_label'   => __( 'Close', 'events-search-addon-for-the-events-calendar' ),
					),
				)
			);
		}

		/**
		 * Register this addon's consent notice with the shared CPFM module.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_cpfm_notice() {
			if ( ! class_exists( 'CPFM_Feedback_Notice' ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$notice = array(
				'title'          => __( 'Events Addons By Cool Plugins', 'events-search-addon-for-the-events-calendar' ),
				'message'        => __( 'Help us make this plugin more compatible with your site by sharing non-sensitive site data. ', 'events-search-addon-for-the-events-calendar' ),
				'pages'          => array( 'cool-plugins-events-addon', self::settings_slug() ),
				// Includes our own settings page so the panel auto-opens there, the
				// same way Event Countdown does on its screen.
				'always_show_on' => array( 'cool-plugins-events-addon', self::settings_slug() ),
				'plugin_name'    => 'ecsa',

				// Panel-wide chrome (header, consent copy, buttons) - only the
				// FIRST plugin to register ever supplies this; see
				// CPFM_Feedback_Notice's own $panel_i18n docblock for why it
				// must come from the host and never be hardcoded in that
				// shared file.
				'i18n'           => array(
					'panel_title'         => __( 'Help Improve Plugins', 'events-search-addon-for-the-events-calendar' ),
					'more_info'           => __( 'More info', 'events-search-addon-for-the-events-calendar' ),
					'consent_intro'       => __( 'Opt in to receive email updates about security improvements, new features, helpful tutorials, and occasional special offers. We\'ll collect:', 'events-search-addon-for-the-events-calendar' ),
					'consent_item_site'   => __( 'Your website home URL and WordPress admin email.', 'events-search-addon-for-the-events-calendar' ),
					'consent_item_compat' => __( 'To check plugin compatibility, we will collect the following: list of active plugins and themes, PHP, MySQL and WordPress versions, memory limit, whether the site is multisite, and the site language. ', 'events-search-addon-for-the-events-calendar' ),
					'consent_link'        => __( 'Click here', 'events-search-addon-for-the-events-calendar' ),
					'yes_label'           => __( "Yes, it's OK", 'events-search-addon-for-the-events-calendar' ),
					'no_label'            => __( 'No, Thanks', 'events-search-addon-for-the-events-calendar' ),
				),
			);

			\CPFM_Feedback_Notice::cpfm_register_notice( 'cool_events', $notice );

			if ( ! isset( $GLOBALS['cool_plugins_feedback'] ) ) {
				$GLOBALS['cool_plugins_feedback'] = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared ecosystem global.
			}

			$GLOBALS['cool_plugins_feedback']['cool_events'][] = $notice; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared ecosystem global.
		}

		/**
		 * Send the usage payload immediately after an explicit opt-in.
		 *
		 * @since 2.0.0
		 * @param string $category Consent category.
		 * @return void
		 */
		public function send_data_after_opt_in( $category ) {
			if ( 'cool_events' !== $category ) {
				return;
			}
			// Deliberately does NOT touch CONSENT_LOCAL: a per-plugin opt-out
			// survives shared popup changes.

			$this->register_cpfm_usage_cron();

			// Defer the send to cron so a slow feedback endpoint never blocks
			// the user's opt-in click.
			if ( class_exists( 'CPFM_Usage_Cron' ) ) {
				\CPFM_Usage_Cron::cpfm_schedule_event( 'ecsa_extra_data_update' );
			}
			if ( ! wp_next_scheduled( 'ecsa_extra_data_update' ) ) {
				wp_schedule_single_event( time(), 'ecsa_extra_data_update' );
			}
		}

		/**
		 * React to a shared opt-OUT: stop this plugin's cron immediately.
		 *
		 * @since 2.0.0
		 * @param string $category CPFM consent category.
		 * @return void
		 */
		public function clear_data_after_opt_out( $category ) {
			if ( 'cool_events' !== $category ) {
				return;
			}
			// Deliberately does NOT touch CONSENT_LOCAL (see send_data_after_opt_in).
			if ( wp_next_scheduled( 'ecsa_extra_data_update' ) ) {
				wp_clear_scheduled_hook( 'ecsa_extra_data_update' );
			}
		}

		/**
		 * Boot admin-only modules.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private function boot_admin() {
			if ( class_exists( __NAMESPACE__ . '\Admin\Settings\Settings_Page' ) ) {
				Admin\Settings\Settings_Page::init();
			}

			// Review / welcome via shared CPFM. Deferred to init 1 for WP 6.7
			// JIT-textdomain notice (config carries translated strings).
			add_action( 'init', array( $this, 'register_review_ask' ), 1 );
			add_action( 'init', array( $this, 'register_welcome_notice' ), 1 );

			if ( class_exists( __NAMESPACE__ . '\Admin\Notices' ) ) {
				Admin\Notices::init();
			}

			if ( ! class_exists( 'ECSA_ECA_Integration' ) ) {
				self::require_if_exists( 'admin/class-ecsa-eca-integration.php' );
			}

			if ( class_exists( 'ECSA_ECA_Integration' ) ) {
				\ECSA_ECA_Integration::boot_admin();
			}

			add_filter( 'plugin_action_links_' . plugin_basename( ECSA_FILE ), array( $this, 'plugin_action_links' ), 10, 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_cpfm_admin_assets' ), 10 );
		}

		/**
		 * Enqueue the shared CPFM consent scripts on our settings page only.
		 *
		 * @since 2.0.0
		 * @param string $hook_suffix Current admin page hook.
		 * @return void
		 */
		public function enqueue_cpfm_admin_assets( $hook_suffix = '' ) {
			if ( ! class_exists( __NAMESPACE__ . '\Admin\Settings\Settings_Page' )
				|| ! Admin\Settings\Settings_Page::is_settings_screen( $hook_suffix ) ) {
				return;
			}

			wp_enqueue_script( 'cpfm-settings-data-share', ECSA_URL . 'admin/cpfm-feedback/js/cpfm-admin-share-data.js', array( 'jquery' ), ECSA_VERSION, true );
			wp_enqueue_script( 'cpfm-setting-js', ECSA_URL . 'admin/cpfm-feedback/js/cpfm-setting.js', array( 'jquery' ), ECSA_VERSION, true );
			wp_localize_script(
				'cpfm-setting-js',
				'cpfm_ajax_obj',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'cpfm_nonce_action' ),
				)
			);
		}

		/**
		 * Add a Settings link to the plugins-list row.
		 *
		 * @since 2.0.0
		 * @param string[] $links Existing action links.
		 * @return string[]
		 */
		public function plugin_action_links( $links ) {
			$settings = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::settings_slug() ) ),
				esc_html__( 'Settings', 'events-search-addon-for-the-events-calendar' )
			);

			array_unshift( $links, $settings );

			/*
			 * Get Pro, last in the row and styled to be found.
			 *
			 * The colour is inline rather than enqueued: this is two links on
			 * one core screen, and a stylesheet loaded on plugins.php for that
			 * would cost every admin a request to style someone else's table.
			 * `!important` because several admin themes colour
			 * `.plugins a` from an id-level selector.
			 *
			 * ECSA_PRO_URL is guarded like every other constant this tree
			 * reads, so a partially deployed tree simply shows no link.
			 */
			if ( defined( 'ECSA_PRO_URL' ) ) {
				// Re-tag the campaign for THIS surface: the constant is written
				// for the settings panel's locked cards, and leaving it would
				// report every plugins-row click as a locked-card click.
				$pro_url = add_query_arg( 'utm_content', 'plugins_row', ECSA_PRO_URL );

				$links[] = sprintf(
					'<a href="%s" target="_blank" rel="noopener" style="color:#d63638!important;font-weight:700;">%s</a>',
					esc_url( $pro_url ),
					esc_html__( 'Get Pro', 'events-search-addon-for-the-events-calendar' )
				);
			}

			return $links;
		}

		/**
		 * Load translations and seed the install-tracking options.
		 *
		 * Runs on `init`: WordPress 6.7+ emits a notice when a text domain is
		 * used earlier than this.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function load_textdomain() {
			/*
			 * v1.3.6 loaded the domain as 'ecsa', which does not match the
			 * `Text Domain:` header — every translated string in the plugin
			 * silently fell back to English. Corrected here.
			 */
			load_plugin_textdomain(
				'events-search-addon-for-the-events-calendar',
				false,
				dirname( plugin_basename( ECSA_FILE ) ) . '/languages/'
			); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- retained for non-wp.org installs.

			if ( ! get_option( 'ecsa_initial_save_version' ) ) {
				add_option( 'ecsa_initial_save_version', ECSA_VERSION );
			}

			/*
			 * `H`, not `h`. Both install-date rows are consumed by
			 * `CPFM_Review::legacy_install_time()`, which parses them with
			 * `strtotime( $raw . ' UTC' )`. A 12-hour hour with no meridiem
			 * round-trips 18:30 as 06:30, so the review ask would come due up
			 * to twelve hours early — the same 12-hour class of defect this
			 * rewrite exists to retire. Written only when absent, so no
			 * existing row is rewritten and no site_id churns.
			 */
			if ( ! get_option( 'ecsa-install-date' ) ) {
				add_option( 'ecsa-install-date', gmdate( 'Y-m-d H:i:s' ) );
			}
		}

		/**
		 * Activation routine.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function activate() {
			/*
			 * ORDER IS LOAD-BEARING: Schema::maybe_upgrade() runs FIRST.
			 *
			 * It distinguishes "upgraded from 1.3.x" from "fresh install" by
			 * testing whether the legacy `ecsa-v` row already exists. Seeding
			 * the legacy rows before calling it makes that test always true, so
			 * every brand-new install would be flagged as an upgrade and would
			 * later receive the one-time "what changed since 1.3.x" notice —
			 * addressed to a user who has never run the plugin before.
			 */
			Schema::maybe_upgrade();

			// Legacy rows: seeded only when absent, never overwritten, so a
			// re-activation after a downgrade does not clobber real values.
			add_option( 'ecsa-v', ECSA_VERSION );
			add_option( 'ecsa-type', defined( 'ECSAP_VERSION' ) ? 'PRO' : 'FREE' );
			add_option( 'ecsa-installDate', gmdate( 'Y-m-d H:i:s' ) );
			add_option( 'ecsa-ratingDiv', 'no' );
			add_option( 'ecsa_initial_save_version', ECSA_VERSION );
			add_option( 'ecsa-install-date', gmdate( 'Y-m-d H:i:s' ) );

			/*
			 * ONE-SHOT: send the admin to the settings screen after the FIRST
			 * activation only.
			 *
			 * TWO ROWS, and it needs both. The trigger has to be DELETED when it
			 * fires, or a failure downstream would leave the site bouncing to one
			 * screen forever — so the trigger cannot also be the record that the
			 * trip already happened. Relying on `add_option` alone re-armed on
			 * every reactivation, because by then the row it tested was gone.
			 *
			 * `ecsa_activation_redirect_done` is that record, written when the
			 * redirect fires and never removed. Clearing the plugin's rows resets
			 * both, which is what makes a fresh install feel like one.
			 *
			 * OPTIONS, not transients: a transient can be evicted by an object
			 * cache before `admin_init` runs, which would drop the redirect on
			 * exactly the busy sites most likely to have one.
			 */
			if ( ! get_option( 'ecsa_activation_redirect_done' ) ) {
				add_option( 'ecsa_activation_redirect', '1' );
			}

			/*
			 * Schedule the usage cron only with recorded consent. The handler
			 * re-checks consent on every run regardless — scheduling is not
			 * treated as standing permission.
			 */
			if ( self::data_sharing_enabled() ) {
				// On the activation request plugins_loaded already fired without
				// us, so the framework (and the cron class's `every_30_days`
				// schedule filter) must be loaded here before scheduling.
				self::boot_cpfm_framework();
				if ( class_exists( 'CPFM_Usage_Cron' ) ) {
					\CPFM_Usage_Cron::cpfm_register( self::usage_cron_config() );
				}

				if ( ! wp_next_scheduled( 'ecsa_extra_data_update' ) ) {
					wp_schedule_event( time(), 'every_30_days', 'ecsa_extra_data_update' );
				}
			}
		}

		/**
		 * Deactivation routine. Clears schedules only — never deletes data.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function deactivate() {
			wp_clear_scheduled_hook( 'ecsa_extra_data_update' );
		}

		/**
		 * Send the admin to the settings screen once, after a first activation.
		 *
		 * The flag is consumed BEFORE anything else can fail, so no site can end
		 * up permanently bouncing to one screen.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function maybe_activation_redirect() {
			if ( '1' !== get_option( 'ecsa_activation_redirect' ) ) {
				return;
			}

			/*
			 * THE GUARDS COME FIRST, and that ordering is the fix for a real bug.
			 *
			 * Consuming the trigger before testing these meant ANY background admin
			 * request burned it: the heartbeat fires admin-ajax within seconds of
			 * login, hits this, deletes the row and returns at the ajax guard — so
			 * the admin lands on the dashboard and the one-shot is already spent.
			 * It survived the first test only because nothing happened to poll
			 * before the page loaded.
			 *
			 * Nothing below can loop: the row is deleted on the same request that
			 * redirects, and the destination is an ordinary admin screen.
			 */
			if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// A real admin page load. Consume, and record that the offer was made.
			delete_option( 'ecsa_activation_redirect' );
			update_option( 'ecsa_activation_redirect_done', '1', false );

			/*
			 * NOT during a bulk activation. `activate-multi` means several plugins
			 * were ticked at once; stealing the page would strand the admin away
			 * from the list and hide whatever the others had to say. Consumed above
			 * regardless, so this is a redirect forfeited rather than one that waits
			 * to ambush them on a later request.
			 */
			if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=ecsa-settings' ) );
			exit;
		}
	}
}
