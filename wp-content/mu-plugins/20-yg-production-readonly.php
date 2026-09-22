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
 *   - Plugin and theme updates. Settings ARE locked (YG_ALLOW_LIVE_SETTINGS
 *     lifts that). Locking an admin out of security updates is not a safety
 *     feature, so update screens stay reachable.
 *
 * ESCAPE HATCH: define( 'YG_ALLOW_LIVE_EDITS', true ) in wp-config.php to lift
 * this instantly, without a deploy. wp-config.php is not in version control, so
 * that switch stays in the hands of whoever holds the server.
 *
 * PER-PAGE ESCAPE HATCH: define( 'YG_WRITABLE_POST_IDS', '132003' ) — a comma
 * separated list, or a real array — leaves the whole site locked but makes
 * those specific posts editable. Use this instead of YG_ALLOW_LIVE_EDITS when
 * one page genuinely has to be worked on live: it keeps the blast radius to the
 * pages named, rather than opening every page and every setting at once.
 *
 * The same caveat still applies to anything on that list. A publish from
 * staging replaces these pages like any other, so an unlocked page is for work
 * that is either throwaway or is being copied back to staging afterwards.
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

/**
 * Posts that stay editable while the rest of the site is locked.
 *
 * Read from YG_WRITABLE_POST_IDS in wp-config.php, which accepts either an
 * array or a string of ids in any separator ( '132003, 71549' ). Kept out of
 * version control on purpose, for the same reason as YG_ALLOW_LIVE_EDITS:
 * unlocking a page is an operational decision made by whoever holds the
 * server, and it should not need a deploy to make or to reverse.
 *
 * @return int[]
 */
function yg_readonly_writable_post_ids() {
	$ids = [];

	if ( defined( 'YG_WRITABLE_POST_IDS' ) ) {
		$raw = YG_WRITABLE_POST_IDS;
		$ids = is_array( $raw )
			? $raw
			: preg_split( '/[^0-9]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
	}

	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

	return apply_filters( 'yg_readonly_writable_post_ids', $ids );
}

/**
 * The post this admin request is about, when the capability check itself does
 * not carry one.
 *
 * Several of the blocked capabilities are primitive — edit_published_pages,
 * upload_files — and are checked with no object at all, so $args[0] is empty
 * and there is nothing to compare against the allowlist. Falling back to the
 * post named in the request is what lets an unlocked page be edited end to
 * end: the editor screen, its autosaves, its REST calls, and the media modal
 * opened from inside it.
 *
 * This only ever widens permission for an id that is already on the allowlist.
 * It cannot be used to reach a different post: the meta-cap check for that
 * post arrives with its own $args[0] and is matched on that, not on this.
 *
 * @return int
 */
function yg_readonly_requested_post_id() {
	foreach ( [ 'post', 'post_ID', 'post_id' ] as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST[ $key ] ) && is_numeric( $_REQUEST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( $_REQUEST[ $key ] );
		}
	}

	// Block editor and REST: /wp-json/wp/v2/pages/132003
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_SERVER['REQUEST_URI'] ) ) {
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		if ( preg_match( '#/wp/v2/[a-z0-9_-]+/(\d+)#i', $uri, $m ) ) {
			return absint( $m[1] );
		}
	}

	return 0;
}

/**
 * Whether this capability check concerns a post that has been unlocked.
 *
 * @param array $args The map_meta_cap $args array.
 * @return bool
 */
/**
 * Admin screens that stay reachable while settings are otherwise locked.
 *
 * Read from YG_ALLOW_ADMIN_PAGES in wp-config.php — a comma separated list of
 * `page` slugs, or a real array. These are the slugs in admin.php?page=<slug>,
 * so this opens one plugin's screen rather than the whole settings surface the
 * way YG_ALLOW_LIVE_SETTINGS does.
 *
 * Added for the Leads dashboard: it is a read-and-export view of the site's own
 * lead records, and locking the live site out of its own leads helps nobody.
 * Being on this list is not permission to change site settings — every other
 * manage_options screen stays blocked.
 *
 * @return string[]
 */
