<?php
/**
 * Plugin Name: YG: LMS lead relay
 * Description: Server-side relay for the hand-built enquiry forms that used to post
 *              straight to the LMS lead-widget from the visitor's browser, with the
 *              LMS API key in the page source. Those forms now post to
 *              /wp-json/yg/v1/lms-lead; this adds the key from wp-config.php and
 *              forwards the lead unchanged, so the key never reaches the browser.
 * Version:     1.0.1
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

/*
 * Why this exists
 * ---------------
 * As of 1 Oct 2026, 58 HTML widgets on 37 published pages and posts posted leads to
 * .../lead-management/lead-widget/website themselves, each with
 * setRequestHeader("api-key", "<key>") in the page. Anyone could read the key and
 * create LMS leads with it. 33 of those widgets also used the hostname
 * crm-backend.yesgermany.org, which no longer resolves, so their visitors got
 * "Network error" and the lead was lost.
 *
 * The widgets keep their own markup, validation and redirect. Only the URL changes
 * to this route, and the api-key header is removed. The body is forwarded as sent
 * and the LMS answer is returned as received, so `res.success === "1"` and
 * `xhr.status === 200` checks in the widgets keep working.
 *
 * Shares the URL, key and failure log with 48-yg-lms-push.php.
 */

const YG_LMS_RELAY_NAMESPACE = 'yg/v1';
const YG_LMS_RELAY_ROUTE     = '/lms-lead';

/**
 * Sites whose pages may post here.
 *
 * This site itself, plus dubai.yesgermany.com, a separate WordPress install whose
 * hand-built forms carry the same key and can point here instead. WordPress core
 * already answers the CORS preflight for REST routes; this list is what decides
 * whether the lead is accepted.
 */
function yg_lms_relay_origins() {
	// dubai answers on both hostnames without redirecting, so both are listed.
	$origins = array( 'https://dubai.yesgermany.com', 'https://www.dubai.yesgermany.com' );

	$home = wp_parse_url( home_url() );
	if ( ! empty( $home['host'] ) ) {
		$scheme    = isset( $home['scheme'] ) ? $home['scheme'] : 'https';
		$host      = $home['host'];
		$origins[] = $scheme . '://' . $host;
		$origins[] = $scheme . '://' . ( 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host );
	}

	return array_values( array_unique( (array) apply_filters( 'yg_lms_relay_origins', $origins ) ) );
}

/**
 * True unless the browser said it came from somewhere else.
 *
 * Browsers send Origin on every POST, same-origin included. A request without one
 * is let through rather than refused: dropping a real lead costs more than the
 * rate limit below lets an abuser do.
 */
function yg_lms_relay_origin_ok() {
	if ( empty( $_SERVER['HTTP_ORIGIN'] ) ) {
		return true;
	}
	$origin = rtrim( (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ), '/' );
	return in_array( $origin, yg_lms_relay_origins(), true );
}

/**
 * At most this many leads per visitor IP in ten minutes.
 */
function yg_lms_relay_rate_ok() {
	$limit = (int) apply_filters( 'yg_lms_relay_rate_limit', 10 );
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	$key   = 'yg_lms_relay_rate_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= $limit ) {
		return false;
	}
	set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
	return true;
}

/**
 * The body to forward, or null when it is not a lead.
 *
 * Keeps the widget's own shape: field_data as a list of {code, value}, plus any
 * scalar top-level keys (tracking_url, redirect_url). Values are passed through as
 * the browser sent them, only capped in length: they used to reach the LMS
 * unfiltered, and WordPress's text sanitizers would strip %XX from tracking URLs.
 */
function yg_lms_relay_payload( $body ) {
	if ( ! is_array( $body ) || empty( $body['field_data'] ) || ! is_array( $body['field_data'] ) ) {
		return null;
	}

	$fields = array();
	foreach ( array_slice( $body['field_data'], 0, 80 ) as $item ) {
		if ( ! is_array( $item ) || ! isset( $item['code'] ) || ! is_scalar( $item['code'] ) ) {
			continue;
		}
		$value    = isset( $item['value'] ) && is_scalar( $item['value'] ) ? (string) $item['value'] : '';
		$fields[] = array(
			'code'  => substr( sanitize_text_field( (string) $item['code'] ), 0, 100 ),
			'value' => substr( $value, 0, 5000 ),
		);
	}
	if ( ! $fields ) {
		return null;
	}

	$payload = array( 'field_data' => $fields );
	foreach ( $body as $key => $value ) {
		if ( 'field_data' === $key || ! is_scalar( $value ) ) {
			continue;
		}
		$payload[ substr( sanitize_key( $key ), 0, 50 ) ] = substr( (string) $value, 0, 2000 );
	}

	return $payload;
}

