<?php
/**
 * Check-in Activation Manager
 * 
 * 1. Manual Activation: Admin button in Tickets list.
 * 2. Auto Activation: Cron job (72h before flight).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checkin_Manager {

    public function __construct() {
        // 1. Admin Column for Manual Activation
        // Debug: Re-enable column to see debug data
        add_filter( 'manage_tickets_posts_columns', [ $this, 'add_checkin_column' ] );
        
        // We hook into the 'cancel_flight' column defined by class-flight-cancellation-admin.php
        // to render this button in the same "Actions" column.
        add_action( 'manage_tickets_posts_custom_column', [ $this, 'render_checkin_button' ], 20, 2 ); // Priority 20 to run AFTER cancel button
        
        add_action( 'admin_action_activate_checkin', [ $this, 'handle_manual_activation' ] );
        add_action( 'admin_action_deactivate_checkin', [ $this, 'handle_manual_deactivation' ] );
        
        add_action( 'admin_head', [ $this, 'add_admin_styles' ] );

        add_shortcode( 'wind_is_checkin_active', [ $this, 'is_checkin_active_shortcode' ] ); // Register Visibility Helper

        // 2. Cron Job for Auto Activation
        add_action( 'wind_hourly_checkin_activation', [ $this, 'auto_activate_checkin' ] );
        
        // Ensure cron is scheduled
        if ( ! wp_next_scheduled( 'wind_hourly_checkin_activation' ) ) {
            wp_schedule_event( time(), 'hourly', 'wind_hourly_checkin_activation' );
        }
        
        // Admin Notice
        add_action( 'admin_notices', [ $this, 'show_admin_notice' ] );
    }

    /**
     * Styles for the button
     */
    public function add_admin_styles() {
        $screen = get_current_screen();
        if ( $screen && $screen->post_type === 'tickets' ) {
            echo '<style>
                .verify-checkin-btn {
                    background: #2271b1;
                    color: #fff;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 3px;
                    cursor: pointer;
                    font-size: 13px;
                    text-decoration: none;
                    display: block;
                    margin-top: 5px; /* Spacing below cancel button */
                    width: 100%; /* Full width for stacking */
                    text-align: center;
                    box-sizing: border-box;
                }
                .verify-checkin-btn:hover {
                    background: #135e96;
                    color: #fff;
                }
                .deactivate-checkin-btn {
                    background: #46b450; /* Green as requested */
                    color: #fff;
                    border: 1px solid #46b450;
                    padding: 6px 12px;
                    border-radius: 3px;
                    width: 100%;
                    display: block;
                    text-align: center;
                    margin-top: 5px;
                    font-size: 13px;
                    text-decoration: none;
                    box-sizing: border-box;
                }
                .deactivate-checkin-btn:hover {
                    background: #3a9c42;
                    color: #fff;
                    border-color: #3a9c42; 
                }
                /* Remove checkin-active-label since we use the button itself as status */
            </style>';
        }
    }

    /**
     * Add Column (Deprecated - Merged into Actions)
     */
    public function add_checkin_column( $columns ) {
        return $columns;
    }

    /**
     * Render Button
     */
    public function render_checkin_button( $column, $post_id ) {
        if ( $column === 'cancel_flight' ) {
            
            // Check if already active (Handle String 'yes' or Array ['yes'] from JetEngine)
            $is_active = get_post_meta( $post_id, '_checkin_active', true );
            $active_flag = false;
            
            if ( 'yes' === $is_active ) {
                $active_flag = true;
            } elseif ( is_array( $is_active ) && in_array( 'yes', $is_active ) ) {
                $active_flag = true;
            }
            
            if ( $active_flag ) {
                // Show Deactivate Button (Green "Check-in Active") -> Click to Deactivate
                // User said "make deactivate button green". Assuming they mean the state "Active" is green.
                $url = wp_nonce_url(
                    admin_url( 'admin.php?action=deactivate_checkin&flight_id=' . $post_id ),
                    'deactivate_checkin_' . $post_id
                );
                
                echo sprintf(
                    '<a href="%s" class="deactivate-checkin-btn">Activated Check-in</a>',
                    esc_url( $url )
                );
            } else {
                // Show Activate Button (Blue)
                $url = wp_nonce_url(
                    admin_url( 'admin.php?action=activate_checkin&flight_id=' . $post_id ),
                    'activate_checkin_' . $post_id
                );
                
                echo sprintf(
                    '<a href="%s" class="verify-checkin-btn">Activate Check-in</a>',
                    esc_url( $url )
                );
            }
        }
    }

    /**
     * Handle Manual Activation
     */
    public function handle_manual_activation() {
        if ( ! isset( $_GET['flight_id'] ) || ! check_admin_referer( 'activate_checkin_' . $_GET['flight_id'] ) ) {
            wp_die( 'Security check failed.' );
        }
        
        $flight_id = (int) $_GET['flight_id'];
        
        error_log( "CheckinManager: Activating Flight $flight_id" );
        
        error_log( "CheckinManager: Activating Flight $flight_id" );
        
        // 1. Delete first
        delete_post_meta( $flight_id, '_checkin_active' );
        
        // 2. Update as ARRAY ['yes'] for Backend Checkbox UI
        update_post_meta( $flight_id, '_checkin_active', ['yes'] ); 
        
        // Clear cache
        clean_post_cache( $flight_id );
        
         $redirect = add_query_arg( ['checkin_status' => 'activated'], wp_get_referer() );
        wp_safe_redirect( $redirect );
    }

    /**
     * Handle Manual Deactivation
     */
    public function handle_manual_deactivation() {
        if ( ! isset( $_GET['flight_id'] ) || ! check_admin_referer( 'deactivate_checkin_' . $_GET['flight_id'] ) ) {
            wp_die( 'Security check failed.' );
        }
        
        $flight_id = (int) $_GET['flight_id'];
        
        error_log( "CheckinManager: Deactivating Flight $flight_id" );
        
        // Delete key
        delete_post_meta( $flight_id, '_checkin_active' ); 
        
        // Clear cache
        clean_post_cache( $flight_id );

        $redirect = add_query_arg( ['checkin_status' => 'deactivated'], wp_get_referer() );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Auto Activation (Cron)
     * Runs hourly. Checks flights starting within 72 hours.
     */
    public function auto_activate_checkin() {
        // Query Tickets happening in the next 72..73 hours?
        // Or just ALL tickets in future <= 72h? 
        // Better: Find tickets where Date is between NOW and NOW+72H.
        // AND exclude ones that are already active to save processing
        
        // NOTE: Meta query date format depends on how it's stored.
        // Usually timestamps or Y-m-d. Assuming timestamp from other files context.
        // Actually, let's query all PUBLISHED tickets and filter in PHP loop for safety if dataset isn't huge.
        // If dataset huge, need precise meta query.
        
        // Let's rely on meta_query for efficiency.
        $now = current_time( 'timestamp' );
        $limit = $now + ( 72 * 3600 ); // 72 Hours from now
        
        $args = [
            'post_type' => 'tickets',
            'posts_per_page' => -1, // Process all candidates
            'meta_query' => [
                'relation' => 'AND',
                [
                   'key' => '_flight_date', // Meta key used in other file
                   'value' => [ $now, $limit ],
                   'compare' => 'BETWEEN',
                   'type' => 'NUMERIC' 
                ],
                [
                    'key' => '_checkin_active',
                    'compare' => 'NOT EXISTS' // Only inactive ones
                ]
            ]
        ];
        
        $tickets = get_posts( $args );
        $total_activated = 0;
        
        foreach ( $tickets as $ticket ) {
            $total_activated += $this->activate_flight_bookings( $ticket->ID );
        }
        
        if ( $total_activated > 0 ) {
            error_log( "CheckinManager: Auto-activated $total_activated reservations." );
        }
    }

    /**
     * Core Logic: Activate check-in for a flight
     * Simply marks the Ticket as active. Default status 'Pendente' handles the rest.
     */
    private function activate_flight_bookings( $flight_id ) {
        
        // Mark Flight as Active (This is the checkbox meta user requested)
        update_post_meta( $flight_id, '_checkin_active', 'yes' );
        
        // We no longer iterate through bookings as default status is already 'Pendente'.
        // The activation concept is now purely about this Ticket flag which can drive Frontend visibility.
        
        return 1; // Return 1 to indicate success
    }

    /**
     * Admin Notice
     */
    public function show_admin_notice() {
        if ( isset( $_GET['checkin_activated'] ) ) {
            $count = (int) $_GET['count'];
            // Simplify notice since we no longer count reservations
            echo '<div class="notice notice-success is-dismissible">
                <p>Check-in functionality activated for this flight.</p>
            </div>';
        }
        if ( isset( $_GET['checkin_status'] ) && 'deactivated' === $_GET['checkin_status'] ) {
             echo '<div class="notice notice-info is-dismissible">
                <p>Check-in deactivated.</p>
            </div>';
        }
    }

    /**
     * Smart Shortcode for Visibility (Returns 1 or 0)
     * Handles both Ticket context and Reservation context (via parent lookup).
     * Usage: [wind_is_checkin_active]
     */
    public function is_checkin_active_shortcode() {
        $id = get_the_ID();
        $is_active = false;
        
        // 1. Check direct meta (Ticket Page)
        $val = get_post_meta( $id, '_checkin_active', true );
        if ( $this->is_meta_active($val) ) {
            $is_active = true;
        }
        
        // 2. If not, check if it's a Reservation -> Find Parent Flight
        if ( ! $is_active ) {
            $parent_id = get_post_meta( $id, 'apartment_id', true ); // JetBooking Logic
            if ( ! empty( $parent_id ) ) {
                 $p_val = get_post_meta( $parent_id, '_checkin_active', true );
                 if ( $this->is_meta_active($p_val) ) {
                    $is_active = true;
                 }
            }
        }
        
        return $is_active ? '1' : '0';
    }

    /**
     * Helper to check if meta value implies Active
     */
    private function is_meta_active( $val ) {
        // String 'yes' (Legacy/Simple)
        if ( 'yes' === $val ) return true;
        
        // Array ['yes'] (JetEngine Checkbox)
        if ( is_array($val) && in_array('yes', $val) ) return true;
        
        return false;
    }
}

new Checkin_Manager();
