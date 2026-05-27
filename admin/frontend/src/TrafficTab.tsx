import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { T } from './theme';

// ── Types ──────────────────────────────────────────────────────────────────

interface Verdict {
  direction: 'seo' | 'cro' | 'both' | 'no_data';
  label: string;
  color: string;
  title: string;
  summary: string;
  actions: string[];
}

interface GscQuery {
  keyword: string;
  clicks: number;
  impressions: number;
  ctr: number;
  position: number;
}

interface GscPage {
  url: string;
  clicks: number;
  impressions: number;
  ctr: number;
  position: number;
}

interface Sitemap {
  url: string;
  last_submitted: string;
  errors: number;
  warnings: number;
  is_pending: boolean;
}

interface Channel {
  channel: string;
  sessions: number;
}

interface TopPage {
  path: string;
  title: string;
  sessions: number;
  page_views: number;
  engagement_rate: number;
}

interface GA4Data {
  sessions: number;
  total_users: number;
  bounce_rate: number;
  engagement_rate: number;
  conversions: number;
  avg_session_duration: number;
  channels: Channel[];
  top_pages: TopPage[];
  period_days: number;
}

interface GSCData {
  total_clicks: number;
  total_impressions: number;
  avg_ctr: number;
  avg_position: number;
  top_queries: GscQuery[];
  top_pages: GscPage[];
  sitemaps: Sitemap[];
  period_days: number;
}

interface Summary {
  ga4: GA4Data | Record<string, never>;
  gsc: GSCData | Record<string, never>;
  verdict: Verdict;
  fetched_at: number | null;
  ga4_connected: boolean;
  gsc_connected: boolean;
  errors?: Record<string, string>;
}

interface Status {
  ga4_connected: boolean;
  gsc_connected: boolean;
  has_tokens: boolean;
  ga4_property: string;
  gsc_property: string;
  auth_url: string;
  fetched_at: number | null;
  has_data: boolean;
}

interface GscSite {
  url: string;
  permission_level: string;
}

interface GA4Property {
  id: string;
  name: string;
  account: string;
}

interface TrafficTabProps {
  nonce: string;
  apiBase: string;
  features?: Record<string, any>;
}

// ── Helpers ────────────────────────────────────────────────────────────────

function fmt(n: number): string {
  return n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(n);
}

function fmtTime(secs: number): string {
  const m = Math.floor(secs / 60);
  const s = secs % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function fmtDate(ts: number | null): string {
  if (!ts) return 'Never';
  return new Date(ts * 1000).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function posColor(pos: number): string {
  if (pos <= 3) return '#10b981';
  if (pos <= 10) return '#f59e0b';
  return '#ef4444';
}

function StatCard({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div style={{ background: T.bgSubtle, borderRadius: 10, padding: '16px 20px', flex: 1, minWidth: 120 }}>
      <div style={{ fontSize: 11, color: T.textMuted, fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 6 }}>{label}</div>
      <div style={{ fontSize: 26, fontWeight: 700, color: T.textPrimary, lineHeight: 1 }}>{value}</div>
      {sub && <div style={{ fontSize: 12, color: T.textMuted, marginTop: 4 }}>{sub}</div>}
    </div>
  );
}

function SectionHeader({ title, subtitle }: { title: string; subtitle?: string }) {
  return (
    <div style={{ marginBottom: 16 }}>
      <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: T.textPrimary }}>{title}</h3>
      {subtitle && <p style={{ margin: '4px 0 0', fontSize: 13, color: T.textMuted }}>{subtitle}</p>}
    </div>
  );
}

// ── Main Component ─────────────────────────────────────────────────────────

