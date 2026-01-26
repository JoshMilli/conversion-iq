<?php
/**
 * Conversion IQ - Complete Diagnostic Report
 * Add this to the main plugin file as an AJAX action if needed
 * Or visit this standalone page for diagnostics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// If called as standalone, load WordPress
if ( ! function_exists( 'get_option' ) ) {
    $wp_path = dirname(__FILE__);
    while ($wp_path != '/' && $wp_path != 'C:\\' && !file_exists($wp_path . '/wp-load.php')) {
        $wp_path = dirname($wp_path);
    }
    if (file_exists($wp_path . '/wp-load.php')) {
        require_once $wp_path . '/wp-load.php';
    } else {
        die('Could not load WordPress');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Conversion IQ Diagnostic Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; margin: 20px; background: #f1f1f1; }
        .wrap { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { color: #0073aa; border-bottom: 3px solid #0073aa; padding-bottom: 10px; }
        h2 { color: #23282d; margin-top: 30px; }
        .status { padding: 10px; margin: 10px 0; border-left: 4px solid #ddd; border-radius: 3px; }
        .status.pass { background: #f1f8f4; border-left-color: #22873a; }
        .status.fail { background: #fdeef0; border-left-color: #d73a49; }
        .status.warn { background: #fef8d4; border-left-color: #d4a000; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; margin-left: 10px; }
        .badge.pass { background: #22873a; color: white; }
        .badge.fail { background: #d73a49; color: white; }
        .badge.warn { background: #d4a000; color: white; }
        code { background: #f5f5f5; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f9f9f9; font-weight: bold; }
        .action-button { background: #0073aa; color: white; padding: 10px 15px; border: none; border-radius: 3px; cursor: pointer; margin: 10px 5px 10px 0; }
        .action-button:hover { background: #005a87; }
        #test-results { background: #f5f5f5; padding: 15px; border-radius: 3px; margin: 15px 0; max-height: 300px; overflow-y: auto; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Conversion IQ - Diagnostic Report</h1>
    
    <p><strong>Last Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
    
    <?php
    // Check current user
    if (!current_user_can('manage_options')) {
        echo '<div class="status fail"><strong>ERROR:</strong> You do not have permission to view this page. Admin access required.</div>';
        die();
    }
    ?>

    <h2>1. Plugin Installation</h2>
    <?php
    $active_plugins = get_option('active_plugins');
    $plugin_name = 'conversion-iq/conversion-iq.php';
    $is_active = in_array($plugin_name, $active_plugins);
    
    echo '<div class="status ' . ($is_active ? 'pass' : 'fail') . '">';
    echo ($is_active ? '✓ Plugin is ACTIVE' : '✗ Plugin is NOT ACTIVE');
    echo '<span class="badge ' . ($is_active ? 'pass' : 'fail') . '">' . ($is_active ? 'PASS' : 'FAIL') . '</span>';
    echo '</div>';
    ?>

    <h2>2. WordPress & PHP Requirements</h2>
    <?php
    global $wp_version;
    $php_version = phpversion();
    $wp_ok = version_compare($wp_version, '6.0', '>=');
    $php_ok = version_compare($php_version, '7.4', '>=');
    
    echo '<div class="status ' . ($wp_ok ? 'pass' : 'fail') . '">';
    echo 'WordPress: ' . $wp_version . ' ' . ($wp_ok ? '✓' : '✗');
    echo '<span class="badge ' . ($wp_ok ? 'pass' : 'fail') . '">' . ($wp_ok ? 'OK' : 'TOO OLD') . '</span>';
    echo '</div>';
    
    echo '<div class="status ' . ($php_ok ? 'pass' : 'fail') . '">';
    echo 'PHP: ' . $php_version . ' ' . ($php_ok ? '✓' : '✗');
    echo '<span class="badge ' . ($php_ok ? 'pass' : 'fail') . '">' . ($php_ok ? 'OK' : 'TOO OLD') . '</span>';
    echo '</div>';
    ?>

    <h2>3. Plugin Constants</h2>
    <?php
    $constants_ok = true;
    
    $checks = [
        'CONVERSION_IQ_VERSION' => CONVERSION_IQ_VERSION ?? null,
        'CONVERSION_IQ_DIR' => CONVERSION_IQ_DIR ?? null,
        'CONVERSION_IQ_URL' => CONVERSION_IQ_URL ?? null,
    ];
    
    foreach ($checks as $const => $value) {
        $ok = !empty($value);
        $constants_ok = $constants_ok && $ok;
        echo '<div class="status ' . ($ok ? 'pass' : 'fail') . '">';
        echo '<code>' . $const . '</code>: ' . ($value ? esc_html(substr($value, 0, 80)) . '...' : 'NOT DEFINED');
        echo '<span class="badge ' . ($ok ? 'pass' : 'fail') . '">' . ($ok ? 'OK' : 'ERROR') . '</span>';
        echo '</div>';
    }
    ?>

    <h2>4. Build Files</h2>
    <?php
    $assets_dir = CONVERSION_IQ_DIR . 'admin/build/vite-dist/assets/';
    $assets_exist = is_dir($assets_dir);
    
    echo '<div class="status ' . ($assets_exist ? 'pass' : 'fail') . '">';
    echo 'Build Directory: ' . ($assets_exist ? '✓ EXISTS' : '✗ MISSING');
    echo '<span class="badge ' . ($assets_exist ? 'pass' : 'fail') . '">' . ($assets_exist ? 'OK' : 'ERROR') . '</span>';
    echo '</div>';
    
    if ($assets_exist) {
        $files = scandir($assets_dir);
        $js_files = array_filter($files, function($f) { return strpos($f, 'index.') === 0 && substr($f, -3) === '.js'; });
        $css_files = array_filter($files, function($f) { return strpos($f, 'index.') === 0 && substr($f, -4) === '.css'; });
        
        echo '<div class="status ' . (!empty($js_files) ? 'pass' : 'fail') . '">';
        echo 'JavaScript Bundle: ' . (!empty($js_files) ? '✓ FOUND' : '✗ MISSING');
        echo '<span class="badge ' . (!empty($js_files) ? 'pass' : 'fail') . '">' . (!empty($js_files) ? 'OK' : 'ERROR') . '</span>';
        if (!empty($js_files)) {
            echo '<br/><small>Files: ' . implode(', ', array_values($js_files)) . '</small>';
        }
        echo '</div>';
        
        echo '<div class="status ' . (!empty($css_files) ? 'pass' : 'fail') . '">';
        echo 'CSS Bundle: ' . (!empty($css_files) ? '✓ FOUND' : '✗ MISSING');
        echo '<span class="badge ' . (!empty($css_files) ? 'pass' : 'fail') . '">' . (!empty($css_files) ? 'OK' : 'ERROR') . '</span>';
        if (!empty($css_files)) {
            echo '<br/><small>Files: ' . implode(', ', array_values($css_files)) . '</small>';
        }
        echo '</div>';
    }
    ?>

    <h2>5. REST API Registration</h2>
    <?php
    $rest_routes = rest_get_routes();
    $conversioniq_routes = array_filter($rest_routes, function($route) {
        return strpos($route, 'conversioniq') !== false;
    });
    
    echo '<div class="status ' . (!empty($conversioniq_routes) ? 'pass' : 'fail') . '">';
    echo 'Conversion IQ Routes: ' . (count($conversioniq_routes) > 0 ? '✓ ' . count($conversioniq_routes) . ' routes' : '✗ NO ROUTES');
    echo '<span class="badge ' . (!empty($conversioniq_routes) ? 'pass' : 'fail') . '">' . (!empty($conversioniq_routes) ? 'OK' : 'ERROR') . '</span>';
    echo '</div>';
    
    if (!empty($conversioniq_routes)) {
        echo '<table>';
        echo '<tr><th>Route</th><th>Methods</th></tr>';
        foreach ($conversioniq_routes as $route => $data) {
            $methods = isset($data['methods']) ? implode(', ', $data['methods']) : 'N/A';
            echo '<tr><td><code>' . esc_html($route) . '</code></td><td>' . esc_html($methods) . '</td></tr>';
        }
        echo '</table>';
    }
    ?>

    <h2>6. Database Tables</h2>
    <?php
    global $wpdb;
    $tables = $wpdb->get_results("SHOW TABLES LIKE '%conversioniq%'");
    $tables_ok = count($tables) > 0;
    
    echo '<div class="status ' . ($tables_ok ? 'pass' : 'warn') . '">';
    echo 'Database Tables: ' . (count($tables) > 0 ? '✓ ' . count($tables) . ' tables' : '⚠ NO TABLES (may be normal)');
    echo '<span class="badge ' . ($tables_ok ? 'pass' : 'warn') . '">' . ($tables_ok ? 'OK' : 'WARN') . '</span>';
    echo '</div>';
    
    if (!empty($tables)) {
        echo '<table>';
        echo '<tr><th>Table Name</th></tr>';
        foreach ($tables as $table) {
            $table_name = current((array)$table);
            echo '<tr><td><code>' . esc_html($table_name) . '</code></td></tr>';
        }
        echo '</table>';
    }
    ?>

    <h2>7. File Permissions</h2>
    <?php
    $files_to_check = [
        'admin/dashboard.php',
        'includes/rest-api.php',
        'includes/class-ai-engine.php',
    ];
    
    $all_readable = true;
    foreach ($files_to_check as $file) {
        $full_path = CONVERSION_IQ_DIR . $file;
        $readable = is_readable($full_path);
        $all_readable = $all_readable && $readable;
        
        echo '<div class="status ' . ($readable ? 'pass' : 'fail') . '">';
        echo 'File: <code>' . esc_html($file) . '</code> ' . ($readable ? '✓ Readable' : '✗ Not readable');
        echo '<span class="badge ' . ($readable ? 'pass' : 'fail') . '">' . ($readable ? 'OK' : 'ERROR') . '</span>';
        echo '</div>';
    }
    ?>

    <h2>8. Test API Endpoint</h2>
    <p>
        <button class="action-button" onclick="testAPI()">Test API Endpoint</button>
        <button class="action-button" onclick="clearCache()">Clear Cache</button>
    </p>
    <div id="test-results"></div>

    <h2>9. Quick Actions</h2>
    <p>
        <button class="action-button" onclick="goToPlugin()">Go to Plugin</button>
        <button class="action-button" onclick="reloadPage()">Reload Page</button>
    </p>

    <hr>
    <p><small>Report generated for troubleshooting. Check your browser's Developer Console (F12) for additional JavaScript errors.</small></p>
</div>

<script>
function testAPI() {
    const results = document.getElementById('test-results');
    results.innerHTML = '<p>Testing API...</p>';
    
    // This will be set by wp_localize_script, but we can test the REST URL directly
    const restUrl = '<?php echo rest_url('conversioniq/v1/'); ?>';
    
    fetch(restUrl + 'auth/status')
        .then(r => {
            results.innerHTML += '<p>Status: ' + r.status + ' ' + r.statusText + '</p>';
            return r.json();
        })
        .then(data => {
            results.innerHTML += '<p><strong>API Response:</strong></p><pre>' + JSON.stringify(data, null, 2) + '</pre>';
        })
        .catch(err => {
            results.innerHTML += '<p style="color: red;"><strong>Error:</strong> ' + err.message + '</p>';
        });
}

function clearCache() {
    const results = document.getElementById('test-results');
    results.innerHTML = '<p>Clearing cache...</p>';
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=conversioniq_clear_cache'
    })
    .then(r => r.text())
    .then(text => {
        results.innerHTML += '<p>Cache cleared. Reloading...</p>';
        setTimeout(() => location.reload(), 1000);
    })
    .catch(err => {
        results.innerHTML += '<p style="color: red;">Error: ' + err.message + '</p>';
    });
}

function goToPlugin() {
    window.location.href = '<?php echo admin_url('admin.php?page=conversion-iq'); ?>';
}

function reloadPage() {
    location.reload();
}
</script>

</body>
</html>
