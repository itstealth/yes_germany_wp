<?php
/**
 * Plugin Name: YG Partial Leads
 * Description: Saves what a visitor types into the enquiry forms before they press Submit. A draft that is never submitted becomes a partial lead and is sent to the CRM (saveDetails, 8443) after a quiet period. Submitted drafts are only marked; the normal lead goes through exactly as before.
 * Version:     1.1.1
 * Author:      Stealth Digital
 *
 * Asked for on 2026-11-05: "capture lead even when people dont submit ... when
 * they do submit or make correction it will overwrite that lead" and "in crm it
 * will generate leads even on phone number so ok keep it partial but then we
 * have to push it on [crm] too".
 *
 * Flow
 * ----
 * 1. Browser: on the Enquiry Form (139211) and the influencer form (139425),
 *    once every field except branch is filled (name, valid email, 10+ digit
 *    phone, level, intake, state), the form's fields are posted to
 *    /wp-json/yg/v1/partial-lead about a second after the visitor stops typing,
 *    and again when the tab is hidden. One random draft id per form per browser,
 *    so every save overwrites the same row - never a new one.
 * 2. Submit: the draft id rides along in a hidden input. When CF7 accepts the
 *    submission the draft is marked `submitted` with the lead id. The real lead
 *    is stored and sent to 8443 by YG Lead Capture and mu 45 exactly as before.
 * 3. No submit: `wp yg-partial-push` (system cron, every 5 minutes) sends drafts
 *    untouched for YG_PARTIAL_PUSH_AFTER seconds to saveDetails, built by the
 *    same code that rebuilds stored leads (mu 59). Since 1.1.0 it goes exactly
 *    like a normal lead - same form title, no partial marker - at the user's
 *    request ("we dont wanna know if its partial or not").
 *
 * Kept apart from wpb9_yg_leads on purpose: that table feeds the lead counts,
 * the duplicate checks, the daily check (63) and the 8443 safety net (59). A
 * partial row there would be counted as a lead and re-sent by the safety net.
 *
 * mh.yesgermany.com is not touched: its forms are a separate plugin on another
 * site, and mh leads are email only.
 *
 * Undo: delete this file. The table can stay; nothing else reads it.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

const YG_PARTIAL_DB_VERSION = '1';
const YG_PARTIAL_PUSH_AFTER = 1200; // 20 minutes without a change and no submit.
const YG_PARTIAL_MAX_AGE    = 172800; // Drafts older than 2 days are never sent.
const YG_PARTIAL_MAX_TRIES  = 4;

/**
 * Forms whose drafts are captured.
 *
 * @return int[]
 */
function yg_partial_form_ids() {
	return array_map( 'intval', (array) apply_filters( 'yg_partial_form_ids', array( 139211, 139425 ) ) );
}

/**
 * Optional consent line under the submit button. Off until the client approves
 * the wording: define( 'YG_PARTIAL_NOTICE', '...' ) in wp-config.php to show it.
 *
 * @return string
 */
function yg_partial_notice() {
	return defined( 'YG_PARTIAL_NOTICE' ) ? (string) YG_PARTIAL_NOTICE : '';
}

function yg_partial_table() {
	global $wpdb;
	return $wpdb->prefix . 'yg_lead_partials';
}

/* ---------------------------------------------------------------------------
 * Table
 * ------------------------------------------------------------------------- */

add_action( 'init', 'yg_partial_maybe_install' );

function yg_partial_maybe_install() {
	if ( get_option( 'yg_partial_db_version' ) === YG_PARTIAL_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = yg_partial_table();
	dbDelta(
		"CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(36) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_title varchar(191) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			saves int(10) unsigned NOT NULL DEFAULT 0,
			row_json longtext NULL,
			lead_id bigint(20) unsigned NULL,
			push_tries tinyint(3) unsigned NOT NULL DEFAULT 0,
			pushed_at datetime NULL,
			crm_http smallint(6) NULL,
			crm_lead_id varchar(100) NOT NULL DEFAULT '',
			crm_response text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status_updated (status, updated_at),
			KEY phone (phone)
		) {$wpdb->get_charset_collate()};"
	);
	update_option( 'yg_partial_db_version', YG_PARTIAL_DB_VERSION, false );
}

