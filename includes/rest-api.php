<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extract HTML structure for AI analysis
 */
function conversioniq_extract_html_structure( $html ) {
    // Identify likely page sections based on content and structure
    $sections = array();

    // Collect visible headings text
    preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $headings );
    if ( ! empty( $headings[1] ) ) {
        $sections['headings'] = array_slice( array_map( 'wp_strip_all_tags', $headings[1] ), 0, 10 );
    }

    // Detect common sections by class/id patterns
    $section_patterns = array(
        'Hero' => 'hero|banner|jumbotron|intro|header-content',
        'Features' => 'feature|benefit|service|offer',
        'About' => 'about|story|mission|who-we-are',
        'Testimonials' => 'testimonial|review|feedback|social-proof',
        'Pricing' => 'pricing|plan|package|tier',
        'FAQ' => 'faq|question|accordion',
        'CTA' => 'cta|call-to-action|conversion|booking|contact-form',
        'Trust' => 'trust|guarantee|security|badge|certification',
    );

    $detected_sections = array();
    foreach ( $section_patterns as $section_name => $pattern ) {
        if ( preg_match( '/(?:class|id)=["\'][^"\']*(?:' . $pattern . ')[^"\']*["\']/i', $html ) ) {
            $detected_sections[] = $section_name . ' Section';
        }
    }

    // Build a concise summary for the AI
    $summary = "Page Structure Analysis:\n";
    if ( ! empty( $detected_sections ) ) {
        $summary .= 'Detected sections: ' . implode( ', ', $detected_sections ) . "\n";
    }
    if ( ! empty( $sections['headings'] ) ) {
        $summary .= 'Main headings: ' . implode( ' | ', $sections['headings'] ) . "\n";
    }
    $summary .= "\nNote: Use these section names when categorizing your suggestions.";

    return $summary;
}

