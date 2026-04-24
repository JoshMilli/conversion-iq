import React, { useEffect, useState } from 'react';
import axios from 'axios';
import type { Suggestion, Audit, Page, Branding } from './types';
import OverviewTab from './OverviewTab';
import FaqTab from './FaqTab';

type Page = { id: number; title: string; permalink: string };

const api = (path: string) => {
  // @ts-ignore
  const base = window.ConversionIQData?.restUrl || '/wp-json/conversioniq/v1/';
  return base + path;
};
const nonce = (window as any).ConversionIQData?.nonce;

// Branding config (injected from PHP via ConversionIQData)
const branding = (window as any).ConversionIQData?.branding || {};
const windowFeatures: Record<string, any> = (window as any).ConversionIQData?.features || {};
const windowPlan: string = (window as any).ConversionIQData?.plan || 'free';

const B = {
  company: branding.company_name || 'Webtec',
  product: branding.product_name || 'Conversion IQ',
  supportEmail: branding.support_email || 'support@conversioniq-app.com',
  websiteUrl: branding.website_url || 'https://trywebtec.com',
  contactUrl: branding.contact_url || 'https://trywebtec.com/contact',
  primaryColor: branding.primary_color || '#1e3a5f',
  accentColor: branding.accent_color || '#2563eb',
  logoUrl: branding.logo_url || '',
  hidePoweredBy: branding.hide_powered_by || false,
  faqItems: branding.faq_items || [],
};

