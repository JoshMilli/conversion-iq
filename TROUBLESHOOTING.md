# Conversion IQ - Loading Issue Troubleshooting

## Problem
After installing the Conversion IQ plugin, the admin dashboard shows "Loading..." indefinitely and doesn't initialize.

## Root Causes

This can happen due to several reasons:

1. **React bundle not loading** - The compiled JavaScript files aren't being found or enqueued
2. **API not responding** - The REST API isn't properly registered or responding
3. **Nonce not available** - The WordPress security token isn't passed to the frontend
4. **Cache issues** - Browser or server cache is preventing fresh load
5. **PHP errors** - Silent errors preventing proper initialization

## Quick Fix Steps

### Step 1: Clear All Caches
```bash
# If you have a terminal or command line access:
# Navigate to your WordPress root and:

# WordPress cache
wp cache flush

# Browser cache - In WordPress admin:
# Go to: Conversion IQ > Settings
# Click "Clear Cache & Reload"
```

### Step 2: Check Browser Console for Errors
1. Open the plugin admin page
2. Right-click → "Inspect" (or press F12)
3. Go to "Console" tab
4. Look for red error messages
5. Check if you see: "Conversion IQ: Checking authentication..."

### Step 3: Verify Plugin Data is Available
Run this in browser console:
```javascript
// If this returns an object with restUrl and nonce, the plugin is loading correctly
console.log(ConversionIQData);

// If undefined, the wp_localize_script failed
// If empty nonce, there's a security issue
```

### Step 4: Test API Endpoint
Run this in browser console:
```javascript
const base = ConversionIQData?.restUrl || '/wp-json/conversioniq/v1/';
const nonce = ConversionIQData?.nonce;

fetch(base + 'auth/status', {
  headers: {
    'X-WP-Nonce': nonce || ''
  }
})
.then(r => r.json())
.then(data => console.log('API Response:', data))
.catch(err => console.error('API Error:', err));
```

### Step 5: Check WordPress Error Log
1. Find your WordPress error log (usually `wp-content/debug.log`)
2. Look for entries starting with "Conversion IQ:"
3. Check for PHP errors related to the plugin

Common log entries to look for:
```
Conversion IQ: Enqueueing admin assets for hook: toplevel_page_conversion-iq
Conversion IQ: Looking for assets in: ...
Conversion IQ: Found files: ...
```

### Step 6: Rebuild the Admin Dashboard
If the React build is missing or corrupted:

```bash
# Navigate to: /admin/frontend/
cd conversion-iq/admin/frontend

# Install dependencies
npm install

# Build the React app
npm run build

# The output should go to: ../build/vite-dist/
# Verify the files exist:
# - admin/build/vite-dist/assets/index.*.js
# - admin/build/vite-dist/assets/index.*.css
```

### Step 7: Verify Build Files Exist
Check that these files exist in your WordPress installation:
```
/wp-content/plugins/conversion-iq/admin/build/vite-dist/assets/index.*.js
/wp-content/plugins/conversion-iq/admin/build/vite-dist/assets/index.*.css
```

If they don't exist, you need to rebuild (see Step 6).

### Step 8: Check Permissions
Verify the plugin can read these directories:
```bash
# SSH/Terminal command:
ls -la /path/to/conversion-iq/admin/build/vite-dist/assets/

# Files should be readable by the web server user (www-data, apache, etc.)
# If not, fix permissions:
chmod 755 /path/to/conversion-iq/admin/build/vite-dist/assets/*
```

## Debugging with Test Pages

### Test 1: Plugin Initialization Test
Visit: `http://yoursite.com/wp-content/plugins/conversion-iq/test-plugin-init.php`

This shows:
- Plugin activation status
- Defined constants
- Build files present
- REST API routes registered
- Database tables created
- WordPress error log

### Test 2: Dashboard Troubleshooting
Edit your admin menu to temporary display: `admin/debug-dashboard.php` instead of `admin/dashboard.php`

This shows:
- Plugin data availability
- API endpoint responsiveness
- Build file accessibility
- Detailed error information

## Solution by Error Type

### Error: "Nonce is missing!"
- **Cause**: `wp_localize_script` didn't run
- **Fix**: 
  1. Clear WordPress object cache: `wp cache flush`
  2. Check `conversion-iq.php` line ~210 - ensure `wp_localize_script` is called after `wp_enqueue_script`
  3. Verify nonce generation: `echo wp_create_nonce( 'wp_rest' );` in browser console

### Error: "Auth check failed" with HTTP error
- **Cause**: REST API not enabled or not responding
- **Fix**:
  1. Go to WordPress Settings → Permalinks
  2. Click "Save Changes" (without changing anything)
  3. This flushes rewrite rules
  4. Try the plugin again

### Error: "Cannot find module assets/index.*.js"
- **Cause**: React build files missing
- **Fix**:
  1. Navigate to `/admin/frontend/`
  2. Run: `npm install` then `npm run build`
  3. Verify files exist in `admin/build/vite-dist/assets/`

### Error: "404 on script load"
- **Cause**: Incorrect URL path or file not readable
- **Fix**:
  1. Check browser Network tab (F12 → Network)
  2. Find the request for `index.*.js`
  3. Note the full URL
  4. Visit that URL directly in browser - should download the file
  5. If 404, rebuild the assets (see Step 6)

## Advanced Debugging

### Enable Debug Mode
In `wp-config.php`, add:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', true);
```

Then check `wp-content/debug.log` for detailed error messages.

### Check Network Requests
1. F12 → Network tab
2. Reload the plugin page
3. Look for requests with red status (4xx, 5xx)
4. Click on each and check Response tab
5. Note any error messages

### Check REST API Registration
Run in browser console:
```javascript
fetch('/wp-json/')
  .then(r => r.json())
  .then(data => {
    const ciq = data.routes['/conversioniq/v1/auth/status'];
    console.log('Conversion IQ API registered:', !!ciq);
  });
```

## Still Not Working?

1. **Check PHP version**: Must be 7.4+
   ```bash
   php -v
   ```

2. **Check WordPress version**: Must be 6.0+
   ```
   In WordPress admin: Settings → General → WordPress Version
   ```

3. **Check for conflicting plugins**: Disable other plugins temporarily

4. **Check JavaScript console**: Make sure no 3rd party script is breaking things

5. **Review error log**: Check `wp-content/debug.log` for any clues

6. **Check file permissions**: Ensure plugin files are readable

## If All Else Fails

1. **Reinstall the plugin**:
   - Delete the `/wp-content/plugins/conversion-iq/` directory
   - Re-upload the plugin files
   - Reactivate in WordPress admin

2. **Reset plugin options**:
   ```bash
   # Via WordPress admin:
   # Go to Tools → MySQL Database Admin (if available)
   # Run: DELETE FROM wp_options WHERE option_name LIKE 'conversioniq%';
   ```

3. **Check Server Logs**:
   - Apache error log: `/var/log/apache2/error.log`
   - Nginx error log: `/var/log/nginx/error.log`
   - PHP-FPM log: `/var/log/php-fpm.log`

## Contact Support
If none of these solutions work, provide:
1. PHP version
2. WordPress version
3. Browser console errors (F12 → Console tab)
4. WordPress debug log (`wp-content/debug.log`)
5. Network tab errors (F12 → Network tab)
