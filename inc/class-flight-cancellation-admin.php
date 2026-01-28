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
                    display: block;
                    width: 100%;
                    box-sizing: border-box;
                    text-align: center;
                }
                .cancel-flight-btn:hover {
                    background: #b52727;
                    color: #fff;
                }
                .column-cancel_flight {
                    width: 140px; /* Slightly wider to accommodate full width feel */
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
            
            // Updated JS to use Prompt
            echo sprintf(
                '<a href="#" class="cancel-flight-btn" onclick="
                    var reason = prompt(\'⚠️ CANCEL ENTIRE FLIGHT? This will cancel ALL bookings.\\n\\nPlease enter a cancellation reason:\');
                    if(reason === null) return false;
                    if(confirm(\'Are you sure you want to cancel this flight?\')) {
                        window.location.href = \'%s\' + \'&reason=\' + encodeURIComponent(reason);
                    }
                    return false;
                ">Cancel Flight</a>',
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
        
        $reason = isset( $_GET['reason'] ) ? sanitize_textarea_field( $_GET['reason'] ) : '';

        $cancelled_count = 0;
        
        // Cancel each booking
        foreach ( $bookings as $booking ) {
            
            // --- 1. Re-check Status (Concurrency Protection) ---
            // If this booking was part of a group processed in a previous iteration, it might already be cancelled.
            $current_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table_name WHERE booking_id = %d", $booking->booking_id ) );
            if ( 'cancelled' === $current_status || 'refunded' === $current_status ) {
                error_log( "FlightCancellation: Skipping Booking {$booking->booking_id} (Already Cancelled)" );
                continue;
            }

            // --- 2. Find CPT & Set Meta FIRST ---
            // We must set the cancellation type/reason BEFORE triggering the status update
            // so that the 'monitor_booking_updates' hook reads the correct data.
            $reservation_posts = get_posts([
                'post_type'  => 'reservation_record',
                'meta_query' => [
                    [
                        'key'     => 'booking_ids',
                        'value'   => $booking->booking_id,
                        'compare' => 'LIKE' 
                    ]
                ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);

            if ( ! empty( $reservation_posts ) ) {
                $cpt_id = $reservation_posts[0];
                
                // Set Meta: Type & Reason
                update_post_meta( $cpt_id, 'resv_cancellation_type', 'flight_cancelled' );
                update_post_meta( $cpt_id, '_admin_cancellation_reason', $reason );
                // Note: We don't set 'reservation_status' here, handle_group_cancellation will do it.
                
                error_log( "FlightCancellation: Pre-set meta for CPT $cpt_id" );
            }

            // --- 3. Update DB (Triggers Monitor -> Group Handler -> Email) ---
            jet_abaf()->db->update_booking( $booking->booking_id, [
                'status' => 'cancelled'
            ] );
            
            $cancelled_count++;
            error_log( "FlightCancellation: Cancelled booking ID {$booking->booking_id}" );

            // REMOVED: Manual do_action call. 
            // The jet_abaf update triggers 'monitor_booking_updates' which triggers 'handle_group_cancellation' 
            // which triggers 'wind_passenger/group_cancellation_email'.
        }
        
        error_log( "FlightCancellation: Successfully cancelled $cancelled_count bookings for Flight $flight_id" );
        
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
