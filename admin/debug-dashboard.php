<?php
/**
 * Check Dashboard Loading
 * This file outputs HTML to help debug why the admin dashboard isn't loading
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Conversion IQ - Dashboard Troubleshooting', 'conversion-iq' ); ?></h1>
    
    <div style="background: #f5f5f5; padding: 15px; margin: 15px 0; border-left: 4px solid #0073aa;">
        <h2>Diagnostic Information</h2>
        <p><strong>Instructions:</strong> Right-click on this page → "Inspect" or press F12 → Go to "Console" tab</p>
        
        <h3>Check the browser console for these messages:</h3>
        <ul>
            <li>✓ If you see "Conversion IQ: Checking authentication..." - JavaScript loaded</li>
            <li>✓ If you see "API Base:" and "Nonce:" - Plugin data available</li>
            <li>✗ If you see red errors - There's a problem to fix</li>
        </ul>
        
        <p>
            <button onclick="testPluginData()" class="button button-primary">Test Plugin Data</button>
            <button onclick="testAPIEndpoint()" class="button">Test API Endpoint</button>
            <button onclick="testBuildFiles()" class="button">Test Build Files</button>
            <button onclick="clearCache()" class="button">Clear Cache & Reload</button>
        </p>
    </div>

    <div id="test-results" style="background: #fff; padding: 15px; margin: 15px 0; border: 1px solid #ddd;">
        <p>Test results will appear here...</p>
    </div>

    <div id="conversion-iq-app">Loading...</div>

    <noscript>
        <div style="background: #fee; padding: 15px; border: 1px solid #fcc;">
            <p><strong>Error:</strong> This page requires JavaScript.</p>
        </div>
    </noscript>

    <script>
        function log(msg) {
            const results = document.getElementById('test-results');
            results.innerHTML += '<p>' + msg + '</p>';
        }

        function testPluginData() {
            log('<strong>Testing Plugin Data...</strong>');
            if (typeof window.ConversionIQData !== 'undefined') {
                log('✓ ConversionIQData found');
                log('  - restUrl: ' + window.ConversionIQData.restUrl);
                log('  - nonce: ' + (window.ConversionIQData.nonce ? 'Present' : 'MISSING'));
                log('  - pluginUrl: ' + window.ConversionIQData.pluginUrl);
                log('  - version: ' + window.ConversionIQData.version);
            } else {
                log('✗ ConversionIQData is UNDEFINED - JavaScript not properly injected');
            }
        }

        function testAPIEndpoint() {
            log('<strong>Testing API Endpoint...</strong>');
            const base = window.ConversionIQData?.restUrl || '/wp-json/conversioniq/v1/';
            const nonce = window.ConversionIQData?.nonce;
            
            fetch(base + 'auth/status', {
                headers: {
                    'X-WP-Nonce': nonce || ''
                }
            })
            .then(r => {
                log('  - HTTP ' + r.status + ' ' + r.statusText);
                return r.json();
            })
            .then(data => {
                log('✓ API Response: ' + JSON.stringify(data));
            })
            .catch(err => {
                log('✗ API Error: ' + err.message);
            });
        }

        function testBuildFiles() {
            log('<strong>Testing Build Files...</strong>');
            fetch('<?php echo CONVERSION_IQ_URL; ?>admin/build/vite-dist/index.html')
                .then(r => {
                    log('  - index.html: ' + r.status);
                })
                .catch(err => {
                    log('✗ Cannot load index.html: ' + err.message);
                });
        }

        function clearCache() {
            log('<strong>Clearing cache...</strong>');
            fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=conversioniq_clear_cache', {
                method: 'POST'
            })
            .then(() => {
                log('Cache cleared. Reloading...');
                window.location.reload();
            })
            .catch(err => {
                log('✗ Error: ' + err.message);
                window.location.reload();
            });
        }

        // Auto-run on load
        window.addEventListener('load', function() {
            testPluginData();
        });
    </script>
</div>