add_action( 'rest_api_init', function() {
    // Authentication routes
    register_rest_route( 'conversioniq/v1', '/auth/status', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_auth_status',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    register_rest_route( 'conversioniq/v1', '/auth/login', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_auth_login',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    register_rest_route( 'conversioniq/v1', '/auth/register', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_auth_register',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    register_rest_route( 'conversioniq/v1', '/auth/logout', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_auth_logout',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    register_rest_route( 'conversioniq/v1', '/settings', array(
        array(
            'methods' => 'POST',
            'callback' => 'conversioniq_save_settings',
            'permission_callback' => function() { return current_user_can('manage_options'); }
        ),
        array(
            'methods' => 'GET',
            'callback' => 'conversioniq_get_settings',
            'permission_callback' => function() { return current_user_can('manage_options'); }
        ),
    ) );

    register_rest_route( 'conversioniq/v1', '/audit', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_run_audit',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    register_rest_route( 'conversioniq/v1', '/audits', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_list_audits',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    // Return published pages (id, title, permalink)
    register_rest_route( 'conversioniq/v1', '/pages', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_list_pages',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    // Get single page content for AI analysis
    register_rest_route( 'conversioniq/v1', '/page/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_get_page_content',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    register_rest_route( 'conversioniq/v1', '/report', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_generate_report',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    // Automated reporting settings
    register_rest_route( 'conversioniq/v1', '/automated-settings', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_get_automated_settings',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    register_rest_route( 'conversioniq/v1', '/automated-settings', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_save_automated_settings',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    // Auto-fill business information by analyzing homepage
    register_rest_route( 'conversioniq/v1', '/guess-business-info', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_guess_business_info',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    // Test email endpoint
    register_rest_route( 'conversioniq/v1', '/test-email', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_test_email',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );

    // Send manual audit report email
    register_rest_route( 'conversioniq/v1', '/send-manual-report', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_send_manual_report',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );
} );


function conversioniq_save_settings( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    if ( empty( $params ) ) {
        return new WP_REST_Response( array('success'=>false,'message'=>__('No settings provided','conversion-iq')), 400 );
    }
    // Save OpenAI API key separately for backend use
    if ( isset( $params['openai_api_key'] ) ) {
        update_option( 'conversioniq_api_key', sanitize_text_field( $params['openai_api_key'] ) );
        unset( $params['openai_api_key'] );
    }
    update_option( 'conversion_iq_settings', wp_json_encode( $params ) );
    return array( 'success' => true );
}

function conversioniq_get_settings() {
    $v = get_option( 'conversion_iq_settings', '{}' );
    $decoded = json_decode( $v, true );
    return rest_ensure_response( $decoded );
}

function conversioniq_get_automated_settings() {
    $settings = get_option( 'conversion_iq_automated_reports', array(
        'enabled' => false,
        'frequency' => 'weekly',
        'email' => '',
        'defaultPages' => array()
    ) );
    return rest_ensure_response( $settings );
}

function conversioniq_save_automated_settings( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    
    // Process and validate emails (comma-separated)
    $email_input = isset( $body['email'] ) ? sanitize_text_field( $body['email'] ) : '';
    $emails = array_map( 'trim', explode( ',', $email_input ) );
    $valid_emails = array_filter( $emails, 'is_email' );
    
    $settings = array(
        'enabled' => isset( $body['enabled'] ) ? (bool) $body['enabled'] : false,
        'frequency' => isset( $body['frequency'] ) ? sanitize_text_field( $body['frequency'] ) : 'weekly',
        'email' => implode( ', ', $valid_emails ),
        'defaultPages' => isset( $body['defaultPages'] ) ? array_map( 'intval', $body['defaultPages'] ) : array()
    );
    
    // Validate emails
    if ( $settings['enabled'] && empty( $valid_emails ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'At least one valid email address is required when automated reports are enabled'
        ), 400 );
    }
    
    if ( $settings['enabled'] && count( $valid_emails ) < count( $emails ) ) {
        $invalid_count = count( $emails ) - count( $valid_emails );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => "Found {$invalid_count} invalid email address(es). Please check your email list."
        ), 400 );
    }
    
    // Save settings
    update_option( 'conversion_iq_automated_reports', $settings );
    
    // Clear existing cron job
    $timestamp = wp_next_scheduled( 'conversioniq_automated_audit' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'conversioniq_automated_audit' );
    }
    
    // Schedule new cron job if enabled
    if ( $settings['enabled'] && ! empty( $settings['defaultPages'] ) ) {
        $next_run = conversioniq_get_next_run_time( $settings['frequency'] );
        wp_schedule_event( $next_run, 'conversioniq_' . $settings['frequency'], 'conversioniq_automated_audit' );
        
        error_log( '📅 Scheduled automated audit: ' . $settings['frequency'] . ' starting ' . date( 'Y-m-d H:i:s', $next_run ) );
    }
    
    return rest_ensure_response( array(
        'success' => true,
        'message' => 'Automated report settings saved successfully',
        'next_run' => $settings['enabled'] ? date( 'Y-m-d H:i:s', $next_run ?? time() ) : null
    ) );
}

function conversioniq_run_audit( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    $pages = isset( $body['pages'] ) ? $body['pages'] : array();
    if ( empty( $pages ) ) {
        return new WP_REST_Response( array('success'=>false,'message'=>__('No pages specified','conversion-iq')), 400 );
    }

    $business = json_decode( get_option( 'conversion_iq_settings', '{}'), true );
    
    // Research industry benchmarks once at start of audit
    error_log( '🔬 Researching industry benchmarks...' );
    $benchmark_research = ConversionIQ_AI::research_industry_benchmarks(
        isset( $business['industry'] ) ? $business['industry'] : '',
        isset( $business['audience'] ) ? $business['audience'] : '',
        isset( $business['goal'] ) ? $business['goal'] : ''
    );
    error_log( '📊 Benchmark research complete: avg=' . ( $benchmark_research['industry_average'] ?? 'N/A' ) . ', top=' . ( $benchmark_research['top_performers_threshold'] ?? 'N/A' ) );
    
    $results = array();
    
    foreach ( $pages as $page_id ) {
        $post = get_post( intval( $page_id ) );
        if ( ! $post ) continue;
        
        // Get clean page content
        $content = $post->post_content;
        $content = strip_shortcodes( $content );
        $content = wp_strip_all_tags( $content );
        
        // Fetch HTML structure for better AI analysis
        $page_url = get_permalink( $post );
        $html_structure = '';
        
        error_log( '🌐 Fetching HTML from: ' . $page_url );
        $response = wp_remote_get( $page_url, array(
            'timeout' => 10,
            'sslverify' => false,
        ) );
        
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $html = wp_remote_retrieve_body( $response );
            
            // Extract key HTML elements and their classes/IDs
            $html_structure = conversioniq_extract_html_structure( $html );
            error_log( '✅ HTML structure extracted (' . strlen( $html_structure ) . ' chars)' );
        } else {
            error_log( '⚠️ Could not fetch HTML: ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP error' ) );
        }
        
        $payload = array(
            'business' => $business,
            'page' => array(
                'title' => $post->post_title,
                'content' => $content,
                'url' => $page_url,
                'word_count' => str_word_count( $content ),
                'html_structure' => $html_structure,
            ),
        );
        
        error_log( '🔍 Running audit for: ' . $post->post_title );
        error_log( '📄 Content length: ' . strlen($content) . ' chars, Word count: ' . str_word_count($content) );
        
        $audit_start = microtime(true);
        try {
            $ai = ConversionIQ_AI::analyze( $payload );
            $audit_time = round((microtime(true) - $audit_start), 2);
            
            // Validate AI response structure
            if ( ! is_array( $ai ) ) {
                throw new Exception( 'AI returned invalid response type: ' . gettype( $ai ) );
            }
            
            // Add benchmark research to audit results
            $ai['benchmark_research'] = $benchmark_research;
            
            // Check for required fields and log diagnostic info
            $has_clarity = isset( $ai['clarity_score'] );
            $has_suggestions = isset( $ai['suggestions'] );
            $has_ai_flag = isset( $ai['ai_used'] );
            $ai_used = isset( $ai['ai_used'] ) ? $ai['ai_used'] : true;
            
            if ( ! $has_clarity || ! $has_suggestions ) {
                error_log( '⚠️ AI response missing required fields. Has clarity: ' . ($has_clarity ? 'YES' : 'NO') . ', Has suggestions: ' . ($has_suggestions ? 'YES' : 'NO') );
                error_log( '📋 Response keys: ' . json_encode( array_keys( $ai ) ) );
            }
            
            $insert_id = ConversionIQ_DB::insert_audit( $post->ID, $post->post_title, $ai );
            
            // Add company identifier for webhook tracking
            $account = get_option( 'conversioniq_account', null );
            $company_info = array(
                'company_name' => $account['company'] ?? '',
                'company_id' => $account['company_id'] ?? '',
                'site_url' => get_site_url()
            );
            
            $ai['insert_id'] = $insert_id;
            $ai['page_id'] = $post->ID;
            $ai['page_title'] = $post->post_title;
            $ai['page_url'] = $page_url;
            $ai['created_at'] = current_time( 'mysql' );
            $ai['company_info'] = $company_info;
            $ai['_debug'] = array(
                'audit_time' => $audit_time . 's',
                'ai_used' => $ai_used,
                'content_length' => strlen($content),
                'has_all_fields' => ($has_clarity && $has_suggestions),
                'status' => 'success'
            );
            $results[] = $ai;
            
            // Sync audit to Supabase cloud database
            try {
                $supabase_sync = new ConversionIQ_Supabase_Sync();
                $business_data = isset($payload['business']) ? $payload['business'] : array();
                $sync_success = $supabase_sync->send_audit(array(
                    'page_url' => $page_url,
                    'page_title' => $post->post_title,
                    'industry' => isset($business_data['industry']) ? $business_data['industry'] : null,
                    'clarity_score' => isset($ai['clarity_score']) ? $ai['clarity_score'] : null,
                    'emotional_score' => isset($ai['emotional_score']) ? $ai['emotional_score'] : null,
                    'cta_strength' => isset($ai['cta_strength']) ? $ai['cta_strength'] : null,
                    'readability_score' => isset($ai['readability_score']) ? $ai['readability_score'] : null,
                    'engagement_score' => isset($ai['engagement_score']) ? $ai['engagement_score'] : null,
                    'trust_score' => isset($ai['trust_score']) ? $ai['trust_score'] : null,
                    'overall_score' => isset($ai['overall_score']) ? $ai['overall_score'] : null,
                    'suggestions' => isset($ai['suggestions']) ? $ai['suggestions'] : array(),
                    'functionality_suggestions' => isset($ai['functionality_suggestions']) ? $ai['functionality_suggestions'] : array(),
                    'rewrites' => isset($ai['rewrites']) ? $ai['rewrites'] : array(),
                    'analysis_method' => isset($ai['analysis_method']) ? $ai['analysis_method'] : 'single',
                    'sections_analyzed' => isset($ai['sections_analyzed']) ? $ai['sections_analyzed'] : 1
                ));
                
                // Track usage for analytics
                $supabase_sync->track_usage('analyze_page');
                
                if (!$sync_success) {
                    error_log('ConversionIQ: Warning - Failed to sync audit to Supabase cloud');
                }
            } catch (Exception $e) {
                error_log('ConversionIQ: Supabase sync exception - ' . $e->getMessage());
            }
            
            // Send to webhook if configured
            conversioniq_send_webhook( $ai );
            
            error_log( '✅ Audit completed for: ' . $post->post_title . ' in ' . $audit_time . 's (AI used: ' . ($ai_used ? 'YES' : 'NO - FALLBACK') . ', scores: clarity=' . ( $ai['clarity_score'] ?? 'N/A' ) . ', cta=' . ( $ai['cta_strength'] ?? 'N/A' ) . ')' );
        } catch ( Exception $e ) {
            $audit_time = round((microtime(true) - $audit_start), 2);
            error_log( '❌ Audit EXCEPTION for ' . $post->post_title . ' after ' . $audit_time . 's: ' . $e->getMessage() );
            error_log( '❌ Exception trace: ' . $e->getTraceAsString() );
            // Add fallback result with debug info
            $results[] = array(
                'page_id' => $post->ID,
                'page_title' => $post->post_title,
                'page_url' => $page_url,
                'clarity_score' => 70,
                'emotional_score' => 70,
                'cta_strength' => 70,
                'readability_score' => 70,
                'engagement_score' => 70,
                'trust_score' => 70,
                'suggestions' => array(
                    array( 'text' => 'Audit failed: ' . $e->getMessage(), 'target' => '' )
                ),
                'ai_used' => false,
                'created_at' => current_time( 'mysql' ),
                '_debug' => array(
                    'error' => $e->getMessage(),
                    'audit_time' => $audit_time . 's',
                    'status' => 'exception'
                )
            );
        }
    }

    return rest_ensure_response( array('success'=>true,'results'=>$results) );
}

function conversioniq_get_next_run_time( $frequency ) {
    $now = current_time( 'timestamp' );
    
    switch ( $frequency ) {
        case 'weekly':
            // Next Monday at 9 AM
            $next = strtotime( 'next Monday 9:00', $now );
            break;
        case 'monthly':
            // 1st of next month at 9 AM
            $next = strtotime( 'first day of next month 9:00', $now );
            break;
        case 'bimonthly':
            // 1st of month after next at 9 AM
            $next = strtotime( 'first day of next month +1 month 9:00', $now );
            break;
        default:
            $next = strtotime( '+1 week', $now );
    }
    
    return $next;
}

function conversioniq_list_audits( WP_REST_Request $request ) {
    $rows = ConversionIQ_DB::get_audits();
    
    // Flatten structure: merge 'data' fields with top-level fields
    $formatted = array();
    foreach ( $rows as $row ) {
        $audit = is_array( $row['data'] ) ? $row['data'] : array();
        $audit['id'] = $row['id'];
        $audit['page_id'] = $row['page_id'];
        $audit['page_title'] = $row['page_title']; // Ensure page_title is always present
        $audit['created_at'] = $row['created_at'];
        $formatted[] = $audit;
    }
    
    return rest_ensure_response( $formatted );
}

function conversioniq_generate_report( WP_REST_Request $request ) {
    // Clear any output that might have been sent
    if ( ob_get_level() ) {
        ob_clean();
    }
    
    error_log('🔵 REST API: Report generation endpoint called');
    
    $params = $request->get_json_params();
    if ( empty( $params['audit_id'] ) ) {
        error_log('❌ REST API: Missing audit_id');
        return new WP_REST_Response( array('success'=>false,'message'=>'Missing audit_id'), 400 );
    }
    
    $audit_id = intval( $params['audit_id'] );
    error_log('🔵 REST API: Audit ID: ' . $audit_id);
    
    $audit = ConversionIQ_DB::get_audit( $audit_id );
    if ( ! $audit ) {
        error_log('❌ REST API: Audit not found: ' . $audit_id);
        return new WP_REST_Response( array('success'=>false,'message'=>'Audit not found'), 404 );
    }
    
    error_log('🔵 REST API: Audit found, calling generate_pdf_for_audit()');
    
    // Generate report with error handling
    try {
        $res = ConversionIQ_Reports::generate_pdf_for_audit( $audit );
        error_log('🔵 REST API: generate_pdf_for_audit() returned: ' . json_encode($res));
        return rest_ensure_response( $res );
    } catch (Exception $e) {
        error_log('❌ REST API: Exception caught: ' . $e->getMessage());
        error_log('❌ REST API: Stack trace: ' . $e->getTraceAsString());
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Report generation error: ' . $e->getMessage()
        ), 500 );
    } catch (Error $e) {
        error_log('❌ REST API: Fatal error caught: ' . $e->getMessage());
        error_log('❌ REST API: Stack trace: ' . $e->getTraceAsString());
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Report generation fatal error: ' . $e->getMessage()
        ), 500 );
    }
}

