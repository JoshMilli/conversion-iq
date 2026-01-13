<?php
/**
 * Quick test script to check if Abacus.ai API is working
 * Run this in browser: yoursite.com/wp-content/plugins/conversion-iq/test-ai-api.php
 */

// Load WordPress
require_once('../../../wp-load.php');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Conversion IQ - API Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        h2 { color: #7c3aed; margin-top: 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #3b82f6; }
        .step { background: #eff6ff; padding: 10px; margin: 10px 0; border-left: 4px solid #3b82f6; }
    </style>
</head>
<body>
    <h1>🧪 Conversion IQ API Test</h1>
    
    <div class="box">
        <h2>Configuration</h2>
        <?php
        $api_key = 's2_7b1143d048014d04b7d489a17671b1a7';
        $api_url = 'https://routellm.abacus.ai/v1/chat/completions';
        
        echo "<div class='step'>";
        echo "<strong>API URL:</strong> $api_url<br>";
        echo "<strong>API Key:</strong> " . substr($api_key, 0, 15) . "...<br>";
        echo "<strong>Test Type:</strong> Simple JSON response test";
        echo "</div>";
        ?>
    </div>
    
    <div class="box">
        <h2>Test 1: Basic API Connection</h2>
        <?php
        $simple_prompt = 'Return only this exact JSON without any markdown or extra text: {"test": "success", "number": 42}';
        
        $body = array(
            'model' => 'route-llm',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $simple_prompt
                )
            ),
            'stream' => false
        );
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $body ),
            'timeout' => 30,
            'sslverify' => true,
        );
        
        echo "<p class='info'>📤 Sending request...</p>";
        $start = microtime(true);
        $response = wp_remote_post( $api_url, $args );
        $elapsed = round(microtime(true) - $start, 2);
        
        if ( is_wp_error( $response ) ) {
            echo "<p class='error'>❌ ERROR: " . $response->get_error_message() . "</p>";
            echo "<p class='error'>Error code: " . $response->get_error_code() . "</p>";
        } else {
            $status_code = wp_remote_retrieve_response_code( $response );
            echo "<p class='info'>⏱️ Response time: {$elapsed}s</p>";
            echo "<p class='info'>📡 HTTP Status: $status_code</p>";
            
            if ( $status_code !== 200 ) {
                echo "<p class='error'>❌ API returned error status: $status_code</p>";
                $error_body = wp_remote_retrieve_body( $response );
                echo "<pre>" . htmlspecialchars($error_body) . "</pre>";
            } else {
                echo "<p class='success'>✅ API connection successful!</p>";
                
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
                
                echo "<h3>Raw Response:</h3>";
                echo "<pre>" . htmlspecialchars(substr($body, 0, 1000)) . "</pre>";
                
                if ( isset( $data['choices'][0]['message']['content'] ) ) {
                    $content = $data['choices'][0]['message']['content'];
                    echo "<h3>AI Response Content:</h3>";
                    echo "<pre>" . htmlspecialchars($content) . "</pre>";
                    
                    // Try to parse as JSON
                    $parsed = json_decode(trim($content), true);
                    if ($parsed) {
                        echo "<p class='success'>✅ Valid JSON response!</p>";
                        echo "<pre>" . json_encode($parsed, JSON_PRETTY_PRINT) . "</pre>";
                    } else {
                        echo "<p class='error'>❌ Failed to parse as JSON: " . json_last_error_msg() . "</p>";
                    }
                } else {
                    echo "<p class='error'>❌ Unexpected response structure</p>";
                }
            }
        }
        ?>
    </div>
    
    <div class="box">
        <h2>Test 2: Audit-Style Request</h2>
        <?php
        $audit_prompt = 'Analyze this page and return ONLY valid JSON (no markdown): {"clarity_score": 85, "emotional_score": 78, "cta_strength": 82, "readability_score": 90, "engagement_score": 75, "trust_score": 88, "suggestions": [{"text": "Test suggestion", "section": "Hero"}], "ai_used": true}';
        
        $body2 = array(
            'model' => 'route-llm',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $audit_prompt
                )
            ),
            'stream' => false
        );
        
        $args2 = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $body2 ),
            'timeout' => 30,
            'sslverify' => true,
        );
        
        echo "<p class='info'>📤 Sending audit-style request...</p>";
        $start2 = microtime(true);
        $response2 = wp_remote_post( $api_url, $args2 );
        $elapsed2 = round(microtime(true) - $start2, 2);
        
        if ( is_wp_error( $response2 ) ) {
            echo "<p class='error'>❌ ERROR: " . $response2->get_error_message() . "</p>";
        } else {
            $status_code2 = wp_remote_retrieve_response_code( $response2 );
            echo "<p class='info'>⏱️ Response time: {$elapsed2}s</p>";
            echo "<p class='info'>📡 HTTP Status: $status_code2</p>";
            
            if ( $status_code2 !== 200 ) {
                echo "<p class='error'>❌ API returned error status: $status_code2</p>";
                $error_body2 = wp_remote_retrieve_body( $response2 );
                echo "<pre>" . htmlspecialchars($error_body2) . "</pre>";
            } else {
                echo "<p class='success'>✅ Audit-style request successful!</p>";
                
                $body2_result = wp_remote_retrieve_body( $response2 );
                $data2 = json_decode( $body2_result, true );
                
                if ( isset( $data2['choices'][0]['message']['content'] ) ) {
                    $content2 = $data2['choices'][0]['message']['content'];
                    echo "<h3>AI Response:</h3>";
                    echo "<pre>" . htmlspecialchars($content2) . "</pre>";
                    
                    // Remove markdown if present
                    $clean_content = trim($content2);
                    if ( preg_match( '/```json\s*(.*?)\s*```/s', $clean_content, $matches ) ) {
                        $clean_content = $matches[1];
                        echo "<p class='info'>ℹ️ Removed markdown code blocks</p>";
                    }
                    
                    $parsed2 = json_decode(trim($clean_content), true);
                    if ($parsed2) {
                        echo "<p class='success'>✅ Valid audit JSON!</p>";
                        
                        // Check for required fields
                        $required = ['clarity_score', 'emotional_score', 'cta_strength', 'readability_score', 'engagement_score', 'trust_score'];
                        $missing = [];
                        foreach ($required as $field) {
                            if (!isset($parsed2[$field])) {
                                $missing[] = $field;
                            }
                        }
                        
                        if (empty($missing)) {
                            echo "<p class='success'>✅ All required fields present!</p>";
                        } else {
                            echo "<p class='error'>❌ Missing fields: " . implode(', ', $missing) . "</p>";
                        }
                        
                        echo "<pre>" . json_encode($parsed2, JSON_PRETTY_PRINT) . "</pre>";
                    } else {
                        echo "<p class='error'>❌ Failed to parse as JSON: " . json_last_error_msg() . "</p>";
                    }
                }
            }
        }
        ?>
    </div>
    
    <div class="box">
        <h2>Diagnosis</h2>
        <div class="step">
            <strong>If both tests pass:</strong> The API is working correctly. The issue might be with the prompt length or content in actual audits.
        </div>
        <div class="step">
            <strong>If tests fail with network errors:</strong> Check firewall, SSL certificates, or server outbound connections.
        </div>
        <div class="step">
            <strong>If tests fail with 401/403:</strong> API key is invalid or expired.
        </div>
        <div class="step">
            <strong>If JSON parsing fails:</strong> The AI is returning markdown-wrapped JSON. This should be handled in the code.
        </div>
    </div>
    
</body>
</html>
