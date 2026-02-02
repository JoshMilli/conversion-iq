-- ============================================================
-- CONVERSION IQ - DATABASE MIGRATION v1.7.22
-- ============================================================
-- Add business information fields to organizations table
-- Run this in Supabase SQL Editor
-- ============================================================

-- Add business information columns to organizations table
ALTER TABLE organizations
ADD COLUMN IF NOT EXISTS industry TEXT,
ADD COLUMN IF NOT EXISTS product TEXT,
ADD COLUMN IF NOT EXISTS audience TEXT,
ADD COLUMN IF NOT EXISTS pain_points TEXT,
ADD COLUMN IF NOT EXISTS competitors TEXT,
ADD COLUMN IF NOT EXISTS goal TEXT,
ADD COLUMN IF NOT EXISTS additional_info TEXT;

-- Create indexes for commonly queried fields
CREATE INDEX IF NOT EXISTS idx_organizations_industry ON organizations(industry);

-- Update the updated_at trigger to include new fields
-- (If you have a trigger that updates updated_at, it will automatically include these new fields)

COMMENT ON COLUMN organizations.industry IS 'Business industry or niche';
COMMENT ON COLUMN organizations.product IS 'Products or services offered';
COMMENT ON COLUMN organizations.audience IS 'Target audience or customers';
COMMENT ON COLUMN organizations.pain_points IS 'Main customer pain points (comma separated)';
COMMENT ON COLUMN organizations.competitors IS 'Key competitors (comma separated)';
COMMENT ON COLUMN organizations.goal IS 'Primary conversion goal';
COMMENT ON COLUMN organizations.additional_info IS 'Additional business context and information';
