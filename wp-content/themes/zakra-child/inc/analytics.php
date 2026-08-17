<?php
/**
 * Analytics and tag management.
 *
 * Previously hard-coded into the parent theme's header.php. Reimplemented on
 * hooks so no header.php override is needed and zakra stays updatable.
 *
 * These tags only fire in production. On staging they are suppressed, otherwise
 * test traffic pollutes the live GA4 / Google Ads conversion data.
 *
 * @package yes-germany
 */

defined( 'ABSPATH' ) || exit;

// IDs carried over verbatim from the previous parent-theme edit.
const YG_GTM_ID     = 'GTM-WVKNCD3Q';
const YG_GTAG_ID    = 'GT-M3VGS323';
const YG_GOOGLE_ADS = 'AW-11122290827';

/**
 * Whether analytics should load.
 *
 * yg_is_production() is provided by the mu-plugin, which always loads first.
 * The function_exists guard keeps the theme usable if the mu-plugin is absent.
 *
 * @return bool
 */
function yg_analytics_enabled() {
	if ( function_exists( 'yg_is_production' ) && ! yg_is_production() ) {
		return false;
	}
	return ! is_admin();
}

/**
 * Google Tag Manager container, plus the first-landing-page capture that
 * feeds the lead payload.
 */
function yg_analytics_head() {
	if ( ! yg_analytics_enabled() ) {
		return;
	}
	?>
	<!-- Google Tag Manager -->
	<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
	new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
	j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
	'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
	})(window,document,'script','dataLayer','<?php echo esc_js( YG_GTM_ID ); ?>');</script>

	<script>
	/* Remember the first page this visitor landed on, for lead attribution. */
	(function captureFirstLandingPage() {
		var KEY = 'yg_referral_url';
		try {
			if ( ! localStorage.getItem( KEY ) ) {
				localStorage.setItem( KEY, window.location.href );
			}
		} catch ( e ) {}
	})();
	</script>

	<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( YG_GTAG_ID ); ?>"></script>
	<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());
	gtag('config', '<?php echo esc_js( YG_GTAG_ID ); ?>');
	gtag('config', '<?php echo esc_js( YG_GOOGLE_ADS ); ?>');
	</script>
	<?php
}
add_action( 'wp_head', 'yg_analytics_head', 1 );

/**
 * GTM noscript fallback. Belongs immediately after <body>.
 */
function yg_analytics_body_open() {
	if ( ! yg_analytics_enabled() ) {
		return;
	}
	?>
	<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( YG_GTM_ID ); ?>"
	height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
	<?php
}
add_action( 'wp_body_open', 'yg_analytics_body_open' );
