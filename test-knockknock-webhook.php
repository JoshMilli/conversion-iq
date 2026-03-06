<?php
/**
 * Test file for KnockKnock Webhook Integration
 * 
 * Usage: Access this file directly in your browser to test the webhook endpoint
 * Make sure to configure your Company ID and Webhook Secret in the plugin settings first!
 */

// WordPress Bootstrap
require_once('../../../wp-load.php');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>KnockKnock Webhook Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #7c3aed;
            padding-bottom: 10px;
        }
        .section {
            margin: 30px 0;
            padding: 20px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 4px solid #7c3aed;
        }
        .config-info {
            background: #eff6ff;
            border: 1px solid #dbeafe;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .success {
            background: #d1fae5;
            border-color: #6ee7b7;
            color: #065f46;
        }
        .error {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        button {
            background: #7c3aed;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin: 10px 10px 10px 0;
        }
        button:hover {
            background: #6d28d9;
        }
        button:disabled {
            background: #d1d5db;
            cursor: not-allowed;
        }
        pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 14px;
            line-height: 1.6;
        }
        .info-label {
            font-weight: 600;
            color: #6b7280;
            display: inline-block;
            width: 150px;
        }
        .info-value {
            color: #111827;
            font-family: monospace;
        }
        #response {
            margin-top: 20px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .table th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
        }
        .table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔔 KnockKnock Webhook Integration Test</h1>
        
        <?php
        // Get current configuration
        $company_id = get_option('conversioniq_knockknock_company_id', '');
        $webhook_secret = get_option('conversioniq_knockknock_webhook_secret', '');
        $webhook_url = home_url('/wp-json/conversioniq/v1/webhook');
        
        // Check database tables
        global $wpdb;
        $table_logs = $wpdb->prefix . 'conversioniq_webhook_logs';
        $table_leads = $wpdb->prefix . 'conversioniq_leads';
        
        $table_exists_logs = $wpdb->get_var("SHOW TABLES LIKE '{$table_logs}'") === $table_logs;
        $table_exists_leads = $wpdb->get_var("SHOW TABLES LIKE '{$table_leads}'") === $table_leads;
        ?>
        
        <div class="section">
            <h2>📊 Configuration Status</h2>
            <div class="config-info <?php echo $company_id ? 'success' : 'error'; ?>">
                <div><span class="info-label">Company ID:</span> <span class="info-value"><?php echo $company_id ?: '❌ Not configured'; ?></span></div>
                <div><span class="info-label">Webhook Secret:</span> <span class="info-value"><?php echo $webhook_secret ? '✅ Configured (' . strlen($webhook_secret) . ' chars)' : '⚠️ Not set (optional)'; ?></span></div>
                <div><span class="info-label">Webhook URL:</span> <span class="info-value"><?php echo $webhook_url; ?></span></div>
            </div>
            
            <div class="config-info <?php echo ($table_exists_logs && $table_exists_leads) ? 'success' : 'error'; ?>">
                <div><span class="info-label">Database Tables:</span></div>
                <div style="margin-left: 20px;">
                    <div>webhook_logs: <?php echo $table_exists_logs ? '✅ Exists' : '❌ Missing'; ?></div>
                    <div>leads: <?php echo $table_exists_leads ? '✅ Exists' : '❌ Missing'; ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!$company_id): ?>
            <div class="section error">
                <h3>⚠️ Setup Required</h3>
                <p>Please configure your KnockKnock Company ID in the Account tab of the Conversion IQ plugin dashboard before testing.</p>
            </div>
        <?php else: ?>
            <div class="section">
                <h2>🧪 Test Webhooks</h2>
                <p>Click the buttons below to simulate webhook events:</p>
                
                <button onclick="testWebhook('new_lead')">📨 Test New Lead Event</button>
                <button onclick="testWebhook('new_user_identified')">👤 Test User Identified Event</button>
                <button onclick="fetchLeads()">🔍 View Stored Leads</button>
                
                <div id="response"></div>
            </div>
            
            <div class="section">
                <h2>📝 Sample Payloads</h2>
                <h3>New Lead Event:</h3>
                <pre><?php
