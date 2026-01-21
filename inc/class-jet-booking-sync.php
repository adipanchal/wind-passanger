<?php
/**
 * Class Jet_Booking_Sync
 * 
 * Syncs the internal JetBooking status (from DB table) to the 'reservation_record' CPT meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Jet_Booking_Sync {

    public function __construct() {
        // Hook into JetBooking status update
        // This fires when a booking status is updated in the DB
        add_action( 'jet-booking/db/update-booking', [ $this, 'sync_status_to_cpt' ], 10, 3 );

        // Hook into JetFormBuilder to link Booking ID to CPT on submission
        add_action( 'jet-form-builder/form-handler/after-send', [ $this, 'link_booking_on_form_submission' ], 10, 2 );
    }

    /**
     * Link CPT to Booking ID on Form Submission
     */
    public function link_booking_on_form_submission( $handler, $actions ) {
        // 1. Try to find the Created Booking ID
        // JetBooking stores it in the 'booking_id' context usually
        $booking_id = false;
        
        // Method A: Check Request/Context
        if ( ! empty( $handler->request_data['booking_id'] ) ) {
            $booking_id = $handler->request_data['booking_id'];
        } 
        // Method B: Check Action Results
        else {
            foreach ( $actions as $action ) {
                if ( 'apartment_booking' === $action->get_type() || 'appointment_booking' === $action->get_type() ) {
                    // Start 3.0+ stores result differently, but often it's in the context
                    $booking_id = jet_fb_context()->get_value( 'booking_id' );
                    break;
                }
            }
        }

        // 2. Find the Inserted Post ID
        $post_id = false;
        foreach ( $actions as $action ) {
            if ( 'insert_post' === $action->get_type() || 'update_post' === $action->get_type() ) {
                
                // Usually stored in dynamic ID property after run
                if ( method_exists( $action, 'get_dynamic_id' ) ) {
                    $post_id = $action->get_dynamic_id();
                }
                
                // Fallback: Check if it's in the args
                if ( ! $post_id && isset( $handler->request_data['inserted_post_id'] ) ) {
                    $post_id = $handler->request_data['inserted_post_id'];
                }
                break;
            }
        }

        // 3. Link them if both found
        if ( $booking_id && $post_id ) {
            // Save the Booking ID into the CPT meta
            update_post_meta( $post_id, 'resv_booking_id', $booking_id );
            
            // Also save strict 'booking_id' just in case other plugins need it
            update_post_meta( $post_id, 'booking_id', $booking_id );
        }
    }

    /**
     * Sync Status
     * 
     * @param int    $booking_id The ID from wp_jet_apartment_bookings
     * @param object $booking    The full booking object
     * @param array  $data       The data being updated (contains 'status')
     */
    public function sync_status_to_cpt( $booking_id, $booking, $data ) {
        
        // We only care if status is being changed
        if ( ! isset( $data['status'] ) ) {
            return;
        }

        $new_status = $data['status'];

        // Find the 'reservation_record' CPT post that holds this booking ID.
        // We check common meta keys including the user's 'resv_booking_id'
        $posts = get_posts([
            'post_type'  => 'reservation_record',
            'meta_query' => [
                'relation' => 'OR',
                [
                     'key'   => 'resv_booking_id', // User's specific key
                     'value' => $booking_id,
                ],
                [
                    'key'   => 'booking_id',
                    'value' => $booking_id,
                ],
                [
                    'key'   => '_booking_id', 
                    'value' => $booking_id,
                ],
                [
                    'key'   => 'jet_booking_id', 
                    'value' => $booking_id,
                ]
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if ( empty( $posts ) ) {
            return; // No linked CPT found
        }

        $cpt_id = $posts[0];

        // Update the CPT meta field 'reservation_status'
        update_post_meta( $cpt_id, 'reservation_status', $new_status );
        
        // Also update 'resv_status' if that's the matching key pattern
        update_post_meta( $cpt_id, 'resv_status', $new_status );
    }
}

// new Jet_Booking_Sync();
