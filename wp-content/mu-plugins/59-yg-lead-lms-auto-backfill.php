<?php
/**
 * Plugin Name: YG Lead LMS Check & Backfill
 * Description: Checks every recent www lead in the YES Germany LMS itself (search by phone, then email) and pushes the ones that are not there through the LMS lead-widget API. Run by the system cron every 6 hours via `wp yg-lms-backfill`. Production only.
 * Version:     3.3.0
 * Author:      Stealth Digital
 *
 * Why
 * ---
 * Website leads reach the LMS from the visitor's browser through the website-
 * form API (yesgermany.org:8443 - unreachable from this host). Its answer is
 * not proof: on 2026-10-11 five leads got "Form data saved successfully" with
 * an id and were still not in the LMS. 1.0 trusted that answer and skipped
 * them. 2.0 asks the LMS directly.
 *
 * How
 * ---
 * Every www lead from the last YG_LMS_CHECK_HOURS hours, at least
 * YG_LMS_CHECK_MIN_AGE old, with no verified LMS row in the Lead API log:
 *   - LMS search by phone (last 10 digits), then by email;
 *   - found     -> a row channel=lms transport=verify ok=1 remote_id=<LMS id>,
 *                  and the lead is never checked again;
 *   - not found -> pushed to the lead-widget API with the visitor's own fields
 *                  (transport auto-backfill), at most YG_LMS_BACKFILL_MAX_TRIES;
 *   - search failed -> nothing is pushed; the lead is checked next run.
 * A person already in the LMS counts as found: the counsellor has them, and
 * re-sending would only add an interaction.
 *
 * Reading the LMS needs a user login: YG_LMS_READ_EMAIL / YG_LMS_READ_PASSWORD
 * in wp-config.php (never in this repository). The token is cached.
 *
 * mh leads are email-only (mu-plugin 58) and Dubai leads go through the relay
 * (55), so only www leads are checked.
 *
 * 3.0.0 - missing leads go to saveDetails first (2026-10-13)
 * -------------------------------------------------------
 * Pushing straight to the LMS skipped the client's MongoDB, which is fed by
 * saveDetails (yesgermany.org:8443/websiteForm/saveDetails). With
 * YG_LMS_BACKFILL_VIA_CRM true in wp-config.php a lead missing from the LMS is:
 *   1. sent to saveDetails with the visitor's original fields and attribution
 *      (transport auto-backfill-crm, up to 4 tries);
 *   2. if saveDetails accepted it - now or at submit time - but it is still not
 *      in the LMS 2 hours later, the client's MongoDB -> LMS sync has dropped
 *      it, and only then is it pushed to the LMS lead-widget (it is already in
 *      MongoDB by then, so nothing is skipped).
 * Without the constant the job behaves exactly as 2.1.
 *
 * 3.1.0 - resends use the page URL's UTMs (2026-10-13), like mu-plugin 45 2.1.
 *
 * 3.2.0 - German-language leads are held (2026-10-13): they go from MongoDB to a
 * separate LMS, so they are never pushed into this one; the job only makes sure
 * saveDetails has them. YG_LMS_BACKFILL_HOLD_GERMAN = false undoes this.
 *
 * 3.3.0 - a lead found in the LMS still goes to saveDetails if saveDetails never
 * accepted it (2026-10-14): lead 2074 timed out on saveDetails, was found in the
 * LMS through another channel, and so never reached MongoDB.
 *
 * Cron (yesgermanycom's crontab) - every 6 hours, server time is IST:
 *   17 0,6,12,18 * * * cd ~/public_html && /usr/local/bin/php ~/bin/wp yg-lms-backfill --skip-themes >> ~/yg-cron/lms-backfill.log 2>&1
 * By hand: `wp yg-lms-backfill --dry-run [--hours=72]`.
 */

defined( 'ABSPATH' ) || exit;

