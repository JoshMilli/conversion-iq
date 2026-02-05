# Basecamp Email Integration

## Overview
When audit reports are sent to email addresses containing the word "basecamp", the system automatically sends a **plain text version** instead of the HTML version. This ensures proper rendering in Basecamp, which doesn't support CSS styling.

## What Was Changed

### 1. Added Basecamp Detection Function
**File**: `includes/rest-api.php`

```php
function conversioniq_has_basecamp_email( $emails )
```

This function checks if any email address (in a string or array) contains the word "basecamp" (case-insensitive).

### 2. Modified Test Email Function
**File**: `includes/rest-api.php` → `conversioniq_test_email()`

- Detects if the recipient email contains "basecamp"
- Sends plain text version for Basecamp emails
- Sends HTML version for regular emails
- Logs which format was used

**Plain Text Format**:
```
CONVERSION IQ TEST EMAIL
=================================

Email System Working!

Your Conversion IQ email system is configured correctly...
```

### 3. Modified Automated Reports Function
**File**: `includes/class-automated-reports.php` → `send_email_report()`

- Detects if ANY recipient email contains "basecamp"
- Sends plain text version for Basecamp emails
- Still includes PDF attachments (both versions)
- Logs which format was used

**Plain Text Format**:
```
CONVERSION IQ AUDIT REPORT
=================================

Hello,

Your automated Conversion IQ audit has been completed.
We analyzed X pages on your website.

OVERALL PERFORMANCE: XX/100 - Status

PAGES ANALYZED:
---------------------------------
* Page Title
  Score: XX/100
  URL: https://...
  - Clarity: XX/100
  - Emotional: XX/100
  - CTA: XX/100
  - Readability: XX/100
  - Engagement: XX/100
  - Trust: XX/100

KEY INSIGHTS:
---------------------------------
Top Performers: Your strongest areas are...
Focus Areas: Prioritize improvements in...

NEXT STEPS:
---------------------------------
1. Review the attached PDF reports
2. Prioritize changes based on scores
3. Schedule implementation with Webtec

ATTACHED FILES:
- X detailed PDF reports with page-specific recommendations

---
Conversion IQ - Powered by Webtec
```

### 4. Manual Report Emails
**File**: `includes/rest-api.php` → `conversioniq_send_manual_report()`

This function calls the same `send_email_report()` method, so it automatically inherits the Basecamp detection logic.

## How It Works

1. **Email Input**: User provides email address(es) (can be comma-separated)
2. **Detection**: System checks if ANY email contains "basecamp"
3. **Format Selection**: 
   - If Basecamp detected → Send plain text version
   - If regular email → Send HTML version
4. **Attachments**: PDF reports are included in both versions
5. **Logging**: System logs which format was sent

## Testing

To test the Basecamp email format:

1. **Test Email**:
   ```
   POST /wp-json/conversioniq/v1/test-email
   {
     "email": "project+123@basecamp.com"
   }
   ```

2. **Manual Report**:
   ```
   POST /wp-json/conversioniq/v1/send-manual-report
   {
     "email": "team@basecamp.com, admin@example.com",
     "page_ids": [1, 2, 3]
   }
   ```

3. **Automated Reports**:
   - Set email to include "basecamp" in Settings
   - Enable automated reports
   - Wait for scheduled execution or trigger manually

## Benefits

✅ **Basecamp Compatibility**: Plain text renders perfectly in Basecamp
✅ **Automatic Detection**: No manual configuration needed
✅ **PDF Attachments Preserved**: Full detailed reports still attached
✅ **Maintains HTML for Others**: Regular emails still get styled version
✅ **Mixed Recipients**: If sending to both Basecamp and regular emails, all receive the plain text version (for consistency)

## Notes

- The detection is case-insensitive (`basecamp`, `Basecamp`, `BASECAMP` all work)
- Works with Basecamp's email-to-project format: `project+123@basecamp.com`
- If ANY recipient has "basecamp" in their email, plain text is sent to ALL recipients
- PDF attachments work the same in both formats
- Content is slightly condensed in plain text but includes all key information

## Log Messages

When emails are sent, you'll see logs like:

```
📧 Sending test email to: project@basecamp.com (Basecamp - Plain Text)
📧 Attempting to send email to: admin@example.com (HTML)
📧 Attempting to send email to: team@basecamp.com (Basecamp - Plain Text)
```
