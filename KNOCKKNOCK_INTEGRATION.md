# KnockKnock Webhook Integration

## Overview

The KnockKnock webhook integration enables Conversion IQ to receive real-time visitor and lead data from KnockKnock, enriching reports with actual user behavior and conversion metrics.

## Features

✅ **Real-time Lead Tracking** - Capture new leads as they convert  
✅ **Visitor Identification** - Track identified users across your site  
✅ **Page Analytics** - Automatic conversion rate calculation per page  
✅ **Secure Webhook Validation** - HMAC-SHA256 signature verification  
✅ **Settings UI** - Easy configuration in the Account tab  
✅ **Lead Dashboard** - View recent leads in the admin panel  

---

## Setup Instructions

### 1. Configure in WordPress

1. Navigate to **Conversion IQ → Account Tab**
2. Scroll to the **KnockKnock Webhook Integration** section
3. Configure authentication (at least one required):
   - **Webhook Secret Key** (recommended) - Enables HMAC signature verification
   - **Client Company ID** (optional) - For basic routing if secret not configured
4. Copy the **Webhook Endpoint URL**
5. Click **Save Settings**

**Authentication Methods:**
- **Secure** (recommended): Use Webhook Secret for HMAC-SHA256 signature verification
- **Basic**: Use Company ID for basic payload matching
- **Both**: For maximum security, configure both methods

### 2. Configure in KnockKnock

1. Log into your KnockKnock dashboard
2. Go to Webhook Settings
3. Add a new webhook with:
   - **URL**: `https://yoursite.com/wp-json/conversioniq/v1/webhook`
   - **Secret**: (the same secret you entered in WordPress)
   - **Events**: Enable `new_lead` and `new_user_identified`
4. Save and test the webhook

---

## Architecture

### Database Schema

#### `wp_conversioniq_webhook_logs`
Stores raw webhook events for auditing and debugging.

```sql
- id (primary key)
- event_type (new_lead, new_user_identified)
- webhook_id (from KnockKnock)
- company_id (to identify the WordPress account)
- raw_payload (full JSON data)
- verified (signature validation status)
- timestamp (when event occurred)
- created_at (when received)
```

#### `wp_conversioniq_leads`
Processed lead information.

```sql
- id (primary key)
- webhook_log_id (foreign key)
- first_name
- last_name
- email
- phone
- page_url (where conversion happened)
- user_session_id (from KnockKnock)
- converted_at
- created_at
```

#### `wp_conversioniq_visitor_sessions`
Identified visitor tracking.

```sql
- id (primary key)
- webhook_log_id (foreign key)
- user_session_id (unique, from KnockKnock)
- first_name
- last_name
- email
- page_url (where identified)
- identified_at
- created_at
```

#### `wp_conversioniq_page_analytics`
Aggregated conversion metrics per page.

```sql
- id (primary key)
- page_url
- total_visitors (count of unique sessions)
- identified_visitors (count with email)
- total_leads (conversion count)
- conversion_rate (calculated percentage)
- last_updated
```

---

## Webhook Events

### Event: `new_lead`

Triggered when a visitor converts (submits a form, makes a purchase, etc).

**Payload:**
```json
{
  "event": "new_lead",
  "data": {
    "user_session": {
      "_id": "64f1a2b3c4d5e6f7a8b9c0d1",
      "first_name": "John",
      "last_name": "Doe",
      "email": "john@example.com",
      "page_url": "https://yoursite.com/pricing"
    },
    "contact_information": {
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+1234567890"
    }
  },
  "webhook_id": "6712abc...",
  "company_id": "6641b37...",
  "timestamp": 1710000000
}
```

**Processing:**
1. Validates company_id matches configured value
2. Verifies HMAC signature (if secret is set)
3. Stores lead in `wp_conversioniq_leads`
4. Updates page analytics

### Event: `new_user_identified`

Triggered when a previously anonymous visitor is identified.

**Payload:**
```json
{
  "event": "new_user_identified",
  "data": {
    "user_session": {
      "_id": "64f1a2b3c4d5e6f7a8b9c0d2",
      "first_name": "Jane",
      "last_name": "Smith",
      "email": "jane@example.com",
      "page_url": "https://yoursite.com/about"
    }
  },
  "webhook_id": "6712def...",
  "company_id": "6641b37...",
  "timestamp": 1710000000
}
```

**Processing:**
1. Validates company_id and signature
2. Stores/updates session in `wp_conversioniq_visitor_sessions`
3. Updates page analytics

---

## Security

### Three-Tier Authentication Strategy

The webhook handler uses a flexible authentication approach:

**Tier 1: HMAC Signature (Secure - Recommended)**
- If Webhook Secret is configured, HMAC-SHA256 signature verification is used
- Signature algorithm: `HMAC-SHA256(secret, "{timestamp}.{json_body}")`
- Includes timestamp validation (5-minute window to prevent replay attacks)
- Uses timing-safe comparison to prevent timing attacks

**Tier 2: Company ID (Basic Fallback)**
- If no Webhook Secret is set, validates `company_id` from payload
- Matches against stored `knockknock_company_id` option
- Less secure than HMAC but useful for simple routing

**Tier 3: Rejection**
- If neither authentication method is configured, webhook is rejected
- Returns 401 Unauthorized error

