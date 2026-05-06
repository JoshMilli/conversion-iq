<?php
if (!defined('ABSPATH')) {
    exit;
}

class ConversionIQ_AI
{

    const ABACUS_API_URL = 'https://routellm.abacus.ai/v1/chat/completions';

    /**
     * Get API key from wp-config.php or wp_options (synced via license activation)
     */
    private static function get_api_key()
    {
        // Prefer API key from wp-config.php constant
        if (defined('CONVERSIONIQ_ABACUS_KEY')) {
            return CONVERSIONIQ_ABACUS_KEY;
        }
        // Then try wp_options (delivered via /api/get-config during license sync)
        $opt = get_option('conversioniq_api_key', '');
        if (!empty($opt)) {
            return $opt;
        }
        // No key available — audits will fail until a valid license is activated
        ciq_log('❌ ConversionIQ: No API key found (conversioniq_api_key is empty). Re-activate your license to provision a key.');
        return '';
    }

    /**
     * Analyze page content using Abacus.ai route-llm
     */
    public static function analyze($payload)
    {
        $page_title = isset($payload['page']['title']) ? $payload['page']['title'] : 'Unknown Page';
        $page_content = isset($payload['page']['content']) ? $payload['page']['content'] : '';
        $page_url = isset($payload['page']['url']) ? $payload['page']['url'] : '';
        $word_count = isset($payload['page']['word_count']) ? $payload['page']['word_count'] : 0;
        $html_structure = isset($payload['page']['html_structure']) ? $payload['page']['html_structure'] : '';
        $business = isset($payload['business']) ? $payload['business'] : array();
        $screenshot_url = isset($payload['page']['screenshot_url']) ? $payload['page']['screenshot_url'] : null;

        // Check if content is too long and needs chunking
        if (strlen($page_content) > 8000) {
            ciq_log('📚 Long content detected (' . strlen($page_content) . ' chars), using chunked analysis');
            return self::analyze_chunked($payload);
        }

        // Build the AI prompts (system = rubric/persona, user = page content)
        $system_prompt = self::build_system_prompt();
        $user_prompt = self::build_user_prompt($page_title, $page_content, $page_url, $word_count, $html_structure, $business);

        // Call Abacus.ai API
        $start_time = microtime(true);
        $ai_response = self::call_abacus_ai($user_prompt, $system_prompt, $screenshot_url);
        $elapsed = round((microtime(true) - $start_time), 2);

        $debug_info = array(
            'elapsed_time' => $elapsed . 's',
            'is_array' => is_array($ai_response),
            'has_success_key' => isset($ai_response['success']),
            'success_value' => isset($ai_response['success']) ? ($ai_response['success'] ? 'TRUE' : 'FALSE') : 'MISSING',
            'has_data_key' => isset($ai_response['data']),
            'has_error_key' => isset($ai_response['error']),
            'error_value' => isset($ai_response['error']) ? $ai_response['error'] : 'none',
        );
        ciq_log('🔍 AI Response Debug: ' . json_encode($debug_info));
        ciq_log('⏱️ AI call took: ' . $elapsed . ' seconds');

        if ($ai_response && isset($ai_response['success']) && $ai_response['success']) {
            ciq_log('✅ AI analysis successful, returning data');
            $result = $ai_response['data'];
            
            // Attach raw webhook stats to the audit data so reports can show real numbers
            $webhook_stats = self::get_webhook_statistics($page_url);
            if ($webhook_stats) {
                $result['webhook_stats'] = $webhook_stats;
                ciq_log('📊 Attached webhook_stats to audit: ' . $webhook_stats['total_interactions'] . ' interactions');
            }
            
            return $result;
        }

        // Log the failure — do NOT return fallback/mock data
        $error_reason = isset($ai_response['error']) ? $ai_response['error'] : 'Unknown error - response structure invalid';
        ciq_log('❌ AI analysis failed — audit will not be saved. Reason: ' . $error_reason);
        ciq_log('📋 Full response: ' . json_encode($ai_response));

        return null;
    }

    /**
     * Analyze long pages by splitting into sections
     */
    private static function analyze_chunked($payload)
    {
        $page_title = isset($payload['page']['title']) ? $payload['page']['title'] : 'Unknown Page';
        $content = isset($payload['page']['content']) ? $payload['page']['content'] : '';
        // Screenshot is only used on the first chunk — it gives overall visual context
        // for the hero/above-the-fold area, which is where visual scoring matters most.
        $screenshot_url = isset($payload['page']['screenshot_url']) ? $payload['page']['screenshot_url'] : null;

        ciq_log('🔍 Starting chunked analysis for: ' . $page_title);

        $sections = self::split_into_sections($content);

        if (empty($sections)) {
            ciq_log('⚠️ Failed to split content into sections, falling back to truncated analysis');
            $payload['page']['content'] = substr($content, 0, 8000);
            return self::analyze($payload);
        }

        $all_scores = array();
        $all_suggestions = array();
        $all_functionality_suggestions = array();

        $section_count = count($sections);
        $current = 0;

        foreach ($sections as $section_name => $section_content) {
            $current++;
            ciq_log("📄 Analyzing section {$current}/{$section_count}: {$section_name} (" . strlen($section_content) . " chars)");

            // Compress content if still too long
            $compressed = self::compress_content($section_content);

            // Update payload with section content
            $section_payload = $payload;
            $section_payload['page']['content'] = $compressed;
            $section_payload['page']['word_count'] = str_word_count($compressed);

            $system_prompt = self::build_system_prompt($section_name);
            $user_prompt = self::build_user_prompt(
                $payload['page']['title'],
                $compressed,
                isset($payload['page']['url']) ? $payload['page']['url'] : '',
                str_word_count($compressed),
                isset($payload['page']['html_structure']) ? $payload['page']['html_structure'] : '',
                isset($payload['business']) ? $payload['business'] : array()
            );

            // Pass screenshot only on the first chunk (hero section / above-the-fold context)
            $chunk_screenshot = ( $current === 1 ) ? $screenshot_url : null;
            $response = self::call_abacus_ai($user_prompt, $system_prompt, $chunk_screenshot);

            if ($response && isset($response['success']) && $response['success']) {
                $data = $response['data'];
                $all_scores[] = $data;

                // Collect suggestions with section context
                if (isset($data['suggestions']) && is_array($data['suggestions'])) {
                    foreach ($data['suggestions'] as $suggestion) {
                        if (is_array($suggestion)) {
                            $suggestion['analyzed_section'] = $section_name;
                            $all_suggestions[] = $suggestion;
                        }
                    }
                }

                // Collect functionality suggestions (only from first section to avoid duplicates)
                if ($current === 1 && isset($data['functionality_suggestions']) && is_array($data['functionality_suggestions'])) {
                    $all_functionality_suggestions = $data['functionality_suggestions'];
                }

                ciq_log("✅ Section '{$section_name}' analyzed successfully");
            }
            else {
                ciq_log("⚠️ Section '{$section_name}' analysis failed");
            }

            // Small delay to avoid rate limiting
            if ($current < $section_count) {
                sleep(1);
            }
        }

        // Aggregate results
        return self::aggregate_section_results($all_scores, $all_suggestions, $all_functionality_suggestions, $payload);
    }

