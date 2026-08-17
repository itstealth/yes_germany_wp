<?php
/**
 * Zakra Child theme functions.
 *
 * Everything here was previously edited directly into the PARENT zakra theme,
 * which is why the parent could never be updated without losing the work.
 * Moving it into a child theme means zakra can be updated normally.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

define( 'YG_CHILD_VERSION', '1.0.0' );

/**
 * Load parent then child stylesheet.
 *
 * The child stylesheet holds the ~345 lines of custom CSS that used to be
 * appended to the parent's style.css.
 */
function yg_child_enqueue_styles() {
	wp_enqueue_style(
		'zakra-parent-style',
		get_template_directory_uri() . '/style.css',
		array(),
		wp_get_theme( get_template() )->get( 'Version' )
	);

	wp_enqueue_style(
		'zakra-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		array( 'zakra-parent-style' ),
		YG_CHILD_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'yg_child_enqueue_styles' );

/*
 * ---------------------------------------------------------------------------
 * Feature modules
 *
 * Each of these was inline in the parent theme. They are split out so a change
 * to one cannot break the others, and so each can state its own environment
 * requirements.
 * ---------------------------------------------------------------------------
 */
require_once get_stylesheet_directory() . '/inc/analytics.php';
require_once get_stylesheet_directory() . '/inc/lead-capture.php';
require_once get_stylesheet_directory() . '/inc/sockets-js-fix.php';
