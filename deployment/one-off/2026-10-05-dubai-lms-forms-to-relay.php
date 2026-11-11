<?php
/*
 * One-off content fix for dubai.yesgermany.com, run DIRECTLY on that install
 * (it has no staging and is not part of Publish to Live).
 *
 * Same job as 2026-10-01-lms-forms-to-relay.php on the main site: the hand-built
 * forms in Elementor HTML widgets post straight to the LMS lead-widget with the
 * api-key in the page. This points them at the main site's relay
 * (mu-plugin 55, which already accepts the dubai origin) and removes the key.
 * The relay URL is absolute because dubai does not run mu-plugin 55 itself.
 *
 * Unlike the main-site script, the header patterns here are not anchored to the
 * start of a line: on dubai the api-key often sits inline, e.g.
 *   xhr.open("POST", "...", true); xhr.setRequestHeader("api-key", "...");
 *   headers: { "Content-Type": "application/json", "api-key": "..." },
 *
 *   cd ~/dubai.yesgermany.com
 *   wp eval-file 2026-10-05-dubai-lms-forms-to-relay.php          # dry run, prints JSON (key redacted)
 *   wp eval-file 2026-10-05-dubai-lms-forms-to-relay.php apply    # writes
 *
 * Apply saves every changed _elementor_data to the option
 * yg_lms_relay_backup_<UTC timestamp> first, and drops the post's Elementor
 * element cache so the page re-renders. A post is left untouched if any key or
 * LMS URL would remain in it.
 */
global $wpdb; $P = $wpdb->prefix;
$apply = in_array( 'apply', (array) ( $args ?? array() ), true );
$RELAY = 'https://www.yesgermany.com/wp-json/yg/v1/lms-lead';

function yg_dubai_rw_code( $c, $relay ) {
	$c = preg_replace( '#https://(?:lms|crm-backend)\.yesgermany\.org/api/v1/lead-management/lead-widget/website#', $relay, $c );
	$c = str_replace( '${baseUrl}/api/v1/lead-management/lead-widget/website', $relay, $c );
	// xhr.setRequestHeader("api-key", "..."); wherever it sits, live or commented out
	$c = preg_replace( '#(?://[ \t]*)?[\w.]*setRequestHeader\(\s*["\']api-key["\']\s*,\s*["\'][^"\']*["\']\s*\);?[ \t]*#i', '', $c );
	// "api-key": "..." inside a headers object, with the comma that joins it
	$c = preg_replace( '#,\s*["\']api-key["\']\s*:\s*["\'][^"\']*["\']#i', '', $c );
	$c = preg_replace( '#["\']api-key["\']\s*:\s*["\'][^"\']*["\']\s*,?#i', '', $c );
	return $c;
}
$rows = $wpdb->get_results( "SELECT p.ID, p.post_name, p.post_status, m.meta_id, m.meta_value FROM {$P}postmeta m JOIN {$P}posts p ON p.ID=m.post_id WHERE m.meta_key='_elementor_data' AND m.meta_value LIKE '%lead-widget%' AND p.post_type IN ('page','post','elementor_library') AND p.post_status IN ('publish','draft','private','pending','future') ORDER BY p.ID", ARRAY_A );
$report = array(); $backup = array(); $bad = 0; $changed = 0;
foreach ( $rows as $r ) {
	$data = json_decode( $r['meta_value'], true );
	if ( ! is_array( $data ) ) { $report[] = array( 'ID' => $r['ID'], 'error' => 'json' ); $bad++; continue; }
	$touched = array();
	$walk = function ( &$els ) use ( &$walk, &$touched, $RELAY ) {
		foreach ( $els as &$e ) {
			foreach ( array( 'html', 'editor', 'custom_html' ) as $k ) {
				if ( isset( $e['settings'][ $k ] ) && is_string( $e['settings'][ $k ] ) && false !== strpos( $e['settings'][ $k ], 'lead-widget' ) ) {
					$new = yg_dubai_rw_code( $e['settings'][ $k ], $RELAY );
					if ( $new !== $e['settings'][ $k ] ) { $e['settings'][ $k ] = $new; $touched[] = array( 'el' => $e['id'], 'code' => $new ); }
				}
			}
			if ( ! empty( $e['elements'] ) ) $walk( $e['elements'] );
		}
	};
	$walk( $data );
	$json = wp_json_encode( $data );
	$left = preg_match_all( '/ApiKey[A-Za-z0-9_\-]{6,}/', $json ) + preg_match_all( '#lead-widget\\\\?/website#', $json ) + preg_match_all( '#api-key#i', $json );
	$report[] = array( 'ID' => $r['ID'], 'slug' => $r['post_name'], 'status' => $r['post_status'], 'widgets' => count( $touched ), 'left' => $left,
		'codes' => array_map( function ( $t ) { return preg_replace( '/ApiKey[A-Za-z0-9_\-]+/', 'ApiKeyREDACTED', $t['code'] ); }, $touched ) );
	if ( $left ) { $bad++; continue; }
	if ( $touched && $apply ) {
		$backup[ $r['meta_id'] ] = array( 'post_id' => $r['ID'], 'meta_value' => $r['meta_value'] );
		$wpdb->update( "{$P}postmeta", array( 'meta_value' => $json ), array( 'meta_id' => $r['meta_id'] ) );
		delete_post_meta( (int) $r['ID'], '_elementor_element_cache' );
		clean_post_cache( (int) $r['ID'] );
		$changed++;
	}
}
if ( $apply && $backup ) {
	add_option( 'yg_lms_relay_backup_' . gmdate( 'Ymd_His' ), $backup, '', 'no' );
}
fwrite( STDERR, sprintf( "rows=%d changed=%d not-clean=%d apply=%s\n", count( $rows ), $changed, $bad, $apply ? 'yes' : 'no' ) );
echo wp_json_encode( $report );
