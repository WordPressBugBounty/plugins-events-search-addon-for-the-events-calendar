<?php
/**
 * Uninstall clean-up.
 *
 * Deletes ONLY this plugin's own data, from an explicit key list — never a
 * wildcard sweep over `wp_options`. Two categories are deliberately left alone:
 *
 * - The shared `cpfm_*_cool_events` consent/onboarding options. Four sibling
 *   Cool Plugins Events addons read the same rows, so deleting them here would
 *   silently reset consent for plugins that are still installed. Reference
 *   counting is not attempted (it cannot be made reliable across independent
 *   plugins); the accepted trade-off is that these rows are orphaned if every
 *   sibling is removed.
 * - User content of any kind. Nothing this plugin creates lives outside options
 *   and transients.
 *
 * Nothing is transmitted on uninstall.
 *
 * MULTISITE LIMITATION: on a network the per-site clean-up is looped over every
 * blog, but only when the network has 200 sites or fewer. Above that threshold
 * the loop would reliably exhaust the PHP time limit mid-uninstall and leave the
 * network in a half-cleaned state, so only the current site is cleaned and the
 * remaining sites keep their (inert) option rows.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Maximum number of network sites this uninstaller will iterate.
 *
 * @since 2.0.0
 */
define( 'ECSA_UNINSTALL_MAX_SITES', 200 );

if ( ! function_exists( 'ecsa_uninstall_site' ) ) {
	/**
	 * Remove this plugin's options, transients and schedules for one site.
	 *
	 * Safe to call repeatedly; every step is idempotent.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	function ecsa_uninstall_site() {
		global $wpdb;

		wp_clear_scheduled_hook( 'ecsa_extra_data_update' );

		$ecsa_options = array(
			// v2 keys.
			'ecsa_settings',
			'ecsa_engine_mode',
			'ecsa_schema_version',
			'ecsa_upgraded_from_legacy',
			/*
			 * RETIRED with the install-age gate, and only ever written by a 2.0-dev
			 * install — the step that used to delete them went with the rest of the
			 * pre-release migrations. They stay on this list because uninstall is the
			 * one place that should name every row this plugin has ever written, and
			 * `delete_option()` on an absent row is a no-op.
			 */
			'ecsa_legacy_scope',
			'ecsa_legacy_scope_notice',
			// Legacy v1.3.6 keys.
			'ecsa-v',
			'ecsa-type',
			/*
			 * `ecsa-installDate` and `ecsa-install-date` are two DISTINCT keys,
			 * not a typo or a duplicate: the camelCase row drives the review
			 * notice, the hyphenated row seeds the feedback `site_id` hash.
			 * BOTH must stay listed.
			 */
			'ecsa-installDate',
			'ecsa-install-date',
			'ecsa-ratingDiv',
			'ecsa_initial_save_version',
		);

		foreach ( $ecsa_options as $ecsa_option ) {
			delete_option( $ecsa_option );
		}

		/*
		 * Transient sweep. `esc_like()` is mandatory here: `_` is a LIKE
		 * wildcard, so the unescaped pattern `_transient_ecsa_%` would also
		 * match rows such as `atransientXecsaY…`.
		 *
		 * Names are selected first and removed via delete_option()/
		 * delete_transient() rather than a bare DELETE. Note what this does and
		 * does not buy: delete_option() invalidates the `options` cache group,
		 * NOT the `transient` group that set_transient() writes to on a
		 * persistent-object-cache site — those are different groups AND
		 * different keys. delete_transient() is what actually clears both, so
		 * both are called. (On such a site the wp_options rows usually do not
		 * exist at all, making the SELECT a no-op, but an uninstall should not
		 * depend on that.)
		 */
		$ecsa_patterns = array(
			$wpdb->esc_like( '_transient_ecsa_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ecsa_' ) . '%',
		);

		foreach ( $ecsa_patterns as $ecsa_pattern ) {
			$ecsa_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall sweep; no cache to consult.
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$ecsa_pattern
				)
			);

			if ( empty( $ecsa_names ) ) {
				continue;
			}

			foreach ( $ecsa_names as $ecsa_name ) {
				delete_option( $ecsa_name );

				// Clear the `transient` cache group too (see note above).
				if ( 0 === strpos( $ecsa_name, '_transient_' ) && 0 !== strpos( $ecsa_name, '_transient_timeout_' ) ) {
					delete_transient( substr( $ecsa_name, strlen( '_transient_' ) ) );
				}
			}
		}
	}
}

if ( is_multisite() ) {
	/*
	 * Fetch one row more than the threshold, never the whole network. With
	 * `'number' => 0` there is no LIMIT clause, so every site row would be
	 * materialised into PHP before count() is consulted — running the exact
	 * unbounded query the threshold exists to avoid, on exactly the oversized
	 * networks it is meant to protect. `count() > MAX` is decided identically
	 * from MAX+1 rows.
	 */
	$ecsa_site_ids = get_sites(
		array(
			'number' => ECSA_UNINSTALL_MAX_SITES + 1,
			'fields' => 'ids',
		)
	);

	if ( is_array( $ecsa_site_ids ) && count( $ecsa_site_ids ) <= ECSA_UNINSTALL_MAX_SITES ) {
		foreach ( $ecsa_site_ids as $ecsa_site_id ) {
			switch_to_blog( (int) $ecsa_site_id );
			ecsa_uninstall_site();
			restore_current_blog();
		}
	} else {
		// Oversized network: clean the current site only (see file header).
		ecsa_uninstall_site();
	}

	unset( $ecsa_site_ids, $ecsa_site_id );
} else {
	ecsa_uninstall_site();
}
