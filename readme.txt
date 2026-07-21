=== Conversion IQ ===
Contributors: WebTec
Tags: conversion optimization, AI audit, copy analysis, website scoring, lead intelligence
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.5.4
License: GPLv2 or later

AI-powered conversion auditing for WordPress. Scores your pages, generates actionable reports, and rewrites copy to convert more visitors.

== Description ==

Conversion IQ analyzes your website pages using GPT-4o and scores them across six weighted metrics:

* **Clarity** (20%) — How quickly visitors understand your value proposition
* **CTA Strength** (20%) — Effectiveness and placement of calls-to-action
* **Emotional Impact** (15%) — Connection and storytelling quality
* **Readability** (15%) — Content structure, scannability, and comprehension
* **Engagement** (15%) — Interactive elements that keep visitors on-page
* **Trust Signals** (15%) — Social proof, testimonials, and credibility indicators

**Key Features:**

* Six-metric weighted scoring with SVG radial gauge
* AI-generated copy rewrites for headlines, CTAs, and key sections
* Multi-page HTML/PDF reports with executive summary, table of contents, benchmarks, and recommendations
* Automated scheduled audits (daily, weekly, monthly)
* Score history tracking with trend visualization
* Lead intelligence integration via KnockKnock (visitor identification, company data, geographic insights)
* White-label support — customize branding, colors, logo, and company name
* Plan-based feature gating (Starter, Professional, Agency)
* Supabase cloud sync for cross-site dashboard
* REST API with rate limiting and input validation

== Installation ==

1. Upload `conversion-iq.zip` via Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Navigate to **Conversion IQ** in the WordPress admin menu.
4. Enter your license key on the Settings tab.
5. Configure your business context (industry, audience, goals) for more accurate audits.
6. Run your first audit from the Audits tab.

== Frequently Asked Questions ==

= What AI model does this use? =
Conversion IQ uses GPT-4o via a managed API proxy for consistent, high-quality scoring.

= How are scores calculated? =
Each metric is scored 0-100 by the AI using a calibrated rubric with anchor examples. The overall score is a weighted average, not a simple mean.

= Can I white-label the reports? =
Yes. On the Professional and Agency plans you can customize the company name, logo, accent colors, and contact details shown in reports.

= Does it work with page builders? =
Yes. Conversion IQ extracts rendered content from any published page regardless of how it was built (Elementor, Divi, Gutenberg, etc.).

== Changelog ==

= 2.5.4 =
* Fix: Critical error ("There has been a critical error on this website") when running an audit on plans with KnockKnock enabled (e.g. Business/Agency). The KnockKnock class could be referenced before it was loaded when the plan/feature flags changed mid-request. It's now guarded with class_exists() so the audit runs safely and simply omits visitor-intelligence data if the module isn't loaded for that request.

= 2.5.3 =
* Fix: The weekly audit limit was capped at 3/week for every plan — including Business and Agency. Per-plan limits are now graduated: Free 3, Starter 10, Professional 25, Business and Agency unlimited.
* Fix: Corrected the limit message to describe the rolling 7-day window accurately (it no longer implies a fixed reset date).

= 2.5.2 =
* Fix: GA4 property list now paginates through all Google Analytics accounts/properties, so newly created or additional properties are no longer missed in Traffic Intelligence setup.
* Fix: Google Analytics fetch errors are now surfaced during setup (previously only Search Console errors showed), plus a "Refresh list" button re-pulls the latest properties without reconnecting.
* Improvement: Connect either Search Console OR GA4 — both are no longer required. Step 2 (GA4) can be skipped and setup still saves, and a Search-Console-only setup no longer re-launches the wizard.

= 2.5.1 =
* Improvement: Self-hosted update channel — the plugin now checks a JSON update-info endpoint on our own server (instead of GitHub) and reports available versions through WordPress's standard update system, so WP core and tools like WP Umbrella can detect and auto-install new versions.
* Improvement: Update checks are cached for ~12 hours, read the installed version dynamically from the plugin header, send the site's license key + domain for authorisation, and fail silently if the endpoint is unreachable.

