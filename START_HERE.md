# 🔧 Conversion IQ - Stuck on "Loading..."? START HERE

## What's Happening?

You've installed Conversion IQ but the admin dashboard is stuck showing "Loading..." and never completes.

## Root Cause (Technical)

The React application isn't initializing because one of these is failing:
1. ❌ The JavaScript bundle isn't loading
2. ❌ The WordPress REST API isn't responding
3. ❌ The security nonce isn't available
4. ❌ Browser cache is serving old/broken files

## ✅ SOLUTION (Try in Order)

### STEP 1: Hard Refresh Browser Cache (30 seconds)

**This solves the problem 60% of the time:**

1. Open the Conversion IQ plugin page
2. Press **Ctrl+Shift+R** (Windows) or **Cmd+Shift+R** (Mac)
3. Wait for the page to reload completely (10+ seconds)
4. If it works now, ✅ **DONE!** Problem solved!

### STEP 2: Clear WordPress Cache (2 minutes)

If Step 1 didn't work:

1. Go to **WordPress Admin Panel**
2. Navigate to **Settings → Permalinks**
3. Click the **Save Changes** button (don't modify anything)
4. Wait 30 seconds
5. Go back to **Conversion IQ** and try again
6. If it works now, ✅ **DONE!** Problem solved!

### STEP 3: Check Browser Console for Errors (5 minutes)

If Steps 1-2 didn't work:

1. Open the Conversion IQ page in WordPress
2. **Right-click anywhere** on the page
3. Select **"Inspect"** or press **F12**
4. Click the **"Console"** tab
5. Look for **RED ERROR MESSAGES** (not yellow warnings)

**If you see red errors:**
- Note the error message
- Try searching Google for the error
- Or email support with a screenshot

**If you see "Conversion IQ: Checking authentication...":**
- This means the plugin loaded correctly
- Wait longer (up to 30 seconds)
- If still stuck, check Step 4

### STEP 4: Rebuild the React App (5 minutes)

If Steps 1-3 didn't work, the built React files might be corrupted:

**Requirements:**
- Node.js must be installed on your computer
- Terminal/Command Prompt access

**Steps:**

```bash
# Open terminal/command prompt and run these commands:

# Navigate to the plugin
cd /path/to/wordpress/wp-content/plugins/conversion-iq/admin/frontend

# Install dependencies
npm install

# Rebuild the React app  
npm run build

# Check that the build succeeded
ls -la ../build/vite-dist/assets/
# or on Windows:
dir ..\build\vite-dist\assets\
```

**You should see files like:**
- `index.ABC123.js`
- `index.DEF456.css`

If the build succeeds, ✅ **DONE!** Go back to WordPress and try the plugin.

### STEP 5: Enable Debug Mode (3 minutes)

If you've tried Steps 1-4 without success:

1. Connect to your WordPress installation via SFTP/FTP or File Manager
2. Edit `wp-config.php` (in the root of your WordPress installation)
3. Find this line:
   ```php
   define('WP_DEBUG', false);
   ```
4. Replace it with:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
5. Save the file
6. Go to **Conversion IQ** in WordPress admin
7. Check if a "Diagnostics" submenu appears (it only shows when WP_DEBUG is on)
8. Click **Conversion IQ → Diagnostics**
9. Review the diagnostic report - it will show exactly what's wrong
10. Check `wp-content/debug.log` for error messages

### STEP 6: Test the API Directly (3 minutes)

1. Open **F12 → Console** tab
2. Copy and paste this code:

```javascript
// Test if plugin data is available
console.log('ConversionIQData:', ConversionIQData);

// Test API endpoint
const baseUrl = ConversionIQData?.restUrl || '/wp-json/conversioniq/v1/';
const nonce = ConversionIQData?.nonce;

fetch(baseUrl + 'auth/status', {
  method: 'GET',
  headers: {
    'X-WP-Nonce': nonce || ''
  }
})
.then(response => {
  console.log('API Status:', response.status);
  return response.json();
})
.then(data => {
  console.log('API Response:', data);
})
.catch(error => {
  console.error('API Error:', error);
});
```

3. Press **Enter**
4. Look at the console output:
   - If you see `ConversionIQData: {restUrl: "...", nonce: "..."}` ✓ Good!
   - If you see `ConversionIQData: undefined` ✗ Plugin data not injected
   - If you see API Response with data ✓ REST API working!
   - If you see error ✗ REST API not responding

## 🆘 Still Stuck? 

Provide this information when asking for help:

1. **From Browser Console (F12 → Console):**
   - Screenshot of any RED error messages
   - Output of: `console.log(ConversionIQData)`
   - Output of: `navigator.userAgent`

2. **From WordPress:**
   - WordPress version (found in Admin → Dashboard)
   - PHP version (ask hosting provider)
   - Plugin version (in plugin details)

3. **From wp-config.php debug log:**
   - Copy last 20 lines from `wp-content/debug.log`
   - Look for "Conversion IQ:" entries

4. **From Diagnostics page (if accessible):**
   - Screenshot of the full diagnostic report

## 🚀 Prevention Tips

To prevent this issue in the future:

1. **After updating WordPress or plugins:**
   - Go to **Settings → Permalinks** and click **Save Changes**
   - Clear your browser cache (Ctrl+Shift+Delete)

2. **Keep WordPress debug logs clean:**
   - Regularly check and archive `wp-content/debug.log`
   - Delete files older than 30 days

3. **Monitor your error log:**
   - Check `wp-content/debug.log` after each plugin installation
   - Look for "Conversion IQ:" entries
   - Fix any issues early

## 📚 More Help

- See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for comprehensive troubleshooting
- See [LOADING_FIX.md](LOADING_FIX.md) for more detailed information
- See [QUICK_START.md](QUICK_START.md) for initial setup

---

**Remember:** The diagnostic tool is your best friend! Once you enable WP_DEBUG, the **Conversion IQ → Diagnostics** page will show exactly what's wrong and how to fix it.
