<?php
/**
 * Plugin Name: Conversion IQ
 * Plugin URI: https://trywebtec.com
 * Description: AI-powered WordPress plugin that audits and improves website copy and conversion clarity.
 * Version: 2.0.92
 * Author: Webtec
 * Author URI: https://trywebtec.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: conversion-iq
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONVERSION_IQ_VERSION', '2.0.92' );
define( 'CONVERSION_IQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONVERSION_IQ_URL', plugin_dir_url( __FILE__ ) );
define( 'CONVERSION_IQ_FILE', __FILE__ );

// Google PageSpeed Insights API key — used by conversioniq_fetch_core_web_vitals().
// Can be overridden per-environment by defining CONVERSIONIQ_PAGESPEED_KEY in wp-config.php.
if ( ! defined( 'CONVERSIONIQ_PAGESPEED_KEY' ) ) {
    define( 'CONVERSIONIQ_PAGESPEED_KEY', 'AIzaSyAtH41-fIhW2ywWvS1RsC3Yg_Vton6TyhM' );
}

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

// Debug logging helper — always writes to PHP error log
if (!function_exists('ciq_log')) {
    function ciq_log($message) {
        error_log('[CIQ] ' . $message);
    }
}

// Include required files
require_once CONVERSION_IQ_DIR . 'includes/class-config-manager.php';
require_once CONVERSION_IQ_DIR . 'includes/class-database.php';
require_once CONVERSION_IQ_DIR . 'includes/rest-api.php';
require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
require_once CONVERSION_IQ_DIR . 'includes/class-seo-analyzer.php';
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

    // Heatmap sync: remove old-style scheduled event if it exists (replaced by admin_init fallback)
    $heatmap_cron = wp_next_scheduled( 'conversioniq_heatmap_sync' );
    if ( $heatmap_cron ) {
        wp_unschedule_event( $heatmap_cron, 'conversioniq_heatmap_sync' );
    }

    // Schedule 2-minute audit-job poller if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_poll_audit_jobs' ) ) {
        wp_schedule_event( time() + 120, 'conversioniq_twominutes', 'conversioniq_poll_audit_jobs' );
    }

    // Schedule weekly SEO full-site sweep if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_seo_sweep' ) ) {
        wp_schedule_event( time() + 2 * DAY_IN_SECONDS, 'weekly', 'conversioniq_seo_sweep' );
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

// ── SEO Audit: auto-trigger on save_post ──────────────────────────────────
// When a page or post is published/updated, schedule a deferred SEO audit so
// the SaaS dashboard always has fresh scores without blocking the save response.
add_action( 'save_post', function( $post_id, $post, $update ) {
    // Only licensed installs
    if ( ! get_option( 'conversioniq_api_key' ) ) {
        return;
    }
    // Only pages and posts
    if ( ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
        return;
    }
    // Only published content
    if ( $post->post_status !== 'publish' ) {
        return;
    }
    // Skip autosaves and revisions
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    // Throttle: no more than one auto-audit per post per 60 seconds
    if ( get_transient( 'ciq_seo_autosave_' . $post_id ) ) {
        return;
    }
    set_transient( 'ciq_seo_autosave_' . $post_id, 1, 60 );

    // Defer by 30 s so all postmeta (Yoast/RankMath) is flushed before we read it
    wp_schedule_single_event( time() + 30, 'conversioniq_seo_audit_single', array( $post_id ) );
    ciq_log( 'SEO auto-audit: scheduled for post_id=' . $post_id . ' ("' . $post->post_title . '") in 30s' );
}, 10, 3 );

// Handler for deferred single-page SEO audit
add_action( 'conversioniq_seo_audit_single', function( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) {
        return;
    }
    ciq_log( 'SEO auto-audit: running for post_id=' . $post_id . ' ("' . $post->post_title . '")' );

    $result = ConversionIQ_SEO_Analyzer::analyze( $post_id );
    if ( is_wp_error( $result ) ) {
        ciq_log( 'SEO auto-audit: error — ' . $result->get_error_message() );
        return;
    }

    $supabase = new ConversionIQ_Supabase_Sync();
    $supabase->send_seo_audit( $result );
    ciq_log( 'SEO auto-audit: synced — score=' . $result['overall_score'] . ' for post_id=' . $post_id );
} );

// ── SEO Audit: weekly full-site sweep ────────────────────────────────────
add_action( 'conversioniq_seo_sweep', function() {
    if ( ! get_option( 'conversioniq_api_key' ) ) {
        return;
    }

    ciq_log( 'SEO sweep: starting weekly full-site audit' );

    // Load the last-audited map: post_id => unix timestamp
    $last_audited = get_option( 'conversioniq_seo_last_audited', array() );
    $cutoff       = time() - 7 * DAY_IN_SECONDS;

    // Get all published pages + posts ordered by ID so we page through consistently
    $candidates = get_posts( array(
        'post_type'      => array( 'page', 'post' ),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ) );

    // Filter to those not audited in the last 7 days
    $due = array_filter( $candidates, function( $id ) use ( $last_audited, $cutoff ) {
        return empty( $last_audited[ $id ] ) || $last_audited[ $id ] < $cutoff;
    } );

    // Sort oldest-audited first so we always make progress
    usort( $due, function( $a, $b ) use ( $last_audited ) {
        return ( $last_audited[ $a ] ?? 0 ) - ( $last_audited[ $b ] ?? 0 );
    } );

    // Process up to 5 per cron run to stay well within execution limits
    $batch   = array_slice( $due, 0, 5 );
    $synced  = 0;
    $supabase = new ConversionIQ_Supabase_Sync();

    foreach ( $batch as $post_id ) {
        $result = ConversionIQ_SEO_Analyzer::analyze( $post_id );
        if ( is_wp_error( $result ) ) {
            ciq_log( 'SEO sweep: error for post_id=' . $post_id . ' — ' . $result->get_error_message() );
            continue;
        }
        $supabase->send_seo_audit( $result );
        $last_audited[ $post_id ] = time();
        $synced++;
        ciq_log( 'SEO sweep: audited post_id=' . $post_id . ' score=' . $result['overall_score'] );
    }

    update_option( 'conversioniq_seo_last_audited', $last_audited );
    ciq_log( 'SEO sweep: done — audited ' . $synced . ' of ' . count( $due ) . ' due pages (' . count( $candidates ) . ' total)' );
} );

// Nightly heatmap summary sync (legacy cron hook — kept in case someone re-schedules it)
add_action( 'conversioniq_heatmap_sync', 'conversioniq_heatmap_sync_daily' );

// ── Heatmap daily sync fallback — fires on admin_init, once per UTC day ──────
// More reliable than WP-Cron alone: runs the first time any admin visits the
// dashboard after midnight UTC, guaranteeing yesterday's data gets synced even
// on low-traffic sites where no page load happens at the scheduled 3am time.
add_action( 'admin_init', function() {
    // Only sync when a license is active
    if ( ! get_option( 'conversioniq_api_key' ) ) {
        return;
    }

    $today_utc = gmdate( 'Y-m-d' );
    $last_sync = get_option( 'conversioniq_heatmap_last_sync_date', '' );

    // Already synced today — nothing to do
    if ( $last_sync === $today_utc ) {
        return;
    }

    // Prevent concurrent runs (e.g. two admin tabs open at once)
    if ( get_transient( 'ciq_heatmap_sync_lock' ) ) {
        return;
    }
    set_transient( 'ciq_heatmap_sync_lock', 1, 300 ); // 5-minute lock

    // Mark as run for today before the sync so a page reload doesn\'t double-run
    update_option( 'conversioniq_heatmap_last_sync_date', $today_utc );

    ciq_log( '🔄 Heatmap admin_init sync: running for ' . $today_utc );
    conversioniq_heatmap_sync_daily();
} );

// ── Audit Jobs Poller ──────────────────────────────────────────────────────

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

// Fallback: also run on every admin page load, throttled to once per 2 minutes
// via a transient. This guarantees the poll fires even on low-traffic sites
// where WP-Cron never gets a chance to tick.
add_action( 'admin_init', function() {
    if ( get_transient( 'ciq_poll_throttle' ) ) return;
    set_transient( 'ciq_poll_throttle', 1, 120 );
    conversioniq_poll_audit_jobs_handler();
} );

function conversioniq_poll_audit_jobs_handler() {
    ciq_log( 'poll_audit_jobs: handler fired at ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC' );

    $org_id = get_option( 'conversioniq_organization_id', '' );
    if ( ! $org_id ) {
        ciq_log( 'poll_audit_jobs: no organization_id set — skipping' );
        return;
    }
    ciq_log( 'poll_audit_jobs: org=' . $org_id );

    $supabase = new ConversionIQ_Supabase_Sync();
    $job      = $supabase->fetch_pending_job();

    if ( ! $job ) {
        ciq_log( 'poll_audit_jobs: no pending jobs found' );
        return;
    }

    $job_id = $job['id'];
    ciq_log( 'ConversionIQ: Claimed audit job ' . $job_id );

    // Claim immediately so no other cron tick double-runs it
    $supabase->mark_job_running( $job_id );

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

        $supabase->mark_job_complete( $job_id );
        ciq_log( 'ConversionIQ: Audit job ' . $job_id . ' completed (' . count( $page_ids ) . ' page(s))' );

    } catch ( Exception $e ) {
        $supabase->mark_job_failed( $job_id, $e->getMessage() );
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

// Enqueue heatmap tracker on public-facing pages when license is active
add_action( 'wp_enqueue_scripts', function() {
    // Only track front-end pages, not admin
    if ( is_admin() ) {
        return;
    }

    // Only track when license is active (api_key is present)
    $api_key = get_option( 'conversioniq_api_key', '' );
    if ( empty( $api_key ) ) {
        return;
    }

    wp_enqueue_script(
        'ciq-heatmap-tracker',
        CONVERSION_IQ_URL . 'assets/js/ciq-heatmap-tracker.js',
        array(),
        CONVERSION_IQ_VERSION,
        true // load in footer
    );

    wp_localize_script( 'ciq-heatmap-tracker', 'ciqTrackerConfig', array(
        'endpoint' => esc_url_raw( rest_url( 'conversioniq/v1/heatmap/record' ) ),
    ) );
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

