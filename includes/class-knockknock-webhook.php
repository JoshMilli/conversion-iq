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
        
        error_log('=== ConversionIQ: WEBHOOK RECEIVED ===');
        error_log('ConversionIQ: Request method: ' . $request->get_method());
        
        // Get headers
        $signature = $request->get_header('X-Webhook-Signature');
        $timestamp = $request->get_header('X-Webhook-Timestamp');
        $event_type = $request->get_header('X-Webhook-Event');
        
        error_log('ConversionIQ: Event type from header: ' . ($event_type ?: 'NOT SET'));
        error_log('ConversionIQ: Signature present: ' . ($signature ? 'YES' : 'NO'));
        error_log('ConversionIQ: Timestamp: ' . ($timestamp ?: 'NOT SET'));
        
        // Get raw body
        $raw_body = $request->get_body();
        error_log('ConversionIQ: Payload length: ' . strlen($raw_body) . ' bytes');
        
        $payload = json_decode($raw_body, true);
        
        if (!$payload) {
            error_log('ConversionIQ: Invalid JSON payload');
            error_log('ConversionIQ: Raw body: ' . substr($raw_body, 0, 500));
            return new WP_REST_Response(['error' => 'Invalid JSON payload'], 400);
        }
        
        // FALLBACK: If event_type not in header, check payload
        if (empty($event_type)) {
            // Try common payload fields for event type
            $event_type = $payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? '';
            error_log('ConversionIQ: Event type from payload: ' . ($event_type ?: 'STILL NOT FOUND'));
            
            // If still not found, try to infer from structure
            if (empty($event_type) && isset($payload['data'])) {
                $data = $payload['data'];
                
                // Infer based on structure
                if (isset($data['is_conversion']) || isset($data['conversion_type'])) {
                    $event_type = 'new_lead';
                    error_log('ConversionIQ: Event type INFERRED as new_lead from structure');
                } elseif (isset($data['user_session_id']) || isset($data['user_session'])) {
                    $event_type = 'new_user_identified';
                    error_log('ConversionIQ: Event type INFERRED as new_user_identified from structure');
                }
            }
        }
        
        error_log('ConversionIQ: Final event type to process: ' . ($event_type ?: 'NONE'));
        
        $payload = json_decode($raw_body, true);
        
        if (!$payload) {
            error_log('ConversionIQ: Invalid JSON payload');
            error_log('ConversionIQ: Raw body: ' . substr($raw_body, 0, 500));
            return new WP_REST_Response(['error' => 'Invalid JSON payload'], 400);
        }
        
        $company_id = $payload['company_id'] ?? '';
        error_log("ConversionIQ: Company ID from webhook: {$company_id}");
        error_log('ConversionIQ: Full payload: ' . json_encode($payload));
        
        // Get webhook secret for this site
        $webhook_secret = get_option('conversioniq_knockknock_webhook_secret');
        $configured_company_id = get_option('conversioniq_knockknock_company_id');
        
        error_log("ConversionIQ: Configured company ID: {$configured_company_id}");
        error_log("ConversionIQ: Webhook secret configured: " . ($webhook_secret ? 'YES' : 'NO'));
        
        // Security Strategy:
        // 1. If webhook secret is set -> verify HMAC signature (recommended, secure)
        // 2. If no secret but company ID configured -> verify company ID match (basic routing)
        // 3. If neither configured -> reject (must configure at least one)
        
        if ($webhook_secret) {
            // SECURE: Verify HMAC signature
            if (!$this->verify_signature($signature, $timestamp, $raw_body, $webhook_secret)) {
                error_log("ConversionIQ: Invalid webhook signature");
                return new WP_REST_Response(['error' => 'Invalid signature'], 403);
            }
            error_log('ConversionIQ: Webhook signature verified successfully (HMAC)');
            
            // Company ID is just for reference/logging when using HMAC
            if ($company_id && $configured_company_id && $company_id !== $configured_company_id) {
                error_log("ConversionIQ: WARNING - Company ID mismatch but allowing due to valid HMAC. Expected: {$configured_company_id}, Got: {$company_id}");
            }
            
        } else if ($configured_company_id) {
            // BASIC: Fall back to company ID verification if no secret
            error_log('ConversionIQ: No webhook secret configured, using Company ID verification (less secure)');
            
            if ($company_id !== $configured_company_id) {
                error_log("ConversionIQ: Company ID mismatch. Expected: {$configured_company_id}, Got: {$company_id}");
                return new WP_REST_Response(['error' => 'Company ID mismatch'], 403);
            }
            error_log('ConversionIQ: Company ID verified successfully (basic auth)');
            
        } else {
            // REJECTED: Neither authentication method configured
            error_log('ConversionIQ: REJECTED - No webhook secret or company ID configured');
            return new WP_REST_Response([
                'error' => 'Authentication not configured',
                'message' => 'Please configure either a webhook secret (recommended) or company ID in plugin settings'
            ], 403);
        }
        
        // Log the webhook event
        $log_id = $this->log_webhook_event($payload, $event_type);
        error_log("ConversionIQ: Webhook logged with ID: {$log_id}");
        
        // Process based on event type
        error_log("ConversionIQ: Processing event type: " . var_export($event_type, true));
        error_log("ConversionIQ: Event type comparison: 'new_lead'=" . ($event_type === 'new_lead' ? 'TRUE' : 'FALSE') . ", 'new_user_identified'=" . ($event_type === 'new_user_identified' ? 'TRUE' : 'FALSE'));
        
        if (empty($event_type)) {
            error_log("ConversionIQ: ❌ ERROR - No event type could be determined. Webhook will be logged but not processed.");
            error_log("ConversionIQ: Full payload for debugging: " . json_encode($payload));
        }
        
        switch ($event_type) {
            case 'new_lead':
                error_log("ConversionIQ: → Calling process_new_lead()");
                $this->process_new_lead($log_id, $payload);
                error_log("ConversionIQ: ✓ Completed processing new_lead event");
                break;
                
            case 'new_user_identified':
                error_log("ConversionIQ: → Calling process_new_user()");
                $this->process_new_user($log_id, $payload);
                error_log("ConversionIQ: ✓ Completed processing new_user_identified event");
                break;
                
            default:
                error_log("ConversionIQ: ⚠ UNHANDLED EVENT TYPE: '{$event_type}'");
                error_log("ConversionIQ: Event type is: " . gettype($event_type) . " with length: " . strlen((string)$event_type));
                error_log("ConversionIQ: Available event types: 'new_lead', 'new_user_identified'");
                error_log("ConversionIQ: First 500 chars of payload: " . substr(json_encode($payload), 0, 500));
        }
        
        // Verify data was saved
        $verify_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_leads}");
        error_log("ConversionIQ: Total leads in database now: {$verify_count}");
        
        error_log('=== ConversionIQ: WEBHOOK PROCESSING COMPLETE ===');
        
        return new WP_REST_Response(['success' => true, 'log_id' => $log_id], 200);
    }
    
    /**
     * Get webhook logs for display
     */
    public function get_webhook_logs(WP_REST_Request $request) {
        global $wpdb;
        
        error_log('ConversionIQ: get_webhook_logs called');
        
        $limit = $request->get_param('limit') ?: 50;
        
        // Get actual leads (from new_lead events)
        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                l.id,
                l.first_name,
                l.last_name,
                l.email,
                l.phone,
                l.page_url,
                l.initial_page_visit,
                l.converted_at as timestamp,
                'lead' as type,
                wl.event_type
            FROM {$this->table_leads} l
            LEFT JOIN {$this->table_logs} wl ON l.webhook_log_id = wl.id
            ORDER BY l.converted_at DESC
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        error_log('ConversionIQ: Found ' . count($leads) . ' leads');
        
        // Get identified visitors (from new_user_identified events)
        $visitors = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                s.id,
                s.first_name,
                s.last_name,
                s.email,
                NULL as phone,
                s.page_url,
                s.initial_page_visit,
                s.identified_at as timestamp,
                'visitor' as type,
                wl.event_type
            FROM {$this->table_sessions} s
            LEFT JOIN {$this->table_logs} wl ON s.webhook_log_id = wl.id
            ORDER BY s.identified_at DESC
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        error_log('ConversionIQ: Found ' . count($visitors) . ' identified visitors');
        
        // Combine and sort by timestamp
        $combined = array_merge($leads, $visitors);
        usort($combined, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        // Limit to requested count
        $combined = array_slice($combined, 0, $limit);
        
        $total_count = count($combined);
        error_log("ConversionIQ: Returning {$total_count} combined records");
        
        return new WP_REST_Response([
            'success' => true,
            'leads' => $combined,
            'total' => $total_count,
            'debug' => [
                'leads_count' => count($leads),
                'visitors_count' => count($visitors),
                'combined_count' => $total_count
            ]
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
        
        error_log("ConversionIQ: process_new_lead called - Log ID: {$log_id}");
        error_log("ConversionIQ: Lead payload structure: " . json_encode(array_keys($payload)));
        
        $data = $payload['data'] ?? [];
        error_log("ConversionIQ: Data keys: " . json_encode(array_keys($data)));
        
        // KnockKnock actual structure: data.contact.{firstName, lastName, businessEmail, etc}
        $contact = $data['contact'] ?? [];
        $contact_info = $data['contact_information'] ?? []; // Fallback for different structure
        $geo = $contact['geo'] ?? [];
        $company = $data['company'] ?? [];
        
        error_log("ConversionIQ: Contact keys: " . json_encode(array_keys($contact)));
        
        // Extract email (try multiple sources)
        $email = $contact['businessEmail'] ?? $contact['personalEmail'] ?? $contact['email'] ?? $contact_info['email'] ?? '';
        
        // Extract page URL (might be in different places)
        $page_url = $data['page_url'] ?? $contact['workspaceName'] ?? '';
        
        if (empty($email)) {
            error_log('ConversionIQ: ERROR - No email in lead data, cannot save lead');
            error_log('ConversionIQ: Full payload: ' . json_encode($payload));
            return;
        }
        
        // Prepare lead data (handle both camelCase from KnockKnock and snake_case)
        $lead_data = [
            'webhook_log_id' => $log_id,
            'first_name' => $contact['firstName'] ?? $contact['first_name'] ?? $this->parse_first_name($contact_info['name'] ?? ''),
            'last_name' => $contact['lastName'] ?? $contact['last_name'] ?? $this->parse_last_name($contact_info['name'] ?? ''),
            'email' => $email,
            'phone' => $contact['phone'] ?? $contact_info['phone'] ?? null,
            'city' => $geo['city'] ?? null,
            'state' => $geo['state'] ?? null,
            'country' => $geo['country'] ?? null,
            'company_name' => $company['name'] ?? null,
            'company_domain' => $company['domain'] ?? null,
            'company_industry' => $company['industry'] ?? null,
            'job_title' => $contact['position'] ?? null,
            'linkedin_url' => $contact['linkedin'] ?? null,
            'page_url' => $page_url,
            'page_title' => null,
            'initial_page_visit' => $data['initial_page_visit'] ?? null,
            'user_session_id' => $data['user_session_id'] ?? $data['user_session']['_id'] ?? '',
            'converted_at' => gmdate('Y-m-d H:i:s', $payload['timestamp'] ?? time()),
            'created_at' => current_time('mysql')
        ];
        
        error_log("ConversionIQ: Attempting to insert lead: " . json_encode($lead_data));
        
        // Insert lead
        $result = $wpdb->insert($this->table_leads, $lead_data);
        
        if ($result === false) {
            error_log("ConversionIQ: ERROR - Failed to insert lead into database");
            error_log("ConversionIQ: wpdb->last_error: " . $wpdb->last_error);
            error_log("ConversionIQ: wpdb->last_query: " . $wpdb->last_query);
        } else {
            $insert_id = $wpdb->insert_id;
            error_log("ConversionIQ: SUCCESS - Lead saved with ID: {$insert_id} - Email: {$email}, Page: {$page_url}");
        }
        
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
        
        error_log("ConversionIQ: process_new_user called - Log ID: {$log_id}");
        
        $data = $payload['data'] ?? [];
        error_log("ConversionIQ: Data keys: " . json_encode(array_keys($data)));
        
        // KnockKnock actual structure: data.user_session_id at root, data.contact with user info
        $contact = $data['contact'] ?? [];
        $geo = $contact['geo'] ?? [];
        $company = $data['company'] ?? [];
        $user_session_id = $data['user_session_id'] ?? $data['user_session']['_id'] ?? '';
        
        if (empty($user_session_id)) {
            error_log('ConversionIQ: ERROR - No user_session_id in identified user data');
            error_log('ConversionIQ: Full payload: ' . json_encode($payload));
            return;
        }
        
        // Extract email (prefer business, fallback to personal)
        $email = $contact['businessEmail'] ?? $contact['personalEmail'] ?? $contact['email'] ?? null;
        
        // Extract page URL
        $page_url = $data['page_url'] ?? $contact['workspaceName'] ?? '';
        
        error_log("ConversionIQ: User session ID: {$user_session_id}, Email: {$email}, Page: {$page_url}");
        
        // Prepare session data (handle both camelCase and snake_case)
        $session_data = [
            'webhook_log_id' => $log_id,
            'user_session_id' => $user_session_id,
            'first_name' => $contact['firstName'] ?? $contact['first_name'] ?? null,
            'last_name' => $contact['lastName'] ?? $contact['last_name'] ?? null,
            'email' => $email,
            'city' => $geo['city'] ?? null,
            'state' => $geo['state'] ?? null,
            'country' => $geo['country'] ?? null,
            'company_name' => $company['name'] ?? null,
            'company_domain' => $company['domain'] ?? null,
            'company_industry' => $company['industry'] ?? null,
            'job_title' => $contact['position'] ?? null,
            'linkedin_url' => $contact['linkedin'] ?? null,
            'page_url' => $page_url,
            'initial_page_visit' => $data['initial_page_visit'] ?? null,
            'identified_at' => gmdate('Y-m-d H:i:s', $payload['timestamp'] ?? time()),
            'created_at' => current_time('mysql')
        ];
        
        error_log("ConversionIQ: Attempting to save visitor session: " . json_encode($session_data));
        
        // Insert or update visitor session
        $result = $wpdb->replace($this->table_sessions, $session_data);
        
        if ($result === false) {
            error_log("ConversionIQ: ERROR - Failed to save visitor session");
            error_log("ConversionIQ: wpdb->last_error: " . $wpdb->last_error);
        } else {
            error_log("ConversionIQ: SUCCESS - Visitor identified - Session: {$user_session_id}, Email: {$email}");
        }
        
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
