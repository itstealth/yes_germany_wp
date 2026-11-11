<?php
/*
 * One-off content change: the 62.5% text fix for four more German-language
 * pages, the same fix /german-language-enquiry/ (41571) got on 1 Oct 2026.
 * Run on STAGING, then publish (Additional CSS is the custom_css post 5445,
 * which travels with the content tables).
 *
 * These pages' hand-built HTML is sized in rem for a 16px base, but Zakra sets
 * the root to 62.5% (10px), so their rem-sized text rendered at 62.5%: 22-53
 * pieces of text under 12px per page (the fixed page has 1-5). Their root goes
 * to 16px. Unlike 41571 (Canvas), three of them use Full Width with the theme
 * header and footer, so body is pinned to 16px too; otherwise Zakra's 1.6rem
 * body becomes 25.6px.
 *
 *   php run-in-wp 2026-10-06-german-pages-font.php          # dry run
 *   php run-in-wp 2026-10-06-german-pages-font.php apply    # writes
 */
$apply  = in_array( 'apply', (array) ( $args ?? array() ), true );
$pages  = array( 133841 => 'german-language-course', 7010 => 'german-classes-in-chennai', 4282 => 'german-language', 8025 => 'german-language-course-in-delhi' );
$marker = '/* yg-german-pages-root-16 */';

foreach ( $pages as $id => $slug ) {
	if ( get_post_field( 'post_name', $id ) !== $slug ) { fwrite( STDERR, "page $id is not $slug; stopping\n" ); return; }
}
$post = wp_get_custom_css_post( get_stylesheet() );
if ( ! $post ) { fwrite( STDERR, "no Additional CSS post\n" ); return; }
if ( false !== strpos( $post->post_content, $marker ) ) { echo "already applied\n"; return; }

$html = array(); $body = array();
foreach ( array_keys( $pages ) as $id ) { $html[] = "html:has(body.page-id-$id)"; $body[] = "body.page-id-$id"; }
$css = "\n\n$marker\n/* Same 62.5% fix as page-id-41571, for German Language Course, Chennai, German Language\n   and Delhi: their hand-built design is sized in rem for a 16px base. Body pinned to 16px\n   so the theme's 1.6rem body text does not grow to 25.6px. */\n"
	. implode( ",\n", $html ) . " { font-size: 16px; }\n"
	. implode( ",\n", $body ) . " { font-size: 16px; }\n";

echo "Additional CSS post {$post->ID}: append\n$css";
if ( $apply ) {
	$r = wp_update_custom_css_post( $post->post_content . $css, array( 'stylesheet' => get_stylesheet() ) );
	echo is_wp_error( $r ) ? 'ERROR ' . $r->get_error_message() . "\n" : "APPLIED (post {$r->ID})\n";
}
