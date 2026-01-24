# Google Analytics Integration - Setup Guide

## Overview
The Conversion IQ plugin now integrates with Google Analytics 4 (GA4) to pull real conversion data and enhance audit insights with actual performance metrics.

## Features
- **Real Conversion Data**: Pull page views, conversions, conversion rates, bounce rates, and engagement metrics
- **Enhanced Audit Reports**: Display GA metrics alongside AI-powered audit scores
- **Top Pages Analysis**: View your best-performing pages based on conversion data
- **30-Day Historical Data**: Track performance trends over time

## Setup Instructions

### Step 1: Create Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the **Google Analytics Data API**:
   - Go to "APIs & Services" > "Library"
   - Search for "Google Analytics Data API"
   - Click "Enable"

### Step 2: Create OAuth 2.0 Credentials

1. Go to "APIs & Services" > "Credentials"
2. Click "Create Credentials" > "OAuth client ID"
3. If prompted, configure the OAuth consent screen:
   - Choose "External" user type
   - Fill in app name: "Conversion IQ"
   - Add your email as support email
   - Add authorized domains (your WordPress site domain)
   - Save and continue through the scopes and test users screens
4. Create OAuth client ID:
   - Application type: **Web application**
   - Name: "Conversion IQ WordPress Plugin"
   - Authorized redirect URIs: `https://yourdomain.com/wp-admin/admin.php?page=conversioniq&ga_callback=1`
     (Replace `yourdomain.com` with your actual domain)
5. Click "Create"
6. **Save your Client ID and Client Secret** - you'll need these!

### Step 3: Configure Plugin Settings

1. In WordPress admin, go to **Conversion IQ** > **Settings** tab
2. Scroll down to "Google Analytics Integration" section
3. Enter your **Client ID** and **Client Secret** from Step 2
4. Click "Save Credentials"
5. Click "Connect to Google Analytics" button
6. You'll be redirected to Google to authorize access
7. After authorization, select which GA4 property to use
8. Done! You're now connected

## Important Notes

### Redirect URI
The redirect URI **must exactly match** what you configured in Google Cloud Console. Common issues:
- Make sure to use `https://` if your site uses SSL
- Include `www.` if your site uses it
- The path must be `/wp-admin/admin.php?page=conversioniq&ga_callback=1`

### GA4 Property
- You must have a Google Analytics 4 property (not Universal Analytics)
- You must have at least "Viewer" access to the GA4 property
- The property must have data (at least a few page views)

### Scopes Required
The plugin requests the following OAuth scope:
- `https://www.googleapis.com/auth/analytics.readonly` (Read-only access to Analytics data)

## How It Works

### Data Flow
1. **OAuth Authentication**: Plugin uses OAuth 2.0 to securely authenticate with Google
2. **Token Storage**: Access and refresh tokens are stored in WordPress options (encrypted)
3. **API Requests**: When viewing an audit, the plugin makes API calls to GA4 to fetch page metrics
4. **Data Display**: Real metrics are displayed alongside AI audit scores

### API Endpoints

The plugin adds these REST API endpoints:

- `GET /wp-json/conversioniq/v1/ga/status` - Check connection status
- `POST /wp-json/conversioniq/v1/ga/save-credentials` - Save OAuth credentials
- `GET /wp-json/conversioniq/v1/ga/auth-url` - Get Google authorization URL
- `GET /wp-json/conversioniq/v1/ga/properties` - List available GA4 properties
- `POST /wp-json/conversioniq/v1/ga/save-property` - Select a property to use
- `POST /wp-json/conversioniq/v1/ga/disconnect` - Disconnect GA integration
- `POST /wp-json/conversioniq/v1/ga/page-data` - Get metrics for a specific page
- `GET /wp-json/conversioniq/v1/ga/top-pages` - Get top converting pages

### Available Metrics

For each page, the plugin can retrieve:
- **Page Views**: Total number of page views
- **Conversions**: Total conversion events
- **Conversion Rate**: Percentage of views that converted
- **Bounce Rate**: Percentage of single-page sessions
- **Engagement Rate**: Percentage of engaged sessions
- **Average Session Duration**: Average time spent on page (in seconds)

## Usage

### In Audit Reports
When viewing an audit report (click "View Report" on any audit), the Overview tab will show a "Google Analytics Data" section with real metrics for the last 30 days.

### Future Enhancements
Potential features for future versions:
- Custom date ranges for metrics
- Comparison with previous periods
- Goal-specific conversion tracking
- Multi-page funnel analysis
- Automated alerts for metric changes
- Historical trend charts

## Troubleshooting

### "Failed to get access token"
- Verify your Client ID and Client Secret are correct
- Check that the redirect URI in Google Cloud Console matches exactly
- Ensure the Google Analytics Data API is enabled

### "Token expired and refresh failed"
- The refresh token may have been revoked
- Disconnect and reconnect the integration
- Check that your OAuth consent screen is still active

### "No data available"
- Ensure the page has been visited in the last 30 days
- Check that the GA4 property is collecting data
- Verify you have access to the selected property

### API Quota Limits
Google Analytics Data API has daily quota limits:
- Free tier: 25,000 tokens per day
- Each request uses tokens based on complexity
- The plugin caches data to minimize API calls

## Security Considerations

- OAuth tokens are stored in WordPress options (consider using a secure options plugin)
- The plugin only requests read-only access to Analytics data
- Client Secret should be kept confidential
- Consider using environment variables for production deployments
- Tokens are automatically refreshed when they expire

## Database Storage

GA credentials are stored in:
```php
get_option('conversioniq_ga_credentials')
```

This includes:
- `client_id`
- `client_secret`
- `access_token`
- `refresh_token`
- `token_expires`
- `property_id`
- `property_name`

## Code Reference

### Main PHP Class
`includes/class-google-analytics.php` - Handles all GA API interactions

### REST API Callbacks
`includes/rest-api.php` - Contains endpoint callbacks (search for "GOOGLE ANALYTICS API ENDPOINTS")

### Frontend Integration
`admin/frontend/src/app.tsx` - React components for GA connection UI and metrics display

## Support

For issues or questions:
1. Check this documentation first
2. Review the browser console for error messages
3. Check WordPress debug.log for PHP errors
4. Contact support with error details

## Version History

- **v1.7.4** - Initial Google Analytics integration release
  - OAuth 2.0 authentication
  - Real-time metrics display
  - 30-day historical data
