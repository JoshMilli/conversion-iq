-- ============================================================
-- CONVERSION IQ - FIX RLS POLICY FOR REGISTRATION
-- ============================================================
-- Run this in Supabase SQL Editor to allow public registration
-- ============================================================

-- Drop existing restrictive policy if it exists
DROP POLICY IF EXISTS "Enable read access for all users" ON organizations;
DROP POLICY IF EXISTS "Enable insert for authenticated users only" ON organizations;

-- Allow public INSERT for registration (anonymous users can create accounts)
CREATE POLICY "Allow public registration"
ON organizations
FOR INSERT
TO anon
WITH CHECK (true);

-- Allow users to read their own organization data
CREATE POLICY "Users can read their own organization"
ON organizations
FOR SELECT
TO anon
USING (true);

-- Allow users to update their own organization
CREATE POLICY "Users can update their own organization"
ON organizations
FOR UPDATE
TO anon
USING (true)
WITH CHECK (true);

-- Verify RLS is enabled
ALTER TABLE organizations ENABLE ROW LEVEL SECURITY;

-- Check the policies
SELECT schemaname, tablename, policyname, permissive, roles, cmd, qual, with_check
FROM pg_policies
WHERE tablename = 'organizations';
