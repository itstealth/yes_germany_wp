<?php
/**
 * Plugin Name: YG Performance: small ElementsKit icon font
 * Description: Serves the ElementsKit icons the site uses from a 23 KB font instead of
 *              the 465 KB one.
 *
 *              The ElementsKit icon font (elementskit.woff, 465 KB, 954 icons) is the
 *              largest file on every page; the header alone needs it for the menu arrow,
 *              the burger and the social icons. This adds a second font face for the same
 *              family, holding only the icons the site uses (subset with fontTools from the
 *              plugin's own font: same outlines, widths and metrics), with a unicode-range.
 *              Browsers check the face declared last first and only download the faces
 *              whose range covers an icon on the page, so:
 *              - a page using only these icons downloads the 23 KB font;
 *              - a page using any other icon (one added later in Elementor) still gets the
 *                full font for it, exactly as before. No icon can go blank.
 *              Icons kept (25 Nov 2026: every ekiticons value in the database, every icon
 *              on the 710 sitemap pages, and the widgets' default icons):
 *   apartment arrow-right book burger-menu business-hour calendar3 
 *   calendar-page-empty cancel clock1 cog comment communication Computer 
 *   credit-card customer-support-2 doctor document down-arrow1 earth ekit email 
 *   engineer envelope facebook finance-book folder gift1 google-map graph-2 
 *   hand_shake home hours-support id-card image information instagram-1 internet 
 *   left-arrow left-arrow2 link linkedin magic-wand mail management map map-marker1 
 *   minus money1 money-bag open-book phone-call phone-handset pinterest play play1 
 *   play-button plus quote quote1 Rating review right-arrow right-arrow2 right-mark 
 *   search search11 settings1 settings-2 smile star star-1 suitcase support1 team1 
 *   testimonial-slider tick trophy twitter twitter-1 university up-arrow up-arrow1 
 *   user whatsapp-1 youtube youtube-1 youtube-v 
 *              To add icons: re-run the subset with their code points and update
 *              YG_ICON_SUBSET_RANGE. To revert, remove this file and clear the page cache.
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

const YG_ICON_SUBSET_FILE    = 'assets/elementskit-subset.woff2';
const YG_ICON_SUBSET_VERSION = '1';
const YG_ICON_SUBSET_RANGE   = 'U+eba0,U+e87a,U+e828,U+eab8,U+e905,U+eb9c,U+eaac,U+e9a5,U+e864,U+e810,U+eb39,U+eac4,U+ebb1,U+ea03,U+ebb6,U+ead5,U+ea0e,U+e994,U+e853,U+e947,U+e998,U+ebc0,U+ebc1,U+eb43,U+ea1f,U+e970,U+e844,U+e910,U+ebc5,U+eabb,U+e800,U+e9b4,U+ea2d,U+ea31,U+ea34,U+eb6c,U+e999,U+e94a,U+e94c,U+e86b,U+eb45,U+e803,U+ea3c,U+ebce,U+e834,U+e973,U+e981,U+ea46,U+ebd3,U+eaf2,U+e992,U+e830,U+eb50,U+e978,U+eab6,U+e980,U+e982,U+e97e,U+e99a,U+ebd8,U+eac0,U+e9c5,U+e94d,U+ebd9,U+eb16,U+eb28,U+ea69,U+ea6b,U+e854,U+eac1,U+e9d2,U+ea86,U+e98d,U+e9c7,U+e940,U+e983,U+eac3,U+eb44,U+eb6f,U+ebe5,U+e9c3,U+e995,U+e82a,U+eb46,U+eb4c,U+eb71,U+eb4b';

/*
 * Printed late in <head>, after the stylesheet that declares the full font
 * (LiteSpeed's combined CSS, or ekiticons.css itself), so this face is checked first.
 */
add_action(
	'wp_head',
	function () {
		if ( ! is_readable( __DIR__ . '/' . YG_ICON_SUBSET_FILE ) ) {
			return;
		}
		$url = content_url( 'mu-plugins/' . YG_ICON_SUBSET_FILE ) . '?v=' . YG_ICON_SUBSET_VERSION;
		printf(
			'<style id="yg-icon-subset">@font-face{font-family:elementskit;src:url(%s) format("woff2");font-weight:400;font-style:normal;font-display:swap;unicode-range:%s}</style>' . "\n",
			esc_url( $url ),
			esc_html( YG_ICON_SUBSET_RANGE )
		);
	},
	99
);