export default function TrafficTab({ nonce, apiBase, features }: TrafficTabProps) {
  const api = (path: string) => apiBase + path;
  const headers = { 'X-WP-Nonce': nonce };

  const canUseTraffic = !!(features?.traffic_insights);

  // State
  const [status, setStatus] = useState<Status | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [notice, setNotice] = useState<{ type: 'success' | 'error'; msg: string } | null>(null);

  // Property setup wizard state
  const [setupStep, setSetupStep] = useState<'idle' | 'select-gsc' | 'select-ga4' | 'saving'>('idle');
  const [gscSites, setGscSites] = useState<GscSite[]>([]);
  const [ga4Props, setGa4Props] = useState<GA4Property[]>([]);
  const [selectedGsc, setSelectedGsc] = useState('');
  const [selectedGa4, setSelectedGa4] = useState('');
  const [selectedGa4Name, setSelectedGa4Name] = useState('');
  const [setupLoading, setSetupLoading] = useState(false);

  const showNotice = (type: 'success' | 'error', msg: string) => {
    setNotice({ type, msg });
    setTimeout(() => setNotice(null), 5000);
  };

  // Load status on mount
  useEffect(() => {
    axios.get(api('traffic-status'), { headers })
      .then(r => {
        setStatus(r.data);
        // If fully connected and has cached data, also load summary
        if (r.data.ga4_connected && (r.data.gsc_connected || r.data.has_data)) {
          return axios.get(api('traffic-summary'), { headers });
        }
        return null;
      })
      .then(r => { if (r) setSummary(r.data); })
      .catch(() => showNotice('error', 'Failed to load traffic status.'))
      .finally(() => setLoading(false));
  }, []);

  // Auto-launch property wizard when OAuth just completed (tokens exist but no property chosen yet)
  useEffect(() => {
    if (status && status.has_tokens && !status.ga4_connected && setupStep === 'idle') {
      startSetup();
    }
  }, [status]);

  // Force refresh
  const handleRefresh = useCallback(async () => {
    setRefreshing(true);
    try {
      const r = await axios.post(api('traffic-refresh'), {}, { headers });
      setSummary(r.data);
      setStatus(prev => prev ? { ...prev, fetched_at: r.data.fetched_at, has_data: true } : prev);
      showNotice('success', 'Traffic data refreshed.');
    } catch (e: any) {
      const msg = e?.response?.data?.message || 'Refresh failed. Try again in an hour.';
      showNotice('error', msg);
    } finally {
      setRefreshing(false);
    }
  }, []);

  // Start property setup after OAuth is complete
  const startSetup = useCallback(async () => {
    setSetupStep('select-gsc');
    setSetupLoading(true);
    try {
      const [gscR, ga4R] = await Promise.all([
        axios.get(api('traffic-gsc-sites'), { headers }),
        axios.get(api('traffic-ga4-properties'), { headers }),
      ]);
      if (gscR.data.error) {
        showNotice('error', 'Google Search Console: ' + gscR.data.error);
      }
      setGscSites(gscR.data.sites || []);
      setGa4Props(ga4R.data.properties || []);
    } catch {
      showNotice('error', 'Failed to load your Google properties. Please try reconnecting.');
      setSetupStep('idle');
    } finally {
      setSetupLoading(false);
    }
  }, []);

  // Save selected properties
  const saveProperties = useCallback(async () => {
    if (!selectedGsc && !selectedGa4) {
      showNotice('error', 'Please select at least one property.');
      return;
    }
    setSetupStep('saving');
    try {
      await axios.post(api('traffic-save-property'), {
        gsc_site_url: selectedGsc,
        ga4_property_id: selectedGa4,
        ga4_property_name: selectedGa4Name,
      }, { headers });

      // Reload status then fetch fresh data
      const statusR = await axios.get(api('traffic-status'), { headers });
      setStatus(statusR.data);
      const sumR = await axios.post(api('traffic-refresh'), {}, { headers });
      setSummary(sumR.data);
      setSetupStep('idle');
      showNotice('success', 'Properties saved. Loading your traffic data…');
    } catch {
      showNotice('error', 'Failed to save properties.');
      setSetupStep('idle');
    }
  }, [selectedGsc, selectedGa4, selectedGa4Name]);

  // Disconnect
  const handleDisconnect = useCallback(async () => {
    if (!confirm('Disconnect from Google? This will remove all stored tokens.')) return;
    await axios.post(api('traffic-disconnect'), {}, { headers });
    setStatus(prev => prev ? { ...prev, ga4_connected: false, gsc_connected: false, has_data: false } : prev);
    setSummary(null);
    showNotice('success', 'Disconnected from Google.');
  }, []);

  // ── Render helpers ─────────────────────────────────────────────────────────

  if (loading) {
    return (
      <div style={{ padding: 48, textAlign: 'center', color: T.textMuted }}>
        Loading traffic data…
      </div>
    );
  }

  // Upgrade prompt for free plan
  if (!canUseTraffic) {
    return (
      <div style={{ background: T.bgCard, borderRadius: 16, padding: 48, textAlign: 'center', border: `1px solid ${T.border}` }}>
        <div style={{ fontSize: 40, marginBottom: 16 }}>📊</div>
        <h2 style={{ color: T.textPrimary, margin: '0 0 8px' }}>Traffic Intelligence</h2>
        <p style={{ color: T.textMuted, maxWidth: 480, margin: '0 auto 24px', lineHeight: 1.6 }}>
          Connect GA4 and Google Search Console to see site-wide traffic, organic keywords,
          indexed pages, and a clear verdict on whether to focus on SEO or conversion rate optimisation.
        </p>
        <div style={{ display: 'inline-block', padding: '10px 24px', background: T.accent, color: '#fff', borderRadius: 8, fontWeight: 600, fontSize: 14 }}>
          Upgrade to Starter to unlock
        </div>
      </div>
    );
  }

  // Not connected yet — no OAuth tokens at all
  if (!status?.has_tokens && !status?.ga4_connected && !status?.gsc_connected) {
    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
        {notice && <NoticeBar notice={notice} />}
        <ConnectCard authUrl={status?.auth_url || ''} />
      </div>
    );
  }

  // Has tokens but property wizard not launched yet — show loading
  if (status?.has_tokens && !status?.ga4_connected && setupStep === 'idle') {
    return (
      <div style={{ padding: 48, textAlign: 'center', color: T.textMuted }}>
        Loading your Google properties…
      </div>
    );
  }

  // Property selection wizard
  if (setupStep === 'select-gsc' || setupStep === 'select-ga4' || setupStep === 'saving') {
    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
        {notice && <NoticeBar notice={notice} />}
        <PropertyWizard
          step={setupStep}
          loading={setupLoading}
          gscSites={gscSites}
          ga4Props={ga4Props}
          selectedGsc={selectedGsc}
          selectedGa4={selectedGa4}
          onSelectGsc={setSelectedGsc}
          onSelectGa4={(id, name) => { setSelectedGa4(id); setSelectedGa4Name(name); }}
          onNextStep={() => setSetupStep('select-ga4')}
          onSkipGsc={() => { setSelectedGsc(''); setSetupStep('select-ga4'); }}
          onSave={saveProperties}
          onBack={() => setSetupStep('select-gsc')}
          onDisconnect={handleDisconnect}
        />
      </div>
    );
  }

  // Main dashboard
  const ga4 = (summary?.ga4 || {}) as Partial<GA4Data>;
  const gsc = (summary?.gsc || {}) as Partial<GSCData>;
  const verdict = summary?.verdict;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      {notice && <NoticeBar notice={notice} />}

      {/* Header bar */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', background: T.bgCard, borderRadius: 12, padding: '16px 20px', border: `1px solid ${T.border}` }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 20, fontWeight: 700, color: T.textPrimary }}>Traffic Intelligence</h2>
          <p style={{ margin: '2px 0 0', fontSize: 13, color: T.textMuted }}>
            Last updated: {fmtDate(summary?.fetched_at ?? null)} &nbsp;·&nbsp;
            {status?.ga4_connected ? '✓ GA4' : '✗ GA4'} &nbsp;·&nbsp;
            {status?.gsc_connected ? '✓ GSC' : '✗ GSC'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            onClick={handleRefresh}
            disabled={refreshing}
            style={{ padding: '8px 16px', background: T.accent, color: '#fff', border: 'none', borderRadius: 8, fontWeight: 600, fontSize: 13, cursor: refreshing ? 'not-allowed' : 'pointer', opacity: refreshing ? 0.6 : 1 }}
          >
            {refreshing ? 'Refreshing…' : '↺ Refresh'}
          </button>
          <button
            onClick={() => startSetup()}
            style={{ padding: '8px 16px', background: T.bgSubtle, color: T.textSecondary, border: `1px solid ${T.border}`, borderRadius: 8, fontWeight: 600, fontSize: 13, cursor: 'pointer' }}
          >
            Change Properties
          </button>
          <button
            onClick={handleDisconnect}
            style={{ padding: '8px 16px', background: 'transparent', color: T.textMuted, border: `1px solid ${T.border}`, borderRadius: 8, fontSize: 13, cursor: 'pointer' }}
          >
            Disconnect
          </button>
        </div>
      </div>

      {/* Verdict card */}
      {verdict && verdict.direction !== 'no_data' && (
        <VerdictCard verdict={verdict} />
      )}

      {/* No data yet */}
      {!summary && (
        <div style={{ background: T.bgCard, borderRadius: 12, padding: 32, textAlign: 'center', border: `1px solid ${T.border}` }}>
          <p style={{ color: T.textMuted }}>No data yet. Hit <strong>Refresh</strong> to fetch your first snapshot.</p>
        </div>
      )}

      {summary && (
        <>
          {/* GA4 metrics */}
          {summary.ga4_connected && ga4.sessions !== undefined && (
            <div style={{ background: T.bgCard, borderRadius: 12, padding: 24, border: `1px solid ${T.border}` }}>
              <SectionHeader title="Traffic Overview" subtitle={`Google Analytics 4 — last ${ga4.period_days ?? 28} days`} />
              <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 24 }}>
                <StatCard label="Sessions"       value={fmt(ga4.sessions ?? 0)} />
                <StatCard label="Users"          value={fmt(ga4.total_users ?? 0)} />
                <StatCard label="Engagement Rate" value={`${ga4.engagement_rate ?? 0}%`} />
                <StatCard label="Bounce Rate"    value={`${ga4.bounce_rate ?? 0}%`} />
                <StatCard label="Conversions"    value={fmt(ga4.conversions ?? 0)} />
                <StatCard label="Avg. Session"   value={fmtTime(ga4.avg_session_duration ?? 0)} />
              </div>

              {/* Channels */}
              {ga4.channels && ga4.channels.length > 0 && (
                <>
                  <h4 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 600, color: T.textSecondary, textTransform: 'uppercase', letterSpacing: 0.5 }}>Traffic Channels</h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {ga4.channels.map((ch, i) => {
                      const total = (ga4.sessions ?? 1);
                      const pct = total > 0 ? Math.round((ch.sessions / total) * 100) : 0;
                      return (
                        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                          <div style={{ width: 160, fontSize: 13, color: T.textPrimary, flexShrink: 0 }}>{ch.channel || 'Unknown'}</div>
                          <div style={{ flex: 1, height: 8, background: T.bgSubtle, borderRadius: 4, overflow: 'hidden' }}>
                            <div style={{ width: `${pct}%`, height: '100%', background: T.accent, borderRadius: 4 }} />
                          </div>
                          <div style={{ width: 60, fontSize: 12, color: T.textMuted, textAlign: 'right' }}>{fmt(ch.sessions)} ({pct}%)</div>
                        </div>
                      );
                    })}
                  </div>
                </>
              )}

              {/* Top pages */}
              {ga4.top_pages && ga4.top_pages.length > 0 && (
                <div style={{ marginTop: 24 }}>
                  <h4 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 600, color: T.textSecondary, textTransform: 'uppercase', letterSpacing: 0.5 }}>Top Pages by Sessions</h4>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                      <tr style={{ borderBottom: `1px solid ${T.border}` }}>
                        {['Page', 'Sessions', 'Views', 'Engagement'].map(h => (
                          <th key={h} style={{ padding: '6px 8px', textAlign: h === 'Page' ? 'left' : 'right', color: T.textMuted, fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {ga4.top_pages.map((p, i) => (
                        <tr key={i} style={{ borderBottom: `1px solid ${T.border}` }}>
                          <td style={{ padding: '8px', color: T.textPrimary, maxWidth: 320, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            <span style={{ fontSize: 11, color: T.textMuted, marginRight: 6 }}>{p.title || 'Untitled'}</span>
                            <span style={{ color: T.textMuted, fontSize: 11 }}>{p.path}</span>
                          </td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textPrimary }}>{fmt(p.sessions)}</td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textMuted }}>{fmt(p.page_views)}</td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textMuted }}>{p.engagement_rate}%</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          {/* GSC metrics */}
          {summary.gsc_connected && gsc.total_clicks !== undefined && (
            <div style={{ background: T.bgCard, borderRadius: 12, padding: 24, border: `1px solid ${T.border}` }}>
              <SectionHeader title="Organic Search Performance" subtitle={`Google Search Console — last ${gsc.period_days ?? 28} days`} />
              <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 24 }}>
                <StatCard label="Organic Clicks"     value={fmt(gsc.total_clicks ?? 0)} />
                <StatCard label="Impressions"        value={fmt(gsc.total_impressions ?? 0)} />
                <StatCard label="Avg. CTR"           value={`${gsc.avg_ctr ?? 0}%`} />
                <StatCard label="Avg. Position"      value={`${gsc.avg_position ?? 0}`} sub={gsc.avg_position && gsc.avg_position <= 10 ? '✓ Page 1' : gsc.avg_position ? '⚠ Not page 1' : ''} />
              </div>

              {/* Top queries */}
              {gsc.top_queries && gsc.top_queries.length > 0 && (
                <>
                  <h4 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 600, color: T.textSecondary, textTransform: 'uppercase', letterSpacing: 0.5 }}>Top Organic Keywords</h4>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                      <tr style={{ borderBottom: `1px solid ${T.border}` }}>
                        {['Keyword', 'Clicks', 'Impressions', 'CTR', 'Position'].map(h => (
                          <th key={h} style={{ padding: '6px 8px', textAlign: h === 'Keyword' ? 'left' : 'right', color: T.textMuted, fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {gsc.top_queries.map((q, i) => (
                        <tr key={i} style={{ borderBottom: `1px solid ${T.border}` }}>
                          <td style={{ padding: '8px', color: T.textPrimary }}>{q.keyword}</td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textPrimary, fontWeight: 600 }}>{q.clicks}</td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textMuted }}>{q.impressions.toLocaleString()}</td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textMuted }}>{q.ctr}%</td>
                          <td style={{ padding: '8px', textAlign: 'right' }}>
                            <span style={{ color: posColor(q.position), fontWeight: 600 }}>{q.position}</span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </>
              )}

              {/* Sitemaps */}
              {gsc.sitemaps && gsc.sitemaps.length > 0 && (
                <div style={{ marginTop: 24 }}>
                  <h4 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 600, color: T.textSecondary, textTransform: 'uppercase', letterSpacing: 0.5 }}>Sitemaps</h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                    {gsc.sitemaps.map((sm, i) => (
                      <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px', background: T.bgSubtle, borderRadius: 8 }}>
                        <span style={{ fontSize: 16 }}>{sm.errors > 0 ? '❌' : sm.warnings > 0 ? '⚠️' : '✅'}</span>
                        <div style={{ flex: 1 }}>
                          <div style={{ fontSize: 13, color: T.textPrimary, fontWeight: 500 }}>{sm.url.replace(/^https?:\/\/[^/]+/, '')}</div>
                          <div style={{ fontSize: 11, color: T.textMuted }}>Submitted: {sm.last_submitted ? new Date(sm.last_submitted).toLocaleDateString() : 'N/A'}</div>
                        </div>
                        {sm.errors > 0 && <span style={{ fontSize: 12, color: '#ef4444', fontWeight: 600 }}>{sm.errors} errors</span>}
                        {sm.warnings > 0 && <span style={{ fontSize: 12, color: '#f59e0b', fontWeight: 600 }}>{sm.warnings} warnings</span>}
                        {sm.is_pending && <span style={{ fontSize: 11, color: T.textMuted }}>Processing…</span>}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Top GSC pages */}
              {gsc.top_pages && gsc.top_pages.length > 0 && (
                <div style={{ marginTop: 24 }}>
                  <h4 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 600, color: T.textSecondary, textTransform: 'uppercase', letterSpacing: 0.5 }}>Top Pages in Search</h4>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                      <tr style={{ borderBottom: `1px solid ${T.border}` }}>
                        {['URL', 'Clicks', 'Impressions', 'CTR', 'Pos.'].map(h => (
                          <th key={h} style={{ padding: '6px 8px', textAlign: h === 'URL' ? 'left' : 'right', color: T.textMuted, fontWeight: 600, fontSize: 11, textTransform: 'uppercase' }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {gsc.top_pages.map((p, i) => (
                        <tr key={i} style={{ borderBottom: `1px solid ${T.border}` }}>
                          <td style={{ padding: '8px', color: T.textPrimary, maxWidth: 320, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {p.url.replace(/^https?:\/\/[^/]+/, '') || '/'}
                          </td>
                          <td style={{ padding: '8px', textAlign: 'right', fontWeight: 600, color: T.textPrimary }}>{p.clicks}</td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textMuted }}>{p.impressions.toLocaleString()}</td>
                          <td style={{ padding: '8px', textAlign: 'right', color: T.textMuted }}>{p.ctr}%</td>
                          <td style={{ padding: '8px', textAlign: 'right' }}>
                            <span style={{ color: posColor(p.position), fontWeight: 600 }}>{p.position}</span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </>
      )}
    </div>
  );
}

// ── Sub-components ─────────────────────────────────────────────────────────

function NoticeBar({ notice }: { notice: { type: 'success' | 'error'; msg: string } }) {
  const bg = notice.type === 'success' ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)';
  const border = notice.type === 'success' ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)';
  const color  = notice.type === 'success' ? '#10b981' : '#ef4444';
  return (
    <div style={{ padding: '12px 16px', background: bg, border: `1px solid ${border}`, borderRadius: 8, color, fontSize: 14 }}>
      {notice.msg}
    </div>
  );
}

function ConnectCard({ authUrl }: { authUrl: string }) {
  return (
    <div style={{ background: T.bgCard, borderRadius: 16, padding: 40, border: `1px solid ${T.border}`, textAlign: 'center' }}>
      <div style={{ fontSize: 48, marginBottom: 16 }}>🔌</div>
      <h2 style={{ margin: '0 0 8px', color: T.textPrimary, fontSize: 22 }}>Connect Google to unlock Traffic Intelligence</h2>
      <p style={{ color: T.textMuted, maxWidth: 520, margin: '0 auto 28px', lineHeight: 1.7, fontSize: 14 }}>
        Link your Google Analytics 4 and Search Console accounts. We'll show you site-wide traffic,
        organic keywords, indexed pages, and a clear recommendation on where to focus next —
        traffic growth or conversion optimisation.
      </p>
      <div style={{ display: 'flex', gap: 16, justifyContent: 'center', marginBottom: 24 }}>
        {['📊 GA4 Sessions & Users', '🔍 Organic Keywords & Rankings', '🗺 Sitemap & Index Status', '🎯 CRO vs SEO Verdict'].map((f, i) => (
          <div key={i} style={{ padding: '8px 14px', background: T.bgSubtle, borderRadius: 8, fontSize: 12, color: T.textSecondary }}>{f}</div>
        ))}
      </div>
      {authUrl ? (
        <a
          href={authUrl}
          style={{ display: 'inline-block', padding: '12px 32px', background: '#4285f4', color: '#fff', borderRadius: 8, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}
        >
          Connect Google Account
        </a>
      ) : (
        <p style={{ color: '#ef4444', fontSize: 13 }}>
          Google OAuth is not configured yet. Please contact support.
        </p>
      )}
    </div>
  );
}

function PropertySetupPrompt({ onStart }: { onStart: () => void }) {
  return (
    <div style={{ background: T.bgCard, borderRadius: 16, padding: 40, border: `1px solid ${T.border}`, textAlign: 'center' }}>
      <div style={{ fontSize: 40, marginBottom: 16 }}>✅</div>
      <h2 style={{ margin: '0 0 8px', color: T.textPrimary }}>Google Connected!</h2>
      <p style={{ color: T.textMuted, marginBottom: 24 }}>Now select which GSC site and GA4 property to track.</p>
      <button onClick={onStart} style={{ padding: '12px 28px', background: T.accent, color: '#fff', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: 'pointer' }}>
        Choose Properties →
      </button>
    </div>
  );
}

function PropertyWizard({ step, loading, gscSites, ga4Props, selectedGsc, selectedGa4, onSelectGsc, onSelectGa4, onNextStep, onSkipGsc, onSave, onBack, onDisconnect }: {
  step: string;
  loading: boolean;
  gscSites: GscSite[];
  ga4Props: GA4Property[];
  selectedGsc: string;
  selectedGa4: string;
  onSelectGsc: (url: string) => void;
  onSelectGa4: (id: string, name: string) => void;
  onNextStep: () => void;
  onSkipGsc: () => void;
  onSave: () => void;
  onBack: () => void;
  onDisconnect: () => void;
}) {
  const [gscSearch, setGscSearch] = useState('');
  if (loading) {
    return <div style={{ padding: 48, textAlign: 'center', color: T.textMuted }}>Loading your Google properties…</div>;
  }

  if (step === 'saving') {
    return <div style={{ padding: 48, textAlign: 'center', color: T.textMuted }}>Saving and fetching your data…</div>;
  }

  if (step === 'select-gsc') {
    const filteredSites = gscSites.filter(s => s.url.toLowerCase().includes(gscSearch.toLowerCase()));
    return (
      <div style={{ background: T.bgCard, borderRadius: 16, padding: 32, border: `1px solid ${T.border}` }}>
        <h3 style={{ margin: '0 0 8px', color: T.textPrimary }}>Step 1 of 2 — Select Search Console Property</h3>
        <p style={{ color: T.textMuted, marginBottom: 20, fontSize: 13 }}>Choose the site you want to pull organic search data for.</p>
        {gscSites.length === 0 ? (
          <p style={{ color: '#ef4444', fontSize: 13 }}>No GSC sites found. Make sure you have Search Console access for your site.</p>
        ) : (
          <>
            <div style={{ position: 'relative', marginBottom: 12 }}>
              <span style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: T.textMuted, fontSize: 14, pointerEvents: 'none' }}>🔍</span>
              <input
                type="text"
                placeholder="Filter properties…"
                value={gscSearch}
                onChange={e => setGscSearch(e.target.value)}
                style={{ width: '100%', padding: '9px 12px 9px 36px', background: T.bgSubtle, border: `1px solid ${T.border}`, borderRadius: 8, color: T.textPrimary, fontSize: 14, outline: 'none', boxSizing: 'border-box' }}
              />
            </div>
            {filteredSites.length === 0 ? (
              <p style={{ color: T.textMuted, fontSize: 13, marginBottom: 24 }}>No properties match "{gscSearch}".</p>
            ) : (
              <>
                <div style={{ fontSize: 12, color: T.textMuted, marginBottom: 8 }}>
                  {filteredSites.length === gscSites.length ? `${gscSites.length} properties` : `${filteredSites.length} of ${gscSites.length} properties`}
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: 320, overflowY: 'auto', marginBottom: 24, paddingRight: 4 }}>
                  {filteredSites.map((s, i) => (
                    <label key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 16px', background: selectedGsc === s.url ? T.primaryBg : T.bgSubtle, border: `1px solid ${selectedGsc === s.url ? T.primary : T.border}`, borderRadius: 8, cursor: 'pointer', flexShrink: 0 }}>
                      <input type="radio" name="gsc" value={s.url} checked={selectedGsc === s.url} onChange={() => onSelectGsc(s.url)} style={{ accentColor: T.primary }} />
                      <span style={{ flex: 1, color: T.textPrimary, fontSize: 14 }}>{s.url}</span>
                      <span style={{ fontSize: 11, color: T.textMuted }}>{s.permission_level}</span>
                    </label>
                  ))}
                </div>
              </>
            )}
          </>
        )}
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
          <button onClick={onSkipGsc} style={{ padding: '10px 20px', background: 'none', color: T.textMuted, border: `1px solid ${T.border}`, borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>
            Skip GSC
          </button>
          <button onClick={onNextStep} disabled={!selectedGsc} style={{ padding: '10px 24px', background: T.accent, color: '#fff', border: 'none', borderRadius: 8, fontWeight: 600, cursor: selectedGsc ? 'pointer' : 'not-allowed', opacity: selectedGsc ? 1 : 0.5 }}>
            Next: GA4 Property →
          </button>
        </div>
        <div style={{ marginTop: 20, textAlign: 'center' }}>
          <button onClick={onDisconnect} style={{ background: 'none', border: 'none', color: T.textMuted, fontSize: 12, cursor: 'pointer', textDecoration: 'underline' }}>
            Sign out of Google
          </button>
        </div>
      </div>
    );
  }

  if (step === 'select-ga4') {
    return (
      <div style={{ background: T.bgCard, borderRadius: 16, padding: 32, border: `1px solid ${T.border}` }}>
        <h3 style={{ margin: '0 0 8px', color: T.textPrimary }}>Step 2 of 2 — Select GA4 Property</h3>
        <p style={{ color: T.textMuted, marginBottom: 20, fontSize: 13 }}>Choose the GA4 property for site-wide traffic metrics. You can skip this if you only want Search Console data.</p>
        {ga4Props.length === 0 ? (
          <p style={{ color: T.textMuted, fontSize: 13 }}>No GA4 properties found. You can skip this step and use Search Console data only.</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 24 }}>
            {ga4Props.map((p, i) => (
              <label key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 16px', background: selectedGa4 === p.id ? T.primaryBg : T.bgSubtle, border: `1px solid ${selectedGa4 === p.id ? T.primary : T.border}`, borderRadius: 8, cursor: 'pointer' }}>
                <input type="radio" name="ga4" value={p.id} checked={selectedGa4 === p.id} onChange={() => onSelectGa4(p.id, p.name)} style={{ accentColor: T.primary }} />
                <div style={{ flex: 1 }}>
                  <div style={{ color: T.textPrimary, fontSize: 14 }}>{p.name}</div>
                  <div style={{ fontSize: 11, color: T.textMuted }}>{p.account} · {p.id}</div>
                </div>
              </label>
            ))}
          </div>
        )}
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
          <button onClick={onBack} style={{ padding: '10px 20px', background: T.bgSubtle, color: T.textSecondary, border: `1px solid ${T.border}`, borderRadius: 8, cursor: 'pointer' }}>
            ← Back
          </button>
          <button onClick={onSave} style={{ padding: '10px 24px', background: T.accent, color: '#fff', border: 'none', borderRadius: 8, fontWeight: 600, cursor: 'pointer' }}>
            Save & Load Data
          </button>
        </div>
        <div style={{ marginTop: 20, textAlign: 'center' }}>
          <button onClick={onDisconnect} style={{ background: 'none', border: 'none', color: T.textMuted, fontSize: 12, cursor: 'pointer', textDecoration: 'underline' }}>
            Sign out of Google
          </button>
        </div>
      </div>
    );
  }

  return null;
}

function VerdictCard({ verdict }: { verdict: Verdict }) {
  const dirIcons: Record<string, string> = { seo: '🔍', cro: '⚡', both: '🎯', no_data: '📊' };
  return (
    <div style={{ background: T.bgCard, borderRadius: 12, padding: 24, border: `2px solid ${verdict.color}22`, boxShadow: `0 0 0 1px ${verdict.color}33` }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16 }}>
        <div style={{ width: 48, height: 48, borderRadius: 12, background: `${verdict.color}20`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 24, flexShrink: 0 }}>
          {dirIcons[verdict.direction] || '🎯'}
        </div>
        <div style={{ flex: 1 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
            <span style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 0.8, color: verdict.color }}>
              Priority Recommendation
            </span>
            <span style={{ padding: '2px 10px', background: `${verdict.color}20`, color: verdict.color, borderRadius: 20, fontSize: 12, fontWeight: 700 }}>
              {verdict.label}
            </span>
          </div>
          <h3 style={{ margin: '0 0 6px', fontSize: 18, fontWeight: 700, color: T.textPrimary }}>{verdict.title}</h3>
          <p style={{ margin: '0 0 16px', fontSize: 14, color: T.textSecondary, lineHeight: 1.6 }}>{verdict.summary}</p>
          {verdict.actions.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              {verdict.actions.map((action, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: 8, fontSize: 13, color: T.textSecondary }}>
                  <span style={{ color: verdict.color, marginTop: 1, flexShrink: 0 }}>→</span>
                  <span>{action}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
