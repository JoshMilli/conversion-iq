import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────

interface SeoChecklistItem {
  label: string;
  pass: boolean;
  category: string;
  fix: string;
}

interface SeoAction {
  category: string;
  label: string;
  fix: string;
  priority: 'high' | 'medium' | 'low';
}

interface CwvMetrics {
  lcp_ms: number | null;
  cls: number | null;
  inp_ms: number | null;
  fcp_ms: number | null;
  ttfb_ms: number | null;
  strategy: string;
  sample_size?: number;
}

interface CwvScores {
  lcp?: 'good' | 'needs_improvement' | 'poor';
  cls?: 'good' | 'needs_improvement' | 'poor';
  inp?: 'good' | 'needs_improvement' | 'poor';
  fcp?: 'good' | 'needs_improvement' | 'poor';
  ttfb?: 'good' | 'needs_improvement' | 'poor';
}

interface SeoAuditData {
  page_id: number;
  page_title: string;
  page_url: string;
  overall_score: number;
  category_scores: {
    meta: number;
    headings: number;
    keywords: number;
    images: number;
    links: number;
    schema: number;
    technical: number;
  };
  checklist: SeoChecklistItem[];
  actions: SeoAction[];
  details: {
    meta: Record<string, any>;
    keywords: Record<string, any>;
    images: Record<string, any>;
    links: Record<string, any>;
    schema: Record<string, any>;
    technical: Record<string, any>;
  };
  core_web_vitals: CwvMetrics | null;
  cwv_scores: CwvScores | null;
  analyzed_at: string;
}

interface Page {
  id: number;
  title: string;
  permalink: string;
}

interface SeoTabProps {
  nonce: string;
  apiBase: string;
  pages: Page[];
}

// ── Constants ──────────────────────────────────────────────────────────────

const CATEGORY_LABELS: Record<string, string> = {
  meta:      'Meta Tags',
  headings:  'Headings',
  keywords:  'Keywords',
  images:    'Images',
  links:     'Links',
  schema:    'Structured Data',
  technical: 'Technical',
};

const CATEGORY_WEIGHTS: Record<string, number> = {
  meta: 20, headings: 15, keywords: 15,
  images: 10, links: 10, schema: 10, technical: 20,
};

// ── Helpers ────────────────────────────────────────────────────────────────

function scoreColor(score: number): string {
  if (score >= 80) return '#16a34a';
  if (score >= 60) return '#d97706';
  return '#dc2626';
}

function scoreBg(score: number): string {
  if (score >= 80) return '#f0fdf4';
  if (score >= 60) return '#fffbeb';
  return '#fef2f2';
}

function scoreBorder(score: number): string {
  if (score >= 80) return '#bbf7d0';
  if (score >= 60) return '#fde68a';
  return '#fecaca';
}

function scoreLabel(score: number): string {
  if (score >= 80) return 'Good';
  if (score >= 60) return 'Needs Work';
  return 'Poor';
}

function cwvColor(status?: string): string {
  if (status === 'good') return '#16a34a';
  if (status === 'needs_improvement') return '#d97706';
  if (status === 'poor') return '#dc2626';
  return '#9ca3af';
}

function cwvLabel(status?: string): string {
  if (status === 'good') return 'Good';
  if (status === 'needs_improvement') return 'Improve';
  if (status === 'poor') return 'Poor';
  return '';
}

// ── Sub-components ─────────────────────────────────────────────────────────

function ScoreGauge({ score, size = 72 }: { score: number; size?: number }) {
  const strokeW = size < 50 ? 4 : 5;
  const r = (size / 2) - strokeW - 1;
  const circ = 2 * Math.PI * r;
  const filled = (score / 100) * circ;
  const color = scoreColor(score);
  const fontSize = size >= 70 ? 16 : size >= 48 ? 12 : 10;

  return (
    <svg width={size} height={size} style={{ flexShrink: 0, display: 'block' }}>
      <circle cx={size/2} cy={size/2} r={r} fill="none" stroke="#f1f5f9" strokeWidth={strokeW} />
      <circle cx={size/2} cy={size/2} r={r} fill="none" stroke={color} strokeWidth={strokeW}
        strokeDasharray={`${filled} ${circ - filled}`} strokeLinecap="round"
        transform={`rotate(-90 ${size/2} ${size/2})`} />
      <text x={size/2} y={size/2 + 1} textAnchor="middle" dominantBaseline="middle"
        fontSize={fontSize} fontWeight={700} fill={color} fontFamily="inherit">
        {score}
      </text>
    </svg>
  );
}

