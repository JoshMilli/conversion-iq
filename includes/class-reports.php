<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ConversionIQ_Reports {
    public static function generate_pdf_for_audit( $audit ) {
        // Increase PHP limits to handle large HTML generation
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '120');
        @set_time_limit(120);
        
        // Enable output compression if available
        if (function_exists('ini_get') && ini_get('zlib.output_compression') == 0) {
            @ini_set('zlib.output_compression', '1');
        }
        
        // Log initial memory
        error_log('🔧 Report generation starting. Memory usage: ' . round(memory_get_usage()/1024/1024, 2) . 'MB / ' . ini_get('memory_limit'));
        
        // $audit is the DB row (with decoded data)
        if ( ! $audit || ! isset( $audit['data'] ) ) {
            error_log( '❌ Report generation failed: Invalid audit data' );
            return array(
                'success' => false,
                'message' => 'Invalid audit data',
            );
        }
        
        try {
            error_log( '📄 Starting report generation for audit #' . $audit['id'] );
            error_log( '🔍 Available data keys: ' . json_encode( array_keys( $audit['data'] ) ) );
            
            $upload = wp_upload_dir();
            $dir = trailingslashit( $upload['basedir'] ) . 'conversioniq/reports/';
            if ( ! file_exists( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            
            error_log( '📁 Upload directory: ' . $dir );

            // Generate clean filename: ConversionIQ-Audit-PageName-Date
            $page_slug = sanitize_title( $audit['page_title'] );
            $date_stamp = date( 'Y-m-d' );
            $filename = 'ConversionIQ-Audit-' . $page_slug . '-' . $date_stamp . '.pdf';
            $path = $dir . $filename;

            $data = $audit['data'];
            
            // Validate that $data is an array
            if ( ! is_array( $data ) ) {
                error_log( '❌ Report generation failed: audit data is not an array, got ' . gettype( $data ) );
                return array(
                    'success' => false,
                    'message' => 'Audit data is not properly formatted',
                );
            }
            
            error_log( '✅ Data is array, proceeding with HTML generation' );
            
            $report_date = date('F j, Y');
            $page_name = esc_html($audit['page_title']);
            $page_url = isset($audit['page_url']) ? esc_url($audit['page_url']) : '';

        // Get saved business settings if available
        $business = json_decode( get_option( 'conversion_iq_settings', '{}' ), true );
        
        // Get account information for company name and website
        $account = get_option( 'conversioniq_account', null );
        $company_name = isset($account['company']) ? esc_html($account['company']) : '';
        $website_url = isset($account['site_url']) ? esc_url($account['site_url']) : get_site_url();

        // Webtec brand colors
        $webtec_navy = '#1e3a5f';
        $webtec_blue = '#2563eb';
        $webtec_light_blue = '#dbeafe';
        
        // Webtec logo - convert to base64 for reliable PDF rendering
        $logo_path = CONVERSION_IQ_DIR . 'assets/images/Webtec.png';
        if ( file_exists( $logo_path ) ) {
            $logo_data = base64_encode( file_get_contents( $logo_path ) );
            $logo_html = '<img src="data:image/png;base64,' . $logo_data . '" alt="Webtec" style="width: 90px; height: auto;" />';
            // Free memory immediately
            unset($logo_data);
        } else {
            // Fallback if logo file doesn't exist
            $logo_html = '<div style="font-size: 24px; font-weight: bold; color: #1e3a5f;">WEBTEC</div>';
        }

        // Modern multi-page report HTML with Webtec branding
        $html = '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Website Conversion Audit Report</title>
    <style>
        @page { 
            margin: 0; 
            size: A4 portrait;
        }
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        body { 
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: #1e3a5f;
            line-height: 1.6;
            background: #ffffff;
            white-space: normal;
            orphans: 3;
            widows: 3;
        }
        
        /* Global text rendering rules */
        p, div, span, li, td, th, h1, h2, h3, h4, h5, h6 {
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        /* Print-specific rules for better PDF output */
        @media print {
            .page {
                page-break-after: always;
                page-break-inside: avoid;
            }
            .page:last-child {
                page-break-after: avoid;
            }
            /* Prevent breaks inside styled content boxes */
            div[style*="background"][style*="padding"] {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
        
        /* Page Structure */
        .page {
            max-width: 1200px;
            width: 100%;
            min-height: 29.7cm;
            padding: 0;
            margin: 0 auto;
            background: #ffffff;
            page-break-after: always;
            page-break-inside: avoid;
            position: relative;
            overflow: visible;
        }
        .page:last-child {
            page-break-after: avoid;
        }
        
        /* Prevent page breaks inside key elements */
        .intro-box, .section, .score-grid, .recommendation-item,
        .feature-card, .score-card {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        /* Prevent orphaned headings */
        h1, h2, h3, h4, h5, h6 {
            page-break-after: avoid;
            break-after: avoid;
            orphans: 3;
            widows: 3;
        }
        
        /* Keep headings with following content */
        h3 + div, h3 + p, h4 + div, h4 + p {
            page-break-before: avoid;
        }
        
        /* Page 1: Cover Page */
        .cover-page {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 60px 80px;
        }
        .cover-logo {
            width: 180px;
            height: auto;
            margin-bottom: 40px;
        }
        .cover-title {
            font-size: 48px;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 20px;
            letter-spacing: -1px;
        }
        .cover-subtitle {
            font-size: 24px;
            opacity: 0.9;
            margin-bottom: 60px;
            font-weight: 300;
        }
        .cover-company-info {
            margin-bottom: 30px;
            padding: 0;
        }
        .cover-company-name {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #ffffff;
        }
        .cover-company-url {
            font-size: 16px;
            opacity: 0.9;
            color: #dbeafe;
            word-break: break-all;
        }
        .cover-page-name {
            font-size: 20px;
            padding: 20px 30px;
            background: rgba(255,255,255,0.15);
            border-left: 4px solid #ffffff;
            margin-bottom: 40px;
            border-radius: 4px;
        }
        .cover-meta {
            border-top: 1px solid rgba(255,255,255,0.3);
            padding-top: 30px;
            font-size: 16px;
            opacity: 0.9;
        }
        .cover-date {
            font-size: 14px;
            opacity: 0.8;
            margin-top: 10px;
        }
        
        /* Introduction Page */
        .intro-page {
            padding: 80px;
        }
        .page-header {
            border-bottom: 3px solid #2563eb;
            padding-bottom: 20px;
            margin-bottom: 40px;
        }
        .page-number {
            position: absolute;
            bottom: 40px;
            right: 80px;
            font-size: 12px;
            color: #6b7280;
        }
        .intro-title {
            font-size: 36px;
            color: #1e3a5f;
            margin-bottom: 30px;
            font-weight: 700;
        }
        .intro-text {
            font-size: 16px;
            line-height: 1.8;
            color: #374151;
            margin-bottom: 20px;
            white-space: normal;
        }
        .intro-box {
            background: #dbeafe;
            padding: 30px;
            border-radius: 8px;
            border-left: 4px solid #2563eb;
            margin: 30px 0;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .intro-box h3 {
            color: #1e3a5f;
            font-size: 20px;
            margin-bottom: 15px;
        }
        .intro-list {
            list-style: none;
            padding-left: 0;
        }
        .intro-list li {
            padding: 10px 0 10px 30px;
            position: relative;
            font-size: 15px;
            color: #374151;
        }
        .intro-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #2563eb;
            font-weight: bold;
            font-size: 18px;
        }
        
        /* Content Pages */
        .content-page {
            padding: 50px 70px 60px 70px;
        }
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            border-bottom: 2px solid #2563eb;
            margin-bottom: 40px;
        }
        .content-header h2 {
            font-size: 28px;
            color: #1e3a5f;
            font-weight: 700;
        }
        .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
            break-inside: avoid;
            orphans: 2;
            widows: 2;
        }
        .section-title {
            font-size: 22px;
            color: #1e3a5f;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
            page-break-after: avoid;
            orphans: 3;
            widows: 3;
        }
        
        /* Score Grid */
        .score-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 30px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .score-card {
            background: #f9fafb;
            padding: 18px;
            border-radius: 8px;
            border-left: 4px solid;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .score-card.clarity { border-color: #2563eb; }
        .score-card.emotional { border-color: #f59e0b; }
        .score-card.cta { border-color: #10b981; }
        .score-card.readability { border-color: #9333ea; }
        .score-card.engagement { border-color: #d97706; }
        .score-card.trust { border-color: #0891b2; }
        .score-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .score-value {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 12px;
        }
        .score-card.clarity .score-value { color: #2563eb; }
        .score-card.emotional .score-value { color: #f59e0b; }
        .score-card.cta .score-value { color: #10b981; }
        .score-card.readability .score-value { color: #9333ea; }
        .score-card.engagement .score-value { color: #d97706; }
        .score-card.trust .score-value { color: #0891b2; }
        .score-bar {
            background: #e5e7eb;
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
        }
        .score-bar-fill {
            height: 100%;
            border-radius: 3px;
        }
        
        /* Recommendations */
        .recommendation-list {
            list-style: none;
            padding: 0;
        }
        .recommendation-item {
            background: #ffffff;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #2563eb;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .recommendation-number {
            display: inline-block;
            background: #2563eb;
            color: #ffffff;
            width: 28px;
            height: 28px;
            line-height: 28px;
            text-align: center;
            border-radius: 50%;
            font-weight: 700;
            font-size: 14px;
            margin-right: 15px;
        }
        .recommendation-text {
            display: inline;
            font-size: 15px;
            line-height: 1.6;
            color: #374151;
            white-space: normal;
            word-wrap: break-word;
        }
        
        /* Features Section */
        .feature-card {
            background: #f9fafb;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
            border-left: 4px solid #9333ea;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .feature-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e3a5f;
            margin-bottom: 10px;
        }
        .feature-desc {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .feature-impact {
            background: #ffffff;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 13px;
            color: #059669;
            font-weight: 600;
        }
        
        /* Thank You Page */
        .thank-you-page {
            padding: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
        }
        .thank-you-content {
            text-align: center;
            max-width: 600px;
            margin: 0 auto;
        }
        .thank-you-title {
            font-size: 42px;
            color: #1e3a5f;
            font-weight: 700;
            margin-bottom: 30px;
        }
        .thank-you-text {
            font-size: 18px;
            color: #374151;
            line-height: 1.8;
            margin-bottom: 40px;
        }
        .thank-you-cta {
            background: #2563eb;
            color: #ffffff;
            padding: 20px 40px;
            border-radius: 8px;
            display: inline-block;
            font-size: 18px;
            font-weight: 600;
            text-decoration: none;
            margin-bottom: 40px;
        }
        .thank-you-footer {
            border-top: 2px solid #e5e7eb;
            padding-top: 30px;
            margin-top: 40px;
        }
        .thank-you-footer p {
            font-size: 14px;
            color: #6b7280;
            margin: 5px 0;
        }
    </style>
</head>
<body>';

        // ============ PAGE 1: COVER PAGE ============
        $html .= '
        <div class="page cover-page">
            <div>
                '.$logo_html.'
            </div>
            <div>
                <h1 class="cover-title">Website Conversion<br>Audit Report</h1>
                <p class="cover-subtitle">Professional Analysis & Recommendations</p>';
        
        // Add company information if available
        if ( $company_name ) {
            $html .= '
                <div class="cover-company-info">';
            $html .= '
                    <div class="cover-company-name">'.$company_name.'</div>';
            $html .= '
                </div>';
        }
        
        $html .= '
                <div class="cover-page-name">
                    <strong>Page Analyzed:</strong> '.$page_name.'
                </div>
                '.($page_url ? '<div class="cover-page-url" style="margin-top: 8px; font-size: 14px; color: #dbeafe;">'.$page_url.'</div>' : '').'
            </div>
            <div class="cover-meta">
                <p><strong>Prepared by:</strong> Webtec</p>
                <p class="cover-date">Report Date: '.$report_date.'</p>
            </div>
        </div>';

        // ============ PAGE 2: INTRODUCTION ============
        $html .= '
        <div class="page intro-page">
            <div class="page-header">
                '.$logo_html.'
            </div>
            
            <h1 class="intro-title">About This Report</h1>
            
            <p class="intro-text">
                Thank you for choosing Webtec\'s ConversionIQ platform to analyze your website. This comprehensive audit has been designed to help you understand how your site performs in key areas that directly impact visitor engagement and conversion rates.
            </p>
            
            <p class="intro-text">
                Using advanced AI analysis combined with conversion rate optimization best practices, industry insights and competitor analysis. We\'ve evaluated your page across six critical dimensions and identified specific opportunities for improvement.
            </p>
            
            <div class="intro-box">
                <h3>What You\'ll Find in This Report</h3>
                <ul class="intro-list">
                    <li><strong>Performance Scores:</strong> Six key metrics measuring your page\'s effectiveness</li>
                    <li><strong>Detailed Recommendations:</strong> Specific, actionable suggestions for improvement</li>
                    <li><strong>Feature Suggestions:</strong> Advanced functionality that could boost conversions</li>
                    <li><strong>Priority Actions:</strong> The most impactful changes you can make right now</li>
                </ul>
            </div>
            
            <div class="intro-box" style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-left: 4px solid #2563eb; padding: 30px; page-break-inside: avoid; break-inside: avoid;">
                <h3 style="color: #1e3a5f; margin: 0 0 12px 0; font-size: 20px;">Business Context for This Analysis</h3>
                <p style="font-size: 15px; color: #374151; line-height: 1.6; margin-bottom: 20px; white-space: normal; word-wrap: break-word;">
                    This report has been tailored to your site and industry by considering the following:
                </p>
                
                <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); page-break-inside: avoid; break-inside: avoid;">
                    <div style="display: grid; gap: 12px;">
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Industry/Niche</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">'.(!empty($business['industry']) ? esc_html($business['industry']) : '<span style="color: #9ca3af;">Not specified</span>').'</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">What You Sell</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">'.(!empty($business['product']) ? esc_html($business['product']) : '<span style="color: #9ca3af;">Not specified</span>').'</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Target Audience</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">'.(!empty($business['audience']) ? esc_html($business['audience']) : '<span style="color: #9ca3af;">Not specified</span>').'</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Customer Pain Points</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">'.(!empty($business['pain_points']) ? esc_html($business['pain_points']) : '<span style="color: #9ca3af;">Not specified</span>').'</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Key Competitors</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">'.(!empty($business['competitors']) ? esc_html($business['competitors']) : '<span style="color: #9ca3af;">Not specified</span>').'</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Primary Conversion Goal</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">'.(!empty($business['goal']) ? esc_html($business['goal']) : '<span style="color: #9ca3af;">Not specified</span>').'</div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 16px; padding: 14px; background: white; border-radius: 8px; border-left: 3px solid #f59e0b; page-break-inside: avoid; break-inside: avoid;">
                    <p style="font-size: 13px; color: #92400e; line-height: 1.6; margin: 0; white-space: normal; word-wrap: break-word;">
                        We know this information will change over time. Let us know so we can update our database and ensure your recommendations are tailored correctly for you.
                    </p>
                </div>
            </div>
            
            <p class="intro-text">
                Our team at Webtec is here to help you implement these changes and achieve your digital marketing goals. Let\'s dive into your results.
            </p>
            
            <div class="page-number">Page 1</div>
        </div>';

        // ============ PAGE 3: EXECUTIVE SUMMARY ============
        // Calculate overall score and determine status
        $overall_score = round((
            intval($data['clarity_score'] ?? 0) +
            intval($data['emotional_score'] ?? 0) +
            intval($data['cta_strength'] ?? 0) +
            intval($data['readability_score'] ?? 0) +
            intval($data['engagement_score'] ?? 0) +
            intval($data['trust_score'] ?? 0)
        ) / 6);
        
        $status = $overall_score >= 85 ? 'Excellent' : ($overall_score >= 75 ? 'Good' : ($overall_score >= 60 ? 'Fair' : 'Needs Improvement'));
        $status_color = $overall_score >= 85 ? '#10b981' : ($overall_score >= 75 ? '#2563eb' : ($overall_score >= 60 ? '#f59e0b' : '#ef4444'));
        
        // Find lowest score for priority action
        $score_values = [
            'Clarity' => intval($data['clarity_score'] ?? 0),
            'Emotional Connection' => intval($data['emotional_score'] ?? 0),
            'CTA Strength' => intval($data['cta_strength'] ?? 0),
            'Readability' => intval($data['readability_score'] ?? 0),
            'Engagement' => intval($data['engagement_score'] ?? 0),
            'Trust Signals' => intval($data['trust_score'] ?? 0),
        ];
        asort($score_values);
        $lowest_area = array_key_first($score_values);
        $potential_gain = round((85 - $score_values[$lowest_area]) * 0.8);
        
        // Find highest and second-lowest scores for insight cards
        $sorted_scores_asc = $score_values;
        asort($sorted_scores_asc);
        $sorted_scores_desc = $score_values;
        arsort($sorted_scores_desc);
        
        $highest_area = array_key_first($sorted_scores_desc);
        $highest_score = $sorted_scores_desc[$highest_area];
        
        // Get second-lowest for "Biggest Opportunity"
        $scores_array = array_values($sorted_scores_asc);
        $keys_array = array_keys($sorted_scores_asc);
        $second_lowest_area = isset($keys_array[1]) ? $keys_array[1] : $keys_array[0];
        $second_lowest_score = $sorted_scores_asc[$second_lowest_area];
        
        // Get third-lowest for "Quick Win"
        $third_lowest_area = isset($keys_array[2]) ? $keys_array[2] : $keys_array[1];
        $third_lowest_score = $sorted_scores_asc[$third_lowest_area];
        
        // Fetch historical data for trend
        global $wpdb;
        $table = $wpdb->prefix . 'conversioniq_audits';
        $historical = $wpdb->get_results( $wpdb->prepare( 
            "SELECT data, created_at FROM $table WHERE page_id = %d ORDER BY created_at DESC LIMIT 5", 
            $audit['page_id'] 
        ), ARRAY_A );
        
        // Extract benchmark research data from AI analysis
        $industry_avg = null;
        $top_performers = null;
        $conversion_lift = '';
        $competitive_factors = array();
        $industry_challenges = array();
        $competitive_context = '';
        $quick_wins = array();
        
        if (isset($data['benchmark_research']) && is_array($data['benchmark_research'])) {
            $benchmark = $data['benchmark_research'];
            $industry_avg = isset($benchmark['industry_average']) ? intval($benchmark['industry_average']) : null;
            $top_performers = isset($benchmark['top_performers_threshold']) ? intval($benchmark['top_performers_threshold']) : null;
            $conversion_lift = isset($benchmark['conversion_rate_lift_per_10_points']) ? $benchmark['conversion_rate_lift_per_10_points'] : '';
            $competitive_factors = isset($benchmark['key_competitive_factors']) && is_array($benchmark['key_competitive_factors']) ? $benchmark['key_competitive_factors'] : array();
            $industry_challenges = isset($benchmark['industry_challenges']) && is_array($benchmark['industry_challenges']) ? $benchmark['industry_challenges'] : array();
            $competitive_context = isset($benchmark['competitive_context']) ? strval($benchmark['competitive_context']) : '';
            $quick_wins = isset($benchmark['quick_wins']) && is_array($benchmark['quick_wins']) ? $benchmark['quick_wins'] : array();
        }
        
        $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Executive Summary</h2>
                <span style="font-size: 14px; color: #6b7280;">'.$report_date.'</span>
            </div>
            
            <!-- Overall Score Card -->
            <div style="background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%); padding: 25px; border-radius: 12px; margin-bottom: 20px; text-align: center; border: 2px solid '.esc_attr($status_color).';">
                <div style="font-size: 56px; font-weight: 800; color: '.esc_attr($status_color).'; margin-bottom: 6px;">'.$overall_score.'<span style="font-size: 28px;">/100</span></div>
                <div style="font-size: 20px; font-weight: 600; color: #1e3a5f; margin-bottom: 6px;">'.$status.' Performance</div>
                <div style="font-size: 14px; color: #6b7280;">Your website shows '.(strtolower($status)).' performance with opportunities for growth</div>
            </div>
            
            <!-- Key Insights -->';
            
        // Check if we have AI-generated insights
        $has_ai_insights = isset($data['executive_summary']) && !empty($data['executive_summary']);
        $has_priority_insight = isset($data['top_priority_insight']) && !empty($data['top_priority_insight']);
        
        if ($has_ai_insights || $has_priority_insight) {
            // Use AI-generated insights
            $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Key Insights</h3>';
                
            if ($has_ai_insights) {
                $html .= '<div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); padding: 20px; border-radius: 10px; margin-bottom: 16px; border-left: 4px solid #0891b2; page-break-inside: avoid; break-inside: avoid;">
                    <h4 style="color: #0891b2; font-size: 16px; margin-bottom: 12px; font-weight: 700;">📊 Executive Summary</h4>
                    <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin: 0;">'.nl2br(esc_html($data['executive_summary'])).'</p>
                </div>';
            }
            
            if ($has_priority_insight) {
                $html .= '<div style="background: #fff7ed; padding: 20px; border-radius: 10px; border-left: 4px solid #f59e0b; page-break-inside: avoid; break-inside: avoid;">
                    <h4 style="color: #f59e0b; font-size: 16px; margin-bottom: 12px; font-weight: 700;">🎯 Top Priority Focus</h4>
                    <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin: 0;">'.nl2br(esc_html($data['top_priority_insight'])).'</p>
                </div>';
            }
            
            $html .= '</div>';
        } else {
            // Fallback to static template for older audits
            $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Key Insights</h3>
                
                <div style="display: grid; gap: 15px;">
                    <!-- Priority Action -->
                    <div style="background: #fff7ed; padding: 16px; border-radius: 8px; border-left: 4px solid #f59e0b; page-break-inside: avoid; break-inside: avoid;">
                        <h4 style="color: #f59e0b; font-size: 16px; margin-bottom: 10px; font-weight: 700;">⚡ Top Priority Action</h4>
                        <p style="font-size: 14px; color: #374151; line-height: 1.6; margin-bottom: 10px;">
                            <strong>Focus Area:</strong> '.$lowest_area.' (currently scoring '.$score_values[$lowest_area].' out of 100)
                        </p>
                        <p style="font-size: 13px; color: #374151; line-height: 1.6; margin: 0;">
                            This represents your greatest opportunity for improvement. By addressing the issues identified, you can expect an improvement of approximately <strong>+'.$potential_gain.' points</strong>. '.$lowest_area.' directly impacts how visitors perceive your offer and make decisions.
                        </p>
                    </div>
                </div>
                
                <!-- Insight Cards Grid -->
                <div style="display: table; width: 100%; table-layout: fixed; margin-top: 15px;">
                    <div style="display: table-row;">
                        <!-- Top Strength Card -->
                        <div style="display: table-cell; width: 33.33%; padding-right: 8px; vertical-align: top;">
                            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); padding: 16px; border-radius: 8px; border-left: 4px solid #10b981; height: 100%; page-break-inside: avoid; break-inside: avoid;">
                                <h4 style="color: #10b981; font-size: 15px; margin-bottom: 10px; font-weight: 700;">🎯 Top Strength</h4>
                                <p style="font-size: 13px; color: #1e293b; line-height: 1.6; margin: 0;">
                                    Your <strong>'.$highest_area.' ('.$highest_score.'/100)</strong> shows strong performance. This solid foundation helps keep visitors engaged and moving toward conversion.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Biggest Opportunity Card -->
                        <div style="display: table-cell; width: 33.33%; padding-left: 4px; padding-right: 4px; vertical-align: top;">
                            <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 16px; border-radius: 8px; border-left: 4px solid #f59e0b; height: 100%; page-break-inside: avoid; break-inside: avoid;">
                                <h4 style="color: #f59e0b; font-size: 15px; margin-bottom: 10px; font-weight: 700;">💡 Biggest Opportunity</h4>
                                <p style="font-size: 13px; color: #1e293b; line-height: 1.6; margin: 0;">
                                    Your <strong>'.$second_lowest_area.' ('.$second_lowest_score.'/100)</strong> could better connect with your target audience. Improvements here typically lift conversions by 20-30%.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Quick Win Card -->
                        <div style="display: table-cell; width: 33.33%; padding-left: 8px; vertical-align: top;">
                            <div style="background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); padding: 16px; border-radius: 8px; border-left: 4px solid #8b5cf6; height: 100%; page-break-inside: avoid; break-inside: avoid;">
                                <h4 style="color: #8b5cf6; font-size: 15px; margin-bottom: 10px; font-weight: 700;">🚀 Quick Win</h4>
                                <p style="font-size: 13px; color: #1e293b; line-height: 1.6; margin: 0;">
                                    Strengthening your <strong>'.$third_lowest_area.' ('.$third_lowest_score.'/100)</strong> with targeted improvements could yield immediate results in conversion rates.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
        }
        
        // Benchmark Explanation Section - only show if we have AI-generated benchmark data
        if ($industry_avg !== null && $top_performers !== null) {
            $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Understanding Your Benchmark Score</h3>
                
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%); padding: 24px; border-radius: 12px; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; page-break-inside: avoid; break-inside: avoid;">
                        <h4 style="color: #1e3a5f; font-size: 16px; margin-bottom: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">'.(!empty($business['industry']) ? esc_html($business['industry']).' Industry' : 'Industry').' Benchmark</h4>
                        <p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin-bottom: 20px;">
                            Based on AI analysis of '.(!empty($business['industry']) ? 'competitive '.strtolower(esc_html($business['industry'])).' websites' : 'thousands of websites').' and conversion optimization data
                        </p>
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 24px;">
                            <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); border-radius: 10px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);">
                                <div style="font-size: 12px; color: rgba(255,255,255,0.8); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Your Score</div>
                                <div style="font-size: 48px; font-weight: 800; color: #ffffff; line-height: 1;">'.$overall_score.'</div>
                            </div>
                            <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%); border-radius: 10px; border: 2px solid #f59e0b;">
                                <div style="font-size: 12px; color: #92400e; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">'.(!empty($business['industry']) ? esc_html($business['industry']) : 'Industry').' Average</div>
                                <div style="font-size: 48px; font-weight: 800; color: #f59e0b; line-height: 1;">'.$industry_avg.'</div>
                            </div>
                            <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-radius: 10px; border: 2px solid #10b981;">
                                <div style="font-size: 12px; color: #065f46; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Top Performers</div>
                                <div style="font-size: 48px; font-weight: 800; color: #10b981; line-height: 1;">'.$top_performers.'<span style="font-size: 28px;">+</span></div>
                            </div>
                        </div>
                        
                        <div style="background: white; padding: 18px; border-radius: 8px; border-left: 4px solid #2563eb; margin-bottom: 15px;">
                            <h5 style="color: #1e3a5f; font-size: 15px; margin: 0 0 10px 0; font-weight: 700;">Competitive Position</h5>
                            <p style="font-size: 13px; color: #374151; line-height: 1.6; margin: 0;">';
        
        // Dynamic competitive analysis based on score and industry
        $industry_name = !empty($business['industry']) ? strtolower($business['industry']) : 'your industry';
        
        if ($overall_score >= $top_performers) {
            $html .= 'You are <strong>outperforming most competitors</strong> in '.$industry_name.'. Your score places you in the <strong>top 10%</strong> of similar businesses. Maintaining this level while optimizing weaker areas will solidify your market leadership position.';
        } elseif ($overall_score >= $industry_avg) {
            $html .= 'You are performing <strong>above the '.esc_html($industry_name).' average</strong>. This means your page is more effective than approximately <strong>50-70% of competitors</strong>. The recommendations in this report will help you break into the top tier.';
        } elseif ($overall_score >= ($industry_avg - 12)) {
            $html .= 'Your performance is <strong>slightly below the '.esc_html($industry_name).' standard</strong>. Many of your competitors are converting visitors more effectively. However, you\'re positioned to make rapid gains - businesses at this level often see the most dramatic improvements from optimization.';
        } else {
            $html .= 'There is significant opportunity to improve your competitive position in '.esc_html($industry_name).'. Most competitors are converting at higher rates, but this gap represents your biggest growth opportunity. The recommendations provided target high-impact improvements.';
        }
        
        $html .= '</p>
                        </div>';
        
        // Display competitive context if available - EXPANDED
        if (!empty($competitive_context)) {
            $html .= '<div style="background: #f0f9ff; padding: 20px; border-radius: 8px; border-left: 4px solid #0891b2; margin-bottom: 20px;">
                <h5 style="color: #0891b2; font-size: 15px; margin: 0 0 12px 0; font-weight: 700;">Competitive Landscape</h5>
                <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin-bottom: 0;">'.nl2br(esc_html($competitive_context)).'</p>';
            
            // Add industry-specific context if available - integrated naturally
            if (!empty($business['industry'])) {
                $html .= '<p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin: 14px 0 0 0;">
                    In the '.esc_html($business['industry']).' sector, leading websites distinguish themselves through exceptional trust-building elements, compelling value propositions, and frictionless conversion pathways. With your score of <strong>'.$overall_score.'</strong> compared to the industry average of <strong>'.$industry_avg.'</strong>, you are '.($overall_score >= $industry_avg ? 'demonstrating strong competitive positioning' : 'well-positioned to capture significant market share through strategic optimization').'. The performance gap between average competitors ('.$industry_avg.') and top-tier performers ('.$top_performers.'+) represents a measurable opportunity for differentiation in your market.
                </p>';
            }
            
            $html .= '</div>';
        } elseif (!empty($business['industry'])) {
            // Fallback competitive landscape if AI didn't provide one
            $html .= '<div style="background: #f0f9ff; padding: 20px; border-radius: 8px; border-left: 4px solid #0891b2; margin-bottom: 20px;">
                <h5 style="color: #0891b2; font-size: 15px; margin: 0 0 12px 0; font-weight: 700;">Competitive Landscape</h5>
                <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin-bottom: 14px;">
                    The <strong>'.esc_html($business['industry']).' industry</strong> is characterized by intense competition for visitor attention and trust. Research across competitive websites in this sector reveals that market leaders consistently prioritize three core elements: immediate credibility establishment, crystal-clear value communication, and streamlined user journeys that minimize conversion friction.
                </p>
                <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin: 0;">
                    Your current score of <strong>'.$overall_score.'</strong> positions you '.($overall_score >= $industry_avg ? 'above the industry benchmark of '.$industry_avg.', placing you in the upper tier of '.esc_html($business['industry']).' competitors' : 'below the industry benchmark of '.$industry_avg.', indicating significant headroom for competitive gains').'. Analysis shows that businesses scoring in the '.$top_performers.'+ range typically capture disproportionate market share through compound advantages in credibility, clarity, and conversion effectiveness. The '.abs($top_performers - $overall_score).'-point gap to top-performer status represents your most direct path to measurable competitive advantage.
                </p>
            </div>';
        }
        
        // Display quick wins if available - MORE COMPACT
        if (!empty($quick_wins) && is_array($quick_wins)) {
            $html .= '<div style="margin-top: 20px;">
                <h5 style="color: #1e3a5f; font-size: 15px; margin: 0 0 12px 0; font-weight: 700;">⚡ Quick Wins</h5>
                <div style="display: grid; gap: 10px;">';
            
            $win_number = 1;
            foreach ($quick_wins as $win) {
                if (isset($win['tactic'])) {
                    $tactic = esc_html($win['tactic']);
                    $impact = isset($win['impact']) ? esc_html($win['impact']) : '';
                    
                    $html .= '<div style="background: white; padding: 14px; border-radius: 6px; border: 1px solid #e5e7eb; display: flex; align-items: start; gap: 10px;">
                        <div style="background: #2563eb; color: white; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0;">'.$win_number.'</div>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #1e3a5f; font-size: 13px; line-height: 1.4;">'.$tactic.'</div>';
                    
                    if (!empty($impact)) {
                        $html .= '<div style="font-size: 12px; color: #059669; margin-top: 4px;">Impact: '.$impact.'</div>';
                    }
                    
                    $html .= '</div>
                    </div>';
                    
                    $win_number++;
                }
            }
            
            $html .= '</div>
            </div>';
        }
        
        $html .= '</div>
                </div>
            </div>';
        } // End of benchmark section conditional
            
        // Historical Trend (if data exists)
        if (count($historical) > 1) {
            $html .= '<div class="section">
                <h3 class="section-title">Performance Trend</h3>
                <!-- Centered table container for better TCPDF rendering -->
                <table cellpadding="0" cellspacing="0" style="width: 650px; margin: 0 auto; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 0;">
                            <div style="background: #ffffff; padding: 30px 20px; border-radius: 8px; border: 1px solid #e5e7eb;">
                                <p style="font-size: 14px; color: #6b7280; margin-bottom: 30px; text-align: center;">Historical performance based on your last '.count($historical).' audits</p>
                                
                                <!-- Chart Area with Grid -->
                                <div style="position: relative; padding: 20px 0;">
                                    <!-- Y-axis labels -->
                                    <div style="position: absolute; left: -40px; top: 20px; bottom: 40px; display: flex; flex-direction: column; justify-content: space-between; text-align: right; font-size: 12px; color: #6b7280; width: 35px;">
                            <span>100</span>
                            <span>75</span>
                            <span>50</span>
                            <span>25</span>
                            <span>0</span>
                        </div>
                        
                        <!-- Grid lines -->
                        <div style="position: absolute; left: 0; right: 0; top: 20px; bottom: 40px;">
                            <div style="position: absolute; width: 100%; height: 1px; background: #e5e7eb; top: 0;"></div>
                            <div style="position: absolute; width: 100%; height: 1px; background: #e5e7eb; top: 25%;"></div>
                            <div style="position: absolute; width: 100%; height: 1px; background: #e5e7eb; top: 50%;"></div>
                            <div style="position: absolute; width: 100%; height: 1px; background: #e5e7eb; top: 75%;"></div>
                            <div style="position: absolute; width: 100%; height: 1px; background: #d1d5db; top: 100%;"></div>
                        </div>
                        
                        <!-- Bar Chart -->
                        <div style="display: grid; grid-template-columns: repeat('.min(count($historical), 5).', 1fr); gap: 20px; position: relative; z-index: 10;">';
            
            $trend_data = [];
            foreach ($historical as $h) {
                $h_data = json_decode($h['data'], true);
                if ($h_data) {
                    $h_score = round((
                        intval($h_data['clarity_score'] ?? 0) +
                        intval($h_data['emotional_score'] ?? 0) +
                        intval($h_data['cta_strength'] ?? 0) +
                        intval($h_data['readability_score'] ?? 0) +
                        intval($h_data['engagement_score'] ?? 0) +
                        intval($h_data['trust_score'] ?? 0)
                    ) / 6);
                    $trend_data[] = ['score' => $h_score, 'date' => date('M j', strtotime($h['created_at']))];
                }
            }
            $trend_data = array_reverse($trend_data);
            
            foreach ($trend_data as $idx => $point) {
                $is_current = ($idx === count($trend_data) - 1);
                $bar_color = $is_current ? '#2563eb' : '#94a3b8';
                $html .= '<div style="display: flex; flex-direction: column; align-items: center;">
                    <div style="height: 200px; width: 100%; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 15px;">
                        <div style="width: 70%; background: '.$bar_color.'; height: '.($point['score'] * 2).'px; border-radius: 6px 6px 0 0; position: relative; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div style="position: absolute; top: -30px; left: 50%; transform: translateX(-50%); background: '.$bar_color.'; color: white; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 16px; white-space: nowrap;">'.$point['score'].'</div>
                        </div>
                    </div>
                    <div style="font-size: 13px; color: '.($is_current ? '#1e3a5f' : '#6b7280').'; font-weight: '.($is_current ? '600' : '400').';">'.esc_html($point['date']).'</div>
                    '.($is_current ? '<div style="font-size: 11px; color: #2563eb; font-weight: 600; margin-top: 4px;">Current</div>' : '').
                '</div>';
            }
            
            $html .= '</div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>';
            
            // Calculate trend
            if (count($trend_data) >= 2) {
                $first_score = $trend_data[0]['score'];
                $last_score = $trend_data[count($trend_data) - 1]['score'];
                $change = $last_score - $first_score;
                $change_percent = $first_score > 0 ? round(($change / $first_score) * 100) : 0;
                
                $html .= '<table cellpadding="0" cellspacing="0" style="width: 650px; margin: 25px auto 0; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 0;">
                            <div style="padding: 25px; background: '.($change >= 0 ? '#d1fae5' : '#fee2e2').'; border-radius: 8px; border-left: 4px solid '.($change >= 0 ? '#10b981' : '#ef4444').';">
                        <p style="font-size: 16px; color: #374151; margin: 0; line-height: 1.6;">
                            <strong>'.($change >= 0 ? 'Trend: Improving' : 'Trend: Declining').'</strong><br>
                            Your score has '.($change >= 0 ? 'increased' : 'decreased').' by <strong>'.abs($change).' points</strong> ('.($change >= 0 ? '+' : '').$change_percent.'%) since your first audit. '.($change >= 0 ? 'This positive trend indicates that previous optimization efforts are working effectively.' : 'This decline suggests a need to review recent changes and refocus on core conversion principles.').'
                        </p>
                            </div>
                        </td>
                    </tr>
                </table>';
            }
            
            $html .= '</div>
            <div class="page-number">Page 2</div>
        </div>';
        }

        // ============ PAGE 4: SCORES & ANALYSIS ============
        $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Performance Analysis</h2>
                <span style="font-size: 14px; color: #6b7280;">'.$report_date.'</span>
            </div>
            
            <div class="section">
                <h3 class="section-title">Conversion Scores</h3>
                <div class="score-grid">';
        
        $scores = [
            ['key' => 'clarity_score', 'label' => 'Clarity', 'class' => 'clarity', 'color' => '#2563eb'],
            ['key' => 'emotional_score', 'label' => 'Emotional Impact', 'class' => 'emotional', 'color' => '#f59e0b'],
            ['key' => 'cta_strength', 'label' => 'CTA Strength', 'class' => 'cta', 'color' => '#10b981'],
            ['key' => 'readability_score', 'label' => 'Readability', 'class' => 'readability', 'color' => '#9333ea'],
            ['key' => 'engagement_score', 'label' => 'Engagement', 'class' => 'engagement', 'color' => '#d97706'],
            ['key' => 'trust_score', 'label' => 'Trust Signals', 'class' => 'trust', 'color' => '#0891b2'],
        ];
        
        foreach ( $scores as $score ) {
            $value = intval( $data[$score['key']] ?? 0 );
            $html .= '<div class="score-card '.esc_attr($score['class']).'">
                <div class="score-label">'.esc_html($score['label']).'</div>
                <div class="score-value">'.$value.'</div>
                <div class="score-bar">
                    <div class="score-bar-fill" style="width:'.$value.'%;background:'.esc_attr($score['color']).'"></div>
                </div>
            </div>';
        }
        
        $html .= '</div>
            </div>';

        // Score Descriptions
        $html .= '<div class="section">
                <h3 class="section-title">Understanding Your Scores</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #2563eb;">
                        <h4 style="color: #2563eb; font-size: 14px; margin-bottom: 8px;">📝 Clarity Score</h4>
                        <p style="font-size: 13px; color: #374151; line-height: 1.5;">Measures how clearly your message is communicated. Higher scores indicate visitors instantly understand what you offer and why it matters.</p>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                        <h4 style="color: #f59e0b; font-size: 14px; margin-bottom: 8px;">💫 Emotional Impact</h4>
                        <p style="font-size: 13px; color: #374151; line-height: 1.5;">Evaluates how well your content connects emotionally with visitors. Strong emotional appeal drives engagement and action.</p>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #10b981;">
                        <h4 style="color: #10b981; font-size: 14px; margin-bottom: 8px;">🎯 CTA Strength</h4>
                        <p style="font-size: 13px; color: #374151; line-height: 1.5;">Assesses the effectiveness of your call-to-action buttons and prompts. Strong CTAs are clear, compelling, and strategically placed.</p>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #9333ea;">
                        <h4 style="color: #9333ea; font-size: 14px; margin-bottom: 8px;">📖 Readability</h4>
                        <p style="font-size: 13px; color: #374151; line-height: 1.5;">Measures how easy your content is to read and understand. Better readability keeps visitors engaged longer and improves comprehension.</p>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #d97706;">
                        <h4 style="color: #d97706; font-size: 14px; margin-bottom: 8px;">⚡ Engagement</h4>
                        <p style="font-size: 13px; color: #374151; line-height: 1.5;">Evaluates elements that encourage visitor interaction. High engagement means visitors are more likely to explore and take action.</p>
                    </div>
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #0891b2;">
                        <h4 style="color: #0891b2; font-size: 14px; margin-bottom: 8px;">🔒 Trust Signals</h4>
                        <p style="font-size: 13px; color: #374151; line-height: 1.5;">Measures credibility indicators like testimonials, guarantees, and security features. Strong trust signals reduce hesitation and increase conversions.</p>
                    </div>
                </div>
            </div>';

        // Recommendations
        if ( ! empty( $data['suggestions'] ) && is_array( $data['suggestions'] ) ) {
            $html .= '<div class="section">
                <h3 class="section-title">Recommendations</h3>';
            
            $counter = 1;
            foreach ( $data['suggestions'] as $s ) {
                // Handle both string format (old) and object format (new with why/impact/implementation)
                if (is_string($s)) {
                    $suggestion_text = $s;
                    $has_details = false;
                } else {
                    $suggestion_text = isset($s['text']) ? $s['text'] : '';
                    $has_details = !empty($s['why']) || !empty($s['impact']) || !empty($s['implementation']);
                }
                
                if ( ! empty( $suggestion_text ) ) {
                    if ($has_details) {
                        // New detailed format with styled card
                        $html .= '<div style="background: #f9fafb; padding: 20px; border-radius: 10px; margin-bottom: 16px; border-left: 4px solid #2563eb; page-break-inside: avoid; break-inside: avoid;">
                            <div style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px;">
                                <span style="flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #2563eb; color: #ffffff; border-radius: 50%; font-size: 14px; font-weight: 700;">'.$counter.'</span>
                                <h4 style="margin: 0; color: #1e3a5f; font-size: 16px; font-weight: 700; line-height: 1.4;">'.esc_html($suggestion_text).'</h4>
                            </div>';
                        
                        if (!empty($s['why'])) {
                            $html .= '<div style="margin-left: 40px; margin-bottom: 10px;">
                                <div style="font-size: 12px; font-weight: 700; color: #2563eb; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Why This Matters</div>
                                <p style="margin: 0; font-size: 14px; color: #4b5563; line-height: 1.6;">'.nl2br(esc_html($s['why'])).'</p>
                            </div>';
                        }
                        
                        if (!empty($s['impact'])) {
                            $html .= '<div style="margin-left: 40px; margin-bottom: 10px;">
                                <div style="font-size: 12px; font-weight: 700; color: #059669; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Expected Impact</div>
                                <p style="margin: 0; font-size: 14px; color: #4b5563; line-height: 1.6;">'.nl2br(esc_html($s['impact'])).'</p>
                            </div>';
                        }
                        
                        if (!empty($s['implementation'])) {
                            $html .= '<div style="margin-left: 40px;">
                                <div style="font-size: 12px; font-weight: 700; color: #7c3aed; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">How To Implement</div>
                                <p style="margin: 0; font-size: 14px; color: #4b5563; line-height: 1.6;">'.nl2br(esc_html($s['implementation'])).'</p>
                            </div>';
                        }
                        
                        $html .= '</div>';
                    } else {
                        // Old simple format
                        $html .= '<div style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; page-break-inside: avoid; break-inside: avoid;">
                            <span style="flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: #2563eb; color: #ffffff; border-radius: 50%; font-size: 13px; font-weight: 700;">'.$counter.'</span>
                            <p style="margin: 0; font-size: 14px; color: #374151; line-height: 1.6;">'.esc_html($suggestion_text).'</p>
                        </div>';
                    }
                    $counter++;
                }
            }
            $html .= '</div>';
        }

        $html .= '
            <div class="page-number">Page 3</div>
        </div>';

        // ============ PAGE 5: FEATURES & FUNCTIONALITY ============
        $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Additional Features & Functionality</h2>
                <span style="font-size: 14px; color: #6b7280;">'.$report_date.'</span>
            </div>
            
            <p style="font-size: 15px; color: #374151; margin-bottom: 30px; line-height: 1.8;">
                Based on your page analysis and industry best practices, here are advanced features that could significantly improve your conversion rates and user experience.
            </p>';

        // Functionality suggestions
        if ( ! empty( $data['functionality_suggestions'] ) && is_array( $data['functionality_suggestions'] ) ) {
            foreach ( $data['functionality_suggestions'] as $feature ) {
                $feature_title = $feature['title'] ?? 'Suggested Feature';
                $feature_desc = $feature['description'] ?? '';
                $feature_why = $feature['why'] ?? '';
                $feature_impact = $feature['impact'] ?? '';
                $feature_impl = $feature['implementation'] ?? '';
                
                $html .= '<div class="feature-card" style="page-break-inside: avoid; break-inside: avoid;">
                    <h4 class="feature-title">'.esc_html($feature_title).'</h4>
                    <p class="feature-desc">'.esc_html($feature_desc).'</p>';
                
                // Show detailed fields if available
                if (!empty($feature_why)) {
                    $html .= '<div style="margin-top: 12px; padding: 12px; background: #f0f9ff; border-radius: 6px; border-left: 3px solid #0891b2;">
                        <div style="font-size: 11px; font-weight: 700; color: #0891b2; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Why This Feature</div>
                        <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.5;">'.nl2br(esc_html($feature_why)).'</p>
                    </div>';
                }
                
                if (!empty($feature_impact)) {
                    $html .= '<div style="margin-top: 8px; padding: 12px; background: #f0fdf4; border-radius: 6px; border-left: 3px solid #059669;">
                        <div style="font-size: 11px; font-weight: 700; color: #059669; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Expected Impact</div>
                        <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.5;">'.nl2br(esc_html($feature_impact)).'</p>
                    </div>';
                }
                
                if (!empty($feature_impl)) {
                    $html .= '<div style="margin-top: 8px; padding: 12px; background: #faf5ff; border-radius: 6px; border-left: 3px solid #7c3aed;">
                        <div style="font-size: 11px; font-weight: 700; color: #7c3aed; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Implementation Guide</div>
                        <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.5;">'.nl2br(esc_html($feature_impl)).'</p>
                    </div>';
                }
                
                $html .= '</div>';
            }
        } else {
            // Default suggestions if none provided
            $default_features = [
                [
                    'title' => 'Live Chat Integration',
                    'description' => 'Add real-time chat support to answer visitor questions instantly and reduce bounce rates.',
                    'impact' => 'Can increase conversions by 15-30%'
                ],
                [
                    'title' => 'Exit-Intent Popups',
                    'description' => 'Capture leaving visitors with targeted offers or lead magnets before they exit your site.',
                    'impact' => 'Recover 10-15% of abandoning visitors'
                ],
                [
                    'title' => 'Social Proof Widgets',
                    'description' => 'Display recent purchases, testimonials, or user counts to build trust and urgency.',
                    'impact' => 'Boost trust and conversions by 20%'
                ],
                [
                    'title' => 'Personalized Recommendations',
                    'description' => 'Show relevant products or content based on visitor behavior and preferences.',
                    'impact' => 'Increase average order value by 25%'
                ]
            ];
            
            foreach ( $default_features as $feature ) {
                $html .= '<div class="feature-card">
                    <h4 class="feature-title">'.esc_html($feature['title']).'</h4>
                    <p class="feature-desc">'.esc_html($feature['description']).'</p>
                    <div class="feature-impact">Expected Impact: '.esc_html($feature['impact']).'</div>
                </div>';
            }
        }

        $html .= '
            <div class="page-number">Page 4</div>
        </div>';

        // ============ LAST PAGE: THANK YOU ============
        $html .= '
        <div class="page thank-you-page">
            <div class="thank-you-content">
                <h1 class="thank-you-title">Thank You</h1>
                
                <p class="thank-you-text">
                    We appreciate the opportunity to analyze your website and provide these insights. At Webtec, we\'re committed to helping businesses like yours achieve measurable growth through data-driven optimization.
                </p>
                
                <p class="thank-you-text">
                    If you have questions about this report or need assistance implementing these recommendations, our team is here to help.
                </p>
                
                <a href="https://trywebtec.com/contact" class="thank-you-cta">
                    Get Implementation Support
                </a>
                
                <div class="thank-you-footer">
                    '.$logo_html.'
                    <p style="margin-top: 20px;"><strong>Report Generated:</strong> '.$report_date.'</p>
                    <p style="margin-top: 15px;">
                        <strong>Webtec Digital Solutions</strong><br>
                        Email: support@trywebtec.com<br>
                        Web: https://trywebtec.com
                    </p>
                </div>
            </div>
        </div>';

        $html .= '</body></html>';

        error_log( '✅ HTML generation complete. Length: ' . strlen($html) . ' bytes' );
        
        // Free up memory after HTML generation
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Try using DOMPDF if available via Composer
        if ( class_exists( '\Dompdf\Dompdf' ) ) {
            try {
                error_log( '🔧 Using DOMPDF for PDF generation' );
                $dompdf = new \Dompdf\Dompdf();
                $dompdf->loadHtml( $html );
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                file_put_contents( $path, $dompdf->output() );
                $url = trailingslashit( $upload['baseurl'] ) . 'conversioniq/reports/' . $filename;
                error_log( '✅ PDF generated successfully: ' . $url );
                
                // Free memory
                unset($html, $dompdf);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                
                return array('success' => true, 'url' => $url, 'path' => $path);
            } catch ( Exception $e ) {
                error_log( '❌ DOMPDF Error: ' . $e->getMessage() );
            }
        } else {
            error_log( '⚠️ DOMPDF not available, using HTML fallback' );
        }

        // Alternative: Use WordPress built-in functionality to create better formatted HTML
        // that can be printed to PDF by the browser
        $fallback_path = $dir . str_replace('.pdf', '.html', $filename);
        
        // Add print stylesheet for better PDF conversion
        $print_ready_html = str_replace(
            '</head>',
            '<style>@media print { body { margin: 0; padding: 0; } .page { page-break-after: always; } .page:last-child { page-break-after: avoid; } }</style></head>',
            $html
        );
        
        file_put_contents( $fallback_path, $print_ready_html );
        
        // Free memory
        unset($html, $print_ready_html);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $url = trailingslashit( $upload['baseurl'] ) . 'conversioniq/reports/' . basename( $fallback_path );
        
        error_log( '✅ HTML report generated: ' . $url );
        
        return array(
            'success' => true, 
            'url' => $url, 
            'path' => $fallback_path, 
            'note' => 'HTML report generated. Open in browser and use Print > Save as PDF for PDF version.',
            'is_html' => true
        );
        
        } catch ( Exception $e ) {
            error_log( '❌ Report generation exception: ' . $e->getMessage() );
            error_log( '❌ Stack trace: ' . $e->getTraceAsString() );
            return array(
                'success' => false,
                'message' => 'Report generation failed: ' . $e->getMessage(),
            );
        }
    }
}
