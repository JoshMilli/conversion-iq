# Conversion IQ - Admin Account Setup

## Overview
This guide explains how to set up a universal admin account in Supabase that can be used to log into Conversion IQ on **any WordPress site** without creating a new account for each site.

## Quick Setup (5 minutes)

### Step 1: Create Admin Account in Supabase

1. Open your **Supabase Dashboard**
2. Go to **SQL Editor** → **New Query**
3. Copy and paste the entire contents of `SUPABASE_CREATE_ADMIN.sql`
4. Click **Run** (or press `Ctrl+Enter`)
5. You should see: "Success. No rows returned"

### Step 2: Verify Account Creation

Run this query in Supabase SQL Editor:

```sql
SELECT id, username, user_email, user_full_name, company_name, plan 
FROM organizations 
WHERE username = 'admin';
```

You should see one row with:
- **Username:** admin
- **Email:** admin@conversioniq.com
- **Plan:** enterprise

### Step 3: Login on Any WordPress Site

1. Install Conversion IQ plugin on any WordPress site
2. Go to the plugin dashboard
3. Click "Sign In"
4. Enter credentials:
   - **Username:** `admin`
   - **Password:** `password`
5. ✓ You're logged in!

## Default Credentials

```
Username: admin
Password: password
```

**🔒 Security Note:** Change the password after first use (see below)

## How It Works

### Authentication Flow

1. **Plugin sends login request** to WordPress REST API
2. **WordPress queries Supabase** organizations table by username
3. **Password is verified** using PHP's `password_verify()` against the stored bcrypt hash
4. **Account data is cached** in WordPress options for performance
5. **API key is stored** for future Supabase operations

### Key Files

- **REST API:** `includes/rest-api.php` - `conversioniq_auth_login()` function (line 935)
- **Supabase Sync:** `includes/class-supabase-sync.php` - `validate_login()` method (line 210)
- **Organizations Table:** Supabase database table with username/password_hash columns

## Changing the Password

### Option 1: Using the Test Script

1. Edit `test-password-hash.php` and change the password variable:
   ```php
   $password = 'YourNewSecurePassword123!';
   ```

2. Run the script:
   ```bash
   php test-password-hash.php
   ```

3. Copy the generated hash

4. Update Supabase:
   ```sql
   UPDATE organizations 
   SET password_hash = 'YOUR_COPIED_HASH_HERE', 
       updated_at = NOW() 
   WHERE username = 'admin';
   ```

### Option 2: Manual Hash Generation

Use any online bcrypt generator or run this PHP:

```php
<?php
echo password_hash('YourNewPassword', PASSWORD_DEFAULT);
?>
```

Then update Supabase with the generated hash.

## Testing the Login

### Test Username/Password Validation

1. Open your WordPress site with Conversion IQ installed
2. Open browser console (F12)
3. Click "Sign In" and enter admin credentials
4. Check console for authentication logs:
   ```
   === Conversion IQ: Authentication Check Started ===
   ✓ Auth API Response: {authenticated: true, account: {...}}
   ✓ User authenticated successfully
   ```

### Test Supabase Connection

Run this query in Supabase to see if the account exists:

```sql
SELECT username, user_email, company_name, created_at 
FROM organizations 
WHERE username = 'admin';
```

## Troubleshooting

### "Invalid username or password"

**Check 1:** Verify the admin account exists in Supabase
```sql
SELECT * FROM organizations WHERE username = 'admin';
```

**Check 2:** Test password hash manually
```php
<?php
$hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
var_dump(password_verify('password', $hash)); // Should output: bool(true)
?>
```

**Check 3:** Check WordPress error logs
```bash
tail -f wp-content/debug.log | grep "ConversionIQ"
```

### "Supabase not configured"

1. Go to WordPress Admin → Conversion IQ → Settings
2. Enter your Supabase credentials:
   - **Supabase URL:** `https://your-project.supabase.co`
   - **Anon Key:** Your public anon key from Supabase
3. Save settings and try logging in again

### "Login failed. Please try again."

This is a generic error. Check:
1. WordPress `debug.log` for detailed error message
2. Browser console for network errors
3. Supabase logs in Supabase Dashboard → Logs

## Creating Additional Admin Accounts

To create more admin accounts with different usernames:

```sql
INSERT INTO organizations (
    name,
    domain,
    api_key,
    plan,
    max_audits_per_month,
    user_full_name,
    user_email,
    company_name,
    company_id,
    username,
    password_hash,
    created_at,
    updated_at
) VALUES (
    'Second Admin Account',
    'admin2-test.local',
    'ADMIN2-' || encode(gen_random_bytes(24), 'hex'),
    'enterprise',
    1000,
    'Admin User 2',
    'admin2@conversioniq.com',
    'Conversion IQ',
    'conversioniq-admin2',
    'admin2',  -- Different username
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    NOW(),
    NOW()
);
```

## Security Best Practices

1. **Change default password immediately** after first login
2. **Use strong passwords:** Minimum 12 characters, mix of letters/numbers/symbols
3. **Don't share credentials:** Create separate accounts for each team member
4. **Rotate passwords regularly:** Update passwords every 90 days
5. **Monitor access:** Check Supabase logs for suspicious login attempts

## Files Reference

| File | Purpose |
|------|---------|
| `SUPABASE_CREATE_ADMIN.sql` | SQL script to create admin account in Supabase |
| `test-password-hash.php` | Generate password hashes for testing |
| `ADMIN_ACCOUNT_SETUP.md` | This documentation file |
| `includes/rest-api.php` | Login endpoint implementation |
| `includes/class-supabase-sync.php` | Supabase authentication logic |

## Support

If you encounter issues:
1. Check the troubleshooting section above
2. Review WordPress `debug.log`
3. Check Supabase Dashboard → Logs
4. Verify Supabase RLS policies are configured correctly (see `SUPABASE_FIX_RLS.sql`)
