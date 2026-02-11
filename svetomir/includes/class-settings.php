<?php

/**
 * Settings Class
 *
 * Handles admin settings page for the plugin
 *
 * @package WCM_Dec31
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WCM_Dec31_Settings
 */
class WCM_Dec31_Settings {

    /**
     * Instance of this class
     *
     * @var WCM_Dec31_Settings
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return WCM_Dec31_Settings
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
        add_action( 'admin_menu', array( $this, 'add_settings_page' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wcm_dec31_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_wcm_dec31_realign_memberships', array( $this, 'ajax_realign_memberships' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'set_activation_time' ) );
    }

    /**
     * Set plugin activation time on first run
     */
    public function set_activation_time() {
        if ( ! get_option( 'wcm_dec31_activation_time' ) ) {
            update_option( 'wcm_dec31_activation_time', current_time( 'timestamp' ) );
        }
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( 'wcm_dec31_settings', 'wcm_dec31_settings', array( $this, 'sanitize_settings' ) );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Settings input.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        $sanitized['enabled'] = isset( $input['enabled'] ) ? 1 : 0;
        $sanitized['membership_product_ids'] = array();

        // Preferred new field: comma-separated product IDs.
        if ( isset( $input['membership_product_ids'] ) ) {
            $raw_ids = is_array( $input['membership_product_ids'] )
                ? implode( ',', $input['membership_product_ids'] )
                : (string) $input['membership_product_ids'];

            $parts = preg_split( '/[\s,]+/', $raw_ids );
            if ( is_array( $parts ) ) {
                foreach ( $parts as $part ) {
                    if ( '' === trim( $part ) ) {
                        continue;
                    }
                    $id = absint( $part );
                    if ( $id > 0 ) {
                        $sanitized['membership_product_ids'][] = $id;
                    }
                }
            }
        }

        // Backward compatibility with old single-ID field.
        if ( empty( $sanitized['membership_product_ids'] ) && ! empty( $input['membership_product_id'] ) ) {
            $legacy_id = absint( $input['membership_product_id'] );
            if ( $legacy_id > 0 ) {
                $sanitized['membership_product_ids'][] = $legacy_id;
            }
        }
        $sanitized['membership_product_ids'] = array_values( array_unique( $sanitized['membership_product_ids'] ) );

        $sanitized['excluded_plans'] = isset( $input['excluded_plans'] ) && is_array( $input['excluded_plans'] )
            ? array_map( 'intval', $input['excluded_plans'] )
            : array();

        return $sanitized;
    }

