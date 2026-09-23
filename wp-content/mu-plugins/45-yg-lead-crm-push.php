<?php
/**
 * Plugin Name: YG Lead CRM Push
 * Description: Sends a lead to the YES Germany CRM once - on the final, fully
 *              validated form submission. Replaces the site-wide JavaScript
 *              submit listener that posted every form, including half-filled
 *              ones, and unhooks it if it is still present in the theme.
 * Version:     1.0.0
 * Author:      Stealth Digital
 *
 * Why this is a must-use plugin and not theme code
 * ------------------------------------------------
 * The live site runs the PARENT theme `zakra`, not `zakra-child`, so anything
 * placed in the child theme does not execute on production. mu-plugins run
 * whatever the active theme is, and are one of the three paths the deploy
 * pipeline actually ships.
 *
 * Why the CF7 hook and not a submit listener
 * ------------------------------------------
 * `wpcf7_before_send_mail` fires once per submission, only after Contact Form 7
 * has validated every required field. A DOM "submit" listener fires earlier and
 * for everything: each step of a multi-step form, submissions that then fail
 * validation, the search box, the comment form. That is how leads carrying
 * nothing but a name, email and phone reached the CRM.
 *
 * Two transports, one rule
 * ------------------------
 * The CRM listens on port 8443, and this cPanel host REJECTS all outbound
 * traffic on non-standard ports - `portquiz.net:8443` is refused in 1ms, so it
 * is the host's own firewall, not the CRM's. Until outbound 8443 is opened,
 * PHP cannot reach the CRM at all, so the browser has to make the call.
 *
 *   browser (default) - the visitor's browser POSTs, on CF7's own submit event,
 *                       once, only for a submission CF7 accepted. Works today.
 *   server            - PHP POSTs on wpcf7_before_send_mail. Nothing the
 *                       visitor runs can block or skip it. Switch to this the
 *                       day outbound 8443 is open:
 *                       define( 'YG_LEAD_API_TRANSPORT', 'server' );
 *
 * Either way the trigger is the same: one completed, validated submission. The
 * browser transport listens for CF7's `wpcf7submit` event, NOT for the DOM
 * "submit" event the old code used - that is the whole difference.
 *
 * Elementor Pro forms
 * -------------------
 * Three published pages collect leads with an Elementor Pro form rather than
 * CF7: german-classes-online-in-germany, online-ielts-coaching-in-delhi and
 * online-ielts-course-in-mumbai. The old listener swept those up by accident,
 * because it caught every form on the site. Narrowing to CF7 would have dropped
 * them, so they are handled explicitly here - and recorded in the site's own
 * leads table as well, which has never held them: YG Lead Capture hooks CF7 and
 * nothing else, so those enquiries only ever existed as an email.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

const YG_LEAD_API_DEFAULT_URL = 'https://yesgermany.org:8443/websiteForm/saveDetails';

/*
 * Guarded because an older build of the child theme declared a function of this
 * name. mu-plugins load first, so if a stale copy is still on disk the theme's
 * require would otherwise fatal on a redeclare.
 */
if ( ! function_exists( 'yg_lead_api_url' ) ) {
	/**
	 * CRM endpoint. Overridable in wp-config.php so staging can be pointed at a
	 * test collector instead of the live CRM.
	 *
	 * @return string
	 */
	function yg_lead_api_url() {
		if ( defined( 'YG_LEAD_API_URL' ) && YG_LEAD_API_URL ) {
			return (string) YG_LEAD_API_URL;
		}
		return YG_LEAD_API_DEFAULT_URL;
	}
}

/**
 * Whether this install may actually POST to the CRM.
 *
 * Production sends. Anywhere else the payload is only written to the log, so a
 * staging test cannot create real leads in the client's CRM. Two escape
 * hatches, both set in wp-config.php:
 *
 *   YG_LEAD_API_ENABLED  true  - send from this install (use with a test URL)
 *                        false - never send, log only, even on production
 *   YG_LEAD_API_URL            - a non-production endpoint; safe to send to
 *
 * @return bool
 */