function conversioniq_list_pages( WP_REST_Request $request ) {
    $args = array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        'sort_column' => 'post_title',
        'sort_order'  => 'asc',
        'number'      => 999,
    );
    $pages = get_pages( $args );
    $out = array();
    foreach ( $pages as $p ) {
        $out[] = array(
            'id'        => $p->ID,
            'title'     => $p->post_title,
            'permalink' => get_permalink( $p ),
        );
    }
    return rest_ensure_response( $out );
}

function conversioniq_get_page_content( WP_REST_Request $request ) {
    $id = intval( $request['id'] );
    $post = get_post( $id );
    if ( ! $post ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Page not found', 'conversion-iq' ) ), 404 );
    }
    $content = apply_filters( 'the_content', $post->post_content );
    $excerpt = wp_strip_all_tags( $post->post_excerpt );
    return rest_ensure_response( array(
        'id'         => $post->ID,
        'title'      => $post->post_title,
        'permalink'  => get_permalink( $post ),
        'content'    => $content,
        'excerpt'    => $excerpt,
        'word_count' => str_word_count( wp_strip_all_tags( $content ) ),
    ) );
}



function conversioniq_guess_business_info( WP_REST_Request $request ) {
    error_log( '🔍 Auto-fill: Fetching homepage content' );
    
    // Get homepage URL
    $home_url = get_home_url();
    $response = wp_remote_get( $home_url, array(
        'timeout' => 15,
        'sslverify' => false,
    ) );
    
    if ( is_wp_error( $response ) ) {
        error_log( '❌ Failed to fetch homepage: ' . $response->get_error_message() );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to fetch homepage' ), 500 );
    }
    
    $html = wp_remote_retrieve_body( $response );
    $content = wp_strip_all_tags( $html );
    $content = preg_replace( '/\s+/', ' ', $content ); // Normalize whitespace
    $content = substr( $content, 0, 3000 ); // Limit to first 3000 chars
    
    error_log( '✅ Homepage content fetched (' . strlen( $content ) . ' chars)' );
    
    // Build AI prompt for business info extraction
    $prompt = "You are analyzing a homepage to extract business information. Extract the following details from the page content below:

**Required Information:**
- Industry/Niche: What industry or market does this business operate in?
- Product/Service: What specific products or services do they sell?
- Target Audience: Who are their customers? (demographics, roles, etc.)
- Pain Points: What problems do they solve for customers? (comma-separated list)
- Competitors: Who might their competitors be in this space? (comma-separated list of similar businesses)
- Goal: What is the primary conversion goal? (e.g., 'Book a call', 'Purchase product', 'Sign up for trial')
- Additional Info: Any other relevant context about the business (unique selling points, special offers, guarantees, etc.)

**Homepage Content:**
{$content}

**Return format (JSON only, no markdown):**
{
  \"industry\": \"Industry name\",
  \"product\": \"What they sell\",
  \"audience\": \"Who they sell to\",
  \"pain_points\": \"Problem 1, Problem 2, Problem 3\",
  \"competitors\": \"Competitor 1, Competitor 2\",
  \"goal\": \"Primary conversion goal\",
  \"additional_info\": \"Other relevant context\"
}

IMPORTANT: Return ONLY valid JSON, no code blocks, no explanations.";

    // Call AI
    $ai_body = array(
        'model' => 'gpt-4o-mini',
        'messages' => array(
            array(
                'role' => 'user',
                'content' => $prompt
            )
        ),
        'max_tokens' => 2000,
        'temperature' => 0.7,
        'stream' => false
    );
    
    $ai_args = array(
        'headers' => array(
            'Authorization' => 'Bearer s2_7b1143d048014d04b7d489a17671b1a7',
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode( $ai_body ),
        'timeout' => 60,
        'sslverify' => true,
    );
    
    error_log( '🤖 Calling AI to extract business info...' );
    $ai_response = wp_remote_post( 'https://routellm.abacus.ai/v1/chat/completions', $ai_args );
    
    if ( is_wp_error( $ai_response ) ) {
        error_log( '❌ AI API error: ' . $ai_response->get_error_message() );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'AI analysis failed' ), 500 );
    }
    
    $status_code = wp_remote_retrieve_response_code( $ai_response );
    error_log( '📡 Auto-fill API response status: ' . $status_code );
    
    if ( $status_code !== 200 ) {
        $error_body = wp_remote_retrieve_body( $ai_response );
        error_log( '❌ Auto-fill API returned non-200 status: ' . $status_code );
        error_log( '❌ Response body: ' . substr( $error_body, 0, 500 ) );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'AI API error: ' . $status_code ), 500 );
    }
    
    $response_body = wp_remote_retrieve_body( $ai_response );
    error_log( '📄 Response body length: ' . strlen( $response_body ) . ' chars' );
    error_log( '📄 First 500 chars: ' . substr( $response_body, 0, 500 ) );
    
    $ai_data = json_decode( $response_body, true );
    
    if ( ! $ai_data ) {
        error_log( '❌ Failed to parse AI response as JSON: ' . json_last_error_msg() );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid JSON response' ), 500 );
    }
    
    error_log( '🔍 Response structure keys: ' . json_encode( array_keys( $ai_data ) ) );
    
    if ( ! isset( $ai_data['choices'][0]['message']['content'] ) ) {
        error_log( '⚠️ No AI response content' );
        error_log( '⚠️ Full response structure: ' . json_encode( $ai_data ) );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'AI returned no content' ), 500 );
    }
    
    $ai_content = trim( $ai_data['choices'][0]['message']['content'] );
    
    // Remove markdown code blocks if present
    if ( preg_match( '/```json\s*(.*?)\s*```/s', $ai_content, $matches ) ) {
        $ai_content = $matches[1];
    } elseif ( preg_match( '/```\s*(.*?)\s*```/s', $ai_content, $matches ) ) {
        $ai_content = $matches[1];
    }
    
    $fields = json_decode( $ai_content, true );
    
    if ( ! $fields ) {
        error_log( '⚠️ Failed to parse AI response as JSON' );
        error_log( 'Raw AI response: ' . substr( $ai_content, 0, 500 ) );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to parse AI response' ), 500 );
    }
    
    error_log( '✅ Successfully extracted business info' );
    
    return rest_ensure_response( array(
        'success' => true,
        'fields' => $fields
    ) );
}