    /**
     * Split content into logical sections
     */
    private static function split_into_sections($content)
    {
        $sections = array();

        // Strategy 1: Split by HTML section tags
        if (preg_match_all('/<section[^>]*>(.*?)<\/section>/is', $content, $matches)) {
            foreach ($matches[0] as $i => $section_html) {
                $section_name = "Section " . ($i + 1);
                // Try to get section ID or class for better naming
                if (preg_match('/id=["\']([^"\'\']+)["\']/', $section_html, $id_match)) {
                    $section_name = ucfirst(str_replace(array('-', '_'), ' ', $id_match[1]));
                }
                elseif (preg_match('/class=["\']([^"\'\']+)["\']/', $section_html, $class_match)) {
                    $classes = explode(' ', $class_match[1]);
                    $section_name = ucfirst(str_replace(array('-', '_'), ' ', $classes[0]));
                }
                $sections[$section_name] = wp_strip_all_tags($matches[1][$i]);
            }
        }

        // Strategy 2: If no sections, split by headers (H1-H3)
        if (empty($sections)) {
            $parts = preg_split('/(<h[1-3][^>]*>.*?<\/h[1-3]>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            $current_section = 'Introduction';
            $current_content = '';

            foreach ($parts as $part) {
                if (preg_match('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/i', $part, $header)) {
                    if (!empty(trim($current_content))) {
                        $sections[$current_section] = trim(wp_strip_all_tags($current_content));
                    }
                    $current_section = wp_strip_all_tags($header[1]);
                    $current_content = '';
                }
                else {
                    $current_content .= $part;
                }
            }

            if (!empty(trim($current_content))) {
                $sections[$current_section] = trim(wp_strip_all_tags($current_content));
            }
        }

        // Strategy 3: Fallback - split by character count into even chunks
        if (empty($sections)) {
            $chunk_size = 6000;
            $chunks = str_split($content, $chunk_size);
            foreach ($chunks as $i => $chunk) {
                $sections["Part " . ($i + 1)] = wp_strip_all_tags($chunk);
            }
        }

        // Remove empty or very short sections (less than 100 chars)
        $sections = array_filter($sections, function ($content) {
            return strlen(trim($content)) > 100;
        });

        ciq_log('📑 Split content into ' . count($sections) . ' sections: ' . implode(', ', array_keys($sections)));

        return $sections;
    }

    /**
     * Intelligently compress content while preserving key conversion elements
     */
    private static function compress_content($content)
    {
        if (strlen($content) <= 7000) {
            return $content;
        }

        ciq_log('🗜️ Compressing content from ' . strlen($content) . ' chars');

        $key_elements = array();

        // 1. Extract Headlines (H1-H3)
        if (preg_match_all('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $content, $headers)) {
            $key_elements['headers'] = implode("\n", array_slice($headers[0], 0, 5));
        }

        // 2. Extract CTAs (buttons, links with CTA classes)
        if (preg_match_all('/<(?:button|a)[^>]*class=["\'][^"\'\']*(?:cta|button|btn)[^"\'\']*["\'][^>]*>(.*?)<\/(?:button|a)>/is', $content, $ctas)) {
            $key_elements['ctas'] = implode("\n", array_slice($ctas[0], 0, 5));
        }

        // 3. Extract first few paragraphs
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $paragraphs, PREG_SET_ORDER)) {
            $first_paras = array_slice(array_map(function ($p) {
                return $p[0]; }, $paragraphs), 0, 4);
            $key_elements['key_paragraphs'] = implode("\n", $first_paras);
        }

        // 4. Extract lists (features, benefits)
        if (preg_match_all('/<(?:ul|ol)[^>]*>(.*?)<\/(?:ul|ol)>/is', $content, $lists)) {
            $key_elements['lists'] = implode("\n", array_slice($lists[0], 0, 2));
        }

        // 5. Extract any pricing or value-related content
        if (preg_match_all('/<[^>]*class=["\'][^"\'\']*(?:price|pricing|value|cost)[^"\'\']*["\'][^>]*>.*?<\/[^>]+>/is', $content, $pricing)) {
            $key_elements['pricing'] = implode("\n", array_slice($pricing[0], 0, 3));
        }

        $compressed = "[CONTENT COMPRESSED - Key Elements Extracted]\n\n" . implode("\n\n", array_filter($key_elements));

        // If still too long, truncate
        if (strlen($compressed) > 7000) {
            $compressed = substr($compressed, 0, 7000) . '... [truncated]';
        }

        ciq_log('🗜️ Compressed to ' . strlen($compressed) . ' chars');

        return $compressed;
    }

    /**
     * Aggregate results from multiple section analyses
     */
    private static function aggregate_section_results($all_scores, $all_suggestions, $all_functionality_suggestions, $original_payload)
    {
        if (empty($all_scores)) {
            ciq_log('❌ No sections could be analyzed — AI analysis failed for all sections. Audit will not be saved.');
            return null;
        }

        ciq_log('🔢 Aggregating results from ' . count($all_scores) . ' sections');

        // Average all scores
        $averaged = array(
            'clarity_score' => 0,
            'emotional_score' => 0,
            'cta_strength' => 0,
            'readability_score' => 0,
            'engagement_score' => 0,
            'trust_score' => 0,
        );

        $count = count($all_scores);
        foreach ($all_scores as $scores) {
            foreach ($averaged as $key => $value) {
                if (isset($scores[$key])) {
                    $averaged[$key] += intval($scores[$key]);
                }
            }
        }

        foreach ($averaged as $key => $value) {
            $averaged[$key] = round($value / $count);
        }

        ciq_log('✅ Averaged scores calculated: clarity=' . $averaged['clarity_score'] . ', engagement=' . $averaged['engagement_score']);

        // Combine suggestions (limit to top 15 most impactful)
        $limited_suggestions = array_slice($all_suggestions, 0, 15);
        ciq_log('📝 Combined ' . count($all_suggestions) . ' suggestions, limited to ' . count($limited_suggestions));

        // Use first section's rewrites and insights (or merge them)
        $first_section = $all_scores[0];

        $result = array_merge($averaged, array(
            'suggestions' => $limited_suggestions,
            'functionality_suggestions' => $all_functionality_suggestions,
            'rewrites' => isset($first_section['rewrites']) ? $first_section['rewrites'] : array(),
            'insights' => isset($first_section['insights']) ? $first_section['insights'] : array(),
            'recommendations' => isset($first_section['recommendations']) ? $first_section['recommendations'] : array(),
            'cro_checklist' => isset($first_section['cro_checklist']) ? $first_section['cro_checklist'] : null,
            'ai_used' => true,
            'analysis_method' => 'chunked',
            'sections_analyzed' => count($all_scores),
            'overall_score' => (int) round(
                $averaged['clarity_score'] * 0.20 +
                $averaged['emotional_score'] * 0.15 +
                $averaged['cta_strength'] * 0.20 +
                $averaged['readability_score'] * 0.15 +
                $averaged['engagement_score'] * 0.15 +
                $averaged['trust_score'] * 0.15
            ),
        ));

        ciq_log('✅ Aggregation complete - returning chunked analysis results');

        return $result;
    }

