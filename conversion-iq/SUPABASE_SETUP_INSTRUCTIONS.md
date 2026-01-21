# 🚀 Supabase Setup Guide - Complete Instructions

## Step 1: Create Supabase Project (5 minutes)

1. **Go to**: https://supabase.com
2. **Sign up/Login** with GitHub or email
3. **Click**: "New Project"
4. **Fill in**:
   - **Name**: `conversion-iq` (or any name you prefer)
   - **Database Password**: Choose a strong password and **save it securely**
   - **Region**: Choose closest to you (e.g., US East, EU West)
5. **Click**: "Create new project"
6. **Wait**: ~2 minutes for project to initialize

## Step 2: Get Your API Keys (2 minutes)

1. **Go to**: Project Settings (gear icon in left sidebar)
2. **Click**: "API" in the settings menu
3. **Copy these values** (you'll need them):
   ```
   Project URL: https://[your-project].supabase.co
   anon public key: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
   service_role key: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
   ```

## Step 3: Run Database Schema (3 minutes)

1. **Go to**: SQL Editor (database icon in left sidebar)
2. **Click**: "New Query" button
3. **Open the file**: `SUPABASE_COMPLETE_SCHEMA.sql` from your conversion-iq folder
4. **Copy all the SQL** from that file
5. **Paste** into Supabase SQL Editor
6. **Click**: "Run" button (or press Ctrl+Enter)
7. **Wait**: Should see "Success" message
8. **Verify**: Scroll down to see verification queries show your tables

## Step 4: Configure WordPress Plugin (2 minutes)

### Option A: Via wp-config.php (Recommended)

Add these lines to your WordPress `wp-config.php` file:

```php
// Supabase Configuration for Conversion IQ
define('CONVERSIONIQ_SUPABASE_URL', 'https://YOUR-PROJECT-ID.supabase.co');
define('CONVERSIONIQ_SUPABASE_ANON_KEY', 'your-anon-public-key-here');
```

**Replace**:
- `YOUR-PROJECT-ID` with your actual project URL from Step 2
- `your-anon-public-key-here` with your actual anon key from Step 2

### Option B: Via WordPress Admin (Future)
We can add a settings page later for easier configuration.

## Step 5: Activate Plugin & Register (2 minutes)

1. **WordPress Admin** → Plugins → Conversion IQ
2. **Deactivate** (if already active)
3. **Activate** plugin
4. **Check WordPress error log** for:
   ```
   ConversionIQ: Successfully registered as organization [uuid]
   ```

## Step 6: Create Admin User (for Web Portal) (2 minutes)

1. **Go to**: Supabase → Authentication → Users (shield icon)
2. **Click**: "Add user" → "Create new user"
3. **Fill in**:
   - **Email**: Your admin email (e.g., admin@yourcompany.com)
   - **Password**: Choose a secure password
   - **Auto Confirm User**: ✓ Check this box
4. **Click**: "Create user"

## Step 7: Configure Web Admin Portal (3 minutes)

1. **Open**: `C:\Users\joshm\Desktop\Conversion IQ\Web-app`
2. **Create/Edit**: `.env.local` file with:
   ```env
   NEXT_PUBLIC_SUPABASE_URL=https://YOUR-PROJECT-ID.supabase.co
   NEXT_PUBLIC_SUPABASE_ANON_KEY=your-anon-key-here
   SUPABASE_SERVICE_ROLE_KEY=your-service-role-key-here
   ```
3. **Save** the file

## Step 8: Test Everything (5 minutes)

### Test 1: Verify Database Tables
1. **Supabase** → Table Editor
2. **Should see**:
   - ✓ organizations
   - ✓ audits
   - ✓ case_studies
   - ✓ api_usage

### Test 2: Check WordPress Registration
1. **WordPress Admin** → Settings → Options (or use plugin like WP Options)
2. **Look for**:
   - `conversioniq_api_key` → Should have value like `ciq_abc123...`
   - `conversioniq_organization_id` → Should have UUID

Or check via wp-cli:
```bash
wp option get conversioniq_api_key
wp option get conversioniq_organization_id
```

### Test 3: Run an Audit
1. **WordPress Admin** → Conversion IQ
2. **Run an audit** on any page
3. **Check Supabase** → Table Editor → audits
4. **Should see**: New row with your audit data

### Test 4: View in Admin Portal
1. **Open terminal** in Web-app folder:
   ```bash
   cd "C:\Users\joshm\Desktop\Conversion IQ\Web-app"
   npm install  # First time only
   npm run dev
   ```
2. **Open browser**: http://localhost:3000
3. **Login** with admin email/password from Step 6
4. **Navigate to**: Audits page
5. **Should see**: Your audit from WordPress!

## Troubleshooting

### "Project URL not found"
- Double-check you copied the full URL from Step 2
- Make sure URL includes `https://`

### "Registration failed"
- Check WordPress error log
- Verify credentials in wp-config.php
- Make sure Supabase project is active (not paused)

### "No tables found"
- Re-run the SQL schema from Step 3
- Check for SQL errors in Supabase

### "Can't login to admin portal"
- Verify user was created in Step 6
- Check "Auto Confirm User" was checked
- Verify .env.local has correct credentials

### "Audits not showing in Supabase"
- Check WordPress plugin is registered (Step 5)
- Check error log for sync errors
- Verify organization_id exists in WordPress options

## What You Should Have Now

✅ Supabase project with all tables created  
✅ WordPress plugin registered as an organization  
✅ Admin user created for web portal  
✅ Web portal configured and running  
✅ First audit synced and visible everywhere  

## Next Steps

1. ✅ Add more case studies in admin portal
2. ✅ Run audits on multiple pages
3. ✅ Invite team members to admin portal
4. ✅ Deploy admin portal to production (Vercel)
5. ✅ Customize organization plans and limits

## Quick Reference

**Supabase Dashboard**: https://supabase.com/dashboard  
**SQL Schema File**: `SUPABASE_COMPLETE_SCHEMA.sql`  
**Admin Portal**: http://localhost:3000  
**WordPress Plugin**: Conversion IQ  

---

**Total Setup Time**: ~20 minutes  
**Result**: Fully functional multi-tenant SaaS platform! 🎉