/**
 * Send audit results to webhook endpoint
 */
function conversioniq_send_webhook( $audit_data ) {
    // Hardcoded webhook URL (your support portal endpoint)
    $webhook_url = 'https://webtecsupportportal.abacusai.app/api/webhook/conversion-iq';
    
    // Get account info for API key
    $account = get_option( 'conversioniq_account', null );
    
    // Skip if no account (user not registered)
    if ( ! $account || empty( $account['api_key'] ) ) {
        error_log( '⚠️ Webhook skipped: No account registered' );
        return;
    }
    
    $api_key = $account['api_key'];
    
    // Prepare payload
    $payload = array(
        'company' => array(
            'name' => $account['company'] ?? '',
            'id' => $account['company_id'] ?? '',
            'email' => $account['email'] ?? ''
        ),
        'page_title' => $audit_data['page_title'] ?? '',
        'page_url' => $audit_data['page_url'] ?? '',
        'page_id' => $audit_data['page_id'] ?? 0,
        'scores' => array(
            'clarity_score' => $audit_data['clarity_score'] ?? 0,
            'emotional_score' => $audit_data['emotional_score'] ?? 0,
            'cta_strength' => $audit_data['cta_strength'] ?? 0,
            'readability_score' => $audit_data['readability_score'] ?? 0,
            'engagement_score' => $audit_data['engagement_score'] ?? 0,
            'trust_score' => $audit_data['trust_score'] ?? 0,
        ),
        'suggestions' => $audit_data['suggestions'] ?? array(),
        'ai_used' => $audit_data['ai_used'] ?? true,
        'created_at' => $audit_data['created_at'] ?? current_time( 'mysql' ),
        'site_url' => get_site_url(),
        'site_name' => get_bloginfo( 'name' )
    );
    
    // Prepare headers with API key from account
    $headers = array(
        'Content-Type' => 'application/json',
        'User-Agent' => 'ConversionIQ-WordPress-Plugin/1.0',
        'X-API-Key' => $api_key
    );
    
    // Send webhook (blocking to ensure delivery and get response)
    $response = wp_remote_post( $webhook_url, array(
        'headers' => $headers,
        'body' => wp_json_encode( $payload ),
        'timeout' => 15,
        'blocking' => true // Blocking to ensure delivery
    ) );
    
    // Log detailed webhook results
    if ( is_wp_error( $response ) ) {
        error_log( '❌ Webhook FAILED: ' . $response->get_error_message() );
        error_log( '   URL: ' . $webhook_url );
        error_log( '   API Key: ' . substr($api_key, 0, 8) . '...' );
    } else {
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        error_log( '✅ Webhook SENT successfully!' );
        error_log( '   URL: ' . $webhook_url );
        error_log( '   Status: ' . $status_code );
        error_log( '   Response: ' . $body );
        error_log( '   Page: ' . $payload['page_title'] );
    }
}

