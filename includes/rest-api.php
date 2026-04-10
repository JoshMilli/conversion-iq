<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if any email address in the list contains 'basecamp'
 */
function conversioniq_has_basecamp_email($emails)
{
    if (is_string($emails)) {
        $emails = array_map('trim', explode(',', $emails));
    }

    foreach ($emails as $email) {
        if (stripos($email, 'basecamp') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Extract HTML structure for AI analysis
 */
function conversioniq_extract_html_structure($html)
{
    // Identify likely page sections based on content and structure
    $sections = array();

    // Collect visible headings text
    preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $headings);
    if (!empty($headings[1])) {
        $sections['headings'] = array_slice(array_map('wp_strip_all_tags', $headings[1]), 0, 10);
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
    foreach ($section_patterns as $section_name => $pattern) {
        if (preg_match('/(?:class|id)=["\'][^"\']*(?:' . $pattern . ')[^"\']*["\']/i', $html)) {
            $detected_sections[] = $section_name . ' Section';
        }
    }

    // Extract testimonial names specifically for trust scoring
    $testimonial_names = array();
    
    // Strategy 1: Look for jet-listing or Elementor dynamic field patterns (specific to user's site structure)
    // Extract names from h3/h6 tags and titles from span tags within jet-listing-dynamic-field__content
    error_log('ðŸ” Strategy 1: Searching for jet-listing-dynamic-field__content patterns');
    
    if (preg_match_all('/<(?:h[3-6]|div)[^>]*class="[^"]*jet-listing-dynamic-field__content[^"]*"[^>]*>([^<]+)<\/(?:h[3-6]|div)>/is', $html, $name_elements)) {
        $potential_names = array_map('wp_strip_all_tags', $name_elements[1]);
        error_log('ðŸ” Found ' . count($potential_names) . ' potential name elements: ' . implode(', ', array_slice($potential_names, 0, 10)));
        
        // Extract titles from span elements with same class
        if (preg_match_all('/<span[^>]*class="[^"]*jet-listing-dynamic-field__content[^"]*"[^>]*>([^<]+)<\/span>/is', $html, $title_elements)) {
            $potential_titles = array_map('wp_strip_all_tags', $title_elements[1]);
            error_log('ðŸ” Found ' . count($potential_titles) . ' potential title elements: ' . implode(', ', array_slice($potential_titles, 0, 10)));
            
            // Match names with titles (skip non-name elements like quote text)
            $name_count = 0;
            foreach ($potential_names as $idx => $name) {
                $name = trim($name);
                // Check if this looks like a person's name (2+ words, starts with capital, less than 50 chars)
                if (preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-zA-Z]+)+)$/u', $name) && strlen($name) < 50) {
                    // Find corresponding title - check both single words and multi-word titles
                    if (isset($potential_titles[$idx])) {
                        $title = trim($potential_titles[$idx]);
                        // Match common executive/business titles (single or multi-word)
                        if (preg_match('/^(CEO|COO|CTO|CFO|President|Vice President|VP|Director|Manager|Founder|Co-Founder|Owner|Chief|Head of [A-Za-z]+|Senior [A-Za-z]+|Lead [A-Za-z]+)$/i', $title)) {
                            $formatted_title = strtoupper($title);
                            // Normalize common multi-word titles
                            if (preg_match('/^HEAD OF (.+)$/i', $formatted_title, $head_match)) {
                                $formatted_title = 'HEAD OF ' . strtoupper($head_match[1]);
                            }
                            $testimonial_names[] = $name . ', ' . $formatted_title;
                            $name_count++;
                            error_log('âœ… Matched testimonial: ' . $name . ', ' . $formatted_title);
                        } else {
                            error_log('âš ï¸ Name found but title not recognized: ' . $name . ' -> "' . $title . '"');
                        }
                    }
                }
            }
            error_log('ðŸ” Strategy 1 result: Found ' . $name_count . ' valid testimonials');
        } else {
            error_log('âš ï¸ No span elements with jet-listing-dynamic-field__content found');
        }
    } else {
        error_log('âš ï¸ No h3-h6/div elements with jet-listing-dynamic-field__content found');
    }
    
    // Strategy 2: Look for testimonial blocks with traditional class names
    if (empty($testimonial_names)) {
        if (preg_match_all('/<[^>]*class="[^"]*(?:testimonial|review|feedback|client-info)[^"]*"[^>]*>(.*?)<\/[^>]+>/is', $html, $testimonial_blocks)) {
            foreach ($testimonial_blocks[1] as $block) {
                // Look for name in h3-h6 or strong tags
                if (preg_match('/<(?:h[3-6]|strong|div)[^>]*>([A-Z][a-z]+\s+[A-Z][a-zA-Z]+[^<]*)<\/(?:h[3-6]|strong|div)>/i', $block, $name_match)) {
                    $name = trim(wp_strip_all_tags($name_match[1]));
                    // Look for title in the same block
                    if (preg_match('/<(?:span|p|div)[^>]*>(CEO|COO|CTO|CFO|President|Director|Manager|Founder|Owner)<\/(?:span|p|div)>/i', $block, $title_match)) {
                        $testimonial_names[] = $name . ', ' . strtoupper($title_match[1]);
                    } else if (preg_match('/\b(CEO|COO|CTO|CFO|President|Director|Manager|Founder)\b/i', $block, $title_match)) {
                        $testimonial_names[] = $name . ', ' . strtoupper($title_match[1]);
                    }
                }
            }
        }
    }
    
    // Strategy 3: Fallback - look for name + title pattern with larger gap (but filter aggressively)
    if (empty($testimonial_names)) {
        if (preg_match_all('/\b([A-Z][a-z]{2,}\s+[A-Z][a-z]{2,}(?:\s+[A-Z][a-z]+)?)\b[\s\S]{0,300}?\b(CEO|COO|CTO|CFO|President|Director|Manager|Founder)\b/i', $html, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $name = trim($matches[1][$i]);
                $title = strtoupper($matches[2][$i]);
                
                // Aggressive filtering for false positives
                if (!preg_match('/^(Read More|Learn More|Click Here|Get Started|Contact Us|About Us|View All|See More|Our Team|Web Designer|Jhon Doe|John Doe|Jane Doe)$/i', $name) &&
                    !preg_match('/\b(class|div|span|button|link|script|cookie|hidden)\b/i', $name)) {
                    $testimonial_names[] = $name . ', ' . $title;
                }
            }
        }
    }
    
    // Limit to first 10 names and ensure uniqueness
    $testimonial_names = array_slice(array_unique($testimonial_names), 0, 10);
    
    // Log extracted testimonial names for debugging
    if (!empty($testimonial_names)) {
        error_log('ðŸ‘¤ Extracted testimonial names: ' . implode('; ', $testimonial_names));
    } else {
        error_log('ðŸ‘¤ No testimonial names found in HTML structure (' . strlen($html) . ' characters fetched)');
        error_log('ðŸ“„ HTML preview (first 500 chars): ' . substr($html, 0, 500));
    }

    // Build a concise summary for the AI
    $summary = "Page Structure Analysis:\n";
    if (!empty($detected_sections)) {
        $summary .= 'Detected sections: ' . implode(', ', $detected_sections) . "\n";
    }
    if (!empty($sections['headings'])) {
        $summary .= 'Main headings: ' . implode(' | ', $sections['headings']) . "\n";
    }
    if (!empty($testimonial_names)) {
        $summary .= 'Found testimonial attributions: ' . implode('; ', $testimonial_names) . "\n";
        $summary .= "**IMPORTANT**: These names indicate testimonials with attribution. Trust score should be 60+ (not 0).\n";
    }
    $summary .= "\nNote: Use these section names when categorizing your suggestions.";

    return $summary;
}

