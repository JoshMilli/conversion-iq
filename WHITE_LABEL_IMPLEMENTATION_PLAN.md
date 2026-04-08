# Conversion IQ — White-Label SaaS Implementation Plan

## Overview

Transform Conversion IQ from a single-brand WordPress plugin into a fully white-label SaaS platform where agencies can resell under their own branding.

---

## Phase 1: Plugin White-Label Architecture ✅ COMPLETE

### 1.1 Centralized Config Manager
- **File**: `includes/class-config-manager.php`
- Central branding source of truth for the entire plugin
- `get_defaults()` — Webtec fallback values
- `get_branding()` — merges cached remote config over defaults
- `get($key)` — single value accessor
- `get_feature_flags()` — plan-based feature toggles
- `get_logo_html($style)` — handles remote URL, base64, or text fallback
- `sync_from_saas()` — fetches branding from `/api/get-config` endpoint
- `sanitize_branding()` — validates all field types

### 1.2 Report Branding
- **File**: `includes/class-reports.php`
- Replaced hardcoded colors (`$webtec_navy`, `$webtec_blue`, `$webtec_light_blue`) with `$branding['primary_color']`, etc.
- Logo rendered via `ConversionIQ_Config_Manager::get_logo_html()`
- All text references ("Prepared by: Webtec", intro, thank-you footer) now dynamic

### 1.3 Automated Email Branding
- **File**: `includes/class-automated-reports.php`
- Email subject, From/Reply-To headers use branded values
- Email body fully branded (no Webtec/Calendly/WebTec references)
- Footer has `$brand_hide_powered_by` toggle
- Basecamp plain text version also fully branded

### 1.4 Dashboard Branding
- **File**: `admin/dashboard.php` — injects branding into `window.ConversionIQData`
- **File**: `admin/frontend/src/app.tsx` — `B` branding accessor object
  - Header: gradient colors, logo, product name, powered-by toggle
  - FAQ: uses `B.faqItems` if provided, otherwise defaults with `B.company`/`B.product`
  - All `mailto:` links use `B.supportEmail`
  - Suggested functionality modal CTA uses dynamic branding

### 1.5 Main Plugin File
- **File**: `conversion-iq.php`
- Config manager loaded first in include list
- Menu title dynamic via `ConversionIQ_Config_Manager::get('product_name')`
- Daily cron `conversioniq_sync_config` refreshes cached branding
- Hardcoded GitHub token removed — now reads from constant or `wp_option`

### 1.6 Credential Security
- **File**: `includes/class-ai-engine.php`
- API key lookup: `wp-config.php` constant → `wp_options` → hardcoded fallback

---

## Phase 2: Supabase Schema ✅ COMPLETE

### 2.1 Existing Tables (unchanged)
| Table | Purpose |
|-------|---------|
| `api_usage` | API call tracking |
| `audits` | Audit records |
| `case_studies` | Case study content |
| `ciq_customers` | Customer records |
| `ciq_license_validations` | License validation log |
| `ciq_licenses` | License keys & plans |
| `organizations` | Organization records |

### 2.2 New Tables (migration applied)
| Table | Purpose |
|-------|---------|
| `agency_branding` | Custom branding per agency (colors, logo, emails, FAQ, powered-by toggle) |
| `plan_features` | Feature flags per plan (max_sites, custom_branding, client_management, etc.) |

### 2.3 Altered Tables
- `ciq_licenses` — added `plan` column (enum: `starter`, `professional`, `agency`) and `agency_customer_id` (FK to `ciq_customers` for sub-licenses)

### 2.4 Seed Data
- `plan_features` seeded with three plans:
  - **Starter**: 3 sites, no custom branding, no client management
  - **Professional**: 10 sites, custom branding, no client management
  - **Agency**: 100 sites, custom branding, client management enabled

---

## Phase 3: Website / Dashboard (conversioniq-app.com) 🔲 TODO

### 3.1 Authentication & Routing
- Login via email + password (Supabase Auth)
- After login, query `ciq_licenses` for the customer's distinct plans
- Route to appropriate dashboard level:
  - `starter` / `professional` → Standard dashboard
  - `agency` → Agency dashboard (superset of standard)

### 3.2 Dashboard Pages

#### Overview Page
- **Standard view**: license count, active sites, recent audits, usage stats
- **Agency view**: adds client count, total client sites, revenue summary

#### My Licenses Page
- Table: license key (masked), plan, status, associated site URL, activated date
- Actions: copy key, deactivate, view details

