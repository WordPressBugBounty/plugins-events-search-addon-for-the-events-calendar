<?php
/**
 * Class autoloader.
 *
 * Mirrors the sibling addon's loader shape (countdown-for-the-events-calendar/
 * includes/class-tecc-autoloader.php) so the two trees stay navigable by the
 * same rules.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Autoloader' ) ) {

	/**
	 * Maps `CoolPlugins\EventsSearch\*` to `class-ecsa-*.php` files.
	 *
	 * Mapping rules:
	 *  - The namespace prefix is stripped; the last segment is the class name.
	 *  - A leading `Admin` segment roots the lookup at `admin/`, everything
	 *    else roots at `includes/`.
	 *  - Remaining segments become lowercased sub-directories.
	 *  - Filename is `class-ecsa-` + the class name lowercased with `_` → `-`.
	 *
	 * So `CoolPlugins\EventsSearch\Admin\Settings\Settings_Page` resolves to
	 * `admin/settings/class-ecsa-settings-page.php`, and
	 * `CoolPlugins\EventsSearch\Tec\Tec` to `includes/tec/class-ecsa-tec.php`.
	 *
	 * A missing file is a silent no-op — never a fatal — so a partially
	 * deployed tree degrades instead of white-screening.
	 *
	 * @since 2.0.0
	 */
	final class Autoloader {

		/**
		 * Namespace prefix handled by this loader.
		 */
		const PREFIX = 'CoolPlugins\\EventsSearch\\';

		/**
		 * Register the autoloader with SPL.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function register() {
			spl_autoload_register( array( __CLASS__, 'autoload' ) );
		}

		/**
		 * Resolve and require a class file.
		 *
		 * @since 2.0.0
		 * @param string $class_name Fully-qualified class name.
		 * @return void
		 */
		public static function autoload( $class_name ) {
			if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( self::PREFIX ) );
			$segments = explode( '\\', $relative );
			$class    = array_pop( $segments );

			$base = 'includes/';
			if ( isset( $segments[0] ) && 'Admin' === $segments[0] ) {
				$base = 'admin/';
				array_shift( $segments );
			}

			$subdir = '';
			if ( ! empty( $segments ) ) {
				$subdir = strtolower( implode( '/', $segments ) ) . '/';
			}

			$file = ECSA_PATH . $base . $subdir . 'class-ecsa-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
}