/**
 * Query WordPress Custom Post Types for review/testimonial entries
 * to supplement HTML trust signal analysis with database-sourced reviews.
 *
 * Handles CPTs like 'review', 'reviews', '_reviews', 'testimonial', etc.
 * Meta fields checked follow common patterns (e.g. _name, _position, _review-copy).
 *
 * @return string Formatted context string, or empty string if no reviews found.
 */
function conversioniq_get_cpt_reviews() {
    static $cached_result = null;
    if ($cached_result !== null) {
        return $cached_result;
    }

    // Scan all registered post types for review/testimonial CPTs
    $all_types = get_post_types(array(), 'names');
    $review_cpt_candidates = array();
    foreach ($all_types as $post_type) {
        $slug = strtolower($post_type);
        if (strpos($slug, 'review') !== false || strpos($slug, 'testimonial') !== false) {
            $review_cpt_candidates[] = $post_type;
        }
    }

    if (empty($review_cpt_candidates)) {
        error_log('â„¹ï¸ No review/testimonial CPTs detected on this site');
        $cached_result = '';
        return '';
    }

    error_log('ðŸ” Detected review CPTs: ' . implode(', ', $review_cpt_candidates));

    // Common meta field names used by popular review/testimonial CPT setups
    $name_fields     = array('_name', 'reviewer_name', 'client_name', 'author_name', 'name', 'review_author');
    $position_fields = array('_position', 'reviewer_position', 'client_position', 'job_title', 'position', 'role');
    $text_fields     = array('_review-copy', 'review_text', 'review_content', 'testimonial_text', 'review_body', 'review_copy');

    $all_reviews = array();

    foreach ($review_cpt_candidates as $cpt) {
        $posts = get_posts(array(
            'post_type'   => $cpt,
            'post_status' => 'publish',
            'numberposts' => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ));

        if (empty($posts)) {
            continue;
        }

        error_log('ðŸ“ CPT "' . $cpt . '": ' . count($posts) . ' published reviews');

        foreach ($posts as $post) {
            // Reviewer name
            $reviewer_name = '';
            foreach ($name_fields as $field) {
                $val = trim((string) get_post_meta($post->ID, $field, true));
                if ($val !== '') {
                    $reviewer_name = sanitize_text_field($val);
                    break;
                }
            }
            // Fallback: post title when it looks like a person's name
            if ($reviewer_name === '' && preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-zA-Z]+)+$/', trim($post->post_title))) {
                $reviewer_name = trim($post->post_title);
            }

            // Reviewer position / job title
            $reviewer_position = '';
            foreach ($position_fields as $field) {
                $val = trim((string) get_post_meta($post->ID, $field, true));
                if ($val !== '') {
                    $reviewer_position = sanitize_text_field($val);
                    break;
                }
            }

            // Review body text
            $review_text = '';
            foreach ($text_fields as $field) {
                $val = trim((string) get_post_meta($post->ID, $field, true));
                if ($val !== '') {
                    $review_text = wp_strip_all_tags($val);
                    break;
                }
            }
            if ($review_text === '' && !empty($post->post_content)) {
                $review_text = wp_strip_all_tags($post->post_content);
            }

            if ($reviewer_name === '' && $review_text === '') {
                continue;
            }

            // Format: "Name, Position: "text preview""
            $entry = $reviewer_name;
            if ($reviewer_name !== '' && $reviewer_position !== '') {
                $entry .= ', ' . $reviewer_position;
            }
            if ($review_text !== '') {
                $preview = mb_strlen($review_text) > 150 ? mb_substr($review_text, 0, 150) . '...' : $review_text;
                $entry .= ($entry !== '' ? ': "' : '"') . $preview . '"';
            }

            $all_reviews[] = $entry;
        }
    }

    if (empty($all_reviews)) {
        error_log('â„¹ï¸ Review CPTs found but no publishable review data could be extracted');
        $cached_result = '';
        return '';
    }

    $count = count($all_reviews);
    error_log('âœ… ' . $count . ' CPT reviews extracted for trust scoring');

    $summary  = "\n\n**SITE REVIEWS (WordPress Custom Post Type - Database):**\n";
    $summary .= 'This site has ' . $count . ' published customer reviews stored in a WordPress Custom Post Type (these are manually curated reviews often not rendered in standard page HTML).' . "\n";
    foreach (array_slice($all_reviews, 0, 10) as $i => $review) {
        $summary .= ($i + 1) . '. ' . $review . "\n";
    }
    if ($count > 10) {
        $summary .= '... and ' . ($count - 10) . " more reviews in the database.\n";
    }
    $summary .= '**TRUST SIGNAL**: These ' . $count . ' verified manual customer reviews are a strong trust indicator. ';
    $summary .= 'The trust score must reflect this social proof (minimum 65 when named reviews exist, 75+ when reviews have both name and position).';

    $cached_result = $summary;
    return $summary;
}

