<?php
/**
 * Plugin Name: YG: keep every CRM / LMS answer
 * Description: Stores the API response for every lead the site sends to the CRM
 *              or the LMS, next to the lead, so a lead missing from the LMS can be
 *              checked against what the API actually said.
 *
 * Why this exists
 * ---------------
 * Until 7 Oct 2026 the site kept an API answer only when a send failed, and for
 * main-site leads not even that: the CRM call is made by the visitor's browser
 * (this host blocks outbound port 8443, the CRM's port), so its answer went to
 * the browser and nowhere else. When 39 leads from 1-5 Oct were reported missing
 * from the LMS there was no way to see what the CRM or LMS had replied.
 *
 * What is recorded, one row per send, in {prefix}yg_lead_api_log:
 *
 *   crm  browser   mu-plugin 45 reads the CRM's reply in the browser and posts
 *                  it to /wp-json/yg/v1/lead-api-result (below), or says that it
 *                  could not (timeout, network error, no fetch support).
 *   crm  server    mu-plugin 45's server transport, if it is ever switched on.
 *   lms  server    mu-plugin 48 (mh.yesgermany.com leads to the LMS widget).
 *   lms  relay     mu-plugin 55 (hand-built forms, incl. dubai, via the relay).
 *
 * Each row carries the HTTP status, the id the API returned (crm_lead_id /
 * lead_id), the response body (first 4000 characters) and, where it can be
 * matched, the id of the lead in {prefix}yg_leads. Tools > Lead API responses
 * shows the latest rows; `wp db query` reads the table for anything else.
 *
 * The browser endpoint is public by necessity (visitors are not logged in). It
 * only stores a row when the email or phone matches a lead the site itself
 * recorded in the last 30 minutes, and is rate limited, so it cannot be used to
 * fill the table with made-up rows.
 *
 * @package YesGermany
 */

defined( 'ABSPATH' ) || exit;

const YG_LEAD_API_LOG_VERSION = '1';

function yg_lead_api_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'yg_lead_api_log';
}

/**
 * Create the table once (and again if the schema version changes).
 */
function yg_lead_api_log_install() {
	if ( YG_LEAD_API_LOG_VERSION === get_option( 'yg_lead_api_log_version' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = yg_lead_api_log_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			lead_id bigint(20) unsigned DEFAULT NULL,
			channel varchar(20) NOT NULL DEFAULT '',
			transport varchar(40) NOT NULL DEFAULT '',
			http_status smallint(6) DEFAULT NULL,
			ok tinyint(1) NOT NULL DEFAULT 0,
			remote_id varchar(100) NOT NULL DEFAULT '',
			duration_ms int(11) DEFAULT NULL,
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			page_url text,
			response text,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY created_at (created_at),
			KEY email (email)
		) {$charset};"
	);
	update_option( 'yg_lead_api_log_version', YG_LEAD_API_LOG_VERSION, false );
}
add_action( 'init', 'yg_lead_api_log_install', 1 );

/**
 * The newest lead the site recorded in the last 30 minutes with this email or
 * phone (last 10 digits), or 0.
 */
function yg_lead_api_match_lead( $email, $phone ) {
	global $wpdb;
	$leads = $wpdb->prefix . 'yg_leads';
	$email = strtolower( trim( (string) $email ) );
	$digits = substr( preg_replace( '/\D/', '', (string) $phone ), -10 );
	if ( '' === $email && strlen( $digits ) < 7 ) {
		return 0;
	}
	$since = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );
	$where = array();
	$args  = array( $since );
	if ( '' !== $email ) {
		$where[] = 'LOWER(email) = %s';
		$args[]  = $email;
	}
	if ( strlen( $digits ) >= 7 ) {
		$where[] = 'phone LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( $digits );
	}
	$sql = "SELECT id FROM {$leads} WHERE created_at >= %s AND (" . implode( ' OR ', $where ) . ') ORDER BY id DESC LIMIT 1';
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}

/**
 * Store one API answer. Never throws: recording must not break a lead.
 *
 * @param array $row channel, transport, http_status, response, remote_id,
 *                   duration_ms, email, phone, page_url, lead_id (optional).
 */
