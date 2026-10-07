<?php
/*
 * One-off content change: /technical-university-of-munich-in-germany/ (72398)
 * gets the new TUM content in the layout of yesgermany-mumbai-page-structure-v4:
 * one full-width container holding one HTML widget (2026-10-07-tum-page.html,
 * styles scoped to .ygt, px sizes, the site's Enquiry Form via shortcode).
 * Template stays elementor_header_footer, so the site header and footer remain.
 * Run on STAGING, then publish.
 *
 *   php run-in-wp 2026-10-07-tum-page.php <html-file>          # dry run
 *   php run-in-wp 2026-10-07-tum-page.php <html-file> apply    # writes
 */
$ID    = 72398;
$file  = $args[0] ?? '';
$apply = in_array( 'apply', (array) $args, true );
$html  = (string) @file_get_contents( $file );
if ( strlen( $html ) < 1000 || false === strpos( $html, 'contact-form-7 id="139211"' ) ) { fwrite( STDERR, "bad html file\n" ); return; }
if ( 'technical-university-of-munich-in-germany' !== get_post_field( 'post_name', $ID ) ) { fwrite( STDERR, "72398 is not the TUM page\n" ); return; }

$data = array( array(
	'id' => 'ygtumwrap', 'elType' => 'container', 'isInner' => false,
	'settings' => array(
		'content_width' => 'full',
		'padding' => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
		'_title' => 'TUM page (hand-built HTML, see deployment/one-off/2026-10-07-tum-page.html)',
	),
	'elements' => array( array( 'id' => 'ygtumhtml', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $html ), 'elements' => array() ) ),
) );
echo "72398: replace Elementor data (", strlen( (string) get_post_meta( $ID, '_elementor_data', true ) ), " bytes) with one HTML widget (", strlen( $html ), " bytes); template ", get_post_meta( $ID, '_wp_page_template', true ), "\n";
if ( ! $apply ) { echo "DRY RUN\n"; return; }
update_post_meta( $ID, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
update_post_meta( $ID, '_wp_page_template', 'elementor_header_footer' );
foreach ( array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets' ) as $k ) delete_post_meta( $ID, $k );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $ID ) );
clean_post_cache( $ID );
if ( class_exists( '\Elementor\Plugin' ) ) \Elementor\Plugin::$instance->files_manager->clear_cache();
echo "APPLIED\n";