add_action('rest_api_init', function () {
    // License routes
    register_rest_route('conversioniq/v1', '/license/status', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_license_status',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

    register_rest_route('conversioniq/v1', '/license/activate', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_license_activate',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/settings', array(
                array(
                'methods' => 'POST',
                'callback' => 'conversioniq_save_settings',
                'permission_callback' => function () {
                return current_user_can('manage_options'); }
                ),
                    array(
                    'methods' => 'GET',
                    'callback' => 'conversioniq_get_settings',
                    'permission_callback' => function () {
                return current_user_can('manage_options'); }
                ),
            ));

            register_rest_route('conversioniq/v1', '/audit', array(
                'methods' => 'POST',
                'callback' => 'conversioniq_run_audit',
                'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/audits', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_list_audits',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Return published pages (id, title, permalink)
        register_rest_route('conversioniq/v1', '/pages', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_list_pages',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Get single page content for AI analysis
        register_rest_route('conversioniq/v1', '/page/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_get_page_content',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/report', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_generate_report',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Automated reporting settings
        register_rest_route('conversioniq/v1', '/automated-settings', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_get_automated_settings',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/automated-settings', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_save_automated_settings',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Auto-fill business information by analyzing homepage
        register_rest_route('conversioniq/v1', '/guess-business-info', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_guess_business_info',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Test email endpoint
        register_rest_route('conversioniq/v1', '/test-email', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_test_email',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Send manual audit report email
        register_rest_route('conversioniq/v1', '/send-manual-report', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_send_manual_report',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Google Analytics endpoints
        register_rest_route('conversioniq/v1', '/ga/status', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_ga_status',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/ga/save-credentials', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_ga_save_credentials',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/ga/auth-url', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_ga_auth_url',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/ga/properties', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_ga_properties',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/ga/save-property', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_ga_save_property',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/ga/disconnect', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_ga_disconnect',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/ga/page-data', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_ga_page_data',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/ga/top-pages', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_ga_top_pages',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/score-history', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_score_history',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    });


function conversioniq_save_settings(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_REST_Response(array('success' => false, 'message' => __('No settings provided', 'conversion-iq')), 400);
    }
    // Save OpenAI API key separately for backend use
    if (isset($params['openai_api_key'])) {
        update_option('conversioniq_api_key', sanitize_text_field($params['openai_api_key']));
        unset($params['openai_api_key']);
    }

    // Save KnockKnock settings separately
    if (isset($params['knockknock_company_id'])) {
        update_option('conversioniq_knockknock_company_id', sanitize_text_field($params['knockknock_company_id']));
        unset($params['knockknock_company_id']);
    }
    if (isset($params['knockknock_webhook_secret'])) {
        update_option('conversioniq_knockknock_webhook_secret', sanitize_text_field($params['knockknock_webhook_secret']));
        unset($params['knockknock_webhook_secret']);
    }

    update_option('conversion_iq_settings', wp_json_encode($params));

    return array('success' => true);
}

function conversioniq_get_settings()
{
    $v = get_option('conversion_iq_settings', '{}');
    $decoded = json_decode($v, true);

    // Add KnockKnock settings
    $decoded['knockknock_company_id'] = get_option('conversioniq_knockknock_company_id', '');
    $decoded['knockknock_webhook_secret'] = get_option('conversioniq_knockknock_webhook_secret', '');
    $decoded['knockknock_webhook_url'] = home_url('/wp-json/conversioniq/v1/webhook');

    return rest_ensure_response($decoded);
}

function conversioniq_get_automated_settings()
{
    $settings = get_option('conversion_iq_automated_reports', array(
        'enabled' => false,
        'frequency' => 'weekly',
        'email' => '',
        'defaultPages' => array()
    ));
    return rest_ensure_response($settings);
}

function conversioniq_save_automated_settings(WP_REST_Request $request)
{
    $body = $request->get_json_params();

    // Process and validate emails (comma-separated)
    $email_input = isset($body['email']) ? sanitize_text_field($body['email']) : '';
    $emails = array_map('trim', explode(',', $email_input));
    $valid_emails = array_filter($emails, 'is_email');

    $settings = array(
        'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : false,
        'frequency' => isset($body['frequency']) ? sanitize_text_field($body['frequency']) : 'weekly',
        'email' => implode(', ', $valid_emails),
        'defaultPages' => isset($body['defaultPages']) ? array_map('intval', $body['defaultPages']) : array()
    );

    // Validate emails
    if ($settings['enabled'] && empty($valid_emails)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'At least one valid email address is required when automated reports are enabled'
        ), 400);
    }

    if ($settings['enabled'] && count($valid_emails) < count($emails)) {
        $invalid_count = count($emails) - count($valid_emails);
        return new WP_REST_Response(array(
            'success' => false,
            'message' => "Found {$invalid_count} invalid email address(es). Please check your email list."
        ), 400);
    }

    // Save settings
    update_option('conversion_iq_automated_reports', $settings);

    // Clear existing cron job
    $timestamp = wp_next_scheduled('conversioniq_automated_audit');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'conversioniq_automated_audit');
    }

    // Schedule new cron job if enabled
    if ($settings['enabled'] && !empty($settings['defaultPages'])) {
        $next_run = conversioniq_get_next_run_time($settings['frequency']);
        wp_schedule_event($next_run, 'conversioniq_' . $settings['frequency'], 'conversioniq_automated_audit');

        ciq_log('Scheduled automated audit: ' . $settings['frequency']);
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Automated report settings saved successfully',
        'next_run' => $settings['enabled'] ? date('Y-m-d H:i:s', $next_run ?? time()) : null
    ));
}

function conversioniq_run_audit(WP_REST_Request $request)
{
    // Rate limiting: one audit request per 30 seconds per user
    $user_id = get_current_user_id();
    $transient_key = 'ciq_audit_lock_' . $user_id;
    if (get_transient($transient_key)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => __('Please wait 30 seconds between audit requests.', 'conversion-iq')
        ), 429);
    }
    set_transient($transient_key, 1, 30);

    $body = $request->get_json_params();
    $pages = isset($body['pages']) ? $body['pages'] : array();
    if (empty($pages)) {
        return new WP_REST_Response(array('success' => false, 'message' => __('No pages specified', 'conversion-iq')), 400);
    }

    // Cap at 10 pages per request
    $pages = array_slice($pages, 0, 10);

    // Validate page IDs: must be integers referencing published pages/posts
    $allowed_types = array('page', 'post');
    $valid_pages = array();
    foreach ($pages as $pid) {
        $pid = absint($pid);
        if ($pid <= 0) continue;
        $post = get_post($pid);
        if ($post && $post->post_status === 'publish' && in_array($post->post_type, $allowed_types, true)) {
            $valid_pages[] = $pid;
        }
    }
    if (empty($valid_pages)) {
        return new WP_REST_Response(array('success' => false, 'message' => __('No valid published pages found.', 'conversion-iq')), 400);
    }
    $pages = $valid_pages;

    $business = json_decode(get_option('conversion_iq_settings', '{}'), true);

    // Research industry benchmarks once at start of audit
    error_log('ðŸ”¬ Researching industry benchmarks...');
    $benchmark_research = ConversionIQ_AI::research_industry_benchmarks(
        isset($business['industry']) ? $business['industry'] : '',
        isset($business['audience']) ? $business['audience'] : '',
        isset($business['goal']) ? $business['goal'] : ''
    );
    error_log('ðŸ“Š Benchmark research complete: avg=' . ($benchmark_research['industry_average'] ?? 'N/A') . ', top=' . ($benchmark_research['top_performers_threshold'] ?? 'N/A'));

    $results = array();

    foreach ($pages as $page_id) {
        $post = get_post(intval($page_id));
        if (!$post)
            continue;

        // Get clean page content
        $content = $post->post_content;
        $content = strip_shortcodes($content);
        $content = wp_strip_all_tags($content);

        // Fetch HTML structure for better AI analysis
        $page_url = get_permalink($post);
        $html_structure = '';

        ciq_log('Fetching HTML from: ' . $page_url);
        $response = wp_remote_get($page_url, array(
            'timeout' => 10,
            'sslverify' => false,
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $html = wp_remote_retrieve_body($response);

            // Extract key HTML elements and their classes/IDs
            $html_structure = conversioniq_extract_html_structure($html);
            ciq_log('HTML structure extracted (' . strlen($html_structure) . ' chars)');
        }
        else {
            ciq_log('Could not fetch HTML: ' . (is_wp_error($response) ? $response->get_error_message() : 'HTTP error'));
        }

        // Supplement HTML-based trust signals with reviews stored in WordPress CPTs
        $cpt_reviews = conversioniq_get_cpt_reviews();
        if (!empty($cpt_reviews)) {
            $html_structure .= $cpt_reviews;
            ciq_log('CPT reviews appended to trust signal context');
        }

        $payload = array(
            'business' => $business,
            'page' => array(
                'title' => $post->post_title,
                'content' => $content,
                'url' => $page_url,
                'word_count' => str_word_count($content),
                'html_structure' => $html_structure,
            ),
        );

        // Calculate content hash for change detection
        $content_hash = hash('sha256', $content . $html_structure);

        ciq_log('Running audit for: ' . $post->post_title);
                ciq_log('Content hash: ' . $content_hash);

        $audit_start = microtime(true);
        try {
            $ai = ConversionIQ_AI::analyze($payload);
            $audit_time = round((microtime(true) - $audit_start), 2);

            // Validate AI response structure
            if (!is_array($ai)) {
                throw new Exception('AI returned invalid response type: ' . gettype($ai));
            }

            // Add benchmark research to audit results
            $ai['benchmark_research'] = $benchmark_research;

            // Check for required fields and log diagnostic info
            $has_clarity = isset($ai['clarity_score']);
            $has_suggestions = isset($ai['suggestions']);
            $has_ai_flag = isset($ai['ai_used']);
            $ai_used = isset($ai['ai_used']) ? $ai['ai_used'] : true;

            if (!$has_clarity || !$has_suggestions) {
                error_log('âš ï¸ AI response missing required fields. Has clarity: ' . ($has_clarity ? 'YES' : 'NO') . ', Has suggestions: ' . ($has_suggestions ? 'YES' : 'NO'));
                error_log('ðŸ“‹ Response keys: ' . json_encode(array_keys($ai)));
            }

            $insert_id = ConversionIQ_DB::insert_audit($post->ID, $post->post_title, $ai, $content_hash);

            // Invalidate score history cache
            delete_transient('ciq_score_history');

            // Add company identifier for webhook tracking
            $account = get_option('conversioniq_account', null);
            $company_info = array(
                'company_name' => $account['company'] ?? '',
                'company_id' => $account['company_id'] ?? '',
                'site_url' => get_site_url()
            );

            $ai['insert_id'] = $insert_id;
            $ai['page_id'] = $post->ID;
            $ai['page_title'] = $post->post_title;
            $ai['page_url'] = $page_url;
            $ai['created_at'] = current_time('mysql');
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
                    ciq_log('Failed to sync audit to Supabase cloud');
                }
            }
            catch (Exception $e) {
                ciq_log('Supabase sync exception - ' . $e->getMessage());
            }

            // Send to webhook if configured
            conversioniq_send_webhook($ai);

            ciq_log('Audit completed for: ' . $post->post_title . ' in ' . $audit_time . 's');
        }
        catch (Exception $e) {
            $audit_time = round((microtime(true) - $audit_start), 2);
            error_log('ConversionIQ: Audit EXCEPTION for ' . $post->post_title . ': ' . $e->getMessage());
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
                        array('text' => 'Audit failed: ' . $e->getMessage(), 'target' => '')
                ),
                'ai_used' => false,
                'created_at' => current_time('mysql'),
                '_debug' => array(
                    'error' => $e->getMessage(),
                    'audit_time' => $audit_time . 's',
                    'status' => 'exception'
                )
            );
        }
    }

    return rest_ensure_response(array('success' => true, 'results' => $results));
}

function conversioniq_get_next_run_time($frequency)
{
    $now = current_time('timestamp');

    switch ($frequency) {
        case 'weekly':
            // Next Monday at 9 AM
            $next = strtotime('next Monday 9:00', $now);
            break;
        case 'monthly':
            // 1st of next month at 9 AM
            $next = strtotime('first day of next month 9:00', $now);
            break;
        case 'bimonthly':
            // 1st of month after next at 9 AM
            $next = strtotime('first day of next month +1 month 9:00', $now);
            break;
        default:
            $next = strtotime('+1 week', $now);
    }

    return $next;
}

function conversioniq_score_history(WP_REST_Request $request)
{
    // Cache for 5 minutes to avoid decoding all JSON blobs on every request
    $cached = get_transient('ciq_score_history');
    if ($cached !== false) {
        return rest_ensure_response($cached);
    }
    $history = ConversionIQ_DB::get_score_history();
    set_transient('ciq_score_history', $history, 5 * MINUTE_IN_SECONDS);
    return rest_ensure_response($history);
}

function conversioniq_list_audits(WP_REST_Request $request)
{
    $rows = ConversionIQ_DB::get_audits();

    // Flatten structure: merge 'data' fields with top-level fields
    $formatted = array();
    foreach ($rows as $row) {
        $audit = is_array($row['data']) ? $row['data'] : array();
        $audit['id'] = $row['id'];
        $audit['page_id'] = $row['page_id'];
        $audit['page_title'] = $row['page_title']; // Ensure page_title is always present
        $audit['created_at'] = $row['created_at'];

        // Add content change detection
        $content_changed = ConversionIQ_DB::has_content_changed($row['id']);
        if ($content_changed !== null) {
            $audit['content_changed'] = $content_changed;
        }

        $formatted[] = $audit;
    }

    return rest_ensure_response($formatted);
}

function conversioniq_generate_report(WP_REST_Request $request)
{
    // Clear any output that might have been sent
    if (ob_get_level()) {
        ob_clean();
    }

    error_log('ðŸ”µ REST API: Report generation endpoint called');

    $params = $request->get_json_params();
    if (empty($params['audit_id'])) {
        error_log('âŒ REST API: Missing audit_id');
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing audit_id'), 400);
    }

    $audit_id = intval($params['audit_id']);
    error_log('ðŸ”µ REST API: Audit ID: ' . $audit_id);

    $audit = ConversionIQ_DB::get_audit($audit_id);
    if (!$audit) {
        error_log('âŒ REST API: Audit not found: ' . $audit_id);
        return new WP_REST_Response(array('success' => false, 'message' => 'Audit not found'), 404);
    }

    error_log('ðŸ”µ REST API: Audit found, calling generate_pdf_for_audit()');

    // Generate report with error handling
    try {
        $res = ConversionIQ_Reports::generate_pdf_for_audit($audit);
        error_log('ðŸ”µ REST API: generate_pdf_for_audit() returned: ' . json_encode($res));
        return rest_ensure_response($res);
    }
    catch (Exception $e) {
        error_log('âŒ REST API: Exception caught: ' . $e->getMessage());
        error_log('âŒ REST API: Stack trace: ' . $e->getTraceAsString());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Report generation error: ' . $e->getMessage()
        ), 500);
    }
    catch (Error $e) {
        error_log('âŒ REST API: Fatal error caught: ' . $e->getMessage());
        error_log('âŒ REST API: Stack trace: ' . $e->getTraceAsString());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Report generation fatal error: ' . $e->getMessage()
        ), 500);
    }
}

