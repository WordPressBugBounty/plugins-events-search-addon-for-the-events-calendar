<?php
/**
 * Universal Header Template for All Events Addon Pages
 * 
 * Can be used for: Dashboard, License, Settings, or any other page
 * 
 * Variables available:
 * @var string $prefix - CSS prefix (default: 'ect')
 * @var bool $show_wrapper - Show wrapper div (default: false for dashboard, true for others)
 * 
 * Usage Examples:
 * 
 * For Dashboard (no wrapper):
 * include 'dashboard-header.php';
 * 
 * For other pages (with wrapper):
 * $show_wrapper = true;
 * include 'dashboard-header.php';
 */

if (!defined('ABSPATH')) {
    exit;
}

// Default values
if (!isset($prefix)) {
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $prefix = 'ect';
}
if (!isset($show_wrapper)) {
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $show_wrapper = false; // Default: no wrapper (for dashboard)
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$prefix = sanitize_key($prefix);
?>

<?php if ($show_wrapper): ?>
<div class="<?php echo esc_attr($prefix); ?>-dashboard-wrapper">
<?php endif; ?>

<header class="<?php echo esc_attr($prefix); ?>-top-header">
    <div class="<?php echo esc_attr($prefix); ?>-header-left">
        <div class="<?php echo esc_attr($prefix); ?>-header-img-box">
            <img src="<?php echo esc_url(plugin_dir_url( __FILE__ ).'../assets/images/the-events-calendar-addon-icon.svg'); ?>" alt="Events Calendar Addons">
        </div>
        <h1><?php echo esc_html__('Events Calendar Addons', 'events-search-addon-for-the-events-calendar'); ?></h1>
    </div>
    <div class="<?php echo esc_attr($prefix); ?>-header-right">
        <a href="<?php echo esc_url('https://eventscalendaraddons.com/demos/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=demo&utm_content=dashboard'); ?>" target="_blank" rel="noopener" class="<?php echo esc_attr($prefix); ?>-btn <?php echo esc_attr($prefix); ?>-btn-outline">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><g fill="currentColor"><path d="M10.5 8a2.5 2.5 0 1 1-5 0a2.5 2.5 0 0 1 5 0"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8m8 3.5a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7"/></g></svg></span> <?php echo esc_html__('View Demos', 'events-search-addon-for-the-events-calendar'); ?>
        </a>
        <a href="<?php echo esc_url('https://eventscalendaraddons.com/docs/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=docs&utm_content=dashboard'); ?>" target="_blank" rel="noopener" class="<?php echo esc_attr($prefix); ?>-btn <?php echo esc_attr($prefix); ?>-btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 56"><path fill="currentColor" d="M15.555 53.125h24.89c4.852 0 7.266-2.461 7.266-7.336V24.508H30.742c-3 0-4.406-1.43-4.406-4.43V2.875H15.555c-4.828 0-7.266 2.484-7.266 7.36v35.554c0 4.898 2.438 7.336 7.266 7.336m15.258-31.828h16.64c-.164-.961-.844-1.899-1.945-3.047L32.57 5.102c-1.078-1.125-2.062-1.805-3.047-1.97v16.9c0 .843.446 1.265 1.29 1.265m-11.836 13.36c-.961 0-1.641-.68-1.641-1.594c0-.915.68-1.594 1.64-1.594h18.07c.938 0 1.665.68 1.665 1.593c0 .915-.727 1.594-1.664 1.594Zm0 8.929c-.961 0-1.641-.68-1.641-1.594s.68-1.594 1.64-1.594h18.07c.938 0 1.665.68 1.665 1.594s-.727 1.594-1.664 1.594Z"/></svg> <?php echo esc_html__('Check Docs', 'events-search-addon-for-the-events-calendar'); ?>
        </a>
    </div>
</header>

<?php if ($show_wrapper): ?>
<div class="<?php echo esc_attr($prefix); ?>-main-content-wrapper">
<?php endif; ?>