function yg_lead_api_enabled() {
	if ( defined( 'YG_LEAD_API_ENABLED' ) ) {
		return (bool) YG_LEAD_API_ENABLED;
	}
	if ( defined( 'YG_LEAD_API_URL' ) && YG_LEAD_API_URL ) {
		return true; // Explicit override endpoint - not the live CRM.
	}
	return ! function_exists( 'yg_is_production' ) || yg_is_production();
}

/*
 * ---------------------------------------------------------------------------
 * Remove the old site-wide listener
 *
 * `yesgermany_send_form_to_api()` lives in the parent theme's functions.php,
 * which is not in this repository and is not deployed, so it cannot simply be
 * deleted from here. Unhooking it stops it running and is revertible by
 * deleting this file. The parent-theme function should still be deleted by
 * hand once this has been verified on production.
 *
 * `yg_lead_capture_script()` was this repository's cleaned-up copy of the same
 * listener. It has been removed from the child theme; it is unhooked here too
 * in case an older build of the theme is still on disk.
 *
 * wp_loaded runs after the theme's functions.php, so both hooks exist by now.
 * ---------------------------------------------------------------------------
 */
add_action(
	'wp_loaded',
	function () {
		remove_action( 'wp_footer', 'yesgermany_send_form_to_api', 999 );
		remove_action( 'wp_footer', 'yg_lead_capture_script', 999 );
	}
);

/**
 * Which side makes the HTTP call.
 *
 * Defaults to the browser because this host's firewall refuses outbound 8443.
 * Set YG_LEAD_API_TRANSPORT to 'server' in wp-config.php once that is lifted.
 *
 * @return string 'browser' or 'server'
 */
function yg_lead_api_transport() {
	if ( defined( 'YG_LEAD_API_TRANSPORT' ) && 'server' === YG_LEAD_API_TRANSPORT ) {
		return 'server';
	}
	return 'browser';
}

/*
 * ---------------------------------------------------------------------------
 * Browser transport
 *
 * Bound to CF7's `wpcf7submit` event, which fires once per submission after
 * CF7 has decided the outcome. Only `mail_sent` and `mail_failed` are
 * forwarded: both mean every required field validated. `validation_failed`,
 * `spam` and `acceptance_missing` are not leads and are dropped - that is the
 * behaviour the old global listener did not have.
 *
 * `mail_failed` is forwarded deliberately. The lead is complete; whether the
 * notification email left the building is a separate problem, and this site
 * has a known sender-domain fault.
 * ---------------------------------------------------------------------------
 */
add_action( 'wp_footer', 'yg_lead_api_browser_script', 999 );

/**
 * Print the browser-side push.
 */
