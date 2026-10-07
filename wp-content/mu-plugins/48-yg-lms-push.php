<?php
/**
 * Plugin Name: YG mh Lead Delivery (CRM + LMS)
 * Description: Makes enquiries submitted from mh.yesgermany.com reach both downstream systems. The CRM push in 45-yg-lead-crm-push.php runs in the browser by default, off CF7's JS events - neither of which exists on mh - so mh leads were never reaching it; this forces that push server-side for mh only. It also forwards to the LMS lead-widget, which is a separate system from the CRM and does not receive its leads (verified 24 Sep 2026).
 * Version:     1.4.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * LMS lead-widget endpoint.
 *
 * Separate from the CRM. Measured 24 Sep 2026: posting a lead here returns
 * {"success":"1","message":"Lead added successfully","lead_id":"<uuid>"} and the
 * lead appears in Lead Management; the same lead posted to the CRM returns a
 * crm_lead_id and does not appear there.
 */
const YG_LMS_DEFAULT_URL = 'https://lms.yesgermany.org/api/v1/lead-management/lead-widget/website';

function yg_lms_url() {
	if ( defined( 'YG_LMS_URL' ) && YG_LMS_URL ) {
		return (string) YG_LMS_URL;
	}
	return (string) apply_filters( 'yg_lms_url', YG_LMS_DEFAULT_URL );
}

/**
 * The widget's API key.
 *
 * Set YG_LMS_API_KEY in wp-config.php. It is deliberately not in this file: the
 * repository is public. The key also sat in the page source of the mh landing
 * pages until 24 Sep 2026, so it should be rotated at the LMS end.
 *
 * Returns '' when unset, and yg_lms_push() then logs and skips the LMS.
 */
function yg_lms_api_key() {
	if ( defined( 'YG_LMS_API_KEY' ) && YG_LMS_API_KEY ) {
		return (string) YG_LMS_API_KEY;
	}
	return (string) apply_filters( 'yg_lms_api_key', '' );
}

/**
 * Which origins are forwarded.
 *
 * Only mh for now. Main-site submissions are deliberately left alone: whether
 * they already reach the LMS by some other route is the client's to confirm, and
 * forwarding them blindly would duplicate every lead there. Widen this list once
 * that is known.
 */
function yg_lms_origins() {
	return (array) apply_filters( 'yg_lms_origins', array( 'https://mh.yesgermany.com' ) );
}

/**
 * Did this submission come from one of those origins?
 *
 * CF7 cannot help here: get_request_url() only trusts a referer that starts with
 * this site's own home_url, so a cross-install submission records the REST
 * endpoint instead.
 *
 * Origin is checked before Referer, and it is the one that matters. A browser
 * MUST send Origin on a cross-origin POST, and it is exactly the scheme and host
 * with no path. Referer is best-effort: the default referrer policy
 * (strict-origin-when-cross-origin) trims it to the origin anyway, and a
 * privacy setting, a `no-referrer` policy or an extension can remove it
 * altogether - which would silently drop the lead from the LMS. Referer is kept
 * only as a fallback for a non-browser caller that sets it.
 *
 * The landing page is not lost by relying on Origin: form.js writes
 * hostname + pathname into utm_content, which travels in field_data.
 */
