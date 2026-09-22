<?php
/**
 * Plugin Name: YG CF7 Required Fields
 * Description: Makes Contact Form 7's required fields actually stop a submission.
 *              CF7 marks them aria-required but never sets the HTML5 required
 *              attribute, so the browser submits anyway and the form only fails
 *              after a server round trip. Mirroring the attribute means an
 *              incomplete form cannot be sent at all - no request to WordPress,
 *              and nothing for the CRM to receive.
 * Version:     1.0.0
 * Author:      Stealth Digital
 *
 * Elementor Pro needs none of this: it already renders required="required" on
 * its required fields, so those forms are blocked by the browser today.
 *
 * Scope
 * -----
 * Two limits. Only the forms in yg_managed_form_ids() - the two enquiry forms -
 * are touched at all; every other form on the site behaves exactly as it did.
 * And within those, only controls CF7 itself marked `aria-required="true"`,
 * so this cannot invent a rule the form does not already have.
 *
 * Fields forced to required
 * -------------------------
 * `nearest_branch` on the Enquiry Form was optional, and the result was that
 * **0 of 450 leads carried a branch** - the column was worthless. It is made
 * required here rather than by editing the form, for two reasons: a form is
 * content, so an edit on live is replaced by the next content push from
 * staging, and doing it in code puts the change in git, through CI, and lets it
 * be reverted by deleting one file.
 *
 * Safe because every one of the 29 states in both forms has at least one branch
 * mapped in `branch_selection_cf7_data` (checked 2026-09-22), and both carry the
 * `id:State` / `id:your_branch` ids the branch plugin keys off. If a state with
 * no branches is ever added, yg-form-popup-v2 hides AND disables the field for
 * it - and a disabled required field can never be filled, so that state could
 * not submit at all. Re-check this before adding a state, and before adding a
 * form to the list below.
 *
 * Note `branchIsRequired()` in yg-form-popup-v2 is hardcoded to return false,
 * so that plugin's own branch gating never fires. This replaces it properly.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only forms this project touches.
 *
 * Scoped deliberately. The CRM Leads Tracking forms (132347, 126871, 139558)
 * and the multistep test form (134415) are left exactly as they were - no
 * forced fields, no required mirroring, no format rules - because the client
 * asked for the work to stop at the two enquiry forms. Their submissions still
 * reach the CRM as before; nothing about them changes.
 *
 * @return int[]
 */
function yg_managed_form_ids() {
	return apply_filters( 'yg_managed_form_ids', array( 139211, 139425 ) );
}

/**
 * Whether a form is one this project manages.
 *
 * @param int $form_id CF7 form ID.
 * @return bool
 */
function yg_is_managed_form( $form_id ) {
	return in_array( (int) $form_id, yg_managed_form_ids(), true );
}

/**
 * Fields that must be required regardless of how the form is written.
 *
 * Keyed by CF7 form ID; values are field names.
 *
 * @return array
 */
function yg_cf7_forced_required() {
	return apply_filters(
		'yg_cf7_forced_required',
		array(
			139211 => array( 'nearest_branch' ), // Enquiry Form.
			139425 => array( 'nearest_branch' ), // Influencer marketing form - same build.
		)
	);
}

add_filter( 'wpcf7_form_tag', 'yg_cf7_force_required_tag', 10, 1 );

/**
 * Mark a listed field required as the form is built.
 *
 * CF7 decides required-ness from the tag's type ending in `*`, and everything
 * downstream follows from that: the server-side validator, and the
 * `aria-required` attribute in the markup - which the script below then mirrors
 * onto the real `required` attribute. So this one change enforces the field on
 * both sides.
 *
 * @param WPCF7_FormTag|array $tag The form tag.
 * @return WPCF7_FormTag|array
 */
