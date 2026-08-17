<?php
/**
 * Plugin Name: YG Environment Guard
 * Description: Environment safety rails. On any non-production install this blocks
 *              outbound email, hides the site from search engines, and marks the
 *              admin UI, so a copy of production data can never reach real people.
 * Version:     1.1.0
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

/**
 * Serve uploads from production when they are missing locally.
 *
 * Staging holds no uploads directory (3.5 GB / 64k files on production), so
 * attachment URLs are rewritten to the live CDN/origin for reading only.
 * Nginx also proxies these at the edge; this filter covers URLs generated in
 * PHP, such as srcset entries.
 *
 * Defined in wp-config.php as YG_UPLOADS_PROXY, e.g. https://www.yesgermany.com
 */
if ( defined( 'YG_UPLOADS_PROXY' ) && YG_UPLOADS_PROXY ) {

	add_filter(
		'upload_dir',
		function ( $dirs ) {
			$local = wp_parse_url( $dirs['baseurl'], PHP_URL_PATH );
			if ( $local ) {
				$dirs['baseurl'] = rtrim( YG_UPLOADS_PROXY, '/' ) . $local;
				$dirs['url']     = rtrim( YG_UPLOADS_PROXY, '/' ) . wp_parse_url( $dirs['url'], PHP_URL_PATH );
			}
			return $dirs;
		},
		PHP_INT_MAX
	);
}
