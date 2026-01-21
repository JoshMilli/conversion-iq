# 🚀 Quick Start: Supabase Integration

## ✅ What's Done

All code changes are complete! Here's what was implemented:

### Files Added:
- ✅ `includes/class-supabase-sync.php` - Supabase sync handler

### Files Modified:
- ✅ `conversion-iq.php` - Added sync class include
- ✅ `includes/rest-api.php` - Added sync calls (2 locations)

### Documentation Created:
- ✅ `SUPABASE_CONFIG.md` - Full configuration guide
- ✅ `SUPABASE_INTEGRATION_SUMMARY.md` - Technical implementation details
- ✅ `QUICK_START.md` - This file!

---

## 🎯 Next: 3 Steps to Go Live

### Step 1: Configure Supabase (2 minutes)

Open your WordPress `wp-config.php` and add these two lines:

```php
// Supabase Configuration for Conversion IQ
define('CONVERSIONIQ_SUPABASE_URL', 'https://spefdqiywnihehfhrood.supabase.co');
define('CONVERSIONIQ_SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InNwZWZkcWl5d25paGVoZmhyb29kIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Njg5ODI4NDcsImV4cCI6MjA4NDU1ODg0N30.FHJRpodLKgwW6hexRqGXKfcVFS4pwntSq83yNyR74d8');
```

**Location**: Add after the database configuration section, before `/* That's all, stop editing! */`

### Step 2: Activate Plugin (1 minute)

In WordPress admin:

1. Go to Plugins → Installed Plugins
2. **Deactivate** Conversion IQ (if already active)
3. **Activate** Conversion IQ
4. Look for success in your WordPress error log:
   ```
   ConversionIQ: Successfully registered as organization [UUID]
   ```

**Verify Registration**:
```php
// Check in wp-admin or wp-cli:
get_option('conversioniq_api_key');          // Should return: ciq_abc123...
get_option('conversioniq_organization_id');  // Should return: UUID
```

### Step 3: Test It (2 minutes)

#### 3a. Run an Audit in WordPress

1. Go to Conversion IQ in WordPress admin
2. Select a page
3. Click "Run Audit"
4. Audit should complete normally

#### 3b. Verify in Supabase

1. Log in to https://supabase.com
2. Open your project: **spefdqiywnihehfhrood**
3. Go to **Table Editor**
4. Check **organizations** table:
   - Should see your WordPress site listed
   - Should have an `api_key` starting with `ciq_`
5. Check **audits** table:
   - Should see your audit data
   - Should have all scores, suggestions, etc.

#### 3c. View in Admin Portal

1. Open terminal/PowerShell
2. Navigate to Web-app:
   ```powershell
   cd "C:\Users\joshm\Desktop\Conversion IQ\Web-app"
   npm run dev
   ```
3. Open http://localhost:3000
4. Log in (if required)
5. Navigate to **Audits** page
6. **Your audit should be visible!** 🎉

---

## ✨ What Happens Now

### Automatic Sync

Every time ANY audit runs in your WordPress plugin:

1. ✅ Audit completes locally in WordPress
2. ✅ **Automatically synced to Supabase cloud**
3. ✅ Visible in admin portal immediately
4. ✅ Usage tracked for analytics

### Multi-Site Ready

If you install this plugin on **10 different WordPress sites**:

- Each registers as a separate "organization"
- Each gets its own unique API key
- ALL audits from ALL sites go to Supabase
- Admin portal shows audits from ALL organizations
- Filter/search by organization, date, scores, etc.

### No User Impact

- If Supabase is down, audits still work locally
- Sync happens in the background
- No impact on audit performance
- No impact on user experience

---

## 🔍 Troubleshooting

### Problem: Plugin won't activate

**Check**:
- PHP syntax errors: `tail -f wp-content/debug.log`
- Credentials in wp-config.php are correct
- Supabase project is active

**Fix**:
- Double-check wp-config.php formatting
- Ensure quotes are straight quotes, not smart quotes
- Verify no extra spaces in credentials

### Problem: Audits work but not syncing

**Check**:
1. WordPress error log for "ConversionIQ Sync Error"
2. Options table has `conversioniq_api_key` and `conversioniq_organization_id`
3. Supabase project is active and accepting requests

**Fix**:
- Deactivate and reactivate plugin to re-register
- Check Supabase logs at https://supabase.com (Project → Logs)
- Verify credentials match exactly

### Problem: Admin portal doesn't show audits

**Check**:
1. Supabase Table Editor has data in `audits` table
2. Web-app `.env.local` has correct credentials
3. Next.js dev server is running

**Fix**:
- Restart dev server: `npm run dev`
- Check browser console for errors (F12)
- Verify .env.local matches Supabase credentials

---

## 📊 Verify Everything Works

Run this checklist:

- [ ] wp-config.php has Supabase credentials
- [ ] Plugin activates without errors
- [ ] `conversioniq_api_key` option exists
- [ ] `conversioniq_organization_id` option exists
- [ ] Run test audit in WordPress - completes successfully
- [ ] Supabase → organizations table has your site
- [ ] Supabase → audits table has your audit
- [ ] Admin portal shows the audit
- [ ] No errors in WordPress error log

---

## 📚 Where to Look Next

### Full Documentation:
- **SUPABASE_CONFIG.md** - Complete setup guide
- **SUPABASE_INTEGRATION_SUMMARY.md** - Technical details
- **Web-app/ARCHITECTURE_SUMMARY.md** - System overview
- **Web-app/WORDPRESS_INTEGRATION.md** - Integration guide

### Key Files:
- `includes/class-supabase-sync.php` - All sync logic
- `includes/rest-api.php` - Where sync is called
- `conversion-iq.php` - Main plugin file

---

## 🎉 Success!

If you completed all steps and verified the checklist, you now have:

✅ **Centralized Database** - All audits in Supabase
✅ **Real-time Admin Portal** - See everything at localhost:3000
✅ **Multi-tenant Ready** - Can handle unlimited WordPress sites
✅ **Analytics Ready** - Track usage, trends, improvements
✅ **Scalable** - No server management needed

---

## 🚀 Next Level Features (Future)

Now that data is centralized, you could:

1. **Add More Analytics**
   - Track conversion improvements over time
   - Identify which industries benefit most
   - Compare scores across organizations

2. **Enhanced Case Studies**
   - Pull from Supabase to enhance AI prompts
   - Add new case studies via admin portal
   - Share knowledge across all WordPress sites

3. **Billing & Limits**
   - Use `is_over_limit()` to enforce audit caps
   - Upgrade flows for premium plans
   - Usage dashboards

4. **Webhooks**
   - Notify admin when new audit completes
   - Integration with Slack, Discord, etc.
   - Real-time alerts for poor scores

5. **Deploy Admin Portal**
   - Deploy to Vercel (free tier)
   - Access from anywhere
   - Share with team members

---

## 💬 Questions?

Check the error logs:
- **WordPress**: `wp-content/debug.log`
- **Supabase**: Project dashboard → Logs
- **Browser**: F12 → Console

All documentation is in the `conversion-iq/` folder!

---

**That's it! You're all set up!** 🎊
