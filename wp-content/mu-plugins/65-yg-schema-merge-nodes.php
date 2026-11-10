<?php
/**
 * Plugin Name: YG Schema: merge nodes with the same @id
 * Description: AIOSEO outputs a node once for every place it is defined. When a page's AIOSEO Custom Schema adds properties to a node AIOSEO already makes (e.g. the page's WebPage or the site Organization), the two copies are merged into one, so search engines see one entity.
 * Version:     1.0.0
 * Author:      Stealth Digital
 *
 * Added 2026-10-23 for the Madurai page (139869), whose AIOSEO Custom Schema
 * ("Madurai branch – LocalBusiness + page links") adds:
 *   - a LocalBusiness node for the branch,
 *   - extra properties for https://www.yesgermany.com/german-education-consultant-in-madurai/#webpage
 *     (description, about, mainEntity),
 *   - extra properties for https://www.yesgermany.com/#organization
 *     (legalName, logo with a site-wide @id, sameAs with YouTube, department).
 *
 * How it merges: nodes are compared by their exact @id. The first copy in the
 * graph is kept and the later copies only fill in properties it lacks. AIOSEO
 * puts Custom Schema before its own graphs, so a value written in Custom Schema
 * wins over AIOSEO's. Nodes without @id, and relative @ids such as "/#organization",
 * are left alone.
 *
 * Only pages that define the same @id twice are affected; every other page's
 * schema is output unchanged.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'aioseo_schema_output',
	function ( $graphs ) {
		if ( ! is_array( $graphs ) ) {
			return $graphs;
		}
		$out = array();
		$pos = array();
		foreach ( $graphs as $node ) {
			$id = is_array( $node ) && isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';
			if ( '' === $id || ! preg_match( '#^https?://#', $id ) ) {
				$out[] = $node;
				continue;
			}
			if ( ! isset( $pos[ $id ] ) ) {
				$pos[ $id ] = count( $out );
				$out[]      = $node;
				continue;
			}
			$out[ $pos[ $id ] ] += $node; // Keep the first copy's values, add what it lacks.
		}
		return $out;
	},
	20
);
