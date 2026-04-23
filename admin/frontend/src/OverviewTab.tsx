import React from 'react';
import type { Audit, Page } from './types';

interface OverviewTabProps {
  scoreHistory: any[];
  overviewPageFilter: string;
  setOverviewPageFilter: (v: string) => void;
  pages: Page[];
  audits: Audit[];
  setActiveTab: (tab: string) => void;
}

export default function OverviewTab({ scoreHistory, overviewPageFilter, setOverviewPageFilter, pages, audits, setActiveTab }: OverviewTabProps) {
  const uniquePages = Array.from(new Map(scoreHistory.map((h: any) => [h.page_id, h.page_title])).entries());
  const filtered = overviewPageFilter === 'all' ? scoreHistory : scoreHistory.filter((h: any) => String(h.page_id) === overviewPageFilter);

  const latestByPage = new Map<number, any>();
  const previousByPage = new Map<number, any>();
  scoreHistory.forEach((h: any) => {
    const existing = latestByPage.get(h.page_id);
    if (!existing || new Date(h.created_at) > new Date(existing.created_at)) {
      if (existing) previousByPage.set(h.page_id, existing);
      latestByPage.set(h.page_id, h);
    } else {
      const prev = previousByPage.get(h.page_id);
      if (!prev || new Date(h.created_at) > new Date(prev.created_at)) {
        previousByPage.set(h.page_id, h);
      }
    }
  });

  const latestAudits = Array.from(latestByPage.values());
  const avgScore = latestAudits.length > 0 ? Math.round(latestAudits.reduce((s: number, a: any) => s + (a.overall_score || 0), 0) / latestAudits.length) : 0;

  let totalDelta = 0;
  let deltaCount = 0;
  latestByPage.forEach((latest, pageId) => {
    const prev = previousByPage.get(pageId);
    if (prev && latest.overall_score && prev.overall_score) {
      totalDelta += latest.overall_score - prev.overall_score;
      deltaCount++;
    }
  });
  const avgDelta = deltaCount > 0 ? Math.round(totalDelta / deltaCount) : 0;

  const cats = ['clarity_score', 'emotional_score', 'cta_strength', 'readability_score', 'engagement_score', 'trust_score'] as const;
  const catLabels: Record<string, string> = { clarity_score: 'Clarity', emotional_score: 'Emotional', cta_strength: 'CTA Strength', readability_score: 'Readability', engagement_score: 'Engagement', trust_score: 'Trust' };
  const catColors: Record<string, string> = { clarity_score: '#2563eb', emotional_score: '#f59e0b', cta_strength: '#10b981', readability_score: '#9333ea', engagement_score: '#d97706', trust_score: '#0891b2' };
  const catAvgs = cats.map(c => {
    const vals = latestAudits.filter((a: any) => a[c] != null).map((a: any) => a[c] as number);
    const avg = vals.length > 0 ? Math.round(vals.reduce((s, v) => s + v, 0) / vals.length) : 0;
    let catDelta = 0;
    let catDeltaCount = 0;
    latestByPage.forEach((latest, pageId) => {
      const prev = previousByPage.get(pageId);
      if (prev && latest[c] != null && prev[c] != null) {
        catDelta += latest[c] - prev[c];
        catDeltaCount++;
      }
    });
    return { key: c, label: catLabels[c], color: catColors[c], avg, delta: catDeltaCount > 0 ? Math.round(catDelta / catDeltaCount) : 0 };
  });

  const chartData: { date: string; label: string; score: number }[] = [];
  const byDate = new Map<string, number[]>();
  filtered.forEach((h: any) => {
    const d = h.created_at.substring(0, 10);
    if (!byDate.has(d)) byDate.set(d, []);
    byDate.get(d)!.push(h.overall_score || 0);
  });
  byDate.forEach((scores, date) => {
    const avg = Math.round(scores.reduce((a, b) => a + b, 0) / scores.length);
    chartData.push({ date, label: new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' }), score: avg });
  });

  const W = 800, H = 280, PAD = { t: 20, r: 30, b: 40, l: 45 };
  const plotW = W - PAD.l - PAD.r, plotH = H - PAD.t - PAD.b;
  const minScore = chartData.length ? Math.max(0, Math.min(...chartData.map(d => d.score)) - 10) : 0;
  const maxScore = chartData.length ? Math.min(100, Math.max(...chartData.map(d => d.score)) + 10) : 100;
  const range = maxScore - minScore || 1;

  const toX = (i: number) => PAD.l + (chartData.length > 1 ? (i / (chartData.length - 1)) * plotW : plotW / 2);
  const toY = (v: number) => PAD.t + plotH - ((v - minScore) / range) * plotH;

  const linePath = chartData.map((d, i) => `${i === 0 ? 'M' : 'L'}${toX(i).toFixed(1)},${toY(d.score).toFixed(1)}`).join(' ');
  const areaPath = chartData.length > 1 ? linePath + ` L${toX(chartData.length - 1).toFixed(1)},${(PAD.t + plotH).toFixed(1)} L${toX(0).toFixed(1)},${(PAD.t + plotH).toFixed(1)} Z` : '';

  const yTicks: number[] = [];
  const step = range <= 30 ? 5 : range <= 60 ? 10 : 20;
  for (let v = Math.ceil(minScore / step) * step; v <= maxScore; v += step) yTicks.push(v);

  const pagesRanked = [...latestAudits].sort((a: any, b: any) => (a.overall_score || 0) - (b.overall_score || 0));

  const scoreColor = (s: number) => s >= 80 ? '#10b981' : s >= 60 ? '#f59e0b' : '#ef4444';
  const scoreLabel = (s: number) => s >= 80 ? 'Great' : s >= 60 ? 'Needs Work' : 'Critical';

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      {/* Summary Cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16 }}>
        {[
          { label: 'Avg Score', value: avgScore, icon: '\u{1F4CA}', sub: avgScore >= 80 ? 'Great' : avgScore >= 60 ? 'Good' : 'Needs Work', gradient: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', white: true },
          { label: 'Pages Audited', value: latestByPage.size, icon: '\u{1F4C4}', sub: `of ${pages.length} pages`, gradient: 'linear-gradient(135deg, #fff 0%, #f9fafb 100%)', white: false },
          { label: 'Total Audits', value: scoreHistory.length, icon: '\u{1F50D}', sub: 'all time', gradient: 'linear-gradient(135deg, #fff 0%, #f9fafb 100%)', white: false },
          { label: 'Trend', value: `${avgDelta >= 0 ? '+' : ''}${avgDelta}`, icon: avgDelta >= 0 ? '\u{1F4C8}' : '\u{1F4C9}', sub: 'pts since last audit', gradient: avgDelta >= 0 ? 'linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%)' : 'linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%)', white: false }
        ].map((card, i) => (
          <div key={i} style={{ background: card.gradient, borderRadius: 16, padding: '24px 20px', border: card.white ? 'none' : '1px solid #e5e7eb', boxShadow: '0 2px 12px rgba(0,0,0,0.06)', transition: 'transform 0.2s' }} onMouseEnter={e => e.currentTarget.style.transform = 'translateY(-2px)'} onMouseLeave={e => e.currentTarget.style.transform = 'translateY(0)'}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
              <span style={{ fontSize: 13, fontWeight: 600, color: card.white ? 'rgba(255,255,255,0.8)' : '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{card.label}</span>
              <span style={{ fontSize: 20 }}>{card.icon}</span>
            </div>
            <div style={{ fontSize: 36, fontWeight: 800, color: card.white ? '#fff' : '#111827', lineHeight: 1 }}>{card.value}</div>
            <div style={{ fontSize: 13, color: card.white ? 'rgba(255,255,255,0.7)' : '#9ca3af', marginTop: 6 }}>{card.sub}</div>
          </div>
        ))}
      </div>

      {/* Score History Chart */}
      <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 2px 12px rgba(0,0,0,0.06)', border: '1px solid #e5e7eb', padding: 32 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
          <div>
            <h2 style={{ margin: 0, fontSize: 20, fontWeight: 700, color: '#111827' }}>Score History</h2>
            <p style={{ margin: '4px 0 0', fontSize: 14, color: '#6b7280' }}>Track your conversion scores over time</p>
          </div>
          <select
            value={overviewPageFilter}
            onChange={e => setOverviewPageFilter(e.target.value)}
            style={{ padding: '10px 16px', borderRadius: 10, border: '1px solid #d1d5db', fontSize: 14, color: '#374151', cursor: 'pointer', background: '#f9fafb', outline: 'none', minWidth: 180 }}
          >
            <option value="all">All Pages</option>
            {uniquePages.map(([id, title]) => (
              <option key={id} value={String(id)}>{title as string}</option>
            ))}
          </select>
        </div>
        {chartData.length < 2 ? (
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '60px 20px', background: 'linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%)', borderRadius: 12 }}>
            <div style={{ fontSize: 48, marginBottom: 16 }}>📈</div>
            <div style={{ fontSize: 18, fontWeight: 600, color: '#374151', marginBottom: 8 }}>Not enough data yet</div>
            <div style={{ fontSize: 14, color: '#6b7280', textAlign: 'center', maxWidth: 400 }}>Run at least 2 audits to see your score trend. Go to the Audits tab to analyze your pages!</div>
            <button onClick={() => setActiveTab('audits')} style={{ marginTop: 20, padding: '10px 24px', background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', border: 'none', borderRadius: 10, fontSize: 14, fontWeight: 600, cursor: 'pointer', boxShadow: '0 4px 12px rgba(124,58,237,0.25)' }}>Run Your First Audit</button>
          </div>
        ) : (
          <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', height: 'auto', maxHeight: 300 }}>
            <defs>
              <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#7c3aed" stopOpacity="0.2" />
                <stop offset="100%" stopColor="#7c3aed" stopOpacity="0.02" />
              </linearGradient>
              <filter id="glow">
                <feGaussianBlur stdDeviation="2" result="blur" />
                <feMerge><feMergeNode in="blur" /><feMergeNode in="SourceGraphic" /></feMerge>
              </filter>
            </defs>
            {yTicks.map(v => (
              <g key={v}>
                <line x1={PAD.l} y1={toY(v)} x2={W - PAD.r} y2={toY(v)} stroke="#f3f4f6" strokeWidth="1" />
                <text x={PAD.l - 8} y={toY(v) + 4} textAnchor="end" fill="#9ca3af" fontSize="11" fontFamily="Inter,system-ui,sans-serif">{v}</text>
              </g>
            ))}
            {areaPath && <path d={areaPath} fill="url(#areaGrad)" />}
            <path d={linePath} fill="none" stroke="#7c3aed" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" filter="url(#glow)" />
            {chartData.map((d, i) => (
              <g key={i}>
                <circle cx={toX(i)} cy={toY(d.score)} r="5" fill="#fff" stroke="#7c3aed" strokeWidth="2.5" />
                <text x={toX(i)} y={toY(d.score) - 12} textAnchor="middle" fill="#7c3aed" fontSize="12" fontWeight="700" fontFamily="Inter,system-ui,sans-serif">{d.score}</text>
                {(chartData.length <= 12 || i % Math.ceil(chartData.length / 12) === 0) && (
                  <text x={toX(i)} y={H - 8} textAnchor="middle" fill="#9ca3af" fontSize="11" fontFamily="Inter,system-ui,sans-serif">{d.label}</text>
                )}
              </g>
            ))}
          </svg>
        )}
      </section>

      {/* Score Breakdown by Category */}
      <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 2px 12px rgba(0,0,0,0.06)', border: '1px solid #e5e7eb', padding: 32 }}>
        <h2 style={{ margin: '0 0 4px', fontSize: 20, fontWeight: 700, color: '#111827' }}>Score Breakdown</h2>
        <p style={{ margin: '0 0 24px', fontSize: 14, color: '#6b7280' }}>Average across all audited pages</p>
        {latestAudits.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '40px 20px', background: '#f9fafb', borderRadius: 12, color: '#9ca3af' }}>No audit data yet</div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {catAvgs.map(c => (
              <div key={c.key} style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                <div style={{ width: 100, fontSize: 14, fontWeight: 600, color: '#374151', flexShrink: 0 }}>{c.label}</div>
                <div style={{ flex: 1, position: 'relative', height: 32, background: '#f3f4f6', borderRadius: 16, overflow: 'hidden' }}>
                  <div style={{
                    height: '100%',
                    width: `${c.avg}%`,
                    background: `linear-gradient(90deg, ${c.color}88 0%, ${c.color} 100%)`,
                    borderRadius: 16,
                    transition: 'width 1s ease-out',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'flex-end',
                    paddingRight: c.avg > 15 ? 12 : 0,
                    minWidth: c.avg > 0 ? 32 : 0
                  }}>
                    {c.avg > 15 && <span style={{ fontSize: 13, fontWeight: 700, color: '#fff' }}>{c.avg}</span>}
                  </div>
                  {c.avg <= 15 && <span style={{ position: 'absolute', left: `${c.avg}%`, top: '50%', transform: 'translate(8px, -50%)', fontSize: 13, fontWeight: 700, color: c.color }}>{c.avg}</span>}
                </div>
                <div style={{ width: 60, textAlign: 'right', fontSize: 13, fontWeight: 600, color: c.delta > 0 ? '#10b981' : c.delta < 0 ? '#ef4444' : '#9ca3af', flexShrink: 0 }}>
                  {c.delta !== 0 ? `${c.delta > 0 ? '\u2191' : '\u2193'} ${Math.abs(c.delta)}` : '\u2014'}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Pages Needing Attention */}
      <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 2px 12px rgba(0,0,0,0.06)', border: '1px solid #e5e7eb', padding: 32 }}>
        <h2 style={{ margin: '0 0 4px', fontSize: 20, fontWeight: 700, color: '#111827' }}>Page Performance</h2>
        <p style={{ margin: '0 0 24px', fontSize: 14, color: '#6b7280' }}>Your pages ranked by conversion score — lowest first</p>
        {pagesRanked.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '40px 20px', background: '#f9fafb', borderRadius: 12, color: '#9ca3af' }}>No pages audited yet. Run an audit to see results here.</div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 1, borderRadius: 12, overflow: 'hidden', border: '1px solid #e5e7eb' }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 80px 80px 100px 140px', gap: 0, padding: '12px 20px', background: '#f9fafb', borderBottom: '1px solid #e5e7eb' }}>
              <span style={{ fontSize: 12, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Page</span>
              <span style={{ fontSize: 12, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', textAlign: 'center' }}>Score</span>
              <span style={{ fontSize: 12, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', textAlign: 'center' }}>Change</span>
              <span style={{ fontSize: 12, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', textAlign: 'center' }}>Status</span>
              <span style={{ fontSize: 12, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em', textAlign: 'right' }}>Action</span>
            </div>
            {pagesRanked.map((p: any, i: number) => {
              const prev = previousByPage.get(p.page_id);
              const delta = prev ? (p.overall_score || 0) - (prev.overall_score || 0) : null;
              const sc = p.overall_score || 0;
              return (
                <div key={p.page_id} style={{ display: 'grid', gridTemplateColumns: '1fr 80px 80px 100px 140px', gap: 0, padding: '16px 20px', background: i % 2 === 0 ? '#fff' : '#fafafa', alignItems: 'center', transition: 'background 0.15s' }} onMouseEnter={e => e.currentTarget.style.background = '#f5f3ff'} onMouseLeave={e => e.currentTarget.style.background = i % 2 === 0 ? '#fff' : '#fafafa'}>
                  <div>
                    <div style={{ fontSize: 14, fontWeight: 600, color: '#111827' }}>{p.page_title}</div>
                    <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 2 }}>
                      {new Date(p.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                    </div>
                  </div>
                  <div style={{ textAlign: 'center' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 44, height: 44, borderRadius: '50%', background: `${scoreColor(sc)}15`, color: scoreColor(sc), fontSize: 16, fontWeight: 800 }}>{sc}</span>
                  </div>
                  <div style={{ textAlign: 'center', fontSize: 14, fontWeight: 700, color: delta !== null ? (delta > 0 ? '#10b981' : delta < 0 ? '#ef4444' : '#9ca3af') : '#d1d5db' }}>
                    {delta !== null ? `${delta > 0 ? '+' : ''}${delta}` : '\u2014'}
                  </div>
                  <div style={{ textAlign: 'center' }}>
                    <span style={{ display: 'inline-block', padding: '4px 12px', borderRadius: 20, fontSize: 12, fontWeight: 600, background: `${scoreColor(sc)}15`, color: scoreColor(sc) }}>{scoreLabel(sc)}</span>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <button
                      onClick={() => {
                        const fullAudit = audits.find((a: any) => a.page_id === p.page_id);
                        if (fullAudit?.report_token) {
                          window.open(`https://conversioniq-app.com/reports/${fullAudit.report_token}`, '_blank', 'noopener,noreferrer');
                        } else {
                          setActiveTab('audits');
                        }
                      }}
                      style={{ padding: '8px 16px', background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', border: 'none', borderRadius: 8, fontSize: 13, fontWeight: 600, cursor: 'pointer', transition: 'transform 0.2s, box-shadow 0.2s', boxShadow: '0 2px 8px rgba(124,58,237,0.2)' }}
                      onMouseEnter={e => { e.currentTarget.style.transform = 'translateY(-1px)'; e.currentTarget.style.boxShadow = '0 4px 12px rgba(124,58,237,0.35)'; }}
                      onMouseLeave={e => { e.currentTarget.style.transform = 'translateY(0)'; e.currentTarget.style.boxShadow = '0 2px 8px rgba(124,58,237,0.2)'; }}
                    >
                      View Report
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}