export default function App() {
  // License
  const [licenseStatus, setLicenseStatus] = useState<'active' | 'inactive' | 'checking'>('checking');
  const [profileRefreshing, setProfileRefreshing] = useState(false);
  const [licenseKey, setLicenseKey] = useState('');
  const [licenseLoading, setLicenseLoading] = useState(false);
  const [licenseCustomer, setLicenseCustomer] = useState<{ name: string; email: string; company: string; plan?: string } | null>(null);
  const [licenseValidatedAt, setLicenseValidatedAt] = useState<number>(0);
  // Derived: prefer live licenseCustomer.plan (updated on refresh) over the window constant
  const currentPlan: string = (licenseCustomer?.plan || windowPlan).toLowerCase();
  // Feature flags in state so they update reactively on plan refresh
  const [liveFeatures, setLiveFeatures] = useState<Record<string, any>>(windowFeatures);
  const canUse = (feature: string): boolean => !!liveFeatures[feature];
  const maxPagesPerAudit: number = (liveFeatures.max_pages_per_audit as number) || 1;
  const [licenseSites, setLicenseSites] = useState<{ site_url: string; activated_at: string }[] | null>(null);
  const [licenseSitesLoading, setLicenseSitesLoading] = useState(false);
  const [licenseMaxSites, setLicenseMaxSites] = useState<number | null>(null);
  const [deactivatingUrl, setDeactivatingUrl] = useState<string | null>(null);
  const [noticeType, setNoticeType] = useState<'success' | 'error' | null>(null);
  const TOAST_MS = 4000; // Consistent auto-dismiss duration
  const showError = (msg: string) => { setNotice(msg); setNoticeType('error'); };
  const showSuccess = (msg: string) => { setNotice(msg); setNoticeType('success'); };

  const [settings, setSettings] = useState<any>({});
  const [pages, setPages] = useState<Page[]>([]);
  const [selectedPages, setSelectedPages] = useState<number[]>([]);
  const [audits, setAudits] = useState<Audit[]>([]);
  const [loading, setLoading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [notice, setNotice] = useState<string | null>(null);
  // Per-section loading states
  const [savingSettings, setSavingSettings] = useState(false);
  const [savingAutomated, setSavingAutomated] = useState(false);
  const [auditRunning, setAuditRunning] = useState(false);
  const [activeTab, setActiveTab] = useState<'overview' | 'settings' | 'automated' | 'audits' | 'knockknock' | 'license' | 'faq'>('overview');
  const [automatedReporting, setAutomatedReporting] = useState({
    enabled: false,
    frequency: 'weekly',
    email: '',
    defaultPages: [] as number[]
  });
  const [testEmail, setTestEmail] = useState('');
  const [testEmailLoading, setTestEmailLoading] = useState(false);
  const [scoreHistory, setScoreHistory] = useState<any[]>([]);
  const [overviewPageFilter, setOverviewPageFilter] = useState<string>('all');
  const [manualReportEmail, setManualReportEmail] = useState('');
  const [manualReportLoading, setManualReportLoading] = useState(false);
  const [manualReportLog, setManualReportLog] = useState<string[]>([]);
  const [auditProgress, setAuditProgress] = useState({
    isRunning: false,
    currentPage: '',
    currentIndex: 0,
    totalPages: 0,
    message: 'Initializing audit...'
  });
  const [auditFilter, setAuditFilter] = useState<'all' | 'ai' | 'fallback'>('all');
  const [auditSearchQuery, setAuditSearchQuery] = useState('');
  
  // License display state
  const [showLicenseKey, setShowLicenseKey] = useState(false);
  const [fullLicenseKey, setFullLicenseKey] = useState('');

  // KnockKnock Webhook state
  const [knockKnockCompanyId, setKnockKnockCompanyId] = useState('');
  const [knockKnockWebhookSecret, setKnockKnockWebhookSecret] = useState('');
  const [knockKnockWebhookUrl, setKnockKnockWebhookUrl] = useState('');
  const [showKnockKnockSecret, setShowKnockKnockSecret] = useState(false);
  const [knockKnockLeads, setKnockKnockLeads] = useState<any[]>([]);
  const [knockKnockLeadsLoading, setKnockKnockLeadsLoading] = useState(false);
  const [knockKnockSearchQuery, setKnockKnockSearchQuery] = useState('');
  const [knockKnockTypeFilter, setKnockKnockTypeFilter] = useState<'all' | 'lead' | 'visitor'>('all');
  const [knockKnockCurrentPage, setKnockKnockCurrentPage] = useState(1);
  const [knockKnockViewMode, setKnockKnockViewMode] = useState<'table' | 'cards'>('table');
  const knockKnockItemsPerPage = 20;
  const [knockKnockStats, setKnockKnockStats] = useState({ totalLeads: 0, totalVisitors: 0, totalToday: 0 });
  const [selectedLead, setSelectedLead] = useState<any>(null);
  const [visitorTrend, setVisitorTrend] = useState<{ month: string; label: string; visitors: number; leads: number }[]>([]);


  // Auto-dismiss toast notifications after a consistent duration
  useEffect(() => {
    if (!notice) return;
    const timer = setTimeout(() => setNotice(null), TOAST_MS);
    return () => clearTimeout(timer);
  }, [notice]);

  // Check license status on mount
  useEffect(() => {
    axios.get(api('license/status'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        if (r.data.activated === true) {
          setLicenseStatus('active');
          setLicenseCustomer(r.data.customer || null);
          setLicenseKey(r.data.license_key || '');
          setFullLicenseKey(r.data.license_key_full || '');
          setLicenseValidatedAt(r.data.validated_at || 0);
          if (r.data.features) setLiveFeatures(r.data.features);
        } else {
          setLicenseStatus('inactive');
        }
      })
      .catch(() => setLicenseStatus('inactive'));
  }, []);

  // Load settings, pages, audits, automated settings
  useEffect(() => {
    // Load settings first, then immediately fetch business profile on top so Supabase
    // data always wins without a race condition against setSettings(r.data).
    axios.get(api('settings'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ Settings loaded');
        setSettings(r.data);
        // Load KnockKnock settings
        setKnockKnockCompanyId(r.data.knockknock_company_id || '');
        setKnockKnockWebhookSecret(r.data.knockknock_webhook_secret || '');
        setKnockKnockWebhookUrl(r.data.knockknock_webhook_url || '');
        // Chain business profile fetch so it always merges AFTER setSettings(r.data)
        return axios.get(api('business-profile'), { headers: { 'X-WP-Nonce': nonce } });
      })
      .then(r => {
        if (r?.data && typeof r.data === 'object') {
          const nonEmpty = Object.fromEntries(
            Object.entries(r.data).filter(([, v]) => v != null && v !== '')
          );
          if (Object.keys(nonEmpty).length > 0) {
            setSettings(prev => ({ ...prev, ...nonEmpty }));
          }
        }
      })
      .catch(err => {
        console.error('✗ Failed to load settings:', err);
      });
    
    axios.get(api('pages'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ Pages loaded:', r.data.length, 'page(s)');
        setPages(r.data);
      })
      .catch(err => console.error('✗ Failed to load pages:', err));
    
    axios.get(api('audits'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ Audits loaded:', r.data.length, 'audit(s)');
        setAudits(r.data.map((row: any) => ({
          ...row,
          insert_id: row.id
        })));
      })
      .catch(err => console.error('✗ Failed to load audits:', err));
    
    axios.get(api('score-history'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ Score history loaded:', r.data.length, 'data points');
        setScoreHistory(r.data);
      })
      .catch(err => console.error('✗ Failed to load score history:', err));

    axios.get(api('automated-settings'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ Automated settings loaded');
        // Ensure defaultPages is always an array
        const settings = {
          ...r.data,
          defaultPages: Array.isArray(r.data.defaultPages) ? r.data.defaultPages : []
        };
        setAutomatedReporting(settings);
      })
      .catch(err => console.error('✗ Failed to load automated settings:', err));

  }, []);

  // Re-fetch business profile when license becomes active (e.g. after activating on this page)
  // Also restore audit history from Supabase — survives plugin reinstalls
  useEffect(() => {
    if (licenseStatus !== 'active') return;
    axios.get(api('business-profile'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        if (r.data && typeof r.data === 'object') {
          const nonEmpty = Object.fromEntries(
            Object.entries(r.data).filter(([, v]) => v != null && v !== '')
          );
          if (Object.keys(nonEmpty).length > 0) {
            setSettings(prev => ({ ...prev, ...nonEmpty }));
          }
        }
      })
      .catch(err => console.error('✗ Failed to re-fetch business profile:', err?.message));

    // Restore audit history from Supabase so data survives reinstalls
    axios.get(api('audits/supabase'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        if (Array.isArray(r.data) && r.data.length > 0) {
          setAudits(r.data);
          // Also derive scoreHistory from these audits so the Overview tab renders
          // (scoreHistory normally comes from the local WP DB which is empty after reinstall)
          const history = r.data
            .map((a: any) => ({
              id:               a.id,
              page_id:          a.page_id,
              page_title:       a.page_title,
              created_at:       a.created_at,
              overall_score:    a.overall_score,
              clarity_score:    a.clarity_score,
              emotional_score:  a.emotional_score,
              cta_strength:     a.cta_strength,
              readability_score: a.readability_score,
              engagement_score: a.engagement_score,
              trust_score:      a.trust_score,
            }))
            .reverse(); // Supabase returns newest-first; scoreHistory expects oldest-first
          setScoreHistory(history);
        }
      })
      .catch(err => console.error('✗ Failed to restore audits from Supabase:', err?.message));
  }, [licenseStatus]);

  // Handlers
  const handleLicenseActivate = async () => {
    if (!licenseKey.trim()) {
      showError('Please enter a license key');
      return;
    }
    setLicenseLoading(true);
    setNotice(null);
    setNoticeType(null);
    try {
      const response = await axios.post(api('license/activate'), { license_key: licenseKey.trim() }, {
        headers: { 'X-WP-Nonce': nonce }
      });
      if (response.data.success) {
        setLicenseStatus('active');
        setLicenseCustomer(response.data.customer || null);
        if (response.data.features) setLiveFeatures(response.data.features);
        showSuccess('License activated successfully!');
      } else {
        showError(response.data.message || 'Activation failed');
      }
    } catch (err: any) {
      showError(err.response?.data?.message || 'Could not activate license. Please try again.');
    } finally {
      setLicenseLoading(false);
    }
  };

  const handleLicenseRefresh = async () => {
    setLicenseLoading(true);
    try {
      const r = await axios.post(api('license/refresh'), {}, { headers: { 'X-WP-Nonce': nonce } });
      if (r.data.success) {
        if (r.data.customer) setLicenseCustomer(r.data.customer);
        if (r.data.features) setLiveFeatures(r.data.features);
        showSuccess('Plan refreshed — now showing: ' + (r.data.customer?.plan ?? 'unknown'));
      } else {
        showError(r.data.message || 'Failed to refresh plan.');
      }
    } catch (err: any) {
      showError(err.response?.data?.message || 'Could not reach the license server.');
    } finally {
      setLicenseLoading(false);
    }
  };

  const handleLicenseDeactivate = async () => {
    if (!confirm('Deactivate this license on the current site? This will release your site slot so you can use it elsewhere.')) return;
    setLicenseLoading(true);
    try {
      await axios.post(api('license/deactivate'), {}, { headers: { 'X-WP-Nonce': nonce } });
      setLicenseStatus('inactive');
      setLicenseCustomer(null);
      setLicenseKey('');
      setFullLicenseKey('');
      setLicenseSites(null);
      setLicenseMaxSites(null);
      showSuccess('License deactivated. This site slot has been released.');
    } catch (err: any) {
      showError(err.response?.data?.message || 'Failed to deactivate license.');
    } finally {
      setLicenseLoading(false);
    }
  };

  const handleFetchSites = async () => {
    setLicenseSitesLoading(true);
    try {
      const r = await axios.get(api('license/sites'), { headers: { 'X-WP-Nonce': nonce } });
      if (r.data.success) {
        setLicenseSites(r.data.sites || []);
        setLicenseMaxSites(r.data.max_sites ?? null);
      } else {
        showError(r.data.message || 'Failed to load sites.');
      }
    } catch (err: any) {
      showError(err.response?.data?.message || 'Could not reach the license server.');
    } finally {
      setLicenseSitesLoading(false);
    }
  };

  const handleRemoveSite = async (siteUrl: string) => {
    if (!confirm(`Remove ${siteUrl} from this license? That site will lose access until re-activated.`)) return;
    setDeactivatingUrl(siteUrl);
    try {
      const r = await axios.post(api('license/remove-site'), { site_url: siteUrl }, { headers: { 'X-WP-Nonce': nonce } });
      if (r.data.success) {
        setLicenseSites(prev => (prev ?? []).filter(s => s.site_url !== siteUrl));
        showSuccess(`${siteUrl} removed from license.`);
      } else {
        showError(r.data.message || 'Failed to remove site.');
      }
    } catch (err: any) {
      showError(err.response?.data?.message || 'Could not reach the license server.');
    } finally {
      setDeactivatingUrl(null);
    }
  };

  const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setSettings({ ...settings, [e.target.name]: e.target.value });
  };  
  const handleGuessFields = async () => {
    setLoading(true);
    setNotice('Analyzing your homepage to extract business information...');
    try {
      const response = await axios.post(
        api('guess-business-info'),
        {},
        { headers: { 'X-WP-Nonce': nonce } }
      );
      
      if (response.data.success && response.data.fields) {
        setSettings({ ...settings, ...response.data.fields });
        setNotice('✅ Business information extracted successfully!');
      } else {
        throw new Error('Failed to extract information');
      }
    } catch (err) {
      console.error('❌ Auto-fill failed:', err);
      const serverMsg = (err as any)?.response?.data?.message;
      const status = (err as any)?.response?.status;
      let errorMsg = 'Failed to extract business info — please try again.';
      if (status === 403 || (serverMsg && serverMsg.toLowerCase().includes('license'))) {
        errorMsg = 'License not activated. Please activate your license to use this feature.';
      } else if (serverMsg) {
        errorMsg = serverMsg;
      }
      setNotice('❌ ' + errorMsg);
    } finally {
      setLoading(false);
    }
  };
  const handleSaveSettings = () => {
    setSavingSettings(true);
    const profileFields = {
      business_name: settings.business_name,
      industry: settings.industry,
      product: settings.product,
      audience: settings.audience,
      pain_points: settings.pain_points,
      competitors: settings.competitors,
      goal: settings.goal,
      additional_info: settings.additional_info,
      unique_selling_points: settings.unique_selling_points,
      target_geography: settings.target_geography,
      price_point: settings.price_point,
      primary_traffic_source: settings.primary_traffic_source,
    };
    axios.post(api('business-profile'), profileFields, { headers: { 'X-WP-Nonce': nonce } })
      .then(() => showSuccess('Business profile saved!'))
      .catch(() => showError('Failed to save business profile'))
      .finally(() => setSavingSettings(false));
  };
  const handleSaveAutomatedSettings = async () => {
    // Check if business information is filled out
    const hasBusinessInfo = settings.industry && settings.product && settings.audience && settings.goal;
    if (!hasBusinessInfo) {
      showError('Please fill out Business Information tab first before enabling automated audits');
      return;
    }
    
    setSavingAutomated(true);
    try {
      const response = await axios.post(api('automated-settings'), automatedReporting, { headers: { 'X-WP-Nonce': nonce } });
      if (response.data.success) {
        showSuccess('Automated report settings saved! ' + (response.data.next_run ? `Next run: ${new Date(response.data.next_run).toLocaleString()}` : ''));
      } else {
        showError(response.data.message);
      }
    } catch (err: any) {
      showError('Failed to save automated settings: ' + (err.response?.data?.message || err.message));
    } finally {
      setSavingAutomated(false);
    }
  };

  const handleTestEmail = async () => {
    const emailToTest = testEmail || automatedReporting.email;
    
    if (!emailToTest || !emailToTest.includes('@')) {
      setNotice('❌ Please enter a valid email address');
      return;
    }

    setTestEmailLoading(true);
    try {
      const response = await axios.post(api('test-email'), { email: emailToTest }, { headers: { 'X-WP-Nonce': nonce } });
      if (response.data.success) {
        setNotice('✅ Test email sent successfully to ' + emailToTest + '! Check your inbox.');
        setTestEmail('');
      } else {
        setNotice('❌ ' + response.data.message);
      }
    } catch (err: any) {
      setNotice('❌ Failed to send test email: ' + (err.response?.data?.message || err.message));
    } finally {
      setTestEmailLoading(false);
    }
  };

  const handleSendManualReport = async () => {
    const emailToSend = manualReportEmail || automatedReporting.email;
    
    if (!emailToSend || !emailToSend.includes('@')) {
      setNotice('❌ Please enter a valid email address');
      return;
    }

    if (automatedReporting.defaultPages.length === 0) {
      setNotice('❌ Please select at least one page in "Default Pages to Audit" above');
      return;
    }

    setManualReportLoading(true);
    setManualReportLog([]);
    
    try {
      const response = await axios.post(api('send-manual-report'), { 
        email: emailToSend,
        page_ids: automatedReporting.defaultPages
      }, { headers: { 'X-WP-Nonce': nonce } });
      
      if (response.data.success) {
        setNotice('✅ Audit report sent successfully to ' + emailToSend + '! Report includes ' + automatedReporting.defaultPages.length + ' page(s).');
        setManualReportEmail('');
        setManualReportLog(response.data.log || []);
      } else {
        setNotice('❌ ' + response.data.message);
        setManualReportLog(response.data.log || [response.data.message]);
      }
    } catch (err: any) {
      const errorMsg = err.response?.data?.message || err.message;
      setNotice('❌ Failed to send report: ' + errorMsg);
      setManualReportLog(err.response?.data?.log || [errorMsg]);
    } finally {
      setManualReportLoading(false);
    }
  };

  // KnockKnock webhook functions
  const handleSaveKnockKnockSettings = async () => {
    // Require at least one authentication method
    if (!knockKnockCompanyId.trim() && !knockKnockWebhookSecret.trim()) {
      setNotice('❌ Please enter either a Company ID or Webhook Secret (or both)');
      return;
    }

    setLoading(true);
    try {
      const response = await axios.post(api('settings'), {
        ...settings,
        knockknock_company_id: knockKnockCompanyId,
        knockknock_webhook_secret: knockKnockWebhookSecret
      }, { headers: { 'X-WP-Nonce': nonce } });

      if (response.data.success) {
        setNotice('✅ KnockKnock settings saved successfully!');
      } else {
        setNotice('❌ Failed to save KnockKnock settings');
      }
    } catch (err: any) {
      setNotice('❌ Failed to save: ' + (err.response?.data?.message || err.message));
    } finally {
      setLoading(false);
    }
  };

  const fetchKnockKnockLeads = async () => {
    console.log('=== Fetching KnockKnock Leads ===');
    setKnockKnockLeadsLoading(true);
    try {
      const url = api('webhooks');
      console.log('Fetching from:', url);
      const response = await axios.get(url, { headers: { 'X-WP-Nonce': nonce } });
      console.log('Response status:', response.status);
      console.log('Response data:', response.data);
      
      if (response.data.success) {
        console.log('Leads found:', response.data.leads?.length || 0);
        console.log('Lead data:', response.data.leads);
        setKnockKnockLeads(response.data.leads || []);
      } else {
        console.warn('Success flag is false:', response.data);
      }
    } catch (err: any) {
      console.error('Failed to load KnockKnock leads:', err);
      console.error('Error response:', err.response?.data);
    } finally {
      setKnockKnockLeadsLoading(false);
      console.log('=== Fetch Complete ===');
    }
  };

  const copyKnockKnockUrl = () => {
    navigator.clipboard.writeText(knockKnockWebhookUrl);
    setNotice('✅ Webhook URL copied to clipboard!');
  };

  // Load KnockKnock leads when Growth Machine tab is opened
  useEffect(() => {
    const hasAuth = knockKnockCompanyId || knockKnockWebhookSecret;

    if (activeTab === 'knockknock' && hasAuth) {
      fetchKnockKnockLeads();
      // Load monthly visitor trend
      axios.get(api('visitor-trend'), { headers: { 'X-WP-Nonce': nonce } })
        .then(r => { if (r.data?.months) setVisitorTrend(r.data.months); })
        .catch(() => {});
    }
  }, [activeTab, knockKnockCompanyId, knockKnockWebhookSecret]);

  // Auto-refresh leads every 30 seconds when on Growth Machine tab
  useEffect(() => {
    const hasAuth = knockKnockCompanyId || knockKnockWebhookSecret;
    
    if (activeTab === 'knockknock' && hasAuth) {
      const intervalId = setInterval(() => {
        fetchKnockKnockLeads();
      }, 30000); // 30 seconds
      
      return () => clearInterval(intervalId);
    }
  }, [activeTab, knockKnockCompanyId, knockKnockWebhookSecret]);

  const handlePageSelect = (id: number) => {
    setSelectedPages(p => {
      if (p.includes(id)) return p.filter(x => x !== id);
      if (p.length >= maxPagesPerAudit) {
        setNotice(`⚠️ Your ${currentPlan.charAt(0).toUpperCase() + currentPlan.slice(1)} plan allows up to ${maxPagesPerAudit} page${maxPagesPerAudit !== 1 ? 's' : ''} per audit. Upgrade for more.`);
        return p;
      }
      return [...p, id];
    });
  };
  const handleRunAudit = async () => {
    if (!selectedPages.length) { setNotice('Select at least one page'); return; }
    
    // Check if business information is filled out
    const hasBusinessInfo = settings.industry && settings.product && settings.audience && settings.goal;
    if (!hasBusinessInfo) {
      setNotice('❌ Please fill out Business Information tab first before running audits');
      return;
    }
    
    setLoading(true);
    setProgress(0);
    setNotice('Analyzing page content with AI...');
    
    // Show progress modal
    const pageNames = selectedPages.map(id => pages.find(p => p.id === id)?.title || 'Unknown Page');
    setAuditProgress({
      isRunning: true,
      currentPage: pageNames[0] || '',
      currentIndex: 0,
      totalPages: selectedPages.length,
      message: 'Starting audit analysis...'
    });
    
    try {
      console.log(`🔍 Running audit for ${selectedPages.length} page(s)`);
      
      // Update progress message
      setAuditProgress(prev => ({
        ...prev,
        message: 'Analyzing page content with AI...'
      }));
      
      // Call backend audit endpoint - AI is handled on the server
      const response = await axios.post(
        api('audit'), 
        { pages: selectedPages }, 
        { headers: { 'X-WP-Nonce': nonce } }
      );

      if (response.status === 429 || (response.data && response.data.error_code === 'weekly_limit_reached')) {
        setNotice(`⚠️ Weekly audit limit reached. Your plan allows 3 audits per week. Upgrade your plan for more, or wait for your limit to reset.`);
        return;
      }
      
      if (response.data.success && response.data.results) {
        console.log('✅ Audit completed successfully');
        const results = response.data.results;
        
        // Log detailed debug info for each result
        results.forEach((result: any, index: number) => {
          console.group(`📊 Audit Result ${index + 1}: ${result.page_title || 'Unknown'}`);
          console.log('Page ID:', result.page_id);
          console.log('AI Used:', result.ai_used !== false ? '✅ YES' : '❌ NO (Fallback)');
          console.log('Scores:', {
            clarity: result.clarity_score,
            emotional: result.emotional_score,
            cta: result.cta_strength,
            readability: result.readability_score,
            engagement: result.engagement_score,
            trust: result.trust_score
          });
          console.log('Suggestions Count:', result.suggestions?.length || 0);
          
          // Detailed webhook posting information
          console.log('%c━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'color: #10b981');
          console.log('%c🌐 WEBHOOK POSTED TO SUPPORT PORTAL', 'color: #10b981; font-weight: bold; font-size: 13px; background: #d1fae5; padding: 4px 8px; border-radius: 4px');
          console.log('%cEndpoint:', 'color: #059669; font-weight: bold', 'https://webtecsupportportal.abacusai.app/api/webhook/conversion-iq');
          console.log('%cMethod:', 'color: #059669; font-weight: bold', 'POST');
          console.log('%cAuthentication:', 'color: #059669; font-weight: bold', 'X-API-Key header (auto-configured from account)');
          console.log('%cPayload Data:', 'color: #059669; font-weight: bold');
          console.log('  • Page Title:', result.page_title || 'N/A');
          console.log('  • Page URL:', result.page_url || 'N/A');
          console.log('  • Page ID:', result.page_id || 'N/A');
          console.log('  • Clarity Score:', result.clarity_score);
          console.log('  • Emotional Score:', result.emotional_score);
          console.log('  • CTA Strength:', result.cta_strength);
          console.log('  • Readability Score:', result.readability_score);
          console.log('  • Engagement Score:', result.engagement_score);
          console.log('  • Trust Score:', result.trust_score);
          console.log('  • Suggestions:', result.suggestions?.length || 0, 'items');
          console.log('  • AI Used:', result.ai_used !== false ? 'YES' : 'NO (Fallback)');
          console.log('  • Site URL:', window.location.origin);
          console.log('%c✅ Check WordPress debug.log for webhook response details', 'color: #10b981; font-style: italic');
          console.log('%c━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'color: #10b981');
          
          // Warn if using fallback
          if (result.ai_used === false) {
            console.warn('⚠️ This audit used FALLBACK data - AI analysis failed!');
            console.log('%c💡 Check WordPress debug.log for detailed API response', 'color: #f59e0b; font-weight: bold');
          }
          
          console.groupEnd();
        });
        
        setProgress(75);
        setNotice('✨ Finalizing audit results...');
        setAuditProgress(prev => ({
          ...prev,
          message: '✨ Finalizing audit results...'
        }));
        
        setAudits(audits => [...results, ...audits]);
        setProgress(100);
        setNotice('🎉 Audit complete!');
        
        // Update to completed state
        setAuditProgress(prev => ({
          ...prev,
          currentIndex: prev.totalPages,
          message: '🎉 Audit complete!'
        }));
        
        // Clear notice and modal after 2 seconds
        setTimeout(() => {
          setNotice(null);
          setProgress(0);
          setAuditProgress({
            isRunning: false,
            currentPage: '',
            currentIndex: 0,
            totalPages: 0,
            message: ''
          });
        }, 2000);
        
        setSelectedPages([]); // Clear selected pages after audit completes
      } else {
        throw new Error('Invalid audit response');
      }
    } catch (err: any) {
      console.error('❌ Audit failed:', err);
      let errorMessage = 'Audit failed - check browser console for details';
      
      // Extract specific error message if available
      if (err.response?.data?.message) {
        errorMessage = `Audit failed: ${err.response.data.message}`;
      } else if (err.message) {
        errorMessage = `Audit failed: ${err.message}`;
      }
      
      setNotice(errorMessage);
      setAuditProgress(prev => ({
        ...prev,
        message: '❌ ' + errorMessage
      }));
      
      // Keep error message visible longer (5 seconds)
      setTimeout(() => {
        setNotice(null);
        setAuditProgress({
          isRunning: false,
          currentPage: '',
          currentIndex: 0,
          totalPages: 0,
          message: ''
        });
      }, 5000);
    } finally {
      setLoading(false);
      setProgress(0);
    }
  };

  // ── License Gate ──────────────────────────────────────────────────────────
  if (licenseStatus === 'checking') {
    return (
      <div className="ciq-frontend-root" style={{ minHeight: '100vh', background: '#f3f4f6', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'Inter,Arial,Helvetica,sans-serif' }}>
        <div style={{ textAlign: 'center', color: '#6b7280' }}>
          <div style={{ width: 40, height: 40, border: '4px solid #e5e7eb', borderTopColor: '#7c3aed', borderRadius: '50%', animation: 'spin 0.8s linear infinite', margin: '0 auto 16px' }} />
          <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
          <p style={{ margin: 0, fontSize: 15 }}>Loading {B.product}…</p>
        </div>
      </div>
    );
  }

  if (licenseStatus === 'inactive') {
    return (
      <div className="ciq-frontend-root" style={{ minHeight: '100vh', background: `linear-gradient(135deg, ${B.primaryColor} 0%, ${B.accentColor} 100%)`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'Inter,Arial,Helvetica,sans-serif', padding: 24 }}>
        <div style={{ background: '#fff', borderRadius: 20, boxShadow: '0 20px 60px rgba(0,0,0,0.25)', padding: '48px 40px', maxWidth: 480, width: '100%' }}>
          {/* Logo / branding */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 32, justifyContent: 'center' }}>
            <img
              src={B.logoUrl || `${(window as any).ConversionIQData?.pluginUrl || ''}/assets/images/Webtec.png`}
              alt={B.company}
              style={{ width: 100, height: 'auto' }}
            />
          </div>

          <h1 style={{ margin: '0 0 8px 0', fontSize: 26, fontWeight: 800, color: '#111827', textAlign: 'center' }}>
            Activate {B.product}
          </h1>
          <p style={{ margin: '0 0 32px 0', fontSize: 15, color: '#6b7280', textAlign: 'center', lineHeight: 1.6 }}>
            Enter your license key to unlock AI-powered conversion audits.{' '}
            <a href="https://conversioniq-app.com/pricing" target="_blank" rel="noopener noreferrer" style={{ color: '#7c3aed', fontWeight: 600, textDecoration: 'none' }}>
              Get a key →
            </a>
          </p>

          {notice && (
            <div style={{
              background: noticeType === 'error' ? '#fee2e2' : '#d1fae5',
              border: noticeType === 'error' ? '1px solid #fca5a5' : '1px solid #6ee7b7',
              color: noticeType === 'error' ? '#991b1b' : '#065f46',
              borderRadius: 10, padding: '12px 16px', marginBottom: 20, fontWeight: 500, fontSize: 14,
            }}>
              {notice}
            </div>
          )}

          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 6 }}>
              License Key
            </label>
            <input
              type="text"
              placeholder="CIQ-XXXXX-XXXXX-XXXXX-XXXXX"
              value={licenseKey}
              onChange={e => setLicenseKey(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && !licenseLoading && handleLicenseActivate()}
              style={{ width: '100%', padding: '12px 16px', border: '2px solid #e5e7eb', borderRadius: 10, fontSize: 15, outline: 'none', fontFamily: 'monospace', letterSpacing: '0.04em', boxSizing: 'border-box', transition: 'border 0.2s' }}
              onFocus={e => (e.target.style.borderColor = '#7c3aed')}
              onBlur={e => (e.target.style.borderColor = '#e5e7eb')}
              autoFocus
            />
          </div>

          <button
            onClick={handleLicenseActivate}
            disabled={licenseLoading || !licenseKey.trim()}
            style={{ width: '100%', padding: '14px 0', background: licenseLoading || !licenseKey.trim() ? '#c4b5fd' : 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', border: 'none', borderRadius: 10, fontSize: 16, fontWeight: 700, cursor: licenseLoading || !licenseKey.trim() ? 'not-allowed' : 'pointer', transition: 'opacity 0.2s', marginBottom: 24 }}
          >
            {licenseLoading ? 'Activating…' : 'Activate License'}
          </button>

          <div style={{ borderTop: '1px solid #f3f4f6', paddingTop: 20, textAlign: 'center', fontSize: 13, color: '#9ca3af' }}>
            Need help?{' '}
            <a href={`mailto:${B.supportEmail}`} style={{ color: '#7c3aed', fontWeight: 600, textDecoration: 'none' }}>
              {B.supportEmail}
            </a>
          </div>
        </div>
      </div>
    );
  }
  // ── End License Gate ──────────────────────────────────────────────────────

  return (
    <div className="ciq-frontend-root" style={{ minHeight: '100vh', background: '#f3f4f6', padding: 0, fontFamily: 'Inter,Arial,Helvetica,sans-serif' }}>
      <header style={{ background: `linear-gradient(135deg, ${B.primaryColor} 0%, ${B.accentColor} 100%)`, color: '#fff', padding: '32px 0', boxShadow: '0 4px 20px rgba(0,0,0,0.1)', marginBottom: 40 }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', padding: '0 32px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 24, marginBottom: 20 }}>
            <img 
              src={B.logoUrl || `${(window as any).ConversionIQData?.pluginUrl || ''}/assets/images/Webtec.png`} 
              alt={B.company} 
              style={{ width: 140, height: 'auto' }} 
            />
            <div style={{ height: 40, width: 1, background: 'rgba(255,255,255,0.3)' }}></div>
            <div>
              <h1 style={{ margin: 0, fontWeight: 800, fontSize: 36, letterSpacing: -1 }}>{B.product}</h1>
              <p style={{ margin: '4px 0 0 0', fontSize: 16, opacity: 0.9 }}>AI-powered conversion audits & recommendations</p>
            </div>
          </div>
          <div style={{ padding: '16px 20px', background: 'rgba(255,255,255,0.12)', borderRadius: 12, fontSize: 14, lineHeight: 1.7, borderLeft: '4px solid rgba(255,255,255,0.3)' }}>
            <p style={{ margin: 0, opacity: 0.95 }}>
              {B.hidePoweredBy ? '' : <><strong>Built by {B.company}</strong> for conversion audits. </>}Our audits are based on best practices and validated tests over thousands of customers.
            </p>
            <p style={{ margin: '8px 0 0 0', opacity: 0.9, fontSize: 13 }}>
              Unsure about your results? <a href={`mailto:${B.supportEmail}`} style={{ color: '#fff', textDecoration: 'underline', fontWeight: 500 }}>Contact our support team</a> for assistance.
            </p>
          </div>
        </div>
      </header>

      <main style={{ maxWidth: 1200, margin: '0 auto', padding: '0 32px 60px 32px' }}>
        {notice && (
          <div style={{
            background: noticeType === 'error' ? '#fee2e2' : noticeType === 'success' ? '#d1fae5' : '#7c3aed',
            border: noticeType === 'error' ? '1px solid #fca5a5' : noticeType === 'success' ? '1px solid #6ee7b7' : 'none',
            color: noticeType === 'error' ? '#991b1b' : noticeType === 'success' ? '#065f46' : '#fff',
            borderRadius: 12, padding: 16, marginBottom: 24, fontWeight: 500,
            boxShadow: noticeType ? 'none' : '0 4px 12px rgba(124, 58, 237, 0.2)'
          }}>
            {notice}
          </div>
        )}
        {loading && progress > 0 && (
          <div style={{ marginBottom: 24, background: '#fff', padding: 20, borderRadius: 12, boxShadow: '0 2px 8px rgba(0,0,0,0.08)' }}>
            <div style={{ height: 10, background: '#e9d5ff', borderRadius: 8, overflow: 'hidden' }}>
              <div style={{ 
                width: `${progress}%`, 
                height: 10, 
                background: 'linear-gradient(90deg, #7c3aed 0%, #5b21b6 100%)', 
                transition: 'width 0.5s ease-out', 
                borderRadius: 8 
              }} />
            </div>
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 8, fontStyle: 'italic' }}>
              {progress < 75 && '🤖 AI is analyzing your page content and generating insights...'}
              {progress >= 75 && progress < 100 && '✨ Almost done! Finalizing your comprehensive audit report...'}
              {progress === 100 && '✅ Success! Your audit is ready to view.'}
            </div>
          </div>
        )}

        {/* Tab Navigation */}
        <div style={{ background: '#fff', borderRadius: 12, marginBottom: 24, boxShadow: '0 2px 8px rgba(0,0,0,0.06)', overflow: 'hidden' }}>
          <div style={{ display: 'flex', borderBottom: '2px solid #f3f4f6' }}>
            <button
              onClick={() => setActiveTab('overview')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'overview' ? '#7c3aed' : '#fff',
                color: activeTab === 'overview' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'overview' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s'
              }}
            >
              Overview
            </button>
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
            <button
              onClick={() => setActiveTab('knockknock')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'knockknock' ? '#7c3aed' : '#fff',
                color: activeTab === 'knockknock' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'knockknock' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s',
                position: 'relative'
              }}
            >
              <span style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                KnockKnock
                {!canUse('knockknock') && (
                  <span style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: 16,
                    height: 16,
                    background: activeTab === 'knockknock' ? 'rgba(255,255,255,0.25)' : '#f3e8ff',
                    borderRadius: 4,
                    fontSize: 10,
                    lineHeight: 1,
                    color: activeTab === 'knockknock' ? '#fff' : '#7c3aed',
                    flexShrink: 0
                  }}>🔒</span>
                )}
              </span>
            </button>
            <button
              onClick={() => setActiveTab('license')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'license' ? '#7c3aed' : '#fff',
                color: activeTab === 'license' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'license' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s'
              }}
            >
              License
            </button>
            <button
              onClick={() => setActiveTab('faq')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'faq' ? '#7c3aed' : '#fff',
                color: activeTab === 'faq' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'faq' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s'
              }}
            >
              FAQ
            </button>
          </div>
        </div>

        {/* Overview Tab */}
        {activeTab === 'overview' && (
          <OverviewTab
            scoreHistory={scoreHistory}
            overviewPageFilter={overviewPageFilter}
            setOverviewPageFilter={setOverviewPageFilter}
            pages={pages}
            audits={audits}
            setActiveTab={setActiveTab as any}
          />
        )}

        {/* Business Information Tab — read-only view, editable at conversioniq-app.com */}
        {activeTab === 'settings' && (() => {
          const profileField = (label: string, value: string | undefined) => (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
              <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#9ca3af' }}>{label}</span>
              <span style={{ fontSize: 14, color: value ? '#111827' : '#d1d5db', fontWeight: value ? 500 : 400 }}>
                {value || '—'}
              </span>
            </div>
          );

          const hasAnyProfile = !!(settings.business_name || settings.industry || settings.product || settings.audience || settings.goal);

          return (
            <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 8 }}>
                <h2 style={{ margin: 0, fontSize: 24, fontWeight: 700, color: '#111827' }}>Business Information</h2>
                <div style={{ display: 'flex', gap: 8 }}>
                  <button
                    onClick={() => {
                      setProfileRefreshing(true);
                      console.log('[CIQ BP] Manual Refresh clicked\u2026');
                      axios.get(api('business-profile'), { headers: { 'X-WP-Nonce': nonce } })
                        .then(r => {
                          console.log('[CIQ BP] Refresh response status:', r?.status);
                          console.log('[CIQ BP] Refresh raw data:', r?.data);
                          const nonEmpty = Object.fromEntries(
                            Object.entries(r.data).filter(([, v]) => v != null && v !== '')
                          );
                          console.log('[CIQ BP] Refresh non-empty fields:', nonEmpty);
                          if (Object.keys(nonEmpty).length > 0) {
                            setSettings(prev => ({ ...prev, ...nonEmpty }));
                            console.log('[CIQ BP] Refresh merged \u2713');
                          } else {
                            console.warn('[CIQ BP] Refresh: all fields null/empty');
                          }
                        })
                        .catch(err => console.error('[CIQ BP] Refresh failed:', err?.response?.status, err?.response?.data || err?.message))
                        .finally(() => setProfileRefreshing(false));
                    }}
                    disabled={profileRefreshing}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 14px', background: profileRefreshing ? '#e5e7eb' : '#f3f4f6', color: '#374151', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 14, fontWeight: 600, cursor: profileRefreshing ? 'wait' : 'pointer', transition: 'all 0.2s' }}
                  >
                    {profileRefreshing ? '⏳' : '↻'} {profileRefreshing ? 'Syncing…' : 'Refresh'}
                  </button>
                  <a
                    href="https://conversioniq-app.com/dashboard/profile"
                    target="_blank"
                    rel="noopener noreferrer"
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 18px', background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', textDecoration: 'none', borderRadius: 8, fontSize: 14, fontWeight: 600, boxShadow: '0 2px 8px rgba(124,58,237,0.3)', whiteSpace: 'nowrap', transition: 'opacity 0.2s' }}
                    onMouseEnter={(e) => (e.currentTarget.style.opacity = '0.85')}
                    onMouseLeave={(e) => (e.currentTarget.style.opacity = '1')}
                  >
                    Edit Profile
                  </a>
                </div>
              </div>
              <p style={{ color: '#6b7280', marginBottom: 24, fontSize: 15 }}>
                This profile is used by the AI to deliver personalized audit recommendations. To update it, visit your account at conversioniq-app.com.
              </p>

              {!hasAnyProfile && (
                <div style={{ marginBottom: 24, padding: '16px 20px', background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 10, display: 'flex', alignItems: 'center', gap: 12 }}>
                  <span style={{ fontSize: 20 }}>⚠️</span>
                  <div>
                    <div style={{ fontWeight: 600, color: '#92400e', marginBottom: 2 }}>Business profile is empty</div>
                    <div style={{ fontSize: 13, color: '#78350f' }}>
                      Complete your profile at{' '}
                      <a href="https://conversioniq-app.com/onboarding" target="_blank" rel="noopener noreferrer" style={{ color: '#92400e', fontWeight: 600 }}>conversioniq-app.com/onboarding</a>
                      {' '}to improve AI audit quality.
                    </div>
                  </div>
                </div>
              )}

              {/* Group: Your Business */}
              <div style={{ marginBottom: 20 }}>
                <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.07em', color: '#7c3aed', marginBottom: 12 }}>Your Business</div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '16px 24px', padding: '20px 24px', background: '#f9fafb', borderRadius: 10, border: '1px solid #f3f4f6' }}>
                  {profileField('Business Name', settings.business_name)}
                  {profileField('Industry / Niche', settings.industry)}
                  {profileField('What You Sell', settings.product)}
                </div>
              </div>

              {/* Group: Your Customers */}
              <div style={{ marginBottom: 20 }}>
                <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.07em', color: '#7c3aed', marginBottom: 12 }}>Your Customers</div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '16px 24px', padding: '20px 24px', background: '#f9fafb', borderRadius: 10, border: '1px solid #f3f4f6' }}>
                  {profileField('Target Audience', settings.audience)}
                  {profileField('Customer Pain Points', settings.pain_points)}
                  {profileField('Key Competitors', settings.competitors)}
                </div>
              </div>

              {/* Group: Goals & Market */}
              <div style={{ marginBottom: 20 }}>
                <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.07em', color: '#7c3aed', marginBottom: 12 }}>Goals & Market</div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '16px 24px', padding: '20px 24px', background: '#f9fafb', borderRadius: 10, border: '1px solid #f3f4f6' }}>
                  {profileField('Primary Conversion Goal', settings.goal)}
                  {profileField('Unique Selling Points', settings.unique_selling_points)}
                  {profileField('Target Geography', settings.target_geography)}
                </div>
              </div>

              {/* Group: Positioning */}
              <div style={{ marginBottom: 20 }}>
                <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.07em', color: '#7c3aed', marginBottom: 12 }}>Positioning</div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '16px 24px', padding: '20px 24px', background: '#f9fafb', borderRadius: 10, border: '1px solid #f3f4f6' }}>
                  {profileField('Price Point', settings.price_point)}
                  {profileField('Primary Traffic Source', settings.primary_traffic_source)}
                  {settings.additional_info && (
                    <div style={{ gridColumn: '1 / -1', ...{ display: 'flex', flexDirection: 'column', gap: 4 } as any }}>
                      <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#9ca3af' }}>Additional Notes</span>
                      <span style={{ fontSize: 14, color: '#111827', fontWeight: 500, lineHeight: 1.6 }}>{settings.additional_info}</span>
                    </div>
                  )}
                </div>
              </div>

              <div style={{ marginTop: 8, fontSize: 13, color: '#9ca3af', display: 'flex', alignItems: 'center', gap: 6 }}>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                Profile syncs automatically when you activate your license. Use the Refresh button to pull the latest changes manually.
              </div>
            </section>
          );
        })()}

        {/* Automated Reports Tab */}
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
                    • <strong>{automatedReporting.defaultPages.filter(id => pages.some(p => p.id === id)).length} page{automatedReporting.defaultPages.filter(id => pages.some(p => p.id === id)).length !== 1 ? 's' : ''}</strong> will be audited<br />
                    • Results will be emailed to <strong>{automatedReporting.email || 'your email'}</strong>
                  </div>
                </div>

                {/* Test Email Section */}
                <div style={{ marginTop: 32, paddingTop: 32, borderTop: '2px solid #e5e7eb' }}>
                  <h3 style={{ margin: '0 0 12px 0', fontSize: 18, fontWeight: 700, color: '#111827' }}>Test Email Delivery</h3>
                  <p style={{ fontSize: 14, color: '#6b7280', marginBottom: 16 }}>
                    Send a test email to verify your email configuration is working correctly.
                  </p>
                  <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                    <div style={{ flex: 1 }}>
                      <input
                        type="email"
                        placeholder={automatedReporting.email || "Enter email address..."}
                        value={testEmail}
                        onChange={(e) => setTestEmail(e.target.value)}
                        style={{ 
                          width: '100%', 
                          padding: '12px 16px', 
                          border: '1px solid #d1d5db', 
                          borderRadius: 8, 
                          fontSize: 14, 
                          outline: 'none', 
                          background: '#fff', 
                          color: '#111827' 
                        }}
                        onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                        onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                      />
                      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: 6 }}>
                        Leave blank to use: {automatedReporting.email || 'no email configured'}
                      </p>
                    </div>
                    <button
                      onClick={handleTestEmail}
                      disabled={testEmailLoading || (!testEmail && !automatedReporting.email)}
                      style={{
                        padding: '12px 24px',
                        background: testEmailLoading || (!testEmail && !automatedReporting.email) ? '#d1d5db' : '#10b981',
                        color: '#fff',
                        border: 'none',
                        borderRadius: 8,
                        fontSize: 15,
                        fontWeight: 600,
                        cursor: testEmailLoading || (!testEmail && !automatedReporting.email) ? 'not-allowed' : 'pointer',
                        transition: 'all 0.2s',
                        whiteSpace: 'nowrap',
                        minWidth: 140
                      }}
                      onMouseEnter={(e) => !testEmailLoading && (testEmail || automatedReporting.email) && (e.currentTarget.style.background = '#059669')}
                      onMouseLeave={(e) => !testEmailLoading && (testEmail || automatedReporting.email) && (e.currentTarget.style.background = '#10b981')}
                    >
                      {testEmailLoading ? 'Sending...' : '📧 Send Test Email'}
                    </button>
                  </div>
                </div>

                {/* Send Manual Report Section */}
                <div style={{ marginTop: 32, paddingTop: 32, borderTop: '2px solid #e5e7eb' }}>
                  <h3 style={{ margin: '0 0 12px 0', fontSize: 18, fontWeight: 700, color: '#111827' }}>Send Audit Report with Real Results</h3>
                  <p style={{ fontSize: 14, color: '#6b7280', marginBottom: 16 }}>
                    Send an actual audit report email with real results from the pages selected in "Default Pages to Audit" above. If audits don't exist yet, they'll be generated automatically.
                  </p>

                  {/* Warning if no pages selected */}
                  {automatedReporting.defaultPages.length === 0 && (
                    <div style={{ 
                      background: '#fef3c7', 
                      border: '1px solid #f59e0b', 
                      borderRadius: 8, 
                      padding: 12, 
                      marginBottom: 16,
                      fontSize: 14,
                      color: '#92400e'
                    }}>
                      ⚠️ No pages selected. Please select pages in "Default Pages to Audit" above.
                    </div>
                  )}


                  
                  {/* Email Input */}
                  <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                    <div style={{ flex: 1 }}>
                      <input
                        type="text"
                        placeholder={automatedReporting.email || "Enter email address(es)..."}
                        value={manualReportEmail}
                        onChange={(e) => setManualReportEmail(e.target.value)}
                        style={{ 
                          width: '100%', 
                          padding: '12px 16px', 
                          border: '1px solid #d1d5db', 
                          borderRadius: 8, 
                          fontSize: 14, 
                          outline: 'none', 
                          background: '#fff', 
                          color: '#111827' 
                        }}
                        onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                        onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                      />
                      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: 6 }}>
                        Multiple emails separated by commas. Leave blank to use: {automatedReporting.email || 'no email configured'}
                      </p>
                    </div>
                    <button
                      onClick={handleSendManualReport}
                      disabled={manualReportLoading || automatedReporting.defaultPages.length === 0 || (!manualReportEmail && !automatedReporting.email)}
                      style={{
                        padding: '12px 24px',
                        background: manualReportLoading || automatedReporting.defaultPages.length === 0 || (!manualReportEmail && !automatedReporting.email) ? '#d1d5db' : '#2563eb',
                        color: '#fff',
                        border: 'none',
                        borderRadius: 8,
                        fontSize: 15,
                        fontWeight: 600,
                        cursor: manualReportLoading || automatedReporting.defaultPages.length === 0 || (!manualReportEmail && !automatedReporting.email) ? 'not-allowed' : 'pointer',
                        transition: 'all 0.2s',
                        whiteSpace: 'nowrap',
                        minWidth: 180
                      }}
                      onMouseEnter={(e) => !manualReportLoading && automatedReporting.defaultPages.length > 0 && (manualReportEmail || automatedReporting.email) && (e.currentTarget.style.background = '#1d4ed8')}
                      onMouseLeave={(e) => !manualReportLoading && automatedReporting.defaultPages.length > 0 && (manualReportEmail || automatedReporting.email) && (e.currentTarget.style.background = '#2563eb')}
                    >
                      {manualReportLoading ? 'Sending Report...' : `📊 Send Report (${automatedReporting.defaultPages.length} page${automatedReporting.defaultPages.length !== 1 ? 's' : ''})`}
                    </button>
                  </div>

                  {/* Status Log */}
                  {(manualReportLog.length > 0 || manualReportLoading) && (
                    <div style={{ 
                      marginTop: 16, 
                      background: '#1f2937', 
                      borderRadius: 8, 
                      padding: 16, 
                      maxHeight: 300, 
                      overflow: 'auto',
                      fontFamily: 'Monaco, Courier, monospace',
                      fontSize: 13,
                      lineHeight: 1.8
                    }}>
                      <div style={{ 
                        color: '#10b981', 
                        fontWeight: 600, 
                        marginBottom: 12, 
                        paddingBottom: 8, 
                        borderBottom: '1px solid #374151' 
                      }}>
                        📋 Status Log
                      </div>
                      {manualReportLoading && manualReportLog.length === 0 && (
                        <div style={{ color: '#fbbf24', animation: 'pulse 2s infinite' }}>
                          ⏳ Initializing...
                        </div>
                      )}
                      {manualReportLog.map((log, index) => (
                        <div 
                          key={index} 
                          style={{ 
                            color: log.includes('❌') ? '#ef4444' : log.includes('✅') ? '#10b981' : '#d1d5db',
                            marginBottom: 6,
                            paddingLeft: log.startsWith('  ') ? 20 : 0
                          }}
                        >
                          {log}
                        </div>
                      ))}
                      {manualReportLoading && (
                        <div style={{ color: '#60a5fa', marginTop: 8 }}>
                          <span style={{ display: 'inline-block', animation: 'pulse 1.5s infinite' }}>⚡ Processing...</span>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </>
            )}

            <button
              className="ciq-btn primary"
              onClick={handleSaveAutomatedSettings}
              disabled={savingAutomated || (automatedReporting.enabled && (automatedReporting.defaultPages.length === 0 || !automatedReporting.email))}
              style={{
                marginTop: 24,
                padding: '12px 24px',
                background: (automatedReporting.enabled && (automatedReporting.defaultPages.length === 0 || !automatedReporting.email)) ? '#d1d5db' : '#7c3aed',
                color: '#fff',
                border: 'none',
                borderRadius: 8,
                fontSize: 15,
                fontWeight: 600,
                cursor: (savingAutomated || (automatedReporting.enabled && (automatedReporting.defaultPages.length === 0 || !automatedReporting.email))) ? 'not-allowed' : 'pointer',
                transition: 'all 0.2s'
              }}
              onMouseEnter={(e) => !savingAutomated && !(automatedReporting.enabled && (automatedReporting.defaultPages.length === 0 || !automatedReporting.email)) && (e.currentTarget.style.background = '#6d28d9')}
              onMouseLeave={(e) => !savingAutomated && !(automatedReporting.enabled && (automatedReporting.defaultPages.length === 0 || !automatedReporting.email)) && (e.currentTarget.style.background = '#7c3aed')}
            >
              {savingAutomated ? 'Saving...' : 'Save Automated Settings'}
            </button>
          </section>
        )}

        {/* Audits Tab */}
        {activeTab === 'audits' && (
          <>
            {/* Pages to Analyze Section */}
            <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32, marginBottom: 24 }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, marginBottom: 8 }}>
                <h2 style={{ margin: 0, fontSize: 24, fontWeight: 700, color: '#111827' }}>Select Pages to Analyze</h2>
                <span style={{ padding: '4px 12px', background: selectedPages.length >= maxPagesPerAudit ? '#fef3c7' : '#f3e8ff', color: selectedPages.length >= maxPagesPerAudit ? '#92400e' : '#5b21b6', borderRadius: 20, fontSize: 13, fontWeight: 600, border: `1px solid ${selectedPages.length >= maxPagesPerAudit ? '#fcd34d' : '#c4b5fd'}` }}>
                  {selectedPages.length} / {maxPagesPerAudit} pages selected
                </span>
              </div>
              {/* Plan limits info strip — always visible */}
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8, marginBottom: 16, padding: '10px 16px', background: '#f5f3ff', border: '1px solid #ede9fe', borderRadius: 8 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', fontSize: 13, color: '#5b21b6' }}>
                  <span>
                    <strong style={{ textTransform: 'capitalize' }}>{currentPlan}</strong> plan
                  </span>
                  <span style={{ width: 1, height: 14, background: '#c4b5fd', display: 'inline-block' }} />
                  <span>Up to <strong>{maxPagesPerAudit} page{maxPagesPerAudit !== 1 ? 's' : ''}</strong> per audit</span>
                  <span style={{ width: 1, height: 14, background: '#c4b5fd', display: 'inline-block' }} />
                  <span><strong>{(liveFeatures.audits_per_week as number) || 3} audits</strong> per week</span>
                </div>
                {currentPlan !== 'agency' && (
                  <button onClick={() => setActiveTab('license')} style={{ background: 'none', color: '#7c3aed', border: '1px solid #c4b5fd', borderRadius: 6, padding: '4px 12px', fontSize: 12, fontWeight: 600, cursor: 'pointer' }}>
                    Upgrade plan →
                  </button>
                )}
              </div>
              <p style={{ color: '#6b7280', marginBottom: selectedPages.length >= maxPagesPerAudit ? 12 : 20, fontSize: 15 }}>Choose which pages you want to audit now.</p>
              {selectedPages.length >= maxPagesPerAudit && (
                <div style={{ marginBottom: 16, padding: '10px 16px', background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 8, fontSize: 13, color: '#92400e', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
                  <span>🔒 Page limit reached for your <strong>{currentPlan.charAt(0).toUpperCase() + currentPlan.slice(1)}</strong> plan.</span>
                  <button onClick={() => setActiveTab('license')} style={{ background: '#7c3aed', color: '#fff', border: 'none', borderRadius: 6, padding: '6px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>Upgrade Plan →</button>
                </div>
              )}
              <div style={{ maxHeight: 240, overflow: 'auto', border: '1px solid #d1d5db', borderRadius: 8, padding: 16, background: '#f9fafb' }}>
                {pages.length === 0 ? (
                  <div style={{ color: '#9ca3af', textAlign: 'center', padding: 20 }}>No pages found. Please publish some pages first.</div>
                ) : (
                  pages.map(p => (
                    <label key={p.id} style={{ display: 'flex', alignItems: 'center', padding: '10px 12px', marginBottom: 8, background: selectedPages.includes(p.id) ? '#f3e8ff' : '#fff', borderRadius: 6, cursor: 'pointer', transition: 'all 0.2s', border: '1px solid transparent' }} onMouseEnter={(e) => e.currentTarget.style.borderColor = '#a78bfa'} onMouseLeave={(e) => !selectedPages.includes(p.id) && (e.currentTarget.style.borderColor = 'transparent')}>
                      <input type="checkbox" checked={selectedPages.includes(p.id)} onChange={() => handlePageSelect(p.id)} disabled={!selectedPages.includes(p.id) && selectedPages.length >= maxPagesPerAudit} style={{ marginRight: 12, width: 18, height: 18, cursor: !selectedPages.includes(p.id) && selectedPages.length >= maxPagesPerAudit ? 'not-allowed' : 'pointer', accentColor: '#7c3aed' }} />
                      <span style={{ flex: 1, fontWeight: 500, color: '#111827' }}>{p.title}</span>
                      <span style={{ color: '#9ca3af', fontSize: 13 }}>ID: {p.id}</span>
                    </label>
                  ))
                )}
              </div>

              {/* Selected Pages List */}
              {selectedPages.length > 0 && (
                <div style={{ marginTop: 16, padding: 16, background: '#f3e8ff', borderRadius: 8, border: '1px solid #c4b5fd' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, color: '#5b21b6', marginBottom: 8 }}>
                    ✓ {selectedPages.length} page{selectedPages.length !== 1 ? 's' : ''} selected for audit:
                  </div>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    {selectedPages.map(pageId => {
                      const page = pages.find(p => p.id === pageId);
                      return page ? (
                        <div key={pageId} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', background: '#fff', borderRadius: 6, fontSize: 13, fontWeight: 500, color: '#5b21b6', boxShadow: '0 1px 2px rgba(0,0,0,0.05)', border: '1px solid #e9d5ff' }}>
                          {page.title}
                          <button
                            onClick={() => handlePageSelect(pageId)}
                            style={{ background: 'none', border: 'none', color: '#ef4444', cursor: 'pointer', padding: 0, marginLeft: 4, fontSize: 16, lineHeight: 1 }}
                            title="Remove"
                          >
                            ×
                          </button>
                        </div>
                      ) : null;
                    })}
                  </div>
                </div>
              )}

              <button className="ciq-btn primary" style={{ marginTop: 20, padding: '14px 32px', background: '#7c3aed', color: '#fff', border: 'none', borderRadius: 8, fontSize: 16, fontWeight: 600, cursor: loading || selectedPages.length === 0 ? 'not-allowed' : 'pointer', opacity: loading || selectedPages.length === 0 ? 0.6 : 1, transition: 'all 0.2s' }} onClick={handleRunAudit} disabled={loading || selectedPages.length === 0} onMouseEnter={(e) => !loading && selectedPages.length > 0 && (e.currentTarget.style.background = '#6d28d9')} onMouseLeave={(e) => !loading && selectedPages.length > 0 && (e.currentTarget.style.background = '#7c3aed')}>
                {loading ? 'Running Audit...' : `Run Audit${selectedPages.length > 0 ? ` (${selectedPages.length} page${selectedPages.length !== 1 ? 's' : ''})` : ''}`}
              </button>
            </section>

            {/* Audit Results Section */}
            <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
              <h2 style={{ margin: '0 0 20px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>Audit Results</h2>
              <div style={{ display: 'flex', alignItems: 'center', marginBottom: 24 }}>
                <div style={{ width: 40, height: 40, borderRadius: 10, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', display: 'flex', alignItems: 'center', justifyContent: 'center', marginRight: 12 }}>
                  <span style={{ fontSize: 20 }}>📊</span>
                </div>
                <h2 style={{ margin: 0, fontSize: 24, fontWeight: 700, color: '#1f2937' }}>Audit Results</h2>
              </div>
              
              {audits.length > 0 && (
                <div style={{ marginBottom: 24, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
                  <input
                    type="text"
                    placeholder="🔍 Search by page title..."
                    value={auditSearchQuery}
                    onChange={(e) => setAuditSearchQuery(e.target.value)}
                    style={{ flex: '1 1 300px', padding: '10px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                  />
                  <div style={{ display: 'flex', gap: 8 }}>
                    <button
                      onClick={() => setAuditFilter('all')}
                      style={{
                        padding: '10px 20px',
                        border: 'none',
                        borderRadius: 8,
                        fontSize: 14,
                        fontWeight: 600,
                        cursor: 'pointer',
                        background: auditFilter === 'all' ? 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)' : '#f3f4f6',
                        color: auditFilter === 'all' ? '#fff' : '#6b7280',
                        transition: 'all 0.2s'
                      }}
                    >
                      All ({audits.length})
                    </button>
                    <button
                      onClick={() => setAuditFilter('ai')}
                      style={{
                        padding: '10px 20px',
                        border: 'none',
                        borderRadius: 8,
                        fontSize: 14,
                        fontWeight: 600,
                        cursor: 'pointer',
                        background: auditFilter === 'ai' ? 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)' : '#f3f4f6',
                        color: auditFilter === 'ai' ? '#fff' : '#6b7280',
                        transition: 'all 0.2s'
                      }}
                    >
                      AI Powered ({audits.filter(a => a.ai_used !== false).length})
                    </button>
                    <button
                      onClick={() => setAuditFilter('fallback')}
                      style={{
                        padding: '10px 20px',
                        border: 'none',
                        borderRadius: 8,
                        fontSize: 14,
                        fontWeight: 600,
                        cursor: 'pointer',
                        background: auditFilter === 'fallback' ? 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)' : '#f3f4f6',
                        color: auditFilter === 'fallback' ? '#fff' : '#6b7280',
                        transition: 'all 0.2s'
                      }}
                    >
                      Fallback ({audits.filter(a => a.ai_used === false).length})
                    </button>
                  </div>
                </div>
              )}
              
              {(() => {
                // Filter audits
                let filteredAudits = audits.filter(a => {
                  const matchesFilter = auditFilter === 'all' || 
                    (auditFilter === 'ai' && a.ai_used !== false) ||
                    (auditFilter === 'fallback' && a.ai_used === false);
                  const matchesSearch = !auditSearchQuery || 
                    (a.page_title || '').toLowerCase().includes(auditSearchQuery.toLowerCase());
                  return matchesFilter && matchesSearch;
                });
                
                // Group by date
                const groupedByDate: { [key: string]: typeof audits } = {};
                filteredAudits.forEach(audit => {
                  const date = audit.created_at ? new Date(audit.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Unknown Date';
                  if (!groupedByDate[date]) groupedByDate[date] = [];
                  groupedByDate[date].push(audit);
                });
                
                const dateGroups = Object.entries(groupedByDate).sort((a, b) => 
                  new Date(b[0]).getTime() - new Date(a[0]).getTime()
                );
                
                if (filteredAudits.length === 0 && audits.length === 0) {
                  return <div style={{ color: '#9ca3af', textAlign: 'center', padding: 40, background: '#f9fafb', borderRadius: 12 }}>No audits yet. Select pages above and run your first audit!</div>;
                }
                
                if (filteredAudits.length === 0) {
                  return <div style={{ color: '#9ca3af', textAlign: 'center', padding: 40, background: '#f9fafb', borderRadius: 12 }}>No audits match your filter criteria.</div>;
                }
                
                return dateGroups.map(([date, dateAudits]) => (
                  <div key={date} style={{ marginBottom: 32 }}>
                    <h3 style={{ fontSize: 16, fontWeight: 600, color: '#6b7280', marginBottom: 16, paddingBottom: 8, borderBottom: '2px solid #e5e7eb' }}>
                      {date}
                    </h3>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(380px, 1fr))', gap: 20 }}>
                      {dateAudits.map((a, i) => (
                  <div key={i} style={{ border: '1px solid #e5e7eb', borderRadius: 12, padding: 20, background: '#fff', transition: 'all 0.2s', boxShadow: '0 1px 3px rgba(0,0,0,0.05)' }} onMouseEnter={(e) => { e.currentTarget.style.borderColor = '#a78bfa'; e.currentTarget.style.boxShadow = '0 4px 12px rgba(124, 58, 237, 0.15)'; }} onMouseLeave={(e) => { e.currentTarget.style.borderColor = '#e5e7eb'; e.currentTarget.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)'; }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                <div>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#111827' }}>{a.page_title || 'Unknown Page'}</h3>
                  {a.created_at && (
                    <div style={{ fontSize: 13, color: '#6b7280', marginTop: 4 }}>
                      {new Date(a.created_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })}
                    </div>
                  )}
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  {a.content_changed === false && (
                    <div title="Page content unchanged from previous audit - scores may vary due to AI analysis" style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, padding: '6px 12px', borderRadius: 8, background: '#fef3c7', color: '#92400e', border: '1px solid #fbbf24' }}>
                      <span>⚠️</span>
                      <span>Page Unchanged</span>
                    </div>
                  )}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 14, fontWeight: 600, padding: '6px 12px', borderRadius: 8, background: a.ai_used === false ? '#fef3c7' : '#f3e8ff', color: a.ai_used === false ? '#92400e' : '#7c3aed' }}>
                    <span>{a.ai_used === false ? '⚠' : '✓'}</span>
                    <span>{a.ai_used === false ? 'Fallback' : 'AI Powered'}</span>
                  </div>
                </div>
              </div>
              
              {/* Overall Score Hero */}
              {(() => {
                const os = a.overall_score || Math.round(((a.clarity_score || 0) * 0.20 + (a.emotional_score || 0) * 0.15 + (a.cta_strength || 0) * 0.20 + (a.readability_score || 0) * 0.15 + (a.engagement_score || 0) * 0.15 + (a.trust_score || 0) * 0.15));
                const scoreColor = os >= 75 ? '#10b981' : os >= 50 ? '#f59e0b' : '#ef4444';
                return os > 0 ? (
                  <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16, padding: '16px 20px', background: `linear-gradient(135deg, ${scoreColor}10, ${scoreColor}08)`, borderRadius: 12, border: `1px solid ${scoreColor}30` }}>
                    <div style={{ width: 56, height: 56, borderRadius: '50%', background: `conic-gradient(${scoreColor} ${os * 3.6}deg, #e5e7eb ${os * 3.6}deg)`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                      <div style={{ width: 44, height: 44, borderRadius: '50%', background: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 18, color: scoreColor }}>{os}</div>
                    </div>
                    <div>
                      <div style={{ fontSize: 14, fontWeight: 700, color: '#111827' }}>Overall Score</div>
                      <div style={{ fontSize: 12, color: '#6b7280' }}>{os >= 75 ? 'Great — high conversion potential' : os >= 50 ? 'Needs work — room to improve' : 'Critical — significant issues found'}</div>
                    </div>
                  </div>
                ) : null;
              })()}

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: 12, marginBottom: 16 }}>
                <div style={{ textAlign: 'center', padding: 12, background: 'linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)', borderRadius: 10 }}>
                  <div style={{ fontSize: 12, color: '#64748b', marginBottom: 4, fontWeight: 500 }}>Conversion Clarity</div>
                  <div style={{ fontSize: 24, fontWeight: 700, color: '#2563eb' }}>{a.clarity_score || 0}</div>
                </div>
                <div style={{ textAlign: 'center', padding: 12, background: 'linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%)', borderRadius: 10 }}>
                  <div style={{ fontSize: 12, color: '#64748b', marginBottom: 4, fontWeight: 500 }}>Emotional Resonance</div>
                  <div style={{ fontSize: 24, fontWeight: 700, color: '#f59e0b' }}>{a.emotional_score || 0}</div>
                </div>
                <div style={{ textAlign: 'center', padding: 12, background: 'linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)', borderRadius: 10 }}>
                  <div style={{ fontSize: 12, color: '#64748b', marginBottom: 4, fontWeight: 500 }}>CTA</div>
                  <div style={{ fontSize: 24, fontWeight: 700, color: '#10b981' }}>{a.cta_strength || 0}</div>
                </div>
                <div style={{ textAlign: 'center', padding: 12, background: 'linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%)', borderRadius: 10 }}>
                  <div style={{ fontSize: 12, color: '#64748b', marginBottom: 4, fontWeight: 500 }}>Readability</div>
                  <div style={{ fontSize: 24, fontWeight: 700, color: '#9333ea' }}>{a.readability_score || 0}</div>
                </div>
                <div style={{ textAlign: 'center', padding: 12, background: 'linear-gradient(135deg, #fefce8 0%, #fef3c7 100%)', borderRadius: 10 }}>
                  <div style={{ fontSize: 12, color: '#64748b', marginBottom: 4, fontWeight: 500 }}>Engagement</div>
                  <div style={{ fontSize: 24, fontWeight: 700, color: '#d97706' }}>{a.engagement_score || 0}</div>
                </div>
                <div style={{ textAlign: 'center', padding: 12, background: 'linear-gradient(135deg, #ecfeff 0%, #cffafe 100%)', borderRadius: 10 }}>
                  <div style={{ fontSize: 12, color: '#64748b', marginBottom: 4, fontWeight: 500 }}>Trust</div>
                  <div style={{ fontSize: 24, fontWeight: 700, color: '#0891b2' }}>{a.trust_score || 0}</div>
                </div>
              </div>
              
              {a.recommendations?.priority && (
                <div style={{ marginBottom: 16, padding: 16, background: 'linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%)', borderRadius: 10, borderLeft: '4px solid #f59e0b' }}>
                  <div style={{ fontSize: 12, fontWeight: 700, color: '#f59e0b', marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.5 }}>🔥 Priority Recommendation</div>
                  <div style={{ fontSize: 14, color: '#374151', lineHeight: 1.6, fontWeight: 500 }}>
                    {typeof a.recommendations.priority === 'string' ? a.recommendations.priority : a.recommendations.priority.text}
                  </div>
                </div>
              )}
              
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                <div style={{ display: 'flex', gap: 12 }}>
                  {a.ai_used === false ? (
                    <button 
                      className="ciq-btn" 
                      onClick={async () => {
                        if (!a.page_id) return;
                        setLoading(true);
                        setNotice('🔄 Retrying audit...');
                        try {
                          const response = await axios.post(
                            api('audit'), 
                            { pages: [a.page_id] }, 
                            { headers: { 'X-WP-Nonce': nonce } }
                          );
                          
                          if (response.data.success && response.data.results) {
                            const newAudit = response.data.results[0];
                            // Replace the failed audit with the new one
                            setAudits(audits => audits.map(audit => 
                              audit.insert_id === a.insert_id ? newAudit : audit
                            ));
                            setNotice('✅ Audit completed successfully!');
                          } else {
                            throw new Error('Retry failed');
                          }
                        } catch (err: any) {
                          console.error('❌ Retry failed:', err);
                          setNotice('Retry failed - please try again later.');
                        } finally {
                          setLoading(false);
                        }
                      }}
                      disabled={loading}
                      style={{ 
                        flex: 1, 
                        padding: '12px 20px', 
                        background: loading ? '#d1d5db' : 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)', 
                        color: '#fff', 
                        border: 'none', 
                        borderRadius: 10, 
                        fontSize: 15, 
                        fontWeight: 600, 
                        cursor: loading ? 'not-allowed' : 'pointer', 
                        boxShadow: '0 4px 12px rgba(245, 158, 11, 0.3)', 
                        transition: 'transform 0.2s' 
                      }} 
                      onMouseEnter={(e) => !loading && (e.currentTarget.style.transform = 'translateY(-2px)')} 
                      onMouseLeave={(e) => !loading && (e.currentTarget.style.transform = 'translateY(0)')}
                    >
                      🔄 Retry Audit
                    </button>
                  ) : (
                    a.report_token ? (
                      <a
                        href={`https://conversioniq-app.com/reports/${a.report_token}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8, width: '100%', padding: '14px 20px', background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', borderRadius: 10, fontSize: 15, fontWeight: 600, textDecoration: 'none', boxShadow: '0 4px 12px rgba(124, 58, 237, 0.3)', transition: 'transform 0.2s' }}
                        onMouseEnter={(e) => e.currentTarget.style.transform = 'translateY(-2px)'}
                        onMouseLeave={(e) => e.currentTarget.style.transform = 'translateY(0)'}
                      >
                        View Full Report →
                      </a>
                    ) : null
                  )}
              </div>
            </div>
                  </div>
                      ))}
                    </div>
                  </div>
                ));
              })()}
            </section>
          </>
        )}

        {/* KnockKnock Tab */}
        {activeTab === 'knockknock' && (
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <div style={{ marginBottom: 32 }}>
              <h2 style={{ margin: '0 0 8px 0', fontSize: 28, fontWeight: 700, color: '#111827' }}>
                KnockKnock
              </h2>
              <p style={{ color: '#6b7280', fontSize: 15, margin: 0 }}>
                Track visitor engagement and lead conversion with advanced analytics and real-time insights
              </p>
            </div>

            {!canUse('knockknock') && (
              <div style={{ textAlign: 'center', padding: '60px 40px', background: 'linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%)', borderRadius: 16, border: '2px dashed #a78bfa' }}>
                <div style={{ fontSize: 48, marginBottom: 16 }}>�</div>
                <h3 style={{ margin: '0 0 12px 0', fontSize: 22, fontWeight: 700, color: '#4c1d95' }}>Know who's actually on your site</h3>
                <p style={{ color: '#6d28d9', fontSize: 15, maxWidth: 520, margin: '0 auto 8px' }}>
                  Right now you can see <strong>what's wrong</strong> with your pages. KnockKnock tells you <strong>who's reading them</strong> — real company names, job titles, and contact details for anonymous visitors.
                </p>
                <p style={{ color: '#7c3aed', fontSize: 14, maxWidth: 480, margin: '0 auto 24px', lineHeight: 1.6 }}>
                  Instead of guessing who your traffic is, you'll know exactly which companies are considering you — and reach out before they go to a competitor.
                </p>
                <div style={{ display: 'flex', justifyContent: 'center', gap: 32, marginBottom: 28, flexWrap: 'wrap' }}>
                  {[
                    { icon: '🏢', label: 'Company intelligence' },
                    { icon: '👤', label: 'Visitor identification' },
                    { icon: '⚡', label: 'Real-time alerts' },
                  ].map(({ icon, label }) => (
                    <div key={label} style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#5b21b6', fontSize: 14, fontWeight: 600 }}>
                      <span style={{ fontSize: 20 }}>{icon}</span> {label}
                    </div>
                  ))}
                </div>
                <button onClick={() => setActiveTab('license')} style={{ background: '#7c3aed', color: '#fff', border: 'none', borderRadius: 8, padding: '14px 32px', fontSize: 16, fontWeight: 600, cursor: 'pointer' }}>
                  Unlock on Business or Agency →
                </button>
                <div style={{ marginTop: 12, fontSize: 12, color: '#8b5cf6' }}>Available on Business ($249/mo) and Agency ($449/mo)</div>
              </div>
            )}

            {canUse('knockknock') && (<>
            {/* Statistics Cards */}
            {(knockKnockCompanyId || knockKnockWebhookSecret) && knockKnockLeads.length > 0 && (() => {
              const thisMonth  = visitorTrend[0]  ?? { visitors: 0, leads: 0, label: 'This Month' };
              const lastMonth  = visitorTrend[1]  ?? { visitors: 0, leads: 0, label: 'Last Month' };
              const visitorDelta = thisMonth.visitors - lastMonth.visitors;
              const leadDelta    = thisMonth.leads    - lastMonth.leads;
              const deltaStyle = (n: number) => ({ color: n >= 0 ? '#bbf7d0' : '#fecaca', fontSize: 12, fontWeight: 600 });
              const deltaLabel = (n: number) => n === 0 ? '— same as last month' : `${n > 0 ? '▲' : '▼'} ${Math.abs(n)} vs last month`;
              return (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 20, marginBottom: 32 }}>
                <div style={{ background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', borderRadius: 12, padding: 24, color: '#fff' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, opacity: 0.9, marginBottom: 8 }}>Total Interactions</div>
                  <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 4 }}>{knockKnockLeads.length}</div>
                  <div style={{ fontSize: 13, opacity: 0.8 }}>All time tracking</div>
                </div>
                <div style={{ background: 'linear-gradient(135deg, #10b981 0%, #059669 100%)', borderRadius: 12, padding: 24, color: '#fff' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, opacity: 0.9, marginBottom: 8 }}>Leads — {thisMonth.label}</div>
                  <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 4 }}>{thisMonth.leads}</div>
                  <div style={deltaStyle(leadDelta)}>{deltaLabel(leadDelta)}</div>
                </div>
                <div style={{ background: 'linear-gradient(135deg, #3b82f6 0%, #1e40af 100%)', borderRadius: 12, padding: 24, color: '#fff' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, opacity: 0.9, marginBottom: 8 }}>Identified Visitors — {thisMonth.label}</div>
                  <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 4 }}>{thisMonth.visitors}</div>
                  <div style={deltaStyle(visitorDelta)}>{deltaLabel(visitorDelta)}</div>
                </div>
                <div style={{ background: 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)', borderRadius: 12, padding: 24, color: '#fff' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, opacity: 0.9, marginBottom: 8 }}>Today</div>
                  <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 4 }}>
                    {knockKnockLeads.filter(l => {
                      const today = new Date().toDateString();
                      return new Date(l.timestamp).toDateString() === today;
                    }).length}
                  </div>
                  <div style={{ fontSize: 13, opacity: 0.8 }}>New interactions</div>
                </div>
              </div>
              );
            })()}

            {/* Configuration Section */}
            <div style={{ background: '#f9fafb', borderRadius: 12, padding: 24, marginBottom: 32, border: '1px solid #e5e7eb' }}>
              <h3 style={{ margin: '0 0 20px 0', fontSize: 20, fontWeight: 600, color: '#111827' }}>⚙️ Webhook Configuration</h3>
              
              <div style={{ display: 'grid', gap: 20 }}>
                {/* Company ID */}
                <div>
                  <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>
                    Client Company ID {!knockKnockWebhookSecret && <span style={{ color: '#ef4444' }}>*</span>}
                  </label>
                  <input
                    type="text"
                    placeholder="Enter your KnockKnock Company ID"
                    value={knockKnockCompanyId}
                    onChange={(e) => setKnockKnockCompanyId(e.target.value)}
                    style={{ 
                      width: '100%', 
                      padding: '12px 16px', 
                      border: '1px solid #d1d5db', 
                      borderRadius: 8, 
                      fontSize: 14, 
                      outline: 'none', 
                      transition: 'border 0.2s',
                      background: '#fff',
                      color: '#111827'
                    }}
                    onFocus={(e) => e.currentTarget.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.currentTarget.style.borderColor = '#d1d5db'}
                  />
                  <p style={{ fontSize: 12, color: '#6b7280', marginTop: 6, marginBottom: 0 }}>
                    Optional if webhook secret is configured
                  </p>
                </div>

                {/* Webhook Secret */}
                <div>
                  <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>
                    Webhook Secret Key {!knockKnockCompanyId && <span style={{ color: '#ef4444' }}>*</span>}
                  </label>
                  <div style={{ position: 'relative' }}>
                    <input
                      type={showKnockKnockSecret ? 'text' : 'password'}
                      placeholder="Enter webhook secret for HMAC validation"
                      value={knockKnockWebhookSecret}
                      onChange={(e) => setKnockKnockWebhookSecret(e.target.value)}
                      style={{ 
                        width: '100%', 
                        padding: '12px 40px 12px 16px', 
                        border: '1px solid #d1d5db', 
                        borderRadius: 8, 
                        fontSize: 14, 
                        outline: 'none', 
                        transition: 'border 0.2s',
                        background: '#fff',
                        color: '#111827',
                        fontFamily: showKnockKnockSecret ? 'monospace' : 'inherit'
                      }}
                      onFocus={(e) => e.currentTarget.style.borderColor = '#7c3aed'}
                      onBlur={(e) => e.currentTarget.style.borderColor = '#d1d5db'}
                    />
                    <button
                      onClick={() => setShowKnockKnockSecret(!showKnockKnockSecret)}
                      style={{
                        position: 'absolute',
                        right: 12,
                        top: '50%',
                        transform: 'translateY(-50%)',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        padding: 4,
                        fontSize: 18,
                        color: '#6b7280'
                      }}
                      title={showKnockKnockSecret ? 'Hide' : 'Show'}
                    >
                      {showKnockKnockSecret ? '👁️' : '👁️‍🗨️'}
                    </button>
                  </div>
                  <p style={{ fontSize: 12, color: '#6b7280', marginTop: 6, marginBottom: 0 }}>
                    <strong>Recommended:</strong> HMAC signature validation for secure webhooks
                  </p>
                </div>

                {/* Webhook URL */}
                <div>
                  <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>
                    Webhook Endpoint URL
                  </label>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <input
                      type="text"
                      value={knockKnockWebhookUrl}
                      readOnly
                      style={{ 
                        flex: 1, 
                        padding: '12px 16px', 
                        border: '1px solid #d1d5db', 
                        borderRadius: 8, 
                        fontSize: 13, 
                        background: '#f9fafb',
                        color: '#111827',
                        fontFamily: 'monospace'
                      }}
                    />
                    <button
                      onClick={copyKnockKnockUrl}
                      style={{
                        padding: '12px 20px',
                        background: '#7c3aed',
                        color: '#fff',
                        border: 'none',
                        borderRadius: 8,
                        fontSize: 14,
                        fontWeight: 600,
                        cursor: 'pointer',
                        whiteSpace: 'nowrap',
                        transition: 'background 0.2s'
                      }}
                      onMouseEnter={(e) => e.currentTarget.style.background = '#6d28d9'}
                      onMouseLeave={(e) => e.currentTarget.style.background = '#7c3aed'}
                    >
                      📋 Copy
                    </button>
                  </div>
                  <p style={{ fontSize: 12, color: '#6b7280', marginTop: 6, marginBottom: 0 }}>
                    Configure this URL in your KnockKnock webhook settings
                  </p>
                </div>

                {/* Save Button */}
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 8 }}>
                  <button
                    onClick={handleSaveKnockKnockSettings}
                    disabled={loading}
                    style={{
                      padding: '12px 32px',
                      background: loading ? '#d1d5db' : '#10b981',
                      color: '#fff',
                      border: 'none',
                      borderRadius: 8,
                      fontSize: 15,
                      fontWeight: 600,
                      cursor: loading ? 'not-allowed' : 'pointer',
                      transition: 'all 0.2s'
                    }}
                    onMouseEnter={(e) => !loading && (e.currentTarget.style.background = '#059669')}
                    onMouseLeave={(e) => !loading && (e.currentTarget.style.background = '#10b981')}
                  >
                    {loading ? '💾 Saving...' : '💾 Save Configuration'}
                  </button>
                </div>
              </div>
            </div>

            {/* Leads & Visitors Data Section */}
            {(!knockKnockCompanyId && !knockKnockWebhookSecret) ? (
              <div style={{ background: '#fef3c7', borderRadius: 12, padding: 32, textAlign: 'center', border: '1px solid #fde68a' }}>
                <div style={{ fontSize: 48, marginBottom: 16 }}>⚠️</div>
                <h3 style={{ fontSize: 20, fontWeight: 600, color: '#92400e', marginBottom: 8 }}>
                  Authentication Required
                </h3>
                <p style={{ fontSize: 15, color: '#78350f', marginBottom: 0 }}>
                  Configure your Company ID or Webhook Secret above to start receiving webhook data
                </p>
              </div>
            ) : (
              <div style={{ background: '#fff', borderRadius: 12, border: '1px solid #e5e7eb' }}>
                {/* Header with Controls */}
                <div style={{ padding: '20px 24px', borderBottom: '1px solid #e5e7eb' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 16 }}>
                    <h3 style={{ margin: 0, fontSize: 20, fontWeight: 600, color: '#111827' }}>
                      📊 Leads & Visitors
                    </h3>
                    
                    <div style={{ display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
                      {/* Search */}
                      <input
                        type="text"
                        placeholder="🔍 Search by name or email..."
                        value={knockKnockSearchQuery}
                        onChange={(e) => setKnockKnockSearchQuery(e.target.value)}
                        style={{
                          padding: '8px 16px',
                          border: '1px solid #d1d5db',
                          borderRadius: 8,
                          fontSize: 14,
                          outline: 'none',
                          minWidth: 200
                        }}
                        onFocus={(e) => e.currentTarget.style.borderColor = '#7c3aed'}
                        onBlur={(e) => e.currentTarget.style.borderColor = '#d1d5db'}
                      />
                      
                      {/* Type Filter */}
                      <select
                        value={knockKnockTypeFilter}
                        onChange={(e) => setKnockKnockTypeFilter(e.target.value as any)}
                        style={{
                          padding: '8px 16px',
                          border: '1px solid #d1d5db',
                          borderRadius: 8,
                          fontSize: 14,
                          outline: 'none',
                          cursor: 'pointer',
                          background: '#fff'
                        }}
                      >
                        <option value="all">All Types</option>
                        <option value="lead">🎯 Leads Only</option>
                        <option value="visitor">👤 Visitors Only</option>
                      </select>
                      
                      {/* View Mode Toggle */}
                      <div style={{ display: 'flex', border: '1px solid #d1d5db', borderRadius: 8, overflow: 'hidden' }}>
                        <button
                          onClick={() => setKnockKnockViewMode('table')}
                          style={{
                            padding: '8px 16px',
                            background: knockKnockViewMode === 'table' ? '#7c3aed' : '#fff',
                            color: knockKnockViewMode === 'table' ? '#fff' : '#6b7280',
                            border: 'none',
                            fontSize: 14,
                            fontWeight: 600,
                            cursor: 'pointer'
                          }}
                        >
                          📝 Table
                        </button>
                        <button
                          onClick={() => setKnockKnockViewMode('cards')}
                          style={{
                            padding: '8px 16px',
                            background: knockKnockViewMode === 'cards' ? '#7c3aed' : '#fff',
                            color: knockKnockViewMode === 'cards' ? '#fff' : '#6b7280',
                            border: 'none',
                            borderLeft: '1px solid #d1d5db',
                            fontSize: 14,
                            fontWeight: 600,
                            cursor: 'pointer'
                          }}
                        >
                          🃏 Cards
                        </button>
                      </div>
                      
                      {/* Refresh */}
                      <button
                        onClick={fetchKnockKnockLeads}
                        disabled={knockKnockLeadsLoading}
                        style={{
                          padding: '8px 16px',
                          background: '#f3f4f6',
                          color: '#6b7280',
                          border: '1px solid #d1d5db',
                          borderRadius: 8,
                          fontSize: 14,
                          fontWeight: 600,
                          cursor: knockKnockLeadsLoading ? 'not-allowed' : 'pointer',
                          transition: 'all 0.2s'
                        }}
                        onMouseEnter={(e) => !knockKnockLeadsLoading && (e.currentTarget.style.background = '#e5e7eb')}
                        onMouseLeave={(e) => !knockKnockLeadsLoading && (e.currentTarget.style.background = '#f3f4f6')}
                      >
                        {knockKnockLeadsLoading ? '⏳' : '🔄 Refresh'}
                      </button>
                    </div>
                  </div>
                </div>

                {/* Data Display */}
                <div style={{ padding: 24 }}>
                  {knockKnockLeadsLoading ? (
                    <div style={{ textAlign: 'center', padding: 48, color: '#6b7280' }}>
                      <div style={{ fontSize: 32, marginBottom: 12 }}>⏳</div>
                      <div style={{ fontSize: 16 }}>Loading data...</div>
                    </div>
                  ) : (() => {
                    // Filter and search logic
                    const filtered = knockKnockLeads.filter(item => {
                      const matchesType = knockKnockTypeFilter === 'all' || item.type === knockKnockTypeFilter;
                      const searchLower = knockKnockSearchQuery.toLowerCase();
                      const matchesSearch = !searchLower || 
                        (item.first_name && item.first_name.toLowerCase().includes(searchLower)) ||
                        (item.last_name && item.last_name.toLowerCase().includes(searchLower)) ||
                        (item.email && item.email.toLowerCase().includes(searchLower));
                      return matchesType && matchesSearch;
                    });
                    
                    // Pagination
                    const totalPages = Math.ceil(filtered.length / knockKnockItemsPerPage);
                    const startIndex = (knockKnockCurrentPage - 1) * knockKnockItemsPerPage;
                    const paginatedData = filtered.slice(startIndex, startIndex + knockKnockItemsPerPage);
                    
                    if (filtered.length === 0) {
                      return (
                        <div style={{ background: '#eff6ff', borderRadius: 8, padding: 32, textAlign: 'center' }}>
                          <div style={{ fontSize: 32, marginBottom: 12 }}>📭</div>
                          <div style={{ fontSize: 16, fontWeight: 600, color: '#1e40af', marginBottom: 4 }}>
                            No data found
                          </div>
                          <div style={{ fontSize: 14, color: '#3b82f6' }}>
                            {knockKnockLeads.length === 0 
                              ? 'Send a test webhook from KnockKnock to get started'
                              : 'Try adjusting your search or filter criteria'}
                          </div>
                        </div>
                      );
                    }
                    
                    return (
                      <>
                        {/* Table View */}
                        {knockKnockViewMode === 'table' && (
                          <div style={{ overflowX: 'auto' }}>
                            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
                              <thead>
                                <tr style={{ background: '#f9fafb', borderBottom: '2px solid #e5e7eb' }}>
                                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, color: '#6b7280' }}>Type</th>
                                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, color: '#6b7280' }}>Name</th>
                                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, color: '#6b7280' }}>Email</th>
                                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, color: '#6b7280' }}>Source</th>
                                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, color: '#6b7280' }}>Date</th>
                                </tr>
                              </thead>
                              <tbody>
                                {paginatedData.map((item, idx) => (
                                  <tr 
                                    key={item.id || idx} 
                                    style={{ 
                                      borderBottom: '1px solid #e5e7eb',
                                      cursor: 'pointer',
                                      transition: 'background 0.2s'
                                    }}
                                    onClick={() => setSelectedLead(item)}
                                    onMouseEnter={(e) => e.currentTarget.style.background = '#f9fafb'}
                                    onMouseLeave={(e) => e.currentTarget.style.background = 'transparent'}
                                  >
                                    <td style={{ padding: '14px 16px' }}>
                                      <span style={{
                                        display: 'inline-block',
                                        padding: '4px 10px',
                                        borderRadius: 6,
                                        fontSize: 12,
                                        fontWeight: 600,
                                        background: item.type === 'lead' ? '#dcfce7' : '#dbeafe',
                                        color: item.type === 'lead' ? '#166534' : '#1e40af'
                                      }}>
                                        {item.type === 'lead' ? 'Lead' : 'Visitor'}
                                      </span>
                                    </td>
                                    <td style={{ padding: '14px 16px', color: '#111827', fontWeight: 500 }}>
                                      {item.first_name && item.last_name 
                                        ? `${item.first_name} ${item.last_name}` 
                                        : item.first_name || item.last_name || 'Anonymous'}
                                    </td>
                                    <td style={{ padding: '14px 16px', color: '#111827' }}>
                                      {item.email || <span style={{ color: '#9ca3af' }}>No email</span>}
                                    </td>
                                    <td style={{ padding: '14px 16px' }}>
                                      {item.initial_page_visit || item.page_url ? (
                                        <a 
                                          href={item.initial_page_visit || item.page_url} 
                                          target="_blank" 
                                          rel="noopener noreferrer"
                                          style={{ color: '#7c3aed', textDecoration: 'none', fontWeight: 500 }}
                                          onClick={(e) => e.stopPropagation()}
                                        >
                                          {(() => {
                                            const url = item.initial_page_visit || item.page_url;
                                            try {
                                              const parsed = new URL(url);
                                              return parsed.pathname === '/' ? parsed.hostname : parsed.pathname;
                                            } catch {
                                              return url;
                                            }
                                          })()}
                                        </a>
                                      ) : <span style={{ color: '#9ca3af' }}>Unknown</span>}
                                    </td>
                                    <td style={{ padding: '14px 16px', color: '#6b7280', fontSize: 13 }}>
                                      {item.timestamp ? new Date(item.timestamp).toLocaleDateString('en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                      }) : 'N/A'}
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        )}
                        
                        {/* Cards View */}
                        {knockKnockViewMode === 'cards' && (
                          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: 16 }}>
                            {paginatedData.map((item, idx) => (
                              <div 
                                key={item.id || idx}
                                onClick={() => setSelectedLead(item)}
                                style={{
                                  background: '#fff',
                                  border: '1px solid #e5e7eb',
                                  borderRadius: 12,
                                  padding: 20,
                                  transition: 'all 0.2s',
                                  cursor: 'pointer'
                                }}
                                onMouseEnter={(e) => {
                                  e.currentTarget.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                                  e.currentTarget.style.transform = 'translateY(-2px)';
                                }}
                                onMouseLeave={(e) => {
                                  e.currentTarget.style.boxShadow = 'none';
                                  e.currentTarget.style.transform = 'translateY(0)';
                                }}
                              >
                                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
                                  <span style={{
                                    padding: '4px 10px',
                                    borderRadius: 6,
                                    fontSize: 12,
                                    fontWeight: 600,
                                    background: item.type === 'lead' ? '#dcfce7' : '#dbeafe',
                                    color: item.type === 'lead' ? '#166534' : '#1e40af'
                                  }}>
                                    {item.type === 'lead' ? '🎯 Lead' : '👤 Visitor'}
                                  </span>
                                  <span style={{ fontSize: 12, color: '#6b7280' }}>
                                    {item.timestamp && new Date(item.timestamp).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                  </span>
                                </div>
                                
                                <div style={{ marginBottom: 16 }}>
                                  <div style={{ fontSize: 18, fontWeight: 600, color: '#111827', marginBottom: 4 }}>
                                    {item.first_name && item.last_name 
                                      ? `${item.first_name} ${item.last_name}` 
                                      : item.first_name || item.last_name || 'Anonymous User'}
                                  </div>
                                  <div style={{ fontSize: 14, color: '#6b7280' }}>
                                    {item.email || 'No email provided'}
                                  </div>
                                </div>
                                
                                {(item.initial_page_visit || item.page_url) && (
                                  <div style={{ fontSize: 13, color: '#7c3aed', fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    🔗 {(() => {
                                      const url = item.initial_page_visit || item.page_url;
                                      try {
                                        const parsed = new URL(url);
                                        return parsed.pathname === '/' ? parsed.hostname : parsed.pathname;
                                      } catch {
                                        return url;
                                      }
                                    })()}
                                  </div>
                                )}
                              </div>
                            ))}
                          </div>
                        )}
                        
                        {/* Pagination */}
                        {totalPages > 1 && (
                          <div style={{ marginTop: 24, display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 12 }}>
                            <button
                              onClick={() => setKnockKnockCurrentPage(Math.max(1, knockKnockCurrentPage - 1))}
                              disabled={knockKnockCurrentPage === 1}
                              style={{
                                padding: '8px 16px',
                                background: knockKnockCurrentPage === 1 ? '#f3f4f6' : '#fff',
                                color: knockKnockCurrentPage === 1 ? '#9ca3af' : '#6b7280',
                                border: '1px solid #d1d5db',
                                borderRadius: 6,
                                fontSize: 14,
                                fontWeight: 600,
                                cursor: knockKnockCurrentPage === 1 ? 'not-allowed' : 'pointer'
                              }}
                            >
                              ← Previous
                            </button>
                            
                            <span style={{ fontSize: 14, color: '#6b7280' }}>
                              Page {knockKnockCurrentPage} of {totalPages} ({filtered.length} total)
                            </span>
                            
                            <button
                              onClick={() => setKnockKnockCurrentPage(Math.min(totalPages, knockKnockCurrentPage + 1))}
                              disabled={knockKnockCurrentPage === totalPages}
                              style={{
                                padding: '8px 16px',
                                background: knockKnockCurrentPage === totalPages ? '#f3f4f6' : '#fff',
                                color: knockKnockCurrentPage === totalPages ? '#9ca3af' : '#6b7280',
                                border: '1px solid #d1d5db',
                                borderRadius: 6,
                                fontSize: 14,
                                fontWeight: 600,
                                cursor: knockKnockCurrentPage === totalPages ? 'not-allowed' : 'pointer'
                              }}
                            >
                              Next →
                            </button>
                          </div>
                        )}
                      </>
                    );
                  })()}
                </div>
              </div>
            )}
            </>)}
          </section>
        )}

        {/* License Tab */}
        {activeTab === 'license' && (
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <h2 style={{ margin: '0 0 8px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>License</h2>
            <p style={{ color: '#6b7280', marginBottom: 32, fontSize: 15 }}>
              Activate your Conversion IQ license to enable all features.
            </p>

            {/* Status card */}
            <div style={{
              background: licenseStatus === 'active' ? '#f0fdf4' : licenseStatus === 'checking' ? '#f9fafb' : '#fef2f2',
              border: `1px solid ${licenseStatus === 'active' ? '#86efac' : licenseStatus === 'checking' ? '#e5e7eb' : '#fca5a5'}`,
              borderRadius: 12,
              padding: 24,
              marginBottom: 24,
              display: 'flex',
              alignItems: 'center',
              gap: 16
            }}>
              <div style={{ fontSize: 32 }}>
                {licenseStatus === 'active' ? '\u2705' : licenseStatus === 'checking' ? '\u23F3' : '\u274C'}
              </div>
              <div>
                <div style={{ fontSize: 18, fontWeight: 700, color: licenseStatus === 'active' ? '#166534' : licenseStatus === 'checking' ? '#374151' : '#991b1b' }}>
                  {licenseStatus === 'active' ? 'License Active' : licenseStatus === 'checking' ? 'Checking...' : 'License Inactive'}
                </div>
                <div style={{ fontSize: 14, color: '#6b7280', marginTop: 2 }}>
                  {licenseStatus === 'active'
                    ? (licenseCustomer?.name ? `Licensed to ${licenseCustomer.name}` : 'Your license is valid and active')
                    : licenseStatus === 'checking'
                    ? 'Verifying license status...'
                    : 'Enter your license key below to activate'}
                </div>
              </div>
            </div>

            {/* Customer info when active */}
            {licenseStatus === 'active' && licenseCustomer && (
              <div style={{ background: '#f9fafb', borderRadius: 12, padding: 24, marginBottom: 24, border: '1px solid #e5e7eb' }}>
                <h3 style={{ margin: '0 0 16px 0', fontSize: 16, fontWeight: 600, color: '#111827' }}>License Details</h3>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                  {licenseCustomer.name && (
                    <div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Name</div>
                      <div style={{ fontSize: 15, color: '#111827', fontWeight: 500 }}>{licenseCustomer.name}</div>
                    </div>
                  )}
                  {licenseCustomer.email && (
                    <div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Email</div>
                      <div style={{ fontSize: 15, color: '#111827', fontWeight: 500 }}>{licenseCustomer.email}</div>
                    </div>
                  )}
                  {licenseCustomer.company && (
                    <div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Company</div>
                      <div style={{ fontSize: 15, color: '#111827', fontWeight: 500 }}>{licenseCustomer.company}</div>
                    </div>
                  )}
                  {licenseCustomer.plan && (
                    <div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Plan</div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{
                          display: 'inline-block',
                          padding: '4px 12px',
                          borderRadius: 20,
                          fontSize: 13,
                          fontWeight: 600,
                          background: currentPlan === 'agency' ? '#7c3aed' : currentPlan === 'professional' ? '#2563eb' : '#6b7280',
                          color: '#fff',
                          textTransform: 'capitalize',
                        }}>{licenseCustomer.plan}</span>
                        <button
                          onClick={handleLicenseRefresh}
                          disabled={licenseLoading}
                          title="Re-validate your license to pull the latest plan from the server"
                          style={{ padding: '4px 10px', background: '#fff', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 12, fontWeight: 600, color: '#374151', cursor: licenseLoading ? 'not-allowed' : 'pointer', display: 'flex', alignItems: 'center', gap: 5, opacity: licenseLoading ? 0.5 : 1, transition: 'all 0.2s' }}
                          onMouseEnter={(e) => { if (!licenseLoading) { e.currentTarget.style.borderColor = '#7c3aed'; e.currentTarget.style.color = '#7c3aed'; }}}
                          onMouseLeave={(e) => { e.currentTarget.style.borderColor = '#d1d5db'; e.currentTarget.style.color = '#374151'; }}
                        >
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.56"/></svg>
                          {licenseLoading ? 'Refreshing...' : 'Refresh Plan'}
                        </button>
                      </div>
                    </div>
                  )}
                  <div>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Status</div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                      <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#10b981', display: 'inline-block' }} />
                      <span style={{ fontSize: 15, color: '#059669', fontWeight: 500 }}>Active</span>
                    </div>
                  </div>
                  {licenseValidatedAt > 0 && (
                    <div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Activated</div>
                      <div style={{ fontSize: 15, color: '#111827', fontWeight: 500 }}>{new Date(licenseValidatedAt * 1000).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>
                    </div>
                  )}
                  {licenseValidatedAt > 0 && (
                    <div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Last Verified</div>
                      <div style={{ fontSize: 15, color: '#111827', fontWeight: 500 }}>{new Date(licenseValidatedAt * 1000).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</div>
                    </div>
                  )}
                </div>
                {/* Plan comparison */}
                {(() => {
                  const plans: Record<string, { label: string; price: string; color: string; features: string[] }> = {
                    free: { label: 'Free', price: '$0', color: '#9ca3af', features: ['1 site', '1 page per audit', 'AI conversion audit', '6 conversion scores'] },
                    starter: { label: 'Starter', price: '$89/mo', color: '#6b7280', features: ['1 site', '2 pages per audit', 'AI conversion audit', '6 conversion scores', 'AI copy suggestions', 'Priority quick wins', 'Automated PDF reports'] },
                    professional: { label: 'Professional', price: '$179/mo', color: '#2563eb', features: ['1 site', '4 pages per audit', 'Everything in Starter', 'Priority support'] },
                    business: { label: 'Business', price: '$249/mo', color: '#7c3aed', features: ['1 site', '6 pages per audit', 'Everything in Professional', 'KnockKnock visitor intelligence'] },
                    agency: { label: 'Agency', price: '$449/mo', color: '#f59e0b', features: ['100 sites', '15 pages per audit', 'Everything in Business', 'Full white-label branding'] },
                  };
                  const order = ['free', 'starter', 'professional', 'business', 'agency'];
                  const currentIdx = order.indexOf(currentPlan);
                  const current = plans[currentPlan] || plans.free;
                  const nextKey = currentIdx < order.length - 1 ? order[currentIdx + 1] : null;
                  const next = nextKey ? plans[nextKey] : null;

                  return (
                    <div style={{ marginTop: 20, display: 'grid', gridTemplateColumns: next ? '1fr 1fr' : '1fr', gap: 16 }}>
                      {/* Current plan */}
                      <div style={{ border: `2px solid ${current.color}`, borderRadius: 12, padding: 20, position: 'relative' }}>
                        <div style={{ position: 'absolute', top: -11, left: 16, background: '#f9fafb', padding: '0 8px', fontSize: 11, fontWeight: 700, color: current.color, textTransform: 'uppercase', letterSpacing: 1 }}>Current Plan</div>
                        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 12 }}>
                          <span style={{ fontSize: 20, fontWeight: 700, color: '#111827' }}>{current.label}</span>
                          <span style={{ fontSize: 14, color: '#6b7280' }}>{current.price}</span>
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                          {current.features.map((f, i) => (
                            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#374151' }}>
                              <span style={{ color: '#10b981', fontWeight: 700, fontSize: 14 }}>✓</span>
                              <span>{f}</span>
                            </div>
                          ))}
                        </div>
                      </div>
                      {/* Next plan up */}
                      {next && nextKey && (
                        <div style={{ border: '2px solid #e5e7eb', borderRadius: 12, padding: 20, position: 'relative', background: '#fafafa' }}>
                          <div style={{ position: 'absolute', top: -11, left: 16, background: '#fafafa', padding: '0 8px', fontSize: 11, fontWeight: 700, color: next.color, textTransform: 'uppercase', letterSpacing: 1 }}>Upgrade Available</div>
                          <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 12 }}>
                            <span style={{ fontSize: 20, fontWeight: 700, color: '#111827' }}>{next.label}</span>
                            <span style={{ fontSize: 14, color: '#6b7280' }}>{next.price}</span>
                          </div>
                          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 16 }}>
                            {next.features.map((f, i) => (
                              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#374151' }}>
                                <span style={{ color: next.color, fontWeight: 700, fontSize: 14 }}>✓</span>
                                <span>{f}</span>
                              </div>
                            ))}
                          </div>
                          <a
                            href="https://conversioniq-app.com"
                            target="_blank"
                            rel="noopener noreferrer"
                            style={{ display: 'block', textAlign: 'center', padding: '10px 20px', background: next.color, color: '#fff', borderRadius: 8, fontSize: 14, fontWeight: 600, textDecoration: 'none', transition: 'opacity 0.2s' }}
                            onMouseEnter={(e) => e.currentTarget.style.opacity = '0.9'}
                            onMouseLeave={(e) => e.currentTarget.style.opacity = '1'}
                          >
                            Upgrade to {next.label}
                          </a>
                        </div>
                      )}
                    </div>
                  );
                })()}
              </div>
            )}

            {/* License key section */}
            {licenseStatus === 'active' ? (
              <div style={{ marginBottom: 24 }}>
                {/* Site Management */}
                <div style={{ background: '#f9fafb', borderRadius: 12, padding: 24, marginBottom: 24, border: '1px solid #e5e7eb' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: licenseSites !== null ? 16 : 0 }}>
                    <div>
                      <div style={{ fontSize: 15, fontWeight: 700, color: '#111827' }}>Active Sites</div>
                      <div style={{ fontSize: 13, color: '#6b7280', marginTop: 2 }}>
                        Sites currently using this license key
                        {licenseMaxSites !== null && (
                          <span style={{ marginLeft: 8, padding: '2px 8px', background: '#f3f4f6', border: '1px solid #e5e7eb', borderRadius: 20, fontSize: 11, fontWeight: 600, color: '#374151' }}>
                            {licenseSites?.length ?? '?'} / {licenseMaxSites} sites used
                          </span>
                        )}
                      </div>
                    </div>
                    <button
                      onClick={handleFetchSites}
                      disabled={licenseSitesLoading}
                      style={{ padding: '8px 16px', background: '#fff', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 13, fontWeight: 600, color: '#374151', cursor: licenseSitesLoading ? 'not-allowed' : 'pointer', display: 'flex', alignItems: 'center', gap: 6, transition: 'all 0.2s' }}
                      onMouseEnter={(e) => { if (!licenseSitesLoading) { e.currentTarget.style.borderColor = '#7c3aed'; e.currentTarget.style.color = '#7c3aed'; }}}
                      onMouseLeave={(e) => { e.currentTarget.style.borderColor = '#d1d5db'; e.currentTarget.style.color = '#374151'; }}
                    >
                      {licenseSitesLoading ? (
                        <><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ animation: 'spin 1s linear infinite' }}><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Loading...</>
                      ) : (
                        <><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.56"/></svg> {licenseSites !== null ? 'Refresh' : 'View Sites'}</>
                      )}
                    </button>
                  </div>

                  {licenseSites !== null && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                      {licenseSites.length === 0 ? (
                        <div style={{ fontSize: 13, color: '#6b7280', textAlign: 'center', padding: '12px 0' }}>No active site activations found.</div>
                      ) : licenseSites.map((site, i) => {
                        const isCurrentSite = site.site_url.replace(/\/$/, '') === (window as any).location?.origin?.replace(/\/$/, '');
                        const isRemoving = deactivatingUrl === site.site_url;
                        return (
                          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 16px', background: '#fff', borderRadius: 8, border: `1px solid ${isCurrentSite ? '#a5b4fc' : '#e5e7eb'}` }}>
                            <div style={{ width: 8, height: 8, borderRadius: '50%', background: '#10b981', flexShrink: 0 }} />
                            <div style={{ flex: 1, minWidth: 0 }}>
                              <div style={{ fontSize: 14, fontWeight: 600, color: '#111827', display: 'flex', alignItems: 'center', gap: 8 }}>
                                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{site.site_url}</span>
                                {isCurrentSite && <span style={{ flexShrink: 0, padding: '2px 8px', background: '#ede9fe', border: '1px solid #c4b5fd', borderRadius: 20, fontSize: 11, fontWeight: 600, color: '#6d28d9' }}>This site</span>}
                              </div>
                              {site.activated_at && (
                                <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>
                                  Activated {new Date(site.activated_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}
                                </div>
                              )}
                            </div>
                            <button
                              onClick={() => isCurrentSite ? handleLicenseDeactivate() : handleRemoveSite(site.site_url)}
                              disabled={isRemoving || licenseLoading}
                              style={{ flexShrink: 0, padding: '6px 14px', background: '#fff', border: '1px solid #fca5a5', borderRadius: 8, fontSize: 12, fontWeight: 600, color: '#dc2626', cursor: (isRemoving || licenseLoading) ? 'not-allowed' : 'pointer', opacity: (isRemoving || licenseLoading) ? 0.5 : 1, transition: 'all 0.2s' }}
                              onMouseEnter={(e) => { if (!isRemoving && !licenseLoading) { e.currentTarget.style.background = '#fef2f2'; }}}
                              onMouseLeave={(e) => { e.currentTarget.style.background = '#fff'; }}
                            >
                              {isRemoving ? 'Removing...' : 'Remove'}
                            </button>
                          </div>
                        );
                      })}
                      {licenseMaxSites !== null && licenseSites.length < licenseMaxSites && (
                        <div style={{ fontSize: 12, color: '#6b7280', textAlign: 'center', padding: '8px 0', borderTop: '1px solid #f3f4f6', marginTop: 4 }}>
                          {licenseMaxSites - licenseSites.length} site slot{licenseMaxSites - licenseSites.length !== 1 ? 's' : ''} available — install the plugin on another site and enter this key to activate it.
                        </div>
                      )}
                    </div>
                  )}
                </div>

                <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>
                  License Key
                </label>
                <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                  <div style={{
                    flex: 1,
                    padding: '12px 16px',
                    border: '1px solid #d1d5db',
                    borderRadius: 8,
                    fontSize: 14,
                    fontFamily: 'monospace',
                    color: '#111827',
                    background: '#f9fafb',
                    letterSpacing: showLicenseKey ? 0 : 2,
                  }}>
                    {showLicenseKey ? fullLicenseKey : licenseKey}
                  </div>
                  <button
                    onClick={() => setShowLicenseKey(!showLicenseKey)}
                    style={{
                      padding: '12px 20px',
                      background: '#fff',
                      color: '#374151',
                      border: '1px solid #d1d5db',
                      borderRadius: 8,
                      fontSize: 13,
                      fontWeight: 600,
                      cursor: 'pointer',
                      transition: 'all 0.2s',
                      whiteSpace: 'nowrap',
                      display: 'flex',
                      alignItems: 'center',
                      gap: 6,
                    }}
                    onMouseEnter={(e) => { e.currentTarget.style.borderColor = '#7c3aed'; e.currentTarget.style.color = '#7c3aed'; }}
                    onMouseLeave={(e) => { e.currentTarget.style.borderColor = '#d1d5db'; e.currentTarget.style.color = '#374151'; }}
                  >
                    {showLicenseKey ? '🙈 Hide' : '👁 Reveal'}
                  </button>
                  {showLicenseKey && (
                    <button
                      onClick={() => { navigator.clipboard.writeText(fullLicenseKey); showSuccess('License key copied!'); }}
                      style={{
                        padding: '12px 20px',
                        background: '#fff',
                        color: '#374151',
                        border: '1px solid #d1d5db',
                        borderRadius: 8,
                        fontSize: 13,
                        fontWeight: 600,
                        cursor: 'pointer',
                        transition: 'all 0.2s',
                        whiteSpace: 'nowrap',
                      }}
                      onMouseEnter={(e) => { e.currentTarget.style.borderColor = '#7c3aed'; e.currentTarget.style.color = '#7c3aed'; }}
                      onMouseLeave={(e) => { e.currentTarget.style.borderColor = '#d1d5db'; e.currentTarget.style.color = '#374151'; }}
                    >
                      📋 Copy
                    </button>
                  )}
                </div>
              </div>
            ) : (
              <div style={{ marginBottom: 24 }}>
                <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>
                  License Key
                </label>
                <div style={{ display: 'flex', gap: 12 }}>
                  <input
                    type="text"
                    value={licenseKey}
                    onChange={(e) => setLicenseKey(e.target.value)}
                    placeholder="CIQ-XXXXX-XXXXX-XXXXX-XXXXX"
                    style={{
                      flex: 1,
                      padding: '12px 16px',
                      border: '1px solid #d1d5db',
                      borderRadius: 8,
                      fontSize: 14,
                      outline: 'none',
                      fontFamily: 'monospace',
                      color: '#111827',
                      background: '#fff'
                    }}
                    onFocus={(e) => e.currentTarget.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.currentTarget.style.borderColor = '#d1d5db'}
                  />
                  <button
                    onClick={handleLicenseActivate}
                    disabled={licenseLoading}
                    style={{
                      padding: '12px 24px',
                      background: licenseLoading ? '#d1d5db' : '#7c3aed',
                      color: '#fff',
                      border: 'none',
                      borderRadius: 8,
                      fontSize: 14,
                      fontWeight: 600,
                      cursor: licenseLoading ? 'not-allowed' : 'pointer',
                      transition: 'all 0.2s',
                      whiteSpace: 'nowrap'
                    }}
                    onMouseEnter={(e) => !licenseLoading && (e.currentTarget.style.background = '#6d28d9')}
                    onMouseLeave={(e) => !licenseLoading && (e.currentTarget.style.background = '#7c3aed')}
                  >
                    {licenseLoading ? 'Activating...' : 'Activate License'}
                  </button>
                </div>
                <p style={{ margin: '8px 0 0 0', fontSize: 13, color: '#6b7280' }}>
                  Your license key was emailed to you when you purchased {B.product}. Keys follow the format CIQ-XXXXX-XXXXX-XXXXX-XXXXX.{' '}
                  <a href={`mailto:${B.supportEmail}`} style={{ color: '#7c3aed', textDecoration: 'none', fontWeight: 500 }}>
                    Contact support
                  </a>{' '}
                  if you need help.
                </p>
              </div>
            )}
          </section>
        )}

        {/* FAQ Tab */}
        {activeTab === 'faq' && (
          <FaqTab B={B} />
        )}
      </main>

      {/* Audit Progress Modal */}
      {auditProgress.isRunning && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0, 0, 0, 0.7)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 10000,
          backdropFilter: 'blur(4px)'
        }}>
          <div style={{
            background: '#fff',
            borderRadius: 16,
            padding: 40,
            maxWidth: 500,
            width: '90%',
            boxShadow: '0 20px 50px rgba(0, 0, 0, 0.3)',
            textAlign: 'center'
          }}>
            {/* Animated Spinner */}
            <div style={{
              width: 80,
              height: 80,
              margin: '0 auto 24px',
              border: '4px solid #e5e7eb',
              borderTop: '4px solid #7c3aed',
              borderRadius: '50%',
              animation: 'spin 1s linear infinite'
            }} />
            <style>{`
              @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
              }
            `}</style>
            
            <h3 style={{
              margin: '0 0 12px 0',
              fontSize: 24,
              fontWeight: 700,
              color: '#111827'
            }}>
              Running Audit Analysis
            </h3>
            
            <p style={{
              margin: '0 0 20px 0',
              fontSize: 16,
              color: '#6b7280',
              lineHeight: 1.6
            }}>
              {auditProgress.message}
            </p>
            
            <p style={{
              marginTop: 20,
              fontSize: 13,
              color: '#9ca3af',
              fontStyle: 'italic'
            }}>
              This may take a minute depending on page complexity...
            </p>
          </div>
        </div>
      )}

      {/* Lead Detail Modal */}
      {selectedLead && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0, 0, 0, 0.5)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 10000,
          backdropFilter: 'blur(4px)',
          padding: 20
        }}
        onClick={() => setSelectedLead(null)}
        >
          <div style={{
            background: '#fff',
            borderRadius: 16,
            maxWidth: 700,
            width: '100%',
            maxHeight: '90vh',
            overflow: 'auto',
            boxShadow: '0 20px 50px rgba(0, 0, 0, 0.3)'
          }}
          onClick={(e) => e.stopPropagation()}
          >
            {/* Header */}
            <div style={{
              padding: '24px 32px',
              borderBottom: '1px solid #e5e7eb',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)',
              color: '#fff',
              borderRadius: '16px 16px 0 0'
            }}>
              <div>
                <h2 style={{ margin: '0 0 4px 0', fontSize: 24, fontWeight: 700 }}>
                  {selectedLead.type === 'lead' ? '🎯 Lead Details' : '👤 Visitor Details'}
                </h2>
                <p style={{ margin: 0, fontSize: 14, opacity: 0.9 }}>
                  {selectedLead.first_name && selectedLead.last_name 
                    ? `${selectedLead.first_name} ${selectedLead.last_name}` 
                    : selectedLead.first_name || selectedLead.last_name || 'Anonymous User'}
                </p>
              </div>
              <button
                onClick={() => setSelectedLead(null)}
                style={{
                  background: 'rgba(255,255,255,0.2)',
                  border: 'none',
                  color: '#fff',
                  padding: '8px 12px',
                  borderRadius: 8,
                  fontSize: 18,
                  cursor: 'pointer',
                  transition: 'all 0.2s'
                }}
                onMouseEnter={(e) => e.currentTarget.style.background = 'rgba(255,255,255,0.3)'}
                onMouseLeave={(e) => e.currentTarget.style.background = 'rgba(255,255,255,0.2)'}
              >
                ✕
              </button>
            </div>

            {/* Body */}
            <div style={{ padding: 32 }}>
              {/* Contact Information */}
              <div style={{ marginBottom: 32 }}>
                <h3 style={{ margin: '0 0 16px 0', fontSize: 18, fontWeight: 600, color: '#111827', display: 'flex', alignItems: 'center', gap: 8 }}>
                  📧 Contact Information
                </h3>
                <div style={{ display: 'grid', gap: 16 }}>
                  <div style={{ display: 'flex', padding: 16, background: '#f9fafb', borderRadius: 8, border: '1px solid #e5e7eb' }}>
                    <div style={{ flex: 1 }}>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Full Name</div>
                      <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>
                        {selectedLead.first_name && selectedLead.last_name 
                          ? `${selectedLead.first_name} ${selectedLead.last_name}` 
                          : selectedLead.first_name || selectedLead.last_name || 'Not provided'}
                      </div>
                    </div>
                  </div>
                  <div style={{ display: 'flex', padding: 16, background: '#f9fafb', borderRadius: 8, border: '1px solid #e5e7eb' }}>
                    <div style={{ flex: 1 }}>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Email Address</div>
                      <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>
                        {selectedLead.email || <span style={{ color: '#9ca3af' }}>Not provided</span>}
                      </div>
                    </div>
                    {selectedLead.email && (
                      <a
                        href={`mailto:${selectedLead.email}`}
                        style={{
                          padding: '8px 16px',
                          background: '#7c3aed',
                          color: '#fff',
                          borderRadius: 6,
                          fontSize: 14,
                          fontWeight: 600,
                          textDecoration: 'none',
                          transition: 'all 0.2s'
                        }}
                        onMouseEnter={(e) => e.currentTarget.style.background = '#6d28d9'}
                        onMouseLeave={(e) => e.currentTarget.style.background = '#7c3aed'}
                      >
                        Send Email
                      </a>
                    )}
                  </div>
                  {selectedLead.phone && (
                    <div style={{ display: 'flex', padding: 16, background: '#f9fafb', borderRadius: 8, border: '1px solid #e5e7eb' }}>
                      <div style={{ flex: 1 }}>
                        <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Phone Number</div>
                        <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>{selectedLead.phone}</div>
                      </div>
                      <a
                        href={`tel:${selectedLead.phone}`}
                        style={{
                          padding: '8px 16px',
                          background: '#10b981',
                          color: '#fff',
                          borderRadius: 6,
                          fontSize: 14,
                          fontWeight: 600,
                          textDecoration: 'none',
                          transition: 'all 0.2s'
                        }}
                        onMouseEnter={(e) => e.currentTarget.style.background = '#059669'}
                        onMouseLeave={(e) => e.currentTarget.style.background = '#10b981'}
                      >
                        Call
                      </a>
                    </div>
                  )}
                </div>
              </div>

              {/* Activity Information */}
              <div style={{ marginBottom: 32 }}>
                <h3 style={{ margin: '0 0 16px 0', fontSize: 18, fontWeight: 600, color: '#111827', display: 'flex', alignItems: 'center', gap: 8 }}>
                  📊 Activity Details
                </h3>
                <div style={{ display: 'grid', gap: 16 }}>
                  <div style={{ padding: 16, background: '#f9fafb', borderRadius: 8, border: '1px solid #e5e7eb' }}>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Type</div>
                    <span style={{
                      display: 'inline-block',
                      padding: '6px 12px',
                      borderRadius: 6,
                      fontSize: 14,
                      fontWeight: 600,
                      background: selectedLead.type === 'lead' ? '#dcfce7' : '#dbeafe',
                      color: selectedLead.type === 'lead' ? '#166534' : '#1e40af'
                    }}>
                      {selectedLead.type === 'lead' ? '🎯 Lead (Converted)' : '👤 Visitor (Identified)'}
                    </span>
                  </div>
                  {selectedLead.initial_page_visit && (
                    <div style={{ padding: 16, background: '#fef3c7', borderRadius: 8, border: '1px solid #fde68a' }}>
                      <div style={{ fontSize: 12, color: '#92400e', marginBottom: 8, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 6 }}>
                        🚪 Initial Landing Page
                      </div>
                      <a 
                        href={selectedLead.initial_page_visit} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        style={{ 
                          color: '#7c3aed', 
                          fontSize: 14, 
                          fontWeight: 500,
                          textDecoration: 'none',
                          wordBreak: 'break-all',
                          display: 'block',
                          marginBottom: 4
                        }}
                      >
                        {selectedLead.initial_page_visit}
                      </a>
                      <p style={{ fontSize: 12, color: '#78350f', margin: '8px 0 0 0' }}>
                        This is the first page they visited before {selectedLead.type === 'lead' ? 'converting' : 'being identified'}
                      </p>
                    </div>
                  )}
                  {selectedLead.page_url && (
                    <div style={{ padding: 16, background: '#f9fafb', borderRadius: 8, border: '1px solid #e5e7eb' }}>
                      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 8, fontWeight: 500 }}>
                        {selectedLead.type === 'lead' ? 'Conversion Page' : 'Current Page'}
                      </div>
                      <a 
                        href={selectedLead.page_url} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        style={{ 
                          color: '#7c3aed', 
                          fontSize: 14, 
                          fontWeight: 500,
                          textDecoration: 'none',
                          wordBreak: 'break-all'
                        }}
                      >
                        {selectedLead.page_url}
                      </a>
                    </div>
                  )}
                  <div style={{ padding: 16, background: '#f9fafb', borderRadius: 8, border: '1px solid #e5e7eb' }}>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Date & Time</div>
                    <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>
                      {selectedLead.timestamp ? new Date(selectedLead.timestamp).toLocaleDateString('en-US', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit'
                      }) : 'N/A'}
                    </div>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
                {selectedLead.email && (
                  <a
                    href={`mailto:${selectedLead.email}?subject=Follow up from ${location.hostname}`}
                    style={{
                      padding: '12px 24px',
                      background: '#7c3aed',
                      color: '#fff',
                      borderRadius: 8,
                      fontSize: 15,
                      fontWeight: 600,
                      textDecoration: 'none',
                      transition: 'all 0.2s',
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 8
                    }}
                    onMouseEnter={(e) => e.currentTarget.style.background = '#6d28d9'}
                    onMouseLeave={(e) => e.currentTarget.style.background = '#7c3aed'}
                  >
                    📧 Send Follow-Up Email
                  </a>
                )}
                <button
                  onClick={() => setSelectedLead(null)}
                  style={{
                    padding: '12px 24px',
                    background: '#f3f4f6',
                    color: '#6b7280',
                    border: '1px solid #d1d5db',
                    borderRadius: 8,
                    fontSize: 15,
                    fontWeight: 600,
                    cursor: 'pointer',
                    transition: 'all 0.2s'
                  }}
                  onMouseEnter={(e) => e.currentTarget.style.background = '#e5e7eb'}
                  onMouseLeave={(e) => e.currentTarget.style.background = '#f3f4f6'}
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
}