= 2.5.0 =
* Feature: Implementation Reviews — approved copy changes are applied to the existing page IN PLACE; the permalink, post ID, and URL never change (no more cloned "optimized" page).
* Feature: Elementor support — headline, CTA, and body-copy changes are applied by editing the page's _elementor_data widget tree; Gutenberg and classic editors are supported too.
* Feature: Shareable draft preview — the apply step stages edits on the same post and returns a tokenised preview link that renders for logged-in admins AND logged-out clients (no WordPress login required), on the live permalink.
* Feature: Publish in place — publishing writes the approved copy to the live post at the same URL and clears the Elementor cache so the new text renders immediately.
* Improvement: Exact-match application — a change is applied only when its original text is found exactly; unmatched changes are reported (before_not_found) instead of guessed, and a post-apply QA step verifies the new copy is present.
* Improvement: Copy audit completeness — audits are built from a deterministic top-of-page inventory (hero + the next 5 sections) so the hero heading, sub-heading, and CTA and the sections directly below them are never skipped.
* Improvement: Localised rewrites — suggested copy is written in the page's own language.
* Improvement: Implementation reviews are built 1:1 from the latest audit's suggestions (verbatim), with builder-aware content extraction for Elementor pages.

= 2.4.0 =
* Feature: Conversion tracking — server-side hooks for Contact Form 7, Gravity Forms, WPForms, Ninja Forms, Formidable Forms, and Fluent Forms record conversions locally and push daily to Supabase via the SaaS proxy (conversioniq-app.com/api/conversions/sync); zero client setup required
* Feature: Conversion Goals — user-defined goals for thank-you page visits, phone/email link clicks, CSS-selector element clicks, Calendly booking confirmations, and external link clicks; managed via new Traffic Intelligence UI panel
* Feature: Conversion Goals UI — "Conversion Goals" panel added to Traffic Intelligence dashboard tab; supports add, label, and delete with per-goal type hints and live validation
* Feature: Client-side conversion fallback — ciq-heatmap-tracker.js extended with a goal-matching engine that reads injected goals from ciqTrackerConfig.convGoals and fires POST /conversioniq/v1/track-conversion via sendBeacon (no CORS issues, non-blocking); also fires on native form submits not covered by server-side hooks
* Feature: Non-PII proof capture — form callbacks extract field labels and field count (never field values) as proof data per conversion event; stored in the events jsonb column in Supabase
* Feature: New REST endpoints — POST /conversioniq/v1/track-conversion (public JS fallback), GET/POST /conversioniq/v1/conversion-goals (admin-only goals CRUD)
* Improvement: restBase added to ciqTrackerConfig so front-end JS can construct REST URLs without hardcoding the path
* Improvement: SEO analyzer — H1 word count tracked; checklist warns when H1 exceeds 10 words and explains the H1 (short, keyword-focused) + H2 (persuasive headline) hero pattern

= 2.3.1 =
* Improvement: GA4 property wizard — search/filter bar and scrollable list added (matches GSC step); filters by property name, account name, or property ID

= 2.3.0 =
* Feature: Traffic Intelligence — Supabase sync now routes through SaaS proxy (conversioniq-app.com/api/traffic/sync-snapshot) to bypass RLS; supports daily snapshot accumulation keyed by org_id + source + snapshot_date for trend tracking on the SaaS dashboard
* Feature: Traffic Intelligence — snapshots now include ga4_property_id and gsc_property so the SaaS dashboard can isolate data by connected account and handle property switches cleanly
* Feature: Debug Logs — new Traffic Intelligence Sync Tester panel with force-refresh button that bypasses the 1-hour rate limit and returns GA4/GSC fetch results and Supabase push status
* Feature: new /traffic-debug-sync REST endpoint (manage_options only) that clears transients and runs the same pipeline as the daily cron
* Improvement: GSC property wizard — search/filter bar, scrollable list (max 320px), and Skip GSC button added so agencies managing many client accounts can navigate the list quickly
* Fix: T.accent colour token was undefined in theme.ts causing blue CTA buttons (Refresh, Connect, Next, Save) to render with a white background and invisible white text on dark theme
* Fix: get_property_id() public accessor added to ConversionIQ_Google_Analytics

