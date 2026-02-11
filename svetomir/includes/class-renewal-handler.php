<?php

/**
 * Renewal Handler Class
 *
 * Handles membership renewals to update expiration to Dec 31 of renewal year
 *
 * @package WCM_Dec31
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WCM_Dec31_Renewal_Handler
 */
class WCM_Dec31_Renewal_Handler {

    /**
     * Instance of this class
     *
     * @var WCM_Dec31_Renewal_Handler
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return WCM_Dec31_Renewal_Handler
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
        add_action( 'wc_memberships_user_membership_renewed', array( $this, 'handle_renewal' ), 10, 2 );
        add_action( 'wc_memberships_user_membership_saved', array( $this, 'handle_membership_save' ), 10, 2 );
    }

    /**
     * Handle membership renewal
     *
     * @param \WC_Memberships_User_Membership $user_membership User membership object.
     * @param \WC_Memberships_Membership_Plan $plan Membership plan object.
     */
    public function handle_renewal( $user_membership, $plan ) {

        // Check if plugin is enabled
        $settings = get_option( 'wcm_dec31_settings', array() );
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Check if plan is excluded
        if ( $this->is_plan_excluded( $plan ) ) {
            return;
        }

        // Update expiration to Dec 31 of current year
        $this->update_expiration_to_dec31( $user_membership );
    }

    /**
     * Handle membership save (catches manual changes)
     *
     * @param \WC_Memberships_User_Membership $user_membership User membership object.
     * @param array                            $args Additional arguments.
     */
    public function handle_membership_save( $user_membership, $args ) {

        // Check if plugin is enabled
        $settings = get_option( 'wcm_dec31_settings', array() );
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Only process if this is a renewal (start date changed or status reactivated)
        if ( ! $this->is_renewal( $user_membership ) ) {
            return;
        }

        // Get plan
        $plan = $user_membership->get_plan();
        if ( ! $plan ) {
            return;
        }

        // Check if plan is excluded
        if ( $this->is_plan_excluded( $plan ) ) {
            return;
        }

        // Update expiration to Dec 31 of current year
        $this->update_expiration_to_dec31( $user_membership );
    }

    /**
     * Check if membership plan is excluded
     *
     * @param \WC_Memberships_Membership_Plan $plan Membership plan object.
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

    /**
     * Check if this is a renewal event
     *
     * @param \WC_Memberships_User_Membership $user_membership User membership object.
     * @return bool
     */
    private function is_renewal( $user_membership ) {
        // Check if status is being reactivated
        if ( method_exists( $user_membership, 'get_status' ) ) {
            $status = $user_membership->get_status();
            if ( 'active' === $status ) {
                // Check if expiration date exists and is in the past
                $expiration = $user_membership->get_end_date( 'timestamp' );
                if ( $expiration && $expiration < current_time( 'timestamp' ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Update membership expiration to Dec 31 of current calendar year
     *
     * @param \WC_Memberships_User_Membership $user_membership User membership object.
     */
    private function update_expiration_to_dec31( $user_membership ) {
        if ( ! is_object( $user_membership ) || ! method_exists( $user_membership, 'set_end_date' ) ) {
            return;
        }

        $tz = wp_timezone();
        $current_year = (int) current_time( 'Y' );
        $dec31_local = new DateTimeImmutable( sprintf( '%04d-12-31 23:59:59', $current_year ), $tz );

        // Temporarily remove our filter to avoid infinite loop
        remove_filter( 'wc_memberships_membership_plan_expiration_date', array( WCM_Dec31_Expiration_Manager::get_instance(), 'override_expiration_date' ), 10 );

        // Update expiration date
        $user_membership->set_end_date( $dec31_local->format( 'Y-m-d H:i:s' ) );

        // Re-add filter
        add_filter( 'wc_memberships_membership_plan_expiration_date', array( WCM_Dec31_Expiration_Manager::get_instance(), 'override_expiration_date' ), 10, 3 );
    }
}

