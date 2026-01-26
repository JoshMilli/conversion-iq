import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './app'
import './styles.css'

console.log('=== Conversion IQ React App Initializing ===');
console.log('Timestamp:', new Date().toISOString());
console.log('React version:', React.version);

try {
  const el = document.getElementById('conversion-iq-app') ?? document.getElementById('root')
  if (el) {
    console.log('✓ Found app container:', el.id);
    console.log('Creating React root and rendering App component...');
    createRoot(el as HTMLElement).render(<App />)
    console.log('✓ React app mounted to:', el.id);
    
    // Signal to dashboard that React mounted successfully
    if (typeof window !== 'undefined' && (window as any).conversionIQReactMounted) {
      (window as any).conversionIQReactMounted();
    }
  } else {
    console.error('✗ ERROR: Could not find app container (conversion-iq-app or root)');
    console.error('Available elements:', {
      bodyChildren: document.body.children.length,
      elementsWithId: Array.from(document.querySelectorAll('[id]')).map(e => e.id)
    });
    throw new Error('App container not found');
  }
} catch (error) {
  console.error('✗ ERROR mounting React app:', error);
  const appDiv = document.getElementById('conversion-iq-app');
  if (appDiv) {
    appDiv.innerHTML = '<div style="color: red; padding: 20px;"><strong>ERROR:</strong> Failed to mount React app. Check browser console for details.</div>';
  }
}
