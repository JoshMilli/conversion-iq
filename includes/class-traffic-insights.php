<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ConversionIQ Traffic Insights
 *
 * Orchestrates GA4 + GSC data fetching, caching, the Traffic/SEO verdict,
 * and Supabase synchronisation for the SaaS dashboard.
 */
class ConversionIQ_Traffic_Insights {

    const TRANSIENT_GA4  = 'ciq_traffic_ga4';
    const TRANSIENT_GSC  = 'ciq_traffic_gsc';
    const CACHE_TTL      = 21600; // 6 hours

    /** @var ConversionIQ_Google_Analytics */
    private $ga;

    public function __construct() {
        $this->ga = new ConversionIQ_Google_Analytics();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the full summary (cached or freshly fetched).
     *
     * @param bool $force_refresh Skip the cache and re-fetch.
     * @return array
     */
    public function get_summary( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = $this->get_cached();
            if ( $cached !== null ) {
                ciq_log( '[Traffic] get_summary: serving from cache (TTL ' . self::CACHE_TTL / 3600 . 'h)' );
                return $cached;
            }
            ciq_log( '[Traffic] get_summary: cache miss — fetching fresh data' );
        } else {
            ciq_log( '[Traffic] get_summary: force_refresh=true — bypassing cache' );
        }
        return $this->fetch_and_cache();
    }

    /**
     * Return the connection status for both GA4 and GSC.
     *
     * @return array
     */
    public function get_status() {
        $ga4_connected = $this->ga->is_connected();
        $gsc_connected = $this->ga->is_gsc_connected();

        return array(
            'ga4_connected'  => $ga4_connected,
            'gsc_connected'  => $gsc_connected,
            'has_tokens'     => $this->ga->has_tokens(),
            'ga4_property'   => $this->ga->is_connected() ? ( get_option( 'conversioniq_ga_credentials', array() )['property_name'] ?? '' ) : '',
            'gsc_property'   => $this->ga->get_gsc_property(),
            'auth_url'       => $this->ga->get_auth_url(),
            'fetched_at'     => get_option( 'conversioniq_traffic_fetched_at', null ),
            'has_data'       => ( get_transient( self::TRANSIENT_GA4 ) !== false || get_transient( self::TRANSIENT_GSC ) !== false ),
        );
    }

    // ── Verdict ───────────────────────────────────────────────────────────────

