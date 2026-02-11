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
        add_action( 'wc_memberships_grant_membership_access_from_purchase', array( $this, 'handle_membership_granted_from_purchase' ), 10, 2 );
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
     * Handle membership grant from purchase.
     *
     * Hook signature:
     * wc_memberships_grant_membership_access_from_purchase( $membership_plan, $args )
     *
     * $args is expected to contain:
     * - user_membership_id
     * - user_id
     * - order_id
     * - product_id
     *
     * Sets end date to Dec 31 23:59:59 of SAME year as payment date (site timezone),
     * then passes Unix timestamp to set_end_date().
     *
     * @param \WC_Memberships_Membership_Plan|mixed $membership_plan Membership plan object.
     * @param array                                 $args            Grant context args.
     */
    public function handle_membership_granted_from_purchase( $membership_plan, $args ) {
        $settings = get_option( 'wcm_dec31_settings', array( 'enabled' => 1 ) );
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( ! is_array( $args ) ) {
            return;
        }

        $configured_product_ids = array();
        if ( isset( $settings['membership_product_ids'] ) && is_array( $settings['membership_product_ids'] ) ) {
            $configured_product_ids = array_map( 'absint', $settings['membership_product_ids'] );
        } elseif ( ! empty( $settings['membership_product_id'] ) ) {
            // Backward compatibility with old single-ID setting.
            $configured_product_ids = array( absint( $settings['membership_product_id'] ) );
        }
        $configured_product_ids = array_values( array_filter( array_unique( $configured_product_ids ) ) );

        if ( empty( $configured_product_ids ) ) {
            return;
        }

        $user_membership_id = isset( $args['user_membership_id'] ) ? absint( $args['user_membership_id'] ) : 0;
        $order_id           = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
        $product_id         = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0;
        $user_id            = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;

        if ( $user_membership_id <= 0 || $order_id <= 0 || $product_id <= 0 ) {
            return;
        }

        if ( ! in_array( $product_id, $configured_product_ids, true ) ) {
            return;
        }

        if ( ! function_exists( 'wc_memberships_get_user_membership' ) ) {
            return;
        }

        $membership = wc_memberships_get_user_membership( $user_membership_id );
        if ( ! $membership ) {
            return;
        }

        if ( $user_id > 0 && method_exists( $membership, 'get_user_id' ) && (int) $membership->get_user_id() !== $user_id ) {
            return;
        }

        $plan = $membership->get_plan();
        if ( $this->is_plan_excluded( $plan ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $payment_date = $order->get_date_paid();
        if ( ! $payment_date ) {
            $payment_date = $order->get_date_completed();
        }
        if ( ! $payment_date ) {
            $payment_date = $order->get_date_created();
        }
        if ( ! $payment_date ) {
            return;
        }

        $tz = wp_timezone();
        $payment_local = clone $payment_date;
        $payment_local->setTimezone( $tz );
        $payment_year = (int) $payment_local->date( 'Y' );

        $dec31_local = new DateTimeImmutable( sprintf( '%04d-12-31 23:59:59', $payment_year ), $tz );
        $expiry_timestamp = $dec31_local->getTimestamp();

        if ( method_exists( $membership, 'set_end_date' ) ) {
            $membership->set_end_date( $expiry_timestamp );
            if ( method_exists( $membership, 'save' ) ) {
                $membership->save();
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                sprintf(
                    '[WCM_Dec31] grant_from_purchase: order_id=%d user_id=%d product_id=%d expiry_ts=%d expiry_local=%s',
                    $order_id,
                    $user_id,
                    $product_id,
                    $expiry_timestamp,
                    $dec31_local->format( 'Y-m-d H:i:s T' )
                )
            );
        }
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

