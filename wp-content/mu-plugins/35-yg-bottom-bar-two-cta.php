<?php
/**
 * Plugin Name: YG Bottom Bar - two-CTA variant on programme pages
 * Description: On the dMAT, DAAD Scholarships and uni-assist pages the mobile bottom bar shows only WhatsApp (08607074646) and Book Now (opens the enquiry popup). Every other page keeps the full bar exactly as configured in Appearance > Menus.
 * Version:     1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pages that get the two-CTA bar. Slugs, matching how yg-form-popup-v2 lists
 * its pages, so the same list works on staging and live regardless of IDs.
 */
function yg_bottom_bar_two_cta_pages() {
	return apply_filters( 'yg_bottom_bar_two_cta_pages', array( 'dmat', 'daad-scholarships', 'uni-assist' ) );
}

/* 08607074646 in international form, same link format as the site-wide item. */
define( 'YG_BOTTOM_BAR_WHATSAPP_URL', 'https://api.whatsapp.com/send?phone=918607074646&text=Hi' );

/*
 * The bottom-bar plugin (mobile-bottom-menu-for-wp) renders
 * wp_nav_menu( theme_location = bnav_bottom_nav ) and, on wp_nav_menu_objects
 * at priority 10, rewrites each item's title into icon markup looked up by
 * $item->ID. Running at priority 5 lets us swap the item list before that
 * happens; cloning the existing items keeps their IDs, so icons, the
 * yg-popup-trigger class on Book Now and all styling carry over untouched.
 * The menu in the database is never modified.
 */
add_filter( 'wp_nav_menu_objects', function ( $items, $args ) {
	if ( ! isset( $args->theme_location ) || 'bnav_bottom_nav' !== $args->theme_location ) {
		return $items;
	}
	if ( is_admin() || ! is_page( yg_bottom_bar_two_cta_pages() ) ) {
		return $items;
	}

	$whatsapp = null;
	$book_now = null;

	foreach ( $items as $item ) {
		if ( ! empty( $item->menu_item_parent ) ) {
			continue;
		}
		$title   = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		$classes = (array) $item->classes;

		if ( null === $whatsapp && 'whatsapp' === $title ) {
			$whatsapp = clone $item;
		} elseif ( null === $book_now && ( in_array( 'yg-popup-trigger', $classes, true ) || 'book now' === $title ) ) {
			$book_now = clone $item;
		}
	}

	/* Both must exist in the menu; otherwise leave the bar as configured
	   rather than render something half-built. */
	if ( ! $whatsapp || ! $book_now ) {
		return $items;
	}

	$whatsapp->url = YG_BOTTOM_BAR_WHATSAPP_URL;

	return array( $whatsapp, $book_now );
}, 5, 2 );
