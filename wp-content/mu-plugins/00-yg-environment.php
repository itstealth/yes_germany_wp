<?php
/**
 * Plugin Name: YG Environment Guard
 * Description: Environment safety rails. On any non-production install this blocks
 *              outbound email, hides the site from search engines, and marks the
 *              admin UI, so a copy of production data can never reach real people.
 * Version:     1.2.0
 * Author:      Stealth Digital
 *
 * Loaded as a must-use plugin so it cannot be deactivated from wp-admin, and
 * numbered 00- so it loads before anything that might depend on yg_is_production().
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current environment.
 *
 * WP_ENVIRONMENT_TYPE is set in wp-config.php, which is never in version control.
 * Anything not explicitly "production" is treated as non-production, so a missing
 * or misspelled value fails safe rather than enabling live behaviour.
 *
 * @return string
 */
function yg_env() {
	if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE ) {
		return WP_ENVIRONMENT_TYPE;
	}
	return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
}

/**
 * Whether this install is production.
 *
 * @return bool
 */
function yg_is_production() {
	return 'production' === yg_env();
}

if ( yg_is_production() ) {
	return;
}

/*
 * ---------------------------------------------------------------------------
 * Non-production rails
 * ---------------------------------------------------------------------------
 */

/**
 * Hard-block outbound email.
 *
 * Staging carries a copy of the production users table. Without this, any cron
 * job or plugin could email real students from a test environment.
 */
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

/**
 * Keep staging out of search results.
 */
add_filter( 'pre_option_blog_public', '__return_zero' );

/**
 * Belt-and-braces noindex header in case blog_public is overridden elsewhere.
 */
add_action(
	'send_headers',
	function () {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}
);

/**
 * Make the environment unmistakable in wp-admin, so nobody edits staging
 * believing it is the live site.
 */
add_action(
	'admin_notices',
	function () {
		printf(
			'<div class="notice notice-warning"><p><strong>%s environment.</strong> %s</p></div>',
			esc_html( strtoupper( yg_env() ) ),
			esc_html__( 'Outbound email is disabled, search engines are blocked, and CRM lead forwarding is off. Changes here do not affect the live site.', 'yes-germany' )
		);
	}
);

add_action(
	'admin_bar_menu',
	function ( $bar ) {
		$bar->add_node(
			array(
				'id'    => 'yg-env',
				'title' => '&#9679; ' . strtoupper( yg_env() ),
				'meta'  => array( 'title' => 'Current environment' ),
			)
		);
	},
	100
);

/*
 * Upload URLs are deliberately NOT rewritten to production.
 *
 * An upload_dir filter here used to point every upload URL at
 * www.yesgermany.com, including files uploaded on staging that exist only on
 * staging's disk. Those rendered as broken images on staging, and the wrong URL
 * was saved into Elementor data and attachment rows. The /expert-team/ page lost
 * two images this way on 2026-09-24.
 *
 * Nothing is lost by leaving URLs on the staging domain: staging nginx serves a
 * file locally when it has it and reads through to production when it does not
 * (docker/nginx/default.conf), and deployment/push-fast.sh rewrites staging URLs
 * to production ones and copies the missing files on publish.
 */