function SectionLabel({ text }: { text: string }) {
  return (
    <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.08em',
      textTransform: 'uppercase' as const, color: '#94a3b8', marginBottom: 14 }}>
      {text}
    </div>
  );
}

function Card({ children, style }: { children: React.ReactNode; style?: React.CSSProperties }) {
  return (
    <div style={{ background: '#fff', borderRadius: 10, border: '1px solid #e2e8f0',
      boxShadow: '0 1px 3px rgba(0,0,0,0.04)', ...style }}>
      {children}
    </div>
  );
}

function StatusDot({ pass }: { pass: boolean }) {
  return (
    <span style={{
      display: 'inline-block', width: 8, height: 8, borderRadius: '50%', flexShrink: 0,
      background: pass ? '#16a34a' : '#dc2626',
      marginTop: 3,
    }} />
  );
}

function PriorityTag({ priority }: { priority: string }) {
  const map: Record<string, { color: string; bg: string; border: string }> = {
    high:   { color: '#b91c1c', bg: '#fef2f2', border: '#fecaca' },
    medium: { color: '#92400e', bg: '#fffbeb', border: '#fde68a' },
    low:    { color: '#166534', bg: '#f0fdf4', border: '#bbf7d0' },
  };
  const s = map[priority] || map.low;
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', padding: '2px 8px',
      borderRadius: 99, fontSize: 11, fontWeight: 600, letterSpacing: '0.02em',
      background: s.bg, color: s.color, border: `1px solid ${s.border}` }}>
      {priority.charAt(0).toUpperCase() + priority.slice(1)}
    </span>
  );
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline',
      gap: 12, padding: '6px 0', borderBottom: '1px solid #f8fafc' }}>
      <span style={{ fontSize: 12, color: '#64748b', flexShrink: 0 }}>{label}</span>
      <span style={{ fontSize: 12, fontWeight: 500, color: '#1e293b',
        textAlign: 'right' as const, wordBreak: 'break-word' as const }}>
        {value}
      </span>
    </div>
  );
}

function DetailBlock({ title, items }: { title: string; items: { label: string; value: string }[] }) {
  return (
    <div style={{ padding: '16px 20px', borderRight: '1px solid #f1f5f9' }}>
      <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 10,
        paddingBottom: 8, borderBottom: '2px solid #f1f5f9' }}>{title}</div>
      {items.map(({ label, value }) => (
        <DetailRow key={label} label={label} value={value} />
      ))}
    </div>
  );
}

// ── Main Component ─────────────────────────────────────────────────────────

