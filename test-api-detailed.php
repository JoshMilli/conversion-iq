<?php
/**
 * Detailed API Test Script
 * Upload to: wp-content/plugins/conversion-iq/test-api-detailed.php
 * Access via: https://projectwebtec7.com/bs4m8nir8pfpdx/wp-content/plugins/conversion-iq/test-api-detailed.php
 */

header('Content-Type: text/html; charset=utf-8');

// Test configuration
$api_key = 's2_7b1143d048014d04b7d489a17671b1a7';
$api_url = 'https://routellm.abacus.ai/v1/chat/completions';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Abacus.ai API Diagnostic Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .test { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { border-left: 4px solid #4CAF50; }
        .error { border-left: 4px solid #f44336; }
        .warning { border-left: 4px solid #ff9800; }
        h2 { margin-top: 0; color: #333; }
        pre { background: #f9f9f9; padding: 15px; overflow-x: auto; border-radius: 4px; }
        .label { font-weight: bold; color: #666; }
        .value { color: #333; }
        .http-500 { color: #f44336; font-weight: bold; }
        .http-200 { color: #4CAF50; font-weight: bold; }
        .http-401 { color: #ff9800; font-weight: bold; }
    </style>
</head>
<body>
    <h1>🔍 Abacus.ai API Diagnostic Test</h1>
    <p>Testing API endpoint: <code><?php echo htmlspecialchars($api_url); ?></code></p>
    <p>API Key: <code><?php echo htmlspecialchars(substr($api_key, 0, 20)) . '...'; ?></code></p>

<?php

// Test 1: Simple echo test
echo '<div class="test">';
echo '<h2>Test 1: Simple Echo Request</h2>';

$test1_body = array(
    'model' => 'openrouter/auto',
    'messages' => array(
        array(
            'role' => 'user',
            'content' => 'Reply with just the word "SUCCESS" and nothing else.'
        )
    ),
    'max_tokens' => 50,
    'temperature' => 0.1
);

$test1_args = array(
    'headers' => array(
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
    ),
    'body' => json_encode($test1_body),
    'timeout' => 30,
    'sslverify' => true,
);

$start_time = microtime(true);
$response1 = @file_get_contents(
    $api_url,
    false,
    stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Authorization: Bearer {$api_key}\r\n" .
                       "Content-Type: application/json\r\n",
            'content' => json_encode($test1_body),
            'timeout' => 30,
        )
    ))
);
$elapsed_time = round((microtime(true) - $start_time) * 1000);

if ($response1 === false) {
    $error = error_get_last();
    echo '<div class="error">';
    echo '<p><span class="label">Status:</span> <span class="http-500">FAILED</span></p>';
    echo '<p><span class="label">Error:</span> <span class="value">' . htmlspecialchars($error['message']) . '</span></p>';
    echo '</div>';
} else {
    $status_code = 'Unknown';
    foreach ($http_response_header as $header) {
        if (preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $header, $matches)) {
            $status_code = $matches[1];
        }
    }
    
    $status_class = 'http-200';
    if ($status_code != 200) {
        $status_class = $status_code == 401 || $status_code == 403 ? 'http-401' : 'http-500';
    }
    
    echo '<p><span class="label">Status Code:</span> <span class="' . $status_class . '">' . htmlspecialchars($status_code) . '</span></p>';
    echo '<p><span class="label">Response Time:</span> <span class="value">' . $elapsed_time . 'ms</span></p>';
    echo '<p><span class="label">Response Length:</span> <span class="value">' . strlen($response1) . ' bytes</span></p>';
    
    // Check if response is HTML (error page)
    if (stripos($response1, '<!DOCTYPE html>') !== false || stripos($response1, '<html') !== false) {
        echo '<div class="error">';
        echo '<p><strong>⚠️ Response is HTML, not JSON (API returned error page)</strong></p>';
        echo '<p><span class="label">First 500 chars:</span></p>';
        echo '<pre>' . htmlspecialchars(substr($response1, 0, 500)) . '</pre>';
        echo '</div>';
    } else {
        $json_data = json_decode($response1, true);
        
        if ($json_data === null) {
            echo '<div class="warning">';
            echo '<p><strong>⚠️ Response is not valid JSON</strong></p>';
            echo '<p><span class="label">JSON Error:</span> <span class="value">' . json_last_error_msg() . '</span></p>';
            echo '<p><span class="label">Raw Response:</span></p>';
            echo '<pre>' . htmlspecialchars(substr($response1, 0, 1000)) . '</pre>';
            echo '</div>';
        } else {
            if (isset($json_data['error'])) {
                echo '<div class="error">';
                echo '<p><strong>❌ API returned error</strong></p>';
                echo '<p><span class="label">Error Type:</span> <span class="value">' . htmlspecialchars($json_data['error']['type'] ?? 'unknown') . '</span></p>';
                echo '<p><span class="label">Error Message:</span> <span class="value">' . htmlspecialchars($json_data['error']['message'] ?? json_encode($json_data['error'])) . '</span></p>';
                echo '<p><span class="label">Full Response:</span></p>';
                echo '<pre>' . htmlspecialchars(json_encode($json_data, JSON_PRETTY_PRINT)) . '</pre>';
                echo '</div>';
            } elseif (isset($json_data['choices'][0]['message']['content'])) {
                echo '<div class="success">';
                echo '<p><strong>✅ SUCCESS! API is working</strong></p>';
                echo '<p><span class="label">Response Content:</span> <span class="value">' . htmlspecialchars($json_data['choices'][0]['message']['content']) . '</span></p>';
                echo '<p><span class="label">Model Used:</span> <span class="value">' . htmlspecialchars($json_data['model'] ?? 'unknown') . '</span></p>';
                echo '<p><span class="label">Full Response:</span></p>';
                echo '<pre>' . htmlspecialchars(json_encode($json_data, JSON_PRETTY_PRINT)) . '</pre>';
                echo '</div>';
            } else {
                echo '<div class="warning">';
                echo '<p><strong>⚠️ Unexpected response structure</strong></p>';
                echo '<p><span class="label">Response Keys:</span> <span class="value">' . htmlspecialchars(implode(', ', array_keys($json_data))) . '</span></p>';
                echo '<p><span class="label">Full Response:</span></p>';
                echo '<pre>' . htmlspecialchars(json_encode($json_data, JSON_PRETTY_PRINT)) . '</pre>';
                echo '</div>';
            }
        }
    }
}

echo '</div>';

// Test 2: Alternative model name
echo '<div class="test">';
echo '<h2>Test 2: Alternative Model Names</h2>';

$models_to_test = array(
    'gpt-3.5-turbo',
    'gpt-4',
    'claude-3-haiku',
    'anthropic/claude-3-haiku'
);

foreach ($models_to_test as $model) {
    echo '<h3>Testing model: <code>' . htmlspecialchars($model) . '</code></h3>';
    
    $test_body = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'user', 'content' => 'Say "OK"')
        ),
        'max_tokens' => 10
    );
    
    $start_time = microtime(true);
    $response = @file_get_contents(
        $api_url,
        false,
        stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Authorization: Bearer {$api_key}\r\n" .
                           "Content-Type: application/json\r\n",
                'content' => json_encode($test_body),
                'timeout' => 30,
            )
        ))
    );
    $elapsed = round((microtime(true) - $start_time) * 1000);
    
    if ($response === false) {
        echo '<p>❌ Request failed</p>';
        continue;
    }
    
    $status = 'Unknown';
    foreach ($http_response_header as $header) {
        if (preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $header, $matches)) {
            $status = $matches[1];
        }
    }
    
    $json = json_decode($response, true);
    
    if ($status == 200 && isset($json['choices'][0]['message']['content'])) {
        echo '<p>✅ <strong>SUCCESS</strong> - Status: ' . $status . ' - Time: ' . $elapsed . 'ms</p>';
        echo '<p>Response: <code>' . htmlspecialchars(substr($json['choices'][0]['message']['content'], 0, 100)) . '</code></p>';
    } elseif (stripos($response, '<!DOCTYPE html>') !== false) {
        echo '<p>❌ HTML error page - Status: ' . $status . '</p>';
    } elseif (isset($json['error'])) {
        echo '<p>❌ API Error - Status: ' . $status . ' - ' . htmlspecialchars($json['error']['message'] ?? json_encode($json['error'])) . '</p>';
    } else {
        echo '<p>⚠️ Unexpected response - Status: ' . $status . '</p>';
    }
}

