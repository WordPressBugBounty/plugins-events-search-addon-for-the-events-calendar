<?php
/**
 * Cache — the two-tier store behind the query spine.
 *
 * THE FACT THIS CLASS EXISTS TO ENCODE: `tribe( 'cache' )->set()` ends in
 * `wp_cache_set()`, which without a persistent object cache — i.e. on most
 * WordPress sites — is REQUEST-SCOPED. Anything cached through it is gone by the
 * next request, so using it for facet counts or found-totals means every visitor
 * pays full price for every aggregate query while the code reads as though it
 * were cached. Anything that must survive the request is written with
 * `set_transient()`.
 *
 * Two tiers, in read order:
 *   Tier 1 — a private static array. Within-request memoization; free, and the
 *            reason a Month view that resolves the same Criteria three times
 *            only pays once.
 *   Tier 2 — transients. Non-autoloaded, fixed-length keys derived from
 *            `Criteria::hash()`, soft-expiry envelopes so a stampede can be
 *            answered with slightly stale data instead of a duplicate query.
 *
 * WHY OUR OWN VERSION COUNTER (`version()`/`bump()`) AND NOT A GLOBAL
 * `save_post` TIMESTAMP: a global timestamp invalidates every cached
 * combination at the same instant, so the first request after any event edit
 * faces a completely cold cache across every facet permutation at once — a
 * guaranteed stampede, on a schedule set by however often the site's editors
 * work. Our counter is bumped by the same events, but it is a value we control,
 * it is the only thing a flush has to touch when an object cache is in play, and
 * it composes into the key rather than replacing it.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Cache' ) ) {

	/**
	 * Request memoization + transient store, with a version counter, a stampede
	 * lock and a keyword-bounded cacheability policy.
	 *
	 * @since 2.0.0
	 */
	final class Cache {

		/**
		 * Key prefix. Every transient this plugin writes starts with it, which is
		 * what makes `flush()` and `uninstall.php` able to sweep by LIKE.
		 */
		const PREFIX = 'ecsa_';

		/**
		 * Option holding our monotonic invalidation counter. Non-autoloaded.
		 */
		const OPTION_VERSION = 'ecsa_cache_version';

		/**
		 * Stampede lock lifetime, in seconds.
		 */
		const LOCK_TTL = 30;

		/**
		 * Grace window, in seconds, that a value stays physically readable AFTER
		 * its soft expiry.
		 *
		 * The transient's real TTL is `$ttl + GRACE`. Without this there is no
		 * such thing as a stale value to serve — an expired transient is deleted,
		 * so every process that arrives after expiry would have to run the
		 * producer, which is precisely the pile-on the lock exists to prevent.
		 */
		const GRACE = 300;

		/**
		 * Long TTL — absolute date ranges and facet counts.
		 */
		const TTL_LONG = 900;

		/**
		 * Short TTL — anything carrying a keyword.
		 */
		const TTL_SHORT = 300;

		/**
		 * Longest keyword that may participate in a cache key.
		 *
		 * See `is_cacheable()` for why this is a security bound and not a
		 * performance tuning knob.
		 */
		const MAX_CACHEABLE_KEYWORD = 20;

		/**
		 * Deepest page a keyword-free payload may cache on a transient backend.
		 *
		 * Beyond it a cache entry is almost never re-read, but each one is a
		 * `wp_options` row an anonymous visitor can mint by walking page
		 * numbers. With an external object cache the bound does not apply.
		 *
		 * @since 2.0.1
		 * @var int
		 */
		const MAX_CACHEABLE_PAGE = 5;

		/**
		 * Tier 1: within-request memoization. Keyed by full (versioned) key.
		 *
		 * @since 2.0.0
		 * @var array<string, array<string, mixed>>
		 */
		private static $memo = array();

		/**
		 * Memoized version counter. `null` = not yet read.
		 *
		 * @since 2.0.0
		 * @var int|null
		 */
		private static $version = null;

		/**
		 * Whether `init()` has already registered its hooks.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Taxonomies whose term edits invalidate our caches.
		 *
		 * Hardcoded, never derived from input — the term hooks fire for every
		 * taxonomy on the site and we only care about two.
		 */
		const WATCHED_TAXONOMIES = array( 'tribe_events_cat', 'post_tag' );

		/**
		 * Post types whose saves/deletes invalidate our caches.
		 */
		const WATCHED_POST_TYPES = array( 'tribe_events', 'tribe_venue', 'tribe_organizer' );

		/**
		 * Register invalidation hooks.
		 *
		 * Nothing runs at include time; the plugin bootstrap calls this.
		 * Idempotent, because a double `init()` would double-bump the counter on
		 * every save and halve the effective cache lifetime for no reason.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function init() {
			if ( true === self::$booted ) {
				return;
			}

			self::$booted = true;

			foreach ( self::WATCHED_POST_TYPES as $post_type ) {
				add_action( 'save_post_' . $post_type, array( __CLASS__, 'bump' ), 10, 1 );
			}

			add_action( 'deleted_post', array( __CLASS__, 'bump_for_deleted_post' ), 10, 2 );

			/*
			 * Term hooks fire for EVERY taxonomy on the site, so each handler
			 * filters on the taxonomy argument. `delete_term` passes the taxonomy
			 * in the same third position as `edited_term`/`created_term`, which is
			 * why one handler serves all three.
			 */
			add_action( 'edited_term', array( __CLASS__, 'bump_for_term' ), 10, 3 );
			add_action( 'created_term', array( __CLASS__, 'bump_for_term' ), 10, 3 );
			add_action( 'delete_term', array( __CLASS__, 'bump_for_term' ), 10, 3 );
		}

		/**
		 * Read a cached value.
		 *
		 * Reads Tier 1 then Tier 2, and promotes a Tier 2 hit into Tier 1 so a
		 * second read in the same request costs nothing.
		 *
		 * Returns the sentinel `false` on a miss. Callers may safely cache a
		 * literal `false`/`null` value: values are stored inside an envelope, so
		 * the sentinel is never confused with a stored falsy payload.
		 *
		 * @since 2.0.0
		 * @param string $key Caller key, normally `Criteria::hash()`.
		 * @return mixed Cached value, or `false` when absent or past its grace.
		 */
		public static function get( $key ) {
			$envelope = self::read_envelope( self::full_key( $key ) );

			if ( null === $envelope ) {
				return false;
			}

			return $envelope['v'];
		}

		/**
		 * Write a value to both tiers.
		 *
		 * The transient's real lifetime is `$ttl + GRACE`; `$ttl` is recorded
		 * inside the envelope as a soft expiry, which is what `remember()` reads.
		 *
		 * @since 2.0.0
		 * @param string $key   Caller key.
		 * @param mixed  $value Value to store (may be falsy).
		 * @param int    $ttl   Soft lifetime in seconds.
		 * @return bool True when the transient write was accepted.
		 */
		public static function set( $key, $value, $ttl ) {
			$ttl  = max( 1, (int) $ttl );
			$full = self::full_key( $key );

			$envelope = array(
				'v' => $value,
				'e' => time() + $ttl,
			);

			self::$memo[ $full ] = $envelope;

			return (bool) set_transient( $full, $envelope, $ttl + self::GRACE );
		}

		/**
		 * Drop a single key from both tiers.
		 *
		 * @since 2.0.0
		 * @param string $key Caller key.
		 * @return void
		 */
		public static function delete( $key ) {
			$full = self::full_key( $key );

			unset( self::$memo[ $full ] );

			delete_transient( $full );
		}

		/**
		 * Get-or-produce, with a stampede lock.
		 *
		 * A fresh value is returned immediately. Past the soft expiry, exactly one
		 * process takes a 30-second lock and runs the producer; anyone else who
		 * arrives while that lock is held and who has ANY value in the grace window
		 * gets the stale value rather than piling a second copy of the most
		 * expensive query in the request onto an already-busy database. A process
		 * that finds the lock held but has nothing stale to serve must still
		 * produce — returning empty would render an empty results page.
		 *
		 * @since 2.0.0
		 * @param string   $key      Caller key.
		 * @param int      $ttl      Soft lifetime in seconds.
		 * @param callable $producer Zero-argument producer.
		 * @return mixed The cached, stale or freshly produced value.
		 */
		public static function remember( $key, $ttl, callable $producer ) {
			$full     = self::full_key( $key );
			$envelope = self::read_envelope( $full );

			if ( null !== $envelope && time() < (int) $envelope['e'] ) {
				return $envelope['v'];
			}

			$lock     = substr( $full, 0, 150 ) . '_lk';
			$acquired = false;

			if ( false !== get_transient( $lock ) ) {
				if ( null !== $envelope ) {
					// Someone else is already recomputing. Slightly stale beats a
					// duplicate aggregate query.
					return $envelope['v'];
				}
			} else {
				set_transient( $lock, 1, self::LOCK_TTL );
				$acquired = true;
			}

			$value = call_user_func( $producer );

			self::set( $key, $value, $ttl );

			if ( true === $acquired ) {
				delete_transient( $lock );
			}

			return $value;
		}

		/**
		 * Our monotonic cache version.
		 *
		 * Every key incorporates it, so a bump invalidates logically without
		 * touching a single row — which is the only invalidation that works when
		 * an external object cache is serving the transients and there is nothing
		 * in `wp_options` to delete.
		 *
		 * @since 2.0.0
		 * @return int Always >= 1.
		 */
		public static function version() {
			if ( null !== self::$version ) {
				return self::$version;
			}

			$stored = get_option( self::OPTION_VERSION );

			if ( false === $stored ) {
				// Fourth argument is autoload: this must never be autoloaded, it is
				// read only on paths that are about to hit the cache anyway.
				add_option( self::OPTION_VERSION, 1, '', 'no' );
				$stored = 1;
			}

			$stored = (int) $stored;

			self::$version = $stored > 0 ? $stored : 1;

			return self::$version;
		}

		/**
		 * Increment the version counter, invalidating every existing key.
		 *
		 * Also clears Tier 1: within one request, code that bumps and then reads
		 * (a bulk edit, an importer) must not be served its own pre-bump memo.
		 *
		 * Accepts and ignores an argument so it can be hooked directly to
		 * `save_post_*`.
		 *
		 * @since 2.0.0
		 * @param mixed $unused Hook argument, ignored.
		 * @return int The new version.
		 */
		public static function bump( $unused = null ) {
			unset( $unused );

			$next = self::version() + 1;

			// Wrap well short of PHP_INT_MAX rather than relying on float
			// coercion at the boundary. A wrap only risks colliding with keys
			// that expired billions of edits ago.
			if ( $next >= 2147483647 ) {
				$next = 1;
			}

			update_option( self::OPTION_VERSION, $next, false );

			self::$version = $next;
			self::$memo    = array();

			return $next;
		}

		/**
		 * Bump when one of our watched post types is deleted.
		 *
		 * `deleted_post` fires for every post type on the site. When the post
		 * object is still available we filter on it; when it is not, we bump
		 * anyway — an unnecessary invalidation is cheap, a stale facet count that
		 * links to a deleted venue is a visible bug.
		 *
		 * @since 2.0.0
		 * @param int          $post_id Deleted post ID.
		 * @param \WP_Post|null $post   Deleted post object, when available.
		 * @return void
		 */
		public static function bump_for_deleted_post( $post_id, $post = null ) {
			unset( $post_id );

			if ( $post instanceof \WP_Post && ! in_array( $post->post_type, self::WATCHED_POST_TYPES, true ) ) {
				return;
			}

			self::bump();
		}

		/**
		 * Bump when a term in one of our two taxonomies changes.
		 *
		 * Shared by `edited_term`, `created_term` and `delete_term`, all of which
		 * pass the taxonomy as the third argument.
		 *
		 * @since 2.0.0
		 * @param int    $term_id  Term ID.
		 * @param int    $tt_id    Term taxonomy ID.
		 * @param string $taxonomy Taxonomy name.
		 * @return void
		 */
		public static function bump_for_term( $term_id, $tt_id, $taxonomy ) {
			unset( $term_id, $tt_id );

			if ( ! is_string( $taxonomy ) || ! in_array( $taxonomy, self::WATCHED_TAXONOMIES, true ) ) {
				return;
			}

			self::bump();
		}

		/**
		 * Delete every transient this plugin owns. Powers "Clear search cache"
		 * on the Advanced tab.
		 *
		 * `esc_like()` is MANDATORY, not defensive: `_` is a single-character LIKE
		 * wildcard, so an unescaped `_transient_ecsa_%` also matches other
		 * plugins' rows (`Xtransient1ecsa...`). The timeout twin
		 * (`_transient_timeout_*`) is a separate row and is swept in the same
		 * statement — deleting only the value rows leaves orphaned timeouts
		 * behind forever.
		 *
		 * The version bump at the end is the part that actually works when an
		 * external object cache is installed: in that configuration transients
		 * never reach `wp_options`, so the DELETE matches nothing and only the
		 * counter can invalidate them.
		 *
		 * @since 2.0.0
		 * @return int Rows deleted.
		 */
		public static function flush() {
			global $wpdb;

			self::$memo = array();

			$rows = 0;

			if ( isset( $wpdb ) && is_object( $wpdb ) ) {
				$value_like   = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
				$timeout_like = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate cache sweep; no core API deletes transients by prefix.
				$rows = (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
						$value_like,
						$timeout_like
					)
				);
			}

			self::bump();

			return $rows;
		}

		/**
		 * May this criteria array be cached at all?
		 *
		 * `q` is attacker-controlled free text, and on a site with no persistent
		 * object cache a transient IS A ROW IN `wp_options`. Caching an unbounded
		 * keyword space therefore hands an anonymous visitor a write primitive
		 * against the options table: a script iterating distinct search terms
		 * inserts two rows per term, and the autoloaded-options query is one of
		 * the few things on a WordPress request that degrades the whole site
		 * rather than one page. The keyword ceiling and the fixed-length hashed
		 * key (`Criteria::hash()`) are the two halves of the mitigation.
		 *
		 * The second rule — keyword-bearing payloads are not cached without an
		 * external object cache — follows from the same reasoning: with a real
		 * object cache the bounded write lands in memory that evicts under
		 * pressure, which is an acceptable place to put attacker-influenced keys;
		 * `wp_options` is not.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $criteria Normalized criteria.
		 * @return bool True when a cache write is permitted.
		 */
		public static function is_cacheable( array $criteria ) {
			$q = isset( $criteria['q'] ) ? (string) $criteria['q'] : '';

			if ( '' === $q ) {
				if ( wp_using_ext_object_cache() ) {
					return true;
				}

				/*
				 * Transient backend: only DEFAULT-SHAPED payloads may claim a
				 * `wp_options` row. Page depth, ID lists, a location and a
				 * custom date range each multiply the key space without a
				 * keyword — `Criteria::hash()` folds all of them in — so an
				 * anonymous visitor walking `?ecsa_page=` or fabricated term
				 * IDs would otherwise mint unbounded rows exactly as a keyword
				 * loop would. What stays cacheable is the hot set: the first
				 * pages of the preset views, which is where cache hits
				 * actually happen.
				 */
				$page = isset( $criteria['page'] ) ? (int) $criteria['page'] : 1;

				if ( $page > self::MAX_CACHEABLE_PAGE ) {
					return false;
				}

				foreach ( array( 'categories', 'tags', 'venues', 'organizers' ) as $ids ) {
					if ( ! empty( $criteria[ $ids ] ) ) {
						return false;
					}
				}

				if ( ! empty( $criteria['location'] ) && is_array( $criteria['location'] ) && array_filter( $criteria['location'] ) ) {
					return false;
				}

				if ( ! empty( $criteria['date_from'] ) || ! empty( $criteria['date_to'] ) ) {
					return false;
				}

				return true;
			}

			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $q, 'UTF-8' ) : strlen( $q );

			if ( $length > self::MAX_CACHEABLE_KEYWORD ) {
				return false;
			}

			return (bool) wp_using_ext_object_cache();
		}

		/**
		 * Choose a TTL for a criteria array.
		 *
		 * Keyword-bearing payloads get the short TTL: they are the least reusable
		 * thing we compute, so a long lifetime buys almost no hits while holding
		 * attacker-influenced keys resident for a quarter of an hour.
		 *
		 * A RELATIVE date preset (`today`, `this_week`, …) is additionally clamped
		 * to the seconds remaining until the site's next LOCAL midnight. Without
		 * that clamp a payload computed at 23:58 for "today" is served as "today"
		 * at 00:13 the next morning — showing yesterday's events under a label
		 * that says today, which is the same class of off-by-a-day defect the
		 * whole `Tec::today()` machinery exists to retire. Absolute ranges have no
		 * such dependency and keep the long TTL.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $criteria Normalized criteria.
		 * @return int TTL in seconds, always >= 1.
		 */
		public static function ttl_for( array $criteria ) {
			$q   = isset( $criteria['q'] ) ? (string) $criteria['q'] : '';
			$ttl = ( '' === $q ) ? self::TTL_LONG : self::TTL_SHORT;

			$preset = isset( $criteria['date_preset'] ) ? (string) $criteria['date_preset'] : 'any';

			if ( class_exists( __NAMESPACE__ . '\Criteria' ) && Criteria::is_relative_preset( $preset ) ) {
				$ttl = min( $ttl, self::seconds_to_local_midnight() );
			}

			/**
			 * Filter the cache TTL for a criteria array.
			 *
			 * @since 2.0.0
			 * @param int   $ttl      TTL in seconds.
			 * @param array $criteria Normalized criteria.
			 */
			$ttl = (int) apply_filters( 'ecsa_cache_ttl', $ttl, $criteria );

			// Floored at 1, NOT at some comfortable minimum: when eleven seconds
			// remain before midnight, eleven seconds is the correct answer.
			return max( 1, $ttl );
		}

		/**
		 * Seconds from now until the site's next local midnight.
		 *
		 * Computed through `wp_timezone()` and compared as UTC timestamps, so a
		 * DST transition inside the window yields the true elapsed seconds rather
		 * than a nominal 24-hour arithmetic result.
		 *
		 * @since 2.0.0
		 * @return int Seconds remaining, at least 1.
		 */
		private static function seconds_to_local_midnight() {
			$now = new \DateTimeImmutable( 'now', wp_timezone() );

			$midnight = $now->modify( 'tomorrow midnight' );

			if ( ! $midnight instanceof \DateTimeImmutable ) {
				return self::TTL_SHORT;
			}

			return max( 1, $midnight->getTimestamp() - $now->getTimestamp() );
		}

		/**
		 * Build the versioned, length-bounded transient key.
		 *
		 * Keys must stay short: WordPress caps an option name at 191 characters on
		 * a utf8mb4 install, and a transient writes both `_transient_{key}` and
		 * `_transient_timeout_{key}`. Callers pass a 32-character
		 * `Criteria::hash()`, which this keeps well inside the bound; anything
		 * else is stripped to the safe alphabet and truncated rather than trusted.
		 *
		 * @since 2.0.0
		 * @param string $key Caller key.
		 * @return string
		 */
		private static function full_key( $key ) {
			$key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );
			$key = substr( (string) $key, 0, 40 );

			return self::PREFIX . self::version() . '_' . $key;
		}

		/**
		 * Read an envelope from Tier 1, then Tier 2.
		 *
		 * Returns `null` for a miss or for anything that is not a well-formed
		 * envelope — a value written by an older plugin version, or a transient
		 * collision, must degrade to a miss and never be handed to a caller as a
		 * payload.
		 *
		 * @since 2.0.0
		 * @param string $full Versioned key.
		 * @return array<string, mixed>|null
		 */
		private static function read_envelope( $full ) {
			if ( isset( self::$memo[ $full ] ) ) {
				return self::$memo[ $full ];
			}

			$stored = get_transient( $full );

			if ( ! is_array( $stored ) || ! array_key_exists( 'v', $stored ) || ! isset( $stored['e'] ) ) {
				return null;
			}

			self::$memo[ $full ] = $stored;

			return $stored;
		}
	}
}
