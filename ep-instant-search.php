<?php
/**
 * Plugin Name: ElasticPress Instant Search (DEBUG STEP 2)
 * Plugin URI: https://github.com/maikunari/ep-instant-search
 * Description: DEBUG STEP 2 - Testing ElasticPress detection
 * Version: 2.12.3-debug-step2
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
    }

    /**
     * Show that plugin loaded successfully
     */
    public function debug_notice() {
        if (isset($this->ep_found) && $this->ep_found) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>EP Instant Search DEBUG STEP 2:</strong> ElasticPress check passed! EP_VERSION: ' . (defined('EP_VERSION') ? EP_VERSION : 'undefined') . '</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>EP Instant Search DEBUG STEP 2:</strong> ElasticPress not found</p>';
            echo '</div>';
        }
    }
}

// Initialize plugin
new EP_Instant_Search();