function yg_lead_api_browser_script() {
	if ( is_admin() || 'browser' !== yg_lead_api_transport() ) {
		return;
	}
	if ( ! yg_lead_api_enabled() ) {
		return; // Staging: never POST into the live CRM.
	}
	?>
	<script id="yg-lead-api-push">
	(function () {
		var API_URL = <?php echo wp_json_encode( yg_lead_api_url() ); ?>;
		var COMPLETE = { mail_sent: 1, mail_failed: 1 };
		var sent     = {};
		/*
		 * Anchored on word boundaries, not loose substrings. The old pattern
		 * matched "pass" anywhere, so a field named passport_number - entirely
		 * plausible on a study-abroad form - would have been dropped from the
		 * payload in silence. Nothing currently on the site hits it, but the
		 * trap was one new field away.
		 */
		var SKIP     = /^_wpcf7|^_wpnonce|^form_id$|(^|[_-])(pass|passwd|password|pwd|token|nonce|captcha|cvv|iban|card|cardnumber)([_-]|$)/i;

		/* Same session values the previous script recorded, unchanged, so the
		   CRM sees the field names it already reads. */
		try {
			if ( ! sessionStorage.getItem( 'yg_referral_url' ) ) {
				sessionStorage.setItem( 'yg_referral_url', document.referrer || '' );
			}
		} catch ( e ) {}

		/*
		 * Both events. `wpcf7mailsent` is dispatched first, so this gets its
		 * chance before wpcf7-redirect navigates; `wpcf7submit` still covers
		 * mail_failed, where the lead is complete but the notification did not
		 * leave. The unitTag guard keeps it to one send either way.
		 */
		document.addEventListener( 'wpcf7mailsent', handleCf7 );
		document.addEventListener( 'wpcf7submit', handleCf7 );

		function handleCf7( e ) {
			var d = e.detail || {};

			if ( ! COMPLETE[ d.status ] ) {
				return; /* Incomplete, spam, or a step that did not validate. */
			}
			if ( sent[ d.unitTag ] ) {
				return;
			}
			sent[ d.unitTag ] = true;

			var payload = {};

			( d.inputs || [] ).forEach( function ( field ) {
				if ( SKIP.test( field.name ) ) {
					return;
				}
				payload[ field.name ] = Array.isArray( field.value )
					? field.value.join( ', ' )
					: field.value;
			} );

			payload.form_id      = d.contactFormId || '';
			payload.tracking_url = window.location.href || '';
			payload.page_title   = document.title || '';
			payload.referrer     = document.referrer || '';
			payload.user_agent   = navigator.userAgent || '';
			payload.timestamp    = new Date().toISOString();

			try {
				payload.yg_referral_url = sessionStorage.getItem( 'yg_referral_url' ) || '';
			} catch ( err ) {}

			post( payload );
		}

		/*
		 * Elementor Pro forms.
		 *
		 * Elementor clears the fields once it has accepted a submission, so the
		 * values are snapshotted when the form is sent and only POSTed if
		 * Elementor then reports success. Success means Elementor's own
		 * server-side validation passed, which is the same rule CF7 gets.
		 *
		 * The listener is attached to each form element, never to document, so
		 * it can only ever see the forms it was bound to.
		 */
		var snapshots = new WeakMap();

		function bindElementorForms() {
			var forms = document.querySelectorAll( 'form.elementor-form' );
			for ( var i = 0; i < forms.length; i++ ) {
				if ( forms[ i ].dataset.ygBound ) {
					continue;
				}
				forms[ i ].dataset.ygBound = '1';
				forms[ i ].addEventListener( 'submit', function () {
					snapshots.set( this, readFields( this ) );
				} );
			}
		}

		/*
		 * Read the controls themselves rather than a FormData snapshot, because
		 * the field NAME is useless here: Elementor calls its fields
		 * form_fields[field_e94d360]. What identifies a field to a human - and
		 * so to the CRM - is its type and its placeholder, which only the
		 * element carries. Both are kept alongside the value.
		 */
		function readFields( form ) {
			var out  = { values: {}, hints: {} };
			var els  = form.querySelectorAll( 'input, select, textarea' );

			for ( var i = 0; i < els.length; i++ ) {
				var el   = els[ i ];
				var type = el.type || '';

				if ( ! el.name || el.disabled || 'submit' === type || 'button' === type ) {
					continue;
				}
				if ( ( 'checkbox' === type || 'radio' === type ) && ! el.checked ) {
					continue;
				}

				/* Elementor names its inputs form_fields[whatever]. */
				var m   = el.name.match( /^form_fields\[(.+)\]$/ );
				var key = m ? m[ 1 ] : el.name;

				if ( SKIP.test( key ) ) {
					continue;
				}

				out.values[ key ] = ( undefined !== out.values[ key ] && out.values[ key ] )
					? out.values[ key ] + ', ' + el.value
					: el.value;

				out.hints[ key ] = type + ' ' +
					( el.placeholder || '' ) + ' ' +
					( el.getAttribute( 'aria-label' ) || '' );
			}

			return out;
		}

		if ( window.jQuery ) {
			jQuery( document ).on( 'submit_success', function ( e ) {
				var form = e.target && e.target.closest
					? e.target.closest( 'form.elementor-form' )
					: null;
				if ( ! form ) {
					return;
				}

				var fields = snapshots.get( form ) || readFields( form );
				snapshots.delete( form );

				var payload = {};
				var k;
				for ( k in fields.values ) {
					if ( Object.prototype.hasOwnProperty.call( fields.values, k ) ) {
						payload[ k ] = fields.values[ k ];
					}
				}

				/*
				 * The CRM reads the Enquiry Form's field names, so an Elementor
				 * lead is given the same three keys. Matched on the field's own
				 * type and placeholder, since its name carries no meaning. The
				 * originals stay in the payload, so nothing is lost.
				 */
				for ( k in fields.values ) {
					if ( ! Object.prototype.hasOwnProperty.call( fields.values, k ) ) {
						continue;
					}
					var value = fields.values[ k ];
					var hint  = k + ' ' + ( fields.hints[ k ] || '' );

					if ( ! value ) {
						continue;
					}
					if ( ! payload.email && /email/i.test( hint ) ) {
						payload.email = value;
					}
					if ( ! payload.phone && /tel|phone|mobile|contact number/i.test( hint ) ) {
						payload.phone = value;
					}
					if ( ! payload.full_name && /^name |full name|your name/i.test( hint ) ) {
						payload.full_name = value;
					}
				}

				payload.form_source  = 'elementor';
				payload.tracking_url = window.location.href || '';
				payload.page_title   = document.title || '';
				payload.referrer     = document.referrer || '';
				payload.user_agent   = navigator.userAgent || '';
				payload.timestamp    = new Date().toISOString();

				post( payload );
			} );
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', bindElementorForms );
		} else {
			bindElementorForms();
		}
		document.addEventListener( 'elementor/popup/show', bindElementorForms );

		/**
		 * fetch, not sendBeacon: a JSON content type makes the request
		 * preflighted, and sendBeacon cannot preflight - it would be dropped
		 * silently. The CRM answers OPTIONS correctly for this site's own
		 * origins. keepalive covers the redirect to /thank-you/.
		 */
		/*
		 * The CRM is sent the number in full international form.
		 *
		 * The form splits it: the visible field holds the national number and a
		 * hidden phone-country-code holds the dial code, so the CRM was
		 * receiving a bare 10 digits with the country in a separate key it may
		 * never have read. `phone` now carries +91XXXXXXXXXX. The national
		 * number is kept alongside so nothing is lost.
		 *
		 * India is the fallback when no dial code came through - 488 of 494
		 * recorded leads are +91, and 5 arrived with the field empty.
		 */
		function withFullPhone( payload ) {
			var raw = payload.phone || payload.mobile || '';
			if ( ! raw ) {
				return payload;
			}
			var national = String( raw ).replace( /\D/g, '' );
			var cc       = String( payload['phone-country-code'] || '' ).replace( /[^0-9]/g, '' ) || '91';

			payload.phone_national     = national;
			payload.phone_country_code = '+' + cc;
			payload.phone              = '+' + cc + national;
			return payload;
		}

		/**
		 * sendBeacon with form encoding, because the lead has to survive the
		 * redirect.
		 *
		 * Contact Form 7 dispatches `wpcf7mailsent` BEFORE `wpcf7submit`, and
		 * wpcf7-redirect navigates to /thank-you/ on mailsent. By the time this
		 * ran the page was already unloading and the fetch - keepalive or not -
		 * was killed mid-flight. Measured on the live site: sent directly, the
		 * CRM created a lead every time; of 30 real form submissions it created
		 * none, and of 10 slower ones only 7.
		 *
		 * sendBeacon exists for exactly this: the browser takes ownership of the
		 * request and completes it after the page is gone.
		 *
		 * It cannot carry an application/json content type - that is not
		 * CORS-safelisted, so it would need a preflight, which sendBeacon cannot
		 * perform, and the request is dropped in silence. URLSearchParams sends
		 * application/x-www-form-urlencoded, which is safelisted. The CRM parses
		 * it and returns a crm_lead_id - verified against the live endpoint
		 * before this was written.
		 */
		function post( payload ) {
			payload = withFullPhone( payload );

			var params = new URLSearchParams();
			for ( var k in payload ) {
				if ( Object.prototype.hasOwnProperty.call( payload, k ) ) {
					var v = payload[ k ];
					params.append( k, ( null === v || undefined === v ) ? '' : String( v ) );
				}
			}

			var queued = false;
			try {
				if ( navigator.sendBeacon ) {
					queued = navigator.sendBeacon( API_URL, params );
				}
			} catch ( err ) {}

			if ( queued ) {
				return;
			}

			/* No sendBeacon, or it refused the payload: the same encoding over
			   fetch, so this path needs no preflight either. */
			try {
				fetch( API_URL, {
					method:    'POST',
					headers:   { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
					body:      params.toString(),
					keepalive: true
				} ).catch( function () {} );
			} catch ( err ) {}
		}
	})();
	</script>
	<?php
}

/*
 * ---------------------------------------------------------------------------
 * Server transport
 * ---------------------------------------------------------------------------
 */

add_action( 'wpcf7_before_send_mail', 'yg_lead_api_push', 20, 3 );

/**
 * Forward one completed submission to the CRM.
 *
 * Priority 20 puts this after YG Lead Capture's own record at priority 5, so
 * the site keeps its copy of the lead even if the CRM call fails.
 *
 * @param WPCF7_ContactForm $contact_form The submitted form.
 * @param bool              $abort        CF7's abort flag - never touched here.
 * @param WPCF7_Submission  $submission   The submission object.
 */
function yg_lead_api_push( $contact_form, &$abort = null, $submission = null ) {
	static $sent = array();

	if ( 'server' !== yg_lead_api_transport() ) {
		return; /* The browser is making this call - never send it twice. */
	}

	try {
		if ( ! $submission && class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
		}
		if ( ! $submission ) {
			return;
		}

		$form_id    = $contact_form ? (int) $contact_form->id() : 0;
		$form_title = $contact_form ? (string) $contact_form->title() : '';

		/**
		 * Whether this form's submissions go to the CRM.
		 *
		 * Every validated CF7 form is forwarded by default - that is already far
		 * narrower than the old listener, which posted every form on the site.
		 * Filter it to exclude e-book downloads or job applications if the CRM
		 * should not hold them.
		 *
		 * @param bool   $push       Whether to forward.
		 * @param int    $form_id    CF7 form ID.
		 * @param string $form_title CF7 form title.
		 */
		if ( ! apply_filters( 'yg_lead_api_should_push', true, $form_id, $form_title ) ) {
			return;
		}

		/* One POST per submission, however many times CF7 runs its hooks. */
		$unit_tag = isset( $_POST['_wpcf7_unit_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpcf7_unit_tag'] ) ) : (string) $form_id; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- CF7 has already validated this submission.
		if ( isset( $sent[ $unit_tag ] ) ) {
			return;
		}
		$sent[ $unit_tag ] = true;

		$payload = yg_lead_api_payload( $submission, $form_id, $form_title );

		/**
		 * The payload as it will be sent. Field names are the form's own, which
		 * is what the CRM already reads.
		 *
		 * @param array $payload Payload.
		 * @param int   $form_id CF7 form ID.
		 */
		$payload = apply_filters( 'yg_lead_api_payload', $payload, $form_id );

		yg_lead_api_send( $payload );
	} catch ( \Throwable $e ) {
		/* The CRM must never be able to break a form submission. */
		yg_lead_api_log( 'exception: ' . $e->getMessage(), array() );
	}
}