/* ---------------------------------------------------------------------------
 * Saving a draft
 * ------------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'yg/v1',
			'/partial-lead',
			array(
				'methods'             => 'POST',
				'callback'            => 'yg_partial_rest_save',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Same-site check. Pages are cached, so a nonce would be stale; the Origin (or
 * Referer) must be this site instead.
 *
 * @return bool
 */
function yg_partial_same_site() {
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			return wp_parse_url( (string) wp_unslash( $_SERVER[ $k ] ), PHP_URL_HOST ) === $home;
		}
	}
	return false;
}

/**
 * Crude per-IP limit: 120 saves per 10 minutes.
 *
 * @param string $ip Visitor IP.
 * @return bool True when over the limit.
 */
function yg_partial_rate_limited( $ip ) {
	$key = 'yg_pl_rl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 120 ) {
		return true;
	}
	set_transient( $key, $n + 1, 600 );
	return false;
}

/**
 * Keep only plain form fields; never CF7 plumbing or anything credential-like.
 *
 * @param mixed $fields Raw fields from the request.
 * @return array
 */
function yg_partial_clean_fields( $fields ) {
	$out  = array();
	$skip = '/^_|^g-recaptcha|(^|[_-])(pass|passwd|password|pwd|token|nonce|captcha|cvv|iban|card|cardnumber)([_-]|$)/i';
	if ( ! is_array( $fields ) ) {
		return $out;
	}
	foreach ( $fields as $k => $v ) {
		$k = (string) $k;
		if ( count( $out ) >= 40 || ! preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $k ) || preg_match( $skip, $k ) ) {
			continue;
		}
		if ( is_array( $v ) ) {
			$v = array_values( array_map( 'sanitize_text_field', array_filter( array_slice( $v, 0, 10 ), 'is_scalar' ) ) );
			$v = array_map( fn( $s ) => mb_substr( $s, 0, 300 ), $v );
		} elseif ( is_scalar( $v ) ) {
			$v = mb_substr( sanitize_textarea_field( (string) $v ), 0, 1000 );
		} else {
			continue;
		}
		$out[ $k ] = $v;
	}
	return $out;
}

function yg_partial_digits( $phone ) {
	return preg_replace( '/\D/', '', (string) $phone );
}

/**
 * Fields a draft must have before it is saved or sent: everything the form
 * requires except branch.
 *
 * @return string[]
 */
function yg_partial_required_keys() {
	return (array) apply_filters( 'yg_partial_required_keys', array( 'full_name', 'email', 'phone', 'level_of_education', 'preferred_intake_of_pursuing', 'state' ) );
}

/**
 * Whether a set of form fields is complete enough to be a lead.
 *
 * @param array $fields Form fields.
 * @return bool
 */
function yg_partial_complete( array $fields ) {
	foreach ( yg_partial_required_keys() as $k ) {
		$v = isset( $fields[ $k ] ) ? $fields[ $k ] : '';
		$v = is_array( $v ) ? (string) reset( $v ) : (string) $v;
		if ( '' === trim( $v ) ) {
			return false;
		}
	}
	return is_email( is_array( $fields['email'] ) ? reset( $fields['email'] ) : $fields['email'] )
		&& strlen( yg_partial_digits( is_array( $fields['phone'] ) ? reset( $fields['phone'] ) : $fields['phone'] ) ) >= 10;
}

