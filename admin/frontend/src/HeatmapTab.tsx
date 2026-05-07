import React, { useState, useEffect, useRef, useCallback } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────

interface HeatmapPoint {
  x_pct: string | number;
  y_pct: string | number;
  element_tag?: string;
  element_text?: string;
}

interface TopElement {
  element_tag: string;
  element_text: string;
  clicks: number;
}

interface HeatmapPage {
  page_url: string;
  total_events: number;
  total_sessions: number;
  last_event: string;
}

interface ScreenshotData {
  screenshot_url: string;
  page_width: number;
  page_height: number | null;
  captured_at: string | null;
  from_cache: boolean;
}

interface HeatmapTabProps {
  nonce: string;
  apiBase: string;
  features?: Record<string, any>;
}

// ── Canvas heatmap renderer ────────────────────────────────────────────────

function renderHeatmap(
  canvas: HTMLCanvasElement,
  points: HeatmapPoint[],
  imgWidth: number,
  imgHeight: number
) {
  const ctx = canvas.getContext('2d');
  if (!ctx || points.length === 0) return;

  canvas.width  = imgWidth;
  canvas.height = imgHeight;
  ctx.clearRect(0, 0, imgWidth, imgHeight);

  // Build density layer: draw a radial gradient blob for each click
  const radius = Math.max(14, Math.min(40, imgWidth * 0.02));
  const alphaCanvas = document.createElement('canvas');
  alphaCanvas.width  = imgWidth;
  alphaCanvas.height = imgHeight;
  const aCtx = alphaCanvas.getContext('2d')!;

  points.forEach((p) => {
    const x = (parseFloat(String(p.x_pct)) / 100) * imgWidth;
    const y = (parseFloat(String(p.y_pct)) / 100) * imgHeight;
    const grad = aCtx.createRadialGradient(x, y, 0, x, y, radius);
    grad.addColorStop(0,   'rgba(255,255,255,0.6)');
    grad.addColorStop(0.4, 'rgba(255,255,255,0.3)');
    grad.addColorStop(1,   'rgba(255,255,255,0)');
    aCtx.fillStyle = grad;
    aCtx.fillRect(x - radius, y - radius, radius * 2, radius * 2);
  });

  // Map greyscale density to colour using a hot→cold palette
  const densityData = aCtx.getImageData(0, 0, imgWidth, imgHeight);
  const outputData  = ctx.createImageData(imgWidth, imgHeight);

  for (let i = 0; i < densityData.data.length; i += 4) {
    const v = densityData.data[i]; // 0–255
    if (v === 0) continue;
    const t = v / 255; // 0→1

    // Palette: blue → cyan → green → yellow → red
    let r = 0, g = 0, b = 0, a = 0;
    if (t < 0.25) {
      const s = t / 0.25;
      r = 0; g = Math.round(s * 255); b = 255; a = Math.round(t * 4 * 200);
    } else if (t < 0.5) {
      const s = (t - 0.25) / 0.25;
      r = 0; g = 255; b = Math.round((1 - s) * 255); a = Math.round(200 + s * 30);
    } else if (t < 0.75) {
      const s = (t - 0.5) / 0.25;
      r = Math.round(s * 255); g = 255; b = 0; a = 230;
    } else {
      const s = (t - 0.75) / 0.25;
      r = 255; g = Math.round((1 - s) * 255); b = 0; a = 230;
    }

    outputData.data[i]     = r;
    outputData.data[i + 1] = g;
    outputData.data[i + 2] = b;
    outputData.data[i + 3] = a;
  }

  ctx.putImageData(outputData, 0, 0);
}

// ── Component ──────────────────────────────────────────────────────────────

