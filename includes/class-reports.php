<?php
if (!defined('ABSPATH')) {
    exit;
}

class ConversionIQ_Reports
{
    public static function generate_pdf_for_audit($audit)
    {
        // Increase PHP limits to handle large HTML generation
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '120');
        @set_time_limit(120);

        // Enable output compression if available
        if (function_exists('ini_get') && ini_get('zlib.output_compression') == 0) {
            @ini_set('zlib.output_compression', '1');
        }

        // Log initial memory
        ciq_log('🔧 Report generation starting. Memory usage: ' . round(memory_get_usage() / 1024 / 1024, 2) . 'MB / ' . ini_get('memory_limit'));

        // $audit is the DB row (with decoded data)
        if (!$audit || !isset($audit['data'])) {
            ciq_log('❌ Report generation failed: Invalid audit data');
            return array(
                'success' => false,
                'message' => 'Invalid audit data',
            );
        }

        try {
            ciq_log('📄 Starting report generation for audit #' . $audit['id']);
            ciq_log('🔍 Available data keys: ' . json_encode(array_keys($audit['data'])));

            $upload = wp_upload_dir();
            $dir = trailingslashit($upload['basedir']) . 'conversioniq/reports/';
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }

            ciq_log('📁 Upload directory: ' . $dir);

            $data = $audit['data'];

            // Validate that $data is an array
            if (!is_array($data)) {
                ciq_log('❌ Report generation failed: audit data is not an array, got ' . gettype($data));
                return array(
                    'success' => false,
                    'message' => 'Audit data is not properly formatted',
                );
            }

            ciq_log('✅ Data is array, proceeding with detection and generation');

            // Normalize nested AI response paths — the AI returns insights/recommendations nested,
            // but older audits or flattened data may have them at top level. Support both.
            $insights = isset($data['insights']) && is_array($data['insights']) ? $data['insights'] : array();
            $recommendations = isset($data['recommendations']) && is_array($data['recommendations']) ? $data['recommendations'] : array();
            $rewrites = isset($data['rewrites']) && is_array($data['rewrites']) ? $data['rewrites'] : array();

            // Support flat keys from older audit data as fallback
            if (empty($insights) && isset($data['executive_summary'])) {
                $insights = array(
                    'executive_summary' => $data['executive_summary'] ?? '',
                    'top_priority_insight' => $data['top_priority_insight'] ?? '',
                    'strengths' => $data['strengths'] ?? array(),
                    'weaknesses' => $data['weaknesses'] ?? array(),
                    'opportunities' => $data['opportunities'] ?? array(),
                    'audience_alignment' => $data['audience_alignment'] ?? '',
                );
            }

            // Initialize page counter for dynamic numbering
            $page_num = 0;
            
            // SIMPLIFIED APPROACH: Check if site is Spanish-language
            // If the site URL or domain contains Spanish indicators, use HTML
            $site_url = get_site_url();
            $site_lang = get_locale(); // Returns 'es_ES', 'es_MX', etc for Spanish
            $is_spanish_site = (strpos($site_lang, 'es_') === 0) || (strpos($site_url, 'pastelesincreibles') !== false);
            
            if ($is_spanish_site) {
                ciq_log('🌍 SPANISH SITE DETECTED - Language: ' . $site_lang . ' | URL: ' . $site_url);
                ciq_log('🌍 Forcing HTML output for all Spanish site audits');
            }
            
            $force_html_early = $is_spanish_site;
            
            // Generate clean filename - use .html extension if forcing HTML
            $date_stamp = date('Y-m-d');
            $audit_id = isset($audit['id']) ? $audit['id'] : time();
            $page_id = isset($audit['page_id']) ? $audit['page_id'] : 'unknown';
            $extension = $force_html_early ? '.html' : '.pdf';
            $filename = 'ConversionIQ-Audit-' . $page_id . '-' . $audit_id . '-' . $date_stamp . $extension;
            $path = $dir . $filename;
            ciq_log('📄 Target filename: ' . $filename . ' (Spanish site: ' . ($is_spanish_site ? 'YES' : 'NO') . ')');

            $report_date = date('F j, Y');
            $page_name = esc_html($audit['page_title']);
            $page_url = isset($audit['page_url']) ? esc_url($audit['page_url']) : '';

            // Get saved business settings if available
            $business = json_decode(get_option('conversion_iq_settings', '{}'), true);

            // Get account information for company name and website
            $account = get_option('conversioniq_account', null);
            $company_name = isset($account['company']) ? esc_html($account['company']) : '';
            $website_url = isset($account['site_url']) ? esc_url($account['site_url']) : get_site_url();

            // Brand colors and info from config manager
            // Only apply custom branding if the plan allows it
            if (ConversionIQ_Config_Manager::can('custom_branding')) {
                $branding = ConversionIQ_Config_Manager::get_branding();
            } else {
                // Starter plan: always use default branding
                $branding = array(
                    'primary_color'  => '#09090b',
                    'accent_color'   => '#f59e0b',
                    'light_color'    => '#1a1100',
                    'company_name'   => 'Webtec',
                    'product_name'   => 'Conversion IQ',
                    'support_email'  => 'support@trywebtec.com',
                    'website_url'    => 'https://trywebtec.com',
                    'contact_url'    => 'https://trywebtec.com/contact',
                );
            }
            $webtec_navy = $branding['primary_color'];
            $webtec_blue = $branding['accent_color'];
            $webtec_light_blue = $branding['light_color'];
            $brand_company = esc_html($branding['company_name']);
            $brand_product = esc_html($branding['product_name']);
            $brand_support_email = esc_attr($branding['support_email']);
            $brand_website_url = esc_url($branding['website_url']);
            $brand_contact_url = esc_url($branding['contact_url']);

            // Logo from config manager (handles remote URL, bundled file, or text fallback)
            $logo_html = ConversionIQ_Config_Manager::get_logo_html();

            // Determine if this is a free plan — controls which report sections are gated.
            // SECURITY: For free plans, real AI content is NEVER written to the HTML document.
            // Gated sections only contain generic fake placeholder items (CSS-blurred), so
            // viewing the page source reveals nothing about the actual audit results.
            $is_free_plan = (ConversionIQ_Config_Manager::get_plan() === 'free');

            // Helper closure: generate a locked section block with blurred fake placeholder items.
            // $section_title string  — section heading
            // $subtitle      string  — subheading shown above the gate
            // $fake_items    array   — array of ['title'=>..., 'body'=>...] placeholder cards
            // $accent_color  string  — top border colour for placeholder cards
            $gated_block = function ($section_title, $subtitle, array $fake_items, $accent_color) use ($brand_contact_url) {
                $out  = '<div class="section" style="page-break-inside:avoid;break-inside:avoid;">';
                $out .= '<h3 class="section-title" style="display:flex;align-items:center;gap:10px;">'
                    . esc_html($section_title)
                    . ' <span style="font-size:11px;font-weight:600;color:#7c3aed;background:#f3e8ff;'
                    . 'padding:3px 10px;border-radius:6px;">&#x1F512; Premium</span></h3>';
                if ($subtitle) {
                    $out .= '<p style="font-size:14px;color:#6b7280;margin-bottom:16px;line-height:1.6;">'
                        . esc_html($subtitle) . '</p>';
                }
                $out .= '<div class="premium-gate-wrapper">';
                $out .= '<div class="premium-gate-blurred">';
                foreach ($fake_items as $item) {
                    $out .= '<div style="background:white;padding:20px;border-radius:10px;margin-bottom:14px;'
                        . 'border:1px solid #e2e8f0;border-top:3px solid ' . esc_attr($accent_color) . ';">';
                    $out .= '<h4 style="margin:0 0 8px 0;color:#1e293b;font-size:15px;font-weight:700;">'
                        . esc_html($item['title']) . '</h4>';
                    $out .= '<p style="margin:0;font-size:13px;color:#475569;line-height:1.7;">'
                        . esc_html($item['body']) . '</p>';
                    $out .= '</div>';
                }
                $out .= '</div>'; // .premium-gate-blurred
                $out .= '<div class="premium-gate-overlay">';
                $out .= '<div style="text-align:center;max-width:360px;">';
                $out .= '<div style="font-size:28px;margin-bottom:10px;">&#x1F512;</div>';
                $out .= '<div style="font-size:17px;font-weight:700;color:#1e293b;margin-bottom:6px;">See the complete breakdown</div>';
                $out .= '<div style="font-size:13px;color:#6b7280;margin-bottom:18px;line-height:1.6;">'
                    . 'More tailored recommendations for this page are included with any paid plan.</div>';
                $out .= '<a href="' . esc_url($brand_contact_url) . '" '
                    . 'style="display:inline-block;background:#7c3aed;color:white;padding:12px 28px;'
                    . 'border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">'
                    . 'View Upgrade Options &rarr;</a>';
                $out .= '</div></div></div></div>';
                return $out;
            };

