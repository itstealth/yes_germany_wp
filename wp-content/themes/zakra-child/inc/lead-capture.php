<?php
/**
 * Lead capture — forwards front-end form submissions to the YES Germany CRM.
 *
 * Carried over from the parent theme's wp_footer hook. Two changes were made
 * deliberately; both are noted inline:
 *
 *   1. Production-only. The original ran everywhere, so a staging copy would
 *      have posted test submissions into the live lead database.
 *   2. Password and login fields are excluded. The original attached to EVERY
 *      form submit event, including wp-login.php, and posted every field to an
 *      external host — which sent admin passwords off-site in plain JSON.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRM endpoint. Overridable via wp-config.php (YG_LEAD_API_URL) so staging can
 * point at a test collector instead of the live CRM.
 */
function yg_lead_api_url() {
	if ( defined( 'YG_LEAD_API_URL' ) && YG_LEAD_API_URL ) {
		return YG_LEAD_API_URL;
	}
	return 'https://yesgermany.org:8443/websiteForm/saveDetails';
}

/**
 * Whether lead forwarding should run.
 *
 * Staging must never write into the live CRM, so this is production-only
 * unless an explicit override endpoint is configured.
 *
 * @return bool
 */
function yg_lead_capture_enabled() {
	if ( is_admin() ) {
		return false;
	}
	if ( defined( 'YG_LEAD_API_URL' ) && YG_LEAD_API_URL ) {
		return true; // Explicit override — safe to run outside production.
	}
	return ! function_exists( 'yg_is_production' ) || yg_is_production();
}

/**
 * Print the capture script.
 */
function yg_lead_capture_script() {
	if ( ! yg_lead_capture_enabled() ) {
		return;
	}
	?>
	<script>
	(function () {
		var API_URL      = <?php echo wp_json_encode( yg_lead_api_url() ); ?>;
		var lastLeadTime = 0;

		/* Seed attribution values once per session. */
		try {
			if ( ! sessionStorage.getItem( 'yg_referral_url' ) ) {
				sessionStorage.setItem( 'yg_referral_url', document.referrer || '' );
			}
			if ( ! sessionStorage.getItem( 'yg_session_active' ) ) {
				sessionStorage.setItem( 'yg_session_active', 'true' );
			}
		} catch ( e ) {}

		/*
		 * Never forward credentials. The listener is global, so it also sees
		 * login and password-reset forms.
		 */
		var SKIP_FIELD = /pass|pwd|password|token|nonce|_wpnonce|captcha|card|cvv|iban/i;

		function isCredentialForm( form ) {
			if ( form.querySelector( 'input[type="password"]' ) ) {
				return true;
			}
			var action = ( form.getAttribute( 'action' ) || '' ).toLowerCase();
			return action.indexOf( 'wp-login' ) > -1 || action.indexOf( 'lostpassword' ) > -1;
		}

		document.addEventListener( 'submit', function ( e ) {
			var form = e.target;
			if ( ! ( form instanceof HTMLFormElement ) ) {
				return;
			}
			if ( isCredentialForm( form ) ) {
				return;
			}

			/* Debounce double submits. */
			var now = Date.now();
			if ( now - lastLeadTime < 1000 ) {
				return;
			}
			lastLeadTime = now;

			if ( form.dataset.leadCaptured === 'true' ) {
				return;
			}
			form.dataset.leadCaptured = 'true';

			var payload = {};
			new FormData( form ).forEach( function ( value, key ) {
				if ( ! SKIP_FIELD.test( key ) ) {
					payload[ key ] = value;
				}
			} );

			payload.tracking_url = window.location.href || '';
			payload.page_title   = document.title || '';
			payload.referrer     = document.referrer || '';
			payload.user_agent   = navigator.userAgent || '';
			payload.timestamp    = new Date().toISOString();

			try {
				payload.yg_referral_url   = sessionStorage.getItem( 'yg_referral_url' ) || '';
				payload.yg_session_active = sessionStorage.getItem( 'yg_session_active' ) || '';
			} catch ( e ) {}

			try {
				var body = JSON.stringify( payload );
				if ( navigator.sendBeacon ) {
					navigator.sendBeacon( API_URL, new Blob( [ body ], { type: 'application/json' } ) );
				} else {
					fetch( API_URL, {
						method:    'POST',
						headers:   { 'Content-Type': 'application/json' },
						body:      body,
						keepalive: true
					} );
				}
			} catch ( err ) {
				/* Never let tracking break the actual submission. */
			}
		}, false );
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'yg_lead_capture_script', 999 );
