<?php
/**
 * Plugin Name: YG mh Leads: CRM + Email
 * Description: Enquiries from mh.yesgermany.com go to the CRM (and from there the LMS) the same way main-site leads do, and are still emailed to the Maharashtra team, Meta leads included. Main-site leads are untouched.
 * Version:     2.0.0
 *
 * Where an mh lead goes:
 *   - the lead-capture list on this site (unchanged);
 *   - the CRM, sent from this server by mu-plugin 45 (filter below). The
 *     client's CRM-to-LMS sync then creates the LMS lead, exactly as for a
 *     www lead;
 *   - email to YG_MH_MAIL_RECIPIENTS (53) - Google Ads AND Meta leads.
 *
 * 2.0.0 - (2026-11-17) CRM + LMS back on, email kept (client request).
 *   The file name is kept so the hot-applied copy replaces 1.0.0 in place.
 * 1.0.0 - (2026-10-08) email only (client decision): CRM and LMS off.
 *
 * Not sent from the browser: mh's own plugin (yg-mh-enquiry) keeps its CRM
 * URL empty, so each lead is sent once, by the server.
 */

defined( 'ABSPATH' ) || exit;

/* 45: send mh submissions to the CRM like any other lead. */
add_filter( 'yg_lead_api_push_mh', '__return_true' );

/*
 * 48's direct LMS push stays off. The CRM sync already creates the LMS lead
 * (that is how every www lead gets there); pushing to the LMS as well would
 * add each person a second time as an "interaction". 48 only ever forwards
 * mh submissions, so this stops nothing else.
 */
add_filter( 'yg_lms_should_push', '__return_false', 50 );

/**
 * One posted value from the current CF7 submission.
 *
 * @param string $key Field name.
 * @return string
 */
function yg_mh_leads_posted( $key ) {
	$sub = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
	if ( ! $sub ) {
		return '';
	}
	$v = $sub->get_posted_data( $key );
	if ( is_array( $v ) ) {
		$v = reset( $v );
	}
	return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
}

/*
 * 45 builds the payload as for a www page: page URL = the submission URL
 * (here www's form endpoint, /wp-json/...), referrer and first-touch UTMs from
 * www's own cookie. For an mh lead the truth is in the fields mh's form posts
 * (yg_page_url, yg_first_landing, yg_first_referrer, utm_*, yg_gclid,
 * yg_gad_campaignid, yg_fbclid), so those win - the same values mh's own
 * browser push sent before 8 Oct.
 */
add_filter(
	'yg_lead_api_payload',
	function ( $payload, $form_id ) {
		if ( ! is_array( $payload ) || ! function_exists( 'yg_mh_mail_is_mh_submission' ) || ! yg_mh_mail_is_mh_submission() ) {
			return $payload;
		}

		$page = esc_url_raw( yg_mh_leads_posted( 'yg_page_url' ) );
		if ( '' !== $page ) {
			$payload['tracking_url'] = $page;
		}
		$payload['page_title']      = 'mh.yesgermany.com';
		$payload['referrer']        = esc_url_raw( yg_mh_leads_posted( 'yg_first_referrer' ) );
		$payload['yg_referral_url'] = $payload['referrer'];
		$payload['yg_landing_page'] = esc_url_raw( yg_mh_leads_posted( 'yg_first_landing' ) );

		/* UTMs exactly as mh posted them, never www's cookie. */
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $k ) {
			$payload[ $k ] = yg_mh_leads_posted( $k );
		}

		/* An ad click without UTM tags is credited the way 45 credits www ones. */
		if ( function_exists( 'yg_lead_api_utm_from_click' ) ) {
			$payload = yg_lead_api_utm_from_click(
				$payload,
				array(
					array(
						'gclid'          => yg_mh_leads_posted( 'yg_gclid' ),
						'gad_campaignid' => yg_mh_leads_posted( 'yg_gad_campaignid' ),
						'fbclid'         => yg_mh_leads_posted( 'yg_fbclid' ),
					),
				)
			);
		}
		return $payload;
	},
	20,
	2
);

/*
 * 53 skips mail for Meta leads (30 Sep decision). mh leads are emailed
 * whatever their source, so that one case is overridden; any other reason
 * to skip mail stands.
 */
add_filter(
	'wpcf7_skip_mail',
	function ( $skip, $contact_form ) {
		if ( ! function_exists( 'yg_mh_mail_is_mh_submission' ) || ! function_exists( 'yg_mh_mail_is_meta_lead' ) ) {
			return $skip;
		}
		if ( ! $contact_form instanceof WPCF7_ContactForm || (int) $contact_form->id() !== yg_mh_mail_form_id() ) {
			return $skip;
		}
		if ( yg_mh_mail_is_mh_submission() && yg_mh_mail_is_meta_lead() ) {
			return false;
		}
		return $skip;
	},
	30,
	2
);

/*
 * 53 labels a lead "Facebook" only when its source contains "facebook"; real
 * Meta leads mostly arrive as "fb" or "ig" and would be mislabelled Google Ads.
 */
add_filter(
	'wpcf7_mail_components',
	function ( $components, $contact_form, $mail ) {
		if ( ! function_exists( 'yg_mh_mail_is_mh_submission' ) || ! function_exists( 'yg_mh_mail_is_meta_lead' ) ) {
			return $components;
		}
		if ( ! $contact_form instanceof WPCF7_ContactForm || (int) $contact_form->id() !== yg_mh_mail_form_id() ) {
			return $components;
		}
		if ( is_object( $mail ) && method_exists( $mail, 'name' ) && 'mail' !== $mail->name() ) {
			return $components;
		}
		if ( yg_mh_mail_is_mh_submission() && yg_mh_mail_is_meta_lead() ) {
			$components['subject'] = 'From MH YES Germany Facebook';
		}
		return $components;
	},
	30,
	3
);
