<?php
/**
 * Reprocess Existing Webhook Logs
 * This script reprocesses webhook logs that were received before the payload parsing fix
 * 
 * Access: /wp-content/plugins/conversion-iq/reprocess-webhooks.php
 * 
 * IMPORTANT: Delete this file after use for security
 */

require_once('../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Unauthorized');
}

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Reprocess KnockKnock Webhooks</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 40px; max-width: 900px; margin: 0 auto; }
        h1 { color: #1d4ed8; }
        .success { background: #d1fae5; border: 1px solid #10b981; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .error { background: #fee2e2; border: 1px solid #ef4444; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .info { background: #dbeafe; border: 1px solid #3b82f6; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .webhook-item { background: #f9fafb; border: 1px solid #e5e7eb; padding: 15px; border-radius: 8px; margin: 10px 0; }
        pre { background: #1f2937; color: #f9fafb; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .btn { background: #1d4ed8; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #1e40af; }
    </style>
</head>
<body>
    <h1>🔄 Reprocess KnockKnock Webhooks</h1>
    <div class="info">
        <strong>This script will:</strong>
        <ul>
            <li>Find all webhook logs that weren't processed (no associated leads/sessions)</li>
            <li>Re-run the processing logic with the updated payload parser</li>
            <li>Create lead or visitor session records from the webhook data</li>
        </ul>
        <strong>⚠️ Delete this file after use for security</strong>
    </div>

<?php

if (isset($_GET['process'])) {
    global $wpdb;
    
    // Load the webhook handler
    require_once(plugin_dir_path(__FILE__) . 'includes/class-knockknock-webhook.php');
    $handler = new ConversionIQ_KnockKnock_Webhook_Handler();
    
    // Get reflection to access private methods
    $reflection = new ReflectionClass($handler);
    $process_lead_method = $reflection->getMethod('process_new_lead');
    $process_lead_method->setAccessible(true);
    $process_user_method = $reflection->getMethod('process_new_user');
    $process_user_method->setAccessible(true);
    
    $logs_table = $wpdb->prefix . 'conversioniq_webhook_logs';
    $leads_table = $wpdb->prefix . 'conversioniq_leads';
    $sessions_table = $wpdb->prefix . 'conversioniq_visitor_sessions';
    
    // Find unprocessed webhook logs
    $unprocessed_logs = $wpdb->get_results("
        SELECT wl.* 
        FROM {$logs_table} wl
        LEFT JOIN {$leads_table} l ON wl.id = l.webhook_log_id
        LEFT JOIN {$sessions_table} s ON wl.id = s.webhook_log_id
        WHERE l.id IS NULL AND s.id IS NULL
        AND wl.event_type IN ('new_lead', 'new_user_identified')
        ORDER BY wl.created_at ASC
    ", ARRAY_A);
    
    echo "<h2>Processing " . count($unprocessed_logs) . " unprocessed webhooks...</h2>";
    
    $processed_count = 0;
    $error_count = 0;
    
    foreach ($unprocessed_logs as $log) {
        $payload = json_decode($log['raw_payload'], true);
        
        echo "<div class='webhook-item'>";
        echo "<strong>Webhook #{$log['id']}</strong> - Type: <code>{$log['event_type']}</code> - Received: {$log['created_at']}<br>";
        
        try {
            if ($log['event_type'] === 'new_lead') {
                $process_lead_method->invoke($handler, $log['id'], $payload);
                echo "<span style='color: #10b981;'>✓ Processed as lead</span>";
                $processed_count++;
            } elseif ($log['event_type'] === 'new_user_identified') {
                $process_user_method->invoke($handler, $log['id'], $payload);
                echo "<span style='color: #10b981;'>✓ Processed as identified user</span>";
                $processed_count++;
            }
            
            // Show what was extracted
            $data = $payload['data'] ?? [];
            $contact = $data['contact'] ?? [];
            if (!empty($contact)) {
                $name = ($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '');
                $email = $contact['businessEmail'] ?? $contact['personalEmail'] ?? 'No email';
                echo "<br><small>Name: {$name}, Email: {$email}</small>";
            }
            
        } catch (Exception $e) {
            echo "<span style='color: #ef4444;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>";
            $error_count++;
        }
        
        echo "</div>";
    }
    
    // Summary
    echo "<div class='success'>";
    echo "<h3>✅ Reprocessing Complete</h3>";
    echo "<p>Successfully processed: <strong>{$processed_count}</strong></p>";
    if ($error_count > 0) {
        echo "<p>Errors: <strong>{$error_count}</strong></p>";
    }
    echo "</div>";
    
    // Show current counts
    $lead_count = $wpdb->get_var("SELECT COUNT(*) FROM {$leads_table}");
    $session_count = $wpdb->get_var("SELECT COUNT(*) FROM {$sessions_table}");
    
    echo "<div class='info'>";
    echo "<h3>📊 Current Database State</h3>";
    echo "<p>Total leads: <strong>{$lead_count}</strong></p>";
    echo "<p>Total visitor sessions: <strong>{$session_count}</strong></p>";
    echo "</div>";
    
    echo "<p><a href='?'>← Back</a> | <a href='" . admin_url('admin.php?page=conversion-iq') . "'>View in Dashboard →</a></p>";
    
} else {
    // Show preview
    global $wpdb;
    
    $logs_table = $wpdb->prefix . 'conversioniq_webhook_logs';
    $leads_table = $wpdb->prefix . 'conversioniq_leads';
    $sessions_table = $wpdb->prefix . 'conversioniq_visitor_sessions';
    
    $unprocessed_logs = $wpdb->get_results("
        SELECT wl.* 
        FROM {$logs_table} wl
        LEFT JOIN {$leads_table} l ON wl.id = l.webhook_log_id
        LEFT JOIN {$sessions_table} s ON wl.id = s.webhook_log_id
        WHERE l.id IS NULL AND s.id IS NULL
        AND wl.event_type IN ('new_lead', 'new_user_identified')
        ORDER BY wl.created_at ASC
    ", ARRAY_A);
    
    echo "<h2>Found " . count($unprocessed_logs) . " unprocessed webhooks</h2>";
    
    if (count($unprocessed_logs) > 0) {
        foreach ($unprocessed_logs as $log) {
            $payload = json_decode($log['raw_payload'], true);
            $data = $payload['data'] ?? [];
            $contact = $data['contact'] ?? [];
            
            echo "<div class='webhook-item'>";
            echo "<strong>Webhook #{$log['id']}</strong><br>";
            echo "Type: <code>{$log['event_type']}</code><br>";
            echo "Received: {$log['created_at']}<br>";
            
            if (!empty($contact)) {
                $name = ($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '');
                $email = $contact['businessEmail'] ?? $contact['personalEmail'] ?? 'No email';
                echo "Contact: {$name} ({$email})<br>";
            }
            
            echo "</div>";
        }
        
        echo "<form method='get'>";
        echo "<input type='hidden' name='process' value='1'>";
        echo "<button type='submit' class='btn'>🚀 Process All Webhooks</button>";
        echo "</form>";
    } else {
        echo "<div class='info'>";
        echo "<p>✓ No unprocessed webhooks found. All webhooks have been successfully processed!</p>";
        echo "</div>";
    }
}

?>

    <hr style="margin: 40px 0;">
    <p style="color: #6b7280; font-size: 14px;">
        <strong>⚠️ Security Notice:</strong> Delete this file after use:<br>
        <code>/wp-content/plugins/conversion-iq/reprocess-webhooks.php</code>
    </p>
</body>
</html>
