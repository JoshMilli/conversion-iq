# 🔍 Debugging Audit Errors - Quick Guide

## Changes Made to Improve Error Visibility

### 1. Frontend Error Display (app.tsx)
**Before:** Generic message "Audit failed - check server logs"
**After:** Shows specific error details with:
- API error messages from backend
- JavaScript error messages
- 10-second display (instead of 3 seconds)
- Detailed console logging

### 2. Backend Error Logging (rest-api.php)
**Added comprehensive logging:**
- ✅ Validates AI response structure
- ✅ Checks for required fields (clarity_score, suggestions, etc.)
- ✅ Logs actual score values when successful
- ✅ Full stack traces on exceptions
- ✅ Response type validation

### 3. AI Engine Validation (class-ai-engine.php)
**Enhanced error detection:**
- ✅ JSON parsing error details (shows json_last_error_msg)
- ✅ Validates all 6 required score fields
- ✅ Lists missing fields if any
- ✅ Logs response structure
- ✅ Auto-fixes suggestions if not array format
- ✅ Shows first 1000 chars of raw response on parse errors

## How to Debug Audit Failures

### Step 1: Check Browser Console
1. Open WordPress Admin > Conversion IQ
2. Press F12 to open Developer Tools
3. Go to "Console" tab
4. Run an audit
5. Look for messages starting with:
   - 🔍 (audit starting)
   - ❌ (errors)
   - ⚠️ (warnings)
   - ✅ (success)

### Step 2: Check WordPress Debug Log
Enable WordPress debugging in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Then check: `wp-content/debug.log`

**What to look for:**
- `🚀 Calling Abacus.ai route-llm API...` - API call initiated
- `📡 Response status: 200` - API responded
- `📄 AI Response length: X characters` - Got content
- `✅ AI response parsed successfully` - JSON valid
- `❌ Abacus.ai API error: ...` - API call failed
- `⚠️ Failed to parse AI response` - Invalid JSON
- `⚠️ AI response missing required fields` - Incomplete response

### Step 3: Common Error Messages & Solutions

#### Error: "Invalid JSON response"
**Cause:** AI returned malformed JSON
**Check:** Debug log for "Raw response (first 1000 chars)"
**Fix:** The AI might be including markdown or extra text

#### Error: "AI response missing required fields"
**Cause:** AI didn't return all score fields
**Check:** Debug log shows which fields are missing
**Fix:** Prompt might need adjustment

#### Error: "API returned 401"
**Cause:** Invalid or expired API key
**Fix:** Check API key in `class-ai-engine.php` line 8

#### Error: "API returned 429"
**Cause:** Rate limit exceeded
**Fix:** Wait a few minutes, then retry

#### Error: "Empty AI response"
**Cause:** AI returned no content
**Check:** Debug log for API response body
**Fix:** Check API status or try different model

#### Error: "Audit failed: [specific message]"
**Cause:** Frontend now shows the actual backend error
**Action:** Read the message - it tells you exactly what went wrong!

### Step 4: Test with Debug Mode

Run this in browser console while on Conversion IQ page:
```javascript
// Enable verbose logging
localStorage.setItem('ciq_debug', 'true');

// Check API connectivity
fetch(ConversionIQData.restUrl + 'settings', {
  headers: { 'X-WP-Nonce': ConversionIQData.nonce }
})
.then(r => r.json())
.then(d => console.log('Settings API works:', d))
.catch(e => console.error('Settings API failed:', e));
```

### Step 5: Manual API Test

Test the AI directly via curl:
```bash
curl -X POST https://routellm.abacus.ai/v1/chat/completions \
  -H "Authorization: Bearer s2_7b1143d048014d04b7d489a17671b1a7" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "route-llm",
    "messages": [{"role": "user", "content": "Say hello"}],
    "stream": false
  }'
```

If this fails, the API key or endpoint is the problem.

## Error Log Emoji Guide
- 🔍 = Starting operation
- 🌐 = Fetching URL
- 🚀 = Calling API
- 📡 = Got API response
- 📄 = Processing content
- ✅ = Success
- ❌ = Error (critical)
- ⚠️ = Warning (non-critical)

## Quick Fixes

### If audits are stuck loading:
1. Check browser console for errors
2. Refresh the page
3. Clear browser cache (Ctrl+Shift+Delete)
4. Check WordPress debug log

### If no error message appears:
1. Error messages now stay for 10 seconds
2. Check browser console (F12)
3. Error might have already auto-dismissed

### If "Audit failed" with no details:
1. This is now fixed - you should see specific errors
2. If still generic, check debug.log
3. Make sure you're using the new build (index.Cu3uJgZj.js)

## Files Updated
- ✅ `admin/frontend/src/app.tsx` - Better error display
- ✅ `includes/rest-api.php` - Comprehensive validation
- ✅ `includes/class-ai-engine.php` - Detailed AI logging
- ✅ `conversion-iq.php` - New bundle: index.Cu3uJgZj.js

## Test After Update
1. Clear WordPress cache (if using caching plugin)
2. Hard refresh browser (Ctrl+Shift+R)
3. Run an audit
4. You should now see specific error messages if anything fails
