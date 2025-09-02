<?php
if (!defined('ABSPATH')) {
    exit;
} 
/**
 * This php file render HTML header for addons dashboard page
 */
    if( !isset( $this->main_menu_slug ) ):
        return;
    endif;

    $cool_plugins_docs = "https://eventscalendaraddons.com/docs/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=docs&utm_content=dashboard";
    $cool_plugins_visit_website = "https://eventscalendaraddons.com/demos/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=demo&utm_content=dashboard";
?>

<div id="cool-plugins-container" class="<?php echo esc_attr($this->main_menu_slug); ?>">
    <div class="cool-header">
        <h2 style=""><?php echo wp_kses_post($this->dashboar_page_heading); ?></h2>
        <a href="<?php echo esc_url($cool_plugins_docs); ?>" target="_blank" class="button">Check Docs</a>
        <a href="<?php echo esc_url($cool_plugins_visit_website); ?>" target="_blank" class="button">View Demos</a>
    </div>