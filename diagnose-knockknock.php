<?php
/**
 * Quick Diagnostic Tool for KnockKnock Integration
 * Access: /wp-content/plugins/conversion-iq/diagnose-knockknock.php
 */

require_once('../../../wp-load.php');

header('Content-Type: application/json');

global $wpdb;

$diagnostics = [
    'timestamp' => current_time('mysql'),
    'settings' => [
        'company_id' => get_option('conversioniq_knockknock_company_id', ''),
        'webhook_secret_set' => !empty(get_option('conversioniq_knockknock_webhook_secret')),
        'webhook_url' => home_url('/wp-json/conversioniq/v1/webhook')
    ],
    'tables' => [],
    'data' => []
];

// Check tables
$tables_to_check = [
    'webhook_logs' => $wpdb->prefix . 'conversioniq_webhook_logs',
    'leads' => $wpdb->prefix . 'conversioniq_leads',
    'visitor_sessions' => $wpdb->prefix . 'conversioniq_visitor_sessions',
    'page_analytics' => $wpdb->prefix . 'conversioniq_page_analytics'
];

foreach ($tables_to_check as $key => $table_name) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    $count = 0;
    
    if ($exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }
    
    $diagnostics['tables'][$key] = [
        'name' => $table_name,
        'exists' => $exists,
        'count' => $count
    ];
}

// Get recent webhook logs
$logs_table = $wpdb->prefix . 'conversioniq_webhook_logs';
if ($diagnostics['tables']['webhook_logs']['exists']) {
    $diagnostics['data']['recent_webhook_logs'] = $wpdb->get_results(
        "SELECT id, event_type, company_id, verified, timestamp, created_at, raw_payload 
         FROM {$logs_table} 
         ORDER BY created_at DESC 
         LIMIT 10",
        ARRAY_A
    );
    
    // Decode raw_payload JSON for easier reading
    foreach ($diagnostics['data']['recent_webhook_logs'] as &$log) {
        if (isset($log['raw_payload'])) {
            $decoded = json_decode($log['raw_payload'], true);
            $log['payload_decoded'] = $decoded;
            // Keep first 500 chars of raw for reference
            $log['raw_payload_preview'] = substr($log['raw_payload'], 0, 500);
            unset($log['raw_payload']);
        }
    }
}

// Get recent leads
$leads_table = $wpdb->prefix . 'conversioniq_leads';
if ($diagnostics['tables']['leads']['exists']) {
    $diagnostics['data']['recent_leads'] = $wpdb->get_results(
        "SELECT id, first_name, last_name, email, page_url, converted_at, created_at 
         FROM {$leads_table} 
         ORDER BY created_at DESC 
         LIMIT 10",
        ARRAY_A
    );
}

// Test REST API endpoint
$test_url = home_url('/wp-json/conversioniq/v1/webhooks');
$test_nonce = wp_create_nonce('wp_rest');

$diagnostics['api_test'] = [
    'endpoint' => $test_url,
    'note' => 'Use this URL with X-WP-Nonce header for authenticated requests'
];

// Check if WordPress debug is enabled
$diagnostics['debug_mode'] = [
    'WP_DEBUG' => defined('WP_DEBUG') && WP_DEBUG,
    'WP_DEBUG_LOG' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
    'debug_log_path' => WP_CONTENT_DIR . '/debug.log'
];

echo json_encode($diagnostics, JSON_PRETTY_PRINT);
