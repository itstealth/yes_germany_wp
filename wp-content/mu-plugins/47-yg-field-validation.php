<?php
/**
 * Plugin Name: YG Field Validation
 * Description: Format rules for the lead fields - phone, name and email - enforced
 *              in the browser so a bad value cannot be submitted, and again on the
 *              server so it cannot be bypassed. The CRM validates nothing at all,
 *              so this is the only thing standing between a typo and a sales call.
 * Version:     1.0.0
 * Author:      Stealth Digital
 *
 * Rules, and why each is drawn where it is
 * ----------------------------------------
 * Measured against all 494 recorded leads on 2026-09-22, so none of these
 * rejects a value a real person has actually submitted.
 *
 * PHONE - the form is NOT India-only. intl-tel-input is configured
 *   onlyCountries: ["in","ae","qa","sa","om","kw","bh","de"] - the Gulf and
 *   Germany, which for a study-abroad consultancy is exactly the high-intent
 *   audience. 488 of 494 leads are +91, but one is +971. A flat "must be +91"
 *   rule would reject every Gulf and German applicant, so the Indian rule is
 *   applied only to Indian numbers:
 *     +91 (or no country code, the India default): exactly 10 digits, first
 *          digit 6-9. This would have caught the 3 bad-prefix and 2 nine-digit
 *          numbers already in the table.
 *     any other dial code: digits only, 7-12 of them.
 *
 * ENGLISH ONLY - every free-text value must be plain English/Latin. A name in
 *   Devanagari, Tamil, Telugu, Arabic or any other script is refused in the
 *   browser before the form can be sent. This is a deliberate client rule, not
 *   a technical limit: the CRM and the sales team work in English, so a name
 *   nobody downstream can read is worse than no lead. It also means an accented
 *   Latin name (Müller) is refused, which is the same rule applied evenly.
 *
 * NAME - A-Z and a-z, spaces, hyphens, apostrophes and full stops. No digits,
 *   no symbols, no non-Latin characters. A surname is NOT required: 98 of 494
 *   real leads are a single word, and demanding two would reject a fifth. A surname is NOT
 *   required: 98 of 494 real leads are a single word, and demanding two would
 *   have rejected a fifth of them.
 *
 * EMAIL - WordPress's own is_email(), plus a dotted TLD, so "a@b" is refused
 *   while every address already on file passes.
 *
 * Scope
 * -----
 * Only the forms in yg_managed_form_ids() - 139211 and 139425. The CRM Leads
 * Tracking forms are deliberately untouched.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * Which rule applies to a field, decided by its name rather than its CF7 type.
 *
 * Type is not reliable here: `phone` is [tel*] on the Enquiry Form but [text*]
 * on the CRM Leads Tracking forms, so keying off the type would silently skip
 * validation on half the site.
 *
 * @param string $name Field name.
 * @return string 'phone' | 'name' | 'email' | ''
 */
function yg_field_rule_for( $name ) {
	if ( preg_match( '/phone|mobile|contact[_-]?number/i', $name ) ) {
		return 'phone';
	}
	if ( preg_match( '/name/i', $name ) && ! preg_match( '/user|file|company|course|form/i', $name ) ) {
		return 'name';
	}
	if ( preg_match( '/e-?mail/i', $name ) ) {
		return 'email';
	}
	return 'text'; // Everything else free-text: English-only still applies.
}

/* CF7 fires a filter per field type, and both required and optional variants. */
foreach ( array( 'text', 'text*', 'tel', 'tel*', 'email', 'email*' ) as $yg_type ) {
	add_filter( 'wpcf7_validate_' . $yg_type, 'yg_validate_field', 20, 2 );
}

/**
 * Server-side check. Authoritative - the browser rules can be bypassed.
 *
 * @param WPCF7_Validation $result Running validation result.
 * @param WPCF7_FormTag    $tag    The field.
 * @return WPCF7_Validation
 */
