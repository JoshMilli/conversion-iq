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
        
        // Track script loading errors
        window.addEventListener('error', function(event) {
            if (event.filename && event.filename.includes('index.') && event.filename.includes('conversion')) {
                console.error('✗ SCRIPT LOADING ERROR:', {
                    message: event.message,
                    filename: event.filename,
                    lineno: event.lineno
                });
            }
        }, true);
        
        // Timeout to detect if React never loads
        var reactLoadTimeout = setTimeout(function() {
            console.error('✗ TIMEOUT: React app failed to initialize within 5 seconds');
            console.error('Check:');
            console.error('  1. Network tab - is index.*.js loading?');
            console.error('  2. Console - any red errors above?');
            console.error('  3. Browser DevTools - go to Network tab and check for failed requests');
            var appDiv = document.getElementById('conversion-iq-app');
            if (appDiv) {
                appDiv.innerHTML = '<div style="color: red; padding: 20px;"><strong>ERROR:</strong> React app failed to load. Check browser console (F12) for details.</div>';
            }
        }, 5000);
        
        // Clear timeout once React mounts
        window.conversionIQReactMounted = function() {
            clearTimeout(reactLoadTimeout);
            console.log('✓ React app successfully mounted');
        };
    </script>
</div>
