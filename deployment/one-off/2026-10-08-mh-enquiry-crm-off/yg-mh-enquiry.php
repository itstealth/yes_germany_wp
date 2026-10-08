<?php
/**
 * Plugin Name: YG MH Enquiry Form
 * Description: Shows the main site's enquiry form on mh.yesgermany.com and submits it to the main site, so Maharashtra campaign leads land in the lead capture plugin there. The form is never duplicated here; it is fetched from the main site, so the two cannot drift apart.
 * Version:     1.3.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

define( 'YG_MH_ENQUIRY_VERSION', '1.3.0' );
define( 'YG_MH_ENQUIRY_BRIDGE', 'https://www.yesgermany.com/wp-json/yg/v1/blog-form' );
define( 'YG_MH_ENQUIRY_CACHE', 'yg_mh_enquiry_payload' );

/**
 * The form, branch mapping and asset URLs, as the main site reports them.
 *
 * Cached for an hour so a page view costs no extra request in the normal case.
 * On failure the last good copy is kept and served rather than showing a broken
 * form: these are paid-traffic landing pages, and a stale-but-working form is
 * worth far more than an empty space. Returns null only if the bridge has never
 * answered at all.
 */
function yg_mh_enquiry_payload() {
	$cached = get_transient( YG_MH_ENQUIRY_CACHE );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$res = wp_remote_get( YG_MH_ENQUIRY_BRIDGE, array( 'timeout' => 8 ) );

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return get_option( YG_MH_ENQUIRY_CACHE . '_last', null );
	}

	$data = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( ! is_array( $data ) || empty( $data['html'] ) || empty( $data['endpoint'] ) ) {
		return get_option( YG_MH_ENQUIRY_CACHE . '_last', null );
	}

	set_transient( YG_MH_ENQUIRY_CACHE, $data, HOUR_IN_SECONDS );
	update_option( YG_MH_ENQUIRY_CACHE . '_last', $data, false );

	return $data;
}

/**
 * Render the form.
 *
 * Attributes, because the two landing pages differ in exactly these ways and
 * nothing else:
 *
 *   thank_you  where to send the visitor after a successful submission. This is
 *              what fires the PixelYourSite conversion, so each campaign keeps
 *              its own page - / goes to /thank-you/, the Facebook landing page
 *              to /fb-thank-you/. Getting this wrong silently breaks ad
 *              reporting while the form still appears to work.
 *   source     what the lead is labelled in the leads panel when the visitor
 *              arrives with no utm_source. A real utm_source always wins.
 *   heading    the yellow banner above the form. Left empty, the form's own
 *              title is shown instead, styled the same way.
 */
function yg_mh_enquiry_render( $atts = array() ) {
	$payload = yg_mh_enquiry_payload();

	if ( ! $payload ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'thank_you' => home_url( '/thank-you/' ),
			'source'    => 'mh.yesgermany.com',
			'heading'   => '',
		),
		$atts,
		'yg_mh_enquiry'
	);

	wp_enqueue_style( 'yg-mh-enquiry' );
	wp_enqueue_style( 'wpcf7-styles' );
	wp_enqueue_style( 'bscf7-frontend-style' );
	wp_enqueue_style( 'yg-searchable-select' );
	wp_enqueue_script( 'branch-selection-cf7' );
	wp_enqueue_script( 'bscf7-frontend-ui' );
	wp_enqueue_script( 'yg-searchable-select' );
	wp_enqueue_script( 'yg-mh-enquiry' );

	yg_mh_enquiry_rendered( true );

	wp_add_inline_script(
		'branch-selection-cf7',
		'window.BranchSelectionData = ' . wp_json_encode( isset( $payload['branches'] ) ? $payload['branches'] : array() ) . ';',
		'before'
	);

	/* A banner and the form's own <h2> would be the same heading twice, so the
	   wrapper says which one is in use and the stylesheet hides the other. */
	$heading = '';
	$class   = 'yg-mh-enquiry';

	if ( '' !== trim( (string) $atts['heading'] ) ) {
		$heading = '<div class="yg-mh-enquiry-banner">' . esc_html( $atts['heading'] ) . '</div>';
		$class  .= ' has-heading';
	}

	/*
	 * Configuration rides on the wrapper rather than a window global so two
	 * instances on one page cannot overwrite each other's thank-you URL.
	 *
	 * The markup itself is the main site's own, unaltered - layout is done
	 * entirely in our stylesheet. wp_kses is not used: this is our own
	 * first-party markup from a known endpoint, and stripping it would take
	 * CF7's hidden fields with it.
	 */
	return sprintf(
		'<div class="%s" data-endpoint="%s" data-thank-you="%s" data-source="%s" data-crm="%s" data-form-id="%s">%s%s</div>',
		esc_attr( $class ),
		esc_attr( $payload['endpoint'] ),
		esc_url( $atts['thank_you'] ),
		esc_attr( $atts['source'] ),
		esc_attr( yg_mh_enquiry_crm_url( $payload ) ),
		esc_attr( isset( $payload['form_id'] ) ? $payload['form_id'] : '' ),
		$heading,
		$payload['html']
	);
}

add_shortcode( 'yg_mh_enquiry', 'yg_mh_enquiry_render' );

