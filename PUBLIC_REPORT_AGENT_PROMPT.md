# Agent Prompt: Build Public Report Page — Conversion IQ

## Context

You are building a public-facing report page for a SaaS product called **Conversion IQ** (brand: Conversion IQ by WebTec). When a customer runs a website conversion audit, the plugin pushes all analysis data to a Supabase database and generates a unique `report_token`. The report is shared via a permanent URL like:

```
https://conversioniq-app.com/reports/[report_token]
```

Your job is to build the page that fetches and renders this report. This is a Next.js project using the App Router, Tailwind CSS, and TypeScript.

---

## Tech Stack

- **Framework**: Next.js (App Router — `app/` directory)
- **Styling**: Tailwind CSS
- **Language**: TypeScript
- **Data source**: Supabase (REST or `@supabase/supabase-js` client)
- **Supabase table**: `audits`
- **Route**: `app/reports/[token]/page.tsx`

---

## Supabase Schema — `audits` table

All columns you need to read:

```ts
type AuditRow = {
  id: number
  created_at: string
  organization_id: string
  page_url: string
  page_title: string | null
  industry: string | null
  plan: 'free' | 'starter' | 'pro' | 'business' | 'agency'

  // Six individual scores (0-100)
  clarity_score: number | null
  emotional_score: number | null
  cta_strength: number | null
  readability_score: number | null
  engagement_score: number | null
  trust_score: number | null
  overall_score: number | null

  // JSONB fields — full AI output
  insights: {
    executive_summary: string
    strengths: string[]
    weaknesses: string[]
    opportunities: string[]
    top_priority_insight: string
    audience_alignment: string
  } | null

  recommendations: {
    quick_wins: Array<{
      text: string
      why: string
      impact: string
      difficulty: 'Easy' | 'Medium' | 'Hard'
    }>
    long_term: Array<{
      text: string
      why: string
      impact: string
      difficulty: 'Easy' | 'Medium' | 'Hard'
      timeframe: string
    }>
    priority: {
      text: string
      why: string
      impact: string
      next_steps: string   // format: "1. Step, 2. Step, 3. Step"
    }
  } | null

  benchmark_research: {
    industry_average: number
    top_performers_threshold: number
    competitive_context: string
  } | null

  business_context: {
    industry: string | null
    product: string | null
    audience: string | null
    goal: string | null
    pain_points: string | null
  } | null

  lead_intelligence: {
    insight: string
    recommendations: string[]
  } | null

  suggestions: Array<{
    text: string
    section: string
    why: string
    impact: string
    implementation: string
  }>

  functionality_suggestions: Array<{
    title: string
    category: string
    description: string
    why: string
    impact: string
    implementation: string
    icon: string
  }>

  rewrites: {
    headline?: string
    subheadline?: string
    primary_cta?: string
    secondary_cta?: string
    value_proposition?: string
    social_proof_intro?: string
    feature_1?: string
    feature_2?: string
    feature_3?: string
    feature_4?: string
    feature_5?: string
    faq_answer_1?: string
    faq_answer_2?: string
    faq_answer_3?: string
    closing_statement?: string
  }

  cro_checklist: Array<{
    element: string
    present: boolean
    explanation: string
    priority: 'high' | 'medium' | 'low'
  }> | null

  report_token: string
}
```

---

## Data Fetching

Fetch server-side using the Supabase client. The query must filter by `report_token` only — **no auth required** (public RLS policy is already set on the table for rows where `report_token IS NOT NULL`).

```ts
// app/reports/[token]/page.tsx
import { createClient } from '@supabase/supabase-js'

const supabase = createClient(
  process.env.NEXT_PUBLIC_SUPABASE_URL!,
  process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!
)

export default async function ReportPage({ params }: { params: { token: string } }) {
  const { data, error } = await supabase
    .from('audits')
    .select('*')
    .eq('report_token', params.token)
    .single()

  if (!data || error) {
    notFound()
  }
  // render...
}
```

---

## Page Structure — Sections in Order

Build the page as a single scrolling document with a sticky header/nav. Each section below maps to a visual card or group of cards.

### 0. Page Header (sticky, fixed top)

- Left: Conversion IQ logo + "Conversion Report" label
- Center: Page title (`page_title` or domain extracted from `page_url`)
- Right: Analyzed URL as a truncated link + "Generated [date]" in small text
- Background: white, light shadow, `z-50`

---

### 1. Cover / Hero Section