function conversioniq_list_pages(WP_REST_Request $request)
{
    $args = array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'sort_column' => 'post_title',
        'sort_order' => 'asc',
        'number' => 999,
    );
    $pages = get_pages($args);
    $out = array();
    foreach ($pages as $p) {
        $out[] = array(
            'id' => $p->ID,
            'title' => $p->post_title,
            'permalink' => get_permalink($p),
        );
    }
    return rest_ensure_response($out);
}

function conversioniq_get_page_content(WP_REST_Request $request)
{
    $id = intval($request['id']);
    $post = get_post($id);
    if (!$post) {
        return new WP_REST_Response(array('success' => false, 'message' => __('Page not found', 'conversion-iq')), 404);
    }
    $content = apply_filters('the_content', $post->post_content);
    $excerpt = wp_strip_all_tags($post->post_excerpt);
    return rest_ensure_response(array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'permalink' => get_permalink($post),
        'content' => $content,
        'excerpt' => $excerpt,
        'word_count' => str_word_count(wp_strip_all_tags($content)),
    ));
}



function conversioniq_guess_business_info(WP_REST_Request $request)
{
    error_log('ðŸ” Auto-fill: Fetching homepage content');

    // Get homepage URL
    $home_url = get_home_url();
    $response = wp_remote_get($home_url, array(
        'timeout' => 15,
        'sslverify' => false,
    ));

    if (is_wp_error($response)) {
        error_log('âŒ Failed to fetch homepage: ' . $response->get_error_message());
        return new WP_REST_Response(array('success' => false, 'message' => 'Failed to fetch homepage'), 500);
    }

    $html = wp_remote_retrieve_body($response);
    $content = wp_strip_all_tags($html);
    $content = preg_replace('/\s+/', ' ', $content); // Normalize whitespace
    $content = substr($content, 0, 3000); // Limit to first 3000 chars

    error_log('âœ… Homepage content fetched (' . strlen($content) . ' chars)');

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
    $api_key = get_option('conversioniq_api_key', '');
    if (empty($api_key)) {
        error_log('❌ Guess fields: No API key found — license must be activated first.');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'License not activated. Please activate your license to use this feature.',
        ), 403);
    }

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
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode($ai_body),
        'timeout' => 60,
        'sslverify' => true,
    );

    error_log('ðŸ¤– Calling AI to extract business info...');
    $ai_response = wp_remote_post('https://routellm.abacus.ai/v1/chat/completions', $ai_args);

    if (is_wp_error($ai_response)) {
        error_log('âŒ AI API error: ' . $ai_response->get_error_message());
        return new WP_REST_Response(array('success' => false, 'message' => 'AI analysis failed'), 500);
    }

    $status_code = wp_remote_retrieve_response_code($ai_response);
    error_log('ðŸ“¡ Auto-fill API response status: ' . $status_code);

    if ($status_code !== 200) {
        $error_body = wp_remote_retrieve_body($ai_response);
        error_log('âŒ Auto-fill API returned non-200 status: ' . $status_code);
        error_log('âŒ Response body: ' . substr($error_body, 0, 500));
        return new WP_REST_Response(array('success' => false, 'message' => 'AI API error: ' . $status_code), 500);
    }

    $response_body = wp_remote_retrieve_body($ai_response);
    error_log('ðŸ“„ Response body length: ' . strlen($response_body) . ' chars');
    error_log('ðŸ“„ First 500 chars: ' . substr($response_body, 0, 500));

    $ai_data = json_decode($response_body, true);

    if (!$ai_data) {
        error_log('âŒ Failed to parse AI response as JSON: ' . json_last_error_msg());
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid JSON response'), 500);
    }

    error_log('ðŸ” Response structure keys: ' . json_encode(array_keys($ai_data)));

    if (!isset($ai_data['choices'][0]['message']['content'])) {
        error_log('âš ï¸ No AI response content');
        error_log('âš ï¸ Full response structure: ' . json_encode($ai_data));
        return new WP_REST_Response(array('success' => false, 'message' => 'AI returned no content'), 500);
    }

    $ai_content = trim($ai_data['choices'][0]['message']['content']);

    // Remove markdown code blocks if present
    if (preg_match('/```json\s*(.*?)\s*```/s', $ai_content, $matches)) {
        $ai_content = $matches[1];
    }
    elseif (preg_match('/```\s*(.*?)\s*```/s', $ai_content, $matches)) {
        $ai_content = $matches[1];
    }

    $fields = json_decode($ai_content, true);

    if (!$fields) {
        error_log('âš ï¸ Failed to parse AI response as JSON');
        error_log('Raw AI response: ' . substr($ai_content, 0, 500));
        return new WP_REST_Response(array('success' => false, 'message' => 'Failed to parse AI response'), 500);
    }

    error_log('âœ… Successfully extracted business info');

    return rest_ensure_response(array(
        'success' => true,
        'fields' => $fields
    ));
}