/**
 * Where the form pushes the lead to the CRM from the browser, or '' for no push.
 *
 * Off since 2026-10-08: mh leads are not sent to the CRM (client decision);
 * they are still recorded in the main site's lead list, emailed per
 * mu-plugin 53 and pushed to the LMS per mu-plugin 48. form.js skips the push
 * when data-crm is empty. Return $payload['crm_url'] from the filter to
 * turn it back on.
 */
function yg_mh_enquiry_crm_url( $payload ) {
	return (string) apply_filters( 'yg_mh_enquiry_crm_url', '', $payload );
}


add_action(
	'wp_enqueue_scripts',
	function () {
		$payload = yg_mh_enquiry_payload();
		$assets  = ( $payload && ! empty( $payload['assets'] ) ) ? $payload['assets'] : array();
		$base    = plugin_dir_url( __FILE__ );

		if ( ! empty( $assets['cf7_css'] ) ) {
			wp_register_style( 'wpcf7-styles', $assets['cf7_css'], array(), YG_MH_ENQUIRY_VERSION );
		}
		if ( ! empty( $assets['form_css'] ) ) {
			wp_register_style( 'bscf7-frontend-style', $assets['form_css'], array(), YG_MH_ENQUIRY_VERSION );
		}
		if ( ! empty( $assets['branch_js'] ) ) {
			wp_register_script( 'branch-selection-cf7', $assets['branch_js'], array(), YG_MH_ENQUIRY_VERSION, true );
		}
		if ( ! empty( $assets['ui_js'] ) ) {
			wp_register_script( 'bscf7-frontend-ui', $assets['ui_js'], array(), YG_MH_ENQUIRY_VERSION, true );
		}

		/*
		 * The searchable State / Branch dropdown, as the main site has it.
		 *
		 * This is the popup plugin's file. Its DOM-ready handler upgrades the
		 * selects in every inline CF7 form first, then looks for
		 * #yg-form-popup-overlay and returns when it is absent - which it
		 * always is here - so mh gets the dropdowns and none of the popup. Its
		 * stylesheet is scoped to .yg- classes and that same overlay id, so
		 * nothing else on the page is touched.
		 *
		 * It deliberately leaves the state -> branch mapping to
		 * branch-selection.js, which is already loaded above; doing it twice
		 * would double-bind the change handler.
		 */
		if ( ! empty( $assets['select_css'] ) ) {
			wp_register_style( 'yg-searchable-select', $assets['select_css'], array(), YG_MH_ENQUIRY_VERSION );
		}
		if ( ! empty( $assets['select_js'] ) ) {
			wp_register_script( 'yg-searchable-select', $assets['select_js'], array(), YG_MH_ENQUIRY_VERSION, true );
		}

		/*
		 * Load after the main site's form styles so our overrides win on order
		 * as well as specificity - several of its rules match ours exactly, and
		 * without this the later sheet takes the tie. Deps are filtered to what
		 * actually registered: naming a handle that is missing would drop this
		 * stylesheet entirely.
		 */
		$deps = array();
		foreach ( array( 'wpcf7-styles', 'bscf7-frontend-style', 'yg-searchable-select' ) as $h ) {
			if ( wp_style_is( $h, 'registered' ) ) {
				$deps[] = $h;
			}
		}

		wp_register_style( 'yg-mh-enquiry', $base . 'assets/form.css', $deps, YG_MH_ENQUIRY_VERSION );
		wp_register_script( 'yg-mh-enquiry', $base . 'assets/form.js', array(), YG_MH_ENQUIRY_VERSION, true );
	},
	5
);

/**
 * Keep mh's own Contact Form 7 away from a form that is not its own.
 *
 * CF7's script initialises every `.wpcf7 > form` it finds on the page and binds
 * its own submit handler straight to the element. Ours listens on the document,
 * so CF7's runs first, calls preventDefault() and wins - and it posts form
 * 139211 to mh's REST API, where that form does not exist. The visitor sees an
 * error and no lead is ever created. The CF7 Redirect add-on rides on the same
 * events and goes with it.
 *
 * Dequeued in the footer, which is where CF7 prints these, and only on a page
 * that actually rendered our form. Note this takes CF7 off the whole page, so a
 * page carrying this form must not also carry one of mh's own CF7 forms; the
 * two landing pages do not. Turn it off with
 * add_filter( 'yg_mh_enquiry_release_cf7', '__return_false' ).
 */
function yg_mh_enquiry_rendered( $set = false ) {
	static $rendered = false;

	if ( $set ) {
		$rendered = true;
	}

	return $rendered;
}

add_action(
	'wp_footer',
	function () {
		if ( ! yg_mh_enquiry_rendered() ) {
			return;
		}
		if ( ! apply_filters( 'yg_mh_enquiry_release_cf7', true ) ) {
			return;
		}

		foreach ( array( 'contact-form-7', 'swv', 'wpcf7-redirect-script' ) as $handle ) {
			wp_dequeue_script( $handle );
		}

		/* The main site's copy of this stylesheet is already loaded, from the
		   same CF7 version; mh's is the same file twice. */
		wp_dequeue_style( 'contact-form-7' );
	},
	1
);

/**
 * Let an admin force a refresh after the form is edited on the main site:
 * /?yg-mh-form-refresh=1
 */
add_action(
	'init',
	function () {
		if ( ! isset( $_GET['yg-mh-form-refresh'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_transient( YG_MH_ENQUIRY_CACHE );
	}
);
