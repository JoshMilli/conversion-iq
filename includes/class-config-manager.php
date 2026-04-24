<?php
if (!defined('ABSPATH')) {
    exit;
}

class ConversionIQ_Config_Manager
{
    const BRANDING_OPTION = 'conversioniq_branding_config';
    const FEATURE_FLAGS_OPTION = 'conversioniq_feature_flags';
    const CONFIG_UPDATED_OPTION = 'conversioniq_saas_config_updated_at';

    /**
     * Default branding config — used when no remote config is cached.
     * These match the current hardcoded Webtec branding.
     */
    private static function get_defaults()
    {
        return array(
            'company_name'   => 'Webtec',
            'product_name'   => 'Conversion IQ',
            'logo_url'       => '',  // Empty = use bundled Webtec.png
            'primary_color'  => '#1e3a5f',
            'accent_color'   => '#2563eb',
            'light_color'    => '#dbeafe',
            'support_email'  => 'support@trywebtec.com',
            'website_url'    => 'https://trywebtec.com',
            'contact_url'    => 'https://trywebtec.com/contact',
            'booking_url'    => 'https://calendly.com/webtec-website/success-meeting',
            'tagline'        => '',
            'email_reply_to' => 'support@trywebtec.com',
            'hide_powered_by' => false,
            'faq_items'      => array(),
        );
    }

    /**
     * Get the merged branding config: defaults + any remote overrides.
     */
    public static function get_branding()
    {
        $defaults = self::get_defaults();
        $cached = get_option(self::BRANDING_OPTION, null);

        if (!$cached || !is_array($cached)) {
            return $defaults;
        }

        // Merge cached over defaults so any new default keys are present
        return array_merge($defaults, $cached);
    }

    /**
     * Get a single branding value.
     */
    public static function get($key, $fallback = '')
    {
        $branding = self::get_branding();
        return isset($branding[$key]) ? $branding[$key] : $fallback;
    }

    /**
     * Get the current license plan name.
     */
    public static function get_plan()
    {
        $customer = get_option('conversioniq_license_customer', null);
        if ($customer && is_array($customer) && !empty($customer['plan'])) {
            return strtolower($customer['plan']);
        }
        return 'free'; // default to free tier
    }

    /**
     * Plan-based feature defaults. These are the baseline when no remote
     * feature flags have been synced from the API yet.
     * API-provided flags always override these.
     */
    private static function get_plan_defaults()
    {
        $plans = array(
            'free' => array(
                'max_sites'              => 1,
                'max_pages_per_audit'    => 1,
                'audits_per_week'        => 3,
                'conversion_scores'      => 6,
                'ai_copy_suggestions'    => false,
                'priority_quick_wins'    => false,
                'automated_reports'      => true,
                'pdf_export'             => false,
                'knockknock'             => false,
                'suggestions_unlocked'   => false,
                'custom_branding'        => false,
                'white_label_emails'     => false,
                'priority_support'       => false,
                'custom_faq'             => false,
                'hide_powered_by'        => false,
                'client_management'      => false,
                'sub_license_distribution' => false,
            ),
            'starter' => array(
                'max_sites'              => 1,
                'max_pages_per_audit'    => 2,
                'audits_per_week'        => 3,
                'conversion_scores'      => 6,
                'ai_copy_suggestions'    => true,
                'priority_quick_wins'    => true,
                'automated_reports'      => true,
                'pdf_export'             => true,
                'knockknock'             => false,
                'suggestions_unlocked'   => true,
                'custom_branding'        => false,
                'white_label_emails'     => false,
                'priority_support'       => false,
                'custom_faq'             => false,
                'hide_powered_by'        => false,
                'client_management'      => false,
                'sub_license_distribution' => false,
            ),
            'professional' => array(
                'max_sites'              => 1,
                'max_pages_per_audit'    => 4,
                'audits_per_week'        => 3,
                'conversion_scores'      => 6,
                'ai_copy_suggestions'    => true,
                'priority_quick_wins'    => true,
                'automated_reports'      => true,
                'pdf_export'             => true,
                'knockknock'             => false,
                'suggestions_unlocked'   => true,
                'custom_branding'        => false,
                'white_label_emails'     => false,
                'priority_support'       => true,
                'custom_faq'             => false,
                'hide_powered_by'        => false,
                'client_management'      => false,
                'sub_license_distribution' => false,
            ),
            'business' => array(
                'max_sites'              => 1,
                'max_pages_per_audit'    => 6,
                'audits_per_week'        => 3,
                'conversion_scores'      => 6,
                'ai_copy_suggestions'    => true,
                'priority_quick_wins'    => true,
                'automated_reports'      => true,
                'pdf_export'             => true,
                'knockknock'             => true,
                'suggestions_unlocked'   => true,
                'custom_branding'        => false,
                'white_label_emails'     => false,
                'priority_support'       => true,
                'custom_faq'             => false,
                'hide_powered_by'        => false,
                'client_management'      => false,
                'sub_license_distribution' => false,
            ),
            'agency' => array(
                'max_sites'              => 100,
                'max_pages_per_audit'    => 15,
                'audits_per_week'        => 3,
                'conversion_scores'      => 6,
                'ai_copy_suggestions'    => true,
                'priority_quick_wins'    => true,
                'automated_reports'      => true,
                'pdf_export'             => true,
                'knockknock'             => true,
                'suggestions_unlocked'   => true,
                'custom_branding'        => true,
                'white_label_emails'     => true,
                'priority_support'       => true,
                'custom_faq'             => true,
                'hide_powered_by'        => true,
                'client_management'      => true,
                'sub_license_distribution' => true,
            ),
        );

        $plan = self::get_plan();
        return isset($plans[$plan]) ? $plans[$plan] : $plans['free'];
    }

