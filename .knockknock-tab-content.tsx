        {/* KnockKnock Tab */}
        {activeTab === 'knockknock' && (
          <section style={{ background: '#fff', borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.1)', padding: 32 }}>
            <div style={{ marginBottom: 32 }}>
              <h2 style={{ margin: '0 0 8px 0', fontSize: 28, fontWeight: 700, color: '#111827' }}>
                🔔 KnockKnock Integration
              </h2>
              <p style={{ color: '#6b7280', fontSize: 15, margin: 0 }}>
                Track real visitors and leads from KnockKnock with webhook integration
              </p>
            </div>

            {/* Statistics Cards */}
            {(knockKnockCompanyId || knockKnockWebhookSecret) && knockKnockLeads.length > 0 && (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 20, marginBottom: 32 }}>
                <div style={{ background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', borderRadius: 12, padding: 24, color: '#fff' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, opacity: 0.9, marginBottom: 8 }}>Total Interactions</div>
                  <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 4 }}>{knockKnockLeads.length}</div>
                  <div style={{ fontSize: 13, opacity: 0.8 }}>All time tracking</div>
                </div>
                <div style={{ background: 'linear-gradient(135deg, #10b981 0%, #059669 100%)', borderRadius: 12, padding: 24, color: '#fff' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, opacity: 0.9, marginBottom: 8 }}>Leads Captured</div>
                  <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 4 }}>
                    {knockKnockLeads.filter(l => l.type === 'lead').length}
                  </div>
                  <div style={{ fontSize: 13, opacity: 0.8 }}>Converted visitors</div>
                </div>
                <div style={{ background: 'linear-gradient(135deg, #3b82f6 0%, #1e40af 100%)', borderRadius: 12, padding: 24, color: '#fff' }}>
                  <div style={{ fontSize: 14, fontWeight: 600, opacity: 0.9, marginBottom: 8 }}>Identified Visitors</div>
                  <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 4 }}>
                    {knockKnockLeads.filter(l => l.type === 'visitor').length}
                  </div>
                  <div style={{ fontSize: 13, opacity: 0.8 }}>Tracked users</div>
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
            )}

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
                      <div style={{ fontSize: 32 marginBottom: 12 }}>⏳</div>
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
                                  <tr key={item.id || idx} style={{ borderBottom: '1px solid #e5e7eb' }}>
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
                                        {item.type === 'lead' ? '🎯 Lead' : '👤 Visitor'}
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
                                      {item.page_url ? (
                                        <a 
                                          href={item.page_url} 
                                          target="_blank" 
                                          rel="noopener noreferrer"
                                          style={{ color: '#7c3aed', textDecoration: 'none', fontWeight: 500 }}
                                        >
                                          {new URL(item.page_url).pathname}
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
                                
                                {item.page_url && (
                                  <div style={{ fontSize: 13, color: '#7c3aed', fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    🔗 {new URL(item.page_url).pathname}
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
          </section>
        )}
