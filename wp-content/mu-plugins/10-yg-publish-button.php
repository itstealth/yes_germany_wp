<?php
/**
 * Plugin Name: YG Publish to Live
 * Description: Adds a "Publish to Live" button to the staging admin bar, so the
 *              content team can publish without using GitHub. It triggers the
 *              same approval-gated workflow — nothing about the safety changes.
 * Version:     1.0.0
 * Author:      Stealth Digital
 *
 * STAGING ONLY. The button never renders in production, because publishing to
 * live from live is meaningless and the endpoint would be a needless risk.
 *
 * Requires two constants in wp-config.php (never in Git):
 *
 *   define( 'YG_GITHUB_TOKEN', 'github_pat_...' );  // fine-grained, actions:write, this repo only
 *   define( 'YG_GITHUB_REPO',  'itstealth/yes_germany_wp' );
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the button can operate.
 *
 * @return bool
 */
function yg_publish_ready() {
	return ! yg_is_production()
		&& defined( 'YG_GITHUB_TOKEN' ) && YG_GITHUB_TOKEN
		&& defined( 'YG_GITHUB_REPO' ) && YG_GITHUB_REPO;
}

/**
 * Only administrators may publish.
 *
 * Anyone who can reach staging's wp-admin could otherwise trigger a production
 * deploy. The approval gate would still catch it, but there is no reason to let
 * an editor start one.
 *
 * @return bool
 */
function yg_publish_permitted() {
	return yg_publish_ready() && current_user_can( 'manage_options' );
}

/**
 * Whether the button should be drawn at all.
 *
 * Deliberately weaker than yg_publish_permitted(): the button is shown to any
 * administrator on staging even when the token is missing, and says so when
 * clicked. Hiding it until configured meant nobody could find the thing they
 * were being told to look for.
 *
 * @return bool
 */
function yg_publish_visible() {
	return ! yg_is_production() && current_user_can( 'manage_options' );
}

/**
 * Add the button to the admin bar.
 *
 * @param WP_Admin_Bar $bar Admin bar instance.
 */
function yg_publish_admin_bar( $bar ) {
	if ( ! yg_publish_visible() ) {
		return;
	}

	$ready = yg_publish_ready();
	$label = $ready
		? __( 'Publish to Live', 'yes-germany' )
		: __( 'Publish to Live (setup needed)', 'yes-germany' );

	$bar->add_node(
		array(
			'id'    => 'yg-publish',
			'title' => '<span class="ab-icon dashicons dashicons-upload" style="top:2px"></span>'
				. '<span class="ab-label">' . esc_html( $label ) . '</span>',
			'href'  => '#',
			'meta'  => array(
				'title'   => __( 'Publish staging content to www.yesgermany.com', 'yes-germany' ),
				'onclick' => 'ygPublishToLive(); return false;',
			),
		)
	);
}
add_action( 'admin_bar_menu', 'yg_publish_admin_bar', 90 );

/**
 * Inline script for the button.
 *
 * Deliberately dependency-free: this must keep working even if a plugin update
 * breaks something on the page.
 */
