<?php
/**
 * Plugin Initialization Test
 * Place this file in the root of your WordPress installation to test
 * Usage: Visit http://yoursite.com/test-plugin-init.php
 */

// Load WordPress
$wp_path = dirname(__FILE__);
while ($wp_path != '/' && $wp_path != 'C:\\' && !file_exists($wp_path . '/wp-load.php')) {
    $wp_path = dirname($wp_path);
}

if (!file_exists($wp_path . '/wp-load.php')) {
    die('Could not find WordPress installation');
}

require_once $wp_path . '/wp-load.php';

echo '<h1>Conversion IQ Plugin Diagnostics</h1>';
echo '<pre>';

// Check if plugin is active
$active_plugins = get_option('active_plugins');
$plugin_name = 'conversion-iq/conversion-iq.php';
$is_active = in_array($plugin_name, $active_plugins);

echo "Plugin Active: " . ($is_active ? '✓ YES' : '✗ NO') . "\n";
echo "Active Plugins: " . print_r($active_plugins, true) . "\n";

// Check constants
echo "\n=== CONVERSION IQ CONSTANTS ===\n";
echo "CONVERSION_IQ_VERSION: " . (defined('CONVERSION_IQ_VERSION') ? CONVERSION_IQ_VERSION : 'NOT DEFINED') . "\n";
echo "CONVERSION_IQ_DIR: " . (defined('CONVERSION_IQ_DIR') ? CONVERSION_IQ_DIR : 'NOT DEFINED') . "\n";
echo "CONVERSION_IQ_URL: " . (defined('CONVERSION_IQ_URL') ? CONVERSION_IQ_URL : 'NOT DEFINED') . "\n";

// Check if build files exist
echo "\n=== BUILD FILES ===\n";
$assets_dir = CONVERSION_IQ_DIR . 'admin/build/vite-dist/assets/';
if (is_dir($assets_dir)) {
    echo "Assets Directory exists: ✓\n";
    $files = scandir($assets_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "  - $file\n";
        }
    }
} else {
    echo "Assets Directory exists: ✗\n";
}

// Check REST API registration
echo "\n=== REST API ===\n";
$rest_routes = rest_get_routes();
$conversioniq_routes = array_filter($rest_routes, function($route) {
    return strpos($route, 'conversioniq') !== false;
});
echo "Conversion IQ REST Routes Registered: " . (count($conversioniq_routes) > 0 ? '✓' : '✗') . "\n";
if (count($conversioniq_routes) > 0) {
    foreach (array_keys($conversioniq_routes) as $route) {
        echo "  - $route\n";
    }
}

// Check for errors
echo "\n=== ERROR LOG (last 20 lines) ===\n";
$error_log = ini_get('error_log');
if ($error_log && file_exists($error_log)) {
    $lines = array_slice(file($error_log), -20);
    foreach ($lines as $line) {
        echo trim($line) . "\n";
    }
} else {
    echo "Error log not found at: $error_log\n";
}

// Check database tables
echo "\n=== DATABASE TABLES ===\n";
global $wpdb;
$tables = $wpdb->get_results("SHOW TABLES LIKE '%conversioniq%'");
echo "Conversion IQ Tables: " . (count($tables) > 0 ? '✓' : '✗') . "\n";
if (count($tables) > 0) {
    foreach ($tables as $table) {
        $table_name = current((array)$table);
        echo "  - $table_name\n";
    }
}

// Test API endpoint
echo "\n=== TEST API CALL ===\n";
$response = wp_remote_get(home_url('/wp-json/conversioniq/v1/auth/status'), array(
    'headers' => array(
        'X-WP-Nonce' => wp_create_nonce('wp_rest'),
    )
));

if (is_wp_error($response)) {
    echo "API Error: " . $response->get_error_message() . "\n";
} else {
    echo "HTTP Status: " . wp_remote_retrieve_response_code($response) . "\n";
    $body = wp_remote_retrieve_body($response);
    echo "Response Body:\n" . substr($body, 0, 500) . "\n";
}

echo '</pre>';
echo '<p><a href="' . admin_url('admin.php?page=conversion-iq') . '">Back to Plugin</a></p>';
?>
