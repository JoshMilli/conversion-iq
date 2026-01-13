<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ConversionIQ_AI {
    
    const ABACUS_API_URL = 'https://routellm.abacus.ai/v1/chat/completions';
    
    /**
     * Get API key from wp-config.php or fallback to constant
     */
    private static function get_api_key() {
        // Prefer API key from wp-config.php for better security
        if ( defined( 'CONVERSIONIQ_ABACUS_KEY' ) ) {
            return CONVERSIONIQ_ABACUS_KEY;
        }
        // Fallback to hardcoded key (should be moved to wp-config.php)
        return 's2_7b1143d048014d04b7d489a17671b1a7';
    }
    
    /**
     * Analyze page content using Abacus.ai route-llm
     */
    public static function analyze( $payload ) {
        $page_title = isset( $payload['page']['title'] ) ? $payload['page']['title'] : 'Unknown Page';
        $page_content = isset( $payload['page']['content'] ) ? $payload['page']['content'] : '';
        $page_url = isset( $payload['page']['url'] ) ? $payload['page']['url'] : '';
        $word_count = isset( $payload['page']['word_count'] ) ? $payload['page']['word_count'] : 0;
        $html_structure = isset( $payload['page']['html_structure'] ) ? $payload['page']['html_structure'] : '';
        $business = isset( $payload['business'] ) ? $payload['business'] : array();
        
        // Build the AI prompt
        $prompt = self::build_prompt( $page_title, $page_content, $page_url, $word_count, $html_structure, $business );
        
        // Call Abacus.ai API
        $start_time = microtime(true);
        $ai_response = self::call_abacus_ai( $prompt );
        $elapsed = round((microtime(true) - $start_time), 2);
        
        $debug_info = array(
            'elapsed_time' => $elapsed . 's',
            'is_array' => is_array( $ai_response ),
            'has_success_key' => isset( $ai_response['success'] ),
            'success_value' => isset( $ai_response['success'] ) ? ($ai_response['success'] ? 'TRUE' : 'FALSE') : 'MISSING',
            'has_data_key' => isset( $ai_response['data'] ),
            'has_error_key' => isset( $ai_response['error'] ),
            'error_value' => isset( $ai_response['error'] ) ? $ai_response['error'] : 'none',
        );
        error_log( '🔍 AI Response Debug: ' . json_encode( $debug_info ) );
        error_log( '⏱️ AI call took: ' . $elapsed . ' seconds' );
        
        if ( $ai_response && isset( $ai_response['success'] ) && $ai_response['success'] ) {
            error_log( '✅ AI analysis successful, returning data' );
            return $ai_response['data'];
        }
        
        // Log why we're falling back
        $error_reason = isset( $ai_response['error'] ) ? $ai_response['error'] : 'Unknown error - response structure invalid';
        error_log( '⚠️⚠️⚠️ FALLING BACK TO MOCK DATA - Reason: ' . $error_reason );
        error_log( '📋 Full response: ' . json_encode( $ai_response ) );
        
        // Fallback to mock response if AI fails
        return self::mock_response( $page_title );
    }
    
    /**
     * Build comprehensive prompt for AI analysis
     */
    private static function build_prompt( $title, $content, $url, $word_count, $html_structure, $business ) {
        $industry = isset( $business['industry'] ) ? $business['industry'] : 'Not specified';
        $product = isset( $business['product'] ) ? $business['product'] : 'Not specified';
        $audience = isset( $business['audience'] ) ? $business['audience'] : 'Not specified';
        $pain_points = isset( $business['pain_points'] ) ? $business['pain_points'] : 'Not specified';
        $competitors = isset( $business['competitors'] ) ? $business['competitors'] : 'Not specified';
        $goal = isset( $business['goal'] ) ? $business['goal'] : 'Not specified';
        
        // Limit content length to prevent token overflow (max ~8000 chars for quality analysis)
        if ( strlen( $content ) > 8000 ) {
            $content = substr( $content, 0, 8000 ) . '... [content truncated]';
            error_log( '⚠️ Content truncated to 8000 chars to fit token limit' );
        }
        
        // Limit HTML structure to 2000 chars
        if ( strlen( $html_structure ) > 2000 ) {
            $html_structure = substr( $html_structure, 0, 2000 ) . '... [structure truncated]';
        }
        
        $prompt = "You are an expert conversion copywriter and UX analyst. Perform a comprehensive analysis of the following WordPress page.

**Business Context:**
- Industry: {$industry}
- Product/Service: {$product}
- Target Audience: {$audience}
- Customer Pain Points: {$pain_points}
- Key Competitors: {$competitors}
- Primary Goal: {$goal}

**Page Information:**
- Title: {$title}
- URL: {$url}
- Word Count: {$word_count} words

**Page Content:**
{$content}

**HTML Structure (for targeting specific elements):**
{$html_structure}

**Analysis Task:**
Analyze this page SPECIFICALLY in the context of the business information provided above. Your suggestions MUST be:
1. Directly relevant to the actual page content (not generic advice)
2. Aligned with the stated business goals and target audience
3. Addressing the specific customer pain points mentioned
4. Competitive against the mentioned competitors
5. Actionable and specific (reference actual page elements and sections)

**IMPORTANT for Page Sections:**
- Identify which specific section of the page each suggestion relates to
- Use clear section names like: \"Hero Section\", \"About Section\", \"Features Section\", \"Testimonials Section\", \"CTA Section\", \"Pricing Section\", \"FAQ Section\", \"Footer\"
- Be specific about what part of the page needs improvement
- Follow the natural flow and structure of the page sections

**IMPORTANT for Functionality Suggestions:**
Based on the page analysis, audit scores, and business goals, recommend 4-6 specific features or integrations that would improve conversion rates. Choose from these common WordPress enhancements:
- E-Commerce/Webshop Integration (for selling products/services)
- Live Chat Support (for real-time customer engagement)
- Email Marketing Integration (Mailchimp, etc. for lead nurturing)
- Instagram/Social Media Feed (for social proof and trust)
- Blog System (for content marketing and SEO)
- Popup for lead capture and exit-intent 
- CRM Integration (for managing customer relationships)
- SEO Optimization (for better search rankings)
- Newsletter Signup (for building email lists)
- Multi-Language Support (for international audiences)
- Conversion Popups (for lead capture and exit-intent)
- Custom Sliders/Carousels (for showcasing multiple offerings)
- Booking/Appointment System (for service-based businesses)
- Membership/Login System (for restricted content)
- Custom Forms (for lead generation)
- Payment Gateway Integration (for accepting payments)
- Analytics Dashboard (for data-driven decisions)
- KnockKnock AI Visitor Intelligence (tracks visitor behavior in real-time, scores lead intent, engages hot prospects automatically with chat/voice, and connects high-intent leads to sales reps via instant video/phone calls - ideal for high-value B2B services, complex products, or businesses with active sales teams)

For each functionality suggestion, explain WHY this specific business needs it based on:
- Their audit scores (e.g., \"Your trust score of X suggests...\")
- Their business goals (e.g., \"To achieve {$goal}, you need...\")
- Their target audience needs
- Identified weaknesses or gaps in current implementation

**Required Output (JSON only, no markdown):**
{
  \"clarity_score\": [0-100],
  \"emotional_score\": [0-100],
  \"cta_strength\": [0-100],
  \"readability_score\": [0-100],
  \"engagement_score\": [0-100],
  \"trust_score\": [0-100],
    \"suggestions\": [
        {
            \"text\": \"Specific, actionable suggestion based on page content and business context\",
            \"section\": \"Section name (e.g., 'Hero Section', 'Features Section', 'CTA Section')\"
        }
    ],
    \"functionality_suggestions\": [
        {
            \"title\": \"Feature name (e.g., 'Live Chat Support', 'E-Commerce Integration', 'Multi-Language Support')\",
            \"description\": \"Brief description of what this feature does and how it works\",
            \"why\": \"Specific explanation of why this business needs this feature based on their audit scores, goals, and target audience. Reference specific weaknesses or opportunities from the analysis.\",
            \"icon\": \"Single emoji that represents this feature (e.g., 💬, 🛒, 🌍, 📧, 📝, 🔔, 📱, 🎨)\"
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
    \"strengths\": [\"Strength 1\", \"Strength 2\", \"Strength 3\"],
    \"weaknesses\": [\"Weakness 1\", \"Weakness 2\", \"Weakness 3\"],
    \"opportunities\": [\"Opportunity 1\", \"Opportunity 2\"],
    \"audience_alignment\": \"Analysis of alignment with {$audience}\",
    \"tone_analysis\": \"Tone analysis\"
  },
  \"recommendations\": {
    \"quick_wins\": [\"Quick win 1\", \"Quick win 2\", \"Quick win 3\"],
    \"long_term\": [\"Long-term 1\", \"Long-term 2\"],
    \"priority\": \"Top priority recommendation\"
  },
  \"ai_used\": true
}

CRITICAL: Return ONLY valid JSON. No markdown, no code blocks, no explanatory text. Provide specific section names for each suggestion.";

        return $prompt;
    }
    
    /**
     * Call Abacus.ai route-llm API
     */
    private static function call_abacus_ai( $prompt ) {
        $body = array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'max_tokens' => 4000,
            'temperature' => 0.7,
            'stream' => false
        );
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . self::get_api_key(),
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
            'timeout' => 45,
            'sslverify' => true,
        );
        
        error_log( '🚀 Calling Abacus.ai route-llm API...' );
        error_log( '📏 Prompt length: ' . strlen( $prompt ) . ' chars' );
        
        $response = wp_remote_post( self::ABACUS_API_URL, $args );
        
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log( '❌ Abacus.ai API WP_Error: ' . $error_msg );
            error_log( '❌ Error code: ' . $error_code );
            error_log( '❌ Error type: Network/Connection issue' );
            return array( 'success' => false, 'error' => 'API connection failed: ' . $error_msg . ' (code: ' . $error_code . ')' );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        error_log( "📡 Response status: {$status_code}" );
        
        if ( $status_code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            error_log( "❌ Abacus.ai API HTTP error: {$status_code}" );
            error_log( "❌ Response headers: " . json_encode( wp_remote_retrieve_headers( $response ) ) );
            error_log( "❌ Response body: " . substr( $body, 0, 500 ) );
            return array( 'success' => false, 'error' => "API returned HTTP {$status_code}: " . substr( $body, 0, 200 ) );
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            error_log( '⚠️ No content in AI response' );
            error_log( '⚠️ Response structure: ' . json_encode( array_keys( $data ) ) );
            error_log( '⚠️ Full response body: ' . substr( $body, 0, 1000 ) );
            return array( 'success' => false, 'error' => 'Empty AI response - check logs for details' );
        }
        
        $content = $data['choices'][0]['message']['content'];
        error_log( '📄 AI Response length: ' . strlen( $content ) . ' characters' );
        error_log( '📄 First 500 chars of response: ' . substr( $content, 0, 500 ) );
        
        // Try to parse JSON response
        $content = trim( $content );
        
        // Remove markdown code blocks if present
        if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
            $content = $matches[1];
            error_log( '✂️ Removed JSON markdown wrapper' );
        } elseif ( preg_match( '/```\s*(.*?)\s*```/s', $content, $matches ) ) {
            $content = $matches[1];
            error_log( '✂️ Removed generic markdown wrapper' );
        }
        
        error_log( '🔍 Attempting to parse JSON (length: ' . strlen( trim( $content ) ) . ')' );
        $parsed = json_decode( $content, true );
        
        if ( ! $parsed ) {
            error_log( '⚠️ Failed to parse AI response as JSON' );
            error_log( 'JSON Error: ' . json_last_error_msg() );
            error_log( 'Raw response (first 1000 chars): ' . substr( $content, 0, 1000 ) );
            return array( 'success' => false, 'error' => 'Invalid JSON response: ' . json_last_error_msg() );
        }
        
        // Validate required fields in response
        $required_fields = array( 'clarity_score', 'emotional_score', 'cta_strength', 'readability_score', 'engagement_score', 'trust_score' );
        $missing_fields = array();
        foreach ( $required_fields as $field ) {
            if ( ! isset( $parsed[ $field ] ) ) {
                $missing_fields[] = $field;
            }
        }
        
        if ( ! empty( $missing_fields ) ) {
            error_log( '⚠️ AI response missing required fields: ' . implode( ', ', $missing_fields ) );
            error_log( 'AI response structure: ' . json_encode( array_keys( $parsed ) ) );
            error_log( 'Full AI response: ' . json_encode( $parsed ) );
            // Still continue - these might be optional or have defaults
        }
        
        // Ensure suggestions is an array
        if ( isset( $parsed['suggestions'] ) && ! is_array( $parsed['suggestions'] ) ) {
            error_log( '⚠️ Suggestions is not an array, converting...' );
            $parsed['suggestions'] = array( array( 'text' => $parsed['suggestions'], 'section' => 'General' ) );
        }
        
        error_log( '✅ AI response parsed successfully (suggestions: ' . ( isset( $parsed['suggestions'] ) ? count( $parsed['suggestions'] ) : 0 ) . ')' );
        error_log( '✅ Returning success=true with data' );
        return array( 'success' => true, 'data' => $parsed );
    }

    /**
     * Fallback mock response if AI fails
     */
    private static function mock_response( $title ) {
        error_log( '🔄 Returning fallback mock response for: ' . $title );
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
                    'impact' => 'high',
                    'difficulty' => 'n/a'
                ),
                array(
                    'text' => 'The audit could not be completed using AI. This may be due to API connectivity issues or invalid responses.',
                    'section' => 'Technical',
                    'impact' => 'high',
                    'difficulty' => 'n/a'
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
                'strengths' => array( 'Fallback data generated' ),
                'weaknesses' => array( 'AI unavailable - check debug.log for details' ),
                'opportunities' => array( 'Retry audit after fixing AI integration' ),
                'audience_alignment' => 'Unable to analyze without AI',
                'tone_analysis' => 'Unable to analyze without AI'
            ),
            'recommendations' => array(
                'quick_wins' => array( 'Check WordPress debug.log at wp-content/debug.log' ),
                'long_term' => array( 'Verify Abacus.ai API key and connectivity' ),
                'priority' => 'Fix AI integration to get real audit data'
            )
        );
    }
    
    /**
     * Research industry-specific benchmarks and competitive intelligence
     */
    public static function research_industry_benchmarks( $industry, $audience, $goal ) {
        if ( empty( $industry ) ) {
            return self::get_fallback_benchmarks();
        }
        
        $prompt = "You are a conversion optimization and competitive intelligence expert. Research and provide specific data about the {$industry} industry.

**Industry:** {$industry}
**Target Audience:** " . ( !empty( $audience ) ? $audience : 'Not specified' ) . "
**Business Goal:** " . ( !empty( $goal ) ? $goal : 'Not specified' ) . "

Provide detailed, data-driven competitive intelligence for this industry. Your research should be specific to this industry and include:

1. **Average Conversion Score**: What is the typical overall conversion optimization score (0-100) for {$industry} websites? Consider industry maturity, competition level, and typical implementation quality. This should be a number between 60-75.

2. **Top Performer Threshold**: What score do the top 10% of {$industry} businesses achieve? Consider industry leaders and best-in-class examples. This should be a number between 85-95.

3. **Conversion Rate Impact**: For {$industry} specifically, how much does conversion rate typically improve per 10-point score increase? Consider industry-specific factors like sales cycles, average order value, and decision complexity.

4. **Quick Wins**: Identify 3 specific, actionable tactics that {$industry} businesses can implement quickly (within 1-2 weeks) to improve conversions. Be very specific and tactical.

5. **Key Competitive Factors**: What are the 3-4 most critical conversion factors that separate winners from losers in {$industry}? Be specific to this industry.

6. **Industry Challenges**: What specific obstacles or pain points do {$industry} businesses face in converting visitors?

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
  \"competitive_context\": \"2-3 sentences about the competitive landscape in {$industry} and what it takes to win\"
}

IMPORTANT: 
- industry_average must be an INTEGER between 60-75 (e.g., 68, 72, 70)
- top_performers_threshold must be an INTEGER between 85-95 (e.g., 88, 90, 92)
- Do NOT use placeholder values like 1 or X
- Provide realistic, researched data specific to {$industry}";

        $response = self::call_abacus_ai( $prompt );
        
        if ( $response && isset( $response['success'] ) && $response['success'] && isset( $response['data'] ) ) {
            error_log( '✅ Industry benchmark research successful for: ' . $industry );
            return $response['data'];
        }
        
        error_log( '⚠️ Industry benchmark research failed, using fallback' );
        return self::get_fallback_benchmarks();
    }
    
    /**
     * Get fallback benchmark data when AI research fails
     */
    private static function get_fallback_benchmarks() {
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
            'competitive_context' => 'The digital landscape is increasingly competitive. Businesses that invest in conversion optimization typically see significant advantages over competitors who focus only on traffic generation.'
        );
    }
}
