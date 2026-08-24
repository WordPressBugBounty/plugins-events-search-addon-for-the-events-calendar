<?php
/**
 * The Events Calendar access layer — the ONLY place in this plugin that is
 * allowed to know TEC internals.
 *
 * This layer is deliberately the guard/access surface alone: availability
 * gating, timezone-correct day boundaries and the visibility guard.
 * Nothing that no caller needs is built ahead of time.
 *
 * Contract (verified against live TEC 6.17 / Custom Tables V1):
 * - Events are queried ONLY through the occurrence-aware ORM (`tribe_events()`);
 *   never a hand-rolled `_EventStartDate` meta_query, which misses recurring
 *   occurrences entirely.
 * - Day boundaries come from `self::today()` — never a null argument to TEC's
 *   day helpers. See that method's docblock for the 12-hour bug it retires.
 * - Only published, non-password-protected `tribe_events` are ever surfaced
 *   (see `visible()`).
 *
 * Every public method that touches TEC opens with an `if ( ! self::available() )`
 * guard returning a safe empty value, so a site without — or with a too-old —
 * The Events Calendar degrades to nothing rather than fatalling.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Tec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CoolPlugins\EventsSearch\Tec\Tec' ) ) {

	/**
	 * TEC availability gate, timezone-correct day boundaries and the event
	 * visibility guard.
	 *
	 * @since 2.0.0
	 */
	final class Tec {

		/**
		 * Memoized result of `available()`.
		 *
		 * `null` means "not yet resolved". A negative answer is only cached once
		 * `plugins_loaded` has fired — see `available()` for why.
		 *
		 * @since 2.0.0
		 * @var bool|null
		 */
		private static $available = null;

		/**
		 * Whether a compatible The Events Calendar is active.
		 *
		 * Hard gate for every TEC call. Requires both the main
		 * class and the ORM entry point, then — when `ECSA_MIN_TEC_VERSION` and
		 * `Tribe__Events__Main::VERSION` are both available — a version floor
		 * check.
		 *
		 * Memoization detail: a *negative* answer is cached only after
		 * `plugins_loaded` has fired. `tribe_events()` is defined by a
		 * template-tag file required during TEC's own bootstrap, so an early
		 * caller (this plugin boots at `plugins_loaded` 5) can legitimately see
		 * "not yet loaded". Caching that would poison every later call for the
		 * rest of the request. A positive answer is always safe to cache: TEC
		 * cannot un-load itself mid-request.
		 *
		 * @since 2.0.0
		 * @return bool True when TEC is present and meets the version floor.
		 */
		public static function available() {
			if ( null !== self::$available ) {
				return self::$available;
			}

			$available = class_exists( 'Tribe__Events__Main' ) && function_exists( 'tribe_events' );

			if ( $available && defined( 'ECSA_MIN_TEC_VERSION' ) && defined( 'Tribe__Events__Main::VERSION' ) ) {
				$available = version_compare( \Tribe__Events__Main::VERSION, ECSA_MIN_TEC_VERSION, '>=' );
			}

			/*
			 * Cache POSITIVES ONLY.
			 *
			 * A negative is never cached, because "TEC is not loaded yet" and
			 * "TEC is not installed" are indistinguishable here and this plugin
			 * boots at `plugins_loaded` 5 — early enough to observe the former.
			 *
			 * Do NOT reintroduce a `did_action( 'plugins_loaded' )` sentinel to
			 * decide it is "late enough": WordPress increments the action
			 * counter BEFORE dispatching any callback, so `did_action()` already
			 * returns 1 inside the very first `plugins_loaded` callback. Such a
			 * guard admits exactly the early callers it was meant to exclude and
			 * would pin `false` for the rest of the request.
			 *
			 * Re-running the check costs two hash lookups, which is cheaper than
			 * being wrong. A positive is always safe to cache: TEC cannot
			 * un-load itself mid-request.
			 */
			if ( $available ) {
				self::$available = true;
			}

			return $available;
		}

		/**
		 * The events archive URL, or '' when there is no usable one.
		 *
		 * Lives HERE because this file is the only one allowed to know TEC's
		 * internals. `tribe_get_events_link()` is preferred — it honours whatever
		 * the site has configured as its events page, including a static page — and
		 * the post-type archive is the fallback for a TEC old enough to lack it.
		 *
		 * Returns '' rather than a home-page URL when TEC is absent: a caller that
		 * cannot get a real destination must be able to tell, because sending a
		 * visitor somewhere that cannot answer their search is the defect this
		 * exists to avoid.
		 *
		 * @since 2.0.0
		 * @return string Absolute URL, or ''.
		 */
		public static function events_archive_url() {
			if ( ! self::available() ) {
				return '';
			}

			if ( function_exists( 'tribe_get_events_link' ) ) {
				$url = tribe_get_events_link();
				if ( is_string( $url ) && '' !== $url ) {
					return $url;
				}
			}

			$url = get_post_type_archive_link( 'tribe_events' );

			return is_string( $url ) ? $url : '';
		}


		/*
		 * DELIBERATELY ABSENT: a paid-add-on probe.
		 *
		 * A method here used to test whether TEC's PAID front-end filtering
		 * add-on had registered its bootstrap callback, and every `tec_views`
		 * gate consulted it — so our List-view integration disabled itself
		 * whenever that add-on was present.
		 *
		 * That coupling is gone, deliberately and permanently: our bar always
		 * runs when the admin asks for it. DO NOT REINTRODUCE A PROBE HERE, in
		 * any spelling — not a hook-registration lookup, not a main-file
		 * constant, not a class name. `Display\Tec_Views` documents the collision
		 * this trade-off accepts.
		 */

		/**
		 * Today's date in the SITE's timezone, as a bare `Y-m-d` string.
		 *
		 * This method exists to retire a verified 12-hour bug class.
		 * `tribe_beginning_of_day()` and `tribe_end_of_day()` are internally bare
		 * `date()` / `strtotime()` calls, and WordPress forces PHP's default
		 * timezone to UTC (`wp-settings.php`). Calling either with `null` therefore
		 * resolves "today" in **UTC**, not in the site's timezone — so on any site
		 * west of UTC the boundary is a day early for part of the day, and east of
		 * UTC a day late. Only the *date component* of an explicit argument
		 * survives those helpers, which is why this returns a date-only string.
		 *
		 * Every caller passes this explicit string. No file in this plugin may
		 * call the raw TEC helpers with `null`, a timestamp, or a full datetime.
		 *
		 * (The helpers' handling of TEC's `multiDayCutoff` option is correct and
		 * is preserved by going through them rather than reimplementing them.)
		 *
		 * @since 2.0.0
		 * @return string Site-local date in `Y-m-d` format.
		 */
		public static function today() {
			return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		}

		/**
		 * Start-of-day boundary for a site-local date.
		 *
		 * Wraps `tribe_beginning_of_day()` so no other file in the plugin ever
		 * calls the raw helper — that is the single enforcement point for the
		 * explicit-date-string rule documented on `today()`.
		 *
		 * @since 2.0.0
		 * @param string|null $date Site-local date as `Y-m-d`. Defaults to `today()`.
		 * @return string TEC-formatted datetime, or '' when TEC is unavailable.
		 */
		public static function beginning_of_day( $date = null ) {
			if ( ! self::available() || ! function_exists( 'tribe_beginning_of_day' ) ) {
				return '';
			}

			$date = self::normalize_date( $date );

			return (string) tribe_beginning_of_day( $date );
		}

		/**
		 * End-of-day boundary for a site-local date.
		 *
		 * Wraps `tribe_end_of_day()`; see `beginning_of_day()` and `today()`.
		 *
		 * @since 2.0.0
		 * @param string|null $date Site-local date as `Y-m-d`. Defaults to `today()`.
		 * @return string TEC-formatted datetime, or '' when TEC is unavailable.
		 */
		public static function end_of_day( $date = null ) {
			if ( ! self::available() || ! function_exists( 'tribe_end_of_day' ) ) {
				return '';
			}

			$date = self::normalize_date( $date );

			return (string) tribe_end_of_day( $date );
		}

		/**
		 * Coerce a caller-supplied date to the bare `Y-m-d` string the TEC day
		 * helpers require.
		 *
		 * Anything that is not a usable date-only string falls back to
		 * `today()` rather than being passed through, so a careless caller can
		 * never reintroduce the null/timestamp path.
		 *
		 * @since 2.0.0
		 * @param mixed $date Candidate date value.
		 * @return string Site-local date in `Y-m-d` format.
		 */
		private static function normalize_date( $date ) {
			if ( ! is_string( $date ) ) {
				return self::today();
			}

			$date = trim( $date );

			// Take only the date component: the helpers discard the time anyway,
			// and accepting a full datetime here invites callers to pass one.
			if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})/', $date, $matches ) ) {
				return self::today();
			}

			return $matches[1];
		}

		/**
		 * Visibility guard: may this post be surfaced to a public request?
		 *
		 * True only for a published `tribe_events` post that is not
		 * password-protected.
		 *
		 * The password check is load-bearing, not belt-and-braces. A
		 * password-protected post keeps `post_status = 'publish'`, so filtering
		 * on status alone does NOT exclude it. TEC's search matches
		 * `post_content`, which turns an unfiltered search into a content
		 * oracle: an anonymous visitor could confirm words inside a protected
		 * event's body by watching which queries return it, and read its title
		 * and date from the result card — without ever supplying the password.
		 *
		 * This is the last line of defence. The password exclusion is also
		 * applied at query level (events, `/suggest`, both two-pass
		 * venue/organizer repos and the facet ID set) so protected posts are
		 * never fetched in the first place.
		 *
		 * @since 2.0.0
		 * @param mixed $post Candidate post (anything; non-posts return false).
		 * @return bool True when the post may be shown publicly.
		 */
		public static function visible( $post ) {
			return $post instanceof \WP_Post
				&& 'tribe_events' === $post->post_type
				&& 'publish' === $post->post_status
				&& ! post_password_required( $post );
		}
	}
}
