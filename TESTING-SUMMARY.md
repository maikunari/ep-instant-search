# Production Fatal Error Testing - Quick Start

## What's Happening?

**v2.12.1** works perfectly on staging but causes a fatal error on production.

## What to Test Now

### Option 1: Minimal Debug Version (SAFEST)

**Branch:** `debug/minimal-version`  
**File:** Download just `ep-instant-search.php` from this branch

This version does almost nothing - just proves the plugin can load. If this crashes, we have an environment problem. If it works, we know the issue is in our ElasticPress code.

**Steps:**
1. Download `ep-instant-search.php` from `debug/minimal-version` branch
2. Upload to production
3. Activate plugin
4. Look for green admin notice: "Minimal version loaded successfully!"

**If it works:** Report back and we'll add features incrementally  
**If it crashes:** We have an environment issue to investigate

---

### Option 2: Get Error Details (MOST HELPFUL)

If you can enable WordPress debug mode temporarily:

**Add to `wp-config.php`:**
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Then:**
1. Upload v2.12.1 
2. Activate plugin
3. Check `/wp-content/debug.log` for the exact error
4. Send me the error message

This will tell us exactly what line is failing and why.

---

## Files Available

- **main branch:** Current working v2.12.1 (crashes on production)
- **debug/minimal-version branch:** Minimal test version (v2.12.2-debug)
- **DEBUG-INSTRUCTIONS.md:** Full step-by-step testing guide

---

## Next Steps

Once we know if the minimal version works or crashes, we'll know:

- ✅ **Works:** Add back ElasticPress integration piece by piece
- ❌ **Crashes:** Investigate environment (PHP version, WordPress version, server config)

Either way, we'll solve this!