/**
 * Send audit results to webhook endpoint
 */
function conversioniq_send_webhook($audit_data)
{
    // Hardcoded webhook URL (your support portal endpoint)
    $webhook_url = 'https://webtecsupportportal.abacusai.app/api/webhook/conversion-iq';

    // Get account info for API key
    $account = get_option('conversioniq_account', null);

    // Skip if no account (user not registered)
    if (!$account || empty($account['api_key'])) {
        error_log('âš ï¸ Webhook skipped: No account registered');
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
        'created_at' => $audit_data['created_at'] ?? current_time('mysql'),
        'site_url' => get_site_url(),
        'site_name' => get_bloginfo('name')
    );

    // Prepare headers with API key from account
    $headers = array(
        'Content-Type' => 'application/json',
        'User-Agent' => 'ConversionIQ-WordPress-Plugin/1.0',
        'X-API-Key' => $api_key
    );

    // Send webhook (blocking to ensure delivery and get response)
    $response = wp_remote_post($webhook_url, array(
        'headers' => $headers,
        'body' => wp_json_encode($payload),
        'timeout' => 15,
        'blocking' => true // Blocking to ensure delivery
    ));

    // Log detailed webhook results
    if (is_wp_error($response)) {
        error_log('âŒ Webhook FAILED: ' . $response->get_error_message());
        error_log('   URL: ' . $webhook_url);
        error_log('   API Key: ' . substr($api_key, 0, 8) . '...');
    }
    else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('âœ… Webhook SENT successfully!');
        error_log('   URL: ' . $webhook_url);
        error_log('   Status: ' . $status_code);
        error_log('   Response: ' . $body);
        error_log('   Page: ' . $payload['page_title']);
    }
}