function yg_lead_api_record( array $row ) {
	try {
		global $wpdb;
		yg_lead_api_log_install();

		$response = isset( $row['response'] ) ? $row['response'] : '';
		if ( ! is_string( $response ) ) {
			$response = wp_json_encode( $response );
		}
		$decoded = json_decode( (string) $response, true );
		$remote  = isset( $row['remote_id'] ) ? (string) $row['remote_id'] : '';
		if ( '' === $remote && is_array( $decoded ) ) {
			foreach ( array( 'crm_lead_id', 'lead_id', 'id' ) as $k ) {
				if ( ! empty( $decoded[ $k ] ) && is_scalar( $decoded[ $k ] ) ) {
					$remote = (string) $decoded[ $k ];
					break;
				}
			}
		}
		$status = isset( $row['http_status'] ) && '' !== $row['http_status'] ? (int) $row['http_status'] : null;
		$lead   = ! empty( $row['lead_id'] ) ? (int) $row['lead_id'] : yg_lead_api_match_lead( $row['email'] ?? '', $row['phone'] ?? '' );

		$wpdb->insert(
			yg_lead_api_log_table(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'lead_id'     => $lead ? $lead : null,
				'channel'     => substr( (string) ( $row['channel'] ?? '' ), 0, 20 ),
				'transport'   => substr( (string) ( $row['transport'] ?? '' ), 0, 40 ),
				'http_status' => $status,
				'ok'          => ( $status >= 200 && $status < 300 && '' !== $remote ) ? 1 : 0,
				'remote_id'   => substr( $remote, 0, 100 ),
				'duration_ms' => isset( $row['duration_ms'] ) && '' !== $row['duration_ms'] ? (int) $row['duration_ms'] : null,
				'email'       => substr( strtolower( trim( (string) ( $row['email'] ?? '' ) ) ), 0, 191 ),
				'phone'       => substr( (string) ( $row['phone'] ?? '' ), 0, 60 ),
				'page_url'    => substr( (string) ( $row['page_url'] ?? '' ), 0, 2000 ),
				'response'    => substr( (string) $response, 0, 4000 ),
			)
		);
	} catch ( \Throwable $e ) {
		// Recording is best effort.
	}
}

/*
 * ---------------------------------------------------------------------------
 * Browser endpoint: the CRM answer as the visitor's browser saw it
 * ---------------------------------------------------------------------------
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'yg/v1',
			'/lead-api-result',
			array(
				'methods'             => 'POST',
				'callback'            => 'yg_lead_api_result_endpoint',
				// Public on purpose: visitors are not logged in. Checks are in the handler.
				'permission_callback' => '__return_true',
			)
		);
	}
);

function yg_lead_api_result_endpoint( WP_REST_Request $request ) {
	// Same site only (sendBeacon sends Origin on POST).
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
	$home   = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== $home ) {
		return new WP_REST_Response( array( 'stored' => false ), 403 );
	}

	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	$key = 'yg_lead_api_res_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 20 ) {
		return new WP_REST_Response( array( 'stored' => false ), 429 );
	}
	set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

	// sendBeacon posts the JSON as text/plain, so read the raw body.
	$body = json_decode( (string) $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		return new WP_REST_Response( array( 'stored' => false ), 400 );
	}

	$email = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
	$phone = isset( $body['phone'] ) ? preg_replace( '/[^0-9+]/', '', (string) $body['phone'] ) : '';
	$lead  = yg_lead_api_match_lead( $email, $phone );
	if ( ! $lead ) {
		// Not a lead this site just recorded: nothing is stored.
		return new WP_REST_Response( array( 'stored' => false ), 202 );
	}

	yg_lead_api_record(
		array(
			'lead_id'     => $lead,
			'channel'     => 'crm',
			'transport'   => 'browser-' . sanitize_key( (string) ( $body['transport'] ?? 'fetch' ) ),
			'http_status' => isset( $body['status'] ) && is_numeric( $body['status'] ) && (int) $body['status'] > 0 ? (int) $body['status'] : null,
			'response'    => isset( $body['response'] ) ? (string) $body['response'] : '',
			'duration_ms' => isset( $body['ms'] ) ? (int) $body['ms'] : null,
			'email'       => $email,
			'phone'       => $phone,
			'page_url'    => isset( $body['page'] ) ? esc_url_raw( (string) $body['page'] ) : '',
		)
	);
	return new WP_REST_Response( array( 'stored' => true ), 201 );
}

/*
 * ---------------------------------------------------------------------------
 * Tools > Lead API responses
 * ---------------------------------------------------------------------------
 */
add_action(
	'admin_menu',
	function () {
		add_management_page( 'Lead API responses', 'Lead API responses', 'manage_options', 'yg-lead-api-log', 'yg_lead_api_log_page' );
	}
);

function yg_lead_api_log_page() {
	global $wpdb;
	yg_lead_api_log_install();
	$rows = $wpdb->get_results( 'SELECT * FROM ' . yg_lead_api_log_table() . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore
	echo '<div class="wrap"><h1>Lead API responses</h1>';
	echo '<p>The latest 200 answers from the CRM and the LMS, one row per send. Times are UTC. "OK" means a 2xx answer that carried a lead id.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Lead #</th><th>Email / phone</th><th>Channel</th><th>Via</th><th>HTTP</th><th>OK</th><th>Remote id</th><th>ms</th><th>Response</th></tr></thead><tbody>';
	foreach ( (array) $rows as $r ) {
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s<br>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code style="white-space:pre-wrap;word-break:break-all">%s</code></td></tr>',
			esc_html( $r->created_at ),
			esc_html( $r->lead_id ? $r->lead_id : '-' ),
			esc_html( $r->email ),
			esc_html( $r->phone ),
			esc_html( $r->channel ),
			esc_html( $r->transport ),
			esc_html( null === $r->http_status ? '-' : $r->http_status ),
			$r->ok ? '&#10003;' : '&#10007;',
			esc_html( $r->remote_id ),
			esc_html( null === $r->duration_ms ? '' : $r->duration_ms ),
			esc_html( mb_substr( (string) $r->response, 0, 300 ) )
		);
	}
	echo '</tbody></table></div>';
}
