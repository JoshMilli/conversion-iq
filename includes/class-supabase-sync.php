<?php
/**
 * Supabase Synchronization Handler
 * 
 * Handles sending audit data to Supabase cloud database for centralized management
 * 
 * @package ConversionIQ
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class ConversionIQ_Supabase_Sync {
    
    /**
     * Supabase project URL
     * @var string
     */
    private $supabase_url;
    
    /**
     * Supabase anonymous key (public)
     * @var string
     */
    private $supabase_anon_key;
    
    /**
     * Organization's unique API key
     * @var string
     */
    private $api_key;
    
    /**
     * Organization ID in Supabase
     * @var string
     */
    private $organization_id;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Get credentials from WordPress options or constants
        // Default credentials are set here - all WordPress sites will automatically connect to your Supabase
        $this->supabase_url = $this->get_config('supabase_url', 'https://spefdqiywnihehfhrood.supabase.co');
        $this->supabase_anon_key = $this->get_config('supabase_anon_key', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InNwZWZkcWl5d25paGVoZmhyb29kIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Njg5ODI4NDcsImV4cCI6MjA4NDU1ODg0N30.FHJRpodLKgwW6hexRqGXKfcVFS4pwntSq83yNyR74d8');
        $this->api_key = get_option('conversioniq_api_key');
        $this->organization_id = get_option('conversioniq_organization_id');
        
        // NOTE: Auto-registration disabled - users must register through the UI
        // This prevents duplicate organization records
    }
    
    /**
     * Get configuration value from constant or option
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    private function get_config($key, $default = '') {
        // Check for constant (e.g., CONVERSIONIQ_SUPABASE_URL in wp-config.php)
        $constant = 'CONVERSIONIQ_' . strtoupper($key);
        if (defined($constant)) {
            return constant($constant);
        }
        
        // Check WordPress option
        return get_option('conversioniq_' . $key, $default);
    }
    
    /**
     * Register this WordPress installation as an organization in Supabase
     * 
     * @return bool Success status
     */
    private function register_installation() {
        if (!$this->supabase_anon_key) {
            error_log('ConversionIQ: Cannot register - Supabase credentials not configured');
            return false;
        }
        
        $site_url = get_site_url();
        $site_name = get_bloginfo('name');
        $api_key = $this->generate_api_key();
        
        // Get account data if available
        $account = get_option('conversioniq_account', null);
        
        // Prepare organization data
        $org_data = [
            'name' => $site_name ?: 'WordPress Site',
            'domain' => parse_url($site_url, PHP_URL_HOST),
            'api_key' => $api_key,
            'plan' => 'free',
            'max_audits_per_month' => 10
        ];
        
        // Add account/user data if available
        if ($account && is_array($account)) {
            $org_data['user_full_name'] = isset($account['full_name']) ? $account['full_name'] : null;
            $org_data['user_email'] = isset($account['email']) ? $account['email'] : null;
            $org_data['company_name'] = isset($account['company']) ? $account['company'] : null;
            $org_data['company_id'] = isset($account['company_id']) ? $account['company_id'] : null;
            $org_data['username'] = isset($account['username']) ? $account['username'] : null;
        }
        
        $response = wp_remote_post($this->supabase_url . '/rest/v1/organizations', [
            'headers' => [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'body' => json_encode($org_data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('ConversionIQ Registration Error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 201) {
            error_log('ConversionIQ Registration Failed: Status ' . $status_code);
            error_log('Response: ' . wp_remote_retrieve_body($response));
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body[0]['api_key']) && isset($body[0]['id'])) {
            update_option('conversioniq_api_key', $body[0]['api_key']);
            update_option('conversioniq_organization_id', $body[0]['id']);
            $this->api_key = $body[0]['api_key'];
            $this->organization_id = $body[0]['id'];
            
            error_log('ConversionIQ: Successfully registered as organization ' . $this->organization_id);
            return true;
        }
        
        return false;
    }
    
    /**
     * Generate a unique API key for this organization
     * 
     * @return string Generated API key
     */
    private function generate_api_key() {
        return 'ciq_' . bin2hex(random_bytes(32));
    }
    
    /**
     * Check if an account with the given email or username exists in Supabase
     *
     * @param string $email Email address to check
     * @param string $username Username to check
     * @return array|false Organization data if exists, false otherwise
     */
    public function check_account_exists($email = null, $username = null) {
        if (!$this->supabase_anon_key) {
            return false;
        }
        
        $filters = [];
        if ($email) {
            $filters[] = 'user_email=eq.' . urlencode($email);
        }
        if ($username) {
            $filters[] = 'username=eq.' . urlencode($username);
        }
        
        if (empty($filters)) {
            return false;
        }
        
        $query = implode(',', $filters);
        $url = $this->supabase_url . '/rest/v1/organizations?or=(' . $query . ')&select=*';
        
        $response = wp_remote_get($url, [
            'headers' => [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            // Silently fail for check - better to allow registration than block it
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body) && is_array($body)) {
            return $body[0]; // Return first match
        }
        
        return false;
    }
    
    /**
     * Validate login credentials against Supabase
     *
     * @param string $username Username
     * @param string $password Password
     * @return array|false Organization data if valid, false otherwise
     */
    public function validate_login($username, $password) {
        if (!$this->supabase_anon_key) {
            error_log('ConversionIQ: Cannot validate login - Supabase not configured');
            return false;
        }
        
        $url = $this->supabase_url . '/rest/v1/organizations?username=eq.' . urlencode($username) . '&select=*';
        
        $response = wp_remote_get($url, [
            'headers' => [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('ConversionIQ: Failed to validate login - ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body) && is_array($body)) {
            $org = $body[0];
            
            // Verify password if password_hash exists
            if (isset($org['password_hash']) && password_verify($password, $org['password_hash'])) {
                return $org;
            }
        }
        
        return false;
    }
    
    /**
     * Create a new account in Supabase
     *
     * @param array $account_data Account data (full_name, email, company, username, password_hash, etc.)
     * @return array|false Created organization data if successful, false otherwise
     */
    public function create_account($account_data) {
        if (!$this->supabase_anon_key) {
            return [
                'error' => 'Supabase not configured',
                'debug' => ['anon_key_missing' => true]
            ];
        }
        
        $site_url = get_site_url();
        $site_name = get_bloginfo('name');
        
        // Prepare organization data
        $org_data = [
            'name' => $site_name ?: 'WordPress Site',
            'domain' => parse_url($site_url, PHP_URL_HOST),
            'api_key' => $account_data['api_key'],
            'plan' => 'free',
            'max_audits_per_month' => 10,
            'user_full_name' => $account_data['full_name'],
            'user_email' => $account_data['email'],
            'company_name' => $account_data['company'],
            'company_id' => $account_data['company_id'],
            'username' => $account_data['username'],
            'password_hash' => $account_data['password_hash']
        ];
        
        $url = $this->supabase_url . '/rest/v1/organizations';
        
        $response = wp_remote_post($url, [
            'headers' => [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'body' => json_encode($org_data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return [
                'error' => $response->get_error_message(),
                'debug' => [
                    'wp_error' => true,
                    'error_code' => $response->get_error_code()
                ]
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $body = json_decode($response_body, true);
        
        if ($status_code !== 201) {
            return [
                'error' => 'Supabase API error',
                'debug' => [
                    'status_code' => $status_code,
                    'response' => $body,
                    'url' => $url,
                    'sent_data' => $org_data
                ]
            ];
        }
        
        if (isset($body[0])) {
            return $body[0];
        }
        
        return [
            'error' => 'Unexpected response format',
            'debug' => [
                'status_code' => $status_code,
                'response' => $body
            ]
        ];
    }

    /**
     * Send audit data to Supabase
     *
     * @param array $audit_data The complete audit data
     * @return bool Success status
     */
    public function send_audit($audit_data) {
        // Check if we're configured
        if (!$this->organization_id || !$this->api_key || !$this->supabase_anon_key) {
            error_log('ConversionIQ: Cannot sync audit - not properly configured');
            return false;
        }
        
        // Prepare audit data for Supabase
        $supabase_data = [
            'organization_id' => $this->organization_id,
            'page_url' => $audit_data['page_url'] ?? '',
            'page_title' => $audit_data['page_title'] ?? null,
            'industry' => $audit_data['industry'] ?? null,
            'clarity_score' => $this->normalize_score($audit_data['clarity_score'] ?? null),
            'emotional_score' => $this->normalize_score($audit_data['emotional_score'] ?? null),
            'cta_strength' => $this->normalize_score($audit_data['cta_strength'] ?? null),
            'readability_score' => $this->normalize_score($audit_data['readability_score'] ?? null),
            'engagement_score' => $this->normalize_score($audit_data['engagement_score'] ?? null),
            'trust_score' => $this->normalize_score($audit_data['trust_score'] ?? null),
            'overall_score' => $this->normalize_score($audit_data['overall_score'] ?? null),
            'suggestions' => $audit_data['suggestions'] ?? [],
            'functionality_suggestions' => $audit_data['functionality_suggestions'] ?? [],
            'rewrites' => $audit_data['rewrites'] ?? [],
            'analysis_method' => $audit_data['analysis_method'] ?? 'single',
            'sections_analyzed' => intval($audit_data['sections_analyzed'] ?? 1),
            'ai_used' => true
        ];
        
        // Send to Supabase
        $response = wp_remote_post($this->supabase_url . '/rest/v1/audits', [
            'headers' => [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->api_key,
                'Prefer' => 'return=minimal'
            ],
            'body' => json_encode($supabase_data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('ConversionIQ Sync Error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 201) {
            error_log('ConversionIQ Sync Failed: Status ' . $status_code);
            error_log('Response: ' . wp_remote_retrieve_body($response));
            return false;
        }
        
        return true;
    }
    
    /**
     * Normalize score value to integer or null
     * 
     * @param mixed $score Score value
     * @return int|null Normalized score
     */
    private function normalize_score($score) {
        if ($score === null || $score === '') {
            return null;
        }
        return intval($score);
    }
    
    /**
     * Fetch case studies from Supabase to enhance AI recommendations
     * 
     * @param string|null $industry Filter by industry
     * @return array Case studies
     */
    public function fetch_case_studies($industry = null) {
        if (!$this->supabase_anon_key) {
            return [];
        }
        
        $url = $this->supabase_url . '/rest/v1/case_studies?is_public=eq.true&select=*';
        
        // Add industry filter if provided
        if ($industry) {
            $url .= '&industry=ilike.' . urlencode('%' . $industry . '%');
        }
        
        $response = wp_remote_get($url, [
            'headers' => [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            error_log('ConversionIQ: Failed to fetch case studies - ' . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $case_studies = json_decode($body, true);
        
        return is_array($case_studies) ? $case_studies : [];
    }
    
    /**
     * Track API usage for analytics and billing
     * 
     * @param string $endpoint Endpoint name
     * @return bool Success status
     */
    public function track_usage($endpoint = 'analyze_page') {
        if (!$this->organization_id || !$this->supabase_anon_key) {
            return false;
        }
        
        // Fire and forget - don't wait for response
        wp_remote_post($this->supabase_url . '/rest/v1/api_usage', [
            'headers' => [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=minimal'
            ],
            'body' => json_encode([
                'organization_id' => $this->organization_id,
                'endpoint' => $endpoint,
                'request_count' => 1,
                'date' => current_time('Y-m-d')
            ]),
            'timeout' => 5,
            'blocking' => false // Non-blocking request
        ]);
        
        return true;
    }
    
    /**
     * Check if the organization is at or over the monthly audit limit
     * 
     * @return bool True if over limit
     */
    public function is_over_limit() {
        if (!$this->organization_id || !$this->supabase_anon_key) {
            return false; // Allow if not configured
        }
        
        // Get organization details
        $response = wp_remote_get(
            $this->supabase_url . '/rest/v1/organizations?id=eq.' . $this->organization_id . '&select=max_audits_per_month',
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]
        );
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $max_audits = isset($body[0]['max_audits_per_month']) ? intval($body[0]['max_audits_per_month']) : 10;
        
        // Count audits this month
        $start_of_month = date('Y-m-01');
        $response = wp_remote_get(
            $this->supabase_url . '/rest/v1/audits?organization_id=eq.' . $this->organization_id . '&created_at=gte.' . $start_of_month . '&select=id',
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json',
                    'Range' => '0-0',
                    'Prefer' => 'count=exact'
                ],
                'timeout' => 10
            ]
        );
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $content_range = wp_remote_retrieve_header($response, 'content-range');
        if (preg_match('/\/(\d+)$/', $content_range, $matches)) {
            $current_count = intval($matches[1]);
            return $current_count >= $max_audits;
        }
        
        return false;
    }
    
    /**
     * Get organization statistics
     * 
     * @return array|null Organization stats
     */
    public function get_stats() {
        if (!$this->organization_id || !$this->supabase_anon_key) {
            return null;
        }
        
        $response = wp_remote_get(
            $this->supabase_url . '/rest/v1/organizations?id=eq.' . $this->organization_id . '&select=*',
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]
        );
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body[0]) ? $body[0] : null;
    }
    
    /**
     * Check if an organization exists in Supabase
     * 
     * @param string $organization_id Organization ID
     * @return bool True if exists, false otherwise
     */
    public function organization_exists($organization_id) {
        if (!$this->supabase_anon_key || !$organization_id) {
            return false;
        }
        
        $response = wp_remote_get(
            $this->supabase_url . '/rest/v1/organizations?id=eq.' . urlencode($organization_id) . '&select=id',
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]
        );
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($body);
    }
    
    /**
     * Get organization data from Supabase
     * 
     * @param string $organization_id Organization ID
     * @return array|null Organization data or null on failure
     */
    public function get_organization($organization_id) {
        if (!$this->supabase_anon_key || !$organization_id) {
            error_log('ConversionIQ: Cannot get organization - missing credentials or ID');
            return null;
        }
        
        $response = wp_remote_get(
            $this->supabase_url . '/rest/v1/organizations?id=eq.' . urlencode($organization_id),
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]
        );
        
        if (is_wp_error($response)) {
            error_log('ConversionIQ: Failed to get organization - ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('ConversionIQ: Failed to get organization - status ' . $status_code);
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body[0]) ? $body[0] : null;
    }
    
    /**
     * Create a new organization in Supabase
     * 
     * @param array $org_data Organization data
     * @return array|null Created organization data or null on failure
     */
    public function create_organization($org_data) {
        if (!$this->supabase_anon_key) {
            error_log('ConversionIQ: Cannot create organization - Supabase credentials not configured');
            error_log('Supabase URL: ' . ($this->supabase_url ? $this->supabase_url : 'NOT SET'));
            return null;
        }
        
        error_log('ConversionIQ: Creating organization with data: ' . wp_json_encode($org_data));
        
        $response = wp_remote_post(
            $this->supabase_url . '/rest/v1/organizations',
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json',
                    'Prefer' => 'return=representation'
                ],
                'body' => json_encode($org_data),
                'timeout' => 15
            ]
        );
        
        if (is_wp_error($response)) {
            error_log('ConversionIQ: Organization creation failed - ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('ConversionIQ: Create organization response status: ' . $status_code);
        error_log('ConversionIQ: Create organization response body: ' . $body);
        
        if ($status_code !== 201) {
            error_log('ConversionIQ: Organization creation failed with status ' . $status_code);
            return null;
        }
        
        $parsed_body = json_decode($body, true);
        return isset($parsed_body[0]) ? $parsed_body[0] : null;
    }
    
    /**
     * Check if username or email conflicts with another organization
     * 
     * @param string $username Username to check
     * @param string $email Email to check
     * @param string $exclude_org_id Organization ID to exclude from check
     * @return string|null Error message if conflict exists, null otherwise
     */
    public function check_account_conflict($username, $email, $exclude_org_id) {
        if (!$this->supabase_anon_key) {
            return 'Configuration error';
        }
        
        // Build query with proper exclusion
        $username_query = $this->supabase_url . '/rest/v1/organizations?username=eq.' . urlencode($username) . '&select=id';
        if (!empty($exclude_org_id)) {
            $username_query .= '&id=neq.' . urlencode($exclude_org_id);
        }
        
        // Check username
        $response = wp_remote_get(
            $username_query,
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]
        );
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body)) {
                return 'Username is already taken';
            }
        }
        
        // Build query with proper exclusion for email
        $email_query = $this->supabase_url . '/rest/v1/organizations?user_email=eq.' . urlencode($email) . '&select=id';
        if (!empty($exclude_org_id)) {
            $email_query .= '&id=neq.' . urlencode($exclude_org_id);
        }
        
        // Check email
        $response = wp_remote_get(
            $email_query,
            [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]
        );
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body)) {
                return 'Email is already in use';
            }
        }
        
        return null;
    }
    
    /**
     * Update organization information in Supabase
     *      * Validate a license key directly against Supabase ciq_licenses + ciq_customers.
     *
     * @param string $license_key
     * @return array { valid: bool, message: string, customer: array|null }
     */
    public function validate_license( $license_key ) {
        if ( ! $this->supabase_anon_key ) {
            return array( 'valid' => false, 'message' => 'License server not configured.' );
        }

        $url = $this->supabase_url
            . '/rest/v1/ciq_licenses'
            . '?license_key=eq.' . urlencode( $license_key )
            . '&select=id,license_key,status,expires_at,ciq_customers(id,name,email,company,plan)';

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'ConversionIQ: License validation failed - ' . $response->get_error_message() );
            return array( 'valid' => false, 'message' => 'Could not reach the license server. Please try again.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        error_log( 'ConversionIQ: License validation response ' . $code . ' - ' . wp_json_encode( $body ) );

        if ( $code !== 200 || ! is_array( $body ) ) {
            return array( 'valid' => false, 'message' => 'License validation failed. Please try again.' );
        }

        if ( empty( $body ) ) {
            return array( 'valid' => false, 'message' => 'License key not found. Please check your key and try again.' );
        }

        $license = $body[0];
        $status  = strtolower( $license['status'] ?? 'inactive' );

        if ( ! in_array( $status, array( 'active', 'trial' ), true ) ) {
            return array( 'valid' => false, 'message' => 'This license is ' . $status . '. Please contact support.' );
        }

        if ( ! empty( $license['expires_at'] ) ) {
            $expires = strtotime( $license['expires_at'] );
            if ( $expires !== false && $expires < time() ) {
                return array( 'valid' => false, 'message' => 'This license expired on ' . date( 'F j, Y', $expires ) . '. Please renew at conversioniq-app.com.' );
            }
        }

        $customer  = null;
        $cust_data = $license['ciq_customers'] ?? null;
        if ( is_array( $cust_data ) ) {
            $customer = array(
                'name'    => sanitize_text_field( $cust_data['name'] ?? '' ),
                'email'   => sanitize_email( $cust_data['email'] ?? '' ),
                'company' => sanitize_text_field( $cust_data['company'] ?? '' ),
                'plan'    => sanitize_text_field( $cust_data['plan'] ?? '' ),
            );
        }

        return array(
            'valid'    => true,
            'message'  => 'License activated successfully!',
            'customer' => $customer,
        );
    }

    /**     * @param string $organization_id Organization ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public function update_organization($organization_id, $data) {
        if (!$this->supabase_anon_key || !$organization_id) {
            error_log('ConversionIQ: Cannot update organization - missing credentials or ID');
            error_log('Supabase URL: ' . ($this->supabase_url ? 'SET' : 'NOT SET'));
            error_log('Supabase Key: ' . ($this->supabase_anon_key ? 'SET' : 'NOT SET'));
            error_log('Organization ID: ' . $organization_id);
            return false;
        }
        
        $url = $this->supabase_url . '/rest/v1/organizations?id=eq.' . urlencode($organization_id);
        error_log('ConversionIQ: Updating organization - URL: ' . $url);
        error_log('ConversionIQ: Update data: ' . wp_json_encode($data));
        
        $response = wp_remote_request(
            $url,
            [
                'method' => 'PATCH',
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json',
                    'Prefer' => 'return=representation'
                ],
                'body' => json_encode($data),
                'timeout' => 15
            ]
        );
        
        if (is_wp_error($response)) {
            error_log('ConversionIQ: Organization update failed - ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('ConversionIQ: Update response status: ' . $status_code);
        error_log('ConversionIQ: Update response body: ' . $response_body);
        
        if ($status_code !== 200) {
            error_log('ConversionIQ: Organization update failed with status ' . $status_code);
            return false;
        }
        
        return true;
    }
}
