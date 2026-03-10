<?php
if (!defined('ABSPATH')) {
    exit;
}

class ConversionIQ_AI
{

    const ABACUS_API_URL = 'https://routellm.abacus.ai/v1/chat/completions';

    /**
     * Get API key from wp-config.php or fallback to constant
     */
    private static function get_api_key()
    {
        // Prefer API key from wp-config.php for better security
        if (defined('CONVERSIONIQ_ABACUS_KEY')) {
            return CONVERSIONIQ_ABACUS_KEY;
        }
        // Fallback to hardcoded key (should be moved to wp-config.php)
        return 's2_7b1143d048014d04b7d489a17671b1a7';
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

        // Check if content is too long and needs chunking
        if (strlen($page_content) > 8000) {
            error_log('📚 Long content detected (' . strlen($page_content) . ' chars), using chunked analysis');
            return self::analyze_chunked($payload);
        }

        // Build the AI prompt
        $prompt = self::build_prompt($page_title, $page_content, $page_url, $word_count, $html_structure, $business);

        // Call Abacus.ai API
        $start_time = microtime(true);
        $ai_response = self::call_abacus_ai($prompt);
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
        error_log('🔍 AI Response Debug: ' . json_encode($debug_info));
        error_log('⏱️ AI call took: ' . $elapsed . ' seconds');

        if ($ai_response && isset($ai_response['success']) && $ai_response['success']) {
            error_log('✅ AI analysis successful, returning data');
            return $ai_response['data'];
        }

        // Log why we're falling back
        $error_reason = isset($ai_response['error']) ? $ai_response['error'] : 'Unknown error - response structure invalid';
        error_log('⚠️⚠️⚠️ FALLING BACK TO MOCK DATA - Reason: ' . $error_reason);
        error_log('📋 Full response: ' . json_encode($ai_response));

        // Fallback to mock response if AI fails
        return self::mock_response($page_title);
    }

