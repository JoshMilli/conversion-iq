# Supabase Integration - Implementation Summary

## Files Added

### 1. `includes/class-supabase-sync.php` ✅ NEW FILE

Complete Supabase synchronization handler that:
- Registers WordPress installation as an organization on first activation
- Sends audit data to Supabase cloud database after each analysis
- Fetches case studies from Supabase (optional enhancement)
- Tracks API usage for analytics and billing
- Checks monthly audit limits

**Source**: Copied from `C:\Users\joshm\Desktop\Conversion IQ\Web-app\wordpress-plugin-files\class-supabase-sync.php`

## Files Modified

### 2. `conversion-iq.php` ✅ MODIFIED

**Line 49** - Added include statement:

```php
// Include required files
require_once CONVERSION_IQ_DIR . 'includes/class-database.php';
require_once CONVERSION_IQ_DIR . 'includes/rest-api.php';
require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
require_once CONVERSION_IQ_DIR . 'includes/class-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-automated-reports.php';
require_once CONVERSION_IQ_DIR . 'includes/class-supabase-sync.php';  // ← NEW
```

### 3. `includes/rest-api.php` ✅ MODIFIED

**Two locations where audit sync was added:**

#### Location 1: Lines ~330-365 (Main Audit Endpoint)

After `$results[] = $ai;` and before `conversioniq_send_webhook( $ai );`:

```php
// Sync audit to Supabase cloud database
try {
    $supabase_sync = new ConversionIQ_Supabase_Sync();
    $business_data = isset($payload['business']) ? $payload['business'] : array();
    $sync_success = $supabase_sync->send_audit(array(
        'page_url' => $page_url,
        'page_title' => $post->post_title,
        'industry' => isset($business_data['industry']) ? $business_data['industry'] : null,
        'clarity_score' => isset($ai['clarity_score']) ? $ai['clarity_score'] : null,
        'emotional_score' => isset($ai['emotional_score']) ? $ai['emotional_score'] : null,
        'cta_strength' => isset($ai['cta_strength']) ? $ai['cta_strength'] : null,
        'readability_score' => isset($ai['readability_score']) ? $ai['readability_score'] : null,
        'engagement_score' => isset($ai['engagement_score']) ? $ai['engagement_score'] : null,
        'trust_score' => isset($ai['trust_score']) ? $ai['trust_score'] : null,
        'overall_score' => isset($ai['overall_score']) ? $ai['overall_score'] : null,
        'suggestions' => isset($ai['suggestions']) ? $ai['suggestions'] : array(),
        'functionality_suggestions' => isset($ai['functionality_suggestions']) ? $ai['functionality_suggestions'] : array(),
        'rewrites' => isset($ai['rewrites']) ? $ai['rewrites'] : array(),
        'analysis_method' => isset($ai['analysis_method']) ? $ai['analysis_method'] : 'single',
        'sections_analyzed' => isset($ai['sections_analyzed']) ? $ai['sections_analyzed'] : 1
    ));
    
    // Track usage for analytics
    $supabase_sync->track_usage('analyze_page');
    
    if (!$sync_success) {
        error_log('ConversionIQ: Warning - Failed to sync audit to Supabase cloud');
    }
} catch (Exception $e) {
    error_log('ConversionIQ: Supabase sync exception - ' . $e->getMessage());
}
```

#### Location 2: Lines ~1124-1165 (Automated/Scheduled Audit Endpoint)

After `$log[] = '    ✅ Audit completed and saved...';`:

```php
// Sync to Supabase cloud database
try {
    $supabase_sync = new ConversionIQ_Supabase_Sync();
    $sync_success = $supabase_sync->send_audit(array(
        'page_url' => $page_url,
        'page_title' => $page->post_title,
        'industry' => isset($business['industry']) ? $business['industry'] : null,
        'clarity_score' => isset($ai_result['clarity_score']) ? $ai_result['clarity_score'] : null,
        'emotional_score' => isset($ai_result['emotional_score']) ? $ai_result['emotional_score'] : null,
        'cta_strength' => isset($ai_result['cta_strength']) ? $ai_result['cta_strength'] : null,
        'readability_score' => isset($ai_result['readability_score']) ? $ai_result['readability_score'] : null,
        'engagement_score' => isset($ai_result['engagement_score']) ? $ai_result['engagement_score'] : null,
        'trust_score' => isset($ai_result['trust_score']) ? $ai_result['trust_score'] : null,
        'overall_score' => isset($ai_result['overall_score']) ? $ai_result['overall_score'] : null,
        'suggestions' => isset($ai_result['suggestions']) ? $ai_result['suggestions'] : array(),
        'functionality_suggestions' => isset($ai_result['functionality_suggestions']) ? $ai_result['functionality_suggestions'] : array(),
        'rewrites' => isset($ai_result['rewrites']) ? $ai_result['rewrites'] : array(),
        'analysis_method' => isset($ai_result['analysis_method']) ? $ai_result['analysis_method'] : 'single',
        'sections_analyzed' => isset($ai_result['sections_analyzed']) ? $ai_result['sections_analyzed'] : 1
    ));
    
    $supabase_sync->track_usage('analyze_page');
    
    if ($sync_success) {
        $log[] = '    ☁️ Synced to Supabase cloud';
    }
} catch (Exception $e) {
    $log[] = '    ⚠️ Supabase sync skipped: ' . $e->getMessage();
}
```