function yg_cf7_force_required_tag( $tag ) {
	$form = class_exists( 'WPCF7_ContactForm' ) ? WPCF7_ContactForm::get_current() : null;
	if ( ! $form ) {
		return $tag;
	}

	$forced = yg_cf7_forced_required();
	$id     = (int) $form->id();
	if ( empty( $forced[ $id ] ) ) {
		return $tag;
	}

	$name = is_object( $tag ) ? $tag->name : ( isset( $tag['name'] ) ? $tag['name'] : '' );
	$type = is_object( $tag ) ? $tag->type : ( isset( $tag['type'] ) ? $tag['type'] : '' );

	if ( ! in_array( $name, $forced[ $id ], true ) || '*' === substr( $type, -1 ) ) {
		return $tag;
	}

	if ( is_object( $tag ) ) {
		$tag->type = $type . '*';
	} else {
		$tag['type'] = $type . '*';
	}

	/*
	 * A select also needs `first_as_label`, or making it required achieves
	 * nothing.
	 *
	 * Without it CF7 renders the placeholder as a real option whose value is
	 * its own text - value="-- Select Your Branch --". That is a non-empty
	 * value, so the browser and CF7 both consider the field filled, and the
	 * literal string "-- Select Your Branch --" would be sent to the CRM as the
	 * branch name. `state` already carries the option, which is why its
	 * required rule works and the branch's did not.
	 */
	if ( 0 === strpos( $type, 'select' ) ) {
		if ( is_object( $tag ) ) {
			$opts = (array) $tag->options;
			if ( ! in_array( 'first_as_label', $opts, true ) ) {
				$opts[]       = 'first_as_label';
				$tag->options = $opts;
			}
		} elseif ( isset( $tag['options'] ) && is_array( $tag['options'] )
			&& ! in_array( 'first_as_label', $tag['options'], true ) ) {
			$tag['options'][] = 'first_as_label';
		}
	}

	return $tag;
}

add_action( 'wp_footer', 'yg_cf7_required_fields_script', 998 );

/**
 * Print the attribute mirror.
 */
function yg_cf7_required_fields_script() {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="yg-cf7-required-fields">
	(function () {
		var MANAGED = <?php echo wp_json_encode( array_map( 'strval', yg_managed_form_ids() ) ); ?>;

		/* Which CF7 form this is - the id rides in a hidden _wpcf7 input. */
		function managed( form ) {
			var id = form.querySelector( 'input[name="_wpcf7"]' );
			return !! id && MANAGED.indexOf( String( id.value ) ) > -1;
		}

		/**
		 * Mirror aria-required onto the real attribute for one form.
		 *
		 * Disabled controls are skipped: a disabled field is not submitted, and
		 * marking it required would make the form impossible to send. That is
		 * exactly how the branch select behaves when the chosen state has no
		 * branches mapped to it.
		 *
		 * Hidden inputs are skipped for the same reason - the browser refuses
		 * to report a validation message on a control it cannot focus, which
		 * would block the form with no visible explanation.
		 */
		function sync( form ) {
			var fields = form.querySelectorAll( '[aria-required="true"]' );
			for ( var i = 0; i < fields.length; i++ ) {
				var field = fields[ i ];
				if ( field.disabled || 'hidden' === field.type ) {
					field.removeAttribute( 'required' );
				} else {
					field.setAttribute( 'required', 'required' );
				}
			}
		}

		function syncAll() {
			var forms = document.querySelectorAll( 'form.wpcf7-form' );
			for ( var i = 0; i < forms.length; i++ ) {
				if ( managed( forms[ i ] ) ) {
					sync( forms[ i ] );
				}
			}
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', syncAll );
		} else {
			syncAll();
		}

		/* CF7 re-renders its controls after every outcome, and the branch field
		   is enabled and disabled as the state changes, so re-apply on both. */
		document.addEventListener( 'wpcf7submit', syncAll );
		document.addEventListener( 'wpcf7invalid', syncAll );
		document.addEventListener( 'wpcf7reset', syncAll );
		/*
		 * Re-apply after the change, not during it.
		 *
		 * yg-form-popup-v2's populateBranches() rebuilds the branch options on
		 * every state change and ends with branchField.removeAttribute(
		 * 'required' ) - it was written when branch was optional. Running on a
		 * later tick means this always lands after that, whatever order the
		 * listeners were bound in.
		 */
		document.addEventListener( 'change', function ( e ) {
			var form = e.target && e.target.form;
			if ( form && form.classList.contains( 'wpcf7-form' ) && managed( form ) ) {
				sync( form );
				setTimeout( function () {
					sync( form );
				}, 0 );
			}
		} );
	})();
	</script>
	<?php
}
