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
        /**
         * No automatic plan-expiration override.
         * Expiration is set explicitly when membership is granted from purchase
         * via wc_memberships_grant_membership_access_from_purchase.
         */
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
        // No-op (kept only for backward compatibility).
        return $expiration_date;
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