const YG_LMS_BACKFILL_START_ID  = 1791;
const YG_LMS_CHECK_HOURS        = 48;
const YG_LMS_CHECK_MIN_AGE      = 1800;
const YG_LMS_BACKFILL_MAX_TRIES = 4;
const YG_LMS_BACKFILL_BATCH     = 300;
const YG_LMS_READ_BASE          = 'https://lms.yesgermany.org/api/v1';

/* ----------------------------------------------------------------------------
 * LMS read access
 * ------------------------------------------------------------------------- */

/**
 * Bearer token for the LMS read API, cached for 6 hours.
 *
 * @param bool $fresh Ignore the cache.
 * @return string '' when it cannot log in.
 */
function yg_lms_read_token( $fresh = false ) {
	if ( ! $fresh ) {
		$cached = get_transient( 'yg_lms_read_token' );
		if ( $cached ) {
			return (string) $cached;
		}
	}
	if ( ! defined( 'YG_LMS_READ_EMAIL' ) || ! defined( 'YG_LMS_READ_PASSWORD' ) ) {
		return '';
	}
	$res = wp_remote_post(
		YG_LMS_READ_BASE . '/user-management/login-with-password',
		array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'    => wp_json_encode( array( 'email' => YG_LMS_READ_EMAIL, 'password' => YG_LMS_READ_PASSWORD ) ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return '';
	}
	$body  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$token = is_array( $body ) && ! empty( $body['token'] ) ? (string) $body['token'] : '';
	if ( $token ) {
		set_transient( 'yg_lms_read_token', $token, 6 * HOUR_IN_SECONDS );
	}
	return $token;
}

/**
 * Search the LMS. Returns the list of hits, or null when the search itself
 * failed (so the caller must not treat it as "not in the LMS").
 *
 * @param string $term Phone or email.
 * @return array|null
 */
function yg_lms_search( $term ) {
	$term = trim( (string) $term );
	if ( '' === $term ) {
		return array();
	}
	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$token = yg_lms_read_token( $attempt > 0 );
		if ( '' === $token ) {
			return null;
		}
		$res = wp_remote_get(
			YG_LMS_READ_BASE . '/search/lead-details?search=' . rawurlencode( $term ),
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code ) {
			delete_transient( 'yg_lms_read_token' );
			continue;
		}
		if ( 200 !== $code ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || ! isset( $body['pagination']['data'] ) || ! is_array( $body['pagination']['data'] ) ) {
			return null;
		}
		return $body['pagination']['data'];
	}
	return null;
}

/**
 * Where a lead is in the LMS.
 *
 * @param array $lead Row from yg_leads.
 * @return array|null ['lead_id' => ..., 'by' => 'phone'|'email'], [] when not found, null when the search failed.
 */
function yg_lms_find_lead( array $lead ) {
	$digits = preg_replace( '/\D/', '', (string) $lead['phone'] );
	$phone  = substr( $digits, -10 );

	foreach ( array( 'phone' => $phone, 'email' => (string) $lead['email'] ) as $by => $term ) {
		if ( '' === $term || ( 'phone' === $by && strlen( $term ) < 10 ) ) {
			continue;
		}
		$hits = yg_lms_search( $term );
		if ( null === $hits ) {
			return null;
		}
		foreach ( $hits as $hit ) {
			$hit_phone = substr( preg_replace( '/\D/', '', (string) ( $hit['phone'] ?? '' ) ), -10 );
			$hit_email = strtolower( trim( (string) ( $hit['email'] ?? '' ) ) );
			if ( ( 'phone' === $by && $hit_phone === $phone ) || ( 'email' === $by && $hit_email === strtolower( trim( $term ) ) ) ) {
				return array( 'lead_id' => (string) ( $hit['lead_id'] ?? '' ), 'by' => $by );
			}
		}
	}
	return array();
}

/* ----------------------------------------------------------------------------
 * Candidates and payload
 * ------------------------------------------------------------------------- */

/**
 * www leads that still need checking, oldest first.
 *
 * @param int $limit Maximum rows.
 * @param int $hours Look-back window.
 * @return array[]
 */