export default function SeoTab({ nonce, apiBase, pages }: SeoTabProps) {
  const [selectedPageId, setSelectedPageId] = useState<number | null>(
    pages.length > 0 ? pages[0].id : null
  );
  const [loading, setLoading] = useState(false);
  const [cacheLoading, setCacheLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [audit, setAudit] = useState<SeoAuditData | null>(null);
  const [activeCategory, setActiveCategory] = useState<string>('all');

  type SiteAuditRow = { id: number; title: string; url: string; score: number | null; categoryScores: Record<string, number> | null; error: string | null };
  const cancelRef = useRef(false);
  const [siteRunning, setSiteRunning] = useState(false);
  const [siteProgress, setSiteProgress] = useState<{ current: number; total: number; title: string } | null>(null);
  const [siteResults, setSiteResults] = useState<SiteAuditRow[]>([]);
  const [siteDone, setSiteDone] = useState(false);

  const api = (path: string) => apiBase + path;

  const runAudit = async () => {
    if (!selectedPageId) return;
    setLoading(true); setError(null); setAudit(null);
    try {
      const res = await axios.get(api('seo-audit'), {
        params: { page_id: selectedPageId },
        headers: { 'X-WP-Nonce': nonce },
      });
      if (res.data.success) setAudit(res.data.data);
      else setError(res.data.message || 'Analysis failed.');
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Could not reach the server.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setAudit(null);
    setError(null);
    if (!selectedPageId) return;
    let cancelled = false;
    setCacheLoading(true);
    axios.get(api('seo-last'), {
      params: { page_id: selectedPageId },
      headers: { 'X-WP-Nonce': nonce },
    }).then(res => {
      if (!cancelled && res.data.success && res.data.data) {
        setAudit(res.data.data);
      }
    }).catch(() => {
      // silently ignore — user can still run a fresh audit manually
    }).finally(() => {
      if (!cancelled) setCacheLoading(false);
    });
    return () => { cancelled = true; };
  }, [selectedPageId]);

  const runSiteAudit = async () => {
    if (siteRunning || pages.length === 0) return;
    cancelRef.current = false;
    setSiteRunning(true); setSiteDone(false); setSiteResults([]);
    setSiteProgress({ current: 0, total: pages.length, title: pages[0].title });

    for (let i = 0; i < pages.length; i++) {
      if (cancelRef.current) break;
      const page = pages[i];
      setSiteProgress({ current: i + 1, total: pages.length, title: page.title });
      try {
        const res = await axios.get(api('seo-audit'), {
          params: { page_id: page.id },
          headers: { 'X-WP-Nonce': nonce },
        });
        if (res.data.success) {
          const d = res.data.data as SeoAuditData;
          setSiteResults(prev => [...prev, {
            id: page.id, title: page.title, url: d.page_url,
            score: d.overall_score, categoryScores: d.category_scores, error: null,
          }]);
        } else {
          setSiteResults(prev => [...prev, { id: page.id, title: page.title, url: page.permalink, score: null, categoryScores: null, error: res.data.message || 'Failed' }]);
        }
      } catch (e: any) {
        setSiteResults(prev => [...prev, { id: page.id, title: page.title, url: page.permalink, score: null, categoryScores: null, error: e?.response?.data?.message || 'Request failed' }]);
      }
      if (i < pages.length - 1 && !cancelRef.current) await new Promise(r => setTimeout(r, 400));
    }
    setSiteRunning(false); setSiteDone(true); setSiteProgress(null);
  };

  const filteredChecklist = audit
    ? (activeCategory === 'all' ? audit.checklist : audit.checklist.filter(i => i.category === activeCategory))
    : [];

  const failCount = audit ? audit.checklist.filter(i => !i.pass).length : 0;
  const passCount = audit ? audit.checklist.filter(i => i.pass).length : 0;

  // ── Render ──────────────────────────────────────────────────────────────

  return (
    <div style={{ fontFamily: 'inherit', color: '#1e293b' }}>

      {/* ── Top toolbar ── */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>

        {/* Single page audit */}
        <Card style={{ padding: '20px 24px' }}>
          <SectionLabel text="Page Audit" />
          <div style={{ display: 'flex', gap: 10 }}>
            <select
              value={selectedPageId ?? ''}
              onChange={e => setSelectedPageId(Number(e.target.value))}
              style={{ flex: 1, padding: '9px 12px', border: '1px solid #e2e8f0', borderRadius: 7,
                fontSize: 13, color: '#1e293b', background: '#f8fafc', outline: 'none',
                cursor: 'pointer', minWidth: 0 }}>
              <option value="" disabled>Select a page...</option>
              {pages.map(p => <option key={p.id} value={p.id}>{p.title}</option>)}
            </select>
            <button onClick={runAudit} disabled={loading || !selectedPageId}
              style={{ padding: '9px 20px', background: loading ? '#a78bfa' : '#7c3aed',
                color: '#fff', border: 'none', borderRadius: 7, fontSize: 13, fontWeight: 600,
                cursor: loading ? 'not-allowed' : 'pointer', whiteSpace: 'nowrap' as const,
                transition: 'background 0.15s', flexShrink: 0 }}>
              {loading ? 'Analysing...' : 'Run Audit'}
            </button>
          </div>
          {error && (
            <div style={{ marginTop: 10, padding: '8px 12px', background: '#fef2f2',
              border: '1px solid #fecaca', borderRadius: 6, color: '#dc2626', fontSize: 12 }}>
              {error}
            </div>
          )}
        </Card>

        {/* Full site audit */}
        <Card style={{ padding: '20px 24px' }}>
          <SectionLabel text="Full Site Audit" />
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ flex: 1, fontSize: 13, color: '#64748b', lineHeight: 1.5 }}>
              Audit all {pages.length} page{pages.length !== 1 ? 's' : ''} and sync results to your dashboard.
            </div>
            <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
              {siteRunning && (
                <button onClick={() => { cancelRef.current = true; }}
                  style={{ padding: '9px 16px', background: '#fff', color: '#dc2626',
                    border: '1px solid #fecaca', borderRadius: 7, fontSize: 13,
                    fontWeight: 600, cursor: 'pointer' }}>
                  Cancel
                </button>
              )}
              <button onClick={runSiteAudit} disabled={siteRunning || pages.length === 0}
                style={{ padding: '9px 20px', background: siteRunning ? '#a78bfa' : '#7c3aed',
                  color: '#fff', border: 'none', borderRadius: 7, fontSize: 13, fontWeight: 600,
                  cursor: siteRunning || pages.length === 0 ? 'not-allowed' : 'pointer',
                  whiteSpace: 'nowrap' as const, transition: 'background 0.15s' }}>
                {siteRunning ? 'Running...' : siteDone ? 'Re-run' : 'Audit Entire Site'}
              </button>
            </div>
          </div>

          {siteRunning && siteProgress && (
            <div style={{ marginTop: 14 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11,
                color: '#94a3b8', marginBottom: 5 }}>
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' as const,
                  maxWidth: '70%' }}>{siteProgress.title}</span>
                <span>{siteProgress.current} / {siteProgress.total}</span>
              </div>
              <div style={{ height: 4, background: '#f1f5f9', borderRadius: 99, overflow: 'hidden' }}>
                <div style={{ height: '100%', borderRadius: 99, background: '#7c3aed',
                  width: `${Math.round((siteProgress.current / siteProgress.total) * 100)}%`,
                  transition: 'width 0.3s ease' }} />
              </div>
            </div>
          )}
        </Card>
      </div>

      {/* ── Site audit results table ── */}
      {siteResults.length > 0 && (
        <Card style={{ marginBottom: 16, overflow: 'hidden' }}>
          <div style={{ padding: '14px 20px', borderBottom: '1px solid #f1f5f9',
            display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
            <SectionLabel text="Site Audit Results" />
            {siteDone && (
              <div style={{ display: 'flex', gap: 16, fontSize: 12, marginBottom: 14 }}>
                <span style={{ color: '#16a34a', fontWeight: 600 }}>
                  {siteResults.filter(r => r.score !== null && r.score >= 80).length} Good
                </span>
                <span style={{ color: '#d97706', fontWeight: 600 }}>
                  {siteResults.filter(r => r.score !== null && r.score >= 60 && r.score < 80).length} Needs Work
                </span>
                <span style={{ color: '#dc2626', fontWeight: 600 }}>
                  {siteResults.filter(r => r.score !== null && r.score < 60).length} Poor
                </span>
              </div>
            )}
          </div>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ background: '#f8fafc' }}>
                  <th style={{ textAlign: 'left', padding: '9px 20px', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0' }}>Page</th>
                  <th style={{ textAlign: 'center', padding: '9px 12px', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0' }}>Score</th>
                  {Object.entries(CATEGORY_LABELS).map(([cat, label]) => (
                    <th key={cat} style={{ textAlign: 'center', padding: '9px 10px', color: '#94a3b8',
                      fontWeight: 500, fontSize: 11, borderBottom: '1px solid #e2e8f0',
                      whiteSpace: 'nowrap' as const }}>
                      {label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {[...siteResults].sort((a, b) => (a.score ?? -1) - (b.score ?? -1)).map((row, i) => (
                  <tr key={row.id} style={{ borderBottom: '1px solid #f8fafc',
                    background: i % 2 === 0 ? '#fff' : '#fafafa' }}>
                    <td style={{ padding: '9px 20px', maxWidth: 280, overflow: 'hidden',
                      textOverflow: 'ellipsis', whiteSpace: 'nowrap' as const }}>
                      <a href={row.url} target="_blank" rel="noreferrer"
                        style={{ color: '#334155', textDecoration: 'none', fontWeight: 500 }}
                        title={row.url}>{row.title}</a>
                    </td>
                    <td style={{ textAlign: 'center', padding: '9px 12px' }}>
                      {row.error
                        ? <span style={{ color: '#94a3b8', fontSize: 11 }}>Error</span>
                        : row.score !== null
                          ? <span style={{ fontWeight: 700, color: scoreColor(row.score), fontSize: 13 }}>{row.score}</span>
                          : <span style={{ color: '#cbd5e1' }}>-</span>}
                    </td>
                    {Object.keys(CATEGORY_LABELS).map(cat => {
                      const s = row.categoryScores?.[cat] ?? null;
                      return (
                        <td key={cat} style={{ textAlign: 'center', padding: '9px 10px' }}>
                          {s !== null
                            ? <span style={{ fontWeight: 600, color: scoreColor(s), fontSize: 12 }}>{s}</span>
                            : <span style={{ color: '#e2e8f0' }}>-</span>}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {siteDone && (
            <div style={{ padding: '10px 20px', fontSize: 11, color: '#94a3b8', borderTop: '1px solid #f1f5f9' }}>
              All results synced to SaaS dashboard &middot; sorted by lowest score
            </div>
          )}
        </Card>
      )}

      {/* ── Loading state ── */}
      {cacheLoading && !audit && (
        <Card style={{ padding: '14px 20px', marginBottom: 16,
          display: 'flex', alignItems: 'center', gap: 10 }}>
          <div style={{ width: 14, height: 14, border: '2px solid #e2e8f0',
            borderTopColor: '#7c3aed', borderRadius: '50%',
            animation: 'spin 0.8s linear infinite', flexShrink: 0 }} />
          <span style={{ fontSize: 13, color: '#94a3b8' }}>Loading last audit result...</span>
          <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
        </Card>
      )}

      {/* ── Loading state ── */}
      {loading && (
        <Card style={{ padding: 48, textAlign: 'center', marginBottom: 16 }}>
          <div style={{ width: 36, height: 36, border: '3px solid #e2e8f0',
            borderTopColor: '#7c3aed', borderRadius: '50%', margin: '0 auto 16px',
            animation: 'spin 0.8s linear infinite' }} />
          <div style={{ fontSize: 14, fontWeight: 600, color: '#334155' }}>Analysing page...</div>
          <div style={{ fontSize: 12, color: '#94a3b8', marginTop: 4 }}>
            Checking meta tags, headings, keywords, structured data, and Core Web Vitals
          </div>
          <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
        </Card>
      )}

      {/* ── Audit results ── */}
      {audit && !loading && (
        <>
          {/* Last-audited bar */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            padding: '8px 14px', background: '#f8fafc', border: '1px solid #e2e8f0',
            borderRadius: 8, marginBottom: 12, fontSize: 12 }}>
            <span style={{ color: '#64748b' }}>
              Last audited {new Date(audit.analyzed_at).toLocaleString()}
            </span>
            <button onClick={runAudit} disabled={loading}
              style={{ fontSize: 12, fontWeight: 600, color: '#7c3aed', background: 'none',
                border: 'none', cursor: loading ? 'not-allowed' : 'pointer', padding: 0 }}>
              Re-run audit
            </button>
          </div>
          {/* Score overview */}
          <div style={{ display: 'grid', gridTemplateColumns: '188px 1fr', gap: 16, marginBottom: 16 }}>

            {/* Overall score */}
            <Card style={{ padding: 24, display: 'flex', flexDirection: 'column',
              alignItems: 'center', justifyContent: 'center', gap: 10,
              background: scoreBg(audit.overall_score), border: `1px solid ${scoreBorder(audit.overall_score)}` }}>
              <ScoreGauge score={audit.overall_score} size={80} />
              <div style={{ textAlign: 'center' }}>
                <div style={{ fontSize: 13, fontWeight: 700, color: scoreColor(audit.overall_score) }}>
                  {scoreLabel(audit.overall_score)}
                </div>
                <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>Overall SEO Score</div>
                <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 6 }}>
                  {failCount} issue{failCount !== 1 ? 's' : ''} &middot; {passCount} passing
                </div>
              </div>
            </Card>

            {/* Category scores */}
            <Card style={{ padding: 20 }}>
              <SectionLabel text="Category Breakdown" />
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 8 }}>
                {Object.entries(audit.category_scores).map(([cat, score]) => (
                  <div key={cat} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center',
                    gap: 8, padding: '12px 6px', borderRadius: 8,
                    background: '#f8fafc', border: '1px solid #f1f5f9' }}>
                    <ScoreGauge score={score} size={44} />
                    <div style={{ textAlign: 'center' }}>
                      <div style={{ fontSize: 11, fontWeight: 600, color: '#475569', lineHeight: 1.3 }}>
                        {CATEGORY_LABELS[cat]}
                      </div>
                      <div style={{ fontSize: 10, color: '#94a3b8', marginTop: 2 }}>
                        {CATEGORY_WEIGHTS[cat]}% wt
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </Card>
          </div>

          {/* Core Web Vitals */}
          <Card style={{ marginBottom: 16, overflow: 'hidden' }}>
            <div style={{ padding: '14px 20px', borderBottom: '1px solid #f1f5f9',
              display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
              <div>
                <SectionLabel text="Core Web Vitals" />
                <div style={{ fontSize: 12, color: '#94a3b8', marginTop: -8 }}>Real User Metrics - last 30 days</div>
              </div>
              {audit.core_web_vitals?.sample_size != null && (
                <span style={{ fontSize: 11, color: '#94a3b8', background: '#f8fafc',
                  border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 10px', flexShrink: 0 }}>
                  {audit.core_web_vitals.sample_size} session{audit.core_web_vitals.sample_size !== 1 ? 's' : ''}
                </span>
              )}
            </div>

            {!audit.core_web_vitals ? (
              <div style={{ padding: '24px 20px', display: 'flex', alignItems: 'flex-start', gap: 12,
                color: '#64748b', fontSize: 13 }}>
                <div style={{ width: 32, height: 32, borderRadius: 8, background: '#f8fafc',
                  border: '1px solid #e2e8f0', display: 'flex', alignItems: 'center',
                  justifyContent: 'center', flexShrink: 0, fontSize: 14 }}>i</div>
                <div>
                  <div style={{ fontWeight: 600, color: '#334155', marginBottom: 3 }}>No Real User Metrics yet</div>
                  <div style={{ fontSize: 12, color: '#94a3b8', lineHeight: 1.5 }}>
                    Core Web Vitals will appear once the heatmap tracker has collected sessions on this page.
                  </div>
                </div>
              </div>
            ) : (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)' }}>
                {([
                  { key: 'lcp', label: 'LCP', desc: 'Largest Contentful Paint', value: audit.core_web_vitals.lcp_ms != null ? `${audit.core_web_vitals.lcp_ms}ms` : '-', threshold: '< 2,500ms' },
                  { key: 'cls', label: 'CLS', desc: 'Cumulative Layout Shift', value: audit.core_web_vitals.cls != null ? audit.core_web_vitals.cls.toFixed(3) : '-', threshold: '< 0.1' },
                  { key: 'inp', label: 'INP', desc: 'Interaction to Next Paint', value: audit.core_web_vitals.inp_ms != null ? `${audit.core_web_vitals.inp_ms}ms` : '-', threshold: '< 200ms' },
                  { key: 'fcp', label: 'FCP', desc: 'First Contentful Paint', value: audit.core_web_vitals.fcp_ms != null ? `${audit.core_web_vitals.fcp_ms}ms` : '-', threshold: '< 1,800ms' },
                  { key: 'ttfb', label: 'TTFB', desc: 'Time to First Byte', value: audit.core_web_vitals.ttfb_ms != null ? `${audit.core_web_vitals.ttfb_ms}ms` : '-', threshold: '< 800ms' },
                ] as const).map(({ key, label, desc, value, threshold }, i, arr) => {
                  const status = audit.cwv_scores?.[key as keyof CwvScores];
                  const col = cwvColor(status);
                  return (
                    <div key={key} style={{ padding: '18px 20px',
                      borderRight: i < arr.length - 1 ? '1px solid #f1f5f9' : 'none' }}>
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                        marginBottom: 8 }}>
                        <span style={{ fontSize: 12, fontWeight: 700, color: '#334155', letterSpacing: '0.02em' }}>
                          {label}
                        </span>
                        {status && (
                          <span style={{ fontSize: 10, fontWeight: 600, padding: '2px 7px',
                            borderRadius: 99, background: col + '18', color: col,
                            letterSpacing: '0.03em' }}>
                            {cwvLabel(status)}
                          </span>
                        )}
                      </div>
                      <div style={{ fontSize: 22, fontWeight: 700, color: col, letterSpacing: '-0.02em',
                        marginBottom: 6 }}>{value}</div>
                      <div style={{ fontSize: 11, color: '#94a3b8' }}>{desc}</div>
                      <div style={{ fontSize: 10, color: '#cbd5e1', marginTop: 2 }}>Good: {threshold}</div>
                    </div>
                  );
                })}
              </div>
            )}
          </Card>

          {/* Actions + Checklist side by side */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>

            {/* Action items */}
            <Card style={{ overflow: 'hidden' }}>
              <div style={{ padding: '14px 20px', borderBottom: '1px solid #f1f5f9' }}>
                <SectionLabel text="Recommended Actions" />
                <div style={{ fontSize: 12, color: '#94a3b8', marginTop: -8 }}>
                  {audit.actions.length} item{audit.actions.length !== 1 ? 's' : ''}, sorted by priority
                </div>
              </div>
              {audit.actions.length === 0 ? (
                <div style={{ padding: '20px', fontSize: 13, color: '#94a3b8' }}>No actions - great work.</div>
              ) : (
                <div style={{ maxHeight: 380, overflowY: 'auto' }}>
                  {audit.actions.map((action, idx) => (
                    <div key={idx} style={{ padding: '12px 20px', borderBottom: '1px solid #f8fafc',
                      borderLeft: `3px solid ${action.priority === 'high' ? '#dc2626' : action.priority === 'medium' ? '#d97706' : '#16a34a'}` }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 5 }}>
                        <PriorityTag priority={action.priority} />
                        <span style={{ fontSize: 11, color: '#94a3b8' }}>{CATEGORY_LABELS[action.category]}</span>
                      </div>
                      <div style={{ fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 3 }}>
                        {action.label}
                      </div>
                      <div style={{ fontSize: 11, color: '#64748b', lineHeight: 1.5 }}>{action.fix}</div>
                    </div>
                  ))}
                </div>
              )}
            </Card>

            {/* Checklist */}
            <Card style={{ overflow: 'hidden' }}>
              <div style={{ padding: '14px 20px', borderBottom: '1px solid #f1f5f9' }}>
                <SectionLabel text="SEO Checklist" />
                <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' as const, marginTop: 4 }}>
                  {(['all', ...Object.keys(CATEGORY_LABELS)] as string[]).map(cat => (
                    <button key={cat} onClick={() => setActiveCategory(cat)}
                      style={{ padding: '3px 9px', borderRadius: 6, fontSize: 11, fontWeight: 500,
                        border: '1px solid', cursor: 'pointer', transition: 'all 0.1s',
                        borderColor: activeCategory === cat ? '#7c3aed' : '#e2e8f0',
                        background: activeCategory === cat ? '#7c3aed' : '#fff',
                        color: activeCategory === cat ? '#fff' : '#64748b' }}>
                      {cat === 'all' ? 'All' : CATEGORY_LABELS[cat]}
                    </button>
                  ))}
                </div>
              </div>
              <div style={{ maxHeight: 380, overflowY: 'auto' }}>
                {filteredChecklist.length === 0 && (
                  <div style={{ padding: '20px', fontSize: 13, color: '#94a3b8' }}>No items.</div>
                )}
                {filteredChecklist.map((item, idx) => (
                  <div key={idx} style={{ display: 'flex', alignItems: 'flex-start', gap: 10,
                    padding: '10px 20px', borderBottom: '1px solid #f8fafc' }}>
                    <StatusDot pass={item.pass} />
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 12, fontWeight: 500, color: '#334155' }}>{item.label}</div>
                      {!item.pass && item.fix && (
                        <div style={{ fontSize: 11, color: '#64748b', marginTop: 2, lineHeight: 1.5 }}>{item.fix}</div>
                      )}
                    </div>
                    <span style={{ flexShrink: 0, fontSize: 10, fontWeight: 500, color: '#94a3b8',
                      alignSelf: 'flex-start', marginTop: 1, whiteSpace: 'nowrap' as const }}>
                      {CATEGORY_LABELS[item.category]}
                    </span>
                  </div>
                ))}
              </div>
            </Card>
          </div>

          {/* Details panel */}
          <Card style={{ marginBottom: 16, overflow: 'hidden' }}>
            <div style={{ padding: '14px 20px', borderBottom: '1px solid #f1f5f9' }}>
              <SectionLabel text="Page Details" />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)' }}>

              {/* Meta */}
              <DetailBlock title="Meta" items={[
                { label: 'SEO Plugin', value: audit.details.meta.seo_plugin || '-' },
                { label: 'Title', value: audit.details.meta.title || '(none)' },
                { label: 'Title length', value: `${audit.details.meta.title_length || 0} chars` },
                { label: 'Description length', value: `${audit.details.meta.description_length || 0} chars` },
                { label: 'OG Image', value: audit.details.meta.og_image ? 'Set' : 'Missing' },
                { label: 'Canonical', value: audit.details.technical.canonical_set ? 'Set' : 'Missing' },
                { label: 'Robots', value: audit.details.meta.robots || 'default' },
              ]} />

              {/* Keywords */}
              <DetailBlock title="Keywords" items={[
                { label: 'Focus keyword', value: audit.details.keywords.focus_keyword || '(not set)' },
                { label: 'In H1', value: audit.details.keywords.in_h1 ? 'Yes' : 'No' },
                { label: 'In first paragraph', value: audit.details.keywords.in_first_paragraph ? 'Yes' : 'No' },
                { label: 'In meta description', value: audit.details.keywords.in_meta_description ? 'Yes' : 'No' },
                { label: 'In URL slug', value: audit.details.keywords.in_slug ? 'Yes' : 'No' },
                { label: 'Density', value: audit.details.keywords.density_pct != null ? `${audit.details.keywords.density_pct}%` : '-' },
                { label: 'Word count', value: audit.details.keywords.word_count?.toLocaleString() || '-' },
              ]} />

              {/* Images, Links, Schema, Technical */}
              <div style={{ padding: '16px 20px' }}>
                <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 10,
                  paddingBottom: 8, borderBottom: '2px solid #f1f5f9' }}>Images</div>
                <DetailRow label="Total images" value={String(audit.details.images.total)} />
                <DetailRow label="With alt text" value={`${audit.details.images.with_alt} / ${audit.details.images.total}`} />
                <DetailRow label="Alt coverage" value={`${audit.details.images.coverage_pct}%`} />

                <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', margin: '14px 0 10px',
                  paddingBottom: 8, borderBottom: '2px solid #f1f5f9' }}>Links</div>
                <DetailRow label="Internal" value={String(audit.details.links.internal)} />
                <DetailRow label="External" value={String(audit.details.links.external)} />

                <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', margin: '14px 0 10px',
                  paddingBottom: 8, borderBottom: '2px solid #f1f5f9' }}>Structured Data</div>
                <DetailRow label="JSON-LD" value={audit.details.schema.has_json_ld ? 'Present' : 'Missing'} />
                <DetailRow label="Schema types" value={audit.details.schema.types?.length > 0 ? audit.details.schema.types.join(', ') : 'None'} />

                <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', margin: '14px 0 10px',
                  paddingBottom: 8, borderBottom: '2px solid #f1f5f9' }}>Technical</div>
                <DetailRow label="HTTPS" value={audit.details.technical.is_https ? 'Yes' : 'No'} />
                <DetailRow label="Slug" value={`/${audit.details.technical.slug}`} />
                <DetailRow label="Stop words in slug" value={audit.details.technical.slug_has_stop_words ? 'Yes' : 'No'} />
              </div>
            </div>
          </Card>

          {/* Footer */}
          <div style={{ fontSize: 11, color: '#cbd5e1', paddingBottom: 4 }}>
            Analysed {new Date(audit.analyzed_at).toLocaleString()} &middot; {audit.page_url}
          </div>
        </>
      )}

      {/* Empty state */}
      {!audit && !loading && !error && siteResults.length === 0 && (
        <Card style={{ padding: 48, textAlign: 'center' }}>
          <div style={{ width: 44, height: 44, borderRadius: 10, background: '#f3f0ff',
            border: '1px solid #e9d5ff', display: 'flex', alignItems: 'center',
            justifyContent: 'center', margin: '0 auto 16px', fontSize: 20 }}>
            S
          </div>
          <div style={{ fontSize: 15, fontWeight: 700, color: '#334155', marginBottom: 6 }}>
            Select a page and run an audit
          </div>
          <div style={{ fontSize: 13, color: '#94a3b8', maxWidth: 380, margin: '0 auto', lineHeight: 1.6 }}>
            On-page SEO analysis covering meta tags, headings, keywords, images, links,
            structured data, technical factors, and Core Web Vitals from real visitors.
          </div>
        </Card>
      )}
    </div>
  );
}
