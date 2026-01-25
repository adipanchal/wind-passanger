<?php
/**
 * Voucher Manager
 *
 * Handles logic for transferring vouchers between users and activating gifted vouchers.
 * Integrates with JetFormBuilder using Custom Actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Voucher_Manager' ) ) :

class Voucher_Manager {

    public function __construct() {
        // Register JetFormBuilder Actions
        add_action( 'jet-form-builder/custom-action/handle_voucher_transfer', [ $this, 'handle_transfer_action' ], 10, 2 );
        add_action( 'jet-form-builder/custom-action/handle_voucher_activation', [ $this, 'handle_activation_action' ], 10, 2 );
        
        // Redirect guests accessing activation page
        add_action( 'template_redirect', [ $this, 'redirect_guests_from_activation' ] );

        $this->register_shortcodes();
    }

    /**
     * Redirect Guests if they access /ativar-voucher directly
     */
    public function redirect_guests_from_activation() {
        // Adjust slug if needed. User mentioned "open url". Assuming page slug is 'ativar-voucher'
        if ( is_page( 'ativar-voucher' ) && ! is_user_logged_in() ) {
            wp_redirect( home_url() );
            exit;
        }
    }

    /**
     * Handle Transfer Voucher (Form ID: 5863)
     */
    public function handle_transfer_action( $settings, $form_handler ) {
        try {
            // 1. Get Form Data (Fix: Use $_REQUEST directly as Action_Handler doesn't have get_value)
            $voucher_id_raw = isset( $_REQUEST['voucher_id'] ) ? $_REQUEST['voucher_id'] : 0;
            $voucher_id     = absint( $voucher_id_raw );
            
            error_log( "VoucherMgr: Starting Transfer for Voucher ID: $voucher_id" );
            
            $receiver_email = isset( $_REQUEST['receiver_email'] ) ? sanitize_email( $_REQUEST['receiver_email'] ) : '';
            $receiver_name  = isset( $_REQUEST['receiver_name'] ) ? sanitize_text_field( $_REQUEST['receiver_name'] ) : '';
            $gift_message   = isset( $_REQUEST['gift_message'] ) ? sanitize_textarea_field( $_REQUEST['gift_message'] ) : '';
            
            // Security: Current User
            $current_user_id = get_current_user_id();
            if ( ! $current_user_id ) {
                throw new Exception( 'You must be logged in.' );
            }

            // 2. Validate Voucher Ownership & Status
            $owner_id = get_post_meta( $voucher_id, 'voucher_owner', true );
            // Handle array case
            if ( is_array( $owner_id ) ) { $owner_id = $owner_id[0]; }
            
            if ( (int) $owner_id !== $current_user_id && ! current_user_can( 'manage_options' ) ) {
                 throw new Exception( 'Security Error: You do not own this voucher.' );
            }

            $status = get_post_meta( $voucher_id, 'voucher_status', true );
            if ( 'active' !== $status ) {
                 throw new Exception( 'Only ACTIVE vouchers can be transferred.' );
            }
            
            // Check if booked
            $booking_id = get_post_meta( $voucher_id, 'linked_reservation_id', true );
            if ( ! empty( $booking_id ) ) {
                 throw new Exception( 'This voucher is linked to a booking and cannot be transferred.' );
            }

            // 3. Clone Process
            $original_post = get_post( $voucher_id );
            if ( ! $original_post ) {
                throw new Exception( "Voucher Post $voucher_id not found." );
            }
            
            $new_voucher_args = [
                'post_title'   => $original_post->post_title . ' (Gift)',
                'post_type'    => 'voucher',
                'post_status'  => 'publish',
                'post_content' => $original_post->post_content,
            ];
            
            $new_voucher_id = wp_insert_post( $new_voucher_args );
            
            if ( is_wp_error( $new_voucher_id ) ) {
                 throw new Exception( 'Failed to generate gift voucher.' );
            }

            // Copy Meta
            $meta_to_copy = [
                'voucher_category', 'voucher_passengers', 'voucher_origin',
                'voucher_expiry_date', 'voucher_gift_box', 'voucher_after_ballooning',
                'voucher_accommodation_status', 'original_buyer_id', 'voucher_code', 'voucher_title'
            ];
            
            foreach ( $meta_to_copy as $key ) {
                $val = get_post_meta( $voucher_id, $key, true );
                update_post_meta( $new_voucher_id, $key, $val );
            }
            
            // Fix: Ensure Code Exists
            $code = get_post_meta( $new_voucher_id, 'voucher_code', true );
            if ( empty( $code ) ) {
                $code = strtoupper( wp_generate_password( 13, false, false ) );
                update_post_meta( $new_voucher_id, 'voucher_code', $code );
            }
            
            // Set New Meta
            update_post_meta( $new_voucher_id, 'voucher_status', 'pending_activation' );
            update_post_meta( $new_voucher_id, 'voucher_owner', 0 ); 
            update_post_meta( $new_voucher_id, 'transferred_to_email', $receiver_email );
            update_post_meta( $new_voucher_id, 'gift_from_name', wp_get_current_user()->display_name );
            update_post_meta( $new_voucher_id, 'gift_message', $gift_message );
            update_post_meta( $new_voucher_id, 'parent_voucher_id', $voucher_id );
            
            // --- NEW: Copy Taxonomies (Flight Type) ---
            $flight_terms = wp_get_post_terms( $voucher_id, 'flight-type', [ 'fields' => 'ids' ] );
            if ( ! empty( $flight_terms ) && ! is_wp_error( $flight_terms ) ) {
                wp_set_post_terms( $new_voucher_id, $flight_terms, 'flight-type' );
            }

            // Set specific taxonomy status if needed (optional, assuming 'pending' exists or leave empty until active)
            // For now, we rely on Meta 'voucher_status' = pending_activation. 
            // If you have a 'Pending' term, we could set it:
            wp_set_object_terms( $new_voucher_id, 'pending', 'voucher-status' );
            
            // 4. Update Original
            update_post_meta( $voucher_id, 'voucher_status', 'transferred' );
            update_post_meta( $voucher_id, 'transferred_to_email', $receiver_email );
            update_post_meta( $voucher_id, 'clone_voucher_id', $new_voucher_id );
            
            // 5. Send Email
            // $code is already set above
            $activation_link = home_url( '/ativar-voucher?code=' . $code );
            
            $subject = "You received a Gift Voucher from " . wp_get_current_user()->display_name;
            $message = "Hello $receiver_name,\n\n";
            $message .= "You have received a special gift voucher!\n\n";
            if ( $gift_message ) {
                $message .= "Message: \"$gift_message\"\n\n";
            }
            $message .= "Click here to accept and activate your voucher:\n";
            $message .= $activation_link . "\n\n";
            $message .= "Or copy this code manually: " . $code . "\n\n";
            $message .= "Note: You must login with email $receiver_email to claim this voucher.";
            
            wp_mail( $receiver_email, $subject, $message );
            
        } catch ( Exception $e ) {
            error_log( 'VoucherTransfer Error: ' . $e->getMessage() );
            // Rethrow so JetFormBuilder handles it and shows message
            throw $e;
        }
    }

    /**
     * Handle Voucher Activation (Form ID: 5866)
     */
    public function handle_activation_action( $settings, $form_handler ) {
        try {
            $activation_code = isset( $_REQUEST['activation_code'] ) ? sanitize_text_field( $_REQUEST['activation_code'] ) : '';
            
            $current_user = wp_get_current_user();
            if ( ! $current_user->ID ) {
                 // Should be caught by redirect, but for API/AJAX safety:
                 throw new Exception( 'You must be logged in.' );
            }
            
            // Find Pending Voucher
            $args = [
                'post_type'  => 'voucher',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key'   => 'voucher_code',
                        'value' => $activation_code,
                        'compare' => '='
                    ],
                    [
                        'key'   => 'voucher_status',
                        'value' => 'pending_activation',
                        'compare' => '='
                    ]
                ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ];
            
            $query = new WP_Query( $args );
            
            if ( empty( $query->posts ) ) {
                 throw new Exception( 'Invalid or already activated voucher code.' );
            }
            
            $voucher_id = $query->posts[0];
            
            // Match Email (Security)
            $allowed_email = get_post_meta( $voucher_id, 'transferred_to_email', true );
            $current_email = $current_user->user_email;
            
            error_log( "VoucherSecurity: Claiming Voucher $voucher_id. Code: $activation_code" );
            error_log( "VoucherSecurity: Allowed: '$allowed_email' | Current: '$current_email'" );
            
            // Normalize emails
            if ( strtolower( trim( $allowed_email ) ) !== strtolower( trim( $current_email ) ) ) {
                 // STRICT SECURITY: No admin bypass. 
                 throw new Exception( "Access Denied. This voucher was sent to $allowed_email. You are logged in as $current_email." );
            }
            
            // Activate & Assign
            update_post_meta( $voucher_id, 'voucher_owner', $current_user->ID );
            update_post_meta( $voucher_id, 'voucher_status', 'active' );
            update_post_meta( $voucher_id, 'activation_date', current_time( 'mysql' ) );

            // Update Taxonomy
            wp_set_object_terms( $voucher_id, 'active', 'voucher-status' );

        } catch ( Exception $e ) {
            error_log( 'VoucherActivate Error: ' . $e->getMessage() );
            // SAFE FALLBACK: Avoid critical error by nicely dying
            wp_die( $e->getMessage(), 'Voucher Activation Error', [ 'response' => 403, 'back_link' => true ] );
        }
    }

    /**
     * Dashboard Shortcodes
     * Usage in JetListing:
     * [wind_voucher_status_badge]
     * [wind_booking_url] -> Returns URL string
     * [wind_transfer_button text="Transfer"] -> Returns Button HTML
     */
    public function register_shortcodes() {
        add_shortcode( 'wind_voucher_status_badge', [ $this, 'render_status_badge' ] );
        add_shortcode( 'wind_booking_url', [ $this, 'render_booking_url' ] );
        add_shortcode( 'wind_transfer_button', [ $this, 'render_transfer_button' ] );
        // Deprecating wind_voucher_actions or keeping as legacy? User asked for separate.
        // I will keep wind_voucher_actions but update it too just in case.
    }

    public function render_status_badge() {
        // ... (Keep existing logic)
        $id = get_the_ID();
        $status = get_post_meta( $id, 'voucher_status', true );
        
        switch ( $status ) {
            case 'active':
                return '<span class="voucher-badge badge-active">Ativo </span>';
            case 'used':
                return '<span class="voucher-badge badge-used">Usado</i></span>';
            case 'transferred':
                return '<span class="voucher-badge badge-transferred">Transferido</i></span>';
            case 'pending_activation':
                return '<span class="voucher-badge badge-pending">Pendente</i></span>';
            default:
                return '<span class="voucher-badge badge-inactive">Inativo</span>';
        }
    }

    /**
     * Returns ONLY the URL for "Fazer Marcação"
     */
    public function render_booking_url( $atts ) {
         $atts = shortcode_atts( [
            'base_url'  => '/schedule-flight',
        ], $atts );

        $id = get_the_ID();
        $status = get_post_meta( $id, 'voucher_status', true );
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );

        if ( 'active' === $status && empty( $booking_id ) ) {
            return esc_url( $atts['base_url'] . '?voucher_id=' . $id );
        }
        
        return '#'; // Or empty string? '#' is safe for href.
    }

    /**
     * Returns ONLY the Transfer Button
     * Checks: Active, Not Booked, Not a Gift (No Re-gifting)
     */
    public function render_transfer_button( $atts ) {
        $atts = shortcode_atts( [
            'transfer_url' => '/transferir-voucher',
            'text'         => 'Transferir',
            'class'        => 'btn-action btn-transfer'
        ], $atts );

        $id = get_the_ID();
        $status = get_post_meta( $id, 'voucher_status', true );
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );
        $parent_id  = get_post_meta( $id, 'parent_voucher_id', true );

        // Logic: 
        // 1. Must be Active
        // 2. Must NOT be booked
        // 3. Must NOT be a received gift (parent_id check) -> "only one time share"
        
        if ( 'active' === $status && empty( $booking_id ) && empty( $parent_id ) ) {
            return sprintf( 
                '<a href="%s" class="%s" title="Transferir Voucher">%s</a>', 
                esc_url( add_query_arg( 'voucher_id', $id, $atts['transfer_url'] ) ),
                esc_attr( $atts['class'] ),
                esc_html( $atts['text'] )
            );
        }

        return ''; // Logic to hide button
    }
}

endif; // End class check

new Voucher_Manager();
