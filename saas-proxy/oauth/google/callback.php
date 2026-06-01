<?php
/**
 * Conversion IQ — OAuth Relay Proxy
 *
 * Deploy this file to: app.conversioniq.com/oauth/google/callback
 * (e.g. upload to public_html/oauth/google/callback.php on your hosting,
 *  with app.conversioniq.com pointing to that host)
 *
 * What it does:
 *   1. Receives the Google OAuth callback (state + code)
 *   2. Decodes the state to extract the originating WP site URL
 *   3. Relays the code back to that WP site's admin
 */

// ── Security headers ────────────────────────────────────────────────────────
header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: DENY' );

// ── Collect params ──────────────────────────────────────────────────────────
$state = isset( $_GET['state'] ) ? trim( $_GET['state'] ) : '';
$code  = isset( $_GET['code']  ) ? trim( $_GET['code']  ) : '';
$error = isset( $_GET['error'] ) ? trim( $_GET['error'] ) : '';

if ( $state === '' ) {
    http_response_code( 400 );
    exit( 'Missing state parameter.' );
}

// ── Decode base64url state ──────────────────────────────────────────────────
$json = base64_decode( strtr( $state, '-_', '+/' ) );
$data = $json ? json_decode( $json, true ) : null;

if ( ! is_array( $data ) || empty( $data['site_url'] ) ) {
    http_response_code( 400 );
    exit( 'Invalid state parameter.' );
}

$site_url = $data['site_url'];

// ── Validate site_url (must be https, no path injection) ────────────────────
$parsed = parse_url( $site_url );

if (
    ! isset( $parsed['scheme'], $parsed['host'] ) ||
    $parsed['scheme'] !== 'https'
) {
    http_response_code( 400 );
    exit( 'Invalid site URL in state.' );
}

// ── Build relay URL ─────────────────────────────────────────────────────────
// Relay back to WP admin; the plugin's admin_init hook handles ga_callback=1
$relay_params = [
    'page'        => 'conversioniq',
    'ga_callback' => '1',
];

if ( $error !== '' ) {
    $relay_params['ciq_oauth_error'] = $error;
} elseif ( $code !== '' ) {
    $relay_params['code']  = $code;
    $relay_params['state'] = $state;
} else {
    http_response_code( 400 );
    exit( 'No code or error received from Google.' );
}

// Ensure site_url has a trailing slash before appending query string
$base        = rtrim( $site_url, '/' ) . '/';
$relay_url   = $base . 'admin.php?' . http_build_query( $relay_params );

// ── Redirect ────────────────────────────────────────────────────────────────
header( 'Location: ' . $relay_url, true, 302 );
exit;