/**
 * License functions
 */
function conversioniq_license_status()
{
    $license_key    = get_option('conversioniq_license_key', '');
    $license_status = get_option('conversioniq_license_status', 'inactive');
    $validated_at   = get_option('conversioniq_license_validated_at', 0);

    // Grace period: treat as active if last successful validation was within 7 days
    if ($license_status !== 'active' && $validated_at) {
        if ((time() - (int) $validated_at) < (7 * DAY_IN_SECONDS)) {
            $license_status = 'active';
        }
    }

    $customer = get_option('conversioniq_license_customer', null);

    return rest_ensure_response(array(
        'activated'    => ($license_status === 'active'),
        'license_key'  => $license_key ? substr($license_key, 0, 7) . '...' : '',
        'license_key_full' => $license_key,
        'status'       => $license_status,
        'validated_at' => $validated_at,
        'customer'     => $customer,
    ));
}

function conversioniq_license_activate(WP_REST_Request $request)
{
    $params      = $request->get_json_params();
    $license_key = sanitize_text_field($params['license_key'] ?? '');

    if (empty($license_key)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'License key is required',
        ), 400);
    }

    // Basic format check: CIQ-XXXXX-XXXXX-XXXXX-XXXXX
    if (!preg_match('/^CIQ-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/i', $license_key)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid license key format. Keys look like: CIQ-XXXXX-XXXXX-XXXXX-XXXXX',
        ), 400);
    }

    // Call the Conversion IQ licensing server
    $response = wp_remote_post('https://conversioniq-app.com/api/validate-license', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'license_key' => $license_key,
            'site_url'    => get_site_url(),
        )),
    ));

    if (is_wp_error($response)) {
        error_log('ConversionIQ License Validation Error: ' . $response->get_error_message());
        // Allow activation if we already had a recent successful validation (grace period)
        $validated_at = get_option('conversioniq_license_validated_at', 0);
        if ($validated_at && (time() - (int) $validated_at) < (7 * DAY_IN_SECONDS)) {
            return rest_ensure_response(array(
                'success' => true,
                'message' => 'License server unreachable - using cached validation.',
                'status'  => 'active',
            ));
        }
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not reach the license server. Please try again.',
        ), 503);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 || empty($body['valid'])) {
        $msg = $body['message'] ?? 'License key is not valid or has expired.';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $msg,
        ), 400);
    }

    // Store the validated license
    update_option('conversioniq_license_key', $license_key);
    update_option('conversioniq_license_status', 'active');
    update_option('conversioniq_license_validated_at', time());

    // Store API key if the validation response includes one
    if (!empty($body['api_key'])) {
        update_option('conversioniq_api_key', sanitize_text_field($body['api_key']));
        error_log('ConversionIQ License: API key stored from validation response');
    }

    // Store customer info if the server returned it
    $customer = null;
    if (!empty($body['customer']) && is_array($body['customer'])) {
        $customer = array(
            'name'    => sanitize_text_field($body['customer']['name'] ?? ''),
            'email'   => sanitize_email($body['customer']['email'] ?? ''),
            'company' => sanitize_text_field($body['customer']['company'] ?? ''),
            'plan'    => sanitize_text_field($body['customer']['plan'] ?? ''),
        );
        update_option('conversioniq_license_customer', $customer);
    }

    // Trigger a config sync to pull branding, feature flags, and API key from the SaaS backend
    if (class_exists('ConversionIQ_Config_Manager')) {
        ConversionIQ_Config_Manager::sync_from_saas();
    }

    return rest_ensure_response(array(
        'success'  => true,
        'message'  => 'License activated successfully!',
        'status'   => 'active',
        'customer' => $customer,
    ));
}

/**
 * Test email functionality
 */
