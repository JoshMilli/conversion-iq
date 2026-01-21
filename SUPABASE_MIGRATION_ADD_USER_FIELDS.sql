-- Add user/account fields to organizations table
-- Run this in Supabase SQL Editor to track user data for each WordPress installation

ALTER TABLE organizations
ADD COLUMN IF NOT EXISTS user_full_name TEXT,
ADD COLUMN IF NOT EXISTS user_email TEXT,
ADD COLUMN IF NOT EXISTS company_name TEXT,
ADD COLUMN IF NOT EXISTS company_id TEXT,
ADD COLUMN IF NOT EXISTS username TEXT;

-- Add index on email for faster lookups
CREATE INDEX IF NOT EXISTS idx_organizations_email ON organizations(user_email);

-- Add index on company_id for grouping sites by company
CREATE INDEX IF NOT EXISTS idx_organizations_company ON organizations(company_id);

-- Verify the changes
SELECT column_name, data_type 
FROM information_schema.columns 
WHERE table_name = 'organizations'
ORDER BY ordinal_position;
