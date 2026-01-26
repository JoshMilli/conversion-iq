<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Conversion IQ', 'conversion-iq' ); ?></h1>
    <p><?php esc_html_e( 'AI-powered audits to improve clarity, emotional resonance, and CTAs.', 'conversion-iq' ); ?></p>

    <div id="conversion-iq-app">Loading...</div>

    <noscript>
        <p><?php esc_html_e( 'This page requires JavaScript.', 'conversion-iq' ); ?></p>
    </noscript>

    <script>
        console.log('=== Conversion IQ Plugin Dashboard Loaded ===');
        console.log('Timestamp:', new Date().toISOString());
        console.log('Page URL:', window.location.href);
        console.log('User Agent:', navigator.userAgent);
        
        // Check if ConversionIQData is available
        if (typeof ConversionIQData !== 'undefined') {
            console.log('✓ ConversionIQData Available:', {
                restUrl: ConversionIQData.restUrl,
                nonce: ConversionIQData.nonce ? '(present)' : '(MISSING)',
                pluginUrl: ConversionIQData.pluginUrl,
                version: ConversionIQData.version
            });
        } else {
            console.warn('✗ ConversionIQData NOT YET AVAILABLE - will be available when scripts load');
        }
        
        console.log('Waiting for React app to mount...');
    </script>
</div>
