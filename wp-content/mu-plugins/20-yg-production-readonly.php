<?php
/**
 * Plugin Name: YES Germany — production content is read-only
 * Description: Blocks content editing in wp-admin on production. Staging is the
 *              only place content is authored; a publish makes production match
 *              it, so anything typed into the live site is overwritten at the
 *              next publish anyway. This stops the work being done in the first
 *              place rather than letting someone spend an afternoon on it.
 * Author:      Stealth Digital
 *
 * WHAT IS STILL ALLOWED, deliberately:
 *
 *   - Job applications, comments and form entries. Those are created by real
 *     visitors on the front end and are the client's actual leads. Blocking
 *     them would be far worse than anything this file prevents.
 *   - Everything outside wp-admin. The filter only applies to admin-side and
 *     REST/AJAX editor requests, never front-end writes.
 *   - Plugin and theme updates, and settings, unless YG_READONLY_SETTINGS is
 *     set. Locking an admin out of security updates is not a safety feature.
 *
 * ESCAPE HATCH: define( 'YG_ALLOW_LIVE_EDITS', true ) in wp-config.php to lift
 * this instantly, without a deploy. wp-config.php is not in version control, so
 * that switch stays in the hands of whoever holds the server.
 *
 * @package YesGermany
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the lock applies to this request.
 *
 * @return bool
 */
function yg_readonly_active() {
	// YG_READONLY_TEST lets staging exercise this file; production keys off the
	// environment as normal.
	if ( defined( 'YG_READONLY_TEST' ) && YG_READONLY_TEST ) {
		return true;
	}
	if ( defined( 'YG_ALLOW_LIVE_EDITS' ) && YG_ALLOW_LIVE_EDITS ) {
		return false;
	}
	// yg_is_production() comes from 00-yg-environment.php, which loads first.
	return function_exists( 'yg_is_production' ) ? yg_is_production() : false;
}

/**
 * Post types that must stay writable on production.
 *
 * These are created by visitors, not editors. awsm_job_application in
 * particular is the client's lead flow — four of them were lost once already,
 * and that must not be repeatable from here.
 *
 * @return string[]
 */
function yg_readonly_exempt_post_types() {
	return apply_filters(
		'yg_readonly_exempt_post_types',
		[ 'awsm_job_application', 'wpforms_log', 'flamingo_inbound' ]
	);
}

if ( yg_readonly_active() ) {

	/**
	 * Deny content-editing capabilities in the admin.
	 *
	 * map_meta_cap is used rather than user_has_cap because it receives the
	 * object being acted on, so a job application can be exempted while a page
	 * is blocked. A blanket capability filter cannot tell those apart.
	 */
	add_filter(
		'map_meta_cap',
		function ( $caps, $cap, $user_id, $args ) {
			// Front-end writes are never touched. Only the editing surfaces.
			if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return $caps;
			}

			$blocked = [
				'edit_post',
				'delete_post',
				'publish_post',
				'edit_page',
				'delete_page',
				'publish_pages',
				'edit_published_posts',
				'edit_published_pages',
				'delete_published_posts',
				'delete_published_pages',
				'upload_files',
			];

			if ( ! in_array( $cap, $blocked, true ) ) {
				return $caps;
			}

			// Exempt visitor-generated content.
			if ( ! empty( $args[0] ) ) {
				$post = get_post( $args[0] );
				if ( $post && in_array( $post->post_type, yg_readonly_exempt_post_types(), true ) ) {
					return $caps;
				}
			}

			return [ 'do_not_allow' ];
		},
		10,
		4
	);

	// Settings are locked only when asked for. The "Discourage search engines"
	// tick box lives here, which is worth remembering — but so does every
	// plugin's configuration.
	if ( defined( 'YG_READONLY_SETTINGS' ) && YG_READONLY_SETTINGS ) {
		add_filter(
			'map_meta_cap',
			function ( $caps, $cap ) {
				return ( 'manage_options' === $cap && is_admin() ) ? [ 'do_not_allow' ] : $caps;
			},
			10,
			2
		);
	}

	/**
	 * Say why, in the place where someone is about to be confused.
	 */
	add_action(
		'admin_notices',
		function () {
			$staging = defined( 'YG_STAGING_URL' ) ? YG_STAGING_URL : 'https://yg-staging.stealthlearn.in';
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s" target="_blank" rel="noopener">%s</a>%s</p></div>',
				esc_html__( 'This is the live site — content here is read-only.', 'yesgermany' ),
				esc_html__( 'Edits made here are replaced the next time staging publishes. Make the change on', 'yesgermany' ),
				esc_url( $staging ),
				esc_html__( 'staging', 'yesgermany' ),
				esc_html__( ' and publish it from there.', 'yesgermany' )
			);
		}
	);

	// Make the state obvious at a glance rather than only on failure.
	add_action(
		'admin_bar_menu',
		function ( $bar ) {
			$bar->add_node(
				[
					'id'    => 'yg-readonly',
					'title' => '● LIVE — read-only',
					'href'  => false,
					'meta'  => [ 'title' => 'Content is authored on staging and published from there.' ],
				]
			);
		},
		100
	);
}
