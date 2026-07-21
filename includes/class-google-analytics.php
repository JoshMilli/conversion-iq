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
        // WP options override the shared-app constants (for clients using their own GCP project).
        $this->client_id     = ! empty( $options['client_id'] )     ? $options['client_id']     : ( defined( 'CIQ_GOOGLE_CLIENT_ID' )     ? CIQ_GOOGLE_CLIENT_ID     : '' );
        $this->client_secret = ! empty( $options['client_secret'] ) ? $options['client_secret'] : ( defined( 'CIQ_GOOGLE_CLIENT_SECRET' ) ? CIQ_GOOGLE_CLIENT_SECRET : '' );
        $this->access_token  = $options['access_token']  ?? '';
        $this->refresh_token = $options['refresh_token'] ?? '';
        $this->property_id   = $options['property_id']   ?? '';
        // Use the SaaS proxy URI by default; falls back to direct WP-admin URL if constant overridden.
        $this->redirect_uri  = defined( 'CIQ_GOOGLE_REDIRECT_URI' ) ? CIQ_GOOGLE_REDIRECT_URI : admin_url( 'admin.php?page=conversioniq&ga_callback=1' );
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
     * Check if OAuth tokens are stored (regardless of property selection).
     */
    public function has_tokens() {
        return ! empty( $this->access_token ) || ! empty( $this->refresh_token );
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
            ciq_log( '[OAuth] get_auth_url: no client_id configured — returning empty URL' );
            return '';
        }

        $nonce = wp_create_nonce( 'ciq_google_oauth' );
        set_transient( 'ciq_google_oauth_state', $nonce, 600 );

        // Encode state as base64url JSON so the SaaS proxy can extract site_url
        // and redirect the user back to the correct WordPress installation.
        $state = rtrim(
            strtr(
                base64_encode( wp_json_encode( array(
                    'site_url' => admin_url(),
                    'nonce'    => $nonce,
                ) ) ),
                '+/', '-_'
            ),
            '='
        );

        $params = array(
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        );

        ciq_log( '[OAuth] Auth URL generated — client_id=' . substr( $this->client_id, 0, 20 ) . '... redirect_uri=' . $this->redirect_uri . ' nonce=' . $nonce );

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function exchange_code($code) {
        ciq_log( '[OAuth] exchange_code: starting token exchange — code_length=' . strlen( $code ) );

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->redirect_uri,
                'grant_type'    => 'authorization_code'
            )
        ));

        if (is_wp_error($response)) {
            ciq_log( '[OAuth] exchange_code: WP_Error — ' . $response->get_error_message() );
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            $expires_in    = (int) ( $body['expires_in'] ?? 3600 );
            $has_refresh   = ! empty( $body['refresh_token'] ) ? 'yes' : 'no';
            $this->save_credentials(array(
                'access_token'  => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? '',
                'token_expires' => time() + $expires_in
            ));
            ciq_log( '[OAuth] exchange_code: SUCCESS — HTTP ' . $http_code . ' expires_in=' . $expires_in . 's has_refresh_token=' . $has_refresh );
            return array('success' => true);
        }

        $error = $body['error'] ?? 'unknown';
        $desc  = $body['error_description'] ?? 'Failed to get access token';
        ciq_log( '[OAuth] exchange_code: FAILED — HTTP ' . $http_code . ' error=' . $error . ' desc=' . $desc );
        return array('success' => false, 'error' => $desc);
    }
    
    /**
     * Refresh access token using refresh token
     */
    private function refresh_access_token() {
        if (empty($this->refresh_token)) {
            ciq_log( '[OAuth] refresh_access_token: no refresh token stored — cannot refresh' );
            return false;
        }

        ciq_log( '[OAuth] refresh_access_token: refreshing expired access token' );

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'refresh_token' => $this->refresh_token,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type'    => 'refresh_token'
            )
        ));

        if (is_wp_error($response)) {
            ciq_log( '[OAuth] refresh_access_token: WP_Error — ' . $response->get_error_message() );
            return false;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            $expires_in = (int) ( $body['expires_in'] ?? 3600 );
            $this->save_credentials(array(
                'access_token'  => $body['access_token'],
                'token_expires' => time() + $expires_in
            ));
            ciq_log( '[OAuth] refresh_access_token: SUCCESS — HTTP ' . $http_code . ' new token expires in ' . $expires_in . 's' );
            return true;
        }

        $error = $body['error'] ?? 'unknown';
        $desc  = $body['error_description'] ?? '';
        ciq_log( '[OAuth] refresh_access_token: FAILED — HTTP ' . $http_code . ' error=' . $error . ( $desc ? ' (' . $desc . ')' : '' ) );
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
            ciq_log( '[API] api_request: WP_Error on ' . $endpoint . ' — ' . $response->get_error_message() );
            return array('error' => $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code >= 400 ) {
            ciq_log( '[API] api_request: HTTP ' . $http_code . ' from ' . $endpoint );
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ( isset( $decoded['error'] ) ) {
            $msg = is_array( $decoded['error'] ) ? ( $decoded['error']['message'] ?? wp_json_encode( $decoded['error'] ) ) : $decoded['error'];
            ciq_log( '[API] api_request: API error from ' . $endpoint . ' — ' . $msg );
        }
        return $decoded;
    }
    
    /**
     * Get list of available GA4 properties
     */
    public function get_properties() {
        // accountSummaries is paginated (default page size 50). Without following
        // nextPageToken, accounts/properties beyond the first page — often the newest —
        // never appear, which is why "the latest properties" were sometimes missing.
        // Loop every page (pageSize=200) and merge, with a safety cap.
        $properties = array();
        $page_token = '';
        $pages      = 0;

        do {
            $url = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200';
            if ( $page_token !== '' ) {
                $url .= '&pageToken=' . rawurlencode( $page_token );
            }

            $response = $this->api_request( $url );

            if ( isset( $response['error'] ) ) {
                // If earlier pages already succeeded, return what we have rather than
                // losing everything; otherwise surface the error to the UI.
                if ( ! empty( $properties ) ) {
                    ciq_log( '[GA4] get_properties: partial list — error on page ' . ( $pages + 1 ) . ': ' . ( is_string( $response['error'] ) ? $response['error'] : wp_json_encode( $response['error'] ) ) );
                    break;
                }
                return array( 'success' => false, 'error' => $response['error'] );
            }

            if ( ! empty( $response['accountSummaries'] ) && is_array( $response['accountSummaries'] ) ) {
                foreach ( $response['accountSummaries'] as $account ) {
                    if ( empty( $account['propertySummaries'] ) || ! is_array( $account['propertySummaries'] ) ) {
                        continue;
                    }
                    foreach ( $account['propertySummaries'] as $property ) {
                        if ( empty( $property['property'] ) ) continue;
                        $properties[] = array(
                            'id'      => $property['property'],
                            'name'    => $property['displayName'] ?? '(unnamed property)',
                            'account' => $account['displayName'] ?? '',
                        );
                    }
                }
            }

            $page_token = $response['nextPageToken'] ?? '';
            $pages++;
        } while ( $page_token !== '' && $pages < 20 ); // 20 × 200 = 4000 accounts max

        ciq_log( '[GA4] get_properties: ' . count( $properties ) . ' propertie(s) across ' . $pages . ' page(s)' );
        return array( 'success' => true, 'properties' => $properties );
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
        ciq_log( '[OAuth] disconnect: clearing all stored Google credentials and tokens' );
        delete_option('conversioniq_ga_credentials');
        $this->load_credentials();
        return array('success' => true);
    }
    
    /**
     * Get connection status and settings
     */
    public function get_status() {
        return array(
            'connected'       => $this->is_connected(),
            'has_credentials' => !empty($this->client_id) && !empty($this->client_secret),
            'property_id'     => $this->property_id,
            'property_name'   => get_option('conversioniq_ga_credentials', array())['property_name'] ?? '',
            'gsc_connected'   => $this->is_gsc_connected(),
            'gsc_property'    => $this->get_gsc_property(),
        );
    }

    // ── GSC helpers ──────────────────────────────────────────────────────────

    /**
     * Save the GSC site property URL.
     */
    public function save_gsc_property( $site_url ) {
        $this->save_credentials( array( 'gsc_property_url' => esc_url_raw( $site_url ) ) );
        return array( 'success' => true );
    }

    /**
     * Return the stored GSC property URL.
     */
    public function get_gsc_property() {
        $options = get_option( 'conversioniq_ga_credentials', array() );
        return $options['gsc_property_url'] ?? '';
    }

    /**
     * Return the stored GA4 property ID (e.g. "properties/317081735").
     */
    public function get_property_id() {
        return $this->property_id;
    }

    /**
     * True when we have a token AND a GSC property set.
     */
    public function is_gsc_connected() {
        return ! empty( $this->access_token ) && ! empty( $this->get_gsc_property() );
    }

    /**
     * List all GSC sites the authenticated user has access to.
     */
    public function get_gsc_sites() {
        if ( ! $this->has_tokens() ) {
            ciq_log( '[GSC] get_gsc_sites: no tokens — skipping' );
            return array( 'error' => 'Not connected to Google' );
        }

        ciq_log( '[GSC] get_gsc_sites: fetching site list from Search Console API' );
        $response = $this->api_request( 'https://searchconsole.googleapis.com/webmasters/v3/sites' );

        if ( isset( $response['error'] ) ) {
            $msg = is_array( $response['error'] ) ? ( $response['error']['message'] ?? wp_json_encode( $response['error'] ) ) : $response['error'];
            ciq_log( '[GSC] get_gsc_sites: error — ' . $msg );
            return array( 'error' => $msg );
        }

        $sites = array();
        foreach ( $response['siteEntry'] ?? array() as $entry ) {
            $sites[] = array(
                'url'              => $entry['siteUrl']         ?? '',
                'permission_level' => $entry['permissionLevel'] ?? '',
            );
        }

        ciq_log( '[GSC] get_gsc_sites: found ' . count( $sites ) . ' site(s)' );
        return array( 'success' => true, 'sites' => $sites );
    }

    // ── Site-wide data fetchers ───────────────────────────────────────────────

    /**
     * Fetch a 28-day site-wide GA4 summary (sessions, users, channels, top pages).
     *
     * @param int $days
     * @return array
     */
    public function fetch_ga4_site_summary( $days = 28 ) {
        if ( ! $this->is_connected() ) {
            ciq_log( '[GA4] fetch_ga4_site_summary: not connected — skipping' );
            return array( 'error' => 'GA4 not connected' );
        }

        ciq_log( '[GA4] fetch_ga4_site_summary: starting — property=' . $this->property_id . ' days=' . $days );

        $end   = gmdate( 'Y-m-d' );
        $start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $base  = "https://analyticsdata.googleapis.com/v1beta/{$this->property_id}:runReport";

        // ── Overall site metrics ──────────────────────────────────────────
        $site_resp = $this->api_request( $base, array(
            'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
            'metrics'    => array(
                array( 'name' => 'sessions' ),
                array( 'name' => 'totalUsers' ),
                array( 'name' => 'bounceRate' ),
                array( 'name' => 'engagementRate' ),
                array( 'name' => 'conversions' ),
                array( 'name' => 'averageSessionDuration' ),
            ),
        ) );

        if ( isset( $site_resp['error'] ) ) {
            $msg = is_array( $site_resp['error'] ) ? ( $site_resp['error']['message'] ?? wp_json_encode( $site_resp['error'] ) ) : $site_resp['error'];
            ciq_log( '[GA4] fetch_ga4_site_summary: site metrics error — ' . $msg );
            return array( 'error' => $msg );
        }

        $m = $site_resp['rows'][0]['metricValues'] ?? array();
        $summary = array(
            'sessions'              => (int)   ( $m[0]['value'] ?? 0 ),
            'total_users'           => (int)   ( $m[1]['value'] ?? 0 ),
            'bounce_rate'           => round( (float) ( $m[2]['value'] ?? 0 ) * 100, 1 ),
            'engagement_rate'       => round( (float) ( $m[3]['value'] ?? 0 ) * 100, 1 ),
            'conversions'           => (int)   ( $m[4]['value'] ?? 0 ),
            'avg_session_duration'  => round( (float) ( $m[5]['value'] ?? 0 ), 0 ),
            'period_days'           => $days,
        );

        // ── Traffic channels ─────────────────────────────────────────────
        $channels_resp = $this->api_request( $base, array(
            'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
            'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ),
            'metrics'    => array( array( 'name' => 'sessions' ) ),
            'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
            'limit'      => 8,
        ) );

        $channels = array();
        foreach ( $channels_resp['rows'] ?? array() as $row ) {
            $channels[] = array(
                'channel'  => $row['dimensionValues'][0]['value'] ?? '',
                'sessions' => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
            );
        }
        $summary['channels'] = $channels;

        // ── Top pages ────────────────────────────────────────────────────
        $pages_resp = $this->api_request( $base, array(
            'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
            'dimensions' => array( array( 'name' => 'pagePath' ), array( 'name' => 'pageTitle' ) ),
            'metrics'    => array(
                array( 'name' => 'sessions' ),
                array( 'name' => 'screenPageViews' ),
                array( 'name' => 'engagementRate' ),
            ),
            'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
            'limit'      => 10,
        ) );

        $top_pages = array();
        foreach ( $pages_resp['rows'] ?? array() as $row ) {
            $top_pages[] = array(
                'path'           => $row['dimensionValues'][0]['value'] ?? '',
                'title'          => $row['dimensionValues'][1]['value'] ?? '',
                'sessions'       => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
                'page_views'     => (int) ( $row['metricValues'][1]['value'] ?? 0 ),
                'engagement_rate' => round( (float) ( $row['metricValues'][2]['value'] ?? 0 ) * 100, 1 ),
            );
        }
        $summary['top_pages'] = $top_pages;

        ciq_log( '[GA4] fetch_ga4_site_summary: SUCCESS — sessions=' . $summary['sessions'] . ' users=' . $summary['total_users'] . ' conversions=' . $summary['conversions'] . ' engagement=' . $summary['engagement_rate'] . '% channels=' . count( $channels ) . ' top_pages=' . count( $top_pages ) );

        return $summary;
    }

    /**
     * Fetch a 28-day site-wide GSC summary (clicks, impressions, keywords, sitemaps).
     *
     * @param int $days
     * @return array
     */
    /**
     * Fetch the top search queries driving traffic to a specific page URL.
     *
     * Used by the audit pipeline to enrich copy rewrites with real search intent.
     * Returns null when GSC is not connected — callers must handle gracefully.
     * Results are cached in a transient for 6 hours to avoid redundant API calls.
     *
     * @param string $page_url  Full page URL (e.g. https://example.com/services/).
     * @param int    $days      Lookback window in days (default 90 for richer signal).
     * @return array|null  Array of { query, clicks, impressions, ctr, position }
     *                     sorted by clicks DESC, or null if GSC not connected.
     */
    public function fetch_gsc_page_queries( $page_url, $days = 90 ) {
        if ( ! $this->is_gsc_connected() ) {
            ciq_log( '[GSC] fetch_gsc_page_queries: not connected — returning null' );
            return null;
        }

        // Cache key includes URL hash + day window so refreshes on different windows
        $cache_key = 'ciq_gsc_page_' . md5( $page_url . '_' . $days );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            ciq_log( '[GSC] fetch_gsc_page_queries: served from cache for ' . $page_url );
            return $cached;
        }

        $end          = gmdate( 'Y-m-d' );
        $start        = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $property_url = $this->get_gsc_property();
        $encoded      = rawurlencode( $property_url );
        $base         = "https://searchconsole.googleapis.com/webmasters/v3/sites/{$encoded}";

        $response = $this->api_request( $base . '/searchAnalytics/query', array(
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => array( 'query' ),
            'rowLimit'   => 10,
            'dimensionFilterGroups' => array(
                array(
                    'filters' => array(
                        array(
                            'dimension'  => 'page',
                            'operator'   => 'contains',
                            'expression' => parse_url( $page_url, PHP_URL_PATH ) ?: '/',
                        ),
                    ),
                ),
            ),
        ) );

        if ( isset( $response['error'] ) ) {
            $msg = is_array( $response['error'] ) ? ( $response['error']['message'] ?? wp_json_encode( $response['error'] ) ) : $response['error'];
            ciq_log( '[GSC] fetch_gsc_page_queries: error — ' . $msg );
            return null;
        }

        $queries = array();
        foreach ( $response['rows'] ?? array() as $row ) {
            $queries[] = array(
                'query'       => $row['keys'][0]   ?? '',
                'clicks'      => (int)   ( $row['clicks']      ?? 0 ),
                'impressions' => (int)   ( $row['impressions'] ?? 0 ),
                'ctr'         => round( (float) ( $row['ctr']      ?? 0 ) * 100, 2 ),
                'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
            );
        }

        // Sort by clicks descending
        usort( $queries, fn( $a, $b ) => $b['clicks'] - $a['clicks'] );

        // Cache for 6 hours
        set_transient( $cache_key, $queries, 6 * HOUR_IN_SECONDS );

        ciq_log( '[GSC] fetch_gsc_page_queries: ' . count( $queries ) . ' queries for ' . $page_url );
        return $queries;
    }

    public function fetch_gsc_site_summary( $days = 28 ) {
        if ( ! $this->is_gsc_connected() ) {
            ciq_log( '[GSC] fetch_gsc_site_summary: not connected — skipping' );
            return array( 'error' => 'GSC not connected' );
        }

        ciq_log( '[GSC] fetch_gsc_site_summary: starting — property=' . $this->get_gsc_property() . ' days=' . $days );

        $end          = gmdate( 'Y-m-d' );
        $start        = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $property_url = $this->get_gsc_property();
        $encoded      = rawurlencode( $property_url );
        $base         = "https://searchconsole.googleapis.com/webmasters/v3/sites/{$encoded}";

        // ── Overall site metrics ──────────────────────────────────────────
        $site_resp = $this->api_request( $base . '/searchAnalytics/query', array(
            'startDate' => $start,
            'endDate'   => $end,
            'rowLimit'  => 1,
        ) );

        if ( isset( $site_resp['error'] ) ) {
            $msg = is_array( $site_resp['error'] ) ? ( $site_resp['error']['message'] ?? wp_json_encode( $site_resp['error'] ) ) : $site_resp['error'];
            ciq_log( '[GSC] fetch_gsc_site_summary: site metrics error — ' . $msg );
            return array( 'error' => $msg );
        }

        $r       = $site_resp['rows'][0] ?? array();
        $summary = array(
            'total_clicks'      => (int)   ( $r['clicks']      ?? 0 ),
            'total_impressions' => (int)   ( $r['impressions'] ?? 0 ),
            'avg_ctr'           => round( (float) ( $r['ctr']      ?? 0 ) * 100, 2 ),
            'avg_position'      => round( (float) ( $r['position'] ?? 0 ), 1 ),
            'period_days'       => $days,
        );

        // ── Top queries ───────────────────────────────────────────────────
        $queries_resp = $this->api_request( $base . '/searchAnalytics/query', array(
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => array( 'query' ),
            'rowLimit'   => 20,
        ) );

        $queries = array();
        foreach ( $queries_resp['rows'] ?? array() as $row ) {
            $queries[] = array(
                'keyword'     => $row['keys'][0]   ?? '',
                'clicks'      => (int)   ( $row['clicks']      ?? 0 ),
                'impressions' => (int)   ( $row['impressions'] ?? 0 ),
                'ctr'         => round( (float) ( $row['ctr']      ?? 0 ) * 100, 2 ),
                'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
            );
        }
        $summary['top_queries'] = $queries;

        // ── Top pages ─────────────────────────────────────────────────────
        $pages_resp = $this->api_request( $base . '/searchAnalytics/query', array(
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => array( 'page' ),
            'rowLimit'   => 10,
        ) );

        $top_pages = array();
        foreach ( $pages_resp['rows'] ?? array() as $row ) {
            $top_pages[] = array(
                'url'         => $row['keys'][0]   ?? '',
                'clicks'      => (int)   ( $row['clicks']      ?? 0 ),
                'impressions' => (int)   ( $row['impressions'] ?? 0 ),
                'ctr'         => round( (float) ( $row['ctr']      ?? 0 ) * 100, 2 ),
                'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
            );
        }
        $summary['top_pages'] = $top_pages;

        // ── Sitemaps ──────────────────────────────────────────────────────
        $sitemaps_resp = $this->api_request( $base . '/sitemaps' );

        $sitemaps = array();
        foreach ( $sitemaps_resp['sitemap'] ?? array() as $sm ) {
            $sitemaps[] = array(
                'url'            => $sm['path']          ?? '',
                'last_submitted' => $sm['lastSubmitted'] ?? '',
                'errors'         => (int) ( $sm['errors']   ?? 0 ),
                'warnings'       => (int) ( $sm['warnings'] ?? 0 ),
                'is_pending'     => ! empty( $sm['isPending'] ),
            );
        }
        $summary['sitemaps'] = $sitemaps;

        ciq_log( '[GSC] fetch_gsc_site_summary: SUCCESS — clicks=' . $summary['total_clicks'] . ' impressions=' . $summary['total_impressions'] . ' avg_position=' . $summary['avg_position'] . ' queries=' . count( $summary['top_queries'] ?? array() ) . ' sitemaps=' . count( $sitemaps ) );

        return $summary;
    }
}