function yg_readonly_allowed_admin_pages() {
	$pages = [];

	if ( defined( 'YG_ALLOW_ADMIN_PAGES' ) ) {
		$raw   = YG_ALLOW_ADMIN_PAGES;
		$pages = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
	}

	$pages = array_values( array_filter( array_map( 'trim', (array) $pages ) ) );

	return apply_filters( 'yg_readonly_allowed_admin_pages', $pages );
}

/**
 * Whether this request is for an admin screen that has been opened.
 *
 * @return bool
 */
function yg_readonly_on_allowed_admin_page() {
	$pages = yg_readonly_allowed_admin_pages();
	if ( ! $pages ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	return ( '' !== $page && in_array( $page, $pages, true ) );
}

function yg_readonly_target_is_writable( $args ) {
	$writable = yg_readonly_writable_post_ids();
	if ( ! $writable ) {
		return false;
	}

	$target = ( ! empty( $args[0] ) && is_numeric( $args[0] ) )
		? absint( $args[0] )
		: yg_readonly_requested_post_id();

	return ( $target && in_array( $target, $writable, true ) );
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

			// Pages explicitly unlocked via YG_WRITABLE_POST_IDS. The user's
			// real capabilities apply from here — this hands the check back to
			// WordPress rather than granting anything of its own, so a
			// contributor still cannot publish someone else's page.
			if ( yg_readonly_target_is_writable( $args ) ) {
				return $caps;
			}

			return [ 'do_not_allow' ];
		},
		10,
		4
	);

	// Settings are locked too. The "Discourage search engines" tick box lives
	// behind manage_options, and it being flipped on the live site is exactly
	// the incident this is meant to prevent.
	//
	// Updates are handled by their own capabilities (update_plugins,
	// update_themes, update_core) and are NOT blocked here, so a locked-down
	// site can still be patched.
	//
	// YG_ALLOW_LIVE_SETTINGS lifts just this part, leaving content locked.
	if ( ! ( defined( 'YG_ALLOW_LIVE_SETTINGS' ) && YG_ALLOW_LIVE_SETTINGS ) ) {
		add_filter(
			'map_meta_cap',
			function ( $caps, $cap ) {
				if ( 'manage_options' !== $cap ) {
					return $caps;
				}
				if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
					return $caps;
				}
				// Let the update screens through; they check update_* caps but
				// several admin pages gate on manage_options first.
				global $pagenow;
				$allow = [ 'update-core.php', 'plugins.php', 'themes.php', 'update.php', 'site-health.php' ];
				if ( isset( $pagenow ) && in_array( $pagenow, $allow, true ) ) {
					return $caps;
				}
				// Individual plugin screens opened via YG_ALLOW_ADMIN_PAGES.
				// Matched on the ?page= slug, not on $pagenow: these all live
				// under admin.php, so allowing the file would open every
				// plugin's admin screen at once.
				if ( yg_readonly_on_allowed_admin_page() ) {
					return $caps;
				}
				return [ 'do_not_allow' ];
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
			// On an unlocked page, the warning above would be actively wrong.
			// Say what is true here instead, including the part people forget.
			if ( yg_readonly_target_is_writable( [] ) ) {
				printf(
					'<div class="notice notice-success"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'This page is unlocked for editing on the live site.', 'yesgermany' ),
					esc_html__( 'The rest of the site is still read-only. Anything you change here is replaced the next time staging publishes, so copy the work back to staging when you are done.', 'yesgermany' )
				);
				return;
			}

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
			$unlocked = yg_readonly_writable_post_ids();
			$title    = '● LIVE — read-only';
			$hint     = 'Content is authored on staging and published from there.';

			if ( $unlocked ) {
				$title = sprintf(
					'● LIVE — read-only (%d page%s unlocked)',
					count( $unlocked ),
					( 1 === count( $unlocked ) ) ? '' : 's'
				);
				$hint = 'Unlocked: ' . implode( ', ', $unlocked ) . '. Everything else is read-only.';
			}

			$bar->add_node(
				[
					'id'    => 'yg-readonly',
					'title' => $title,
					'href'  => false,
					'meta'  => [ 'title' => $hint ],
				]
			);
		},
		100
	);
}