/**
 * Build the CRM payload from a validated submission.
 *
 * Field names are left exactly as the form posts them, and the tracking keys
 * carry the same names the old script used, so the CRM needs no change.
 *
 * @param WPCF7_Submission $submission Validated submission.
 * @param int              $form_id    CF7 form ID.
 * @param string           $form_title CF7 form title.
 * @return array
 */
function yg_lead_api_payload( $submission, $form_id, $form_title ) {
	/*
	 * CF7's own plumbing, and anything that could carry a credential. Anchored
	 * on word boundaries so a real field is never dropped by accident - the
	 * loose version matched "pass" inside passport_number.
	 */
	$skip = '/^_wpcf7|^_wpnonce|(^|[_-])(pass|passwd|password|pwd|token|nonce|captcha|cvv|iban|card|cardnumber)([_-]|$)/i';

	$payload = array();
	foreach ( (array) $submission->get_posted_data() as $key => $value ) {
		if ( preg_match( $skip, $key ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}
		if ( ! is_scalar( $value ) ) {
			continue;
		}
		$payload[ $key ] = sanitize_textarea_field( (string) $value );
	}

	$page_url = (string) $submission->get_meta( 'url' );

	$payload['form_id']      = $form_id;
	$payload['form_title']   = $form_title;
	$payload['tracking_url'] = $page_url;
	$payload['page_title']   = yg_lead_api_page_title( $page_url );
	$payload['referrer']     = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	$payload['user_agent']   = (string) $submission->get_meta( 'user_agent' );
	$payload['remote_ip']    = (string) $submission->get_meta( 'remote_ip' );
	$payload['timestamp']    = gmdate( 'c' );

	/*
	 * The old script read these two from sessionStorage. Server-side the same
	 * information is in the attribution cookie YG Lead Capture writes on the
	 * landing page, which is more reliable: it survives the visitor navigating
	 * between pages, and sessionStorage did not survive a new tab.
	 */
	$attr                          = yg_lead_api_attribution();
	$payload['yg_referral_url']    = $attr['referrer'];
	$payload['yg_landing_page']    = $attr['landing_page'];

	return $payload;
}

/**
 * Title of the page the form was submitted from.
 *
 * @param string $page_url Page URL recorded by CF7.
 * @return string
 */
function yg_lead_api_page_title( $page_url ) {
	$post_id = $page_url ? url_to_postid( $page_url ) : 0;
	if ( $post_id ) {
		return (string) get_the_title( $post_id );
	}
	return (string) get_bloginfo( 'name' );
}

/**
 * Referrer and landing page from the `yg_attr` cookie, when it is there.
 *
 * Read directly rather than through YG_LC_Attribution so this keeps working if
 * the YG Lead Capture plugin is ever deactivated.
 *
 * @return array{referrer:string,landing_page:string}
 */
function yg_lead_api_attribution() {
	$out = array(
		'referrer'     => '',
		'landing_page' => '',
	);

	if ( empty( $_COOKIE['yg_attr'] ) ) {
		return $out;
	}

	$data = json_decode( wp_unslash( $_COOKIE['yg_attr'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is sanitized below.
	if ( ! is_array( $data ) ) {
		return $out;
	}

	foreach ( array_keys( $out ) as $key ) {
		if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
			$out[ $key ] = esc_url_raw( (string) $data[ $key ] );
		}
	}

	return $out;
}

/**
 * Record a dry run or a failure.
 *
 * A lead that the CRM did not take is the one thing worth keeping, so the whole
 * payload is written and can be replayed by hand. The site's own copy of the
 * lead is in wpb9_yg_leads regardless.
 *
 * @param string $reason  What happened.
 * @param array  $payload The payload.
 */
function yg_lead_api_log( $reason, array $payload ) {
	$dir  = wp_upload_dir();
	$file = trailingslashit( $dir['basedir'] ) . 'yg-lead-api.log';

	$line = wp_json_encode(
		array(
			'at'      => current_time( 'mysql' ),
			'reason'  => $reason,
			'url'     => yg_lead_api_url(),
			'payload' => $payload,
		)
	);

	@file_put_contents( $file, $line . "\n", FILE_APPEND ); // phpcs:ignore
}

/**
 * POST one payload to the CRM and judge the answer.
 *
 * @param array $payload Payload.
 */
function yg_lead_api_send( array $payload ) {
	$payload = yg_lead_api_full_phone( $payload );

	if ( ! yg_lead_api_enabled() ) {
		yg_lead_api_log( 'dry-run (not sent)', $payload );
		return;
	}

	$response = wp_remote_post(
		yg_lead_api_url(),
		array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $response ) ) {
		yg_lead_api_log( 'transport error: ' . $response->get_error_message(), $payload );
		return;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( $code < 200 || $code > 299 ) {
		yg_lead_api_log( sprintf( 'HTTP %d: %s', $code, $body ), $payload );
		return;
	}

	/*
	 * A 200 is not proof the lead landed. The CRM answers
	 * {"success":true,"message":...,"crm_lead_id":"<uuid>"} when it created one,
	 * and the same 200 with no crm_lead_id when it did not.
	 *
	 * Measured 2026-09-22 against the live endpoint: the CRM creates a lead if
	 * and only if the payload carries a `phone` key. Name and email alone
	 * produce a 200 and no lead; a phone of "123" with a malformed email
	 * produces one. It validates nothing - which is why completeness has to be
	 * enforced on this side, and why a missing id is only a failure for a
	 * payload that actually had a phone. A form that collects name and email
	 * only, such as an e-book download, is working correctly with no id back.
	 */
	$decoded = json_decode( $body, true );
	$had_phone = ! empty( $payload['phone'] );

	if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
		yg_lead_api_log( 'rejected: ' . $body, $payload );
		return;
	}
	if ( $had_phone && empty( $decoded['crm_lead_id'] ) ) {
		yg_lead_api_log( 'phone sent but no crm_lead_id returned: ' . $body, $payload );
	}
}

/*
 * ---------------------------------------------------------------------------
 * Elementor Pro forms
 *
 * `elementor_pro/forms/new_record` fires only once Elementor has validated the
 * submission, so it carries the same guarantee as the CF7 hook: every required
 * field is filled.
 *
 * Two jobs here, and the first one is new. These leads have never been in
 * wpb9_yg_leads - YG Lead Capture hooks Contact Form 7 and nothing else - so
 * the three Elementor pages' enquiries existed only as an email. Recording them
 * puts every lead on the site in one table and one dashboard.
 * ---------------------------------------------------------------------------
 */
add_action( 'elementor_pro/forms/new_record', 'yg_lead_elementor_record', 10, 2 );

/**
 * Record an Elementor submission, and forward it when PHP is the transport.
 *
 * @param object $record  Elementor's Form_Record.
 * @param object $handler Elementor's AJAX handler - not used.
 */
function yg_lead_elementor_record( $record, $handler = null ) {
	try {
		$fields = is_object( $record ) && method_exists( $record, 'get' ) ? $record->get( 'fields' ) : null;
		if ( ! is_array( $fields ) ) {
			return;
		}

		$posted = array();
		foreach ( $fields as $id => $field ) {
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
			}
			$posted[ (string) $id ] = sanitize_textarea_field( (string) $value );

			/*
			 * Elementor names most fields field_<hash>, which tells the leads
			 * table nothing. Copy the value to the name the table reads, using
			 * the field's own type and label to decide which it is. The
			 * original key is kept, so nothing is lost.
			 */
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$title = isset( $field['title'] ) ? (string) $field['title'] : '';

			if ( ( 'email' === $type || 'email' === $id ) && empty( $posted['email'] ) ) {
				$posted['email'] = $posted[ (string) $id ];
			}
			if ( ( 'tel' === $type || preg_match( '/phone|mobile|contact number/i', $title . ' ' . $id ) ) && empty( $posted['phone'] ) ) {
				$posted['phone'] = $posted[ (string) $id ];
			}
			if ( ( 'name' === $id || preg_match( '/full name|your name/i', $title ) ) && empty( $posted['full_name'] ) ) {
				$posted['full_name'] = $posted[ (string) $id ];
			}
		}

		$page_url = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$settings = method_exists( $record, 'get_form_settings' ) ? $record->get_form_settings( 'form_name' ) : '';
		$post_id  = $page_url ? url_to_postid( $page_url ) : 0;

		/* Record in the site's own table, through the plugin that owns it. */
		if ( class_exists( 'YG_LC_Capture' ) && method_exists( 'YG_LC_Capture', 'record' ) ) {
			YG_LC_Capture::record(
				$posted,
				array(
					'form_id'    => (int) $post_id,
					'form_title' => 'Elementor: ' . ( $settings ? $settings : 'form' ),
					'status'     => 'sent',
					'unit_tag'   => 'elementor-' . md5( $page_url . wp_json_encode( $posted ) ),
					'page_url'   => $page_url,
					'referrer'   => $page_url,
					'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'cookie'     => class_exists( 'YG_LC_Attribution' ) ? YG_LC_Attribution::from_request() : array(),
				)
			);
		}

		/*
		 * The CRM call. On the browser transport the page makes it on
		 * submit_success instead, so this would be a duplicate.
		 */
		if ( 'server' !== yg_lead_api_transport() ) {
			return;
		}

		$payload = $posted;
		$attr    = yg_lead_api_attribution();

		$payload['form_source']     = 'elementor';
		$payload['form_title']      = 'Elementor: ' . ( $settings ? $settings : 'form' );
		$payload['tracking_url']    = $page_url;
		$payload['page_title']      = yg_lead_api_page_title( $page_url );
		$payload['referrer']        = $page_url;
		$payload['user_agent']      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$payload['timestamp']       = gmdate( 'c' );
		$payload['yg_referral_url'] = $attr['referrer'];

		yg_lead_api_send( $payload );
	} catch ( \Throwable $e ) {
		yg_lead_api_log( 'elementor: ' . $e->getMessage(), array() );
	}
}

/**
 * Put the dial code back onto the number before it leaves.
 *
 * The form splits a phone in two - the visible field holds the national number,
 * a hidden `phone-country-code` holds the dial code - so the CRM was receiving
 * a bare 10 digits. This sends `phone` as +91XXXXXXXXXX and keeps the national
 * number and the dial code beside it, so no part of what the visitor typed is
 * lost. India is the fallback: 488 of 494 recorded leads are +91, and 5 came
 * through with the country-code field empty.
 *
 * @param array $payload Payload.
 * @return array
 */
function yg_lead_api_full_phone( array $payload ) {
	$raw = '';
	foreach ( array( 'phone', 'mobile', 'your-phone' ) as $key ) {
		if ( ! empty( $payload[ $key ] ) ) {
			$raw = (string) $payload[ $key ];
			break;
		}
	}
	if ( '' === $raw ) {
		return $payload;
	}

	$national = preg_replace( '/\D/', '', $raw );
	$cc       = isset( $payload['phone-country-code'] ) ? preg_replace( '/\D/', '', (string) $payload['phone-country-code'] ) : '';
	if ( '' === $cc ) {
		$cc = '91';
	}

	$payload['phone_national']     = $national;
	$payload['phone_country_code'] = '+' . $cc;
	$payload['phone']              = '+' . $cc . $national;

	return $payload;
}