    /**
     * Generate the Traffic-vs-CRO verdict from fetched metrics.
     *
     * @param array $ga4 Parsed GA4 site summary.
     * @param array $gsc Parsed GSC site summary.
     * @return array
     */
    public function get_verdict( $ga4, $gsc ) {
        if ( empty( $ga4 ) && empty( $gsc ) ) {
            return array(
                'direction' => 'no_data',
                'label'     => 'Connect Google',
                'color'     => '#6b7280',
                'title'     => 'Connect your Google accounts to unlock recommendations',
                'summary'   => 'Once connected, we\'ll analyse your traffic and conversion data to give you a clear growth priority.',
                'actions'   => array(),
            );
        }

        $sessions        = (int)   ( $ga4['sessions']         ?? 0 );
        $conversions     = (int)   ( $ga4['conversions']      ?? 0 );
        $engagement_rate = (float) ( $ga4['engagement_rate']  ?? 0 );
        $organic_clicks  = (int)   ( $gsc['total_clicks']     ?? 0 );
        $avg_position    = (float) ( $gsc['avg_position']     ?? 0 );

        // ── Decision thresholds ───────────────────────────────────────────
        $low_sessions       = $sessions > 0 && $sessions < 300;   // < 10/day
        $very_low_organic   = $organic_clicks < 50;
        $decent_sessions    = $sessions >= 300;
        $zero_conversions   = $conversions === 0;
        $poor_engagement    = $engagement_rate > 0 && $engagement_rate < 40;
        $weak_rankings      = $avg_position > 20 && $avg_position > 0;

        // 1. Almost no traffic at all → grow reach first
        if ( $low_sessions && $very_low_organic ) {
            return array(
                'direction' => 'seo',
                'label'     => 'Grow Your Traffic First',
                'color'     => '#f59e0b',
                'title'     => 'Your site needs more traffic before CRO will move the needle.',
                'summary'   => 'With ' . number_format( $sessions ) . ' sessions and ' . number_format( $organic_clicks ) . ' organic clicks in the last 28 days, the biggest lever right now is increasing reach — not optimising what you already have.',
                'actions'   => array(
                    'Focus on on-page SEO for your top 3 most important pages',
                    'Target long-tail keywords with clear search intent',
                    'Submit (or verify) your sitemap in Google Search Console',
                    'Build topical authority with supporting blog content',
                ),
            );
        }

        // 2. Decent sessions but no conversions tracked → CRO or GA4 setup issue
        if ( $decent_sessions && $zero_conversions ) {
            return array(
                'direction' => 'cro',
                'label'     => 'Optimise for Conversions',
                'color'     => '#10b981',
                'title'     => 'Good traffic, but no conversions are being tracked.',
                'summary'   => 'You have ' . number_format( $sessions ) . ' sessions — that\'s a real audience. Either conversion events are not configured in GA4, or there\'s a clear friction issue on key pages.',
                'actions'   => array(
                    'Set up conversion events in GA4 (form submits, phone clicks, purchases)',
                    'Run CRO audits on your top landing pages',
                    'Review CTA clarity and placement on high-traffic pages',
                    'Check mobile UX on pages with high bounce rates',
                ),
            );
        }

        // 3. Traffic arriving but visitors not engaging → CRO focus
        if ( $decent_sessions && $poor_engagement ) {
            return array(
                'direction' => 'cro',
                'label'     => 'Improve Page Engagement',
                'color'     => '#f59e0b',
                'title'     => 'Traffic is there, but visitors aren\'t staying.',
                'summary'   => 'An engagement rate of ' . round( $engagement_rate ) . '% suggests visitors are landing and leaving quickly. Your pages may not be immediately communicating value.',
                'actions'   => array(
                    'Strengthen the above-the-fold value proposition on key pages',
                    'Improve page load speed — check your Core Web Vitals in the SEO tab',
                    'Add internal links to keep visitors exploring',
                    'Audit your top exit pages for CRO issues',
                ),
            );
        }

        // 4. Traffic exists but ranking poorly organically → SEO opportunity
        if ( $decent_sessions && $weak_rankings && $organic_clicks < 200 ) {
            return array(
                'direction' => 'seo',
                'label'     => 'Improve Search Rankings',
                'color'     => '#2563eb',
                'title'     => 'You have traffic, but organic search rankings need work.',
                'summary'   => 'Your site averages position ' . round( $avg_position, 1 ) . ' in Google. Many potential visitors are seeing your pages but clicking competitors. Improving rankings will compound over time.',
                'actions'   => array(
                    'Expand and deepen content on pages ranking positions 10–20 (easiest wins)',
                    'Target featured snippets with structured answers',
                    'Improve page titles and meta descriptions for better click-through rates',
                    'Build topical authority with supporting pillar content',
                ),
            );
        }

        // 5. Both look reasonable → balanced approach
        return array(
            'direction' => 'both',
            'label'     => 'Strong Foundation — Keep Optimising',
            'color'     => '#10b981',
            'title'     => 'Your site has a solid base. Refine both traffic quality and conversions.',
            'summary'   => number_format( $sessions ) . ' sessions and ' . number_format( $organic_clicks ) . ' organic clicks in 28 days. Focus CRO on your highest-traffic pages, and build SEO for long-term compounding growth.',
            'actions'   => array(
                'Run CRO audits on your top 3 traffic pages',
                'Target keywords where you rank positions 5–15 (easiest to push to page 1)',
                'A/B test your primary CTA on high-traffic landing pages',
                'Review Core Web Vitals for speed improvements',
            ),
        );
    }

    // ── Supabase sync ─────────────────────────────────────────────────────────

