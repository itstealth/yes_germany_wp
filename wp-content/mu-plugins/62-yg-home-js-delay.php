<?php
/**
 * Plugin Name: YG Performance: delay non-form JavaScript
 * Description: Turns LiteSpeed Cache's "Load JS Delayed" on, page by page, and keeps every
 *              script the enquiry forms need out of it.
 *
 *              Pages scored around 26 on mobile with up to 10 seconds of main-thread
 *              blocking, nearly all of it JavaScript that runs before the visitor has done
 *              anything: Elementor, its add-ons, the sliders and the advertising tags.
 *              Delayed, those load at the first scroll, tap or key press instead.
 *
 *              Forms are what make this dangerous. A delayed script is not there for the
 *              first seconds after the first touch, and a form submitted in that window
 *              would skip its own handler. So:
 *              - Contact Form 7, the phone field, the state/branch selects, the validation,
 *                the lead attribution, the submit lock/redirect and the enquiry popup are
 *                excluded and load exactly as before;
 *              - before LiteSpeed optimises a page, its HTML is checked. A page with any
 *                other kind of form (hand-built HTML forms, Elementor forms, loose email or
 *                phone fields, the LMS relay), or a Contact Form 7 form not in the tested
 *                list below, is served the way it was before - not delayed - and remembered
 *                so it is never delayed again;
 *              - thank-you pages are never delayed (their conversion tags wait for the
 *                page's load event, which delayed scripts would miss).
 *
 *              YG_JS_DELAY_SCOPE    'home' = homepage only, 'site' = every eligible page.
 *              YG_JS_DELAY_PUBLIC   false = only for requests carrying ?yg_delaytest=1.
 *              To revert, remove this file and clear the page cache.
 * Version:     2.1.2
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'YG_JS_DELAY_SCOPE' ) ) {
	define( 'YG_JS_DELAY_SCOPE', 'site' );
}
if ( ! defined( 'YG_JS_DELAY_PUBLIC' ) ) {
	define( 'YG_JS_DELAY_PUBLIC', true );
}

/*
 * Also delay Google Tag Manager and the Google Analytics / Ads tags.
 * Measured on the homepage (mobile Lighthouse): 38-54 without, 67-77 with.
 * The cost: those tags count a visit at the visitor's first touch, not at page
 * load, so a visitor who leaves without touching the page is not counted.
 * Set to false to load the Google tags up front again.
 */
if ( ! defined( 'YG_JS_DELAY_GOOGLE_TAGS' ) ) {
	define( 'YG_JS_DELAY_GOOGLE_TAGS', true );
}

/*
 * Contact Form 7 forms tested with delay on: their scripts are all in the
 * exclusion lists below and a fast submit goes through the normal path.
 * 139211 Enquiry Form (popup and in-page), 139425 influencer marketing form,
 * 126871 / 132347 / 139558 German-language course forms (tested 2026-10-14:
 * submitted 0.1 s after the first touch with 60 scripts still waiting, they
 * still went through Contact Form 7's normal path with the same fields).
 */
const YG_JS_DELAY_SAFE_CF7 = array( 139211, 139425, 126871, 132347, 139558 );

/** Option holding paths found unsafe at run time: path => reason. */
const YG_JS_DELAY_BLOCKED_OPTION = 'yg_js_delay_blocked_paths';

/**
 * Path of this request, or '' when it is not an ordinary page view.
 *
 * Decided from the URL because LiteSpeed reads its settings on `init`, before
 * WordPress knows which page was asked for.
 *
 * @return string
 */
function yg_js_delay_path() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return '';
	}
	if ( empty( $_SERVER['REQUEST_URI'] ) || ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) ) {
		return '';
	}
	$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' === $path || 0 === strpos( $path, '/wp-' ) || preg_match( '#\.[a-z0-9]{2,5}$#i', $path ) ) {
		return ''; // wp-json, wp-login, wp-content, sitemap.xml, robots.txt ...
	}
	return $path;
}

/**
 * Whether this request is a test request (?yg_delaytest=1).
 *
 * @return bool
 */
