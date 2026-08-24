<?php
/**
 * License page admin notices — rendered below product tabs.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="eca-notices-wrapper eca-license-notices-wrapper">
	<?php
	/*
	 * wp-admin/js/common.js parks .notice after .wp-header-end (preferred)
	 * or the first .wrap h1|h2. On the license page this anchor sits below tabs.
	 */
	?>
	<hr class="wp-header-end">
	<?php
	/**
	 * Shared Cool Plugins / Events Addons admin notices slot.
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