function conversioniq_test_email(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $test_email = sanitize_email($params['email'] ?? '');

    // Get settings to use configured email or fallback
    $settings = get_option('conversion_iq_automated_reports', array());
    $email = !empty($test_email) ? $test_email : ($settings['email'] ?? get_option('admin_email'));

    if (!is_email($email)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid email address'
        ), 400);
    }

    $site_name = get_bloginfo('name');
    $subject = 'âœ… Conversion IQ Test Email - ' . date('M j, Y g:i A');

    // Check if this is a Basecamp email
    $is_basecamp = conversioniq_has_basecamp_email($email);

    if ($is_basecamp) {
        // Send plain text version for Basecamp
        $message = "CONVERSION IQ TEST EMAIL\n";
        $message .= "=================================\n\n";
        $message .= "Email System Working!\n\n";
        $message .= "Your Conversion IQ email system is configured correctly and working as expected.\n";
        $message .= "Automated audit reports will be delivered to this address.\n\n";
        $message .= "SITE INFORMATION:\n";
        $message .= "- WordPress Site: " . $site_name . "\n";
        $message .= "- Site URL: " . get_home_url() . "\n";
        $message .= "- Recipient Email: " . $email . "\n";
        $message .= "- Test Time: " . date('F j, Y g:i A') . "\n\n";
        $message .= "WHAT HAPPENS NEXT?\n";
        $message .= "When you enable automated reports in Conversion IQ settings, audit reports will be sent\n";
        $message .= "to this email address according to your chosen schedule (weekly, monthly, or bi-monthly).\n\n";
        $message .= "---\n";
        $message .= "Conversion IQ by Webtec\n";
        $message .= "AI-Powered Website Conversion Optimization\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );
    }
    else {
        // Send HTML version for regular emails
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
            <h1>âœ… Email System Working!</h1>
        </div>
        <div class="content">
            <div class="success-box">
                <h2>Test Successful</h2>
                <p>Your Conversion IQ email system is configured correctly and working as expected. Automated audit reports will be delivered to this address.</p>
            </div>
            
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">WordPress Site</div>
                    <div class="info-value">' . esc_html($site_name) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Site URL</div>
                    <div class="info-value">' . esc_html(get_home_url()) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Recipient Email</div>
                    <div class="info-value">' . esc_html($email) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Test Time</div>
                    <div class="info-value">' . date('F j, Y g:i A') . '</div>
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
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );
    }

    error_log('ðŸ“§ Sending test email to: ' . $email . ($is_basecamp ? ' (Basecamp - Plain Text)' : ' (HTML)'));
    $sent = wp_mail($email, $subject, $message, $headers);

    if ($sent) {
        error_log('âœ… Test email sent successfully');
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Test email sent successfully to ' . $email
        ));
    }
    else {
        error_log('âŒ Failed to send test email');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to send test email. Check your WordPress email configuration.'
        ), 500);
    }
}

/**
 * Send manual audit report with real results
 */
