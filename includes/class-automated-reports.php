<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles automated audit scheduling and email reporting
 */
class ConversionIQ_Automated_Reports
{

    /**
     * Initialize automated reports functionality
     */
    public static function init()
    {
        // Register custom cron schedules
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedules'));

        // Register the cron hook
        add_action('conversioniq_automated_audit', array(__CLASS__, 'run_automated_audit'));

        // Cleanup on plugin deactivation
        register_deactivation_hook(CONVERSION_IQ_FILE, array(__CLASS__, 'deactivate'));
    }

    /**
     * Add custom cron schedules
     */
    public static function add_cron_schedules($schedules)
    {
        $schedules['conversioniq_weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Weekly (Monday)', 'conversion-iq')
        );

        $schedules['conversioniq_monthly'] = array(
            'interval' => 30 * DAY_IN_SECONDS, // Approximate
            'display' => __('Monthly', 'conversion-iq')
        );

        $schedules['conversioniq_bimonthly'] = array(
            'interval' => 60 * DAY_IN_SECONDS, // Approximate
            'display' => __('Bi-Monthly', 'conversion-iq')
        );

        return $schedules;
    }

    /**
     * Run automated audit and send email report
     */
    public static function run_automated_audit()
    {
        error_log('🤖 ConversionIQ: Starting automated audit...');

        $settings = get_option('conversion_iq_automated_reports', array());

        if (empty($settings['enabled']) || empty($settings['defaultPages']) || empty($settings['email'])) {
            error_log('⚠️ Automated audit cancelled: invalid settings');
            return;
        }

        $business = json_decode(get_option('conversion_iq_settings', '{}'), true);
        $results = array();

        foreach ($settings['defaultPages'] as $page_id) {
            $post = get_post(intval($page_id));
            if (!$post) {
                continue;
            }

            // Get page content
            $content = $post->post_content;
            $content = strip_shortcodes($content);
            $content = wp_strip_all_tags($content);

            // Fetch HTML structure
            $page_url = get_permalink($post);
            $html_structure = '';

            $response = wp_remote_get($page_url, array(
                'timeout' => 10,
                'sslverify' => false,
            ));

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $html = wp_remote_retrieve_body($response);
                $html_structure = conversioniq_extract_html_structure($html);
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

            error_log('📄 Automated audit: ' . $post->post_title);

            try {
                // Always run a fresh audit analysis
                $ai = ConversionIQ_AI::analyze($payload);

                if (is_array($ai)) {
                    // Save the fresh audit to database
                    $insert_id = ConversionIQ_DB::insert_audit($post->ID, $post->post_title, $ai);
                    $ai['insert_id'] = $insert_id;
                    $ai['page_id'] = $post->ID;
                    $ai['page_title'] = $post->post_title;
                    $ai['page_url'] = $page_url;
                    $ai['created_at'] = current_time('mysql');
                    $results[] = $ai;

                    error_log('✅ Fresh audit completed for: ' . $post->post_title . ' (Audit ID: ' . $insert_id . ')');

                    // Send to webhook if configured
                    if (function_exists('conversioniq_send_webhook')) {
                        conversioniq_send_webhook($ai);
                    }
                }
                else {
                    error_log('⚠️ Audit analysis returned non-array result for: ' . $post->post_title);
                }
            }
            catch (Exception $e) {
                error_log('❌ Automated audit error for ' . $post->post_title . ': ' . $e->getMessage());
            }
        }

        if (empty($results)) {
            error_log('⚠️ No audit results to email');
            return;
        }

        error_log('✅ Automated audits completed. Generated ' . count($results) . ' fresh audit(s) for scheduled report');

        // Send email with results
        self::send_email_report($settings['email'], $results, $business);

        error_log('📧 Automated report emailed to ' . $settings['email'] . ' with ' . count($results) . ' page(s)');
    }

    /**
     * Send email report with audit results
     * @param string $email Comma-separated email addresses
     * @return array ['success' => bool, 'messages' => array] Success status and log messages
     */
    private static function send_email_report($email, $results, $business)
    {
        $messages = array(); // Local log array
        
        // Parse comma-separated emails
        $email_list = array_map('trim', explode(',', $email));
        $valid_emails = array_filter($email_list, 'is_email');

        if (empty($valid_emails)) {
            $msg = '❌ No valid email addresses provided';
            error_log($msg);
            $messages[] = $msg;
            return array('success' => false, 'messages' => $messages);
        }

        $site_name = get_bloginfo('name');
        $site_url = get_home_url();
        $total_pages = count($results);

        // Calculate average scores and collect page summaries
        $total_score = 0;
        $page_summaries = array();
        $attachments = array();

        foreach ($results as $result) {
            $clarity = intval($result['clarity_score'] ?? 0);
            $emotional = intval($result['emotional_score'] ?? 0);
            $cta = intval($result['cta_strength'] ?? 0);
            $readability = intval($result['readability_score'] ?? 0);
            $engagement = intval($result['engagement_score'] ?? 0);
            $trust = intval($result['trust_score'] ?? 0);

            $page_score = round(($clarity + $emotional + $cta + $readability + $engagement + $trust) / 6);
            $total_score += $page_score;

            $page_summaries[] = array(
                'title' => esc_html($result['page_title'] ?? 'Unknown Page'),
                'url' => esc_url($result['page_url'] ?? ''),
                'score' => $page_score,
                'scores' => array(
                    'clarity' => $clarity,
                    'emotional' => $emotional,
                    'cta' => $cta,
                    'readability' => $readability,
                    'engagement' => $engagement,
                    'trust' => $trust
                )
            );

            // Generate PDF report for this audit if it exists
            if (isset($result['insert_id'])) {
                global $wpdb;
                $table = $wpdb->prefix . 'conversioniq_audits';
                $audit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $result['insert_id']), ARRAY_A);

                if ($audit) {
                    $audit['data'] = json_decode($audit['data'], true);
                    $msg = '📄 Generating PDF for audit ID: ' . $result['insert_id'] . ' (' . $result['page_name'] . ')';
                    error_log($msg);
                    $messages[] = $msg;
                    
                    $pdf_result = ConversionIQ_Reports::generate_pdf_for_audit($audit);

                    if ($pdf_result['success'] && isset($pdf_result['path']) && file_exists($pdf_result['path'])) {
                        // Verify file is readable and has content
                        $file_size = filesize($pdf_result['path']);
                        if ($file_size > 0) {
                            $attachments[] = $pdf_result['path'];
                            $msg = '✅ PDF generated: ' . basename($pdf_result['path']) . ' (Size: ' . round($file_size / 1024, 2) . ' KB)';
                            error_log($msg);
                            $messages[] = $msg;
                        }
                        else {
                            $msg = '⚠️ PDF file is empty (0 bytes): ' . basename($pdf_result['path']);
                            error_log($msg);
                            $messages[] = $msg;
                        }
                    }
                    else {
                        $msg = '⚠️ PDF generation failed or file not found for audit ID: ' . $result['insert_id'];
                        error_log($msg);
                        $messages[] = $msg;
                        
                        if (isset($pdf_result['error'])) {
                            $msg = '   Error: ' . $pdf_result['error'];
                            error_log($msg);
                            $messages[] = $msg;
                        }
                    }
                }
                else {
                    $msg = '⚠️ Audit record not found for ID: ' . $result['insert_id'];
                    error_log($msg);
                    $messages[] = $msg;
                }
            }
        }

        $overall_score = $total_pages > 0 ? round($total_score / $total_pages) : 0;
        $status = $overall_score >= 85 ? 'Excellent' : ($overall_score >= 75 ? 'Good' : ($overall_score >= 60 ? 'Fair' : 'Needs Improvement'));
        $status_color = $overall_score >= 85 ? '#10b981' : ($overall_score >= 75 ? '#2563eb' : ($overall_score >= 60 ? '#f59e0b' : '#ef4444'));

        // Branding from config manager — only apply custom branding if plan allows it
        if (ConversionIQ_Config_Manager::can('white_label_emails')) {
            $branding = ConversionIQ_Config_Manager::get_branding();
        } else {
            // Use default branding for Starter plans
            $branding = array(
                'company_name'   => 'Webtec',
                'product_name'   => 'Conversion IQ',
                'support_email'  => 'support@trywebtec.com',
                'booking_url'    => 'https://calendly.com/webtec-website/success-meeting',
                'hide_powered_by' => false,
            );
        }
        $brand_company = esc_html($branding['company_name']);
        $brand_product = esc_html($branding['product_name']);
        $brand_support_email = $branding['support_email'];
        $brand_booking_url = esc_url($branding['booking_url']);
        $brand_hide_powered_by = !empty($branding['hide_powered_by']);
        $logo_html = ConversionIQ_Config_Manager::get_logo_html('height: 60px; width: auto; display: block; margin: 0 auto;');

        // Calculate insights
        $low_scoring_pages = array_filter($page_summaries, function ($p) {
            return $p['score'] < 60; });
        $high_scoring_pages = array_filter($page_summaries, function ($p) {
            return $p['score'] >= 75; });

        // Find weakest areas
        $avg_scores = array(
            'clarity' => 0,
            'emotional' => 0,
            'cta' => 0,
            'readability' => 0,
            'engagement' => 0,
            'trust' => 0
        );
        foreach ($page_summaries as $page) {
            foreach ($avg_scores as $key => $val) {
                $avg_scores[$key] += $page['scores'][$key];
            }
        }
        foreach ($avg_scores as $key => $val) {
            $avg_scores[$key] = round($val / $total_pages);
        }
        arsort($avg_scores);
        $weakest_areas = array_values(array_slice(array_keys($avg_scores), -2, 2));
        $strongest_areas = array_values(array_slice(array_keys($avg_scores), 0, 2));

        // Build email subject
        $subject = sprintf('[%s] %s Audit Report - Score: %d/100', $site_name, $brand_product, $overall_score);

        // Check if any recipient email contains 'basecamp'
        $is_basecamp = conversioniq_has_basecamp_email($valid_emails);

        // Build HTML page list
        $page_list_html = '';
        foreach ($page_summaries as $summary) {
            $score_color = $summary['score'] >= 75 ? '#10b981' : ($summary['score'] >= 60 ? '#f59e0b' : '#ef4444');
            $page_list_html .= sprintf(
                '<tr>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
                        <a href="%s" style="color: #2563eb; text-decoration: none; font-weight: 500;">%s</a>
                    </td>
                    <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; text-align: center;">
                        <span style="color: %s; font-weight: 700; font-size: 16px;">%d</span><span style="color: #6b7280; font-size: 14px;">/100</span>
                    </td>
                </tr>',
                esc_url($summary['url']),
                esc_html($summary['title']),
                $score_color,
                $summary['score']
            );
        }

        // Logo HTML already built from config manager above

        // Build HTML email with professional styling
        $message = sprintf(            '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $brand_product . ' Audit Report</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f9fafb; line-height: 1.6;">
    <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; max-width: 600px;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1e3a5f 0%%, #2563eb 100%%); padding: 40px 32px; text-align: center;">
                            %s
                            <h1 style="margin: 16px 0 0 0; color: #ffffff; font-size: 28px; font-weight: 700; letter-spacing: -0.5px;">
                                ' . $brand_product . ' Audit Report
                            </h1>
                            <p style="margin: 8px 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">
                                Your automated conversion analysis is complete
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Main Content -->
                    <tr>
                        <td style="padding: 32px;">
                            
                            <!-- Greeting -->
                            <p style="margin: 0 0 24px; color: #1f2937; font-size: 16px;">
                                Hello,
                            </p>
                            
                            <p style="margin: 0 0 24px; color: #4b5563; font-size: 15px;">
                                Your automated ' . $brand_product . ' audit has been completed. We analyzed <strong>%d page%s</strong> on your website to evaluate conversion performance across six critical factors: conversion clarity, emotional resonance, call-to-action effectiveness, readability, engagement, and trust signals.
                            </p>
                            
                            <p style="margin: 0 0 12px; color: #4b5563; font-size: 15px;">
                                Feel free to book a quick chat if you have any questions: <a href="' . $brand_booking_url . '" style="color: #2563eb; text-decoration: none;">' . $brand_booking_url . '</a>
                            </p>
                            
                            <p style="margin: 0 0 24px; color: #4b5563; font-size: 15px;">
                                You are also invited to paste any recommendations directly into Basecamp and our Customer Success Team will get onto them right away.
                            </p>
                            
                            <p style="margin: 0 0 24px; color: #4b5563; font-size: 15px;">
                                Thanks,<br>
                                ' . $brand_company . '
                            </p>
                            
                            <!-- Overall Score Card -->
                            <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f3f4f6; border-radius: 8px; margin: 24px 0; border-left: 4px solid %s;">
                                <tr>
                                    <td style="padding: 24px;">
                                        <div style="text-align: center;">
                                            <p style="margin: 0 0 8px; color: #6b7280; font-size: 12px; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">
                                                Overall Performance
                                            </p>
                                            <p style="margin: 0; color: %s; font-size: 48px; font-weight: 700; line-height: 1;">
                                                %d<span style="color: #9ca3af; font-size: 24px;">/100</span>
                                            </p>
                                            <p style="margin: 8px 0 0; color: #4b5563; font-size: 16px; font-weight: 600;">
                                                %s
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Pages Analyzed -->
                            <h2 style="margin: 32px 0 16px; color: #1f2937; font-size: 20px; font-weight: 700; letter-spacing: -0.3px;">
                                Pages Analyzed
                            </h2>
                            <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; margin-bottom: 24px;">
                                <thead>
                                    <tr style="background-color: #f9fafb;">
                                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Page
                                        </th>
                                        <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Score
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    %s
                                </tbody>
                            </table>
                            
                            <!-- Key Insights -->
                            <h2 style="margin: 32px 0 16px; color: #1f2937; font-size: 20px; font-weight: 700; letter-spacing: -0.3px;">
                                Key Insights
                            </h2>
                            <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 12px 16px; background-color: #ecfdf5; border-left: 3px solid #10b981; border-radius: 4px; margin-bottom: 12px;">
                                        <p style="margin: 0; color: #065f46; font-size: 14px; line-height: 1.5;">
                                            <strong>Top Performers:</strong> Your strongest areas are <strong>%s</strong> (averaging %d/100) and <strong>%s</strong> (averaging %d/100)
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 12px 16px; background-color: #fef3c7; border-left: 3px solid #f59e0b; border-radius: 4px;">
                                        <p style="margin: 0; color: #92400e; font-size: 14px; line-height: 1.5;">
                                            <strong>Focus Areas:</strong> Prioritize improvements in <strong>%s</strong> (averaging %d/100) and <strong>%s</strong> (averaging %d/100)
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 16px 0 0 0; color: #4b5563; font-size: 14px; padding: 12px 16px; background-color: #eff6ff; border-radius: 4px; border-left: 3px solid #2563eb;">
                                Each attached PDF report contains specific, actionable recommendations tailored to your business
                            </p>
                            
                            <!-- Next Steps -->
                            <h2 style="margin: 32px 0 16px; color: #1f2937; font-size: 20px; font-weight: 700; letter-spacing: -0.3px;">
                                Next Steps
                            </h2>
                            <table width="100%%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding: 12px 0;">
                                        <table width="100%%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="32" style="vertical-align: top;">
                                                    <div style="width: 24px; height: 24px; background-color: #2563eb; color: #ffffff; border-radius: 50%%; text-align: center; line-height: 24px; font-weight: 700; font-size: 12px;">1</div>
                                                </td>
                                                <td style="padding-left: 12px; color: #4b5563; font-size: 14px;">
                                                    Review the attached PDF reports for detailed analysis and recommendations
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0;">
                                        <table width="100%%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="32" style="vertical-align: top;">
                                                    <div style="width: 24px; height: 24px; background-color: #2563eb; color: #ffffff; border-radius: 50%%; text-align: center; line-height: 24px; font-weight: 700; font-size: 12px;">2</div>
                                                </td>
                                                <td style="padding-left: 12px; color: #4b5563; font-size: 14px;">
                                                    Prioritize changes based on the scores and suggestions provided
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0;">
                                        <table width="100%%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="32" style="vertical-align: top;">
                                                    <div style="width: 24px; height: 24px; background-color: #2563eb; color: #ffffff; border-radius: 50%%; text-align: center; line-height: 24px; font-weight: 700; font-size: 12px;">3</div>
                                                </td>
                                                <td style="padding-left: 12px; color: #4b5563; font-size: 14px;">
                                                    Schedule implementation - Reach out to ' . $brand_company . ' for a call to discuss and help implement the recommendations
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Attached Files -->
                            <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0; background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <p style="margin: 0 0 4px; color: #1f2937; font-size: 14px; font-weight: 600;">
                                            Attached Files
                                        </p>
                                        <p style="margin: 0; color: #6b7280; font-size: 13px;">
                                            %d detailed PDF report%s with page-specific recommendations, suggested content rewrites, and visual analysis
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 24px 32px; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0 0 8px; color: #1f2937; font-size: 14px; font-weight: 600; text-align: center;">
                                ' . $brand_product . '
                            </p>
                            ' . ($brand_hide_powered_by ? '' : '<p style="margin: 0; color: #6b7280; font-size: 12px; text-align: center;">
                                Powered by <strong>' . $brand_company . '</strong>
                            </p>') . '
                            <p style="margin: 12px 0 0; color: #9ca3af; font-size: 12px; text-align: center;">
                                Need help implementing changes or have questions?<br>
                                Contact us at <a href="mailto:' . esc_attr($brand_support_email) . '" style="color: #2563eb; text-decoration: none;">' . esc_html($brand_support_email) . '</a>
                            </p>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>',
            $logo_html,
            $total_pages,
            $total_pages > 1 ? 's' : '',
            $status_color,
            $status_color,
            $overall_score,
            $status,
            $page_list_html,
            ucfirst($strongest_areas[0]),
            $avg_scores[$strongest_areas[0]],
            ucfirst($strongest_areas[1]),
            $avg_scores[$strongest_areas[1]],
            ucfirst($weakest_areas[0]),
            $avg_scores[$weakest_areas[0]],
            ucfirst($weakest_areas[1]),
            $avg_scores[$weakest_areas[1]],
            count($attachments),
            count($attachments) !== 1 ? 's' : ''
        );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $brand_product . ' - ' . $site_name . ' <noreply@' . parse_url(get_site_url(), PHP_URL_HOST) . '>',
            'Reply-To: ' . $brand_company . ' Support <' . $brand_support_email . '>'
        );

        // If sending to Basecamp, create plain text version instead
        if ($is_basecamp) {
            $message = strtoupper($brand_product) . " AUDIT REPORT\n";
            $message .= "=================================\n";
            $message .= "\n\n";
            $message .= "Hello,\n\n";
            $message .= "Your automated " . $brand_product . " audit has been completed.\n";
            $message .= "We analyzed " . $total_pages . " page" . ($total_pages > 1 ? 's' : '') . " on your website.\n\n";
            $message .= "Feel free to book a quick chat if you have any questions:\n";
            $message .= $brand_booking_url . "\n\n";
            $message .= "You can paste any recommendations directly into Basecamp and our Customer Success Team will get onto them right away.\n\n";
            $message .= "Thanks,\n" . $brand_company . "\n\n";

            $message .= "OVERALL PERFORMANCE: " . $overall_score . "/100 - " . $status . "\n";
            $message .= "=================================\n\n";

            $message .= "PAGES ANALYZED:\n";
            $message .= "---------------------------------\n";
            foreach ($page_summaries as $summary) {
                $message .= "* " . $summary['title'] . "\n";
                $message .= "  Score: " . $summary['score'] . "/100\n";
                $message .= "  URL: " . $summary['url'] . "\n";
                $message .= "  - Clarity: " . $summary['scores']['clarity'] . "/100\n";
                $message .= "  - Emotional: " . $summary['scores']['emotional'] . "/100\n";
                $message .= "  - CTA: " . $summary['scores']['cta'] . "/100\n";
                $message .= "  - Readability: " . $summary['scores']['readability'] . "/100\n";
                $message .= "  - Engagement: " . $summary['scores']['engagement'] . "/100\n";
                $message .= "  - Trust: " . $summary['scores']['trust'] . "/100\n\n";
            }

            $message .= "KEY INSIGHTS:\n";
            $message .= "---------------------------------\n";
            $message .= "Top Performers: Your strongest areas are " . ucfirst($strongest_areas[0]) . " (averaging " . $avg_scores[$strongest_areas[0]] . "/100)\n";
            $message .= "                and " . ucfirst($strongest_areas[1]) . " (averaging " . $avg_scores[$strongest_areas[1]] . "/100)\n\n";
            $message .= "Focus Areas: Prioritize improvements in " . ucfirst($weakest_areas[0]) . " (averaging " . $avg_scores[$weakest_areas[0]] . "/100)\n";
            $message .= "             and " . ucfirst($weakest_areas[1]) . " (averaging " . $avg_scores[$weakest_areas[1]] . "/100)\n\n";

            $message .= "NEXT STEPS:\n";
            $message .= "---------------------------------\n";
            $message .= "1. Review the attached PDF reports for detailed analysis and recommendations\n";
            $message .= "2. Prioritize changes based on the scores and suggestions provided\n";
            $message .= "3. Schedule implementation - Reach out to " . $brand_company . " for a call to discuss and implement recommendations\n\n";

            $message .= "ATTACHED FILES:\n";
            $message .= "- " . count($attachments) . " detailed PDF report" . (count($attachments) !== 1 ? 's' : '') . " with page-specific recommendations\n\n";

            $message .= "---\n";
            $message .= $brand_product . " - Powered by " . $brand_company . "\n";
            $message .= "Need help? Contact us at " . $brand_support_email . "\n";

            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . $brand_product . ' - ' . $site_name . ' <noreply@' . parse_url(get_site_url(), PHP_URL_HOST) . '>',
                'Reply-To: ' . $brand_company . ' Support <' . $brand_support_email . '>'
            );
        }

        $msg = '📧 Preparing to send email to: ' . implode(', ', $valid_emails) . ($is_basecamp ? ' (Basecamp - Plain Text)' : ' (HTML)');
        error_log($msg);
        $messages[] = $msg;
        
        $msg = '📎 Total attachments: ' . count($attachments) . ' PDF file(s)';
        error_log($msg);
        $messages[] = $msg;
        
        if (count($attachments) > 0) {
            $messages[] = '📎 Attachment details:';
            error_log('📎 Attachment details:');
            $total_size = 0;
            foreach ($attachments as $idx => $file) {
                $size = file_exists($file) ? filesize($file) : 0;
                $total_size += $size;
                $msg = '   ' . ($idx + 1) . '. ' . basename($file) . ' (' . round($size / 1024, 2) . ' KB)';
                error_log($msg);
                $messages[] = $msg;
            }
            $msg = '📎 Total attachment size: ' . round($total_size / 1024, 2) . ' KB';
            error_log($msg);
            $messages[] = $msg;
            
            // Warn if total size is large
            if ($total_size > 10 * 1024 * 1024) { // 10 MB
                $msg = '⚠️ Warning: Total attachment size exceeds 10MB - may be rejected by some email servers';
                error_log($msg);
                $messages[] = $msg;
            }
        }
        else {
            $msg = '⚠️ No PDF attachments generated - email will be sent without reports';
            error_log($msg);
            $messages[] = $msg;
        }

        // Send email with PDF attachments to all recipients
        $sent = wp_mail($valid_emails, $subject, $message, $headers, $attachments);

        if ($sent) {
            $msg = '✅ Email queued successfully for ' . count($valid_emails) . ' recipient(s): ' . implode(', ', $valid_emails);
            error_log($msg);
            $messages[] = $msg;
        }
        else {
            $msg = '❌ Failed to send email to: ' . implode(', ', $valid_emails);
            error_log($msg);
            $messages[] = $msg;
            
            global $phpmailer;
            if (isset($phpmailer) && is_object($phpmailer)) {
                $msg = '❌ PHPMailer Error: ' . $phpmailer->ErrorInfo;
                error_log($msg);
                $messages[] = $msg;
            }
            else {
                $msg = '❌ PHPMailer object not available for debugging';
                error_log($msg);
                $messages[] = $msg;
            }
        }

        return array('success' => $sent, 'messages' => $messages);
    }

    /**
     * Deactivate scheduled events
     */
    public static function deactivate()
    {
        $timestamp = wp_next_scheduled('conversioniq_automated_audit');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'conversioniq_automated_audit');
        }
    }
}
