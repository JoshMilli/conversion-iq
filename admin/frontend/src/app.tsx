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
  functionality_suggestions?: {
    title: string;
    description: string;
    why: string;
    icon?: string;
  }[];
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
  // Authentication & Account
  const [isAuthenticated, setIsAuthenticated] = useState<boolean | null>(null);
  const [showLogin, setShowLogin] = useState(true);
  const [account, setAccount] = useState<any>(null);
  const [authLoading, setAuthLoading] = useState(true);
  
  // Login/Register form
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login');
  const [authForm, setAuthForm] = useState({
    username: '',
    password: '',
    email: '',
    fullName: '',
    company: ''
  });
  
  const [settings, setSettings] = useState<any>({});
  const [pages, setPages] = useState<Page[]>([]);
  const [selectedPages, setSelectedPages] = useState<number[]>([]);
  const [audits, setAudits] = useState<Audit[]>([]);
  const [loading, setLoading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [notice, setNotice] = useState<string | null>(null);
  const [modal, setModal] = useState<{ audit?: Audit; open: boolean; tab?: string; gaData?: any }>({ open: false, tab: 'overview' });
  const [expandedSuggestions, setExpandedSuggestions] = useState<Set<number>>(new Set([0])); // First suggestion expanded by default
  const [activeTab, setActiveTab] = useState<'settings' | 'automated' | 'audits' | 'account' | 'faq'>('settings');
  const [automatedReporting, setAutomatedReporting] = useState({
    enabled: false,
    frequency: 'weekly',
    email: '',
    defaultPages: [] as number[]
  });
  const [testEmail, setTestEmail] = useState('');
  const [testEmailLoading, setTestEmailLoading] = useState(false);
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
  
  // Account editing state
  const [isEditingAccount, setIsEditingAccount] = useState(false);
  const [accountForm, setAccountForm] = useState({
    full_name: '',
    email: '',
    company: '',
    username: ''
  });
  
  // Google Analytics state
  const [gaStatus, setGaStatus] = useState<any>({ connected: false, has_credentials: false });
  const [gaClientId, setGaClientId] = useState('');
  const [gaClientSecret, setGaClientSecret] = useState('');
  const [gaProperties, setGaProperties] = useState<any[]>([]);
  const [gaLoading, setGaLoading] = useState(false);

  // Check authentication status on mount
  useEffect(() => {
    console.log('=== Conversion IQ: Authentication Check Started ===');
    console.log('Timestamp:', new Date().toISOString());
    console.log('API Base URL:', (window as any).ConversionIQData?.restUrl || 'NOT FOUND');
    console.log('Nonce Status:', nonce ? '✓ Present' : '✗ Missing');
    console.log('ConversionIQData object:', (window as any).ConversionIQData);
    
    if (!nonce) {
      console.error('✗ CRITICAL ERROR: Nonce is missing!');
      console.error('This means wp_localize_script() did not run properly.');
      console.error('Check:');
      console.error('  1. WordPress REST nonce generation');
      console.error('  2. Admin menu page hook (toplevel_page_conversion-iq)');
      console.error('  3. Browser console for any other errors');
      setAuthLoading(false);
      setIsAuthenticated(false);
      return;
    }
    
    console.log('→ Calling auth/status endpoint...');
    axios.get(api('auth/status'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ Auth API Response:', r.data);
        if (r.data.authenticated) {
          console.log('✓ User authenticated successfully');
          setIsAuthenticated(true);
          setAccount(r.data.account);
          setShowLogin(false);
        } else {
          console.log('⚠ User not authenticated');
          setIsAuthenticated(false);
        }
      })
      .catch(err => {
        console.error('✗ Auth API Call Failed:');
        console.error('  URL:', api('auth/status'));
        console.error('  Status:', err.response?.status);
        console.error('  Error:', err.message);
        console.error('  Response Data:', err.response?.data);
        setIsAuthenticated(false);
      })
      .finally(() => {
        console.log('=== Authentication Check Complete ===');
        setAuthLoading(false);
      });
  }, []);

  // Load settings, pages, audits, automated settings (only when authenticated)
  useEffect(() => {
    if (!isAuthenticated) {
      console.log('→ Skipping data load - user not authenticated');
      return;
    }
    
    console.log('=== Loading Plugin Data ===');
    
    axios.get(api('settings'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ Settings loaded');
        setSettings(r.data);
      })
      .catch(err => console.error('✗ Failed to load settings:', err));
    
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
    
    axios.get(api('ga/status'), { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        console.log('✓ GA status loaded');
        setGaStatus(r.data);
      })
      .catch(err => console.error('✗ Failed to load GA status:', err));
  }, [isAuthenticated]);

  // Check for GA OAuth callback
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('ga_connected') === '1') {
      setNotice('✅ Google Analytics authenticated! Now select a property.');
      // Load properties after successful auth
      setGaLoading(true);
      axios.get(api('ga/properties'), { headers: { 'X-WP-Nonce': nonce } })
        .then(r => {
          if (r.data.success) {
            setGaProperties(r.data.properties);
          } else {
            setNotice('❌ ' + r.data.error);
          }
        })
        .catch(err => setNotice('❌ Failed to load properties: ' + (err.response?.data?.error || err.message)))
        .finally(() => setGaLoading(false));
      // Clean up URL
      window.history.replaceState({}, document.title, window.location.pathname + '?page=conversioniq');
    } else if (params.get('ga_error')) {
      setNotice('❌ Google Analytics auth failed: ' + params.get('ga_error'));
      window.history.replaceState({}, document.title, window.location.pathname + '?page=conversioniq');
    }
  }, []);

  // Load GA data when modal opens
  useEffect(() => {
    if (modal.open && modal.audit && modal.audit.page_url && gaStatus.connected) {
      axios.post(api('ga/page-data'), {
        url: modal.audit.page_url,
        days: 30
      }, { headers: { 'X-WP-Nonce': nonce } })
        .then(r => {
          if (r.data.success) {
            setModal(m => ({ ...m, gaData: r.data.data }));
          }
        })
        .catch(err => console.error('Failed to load GA data:', err));
    }
  }, [modal.open, modal.audit?.page_url, gaStatus.connected]);

  // Handlers
  const handleAuthFormChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setAuthForm({ ...authForm, [e.target.name]: e.target.value });
  };

  const handleLogin = async () => {
    setLoading(true);
    setNotice(null);
    try {
      const response = await axios.post(api('auth/login'), {
        username: authForm.username,
        password: authForm.password
      }, { headers: { 'X-WP-Nonce': nonce } });

      if (response.data.success) {
        setIsAuthenticated(true);
        setAccount(response.data.account);
        setShowLogin(false);
        setNotice('✅ Welcome back!');
      } else {
        setNotice('❌ ' + response.data.message);
      }
    } catch (err: any) {
      setNotice('❌ Login failed: ' + (err.response?.data?.message || 'Invalid credentials'));
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async () => {
    if (!authForm.fullName || !authForm.email || !authForm.company || !authForm.username || !authForm.password) {
      setNotice('❌ Please fill out all fields');
      return;
    }

    setLoading(true);
    setNotice(null);
    try {
      const response = await axios.post(api('auth/register'), {
        full_name: authForm.fullName,
        email: authForm.email,
        company: authForm.company,
        username: authForm.username,
        password: authForm.password
      }, { headers: { 'X-WP-Nonce': nonce } });

      if (response.data.success) {
        setIsAuthenticated(true);
        setAccount(response.data.account);
        setShowLogin(false);
        setNotice('✅ Account created successfully!');
      } else {
        setNotice('❌ ' + response.data.message);
      }
    } catch (err: any) {
      console.error('Registration Error:', err);
      console.error('Error Response:', err.response?.data);
      
      const errorData = err.response?.data;
      let errorMessage = err.response?.data?.message || 'Please try again';
      
      // Log debug information if available
      if (errorData?.debug) {
        console.error('Debug Info:', errorData.debug);
      }
      if (errorData?.error) {
        console.error('Error Details:', errorData.error);
        errorMessage += '\n\nCheck browser console for details.';
      }
      
      setNotice('❌ Registration failed: ' + errorMessage);
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = async () => {
    try {
      await axios.post(api('auth/logout'), {}, { headers: { 'X-WP-Nonce': nonce } });
      setIsAuthenticated(false);
      setAccount(null);
      setShowLogin(true);
      setNotice('👋 Logged out successfully');
    } catch (err) {
      console.error('Logout error:', err);
    }
  };

  const handleAccountFormChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setAccountForm({ ...accountForm, [e.target.name]: e.target.value });
  };

  const handleEditAccount = () => {
    setAccountForm({
      full_name: account?.full_name || '',
      email: account?.email || '',
      company: account?.company || '',
      username: account?.username || ''
    });
    setIsEditingAccount(true);
  };

  const handleCancelEditAccount = () => {
    setIsEditingAccount(false);
    setAccountForm({
      full_name: '',
      email: '',
      company: '',
      username: ''
    });
  };

  const handleUpdateAccount = async () => {
    if (!accountForm.full_name || !accountForm.email || !accountForm.company || !accountForm.username) {
      setNotice('❌ Please fill out all fields');
      return;
    }

    setLoading(true);
    setNotice(null);
    try {
      const response = await axios.post(api('account/update'), accountForm, {
        headers: { 'X-WP-Nonce': nonce }
      });

      if (response.data.success) {
        setAccount(response.data.account);
        setIsEditingAccount(false);
        setNotice('✅ Account updated successfully!');
        setTimeout(() => setNotice(null), 3000);
      } else {
        setNotice('❌ ' + response.data.message);
      }
    } catch (err: any) {
      setNotice('❌ Failed to update account: ' + (err.response?.data?.message || 'Please try again'));
    } finally {
      setLoading(false);
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
        setTimeout(() => setNotice(null), 3000);
      } else {
        throw new Error('Failed to extract information');
      }
    } catch (err) {
      console.error('❌ Auto-fill failed:', err);
      console.error('Response details:', (err as any)?.response?.data);
      console.error('Status code:', (err as any)?.response?.status);
      
      let errorMsg = 'Failed to extract business info';
      if ((err as any)?.response?.data?.message) {
        errorMsg = (err as any).response.data.message;
        console.error('Server message:', errorMsg);
      }
      
      setNotice('❌ ' + errorMsg + ' - check console for details');
      setTimeout(() => setNotice(null), 5000);
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
  const handleSaveAutomatedSettings = async () => {
    // Check if business information is filled out
    const hasBusinessInfo = settings.industry && settings.product && settings.audience && settings.goal;
    if (!hasBusinessInfo) {
      setNotice('❌ Please fill out Business Information tab first before enabling automated audits');
      setTimeout(() => setNotice(null), 5000);
      return;
    }
    
    setLoading(true);
    try {
      const response = await axios.post(api('automated-settings'), automatedReporting, { headers: { 'X-WP-Nonce': nonce } });
      if (response.data.success) {
        setNotice('✅ Automated report settings saved! ' + (response.data.next_run ? `Next run: ${new Date(response.data.next_run).toLocaleString()}` : ''));
      } else {
        setNotice('❌ ' + response.data.message);
      }
    } catch (err: any) {
      setNotice('❌ Failed to save automated settings: ' + (err.response?.data?.message || err.message));
    } finally {
      setLoading(false);
    }
  };

  const handleTestEmail = async () => {
    const emailToTest = testEmail || automatedReporting.email;
    
    if (!emailToTest || !emailToTest.includes('@')) {
      setNotice('❌ Please enter a valid email address');
      setTimeout(() => setNotice(null), 3000);
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
      setTimeout(() => setNotice(null), 8000);
    }
  };

  const handleSendManualReport = async () => {
    const emailToSend = manualReportEmail || automatedReporting.email;
    
    if (!emailToSend || !emailToSend.includes('@')) {
      setNotice('❌ Please enter a valid email address');
      setTimeout(() => setNotice(null), 3000);
      return;
    }

    if (automatedReporting.defaultPages.length === 0) {
      setNotice('❌ Please select at least one page in "Default Pages to Audit" above');
      setTimeout(() => setNotice(null), 3000);
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
      setTimeout(() => setNotice(null), 8000);
    }
  };

  // Google Analytics handlers
  const handleGaSaveCredentials = async () => {
    if (!gaClientId || !gaClientSecret) {
      setNotice('❌ Please enter both Client ID and Client Secret');
      return;
    }
    
    setGaLoading(true);
    try {
      const response = await axios.post(api('ga/save-credentials'), {
        client_id: gaClientId,
        client_secret: gaClientSecret
      }, { headers: { 'X-WP-Nonce': nonce } });
      
      if (response.data.success) {
        setNotice('✅ Credentials saved! Now click "Connect to Google Analytics"');
        setGaStatus({ ...gaStatus, has_credentials: true });
      }
    } catch (err: any) {
      setNotice('❌ Failed to save credentials: ' + (err.response?.data?.error || err.message));
    } finally {
      setGaLoading(false);
    }
  };

  const handleGaConnect = async () => {
    try {
      const response = await axios.get(api('ga/auth-url'), { headers: { 'X-WP-Nonce': nonce } });
      if (response.data.success && response.data.url) {
        window.location.href = response.data.url;
      }
    } catch (err: any) {
      setNotice('❌ ' + (err.response?.data?.error || 'Failed to get auth URL'));
    }
  };

  const handleGaLoadProperties = async () => {
    setGaLoading(true);
    try {
      const response = await axios.get(api('ga/properties'), { headers: { 'X-WP-Nonce': nonce } });
      if (response.data.success) {
        setGaProperties(response.data.properties);
      } else {
        setNotice('❌ ' + response.data.error);
      }
    } catch (err: any) {
      setNotice('❌ Failed to load properties: ' + (err.response?.data?.error || err.message));
    } finally {
      setGaLoading(false);
    }
  };

  const handleGaSelectProperty = async (propertyId: string, propertyName: string) => {
    setGaLoading(true);
    try {
      const response = await axios.post(api('ga/save-property'), {
        property_id: propertyId,
        property_name: propertyName
      }, { headers: { 'X-WP-Nonce': nonce } });
      
      if (response.data.success) {
        setNotice('✅ Google Analytics connected successfully!');
        setGaStatus({ connected: true, has_credentials: true, property_id: propertyId, property_name: propertyName });
        setGaProperties([]);
      }
    } catch (err: any) {
      setNotice('❌ ' + (err.response?.data?.error || 'Failed to save property'));
    } finally {
      setGaLoading(false);
    }
  };

  const handleGaDisconnect = async () => {
    if (!confirm('Are you sure you want to disconnect Google Analytics?')) return;
    
    setGaLoading(true);
    try {
      await axios.post(api('ga/disconnect'), {}, { headers: { 'X-WP-Nonce': nonce } });
      setNotice('✅ Google Analytics disconnected');
      setGaStatus({ connected: false, has_credentials: false });
      setGaClientId('');
      setGaClientSecret('');
    } catch (err: any) {
      setNotice('❌ Failed to disconnect');
    } finally {
      setGaLoading(false);
    }
  };

  const handlePageSelect = (id: number) => {
    setSelectedPages(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id]);
  };
  const handleRunAudit = async () => {
    if (!selectedPages.length) { setNotice('Select at least one page'); return; }
    
    // Check if business information is filled out
    const hasBusinessInfo = settings.industry && settings.product && settings.audience && settings.goal;
    if (!hasBusinessInfo) {
      setNotice('❌ Please fill out Business Information tab first before running audits');
      setTimeout(() => setNotice(null), 5000);
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
          
          // Log debug info if present
          if (result._debug) {
            console.log('🔍 Debug Info:', result._debug);
            if (result._debug.status === 'exception') {
              console.error('💥 EXCEPTION:', result._debug.error);
            }
            if (result._debug.status === 'success' && !result._debug.has_all_fields) {
              console.warn('⚠️ Missing required fields in AI response');
            }
          }
          
          // Warn if using fallback
          if (result.ai_used === false) {
            console.warn('⚠️ This audit used FALLBACK data - AI analysis failed!');
            if (result._debug?.error) {
              console.error('❌ Error Details:', result._debug.error);
            }
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
  const handleExportReport = (insert_id?: number) => {
    console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    console.log('📄 EXPORT REPORT INITIATED');
    console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    console.log('Audit ID provided:', insert_id);
    console.log('Audit ID type:', typeof insert_id);
    console.log('Audit ID truthy?', !!insert_id);
    
    if (!insert_id) {
      console.error('❌ Cannot export: No audit ID provided');
      setNotice('Cannot export - no audit ID');
      return;
    }
    
    console.log('✅ Audit ID validated:', insert_id);
    console.log('🌐 API endpoint:', api('report'));
    console.log('📦 Request payload:', { audit_id: insert_id });
    console.log('🔐 Nonce:', nonce ? 'Present' : 'MISSING');
    
    setLoading(true);
    setNotice('Generating report...');
    
    console.log('🚀 Sending POST request...');
    const startTime = Date.now();
    
    axios.post(api('report'), { audit_id: insert_id }, { headers: { 'X-WP-Nonce': nonce } })
      .then(r => {
        const duration = ((Date.now() - startTime) / 1000).toFixed(2);
        console.log(`✅ Request completed in ${duration}s`);
        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        console.log('📨 RESPONSE RECEIVED');
        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        console.log('Status:', r.status, r.statusText);
        console.log('Response data:', r.data);
        console.log('Response data type:', typeof r.data);
        console.log('Response data length:', r.data ? r.data.length : 'null/undefined');
        console.log('Is empty string?', r.data === '');
        console.log('Response headers:', {
          'content-type': r.headers['content-type'],
          'content-length': r.headers['content-length'],
          'x-wp-nonce': r.headers['x-wp-nonce']
        });
        
        if (typeof r.data === 'object' && r.data !== null) {
          console.log('Response data keys:', Object.keys(r.data));
          console.log('Has success?', 'success' in r.data);
          console.log('Has url?', 'url' in r.data);
          console.log('Has test?', 'test' in r.data);
        } else {
          console.log('Response is not an object - raw value:', r.data);
        }
        
        if (r.data && r.data.url) {
          console.log('✅ Report URL found:', r.data.url);
          
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
        } else if (r.data && r.data.test) {
          console.log('🧪 Test response received:', r.data.test);
          setNotice('Test response: ' + r.data.test);
        } else {
          console.error('⚠️ No URL in response');
          console.error('Expected r.data.url but got:', r.data);
          setNotice('Report generation failed - no URL returned');
        }
        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
      })
      .catch(err => {
        const duration = ((Date.now() - startTime) / 1000).toFixed(2);
        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        console.error(`❌ REQUEST FAILED after ${duration}s`);
        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        console.error('Error object:', err);
        console.error('Error message:', err.message);
        console.error('Error response:', err.response);
        if (err.response) {
          console.error('Error status:', err.response.status);
          console.error('Error data:', err.response.data);
          console.error('Error headers:', err.response.headers);
        }
        console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        setNotice('Report export failed - check console');
      })
      .finally(() => setLoading(false));
  };

  // Show loading spinner while checking auth
  if (authLoading) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '100vh', background: '#f3f4f6' }}>
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 48, marginBottom: 16 }}>⏳</div>
          <div style={{ fontSize: 18, color: '#6b7280' }}>Loading...</div>
        </div>
      </div>
    );
  }

  // Show login/register screen if not authenticated
  if (!isAuthenticated) {
    return (
      <div style={{ minHeight: '100vh', background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
        <div style={{ background: '#fff', borderRadius: 16, boxShadow: '0 20px 60px rgba(0,0,0,0.3)', maxWidth: 480, width: '100%', overflow: 'hidden' }}>
          <div style={{ background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', padding: 32, textAlign: 'center' }}>
            <h1 style={{ margin: 0, fontSize: 32, fontWeight: 800 }}>Conversion IQ</h1>
            <p style={{ margin: '8px 0 0 0', opacity: 0.9 }}>AI-Powered Conversion Audits</p>
          </div>

          <div style={{ padding: 32 }}>
            {notice && (
              <div style={{ background: notice.includes('❌') ? '#fee2e2' : '#d1fae5', border: `1px solid ${notice.includes('❌') ? '#fca5a5' : '#6ee7b7'}`, color: notice.includes('❌') ? '#991b1b' : '#065f46', borderRadius: 8, padding: 12, marginBottom: 20, fontSize: 14 }}>
                {notice}
              </div>
            )}

            <div style={{ display: 'flex', gap: 8, marginBottom: 24, borderBottom: '2px solid #e5e7eb' }}>
              <button
                onClick={() => setAuthMode('login')}
                style={{
                  flex: 1,
                  padding: '12px',
                  background: 'none',
                  border: 'none',
                  borderBottom: authMode === 'login' ? '2px solid #7c3aed' : 'none',
                  color: authMode === 'login' ? '#7c3aed' : '#6b7280',
                  fontWeight: 600,
                  cursor: 'pointer',
                  fontSize: 16,
                  marginBottom: -2
                }}
              >
                Login
              </button>
              <button
                onClick={() => setAuthMode('register')}
                style={{
                  flex: 1,
                  padding: '12px',
                  background: 'none',
                  border: 'none',
                  borderBottom: authMode === 'register' ? '2px solid #7c3aed' : 'none',
                  color: authMode === 'register' ? '#7c3aed' : '#6b7280',
                  fontWeight: 600,
                  cursor: 'pointer',
                  fontSize: 16,
                  marginBottom: -2
                }}
              >
                Create Account
              </button>
            </div>

            {authMode === 'login' ? (
              <div>
                <div style={{ marginBottom: 16 }}>
                  <label style={{ display: 'block', marginBottom: 6, fontWeight: 600, color: '#111827', fontSize: 14 }}>Username</label>
                  <input
                    type="text"
                    name="username"
                    value={authForm.username}
                    onChange={handleAuthFormChange}
                    placeholder="Enter your username"
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                  />
                </div>

                <div style={{ marginBottom: 20 }}>
                  <label style={{ display: 'block', marginBottom: 6, fontWeight: 600, color: '#111827', fontSize: 14 }}>Password</label>
                  <input
                    type="password"
                    name="password"
                    value={authForm.password}
                    onChange={handleAuthFormChange}
                    placeholder="Enter your password"
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                    onKeyPress={(e) => e.key === 'Enter' && handleLogin()}
                  />
                </div>

                <button
                  onClick={handleLogin}
                  disabled={loading}
                  style={{ width: '100%', padding: '14px', background: '#7c3aed', color: '#fff', border: 'none', borderRadius: 8, fontSize: 16, fontWeight: 600, cursor: loading ? 'not-allowed' : 'pointer', opacity: loading ? 0.6 : 1 }}
                >
                  {loading ? 'Logging in...' : 'Login'}
                </button>
              </div>
            ) : (
              <div>
                <div style={{ marginBottom: 16 }}>
                  <label style={{ display: 'block', marginBottom: 6, fontWeight: 600, color: '#111827', fontSize: 14 }}>Full Name</label>
                  <input
                    type="text"
                    name="fullName"
                    value={authForm.fullName}
                    onChange={handleAuthFormChange}
                    placeholder="John Doe"
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                  />
                </div>

                <div style={{ marginBottom: 16 }}>
                  <label style={{ display: 'block', marginBottom: 6, fontWeight: 600, color: '#111827', fontSize: 14 }}>Email</label>
                  <input
                    type="email"
                    name="email"
                    value={authForm.email}
                    onChange={handleAuthFormChange}
                    placeholder="john@company.com"
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                  />
                </div>

                <div style={{ marginBottom: 16 }}>
                  <label style={{ display: 'block', marginBottom: 6, fontWeight: 600, color: '#111827', fontSize: 14 }}>Company</label>
                  <input
                    type="text"
                    name="company"
                    value={authForm.company}
                    onChange={handleAuthFormChange}
                    placeholder="Acme Inc"
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                  />
                </div>

                <div style={{ marginBottom: 16 }}>
                  <label style={{ display: 'block', marginBottom: 6, fontWeight: 600, color: '#111827', fontSize: 14 }}>Username</label>
                  <input
                    type="text"
                    name="username"
                    value={authForm.username}
                    onChange={handleAuthFormChange}
                    placeholder="Choose a username"
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                  />
                </div>

                <div style={{ marginBottom: 20 }}>
                  <label style={{ display: 'block', marginBottom: 6, fontWeight: 600, color: '#111827', fontSize: 14 }}>Password</label>
                  <input
                    type="password"
                    name="password"
                    value={authForm.password}
                    onChange={handleAuthFormChange}
                    placeholder="Create a password"
                    style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none' }}
                    onFocus={(e) => e.target.style.borderColor = '#7c3aed'}
                    onBlur={(e) => e.target.style.borderColor = '#d1d5db'}
                    onKeyPress={(e) => e.key === 'Enter' && handleRegister()}
                  />
                </div>

                <button
                  onClick={handleRegister}
                  disabled={loading}
                  style={{ width: '100%', padding: '14px', background: '#7c3aed', color: '#fff', border: 'none', borderRadius: 8, fontSize: 16, fontWeight: 600, cursor: loading ? 'not-allowed' : 'pointer', opacity: loading ? 0.6 : 1 }}
                >
                  {loading ? 'Creating Account...' : 'Create Account'}
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="ciq-frontend-root" style={{ minHeight: '100vh', background: '#f3f4f6', padding: 0, fontFamily: 'Inter,Arial,Helvetica,sans-serif' }}>
      <header style={{ background: 'linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%)', color: '#fff', padding: '32px 0', boxShadow: '0 4px 20px rgba(0,0,0,0.1)', marginBottom: 40 }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', padding: '0 32px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 24, marginBottom: 20 }}>
            <img 
              src={`${(window as any).ConversionIQData?.pluginUrl || ''}/assets/images/Webtec.png`} 
              alt="Webtec" 
              style={{ width: 140, height: 'auto' }} 
            />
            <div style={{ height: 40, width: 1, background: 'rgba(255,255,255,0.3)' }}></div>
            <div>
              <h1 style={{ margin: 0, fontWeight: 800, fontSize: 36, letterSpacing: -1 }}>Conversion IQ</h1>
              <p style={{ margin: '4px 0 0 0', fontSize: 16, opacity: 0.9 }}>AI-powered conversion audits & recommendations</p>
            </div>
          </div>
          <div style={{ padding: '16px 20px', background: 'rgba(255,255,255,0.12)', borderRadius: 12, fontSize: 14, lineHeight: 1.7, borderLeft: '4px solid rgba(255,255,255,0.3)' }}>
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
              onClick={() => setActiveTab('account')}
              style={{
                flex: 1,
                padding: '16px 24px',
                background: activeTab === 'account' ? '#7c3aed' : '#fff',
                color: activeTab === 'account' ? '#fff' : '#6b7280',
                border: 'none',
                borderBottom: activeTab === 'account' ? '3px solid #5b21b6' : '3px solid transparent',
                cursor: 'pointer',
                fontSize: 16,
                fontWeight: 600,
                transition: 'all 0.2s'
              }}
            >
              Account
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
            </div>            <div style={{ marginBottom: 16 }}>
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

                <button
                  className="ciq-btn primary"
                  onClick={handleSaveAutomatedSettings}
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
          </section>
        )}

        {/* Audits Tab */}
        {activeTab === 'audits' && (
          <>
            {/* Pages to Analyze Section */}
            <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32, marginBottom: 24 }}>
              <h2 style={{ margin: '0 0 8px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>Select Pages to Analyze</h2>
              <p style={{ color: '#6b7280', marginBottom: 20, fontSize: 15 }}>Choose which pages you want to audit now.</p>
              <div style={{ maxHeight: 240, overflow: 'auto', border: '1px solid #d1d5db', borderRadius: 8, padding: 16, background: '#f9fafb' }}>
                {pages.length === 0 ? (
                  <div style={{ color: '#9ca3af', textAlign: 'center', padding: 20 }}>No pages found. Please publish some pages first.</div>
                ) : (
                  pages.map(p => (
                    <label key={p.id} style={{ display: 'flex', alignItems: 'center', padding: '10px 12px', marginBottom: 8, background: selectedPages.includes(p.id) ? '#f3e8ff' : '#fff', borderRadius: 6, cursor: 'pointer', transition: 'all 0.2s', border: '1px solid transparent' }} onMouseEnter={(e) => e.currentTarget.style.borderColor = '#a78bfa'} onMouseLeave={(e) => !selectedPages.includes(p.id) && (e.currentTarget.style.borderColor = 'transparent')}>
                      <input type="checkbox" checked={selectedPages.includes(p.id)} onChange={() => handlePageSelect(p.id)} style={{ marginRight: 12, width: 18, height: 18, cursor: 'pointer', accentColor: '#7c3aed' }} />
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
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 14, fontWeight: 600, padding: '6px 12px', borderRadius: 8, background: a.ai_used === false ? '#fef3c7' : '#f3e8ff', color: a.ai_used === false ? '#92400e' : '#7c3aed' }}>
                  <span>{a.ai_used === false ? '⚠' : '✓'}</span>
                  <span>{a.ai_used === false ? 'Fallback' : 'AI Powered'}</span>
                </div>
              </div>
              
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
                            setTimeout(() => setNotice(null), 3000);
                          } else {
                            throw new Error('Retry failed');
                          }
                        } catch (err: any) {
                          console.error('❌ Retry failed:', err);
                          setNotice('Retry failed - please try again later.');
                          setTimeout(() => setNotice(null), 5000);
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
                    <>
                      <button className="ciq-btn primary" onClick={() => setModal({ audit: a, open: true, tab: 'overview' })} style={{ flex: 1, padding: '12px 20px', background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)', color: '#fff', border: 'none', borderRadius: 10, fontSize: 15, fontWeight: 600, cursor: 'pointer', boxShadow: '0 4px 12px rgba(124, 58, 237, 0.3)', transition: 'transform 0.2s' }} onMouseEnter={(e) => e.currentTarget.style.transform = 'translateY(-2px)'} onMouseLeave={(e) => e.currentTarget.style.transform = 'translateY(0)'}>
                        View Full Report
                      </button>
                      <button className="ciq-btn" onClick={() => handleExportReport(a.insert_id)} style={{ padding: '12px 20px', background: '#fff', color: '#7c3aed', border: '1px solid #7c3aed', borderRadius: 10, fontSize: 15, fontWeight: 600, cursor: 'pointer', transition: 'all 0.2s' }} onMouseEnter={(e) => { e.currentTarget.style.background = '#7c3aed'; e.currentTarget.style.color = '#fff'; }} onMouseLeave={(e) => { e.currentTarget.style.background = '#fff'; e.currentTarget.style.color = '#7c3aed'; }}>
                        Export PDF
                      </button>
                    </>
                  )}
                </div>
                <a
                  href={`mailto:support@trywebtec.com?subject=Free 15-Min Expert Review Request - ${encodeURIComponent(a.page_title || 'Audit')}&body=Hi Webtec Team,%0D%0A%0D%0AI'd like to schedule a FREE 15-minute expert review of my Conversion IQ audit results.%0D%0A%0D%0APage: ${encodeURIComponent(a.page_title || 'N/A')}%0D%0AAudit Date: ${a.created_at ? encodeURIComponent(new Date(a.created_at).toLocaleDateString()) : 'N/A'}%0D%0A%0D%0APlease let me know your availability.%0D%0A%0D%0AThank you!`}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: 8,
                    padding: '10px 16px',
                    background: 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
                    color: '#fff',
                    textDecoration: 'none',
                    borderRadius: 10,
                    fontSize: 14,
                    fontWeight: 600,
                    transition: 'all 0.2s',
                    boxShadow: '0 2px 8px rgba(16, 185, 129, 0.25)',
                    border: 'none'
                  }}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.transform = 'translateY(-2px)';
                    e.currentTarget.style.boxShadow = '0 4px 12px rgba(16, 185, 129, 0.35)';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.transform = 'translateY(0)';
                    e.currentTarget.style.boxShadow = '0 2px 8px rgba(16, 185, 129, 0.25)';
                  }}
                >
                  <span>🎯 Get FREE 15-Min Expert Review</span>
                </a>
              </div>
            </div>
          ))}
                    </div>
                  </div>
                ))
              })()}
            </section>
          </>
        )}

        {/* Account Tab */}
        {activeTab === 'account' && (
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <h2 style={{ margin: '0 0 8px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>Account Settings</h2>
            <p style={{ color: '#6b7280', marginBottom: 32, fontSize: 15 }}>
              Manage your account information and API credentials.
            </p>

            <div style={{ background: '#f9fafb', borderRadius: 12, padding: 24, marginBottom: 24, border: '1px solid #e5e7eb' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600, color: '#111827' }}>Account Information</h3>
                {!isEditingAccount && (
                  <button
                    onClick={handleEditAccount}
                    style={{
                      padding: '8px 16px',
                      background: '#7c3aed',
                      color: '#fff',
                      border: 'none',
                      borderRadius: 6,
                      fontSize: 14,
                      fontWeight: 600,
                      cursor: 'pointer',
                      transition: 'all 0.2s'
                    }}
                    onMouseEnter={(e) => e.currentTarget.style.background = '#6d28d9'}
                    onMouseLeave={(e) => e.currentTarget.style.background = '#7c3aed'}
                  >
                    ✏️ Edit
                  </button>
                )}
              </div>
              
              {!isEditingAccount ? (
                <div style={{ display: 'grid', gap: 16 }}>
                  <div>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Full Name</div>
                    <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>{account?.full_name || 'Not set'}</div>
                  </div>
                  <div>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Email</div>
                    <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>{account?.email || 'Not set'}</div>
                  </div>
                  <div>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Company</div>
                    <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>{account?.company || 'Not set'}</div>
                  </div>
                  <div>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Username</div>
                    <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>{account?.username || 'Not set'}</div>
                  </div>
                  <div>
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 4, fontWeight: 500 }}>Member Since</div>
                    <div style={{ fontSize: 16, color: '#111827', fontWeight: 500 }}>
                      {account?.created_at ? new Date(account.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Unknown'}
                    </div>
                  </div>
                </div>
              ) : (
                <div style={{ display: 'grid', gap: 16 }}>
                  <div>
                    <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>Full Name</label>
                    <input
                      type="text"
                      name="full_name"
                      value={accountForm.full_name}
                      onChange={handleAccountFormChange}
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
                  </div>
                  <div>
                    <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>Email</label>
                    <input
                      type="email"
                      name="email"
                      value={accountForm.email}
                      onChange={handleAccountFormChange}
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
                  </div>
                  <div>
                    <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>Company</label>
                    <input
                      type="text"
                      name="company"
                      value={accountForm.company}
                      onChange={handleAccountFormChange}
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
                  </div>
                  <div>
                    <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>Username</label>
                    <input
                      type="text"
                      name="username"
                      value={accountForm.username}
                      onChange={handleAccountFormChange}
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
                  </div>
                  <div style={{ display: 'flex', gap: 12, marginTop: 8 }}>
                    <button
                      onClick={handleUpdateAccount}
                      disabled={loading}
                      style={{
                        padding: '12px 24px',
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
                      {loading ? 'Saving...' : '✓ Save Changes'}
                    </button>
                    <button
                      onClick={handleCancelEditAccount}
                      disabled={loading}
                      style={{
                        padding: '12px 24px',
                        background: '#fff',
                        color: '#6b7280',
                        border: '1px solid #d1d5db',
                        borderRadius: 8,
                        fontSize: 15,
                        fontWeight: 600,
                        cursor: loading ? 'not-allowed' : 'pointer',
                        transition: 'all 0.2s'
                      }}
                      onMouseEnter={(e) => !loading && (e.currentTarget.style.background = '#f9fafb')}
                      onMouseLeave={(e) => !loading && (e.currentTarget.style.background = '#fff')}
                    >
                      Cancel
                    </button>
                  </div>
                </div>
              )}
            </div>

            <div style={{ background: '#fef3c7', borderRadius: 12, padding: 24, marginBottom: 24, border: '1px solid #fde68a' }}>
              <h3 style={{ margin: '0 0 8px 0', fontSize: 18, fontWeight: 600, color: '#92400e' }}>🔑 Your API Key</h3>
              <p style={{ fontSize: 14, color: '#78350f', marginBottom: 16 }}>
                This key is automatically generated for webhook authentication. Keep it secure!
              </p>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <input
                  type="text"
                  value={account?.api_key || 'Loading...'}
                  readOnly
                  style={{
                    flex: 1,
                    padding: '12px 16px',
                    background: '#fff',
                    border: '1px solid #fbbf24',
                    borderRadius: 8,
                    fontSize: 14,
                    fontFamily: 'monospace',
                    color: '#111827'
                  }}
                />
                <button
                  onClick={() => {
                    navigator.clipboard.writeText(account?.api_key || '');
                    setNotice('✅ API Key copied to clipboard!');
                    setTimeout(() => setNotice(null), 2000);
                  }}
                  style={{
                    padding: '12px 20px',
                    background: '#f59e0b',
                    color: '#fff',
                    border: 'none',
                    borderRadius: 8,
                    fontSize: 14,
                    fontWeight: 600,
                    cursor: 'pointer',
                    whiteSpace: 'nowrap'
                  }}
                >
                  Copy Key
                </button>
              </div>
              <p style={{ fontSize: 12, color: '#78350f', marginTop: 12, marginBottom: 0 }}>
                💡 Use this key in your support portal's webhook configuration (WEBHOOK_API_KEY environment variable)
              </p>
            </div>

            {/* Google Analytics Integration */}
            <div style={{ marginTop: 32, paddingTop: 32, borderTop: '2px solid #e5e7eb' }}>
              <h2 style={{ margin: '0 0 8px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>
                📊 Google Analytics Integration
              </h2>
              <p style={{ color: '#6b7280', marginBottom: 24, fontSize: 15 }}>
                Connect Google Analytics to pull real conversion data and enhance audit insights with actual performance metrics.
              </p>

              {!gaStatus.connected ? (
                <>
                  {!gaStatus.has_credentials ? (
                    <div style={{ background: '#f3f4f6', padding: 24, borderRadius: 12, marginBottom: 20 }}>
                      <h3 style={{ margin: '0 0 16px 0', fontSize: 18, fontWeight: 600, color: '#111827' }}>
                        Step 1: Configure Google API Credentials
                      </h3>
                      <p style={{ fontSize: 14, color: '#6b7280', marginBottom: 16 }}>
                        Create OAuth credentials in{' '}
                        <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" style={{ color: '#7c3aed', textDecoration: 'none', fontWeight: 600 }}>
                          Google Cloud Console
                        </a>
                        {' '}and enter them below.
                      </p>
                      <div style={{ marginBottom: 16 }}>
                        <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>
                          Client ID
                        </label>
                        <input
                          type="text"
                          placeholder="Enter Google OAuth Client ID"
                          value={gaClientId}
                          onChange={(e) => setGaClientId(e.target.value)}
                          style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }}
                          onFocus={(e) => e.currentTarget.style.borderColor = '#7c3aed'}
                          onBlur={(e) => e.currentTarget.style.borderColor = '#d1d5db'}
                        />
                      </div>
                      <div style={{ marginBottom: 16 }}>
                        <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, color: '#111827', fontSize: 14 }}>
                          Client Secret
                        </label>
                        <input
                          type="password"
                          placeholder="Enter Google OAuth Client Secret"
                          value={gaClientSecret}
                          onChange={(e) => setGaClientSecret(e.target.value)}
                          style={{ width: '100%', padding: '12px 16px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, outline: 'none', transition: 'border 0.2s', background: '#fff', color: '#111827' }}
                          onFocus={(e) => e.currentTarget.style.borderColor = '#7c3aed'}
                          onBlur={(e) => e.currentTarget.style.borderColor = '#d1d5db'}
                        />
                      </div>
                      <button
                        onClick={handleGaSaveCredentials}
                        disabled={gaLoading}
                        style={{
                          padding: '12px 24px',
                          background: gaLoading ? '#d1d5db' : '#7c3aed',
                          color: '#fff',
                          border: 'none',
                          borderRadius: 8,
                          fontSize: 15,
                          fontWeight: 600,
                          cursor: gaLoading ? 'not-allowed' : 'pointer',
                          transition: 'all 0.2s'
                        }}
                      >
                        {gaLoading ? 'Saving...' : 'Save Credentials'}
                      </button>
                    </div>
                  ) : gaProperties.length === 0 ? (
                    <div style={{ background: '#f3f4f6', padding: 24, borderRadius: 12 }}>
                      <h3 style={{ margin: '0 0 16px 0', fontSize: 18, fontWeight: 600, color: '#111827' }}>
                        Step 2: Connect to Google Analytics
                      </h3>
                      <p style={{ fontSize: 14, color: '#6b7280', marginBottom: 16 }}>
                        Click the button below to authorize access to your Google Analytics account.
                      </p>
                      <button
                        onClick={handleGaConnect}
                        style={{
                          padding: '12px 24px',
                          background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)',
                          color: '#fff',
                          border: 'none',
                          borderRadius: 8,
                          fontSize: 15,
                          fontWeight: 600,
                          cursor: 'pointer',
                          boxShadow: '0 4px 12px rgba(124, 58, 237, 0.3)',
                          transition: 'transform 0.2s'
                        }}
                        onMouseEnter={(e) => e.currentTarget.style.transform = 'translateY(-2px)'}
                        onMouseLeave={(e) => e.currentTarget.style.transform = 'translateY(0)'}
                      >
                        🔗 Connect to Google Analytics
                      </button>
                    </div>
                  ) : (
                    <div style={{ background: '#f3f4f6', padding: 24, borderRadius: 12 }}>
                      <h3 style={{ margin: '0 0 16px 0', fontSize: 18, fontWeight: 600, color: '#111827' }}>
                        Step 3: Select a Property
                      </h3>
                      <p style={{ fontSize: 14, color: '#6b7280', marginBottom: 16 }}>
                        Choose which Google Analytics property to use:
                      </p>
                      <div style={{ maxHeight: 300, overflow: 'auto' }}>
                        {gaProperties.map((prop: any) => (
                          <div
                            key={prop.id}
                            onClick={() => handleGaSelectProperty(prop.id, prop.name)}
                            style={{
                              padding: 16,
                              background: '#fff',
                              border: '1px solid #d1d5db',
                              borderRadius: 8,
                              marginBottom: 12,
                              cursor: 'pointer',
                              transition: 'all 0.2s'
                            }}
                            onMouseEnter={(e) => {
                              e.currentTarget.style.borderColor = '#7c3aed';
                              e.currentTarget.style.background = '#f3e8ff';
                            }}
                            onMouseLeave={(e) => {
                              e.currentTarget.style.borderColor = '#d1d5db';
                              e.currentTarget.style.background = '#fff';
                            }}
                          >
                            <div style={{ fontWeight: 600, color: '#111827', marginBottom: 4 }}>
                              {prop.name}
                            </div>
                            <div style={{ fontSize: 13, color: '#6b7280' }}>
                              Account: {prop.account}
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </>
              ) : (
                <div style={{ background: '#d1fae5', padding: 24, borderRadius: 12, border: '2px solid #10b981' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', marginBottom: 16 }}>
                    <div>
                      <div style={{ fontSize: 18, fontWeight: 700, color: '#065f46', marginBottom: 8 }}>
                        ✅ Connected to Google Analytics
                      </div>
                      <div style={{ fontSize: 15, color: '#059669' }}>
                        Property: <strong>{gaStatus.property_name || gaStatus.property_id}</strong>
                      </div>
                    </div>
                    <button
                      onClick={handleGaDisconnect}
                      disabled={gaLoading}
                      style={{
                        padding: '8px 16px',
                        background: '#fff',
                        color: '#dc2626',
                        border: '1px solid #dc2626',
                        borderRadius: 6,
                        fontSize: 13,
                        fontWeight: 600,
                        cursor: gaLoading ? 'not-allowed' : 'pointer',
                        transition: 'all 0.2s'
                      }}
                      onMouseEnter={(e) => !gaLoading && (e.currentTarget.style.background = '#fef2f2')}
                      onMouseLeave={(e) => !gaLoading && (e.currentTarget.style.background = '#fff')}
                    >
                      Disconnect
                    </button>
                  </div>
                  <div style={{ fontSize: 14, color: '#059669', lineHeight: 1.6 }}>
                    Real conversion data will now be included in audit reports. The plugin will automatically pull metrics like:
                    <ul style={{ marginTop: 8, marginBottom: 0, paddingLeft: 24 }}>
                      <li>Page views and conversion rates</li>
                      <li>Bounce rate and engagement metrics</li>
                      <li>Average session duration</li>
                    </ul>
                  </div>
                </div>
              )}
            </div>

            <button
              onClick={handleLogout}
              style={{
                marginTop: 32,
                padding: '12px 24px',
                background: '#ef4444',
                color: '#fff',
                border: 'none',
                borderRadius: 8,
                fontSize: 15,
                fontWeight: 600,
                cursor: 'pointer',
                transition: 'all 0.2s'
              }}
              onMouseEnter={(e) => e.currentTarget.style.background = '#dc2626'}
              onMouseLeave={(e) => e.currentTarget.style.background = '#ef4444'}
            >
              Logout
            </button>
          </section>
        )}

        {/* FAQ Tab */}
        {activeTab === 'faq' && (
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <div style={{ marginBottom: 32, textAlign: 'center' }}>
              <h2 style={{ margin: '0 0 12px 0', fontSize: 28, fontWeight: 700, color: '#111827' }}>Frequently Asked Questions</h2>
              <p style={{ color: '#6b7280', fontSize: 16, maxWidth: 700, margin: '0 auto' }}>
                Everything you need to know about Conversion IQ and how Webtec can help you maximize your website's performance.
              </p>
            </div>

            <div style={{ maxWidth: 800, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 20 }}>
              {[
                {
                  q: "What is Conversion IQ and how does it work?",
                  a: "Conversion IQ is an AI-powered conversion analysis tool that audits your website pages across six critical performance metrics: Conversion Clarity, Emotional Resonance, CTA Strength, Readability, Engagement, and Trust. It analyzes your actual content in the context of your business goals, target audience, and competitive landscape to provide specific, actionable recommendations for improving conversion rates."
                },
                {
                  q: "Why do I need Webtec if the AI provides recommendations?",
                  a: "While the AI identifies issues and suggests improvements, Webtec ensures proper implementation, testing, and optimization. Our team brings years of conversion expertise to interpret the data, prioritize changes based on impact, and execute solutions correctly. Think of it as the difference between a diagnostic report and professional treatment—both are necessary for optimal results."
                },
                {
                  q: "Are the suggestions personalized to my business?",
                  a: "Yes. Every audit analyzes your specific page content, business objectives, target audience, and competitive context. The recommendations become increasingly refined as you run audits over time, especially after implementing changes. Additionally, each audit includes a complimentary 15-minute expert consultation with our team to provide personalized guidance."
                },
                {
                  q: "What's included in the FREE 15-minute expert review?",
                  a: "Each audit includes a complimentary consultation with a Webtec conversion specialist. During this session, we review your audit results, answer questions, help prioritize recommendations by impact, and provide guidance on implementation strategies. This ensures you understand your data and can make informed decisions about next steps."
                },
                {
                  q: "How is this different from SEO or analytics tools?",
                  a: "Traditional tools focus on traffic acquisition and technical performance. Conversion IQ focuses on what happens after visitors arrive—whether they understand your value proposition, trust your brand, and take desired actions. We analyze conversion psychology, message clarity, and persuasive elements that other tools don't measure."
                },
                {
                  q: "Can I implement changes myself?",
                  a: "Yes, you can implement recommendations independently if you have the technical capability and conversion expertise. However, many clients choose to work with Webtec to ensure changes follow proven conversion patterns, avoid common pitfalls, and achieve measurable results faster. We provide implementation support at various service levels to match your needs."
                },
                {
                  q: "What is the 'Suggested Functionality' tab?",
                  a: "Based on your audit results and business goals, this section recommends features or integrations that could enhance conversion performance—such as live chat, e-commerce capabilities, or marketing automation. Each recommendation explains why it would benefit your specific situation. These are optional suggestions to help you identify growth opportunities."
                },
                {
                  q: "How often should I run audits?",
                  a: "We recommend running audits: (1) As a baseline when starting, (2) After implementing significant changes, (3) Quarterly to track performance trends, (4) Before major campaigns or launches. The Automated Reports feature can schedule regular audits to maintain consistent monitoring without manual intervention."
                },
                {
                  q: "What happens after I receive my audit results?",
                  a: "You have several options: Review and implement suggestions independently, schedule your complimentary expert consultation for guidance, request a detailed implementation proposal from Webtec, or simply monitor your scores over time. The tool is designed to provide value at whatever level of engagement works for your business."
                },
                {
                  q: "How should I interpret the scoring system?",
                  a: "Scores range from 0-100 across six metrics. Generally: 80+ indicates strong performance, 60-79 shows room for improvement, and below 60 suggests priority attention needed. However, context matters—your industry, audience, and goals affect what constitutes a 'good' score. Your expert review consultation can help interpret results specific to your situation."
                }
              ].map((faq, i) => (
                <div 
                  key={i} 
                  style={{ 
                    background: '#f9fafb', 
                    borderRadius: 12, 
                    padding: 24, 
                    border: '1px solid #e5e7eb',
                    transition: 'all 0.2s'
                  }}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.borderColor = '#7c3aed';
                    e.currentTarget.style.boxShadow = '0 4px 12px rgba(124, 58, 237, 0.1)';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.borderColor = '#e5e7eb';
                    e.currentTarget.style.boxShadow = 'none';
                  }}
                >
                  <h3 style={{ margin: '0 0 12px 0', fontSize: 18, fontWeight: 700, color: '#111827' }}>
                    {faq.q}
                  </h3>
                  <p style={{ margin: 0, color: '#374151', lineHeight: 1.7, fontSize: 15 }}>
                    {faq.a}
                  </p>
                </div>
              ))}

              <div style={{ marginTop: 32, padding: 32, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', borderRadius: 16, textAlign: 'center', color: '#fff' }}>
                <h3 style={{ margin: '0 0 12px 0', fontSize: 24, fontWeight: 700 }}>Still Have Questions?</h3>
                <p style={{ margin: '0 0 20px 0', fontSize: 16, opacity: 0.95 }}>
                  Our team is here to help. Schedule your FREE expert review or reach out with any questions.
                </p>
                <a
                  href="mailto:support@trywebtec.com?subject=Conversion IQ Question&body=Hi! I have a question about Conversion IQ:%0D%0A%0D%0A[Your question here]"
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 8,
                    padding: '12px 32px',
                    background: '#fff',
                    color: '#7c3aed',
                    textDecoration: 'none',
                    borderRadius: 10,
                    fontSize: 16,
                    fontWeight: 600,
                    transition: 'all 0.2s',
                    boxShadow: '0 4px 12px rgba(0, 0, 0, 0.2)'
                  }}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.transform = 'translateY(-2px)';
                    e.currentTarget.style.boxShadow = '0 6px 16px rgba(0, 0, 0, 0.3)';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.transform = 'translateY(0)';
                    e.currentTarget.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.2)';
                  }}
                >
                  📧 Contact Webtec Support
                </a>
              </div>
            </div>
          </section>
        )}
      </main>

        {modal.open && modal.audit && (
          <div style={{ position: 'fixed', left: 0, top: 0, width: '100vw', height: '100vh', background: 'rgba(0,0,0,0.5)', zIndex: 9999, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }} onClick={() => setModal({ open: false })}>
            <div style={{ background: '#fff', borderRadius: 12, padding: 0, maxWidth: 900, width: '100%', maxHeight: '90vh', overflow: 'hidden', display: 'flex', flexDirection: 'column' }} onClick={(e) => e.stopPropagation()}>
              {/* Header */}
              <div style={{ padding: '20px 24px', borderBottom: '1px solid #eee', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div>
                  <h3 style={{ margin: 0, fontSize: 22, fontWeight: 700 }}>Audit Report: {modal.audit.page_title}</h3>
                  <div style={{ fontSize: 13, color: '#666', marginTop: 4 }}>
                    AI Powered: {modal.audit.ai_used === false ? '✗ Fallback Mode' : '✓ Active'}
                    {modal.audit.created_at && (
                      <span style={{ marginLeft: 12 }}>
                        • Run on {new Date(modal.audit.created_at).toLocaleString()}
                      </span>
                    )}
                  </div>
                </div>
                <button onClick={() => setModal({ open: false })} style={{ background: 'none', border: 'none', fontSize: 24, cursor: 'pointer', color: '#999' }}>×</button>
              </div>

              {/* Tabs */}
              <div style={{ display: 'flex', gap: 0, borderBottom: '1px solid #eee', padding: '0 24px', background: '#fafafa' }}>
                {['overview', 'insights', 'suggestions', 'functionality'].map(tab => (
                  <button 
                    key={tab} 
                    onClick={() => setModal({ ...modal, tab })}
                    style={{ 
                      padding: '12px 20px', 
                      background: modal.tab === tab ? '#fff' : 'transparent',
                      border: 'none',
                      borderBottom: modal.tab === tab ? '2px solid #7c3aed' : '2px solid transparent',
                      cursor: 'pointer',
                      fontWeight: modal.tab === tab ? 600 : 400,
                      color: modal.tab === tab ? '#7c3aed' : '#666',
                      textTransform: 'capitalize'
                    }}
                  >
                    {tab === 'functionality' ? 'Suggested Functionality' : tab === 'suggestions' ? 'Copy Suggestions' : tab}
                  </button>
                ))}
              </div>

              {/* Content */}
              <div style={{ padding: 24, overflowY: 'auto', flex: 1 }}>
                {modal.tab === 'overview' && (
                  <div>
                    <h4 style={{ marginTop: 0, marginBottom: 16 }}>Performance Scores</h4>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 24 }}>
                      {[
                        { label: 'Conversion Clarity', value: modal.audit.clarity_score, color: '#2b9af3' },
                        { label: 'Emotional Resonance', value: modal.audit.emotional_score, color: '#f39c12' },
                        { label: 'CTA Strength', value: modal.audit.cta_strength, color: '#27ae60' },
                        { label: 'Readability', value: modal.audit.readability_score, color: '#9333ea' },
                        { label: 'Engagement', value: modal.audit.engagement_score, color: '#d97706' },
                        { label: 'Trust', value: modal.audit.trust_score, color: '#0284c7' },
                      ].map((metric, idx) => metric.value ? (
                        <div key={idx} style={{ padding: 16, background: '#f9fafb', borderRadius: 8, textAlign: 'center' }}>
                          <div style={{ fontSize: 13, color: '#666', marginBottom: 8 }}>{metric.label}</div>
                          <div style={{ fontSize: 32, fontWeight: 700, color: metric.color }}>{metric.value}</div>
                          <div style={{ marginTop: 8, height: 6, background: '#e5e7eb', borderRadius: 3, overflow: 'hidden' }}>
                            <div style={{ width: `${metric.value}%`, height: 6, background: metric.color, transition: 'width 0.3s' }}></div>
                          </div>
                        </div>
                      ) : null)}
                    </div>
                    
                    {/* Google Analytics Metrics */}
                    {modal.gaData && (
                      <div style={{ marginBottom: 24, padding: 20, background: 'linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%)', borderRadius: 12, border: '2px solid #0284c7' }}>
                        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 16 }}>
                          <span style={{ fontSize: 24, marginRight: 10 }}>📊</span>
                          <div>
                            <h4 style={{ margin: 0, color: '#0c4a6e', fontSize: 18 }}>Google Analytics Data (Last 30 Days)</h4>
                            <p style={{ margin: 0, fontSize: 13, color: '#075985' }}>Real conversion metrics from your site</p>
                          </div>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12 }}>
                          <div style={{ padding: 14, background: '#fff', borderRadius: 8, textAlign: 'center', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
                            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>Page Views</div>
                            <div style={{ fontSize: 26, fontWeight: 700, color: '#0284c7' }}>{modal.gaData.pageViews.toLocaleString()}</div>
                          </div>
                          <div style={{ padding: 14, background: '#fff', borderRadius: 8, textAlign: 'center', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
                            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>Conversions</div>
                            <div style={{ fontSize: 26, fontWeight: 700, color: '#10b981' }}>{modal.gaData.conversions.toLocaleString()}</div>
                          </div>
                          <div style={{ padding: 14, background: '#fff', borderRadius: 8, textAlign: 'center', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
                            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>Conversion Rate</div>
                            <div style={{ fontSize: 26, fontWeight: 700, color: '#7c3aed' }}>{modal.gaData.conversionRate}%</div>
                          </div>
                          <div style={{ padding: 14, background: '#fff', borderRadius: 8, textAlign: 'center', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
                            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>Bounce Rate</div>
                            <div style={{ fontSize: 26, fontWeight: 700, color: '#dc2626' }}>{modal.gaData.bounceRate}%</div>
                          </div>
                          <div style={{ padding: 14, background: '#fff', borderRadius: 8, textAlign: 'center', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
                            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>Engagement Rate</div>
                            <div style={{ fontSize: 26, fontWeight: 700, color: '#f59e0b' }}>{modal.gaData.engagementRate}%</div>
                          </div>
                          <div style={{ padding: 14, background: '#fff', borderRadius: 8, textAlign: 'center', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
                            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>Avg. Session (sec)</div>
                            <div style={{ fontSize: 26, fontWeight: 700, color: '#6366f1' }}>{modal.gaData.avgSessionDuration}</div>
                          </div>
                        </div>
                        <div style={{ marginTop: 12, fontSize: 12, color: '#075985', textAlign: 'center', fontStyle: 'italic' }}>
                          This data helps identify opportunities to improve your conversion rate
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {modal.tab === 'insights' && (
                  <div>
                    {modal.audit.insights?.executive_summary && (
                      <div style={{ marginBottom: 24, padding: 20, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', borderRadius: 12, color: '#fff', boxShadow: '0 4px 12px rgba(102, 126, 234, 0.3)' }}>
                        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
                          <span style={{ fontSize: 28, marginRight: 10 }}>📊</span>
                          <h4 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Executive Summary</h4>
                        </div>
                        <p style={{ margin: 0, fontSize: 15, lineHeight: 1.7, opacity: 0.95 }}>{modal.audit.insights.executive_summary}</p>
                      </div>
                    )}

                    {modal.audit.insights?.top_priority_insight && (
                      <div style={{ marginBottom: 24, padding: 20, background: '#fff7ed', borderRadius: 12, border: '2px solid #f59e0b', boxShadow: '0 2px 8px rgba(245, 158, 11, 0.15)' }}>
                        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
                          <span style={{ fontSize: 28, marginRight: 10 }}>🎯</span>
                          <h4 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#ea580c' }}>Top Priority Focus</h4>
                        </div>
                        <p style={{ margin: 0, fontSize: 15, lineHeight: 1.7, color: '#374151' }}>{modal.audit.insights.top_priority_insight}</p>
                      </div>
                    )}

                    {modal.audit.insights?.strengths && modal.audit.insights.strengths.length > 0 && (
                      <div style={{ marginBottom: 24 }}>
                        <h4 style={{ color: '#27ae60', marginBottom: 12, fontSize: 17, fontWeight: 700 }}>💪 What's Working Well</h4>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                          {modal.audit.insights.strengths.map((s, i) => (
                            <div key={i} style={{ background: '#f0fdf4', padding: 16, borderRadius: 8, borderLeft: '4px solid #27ae60', fontSize: 14, lineHeight: 1.6, color: '#374151' }}>{s}</div>
                          ))}
                        </div>
                      </div>
                    )}

                    {modal.audit.insights?.weaknesses && modal.audit.insights.weaknesses.length > 0 && (
                      <div style={{ marginBottom: 24 }}>
                        <h4 style={{ color: '#dc2626', marginBottom: 12, fontSize: 17, fontWeight: 700 }}>⚠️ Areas for Improvement</h4>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                          {modal.audit.insights.weaknesses.map((w, i) => (
                            <div key={i} style={{ background: '#fef2f2', padding: 16, borderRadius: 8, borderLeft: '4px solid #dc2626', fontSize: 14, lineHeight: 1.6, color: '#374151' }}>{w}</div>
                          ))}
                        </div>
                      </div>
                    )}

                    {modal.audit.insights?.opportunities && modal.audit.insights.opportunities.length > 0 && (
                      <div style={{ marginBottom: 24 }}>
                        <h4 style={{ color: '#0284c7', marginBottom: 12, fontSize: 17, fontWeight: 700 }}>🚀 Growth Opportunities</h4>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                          {modal.audit.insights.opportunities.map((o, i) => (
                            <div key={i} style={{ background: '#e0f2fe', padding: 16, borderRadius: 8, borderLeft: '4px solid #0284c7', fontSize: 14, lineHeight: 1.6, color: '#374151' }}>{o}</div>
                          ))}
                        </div>
                      </div>
                    )}

                    {modal.audit.insights?.audience_alignment && (
                      <div style={{ padding: 20, background: '#f3f4f6', borderRadius: 12, border: '2px solid #9ca3af' }}>
                        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
                          <span style={{ fontSize: 24, marginRight: 10 }}>👥</span>
                          <h4 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#374151' }}>Audience Alignment</h4>
                        </div>
                        <p style={{ margin: 0, fontSize: 14, lineHeight: 1.7, color: '#4b5563' }}>{modal.audit.insights.audience_alignment}</p>
                      </div>
                    )}
                  </div>
                )}

                {modal.tab === 'suggestions' && (
                  <div>
                    <h4 style={{ marginTop: 0, marginBottom: 16 }}>Improvement Suggestions</h4>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                      {(modal.audit.suggestions || []).map((s, i) => {
                        const suggestion = typeof s === 'string' ? { text: s } : s;
                        const isExpanded = expandedSuggestions.has(i);
                        const hasSection = suggestion.section && suggestion.section.trim() !== '';
                        
                        return (
                          <div key={i} style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden', background: '#fff' }}>
                            <button
                              onClick={() => {
                                const newExpanded = new Set(expandedSuggestions);
                                if (isExpanded) {
                                  newExpanded.delete(i);
                                } else {
                                  newExpanded.add(i);
                                }
                                setExpandedSuggestions(newExpanded);
                              }}
                              style={{
                                width: '100%',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                padding: '14px 16px',
                                background: isExpanded ? '#f0f6ff' : '#fff',
                                border: 'none',
                                cursor: 'pointer',
                                textAlign: 'left',
                                transition: 'background 0.2s'
                              }}
                              onMouseEnter={(e) => !isExpanded && (e.currentTarget.style.background = '#f9fafb')}
                              onMouseLeave={(e) => !isExpanded && (e.currentTarget.style.background = '#fff')}
                            >
                              <div style={{ flex: 1 }}>
                                <div style={{ fontSize: 15, fontWeight: 600, color: '#111827', marginBottom: hasSection ? 4 : 0 }}>
                                  Suggestion #{i + 1}
                                </div>
                                {hasSection && (
                                  <div style={{ fontSize: 12, color: '#7c3aed', fontWeight: 500 }}>
                                    {suggestion.section}
                                  </div>
                                )}
                              </div>
                              <div style={{ fontSize: 18, color: '#9ca3af', transition: 'transform 0.2s', transform: isExpanded ? 'rotate(180deg)' : 'rotate(0deg)' }}>
                                ▼
                              </div>
                            </button>
                            {isExpanded && (
                              <div style={{ padding: '16px', background: '#f0f6ff', borderTop: '1px solid #e5e7eb' }}>
                                <p style={{ margin: 0, color: '#374151', lineHeight: 1.6, fontSize: 14, fontWeight: 600 }}>
                                  {suggestion.text}
                                </p>
                                {suggestion.why && (
                                  <div style={{ marginTop: 12, padding: 12, background: '#fff', borderRadius: 6, borderLeft: '3px solid #7c3aed' }}>
                                    <div style={{ fontSize: 12, fontWeight: 600, color: '#7c3aed', marginBottom: 4, textTransform: 'uppercase' }}>Why This Matters</div>
                                    <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>{suggestion.why}</p>
                                  </div>
                                )}
                                {suggestion.impact && (
                                  <div style={{ marginTop: 8, padding: 12, background: '#fff', borderRadius: 6, borderLeft: '3px solid #10b981' }}>
                                    <div style={{ fontSize: 12, fontWeight: 600, color: '#10b981', marginBottom: 4, textTransform: 'uppercase' }}>Expected Impact</div>
                                    <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>{suggestion.impact}</p>
                                  </div>
                                )}
                                {suggestion.implementation && (
                                  <div style={{ marginTop: 8, padding: 12, background: '#fff', borderRadius: 6, borderLeft: '3px solid #f59e0b' }}>
                                    <div style={{ fontSize: 12, fontWeight: 600, color: '#f59e0b', marginBottom: 4, textTransform: 'uppercase' }}>How To Implement</div>
                                    <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>{suggestion.implementation}</p>
                                  </div>
                                )}
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>

                    {modal.audit.recommendations?.quick_wins && modal.audit.recommendations.quick_wins.length > 0 && (
                      <div style={{ marginTop: 24 }}>
                        <h4 style={{ color: '#27ae60' }}>⚡ Quick Wins</h4>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                          {modal.audit.recommendations.quick_wins.map((q, i) => {
                            const quickWin = typeof q === 'string' ? { text: q } : q;
                            return (
                              <div key={i} style={{ background: '#f0fdf4', padding: 16, borderRadius: 8, borderLeft: '4px solid #27ae60' }}>
                                <div style={{ fontSize: 15, fontWeight: 600, color: '#27ae60', marginBottom: 8 }}>✓ {quickWin.text}</div>
                                {quickWin.why && (
                                  <div style={{ fontSize: 13, color: '#374151', marginBottom: 6, lineHeight: 1.5 }}>
                                    <strong style={{ color: '#059669' }}>Why:</strong> {quickWin.why}
                                  </div>
                                )}
                                {quickWin.impact && (
                                  <div style={{ fontSize: 13, color: '#374151', marginBottom: 6, lineHeight: 1.5 }}>
                                    <strong style={{ color: '#059669' }}>Impact:</strong> {quickWin.impact}
                                  </div>
                                )}
                                {quickWin.difficulty && (
                                  <div style={{ display: 'inline-block', marginTop: 4, padding: '4px 10px', background: '#d1fae5', borderRadius: 4, fontSize: 12, fontWeight: 600, color: '#065f46' }}>
                                    {quickWin.difficulty}
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}

                    {modal.audit.recommendations?.long_term && modal.audit.recommendations.long_term.length > 0 && (
                      <div style={{ marginTop: 24 }}>
                        <h4 style={{ color: '#9333ea' }}>🎯 Long-term Improvements</h4>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                          {modal.audit.recommendations.long_term.map((l, i) => {
                            const longTerm = typeof l === 'string' ? { text: l } : l;
                            return (
                              <div key={i} style={{ background: '#faf5ff', padding: 16, borderRadius: 8, borderLeft: '4px solid #9333ea' }}>
                                <div style={{ fontSize: 15, fontWeight: 600, color: '#9333ea', marginBottom: 8 }}>→ {longTerm.text}</div>
                                {longTerm.why && (
                                  <div style={{ fontSize: 13, color: '#374151', marginBottom: 6, lineHeight: 1.5 }}>
                                    <strong style={{ color: '#7e22ce' }}>Why:</strong> {longTerm.why}
                                  </div>
                                )}
                                {longTerm.impact && (
                                  <div style={{ fontSize: 13, color: '#374151', marginBottom: 6, lineHeight: 1.5 }}>
                                    <strong style={{ color: '#7e22ce' }}>Impact:</strong> {longTerm.impact}
                                  </div>
                                )}
                                <div style={{ display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
                                  {longTerm.difficulty && (
                                    <div style={{ padding: '4px 10px', background: '#e9d5ff', borderRadius: 4, fontSize: 12, fontWeight: 600, color: '#6b21a8' }}>
                                      {longTerm.difficulty}
                                    </div>
                                  )}
                                  {longTerm.timeframe && (
                                    <div style={{ padding: '4px 10px', background: '#e9d5ff', borderRadius: 4, fontSize: 12, fontWeight: 600, color: '#6b21a8' }}>
                                      ⏱️ {longTerm.timeframe}
                                    </div>
                                  )}
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}

                    {modal.audit.recommendations?.priority && (
                      <div style={{ marginTop: 24, padding: 20, background: '#fff7ed', borderRadius: 12, borderLeft: '4px solid #f39c12', boxShadow: '0 2px 8px rgba(243, 156, 18, 0.1)' }}>
                        <h4 style={{ marginTop: 0, marginBottom: 12, color: '#f39c12', fontSize: 18 }}>🔥 Priority Recommendation</h4>
                        {(() => {
                          const priority = typeof modal.audit.recommendations.priority === 'string' 
                            ? { text: modal.audit.recommendations.priority } 
                            : modal.audit.recommendations.priority;
                          return (
                            <>
                              <p style={{ margin: '0 0 12px 0', fontSize: 15, fontWeight: 600, color: '#374151', lineHeight: 1.6 }}>{priority.text}</p>
                              {priority.why && (
                                <div style={{ marginTop: 12, padding: 12, background: '#fff', borderRadius: 6 }}>
                                  <div style={{ fontSize: 12, fontWeight: 600, color: '#ea580c', marginBottom: 4, textTransform: 'uppercase' }}>Why This Is Priority</div>
                                  <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>{priority.why}</p>
                                </div>
                              )}
                              {priority.impact && (
                                <div style={{ marginTop: 8, padding: 12, background: '#fff', borderRadius: 6 }}>
                                  <div style={{ fontSize: 12, fontWeight: 600, color: '#ea580c', marginBottom: 4, textTransform: 'uppercase' }}>Expected Impact</div>
                                  <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>{priority.impact}</p>
                                </div>
                              )}
                              {priority.next_steps && (
                                <div style={{ marginTop: 8, padding: 12, background: '#fff', borderRadius: 6 }}>
                                  <div style={{ fontSize: 12, fontWeight: 600, color: '#ea580c', marginBottom: 4, textTransform: 'uppercase' }}>Next Steps</div>
                                  <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5, whiteSpace: 'pre-line' }}>{priority.next_steps}</p>
                                </div>
                              )}
                            </>
                          );
                        })()}
                      </div>
                    )}
                  </div>
                )}

                {modal.tab === 'functionality' && (
                  <div>
                    <div style={{ background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', color: '#fff', padding: 20, borderRadius: 12, marginBottom: 24 }}>
                      <h4 style={{ margin: '0 0 8px 0', fontSize: 18 }}>💡 Boost Your Conversions</h4>
                      <p style={{ margin: 0, fontSize: 14, opacity: 0.95 }}>
                        Based on your audit results and business goals, these features could significantly improve your conversion rates and user experience.
                      </p>
                    </div>

                    {modal.audit.functionality_suggestions && modal.audit.functionality_suggestions.length > 0 ? (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                        {modal.audit.functionality_suggestions.map((feature: any, i: number) => (
                          <div 
                            key={i} 
                            style={{ 
                              border: '1px solid #e5e7eb', 
                              borderRadius: 12, 
                              overflow: 'hidden',
                              transition: 'all 0.2s',
                              background: '#fff'
                            }}
                            onMouseEnter={(e) => {
                              e.currentTarget.style.borderColor = '#7c3aed';
                              e.currentTarget.style.boxShadow = '0 4px 12px rgba(124, 58, 237, 0.15)';
                            }}
                            onMouseLeave={(e) => {
                              e.currentTarget.style.borderColor = '#e5e7eb';
                              e.currentTarget.style.boxShadow = 'none';
                            }}
                          >
                            <div style={{ padding: 20 }}>
                              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, marginBottom: 12 }}>
                                <div style={{ fontSize: 32 }}>{feature.icon || '⚡'}</div>
                                <div style={{ flex: 1 }}>
                                  <h5 style={{ margin: '0 0 8px 0', fontSize: 18, fontWeight: 700, color: '#111827' }}>
                                    {feature.title}
                                  </h5>
                                  <p style={{ margin: '0 0 12px 0', color: '#6b7280', fontSize: 14, lineHeight: 1.6 }}>
                                    {feature.description}
                                  </p>
                                  <div style={{ padding: 12, background: '#f0f6ff', borderRadius: 8, borderLeft: '3px solid #7c3aed', marginBottom: 16 }}>
                                    <div style={{ fontSize: 12, fontWeight: 600, color: '#7c3aed', marginBottom: 4 }}>
                                      WHY YOU NEED THIS
                                    </div>
                                    <div style={{ fontSize: 13, color: '#374151', lineHeight: 1.5 }}>
                                      {feature.why}
                                    </div>
                                  </div>
                                  <a
                                    href={`mailto:support@trywebtec.com?subject=Interested in ${encodeURIComponent(feature.title)}&body=Hi! I'm interested in learning more about adding ${encodeURIComponent(feature.title)} to my website. Based on my Conversion IQ audit, this feature was recommended to improve my conversion rates.%0D%0A%0D%0ACould you provide more details about implementation and pricing?`}
                                    style={{
                                      display: 'inline-flex',
                                      alignItems: 'center',
                                      gap: 8,
                                      padding: '10px 20px',
                                      background: 'linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%)',
                                      color: '#fff',
                                      textDecoration: 'none',
                                      borderRadius: 8,
                                      fontSize: 14,
                                      fontWeight: 600,
                                      transition: 'all 0.2s',
                                      boxShadow: '0 2px 8px rgba(124, 58, 237, 0.25)'
                                    }}
                                    onMouseEnter={(e) => {
                                      e.currentTarget.style.transform = 'translateY(-2px)';
                                      e.currentTarget.style.boxShadow = '0 4px 12px rgba(124, 58, 237, 0.35)';
                                    }}
                                    onMouseLeave={(e) => {
                                      e.currentTarget.style.transform = 'translateY(0)';
                                      e.currentTarget.style.boxShadow = '0 2px 8px rgba(124, 58, 237, 0.25)';
                                    }}
                                  >
                                    <span>📧 Contact Webtec</span>
                                    <span>→</span>
                                  </a>
                                </div>
                              </div>
                            </div>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <div style={{ textAlign: 'center', padding: 40, background: '#f9fafb', borderRadius: 12, color: '#6b7280' }}>
                        <div style={{ fontSize: 48, marginBottom: 16 }}>🤖</div>
                        <p style={{ margin: 0, fontSize: 15 }}>No functionality suggestions available for this audit.</p>
                      </div>
                    )}

                    <div style={{ marginTop: 24, padding: 20, background: '#fef3c7', borderRadius: 12, borderLeft: '4px solid #f59e0b' }}>
                      <h5 style={{ margin: '0 0 8px 0', color: '#92400e', fontSize: 16 }}>
                        🎯 Custom Solutions Available
                      </h5>
                      <p style={{ margin: '0 0 12px 0', color: '#78350f', fontSize: 14, lineHeight: 1.6 }}>
                        Don't see what you need? Webtec specializes in custom WordPress solutions tailored to your specific business goals. 
                        From API integrations to complex workflows, we can build it.
                      </p>
                      <a
                        href="mailto:support@trywebtec.com?subject=Custom Development Inquiry&body=Hi! I have a custom development need that I'd like to discuss. Here's what I'm looking for:%0D%0A%0D%0A[Describe your needs here]"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: 8,
                          padding: '10px 20px',
                          background: '#f59e0b',
                          color: '#fff',
                          textDecoration: 'none',
                          borderRadius: 8,
                          fontSize: 14,
                          fontWeight: 600,
                          transition: 'all 0.2s'
                        }}
                        onMouseEnter={(e) => {
                          e.currentTarget.style.background = '#d97706';
                          e.currentTarget.style.transform = 'translateY(-2px)';
                        }}
                        onMouseLeave={(e) => {
                          e.currentTarget.style.background = '#f59e0b';
                          e.currentTarget.style.transform = 'translateY(0)';
                        }}
                      >
                        Discuss Custom Solutions →
                      </a>
                    </div>
                  </div>
                )}
              </div>

              {/* Footer */}
              <div style={{ padding: '16px 24px', borderTop: '1px solid #eee', display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                <button className="ciq-btn" onClick={() => handleExportReport(modal.audit?.insert_id)}>Export PDF</button>
                <button className="ciq-btn primary" onClick={() => setModal({ open: false })}>Close</button>
              </div>
            </div>
          </div>
        )}
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

    </div>
  );
}
