<?php
/**
 * Shared ECA admin header for Dashboard, License, and other addon admin pages.
 *
 * Expected vars:
 * @var string $eca_active_nav      Active nav key: 'dashboard' | 'license'.
 * @var string $eca_plugin_context  Optional plugin name under the brand (settings pages).
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eca_active_nav = isset( $eca_active_nav ) ? sanitize_key( (string) $eca_active_nav ) : 'dashboard';
if ( ! isset( $eca_plugin_context ) ) {
	$eca_plugin_context = class_exists( 'ECA_Dashboard_Page' )
		? ECA_Dashboard_Page::resolve_header_plugin_context()
		: '';
} else {
	$eca_plugin_context = sanitize_text_field( (string) $eca_plugin_context );
}
$eca_admin_urls  = class_exists( 'ECA_Dashboard_Registry' ) ? ECA_Dashboard_Registry::admin_urls() : array();
$eca_license_url = ! empty( $eca_admin_urls['license'] ) ? (string) $eca_admin_urls['license'] : '';
$eca_dash_url    = ! empty( $eca_admin_urls['dashboard'] ) ? $eca_admin_urls['dashboard'] : admin_url( 'admin.php?page=' . ( class_exists( 'ECA_Dashboard_Page' ) ? ECA_Dashboard_Page::PAGE_SLUG : 'cool-plugins-events-addon' ) );

$eca_docs_url    = 'https://eventscalendaraddons.com/docs/';
$eca_support_url = 'https://eventscalendaraddons.com/support/';
$eca_utm_source  = 'eca_plugin';

if ( class_exists( 'ECA_Dashboard_Registry' ) ) {
	$manifest = ECA_Dashboard_Registry::get_merged_manifest();
	if ( ! empty( $manifest['header']['docsUrl'] ) ) {
		$eca_docs_url = (string) $manifest['header']['docsUrl'];
	}
	if ( ! empty( $manifest['header']['supportUrl'] ) ) {
		$eca_support_url = (string) $manifest['header']['supportUrl'];
	}
	/*
	 * ATTRIBUTE TO THE HOST, not to whoever sorts first.
	 *
	 * This used to walk a fixed list (divi, esb, spb, speakers) and take the
	 * first entry present in the MERGED manifest — which always carries every
	 * addon, installed or not. `divi` therefore always won, and every
	 * sibling's Docs/Support links reported themselves as `ecmd_plugin`.
	 * The host's own utmSource was never consulted.
	 */
	$eca_utm_host = class_exists( 'ECA_Dashboard_Page' ) && method_exists( 'ECA_Dashboard_Page', 'host_slug' )
		? ECA_Dashboard_Page::host_slug()
		: '';

	if ( $eca_utm_host && ! empty( $manifest['addons'][ $eca_utm_host ]['utmSource'] ) ) {
		$eca_utm_source = (string) $manifest['addons'][ $eca_utm_host ]['utmSource'];
	} else {
		// Fallback only for a host that declares no utmSource of its own.
		foreach ( array( 'divi', 'esb', 'spb', 'speakers' ) as $utm_addon ) {
			if ( ! empty( $manifest['addons'][ $utm_addon ]['utmSource'] ) ) {
				$eca_utm_source = (string) $manifest['addons'][ $utm_addon ]['utmSource'];
				break;
			}
		}
	}
}

$eca_link_utm = static function ( $url, $campaign ) use ( $eca_utm_source ) {
	return add_query_arg(
		array(
			'utm_source'   => $eca_utm_source,
			'utm_medium'   => 'inside',
			'utm_campaign' => $campaign,
			'utm_content'  => 'dashboard_header',
		),
		$url
	);
};

