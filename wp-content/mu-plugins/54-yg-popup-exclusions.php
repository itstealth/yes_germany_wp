<?php
/**
 * Plugin Name: YG: pages where the form popup does not open by itself
 * Description: Adds pages to yg-form-popup-v2's "no auto-open" list: the popup
 *              never opens on its own there, but the bottom bar's "Enquire Now"
 *              and other popup buttons still open it.
 *
 * Why this exists
 * ---------------
 * yg-form-popup-v2 lives only on the server, not in this repository, and its
 * page lists are hard-coded in the plugin. It exposes them through filters, so
 * pages are added here instead of by editing the plugin in place.
 *
 * Until 8 Oct 2026 these pages were on the plugin's "no popup" list
 * (yg_form_popup_no_popup_pages), which leaves the overlay out of the page
 * entirely - so "Enquire Now" in the mobile bottom bar had nothing to open and
 * fell through to /book-an-appointment/. What the pages needed was only for the
 * popup not to appear by itself over their own form; yg_form_popup_no_autoopen_pages
 * does exactly that (data-yg-no-autoopen on the overlay) and keeps the buttons.
 *
 *   german-language-enquiry   requested 1 Oct 2026; the page has its own
 *                             demo-class form, so the popup stacked a second
 *                             form over it.
 *   german-language-course,   requested 6 Oct 2026, same reason: each has its
 *   german-classes-in-chennai, own "Book Free Demo Class" form.
 *   german-language,
 *   german-language-course-in-delhi
 *   technical-university-of-munich-in-germany   requested 7 Oct 2026; the page
 *                             has the Enquiry Form in its hero.
 *   technical-university-of-berlin-in-germany,          same layout, added
 *   ludwig-maximilian-university-of-munich-in-germany   7 Oct 2026.
 *
 * Also, without listing them: every German-language page built from the
 * hand-made demo-class designs - the pages mu-plugin 56 gives a 16px base. Each
 * carries its own "Book Free Demo Class" form, so the popup would stack a
 * second form over it (Chandigarh, Bangalore, Mumbai, Pune, Hyderabad and
 * /german-language-classes/ were the first caught this way, 7 Oct 2026).
 *
 * Remove a slug to let the popup open by itself on that page again.
 *
 * @package YesGermany
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'yg_form_popup_no_autoopen_pages',
	function ( $pages ) {
		$pages   = (array) $pages;
		$pages[] = 'german-language-enquiry';
		$pages[] = 'german-language-course';
		$pages[] = 'german-classes-in-chennai';
		$pages[] = 'german-language';
		$pages[] = 'german-language-course-in-delhi';
		$pages[] = 'technical-university-of-munich-in-germany';
		$pages[] = 'technical-university-of-berlin-in-germany';
		$pages[] = 'ludwig-maximilian-university-of-munich-in-germany';

		// German pages in the demo-class designs (see mu-plugin 56).
		if ( function_exists( 'yg_rem_base_applies' ) && yg_rem_base_applies() ) {
			$pages[] = (int) get_queried_object_id();
		}
		return array_values( array_unique( $pages ) );
	}
);
