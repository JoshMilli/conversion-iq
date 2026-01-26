import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './app'
import './styles.css'

console.log('=== Conversion IQ React App Initializing ===');
console.log('Timestamp:', new Date().toISOString());

const el = document.getElementById('conversion-iq-app') ?? document.getElementById('root')
if (el) {
  console.log('✓ Found app container:', el.id);
  console.log('Creating React root and rendering App component...');
  createRoot(el as HTMLElement).render(<App />)
  console.log('✓ React app mounted to:', el.id);
} else {
  console.error('✗ ERROR: Could not find app container (conversion-iq-app or root)');
  console.error('Available elements:', {
    bodyChildren: document.body.children.length,
    elementsWithId: Array.from(document.querySelectorAll('[id]')).map(e => e.id)
  });
}
