<?php
/**
 * Read-time mapping from v1.3.6 shortcode input to v2 criteria.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.0.0
 */

namespace CoolPlugins\EventsSearch\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CoolPlugins\EventsSearch\Compat\Legacy_Map' ) ) {

	/**
	 * Translates v1.3.6 attribute keys into v2 config keys.
	 *
	 * THE ONLY LEGACY SURFACE. v2.0 never shipped, so no install on earth can
	 * hold an intermediate 2.0 spelling; the three maps that used to translate
	 * one 2.0-internal name into a later 2.0-internal name (`Settings::map_legacy()`,
	 * `Shortcode::map_deprecated()`, `Instance::map_legacy_display()`) are gone.
	 * What remains is exactly the v1.3.6 vocabulary, which IS in ~3,000 installs'
	 * published content. Do not grow this class with anything else.
	 *
	 * READ-TIME ONLY. Nothing in this class writes, rewrites or deletes a
	 * stored option row — that is precisely what keeps a downgrade to 1.3.6
	 * lossless (plan §1.6, a tested assertion).
	 *
	 * SPARSE BY DESIGN. Only keys whose 1.3.6 attribute was actually supplied
	 * are emitted. A total array (every key, defaults filled in) would make a
	 * bare `[events-calendar-search]` silently override the admin's saved
	 * per_page / control size / time window with this class's fallbacks, which
	 * is the opposite of "an absent attribute inherits".
	 *
	 * Every method is a pure function: no option reads, no globals, no hooks.
	 * The only WordPress dependencies are `sanitize_text_field()` and
	 * `absint()`, so the class can be exercised in isolation by stubbing those
	 * two functions alone.
	 *
	 * Unknown keys are DISCARDED rather than carried through — a v2 config
	 * array only ever contains keys this class knows how to produce.
	 *
	 * @since 2.0.0
	 */
	final class Legacy_Map {

		/**
		 * v1.3.6 `layout` -> the v2 numeric `control_size` percent scale.
		 *
		 * The numbers come from the 1.3.6 stylesheet, where the three layouts set
		 * `font-size: 14px / 16px / 20px` against a 16px base — 88% / 100% / 125%.
		 *
		 * INPUT HEIGHT IS DELIBERATELY NOT MATCHED, and this is not an oversight
		 * to "fix" later: 1.3.6's `large` layout drew a 90px-tall input, which
		 * against v2's 40px control would need `control_size = 205`, well past the
		 * 140 cap `Instance::CONTROL_SIZE_MAX` enforces for every other surface.
		 * Raising that cap for one legacy value would let a 2020 shortcode paint a
		 * bar no v2 setting can produce. Type scale is the honest half of the
		 * mapping; height is not portable and is not carried over.
		 *
		 * @var array<string, int>
		 */
		const CONTROL_SIZES = array(
			'small'  => 88,
			'medium' => 100,
			'large'  => 125,
		);

		/**
		 * The v1.3.6 SHORTCODE default for `layout`.
		 *
		 * Recorded rather than applied: an absent `layout` must inherit the
		 * admin's saved control size (three-tier), and `medium` maps to 100,
		 * which is `Instance::CONTROL_SIZE_DEFAULT` anyway — so an un-configured
		 * site lands on exactly the 1.3.6 shortcode default either way.
		 */
		const LAYOUT_DEFAULT_SHORTCODE = 'medium';

		/**
		 * The v1.3.6 WIDGET default for `layout`.
		 *
		 * The classic widget defaulted to `small`, not `medium`. It is preserved
		 * at the widget itself, which passes an explicit `layout` on every render
		 * (`EventsCalendarSearchAddonWidget::widget()`), so the two entry points
		 * keep their different defaults without this class needing two code paths.
		 */
		const LAYOUT_DEFAULT_WIDGET = 'small';

		/**
		 * Lowest accepted `per_page`.
		 */
		const PER_PAGE_MIN = 1;

		/**
		 * Highest accepted `per_page`.
		 *
		 * Bounds the results page so a legacy `show-events="9999"` cannot turn
		 * into an unbounded query downstream.
		 */
		const PER_PAGE_MAX = 50;

		/**
		 * Fallback `per_page` when `show-events` was supplied but unusable
		 * (empty, zero, negative or non-numeric).
		 *
		 * The 1.3.6 default was unreachable (`shortcode_atts` always set the
		 * key, so `absint( '' )` won and the real default never applied), which
		 * means no site ever deliberately chose it. 8 is the v2 dropdown size.
		 */
		const PER_PAGE_DEFAULT = 8;

		/**
		 * Bounds for the per-instance type-ahead suggestion count.
		 *
		 * `show-events` is what 1.3.6 actually controlled: it had no results
		 * grid at all, only the suggestion dropdown, so the honest v2 target is
		 * the suggestion limit. The MAX is a hard server-side ceiling, mirrored
		 * by `Rest_Controller::SUGGEST_LIMIT_MAX` — a public
		 * route is capped regardless of what an instance asks for.
		 */
		const SUGGEST_LIMIT_MIN     = 1;
		const SUGGEST_LIMIT_MAX     = 20;
		const SUGGEST_LIMIT_DEFAULT = 10;

		/**
		 * Maximum stored placeholder length, in characters.
		 */
		const PLACEHOLDER_MAX = 80;

		/**
		 * Map a v1.3.6 shortcode attribute array to v2 config keys.
		 *
		 * Accepts the hyphenated attribute names used by
		 * `[events-calendar-search]`. `content-type` is READ AND DISCARDED: the
		 * attribute stays declared on the shortcode so a published
		 * `[events-calendar-search content-type="basic"]` is not treated as
		 * carrying an unknown attribute, but the basic/advance
		 * card split is out of scope, so computing a value nothing consumes would
		 * be dead work.
		 *
		 * @since 2.0.0
		 * @param array $atts Raw shortcode attributes, hyphenated keys.
		 * @return array<string, mixed> Sparse v2 config array: only the keys
		 *                              whose source attribute was supplied.
		 */
		public static function from_shortcode( array $atts ) {
			return self::normalize(
				array(
					'placeholder'   => self::pluck( $atts, 'placeholder' ),
					'per_page'      => self::pluck( $atts, 'show-events' ),
					'suggest_limit' => self::pluck( $atts, 'show-events' ),
					'time'          => self::pluck( $atts, 'disable-past-events' ),
					'control_size'  => self::pluck( $atts, 'layout' ),
				)
			);
		}

		/**
		 * Fetch a key from a raw input array without notices.
		 *
		 * Non-scalar values (a nested array from a hand-built shortcode call)
		 * collapse to null, and so does an empty string — an attribute present
		 * but blank carries no intent, so it must inherit like an absent one.
		 *
		 * @since 2.0.0
		 * @param array  $raw Source array.
		 * @param string $key Key to read.
		 * @return string|null Scalar value cast to string, or null when absent
		 *                     or blank.
		 */
		private static function pluck( array $raw, $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				return null;
			}

			$value = trim( (string) $raw[ $key ] );

			return '' !== $value ? $value : null;
		}

		/**
		 * Apply the allowlist, clamp and fallback logic.
		 *
		 * A null value means "the author did not supply this attribute", and the
		 * key is omitted entirely so the caller's own three-tier resolution
		 * (attribute -> site default -> shipped constant) runs untouched.
		 *
		 * @since 2.0.0
		 * @param array $raw Values already keyed by their v2 config names, each a
		 *                   non-empty string or null.
		 * @return array<string, mixed> Sparse v2 config array.
		 */
		private static function normalize( array $raw ) {
			$config = array();

			// placeholder: free text, sanitized and length-capped.
			if ( null !== $raw['placeholder'] ) {
				$config['placeholder'] = self::truncate(
					sanitize_text_field( (string) $raw['placeholder'] ),
					self::PLACEHOLDER_MAX
				);
			}

			/*
			 * per_page: the legacy default was unreachable, so a zero or junk
			 * value carries no user intent — fall back rather than clamp it up to
			 * PER_PAGE_MIN, which would silently mean "1 result".
			 */
			if ( null !== $raw['per_page'] ) {
				$per_page = absint( $raw['per_page'] );
				if ( 0 === $per_page ) {
					$per_page = self::PER_PAGE_DEFAULT;
				}
				$config['per_page'] = (int) min( self::PER_PAGE_MAX, max( self::PER_PAGE_MIN, $per_page ) );
			}

			/*
			 * suggest_limit: the same `show-events` attribute, mapped to what it
			 * actually governed in 1.3.6 — how many rows the type-ahead dropdown
			 * showed. Clamped to a hard ceiling here AND again at the REST
			 * boundary, because an instance value reaches the server as a query
			 * parameter and a public route never trusts one.
			 */
			if ( null !== $raw['suggest_limit'] ) {
				$limit = absint( $raw['suggest_limit'] );
				if ( 0 === $limit ) {
					$limit = self::SUGGEST_LIMIT_DEFAULT;
				}
				$config['suggest_limit'] = (int) min( self::SUGGEST_LIMIT_MAX, max( self::SUGGEST_LIMIT_MIN, $limit ) );
			}

			/*
			 * time: the legacy value is the literal STRING 'true'/'false' (the
			 * widget's <select> stored those words, not booleans). Only the
			 * exact string 'true' means "hide past events"; every other supplied
			 * value means the unfiltered 'all'. A naive (bool) cast makes the
			 * string 'false' truthy, which would flip every legacy instance to
			 * upcoming-only — do not "simplify" this.
			 */
			if ( null !== $raw['time'] ) {
				$disable_past   = strtolower( trim( (string) $raw['time'] ) );
				$config['time'] = ( 'true' === $disable_past ) ? 'upcoming' : 'all';
			}

			/*
			 * control_size: 1.3.6's `layout` word becomes the v2 numeric percent
			 * scale directly. Emitting small|medium|large would be a fourth
			 * vocabulary nothing else in v2 speaks, which is half the reason this
			 * value was computed and then dropped on the floor for two phases.
			 * An unrecognised word carries no intent, so the key is omitted and
			 * the admin's saved scale applies.
			 */
			if ( null !== $raw['control_size'] ) {
				$layout = strtolower( trim( (string) $raw['control_size'] ) );
				if ( isset( self::CONTROL_SIZES[ $layout ] ) ) {
					$config['control_size'] = (int) self::CONTROL_SIZES[ $layout ];
				}
			}

			return $config;
		}

		/**
		 * Cap a string to a character length, multibyte-safe when possible.
		 *
		 * `mb_substr()` is guarded because on bare PHP
		 * mbstring may be absent; `substr()` is an acceptable degradation for
		 * a display placeholder.
		 *
		 * @since 2.0.0
		 * @param string $value  Value to cap.
		 * @param int    $length Maximum length in characters.
		 * @return string
		 */
		private static function truncate( $value, $length ) {
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $value, 0, $length );
			}

			return substr( $value, 0, $length );
		}
	}
}
