<?php
/**
 * Plugin Name: YG Performance: logo, hero pictures and fonts load first
 * Description: Stops the top of the page from appearing in pieces.
 *
 *              Recorded on the homepage (19 Oct 2026, phone on 4G): the logo was an empty
 *              box until about 9 s, the hero pictures popped in last, and the text jumped
 *              from a system font to Poppins at 4-7 s. Causes:
 *              - LiteSpeed lazy-loads every image, the logo and the hero pictures too, so
 *                they waited for its lazy-load script;
 *              - Poppins came from Google through LiteSpeed's font loader, which fetched
 *                every weight of the font before the text settled.
 *              So:
 *              - the logo and the homepage hero pictures are kept out of LiteSpeed's lazy
 *                loading. The hero pictures keep the browser's own loading="lazy", so the
 *                visible one loads at once and the copy hidden on this device (Elementor
 *                has a desktop and a mobile hero) is not downloaded;
 *              - fonts are served from this site (Elementor > Settings > Performance >
 *                "Load Google Fonts Locally", switched on 19 Oct 2026), and the Poppins
 *                files the top of the page needs are preloaded.
 *
 *              1.1.0 (25 Nov 2026) - section backgrounds at the top of the page:
 *              Elementor hides the background picture of every top-level section from
 *              the third one down (the fourth on tall screens) until its lazy-load
 *              script has run, and on phones that script runs late. The homepage's
 *              mobile hero is such a section: Lighthouse (mobile) measured its picture
 *              starting 7.4 s after the page. The first three top-level sections of the
 *              page now carry Elementor's own `e-no-lazyload` class, so their
 *              backgrounds load with the stylesheet. Same pictures, nothing restyled;
 *              a section hidden on the device (display:none) still downloads nothing.
 *              The homepage's mobile hero picture is also preloaded on phones.
 *
 *              1.2.0 (25 Nov 2026) - layout shift: an upload image with no width/height
 *              and no srcset (the homepage's India map in the mobile hero) gave the page
 *              a layout shift of 0.196 when it arrived. Such images now get their file's
 *              own width and height. Without a srcset the browser already draws them at
 *              that size, so nothing moves; the space is just reserved before the
 *              picture loads.
 *              To revert, remove this file and clear the page cache.
 * Version:     1.2.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

/** The site logo: in every page header. Matched on the image URL. */
const YG_ABOVE_FOLD_LOGO = '/logo-3-1.';

/**
 * Homepage hero pictures: desktop (student) and mobile (India map).
 * Plugin 62 skips its own hero guess on pages that have one of these.
 */
const YG_ABOVE_FOLD_HERO_IMAGES = array( '/Study-In-Germany-', '/Map-rcnrlhpqlzuyk' );

/** Poppins weights used at the top of the page (latin, upright). */
const YG_ABOVE_FOLD_FONT_WEIGHTS = array( '400', '700', '900' );

add_filter(
	'litespeed_media_lazy_img_excludes',
	function ( $excludes ) {
		return array_merge( (array) $excludes, array( YG_ABOVE_FOLD_LOGO ), YG_ABOVE_FOLD_HERO_IMAGES );
	}
);

/**
 * Preload the Poppins files Elementor saved locally.
 *
 * Their names carry a hash Elementor picks when it downloads the font, so they
 * are read from its stylesheet rather than written here.
 */
add_action(
	'wp_head',
	function () {
		if ( ! get_option( 'elementor_local_google_fonts' ) ) {
			return;
		}
		$file = wp_upload_dir()['basedir'] . '/elementor/google-fonts/css/poppins.css';
		$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! preg_match_all( '#/\* latin \*/\s*@font-face\s*\{(.*?)\}#s', $css, $faces ) ) {
			return;
		}
		foreach ( $faces[1] as $face ) {
			if ( ! preg_match( '#font-style:\s*normal#', $face )
				|| ! preg_match( '#font-weight:\s*(\d+)#', $face, $weight )
				|| ! in_array( $weight[1], YG_ABOVE_FOLD_FONT_WEIGHTS, true )
				|| ! preg_match( '#url\(([^)]+\.woff2)\)#', $face, $url ) ) {
				continue;
			}
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( trim( $url[1], '\'"' ) ) );
		}
	},
	1
);

