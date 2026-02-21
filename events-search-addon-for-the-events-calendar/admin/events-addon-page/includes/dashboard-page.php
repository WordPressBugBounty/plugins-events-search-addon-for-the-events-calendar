<?php
/**
 * Dashboard Main Content - Plugin Cards Template
 * 
 * Variables required:
 * @var string $prefix - CSS prefix (e.g., 'ect', 'ea')
 * @var array $activated_addons - Array of activated plugins
 * @var array $available_addons - Array of available plugins
 * @var array $pro_addons - Array of PRO plugins
 * @var object $dashboard_instance - Instance of dashboard class with render_plugin_card method
 * 
 * Usage:
 * include 'path/to/dashboard-page.php';
 */

if (!defined('ABSPATH')) {
    exit;
}

// Default prefix if not set
if (!isset($prefix)) {
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $prefix = 'ect';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$prefix = sanitize_key($prefix);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$activated_addons = isset($activated_addons) && is_array($activated_addons) ? $activated_addons : array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$available_addons = isset($available_addons) && is_array($available_addons) ? $available_addons : array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$pro_addons = isset($pro_addons) && is_array($pro_addons) ? $pro_addons : array();
?>

<div class="<?php echo esc_attr($prefix); ?>-content">
    
    <?php if(!empty($activated_addons)): ?>
    <!-- Currently Activated Addons -->
    <div class="<?php echo esc_attr($prefix); ?>-section-title">
    <span class="<?php echo esc_attr($prefix); ?>-indicator" style="background: var(--<?php echo esc_attr($prefix); ?>-success);"></span>
        <?php echo esc_html__('Currently Activated Addons', 'events-search-addon-for-the-events-calendar'); ?>
        <?php /* translators: %d: number of active addons */ ?>
        <span class="<?php echo esc_attr($prefix); ?>-title-count"><?php echo esc_html( sprintf( _n( '%d Active Addon', '%d Active Addons', count( $activated_addons ), 'events-search-addon-for-the-events-calendar' ), count( $activated_addons ) ) ); ?></span>
    </div>
    
    <div class="<?php echo esc_attr($prefix); ?>-cards-container">
        <?php foreach($activated_addons as $plugin): 
            if(isset($dashboard_instance) && method_exists($dashboard_instance, 'render_plugin_card')){
                $dashboard_instance->render_plugin_card($prefix, $plugin, 'activated');
            }
        endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($pro_addons)): ?>
    <!-- Premium Addons -->
    <div class="<?php echo esc_attr($prefix); ?>-section-title">
    <span class="<?php echo esc_attr($prefix); ?>-indicator" style="background: #000;"></span> 
        <?php echo esc_html__('Premium Addons', 'events-search-addon-for-the-events-calendar'); ?>
    </div>
    
    <div class="<?php echo esc_attr($prefix); ?>-cards-container <?php echo esc_attr($prefix); ?>-premium-addons">
        <?php foreach($pro_addons as $plugin): 
            if(isset($dashboard_instance) && method_exists($dashboard_instance, 'render_plugin_card')){
                $dashboard_instance->render_plugin_card($prefix, $plugin, 'pro');
            }
        endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($available_addons)): ?>
    <!-- Available Addons -->
    <div class="<?php echo esc_attr($prefix); ?>-section-title">
    <span class="<?php echo esc_attr($prefix); ?>-indicator" style="background: #94a3b8;"></span> 
        <?php echo esc_html__('Available Addons', 'events-search-addon-for-the-events-calendar'); ?>
    </div>
    
    <div class="<?php echo esc_attr($prefix); ?>-cards-container">
        <?php foreach($available_addons as $plugin): 
            if(isset($dashboard_instance) && method_exists($dashboard_instance, 'render_plugin_card')){
                $dashboard_instance->render_plugin_card($prefix, $plugin, 'available');
            }
        endforeach; ?>
    </div>
    <?php endif; ?>

</div>