function yg_js_delay_is_test() {
	return isset( $_GET['yg_delaytest'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Whether delay is wanted for this request, before the page is built.
 *
 * @return bool
 */
function yg_js_delay_applies() {
	$path = yg_js_delay_path();
	if ( '' === $path ) {
		return false;
	}
	if ( ! YG_JS_DELAY_PUBLIC && ! yg_js_delay_is_test() ) {
		return false;
	}
	if ( 'site' !== YG_JS_DELAY_SCOPE && '/' !== $path && ! yg_js_delay_is_test() ) {
		return false;
	}
	if ( false !== stripos( $path, 'thank' ) ) {
		return false;
	}
	$blocked = get_option( YG_JS_DELAY_BLOCKED_OPTION, array() );
	return ! ( is_array( $blocked ) && isset( $blocked[ $path ] ) );
}

/**
 * Why this page must not be delayed, or '' when it is safe.
 *
 * @param string $html The page as built, before LiteSpeed optimises it.
 * @return string
 */
function yg_js_delay_unsafe_reason( $html ) {
	// Endpoints that only hand-built lead forms call from their own scripts.
	foreach ( array( 'lms-lead', 'lead-widget', 'lms.yesgermany', 'saveDetails', 'websiteForm' ) as $needle ) {
		if ( false !== stripos( $html, $needle ) ) {
			return 'mentions ' . $needle;
		}
	}

	if ( preg_match_all( '#<form\b[^>]*>#i', $html, $forms ) ) {
		foreach ( $forms[0] as $tag ) {
			if ( false !== stripos( $tag, 'wpcf7-form' ) ) {
				continue;
			}
			$is_get = (bool) preg_match( '#\bmethod\s*=\s*["\']?get#i', $tag ) || ! preg_match( '#\bmethod\s*=#i', $tag );
			if ( $is_get && false === stripos( $tag, 'elementor-form' ) ) {
				continue; // A search box: the browser sends it by itself.
			}
			return 'form ' . substr( preg_replace( '#\s+#', ' ', $tag ), 0, 80 );
		}
	}

	if ( preg_match_all( '#name=["\']_wpcf7["\'][^>]*value=["\'](\d+)["\']#i', $html, $ids ) ) {
		foreach ( array_unique( array_map( 'intval', $ids[1] ) ) as $id ) {
			if ( ! in_array( $id, YG_JS_DELAY_SAFE_CF7, true ) ) {
				return 'cf7 form ' . $id;
			}
		}
	}

	// Lead fields outside any form, wired up by a script of their own.
	$outside = preg_replace( '#<form\b.*?</form>#is', '', $html );
	if ( preg_match( '#<input\b[^>]*\btype\s*=\s*["\']?(tel|email)\b#i', $outside ) || preg_match( '#<textarea\b#i', $outside ) ) {
		return 'lead field outside a form';
	}

	return '';
}

/**
 * Load the page's first two large images straight away.
 *
 * LiteSpeed lazy-loads every image, including the big picture at the top of the
 * page, which Lighthouse counts as the "largest paint": it waited for the lazy
 * loader and took 7 seconds on the city landing pages. The first two images at
 * least 400px wide (Elementor often has a desktop and a mobile copy of the hero)
 * are marked data-no-lazy, which LiteSpeed respects; the first also gets
 * fetchpriority="high".
 *
 * Pages whose hero pictures are listed in plugin 64 are left to it: on the
 * homepage this guess picked a picture hidden on every device and a banner
 * 4,000px down the page.
 *
 * @param string $html Page HTML.
 * @return string
 */
function yg_js_delay_eager_hero( $html ) {
	$body = stripos( $html, '<body' );
	if ( false === $body ) {
		return $html;
	}
	if ( defined( 'YG_ABOVE_FOLD_HERO_IMAGES' ) ) {
		foreach ( YG_ABOVE_FOLD_HERO_IMAGES as $file ) {
			if ( false !== strpos( $html, $file, $body ) ) {
				return $html;
			}
		}
	}
	$count = 0;
	$tail  = preg_replace_callback(
		'#<img\b[^>]*>#i',
		function ( $m ) use ( &$count ) {
			$tag = $m[0];
			if ( $count >= 2 || ! preg_match( '#\swidth=["\']?(\d+)#i', $tag, $w ) || (int) $w[1] < 400 || false !== stripos( $tag, 'data-no-lazy' ) ) {
				return $tag;
			}
			++$count;
			$tag = preg_replace( '#\sloading=["\']?lazy["\']?#i', '', $tag );
			$add = ' data-no-lazy="1"' . ( 1 === $count && false === stripos( $tag, 'fetchpriority' ) ? ' fetchpriority="high"' : '' );
			return preg_replace( '#^<img\b#i', '<img' . $add, $tag );
		},
		substr( $html, $body )
	);
	return null === $tail ? $html : substr( $html, 0, $body ) . $tail;
}

/**
 * Force LiteSpeed's settings for this request.
 */
add_action(
	'init',
	function () {
		if ( ! yg_js_delay_applies() ) {
			return;
		}
		$GLOBALS['yg_js_delay_on'] = true;

		/*
		 * LiteSpeed swaps an image for its .webp copy only when this request says it
		 * accepts WebP. The page cache keeps one copy of a page for everyone, so a bot
		 * that does not say so would store the PNG version for all visitors. Every
		 * browser in use has read WebP since 2020, so always ask for it here.
		 */
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false === strpos( $accept, 'image/webp' ) ) {
			$_SERVER['HTTP_ACCEPT'] = ltrim( $accept . ',image/webp', ',' );
		}

		// Script files the forms need. Kept out of minify too: LiteSpeed rewrites
		// DOMContentLoaded inside every file it minifies while delay is on.
		$form_files = array(
			'yg-form-popup-v2',
			'contact-form-7',
			'branch-selection-for-cf7',
			'country-phone-field',
			'dist/hooks',
			'dist/i18n',
			'yg-lead-capture',
		);

		// Inline scripts the forms need, matched on a word each one contains.
		$form_inline = array(
			'BranchSelectionData',
			'var wpcf7', // CF7's settings. Not plain 'wpcf7': page scripts that only mention it in a comment also start sliders.
			'nbcpf',
			'setLocaleData',
			'countrySelect',
			'yg-submitting',
			'MANAGED',
			'yg_partial_', // Partial-lead draft saver (mu 68): must be listening before the visitor types.
			'sessionStorage',
			'ht_ctc', // WhatsApp button settings: its script is not delayed, so neither are they.
		);

		// Inline scripts the page needs to look right before the first touch.
		$visual_inline = array(
			// Elementor's background lazy-load: its CSS hides every section's background
			// image until this marks the section loaded. Delayed, the homepage's mobile
			// hero stayed transparent and the fixed footer showed through it.
			'lazyloadRunObserver',
		);

		do_action( 'litespeed_conf_force', 'optm-js_exc', array_merge( array( 'jquery.js', 'jquery.min.js' ), $form_files ) );
		do_action(
			'litespeed_conf_force',
			'optm-js_defer_exc',
			array_merge( array( 'jquery.js', 'jquery.min.js' ), YG_JS_DELAY_GOOGLE_TAGS ? array() : array( 'gtm.js', 'analytics.js' ), $form_files, $form_inline, $visual_inline )
		);
		do_action( 'litespeed_conf_force', 'optm-js_defer', 2 );
	},
	1
);

// MonsterInsights marks its tags data-cfasync="false", which LiteSpeed never delays.
add_filter(
	'monsterinsights_tracking_analytics_script_attributes',
	function ( $attributes ) {
		if ( YG_JS_DELAY_GOOGLE_TAGS && yg_js_delay_applies() ) {
			unset( $attributes['data-cfasync'], $attributes['data-wpfc-render'] );
		}
		return $attributes;
	}
);

/*
 * Last check, on the finished page and before LiteSpeed optimises it. If the
 * page turns out to hold a form that delay could break, serve it with no
 * LiteSpeed optimisation at all (every script loads normally), keep this copy
 * out of the page cache, and remember the page so the next request is built
 * the ordinary way again.
 */
add_filter(
	'litespeed_buffer_before',
	function ( $buffer ) {
		if ( empty( $GLOBALS['yg_js_delay_on'] ) || ! is_string( $buffer ) || false === stripos( $buffer, '<html' ) ) {
			return $buffer;
		}
		$reason = yg_js_delay_unsafe_reason( $buffer );
		// Test only: ?yg_delaytest=force skips the check, to try out a form before it is listed as safe.
		if ( '' !== $reason && yg_js_delay_is_test() && 'force' === $_GET['yg_delaytest'] && false === strpos( $reason, 'mentions' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reason = '';
		}
		if ( yg_js_delay_is_test() && ! headers_sent() ) {
			header( 'X-YG-JS-Delay: ' . ( '' === $reason ? 'on' : 'off; ' . substr( preg_replace( '#[^\x20-\x7e]#', '', $reason ), 0, 120 ) ) );
		}
		if ( '' === $reason ) {
			return yg_js_delay_eager_hero( $buffer );
		}

		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) {
			define( 'LITESPEED_NO_OPTM', true );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! yg_js_delay_is_test() ) {
			$blocked = get_option( YG_JS_DELAY_BLOCKED_OPTION, array() );
			$blocked = is_array( $blocked ) ? $blocked : array();
			$path    = yg_js_delay_path();
			if ( '' !== $path && ! isset( $blocked[ $path ] ) && count( $blocked ) < 5000 ) {
				$blocked[ $path ] = substr( $reason, 0, 120 ) . ' @ ' . gmdate( 'Y-m-d H:i' );
				update_option( YG_JS_DELAY_BLOCKED_OPTION, $blocked, true );
			}
		}
		return $buffer;
	},
	5
);
