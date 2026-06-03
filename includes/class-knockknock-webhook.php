<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * KnockKnock API Integration
 *
 * Polls the KnockKnock Leads & Visitors API on a schedule and writes enriched
 * lead/visitor records to the local WP database tables.
 *
 * Authentication: one API key per client site — generated in the KnockKnock
 * dashboard at Settings → Webhooks → API Key → Generate API Key.
 *
 * Incremental sync: a UTC timestamp watermark is stored in
 * conversioniq_knockknock_last_sync and passed as the `since` query param on
 * every run so only new / recently-updated records are fetched.
 */
class ConversionIQ_KnockKnock_API {

    const API_BASE  = 'https://api.knock-knockapp.com';
    const SYNC_HOOK = 'conversioniq_knockknock_sync';
    const OPT_KEY   = 'conversioniq_knockknock_api_key';
    const OPT_SINCE = 'conversioniq_knockknock_last_sync';

    private $table_leads;
    private $table_sessions;

    public function __construct() {
        global $wpdb;
        $this->table_leads    = $wpdb->prefix . 'conversioniq_leads';
        $this->table_sessions = $wpdb->prefix . 'conversioniq_visitor_sessions';

        add_action('rest_api_init', [$this, 'register_endpoints']);
        add_action(self::SYNC_HOOK, [$this, 'run_sync']);
    }

    /**
     * Schedule the twice-daily sync cron if not already queued.
     * Called from conversion-iq.php init action.
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled(self::SYNC_HOOK)) {
            wp_schedule_event(time() + 600, 'twicedaily', self::SYNC_HOOK);
            ciq_log('KnockKnock API: Cron scheduled (twicedaily, first run in ~10 min)');
        }
    }

    // -------------------------------------------------------------------------
    // REST Endpoints
    // -------------------------------------------------------------------------

    public function register_endpoints() {
        // List leads/visitors from local DB
        register_rest_route('conversioniq/v1', '/kk-leads', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_leads'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);

        // Trigger an on-demand sync
        register_rest_route('conversioniq/v1', '/kk-sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'manual_sync'],
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);
    }

    /**
     * GET /conversioniq/v1/kk-leads
     * Returns combined leads + visitors from the local DB.
     */
    public function get_leads(WP_REST_Request $request) {
        global $wpdb;

        $limit     = min(500, max(1, intval($request->get_param('limit') ?: 200)));
        $date_from = sanitize_text_field($request->get_param('date_from') ?: '');
        $date_to   = sanitize_text_field($request->get_param('date_to')   ?: '');

        // Validate YYYY-MM-DD format to prevent injection
        $date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ? $date_from : '';
        $date_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)   ? $date_to   : '';

        // Build optional WHERE clauses for each table
        $leads_where    = 'WHERE 1=1';
        $visitors_where = 'WHERE 1=1';
        $leads_args     = [];
        $visitors_args  = [];

