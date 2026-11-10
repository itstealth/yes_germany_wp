<?php
/**
 * Plugin Name: YG: Attribution hygiene (no stale or fake campaign on later leads)
 * Description: 1) Ignores fake ad parameters (fbclid=fbclid, gclid=gclid, unfilled {placeholders}) in the remembered first arrival and the submitting page's URL. 2) After a lead is accepted, the browser forgets its remembered first arrival, so the next, different lead from that browser is not credited to an old link.
 * Version:     1.0.0
 * Author:      YES Germany
 *
 * Why (3 Nov 2026): staff entering organic enquiries through the website were
 * doing it in a browser that had once opened
 *   /?utm_source=Google-Ads&utm_medium=Search&utm_campaign=Stealth-GMAT-Traffic-2027&fbclid=fbclid
 * YG Lead Capture keeps a visitor's first arrival with campaign tags for 90 days
 * (the yg_attr cookie), so every enquiry entered there afterwards - 35 between
 * 31 Oct and 3 Nov - reached the CRM/LMS as "Google-Ads" and was listed as
 * "Facebook" (the literal fbclid=fbclid). An earlier link did the same
 * ("Stealth-Germany-North-India", 17 leads from 15 Sep).
 *
 * How:
 *  - At the start of each request, placeholder click IDs / UTM values are removed
 *    from $_COOKIE['yg_attr'] (including its stored landing URL) and from URLs in
 *    the posted form fields and the Referer. Everything that reads attribution
 *    (YG Lead Capture, mu-plugins 45 and 55) then sees clean values.
 *  - When a Contact Form 7 submission returns mail_sent, or the hand-built forms'
 *    relay (yg/v1/lms-lead) succeeds, the response expires the yg_attr cookie.
 *    The lead just sent keeps its attribution (it was read earlier in the same
 *    request); only later leads from that browser start clean. The LMS keeps one
 *    source per person anyway, so a genuine visitor's repeat enquiry loses nothing.
 */

defined( 'ABSPATH' ) || exit;

const YG_ATTR_CLICK_KEYS = array( 'gclid', 'fbclid', 'gbraid', 'wbraid', 'msclkid', 'gad_campaignid', 'gad_source' );

/** True for values that are a template or a stand-in, not a real ID or tag. */
function yg_attr_is_placeholder( $key, $value ) {
	$v = strtolower( trim( rawurldecode( (string) $value ) ) );
	if ( '' === $v ) {
		return false;
	}
	if ( $v === strtolower( (string) $key ) || false !== strpos( $v, '{' ) || false !== strpos( $v, '}' ) ) {
		return true;
	}
	return in_array( $v, array( 'undefined', 'null', '(not set)', 'not set', 'test', 'xxx' ), true )
		&& in_array( strtolower( (string) $key ), YG_ATTR_CLICK_KEYS, true );
}

/** Drop placeholder query parameters from a URL; everything else stays byte-for-byte. */
function yg_attr_clean_url( $url ) {
	$url = (string) $url;
	$q   = strpos( $url, '?' );
	if ( false === $q ) {
		return $url;
	}
	$hash = '';
	$h    = strpos( $url, '#', $q );
	if ( false !== $h ) {
		$hash = substr( $url, $h );
		$url  = substr( $url, 0, $h );
	}
	$base  = substr( $url, 0, $q );
	$parts = explode( '&', substr( $url, $q + 1 ) );
	$keep  = array();
	foreach ( $parts as $p ) {
		if ( '' === $p ) {
			continue;
		}
		$kv = explode( '=', $p, 2 );
		$k  = strtolower( rawurldecode( $kv[0] ) );
		$v  = isset( $kv[1] ) ? str_replace( '+', ' ', $kv[1] ) : '';
		$tracked = in_array( $k, YG_ATTR_CLICK_KEYS, true ) || 0 === strpos( $k, 'utm_' );
		if ( $tracked && yg_attr_is_placeholder( $k, $v ) ) {
			continue;
		}
		$keep[] = $p;
	}
	return $base . ( $keep ? '?' . implode( '&', $keep ) : '' ) . $hash;
}

/** Clean one yg_attr cookie value (JSON). Returns the original string when nothing changes. */
function yg_attr_clean_cookie( $raw ) {
	$data = json_decode( (string) $raw, true );
	if ( ! is_array( $data ) ) {
		return $raw;
	}
	$changed = false;
	foreach ( $data as $k => $v ) {
		if ( ! is_scalar( $v ) ) {
			continue;
		}
		$tracked = in_array( strtolower( $k ), YG_ATTR_CLICK_KEYS, true ) || 0 === strpos( strtolower( $k ), 'utm_' );
		if ( $tracked && yg_attr_is_placeholder( $k, $v ) ) {
			unset( $data[ $k ] );
			$changed = true;
		} elseif ( 'landing_page' === $k ) {
			$clean = yg_attr_clean_url( $v );
			if ( $clean !== $v ) {
				$data[ $k ] = $clean;
				$changed    = true;
			}
		}
	}
	return $changed ? wp_json_encode( $data ) : $raw;
}

/* 1) Clean what every attribution reader sees. muplugins_loaded: before WordPress
      adds slashes (wp_magic_quotes) and before any plugin reads these. */
add_action(
	'muplugins_loaded',
	function () {
		if ( ! empty( $_COOKIE['yg_attr'] ) && is_string( $_COOKIE['yg_attr'] ) ) {
			$_COOKIE['yg_attr'] = yg_attr_clean_cookie( $_COOKIE['yg_attr'] );
		}
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ) {
			$_SERVER['HTTP_REFERER'] = yg_attr_clean_url( $_SERVER['HTTP_REFERER'] );
		}
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) && ! empty( $_POST ) ) {
			foreach ( $_POST as $k => $v ) {
				if ( is_string( $v ) && false !== strpos( $v, '?' ) && preg_match( '#^https?://#i', $v ) ) {
					$_POST[ $k ] = yg_attr_clean_url( $v );
				} elseif ( is_string( $v ) && 0 === strpos( strtolower( (string) $k ), 'utm_' ) && yg_attr_is_placeholder( $k, $v ) ) {
					$_POST[ $k ] = '';
				}
			}
		}
	},
	0
);

/* 2) Forget the remembered first arrival once a lead has been accepted. */
add_filter(
	'rest_post_dispatch',
	function ( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || empty( $_COOKIE['yg_attr'] ) || headers_sent() ) {
			return $response;
		}
		$route = (string) $request->get_route();
		$data  = $response->get_data();
		$ok    = false;
		if ( preg_match( '#^/contact-form-7/v1/contact-forms/\d+/feedback$#', $route ) ) {
			$ok = is_array( $data ) && isset( $data['status'] ) && 'mail_sent' === $data['status'];
		} elseif ( '/yg/v1/lms-lead' === $route ) {
			$ok = 200 === (int) $response->get_status();
		}
		if ( $ok ) {
			// Same name, path and host-only scope as YG Lead Capture's attribution.js.
			setcookie( 'yg_attr', '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'samesite' => 'Lax' ) );
			$response->header( 'X-YG-Attr', 'cleared' );
		}
		return $response;
	},
	99,
	3
);
