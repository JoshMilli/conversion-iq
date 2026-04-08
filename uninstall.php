<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove all plugin tables
global $wpdb;
$tables = array(
    $wpdb->prefix . 'conversioniq_audits',
    $wpdb->prefix . 'conversioniq_webhook_logs',
    $wpdb->prefix . 'conversioniq_leads',
    $wpdb->prefix . 'conversioniq_visitor_sessions',
    $wpdb->prefix . 'conversioniq_page_analytics',
);
foreach ($tables as $table) {
    $wpdb->query( "DROP TABLE IF EXISTS $table" );
}

// Remove all plugin options
$options = array(
    'conversion_iq_settings',
    'conversion_iq_automated_reports',
    'conversioniq_api_key',
    'conversioniq_version',
    'conversioniq_last_updated',
    'conversioniq_license_key',
    'conversioniq_license_status',
    'conversioniq_license_validated_at',
    'conversioniq_license_customer',
    'conversioniq_branding_config',
    'conversioniq_feature_flags',
    'conversioniq_saas_config_updated_at',
    'conversioniq_ga_credentials',
    'conversioniq_github_token',
    'conversioniq_knockknock_company_id',
    'conversioniq_knockknock_webhook_secret',
    'conversioniq_organization_id',
    'conversioniq_account',
);
foreach ($options as $option) {
    delete_option($option);
}

// Clear scheduled cron jobs
$timestamp = wp_next_scheduled('conversioniq_automated_audit');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'conversioniq_automated_audit');
}

// Clean up transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ciq_%' OR option_name LIKE '_transient_timeout_ciq_%'");
