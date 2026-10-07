<?php
/*
 * One-off content change: on ten German-language course pages in the "wsc"
 * design, the eight feature-card captions ("We provide free study material...",
 * "Classes are led by experienced, Goethe-certified trainers", ...) are Elementor
 * text-editor widgets with their font size set to 10px - too small to read.
 * Not the theme's 62.5% rem problem (mu-plugin 56): the size is set explicitly.
 * Raised to 14px, the body size of the fixed German pages. Only text-editor
 * widgets whose desktop size is exactly 10px are touched.
 * Run on STAGING, then publish.
 *
 *   php run-in-wp 2026-10-07-german-course-captions-14px.php          # dry run
 *   php run-in-wp 2026-10-07-german-course-captions-14px.php apply    # writes
 */
$apply = in_array( 'apply', (array) ( $args ?? array() ), true );
$slugs = array( 'coimbatore', 'ghaziabad', 'gurgaon', 'jaipur', 'kochi', 'kolkata', 'lucknow', 'noida', 'thane', 'thrissur' );
$total = 0;

foreach ( $slugs as $city ) {
	$p = get_page_by_path( "german-language-course-in-$city" );
	if ( ! $p ) { echo "german-language-course-in-$city: NOT FOUND\n"; continue; }
	$data = json_decode( get_post_meta( $p->ID, '_elementor_data', true ), true );
	if ( ! is_array( $data ) ) { echo "#{$p->ID}: no Elementor data\n"; continue; }

	$changed = array();
	$walk = function ( &$els ) use ( &$walk, &$changed ) {
		foreach ( $els as &$e ) {
			$s = &$e['settings'];
			if ( 'text-editor' === ( $e['widgetType'] ?? '' )
				&& 'px' === ( $s['typography_font_size']['unit'] ?? '' )
				&& 10 == ( $s['typography_font_size']['size'] ?? 0 ) ) {
				$s['typography_font_size']['size'] = 14;
				$changed[] = $e['id'] . ' "' . mb_substr( trim( wp_strip_all_tags( (string) ( $s['editor'] ?? '' ) ) ), 0, 40 ) . '"'
					. ( isset( $s['typography_font_size_tablet']['size'] ) && '' !== $s['typography_font_size_tablet']['size'] ? ' tablet=' . $s['typography_font_size_tablet']['size'] . $s['typography_font_size_tablet']['unit'] : '' )
					. ( isset( $s['typography_font_size_mobile']['size'] ) && '' !== $s['typography_font_size_mobile']['size'] ? ' mobile=' . $s['typography_font_size_mobile']['size'] . $s['typography_font_size_mobile']['unit'] : '' );
			}
			unset( $s );
			if ( ! empty( $e['elements'] ) ) $walk( $e['elements'] );
		}
	};
	$walk( $data );
	$total += count( $changed );
	echo "#{$p->ID} {$p->post_name}: " . count( $changed ) . " caption(s)\n";
	foreach ( $changed as $c ) echo "    $c\n";

	if ( $apply && $changed ) {
		update_post_meta( $p->ID, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		delete_post_meta( $p->ID, '_elementor_css' );
		delete_post_meta( $p->ID, '_elementor_element_cache' );
		delete_post_meta( $p->ID, '_elementor_page_assets' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $p->ID ) );
		clean_post_cache( $p->ID );
	}
}
if ( $apply && class_exists( '\Elementor\Plugin' ) ) \Elementor\Plugin::$instance->files_manager->clear_cache();
echo "total $total caption(s) ", $apply ? "APPLIED\n" : "- DRY RUN, nothing written\n";
