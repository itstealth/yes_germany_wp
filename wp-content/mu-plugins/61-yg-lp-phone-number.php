<?php
/**
 * Plugin Name: YG Landing-page phone number
 * Description: On three ad landing pages the site-wide call / WhatsApp parts (header, mobile bottom bar, floating WhatsApp button) show +91 86070 74646 instead of the main number. The pages' own buttons were changed in their content.
 * Version:     1.0.0
 * Author:      Stealth Digital
 *
 * Requested 2026-10-13 for:
 *   140018 /dmat-result-2026-update/
 *    71549 /german-education-consultant/
 *    11375 /masters-in-germany/
 *
 * The header, the mobile bottom menu and the Click to Chat button are shared by
 * every page, so they cannot be edited for three pages only. Instead the
 * finished HTML of these pages is rewritten: 80706 06070 -> 86070 74646 in every
 * format (tel:, wa.me, api.whatsapp.com, Click to Chat settings, visible text).
 * The organisation schema (JSON-LD) keeps the main number. Cache Enabler starts
 * its buffer earlier, so the cached copy already carries the swap.
 *
 * To add or remove a page: the yg_lp_phone_pages filter, or the list below.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page IDs that use the landing-page number.
 *
 * @return int[]
 */
function yg_lp_phone_pages() {
	return array_map( 'intval', (array) apply_filters( 'yg_lp_phone_pages', array( 140018, 71549, 11375 ) ) );
}

add_action(
	'template_redirect',
	function () {
		if ( is_admin() || ! is_singular() || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( in_array( (int) get_queried_object_id(), yg_lp_phone_pages(), true ) ) {
			ob_start( 'yg_lp_phone_swap' );
		}
	},
	1
);

/**
 * Swap the main number for the landing-page number, outside JSON-LD.
 *
 * @param string $html Page HTML.
 * @return string
 */
function yg_lp_phone_swap( $html ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}
	$map   = array(
		'80706 06070' => '86070 74646',
		'80706-06070' => '86070-74646',
		'8070606070'  => '8607074646',
	);
	$parts = preg_split( '#(<script[^>]*application/ld\+json[^>]*>.*?</script>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		return strtr( $html, $map );
	}
	foreach ( $parts as $i => $part ) {
		if ( 0 === $i % 2 ) {
			$parts[ $i ] = strtr( $part, $map );
		}
	}
	return implode( '', $parts );
}