function conversioniq_send_manual_report(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $email_input = sanitize_text_field($params['email'] ?? '');
    $page_ids = isset($params['page_ids']) ? array_map('intval', $params['page_ids']) : array();

    $log = array();
    $log[] = 'ðŸ” Starting manual report generation...';

    // Get settings to use configured email or fallback
    $settings = get_option('conversion_iq_automated_reports', array());
    if (empty($email_input)) {
        $email_input = $settings['email'] ?? get_option('admin_email');
    }

    // Process comma-separated emails
    $emails = array_map('trim', explode(',', $email_input));
    $valid_emails = array_filter($emails, 'is_email');
    $email = implode(', ', $valid_emails);

    $log[] = 'ðŸ“§ Target email(s): ' . $email;

    if (empty($valid_emails)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'At least one valid email address is required',
            'log' => $log
        ), 400);
    }

    if (empty($page_ids)) {
        $log[] = 'âŒ No pages selected';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No pages selected for the report',
            'log' => $log
        ), 400);
    }

    $log[] = 'ðŸ“„ Selected page IDs: ' . implode(', ', $page_ids);

    // Get the most recent audits for the selected pages
    global $wpdb;
    $table = $wpdb->prefix . 'conversioniq_audits';
    $placeholders = implode(',', array_fill(0, count($page_ids), '%d'));

    $log[] = 'ðŸ”Ž Querying database for audits...';

    $audits = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE page_id IN ($placeholders) 
         ORDER BY created_at DESC",
        ...$page_ids
    ), ARRAY_A);

    // If no audits exist, run them automatically
    if (empty($audits)) {
        $log[] = 'ðŸ“Š No existing audits found - running audits automatically...';

        // Run audits for each page
        foreach ($page_ids as $page_id) {
            $page = get_post($page_id);
            if (!$page) {
                $log[] = '  âš ï¸ Page ID ' . $page_id . ' not found, skipping';
                continue;
            }

            $log[] = '  ðŸ”„ Running audit for: ' . $page->post_title;

            // Get page content
            $page_url = get_permalink($page_id);
            $content = $page->post_content;
            $content = strip_shortcodes($content);
            $content = wp_strip_all_tags($content);

            // Fetch HTML structure
            $html_structure = '';
            $response = wp_remote_get($page_url, array(
                'timeout' => 10,
                'sslverify' => false,
            ));

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $html = wp_remote_retrieve_body($response);
                $html_structure = conversioniq_extract_html_structure($html);
            }

            // Supplement HTML-based trust signals with reviews stored in WordPress CPTs
            $cpt_reviews = conversioniq_get_cpt_reviews();
            if (!empty($cpt_reviews)) {
                $html_structure .= $cpt_reviews;
            }

            // Get business settings
            $business_settings = get_option('conversion_iq_settings', '{}');
            $business = json_decode($business_settings, true);

            // Prepare payload for AI analysis
            $payload = array(
                'business' => $business,
                'page' => array(
                    'title' => $page->post_title,
                    'content' => $content,
                    'url' => $page_url,
                    'word_count' => str_word_count($content),
                    'html_structure' => $html_structure,
                ),
            );

            // Run the AI analysis
            if (!class_exists('ConversionIQ_AI')) {
                require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
            }

            try {
                $ai_result = ConversionIQ_AI::analyze($payload);

                if (is_array($ai_result)) {
                    // Save audit to database
                    $inserted = $wpdb->insert(
                        $table,
                        array(
                        'page_id' => $page_id,
                        'page_title' => $page->post_title,
                        'page_url' => $page_url,
                        'data' => wp_json_encode($ai_result),
                        'ai_used' => true,
                        'created_at' => current_time('mysql')
                    ),
                        array('%d', '%s', '%s', '%s', '%d', '%s')
                    );

                    if ($inserted) {
                        $log[] = '    âœ… Audit completed and saved (ID: ' . $wpdb->insert_id . ')';

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
                                $log[] = '    â˜ï¸ Synced to Supabase cloud';
                            }
                        }
                        catch (Exception $e) {
                            $log[] = '    âš ï¸ Supabase sync skipped: ' . $e->getMessage();
                        }
                    }
                    else {
                        $log[] = '    âš ï¸ Audit completed but failed to save: ' . $wpdb->last_error;
                    }
                }
                else {
                    $log[] = '    âŒ Audit failed: Invalid response from AI';
                }
            }
            catch (Exception $e) {
                $log[] = '    âŒ Audit failed: ' . $e->getMessage();
            }
        }

        // Re-query for the newly created audits
        $log[] = 'ðŸ”„ Fetching newly created audits...';
        $audits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE page_id IN ($placeholders) 
             ORDER BY created_at DESC",
            ...$page_ids
        ), ARRAY_A);

        if (empty($audits)) {
            $log[] = 'âŒ Failed to create audits';
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to generate audits for the selected pages.',
                'log' => $log
            ), 500);
        }
    }

    $log[] = 'âœ… Found ' . count($audits) . ' audit record(s) in database';

    // Group audits by page_id and get the most recent one for each
    $latest_audits = array();
    $seen_pages = array();

    foreach ($audits as $audit) {
        $page_id = $audit['page_id'];
        if (!in_array($page_id, $seen_pages)) {
            $audit['data'] = json_decode($audit['data'], true);
            $latest_audits[] = $audit;
            $seen_pages[] = $page_id;
        }
    }

    $log[] = 'ðŸ“Š Processing ' . count($latest_audits) . ' unique page audit(s)';

    // Prepare results array in the format expected by the email function
    $results = array();
    foreach ($latest_audits as $audit) {
        $data = $audit['data'];
        $log[] = '  âœ“ ' . $audit['page_title'] . ' (ID: ' . $audit['id'] . ')';
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
    $log[] = 'ðŸ¢ Loading business context...';
    $business_settings = get_option('conversion_iq_settings', '{}');
    $business = json_decode($business_settings, true);
    $business_context = array(
        'industry' => $business['industry'] ?? '',
        'audience' => $business['audience'] ?? '',
        'goal' => $business['goal'] ?? ''
    );

    // Use the automated reports class to send the email
    $log[] = 'ðŸ“„ Generating PDF reports...';
    if (!class_exists('ConversionIQ_Automated_Reports')) {
        require_once CONVERSION_IQ_DIR . 'includes/class-automated-reports.php';
    }

    // Call the send_email_report method using reflection since it's private
    $log[] = 'ðŸ“§ Preparing email with attachments...';
    
    // Add error handler to capture wp_mail errors
    $mail_error = '';
    add_action('wp_mail_failed', function($wp_error) use (&$mail_error, &$log) {
        $mail_error = $wp_error->get_error_message();
        $log[] = 'âŒ Email error: ' . $mail_error;
        error_log('âŒ wp_mail error: ' . $mail_error);
    });
    
    $reflection = new ReflectionClass('ConversionIQ_Automated_Reports');
    $method = $reflection->getMethod('send_email_report');
    $method->setAccessible(true);

    $result = $method->invoke(null, $email, $results, $business_context);
    
    // Extract the success status and messages from the result
    $sent = isset($result['success']) ? $result['success'] : false;
    $email_messages = isset($result['messages']) ? $result['messages'] : array();
    
    // Merge the detailed messages into the main log
    foreach ($email_messages as $msg) {
        $log[] = $msg;
    }

    if ($sent) {
        $log[] = 'âœ… Email sent successfully via wp_mail()';
        $log[] = 'ðŸ“¬ Email queued for delivery to: ' . $email;
        $log[] = 'â„¹ï¸ If you don\'t receive the email, check:';
        $log[] = '  - Your spam/junk folder';
        $log[] = '  - WordPress email configuration';
        $log[] = '  - Server email sending limits';
        $log[] = '  - PDF attachment size (might be rejected by email server)';
        
        error_log('âœ… Manual audit report queued for delivery to: ' . $email . ' with ' . count($results) . ' page(s)');
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Audit report queued for delivery to ' . $email . ' with ' . count($results) . ' page(s). Check your inbox and spam folder.',
            'log' => $log
        ));
    }
    else {
        $log[] = 'âŒ wp_mail() returned false - email not sent';
        if (!empty($mail_error)) {
            $log[] = 'âŒ Error details: ' . $mail_error;
        }
        $log[] = 'ðŸ’¡ Troubleshooting steps:';
        $log[] = '  1. Test email delivery works (confirm this first)';
        $log[] = '  2. Check if PDFs are being generated';
        $log[] = '  3. Try sending to one page at a time';
        $log[] = '  4. Contact your hosting provider about email sending';
        
        error_log('âŒ Failed to send manual audit report to: ' . $email . ($mail_error ? ' - Error: ' . $mail_error : ''));
        
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to send audit report. ' . ($mail_error ? 'Error: ' . $mail_error : 'Check WordPress email configuration.'),
            'log' => $log
        ), 500);
    }
}

// ============================================================
// GOOGLE ANALYTICS API ENDPOINTS
// ============================================================

function conversioniq_ga_status(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_status());
}

function conversioniq_ga_save_credentials(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $client_id = $params['client_id'] ?? '';
    $client_secret = $params['client_secret'] ?? '';

    if (empty($client_id) || empty($client_secret)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Client ID and Client Secret are required'
        ), 400);
    }

    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->save_client_credentials($client_id, $client_secret));
}

function conversioniq_ga_auth_url(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    $url = $ga->get_auth_url();

    if (empty($url)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Please configure Google Analytics credentials first'
        ), 400);
    }

    return rest_ensure_response(array('success' => true, 'url' => $url));
}

function conversioniq_ga_properties(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_properties());
}

function conversioniq_ga_save_property(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $property_id = $params['property_id'] ?? '';
    $property_name = $params['property_name'] ?? '';

    if (empty($property_id)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Property ID is required'
        ), 400);
    }

    $ga = new ConversionIQ_Google_Analytics();
    $result = $ga->save_property($property_id);

    // Also save property name for display
    if ($result['success'] && $property_name) {
        $options = get_option('conversioniq_ga_credentials', array());
        $options['property_name'] = $property_name;
        update_option('conversioniq_ga_credentials', $options);
    }

    return rest_ensure_response($result);
}

function conversioniq_ga_disconnect(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->disconnect());
}

function conversioniq_ga_page_data(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $page_url = $params['url'] ?? '';
    $days = $params['days'] ?? 30;

    if (empty($page_url)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Page URL is required'
        ), 400);
    }

    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_page_conversions($page_url, $days));
}

function conversioniq_ga_top_pages(WP_REST_Request $request)
{
    $limit = $request->get_param('limit') ?? 10;
    $days = $request->get_param('days') ?? 30;

    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_top_pages($limit, $days));
}
