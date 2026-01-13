// Simplified TypeScript source for the admin app.
// NOTE: This project ships a compiled bundle at admin/build/app.bundle.js which is used by the plugin.
// This source file is a lightweight placeholder so developers can see intended UI behavior.

// We avoid direct React imports here to keep this file self-contained and prevent local TS compile
// errors in environments without dev dependencies.

const mount = document.getElementById('conversion-iq-app');
if (mount) {
  const el = document.createElement('div');
  el.textContent = 'Conversion IQ (TypeScript placeholder) — the compiled bundle is used in production.';
  mount.innerHTML = '';
  mount.appendChild(el);
}

export {};