    /**
     * Analyze long pages by splitting into sections
     */
    private static function analyze_chunked($payload)
    {
        $page_title = isset($payload['page']['title']) ? $payload['page']['title'] : 'Unknown Page';
        $content = isset($payload['page']['content']) ? $payload['page']['content'] : '';

        error_log('🔍 Starting chunked analysis for: ' . $page_title);

        $sections = self::split_into_sections($content);

        if (empty($sections)) {
            error_log('⚠️ Failed to split content into sections, falling back to truncated analysis');
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
            error_log("📄 Analyzing section {$current}/{$section_count}: {$section_name} (" . strlen($section_content) . " chars)");

            // Compress content if still too long
            $compressed = self::compress_content($section_content);

            // Update payload with section content
            $section_payload = $payload;
            $section_payload['page']['content'] = $compressed;
            $section_payload['page']['word_count'] = str_word_count($compressed);

            $prompt = self::build_prompt(
                $payload['page']['title'],
                $compressed,
                isset($payload['page']['url']) ? $payload['page']['url'] : '',
                str_word_count($compressed),
                isset($payload['page']['html_structure']) ? $payload['page']['html_structure'] : '',
                isset($payload['business']) ? $payload['business'] : array(),
                $section_name
            );

            $response = self::call_abacus_ai($prompt);

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

                error_log("✅ Section '{$section_name}' analyzed successfully");
            }
            else {
                error_log("⚠️ Section '{$section_name}' analysis failed");
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

        error_log('📑 Split content into ' . count($sections) . ' sections: ' . implode(', ', array_keys($sections)));

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

        error_log('🗜️ Compressing content from ' . strlen($content) . ' chars');

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

        error_log('🗜️ Compressed to ' . strlen($compressed) . ' chars');

        return $compressed;
    }

    /**
     * Aggregate results from multiple section analyses
     */
    private static function aggregate_section_results($all_scores, $all_suggestions, $all_functionality_suggestions, $original_payload)
    {
        if (empty($all_scores)) {
            error_log('⚠️ No scores to aggregate, using mock response');
            return self::mock_response(isset($original_payload['page']['title']) ? $original_payload['page']['title'] : 'Unknown Page');
        }

        error_log('🔢 Aggregating results from ' . count($all_scores) . ' sections');

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

        error_log('✅ Averaged scores calculated: clarity=' . $averaged['clarity_score'] . ', engagement=' . $averaged['engagement_score']);

        // Combine suggestions (limit to top 15 most impactful)
        $limited_suggestions = array_slice($all_suggestions, 0, 15);
        error_log('📝 Combined ' . count($all_suggestions) . ' suggestions, limited to ' . count($limited_suggestions));

        // Use first section's rewrites and insights (or merge them)
        $first_section = $all_scores[0];

        $result = array_merge($averaged, array(
            'suggestions' => $limited_suggestions,
            'functionality_suggestions' => $all_functionality_suggestions,
            'rewrites' => isset($first_section['rewrites']) ? $first_section['rewrites'] : array(),
            'insights' => isset($first_section['insights']) ? $first_section['insights'] : array(),
            'recommendations' => isset($first_section['recommendations']) ? $first_section['recommendations'] : array(),
            'ai_used' => true,
            'analysis_method' => 'chunked',
            'sections_analyzed' => count($all_scores)
        ));

        error_log('✅ Aggregation complete - returning chunked analysis results');

        return $result;
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
     * Build comprehensive prompt for AI analysis
     */
    private static function build_prompt($title, $content, $url, $word_count, $html_structure, $business, $section_name = null)
    {
        $industry = isset($business['industry']) ? $business['industry'] : 'Not specified';
        $product = isset($business['product']) ? $business['product'] : 'Not specified';
        $audience = isset($business['audience']) ? $business['audience'] : 'Not specified';
        $pain_points = isset($business['pain_points']) ? $business['pain_points'] : 'Not specified';
        $competitors = isset($business['competitors']) ? $business['competitors'] : 'Not specified';
        $goal = isset($business['goal']) ? $business['goal'] : 'Not specified';

        $page_type_info = self::detect_page_type($title, $url);
        $page_type = $page_type_info['type'];
        $page_context = $page_type_info['context'];
        $conversion_goal = $page_type_info['conversion_goal'];

        error_log('🎯 Detected page type: ' . $page_type . ' | Conversion goal: ' . $conversion_goal);

        // Process recent leads data
        $recent_leads = isset($business['recent_leads']) ? $business['recent_leads'] : array();
        $leads_context = '';

        if (!empty($recent_leads['page_specific_leads']) || !empty($recent_leads['site_wide_leads'])) {
            $leads_context .= "\n\n**Recent Lead Data (KnockKnock Webhooks):**\n";
            $leads_context .= "Use this data to analyze how well the page messaging aligns with actual converting visitors.\n";

            if (!empty($recent_leads['page_specific_leads'])) {
                $leads_context .= "- Page-Specific Leads (converted on this specific URL):\n";
                foreach ($recent_leads['page_specific_leads'] as $lead) {
                    $json_data = is_string($lead->data) ? $lead->data : wp_json_encode($lead->data);
                    $leads_context .= "  - " . $json_data . "\n";
                }
            }

            if (!empty($recent_leads['site_wide_leads'])) {
                $leads_context .= "- Site-Wide Leads (converted elsewhere on the site, for context):\n";
                foreach ($recent_leads['site_wide_leads'] as $lead) {
                    $json_data = is_string($lead->data) ? $lead->data : wp_json_encode($lead->data);
                    $leads_context .= "  - " . $json_data . "\n";
                }
            }

            $leads_context .= "\n**CRITICAL INSTRUCTIONS for Lead Data Analysis:**\n";
            $leads_context .= "1. Analyze the lead data to understand the actual demographics, interests, and pain points of converting visitors.\n";
            $leads_context .= "2. Compare this against the page content. Identify gaps where the messaging doesn't address the actual needs of the leads.\n";
            $leads_context .= "3. Provide a 'lead_intelligence_summary' in the JSON response detailing your findings and suggesting specific content alignments.\n";
        }


        // Section context for chunked analysis
        $section_context = '';
        if ($section_name) {
            $section_context = "\n**ANALYSIS CONTEXT:**\nThis is a SECTION of a larger page. You are analyzing the '{$section_name}' section specifically.\nFocus your analysis on this section's content and contribution to overall page conversion.\nProvide section-specific suggestions.\n";
            error_log('📍 Building prompt for section: ' . $section_name);
        }

        // Limit content length to prevent token overflow (max ~8000 chars for quality analysis)
        if (strlen($content) > 8000) {
            $content = substr($content, 0, 8000) . '... [content truncated]';
            error_log('⚠️ Content truncated to 8000 chars to fit token limit');
        }

        // Limit HTML structure to 2000 chars
        if (strlen($html_structure) > 2000) {
            $html_structure = substr($html_structure, 0, 2000) . '... [structure truncated]';
        }

        $prompt = "You are an expert conversion copywriter and UX analyst. Perform a comprehensive analysis of the following WordPress page.{$section_context}

**Business Context:**
- Industry: {$industry}
- Product/Service: {$product}
- Target Audience: {$audience}
- Customer Pain Points: {$pain_points}
- Key Competitors: {$competitors}
- Primary Business Goal: {$goal}

**Page Type & Context:**
- Page Type: {$page_type}
- Page Purpose: {$page_context}
- Specific Conversion Goal for This Page: {$conversion_goal}

**IMPORTANT - Page-Specific Analysis:**
This is a {$page_type} page. Your analysis MUST consider the unique conversion goals and user expectations for this page type:
{$page_context}

Evaluate all metrics (clarity, emotional resonance, CTA, readability, engagement, trust) specifically through the lens of this page type's conversion goals.

**CRITICAL - Clarity Score Scoring Guidelines:**
When evaluating clarity_score (0-100), use these specific criteria:

- **0-20**: No clear value proposition, confusing or missing headline, unclear what the business does or offers, visitors likely confused within 5 seconds
- **20-40**: Vague value proposition, generic headline (e.g., 'Welcome' or 'Quality Services'), requires reading multiple paragraphs to understand core offering, heavy use of industry jargon without explanation
- **40-60**: Basic value proposition present but not compelling, headline states what you do but not why it matters, moderate jargon usage, benefits mentioned but buried or unclear, some specificity but lacks focus
- **60-75**: Clear value proposition in headline, what you do and who it's for is obvious, benefits mentioned but could be more prominent, minimal jargon or jargon is explained, good specificity
- **75-85**: Strong value proposition prominently displayed, benefit-focused headline, crystal clear what you offer and why visitors should care, industry-specific but accessible language, features framed as benefits
- **85-100**: Exceptional clarity with benefit-driven headline that speaks directly to target pain points, unique value proposition immediately obvious, every section has clear purpose, zero ambiguity, perfect balance of specificity and accessibility

IMPORTANT: Pages with vague headlines like 'Welcome to Our Site' or 'Quality Services' should score 20-40, NOT 60+. Clarity requires immediate understanding of WHAT you offer, WHO it's for, and WHY it matters.

**CRITICAL - CTA Strength Scoring Guidelines:**
When evaluating cta_strength (0-100), use these specific criteria:

- **0-20**: No clear CTA present, or only generic links like 'Click Here' or 'Learn More', no visual prominence, no action orientation
- **20-40**: Weak CTAs present (e.g., 'Submit' or 'Learn More'), minimal visual contrast, unclear what happens next, multiple competing CTAs with no hierarchy, passive language
- **40-60**: Basic action-oriented CTAs (e.g., 'Get Started' or 'Contact Us'), some visual contrast, CTA present but not prominent, moderate specificity about next step, some urgency but not compelling
- **60-75**: Clear action-oriented CTAs with good visual prominence, specific about outcome (e.g., 'Get Your Free Quote'), strategic placement (above fold + end of page), decent contrast and sizing, some urgency or benefit reinforcement
- **75-85**: Strong CTAs with action verbs and benefit clarity (e.g., 'Start Saving Money Today'), excellent visual contrast and prominence, strategic multiple placements, urgency and value clear, limited friction (short forms), clear hierarchy between primary and secondary CTAs
- **85-100**: Exceptional CTAs with compelling action verbs + benefit + urgency (e.g., 'Get Your Free Audit - 24 Hour Results'), outstanding visual design with high contrast, perfect placement throughout user journey, micro-commitments for progressive engagement, zero friction, personalized to page context

IMPORTANT: Generic CTAs like 'Learn More', 'Submit', or 'Click Here' should score 20-40 maximum, NOT 60+. Strong CTAs require ACTION VERBS + SPECIFIC BENEFIT + URGENCY.

**CRITICAL - Readability Score Scoring Guidelines:**
When evaluating readability_score (0-100), use these specific criteria:

- **0-20**: Dense walls of text with no breaks, paragraphs over 150 words, no subheadings, sentences over 30 words consistently, tiny font sizes, poor contrast, no visual hierarchy
- **20-40**: Long paragraphs (100-150 words), minimal subheadings, complex sentence structure, poor formatting, limited white space, difficult to scan, small fonts or low contrast issues
- **40-60**: Moderate paragraph length (60-100 words), some subheadings present, mixed sentence complexity, basic formatting (bold/bullets used occasionally), adequate white space, somewhat scannable, readable fonts but room for improvement
- **60-75**: Good paragraph length (40-60 words), clear subheadings throughout, varied sentence length, good use of bullets and lists, ample white space, easy to scan, strong visual hierarchy, good font sizing and contrast
- **75-85**: Short, focused paragraphs (30-40 words), descriptive subheadings every 2-3 paragraphs, simple sentence structure, excellent use of formatting (bullets, bold, highlights), generous white space, highly scannable, perfect typography and contrast
- **85-100**: Exceptional readability with bite-sized content (20-30 word paragraphs), compelling subheadings that tell the story, simple language (8th grade level or below), strategic use of visuals to break up text, outstanding white space and visual flow, perfect typography hierarchy, mobile-optimized line length

IMPORTANT: Pages with paragraphs over 100 words or no subheadings should score below 50. Readability requires SHORT PARAGRAPHS + CLEAR SUBHEADINGS + SIMPLE SENTENCES + VISUAL HIERARCHY.

**CRITICAL - Emotional Score Scoring Guidelines:**
When evaluating emotional_score (0-100), use these specific criteria:

- **0-20**: Purely technical/factual language with no emotional appeal, no acknowledgment of customer pain points, completely generic copy that could apply to any business, no storytelling, sterile tone
- **20-40**: Minimal emotional connection, occasional mention of benefits but no pain point focus, very generic language ('we care about quality'), no storytelling or human element, mostly feature-focused rather than benefit-focused
- **40-60**: Some emotional language present, basic pain points mentioned but not explored deeply, generic power words used sparingly (e.g., 'great', 'best'), limited storytelling, mix of features and benefits, attempts connection but feels templated
- **60-75**: Clear pain point acknowledgment, good use of emotional language and power words, benefits prominently featured, some storytelling elements (customer success stories or problem/solution framing), empathetic tone, audience-specific language
- **75-85**: Strong emotional resonance with deep pain point understanding, compelling power words throughout (e.g., 'transform', 'breakthrough', 'finally'), effective storytelling that creates connection, aspirational language about outcomes, authenticity and empathy clear, speaks directly to audience struggles and desires
- **85-100**: Exceptional emotional engagement with masterful pain point articulation, powerful storytelling that resonates deeply, language that creates vivid before/after scenarios, aspirational future state painted clearly, authentic voice that builds trust, perfect balance of empathy and inspiration, audience feels truly understood

IMPORTANT: Pages that only list features without connecting to emotional benefits should score below 50. Emotional resonance requires PAIN POINT ACKNOWLEDGMENT + BENEFIT FOCUS + EMPATHETIC LANGUAGE + STORYTELLING.

**CRITICAL - Engagement Score Scoring Guidelines:**
When evaluating engagement_score (0-100), use these specific criteria:

- **0-20**: Static page with only text, no images or minimal stock photos, no interactive elements, no multimedia, no forms except basic contact, monotonous single-column layout, nothing to explore or interact with
- **20-40**: Basic images present (mostly stock photos), minimal interactivity (just a contact form), no multimedia (video/audio), simple layout with little visual variety, few reasons for users to engage beyond reading, no social proof or dynamic content
- **40-60**: Good image usage with some custom photography, basic interactivity (forms, clickable elements), limited multimedia (perhaps one video), some visual variety in layout, 1-2 engagement hooks (quiz, calculator, or chat), social proof present but static
- **60-75**: Strong visual design with custom imagery and graphics, multiple forms of interactivity (forms, accordions, tabs), multimedia present (videos, infographics), good layout variety, 2-3 engagement mechanisms, social proof integrated throughout, clear CTAs encourage exploration
- **75-85**: Excellent engagement with multiple interactive elements (calculators, quizzes, comparison tools, live chat), rich multimedia (multiple videos, animations, interactive demos), dynamic content, strong visual hierarchy with varied layouts, 3-4 clear engagement hooks, social proof widgets, personalized elements, mobile-optimized interactions
- **85-100**: Exceptional engagement with immersive experience (product configurators, AI chatbots, virtual tours, interactive assessments), comprehensive multimedia integration, gamification elements, dynamic personalization, 5+ distinct engagement points, real-time social proof, micro-interactions throughout, seamless omnichannel integration, progress indicators for multi-step processes

IMPORTANT: Pages with only static text and basic contact forms should score below 40. Engagement requires INTERACTIVE ELEMENTS + MULTIMEDIA + VISUAL VARIETY + MULTIPLE TOUCHPOINTS + DYNAMIC CONTENT.

**CRITICAL - Trust Score Scoring Guidelines:**
When evaluating trust_score (0-100), use these specific criteria:

- **0-20**: No social proof elements at all (no testimonials, reviews, trust badges, certifications, client logos, or credibility indicators)
- **20-40**: Minimal social proof (generic statements like 'trusted by thousands' without evidence, or very weak trust signals)
- **40-60**: Anonymous or partially attributed testimonials (quotes with job titles/company types but NO specific names or photos), OR limited trust badges
- **60-75**: Testimonials with SOME attribution (names OR photos, but not both), OR multiple trust badges/certifications displayed
- **75-85**: Full testimonials with names AND photos AND company names, OR strong combination of testimonials + trust badges + certifications
- **85-100**: Comprehensive trust architecture (multiple testimonials with full attribution + photos, client logos, certifications, security badges, case studies, reviews, media mentions)

IMPORTANT: Testimonials with job titles and company types (e.g., 'CEO, IT Company') but NO actual names or photos should score in the 40-60 range, NOT 0-20.

**Page Information:**
- Title: {$title}
- URL: {$url}
- Word Count: {$word_count} words

**Page Content:**
{$content}

**HTML Structure (for targeting specific elements):**
{$html_structure}

**Analysis Task:**
Analyze this page SPECIFICALLY in the context of:
1. The page type and its specific conversion goals (not just general business goals)
2. The business information and target audience
3. The actual page content (not generic advice)
4. Customer pain points and competitive positioning
5. How this page fits into the overall customer journey
6. The provided lead data (if any), to ensure messaging aligns with actual converting visitors
{$leads_context}

Your suggestions MUST be:
- Appropriate for a {$page_type} page
- Aligned with this page's conversion goal: {$conversion_goal}
- Based on actual page content (reference specific elements)
- Actionable and specific

**IMPORTANT for Page Sections:**
- Identify which specific section of the page each suggestion relates to
- Use clear section names like: 'Hero Section', 'About Section', 'Features Section', 'Testimonials Section', 'CTA Section', 'Pricing Section', 'FAQ Section', 'Footer'
- Be specific about what part of the page needs improvement
- Follow the natural flow and structure of the page sections

**CRITICAL INSTRUCTIONS for Additional Features & Functionality:**

Analyze the FULL CONTEXT of this specific business to recommend 4-6 features or integrations that would genuinely improve their conversion rates. 

**YOU MUST:**
1. Base recommendations ONLY on genuine needs identified from:
   - Specific audit score weaknesses (e.g., low trust score = 65 needs testimonials/reviews system)
   - Actual business goals and what's missing to achieve them
   - Target audience behavior and expectations for this industry
   - Page type and what's typically needed for that conversion goal
   - Gaps or missing elements you identified in the page content/structure

2. BE SPECIFIC about why this business needs each feature:
   - Reference specific audit scores (e.g., 'Your trust score of 62 indicates...')
   - Connect to their stated business goal (e.g., 'To achieve {$goal}, you currently lack...')
   - Explain what problem it solves for their specific audience
   - Point to a gap you found in the content analysis

3. DO NOT recommend generic features that apply to everyone
   - BAD: Live Chat Support - helps engage visitors in real-time
   - GOOD: Live Chat Support - Your service-based business with complex offerings (mentioned in business info) and low engagement score of 64 suggests visitors need immediate answers to proceed. Your target audience of {$audience} typically has questions before converting.

4. VARIETY MATTERS: 
   - Don't recommend the same 4-5 features for different businesses
   - Consider the unique combination of: page type, industry, audience, goals, and weaknesses
   - A B2B software company needs different features than a local restaurant or e-commerce store

5. ONLY recommend features that solve problems you actually found:
   - If trust score is high, don't suggest testimonials system
   - If they already have clear CTAs, don't suggest popup forms
   - If single-location local business, skip multi-language support
   - If simple product page, skip booking systems

**Common feature categories to consider (choose relevant ones only):**
E-Commerce Integration, Live Chat, Email Marketing, Social Media Integration, Blog/Content System, Lead Capture Forms, Booking/Appointment System, Testimonials/Reviews System, FAQ/Knowledge Base, Multi-Language Support, Analytics/Tracking Tools, Payment Gateways, Membership Systems, Search Functionality, Comparison Tools, Live Product Demos, Customer Portal, Chatbot/AI Assistant, Video Integration, Mobile App, Custom Calculators/Tools, Automated Follow-ups, Social Proof Widgets, Exit-Intent Technology

For EACH recommendation, write a detailed 'why' that includes:
- Reference to specific audit score(s)
- Connection to their business goal
- How it addresses their target audience's needs
- What gap or weakness it solves from your analysis

**CRITICAL INSTRUCTIONS for Recommendations & Suggestions:**

Every suggestion and recommendation MUST include:
1. **Why**: Clear reasoning explaining why this change matters for conversion
2. **Impact**: Specific metrics that will improve (clarity, trust, CTA strength, etc.)
3. **Implementation**: Practical guidance on how to implement this change
4. **Context**: Reference to audit findings, scores, or business goals that justify this recommendation

**CRITICAL - Score References Must Be Accurate:**
When referencing scores in your recommendations (e.g., 'Your trust score of X'), you MUST use the EXACT scores you assigned in the clarity_score, emotional_score, cta_strength, readability_score, engagement_score, and trust_score fields. Double-check that every score reference in your text matches the numeric scores you provide. Inconsistent scoring will confuse users.

Make recommendations DETAILED and ACTIONABLE:
- BAD: Add testimonials to build trust
- GOOD: Add testimonials from satisfied clients in a dedicated section below the hero
  - Why: Your trust score of 58 is significantly below the industry average of 72, indicating visitors need social proof before converting
  - Impact: Expected to increase trust score by 15-20 points and reduce bounce rate
  - Implementation: Contact 3-5 recent satisfied clients for testimonials, create a testimonials section with photos and full names, place above the pricing table

Prioritize recommendations by:
1. **Quick Wins**: High-impact, low-effort changes (1-2 days to implement)
2. **Long-term**: Strategic improvements requiring more time/resources
3. **Priority**: The ONE most critical change that addresses the biggest weakness

**CRITICAL INSTRUCTIONS for Quick Wins:**

Quick wins must be PAGE-SPECIFIC and immediately actionable - NOT generic industry advice:

- ❌ BAD (Generic): 'Add social proof' or 'Improve your CTA' or 'Optimize landing pages'
- ✅ GOOD (Page-Specific): 'Add 2-3 customer testimonials with photos above the Get Started button in your hero section - your trust score of 62 reflects this gap'

REQUIREMENTS for each Quick Win:
1. Reference SPECIFIC page elements (sections, headlines, CTAs, images)
2. Connect to ACTUAL weaknesses found in THIS page's analysis (cite scores)
3. Provide ACTIONABLE advice (what to change, where to change it)
4. Explain measurable impact specific to this page's gaps
5. Make sure each of the 3 quick wins addresses DIFFERENT aspects of the page

Examples of good quick wins:
- Replace your hero headline 'Welcome to Our Site' with a benefit-focused statement like 'Save 40% on Energy Costs with Smart Home Automation' - addresses your clarity score of 68
- Add security badges (SSL, BBB, industry certifications) below the contact form - your trust score of 61 indicates visitors need more credibility signals
- Split your 400-word paragraph in the benefits section into 3-4 shorter paragraphs with subheadings - readability score of 55 suggests content is too dense

**CRITICAL INSTRUCTIONS for Key Insights:**

The insights section is the FIRST thing clients read - make it valuable, specific, and actionable:

1. **Executive Summary**: Write a 2-3 sentence overview in client-facing language that:
   - Summarizes the overall conversion health based on audit scores
   - Highlights the #1 priority area that needs attention
   - Sets a positive, solution-focused tone (avoid being overly negative)
   - Example: Your page shows strong foundations with clear messaging (clarity: 78), but trust-building elements are your biggest opportunity. With a trust score of 58, adding social proof and testimonials could increase conversions by 20-30%. The good news: these are quick wins that can be implemented within a week.

2. **Strengths**: List 2-3 specific things this page does WELL:
   - Reference actual content you analyzed (not generic observations)
   - Connect to specific scores (e.g., Strong headline clarity 82/100)
   - Be specific about what's working
   - BAD: Good content quality
   - GOOD: Your feature descriptions effectively address customer pain points, creating strong emotional resonance

3. **Weaknesses**: Identify 2-3 critical gaps that hurt conversion:
   - Be constructive and solution-focused in tone
   - Reference specific audit scores that are below 70
   - Point to actual missing elements you identified
   - Frame in terms of missed opportunities, not just problems
   - BAD: Poor trust signals
   - GOOD: Missing testimonials and client logos (trust score: 58) - your competitors showcase social proof prominently, and adding this could boost conversions significantly

4. **Opportunities**: List 2-3 high-impact improvements presented positively:
   - Focus on what could be gained, not what's missing
   - Connect to business goals and expected outcomes
   - Make clients excited about the potential
   - BAD: Need better CTAs
   - GOOD: Strengthening your CTA from Learn More to action-driven copy could increase click-through rates by 15-25%

5. **Top Priority Insight**: A clear, client-friendly explanation of the #1 area to focus on:
   - Explain WHY this is the priority (reference the lowest score or biggest gap)
   - Explain the IMPACT of fixing it (expected conversion improvement)
   - Make it digestible for non-technical business owners
   - Set realistic timeframe and difficulty expectations
   - BAD: Improve trust score
   - GOOD: Your trust score of 58 is your biggest opportunity. For this industry targeting this audience, trust signals are critical - visitors need proof before converting. Adding client testimonials, trust badges, and case studies could lift your conversion rate by 25-30% within 2 weeks of implementation.

6. **Audience Alignment**: Specific analysis of how well the page speaks to {$audience}:
   - Reference actual language, tone, and messaging from the page
   - Identify gaps between current copy and audience expectations
   - Be specific about what resonates and what doesn't
   - BAD: Good audience fit
   - GOOD: Your messaging resonates well with the target audience, particularly the emphasis on their pain points. However, the technical jargon in the features section may alienate non-technical decision-makers.

**Required Output (JSON only, no markdown):**
{
  \"clarity_score\": [0-100],
  \"emotional_score\": [0-100],
  \"cta_strength\": [0-100],
  \"readability_score\": [0-100],
  \"engagement_score\": [0-100],
    \"suggestions\": [
        {
            \"text\": \"Specific, actionable suggestion based on page content and business context\",
            \"section\": \"Section name (e.g., 'Hero Section', 'Features Section', 'CTA Section')\",
            \"why\": \"Explain why this change is important for conversion - reference specific weaknesses or opportunities you identified\",
            \"impact\": \"Which metrics this will improve (e.g., 'Improves trust score and emotional resonance', 'Increases CTA strength and clarity')\",
            \"implementation\": \"Brief guidance on how to implement this (e.g., 'Add a testimonials widget in the sidebar', 'Replace current headline with suggested rewrite')\"
        }
    ],
    \"lead_intelligence_summary\": {
        \"overview\": \"High-level summary of lead demographics and behavior based on the provided KnockKnock webhook data.\",
        \"messaging_alignment\": \"How well the current page messaging aligns with the actual leads data. Are there gaps?\",
        \"audience_insights\": \"Specific insights derived from the leads (e.g., common titles, interests, or interactions discovered in the data).\",
        \"recommended_adjustments\": \"Specific content or structural changes to better capture similar leads based on the intel.\"
    },
    \"functionality_suggestions\": [
        {
            \"title\": \"Specific feature name that addresses an identified gap\",
            \"description\": \"What this feature does and how it works (2-3 sentences)\",
            \"why\": \"Detailed, specific explanation referencing: (1) specific audit score weaknesses, (2) how it helps achieve their business goal '{$goal}', (3) why their target audience '{$audience}' needs this, (4) what problem/gap from your analysis it solves. Be specific and reference actual findings from this audit.\",
            \"icon\": \"Single emoji representing the feature\"
        }
    ],
    \"rewrites\": {
        \"headline\": \"Improved headline for {$audience}\",
        \"subheadline\": \"Subheadline addressing {$pain_points}\",
        \"primary_cta\": \"Primary CTA button text aligned with {$goal}\",
        \"secondary_cta\": \"Secondary CTA if applicable\",
        \"value_proposition\": \"Clear value proposition statement\",
        \"social_proof_intro\": \"Introduction for testimonials/reviews section\",
        \"feature_1\": \"First key feature description\",
        \"feature_2\": \"Second key feature description\",
        \"feature_3\": \"Third key feature description\",
        \"faq_answer_1\": \"Improved answer to top FAQ\",
        \"closing_statement\": \"Final conversion-focused statement\"
    },
  \"insights\": {
    \"executive_summary\": \"2-3 sentence client-facing overview that summarizes conversion health, highlights #1 priority, and sets positive tone. Reference specific scores and expected improvement.\",
    \"strengths\": [
        \"Specific strength #1 with reference to actual page content and scores (e.g., 'Strong value proposition in hero section addresses {$audience}'s need for X, achieving clarity score of 85')\",
        \"Specific strength #2 connecting to business goals\"
    ],
    \"weaknesses\": [
        \"Constructive weakness #1 with specific score and what's missing (e.g., 'Trust score of 58 indicates missing social proof - no testimonials or client logos visible, unlike competitors')\",
        \"Constructive weakness #2 framed as missed opportunity\"
    ],
    \"opportunities\": [
        \"High-impact opportunity #1 presented positively with expected outcome (e.g., 'Strengthening CTAs could increase conversions by 15-25%')\",
        \"High-impact opportunity #2 tied to business goals\"
    ],
    \"top_priority_insight\": \"Client-friendly explanation of the #1 focus area: why it's the priority (reference lowest score), impact of fixing it (expected % improvement), realistic timeframe. Make it digestible for non-technical business owners.\",
    \"audience_alignment\": \"Specific analysis of how well page speaks to {$audience} - reference actual language, tone, and messaging from the page. Identify gaps between current copy and audience expectations with specific examples.\"
  },
  \"recommendations\": {
    \"quick_wins\": [
        {
            \"text\": \"Specific, actionable quick win tied to THIS PAGE's actual content (e.g., 'Add customer testimonials above the CTA button in the hero section' NOT generic advice like 'Improve social proof'). Reference specific sections, headlines, or CTAs from the page.\",
            \"why\": \"Why this specific change matters for THIS page - reference actual weaknesses found in the analysis (e.g., 'Your trust score of 62 reflects the absence of social proof in the hero section where visitors make their first impression')\",
            \"impact\": \"Expected measurable improvement specific to this page's weaknesses (e.g., 'Could increase trust score from 62 to 75-80' or 'Likely to reduce bounce rate by 15-20%')\",
            \"difficulty\": \"Easy\"
        },
        {
            \"text\": \"Second page-specific quick win - must be different from the first and reference different aspects of the page\",
            \"why\": \"Explain why based on this page's specific gaps\",
            \"impact\": \"Measurable impact prediction\",
            \"difficulty\": \"Easy\"
        },
        {
            \"text\": \"Third page-specific quick win\",
            \"why\": \"Explain based on page analysis\",
            \"impact\": \"Expected improvement\",
            \"difficulty\": \"Easy\"
        }
    ],
    \"long_term\": [
        {
            \"text\": \"Long-term strategic improvement\",
            \"why\": \"Why this requires more time/resources and its strategic value\",
            \"impact\": \"Expected long-term conversion improvement\",
            \"difficulty\": \"Medium\" or \"Hard\",
            \"timeframe\": \"Estimated implementation time (e.g., '2-4 weeks', '1-2 months')\"
        }
    ],
    \"priority\": {
        \"text\": \"Top priority recommendation that will have the biggest impact\",
        \"why\": \"Why this is the #1 priority - reference the most critical weakness or opportunity\",
        \"impact\": \"Expected conversion lift and which metrics will improve most\",
        \"next_steps\": \"Specific first steps to take (e.g., '1. Research competitor testimonials, 2. Reach out to satisfied clients, 3. Design testimonial section')\"
    }
  },
  \"ai_used\": true
}