    /**
     * Get aggregated webhook statistics for AI analysis
     * @param string $page_url The URL of the page being analyzed
     * @return array|null Aggregated webhook statistics or null if no data
     */
    public static function get_webhook_statistics($page_url) {
        global $wpdb;
        
        $leads_table = $wpdb->prefix . 'conversioniq_leads';
        $visitors_table = $wpdb->prefix . 'conversioniq_visitor_sessions';
        
        // Normalize homepage URL for consistent matching
        $parsed_url = parse_url($page_url);
        $site_url = get_site_url();
        $parsed_site = parse_url($site_url);
        
        // Homepage variations to check
        $base_host = $parsed_site['host'];
        $scheme = $parsed_site['scheme'];
        
        $homepage_variations = array(
            $site_url,
            trailingslashit($site_url),
            rtrim($site_url, '/'),
            $scheme . '://' . $base_host,
            $scheme . '://' . $base_host . '/',
            $scheme . '://www.' . $base_host,
            $scheme . '://www.' . $base_host . '/',
        );
        
        // Remove www if present and add non-www variation
        if (strpos($base_host, 'www.') === 0) {
            $non_www = substr($base_host, 4);
            $homepage_variations[] = $scheme . '://' . $non_www;
            $homepage_variations[] = $scheme . '://' . $non_www . '/';
        }
        
        // Remove duplicates
        $homepage_variations = array_unique($homepage_variations);
        $homepage_variations = array_values($homepage_variations);
        
        // Check if this is the homepage
        $is_homepage = in_array($page_url, $homepage_variations) || 
                       $page_url === $site_url || 
                       $page_url === trailingslashit($site_url);
        
        ciq_log('🔍 Webhook Stats Query - Page URL: ' . $page_url);
        ciq_log('🔍 Is Homepage: ' . ($is_homepage ? 'YES' : 'NO'));
        ciq_log('🔍 Homepage variations: ' . implode(', ', $homepage_variations));
        
        // Build SQL condition for homepage matching
        if ($is_homepage) {
            $homepage_placeholders = implode(',', array_fill(0, count($homepage_variations), '%s'));
            $where_clause_leads = "initial_page_visit IN ($homepage_placeholders)";
            $where_clause_visitors = "page_url IN ($homepage_placeholders)";
            $sql_params = $homepage_variations;
        } else {
            // Normalize the URL for flexible matching: strip protocol, www, trailing slash
            $normalized_url = preg_replace('#^https?://(www\.)?#', '', rtrim($page_url, '/'));
            // Use LIKE with the path portion for more reliable matching
            $parsed_page = parse_url($page_url);
            $page_path = isset($parsed_page['path']) ? rtrim($parsed_page['path'], '/') : '';
            
            if (!empty($page_path) && $page_path !== '' && $page_path !== '/') {
                // Match any URL ending with this path (handles http/https, www/non-www, trailing slash)
                $where_clause_leads = "initial_page_visit LIKE %s";
                $where_clause_visitors = "page_url LIKE %s";
                $sql_params = array('%' . $wpdb->esc_like($page_path) . '%');
            } else {
                // Fallback to exact match
                $where_clause_leads = "initial_page_visit = %s";
                $where_clause_visitors = "page_url = %s";
                $sql_params = array($page_url);
            }
        }
        
        // Get leads that started on this page
        $leads_query = "SELECT email, first_name, last_name, page_title, initial_page_visit, city, state, country, company_name, company_industry, job_title, created_at 
             FROM $leads_table 
             WHERE $where_clause_leads 
             ORDER BY created_at DESC 
             LIMIT 50";
        ciq_log('🔍 Leads SQL: ' . $leads_query);
        $leads = $wpdb->get_results($wpdb->prepare($leads_query, ...$sql_params), ARRAY_A);
        
        // Get visitors engaged on this page
        $visitors_query = "SELECT email, first_name, last_name, page_url, city, state, country, company_name, company_industry, job_title, created_at 
             FROM $visitors_table 
             WHERE $where_clause_visitors 
             ORDER BY created_at DESC 
             LIMIT 50";
        ciq_log('🔍 Visitors SQL: ' . $visitors_query);
        $visitors = $wpdb->get_results($wpdb->prepare($visitors_query, ...$sql_params), ARRAY_A);
        
        ciq_log('🔍 Found ' . count($leads) . ' leads, ' . count($visitors) . ' visitors');
        
        // Get total site stats for context
        $total_site_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table");
        $total_site_visitors = $wpdb->get_var("SELECT COUNT(*) FROM $visitors_table");
        
        // Store page-specific counts BEFORE any fallback
        $page_specific_visitors = count($visitors);
        $used_fallback = false;
        
        // If no page-specific data, fallback to site-wide for contextual sections (domains, recent visitors)
        if (empty($leads) && empty($visitors)) {
            ciq_log('🔍 No page-specific webhook data found - trying site-wide fallback');
            ciq_log('🔍 Leads table: ' . $leads_table);
            ciq_log('🔍 Visitors table: ' . $visitors_table);
            ciq_log('🔍 Last DB error: ' . $wpdb->last_error);
            
            // Check total table counts first
            $total_all_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table");
            $total_all_visitors = $wpdb->get_var("SELECT COUNT(*) FROM $visitors_table");
            ciq_log('🔍 Total records site-wide - leads: ' . $total_all_leads . ', visitors: ' . $total_all_visitors);
            
            // Fallback: get ALL site visitor data (useful for domains, recent visitors context)
            $leads = $wpdb->get_results(
                "SELECT email, first_name, last_name, page_title, initial_page_visit, city, state, country, company_name, company_industry, job_title, created_at 
                 FROM $leads_table ORDER BY created_at DESC LIMIT 50",
                ARRAY_A
            );
            $visitors = $wpdb->get_results(
                "SELECT email, first_name, last_name, page_url, city, state, country, company_name, company_industry, job_title, created_at 
                 FROM $visitors_table ORDER BY created_at DESC LIMIT 50",
                ARRAY_A
            );
            
            ciq_log('🔍 Site-wide fallback query - leads found: ' . count($leads) . ', visitors found: ' . count($visitors));
            if (!empty($visitors)) {
                $sample_urls = array_unique(array_column($visitors, 'page_url'));
                ciq_log('🔍 Sample page_url values in DB: ' . json_encode(array_slice($sample_urls, 0, 5)));
            }
            
            if (empty($leads) && empty($visitors)) {
                ciq_log('❌ No webhook data found site-wide - tables may be empty or missing');
                return null;
            }
            
            $used_fallback = true;
            // Page-specific count stays 0 since we couldn't match this page
            $page_specific_visitors = 0;
            
            ciq_log('✅ Site-wide fallback: using ' . count($leads) . ' leads, ' . count($visitors) . ' visitors');
        }
        
        // Aggregate statistics
        $total_leads = count($leads);
        $total_visitors = count($visitors);
        $total_interactions = $total_leads + $total_visitors;
        
        // Company analysis from stored company_name field
        $companies = array();
        foreach (array_merge($leads, $visitors) as $item) {
            if (!empty($item['company_name'])) {
                $companies[] = $item['company_name'];
            }
        }
        $company_counts = array_count_values($companies);
        arsort($company_counts);
        $top_companies = array_slice($company_counts, 0, 10, true);
        
        // Domain analysis
        $domains = array();
        foreach (array_merge($leads, $visitors) as $item) {
            if (!empty($item['email']) && strpos($item['email'], '@') !== false) {
                $domain = substr(strrchr($item['email'], "@"), 1);
                $domains[] = $domain;
            }
        }
        $domain_counts = array_count_values($domains);
        arsort($domain_counts);
        $top_domains = array_slice($domain_counts, 0, 10, true);
        
        // Industry analysis from stored company_industry field
        $industries = array();
        foreach (array_merge($leads, $visitors) as $item) {
            if (!empty($item['company_industry'])) {
                $industries[] = $item['company_industry'];
            }
        }
        $industry_counts = array_count_values($industries);
        arsort($industry_counts);
        $top_industries = array_slice($industry_counts, 0, 10, true);
        
        // Job title analysis from stored job_title field
        $job_titles = array();
        foreach (array_merge($leads, $visitors) as $item) {
            if (!empty($item['job_title'])) {
                $job_titles[] = $item['job_title'];
            }
        }
        $job_title_counts = array_count_values($job_titles);
        arsort($job_title_counts);
        $top_job_titles = array_slice($job_title_counts, 0, 10, true);

        // Decision-maker tier classification from job_title field
        $all_people = array_merge($leads, $visitors);
        $tiers = array('Executive' => 0, 'Director/VP' => 0, 'Manager' => 0, 'Individual' => 0);
        foreach ($all_people as $person) {
            $title = $person['job_title'] ?? '';
            if (empty($title)) continue;
            if (preg_match('/\b(ceo|founder|owner|president|coo|cto|cfo|ciso|cmo|cpo|chief)\b/i', $title)) {
                $tiers['Executive']++;
            } elseif (preg_match('/\b(director|vp|vice president|head of|svp|evp)\b/i', $title)) {
                $tiers['Director/VP']++;
            } elseif (preg_match('/\b(manager|lead|senior|supervisor|team lead)\b/i', $title)) {
                $tiers['Manager']++;
            } else {
                $tiers['Individual']++;
            }
        }
        $decision_maker_tiers = array_filter($tiers); // Remove zero-count tiers

        // Geographic distribution from city/country fields
        $locations = array();
        foreach ($all_people as $person) {
            $city    = $person['city'] ?? '';
            $country = $person['country'] ?? '';
            if (!empty($city) && !empty($country)) {
                $locations[] = $city . ', ' . $country;
            } elseif (!empty($city)) {
                $locations[] = $city;
            } elseif (!empty($country)) {
                $locations[] = $country;
            }
        }
        $location_counts = array_count_values($locations);
        arsort($location_counts);
        $top_locations = array_slice($location_counts, 0, 10, true);

        // Company intelligence: group all people by company with named contacts
        $company_intel_raw = array();
        foreach ($all_people as $person) {
            $company = $person['company_name'] ?? '';
            if (empty($company)) continue;
            if (!isset($company_intel_raw[$company])) {
                $company_intel_raw[$company] = array(
                    'company'  => $company,
                    'industry' => $person['company_industry'] ?? '',
                    'count'    => 0,
                    'contacts' => array(),
                    'last_seen'=> $person['created_at'] ?? '',
                );
            }
            $entry = &$company_intel_raw[$company];
            $entry['count']++;
            if (!empty($person['created_at']) && ($person['created_at'] ?? '') > $entry['last_seen']) {
                $entry['last_seen'] = $person['created_at'];
            }
            if (empty($entry['industry']) && !empty($person['company_industry'])) {
                $entry['industry'] = $person['company_industry'];
            }
            // Add unique named contacts (deduplicated by email, max 3 per company)
            $email = $person['email'] ?? '';
            $already_added = false;
            foreach ($entry['contacts'] as $c) {
                if (!empty($email) && ($c['email'] ?? '') === $email) { $already_added = true; break; }
            }
            $name_parts = array_filter(array($person['first_name'] ?? '', $person['last_name'] ?? ''));
            $name = implode(' ', $name_parts);
            if (!$already_added && count($entry['contacts']) < 3 && (!empty($name) || !empty($person['job_title'] ?? ''))) {
                $entry['contacts'][] = array(
                    'name'    => $name,
                    'title'   => $person['job_title'] ?? '',
                    'email'   => $email,
                    'city'    => $person['city'] ?? '',
                    'country' => $person['country'] ?? '',
                );
            }
            unset($entry);
        }
        uasort($company_intel_raw, function($a, $b) { return $b['count'] - $a['count']; });
        $company_intelligence = array_slice($company_intel_raw, 0, 8, true);

        // Time-based analysis
        $weekday_counts = array();
        $hour_counts = array();
        foreach (array_merge($leads, $visitors) as $item) {
            if (!empty($item['created_at'])) {
                $timestamp = strtotime($item['created_at']);
                $weekday = date('l', $timestamp);
                $hour = date('G', $timestamp);
                
                if (!isset($weekday_counts[$weekday])) $weekday_counts[$weekday] = 0;
                $weekday_counts[$weekday]++;
                
                if (!isset($hour_counts[$hour])) $hour_counts[$hour] = 0;
                $hour_counts[$hour]++;
            }
        }
        arsort($weekday_counts);
        arsort($hour_counts);
        
        // Calculate contribution percentages (page-specific visitors vs total site visitors)
        $total_site_all = (int)$total_site_leads + (int)$total_site_visitors;
        $site_contribution_pct = ($total_site_all > 0) 
            ? round(($page_specific_visitors / $total_site_all) * 100, 1) 
            : 0;
        
        ciq_log('✅ Webhook stats compiled: ' . $total_interactions . ' interactions, page visitors: ' . $page_specific_visitors . ', ' . $site_contribution_pct . '% contribution');
        
        return array(
            'total_leads' => $total_leads,
            'total_visitors' => $total_visitors,
            'total_interactions' => $total_interactions,
            'page_specific_visitors' => $page_specific_visitors,
            'total_site_leads' => (int)$total_site_leads,
            'total_site_visitors' => (int)$total_site_visitors,
            'site_contribution_pct' => $site_contribution_pct,
            'used_fallback' => $used_fallback,
            'top_companies' => $top_companies,
            'top_domains' => $top_domains,
            'top_industries' => $top_industries,
            'top_job_titles' => $top_job_titles,
            'decision_maker_tiers' => $decision_maker_tiers,
            'top_locations' => $top_locations,
            'company_intelligence' => $company_intelligence,
            'peak_weekday' => !empty($weekday_counts) ? array_key_first($weekday_counts) : 'Unknown',
            'peak_hour' => !empty($hour_counts) ? array_key_first($hour_counts) : 'Unknown',
            'sample_leads' => array_slice($leads, 0, 5),
            'sample_visitors' => array_slice($visitors, 0, 5),
        );
    }

