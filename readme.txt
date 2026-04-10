=== Conversion IQ ===
Contributors: WebTec
Tags: conversion optimization, AI audit, copy analysis, website scoring, lead intelligence
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.36
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
