<?php
/**
 * Plugin Name: Conversion IQ
 * Plugin URI: https://trywebtec.com
 * Description: AI-powered WordPress plugin that audits and improves website copy and conversion clarity.
 * Version: 2.5.10
 * Author: Webtec
 * Author URI: https://trywebtec.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: conversion-iq
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONVERSION_IQ_VERSION', '2.5.10' );
define( 'CONVERSION_IQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONVERSION_IQ_URL', plugin_dir_url( __FILE__ ) );
define( 'CONVERSION_IQ_FILE', __FILE__ );

// Google PageSpeed Insights API key — used by conversioniq_fetch_core_web_vitals().
// Can be overridden per-environment by defining CONVERSIONIQ_PAGESPEED_KEY in wp-config.php.
if ( ! defined( 'CONVERSIONIQ_PAGESPEED_KEY' ) ) {
    define( 'CONVERSIONIQ_PAGESPEED_KEY', 'AIzaSyAtH41-fIhW2ywWvS1RsC3Yg_Vton6TyhM' );
}

// Google OAuth 2.0 — shared Webtec app used by all Conversion IQ installations.
// Credentials are NOT stored in this file.  They are pushed by the SaaS config-sync
// endpoint and stored in wp_options('conversioniq_google_oauth_credentials').
// Clients who want to use their own Google Cloud project can still override via
// wp-config.php constants (CIQ_GOOGLE_CLIENT_ID / CIQ_GOOGLE_CLIENT_SECRET).
if ( ! defined( 'CIQ_GOOGLE_CLIENT_ID' ) ) {
    $ciq_oauth_cfg = function_exists( 'get_option' )
        ? get_option( 'conversioniq_google_oauth_credentials', array() )
        : array();
    define( 'CIQ_GOOGLE_CLIENT_ID',     ! empty( $ciq_oauth_cfg['client_id'] )     ? $ciq_oauth_cfg['client_id']     : '' );
    define( 'CIQ_GOOGLE_CLIENT_SECRET', ! empty( $ciq_oauth_cfg['client_secret'] ) ? $ciq_oauth_cfg['client_secret'] : '' );
    unset( $ciq_oauth_cfg );
}
if ( ! defined( 'CIQ_GOOGLE_REDIRECT_URI' ) ) {
    // SaaS proxy handles the code exchange then forwards the result back to the
    // originating WordPress site.  Override with the direct WP-admin URL in
    // wp-config.php only for local/staging environments.
    define( 'CIQ_GOOGLE_REDIRECT_URI', 'https://conversioniq-app.com/oauth/google/callback' );
}

// ── Plugin Update Checker — self-hosted update channel ────────────────────────
// Conversion IQ is distributed from our own server (not WordPress.org). The Plugin
// Update Checker polls a JSON "update info" endpoint for the latest version and, when
// it is newer than the version in THIS file's header, injects the update into
// WordPress's standard update transient (pre_set_site_transient_update_plugins) and
// the "View details" popup (plugins_api). That lets WP core, background auto-updates,
// and external maintenance tools (e.g. WP Umbrella) detect and install it. The
// installed version is read dynamically from the plugin header; the remote check is
// cached for ~12 hours; and unreachable/invalid responses fail quietly.
require CONVERSION_IQ_DIR . 'lib/plugin-update-checker-5.6/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Endpoint that returns the update-info JSON. Override in wp-config.php
// (define CONVERSIONIQ_UPDATE_INFO_URL) or via the conversioniq_update_info_url option.
if ( ! defined( 'CONVERSIONIQ_UPDATE_INFO_URL' ) ) {
    define( 'CONVERSIONIQ_UPDATE_INFO_URL', 'https://conversioniq-app.com/api/plugin-info' );
}
$ciq_update_info_url = get_option( 'conversioniq_update_info_url', '' );
if ( empty( $ciq_update_info_url ) ) {
    $ciq_update_info_url = CONVERSIONIQ_UPDATE_INFO_URL;
}

$conversionIQUpdateChecker = PucFactory::buildUpdateChecker(
    $ciq_update_info_url, // self-hosted JSON metadata URL (must NOT be a github/gitlab host)
    __FILE__,             // main plugin file — installed version is read from its header
    'conversion-iq',      // slug — MUST match the plugin folder and the zip's internal folder
    12                    // check every ~12 hours; result cached in a WP transient
);

// Send the site's license key + domain with every update check and download request so
// the server can authorise the client and serve the correct zip. These are already
// stored on the site (not new secrets); a fully public endpoint may simply ignore them.
if ( method_exists( $conversionIQUpdateChecker, 'addQueryArgFilter' ) ) {
    $conversionIQUpdateChecker->addQueryArgFilter( function ( $query_args ) {
        $license = get_option( 'conversioniq_license_key', '' );
        if ( ! empty( $license ) ) {
            $query_args['license_key'] = $license;
        }
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $host ) {
            $query_args['domain'] = $host;
        }
        return $query_args;
    } );
}

// Automatically apply updates to this plugin on all client sites.
// WordPress background cron checks every ~12 hours; when a new version is
// detected on GitHub, this approves the update without requiring manual action.
add_filter('auto_update_plugins', function($update, $item) {
    if (isset($item->plugin) && $item->plugin === plugin_basename(__FILE__)) {
        return true;
    }
    return $update;
}, 10, 2);

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

                    // Clear the update checker's cached remote metadata so the next
                    // check re-reads the endpoint fresh (requirement: clear on update).
                    global $conversionIQUpdateChecker;
                    if ( isset( $conversionIQUpdateChecker ) && is_object( $conversionIQUpdateChecker )
                         && method_exists( $conversionIQUpdateChecker, 'resetUpdateState' ) ) {
                        $conversionIQUpdateChecker->resetUpdateState();
                    }

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

/**
 * Structured apply-flow logger.
 *
 * Writes every entry to both the PHP error log and a dated flat file at
 * wp-content/uploads/conversioniq/apply-log-YYYY-MM-DD.log so logs from
 * concurrent jobs can be grepped by review_id.
 *
 * @param string       $review_id  UUID of the implementation_reviews row.
 * @param string       $stage      All-caps label, e.g. 'JOB_PICKED_UP'.
 * @param array|string $data       Structured data (encoded to JSON) or a plain string.
 */
