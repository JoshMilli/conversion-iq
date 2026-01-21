# Conversion IQ - Build Information

## Build Date
January 21, 2026

## Version
1.6.7

## Build Contents

### WordPress Plugin
- **Package**: `conversion-iq-v1.6.7.zip`
- **Size**: 0.29 MB (296 KB)
- **Type**: Production-ready WordPress plugin

### Included Components

#### Core Plugin Files
✅ Main plugin file (conversion-iq.php)
✅ Uninstall script
✅ Composer configuration
✅ README

#### Admin Interface
✅ React-based admin dashboard (built with Vite)
✅ Production-optimized JavaScript bundle (247 KB)
✅ Minified CSS (383 bytes)
✅ WordPress admin integration (dashboard.php)

#### Plugin Features
✅ AI Engine (Abacus.ai integration with chunking support)
✅ Database management
✅ REST API endpoints
✅ Automated reports
✅ **NEW: Supabase cloud sync** (class-supabase-sync.php)
✅ Auto-updates from GitHub

#### Assets
✅ Admin CSS styles
✅ Images and branding

#### Third-Party Libraries
✅ Plugin Update Checker 5.6 (with 30+ language translations)
✅ Parsedown (Markdown parser)

## What's New in This Build

### Supabase Integration
- ✅ Automatic cloud sync for all audits
- ✅ Multi-tenant SaaS architecture
- ✅ Organization registration (auto-registers WordPress sites)
- ✅ User account tracking (email, company, username)
- ✅ Built-in Supabase credentials (no wp-config edits needed)
- ✅ Real-time data sync to centralized database

### Setup Files Included
- ✅ `SUPABASE_COMPLETE_SCHEMA.sql` - Database schema
- ✅ `SUPABASE_SETUP_INSTRUCTIONS.md` - Setup guide

## Installation

### For WordPress Site Owners
1. Upload `conversion-iq-v1.6.7.zip` to WordPress
2. Go to Plugins → Add New → Upload Plugin
3. Choose the zip file
4. Click "Install Now"
5. Activate the plugin
6. Plugin automatically registers with Supabase cloud

### For Developers
1. Clone from GitHub: https://github.com/JoshMilli/conversion-iq
2. Latest commit includes all Supabase integration

## Build Process

### Admin Frontend
```bash
cd admin/frontend
npm install
npm run build
```

Output: `admin/build/vite-dist/`
- Optimized React bundle
- Minified CSS
- Production HTML

### Plugin Package
```bash
# Automatically includes:
# - Core PHP files
# - Built admin assets
# - Library dependencies
# - Supabase setup files
# 
# Excludes:
# - Development files (node_modules, src files)
# - Test files
# - Documentation (except setup guides)
```

## Technical Specs

### Requirements
- WordPress 6.0+
- PHP 7.4+
- Modern browser (for admin interface)

### Dependencies
- Abacus.ai API (for AI analysis)
- Supabase (for cloud database)
- React 18.2
- Vite 5.2 (build tool)

### Browser Compatibility
- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)

## Files NOT Included in Package
- ❌ node_modules (too large, not needed in production)
- ❌ Frontend source files (.tsx, .ts)
- ❌ Development configs (tsconfig, vite configs)
- ❌ Test files (test-*.php, validate-*.php)
- ❌ Build scripts (update-*.py, *.ps1)
- ❌ .git directory
- ❌ Most documentation files (except Supabase setup)

## Security Features
- ✅ Row Level Security (RLS) in Supabase
- ✅ API key authentication
- ✅ Secure password hashing (bcrypt)
- ✅ WordPress nonce verification
- ✅ Input sanitization

## Performance
- ✅ Intelligent content chunking (handles 8000+ char pages)
- ✅ Optimized React bundle (gzipped: 73.44 KB)
- ✅ Database indexes for fast queries
- ✅ Non-blocking Supabase sync

## Support & Updates
- **GitHub**: https://github.com/JoshMilli/conversion-iq
- **Auto-updates**: Enabled (checks GitHub for new releases)
- **Branch**: main
- **Update frequency**: Checks every update cycle

## Next Steps After Installation
1. ✅ Plugin activates and registers with Supabase
2. ✅ Run first audit to test functionality
3. ✅ Check Supabase dashboard to verify data sync
4. ✅ Log in to admin portal at localhost:3000 to view audits

## Deployment Options

### Option 1: Manual Upload
Use the zip file for manual WordPress installations

### Option 2: GitHub Integration
WordPress sites can auto-update from GitHub repository

### Option 3: Distribution
Share zip file with clients for easy installation

## Build Verification

✅ PHP syntax validated (no errors)
✅ Frontend built successfully
✅ All required files included
✅ Package size optimized
✅ Supabase credentials embedded
✅ Update checker configured

---

**Build Status**: ✅ Complete and ready for deployment
**Last Updated**: January 21, 2026
**Build Tool**: PowerShell + npm/Vite