$new_lead_payload = [
    'event' => 'new_lead',
    'data' => [
        'user_session' => [
            '_id' => '64f1a2b3c4d5e6f7a8b9c0d1',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'page_url' => home_url('/pricing')
        ],
        'contact_information' => [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1234567890'
        ]
    ],
    'webhook_id' => '6712abc' . uniqid(),
    'company_id' => $company_id,
    'timestamp' => time()
];
echo json_encode($new_lead_payload, JSON_PRETTY_PRINT);
?></pre>

                <h3>New User Identified Event:</h3>
                <pre><?php
$new_user_payload = [
    'event' => 'new_user_identified',
    'data' => [
        'user_session' => [
            '_id' => '64f1a2b3c4d5e6f7a8b9c0d2',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'page_url' => home_url('/contact')
        ]
    ],
    'webhook_id' => '6712def' . uniqid(),
    'company_id' => $company_id,
    'timestamp' => time()
];
echo json_encode($new_user_payload, JSON_PRETTY_PRINT);
?></pre>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
    async function testWebhook(eventType) {
        const responseDiv = document.getElementById('response');
        responseDiv.innerHTML = '<div style="padding: 20px; background: #fef3c7; border-radius: 8px; margin-top: 20px;">⏳ Sending webhook...</div>';
        
        const companyId = '<?php echo $company_id; ?>';
        const webhookSecret = '<?php echo $webhook_secret; ?>';
        const timestamp = Math.floor(Date.now() / 1000);
        
        let payload;
        if (eventType === 'new_lead') {
            payload = {
                event: 'new_lead',
                data: {
                    user_session: {
                        _id: '64f1a2b3c4d5e6f7a8b9c0d1',
                        first_name: 'John',
                        last_name: 'Doe',
                        email: 'john.doe.test.' + Date.now() + '@example.com',
                        page_url: '<?php echo home_url('/pricing'); ?>'
                    },
                    contact_information: {
                        name: 'John Doe',
                        email: 'john.doe.test.' + Date.now() + '@example.com',
                        phone: '+1234567890'
                    }
                },
                webhook_id: '6712abc' + Math.random().toString(36).substr(2, 9),
                company_id: companyId,
                timestamp: timestamp
            };
        } else {
            payload = {
                event: 'new_user_identified',
                data: {
                    user_session: {
                        _id: '64f1a2b3c4d5e6f7a8b9c0d2',
                        first_name: 'Jane',
                        last_name: 'Smith',
                        email: 'jane.smith.test.' + Date.now() + '@example.com',
                        page_url: '<?php echo home_url('/contact'); ?>'
                    }
                },
                webhook_id: '6712def' + Math.random().toString(36).substr(2, 9),
                company_id: companyId,
                timestamp: timestamp
            };
        }
        
        const body = JSON.stringify(payload);
        
        // Calculate HMAC signature
        let signature = '';
        if (webhookSecret) {
            const encoder = new TextEncoder();
            const data = encoder.encode(timestamp + '.' + body);
            const keyData = encoder.encode(webhookSecret);
            const key = await crypto.subtle.importKey(
                'raw',
                keyData,
                { name: 'HMAC', hash: 'SHA-256' },
                false,
                ['sign']
            );
            const signatureBuffer = await crypto.subtle.sign('HMAC', key, data);
            signature = Array.from(new Uint8Array(signatureBuffer))
                .map(b => b.toString(16).padStart(2, '0'))
                .join('');
        }
        
        try {
            const response = await fetch('<?php echo $webhook_url; ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Webhook-Signature': signature,
                    'X-Webhook-Timestamp': timestamp.toString(),
                    'X-Webhook-Event': eventType
                },
                body: body
            });
            
            const result = await response.json();
            
            if (response.ok) {
                responseDiv.innerHTML = `
                    <div style="padding: 20px; background: #d1fae5; border-radius: 8px; margin-top: 20px; border-left: 4px solid #10b981;">
                        <h3 style="margin-top: 0; color: #065f46;">✅ Success!</h3>
                        <div><strong>Status:</strong> ${response.status}</div>
                        <div><strong>Event:</strong> ${eventType}</div>
                        <div><strong>Log ID:</strong> ${result.log_id || 'N/A'}</div>
                        <pre style="background: #064e3b; color: #d1fae5; margin-top: 10px;">${JSON.stringify(result, null, 2)}</pre>
                    </div>
                `;
            } else {
                responseDiv.innerHTML = `
                    <div style="padding: 20px; background: #fee2e2; border-radius: 8px; margin-top: 20px; border-left: 4px solid #ef4444;">
                        <h3 style="margin-top: 0; color: #991b1b;">❌ Error</h3>
                        <div><strong>Status:</strong> ${response.status}</div>
                        <div><strong>Message:</strong> ${result.error || 'Unknown error'}</div>
                        <pre style="background: #7f1d1d; color: #fee2e2; margin-top: 10px;">${JSON.stringify(result, null, 2)}</pre>
                    </div>
                `;
            }
        } catch (error) {
            responseDiv.innerHTML = `
                <div style="padding: 20px; background: #fee2e2; border-radius: 8px; margin-top: 20px; border-left: 4px solid #ef4444;">
                    <h3 style="margin-top: 0; color: #991b1b;">❌ Network Error</h3>
                    <div>${error.message}</div>
                </div>
            `;
        }
    }
    
    async function fetchLeads() {
        const responseDiv = document.getElementById('response');
        responseDiv.innerHTML = '<div style="padding: 20px; background: #fef3c7; border-radius: 8px; margin-top: 20px;">⏳ Loading leads...</div>';
        
        try {
            const response = await fetch('<?php echo home_url('/wp-json/conversioniq/v1/webhooks'); ?>', {
                headers: {
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                }
            });
            
            const result = await response.json();
            
            if (response.ok && result.leads) {
                if (result.leads.length === 0) {
                    responseDiv.innerHTML = `
                        <div style="padding: 20px; background: #eff6ff; border-radius: 8px; margin-top: 20px;">
                            <h3 style="margin-top: 0; color: #1e40af;">ℹ️ No Leads Yet</h3>
                            <p>No webhook data has been received yet. Try sending a test event above!</p>
                        </div>
                    `;
                } else {
                    let tableHTML = `
                        <div style="padding: 20px; background: #d1fae5; border-radius: 8px; margin-top: 20px;">
                            <h3 style="margin-top: 0; color: #065f46;">✅ Found ${result.leads.length} Lead(s)</h3>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Page</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    result.leads.forEach(lead => {
                        const name = (lead.first_name || '') + ' ' + (lead.last_name || '');
                        const date = new Date(lead.converted_at).toLocaleString();
                        tableHTML += `
                            <tr>
                                <td>${name.trim() || 'N/A'}</td>
                                <td>${lead.email || 'N/A'}</td>
                                <td>${lead.page_url || 'N/A'}</td>
                                <td>${date}</td>
                            </tr>
                        `;
                    });
                    
                    tableHTML += '</tbody></table></div>';
                    responseDiv.innerHTML = tableHTML;
                }
            } else {
                responseDiv.innerHTML = `
                    <div style="padding: 20px; background: #fee2e2; border-radius: 8px; margin-top: 20px;">
                        <h3 style="margin-top: 0; color: #991b1b;">❌ Error Loading Leads</h3>
                        <div>${result.error || 'Unknown error'}</div>
                    </div>
                `;
            }
        } catch (error) {
            responseDiv.innerHTML = `
                <div style="padding: 20px; background: #fee2e2; border-radius: 8px; margin-top: 20px;">
                    <h3 style="margin-top: 0; color: #991b1b;">❌ Network Error</h3>
                    <div>${error.message}</div>
                </div>
            `;
        }
    }
    </script>
</body>
</html>
