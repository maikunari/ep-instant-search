<?php
/**
 * Plugin Name: ElasticPress Instant Search (DEBUG MINIMAL)
 * Plugin URI: https://github.com/maikunari/ep-instant-search
 * Description: MINIMAL VERSION FOR DEBUGGING - Only loads basic structure
 * Version: 2.12.2-debug
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
        // Minimal initialization - just add admin notice
        add_action('admin_notices', array($this, 'debug_notice'));
    }

    /**
     * Show that plugin loaded successfully
     */
    public function debug_notice() {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>EP Instant Search DEBUG:</strong> Minimal version loaded successfully!</p>';
        echo '</div>';
    }
}

// Initialize plugin
new EP_Instant_Search();
