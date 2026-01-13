# Manual Edits Required for Auto-Fill Business Info Feature

## 1. Backend: Add REST Endpoint (rest-api.php)

**File**: `includes/rest-api.php`

**Location**: After line 95 (after the `/report` endpoint registration), inside the `rest_api_init` action

**Add this endpoint registration**:
```php
    register_rest_route( 'conversioniq/v1', '/guess-business-info', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_guess_business_info',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ) );
```

**At the end of the file (before closing PHP tag)**, add this function:
```php
function conversioniq_guess_business_info( WP_REST_Request $request ) {
    error_log( '🔍 Auto-fill: Fetching homepage content' );
    
    // Get homepage URL
    $home_url = get_home_url();
    $response = wp_remote_get( $home_url, array(
        'timeout' => 15,
        'sslverify' => false,
    ) );
    
    if ( is_wp_error( $response ) ) {
        error_log( '❌ Failed to fetch homepage: ' . $response->get_error_message() );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to fetch homepage' ), 500 );
    }
    
    $html = wp_remote_retrieve_body( $response );
    $content = wp_strip_all_tags( $html );
    $content = preg_replace( '/\s+/', ' ', $content ); // Normalize whitespace
    $content = substr( $content, 0, 3000 ); // Limit to first 3000 chars
    
    error_log( '✅ Homepage content fetched (' . strlen( $content ) . ' chars)' );
    
    // Build AI prompt for business info extraction
    $prompt = "You are analyzing a homepage to extract business information. Extract the following details from the page content below:

**Required Information:**
- Industry/Niche: What industry or market does this business operate in?
- Product/Service: What specific products or services do they sell?
- Target Audience: Who are their customers? (demographics, roles, etc.)
- Pain Points: What problems do they solve for customers? (comma-separated list)
- Competitors: Who might their competitors be in this space? (comma-separated list of similar businesses)
- Goal: What is the primary conversion goal? (e.g., 'Book a call', 'Purchase product', 'Sign up for trial')
- Additional Info: Any other relevant context about the business (unique selling points, special offers, guarantees, etc.)

**Homepage Content:**
{$content}

**Return format (JSON only, no markdown):**
{
  \"industry\": \"Industry name\",
  \"product\": \"What they sell\",
  \"audience\": \"Who they sell to\",
  \"pain_points\": \"Problem 1, Problem 2, Problem 3\",
  \"competitors\": \"Competitor 1, Competitor 2\",
  \"goal\": \"Primary conversion goal\",
  \"additional_info\": \"Other relevant context\"
}

IMPORTANT: Return ONLY valid JSON, no code blocks, no explanations.";

    // Call AI
    $ai_body = array(
        'model' => 'route-llm',
        'messages' => array(
            array(
                'role' => 'user',
                'content' => $prompt
            )
        ),
        'stream' => false
    );
    
    $ai_args = array(
        'headers' => array(
            'Authorization' => 'Bearer s2_7b1143d048014d04b7d489a17671b1a7',
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode( $ai_body ),
        'timeout' => 60,
        'sslverify' => true,
    );
    
    error_log( '🤖 Calling AI to extract business info...' );
    $ai_response = wp_remote_post( 'https://routellm.abacus.ai/v1/chat/completions', $ai_args );
    
    if ( is_wp_error( $ai_response ) ) {
        error_log( '❌ AI API error: ' . $ai_response->get_error_message() );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'AI analysis failed' ), 500 );
    }
    
    $ai_data = json_decode( wp_remote_retrieve_body( $ai_response ), true );
    
    if ( ! isset( $ai_data['choices'][0]['message']['content'] ) ) {
        error_log( '⚠️ No AI response content' );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'AI returned no content' ), 500 );
    }
    
    $ai_content = trim( $ai_data['choices'][0]['message']['content'] );
    
    // Remove markdown code blocks if present
    if ( preg_match( '/```json\s*(.*?)\s*```/s', $ai_content, $matches ) ) {
        $ai_content = $matches[1];
    } elseif ( preg_match( '/```\s*(.*?)\s*```/s', $ai_content, $matches ) ) {
        $ai_content = $matches[1];
    }
    
    $fields = json_decode( $ai_content, true );
    
    if ( ! $fields ) {
        error_log( '⚠️ Failed to parse AI response as JSON' );
        error_log( 'Raw AI response: ' . substr( $ai_content, 0, 500 ) );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Failed to parse AI response' ), 500 );
    }
    
    error_log( '✅ Successfully extracted business info' );
    
    return rest_ensure_response( array(
        'success' => true,
        'fields' => $fields
    ) );
}
```

