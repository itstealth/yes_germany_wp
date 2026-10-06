<?php
/*
 * One-off content change: the shared site header (ElementsKit template 61435)
 * takes the old homepage header's design - yellow bar, white logo, Poppins/
 * system type, red map icon - with the full `mobile-menud` menu, on every page.
 * Run on STAGING, then publish to live.
 *
 * Requested 2026-10-06: the single header (2026-10-05-single-header.php) used
 * 61435's white design; the client wants the homepage design everywhere, with
 * the updated links. Decisions: no phone bar (as on the old homepage header);
 * the red icon shows on the homepage only, where it scrolls to the branches map.
 *
 * The new content is 2026-10-06-header-61435.json, built from the homepage's
 * old header block (a006f0b, from the 2026-10-05 backup) with fresh ids, the
 * menu switched to mobile-menud, and its z_index moved off the inherited
 * --z-index variable.
 *
 *   php run-in-wp 2026-10-06-header-old-design.php <json>          # dry run
 *   php run-in-wp 2026-10-06-header-old-design.php <json> apply    # writes
 *
 * Backups of the previous 61435 (2026-10-06): live ~/_yg_backups/header-61435-*,
 * staging ~/backups/header-61435-*, and a local copy.
 */
$ID    = 61435;
$json  = $args[0] ?? '';
$apply = in_array( 'apply', (array) $args, true );

$new = json_decode( (string) @file_get_contents( $json ), true );
if ( ! is_array( $new ) || 'yghdrbar' !== ( $new[0]['id'] ?? '' ) ) { fwrite( STDERR, "bad or missing JSON: $json\n" ); return; }
if ( 'elementskit_template' !== get_post_type( $ID ) ) { fwrite( STDERR, "$ID is not the ElementsKit template\n" ); return; }

$cur = json_decode( get_post_meta( $ID, '_elementor_data', true ), true );
echo "61435 now: ", implode( ',', array_column( (array) $cur, 'id' ) ), "\n";
echo "61435 new: ", implode( ',', array_column( $new, 'id' ) ), "\n";
if ( $cur == $new ) { echo "already applied\n"; return; }

if ( $apply ) {
	update_post_meta( $ID, '_elementor_data', wp_slash( wp_json_encode( $new ) ) );
	delete_post_meta( $ID, '_elementor_css' );
	delete_post_meta( $ID, '_elementor_element_cache' );
	delete_post_meta( $ID, '_elementor_page_assets' );
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $ID ) );
	clean_post_cache( $ID );
	if ( class_exists( '\Elementor\Plugin' ) ) \Elementor\Plugin::$instance->files_manager->clear_cache();
	echo "APPLIED\n";
} else {
	echo "DRY RUN - nothing written\n";
}