$eca_docs_url    = $eca_link_utm( $eca_docs_url, 'docs' );
$eca_support_url = $eca_link_utm( $eca_support_url, 'support' );
?>
<div class="wrap eca-admin-wrap">
	<div class="eca-admin-page">

		<header class="eca-admin-header">
			<div class="eca-admin-header__left">
				<a href="<?php echo esc_url( $eca_dash_url ); ?>" class="eca-admin-brand<?php echo $eca_plugin_context ? ' eca-admin-brand--has-context' : ''; ?>" aria-label="<?php ECA_Dashboard_I18n::esc_attr_e( 'Events Calendar Addons Dashboard' ); ?>">
					<span class="eca-admin-brand__logo" aria-hidden="true">
						<span class="dashicons dashicons-calendar-alt"></span>
					</span>
					<span class="eca-admin-brand__text">
						<span class="eca-admin-brand__name"><?php ECA_Dashboard_I18n::esc_html_e( 'Events Calendar Addons' ); ?></span>
						<?php if ( $eca_plugin_context ) : ?>
							<span class="eca-admin-brand__context">
								<span class="eca-admin-brand__context-mark" aria-hidden="true">→</span>
								<span class="eca-admin-brand__context-label"><?php echo esc_html( $eca_plugin_context ); ?></span>
							</span>
						<?php endif; ?>
					</span>
				</a>
				<nav class="eca-admin-nav<?php echo $eca_license_url ? ' eca-admin-nav--with-license' : ''; ?>" aria-label="<?php ECA_Dashboard_I18n::esc_attr_e( 'Events Calendar Addons navigation' ); ?>">
					<a href="<?php echo esc_url( $eca_dash_url ); ?>" class="eca-admin-nav__item<?php echo 'dashboard' === $eca_active_nav ? ' is-active' : ''; ?>"<?php echo 'dashboard' === $eca_active_nav ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons dashicons-dashboard" aria-hidden="true"></span>
						<span><?php ECA_Dashboard_I18n::esc_html_e( 'Dashboard' ); ?></span>
					</a>
					<?php if ( $eca_license_url ) : ?>
						<a href="<?php echo esc_url( $eca_license_url ); ?>" class="eca-admin-nav__item<?php echo 'license' === $eca_active_nav ? ' is-active' : ''; ?>"<?php echo 'license' === $eca_active_nav ? ' aria-current="page"' : ''; ?>>
							<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
							<span><?php ECA_Dashboard_I18n::esc_html_e( 'License' ); ?></span>
						</a>
					<?php endif; ?>
				</nav>
			</div>
			<div class="eca-admin-header__right">
				<a href="<?php echo esc_url( $eca_support_url ); ?>" id="eca-header-support" target="_blank" rel="noopener noreferrer" class="eca-btn-secondary">
					<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
					<span><?php ECA_Dashboard_I18n::esc_html_e( 'Get Support' ); ?></span>
				</a>
				<a href="<?php echo esc_url( $eca_docs_url ); ?>" id="eca-header-docs" target="_blank" rel="noopener noreferrer" class="eca-btn-primary">
					<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
					<span><?php ECA_Dashboard_I18n::esc_html_e( 'Check Docs' ); ?></span>
				</a>
			</div>
		</header>

		<main class="eca-admin-main">
			<?php
			/*
			 * License page: defer notices below product tabs via render_license_notices().
			 * Only skip here when that API exists — mixed old/new installs must keep
			 * the header slot so notices are never lost.
			 */
			$eca_defer_license_notices = ( 'license' === $eca_active_nav
				&& class_exists( 'ECA_Dashboard_Page' )
				&& method_exists( 'ECA_Dashboard_Page', 'render_license_notices' ) );
			?>
			<?php if ( ! $eca_defer_license_notices ) : ?>
			<div class="eca-notices-wrapper">
				<?php
				/*
				 * wp-admin/js/common.js parks .notice after .wp-header-end (preferred)
				 * or the first .wrap h1|h2. This keeps Cool Plugins notices in this slot
				 * instead of after the hero / license page title inside #eca-dash-root.
				 */
				?>
				<hr class="wp-header-end">
				<?php
				/**
				 * Shared Cool Plugins / Events Addons admin notices slot.
				 * Guard against stacked legacy + ECA renderers firing this twice.
				 *
				 * @phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
				 */
				if ( ! defined( 'ECA_ADMIN_NOTICES_RENDERED' ) ) {
					define( 'ECA_ADMIN_NOTICES_RENDERED', true );
					do_action( 'ect_display_admin_notices' );
				}
				// phpcs:enable
				?>
			</div>
			<?php endif; ?>
