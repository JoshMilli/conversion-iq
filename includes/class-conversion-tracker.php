<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ConversionIQ Conversion Tracker
 *
 * Hooks into every major WordPress form plugin and records conversions
 * locally in wp_options. Captures non-PII proof data (field labels, field
 * count, goal type) but never stores submitted values.
 *
 * A daily cron job pushes counts + proof data to the SaaS proxy
 * (conversioniq-app.com) which writes to Supabase.
 *
 * Also supports user-defined Conversion Goals (tel clicks, thank-you pages,
 * element clicks, Calendly, etc.) detected by the front-end JS tracker.
 *
 * Supported form plugins (server-side):
 *   Contact Form 7, Gravity Forms, WPForms, Ninja Forms, Formidable, Fluent Forms
 *
 * Client-side fallback:
 *   ciq-heatmap-tracker.js listens for form submits + goal events and calls
 *   POST /wp-json/conversioniq/v1/track-conversion
 */
class ConversionIQ_Conversion_Tracker {

    /** wp_options key — rolling 90-day conversion log */
    const COUNT_OPTION = 'conversioniq_conversion_counts';

    /** wp_options key — user-defined conversion goals */
    const GOALS_OPTION = 'conversioniq_conversion_goals';

    // ─────────────────────────────────────────────────────────────────────
    // Bootstrap
    // ─────────────────────────────────────────────────────────────────────

    public static function init() {
        // Server-side form plugin hooks
        add_action( 'wpcf7_mail_sent',               array( __CLASS__, 'on_cf7_submit' ) );
        add_action( 'gform_after_submission',         array( __CLASS__, 'on_gravity_submit' ),   10, 2 );
        add_action( 'wpforms_process_complete',       array( __CLASS__, 'on_wpforms_submit' ),   10, 4 );
        add_action( 'ninja_forms_after_submission',   array( __CLASS__, 'on_ninja_submit' ) );
        add_action( 'frm_after_create_entry',         array( __CLASS__, 'on_formidable_submit' ), 10, 2 );
        add_action( 'fluentform_submission_inserted', array( __CLASS__, 'on_fluent_submit' ),    10, 3 );

        // REST endpoints (JS fallback + Goals CRUD)
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Form plugin callbacks — extract field labels (non-PII) only
    // ─────────────────────────────────────────────────────────────────────

    public static function on_cf7_submit( $contact_form ) {
        $name   = method_exists( $contact_form, 'title' ) ? $contact_form->title() : 'Contact Form 7';
        $labels = array();
        if ( method_exists( $contact_form, 'scan_form_tags' ) ) {
            foreach ( $contact_form->scan_form_tags() as $tag ) {
                if ( ! empty( $tag->name ) && $tag->basetype !== 'submit' ) {
                    $labels[] = sanitize_text_field( $tag->name );
                }
            }
        }
        self::record( $name, wp_get_referer() ?: get_site_url(), 'cf7', array(
            'field_labels' => array_slice( $labels, 0, 20 ),
            'field_count'  => count( $labels ),
        ) );
    }

    public static function on_gravity_submit( $entry, $form ) {
        $labels = array();
        if ( ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                if ( ! empty( $field->label ) && $field->type !== 'submit' ) {
                    $labels[] = sanitize_text_field( $field->label );
                }
            }
        }
        self::record( $form['title'] ?? 'Gravity Form', wp_get_referer() ?: get_site_url(), 'gravityforms', array(
            'field_labels' => array_slice( $labels, 0, 20 ),
            'field_count'  => count( $labels ),
        ) );
    }

    public static function on_wpforms_submit( $fields, $entry, $form_data, $entry_id ) {
        $labels = array();
        if ( ! empty( $form_data['fields'] ) ) {
            foreach ( $form_data['fields'] as $field ) {
                if ( ! empty( $field['label'] ) && ( $field['type'] ?? '' ) !== 'submit' ) {
                    $labels[] = sanitize_text_field( $field['label'] );
                }
            }
        }
        self::record( $form_data['settings']['form_title'] ?? 'WPForms', wp_get_referer() ?: get_site_url(), 'wpforms', array(
            'field_labels' => array_slice( $labels, 0, 20 ),
            'field_count'  => count( $labels ),
        ) );
    }

    public static function on_ninja_submit( $form_data ) {
        $labels = array();
        if ( ! empty( $form_data['fields'] ) ) {
            foreach ( $form_data['fields'] as $field ) {
                if ( ! empty( $field['label'] ) ) {
                    $labels[] = sanitize_text_field( $field['label'] );
                }
            }
        }
        self::record( $form_data['settings']['title'] ?? 'Ninja Form', wp_get_referer() ?: get_site_url(), 'ninjaforms', array(
            'field_labels' => array_slice( $labels, 0, 20 ),
            'field_count'  => count( $labels ),
        ) );
    }

    public static function on_formidable_submit( $entry_id, $form_id ) {
        // Formidable doesn't expose a field list at this hook stage
        self::record( 'Formidable Form ' . $form_id, wp_get_referer() ?: get_site_url(), 'formidable', array() );
    }

