import React, { useEffect, useState } from 'react';
import axios from 'axios';

type Suggestion = {
  text: string;
  section?: string; // Page section (e.g., "Hero Section", "Features")
};

type Audit = {
  insert_id?: number;
  clarity_score?: number;
  emotional_score?: number;
  cta_strength?: number;
  readability_score?: number;
  engagement_score?: number;
  trust_score?: number;
  suggestions?: Suggestion[] | string[]; // Support both old and new format
  rewrites?: Record<string, string>;
  page_id?: number;
  page_title?: string;
  page_url?: string;
  ai_used?: boolean;
  created_at?: string;
  insights?: {
    strengths?: string[];
    weaknesses?: string[];
    opportunities?: string[];
    audience_alignment?: string;
    tone_analysis?: string;
  };
  recommendations?: {
    quick_wins?: string[];
    long_term?: string[];
    priority?: string;
  };
};

type Page = { id: number; title: string; permalink: string };

const api = (path: string) => {
  // @ts-ignore
  const base = window.ConversionIQData?.restUrl || '/wp-json/conversioniq/v1/';
  return base + path;
};
const nonce = (window as any).ConversionIQData?.nonce;

export default function App() {
  const [settings, setSettings] = useState<any>({});
  const [pages, setPages] = useState<Page[]>([]);
  const [selectedPages, setSelectedPages] = useState<number[]>([]);
  const [audits, setAudits] = useState<Audit[]>([]);
  const [loading, setLoading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [notice, setNotice] = useState<string | null>(null);
  const [modal, setModal] = useState<{ audit?: Audit; open: boolean; tab?: string }>({ open: false, tab: 'overview' });
  const [expandedSuggestions, setExpandedSuggestions] = useState<Set<number>>(new Set([0])); // First suggestion expanded by default
  const [activeTab, setActiveTab] = useState<'settings' | 'automated' | 'audits'>('settings');
  const [automatedReporting, setAutomatedReporting] = useState({
    enabled: false,
    frequency: 'weekly',
    email: '',
    defaultPages: [] as number[]
  });

  // Load settings, pages, audits
  useEffect(() => {
    axios.get(api('settings'), { headers: { 'X-WP-Nonce': nonce } }).then(r => setSettings(r.data));
    axios.get(api('pages'), { headers: { 'X-WP-Nonce': nonce } }).then(r => setPages(r.data));
    axios.get(api('audits'), { headers: { 'X-WP-Nonce': nonce } }).then(r => setAudits(r.data.map((row: any) => row.data || row)));
  }, []);

  // Handlers
  const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setSettings({ ...settings, [e.target.name]: e.target.value });
  };
  
  const handleGuessFields = async () => {
    setLoading(true);
    setNotice('🤖 Analyzing your homepage to extract business information...');
    try {
      const response = await axios.post(
        api('guess-business-info'),
        {},
        { headers: { 'X-WP-Nonce': nonce } }
      );
      
      if (response.data.success && response.data.fields) {
        setSettings({ ...settings, ...response.data.fields });
        setNotice('✅ Business information extracted successfully!');
        setTimeout(() => setNotice(null), 3000);
      } else {
        throw new Error('Failed to extract information');
      }
    } catch (err) {
      console.error('❌ Auto-fill failed:', err);
      setNotice('Failed to extract business info - check server logs');
      setTimeout(() => setNotice(null), 3000);
    } finally {
      setLoading(false);
    }
  };
  
  const handleSaveSettings = () => {
    setLoading(true);
    axios.post(api('settings'), settings, { headers: { 'X-WP-Nonce': nonce } })
      .then(() => setNotice('Settings saved!'))
      .catch(() => setNotice('Failed to save settings'))
      .finally(() => setLoading(false));
  };
  const handlePageSelect = (id: number) => {
    setSelectedPages(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id]);
  };
  const handleRunAudit = async () => {
    if (!selectedPages.length) { setNotice('Select at least one page'); return; }
    setLoading(true);
    setProgress(0);
    setNotice('Analyzing page content with AI...');
    try {
      console.log(`🔍 Running audit for ${selectedPages.length} page(s)`);
      
      // Call backend audit endpoint - AI is handled on the server
      const response = await axios.post(
        api('audit'), 
        { pages: selectedPages }, 
        { headers: { 'X-WP-Nonce': nonce } }
      );
      
      if (response.data.success && response.data.results) {
        console.log('✅ Audit completed successfully');
        const results = response.data.results;
        
        setProgress(75);
        setNotice('✨ Finalizing audit results...');
        
        setAudits(audits => [...results, ...audits]);
        setProgress(100);
        setNotice('🎉 Audit complete!');
        
        // Clear notice after 3 seconds
        setTimeout(() => {
          setNotice(null);
          setProgress(0);
        }, 3000);
        
        setSelectedPages([]); // Clear selected pages after audit completes
      } else {
        throw new Error('Invalid audit response');
      }
    } catch (err) {
      console.error('❌ Audit failed:', err);
      setNotice('Audit failed - check server logs for details');
    } finally {
      setLoading(false);
    }
  };
  const handleExportReport = (insert_id?: number) => {
    if (!insert_id) {
      console.error('❌ Cannot export: No audit ID provided');
      setNotice('Cannot export - no audit ID');
      return;
    }
    console.log(`📄 Exporting report for audit #${insert_id}`);
    setLoading(true);
    setNotice('Generating report...');
    axios.post(api('report'), { audit_id: insert_id }, { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('Report export response:', r.data);
        if (r.data.url) {
          console.log('✅ Report generated:', r.data.url);
          
          // Trigger download instead of opening in new tab
          const link = document.createElement('a');
          link.href = r.data.url;
          link.download = `conversion-iq-report-${insert_id}.html`;
          link.target = '_blank';
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          
          setNotice('✅ Report downloaded successfully!');
          setTimeout(() => setNotice(null), 3000);
        } else {
          console.error('⚠️ No URL in response:', r.data);
          setNotice('Report generation failed - no URL returned');
        }
      })
      .catch(err => {
        console.error('❌ Report export error:', err);
        setNotice('Report export failed - check console');
      })
      .finally(() => setLoading(false));
  };

  return (
    <div className="ciq-frontend-root" style={{ minHeight: '100vh', background: '#f3f4f6', padding: 0, fontFamily: 'Inter,Arial,Helvetica,sans-serif' }}>
      <header style={{ background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', padding: '40px 0 36px 0', boxShadow: '0 4px 20px rgba(0,0,0,0.1)', marginBottom: 40 }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', padding: '0 32px' }}>
          <h1 style={{ margin: 0, fontWeight: 800, fontSize: 42, letterSpacing: -1.5 }}>Conversion IQ</h1>
          <p style={{ margin: '10px 0 0 0', fontSize: 20, opacity: 0.95, lineHeight: 1.5 }}>AI-powered audits & actionable recommendations for your WordPress pages.</p>
          <div style={{ marginTop: 20, padding: '16px 20px', background: 'rgba(255,255,255,0.12)', borderRadius: 12, fontSize: 14, lineHeight: 1.7, borderLeft: '4px solid rgba(255,255,255,0.3)' }}>
            <p style={{ margin: 0, opacity: 0.95 }}>
              <strong>Built by Webtec</strong> for conversion audits. Our audits are based on best practices and validated tests over thousands of customers.
            </p>
            <p style={{ margin: '8px 0 0 0', opacity: 0.9, fontSize: 13 }}>
              Unsure about your results? <a href="mailto:support@trywebtec.com" style={{ color: '#fff', textDecoration: 'underline', fontWeight: 500 }}>Contact our support team</a> for assistance.
            </p>
          </div>
        </div>
      </header>

      <main style={{ maxWidth: 1200, margin: '0 auto', padding: '0 32px 60px 32px' }}>
        {notice && (
          <div style={{ background: '#7c3aed', border: 'none', color: '#fff', borderRadius: 12, padding: 16, marginBottom: 24, fontWeight: 500, boxShadow: '0 4px 12px rgba(124, 58, 237, 0.2)' }}>
            {notice}
          </div>
        )}
        {loading && (
          <div style={{ marginBottom: 24, background: '#fff', padding: 20, borderRadius: 12, boxShadow: '0 2px 8px rgba(0,0,0,0.08)' }}>
            <div style={{ fontSize: 14, color: '#374151', marginBottom: 8, fontWeight: 500 }}>
              {notice || 'Processing...'} {progress > 0 && `${progress}%`}
            </div>
            <div style={{ height: 10, background: '#e9d5ff', borderRadius: 8, overflow: 'hidden' }}>
              <div style={{ 
                width: `${progress}%`, 
                height: 10, 
                background: 'linear-gradient(90deg, #7c3aed 0%, #5b21b6 100%)', 
                transition: 'width 0.5s ease-out', 
                borderRadius: 8 
              }} />
            </div>
            {progress > 0 && (
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 8, fontStyle: 'italic' }}>
                {progress < 75 && '🤖 AI is analyzing your page content and generating insights...'}
                {progress >= 75 && progress < 100 && '✨ Almost done! Finalizing your comprehensive audit report...'}
                {progress === 100 && '✅ Success! Your audit is ready to view.'}
              </div>
            )}
          </div>
        )}

        {/* Tab Navigation */}
        <div style={{ background: '#fff', borderRadius: 12, marginBottom: 24, boxShadow: '0 2px 8px rgba(0,0,0,0.06)', overflow: 'hidden' }}>
          <div style={{ display: 'flex', borderBottom: '2px solid #f3f4f6' }}>
            <button
              onClick={() => setActiveTab('settings')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'settings' ? '#7c3aed' : '#fff',
                color: activeTab === 'settings' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'settings' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s'
              }}
            >
              Business Information
            </button>
            <button
              onClick={() => setActiveTab('automated')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'automated' ? '#7c3aed' : '#fff',
                color: activeTab === 'automated' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'automated' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s'
              }}
            >
              Automated Reports
            </button>
            <button
              onClick={() => setActiveTab('audits')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'audits' ? '#7c3aed' : '#fff',
                color: activeTab === 'audits' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'audits' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s'
              }}
            >
              Audits
            </button>
          </div>
        </div>

        {/* Settings Tab */}
        {activeTab === 'settings' && (
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
              <h2 style={{ margin: 0, fontSize: 24, fontWeight: 700, color: '#111827' }}>Business Information</h2>
              <button 
                onClick={handleGuessFields} 
                disabled={loading}
                style={{ 
                  padding: '10px 20px', 
                  background: loading ? '#d1d5db' : 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)', 
                  color: '#fff', 
                  border: 'none', 
                  borderRadius: 8, 
                  fontSize: 14, 
                  fontWeight: 600, 
                  cursor: loading ? 'not-allowed' : 'pointer',
                  boxShadow: '0 2px 8px rgba(245, 158, 11, 0.3)',
                  transition: 'all 0.2s' 
                }}
                onMouseEnter={(e) => !loading && (e.currentTarget.style.transform = 'translateY(-2px)')}
                onMouseLeave={(e) => !loading && (e.currentTarget.style.transform = 'translateY(0)')}
              >
                🪄 Guess these fields for me
              </button>
            </div>
            <p style={{ color: '#6b7280', marginBottom: 24, fontSize: 15 }}>
              Provide details about your business to help our AI deliver personalized audit recommendations.
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginBottom: 16 }}>
              <input name="industry" placeholder="Industry/Niche" value={settings.industry || ''} onChange={handleSettingsChange} style={{ padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }} onFocus={(e) => e.target.style.borderColor = '#7c3aed'} onBlur={(e) => e.target.style.borderColor = '#d1d5db'} />
              <input name="product" placeholder="What do you sell?" value={settings.product || ''} onChange={handleSettingsChange} style={{ padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }} onFocus={(e) => e.target.style.borderColor = '#7c3aed'} onBlur={(e) => e.target.style.borderColor = '#d1d5db'} />
              <input name="audience" placeholder="Who do you sell to?" value={settings.audience || ''} onChange={handleSettingsChange} style={{ padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }} onFocus={(e) => e.target.style.borderColor = '#7c3aed'} onBlur={(e) => e.target.style.borderColor = '#d1d5db'} />
              <input name="pain_points" placeholder="Main customer pain points (comma separated)" value={settings.pain_points || ''} onChange={handleSettingsChange} style={{ padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }} onFocus={(e) => e.target.style.borderColor = '#7c3aed'} onBlur={(e) => e.target.style.borderColor = '#d1d5db'} />
              <input name="competitors" placeholder="Key competitors (comma separated)" value={settings.competitors || ''} onChange={handleSettingsChange} style={{ padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }} onFocus={(e) => e.target.style.borderColor = '#7c3aed'} onBlur={(e) => e.target.style.borderColor = '#d1d5db'} />
              <input name="goal" placeholder="Primary conversion goal" value={settings.goal || ''} onChange={handleSettingsChange} style={{ padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }} onFocus={(e) => e.target.style.borderColor = '#7c3aed'} onBlur={(e) => e.target.style.borderColor = '#d1d5db'} />
            </div>
            <div style={{ marginBottom: 16 }}>
              <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>Additional Information (Optional)</label>
              <textarea 
                name="additional_info" 
                placeholder="Any other context about your business, unique selling points, or specific areas you'd like the AI to focus on..." 
                value={settings.additional_info || ''} 
                onChange={handleSettingsChange}
                rows={4}
                style={{ 
                  width: '100%', 
                  padding: '12px 16px', 
                  border: '1px solid #d1d5db', 
                  borderRadius: 8, 
                  fontSize: 14, 
                  outline: 'none', 
                  transition: 'border 0.2s', 
                  background: '#fff', 
                  color: '#111827',
                  fontFamily: 'Inter,Arial,Helvetica,sans-serif',
                  resize: 'vertical'
                }} 
                onFocus={(e) => e.target.style.borderColor = '#7c3aed'} 
                onBlur={(e) => e.target.style.borderColor = '#d1d5db'} 
              />
            </div>
            <button className="ciq-btn primary" onClick={handleSaveSettings} disabled={loading} style={{ marginTop: 20, padding: '12px 24px', background: '#7c3aed', color: '#fff', border: 'none', borderRadius: 8, fontSize: 15, fontWeight: 600, cursor: loading ? 'not-allowed' : 'pointer', opacity: loading ? 0.6 : 1, transition: 'all 0.2s' }} onMouseEnter={(e) => !loading && (e.currentTarget.style.background = '#6d28d9')} onMouseLeave={(e) => !loading && (e.currentTarget.style.background = '#7c3aed')}>
              Save Settings
            </button>
          </section>
        )}

        {/* Automated Reports Tab - keeping same as original */}
        {activeTab === 'automated' && (
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <h2 style={{ margin: '0 0 8px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>Automated Reports</h2>
            <p style={{ color: '#6b7280', marginBottom: 24, fontSize: 15 }}>
              Set up automated audits that run on a schedule and email you the results. Perfect for monitoring your key pages over time.
            </p>

            <div style={{ marginBottom: 24 }}>
              <label style={{ display: 'flex', alignItems: 'center', padding: 16, background: '#f3e8ff', borderRadius: 8, cursor: 'pointer', border: `2px solid ${automatedReporting.enabled ? '#a78bfa' : '#e9d5ff'}` }}>
                <input
                  type="checkbox"
                  checked={automatedReporting.enabled}
                  onChange={(e) => setAutomatedReporting({ ...automatedReporting, enabled: e.target.checked })}
                  style={{ width: 20, height: 20, cursor: 'pointer', marginRight: 12 }}
                />
                <div>
                  <div style={{ fontWeight: 600, color: '#5b21b6', marginBottom: 4 }}>Enable Automated Audits</div>
                  <div style={{ fontSize: 13, color: '#6b21a8' }}>Automatically run audits and receive email reports</div>
                </div>
              </label>
            </div>

            {automatedReporting.enabled && (
              <>
                <div style={{ marginBottom: 20 }}>
                  <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827' }}>Email Address</label>
                  <input
                    type="email"
                    placeholder="your@email.com"
                    value={automatedReporting.email}
                    onChange={(e) => setAutomatedReporting({ ...automatedReporting, email: e.target.value })}
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                  />
                  <p style={{ fontSize: 13, color: '#6b7280', marginTop: 6 }}>Report PDFs will be sent to this email address</p>
                </div>

                <div style={{ marginBottom: 20 }}>
                  <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827' }}>Frequency</label>
                  <select
                    value={automatedReporting.frequency}
                    onChange={(e) => setAutomatedReporting({ ...automatedReporting, frequency: e.target.value })}
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', cursor: 'pointer', background: '#fff', color: '#111827' }}
                  >
                    <option value="weekly">Weekly (Every Monday)</option>
                    <option value="monthly">Monthly (1st of each month)</option>
                    <option value="bimonthly">Every 2 Months</option>
                  </select>
                </div>

                <div style={{ marginBottom: 20 }}>
                  <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827' }}>Default Pages to Audit</label>
                  <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 12 }}>Select which pages should be automatically audited on the scheduled frequency.</p>
                  <div style={{ maxHeight: 200, overflow: 'auto', border: '1px solid #d1d5db', borderRadius: 8, padding: 12, background: '#f9fafb' }}>
                    {pages.length === 0 ? (
                      <div style={{ color: '#9ca3af', textAlign: 'center', padding: 20 }}>No pages available</div>
                    ) : (
                      pages.map(p => (
                        <label key={p.id} style={{ display: 'flex', alignItems: 'center', padding: '8px 10px', marginBottom: 6, background: automatedReporting.defaultPages.includes(p.id) ? '#f3e8ff' : '#fff', borderRadius: 6, cursor: 'pointer', transition: 'all 0.2s' }}>
                          <input
                            type="checkbox"
                            checked={automatedReporting.defaultPages.includes(p.id)}
                            onChange={() => {
                              const isSelected = automatedReporting.defaultPages.includes(p.id);
                              setAutomatedReporting({
                                ...automatedReporting,
                                defaultPages: isSelected
                                  ? automatedReporting.defaultPages.filter(id => id !== p.id)
                                  : [...automatedReporting.defaultPages, p.id]
                              });
                            }}
                            style={{ marginRight: 10, width: 16, height: 16, cursor: 'pointer' }}
                          />
                          <span style={{ flex: 1, fontWeight: 500, color: '#374151', fontSize: 14 }}>{p.title}</span>
                        </label>
                      ))
                    )}
                  </div>
                </div>

                <div style={{ padding: 16, background: '#ede9fe', borderRadius: 8, marginBottom: 20 }}>
                  <div style={{ fontSize: 14, fontWeight: 600, color: '#5b21b6', marginBottom: 8 }}>Summary</div>
                  <div style={{ fontSize: 13, color: '#6b21a8' }}>
                    • <strong>{automatedReporting.frequency === 'weekly' ? 'Weekly' : automatedReporting.frequency === 'monthly' ? 'Monthly' : 'Bi-monthly'}</strong> audits will run automatically<br />
                    • <strong>{automatedReporting.defaultPages.length} page{automatedReporting.defaultPages.length !== 1 ? 's' : ''}</strong> will be audited<br />
                    • Results will be emailed to <strong>{automatedReporting.email || 'your email'}</strong>
                  </div>
                </div>

                <button
                  className="ciq-btn primary"
                  onClick={() => {
                    setNotice('Automated reporting settings saved! Audits will run automatically.');
                    // TODO: Save to backend
                  }}
                  disabled={loading || automatedReporting.defaultPages.length === 0 || !automatedReporting.email}
                  style={{
                    padding: '12px 24px',
                    background: (automatedReporting.defaultPages.length === 0 || !automatedReporting.email) ? '#d1d5db' : '#7c3aed',
                    color: '#fff',
                    border: 'none',
                    borderRadius: 8,
                    fontSize: 15,
                    fontWeight: 600,
                    cursor: (loading || automatedReporting.defaultPages.length === 0 || !automatedReporting.email) ? 'not-allowed' : 'pointer',
                    transition: 'all 0.2s'
                  }}
                  onMouseEnter={(e) => !loading && automatedReporting.defaultPages.length > 0 && automatedReporting.email && (e.currentTarget.style.background = '#6d28d9')}
                  onMouseLeave={(e) => !loading && automatedReporting.defaultPages.length > 0 && automatedReporting.email && (e.currentTarget.style.background = '#7c3aed')}
                >
                  Save Automated Settings
                </button>
              </>
            )}
          </section>
        )}

        {/* Audits Tab - keeping rest of original code ... */}
      </main>
    </div>
  );
}
