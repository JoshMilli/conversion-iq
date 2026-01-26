# Conversion IQ - "Loading..." Fix Guide

## Issue
After installing Conversion IQ, the admin dashboard shows "Loading..." and doesn't initialize.

## Quick Fix (Try This First)

### Option 1: Clear All Caches (60% of the time this works)

1. **In WordPress Admin Panel:**
   - Go to **Settings → Permalinks**
   - Click **Save Changes** (don't change anything, just save)
   - This flushes WordPress rewrite rules

2. **Clear Browser Cache:**
   - Press **Ctrl+Shift+Delete** (Windows) or **Cmd+Shift+Delete** (Mac)
   - Select "All time"
   - Check boxes: Cookies, Cached images/files
   - Click **Clear data**

3. **Go back to Conversion IQ:**
   - Navigate to **Conversion IQ** in the WordPress admin menu
   - Wait 5-10 seconds for it to load

### Option 2: Check Browser Console for Errors

1. **Open Browser Developer Tools:**
   - Right-click on the Conversion IQ page
   - Select **"Inspect"** or press **F12**

2. **Go to Console Tab:**
   - Look for any RED error messages
   - Common errors and their fixes below

3. **Screenshot the Errors:**
   - Take a screenshot if you see red errors
   - This helps with debugging

### Option 3: Rebuild the React App

If Options 1 & 2 don't work, the React bundle might be corrupted:

```bash
# From your computer terminal/command line:

# Navigate to the plugin directory
cd /path/to/wordpress/wp-content/plugins/conversion-iq/admin/frontend

# Install dependencies
npm install

# Rebuild the React app
npm run build

# Verify the build output exists
# You should see: ../build/vite-dist/ with assets/index.*.js and index.*.css
```

**Requirements:**
- Node.js 14+ and npm must be installed
- Run from terminal/command prompt

## Common Browser Console Errors & Fixes

### Error: "Nonce is missing! Check if ConversionIQData is loaded."

**Cause:** WordPress security token not loaded

**Fix:**
1. Go to **Settings → Permalinks**
2. Click **Save Changes**
3. Clear browser cache (Ctrl+Shift+Delete)
4. Reload the page

### Error: "Cannot find module" or "404 on assets"

**Cause:** React build files missing

**Fix:**
```bash
cd wp-content/plugins/conversion-iq/admin/frontend
npm install
npm run build
```

Then reload the WordPress page.

### Error: "Auth check failed" with HTTP error

**Cause:** REST API not responding

**Fix:**
1. Go to **Settings → Permalinks**
2. Click **Save Changes**
3. Wait 30 seconds
4. Try Conversion IQ again

### Error: "CORS error" or "Cross-origin"

**Cause:** Browser security preventing API calls

**Fix:**
- Usually auto-resolved after restarting browser
- If persistent, check with your hosting provider about CORS settings

## Access the Diagnostic Tool

If you're still stuck, use the diagnostic tool:

**If WP_DEBUG is enabled:**
1. Go to WordPress Admin
2. Click **Conversion IQ → Diagnostics**
3. This shows detailed troubleshooting information

**If WP_DEBUG is NOT enabled:**
1. Edit `wp-config.php`
2. Add near the bottom:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
3. Save the file
4. Now access: **Conversion IQ → Diagnostics**

## Test the Plugin API

Run this in the browser console (F12 → Console tab):

```javascript
// Check if plugin data is loaded
console.log(ConversionIQData);

// Test the API
const nonce = ConversionIQData?.nonce;
const baseUrl = ConversionIQData?.restUrl || '/wp-json/conversioniq/v1/';

fetch(baseUrl + 'auth/status', {
  headers: {
    'X-WP-Nonce': nonce
  }
})
.then(r => r.json())
.then(data => console.log('API Response:', data))
.catch(err => console.error('API Error:', err));
```

**Expected output:**
- `ConversionIQData` should show an object with `restUrl`, `nonce`, `pluginUrl`, `version`
- API Response should show authentication status

## Verify Build Files Exist

From terminal/command prompt:

```bash
# Windows
dir C:\path\to\wordpress\wp-content\plugins\conversion-iq\admin\build\vite-dist\assets\

# macOS/Linux
ls -la /path/to/wordpress/wp-content/plugins/conversion-iq/admin/build/vite-dist/assets/
```

You should see at least:
- `index.*.js` (the React bundle)
- `index.*.css` (the styles)

If these files don't exist, rebuild with `npm run build`.

## Check Server Error Log

**PHP Errors:**
- Check `wp-content/debug.log` (created when WP_DEBUG is enabled)
- Look for entries starting with "Conversion IQ:"

**Server Logs:**
- Apache: `/var/log/apache2/error.log`
- Nginx: `/var/log/nginx/error.log`
- Shared hosting: Check your control panel (cPanel, Plesk, etc.)

## Check Requirements

Run this in browser console to verify your setup:

```javascript
fetch('/wp-json/')
  .then(r => r.json())
  .then(data => {
    console.log('WordPress Version:', '<?php global $wp_version; echo $wp_version; ?>');
    console.log('PHP Version:', '<?php echo phpversion(); ?>');
    console.log('REST API Available:', !!data.routes);
  });
```

**Requirements:**
- WordPress 6.0+
- PHP 7.4+

## Still Not Working?

1. **Disable Other Plugins**
   - Temporarily deactivate all other plugins
   - Try Conversion IQ
   - If it works, re-enable plugins one by one to find the conflict

2. **Check File Permissions**
   - Ensure plugin files are readable by the web server
   - Usually: `chmod 755` on directories, `644` on files

3. **Reinstall the Plugin**
   - Delete `/wp-content/plugins/conversion-iq/`
   - Upload fresh copy
   - Reactivate

4. **Contact Support**
   Provide:
   - WordPress version
   - PHP version
   - Browser console errors (F12 → Console)
   - Browser name and version
   - WordPress debug log entries

## Prevention Tips

To prevent this issue in the future:

1. **Enable WordPress Debug Mode** (locally/staging):
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

2. **Check Debug Log After Updates:**
   - Look at `wp-content/debug.log`
   - Search for "Conversion IQ:" entries

3. **Keep WordPress & PHP Updated:**
   - Check **Dashboard → Updates** regularly
   - Ask hosting provider about PHP updates

4. **Clear Caches Regularly:**
   - After each WordPress update
   - After plugin updates
   - When adding new pages/posts

## Reference

For more detailed troubleshooting, see:
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Complete diagnostic guide
- [QUICK_START.md](QUICK_START.md) - Initial setup guide
