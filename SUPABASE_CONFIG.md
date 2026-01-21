# Supabase Configuration for WordPress Plugin

## Overview

This WordPress plugin now syncs all audit data to Supabase cloud database for centralized management through your admin portal.

## Setup Instructions

### Step 1: Add Supabase Credentials to WordPress

You have two options for configuring the Supabase credentials:

#### Option A: wp-config.php (Recommended - More Secure)

Add these lines to your `wp-config.php` file (usually in the root of your WordPress installation):

```php
// Supabase Configuration for Conversion IQ
define('CONVERSIONIQ_SUPABASE_URL', 'https://spefdqiywnihehfhrood.supabase.co');
define('CONVERSIONIQ_SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InNwZWZkcWl5d25paGVoZmhyb29kIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Njg5ODI4NDcsImV4cCI6MjA4NDU1ODg0N30.FHJRpodLKgwW6hexRqGXKfcVFS4pwntSq83yNyR74d8');
```

#### Option B: WordPress Admin Settings Page (Future Enhancement)

We can add a settings page in WordPress admin where you can enter these credentials through a UI. This would be located at:
- WordPress Admin → Settings → Conversion IQ

### Step 2: Activate or Reinstall Plugin

Once the credentials are configured:

1. Deactivate the plugin (if already active)
2. Reactivate the plugin
3. The plugin will automatically:
   - Register your WordPress site as an "organization" in Supabase
   - Generate a unique API key
   - Store the organization ID and API key in WordPress options

### Step 3: Verify Registration

After activation, check your WordPress options to confirm registration:

```php
// Check these WordPress options:
get_option('conversioniq_api_key');          // Should have a value like: ciq_abc123...
get_option('conversioniq_organization_id');  // Should have a UUID value
```

Or check the error log for a success message:
```
ConversionIQ: Successfully registered as organization [UUID]
```

## What Happens Now

### On Every Audit:

1. ✅ User runs audit in WordPress
2. ✅ Plugin analyzes page with AI (using your chunking system)
3. ✅ Plugin saves audit to local WordPress database
4. ✅ **Plugin sends complete audit to Supabase cloud**
5. ✅ Plugin tracks usage for analytics
6. ✅ Admin portal at localhost:3000 shows the audit in real-time

### Data Synced to Supabase:

- Page URL and title
- Industry (if specified)
- All scores (clarity, emotional, CTA, readability, engagement, trust, overall)
- Suggestions and recommendations
- Functionality suggestions
- Rewrite suggestions
- Analysis method (single or chunked)
- Number of sections analyzed
- Timestamp

### Admin Portal Access:

- Open: `http://localhost:3000`
- Navigate to "Audits" page
- See ALL audits from ALL WordPress sites
- Filter by organization, date, scores, etc.

## Architecture

```
WordPress Sites (Multiple)
    ↓ (Each audit)
Supabase Cloud Database
    ↓ (Real-time access)
Admin Portal (localhost:3000)
```

## Supabase Credentials

### What You Have:

- **Supabase URL**: `https://spefdqiywnihehfhrood.supabase.co`
- **Anon Key**: `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...` (safe to use in WordPress)
- **Service Role Key**: `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...` (NEVER use in WordPress - admin portal only)

### Security Notes:

✅ **Safe to use in WordPress**:
- Supabase URL (it's public)
- Supabase anon key (designed to be public, protected by Row Level Security)

❌ **NEVER use in WordPress**:
- Supabase service_role key (this bypasses all security - only use in trusted admin portal)

## Troubleshooting

### Plugin can't register as organization:

1. Check that credentials are in wp-config.php
2. Check WordPress error log for messages starting with "ConversionIQ:"
3. Verify Supabase project is active (log in to supabase.com)
4. Try deactivating and reactivating plugin

### Audits not syncing to Supabase:

1. Check that organization is registered (see Step 3 above)
2. Run an audit and check WordPress error log
3. Look for messages like:
   - "ConversionIQ Sync Error: ..."
   - "ConversionIQ: Warning - Failed to sync audit to Supabase cloud"
4. Check Supabase Table Editor to verify data is being received

### Admin portal not showing audits:

1. Check that Supabase actually has the data (Table Editor → audits)
2. Verify admin portal's .env.local has correct credentials
3. Check browser console for errors
4. Restart Next.js dev server: `npm run dev`

## Testing the Integration

### Test 1: Registration

```bash
# Activate plugin and check logs
tail -f /path/to/wordpress/wp-content/debug.log | grep "ConversionIQ"
```

Expected output:
```
ConversionIQ: Successfully registered as organization abc-123-def-456
```

### Test 2: Audit Sync

1. Run an audit in WordPress admin
2. Check WordPress error log - should NOT see sync errors
3. Open Supabase Table Editor → audits table
4. Should see new row with your audit data

### Test 3: Admin Portal

1. Open `http://localhost:3000`
2. Log in to admin portal
3. Navigate to "Audits" page
4. Should see the audit you just created

## Database Tables in Supabase

### organizations
- Stores each WordPress installation
- Fields: id, name, domain, api_key, plan, max_audits_per_month

### audits
- Stores every audit from all sites
- Fields: id, organization_id, page_url, page_title, scores, suggestions, etc.

### case_studies
- CRO expert case studies (managed in admin portal)
- Used to enhance AI recommendations

### api_usage
- Tracks usage for analytics and billing
- Fields: organization_id, endpoint, request_count, date

## Next Steps

1. ✅ Add credentials to wp-config.php
2. ✅ Activate plugin
3. ✅ Run a test audit
4. ✅ Verify in Supabase Table Editor
5. ✅ Check admin portal
6. 🔜 Deploy admin portal to production (Vercel)
7. 🔜 Add Row Level Security policies in Supabase

## Support

If you encounter issues:

1. Check WordPress error log
2. Check browser console (admin portal)
3. Check Supabase logs (Project → Logs)
4. Verify credentials are correct
5. Ensure Supabase project is active

## Files Modified

- ✅ `includes/class-supabase-sync.php` - New file for Supabase integration
- ✅ `conversion-iq.php` - Added require for sync class
- ✅ `includes/rest-api.php` - Added sync calls after audit generation

## Benefits

✅ Centralized data - all audits in one place
✅ Real-time access - see audits instantly
✅ Multi-tenant - handle unlimited WordPress sites
✅ Analytics - track trends across all clients
✅ Case studies - share knowledge across all sites
✅ No additional server needed - Supabase handles everything