## 2. Frontend: Update app.tsx

### Edit 1: Update handleSettingsChange (Line ~73)

**Find**:
```typescript
  const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement>) => {
```

**Replace with**:
```typescript
  const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
```

### Edit 2: Add handleGuessFields function (After handleSettingsChange, around line 75)

**Add after `handleSettingsChange` function**:
```typescript
  
  const handleGuessFields = async () => {
    setLoading(true);
    setNotice('🤖 Analyzing your homepage to extract business information...');
    try {
      const response = await axios.post(
        api('guess-business-info'),
        {},
        { headers: { 'X-WP-Nonce': nonce } }
      );
      
      if (response.data.success && response.data.fields) {
        setSettings({ ...settings, ...response.data.fields });
        setNotice('✅ Business information extracted successfully!');
        setTimeout(() => setNotice(null), 3000);
      } else {
        throw new Error('Failed to extract information');
      }
    } catch (err) {
      console.error('❌ Auto-fill failed:', err);
      setNotice('Failed to extract business info - check server logs');
      setTimeout(() => setNotice(null), 3000);
    } finally {
      setLoading(false);
    }
  };
```

### Edit 3: Update Business Information heading (Around line 273)

**Find**:
```tsx
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <h2 style={{ margin: '0 0 8px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>Business Information</h2>
```

**Replace with**:
```tsx
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
              <h2 style={{ margin: 0, fontSize: 24, fontWeight: 700, color: '#111827' }}>Business Information</h2>
              <button 
                onClick={handleGuessFields} 
                disabled={loading}
                style={{ 
                  padding: '10px 20px', 
                  background: loading ? '#d1d5db' : 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)', 
                  color: '#fff', 
                  border: 'none', 
                  borderRadius: 8, 
                  fontSize: 14, 
                  fontWeight: 600, 
                  cursor: loading ? 'not-allowed' : 'pointer',
                  boxShadow: '0 2px 8px rgba(245, 158, 11, 0.3)',
                  transition: 'all 0.2s' 
                }}
                onMouseEnter={(e) => !loading && (e.currentTarget.style.transform = 'translateY(-2px)')}
                onMouseLeave={(e) => !loading && (e.currentTarget.style.transform = 'translateY(0)')}
              >
                🪄 Guess these fields for me
              </button>
            </div>
```

### Edit 4: Update inputs grid (Around line 278)

**Find**:
```tsx
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
```

**Replace with**:
```tsx
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginBottom: 16 }}>
```

### Edit 5: Add Additional Information textarea (After the inputs grid, before Save Settings button, around line 285)

**Find the closing `</div>` after the last input (goal field), and add this AFTER it**:
```tsx
            <div style={{ marginBottom: 16 }}>
              <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>Additional Information (Optional)</label>
              <textarea 
                name="additional_info" 
                placeholder="Any other context about your business, unique selling points, or specific areas you'd like the AI to focus on..." 
                value={settings.additional_info || ''} 
                onChange={handleSettingsChange}
                rows={4}
                style={{ 
                  width: '100%', 
                  padding: '12px 16px', 
                  border: '1px solid #d1d5db', 
                  borderRadius: 8, 
                  fontSize: 14, 
                  outline: 'none', 
                  transition: 'border 0.2s', 
                  background: '#fff', 
                  color: '#111827',
                  fontFamily: 'Inter,Arial,Helvetica,sans-serif',
                  resize: 'vertical'
                }} 
                onFocus={(e) => e.target.style.borderColor = '#7c3aed'} 
                onBlur={(e) => e.target.style.borderColor = '#d1d5db'} 
              />
            </div>
```

## 3. Rebuild Frontend

After making the edits, rebuild:
```powershell
cd "c:\Users\joshm\Desktop\Conversion IQ\conversion-iq\admin\frontend"
npm run build
```

Then update the PHP file to reference the new bundle hash in `conversion-iq.php`.

## Summary of Changes

1. **Backend**: Added `/guess-business-info` REST endpoint that:
   - Fetches homepage content
   - Uses AI to extract business information (industry, product, audience, pain points, competitors, goal, additional_info)
   - Returns structured JSON with extracted fields

2. **Frontend**: 
   - Added "Guess these fields for me" button with orange gradient styling
   - Added `handleGuessFields` function to call the new endpoint
   - Updated `handleSettingsChange` to support textarea inputs
   - Added "Additional Information" textarea field for extra context

## Testing

1. Go to Business Information tab
2. Click "🪄 Guess these fields for me" button
3. Watch the progress message while AI analyzes homepage
4. Fields should auto-fill with extracted information
5. Review and edit as needed
6. Save settings
