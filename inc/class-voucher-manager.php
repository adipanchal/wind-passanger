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
            
            // --- NEW: Try to find user by email and assign immediately ---
            $recipient_user = get_user_by( 'email', $receiver_email );
            if ( $recipient_user ) {
                update_post_meta( $new_voucher_id, 'voucher_owner', $recipient_user->ID );
                error_log( "VoucherMgr: Recipient user found (ID: {$recipient_user->ID}). Assigned Owner immediately." );
            } else {
                update_post_meta( $new_voucher_id, 'voucher_owner', 0 );
                error_log( "VoucherMgr: Recipient user not found for email '$receiver_email'. Owner set to 0." );
            }

            update_post_meta( $new_voucher_id, 'transferred_to_email', $receiver_email );
            update_post_meta( $new_voucher_id, 'gift_from_name', wp_get_current_user()->display_name );
            update_post_meta( $new_voucher_id, 'gift_message', $gift_message );
            update_post_meta( $new_voucher_id, 'parent_voucher_id', $voucher_id );
            
            // --- NEW: Copy Taxonomies (Flight Type) ---
            $flight_terms = wp_get_post_terms( $voucher_id, 'flight-type', [ 'fields' => 'ids' ] );
            if ( ! empty( $flight_terms ) && ! is_wp_error( $flight_terms ) ) {
                wp_set_post_terms( $new_voucher_id, $flight_terms, 'flight-type' );
            }

            // Set Taxonomy to Pending
            wp_set_object_terms( $new_voucher_id, 'pending', 'voucher-status' );
            
            // 4. Update Original (Sender)
            update_post_meta( $voucher_id, 'voucher_status', 'transferred' );
            wp_set_object_terms( $voucher_id, 'transferred', 'voucher-status' ); // Ensure taxonomy reflects this
            
            update_post_meta( $voucher_id, 'transferred_to_email', $receiver_email );
            update_post_meta( $voucher_id, 'clone_voucher_id', $new_voucher_id );
            
            // 5. Send Email
            // $code is already set above
            // Changed to use the specific Voucher Page URL (for dynamic templates)
            $activation_link = get_permalink( $new_voucher_id );
            
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
            

            // Activate & Assign (Logic Updated)
            // Even if owner was already assigned during transfer, we confirm it here.
            update_post_meta( $voucher_id, 'voucher_owner', $current_user->ID );
            update_post_meta( $voucher_id, 'voucher_status', 'active' );
            update_post_meta( $voucher_id, 'activation_date', current_time( 'mysql' ) );

            // Update Taxonomy: Remove 'pending' and add 'active'
            wp_remove_object_terms( $voucher_id, 'pending', 'voucher-status' );
            wp_set_object_terms( $voucher_id, 'active', 'voucher-status' );

        } catch ( Exception $e ) {
            error_log( 'VoucherActivate Error: ' . $e->getMessage() );
            // Use JetFormBuilder Exception to show error validation on the form
            if ( class_exists( '\Jet_Form_Builder\Exceptions\Action_Exception' ) ) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception( $e->getMessage() );
            }
            // Fallback
            throw $e;
        }
    }

    /**
     * Dashboard Shortcodes
     * Usage in JetListing:
     * [wind_voucher_status_badge]
     * [wind_booking_url] -> Returns URL string
     * [wind_transfer_button text="Transfer"] -> Returns Button HTML
     * [wind_booking_status_text] -> Returns booking status text
     * [wind_has_booking] -> Returns 1 or 0 for visibility
     * [wind_can_book] -> Returns 1 or 0 for visibility
     * [wind_booking_helper_text] -> Returns helper text below booking
     * [wind_validity_text] -> Returns validity status
     * [wind_purchase_date] -> Returns formatted purchase date
     */
    public function register_shortcodes() {
        add_shortcode( 'wind_voucher_status_badge', [ $this, 'render_status_badge' ] );
        add_shortcode( 'wind_booking_url', [ $this, 'render_booking_url' ] );
        add_shortcode( 'wind_transfer_button', [ $this, 'render_transfer_button' ] );
        
        // New Dashboard Shortcodes
        add_shortcode( 'wind_booking_status_text', [ $this, 'render_booking_status_text' ] );
        add_shortcode( 'wind_has_booking', [ $this, 'render_has_booking' ] );
        add_shortcode( 'wind_can_book', [ $this, 'render_can_book' ] );
        add_shortcode( 'wind_booking_helper_text', [ $this, 'render_booking_helper_text' ] );
        add_shortcode( 'wind_validity_text', [ $this, 'render_validity_text' ] );
        add_shortcode( 'wind_purchase_date', [ $this, 'render_purchase_date' ] );
        add_shortcode( 'wind_transfer_url', [ $this, 'render_transfer_url' ] );
        add_shortcode( 'wind_can_transfer', [ $this, 'render_can_transfer' ] );
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
                // User requested to show "Inativo" for transferred vouchers
                return '<span class="voucher-badge badge-inactive">Inativo</span>';
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
            'base_url'  => '/schedule-flight/',
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

    /* ========================================
     * NEW DASHBOARD SHORTCODES
     * ======================================== */

    /**
     * Helper: Check if voucher is expired
     */
    private function is_voucher_expired( $voucher_id ) {
        $expiry = get_post_meta( $voucher_id, 'voucher_expiry_date', true );
        if ( empty( $expiry ) ) {
            return false;
        }
        return $expiry < current_time( 'timestamp' );
    }

    /**
     * Helper: Format date in Portuguese
     */
    private function format_portuguese_date( $timestamp ) {
        if ( empty( $timestamp ) ) {
            return 'Sem dados';
        }
        
        $months = [
            'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'
        ];
        
        $day = date( 'j', $timestamp );
        $month = $months[ date( 'n', $timestamp ) - 1 ];
        $year = date( 'Y', $timestamp );
        
        return "$day $month $year";
    }

    /**
     * [wind_booking_status_text]
     * Returns: "Voo marcado" | "Voo por marcar" | "Sem marcação disponível"
     */
    public function render_booking_status_text() {
        $id = get_the_ID();
        $status = get_post_meta( $id, 'voucher_status', true );
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );

        if ( ! empty( $booking_id ) ) {
            // Check if this booking is actually active or cancelled
            $cancellation_info = $this->get_booking_cancellation_info( $booking_id );

            if ( $cancellation_info && in_array( $cancellation_info['status'], ['cancelled', 'refunded'] ) ) {
                if ( 'admin_cancelled' === $cancellation_info['type'] ) {
                    return 'Cancelado pela Administração';
                } elseif ( 'user_cancelled' === $cancellation_info['type'] ) {
                    return 'Cancelado pelo Cliente';
                }
                // Fallback if type is missing but status is cancelled
                return 'Reserva Cancelada'; 
            }

            return 'Voo marcado';
        }

        if ( 'active' === $status ) {
            return 'Voo por marcar';
        }

        return 'Sem marcação disponível';
    }

    /**
     * [wind_has_booking]
     * Returns: 1 if booking exists, 0 otherwise (for "Ver reserva" button visibility)
     */
    public function render_has_booking() {
        $id = get_the_ID();
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );
        return ! empty( $booking_id ) ? '1' : '0';
    }

    /**
     * [wind_can_book]
     * Returns: 1 if can book (active + no booking), 0 otherwise (for "Fazer marcação" button visibility)
     */
    public function render_can_book() {
        $id = get_the_ID();
        $status = trim( get_post_meta( $id, 'voucher_status', true ) );
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );

        // Explicitly exclude non-bookable statuses
        $excluded_statuses = [ 'transferred', 'used', 'pending_activation', 'expired' ];
        if ( in_array( $status, $excluded_statuses, true ) ) {
            return '0';
        }

        // Check if expired
        if ( $this->is_voucher_expired( $id ) ) {
            return '0';
        }

        // Must be active AND no booking exists
        if ( 'active' === $status && empty( $booking_id ) ) {
            return '1';
        }

        return '0';
    }

    /**
     * [wind_booking_helper_text]
     * Returns: Helper/subtitle text or empty string
     */
    public function render_booking_helper_text() {
        $id = get_the_ID();
        $status = get_post_meta( $id, 'voucher_status', true );
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );

        // No helper text if active or booked
        if ( 'active' === $status || ! empty( $booking_id ) ) {
            return '';
        }

        // Show specific helper text based on status
        switch ( $status ) {
            case 'used':
                return 'Voucher Usado';
            case 'transferred':
                return 'Voucher Transferido';
            default:
                // Check if expired
                if ( $this->is_voucher_expired( $id ) ) {
                    return 'Voucher Expirado';
                }
                return '';
        }
    }

    /**
     * [wind_validity_text]
     * Returns: "Válido" | "Usado" | "Expirado" | "Sem dados" | "Voucher Transferido"
     */
    public function render_validity_text() {
        $id = get_the_ID();
        $status = get_post_meta( $id, 'voucher_status', true );

        // Check status first
        if ( 'used' === $status ) {
            return 'Usado';
        }

        if ( 'transferred' === $status ) {
            return 'Transferido';
        }

        // Check expiry
        if ( $this->is_voucher_expired( $id ) ) {
            return 'Expirado';
        }

        // If active and not expired
        if ( 'active' === $status ) {
            return 'Válido';
        }

        return 'Sem dados';
    }

    /**
     * [wind_purchase_date]
     * Returns: Formatted purchase date in Portuguese
     */
    public function render_purchase_date() {
        $id = get_the_ID();
        $purchase_date = get_post_meta( $id, 'voucher_purchase_date', true );
        return $this->format_portuguese_date( $purchase_date );
    }

    /**
     * [wind_transfer_url]
     * Returns: URL for transfer page with voucher_id parameter
     */
    public function render_transfer_url( $atts ) {
        $atts = shortcode_atts( [
            'base_url' => '/transferir-voucher',
        ], $atts );

        $id = get_the_ID();
        $status = get_post_meta( $id, 'voucher_status', true );
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );
        $parent_id = get_post_meta( $id, 'parent_voucher_id', true );

        // Same logic as transfer button
        if ( 'active' === $status && empty( $booking_id ) && empty( $parent_id ) ) {
            return esc_url( add_query_arg( 'voucher_id', $id, $atts['base_url'] ) );
        }

        return '#'; // Inactive link
    }

    public function render_can_transfer() {
        $id = get_the_ID();
        $status = trim( get_post_meta( $id, 'voucher_status', true ) );
        $booking_id = get_post_meta( $id, 'linked_reservation_id', true );
        $parent_id = get_post_meta( $id, 'parent_voucher_id', true );

        // Explicitly exclude non-transferable statuses
        $excluded_statuses = [ 'transferred', 'used', 'pending_activation', 'expired' ];
        if ( in_array( $status, $excluded_statuses, true ) ) {
            return '0';
        }

        // Check if expired
        if ( $this->is_voucher_expired( $id ) ) {
            return '0';
        }

        if ( 'active' === $status && empty( $booking_id ) && empty( $parent_id ) ) {
            return '1';
        }
        return '0';
    }

    /**
     * Helper: Get Booking Cancellation Info
     */
    private function get_booking_cancellation_info( $booking_id ) {
        // Try to find the Reservation Record CPT linked to this booking
        // The booking_id is stored in 'booking_ids' meta, possibly serialized
        
        $posts = get_posts([
            'post_type'      => 'reservation_record',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'booking_ids',
                    'value'   => '"' . $booking_id . '"', // Serialized
                    'compare' => 'LIKE'
                ],
                [
                    'key'     => 'booking_ids',
                    'value'   => $booking_id, // Direct
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if ( empty( $posts ) ) {
            // Fallback: Try simple LIKE if the rigid checks fail
             $posts = get_posts([
                'post_type'      => 'reservation_record',
                'meta_query'     => [
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
        
        if ( empty( $posts ) ) {
            return null;
        }

        $post_id = $posts[0];
        
        return [
            'status' => get_post_meta( $post_id, 'reservation_status', true ),
            'type'   => get_post_meta( $post_id, 'resv_cancellation_type', true ),
        ];
    }
}

endif; // End class check

new Voucher_Manager();
