<?php
/**
 * Rate limiter for the public REST surface.
 *
 * Part of the §4 "public REST complexity budget" that is the ONLY thing making
 * `permission_callback => '__return_true'` defensible. The
 * expensive half of a search request is a leading-wildcard LIKE plus a large
 * `IN ()`; the front end debounces it, but `curl` does not, so the throttle has
 * to live on the server.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Rate_Limit' ) ) {

	/**
	 * Transient-backed request throttle, keyed on a salted hash of the caller.
	 *
	 * @since 2.0.0
	 */
	final class Rate_Limit {

		/**
		 * Requests allowed per window.
		 */
		const LIMIT = 30;

		/**
		 * Window length in seconds.
		 */
		const WINDOW = 60;

		/**
		 * Transient key prefix.
		 */
		const PREFIX = 'ecsa_rl_';

		/**
		 * Hash characters kept for the bucket key.
		 *
		 * 32 hex characters is 128 bits — far past any practical collision —
		 * while keeping the resulting option name short, since a transient is a
		 * `wp_options` row on any site without a persistent object cache.
		 */
		const KEY_LENGTH = 32;

		/**
		 * Consume one token for this caller/route pair.
		 *
		 * Fixed-window counter rather than a true leaky bucket: it is the only
		 * shape that survives on `set_transient()` without a read-modify-write
		 * lock, and its worst case (a caller bursting across a window boundary
		 * gets up to 2x limit) is well inside what the endpoint can absorb. The
		 * counter is deliberately incremented BEFORE the limit test, so a caller
		 * that keeps hammering while throttled keeps the window alive rather
		 * than being handed a fresh allowance the moment it expires.
		 *
		 * @since 2.0.0
		 * @param string $route Route identifier, e.g. `/events`. Buckets are
		 *                      per-route so a burst of `/suggest` keystrokes
		 *                      cannot lock a visitor out of `/events`.
		 * @return true|\WP_Error True when the request may proceed, otherwise a
		 *                        429 error carrying `retry_after` in its data.
		 */
		public static function check( $route ) {
			/*
			 * Editors are never throttled: the settings screen's live preview
			 * legitimately fires far more requests than a visitor ever would,
			 * and throttling it would read as a broken plugin rather than a
			 * working defence.
			 */
			if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
				return true;
			}

			$config = self::config( $route );

			$limit  = (int) $config['limit'];
			$window = (int) $config['window'];

			// A non-positive limit or window is the documented "off" switch for
			// hosts that throttle at the edge already.
			if ( $limit <= 0 || $window <= 0 ) {
				return true;
			}

			$key = self::bucket_key( $route );
			$now = time();

			$bucket = get_transient( $key );

			if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['reset'] ) || $bucket['reset'] <= $now ) {
				$bucket = array(
					'count' => 0,
					'reset' => $now + $window,
				);
			}

			++$bucket['count'];

			$retry_after = max( 1, (int) $bucket['reset'] - $now );

			set_transient( $key, $bucket, $retry_after );

			if ( (int) $bucket['count'] > $limit ) {
				return new \WP_Error(
					'ecsa_rate_limited',
					__( 'Too many searches in a short time. Please wait a moment and try again.', 'events-search-addon-for-the-events-calendar' ),
					array(
						'status'      => 429,
						'retry_after' => $retry_after,
					)
				);
			}

			return true;
		}

		/**
		 * Resolve the limit/window pair for a route.
		 *
		 * @since 2.0.0
		 * @param string $route Route identifier.
		 * @return array<string, int> Keys `limit` and `window`.
		 */
		private static function config( $route ) {
			$defaults = array(
				'limit'  => self::LIMIT,
				'window' => self::WINDOW,
			);

			/**
			 * Filter the public REST rate limit.
			 *
			 * @since 2.0.0
			 * @param array<string, int> $config Keys `limit` (requests) and
			 *                                   `window` (seconds). Either at or
			 *                                   below zero disables throttling.
			 * @param string             $route  Route identifier.
			 */
			$config = apply_filters( 'ecsa_rate_limit', $defaults, $route );

			if ( ! is_array( $config ) ) {
				return $defaults;
			}

			return array(
				'limit'  => isset( $config['limit'] ) ? (int) $config['limit'] : self::LIMIT,
				'window' => isset( $config['window'] ) ? (int) $config['window'] : self::WINDOW,
			);
		}

		/**
		 * Build the transient key for the current caller and route.
		 *
		 * The raw IP is hashed and NEVER stored or logged: an option row named
		 * after a visitor's address is personal data at rest, readable by every
		 * plugin on the site and dumped into every database export. `wp_salt()`
		 * is mixed in so the hash is not a rainbow-table lookup away from the
		 * address it was derived from.
		 *
		 * @since 2.0.0
		 * @param string $route Route identifier.
		 * @return string Transient key.
		 */
		private static function bucket_key( $route ) {
			$route = is_string( $route ) ? $route : '';
			$salt  = function_exists( 'wp_salt' ) ? wp_salt() : '';

			return self::PREFIX . substr( hash( 'sha256', self::client_ip() . '|' . $route . '|' . $salt ), 0, self::KEY_LENGTH );
		}

		/**
		 * The caller's IP address, from `REMOTE_ADDR` only.
		 *
		 * `X-Forwarded-For` and friends are deliberately NOT consulted. They are
		 * plain request headers, so on any site not sitting behind a proxy that
		 * overwrites them, an attacker sets a new value per request, lands in a
		 * fresh bucket every time and the limiter becomes decorative. Trusting
		 * `REMOTE_ADDR` alone costs a shared bucket for visitors behind one
		 * reverse proxy, which is a throttle that is merely blunt rather than
		 * one that does not exist. Sites with a trusted proxy can widen the
		 * limit through `ecsa_rate_limit`.
		 *
		 * @since 2.0.0
		 * @return string Validated IP, or a constant stand-in.
		 */
		private static function client_ip() {
			if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
				return 'no-remote-addr';
			}

			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			$ip = filter_var( $ip, FILTER_VALIDATE_IP );

			return false === $ip ? 'invalid-remote-addr' : $ip;
		}
	}
}
