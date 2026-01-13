<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options and tables
global $wpdb;
$table = $wpdb->prefix . 'conversioniq_audits';
$wpdb->query( "DROP TABLE IF EXISTS $table" );
delete_option( 'conversion_iq_settings' );
delete_option( 'conversioniq_api_key' );