/**
 * Authentication functions
 */
function conversioniq_auth_status() {
    $account = get_option( 'conversioniq_account', null );
    
    if ( ! $account ) {
        return rest_ensure_response( array(
            'authenticated' => false
        ) );
    }
    
    // Remove sensitive data
    $safe_account = $account;
    if ( isset( $safe_account['password_hash'] ) ) {
        unset( $safe_account['password_hash'] );
    }
    
    return rest_ensure_response( array(
        'authenticated' => true,
        'account' => $safe_account
    ) );
}

function conversioniq_auth_login( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    $username = sanitize_text_field( $params['username'] ?? '' );
    $password = $params['password'] ?? '';
    
    if ( empty( $username ) || empty( $password ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Username and password are required'
        ), 400 );
    }
    
    // Validate credentials against Supabase
    try {
        $supabase_sync = new ConversionIQ_Supabase_Sync();
        $org_data = $supabase_sync->validate_login( $username, $password );
        
        if ( ! $org_data ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid username or password'
            ), 401 );
        }
        
        // Store/update local WordPress account data for caching
        $account = array(
            'full_name' => $org_data['user_full_name'] ?? '',
            'email' => $org_data['user_email'] ?? '',
            'company' => $org_data['company_name'] ?? '',
            'company_id' => $org_data['company_id'] ?? '',
            'username' => $org_data['username'] ?? $username,
            'password_hash' => $org_data['password_hash'] ?? '',
            'api_key' => $org_data['api_key'] ?? '',
            'created_at' => $org_data['created_at'] ?? current_time( 'mysql' ),
            'last_audit' => null
        );
        
        // Store organization ID for future sync operations
        if ( isset( $org_data['id'] ) ) {
            update_option( 'conversioniq_organization_id', $org_data['id'] );
        }
        if ( isset( $org_data['api_key'] ) ) {
            update_option( 'conversioniq_api_key', $org_data['api_key'] );
        }
        
        update_option( 'conversioniq_account', $account );
        
        // Remove password hash before sending
        unset( $account['password_hash'] );
        
        return rest_ensure_response( array(
            'success' => true,
            'account' => $account
        ) );
        
    } catch ( Exception $e ) {
        error_log( 'ConversionIQ Login Error: ' . $e->getMessage() );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Login failed. Please try again.'
        ), 500 );
    }
}

