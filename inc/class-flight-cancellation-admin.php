<?php
/**
 * Admin Flight Cancellation System
 * Adds a "Cancel Flight" button to the Tickets admin list
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Flight_Cancellation_Admin {

    public function __construct() {
        // Add custom column to Tickets list table
        add_filter( 'manage_tickets_posts_columns', [ $this, 'add_cancel_column' ] );
        add_action( 'manage_tickets_posts_custom_column', [ $this, 'render_cancel_button' ], 10, 2 );
        
        // Handle the cancellation request
        add_action( 'admin_action_cancel_flight', [ $this, 'handle_flight_cancellation' ] );
        
        // Add admin notice for feedback
        add_action( 'admin_notices', [ $this, 'show_cancellation_notice' ] );
        
        // Add custom CSS for the button
        add_action( 'admin_head', [ $this, 'add_admin_styles' ] );
    }
    
    /**
     * Add custom CSS for the cancel button
     */
    public function add_admin_styles() {
        $screen = get_current_screen();
        
        // DEBUG: Log what screen we're on
        if ( $screen ) {
            error_log( "FlightCancellation: Current screen ID: " . $screen->id );
            error_log( "FlightCancellation: Current post_type: " . $screen->post_type );
        }
        
        if ( $screen && $screen->post_type === 'tickets' ) {
            echo '<style>
                .cancel-flight-btn {
                    background: #d63638;
                    color: #fff;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 3px;
                    cursor: pointer;
                    font-size: 13px;
                    text-decoration: none;
                    display: inline-block;
                }
                .cancel-flight-btn:hover {
                    background: #b52727;
                    color: #fff;
                }
                .column-cancel_flight {
                    width: 120px;
                    text-align: center;
                }
            </style>';
        }
    }

    /**
     * Add Cancel Flight column to admin list
     */
    public function add_cancel_column( $columns ) {
        // Insert the column after the last column
        $columns['cancel_flight'] = 'Actions';
        return $columns;
    }

    /**
     * Render the Cancel Flight button in the column
     */
    public function render_cancel_button( $column, $post_id ) {
        if ( $column === 'cancel_flight' ) {
            $cancel_url = wp_nonce_url(
                admin_url( 'admin.php?action=cancel_flight&flight_id=' . $post_id ),
                'cancel_flight_' . $post_id
            );
            
            echo sprintf(
                '<a href="%s" class="cancel-flight-btn" onclick="return confirm(\'⚠️ CANCEL ENTIRE FLIGHT?\\n\\nThis will cancel ALL bookings for this flight.\\n\\nThis action cannot be undone!\');">Cancel Flight</a>',
                esc_url( $cancel_url )
            );
        }
    }

    /**
     * Handle the flight cancellation
     */
    public function handle_flight_cancellation() {
        // Verify nonce and permissions
        if ( ! isset( $_GET['flight_id'] ) || ! isset( $_GET['_wpnonce'] ) ) {
            wp_die( 'Invalid request' );
        }
        
        $flight_id = (int) $_GET['flight_id'];
        
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'cancel_flight_' . $flight_id ) ) {
            wp_die( 'Security check failed' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action' );
        }
        
        error_log( "FlightCancellation: Admin initiated cancellation for Flight ID $flight_id" );
        
        // Get flight date from post meta
        $flight_date = get_post_meta( $flight_id, '_flight_date', true );
        if ( ! $flight_date ) {
            // Fallback: try other common meta keys
            $flight_date = get_post_meta( $flight_id, 'flight_date', true );
        }
        
        error_log( "FlightCancellation: Flight Date: $flight_date" );
        
        // Find all bookings for this flight (apartment_id + date)
        global $wpdb;
        $table_name = $wpdb->prefix . 'jet_apartment_bookings';
        
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id, user_email FROM $table_name 
             WHERE apartment_id = %d 
             AND status != 'cancelled'
             ORDER BY booking_id ASC",
            $flight_id
        ) );
        
        if ( empty( $bookings ) ) {
            // No active bookings
            $redirect_url = add_query_arg( 'flight_cancelled', 'no_bookings', wp_get_referer() );
            wp_safe_redirect( $redirect_url );
            exit;
        }
        
        $cancelled_count = 0;
        
        // Cancel each booking
        foreach ( $bookings as $booking ) {
            // Use JetBooking's DB class to update
            jet_abaf()->db->update_booking( $booking->booking_id, [
                'status' => 'cancelled'
            ] );
            
            $cancelled_count++;
            error_log( "FlightCancellation: Cancelled booking ID {$booking->booking_id} for {$booking->user_email}" );
            
            // Email will be sent here in future (Phase 6)
            // do_action( 'wind_passenger/flight_cancelled_email', $booking->booking_id, $flight_id );
        }
        
        error_log( "FlightCancellation: Successfully cancelled $cancelled_count bookings for Flight $flight_id" );
        
        // Update all affected Reservation Records with cancellation type = flight_cancelled
        // The group cancellation logic already set them to user_cancelled, we need to override
        $cancelled_ids = array_map( function($b) { return $b->booking_id; }, $bookings );
        
        $reservation_posts = get_posts([
            'post_type'  => 'reservation_record',
            'meta_query' => [
                [
                    'key'     => 'booking_ids',
                    'compare' => 'EXISTS'
                ]
            ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        
        foreach ( $reservation_posts as $post_id ) {
            $booking_ids = get_post_meta( $post_id, 'booking_ids', true );
            if ( is_array( $booking_ids ) && !empty( array_intersect( $booking_ids, $cancelled_ids ) ) ) {
                // This reservation record contains at least one cancelled booking from this flight
                update_post_meta( $post_id, 'resv_cancellation_type', 'flight_cancelled' );
                error_log( "FlightCancellation: Updated reservation record $post_id with flight_cancelled type" );
            }
        }
        
        // Redirect back with success message
        $redirect_url = add_query_arg( 
            [ 'flight_cancelled' => 'success', 'count' => $cancelled_count ], 
            wp_get_referer() 
        );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Show admin notice after cancellation
     */
    public function show_cancellation_notice() {
        if ( isset( $_GET['flight_cancelled'] ) ) {
            $status = $_GET['flight_cancelled'];
            
            if ( $status === 'success' ) {
                $count = isset( $_GET['count'] ) ? (int) $_GET['count'] : 0;
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>Flight Cancelled:</strong> Successfully cancelled ' . $count . ' booking(s).</p>';
                echo '</div>';
            } elseif ( $status === 'no_bookings' ) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>No Active Bookings:</strong> This flight has no active bookings to cancel.</p>';
                echo '</div>';
            }
        }
    }
}

new Flight_Cancellation_Admin();