CRITICAL: Return ONLY valid JSON. No markdown, no code blocks, no explanatory text. Provide specific section names for each suggestion.";

        return $prompt;
    }

    /**
     * Call Abacus.ai route-llm API
     */
    private static function call_abacus_ai($prompt)
    {
        $body = array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                    array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
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

        error_log('🚀 Calling Abacus.ai route-llm API...');
        error_log('📏 Prompt length: ' . strlen($prompt) . ' chars');

        $response = wp_remote_post(self::ABACUS_API_URL, $args);

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log('❌ Abacus.ai API WP_Error: ' . $error_msg);
            error_log('❌ Error code: ' . $error_code);
            error_log('❌ Error type: Network/Connection issue');
            return array('success' => false, 'error' => 'API connection failed: ' . $error_msg . ' (code: ' . $error_code . ')');
        }

        $status_code = wp_remote_retrieve_response_code($response);
        error_log("📡 Response status: {$status_code}");

        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            error_log("❌ Abacus.ai API HTTP error: {$status_code}");
            error_log("❌ Response headers: " . json_encode(wp_remote_retrieve_headers($response)));
            error_log("❌ Response body: " . substr($body, 0, 500));
            return array('success' => false, 'error' => "API returned HTTP {$status_code}: " . substr($body, 0, 200));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['choices'][0]['message']['content'])) {
            error_log('⚠️ No content in AI response');
            error_log('⚠️ Response structure: ' . json_encode(array_keys($data)));
            error_log('⚠️ Full response body: ' . substr($body, 0, 1000));
            return array('success' => false, 'error' => 'Empty AI response - check logs for details');
        }

        $content = $data['choices'][0]['message']['content'];
        error_log('📄 AI Response length: ' . strlen($content) . ' characters');
        error_log('📄 First 500 chars of response: ' . substr($content, 0, 500));

        // Try to parse JSON response
        $content = trim($content);

        // Remove markdown code blocks if present
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $content = $matches[1];
            error_log('✂️ Removed JSON markdown wrapper');
        }
        elseif (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            $content = $matches[1];
            error_log('✂️ Removed generic markdown wrapper');
        }

        error_log('🔍 Attempting to parse JSON (length: ' . strlen(trim($content)) . ')');
        $parsed = json_decode($content, true);

        if (!$parsed) {
            error_log('⚠️ Failed to parse AI response as JSON');
            error_log('JSON Error: ' . json_last_error_msg());
            error_log('Raw response (first 1000 chars): ' . substr($content, 0, 1000));
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
            error_log('⚠️ AI response missing required fields: ' . implode(', ', $missing_fields));
            error_log('AI response structure: ' . json_encode(array_keys($parsed)));
            error_log('Full AI response: ' . json_encode($parsed));
        // Still continue - these might be optional or have defaults
        }

        // Ensure suggestions is an array
        if (isset($parsed['suggestions']) && !is_array($parsed['suggestions'])) {
            error_log('⚠️ Suggestions is not an array, converting...');
            $parsed['suggestions'] = array(array('text' => $parsed['suggestions'], 'section' => 'General'));
        }

        error_log('✅ AI response parsed successfully (suggestions: ' . (isset($parsed['suggestions']) ? count($parsed['suggestions']) : 0) . ')');
        error_log('✅ Returning success=true with data');
        return array('success' => true, 'data' => $parsed);
    }

    /**
     * Fallback mock response if AI fails
     */
    private static function mock_response($title)
    {
        error_log('🔄 Returning fallback mock response for: ' . $title);
        return array(
            'clarity_score' => 70,
            'emotional_score' => 70,
            'cta_strength' => 70,
            'readability_score' => 75,
            'engagement_score' => 65,
            'trust_score' => 68,
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
            error_log('✅ Industry benchmark research successful for: ' . $industry);
            return $response['data'];
        }

        error_log('⚠️ Industry benchmark research failed, using fallback');
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
