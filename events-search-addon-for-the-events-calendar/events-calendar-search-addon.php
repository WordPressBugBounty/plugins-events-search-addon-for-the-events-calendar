<?php
/**
 * Plugin Name: The Events Calendar Search Addon
 * Description: A simple events search box to find any event quickly for The Events Calendar Free Plugin (by MODERN TRIBE) - <strong>[events-calendar-search placeholder="Search Events" show-events="5" disable-past-events="false" layout="medium" content-type="advance" ]</strong>
 * Plugin URI: https://eventscalendaraddons.com/
 * Version: 1.2.17
 * Requires at least: 5.0
 * Tested up to: 6.8.2
 * Requires PHP: 5.6
 * Stable tag: 1.2.17
 * Author: Cool Plugins
 * Author URI: https://coolplugins.net/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=author_page&utm_content=plugins_list
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ecsa
 * Domain Path: languages
 * Requires Plugins: the-events-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'ECSA_VERSION' ) ) {
	return;
}

define( 'ECSA_VERSION', '1.2.17' );
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
					'title' => __('Cool Plugins Events Addons', 'ecsa'),
					'message' => __('Help us make this plugin more compatible with your site by sharing non-sensitive site data.', 'ecsa'),
					'pages' => ['cool-plugins-events-addon'],
					'always_show_on' => ['cool-plugins-events-addon'], // This enables auto-show
					'plugin_name'=>'ecsa',
					
				];
	
				CPFM_Feedback_Notice::cpfm_register_notice('cool_events', $notice);
	
					if (!isset($GLOBALS['cool_plugins_feedback'])) {
						$GLOBALS['cool_plugins_feedback'] = [];
					}
				
					$GLOBALS['cool_plugins_feedback']['cool_events'][] = $notice;
		   
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

			$placeholder   = ( ( $attributes['placeholder'] != '' ) ? $attributes['placeholder'] : __( 'Search Events', 'ecsa' ) );
			$show_events   = ( ( $attributes['show-events'] != '' ) ? $attributes['show-events'] : '10' );
			$disable_past  = ( ( $attributes['disable-past-events'] != '' ) ? $attributes['disable-past-events'] : 'false' );
			$layout        = ( ( $attributes['layout'] != '' ) ? $attributes['layout'] : 'medium' );
			$content_type = ( ( $attributes['content-type'] != '' ) ? $attributes['content-type'] : 'advance' );
		
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
				wp_register_style( 'ecsa-styles', ECSA_URL . 'assets/css/ecsa-styles.min.css', ECSA_VERSION, 'all' );
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
			load_plugin_textdomain( 'ecsa', false, basename( dirname( __FILE__ ) ) . '/languages/' );
			
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

	function EventsCalendarSearchAddon() {
		return EventsCalendarSearchAddon::get_instance();
	}

	$GLOBALS['EventsCalendarSearchAddon'] = EventsCalendarSearchAddon();

endif;
