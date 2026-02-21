<?php
/**
 * Dashboard Sidebar Template
 * 
 * Variables available:
 * @var string $prefix - CSS prefix (e.g., 'ect', 'ea')
 * 
 * Usage in any plugin:
 * $prefix = 'your_prefix';
 * include 'path/to/dashboard-sidebar.php';
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
?>

<aside class="<?php echo esc_attr($prefix); ?>-sidebar">

    <!-- Premium Support -->
    <div class="<?php echo esc_attr($prefix); ?>-sidebar-card <?php echo esc_attr($prefix); ?>-premium-support <?php echo esc_attr($prefix); ?>-key-features">
        <div class="<?php echo esc_attr($prefix); ?>-sidebar-header">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.486 2 2 6.486 2 12v4.143C2 17.167 2.897 18 4 18h1a1 1 0 0 0 1-1v-5.143a1 1 0 0 0-1-1h-.908C4.648 6.987 7.978 4 12 4s7.352 2.987 7.908 6.857H19a1 1 0 0 0-1 1V18c0 1.103-.897 2-2 2h-2v-1h-4v3h6c2.206 0 4-1.794 4-4c1.103 0 2-.833 2-1.857V12c0-5.514-4.486-10-10-10"/></svg>
            <h3><?php echo esc_html__('PREMIUM SUPPORT', 'events-search-addon-for-the-events-calendar'); ?></h3>
        </div>
        <ul class="<?php echo esc_attr($prefix); ?>-feature-list">
            <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><defs><mask id="SVGIQLGgV2F"><g fill="none" stroke-linejoin="round" stroke-width="4"><path fill="#fff" stroke="#fff" d="M24 44a19.94 19.94 0 0 0 14.142-5.858A19.94 19.94 0 0 0 44 24a19.94 19.94 0 0 0-5.858-14.142A19.94 19.94 0 0 0 24 4A19.94 19.94 0 0 0 9.858 9.858A19.94 19.94 0 0 0 4 24a19.94 19.94 0 0 0 5.858 14.142A19.94 19.94 0 0 0 24 44Z"/><path stroke="#000" stroke-linecap="round" d="m16 24l6 6l12-12"/></g></mask></defs><path fill="currentColor" d="M0 0h48v48H0z" mask="url(#SVGIQLGgV2F)"/></svg> <?php echo esc_html__('Priority fast support.', 'events-search-addon-for-the-events-calendar'); ?></li>
            <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><defs><mask id="SVGIQLGgV2F"><g fill="none" stroke-linejoin="round" stroke-width="4"><path fill="#fff" stroke="#fff" d="M24 44a19.94 19.94 0 0 0 14.142-5.858A19.94 19.94 0 0 0 44 24a19.94 19.94 0 0 0-5.858-14.142A19.94 19.94 0 0 0 24 4A19.94 19.94 0 0 0 9.858 9.858A19.94 19.94 0 0 0 4 24a19.94 19.94 0 0 0 5.858 14.142A19.94 19.94 0 0 0 24 44Z"/><path stroke="#000" stroke-linecap="round" d="m16 24l6 6l12-12"/></g></mask></defs><path fill="currentColor" d="M0 0h48v48H0z" mask="url(#SVGIQLGgV2F)"/></svg> <?php echo esc_html__('Mon–Fri, 9:30 AM–6:30 PM IST.', 'events-search-addon-for-the-events-calendar'); ?></li>
            <li><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><defs><mask id="SVGIQLGgV2F"><g fill="none" stroke-linejoin="round" stroke-width="4"><path fill="#fff" stroke="#fff" d="M24 44a19.94 19.94 0 0 0 14.142-5.858A19.94 19.94 0 0 0 44 24a19.94 19.94 0 0 0-5.858-14.142A19.94 19.94 0 0 0 24 4A19.94 19.94 0 0 0 9.858 9.858A19.94 19.94 0 0 0 4 24a19.94 19.94 0 0 0 5.858 14.142A19.94 19.94 0 0 0 24 44Z"/><path stroke="#000" stroke-linecap="round" d="m16 24l6 6l12-12"/></g></mask></defs><path fill="currentColor" d="M0 0h48v48H0z" mask="url(#SVGIQLGgV2F)"/></svg> <?php echo esc_html__('Aim to resolve issues in 24 hrs.', 'events-search-addon-for-the-events-calendar'); ?></li>
        </ul>
        <a href="<?php echo esc_url('https://eventscalendaraddons.com/support/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=support&utm_content=dashboard'); ?>" target="_blank" rel="noopener" class="button <?php echo esc_attr($prefix); ?>-button-primary <?php echo esc_attr($prefix); ?>-btn-full">
            <?php echo esc_html__('Contact Support', 'events-search-addon-for-the-events-calendar'); ?>
        </a>
    </div>

    <!-- Trustpilot Rating -->
    <div class="<?php echo esc_attr($prefix); ?>-sidebar-card <?php echo esc_attr($prefix); ?>-trustpilot-rating">
        <div class="<?php echo esc_attr($prefix); ?>-sidebar-header">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="m12 21.35l-1.45-1.32C5.4 15.36 2 12.27 2 8.5C2 5.41 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.08C13.09 3.81 14.76 3 16.5 3C19.58 3 22 5.41 22 8.5c0 3.77-3.4 6.86-8.55 11.53z"/></svg>
            <h3><?php echo esc_html__('LOVING OUR PLUGINS?', 'events-search-addon-for-the-events-calendar'); ?></h3>
        </div>
        <div class="<?php echo esc_attr($prefix); ?>-trustpilot">
            <div class="<?php echo esc_attr($prefix); ?>-stars">
                <a href="<?php echo esc_url('https://wordpress.org/support/plugin/events-search-addon-for-the-events-calendar/reviews/#new-post'); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/images/events-trustpilot.svg'); ?>" alt="Rating"></a>
            </div>
            <p class="<?php echo esc_attr($prefix); ?>-sidebar-text"><?php echo esc_html__('Review us on WP.org and share your feedback with the community.', 'events-search-addon-for-the-events-calendar'); ?></p>
            <a href="<?php echo esc_url('https://wordpress.org/support/plugin/events-search-addon-for-the-events-calendar/reviews/#new-post'); ?>" target="_blank" rel="noopener" class="<?php echo esc_attr($prefix); ?>-trustpilot-link">
                <?php echo esc_html__('Rate us on WP.org', 'events-search-addon-for-the-events-calendar'); ?> <span class="dashicons dashicons-external"></span>
            </a>
        </div>
    </div>

    <!-- Events Calendar PRO -->
    <div class="<?php echo esc_attr($prefix); ?>-sidebar-card <?php echo esc_attr($prefix); ?>-events-calendar-pro">
        <div class="<?php echo esc_attr($prefix); ?>-sidebar-header">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14"><path fill="currentColor" fill-rule="evenodd" d="M6.997.009a5.125 5.125 0 1 0 0 10.249a5.125 5.125 0 0 0 0-10.25ZM7.2 2.432l.683 1.374a.21.21 0 0 0 .174.127l1.516.23a.23.23 0 0 1 .127.397L8.582 5.624a.22.22 0 0 0 0 .206l.214 1.508a.23.23 0 0 1-.34.246l-1.35-.714a.27.27 0 0 0-.223 0l-1.35.714a.23.23 0 0 1-.34-.246l.253-1.508a.22.22 0 0 0-.04-.206L4.287 4.552a.23.23 0 0 1 .127-.39l1.517-.221a.21.21 0 0 0 .174-.127l.683-1.374a.23.23 0 0 1 .413-.008Zm5.1 6.238a6.4 6.4 0 0 1-3.665 2.625l1.412 2.446a.5.5 0 0 0 .916-.12l.51-1.899l1.898.509a.5.5 0 0 0 .562-.733zm-6.936 2.626a6.38 6.38 0 0 1-3.667-2.621l-1.63 2.823a.5.5 0 0 0 .562.733l1.899-.509l.509 1.899a.5.5 0 0 0 .916.12z" clip-rule="evenodd"/></svg>
            <h3><?php echo esc_html__('EVENTS CALENDAR PRO', 'events-search-addon-for-the-events-calendar'); ?></h3>
        </div>
        <p class="<?php echo esc_attr($prefix); ?>-sidebar-text"><?php echo esc_html__('Our addons works perfectly with both The Events Calendar free and official pro version.', 'events-search-addon-for-the-events-calendar'); ?></p>
        <a href="<?php echo esc_url('https://stellarwp.pxf.io/tec'); ?>" target="_blank" rel="noopener" class="button <?php echo esc_attr($prefix); ?>-button-primary <?php echo esc_attr($prefix); ?>-btn-full <?php echo esc_attr($prefix); ?>-btn-buy">
            <?php echo esc_html__('Get Events Calendar Pro', 'events-search-addon-for-the-events-calendar'); ?>
        </a>
    </div>
</aside>
