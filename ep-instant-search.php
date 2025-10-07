<?php
/**
 * Plugin Name: ElasticPress Instant Search
 * Plugin URI: https://friendlyfires.ca
 * Description: Custom instant search for WooCommerce products using ElasticPress without requiring ElasticPress.io subscription. Supports searching by variation SKUs.
 * Version: 2.6.0
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
        
        // Add global filter for variation SKU search
        add_filter('ep_formatted_args', array($this, 'modify_search_for_skus'), 100, 3);
    }
    
    /**
     * Modify ElasticPress query to search variation SKUs
     */
    public function modify_search_for_skus($formatted_args, $args, $wp_query) {
        // Log for debugging
        $log_file = WP_CONTENT_DIR . '/ep-instant-debug.txt';
        file_put_contents($log_file, "GLOBAL FILTER: ep_formatted_args triggered\n", FILE_APPEND);

        // Only modify if this is a search query with a search term
        if (empty($args['s']) || !isset($formatted_args['query'])) {
            file_put_contents($log_file, "Skipping - no search term or query\n", FILE_APPEND);
            return $formatted_args;
        }

        $search_term = $args['s'];
        file_put_contents($log_file, "Search term: {$search_term}\n", FILE_APPEND);

        // Keep the original query
        $original_query = $formatted_args['query'];

        // Try both uppercase and lowercase for variation SKUs
        $search_upper = strtoupper($search_term);
        $search_lower = strtolower($search_term);

        file_put_contents($log_file, "MODIFYING QUERY to include variation SKU search\n", FILE_APPEND);

        // Create a bool query that includes both the original query AND variation SKU matching
        $formatted_args['query'] = array(
            'bool' => array(
                'should' => array(
                    // Original ElasticPress query
                    $original_query,
                    // Exact match on parent SKU field
                    array(
                        'term' => array(
                            'meta._sku.value.raw' => array(
                                'value' => $search_lower,
                                'boost' => 100
                            )
                        )
                    ),
                    // Exact match on variation SKUs (uppercase)
                    array(
                        'term' => array(
                            'meta._variations_skus.value.raw' => array(
                                'value' => $search_upper,
                                'boost' => 100
                            )
                        )
                    ),
                    // Case-insensitive match on variation SKUs
                    array(
                        'match' => array(
                            'meta._variations_skus.value' => array(
                                'query' => $search_term,
                                'boost' => 90
                            )
                        )
                    ),
                    // Wildcard match for partial parent SKUs
                    array(
                        'wildcard' => array(
                            'meta._sku.value' => array(
                                'value' => '*' . $search_lower . '*',
                                'boost' => 50
                            )
                        )
                    ),
                    // Wildcard match for partial variation SKUs
                    array(
                        'wildcard' => array(
                            'meta._variations_skus.value' => array(
                                'value' => '*' . $search_upper . '*',
                                'boost' => 50
                            )
                        )
                    )
                ),
                'minimum_should_match' => 1
            )
        );

        file_put_contents($log_file, "Query modified successfully\n", FILE_APPEND);

        return $formatted_args;
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
                '2.5.0',
                true
            );
            
            wp_localize_script('ep-instant-search-js', 'ep_instant_search', $settings);
        }
    }
    
    /**
     * Handle AJAX search request
     */
    public function handle_search() {
        // CRITICAL: Enable ElasticPress for AJAX requests
        add_filter('ep_ajax_wp_query_integration', '__return_true');
        add_filter('ep_enable_do_weighting', '__return_true');

        // CUSTOM DEBUG - write to a file we control
        $log_file = WP_CONTENT_DIR . '/ep-instant-debug.txt';
        $log_msg = "\n" . date('Y-m-d H:i:s') . " - New search request\n";
        file_put_contents($log_file, $log_msg, FILE_APPEND);

        $search_term = sanitize_text_field($_GET['q'] ?? '');
        file_put_contents($log_file, "Search term: {$search_term}\n", FILE_APPEND);
        
        if (empty($search_term) || strlen($search_term) < 2) {
            file_put_contents($log_file, "Search term too short, exiting\n", FILE_APPEND);
            wp_send_json(array());
            exit;
        }
        
        // Check if this looks like a SKU search (contains hyphen or underscore)
        $is_sku_search = preg_match('/[-_]/', $search_term);
        file_put_contents($log_file, "Is SKU search: " . ($is_sku_search ? 'YES' : 'NO') . "\n", FILE_APPEND);
        
        // Check if ElasticPress is active
        if (!defined('EP_VERSION')) {
            file_put_contents($log_file, "ElasticPress not active!\n", FILE_APPEND);
            wp_send_json(array());
            exit;
        }
        
        file_put_contents($log_file, "ElasticPress version: " . EP_VERSION . "\n", FILE_APPEND);

        // Try to get cached results first
        $cache_key = 'ep_search_' . md5($search_term . '_' . get_locale());
        $cached_results = wp_cache_get($cache_key, 'ep_instant_search');

        if (false !== $cached_results) {
            file_put_contents($log_file, "Returning cached results\n", FILE_APPEND);
            wp_send_json($cached_results);
            exit;
        }
        
        // Set up the query to use ElasticPress
        $args = array(
            'post_type' => array('product', 'post', 'page'),
            'post_status' => 'publish',
            's' => $search_term,
            'posts_per_page' => intval(get_option('ep_instant_search_max_results', 8)),
            'cache_results' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'ep_integrate' => true,
        );
        
        file_put_contents($log_file, "Query args set up\n", FILE_APPEND);

        // Allow filtering of search args (for customization if needed)
        $args = apply_filters('ep_instant_search_query_args', $args, $search_term);

        file_put_contents($log_file, "Running WP_Query with ElasticPress integration...\n", FILE_APPEND);

        // Perform the query - ElasticPress will now integrate thanks to ep_ajax_wp_query_integration filter
        $query = new WP_Query($args);

        // Debug: Log if ElasticPress actually ran
        $ep_success = property_exists($query, 'elasticsearch_success') ? ($query->elasticsearch_success ? 'Yes' : 'No') : 'Unknown';
        file_put_contents($log_file, "EP Integration Success: {$ep_success}\n", FILE_APPEND);
        file_put_contents($log_file, "Found posts: {$query->found_posts}\n", FILE_APPEND);
        
        $results = array();
        
        if ($query->have_posts()) {
            file_put_contents($log_file, "Processing {$query->post_count} posts\n", FILE_APPEND);
            
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
            
            file_put_contents($log_file, "Product IDs: " . implode(',', $product_ids) . "\n", FILE_APPEND);
            
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
                        $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 30);
                        if ($excerpt) {
                            $result['excerpt'] = wp_trim_words($excerpt, 15);
                        }
                    }
                }
                
                $results[] = $result;
            }
        }
        
        file_put_contents($log_file, "Returning " . count($results) . " results\n", FILE_APPEND);

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
            if (defined('EP_VERSION')) {
                ?>
                <div class="notice notice-success">
                    <p>✓ ElasticPress Version: <strong><?php echo EP_VERSION; ?></strong></p>
                </div>
                <?php
                
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
            
            <h2>Debug Log</h2>
            <p>Recent search attempts are logged here:</p>
            <button type="button" id="view-debug-log" class="button">View Debug Log</button>
            <button type="button" id="clear-debug-log" class="button">Clear Debug Log</button>
            <pre id="debug-log-content" style="background: #f0f0f0; padding: 10px; max-height: 400px; overflow-y: auto; display: none;"></pre>
            
            <hr>
            
            <h2>Test Search</h2>
            <p>Type here to test instant search:</p>
            <input type="text" id="test-search-input" class="regular-text" placeholder="Search products..." style="width: 300px;" />
            <div id="test-search-results"></div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#view-debug-log').on('click', function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ep_instant_view_log'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#debug-log-content').text(response.data).show();
                            } else {
                                $('#debug-log-content').text('Error loading log: ' + response.data).show();
                            }
                        }
                    });
                });
                
                $('#clear-debug-log').on('click', function() {
                    if (confirm('Are you sure you want to clear the debug log?')) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ep_instant_clear_log'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#debug-log-content').text('Log cleared').show();
                                    setTimeout(function() {
                                        $('#debug-log-content').hide();
                                    }, 2000);
                                }
                            }
                        });
                    }
                });
                
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

// AJAX handler to view debug log
add_action('wp_ajax_ep_instant_view_log', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $log_file = WP_CONTENT_DIR . '/ep-instant-debug.txt';
    if (file_exists($log_file)) {
        $content = file_get_contents($log_file);
        wp_send_json_success($content);
    } else {
        wp_send_json_error('Log file not found');
    }
});

// AJAX handler to clear debug log
add_action('wp_ajax_ep_instant_clear_log', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $log_file = WP_CONTENT_DIR . '/ep-instant-debug.txt';
    if (file_exists($log_file)) {
        unlink($log_file);
    }
    wp_send_json_success();
});