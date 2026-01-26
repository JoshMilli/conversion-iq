<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Generate nonce and prepare data for immediate injection
$nonce = wp_create_nonce( 'wp_rest' );
$data = array(
    'restUrl' => esc_url_raw( rest_url( 'conversioniq/v1/' ) ),
    'nonce'   => $nonce,
    'pluginUrl' => CONVERSION_IQ_URL,
    'version' => CONVERSION_IQ_VERSION . '.' . get_option('conversioniq_last_updated', time()),
);
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Conversion IQ', 'conversion-iq' ); ?></h1>
    <p><?php esc_html_e( 'AI-powered audits to improve clarity, emotional resonance, and CTAs.', 'conversion-iq' ); ?></p>

    <div id="conversion-iq-app">Loading...</div>

    <noscript>
        <p><?php esc_html_e( 'This page requires JavaScript.', 'conversion-iq' ); ?></p>
    </noscript>

    <script>
        // Inject ConversionIQData immediately (before React bundle loads)
        window.ConversionIQData = <?php echo wp_json_encode( $data ); ?>;
        
        console.log('=== Conversion IQ Plugin Dashboard Loaded ===');
        console.log('Timestamp:', new Date().toISOString());
        console.log('Page URL:', window.location.href);
        console.log('User Agent:', navigator.userAgent);
        console.log('✓ ConversionIQData Available:', {
            restUrl: window.ConversionIQData.restUrl,
            nonce: window.ConversionIQData.nonce ? '(present)' : '(MISSING)',
            pluginUrl: window.ConversionIQData.pluginUrl,
            version: window.ConversionIQData.version
        });
        console.log('Waiting for React app to mount...');
    </script>
</div>