/**
 * REST: create or overwrite the draft for a token.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function yg_partial_rest_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = yg_partial_table();

	if ( ! yg_partial_same_site() ) {
		return new WP_REST_Response( array( 'ok' => false, 'why' => 'origin' ), 403 );
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( yg_partial_rate_limited( $ip ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'why' => 'rate' ), 429 );
	}

	$token   = strtolower( (string) $req->get_param( 'token' ) );
	$form_id = (int) $req->get_param( 'form_id' );
	if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $token ) || ! in_array( $form_id, yg_partial_form_ids(), true ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'why' => 'bad request' ), 400 );
	}

	$fields = yg_partial_clean_fields( $req->get_param( 'fields' ) );
	$phone  = isset( $fields['phone'] ) && is_scalar( $fields['phone'] ) ? (string) $fields['phone'] : '';
	if ( ! yg_partial_complete( $fields ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'why' => 'incomplete' ), 200 );
	}

	$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $t WHERE token = %s", $token ), ARRAY_A );
	if ( $existing && 'open' !== $existing['status'] ) {
		// Already submitted or sent: never overwrite what was delivered. The
		// browser starts a new draft when it sees `restart` (1.1.1) - otherwise a
		// returning visitor's next enquiry was silently ignored.
		return new WP_REST_Response( array( 'ok' => false, 'status' => $existing['status'], 'restart' => true ), 200 );
	}

	$page = esc_url_raw( (string) $req->get_param( 'page_url' ) );
	$form = get_post( $form_id );
	$meta = array(
		'form_id'    => $form_id,
		'form_title' => $form ? $form->post_title : '',
		'status'     => 'partial',
		'page_url'   => $page,
		'referrer'   => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		'ip'         => $ip,
		'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
		'cookie'     => class_exists( 'YG_LC_Attribution' ) ? YG_LC_Attribution::from_request() : array(),
	);

	if ( class_exists( 'YG_LC_Capture' ) ) {
		$row = YG_LC_Capture::build_row( $fields, $meta );
	} else {
		$row = array(
			'form_id'     => $form_id,
			'form_title'  => $meta['form_title'],
			'page_url'    => $page,
			'referrer'    => $meta['referrer'],
			'ip'          => $ip,
			'user_agent'  => $meta['user_agent'],
			'fields_json' => wp_json_encode( $fields ),
			'name'        => isset( $fields['full_name'] ) ? (string) $fields['full_name'] : '',
			'email'       => isset( $fields['email'] ) ? (string) $fields['email'] : '',
			'phone'       => $phone,
		);
	}

	$now  = current_time( 'mysql' );
	$data = array(
		'updated_at' => $now,
		'form_id'    => $form_id,
		'form_title' => (string) $row['form_title'],
		'name'       => (string) $row['name'],
		'email'      => (string) $row['email'],
		'phone'      => yg_partial_digits( $row['phone'] ),
		'row_json'   => wp_json_encode( $row ),
	);

	if ( $existing ) {
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET saves = saves + 1 WHERE id = %d", $existing['id'] ) );
		$wpdb->update( $t, $data, array( 'id' => (int) $existing['id'] ) );
	} else {
		$wpdb->insert( $t, $data + array( 'token' => $token, 'created_at' => $now, 'status' => 'open', 'saves' => 1 ) );
	}
	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/* ---------------------------------------------------------------------------
 * Submit: mark the draft
 * ------------------------------------------------------------------------- */

/*
 * Priority 50: after YG Lead Capture (5) stored the lead and mu 45 (20) sent it.
 * Only fires for a submission CF7 accepted, so a draft whose form failed
 * validation stays open and keeps being overwritten.
 */
add_action( 'wpcf7_before_send_mail', 'yg_partial_mark_submitted', 50, 3 );

function yg_partial_mark_submitted( $contact_form, &$abort = null, $submission = null ) {
	if ( ! $contact_form || ! in_array( (int) $contact_form->id(), yg_partial_form_ids(), true ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- CF7 has verified the submission.
	$token = isset( $_POST['yg_partial_token'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['yg_partial_token'] ) ) ) : '';
	if ( ! preg_match( '/^[0-9a-f-]{36}$/', $token ) ) {
		return;
	}
	global $wpdb;
	// The lead YG Lead Capture stored a moment ago for this phone (priority 5).
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$last10  = substr( yg_partial_digits( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' ), -10 );
	$lead_id = '' === $last10 ? 0 : (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}yg_leads WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),10) = %s AND created_at >= %s ORDER BY id DESC LIMIT 1",
			$last10,
			gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 600 )
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . yg_partial_table() . " SET status = 'submitted', lead_id = NULLIF(%d,0), updated_at = %s WHERE token = %s AND status = 'open'",
			$lead_id,
			current_time( 'mysql' ),
			$token
		)
	);
}