function yg_validate_field( $result, $tag ) {
	$form = class_exists( 'WPCF7_ContactForm' ) ? WPCF7_ContactForm::get_current() : null;
	if ( ! $form || ! function_exists( 'yg_is_managed_form' ) || ! yg_is_managed_form( $form->id() ) ) {
		return $result; // Not one of ours - leave it exactly as it was.
	}

	$name = is_object( $tag ) ? $tag->name : '';
	$rule = $name ? yg_field_rule_for( $name ) : '';
	if ( ! $rule ) {
		return $result;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- CF7 verifies the submission itself.
	$value = isset( $_POST[ $name ] ) ? trim( (string) wp_unslash( $_POST[ $name ] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$cc = isset( $_POST['phone-country-code'] ) ? trim( (string) wp_unslash( $_POST['phone-country-code'] ) ) : '';

	$problem = yg_field_problem( $name, $value, $cc );
	if ( '' !== $problem ) {
		$result->invalidate( $tag, $problem );
	}

	return $result;
}

/**
 * The rules themselves, with no CF7 and no superglobals in sight.
 *
 * Kept pure so it can be run over the existing leads table and proved not to
 * reject values real people have already submitted - which is how the phone
 * and name rules above were settled.
 *
 * @param string $name         Field name.
 * @param string $value        Submitted value.
 * @param string $country_code Dial code from the phone field, if any.
 * @return string Error message, or '' when the value is acceptable.
 */
function yg_field_problem( $name, $value, $country_code = '' ) {
	$rule  = yg_field_rule_for( $name );
	$value = trim( (string) $value );

	if ( ! $rule || '' === $value ) {
		return ''; // Emptiness is the required check's business, not ours.
	}

	if ( 'phone' === $rule ) {
		$digits = preg_replace( '/\D/', '', $value );
		$cc     = trim( (string) $country_code );

		if ( preg_match( '/[^0-9 +()\-]/', $value ) ) {
			return __( 'A phone number can only contain digits.', 'yes-germany' );
		}
		if ( '' === $cc || '+91' === $cc || '91' === $cc ) {
			if ( ! preg_match( '/^[6-9][0-9]{9}$/', $digits ) ) {
				return __( 'Enter a 10-digit Indian mobile number starting with 6, 7, 8 or 9.', 'yes-germany' );
			}
			return '';
		}
		if ( strlen( $digits ) < 7 || strlen( $digits ) > 12 ) {
			return __( 'Enter a valid mobile number for the country you selected.', 'yes-germany' );
		}
		return '';
	}

	if ( 'name' === $rule ) {
		if ( preg_match( '/\d/', $value ) ) {
			return __( 'A name cannot contain numbers.', 'yes-germany' );
		}
		if ( preg_match( '/[^\x20-\x7E]/', $value ) ) {
			return __( 'Please enter your name in English.', 'yes-germany' );
		}
		if ( ! preg_match( "/^[A-Za-z][A-Za-z .'\-]*$/", $value ) ) {
			return __( 'Please use English letters only - no symbols.', 'yes-germany' );
		}
		if ( strlen( $value ) < 2 ) {
			return __( 'Please enter your full name.', 'yes-germany' );
		}
		return '';
	}

	if ( 'text' === $rule ) {
		/* Any other free-text field: English characters only. */
		if ( preg_match( '/[^\x20-\x7E]/', $value ) ) {
			return __( 'Please fill this field in English.', 'yes-germany' );
		}
		return '';
	}

	if ( 'email' === $rule ) {
		if ( ! is_email( $value ) || ! preg_match( '/@[^@\s]+\.[A-Za-z]{2,}$/', $value ) ) {
			return __( 'Enter a valid email address, for example name@example.com.', 'yes-germany' );
		}
	}

	return '';
}

add_action( 'wp_footer', 'yg_field_validation_script', 997 );

/**
 * The same rules in the browser.
 *
 * setCustomValidity is used rather than a submit handler: an invalid control
 * makes the browser refuse the submit by itself and show the message against
 * the field, so nothing has to intercept the event. That keeps this clear of
 * yg-form-popup-v2, which owns the submit button's disabled state.
 */
function yg_field_validation_script() {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="yg-field-validation">
	(function () {
		var MANAGED = <?php echo wp_json_encode( array_map( 'strval', yg_managed_form_ids() ) ); ?>;

		function managed( form ) {
			if ( ! form || ! form.classList.contains( 'wpcf7-form' ) ) {
				return false;
			}
			var id = form.querySelector( 'input[name="_wpcf7"]' );
			return !! id && MANAGED.indexOf( String( id.value ) ) > -1;
		}

		var RULE_PHONE = /phone|mobile|contact[_-]?number/i;
		var RULE_NAME  = /name/i;
		var NOT_NAME   = /user|file|company|course|form/i;
		var RULE_MAIL  = /e-?mail/i;
		/* English only, by client instruction: a name must be plain Latin
		   letters. Anything outside printable ASCII - Devanagari, Tamil,
		   Arabic, even an accented Müller - is refused before submit. */
		var NAME_OK    = /^[A-Za-z][A-Za-z .'\-]*$/;
		var NON_ASCII  = /[^\x20-\x7E]/;

		/**
		 * The dial code the visitor has actually chosen.
		 *
		 * intl-tel-input runs with separateDialCode:true, so the chosen code is
		 * displayed beside the field and can be read directly. The hidden
		 * phone-country-code input is only filled on submit, so it is useless
		 * while typing - it is the fallback, and India is the last resort.
		 */
		function dialCode( field ) {
			var wrap = field.closest( '.iti' ) || field.parentNode;
			var shown = wrap ? wrap.querySelector( '.iti__selected-dial-code' ) : null;
			if ( shown && shown.textContent ) {
				return shown.textContent.trim();
			}
			var form = field.form;
			var hidden = form ? form.querySelector( '[name="phone-country-code"]' ) : null;
			return ( hidden && hidden.value ) ? hidden.value.trim() : '+91';
		}

		function problem( field ) {
			var v = ( field.value || '' ).trim();
			if ( ! v ) {
				return ''; /* Empty is the required attribute's job. */
			}
			var n = field.name || '';

			if ( RULE_PHONE.test( n ) ) {
				if ( /[^0-9 +()\-]/.test( v ) ) {
					return 'A phone number can only contain digits.';
				}
				var digits = v.replace( /\D/g, '' );
				var cc     = dialCode( field );
				if ( '+91' === cc || '91' === cc ) {
					if ( ! /^[6-9][0-9]{9}$/.test( digits ) ) {
						return 'Enter a 10-digit Indian mobile number starting with 6, 7, 8 or 9.';
					}
				} else if ( digits.length < 7 || digits.length > 12 ) {
					return 'Enter a valid mobile number for the country you selected.';
				}
				return '';
			}

			if ( RULE_NAME.test( n ) && ! NOT_NAME.test( n ) ) {
				if ( /\d/.test( v ) ) {
					return 'A name cannot contain numbers.';
				}
				if ( NON_ASCII.test( v ) ) {
					return 'Please enter your name in English.';
				}
				if ( ! NAME_OK.test( v ) ) {
					return 'Please use English letters only - no symbols.';
				}
				if ( v.length < 2 ) {
					return 'Please enter your full name.';
				}
				return '';
			}

			if ( RULE_MAIL.test( n ) || 'email' === field.type ) {
				if ( ! /^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/.test( v ) ) {
					return 'Enter a valid email address, for example name@example.com.';
				}
				return '';
			}

			/* Any other free-text field: English characters only. */
			if ( NON_ASCII.test( v ) ) {
				return 'Please fill this field in English.';
			}
			return '';
		}

		function check( field ) {
			if ( ! field || ! field.setCustomValidity ) {
				return;
			}
			field.setCustomValidity( problem( field ) );
		}

		/* Validate as the visitor types and when they leave the field, so the
		   message appears where the mistake was made rather than at submit. */
		document.addEventListener( 'input', function ( e ) {
			if ( e.target && managed( e.target.form ) ) {
				check( e.target );
			}
		} );
		document.addEventListener( 'blur', function ( e ) {
			if ( e.target && managed( e.target.form ) ) {
				check( e.target );
			}
		}, true );

		/* And re-check everything on submit, in case a field was never touched
		   - a browser autofill fires no input event. */
		function checkAll() {
			var forms = document.querySelectorAll( 'form.wpcf7-form' );
			for ( var i = 0; i < forms.length; i++ ) {
				if ( ! managed( forms[ i ] ) ) {
					continue;
				}
				var fields = forms[ i ].querySelectorAll( 'input, textarea' );
				for ( var j = 0; j < fields.length; j++ ) {
					check( fields[ j ] );
				}
			}
		}
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', checkAll );
		} else {
			checkAll();
		}
		document.addEventListener( 'wpcf7submit', checkAll );
	})();
	</script>
	<?php
}