function yg_lms_is_forwarded_origin() {
	$candidates = array();

	if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
		$candidates[] = (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] );
	}
	if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
		$candidates[] = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
	}

	foreach ( $candidates as $candidate ) {
		if ( '' === $candidate ) {
			continue;
		}
		foreach ( yg_lms_origins() as $origin ) {
			if ( 0 === strpos( $candidate, rtrim( (string) $origin, '/' ) ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Failures, with the whole payload, so a lost lead can be replayed by hand.
 */
function yg_lms_log( $reason, array $payload ) {
	$dir  = wp_upload_dir();
	$file = trailingslashit( $dir['basedir'] ) . 'yg-lms-push.log';

	@file_put_contents( // phpcs:ignore
		$file,
		wp_json_encode(
			array(
				'at'      => current_time( 'mysql' ),
				'reason'  => $reason,
				'url'     => yg_lms_url(),
				'payload' => $payload,
			)
		) . "\n",
		FILE_APPEND
	);
}

/**
 * Build the widget's field_data list.
 *
 * The LMS is given the form's own field names plus two legacy aliases. The old
 * hand-built mh form sent `interested_course` and `city`, so whatever mapping
 * the LMS has was built around those names; the current form calls the same
 * things `level_of_education` and `state`. Sending both costs nothing and means
 * the LMS keeps populating the fields it already knows.
 */
function yg_lms_field_data( array $posted ) {
	$skip = '/^_wpcf7|^_wpnonce|(^|[_-])(pass|passwd|password|pwd|token|nonce|captcha|cvv|iban|card|cardnumber)([_-]|$)/i';

	$fields = array();

	foreach ( $posted as $key => $value ) {
		if ( preg_match( $skip, $key ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}
		if ( ! is_scalar( $value ) ) {
			continue;
		}
		$fields[ $key ] = sanitize_textarea_field( (string) $value );
	}

	/* Phone with its country code, the way the old form sent it. */
	if ( ! empty( $fields['phone'] ) && ! empty( $fields['phone-country-code'] ) ) {
		$code  = preg_replace( '/[^0-9+]/', '', $fields['phone-country-code'] );
		$plain = preg_replace( '/[^0-9]/', '', $fields['phone'] );
		if ( '' !== $code && 0 !== strpos( $fields['phone'], '+' ) ) {
			$fields['phone'] = ( '+' === $code[0] ? $code : '+' . $code ) . $plain;
		}
	}

	if ( ! empty( $fields['level_of_education'] ) && empty( $fields['interested_course'] ) ) {
		$fields['interested_course'] = $fields['level_of_education'];
	}
	if ( ! empty( $fields['state'] ) && empty( $fields['city'] ) ) {
		$fields['city'] = $fields['state'];
	}

	$out = array();
	foreach ( $fields as $code => $value ) {
		$out[] = array(
			'code'  => $code,
			'value' => $value,
		);
	}

	return $out;
}

/**
 * Forward the submission.
 *
 * Runs on the same hook as the CRM push and the lead capture, at a later
 * priority so it can never delay either. A failure here is logged and swallowed:
 * the lead is already recorded on this site and in the CRM, and CF7 must still
 * tell the visitor the form was sent.
 */
function yg_lms_push( $contact_form, &$abort = null, $submission = null ) {
	try {
		/*
		 * Production only, like the CRM push in 45-yg-lead-crm-push.php. The LMS
		 * is a live system, so a test submission on staging must never reach it,
		 * even if staging's wp-config.php is ever given the key.
		 */
		if ( function_exists( 'yg_is_production' ) && ! yg_is_production() ) {
			return;
		}

		if ( ! yg_lms_is_forwarded_origin() ) {
			return;
		}

		if ( ! $submission && class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
		}
		if ( ! $submission ) {
			return;
		}

		$posted  = (array) $submission->get_posted_data();
		$form_id = $contact_form ? (int) $contact_form->id() : 0;

		/**
		 * Whether this submission goes to the LMS.
		 *
		 * @param bool  $push    Whether to forward.
		 * @param int   $form_id CF7 form ID.
		 * @param array $posted  Posted data.
		 */
		if ( ! apply_filters( 'yg_lms_should_push', true, $form_id, $posted ) ) {
			return;
		}

		/* One POST per submission, however many times CF7 runs its hooks. */
		static $sent = array();
		$unit_tag    = isset( $_POST['_wpcf7_unit_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpcf7_unit_tag'] ) ) : (string) $form_id; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- CF7 has already validated this submission.
		if ( isset( $sent[ $unit_tag ] ) ) {
			return;
		}
		$sent[ $unit_tag ] = true;

		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		$payload = array(
			'field_data'   => yg_lms_field_data( $posted ),
			'tracking_url' => $referer,
			'redirect_url' => $referer ? trailingslashit( $referer ) : '',
		);

		if ( empty( $payload['field_data'] ) ) {
			return;
		}

		if ( '' === yg_lms_api_key() ) {
			yg_lms_log( 'YG_LMS_API_KEY is not defined in wp-config.php; lead not sent to the LMS', $payload );
			return;
		}

		$response = wp_remote_post(
			yg_lms_url(),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'api-key'      => yg_lms_api_key(),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			yg_lms_log( 'transport error: ' . $response->get_error_message(), $payload );
			if ( function_exists( 'yg_lead_api_record' ) ) {
				yg_lead_api_record( array( 'channel' => 'lms', 'transport' => 'server', 'response' => 'transport error: ' . $response->get_error_message(), 'email' => isset( $posted['email'] ) ? $posted['email'] : '', 'phone' => isset( $posted['phone'] ) ? $posted['phone'] : '', 'page_url' => $referer ) );
			}
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		/* Every answer is kept next to the lead (mu-plugin 57), not only failures. */
		if ( function_exists( 'yg_lead_api_record' ) ) {
			yg_lead_api_record(
				array(
					'channel'     => 'lms',
					'transport'   => 'server',
					'http_status' => $code,
					'response'    => $body,
					'email'       => isset( $posted['email'] ) ? $posted['email'] : '',
					'phone'       => isset( $posted['phone'] ) ? $posted['phone'] : '',
					'page_url'    => $referer,
				)
			);
		}

		if ( $code < 200 || $code > 299 ) {
			yg_lms_log( sprintf( 'HTTP %d: %s', $code, $body ), $payload );
			return;
		}

		/*
		 * The widget answers {"success":"1", ... ,"lead_id":"<uuid>"} - note
		 * success is the string "1", not a boolean - and {"success":"0",
		 * "message":"Phone number is mandatory"} when it refuses.
		 */
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || empty( $decoded['lead_id'] ) ) {
			yg_lms_log( 'no lead_id returned: ' . $body, $payload );
		}
	} catch ( \Throwable $e ) {
		yg_lms_log( 'exception: ' . $e->getMessage(), array() );
	}
}

/* 25: after the CRM push (20) and the lead capture (5), so neither is delayed. */
add_action( 'wpcf7_before_send_mail', 'yg_lms_push', 25, 3 );

/*
 * Why the CRM is NOT pushed from here.
 *
 * The obvious fix for mh was to force 45-yg-lead-crm-push.php onto its
 * server-side transport, since mh never loads that plugin's browser script and
 * never fires the CF7 JS events it listens for. That was tried on 24 Sep 2026
 * and fails at the network layer:
 *
 *   cURL error 7: Failed to connect to yesgermany.org:8443 after 1 ms
 *
 * Outbound TCP 8443 is refused from this web server - immediately, so it is a
 * firewall rule, not a timeout. Port 443 to the same host and 443 to the LMS
 * both open fine. That is almost certainly why the CRM push was written to run
 * in the visitor's browser in the first place.
 *
 * So mh pushes the CRM from the browser too, in its own form.js, exactly as the
 * main site does. The CRM allows it: a cross-origin POST from
 * https://mh.yesgermany.com returns Access-Control-Allow-Origin for that origin
 * and creates the lead.
 *
 * If the host ever opens outbound 8443, the better arrangement is to set
 * YG_LEAD_API_TRANSPORT to 'server' in wp-config.php, which moves the CRM push
 * server-side for every install at once and removes the dependency on the
 * visitor's browser staying open. The transport filter added to mu-plugin 45
 * makes that a one-line change.
 */
