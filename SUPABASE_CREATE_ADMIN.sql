-- ============================================================
-- CONVERSION IQ - CREATE ADMIN ACCOUNT IN SUPABASE
-- ============================================================
-- Run this script in: Supabase Dashboard → SQL Editor → New Query
-- This creates a test admin account for logging into any WordPress site
-- ============================================================

-- Insert Admin Account
-- Username: admin
-- Password: password
-- The password hash was generated using PHP's password_hash() with PASSWORD_DEFAULT (bcrypt)
-- This is a standard bcrypt test hash commonly used in Laravel and other frameworks

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
    'Admin Test Account',
    'admin-test.local',
    'ADMIN-' || encode(gen_random_bytes(24), 'hex'),  -- Generate random API key
    'enterprise',
    1000,
    'Admin User',
    'admin@conversioniq.com',
    'Conversion IQ',
    'conversioniq-admin',
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',  -- Password: password
    NOW(),
    NOW()
) ON CONFLICT (username) DO UPDATE SET
    password_hash = EXCLUDED.password_hash,
    updated_at = NOW();

-- ============================================================
-- VERIFICATION QUERY
-- ============================================================
-- Run this after the insert to verify the admin account was created:
-- SELECT id, username, user_email, user_full_name, company_name, plan FROM organizations WHERE username = 'admin';

-- ============================================================
-- LOGIN CREDENTIALS FOR WORDPRESS SITES
-- ============================================================
-- Username: admin
-- Password: password
-- 
-- Use these credentials on ANY WordPress site with Conversion IQ installed
-- ============================================================

-- ============================================================
-- TO CHANGE THE PASSWORD
-- ============================================================
-- 1. Generate a new hash using PHP:
--    <?php echo password_hash('YourNewPassword', PASSWORD_DEFAULT); ?>
-- 
-- 2. Update the password in Supabase:
--    UPDATE organizations 
--    SET password_hash = '$2y$10$YOUR_NEW_HASH_HERE', updated_at = NOW() 
--    WHERE username = 'admin';
-- ============================================================
