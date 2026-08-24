<?php
/**
 * Design-token derivation.
 *
 * THREE settings in — main (accent), background, text — FIFTEEN values out. Every
 * border, hover, tint, badge, muted label and focus ring in the bar is mixed from
 * those three, so there is nothing else to expose and no combination can produce an
 * illegible control.
 *
 * The stylesheet does the same mixing with `color-mix()` for browsers that support
 * it. This class exists for the three things CSS cannot do:
 *
 *  1. `on-accent` — whether text ON the accent should be white or near-black is a
 *     LUMINANCE comparison, which CSS has no function for. Hard-coding white breaks
 *     the moment someone picks a light accent (a lime or amber brand colour gets
 *     white-on-lime, ~1.4:1). Deriving it keeps every preset legible, light or dark.
 *  2. `accent-text` — the accent used AS TEXT, stepped toward the text colour until
 *     it clears WCAG AA on every surface it can land on. Also a luminance search,
 *     also impossible in CSS. See `accent_text()`.
 *  3. A concrete hex fallback for engines without `color-mix()`.
 *
 * Mix ratios and the contrast pick follow the design handoff's `derive-tokens.php`
 * verbatim, so the plugin and the design reference agree to the byte.
 *
 * @package CoolPlugins\EventsSearch
 * @since   2.1.0
 */