export default function HeatmapTab({ nonce, apiBase, features = {} }: HeatmapTabProps) {
  const api = (path: string) => apiBase + path;
  const headers = { 'X-WP-Nonce': nonce };

  // ── Plan-gated heatmap capabilities ──────────────────────────────────────
  const hasScrollMap    = !!features.heatmap_scroll;
  const maxHistoryDays  = (features.heatmap_history_days as number) || 7;
  const allDayOptions   = [7, 30, 90].filter(d => d <= maxHistoryDays);

  const [pages, setPages]             = useState<HeatmapPage[]>([]);
  const [pagesLoading, setPagesLoading] = useState(false);

  const [selectedUrl, setSelectedUrl] = useState('');
  const [days, setDays]               = useState<number>(() => Math.min(30, maxHistoryDays));

  const [points, setPoints]           = useState<HeatmapPoint[]>([]);
  const [topElements, setTopElements] = useState<TopElement[]>([]);
  const [totalEvents, setTotalEvents] = useState(0);
  const [totalSessions, setTotalSessions] = useState(0);
  const [dataLoading, setDataLoading] = useState(false);

  const [screenshot, setScreenshot]   = useState<ScreenshotData | null>(null);
  const [ssLoading, setSsLoading]     = useState(false);
  const [ssError, setSsError]         = useState('');

  const [error, setError]             = useState('');

  const [mapView, setMapView]                       = useState<'click' | 'scroll'>('click');
  const [scrollMilestones, setScrollMilestones]     = useState<{ milestone: number; sessions: number }[]>([]);
  const [scrollLoading, setScrollLoading]           = useState(false);

  const canvasRef = useRef<HTMLCanvasElement>(null);
  const imgRef    = useRef<HTMLImageElement>(null);

  // ── Load tracked pages on mount ──────────────────────────────────────────
  useEffect(() => {
    setPagesLoading(true);
    axios.get(api('heatmap/pages'), { headers })
      .then(r => {
        setPages(r.data.pages || []);
      })
      .catch(() => setError('Could not load heatmap pages.'))
      .finally(() => setPagesLoading(false));
  }, []);

  // ── Load heatmap data when URL or days change ────────────────────────────
  const loadData = useCallback(() => {
    if (!selectedUrl) return;
    setDataLoading(true);
    setError('');
    axios.get(api('heatmap/data'), {
      headers,
      params: { page_url: selectedUrl, days, event_type: 'click' }
    })
      .then(r => {
        setPoints(r.data.points || []);
        setTopElements(r.data.top_elements || []);
        setTotalEvents(r.data.total_events || 0);
        setTotalSessions(r.data.total_sessions || 0);
      })
      .catch(() => setError('Could not load click data.'))
      .finally(() => setDataLoading(false));
  }, [selectedUrl, days]);

  useEffect(() => { loadData(); }, [loadData]);

  // ── Load scroll-depth data ────────────────────────────────────────────────
  const loadScrollData = useCallback(() => {
    if (!selectedUrl) return;
    setScrollLoading(true);
    setScrollMilestones([]);
    axios.get(api('heatmap/data'), {
      headers,
      params: { page_url: selectedUrl, days, event_type: 'scroll' }
    })
      .then(r => {
        const top: { element_tag: string; element_text: string; clicks: number }[] = r.data.top_elements || [];
        const milestones = [25, 50, 75, 90, 100].map(m => {
          const found = top.find(e => e.element_text === m + '%');
          return { milestone: m, sessions: found ? Number(found.clicks) : 0 };
        });
        setScrollMilestones(milestones);
      })
      .catch(() => {})
      .finally(() => setScrollLoading(false));
  }, [selectedUrl, days]);

  useEffect(() => {
    if (mapView === 'scroll') loadScrollData();
  }, [mapView, loadScrollData]);

  // ── Fetch screenshot ─────────────────────────────────────────────────────
  const fetchScreenshot = (force = false) => {
    if (!selectedUrl) return;
    setSsLoading(true);
    setSsError('');

    const endpoint = api('heatmap/screenshot');
    const payload  = { page_url: selectedUrl, force_refresh: force };

    axios.post(endpoint, payload, { headers })
      .then(r => {
        setScreenshot(r.data);
      })
      .catch(err => {
        const msg = err.response?.data?.message || 'Screenshot capture failed.';
        setSsError(msg);
      })
      .finally(() => setSsLoading(false));
  };

  // ── Draw heatmap on canvas when points or screenshot change ───────────────
  useEffect(() => {
    if (!canvasRef.current || !imgRef.current || points.length === 0) return;
    const img = imgRef.current;
    if (!img.complete || !img.naturalWidth) return;
    renderHeatmap(canvasRef.current, points, img.naturalWidth, img.naturalHeight);
  }, [points, screenshot]);

  const handleImgLoad = () => {
    if (!canvasRef.current || !imgRef.current || points.length === 0) return;
    const img = imgRef.current;
    renderHeatmap(canvasRef.current, points, img.naturalWidth, img.naturalHeight);
  };

  // ── Styles ────────────────────────────────────────────────────────────────
  const S = {
    card: { background: '#fff', borderRadius: 16, boxShadow: '0 2px 12px rgba(0,0,0,0.06)', border: '1px solid #e5e7eb', padding: 24 } as React.CSSProperties,
    label: { fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 6, display: 'block' } as React.CSSProperties,
    select: { padding: '10px 14px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, color: '#111827', background: '#fff', outline: 'none', cursor: 'pointer' } as React.CSSProperties,
    btn: (color = '#7c3aed', disabled = false) => ({
      padding: '10px 20px',
      background: disabled ? '#e5e7eb' : color,
      color: disabled ? '#9ca3af' : '#fff',
      border: 'none',
      borderRadius: 8,
      fontSize: 14,
      fontWeight: 600,
      cursor: disabled ? 'not-allowed' : 'pointer',
      transition: 'opacity 0.2s',
    } as React.CSSProperties),
    stat: { padding: '16px 20px', background: '#f9fafb', borderRadius: 10, border: '1px solid #f3f4f6', textAlign: 'center' as const },
  };

  // ── Render ────────────────────────────────────────────────────────────────
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>

      {/* Header */}
      <section style={S.card}>
        <h2 style={{ margin: '0 0 4px', fontSize: 22, fontWeight: 700, color: '#111827' }}>Heatmap</h2>
        <p style={{ margin: '0 0 20px', fontSize: 14, color: '#6b7280' }}>
          See where visitors click and how far they scroll on your pages. The tracker automatically records interactions — no setup needed.
        </p>

        {/* Controls row */}
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'flex-end' }}>
          <div style={{ flex: '1 1 300px' }}>
            <label style={S.label}>Page</label>
            {pagesLoading ? (
              <div style={{ color: '#9ca3af', fontSize: 14 }}>Loading pages…</div>
            ) : pages.length === 0 ? (
              <div style={{ padding: '10px 14px', border: '1px dashed #d1d5db', borderRadius: 8, fontSize: 14, color: '#9ca3af' }}>
                No data yet — heatmap tracking starts as soon as visitors land on your pages.
              </div>
            ) : (
              <select
                style={{ ...S.select, width: '100%' }}
                value={selectedUrl}
                onChange={e => { setSelectedUrl(e.target.value); setScreenshot(null); setSsError(''); }}
              >
                <option value="">— Select a page —</option>
                {pages.map(p => (
                  <option key={p.page_url} value={p.page_url}>
                    {p.page_url} ({p.total_events.toLocaleString()} events)
                  </option>
                ))}
              </select>
            )}
          </div>
          <div>
            <label style={S.label}>Date range</label>
            <select style={S.select} value={days} onChange={e => setDays(Number(e.target.value))}>
              {allDayOptions.map(d => (
                <option key={d} value={d}>Last {d} days</option>
              ))}
              {[30, 90].filter(d => d > maxHistoryDays).map(d => (
                <option key={d} value={d} disabled>Last {d} days (upgrade required)</option>
              ))}
            </select>
          </div>
          {selectedUrl && (
            <button
              style={S.btn('#7c3aed', ssLoading || !selectedUrl)}
              onClick={() => fetchScreenshot(false)}
              disabled={ssLoading || !selectedUrl}
            >
              {ssLoading ? 'Loading screenshot…' : screenshot ? '↻ Refresh screenshot' : '📸 Load screenshot'}
            </button>
          )}
          {screenshot && (
            <button
              style={S.btn('#6b7280', ssLoading)}
              onClick={() => fetchScreenshot(true)}
              disabled={ssLoading}
              title="Force re-capture (bypasses 24h cache)"
            >
              Force refresh
            </button>
          )}
        </div>

        {/* Map type sub-tabs */}
        {selectedUrl && (
          <div style={{ display: 'flex', gap: 8, marginTop: 16, borderTop: '1px solid #f3f4f6', paddingTop: 16, alignItems: 'center', flexWrap: 'wrap' }}>
            <button
              onClick={() => setMapView('click')}
              style={{
                padding: '8px 20px',
                background: mapView === 'click' ? '#7c3aed' : '#f3f4f6',
                color: mapView === 'click' ? '#fff' : '#6b7280',
                border: 'none', borderRadius: 8, fontSize: 14, fontWeight: 600,
                cursor: 'pointer', transition: 'all 0.15s',
              }}
            >
              🖱️ Click Map
            </button>
            {hasScrollMap ? (
              <button
                onClick={() => setMapView('scroll')}
                style={{
                  padding: '8px 20px',
                  background: mapView === 'scroll' ? '#7c3aed' : '#f3f4f6',
                  color: mapView === 'scroll' ? '#fff' : '#6b7280',
                  border: 'none', borderRadius: 8, fontSize: 14, fontWeight: 600,
                  cursor: 'pointer', transition: 'all 0.15s',
                }}
              >
                📜 Scroll Map
              </button>
            ) : (
              <span
                title="Upgrade to Professional to unlock Scroll Map"
                style={{
                  padding: '8px 20px',
                  background: '#f9fafb',
                  color: '#d1d5db',
                  border: '1px dashed #d1d5db',
                  borderRadius: 8, fontSize: 14, fontWeight: 600,
                  cursor: 'not-allowed',
                }}
              >
                📜 Scroll Map <span style={{ fontSize: 11, marginLeft: 4, background: '#fef3c7', color: '#92400e', padding: '1px 6px', borderRadius: 4 }}>Pro</span>
              </span>
            )}
          </div>
        )}

        {ssError && (
          <div style={{ marginTop: 12, padding: '10px 14px', background: '#fee2e2', border: '1px solid #fca5a5', borderRadius: 8, fontSize: 13, color: '#991b1b' }}>
            {ssError}
          </div>
        )}
        {error && (
          <div style={{ marginTop: 12, padding: '10px 14px', background: '#fee2e2', border: '1px solid #fca5a5', borderRadius: 8, fontSize: 13, color: '#991b1b' }}>
            {error}
          </div>
        )}
      </section>

      {/* Stats row */}
      {selectedUrl && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16 }}>
          {[
            { label: 'Total Clicks', value: totalEvents.toLocaleString(), icon: '🖱️' },
            { label: 'Sessions', value: totalSessions.toLocaleString(), icon: '👤' },
            { label: 'Pages Tracked', value: pages.length, icon: '📄' },
            { label: 'Date Range', value: `${days} days`, icon: '📅' },
          ].map((card, i) => (
            <div key={i} style={{ ...S.stat }}>
              <div style={{ fontSize: 24, marginBottom: 6 }}>{card.icon}</div>
              <div style={{ fontSize: 28, fontWeight: 800, color: '#111827' }}>{card.value}</div>
              <div style={{ fontSize: 12, color: '#9ca3af', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', marginTop: 4 }}>{card.label}</div>
            </div>
          ))}
        </div>
      )}

      {/* Click map visualiser */}
      {selectedUrl && mapView === 'click' && (
        <section style={S.card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#111827' }}>Click Map</h3>
            {dataLoading && (
              <span style={{ fontSize: 13, color: '#9ca3af' }}>Loading data…</span>
            )}
            {!dataLoading && points.length > 0 && (
              <span style={{ fontSize: 13, color: '#6b7280' }}>{points.length.toLocaleString()} data points</span>
            )}
          </div>

          {!screenshot && !ssLoading && (
            <div style={{ padding: '60px 20px', background: '#f9fafb', borderRadius: 12, textAlign: 'center', border: '2px dashed #e5e7eb' }}>
              <div style={{ fontSize: 40, marginBottom: 12 }}>📸</div>
              <p style={{ color: '#6b7280', fontSize: 15, margin: '0 0 16px' }}>
                Click <strong>Load screenshot</strong> above to capture a screenshot of this page.<br />
                The heatmap overlay will render on top of it.
              </p>
              {points.length === 0 && (
                <p style={{ color: '#9ca3af', fontSize: 13, margin: 0 }}>
                  No click data recorded yet for this page in the selected date range.
                </p>
              )}
            </div>
          )}

          {ssLoading && (
            <div style={{ padding: '60px 20px', textAlign: 'center', background: '#f9fafb', borderRadius: 12 }}>
              <div style={{ width: 36, height: 36, border: '4px solid #e5e7eb', borderTopColor: '#7c3aed', borderRadius: '50%', animation: 'ciq-spin 0.8s linear infinite', margin: '0 auto 12px' }} />
              <style>{`@keyframes ciq-spin { to { transform: rotate(360deg); } }`}</style>
              <p style={{ color: '#6b7280', margin: 0 }}>Capturing screenshot…</p>
            </div>
          )}

          {screenshot && (
            <div style={{ position: 'relative', display: 'inline-block', width: '100%' }}>
              <img
                ref={imgRef}
                src={screenshot.screenshot_url}
                alt="Page screenshot"
                onLoad={handleImgLoad}
                style={{ display: 'block', width: '100%', height: 'auto', borderRadius: 8, border: '1px solid #e5e7eb' }}
              />
              <canvas
                ref={canvasRef}
                style={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', borderRadius: 8, pointerEvents: 'none', opacity: 0.75 }}
              />
              {screenshot.from_cache && (
                <div style={{ position: 'absolute', top: 8, right: 8, background: 'rgba(0,0,0,0.55)', color: '#fff', fontSize: 11, padding: '4px 8px', borderRadius: 6 }}>
                  Cached · {screenshot.captured_at ? new Date(screenshot.captured_at).toLocaleDateString() : ''}
                </div>
              )}
            </div>
          )}

          {/* Colour scale legend */}
          {screenshot && points.length > 0 && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12 }}>
              <span style={{ fontSize: 12, color: '#9ca3af' }}>Low</span>
              <div style={{ flex: 1, height: 8, borderRadius: 4, background: 'linear-gradient(90deg, #3b82f6 0%, #22c55e 33%, #eab308 66%, #ef4444 100%)' }} />
              <span style={{ fontSize: 12, color: '#9ca3af' }}>High</span>
            </div>
          )}
        </section>
      )}

      {/* No screenshot — show raw dot visualisation as fallback */}
      {selectedUrl && mapView === 'click' && !screenshot && points.length > 0 && (
        <section style={S.card}>
          <h3 style={{ margin: '0 0 4px', fontSize: 18, fontWeight: 700, color: '#111827' }}>Click Distribution</h3>
          <p style={{ margin: '0 0 16px', fontSize: 13, color: '#6b7280' }}>
            Proportional click positions across the page (load a screenshot for the full heatmap overlay).
          </p>
          <div style={{ position: 'relative', width: '100%', paddingBottom: '56.25%', background: '#f3f4f6', borderRadius: 8, overflow: 'hidden' }}>
            <svg
              viewBox="0 0 100 56.25"
              preserveAspectRatio="none"
              style={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%' }}
            >
              {points.slice(0, 500).map((p, i) => {
                const x = parseFloat(String(p.x_pct));
                const y = parseFloat(String(p.y_pct)) * 0.5625; // scale y to viewBox height
                return (
                  <circle key={i} cx={x} cy={y} r="0.6" fill="#ef4444" opacity="0.4" />
                );
              })}
            </svg>
            <div style={{ position: 'absolute', top: 8, left: 8, background: 'rgba(0,0,0,0.5)', color: '#fff', fontSize: 11, padding: '3px 7px', borderRadius: 4 }}>
              {Math.min(points.length, 500)} of {points.length} points shown
            </div>
          </div>
        </section>
      )}

      {/* Top clicked elements */}
      {selectedUrl && mapView === 'click' && topElements.length > 0 && (
        <section style={S.card}>
          <h3 style={{ margin: '0 0 16px', fontSize: 18, fontWeight: 700, color: '#111827' }}>Most Clicked Elements</h3>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {topElements.map((el, i) => {
              const maxClicks = topElements[0]?.clicks || 1;
              const pct = Math.round((el.clicks / maxClicks) * 100);
              return (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <div style={{ width: 32, textAlign: 'center', fontSize: 12, color: '#9ca3af', flexShrink: 0 }}>#{i + 1}</div>
                  <code style={{ fontSize: 12, color: '#7c3aed', background: '#f3e8ff', padding: '2px 6px', borderRadius: 4, flexShrink: 0 }}>
                    {el.element_tag || '?'}
                  </code>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13, color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', marginBottom: 3 }}>
                      {el.element_text || '(no text)'}
                    </div>
                    <div style={{ height: 6, background: '#f3f4f6', borderRadius: 3 }}>
                      <div style={{ height: '100%', width: `${pct}%`, background: 'linear-gradient(90deg, #7c3aed, #5b21b6)', borderRadius: 3 }} />
                    </div>
                  </div>
                  <div style={{ fontSize: 13, fontWeight: 700, color: '#374151', flexShrink: 0 }}>
                    {el.clicks.toLocaleString()}
                  </div>
                </div>
              );
            })}
          </div>
        </section>
      )}

      {/* Scroll map */}
      {selectedUrl && mapView === 'scroll' && (
        <section style={S.card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
            <div>
              <h3 style={{ margin: '0 0 4px', fontSize: 18, fontWeight: 700, color: '#111827' }}>Scroll Depth Map</h3>
              <p style={{ margin: 0, fontSize: 13, color: '#6b7280' }}>How far down the page your visitors scroll.</p>
            </div>
            {scrollLoading && <span style={{ fontSize: 13, color: '#9ca3af' }}>Loading data…</span>}
          </div>

          {scrollLoading && (
            <div style={{ padding: '40px 20px', textAlign: 'center' }}>
              <div style={{ width: 32, height: 32, border: '4px solid #e5e7eb', borderTopColor: '#7c3aed', borderRadius: '50%', animation: 'ciq-spin 0.8s linear infinite', margin: '0 auto 12px' }} />
              <p style={{ color: '#6b7280', margin: 0, fontSize: 14 }}>Loading scroll data…</p>
            </div>
          )}

          {!scrollLoading && scrollMilestones.length > 0 && (() => {
            const baseline = scrollMilestones[0]?.sessions || 0;

            if (baseline === 0) return (
              <div style={{ padding: '40px 20px', textAlign: 'center', background: '#f9fafb', borderRadius: 12, border: '2px dashed #e5e7eb' }}>
                <div style={{ fontSize: 36, marginBottom: 8 }}>📜</div>
                <p style={{ color: '#6b7280', margin: 0 }}>No scroll data recorded yet for this page in the selected date range.</p>
              </div>
            );

            const barColors = ['#22c55e', '#84cc16', '#eab308', '#f97316', '#ef4444'];
            return (
              <>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 8 }}>
                  {scrollMilestones.map(({ milestone, sessions }, i) => {
                    const pct = Math.round((sessions / baseline) * 100);
                    return (
                      <div key={milestone} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                        <div style={{ width: 44, fontSize: 13, fontWeight: 700, color: '#374151', textAlign: 'right', flexShrink: 0 }}>
                          {milestone}%
                        </div>
                        <div style={{ flex: 1, height: 34, background: '#f3f4f6', borderRadius: 6, overflow: 'hidden', position: 'relative' }}>
                          <div style={{
                            height: '100%',
                            width: `${pct}%`,
                            background: barColors[i],
                            borderRadius: 6,
                            transition: 'width 0.5s ease',
                            opacity: 0.85,
                          }} />
                          <span style={{
                            position: 'absolute', left: 10, top: '50%',
                            transform: 'translateY(-50%)',
                            fontSize: 12, fontWeight: 600, color: '#374151',
                            pointerEvents: 'none',
                          }}>
                            {sessions.toLocaleString()} sessions · {pct}% of visitors
                          </span>
                        </div>
                      </div>
                    );
                  })}
                </div>
                <p style={{ fontSize: 12, color: '#9ca3af', margin: '4px 0 20px 56px', fontStyle: 'italic' }}>
                  Percentages relative to visitors who reached 25% scroll depth.
                </p>

                {screenshot ? (
                  <div>
                    <h4 style={{ margin: '0 0 10px', fontSize: 14, fontWeight: 600, color: '#374151' }}>Page overlay</h4>
                    <div style={{ position: 'relative', display: 'inline-block', width: '100%' }}>
                      <img
                        src={screenshot.screenshot_url}
                        alt="Page screenshot"
                        style={{ display: 'block', width: '100%', height: 'auto', borderRadius: 8, border: '1px solid #e5e7eb' }}
                      />
                      {[
                        { from: 0,  to: 25,  milestone: 25,  bg: 'rgba(34,197,94,0.12)' },
                        { from: 25, to: 50,  milestone: 50,  bg: 'rgba(132,204,22,0.15)' },
                        { from: 50, to: 75,  milestone: 75,  bg: 'rgba(234,179,8,0.18)' },
                        { from: 75, to: 90,  milestone: 90,  bg: 'rgba(249,115,22,0.20)' },
                        { from: 90, to: 100, milestone: 100, bg: 'rgba(239,68,68,0.22)' },
                      ].map((band, i) => {
                        const ms = scrollMilestones.find(m => m.milestone === band.milestone);
                        const pct = Math.round(((ms?.sessions ?? 0) / baseline) * 100);
                        return (
                          <div key={i} style={{
                            position: 'absolute',
                            left: 0,
                            top: `${band.from}%`,
                            width: '100%',
                            height: `${band.to - band.from}%`,
                            background: band.bg,
                            borderBottom: i < 4 ? '1px dashed rgba(0,0,0,0.15)' : 'none',
                            display: 'flex',
                            alignItems: 'flex-start',
                            justifyContent: 'flex-end',
                            paddingTop: 4,
                            paddingRight: 8,
                            boxSizing: 'border-box' as const,
                          }}>
                            <span style={{
                              background: 'rgba(0,0,0,0.55)',
                              color: '#fff',
                              fontSize: 11,
                              padding: '3px 7px',
                              borderRadius: 4,
                              whiteSpace: 'nowrap' as const,
                            }}>
                              {band.milestone}% · {pct}% of visitors
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                ) : (
                  <div style={{ padding: '14px 18px', background: '#f3e8ff', borderRadius: 8, fontSize: 13, color: '#5b21b6' }}>
                    💡 Switch to <strong>Click Map</strong> and click <strong>Load screenshot</strong> to see scroll depth bands overlaid on your page.
                  </div>
                )}
              </>
            );
          })()}
        </section>
      )}

      {/* Empty state — license active, no pages tracked yet */}
      {!pagesLoading && pages.length === 0 && !error && (
        <section style={{ ...S.card, textAlign: 'center', padding: '60px 32px' }}>
          <div style={{ fontSize: 48, marginBottom: 16 }}>🖱️</div>
          <h3 style={{ margin: '0 0 8px', fontSize: 20, fontWeight: 700, color: '#111827' }}>No heatmap data yet</h3>
          <p style={{ color: '#6b7280', fontSize: 15, maxWidth: 420, margin: '0 auto 12px' }}>
            The tracker is active. Data will appear here as visitors interact with your pages. Typically starts within a few hours of real traffic.
          </p>
          <p style={{ color: '#9ca3af', fontSize: 13, margin: 0 }}>
            Make sure your license is activated and the plugin version is up to date.
          </p>
        </section>
      )}
    </div>
  );
}
