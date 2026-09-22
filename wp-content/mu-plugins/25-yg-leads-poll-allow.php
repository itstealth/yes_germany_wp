<?php
/**
 * Plugin Name: YG Leads - allow the dashboard live poll on production
 * Description: Narrow exception to the production read-only lock, so the Leads
 *              dashboard's live counter works. Nothing else is unlocked.
 *
 * Why this exists
 * ---------------
 * 20-yg-production-readonly.php denies `manage_options` in the admin unless the
 * request is for a screen named in YG_ALLOW_ADMIN_PAGES, which it matches on
 * $_GET['page']. Loading admin.php?page=yg-leads carries that parameter, so the
 * dashboard renders. Its live poll does not: it POSTs to admin-ajax.php with
 * action/nonce/tab/filters/paged and no `page`, so the allowlist cannot match
 * and the capability check fails. YG_LC_Admin::ajax_poll() then returns 403 and
 * the "updated HH:MM:SS" timestamp never appears.
 *
 * This runs at priority 20, after the read-only filter at priority 10, and
 * re-grants `manage_options` for that one AJAX action only. It returns the
 * primitive capability rather than 'exist', so the user must genuinely hold
 * manage_options - an editor or subscriber is still refused. ajax_poll() also
 * re-checks the capability and verifies its nonce, and only ever reads
 * (COUNT, MAX(id), and rendered rows). No write path is opened.
 *
 * Remove this file to revert.
 *
 * @package yg-leads
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'map_meta_cap',
	function ( $caps, $cap ) {
		if ( 'manage_options' !== $cap ) {
			return $caps;
		}
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return $caps;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ajax_poll() verifies the nonce itself.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'yg_lc_poll' !== $action ) {
			return $caps;
		}

		return array( 'manage_options' );
	},
	20,
	2
);
