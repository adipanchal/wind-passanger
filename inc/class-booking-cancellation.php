<?php
/**
 * Handles grouped booking cancellations.
 * When one booking is cancelled, find all related bookings in the same group and cancel them too.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

error_log('GroupCancellation: File Loaded Successfully');

class Booking_Cancellation {

    public function __construct() {
        // Hook into database queries to catch direct updates
        add_filter( 'query', [ $this, 'monitor_booking_updates' ] );
    }
    
    /**
     * Monitor raw SQL queries for booking status updates
     * This is necessary because JetBooking updates the database directly
     */
    public function monitor_booking_updates( $query ) {
        global $wpdb;
        
        // Only check UPDATE queries on the bookings table
        $table_name = $wpdb->prefix . 'jet_apartment_bookings';
        
        if ( strpos( $query, 'UPDATE' ) === 0 && strpos( $query, $table_name ) !== false ) {
            error_log( "GroupCancellation: Database UPDATE detected: " . $query );
            
            // Extract booking_id and status from the query
            // Pattern: UPDATE wp_jet_apartment_bookings SET `status` = 'cancelled' WHERE `booking_id` = '423'
            // Account for optional backticks and quotes
            if ( preg_match( "/`?status`?\s*=\s*['\"]?(cancelled|refunded)['\"]?/i", $query, $status_match ) &&
                 preg_match( "/`?booking_id`?\s*=\s*['\"]?(\d+)['\"]?/i", $query, $id_match ) ) {
                
                $booking_id = (int) $id_match[1];
                $new_status = $status_match[1];
                
                error_log( "GroupCancellation: Detected cancellation - Booking ID: $booking_id, Status: $new_status" );
                
                // Trigger the group cancellation logic
                $this->handle_group_cancellation( $booking_id, null, [ 'status' => $new_status ] );
            }
        }
        
        return $query;
    }

    /**
     * Handle cascading cancellation for grouped bookings
     * ... existing handle_group_cancellation code ...
     */
    public function handle_group_cancellation( $booking_id, $booking, $data ) {
        
        // --- 1. DEBUG: Log Everything ---
        error_log( "GroupCancellation: HOOK FIRED for Booking ID: $booking_id" );
        // error_log( "GroupCancellation: Incoming Data: " . print_r( $data, true ) );
        
        // 2. Check Loop Protection
        if ( wp_cache_get( 'processing_group_cancellation_' . $booking_id ) ) {
            error_log( "GroupCancellation: Skipping $booking_id (Already Processing)" );
            return;
        }

        // 3. Status Check
        $new_status = isset( $data['status'] ) ? $data['status'] : '';
        if ( ! in_array( $new_status, ['cancelled', 'refunded'] ) ) {
            error_log( "GroupCancellation: Status '$new_status' is not a cancellation. Ignoring." );
            return;
        }

        error_log( "GroupCancellation: Valid Cancellation detected. Searching for parent Reservation Record..." );

        // 4. Find the Reservation Record (CPT) for this booking
        // We look for the CPT that contains this booking_id in its 'booking_ids' array.
        // Since it's serialized, we use LIKE.
        $reservation_posts = get_posts([
            'post_type'  => 'reservation_record',
            'meta_query' => [
                [
                    'key'     => 'booking_ids',
                    'value'   => '"' . $booking_id . '"', // Search for likely serialized format "123"
                    'compare' => 'LIKE' 
                ]
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        
        // Fallback search (just the number)
        if ( empty( $reservation_posts ) ) {
            error_log("GroupCancellation: Search 1 (Serialized) failed. Trying simple LIKE search...");
            $reservation_posts = get_posts([
                'post_type'  => 'reservation_record',
                'meta_query' => [
                    [
                        'key'     => 'booking_ids',
                        'value'   => $booking_id,
                        'compare' => 'LIKE' 
                    ]
                ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
        }

        if ( empty( $reservation_posts ) ) {
            error_log( "GroupCancellation: FAILED. No parent reservation record found for booking $booking_id" );
            return;
        }

        $post_id = $reservation_posts[0];
        error_log( "GroupCancellation: SUCCESS. Found parent post $post_id. Retrieving group..." );

        // 5. Get all Booking IDs in this group
        $group_booking_ids = get_post_meta( $post_id, 'booking_ids', true );
        error_log( "GroupCancellation: Group IDs found: " . print_r($group_booking_ids, true) );
        
        if ( empty( $group_booking_ids ) || ! is_array( $group_booking_ids ) ) {
            error_log( "GroupCancellation: Invalid booking_ids meta for post $post_id" );
            return;
        }

        // Filter out the current one
        $siblings = array_diff( $group_booking_ids, [ $booking_id ] );

        if ( empty( $siblings ) ) {
            error_log( "GroupCancellation: No siblings to cancel. (Single booking group)" ); 
            // Still update CPT status though!
        } else {
            error_log( "GroupCancellation: Found siblings to cancel: " . implode( ', ', $siblings ) );
        }

        // 6. Cancel Siblings
        foreach ( $siblings as $sibling_id ) {
            
            // Mark as processing to prevent infinite loop when we update it
            wp_cache_set( 'processing_group_cancellation_' . $sibling_id, true );
            
            // Update JetBooking status directly in DB
            jet_abaf()->db->update_booking( $sibling_id, [
                'status' => $new_status 
            ] );
            
            error_log( "GroupCancellation: Auto-cancelled sibling booking $sibling_id" );
        }

        // 7. Update CPT Status to match
        update_post_meta( $post_id, 'reservation_status', $new_status );
        update_post_meta( $post_id, 'resv_cancellation_type', 'user_cancelled' ); // Track who cancelled
        error_log( "GroupCancellation: Updated parent post $post_id status to " . $new_status );
        error_log( "GroupCancellation: Set cancellation_type to 'user_cancelled'" );
        
        // 8. Trigger Group Cancellation Email
        do_action( 'wind_passenger/group_cancellation_email', $post_id, $group_booking_ids, $new_status );
    }
}

new Booking_Cancellation();