function yg_publish_script() {
	if ( ! yg_publish_visible() ) {
		return;
	}

	// Not configured yet: explain rather than fail silently.
	if ( ! yg_publish_ready() ) {
		?>
		<script>
		function ygPublishToLive() {
			window.alert(
				'Publish to Live is not configured yet.\n\n' +
				'Add this to wp-config.php on the staging server:\n\n' +
				"  define( 'YG_GITHUB_TOKEN', 'github_pat_...' );\n\n" +
				'Create the token at GitHub \u2192 Settings \u2192 Developer settings \u2192\n' +
				'Fine-grained tokens, with Actions: Read and write on\n' +
				'itstealth/yes_germany_wp only.'
			);
		}
		</script>
		<?php
		return;
	}
	$nonce = wp_create_nonce( 'yg_publish' );
	$url   = admin_url( 'admin-ajax.php' );
	?>
	<script>
	function ygPublishToLive() {
		if ( ! window.confirm(
			'Publish staging content to the LIVE site?\n\n' +
			'This copies posts, pages, designs, media and plugin settings to\n' +
			'www.yesgermany.com.\n\n' +
			'It still needs a second person to approve before anything changes.'
		) ) { return; }

		var node = document.getElementById( 'wp-admin-bar-yg-publish' );
		var label = node ? node.querySelector( '.ab-label' ) : null;
		if ( label ) { label.textContent = 'Requesting…'; }

		var body = new FormData();
		body.append( 'action', 'yg_publish_to_live' );
		body.append( 'nonce', '<?php echo esc_js( $nonce ); ?>' );

		fetch( '<?php echo esc_url_raw( $url ); ?>', { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				if ( label ) { label.textContent = 'Publish to Live'; }
				if ( d && d.success ) {
					window.alert(
						'Publish requested.\n\n' +
						'An approval request has been opened. Once someone approves it,\n' +
						'the change goes live in a few minutes.\n\n' +
						'Track it here:\n' + d.data.url
					);
				} else {
					window.alert( 'Could not start the publish.\n\n' + ( d && d.data ? d.data : 'Unknown error' ) );
				}
			} )
			.catch( function ( e ) {
				if ( label ) { label.textContent = 'Publish to Live'; }
				window.alert( 'Could not reach the publish service.\n\n' + e );
			} );
	}
	</script>
	<?php
}
add_action( 'admin_footer', 'yg_publish_script' );
add_action( 'wp_footer', 'yg_publish_script' );

/**
 * Trigger the GitHub workflow.
 *
 * The token never reaches the browser: the request is made server-side and only
 * the resulting URL is returned.
 */
function yg_publish_ajax() {
	if ( ! yg_publish_permitted() ) {
		wp_send_json_error( __( 'Not permitted.', 'yes-germany' ), 403 );
	}
	if ( ! check_ajax_referer( 'yg_publish', 'nonce', false ) ) {
		wp_send_json_error( __( 'Security check failed. Reload the page and try again.', 'yes-germany' ), 400 );
	}

	$repo     = YG_GITHUB_REPO;
	$workflow = defined( 'YG_GITHUB_WORKFLOW' ) ? YG_GITHUB_WORKFLOW : 'push-content.yml';

	$response = wp_remote_post(
		"https://api.github.com/repos/{$repo}/actions/workflows/{$workflow}/dispatches",
		array(
			'timeout' => 20,
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . YG_GITHUB_TOKEN,
				'X-GitHub-Api-Version' => '2022-11-28',
				'Content-Type'         => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'ref'    => defined( 'YG_GITHUB_REF' ) ? YG_GITHUB_REF : 'main',
					'inputs' => array(
						'confirm' => 'PUSH',
						'dry_run' => 'false',
						'force'   => 'false',
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message(), 502 );
	}

	$code = wp_remote_retrieve_response_code( $response );

	// GitHub returns 204 No Content on a successful dispatch.
	if ( 204 === $code ) {
		wp_send_json_success(
			array(
				'url' => "https://github.com/{$repo}/actions/workflows/{$workflow}",
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	$msg  = $body ? wp_strip_all_tags( substr( $body, 0, 300 ) ) : '';
	wp_send_json_error(
		sprintf(
			/* translators: 1: HTTP status code, 2: response body */
			__( 'GitHub returned %1$d. %2$s', 'yes-germany' ),
			$code,
			$msg
		),
		502
	);
}
add_action( 'wp_ajax_yg_publish_to_live', 'yg_publish_ajax' );

/**
 * Tell an administrator why the button is missing, rather than leaving them
 * wondering.
 */
function yg_publish_setup_notice() {
	if ( yg_is_production() || ! current_user_can( 'manage_options' ) || yg_publish_ready() ) {
		return;
	}
	printf(
		'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <code>YG_GITHUB_TOKEN</code>, <code>YG_GITHUB_REPO</code></p></div>',
		esc_html__( 'Publish to Live is not configured.', 'yes-germany' ),
		esc_html__( 'Add these to wp-config.php on this server:', 'yes-germany' )
	);
}
add_action( 'admin_notices', 'yg_publish_setup_notice' );
