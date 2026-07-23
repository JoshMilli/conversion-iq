<?php
if (!defined('ABSPATH')) {
    exit;
}

class ConversionIQ_AI
{

    const SAAS_API_URL = 'https://conversioniq-app.com';

    /**
     * Authoritative copy inventory for the current audit (hero + next sections, in order).
     * Set at the start of analyze()/analyze_chunked(); consumed by build_user_prompt()
     * (to tell the AI exactly which sections to rewrite) and by the rewrite reconciler.
     * @var array
     */
    private static $copy_inventory = array();

    /**
     * Get license key for authenticating all SaaS AI proxy calls.
     * The AI API key lives only on conversioniq-app.com — never on the WP site.
     */
    private static function get_license_key()
    {
        $key = get_option( 'conversioniq_license_key', '' );
        if ( empty( $key ) ) {
            ciq_log( '❌ ConversionIQ: No license key found. Re-activate your license.' );
        }
        return $key;
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
        $screenshot_url  = isset($payload['page']['screenshot_url'])  ? $payload['page']['screenshot_url']  : null;
        $sprint_context  = isset($payload['page']['sprint_context'])   ? $payload['page']['sprint_context']   : '';
        $gsc_page_queries = isset($payload['page']['gsc_page_queries']) ? $payload['page']['gsc_page_queries'] : null;
        self::$copy_inventory = ( isset($payload['page']['copy_inventory']) && is_array($payload['page']['copy_inventory']) )
            ? $payload['page']['copy_inventory'] : array();

        // Check if content is too long and needs chunking
        if (strlen($page_content) > 15000) {
            ciq_log('📚 Long content detected (' . strlen($page_content) . ' chars), using chunked analysis');
            return self::analyze_chunked($payload);
        }

        // Build the AI prompts (system = rubric/persona, user = page content)
        $system_prompt = self::build_system_prompt();
        $user_prompt = self::build_user_prompt($page_title, $page_content, $page_url, $word_count, $html_structure, $business, $screenshot_url, $sprint_context, $gsc_page_queries);

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

            // Snap rewrites to the deterministic inventory (exact original + selector).
            if ( isset($result['rewrites']) && is_array($result['rewrites']) ) {
                $result['rewrites'] = self::reconcile_rewrites_with_inventory($result['rewrites']);
            }

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
        $page_title     = isset($payload['page']['title'])          ? $payload['page']['title']          : 'Unknown Page';
        $content        = isset($payload['page']['content'])        ? $payload['page']['content']        : '';
        $screenshot_url = isset($payload['page']['screenshot_url']) ? $payload['page']['screenshot_url'] : null;
        $sprint_context = isset($payload['page']['sprint_context']) ? $payload['page']['sprint_context'] : '';
        self::$copy_inventory = ( isset($payload['page']['copy_inventory']) && is_array($payload['page']['copy_inventory']) )
            ? $payload['page']['copy_inventory'] : array();

        ciq_log('🔍 Starting chunked analysis (batch) for: ' . $page_title);

        $sections = self::split_into_sections($content);

        if (empty($sections)) {
            ciq_log('⚠️ Failed to split content into sections, falling back to truncated analysis');
            $payload['page']['content'] = substr($content, 0, 8000);
            return self::analyze($payload);
        }

        $section_count = count($sections);
        ciq_log("📦 Submitting {$section_count} section(s) as a single batch job");

        // ── Build one request object per section ──────────────────────────────
        $batch_requests  = array();   // sent to /api/ai-proxy/batch
        $section_meta    = array();   // indexed same as $batch_requests
        $current         = 0;

        foreach ($sections as $section_name => $section_content) {
            $current++;
            $compressed  = self::compress_content($section_content);
            $is_first    = ( $current === 1 );
            $max_tokens  = $is_first ? 4000 : 1500;
            $chunk_shot  = $is_first ? $screenshot_url : null;

            $system_prompt = self::build_system_prompt($section_name);
            $user_prompt   = self::build_user_prompt(
                $page_title,
                $compressed,
                isset($payload['page']['url'])            ? $payload['page']['url']            : '',
                str_word_count($compressed),
                isset($payload['page']['html_structure']) ? $payload['page']['html_structure'] : '',
                isset($payload['business'])               ? $payload['business']               : array(),
                $chunk_shot,
                $is_first ? $sprint_context : ''
            );

            $messages = array();
            $messages[] = array('role' => 'system', 'content' => $system_prompt);

            if ($chunk_shot) {
                $visual_instruction = "A full-page screenshot of this page is attached below. Use it as primary visual evidence:\n"
                    . "- Above-the-fold: note exactly what a visitor sees before scrolling — headline, CTA button, hero image. "
                    .   "If the primary CTA button is not visible in the first viewport, lower cta_strength accordingly.\n"
                    . "- CTA visual prominence: assess the button's colour contrast, size, and spacing.\n"
                    . "- Layout density & whitespace: judge readability_score from actual typography visible in the screenshot.\n"
                    . "- Trust signals: look for badge images, star-rating widgets, team/founder photos.\n"
                    . "- Visual richness: identify images, graphics, or video thumbnails that inform engagement_score.\n"
                    . "- COPY SECTIONS (for rewrites): Scan the full screenshot and list every distinct copy-bearing section you can see — hero headline, hero subheadline, announcement bar, feature/step headings and their descriptions, stat labels, section intro text, testimonials heading, CTA buttons, secondary links. "
                    .   "You MUST generate a rewrite for each section where the copy could be sharper. Match each section to its verbatim text in CONTENT for the 'original' field.";
                $messages[] = array('role' => 'user', 'content' => array(
                    array('type' => 'text',      'text'      => $user_prompt),
                    array('type' => 'text',      'text'      => $visual_instruction),
                    array('type' => 'image_url', 'image_url' => array('url' => $chunk_shot, 'detail' => 'auto')),
                ));
            } else {
                $messages[] = array('role' => 'user', 'content' => $user_prompt);
            }

            $batch_requests[] = array(
                'model'      => 'gpt-4o',
                'messages'   => $messages,
                'max_tokens' => $max_tokens,
                'temperature'=> 0.1,
                'has_image'  => ( $is_first && ! empty( $chunk_shot ) ),  // only first chunk may have a screenshot
            );
            $section_meta[] = array(
                'name'     => $section_name,
                'is_first' => $is_first,
            );

            ciq_log("📄 Queued section {$current}/{$section_count}: {$section_name} ({$max_tokens} max_tokens)");
        }

        // ── Submit the whole batch ─────────────────────────────────────────────
        $license_key = self::get_license_key();
        if (empty($license_key)) {
            return null;
        }

        $batch_response = wp_remote_post(self::SAAS_API_URL . '/api/ai-proxy/batch', array(
            'headers'   => array(
                'X-License-Key' => $license_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array('requests' => $batch_requests)),
            'timeout' => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($batch_response)) {
            ciq_log('❌ Batch submit WP_Error: ' . $batch_response->get_error_message() . ' — falling back to sequential');
            return self::analyze_chunked_sequential($payload, $sections, $screenshot_url);
        }

        $batch_status = wp_remote_retrieve_response_code($batch_response);
        if ($batch_status !== 200) {
            ciq_log("❌ Batch submit HTTP {$batch_status} — falling back to sequential");
            return self::analyze_chunked_sequential($payload, $sections, $screenshot_url);
        }

        $batch_data = json_decode(wp_remote_retrieve_body($batch_response), true);
        $jobs = isset($batch_data['jobs']) ? $batch_data['jobs'] : array();

        if (count($jobs) !== count($batch_requests)) {
            ciq_log('❌ Batch returned ' . count($jobs) . ' job_ids for ' . count($batch_requests) . ' requests — falling back to sequential');
            return self::analyze_chunked_sequential($payload, $sections, $screenshot_url);
        }

        ciq_log('✅ Batch submitted — ' . count($jobs) . ' jobs queued. Polling all in parallel...');

        // ── Poll all jobs together until all complete ──────────────────────────
        $pending      = array();   // job_id => section_meta index
        $completed    = array();   // section_meta index => poll_data
        foreach ($jobs as $i => $job) {
            if (!empty($job['job_id'])) {
                $pending[$job['job_id']] = $i;
            }
        }

        $max_polls = 36;
        for ($attempt = 1; $attempt <= $max_polls && !empty($pending); $attempt++) {
            sleep(5);
            ciq_log("🔄 Batch poll {$attempt}/{$max_polls} — " . count($pending) . ' job(s) still pending');

            foreach (array_keys($pending) as $job_id) {
                $poll_url  = self::SAAS_API_URL . '/api/ai-proxy/result/' . rawurlencode($job_id);
                $poll_resp = wp_remote_get($poll_url, array(
                    'headers'   => array('X-License-Key' => $license_key),
                    'timeout'   => 10,
                    'sslverify' => true,
                ));
                if (is_wp_error($poll_resp)) continue;

                $poll_data  = json_decode(wp_remote_retrieve_body($poll_resp), true);
                $job_status = isset($poll_data['status']) ? $poll_data['status'] : 'unknown';

                if ($job_status === 'complete') {
                    $idx = $pending[$job_id];
                    $completed[$idx] = $poll_data;
                    unset($pending[$job_id]);
                    ciq_log("✅ Section '" . $section_meta[$idx]['name'] . "' complete (job {$job_id})");

                    if (isset($poll_data['_meta'])) {
                        $m = $poll_data['_meta'];
                        ciq_log("⏱ Timing — total:{$m['total_ms']}ms queue:{$m['queue_ms']}ms processing:{$m['processing_ms']}ms");
                    }
                } elseif ($job_status === 'failed' || $job_status === 'not_found') {
                    $idx = $pending[$job_id];
                    ciq_log("⚠️ Section '" . $section_meta[$idx]['name'] . "' job {$job_status} — skipping");
                    unset($pending[$job_id]);
                }
                // 'pending' → leave in $pending and continue
            }
        }

        if (!empty($pending)) {
            ciq_log('⚠️ ' . count($pending) . ' section job(s) timed out after ' . ($max_polls * 5) . 's');
        }

        // ── Parse completed jobs into scores/suggestions ───────────────────────
        $all_scores                    = array();
        $all_suggestions               = array();
        $all_functionality_suggestions = array();

        ksort($completed); // process in section order
        foreach ($completed as $idx => $poll_data) {
            $section_name = $section_meta[$idx]['name'];
            $is_first     = $section_meta[$idx]['is_first'];

            $response = self::parse_abacus_response($poll_data);
            if (!$response || !$response['success']) {
                ciq_log("⚠️ Section '{$section_name}' parse failed");
                continue;
            }

            $data = $response['data'];
            $all_scores[] = $data;

            if (isset($data['suggestions']) && is_array($data['suggestions'])) {
                foreach ($data['suggestions'] as $suggestion) {
                    if (is_array($suggestion)) {
                        $suggestion['analyzed_section'] = $section_name;
                        $all_suggestions[] = $suggestion;
                    }
                }
            }

            if ($is_first && isset($data['functionality_suggestions']) && is_array($data['functionality_suggestions'])) {
                $all_functionality_suggestions = $data['functionality_suggestions'];
            }
        }

        return self::aggregate_section_results($all_scores, $all_suggestions, $all_functionality_suggestions, $payload);
    }

    /**
     * Fallback: analyze sections sequentially (used when the batch endpoint is unavailable).
     */
    private static function analyze_chunked_sequential($payload, $sections, $screenshot_url)
    {
        ciq_log('🔁 Sequential fallback for chunked analysis');
        $all_scores                    = array();
        $all_suggestions               = array();
        $all_functionality_suggestions = array();
        $section_count = count($sections);
        $current       = 0;

        foreach ($sections as $section_name => $section_content) {
            $current++;
            $compressed     = self::compress_content($section_content);
            $chunk_shot     = ( $current === 1 ) ? $screenshot_url : null;
            $chunk_tokens   = ( $current === 1 ) ? 4000 : 1500;
            $system_prompt  = self::build_system_prompt($section_name);
            $user_prompt    = self::build_user_prompt(
                isset($payload['page']['title'])          ? $payload['page']['title']          : '',
                $compressed,
                isset($payload['page']['url'])            ? $payload['page']['url']            : '',
                str_word_count($compressed),
                isset($payload['page']['html_structure']) ? $payload['page']['html_structure'] : '',
                isset($payload['business'])               ? $payload['business']               : array(),
                $chunk_shot
            );

            ciq_log("📄 Sequential {$current}/{$section_count}: {$section_name}");
            $response = self::call_abacus_ai($user_prompt, $system_prompt, $chunk_shot, $chunk_tokens);

            if ($response && isset($response['success']) && $response['success']) {
                $data = $response['data'];
                $all_scores[] = $data;
                if (isset($data['suggestions']) && is_array($data['suggestions'])) {
                    foreach ($data['suggestions'] as $s) {
                        if (is_array($s)) { $s['analyzed_section'] = $section_name; $all_suggestions[] = $s; }
                    }
                }
                if ($current === 1 && isset($data['functionality_suggestions']) && is_array($data['functionality_suggestions'])) {
                    $all_functionality_suggestions = $data['functionality_suggestions'];
                }
                ciq_log("✅ Section '{$section_name}' done");
            } else {
                ciq_log("⚠️ Section '{$section_name}' failed");
            }
        }

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

        // Merge rewrites from EVERY section. Previously only the first chunk's
        // rewrites were kept, which silently dropped the copy suggestions for every
        // later section of a long (chunked) page — the root of "sections don't match
        // the page". Dedupe by section + original so a block isn't rewritten twice.
        $merged_rewrites = array();
        $seen_rewrites   = array();
        foreach ($all_scores as $section_result) {
            if (empty($section_result['rewrites']) || !is_array($section_result['rewrites'])) continue;
            foreach ($section_result['rewrites'] as $rw) {
                if (!is_array($rw)) continue;
                $key = strtolower(trim(($rw['section'] ?? '') . '|' . ($rw['original'] ?? '')));
                if ($key === '|' || isset($seen_rewrites[$key])) continue;
                $seen_rewrites[$key] = true;
                $merged_rewrites[]   = $rw;
            }
        }
        $merged_rewrites = array_slice($merged_rewrites, 0, 20); // cap generously — cover the whole page
        $merged_rewrites = self::reconcile_rewrites_with_inventory($merged_rewrites);
        ciq_log('✍️ Merged ' . count($merged_rewrites) . ' rewrite(s) across ' . $count . ' section(s)');

        // Insights / recommendations / checklist are page-level; the first chunk
        // carries the screenshot-grounded analysis, so use it as the base.
        $first_section = $all_scores[0];

        $result = array_merge($averaged, array(
            'suggestions' => $limited_suggestions,
            'functionality_suggestions' => $all_functionality_suggestions,
            'rewrites' => $merged_rewrites,
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
     * Public wrapper — returns just the page type string (e.g. "About Page").
     * Used by the audit pipeline to attach page_type to the result and sync to Supabase.
     */
    public static function get_page_type( $title, $url ) {
        return self::detect_page_type( $title, $url )['type'];
    }

    /**
     * Lightweight CRO scoring for a competitor page.
     *
     * Uses a lean prompt (no rewrites, no checklist, no lead data) so it finishes
     * in 3-8 seconds instead of 20-45 seconds. Returns just the 7 score fields
     * plus a brief competitive_insight string.
     *
     * Routes through the SaaS AI proxy (conversioniq-app.com/api/ai-proxy).
     * No AI API key is stored on the WP site — authentication uses the license key.
     *
     * @param string $html_text  Plain-text content already stripped of HTML tags.
     * @param string $url        Competitor page URL (used for context, not fetched here).
     * @param string $name       Display name derived from <title> tag.
     * @param array  $business   Business profile array from conversion_iq_settings.
     * @return array|null  Keys: overall_score, clarity_score, emotional_score,
     *                     cta_strength, readability_score, engagement_score,
     *                     trust_score, competitive_insight. Null on failure.
     */
    public static function score_competitor( $url, $name_hint, $business ) {
        $industry  = $business['industry']  ?? 'Not specified';
        $product   = $business['product']   ?? 'Not specified';
        $audience  = $business['audience']  ?? 'Not specified';

        $system_prompt = 'You are a senior CRO (Conversion Rate Optimisation) analyst with expert knowledge of how real websites look, feel, and convert visitors.

Your task: score a competitor website based on your existing knowledge of it. Do NOT fabricate scores — if you have limited knowledge of a site, use conservative (lower) scores and note it in competitive_insight.

Scoring rubric (0–100 integers — major consumer brands typically score 55–75):
  clarity_score     — how clearly the homepage communicates its core value proposition
  emotional_score   — how well copy and imagery connect emotionally with the target audience
  cta_strength      — prominence, urgency, and effectiveness of calls-to-action
  readability_score — how easy the page is to scan, digest, and navigate
  engagement_score  — visual richness, interactivity, and ability to hold attention
  trust_score       — social proof, reviews, badges, guarantees, credibility signals

overall_score = round(clarity*0.20 + emotional*0.15 + cta*0.20 + readability*0.15 + engagement*0.15 + trust*0.15)

Also include:
  business_name       — real display name of this business (e.g. "Airbnb", not the URL)
  competitive_insight — one precise, actionable sentence naming the single biggest CRO strength OR weakness this competitor has relative to our business, and what we should do about it

Return ONLY valid JSON, no markdown, no commentary:
{"clarity_score":0,"emotional_score":0,"cta_strength":0,"readability_score":0,"engagement_score":0,"trust_score":0,"overall_score":0,"business_name":"","competitive_insight":""}';

        $user_prompt = "Score the CRO quality of this competitor for a {$industry} business.

OUR BUSINESS: {$product}, targeting {$audience}.
COMPETITOR URL: {$url}
DISPLAY NAME HINT: {$name_hint}

Using your knowledge of this website, score its homepage or primary landing page from the perspective of a first-time visitor in our target market. Consider: above-the-fold clarity, headline strength, CTA placement and urgency, social proof visibility, copy tone, and overall visual engagement.

Return only the JSON.";

        $start = microtime( true );
        ciq_log( 'Competitors: scoring "' . $name_hint . '" via GPT knowledge (no scrape) — url=' . $url );

        $result = self::call_chat_endpoint( $system_prompt, $user_prompt, 600 );

        if ( $result ) {
            ciq_log( 'Competitors: ✅ scored "' . ( $result['business_name'] ?? $name_hint ) . '" overall=' . ( $result['overall_score'] ?? '?' ) . ' in ' . round( microtime(true) - $start, 2 ) . 's' );
        } else {
            ciq_log( 'Competitors: ❌ scoring failed for "' . $name_hint . '"' );
        }

        return $result;
    }

    /**
     * Shared helper — calls the SaaS AI proxy with a lean (no-screenshot) request
     * and parses the JSON response.  No AI API key is held on the WP site.
     *
     * @param string $system      System message.
     * @param string $user        User message.
     * @param int    $max_tokens  Token budget (default 350 for lean scoring).
     * @return array|null  Parsed score array, or null on any failure.
     */
    private static function call_chat_endpoint( $system, $user, $max_tokens = 350 ) {
        $license_key = self::get_license_key();
        if ( empty( $license_key ) ) {
            ciq_log( 'Competitors: ❌ no license key — competitor scoring skipped' );
            return null;
        }

        $body = array(
            'model'       => 'gpt-4o',
            'model_hint'  => 'fast',   // route to faster/cheaper model for short scoring prompts
            'messages'    => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => $user   ),
            ),
            'max_tokens'  => $max_tokens,
            'temperature' => 0.1,
        );

        // ── Step 1: Submit job ────────────────────────────────────────────────
        $submit_response = wp_remote_post( self::SAAS_API_URL . '/api/ai-proxy', array(
            'headers'   => array(
                'X-License-Key' => $license_key,
                'Content-Type'  => 'application/json',
            ),
            'body'      => wp_json_encode( $body ),
            'timeout'   => 15,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $submit_response ) ) {
            ciq_log( 'Competitors: API error — ' . $submit_response->get_error_message() );
            return null;
        }

        $submit_status = wp_remote_retrieve_response_code( $submit_response );
        if ( $submit_status !== 200 ) {
            ciq_log( 'Competitors: API HTTP ' . $submit_status . ' — ' . substr( wp_remote_retrieve_body( $submit_response ), 0, 200 ) );
            return null;
        }

        $submit_data = json_decode( wp_remote_retrieve_body( $submit_response ), true );
        $job_id = isset( $submit_data['job_id'] ) ? $submit_data['job_id'] : null;
        if ( ! $job_id ) {
            ciq_log( 'Competitors: no job_id in submit response — raw: ' . substr( wp_remote_retrieve_body( $submit_response ), 0, 200 ) );
            return null;
        }
        ciq_log( 'Competitors: job submitted — job_id=' . $job_id );

        // ── Step 2: Poll for result ───────────────────────────────────────────
        $poll_url  = self::SAAS_API_URL . '/api/ai-proxy/result/' . rawurlencode( $job_id );
        $poll_args = array(
            'headers'   => array( 'X-License-Key' => $license_key ),
            'timeout'   => 10,
            'sslverify' => true,
        );
        $data = null;

        for ( $attempt = 1; $attempt <= 20; $attempt++ ) {
            sleep( 5 );
            $poll_response = wp_remote_get( $poll_url, $poll_args );
            if ( is_wp_error( $poll_response ) ) {
                ciq_log( 'Competitors: poll attempt ' . $attempt . ' error — ' . $poll_response->get_error_message() );
                continue;
            }

            $poll_data  = json_decode( wp_remote_retrieve_body( $poll_response ), true );
            $job_status = isset( $poll_data['status'] ) ? $poll_data['status'] : 'unknown';
            ciq_log( 'Competitors: poll attempt ' . $attempt . ' — status=' . $job_status );

            if ( $job_status === 'complete' ) { $data = $poll_data; break; }
            if ( $job_status === 'failed' || $job_status === 'not_found' ) {
                ciq_log( 'Competitors: job ' . $job_status . ' — ' . ( $poll_data['error'] ?? '' ) );
                return null;
            }
        }

        if ( ! $data ) {
            ciq_log( 'Competitors: job did not complete after polling' );
            return null;
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( empty( $content ) ) {
            ciq_log( 'Competitors: empty API response' );
            return null;
        }

        // Strip markdown fences if model wrapped the JSON
        $content = trim( $content );
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $m ) ) {
            $content = $m[1];
        }

        $parsed = json_decode( $content, true );
        if ( ! is_array( $parsed ) || ! isset( $parsed['overall_score'] ) ) {
            ciq_log( 'Competitors: failed to parse score JSON — ' . substr( $content, 0, 200 ) );
            return null;
        }

        $required = array( 'clarity_score', 'emotional_score', 'cta_strength', 'readability_score', 'engagement_score', 'trust_score', 'overall_score' );
        foreach ( $required as $key ) {
            if ( ! isset( $parsed[ $key ] ) ) {
                ciq_log( 'Competitors: missing field "' . $key . '" in score response' );
                return null;
            }
        }

        return $parsed;
    }

    /**
     * Detect page type and return appropriate conversion context
     */
    private static function detect_page_type($title, $url)
    {
        $title_lower = strtolower($title);
        $url_lower = strtolower($url);

        // Homepage detection — title is "Home" (exact) OR the URL has no path beyond the domain root
        if (preg_match('/^home$/i', $title) ||
        preg_match('/^https?:\/\/[^\/]+\/?$/', $url) ||
        strpos($url_lower, 'homepage') !== false) {
            return array(
                'type' => 'Homepage',
                'context' => 'The homepage is the first impression and gateway to your business. It should quickly communicate value, build trust, and guide visitors to take the next step in their journey.',
                'conversion_goal' => 'Capture attention, communicate value proposition clearly, and guide visitors to explore key pages or take primary action (contact, sign up, learn more)',
                'expected_elements' => [
                    'Hero section with a clear, benefit-driven headline',
                    'Primary CTA visible above the fold without scrolling',
                    'Brief overview of the core service or product offering',
                    'Social proof section (testimonials, client logos, review count)',
                    'Trust signals (awards, certifications, years in business)',
                    'Secondary CTA or lead capture element',
                    'Clear navigation paths to key pages (Services, About, Contact)',
                ],
                'scoring_emphasis' => 'clarity_score and cta_strength are the primary metrics — visitors decide within 5 seconds whether to stay. trust_score is a close second as first-time visitors need rapid credibility signals. emotional_score should reflect how well the hero communicates the brand promise and speaks to the target audience\'s aspirations or pain.'
            );
        }

        // About/Company page
        if (preg_match('/about|who we are|our story|our team|our company|meet the team/i', $title_lower) ||
        preg_match('/about|our-story|our-team|company/i', $url_lower)) {
            return array(
                'type' => 'About Page',
                'context' => 'The About page builds trust and credibility by humanizing your business. Visitors here are evaluating whether to work with you.',
                'conversion_goal' => 'Build trust and emotional connection, showcase expertise and values, guide visitors to contact or service pages',
                'expected_elements' => [
                    'Founder or company origin story (how and why the business was started)',
                    'Team member bios with names, roles, and photos',
                    'Company mission, vision, or values statement',
                    'Years in business or founding date',
                    'Awards, certifications, press mentions, or accreditations',
                    'CTA directing visitors to contact or services page',
                    'Social proof tied to credibility — not sales (e.g., community involvement, expertise)',
                ],
                'scoring_emphasis' => 'trust_score is the single most critical metric on an About page — visitors are actively vetting whether to work with or buy from this business. emotional_score carries extra weight because the page must humanize the brand and create connection. cta_strength is secondary; the primary job is relationship-building, not hard conversion. Flag missing team photos, an absent origin story, or lack of credentials as high-priority weaknesses.'
            );
        }

        // Services/Product pages
        if (preg_match('/services|what we do|our services|products|offerings/i', $title_lower) ||
        preg_match('/services|products|offerings/i', $url_lower)) {
            return array(
                'type' => 'Services/Products Page',
                'context' => 'Service pages are high-intent pages where visitors evaluate your specific offerings. They need clear information and strong CTAs.',
                'conversion_goal' => 'Clearly explain offerings, demonstrate value and benefits, address objections, drive direct conversion (inquiry, booking, purchase)',
                'expected_elements' => [
                    'Individual service or product descriptions with benefit-led copy',
                    'Pricing signals or starting-from rates (or a clear reason why pricing is not listed)',
                    'Benefits vs. features framing for each offering',
                    'Process or how-it-works section showing what happens after enquiry',
                    'Service-specific testimonials or case studies',
                    'Clear CTA per service (Get a Quote, Book Now, Enquire)',
                    'FAQs addressing the most common objections for each service',
                ],
                'scoring_emphasis' => 'clarity_score and cta_strength are the primary metrics — visitors are mid-funnel evaluating a specific purchase decision. Each service needs its own value proposition and CTA. trust_score is critical for objection handling; missing service-specific testimonials or proof should be flagged as a high-priority weakness. emotional_score should reflect how well the copy frames benefits around the visitor\'s outcome, not just feature lists.'
            );
        }

        // Contact page
        if (preg_match('/contact|get in touch|reach us|book|schedule/i', $title_lower) ||
        preg_match('/contact|booking|schedule/i', $url_lower)) {
            return array(
                'type' => 'Contact/Booking Page',
                'context' => 'This is a high-intent page where visitors are ready to take action. Remove friction and make it easy to connect.',
                'conversion_goal' => 'Minimize friction, provide multiple contact options, reassure visitors, make it extremely easy to take action',
                'expected_elements' => [
                    'Contact form with minimal required fields (3 fields or fewer is ideal)',
                    'Multiple contact methods (phone, email, and form)',
                    'Response time promise or availability hours',
                    'Physical address or service area',
                    'Reassurance micro-copy near the form (e.g., "No spam. We reply within 24 hours.")',
                    'Clear expectation of what happens after submitting (next steps)',
                    'Map or directions if in-person visits are relevant',
                ],
                'scoring_emphasis' => 'cta_strength and readability_score are the primary metrics — friction kills conversions on high-intent pages. Count the form fields; more than 5 mandatory fields is a direct conversion obstacle. trust_score matters for final reassurance before submission. clarity_score should reflect how clearly each contact option is presented. Flag any form that asks for information not strictly necessary to make first contact.'
            );
        }

        // FAQ page
        if (preg_match('/faq|frequently asked|questions|help center/i', $title_lower) ||
        preg_match('/faq|questions|help/i', $url_lower)) {
            return array(
                'type' => 'FAQ Page',
                'context' => 'FAQ pages remove objections and answer concerns that prevent conversion. They support the buying decision.',
                'conversion_goal' => 'Address common objections clearly, reduce uncertainty, build confidence, include CTAs to move visitors to conversion',
                'expected_elements' => [
                    'Objection-handling questions covering cost, timeline, process, and qualifications',
                    'Answers that lead naturally into services or next steps',
                    'CTA within or immediately after FAQ answers',
                    'Scannable accordion or clear question/answer formatting',
                    'Internal links to relevant service pages within answers',
                    'Schema FAQ markup for search result rich snippets',
                ],
                'scoring_emphasis' => 'readability_score and clarity_score are the primary metrics — if answers are buried in dense paragraphs or hard to navigate, the page defeats its own purpose. cta_strength matters because FAQ pages should funnel visitors toward conversion, not just answer questions in isolation. Flag FAQ pages with no CTAs within answers as a critical gap.'
            );
        }

        // Pricing page
        if (preg_match('/pricing|plans|packages|cost|rates/i', $title_lower) ||
        preg_match('/pricing|plans|packages/i', $url_lower)) {
            return array(
                'type' => 'Pricing Page',
                'context' => 'Pricing pages are critical conversion points. Visitors need clear value justification and easy next steps.',
                'conversion_goal' => 'Present pricing clearly, justify value, compare options effectively, drive purchase or inquiry with strong CTAs',
                'expected_elements' => [
                    'Clear pricing tiers or packages with names',
                    'Itemised list of what is included in each tier',
                    'Value anchoring — a higher-priced option that makes the primary offer look like value',
                    'FAQ section addressing pricing objections (contracts, hidden fees, cancellation)',
                    'Money-back guarantee, free trial, or other risk-reversal offer',
                    'CTA button per pricing tier',
                    'Social proof tied to ROI or results (e.g., "Clients see 3x ROI within 6 months")',
                ],
                'scoring_emphasis' => 'clarity_score is the primary metric — visitors must immediately understand what they get for the price at each tier. trust_score and cta_strength are close seconds; value justification and low-friction next steps are the conversion levers. emotional_score should reflect how well the copy frames cost in terms of outcome and ROI rather than just listing features. Flag absence of risk-reversal (guarantee, trial) as high priority.'
            );
        }

        // Blog/Article page
        if (preg_match('/blog|article|post|news|guide/i', $title_lower) ||
        preg_match('/blog|article|post|news/i', $url_lower)) {
            return array(
                'type' => 'Blog/Content Page',
                'context' => 'Content pages attract and educate visitors. They should build authority and guide readers to service pages.',
                'conversion_goal' => 'Provide valuable information, establish expertise, include relevant CTAs to services/contact, capture emails for nurturing',
                'expected_elements' => [
                    'Author bio or byline with credentials and photo',
                    'Publish date and last-updated date (freshness signals for trust)',
                    'Subheadings (H2/H3) breaking content into scannable sections',
                    'Inline CTAs or contextual content upgrades relevant to the article topic',
                    'Internal links to related services or product pages',
                    'Email capture or lead magnet (checklist, guide, free tool)',
                    'Social sharing options',
                ],
                'scoring_emphasis' => 'readability_score is the primary metric — content must be easy to consume or visitors will bounce. clarity_score reflects whether the article delivers on its headline promise. trust_score should credit author credentials and freshness signals. cta_strength should be evaluated for in-content CTAs, not just a single page-level button — an article with no inline CTAs should score cta_strength below 40 regardless of page-level CTAs.'
            );
        }

        // Testimonials/Reviews page
        if (preg_match('/testimonial|reviews|success stories|case studies|clients/i', $title_lower) ||
        preg_match('/testimonial|reviews|case-studies/i', $url_lower)) {
            return array(
                'type' => 'Testimonials/Social Proof Page',
                'context' => 'Social proof pages validate your claims and build trust. They overcome skepticism.',
                'conversion_goal' => 'Showcase credible testimonials and results, build trust through social proof, guide visitors to take action',
                'expected_elements' => [
                    'Full testimonials with first name and last name (not just "John D." or "A happy customer")',
                    'Headshot photo for each reviewer',
                    'Company name and job title for each reviewer',
                    'Specific result or outcome mentioned in each testimonial',
                    'Video testimonials if available',
                    'Third-party review platform badges or links (Google, Trustpilot, etc.)',
                    'CTA positioned after the testimonials section',
                ],
                'scoring_emphasis' => 'trust_score is the only metric that carries primary weight on this page — it exists solely to build credibility. Score rigorously: anonymous or first-name-only testimonials should cap trust_score at 50. Named testimonials with photo, company, and specific results can reach 80+. Video testimonials push toward 90+. Flag absence of a CTA after proof as a missed conversion opportunity.'
            );
        }

        // Gallery/Portfolio page
        if (preg_match('/gallery|portfolio|our work|projects/i', $title_lower) ||
        preg_match('/gallery|portfolio|projects/i', $url_lower)) {
            return array(
                'type' => 'Gallery/Portfolio Page',
                'context' => 'Visual showcases demonstrate quality and capability. They should inspire confidence.',
                'conversion_goal' => 'Showcase quality of work, demonstrate capabilities, provide context for projects, guide to inquiry or booking',
                'expected_elements' => [
                    'Project title and brief description for each piece',
                    'Client name or industry context for each project',
                    'Before/after or process images where applicable',
                    'Quantifiable results or outcomes (e.g., "30% increase in organic traffic")',
                    'CTA to request similar work or get a quote',
                    'Filter or category navigation for portfolios with more than 6 projects',
                    'Testimonial or case study link associated with each project',
                ],
                'scoring_emphasis' => 'engagement_score and trust_score are the primary metrics — visitors are visually evaluating quality and deciding whether this business can deliver what they need. clarity_score reflects whether each project clearly communicates what was done, for whom, and with what result. cta_strength should be evaluated for project-level CTAs, not just a single page CTA; missing per-project CTAs are a key conversion gap.'
            );
        }

        // Default for unidentified pages
        return array(
            'type' => 'Standard Page',
            'context' => 'This page supports the overall customer journey and should align with its specific purpose in the conversion funnel.',
            'conversion_goal' => 'Guide visitors toward the primary business goal while serving the specific purpose of this page',
            'expected_elements' => [
                'Clear page headline that states the purpose of the page',
                'CTA aligned to the page\'s logical next step in the visitor journey',
                'Trust signals relevant to the topic or audience',
                'Internal links to related content, services, or contact page',
            ],
            'scoring_emphasis' => 'All scoring dimensions apply equally. Assess whether the page has a clear purpose, communicates it effectively, and guides visitors to a logical next step. Flag any page with no discernible CTA or purpose as a critical clarity gap.'
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

LANGUAGE RULE:
- Write all ANALYSIS and EXPLANATION output in English: scores, suggestions, functionality_suggestions, cro_checklist, insights, recommendations, and every \"why\" / \"explanation\" / \"score_impact\" field.
- Write all CUSTOMER-FACING COPY in the SAME language as the page content being audited — specifically the \"original\" and \"rewrite\" fields of every item in the \"rewrites\" array. Detect the language from the page CONTENT. If the page is in Spanish, these fields are in Spanish; if French, French; and so on. The \"rewrite\" is published verbatim onto the live page, so it MUST match the page's language, locale, spelling, and tone. NEVER translate the suggested copy into English.

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

GROUNDING RULES (STRICT — anti-hallucination; violating these makes the audit untrustworthy):
- The HTML STRUCTURE now includes the site's REAL header/nav and footer markup. If a header/nav CTA button exists on the site, the \"Sticky CTA in Nav\" signal will report YES and/or the button will be visible in the screenshot. NEVER report the navigation as \"missing a button\" or suggest \"add a nav/header CTA\" when a header/nav CTA is present in the signals or screenshot.
- NEVER state an element is missing — and NEVER recommend adding it — unless you have POSITIVE evidence it is absent from BOTH the screenshot AND the HTML/Browser signals. Before writing any \"add X\" / \"no X\" / \"missing X\" statement, verify X is genuinely not present. If the button text already contains a word like \"Free\" (e.g. \"Get My Free Estimate\"), do NOT claim the page lacks a free/no-cost reassurance.
- Absence of evidence is NOT evidence of absence. If you cannot verify a VISUAL item either way (no screenshot AND no HTML/browser signal), set \"explanation\" to state it could not be verified from the available data, keep priority \"low\", and do NOT create a suggestion telling the user to add it.
- Every item in \"suggestions\" MUST name a specific element, section, or copy string that ACTUALLY appears in CONTENT, the screenshot, or the signals. Do NOT emit generic best-practice advice that is not tied to something observable on THIS page.

Elements to evaluate:
1. CTA Above the Fold — Is there a call-to-action button visible without scrolling? [VISUAL — use screenshot]
2. Trust Signals (Certs, Awards) — Are there visible trust elements on the page? This includes: certification/award badges, credential logos, client logo bars ('as seen in' / 'trusted by' sections), case study thumbnails or previews, previous work / portfolio showcase sections, and media/partner logo strips. ANY of these count. [VISUAL — these elements are image-only and invisible in HTML text; use screenshot. If the HTML signal says LIKELY (case study, logo bar, or portfolio section detected), treat that as strong supporting evidence and only mark absent if neither the screenshot nor HTML confirm anything.]
3. Inline Social Proof — Are there testimonials, review widgets, star ratings, or headshot photos within the body? [VISUAL — rendered star-rating widgets may not appear in HTML; use screenshot]
4. Urgency / Scarcity Elements — Is there urgency or scarcity language (limited time, limited spots, countdown)? [COPY — use HTML/text]
5. Sticky CTA in Nav — Is there a persistent CTA button in the navigation bar that remains visible while scrolling? [PRIMARILY HTML/BROWSER — IMPORTANT: a screenshot captures only the initial page state BEFORE the user scrolls. A sticky nav that collapses on load or only becomes fixed after scrolling will NOT appear in the screenshot. Therefore: (a) if the HTML signal says YES or LIKELY (CTA in nav/header or sticky/fixed nav detected), set present=true even if the nav button is absent from the screenshot; (b) if a [BROWSER-CONFIRMED] nav_cta signal is present, that is ground-truth — set present=true; (c) use the screenshot only as supplementary confirmation, never as the sole reason to mark this absent.]
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
- rewrites: MINIMUM 8 objects, and produce ONE for EVERY distinct copy-bearing section that appears in CONTENT — do not stop at a fixed number. Content-rich pages commonly have 12–15 rewriteable sections; cover them ALL. You MUST scan the entire page top to bottom and include, wherever present: hero headline, hero subheadline/supporting paragraph, announcement/rating text, EVERY section heading (H2/H3) AND its intro paragraph, problem lists and solution lists (each list's heading), stat/metric labels, feature descriptions, every step's title and description in a process/how-it-works block, testimonials heading and testimonial quotes, the about/mission heading and its paragraph, and EVERY CTA button or secondary link — including section-level CTAs that repeat down the page. Do NOT limit yourself to the hero + one or two canonical sections; middle-of-page sections (problem/solution blocks, stat bars, multi-step widgets) are frequently the ones most in need of a rewrite. Each object rewrites a copy element that ACTUALLY EXISTS in CONTENT — do NOT invent sections. Quote the real current text verbatim in \"original\". Write \"rewrite\" in the company's industry-specific voice and tone, calibrated to their product, target audience, and pain points. Never produce generic, template-sounding copy. Rules:
  • \"original\" must be a direct quote (or close paraphrase) of the actual text on the page — never a placeholder
  • \"rewrite\" must read as if a senior copywriter wrote it specifically for this business and audience — concrete, benefit-led, no buzzwords
  • \"why\" must reference a specific conversion principle or score gap (e.g. \"Adds specificity that lifts clarity_score — the current copy doesn't explain the outcome\")
  • \"score_impact\" lists which 1–2 scores this change primarily improves
  • \"section\" must name the page's ACTUAL section — its real heading text or an accurate description of that block. The section names in the OUTPUT FORMAT example below are ILLUSTRATIVE ONLY; do NOT force generic labels onto sections that do not exist on this page
  • Write \"original\" and \"rewrite\" in the SAME language as the page content; only \"why\" and \"score_impact\" are in English

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
  \"rewrites\": [
    {
      \"section\": \"Hero Headline\",
      \"original\": \"Exact current headline text from the page\",
      \"rewrite\": \"Sharper, benefit-driven alternative written in the company's voice and calibrated to the target audience's specific pain point\",
      \"why\": \"The current headline names the service but not the outcome — this version leads with the specific result the audience wants, directly addressing the clarity_score gap\",
      \"score_impact\": \"clarity, emotional\"
    },
    {
      \"section\": \"Primary CTA\",
      \"original\": \"Exact CTA text from the page\",
      \"rewrite\": \"Stronger CTA that reduces friction and states what happens next\",
      \"why\": \"Action-oriented CTAs with a concrete next step outperform generic labels — directly improves cta_strength\",
      \"score_impact\": \"cta_strength\"
    }
  ],
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
  \"lead_intelligence_summary\": null,
  \"audience_fit_analysis\": null,
  \"ai_used\": true
}

NOTE: Only include \"lead_intelligence_summary\" and \"audience_fit_analysis\" if a VISITOR INTELLIGENCE
section appears in the user prompt. If no such section is present, omit both keys entirely.
";
    }

    /**
     * Build the user prompt — page content, business context, lead intelligence.
     * This changes for every audit.
     */
    private static function build_user_prompt($title, $content, $url, $word_count, $html_structure, $business, $screenshot_url = null, $sprint_context = '', $gsc_page_queries = null)
    {
        $industry = isset($business['industry']) ? $business['industry'] : 'Not specified';
        $product = isset($business['product']) ? $business['product'] : 'Not specified';
        $audience = isset($business['audience']) ? $business['audience'] : 'Not specified';
        $pain_points = isset($business['pain_points']) ? $business['pain_points'] : 'Not specified';
        $goal = isset($business['goal']) ? $business['goal'] : 'Not specified';

        $page_type_info = self::detect_page_type($title, $url);
        $page_type = $page_type_info['type'];
        $conversion_goal = $page_type_info['conversion_goal'];
        $page_context = $page_type_info['context'];
        $scoring_emphasis = $page_type_info['scoring_emphasis'];
        $expected_elements = isset($page_type_info['expected_elements']) ? $page_type_info['expected_elements'] : [];

        ciq_log('🎯 Detected page type: ' . $page_type . ' | Conversion goal: ' . $conversion_goal);

        // Build page-type structural checklist block
        $page_type_block = "\nPAGE TYPE CONTEXT:\n{$page_context}\n";
        if (!empty($expected_elements)) {
            $page_type_block .= "\nEXPECTED STRUCTURAL ELEMENTS FOR A {$page_type}:\n";
            $page_type_block .= "Audit each element below. For every element that is absent, flag it in your weaknesses, suggestions, or quick_wins. Do NOT create new JSON fields for this — incorporate findings into the existing response structure.\n";
            foreach ($expected_elements as $element) {
                $page_type_block .= "  - {$element}\n";
            }
        }
        $page_type_block .= "\nSCORING EMPHASIS FOR THIS PAGE TYPE:\n{$scoring_emphasis}\n";

        // Get webhook statistics for lead intelligence context.
        // Only query KnockKnock data when the feature is enabled on this plan
        // AND an API key is actually configured — avoids unnecessary DB hits and
        // ensures audience_fit_analysis is never requested without real data.
        // NOTE: class_exists() MUST come first. The KnockKnock class is only require()'d
        // at plugin load when can('knockknock') was true then (conversion-iq.php). A mid-request
        // config sync can flip the flag on AFTER that, so the class may not be loaded even
        // though can('knockknock') is now true — referencing its ::OPT_KEY constant would fatal.
        $kk_enabled    = class_exists( 'ConversionIQ_KnockKnock_API' )
                         && ConversionIQ_Config_Manager::can('knockknock')
                         && ! empty( get_option( ConversionIQ_KnockKnock_API::OPT_KEY, '' ) );
        $webhook_stats = $kk_enabled ? self::get_webhook_statistics($url) : null;
        $leads_context = '';

        if ($webhook_stats) {
            ciq_log('📊 Webhook stats loaded: ' . $webhook_stats['total_interactions'] . ' interactions, page visitors: ' . $webhook_stats['page_specific_visitors']);

            $page_visitors = $webhook_stats['page_specific_visitors'];
            $site_total    = (int)$webhook_stats['total_site_leads'] + (int)$webhook_stats['total_site_visitors'];

            $leads_context  = "\n\n════════════════════════════════════════════════════════\n";
            $leads_context .= "VISITOR INTELLIGENCE (KnockKnock real-visitor data)\n";
            $leads_context .= "════════════════════════════════════════════════════════\n";
            $leads_context .= "All figures below are from real identified visitors synced via the KnockKnock API.\n";
            $leads_context .= "RULE: Use ONLY these numbers in your output. Never fabricate or estimate visitor stats.\n\n";

            // ── Volume ─────────────────────────────────────────────────────────
            $leads_context .= "REACH\n";
            $leads_context .= "• Site-wide identified visitors (all pages, all time): {$site_total}\n";
            $leads_context .= "• Visitors on THIS specific page: {$page_visitors} ({$webhook_stats['site_contribution_pct']}% of site traffic)\n";
            $leads_context .= "• Peak engagement window: {$webhook_stats['peak_weekday']}s at {$webhook_stats['peak_hour']}:00\n\n";

            // ── Actual audience ───────────────────────────────────────────────
            $leads_context .= "ACTUAL AUDIENCE (who is really landing on this page)\n";
            if (!empty($webhook_stats['top_industries'])) {
                $leads_context .= "• Top industries: " . implode(', ', array_keys(array_slice($webhook_stats['top_industries'], 0, 5, true))) . "\n";
            }
            if (!empty($webhook_stats['top_job_titles'])) {
                $leads_context .= "• Top job titles: " . implode(', ', array_keys(array_slice($webhook_stats['top_job_titles'], 0, 5, true))) . "\n";
            }
            if (!empty($webhook_stats['top_locations'])) {
                $leads_context .= "• Top locations: " . implode(', ', array_keys(array_slice($webhook_stats['top_locations'], 0, 5, true))) . "\n";
            }

            // ── Decision-maker tier breakdown (with %) ────────────────────────
            if (!empty($webhook_stats['decision_maker_tiers'])) {
                $tier_parts = array();
                $tier_total = array_sum($webhook_stats['decision_maker_tiers']);
                foreach ($webhook_stats['decision_maker_tiers'] as $tier => $count) {
                    $pct          = $tier_total > 0 ? round(($count / $tier_total) * 100) : 0;
                    $tier_parts[] = "{$tier}: {$count} ({$pct}%)";
                }
                $leads_context .= "• Decision-maker tiers: " . implode(', ', $tier_parts) . "\n";
            }
            $leads_context .= "\n";

            // ── Company intelligence (full profiles) ──────────────────────────
            if (!empty($webhook_stats['company_intelligence'])) {
                $leads_context .= "COMPANY PROFILES (top companies with identified visitors)\n";
                foreach ($webhook_stats['company_intelligence'] as $co) {
                    $co_line  = "• {$co['company']}";
                    if (!empty($co['industry'])) $co_line .= " [{$co['industry']}]";
                    $co_line .= " — {$co['count']} visit(s)";
                    if (!empty($co['last_seen'])) {
                        $co_line .= ', last seen ' . date('M j Y', strtotime($co['last_seen']));
                    }
                    if (!empty($co['contacts'])) {
                        $contact_strs = array();
                        foreach ($co['contacts'] as $c) {
                            $parts = array_filter(array($c['name'], $c['title']));
                            if (!empty($parts)) $contact_strs[] = implode(', ', $parts);
                        }
                        if (!empty($contact_strs)) {
                            $co_line .= ' [contacts: ' . implode('; ', $contact_strs) . ']';
                        }
                    }
                    $leads_context .= $co_line . "\n";
                }
                $leads_context .= "\n";
            }

            // ── Audience-fit gap analysis ──────────────────────────────────────
            $leads_context .= "AUDIENCE FIT ANALYSIS REQUIRED\n";
            $leads_context .= "• Intended target audience (from business profile): {$audience}\n";
            $leads_context .= "• Actual visitor audience: see ACTUAL AUDIENCE section above\n";
            $leads_context .= "You MUST compare these two. Assess whether the real visitors match the intended audience.\n";
            $leads_context .= "Look for misalignments in seniority (e.g. targeting executives but getting individual contributors), ";
            $leads_context .= "industry (e.g. targeting B2B SaaS but getting agencies), or geography.\n";
            $leads_context .= "If well-matched, confirm alignment and explain WHY the content is resonating.\n";
            $leads_context .= "If misaligned, explain how the page messaging, headline, and CTA should adapt to either:\n";
            $leads_context .= "  (a) better convert the ACTUAL visitors arriving, or\n";
            $leads_context .= "  (b) filter them out and attract more of the INTENDED audience.\n";
            $leads_context .= "════════════════════════════════════════════════════════\n";
        }

        // Cap content length — 15 000 chars (~3 750 tokens) stays well within GPT-4o's
        // 128 K-token context window while covering even long-form pages in a single pass.
        if (strlen($content) > 15000) {
            $content = substr($content, 0, 15000) . '... [truncated]';
        }
        // Cap HTML structure — 6 000 chars accommodates the full 13-signal CRO summary
        // plus CPT review data without approaching any model limit.
        if (strlen($html_structure) > 6000) {
            $html_structure = substr($html_structure, 0, 6000) . '... [truncated]';
        }

        // Build lead intelligence JSON fragment if data exists
        $lead_json_fragment = '';
        if ($webhook_stats) {
            $lead_json_fragment = '

VISITOR INTELLIGENCE OUTPUT REQUIREMENTS:
Because VISITOR INTELLIGENCE data was provided above, you MUST include the following two additional fields in your JSON output. These fields are displayed on the published report — write them with the quality and specificity of the rest of your analysis.

"lead_intelligence_summary": {
  "insight": "2-3 sentences. State what the real visitor data reveals about this page\'s reach and conversion performance. Reference actual numbers: page visitor count, site-wide total, peak time. Do NOT make generic statements — ground every sentence in the data above.",
  "recommendations": [
    "Specific action item grounded in the visitor patterns (e.g. if peak is Tuesday 14:00, say what to do with that)",
    "Specific action item grounded in top industries or job titles visiting",
    "Specific action item grounded in which companies visited and whether they converted"
  ]
},

"audience_fit_analysis": {
  "alignment": "<one of: matched | partial | misaligned>",
  "summary": "1-2 sentences comparing the INTENDED audience (from business profile) against the ACTUAL visitor industries, titles, and seniority tiers. Name both audiences explicitly.",
  "gap": "If alignment is partial or misaligned: describe the exact gap in concrete terms (e.g. \'Intended: C-suite SaaS buyers. Actual: 72% are Managers and Individual Contributors from marketing agencies\'). Empty string if alignment=matched.",
  "messaging_implication": "One specific, actionable sentence about how the headline, copy tone, or CTA should change based on the alignment finding — reference an actual element on THIS page.",
  "top_actual_segment": "The single dominant real visitor segment in plain English (e.g. \'Marketing Managers at mid-size agencies\')"
}';
        }

        // Screenshot availability notice — critical for honest visual scoring.
        // When no screenshot was captured, explicitly tell the model so it does not
        // fabricate visual observations for the visual-evidence CRO checklist items.
        $screenshot_notice = '';
        if ( $screenshot_url ) {
            $screenshot_notice = '';
        } else {
            $screenshot_notice = "\nSCREENSHOT NOTICE: No page screenshot is available for this audit. "
                . "However, the HTML structure data may include a 'Real Browser Signals' section containing "
                . "ground-truth observations from real visitor sessions collected by the JS tracker. "
                . "If that section is present, use it as primary evidence for visual CRO checklist items. "
                . "Where no browser signals exist for a visual item, you MUST NOT fabricate or guess visual observations. "
                . "For CRO checklist items 1 (CTA Above the Fold), 2 (Trust Signals), 3 (Inline Social Proof), "
                . "5 (Sticky CTA in Nav), 11 (Anchor Pricing), and 13 (Progress Indicators): "
                . "if no [BROWSER-CONFIRMED] signal exists for the item, set present=false unless HTML signals explicitly confirm presence, "
                . "and set explanation to a statement based solely on HTML evidence (e.g. \"Assessed from HTML signals only — no screenshot available\"). "
                . "Do not include screenshot-observation phrases in suggestions or insights.\n";
        }

        // ── Build GSC search intent context (injected when available) ─────────
        // This grounds copy rewrites in real, quantified search demand for this page.
        // When GSC data is available, the AI must use the actual query language.
        // When absent (GSC not connected), fall back silently — no placeholder text needed.
        $gsc_context = '';
        if ( ! empty( $gsc_page_queries ) && is_array( $gsc_page_queries ) ) {
            $gsc_context  = "\n════════════════════════════════════════════════════════\n";
            $gsc_context .= "SEARCH INTENT DATA (Google Search Console — real queries for THIS page)\n";
            $gsc_context .= "════════════════════════════════════════════════════════\n";
            $gsc_context .= "These are the exact search queries driving real traffic to this specific URL. ";
            $gsc_context .= "They represent PROVEN DEMAND — people searched these phrases and found this page.\n";
            $gsc_context .= "COPY REWRITE INSTRUCTION: All headline and hero copy rewrites MUST incorporate the language ";
            $gsc_context .= "of the highest-click queries naturally. The H1 rewrite in particular should reflect the #1 query. ";
            $gsc_context .= "Do NOT invent new keywords — use only the queries listed below.\n\n";
            $gsc_context .= "Top queries landing on this page (sorted by clicks, 90-day window):\n";
            foreach ( array_slice( $gsc_page_queries, 0, 10 ) as $i => $q ) {
                $gsc_context .= ( $i + 1 ) . '. "' . esc_html( $q['query'] ) . '"'
                    . ' — ' . $q['clicks'] . ' clicks'
                    . ', pos ' . $q['position']
                    . ', ' . $q['impressions'] . ' impressions';
                if ( $i === 0 ) $gsc_context .= ' ← HIGHEST INTENT — use this in H1/hero headline rewrite';
                $gsc_context .= "\n";
            }
            $gsc_context .= "\nRULE: The copy rewrites section (\"rewrites\") MUST contain a rewrite for the H1/hero headline "
                . "that incorporates the #1 query above. The \"why\" field for that rewrite must reference "
                . 'the specific query and its click volume (e.g., "340 real visitors searched this phrase").';
            $gsc_context .= "\n";
        }
        // ── End GSC context ───────────────────────────────────────────────────

        $prompt = "Analyze this {$page_type} page for conversion optimization.

BUSINESS CONTEXT:
- Industry: {$industry}
- Product/Service: {$product}
- Target Audience: {$audience}
- Pain Points: {$pain_points}
- Conversion Goal: {$goal}
- Page Conversion Goal: {$conversion_goal}
{$page_type_block}{$screenshot_notice}
PAGE: {$title} ({$word_count} words)

CONTENT (structural markers: [H1]/[H2]/[H3]/[H4] = headings, [BUTTON] = button label, [CTA] = call-to-action link, --- = section boundary. Use these to identify copy sections accurately):
{$content}

HTML STRUCTURE:
{$html_structure}{$leads_context}
{$gsc_context}
Score this page using the rubric from your instructions. Apply the SCORING EMPHASIS above when calibrating scores — it overrides generic rubric defaults for this specific page type. Audit every EXPECTED STRUCTURAL ELEMENT listed above and incorporate missing-element findings into your weaknesses, suggestions, or quick_wins. Provide all suggestions referencing SPECIFIC page elements. Connect recommendations to actual weaknesses (cite scores). Compute overall_score using the weights specified.

COPY REWRITE RULES — apply these when generating the \"rewrites\" array:
1. IDENTIFY SECTIONS USING THE SCREENSHOT: If a screenshot is available, use it to identify the visual sections on the page (hero, features block, social proof, pricing, CTA section, etc.) and their reading order. Match those visual sections to the corresponding copy in CONTENT. Do NOT transcribe or quote text directly from the screenshot image — screenshots are for section identification only, not for verbatim quoting.
2. QUOTE THE ACTUAL TEXT: \"original\" must be lifted verbatim from the CONTENT above. Use the [H1]/[H2]/[H3]/[BUTTON]/[CTA] markers to locate specific copy elements — these indicate headings, button labels, and call-to-action links. Never invent or paraphrase placeholder text for the \"original\" field.
3. WRITE FOR THIS BUSINESS: the \"rewrite\" must sound like a senior copywriter who deeply understands the {$industry} industry and is writing specifically for {$audience}. Reference {$pain_points} where relevant. Avoid buzzwords, corporate speak, and generic phrases like \"quality solutions\" or \"tailored services\".
4. ONLY rewrite sections that exist on the page and where improvement is meaningful — skip sections where the current copy already scores well.
5. Each rewrite should be immediately usable — no brackets, no placeholders, no \"[Company Name]\" tokens.
6. Tone must match the rest of the page (professional, friendly, technical, etc.) while being sharper and more conversion-focused.
7. LANGUAGE: Detect the language of the CONTENT above and write BOTH \"original\" and \"rewrite\" in that exact language. The rewrite is published directly onto the live page, so it must never be in a different language than the surrounding copy (a Spanish page gets Spanish rewrites, not English). Keep \"why\"/\"score_impact\" in English.
8. SECTION LABELS: Name each rewrite's \"section\" after the page's ACTUAL section — the real heading text or an accurate short description of that block. Do NOT force generic/canonical labels onto sections that don't exist on this page; label only sections that genuinely appear in the CONTENT.{$lead_json_fragment}";

        if ( $sprint_context !== '' ) {
            $prompt .= $sprint_context;
        }

        // Authoritative, ordered section list (hero + next sections). When present it is
        // the source of truth for the rewrites array — the AI must not skip or invent.
        $prompt .= self::build_copy_inventory_block();

        return $prompt;
    }

    /**
     * Build the "AUTHORITATIVE COPY SECTIONS" block from the deterministic inventory.
     * Returns '' when no inventory is available (falls back to screenshot/CONTENT-based
     * discovery). Listing exact text + selectors here is what stops the model missing
     * sections and guarantees the hero heading / sub-heading / CTA are always covered.
     */
    private static function build_copy_inventory_block(): string {
        $inv = self::$copy_inventory;
        if ( empty( $inv ) || ! is_array( $inv ) ) return '';

        $lines = array();
        $n = 0;
        foreach ( $inv as $item ) {
            $text = trim( (string) ( $item['text'] ?? '' ) );
            if ( $text === '' ) continue;
            $n++;
            $sel = trim( (string) ( $item['selector'] ?? '' ) );
            $lines[] = $n . '. [' . ( $item['section_label'] ?? 'Section' ) . ']'
                . ( $sel !== '' ? ' (selector: ' . $sel . ')' : '' )
                . ' "' . str_replace( '"', "'", mb_substr( $text, 0, 300 ) ) . '"';
        }
        if ( empty( $lines ) ) return '';

        return "\n\n=== AUTHORITATIVE COPY SECTIONS (rewrite THESE) ===\n"
            . "These are the EXACT copy elements from the top of the page, in order (hero first). "
            . "Your \"rewrites\" array MUST be built from this list: return one object per section below "
            . "that can be improved, using the EXACT \"original\" text shown and the \"section\" label shown, "
            . "and set \"target\" to the selector shown. Do NOT invent, rename, merge, or skip sections, and "
            . "do NOT add sections that are not in this list. MANDATORY: always include the Hero Heading, "
            . "Hero Sub-heading, and Hero CTA. Follow the LANGUAGE rule (rewrite in the page's language).\n"
            . implode( "\n", $lines ) . "\n";
    }

    /**
     * Snap AI-returned rewrites to the deterministic inventory: overwrite \"original\"
     * with the exact stored text, attach the exact \"target\" selector, and use the
     * inventory's section label. Guarantees the applier's before-text matches and the
     * change targets the right element. Rewrites that don't match any inventory item are
     * kept as-is (so nothing is lost when the inventory is unavailable).
     */
    private static function reconcile_rewrites_with_inventory( array $rewrites ): array {
        $inv = self::$copy_inventory;
        if ( empty( $inv ) || ! is_array( $inv ) ) return $rewrites;

        // Index inventory by normalised text for matching.
        $by_text = array();
        foreach ( $inv as $item ) {
            $key = self::norm_text( (string) ( $item['text'] ?? '' ) );
            if ( $key !== '' && ! isset( $by_text[ $key ] ) ) $by_text[ $key ] = $item;
        }

        foreach ( $rewrites as &$rw ) {
            if ( ! is_array( $rw ) ) continue;
            $orig = self::norm_text( (string) ( $rw['original'] ?? '' ) );
            $match = null;
            if ( $orig !== '' && isset( $by_text[ $orig ] ) ) {
                $match = $by_text[ $orig ];
            } elseif ( $orig !== '' ) {
                // Fallback: substring containment either way (handles minor quoting drift).
                foreach ( $by_text as $k => $item ) {
                    if ( strpos( $k, $orig ) !== false || strpos( $orig, $k ) !== false ) { $match = $item; break; }
                }
            }
            if ( $match ) {
                $rw['original'] = $match['text'];                       // exact current copy
                $rw['section']  = $match['section_label'] ?? ( $rw['section'] ?? '' );
                if ( ! empty( $match['selector'] ) ) $rw['target'] = $match['selector'];
                if ( ! empty( $match['role'] ) )     $rw['role']   = $match['role'];
            }
        }
        unset( $rw );
        return $rewrites;
    }

    /** Lowercase + collapse whitespace + unify curly quotes for tolerant text matching. */
    private static function norm_text( string $s ): string {
        $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98", "\xC2\xB4", '`' ), "'", $s );
        $s = str_replace( array( "\xE2\x80\x9C", "\xE2\x80\x9D" ), '"', $s );
        $s = preg_replace( '/\s+/', ' ', trim( $s ) );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
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
     * Call the SaaS AI proxy (conversioniq-app.com/api/ai-proxy).
     * The WP plugin sends messages + license key; the SaaS adds the real AI API
     * key and forwards to the AI provider.  No AI key is ever stored on the WP site.
     */
    private static function call_abacus_ai($prompt, $system_prompt = null, $screenshot_url = null, $max_tokens = 4000)
    {
        $license_key = self::get_license_key();
        if ( empty( $license_key ) ) {
            return array( 'success' => false, 'error' => 'No license key — re-activate your license.' );
        }

        $messages = array();
        if ($system_prompt) {
            $messages[] = array('role' => 'system', 'content' => $system_prompt);
        }

        if ($screenshot_url) {
            // Multi-modal message: existing text analysis prompt + explicit visual instructions + screenshot.
            // The visual instruction block sits between the text and the image so the model reads
            // the scoring context immediately before looking at the image.
            $visual_instruction = "A full-page screenshot of this page is attached. This screenshot is AUTHORITATIVE for all visual CRO checklist items (1,2,3,5,7,11,13).\n\n"
                . "CRITICAL: CRO Structural Signals marked 'UNCONFIRMED FROM HTML' or 'NOT DETECTED' for visual items mean the HTML parser could not detect the element — NOT that it is absent. For visual items, base present=true/false SOLELY on what you directly observe in this screenshot:\n"
                . "- Item 1 (CTA Above the Fold): Is there a clearly styled button or CTA link visible without scrolling?\n"
                . "- Item 2 (Trust Signals): Look for ANY of the following — certification/award badge images, client logo bars, 'as seen in' / 'trusted by' logo strips, case study preview cards or thumbnails, previous work / portfolio section images, media/partner logo grids. Any one of these makes present=true. If the HTML signal says LIKELY (case study or logo bar detected), actively look for these in the screenshot before marking absent.\n"
                . "- Item 3 (Inline Social Proof): Are testimonial cards, review quotes, headshots, or star-rating widgets visible?\n"
                . "- Item 5 (Sticky CTA in Nav): Is there a button or CTA link in the navigation/header area? IMPORTANT — the screenshot only captures the initial page state before scrolling; a sticky nav that hides on load and appears on scroll will NOT be visible here. If the HTML signal says YES or LIKELY for this item, set present=true regardless of what the screenshot shows. Do NOT mark absent based solely on the screenshot.\n"
                . "- Item 7 (Clear Visual Hierarchy): Does the layout show clear heading weight, whitespace, and visual flow?\n\n"
                . "Additionally assess for scoring: CTA button colour contrast, size, and spacing (cta_strength); layout density and whitespace (readability_score); images/graphics richness (engagement_score).\n"
                . "Cite specific visual observations in suggestions (e.g. 'The screenshot shows the CTA button is below the fold'). Do not invent visual details.";

            $messages[] = array('role' => 'user', 'content' => array(
                array('type' => 'text', 'text' => $prompt),
                array('type' => 'text', 'text' => $visual_instruction),
                array('type' => 'image_url', 'image_url' => array(
                    'url'    => $screenshot_url,
                    'detail' => 'auto',  // 'auto' picks resolution; 'low' loses too much detail for CRO checks
                )),
            ));
        } else {
            $messages[] = array('role' => 'user', 'content' => $prompt);
        }

        $body = array(
            'model'     => 'gpt-4o',
            'messages'  => $messages,
            'max_tokens'=> $max_tokens,
            'temperature'=> 0.1,
            'has_image' => ! empty( $screenshot_url ),  // tell SaaS to keep a vision-capable model
        );

        $submit_args = array(
            'headers' => array(
                'X-License-Key' => $license_key,
                'Content-Type'  => 'application/json',
            ),
            'body'      => wp_json_encode($body),
            'timeout'   => 15,
            'sslverify' => true,
        );

        ciq_log('🚀 Calling SaaS AI proxy (async)...');
        ciq_log('📏 Prompt length: ' . strlen($prompt) . ' chars');

        // ── Step 1: Submit job ────────────────────────────────────────────────
        $submit_response = wp_remote_post(self::SAAS_API_URL . '/api/ai-proxy', $submit_args);

        if (is_wp_error($submit_response)) {
            $error_msg = $submit_response->get_error_message();
            $error_code = $submit_response->get_error_code();
            ciq_log('❌ SaaS AI proxy WP_Error: ' . $error_msg);
            ciq_log('❌ Error code: ' . $error_code);
            ciq_log('❌ Error type: Network/Connection issue');
            return array('success' => false, 'error' => 'API connection failed: ' . $error_msg . ' (code: ' . $error_code . ')');
        }

        $submit_status = wp_remote_retrieve_response_code($submit_response);
        ciq_log("📡 Submit response status: {$submit_status}");

        if ($submit_status !== 200) {
            $submit_body = wp_remote_retrieve_body($submit_response);
            ciq_log("❌ SaaS AI proxy HTTP error on submit: {$submit_status}");
            ciq_log("❌ Response body: " . substr($submit_body, 0, 500));
            return array('success' => false, 'error' => "API returned HTTP {$submit_status}: " . substr($submit_body, 0, 200));
        }

        $submit_data = json_decode(wp_remote_retrieve_body($submit_response), true);
        $job_id = isset($submit_data['job_id']) ? $submit_data['job_id'] : null;

        if (!$job_id) {
            ciq_log('❌ No job_id in AI proxy submit response: ' . substr(wp_remote_retrieve_body($submit_response), 0, 200));
            return array('success' => false, 'error' => 'No job_id returned from AI proxy');
        }

        ciq_log("⏳ Job queued: {$job_id} — polling for result...");

        // ── Step 2: Poll for result (smart backoff via estimated_wait_ms) ──────
        $poll_url   = self::SAAS_API_URL . '/api/ai-proxy/result/' . rawurlencode($job_id);
        $poll_args  = array(
            'headers'   => array( 'X-License-Key' => $license_key ),
            'timeout'   => 10,
            'sslverify' => true,
        );
        $data       = null;
        $max_polls  = 36;

        for ($attempt = 1; $attempt <= $max_polls; $attempt++) {
            // Default 5s, but use estimated_wait_ms from the previous pending response if available.
            // Clamp between 3s and 15s to avoid hammering or waiting too long.
            sleep( isset($next_sleep) ? $next_sleep : 5 );
            unset($next_sleep);

            ciq_log("🔄 Poll attempt {$attempt}/{$max_polls}...");

            $poll_response = wp_remote_get($poll_url, $poll_args);

            if (is_wp_error($poll_response)) {
                ciq_log('⚠️ Poll WP_Error: ' . $poll_response->get_error_message());
                continue;
            }

            $poll_data  = json_decode(wp_remote_retrieve_body($poll_response), true);
            $job_status = isset($poll_data['status']) ? $poll_data['status'] : 'unknown';
            ciq_log("📊 Poll status: {$job_status}");

            if ($job_status === 'complete') {
                $data = $poll_data;
                ciq_log("✅ Job complete on poll attempt {$attempt}");

                // Log SaaS-side timing for diagnostics
                if (isset($data['_meta'])) {
                    $m = $data['_meta'];
                    ciq_log("⏱ SaaS timing — total:{$m['total_ms']}ms queue:{$m['queue_ms']}ms processing:{$m['processing_ms']}ms");
                }
                break;
            }

            if ($job_status === 'failed') {
                $err = isset($poll_data['error']) ? $poll_data['error'] : 'Unknown error';
                ciq_log("❌ AI job failed: {$err}");
                return array('success' => false, 'error' => "AI job failed: {$err}");
            }

            if ($job_status === 'not_found') {
                ciq_log("❌ AI job not found or expired: {$job_id}");
                return array('success' => false, 'error' => 'AI job expired or not found');
            }

            // status === 'pending' — use estimated_wait_ms to set smart next sleep
            if (isset($poll_data['estimated_wait_ms']) && $poll_data['estimated_wait_ms'] > 0) {
                $eta_s       = (int) round($poll_data['estimated_wait_ms'] / 1000);
                $next_sleep  = max(3, min(15, $eta_s));
                $queue_pos   = isset($poll_data['queue_position']) ? $poll_data['queue_position'] : '?';
                ciq_log("⏳ Queue pos:{$queue_pos} ETA:{$poll_data['estimated_wait_ms']}ms — sleeping {$next_sleep}s");
            }
        }

        if (!$data) {
            ciq_log("❌ AI job did not complete after {$max_polls} poll attempts (" . ($max_polls * 5) . "s) — job_id: {$job_id}");
            return array('success' => false, 'error' => 'AI job timed out after polling ' . $max_polls . ' times');
        }

        return self::parse_abacus_response($data);
    }

    /**
     * Parse a completed AI proxy poll response into a structured result array.
     * Shared by call_abacus_ai() and the batch poll path in analyze_chunked().
     *
     * @param array $data  Decoded JSON from a "complete" poll response.
     * @return array       array('success' => true/false, 'data' => [...], 'error' => '...')
     */
    private static function parse_abacus_response($data)
    {
        if (!isset($data['choices'][0]['message']['content'])) {
            ciq_log('⚠️ No content in completed AI job response');
            ciq_log('⚠️ Response keys: ' . json_encode(array_keys($data)));
            return array('success' => false, 'error' => 'Empty AI response in completed job');
        }

        $content = $data['choices'][0]['message']['content'];

        if ( empty( trim( $content ) ) ) {
            ciq_log( '⚠️ AI job completed but returned empty content (job may have produced no tokens)' );
            ciq_log( '⚠️ Full poll data keys: ' . json_encode( array_keys( $data ) ) );
            if ( isset( $data['_meta'] ) ) {
                ciq_log( '⚠️ _meta: ' . json_encode( $data['_meta'] ) );
            }
            return array( 'success' => false, 'error' => 'AI job completed with empty response — model produced no output' );
        }

        ciq_log('📄 AI Response length: ' . strlen($content) . ' characters');
        ciq_log('📄 First 500 chars of response: ' . substr($content, 0, 500));

        $content = trim($content);

        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $content = $matches[1];
            ciq_log('✂️ Removed JSON markdown wrapper');
        } elseif (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
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

        $required_fields = array('clarity_score', 'emotional_score', 'cta_strength', 'readability_score', 'engagement_score', 'trust_score');
        $missing_fields  = array();
        foreach ($required_fields as $field) {
            if (!isset($parsed[$field])) $missing_fields[] = $field;
        }

        if (!empty($missing_fields)) {
            ciq_log('⚠️ AI response missing required fields: ' . implode(', ', $missing_fields));
            ciq_log('AI response structure: ' . json_encode(array_keys($parsed)));

            if (in_array('trust_score', $missing_fields) && isset($parsed['suggestions'])) {
                $extracted_trust_score = self::extract_trust_score_from_text($parsed);
                if ($extracted_trust_score !== null) {
                    $parsed['trust_score'] = $extracted_trust_score;
                    ciq_log('✅ Trust score extracted from suggestion text: ' . $extracted_trust_score);
                }
            }

            ciq_log('Full AI response: ' . json_encode($parsed));
        }

        if (isset($parsed['suggestions']) && !is_array($parsed['suggestions'])) {
            ciq_log('⚠️ Suggestions is not an array, converting...');
            $parsed['suggestions'] = array(array('text' => $parsed['suggestions'], 'section' => 'General'));
        }

        ciq_log('✅ AI response parsed successfully (suggestions: ' . (isset($parsed['suggestions']) ? count($parsed['suggestions']) : 0) . ')');

        $parsed['overall_score'] = (int) round(
            ($parsed['clarity_score']    ?? 0) * 0.20 +
            ($parsed['emotional_score']  ?? 0) * 0.15 +
            ($parsed['cta_strength']     ?? 0) * 0.20 +
            ($parsed['readability_score']?? 0) * 0.15 +
            ($parsed['engagement_score'] ?? 0) * 0.15 +
            ($parsed['trust_score']      ?? 0) * 0.15
        );

        ciq_log('✅ Returning success=true with data (overall_score: ' . $parsed['overall_score'] . ')');
        ciq_log('🔍 audience_fit_analysis in AI response: ' . (isset($parsed['audience_fit_analysis']) ? json_encode($parsed['audience_fit_analysis']) : 'NOT PRESENT'));
        ciq_log('🔍 lead_intelligence_summary in AI response: ' . (isset($parsed['lead_intelligence_summary']) ? 'present' : 'NOT PRESENT'));
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
                    'text' => 'The audit could not be completed using AI. This may be due to API connectivity issues or an inactive license.',
                    'section' => 'Technical',
                    'why' => 'AI integration is required for personalized recommendations.',
                    'impact' => 'Cannot generate custom suggestions for your business',
                    'implementation' => 'Verify your license is active at conversioniq-app.com and check network connectivity'
                )
            ),
            'lead_intelligence_summary' => null,
            'audience_fit_analysis'     => null,
            'functionality_suggestions' => array(
                    array(
                    'title' => 'Fix AI Integration',
                    'description' => 'The AI proxy is not responding. Verify your license is active at conversioniq-app.com and check server logs.',
                    'reasoning' => 'AI analysis failed — unable to provide personalized recommendations',
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
                        'impact' => 'Identifies the root cause of AI proxy issues',
                        'difficulty' => 'Easy'
                    )
                ),
                'long_term' => array(
                        array(
                        'text' => 'Verify your license is active at conversioniq-app.com',
                        'why' => 'A valid license is required to use the AI analysis proxy',
                        'impact' => 'Enables full AI functionality and personalized recommendations',
                        'difficulty' => 'Easy',
                        'timeframe' => '30 minutes'
                    )
                ),
                'priority' => array(
                    'text' => 'Fix AI integration to get real audit data',
                    'why' => 'Without AI analysis, you are only seeing fallback scores that do not reflect your actual page content or business context',
                    'impact' => 'Full access to personalized conversion insights and recommendations',
                    'next_steps' => '1. Check debug.log for error messages, 2. Verify your license is active at conversioniq-app.com, 3. Test API connectivity, 4. Re-run audit'
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