## Configuration Required

### wp-config.php

Add these two lines to your WordPress `wp-config.php` file:

```php
// Supabase Configuration for Conversion IQ
define('CONVERSIONIQ_SUPABASE_URL', 'https://spefdqiywnihehfhrood.supabase.co');
define('CONVERSIONIQ_SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InNwZWZkcWl5d25paGVoZmhyb29kIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Njg5ODI4NDcsImV4cCI6MjA4NDU1ODg0N30.FHJRpodLKgwW6hexRqGXKfcVFS4pwntSq83yNyR74d8');
```

## Data Flow

### First Plugin Activation:

```
1. WordPress Plugin Activates
   ↓
2. class-supabase-sync.php __construct() runs
   ↓
3. Checks for conversioniq_api_key option
   ↓
4. Not found → calls register_installation()
   ↓
5. POST to Supabase /rest/v1/organizations
   ↓
6. Supabase creates organization record
   ↓
7. Returns API key (ciq_xxx...) and organization ID
   ↓
8. Stored in WordPress options:
   - conversioniq_api_key
   - conversioniq_organization_id
```

### Every Audit:

```
1. User clicks "Run Audit" in WordPress
   ↓
2. REST API endpoint receives request
   ↓
3. ConversionIQ_AI::analyze() processes page
   ↓
4. AI returns scores, suggestions, rewrites
   ↓
5. Audit saved to local WordPress database
   ↓
6. NEW: ConversionIQ_Supabase_Sync->send_audit() called
   ↓
7. POST to Supabase /rest/v1/audits
   ↓
8. Audit stored in Supabase cloud
   ↓
9. Admin portal can now see this audit in real-time
```

## What Gets Synced

Every audit sends these fields to Supabase:

- `organization_id` - Which WordPress site this came from
- `page_url` - URL of analyzed page
- `page_title` - Title of page
- `industry` - Business industry (if provided)
- `clarity_score` - Score 0-100
- `emotional_score` - Score 0-100
- `cta_strength` - Score 0-100
- `readability_score` - Score 0-100
- `engagement_score` - Score 0-100
- `trust_score` - Score 0-100
- `overall_score` - Score 0-100
- `suggestions` - Array of improvement suggestions
- `functionality_suggestions` - Array of functionality improvements
- `rewrites` - Array of content rewrites
- `analysis_method` - 'single' or 'chunked'
- `sections_analyzed` - Number of sections (for chunked)
- `ai_used` - Boolean (always true for synced audits)

## Error Handling

The sync is designed to be non-blocking:

- If Supabase is unavailable, audit still completes locally
- Errors are logged but don't affect user experience
- Try-catch blocks prevent sync failures from breaking audits
- Error messages logged to WordPress error log

## Verification

After implementation, verify:

1. ✅ Plugin activates without errors
2. ✅ WordPress options contain `conversioniq_api_key` and `conversioniq_organization_id`
3. ✅ Audits complete successfully
4. ✅ Supabase Table Editor shows new audits in `audits` table
5. ✅ Admin portal at localhost:3000 displays audits
6. ✅ No PHP errors in WordPress error log

## Testing Commands

### Check Registration:

```php
// In WordPress admin or wp-cli
echo get_option('conversioniq_api_key');
echo get_option('conversioniq_organization_id');
```

### Check Supabase Data:

1. Log in to https://supabase.com
2. Open your project (spefdqiywnihehfhrood)
3. Go to Table Editor
4. Open `organizations` table → should see your WordPress site
5. Open `audits` table → should see your audits

### Check Admin Portal:

```bash
# In Web-app directory
cd "C:\Users\joshm\Desktop\Conversion IQ\Web-app"
npm run dev
# Open http://localhost:3000
# Navigate to Audits page
```

## Additional Features in Sync Class

The `ConversionIQ_Supabase_Sync` class includes other useful methods:

### fetch_case_studies()
Retrieve case studies from Supabase to enhance AI recommendations:
```php
$supabase_sync = new ConversionIQ_Supabase_Sync();
$case_studies = $supabase_sync->fetch_case_studies('SaaS');
```

### is_over_limit()
Check if organization has exceeded monthly audit limit:
```php
$supabase_sync = new ConversionIQ_Supabase_Sync();
if ($supabase_sync->is_over_limit()) {
    // Show upgrade message
}
```

### get_stats()
Get organization statistics and plan details:
```php
$supabase_sync = new ConversionIQ_Supabase_Sync();
$stats = $supabase_sync->get_stats();
// Returns: plan, max_audits_per_month, etc.
```

## Summary

✅ **Complete**: All files created and modified
✅ **Tested**: No PHP syntax errors detected
✅ **Documented**: Configuration and testing guides created
✅ **Non-Breaking**: Existing functionality unchanged
✅ **Error-Safe**: Sync failures don't affect user experience

### Next Steps:

1. Add Supabase credentials to wp-config.php
2. Activate/reactivate plugin
3. Run test audit
4. Verify in Supabase
5. Check admin portal