    /**
     * Get feature flags for the current license plan.
     * Remote API flags (synced via get-config) override plan defaults.
     */
    public static function get_feature_flags()
    {
        $defaults = self::get_plan_defaults();

        $cached = get_option(self::FEATURE_FLAGS_OPTION, null);
        if (!$cached || !is_array($cached)) {
            return $defaults;
        }

        // Remote cache can ADD features (e.g. beta flags, manual overrides)
        // but cannot REMOVE features the current plan grants.
        // Plan defaults are the authoritative floor.
        $merged = array_merge($defaults, $cached);
        foreach ($defaults as $key => $val) {
            if ($val === true) {
                $merged[$key] = true; // plan-granted feature, cannot be revoked by stale cache
            }
        }
        return $merged;
    }

    /**
     * Check if a specific feature is enabled for the current plan.
     */
    public static function can($feature)
    {
        $flags = self::get_feature_flags();
        return !empty($flags[$feature]);
    }

    /**
     * Build the logo HTML for reports/emails.
     * Uses remote logo_url if set, otherwise falls back to bundled Webtec.png.
     */
    public static function get_logo_html($style = 'width: 90px; height: auto;')
    {
        $branding = self::get_branding();
        $company  = esc_attr($branding['company_name']);

        // If a remote logo URL is configured, use it directly
        if (!empty($branding['logo_url'])) {
            return '<img src="' . esc_url($branding['logo_url']) . '" alt="' . $company . '" style="' . $style . '" />';
        }

        // Fall back to bundled logo file (base64 for PDF rendering)
        $logo_path = CONVERSION_IQ_DIR . 'assets/images/Webtec.png';
        if (file_exists($logo_path)) {
            $logo_data = base64_encode(file_get_contents($logo_path));
            $logo_html = '<img src="data:image/png;base64,' . $logo_data . '" alt="' . $company . '" style="' . $style . '" />';
            unset($logo_data);
            return $logo_html;
        }

        // Final fallback: text logo
        return '<div style="font-size: 24px; font-weight: bold; color: ' . esc_attr($branding['primary_color']) . ';">' . esc_html($branding['company_name']) . '</div>';
    }

    /**
     * Sync branding config from the SaaS API.
     * Called on license activation and via daily cron.
     */
    public static function sync_from_saas()
    {
        $license_key = get_option('conversioniq_license_key', '');
        if (empty($license_key)) {
            return false;
        }

        $response = wp_remote_post('https://conversioniq-app.com/api/get-config', array(
            'timeout' => 15,
            'body'    => wp_json_encode(array(
                'license_key' => $license_key,
                'site_url'    => get_site_url(),
            )),
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            ciq_log('ConversionIQ Config Sync failed: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            ciq_log('ConversionIQ Config Sync returned HTTP ' . $code);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!$body || !isset($body['branding'])) {
            ciq_log('ConversionIQ Config Sync: invalid response body');
            return false;
        }

        // Sanitize and store branding
        $branding = self::sanitize_branding($body['branding']);
        update_option(self::BRANDING_OPTION, $branding);

        // Store feature flags if present
        if (isset($body['features']) && is_array($body['features'])) {
            update_option(self::FEATURE_FLAGS_OPTION, $body['features']);
        }

        // Store API key if present (Abacus.ai key enabling AI audit features)
        if (!empty($body['api_key'])) {
            update_option('conversioniq_api_key', sanitize_text_field($body['api_key']));
            ciq_log('ConversionIQ Config Sync: API key updated');
        }

        update_option(self::CONFIG_UPDATED_OPTION, time());
        ciq_log('ConversionIQ Config Sync: success');
        return true;
    }

    /**
     * Sanitize branding values from remote API.
     */
    private static function sanitize_branding($raw)
    {
        if (!is_array($raw)) {
            return array();
        }

        $sanitized = array();
        $text_fields = array(
            'company_name', 'product_name', 'tagline',
        );
        $url_fields = array(
            'logo_url', 'website_url', 'contact_url', 'booking_url',
        );
        $email_fields = array(
            'support_email', 'email_reply_to',
        );
        $color_fields = array(
            'primary_color', 'accent_color', 'light_color',
        );

        foreach ($text_fields as $field) {
            if (isset($raw[$field])) {
                $sanitized[$field] = sanitize_text_field($raw[$field]);
            }
        }
        foreach ($url_fields as $field) {
            if (isset($raw[$field])) {
                $sanitized[$field] = esc_url_raw($raw[$field]);
            }
        }
        foreach ($email_fields as $field) {
            if (isset($raw[$field])) {
                $sanitized[$field] = sanitize_email($raw[$field]);
            }
        }
        foreach ($color_fields as $field) {
            if (isset($raw[$field]) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $raw[$field])) {
                $sanitized[$field] = $raw[$field];
            }
        }

        if (isset($raw['hide_powered_by'])) {
            $sanitized['hide_powered_by'] = (bool) $raw['hide_powered_by'];
        }

        if (isset($raw['faq_items']) && is_array($raw['faq_items'])) {
            $sanitized['faq_items'] = array();
            foreach ($raw['faq_items'] as $item) {
                if (isset($item['q']) && isset($item['a'])) {
                    $sanitized['faq_items'][] = array(
                        'q' => sanitize_text_field($item['q']),
                        'a' => wp_kses_post($item['a']),
                    );
                }
            }
        }

        return $sanitized;
    }
}
