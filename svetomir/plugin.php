<?php

/**
 * Plugin Name: WC Memberships - Expire on Dec 31
 * Description: Винаги задава дата на изтичане 31 декември 23:59:59 за текущата календарна година спрямо старта на членството (fallback: текущо време).
 * Version: 2.0.0
 * Author: Svetomir Slavov
 * License: GPLv2 or later
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'WCM_DEC31_VERSION', '2.0.0' );
define( 'WCM_DEC31_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCM_DEC31_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class
 */
class WCM_Dec31_Plugin {

    /**
     * Instance of this class
     *
     * @var WCM_Dec31_Plugin
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return WCM_Dec31_Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once WCM_DEC31_PLUGIN_DIR . 'includes/class-expiration-manager.php';
        require_once WCM_DEC31_PLUGIN_DIR . 'includes/class-renewal-handler.php';
        require_once WCM_DEC31_PLUGIN_DIR . 'includes/class-settings.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize expiration manager
        WCM_Dec31_Expiration_Manager::get_instance();

        // Initialize renewal handler
        WCM_Dec31_Renewal_Handler::get_instance();

        // Initialize settings page
        WCM_Dec31_Settings::get_instance();
    }
}

/**
 * Initialize plugin
 */
function wcm_dec31_init() {
    // Check if WooCommerce Memberships is active
    if ( class_exists( 'WC_Memberships' ) ) {
        WCM_Dec31_Plugin::get_instance();
    } else {
        add_action( 'admin_notices', 'wcm_dec31_missing_dependency_notice' );
    }
}

/**
 * Show notice if WooCommerce Memberships is not active
 */
function wcm_dec31_missing_dependency_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( 'WC Memberships - Expire on Dec 31 requires WooCommerce Memberships to be installed and active.', 'wcm-dec31' ); ?></p>
    </div>
    <?php
}

// Initialize plugin
add_action( 'plugins_loaded', 'wcm_dec31_init', 20 );
