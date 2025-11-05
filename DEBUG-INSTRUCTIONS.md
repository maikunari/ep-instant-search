# Debugging Production Fatal Error - Step-by-Step Guide

## Current Status
- ✅ Version 2.12.1 works on **STAGING**
- ❌ Version 2.12.1 causes **FATAL ERROR on PRODUCTION**
- ❓ Root cause unknown - even basic search doesn't work on production without our plugin

## Test Plan: Incremental Feature Addition

We're using a **minimal version approach** to identify exactly what causes the fatal error.

### Step 1: Test Minimal Version (v2.12.2-debug)

**Branch:** `debug/minimal-version`

**What it does:**
- Loads basic plugin class
- Shows admin notice "Minimal version loaded successfully!"
- **NO** ElasticPress integration
- **NO** search functionality
- **NO** filters or hooks

**Expected Results:**
- ✅ **If it loads without error:** The problem is in our ElasticPress integration code
- ❌ **If it still crashes:** The problem is environmental (PHP version, WordPress core, etc.)

**How to test:**
1. Upload `ep-instant-search.php` from `debug/minimal-version` branch
2. Activate plugin
3. Check if you see green success notice in admin
4. Report back: Did it work or crash?

---

### Step 2: Add ElasticPress Check (if Step 1 succeeds)

If minimal version works, we'll add back the ElasticPress dependency check:

```php
public function __construct() {
    add_action('init', array($this, 'init'));
}

public function init() {
    if (!defined('EP_VERSION')) {
        add_action('admin_notices', array($this, 'elasticpress_missing_notice'));
        return;
    }

    // Success message
    add_action('admin_notices', array($this, 'debug_notice'));
}
```

This tests if checking for ElasticPress causes issues.

---

### Step 3: Add Filter Registration (if Step 2 succeeds)

Next, we'll add the actual filter registration:

```php
public function init() {
    if (!defined('EP_VERSION')) {
        add_action('admin_notices', array($this, 'elasticpress_missing_notice'));
        return;
    }

    // Test filter registration
    if (has_filter('ep_elasticpress_enabled')) {
        add_filter('ep_elasticpress_enabled', array($this, 'force_ep_for_search'), 10, 2);
    }
}

public function force_ep_for_search($enabled, $query) {
    // Do nothing, just test if we can register the filter
    return $enabled;
}
```

---

### Step 4: Add Full Search Logic (if Step 3 succeeds)

Finally, we'll add the actual search forcing logic:

```php
public function force_ep_for_search($enabled, $query) {
    if (is_search() && $query->is_main_query() && !is_admin()) {
        return true;
    }
    return $enabled;
}
```

---

## Diagnostic Questions

While testing, please check:

1. **PHP Version:**
   ```bash
   php -v
   ```
   - Staging: ?
   - Production: ?

2. **WordPress Version:**
   - Staging: ?
   - Production: ?

3. **ElasticPress Version:**
   - Staging: ?
   - Production: ?

4. **Active Plugins on Production:**
   - List all active plugins (especially search/query-related ones)
   - Are there any caching plugins?
   - Are there any security plugins?

5. **Error Logs:**
   If you can access PHP error logs:
   ```
   /path/to/error.log
   ```
   Look for the actual fatal error message - it will tell us exactly what line is failing.

6. **WordPress Debug:**
   Can you enable debug mode on production temporarily?

   In `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

   Then check `/wp-content/debug.log` for the error.

---

## What We're Looking For

The incremental approach will tell us:

- **Crashes at Step 1:** Environment issue (PHP, WordPress, server config)
- **Crashes at Step 2:** ElasticPress detection issue
- **Crashes at Step 3:** Filter registration issue (filter doesn't exist)
- **Crashes at Step 4:** Search query logic issue (is_search, is_main_query, etc.)

---

## Additional Investigation: Theme/Plugin Conflict

You mentioned search doesn't work on production even without ElasticPress. Let's investigate:

1. **Test with default theme:**
   - Switch to Twenty Twenty-Four or another default theme
   - Does search work now?
   - If yes → theme is hijacking search

2. **Disable all plugins except WooCommerce:**
   - Does search work now?
   - If yes → another plugin is hijacking search
   - Enable plugins one by one to find the culprit

3. **Check theme's functions.php:**
   Look for:
   - `pre_get_posts` hooks
   - `posts_search` filters
   - `posts_where` filters
   - Custom WP_Query modifications

---

## Contact for Results

After testing Step 1, let me know:
1. Did the minimal version load without error?
2. Did you see the green admin notice?
3. Any error messages in browser or logs?

Then we'll proceed to the next step!