    /**
     * Add settings page
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Calendar Year Expiry', 'wcm-dec31' ),
            __( 'Calendar Year Expiry', 'wcm-dec31' ),
            'manage_woocommerce',
            'wcm-dec31-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'woocommerce_page_wcm-dec31-settings' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wcm-dec31-admin',
            WCM_DEC31_PLUGIN_URL . 'assets/css/admin-styles.css',
            array(),
            WCM_DEC31_VERSION
        );

        wp_enqueue_script(
            'wcm-dec31-admin',
            WCM_DEC31_PLUGIN_URL . 'assets/js/admin-scripts.js',
            array( 'jquery' ),
            WCM_DEC31_VERSION,
            true
        );

        wp_localize_script(
            'wcm-dec31-admin',
            'wcmDec31',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wcm_dec31_nonce' ),
                'strings' => array(
                    'confirmRealign' => __( 'Are you sure you want to update all active memberships to expire on Dec 31, %s? This action cannot be undone.', 'wcm-dec31' ),
                    'processing'     => __( 'Processing...', 'wcm-dec31' ),
                    'success'        => __( 'Settings saved successfully.', 'wcm-dec31' ),
                    'error'          => __( 'An error occurred. Please try again.', 'wcm-dec31' ),
                    'realignSuccess' => __( 'Memberships updated successfully.', 'wcm-dec31' ),
                    'realignError'   => __( 'Failed to update memberships.', 'wcm-dec31' ),
                ),
            )
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wcm-dec31' ) );
        }

        $settings = get_option( 'wcm_dec31_settings', array(
            'enabled'              => 1,
            'membership_product_ids' => array(),
            'excluded_plans'       => array(),
        ) );

        // Backward compatibility on load: migrate legacy single ID into array in memory.
        if ( empty( $settings['membership_product_ids'] ) && ! empty( $settings['membership_product_id'] ) ) {
            $legacy_id = absint( $settings['membership_product_id'] );
            if ( $legacy_id > 0 ) {
                $settings['membership_product_ids'] = array( $legacy_id );
            }
        }

        $membership_product_ids = isset( $settings['membership_product_ids'] ) && is_array( $settings['membership_product_ids'] )
            ? array_values( array_filter( array_map( 'absint', $settings['membership_product_ids'] ) ) )
            : array();

        // If still empty, try loading legacy value directly from stored option.
        if ( empty( $membership_product_ids ) && ! empty( $settings['membership_product_id'] ) ) {
            $legacy_id = absint( $settings['membership_product_id'] );
            if ( $legacy_id > 0 ) {
                $membership_product_ids = array( $legacy_id );
            }
        }
        $membership_product_ids_csv = implode( ',', $membership_product_ids );

        // Get all membership plans
        $plans = $this->get_membership_plans();

        // Get current year-end date
        $tz = wp_timezone();
        $current_year = (int) current_time( 'Y' );
        $dec31_date = new DateTimeImmutable( sprintf( '%04d-12-31 23:59:59', $current_year ), $tz );

        // Get count of active memberships that would be affected
        $affected_count = $this->get_affected_memberships_count();
        ?>
        <div class="wrap wcm-dec31-settings">
            <h1><?php esc_html_e( 'Calendar Year Expiry Settings', 'wcm-dec31' ); ?></h1>

            <form id="wcm-dec31-settings-form" method="post" action="">
                <?php wp_nonce_field( 'wcm_dec31_settings', 'wcm_dec31_settings_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wcm_dec31_enabled">
                                <?php esc_html_e( 'Enable Calendar Year Expiry', 'wcm-dec31' ); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="wcm_dec31_enabled" name="wcm_dec31_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                <?php esc_html_e( 'Force all new memberships to expire on December 31 of the current calendar year', 'wcm-dec31' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wcm_dec31_membership_product_ids">
                                <?php esc_html_e( 'Membership Product IDs', 'wcm-dec31' ); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="wcm_dec31_membership_product_ids"
                                name="wcm_dec31_settings[membership_product_ids]"
                                value="<?php echo esc_attr( $membership_product_ids_csv ); ?>"
                                class="regular-text"
                                placeholder="123,456,789"
                            >
                            <p class="description">
                                <?php esc_html_e( 'Comma-separated WooCommerce Product IDs for paid membership fee products. Example: 123,456,789', 'wcm-dec31' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wcm_dec31_excluded_plans">
                                <?php esc_html_e( 'Excluded Plans', 'wcm-dec31' ); ?>
                            </label>
                        </th>
                        <td>
                            <select id="wcm_dec31_excluded_plans" name="wcm_dec31_settings[excluded_plans][]" multiple="multiple" style="min-width: 300px; height: 150px;">
                                <?php foreach ( $plans as $plan_id => $plan_name ) : ?>
                                    <option value="<?php echo esc_attr( $plan_id ); ?>" <?php selected( in_array( $plan_id, $settings['excluded_plans'], true ) ); ?>>
                                        <?php echo esc_html( $plan_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Select membership plans to exclude from calendar year expiry. Hold Ctrl/Cmd to select multiple plans.', 'wcm-dec31' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="wcm-dec31-save-settings">
                        <?php esc_html_e( 'Save Settings', 'wcm-dec31' ); ?>
                    </button>
                </p>
            </form>

            <div class="wcm-dec31-info-panel">
                <h2><?php esc_html_e( 'Information', 'wcm-dec31' ); ?></h2>
                <div class="info-content">
                    <p>
                        <strong><?php esc_html_e( 'How it works:', 'wcm-dec31' ); ?></strong>
                    </p>
                    <ul>
                        <li><?php esc_html_e( 'All new memberships created after plugin activation will expire on December 31, 23:59:59 of the current calendar year.', 'wcm-dec31' ); ?></li>
                        <li><?php esc_html_e( 'When a membership is renewed, the expiration date is automatically updated to December 31 of the renewal year.', 'wcm-dec31' ); ?></li>
                        <li><?php esc_html_e( 'Existing memberships created before plugin activation are not affected unless manually realigned.', 'wcm-dec31' ); ?></li>
                    </ul>
                    <p>
                        <strong><?php esc_html_e( 'Current Year-End Date:', 'wcm-dec31' ); ?></strong>
                        <?php echo esc_html( $dec31_date->format( 'Y-m-d H:i:s' ) ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Timezone:', 'wcm-dec31' ); ?></strong>
                        <?php echo esc_html( wp_timezone_string() ); ?>
                    </p>
                </div>
            </div>

            <div class="wcm-dec31-realignment-tool">
                <h2><?php esc_html_e( 'Manual Realignment Tool', 'wcm-dec31' ); ?></h2>
                <p>
                    <?php esc_html_e( 'Update all active memberships to expire on December 31 of the current calendar year.', 'wcm-dec31' ); ?>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Affected Memberships:', 'wcm-dec31' ); ?></strong>
                    <span id="wcm-dec31-affected-count"><?php echo esc_html( $affected_count ); ?></span>
                </p>
                <p>
                    <button type="button" class="button button-secondary" id="wcm-dec31-realign-button" data-year="<?php echo esc_attr( $current_year ); ?>">
                        <?php esc_html_e( 'Realign All Active Memberships', 'wcm-dec31' ); ?>
                    </button>
                </p>
                <div id="wcm-dec31-realign-progress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <p class="progress-text"></p>
                </div>
                <div id="wcm-dec31-messages"></div>
            </div>

        </div>
        <?php
    }

    /**
     * Get membership plans
     *
     * @return array
     */
    private function get_membership_plans() {
        if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
            return array();
        }

