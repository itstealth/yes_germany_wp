<?php
/**
 * Plugin Name: YG: 16px rem base for hand-built pages
 * Description: Pages whose hand-built HTML is sized in rem get a 16px root, so
 *              their text is not shrunk to 62.5% by the theme.
 *
 * Why this exists
 * ---------------
 * Zakra sets the root font size to 62.5% (10px) and sizes its own text in rem
 * against that. The hand-built page designs pasted into Elementor HTML widgets
 * (the German-language city pages, /german-language-course/, the enquiry page)
 * are sized in rem for the browser's normal 16px root, so on this site all of
 * their rem-sized text rendered at 62.5%: 22-53 pieces of text under 12px per
 * page.
 *
 * Until 6 Oct 2026 this was fixed page by page in Additional CSS
 * (html:has(body.page-id-N){font-size:16px}), and every new page built from
 * those designs came out shrunk again. This detects the case instead: if the
 * page's own Elementor content uses rem lengths in its HTML/CSS, the page gets
 *
 *   html { font-size: 16px }   the base those designs are written for
 *   body { font-size: 16px }   keeps the theme's 1.6rem body text at 16px
 *
 * The theme header and footer are Elementor templates sized in px, so they do
 * not change. Pages without rem-sized hand-built content are not touched.
 *
 * Scope: German-language pages only (URL slug contains german-language,
 * german-class or german-course). 34 pages site-wide cross the threshold, but
 * the others (homepage reviews, CBS University, DAAD/SOP/LOR guides, some blog
 * posts) were sized against the 10px root or not reported; widening this would
 * enlarge their text. Add a page with the yg_rem_base_applies filter.
 *
 * Elementor stores its own unit choices as {"unit":"rem"}, never as "1.5rem",
 * so the pattern below only matches lengths written into HTML or CSS by hand.
 *
 * @package YesGermany
 */

defined( 'ABSPATH' ) || exit;

/**
 * At least this many rem lengths in the page's own content. A design written in
 * rem has dozens (the affected pages have 13-59); a stray one does not count.
 */
const YG_REM_BASE_MIN = 10;

/**
 * True when the current page's own content is a hand-built rem design.
 */
function yg_rem_base_applies() {
	static $result = null;
	if ( null !== $result ) {
		return $result;
	}
	$result = false;

	if ( is_admin() || ! is_singular() ) {
		return $result;
	}
	$id = (int) get_queried_object_id();
	if ( ! $id ) {
		return $result;
	}
	$is_german_page = (bool) preg_match( '/german-(language|class|course)/', (string) get_post_field( 'post_name', $id ) );

	$content  = (string) get_post_meta( $id, '_elementor_data', true );
	$settings = get_post_meta( $id, '_elementor_page_settings', true );
	if ( is_array( $settings ) && ! empty( $settings['custom_css'] ) ) {
		$content .= "\n" . $settings['custom_css'];
	}
	if ( '' === $content ) {
		$result = (bool) apply_filters( 'yg_rem_base_applies', false, $id, 0 );
		return $result;
	}

	$count  = preg_match_all( '/(?<![\w.-])\d*\.?\d+rem\b/', $content );
	$result = (bool) apply_filters( 'yg_rem_base_applies', $is_german_page && $count >= YG_REM_BASE_MIN, $id, $count );
	return $result;
}

add_filter(
	'body_class',
	function ( $classes ) {
		if ( yg_rem_base_applies() ) {
			$classes[] = 'yg-rem-base-16';
		}
		return $classes;
	}
);

/*
 * Printed late in <head>, after the theme's stylesheets, so it wins over Zakra's
 * html{font-size:62.5%} without depending on :has().
 */
add_action(
	'wp_head',
	function () {
		if ( yg_rem_base_applies() ) {
			echo "<style id=\"yg-rem-base-16\">html{font-size:16px!important}body{font-size:16px}</style>\n";
		}
	},
	999
);
