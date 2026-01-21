-- ============================================================
-- CONVERSION IQ v1.7.0 - SUPABASE MIGRATION
-- ============================================================
-- Run this in Supabase SQL Editor to add authentication support
-- Go to: Supabase Dashboard → SQL Editor → New Query → Paste & Run
-- ============================================================

-- Add password_hash column to organizations table
ALTER TABLE organizations 
ADD COLUMN IF NOT EXISTS password_hash TEXT;

-- Make username unique if not already
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'organizations_username_key'
    ) THEN
        ALTER TABLE organizations 
        ADD CONSTRAINT organizations_username_key UNIQUE (username);
    END IF;
END $$;

-- Verify the changes
SELECT column_name, data_type 
FROM information_schema.columns 
WHERE table_name = 'organizations' 
AND column_name IN ('password_hash', 'username');