            // Modern multi-page report HTML with Webtec branding
            $html = '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Website Conversion Audit Report</title>
    <style>
        @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap\');
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
            font-family: \'Inter\', "Helvetica Neue", Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            color: #0f1f3d;
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
            background: linear-gradient(160deg, #0f1f3d 0%, #1d4ed8 100%);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 60px 80px;
            position: relative;
            overflow: hidden;
        }
        .cover-page::before {
            content: \'\';
            position: absolute;
            bottom: -120px;
            right: -120px;
            width: 520px;
            height: 520px;
            border-radius: 50%;
            background: rgba(255,255,255,0.04);
            pointer-events: none;
        }
        .cover-page::after {
            content: \'\';
            position: absolute;
            top: -60px;
            right: 200px;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255,255,255,0.03);
            pointer-events: none;
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
            font-size: 17px;
            padding: 16px 24px;
            background: rgba(255,255,255,0.11);
            margin-bottom: 40px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.2);
            font-weight: 400;
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
            padding: 55px 80px 75px 80px;
            border-top: 5px solid #1d4ed8;
        }
        .page-header {
            border-bottom: 1px solid #e8ecf0;
            padding-bottom: 20px;
            margin-bottom: 40px;
        }
        .page-number {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 11px 70px;
            border-top: 1px solid #f0f4f8;
            background: #fafbfc;
            font-size: 11px;
            color: #9ca3af;
            font-weight: 500;
            letter-spacing: 0.2px;
            overflow: hidden;
            text-align: right;
        }
        .page-number::before {
            content: \'' . esc_attr($brand_product) . ' \2014  ' . esc_attr($brand_company) . '\';
            float: left;
            color: #9ca3af;
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
            background: #f0f7ff;
            padding: 30px;
            border-radius: 10px;
            border: 1px solid #bfdbfe;
            border-top: 3px solid #2563eb;
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
            padding: 50px 70px 75px 70px;
            border-top: 5px solid #1d4ed8;
        }
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            border-bottom: 1px solid #e8ecf0;
            margin-bottom: 40px;
        }
        .content-header h2 {
            font-size: 26px;
            color: #0f1f3d;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
            break-inside: avoid;
            orphans: 2;
            widows: 2;
        }
        .section-title {
            font-size: 19px;
            color: #0f1f3d;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 8px;
            letter-spacing: -0.3px;
            border-bottom: 1px solid #f0f4f8;
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
            background: #ffffff;
            padding: 18px;
            border-radius: 10px;
            border: 1px solid #e8ecf0;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .score-card.clarity { border-top: 3px solid #2563eb; }
        .score-card.emotional { border-top: 3px solid #f59e0b; }
        .score-card.cta { border-top: 3px solid #10b981; }
        .score-card.readability { border-top: 3px solid #9333ea; }
        .score-card.engagement { border-top: 3px solid #d97706; }
        .score-card.trust { border-top: 3px solid #0891b2; }
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
        .score-bar-label {
            font-size: 11px;
            color: #9ca3af;
            font-weight: 600;
            margin-top: 7px;
            text-align: center;
            letter-spacing: 0.2px;
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
            border: 1px solid #e2e8f0;
            border-top: 3px solid #2563eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
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
            background: #ffffff;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 10px;
            border: 1px solid #e8ecf0;
            border-top: 3px solid #9333ea;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .feature-category {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 3px 10px;
            border-radius: 12px;
            margin-bottom: 10px;
            background: #ede9fe;
            color: #7c3aed;
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
            align-items: center;
            background: #ffffff;
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
        /* Premium section gate — free plan reports only.
           IMPORTANT: real AI content is NEVER embedded in free-plan HTML.
           Only generic fake placeholder items are rendered here, then blurred via CSS,
           so inspecting the page source reveals nothing about the actual audit results. */
        .premium-gate-wrapper {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            min-height: 220px;
        }
        .premium-gate-blurred {
            filter: blur(6px);
            -webkit-filter: blur(6px);
            user-select: none;
            pointer-events: none;
        }
        .premium-gate-overlay {
            position: absolute;
            left: 0; right: 0; top: 0; bottom: 0;
            background: linear-gradient(to bottom, rgba(255,255,255,0) 0%, rgba(255,255,255,0.88) 28%, rgba(255,255,255,0.99) 58%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            padding: 32px 24px;
            z-index: 10;
        }
    </style>
</head>
<body>';

            // ============ PAGE 1: COVER PAGE ============
            $html .= '
        <div class="page cover-page">
            <div>
                ' . $logo_html . '
            </div>
            <div>
                <h1 class="cover-title">Website Conversion<br>Audit Report</h1>
                <p class="cover-subtitle">Professional Analysis & Recommendations</p>';

            // Add company information if available
            if ($company_name) {
                $html .= '
                <div class="cover-company-info">';
                $html .= '
                    <div class="cover-company-name">' . $company_name . '</div>';
                $html .= '
                </div>';
            }

            $html .= '
                <div class="cover-page-name">
                    <strong>Page Analyzed:</strong> ' . $page_name . '
                </div>
                ' . ($page_url ? '<div class="cover-page-url" style="margin-top: 8px; font-size: 14px; color: #dbeafe;">' . $page_url . '</div>' : '') . '
            </div>
            <div class="cover-meta">
                <p><strong>Prepared by:</strong> ' . $brand_company . '</p>
                <p class="cover-date">Report Date: ' . $report_date . '</p>
            </div>
        </div>';

            // ============ PAGE 2: INTRODUCTION ============
            $html .= '
        <div class="page intro-page">
            <div class="page-header">
                ' . $logo_html . '
            </div>
            
            <h1 class="intro-title">About This Report</h1>
            
            <p class="intro-text">
                Thank you for choosing ' . $brand_company . '\'s ' . $brand_product . ' platform to analyze your website. This comprehensive audit has been designed to help you understand how your site performs in key areas that directly impact visitor engagement and conversion rates.
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
            
            <div class="intro-box" style="background: #f0f7ff; padding: 30px; page-break-inside: avoid; break-inside: avoid;">
                <h3 style="color: #1e3a5f; margin: 0 0 12px 0; font-size: 20px;">Business Context for This Analysis</h3>
                <p style="font-size: 15px; color: #374151; line-height: 1.6; margin-bottom: 20px; white-space: normal; word-wrap: break-word;">
                    This report has been tailored to your site and industry by considering the following:
                </p>
                
                <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); page-break-inside: avoid; break-inside: avoid;">
                    <div style="display: grid; gap: 12px;">
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Industry/Niche</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">' . (!empty($business['industry']) ? esc_html($business['industry']) : '<span style="color: #9ca3af;">Not specified</span>') . '</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">What You Sell</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">' . (!empty($business['product']) ? esc_html($business['product']) : '<span style="color: #9ca3af;">Not specified</span>') . '</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Target Audience</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">' . (!empty($business['audience']) ? esc_html($business['audience']) : '<span style="color: #9ca3af;">Not specified</span>') . '</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Customer Pain Points</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">' . (!empty($business['pain_points']) ? esc_html($business['pain_points']) : '<span style="color: #9ca3af;">Not specified</span>') . '</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Key Competitors</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">' . (!empty($business['competitors']) ? esc_html($business['competitors']) : '<span style="color: #9ca3af;">Not specified</span>') . '</div>
                        </div>
                        
                        <div style="height: 1px; background: #e5e7eb;"></div>
                        
                        <div>
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 600;">Primary Conversion Goal</div>
                            <div style="font-size: 16px; color: #1e3a5f; font-weight: 700; white-space: normal; word-wrap: break-word;">' . (!empty($business['goal']) ? esc_html($business['goal']) : '<span style="color: #9ca3af;">Not specified</span>') . '</div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 16px; padding: 14px; background: #fffbeb; border-radius: 8px; border: 1px solid #fde68a; border-top: 2px solid #f59e0b; page-break-inside: avoid; break-inside: avoid;">
                    <p style="font-size: 13px; color: #92400e; line-height: 1.6; margin: 0; white-space: normal; word-wrap: break-word;">
                        We know this information will change over time. Let us know so we can update our database and ensure your recommendations are tailored correctly for you.
                    </p>
                </div>
            </div>
            
            <p class="intro-text">
                Our team at ' . $brand_company . ' is here to help you implement these changes and achieve your digital marketing goals. Let\'s dive into your results.
            </p>
            
            <div class="page-number">Page ' . (++$page_num) . '</div>
        </div>';

            // ============ TABLE OF CONTENTS ============
            // Determine which conditional sections will appear
            $has_rewrites_for_toc = !empty($rewrites) && is_array($rewrites);
            if ($has_rewrites_for_toc) {
                $has_rewrites_for_toc = false;
                $rw_check_keys = array("headline","subheadline","value_proposition","primary_cta","secondary_cta","social_proof_intro","feature_1","feature_2","feature_3","faq_answer_1","closing_statement");
                foreach ($rw_check_keys as $rk) {
                    if (!empty($rewrites[$rk])) { $has_rewrites_for_toc = true; break; }
                }
            }

            $toc_items = array(
                array("title" => "Executive Summary", "desc" => "Overall score, key insights, and competitive benchmarks"),
                array("title" => "Performance Analysis", "desc" => "Detailed scores, trends, and metric breakdowns"),
            );
            // Lead Intelligence is conditional
            $has_lead_data_for_toc = isset($data['webhook_stats']) || !empty(get_option('conversioniq_knockknock_api_key', ''));
            if ($has_lead_data_for_toc) {
                $toc_items[] = array("title" => "Growth Machine Analysis", "desc" => "Visitor intelligence, company identification, and geographic data");
            }
            $toc_items[] = array("title" => "Features & Functionality", "desc" => "Recommended features to boost conversion rates");
            if ($has_rewrites_for_toc) {
                $toc_items[] = array("title" => "Suggested Copy Rewrites", "desc" => "AI-generated alternative copy for key page sections");
            }
            $toc_items[] = array("title" => "Next Steps", "desc" => "How to get started with implementation");

            $html .= '
        <div class="page content-page" style="background: #f8fafc;">
            <div style="padding: 48px 56px 0;">
                <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 8px;">
                    <h2 style="font-size: 28px; font-weight: 800; color: ' . $webtec_navy . '; letter-spacing: -0.5px;">Table of Contents</h2>
                    <span style="font-size: 13px; color: #94a3b8; font-weight: 500;">' . $report_date . '</span>
                </div>
                <div style="width: 48px; height: 4px; background: linear-gradient(90deg, ' . $webtec_blue . ', ' . $webtec_navy . '); border-radius: 2px; margin-bottom: 40px;"></div>
            </div>

            <div style="padding: 0 56px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';

            $toc_num = 1;
            foreach ($toc_items as $idx => $toc_item) {
                $is_last_odd = (count($toc_items) % 2 !== 0) && ($toc_num === count($toc_items));
                $col_span = $is_last_odd ? ' style="grid-column: 1 / -1;"' : '';
                $html .= '
                <div' . $col_span . '>
                    <div style="background: #fff; border-radius: 14px; padding: 24px; border: 1px solid #e8edf2; box-shadow: 0 1px 4px rgba(15,31,61,0.05); height: 100%;">
                        <div style="width: 36px; height: 36px; border-radius: 8px; background: linear-gradient(135deg, ' . $webtec_blue . ', ' . $webtec_navy . '); color: #fff; font-size: 16px; font-weight: 800; display: flex; align-items: center; justify-content: center; margin-bottom: 14px;">' . $toc_num . '</div>
                        <div style="font-size: 15px; font-weight: 700; color: ' . $webtec_navy . '; margin-bottom: 6px; line-height: 1.3;">' . esc_html($toc_item["title"]) . '</div>
                        <div style="font-size: 12px; color: #64748b; line-height: 1.6;">' . esc_html($toc_item["desc"]) . '</div>
                        <div style="margin-top: 14px; width: 100%; height: 2px; background: linear-gradient(90deg, ' . $webtec_blue . '44, transparent); border-radius: 1px;"></div>
                    </div>
                </div>';
                $toc_num++;
            }

            $html .= '
            </div>
            <div class="page-number">Page ' . (++$page_num) . '</div>
        </div>';

            // ============ EXECUTIVE SUMMARY ============
            // Calculate overall score using the same weighted formula as the AI engine and frontend
            // Weights: clarity 20%, cta 20%, emotional 15%, readability 15%, engagement 15%, trust 15%
            $clarity_val = intval($data['clarity_score'] ?? 0);
            $emotional_val = intval($data['emotional_score'] ?? 0);
            $cta_val = intval($data['cta_strength'] ?? 0);
            $readability_val = intval($data['readability_score'] ?? 0);
            $engagement_val = intval($data['engagement_score'] ?? 0);
            $trust_val = intval($data['trust_score'] ?? 0);

            // Use server-computed overall_score if available, otherwise compute weighted average
            if (!empty($data['overall_score']) && intval($data['overall_score']) > 0) {
                $overall_score = intval($data['overall_score']);
            } else {
                $overall_score = round(
                    $clarity_val * 0.20 +
                    $emotional_val * 0.15 +
                    $cta_val * 0.20 +
                    $readability_val * 0.15 +
                    $engagement_val * 0.15 +
                    $trust_val * 0.15
                );
            }

            $status = $overall_score >= 85 ? 'Excellent' : ($overall_score >= 75 ? 'Good' : ($overall_score >= 60 ? 'Fair' : 'Needs Improvement'));
            $status_color = $overall_score >= 85 ? '#10b981' : ($overall_score >= 75 ? '#2563eb' : ($overall_score >= 60 ? '#f59e0b' : '#ef4444'));

            // Find lowest score for priority action
            $score_values = [
                'Clarity' => $clarity_val,
                'Emotional Connection' => $emotional_val,
                'CTA Strength' => $cta_val,
                'Readability' => $readability_val,
                'Engagement' => $engagement_val,
                'Trust Signals' => $trust_val,
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
            $historical = $wpdb->get_results($wpdb->prepare(
                "SELECT data, created_at FROM $table WHERE page_id = %d ORDER BY created_at DESC LIMIT 5",
                $audit['page_id']
            ), ARRAY_A);

            // Extract benchmark research data from AI analysis
            $industry_avg = null;
            $top_performers = null;
            $conversion_lift = '';
            $competitive_factors = array();
            $industry_challenges = array();
            $competitive_context = '';

            if (isset($data['benchmark_research']) && is_array($data['benchmark_research'])) {
                $benchmark = $data['benchmark_research'];
                $industry_avg = isset($benchmark['industry_average']) ? intval($benchmark['industry_average']) : null;
                $top_performers = isset($benchmark['top_performers_threshold']) ? intval($benchmark['top_performers_threshold']) : null;
                $conversion_lift = isset($benchmark['conversion_rate_lift_per_10_points']) ? $benchmark['conversion_rate_lift_per_10_points'] : '';
                $competitive_factors = isset($benchmark['key_competitive_factors']) && is_array($benchmark['key_competitive_factors']) ? $benchmark['key_competitive_factors'] : array();
                $industry_challenges = isset($benchmark['industry_challenges']) && is_array($benchmark['industry_challenges']) ? $benchmark['industry_challenges'] : array();
                $competitive_context = isset($benchmark['competitive_context']) ? strval($benchmark['competitive_context']) : '';
            }

            $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Executive Summary</h2>
                <span style="font-size: 14px; color: #6b7280;">' . $report_date . '</span>
            </div>
            
            <!-- Overall Score Card -->';
            // SVG radial gauge — 160px circle, stroke-dasharray trick for the arc
            $gauge_radius = 70;
            $gauge_circumference = 2 * 3.14159 * $gauge_radius; // ~439.8
            $gauge_offset = $gauge_circumference - ($gauge_circumference * $overall_score / 100);

            $html .= '
            <div style="background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%); padding: 30px; border-radius: 12px; margin-bottom: 20px; text-align: center; border: 2px solid ' . esc_attr($status_color) . '; page-break-inside: avoid;">
                <svg width="180" height="180" viewBox="0 0 180 180" style="display: block; margin: 0 auto 16px;">
                    <circle cx="90" cy="90" r="' . $gauge_radius . '" fill="none" stroke="#e5e7eb" stroke-width="12" />
                    <circle cx="90" cy="90" r="' . $gauge_radius . '" fill="none" stroke="' . esc_attr($status_color) . '" stroke-width="12"
                        stroke-dasharray="' . round($gauge_circumference, 2) . '" stroke-dashoffset="' . round($gauge_offset, 2) . '"
                        stroke-linecap="round" transform="rotate(-90 90 90)" />
                    <text x="90" y="82" text-anchor="middle" font-size="44" font-weight="800" fill="' . esc_attr($status_color) . '" font-family="Inter, Helvetica, Arial, sans-serif">' . $overall_score . '</text>
                    <text x="90" y="108" text-anchor="middle" font-size="14" fill="#6b7280" font-family="Inter, Helvetica, Arial, sans-serif">out of 100</text>
                </svg>
                <div style="font-size: 20px; font-weight: 700; color: #1e3a5f; margin-bottom: 6px;">' . $status . ' Performance</div>
                <div style="font-size: 14px; color: #6b7280;">' . ($overall_score > 55 ? 'Your website shows ' . strtolower($status) . ' performance with opportunities for growth' : 'Our analysis has identified multiple high-impact opportunities to strengthen your conversion performance') . '</div>
            </div>';

            $html .= '
            
            <!-- Key Insights -->';

            // Check if we have AI-generated insights (support both nested and flat paths)
            $exec_summary = !empty($insights['executive_summary']) ? $insights['executive_summary'] : '';
            $ai_strengths = !empty($insights['strengths']) && is_array($insights['strengths']) ? $insights['strengths'] : array();
            $ai_weaknesses = !empty($insights['weaknesses']) && is_array($insights['weaknesses']) ? $insights['weaknesses'] : array();
            $ai_opportunities = !empty($insights['opportunities']) && is_array($insights['opportunities']) ? $insights['opportunities'] : array();
            $ai_audience = !empty($insights['audience_alignment']) ? $insights['audience_alignment'] : '';

            $has_ai_insights = !empty($exec_summary);

            if ($has_ai_insights) {
                if ($is_free_plan) {
                    // Free plan: show first 2 sentences of the executive summary, then gate the rest
                    // Split on sentence boundaries to get a natural cutoff
                    $exec_sentences = preg_split('/(?<=[.!?])\s+/', trim($exec_summary), -1, PREG_SPLIT_NO_EMPTY);
                    $exec_teaser = implode(' ', array_slice($exec_sentences, 0, 2));
                    $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Key Insights</h3>';
                    $html .= '<div style="background: #f8fbff; padding: 20px; border-radius: 10px; margin-bottom: 16px; border: 1px solid #bae6fd; border-top: 3px solid #0891b2; page-break-inside: avoid; break-inside: avoid;">
                    <h4 style="color: #0891b2; font-size: 16px; margin-bottom: 12px; font-weight: 700;">&#128202; Executive Summary</h4>
                    <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin: 0 0 6px 0;">' . nl2br(esc_html($exec_teaser)) . '</p>
                    <span style="font-size: 13px; color: #7c3aed; font-weight: 600;">&#x1F512; Full analysis available with upgrade &hellip;</span>
                </div>';
                    // Gate the strengths / weaknesses / opportunities breakdown
                    $html .= '<div class="premium-gate-wrapper">';
                    $html .= '<div class="premium-gate-blurred">';
                    $html .= '<div style="background:#f0fdf8;padding:16px;border-radius:10px;border:1px solid #d1fae5;border-top:3px solid #10b981;margin-bottom:12px;">'
                        . '<h4 style="color:#10b981;font-size:14px;margin:0 0 8px 0;font-weight:700;">&#127919; Top Strengths</h4>'
                        . '<p style="margin:0;font-size:13px;color:#1e293b;line-height:1.6;">Your page demonstrates notable strengths in several critical conversion areas that are working in your favour and should be preserved during any updates.</p>'
                        . '</div>';
                    $html .= '<div style="background:#fff7ed;padding:16px;border-radius:10px;border:1px solid #fed7aa;border-top:3px solid #f59e0b;margin-bottom:12px;">'
                        . '<h4 style="color:#f59e0b;font-size:14px;margin:0 0 8px 0;font-weight:700;">&#128161; Weaknesses &amp; Opportunities</h4>'
                        . '<p style="margin:0;font-size:13px;color:#1e293b;line-height:1.6;">Specific friction points were identified in your messaging and user journey that are reducing conversion rates. Addressing these represents your highest-leverage growth opportunity.</p>'
                        . '</div>';
                    $html .= '<div style="background:#f5f3ff;padding:16px;border-radius:10px;border:1px solid #ddd6fe;border-top:3px solid #8b5cf6;">'
                        . '<h4 style="color:#8b5cf6;font-size:14px;margin:0 0 8px 0;font-weight:700;">&#128101; Audience Alignment</h4>'
                        . '<p style="margin:0;font-size:13px;color:#1e293b;line-height:1.6;">A detailed assessment of how well your current messaging resonates with your target audience, including specific language and positioning recommendations.</p>'
                        . '</div>';
                    $html .= '</div>'; // .premium-gate-blurred
                    $html .= '<div class="premium-gate-overlay">';
                    $html .= '<div style="text-align:center;max-width:360px;">';
                    $html .= '<div style="font-size:20px;margin-bottom:8px;">&#x1F512;</div>';
                    $html .= '<div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:6px;">See the complete breakdown</div>';
                    $html .= '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;line-height:1.6;">Strengths, weaknesses, and audience alignment — fully tailored to your page, included with any paid plan.</div>';
                    $html .= '<a href="' . esc_url($brand_contact_url) . '" style="display:inline-block;background:#7c3aed;color:white;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">View Upgrade Options &rarr;</a>';
                    $html .= '</div></div></div>';
                    $html .= '</div>';
                } else {
                    // Use AI-generated insights (paid plans)
                    $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Key Insights</h3>';

                    $html .= '<div style="background: #f8fbff; padding: 20px; border-radius: 10px; margin-bottom: 16px; border: 1px solid #bae6fd; border-top: 3px solid #0891b2; page-break-inside: avoid; break-inside: avoid;">
                    <h4 style="color: #0891b2; font-size: 16px; margin-bottom: 12px; font-weight: 700;">📊 Executive Summary</h4>
                    <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin: 0;">' . nl2br(esc_html($exec_summary)) . '</p>
                </div>';

                    $html .= '</div>';
                }
            }
            else {
                // Fallback to static template for older audits
                $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Key Insights</h3>
                
                <div style="display: grid; gap: 15px;">
                    <!-- Priority Action -->
                    <div style="background: #fffbf5; padding: 16px; border-radius: 10px; border: 1px solid #fde68a; border-top: 3px solid #f59e0b; page-break-inside: avoid; break-inside: avoid;">
                        <h4 style="color: #f59e0b; font-size: 16px; margin-bottom: 10px; font-weight: 700;">⚡ Top Priority Action</h4>
                        <p style="font-size: 14px; color: #374151; line-height: 1.6; margin-bottom: 10px;">
                            <strong>Focus Area:</strong> ' . $lowest_area . ' (currently scoring ' . $score_values[$lowest_area] . ' out of 100)
                        </p>
                        <p style="font-size: 13px; color: #374151; line-height: 1.6; margin: 0;">
                            This represents your greatest opportunity for improvement. By addressing the issues identified, you can expect an improvement of approximately <strong>+' . $potential_gain . ' points</strong>. ' . $lowest_area . ' directly impacts how visitors perceive your offer and make decisions.
                        </p>
                    </div>
                </div>
                
                <!-- Insight Cards Grid -->
                <div style="display: table; width: 100%; table-layout: fixed; margin-top: 15px;">
                    <div style="display: table-row;">
                        <!-- Top Strength Card -->
                        <div style="display: table-cell; width: 33.33%; padding-right: 8px; vertical-align: top;">
                            <div style="background: #f0fdf8; padding: 16px; border-radius: 10px; border: 1px solid #d1fae5; border-top: 3px solid #10b981; height: 100%; page-break-inside: avoid; break-inside: avoid;">
                                <h4 style="color: #10b981; font-size: 15px; margin-bottom: 10px; font-weight: 700;">🎯 Top Strength</h4>
                                <p style="font-size: 13px; color: #1e293b; line-height: 1.6; margin: 0;">
                                    Your <strong>' . $highest_area . ' (' . $highest_score . '/100)</strong> shows strong performance. This solid foundation helps keep visitors engaged and moving toward conversion.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Biggest Opportunity Card -->
                        <div style="display: table-cell; width: 33.33%; padding-left: 4px; padding-right: 4px; vertical-align: top;">
                            <div style="background: #fffbf0; padding: 16px; border-radius: 10px; border: 1px solid #fde68a; border-top: 3px solid #f59e0b; height: 100%; page-break-inside: avoid; break-inside: avoid;">
                                <h4 style="color: #f59e0b; font-size: 15px; margin-bottom: 10px; font-weight: 700;">💡 Biggest Opportunity</h4>
                                <p style="font-size: 13px; color: #1e293b; line-height: 1.6; margin: 0;">
                                    Your <strong>' . $second_lowest_area . ' (' . $second_lowest_score . '/100)</strong> could better connect with your target audience. Improvements here typically lift conversions by 20-30%.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Quick Win Card -->
                        <div style="display: table-cell; width: 33.33%; padding-left: 8px; vertical-align: top;">
                            <div style="background: #f5f3ff; padding: 16px; border-radius: 10px; border: 1px solid #ddd6fe; border-top: 3px solid #8b5cf6; height: 100%; page-break-inside: avoid; break-inside: avoid;">
                                <h4 style="color: #8b5cf6; font-size: 15px; margin-bottom: 10px; font-weight: 700;">🚀 Quick Win</h4>
                                <p style="font-size: 13px; color: #1e293b; line-height: 1.6; margin: 0;">
                                    Strengthening your <strong>' . $third_lowest_area . ' (' . $third_lowest_score . '/100)</strong> with targeted improvements could yield immediate results in conversion rates.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
            }

            // Benchmark Explanation Section - only show if we have AI-generated benchmark data
            if ($industry_avg !== null && $top_performers !== null) {
                if ($is_free_plan) {
                    // Free plan: show the real score comparison, then gate the competitive analysis text
                    $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Understanding Your Benchmark Score</h3>
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%); padding: 24px; border-radius: 12px; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #e5e7eb;">
                    <h4 style="color: #1e3a5f; font-size: 16px; margin-bottom: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">' . (!empty($business['industry']) ? esc_html($business['industry']) . ' Industry' : 'Industry') . ' Benchmark</h4>
                    <p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin-bottom: 20px;">How your page scores against similar businesses in your market.</p>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 30px; margin-bottom: 0;">
                        <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); border-radius: 10px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);">
                            <div style="font-size: 12px; color: rgba(255,255,255,0.8); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Your Score</div>
                            <div style="font-size: 48px; font-weight: 800; color: #ffffff; line-height: 1;">' . $overall_score . '</div>
                        </div>
                        <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%); border-radius: 10px; border: 2px solid #f59e0b;">
                            <div style="font-size: 12px; color: #92400e; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">' . (!empty($business['industry']) ? esc_html($business['industry']) : 'Industry') . ' Average</div>
                            <div style="font-size: 48px; font-weight: 800; color: #f59e0b; line-height: 1;">' . $industry_avg . '</div>
                        </div>
                        <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%); border-radius: 10px; border: 2px solid #8b5cf6;">
                            <div style="font-size: 12px; color: #6d28d9; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Top Performers</div>
                            <div style="font-size: 48px; font-weight: 800; color: #7c3aed; line-height: 1;">' . $top_performers . '</div>
                        </div>
                    </div>
                </div>';
                    // Score improvement projection
                    $score_weights_proj = array(
                        'Clarity'              => 0.20,
                        'Emotional Connection' => 0.15,
                        'CTA Strength'        => 0.20,
                        'Readability'         => 0.15,
                        'Engagement'          => 0.15,
                        'Trust Signals'       => 0.15,
                    );
                    $proj_score = 0;
                    foreach ($score_values as $_pk => $_pv) {
                        $_pw = $score_weights_proj[$_pk] ?? 0;
                        $_pv_adj = ($_pk === $lowest_area || $_pk === $second_lowest_area) ? max((int)$_pv, $industry_avg) : (int)$_pv;
                        $proj_score += $_pv_adj * $_pw;
                    }
                    $proj_score = round($proj_score);
                    if ($proj_score > $overall_score) {
                        $score_lift = $proj_score - $overall_score;
                        $html .= '<div style="background: #f0fdf4; border-radius: 10px; padding: 16px 20px; border: 1px solid #d1fae5; border-left: 4px solid #10b981; margin-bottom: 12px; page-break-inside: avoid;">'
                            . '<p style="margin: 0; font-size: 13px; color: #065f46; line-height: 1.7;"><strong>Score projection:</strong> Raising your two lowest-scoring areas to the industry average would move your overall score from <strong>' . $overall_score . '</strong> to approximately <strong>' . $proj_score . '</strong> (+' . $score_lift . ' points) — a gap that typically correlates with a 15–25% improvement in conversion rate.</p>'
                            . '</div>';
                    }
                    // Gate the competitive analysis text
                    $html .= '<div class="premium-gate-wrapper">';
                    $html .= '<div class="premium-gate-blurred">';
                    $html .= '<div style="background:white;padding:18px;border-radius:10px;border:1px solid #bfdbfe;border-top:3px solid #2563eb;margin-bottom:12px;">'
                        . '<h5 style="color:#1e3a5f;font-size:15px;margin:0 0 8px 0;font-weight:700;">Competitive Position</h5>'
                        . '<p style="margin:0;font-size:13px;color:#374151;line-height:1.6;">A detailed analysis of where your page sits relative to competitors in your market — including the specific improvements that would close the gap to top-performer status.</p>'
                        . '</div>';
                    $html .= '<div style="background:#f0f9ff;padding:18px;border-radius:10px;border:1px solid #bae6fd;border-top:3px solid #0891b2;">'
                        . '<h5 style="color:#0891b2;font-size:15px;margin:0 0 8px 0;font-weight:700;">Competitive Landscape</h5>'
                        . '<p style="margin:0;font-size:13px;color:#374151;line-height:1.6;">An in-depth look at the patterns and tactics used by the top-converting sites in your sector, and exactly how your page compares across each dimension.</p>'
                        . '</div>';
                    $html .= '</div>'; // .premium-gate-blurred
                    $html .= '<div class="premium-gate-overlay">';
                    $html .= '<div style="text-align:center;max-width:360px;">';
                    $html .= '<div style="font-size:20px;margin-bottom:8px;">&#x1F512;</div>';
                    $html .= '<div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:6px;">See where you stand vs. competitors</div>';
                    $html .= '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;line-height:1.6;">Your competitive position and landscape analysis are included with any paid plan.</div>';
                    $html .= '<a href="' . esc_url($brand_contact_url) . '" style="display:inline-block;background:#7c3aed;color:white;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">View Upgrade Options &rarr;</a>';
                    $html .= '</div></div></div>';
                    $html .= '</div>';
                } else {
                $html .= '<div class="section">
                <h3 class="section-title" style="font-size: 20px; margin-bottom: 12px;">Understanding Your Benchmark Score</h3>
                
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%); padding: 24px; border-radius: 12px; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; page-break-inside: avoid; break-inside: avoid;">
                        <h4 style="color: #1e3a5f; font-size: 16px; margin-bottom: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">' . (!empty($business['industry']) ? esc_html($business['industry']) . ' Industry' : 'Industry') . ' Benchmark</h4>
                        <p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin-bottom: 20px;">
                            Based on AI analysis of ' . (!empty($business['industry']) ? 'competitive ' . strtolower(esc_html($business['industry'])) . ' websites' : 'thousands of websites') . ' and conversion optimization data
                        </p>
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 30px; margin-bottom: 24px;">
                            <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); border-radius: 10px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);">
                                <div style="font-size: 12px; color: rgba(255,255,255,0.8); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Your Score</div>
                                <div style="font-size: 48px; font-weight: 800; color: #ffffff; line-height: 1;">' . $overall_score . '</div>
                            </div>
                            <div style="text-align: center; flex: 1; padding: 20px; background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%); border-radius: 10px; border: 2px solid #f59e0b;">
                                <div style="font-size: 12px; color: #92400e; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">' . (!empty($business['industry']) ? esc_html($business['industry']) : 'Industry') . ' Average</div>
                                <div style="font-size: 48px; font-weight: 800; color: #f59e0b; line-height: 1;">' . $industry_avg . '</div>
                            </div>
                        </div>
                        
                        <div style="background: white; padding: 18px; border-radius: 10px; border: 1px solid #bfdbfe; border-top: 3px solid #2563eb; margin-bottom: 15px;">
                            <h5 style="color: #1e3a5f; font-size: 15px; margin: 0 0 10px 0; font-weight: 700;">Competitive Position</h5>
                            <p style="font-size: 13px; color: #374151; line-height: 1.6; margin: 0;">';

                // Dynamic competitive analysis based on score and industry
                $industry_name = !empty($business['industry']) ? strtolower($business['industry']) : 'your industry';

                if ($overall_score >= $top_performers) {
                    $html .= 'You are <strong>outperforming most competitors</strong> in ' . $industry_name . '. Your score places you in the <strong>top 10%</strong> of similar businesses. Maintaining this level while optimizing weaker areas will solidify your market leadership position.';
                }
                elseif ($overall_score >= $industry_avg) {
                    $html .= 'You are performing <strong>above the ' . esc_html($industry_name) . ' average</strong>. This means your page is more effective than approximately <strong>50-70% of competitors</strong>. The recommendations in this report will help you break into the top tier.';
                }
                elseif ($overall_score >= ($industry_avg - 12)) {
                    $html .= 'Your performance is <strong>slightly below the ' . esc_html($industry_name) . ' standard</strong>. Many of your competitors are converting visitors more effectively. However, you\'re positioned to make rapid gains - businesses at this level often see the most dramatic improvements from optimization.';
                }
                else {
                    $html .= 'There is significant opportunity to improve your competitive position in ' . esc_html($industry_name) . '. Most competitors are converting at higher rates, but this gap represents your biggest growth opportunity. The recommendations provided target high-impact improvements.';
                }

                $html .= '</p>
                        </div>';

                // Display competitive context if available - EXPANDED
                if (!empty($competitive_context)) {
                    $html .= '<div style="background: #f0f9ff; padding: 20px; border-radius: 10px; border: 1px solid #bae6fd; border-top: 3px solid #0891b2; margin-bottom: 20px;">
                <h5 style="color: #0891b2; font-size: 15px; margin: 0 0 12px 0; font-weight: 700;">Competitive Landscape</h5>
                <p style="font-size: 14px; color: #1e293b; line-height: 1.7; margin-bottom: 0;">' . nl2br(esc_html($competitive_context)) . '</p>';

                    $html .= '</div>';

                    // Render key competitive factors if present
                    if (!empty($competitive_factors)) {
                        $html .= '<div style="background: #f8faff; padding: 20px; border-radius: 10px; border: 1px solid #dbeafe; border-top: 3px solid #3b82f6; margin-bottom: 20px;">
                        <h5 style="color: #1e40af; font-size: 15px; margin: 0 0 12px 0; font-weight: 700;">&#127942; Key Competitive Factors in ' . esc_html(!empty($business['industry']) ? $business['industry'] : 'Your Industry') . '</h5>
                        <ul style="margin: 0; padding-left: 18px; list-style: disc;">';
                        foreach ($competitive_factors as $factor) {
                            $html .= '<li style="font-size: 13px; color: #1e293b; line-height: 1.7; margin-bottom: 6px;">' . esc_html($factor) . '</li>';
                        }
                        $html .= '</ul></div>';
                    }

                    // Render industry challenges if present
                    if (!empty($industry_challenges)) {
                        $html .= '<div style="background: #fff7ed; padding: 20px; border-radius: 10px; border: 1px solid #fed7aa; border-top: 3px solid #f59e0b; margin-bottom: 20px;">
                        <h5 style="color: #92400e; font-size: 15px; margin: 0 0 12px 0; font-weight: 700;">&#128161; Industry Conversion Challenges</h5>
                        <ul style="margin: 0; padding-left: 18px; list-style: disc;">';
                        foreach ($industry_challenges as $challenge) {
                            $html .= '<li style="font-size: 13px; color: #1e293b; line-height: 1.7; margin-bottom: 6px;">' . esc_html($challenge) . '</li>';
                        }
                        $html .= '</ul></div>';
                    }
                }
                elseif (!empty($business['industry'])) {
                    // No competitive_context from AI — show score comparison only; no generic filler text
                }

                $html .= '</div>
                </div>';
                } // end else (paid plan benchmark)
            } // End of benchmark section conditional

            // Page number for Executive Summary page (always rendered)
            $html .= '
            <div class="page-number">Page ' . (++$page_num) . '</div>
        </div>';

            // ============ SCORES & ANALYSIS ============
            $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Performance Analysis</h2>
                <span style="font-size: 14px; color: #6b7280;">' . $report_date . '</span>
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

            foreach ($scores as $score) {
                $value = intval($data[$score['key']] ?? 0);
                $html .= '<div class="score-card ' . esc_attr($score['class']) . '">
                <div class="score-label">' . esc_html($score['label']) . '</div>
                <div class="score-value">' . $value . '</div>
                <div class="score-bar">
                    <div class="score-bar-fill" style="width:' . $value . '%;background:' . esc_attr($score['color']) . '"></div>
                </div>
                <div class="score-bar-label">' . $value . ' / 100</div>
            </div>';
            }

            $html .= '</div>
            </div>';

            // Historical Score Trend (uses $historical queried earlier)
            if (!empty($historical) && count($historical) > 1) {
                $score_keys = ['clarity_score', 'emotional_score', 'cta_strength', 'readability_score', 'engagement_score', 'trust_score'];
                $score_labels_map = [
                    'clarity_score' => 'Clarity',
                    'emotional_score' => 'Emotional',
                    'cta_strength' => 'CTA',
                    'readability_score' => 'Readability',
                    'engagement_score' => 'Engagement',
                    'trust_score' => 'Trust',
                ];
                $score_colors_map = [
                    'clarity_score' => '#2563eb',
                    'emotional_score' => '#f59e0b',
                    'cta_strength' => '#10b981',
                    'readability_score' => '#9333ea',
                    'engagement_score' => '#d97706',
                    'trust_score' => '#0891b2',
                ];

                // Parse historical audits (newest first, so index 0 = current, index 1 = previous)
                $previous_data_raw = isset($historical[1]['data']) ? $historical[1]['data'] : null;
                if (is_string($previous_data_raw)) {
                    $previous_data = json_decode($previous_data_raw, true);
                } else {
                    $previous_data = $previous_data_raw;
                }

                if (!empty($previous_data) && is_array($previous_data)) {
                    $previous_date = isset($historical[1]['created_at']) ? date('M j, Y', strtotime($historical[1]['created_at'])) : 'Previous';

                    $html .= '<div class="section" style="page-break-inside: avoid; break-inside: avoid;">
                <h3 class="section-title">Score Trends</h3>
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 16px;">Compared to your previous audit on ' . esc_html($previous_date) . '</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">';

                    foreach ($score_keys as $sk) {
                        $current_val = intval($data[$sk] ?? 0);
                        $prev_val = intval($previous_data[$sk] ?? 0);
                        $diff = $current_val - $prev_val;
                        $arrow = $diff > 0 ? '↑' : ($diff < 0 ? '↓' : '→');
                        $diff_color = $diff > 0 ? '#10b981' : ($diff < 0 ? '#ef4444' : '#6b7280');
                        $diff_bg = $diff > 0 ? '#ecfdf5' : ($diff < 0 ? '#fef2f2' : '#f9fafb');
                        $diff_text = $diff > 0 ? '+' . $diff : ($diff < 0 ? (string)$diff : '0');
                        $color = $score_colors_map[$sk];

                        $html .= '<div style="background: white; padding: 16px; border-radius: 10px; border: 1px solid #e5e7eb; text-align: center;">
                        <div style="font-size: 11px; font-weight: 600; color: ' . $color . '; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">' . esc_html($score_labels_map[$sk]) . '</div>
                        <div style="font-size: 28px; font-weight: 800; color: #1e293b;">' . $current_val . '</div>
                        <div style="display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 12px; background: ' . $diff_bg . '; color: ' . $diff_color . '; font-size: 13px; font-weight: 700;">' . $arrow . ' ' . $diff_text . '</div>
                        <div style="font-size: 11px; color: #94a3b8; margin-top: 4px;">was ' . $prev_val . '</div>
                    </div>';
                    }

                    $html .= '</div>
            </div>';
                }
            }

            // Score Descriptions — dynamic per-metric interpretation based on actual values
            // Band helper: returns [band_label, band_color, interpretation]
            $score_band = function($val, $metric_key) {
                $bands = array(
                    'clarity_score' => array(
                        array(0,  30, 'Critical',           '#ef4444', 'Visitors cannot identify what you offer or why it matters within 5 seconds.'),
                        array(31, 50, 'Weak',               '#f59e0b', 'Your value proposition is vague — visitors get a general idea but lack specifics.'),
                        array(51, 65, 'Developing',         '#eab308', 'Value proposition exists but is missing specificity about who, what, or why.'),
                        array(66, 80, 'Strong',             '#2563eb', 'Clear messaging that communicates your audience, offering, and primary benefit.'),
                        array(81,100, 'Exceptional',        '#10b981', 'Visitors immediately understand your differentiated value and are compelled to act.'),
                    ),
                    'emotional_score' => array(
                        array(0,  30, 'Critical',           '#ef4444', 'Content reads as a feature list with no benefit language or pain-point acknowledgment.'),
                        array(31, 50, 'Weak',               '#f59e0b', 'Some benefits mentioned but they are generic — "save time" or "grow your business."'),
                        array(51, 65, 'Developing',         '#eab308', 'Pain points acknowledged with some storytelling, but emotional hooks are shallow.'),
                        array(66, 80, 'Strong',             '#2563eb', 'Strong emotional hooks with a clear before/after transformation narrative.'),
                        array(81,100, 'Exceptional',        '#10b981', 'Deep empathy, aspirational language, and authentic stories — readers feel understood.'),
                    ),
                    'cta_strength' => array(
                        array(0,  30, 'Critical',           '#ef4444', 'No call-to-action found, or only generic text like "Submit" or "Click Here."'),
                        array(31, 50, 'Weak',               '#f59e0b', 'Basic CTAs like "Get Started" without urgency, benefit, or visual prominence.'),
                        array(51, 65, 'Developing',         '#eab308', 'Action-oriented CTAs present with some benefit language (e.g., "Get Your Free Quote").'),
                        array(66, 80, 'Strong',             '#2563eb', 'Compelling CTAs with action verbs, clear benefit, and strong visual prominence.'),
                        array(81,100, 'Exceptional',        '#10b981', 'Strategic CTAs above and below fold with urgency, benefit, and high-contrast design.'),
                    ),
                    'readability_score' => array(
                        array(0,  30, 'Critical',           '#ef4444', 'Dense text walls with no subheadings, paragraphs over 100 words, hard to scan.'),
                        array(31, 50, 'Weak',               '#f59e0b', 'Some structure but paragraphs are 60-100 words with inconsistent hierarchy.'),
                        array(51, 65, 'Developing',         '#eab308', 'Reasonable structure with subheadings and 40-60 word paragraphs.'),
                        array(66, 80, 'Strong',             '#2563eb', 'Clear hierarchy with short paragraphs, bullet points, and effective use of whitespace.'),
                        array(81,100, 'Exceptional',        '#10b981', 'Excellent typography, 20-40 word blocks, F-pattern optimized, highly scannable.'),
                    ),
                    'engagement_score' => array(
                        array(0,  30, 'Critical',           '#ef4444', 'Static text only — no interactive elements beyond a basic contact form.'),
                        array(31, 50, 'Weak',               '#f59e0b', 'Images present with one form or minimal interactivity.'),
                        array(51, 65, 'Developing',         '#eab308', 'Multiple media types with embedded video or basic interactive elements.'),
                        array(66, 80, 'Strong',             '#2563eb', 'Rich interactive content — calculators, quizzes, animations, or multiple CTAs.'),
                        array(81,100, 'Exceptional',        '#10b981', 'Personalization, dynamic content, gamification, deeply interactive experience.'),
                    ),
                    'trust_score' => array(
                        array(0,  30, 'Critical',           '#ef4444', 'No social proof, trust badges, or testimonials found on the page.'),
                        array(31, 50, 'Weak',               '#f59e0b', 'Anonymous testimonials or basic trust badges present, but not both.'),
                        array(51, 65, 'Developing',         '#eab308', 'Named testimonials or client logos or case study mentions present.'),
                        array(66, 80, 'Strong',             '#2563eb', 'Named testimonials with detail, plus trust badges and client logos.'),
                        array(81,100, 'Exceptional',        '#10b981', 'Full testimonials (name+photo+company+result), security seals, and case studies.'),
                    ),
                );
                $metric_bands = isset($bands[$metric_key]) ? $bands[$metric_key] : array();
                foreach ($metric_bands as $b) {
                    if ($val >= $b[0] && $val <= $b[1]) {
                        return array('label' => $b[2], 'color' => $b[3], 'text' => $b[4]);
                    }
                }
                return array('label' => 'N/A', 'color' => '#6b7280', 'text' => '');
            };

            $score_interpretation_data = array(
                array('key' => 'clarity_score', 'label' => 'Clarity',          'icon' => '📝', 'color' => '#2563eb', 'value' => $clarity_val),
                array('key' => 'emotional_score', 'label' => 'Emotional Impact', 'icon' => '💫', 'color' => '#f59e0b', 'value' => $emotional_val),
                array('key' => 'cta_strength', 'label' => 'CTA Strength',       'icon' => '🎯', 'color' => '#10b981', 'value' => $cta_val),
                array('key' => 'readability_score', 'label' => 'Readability',  'icon' => '📖', 'color' => '#9333ea', 'value' => $readability_val),
                array('key' => 'engagement_score', 'label' => 'Engagement',    'icon' => '⚡', 'color' => '#d97706', 'value' => $engagement_val),
                array('key' => 'trust_score', 'label' => 'Trust Signals',      'icon' => '🔒', 'color' => '#0891b2', 'value' => $trust_val),
            );

            // Map $lowest_area label (from $score_values) to $score_interpretation_data key
            $lowest_sid_map = array(
                'Clarity'              => 'clarity_score',
                'Emotional Connection' => 'emotional_score',
                'CTA Strength'        => 'cta_strength',
                'Readability'         => 'readability_score',
                'Engagement'          => 'engagement_score',
                'Trust Signals'       => 'trust_score',
            );
            $lowest_sid_key = $lowest_sid_map[$lowest_area] ?? '';

            // Educational one-liners shown only on the lowest-scoring card
            $metric_why = array(
                'clarity_score'      => 'Clarity is the first filter every visitor applies — if they can\'t immediately grasp what you offer and who it\'s for, they leave.',
                'emotional_score'    => 'Emotional impact determines whether your copy creates the feeling \'this is exactly what I need\' — the pull that motivates action.',
                'cta_strength'       => 'CTA strength is the most direct lever on conversions — a weak ask loses visitors who are already convinced.',
                'readability_score'  => 'Poor readability costs 30–40% of readers before they reach your CTA — busy visitors scan before they read.',
                'engagement_score'   => 'Engagement determines how long visitors stay and explore — interactive elements hold attention and reduce bounce rate.',
                'trust_score'        => 'Trust signals are the final barrier between interest and action — first-time visitors need to feel safe before they commit.',
            );

            $html .= '<div class="section">
                <h3 class="section-title">Understanding Your Scores</h3>';

            // Industry benchmark context line — shown when benchmark data is available
            if ($industry_avg !== null) {
                $ind_label = !empty($business['industry']) ? esc_html($business['industry']) . ' industry' : 'your industry';
                $html .= '<p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin: 4px 0 16px 0;">Benchmark for ' . $ind_label . ': <strong style="color: #1e293b;">' . $industry_avg . '/100</strong> sector average &nbsp;&middot;&nbsp; <strong style="color: #1e293b;">' . $top_performers . '/100</strong> top performers</p>';
            }

            $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 4px;">';

            foreach ($score_interpretation_data as $sid) {
                $band = $score_band($sid['value'], $sid['key']);
                $is_lowest_card = ($sid['key'] === $lowest_sid_key);
                $why_note = ($is_lowest_card && isset($metric_why[$sid['key']])) ? $metric_why[$sid['key']] : '';
                $html .= '
                    <div style="background: #ffffff; padding: 15px; border-radius: 10px; border: 1px solid ' . ($is_lowest_card ? '#fca5a5' : '#e8ecf0') . '; border-top: 3px solid ' . esc_attr($sid['color']) . '; box-shadow: 0 1px 3px rgba(0,0,0,0.04); page-break-inside: avoid;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 4px;">
                            <h4 style="color: ' . esc_attr($sid['color']) . '; font-size: 14px; margin: 0;">' . $sid['icon'] . ' ' . esc_html($sid['label']) . '</h4>
                            <div style="display: flex; align-items: center; gap: 5px;">'
                    . ($is_lowest_card ? '<span style="font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; background: #fef2f2; color: #ef4444;">Focus here first</span>' : '')
                    . '<span style="font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 12px; background: ' . esc_attr($band['color']) . '22; color: ' . esc_attr($band['color']) . ';">' . esc_html($band['label']) . ' (' . $sid['value'] . '/100)</span>
                            </div>
                        </div>
                        <p style="font-size: 13px; color: #374151; line-height: 1.5; margin: 0;">' . esc_html($band['text']) . '</p>'
                    . ($why_note ? '<p style="font-size: 12px; color: #64748b; line-height: 1.5; margin: 8px 0 0 0; padding-top: 8px; border-top: 1px solid #f1f5f9; font-style: italic;">' . esc_html($why_note) . '</p>' : '')
                    . '
                    </div>';
            }

            $html .= '
                </div>
            </div>';

            // Recommendations — split into Quick Wins + Long-Term if available, fall back to legacy suggestions
            $quick_wins = !empty($recommendations['quick_wins']) && is_array($recommendations['quick_wins']) ? $recommendations['quick_wins'] : array();
            $long_term = !empty($recommendations['long_term']) && is_array($recommendations['long_term']) ? $recommendations['long_term'] : array();
            $priority_rec = !empty($recommendations['priority']) && is_array($recommendations['priority']) ? $recommendations['priority'] : array();

            if (!empty($quick_wins) || !empty($long_term)) {
                // Modern split layout

                // Quick Wins section
                if (!empty($quick_wins)) {
                    $html .= '<div class="section">
                <h3 class="section-title" style="display: flex; align-items: center; gap: 10px;">Quick Wins <span style="font-size: 11px; font-weight: 600; color: #10b981; background: #ecfdf5; padding: 3px 10px; border-radius: 6px;">Easy to Implement</span></h3>
                <p style="font-size: 14px; color: #6b7280; margin-bottom: 20px; line-height: 1.6;">High-impact changes you can make right away to see immediate improvement.</p>';

                    $qw_free_limit = 1; // Free plan sees first 1 quick win; rest are gated
                    $qw_counter = 1;
                    foreach ($quick_wins as $qw) {
                        // Cap visible quick wins for free plans
                        if ($is_free_plan && $qw_counter > $qw_free_limit) {
                            break;
                        }
                        $qw_text = is_string($qw) ? $qw : (isset($qw['text']) ? $qw['text'] : '');
                        $qw_why = is_array($qw) && isset($qw['why']) ? $qw['why'] : '';
                        $qw_impact = is_array($qw) && isset($qw['impact']) ? $qw['impact'] : '';
                        $qw_difficulty = is_array($qw) && isset($qw['difficulty']) ? $qw['difficulty'] : 'Easy';

                        $diff_colors = array('Easy' => array('#ecfdf5', '#10b981'), 'Medium' => array('#fef3c7', '#f59e0b'), 'Hard' => array('#fee2e2', '#ef4444'));
                        $dc = isset($diff_colors[$qw_difficulty]) ? $diff_colors[$qw_difficulty] : $diff_colors['Easy'];

                        if (!empty($qw_text)) {
                            $html .= '<div style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 14px; border: 1px solid #d1fae5; border-top: 3px solid #10b981; box-shadow: 0 1px 3px rgba(0,0,0,0.05); page-break-inside: avoid; break-inside: avoid;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; gap: 12px;">
                            <h4 style="margin: 0; color: #1e293b; font-size: 16px; font-weight: 700; line-height: 1.4; flex: 1;">' . $qw_counter . '. ' . esc_html($qw_text) . '</h4>
                            <span style="flex-shrink: 0; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; background: ' . $dc[0] . '; color: ' . $dc[1] . ';">' . esc_html($qw_difficulty) . '</span>
                        </div>';
                            if (!empty($qw_why)) {
                                $html .= '<p style="margin: 0 0 12px 0; font-size: 13px; color: #475569; line-height: 1.7;">' . nl2br(esc_html($qw_why)) . '</p>';
                            }
                            if (!empty($qw_impact)) {
                                $html .= '<div style="background: #f0fdf4; padding: 10px 14px; border-radius: 6px;">
                            <p style="margin: 0; font-size: 12px; color: #166534; line-height: 1.5;"><strong>Impact:</strong> ' . esc_html($qw_impact) . '</p>
                        </div>';
                            }
                            $html .= '</div>';
                            $qw_counter++;
                        }
                    }
                    // For free plans: add a premium gate after the first 2 visible items
                    if ($is_free_plan && count($quick_wins) > $qw_free_limit) {
                        $remaining = count($quick_wins) - $qw_free_limit;
                        $html .= '<div class="premium-gate-wrapper" style="margin-top:0;">';
                        $html .= '<div class="premium-gate-blurred">';
                        for ($i = 0; $i < min($remaining, 2); $i++) {
                            $html .= '<div style="background:white;padding:20px;border-radius:10px;margin-bottom:14px;'
                                . 'border:1px solid #d1fae5;border-top:3px solid #10b981;">';
                            $html .= '<h4 style="margin:0 0 8px 0;color:#1e293b;font-size:15px;font-weight:700;">'
                                . ($qw_free_limit + $i + 1) . '. Additional quick win recommendation</h4>';
                            $html .= '<p style="margin:0;font-size:13px;color:#475569;line-height:1.7;">'
                                . 'A high-impact, easy-to-implement change identified by the AI analysis. '
                                . 'Includes specific implementation steps and expected conversion impact.</p>';
                            $html .= '</div>';
                        }
                        $html .= '</div>'; // .premium-gate-blurred
                        $html .= '<div class="premium-gate-overlay">';
                        $html .= '<div style="text-align:center;max-width:360px;">';
                        $html .= '<div style="font-size:20px;margin-bottom:8px;">&#x1F512;</div>';
                        $html .= '<div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:4px;">'
                            . $remaining . ' more Quick Win' . ($remaining > 1 ? 's' : '') . ' available</div>';
                        $html .= '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;line-height:1.6;">'
                            . 'See all ' . count($quick_wins) . ' tailored quick wins for this page — included with any paid plan.</div>';
                        $html .= '<a href="' . esc_url($brand_contact_url) . '" '
                            . 'style="display:inline-block;background:#7c3aed;color:white;padding:10px 24px;'
                            . 'border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">'
                            . 'View Upgrade Options &rarr;</a>';
                        $html .= '</div></div></div>';
                    }
                    $html .= '</div>';
                }

                // Long-Term Strategic Improvements
                if (!empty($long_term)) {
                    $lt_free_limit = 1; // Free plan sees first 1 strategic improvement; rest are gated
                    if ($is_free_plan) {
                        // Free plan: show first real strategic item, then gate the rest
                        $html .= '<div class="section">
                <h3 class="section-title" style="display: flex; align-items: center; gap: 10px;">Strategic Improvements <span style="font-size: 11px; font-weight: 600; color: #6366f1; background: #eef2ff; padding: 3px 10px; border-radius: 6px;">Long-Term Growth</span></h3>
                <p style="font-size: 14px; color: #6b7280; margin-bottom: 20px; line-height: 1.6;">Larger initiatives that will drive sustained conversion growth over time.</p>';
                        // Render the first real item
                        $lt0 = reset($long_term);
                        $lt0_text = is_string($lt0) ? $lt0 : (isset($lt0['text']) ? $lt0['text'] : '');
                        $lt0_why  = is_array($lt0) && isset($lt0['why'])  ? $lt0['why']  : '';
                        $lt0_impact = is_array($lt0) && isset($lt0['impact']) ? $lt0['impact'] : '';
                        $lt0_difficulty = is_array($lt0) && isset($lt0['difficulty']) ? $lt0['difficulty'] : 'Medium';
                        $lt0_timeframe  = is_array($lt0) && isset($lt0['timeframe'])  ? $lt0['timeframe']  : '';
                        if (!empty($lt0_text)) {
                            $html .= '<div style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 14px; border: 1px solid #e0e7ff; border-top: 3px solid #6366f1; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">';
                            $html .= '<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; gap: 12px;">';
                            $html .= '<h4 style="margin: 0; color: #1e293b; font-size: 16px; font-weight: 700; line-height: 1.4; flex: 1;">1. ' . esc_html($lt0_text) . '</h4>';
                            $html .= '<div style="flex-shrink: 0; text-align: right;">';
                            if (!empty($lt0_timeframe)) $html .= '<div style="padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; background: #eef2ff; color: #6366f1; margin-bottom: 4px;">&#x23F1; ' . esc_html($lt0_timeframe) . '</div>';
                            $html .= '<div style="padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #f59e0b;">' . esc_html($lt0_difficulty) . '</div></div></div>';
                            // Free plan: title + tags only — no why/impact so it's not actionable without upgrading
                            $html .= '<p style="margin: 8px 0 0 0; font-size: 13px; color: #7c3aed; font-weight: 600;">&#x1F512; Full implementation guide available with upgrade &hellip;</p>';
                            $html .= '</div>';
                        }
                        // Gate remaining strategic items
                        $lt_remaining = count($long_term) - $lt_free_limit;
                        if ($lt_remaining > 0) {
                            $html .= '<div class="premium-gate-wrapper">';
                            $html .= '<div class="premium-gate-blurred">';
                            for ($i = 0; $i < min($lt_remaining, 2); $i++) {
                                $html .= '<div style="background:white;padding:20px;border-radius:10px;margin-bottom:14px;border:1px solid #e0e7ff;border-top:3px solid #6366f1;">'
                                    . '<h4 style="margin:0 0 8px 0;color:#1e293b;font-size:15px;font-weight:700;">' . ($lt_free_limit + $i + 1) . '. Additional strategic improvement</h4>'
                                    . '<p style="margin:0;font-size:13px;color:#475569;line-height:1.7;">A high-impact initiative identified for your page, with specific implementation steps, estimated timeframe, and expected revenue impact.</p>'
                                    . '</div>';
                            }
                            $html .= '</div>'; // .premium-gate-blurred
                            $html .= '<div class="premium-gate-overlay">';
                            $html .= '<div style="text-align:center;max-width:360px;">';
                            $html .= '<div style="font-size:20px;margin-bottom:8px;">&#x1F512;</div>';
                            $html .= '<div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:4px;">' . $lt_remaining . ' more strategic improvement' . ($lt_remaining > 1 ? 's' : '') . ' for this page</div>';
                            $html .= '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;line-height:1.6;">See the complete long-term roadmap for this page, included with any paid plan.</div>';
                            $html .= '<a href="' . esc_url($brand_contact_url) . '" style="display:inline-block;background:#7c3aed;color:white;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">View Upgrade Options &rarr;</a>';
                            $html .= '</div></div></div>';
                        }
                        $html .= '</div>';
                    } else {
                    $html .= '<div class="section">
                <h3 class="section-title" style="display: flex; align-items: center; gap: 10px;">Strategic Improvements <span style="font-size: 11px; font-weight: 600; color: #6366f1; background: #eef2ff; padding: 3px 10px; border-radius: 6px;">Long-Term Growth</span></h3>
                <p style="font-size: 14px; color: #6b7280; margin-bottom: 20px; line-height: 1.6;">Larger initiatives that will drive sustained conversion growth over time.</p>';

                    $lt_counter = 1;
                    foreach ($long_term as $lt) {
                        $lt_text = is_string($lt) ? $lt : (isset($lt['text']) ? $lt['text'] : '');
                        $lt_why = is_array($lt) && isset($lt['why']) ? $lt['why'] : '';
                        $lt_impact = is_array($lt) && isset($lt['impact']) ? $lt['impact'] : '';
                        $lt_difficulty = is_array($lt) && isset($lt['difficulty']) ? $lt['difficulty'] : 'Medium';
                        $lt_timeframe = is_array($lt) && isset($lt['timeframe']) ? $lt['timeframe'] : '';

                        if (!empty($lt_text)) {
                            $html .= '<div style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 14px; border: 1px solid #e0e7ff; border-top: 3px solid #6366f1; box-shadow: 0 1px 3px rgba(0,0,0,0.05); page-break-inside: avoid; break-inside: avoid;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; gap: 12px;">
                            <h4 style="margin: 0; color: #1e293b; font-size: 16px; font-weight: 700; line-height: 1.4; flex: 1;">' . $lt_counter . '. ' . esc_html($lt_text) . '</h4>
                            <div style="flex-shrink: 0; text-align: right;">';
                            if (!empty($lt_timeframe)) {
                                $html .= '<div style="padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; background: #eef2ff; color: #6366f1; margin-bottom: 4px;">⏱ ' . esc_html($lt_timeframe) . '</div>';
                            }
                            $html .= '<div style="padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #f59e0b;">' . esc_html($lt_difficulty) . '</div>
                            </div>
                        </div>';
                            if (!empty($lt_why)) {
                                $html .= '<p style="margin: 0 0 12px 0; font-size: 13px; color: #475569; line-height: 1.7;">' . nl2br(esc_html($lt_why)) . '</p>';
                            }
                            if (!empty($lt_impact)) {
                                $html .= '<div style="background: #eef2ff; padding: 10px 14px; border-radius: 6px;">
                            <p style="margin: 0; font-size: 12px; color: #3730a3; line-height: 1.5;"><strong>Impact:</strong> ' . esc_html($lt_impact) . '</p>
                        </div>';
                            }
                            $html .= '</div>';
                            $lt_counter++;
                        }
                    }
                    $html .= '</div>';
                    } // end else (paid plan long-term strategic)
                }
            }
            elseif (!empty($data['suggestions']) && is_array($data['suggestions'])) {
                // Legacy fallback: render old-format suggestions
                $html .= '<div class="section">
                <h3 class="section-title">Priority Recommendations</h3>
                <p style="font-size: 15px; color: #6b7280; margin-bottom: 24px; line-height: 1.7;">
                    Based on your audit results, here are the most impactful changes you can make to improve your conversion rate.
                </p>';

                $counter = 1;

                foreach ($data['suggestions'] as $s) {
                    if (is_string($s)) {
                        $suggestion_text = $s;
                        $has_details = false;
                        $impact_text = '';
                    }
                    else {
                        $suggestion_text = isset($s['text']) ? $s['text'] : '';
                        $has_details = !empty($s['why']) || !empty($s['impact']) || !empty($s['implementation']);
                        $impact_text = isset($s['impact']) ? $s['impact'] : '';
                    }

                    if ($counter <= 2) {
                        $priority = 'HIGH PRIORITY';
                        $priority_color = '#fee2e2';
                        $priority_text_color = '#ef4444';
                    }
                    elseif ($counter <= 4) {
                        $priority = 'MEDIUM PRIORITY';
                        $priority_color = '#fef3c7';
                        $priority_text_color = '#f59e0b';
                    }
                    else {
                        $priority = 'LOW PRIORITY';
                        $priority_color = '#dbeafe';
                        $priority_text_color = '#3b82f6';
                    }

                    if (!empty($suggestion_text)) {
                        $html .= '<div style="background: white; padding: 24px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e0e7ff; border-top: 3px solid #6366f1; box-shadow: 0 1px 4px rgba(0,0,0,0.06); page-break-inside: avoid; break-inside: avoid;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 14px; gap: 16px;">
                            <h4 style="margin: 0; color: #1e293b; font-size: 18px; font-weight: 700; line-height: 1.4; flex: 1;">' . $counter . '. ' . esc_html($suggestion_text) . '</h4>
                            <span style="flex-shrink: 0; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; background: ' . $priority_color . '; color: ' . $priority_text_color . ';">' . $priority . '</span>
                        </div>';

                        if ($has_details && !empty($s['why'])) {
                            $html .= '<p style="margin: 0 0 16px 0; font-size: 14px; color: #475569; line-height: 1.7;">' . nl2br(esc_html($s['why'])) . '</p>';
                        }

                        if (!empty($impact_text)) {
                            $html .= '<div style="background: #f1f5f9; padding: 14px; border-radius: 8px; border: 1px solid #e0e7ff;">
                            <p style="margin: 0; font-size: 13px; color: #334155; line-height: 1.6;"><strong style="color: #1e293b; font-weight: 600;">Expected Impact:</strong> ' . nl2br(esc_html($impact_text)) . '</p>
                        </div>';
                        }

                        if ($has_details && !empty($s['implementation'])) {
                            $html .= '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">How To Implement</div>
                            <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">' . nl2br(esc_html($s['implementation'])) . '</p>
                        </div>';
                        }

                        $html .= '</div>';
                        $counter++;
                    }
                }
                $html .= '</div>';
            }

            if ($is_free_plan) {
                // Transparent inventory — counts derived from real audit data, no AI content shown
                $rw_count = 0;
                if (!empty($rewrites) && is_array($rewrites)) {
                    foreach (array('headline','subheadline','value_proposition','primary_cta','secondary_cta','social_proof_intro','feature_1','feature_2','feature_3','faq_answer_1','closing_statement') as $_rk) {
                        if (!empty($rewrites[$_rk])) $rw_count++;
                    }
                }
                $qw_inv_total = count($quick_wins);
                $lt_inv_total = count($long_term);
                $fs_inv_total = !empty($data['functionality_suggestions']) && is_array($data['functionality_suggestions']) ? count($data['functionality_suggestions']) : 0;
                $ins_total    = count($ai_strengths) + count($ai_weaknesses);
                $inv_parts = array();
                if ($ins_total > 0)    $inv_parts[] = $ins_total . ' key insights';
                if ($qw_inv_total > 0) $inv_parts[] = $qw_inv_total . ' quick wins';
                if ($lt_inv_total > 0) $inv_parts[] = $lt_inv_total . ' strategic improvements';
                if ($rw_count > 0)     $inv_parts[] = $rw_count . ' copy rewrites';
                if ($fs_inv_total > 0) $inv_parts[] = $fs_inv_total . ' feature recommendations';
                if (!empty($inv_parts)) {
                    $html .= '<div style="background: #f8fafc; border-radius: 10px; padding: 16px 20px; border: 1px solid #e2e8f0; margin-top: 20px; page-break-inside: avoid;">'
                        . '<p style="margin: 0 0 5px 0; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.8px;">What\'s in your complete report</p>'
                        . '<p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.7;">This report includes ' . implode(', ', $inv_parts) . ' — all generated specifically for this page.</p>'
                        . '</div>';
                }
            }

            $html .= '
            <div class="page-number">Page ' . (++$page_num) . '</div>
        </div>';

            // ============ PAGE 4.5: LEAD INTELLIGENCE SUMMARY ============
            $knockknock_api_key = get_option('conversioniq_knockknock_api_key', '');
            $is_knockknock_configured = !empty($knockknock_api_key);
            
            ciq_log('=== LEAD INTELLIGENCE SECTION ===');
            ciq_log('📋 Audit ID: ' . ($audit['id'] ?? 'unknown'));
            ciq_log('📋 Page URL: ' . ($audit['page_url'] ?? 'unknown'));
            ciq_log('📋 KnockKnock API key configured: ' . ($is_knockknock_configured ? 'YES' : 'NO (empty)'));
            ciq_log('📋 Data keys in audit: ' . json_encode(array_keys($data)));
            ciq_log('📋 webhook_stats in stored data: ' . (isset($data['webhook_stats']) ? 'YES' : 'NO'));
            ciq_log('📋 lead_intelligence_summary in stored data: ' . (isset($data['lead_intelligence_summary']) ? 'YES' : 'NO'));
            
            // Use stored webhook_stats from audit data (real DB numbers, attached during audit)
            $webhook_stats = isset($data['webhook_stats']) ? $data['webhook_stats'] : null;
            
            if ($webhook_stats) {
                ciq_log('✅ Using stored webhook_stats from audit data: ' . $webhook_stats['total_interactions'] . ' interactions');
            } else {
                ciq_log('⚠️ No webhook_stats in stored audit data - querying DB fresh for: ' . ($audit['page_url'] ?? 'unknown'));
                $webhook_stats = ConversionIQ_AI::get_webhook_statistics($audit['page_url'] ?? '');
                if ($webhook_stats) {
                    ciq_log('✅ Fresh DB query returned: ' . $webhook_stats['total_interactions'] . ' interactions, ' . $webhook_stats['total_leads'] . ' leads, ' . $webhook_stats['total_visitors'] . ' visitors');
                } else {
                    ciq_log('❌ Fresh DB query returned NULL - no webhook data found for this page or site-wide');
                }
            }
            
            $has_lead_intel = !empty($data['lead_intelligence_summary']) && is_array($data['lead_intelligence_summary']);
            ciq_log('📋 has_lead_intel: ' . ($has_lead_intel ? 'YES' : 'NO'));
            ciq_log('📋 webhook_stats result: ' . ($webhook_stats ? 'HAS DATA' : 'NULL - section will be HIDDEN'));
            
            if ($is_free_plan) {
                // Free plan: always show a teaser page — real visitor data never written to HTML
                $html .= '
            <div class="page content-page">
                <div class="content-header">
                    <h2>Visitor Intelligence</h2>
                    <span style="font-size: 14px; color: #6b7280;">' . $report_date . '</span>
                </div>
                <p style="font-size: 15px; color: #374151; margin-bottom: 24px; line-height: 1.8;">
                    See which companies are visiting your page, who the decision-makers are, and when they\'re most active — so you can reach out at exactly the right moment.
                </p>';
                $html .= $gated_block(
                    'Visitor Intelligence',
                    'Company-level visitor identification, industry breakdowns, decision-maker profiles, and peak engagement times — all tied to this specific page.',
                    array(
                        array('title' => 'Company Activity', 'body' => 'Acme Corp · SaaS · 4 visitors · Last seen: Apr 12'),
                        array('title' => 'Visitor Profile', 'body' => 'Industries: Software (38%), Marketing (27%), Professional Services (19%)'),
                        array('title' => 'Peak Engagement', 'body' => 'Tuesday · 2:00 PM · 28 identified visitors this month'),
                    ),
                    '#2563eb'
                );
                $html .= '
                <div class="page-number">Page ' . (++$page_num) . '</div>
            </div>';
            } elseif ($webhook_stats) {
                ciq_log('✅ Rendering Growth Machine Analysis section');
                $html .= '
            <div class="page content-page">
                <div class="content-header">
                    <h2>Growth Machine Analysis</h2>
                    <span style="font-size: 14px; color: #6b7280;">' . $report_date . '</span>
                </div>
                <p style="font-size: 14px; color: #6b7280; margin: 0 0 28px 0; line-height: 1.6;">
                    Real visitor data captured by GrowthMachine for this page. These numbers come directly from your webhook data — not estimates.
                </p>';

                // ---- KEY METRICS ROW ----
                $page_visitors = (int)($webhook_stats['page_specific_visitors'] ?? 0);
                $total_site_identified = (int)($webhook_stats['total_site_leads'] ?? 0) + (int)($webhook_stats['total_site_visitors'] ?? 0);
                $page_visitor_pct = $total_site_identified > 0 ? round(($page_visitors / $total_site_identified) * 100, 1) : 0;
                $peak_day = $webhook_stats['peak_weekday'] ?? '—';
                $peak_hour = isset($webhook_stats['peak_hour']) ? $webhook_stats['peak_hour'] . ':00' : '—';
                
                $html .= '
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 32px;">';
                
                // Stat 1: Site-wide identified visitors (the real GrowthMachine value)
                $html .= '
                    <div style="background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); padding: 22px 16px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(37,99,235,0.15);">
                        <div style="font-size: 36px; font-weight: 800; color: white; line-height: 1; margin-bottom: 6px;">' . $total_site_identified . '</div>
                        <div style="font-size: 11px; color: rgba(255,255,255,0.9); font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">Identified Visitors</div>
                    </div>';
                
                // Stat 2: Page-specific visitors
                $html .= '
                    <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 22px 16px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(16,185,129,0.15);">
                        <div style="font-size: 36px; font-weight: 800; color: white; line-height: 1; margin-bottom: 6px;">' . $page_visitors . '</div>
                        <div style="font-size: 11px; color: rgba(255,255,255,0.9); font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">Page Visitors</div>
                    </div>';
                
                // Stat 3: % of site visitors on this page
                $html .= '
                    <div style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); padding: 22px 16px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(139,92,246,0.15);">
                        <div style="font-size: 36px; font-weight: 800; color: white; line-height: 1; margin-bottom: 6px;">' . $page_visitor_pct . '<span style="font-size: 20px;">%</span></div>
                        <div style="font-size: 11px; color: rgba(255,255,255,0.9); font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">Of Site Visitors</div>
                    </div>';
                
                // Stat 4: Peak Time
                $html .= '
                    <div style="background: linear-gradient(135deg, #ec4899 0%, #be185d 100%); padding: 22px 16px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(236,72,153,0.15);">
                        <div style="font-size: 18px; font-weight: 800; color: white; line-height: 1; margin-bottom: 6px;">' . esc_html($peak_day) . '</div>
                        <div style="font-size: 20px; font-weight: 800; color: rgba(255,255,255,0.95); margin-bottom: 4px;">' . esc_html($peak_hour) . '</div>
                        <div style="font-size: 11px; color: rgba(255,255,255,0.9); font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">Peak Activity</div>
                    </div>';
                
                $html .= '</div>'; // End metrics grid

                // ---- TOP COMPANIES & DOMAINS (side by side) ----
                $top_companies = isset($webhook_stats['top_companies']) ? $webhook_stats['top_companies'] : array();
                $top_domains = isset($webhook_stats['top_domains']) ? $webhook_stats['top_domains'] : array();
                
                // ---- VISITOR PROFILE (Industries & Job Titles) ----
                $top_industries = isset($webhook_stats['top_industries']) ? $webhook_stats['top_industries'] : array();
                $top_job_titles = isset($webhook_stats['top_job_titles']) ? $webhook_stats['top_job_titles'] : array();
                
                if (!empty($top_industries) || !empty($top_job_titles)) {
                    $html .= '
                    <div style="background: #f8fafc; border-radius: 12px; padding: 28px; border: 1px solid #e2e8f0; margin-bottom: 32px;">
                        <h4 style="margin: 0 0 20px 0; font-size: 16px; font-weight: 700; color: #1e3a5f;">Visitor Profile</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">';
                    
                    // Industries column
                    $html .= '
                            <div>';
                    if (!empty($top_industries)) {
                        $html .= '
                                <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px;">Industries</div>
                                <div style="display: flex; flex-direction: column; gap: 8px;">';
                        
                        $industry_colors = array('#2563eb', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#06b6d4', '#f97316', '#6366f1', '#14b8a6', '#e11d48');
                        $idx = 0;
                        foreach (array_slice($top_industries, 0, 6, true) as $industry => $count) {
                            $color = $industry_colors[$idx % count($industry_colors)];
                            $html .= '
                                    <div style="display: flex; align-items: center; padding: 10px 14px; background: white; border-radius: 10px; border: 1px solid #e2e8f0;">
                                        <span style="width: 10px; height: 10px; border-radius: 50%; background: ' . $color . '; margin-right: 10px; flex-shrink: 0;"></span>
                                        <span style="font-size: 13px; font-weight: 500; color: #1e293b;">' . esc_html($industry) . '</span>
                                    </div>';
                            $idx++;
                        }
                        
                        $html .= '
                                </div>';
                    } else {
                        $html .= '
                                <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px;">Industries</div>
                                <div style="padding: 16px; background: white; border-radius: 10px; border: 1px dashed #cbd5e1; text-align: center;">
                                    <span style="font-size: 13px; color: #94a3b8;">Data collecting...</span>
                                </div>';
                    }
                    $html .= '
                            </div>';
                    
                    // Job Titles column
                    $html .= '
                            <div>';
                    if (!empty($top_job_titles)) {
                        $html .= '
                                <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px;">Job Titles</div>
                                <div style="display: flex; flex-direction: column; gap: 8px;">';
                        
                        $title_colors = array('#7c3aed', '#0891b2', '#059669', '#d97706', '#dc2626', '#2563eb', '#c026d3', '#0d9488', '#ca8a04', '#4f46e5');
                        $idx = 0;
                        foreach (array_slice($top_job_titles, 0, 6, true) as $title => $count) {
                            $color = $title_colors[$idx % count($title_colors)];
                            $html .= '
                                    <div style="display: flex; align-items: center; padding: 10px 14px; background: white; border-radius: 10px; border: 1px solid #e2e8f0;">
                                        <span style="width: 10px; height: 10px; border-radius: 50%; background: ' . $color . '; margin-right: 10px; flex-shrink: 0;"></span>
                                        <span style="font-size: 13px; font-weight: 500; color: #1e293b;">' . esc_html($title) . '</span>
                                    </div>';
                            $idx++;
                        }
                        
                        $html .= '
                                </div>';
                    } else {
                        $html .= '
                                <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px;">Job Titles</div>
                                <div style="padding: 16px; background: white; border-radius: 10px; border: 1px dashed #cbd5e1; text-align: center;">
                                    <span style="font-size: 13px; color: #94a3b8;">Data collecting...</span>
                                </div>';
                    }
                    $html .= '
                            </div>';
                    
                    $html .= '
                        </div>
                    </div>';
                }

                // ---- DECISION-MAKER LEVEL ----
                $decision_maker_tiers = isset($webhook_stats['decision_maker_tiers']) ? $webhook_stats['decision_maker_tiers'] : array();
                if (!empty($decision_maker_tiers)) {
                    $total_with_title = array_sum($decision_maker_tiers);
                    $tier_colors = array(
                        'Executive'   => '#2563eb',
                        'Director/VP' => '#7c3aed',
                        'Manager'     => '#10b981',
                        'Individual'  => '#94a3b8',
                    );

                    $html .= '
                    <div style="background: #f8fafc; border-radius: 12px; padding: 28px; border: 1px solid #e2e8f0; margin-bottom: 32px;">
                        <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #1e3a5f;">Decision-Maker Level</h4>
                        <p style="margin: 0 0 20px 0; font-size: 13px; color: #64748b;">Seniority breakdown of identified visitors based on job titles</p>
                        <div style="display: flex; height: 16px; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">';

                    foreach ($decision_maker_tiers as $tier => $count) {
                        $pct  = round(($count / $total_with_title) * 100, 1);
                        $color = $tier_colors[$tier] ?? '#94a3b8';
                        $html .= '<div style="width: ' . $pct . '%; background: ' . $color . ';" title="' . esc_attr($tier) . ': ' . $count . '"></div>';
                    }

                    $html .= '
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 20px;">';

                    foreach ($decision_maker_tiers as $tier => $count) {
                        $pct  = round(($count / $total_with_title) * 100, 1);
                        $color = $tier_colors[$tier] ?? '#94a3b8';
                        $html .= '
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="width: 12px; height: 12px; border-radius: 3px; background: ' . $color . '; flex-shrink: 0;"></span>
                                <span style="font-size: 13px; color: #1e293b; font-weight: 600;">' . esc_html($tier) . '</span>
                                <span style="font-size: 13px; color: #64748b;">' . $count . ' &nbsp;<span style="color: #94a3b8;">(' . $pct . '%)</span></span>
                            </div>';
                    }

                    $html .= '
                        </div>
                    </div>';
                }

                // ---- GEOGRAPHIC DISTRIBUTION ----
                $top_locations = isset($webhook_stats['top_locations']) ? $webhook_stats['top_locations'] : array();
                if (!empty($top_locations)) {
                    $max_loc_count = max($top_locations);

                    $html .= '
                    <div style="background: #f8fafc; border-radius: 12px; padding: 28px; border: 1px solid #e2e8f0; margin-bottom: 32px;">
                        <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #1e3a5f;">Geographic Distribution</h4>
                        <p style="margin: 0 0 20px 0; font-size: 13px; color: #64748b;">Where your identified visitors are located</p>
                        <div style="display: flex; flex-direction: column; gap: 10px;">';

                    foreach (array_slice($top_locations, 0, 8, true) as $location => $count) {
                        $bar_pct = ($max_loc_count > 0) ? round(($count / $max_loc_count) * 100) : 0;
                        $html .= '
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="font-size: 13px; color: #1e293b; font-weight: 500; width: 170px; flex-shrink: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="' . esc_attr($location) . '">' . esc_html($location) . '</div>
                                <div style="flex: 1; background: #e2e8f0; border-radius: 4px; height: 8px; overflow: hidden;">
                                    <div style="width: ' . $bar_pct . '%; height: 100%; background: linear-gradient(90deg, #2563eb, #7c3aed); border-radius: 4px;"></div>
                                </div>
                                <div style="font-size: 12px; color: #64748b; font-weight: 600; width: 24px; text-align: right; flex-shrink: 0;">' . $count . '</div>
                            </div>';
                    }

                    $html .= '
                        </div>
                    </div>';
                }

                // ---- COMPANY INTELLIGENCE CARDS ----
                $company_intelligence = isset($webhook_stats['company_intelligence']) ? $webhook_stats['company_intelligence'] : array();

                if (!empty($company_intelligence)) {
                    $card_colors = array('#2563eb', '#7c3aed', '#10b981', '#f59e0b', '#ec4899', '#06b6d4', '#f97316', '#6366f1');
                    $ci_idx = 0;

                    $html .= '
                    <div style="background: #f8fafc; border-radius: 12px; padding: 28px; border: 1px solid #e2e8f0; margin-bottom: 32px;">
                        <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #1e3a5f;">Company Intelligence</h4>
                        <p style="margin: 0 0 20px 0; font-size: 13px; color: #64748b;">Companies that visited this page — who they are and who you should reach out to</p>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';

                    foreach ($company_intelligence as $ci) {
                        $co_name    = esc_html($ci['company']);
                        $co_industry = esc_html($ci['industry'] ?? '');
                        $co_count   = (int)($ci['count'] ?? 1);
                        $co_last    = !empty($ci['last_seen']) ? date('M j', strtotime($ci['last_seen'])) : '';
                        $accent     = $card_colors[$ci_idx % count($card_colors)];
                        $ci_idx++;

                        $visitor_label = $co_count === 1 ? '1 visitor' : $co_count . ' visitors';

                        $html .= '
                            <div style="background: white; border-radius: 10px; border: 1px solid #e2e8f0; padding: 18px; box-shadow: 0 1px 4px rgba(0,0,0,0.05);">
                                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px;">
                                    <div style="flex: 1; min-width: 0; margin-right: 10px;">
                                        <div style="font-size: 14px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="' . $co_name . '">' . $co_name . '</div>';

                        if ($co_industry) {
                            $html .= '
                                        <span style="display: inline-block; margin-top: 5px; font-size: 11px; background: #eff6ff; color: #2563eb; padding: 2px 8px; border-radius: 10px; font-weight: 500;">' . $co_industry . '</span>';
                        }

                        $html .= '
                                    </div>
                                    <div style="flex-shrink: 0; text-align: right;">
                                        <div style="background: ' . $accent . '; color: white; border-radius: 20px; padding: 3px 12px; font-size: 11px; font-weight: 700; white-space: nowrap;">' . $visitor_label . '</div>';

                        if ($co_last) {
                            $html .= '
                                        <div style="font-size: 10px; color: #94a3b8; margin-top: 4px;">Last: ' . $co_last . '</div>';
                        }

                        $html .= '
                                    </div>
                                </div>';

                        // Contacts list
                        if (!empty($ci['contacts'])) {
                            $html .= '
                                <div style="border-top: 1px solid #f1f5f9; padding-top: 10px; display: flex; flex-direction: column; gap: 8px;">';

                            foreach ($ci['contacts'] as $contact) {
                                $c_name  = esc_html($contact['name'] ?? '');
                                $c_title = esc_html($contact['title'] ?? '');
                                $c_city  = esc_html($contact['city'] ?? '');
                                $c_country = esc_html($contact['country'] ?? '');

                                // Build initials for avatar
                                $initials = '?';
                                if ($c_name) {
                                    $words = explode(' ', trim($c_name));
                                    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                                }

                                $loc_parts = array_filter(array($c_city, $c_country));
                                $c_loc = !empty($loc_parts) ? implode(', ', $loc_parts) : '';

                                $html .= '
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: ' . $accent . '22; color: ' . $accent . '; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">' . esc_html($initials) . '</div>
                                        <div style="min-width: 0;">
                                            <div style="font-size: 13px; font-weight: 600; color: #1e293b;">' . ($c_name ?: '—') . '</div>
                                            <div style="font-size: 11px; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">';

                                $meta_parts = array_filter(array($c_title, $c_loc));
                                $html .= esc_html(implode(' · ', $meta_parts));

                                $html .= '
                                            </div>
                                        </div>
                                    </div>';
                            }

                            $html .= '
                                </div>';
                        }

                        $html .= '
                            </div>';
                    }

                    $html .= '
                        </div>
                    </div>';
                }

                // ---- AI INSIGHT & RECOMMENDATIONS (only if AI provided them based on real data) ----
                if ($has_lead_intel) {
                    $lead_intel = $data['lead_intelligence_summary'];
                    
                    // AI Insight (brief contextual analysis)
                    if (!empty($lead_intel['insight'])) {
                        $html .= '
                    <div style="background: #f0f7ff; border-radius: 12px; padding: 24px; border: 1px solid #bfdbfe; border-top: 3px solid #2563eb; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 15px; font-weight: 700; color: #1e40af;">AI Analysis</h4>
                        <p style="margin: 0; font-size: 14px; color: #1e3a5f; line-height: 1.7;">' . esc_html($lead_intel['insight']) . '</p>
                    </div>';
                    }
                    
                    // AI Recommendations (actionable items based on data)
                    if (!empty($lead_intel['recommendations']) && is_array($lead_intel['recommendations'])) {
                        $html .= '
                    <div style="background: #f0fdf8; border-radius: 12px; padding: 24px; border: 1px solid #d1fae5; border-top: 3px solid #10b981;">
                        <h4 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 700; color: #065f46;">Data-Driven Recommendations</h4>
                        <ol style="margin: 0; padding-left: 20px;">';
                        
                        foreach ($lead_intel['recommendations'] as $rec) {
                            $html .= '<li style="font-size: 14px; color: #1e3a5f; line-height: 1.7; margin-bottom: 8px;">' . esc_html($rec) . '</li>';
                        }
                        
                        $html .= '</ol>
                    </div>';
                    }
                }

                $html .= '
                <div class="page-number">Page ' . (++$page_num) . '</div>
            </div>';
            }

            // ============ FEATURES & FUNCTIONALITY ============
            
            $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Additional Features & Functionality</h2>
                <span style="font-size: 14px; color: #6b7280;">' . $report_date . '</span>
            </div>
            
            <p style="font-size: 15px; color: #374151; margin-bottom: 30px; line-height: 1.8;">
                Based on your page analysis and industry best practices, here are advanced features that could significantly improve your conversion rates and user experience.
            </p>';

            // Functionality suggestions
            $fs_free_limit = 1; // Free plan sees first 1 feature suggestion; rest are gated
            if (!empty($data['functionality_suggestions']) && is_array($data['functionality_suggestions'])) {
                $fs_counter = 0;
                $fs_total = count($data['functionality_suggestions']);
                foreach ($data['functionality_suggestions'] as $feature) {
                    // For free plans, only render the first item
                    if ($is_free_plan && $fs_counter >= $fs_free_limit) {
                        break;
                    }
                    $fs_counter++;
                    $feature_title = $feature['title'] ?? 'Suggested Feature';
                    $feature_category = $feature['category'] ?? '';
                    $feature_desc = $feature['description'] ?? '';
                    $feature_why = $feature['why'] ?? '';
                    $feature_impact = $feature['impact'] ?? '';
                    $feature_impl = $feature['implementation'] ?? '';
                    $feature_icon = $feature['icon'] ?? '';

                    // Map categories to color schemes
                    $category_colors = array(
                        'Conversion Optimization' => array('bg' => '#fef3c7', 'text' => '#b45309'),
                        'Trust & Social Proof' => array('bg' => '#dbeafe', 'text' => '#1d4ed8'),
                        'Engagement & Retention' => array('bg' => '#fce7f3', 'text' => '#be185d'),
                        'SEO & Visibility' => array('bg' => '#d1fae5', 'text' => '#047857'),
                        'Analytics & Intelligence' => array('bg' => '#ede9fe', 'text' => '#7c3aed'),
                        'Personalization' => array('bg' => '#ffedd5', 'text' => '#c2410c'),
                    );
                    $cat_style = isset($category_colors[$feature_category]) ? $category_colors[$feature_category] : array('bg' => '#ede9fe', 'text' => '#7c3aed');

                    $html .= '<div class="feature-card" style="page-break-inside: avoid; break-inside: avoid;">';

                    if (!empty($feature_category)) {
                        $html .= '<span class="feature-category" style="background: ' . $cat_style['bg'] . '; color: ' . $cat_style['text'] . ';">' . esc_html($feature_category) . '</span>';
                    }

                    $title_display = (!empty($feature_icon) ? esc_html($feature_icon) . ' ' : '') . esc_html($feature_title);
                    $html .= '<h4 class="feature-title">' . $title_display . '</h4>
                    <p class="feature-desc">' . esc_html($feature_desc) . '</p>';

                    // Show detailed fields if available
                    if (!empty($feature_why)) {
                        $html .= '<div style="margin-top: 12px; padding: 12px; background: #f0f9ff; border-radius: 8px; border: 1px solid #bae6fd; border-top: 2px solid #0891b2;">
                        <div style="font-size: 11px; font-weight: 700; color: #0891b2; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Why This Feature</div>
                        <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.5;">' . nl2br(esc_html($feature_why)) . '</p>
                    </div>';
                    }

                    if (!empty($feature_impact)) {
                        $html .= '<div style="margin-top: 8px; padding: 12px; background: #f0fdf4; border-radius: 8px; border: 1px solid #d1fae5; border-top: 2px solid #059669;">
                        <div style="font-size: 11px; font-weight: 700; color: #059669; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Expected Impact</div>
                        <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.5;">' . nl2br(esc_html($feature_impact)) . '</p>
                    </div>';
                    }

                    $html .= '</div>';
                }
                // Gate the remaining features for free plans
                if ($is_free_plan && $fs_total > $fs_free_limit) {
                    $remaining_fs = $fs_total - $fs_free_limit;
                    $html .= '<div class="premium-gate-wrapper">';
                    $html .= '<div class="premium-gate-blurred">';
                    for ($i = 0; $i < min($remaining_fs, 2); $i++) {
                        $placeholder_categories = array(
                            array('label' => 'Conversion Optimization', 'bg' => '#fef3c7', 'text' => '#b45309'),
                            array('label' => 'Trust & Social Proof', 'bg' => '#dbeafe', 'text' => '#1d4ed8'),
                        );
                        $pc = $placeholder_categories[$i % 2];
                        $html .= '<div class="feature-card">'
                            . '<span class="feature-category" style="background:' . $pc['bg'] . ';color:' . $pc['text'] . ';">' . $pc['label'] . '</span>'
                            . '<h4 class="feature-title">Additional feature recommendation</h4>'
                            . '<p class="feature-desc">A high-impact feature identified by AI analysis to increase engagement and conversion rates for your specific page and audience type.</p>'
                            . '</div>';
                    }
                    $html .= '</div>'; // .premium-gate-blurred
                    $html .= '<div class="premium-gate-overlay">';
                    $html .= '<div style="text-align:center;max-width:360px;">';
                    $html .= '<div style="font-size:20px;margin-bottom:8px;">&#x1F512;</div>';
                    $html .= '<div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:4px;">'
                        . $remaining_fs . ' more feature suggestion' . ($remaining_fs > 1 ? 's' : '') . ' available</div>';
                    $html .= '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;line-height:1.6;">'
                        . 'See all ' . $fs_total . ' tailored feature recommendations for this page — included with any paid plan.</div>';
                    $html .= '<a href="' . esc_url($brand_contact_url) . '" '
                        . 'style="display:inline-block;background:#7c3aed;color:white;padding:10px 24px;'
                        . 'border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">'
                        . 'View Upgrade Options &rarr;</a>';
                    $html .= '</div></div></div>';
                }
            }
            else {
                // Default suggestions if none provided
                $default_features = [
                    [
                        'icon' => '🧪',
                        'title' => 'A/B Testing Platform',
                        'category' => 'Conversion Optimization',
                        'description' => 'Test different versions of headlines, CTAs, and page layouts to find what converts best for your audience.',
                        'impact' => 'Data-driven improvements can increase conversions by 20-40%',
                        'cat_bg' => '#fef3c7', 'cat_text' => '#b45309'
                    ],
                    [
                        'icon' => '⭐',
                        'title' => 'Review & Testimonial Widgets',
                        'category' => 'Trust & Social Proof',
                        'description' => 'Display verified customer reviews and testimonials with names, photos, and companies to build credibility.',
                        'impact' => 'Can boost trust scores and conversions by 15-25%',
                        'cat_bg' => '#dbeafe', 'cat_text' => '#1d4ed8'
                    ],
                    [
                        'icon' => '🤖',
                        'title' => 'AI Chatbot / Live Chat',
                        'category' => 'Engagement & Retention',
                        'description' => 'Answer visitor questions instantly with an AI-powered chatbot, reducing bounce rates and capturing leads 24/7.',
                        'impact' => 'Increase engagement and lead capture by 15-30%',
                        'cat_bg' => '#fce7f3', 'cat_text' => '#be185d'
                    ],
                    [
                        'icon' => '🔍',
                        'title' => 'Technical SEO Audit & Fixes',
                        'category' => 'SEO & Visibility',
                        'description' => 'Identify and fix technical SEO issues including schema markup, meta tags, page speed, and crawlability.',
                        'impact' => 'Improve organic search rankings and drive 20-50% more qualified traffic',
                        'cat_bg' => '#d1fae5', 'cat_text' => '#047857'
                    ],
                    [
                        'icon' => '📊',
                        'title' => 'Conversion Funnel Tracking',
                        'category' => 'Analytics & Intelligence',
                        'description' => 'Track exactly where visitors drop off in your conversion funnel so you can fix the biggest leaks.',
                        'impact' => 'Identify and fix drop-off points to recover 10-20% of lost conversions',
                        'cat_bg' => '#ede9fe', 'cat_text' => '#7c3aed'
                    ]
                ];

                $df_limit = $is_free_plan ? $fs_free_limit : count($default_features);
                foreach (array_slice($default_features, 0, $df_limit) as $feature) {
                    $html .= '<div class="feature-card">
                    <span class="feature-category" style="background: ' . $feature['cat_bg'] . '; color: ' . $feature['cat_text'] . ';">' . esc_html($feature['category']) . '</span>
                    <h4 class="feature-title">' . esc_html($feature['icon'] . ' ' . $feature['title']) . '</h4>
                    <p class="feature-desc">' . esc_html($feature['description']) . '</p>
                    <div class="feature-impact">Expected Impact: ' . esc_html($feature['impact']) . '</div>
                </div>';
                }
                // Gate remaining default features for free plans
                if ($is_free_plan) {
                    $remaining_df = count($default_features) - $fs_free_limit;
                    $html .= '<div class="premium-gate-wrapper">';
                    $html .= '<div class="premium-gate-blurred">';
                    for ($i = 0; $i < 2; $i++) {
                        $html .= '<div class="feature-card">'
                            . '<span class="feature-category" style="background:#ede9fe;color:#7c3aed;">Analytics & Intelligence</span>'
                            . '<h4 class="feature-title">Additional feature recommendation</h4>'
                            . '<p class="feature-desc">A high-impact feature identified for your page type to increase engagement and drive more conversions. Includes implementation guidance.</p>'
                            . '</div>';
                    }
                    $html .= '</div>'; // .premium-gate-blurred
                    $html .= '<div class="premium-gate-overlay">';
                    $html .= '<div style="text-align:center;max-width:360px;">';
                    $html .= '<div style="font-size:20px;margin-bottom:8px;">&#x1F512;</div>';
                    $html .= '<div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:4px;">'
                        . $remaining_df . ' more feature suggestions available</div>';
                    $html .= '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;line-height:1.6;">'
                        . 'See all tailored feature recommendations for this page — included with any paid plan.</div>';
                    $html .= '<a href="' . esc_url($brand_contact_url) . '" '
                        . 'style="display:inline-block;background:#7c3aed;color:white;padding:10px 24px;'
                        . 'border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">'
                        . 'View Upgrade Options &rarr;</a>';
                    $html .= '</div></div></div>';
                }
            }

            $html .= '
            <div class="page-number">Page ' . (++$page_num) . '</div>
        </div>';

            // ============ COPY REWRITES PAGE ============
            if (!empty($rewrites) && is_array($rewrites)) {

                // ── Detect format: new array-of-objects vs legacy key→value object ──
                $is_new_format = isset($rewrites[0]) && is_array($rewrites[0]) && isset($rewrites[0]['original']);

                if ($is_new_format) {
                    $has_rewrite_content = !empty($rewrites);
                } else {
                    // Legacy format: static key list
                    $rewrite_sections = array(
                        array('key' => 'headline', 'label' => 'Headline', 'color' => '#2563eb', 'bg' => '#eff6ff'),
                        array('key' => 'subheadline', 'label' => 'Subheadline', 'color' => '#7c3aed', 'bg' => '#faf5ff'),
                        array('key' => 'value_proposition', 'label' => 'Value Proposition', 'color' => '#0891b2', 'bg' => '#ecfeff'),
                        array('key' => 'primary_cta', 'label' => 'Primary CTA', 'color' => '#10b981', 'bg' => '#ecfdf5'),
                        array('key' => 'secondary_cta', 'label' => 'Secondary CTA', 'color' => '#059669', 'bg' => '#f0fdf4'),
                        array('key' => 'social_proof_intro', 'label' => 'Social Proof Introduction', 'color' => '#f59e0b', 'bg' => '#fffbeb'),
                        array('key' => 'feature_1', 'label' => 'Key Feature #1', 'color' => '#6366f1', 'bg' => '#eef2ff'),
                        array('key' => 'feature_2', 'label' => 'Key Feature #2', 'color' => '#6366f1', 'bg' => '#eef2ff'),
                        array('key' => 'feature_3', 'label' => 'Key Feature #3', 'color' => '#6366f1', 'bg' => '#eef2ff'),
                        array('key' => 'faq_answer_1', 'label' => 'FAQ Answer', 'color' => '#d97706', 'bg' => '#fef3c7'),
                        array('key' => 'closing_statement', 'label' => 'Closing Statement', 'color' => '#dc2626', 'bg' => '#fef2f2'),
                    );
                    $has_rewrite_content = false;
                    foreach ($rewrite_sections as $rs) {
                        if (!empty($rewrites[$rs['key']])) { $has_rewrite_content = true; break; }
                    }
                }

                if ($has_rewrite_content) {
                    if ($is_free_plan) {
                        // Gate copy rewrites for free plans — real AI copy NEVER written to HTML
                        $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Suggested Copy Rewrites</h2>
                <span style="font-size: 14px; color: #6b7280;">' . $report_date . '</span>
            </div>
            <p style="font-size: 15px; color: #374151; margin-bottom: 24px; line-height: 1.8;">
                AI-generated copy suggestions designed to improve clarity, emotional impact, and conversion rates.
            </p>';
                        $html .= $gated_block(
                            'AI Copy Rewrites',
                            'Ready-to-use headlines, CTAs, and body copy optimized for your audience and conversion goals.',
                            array(
                                array('title' => 'Headline', 'body' => '"Stop Wondering Why Visitors Leave — Get AI-Powered Clarity on What\'s Costing You Conversions"'),
                                array('title' => 'Primary CTA', 'body' => '"Get My Free Conversion Roadmap — See Exactly What to Fix First"'),
                                array('title' => 'Value Proposition', 'body' => '"The only conversion platform that combines AI analysis with actionable recommendations, so you always know what to do next to grow revenue."'),
                            ),
                            '#2563eb'
                        );
                        $html .= '
            <div class="page-number">Page ' . (++$page_num) . '</div>
        </div>';
                    } else {
                    $html .= '
        <div class="page content-page">
            <div class="content-header">
                <h2>Suggested Copy Rewrites</h2>
                <span style="font-size: 14px; color: #6b7280;">' . $report_date . '</span>
            </div>
            
            <p style="font-size: 15px; color: #374151; margin-bottom: 24px; line-height: 1.8;">
                Each rewrite below shows the current copy on your page alongside a sharper, conversion-optimised alternative — written specifically for your business, industry, and audience. A/B test any of these against your live copy to measure the impact.
            </p>';

                    if ($is_new_format) {
                        // ── New before/after format ──────────────────────────────────
                        $section_colors = array('#2563eb','#7c3aed','#0891b2','#10b981','#f59e0b','#6366f1','#d97706','#dc2626','#059669','#0e7490');
                        $color_idx = 0;
                        foreach ($rewrites as $rw) {
                            if (empty($rw['rewrite'])) continue;
                            $section_label = esc_html($rw['section'] ?? 'Copy Section');
                            $original_text = esc_html($rw['original'] ?? '');
                            $rewrite_text  = esc_html($rw['rewrite'] ?? '');
                            $why_text      = esc_html($rw['why'] ?? '');
                            $impact        = esc_html($rw['score_impact'] ?? '');
                            $color         = $section_colors[$color_idx % count($section_colors)];
                            $color_idx++;

                            $html .= '<div style="page-break-inside: avoid; break-inside: avoid; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <span style="display: inline-block; background: ' . $color . '; color: white; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.5px;">' . $section_label . '</span>';
                            if ($impact) {
                                $html .= '<span style="font-size: 11px; color: #6b7280; font-weight: 600;">Improves: ' . $impact . '</span>';
                            }
                            $html .= '</div>';

                            if ($original_text) {
                                $html .= '
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden;">
                        <div style="background: #f9fafb; padding: 16px 18px; border-right: 1px solid #e5e7eb;">
                            <div style="font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px;">Current</div>
                            <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.6;">' . $original_text . '</p>
                        </div>
                        <div style="background: #f0fdf4; padding: 16px 18px;">
                            <div style="font-size: 10px; font-weight: 700; color: ' . $color . '; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px;">Suggested</div>
                            <p style="margin: 0; font-size: 13px; color: #111827; line-height: 1.6; font-weight: 500;">' . $rewrite_text . '</p>
                        </div>
                    </div>';
                            } else {
                                $html .= '
                    <div style="background: #f0fdf4; padding: 16px 18px; border: 1px solid #bbf7d0; border-radius: 10px;">
                        <div style="font-size: 10px; font-weight: 700; color: ' . $color . '; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px;">Suggested Copy</div>
                        <p style="margin: 0; font-size: 13px; color: #111827; line-height: 1.6; font-weight: 500;">' . $rewrite_text . '</p>
                    </div>';
                            }

                            if ($why_text) {
                                $html .= '
                    <div style="margin-top: 6px; padding: 8px 14px; background: #fffbeb; border-left: 3px solid ' . $color . '; border-radius: 0 6px 6px 0;">
                        <p style="margin: 0; font-size: 12px; color: #78350f; line-height: 1.5; font-style: italic;">' . $why_text . '</p>
                    </div>';
                            }

                            $html .= '</div>';
                        }
                    } else {
                        // ── Legacy key→value format ─────────────────────────────────
                        $rendered_count = 0;
                        $total_with_content = 0;
                        foreach ($rewrite_sections as $rs) {
                            if (!empty($rewrites[$rs['key']])) $total_with_content++;
                        }
                        foreach ($rewrite_sections as $rs) {
                            $rw_value = isset($rewrites[$rs['key']]) ? trim($rewrites[$rs['key']]) : '';
                            if (!empty($rw_value)) {
                                $rendered_count++;
                                $html .= '<div style="page-break-inside: avoid; break-inside: avoid;">
                    <div style="background: ' . $rs['bg'] . '; padding: 18px 20px; border-radius: 10px; border: 2px solid ' . $rs['color'] . '; position: relative;">
                        <div style="display: flex; align-items: baseline; gap: 10px; margin-bottom: 8px;">
                            <span style="display: inline-block; background: ' . $rs['color'] . '; color: white; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.5px;">' . esc_html($rs['label']) . '</span>
                        </div>
                        <p style="margin: 0; font-size: 14px; color: #1e293b; line-height: 1.7; font-style: italic;">&ldquo;' . esc_html($rw_value) . '&rdquo;</p>
                    </div>';
                                if ($rendered_count < $total_with_content) {
                                    $html .= '<div style="text-align: center; padding: 6px 0;"><div style="display: inline-block; width: 2px; height: 16px; background: #cbd5e1;"></div><div style="color: #94a3b8; font-size: 16px; line-height: 1;">&#9660;</div></div>';
                                }
                                $html .= '</div>';
                            }
                        }
                    } // end format branch

                    $html .= '
            <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); padding: 18px; border-radius: 10px; margin-top: 16px;">
                <p style="margin: 0; font-size: 13px; color: #0c4a6e; line-height: 1.6;">
                    <strong>Tip:</strong> These rewrites are tailored to your audience and goals. A/B test them against your current copy to measure the impact on conversions.
                </p>
            </div>
            <div class="page-number">Page ' . (++$page_num) . '</div>
        </div>';
                    } // end else (paid plan copy rewrites)
                }
            }

            // ============ LAST PAGE: THANK YOU ============
            // Build dynamic CTA based on audit findings
            $cta_area = $lowest_area;
            $cta_score = $score_values[$lowest_area];
            $cta_text = 'Let Us Help You Improve Your ' . $cta_area;
            $cta_subtitle = 'Your ' . $cta_area . ' score of ' . $cta_score . '/100 represents your biggest growth opportunity. Our team can help you implement the recommendations in this report.';

            $html .= '
        <div class="page thank-you-page">
            <div class="thank-you-content">
                <h1 class="thank-you-title">Thank You</h1>
                
                <p class="thank-you-text">
                    We appreciate the opportunity to analyze your website and provide these insights. At ' . $brand_company . ', we\'re committed to helping businesses like yours achieve measurable growth through data-driven optimization.
                </p>
                
                <p class="thank-you-text">
                    ' . esc_html($cta_subtitle) . '
                </p>
                
                <a href="' . $brand_contact_url . '" class="thank-you-cta">
                    ' . esc_html($cta_text) . '
                </a>
                
                <div class="thank-you-footer">
                    ' . $logo_html . '
                    <p style="margin-top: 20px;"><strong>Report Generated:</strong> ' . $report_date . '</p>
                    <p style="margin-top: 15px;">
                        <strong>' . $brand_company . '</strong><br>
                        Email: ' . $brand_support_email . '<br>
                        Web: ' . $brand_website_url . '
                    </p>
                </div>
            </div>
        </div>';

            $html .= '</body></html>';

            ciq_log('✅ HTML generation complete. Length: ' . strlen($html) . ' bytes');
            
            // IMPORTANT: Save original HTML before any DOMPDF modifications
            // DOMPDF encoding can corrupt the HTML if it fails
            $original_html = $html;

            // Free up memory after HTML generation
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Detect non-ASCII content (Spanish, etc.) and skip DOMPDF directly to HTML
            // DOMPDF has issues with Unicode characters even with DejaVu Sans
            
            // Use early detection result (already checked audit data and page title)
            $force_html = $force_html_early;
            
            // Additional check for HTML entities in the generated HTML (backup detection)
            if (!$force_html) {
                $has_spanish_entities = preg_match('/&[aeiou]acute;|&ntilde;|&iquest;|&iexcl;|&uuml;/i', $html);
                ciq_log('🔍 HTML entities check: ' . ($has_spanish_entities ? 'YES' : 'NO'));
                
                // Sample HTML for non-ASCII content
                $sample_text = substr($html, 0, 10000); // Check first 10KB
                $non_ascii_count = 0;
                $sample_length = strlen($sample_text);
                for ($i = 0; $i < $sample_length; $i++) {
                    if (ord($sample_text[$i]) > 127) {
                        $non_ascii_count++;
                    }
                }
                $non_ascii_percentage = ($non_ascii_count / $sample_length) * 100;
                
                // Force HTML if entities detected OR >0.3% non-ASCII
                if ($has_spanish_entities || $non_ascii_percentage > 0.3) {
                    if ($has_spanish_entities) {
                        $reason = 'Spanish HTML entities detected in content';
                    } else {
                        $reason = round($non_ascii_percentage, 2) . '% non-ASCII characters';
                    }
                    ciq_log('🌍 Non-English content detected (' . $reason . '). Skipping DOMPDF, using HTML fallback for better Unicode support.');
                    $force_html = true;
                }
            }

            // Try using DOMPDF if available via Composer and content is ASCII-safe
            if (!$force_html && class_exists('\Dompdf\Dompdf')) {
                try {
                    ciq_log('🔧 Using DOMPDF for PDF generation');
                    
                    // Configure DOMPDF with proper options for Unicode/UTF-8 support
                    $options = new \Dompdf\Options();
                    $options->set('isHtml5ParserEnabled', true);
                    $options->set('isRemoteEnabled', true);
                    $options->set('defaultFont', 'DejaVu Sans'); // Better Unicode support than Helvetica
                    $options->set('isFontSubsettingEnabled', true);
                    
                    $dompdf = new \Dompdf\Dompdf($options);
                    
                    // Work with a COPY of HTML to preserve original for fallback
                    $html_for_pdf = $original_html;
                    
                    // Ensure HTML is UTF-8 encoded
                    if (function_exists('mb_convert_encoding')) {
                        $html_for_pdf = mb_convert_encoding($html_for_pdf, 'HTML-ENTITIES', 'UTF-8');
                    }
                    
                    $dompdf->loadHtml($html_for_pdf);
                    $dompdf->setPaper('A4', 'portrait');
                    $dompdf->render();
                    
                    // Get PDF output before writing to ensure it's valid
                    $pdf_output = $dompdf->output();
                    
                    if (empty($pdf_output) || strlen($pdf_output) < 1000) {
                        throw new Exception('DOMPDF generated invalid or too small output (' . strlen($pdf_output) . ' bytes)');
                    }
                    
                    // Verify it's actually PDF data
                    if (substr($pdf_output, 0, 4) !== '%PDF') {
                        throw new Exception('DOMPDF output does not appear to be valid PDF');
                    }
                    
                    file_put_contents($path, $pdf_output);
                    $url = trailingslashit($upload['baseurl']) . 'conversioniq/reports/' . $filename;
                    ciq_log('✅ PDF generated successfully: ' . $url . ' (' . strlen($pdf_output) . ' bytes)');

                    // Free memory
                    unset($html, $html_for_pdf, $original_html, $dompdf, $pdf_output);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }

                    return array('success' => true, 'url' => $url, 'path' => $path);
                }
                catch (Exception $e) {
                    ciq_log('❌ DOMPDF Error: ' . $e->getMessage());
                    ciq_log('❌ Stack trace: ' . $e->getTraceAsString());
                    ciq_log('⚠️ Falling back to HTML report due to DOMPDF error');
                    
                    // Clean up any partial PDF file
                    if (file_exists($path)) {
                        @unlink($path);
                        ciq_log('🗑️ Cleaned up partial PDF file');
                    }
                    
                    // Clean up DOMPDF objects
                    unset($dompdf, $html_for_pdf, $pdf_output);
                }
            }
            else {
                ciq_log('⚠️ DOMPDF not available, using HTML fallback');
            }

            // Alternative: Use WordPress built-in functionality to create better formatted HTML
            // that can be printed to PDF by the browser
            ciq_log('📄 Falling back to HTML report generation');
            $fallback_path = $dir . str_replace('.pdf', '.html', $filename);

            // Add print stylesheet for better PDF conversion
            // Use $original_html to ensure clean HTML even if DOMPDF corrupted $html
            $print_ready_html = str_replace(
                '</head>',
                '<style>@media print { body { margin: 0; padding: 0; } .page { page-break-after: always; } .page:last-child { page-break-after: avoid; } }</style></head>',
                $original_html
            );
            
            // Verify we're actually writing HTML, not PDF data
            if (substr($print_ready_html, 0, 10) === '%PDF-1.') {
                ciq_log('❌ ERROR: HTML variable contains PDF data! This should not happen.');
                return array(
                    'success' => false,
                    'message' => 'Internal error: PDF data in HTML variable',
                );
            }
            
            // Double-check: Ensure it starts with HTML
            $html_start = ltrim(substr($print_ready_html, 0, 100));
            if (!preg_match('/^<!DOCTYPE|^<html/i', $html_start)) {
                ciq_log('❌ ERROR: HTML content does not start with DOCTYPE or <html>. First 100 chars: ' . substr($html_start, 0, 100));
                return array(
                    'success' => false,
                    'message' => 'Internal error: Invalid HTML content',
                );
            }

            file_put_contents($fallback_path, $print_ready_html);
            
            // Verify file was written correctly
            if (!file_exists($fallback_path)) {
                ciq_log('❌ ERROR: Failed to write HTML file to ' . $fallback_path);
                return array(
                    'success' => false,
                    'message' => 'Failed to write HTML report file',
                );
            }
            
            // Read back first few bytes to ensure it's HTML
            $file_handle = fopen($fallback_path, 'r');
            $first_bytes = fread($file_handle, 10);
            fclose($file_handle);
            
            if (substr($first_bytes, 0, 4) === '%PDF') {
                ciq_log('❌ CRITICAL ERROR: HTML file contains PDF data after write! Deleting corrupted file.');
                @unlink($fallback_path);
                return array(
                    'success' => false,
                    'message' => 'Critical error: PDF data written to HTML file. Please contact support.',
                );
            }
            
            ciq_log('📝 HTML fallback file size: ' . filesize($fallback_path) . ' bytes');

            // Free memory
            unset($html, $original_html, $print_ready_html);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $url = trailingslashit($upload['baseurl']) . 'conversioniq/reports/' . basename($fallback_path);

            ciq_log('✅ HTML report generated: ' . $url);

            return array(
                'success' => true,
                'url' => $url,
                'path' => $fallback_path,
                'note' => 'HTML report generated. Open in browser and use Print > Save as PDF for PDF version.',
                'is_html' => true
            );

        }
        catch (Exception $e) {
            ciq_log('❌ Report generation exception: ' . $e->getMessage());
            ciq_log('❌ Stack trace: ' . $e->getTraceAsString());
            return array(
                'success' => false,
                'message' => 'Report generation failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Get lead performance metrics for a specific page
     * 
     * @param string $page_url The URL of the page to get metrics for
     * @return array|null Array of metrics or null if no data exists
     */
    private static function get_lead_performance_metrics($page_url) {
        global $wpdb;
        
        $leads_table = $wpdb->prefix . 'conversioniq_leads';
        $visitors_table = $wpdb->prefix . 'conversioniq_visitor_sessions';
        
        // PRIMARY METRIC: Leads that started their journey on this page
        // This is the most meaningful metric - shows the page's value as an entry point
        $leads_started_here = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE initial_page_visit = %s",
            $page_url
        ));
        
        // SECONDARY METRIC: Engaged visitors (identified while on this page)
        // Shows people are spending time and interacting with content
        $engaged_visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visitors_table WHERE page_url = %s",
            $page_url
        ));
        
        // CONTEXT METRIC: Total site leads to calculate this page's contribution
        $total_site_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table");
        
        // Calculate percentage of site leads that started here
        $site_contribution_pct = ($total_site_leads > 0) 
            ? round(($leads_started_here / $total_site_leads) * 100, 1) 
            : 0;
        
        // RECENCY METRIC: Check for activity in last 7 days
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        $recent_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE initial_page_visit = %s AND created_at >= %s",
            $page_url,
            $seven_days_ago
        ));
        $recent_visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visitors_table WHERE page_url = %s AND created_at >= %s",
            $page_url,
            $seven_days_ago
        ));
        $recent_activity = $recent_leads + $recent_visitors;
        
        // Return null if no meaningful data exists
        if ($leads_started_here == 0 && $engaged_visitors == 0) {
            return null;
        }
        
        return array(
            'leads_started_here' => (int)$leads_started_here,
            'engaged_visitors' => (int)$engaged_visitors,
            'site_contribution_pct' => $site_contribution_pct,
            'total_site_leads' => (int)$total_site_leads,
            'recent_activity' => (int)$recent_activity,
            'has_recent_activity' => $recent_activity > 0,
        );
    }
}
