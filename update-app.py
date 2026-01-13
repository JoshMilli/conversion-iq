#!/usr/bin/env python3
"""Update app.tsx with new business info auto-fill functionality"""

import re

file_path = r"admin\frontend\src\app.tsx"

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update handleSettingsChange to support textarea
content = content.replace(
    '  const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement>) => {',
    '  const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {'
)

# 2. Add handleGuessFields function after handleSettingsChange
insert_after = '  const handleSettingsChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {\n    setSettings({ ...settings, [e.target.name]: e.target.value });\n  };'

new_function = '''  
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
  };'''

content = content.replace(insert_after, insert_after + new_function)

# 3. Update Settings Tab section heading to include button
old_heading = '''          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <h2 style={{ margin: '0 0 8px 0', fontSize: 24, fontWeight: 700, color: '#111827' }}>Business Information</h2>'''

new_heading = '''          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
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
            </div>'''

content = content.replace(old_heading, new_heading)

# 4. Add gridTemplateColumns to be minmax(280px, 1fr) and marginBottom 16 to inputs grid
content = content.replace(
    '''            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>''',
    '''            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginBottom: 16 }}>'''
)

# 5. Add Additional Information textarea before Save Settings button
textarea_section = '''            <div style={{ marginBottom: 16 }}>
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
'''

# Find the Save Settings button and insert textarea before it
save_button_pattern = r"(\s+<button className=\"ciq-btn primary\" onClick={handleSaveSettings})"
content = re.sub(save_button_pattern, textarea_section + r"\1", content)

# Write the updated content
with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)

print("✅ Successfully updated app.tsx")
print("Changes made:")
print("1. Updated handleSettingsChange to support textarea")
print("2. Added handleGuessFields function")
print("3. Added 'Guess these fields for me' button to Business Information heading")
print("4. Added 'Additional Information' textarea field")
