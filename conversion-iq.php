<?php
/**
 * Plugin Name: Conversion IQ
 * Plugin URI: https://trywebtec.com
 * Description: AI-powered WordPress plugin that audits and improves website copy and conversion clarity.
 * Version: 1.7.6
 * Author: Webtec
 * Author URI: https://trywebtec.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: conversion-iq
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONVERSION_IQ_VERSION', '1.7.6' );
define( 'CONVERSION_IQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONVERSION_IQ_URL', plugin_dir_url( __FILE__ ) );
define( 'CONVERSION_IQ_FILE', __FILE__ );

// Initialize Plugin Update Checker
require CONVERSION_IQ_DIR . 'lib/plugin-update-checker-5.6/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$conversionIQUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/JoshMilli/conversion-iq', // Replace with your GitHub repo URL
    __FILE__,
    'conversion-iq'
);

// Set the branch to check for updates (use 'main' or 'master' depending on your repo)
$conversionIQUpdateChecker->setBranch('main');

// Authentication for private repository
$conversionIQUpdateChecker->setAuthentication('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');

// Check for updates more frequently (every 1 hour instead of default 12 hours)
$conversionIQUpdateChecker->checkForUpdates();

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
                    
                    error_log('Conversion IQ: Cache cleared after update to version ' . CONVERSION_IQ_VERSION);
                }
            }
        }
    }
}, 10, 2);

// Load Composer autoloader if it exists
if ( file_exists( CONVERSION_IQ_DIR . 'vendor/autoload.php' ) ) {
    require_once CONVERSION_IQ_DIR . 'vendor/autoload.php';
}

// Include required files
require_once CONVERSION_IQ_DIR . 'includes/class-database.php';
require_once CONVERSION_IQ_DIR . 'includes/rest-api.php';
require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
require_once CONVERSION_IQ_DIR . 'includes/class-google-analytics.php';
require_once CONVERSION_IQ_DIR . 'includes/class-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-automated-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-supabase-sync.php';

// Initialize automated reports after WordPress loads
add_action( 'init', function() {
    ConversionIQ_Automated_Reports::init();
    
    // Force flush rewrite rules if version changed (for new REST endpoints)
    $stored_version = get_option( 'conversioniq_version', '0' );
    if ( version_compare( $stored_version, CONVERSION_IQ_VERSION, '<' ) ) {
        // Update database schema
        ConversionIQ_DB::create_tables();
        flush_rewrite_rules();
        update_option( 'conversioniq_version', CONVERSION_IQ_VERSION );
    }
} );

// Activation hook
function conversioniq_install() {
    ConversionIQ_DB::create_tables();
    flush_rewrite_rules();
    update_option( 'conversioniq_version', CONVERSION_IQ_VERSION );
    
    // Get the homepage ID
    $homepage_id = get_option( 'page_on_front' );
    if ( ! $homepage_id ) {
        // Fallback: get the first published page
        $first_page = get_posts( array(
            'post_type' => 'page',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'ASC',
            'post_status' => 'publish'
        ) );
        if ( ! empty( $first_page ) ) {
            $homepage_id = $first_page[0]->ID;
        }
    }
    
    // Enable automated audits with homepage as default page
    $admin_email = get_option( 'admin_email' );
    $automated_settings = array(
        'enabled' => true,
        'frequency' => 'weekly',
        'email' => $admin_email,
        'defaultPages' => $homepage_id ? array( $homepage_id ) : array()
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
}
register_activation_hook( __FILE__, 'conversioniq_install' );

// Admin menu
add_action( 'admin_menu', function() {
    add_menu_page(
        __( 'Conversion IQ', 'conversion-iq' ),
        __( 'Conversion IQ', 'conversion-iq' ),
        'manage_options',
        'conversion-iq',
        'conversioniq_admin_page',
        'dashicons-chart-line',
        56
    );
} );

function conversioniq_admin_page() {
    // Load admin page template
    include CONVERSION_IQ_DIR . 'admin/dashboard.php';
}

// Enqueue admin assets
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'toplevel_page_conversion-iq' ) {
        return;
    }

    // CSS - base admin styles
    wp_enqueue_style( 'conversioniq-admin', CONVERSION_IQ_URL . 'assets/css/admin.css', array(), CONVERSION_IQ_VERSION );

    // Dynamically find the built JS and CSS files from Vite
    $assets_dir = CONVERSION_IQ_DIR . 'admin/build/vite-dist/assets/';
    $assets_url = CONVERSION_IQ_URL . 'admin/build/vite-dist/assets/';
    
    $js_file = null;
    $css_file = null;
    
    if ( is_dir( $assets_dir ) ) {
        $files = scandir( $assets_dir );
        foreach ( $files as $file ) {
            if ( strpos( $file, 'index.' ) === 0 && substr( $file, -3 ) === '.js' ) {
                $js_file = $file;
            }
            if ( strpos( $file, 'index.' ) === 0 && substr( $file, -4 ) === '.css' ) {
                $css_file = $file;
            }
        }
    }
    
    // Dashboard app bundle (built)
    if ( $js_file ) {
        // Add timestamp for cache busting after updates
        $cache_buster = CONVERSION_IQ_VERSION . '.' . get_option('conversioniq_last_updated', time());
        
        wp_enqueue_script(
            'conversion-iq-admin',
            $assets_url . $js_file,
            [],
            $cache_buster,
            true
        );
        
        // Localize data for the React app
        $nonce = wp_create_nonce( 'wp_rest' );
        wp_localize_script( 'conversion-iq-admin', 'ConversionIQData', array(
            'restUrl' => esc_url_raw( rest_url( 'conversioniq/v1/' ) ),
            'nonce'   => $nonce,
            'pluginUrl' => CONVERSION_IQ_URL,
            'version' => $cache_buster,
        ) );
        
        // Set type="module" for the dashboard bundle
        add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
            if ( $handle === 'conversion-iq-admin' ) {
                return str_replace( '<script ', '<script type="module" ', $tag );
            }
            return $tag;
        }, 10, 3 );
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