if ( ! function_exists( 'ciq_apply_log' ) ) {
    function ciq_apply_log( string $review_id, string $stage, $data = null ): void {
        $ts      = gmdate( 'c' );
        $short   = substr( $review_id, 0, 8 );
        $prefix  = '[CIQ-APPLY] ' . $ts . ' review=' . $short . ' | ' . $stage;
        $body    = '';
        if ( $data !== null ) {
            $body = ' ' . ( is_string( $data )
                ? $data
                : json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
        $line = $prefix . $body;

        error_log( $line );

        // Write to dated log file (created lazily, directory created if missing).
        static $dirs_ready = array();
        if ( function_exists( 'wp_upload_dir' ) ) {
            $upload = wp_upload_dir( null, false );
            $dir    = $upload['basedir'] . '/conversioniq';
            if ( ! isset( $dirs_ready[ $dir ] ) ) {
                if ( ! is_dir( $dir ) ) {
                    wp_mkdir_p( $dir );
                    @file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
                }
                $dirs_ready[ $dir ] = true;
            }
            $file = $dir . '/apply-log-' . gmdate( 'Y-m-d' ) . '.log';
            @file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
        }
    }
}

// Include required files
require_once CONVERSION_IQ_DIR . 'includes/class-config-manager.php';
require_once CONVERSION_IQ_DIR . 'includes/class-database.php';
require_once CONVERSION_IQ_DIR . 'includes/class-implementation-applier.php';
require_once CONVERSION_IQ_DIR . 'includes/rest-api.php';
require_once CONVERSION_IQ_DIR . 'includes/class-copy-inventory.php';
require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
require_once CONVERSION_IQ_DIR . 'includes/class-seo-analyzer.php';
require_once CONVERSION_IQ_DIR . 'includes/class-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-automated-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-supabase-sync.php';
require_once CONVERSION_IQ_DIR . 'includes/class-google-analytics.php';
require_once CONVERSION_IQ_DIR . 'includes/class-traffic-insights.php';
require_once CONVERSION_IQ_DIR . 'includes/class-conversion-tracker.php';
if ( ConversionIQ_Config_Manager::can('knockknock') ) {
    require_once CONVERSION_IQ_DIR . 'includes/class-knockknock-webhook.php';
}

// Initialize automated reports after WordPress loads
add_action( 'init', function() {
    ConversionIQ_Automated_Reports::init();
    ConversionIQ_Conversion_Tracker::init();
    
    // Schedule daily config sync if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_sync_config' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'conversioniq_sync_config' );
    }

    // Schedule daily traffic insights sync if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_traffic_sync' ) ) {
        $next = strtotime( gmdate( 'Y-m-d' ) . ' 03:30:00 UTC' );
        if ( $next <= time() ) {
            $next += DAY_IN_SECONDS;
        }
        wp_schedule_event( $next, 'daily', 'conversioniq_traffic_sync' );
    }

    // Schedule weekly DB pruning if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_prune_db' ) ) {
        wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'conversioniq_prune_db' );
    }

    // Heatmap sync: ensure the daily WP-Cron event is scheduled.
    // Belt-and-suspenders alongside the admin_init fallback — WP-Cron fires
    // on any frontend page load after the scheduled time, so data syncs even
    // on days when no admin visits the dashboard.
    if ( ! wp_next_scheduled( 'conversioniq_heatmap_sync' ) ) {
        // Schedule for 02:00 UTC tonight so it runs after midnight rollover
        $next_run = strtotime( gmdate( 'Y-m-d' ) . ' 02:00:00 UTC' );
        if ( $next_run <= time() ) {
            $next_run += DAY_IN_SECONDS; // already past 2am UTC today — schedule for tomorrow
        }
        wp_schedule_event( $next_run, 'daily', 'conversioniq_heatmap_sync' );
    }

    // Schedule 2-minute audit-job poller if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_poll_audit_jobs' ) ) {
        wp_schedule_event( time() + 120, 'conversioniq_twominutes', 'conversioniq_poll_audit_jobs' );
    }

    // Schedule 2-minute implementation review poller if not already scheduled
    if ( ! wp_next_scheduled( 'conversioniq_poll_implementation_jobs' ) ) {
        wp_schedule_event( time() + 120, 'conversioniq_twominutes', 'conversioniq_poll_implementation_jobs' );
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
        // Notify SaaS of the new version immediately rather than waiting for
        // the next daily config-sync cron.
        ConversionIQ_Config_Manager::sync_from_saas();
        ciq_log( 'ConversionIQ: version updated to ' . CONVERSION_IQ_VERSION . ' — config sync triggered.' );
    }
} );

