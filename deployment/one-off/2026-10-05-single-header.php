<?php
/*
 * One-off content change: one shared header for the whole site.
 * Run on STAGING, then publish to live. Never run it on live directly.
 *
 * The site header is ElementsKit template 61435 (menu `mobile-menud`). Pages on
 * the Canvas template get no theme header, so 24 of them (the homepage, 17 city
 * pages, 6 test/clone pages) carried their own copy of an older header: a white
 * logo, the 24-link menu `new-home-page-menu` with old About Us URLs, and a ☰
 * icon to #Map. This replaces that copy with a Template widget that renders 61435
 * itself, so the header is edited in one place from now on. Page bodies and the
 * pages' own footers are not touched. Logo-only ad landing pages are left alone
 * on purpose (no menu, so visitors stay on the form).
 *
 * Also removes the "FFT - Fly. Forex. Travel" item from `mobile-menud`:
 * fft.yesgermany.com's certificate expired on 5 Aug 2026 and it answers 503.
 *
 * `new-home-page-menu` is left in place, unused, so a rollback has its menu.
 *
 *   php run-in-wp 2026-10-05-single-header.php            # dry run, changes nothing
 *   php run-in-wp 2026-10-05-single-header.php apply      # writes
 *
 * Backups taken first (2026-10-05 09:02 UTC), JSON + SQL of every post touched
 * here and both menus: live ~/_yg_backups/header-unify-20261005-090204,
 * staging ~/backups/header-unify-20261005-090204, and a local copy.
 * Restore a page: put its _elementor_data back from the backup and delete its
 * _elementor_css / _elementor_element_cache.
 */

$apply     = in_array( 'apply', (array) ( $args ?? array() ), true );
$HEADER_ID = 61435;
$OLD_MENU  = 'new-home-page-menu';
$FFT_ITEM  = 21565; // `mobile-menud` → SERVICES → FFT - Fly. Forex. Travel
$PAGES     = array( 4748, 12721, 18478, 21649, 94141, 103843, 121211, 122642, 122703, 123140, 125297, 133799, 133817, 133845, 133918, 133953, 133997, 134125, 134130, 134154, 134166, 134828, 134827, 134826 );
// Top-level blocks that only style the old header (homepage).
$DROP_IDS  = array( 'yghomehdrcss' );

if ( 'publish' !== get_post_status( $HEADER_ID ) ) {
	fwrite( STDERR, "header template $HEADER_ID is not published; stopping\n" );
	return;
}

$shared_header = array(
	'id'       => 'ygsharedhdr',
	'elType'   => 'container',
	'isInner'  => false,
	'settings' => array(
		'content_width' => 'full',
		'flex_gap'      => array( 'unit' => 'px', 'size' => 0, 'column' => '0', 'row' => '0' ),
		'padding'       => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
		'margin'        => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
		// Keeps the menu's dropdowns above the page's hero (the homepage hero sits at
		// z-index 2). Set as a plain property, not the container's z_index control:
		// that one is the --z-index variable, which every container inside the header
		// inherits, and it lifted the header's gold rule over the mobile menu panel.
		'custom_css'    => "selector{z-index:10;}",
		// 61435 has no background of its own (pages show white through it). The
		// homepage keeps its branches map in a fixed, z-index 0 panel behind the
		// page; without an opaque header it showed through at 1366x768. The old
		// pasted header was opaque yellow, which is why that never showed before.
		'background_background' => 'classic',
		'background_color'      => '#FFFFFF',
		'_title'        => 'Site header (renders ElementsKit template 61435)',
	),
	'elements' => array(
		array(
			'id'         => 'ygsharedhdrw',
			'elType'     => 'widget',
			'widgetType' => 'template',
			'settings'   => array( 'template_id' => (string) $HEADER_ID ),
			'elements'   => array(),
		),
	),
);

$uses_old_menu = function ( $el ) use ( $OLD_MENU ) {
	return false !== strpos( wp_json_encode( $el ), '"elementskit_nav_menu":"' . $OLD_MENU . '"' );
};

$report = array();
foreach ( $PAGES as $id ) {
	$raw  = get_post_meta( $id, '_elementor_data', true );
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || ! $data ) { $report[] = "#$id SKIP: no Elementor data"; continue; }

	if ( 'ygsharedhdr' === ( $data[0]['id'] ?? '' ) ) {
		// Already converted: bring the wrapper up to the current definition.
		if ( $data[0] == $shared_header ) { $report[] = "#$id already done"; continue; }
		$data[0] = $shared_header;
		$report[] = "#$id " . get_post_field( 'post_name', $id ) . ': update shared header wrapper';
		if ( $apply ) {
			update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_element_cache' );
			delete_post_meta( $id, '_elementor_page_assets' );
			clean_post_cache( $id );
		}
		continue;
	}

	// The old header must be the first block, and the only block that uses the old menu.
	$hits = array_keys( array_filter( $data, $uses_old_menu ) );
	if ( array( 0 ) !== $hits ) { $report[] = "#$id SKIP: old menu found in blocks " . implode( ',', $hits ) . ', expected only block 0'; continue; }

	$removed = array( $data[0]['id'] );
	$new     = array( $shared_header );
	foreach ( array_slice( $data, 1 ) as $block ) {
		if ( in_array( $block['id'], $DROP_IDS, true ) ) { $removed[] = $block['id']; continue; }
		$new[] = $block;
	}

	$report[] = sprintf( '#%d %s: replace header block %s%s; blocks %d → %d', $id, get_post_field( 'post_name', $id ), $removed[0], count( $removed ) > 1 ? ', drop ' . implode( ',', array_slice( $removed, 1 ) ) : '', count( $data ), count( $new ) );

	if ( $apply ) {
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $new ) ) );
		delete_post_meta( $id, '_elementor_css' );
		delete_post_meta( $id, '_elementor_element_cache' );
		delete_post_meta( $id, '_elementor_page_assets' );
		// Bump post_modified directly: wp_update_post() from the CLI runs post_content
		// through kses (no logged-in user) and could strip the page's HTML.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}
}

// FFT menu item
$fft = get_post( $FFT_ITEM );
if ( $fft && 'nav_menu_item' === $fft->post_type && false !== stripos( (string) get_post_meta( $FFT_ITEM, '_menu_item_url', true ), 'fft.yesgermany.com' ) ) {
	$report[] = "menu item $FFT_ITEM ({$fft->post_title}): remove from mobile-menud";
	if ( $apply ) wp_delete_post( $FFT_ITEM, true );
} else {
	$report[] = "menu item $FFT_ITEM: not found or not the FFT link; left alone";
}

if ( $apply && class_exists( '\Elementor\Plugin' ) ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
}

echo implode( "\n", $report ), "\n", $apply ? "APPLIED\n" : "DRY RUN - nothing written\n";