#### Branding Page (Professional + Agency only)
- Form fields:
  - Company Name (text)
  - Product Name (text, default "Conversion IQ")
  - Primary Color (color picker)
  - Secondary Color (color picker)
  - Accent Color (color picker)
  - Logo Upload (image, max 2MB)
  - Support Email (email)
  - Website URL (URL)
  - Contact URL (URL)
  - Hide "Powered by" Badge (toggle)
  - Custom FAQ Items (repeater: question + answer pairs)
- Live preview panel showing report header + email mockup
- Saves to `agency_branding` table

#### Client Management Page (Agency only)
- **Client list table**: name, email, license key (masked), plan, status, sites used
- **Create client license**:
  - Client name, client email
  - Allocate from agency's available license pool
  - Auto-generates license key (`CIQ-XXXXX-XXXXX-XXXXX-XXXXX`)
  - Sets `agency_customer_id` on the new license
- **Manage client**: suspend/reactivate, view usage, revoke license

#### Account Settings Page
- Update email, password
- Billing portal link (Stripe Customer Portal)
- Plan upgrade/downgrade

### 3.3 API Endpoints to Build

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/validate-license` | Plugin calls this on activation — expects `{license_key, site_url}`, returns `{valid, customer: {name, email, company, plan}}` |
| GET | `/api/get-config` | Plugin calls this to fetch branding — expects `license_key` query param, returns full branding object from `agency_branding` merged with defaults |
| GET | `/api/dashboard/overview` | Dashboard overview stats |
| GET | `/api/dashboard/licenses` | List customer's licenses |
| POST | `/api/dashboard/branding` | Save branding config |
| GET | `/api/dashboard/branding` | Get current branding config |
| GET | `/api/dashboard/clients` | List agency's client licenses |
| POST | `/api/dashboard/clients` | Create client license |
| PATCH | `/api/dashboard/clients/:id` | Update client license status |
| DELETE | `/api/dashboard/clients/:id` | Revoke client license |

### 3.4 Stripe Integration
- Checkout flow for new purchases (Starter / Professional / Agency plans)
- Webhook handler for: `checkout.session.completed`, `invoice.paid`, `customer.subscription.deleted`
- On purchase: create `ciq_customers` record, generate license key(s), insert into `ciq_licenses`
- Customer portal for self-service billing management

---

## Phase 4: End-to-End Flow

### Standard Customer Flow
1. Purchase plan on conversioniq-app.com → Stripe checkout
2. Receive license key via email
3. Install WordPress plugin → enter license key
4. Plugin calls `/api/validate-license` → activates
5. Plugin calls `/api/get-config` → gets default branding (or agency branding if sub-license)
6. Daily cron refreshes branding config

### Agency Customer Flow
1. Purchase Agency plan → get license key
2. Log into conversioniq-app.com dashboard
3. Go to **Branding** page → customize colors, logo, company name, FAQ
4. Go to **Client Management** → create client licenses
5. Distribute client license keys to clients
6. Client installs plugin → enters license key
7. Plugin calls `/api/validate-license` → activates (linked to agency via `agency_customer_id`)
8. Plugin calls `/api/get-config` → receives **agency's custom branding**
9. Client sees the agency's branding everywhere — reports, emails, dashboard

---

## Technical Stack

| Component | Technology |
|-----------|------------|
| WordPress Plugin | PHP 7.4+, WordPress 6.0+ |
| Plugin Frontend | React 18.2, TypeScript 5.4, Vite 5.2 |
| Website | Next.js (recommended) |
| Database | Supabase (PostgreSQL) |
| Authentication | Supabase Auth |
| Payments | Stripe (Checkout + Webhooks + Customer Portal) |
| License Format | `CIQ-XXXXX-XXXXX-XXXXX-XXXXX` |
| API Base | `https://conversioniq-app.com` |

---

## Key Architecture Decisions

1. **Branding priority**: Remote config (cached) → Defaults (Webtec). No branding data stored in plugin code except fallbacks.
2. **Config caching**: Branding cached in `wp_options` (`conversioniq_remote_config`), refreshed daily via cron. Can also be triggered manually.
3. **Plan detection**: `plan` column lives on `ciq_licenses`. Query distinct plans for a customer to determine dashboard access level.
4. **Agency sub-licenses**: `agency_customer_id` on `ciq_licenses` links a client license back to the agency. The agency's branding is inherited by all sub-licenses.
5. **Credential security**: No hardcoded tokens. Three-tier lookup: `wp-config.php` constant → `wp_options` → fallback.

---

## Status Summary

| Phase | Status |
|-------|--------|
| Plugin white-label code | ✅ Complete |
| Supabase schema migration | ✅ Complete |
| Website / dashboard | 🔲 Not started |
| Stripe integration | 🔲 Not started |
| End-to-end testing | 🔲 Not started |