    /**
     * Push the latest GA4 and GSC snapshots to Supabase.
     *
     * Uses an upsert on (org_id, source) so the SaaS dashboard always sees
     * the most recent data without accumulating stale rows.
     *
     * @param array $ga4
     * @param array $gsc
     */
    public function sync_to_supabase( $ga4, $gsc ) {
        $org_id  = get_option( 'conversioniq_organization_id', '' );
        $api_key = get_option( 'conversioniq_api_key', '' );

        if ( empty( $org_id ) || empty( $api_key ) ) {
            ciq_log( 'Traffic sync: skipping Supabase — ' . ( empty( $org_id ) ? 'no organization_id' : 'no api_key' ) );
            return false;
        }

        // Route through the SaaS proxy so it can write with the service_role key
        // (direct anon-key writes to site_analytics_snapshots are blocked by RLS).
        $payload = array(
            'organization_id' => $org_id,
            'fetched_at'      => gmdate( 'c' ),
            'period_days'     => 28,
            'ga4_property_id' => $this->ga->get_property_id(),
            'gsc_property'    => $this->ga->get_gsc_property(),
            'ga4'             => ! empty( $ga4 ) ? $ga4 : null,
            'gsc'             => ! empty( $gsc ) ? $gsc : null,
        );

        $response = wp_remote_post( 'https://conversioniq-app.com/api/traffic/sync-snapshot', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );

        $code = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );
        $ok   = ( $code === 200 || $code === 201 );
        ciq_log( 'Traffic sync → conversioniq-app.com/api/traffic/sync-snapshot: HTTP ' . $code . ( $ok ? ' ✅' : ' ❌' ) );

        return $ok;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Return cached data, or null if the cache is empty / expired.
     */
    private function get_cached() {
        $ga4 = get_transient( self::TRANSIENT_GA4 );
        $gsc = get_transient( self::TRANSIENT_GSC );

        // Both missing → no cache
        if ( $ga4 === false && $gsc === false ) {
            return null;
        }

        return $this->build_response( $ga4 ?: array(), $gsc ?: array() );
    }

    /**
     * Fetch fresh data, store it in transients, sync to Supabase.
     */
    private function fetch_and_cache() {
        $ga4    = array();
        $gsc    = array();
        $errors = array();
        $start  = microtime( true );

        $ga4_connected = $this->ga->is_connected();
        $gsc_connected = $this->ga->is_gsc_connected();
        ciq_log( '[Traffic] fetch_and_cache: starting — ga4_connected=' . ( $ga4_connected ? 'yes' : 'no' ) . ' gsc_connected=' . ( $gsc_connected ? 'yes' : 'no' ) );

        if ( $ga4_connected ) {
            $result = $this->ga->fetch_ga4_site_summary( 28 );
            if ( isset( $result['error'] ) ) {
                $errors['ga4'] = $result['error'];
                ciq_log( '[Traffic] fetch_and_cache: GA4 fetch error — ' . $result['error'] );
            } else {
                $ga4 = $result;
            }
        }

        if ( $gsc_connected ) {
            $result = $this->ga->fetch_gsc_site_summary( 28 );
            if ( isset( $result['error'] ) ) {
                $errors['gsc'] = $result['error'];
                ciq_log( '[Traffic] fetch_and_cache: GSC fetch error — ' . $result['error'] );
            } else {
                $gsc = $result;
            }
        }

        if ( ! empty( $ga4 ) || ! empty( $gsc ) ) {
            set_transient( self::TRANSIENT_GA4, $ga4, self::CACHE_TTL );
            set_transient( self::TRANSIENT_GSC, $gsc, self::CACHE_TTL );
            update_option( 'conversioniq_traffic_fetched_at', time() );
            $this->sync_to_supabase( $ga4, $gsc );
            $elapsed = round( ( microtime( true ) - $start ) * 1000 );
            ciq_log( '[Traffic] fetch_and_cache: SUCCESS — ' . $elapsed . 'ms — GA4 sessions=' . ( $ga4['sessions'] ?? 'n/a' ) . ' GSC clicks=' . ( $gsc['total_clicks'] ?? 'n/a' ) . ' cached for ' . ( self::CACHE_TTL / 3600 ) . 'h' );
        } else {
            ciq_log( '[Traffic] fetch_and_cache: no data returned — skipping cache and Supabase sync' );
        }

        $response = $this->build_response( $ga4, $gsc );
        if ( ! empty( $errors ) ) {
            $response['errors'] = $errors;
        }
        return $response;
    }

    /**
     * Build a standardised response array.
     */
    private function build_response( $ga4, $gsc ) {
        return array(
            'ga4'           => $ga4,
            'gsc'           => $gsc,
            'verdict'       => $this->get_verdict( $ga4, $gsc ),
            'fetched_at'    => get_option( 'conversioniq_traffic_fetched_at', null ),
            'ga4_connected' => $this->ga->is_connected(),
            'gsc_connected' => $this->ga->is_gsc_connected(),
        );
    }
}
