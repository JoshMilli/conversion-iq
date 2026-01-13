# Update app.tsx with business info auto-fill functionality

$filePath = "admin\frontend\src\app.tsx"
$backupPath = "admin\frontend\src\app.tsx.backup"

# Make backup
Copy-Item $filePath $backupPath -Force

# Read the file
$content = Get-Content $filePath -Raw

# Edit 1: Update handleSettingsChange to support textarea
$content = $content -replace 'const handleSettingsChange = \(e: React\.ChangeEvent<HTMLInputElement>\) =>', 'const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>'

# Edit 2: Add handleGuessFields function after handleSettingsChange
$newFunction = @'
  
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
'@

$content = $content -replace '(const handleSettingsChange = \(e: React\.ChangeEvent<HTMLInputElement \| HTMLTextAreaElement>\) => \{\s+setSettings\(\{ \.\.\.settings, \[e\.target\.name\]: e\.target\.value \}\);\s+\};)', "`$1$newFunction"

# Edit 3: Update Business Information heading to include button
$oldHeading = '          <section style=\{\{ background: ''#fff'', borderRadius: 16, boxShadow: ''0 1px 3px rgba\(0,0,0,0\.1\)'', padding: 32 \}\}>\s+<h2 style=\{\{ margin: ''0 0 8px 0'', fontSize: 24, fontWeight: 700, color: ''#111827'' \}\}>Business Information</h2>'

$newHeading = @'
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
'@

$content = $content -replace $oldHeading, $newHeading

# Edit 4: Update inputs grid to add marginBottom
$content = $content -replace "(<div style=\{\{ display: 'grid', gridTemplateColumns: 'repeat\(auto-fit, minmax\(280px, 1fr\)\)', gap: 16) \}\}>", '$1, marginBottom: 16 }}>'

# Edit 5: Add Additional Information textarea before Save Settings button
$textarea = @'
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
'@

$content = $content -replace '(\s+<button className="ciq-btn primary" onClick=\{handleSaveSettings\})', "$textarea`$1"

# Write the updated content
$content | Set-Content $filePath -NoNewline

Write-Host "✅ Successfully updated app.tsx" -ForegroundColor Green
Write-Host "Changes made:" -ForegroundColor Cyan
Write-Host "1. Updated handleSettingsChange to support textarea" -ForegroundColor Yellow
Write-Host "2. Added handleGuessFields function" -ForegroundColor Yellow
Write-Host "3. Added 'Guess these fields for me' button to Business Information heading" -ForegroundColor Yellow
Write-Host "4. Updated inputs grid with marginBottom" -ForegroundColor Yellow
Write-Host "5. Added 'Additional Information' textarea field" -ForegroundColor Yellow
