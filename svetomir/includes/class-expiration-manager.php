<?php

/**
 * Expiration Manager Class
 *
 * Handles the calendar year expiration logic for WooCommerce Memberships
 *
 * @package WCM_Dec31
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WCM_Dec31_Expiration_Manager
 */
class WCM_Dec31_Expiration_Manager {

    /**
     * Instance of this class
     *
     * @var WCM_Dec31_Expiration_Manager
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return WCM_Dec31_Expiration_Manager
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
        add_filter( 'wc_memberships_membership_plan_expiration_date', array( $this, 'override_expiration_date' ), 10, 3 );
    }

    /**
     * Override expiration date to Dec 31 of current calendar year
     *
     * @param string|null                            $expiration_date Previously calculated expiration date (ignored).
     * @param \WC_Memberships_User_Membership|mixed  $user_membership User membership object.
     * @param \WC_Memberships_Membership_Plan|mixed  $plan Membership plan object.
     * @return string Y-m-d H:i:s (WordPress local timezone)
     */
    public function override_expiration_date( $expiration_date, $user_membership, $plan ) {

        // Check if plugin is enabled
        $settings = get_option( 'wcm_dec31_settings', array() );
        if ( empty( $settings['enabled'] ) ) {
            return $expiration_date;
        }

        // Check if plan is excluded
        if ( $this->is_plan_excluded( $plan ) ) {
            return $expiration_date;
        }

        // Only apply to new memberships created after plugin activation
        // Check if membership was created before plugin activation
        $activation_time = get_option( 'wcm_dec31_activation_time' );
        if ( $activation_time && is_object( $user_membership ) && method_exists( $user_membership, 'get_start_date' ) ) {
            $start_date = $user_membership->get_start_date( 'timestamp' );
            if ( $start_date && $start_date < $activation_time ) {
                return $expiration_date;
            }
        }

        // Get current calendar year
        $tz = wp_timezone();
        $current_year = (int) current_time( 'Y' );

        // Create Dec 31, 23:59:59 for current year
        $dec31_local = new DateTimeImmutable( sprintf( '%04d-12-31 23:59:59', $current_year ), $tz );

        return $dec31_local->format( 'Y-m-d H:i:s' );
    }

    /**
     * Check if membership plan is excluded
     *
     * @param \WC_Memberships_Membership_Plan|mixed $plan Membership plan object.
     * @return bool
     */
    private function is_plan_excluded( $plan ) {
        if ( ! is_object( $plan ) || ! method_exists( $plan, 'get_id' ) ) {
            return false;
        }

        $settings = get_option( 'wcm_dec31_settings', array() );
        $excluded_plans = isset( $settings['excluded_plans'] ) ? $settings['excluded_plans'] : array();

        if ( empty( $excluded_plans ) || ! is_array( $excluded_plans ) ) {
            return false;
        }

        $plan_id = $plan->get_id();
        return in_array( $plan_id, array_map( 'intval', $excluded_plans ), true );
    }
}

