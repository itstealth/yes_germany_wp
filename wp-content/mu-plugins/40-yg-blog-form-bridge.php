<?php
/**
 * Plugin Name: YG Blog Form Bridge
 * Description: Read-only endpoint the blog install (public_html/blog) uses to render the main site's enquiry form and keep its branch mapping in sync. Adds nothing to the main site's own pages and changes no existing behaviour.
 * Version:     1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The form blog submissions are recorded against.
 *
 * Deliberately the site-wide Enquiry Form, so mail, branch mapping and lead
 * capture behave exactly as they do on the main site. Point the filter at a
 * dedicated "Blog Enquiry" copy if blog leads should be filterable by form
 * name in the leads panel; nothing else needs to change.
 */
function yg_blog_form_id() {
	return (int) apply_filters( 'yg_blog_form_id', 139211 );
}

add_action( 'rest_api_init', function () {
	register_rest_route(
		'yg/v1',
		'/blog-form',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => 'yg_blog_form_bridge',
		)
	);
} );

/**
 * Hand the blog the rendered form rather than a field list.
 *
 * The blog then cannot drift from whatever the form actually is - field names,
 * select options, placeholders and CF7's own hidden fields all come across as
 * they are. Everything returned here is already public: the branch mapping is
 * localised into every front-end page of the main site, and the form markup is
 * on every page that embeds it.
 */
function yg_blog_form_bridge() {
	$form_id = yg_blog_form_id();

	if ( ! shortcode_exists( 'contact-form-7' ) || ! get_post( $form_id ) ) {
		return new WP_Error(
			'yg_blog_form_unavailable',
			'The enquiry form is not available.',
			array( 'status' => 503 )
		);
	}

	$html = do_shortcode(
		sprintf( '[contact-form-7 id="%d" title="%s"]', $form_id, esc_attr( get_the_title( $form_id ) ) )
	);

	$branch_assets = plugins_url( 'assets/', WP_PLUGIN_DIR . '/branch-selection-for-cf7/branch-selection-for-cf7.php' );

	return rest_ensure_response(
		array(
			'form_id'   => $form_id,
			'endpoint'  => rest_url( sprintf( 'contact-form-7/v1/contact-forms/%d/feedback', $form_id ) ),
			'html'      => $html,
			'branches'  => get_option( 'branch_selection_cf7_data' ),
			'thank_you' => home_url( '/thank-you/' ),
			'assets'    => array(
				'branch_js' => $branch_assets . 'branch-selection.js',
				'ui_js'     => $branch_assets . 'frontend-ui.js',
				'form_css'  => $branch_assets . 'frontend-style.css',
				'cf7_css'   => plugins_url( 'includes/css/styles.css', WP_PLUGIN_DIR . '/contact-form-7/wp-contact-form-7.php' ),
			),
		)
	);
}
