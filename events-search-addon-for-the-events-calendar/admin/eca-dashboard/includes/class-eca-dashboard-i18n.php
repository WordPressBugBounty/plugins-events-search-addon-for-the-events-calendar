<?php
/**
 * Shared i18n helpers for the ECA dashboard module.
 *
 * Host plugins pass `text_domain` in ECA_Dashboard_Registry::register_addon().
 * Shared PHP/views stay identical across vendors -- no hardcoded per-plugin domains.
 *
 * @package ECA_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ECA_Dashboard_I18n' ) ) {

	/**
	 * Resolves the active host text domain and wraps gettext helpers.
	 */
	final class ECA_Dashboard_I18n {

		/**
		 * Text domain for shared dashboard UI strings.
		 *
		 * Prefers the last registered addon that sets host_slug (dashboard host),
		 * otherwise the first registered text_domain.
		 *
		 * @return string
		 */
		public static function text_domain() {
			$fallback    = 'default';
			$host_domain = '';

			if ( ! class_exists( 'ECA_Dashboard_Registry' ) ) {
				return $fallback;
			}

			foreach ( ECA_Dashboard_Registry::get_addon_configs() as $addon ) {
				if ( empty( $addon['text_domain'] ) || ! is_string( $addon['text_domain'] ) ) {
					continue;
				}

				$domain = sanitize_text_field( $addon['text_domain'] );
				if ( '' === $domain ) {
					continue;
				}

				if ( 'default' === $fallback ) {
					$fallback = $domain;
				}

				// Match ECA_Dashboard_Page host_slug selection: last host wins.
				if ( ! empty( $addon['host_slug'] ) ) {
					$host_domain = $domain;
				}
			}

			return '' !== $host_domain ? $host_domain : $fallback;
		}

		/**
		 * @param string $text Text to translate.
		 * @return string
		 */
		public static function __( $text ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.LowLevelTranslationFunction
			return __( $text, self::text_domain() );
		}

		/**
		 * @param string $text Text to translate and escape.
		 * @return string
		 */
		public static function esc_html__( $text ) {
			return esc_html( self::__( $text ) );
		}

		/**
		 * @param string $text Text to translate and escape.
		 * @return string
		 */
		public static function esc_attr__( $text ) {
			return esc_attr( self::__( $text ) );
		}

		/**
		 * @param string $text Text to translate and echo.
		 * @return void
		 */
		public static function esc_html_e( $text ) {
			echo self::esc_html__( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}

		/**
		 * @param string $text Text to translate and echo.
		 * @return void
		 */
		public static function esc_attr_e( $text ) {
			echo self::esc_attr__( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
	}
}
