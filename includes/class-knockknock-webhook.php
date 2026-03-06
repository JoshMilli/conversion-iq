<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * KnockKnock Webhook Handler
 * Receives and processes webhook events from KnockKnock
 */
class ConversionIQ_KnockKnock_Webhook_Handler {
    
    private $table_logs;
    private $table_leads;
    private $table_sessions;
    private $table_analytics;
    
    public function __construct() {
        global $wpdb;
        $this->table_logs = $wpdb->prefix . 'conversioniq_webhook_logs';
        $this->table_leads = $wpdb->prefix . 'conversioniq_leads';
        $this->table_sessions = $wpdb->prefix . 'conversioniq_visitor_sessions';
        $this->table_analytics = $wpdb->prefix . 'conversioniq_page_analytics';
        
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
    }
    
    /**
     * Register the webhook endpoint
     */
    public function register_webhook_endpoint() {
        register_rest_route('conversioniq/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // Public endpoint, security via signature
        ]);

        // API endpoint to get webhook logs
        register_rest_route('conversioniq/v1', '/webhooks', [
            'methods' => 'GET',
            'callback' => [$this, 'get_webhook_logs'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
    }
    
    /**
     * Main webhook handler
     */
    public function handle_webhook(WP_REST_Request $request) {
        global $wpdb;
        
        error_log('ConversionIQ: Webhook received');
        
        // Get headers
        $signature = $request->get_header('X-Webhook-Signature');
        $timestamp = $request->get_header('X-Webhook-Timestamp');
        $event_type = $request->get_header('X-Webhook-Event');
        
        // Get raw body
        $raw_body = $request->get_body();
        $payload = json_decode($raw_body, true);
        
        if (!$payload) {
            error_log('ConversionIQ: Invalid JSON payload');
            return new WP_REST_Response(['error' => 'Invalid JSON payload'], 400);
        }
        
        $company_id = $payload['company_id'] ?? '';
        error_log("ConversionIQ: Company ID from webhook: {$company_id}");
        
        // Get webhook secret for this site
        $webhook_secret = get_option('conversioniq_knockknock_webhook_secret');
        $configured_company_id = get_option('conversioniq_knockknock_company_id');
        
        // Verify this webhook is for this account
        if ($company_id !== $configured_company_id) {
            error_log("ConversionIQ: Company ID mismatch. Expected: {$configured_company_id}, Got: {$company_id}");
            return new WP_REST_Response(['error' => 'Company ID mismatch'], 403);
        }
        
        // Verify signature if secret is configured
        if ($webhook_secret && !$this->verify_signature($signature, $timestamp, $raw_body, $webhook_secret)) {
            error_log("ConversionIQ: Invalid webhook signature");
            return new WP_REST_Response(['error' => 'Invalid signature'], 403);
        }
        
        error_log("ConversionIQ: Webhook verified successfully");
        
        // Log the webhook event
        $log_id = $this->log_webhook_event($payload, $event_type);
        
        // Process based on event type
        switch ($event_type) {
            case 'new_lead':
                $this->process_new_lead($log_id, $payload);
                error_log("ConversionIQ: Processed new_lead event");
                break;
                
            case 'new_user_identified':
                $this->process_new_user($log_id, $payload);
                error_log("ConversionIQ: Processed new_user_identified event");
                break;
                
            default:
                error_log("ConversionIQ: Unknown event type: {$event_type}");
        }
        
        return new WP_REST_Response(['success' => true, 'log_id' => $log_id], 200);
    }
    
    /**
     * Get webhook logs for display
     */
    public function get_webhook_logs(WP_REST_Request $request) {
        global $wpdb;
        
        $limit = $request->get_param('limit') ?: 50;
        
        // Get recent leads with details
        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                l.*,
                wl.timestamp as webhook_timestamp,
                wl.event_type
            FROM {$this->table_leads} l
            LEFT JOIN {$this->table_logs} wl ON l.webhook_log_id = wl.id
            ORDER BY l.converted_at DESC
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        return new WP_REST_Response([
            'success' => true,
            'leads' => $leads,
            'total' => count($leads)
        ], 200);
    }
    
    /**
     * Verify HMAC signature
     */
    private function verify_signature($signature, $timestamp, $raw_body, $secret) {
        if (!$signature || !$timestamp || !$secret) {
            error_log('ConversionIQ: Missing signature components');
            return false;
        }
        
        // Check timestamp (5-minute tolerance)
        $age = abs(time() - intval($timestamp));
        if ($age > 300) {
            error_log("ConversionIQ: Webhook timestamp too old: {$age} seconds");
            return false;
        }
        
        // Compute expected signature
        $payload = "{$timestamp}.{$raw_body}";
        $expected_signature = hash_hmac('sha256', $payload, $secret);
        
        // Timing-safe comparison
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Log webhook event to database
     */
    private function log_webhook_event($payload, $event_type) {
        global $wpdb;
        
        $wpdb->insert($this->table_logs, [
            'event_type' => $event_type,
            'webhook_id' => $payload['webhook_id'] ?? '',
            'company_id' => $payload['company_id'] ?? '',
            'raw_payload' => json_encode($payload),
            'verified' => 1,
            'timestamp' => gmdate('Y-m-d H:i:s', $payload['timestamp'] ?? time()),
            'created_at' => current_time('mysql')
        ]);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Process new lead event
     */
    private function process_new_lead($log_id, $payload) {
        global $wpdb;
        
        $data = $payload['data'] ?? [];
        $user_session = $data['user_session'] ?? [];
        $contact_info = $data['contact_information'] ?? [];
        
        $page_url = $user_session['page_url'] ?? '';
        $email = $contact_info['email'] ?? $user_session['email'] ?? '';
        
        if (empty($email)) {
            error_log('ConversionIQ: No email in lead data');
            return;
        }
        
        // Insert lead
        $wpdb->insert($this->table_leads, [
            'webhook_log_id' => $log_id,
            'first_name' => $user_session['first_name'] ?? $this->parse_first_name($contact_info['name'] ?? ''),
            'last_name' => $user_session['last_name'] ?? $this->parse_last_name($contact_info['name'] ?? ''),
            'email' => $email,
            'phone' => $contact_info['phone'] ?? null,
            'page_url' => $page_url,
            'page_title' => null, // Will be enriched later if needed
            'user_session_id' => $user_session['_id'] ?? '',
            'converted_at' => gmdate('Y-m-d H:i:s', $payload['timestamp'] ?? time()),
            'created_at' => current_time('mysql')
        ]);
        
        error_log("ConversionIQ: Lead saved - Email: {$email}, Page: {$page_url}");
        
        // Update page analytics
        if ($page_url) {
            $this->update_page_analytics($page_url);
        }
    }
    
    /**
     * Process new user identified event
     */
    private function process_new_user($log_id, $payload) {
        global $wpdb;
        
        $data = $payload['data'] ?? [];
        $user_session = $data['user_session'] ?? [];
        
        $user_session_id = $user_session['_id'] ?? '';
        $page_url = $user_session['page_url'] ?? '';
        
        if (empty($user_session_id)) {
            error_log('ConversionIQ: No user_session_id in identified user data');
            return;
        }
        
        // Insert or update visitor session
        $wpdb->replace($this->table_sessions, [
            'webhook_log_id' => $log_id,
            'user_session_id' => $user_session_id,
            'first_name' => $user_session['first_name'] ?? null,
            'last_name' => $user_session['last_name'] ?? null,
            'email' => $user_session['email'] ?? null,
            'page_url' => $page_url,
            'identified_at' => gmdate('Y-m-d H:i:s', $payload['timestamp'] ?? time()),
            'created_at' => current_time('mysql')
        ]);
        
        error_log("ConversionIQ: Visitor identified - Session: {$user_session_id}, Page: {$page_url}");
        
        // Update page analytics
        if ($page_url) {
            $this->update_page_analytics($page_url);
        }
    }
    
    /**
     * Update page analytics aggregation
     */
    private function update_page_analytics($page_url) {
        global $wpdb;
        
        if (empty($page_url)) {
            return;
        }
        
        // Get stats for this page
        $total_visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_session_id) FROM {$this->table_sessions} 
             WHERE page_url = %s",
            $page_url
        )) ?: 0;
        
        $identified_visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_session_id) FROM {$this->table_sessions} 
             WHERE page_url = %s AND email IS NOT NULL",
            $page_url
        )) ?: 0;
        
        $total_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_leads} 
             WHERE page_url = %s",
            $page_url
        )) ?: 0;
        
        $conversion_rate = $total_visitors > 0 ? ($total_leads / $total_visitors) * 100 : 0;
        
        // Upsert analytics record
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_analytics} 
             WHERE page_url = %s",
            $page_url
        ));
        
        if ($existing) {
            $wpdb->update($this->table_analytics, [
                'total_visitors' => $total_visitors,
                'identified_visitors' => $identified_visitors,
                'total_leads' => $total_leads,
                'conversion_rate' => round($conversion_rate, 2)
            ], [
                'id' => $existing
            ]);
        } else {
            $wpdb->insert($this->table_analytics, [
                'page_url' => $page_url,
                'total_visitors' => $total_visitors,
                'identified_visitors' => $identified_visitors,
                'total_leads' => $total_leads,
                'conversion_rate' => round($conversion_rate, 2)
            ]);
        }
        
        error_log("ConversionIQ: Analytics updated - Page: {$page_url}, Visitors: {$total_visitors}, Leads: {$total_leads}, Rate: " . round($conversion_rate, 2) . "%");
    }
    
    /**
     * Helper: Parse first name from full name
     */
    private function parse_first_name($full_name) {
        $parts = explode(' ', trim($full_name));
        return $parts[0] ?? '';
    }
    
    /**
     * Helper: Parse last name from full name
     */
    private function parse_last_name($full_name) {
        $parts = explode(' ', trim($full_name));
        array_shift($parts);
        return implode(' ', $parts);
    }
}

// Initialize
new ConversionIQ_KnockKnock_Webhook_Handler();
