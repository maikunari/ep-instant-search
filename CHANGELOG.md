# Changelog

All notable changes to ElasticPress Instant Search will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.9.2] - 2025-11-05

### Changed
- **TEMPORARY**: Disabled archive protection feature due to site conflicts
- Archive protection filter causing Service Unavailable errors on some configurations
- Plugin now works without interfering with ElasticPress default behavior
- Instant search functionality fully operational

### Note
Archive protection will be re-implemented in future version with better compatibility approach.

## [2.9.1] - 2025-11-05

### Fixed
- **CRITICAL**: Fixed 503 error on plugin activation caused by `skip_elasticpress_for_archives()` filter
- Added safety checks to ensure query object is valid before calling methods
- Filter now only applies to main query on frontend (excludes admin and AJAX queries)
- Added `is_main_query()` check to prevent interfering with custom queries

### Changed
- Archive protection filter now returns original `$skip` value when conditions don't match
- Improved error handling to prevent fatal errors during WordPress initialization

## [2.9.0] - 2025-11-05

### Added
- Conditional debug logging - logs only when `WP_DEBUG` is enabled
- Automatic log rotation when file exceeds 1MB
- File locking (`LOCK_EX`) for safe concurrent log writes
- Comprehensive security audit and improvements

### Changed
- Replaced all `file_put_contents()` calls with centralized `debug_log()` method
- Custom CSS now properly sanitized with `wp_strip_all_tags()`
- Debug logging disabled by default in production (only active when `WP_DEBUG = true`)

### Security
- **FIXED**: Custom CSS XSS vulnerability - now properly sanitized before output
- **FIXED**: Debug log file size limits prevent disk space exhaustion
- **IMPROVED**: File write permissions checked before logging
- All security best practices verified and implemented

### Performance
- Reduced disk I/O - debug logging only in development mode
- Log file rotation prevents performance degradation from large files

## [2.8.0] - 2025-11-05

### Added
- Conditional ElasticPress query integration via `ep_skip_query_integration` filter
- Prevents ElasticPress from hijacking product archives, categories, and shop pages
- ElasticPress now only used for actual search queries (search box)
- MySQL used for all other queries (product archives, categories, etc.)

### Changed
- Plugin URI updated to GitHub repository
- Author updated to Mike Sewell
- Author URI updated to https://sonicpixel.jp

### Fixed
- **CRITICAL**: Product archives showing incomplete results when ElasticPress sync is incomplete
- Products now reliably display on category/archive pages using MySQL
- Search functionality continues to use fast Elasticsearch

## [2.7.0] - 2025-11-05

### Changed
- Updated plugin metadata with new author information
- Improved documentation

## [2.6.0] - Previous Release

### Features
- Instant search with loading spinner
- Multi-content type support (products, posts, pages)
- WooCommerce product search with variation SKU support
- Mobile optimized responsive design
- Smart caching system (5 min server-side, 1 min client-side)
- Bulk data loading for optimal performance
- Request cancellation for new searches
- Admin settings panel
- Debug logging system
- XSS prevention in JavaScript
- Proper input sanitization
- AJAX-based search without page reload

### Architecture
- Uses free ElasticPress plugin (not ElasticPress.io subscription)
- Supports self-hosted Elasticsearch instances
- Multi-layer caching strategy
- Optimized queries with full post objects
- SKU search with fuzzy matching
- Weighted search results
- Synonym support through ElasticPress

## [2.0.0] - Initial Architecture

### Core Features
- ElasticPress integration
- WooCommerce product search
- Instant results dropdown
- Keyboard navigation (arrow keys, ESC, Enter)
- Accessible ARIA attributes
- Admin configuration panel

---

## Upgrade Notes

### 2.9.0
- **Breaking**: Debug logs will no longer be created in production unless `WP_DEBUG` is enabled
- **Action Required**: If you rely on debug logs in production, define `WP_DEBUG` as `true` in `wp-config.php`
- **Security**: Custom CSS is now sanitized - malicious code will be stripped

### 2.8.0
- **Important**: After upgrading, product archives will use MySQL instead of Elasticsearch
- **Benefit**: Products will always display correctly even if Elasticsearch sync is incomplete
- **No Action Required**: Search functionality automatically continues using Elasticsearch

### 2.7.0
- No breaking changes
- Safe to upgrade

---

## Security

We take security seriously. If you discover a security vulnerability, please email security@sonicpixel.jp.

## Support

- **Documentation**: [README.md](README.md)
- **Issues**: [GitHub Issues](https://github.com/maikunari/ep-instant-search/issues)
- **Author**: Mike Sewell (https://sonicpixel.jp)
