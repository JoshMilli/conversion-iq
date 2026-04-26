<?php
/**
 * Plugin Name: Conversion IQ
 * Plugin URI: https://trywebtec.com
 * Description: AI-powered WordPress plugin that audits and improves website copy and conversion clarity.
 * Version: 2.0.65
 * Author: Webtec
 * Author URI: https://trywebtec.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: conversion-iq
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONVERSION_IQ_VERSION', '2.0.65' );
define( 'CONVERSION_IQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONVERSION_IQ_URL', plugin_dir_url( __FILE__ ) );
define( 'CONVERSION_IQ_FILE', __FILE__ );

// Initialize Plugin Update Checker
require CONVERSION_IQ_DIR . 'lib/plugin-update-checker-5.6/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$conversionIQUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/JoshMilli/conversion-iq',
    __FILE__,
    'conversion-iq'
);

$conversionIQUpdateChecker->setBranch('main');

// Authentication: prefer wp-config constant, then wp_option, then skip
if ( defined( 'CONVERSIONIQ_GITHUB_TOKEN' ) ) {
    $conversionIQUpdateChecker->setAuthentication( CONVERSIONIQ_GITHUB_TOKEN );
} elseif ( $gh_token = get_option( 'conversioniq_github_token', '' ) ) {
    $conversionIQUpdateChecker->setAuthentication( $gh_token );
}

// Clear cache after plugin updates
add_action('upgrader_process_complete', function($upgrader_object, $options) {
    if ($options['action'] == 'update' && $options['type'] == 'plugin') {
        if (isset($options['plugins'])) {
            foreach ($options['plugins'] as $plugin) {
                if ($plugin == plugin_basename(__FILE__)) {
                    // Clear WordPress cache
                    wp_cache_flush();
                    
                    // Clear any object cache
                    if (function_exists('wp_cache_flush_group')) {
                        wp_cache_flush_group('conversioniq');
                    }
                    
                    // Clear transients
                    delete_transient('conversioniq_cache');
                    
                    // Force browser cache refresh by updating version option
                    update_option('conversioniq_last_updated', time());
                    
                    ciq_log('Conversion IQ: Cache cleared after update to version ' . CONVERSION_IQ_VERSION);
                }
            }
        }
    }
}, 10, 2);

// Load Composer autoloader if it exists
if ( file_exists( CONVERSION_IQ_DIR . 'vendor/autoload.php' ) ) {
    require_once CONVERSION_IQ_DIR . 'vendor/autoload.php';
}

// Debug logging helper — only writes when WP_DEBUG is enabled
if (!function_exists('ciq_log')) {
    function ciq_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
    }
}

// Include required files
require_once CONVERSION_IQ_DIR . 'includes/class-config-manager.php';
require_once CONVERSION_IQ_DIR . 'includes/class-database.php';
require_once CONVERSION_IQ_DIR . 'includes/rest-api.php';
require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
require_once CONVERSION_IQ_DIR . 'includes/class-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-automated-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-supabase-sync.php';
if ( ConversionIQ_Config_Manager::can('knockknock') ) {
    require_once CONVERSION_IQ_DIR . 'includes/class-knockknock-webhook.php';
}

// Initialize automated reports after WordPress loads
add_action( 'init', function() {
    ConversionIQ_Automated_Reports::init();
    
    // Schedule daily config sync if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_sync_config' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'conversioniq_sync_config' );
    }

    // Schedule weekly DB pruning if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_prune_db' ) ) {
        wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'conversioniq_prune_db' );
    }

    // Schedule 2-minute audit-job poller if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_poll_audit_jobs' ) ) {
        wp_schedule_event( time() + 120, 'conversioniq_twominutes', 'conversioniq_poll_audit_jobs' );
    }
    
    // Force flush rewrite rules if version changed (for new REST endpoints)
    $stored_version = get_option( 'conversioniq_version', '0' );
    if ( version_compare( $stored_version, CONVERSION_IQ_VERSION, '<' ) ) {
        // Update database schema
        ConversionIQ_DB::create_tables();
        flush_rewrite_rules();
        update_option( 'conversioniq_version', CONVERSION_IQ_VERSION );
    }
} );

// Daily config sync cron
add_action( 'conversioniq_sync_config', function() {
    ConversionIQ_Config_Manager::sync_from_saas();
} );

// Weekly DB pruning cron — keep tables from growing unbounded across 300+ sites
add_action( 'conversioniq_prune_db', function() {
    ConversionIQ_DB::prune_old_records();
} );

// ── Audit Jobs Poller ──────────────────────────────────────────────────────
// Runs every 2 minutes to check Supabase for pending audit jobs queued by
// the conversioniq-app.com dashboard. This pull model works for any site,
// including those behind firewalls or on staging domains where inbound
// connections from the dashboard server would fail.

// Register the 2-minute custom interval
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['conversioniq_twominutes'] ) ) {
        $schedules['conversioniq_twominutes'] = array(
            'interval' => 120,
            'display'  => 'Every 2 Minutes (Conversion IQ)',
        );
    }
    return $schedules;
} );

add_action( 'conversioniq_poll_audit_jobs', 'conversioniq_poll_audit_jobs_handler' );

function conversioniq_poll_audit_jobs_handler() {
    $org_id     = get_option( 'conversioniq_organization_id', '' );
    $remote_key = get_option( 'conversioniq_remote_secret', '' );

    if ( empty( $org_id ) || empty( $remote_key ) ) return;

    $saas_base = 'https://conversioniq-app.com/api';
    $headers   = array(
        'X-CIQ-API-Key' => $remote_key,
        'Content-Type'  => 'application/json',
    );

    // Ask the cloud for the next pending job for this site
    $poll_resp = wp_remote_post( $saas_base . '/audit-jobs/poll', array(
        'headers' => $headers,
        'body'    => wp_json_encode( array( 'organization_id' => $org_id ) ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $poll_resp ) ) {
        ciq_log( 'ConversionIQ: audit-jobs/poll failed - ' . $poll_resp->get_error_message() );
        return;
    }

    $poll_body = json_decode( wp_remote_retrieve_body( $poll_resp ), true );
    if ( empty( $poll_body['ok'] ) || empty( $poll_body['job'] ) ) return; // Nothing to do

    $job    = $poll_body['job'];
    $job_id = $job['id'];
    ciq_log( 'ConversionIQ: Claimed audit job ' . $job_id );

    try {
        // Resolve page IDs: job payload → stored tracked pages → homepage fallback
        $page_ids = array();
        if ( ! empty( $job['page_ids'] ) ) {
            $decoded  = is_array( $job['page_ids'] ) ? $job['page_ids'] : json_decode( $job['page_ids'], true );
            $page_ids = is_array( $decoded ) ? $decoded : array();
        }
        if ( empty( $page_ids ) ) {
            $page_ids = get_option( 'conversioniq_tracked_pages', array() );
        }
        if ( empty( $page_ids ) ) {
            $front_id = (int) get_option( 'page_on_front' );
            if ( $front_id > 0 ) {
                $page_ids = array( $front_id );
            } else {
                $fallback = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 1 ) );
                if ( ! empty( $fallback ) ) $page_ids = array( $fallback[0]->ID );
            }
        }

        if ( empty( $page_ids ) ) {
            throw new Exception( 'No pages to audit' );
        }

        // Set an admin user context so audit rate-limiting transient is deterministic
        $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
        if ( ! empty( $admins ) ) wp_set_current_user( $admins[0] );

        // Delegate to the standard audit runner
        $audit_request = new WP_REST_Request( 'POST' );
        $audit_request->set_body( wp_json_encode( array( 'pages' => $page_ids ) ) );
        $audit_request->set_header( 'Content-Type', 'application/json' );

        $result = conversioniq_run_audit( $audit_request );
        $data   = $result->get_data();

        if ( empty( $data['success'] ) ) {
            throw new Exception( $data['message'] ?? 'Audit runner returned failure' );
        }

        // Count successful page audits to report back
        $successful = count( array_filter(
            $data['results'] ?? array(),
            function( $r ) { return empty( $r['failed'] ); }
        ) );

        wp_remote_post( $saas_base . '/audit-jobs/' . rawurlencode( $job_id ) . '/complete', array(
            'headers' => $headers,
            'body'    => wp_json_encode( array( 'audits_created' => $successful ) ),
            'timeout' => 15,
        ) );

        ciq_log( 'ConversionIQ: Audit job ' . $job_id . ' completed (' . $successful . '/' . count( $page_ids ) . ' page(s))' );

    } catch ( Exception $e ) {
        wp_remote_post( $saas_base . '/audit-jobs/' . rawurlencode( $job_id ) . '/fail', array(
            'headers' => $headers,
            'body'    => wp_json_encode( array( 'error_message' => $e->getMessage() ) ),
            'timeout' => 15,
        ) );
        ciq_log( 'ConversionIQ: Audit job ' . $job_id . ' failed - ' . $e->getMessage() );
    }
}
// ── End Audit Jobs Poller ──────────────────────────────────────────────────

// Activation hook
function conversioniq_install() {
    ConversionIQ_DB::create_tables();
    flush_rewrite_rules();
    update_option( 'conversioniq_version', CONVERSION_IQ_VERSION );
    
    // Enable automated audits with no default pages selected
    $admin_email = get_option( 'admin_email' );
    $automated_settings = array(
        'enabled' => false,
        'frequency' => 'weekly',
        'email' => $admin_email,
        'defaultPages' => array()
    );
    update_option( 'conversion_iq_automated_reports', $automated_settings );
    
    // Schedule the cron job if we have pages and email
    if ( $automated_settings['enabled'] && ! empty( $automated_settings['defaultPages'] ) && ! empty( $automated_settings['email'] ) ) {
        // Only schedule if not already scheduled
        if ( ! wp_next_scheduled( 'conversioniq_automated_audit' ) ) {
            $next_run = time() + WEEK_IN_SECONDS;
            wp_schedule_event( $next_run, 'conversioniq_weekly', 'conversioniq_automated_audit' );
        }
    }

    // Auto-push remote audit credentials to Supabase if this site is already registered.
    // (Handles reactivations after plugin deactivation — org ID is already stored.)
    if ( get_option( 'conversioniq_organization_id', '' ) ) {
        try {
            $supabase = new ConversionIQ_Supabase_Sync();
            $supabase->push_remote_credentials();
        } catch ( Exception $e ) {
            ciq_log( 'ConversionIQ: push_remote_credentials on plugin activate: ' . $e->getMessage() );
        }
    }

    // Ensure the 2-minute audit-job poller is scheduled
    if ( ! wp_next_scheduled( 'conversioniq_poll_audit_jobs' ) ) {
        wp_schedule_event( time() + 120, 'conversioniq_twominutes', 'conversioniq_poll_audit_jobs' );
    }
}
register_activation_hook( __FILE__, 'conversioniq_install' );

// Admin menu
add_action( 'admin_menu', function() {
    $product_name = ConversionIQ_Config_Manager::get('product_name', 'Conversion IQ');
    add_menu_page(
        $product_name,
        $product_name,
        'manage_options',
        'conversion-iq',
        'conversioniq_admin_page',
        'dashicons-chart-line',
        56
    );
    
    // Add diagnostic submenu if in debug mode
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        add_submenu_page(
            'conversion-iq',
            __( 'Diagnostics', 'conversion-iq' ),
            __( 'Diagnostics', 'conversion-iq' ),
            'manage_options',
            'conversion-iq-diagnostic',
            'conversioniq_diagnostic_page'
        );
        
        add_submenu_page(
            'conversion-iq',
            __( 'Debug Logs', 'conversion-iq' ),
            __( 'Debug Logs', 'conversion-iq' ),
            'manage_options',
            'conversioniq-logs',
            'conversioniq_logs_page'
        );
    }
} );

function conversioniq_admin_page() {
    // Load admin page template
    include CONVERSION_IQ_DIR . 'admin/dashboard.php';
}

function conversioniq_diagnostic_page() {
    // Load diagnostic page
    include CONVERSION_IQ_DIR . 'admin/diagnostic-report.php';
}

function conversioniq_logs_page() {
    // Load debug logs page
    include CONVERSION_IQ_DIR . 'admin/debug-logs.php';
}

// Enqueue admin assets
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'toplevel_page_conversion-iq' ) {
        return;
    }

    ciq_log( 'Conversion IQ: Enqueueing admin assets for hook: ' . $hook );

    // CSS - base admin styles
    wp_enqueue_style( 'conversioniq-admin', CONVERSION_IQ_URL . 'assets/css/admin.css', array(), CONVERSION_IQ_VERSION );

    // Dynamically find the built JS and CSS files from Vite
    $assets_dir = CONVERSION_IQ_DIR . 'admin/build/vite-dist/assets/';
    $assets_url = CONVERSION_IQ_URL . 'admin/build/vite-dist/assets/';
    
    ciq_log( 'Conversion IQ: Looking for assets in: ' . $assets_dir );
    
    $js_file = null;
    $css_file = null;
    
    if ( is_dir( $assets_dir ) ) {
        $files = scandir( $assets_dir );
        ciq_log( 'Conversion IQ: Found files: ' . print_r( $files, true ) );
        
        foreach ( $files as $file ) {
            if ( strpos( $file, 'index.' ) === 0 && substr( $file, -3 ) === '.js' ) {
                $js_file = $file;
                ciq_log( 'Conversion IQ: Found JS file: ' . $js_file );
            }
            if ( strpos( $file, 'index.' ) === 0 && substr( $file, -4 ) === '.css' ) {
                $css_file = $file;
                ciq_log( 'Conversion IQ: Found CSS file: ' . $css_file );
            }
        }
    } else {
        ciq_log( 'Conversion IQ: Assets directory does not exist: ' . $assets_dir );
    }
    
    // Dashboard app bundle (built)
    if ( $js_file ) {
        // Add timestamp for cache busting after updates
        $cache_buster = CONVERSION_IQ_VERSION . '.' . get_option('conversioniq_last_updated', time());
        
        $script_url = $assets_url . $js_file;
        ciq_log( 'Conversion IQ: Enqueueing script from: ' . $script_url );
        
        wp_enqueue_script(
            'conversion-iq-admin',
            $script_url,
            [],
            $cache_buster,
            true
        );
        
        // Localize data for the React app - MUST come after wp_enqueue_script
        $nonce = wp_create_nonce( 'wp_rest' );
        $localized_data = array(
            'restUrl' => esc_url_raw( rest_url( 'conversioniq/v1/' ) ),
            'nonce'   => $nonce,
            'pluginUrl' => CONVERSION_IQ_URL,
            'version' => $cache_buster,
        );
        ciq_log( 'Conversion IQ: Localizing script data: ' . wp_json_encode( $localized_data ) );
        wp_localize_script( 'conversion-iq-admin', 'ConversionIQData', $localized_data );
        
        // Set type="module" for the dashboard bundle
        // Also add error handling script
        add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
            if ( $handle === 'conversion-iq-admin' ) {
                ciq_log( 'Conversion IQ: Setting module type for script: ' . $handle );
                $tag = str_replace( '<script ', '<script type="module" ', $tag );
                // Add error event handler to catch module loading errors
                $tag = str_replace( '<script type="module" ', '<script type="module" onError="console.error(\'Conversion IQ module failed to load:\', event)" ', $tag );
                return $tag;
            }
            return $tag;
        }, 10, 3 );
        
        // Inline script to ensure ConversionIQData is available before the app loads
        wp_add_inline_script( 'conversion-iq-admin', 'console.log("Conversion IQ: Admin script loaded. Version:", ConversionIQData?.version);', 'before' );
    } else {
        ciq_log( 'Conversion IQ: ERROR - No JS file found!' );
    }

    // Enqueue built CSS bundle if it exists
    if ( $css_file ) {
        $cache_buster = CONVERSION_IQ_VERSION . '.' . get_option('conversioniq_last_updated', time());
        wp_enqueue_style( 'conversioniq-dashboard-css', $assets_url . $css_file, array('conversioniq-admin'), $cache_buster );
    }
} );

// Load textdomain for translations
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'conversion-iq', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
} );

// AJAX handler to check plugin status
add_action( 'wp_ajax_conversioniq_status', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    
    $status = array(
        'version' => CONVERSION_IQ_VERSION,
        'rest_api' => rest_get_routes() ? true : false,
        'plugin_url' => CONVERSION_IQ_URL,
        'plugin_dir' => CONVERSION_IQ_DIR,
        'assets_exist' => is_dir( CONVERSION_IQ_DIR . 'admin/build/vite-dist/assets/' ),
    );
    
    wp_send_json_success( $status );
} );

// AJAX handler to clear cache
add_action( 'wp_ajax_conversioniq_clear_cache', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    
    wp_cache_flush();
    delete_transient( 'conversioniq_cache' );
    update_option( 'conversioniq_last_updated', time() );
    
    wp_send_json_success( 'Cache cleared' );
} );

// Handle Google Analytics OAuth callback
add_action( 'admin_init', function() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'conversioniq' && isset( $_GET['code'] ) && isset( $_GET['ga_callback'] ) ) {
        $ga = new ConversionIQ_Google_Analytics();
        $result = $ga->exchange_code( $_GET['code'] );
        
        if ( $result['success'] ) {
            wp_redirect( admin_url( 'admin.php?page=conversioniq&ga_connected=1' ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=conversioniq&ga_error=' . urlencode( $result['error'] ) ) );
        }
        exit;
    }
} );

