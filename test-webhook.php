<?php
/**
 * Test Webhook Endpoint
 * 
 * This file simulates an external webhook receiver for testing.
 * Place this on your internal website or run locally to test webhook integration.
 */

// Allow from any origin (for testing only)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the raw POST data
$raw_data = file_get_contents('php://input');
$data = json_decode($raw_data, true);

// Get headers
$headers = getallheaders();
$api_key = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : 
           (isset($headers['X-Api-Key']) ? $headers['X-Api-Key'] : null);

// Log received data
$log_entry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'api_key' => $api_key ? '***' . substr($api_key, -4) : 'none',
    'data' => $data
];

$log_file = __DIR__ . '/webhook-test-log.json';
$logs = [];
if (file_exists($log_file)) {
    $logs = json_decode(file_get_contents($log_file), true) ?: [];
}
$logs[] = $log_entry;

// Keep only last 50 entries
$logs = array_slice($logs, -50);
file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));

// Respond with success
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Webhook received successfully',
    'received_at' => date('Y-m-d H:i:s'),
    'page_title' => $data['page_title'] ?? 'unknown',
    'scores' => $data['scores'] ?? []
]);