function yg_lms_relay_fail( $status, $message ) {
	return new WP_REST_Response(
		array(
			'success' => '0',
			'message' => $message,
		),
		$status
	);
}

function yg_lms_relay_log( $reason, array $payload ) {
	if ( function_exists( 'yg_lms_log' ) ) {
		yg_lms_log( 'relay: ' . $reason, $payload );
	}
}

function yg_lms_relay_handle( WP_REST_Request $request ) {
	if ( ! yg_lms_relay_origin_ok() ) {
		return yg_lms_relay_fail( 403, 'This form cannot be sent from here.' );
	}

	$payload = yg_lms_relay_payload( $request->get_json_params() );
	if ( null === $payload ) {
		return yg_lms_relay_fail( 400, 'Please fill in the form and try again.' );
	}

	/*
	 * The same lead twice within two minutes gets the first answer back and is
	 * not sent again. Covers double clicks, and the two posts that used to send
	 * one lead to both hostnames, of which only one ever arrived.
	 */
	$dupe_key = 'yg_lms_relay_seen_' . md5( wp_json_encode( $payload['field_data'] ) );
	$previous = get_transient( $dupe_key );
	if ( is_array( $previous ) ) {
		return new WP_REST_Response( $previous['body'], $previous['status'] );
	}

	if ( ! yg_lms_relay_rate_ok() ) {
		yg_lms_relay_log( 'rate limited ' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ), $payload );
		return yg_lms_relay_fail( 429, 'Too many attempts. Please try again in a few minutes.' );
	}

	/* Staging and local must never create leads in the live LMS. */
	if ( function_exists( 'yg_is_production' ) && ! yg_is_production() ) {
		yg_lms_relay_log( 'not production; lead not sent to the LMS', $payload );
		return new WP_REST_Response(
			array(
				'success' => '1',
				'message' => 'Test environment: lead not sent to the LMS',
				'lead_id' => 'not-sent',
			),
			200
		);
	}

	$api_key = function_exists( 'yg_lms_api_key' ) ? yg_lms_api_key() : '';
	$url     = function_exists( 'yg_lms_url' ) ? yg_lms_url() : '';
	if ( '' === $api_key || '' === $url ) {
		yg_lms_relay_log( 'YG_LMS_API_KEY or the LMS URL is not set; lead not sent', $payload );
		return yg_lms_relay_fail( 503, 'Sorry, we could not send your enquiry. Please try again later.' );
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $response ) ) {
		yg_lms_relay_log( 'transport error: ' . $response->get_error_message(), $payload );
		return yg_lms_relay_fail( 502, 'Network error. Please try again later.' );
	}

	$status  = (int) wp_remote_retrieve_response_code( $response );
	$raw     = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $raw, true );

	if ( ! is_array( $decoded ) ) {
		yg_lms_relay_log( sprintf( 'HTTP %d, not JSON: %s', $status, substr( $raw, 0, 500 ) ), $payload );
		return yg_lms_relay_fail( 502, 'Sorry, we could not send your enquiry. Please try again later.' );
	}
	if ( $status < 200 || $status > 299 || empty( $decoded['lead_id'] ) ) {
		yg_lms_relay_log( sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 500 ) ), $payload );
	}

	if ( $status >= 200 && $status <= 299 ) {
		set_transient(
			$dupe_key,
			array(
				'status' => $status,
				'body'   => $decoded,
			),
			2 * MINUTE_IN_SECONDS
		);
	}

	return new WP_REST_Response( $decoded, $status ? $status : 502 );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			YG_LMS_RELAY_NAMESPACE,
			YG_LMS_RELAY_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => 'yg_lms_relay_handle',
				// Public on purpose: visitors are not logged in. Origin, size and rate checks are in the handler.
				'permission_callback' => '__return_true',
			)
		);
	}
);