- Large headline: **"Conversion Report"**
- Subline: page URL (truncated, clickable)
- Industry badge (if `industry` is set)
- Plan badge (e.g. "Free Plan" / "Pro Report") — style: grey pill for free, green pill for paid
- Two stats inline: overall score as a large circular gauge (0–100, color red/amber/green by range), and "Audited [date formatted nicely]"
- Background: dark navy (#0f172a) or brand dark, white text

---

### 2. Score Cards — Performance Analysis

Six metric cards in a responsive 2-col (mobile) / 3-col (tablet) / 6-col (desktop) grid.

**Score names and labels:**
| Field | Display Label |
|---|---|
| `clarity_score` | Message Clarity |
| `emotional_score` | Emotional Pull |
| `cta_strength` | CTA Strength |
| `readability_score` | Readability |
| `engagement_score` | Engagement |
| `trust_score` | Trust Signals |

Each card:
- Icon (use a relevant emoji or Heroicon)
- Label
- Score as a big number `/100`
- A thin horizontal progress bar (color: green ≥70, amber 40–69, red <40)
- One-line description of what the metric measures (see table below)

**Metric descriptions:**
| Metric | Description |
|---|---|
| Message Clarity | How clearly your value proposition is communicated |
| Emotional Pull | How emotionally compelling and relatable your copy is |
| CTA Strength | How effectively your calls-to-action drive action |
| Readability | How easy your content is to read and scan |
| Engagement | How engaging and compelling your page content is |
| Trust Signals | How well your page establishes credibility and trust |

**Lowest card highlight:** Find the card with the lowest score. Give it a soft red border (`border-red-200`) and add a small red badge "Focus here first" in the top-right corner.

**Benchmark bar** (render above the grid if `benchmark_research` is present):
```
[Industry] benchmark: [industry_average]/100 sector avg · [top_performers_threshold]/100 top performers
```
Display as a light grey info bar with an info icon.

---

### 3. Key Insights

Source: `insights` field. If `plan === 'free'`, only show the first 2 sentences of `executive_summary` and gate the rest (see gating rules below).

**Always visible (all plans):**
- Executive summary paragraph
- Top priority insight callout box (accent border, slightly different background)

**Gated on free plan:**
- Strengths list (green checkmarks) — show first 2 items, blur the third
- Weaknesses list (red X's) — show first 2 items, blur the third
- Audience alignment paragraph — blur entirely

---

### 4. Competitive Benchmarks

Source: `benchmark_research`.

Three stat boxes side by side:
1. **Your Score** — `overall_score` / 100
2. **Industry Average** — `industry_average` / 100
3. **Top Performers** — `top_performers_threshold` / 100

Below the boxes: `competitive_context` paragraph (always visible on all plans).

---

### 5. CRO & UX Checklist

Source: `cro_checklist` array.

A 13-item expert checklist evaluating the presence of high-impact conversion elements on the specific page. Render as a responsive grid (2-col mobile, 3-col tablet, 3-col desktop).

**Each checklist item card:**
- Checkbox icon: filled blue ✅ if `present: true`, empty/grey ☐ if `present: false`
- Element name in uppercase (match the visual in the screenshot — all-caps label)
- On hover or click: expand to show `explanation` as a tooltip or inline drawer
- Border: `border-blue-200` when present, `border-red-200` when absent and priority is `high`, `border-gray-200` when absent and priority is `medium` or `low`

**Sorting:** Render items in this order — `present: false` + `priority: 'high'` first, then `present: false` + `priority: 'medium'`, then `present: true`, then `present: false` + `priority: 'low'`.

**Section header:** "CRO & UX Focus" (left-aligned, bold) with "Conversion priorities" as a right-aligned muted label — match the screenshot layout.

**Free plan:** Show first 4 items (after sorting). Gate remaining 9 with blur overlay.
**Starter plan:** Show first 6 items (after sorting). Gate remaining 7 with blur overlay.
**Professional plan:** Show first 8 items (after sorting). Gate remaining 5 with blur overlay.
**Business / Agency plans:** Show all 13 items, no gating.

If `cro_checklist` is null, do not render this section.

---

### 6. Quick Wins

Source: `recommendations.quick_wins`.

**Free plan:** Show the first 2 items fully. Gate items 3–5 with blur overlay.
**Paid plans:** Show all.

Each item card:
- Title/text (bold)
- "Why" line — muted text
- Impact badge (e.g. "Improves CTA Strength")
- Difficulty pill: Easy = green, Medium = amber, Hard = red

---

### 7. Strategic Improvements

Source: `recommendations.long_term`.

**Free plan:** Show the first 2 items fully (including `why` and `impact`). Gate items 3–5 with blur overlay.
**Paid plans:** Show all with full detail including `timeframe`.

---

### 8. Priority Action Plan

Source: `recommendations.priority`.

A highlighted callout card (e.g. amber/yellow left border accent):
- `text` as headline
- `why` paragraph
- `impact` badge
- `next_steps` parsed and rendered as a numbered list (split by `, ` after detecting the `1.` `2.` `3.` pattern)

**Gated entirely on free plan.**

---

### 9. Suggested Copy Rewrites

Source: `rewrites` object.

Display as a table or card list. For each non-empty key:

| Rewrite key | Display label |
|---|---|
| `headline` | Page Headline |
| `subheadline` | Subheadline |
| `primary_cta` | Primary CTA |
| `secondary_cta` | Secondary CTA |
| `value_proposition` | Value Proposition |
| `social_proof_intro` | Social Proof Intro |
| `feature_1` | Feature Highlight 1 |
| `feature_2` | Feature Highlight 2 |
| `feature_3` | Feature Highlight 3 |
| `feature_4` | Feature Highlight 4 |
| `feature_5` | Feature Highlight 5 |
| `faq_answer_1` | FAQ Answer 1 |
| `faq_answer_2` | FAQ Answer 2 |
| `faq_answer_3` | FAQ Answer 3 |
| `closing_statement` | Closing Statement |

Each item: two-column layout — "Label" on left, the new suggested copy on right in a light grey box with a copy-to-clipboard button.

**Free plan:** Show the first 3 real items (no blur). Blur the remaining 12 items with an upgrade overlay stacked behind them.

---

### 10. Functionality Recommendations

Source: `functionality_suggestions` array.

**Free plan:** Show 2 real items. Gate the rest.
**Paid plans:** Show all.

Each item card:
- Icon (`icon` field, which is an emoji)
- Title + category badge
- Description
- Why/impact text
- Implementation hint

---

### 11. Lead Intelligence (Visitor Data)

Source: `lead_intelligence`.

**Always shown as a teaser on free plan** — render a section with blurred placeholder cards and an upgrade CTA. Do not render the real `lead_intelligence` object on free plans.

**Paid plans:** If `lead_intelligence` is not null, render:
- `insight` as a paragraph
- `recommendations` as a bulleted list

If null, render a message: "Visitor intelligence becomes available after your KnockKnock integration captures data for this page."

---

## Gating Rules

The `plan` field on the audit row controls what is visible.

```ts
const isFree = audit.plan === 'free'
const isStarter = audit.plan === 'starter'
const isPro = audit.plan === 'pro'
const isBusiness = audit.plan === 'business'
const isAgency = audit.plan === 'agency'
const isPaid = !isFree

// CRO checklist visible item count by plan
const croChecklistVisibleCount =
  isFree ? 4 :
  isStarter ? 6 :
  isPro ? 8 :
  13  // business + agency see all
```

**Gated sections on free plan** (render a blur overlay with upgrade CTA instead of real content):
- Insights: Strengths (show 2 of 3, blur the third), Weaknesses (show 2 of 3, blur the third), Audience Alignment
- CRO & UX Checklist (Section 5) — tiered by plan: free=4, starter=6, pro=8, business/agency=all 13
- Priority Action Plan (Section 8) — entirely (free plan only)
- Copy Rewrites (Section 9) — show first 3 real, blur remaining 12 (free plan only)
- Functionality Recommendations: show first 2, gate the rest (free plan only)
- Quick Wins: show first 2, gate items 3–5 (free plan only)
- Strategic Improvements: show first 2 fully, gate items 3–5 (free plan only)
- Lead Intelligence (Section 11) — entirely (teaser only, free plan only)

**Gated section visual pattern:**
```tsx
// Wrap gated content in this pattern
<div className="relative">
  <div className="filter blur-sm pointer-events-none select-none" aria-hidden="true">
    {/* fake/placeholder content */}
  </div>
  <div className="absolute inset-0 flex flex-col items-center justify-center bg-white/60 backdrop-blur-sm rounded-lg z-10">
    <LockClosedIcon className="w-6 h-6 text-gray-400 mb-2" />
    <p className="font-semibold text-gray-800 text-sm mb-1">Unlock Full Analysis</p>
    <p className="text-xs text-gray-500 mb-3 text-center">Available on Starter plan and above</p>
    <a
      href="https://trywebtec.com/pricing"
      className="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700"
    >
      Upgrade — from $29/mo
    </a>
  </div>
</div>
```

---

## Score Improvement Projection (Free Plan Only)

After the benchmark section, if `plan === 'free'`, compute and show:

> *"Raising your two lowest-scoring areas to the industry average would move your overall score from [X] to approximately [Y] (+Z points)."*

Compute it client-side:
1. Find the two lowest scores among the six metrics
2. For each of those two, compute the delta: `max(0, industry_average - score)`
3. Apply the same weighting used in the overall score:
   - clarity 20%, emotional 15%, cta 20%, readability 15%, engagement 15%, trust 15%
4. Project new overall = current overall + weighted delta sum
5. Only render if projected > current

---

## Footer / Upgrade CTA

At the bottom of every free-plan report, render a full-width upgrade banner:

- Headline: **"You're seeing a preview of your full report"**
- Body: "Your complete report includes [N] quick wins, [N] strategic improvements, [N] copy rewrites, and full visitor intelligence — all generated specifically for [page title]."
  - Compute counts from: `recommendations.quick_wins.length`, `recommendations.long_term.length`, count of non-empty keys in `rewrites`
- CTA button: "Get Full Access — from $29/mo" → `https://trywebtec.com/pricing`
- Style: dark background (navy), white text, prominent button

Paid plans: render a simple footer with "Powered by Conversion IQ · trywebtec.com".

---

## Branding & Design System

| Token | Value |
|---|---|
| Primary brand color | `#4f46e5` (indigo-600) |
| Dark background | `#0f172a` (slate-900) |
| Card background | `#ffffff` |
| Border | `#e2e8f0` (slate-200) |
| Muted text | `#64748b` (slate-500) |
| Score green | `#22c55e` (green-500) — score ≥ 70 |
| Score amber | `#f59e0b` (amber-500) — score 40–69 |
| Score red | `#ef4444` (red-500) — score < 40 |

Font: Use system font stack or Inter (`next/font/google`).

---

## Score Gauge Component

Build a circular SVG gauge for the overall score:

```tsx
// ScoreGauge.tsx — accepts score (0-100) and size prop
// Color: green ≥70, amber 40-69, red <40
// Show score as large text in center
// Thin arc, ~220° sweep
```

---

## SEO & Metadata

```ts
export async function generateMetadata({ params }) {
  // fetch audit by token
  return {
    title: `Conversion Report — ${audit.page_title ?? audit.page_url}`,
    description: `AI-powered conversion audit for ${audit.page_url}. Overall score: ${audit.overall_score}/100.`,
    robots: 'noindex',   // reports are private by nature
  }
}
```

---

## Error / Not Found

If no audit row matches the token, render a clean "Report not found" page with:
- "This report link may have expired or the token is invalid."
- Link back to `https://trywebtec.com`

---

## Environment Variables Required

```
NEXT_PUBLIC_SUPABASE_URL=https://spefdqiywnihehfhrood.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=<your anon key>
```

---

## File Structure

```
app/
  reports/
    [token]/
      page.tsx         ← server component, fetches data, renders layout
      loading.tsx      ← skeleton loader
      not-found.tsx    ← 404 page
components/
  report/
    ScoreGauge.tsx     ← circular SVG score gauge
    ScoreCard.tsx      ← individual metric card
    GatedBlock.tsx     ← blur + upgrade overlay wrapper
    CROChecklistSection.tsx
    QuickWinsSection.tsx
    RewritesSection.tsx
    BenchmarkSection.tsx
    InsightsSection.tsx
    UpgradeBanner.tsx
```

---

## Summary of What to Build

1. Fetch audit row by `report_token` from Supabase (server-side, no auth)
2. Render a multi-section scrolling report page
3. Apply plan-based gating: free plan blurs/locks content with an upgrade CTA
4. Show all six score cards with colour-coded progress bars
5. Show benchmark comparison boxes
6. Show CRO & UX checklist grid (13 items, sorted by priority, gated after 4 on free)
7. Show insights, quick wins, strategic improvements, copy rewrites, functionality recommendations
8. Score improvement projection on free plan
9. Upgrade banner at the bottom for free plan reports
10. `robots: noindex` on all report pages
