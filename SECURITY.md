# Security Implementation Documentation

## Overview
This document outlines the security measures implemented in the ElasticPress Instant Search plugin to prevent common vulnerabilities and attacks.

## Security Features Implemented

### 1. XSS (Cross-Site Scripting) Prevention

#### JavaScript (Client-Side)
- **HTML Escaping**: Custom `escapeHtml()` function escapes all dangerous characters including `<>'"=/\``
- **DOM Manipulation**: Uses `textContent` instead of `innerHTML` for user-supplied data
- **Safe Element Creation**: `createElement()` helper ensures text content is never interpreted as HTML
- **Input Sanitization**: Search terms are sanitized before display and API requests

```javascript
// Example of safe text insertion
element.textContent = item.title; // Safe
// element.innerHTML = item.title; // Unsafe - would allow XSS
```

#### PHP (Server-Side)
- **Output Escaping**: All dynamic content is escaped using WordPress functions:
  - `sanitize_text_field()` for text inputs
  - `wp_strip_all_tags()` to remove HTML
  - `esc_url()` for URLs
  - `esc_html()` for HTML attributes
  - `wp_kses_post()` for content that may contain limited HTML

### 2. CSRF Protection
- **Nonce Verification**: REST API requests include WordPress nonce
- **Same-Origin Policy**: Credentials set to 'same-origin' for fetch requests
- **Optional Nonce Validation**: AJAX endpoint validates nonce when provided

### 3. Rate Limiting

#### Client-Side
- **Request Throttling**: Maximum 5 requests per second
- **Debouncing**: Configurable delay (100-2000ms) between keystrokes
- **Request Cancellation**: Previous requests aborted when new search initiated

#### Server-Side
- **IP-Based Rate Limiting**: Maximum 10 requests per 60 seconds per IP
- **Transient Storage**: Uses WordPress transients for rate limit tracking
- **429 Response**: Returns proper HTTP status code when rate limited

### 4. Input Validation

#### Search Term Validation
- **Minimum Length**: 2 characters required
- **Maximum Length**: 100 characters maximum
- **Character Filtering**: Dangerous characters removed
- **HTML Stripping**: All HTML tags removed
- **Script Tag Removal**: Explicit removal of script tags

### 5. Response Validation

#### Data Type Checking
- **JSON Validation**: Verifies response is valid JSON
- **Content-Type Verification**: Checks response has correct content-type header
- **Structure Validation**: Validates response has expected structure
- **Property Validation**: Safe property access with defaults

```javascript
// Safe property access
const title = safeGet(item, 'title.rendered', 'Untitled');
```

### 6. Memory Management

#### Cache Management
- **LRU Cache**: Limited to 50 entries with least-recently-used eviction
- **Size Limits**: Maximum results per query capped at 50
- **Cleanup**: Cache cleared on page unload
- **WeakMap Usage**: Prevents memory leaks for request tracking

### 7. Request Security

#### Timeouts
- **Request Timeout**: 5-second timeout for all API requests
- **AbortController**: Proper cleanup of cancelled requests
- **Error Handling**: Graceful fallback without exposing errors

#### Headers
- **Security Headers**: Added for all AJAX responses:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `X-XSS-Protection: 1; mode=block`
  - `Referrer-Policy: strict-origin-when-cross-origin`

### 8. Error Handling

#### Information Disclosure Prevention
- **Generic Error Messages**: Users see generic messages, not technical details
- **Logging**: Detailed errors logged server-side only
- **Fallback Mechanism**: Automatic fallback to AJAX if REST fails
- **No Stack Traces**: Never expose stack traces or file paths

### 9. Configuration Validation

#### Settings Sanitization
- **Integer Validation**: All numeric settings validated and bounded
- **Boolean Conversion**: String settings properly converted to booleans
- **Selector Validation**: CSS selectors tested before use
- **URL Validation**: All URLs properly escaped and validated

## Security Best Practices

### For Developers

1. **Never trust user input**: Always sanitize and validate
2. **Use safe functions**: Prefer `textContent` over `innerHTML`
3. **Validate responses**: Check data types and structure
4. **Limit scope**: Use minimum necessary privileges
5. **Log security events**: Track potential attacks for analysis

### For Site Administrators

1. **Keep WordPress updated**: Security patches are important
2. **Monitor rate limits**: Adjust if legitimate users are affected
3. **Review logs**: Check for suspicious activity patterns
4. **Use HTTPS**: Always use SSL/TLS for production sites
5. **Configure CSP**: Add Content Security Policy headers if needed

## Testing Security

### Manual Testing
```bash
# Test XSS attempts
curl "site.com/wp-admin/admin-ajax.php?action=ep_instant_search&q=<script>alert(1)</script>"

# Test rate limiting
for i in {1..15}; do curl "site.com/wp-admin/admin-ajax.php?action=ep_instant_search&q=test"; done

# Test long input
curl "site.com/wp-admin/admin-ajax.php?action=ep_instant_search&q=$(python -c 'print("a"*200)')"
```

### Automated Testing
- Use OWASP ZAP for vulnerability scanning
- Implement unit tests for sanitization functions
- Use browser security extensions for XSS detection

## Compliance

This implementation follows:
- **OWASP Top 10** security guidelines
- **WordPress Coding Standards** for security
- **PHP Security Best Practices**
- **JavaScript Security Guidelines**

## Regular Security Review

Schedule quarterly reviews to:
1. Update dependencies
2. Review new attack vectors
3. Test security measures
4. Update rate limits based on usage
5. Review error logs for patterns

## Incident Response

If a security issue is discovered:
1. **Isolate**: Disable affected functionality if critical
2. **Patch**: Develop and test fix immediately
3. **Deploy**: Update all installations
4. **Document**: Record incident and prevention measures
5. **Notify**: Inform affected users if data was compromised

## Contact

For security concerns, contact: [security@example.com]
Do not disclose vulnerabilities publicly until patched.