        $plans = wc_memberships_get_membership_plans();
        $plan_list = array();

        foreach ( $plans as $plan ) {
            $plan_list[ $plan->get_id() ] = $plan->get_name();
        }

        return $plan_list;
    }

    /**
     * Get count of affected memberships
     *
     * @return int
     */
    private function get_affected_memberships_count() {
        if ( ! class_exists( 'WC_Memberships_User_Membership' ) ) {
            return 0;
        }

        // Query all active memberships
        $args = array(
            'post_type'      => 'wc_user_membership',
            'post_status'    => 'wcm-active',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );

        $query = new WP_Query( $args );
        return $query->found_posts;
    }

    /**
     * AJAX save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'wcm_dec31_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wcm-dec31' ) ) );
        }

        if ( ! isset( $_POST['settings'] ) || ! is_array( $_POST['settings'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid data.', 'wcm-dec31' ) ) );
        }

        $settings = $this->sanitize_settings( $_POST['settings'] );
        update_option( 'wcm_dec31_settings', $settings );

        wp_send_json_success( array( 'message' => __( 'Settings saved successfully.', 'wcm-dec31' ) ) );
    }

    /**
     * AJAX realign memberships
     */
    public function ajax_realign_memberships() {
        check_ajax_referer( 'wcm_dec31_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wcm-dec31' ) ) );
        }

        if ( ! class_exists( 'WC_Memberships_User_Membership' ) ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce Memberships is not active.', 'wcm-dec31' ) ) );
        }

        // Get current year from request
        $year = isset( $_POST['year'] ) ? intval( $_POST['year'] ) : (int) current_time( 'Y' );

        // Query all active memberships
        $args = array(
            'post_type'      => 'wc_user_membership',
            'post_status'    => 'wcm-active',
            'posts_per_page' => -1,
        );

        $query = new WP_Query( $args );

        if ( empty( $query->posts ) ) {
            wp_send_json_success( array(
                'message' => __( 'No active memberships found.', 'wcm-dec31' ),
                'updated' => 0,
            ) );
        }

        $tz = wp_timezone();
        $dec31_date = new DateTimeImmutable( sprintf( '%04d-12-31 23:59:59', $year ), $tz );
        $expiration_date = $dec31_date->format( 'Y-m-d H:i:s' );

        $updated = 0;
        $settings = get_option( 'wcm_dec31_settings', array() );
        $excluded_plans = isset( $settings['excluded_plans'] ) ? $settings['excluded_plans'] : array();

        // Temporarily remove expiration filter
        remove_filter( 'wc_memberships_membership_plan_expiration_date', array( WCM_Dec31_Expiration_Manager::get_instance(), 'override_expiration_date' ), 10 );

        foreach ( $query->posts as $post_id ) {
            $membership = wc_memberships_get_user_membership( $post_id );
            if ( ! $membership ) {
                continue;
            }

            $plan = $membership->get_plan();
            if ( ! $plan ) {
                continue;
            }

            // Skip excluded plans
            $plan_id = $plan->get_id();
            if ( ! empty( $excluded_plans ) && in_array( $plan_id, $excluded_plans, true ) ) {
                continue;
            }

            // Update expiration date
            $membership->set_end_date( $expiration_date );
            $updated++;
        }

        // Re-add filter
        add_filter( 'wc_memberships_membership_plan_expiration_date', array( WCM_Dec31_Expiration_Manager::get_instance(), 'override_expiration_date' ), 10, 3 );

        wp_send_json_success( array(
            'message' => sprintf( _n( 'Updated %d membership.', 'Updated %d memberships.', $updated, 'wcm-dec31' ), $updated ),
            'updated' => $updated,
        ) );
    }
}