function yg_lms_backfill_candidates( $limit, $hours = YG_LMS_CHECK_HOURS ) {
	global $wpdb;
	$leads = $wpdb->prefix . 'yg_leads';
	$log   = $wpdb->prefix . 'yg_lead_api_log';

	// yg_leads.created_at is UTC.
	$newest = gmdate( 'Y-m-d H:i:s', time() - YG_LMS_CHECK_MIN_AGE );
	$oldest = gmdate( 'Y-m-d H:i:s', time() - (int) $hours * HOUR_IN_SECONDS );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.* FROM $leads l
			 WHERE l.id > %d
			   AND l.created_at BETWEEN %s AND %s
			   AND l.page_url LIKE %s
			   AND l.page_url NOT LIKE %s
			   AND IFNULL(l.referrer, '') NOT LIKE %s
			   AND IFNULL(l.landing_page, '') NOT LIKE %s
			   AND ( NOT EXISTS ( SELECT 1 FROM $log a WHERE a.lead_id = l.id AND a.channel = 'lms' AND a.ok = 1 )
			         OR ( %d = 1
			              AND NOT EXISTS ( SELECT 1 FROM $log c WHERE c.lead_id = l.id AND c.channel = 'crm' AND c.ok = 1 )
			              AND ( SELECT COUNT(*) FROM $log t WHERE t.lead_id = l.id AND t.transport = 'auto-backfill-crm' ) < %d ) )
			   AND ( SELECT COUNT(*) FROM $log b WHERE b.lead_id = l.id AND b.transport = 'auto-backfill' ) < %d
			 ORDER BY l.id
			 LIMIT %d",
			YG_LMS_BACKFILL_START_ID,
			$oldest,
			$newest,
			'%://www.yesgermany.com/%',
			'%/wp-json/%',
			'%mh.yesgermany.com%',
			'%mh.yesgermany.com%',
			yg_lms_backfill_via_crm() ? 1 : 0,
			YG_LMS_BACKFILL_MAX_TRIES,
			YG_LMS_BACKFILL_MAX_TRIES,
			(int) $limit
		),
		ARRAY_A
	);
	// phpcs:enable
}

/**
 * The lead-widget payload: the visitor's own fields, built by the same
 * function the live LMS push uses, plus when the visitor actually enquired.
 *
 * @param array $lead Row from yg_leads.
 * @return array
 */
function yg_lms_backfill_payload( array $lead ) {
	$posted = json_decode( (string) $lead['fields_json'], true );
	$posted = is_array( $posted ) ? $posted : array();
	if ( function_exists( 'yg_lead_api_utm_from_url' ) ) {
		$posted = yg_lead_api_utm_from_url( $posted, ! empty( $posted['yg_tracking_url'] ) ? (string) $posted['yg_tracking_url'] : (string) $lead['page_url'] );
	}

	/*
	 * The CF7 form's hidden `source` field is always "website", and the widget
	 * API files the lead under whatever `source` says - so 78 ChatGPT / Google
	 * Ads leads pushed on 2026-10-10 landed as "website". Send the real
	 * channel instead: the UTM source, as the website-form API would.
	 */
	$utm = isset( $posted['utm_source'] ) ? trim( (string) ( is_array( $posted['utm_source'] ) ? reset( $posted['utm_source'] ) : $posted['utm_source'] ) ) : '';
	if ( '' === $utm && ! empty( $lead['f_utm_source'] ) ) {
		$utm = trim( (string) $lead['f_utm_source'] );
	}
	if ( '' === $utm && ! empty( $lead['c_utm_source'] ) ) {
		$utm = trim( (string) $lead['c_utm_source'] );
	}
	if ( '' !== $utm ) {
		$posted['source'] = $utm;
	}

	$ist = gmdate( 'Y-m-d H:i', strtotime( $lead['created_at'] . ' UTC' ) + 19800 );

	$posted['original_submitted_at'] = $ist . ' IST';
	$posted['submission_note']       = 'Website lead auto-resubmitted on ' . gmdate( 'Y-m-d H:i', time() + 19800 ) . ' IST (originally submitted ' . $ist . ' IST; it was not found in the LMS)';

	return array(
		'field_data'   => yg_lms_field_data( $posted ),
		'tracking_url' => (string) $lead['page_url'],
		'redirect_url' => (string) $lead['page_url'],
	);
}

