<?php
/**
 * Plugin Name: YG mh Leads by Email Only
 * Description: Enquiries from mh.yesgermany.com go by email only - client decision 8 Oct 2026. Stops their LMS push (48) and emails every mh lead, Meta ones included. Main-site leads are untouched.
 * Version:     1.0.0
 *
 * Where an mh lead goes now:
 *   - the lead-capture list on this site (unchanged);
 *   - email to YG_MH_MAIL_RECIPIENTS (53) - Google Ads AND Meta leads;
 *   - NOT the CRM: mh's own plugin yg-mh-enquiry 1.3.0 no longer pushes it;
 *   - NOT the LMS: switched off below.
 *
 * Kept as its own file so 48 and 53 stay as they are in main and the whole
 * change is undone by deleting this one file.
 */

defined( 'ABSPATH' ) || exit;

/*
 * 48 only ever forwards mh submissions (yg_lms_is_forwarded_origin), so turning
 * its filter off stops mh and nothing else. The hand-built forms' relay (55)
 * is a separate path and is not affected.
 */
add_filter( 'yg_lms_should_push', '__return_false', 50 );

/*
 * 53 skips mail for Meta leads (30 Sep decision). With CRM and LMS both gone,
 * that would leave Meta leads reaching nobody, so they are emailed too.
 * Only that one case is overridden; any other reason to skip mail stands.
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