    /**
     * Detect page type and return appropriate conversion context
     */
    private static function detect_page_type($title, $url)
    {
        $title_lower = strtolower($title);
        $url_lower = strtolower($url);

        // Homepage detection
        if (preg_match('/^home$/i', $title) ||
        preg_match('/\/\s*$/', $url) ||
        strpos($url_lower, 'homepage') !== false) {
            return array(
                'type' => 'Homepage',
                'context' => 'The homepage is the first impression and gateway to your business. It should quickly communicate value, build trust, and guide visitors to take the next step in their journey.',
                'conversion_goal' => 'Capture attention, communicate value proposition clearly, and guide visitors to explore key pages or take primary action (contact, sign up, learn more)'
            );
        }

        // About/Company page
        if (preg_match('/about|who we are|our story|our team|our company|meet the team/i', $title_lower) ||
        preg_match('/about|our-story|our-team|company/i', $url_lower)) {
            return array(
                'type' => 'About Page',
                'context' => 'The About page builds trust and credibility by humanizing your business. Visitors here are evaluating whether to work with you.',
                'conversion_goal' => 'Build trust and emotional connection, showcase expertise and values, guide visitors to contact or service pages'
            );
        }

        // Services/Product pages
        if (preg_match('/services|what we do|our services|products|offerings/i', $title_lower) ||
        preg_match('/services|products|offerings/i', $url_lower)) {
            return array(
                'type' => 'Services/Products Page',
                'context' => 'Service pages are high-intent pages where visitors evaluate your specific offerings. They need clear information and strong CTAs.',
                'conversion_goal' => 'Clearly explain offerings, demonstrate value and benefits, address objections, drive direct conversion (inquiry, booking, purchase)'
            );
        }

        // Contact page
        if (preg_match('/contact|get in touch|reach us|book|schedule/i', $title_lower) ||
        preg_match('/contact|booking|schedule/i', $url_lower)) {
            return array(
                'type' => 'Contact/Booking Page',
                'context' => 'This is a high-intent page where visitors are ready to take action. Remove friction and make it easy to connect.',
                'conversion_goal' => 'Minimize friction, provide multiple contact options, reassure visitors, make it extremely easy to take action'
            );
        }

        // FAQ page
        if (preg_match('/faq|frequently asked|questions|help center/i', $title_lower) ||
        preg_match('/faq|questions|help/i', $url_lower)) {
            return array(
                'type' => 'FAQ Page',
                'context' => 'FAQ pages remove objections and answer concerns that prevent conversion. They support the buying decision.',
                'conversion_goal' => 'Address common objections clearly, reduce uncertainty, build confidence, include CTAs to move visitors to conversion'
            );
        }

        // Pricing page
        if (preg_match('/pricing|plans|packages|cost|rates/i', $title_lower) ||
        preg_match('/pricing|plans|packages/i', $url_lower)) {
            return array(
                'type' => 'Pricing Page',
                'context' => 'Pricing pages are critical conversion points. Visitors need clear value justification and easy next steps.',
                'conversion_goal' => 'Present pricing clearly, justify value, compare options effectively, drive purchase or inquiry with strong CTAs'
            );
        }

        // Blog/Article page
        if (preg_match('/blog|article|post|news|guide/i', $title_lower) ||
        preg_match('/blog|article|post|news/i', $url_lower)) {
            return array(
                'type' => 'Blog/Content Page',
                'context' => 'Content pages attract and educate visitors. They should build authority and guide readers to service pages.',
                'conversion_goal' => 'Provide valuable information, establish expertise, include relevant CTAs to services/contact, capture emails for nurturing'
            );
        }

        // Testimonials/Reviews page
        if (preg_match('/testimonial|reviews|success stories|case studies|clients/i', $title_lower) ||
        preg_match('/testimonial|reviews|case-studies/i', $url_lower)) {
            return array(
                'type' => 'Testimonials/Social Proof Page',
                'context' => 'Social proof pages validate your claims and build trust. They overcome skepticism.',
                'conversion_goal' => 'Showcase credible testimonials and results, build trust through social proof, guide visitors to take action'
            );
        }

        // Gallery/Portfolio page
        if (preg_match('/gallery|portfolio|our work|projects/i', $title_lower) ||
        preg_match('/gallery|portfolio|projects/i', $url_lower)) {
            return array(
                'type' => 'Gallery/Portfolio Page',
                'context' => 'Visual showcases demonstrate quality and capability. They should inspire confidence.',
                'conversion_goal' => 'Showcase quality of work, demonstrate capabilities, provide context for projects, guide to inquiry or booking'
            );
        }

        // Default for unidentified pages
        return array(
            'type' => 'Standard Page',
            'context' => 'This page supports the overall customer journey and should align with its specific purpose in the conversion funnel.',
            'conversion_goal' => 'Guide visitors toward the primary business goal while serving the specific purpose of this page'
        );
    }

