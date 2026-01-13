# Security Setup Guide

## API Key Protection

For enhanced security, you should move the Abacus.ai API key from the code to your `wp-config.php` file.

### Recommended Setup

Add this line to your `wp-config.php` file (before the "That's all, stop editing!" comment):

```php
define('CONVERSIONIQ_ABACUS_KEY', 's2_7b1143d048014d04b7d489a17671b1a7');
```

### Benefits

1. **Key not in version control** - wp-config.php is typically excluded from Git
2. **Environment-specific keys** - Different keys for dev/staging/production
3. **Easier key rotation** - Update in one place without touching code

### Fallback

If the constant is not defined in wp-config.php, the plugin will use the hardcoded fallback key. However, this is less secure and should be used for development only.

### Security Checklist

✅ API key removed from frontend code (abacus-ai.ts deleted)
✅ All API calls run through backend PHP only
✅ REST API endpoints protected with `manage_options` capability
✅ API key can be externalized to wp-config.php
⚠️ Recommended: Move key to wp-config.php in production
