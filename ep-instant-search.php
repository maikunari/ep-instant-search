<?php
/**
 * Plugin Name: ElasticPress Instant Search
 * Plugin URI: https://friendlyfires.ca
 * Description: Custom instant search for WooCommerce products using ElasticPress without requiring ElasticPress.io subscription
 * Version: 1.0.0
 * Author: Friendly Fires
 * Text Domain: ep-instant-search
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class EP_Instant_Search {
    
    /**
     * Initialize the plugin
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ep_instant_search', array($this, 'handle_search'));
        add_action('wp_ajax_nopriv_ep_instant_search', array($this, 'handle_search'));
        add_action('wp_head', array($this, 'add_inline_styles'));
        
        // Admin settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check if ElasticPress is active
        if (!defined('EP_VERSION')) {
            add_action('admin_notices', array($this, 'elasticpress_missing_notice'));
            return;
        }
    }
    
    /**
     * Admin notice if ElasticPress is not active
     */
    public function elasticpress_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('ElasticPress Instant Search requires ElasticPress plugin to be installed and activated.', 'ep-instant-search'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        // Load on all frontend pages since search bar is in header
        if (!is_admin()) {
            // Cache settings in transient for better performance
            $settings_key = 'ep_instant_search_settings';
            $settings = get_transient($settings_key);
            
            if (false === $settings) {
                $settings = array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'min_chars' => intval(get_option('ep_instant_search_min_chars', 2)),
                    'max_results' => intval(get_option('ep_instant_search_max_results', 8)),
                    'show_price' => get_option('ep_instant_search_show_price', 'yes'),
                    'show_image' => get_option('ep_instant_search_show_image', 'yes'),
                    'show_sku' => get_option('ep_instant_search_show_sku', 'no'),
                    'search_delay' => intval(get_option('ep_instant_search_delay', 100)),
                    'selectors' => get_option('ep_instant_search_selectors', '.search-field, input[name="s"], .dgwt-wcas-search-input'),
                    'debug' => defined('WP_DEBUG') && WP_DEBUG
                );
                set_transient($settings_key, $settings, HOUR_IN_SECONDS);
            }
            
            wp_enqueue_script(
                'ep-instant-search-js',
                plugin_dir_url(__FILE__) . 'assets/instant-search.js',
                array('jquery'),
                '2.3.2', // Smooth spinner animation
                true
            );
            
            wp_localize_script('ep-instant-search-js', 'ep_instant_search', $settings);
        }
    }
    
    /**
     * Handle AJAX search request
     */
    public function handle_search() {
        $search_term = sanitize_text_field($_GET['q'] ?? '');
        
        if (empty($search_term) || strlen($search_term) < 2) {
            wp_send_json(array());
            exit;
        }
        
        // Check if this looks like a SKU search (contains hyphen or underscore)
        $is_sku_search = preg_match('/[-_]/', $search_term);
        
        // Check if ElasticPress is active
        if (!defined('EP_VERSION')) {
            wp_send_json(array());
            exit;
        }
        
        // Try to get cached results first
        $cache_key = 'ep_search_' . md5($search_term . '_' . get_locale());
        $cached_results = wp_cache_get($cache_key, 'ep_instant_search');
        
        if (false !== $cached_results) {
            wp_send_json($cached_results);
            exit;
        }
        
        // Set up the query to use ElasticPress with ALL its features
        // Let ElasticPress determine which post types to search based on its configuration
        $args = array(
            // Don't specify post_type - let ElasticPress use its configured post types
            // This will search posts, pages, products, and any other indexed types
            'post_status' => 'publish',
            's' => $search_term,
            'posts_per_page' => intval(get_option('ep_instant_search_max_results', 8)),
            // Get full post objects to avoid multiple queries later
            'cache_results' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'ep_integrate' => true,
        );
        
        // For SKU searches, we need to ensure exact matching works
        if ($is_sku_search) {
            // Add a filter to boost exact SKU matches
            add_filter('ep_formatted_args', function($formatted_args) use ($search_term) {
                // Ensure SKU field is being searched with proper analyzer
                if (isset($formatted_args['query'])) {
                    // Keep the original query but boost exact SKU matches
                    $original_query = $formatted_args['query'];
                    
                    // Create a bool query that includes both fuzzy and exact matching
                    $formatted_args['query'] = array(
                        'bool' => array(
                            'should' => array(
                                // Original ElasticPress query (with all features)
                                $original_query,
                                // Exact match on SKU field (highest boost)
                                array(
                                    'term' => array(
                                        'meta._sku.value.raw' => array(
                                            'value' => strtolower($search_term),
                                            'boost' => 100
                                        )
                                    )
                                ),
                                // Wildcard match for partial SKUs
                                array(
                                    'wildcard' => array(
                                        'meta._sku.value' => array(
                                            'value' => '*' . strtolower($search_term) . '*',
                                            'boost' => 50
                                        )
                                    )
                                )
                            )
                        )
                    );
                }
                return $formatted_args;
            }, 100);
        }
        
        // Allow filtering of search args (for customization if needed)
        $args = apply_filters('ep_instant_search_query_args', $args, $search_term);
        
        // Perform the query - ElasticPress will automatically handle everything
        // The 'ep_integrate' => true parameter tells ElasticPress to take over
        // It will apply all configured settings including:
        // - Field weights (title:40, SKU:40, content:20, etc.)
        // - Synonyms
        // - Fuzziness
        // - All other features
        $query = new WP_Query($args);
        
        // Clean up our SKU filter if we added it
        if ($is_sku_search) {
            remove_all_filters('ep_formatted_args', 100);
        }
        
        // Debug: Log if ElasticPress actually ran
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EP Instant Search Query for: ' . $search_term);
            error_log('EP Integration Success: ' . (property_exists($query, 'elasticsearch_success') ? ($query->elasticsearch_success ? 'Yes' : 'No') : 'Unknown'));
            error_log('Found posts: ' . $query->found_posts);
        }
        
        $results = array();
        
        if ($query->have_posts()) {
            $show_price = get_option('ep_instant_search_show_price', 'yes') === 'yes';
            $show_image = get_option('ep_instant_search_show_image', 'yes') === 'yes';
            $show_sku = get_option('ep_instant_search_show_sku', 'no') === 'yes';
            
            // Pre-load all WooCommerce products in one query if needed
            $product_ids = array();
            foreach ($query->posts as $post) {
                if ($post->post_type === 'product') {
                    $product_ids[] = $post->ID;
                }
            }
            
            // Bulk load products if we have any
            $products_map = array();
            if (!empty($product_ids) && function_exists('wc_get_products')) {
                $products = wc_get_products(array(
                    'include' => $product_ids,
                    'limit' => -1,
                ));
                foreach ($products as $product) {
                    $products_map[$product->get_id()] = $product;
                }
            }
            
            foreach ($query->posts as $post) {
                $post_type = $post->post_type;
                $post_id = $post->ID;
                
                $result = array(
                    'id' => $post_id,
                    'title' => html_entity_decode($post->post_title),
                    'url' => get_permalink($post_id),
                    'type' => $post_type,
                );
                
                // Add image for all post types if enabled
                if ($show_image) {
                    $result['image'] = get_the_post_thumbnail_url($post_id, 'thumbnail');
                }
                
                // Handle WooCommerce products specifically
                if ($post_type === 'product' && isset($products_map[$post_id])) {
                    $product = $products_map[$post_id];
                    
                    if ($product && !$product->is_visible()) {
                        continue; // Skip hidden products
                    }
                    
                    if ($product) {
                        // Add product-specific data
                        if ($show_price) {
                            $result['price'] = $product->get_price_html();
                        }
                        
                        if ($show_sku && $sku = $product->get_sku()) {
                            $result['sku'] = $sku;
                        }
                        
                        $result['in_stock'] = $product->is_in_stock();
                        
                        // Use product image if no featured image
                        if ($show_image && !$result['image']) {
                            $image_id = $product->get_image_id();
                            $result['image'] = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                        }
                    }
                } else {
                    // For non-products, add post type label
                    $post_type_obj = get_post_type_object($post_type);
                    $result['type_label'] = $post_type_obj ? $post_type_obj->labels->singular_name : $post_type;
                    
                    // Add excerpt for posts/pages
                    if (in_array($post_type, array('post', 'page'))) {
                        // Use post_excerpt directly from the post object
                        $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 30);
                        if ($excerpt) {
                            $result['excerpt'] = wp_trim_words($excerpt, 15);
                        }
                    }
                }
                
                $results[] = $result;
            }
        }
        
        // Cache results for 5 minutes
        wp_cache_set($cache_key, $results, 'ep_instant_search', 300);
        
        // Return results
        wp_send_json($results);
    }
    
    /**
     * Add inline styles
     */
    public function add_inline_styles() {
        $custom_css = get_option('ep_instant_search_custom_css', '');
        ?>
        <style>
            .ep-instant-results {
                position: absolute;
                top: calc(100% + 2px);
                left: 0;
                right: 0;
                background: #fff;
                border: 1px solid #ddd;
                max-height: 400px;
                overflow-y: auto;
                z-index: 99999;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                display: none;
                margin-top: 0;
            }
            
            /* Ensure container is positioned */
            .ep-autosuggest-container {
                position: relative !important;
            }
            
            .ep-instant-results.active {
                display: block;
            }
            
            .ep-instant-results ul {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            
            .ep-instant-results li {
                border-bottom: 1px solid #eee;
            }
            
            .ep-instant-results li:last-child {
                border-bottom: none;
            }
            
            .ep-instant-results li.selected {
                background-color: #f0f0f0;
            }
            
            .ep-instant-results a {
                display: flex;
                align-items: center;
                padding: 10px 15px;
                text-decoration: none;
                color: inherit;
                transition: background-color 0.2s;
            }
            
            .ep-instant-results a:hover {
                background-color: #f8f8f8;
            }
            
            .ep-instant-results .product-image {
                width: 50px;
                height: 50px;
                margin-right: 15px;
                object-fit: cover;
                border-radius: 4px;
            }
            
            .ep-instant-results .product-details {
                flex: 1;
            }
            
            .ep-instant-results .product-title {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                color: #333;
                font-size: 14px;
            }
            
            .ep-instant-results .product-price {
                display: block;
                color: #77a464;
                font-size: 13px;
                font-weight: 500;
            }
            
            .ep-instant-results .product-sku {
                display: block;
                color: #999;
                font-size: 11px;
                margin-top: 2px;
            }
            
            .ep-instant-results .result-type {
                display: inline-block;
                background: #f0f0f0;
                color: #666;
                font-size: 11px;
                padding: 2px 6px;
                border-radius: 3px;
                margin-bottom: 4px;
                text-transform: uppercase;
            }
            
            .ep-instant-results .result-excerpt {
                display: block;
                color: #666;
                font-size: 12px;
                margin-top: 4px;
                line-height: 1.4;
            }
            
            .ep-instant-results .out-of-stock {
                opacity: 0.6;
            }
            
            .ep-instant-results .out-of-stock .product-price {
                color: #e74c3c;
            }
            
            .ep-instant-results .loading {
                padding: 20px;
                text-align: center;
                color: #666;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
            }
            
            .ep-instant-results .spinner {
                width: 20px;
                height: 20px;
                border: 3px solid #f3f3f3;
                border-top: 3px solid #333;
                border-radius: 50%;
                animation: ep-spin 1s linear infinite;
            }
            
            @keyframes ep-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .ep-instant-results .no-results {
                padding: 20px;
                text-align: center;
                color: #999;
            }
            
            .ep-instant-results .view-all {
                display: block;
                padding: 12px;
                text-align: center;
                background: #f8f8f8;
                color: #333;
                text-decoration: none;
                border-top: 1px solid #ddd;
                font-weight: 500;
                font-size: 13px;
            }
            
            .ep-instant-results .view-all:hover {
                background: #efefef;
            }
            
            @media (max-width: 768px) {
                .ep-instant-results {
                    position: absolute;
                    top: calc(100% + 5px);
                    left: 0;
                    right: 0;
                    max-height: 60vh;
                    width: 100%;
                    margin-top: 0;
                }
                
                /* Specific fix for search forms with extra padding/margin */
                .ep-autosuggest-container {
                    position: relative;
                }
                
                .ep-autosuggest-container .ep-instant-results {
                    top: auto;
                    margin-top: 5px;
                }
            }
            
            <?php echo esc_html($custom_css); ?>
        </style>
        <?php
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'elasticpress',
            'Instant Search Settings',
            'Instant Search',
            'manage_options',
            'ep-instant-search',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ep_instant_search_settings', 'ep_instant_search_min_chars', array(
            'sanitize_callback' => 'absint',
            'default' => 2
        ));
        register_setting('ep_instant_search_settings', 'ep_instant_search_max_results', array(
            'sanitize_callback' => 'absint',
            'default' => 8
        ));
        register_setting('ep_instant_search_settings', 'ep_instant_search_show_price');
        register_setting('ep_instant_search_settings', 'ep_instant_search_show_image');
        register_setting('ep_instant_search_settings', 'ep_instant_search_show_sku');
        register_setting('ep_instant_search_settings', 'ep_instant_search_delay', array(
            'sanitize_callback' => 'absint',
            'default' => 100
        ));
        register_setting('ep_instant_search_settings', 'ep_instant_search_selectors', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('ep_instant_search_settings', 'ep_instant_search_custom_css', array(
            'sanitize_callback' => 'wp_strip_all_tags'
        ));
        
        // Clear transient when settings are updated
        add_action('update_option_ep_instant_search_min_chars', array($this, 'clear_settings_cache'));
        add_action('update_option_ep_instant_search_max_results', array($this, 'clear_settings_cache'));
    }
    
    /**
     * Clear settings cache
     */
    public function clear_settings_cache() {
        delete_transient('ep_instant_search_settings');
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>ElasticPress Instant Search Settings</h1>
            
            <?php
            // Check ElasticPress status
            if (defined('EP_VERSION')) {
                ?>
                <div class="notice notice-success">
                    <p>✓ ElasticPress Version: <strong><?php echo EP_VERSION; ?></strong></p>
                </div>
                <?php
                
                // Check if WooCommerce feature is active
                $features = \ElasticPress\Features::factory();
                $wc_feature = $features->get_registered_feature('woocommerce');
                if ($wc_feature && $wc_feature->is_active()) {
                    ?>
                    <div class="notice notice-success">
                        <p>✓ WooCommerce Feature: <strong>Active</strong></p>
                    </div>
                    <?php
                } else {
                    ?>
                    <div class="notice notice-warning">
                        <p>⚠ WooCommerce Feature is not active. Please activate it in ElasticPress > Features.</p>
                    </div>
                    <?php
                }
            } else {
                ?>
                <div class="notice notice-error">
                    <p>✗ ElasticPress is not active!</p>
                </div>
                <?php
            }
            ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('ep_instant_search_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Minimum Characters</th>
                        <td>
                            <input type="number" name="ep_instant_search_min_chars" value="<?php echo esc_attr(get_option('ep_instant_search_min_chars', 2)); ?>" min="1" max="10" />
                            <p class="description">Minimum number of characters before search starts</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Maximum Results</th>
                        <td>
                            <input type="number" name="ep_instant_search_max_results" value="<?php echo esc_attr(get_option('ep_instant_search_max_results', 8)); ?>" min="1" max="20" />
                            <p class="description">Maximum number of results to show</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Search Delay (ms)</th>
                        <td>
                            <input type="number" name="ep_instant_search_delay" value="<?php echo esc_attr(get_option('ep_instant_search_delay', 300)); ?>" min="100" max="2000" step="100" />
                            <p class="description">Delay in milliseconds before search executes (prevents too many requests)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Display Options</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ep_instant_search_show_price" value="yes" <?php checked(get_option('ep_instant_search_show_price', 'yes'), 'yes'); ?> />
                                Show product price
                            </label><br>
                            
                            <label>
                                <input type="checkbox" name="ep_instant_search_show_image" value="yes" <?php checked(get_option('ep_instant_search_show_image', 'yes'), 'yes'); ?> />
                                Show product image
                            </label><br>
                            
                            <label>
                                <input type="checkbox" name="ep_instant_search_show_sku" value="yes" <?php checked(get_option('ep_instant_search_show_sku', 'no'), 'yes'); ?> />
                                Show product SKU
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Search Field Selectors</th>
                        <td>
                            <input type="text" name="ep_instant_search_selectors" value="<?php echo esc_attr(get_option('ep_instant_search_selectors', '.search-field, input[name="s"], .dgwt-wcas-search-input')); ?>" class="large-text" />
                            <p class="description">CSS selectors for search input fields (comma-separated)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Custom CSS</th>
                        <td>
                            <textarea name="ep_instant_search_custom_css" rows="10" class="large-text code"><?php echo esc_textarea(get_option('ep_instant_search_custom_css', '')); ?></textarea>
                            <p class="description">Add custom CSS to style the instant search results</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Test Search</h2>
            <p>Type here to test instant search:</p>
            <input type="text" id="test-search-input" class="regular-text" placeholder="Search products..." style="width: 300px;" />
            <div id="test-search-results"></div>
            
            <hr>
            
            <h2>Debug Information</h2>
            <ul>
                <li>WooCommerce Active: <?php echo class_exists('WooCommerce') ? 'Yes' : 'No'; ?></li>
                <li>Total Products: <?php echo wp_count_posts('product')->publish; ?></li>
            </ul>
            
            <h3>Active ElasticPress Features Being Used</h3>
            <ul>
                <?php
                if (defined('EP_VERSION')) {
                    $features = \ElasticPress\Features::factory();
                    $active_features = array();
                    
                    // Check which features are active and affect search
                    $search_features = array(
                        'search' => 'Search (field weights & algorithms)',
                        'woocommerce' => 'WooCommerce Products',
                        'synonyms' => 'Synonyms',
                        'autosuggest' => 'Autosuggest (we use our own UI)',
                        'instant-results' => 'Instant Results (we use our own UI)',
                        'did-you-mean' => 'Did You Mean',
                        'related_posts' => 'Related Posts',
                        'facets' => 'Faceted Search',
                        'searchordering' => 'Custom Search Results Order'
                    );
                    
                    foreach ($search_features as $feature_slug => $feature_name) {
                        $feature = $features->get_registered_feature($feature_slug);
                        if ($feature && $feature->is_active()) {
                            $active_features[] = $feature_name;
                            echo '<li>✓ ' . esc_html($feature_name) . '</li>';
                        }
                    }
                    
                    if (empty($active_features)) {
                        echo '<li>No ElasticPress features are active</li>';
                    }
                    
                    // Show search configuration
                    $search_feature = $features->get_registered_feature('search');
                    if ($search_feature && $search_feature->is_active()) {
                        $search_settings = $search_feature->get_settings();
                        if (!empty($search_settings['decaying_enabled'])) {
                            echo '<li>✓ Decay by date weighting: Enabled</li>';
                        }
                    }
                }
                ?>
            </ul>
            
            <h3>Test Direct Query</h3>
            <button type="button" id="test-direct-query" class="button">Test ElasticPress Query</button>
            <div id="direct-query-results" style="margin-top: 10px; padding: 10px; background: #f0f0f0; display: none;"></div>
            
            <script>
            jQuery(document).ready(function($) {
                // Test direct query
                $('#test-direct-query').on('click', function() {
                    var $results = $('#direct-query-results');
                    $results.show().html('Testing ElasticPress query...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ep_instant_test_query',
                            nonce: '<?php echo wp_create_nonce('ep_instant_test'); ?>'
                        },
                        success: function(response) {
                            console.log('Direct Query Response:', response);
                            $results.html('<pre>' + JSON.stringify(response, null, 2) + '</pre>');
                        },
                        error: function(xhr, status, error) {
                            console.error('Query Error:', error);
                            $results.html('Error: ' + error);
                        }
                    });
                });
                
                // Test search on settings page
                $('#test-search-input').on('input', function() {
                    var query = $(this).val();
                    if (query.length < 2) {
                        $('#test-search-results').empty();
                        return;
                    }
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'GET',
                        data: {
                            action: 'ep_instant_search',
                            q: query
                        },
                        success: function(response) {
                            console.log('Search Response:', response);
                            var html = '<div style="margin-top: 10px; padding: 10px; background: #f9f9f9;">';
                            if (response && response.length > 0) {
                                html += '<strong>Found ' + response.length + ' results:</strong><ul>';
                                $.each(response, function(i, product) {
                                    html += '<li>' + product.title + ' (ID: ' + product.id + ')</li>';
                                });
                                html += '</ul>';
                            } else {
                                html += 'No results found';
                            }
                            html += '</div>';
                            $('#test-search-results').html(html);
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
}

// Initialize the plugin
new EP_Instant_Search();

// Add test query handler for debugging
add_action('wp_ajax_ep_instant_test_query', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    check_ajax_referer('ep_instant_test', 'nonce');
    
    // Simple test query
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        's' => 'test',
        'posts_per_page' => 5,
        'ep_integrate' => true,
        'fields' => 'ids'
    );
    
    $query = new WP_Query($args);
    
    $response = array(
        'found_posts' => $query->found_posts,
        'post_count' => $query->post_count,
        'posts' => $query->posts,
        'elasticsearch_success' => property_exists($query, 'elasticsearch_success') ? $query->elasticsearch_success : 'unknown',
        'ep_active' => defined('EP_VERSION'),
        'wc_feature_active' => false
    );
    
    if (defined('EP_VERSION')) {
        $features = \ElasticPress\Features::factory();
        $wc_feature = $features->get_registered_feature('woocommerce');
        $response['wc_feature_active'] = $wc_feature && $wc_feature->is_active();
    }
    
    wp_send_json($response);
});