/* ---------------------------------------------------------------------------
 * Sending abandoned drafts to the CRM
 * ------------------------------------------------------------------------- */

/**
 * Send drafts that went quiet without a submit.
 *
 * @param bool     $dry_run Report only.
 * @param callable $out     Line printer.
 * @return array Summary counts.
 */
function yg_partial_push_run( $dry_run = false, $out = null ) {
	global $wpdb;
	$out = $out ? $out : function ( $s ) {};
	$t   = yg_partial_table();
	$sum = array( 'sent' => 0, 'failed' => 0, 'submitted_elsewhere' => 0, 'already_sent' => 0, 'expired' => 0 );

	$now    = current_time( 'timestamp' );
	$quiet  = gmdate( 'Y-m-d H:i:s', $now - YG_PARTIAL_PUSH_AFTER );
	$oldest = gmdate( 'Y-m-d H:i:s', $now - YG_PARTIAL_MAX_AGE );

	$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'expired' WHERE status = 'open' AND updated_at < %s", $oldest ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM $t WHERE status = 'open' AND updated_at <= %s AND push_tries < %d ORDER BY id LIMIT 50", $quiet, YG_PARTIAL_MAX_TRIES ),
		ARRAY_A
	);

	foreach ( $rows as $p ) {
		$last10 = substr( yg_partial_digits( $p['phone'] ), -10 );

		// Submitted through another tab or browser: the real lead already went.
		$lead = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}yg_leads WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),10) = %s AND created_at >= %s ORDER BY id DESC LIMIT 1",
				$last10,
				gmdate( 'Y-m-d H:i:s', strtotime( $p['created_at'] ) - 86400 )
			)
		);
		if ( $lead ) {
			$sum['submitted_elsewhere']++;
			$out( sprintf( 'SKIP partial %d: phone submitted as lead %d', $p['id'], $lead ) );
			if ( ! $dry_run ) {
				$wpdb->update( $t, array( 'status' => 'submitted', 'lead_id' => $lead ), array( 'id' => (int) $p['id'] ) );
			}
			continue;
		}

		// Same phone already sent as a partial in the last day (several drafts, one person).
		$dupe = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM $t WHERE id <> %d AND status = 'sent' AND RIGHT(phone,10) = %s AND pushed_at >= %s LIMIT 1", $p['id'], $last10, gmdate( 'Y-m-d H:i:s', $now - 86400 ) )
		);
		if ( $dupe ) {
			$sum['already_sent']++;
			$out( sprintf( 'SKIP partial %d: same phone sent as partial %d', $p['id'], $dupe ) );
			if ( ! $dry_run ) {
				$wpdb->update( $t, array( 'status' => 'duplicate' ), array( 'id' => (int) $p['id'] ) );
			}
			continue;
		}

		$row    = json_decode( (string) $p['row_json'], true );
		$row    = is_array( $row ) ? $row : array();
		$fields = json_decode( (string) ( $row['fields_json'] ?? '' ), true );
		if ( ! is_array( $fields ) || ! yg_partial_complete( $fields ) ) {
			$out( sprintf( 'SKIP partial %d: incomplete', $p['id'] ) );
			if ( ! $dry_run ) {
				$wpdb->update( $t, array( 'status' => 'incomplete' ), array( 'id' => (int) $p['id'] ) );
			}
			continue;
		}
		$row = array_merge(
			array( 'id' => 0, 'fields_json' => '{}', 'page_url' => '', 'referrer' => '', 'user_agent' => '', 'ip' => '', 'landing_page' => '', 'gclid' => '', 'fbclid' => '' ),
			$row,
			array( 'created_at' => $p['updated_at'], 'form_id' => (int) $p['form_id'], 'form_title' => $p['form_title'] )
		);

		if ( ! function_exists( 'yg_lead_crm_payload_from_lead' ) || ! function_exists( 'yg_lead_api_send' ) ) {
			$out( 'mu-plugins 45/59 missing - nothing sent' );
			break;
		}
		// Exactly what a normal submission sends: same form title, no partial marker.
		$payload = yg_lead_crm_payload_from_lead( $row );
		unset( $payload['resubmitted_at'] );

		if ( $dry_run ) {
			$out( sprintf( 'WOULD SEND partial %d  %s  %s', $p['id'], $p['name'], $p['form_title'] ) );
			continue;
		}

		$r  = yg_lead_api_send( $payload, array( 'transport' => 'partial' ) );
		$ok = ! empty( $r['ok'] );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET push_tries = push_tries + 1 WHERE id = %d", $p['id'] ) );
		$wpdb->update(
			$t,
			array(
				'status'       => $ok ? 'sent' : ( (int) $p['push_tries'] + 1 >= YG_PARTIAL_MAX_TRIES ? 'failed' : 'open' ),
				'pushed_at'    => current_time( 'mysql' ),
				'crm_http'     => (int) $r['code'],
				'crm_lead_id'  => (string) $r['crm_lead_id'],
				'crm_response' => mb_substr( (string) $r['body'], 0, 2000 ),
			),
			array( 'id' => (int) $p['id'] )
		);
		$sum[ $ok ? 'sent' : 'failed' ]++;
		$out( sprintf( '%s partial %d  %s  HTTP %d  %s', $ok ? 'SENT' : 'FAILED', $p['id'], $p['name'], $r['code'], $ok ? $r['crm_lead_id'] : substr( (string) $r['body'], 0, 150 ) ) );
		sleep( 1 );
	}
	return $sum;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Send abandoned enquiry-form drafts to the CRM.
	 *
	 * [--dry-run]
	 * : Report what would be sent.
	 */
	WP_CLI::add_command(
		'yg-partial-push',
		function ( $args, $assoc ) {
			$dry = ! empty( $assoc['dry-run'] );
			$sum = yg_partial_push_run( $dry, function ( $s ) { WP_CLI::log( $s ); } );
			WP_CLI::log( gmdate( 'Y-m-d H:i:s' ) . ' UTC ' . ( $dry ? '[dry run] ' : '' ) . wp_json_encode( $sum ) );
		}
	);
}

