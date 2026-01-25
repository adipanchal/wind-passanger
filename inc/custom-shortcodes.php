<?php
/**
 * Custom Shortcodes for Wind Passenger
 * 
 * Includes UI logic for displaying reservation details in Elementor listings.
 * Strictly follows "Bilhete 1-5" logic scenarios.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ===========================
// HELPER: Get Request Context
// ===========================
function wind_get_ticket_context( $post_id ) {
    $flight_date = get_post_meta( $post_id, 'resv_flight_date', true );
    $status      = get_post_meta( $post_id, 'reservation_status', true );
    $cancel_type = get_post_meta( $post_id, 'resv_cancellation_type', true );
    
    $flight_ts = $flight_date ? strtotime( $flight_date ) : 0;
    // End of flight day (ensure we don't mark today as past until tomorrow)
    // Actually, usually "Upcoming" includes today.
    $now_ts    = current_time( 'timestamp' );
    
    // Normalize "Today" to start of day for accurate comparison if needed, 
    // but usually flight time matters. Assuming date only:
    $is_past = $flight_ts < strtotime( 'today', $now_ts );

    return [
        'is_past'     => $is_past,
        'status'      => $status,
        'cancel_type' => $cancel_type,
        'checkin'     => get_post_meta( $post_id, 'check-in_status', true ) ?: 'Pendente' 
    ];
}

// ===========================
// SHORTCODE 1: Flight Status Subtext (DATA VOO)
// "Voo a realizar" | "Voo realizado" | "Voo não realizado"
// ===========================
add_shortcode( 'wind_flight_status_label', 'wind_shortcode_flight_status_label' );

function wind_shortcode_flight_status_label() {
    $ctx = wind_get_ticket_context( get_the_ID() );

    // 1. Check Operational Cancellation (Priority)
    // If admin cancelled, the flight will NOT happen, regardless of date.
    if ( 'flight_cancelled' === $ctx['cancel_type'] ) {
        return '<span class="wind-flight-status-sub">Voo não realizado</span>';
    }

    // 2. SCENARIO: Future
    if ( ! $ctx['is_past'] ) {
         return '<span class="wind-flight-status-sub">Voo a realizar</span>';
    }

    // 3. Default Past (Taken or Customer Cancelled) -> "Voo realizado"
    return '<span class="wind-flight-status-sub">Voo realizado</span>';
}

// ===========================
// SHORTCODE 2: Reservation Status (ESTADO)
// "A usar" | "Usado" | "Cancelado pelo cliente" | "Cancelado operacional"
// ===========================
add_shortcode( 'wind_reservation_status', 'wind_shortcode_reservation_status' );

function wind_shortcode_reservation_status() {
    $ctx = wind_get_ticket_context( get_the_ID() );

    // 1. Check Cancellations FIRST
    if ( in_array( $ctx['status'], ['cancelled', 'refunded'] ) ) {
        if ( 'flight_cancelled' === $ctx['cancel_type'] ) {
            return 'Cancelado operacional';
        } elseif ( 'user_cancelled' === $ctx['cancel_type'] ) {
            return 'Cancelado pelo cliente';
        } else {
            // Fallback for legacy
            return 'Cancelado'; 
        }
    }

    // 2. Future Active -> "A usar"
    if ( ! $ctx['is_past'] ) {
        return 'A usar';
    }

    // 3. Past Active (Completed) -> "Usado"
    return 'Usado';
}



// ===========================
// SHORTCODE: Purchase Date (DATA COMPRA)
// ===========================
add_shortcode( 'wind_purchase_date', 'wind_shortcode_purchase_date' );

function wind_shortcode_purchase_date() {
    $post_date = get_the_date( 'j F Y' ); 
    return $post_date;
}
