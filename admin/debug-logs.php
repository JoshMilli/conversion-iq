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
