<?php
/**
 * Data schema versioning and upgrades.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Schema' ) ) {

	/**
	 * Idempotent, additive-only schema upgrades.
	 *
	 * Every step must be safe to run twice and must never rewrite or delete a
	 * value a site already holds — `add_option()` is a no-op when the row
	 * exists, so a site's saved settings survive every update untouched.
	 *
	 * The downgrade contract (plan §8) depends on this class never touching a
	 * v1.3.6 option row: `ecsa-v`, `ecsa-type`, `ecsa-installDate`,
	 * `ecsa-install-date` and `ecsa_initial_save_version` are read-only to v2
	 * forever, so rolling back to 1.3.6 stays lossless.
	 *
	 * ONE documented exception: `ecsa-ratingDiv` is written by the review-notice
	 * dismiss handler (admin/feedback-notice/ecsa-feedback-notice.php), which
	 * sets it to 'yes'. That is non-lossy — 1.3.6 reads 'yes' with identical
	 * meaning — but it is a real write, so any future "no writes to legacy rows"
	 * ship-gate must allowlist it rather than be weakened.
	 *
	 * @since 2.0.0
	 */
	final class Schema {

		/**
		 * Current schema version — the RELEASE BASELINE.
		 *
		 * Steps 2 through 6 existed and are DELETED. Every one of them migrated a
		 * row that only a 2.0-dev install could hold: the retired install-age
		 * gate, the pre-v4 results placement, the `tec_views` bool, the two
		 * withdrawn Filters-button spellings. 2.0 has never been released, so no
		 * site outside this repository has ever held one of those rows, and
		 * shipping the steps would ship migrations for a history that never
		 * happened.
		 *
		 * THE NUMBER DOES NOT GO BACK DOWN. The development installs already
		 * stamped 6 must never re-run a different step under a number they have
		 * passed, and a fresh install should land where they are — so 6 is the
		 * baseline both converge on, `upgrade_to_1()` is the only step that fires,
		 * and post-release migrations continue at 7.
		 */
		const VERSION = 6;

		/**
		 * Option holding the installed schema version.
		 */
		const OPTION = 'ecsa_schema_version';

		/**
		 * Option holding the v2 settings array.
		 */
		const SETTINGS_OPTION = 'ecsa_settings';

		/*
		 * RETIRED IN v3: `ecsa_legacy_scope`, `ecsa_legacy_scope_notice` and the
		 * `LEGACY_SCOPE_CEILING` version test.
		 *
		 * The gate read the VALUE of `ecsa-v` to decide whether a bare shortcode
		 * kept the v1.3.6 scope (a search box, no filters, no results grid) while
		 * a fresh install got the full layout. The shipped defaults are now that
		 * same search-only bar for EVERYONE, so both branches produced identical
		 * output and the install-age question decided nothing. The two option rows
		 * are v2-owned and never shipped, so `upgrade_to_3()` deletes them rather
		 * than leaving debris; no `ecsa-*` row is touched.
		 *
		 * `Compat\Legacy_Map` is NOT part of this and is untouched: the v1.3.6
		 * ATTRIBUTE vocabulary is frozen and still maps in full.
		 */

		/**
		 * Flag recording that this site arrived here by upgrade, not fresh install.
		 *
		 * Gates the one-time post-update notice (plan §8) so it never fires on
		 * a clean install.
		 */
		const UPGRADED_FLAG = 'ecsa_upgraded_from_legacy';

		/**
		 * Gates the one-time upgrade notice: 'show' (upgraded, unseen) | 'done'.
		 */
		const WELCOME_OPTION = 'ecsa_v2_welcome';

		/**
		 * Shipped default settings for the `ecsa_settings` seed.
		 *
		 * Delegates to `Settings::defaults()` (the authoritative schema)
		 * so there is one source of truth. Falls back to a static subset only if
		 * the Settings class is not loadable (a partially deployed tree), which
		 * is harmless because `Settings::get()` merges the full defaults at read
		 * time regardless of what was seeded.
		 *
		 * @since 2.0.0
		 * @return array<string, mixed>
		 */
		public static function defaults() {
			if ( class_exists( 'CoolPlugins\EventsSearch\Settings\Settings' ) ) {
				return \CoolPlugins\EventsSearch\Settings\Settings::defaults();
			}

			// Mirrors the shipped defaults in `Settings::schema()`. Reached only on
			// a partially deployed tree, and harmless either way because
			// `Settings::get()` merges the full defaults at read time.
			return array(
				// `typeahead` is NOT here any more: it stopped being a stored
				// setting (schema v4) and is derived from the placement
				// (`Settings::derive_typeahead()`). Seeding a key the schema no
				// longer declares would write a row nothing reads.
				//
				// `none` = dropdown suggestions only, the shipped placement.
				'results_mode'  => 'none',
				// `off` — the events page is left exactly as The Events Calendar
				// renders it. A three-mode enum since schema v5; the retired bool is
				// migrated by `upgrade_to_5()`.
				'tec_views'     => 'off',
				// Search box only, matching on the title only — the shipped scope.
				'facets'        => array( 'search' ),
				'search_fields' => array( 'title' ),
				'view'          => 'grid',
				'per_page'      => 12,
				'time'          => 'upcoming',
				'sort'          => 'date_asc',
				'recurrence'    => 'next_only',
				'placeholder'   => '',
			);
		}

		/**
		 * Run any pending upgrade steps.
		 *
		 * Called from both `Plugin::__construct()` (covers updates, which never
		 * fire the activation hook) and `Plugin::activate()`.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function maybe_upgrade() {
			$installed = (int) get_option( self::OPTION, 0 );

			if ( $installed >= self::VERSION ) {
				return;
			}

			// A site with legacy rows but no schema version arrived by upgrade.
			if ( 0 === $installed && false !== get_option( 'ecsa-v' ) ) {
				add_option( self::UPGRADED_FLAG, '1.3.x', '', 'no' );

				// One-time "2.0 is here" notice, for EXISTING users only. A fresh
				// install never reaches this branch, so it never sees it.
				// add_option is a no-op if it already exists, and the schema bump
				// below makes this run at most once.
				add_option( self::WELCOME_OPTION, 'show', '', 'no' );
			}

			if ( $installed < 1 ) {
				self::upgrade_to_1();
			}

			/*
			 * STEPS 2 THROUGH 6 WERE DISPATCHED HERE, and all five are deleted
			 * — see the note on `VERSION`. A fresh install runs `upgrade_to_1()`
			 * and is stamped 6; a development install already stamped 6 returned
			 * above without reaching this line at all.
			 */

			update_option( self::OPTION, self::VERSION );
		}

		/**
		 * Schema v1 — seed the settings array.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function upgrade_to_1() {
			add_option( self::SETTINGS_OPTION, self::defaults(), '', 'no' );

			self::correct_settings_autoload();
		}

		/*
		 * `defaults_at_v2()` and `upgrade_to_3()` through `upgrade_to_6()` LIVED
		 * HERE.
		 *
		 * DELETED AT RELEASE, not disabled. Each re-synced a stored value that only
		 * a 2.0-dev row could hold, against a shipped default that moved during
		 * development — a conversation between two versions of an unreleased
		 * plugin. `Settings::get()` merges the shipped defaults for every key a row
		 * does not hold, which is what leaves the seed as the only step a real
		 * install has ever needed.
		 */
		/**
		 * Force `ecsa_settings` off the autoload path.
		 *
		 * `update_option( ..., false )` sets autoload only when it CREATES the
		 * row, so a settings array that was ever written without an explicit
		 * autoload flag rides `alloptions` on every request forever. Correct it
		 * here rather than assuming creation-time got it right.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function correct_settings_autoload() {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( self::SETTINGS_OPTION, false );
				return;
			}

			// WP < 6.4: delete + re-add is the only way to change autoload.
			$existing = get_option( self::SETTINGS_OPTION, null );
			if ( null === $existing ) {
				return;
			}

			delete_option( self::SETTINGS_OPTION );
			add_option( self::SETTINGS_OPTION, $existing, '', 'no' );
		}
	}
}
