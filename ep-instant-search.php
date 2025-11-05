<?php
/**
 * Plugin Name: ElasticPress Instant Search (DEBUG STEP 4)
 * Plugin URI: https://github.com/maikunari/ep-instant-search
 * Description: DEBUG STEP 4 - Testing actual search logic with is_search and is_main_query
 * Version: 2.12.5-debug-step4
 * Author: Mike Sewell
 * Author URI: https://sonicpixel.jp
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
        add_action('admin_notices', array($this, 'debug_notice'));
    }

    /**
     * Initialize with ElasticPress check
     */
    public function init() {
        // Check if ElasticPress is active
        if (!defined('EP_VERSION')) {
            // ElasticPress not found - will show error in admin
            return;
        }

        // If we got here, ElasticPress is active
        // Store this for the debug notice
        $this->ep_found = true;

        // Test filter registration with has_filter() check
        if (has_filter('ep_elasticpress_enabled')) {
            add_filter('ep_elasticpress_enabled', array($this, 'force_ep_for_search'), 10, 2);
            $this->filter_registered = 'modern (ep_elasticpress_enabled)';
        } else {
            // Fallback to pre_get_posts for older versions
            add_action('pre_get_posts', array($this, 'force_ep_for_search_legacy'), 10);
            $this->filter_registered = 'legacy (pre_get_posts)';
        }
    }

    /**
     * Modern method: Force ElasticPress via ep_elasticpress_enabled filter
     */
    public function force_ep_for_search($enabled, $query) {
        // Add safety checks before using query methods
        if (!$query || !is_object($query)) {
            return $enabled;
        }

        // Check if this is a search query
        // Using method_exists to prevent fatal errors
        if (!method_exists($query, 'is_main_query') || !method_exists($query, 'get')) {
            return $enabled;
        }

        // Only enable for frontend search queries
        if (is_search() && $query->is_main_query() && !is_admin()) {
            $this->search_forced = true; // Track for debug notice
            return true;
        }

        return $enabled;
    }

    /**
     * Legacy method: Force ElasticPress via pre_get_posts
     */
    public function force_ep_for_search_legacy($query) {
        // Add safety checks
        if (!$query || !is_object($query)) {
            return;
        }

        // Check methods exist
        if (!method_exists($query, 'is_main_query') || !method_exists($query, 'set')) {
            return;
        }

        // Only enable for frontend search queries
        if (is_search() && $query->is_main_query() && !is_admin()) {
            $query->set('ep_integrate', true);
            $this->search_forced = true; // Track for debug notice
        }
    }

    /**
     * Show that plugin loaded successfully
     */
    public function debug_notice() {
        if (isset($this->ep_found) && $this->ep_found) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>EP Instant Search DEBUG STEP 4:</strong> Full search logic active!</p>';
            echo '<p>Method: ' . (isset($this->filter_registered) ? esc_html($this->filter_registered) : 'unknown') . '</p>';
            echo '<p>EP_VERSION: ' . (defined('EP_VERSION') ? esc_html(EP_VERSION) : 'undefined') . '</p>';
            if (isset($this->search_forced) && $this->search_forced) {
                echo '<p style="color: green; font-weight: bold;">✓ Search was forced to use ElasticPress</p>';
            }
            echo '<p><em>Try searching on the frontend to test!</em></p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>EP Instant Search DEBUG STEP 4:</strong> ElasticPress not found</p>';
            echo '</div>';
        }
    }
}

// Initialize plugin
new EP_Instant_Search();
