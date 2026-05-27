<?php
/**
 * Debug Logs Viewer
 * View WordPress debug logs directly in the admin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get log file path
$log_file = WP_CONTENT_DIR . '/debug.log';
$log_exists = file_exists($log_file);
$log_size = $log_exists ? size_format(filesize($log_file)) : '0 B';

// Handle clear logs action
if (isset($_POST['clear_logs']) && check_admin_referer('conversioniq_clear_logs')) {
    if ($log_exists) {
        file_put_contents($log_file, '');
        echo '<div class="notice notice-success"><p>Debug log cleared successfully.</p></div>';
    }
}

// Handle download logs action
if (isset($_GET['download']) && check_admin_referer('conversioniq_download_logs', 'nonce')) {
    if ($log_exists) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="debug-log-' . date('Y-m-d-His') . '.txt"');
        header('Content-Length: ' . filesize($log_file));
        readfile($log_file);
        exit;
    }
}

// Get lines to display (default 500)
$lines_to_show = isset($_GET['lines']) ? intval($_GET['lines']) : 500;
$search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// Read log file
$log_content = '';
if ($log_exists) {
    $lines = file($log_file, FILE_IGNORE_NEW_LINES);
    $total_lines = count($lines);
    
    // Filter by search term if provided
    if (!empty($search_term)) {
        $lines = array_filter($lines, function($line) use ($search_term) {
            return stripos($line, $search_term) !== false;
        });
        $filtered_count = count($lines);
    }
    
    // Get last N lines
    $lines = array_slice($lines, -$lines_to_show);
    $log_content = implode("\n", array_reverse($lines));
}
?>

<div class="wrap">
    <h1>Debug Logs</h1>
    
    <div class="notice notice-info" style="margin: 20px 0;">
        <p><strong>Log File:</strong> <?php echo esc_html($log_file); ?></p>
        <p><strong>File Size:</strong> <?php echo esc_html($log_size); ?></p>
        <?php if (isset($total_lines)): ?>
            <p><strong>Total Lines:</strong> <?php echo number_format($total_lines); ?>
            <?php if (!empty($search_term)): ?>
                (showing <?php echo number_format($filtered_count); ?> matching lines)
            <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (!$log_exists): ?>
            <p><strong>Note:</strong> Debug log file doesn't exist yet. Enable WP_DEBUG_LOG in wp-config.php to start logging.</p>
        <?php endif; ?>
    </div>
    
    <div style="background: white; padding: 20px; margin-bottom: 20px; border: 1px solid #ccc; border-radius: 4px;">
        <form method="get" style="display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
            <input type="hidden" name="page" value="conversioniq-logs">
            
            <div>
                <label for="search" style="display: block; margin-bottom: 5px;"><strong>Search Logs:</strong></label>
                <input type="text" id="search" name="search" value="<?php echo esc_attr($search_term); ?>" 
                       placeholder="Search term..." style="width: 300px;">
            </div>
            
            <div>
                <label for="lines" style="display: block; margin-bottom: 5px;"><strong>Lines to Show:</strong></label>
                <select name="lines" id="lines" style="width: 120px;">
                    <option value="100" <?php selected($lines_to_show, 100); ?>>Last 100</option>
                    <option value="500" <?php selected($lines_to_show, 500); ?>>Last 500</option>
                    <option value="1000" <?php selected($lines_to_show, 1000); ?>>Last 1000</option>
                    <option value="5000" <?php selected($lines_to_show, 5000); ?>>Last 5000</option>
                </select>
            </div>
            
            <button type="submit" class="button button-primary">Filter Logs</button>
            
            <?php if ($log_exists): ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=conversioniq-logs&download=1'), 'conversioniq_download_logs', 'nonce'); ?>" 
                   class="button">Download Full Log</a>
            <?php endif; ?>
        </form>
        
        <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <button type="button" class="button" onclick="reregisterSync()">🔄 Re-register Sync Endpoint</button>
            <span id="reregister-result" style="font-size: 13px;"></span>
        </div>

        <script>
        function reregisterSync() {
            const result = document.getElementById('reregister-result');
            result.innerHTML = '<em>Registering…</em>';

            fetch('<?php echo esc_js( rest_url('conversioniq/v1/reregister-sync') ); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>'
                },
                body: '{}'
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    result.innerHTML = '<span style="color:green">&#10003; Registered — SaaS will pick this site up on its next sync run.</span>';
                } else {
                    result.innerHTML = '<span style="color:red">&#10007; ' + (data.message || 'Failed — check debug log for details.') + '</span>';
                }
            })
            .catch(err => {
                result.innerHTML = '<span style="color:red">&#10007; Fetch error: ' + err.message + '</span>';
            });
        }
        </script>
        </form>
        
        <?php if ($log_exists): ?>
            <form method="post" style="margin-top: 10px;">
                <?php wp_nonce_field('conversioniq_clear_logs'); ?>
                <button type="submit" name="clear_logs" class="button button-secondary" 
                        onclick="return confirm('Are you sure you want to clear all logs?');">Clear All Logs</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ── Heatmap Sync Test Panel ─────────────────────────────────────── -->
    <div style="background: white; padding: 20px; margin-bottom: 20px; border: 1px solid #ccc; border-radius: 4px;">
        <h3 style="margin-top: 0; margin-bottom: 4px;">🔄 Heatmap Sync Tester</h3>
        <p style="color: #666; margin: 0 0 14px;">Manually trigger a 30-day heatmap backfill to Supabase. Use this to test the sync pipeline, recover after a cron failure, or debug why summaries aren't appearing.</p>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
            <button type="button" id="btn-heatmap-sync" class="button button-primary" onclick="triggerHeatmapSync()">
                🔄 Trigger Heatmap Sync (30-day backfill)
            </button>
            <button type="button" id="btn-heatmap-sync-yesterday" class="button" onclick="triggerHeatmapSyncDate('yesterday')">
                📅 Sync Yesterday Only
            </button>
            <span id="sync-status" style="font-size: 13px; margin-left: 4px;"></span>
        </div>

        <div id="sync-results" style="display:none; margin-top: 16px;"></div>
    </div>

    <script>
    function triggerHeatmapSync() {
        runHeatmapSync(
            document.getElementById('btn-heatmap-sync'),
            '<?php echo esc_js( rest_url('conversioniq/v1/heatmap/trigger-sync') ); ?>',
            '{}'
        );
    }

    function triggerHeatmapSyncDate(when) {
        // Calls the external-cron endpoint with yesterday's date via the admin REST route
        runHeatmapSync(
            document.getElementById('btn-heatmap-sync-yesterday'),
            '<?php echo esc_js( rest_url('conversioniq/v1/heatmap/trigger-sync') ); ?>',
            JSON.stringify({ date: when })
        );
    }

    function runHeatmapSync(btn, url, body) {
        const status  = document.getElementById('sync-status');
        const results = document.getElementById('sync-results');
        const origTxt = btn.textContent;

        btn.disabled = true;
        btn.textContent = '⏳ Syncing…';
        status.innerHTML = '<em style="color:#666;">Running — this may take up to 60 seconds…</em>';
        results.style.display = 'none';
        results.innerHTML = '';

        const startMs = Date.now();

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>'
            },
            body: body
        })
        .then(r => {
            if (!r.ok && r.status !== 400) { throw new Error('HTTP ' + r.status); }
            return r.json();
        })
        .then(data => {
            const elapsed = ((Date.now() - startMs) / 1000).toFixed(1);
            btn.disabled = false;
            btn.textContent = origTxt;

            if (data.success) {
                status.innerHTML = '<span style="color:green; font-weight:600;">✓ Complete</span> <span style="color:#888;">(' + elapsed + 's)</span>';
            } else {
                status.innerHTML = '<span style="color:#c00; font-weight:600;">✗ Failed</span> <span style="color:#888;">(' + elapsed + 's)</span>';
            }

            results.innerHTML = buildSyncResultsHtml(data, elapsed);
            results.style.display = 'block';
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = origTxt;
            status.innerHTML = '<span style="color:#c00;">✗ Fetch error: ' + err.message + '</span>';
            results.innerHTML = '<div style="background:#fdf2f2;border:1px solid #e8b4b4;border-radius:4px;padding:12px;color:#c00;">'
                + '<strong>Request failed:</strong> ' + err.message
                + '<br><small>Check that you are logged in as admin and the REST API is accessible.</small></div>';
            results.style.display = 'block';
        });
    }

    function buildSyncResultsHtml(data, elapsed) {
        const d   = data.diagnostics || {};
        const ok  = v => v
            ? '<span style="color:green;font-weight:bold;">✓</span>'
            : '<span style="color:#c00;font-weight:bold;">✗</span>';
        const row = (label, val) =>
            `<tr><td style="padding:4px 12px 4px 0;color:#555;white-space:nowrap;vertical-align:top;">${label}</td><td style="padding:4px 0;">${val}</td></tr>`;

        let html = '<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:16px;font-size:13px;line-height:1.6;">';

        // ── Status banner ──────────────────────────────────────────────────
        const bg  = data.success ? '#d4edda' : '#f8d7da';
        const bdr = data.success ? '#b5d5c4' : '#f5c6cb';
        html += `<div style="background:${bg};border:1px solid ${bdr};border-radius:4px;padding:10px 14px;margin-bottom:16px;font-weight:bold;font-size:14px;">
            ${data.success ? '✅' : '❌'} ${escHtml(data.message || 'No message returned')}
        </div>`;

        // ── Diagnostics ────────────────────────────────────────────────────
        html += '<h4 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;color:#555;letter-spacing:.04em;">Diagnostics</h4>';
        html += '<table style="border-collapse:collapse;margin-bottom:16px;">';
        html += row('API key set',           ok(d.api_key_set)  + ' ' + (d.api_key_set  ? 'Yes' : '<span style="color:#c00;">No — activate your license first</span>'));
        html += row('Org ID set',            ok(d.org_id_set)   + ' ' + (d.org_id_set   ? 'Yes' : '<span style="color:#c00;">No — activate your license first</span>'));
        html += row('WP-Cron scheduled',     ok(d.cron_scheduled) + ' ' + (d.cron_next_utc || 'N/A'));
        html += row('Last sync date',        escHtml(d.last_sync_date || 'Never'));
        html += row('Sessions in MySQL',     (typeof data.mysql_sessions_total === 'number' ? data.mysql_sessions_total.toLocaleString() : '—'));
        html += row('Elapsed time',          elapsed + 's');
        html += '</table>';

        // ── Synced dates ───────────────────────────────────────────────────
        if (d.synced_dates && d.synced_dates.length) {
            html += `<h4 style="margin:0 0 6px;font-size:13px;text-transform:uppercase;color:#555;letter-spacing:.04em;">
                Heatmap days synced — ${d.synced_dates.length}</h4>`;
            html += '<ul style="margin:0 0 14px 18px;padding:0;">';
            d.synced_dates.forEach(s => {
                html += `<li style="color:green;">✓ ${escHtml(s)}</li>`;
            });
            html += '</ul>';
        } else {
            html += '<p style="color:#888;margin:0 0 12px;"><em>No heatmap event days found in MySQL for the last 30 days.</em></p>';
        }

        // ── Enrichment dates ───────────────────────────────────────────────
        if (d.enrichment_dates && d.enrichment_dates.length) {
            html += `<h4 style="margin:0 0 6px;font-size:13px;text-transform:uppercase;color:#555;letter-spacing:.04em;">
                Enrichment days synced — ${d.enrichment_dates.length}</h4>`;
            html += '<ul style="margin:0 0 14px 18px;padding:0;">';
            d.enrichment_dates.forEach(s => {
                html += `<li style="color:#2271b1;">↑ ${escHtml(s)}</li>`;
            });
            html += '</ul>';
        }

        // ── Skipped dates (collapsible) ────────────────────────────────────
        if (d.skipped_dates && d.skipped_dates.length) {
            html += `<details style="margin-bottom:12px;">
                <summary style="cursor:pointer;color:#777;font-size:13px;">
                    Skipped days (no events) — ${d.skipped_dates.length}
                </summary>
                <ul style="margin:6px 0 0 18px;padding:0;color:#aaa;">`;
            d.skipped_dates.forEach(s => { html += `<li>${escHtml(s)}</li>`; });
            html += '</ul></details>';
        }

        // ── Reload hint ────────────────────────────────────────────────────
        html += `<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e0e0e0;display:flex;align-items:center;gap:12px;">
            <span style="color:#666;font-style:italic;">Reload the page to see new entries in the debug log below.</span>
            <button class="button button-small" onclick="window.location.reload()">↻ Reload Page</button>
        </div>`;

        html += '</div>';
        return html;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    </script>

    <!-- ── Traffic Intelligence Sync Test ─────────────────────────────── -->
    <div style="background: white; padding: 20px; margin-bottom: 20px; border: 1px solid #ccc; border-radius: 4px;">
        <h3 style="margin-top: 0; margin-bottom: 4px;">📊 Traffic Intelligence Sync Tester</h3>
        <p style="color: #666; margin: 0 0 14px;">Manually run the daily GA4 + GSC fetch and Supabase push. Bypasses the 1-hour rate limit so you can test the full pipeline without waiting.</p>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
            <button type="button" id="btn-traffic-sync" class="button button-primary" onclick="triggerTrafficSync()">
                📊 Run Traffic Sync (same as daily cron)
            </button>
            <span id="traffic-sync-status" style="font-size: 13px; margin-left: 4px;"></span>
        </div>

        <div id="traffic-sync-results" style="display:none; margin-top: 16px;"></div>
    </div>

    <script>
    function triggerTrafficSync() {
        const btn     = document.getElementById('btn-traffic-sync');
        const status  = document.getElementById('traffic-sync-status');
        const results = document.getElementById('traffic-sync-results');

        btn.disabled   = true;
        btn.textContent = '⏳ Running…';
        status.innerHTML = '<em style="color:#666;">Fetching GA4 + GSC data and pushing to Supabase…</em>';
        results.style.display = 'none';
        results.innerHTML = '';

        const startMs = Date.now();

        fetch('<?php echo esc_js( rest_url('conversioniq/v1/traffic-debug-sync') ); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>'
            },
            body: '{}'
        })
        .then(r => r.json())
        .then(data => {
            const elapsed = ((Date.now() - startMs) / 1000).toFixed(1);
            btn.disabled   = false;
            btn.textContent = '📊 Run Traffic Sync (same as daily cron)';

            if (data.success) {
                status.innerHTML = '<span style="color:green;font-weight:600;">✓ Complete</span> <span style="color:#888;">(' + elapsed + 's)</span>';
            } else {
                status.innerHTML = '<span style="color:#c00;font-weight:600;">✗ Failed</span> <span style="color:#888;">(' + elapsed + 's)</span>';
            }

            let html = '<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:16px;font-size:13px;line-height:1.6;">';

            const bg  = data.success ? '#d4edda' : '#f8d7da';
            const bdr = data.success ? '#b5d5c4' : '#f5c6cb';
            html += `<div style="background:${bg};border:1px solid ${bdr};border-radius:4px;padding:10px 14px;margin-bottom:16px;font-weight:bold;font-size:14px;">
                ${data.success ? '✅' : '❌'} ${escHtml(data.message || (data.success ? 'Sync complete' : 'Sync failed'))}
            </div>`;

            if (data.success) {
                const ok  = v => v ? '<span style="color:green;font-weight:bold;">✓</span>' : '<span style="color:#c00;font-weight:bold;">✗</span>';
                const row = (l, v) => `<tr><td style="padding:4px 12px 4px 0;color:#555;white-space:nowrap;">${l}</td><td style="padding:4px 0;">${v}</td></tr>`;

                html += '<table style="border-collapse:collapse;margin-bottom:14px;">';
                html += row('GA4 data',        ok(data.ga4_ok) + ' ' + (data.ga4_ok ? 'Fetched — ' + (data.ga4_sessions ?? '?') + ' sessions' : 'No data returned'));
                html += row('GSC data',        ok(data.gsc_ok) + ' ' + (data.gsc_ok ? 'Fetched — ' + (data.gsc_clicks ?? '?') + ' clicks' : 'No data returned'));
                html += row('Supabase push',   ok(data.ga4_ok || data.gsc_ok) + ' Included in fetch (see debug log)');
                html += row('Verdict',         escHtml(data.verdict || 'no_data'));
                html += row('Fetched at',      data.fetched_at ? new Date(data.fetched_at * 1000).toLocaleString() : '—');
                html += row('Elapsed',         elapsed + 's');
                html += '</table>';

                if (data.errors && Object.keys(data.errors).length > 0) {
                    html += '<h4 style="margin:0 0 6px;font-size:12px;text-transform:uppercase;color:#c00;">Errors</h4><ul style="margin:0 0 12px 18px;padding:0;">';
                    Object.entries(data.errors).forEach(([k, v]) => {
                        html += `<li style="color:#c00;">${escHtml(k)}: ${escHtml(v)}</li>`;
                    });
                    html += '</ul>';
                }
            }

            html += `<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e0e0e0;">
                <button class="button button-small" onclick="window.location.reload()">↻ Reload to see log entries</button>
            </div>`;
            html += '</div>';

            results.innerHTML = html;
            results.style.display = 'block';
        })
        .catch(err => {
            btn.disabled   = false;
            btn.textContent = '📊 Run Traffic Sync (same as daily cron)';
            status.innerHTML = '<span style="color:#c00;">✗ Fetch error: ' + err.message + '</span>';
            results.innerHTML = '<div style="background:#fdf2f2;border:1px solid #e8b4b4;border-radius:4px;padding:12px;color:#c00;"><strong>Request failed:</strong> ' + err.message + '</div>';
            results.style.display = 'block';
        });
    }
    </script>

    <?php if ($log_exists && !empty($log_content)): ?>
        <div style="background: #1e1e1e; color: #d4d4d4; padding: 20px; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.5; overflow-x: auto; border-radius: 4px; max-height: 70vh; overflow-y: auto;">
            <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($log_content); ?></pre>
        </div>
        
        <p style="margin-top: 15px; color: #666;">
            <strong>Tip:</strong> Search for "ConversionIQ" or specific emoji markers like 🔍, 📄, ✂️, ⚠️ to filter plugin-specific logs.
        </p>
    <?php elseif (!$log_exists): ?>
        <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 20px; border-radius: 4px;">
            <h3>Debug Logging Not Enabled</h3>
            <p>To enable debug logging, add these lines to your <code>wp-config.php</code> file:</p>
            <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);</pre>
            <p>Then refresh this page to view logs.</p>
        </div>
    <?php else: ?>
        <div style="background: #f0f0f0; padding: 20px; text-align: center; border-radius: 4px;">
            <p>No log entries found<?php echo !empty($search_term) ? ' matching "' . esc_html($search_term) . '"' : ''; ?>.</p>
        </div>
    <?php endif; ?>
    
    <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-left: 4px solid #2271b1; border-radius: 4px;">
        <h3>Common Log Markers</h3>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><strong>🔍</strong> - Analysis/audit start</li>
            <li><strong>📄</strong> - Content processing</li>
            <li><strong>✂️</strong> - Data transformation (JSON cleanup, etc.)</li>
            <li><strong>⚠️</strong> - Warnings</li>
            <li><strong>❌</strong> - Errors</li>
            <li><strong>✅</strong> - Success messages</li>
            <li><strong>🔐</strong> - Security/authentication</li>
        </ul>
        
        <p style="margin-top: 15px;"><strong>Example searches:</strong></p>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><code>ConversionIQ</code> - All plugin logs</li>
            <li><code>testimonial</code> - Testimonial extraction logs</li>
            <li><code>trust_score</code> - Trust score related logs</li>
            <li><code>AI Response</code> - AI API responses</li>
        </ul>
    </div>
</div>
