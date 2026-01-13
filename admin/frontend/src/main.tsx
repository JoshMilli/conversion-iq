import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './app'
import './styles.css'

const el = document.getElementById('conversion-iq-app') ?? document.getElementById('root')
if (el) {
  createRoot(el as HTMLElement).render(<App />)
}