function conversioniq_auth_register( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    
    $full_name = sanitize_text_field( $params['full_name'] ?? '' );
    $email = sanitize_email( $params['email'] ?? '' );
    $company = sanitize_text_field( $params['company'] ?? '' );
    $username = sanitize_text_field( $params['username'] ?? '' );
    $password = $params['password'] ?? '';
    
    // Validation
    if ( empty( $full_name ) || empty( $email ) || empty( $company ) || empty( $username ) || empty( $password ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'All fields are required'
        ), 400 );
    }
    
    if ( ! is_email( $email ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Invalid email address'
        ), 400 );
    }
    
    if ( strlen( $password ) < 6 ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Password must be at least 6 characters'
        ), 400 );
    }
    
    // Check if account already exists in Supabase
    try {
        $supabase_sync = new ConversionIQ_Supabase_Sync();
        $existing_account = $supabase_sync->check_account_exists( $email, $username );
        
        if ( $existing_account ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'An account with this email or username already exists. Please login or use different credentials.'
            ), 400 );
        }
    } catch ( Exception $e ) {
        error_log( 'ConversionIQ Registration Check Error: ' . $e->getMessage() );
    }
    
    // Generate unique API key and company identifier
    $api_key = bin2hex( random_bytes( 24 ) ); // 48 character hex string
    $company_id = sanitize_title( $company ) . '-' . substr( md5( $email . time() ), 0, 8 );
    
    // Create account data
    $account = array(
        'full_name' => $full_name,
        'email' => $email,
        'company' => $company,
        'company_id' => $company_id,
        'username' => $username,
        'password_hash' => password_hash( $password, PASSWORD_BCRYPT ),
        'api_key' => $api_key,
        'created_at' => current_time( 'mysql' ),
        'last_audit' => null
    );
    
    // Create account in Supabase
    try {
        $supabase_sync = new ConversionIQ_Supabase_Sync();
        $supabase_org = $supabase_sync->create_account( $account );
        
        if ( ! $supabase_org ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Failed to create account. Please try again.'
            ), 500 );
        }
        
        // Store organization ID for future sync operations
        if ( isset( $supabase_org['id'] ) ) {
            update_option( 'conversioniq_organization_id', $supabase_org['id'] );
        }
        if ( isset( $supabase_org['api_key'] ) ) {
            update_option( 'conversioniq_api_key', $supabase_org['api_key'] );
        }
        
        // Store account locally as well
        update_option( 'conversioniq_account', $account );
        
        error_log('ConversionIQ: Account created successfully in Supabase with ID: ' . $supabase_org['id']);
        
    } catch ( Exception $e ) {
        error_log( 'ConversionIQ Registration Error: ' . $e->getMessage() );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Failed to create account. Please try again.'
        ), 500 );
    }
    
    // Remove password hash before sending
    unset( $account['password_hash'] );
    
    return rest_ensure_response( array(
        'success' => true,
        'account' => $account,
        'message' => 'Account created successfully'
    ) );
}

function conversioniq_auth_logout() {
    // For now, just return success
    // In a more complex setup, you might want to implement session management
    return rest_ensure_response( array(
        'success' => true
    ) );
}

/**
 * Test email functionality
 */