/** How many top-level sections, from the top of the page content, keep their background out of lazy loading (1.1.0). */
const YG_ABOVE_FOLD_EAGER_SECTIONS = 3;

/**
 * Add Elementor's `e-no-lazyload` class to the first top-level sections of the
 * page content (1.1.0). Runs on the finished page, before LiteSpeed optimises it.
 *
 * @param string $html Finished page.
 * @return string
 */
function yg_above_fold_eager_sections( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
		return $html;
	}
	// The page's own Elementor document, after the header template.
	if ( ! preg_match( '#data-elementor-type="wp-(?:page|post)"#', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}
	$start = $m[0][1];
	$head  = substr( $html, 0, $start );
	$body  = substr( $html, $start );
	$body  = preg_replace_callback(
		'#(<div class=")([^"]*\be-con\b[^"]*\be-parent\b[^"]*)(")#',
		function ( $c ) {
			return false !== strpos( $c[2], 'e-no-lazyload' ) ? $c[0] : $c[1] . $c[2] . ' e-no-lazyload' . $c[3];
		},
		$body,
		YG_ABOVE_FOLD_EAGER_SECTIONS
	);
	return is_string( $body ) ? $head . $body : $html;
}
add_filter( 'litespeed_buffer_before', 'yg_above_fold_eager_sections', 6 );

/**
 * Homepage, phones: preload the mobile hero's background picture (1.1.0).
 * Only while the homepage stylesheet still uses that picture, so a new hero
 * never gets a stale preload.
 */
add_action(
	'wp_head',
	function () {
		if ( ! is_front_page() ) {
			return;
		}
		$id  = (int) get_option( 'page_on_front' );
		$css = wp_upload_dir()['basedir'] . '/elementor/css/post-' . $id . '.css';
		$src = is_readable( $css ) ? (string) file_get_contents( $css ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pic = '/wp-content/uploads/2025/11/Background-1.png';
		if ( '' === $src || false === strpos( $src, $pic ) || ! is_readable( ABSPATH . ltrim( $pic, '/' ) . '.webp' ) ) {
			return;
		}
		printf( '<link rel="preload" as="image" href="%s" media="(max-width: 767px)" fetchpriority="high">' . "\n", esc_url( home_url( $pic . '.webp' ) ) );
	},
	2
);

/**
 * Give upload images that have no width, height or srcset their file's own
 * size (1.2.0), so the browser reserves the space before the picture loads.
 *
 * @param string $html Finished page.
 * @return string
 */
function yg_above_fold_image_sizes( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
		return $html;
	}
	$uploads = wp_upload_dir();
	$base    = set_url_scheme( $uploads['baseurl'], 'https' );
	$dir     = $uploads['basedir'];
	$done    = 0;
	$out     = preg_replace_callback(
		'#<img\b[^>]*>#i',
		function ( $m ) use ( $base, $dir, &$done ) {
			$tag = $m[0];
			if ( $done >= 200 || preg_match( '#\s(?:width|height|srcset|data-srcset|sizes)=#i', $tag ) || ! preg_match( '#\ssrc="([^"]+)"#i', $tag, $src ) ) {
				return $tag;
			}
			$url = set_url_scheme( html_entity_decode( $src[1] ), 'https' );
			if ( 0 !== strpos( $url, $base . '/' ) ) {
				return $tag;
			}
			$file = $dir . '/' . rawurldecode( strtok( substr( $url, strlen( $base ) + 1 ), '?' ) );
			if ( false !== strpos( $file, '..' ) || ! is_file( $file ) ) {
				return $tag;
			}
			$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
				return $tag;
			}
			$done++;
			return preg_replace( '#^<img\b#i', '<img width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"', $tag );
		},
		$html
	);
	return is_string( $out ) ? $out : $html;
}
add_filter( 'litespeed_buffer_before', 'yg_above_fold_image_sizes', 7 );