/* ---------------------------------------------------------------------------
 * Browser
 * ------------------------------------------------------------------------- */

add_action( 'wp_footer', 'yg_partial_script', 997 );

function yg_partial_script() {
	if ( is_admin() ) {
		return;
	}
	$cfg = array(
		'url'    => esc_url_raw( rest_url( 'yg/v1/partial-lead' ) ),
		'forms'  => array_map( 'strval', yg_partial_form_ids() ),
		'notice' => yg_partial_notice(),
	);
	?>
	<script id="yg-partial-leads">
	(function () {
		var CFG = <?php echo wp_json_encode( $cfg ); ?>;

		function formId( form ) {
			var i = form.querySelector( 'input[name="_wpcf7"]' );
			return i ? String( i.value ) : '';
		}
		function uuid() {
			if ( window.crypto && crypto.randomUUID ) { return crypto.randomUUID(); }
			return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
				var r = Math.random() * 16 | 0; return ( 'x' === c ? r : ( r & 3 | 8 ) ).toString( 16 );
			} );
		}
		function store( k, v ) { try { if ( undefined === v ) { return localStorage.getItem( k ); } if ( null === v ) { localStorage.removeItem( k ); } else { localStorage.setItem( k, v ); } } catch ( e ) {} return null; }
		function token( form, fresh ) {
			var key = 'yg_partial_' + formId( form ), t = fresh ? null : store( key );
			if ( ! t ) { t = uuid(); store( key, t ); }
			var h = form.querySelector( 'input[name="yg_partial_token"]' );
			if ( ! h ) { h = document.createElement( 'input' ); h.type = 'hidden'; h.name = 'yg_partial_token'; form.appendChild( h ); }
			h.value = t;
			return t;
		}
		function fields( form ) {
			var out = {}, fd = new FormData( form );
			fd.forEach( function ( v, k ) {
				if ( /^_|^yg_partial_token$|^g-recaptcha/.test( k ) || 'string' !== typeof v ) { return; }
				k = k.replace( /\[\]$/, '' );
				if ( k in out ) { out[ k ] = [].concat( out[ k ], v ); } else { out[ k ] = v; }
			} );
			return out;
		}
		/* Everything the form needs except branch: name, valid email, 10+ digit phone, level, intake, state. */
		var NEED = ['full_name','email','phone','level_of_education','preferred_intake_of_pursuing','state'];
		function complete( f ) {
			for ( var i = 0; i < NEED.length; i++ ) {
				var v = f[ NEED[ i ] ]; v = Array.isArray( v ) ? v[0] : v;
				if ( ! v || ! String( v ).trim() ) { return false; }
			}
			return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( String( f.email ).trim() ) && String( f.phone ).replace( /\D/g, '' ).length >= 10;
		}
		function save( form, retry ) {
			var f = fields( form );
			if ( ! complete( f ) ) { return; }
			var body = JSON.stringify( { token: token( form ), form_id: formId( form ), page_url: location.href, fields: f } );
			if ( body === form.__ygLast ) { return; }
			form.__ygLast = body;
			try {
				fetch( CFG.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( d ) {
						/* That draft was already submitted or sent: start a fresh one, once. */
						if ( d && d.restart && ! retry ) { form.__ygLast = ''; token( form, true ); save( form, true ); }
					} )
					.catch( function () {} );
			} catch ( e ) {}
		}
		function managed( form ) {
			return form && form.classList && form.classList.contains( 'wpcf7-form' ) && CFG.forms.indexOf( formId( form ) ) > -1;
		}
		function prepare( form ) {
			if ( form.__ygPartial ) { return; }
			form.__ygPartial = true;
			token( form );
			if ( CFG.notice && ! form.querySelector( '.yg-partial-notice' ) ) {
				var p = document.createElement( 'p' );
				p.className = 'yg-partial-notice';
				p.style.cssText = 'font-size:11px;line-height:1.4;opacity:.7;margin:6px 0 0';
				p.textContent = CFG.notice;
				var btn = form.querySelector( '[type="submit"]' );
				( btn && btn.parentNode ? btn.parentNode : form ).appendChild( p );
			}
		}
		function each() {
			var forms = document.querySelectorAll( 'form.wpcf7-form' );
			for ( var i = 0; i < forms.length; i++ ) { if ( managed( forms[ i ] ) ) { prepare( forms[ i ] ); } }
		}

		var timers = {};
		function onEdit( e ) {
			var form = e.target && e.target.form;
			if ( ! managed( form ) ) { return; }
			prepare( form );
			var id = formId( form ) + ( form.id || '' );
			clearTimeout( timers[ id ] );
			timers[ id ] = setTimeout( function () { save( form ); }, 1200 );
		}
		document.addEventListener( 'input', onEdit, true );
		document.addEventListener( 'change', onEdit, true );
		document.addEventListener( 'focusout', function ( e ) { var f = e.target && e.target.form; if ( managed( f ) ) { save( f ); } }, true );
		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' !== document.visibilityState ) { return; }
			var forms = document.querySelectorAll( 'form.wpcf7-form' );
			for ( var i = 0; i < forms.length; i++ ) { if ( managed( forms[ i ] ) ) { save( forms[ i ] ); } }
		} );
		/* Sent: the next enquiry from this browser starts a new draft. */
		document.addEventListener( 'wpcf7mailsent', function ( e ) {
			var form = e.target && e.target.closest ? e.target.closest( 'form' ) || e.target.querySelector( 'form' ) : null;
			if ( form && managed( form ) ) { form.__ygLast = ''; token( form, true ); }
		} );

		if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', each ); } else { each(); }
	})();
	</script>
	<?php
}