function conversioniq_test_email( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    $test_email = sanitize_email( $params['email'] ?? '' );
    
    // Get settings to use configured email or fallback
    $settings = get_option( 'conversion_iq_automated_reports', array() );
    $email = ! empty( $test_email ) ? $test_email : ( $settings['email'] ?? get_option( 'admin_email' ) );
    
    if ( ! is_email( $email ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Invalid email address'
        ), 400 );
    }
    
    $site_name = get_bloginfo( 'name' );
    $subject = '✅ Conversion IQ Test Email - ' . date( 'M j, Y g:i A' );
    
    $message = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f3f4f6; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .header { background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); padding: 40px 30px; text-align: center; }
        .header h1 { margin: 0; color: #ffffff; font-size: 28px; font-weight: 700; }
        .content { padding: 40px 30px; }
        .success-box { background: #ecfdf5; border-left: 4px solid #10b981; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .success-box h2 { margin: 0 0 10px 0; color: #065f46; font-size: 20px; }
        .success-box p { margin: 0; color: #047857; line-height: 1.6; }
        .info-grid { background: #f9fafb; border-radius: 12px; padding: 20px; margin: 20px 0; }
        .info-row { padding: 12px 0; border-bottom: 1px solid #e5e7eb; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .info-value { font-size: 16px; color: #111827; margin-top: 4px; }
        .footer { background: #f9fafb; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb; }
        .footer p { margin: 5px 0; font-size: 13px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Email System Working!</h1>
        </div>
        <div class="content">
            <div class="success-box">
                <h2>Test Successful</h2>
                <p>Your Conversion IQ email system is configured correctly and working as expected. Automated audit reports will be delivered to this address.</p>
            </div>
            
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">WordPress Site</div>
                    <div class="info-value">' . esc_html( $site_name ) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Site URL</div>
                    <div class="info-value">' . esc_html( get_home_url() ) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Recipient Email</div>
                    <div class="info-value">' . esc_html( $email ) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Test Time</div>
                    <div class="info-value">' . date( 'F j, Y g:i A' ) . '</div>
                </div>
            </div>
            
            <p style="color: #6b7280; line-height: 1.6; margin-top: 20px;">
                <strong>What happens next?</strong><br>
                When you enable automated reports in Conversion IQ settings, audit reports will be sent to this email address according to your chosen schedule (weekly, monthly, or bi-monthly).
            </p>
        </div>
        <div class="footer">
            <p><strong>Conversion IQ</strong> by Webtec</p>
            <p>AI-Powered Website Conversion Optimization</p>
        </div>
    </div>
</body>
</html>';
    
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>'
    );
    
    error_log( '📧 Sending test email to: ' . $email );
    $sent = wp_mail( $email, $subject, $message, $headers );
    
    if ( $sent ) {
        error_log( '✅ Test email sent successfully' );
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Test email sent successfully to ' . $email
        ) );
    } else {
        error_log( '❌ Failed to send test email' );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Failed to send test email. Check your WordPress email configuration.'
        ), 500 );
    }
}

/**
 * Send manual audit report with real results
 */
function conversioniq_send_manual_report( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    $email_input = sanitize_text_field( $params['email'] ?? '' );
    $page_ids = isset( $params['page_ids'] ) ? array_map( 'intval', $params['page_ids'] ) : array();
    
    $log = array();
    $log[] = '🔍 Starting manual report generation...';
    
    // Get settings to use configured email or fallback
    $settings = get_option( 'conversion_iq_automated_reports', array() );
    if ( empty( $email_input ) ) {
        $email_input = $settings['email'] ?? get_option( 'admin_email' );
    }
    
    // Process comma-separated emails
    $emails = array_map( 'trim', explode( ',', $email_input ) );
    $valid_emails = array_filter( $emails, 'is_email' );
    $email = implode( ', ', $valid_emails );
    
    $log[] = '📧 Target email(s): ' . $email;
    
    if ( empty( $valid_emails ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'At least one valid email address is required',
            'log' => $log
        ), 400 );
    }
    
    if ( empty( $page_ids ) ) {
        $log[] = '❌ No pages selected';
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'No pages selected for the report',
            'log' => $log
        ), 400 );
    }
    
    $log[] = '📄 Selected page IDs: ' . implode( ', ', $page_ids );
    
    // Get the most recent audits for the selected pages
    global $wpdb;
    $table = $wpdb->prefix . 'conversioniq_audits';
    $placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
    
    $log[] = '🔎 Querying database for audits...';
    
    $audits = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table 
         WHERE page_id IN ($placeholders) 
         ORDER BY created_at DESC",
        ...$page_ids
    ), ARRAY_A );
    
    // If no audits exist, run them automatically
    if ( empty( $audits ) ) {
        $log[] = '📊 No existing audits found - running audits automatically...';
        
        // Run audits for each page
        foreach ( $page_ids as $page_id ) {
            $page = get_post( $page_id );
            if ( ! $page ) {
                $log[] = '  ⚠️ Page ID ' . $page_id . ' not found, skipping';
                continue;
            }
            
            $log[] = '  🔄 Running audit for: ' . $page->post_title;
            
            // Get page content
            $page_url = get_permalink( $page_id );
            $content = $page->post_content;
            $content = strip_shortcodes( $content );
            $content = wp_strip_all_tags( $content );
            
            // Fetch HTML structure
            $html_structure = '';
            $response = wp_remote_get( $page_url, array(
                'timeout' => 10,
                'sslverify' => false,
            ) );
            
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $html = wp_remote_retrieve_body( $response );
                $html_structure = conversioniq_extract_html_structure( $html );
            }
            
            // Get business settings
            $business_settings = get_option( 'conversion_iq_settings', '{}' );
            $business = json_decode( $business_settings, true );
            
            // Prepare payload for AI analysis
            $payload = array(
                'business' => $business,
                'page' => array(
                    'title' => $page->post_title,
                    'content' => $content,
                    'url' => $page_url,
                    'word_count' => str_word_count( $content ),
                    'html_structure' => $html_structure,
                ),
            );
            
            // Run the AI analysis
            if ( ! class_exists( 'ConversionIQ_AI' ) ) {
                require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
            }
            
            try {
                $ai_result = ConversionIQ_AI::analyze( $payload );
                
                if ( is_array( $ai_result ) ) {
                    // Save audit to database
                    $inserted = $wpdb->insert(
                        $table,
                        array(
                            'page_id' => $page_id,
                            'page_title' => $page->post_title,
                            'page_url' => $page_url,
                            'data' => wp_json_encode( $ai_result ),
                            'ai_used' => true,
                            'created_at' => current_time( 'mysql' )
                        ),
                        array( '%d', '%s', '%s', '%s', '%d', '%s' )
                    );
                    
                    if ( $inserted ) {
                        $log[] = '    ✅ Audit completed and saved (ID: ' . $wpdb->insert_id . ')';
                        
                        // Sync to Supabase cloud database
                        try {
                            $supabase_sync = new ConversionIQ_Supabase_Sync();
                            $sync_success = $supabase_sync->send_audit(array(
                                'page_url' => $page_url,
                                'page_title' => $page->post_title,
                                'industry' => isset($business['industry']) ? $business['industry'] : null,
                                'clarity_score' => isset($ai_result['clarity_score']) ? $ai_result['clarity_score'] : null,
                                'emotional_score' => isset($ai_result['emotional_score']) ? $ai_result['emotional_score'] : null,
                                'cta_strength' => isset($ai_result['cta_strength']) ? $ai_result['cta_strength'] : null,
                                'readability_score' => isset($ai_result['readability_score']) ? $ai_result['readability_score'] : null,
                                'engagement_score' => isset($ai_result['engagement_score']) ? $ai_result['engagement_score'] : null,
                                'trust_score' => isset($ai_result['trust_score']) ? $ai_result['trust_score'] : null,
                                'overall_score' => isset($ai_result['overall_score']) ? $ai_result['overall_score'] : null,
                                'suggestions' => isset($ai_result['suggestions']) ? $ai_result['suggestions'] : array(),
                                'functionality_suggestions' => isset($ai_result['functionality_suggestions']) ? $ai_result['functionality_suggestions'] : array(),
                                'rewrites' => isset($ai_result['rewrites']) ? $ai_result['rewrites'] : array(),
                                'analysis_method' => isset($ai_result['analysis_method']) ? $ai_result['analysis_method'] : 'single',
                                'sections_analyzed' => isset($ai_result['sections_analyzed']) ? $ai_result['sections_analyzed'] : 1
                            ));
                            
                            $supabase_sync->track_usage('analyze_page');
                            
                            if ($sync_success) {
                                $log[] = '    ☁️ Synced to Supabase cloud';
                            }
                        } catch (Exception $e) {
                            $log[] = '    ⚠️ Supabase sync skipped: ' . $e->getMessage();
                        }
                    } else {
                        $log[] = '    ⚠️ Audit completed but failed to save: ' . $wpdb->last_error;
                    }
                } else {
                    $log[] = '    ❌ Audit failed: Invalid response from AI';
                }
            } catch ( Exception $e ) {
                $log[] = '    ❌ Audit failed: ' . $e->getMessage();
            }
        }
        
        // Re-query for the newly created audits
        $log[] = '🔄 Fetching newly created audits...';
        $audits = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table 
             WHERE page_id IN ($placeholders) 
             ORDER BY created_at DESC",
            ...$page_ids
        ), ARRAY_A );
        
        if ( empty( $audits ) ) {
            $log[] = '❌ Failed to create audits';
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Failed to generate audits for the selected pages.',
                'log' => $log
            ), 500 );
        }
    }
    
    $log[] = '✅ Found ' . count( $audits ) . ' audit record(s) in database';
    
    // Group audits by page_id and get the most recent one for each
    $latest_audits = array();
    $seen_pages = array();
    
    foreach ( $audits as $audit ) {
        $page_id = $audit['page_id'];
        if ( ! in_array( $page_id, $seen_pages ) ) {
            $audit['data'] = json_decode( $audit['data'], true );
            $latest_audits[] = $audit;
            $seen_pages[] = $page_id;
        }
    }
    
    $log[] = '📊 Processing ' . count( $latest_audits ) . ' unique page audit(s)';
    
    // Prepare results array in the format expected by the email function
    $results = array();
    foreach ( $latest_audits as $audit ) {
        $data = $audit['data'];
        $log[] = '  ✓ ' . $audit['page_title'] . ' (ID: ' . $audit['id'] . ')';
        $results[] = array(
            'insert_id' => $audit['id'],
            'page_title' => $audit['page_title'],
            'page_url' => $audit['page_url'],
            'clarity_score' => $data['clarity_score'] ?? 0,
            'emotional_score' => $data['emotional_score'] ?? 0,
            'cta_strength' => $data['cta_strength'] ?? 0,
            'readability_score' => $data['readability_score'] ?? 0,
            'engagement_score' => $data['engagement_score'] ?? 0,
            'trust_score' => $data['trust_score'] ?? 0
        );
    }
    
    // Get business context
    $log[] = '🏢 Loading business context...';
    $business_settings = get_option( 'conversion_iq_settings', '{}' );
    $business = json_decode( $business_settings, true );
    $business_context = array(
        'industry' => $business['industry'] ?? '',
        'audience' => $business['audience'] ?? '',
        'goal' => $business['goal'] ?? ''
    );
    
    // Use the automated reports class to send the email
    $log[] = '📄 Generating PDF reports...';
    if ( ! class_exists( 'ConversionIQ_Automated_Reports' ) ) {
        require_once CONVERSION_IQ_DIR . 'includes/class-automated-reports.php';
    }
    
    // Call the send_email_report method using reflection since it's private
    $log[] = '📧 Preparing email with attachments...';
    $reflection = new ReflectionClass( 'ConversionIQ_Automated_Reports' );
    $method = $reflection->getMethod( 'send_email_report' );
    $method->setAccessible( true );
    
    $sent = $method->invoke( null, $email, $results, $business_context );
    
    if ( $sent ) {
        $log[] = '✅ Email sent successfully!';
        error_log( '✅ Manual audit report sent to: ' . $email . ' with ' . count( $results ) . ' page(s)' );
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Audit report sent successfully to ' . $email . ' with ' . count( $results ) . ' page(s)',
            'log' => $log
        ) );
    } else {
        $log[] = '❌ Failed to send email - check WordPress email configuration';
        error_log( '❌ Failed to send manual audit report' );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Failed to send audit report. Check your WordPress email configuration.',
            'log' => $log
        ), 500 );
    }
}