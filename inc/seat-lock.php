<?php
defined('ABSPATH') || exit;

/* ============================================================
   TRUE REAL CAPACITY (STRICT, LIVE, LOCK SAFE)
   ============================================================ */
function wpj_real_capacity($flight_id)
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(u.ID) - COUNT(b.ID)
        FROM {$wpdb->prefix}jet_apartment_units u
        LEFT JOIN {$wpdb->prefix}jet_apartment_bookings b
          ON u.apartment_id = b.apartment_id
          AND b.status NOT IN ('cancelled','refunded','failed','trash')
        WHERE u.apartment_id = %d
    ", $flight_id));
}

/* ============================================================
   FORCE JETBOOKING TO STORE YOUR REAL CAPACITY
   ============================================================ */
add_filter('jet-booking/apartment/capacity', function ($capacity, $flight_id) {
    $real = wpj_real_capacity($flight_id);
    update_post_meta($flight_id, '_jc_capacity', $real);
    return $real;
}, 9999, 2);

/* ============================================================
   DB TRANSACTION LOCK (RACE CONDITION KILLER)
   ============================================================ */
add_action('jet-booking/db/booking/before-insert', function ($data) {
    global $wpdb;

    $flight = (int) $data['apartment_id'];
    $qty = max(1, (int) ($data['booking_units'] ?? 1));

    $wpdb->query("START TRANSACTION");

    $free = wpj_real_capacity($flight);

    if ($qty > $free) {
        $wpdb->query("ROLLBACK");
        wp_send_json_error(['message' => "Only $free seats left"], 409);
        exit;
    }
});

add_action('jet-booking/db/booking/after-insert', function () {
    global $wpdb;
    $wpdb->query("COMMIT");
}, 1);

/* ============================================================
   FORM-LEVEL LIVE VALIDATION (NO REDIRECT)
   ============================================================ */
add_action('jet-form-builder/custom-action/before-run', function ($action, $handler) {

    if ($action->get_id() !== 'apartment_booking')
        return;

    $flight = (int) ($handler->request_data['apartment_id'] ?? 0);
    $qty = max(1, (int) ($handler->request_data['booking_units'] ?? 1));

    $free = wpj_real_capacity($flight);

    if ($qty > $free) {
        $handler->add_response_error('booking_units', "Only $free seats left.");
        throw new \Jet_Form_Builder\Exceptions\Action_Exception('SOLD OUT');
    }
}, 1, 2);

/* ============================================================
   INSTANT FRONTEND MAX LIMIT (NO AJAX POLLING)
   ============================================================ */
add_filter('jet-form-builder/fields/number/max', function ($max, $field) {
    if ($field->name === 'booking_units') {
        return wpj_real_capacity(get_the_ID());
    }
    return $max;
}, 10, 2);

/* ============================================================
   SHORTCODE [available_seats]
   ============================================================ */
add_shortcode('available_seats', function () {
    return wpj_real_capacity(get_the_ID());
});