namespace CoolPlugins\EventsSearch\Render;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\Tokens' ) ) {

	/**
	 * Derives the full custom-property map from the three colour settings.
	 *
	 * @since 2.1.0
	 */
	final class Tokens {

		/**
		 * Shipped defaults, matching the stylesheet's own fallbacks.
		 */
		const ACCENT_DEFAULT = '#2563eb';
		const TEXT_DEFAULT   = '#1f2937';
		const BG_DEFAULT     = '#ffffff';

		/**
		 * The two candidates for text drawn on top of the accent.
		 */
		const ON_LIGHT = '#ffffff';
		const ON_DARK  = '#0b0b0c';

		/**
		 * WCAG 2.x AA floor for normal-size text.
		 *
		 * The 3:1 large-text allowance is deliberately NOT used: the smallest
		 * accent-coloured text in the bar is the card cost line at 13px, and the
		 * exemption starts at 24px (or 18.66px bold).
		 */
		const TEXT_CONTRAST_FLOOR = 4.5;

		/**
		 * The floor `accent_text()` actually searches against.
		 *
		 * A whisker above `TEXT_CONTRAST_FLOOR` on purpose. The backdrops are
		 * `color-mix()` results the BROWSER composites to 8-bit pixels, and a
		 * single least-significant-bit disagreement between this mix and the
		 * engine's moves the measured ratio by ~0.01. Landing the search exactly
		 * on 4.500 would leave a value that reads 4.49 in a probe; 4.55 costs at
		 * most one percentage point of accent share and removes the whole class
		 * of off-by-a-rounding failures.
		 */
		const ACCENT_TEXT_TARGET = 4.55;

		/**
		 * Memo for `accent_text()`, keyed on its three inputs.
		 *
		 * The search is up to 101 candidates x 5 backdrops of `pow()`. That is
		 * microseconds, but `design_attrs()` runs once per rendered bar AND once
		 * per rendered results block, and a page can hold several instances — so
		 * a pure function of three strings may as well be memoized.
		 *
		 * @var array<string, string>
		 */
		private static $accent_text_cache = array();

		/**
		 * Derive every colour token from the three inputs.
		 *
		 * Ratios are the handoff's: text->bg for the neutral scale (borders, muted,
		 * soft surfaces) and accent->bg for the accent scale (tints, accent border,
		 * focus ring), with the button hover mixed accent->text so it darkens on a
		 * light theme and lightens on a dark one automatically.
		 *
		 * @since 2.1.0
		 * @param string $accent Accent/main colour (#hex), '' for the default.
		 * @param string $text   Text colour (#hex), '' for the default.
		 * @param string $bg     Background colour (#hex), '' for the default.
		 * @return array<string, string> CSS custom property => #hex.
		 */
		public static function derive( $accent = '', $text = '', $bg = '' ) {
			$accent = self::clean( $accent, self::ACCENT_DEFAULT );
			$text   = self::clean( $text, self::TEXT_DEFAULT );
			$bg     = self::clean( $bg, self::BG_DEFAULT );

			return array(
				'--ecsa-accent'          => $accent,
				'--ecsa-text'            => $text,
				'--ecsa-bg'              => $bg,
				// The two values CSS cannot compute.
				'--ecsa-on-accent'       => self::on_color( $accent ),
				'--ecsa-accent-text'     => self::accent_text( $accent, $text, $bg ),
				// Neutral scale (text -> bg).
				'--ecsa-border'          => self::mix( $text, $bg, 0.14 ),
				'--ecsa-border-strong'   => self::mix( $text, $bg, 0.30 ),
				/*
				 * 0.65, where the handoff specifies 0.55 — a DELIBERATE deviation,
				 * kept byte-identical to the shipped stylesheet's color-mix() so the
				 * two implementations cannot drift. At 0.55 muted resolves to
				 * #7f838a on the default palette: 3.52:1, failing WCAG AA for the
				 * result count and sort control (16px, so no large-text exemption).
				 * 0.65 is the lowest mix clearing 4.5:1 across the default and all
				 * six shipped presets.
				 */
				'--ecsa-muted'           => self::mix( $text, $bg, 0.65 ),
				'--ecsa-soft'            => self::mix( $text, $bg, 0.05 ),
				'--ecsa-soft-hover'      => self::mix( $text, $bg, 0.09 ),
				// Accent scale (accent -> bg).
				'--ecsa-tint'            => self::mix( $accent, $bg, 0.08 ),
				'--ecsa-tint-hover'      => self::mix( $accent, $bg, 0.14 ),
				'--ecsa-accent-border'   => self::mix( $accent, $bg, 0.35 ),
				'--ecsa-ring'            => self::mix( $accent, $bg, 0.26 ),
				// Button hover mixes toward the TEXT colour, not black, so it stays
				// in the theme's key on both light and dark backgrounds.
				'--ecsa-accent-hover'    => self::mix( $accent, $text, 0.88 ),
			);
		}

		/**
		 * Flatten a token map into an inline `style` value.
		 *
		 * Values are all `#hex` produced here (never caller input), so the result is
		 * safe to place in an attribute after the usual `esc_attr()`.
		 *
		 * @since 2.1.0
		 * @param array $vars Token map.
		 * @return string
		 */
		public static function to_style( array $vars ) {
			$out = array();
			foreach ( $vars as $property => $value ) {
				$out[] = $property . ':' . $value;
			}
			return implode( ';', $out );
		}

		/**
		 * White or near-black, whichever is more legible on the given colour.
		 *
		 * @since 2.1.0
		 * @param string $bg Background colour (#hex).
		 * @return string
		 */
		public static function on_color( $bg ) {
			return self::contrast_ratio( $bg, self::ON_LIGHT ) >= self::contrast_ratio( $bg, self::ON_DARK )
				? self::ON_LIGHT
				: self::ON_DARK;
		}

		/**
		 * The accent, safe to use AS TEXT.
		 *
		 * A SECOND accent token, and the separation is the whole point. The accent
		 * used as a FILL (buttons, badges, the date pill) is fine as authored — its
		 * legibility is `on_color()`'s job, and dulling it to satisfy a text rule
		 * would cost the palette its punch everywhere for the sake of a few small
		 * labels. The accent used as INK is a different measurement against a
		 * different backdrop, so it gets a different value. One token, two jobs is
		 * how a fix for light themes ends up breaking the dark ones.
		 *
		 * DERIVATION. Walk the accent toward `$text` one percent at a time and take
		 * the LARGEST share of accent that still clears `ACCENT_TEXT_TARGET`
		 * against every backdrop accent-coloured text can land on. Mixing toward
		 * the TEXT colour — not toward black — is what makes this work on both
		 * schemes with no branch: on a light theme the text colour is dark, so the
		 * accent darkens; on a dark theme it is light, so the accent lightens. It
		 * is the same trick `--ecsa-accent-hover` already uses, and it guarantees
		 * the search terminates — at 0% the candidate IS the text colour, which is
		 * by definition the colour that reads on this palette.
		 *
		 * On four of the seven shipped palettes the answer is 100% and the token is
		 * the accent itself, byte for byte, so nothing moves. It only bites where
		 * the accent genuinely could not be read.
		 *
		 * @since 2.1.0
		 * @param string $accent Accent/main colour (#hex), '' for the default.
		 * @param string $text   Text colour (#hex), '' for the default.
		 * @param string $bg     Background colour (#hex), '' for the default.
		 * @return string #hex.
		 */
		public static function accent_text( $accent = '', $text = '', $bg = '' ) {
			$accent = self::clean( $accent, self::ACCENT_DEFAULT );
			$text   = self::clean( $text, self::TEXT_DEFAULT );
			$bg     = self::clean( $bg, self::BG_DEFAULT );

			$key = $accent . '|' . $text . '|' . $bg;

			if ( isset( self::$accent_text_cache[ $key ] ) ) {
				return self::$accent_text_cache[ $key ];
			}

			$backdrops = self::text_backdrops( $accent, $text, $bg );
			// 0% accent is the text colour itself, normalized to six digits. It is
			// the guaranteed-legible floor if the loop somehow clears nothing.
			$answer = self::mix( $accent, $text, 0 );

			for ( $step = 100; $step >= 0; $step-- ) {
				$candidate = self::mix( $accent, $text, $step / 100 );
				$clears    = true;

				foreach ( $backdrops as $backdrop ) {
					if ( self::contrast_ratio( $candidate, $backdrop ) < self::ACCENT_TEXT_TARGET ) {
						$clears = false;
						break;
					}
				}

				if ( $clears ) {
					$answer = $candidate;
					break;
				}
			}

			self::$accent_text_cache[ $key ] = $answer;

			return $answer;
		}

		/**
		 * Every opaque surface accent-coloured TEXT is drawn on.
		 *
		 * Audited against the stylesheet, not guessed: `surface` (card body, panel,
		 * portal, bar band), `tint` (pressed view toggle, checked pill, active facet
		 * trigger, avatar, chosen sort row, "Clear filter"), `tint-hover` (those
		 * same controls hovered, and the text/outline submit button's hover),
		 * `soft` (the facet panel footer, where "Reset" lives) and `soft-hover`
		 * (the ghost trigger's active hover). `tint-hover` is the binding one on
		 * every palette measured, but all five are checked so a future rule that
		 * moves accent text onto another of our surfaces is already covered.
		 *
		 * Ratios are the stylesheet's own, so this measures the colour the browser
		 * actually composites rather than an approximation of it.
		 *
		 * NOT covered, and it cannot be: accent text on page chrome we deliberately
		 * do not paint (the results toolbar, `.ecsa-clear-all`). There the backdrop
		 * belongs to the theme and no derivation can see it — `--ecsa-color-surface`
		 * is the honest stand-in, and it is in the list.
		 *
		 * @since 2.1.0
		 * @param string $accent Accent colour (#hex, already cleaned).
		 * @param string $text   Text colour (#hex, already cleaned).
		 * @param string $bg     Background colour (#hex, already cleaned).
		 * @return string[]
		 */
		private static function text_backdrops( $accent, $text, $bg ) {
			return array(
				$bg,                             // --ecsa-color-surface
				self::mix( $accent, $bg, 0.08 ), // --ecsa-color-tint
				self::mix( $accent, $bg, 0.14 ), // --ecsa-color-tint-hover
				self::mix( $text, $bg, 0.05 ),   // --ecsa-color-soft
				self::mix( $text, $bg, 0.09 ),   // --ecsa-color-soft-hover
			);
		}

		/**
		 * WCAG contrast ratio between two colours.
		 *
		 * @since 2.1.0
		 * @param string $a First colour.
		 * @param string $b Second colour.
		 * @return float
		 */
		public static function contrast_ratio( $a, $b ) {
			$la = self::luminance( $a );
			$lb = self::luminance( $b );

			return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
		}

		/**
		 * WCAG relative luminance.
		 *
		 * @since 2.1.0
		 * @param string $hex Colour.
		 * @return float
		 */
		public static function luminance( $hex ) {
			$rgb = self::hex_to_rgb( $hex );
			$lin = array();

			foreach ( $rgb as $i => $channel ) {
				$channel   = $channel / 255;
				$lin[ $i ] = $channel <= 0.03928
					? $channel / 12.92
					: pow( ( $channel + 0.055 ) / 1.055, 2.4 );
			}

			return ( 0.2126 * $lin[0] ) + ( 0.7152 * $lin[1] ) + ( 0.0722 * $lin[2] );
		}

		/**
		 * Mix two colours in sRGB: `$a * $t + $b * (1 - $t)`.
		 *
		 * Deliberately the same naive sRGB mix `color-mix( in srgb, … )` performs, so
		 * the PHP fallback and the CSS upgrade land on the same colour rather than
		 * shifting when a browser gains support.
		 *
		 * @since 2.1.0
		 * @param string $a First colour.
		 * @param string $b Second colour.
		 * @param float  $t Weight of `$a`, 0..1.
		 * @return string
		 */
		public static function mix( $a, $b, $t ) {
			$ra  = self::hex_to_rgb( $a );
			$rb  = self::hex_to_rgb( $b );
			$out = array();

			for ( $i = 0; $i < 3; $i++ ) {
				$out[ $i ] = ( $ra[ $i ] * $t ) + ( $rb[ $i ] * ( 1 - $t ) );
			}

			return self::rgb_to_hex( $out );
		}

		/**
		 * Normalize a colour to a 6-digit `#hex`, or fall back.
		 *
		 * @since 2.1.0
		 * @param mixed  $value    Raw colour.
		 * @param string $fallback Value to use when `$value` is not a hex colour.
		 * @return string
		 */
		private static function clean( $value, $fallback ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( '' === $value || ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
				return $fallback;
			}

			return $value;
		}

		/**
		 * `#hex` (3 or 6 digit) to an [r, g, b] triple.
		 *
		 * @since 2.1.0
		 * @param string $hex Colour.
		 * @return int[]
		 */
		private static function hex_to_rgb( $hex ) {
			$hex = ltrim( trim( (string) $hex ), '#' );

			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
				return array( 0, 0, 0 );
			}

			return array(
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
			);
		}

		/**
		 * An [r, g, b] triple back to `#rrggbb`.
		 *
		 * @since 2.1.0
		 * @param array $rgb Channels.
		 * @return string
		 */
		private static function rgb_to_hex( array $rgb ) {
			$out = '#';

			foreach ( $rgb as $channel ) {
				$channel = max( 0, min( 255, (int) round( $channel ) ) );
				$out    .= str_pad( dechex( $channel ), 2, '0', STR_PAD_LEFT );
			}

			return $out;
		}
	}
}
