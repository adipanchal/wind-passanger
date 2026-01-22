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
    if (
        ! $voucher_id ||
        get_post_type( $voucher_id ) !== 'voucher' ||
        get_post_status( $voucher_id ) !== 'publish'
    ) {
        return;
    }

    /* -------------------------------------------------
     * CHECK VOUCHER OWNERSHIP
     * ------------------------------------------------- */
    $voucher_owner = (int) get_post_meta( $voucher_id, 'voucher_owner', true );

    if ( $voucher_owner !== $user_id ) {
        return; // voucher not owned by this user
    }

    /* ------------------------
     * Voucher Flight Type
     * ------------------------ */
    $flight_types = wp_get_post_terms( $voucher_id, 'flight-type', [
        'fields' => 'ids',
    ] );

    if ( empty( $flight_types ) || is_wp_error( $flight_types ) ) {
        return;
    }

    /* ------------------------
     * Passengers
     * ------------------------ */
    $passengers = (int) get_post_meta( $voucher_id, 'voucher_passengers', true );
    if ( $passengers <= 0 ) {
        return;
    }

    /* ------------------------
     * Expiry Date → TIMESTAMP (ROBUST)
     * ------------------------ */
    $expiry_raw = get_post_meta( $voucher_id, 'voucher_expiry_date', true );
    if ( ! $expiry_raw ) {
        return;
    }

    // Normalize expiry to timestamp (handles date or timestamp)
    if ( is_numeric( $expiry_raw ) ) {
        $expiry_ts = (int) $expiry_raw;
    } else {
        $expiry_ts = strtotime( $expiry_raw . ' 23:59:59' );
    }

    // Voucher expired
    if ( time() > $expiry_ts ) {
        return;
    }

    /* -------------------------------------------------
     * APPLY FILTERS (ALL CONDITIONS PASSED)
     * ------------------------------------------------- */
    set_query_var( 'voucher_flight_type', $flight_types );
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