echo '</div>';

// Test 3: Check API endpoint accessibility
echo '<div class="test">';
echo '<h2>Test 3: API Endpoint Health Check</h2>';

$health_check = @file_get_contents($api_url, false, stream_context_create(array(
    'http' => array('method' => 'GET', 'timeout' => 10)
)));

if ($health_check === false) {
    echo '<p>⚠️ Cannot reach API endpoint with GET request (expected - most chat APIs require POST)</p>';
} else {
    echo '<p>✅ API endpoint is reachable</p>';
    echo '<pre>' . htmlspecialchars(substr($health_check, 0, 500)) . '</pre>';
}

echo '</div>';

?>

    <div class="test">
        <h2>💡 Recommendations</h2>
        <ul>
            <li><strong>If you see HTTP 500 errors:</strong> The API key may be invalid, expired, or rate limited. Check your Abacus.ai dashboard.</li>
            <li><strong>If you see HTTP 401/403 errors:</strong> Authentication issue - verify your API key is correct.</li>
            <li><strong>If you see HTML responses:</strong> The API is returning error pages instead of JSON - typically server-side errors.</li>
            <li><strong>If a different model works:</strong> The <code>openrouter/auto</code> model might not be supported on this endpoint.</li>
            <li><strong>Next step:</strong> Check <a href="https://docs.abacus.ai/" target="_blank">Abacus.ai documentation</a> for correct model names and API usage.</li>
        </ul>
    </div>

</body>
</html>
