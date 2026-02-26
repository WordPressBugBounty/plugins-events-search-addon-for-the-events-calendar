<?php
if (!defined('ABSPATH')) {
    exit;
} 
/**
 * 
 * This is the main class for creating dashbord addon page and all submenu items
 * 
 * Do not call or initialize this class directly, instead use the function mentioned at the bottom of this file
 */
//phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.Security.NonceVerification.Recommended
if ( !class_exists('cool_plugins_events_addons')) {

    class cool_plugins_events_addons {

        /**
         * None of these variables should be accessable from the outside of the class
         */
            private static $instance;
            private $pro_plugins = array();
            private $pages = array();
            private $main_menu_slug = null;// 'cool-plugins-events-addon';
            private $plugin_tag = null;
            private $dashboar_page_heading ;
            private $disable_plugins = array();
            private $addon_dir = __DIR__;    // point to the main addon-page directory
            private $addon_file = __FILE__;
            
            /**
             * initialize the class and create dashboard page only one time
             */
            public static function init( ){

                if( empty(self::$instance) ){
                    return self::$instance = new self;
                }
                return self::$instance;

            }


            /**
             * Initialize the dashboard with specific plugins as per plugin tag
             * 
             */
            public function show_plugins( $plugin_tag, $menu_slug, $dashboard_heading ){

                if ( ! empty( $plugin_tag ) && ! empty( $menu_slug ) && ! empty( $dashboard_heading ) ) {
                    $this->plugin_tag           = sanitize_key( $plugin_tag );
                    $this->main_menu_slug       = sanitize_key( $menu_slug );
                    $this->dashboar_page_heading = sanitize_text_field( $dashboard_heading );
                } else {
                    return false;
                }

                add_action('admin_menu', array($this, 'init_plugins_dasboard_page'), 10);
                add_action('wp_ajax_ect_dashboard_install_plugin', array($this, 'ect_dashboard_install_plugin'));
                add_action('admin_enqueue_scripts', array($this,'enqueue_required_scripts') );
            }
            
            public function ect_dashboard_install_plugin() {


                if ( ! current_user_can( 'install_plugins' ) ) {
                    $status['errorMessage'] = __( 'Sorry, you are not allowed to install plugins on this site.', 'events-search-addon-for-the-events-calendar' );
                    wp_send_json_error( $status );
                }

			    if ( empty( $_POST['slug'] ) ) {
				    wp_send_json_error( array(
					    'slug'         => '',
					    'errorCode'    => 'no_plugin_specified',
					    'errorMessage' => __( 'No plugin specified.', 'events-search-addon-for-the-events-calendar' ),
				    ));
			    }
     	
		        $plugin_slug = sanitize_key( wp_unslash( $_POST['slug'] ) );

			    check_ajax_referer('ect-plugin-install-' . $plugin_slug);
                // Only allow installation of known marketing plugins (ignore client-manipulated slugs).
			    $allowed_slugs = array(
				    "event-page-templates-addon-for-the-events-calendar",
                    "events-block-for-the-events-calendar",
                    "events-search-addon-for-the-events-calendar",
                    "template-events-calendar",
                    "events-widgets-for-elementor-and-the-events-calendar",
                    "countdown-for-the-events-calendar",
                    "events-calendar-modules-for-divi",
                    "the-events-calendar-templates-and-shortcode",
                    "events-widgets-pro",
                    "event-single-page-builder-pro",
                    "cp-events-calendar-modules-for-divi-pro",
                    "events-speakers-and-sponsors",
				);
			    if ( ! in_array( $plugin_slug, $allowed_slugs, true ) ) {
				    wp_send_json_error( array(
					    'slug'         => $plugin_slug,
					    'errorCode'    => 'plugin_not_allowed',
					    'errorMessage' => __( 'This plugin cannot be installed from here.', 'events-search-addon-for-the-events-calendar' ),
				));
			    }

			    $status = array(
				    'install' => 'plugin',
				    'slug'    => sanitize_key( wp_unslash( $_POST['slug'] ) ),
			    );
			
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
			
			// Check if it is a pro plugin activation request
                $pro_plugins_slugs = array(
                    'events-widgets-pro',
                    'the-events-calendar-templates-and-shortcode',
                    'event-single-page-builder-pro',
                    'cp-events-calendar-modules-for-divi-pro',
                    'events-speakers-and-sponsors',
                );

			if ( in_array( $plugin_slug, $pro_plugins_slugs ) ) {

				if ( ! current_user_can( 'activate_plugin', $plugin_slug ) ) {
					wp_send_json_error( array( 'message' => __( 'Permission denied', 'events-search-addon-for-the-events-calendar' ) ) );
				}

				$plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';

				$pagenow        = isset($_POST['pagenow']) ? sanitize_key($_POST['pagenow']) : '';
				$network_wide = (is_multisite() && 'import' !== $pagenow);
				$activation_result = activate_plugin($plugin_file, '', $network_wide);

				if (is_wp_error($activation_result)) {
					wp_send_json_error(array('message' => $activation_result->get_error_message()));
				}

				wp_send_json_success( array(
                    'message'    => __( 'Plugin activated successfully', 'events-search-addon-for-the-events-calendar' ),
                    'activated'  => true,
                    'plugin_slug' => $plugin_slug,
                ) );
			}else{
				$api = plugins_api( 'plugin_information', array(
					'slug'   => $plugin_slug,
					'fields' => array(
						'sections' => false,
					),
				));

				if ( is_wp_error( $api ) ) {
					$status['errorMessage'] = $api->get_error_message();
					wp_send_json_error( $status );
				}

				$status['pluginName'] = $api->name;
				
				$skin     = new WP_Ajax_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$result   = $upgrader->install( $api->download_link );
				
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$status['debug'] = $skin->get_upgrade_messages();
				}
                
				if ( is_wp_error( $result ) ) {

					$status['errorCode']    = $result->get_error_code();
					$status['errorMessage'] = $result->get_error_message();
					wp_send_json_error( $status );

				} elseif ( is_wp_error( $skin->result ) ) {
					
					if($skin->result->get_error_message() === 'Destination folder already exists.'){
							
						$install_status = install_plugin_install_status( $api );
						$pagenow        = isset( $_POST['pagenow'] ) ? sanitize_key( $_POST['pagenow'] ) : '';

						if ( current_user_can( 'activate_plugin', $install_status['file'] )) {

							$network_wide = ( is_multisite() && 'import' !== $pagenow );
							$activation_result = activate_plugin( $install_status['file'], '', $network_wide );
							if ( is_wp_error( $activation_result ) ) {
								
								$status['errorCode']    = $activation_result->get_error_code();
								$status['errorMessage'] = $activation_result->get_error_message();
								wp_send_json_error( $status );

							} else {

								$status['activated'] = true;
								
							}
							wp_send_json_success( $status );
						}
					}else{
					
						$status['errorCode']    = $skin->result->get_error_code();
						$status['errorMessage'] = $skin->result->get_error_message();
						wp_send_json_error( $status );
					}
					
				} elseif ( $skin->get_errors()->has_errors() ) {

					$status['errorMessage'] = $skin->get_error_messages();
					wp_send_json_error( $status );

				} elseif ( is_null( $result ) ) {

					global $wp_filesystem;

					$status['errorCode']    = 'unable_to_connect_to_filesystem';
					$status['errorMessage'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.' , 'events-search-addon-for-the-events-calendar' );

					if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
						$status['errorMessage'] = esc_html( $wp_filesystem->errors->get_error_message() );
					}

					wp_send_json_error( $status );
				}

				$install_status = install_plugin_install_status( $api );
				$pagenow        = isset( $_POST['pagenow'] ) ? sanitize_key( $_POST['pagenow'] ) : '';

				// 🔄 Auto-activate the plugin right after successful install
				if ( current_user_can( 'activate_plugin', $install_status['file'] ) && is_plugin_inactive( $install_status['file'] ) ) {

					$network_wide = ( is_multisite() && 'import' !== $pagenow );
					$activation_result = activate_plugin( $install_status['file'], '', $network_wide );

					if ( is_wp_error( $activation_result ) ) {
						$status['errorCode']    = $activation_result->get_error_code();
						$status['errorMessage'] = $activation_result->get_error_message();
						wp_send_json_error( $status );
					} else {
						$status['activated'] = true;
					}
				}
				wp_send_json_success( $status );
			}
		}
        



            /**
             * This function will initialize the main dashboard page for all plugins
             */
            function init_plugins_dasboard_page(){

                add_menu_page(
                    esc_html__( 'Events Addons', 'events-search-addon-for-the-events-calendar' ),
                    esc_html__( 'Events Addons', 'events-search-addon-for-the-events-calendar' ),
                    'manage_options',
                    $this->main_menu_slug,
                    array( $this, 'displayPluginAdminDashboard' ),
                    'dashicons-calendar-alt',
                    9
                );
                add_submenu_page(
                    $this->main_menu_slug,
                    esc_html__( 'Dashboard', 'events-search-addon-for-the-events-calendar' ),
                    esc_html__( 'Dashboard', 'events-search-addon-for-the-events-calendar' ),
                    'manage_options',
                    $this->main_menu_slug,
                    array( $this, 'displayPluginAdminDashboard' ),
                    5
                );
            }

            /**
             * This function will render and create the HTML display of dashboard page.
             * All the HTML can be located in other template files.
             * Avoid using any HTML here or use nominal HTML tags inside this function.
             */
            function displayPluginAdminDashboard(){

                $tag = $this->plugin_tag;
                $plugins = $this->request_wp_plugins_data( $tag );
                $pro_plugins = $this->request_pro_plugins_data( $tag );
                
                $this->ect_disable_free_plugins();
                
                // Define PRO plugins list - These are PRO plugins that need to be purchased
                $pro_plugin_slugs = array_keys($pro_plugins);
                
                // Map Free plugins to their PRO counterparts
                // If PRO version exists, FREE version should be hidden
                $free_to_pro_mapping = array();
                
                if(!empty($pro_plugins)){
                    foreach($pro_plugins as $slug => $data){
                        if(isset($data['incompatible']) && !empty($data['incompatible']) && $data['incompatible'] !== 'false'){
                            $free_to_pro_mapping[$data['incompatible']] = $slug;
                        }
                    }
                }
                
                $prefix = 'ect';
                
                if( !empty( $plugins ) || !empty($pro_plugins) ){
                    
                    // Separate plugins into categories
                    $activated_addons = array();
                    $available_addons = array();
                    $pro_addons = array();
                    
                    // Process free plugins
                    if(!empty($plugins)){
                        foreach($plugins as $plugin){
                            $plugin_slug = $plugin['slug'];
                            
                            // IMPORTANT: Skip PRO plugins from free plugins list
                            // PRO plugins will be handled separately from pro_plugins array
                            if(in_array($plugin_slug, $pro_plugin_slugs)){
                                continue; // Skip this plugin, it will be handled in PRO section
                            }
                            
                            // NEW LOGIC: Check if this FREE plugin has a PRO counterpart installed AND active
                            // Only hide FREE when PRO is active; if PRO is deactivated, show FREE in list
                            if(isset($free_to_pro_mapping[$plugin_slug])){
                                $pro_version_slug = $free_to_pro_mapping[$plugin_slug];
                                $pro_plugin_dir = WP_PLUGIN_DIR . '/' . $pro_version_slug;
                                
                                if(file_exists($pro_plugin_dir)){
                                    $pro_is_active = false;
                                    $pro_files = glob($pro_plugin_dir . '/*.php');
                                    if(!empty($pro_files)){
                                        foreach($pro_files as $pf){
                                            $basename = plugin_basename($pf);
                                            if(is_plugin_active($basename)){
                                                $pro_is_active = true;
                                                break;
                                            }
                                        }
                                    }
                                    if($pro_is_active){
                                        continue; // Skip FREE plugin, PRO version is active
                                    }
                                }
                            }
                            
                            $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
                            
                            // Check if plugin exists
                            if(file_exists($plugin_dir)){
                                // Check if plugin is active
                                $plugin_files = glob($plugin_dir . '/*.php');
                                $is_active = false;
                                $plugin_main_file = '';
                                
                                foreach($plugin_files as $plugin_file){
                                    $plugin_basename = plugin_basename($plugin_file);
                                    
                                    // Store the first valid plugin file as fallback
                                    if(empty($plugin_main_file)){
                                        $plugin_data = get_file_data($plugin_file, array('Plugin Name' => 'Plugin Name'));
                                        if(!empty($plugin_data['Plugin Name'])){
                                            $plugin_main_file = $plugin_basename;
                                        }
                                    }
                                    
                                    if(is_plugin_active($plugin_basename)){
                                        $is_active = true;
                                        $plugin_main_file = $plugin_basename;
                                        break;
                                    }
                                }
                                
                                // Set plugin basename for both active and inactive plugins
                                if(!empty($plugin_main_file)){
                                    $plugin['plugin_basename'] = $plugin_main_file;
                                    
                                    // Get actual installed plugin version
                                    $plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_main_file;
                                    if(file_exists($plugin_file_path)){
                                        $plugin_data = get_plugin_data($plugin_file_path, false, false);
                                        if(!empty($plugin_data['Version'])){
                                            $plugin['installed_version'] = $plugin_data['Version'];
                                        }
                                    }
                                }
                                
                                if($is_active){
                                    // Check for updates
                                    $plugin['has_update'] = $this->check_plugin_update($plugin_slug);
                                    $activated_addons[] = $plugin;
                                }else{
                                    // Installed but inactive
                                    $plugin['needs_activation'] = true;
                                    $plugin['has_update'] = $this->check_plugin_update($plugin_slug);
                                    $available_addons[] = $plugin;
                                }
                            }else{
                                // Not installed
                                $available_addons[] = $plugin;
                            }
                        }
                    }
                    
                    // Process PRO plugins
                    if(!empty($pro_plugins)){
                        foreach($pro_plugins as $plugin){
                            $plugin_slug = $plugin['slug'];
                            
                            // Validate if this is actually a PRO plugin
                            $has_buy_link = !empty($plugin['buyLink']);
                            $is_pro_slug = (strpos($plugin_slug, '-pro') !== false) || in_array($plugin_slug, $pro_plugin_slugs);
                            
                            if(!$has_buy_link && !$is_pro_slug){
                                continue;
                            }
                            
                            $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
                            
                            // Check if PRO plugin exists
                            if(file_exists($plugin_dir)){
                                // Check if plugin is active
                                $plugin_files = glob($plugin_dir . '/*.php');
                                $is_active = false;
                                $plugin_main_file = '';
                                
                                foreach($plugin_files as $plugin_file){
                                    $plugin_basename = plugin_basename($plugin_file);
                                    
                                    // Store the first valid plugin file as fallback
                                    if(empty($plugin_main_file)){
                                        $plugin_data = get_file_data($plugin_file, array('Plugin Name' => 'Plugin Name'));
                                        if(!empty($plugin_data['Plugin Name'])){
                                            $plugin_main_file = $plugin_basename;
                                        }
                                    }
                                    
                                    if(is_plugin_active($plugin_basename)){
                                        $is_active = true;
                                        $plugin_main_file = $plugin_basename;
                                        break;
                                    }
                                }
                                
                                // Set plugin basename for both active and inactive plugins
                                if(!empty($plugin_main_file)){
                                    $plugin['plugin_basename'] = $plugin_main_file;
                                    
                                    // Get actual installed plugin version
                                    $plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_main_file;
                                    if(file_exists($plugin_file_path)){
                                        $plugin_data = get_plugin_data($plugin_file_path, false, false);
                                        if(!empty($plugin_data['Version'])){
                                            $plugin['installed_version'] = $plugin_data['Version'];
                                        }
                                    }
                                }
                                
                                if($is_active){
                                    // Active PRO plugin (user ke paas hai + active) → Activated Addons
                                    $plugin['has_update'] = $this->check_plugin_update($plugin_slug);
                                    $activated_addons[] = $plugin;
                                }else{
                                    // Installed but inactive premium plugin (the user owns it but it’s not active) → Show it under Available Addons (including update checks).
                                    $plugin['needs_activation'] = true;
                                    $plugin['is_pro_installed'] = true; // Mark as PRO
                                    $plugin['has_update'] = $this->check_plugin_update($plugin_slug);
                                    $available_addons[] = $plugin;
                                }
                            }else{
                                // PRO plugin NOT INSTALLED (user ke paas NAHI hai) → Pro Addons
                                $pro_addons[] = $plugin;
                            }
                        }
                    }
                    
                    // Render new dashboard
                    $this->render_modern_dashboard($prefix, $activated_addons, $available_addons, $pro_addons);

                }else{
                    echo '<div class="notice notice-warning ect-required-plugin-notice"><p>' . esc_html__( 'No plugins data available at the moment.', 'events-search-addon-for-the-events-calendar' ) . '</p></div>';
                }
            }
            
            /**
             * Check if plugin has update available
             */
            function check_plugin_update($plugin_slug){

                $update_plugins = get_site_transient('update_plugins');
                
                if(!empty($update_plugins->response)){
                    foreach($update_plugins->response as $plugin_file => $plugin_data){
                        if(strpos($plugin_file, $plugin_slug) !== false){
                            return $plugin_data->new_version;
                        }
                    }
                }
                return false;
            }
            
            /**
             * Render Modern Dashboard UI (Using Modular Include Files)
             */
            function render_modern_dashboard($prefix, $activated_addons, $available_addons, $pro_addons){

                // Store instance for use in included files
                $dashboard_instance = $this;
                
                // Sanitize prefix
                $prefix = sanitize_key($prefix);
                
                ?>
                
                <div class="<?php echo esc_attr($prefix); ?>-dashboard-wrapper">
                    <?php 
                    // Include Header
                    include $this->addon_dir . '/includes/dashboard-header.php'; 
                    ?>

                    <div class="<?php echo esc_attr($prefix); ?>-main-grid">
                        <?php 
                        // Include Main Content (Plugin Cards)
                        include $this->addon_dir . '/includes/dashboard-page.php'; 
                        
                        // Include Sidebar
                        include $this->addon_dir . '/includes/dashboard-sidebar.php'; 
                        ?>
                    </div>
                </div>
                <?php
            }  // End of render_modern_dashboard function

            /**
             * Get Demo, Documentation and Org URLs for a plugin (free vs pro have different URLs)
             * Free plugins: slug-based WordPress.org redirect + separate demo/docs paths per plugin.
             *
             * @param string $plugin_slug   Plugin slug
             * @param bool   $is_pro_plugin Whether the plugin is a PRO plugin
             * @return array ['demo' => url, 'docs' => url, 'org' => url or empty]
             */
            public function get_plugin_demo_docs_urls( $plugin_slug, $is_pro_plugin = false ) {

                $demo_url = 'https://eventscalendaraddons.com/demos/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=demo&utm_content=dashboard';
                $docs_url = 'https://eventscalendaraddons.com/docs/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=docs&utm_content=dashboard';
                
                if ( $is_pro_plugin ) {
                    $pro_plugins = $this->request_pro_plugins_data();
                    if ( isset( $pro_plugins[ $plugin_slug ] ) ) {
                        $plugin_data = $pro_plugins[ $plugin_slug ];
                        $demo_url    = ! empty( $plugin_data['demo_url'] ) ? $plugin_data['demo_url'] : $demo_url;
                        $docs_url    = ! empty( $plugin_data['docs_url'] ) ? $plugin_data['docs_url'] : $docs_url;
                    }
                } else {
                    $free_plugins = $this->request_wp_plugins_data();
                    if ( isset( $free_plugins[ $plugin_slug ] ) ) {
                        $plugin_data = $free_plugins[ $plugin_slug ];
                        $demo_url    = ! empty( $plugin_data['demo_url'] ) ? $plugin_data['demo_url'] : $demo_url;
                        $docs_url    = ! empty( $plugin_data['docs_url'] ) ? $plugin_data['docs_url'] : $docs_url;
                    }
                }

                return array(
                    'demo' => esc_url( $demo_url ),
                    'docs' => esc_url( $docs_url ),
                );
            }

            /**
             * Output Demo + Docs links markup for plugin card (single place for all sections)
             *
             * @param string $prefix        CSS prefix
             * @param string $plugin_slug   Plugin slug
             * @param bool   $is_pro_plugin Whether the plugin is PRO
             */
            private function render_plugin_card_demo_docs_links( $prefix, $plugin_slug, $is_pro_plugin ) {

                $urls = $this->get_plugin_demo_docs_urls( $plugin_slug, $is_pro_plugin );
                ?>
                <div class="<?php echo esc_attr( $prefix ); ?>-card-links">
                    <a href="<?php echo esc_url( empty($urls['demo']) ? 'https://eventscalendaraddons.com/demos/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=demo&utm_content=dashboard' : $urls['demo'] ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'View Demo', 'events-search-addon-for-the-events-calendar' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><g fill="currentColor"><path d="M10.5 8a2.5 2.5 0 1 1-5 0a2.5 2.5 0 0 1 5 0"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8m8 3.5a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7"/></g></svg>
                        <?php esc_html_e( 'Demo', 'events-search-addon-for-the-events-calendar' ); ?>
                    </a>
                    <a href="<?php echo esc_url( empty($urls['docs']) ? 'https://eventscalendaraddons.com/docs/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=docs&utm_content=dashboard' : $urls['docs'] ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Documentation', 'events-search-addon-for-the-events-calendar' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 56"><path fill="currentColor" d="M15.555 53.125h24.89c4.852 0 7.266-2.461 7.266-7.336V24.508H30.742c-3 0-4.406-1.43-4.406-4.43V2.875H15.555c-4.828 0-7.266 2.484-7.266 7.36v35.554c0 4.898 2.438 7.336 7.266 7.336m15.258-31.828h16.64c-.164-.961-.844-1.899-1.945-3.047L32.57 5.102c-1.078-1.125-2.062-1.805-3.047-1.97v16.9c0 .843.446 1.265 1.29 1.265m-11.836 13.36c-.961 0-1.641-.68-1.641-1.594c0-.915.68-1.594 1.64-1.594h18.07c.938 0 1.665.68 1.665 1.593c0 .915-.727 1.594-1.664 1.594Zm0 8.929c-.961 0-1.641-.68-1.641-1.594s.68-1.594 1.64-1.594h18.07c.938 0 1.665.68 1.665 1.594s-.727 1.594-1.664 1.594Z"/></svg>
                        <?php esc_html_e( 'Docs', 'events-search-addon-for-the-events-calendar' ); ?>
                    </a>
                </div>
                <?php
            }
            
            /**
             * Render individual plugin card with proper sanitization
             *
             * @param string $prefix CSS prefix
             * @param array $plugin Plugin data array
             * @param string $type Card type: 'activated', 'available', or 'pro'
             */
            function render_plugin_card($prefix, $plugin, $type = 'activated'){
               
                // Sanitize all inputs
                $prefix = sanitize_key($prefix);
                $type = sanitize_key($type);
                
                // Extract and sanitize plugin data
                $plugin_name = isset($plugin['name']) ? sanitize_text_field($plugin['name']) : '';
                $plugin_desc = isset($plugin['desc']) ? sanitize_text_field($plugin['desc']) : '';
                $plugin_slug = isset($plugin['slug']) ? sanitize_key($plugin['slug']) : '';
                
                // Use logo from plugin data if available, otherwise fallback
                $plugin_logo = '';
                if ( ! empty( $plugin['logo'] ) ) {
                    // Check if logo is already a full external URL
                    if ( strpos( $plugin['logo'], 'http' ) !== false ) {
                        $plugin_logo = $plugin['logo'];
                    } else {
                        // Treat as relative path within plugin assets
                        $plugin_logo = plugin_dir_url( __FILE__ ) . 'assets/images/' . ( $plugin['logo'] );
                    }
                }
                if ( empty( $plugin_logo ) ) {
                    $plugin_logo = $this->event_addon_plugins_logo( $plugin_slug );
                }
                if ( $plugin_logo && strpos( $plugin_logo, 'https://ps.w.org' ) !== false ) {
                    $plugin_logo = $this->event_addon_plugins_logo( $plugin_slug );
                }
                
                $has_update = isset($plugin['has_update']) ? sanitize_text_field($plugin['has_update']) : false;
                // Version display: prefer installed, then latest_version, then version
                $available_version = isset($plugin['latest_version']) ? $plugin['latest_version'] : (isset($plugin['version']) ? $plugin['version'] : '');
                $plugin_version = isset($plugin['installed_version']) ? sanitize_text_field($plugin['installed_version']) : sanitize_text_field($available_version);
                
                // Return early if essential data is missing
                if (empty($plugin_name) || empty($plugin_slug)) {
                    return;
                }
                
                // Check if plugin is a PRO plugin
                $is_pro_plugin = false;
                
                // Pro plugin slugs list
                $pro_plugin_slugs = array(
                    'events-widgets-pro',
                    'event-single-page-builder-pro',
                    'cp-events-calendar-modules-for-divi-pro',
                    'events-speakers-and-sponsors',
                    'the-events-calendar-templates-and-shortcode',
                );
                
                // Check if it's a pro plugin based on:
                if ( $type === 'pro' ) {
                    $is_pro_plugin = true;
                } elseif ( isset( $plugin['is_pro_installed'] ) && $plugin['is_pro_installed'] ) {
                    $is_pro_plugin = true;
                } elseif ( $type === 'activated' ) {
                    // Check if activated plugin is a pro plugin
                    $is_pro_plugin = (strpos($plugin_slug, '-pro') !== false) || in_array($plugin_slug, $pro_plugin_slugs, true);
                }
                ?>
                <div class="<?php echo esc_attr($prefix); ?>-card">
                    <?php if ( ! empty( $has_update ) ) : ?>
                        <div title="<?php echo esc_attr__( 'Update available', 'events-search-addon-for-the-events-calendar' ); ?>" class="<?php echo esc_attr($prefix); ?>-pulse-wrapper"></div>
                        <div title="<?php echo esc_attr__( 'Update available', 'events-search-addon-for-the-events-calendar' ); ?>" class="<?php echo esc_attr($prefix); ?>-notification-dot"></div>
                    <?php endif; ?>
                    
                    <?php if ( $is_pro_plugin ) : ?>
                        <span class="<?php echo esc_attr($prefix); ?>-badge <?php echo esc_attr($prefix); ?>-badge-premium"><?php echo esc_html__( 'Pro', 'events-search-addon-for-the-events-calendar' ); ?></span>
                    <?php endif; ?>
                    
                    
                    
                    <div class="<?php echo esc_attr($prefix); ?>-icon-box">
                        <img src="<?php echo esc_url( $plugin_logo ); ?>" alt="<?php echo esc_attr( $plugin_name ); ?>">
                    </div>
                    
                    <div class="<?php echo esc_attr($prefix); ?>-info">
                        <h3><?php echo esc_html( $plugin_name ); ?></h3>
                        <p><?php echo esc_html( $plugin_desc ); ?></p>
                        
                        <?php if ( $type === 'activated' ) : ?>
                            <div class="<?php echo esc_attr($prefix); ?>-badge-group">
                                <div class="<?php echo esc_attr($prefix); ?>-active-update">
                                    <span class="<?php echo esc_attr($prefix); ?>-badge <?php echo esc_attr($prefix); ?>-badge-active"><?php echo esc_html__( 'Active', 'events-search-addon-for-the-events-calendar' ); ?></span>
                                    <?php if($plugin_version): ?>
                                        <span class="<?php echo esc_attr($prefix); ?>-badge <?php echo esc_attr($prefix); ?>-badge-version"><?php echo esc_html($plugin_version); ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if ( $type !== 'pro' ) : ?>
                                    <?php $this->render_plugin_card_demo_docs_links( $prefix, $plugin_slug, $is_pro_plugin ); ?>
                                <?php endif; ?>

                            </div>
                        <?php elseif ( $type === 'available' ) : ?>
                            <div class="<?php echo esc_attr($prefix); ?>-card-footer">
                                <?php
                                $needs_activation = isset($plugin['needs_activation']) && $plugin['needs_activation'] && isset($plugin['plugin_basename']);
                             
                                $install_nonce    = wp_create_nonce( 'ect-plugin-install-' . $plugin_slug );
                                ?>
                                <button type="button"
                                        class="button <?php echo esc_attr($prefix); ?>-button-primary <?php echo esc_attr($prefix); ?>-install-plugin <?php echo $needs_activation ? esc_attr($prefix) . '-btn-activate' : esc_attr($prefix) . '-btn-install'; ?>"
                                        data-slug="<?php echo esc_attr($plugin_slug); ?>"
                                        data-nonce="<?php echo esc_attr($install_nonce ); ?>"
                                >
                                    <?php echo $needs_activation ? esc_html__( 'Activate Now', 'events-search-addon-for-the-events-calendar' ) : esc_html__( 'Install Now', 'events-search-addon-for-the-events-calendar' ); ?>
                                </button>
                                <?php $this->render_plugin_card_demo_docs_links( $prefix, $plugin_slug, $is_pro_plugin ); ?>
                            </div>
                        <?php elseif ( $type === 'pro' ) : ?>
                            <div class="<?php echo esc_attr($prefix); ?>-card-footer">
                                <?php $buy_link = ! empty( $plugin['buyLink'] ) ? esc_url( $plugin['buyLink'] ) : '#'; ?>
                                <a href="<?php echo esc_attr( $buy_link ); ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="button <?php echo esc_attr($prefix); ?>-button-primary <?php echo esc_attr($prefix); ?>-btn-buy">
                                    <?php echo esc_html__( 'Buy Pro', 'events-search-addon-for-the-events-calendar' ); ?>
                                </a>
                                
                                <?php $this->render_plugin_card_demo_docs_links( $prefix, $plugin_slug, $is_pro_plugin ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            }

            /**
             * Lets enqueue all the required CSS & JS
             */
            public function enqueue_required_scripts(){
                    // Enqueue JavaScript file
                    wp_enqueue_script( 'cool-plugins-events-addon', ECSA_URL .'admin/events-addon-page/assets/js/script.js', array('jquery'), ECSA_VERSION, true);
                    
                    // Localize script with required data
                    wp_localize_script( 'cool-plugins-events-addon', 'cp_events', array(
                        'ajax_url'       => admin_url('admin-ajax.php'),
                        'plugin_tag'     => $this->plugin_tag,
                        'prefix'         => 'ect',
                        'activated_label' => esc_html__( 'Activated', 'events-search-addon-for-the-events-calendar' )
                    ));
                }


        /**
         * Load plugins data from JSON fallback file
         */
        public function load_json_fallback($type = 'free') {

            $json_file = $this->addon_dir . '/data/' . $type . '-plugins.json';
            
            if (!file_exists($json_file)) {
                return array();
            }
            
            $json_content = file_get_contents($json_file);
           
            $version_constants = array(
                'ECT_VERSION',
                'ECT_PRO_VERSION',
                'EPTA_PLUGIN_CURRENT_VERSION',
                'EBEC_VERSION',
                'ECSA_VERSION',
                'EWPE_PLUGIN_CURRENT_VERSION',
                'ESPBP_PLUGIN_CURRENT_VERSION',
                'ECMD_V_PRO',
                'ESAS_PLUGIN_CURRENT_VERSION',
                'ECTBE_VERSION',
                'TECC_VERSION_CURRENT',
                'ECMD_V',
            );

            foreach ($version_constants as $constant_name) {
                if (defined($constant_name)) {
                    $json_content = str_replace('{{' . $constant_name . '}}', constant($constant_name), $json_content);
                }
            }
            
            $plugin_info = json_decode($json_content, true);
            
            if (empty($plugin_info) || !is_array($plugin_info)) {
                return array();
            }
            
            $plugins_data = array();
            
            foreach ($plugin_info as $plugin) {
                if (!isset($plugin['slug'])) {
                    continue;
                }
                
                // Common fields mapping
                $json_image_url = isset( $plugin['image_url'] ) ? $plugin['image_url'] : '';
                
                $logo_value = '';
                if ( ! empty( $json_image_url ) ) {
                    $logo_value = sanitize_file_name( $json_image_url );
                } else {
                    // Fallback: store only filename (basename of default logo URL)
                    $logo_value = 'the-events-calendar-addon-icon.svg';
                }
                
                // Store both for transient: version (static), latest_version (placeholder or resolved)
                $static_version = isset( $plugin['version'] ) ? sanitize_text_field( $plugin['version'] ) : '';
                $latest_version = isset( $plugin['latest_version'] ) ? sanitize_text_field( $plugin['latest_version'] ) : $static_version;
                $data = array(
                    'name'           => isset( $plugin['name'] ) ? sanitize_text_field( $plugin['name'] ) : '',
                    'logo'           => $logo_value,
                    'slug'           => sanitize_key( $plugin['slug'] ),
                    'desc'           => isset( $plugin['info'] ) ? sanitize_text_field( $plugin['info'] ) : '', // JSON uses 'info', we map to 'desc'
                    'version'        => $static_version,
                    'latest_version' => $latest_version,
                    'demo_url'       => isset( $plugin['demo_url'] ) ? esc_url_raw( $plugin['demo_url'] ) : '',
                    'docs_url'       => isset( $plugin['docs_url'] ) ? esc_url_raw( $plugin['docs_url'] ) : '',
                );

                if ( $type === 'pro' ) {
                    // Pro specific fields
                    $data['buyLink'] = isset( $plugin['buy_url'] ) ? esc_url_raw( $plugin['buy_url'] ) : '';
                    $data['download_link'] = null;
                    $data['incompatible'] = isset($plugin['free_version']) ? $plugin['free_version'] : null;
                    
                    // Side effect: update disable_plugins
                    if (isset($plugin['free_version']) && $plugin['free_version'] != null && $plugin['free_version'] != 'false') {
                        $this->disable_plugins[$plugin['free_version']] = array('pro' => $plugin['slug']);
                    }
                } else {
                    // Free specific fields
                    $data['tags']           = isset( $plugin['tag'] ) ? sanitize_text_field( $plugin['tag'] ) : '';
                    $data['download_link']  = isset( $plugin['download_url'] ) ? esc_url_raw( $plugin['download_url'] ) : '';
                }
                
                $plugins_data[$plugin['slug']] = $data;
            }
            
            return $plugins_data;
        }

         /**
         * This function will gather all information regarding pro plugins.
         */
        public function request_pro_plugins_data($tag = null) {

            $trans_name  = $this->main_menu_slug . '_pro_api_cache' . $this->plugin_tag;
            $option_name = $this->main_menu_slug . '-' . $this->plugin_tag . '-pro';
        
            // 1️⃣ Check transient first
            $cached = get_transient($trans_name);
            if (false !== $cached && !empty($cached) && is_array($cached)) {
                $this->pro_plugins = $cached;
                return $this->pro_plugins;
            }
        
            // 2️⃣ Load from JSON fallback
            $pro_plugins = $this->load_json_fallback('pro');
            if (empty($pro_plugins) || !is_array($pro_plugins)) {
                $this->pro_plugins = array();
                return $this->pro_plugins;
            }
        
            $this->pro_plugins = $pro_plugins;
           
            if (!empty($this->pro_plugins) && is_array($this->pro_plugins) && count($this->pro_plugins)) {
                set_transient($trans_name, $this->pro_plugins, DAY_IN_SECONDS);
                update_option($option_name, $this->pro_plugins);
                return $this->pro_plugins;
            } else if (get_option($option_name, false) != false) {
                return get_option($option_name);
            }
        }



        /**
         * Gather all the free plugin information from wordpress.org API
         */
        public function request_wp_plugins_data($tag = null) {

            $trans_name  = $this->main_menu_slug . '_api_cache' . $this->plugin_tag;
        
            $cached = get_transient($trans_name);
            if (false !== $cached && !empty($cached)) {
                return $cached;
            }
        
            $all_plugins = $this->load_json_fallback('free');
        
            if (empty($all_plugins) || !is_array($all_plugins)) {
                return array();
            }

            if (!empty($all_plugins) && is_array($all_plugins) && count($all_plugins)) {
                set_transient($trans_name , $all_plugins, DAY_IN_SECONDS);
                update_option($this->main_menu_slug . '-' . $this->plugin_tag, $all_plugins);
                return $all_plugins;
            } elseif (get_option($this->main_menu_slug . '-' . $this->plugin_tag, false) != false) {
                return get_option($this->main_menu_slug . '-' . $this->plugin_tag);
            }
        }
   
    function event_addon_plugins_logo($slug){  

       return $logo_url= plugin_dir_url( __FILE__ ).'assets/images/the-events-calendar-addon-icon.svg';
    }

    function ect_disable_free_plugins() {

        if ( isset( $this->pro_plugins ) && is_array($this->pro_plugins) ) {
            foreach ( $this->pro_plugins as  $plugin ) {
                if ( isset( $plugin['incompatible'] ) && $plugin['incompatible'] != null ) {
                    $this->disable_plugins[ $plugin['incompatible'] ] = array( 'pro' => $plugin['slug'] );
                }
            }
        }
    }   
}

    /**
     * 
     * initialize the main dashboard class with all required parameters
     */
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    if ( ! function_exists( 'cool_plugins_events_addon_settings_page' ) ) {
        function cool_plugins_events_addon_settings_page($tag ,$settings_page_slug, $dashboard_heading ){
            $event_page = cool_plugins_events_addons::init();
            $event_page->show_plugins( $tag, $settings_page_slug, $dashboard_heading );
        }
    }

}