<?php
/**
 * Date_Presets — the nine date presets, resolved in the SITE's timezone.
 *
 * This class exists to retire a verified 12-hour bug. v1.3.6 built its ranges
 * with bare `date()` / `strtotime()` and a `'Y-m-dTg:i'` format string, while
 * WordPress forces PHP's default timezone to UTC (`wp-settings.php`). The
 * result was a range computed for UTC "today" and compared against site-local
 * event times — a boundary that is a day early west of UTC and a day late east
 * of it, for part of every day.
 *
 * The rules that keep that from coming back:
 *
 * - EVERY date calculation runs on a timezone-aware `DateTimeImmutable`
 *   constructed with `wp_timezone()`. No bare `date()`, `strtotime()`,
 *   `mktime()` or `new DateTime()` appears in this file, and none may be added.
 * - Day arithmetic uses `->modify( '+1 day' )`, which is CALENDAR-correct. A
 *   `+86400` seconds jump lands on the same calendar day (or skips one)
 *   whenever it crosses a DST transition.
 * - Day boundaries go through `Tec::beginning_of_day()` / `Tec::end_of_day()`
 *   with an explicit `Y-m-d` string, so TEC's `multiDayCutoff` setting is
 *   honoured. A pure `00:00:00` / `23:59:59` fallback covers the TEC-absent
 *   case and keeps this class testable without booting WordPress.
 * - Week boundaries derive from the `start_of_week` option, and the weekend
 *   derives from the week start (the two days preceding it), overridable via
 *   `ecsa_weekend_days`. A hardcoded Monday week start or Sat–Sun weekend is
 *   simply wrong across MENA and South Asia.
 *
 * All returned values are site-local `Y-m-d H:i:s` strings — the format TEC's
 * ORM expects for `date_overlaps` / `on_date`. `any` returns two empty strings,
 * meaning "no date restriction"; a `custom` range may return one empty string,
 * meaning that side is open-ended.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Query;

use CoolPlugins\EventsSearch\Tec\Tec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Date_Presets' ) ) {

	/**
	 * Resolves a date preset into a site-local start/end pair.
	 *
	 * @since 2.0.0
	 */
	final class Date_Presets {

		/**
		 * The datetime format every boundary is returned in — the format TEC's
		 * repository accepts for `date_overlaps` and `on_date`.
		 */
		const FORMAT = 'Y-m-d H:i:s';

		/**
		 * Hard ceiling, in seconds, on the cache lifetime of a relative preset.
		 *
		 * A relative preset ("today", "this week") stops being true at the next
		 * site-local midnight, so its `Cache-Control: max-age` is the distance to
		 * that midnight. The ceiling bounds how stale an intermediate cache can
		 * get if the site's timezone changes under us.
		 */
		const MAX_RELATIVE_TTL = 3600;

		/**
		 * Fallback week start when `start_of_week` is missing or out of range.
		 *
		 * `1` = Monday, matching both the WordPress default and ISO-8601.
		 */
		const DEFAULT_START_OF_WEEK = 1;

		/**
		 * Resolve a preset into a site-local `array( $start, $end )` pair.
		 *
		 * Total function: an unknown preset degrades to `any` rather than
		 * throwing, so a stale URL or a hand-edited shortcode can never fatal a
		 * front-end render.
		 *
		 * @since 2.0.0
		 * @param string                    $preset   One of the nine preset keys.
		 * @param array<string, mixed>      $criteria Normalized criteria; only `custom` reads
		 *                                            `date_from` / `date_to` from it.
		 * @param \DateTimeInterface|null   $now      Optional "now" override, for tests. Converted
		 *                                            into the site timezone before use.
		 * @return array{0:string,1:string} Site-local `Y-m-d H:i:s` bounds. Either side may be ''
		 *                                  (no restriction on that side); `any` returns both empty.
		 */
		public static function range( $preset, array $criteria = array(), $now = null ) {
			$preset = is_string( $preset ) ? $preset : 'any';

			/*
			 * Anchor every day/week/month calculation at local noon.
			 *
			 * Only the DATE component of the anchor is ever read, and noon is the
			 * one wall-clock time no real timezone has ever skipped or repeated.
			 * Anchoring there makes the arithmetic immune to a DST transition that
			 * lands on midnight (Cuba, Chile, Iran and others do exactly that),
			 * where `->modify( '+1 day' )` on a 00:00 value would be normalized
			 * into 01:00 — harmless here, but only by accident.
			 */
			$anchor = self::now( $now )->setTime( 12, 0, 0 );

			switch ( $preset ) {
				case 'today':
					return self::span( $anchor, $anchor );

				case 'tomorrow':
					$day = $anchor->modify( '+1 day' );

					return self::span( $day, $day );

				case 'this_weekend':
					return self::weekend_span( $anchor );

				case 'this_week':
					$start = self::week_start( $anchor );

					return self::span( $start, $start->modify( '+6 days' ) );

				case 'next_week':
					$start = self::week_start( $anchor )->modify( '+7 days' );

					return self::span( $start, $start->modify( '+6 days' ) );

				case 'this_month':
					$start = $anchor->modify( 'first day of this month' );

					return self::span( $start, $start->modify( 'last day of this month' ) );

				case 'next_month':
					/*
					 * Two steps, deliberately.
					 *
					 * A bare `+1 month` from 31 January overflows into 3 March,
					 * silently skipping February — the single most common date bug
					 * in WordPress plugins. Normalizing to the first of the month
					 * BEFORE adding a month makes overflow arithmetically
					 * impossible: every month has a first day.
					 */
					$start = $anchor->modify( 'first day of this month' )->modify( '+1 month' );

					return self::span( $start, $start->modify( 'last day of this month' ) );

				case 'custom':
					return self::custom_span( $criteria );

				case 'any':
				default:
					return array( '', '' );
			}
		}

		/**
		 * The two ISO weekday numbers that make up "the weekend" on this site.
		 *
		 * Derived from `start_of_week`: the weekend is the two days immediately
		 * PRECEDING the start of the week. With a Monday week start that is
		 * Saturday + Sunday; with a Saturday week start (common across MENA) it is
		 * Thursday + Friday.
		 *
		 * The derivation is a good default, not a universal truth, so it is
		 * filterable. A filter that returns anything other than two integers in
		 * 1..7 is discarded in favour of the derived value — a malformed override
		 * must not be able to produce a nonsensical range.
		 *
		 * @since 2.0.0
		 * @return array{0:int,1:int} Two ISO-8601 weekday numbers (1 = Monday … 7 = Sunday),
		 *                            in chronological order within the week.
		 */
		public static function weekend_days() {
			$iso_week_start = self::iso_week_start();
			$start_of_week  = (int) get_option( 'start_of_week', 1 );

			if ( 0 === $start_of_week || 1 === $start_of_week ) {
				/*
				 * Sunday and Monday week starts BOTH mean a Sat+Sun weekend.
				 *
				 * The "two days preceding the week start" rule is right for
				 * Saturday-start locales (Thu+Fri) but wrong for a Sunday
				 * start, where it yields Fri+Sat — so on the very common US
				 * configuration (Settings > General > Week Starts On = Sunday)
				 * "This weekend" would hide every Sunday event and wrongly
				 * include Friday. A Sunday week start does not imply a Friday
				 * weekend in any locale; it is a calendar-display preference.
				 */
				$days = array( 6, 7 );
			} else {
				$days = array(
					self::iso_shift( $iso_week_start, -2 ),
					self::iso_shift( $iso_week_start, -1 ),
				);
			}

			/**
			 * Filters the days considered "the weekend".
			 *
			 * @since 2.0.0
			 * @param array{0:int,1:int} $days ISO-8601 weekday numbers (1 = Monday … 7 = Sunday).
			 */
			$filtered = apply_filters( 'ecsa_weekend_days', $days );

			return self::valid_weekend( $filtered, $days );
		}

		/**
		 * Seconds remaining until the next site-local midnight, capped.
		 *
		 * Relative presets ("today", "this week") are only true until that
		 * midnight, so this is the `Cache-Control: max-age` a response carrying
		 * one may claim. Without it a CDN or page cache happily serves yesterday's
		 * "today" — exactly the drift described above.
		 *
		 * Always strictly positive: a zero max-age is indistinguishable from
		 * "uncacheable" to some intermediaries, and the value is only ever a
		 * ceiling on staleness.
		 *
		 * @since 2.0.0
		 * @param \DateTimeInterface|null $now Optional "now" override, for tests.
		 * @return int Seconds, in 1..MAX_RELATIVE_TTL.
		 */
		public static function seconds_to_midnight( $now = null ) {
			$now = self::now( $now );

			// Calendar-correct: on a DST day this is 23 or 25 hours away, and the
			// timestamp delta reflects that honestly.
			$midnight = $now->modify( '+1 day' )->setTime( 0, 0, 0 );

			$seconds = $midnight->getTimestamp() - $now->getTimestamp();

			if ( $seconds < 1 ) {
				$seconds = 1;
			}

			return (int) min( self::MAX_RELATIVE_TTL, $seconds );
		}

		/**
		 * Resolve the "this weekend" span.
		 *
		 * Means the NEXT occurrence of the weekend days. When today is already one
		 * of them the span starts today — a visitor searching on Saturday wants
		 * this weekend, not next — and always runs through the end of the later
		 * weekend day.
		 *
		 * Written to tolerate a non-consecutive pair from the `ecsa_weekend_days`
		 * filter rather than assuming the derived Sat/Sun-style adjacency.
		 *
		 * @since 2.0.0
		 * @param \DateTimeImmutable $anchor Site-local noon anchor for "now".
		 * @return array{0:string,1:string}
		 */
		private static function weekend_span( \DateTimeImmutable $anchor ) {
			$days  = self::weekend_days();
			$first = $days[0];
			$last  = $days[1];

			$today = (int) $anchor->format( 'N' );

			if ( $today === $first || $today === $last ) {
				// Mid-weekend: start now, run to the end of the later weekend day.
				$start  = $anchor;
				$offset = ( $last - $today + 7 ) % 7;
			} else {
				$start  = $anchor->modify( '+' . ( ( $first - $today + 7 ) % 7 ) . ' days' );
				$offset = ( $last - $first + 7 ) % 7;
			}

			return self::span( $start, $start->modify( '+' . $offset . ' days' ) );
		}

		/**
		 * Resolve the `custom` span from caller-supplied bounds.
		 *
		 * Either bound may be absent, in which case that side is open-ended. Both
		 * absent means no restriction at all — `Criteria::normalize()` already
		 * rewrites that case to `any`, but this class must not depend on having
		 * been called through it.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $criteria Criteria carrying `date_from` / `date_to`.
		 * @return array{0:string,1:string}
		 */
		private static function custom_span( array $criteria ) {
			$from = self::valid_date( isset( $criteria['date_from'] ) ? $criteria['date_from'] : '' );
			$to   = self::valid_date( isset( $criteria['date_to'] ) ? $criteria['date_to'] : '' );

			if ( '' === $from && '' === $to ) {
				return array( '', '' );
			}

			// A reversed range is a user error, not an empty result set.
			if ( '' !== $from && '' !== $to && $from > $to ) {
				$swap = $from;
				$from = $to;
				$to   = $swap;
			}

			$start = ( '' === $from ) ? '' : self::start_boundary( self::from_date( $from ) );
			$end   = ( '' === $to ) ? '' : self::end_boundary( self::from_date( $to ) );

			return array( $start, $end );
		}

		/**
		 * Build a start/end pair from two day anchors.
		 *
		 * @since 2.0.0
		 * @param \DateTimeImmutable $start_day First day of the span.
		 * @param \DateTimeImmutable $end_day   Last day of the span (inclusive).
		 * @return array{0:string,1:string}
		 */
		private static function span( \DateTimeImmutable $start_day, \DateTimeImmutable $end_day ) {
			return array(
				self::start_boundary( $start_day ),
				self::end_boundary( $end_day ),
			);
		}

		/**
		 * Opening boundary of a site-local day.
		 *
		 * Prefers TEC's helper (via our access layer, which forces the explicit
		 * date-only argument) so `multiDayCutoff` is respected: a site whose day
		 * "starts" at 05:00 must not have late-night events fall out of "today".
		 * Falls back to literal local midnight when TEC is absent.
		 *
		 * @since 2.0.0
		 * @param \DateTimeImmutable $day Any moment on the target day.
		 * @return string Site-local `Y-m-d H:i:s`.
		 */
		private static function start_boundary( \DateTimeImmutable $day ) {
			$date = $day->format( 'Y-m-d' );

			if ( class_exists( Tec::class ) ) {
				$boundary = Tec::beginning_of_day( $date );

				if ( '' !== $boundary ) {
					return $boundary;
				}
			}

			return $day->setTime( 0, 0, 0 )->format( self::FORMAT );
		}

		/**
		 * Closing boundary of a site-local day.
		 *
		 * See `start_boundary()`.
		 *
		 * @since 2.0.0
		 * @param \DateTimeImmutable $day Any moment on the target day.
		 * @return string Site-local `Y-m-d H:i:s`.
		 */
		private static function end_boundary( \DateTimeImmutable $day ) {
			$date = $day->format( 'Y-m-d' );

			if ( class_exists( Tec::class ) ) {
				$boundary = Tec::end_of_day( $date );

				if ( '' !== $boundary ) {
					return $boundary;
				}
			}

			return $day->setTime( 23, 59, 59 )->format( self::FORMAT );
		}

		/**
		 * Coerce the caller's "now" into a site-local `DateTimeImmutable`.
		 *
		 * A supplied value is CONVERTED into the site timezone rather than
		 * reinterpreted, so passing a UTC instant yields the correct local
		 * wall-clock reading of that same instant.
		 *
		 * @since 2.0.0
		 * @param mixed $now Optional `DateTimeInterface`; anything else means "right now".
		 * @return \DateTimeImmutable Site-local.
		 */
		private static function now( $now ) {
			if ( $now instanceof \DateTimeImmutable ) {
				return $now->setTimezone( wp_timezone() );
			}

			if ( $now instanceof \DateTimeInterface ) {
				$immutable = new \DateTimeImmutable( '@' . $now->getTimestamp() );

				return $immutable->setTimezone( wp_timezone() );
			}

			return new \DateTimeImmutable( 'now', wp_timezone() );
		}

		/**
		 * First day of the week containing the anchor.
		 *
		 * @since 2.0.0
		 * @param \DateTimeImmutable $anchor Site-local noon anchor.
		 * @return \DateTimeImmutable Site-local noon on the week's first day.
		 */
		private static function week_start( \DateTimeImmutable $anchor ) {
			// `w` and `start_of_week` share the same 0 = Sunday numbering, which is
			// why the comparison is done in that scheme rather than in ISO.
			$today   = (int) $anchor->format( 'w' );
			$backoff = ( $today - self::start_of_week() + 7 ) % 7;

			if ( 0 === $backoff ) {
				return $anchor;
			}

			return $anchor->modify( '-' . $backoff . ' days' );
		}

		/**
		 * The site's `start_of_week` option, clamped to 0..6.
		 *
		 * @since 2.0.0
		 * @return int 0 = Sunday … 6 = Saturday.
		 */
		private static function start_of_week() {
			$value = (int) get_option( 'start_of_week', self::DEFAULT_START_OF_WEEK );

			if ( $value < 0 || $value > 6 ) {
				return self::DEFAULT_START_OF_WEEK;
			}

			return $value;
		}

		/**
		 * The site's week start expressed as an ISO-8601 weekday number.
		 *
		 * @since 2.0.0
		 * @return int 1 = Monday … 7 = Sunday.
		 */
		private static function iso_week_start() {
			$start = self::start_of_week();

			return ( 0 === $start ) ? 7 : $start;
		}

		/**
		 * Shift an ISO weekday number by a number of days, wrapping the week.
		 *
		 * @since 2.0.0
		 * @param int $iso   ISO weekday (1..7).
		 * @param int $delta Days to add (may be negative).
		 * @return int ISO weekday (1..7).
		 */
		private static function iso_shift( $iso, $delta ) {
			return (int) ( ( ( ( $iso - 1 + $delta ) % 7 ) + 7 ) % 7 ) + 1;
		}

		/**
		 * Accept a filtered weekend pair only if it is well-formed.
		 *
		 * @since 2.0.0
		 * @param mixed              $candidate Filtered value.
		 * @param array{0:int,1:int} $fallback  Derived value to use when the candidate is bad.
		 * @return array{0:int,1:int}
		 */
		private static function valid_weekend( $candidate, array $fallback ) {
			if ( ! is_array( $candidate ) ) {
				return $fallback;
			}

			$days = array_values( $candidate );

			if ( 2 !== count( $days ) ) {
				return $fallback;
			}

			foreach ( $days as $day ) {
				if ( ! is_numeric( $day ) || (int) $day < 1 || (int) $day > 7 ) {
					return $fallback;
				}
			}

			return array( (int) $days[0], (int) $days[1] );
		}

		/**
		 * Validate a `Y-m-d` string.
		 *
		 * `checkdate()` is load-bearing: `2026-02-31` matches the pattern and
		 * parses happily into 3 March, silently shifting the user's range.
		 *
		 * @since 2.0.0
		 * @param mixed $value Candidate date.
		 * @return string `Y-m-d`, or '' when unusable.
		 */
		private static function valid_date( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = trim( $value );

			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
				return '';
			}

			if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return '';
			}

			return $value;
		}

		/**
		 * Turn a validated `Y-m-d` string into a site-local noon anchor.
		 *
		 * Noon for the same reason `range()` anchors there: only the date is read,
		 * and midnight is a wall-clock time some timezones skip.
		 *
		 * @since 2.0.0
		 * @param string $date Validated `Y-m-d`.
		 * @return \DateTimeImmutable
		 */
		private static function from_date( $date ) {
			return new \DateTimeImmutable( $date . ' 12:00:00', wp_timezone() );
		}
	}
}
