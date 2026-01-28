<?php
// ===========================
// Flight Filter based On Active Voucher
// ===========================
add_action( 'template_redirect', function () {
    /* -------------------------------------------------
     * USER MUST BE LOGGED IN
     * ------------------------------------------------- */
    if ( ! is_user_logged_in() ) {
        return; // no flights
    }

    /* -------------------------------------------------
     * FILTER LOGIC (ONLY WHEN voucher_id EXISTS)
     * ------------------------------------------------- */
    if ( empty( $_GET['voucher_id'] ) ) {
        return;
    }

    $voucher_id = absint( $_GET['voucher_id'] );
    $user_id    = get_current_user_id();

    /* -------------------------------------------------
     * VALIDATE VOUCHER (TYPE + STATUS)
     * ------------------------------------------------- */
    /* -------------------------------------------------
     * VALIDATE VOUCHER (TYPE + STATUS + OWNERSHIP)
     * ------------------------------------------------- */
    
    // 1. Basic Validity
    if ( ! $voucher_id || get_post_type( $voucher_id ) !== 'voucher' ) {
         wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
    }

    // 2. Ownership
    $voucher_owner = (int) get_post_meta( $voucher_id, 'voucher_owner', true );
    if ( $voucher_owner !== $user_id ) {
        wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
    }

    // 3. Status Check (MUST BE ACTIVE)
    $status = get_post_meta( $voucher_id, 'voucher_status', true );
    if ( 'active' !== $status ) {
        // Redirect if Transferred, Used, Pending, etc.
        wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
    }
    
    // 4. Booking Check (Already booked?)
    $booking_id = get_post_meta( $voucher_id, 'linked_reservation_id', true );
    if ( ! empty( $booking_id ) ) {
         wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
    }

    /* ------------------------
     * Voucher Flight Type
     * ------------------------ */
    $flight_types = wp_get_post_terms( $voucher_id, 'flight-type', [
        'fields' => 'ids',
    ] );

    if ( empty( $flight_types ) || is_wp_error( $flight_types ) ) {
        wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
    }
    /* ------------------------
     * Voucher Flight Location
     * ------------------------ */
    $flight_location = wp_get_post_terms( $voucher_id, 'flight-location', [
        'fields' => 'ids',
    ] );

    if ( empty( $flight_location ) || is_wp_error( $flight_location ) ) {
        wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
    }

    /* ------------------------
     * Passengers
     * ------------------------ */
    $passengers = (int) get_post_meta( $voucher_id, 'voucher_passengers', true );
    if ( $passengers <= 0 ) {
       wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
    }

    /* ------------------------
     * Expiry Date
     * ------------------------ */
    $expiry_raw = get_post_meta( $voucher_id, 'voucher_expiry_date', true );
    if ( ! $expiry_raw ) {
        // Decide policy: if no expiry set, allow? Or strict? 
        // Assuming allow if not set, but let's stick to strict validation if needed.
        // For now, if missing expiry, maybe just let pass or redirect.
        // Let's assume valid if missing for safety, unless requested otherwise.
    } else {
        // Normalize expiry to timestamp
        if ( is_numeric( $expiry_raw ) ) {
            $expiry_ts = (int) $expiry_raw;
        } else {
            $expiry_ts = strtotime( $expiry_raw . ' 23:59:59' );
        }

        // Voucher expired
        if ( time() > $expiry_ts ) {
            wp_redirect( home_url( '/a-minha-conta/' ) ); exit;
        }
    }

    /* -------------------------------------------------
     * APPLY FILTERS (ALL CONDITIONS PASSED)
     * ------------------------------------------------- */
    set_query_var( 'voucher_flight_type', $flight_types );
    set_query_var( 'voucher_flight_location', $flight_location);
    set_query_var( 'voucher_passengers', $passengers );
    set_query_var( 'voucher_expiry_ts', $expiry_ts );
});


// ===========================
// Add Body Class when Voucher is Active
// ===========================
add_filter('body_class', function ($classes) {
    if (!empty($_GET['voucher_id'])) {
        $classes[] = 'voucher-active';
    }
    return $classes;
});
