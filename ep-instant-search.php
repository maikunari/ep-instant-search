<?php
/**
 * Plugin Name: ElasticPress Instant Search (DEBUG STEP 3)
 * Plugin URI: https://github.com/maikunari/ep-instant-search
 * Description: DEBUG STEP 3 - Testing filter registration with has_filter check
 * Version: 2.12.4-debug-step3
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
        // Just return the value - don't actually modify anything yet
        return $enabled;
    }

    /**
     * Legacy method: Force ElasticPress via pre_get_posts
     */
    public function force_ep_for_search_legacy($query) {
        // Don't do anything yet - just testing registration
    }

    /**
     * Show that plugin loaded successfully
     */
    public function debug_notice() {
        if (isset($this->ep_found) && $this->ep_found) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>EP Instant Search DEBUG STEP 3:</strong> Filter registered successfully!</p>';
            echo '<p>Method: ' . (isset($this->filter_registered) ? esc_html($this->filter_registered) : 'unknown') . '</p>';
            echo '<p>EP_VERSION: ' . (defined('EP_VERSION') ? esc_html(EP_VERSION) : 'undefined') . '</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>EP Instant Search DEBUG STEP 3:</strong> ElasticPress not found</p>';
            echo '</div>';
        }
    }
}

// Initialize plugin
new EP_Instant_Search();
