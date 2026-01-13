# PDF Export Setup Instructions

## Current Status
The plugin generates **print-optimized HTML reports** that can be easily converted to PDF.

## How to Export as PDF

### Option 1: Browser Print (Easiest)
1. Click "Export Report" in the plugin
2. Open the downloaded HTML file in your browser
3. Press `Ctrl+P` (or `Cmd+P` on Mac)
4. Select "Save as PDF" as the printer
5. Click "Save"

**Result:** Professional PDF with perfect formatting

---

## For Automatic PDF Generation (Optional)

### Install DOMPDF Library

#### Method 1: Via Composer (Recommended)
1. Open terminal in plugin directory
2. Run: `composer require dompdf/dompdf`
3. The plugin will automatically detect and use DOMPDF

#### Method 2: Manual Installation
1. Download DOMPDF from: https://github.com/dompdf/dompdf/releases
2. Extract to `conversion-iq/vendor/dompdf/`
3. Create `conversion-iq/vendor/autoload.php` with:
```php
<?php
require_once __DIR__ . '/dompdf/dompdf/autoload.inc.php';
```

#### Method 3: Via WordPress Plugin
1. Install plugin "WP PDF Generator" or similar
2. DOMPDF classes will be available globally

---

## Current Functionality

✅ **What Works Now:**
- Generates beautiful HTML reports
- Base64-encoded logo (displays in all browsers)
- Full-width centered layout
- Print-optimized CSS
- All score descriptions included

✅ **Browser PDF Export:**
- Opens HTML report
- Print to PDF maintains all styling
- Perfect for client delivery

⚠️ **DOMPDF (Optional):**
- Requires Composer or manual installation
- Generates PDF directly without browser
- Recommended for high-volume automated reports

---

## Recommended Approach

**For Manual Reports:** Use browser "Print to PDF" - works perfectly!

**For Automated Reports:** Install DOMPDF via Composer for automatic PDF generation.

---

## Installation Commands

```bash
# Navigate to plugin directory
cd wp-content/plugins/conversion-iq

# Install DOMPDF
composer require dompdf/dompdf

# Done! Plugin will now generate PDFs automatically
```

---

## Troubleshooting

**Logo not showing?**
- Logo is now base64-encoded, should display in all browsers and PDFs
- Check that `assets/images/Webtec.png` exists

**Want automatic PDFs?**
- Install DOMPDF via composer (see above)
- Plugin will automatically detect and use it

**HTML reports working fine?**
- You're all set! Browser PDF export produces perfect results.
