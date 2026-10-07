<?php
/*
 * One-off: create (or update) a university guide page from a hand-built HTML
 * file - one full-width container with one HTML widget, Full Width template so
 * the site header/footer stay, same build as /technical-university-of-munich-in-germany/.
 * Used 2026-10-07 for TU Berlin and LMU (new pages; the older blog posts stay).
 * Run on STAGING, then publish.
 *
 *   php run-in-wp 2026-10-07-create-university-page.php <slug> "<title>" <html-file> [apply]
 */
$slug  = $args[0] ?? ''; $title = $args[1] ?? ''; $file = $args[2] ?? '';
$apply = in_array( 'apply', (array) $args, true );
$html  = (string) @file_get_contents( $file );
if ( ! $slug || ! $title || strlen( $html ) < 1000 || false === strpos( $html, 'contact-form-7 id="139211"' ) ) { fwrite( STDERR, "usage / bad html\n" ); return; }

$existing = get_page_by_path( $slug, OBJECT, 'page' );
$data = array( array(
	'id' => 'ygunivwrap', 'elType' => 'container', 'isInner' => false,
	'settings' => array( 'content_width' => 'full', 'padding' => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ), '_title' => "University guide ($slug), hand-built HTML" ),
	'elements' => array( array( 'id' => 'ygunivhtml', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $html ), 'elements' => array() ) ),
) );
echo ( $existing ? "update page #{$existing->ID}" : "create page" ), " /$slug/ \"$title\" with ", strlen( $html ), " bytes of HTML\n";
if ( ! $apply ) { echo "DRY RUN\n"; return; }

if ( $existing ) {
	$id = $existing->ID;
	global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_title' => $title, 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $id ) );
} else {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => '', 'post_author' => 1 ), true );
	if ( is_wp_error( $id ) ) { echo "ERROR ", $id->get_error_message(), "\n"; return; }
}
update_post_meta( $id, '_wp_page_template', 'elementor_header_footer' );
update_post_meta( $id, '_elementor_edit_mode', 'builder' );
update_post_meta( $id, '_elementor_template_type', 'wp-page' );
if ( defined( 'ELEMENTOR_VERSION' ) ) update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
foreach ( array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets' ) as $k ) delete_post_meta( $id, $k );
clean_post_cache( $id );
if ( class_exists( '\Elementor\Plugin' ) ) \Elementor\Plugin::$instance->files_manager->clear_cache();
echo "DONE page #$id ", get_permalink( $id ), "\n";
