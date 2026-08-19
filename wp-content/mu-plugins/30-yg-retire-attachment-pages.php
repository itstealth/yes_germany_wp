<?php
/**
 * Plugin Name: YES Germany — retire attachment pages
 * Description: Sends /some-image-png/ to the image file itself with a 301.
 *              WordPress publishes a page for every upload; there are 5,895 of
 *              them here, thin, near-duplicate, and indexed. A noindex tag only
 *              removes them once Google recrawls each one, which takes months.
 *              A redirect is the stronger and faster signal.
 * Author:      Stealth Digital
 *
 * Why this exists rather than AIOSEO's own "Redirect Attachment URLs" setting:
 * that handler passes the raw attachment URL to wp_safe_redirect(), and 29 of
 * these files have non-ASCII names — Cyrillic, a curly quote, a rupee sign.
 * wp_validate_redirect() rejects those, so AIOSEO would 301 them to
 * /wp-admin/ — a login screen — instead of the image. Percent-encoding the
 * path first fixes all 29; measured, not assumed.
 *
 * If a URL still fails validation the page is left exactly as it is. Rendering
 * a thin page is a much smaller problem than redirecting visitors and Googlebot
 * to a login screen, so this never falls back to wp-admin.
 *
 * @package YesGermany
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'template_redirect',
	function () {
		if ( ! is_attachment() ) {
			return;
		}

		$id = get_queried_object_id();
		if ( ! $id ) {
			return;
		}

		$url = wp_get_attachment_url( $id );
		if ( empty( $url ) ) {
			return;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return;
		}

		// Encode each path segment separately so the slashes survive.
		$encoded = $parts['scheme'] . '://' . $parts['host']
			. implode( '/', array_map( 'rawurlencode', explode( '/', $parts['path'] ) ) );
		if ( ! empty( $parts['query'] ) ) {
			$encoded .= '?' . $parts['query'];
		}

		// Only redirect somewhere we have confirmed is allowed. Anything else
		// renders as before rather than being sent to the admin fallback.
		if ( wp_validate_redirect( $encoded, '' ) !== $encoded ) {
			return;
		}

		wp_safe_redirect( $encoded, 301, 'YG attachment retire' );
		exit;
	},
	// Ahead of AIOSEO's own Media handler, which sits at 5.
	4
);
