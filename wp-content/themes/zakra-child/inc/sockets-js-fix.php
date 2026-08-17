<?php
/**
 * Strip a third-party sockets.js tag from the homepage and one landing page.
 *
 * Carried over from the parent theme unchanged in behaviour. The original
 * comment said "Page ID 56" while the code targeted 16520; the code is the
 * source of truth, so 16520 is preserved and the ID is now a filterable
 * constant rather than a magic number.
 *
 * This works by buffering the whole page and regex-replacing, which is not
 * cheap. It is kept as-is to avoid changing rendering behaviour during the
 * migration. See docs/technical-debt.md for the follow-up.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pages the sockets.js tag should be removed from.
 *
 * @return int[]
 */
function yg_sockets_js_page_ids() {
	return apply_filters( 'yg_sockets_js_page_ids', array( 16520 ) );
}

/**
 * Start buffering on the affected pages only.
 */
function yg_remove_sockets_js_start() {
	if ( is_admin() ) {
		return;
	}

	$targets = yg_sockets_js_page_ids();

	if ( is_front_page() || ( ! empty( $targets ) && is_page( $targets ) ) ) {
		ob_start( 'yg_remove_sockets_js_filter' );
	}
}
add_action( 'template_redirect', 'yg_remove_sockets_js_start' );

/**
 * Remove any <script src="...sockets.js"> tag from the buffered output.
 *
 * @param string $buffer Page HTML.
 * @return string
 */
function yg_remove_sockets_js_filter( $buffer ) {
	$pattern = '/<script[^>]*src=["\'][^"\']*sockets\.js["\'][^>]*><\/script>/i';
	$result  = preg_replace( $pattern, '', $buffer );

	// preg_replace returns null on failure (e.g. backtrack limit on a large page).
	return ( null === $result ) ? $buffer : $result;
}