= 2.1.0 =
* Fix: device/browser data (device_type, browser, screen_w/h, pixel_ratio) was not reaching Supabase — the enrichment sync SELECT included time_on_page_sec, referrer, utm_source, utm_medium, utm_campaign but those columns were missing from existing installations (only in ALTER TABLE, not in CREATE TABLE SQL), causing MySQL to return a column-not-found error and silently send 0 sessions to the SaaS server
* Fix: added time_on_page_sec, referrer, utm_source, utm_medium, utm_campaign to the CREATE TABLE SQL for conversioniq_heatmap_sessions so dbDelta() adds them to existing tables when create_tables() runs
* Fix: version bump to 2.1.0 triggers the create_tables() schema migration automatically on the next admin page load for all existing installations
* Improvement: enrichment sync now logs $wpdb->last_error alongside the sessions-found count so SQL failures are visible in the debug log rather than silently showing "found = 0"
* Improvement: above-fold CRO checklist now validates against real browser data — measureAboveFold() in JS tracker expanded from 4 to 9 element types (added nav_cta, trust_badge, testimonial, pricing, progress)
* Improvement: conversioniq_extract_html_structure() queries the last 30 real browser sessions for the page URL and appends a [BROWSER-CONFIRMED] block with per-element presence percentages to the AI context
* Improvement: AI engine prompt updated to treat [BROWSER-CONFIRMED] signals as primary evidence for visual CRO checks (H1, CTA, hero image, nav CTA, trust badges, testimonials, pricing, progress indicators), falling back to HTML-only analysis when no browser data exists

= 2.0.83 =
* Fix: page-type detection now correctly identifies About, Services, Contact, FAQ, and Pricing pages — previously all pages with a trailing slash matched as Homepage, causing identical scores across all page types
* Improvement: above-fold CTA detection strips <head>, <script>, and <style> blocks before sampling hero markup, expanded window from 4,000 to 5,000 chars
* Improvement: HTML entity decode (html_entity_decode ENT_QUOTES|ENT_HTML5) and whitespace normalisation applied after wp_strip_all_tags in all three audit pipelines (main loop, scheduled loop, automated reports)
* Improvement: content hash now includes first 2,000 chars of raw HTML so CSS/theme changes trigger a fresh screenshot, not just post_content changes
* Improvement: page_type detected for each audit and synced to Supabase audits table (Phase 2 PATCH) — requires ALTER TABLE audits ADD COLUMN IF NOT EXISTS page_type text in Supabase
* Improvement: plugin_version and organization_id now included in every sync_from_saas() call so SaaS backend stays current on daily cron, not only at activation
* Fix: Supabase Phase 1 INSERT no longer breaks on unknown columns — page_type moved to Phase 2 PATCH to avoid schema-cache errors on sites not yet migrated

= 2.0.38 =
* License tab: Active Sites management panel — view all sites using the license, remove individual site slots
* License tab: Deactivate this site button releases the slot on the licensing server
* New REST endpoints: /license/sites, /license/deactivate, /license/remove-site
* Fix: Guess Fields button now uses provisioned API key instead of hardcoded key
* Fix: Guess Fields button disabled with lock notice when license is not active

= 2.0.37 =
* Fix Guess Fields — use provisioned API key, gate on license status

= 2.0.36 =
* Redesigned Quick Wins, Strategic Improvements, and Priority Recommendation cards in audit modal
* Replaced emoji section headers with clean labeled sections and status pills
* Features & Functionality tab redesigned: monogram badges, inline Why text, consolidated CTA footer
* Fixed API key provisioning via license activation and config sync
* TOC redesigned as 2-column card grid with gradient numbered badges
* Bumped CONVERSION_IQ_VERSION constant to stay in sync with plugin header

= 2.0.35 =
* Weighted overall score calculation (replaces simple average)
* SVG radial score gauge on executive summary
* Dynamic table of contents in reports
* Per-metric score interpretation with band-based descriptions
* Added benchmark_research to AI output schema
* Replaced all error_log() with ciq_log() for cleaner debugging
* Fixed double-paren syntax bug in rest-api.php
* Removed obsolete docs, test files, and backups

= 2.0.33 =
* Rate limiting on audit endpoint
* Input validation for page IDs
* Cached score-history endpoint
* Component splitting (OverviewTab, FaqTab, types)
* Consistent toast auto-dismiss
* Per-section loading states
* Uninstall cleanup

= 2.0.30 =
* AI model upgrade to GPT-4o with calibrated scoring rubric
* Server-side overall_score computation
* Split prompt architecture (system + user)

= 2.0.25 =
* Overview tab with score history charts
* White-label config manager
* Plan-based feature gating
