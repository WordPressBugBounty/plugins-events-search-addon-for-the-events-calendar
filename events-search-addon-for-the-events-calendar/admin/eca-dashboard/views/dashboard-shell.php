<?php
/**
 * Dashboard admin shell — header + mount point only.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eca_active_nav = 'dashboard';
$header_view    = __DIR__ . '/admin-header.php';
if ( file_exists( $header_view ) ) {
	include $header_view;
}
?>
			<div id="eca-dash-root" aria-live="polite"></div>
<?php
$footer_view = __DIR__ . '/admin-footer.php';
if ( file_exists( $footer_view ) ) {
	include $footer_view;
}
