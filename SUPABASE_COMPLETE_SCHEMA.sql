-- ============================================================
-- CONVERSION IQ - COMPLETE SUPABASE DATABASE SCHEMA
-- ============================================================
-- Run this entire script in Supabase SQL Editor
-- Go to: Supabase Dashboard → SQL Editor → New Query → Paste & Run
-- ============================================================

-- Organizations Table (WordPress sites with user account data)
CREATE TABLE organizations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    domain TEXT,
    plan TEXT DEFAULT 'free',
    api_key TEXT UNIQUE NOT NULL,
    max_audits_per_month INTEGER DEFAULT 10,
    -- User/Account Fields
    user_full_name TEXT,
    user_email TEXT,
    company_name TEXT,
    company_id TEXT,
    username TEXT UNIQUE,
    password_hash TEXT,
    -- Timestamps
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Case Studies Table (CRO expert knowledge base)
CREATE TABLE case_studies (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    title TEXT NOT NULL,
    industry TEXT NOT NULL,
    problem TEXT NOT NULL,
    solution TEXT NOT NULL,
    results TEXT NOT NULL,
    before_score INTEGER,
    after_score INTEGER,
    conversion_lift TEXT,
    key_tactics JSONB,
    applicable_to JSONB,
    is_public BOOLEAN DEFAULT true,
    created_by UUID,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Audits Table (All audit results from all WordPress sites)
CREATE TABLE audits (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    organization_id UUID REFERENCES organizations(id) ON DELETE CASCADE,
    page_url TEXT NOT NULL,
    page_title TEXT,
    industry TEXT,
    -- Scores
    clarity_score INTEGER,
    emotional_score INTEGER,
    cta_strength INTEGER,
    readability_score INTEGER,
    engagement_score INTEGER,
    trust_score INTEGER,
    overall_score INTEGER,
    -- Analysis Data
    suggestions JSONB,
    functionality_suggestions JSONB,
    rewrites JSONB,
    analysis_method TEXT,
    sections_analyzed INTEGER DEFAULT 1,
    ai_used BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);

-- API Usage Tracking (For analytics and billing)
CREATE TABLE api_usage (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    organization_id UUID REFERENCES organizations(id) ON DELETE CASCADE,
    endpoint TEXT,
    request_count INTEGER DEFAULT 1,
    date DATE DEFAULT CURRENT_DATE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- ============================================================
-- INDEXES (For Performance)
-- ============================================================

-- Organizations indexes
CREATE INDEX idx_organizations_email ON organizations(user_email);
CREATE INDEX idx_organizations_company ON organizations(company_id);
CREATE INDEX idx_organizations_api_key ON organizations(api_key);

-- Audits indexes
CREATE INDEX idx_audits_org ON audits(organization_id);
CREATE INDEX idx_audits_created ON audits(created_at DESC);
CREATE INDEX idx_audits_overall_score ON audits(overall_score);

-- Case Studies indexes
CREATE INDEX idx_case_studies_industry ON case_studies(industry);
CREATE INDEX idx_case_studies_public ON case_studies(is_public);

-- API Usage indexes
CREATE INDEX idx_api_usage_org_date ON api_usage(organization_id, date);

-- ============================================================
-- ROW LEVEL SECURITY (Optional - for production)
-- ============================================================

-- Enable RLS on all tables
ALTER TABLE organizations ENABLE ROW LEVEL SECURITY;
ALTER TABLE audits ENABLE ROW LEVEL SECURITY;
ALTER TABLE case_studies ENABLE ROW LEVEL SECURITY;
ALTER TABLE api_usage ENABLE ROW LEVEL SECURITY;

-- Policy: Allow service role to do everything (for your admin portal)
CREATE POLICY "Service role full access - organizations"
    ON organizations FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role')
    WITH CHECK (auth.jwt() ->> 'role' = 'service_role');

CREATE POLICY "Service role full access - audits"
    ON audits FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role')
    WITH CHECK (auth.jwt() ->> 'role' = 'service_role');

CREATE POLICY "Service role full access - case_studies"
    ON case_studies FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role')
    WITH CHECK (auth.jwt() ->> 'role' = 'service_role');

CREATE POLICY "Service role full access - api_usage"
    ON api_usage FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role')
    WITH CHECK (auth.jwt() ->> 'role' = 'service_role');

-- Policy: Allow anon to insert audits (from WordPress plugins)
CREATE POLICY "Allow anon to insert audits"
    ON audits FOR INSERT
    TO anon
    WITH CHECK (true);

-- Policy: Allow anon to insert organizations (WordPress registration)
CREATE POLICY "Allow anon to register organizations"
    ON organizations FOR INSERT
    TO anon
    WITH CHECK (true);

-- Policy: Allow anon to update their own organization
CREATE POLICY "Allow anon to update own organization"
    ON organizations FOR UPDATE
    TO anon
    USING (true)
    WITH CHECK (true);

-- Policy: Allow anon to track API usage
CREATE POLICY "Allow anon to track usage"
    ON api_usage FOR INSERT
    TO anon
    WITH CHECK (true);

-- Policy: Allow anon to read public case studies
CREATE POLICY "Public case studies readable"
    ON case_studies FOR SELECT
    TO anon
    USING (is_public = true);

-- ============================================================
-- SAMPLE DATA (Optional - for testing)
-- ============================================================

-- Sample Case Study
INSERT INTO case_studies (
    title,
    industry,
    problem,
    solution,
    results,
    before_score,
    after_score,
    conversion_lift,
    key_tactics,
    applicable_to,
    is_public
) VALUES (
    'SaaS Homepage Redesign Increased Conversions 47%',
    'SaaS',
    'Homepage had unclear value proposition and weak call-to-action. Visitors bounced quickly without understanding the product benefits.',
    'Simplified messaging with customer-focused benefits, added social proof above the fold, and created a single prominent CTA button.',
    'Conversion rate increased from 2.3% to 3.4% (47% improvement). Time on page increased by 65%. Bounce rate decreased by 23%.',
    65,
    92,
    '47%',
    '["Clear Value Proposition", "Social Proof", "Single CTA", "Benefit-Focused Copy", "Trust Signals"]'::jsonb,
    '["B2B", "SaaS", "Software", "Technology"]'::jsonb,
    true
),
(
    'E-commerce Product Page Optimization',
    'E-commerce',
    'Product pages had low add-to-cart rates. Visitors were not confident in purchase decision due to lack of information and trust signals.',
    'Added detailed product descriptions, customer reviews, size guides, and clear shipping information. Implemented urgency tactics with limited stock indicators.',
    'Add-to-cart rate increased by 34%. Cart abandonment decreased by 18%. Customer confidence score improved significantly.',
    58,
    85,
    '34%',
    '["Customer Reviews", "Detailed Descriptions", "Trust Badges", "Urgency Tactics", "Clear CTAs"]'::jsonb,
    '["E-commerce", "Retail", "Fashion", "Consumer Goods"]'::jsonb,
    true
);

-- ============================================================
-- VERIFICATION QUERIES
-- ============================================================

-- Check all tables were created
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND table_type = 'BASE TABLE'
ORDER BY table_name;

-- Check organizations table structure
SELECT column_name, data_type, is_nullable
FROM information_schema.columns 
WHERE table_name = 'organizations'
ORDER BY ordinal_position;

-- Check that indexes were created
SELECT indexname, tablename 
FROM pg_indexes 
WHERE schemaname = 'public'
ORDER BY tablename, indexname;

-- ============================================================
-- SUCCESS!
-- ============================================================
-- If you see no errors above, your database is ready!
-- Next steps:
-- 1. Create an admin user in Supabase Authentication
-- 2. Configure WordPress plugin with Supabase credentials
-- 3. Test WordPress plugin registration
-- 4. Run an audit and verify it appears in Supabase
-- ============================================================
