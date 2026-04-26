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
            ciq_log('ConversionIQ: Cannot register - Supabase credentials not configured');
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
            ciq_log('ConversionIQ Registration Error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 201) {
            ciq_log('ConversionIQ Registration Failed: Status ' . $status_code);
            ciq_log('Response: ' . wp_remote_retrieve_body($response));
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body[0]['api_key']) && isset($body[0]['id'])) {
            update_option('conversioniq_api_key', $body[0]['api_key']);
            update_option('conversioniq_organization_id', $body[0]['id']);
            $this->api_key = $body[0]['api_key'];
            $this->organization_id = $body[0]['id'];
            
            ciq_log('ConversionIQ: Successfully registered as organization ' . $this->organization_id);
            return true;
        }
        
        return false;
    }
    
    /**
     * Ensure this site is registered as an organization in Supabase.
     * Creates a new org if none exists; falls back to domain lookup on conflict.
     *
     * @return bool
     */
    private function ensure_organization() {
        $domain  = parse_url(get_site_url(), PHP_URL_HOST);
        $account = get_option('conversioniq_account', null);

        $org_data = [
            'name'                 => get_bloginfo('name') ?: 'WordPress Site',
            'domain'               => $domain,
            'api_key'              => $this->generate_api_key(),
            'plan'                 => 'free',
            'max_audits_per_month' => 10,
        ];
        if ($account && is_array($account)) {
            $org_data['user_full_name'] = $account['full_name'] ?? null;
            $org_data['user_email']     = $account['email']     ?? null;
            $org_data['company_name']   = $account['company']   ?? null;
            $org_data['username']       = $account['username']  ?? null;
        }

        $response = wp_remote_post($this->supabase_url . '/rest/v1/organizations', [
            'headers' => [
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ],
            'body'    => json_encode($org_data),
            'timeout' => 30,
        ]);

        if (!is_wp_error($response)) {
            $status = wp_remote_retrieve_response_code($response);
            $body   = json_decode(wp_remote_retrieve_body($response), true);

            if ($status === 201 && isset($body[0]['id'])) {
                update_option('conversioniq_organization_id', $body[0]['id']);
                $this->organization_id = $body[0]['id'];
                ciq_log('ConversionIQ: Auto-registered as org ' . $this->organization_id);
                return true;
            }

            // 409 = unique conflict (domain already exists) — look it up instead
            if ($status === 409 || $status === 422 || $status === 400) {
                ciq_log('ConversionIQ: Org INSERT conflict (HTTP ' . $status . ') — looking up existing org by domain');
                return $this->lookup_organization_by_domain($domain);
            }

            ciq_log('ConversionIQ: Org registration returned HTTP ' . $status . ' — ' . wp_remote_retrieve_body($response));
        } else {
            ciq_log('ConversionIQ: Org registration request failed — ' . $response->get_error_message());
        }

        // Last-resort fallback: try to find the org anyway
        return $this->lookup_organization_by_domain($domain);
    }

    /**
     * Look up an existing organization by domain and cache its ID.
     *
     * @param string $domain
     * @return bool
     */
    private function lookup_organization_by_domain($domain) {
        // Try multiple formats: the passed domain, the full site URL with/without trailing slash
        $site_url   = get_site_url();
        $candidates = array_unique( [
            $domain,
            rtrim( $site_url, '/' ) . '/',
            rtrim( $site_url, '/' ),
            parse_url( $site_url, PHP_URL_HOST ),
        ] );

        foreach ( $candidates as $candidate ) {
            $url = $this->supabase_url . '/rest/v1/organizations?domain=eq.' . rawurlencode( $candidate ) . '&select=id&limit=1';

            $response = wp_remote_get( $url, [
                'headers' => [
                    'apikey'        => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'X-API-Key'     => $this->api_key,
                ],
                'timeout' => 15,
            ] );

            if ( is_wp_error( $response ) ) {
                ciq_log( 'ConversionIQ: Org lookup failed — ' . $response->get_error_message() );
                continue;
            }

            $status = wp_remote_retrieve_response_code( $response );
            $body   = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $status === 200 && is_array( $body ) && isset( $body[0]['id'] ) ) {
                update_option( 'conversioniq_organization_id', $body[0]['id'] );
                $this->organization_id = $body[0]['id'];
                ciq_log( 'ConversionIQ: Found existing org ' . $this->organization_id . ' for domain ' . $candidate );
                return true;
            }
        }

        ciq_log( 'ConversionIQ: Could not find org for any domain candidate derived from ' . $site_url );
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
            ciq_log('ConversionIQ: Cannot validate login - Supabase not configured');
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
            ciq_log('ConversionIQ: Failed to validate login - ' . $response->get_error_message());
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
     * Fetch business profile fields from the Supabase organizations row.
     *
     * @return array|null Associative array of profile fields, or null on failure.
     */
    public function fetch_business_profile() {
        if ( ! $this->supabase_anon_key ) {
            ciq_log( 'ConversionIQ: fetch_business_profile — no anon key configured' );
            return null;
        }

        // If we don't have a cached org ID, resolve it from Supabase by domain.
        // The domain stored in Supabase may be a full URL or bare hostname, so try all formats.
        if ( ! $this->organization_id ) {
            $site_url   = get_site_url();
            $candidates = [
                rtrim( $site_url, '/' ) . '/',          // https://example.com/
                rtrim( $site_url, '/' ),                 // https://example.com
                parse_url( $site_url, PHP_URL_HOST ),    // example.com
            ];

            foreach ( $candidates as $candidate ) {
                $lookup_url = $this->supabase_url . '/rest/v1/organizations?domain=eq.'
                    . rawurlencode( $candidate ) . '&select=id&limit=1';

                $lookup = wp_remote_get( $lookup_url, [
                    'headers' => [
                        'apikey'        => $this->supabase_anon_key,
                        'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                        'X-API-Key'     => $this->api_key,
                    ],
                    'timeout' => 10,
                ] );

                if ( ! is_wp_error( $lookup ) ) {
                    $lookup_body = json_decode( wp_remote_retrieve_body( $lookup ), true );
                    if ( wp_remote_retrieve_response_code( $lookup ) === 200
                         && is_array( $lookup_body ) && ! empty( $lookup_body[0]['id'] ) ) {
                        $this->organization_id = $lookup_body[0]['id'];
                        update_option( 'conversioniq_organization_id', $this->organization_id );
                        ciq_log( 'ConversionIQ: fetch_business_profile resolved org ID via domain lookup (' . $candidate . ')' );
                        break;
                    }
                }
            }

            // Fallback: look up by the API key stored from license activation
            if ( ! $this->organization_id && $this->api_key ) {
                $key_url  = $this->supabase_url . '/rest/v1/organizations?api_key=eq.'
                    . rawurlencode( $this->api_key ) . '&select=id&limit=1';
                $key_resp = wp_remote_get( $key_url, [
                    'headers' => [
                        'apikey'        => $this->supabase_anon_key,
                        'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                        'X-API-Key'     => $this->api_key,
                    ],
                    'timeout' => 10,
                ] );
                if ( ! is_wp_error( $key_resp ) ) {
                    $key_body = json_decode( wp_remote_retrieve_body( $key_resp ), true );
                    if ( wp_remote_retrieve_response_code( $key_resp ) === 200
                         && is_array( $key_body ) && ! empty( $key_body[0]['id'] ) ) {
                        $this->organization_id = $key_body[0]['id'];
                        update_option( 'conversioniq_organization_id', $this->organization_id );
                        ciq_log( 'ConversionIQ: fetch_business_profile resolved org ID via api_key lookup' );
                    }
                }
            }

            if ( ! $this->organization_id ) {
                ciq_log( 'ConversionIQ: fetch_business_profile — could not resolve organization ID for ' . get_site_url() );
                return null;
            }
        }

        $fields = 'business_name,industry,product,audience,pain_points,competitors,goal,additional_info,unique_selling_points,target_geography,price_point,primary_traffic_source';
        $url    = $this->supabase_url . '/rest/v1/organizations?id=eq.' . rawurlencode( $this->organization_id ) . '&select=' . $fields . '&limit=1';

        $response = wp_remote_get( $url, [
            'headers' => [
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'X-API-Key'     => $this->api_key,
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            ciq_log( 'ConversionIQ: fetch_business_profile request failed — ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status === 200 && is_array( $body ) && ! empty( $body ) ) {
            return $body[0];
        }

        ciq_log( 'ConversionIQ: fetch_business_profile returned HTTP ' . $status . ' — ' . wp_remote_retrieve_body( $response ) );
        return null;
    }

    /**
     * Save business profile fields to the Supabase organizations row via PATCH.
     *
     * @param array $profile Associative array of profile fields to update.
     * @return bool True on success, false on failure.
     */
    public function save_business_profile( array $profile ) {
        if ( ! $this->supabase_anon_key ) {
            ciq_log( 'ConversionIQ: save_business_profile skipped — no anon key' );
            return false;
        }

        if ( ! $this->organization_id && ! $this->fetch_business_profile() ) {
            ciq_log( 'ConversionIQ: save_business_profile skipped — could not resolve org ID' );
            return false;
        }

        $allowed = [ 'business_name', 'industry', 'product', 'audience', 'pain_points', 'competitors', 'goal', 'additional_info', 'unique_selling_points', 'target_geography', 'price_point', 'primary_traffic_source' ];
        $patch   = [];
        foreach ( $allowed as $field ) {
            if ( array_key_exists( $field, $profile ) ) {
                $patch[ $field ] = $profile[ $field ];
            }
        }

        if ( empty( $patch ) ) {
            return true;
        }

        $response = wp_remote_request(
            $this->supabase_url . '/rest/v1/organizations?id=eq.' . urlencode( $this->organization_id ),
            [
                'method'  => 'PATCH',
                'headers' => [
                    'apikey'        => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type'  => 'application/json',
                    'X-API-Key'     => $this->api_key,
                    'Prefer'        => 'return=minimal',
                ],
                'body'    => json_encode( $patch ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            ciq_log( 'ConversionIQ: save_business_profile PATCH failed — ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 && $status !== 204 ) {
            ciq_log( 'ConversionIQ: save_business_profile PATCH returned HTTP ' . $status );
            return false;
        }

        return true;
    }

    /**
     * Send audit data to Supabase.
     *
     * Two-phase approach:
     *   Phase 1 — INSERT core scalar fields + report_token (proven reliable path).
     *   Phase 2 — PATCH the JSONB report fields onto the same row (best-effort;
     *             failure here keeps the token alive so the report URL still resolves).
     *
     * @param array $audit_data The complete audit data
     * @return string|false report_token string on success, false on failure
     */
    public function send_audit($audit_data) {
        // Anon key is baked into the plugin — if it's missing something is very wrong
        if (!$this->supabase_anon_key) {
            ciq_log('ConversionIQ: Cannot sync audit - Supabase credentials not configured');
            return false;
        }

        // Auto-register this site if we don't have an organization_id yet
        if (!$this->organization_id && !$this->ensure_organization()) {
            ciq_log('ConversionIQ: Cannot sync audit - failed to obtain organization_id');
            return false;
        }

        $token = $audit_data['report_token'] ?? null;

        // ── Phase 1: Core INSERT (same fields that worked before + token + plan) ──
        $core_data = [
            'organization_id'           => $this->organization_id,
            'page_url'                  => $audit_data['page_url'] ?? '',
            'page_title'                => $audit_data['page_title'] ?? null,
            'industry'                  => $audit_data['industry'] ?? null,
            'clarity_score'             => $this->normalize_score($audit_data['clarity_score'] ?? null),
            'emotional_score'           => $this->normalize_score($audit_data['emotional_score'] ?? null),
            'cta_strength'              => $this->normalize_score($audit_data['cta_strength'] ?? null),
            'readability_score'         => $this->normalize_score($audit_data['readability_score'] ?? null),
            'engagement_score'          => $this->normalize_score($audit_data['engagement_score'] ?? null),
            'trust_score'               => $this->normalize_score($audit_data['trust_score'] ?? null),
            'overall_score'             => $this->normalize_score($audit_data['overall_score'] ?? null),
            'suggestions'               => $audit_data['suggestions'] ?? [],
            'functionality_suggestions' => $audit_data['functionality_suggestions'] ?? [],
            'rewrites'                  => $audit_data['rewrites'] ?? [],
            'analysis_method'           => $audit_data['analysis_method'] ?? 'single',
            'sections_analyzed'         => intval($audit_data['sections_analyzed'] ?? 1),
            'ai_used'                   => true,
            'report_token'              => $token,
            'plan'                      => $audit_data['plan'] ?? 'free',
        ];

        $response = wp_remote_post($this->supabase_url . '/rest/v1/audits', [
            'headers' => [
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type'  => 'application/json',
                'X-API-Key'     => $this->api_key,
                'Prefer'        => 'return=minimal',
            ],
            'body'    => json_encode($core_data),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            ciq_log('ConversionIQ Sync Error: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 201) {
            ciq_log('ConversionIQ Sync Failed (Phase 1): Status ' . $status_code);
            ciq_log('ConversionIQ Sync Response: ' . wp_remote_retrieve_body($response));
            return false;
        }

        // ── Phase 2: PATCH JSONB report fields (best-effort, non-blocking on failure) ──
        if ($token) {
            $jsonb_data = [
                'insights'           => $audit_data['insights'] ?? null,
                'recommendations'    => $audit_data['recommendations'] ?? null,
                'benchmark_research' => $audit_data['benchmark_research'] ?? null,
                'business_context'   => $audit_data['business_context'] ?? null,
                'lead_intelligence'  => $audit_data['lead_intelligence'] ?? null,
                'cro_checklist'      => $audit_data['cro_checklist'] ?? null,
            ];

            $patch_body = json_encode($jsonb_data);
            if ($patch_body !== false) {
                $patch_response = wp_remote_request(
                    $this->supabase_url . '/rest/v1/audits?report_token=eq.' . urlencode($token),
                    [
                        'method'  => 'PATCH',
                        'headers' => [
                            'apikey'        => $this->supabase_anon_key,
                            'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                            'Content-Type'  => 'application/json',
                            'X-API-Key'     => $this->api_key,
                            'Prefer'        => 'return=minimal',
                        ],
                        'body'    => $patch_body,
                        'timeout' => 15,
                    ]
                );

                if (is_wp_error($patch_response)) {
                    ciq_log('ConversionIQ Sync Warning (Phase 2 PATCH): ' . $patch_response->get_error_message());
                } else {
                    $patch_status = wp_remote_retrieve_response_code($patch_response);
                    if ($patch_status !== 200 && $patch_status !== 204) {
                        ciq_log('ConversionIQ Sync Warning (Phase 2 PATCH): Status ' . $patch_status);
                        ciq_log('ConversionIQ PATCH Response: ' . wp_remote_retrieve_body($patch_response));
                    }
                }
            } else {
                ciq_log('ConversionIQ Sync Warning: Could not JSON-encode JSONB fields for PATCH');
            }
        }

        return $token ?? true;
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
     * Fetch audit history for a specific page from Supabase.
     *
     * Returns all audits for this organization + page_url ordered oldest-first,
     * with only the score columns and cro_checklist needed for trajectory charts.
     *
     * @param string $page_url The page URL to query
     * @return array|false Array of audit rows on success, false on failure
     */
    public function get_audit_history($page_url) {
        if (!$this->supabase_anon_key) {
            return false;
        }

        if (!$this->organization_id && !$this->ensure_organization()) {
            return false;
        }

        $select = 'id,created_at,overall_score,clarity_score,emotional_score,cta_strength,readability_score,engagement_score,trust_score,cro_checklist';

        $url = $this->supabase_url . '/rest/v1/audits'
            . '?organization_id=eq.' . urlencode($this->organization_id)
            . '&page_url=eq.' . urlencode($page_url)
            . '&select=' . $select
            . '&order=created_at.asc';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'X-API-Key'     => $this->api_key,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            ciq_log('ConversionIQ audit_history error: ' . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            ciq_log('ConversionIQ audit_history non-200: ' . $status . ' — ' . wp_remote_retrieve_body($response));
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : false;
    }

    /**
     * Fetch all audits for this organization from Supabase.
     * Used to restore audit history after plugin reinstall.
     *
     * @param int $limit Maximum rows to return (default 100)
     * @return array|false Array of audit rows or false on failure
     */
    public function get_all_audits($limit = 100) {
        if (!$this->supabase_anon_key) {
            return false;
        }

        if (!$this->organization_id && !$this->ensure_organization()) {
            return false;
        }

        $select = 'id,created_at,page_url,overall_score,clarity_score,emotional_score,' .
                  'cta_strength,readability_score,engagement_score,trust_score,' .
                  'cro_checklist,insights,recommendations,report_token';

        $url = $this->supabase_url . '/rest/v1/audits'
            . '?organization_id=eq.' . urlencode($this->organization_id)
            . '&select=' . $select
            . '&order=created_at.desc'
            . '&limit=' . (int) $limit;

        $response = wp_remote_get($url, array(
            'headers' => array(
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'X-API-Key'     => $this->api_key,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            ciq_log('ConversionIQ get_all_audits error: ' . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            ciq_log('ConversionIQ get_all_audits non-200: ' . $status . ' — ' . wp_remote_retrieve_body($response));
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : false;
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
            ciq_log('ConversionIQ: Failed to fetch case studies - ' . $response->get_error_message());
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
            ciq_log('ConversionIQ: Cannot get organization - missing credentials or ID');
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
            ciq_log('ConversionIQ: Failed to get organization - ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            ciq_log('ConversionIQ: Failed to get organization - status ' . $status_code);
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
            ciq_log('ConversionIQ: Cannot create organization - Supabase credentials not configured');
            ciq_log('Supabase URL: ' . ($this->supabase_url ? $this->supabase_url : 'NOT SET'));
            return null;
        }
        
        ciq_log('ConversionIQ: Creating organization with data: ' . wp_json_encode($org_data));
        
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
            ciq_log('ConversionIQ: Organization creation failed - ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        ciq_log('ConversionIQ: Create organization response status: ' . $status_code);
        ciq_log('ConversionIQ: Create organization response body: ' . $body);
        
        if ($status_code !== 201) {
            ciq_log('ConversionIQ: Organization creation failed with status ' . $status_code);
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
            ciq_log( 'ConversionIQ: License validation failed - ' . $response->get_error_message() );
            return array( 'valid' => false, 'message' => 'Could not reach the license server. Please try again.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        ciq_log( 'ConversionIQ: License validation response ' . $code . ' - ' . wp_json_encode( $body ) );

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
    /**
     * Push tracked pages to Supabase organizations.tracked_pages.
     * Called whenever the admin saves the tracked pages list.     *
     * @param array $page_ids Array of WP post IDs to track
     * @return bool
     */
    public function push_tracked_pages( $page_ids ) {
        if ( ! $this->supabase_anon_key || ! $this->organization_id ) {
            ciq_log( 'ConversionIQ: Cannot push tracked pages - missing credentials or org ID' );
            return false;
        }

        $pages_data = array();
        foreach ( $page_ids as $pid ) {
            $post = get_post( absint( $pid ) );
            if ( $post && $post->post_status === 'publish' ) {
                $pages_data[] = array(
                    'id'    => $post->ID,
                    'title' => $post->post_title,
                    'url'   => get_permalink( $post ),
                );
            }
        }

        $response = wp_remote_request(
            $this->supabase_url . '/rest/v1/organizations?id=eq.' . urlencode( $this->organization_id ),
            array(
                'method'  => 'PATCH',
                'headers' => array(
                    'apikey'        => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type'  => 'application/json',
                    'X-API-Key'     => $this->api_key,
                    'Prefer'        => 'return=minimal',
                ),
                'body'    => json_encode( array( 'tracked_pages' => $pages_data ) ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            ciq_log( 'ConversionIQ: Failed to push tracked pages - ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 && $status !== 204 ) {
            ciq_log( 'ConversionIQ: push_tracked_pages returned HTTP ' . $status );
            return false;
        }

        ciq_log( 'ConversionIQ: Tracked pages pushed to Supabase (' . count( $pages_data ) . ' pages)' );
        return true;
    }

    /**
     * Push remote audit credentials (secret key + endpoint URL) to Supabase organizations row.
     * Called automatically on plugin/license activation — no UI needed.
     *
     * @return bool
     */
    public function push_remote_credentials() {
        if ( ! $this->supabase_anon_key || ! $this->organization_id ) {
            ciq_log( 'ConversionIQ: Cannot push remote credentials - missing credentials or org ID' );
            return false;
        }

        // Ensure remote secret exists — generate one if not yet set.
        $secret = get_option( 'conversioniq_remote_secret', '' );
        if ( empty( $secret ) ) {
            $secret = 'ciq_' . bin2hex( random_bytes( 24 ) );
            update_option( 'conversioniq_remote_secret', $secret );
            ciq_log( 'ConversionIQ: Remote secret generated and stored' );
        }

        $endpoint = get_site_url() . '/wp-json/conversioniq/v1/remote-audit';

        // Build tracked pages list.
        // Use the stored list if one exists; otherwise default to all published pages on this site.
        $tracked_ids = get_option( 'conversioniq_tracked_pages', array() );

        if ( empty( $tracked_ids ) ) {
            $all_pages = get_posts( array(
                'post_type'   => array( 'page', 'post' ),
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby'     => 'post_title',
                'order'       => 'ASC',
            ) );
            $tracked_ids = wp_list_pluck( $all_pages, 'ID' );
            // Persist so future calls (and the GET /tracked-pages endpoint) stay consistent
            update_option( 'conversioniq_tracked_pages', $tracked_ids );
        }

        $pages_data = array();
        foreach ( $tracked_ids as $pid ) {
            $post = get_post( absint( $pid ) );
            if ( $post && $post->post_status === 'publish' ) {
                $pages_data[] = array(
                    'id'    => $post->ID,
                    'title' => $post->post_title,
                    'url'   => get_permalink( $post ),
                );
            }
        }

        $response = wp_remote_request(
            $this->supabase_url . '/rest/v1/organizations?id=eq.' . urlencode( $this->organization_id ),
            array(
                'method'  => 'PATCH',
                'headers' => array(
                    'apikey'        => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type'  => 'application/json',
                    'X-API-Key'     => $this->api_key,
                    'Prefer'        => 'return=minimal',
                ),
                'body'    => json_encode( array(
                    'remote_secret' => $secret,
                    'endpoint'      => $endpoint,
                    'tracked_pages' => $pages_data,
                ) ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            ciq_log( 'ConversionIQ: Failed to push remote credentials - ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 && $status !== 204 ) {
            ciq_log( 'ConversionIQ: push_remote_credentials returned HTTP ' . $status );
            return false;
        }

        ciq_log( 'ConversionIQ: Remote credentials pushed to Supabase (endpoint: ' . $endpoint . ', pages: ' . count( $pages_data ) . ')' );
        return true;
    }

    public function update_organization($organization_id, $data) {
        if (!$this->supabase_anon_key || !$organization_id) {
            ciq_log('ConversionIQ: Cannot update organization - missing credentials or ID');
            ciq_log('Supabase URL: ' . ($this->supabase_url ? 'SET' : 'NOT SET'));
            ciq_log('Supabase Key: ' . ($this->supabase_anon_key ? 'SET' : 'NOT SET'));
            ciq_log('Organization ID: ' . $organization_id);
            return false;
        }
        
        $url = $this->supabase_url . '/rest/v1/organizations?id=eq.' . urlencode($organization_id);
        ciq_log('ConversionIQ: Updating organization - URL: ' . $url);
        ciq_log('ConversionIQ: Update data: ' . wp_json_encode($data));
        
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
            ciq_log('ConversionIQ: Organization update failed - ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        ciq_log('ConversionIQ: Update response status: ' . $status_code);
        ciq_log('ConversionIQ: Update response body: ' . $response_body);
        
        if ($status_code !== 200) {
            ciq_log('ConversionIQ: Organization update failed with status ' . $status_code);
            return false;
        }
        
        return true;
    }

    /**
     * Fetch the oldest pending audit job for this organization from Supabase.
     * Returns the job row as an associative array, or null if none pending.
     *
     * @return array|null
     */
    public function fetch_pending_job() {
        if ( ! $this->supabase_anon_key || ! $this->organization_id ) {
            error_log( '[CIQ] fetch_pending_job: missing supabase_anon_key or organization_id — aborting' );
            return null;
        }

        $url = add_query_arg( array(
            'organization_id' => 'eq.' . $this->organization_id,
            'status'          => 'eq.pending',
            'order'           => 'created_at.asc',
            'limit'           => '1',
        ), $this->supabase_url . '/rest/v1/audit_jobs' );

        error_log( '[CIQ] fetch_pending_job: GET ' . $url );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'X-API-Key'     => $this->api_key,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[CIQ] fetch_pending_job: wp_error - ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        error_log( '[CIQ] fetch_pending_job: HTTP ' . $code . ' — ' . $body );

        $rows = json_decode( $body, true );
        return ( is_array( $rows ) && ! empty( $rows ) ) ? $rows[0] : null;
    }

    /**
     * Update the status of an audit job row.
     *
     * @param string $job_id   UUID of the audit_jobs row
     * @param array  $data     Fields to PATCH
     * @return bool
     */
    private function update_job( $job_id, $data ) {
        if ( ! $this->supabase_anon_key ) return false;

        $patch_url = $this->supabase_url . '/rest/v1/audit_jobs?id=eq.' . urlencode( $job_id );
        error_log( '[CIQ] update_job: PATCH ' . $patch_url . ' — ' . json_encode( $data ) );

        $response = wp_remote_request(
            $patch_url,
            array(
                'method'  => 'PATCH',
                'headers' => array(
                    'apikey'        => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type'  => 'application/json',
                    'X-API-Key'     => $this->api_key,
                    'Prefer'        => 'return=minimal',
                ),
                'body'    => json_encode( $data ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[CIQ] update_job: wp_error - ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        error_log( '[CIQ] update_job: HTTP ' . $code . ' — ' . ( $body ?: '(empty)' ) );

        return true;
    }
        }
        return true;
    }

    /**
     * Mark an audit job as running (claim it so no other instance picks it up).
     *
     * @param string $job_id
     * @return bool
     */
    public function mark_job_running( $job_id ) {
        return $this->update_job( $job_id, array(
            'status' => 'running',
        ) );
    }

    /**
     * Mark an audit job as successfully completed.
     *
     * @param string $job_id
     * @return bool
     */
    public function mark_job_complete( $job_id ) {
        return $this->update_job( $job_id, array(
            'status'       => 'complete',
            'completed_at' => gmdate( 'c' ),
        ) );
    }

    /**
     * Mark an audit job as failed with an error message.
     *
     * @param string $job_id
     * @param string $error_message
     * @return bool
     */
    public function mark_job_failed( $job_id, $error_message = '' ) {
        return $this->update_job( $job_id, array(
            'status'       => 'failed',
            'completed_at' => gmdate( 'c' ),
        ) );
    }
}