/* ---------------------------------------------------------------------------
 * Admin: Leads > Partial leads
 * ------------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'yg-leads', 'Partial leads', 'Partial leads', 'manage_options', 'yg-partial-leads', 'yg_partial_admin_page' );
	},
	20
);

add_action( 'admin_post_yg_partial_export', 'yg_partial_admin_export' );

function yg_partial_admin_rows( $limit = 500 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . yg_partial_table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
}

function yg_partial_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$counts = $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . yg_partial_table() . ' GROUP BY status', ARRAY_A );
	$labels = array(
		'open'      => 'Typing / waiting (not sent yet)',
		'submitted' => 'Submitted - normal lead',
		'sent'      => 'Not submitted - sent to CRM',
		'incomplete'=> 'Incomplete - not sent',
		'failed'    => 'Partial - CRM failed',
		'duplicate' => 'Partial - same phone already sent',
		'expired'   => 'Expired',
	);
	echo '<div class="wrap"><h1>Partial leads</h1>';
	echo '<p>What visitors typed into the Enquiry and influencer forms. Saved once every field except branch is filled. A draft that is not submitted within ' . (int) ( YG_PARTIAL_PUSH_AFTER / 60 ) . ' minutes is sent to the CRM like a normal lead. Times are UTC.</p><p>';
	foreach ( $counts as $c ) {
		echo '<strong>' . esc_html( $labels[ $c['status'] ] ?? $c['status'] ) . ':</strong> ' . (int) $c['n'] . ' &nbsp; ';
	}
	echo '</p><p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yg_partial_export' ), 'yg_partial_export' ) ) . '">Download CSV</a></p>';
	echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Last change</th><th>Status</th><th>Name</th><th>Phone</th><th>Email</th><th>Form</th><th>Saves</th><th>Lead</th><th>CRM</th></tr></thead><tbody>';
	foreach ( yg_partial_admin_rows( 200 ) as $r ) {
		printf(
			'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
			(int) $r['id'],
			esc_html( $r['updated_at'] ),
			esc_html( $labels[ $r['status'] ] ?? $r['status'] ),
			esc_html( $r['name'] ),
			esc_html( $r['phone'] ),
			esc_html( $r['email'] ),
			esc_html( $r['form_title'] ),
			(int) $r['saves'],
			$r['lead_id'] ? (int) $r['lead_id'] : '',
			esc_html( $r['crm_lead_id'] ? $r['crm_lead_id'] : ( $r['crm_http'] ? 'HTTP ' . $r['crm_http'] : '' ) )
		);
	}
	echo '</tbody></table></div>';
}

function yg_partial_admin_export() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'yg_partial_export' ) ) {
		wp_die( 'Not allowed' );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=partial-leads-' . gmdate( 'Y-m-d' ) . '.csv' );
	$f = fopen( 'php://output', 'w' );
	fwrite( $f, "\xEF\xBB\xBF" );
	$cols = array( 'id', 'created_at', 'updated_at', 'status', 'form_title', 'name', 'email', 'phone', 'saves', 'lead_id', 'pushed_at', 'crm_http', 'crm_lead_id', 'crm_response', 'study_level', 'intake', 'state', 'branch', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'page_url' );
	fputcsv( $f, $cols );
	foreach ( yg_partial_admin_rows( 100000 ) as $r ) {
		$row    = json_decode( (string) $r['row_json'], true );
		$row    = is_array( $row ) ? $row : array();
		$fields = json_decode( (string) ( $row['fields_json'] ?? '' ), true );
		$fields = is_array( $fields ) ? $fields : array();
		$first  = function ( $k ) use ( $fields ) {
			$v = $fields[ $k ] ?? '';
			return is_array( $v ) ? (string) reset( $v ) : (string) $v;
		};
		fputcsv(
			$f,
			array(
				$r['id'], $r['created_at'], $r['updated_at'], $r['status'], $r['form_title'], $r['name'], $r['email'], $r['phone'], $r['saves'], $r['lead_id'], $r['pushed_at'], $r['crm_http'], $r['crm_lead_id'], $r['crm_response'],
				$first( 'level_of_education' ), $first( 'preferred_intake_of_pursuing' ), $first( 'state' ), $first( 'nearest_branch' ),
				$row['source'] ?? '', ( $row['c_utm_source'] ?? '' ) ?: ( $row['f_utm_source'] ?? '' ), ( $row['c_utm_medium'] ?? '' ) ?: ( $row['f_utm_medium'] ?? '' ), ( $row['c_utm_campaign'] ?? '' ) ?: ( $row['f_utm_campaign'] ?? '' ), $row['page_url'] ?? '',
			)
		);
	}
	fclose( $f );
	exit;
}