        if (!empty($date_from)) {
            $leads_where    .= ' AND converted_at  >= %s';
            $visitors_where .= ' AND identified_at >= %s';
            $leads_args[]    = $date_from . ' 00:00:00';
            $visitors_args[] = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $leads_where    .= ' AND converted_at  <= %s';
            $visitors_where .= ' AND identified_at <= %s';
            $leads_args[]    = $date_to . ' 23:59:59';
            $visitors_args[] = $date_to . ' 23:59:59';
        }
        $leads_args[]    = $limit;
        $visitors_args[] = $limit;

        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                'lead'          AS type,
                first_name, last_name, email, phone,
                city, state, country,
                company_name, company_domain, company_industry,
                job_title, linkedin_url,
                page_url, initial_page_visit,
                user_session_id,
                converted_at    AS timestamp,
                created_at
            FROM {$this->table_leads}
            {$leads_where}
            ORDER BY converted_at DESC
            LIMIT %d",
            $leads_args
        ), ARRAY_A);

        $visitors = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                'visitor'       AS type,
                first_name, last_name, email,
                NULL            AS phone,
                city, state, country,
                company_name, company_domain, company_industry,
                job_title, linkedin_url,
                page_url, initial_page_visit,
                user_session_id,
                identified_at   AS timestamp,
                created_at
            FROM {$this->table_sessions}
            {$visitors_where}
            ORDER BY identified_at DESC
            LIMIT %d",
            $visitors_args
        ), ARRAY_A);

        $combined = array_merge($leads ?: [], $visitors ?: []);
        usort($combined, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        $combined = array_slice($combined, 0, $limit);

        $filter_str = ($date_from || $date_to)
            ? ' [date_from=' . ($date_from ?: '*') . ', date_to=' . ($date_to ?: '*') . ']'
            : '';
        ciq_log('KnockKnock API: kk-leads requested — returned ' . count($combined)
            . ' records (leads=' . count($leads ?: []) . ', visitors=' . count($visitors ?: []) . ')'
            . $filter_str);

        return new WP_REST_Response([
            'success'   => true,
            'leads'     => $combined,
            'total'     => count($combined),
            'last_sync' => get_option(self::OPT_SINCE, ''),
        ], 200);
    }

    /**
     * POST /conversioniq/v1/kk-sync
     * Triggers an immediate sync. Pass full_resync=true to clear the watermark.
     */
    public function manual_sync(WP_REST_Request $request) {
        $full_resync = filter_var($request->get_param('full_resync'), FILTER_VALIDATE_BOOLEAN);
        if ($full_resync) {
            delete_option(self::OPT_SINCE);
            ciq_log('KnockKnock API: Full re-sync requested — watermark cleared');
        } else {
            ciq_log('KnockKnock API: Manual sync triggered via REST API');
        }

        $records_synced = $this->run_sync();

        return new WP_REST_Response([
            'success'        => true,
            'records_synced' => $records_synced,
            'last_sync'      => get_option(self::OPT_SINCE, ''),
        ], 200);
    }

    // -------------------------------------------------------------------------
    // Sync Engine
    // -------------------------------------------------------------------------

    /**
     * Incremental sync: fetches all records updated since the stored watermark,
     * pages through results, upserts to local DB, then advances the watermark.
     */
    public function run_sync(): int {
        $api_key = get_option(self::OPT_KEY, '');
        if (empty($api_key)) {
            ciq_log('KnockKnock API: Skipping sync — no API key configured');
            return 0;
        }

        // Record start time before fetching so records created during the sync
        // window are not missed on the next run.
        $sync_start  = gmdate('Y-m-d\TH:i:s\Z');
        $since       = get_option(self::OPT_SINCE, '');
        $page        = 1;
        $limit       = 500;
        $total_saved = 0;
        $has_more    = true;

        ciq_log('KnockKnock API: Starting sync — since=' . ($since ?: 'beginning')
            . ' | api_key_length=' . strlen($api_key)
            . ' | api_key_prefix=' . substr($api_key, 0, 6) . '...');

        while ($has_more) {
            $args = ['page' => $page, 'limit' => $limit, 'type' => 'all'];
            if (!empty($since)) {
                $args['since'] = $since;
            }

            $url = self::API_BASE . '/api/v1/lead/visitors?' . http_build_query($args);
            ciq_log('KnockKnock API: GET ' . $url);

            $response = wp_remote_get(
                $url,
                ['headers' => ['x-api-key' => $api_key], 'timeout' => 30]
            );

            if (is_wp_error($response)) {
                ciq_log('KnockKnock API: HTTP error — ' . $response->get_error_message());
                return 0; // Do not advance watermark on failure
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401) {
                ciq_log('KnockKnock API: Invalid or missing API key — stopping sync');
                return 0;
            }
            if ($code !== 200) {
                $body_preview = substr(wp_remote_retrieve_body($response), 0, 300);
                $headers      = wp_remote_retrieve_headers($response);
                $header_str   = '';
                foreach (['content-type', 'location', 'x-error', 'cf-ray', 'server'] as $h) {
                    if (!empty($headers[$h])) {
                        $header_str .= ' | ' . $h . ': ' . $headers[$h];
                    }
                }
                ciq_log('KnockKnock API: Non-200 response — HTTP ' . $code . $header_str . ' | body: ' . $body_preview);
                return 0;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || !isset($body['visitors'])) {
                ciq_log('KnockKnock API: Unexpected response structure on page ' . $page
                    . ' — body: ' . substr(wp_remote_retrieve_body($response), 0, 200));
                return 0;
            }

            $page_count = count($body['visitors']);
            $pagination  = $body['pagination'] ?? [];
            ciq_log('KnockKnock API: Page ' . $page . ' — received ' . $page_count . ' records'
                . ' (total_available=' . ($pagination['total'] ?? '?') . ', has_more=' . ($body['pagination']['has_more'] ? 'true' : 'false') . ')');

            $page_leads = 0;
            $page_visitors = 0;
            foreach ($body['visitors'] as $record) {
                $this->upsert_record($record);
                $total_saved++;
                if (($record['type'] ?? 'visitor') === 'lead') {
                    $page_leads++;
                } else {
                    $page_visitors++;
                }
            }

            if ($page_count > 0) {
                ciq_log('KnockKnock API: Page ' . $page . ' upserted — leads=' . $page_leads . ', visitors=' . $page_visitors);
            }

            $has_more = (bool) ($body['pagination']['has_more'] ?? false);
            $page++;
        }

        // Advance watermark only after a full successful sync
        update_option(self::OPT_SINCE, $sync_start);
        ciq_log("KnockKnock API: Sync complete — {$total_saved} records processed, watermark → {$sync_start}");

        return $total_saved;
    }

    // -------------------------------------------------------------------------
    // Record Upsert
    // -------------------------------------------------------------------------

    /**
     * Maps one API record to the appropriate local DB table and upserts it.
     * user_session_id is the idempotency key for both tables.
     */
    private function upsert_record(array $record) {
        $type    = $record['type'] ?? 'visitor';
        $contact = $record['contact'] ?? [];
        $geo     = $record['geo']     ?? [];
        $company = $record['company'] ?? [];
        $visit   = $record['visit']   ?? [];

        $session_id = sanitize_text_field($record['user_session_id'] ?? '');
        if (empty($session_id)) {
            ciq_log('KnockKnock API: Skipping record — missing user_session_id');
            return;
        }

        $email     = sanitize_email($contact['business_email']  ?? $contact['personal_email'] ?? '');
        $first     = sanitize_text_field($contact['first_name'] ?? '');
        $last      = sanitize_text_field($contact['last_name']  ?? '');
        $phone     = sanitize_text_field($contact['phone']      ?? '');
        $title     = sanitize_text_field($contact['job_title']  ?? '');
        $linkedin  = esc_url_raw($contact['linkedin_url']       ?? '');
        $city      = sanitize_text_field($geo['city']           ?? '');
        $state     = sanitize_text_field($geo['state']          ?? '');
        $country   = sanitize_text_field($geo['country']        ?? '');
        $co_name   = sanitize_text_field($company['name']       ?? '');
        $co_domain = sanitize_text_field($company['domain']     ?? '');
        $co_ind    = sanitize_text_field($company['industry']   ?? '');
        $page_url  = esc_url_raw($visit['page_url']             ?? '');
        $init_url  = esc_url_raw($visit['initial_page_url']     ?? '');

        if ($type === 'lead') {
            $this->upsert_lead(
                $session_id, $email, $first, $last, $phone, $title, $linkedin,
                $city, $state, $country, $co_name, $co_domain, $co_ind,
                $page_url, $init_url, $record['converted_at'] ?? null
            );
        } else {
            $this->upsert_visitor(
                $session_id, $email, $first, $last, $title, $linkedin,
                $city, $state, $country, $co_name, $co_domain, $co_ind,
                $page_url, $init_url, $record['identified_at'] ?? null
            );
        }
    }

    private function upsert_lead(
        $session_id, $email, $first, $last, $phone, $title, $linkedin,
        $city, $state, $country, $co_name, $co_domain, $co_ind,
        $page_url, $init_url, $converted_at
    ) {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_leads} WHERE user_session_id = %s LIMIT 1",
            $session_id
        ));

        $data = [
            'first_name'         => $first,
            'last_name'          => $last,
            'email'              => $email ?: 'unknown@unknown.invalid',
            'phone'              => $phone    ?: null,
            'job_title'          => $title    ?: null,
            'linkedin_url'       => $linkedin ?: null,
            'city'               => $city     ?: null,
            'state'              => $state    ?: null,
            'country'            => $country  ?: null,
            'company_name'       => $co_name   ?: null,
            'company_domain'     => $co_domain ?: null,
            'company_industry'   => $co_ind    ?: null,
            'page_url'           => $page_url,
            'initial_page_visit' => $init_url  ?: null,
            'user_session_id'    => $session_id,
            'converted_at'       => $converted_at
                ? gmdate('Y-m-d H:i:s', strtotime($converted_at))
                : current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($this->table_leads, $data, ['id' => $existing]);
            ciq_log('KnockKnock API: Lead UPDATED — session=' . $session_id . ' email=' . ($email ?: 'n/a'));
        } else {
            $data['webhook_log_id'] = 0;
            $data['created_at']     = current_time('mysql');
            $result = $wpdb->insert($this->table_leads, $data);
            if ($result === false) {
                ciq_log('KnockKnock API: Lead INSERT FAILED — session=' . $session_id . ' error=' . $wpdb->last_error);
            } else {
                ciq_log('KnockKnock API: Lead INSERTED — session=' . $session_id . ' email=' . ($email ?: 'n/a'));
            }
        }
    }

    private function upsert_visitor(
        $session_id, $email, $first, $last, $title, $linkedin,
        $city, $state, $country, $co_name, $co_domain, $co_ind,
        $page_url, $init_url, $identified_at
    ) {
        global $wpdb;

        // $wpdb->replace() leverages the UNIQUE KEY on user_session_id
        $result = $wpdb->replace($this->table_sessions, [
            'user_session_id'    => $session_id,
            'first_name'         => $first,
            'last_name'          => $last,
            'email'              => $email    ?: null,
            'job_title'          => $title    ?: null,
            'linkedin_url'       => $linkedin ?: null,
            'city'               => $city     ?: null,
            'state'              => $state    ?: null,
            'country'            => $country  ?: null,
            'company_name'       => $co_name   ?: null,
            'company_domain'     => $co_domain ?: null,
            'company_industry'   => $co_ind    ?: null,
            'page_url'           => $page_url,
            'initial_page_visit' => $init_url ?: null,
            'identified_at'      => $identified_at
                ? gmdate('Y-m-d H:i:s', strtotime($identified_at))
                : current_time('mysql'),
            'webhook_log_id'     => 0,
            'created_at'         => current_time('mysql'),
        ]);

        if ($result === false) {
            ciq_log('KnockKnock API: Visitor UPSERT FAILED — session=' . $session_id . ' error=' . $wpdb->last_error);
        } else {
            ciq_log('KnockKnock API: Visitor UPSERTED — session=' . $session_id . ' email=' . ($email ?: 'n/a'));
        }
    }
}

// Bootstrap
$ciq_kk_api = new ConversionIQ_KnockKnock_API();
ConversionIQ_KnockKnock_API::schedule_cron();