/* ----------------------------------------------------------------------------
 * saveDetails (client's MongoDB) first
 * ------------------------------------------------------------------------- */

/**
 * Whether missing leads go to saveDetails before the LMS.
 *
 * @return bool
 */
function yg_lms_backfill_via_crm() {
	return defined( 'YG_LMS_BACKFILL_VIA_CRM' ) && YG_LMS_BACKFILL_VIA_CRM
		&& function_exists( 'yg_lead_api_send' ) && function_exists( 'yg_lead_browser_values' );
}

/**
 * German-language leads (level "German Language" / "German Language & IELTS")
 * go from MongoDB to a separate LMS, not this one, so the job never pushes
 * them into this LMS - it only makes sure saveDetails has them. Held, not
 * dropped: a lead stays a candidate for YG_LMS_CHECK_HOURS, so defining
 * YG_LMS_BACKFILL_HOLD_GERMAN as false in wp-config.php pushes them again.
 *
 * @param array $lead Row from yg_leads.
 * @return bool
 */
function yg_lms_backfill_is_held_german( array $lead ) {
	if ( defined( 'YG_LMS_BACKFILL_HOLD_GERMAN' ) && ! YG_LMS_BACKFILL_HOLD_GERMAN ) {
		return false;
	}
	$posted = json_decode( (string) $lead['fields_json'], true );
	$level  = is_array( $posted ) && isset( $posted['level_of_education'] ) ? $posted['level_of_education'] : '';
	$level  = is_array( $level ) ? implode( ' ', array_filter( $level, 'is_scalar' ) ) : (string) $level;
	return false !== stripos( $level, 'German Language' );
}

/**
 * Send a stored lead to saveDetails (transport auto-backfill-crm).
 *
 * @param array    $lead    Row from yg_leads.
 * @param array    $summary Run counters.
 * @param callable $out     Line printer.
 * @param string   $why     Shown in the log line.
 */
function yg_lms_backfill_send_crm( array $lead, array &$summary, $out, $why ) {
	$r = yg_lead_api_send( yg_lead_crm_payload_from_lead( $lead ), array( 'transport' => 'auto-backfill-crm', 'lead_id' => (int) $lead['id'] ) );
	$summary[ $r['ok'] ? 'sent_crm' : 'crm_failed' ]++;
	$out( sprintf( 'SENT TO saveDetails  lead %d (%s)  %s  HTTP %d  %s', $lead['id'], $why, $lead['name'], $r['code'], $r['ok'] ? $r['crm_lead_id'] : 'FAILED ' . substr( $r['body'], 0, 150 ) ) );
	sleep( 1 );
}

/**
 * Rebuild, from the stored lead, the payload the page would have sent: the
 * visitor's own fields, the browser attribution the form carried, and the
 * original submission time.
 *
 * @param array $lead Row from yg_leads.
 * @return array
 */
