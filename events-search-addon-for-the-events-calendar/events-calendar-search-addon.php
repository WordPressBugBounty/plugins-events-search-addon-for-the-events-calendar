<?php
/**
 * Plugin Name: Events Search & Filter Bar for The Events Calendar
 * Description: <a href="https://wordpress.org/plugins/the-events-calendar/">📅 The Events Calendar Addon</a> - Add a fast search box and date filter to your events, anywhere on your site, with a simple shortcode.
 * Plugin URI: https://eventscalendaraddons.com/
 * Version: 2.0.1
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Cool Plugins
 * Author URI: https://coolplugins.net/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=author_page&utm_content=plugins_list
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: events-search-addon-for-the-events-calendar
 * Domain Path: /languages
 * Requires Plugins: the-events-calendar
 *
 * @package CoolPlugins\EventsSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Version single source of truth.
 *
 * ECSA_VERSION is authoritative at runtime; the `Version:` header above is
 * required by WordPress itself and cannot be derived at zero cost (get_file_data
 * reads and parses this file on every request). The two are kept in lockstep
 * mechanically instead: the `phase-ship` gate fails the commit when the header
 * and this constant disagree. Do not "fix" this by adding a runtime file read.
 */
if ( defined( 'ECSA_VERSION' ) ) {
	return;
}

define( 'ECSA_VERSION', '2.0.1' );
define( 'ECSA_FILE', __FILE__ );
define( 'ECSA_PATH', plugin_dir_path( ECSA_FILE ) );
define( 'ECSA_URL', plugin_dir_url( ECSA_FILE ) );
define( 'ECSA_FEEDBACK_API', 'https://feedback.coolplugins.net/' );

if ( ! defined( 'ECSA_DOCS_URL' ) ) {
	define( 'ECSA_DOCS_URL', 'https://eventscalendaraddons.com/docs/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=docs&utm_content=header' );
}
if ( ! defined( 'ECSA_SUPPORT_URL' ) ) {
	define( 'ECSA_SUPPORT_URL', 'https://eventscalendaraddons.com/support/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=support&utm_content=header' );
}
/*
 * WHERE AN UPGRADE PROMPT POINTS.
 *
 * Declared ONCE, here, beside its two siblings and tagged the same way, so the
 * campaign attribution of every upsell in the plugin is one edit rather than a
 * grep. Nothing links to it yet — the settings panel's locked cards are the
 * consumer this exists for — and that is deliberate: a URL that appears in
 * twenty templates is a URL that gets twenty different `utm_content` values by
 * accident.
 *
 * `utm_content` is `locked_card` rather than `header`, because that is the one
 * surface it is for. Per-surface content tags belong on the LINK, not here, and
 * a second surface should add a second constant rather than re-tag this one at
 * the call site.
 */
if ( ! defined( 'ECSA_PRO_URL' ) ) {
	define( 'ECSA_PRO_URL', 'https://eventscalendaraddons.com/plugin/events-search-filter-bar-pro/?utm_source=ecsa_plugin&utm_medium=inside&utm_campaign=pro&utm_content=locked_card' );
}

if ( ! defined( 'ECSA_MIN_TEC_VERSION' ) ) {
	define( 'ECSA_MIN_TEC_VERSION', '6.0.0' );
}

/*
 * Guarded bootstrap only.
 *
 * Nothing below runs plugin logic before `plugins_loaded`. v1.3.6 bootstrapped
 * at include time (instantiating the plugin, requiring the cron on every
 * request, and creating a nonce on every front-end page load); that is the
 * single largest source of fatal-on-update risk being retired here.
 *
 * Activation/deactivation hooks MUST be registered at file scope: the
 * activation request includes this file after `plugins_loaded` has already
 * fired, so registering them inside the bootstrap would silently never run.
 */
/*
 * Pro/Free coexistence guard — loaded FIRST, before the autoloader, in the
 * global namespace, so it works when nothing else in the plugin has.
 *
 * Events Search & Filter Bar Pro is a standalone fork of this plugin and
 * deliberately keeps the public surface identical — the shortcode tags, the
 * `ecsa_settings` option row, the `ecsa/v1` REST namespace, the `ecsa_*` URL
 * parameters and the `ecsa-*` asset handles — so that upgrading costs the site
 * nothing. That shared surface is exactly why the two must never run together.
 * Detecting Pro is coexistence handling, not gated functionality: the guard's
 * only permitted response is to decline to boot and explain itself. See
 * includes/ecsa-activation-guard.php for the full reasoning.
 *
 * `file_exists` rather than a bare `require_once`: a require on a missing file
 * is a compile error, which would white-screen wp-admin and leave the user
 * unable to deactivate anything.
 */
if ( file_exists( ECSA_PATH . 'includes/ecsa-activation-guard.php' ) ) {
	require_once ECSA_PATH . 'includes/ecsa-activation-guard.php';
}

if ( ! class_exists( 'CoolPlugins\EventsSearch\Autoloader' ) ) {
	require_once ECSA_PATH . 'includes/class-ecsa-autoloader.php';
}

if ( class_exists( 'CoolPlugins\EventsSearch\Autoloader' ) ) {
	CoolPlugins\EventsSearch\Autoloader::register();

	if ( class_exists( 'ECSA_Activation_Guard' ) ) {
		/*
		 * The guard owns all three lifecycle points — activation, deactivation
		 * and the `plugins_loaded` 5 boot — because each behaves differently
		 * when Pro is on the site, and splitting them across two owners is how
		 * one of them gets forgotten. Both hooks are registered unconditionally
		 * and before any early return, so a stood-down copy is still
		 * recoverable from the plugins screen.
		 */
		ECSA_Activation_Guard::init( __FILE__ );
	} else {
		/*
		 * Guard file missing from a partially deployed tree. Degrade to the
		 * unguarded bootstrap rather than to a plugin that never boots: a site
		 * running the free plugin alone — which is every wp.org site — is
		 * better served by working than by a missing file it cannot see.
		 */
		register_activation_hook( __FILE__, array( 'CoolPlugins\EventsSearch\Plugin', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'CoolPlugins\EventsSearch\Plugin', 'deactivate' ) );

		add_action( 'plugins_loaded', array( 'CoolPlugins\EventsSearch\Plugin', 'instance' ), 5 );
	}
}
