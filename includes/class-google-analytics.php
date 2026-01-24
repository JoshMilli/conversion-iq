<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Analytics Integration
 * Handles OAuth2 authentication and data retrieval from Google Analytics 4
 */
class ConversionIQ_Google_Analytics {
    
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $access_token;
    private $refresh_token;
    private $property_id;
    
    public function __construct() {
        $this->load_credentials();
    }
    
    /**
     * Load stored credentials from WordPress options
     */
    private function load_credentials() {
        $options = get_option('conversioniq_ga_credentials', array());
        $this->client_id = $options['client_id'] ?? '';
        $this->client_secret = $options['client_secret'] ?? '';
        $this->access_token = $options['access_token'] ?? '';
        $this->refresh_token = $options['refresh_token'] ?? '';
        $this->property_id = $options['property_id'] ?? '';
        $this->redirect_uri = admin_url('admin.php?page=conversioniq&ga_callback=1');
    }
    
    /**
     * Save credentials to WordPress options
     */
    private function save_credentials($data) {
        $options = get_option('conversioniq_ga_credentials', array());
        $options = array_merge($options, $data);
        update_option('conversioniq_ga_credentials', $options);
        $this->load_credentials();
    }
    
    /**
     * Check if GA is connected
     */
    public function is_connected() {
        return !empty($this->access_token) && !empty($this->property_id);
    }
    
    /**
     * Get OAuth2 authorization URL
     */
    public function get_auth_url() {
        if (empty($this->client_id)) {
            return '';
        }
        
        $params = array(
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent'
        );
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function exchange_code($code) {
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code' => $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri' => $this->redirect_uri,
                'grant_type' => 'authorization_code'
            )
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            $this->save_credentials(array(
                'access_token' => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? '',
                'token_expires' => time() + ($body['expires_in'] ?? 3600)
            ));
            return array('success' => true);
        }
        
