<?php
/**
 * Plugin Name: The Events Calendar Search Addon
 * Description: A simple events search box to find any event quickly for The Events Calendar Free Plugin (by MODERN TRIBE) - <strong>[events-calendar-search placeholder="Search Events" show-events="5" disable-past-events="false" layout="medium" content-type="advance" ]</strong>
 * Plugin URI: https://eventscalendaraddons.com/
 * Version: 1.3.6
 * Requires PHP: 5.6
 * Author: Cool Plugins
 * Author URI: https://coolplugins.net/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=author_page&utm_content=plugins_list
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: events-search-addon-for-the-events-calendar
 * Domain Path: /languages
 * Requires Plugins: the-events-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'ECSA_VERSION' ) ) {
	return;
}

define( 'ECSA_VERSION', '1.3.6' );
define( 'ECSA_FILE', __FILE__ );
define( 'ECSA_PATH', plugin_dir_path( ECSA_FILE ) );
define( 'ECSA_URL', plugin_dir_url( ECSA_FILE ) );
define( 'ECSA_FEEDBACK_API', 'https://feedback.coolplugins.net/' );

register_activation_hook( ECSA_FILE, array( 'EventsCalendarSearchAddon', 'ecsa_activate' ) );
register_deactivation_hook( ECSA_FILE, array( 'EventsCalendarSearchAddon', 'ecsa_deactivate' ) );


/*
|--------------------------------------------------------------------------
|  Class EventsCalendarSearchAddon
|--------------------------------------------------------------------------
*/
if ( ! class_exists( 'EventsCalendarSearchAddon' ) ) :
	//phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
	final class EventsCalendarSearchAddon {

		private static $instance = null;

		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}


		/*
		|--------------------------------------------------------------------------
		|  Add action and shortcode.
		|--------------------------------------------------------------------------
		*/
		private function __construct() {
			$this->ecsa_include_files();
			add_action( 'init', array( $this, 'ecsa_load_textdomain' ) );
			add_action( 'plugins_loaded', array( $this, 'ecsa_check_event_calender_installed' ) );
			add_action( 'plugins_loaded', array( $this, 'includes' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'ecsa_register_scripts' ) );
			add_shortcode( 'events-calendar-search', array( $this, 'ecsa_shortcode' ) );
			add_action( 'wp_ajax_ecsa_search_data', 'ecsa_get_searchdata' );
			add_action( 'wp_ajax_nopriv_ecsa_search_data', 'ecsa_get_searchdata' );
			add_action('admin_print_scripts', [$this, 'ect_hide_unrelated_notices']);
		}


		public function ect_hide_unrelated_notices(){ 
			
			// phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded, Generic.Metrics.NestingLevel.MaxExceeded
            $events_pages = false;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page parameter to conditionally hide notices, no data processing
            if (isset($_GET['page'])) {
				
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page parameter to conditionally hide notices, no data processing
				$page_param = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

				$allowed_pages = array(
					'cool-plugins-events-addon',
					'cool-events-registration',
					'tribe-events-shortcode-template-settings',
					'tribe_events-events-template-settings',
					'countdown_for_the_events_calendar',
					'esas-speaker-sponsor-settings',
					'esas_speaker',
					'esas_sponsor',
					'ewpe',
					'epta'
				);

				if (in_array($page_param, $allowed_pages, true)) {
					$events_pages = true;
				}
            }
			$is_post_type_page = false;

			$current_screen = get_current_screen();
			
			if ( $current_screen && ! empty( $current_screen->post_type ) ) {
			
				$allowed_post_types = array(
					'esas_speaker',
					'esas_sponsor',
					'epta',
					'ewpe'
				);
			
				if ( in_array( $current_screen->post_type, $allowed_post_types, true ) ) {
					$is_post_type_page = true;
				}
			}
            if ($events_pages) {
                global $wp_filter;
                // Define rules to remove callbacks.
                $rules = [
                    'user_admin_notices' => [], // remove all callbacks.
                    'admin_notices'      => [],
                    'all_admin_notices'  => [],
                    'admin_footer'       => [
                        'render_delayed_admin_notices', // remove this particular callback.
                    ],
                ];
                $notice_types = array_keys($rules);
                foreach ($notice_types as $notice_type) {
                    if (empty($wp_filter[$notice_type]) || empty($wp_filter[$notice_type]->callbacks) || ! is_array($wp_filter[$notice_type]->callbacks)) {
                        continue;
                    }
                    $remove_all_filters = empty($rules[$notice_type]);
                    foreach ($wp_filter[$notice_type]->callbacks as $priority => $hooks) {
                        foreach ($hooks as $name => $arr) {
                            if (is_object($arr['function']) && is_callable($arr['function'])) {
                                if ($remove_all_filters) {
                                    unset($wp_filter[$notice_type]->callbacks[$priority][$name]);
                                }
                                continue;
                            }
                            $class = ! empty($arr['function'][0]) && is_object($arr['function'][0]) ? strtolower(get_class($arr['function'][0])) : '';
                            // Remove all callbacks except WPForms notices.
                            if ($remove_all_filters && strpos($class, 'wpforms') === false) {
                                unset($wp_filter[$notice_type]->callbacks[$priority][$name]);
                                continue;
                            }
                            $cb = is_array($arr['function']) ? $arr['function'][1] : $arr['function'];
                            // Remove a specific callback.
                            if (! $remove_all_filters) {
                                if (in_array($cb, $rules[$notice_type], true)) {
                                    unset($wp_filter[$notice_type]->callbacks[$priority][$name]);
                                }
                                continue;
                            }
                        }
                    }
                }
            }

			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			if (!$events_pages && !$is_post_type_page) {

				// ✅ GLOBAL LOCK SYSTEM
				if (!defined('ECT_ADMIN_NOTICE_HOOKED')) {

					define('ECT_ADMIN_NOTICE_HOOKED', true);

					add_action(
						'admin_notices',
						array($this, 'ect_dash_admin_notices'),
						PHP_INT_MAX
					);
				}
			}
        }
	
		public function ect_dash_admin_notices() {

			// ✅ Double render protection
			if (defined('ECT_ADMIN_NOTICE_RENDERED')) {
				return;
			}

			define('ECT_ADMIN_NOTICE_RENDERED', true);

			do_action('ect_display_admin_notices');
		}

		public function ecsa_include_files(){
			require_once ECSA_PATH . 'admin/cpfm-feedback/cron/class-cron.php';
		}

		/*
		|--------------------------------------------------------------------------
		|  Check The Event calender is installled or not. If user has not installed yet then show notice
		|--------------------------------------------------------------------------
		*/

		public function ecsa_check_event_calender_installed() {
			if (is_admin()) {
				require_once ECSA_PATH . '/admin/feedback/admin-feedback-form.php';
			}

			if(!class_exists('CPFM_Feedback_Notice')){
				require_once ECSA_PATH . 'admin/cpfm-feedback/cpfm-feedback-notice.php';
			}
	
			add_action('cpfm_register_notice', function () {
			
				if (!class_exists('CPFM_Feedback_Notice') || !current_user_can('manage_options')) {
					return;
				}
				$notice = [
					'title' => __('Cool Plugins Events Addons', 'events-search-addon-for-the-events-calendar'),
					'message' => __('Help us make this plugin more compatible with your site by sharing non-sensitive site data.', 'events-search-addon-for-the-events-calendar'),
					'pages' => ['cool-plugins-events-addon'],
					'always_show_on' => ['cool-plugins-events-addon'], // This enables auto-show
					'plugin_name'=>'ecsa',
					
				];
	
				CPFM_Feedback_Notice::cpfm_register_notice('cool_events', $notice);
	
					if (!isset($GLOBALS['cool_plugins_feedback'])) {
						$GLOBALS['cool_plugins_feedback'] = [];//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
					}
				
					$GLOBALS['cool_plugins_feedback']['cool_events'][] = $notice;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		   
			});
			add_action('cpfm_after_opt_in_ecsa', function($category) {
	
				if ($category === 'cool_events') {
					ECSA_cronjob::ecsa_send_data();
				}
			});
		}


		/*
		|--------------------------------------------------------------------------
		| Events Search Addon Shortcode
		|--------------------------------------------------------------------------
		*/
		public function ecsa_shortcode( $attributes, $content = null ) {
			$attributes = shortcode_atts(
				array(
					'placeholder'         => '',
					'show-events'         => '',
					'disable-past-events' => '',
					'content-type'           => '',
					'layout'              => '',
				),
				$attributes,
				'ecsa'
			);

			$placeholder  = isset( $attributes['placeholder'] ) && '' !== $attributes['placeholder'] ? sanitize_text_field( $attributes['placeholder'] ) : __( 'Search Events', 'events-search-addon-for-the-events-calendar' );
            $show_events  = isset( $attributes['show-events'] ) ? absint( $attributes['show-events'] ) : 10;
            $disable_past = isset( $attributes['disable-past-events'] ) ? sanitize_key( $attributes['disable-past-events'] ) : 'false';
            $layout       = isset( $attributes['layout'] ) && in_array( $attributes['layout'], array( 'small', 'medium', 'large' ), true ) ? sanitize_key( $attributes['layout'] ) : 'medium';
            $content_type = isset( $attributes['content-type'] ) && in_array( $attributes['content-type'], array( 'basic', 'advance' ), true ) ? $attributes['content-type'] : 'advance';
		
			$generate_html = ecsa_generate_html( $placeholder, $show_events, $disable_past, $content_type, $layout );
		
			return $generate_html;

		}


		/*
		|--------------------------------------------------------------------------
		| Register scripts and styles
		|--------------------------------------------------------------------------
		*/
		public function ecsa_register_scripts() {
			if ( ! is_admin() ) {
				$nonceVal= wp_create_nonce('ajax-nonce');
				wp_register_style( 'ecsa-styles', ECSA_URL . 'assets/css/ecsa-styles.min.css', array(), ECSA_VERSION, 'all' );
				wp_register_script( 'ecsa-typeahead', ECSA_URL . 'assets/js/typeahead.bundle.min.js', array( 'jquery' ), ECSA_VERSION, true );
				wp_register_script( 'ecsa-handlebars', ECSA_URL . 'assets/js/handlebars-v4.0.11.js', array( 'jquery' ), ECSA_VERSION, true );
				wp_register_script( 'ecsa-script', ECSA_URL . 'assets/js/ecsa-script.min.js', array( 'jquery' ), ECSA_VERSION, true );
				wp_localize_script(
					'ecsa-script',
					'ecsaSearch',
					array(
						'prefetchUpcomingUrl' => admin_url( 'admin-ajax.php?action=ecsa_search_data&display=upcoming&nonce_val='.$nonceVal ),
						'prefetchPastUrl'     => admin_url( 'admin-ajax.php?action=ecsa_search_data&display=past&nonce_val='.$nonceVal ),
						
					)
				);
				wp_register_style( 'ecsa-styles', ECSA_URL . 'assets/css/ecsa-styles.min.css', false, 'all' );

			}
		}
		/*
		|----------------------------------------------------------------------------
		| Loads the plugin's translated strings.
		|----------------------------------------------------------------------------
		*/
		public function ecsa_load_textdomain() {
			load_plugin_textdomain( 'ecsa', false, basename( dirname( __FILE__ ) ) . '/languages/' );//phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
			
			if (!get_option( 'ecsa_initial_save_version' ) ) {
                add_option( 'ecsa_initial_save_version', ECSA_VERSION );
            }

            if(!get_option( 'ecsa-install-date' ) ) {
                add_option( 'ecsa-install-date', gmdate('Y-m-d h:i:s') );
            }
		}

		/*
		|----------------------------------------------------------------------------
		| Load plugin function files here.
		|----------------------------------------------------------------------------
		*/
		public function includes() {
			if ( is_admin() ) {

				require_once __DIR__ . '/admin/events-addon-page/events-addon-page.php';
				cool_plugins_events_addon_settings_page( 'the-events-calendar', 'cool-plugins-events-addon', '📅 Events Addons For The Events Calendar' );

				require_once __DIR__ . '/admin/feedback-notice/ecsa-feedback-notice.php';
				new ecsaFeedbackNotice();
			}
			require_once __DIR__ . '/includes/ecsa-functions.php';
			require_once __DIR__ . '/includes/ecsa-widget.php';
		}

		/*
		|----------------------------------------------------------------------------
		| Run when activate plugin.
		|----------------------------------------------------------------------------
		*/
		public static function ecsa_activate() {
			update_option( 'ecsa-v', ECSA_VERSION );
			update_option( 'ecsa-type', 'FREE' );
			update_option( 'ecsa-installDate', gmdate( 'Y-m-d h:i:s' ) );
			update_option( 'ecsa-ratingDiv', 'no' );

			$review_option = get_option("cpfm_opt_in_choice_cool_events");

			if ($review_option === 'yes') {
				if (!wp_next_scheduled('ecsa_extra_data_update')) {

					wp_schedule_event(time(), 'every_30_days', 'ecsa_extra_data_update');

				}
			}

			if (!get_option( 'ecsa_initial_save_version' ) ) {
                add_option( 'ecsa_initial_save_version', ECSA_VERSION );
            }

            if(!get_option( 'ecsa-install-date' ) ) {
                add_option( 'ecsa-install-date', gmdate('Y-m-d h:i:s') );
            }
		}


		/*
		|----------------------------------------------------------------------------
		| Run when de-activate plugin.
		|----------------------------------------------------------------------------
		*/
		public static function ecsa_deactivate() {
			if (wp_next_scheduled('ecsa_extra_data_update')) {
				wp_clear_scheduled_hook('ecsa_extra_data_update');
			}
		}

	}
//phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	function EventsCalendarSearchAddon() {
		return EventsCalendarSearchAddon::get_instance();
	}

	$GLOBALS['EventsCalendarSearchAddon'] = EventsCalendarSearchAddon();//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

endif;