    /**
     * Build the system prompt — scoring rubric, persona, calibration examples, output format.
     * This is constant across all audits so the model treats it as immutable rules.
     */
    private static function build_system_prompt($section_name = null)
    {
        $section_context = '';
        if ($section_name) {
            $section_context = "\nYou are analyzing one SECTION ('{$section_name}') of a larger page. Focus on this section's contribution to conversion.\n";
        }

        return "You are an expert conversion rate optimization (CRO) analyst. You produce consistent, calibrated scores based on a fixed rubric. You never guess or estimate — you score only what is present on the page.{$section_context}

LANGUAGE RULE: Regardless of the page content language, ALL output must be in English.

─── SCORING RUBRIC (0-100, integer only) ───

clarity_score (How clearly does the page communicate its value proposition?)
  0-30: No clear value proposition, visitor cannot tell what is offered within 5 seconds
  31-50: Vague value proposition, generic language like \"quality solutions\"
  51-65: Value prop exists but lacks specificity — missing who/what/why
  66-80: Clear value prop with specific audience, offering, and benefit
  81-100: Exceptional — benefit-driven headline, immediate understanding, differentiation obvious

emotional_score (Does the copy connect emotionally with the target audience?)
  0-30: Pure feature list, no benefit language, no pain points addressed
  31-50: Some benefits mentioned but generic (\"save time\", \"grow your business\")
  51-65: Pain points acknowledged, some storytelling or empathy
  66-80: Strong emotional hooks, clear before/after transformation
  81-100: Deep empathy, aspirational language, authentic stories, reader feels understood

cta_strength (How compelling are the calls-to-action?)
  0-30: No CTA, or only generic text like \"Submit\" / \"Click Here\"
  31-50: Basic CTAs like \"Get Started\" / \"Learn More\" without urgency or benefit
  51-65: Action-oriented CTAs with some benefit (\"Get Your Free Quote\")
  66-80: Strong CTAs — action verb + benefit + visual prominence
  81-100: Multiple strategic CTAs with urgency, benefit, high contrast, above and below fold

readability_score (Is the page easy to scan and read?)
  0-30: Wall of text, no subheadings, paragraphs 100+ words
  31-50: Some structure but dense paragraphs (60-100 words), inconsistent hierarchy
  51-65: Reasonable structure with subheadings, 40-60 word paragraphs
  66-80: Clear visual hierarchy, short paragraphs, bullet points, scannable
  81-100: Excellent typography, whitespace, 20-40 word blocks, F-pattern optimized

engagement_score (Does the page encourage interaction and keep visitors engaged?)
  0-30: Static text only, no interactive elements, basic contact form at most
  31-50: Images present, one form or basic interactivity
  51-65: Multiple media types, embedded video or interactive elements
  66-80: Rich interactive content, calculators, quizzes, animations, multiple CTAs
  81-100: Personalization, dynamic content, gamification, deeply interactive experience

trust_score (Does the page establish credibility and reduce risk?)
  0-30: No social proof, no trust badges, no testimonials
  31-50: Anonymous testimonials (\"A satisfied customer\") OR basic trust badges, not both
  51-65: Named testimonials (First Last) without photos, OR logos, OR case study mentions
  66-80: Named testimonials with some detail + trust badges + client logos
  81-100: Full testimonials (name+photo+company+result) + badges + security seals + case studies

TRUST CALIBRATION: If you find full person names (First Last) in testimonials, the minimum trust score is 60. Anonymous roles only = 40-60 cap.

─── CALIBRATION EXAMPLES ───

Example A — SaaS landing page with \"Streamline Your Workflow\" headline, bullet-point features, one \"Start Free Trial\" button, stock photo, no testimonials:
  clarity: 58, emotional: 35, cta: 52, readability: 70, engagement: 40, trust: 20

Example B — Law firm homepage with named partner testimonials (3 with full names), \"Protecting Your Rights Since 1985\" headline, consultation booking form, team photos, BBB badge:
  clarity: 72, emotional: 65, cta: 68, readability: 62, engagement: 55, trust: 78

Example C — E-commerce product page with benefit-driven headline, before/after photos, video demo, 47 reviews with names/photos, urgency timer, \"Add to Cart - Free Shipping\" CTA, trust badges:
  clarity: 85, emotional: 78, cta: 88, readability: 75, engagement: 82, trust: 90

Use these as anchors. A page similar to Example A should score similarly. Do not inflate or deflate relative to these benchmarks.

─── CONSISTENCY RULES ───

1. Score ONLY what is present on the page. Do not give credit for what \"could be\" there.
2. Scores must be internally consistent — if you mention \"no testimonials\" in insights, trust_score cannot be above 50.
3. If page content is unchanged between audits, scores should vary by no more than ±3 points.
4. The overall_score is the weighted average: clarity(20%) + emotional(15%) + cta(20%) + readability(15%) + engagement(15%) + trust(15%).
5. Always compute overall_score yourself using the weights above. Never estimate it.
6. For benchmark_research, use the business INDUSTRY and this page's actual scores to produce specific, grounded competitive intelligence:
   - industry_average: realistic integer (62–72) for typical websites in this exact industry — vary by sector maturity (e.g., SaaS/fintech score higher ~70, trades/local services lower ~64)
   - top_performers_threshold: integer (85–93) for the top 10% in this industry
   - competitive_context: EXACTLY 3 sentences, each grounded in THIS industry and THIS page's scores. Sentence 1: name 2-3 specific tactics that top-converting [industry] websites actually use (e.g., for legal: named case outcomes and bar certifications; for SaaS: live demo links and logo bars; for e-commerce: star ratings inline with product images). Sentence 2: reference the page's actual weakest score by name and number, and explain what it signals competitively in this sector. Sentence 3: state the single most impactful change the business can make to close the gap to top performers, based on the industry pattern. NEVER use phrases like \"top-performing websites prioritize\" or \"crystal-clear value communication\" — those are generic filler. Every sentence must be specific to the industry.
   - key_competitive_factors: EXACTLY 3 bullet points, each naming a specific conversion element that separates winners from losers in THIS industry (e.g., not \"trust signals\" but \"BBB rating + named attorney photos\" for law firms, or \"free trial CTA above fold\" for SaaS)
   - industry_challenges: EXACTLY 2 bullet points naming the specific obstacles this industry faces in converting visitors (e.g., \"high-consideration purchase cycle requires multiple touchpoints\" for B2B, not just \"building trust\")
7. When a full-page screenshot is provided with this message, treat it as primary visual evidence for calibrating scores — it shows the real rendered page, not just the source text. Use it to: verify whether the primary CTA is visible above the fold and assess its visual contrast and button size (informs cta_strength); assess actual layout density, whitespace, and typography legibility as a real visitor would see them (informs readability_score); identify visible trust signals — badge images, founder/team photos, star-rating widgets — that may not appear in the extracted HTML text (informs trust_score); gauge visual richness from images, banners, video thumbnails, and interactive element placeholders (informs engagement_score). In suggestions and insights, cite specific visual observations from the screenshot (e.g. \"The screenshot shows the primary CTA is not visible without scrolling\", \"No trust badge images are visible in the screenshot despite claims in the copy\"). Do not invent visual details not visible in the image. If no screenshot is present, score based on HTML and text alone.

─── FUNCTIONALITY SUGGESTIONS ───

Select 3-5 from this catalog based on score gaps (do NOT suggest features the page already has):
- Conversion: A/B Testing, Exit-Intent Popups, Smart Forms, Heatmap & Session Recording
- Trust: Review Widgets, Trust Badges, Case Study Showcase, Client Logo Bar
- Engagement: AI Chatbot/Live Chat, Email Capture, Push Notifications, Interactive Calculators/Quizzes
- SEO: Technical SEO Audit, Schema Markup, Content Strategy, Local SEO
- Analytics: Conversion Funnel Tracking, Visitor Identification, Goal Tracking, CRM Integration
- Personalization: Dynamic Content, Geo-targeted Offers, Returning Visitor Recognition

Rules: trust<60 → include Trust feature. engagement<50 → include Engagement feature. cta<50 → include Conversion feature.

─── CRO & UX CHECKLIST ───

The HTML STRUCTURE section of the page data includes pre-extracted \"CRO Structural Signals\" derived directly from the page markup. Use the evidence hierarchy below for each item — do NOT write generic definitions in \"explanation\", always reference actual page content or a direct visual observation.

EVIDENCE HIERARCHY (apply per item):
- Items 1, 2, 3, 5, 7, 11, 13 are VISUAL items. When a screenshot is present, the screenshot is your PRIMARY evidence and overrides HTML signals. These items ask about what a visitor actually sees — rendered badge images, a button visible in the first viewport, a sticky nav bar, a pricing table layout, a progress step indicator — none of which are reliably detectable from source HTML alone. Set present=true only if you can directly observe the element in the screenshot. If the screenshot is absent, fall back to HTML signals. When a signal says \"YES\" in the HTML data, treat it as supporting evidence only — verify against the screenshot.
- Items 4, 6, 8, 9, 10, 12 are COPY / BEHAVIOUR items. For these, the HTML Structural Signals and page text are your PRIMARY evidence. The screenshot may provide supporting context but these items do not require visual confirmation.

For ALL items: if a screenshot is present, your \"explanation\" sentence MUST include a direct visual observation (e.g. \"The screenshot shows the primary CTA button is below the first viewport on desktop\" or \"A sticky purple button is visible in the navigation bar in the screenshot\"). If no screenshot is present, reference the HTML signal or page copy as before.

Elements to evaluate:
1. CTA Above the Fold — Is there a call-to-action button visible without scrolling? [VISUAL — use screenshot]
2. Trust Signals (Certs, Awards) — Are there visible certification badges, award images, or credential logos? [VISUAL — image-only badges are invisible in HTML text; use screenshot]
3. Inline Social Proof — Are there testimonials, review widgets, star ratings, or headshot photos within the body? [VISUAL — rendered star-rating widgets may not appear in HTML; use screenshot]
4. Urgency / Scarcity Elements — Is there urgency or scarcity language (limited time, limited spots, countdown)? [COPY — use HTML/text]
5. Sticky CTA in Nav — Is there a persistent CTA button visible in the navigation bar? [VISUAL — use screenshot]
6. Reassurance Micro-copy — Are there friction-reducing phrases near CTAs (\"No credit card required\", \"Cancel anytime\")? [COPY — use HTML/text]
7. Clear Visual Hierarchy — Does the rendered page show clear heading sizes, whitespace, and layout weight? [VISUAL — heading tags alone do not confirm visual weight; use screenshot]
8. Mobile-First UX — Does the page layout and copy suggest mobile-optimised design? [COPY/STRUCTURE — use HTML]
9. Speed / Ease Cues — Are there phrases emphasising ease, speed, or simplicity? [COPY — use HTML/text]
10. Risk Reversal (Guarantee) — Is there a money-back guarantee, free trial, or risk-removal offer? [COPY — use HTML/text]
11. Anchor Pricing — Is there a visible pricing table with a higher-priced option to make the target offer look like value? [VISUAL — use screenshot]
12. Exit Intent Suggestion — Is there an exit intent offer, popup trigger, or retention element mentioned? [COPY/BEHAVIOUR — use HTML]
13. Progress Indicators — Are there visible step indicators, progress bars, or multi-step flow cues? [VISUAL — use screenshot]

Priority rules:
- Mark priority \"high\" when: present=false AND the missing element directly relates to a score below 60
- Mark priority \"medium\" when: present=false AND element would strengthen an already-weak metric
- Mark priority \"low\" when: present=true OR element is a nice-to-have for this page type

You MUST produce the following exact counts. Fewer items will be treated as an incomplete response:
- suggestions: minimum 6 items, each referencing a specific page element; when a screenshot is present, at least 2 suggestions must cite a direct visual observation from it (e.g. \"The screenshot shows...\")
- functionality_suggestions: 3–5 items (apply catalog rules above)
- cro_checklist: EXACTLY 13 items — one per element listed above, in order; visual items (1,2,3,5,7,11,13) must be grounded in screenshot evidence when available
- recommendations.quick_wins: EXACTLY 5 items, ordered easiest/highest-impact first; when a screenshot is present, any quick win that addresses a visual issue (CTA position, button contrast, trust badge placement) must reference what the screenshot shows as justification
- recommendations.long_term: EXACTLY 5 items, ordered by strategic priority
- insights.strengths: EXACTLY 3 items, each citing a specific score or page element; when a screenshot is present, at least 1 strength must reference a positive visual observation
- insights.weaknesses: EXACTLY 3 items, each citing the specific score it relates to; when a screenshot is present, at least 1 weakness must reference a visual finding from the screenshot
- insights.opportunities: EXACTLY 3 items with expected outcomes
- rewrites: ALL 16 keys populated (headline, subheadline, primary_cta, secondary_cta, value_proposition, social_proof_intro, feature_1 through feature_5, faq_answer_1 through faq_answer_3, closing_statement)

Do NOT truncate or omit items to save tokens. Every field above is required.

─── OUTPUT FORMAT ───

Return ONLY valid JSON (no markdown, no code blocks, no commentary). Exact structure:

{
  \"clarity_score\": <int 0-100>,
  \"emotional_score\": <int 0-100>,
  \"cta_strength\": <int 0-100>,
  \"readability_score\": <int 0-100>,
  \"engagement_score\": <int 0-100>,
  \"trust_score\": <int 0-100>,
  \"overall_score\": <int 0-100, computed from weights above>,
  \"suggestions\": [
    {\"text\": \"Specific actionable suggestion\", \"section\": \"Page section name\", \"why\": \"Why this matters — reference specific score\", \"impact\": \"Which metrics improve\", \"implementation\": \"How to do it\"}
  ],
  \"functionality_suggestions\": [
    {\"title\": \"Feature name\", \"category\": \"Category\", \"description\": \"2-3 sentences\", \"why\": \"Reference the specific score gap\", \"impact\": \"Expected improvement\", \"implementation\": \"Specific tools/plugins\", \"icon\": \"emoji\"}
  ],
  \"cro_checklist\": [
    {\"element\": \"CTA Above the Fold\", \"present\": true, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"low\"},
    {\"element\": \"Trust Signals (Certs, Awards)\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"high\"},
    {\"element\": \"Inline Social Proof\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"high\"},
    {\"element\": \"Urgency / Scarcity Elements\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"medium\"},
    {\"element\": \"Sticky CTA in Nav\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"medium\"},
    {\"element\": \"Reassurance Micro-copy\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"medium\"},
    {\"element\": \"Clear Visual Hierarchy\", \"present\": true, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"low\"},
    {\"element\": \"Mobile-First UX\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"medium\"},
    {\"element\": \"Speed / Ease Cues\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"medium\"},
    {\"element\": \"Risk Reversal (Guarantee)\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"high\"},
    {\"element\": \"Anchor Pricing\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"medium\"},
    {\"element\": \"Exit Intent Suggestion\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"low\"},
    {\"element\": \"Progress Indicators\", \"present\": false, \"explanation\": \"Page-specific one-sentence finding\", \"priority\": \"low\"}
  ],
  \"rewrites\": {
    \"headline\": \"Improved headline\",
    \"subheadline\": \"Improved subheadline\",
    \"primary_cta\": \"Primary CTA text\",
    \"secondary_cta\": \"Secondary CTA text\",
    \"value_proposition\": \"Clear value proposition\",
    \"social_proof_intro\": \"Testimonials section intro\",
    \"feature_1\": \"Feature description 1\",
    \"feature_2\": \"Feature description 2\",
    \"feature_3\": \"Feature description 3\",
    \"feature_4\": \"Feature description 4\",
    \"feature_5\": \"Feature description 5\",
    \"faq_answer_1\": \"Top FAQ answer\",
    \"faq_answer_2\": \"Second FAQ answer\",
    \"faq_answer_3\": \"Third FAQ answer\",
    \"closing_statement\": \"Closing conversion statement\"
  },
  \"insights\": {
    \"executive_summary\": \"2-3 sentences: conversion health, #1 priority, positive tone. Reference scores.\",
    \"strengths\": [\"Strength 1 — cite specific score\", \"Strength 2 — cite page element\", \"Strength 3 — cite evidence\"],
    \"weaknesses\": [\"Weakness 1 — cite score and what's missing\", \"Weakness 2 — cite score\", \"Weakness 3 — cite score\"],
    \"opportunities\": [\"Opportunity 1 with expected outcome\", \"Opportunity 2 with expected outcome\", \"Opportunity 3 with expected outcome\"],
    \"top_priority_insight\": \"#1 focus area: why (lowest score), impact (expected improvement), timeframe\",
    \"audience_alignment\": \"How well page speaks to target audience — cite specific language from the page\"
  },
  \"recommendations\": {
    \"quick_wins\": [
      {\"text\": \"Quick win 1\", \"why\": \"Reference actual weakness\", \"impact\": \"Expected improvement\", \"difficulty\": \"Easy\"},
      {\"text\": \"Quick win 2\", \"why\": \"Reference actual weakness\", \"impact\": \"Expected improvement\", \"difficulty\": \"Easy\"},
      {\"text\": \"Quick win 3\", \"why\": \"Reference actual weakness\", \"impact\": \"Expected improvement\", \"difficulty\": \"Medium\"},
      {\"text\": \"Quick win 4\", \"why\": \"Reference actual weakness\", \"impact\": \"Expected improvement\", \"difficulty\": \"Medium\"},
      {\"text\": \"Quick win 5\", \"why\": \"Reference actual weakness\", \"impact\": \"Expected improvement\", \"difficulty\": \"Medium\"}
    ],
    \"long_term\": [
      {\"text\": \"Strategic improvement 1\", \"why\": \"Strategic value\", \"impact\": \"Long-term improvement\", \"difficulty\": \"Medium\", \"timeframe\": \"2-4 weeks\"},
      {\"text\": \"Strategic improvement 2\", \"why\": \"Strategic value\", \"impact\": \"Long-term improvement\", \"difficulty\": \"Medium\", \"timeframe\": \"4-6 weeks\"},
      {\"text\": \"Strategic improvement 3\", \"why\": \"Strategic value\", \"impact\": \"Long-term improvement\", \"difficulty\": \"Hard\", \"timeframe\": \"1-2 months\"},
      {\"text\": \"Strategic improvement 4\", \"why\": \"Strategic value\", \"impact\": \"Long-term improvement\", \"difficulty\": \"Hard\", \"timeframe\": \"2-3 months\"},
      {\"text\": \"Strategic improvement 5\", \"why\": \"Strategic value\", \"impact\": \"Long-term improvement\", \"difficulty\": \"Hard\", \"timeframe\": \"3+ months\"}
    ],
    \"priority\": {
      \"text\": \"Top priority recommendation\", \"why\": \"Why #1\", \"impact\": \"Expected lift\", \"next_steps\": \"1. First step, 2. Second step, 3. Third step\"
    }
  },
  \"benchmark_research\": {
    \"industry_average\": 68,
    \"top_performers_threshold\": 88,
    \"competitive_context\": \"Sentence 1: name 2-3 specific tactics top [industry] competitors use. Sentence 2: reference this page's actual weakest score by name and number and what that signals competitively. Sentence 3: single most impactful change to close the gap, specific to this industry.\",
    \"key_competitive_factors\": [
      \"Industry-specific factor 1 \u2014 name the actual element (e.g., for legal: named attorney bios with bar certifications)\",
      \"Industry-specific factor 2 \u2014 name the actual element (e.g., for SaaS: free trial CTA above fold)\",
      \"Industry-specific factor 3 \u2014 name the actual element (e.g., for e-commerce: inline star ratings on product images)\"
    ],
    \"industry_challenges\": [
      \"Specific challenge 1 for converting visitors in this industry (e.g., for B2B: long decision cycle requires multiple nurture touchpoints)\",
      \"Specific challenge 2 for converting visitors in this industry\"
    ]
  },
  \"ai_used\": true
}";
    }

    /**
     * Build the user prompt — page content, business context, lead intelligence.
     * This changes for every audit.
     */
    private static function build_user_prompt($title, $content, $url, $word_count, $html_structure, $business)
    {
        $industry = isset($business['industry']) ? $business['industry'] : 'Not specified';
        $product = isset($business['product']) ? $business['product'] : 'Not specified';
        $audience = isset($business['audience']) ? $business['audience'] : 'Not specified';
        $pain_points = isset($business['pain_points']) ? $business['pain_points'] : 'Not specified';
        $goal = isset($business['goal']) ? $business['goal'] : 'Not specified';

        $page_type_info = self::detect_page_type($title, $url);
        $page_type = $page_type_info['type'];
        $conversion_goal = $page_type_info['conversion_goal'];

        ciq_log('🎯 Detected page type: ' . $page_type . ' | Conversion goal: ' . $conversion_goal);

        // Get webhook statistics for lead intelligence context
        $webhook_stats = self::get_webhook_statistics($url);
        $leads_context = '';

        if ($webhook_stats) {
            ciq_log('📊 Webhook stats loaded: ' . $webhook_stats['total_interactions'] . ' interactions, page visitors: ' . $webhook_stats['page_specific_visitors']);
            
            $page_visitors = $webhook_stats['page_specific_visitors'];
            $site_total = (int)$webhook_stats['total_site_leads'] + (int)$webhook_stats['total_site_visitors'];
            $leads_context = "\n\nLEAD INTELLIGENCE DATA:\n";
            $leads_context .= "Site-wide: {$site_total} identified visitors. This page: {$page_visitors} ({$webhook_stats['site_contribution_pct']}% of site). ";
            $leads_context .= "Peak: {$webhook_stats['peak_weekday']} at {$webhook_stats['peak_hour']}:00. ";
            
            if (!empty($webhook_stats['top_companies'])) {
                $leads_context .= "Top companies: " . implode(', ', array_keys(array_slice($webhook_stats['top_companies'], 0, 3, true))) . ". ";
            }
            if (!empty($webhook_stats['top_industries'])) {
                $leads_context .= "Industries: " . implode(', ', array_keys(array_slice($webhook_stats['top_industries'], 0, 3, true))) . ". ";
            }
            if (!empty($webhook_stats['top_job_titles'])) {
                $leads_context .= "Job titles: " . implode(', ', array_keys(array_slice($webhook_stats['top_job_titles'], 0, 3, true))) . ". ";
            }
            if (!empty($webhook_stats['decision_maker_tiers'])) {
                $tier_parts = array();
                foreach ($webhook_stats['decision_maker_tiers'] as $tier => $count) {
                    $tier_parts[] = $tier . ' (' . $count . ')';
                }
                $leads_context .= "Decision-maker tiers: " . implode(', ', $tier_parts) . ". ";
            }
            if (!empty($webhook_stats['top_locations'])) {
                $leads_context .= "Locations: " . implode(', ', array_keys(array_slice($webhook_stats['top_locations'], 0, 3, true))) . ". ";
            }
            $leads_context .= "\nUse ONLY these real numbers in lead_intelligence_summary. Do NOT invent stats.\n";
        }

        // Limit content length
        if (strlen($content) > 8000) {
            $content = substr($content, 0, 8000) . '... [truncated]';
        }
        if (strlen($html_structure) > 3500) {
            $html_structure = substr($html_structure, 0, 3500) . '... [truncated]';
        }

        // Build lead intelligence JSON fragment if data exists
        $lead_json_fragment = '';
        if ($webhook_stats) {
            $lead_json_fragment = "\n\nInclude this additional field in your JSON output:\n\"lead_intelligence_summary\": {\n  \"insight\": \"2-3 sentences analyzing what lead data reveals about page performance\",\n  \"recommendations\": [\"Action item based on lead patterns\", \"Action item 2\", \"Action item 3\"]\n}";
        }

        $prompt = "Analyze this {$page_type} page for conversion optimization.

BUSINESS CONTEXT:
- Industry: {$industry}
- Product/Service: {$product}
- Target Audience: {$audience}
- Pain Points: {$pain_points}
- Conversion Goal: {$goal}
- Page Conversion Goal: {$conversion_goal}

PAGE: {$title} ({$word_count} words)

CONTENT:
{$content}

HTML STRUCTURE:
{$html_structure}{$leads_context}

Score this page using the rubric from your instructions. Provide all suggestions referencing SPECIFIC page elements. Connect recommendations to actual weaknesses (cite scores). Compute overall_score using the weights specified.{$lead_json_fragment}";

        return $prompt;
    }

    /**
     * Extract trust score from suggestion text when AI doesn't return it in top-level JSON
     */
    private static function extract_trust_score_from_text($parsed_response) {
        // Search through suggestions, insights, and recommendations for trust score mentions
        $text_to_search = json_encode($parsed_response);
        
        // Look for patterns like "trust score of 58" or "trust score: 58"
        if (preg_match('/trust[_\s]+score[:\s]+(?:of\s+)?(\d+)/i', $text_to_search, $matches)) {
            $score = intval($matches[1]);
            // Validate the score is in reasonable range
            if ($score >= 0 && $score <= 100) {
                return $score;
            }
        }
        
        return null;
    }

    /**
     * Call Abacus.ai route-llm API
     */
    private static function call_abacus_ai($prompt, $system_prompt = null, $screenshot_url = null)
    {
        $messages = array();
        if ($system_prompt) {
            $messages[] = array('role' => 'system', 'content' => $system_prompt);
        }

        if ($screenshot_url) {
            // Multi-modal message: existing text analysis prompt + explicit visual instructions + screenshot.
            // The visual instruction block sits between the text and the image so the model reads
            // the scoring context immediately before looking at the image.
            $visual_instruction = "A full-page screenshot of this page is attached below. Use it as primary visual evidence:\n"
                . "- Above-the-fold: note exactly what a visitor sees before scrolling — headline, CTA button, hero image. "
                .   "If the primary CTA button is not visible in the first viewport, lower cta_strength accordingly.\n"
                . "- CTA visual prominence: assess the button's colour contrast, size, and spacing relative to surrounding elements.\n"
                . "- Layout density & whitespace: judge readability_score from actual typography, line-height, and paragraph spacing visible in the screenshot, not just the extracted text.\n"
                . "- Trust signals: look for badge images, star-rating widgets, team/founder photos, or certification logos that may not appear in the HTML text — credit them in trust_score if present.\n"
                . "- Visual richness: identify images, graphics, video thumbnails, or interactive widget placeholders that inform engagement_score.\n"
                . "In your insights and suggestions, cite specific visual observations (e.g. ‘The screenshot shows the CTA button is below the fold on desktop’, "
                .   "‘No trust badge images are visible in the screenshot despite claims in the copy’). "
                . "Do not invent visual details not visible in the screenshot.";

            $messages[] = array('role' => 'user', 'content' => array(
                array('type' => 'text', 'text' => $prompt),
                array('type' => 'text', 'text' => $visual_instruction),
                array('type' => 'image_url', 'image_url' => array(
                    'url'    => $screenshot_url,
                    'detail' => 'high',
                )),
            ));
        } else {
            $messages[] = array('role' => 'user', 'content' => $prompt);
        }

        $body = array(
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => 4000,
            'temperature' => 0.1,
            'stream' => false
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . self::get_api_key(),
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 45,
            'sslverify' => true,
        );

        ciq_log('🚀 Calling Abacus.ai route-llm API...');
        ciq_log('📏 Prompt length: ' . strlen($prompt) . ' chars');

        $response = wp_remote_post(self::ABACUS_API_URL, $args);

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $error_code = $response->get_error_code();
            ciq_log('❌ Abacus.ai API WP_Error: ' . $error_msg);
            ciq_log('❌ Error code: ' . $error_code);
            ciq_log('❌ Error type: Network/Connection issue');
            return array('success' => false, 'error' => 'API connection failed: ' . $error_msg . ' (code: ' . $error_code . ')');
        }

        $status_code = wp_remote_retrieve_response_code($response);
        ciq_log("📡 Response status: {$status_code}");

        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            ciq_log("❌ Abacus.ai API HTTP error: {$status_code}");
            ciq_log("❌ Response headers: " . json_encode(wp_remote_retrieve_headers($response)));
            ciq_log("❌ Response body: " . substr($body, 0, 500));
            return array('success' => false, 'error' => "API returned HTTP {$status_code}: " . substr($body, 0, 200));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['choices'][0]['message']['content'])) {
            ciq_log('⚠️ No content in AI response');
            ciq_log('⚠️ Response structure: ' . json_encode(array_keys($data)));
            ciq_log('⚠️ Full response body: ' . substr($body, 0, 1000));
            return array('success' => false, 'error' => 'Empty AI response - check logs for details');
        }

        $content = $data['choices'][0]['message']['content'];
        ciq_log('📄 AI Response length: ' . strlen($content) . ' characters');
        ciq_log('📄 First 500 chars of response: ' . substr($content, 0, 500));

        // Try to parse JSON response
        $content = trim($content);

        // Remove markdown code blocks if present
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $content = $matches[1];
            ciq_log('✂️ Removed JSON markdown wrapper');
        }
        elseif (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            $content = $matches[1];
            ciq_log('✂️ Removed generic markdown wrapper');
        }

        ciq_log('🔍 Attempting to parse JSON (length: ' . strlen(trim($content)) . ')');
        $parsed = json_decode($content, true);

        if (!$parsed) {
            ciq_log('⚠️ Failed to parse AI response as JSON');
            ciq_log('JSON Error: ' . json_last_error_msg());
            ciq_log('Raw response (first 1000 chars): ' . substr($content, 0, 1000));
            return array('success' => false, 'error' => 'Invalid JSON response: ' . json_last_error_msg());
        }

        // Validate required fields in response
        $required_fields = array('clarity_score', 'emotional_score', 'cta_strength', 'readability_score', 'engagement_score', 'trust_score');
        $missing_fields = array();
        foreach ($required_fields as $field) {
            if (!isset($parsed[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            ciq_log('⚠️ AI response missing required fields: ' . implode(', ', $missing_fields));
            ciq_log('AI response structure: ' . json_encode(array_keys($parsed)));
            
            // Try to extract trust_score from suggestion text if it's missing
            if (in_array('trust_score', $missing_fields) && isset($parsed['suggestions'])) {
                $extracted_trust_score = self::extract_trust_score_from_text($parsed);
                if ($extracted_trust_score !== null) {
                    $parsed['trust_score'] = $extracted_trust_score;
                    ciq_log('✅ Trust score extracted from suggestion text: ' . $extracted_trust_score);
                }
            }
            
            ciq_log('Full AI response: ' . json_encode($parsed));
        // Still continue - these might be optional or have defaults
        }

        // Ensure suggestions is an array
        if (isset($parsed['suggestions']) && !is_array($parsed['suggestions'])) {
            ciq_log('⚠️ Suggestions is not an array, converting...');
            $parsed['suggestions'] = array(array('text' => $parsed['suggestions'], 'section' => 'General'));
        }

        ciq_log('✅ AI response parsed successfully (suggestions: ' . (isset($parsed['suggestions']) ? count($parsed['suggestions']) : 0) . ')');

        // Ensure overall_score is computed correctly using the weighted formula
        $parsed['overall_score'] = (int) round(
            ($parsed['clarity_score'] ?? 0) * 0.20 +
            ($parsed['emotional_score'] ?? 0) * 0.15 +
            ($parsed['cta_strength'] ?? 0) * 0.20 +
            ($parsed['readability_score'] ?? 0) * 0.15 +
            ($parsed['engagement_score'] ?? 0) * 0.15 +
            ($parsed['trust_score'] ?? 0) * 0.15
        );

        ciq_log('✅ Returning success=true with data (overall_score: ' . $parsed['overall_score'] . ')');
        return array('success' => true, 'data' => $parsed);
    }

    /**
     * Fallback mock response if AI fails
     */
    private static function mock_response($title)
    {
        ciq_log('🔄 Returning fallback mock response for: ' . $title);
        return array(
            'clarity_score' => 70,
            'emotional_score' => 70,
            'cta_strength' => 70,
            'readability_score' => 75,
            'engagement_score' => 65,
            'trust_score' => 68,
            'overall_score' => 70,
            'suggestions' => array(
                    array(
                    'text' => 'AI analysis unavailable - using fallback scores. Check WordPress debug.log for API error details.',
                    'section' => 'System Notice',
                    'why' => 'The AI provider is not responding correctly, preventing detailed analysis.',
                    'impact' => 'Unable to provide accurate conversion insights',
                    'implementation' => 'Check debug.log at wp-content/debug.log for error details'
                ),
                    array(
                    'text' => 'The audit could not be completed using AI. This may be due to API connectivity issues or invalid responses.',
                    'section' => 'Technical',
                    'why' => 'AI integration is required for personalized recommendations.',
                    'impact' => 'Cannot generate custom suggestions for your business',
                    'implementation' => 'Verify Abacus.ai API key in wp-config.php and check network connectivity'
                )
            ),
            'lead_intelligence_summary' => null,
            'functionality_suggestions' => array(
                    array(
                    'title' => 'Fix AI Integration',
                    'description' => 'The AI provider is not responding correctly. Check server logs and API credentials.',
                    'reasoning' => 'AI analysis failed - unable to provide personalized recommendations',
                    'priority' => 'Critical'
                )
            ),
            'rewrites' => array(),
            'ai_used' => false,
            'insights' => array(
                'executive_summary' => 'AI analysis is currently unavailable, so these are fallback scores. To get accurate, personalized conversion insights for your business, please fix the AI integration issues listed below.',
                'strengths' => array('Fallback data has been generated to prevent complete failure'),
                'weaknesses' => array('AI service unavailable - check debug.log at wp-content/debug.log for detailed error messages'),
                'opportunities' => array('Retry audit after fixing AI integration to get real conversion insights and recommendations'),
                'top_priority_insight' => 'Your top priority is fixing the AI integration. Without AI analysis, this audit cannot provide personalized conversion insights based on your actual page content, business goals, or target audience. Fixing this will unlock detailed recommendations that could improve your conversion rate by 20-40%.',
                'audience_alignment' => 'Unable to analyze audience alignment without AI. AI analysis evaluates how well your messaging resonates with your target audience.'
            ),
            'recommendations' => array(
                'quick_wins' => array(
                        array(
                        'text' => 'Check WordPress debug.log at wp-content/debug.log',
                        'why' => 'The log file contains detailed error messages about why AI analysis failed',
                        'impact' => 'Identifies the root cause of AI integration issues',
                        'difficulty' => 'Easy'
                    )
                ),
                'long_term' => array(
                        array(
                        'text' => 'Verify Abacus.ai API key and connectivity',
                        'why' => 'Valid API credentials are required for AI-powered audit analysis',
                        'impact' => 'Enables full AI functionality and personalized recommendations',
                        'difficulty' => 'Easy',
                        'timeframe' => '30 minutes'
                    )
                ),
                'priority' => array(
                    'text' => 'Fix AI integration to get real audit data',
                    'why' => 'Without AI analysis, you are only seeing fallback scores that do not reflect your actual page content or business context',
                    'impact' => 'Full access to personalized conversion insights and recommendations',
                    'next_steps' => '1. Check debug.log for error messages, 2. Verify CONVERSIONIQ_ABACUS_KEY in wp-config.php, 3. Test API connectivity, 4. Re-run audit'
                )
            )
        );
    }

    /**
     * Research industry-specific benchmarks and competitive intelligence
     */
    public static function research_industry_benchmarks($industry, $audience, $goal)
    {
        if (empty($industry)) {
            return self::get_fallback_benchmarks();
        }

        $prompt = "You are a conversion optimization and competitive intelligence expert. Research and provide specific data about the {$industry} industry.

**Industry:** {$industry}
**Target Audience:** " . (!empty($audience) ? $audience : 'Not specified') . "
**Business Goal:** " . (!empty($goal) ? $goal : 'Not specified') . "

Provide detailed, data-driven competitive intelligence for this industry. Your research should be specific to this industry and include:

1. **Average Conversion Score**: What is the typical overall conversion optimization score (0-100) for {$industry} websites? Consider industry maturity, competition level, and typical implementation quality. This should be a number between 60-75.

2. **Top Performer Threshold**: What score do the top 10% of {$industry} businesses achieve? Consider industry leaders and best-in-class examples. This should be a number between 85-95.

3. **Conversion Rate Impact**: For {$industry} specifically, how much does conversion rate typically improve per 10-point score increase? Consider industry-specific factors like sales cycles, average order value, and decision complexity.

4. **Quick Wins**: Identify 3 specific, actionable tactics that {$industry} businesses can implement quickly (within 1-2 weeks) to improve conversions. Be very specific and tactical.

5. **Key Competitive Factors**: What are the 3-4 most critical conversion factors that separate winners from losers in {$industry}? Be specific to this industry.

6. **Industry Challenges**: What specific obstacles or pain points do {$industry} businesses face in converting visitors?

7. **Competitive Context**: Research and describe the SPECIFIC competitive dynamics in {$industry}:
   - What conversion tactics are top-performing {$industry} companies actually using on their websites? (Be specific - mention actual techniques, not generic advice)
   - What are the measurable differences between high-converting and low-converting {$industry} websites? (Cite specific elements like trust signals, page structure, copy approaches)
   - What recent trends or shifts in {$industry} are affecting conversion rates?
   - Include 2-3 CONCRETE examples of what separates market leaders from average performers

**CRITICAL: Output must be ONLY valid JSON with no markdown formatting. Use these EXACT field names:**

{
  \"industry_average\": 72,
  \"top_performers_threshold\": 90,
  \"conversion_rate_lift_per_10_points\": \"15-25%\",
  \"quick_wins\": [
    {\"tactic\": \"Specific tactic name\", \"impact\": \"Expected impact\", \"implementation\": \"How to implement it\"},
    {\"tactic\": \"Specific tactic name\", \"impact\": \"Expected impact\", \"implementation\": \"How to implement it\"},
    {\"tactic\": \"Specific tactic name\", \"impact\": \"Expected impact\", \"implementation\": \"How to implement it\"}
  ],
  \"key_competitive_factors\": [
    \"Factor 1 specific to {$industry}\",
    \"Factor 2 specific to {$industry}\",
    \"Factor 3 specific to {$industry}\"
  ],
  \"industry_challenges\": [
    \"Challenge 1 specific to {$industry}\",
    \"Challenge 2 specific to {$industry}\"
  ],
  \"competitive_context\": \"3-4 sentences with SPECIFIC, TACTICAL insights about the {$industry} competitive landscape. Must include concrete examples of what top performers do differently (e.g., specific trust signals they use, specific page structures, specific copy approaches). NO generic statements - only research-backed, actionable competitive intelligence.\"
}

IMPORTANT: 
- industry_average must be an INTEGER between 60-75 (e.g., 68, 72, 70)
- top_performers_threshold must be an INTEGER between 85-95 (e.g., 88, 90, 92)
- Do NOT use placeholder values like 1 or X
- Provide realistic, researched data specific to {$industry}";

        $response = self::call_abacus_ai($prompt);

        if ($response && isset($response['success']) && $response['success'] && isset($response['data'])) {
            ciq_log('✅ Industry benchmark research successful for: ' . $industry);
            return $response['data'];
        }

        ciq_log('⚠️ Industry benchmark research failed, using fallback');
        return self::get_fallback_benchmarks();
    }

    /**
     * Get fallback benchmark data when AI research fails
     */
    private static function get_fallback_benchmarks()
    {
        return array(
            'industry_average' => 72,
            'top_performers_threshold' => 90,
            'conversion_rate_lift_per_10_points' => '15-25%',
            'key_competitive_factors' => array(
                'Clear value proposition and differentiation',
                'Strong trust signals and social proof',
                'Optimized user experience and page flow'
            ),
            'industry_challenges' => array(
                'Building trust with first-time visitors',
                'Communicating value quickly and clearly'
            ),
            'competitive_context' => 'Industry analysis indicates that top-performing websites prioritize three elements: immediate credibility through specific trust indicators (customer counts, years in business, recognizable client logos), crystal-clear value communication in the first screen, and conversion pathways that eliminate decision friction. Research shows leaders in competitive markets consistently outperform on trust-building (security badges, testimonials with photos/names) and clarity metrics (headline-to-CTA alignment, benefit-focused copy).'
        );
    }
}