function yg_lead_crm_payload_from_lead( array $lead ) {
	$posted  = json_decode( (string) $lead['fields_json'], true );
	$posted  = is_array( $posted ) ? $posted : array();
	$browser = yg_lead_browser_values( $posted );
	$skip    = '/^_wpcf7|^_wpnonce|(^|[_-])(pass|passwd|password|pwd|token|nonce|captcha|cvv|iban|card|cardnumber)([_-]|$)/i';

	$payload = array();
	foreach ( $posted as $key => $value ) {
		if ( preg_match( $skip, (string) $key ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}
		if ( ! is_scalar( $value ) ) {
			continue;
		}
		$payload[ $key ] = (string) $value;
	}

	$page = (string) $lead['page_url'];

	$payload['form_id']           = (int) $lead['form_id'];
	$payload['form_title']        = (string) $lead['form_title'];
	$payload['tracking_url']      = ! empty( $browser['yg_tracking_url'] ) ? $browser['yg_tracking_url'] : $page;
	$payload['page_title']        = ! empty( $browser['yg_page_title'] ) ? $browser['yg_page_title'] : ( function_exists( 'yg_lead_api_page_title' ) ? yg_lead_api_page_title( $page ) : '' );
	$payload['referrer']          = array_key_exists( 'yg_referrer', $browser ) ? $browser['yg_referrer'] : (string) $lead['referrer'];
	$payload['user_agent']        = (string) $lead['user_agent'];
	$payload['remote_ip']         = (string) $lead['ip'];
	$payload['timestamp']         = gmdate( 'c', strtotime( $lead['created_at'] . ' UTC' ) );
	$payload['yg_referral_url']   = ! empty( $browser['yg_referral_url'] ) ? $browser['yg_referral_url'] : (string) $lead['landing_page'];
	$payload['yg_session_active'] = isset( $browser['yg_session_active'] ) ? $browser['yg_session_active'] : '';
	$payload['yg_landing_page']   = (string) $lead['landing_page'];
	$payload['resubmitted_at']    = gmdate( 'c' );

	return function_exists( 'yg_lead_api_utm_from_url' ) ? yg_lead_api_utm_from_url( $payload, $payload['tracking_url'] ) : $payload;
}

/**
 * What to do with a lead that is not in the LMS.
 *
 * @param array $lead Row from yg_leads.
 * @return string 'send' (to saveDetails), 'wait' (saveDetails took it; give the sync time), 'lms' (push to the LMS widget)
 */
function yg_lms_backfill_crm_step( array $lead ) {
	global $wpdb;
	$log = $wpdb->prefix . 'yg_lead_api_log';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	$accepted = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM $log WHERE lead_id = %d AND channel = 'crm' AND ok = 1", (int) $lead['id'] ) );
	$tries    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $log WHERE lead_id = %d AND channel = 'crm' AND transport = 'auto-backfill-crm'", (int) $lead['id'] ) );
	// phpcs:enable

	if ( $accepted ) {
		return ( time() - strtotime( $accepted . ' UTC' ) ) >= 2 * HOUR_IN_SECONDS ? 'lms' : 'wait';
	}
	return $tries < YG_LMS_BACKFILL_MAX_TRIES ? 'send' : 'lms';
}

/* ----------------------------------------------------------------------------
 * Run
 * ------------------------------------------------------------------------- */

/**
 * Check, and push what is missing.
 *
 * @param bool     $dry_run Check only; record and push nothing.
 * @param int      $limit   Batch size.
 * @param int      $hours   Look-back window.
 * @param callable $out     Line printer.
 * @return array
 */
function yg_lms_backfill_run( $dry_run = false, $limit = YG_LMS_BACKFILL_BATCH, $hours = YG_LMS_CHECK_HOURS, $out = null ) {
	$out     = $out ? $out : function ( $line ) {};
	$summary = array( 'checked' => 0, 'in_lms' => 0, 'missing' => 0, 'sent_crm' => 0, 'crm_failed' => 0, 'waiting' => 0, 'pushed' => 0, 'push_failed' => 0, 'search_failed' => 0, 'held_german' => 0 );

	if ( ! function_exists( 'yg_is_production' ) || ! yg_is_production() ) {
		$out( 'not production - nothing is checked or sent from this site' );
		return $summary;
	}
	if ( ! function_exists( 'yg_lms_field_data' ) || ! function_exists( 'yg_lms_api_key' ) || ! function_exists( 'yg_lead_api_record' ) ) {
		$out( 'mu-plugins 48 / 57 are not loaded - nothing done' );
		return $summary;
	}
	if ( '' === yg_lms_api_key() ) {
		$out( 'YG_LMS_API_KEY is not set - nothing done' );
		return $summary;
	}
	if ( '' === yg_lms_read_token() ) {
		$out( 'cannot log in to the LMS (YG_LMS_READ_EMAIL / YG_LMS_READ_PASSWORD) - nothing done' );
		return $summary;
	}

	if ( ! $dry_run ) {
		if ( get_transient( 'yg_lms_backfill_lock' ) ) {
			$out( 'another run is in progress - skipped' );
			return $summary;
		}
		set_transient( 'yg_lms_backfill_lock', time(), 50 * MINUTE_IN_SECONDS );
	}

	foreach ( yg_lms_backfill_candidates( $limit, $hours ) as $lead ) {
		$summary['checked']++;

		if ( yg_lms_backfill_via_crm() && yg_lms_backfill_is_held_german( $lead ) ) {
			if ( 'send' !== yg_lms_backfill_crm_step( $lead ) ) {
				$summary['held_german']++;
				$out( sprintf( 'GERMAN   lead %d  %s  in MongoDB (saveDetails) - goes to the German-language LMS, not pushed here', $lead['id'], $lead['name'] ) );
				continue;
			}
			if ( $dry_run ) {
				$out( sprintf( 'GERMAN   lead %d  %s  saveDetails has not accepted it -> next step: send to saveDetails', $lead['id'], $lead['name'] ) );
				continue;
			}
			yg_lms_backfill_send_crm( $lead, $summary, $out, 'German' );
			continue;
		}

		/*
		 * Already confirmed in the LMS (it came in another way, e.g. a call),
		 * but saveDetails never accepted it: MongoDB still needs it.
		 */
		global $wpdb;
		$verified = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}yg_lead_api_log WHERE lead_id = %d AND channel = 'lms' AND ok = 1", (int) $lead['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $verified ) {
			if ( yg_lms_backfill_via_crm() && 'send' === yg_lms_backfill_crm_step( $lead ) ) {
				if ( $dry_run ) {
					$out( sprintf( 'NOT IN MongoDB  lead %d  %s  in the LMS, but saveDetails never accepted it -> next step: send to saveDetails', $lead['id'], $lead['name'] ) );
				} else {
					yg_lms_backfill_send_crm( $lead, $summary, $out, 'in LMS, not in MongoDB' );
				}
			}
			continue;
		}

		$found = yg_lms_find_lead( $lead );
		usleep( 200000 );

		if ( null === $found ) {
			$summary['search_failed']++;
			$out( sprintf( 'lead %d  %s  LMS search failed - will retry next run', $lead['id'], $lead['name'] ) );
			continue;
		}

		if ( $found ) {
			$summary['in_lms']++;
			if ( ! $dry_run ) {
				yg_lead_api_record(
					array(
						'channel'     => 'lms',
						'transport'   => 'verify',
						'http_status' => 200,
						'response'    => wp_json_encode( array( 'verified' => 'found in LMS by ' . $found['by'] . ' search', 'lead_id' => $found['lead_id'] ) ),
						'lead_id'     => (int) $lead['id'],
						'email'       => $lead['email'],
						'phone'       => $lead['phone'],
						'page_url'    => $lead['page_url'],
					)
				);
			}
			if ( yg_lms_backfill_via_crm() && 'send' === yg_lms_backfill_crm_step( $lead ) ) {
				if ( $dry_run ) {
					$out( sprintf( 'NOT IN MongoDB  lead %d  %s  in the LMS, but saveDetails never accepted it -> next step: send to saveDetails', $lead['id'], $lead['name'] ) );
				} else {
					yg_lms_backfill_send_crm( $lead, $summary, $out, 'in LMS, not in MongoDB' );
				}
			}
			continue;
		}

		$summary['missing']++;
		$step = yg_lms_backfill_via_crm() ? yg_lms_backfill_crm_step( $lead ) : 'lms';

		if ( $dry_run ) {
			$out( sprintf( 'MISSING  lead %d  %s UTC  %s  %s  -> next step: %s', $lead['id'], $lead['created_at'], $lead['name'], $lead['phone'], 'send' === $step ? 'send to saveDetails' : ( 'wait' === $step ? 'wait for the MongoDB->LMS sync' : 'push to LMS widget' ) ) );
			continue;
		}

		if ( 'wait' === $step ) {
			$summary['waiting']++;
			$out( sprintf( 'WAITING  lead %d  %s  saveDetails accepted it; not in the LMS yet', $lead['id'], $lead['name'] ) );
			continue;
		}

		if ( 'send' === $step ) {
			$r = yg_lead_api_send( yg_lead_crm_payload_from_lead( $lead ), array( 'transport' => 'auto-backfill-crm', 'lead_id' => (int) $lead['id'] ) );
			$summary[ $r['ok'] ? 'sent_crm' : 'crm_failed' ]++;
			$out( sprintf( 'SENT TO saveDetails  lead %d  %s  HTTP %d  %s', $lead['id'], $lead['name'], $r['code'], $r['ok'] ? $r['crm_lead_id'] : 'FAILED ' . substr( $r['body'], 0, 150 ) ) );
			sleep( 1 );
			continue;
		}

		$started = microtime( true );
		$res     = wp_remote_post(
			yg_lms_url(),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'api-key'      => yg_lms_api_key(),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( yg_lms_backfill_payload( $lead ) ),
			)
		);
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$code = 0;
			$body = 'transport error: ' . $res->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
		}

		yg_lead_api_record(
			array(
				'channel'     => 'lms',
				'transport'   => 'auto-backfill',
				'http_status' => $code ? $code : null,
				'response'    => $body,
				'lead_id'     => (int) $lead['id'],
				'email'       => $lead['email'],
				'phone'       => $lead['phone'],
				'page_url'    => $lead['page_url'],
				'duration_ms' => $ms,
			)
		);

		$decoded = json_decode( $body, true );
		$ok      = $code >= 200 && $code < 300 && is_array( $decoded ) && ! empty( $decoded['lead_id'] );
		$message = is_array( $decoded ) && isset( $decoded['message'] ) ? $decoded['message'] : substr( $body, 0, 200 );
		$summary[ $ok ? 'pushed' : 'push_failed' ]++;
		$out( sprintf( 'PUSHED  lead %d  %s  HTTP %d  %s  %s', $lead['id'], $lead['name'], $code, $ok ? $decoded['lead_id'] : 'FAILED', $message ) );
		sleep( 1 );
	}

	if ( ! $dry_run ) {
		delete_transient( 'yg_lms_backfill_lock' );
	}
	return $summary;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Check recent www leads in the LMS and push the missing ones.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Check and list missing leads; record and push nothing.
	 *
	 * [--hours=<n>]
	 * : Look-back window in hours. Default 48.
	 *
	 * [--limit=<n>]
	 * : Maximum leads this run. Default 300.
	 */
	WP_CLI::add_command(
		'yg-lms-backfill',
		function ( $args, $assoc ) {
			$dry   = ! empty( $assoc['dry-run'] );
			$hours = isset( $assoc['hours'] ) ? max( 1, (int) $assoc['hours'] ) : YG_LMS_CHECK_HOURS;
			$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : YG_LMS_BACKFILL_BATCH;
			$s     = yg_lms_backfill_run(
				$dry,
				$limit,
				$hours,
				function ( $line ) {
					WP_CLI::log( $line );
				}
			);
			WP_CLI::log(
				sprintf(
					'[%s UTC] %s: %d checked, %d in LMS, %d missing, %d sent to saveDetails, %d saveDetails failed, %d waiting for sync, %d pushed to LMS, %d push failed, %d search failed, %d German held (in MongoDB)%s',
					gmdate( 'Y-m-d H:i:s' ),
					$dry ? 'dry run' : 'run',
					$s['checked'],
					$s['in_lms'],
					$s['missing'],
					$s['sent_crm'],
					$s['crm_failed'],
					$s['waiting'],
					$s['pushed'],
					$s['push_failed'],
					$s['search_failed'],
					$s['held_german'],
					yg_lms_backfill_via_crm() ? '' : ' [saveDetails-first is OFF]'
				)
			);
		}
	);
}