// Daily config sync cron
add_action( 'conversioniq_sync_config', function() {
    ConversionIQ_Config_Manager::sync_from_saas();
} );

// Daily traffic insights sync cron — refreshes cached GA4 + GSC data
add_action( 'conversioniq_traffic_sync', function() {
    if ( ! get_option( 'conversioniq_api_key' ) ) {
        return;
    }
    $insights = new ConversionIQ_Traffic_Insights();
    $insights->get_summary( true ); // force refresh clears transients and re-fetches
    ciq_log( 'Traffic Insights: daily cron sync complete.' );
} );

// ── Google OAuth callback handler ─────────────────────────────────────────
// Handles the redirect from Google after the user grants permission.
// Runs before any output on admin pages.
// OAuth callback — runs on `init` (fires BEFORE WordPress's admin auth redirect),
// so it works even when SameSite cookie restrictions prevent the session cookie
// from being forwarded through the cross-origin OAuth redirect chain.
add_action( 'init', function() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( empty( $_GET['ga_callback'] ) || empty( $_GET['code'] ) ) {
        return;
    }

    ciq_log( '[OAuth] init FIRED — user_id=' . get_current_user_id() . ' logged_in=' . ( is_user_logged_in() ? 'yes' : 'no' ) . ' REQUEST_URI=' . ( $_SERVER['REQUEST_URI'] ?? 'n/a' ) );

    // Verify CSRF state nonce — this is the sole security gate since we cannot
    // require the user to be logged in at the point the callback lands.
    // State is base64url-encoded JSON: { site_url, nonce }
    $raw_state    = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
    $decoded      = base64_decode( strtr( $raw_state, '-_', '+/' ) );
    $state_data   = $decoded ? json_decode( $decoded, true ) : null;
    $nonce        = is_array( $state_data ) ? ( $state_data['nonce'] ?? '' ) : '';
    $stored_nonce = get_transient( 'ciq_google_oauth_state' );

    ciq_log( '[OAuth] init: state decode — json_valid=' . ( is_array( $state_data ) ? 'yes' : 'no' ) . ' nonce_present=' . ( $nonce ? 'yes' : 'no' ) . ' transient_present=' . ( $stored_nonce ? 'yes' : 'no' ) );
    ciq_log( '[OAuth] init: nonce_in_state=' . $nonce . ' stored_nonce=' . (string) $stored_nonce );

    if ( empty( $nonce ) || ! hash_equals( (string) $stored_nonce, $nonce ) ) {
        ciq_log( '[OAuth] init: CSRF check FAILED — stored=' . (string) $stored_nonce . ' received=' . $nonce );
        wp_safe_redirect( admin_url( 'admin.php?page=conversion-iq&ciq_oauth_error=state_mismatch' ) );
        exit;
    }
    delete_transient( 'ciq_google_oauth_state' );
    ciq_log( '[OAuth] init: CSRF check PASSED — proceeding to token exchange' );

    $code   = sanitize_text_field( wp_unslash( $_GET['code'] ) );
    ciq_log( '[OAuth] init: exchanging code (first 20 chars)=' . substr( $code, 0, 20 ) );
    $ga     = new ConversionIQ_Google_Analytics();
    $result = $ga->exchange_code( $code );

    ciq_log( '[OAuth] init: exchange_code result=' . wp_json_encode( array_intersect_key( $result, array_flip( array( 'success', 'error' ) ) ) ) );

    if ( empty( $result['success'] ) ) {
        $error = urlencode( $result['error'] ?? 'oauth_failed' );
        wp_safe_redirect( admin_url( 'admin.php?page=conversion-iq&ciq_oauth_error=' . $error ) );
        exit;
    }

    // Tokens stored — redirect to the plugin page.  If the session cookie was
    // not forwarded, WordPress will intercept at admin.php and redirect through
    // wp-login.php, after which the user lands on the success page.
    ciq_log( '[OAuth] init: SUCCESS — tokens stored, redirecting to plugin' );
    wp_safe_redirect( admin_url( 'admin.php?page=conversion-iq&ciq_tab=traffic&ciq_oauth_success=1' ) );
    exit;
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

// Piggyback conversion sync on the same nightly heatmap cron
add_action( 'conversioniq_heatmap_sync', function() {
    ConversionIQ_Conversion_Tracker::sync_to_saas();
} );

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

    // Sync yesterday's conversion counts alongside the heatmap
    ConversionIQ_Conversion_Tracker::sync_to_saas();
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

// ── Implementation Review Pollers ─────────────────────────────────────────────

add_action( 'conversioniq_poll_implementation_jobs', 'conversioniq_poll_implementation_jobs_handler' );

// Fallback: also run on every admin page load, throttled to once per 2 minutes.
// Uses a separate transient so it doesn't interfere with the audit jobs throttle.
add_action( 'admin_init', function() {
    if ( get_transient( 'ciq_impl_poll_throttle' ) ) return;
    set_transient( 'ciq_impl_poll_throttle', 1, 120 );
    conversioniq_poll_implementation_jobs_handler();
} );

/**
 * Resolve a queue row (page_id + page_url) to a valid, editable WP_Post.
 *
 * The page_id stored by the SaaS can be a crc32 hash rather than a real WP
 * post ID when the audit data was retrieved from history rather than run live.
 * For the home page it is also common for the stored ID to drift after the
 * site's Reading Settings change. Resolution order:
 *
 *  1. page_id > 0 and get_post() returns an accessible post → use it.
 *  2. page_url matches the site front page:
 *     a. show_on_front = 'page' → use page_on_front option.
 *     b. show_on_front = 'posts' → no single page exists; throw with a
 *        customer-facing message explaining how to fix it.
 *  3. url_to_postid( page_url ) → validate and return.
 *  4. get_page_by_path( URL path ) → validate and return.
 *  5. Nothing resolves → throw with a specific message (never the raw ID).
 *
 * @param int    $page_id  Raw page_id from the implementation_reviews row.
 * @param string $page_url Raw page_url from the same row.
 * @return WP_Post         Resolved, editable post.
 * @throws Exception       With a customer-readable message on all failure paths.
 */
function ciq_resolve_implementation_post( int $page_id, string $page_url ): WP_Post {
    $ok_statuses = array( 'publish', 'draft', 'private' );

    // Step 1: trust the stored ID when it resolves cleanly.
    if ( $page_id > 0 ) {
        $post = get_post( $page_id );
        if ( $post instanceof WP_Post && in_array( $post->post_status, $ok_statuses, true ) ) {
            ciq_log( 'ciq_resolve: using stored page_id=' . $page_id );
            return $post;
        }
        ciq_log( 'ciq_resolve: stored page_id=' . $page_id . ' invalid (status=' . ( $post ? $post->post_status : 'null' ) . '), falling back to URL' );
    }

    // Normalize for front-page comparison (strip scheme, www, trailing slash).
    $strip = function( string $u ): string {
        return rtrim( preg_replace( '#^https?://(www\.)?#i', '', $u ), '/' );
    };
    $is_front = ( $strip( $page_url ) === $strip( home_url() ) );

    // Step 2: front-page URL.
    if ( $is_front ) {
        $show_on_front = get_option( 'show_on_front', 'posts' );
        if ( $show_on_front === 'page' ) {
            $front_id = (int) get_option( 'page_on_front', 0 );
            if ( $front_id > 0 ) {
                $post = get_post( $front_id );
                if ( $post instanceof WP_Post && in_array( $post->post_status, $ok_statuses, true ) ) {
                    ciq_log( 'ciq_resolve: resolved home page via page_on_front=' . $front_id . ' (stored page_id was ' . $page_id . ')' );
                    return $post;
                }
            }
            throw new Exception( 'Could not load the static front page (Settings › Reading). It may have been deleted or set to an invalid page.' );
        }
        // show_on_front === 'posts' — no single editable post backs the home feed.
        throw new Exception( 'Your home page shows your latest posts feed, so there is no single page to edit. Go to Settings › Reading, choose "A static page", select a Front page, then run this again.' );
    }

    // Step 3: let WordPress resolve the URL directly.
    if ( ! empty( $page_url ) ) {
        $resolved_id = url_to_postid( $page_url );
        if ( $resolved_id > 0 ) {
            $post = get_post( $resolved_id );
            if ( $post instanceof WP_Post && in_array( $post->post_status, $ok_statuses, true ) ) {
                ciq_log( 'ciq_resolve: resolved via url_to_postid=' . $resolved_id . ' (stored page_id was ' . $page_id . ')' );
                return $post;
            }
        }

        // Step 4: path-based fallback.
        $path = trim( parse_url( $page_url, PHP_URL_PATH ) ?? '', '/' );
        if ( $path ) {
            foreach ( array( 'page', 'post' ) as $ptype ) {
                $page = get_page_by_path( $path, OBJECT, $ptype );
                if ( $page instanceof WP_Post && in_array( $page->post_status, $ok_statuses, true ) ) {
                    ciq_log( 'ciq_resolve: resolved via get_page_by_path path=' . $path . ' type=' . $ptype );
                    return $page;
                }
            }
        }
    }

    // Step 5: genuinely not found.
    throw new Exception( 'The page for this recommendation no longer exists on your site (it may have been deleted). URL: ' . $page_url );
}

function conversioniq_poll_implementation_jobs_handler() {
    $org_id = get_option( 'conversioniq_organization_id', '' );
    if ( ! $org_id ) return;

    $supabase = new ConversionIQ_Supabase_Sync();

    // ── Apply queue (queued_apply → applying → applied | partial) ─────────────
    $apply_job = $supabase->fetch_pending_implementation_job( 'queued_apply' );
    if ( $apply_job ) {
        $review_id = $apply_job['id'];

        // Decode changes early — needed for Stage 1 counts before we claim.
        $all_changes = is_array( $apply_job['changes'] )
            ? $apply_job['changes']
            : json_decode( $apply_job['changes'] ?? '[]', true );
        if ( ! is_array( $all_changes ) ) $all_changes = array();

        $approved_pre = array_values( array_filter( $all_changes, function( $c ) {
            return ( $c['decision'] ?? '' ) === 'approved';
        } ) );

        // ── Stage 1: Job picked up ────────────────────────────────────────
        ciq_apply_log( $review_id, 'JOB_PICKED_UP', array(
            'review_id'        => $review_id,
            'organization_id'  => $org_id,
            'raw_page_id'      => $apply_job['page_id']    ?? null,
            'page_url'         => $apply_job['page_url']   ?? '',
            'page_title'       => $apply_job['page_title'] ?? '',
            'total_changes'    => count( $all_changes ),
            'approved_changes' => count( $approved_pre ),
        ) );

        $supabase->claim_implementation_review( $review_id, 'applying' );

        try {
            // ── Stage 2: Target resolution ────────────────────────────────
            $raw_page_id = absint( $apply_job['page_id'] ?? 0 );
            $page_url    = $apply_job['page_url'] ?? '';

            try {
                $post = ciq_resolve_implementation_post( $raw_page_id, $page_url );
                ciq_apply_log( $review_id, 'TARGET_RESOLVED', array(
                    'raw_page_id'      => $raw_page_id,
                    'resolved_post_id' => $post->ID,
                    'post_type'        => $post->post_type,
                    'post_status'      => $post->post_status,
                    'post_title'       => $post->post_title,
                    'resolution'       => ( $raw_page_id === $post->ID ) ? 'stored_id' : 'url_fallback',
                ) );
            } catch ( Exception $resolve_ex ) {
                ciq_apply_log( $review_id, 'TARGET_RESOLUTION_FAILED', array(
                    'raw_page_id' => $raw_page_id,
                    'page_url'    => $page_url,
                    'error'       => $resolve_ex->getMessage(),
                ) );
                throw $resolve_ex;
            }

            // Index all changes by id for fast lookup when merging results back.
            $changes_by_id = array();
            foreach ( $all_changes as $c ) {
                if ( isset( $c['id'] ) ) $changes_by_id[ $c['id'] ] = $c;
            }

            $approved = $approved_pre;
            if ( empty( $approved ) ) {
                throw new Exception( 'No approved changes in this review batch.' );
            }

            // ── Run the applier ───────────────────────────────────────────
            $applier = new ConversionIQ_Implementation_Applier();
            $result  = $applier->apply_all( $approved, $post );

            // Build per-change results map keyed by change_id.
            $results_map = array();
            foreach ( $result['results'] as $r ) {
                $results_map[ $r['change_id'] ] = $r;
            }

            // Build per-change QA map (Layer-1 read-back verification) keyed by change_id.
            $qa_map = array();
            foreach ( ( $result['qa'] ?? array() ) as $q ) {
                if ( isset( $q['change_id'] ) ) $qa_map[ $q['change_id'] ] = $q;
            }

            // ── Stage 3: Per-change log ───────────────────────────────────
            // Types currently handled by the switch in apply_all().
            $supported_types = array(
                'copy_rewrite', 'reassurance_copy', 'urgency_copy',
                'headline_rewrite', 'cta_swap',
                'meta_title', 'meta_description', 'og_image',
                'focus_keyword', 'alt_text', 'insert_block',
                'schema_inject', 'sticky_cta_css',
            );

            foreach ( $approved as $change ) {
                $cid       = $change['id']   ?? '';
                $ctype     = $change['type'] ?? '';
                $supported = in_array( $ctype, $supported_types, true );
                $r         = $results_map[ $cid ] ?? array(
                    'status'        => 'unknown',
                    'error_code'    => null,
                    'error_message' => null,
                );
                $action = $r['status'];

                $entry = array(
                    'change_id'     => $cid,
                    'type'          => $ctype,
                    'target'        => $change['target'] ?? null,
                    'supported'     => $supported,
                    'before_length' => strlen( $change['before'] ?? '' ),
                    'after_length'  => strlen( $change['after']  ?? '' ),
                    'action'        => $action,
                );

                if ( $action === 'applied' ) {
                    $entry['before_preview'] = mb_substr( $change['before'] ?? '', 0, 120 );
                    $entry['after_preview']  = mb_substr( $change['after']  ?? '', 0, 120 );
                } elseif ( $action === 'skipped' || $action === 'failed' ) {
                    $entry['error_code'] = $r['error_code'];
                    $entry['reason']     = $r['error_message'];
                }

                ciq_apply_log( $review_id, 'CHANGE', $entry );
            }

            // ── Stage 4: Preview staged (in place — no clone, same post/URL) ──
            $live_url = $result['final_url'] ?? get_permalink( $post );
            ciq_apply_log( $review_id, 'PREVIEW_STAGED', array(
                'staged'      => $result['draft_url'] !== null,
                'preview_url' => $result['draft_url'],
                'preview_id'  => $result['preview_id'] ?? null,
                'token'       => ! empty( $result['preview_token'] ) ? substr( $result['preview_token'], 0, 4 ) . '…' : null,
                'live_url'    => $live_url,
                'post_id'     => $post->ID,
                'note'        => $result['draft_url']
                    ? 'Edited content staged on the SAME post; shareable ciq_token preview link keeps the live permalink — works logged-in or logged-out, no new page created.'
                    : 'No previewable content change (meta/SEO-only, or nothing matched exactly).',
            ) );

            // ── Build updated changes + counts ────────────────────────────
            $updated_changes = array_map( function( $change ) use ( $results_map, $qa_map ) {
                $cid = $change['id'] ?? '';
                if ( isset( $results_map[ $cid ] ) ) {
                    $r = $results_map[ $cid ];
                    $change['apply_status'] = $r['status'];
                    $change['apply_error']  = ( $r['status'] !== 'applied' ) ? $r['error_message'] : null;
                    $change['applied_at']   = gmdate( 'c' );
                }
                if ( isset( $qa_map[ $cid ] ) ) {
                    $q = $qa_map[ $cid ];
                    $change['qa_verified'] = $q['verified'];              // true | false | null
                    $change['qa_warnings'] = array_values( $q['warnings'] ?? array() );
                }
                return $change;
            }, $all_changes );

            $applied_count  = count( array_filter( $result['results'], fn( $r ) => $r['status'] === 'applied' ) );
            $skipped_count  = count( array_filter( $result['results'], fn( $r ) => $r['status'] === 'skipped' ) );
            $failed_count   = count( array_filter( $result['results'], fn( $r ) => $r['status'] === 'failed'  ) );
            $total_approved = count( $approved );

            // QA tallies: applied changes that failed read-back, and total warnings.
            $qa_unverified = 0;
            $qa_warnings   = 0;
            foreach ( $qa_map as $q ) {
                if ( ( $q['verified'] ?? null ) === false ) $qa_unverified++;
                $qa_warnings += count( $q['warnings'] ?? array() );
            }

            $counts_str = $total_approved . ' changes: ' . $applied_count . ' applied, '
                . $skipped_count . ' skipped, ' . $failed_count . ' failed.';

            if ( $applied_count === 0 ) {
                $final_status = 'partial';
                $apply_error  = $counts_str . ' No changes were applied.';
            } elseif ( $skipped_count > 0 || $failed_count > 0 ) {
                $final_status = 'partial';
                $apply_error  = $counts_str;
                if ( $result['draft_url'] ) {
                    $apply_error .= ' The edited content is staged — preview it before publishing.';
                }
            } else {
                $final_status = 'applied';
                $apply_error  = null;
            }

            // Surface QA outcomes in the human-facing message.
            if ( $qa_unverified > 0 ) {
                $apply_error = ( $apply_error ? $apply_error . ' ' : $counts_str . ' ' )
                    . 'QA: ' . $qa_unverified . ' applied change(s) could not be verified — please review the preview.';
            } elseif ( $qa_warnings > 0 ) {
                $apply_error = ( $apply_error ? $apply_error . ' ' : '' )
                    . 'QA: ' . $qa_warnings . ' layout warning(s) — review the preview before publishing.';
            }

            $writeback_payload = array(
                'status'      => $final_status,
                'draft_url'   => $result['draft_url'],
                'applied_at'  => gmdate( 'c' ),
                'apply_error' => $apply_error,
                'changes'     => $updated_changes,
            );
            // The full shareable preview link (with the token) is carried in draft_url —
            // that is what the dashboard's "Preview Draft" button opens. We also report
            // the drafted post ID; patch_implementation_review() drops it gracefully if
            // the column doesn't exist, so draft_url is always the reliable path.
            if ( ! empty( $result['preview_id'] ) ) $writeback_payload['preview_id'] = $result['preview_id'];

            // ── Stage 5a: QA read-back results ────────────────────────────
            ciq_apply_log( $review_id, 'QA_RESULT', array(
                'unverified' => $qa_unverified,
                'warnings'   => $qa_warnings,
                'details'    => array_map( function( $q ) {
                    return array(
                        'change_id' => $q['change_id'] ?? '',
                        'type'      => $q['type']      ?? '',
                        'verified'  => $q['verified']  ?? null,
                        'method'    => $q['method']    ?? '',
                        'warnings'  => $q['warnings']  ?? array(),
                    );
                }, array_values( $qa_map ) ),
            ) );

            // ── Stage 5: Summary before writeback ─────────────────────────
            ciq_apply_log( $review_id, 'SUMMARY', array(
                'total_approved' => $total_approved,
                'applied'        => $applied_count,
                'skipped'        => $skipped_count,
                'failed'         => $failed_count,
                'qa_unverified'  => $qa_unverified,
                'qa_warnings'    => $qa_warnings,
                'final_status'   => $final_status,
                'draft_url_set'  => $result['draft_url'] !== null,
                'draft_url'      => $result['draft_url'],
                'apply_error'    => $apply_error,
                'patching'       => array(
                    'status'      => $final_status,
                    'draft_url'   => $result['draft_url'],
                    'applied_at'  => $writeback_payload['applied_at'],
                    'apply_error' => $apply_error,
                    // 'changes' omitted from log — too large; per-change detail is in CHANGE entries above
                ),
            ) );

            // ── Writeback ─────────────────────────────────────────────────
            $wb = $supabase->complete_implementation_review( $review_id, $writeback_payload );

            // ── Stage 6: Writeback result ─────────────────────────────────
            ciq_apply_log( $review_id, 'WRITEBACK_RESULT', array(
                'http_code' => $wb['code'],
                'ok'        => $wb['ok'],
                'body'      => $wb['code'] >= 400 ? $wb['body'] : null,
            ) );

            ciq_log( 'impl_poller: apply done review_id=' . $review_id . ' status=' . $final_status
                . ' applied=' . $applied_count . ' skipped=' . $skipped_count . ' failed=' . $failed_count );

        } catch ( Exception $e ) {
            ciq_apply_log( $review_id, 'EXCEPTION', array( 'message' => $e->getMessage() ) );
            ciq_log( 'impl_poller: apply exception review_id=' . $review_id . ' — ' . $e->getMessage() );
            $supabase->complete_implementation_review( $review_id, array(
                'status'      => 'partial',
                'apply_error' => $e->getMessage(),
            ) );
        }
    }

    // ── Publish queue (queued_publish → publishing → applied) ─────────────────
    // Publishing applies the approved changes to the SAME live post in place —
    // it never touches a clone. The permalink, post ID and URL stay identical.
    $publish_job = $supabase->fetch_pending_implementation_job( 'queued_publish' );
    if ( $publish_job ) {
        $review_id = $publish_job['id'];
        ciq_log( 'impl_poller: found publish job review_id=' . $review_id );

        $all_changes = is_array( $publish_job['changes'] )
            ? $publish_job['changes']
            : json_decode( $publish_job['changes'] ?? '[]', true );
        if ( ! is_array( $all_changes ) ) $all_changes = array();
        $approved = array_values( array_filter( $all_changes, function ( $c ) {
            return ( $c['decision'] ?? '' ) === 'approved';
        } ) );

        $supabase->claim_implementation_review( $review_id, 'publishing' );

        try {
            $post = ciq_resolve_implementation_post(
                absint( $publish_job['page_id'] ?? 0 ),
                $publish_job['page_url'] ?? ''
            );
            $live_url = get_permalink( $post );

            ciq_apply_log( $review_id, 'PUBLISH_START', array(
                'post_id'   => $post->ID,
                'live_url'  => $live_url,
                'approved'  => count( $approved ),
            ) );

            if ( empty( $approved ) ) {
                throw new Exception( 'No approved changes in this review batch.' );
            }

            // Commit the approved changes onto the live post IN PLACE.
            $applier = new ConversionIQ_Implementation_Applier();
            $result  = $applier->apply_all( $approved, $post, 'publish' );

            $results_map = array();
            foreach ( $result['results'] as $r ) {
                $results_map[ $r['change_id'] ] = $r;
            }

            $applied_count = count( array_filter( $result['results'], fn( $r ) => $r['status'] === 'applied' ) );
            $failed_count  = count( array_filter( $result['results'], fn( $r ) => $r['status'] === 'failed'  ) );

            // Merge per-change publish results back into the changes array.
            $updated_changes = array_map( function ( $change ) use ( $results_map ) {
                $cid = $change['id'] ?? '';
                if ( isset( $results_map[ $cid ] ) ) {
                    $r = $results_map[ $cid ];
                    $change['apply_status'] = $r['status'];
                    $change['apply_error']  = ( $r['status'] !== 'applied' ) ? $r['error_message'] : null;
                    $change['applied_at']   = gmdate( 'c' );
                }
                return $change;
            }, $all_changes );

            $final_status = ( $failed_count === 0 && $applied_count > 0 ) ? 'applied' : 'partial';
            $final_url    = $result['final_url'] ?? $live_url; // MUST equal the original page URL

            ciq_apply_log( $review_id, 'PUBLISH_DONE', array(
                'post_id'       => $post->ID,
                'final_url'     => $final_url,
                'url_unchanged' => ( $final_url === $live_url ),
                'applied'       => $applied_count,
                'failed'        => $failed_count,
                'final_status'  => $final_status,
            ) );

            $supabase->complete_implementation_review( $review_id, array(
                'status'      => $final_status,
                'draft_url'   => null, // change is now live on the original URL
                'applied_at'  => gmdate( 'c' ),
                'apply_error' => $failed_count > 0
                    ? ( $applied_count . ' published, ' . $failed_count . ' failed (before text not found).' )
                    : null,
                'changes'     => $updated_changes,
            ) );

            ciq_log( 'impl_poller: publish done review_id=' . $review_id . ' status=' . $final_status
                . ' applied=' . $applied_count . ' failed=' . $failed_count . ' url=' . $final_url );

        } catch ( Exception $e ) {
            ciq_apply_log( $review_id, 'PUBLISH_EXCEPTION', array( 'message' => $e->getMessage() ) );
            ciq_log( 'impl_poller: publish exception review_id=' . $review_id . ' — ' . $e->getMessage() );
            $supabase->complete_implementation_review( $review_id, array(
                'status'      => 'partial',
                'apply_error' => $e->getMessage(),
            ) );
        }
    }
}

/**
 * Render staged edits on a shareable preview request for the SAME post.
 *
 * The apply step stages edited content in the `_ciq_preview_data` post meta (never a
 * clone) along with a random token, and hands back a link like
 * `{permalink}?ciq_preview={id}&ciq_token={token}`. On such a request we validate the
 * token against the stored one and swap the staged content in for that request only,
 * leaving the live page unchanged until publish. We deliberately avoid WordPress's
 * preview=true/preview_nonce mechanism: that nonce is bound to one user and throws
 * "Sorry, you are not allowed to preview drafts." for anyone else, so it can't be
 * shared. The page here stays published, so the token link works for logged-in admins
 * and logged-out clients alike:
 *   • classic: replace post_content via the_content
 *   • Elementor: serve the staged _elementor_data via the get_post_metadata filter
 */
function ciq_render_staged_preview() {
    if ( is_admin() ) return;
    if ( empty( $_GET['ciq_preview'] ) || empty( $_GET['ciq_token'] ) ) return;
    if ( ! class_exists( 'ConversionIQ_Implementation_Applier' ) ) return;

    $post_id = (int) $_GET['ciq_preview'];
    if ( $post_id <= 0 ) return;

    $staged = get_post_meta( $post_id, ConversionIQ_Implementation_Applier::PREVIEW_META, true );
    if ( empty( $staged ) || ! is_array( $staged ) || empty( $staged['token'] ) ) return;

    // Constant-time token check. Only a holder of the exact token sees the staged copy.
    $token = sanitize_text_field( wp_unslash( $_GET['ciq_token'] ) );
    if ( ! hash_equals( (string) $staged['token'], $token ) ) return;

    // Only overlay when the main query actually resolved to this post.
    if ( get_queried_object_id() !== $post_id ) return;

    // Classic / Gutenberg: swap post_content for the previewed post.
    if ( ! empty( $staged['post_content'] ) ) {
        add_filter( 'the_content', function ( $content ) use ( $post_id, $staged ) {
            if ( in_the_loop() && get_the_ID() === $post_id ) {
                return $staged['post_content'];
            }
            return $content;
        }, 1 );
    }

    // Elementor: return the staged widget tree for this post's _elementor_data.
    if ( ! empty( $staged['elementor'] ) && is_array( $staged['elementor'] ) ) {
        $json = wp_json_encode( $staged['elementor'] ); // unslashed, as get_post_meta returns it
        add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key ) use ( $post_id, $json ) {
            if ( $object_id === $post_id && $meta_key === '_elementor_data' ) {
                return array( $json );
            }
            return $value;
        }, 10, 3 );
        if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
            try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch ( \Throwable $t ) {}
        }
    }
}
add_action( 'wp', 'ciq_render_staged_preview' );
// ── End Implementation Review Pollers ─────────────────────────────────────────

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

    // Ensure the 2-minute implementation review poller is scheduled
    if ( ! wp_next_scheduled( 'conversioniq_poll_implementation_jobs' ) ) {
        wp_schedule_event( time() + 120, 'conversioniq_twominutes', 'conversioniq_poll_implementation_jobs' );
    }

    // Push version + sync endpoint to SaaS on activation/reactivation.
    // Runs after a short delay so REST routes are fully registered first.
    wp_schedule_single_event( time() + 10, 'conversioniq_sync_config' );
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
        'endpoint'    => esc_url_raw( rest_url( 'conversioniq/v1/heatmap/record' ) ),
        'restBase'    => esc_url_raw( rest_url() ),
        'convGoals'   => ConversionIQ_Conversion_Tracker::get_goals(),
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

// (Duplicate OAuth handler removed — the secure handler above handles all OAuth callbacks.)