        return array('success' => false, 'error' => $body['error_description'] ?? 'Failed to get access token');
    }
    
    /**
     * Refresh access token using refresh token
     */
    private function refresh_access_token() {
        if (empty($this->refresh_token)) {
            return false;
        }
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'refresh_token' => $this->refresh_token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token'
            )
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            $this->save_credentials(array(
                'access_token' => $body['access_token'],
                'token_expires' => time() + ($body['expires_in'] ?? 3600)
            ));
            return true;
        }
        
        return false;
    }
    
    /**
     * Make API request to Google Analytics
     */
    private function api_request($endpoint, $body = null) {
        $options = get_option('conversioniq_ga_credentials', array());
        $token_expires = $options['token_expires'] ?? 0;
        
        // Refresh token if expired
        if (time() >= $token_expires) {
            if (!$this->refresh_access_token()) {
                return array('error' => 'Token expired and refresh failed');
            }
        }
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($body) {
            $args['body'] = json_encode($body);
            $response = wp_remote_post($endpoint, $args);
        } else {
            $response = wp_remote_get($endpoint, $args);
        }
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    /**
     * Get list of available GA4 properties
     */
    public function get_properties() {
        $response = $this->api_request('https://analyticsadmin.googleapis.com/v1beta/accountSummaries');
        
        if (isset($response['error'])) {
            return array('success' => false, 'error' => $response['error']);
        }
        
        $properties = array();
        if (isset($response['accountSummaries'])) {
            foreach ($response['accountSummaries'] as $account) {
                if (isset($account['propertySummaries'])) {
                    foreach ($account['propertySummaries'] as $property) {
                        $properties[] = array(
                            'id' => $property['property'],
                            'name' => $property['displayName'],
                            'account' => $account['displayName']
                        );
                    }
                }
            }
        }
        
        return array('success' => true, 'properties' => $properties);
    }
    
    /**
     * Get conversion data for a specific page URL
     */
    public function get_page_conversions($page_url, $days = 30) {
        if (!$this->is_connected()) {
            return array('error' => 'Google Analytics not connected');
        }
        
        // Parse URL to get path
        $url_parts = parse_url($page_url);
        $page_path = $url_parts['path'] ?? '/';
        
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $request_body = array(
            'dateRanges' => array(
                array(
                    'startDate' => $start_date,
                    'endDate' => $end_date
                )
            ),
            'dimensions' => array(
                array('name' => 'pagePath')
            ),
            'metrics' => array(
                array('name' => 'screenPageViews'),
                array('name' => 'conversions'),
                array('name' => 'bounceRate'),
                array('name' => 'averageSessionDuration'),
                array('name' => 'engagementRate')
            ),
            'dimensionFilter' => array(
                'filter' => array(
                    'fieldName' => 'pagePath',
                    'stringFilter' => array(
                        'matchType' => 'EXACT',
                        'value' => $page_path
                    )
                )
            )
        );
        
        $response = $this->api_request(
            "https://analyticsdata.googleapis.com/v1beta/{$this->property_id}:runReport",
            $request_body
        );
        
        if (isset($response['error'])) {
            return array('error' => $response['error']['message'] ?? 'API request failed');
        }
        
        // Parse response
        $data = array(
            'pageViews' => 0,
            'conversions' => 0,
            'conversionRate' => 0,
            'bounceRate' => 0,
            'avgSessionDuration' => 0,
            'engagementRate' => 0
        );
        
        if (isset($response['rows']) && count($response['rows']) > 0) {
            $row = $response['rows'][0];
            $metrics = $row['metricValues'] ?? array();
            
            $data['pageViews'] = (int) ($metrics[0]['value'] ?? 0);
            $data['conversions'] = (int) ($metrics[1]['value'] ?? 0);
            $data['bounceRate'] = round((float) ($metrics[2]['value'] ?? 0) * 100, 2);
            $data['avgSessionDuration'] = round((float) ($metrics[3]['value'] ?? 0), 2);
            $data['engagementRate'] = round((float) ($metrics[4]['value'] ?? 0) * 100, 2);
            
            if ($data['pageViews'] > 0) {
                $data['conversionRate'] = round(($data['conversions'] / $data['pageViews']) * 100, 2);
            }
        }
        
        return array('success' => true, 'data' => $data, 'period' => "{$days} days");
    }
    
    /**
     * Get top converting pages
     */
    public function get_top_pages($limit = 10, $days = 30) {
        if (!$this->is_connected()) {
            return array('error' => 'Google Analytics not connected');
        }
        
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $request_body = array(
            'dateRanges' => array(
                array(
                    'startDate' => $start_date,
                    'endDate' => $end_date
                )
            ),
            'dimensions' => array(
                array('name' => 'pagePath'),
                array('name' => 'pageTitle')
            ),
            'metrics' => array(
                array('name' => 'screenPageViews'),
                array('name' => 'conversions'),
                array('name' => 'engagementRate')
            ),
            'orderBys' => array(
                array(
                    'metric' => array('metricName' => 'conversions'),
                    'desc' => true
                )
            ),
            'limit' => $limit
        );
        
        $response = $this->api_request(
            "https://analyticsdata.googleapis.com/v1beta/{$this->property_id}:runReport",
            $request_body
        );
        
        if (isset($response['error'])) {
            return array('error' => $response['error']['message'] ?? 'API request failed');
        }
        
        $pages = array();
        if (isset($response['rows'])) {
            foreach ($response['rows'] as $row) {
                $dimensions = $row['dimensionValues'] ?? array();
                $metrics = $row['metricValues'] ?? array();
                
                $pageViews = (int) ($metrics[0]['value'] ?? 0);
                $conversions = (int) ($metrics[1]['value'] ?? 0);
                
                $pages[] = array(
                    'path' => $dimensions[0]['value'] ?? '',
                    'title' => $dimensions[1]['value'] ?? '',
                    'pageViews' => $pageViews,
                    'conversions' => $conversions,
                    'conversionRate' => $pageViews > 0 ? round(($conversions / $pageViews) * 100, 2) : 0,
                    'engagementRate' => round((float) ($metrics[2]['value'] ?? 0) * 100, 2)
                );
            }
        }
        
        return array('success' => true, 'pages' => $pages);
    }
    
    /**
     * Save client credentials
     */
    public function save_client_credentials($client_id, $client_secret) {
        $this->save_credentials(array(
            'client_id' => sanitize_text_field($client_id),
            'client_secret' => sanitize_text_field($client_secret)
        ));
        return array('success' => true);
    }
    
    /**
     * Save selected property
     */
    public function save_property($property_id) {
        $this->save_credentials(array(
            'property_id' => sanitize_text_field($property_id)
        ));
        return array('success' => true);
    }
    
    /**
     * Disconnect GA
     */
    public function disconnect() {
        delete_option('conversioniq_ga_credentials');
        $this->load_credentials();
        return array('success' => true);
    }
    
    /**
     * Get connection status and settings
     */
    public function get_status() {
        return array(
            'connected' => $this->is_connected(),
            'has_credentials' => !empty($this->client_id) && !empty($this->client_secret),
            'property_id' => $this->property_id,
            'property_name' => get_option('conversioniq_ga_credentials', array())['property_name'] ?? ''
        );
    }
}
