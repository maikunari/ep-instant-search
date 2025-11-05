<?php
/**
 * Plugin Name: ElasticPress Instant Search
 * Plugin URI: https://github.com/maikunari/ep-instant-search
 * Description: SUPER DIAGNOSTIC VERSION - Logs every initialization step
 * Version: 2.14.1-diagnostic-full
 * Author: Mike Sewell
 * Author URI: https://sonicpixel.jp
 * Text Domain: ep-instant-search
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// DIAGNOSTIC LOGGING - Start immediately
$diagnostic_log = WP_CONTENT_DIR . '/ep-diagnostic.log';

function diagnostic_log($message) {
    global $diagnostic_log;
    $timestamp = date('Y-m-d H:i:s');
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = isset($trace[1]) ? $trace[1]['function'] : 'global';
    file_put_contents($diagnostic_log, "[$timestamp] [$caller] $message\n", FILE_APPEND);
}

diagnostic_log("=== PLUGIN FILE LOADED ===");

class EP_Instant_Search {

    public function __construct() {
        diagnostic_log("Constructor: START");

        try {
            diagnostic_log("Constructor: Registering init hook");
            add_action('init', array($this, 'init'));

            diagnostic_log("Constructor: Registering enqueue_scripts hook");
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

            diagnostic_log("Constructor: Registering AJAX hooks");
            add_action('wp_ajax_ep_instant_search', array($this, 'handle_search'));
            add_action('wp_ajax_nopriv_ep_instant_search', array($this, 'handle_search'));

            diagnostic_log("Constructor: Registering inline styles hook");
            add_action('wp_head', array($this, 'add_inline_styles'));

            diagnostic_log("Constructor: Registering admin hooks");
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));

            diagnostic_log("Constructor: COMPLETE");
        } catch (Exception $e) {
            diagnostic_log("Constructor: EXCEPTION - " . $e->getMessage());
            throw $e;
        }
    }

    public function init() {
        diagnostic_log("init: START");

        try {
            // Check if ElasticPress is active
            if (!defined('EP_VERSION')) {
                diagnostic_log("init: ElasticPress NOT active");
                add_action('admin_notices', array($this, 'elasticpress_missing_notice'));
                return;
            }

            diagnostic_log("init: ElasticPress version " . EP_VERSION);

            // Add global filter for variation SKU search
            diagnostic_log("init: Adding ep_formatted_args filter");
            add_filter('ep_formatted_args', array($this, 'modify_search_for_skus'), 100, 3);

            diagnostic_log("init: COMPLETE");
        } catch (Exception $e) {
            diagnostic_log("init: EXCEPTION - " . $e->getMessage());
        }
    }

    public function modify_search_for_skus($formatted_args, $args, $wp_query) {
        // Only modify if this is a search query with a search term
        if (empty($args['s']) || !isset($formatted_args['query'])) {
            return $formatted_args;
        }

        $search_term = $args['s'];
        $original_query = $formatted_args['query'];
        $search_upper = strtoupper($search_term);
        $search_lower = strtolower($search_term);

        // Create a bool query that includes both the original query AND variation SKU matching
        $formatted_args['query'] = array(
            'bool' => array(
                'should' => array(
                    $original_query,
                    array(
                        'term' => array(
                            'meta._sku.value.raw' => array(
                                'value' => $search_lower,
                                'boost' => 100
                            )
                        )
                    ),
                    array(
                        'term' => array(
                            'meta._variations_skus.value.raw' => array(
                                'value' => $search_upper,
                                'boost' => 100
                            )
                        )
                    ),
                    array(
                        'match' => array(
                            'meta._variations_skus.value' => array(
                                'query' => $search_term,
                                'boost' => 90
                            )
                        )
                    ),
                    array(
                        'wildcard' => array(
                            'meta._sku.value' => array(
                                'value' => '*' . $search_lower . '*',
                                'boost' => 50
                            )
                        )
                    ),
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

        return $formatted_args;
    }

    public function elasticpress_missing_notice() {
        diagnostic_log("elasticpress_missing_notice: Called");
        ?>
        <div class="notice notice-error">
            <p><?php _e('ElasticPress Instant Search requires ElasticPress plugin to be installed and activated.', 'ep-instant-search'); ?></p>
        </div>
        <?php
    }

    public function enqueue_scripts() {
        diagnostic_log("enqueue_scripts: START");

        try {
            if (!is_admin()) {
                diagnostic_log("enqueue_scripts: Loading settings");

                $settings = array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'min_chars' => intval(get_option('ep_instant_search_min_chars', 2)),
                    'max_results' => intval(get_option('ep_instant_search_max_results', 8)),
                    'show_price' => get_option('ep_instant_search_show_price', 'yes'),
                    'show_image' => get_option('ep_instant_search_show_image', 'yes'),
                    'show_sku' => get_option('ep_instant_search_show_sku', 'no'),
                    'search_delay' => intval(get_option('ep_instant_search_delay', 100)),
                    'selectors' => get_option('ep_instant_search_selectors', '.search-field, input[name="s"]'),
                    'debug' => true
                );

                diagnostic_log("enqueue_scripts: Enqueueing JavaScript");

                wp_enqueue_script(
                    'ep-instant-search-js',
                    plugin_dir_url(__FILE__) . 'assets/instant-search.js',
                    array('jquery'),
                    '2.14.0',
                    true
                );

                wp_localize_script('ep-instant-search-js', 'ep_instant_search', $settings);

                diagnostic_log("enqueue_scripts: COMPLETE");
            }
        } catch (Exception $e) {
            diagnostic_log("enqueue_scripts: EXCEPTION - " . $e->getMessage());
        }
    }

    public function handle_search() {
        diagnostic_log("handle_search: START");

        try {
            // CRITICAL: Enable ElasticPress for AJAX requests
            add_filter('ep_ajax_wp_query_integration', '__return_true');
            add_filter('ep_enable_do_weighting', '__return_true');
            diagnostic_log("handle_search: ElasticPress AJAX filters added");

            $search_term = sanitize_text_field($_GET['q'] ?? '');
            diagnostic_log("handle_search: Search term = " . $search_term);

            if (empty($search_term) || strlen($search_term) < 2) {
                diagnostic_log("handle_search: Search term too short, returning empty");
                wp_send_json(array());
                exit;
            }

            // Check if ElasticPress is active
            if (!defined('EP_VERSION')) {
                diagnostic_log("handle_search: ERROR - ElasticPress not active");
                wp_send_json(array());
                exit;
            }

            diagnostic_log("handle_search: ElasticPress version " . EP_VERSION);

            // Try to get cached results first
            $cache_key = 'ep_search_' . md5($search_term . '_' . get_locale());
            $cached_results = wp_cache_get($cache_key, 'ep_instant_search');

            if (false !== $cached_results) {
                diagnostic_log("handle_search: Returning cached results");
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

            diagnostic_log("handle_search: Query args prepared");

            // Allow filtering of search args
            $args = apply_filters('ep_instant_search_query_args', $args, $search_term);

            diagnostic_log("handle_search: Running WP_Query...");

            // Perform the query
            $query = new WP_Query($args);

            diagnostic_log("handle_search: WP_Query complete - found " . $query->found_posts . " posts");

            $results = array();

            if ($query->have_posts()) {
                diagnostic_log("handle_search: Processing results");

                $show_price = get_option('ep_instant_search_show_price', 'yes') === 'yes';
                $show_image = get_option('ep_instant_search_show_image', 'yes') === 'yes';
                $show_sku = get_option('ep_instant_search_show_sku', 'no') === 'yes';

                // Pre-load all WooCommerce products in one query
                $product_ids = array();
                foreach ($query->posts as $post) {
                    if ($post->post_type === 'product') {
                        $product_ids[] = $post->ID;
                    }
                }

                diagnostic_log("handle_search: Found " . count($product_ids) . " products");

                // Bulk load products
                $products_map = array();
                if (!empty($product_ids) && function_exists('wc_get_products')) {
                    $products = wc_get_products(array(
                        'include' => $product_ids,
                        'limit' => -1,
                    ));
                    foreach ($products as $product) {
                        $products_map[$product->get_id()] = $product;
                    }
                    diagnostic_log("handle_search: Loaded " . count($products_map) . " WooCommerce products");
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

            diagnostic_log("handle_search: Returning " . count($results) . " results");

            // Cache results for 5 minutes
            wp_cache_set($cache_key, $results, 'ep_instant_search', 300);

            // Return results
            wp_send_json($results);

        } catch (Exception $e) {
            diagnostic_log("handle_search: EXCEPTION - " . $e->getMessage());
            wp_send_json(array());
        }
    }

    public function add_inline_styles() {
        diagnostic_log("add_inline_styles: START");

        try {
            ?>
            <style>
                .ep-instant-results {
                    display: none;
                    position: absolute;
                    background: #fff;
                    border: 1px solid #ddd;
                    z-index: 99999;
                }
            </style>
            <?php

            diagnostic_log("add_inline_styles: COMPLETE");
        } catch (Exception $e) {
            diagnostic_log("add_inline_styles: EXCEPTION - " . $e->getMessage());
        }
    }

    public function add_admin_menu() {
        diagnostic_log("add_admin_menu: START");

        try {
            // Check if we can access ElasticPress admin
            if (!function_exists('add_submenu_page')) {
                diagnostic_log("add_admin_menu: add_submenu_page function doesn't exist!");
                return;
            }

            diagnostic_log("add_admin_menu: Checking for ElasticPress parent menu");

            // Try to add submenu - this might fail if ElasticPress isn't loaded yet
            $result = add_submenu_page(
                'elasticpress',
                'Instant Search Settings',
                'Instant Search',
                'manage_options',
                'ep-instant-search',
                array($this, 'settings_page')
            );

            if ($result === false) {
                diagnostic_log("add_admin_menu: add_submenu_page returned FALSE");
            } else {
                diagnostic_log("add_admin_menu: Submenu added successfully");
            }

            diagnostic_log("add_admin_menu: COMPLETE");
        } catch (Exception $e) {
            diagnostic_log("add_admin_menu: EXCEPTION - " . $e->getMessage());
        }
    }

    public function register_settings() {
        diagnostic_log("register_settings: START");

        try {
            diagnostic_log("register_settings: Registering settings");

            register_setting('ep_instant_search_settings', 'ep_instant_search_min_chars', array(
                'sanitize_callback' => 'absint',
                'default' => 2
            ));

            register_setting('ep_instant_search_settings', 'ep_instant_search_max_results', array(
                'sanitize_callback' => 'absint',
                'default' => 8
            ));

            diagnostic_log("register_settings: COMPLETE");
        } catch (Exception $e) {
            diagnostic_log("register_settings: EXCEPTION - " . $e->getMessage());
        }
    }

    public function settings_page() {
        diagnostic_log("settings_page: START");

        try {
            ?>
            <div class="wrap">
                <h1>ElasticPress Instant Search - Diagnostic Mode</h1>
                <div class="notice notice-info">
                    <p><strong>DIAGNOSTIC VERSION ACTIVE</strong></p>
                    <p>Check the diagnostic log at: <code><?php echo WP_CONTENT_DIR . '/ep-diagnostic.log'; ?></code></p>
                </div>

                <?php
                diagnostic_log("settings_page: Checking EP_VERSION");

                if (defined('EP_VERSION')) {
                    diagnostic_log("settings_page: EP_VERSION is " . EP_VERSION);
                    ?>
                    <div class="notice notice-success">
                        <p>✓ ElasticPress Version: <strong><?php echo EP_VERSION; ?></strong></p>
                    </div>
                    <?php

                    diagnostic_log("settings_page: Checking for ElasticPress\\Features class");

                    if (class_exists('\ElasticPress\Features')) {
                        diagnostic_log("settings_page: ElasticPress\\Features class exists");

                        if (method_exists('\ElasticPress\Features', 'factory')) {
                            diagnostic_log("settings_page: factory() method exists");

                            try {
                                $features = \ElasticPress\Features::factory();
                                diagnostic_log("settings_page: factory() returned: " . gettype($features));

                                if ($features && method_exists($features, 'get_registered_feature')) {
                                    diagnostic_log("settings_page: get_registered_feature() method exists");

                                    $wc_feature = $features->get_registered_feature('woocommerce');
                                    diagnostic_log("settings_page: WooCommerce feature: " . gettype($wc_feature));

                                    if ($wc_feature && method_exists($wc_feature, 'is_active')) {
                                        diagnostic_log("settings_page: is_active() method exists");

                                        $is_active = $wc_feature->is_active();
                                        diagnostic_log("settings_page: WooCommerce feature active: " . ($is_active ? 'YES' : 'NO'));

                                        if ($is_active) {
                                            ?>
                                            <div class="notice notice-success">
                                                <p>✓ WooCommerce Feature: <strong>Active</strong></p>
                                            </div>
                                            <?php
                                        } else {
                                            ?>
                                            <div class="notice notice-warning">
                                                <p>⚠ WooCommerce Feature is not active</p>
                                            </div>
                                            <?php
                                        }
                                    } else {
                                        diagnostic_log("settings_page: is_active() method doesn't exist or wc_feature is null");
                                    }
                                } else {
                                    diagnostic_log("settings_page: get_registered_feature() doesn't exist or features is null");
                                }
                            } catch (Exception $e) {
                                diagnostic_log("settings_page: EXCEPTION in features check - " . $e->getMessage());
                                ?>
                                <div class="notice notice-error">
                                    <p>Error checking features: <?php echo esc_html($e->getMessage()); ?></p>
                                </div>
                                <?php
                            }
                        } else {
                            diagnostic_log("settings_page: factory() method doesn't exist");
                        }
                    } else {
                        diagnostic_log("settings_page: ElasticPress\\Features class doesn't exist");
                    }
                } else {
                    diagnostic_log("settings_page: EP_VERSION not defined");
                    ?>
                    <div class="notice notice-error">
                        <p>✗ ElasticPress is not active!</p>
                    </div>
                    <?php
                }
                ?>

                <h2>Diagnostic Log</h2>
                <button type="button" id="reload-log" class="button">Reload Log</button>
                <pre id="log-content" style="background: #f0f0f0; padding: 10px; max-height: 600px; overflow-y: auto; font-family: monospace; font-size: 12px;"><?php
                    $log_file = WP_CONTENT_DIR . '/ep-diagnostic.log';
                    if (file_exists($log_file)) {
                        echo esc_html(file_get_contents($log_file));
                    } else {
                        echo "Log file not found";
                    }
                ?></pre>

                <script>
                jQuery(document).ready(function($) {
                    $('#reload-log').on('click', function() {
                        location.reload();
                    });
                });
                </script>
            </div>
            <?php

            diagnostic_log("settings_page: COMPLETE");
        } catch (Exception $e) {
            diagnostic_log("settings_page: EXCEPTION - " . $e->getMessage());
            echo '<div class="notice notice-error"><p>Fatal error in settings page: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

diagnostic_log("=== INITIALIZING PLUGIN CLASS ===");

try {
    $ep_instant_search = new EP_Instant_Search();
    diagnostic_log("=== PLUGIN CLASS INITIALIZED SUCCESSFULLY ===");
} catch (Exception $e) {
    diagnostic_log("=== PLUGIN INITIALIZATION FAILED: " . $e->getMessage() . " ===");
    throw $e;
}

diagnostic_log("=== PLUGIN FILE COMPLETE ===");
