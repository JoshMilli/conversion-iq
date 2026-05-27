/**
 * Conversion IQ Design System — Single Source of Truth
 * Matches the branding at https://conversioniq-app.com/
 */

export const T = {
  // ── Backgrounds ──────────────────────────────────────────────────────────
  bgPage:       '#09090b',               // dominant page/hero background
  bgCard:       '#0c0c10',               // cards, modals, elevated surfaces
  bgSubtle:     'rgba(255,255,255,0.03)', // very subtle fills
  bgHover:      'rgba(255,255,255,0.05)', // hover on dark surfaces
  bgInput:      '#0f0f14',               // form inputs

  // ── Primary Accent — Amber ───────────────────────────────────────────────
  primary:          '#f59e0b',                     // amber-500 — CTAs, highlights
  primaryHover:     '#fbbf24',                     // amber-400 — hover state
  primaryDark:      '#d97706',                     // amber-600 — deeper contrast
  primaryBg:        'rgba(245,158,11,0.08)',        // light amber fill
  primaryBgHover:   'rgba(245,158,11,0.12)',        // stronger amber fill on hover
  primaryBorder:    'rgba(245,158,11,0.20)',        // amber card borders

  // ── Secondary Accent — Blue ──────────────────────────────────────────────
  blue:         '#3b82f6',               // blue-500 — secondary accents
  accent:       '#3b82f6',               // alias — interactive CTAs (Connect, Refresh, Next…)

  // ── Text ─────────────────────────────────────────────────────────────────
  textPrimary:   '#fafafa',                   // headlines, bold body
  textSecondary: 'rgba(255,255,255,0.60)',     // nav, body copy
  textMuted:     'rgba(255,255,255,0.40)',     // descriptions, subtle
  textWhisper:   'rgba(255,255,255,0.25)',     // disclaimers, faint labels

  // ── Borders ──────────────────────────────────────────────────────────────
  border:        'rgba(255,255,255,0.06)',     // default card/divider border
  borderMid:     'rgba(255,255,255,0.10)',     // slightly stronger border
  borderAccent:  'rgba(245,158,11,0.20)',      // amber-accented borders

  // ── Status ───────────────────────────────────────────────────────────────
  success:  '#22c55e',   // emerald-500 — good scores, positive states
  warning:  '#f59e0b',   // amber-500   — moderate, caution
  danger:   '#ef4444',   // red-500     — poor scores, errors

  // ── Compound helpers ─────────────────────────────────────────────────────
  /** Amber CTA button gradient */
  btnPrimary:         'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
  btnPrimaryHover:    'linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%)',
  btnPrimaryDisabled: 'rgba(245,158,11,0.30)',
  btnPrimaryText:     '#000000',   // dark text on amber button

  /** Ghost / secondary button */
  btnGhost:     'rgba(255,255,255,0.06)',
  btnGhostHover:'rgba(255,255,255,0.10)',

  /** Dark header gradient (replaces old navy → blue) */
  gradHeader: 'linear-gradient(135deg, #09090b 0%, #0c0c10 100%)',

  /** Amber text gradient — hero titles */
  gradAmberText: 'linear-gradient(135deg, #f59e0b 0%, #fbbf24 50%, #f59e0b 100%)',
} as const;

/** Score colour helper — mirrors the website's scoring palette */
export function scoreColor(score: number): string {
  if (score >= 65) return '#22c55e';
  if (score >= 40) return '#f59e0b';
  return '#ef4444';
}