### HMAC Verification Steps:
1. Extract `X-Webhook-Signature` and `X-Webhook-Timestamp` headers
2. Reject if timestamp is >5 minutes old (prevents replay attacks)
3. Compute expected signature
4. Compare using timing-safe equality (`hash_equals()`)

**Headers Required for HMAC:**
- `Content-Type: application/json`
- `X-Webhook-Signature` - HMAC signature
- `X-Webhook-Timestamp` - Unix timestamp
- `X-Webhook-Event` - Event name

---

## API Endpoints

### `POST /wp-json/conversioniq/v1/webhook`
**Public endpoint** - Receives webhook from KnockKnock  
**Authentication:** HMAC signature verification  
**Permission:** Public (security via signature)

### `GET /wp-json/conversioniq/v1/webhooks`
**Fetch recent leads** - Returns last 50 leads  
**Authentication:** WordPress nonce  
**Permission:** `manage_options`

### `GET/POST /wp-json/conversioniq/v1/settings`
**Save/retrieve settings** - Includes KnockKnock configuration  
**Authentication:** WordPress nonce  
**Permission:** `manage_options`

---

## Testing

### Using the Test Page

1. Access `https://yoursite.com/wp-content/plugins/conversion-iq/test-knockknock-webhook.php`
2. Configure your Company ID and Secret in the plugin settings first
3. Use the test buttons to simulate webhook events
4. View results in real-time

### Manual Testing with cURL

```bash
# Set variables
WEBHOOK_URL="https://yoursite.com/wp-json/conversioniq/v1/webhook"
COMPANY_ID="your_company_id"
SECRET="your_webhook_secret"
TIMESTAMP=$(date +%s)

# Create payload
PAYLOAD='{
  "event": "new_lead",
  "data": {
    "user_session": {
      "_id": "test123",
      "first_name": "Test",
      "last_name": "User",
      "email": "test@example.com",
      "page_url": "https://yoursite.com/test"
    },
    "contact_information": {
      "name": "Test User",
      "email": "test@example.com",
      "phone": "+1234567890"
    }
  },
  "webhook_id": "test_webhook_123",
  "company_id": "'$COMPANY_ID'",
  "timestamp": '$TIMESTAMP'
}'

# Calculate signature
SIGNATURE=$(echo -n "${TIMESTAMP}.${PAYLOAD}" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)

# Send webhook
curl -X POST "$WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: $SIGNATURE" \
  -H "X-Webhook-Timestamp: $TIMESTAMP" \
  -H "X-Webhook-Event: new_lead" \
  -d "$PAYLOAD"
```

---

## Integration with Reports

### Future Enhancements (Planned)

The KnockKnock data will be used to enhance audit reports:

1. **Real Conversion Rates** - Show actual conversion data vs benchmarks
2. **Data-Driven Insights** - Generate recommendations based on real user behavior
3. **Page Performance Metrics** - Display visitors, leads, and conversion rates
4. **Audience Insights** - Understand who is converting (demographics from lead data)
5. **Objective-Based Scoring** - Compare audit scores with actual performance

---

## Troubleshooting

### Webhook Not Receiving Data

1. **Check Authentication** - At least one method must be configured:
   - Either Webhook Secret (preferred) OR Company ID
2. **Verify Endpoint URL** - Should be publicly accessible
3. **Test with test-knockknock-webhook.php** - Simulates webhook locally
4. **Check Error Logs** - Look in WordPress debug.log for errors
5. **If using Webhook Secret** - Must match exactly (case-sensitive)
6. **If using Company ID** - Must match the ID in webhook payload

### Signature Verification Failing

1. **Clock Skew** - Ensure server time is accurate
2. **Secret Mismatch** - Verify secret is identical in both systems
3. **Timestamp Old** - Check if >5 minutes old
4. **Encoding Issues** - Ensure UTF-8 encoding

### Database Issues

If tables don't exist, the plugin will create them on activation. To manually recreate:

```php
require_once CONVERSION_IQ_DIR . 'includes/class-database.php';
ConversionIQ_DB::create_knockknock_tables();
```

---

## Files Added/Modified

### New Files:
- `/includes/class-knockknock-webhook.php` - Webhook handler class
- `/test-knockknock-webhook.php` - Testing interface
- `/KNOCKKNOCK_INTEGRATION.md` - This documentation

### Modified Files:
- `/conversion-iq.php` - Added webhook handler include
- `/includes/class-database.php` - Added table creation methods
- `/includes/rest-api.php` - Extended settings endpoints
- `/admin/frontend/src/app.tsx` - Added settings UI

---

## Support

For issues or questions:
- Check error logs: `wp-content/debug.log`
- Test webhook: `/test-knockknock-webhook.php`
- KnockKnock documentation: https://knockknock.com/docs

---

## Version History

### v1.8.4
- Improved authentication flexibility
- Webhook Secret now primary authentication method (HMAC-SHA256)
- Company ID optional, used as fallback if secret not configured
- Enhanced UI to reflect optional fields
- Added extensive debugging logs

### v1.8.3
- Initial KnockKnock webhook integration
- Added settings UI in Account tab
- Implemented lead tracking and analytics
- Created test interface