    public static function on_fluent_submit( $insert_id, $form_data, $form ) {
        $labels = array();
        if ( ! empty( $form_data['form_fields'] ) ) {
            foreach ( $form_data['form_fields'] as $field ) {
                if ( ! empty( $field['raw']['label'] ) ) {
                    $labels[] = sanitize_text_field( $field['raw']['label'] );
                }
            }
        }
        self::record( $form->title ?? 'Fluent Form', wp_get_referer() ?: get_site_url(), 'fluentforms', array(
            'field_labels' => array_slice( $labels, 0, 20 ),
            'field_count'  => count( $labels ),
        ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    // REST endpoints
    // ─────────────────────────────────────────────────────────────────────

    public static function register_rest_routes() {
        // JS fallback — public, front-end accessible
        register_rest_route( 'conversioniq/v1', '/track-conversion', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_track_conversion' ),
            'permission_callback' => '__return_true',
        ) );

        // Goals — admin only
        register_rest_route( 'conversioniq/v1', '/conversion-goals', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_goals' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );
        register_rest_route( 'conversioniq/v1', '/conversion-goals', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_save_goals' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );
    }

    public static function rest_track_conversion( WP_REST_Request $request ) {
        $form_name  = sanitize_text_field( $request->get_param( 'form_name' )  ?: 'Web Form' );
        $page_url   = esc_url_raw( $request->get_param( 'page_url' )   ?: get_site_url() );
        $goal_type  = sanitize_key( $request->get_param( 'goal_type' )  ?: 'form_submit' );
        $goal_label = sanitize_text_field( $request->get_param( 'goal_label' ) ?: '' );

        // Reject off-site URLs to prevent abuse
        $site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
        $request_host = wp_parse_url( $page_url, PHP_URL_HOST );
        if ( $request_host && $site_host && $request_host !== $site_host ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'Invalid page_url' ), 400 );
        }

        $meta = array( 'goal_type' => $goal_type );
        if ( $goal_label ) {
            $meta['goal_label'] = $goal_label;
        }

        self::record( $form_name, $page_url, 'js_fallback', $meta );
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    public static function rest_get_goals( WP_REST_Request $request ) {
        return new WP_REST_Response( self::get_goals(), 200 );
    }

    public static function rest_save_goals( WP_REST_Request $request ) {
        $raw    = $request->get_json_params();
        $goals  = isset( $raw['goals'] ) ? $raw['goals'] : $raw; // accept { goals:[...] } or bare array
        $result = self::save_goals( (array) $goals );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => $result->get_error_message() ), 400 );
        }
        return new WP_REST_Response( array( 'success' => true, 'goals' => $result ), 200 );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Core record method
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Central entry point called by every form hook and the REST endpoint.
     *
     * @param string $form_name  Human-readable form title or goal label
     * @param string $page_url   URL where the conversion occurred
     * @param string $source     Plugin slug or 'js_fallback'
     * @param array  $meta       Non-PII proof data: field_labels, field_count, goal_type, goal_label
     */
    public static function record( string $form_name, string $page_url, string $source, array $meta = array() ) {
        ciq_log( '[Conversion] ' . $source . ': "' . $form_name . '" on ' . $page_url );
        self::increment_local_count( $source, $form_name, $page_url, $meta );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Local storage
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Increment today's count and append to the event log.
     *
     * Stored structure (wp_options 'conversioniq_conversion_counts'):
     * [
     *   'YYYY-MM-DD' => [
     *     'total'     => int,
     *     'by_source' => [ 'cf7' => int, ... ],
     *     'events'    => [          // capped at 200/day
     *       [
     *         'form_name'    => string,    // form title or goal label
     *         'page_url'     => string,    // page where it happened
     *         'source'       => string,    // cf7 | gravityforms | js_fallback | ...
     *         'goal_type'    => string,    // form_submit | tel_click | thank_you_page | ...
     *         'goal_label'   => string,    // optional human label for the matched goal
     *         'field_labels' => string[],  // field names only — NEVER field values
     *         'field_count'  => int,
     *         'ts'           => int,       // Unix timestamp
     *       ], ...
     *     ]
     *   ]
     * ]
     */
    private static function increment_local_count( string $source, string $form_name, string $page_url, array $meta = array() ) {
        $today = gmdate( 'Y-m-d' );
        $data  = get_option( self::COUNT_OPTION, array() );

        if ( ! isset( $data[ $today ] ) ) {
            $data[ $today ] = array( 'total' => 0, 'by_source' => array(), 'events' => array() );
        }

        $data[ $today ]['total']++;
        $data[ $today ]['by_source'][ $source ] = ( $data[ $today ]['by_source'][ $source ] ?? 0 ) + 1;

        if ( count( $data[ $today ]['events'] ) < 200 ) {
            $event = array(
                'form_name'    => substr( $form_name, 0, 120 ),
                'page_url'     => substr( $page_url, 0, 500 ),
                'source'       => $source,
                'goal_type'    => sanitize_key( $meta['goal_type']   ?? 'form_submit' ),
                'field_labels' => array_map( 'strval', array_slice( $meta['field_labels'] ?? array(), 0, 20 ) ),
                'field_count'  => (int) ( $meta['field_count'] ?? count( $meta['field_labels'] ?? array() ) ),
                'ts'           => time(),
            );
            if ( ! empty( $meta['goal_label'] ) ) {
                $event['goal_label'] = substr( sanitize_text_field( $meta['goal_label'] ), 0, 120 );
            }
            $data[ $today ]['events'][] = $event;
        }

        // Keep only the last 90 days to prevent unbounded option growth
        if ( count( $data ) > 90 ) {
            ksort( $data );
            $data = array_slice( $data, -90, 90, true );
        }

        update_option( self::COUNT_OPTION, $data, false );
    }

    /**
     * Return aggregated conversion count for the last N days.
     *
     * @return array [ 'total' => int, 'by_day' => [ 'YYYY-MM-DD' => int, ... ] ]
     */
    public static function get_counts( int $days = 28 ): array {
        $data  = get_option( self::COUNT_OPTION, array() );
        $since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $total  = 0;
        $by_day = array();

        foreach ( $data as $date => $entry ) {
            if ( $date >= $since ) {
                $by_day[ $date ] = $entry['total'];
                $total           += $entry['total'];
            }
        }

        return array( 'total' => $total, 'by_day' => $by_day );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Conversion Goals CRUD
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Return the saved goals array.
     * Each goal: [ 'id' => string, 'type' => string, 'value' => string, 'label' => string ]
     *
     * Valid types:
     *   thank_you_page  — URL contains this string (e.g. '/thank-you')
     *   tel_click       — any click on a[href^="tel:"] (value = phone number to match, or empty for all)
     *   mailto_click    — any click on a[href^="mailto:"] (value = email to match, or empty for all)
     *   element_click   — click on element matching CSS selector stored in value
     *   calendly        — Calendly postMessage event_scheduled (value ignored)
     *   external_link   — click on any off-site link (value ignored)
     */
    public static function get_goals(): array {
        return get_option( self::GOALS_OPTION, array() );
    }

    /**
     * Validate and persist the goals array.
     *
     * @param  array $raw  Array of goal objects from the REST request
     * @return array|WP_Error  Cleaned goals, or WP_Error on validation failure
     */
    public static function save_goals( array $raw ) {
        $allowed_types = array( 'thank_you_page', 'tel_click', 'mailto_click', 'element_click', 'calendly', 'external_link' );
        $requires_value = array( 'thank_you_page', 'element_click' );
        $clean = array();

        foreach ( $raw as $goal ) {
            $type  = sanitize_key( $goal['type']  ?? '' );
            $value = sanitize_text_field( $goal['value'] ?? '' );
            $label = sanitize_text_field( $goal['label'] ?? '' );

            if ( ! in_array( $type, $allowed_types, true ) ) {
                return new WP_Error( 'invalid_goal_type', 'Unknown goal type: ' . $type );
            }
            if ( empty( $value ) && in_array( $type, $requires_value, true ) ) {
                return new WP_Error( 'missing_goal_value', 'Goal value required for type: ' . $type );
            }

            $clean[] = array(
                'id'    => sanitize_key( $goal['id'] ?? wp_generate_uuid4() ),
                'type'  => $type,
                'value' => $value,
                'label' => $label ?: $type,
            );
        }

        update_option( self::GOALS_OPTION, $clean );
        return $clean;
    }

    // ─────────────────────────────────────────────────────────────────────
    // SaaS sync
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Push one day's conversion data to the SaaS proxy.
     * Called from the nightly heatmap cron and the admin_init once-per-day fallback.
     *
     * @param string|null $date  'YYYY-MM-DD', defaults to yesterday
     */
    public static function sync_to_saas( ?string $date = null ) {
        $org_id  = get_option( 'conversioniq_organization_id', '' );
        $api_key = get_option( 'conversioniq_api_key', '' );

        if ( empty( $org_id ) || empty( $api_key ) ) {
            ciq_log( '[Conversion sync] skipping — no organization_id or api_key.' );
            return false;
        }

        $date = $date ?: gmdate( 'Y-m-d', strtotime( '-1 day' ) );
        $data = get_option( self::COUNT_OPTION, array() );

        if ( empty( $data[ $date ] ) ) {
            ciq_log( '[Conversion sync] no conversions for ' . $date . ', skipping.' );
            return true;
        }

        $payload = array(
            'organization_id' => $org_id,
            'date'            => $date,
            'total'           => $data[ $date ]['total'],
            'by_source'       => $data[ $date ]['by_source'],
            'events'          => $data[ $date ]['events'] ?? array(),
        );

        $response = wp_remote_post( 'https://conversioniq-app.com/api/conversions/sync', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );

        $code = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );
        $ok   = ( $code === 200 || $code === 201 );
        ciq_log( '[Conversion sync] → conversioniq-app.com/api/conversions/sync: HTTP ' . $code . ( $ok ? ' ✅' : ' ❌' ) );

        return $ok;
    }
}
