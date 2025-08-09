# ElasticPress Instant Search

A lightweight WordPress plugin that adds instant search functionality to your WooCommerce store using ElasticPress. This plugin provides a fast, responsive search experience similar to ElasticPress.io's premium Instant Results feature, but without the subscription.

## Description

ElasticPress Instant Search is a simple wrapper around the free ElasticPress plugin that provides instant search results as users type. It leverages all of ElasticPress's powerful search features including:

- **Weighted search** - Configured field weights for products, posts, and pages
- **Synonyms** - ElasticPress synonym support
- **Fuzzy matching** - Typo-tolerant search
- **Multi-content search** - Products, posts, pages, and custom post types
- **SKU search** - Special handling for product SKUs including partial matches

## Features

- ⚡ **Instant results** - Search results appear as you type with minimal delay
- 🔄 **Smart caching** - Multi-layer caching for optimal performance
- 📱 **Mobile optimized** - Responsive design that works perfectly on all devices
- 🎯 **Accurate results** - Uses ElasticPress's configured weights and relevance
- 🛒 **WooCommerce ready** - Shows product prices, images, SKUs, and stock status
- 📝 **Multi-content** - Searches all content types configured in ElasticPress
- ⚙️ **Customizable** - Admin settings for appearance and behavior

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher (for product search)
- **[ElasticPress plugin](https://wordpress.org/plugins/elasticpress/)** (free version) - Must be installed, activated, and properly configured
- **Self-hosted Elasticsearch instance** - You need your own Elasticsearch server (not included)

### About ElasticPress

[ElasticPress](https://wordpress.org/plugins/elasticpress/) is a free WordPress plugin by 10up that powers fast and flexible search by connecting your site to an Elasticsearch server. It replaces the default WordPress search with Elasticsearch, providing:

- Lightning-fast search results
- Relevant results with weighted scoring
- Fuzzy matching for typos
- Search across multiple content types
- WooCommerce product search
- Custom field and meta search
- Synonym support
- And much more

**Note:** ElasticPress is different from ElasticPress.io (the paid hosted service). The free plugin requires your own Elasticsearch server, which is why we recommend using DigitalOcean's one-click setup below.

## Elasticsearch Setup

This plugin requires a self-hosted Elasticsearch instance. Unlike ElasticPress.io (the paid service), you need to host your own Elasticsearch server.

### Recommended: DigitalOcean Elasticsearch

The easiest way to get started is with DigitalOcean's pre-configured Elasticsearch droplet:

**[Deploy Elasticsearch on DigitalOcean](https://marketplace.digitalocean.com/apps/elasticsearch)**

- One-click deployment
- Starting at $4/month for small sites
- Scales with your needs
- Includes monitoring and backups
- Pre-configured and optimized

### Alternative Options

- AWS Elasticsearch Service
- Elastic Cloud (official hosted service)
- Self-managed VPS with Elasticsearch
- Local development with Docker

## Installation

1. **Set up Elasticsearch**:
   - Deploy Elasticsearch using [DigitalOcean's 1-Click App](https://marketplace.digitalocean.com/apps/elasticsearch)
   - Note your Elasticsearch endpoint URL
   - Configure security settings if needed

2. **Install and configure ElasticPress**:
   - Install the [free ElasticPress plugin from WordPress.org](https://wordpress.org/plugins/elasticpress/)
   - Go to ElasticPress → Settings
   - Enter your Elasticsearch endpoint URL
   - Enable desired features (Search, WooCommerce, etc.)
   - Run initial sync to index your content

3. **Install ElasticPress Instant Search**:
   - Upload the `ep-instant-search` folder to `/wp-content/plugins/`
   - Activate the plugin through the 'Plugins' menu in WordPress
   - Configure settings under ElasticPress → Instant Search

## Configuration

### Admin Settings

Navigate to **ElasticPress → Instant Search** in your WordPress admin:

- **Minimum Characters**: Number of characters before search starts (default: 2)
- **Maximum Results**: Number of results to display (default: 8)
- **Search Delay**: Milliseconds to wait after typing stops (default: 100ms)
- **Display Options**:
  - Show product prices
  - Show product images
  - Show product SKUs
- **Search Field Selectors**: CSS selectors for search inputs to enhance
- **Custom CSS**: Add your own styles

### Default Search Selectors

The plugin automatically enhances search fields matching these selectors:
- `.search-field`
- `input[name="s"]`
- `.dgwt-wcas-search-input`

You can add custom selectors in the admin settings.

## How It Works

1. **User types** in a search field
2. **Instant feedback** - Loading spinner appears immediately
3. **Smart debouncing** - Waits 100ms after typing stops
4. **ElasticPress search** - Query sent through WordPress with `ep_integrate => true`
5. **Results display** - Formatted results replace the spinner
6. **Caching** - Results cached for 5 minutes server-side, 1 minute client-side

## Performance Features

- **Request cancellation** - Previous searches abort when new character typed
- **Bulk data loading** - Products loaded in single query
- **Smart caching** - Multi-layer caching strategy
- **Optimized queries** - Full post objects to minimize database calls
- **Instant visual feedback** - Spinner shows immediately while loading

## Customization

### CSS Classes

The plugin uses these CSS classes you can target:

```css
.ep-instant-results         /* Results container */
.ep-instant-results.active   /* When showing results */
.autosuggest-item           /* Individual result */
.autosuggest-link           /* Result link */
.product-image              /* Product thumbnail */
.product-title              /* Product/post title */
.product-price              /* Product price */
.product-sku                /* Product SKU */
.result-type                /* Post type label */
.result-excerpt             /* Post excerpt */
.loading                    /* Loading container */
.spinner                    /* Animated spinner */
.no-results                 /* No results message */
.view-all                   /* View all results link */
```

### Hooks and Filters

```php
// Modify search query arguments
add_filter('ep_instant_search_query_args', function($args, $search_term) {
    // Customize query args
    return $args;
}, 10, 2);
```

## Troubleshooting

### Search not working?

1. **Check ElasticPress status**:
   - Go to ElasticPress → Health
   - Ensure connection is active
   - Verify content is indexed

2. **Verify settings**:
   - ElasticPress → Features → Search is enabled
   - ElasticPress → Features → WooCommerce is enabled (for products)
   - Content is synced (run sync if needed)

3. **Test in admin**:
   - Go to ElasticPress → Instant Search
   - Use the "Test Search" field
   - Check browser console for errors

### Results not appearing?

- Clear browser cache
- Check browser console for JavaScript errors
- Verify AJAX URL is accessible
- Ensure no security plugins blocking AJAX requests

### Slow performance?

- Reduce search delay in settings
- Check Elasticsearch server response time
- Verify object cache is working
- Review ElasticPress query monitor data

## Compatibility

- Works with most themes and page builders
- Compatible with popular search plugins that use standard WordPress search
- Supports custom post types indexed by ElasticPress
- Multi-language support through ElasticPress

## Performance

Typical response times:
- **Search trigger**: 100ms after typing stops
- **Results display**: 200-500ms (depends on Elasticsearch)
- **Cached results**: < 50ms

## Support

This plugin is provided as-is. For issues related to:
- **Search accuracy/relevance**: Configure in ElasticPress settings
- **Elasticsearch connection**: See ElasticPress documentation
- **Plugin bugs**: Create an issue with details

## License

GPL v2 or later

## Credits

Built to work with the excellent [ElasticPress](https://github.com/10up/ElasticPress) plugin by 10up.

## Changelog

### 1.0.0
- Initial release
- Instant search with loading spinner
- Multi-content type support
- WooCommerce product search
- Mobile optimized
- Smart caching system
- Bulk data loading for performance