<?php
/**
 * Class Jet_Booking_Sync
 * 
 * Syncs the internal JetBooking status (from DB table) to the 'reservation_record' CPT meta.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Jet_Booking_Sync
{

    public function __construct()
    {
        // Hook into JetBooking status update
        // This fires when a booking status is updated in the DB
        add_action('jet-booking/db/update-booking', [$this, 'sync_status_to_cpt'], 10, 3);

        // Hook into JetFormBuilder to link Booking ID to CPT on submission
        add_action('jet-form-builder/form-handler/after-send', [$this, 'link_booking_on_form_submission'], 10, 2);
    }

    /**
     * Link CPT to Booking ID on Form Submission
     */
    /**
     * Link CPT to Booking ID on Form Submission
     * 
     * NEW ARCHITECTURE: Creates ONE post per booking group with repeater for all passengers
     */
    public function link_booking_on_form_submission($handler, $is_success)
    {
        global $wpdb;

        error_log('JetBookingSync: Form Handler Started');

        if (!$is_success) {
            error_log('JetBookingSync: Form submission failed, skipping');
            return;
        }
        // --- DATA FETCHING STRATEGY ---
        // START with $_POST as base (always available)
        $form_data = $_POST;
        
        // --- FETCH HANDLER DATA ---
        // 1. Try jet_fb_context() for booking_ids (Preferred for arrays)
        $handler_data = [];
        if ( function_exists('jet_fb_context') ) {
             $ctx_booking_ids = jet_fb_context()->get_value( 'booking_ids' );
             if ( ! empty( $ctx_booking_ids ) ) {
                 $handler_data['booking_ids'] = $ctx_booking_ids;
             }
        }
        
        // 2. Try Reflection to access protected request_data (Fallback)
        if ( empty($handler_data['booking_ids']) ) {
            try {
                $reflection = new ReflectionClass($handler);
                // Check response_data first (often has hook results)
                if ($reflection->hasProperty('response_data')) {
                    $prop = $reflection->getProperty('response_data');
                    $prop->setAccessible(true);
                    $response_data = $prop->getValue($handler);
                    if (isset($response_data['booking_ids'])) {
                        $handler_data = array_merge($handler_data, $response_data);
                    }
                }
            } catch (Exception $e) {
                // Sssh, it's okay
            }
        }

        // MERGE data
        if (!empty($handler_data)) {
            // Handler/Context data OVERWRITES $_POST because it contains computed fields
            $form_data = array_merge($form_data, $handler_data);
        }

        if (empty($form_data)) {
            error_log('JetBookingSync: CRITICAL - Form data is completely empty!');
            return;
        }

        // Get current WordPress user
        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            error_log('JetBookingSync: No logged-in user, cannot create reservation');
            return;
        }

        // Try to get flight date from form data
        $flight_date = null;
        if (isset($form_data['flight_date'])) {
            $flight_date = sanitize_text_field($form_data['flight_date']);
        }

        // Fallback: get from first booking if we can detect it
        $booking_id_single = false;
        if (function_exists('jet_fb_context')) {
            $booking_id_single = jet_fb_context()->get_value('booking_id');
        }

        if (!$flight_date && $booking_id_single) {
            // Query booking to get check_in_date
            $bookings_table = $wpdb->prefix . 'jet_apartment_bookings';
            $booking_data = $wpdb->get_row($wpdb->prepare(
                "SELECT check_in_date, apartment_id FROM $bookings_table WHERE booking_id = %d",
                $booking_id_single
            ));
            if ($booking_data) {
                $flight_date = $booking_data->check_in_date;
            }
        }

        if (!$flight_date) {
            error_log('JetBookingSync: Cannot determine flight_date, skipping');
            return;
        }

        // Get booking IDs from form submission data (JetBooking provides this!)
        $booking_id_array = [];

        // Priority 1: Check request_data['booking_ids'] (Array, includes ALL bookings)
        // Ensure we handle array data correctly
        if (isset($form_data['booking_ids']) && is_array($form_data['booking_ids'])) {
            $booking_id_array = array_map('intval', $form_data['booking_ids']);
            error_log('JetBookingSync: Found booking_ids in request_data (Priority 1): ' . implode(', ', $booking_id_array));
        }

        // Priority 2: Check context for single booking_id (Fallback only if array missing)
        if (empty($booking_id_array) && function_exists('jet_fb_context')) {
            $single_id = jet_fb_context()->get_value('booking_id');
            if ($single_id) {
                $booking_id_array = [intval($single_id)];
                error_log('JetBookingSync: Found single booking_id in context (Priority 2): ' . $single_id);
            }
        }

        // Priority 3: Check request_data for single booking_id (Last resort)
        if (empty($booking_id_array) && isset($form_data['booking_id'])) {
            $booking_id_array = [intval($form_data['booking_id'])];
            error_log('JetBookingSync: Found single booking_id in request_data (Priority 3): ' . $form_data['booking_id']);
        }

        if (empty($booking_id_array)) {
            error_log('JetBookingSync: No booking IDs found in form submission');
            return;
        }

        // Now query DB to get full details for each booking
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'jet_apartment_bookings';

        $placeholders = implode(',', array_fill(0, count($booking_id_array), '%d'));
        $query = "SELECT booking_id, apartment_id, apartment_unit, user_email, user_id, status 
                  FROM $bookings_table 
                  WHERE booking_id IN ($placeholders)
                  ORDER BY booking_id ASC";

        $booking_ids = $wpdb->get_results($wpdb->prepare($query, ...$booking_id_array));

        if (empty($booking_ids)) {
            error_log('JetBookingSync: Could not fetch booking details from DB for IDs: ' . implode(', ', $booking_id_array));
            return;
        }

        $booking_count = count($booking_ids);
        error_log("JetBookingSync: Found $booking_count booking(s) for user $current_user_id");

        // Generate unique booking group ID
        $booking_group_id = md5($current_user_id . $flight_date . microtime());

        // Get flight/apartment details from first booking
        $first_booking = $booking_ids[0];
        $apartment_id = $first_booking->apartment_id;

        // Check if form already created a post via "Insert Post" action
        $post_id = false;

        // Method 1: Check Context (Most reliable)
        if (function_exists('jet_fb_context')) {
            $post_id = jet_fb_context()->get_value('inserted_post_id');
            if ($post_id) {
                error_log("JetBookingSync: Found Post ID via jet_fb_context: $post_id");
            }
        }

        // Method 2: Check Request Data
        if (!$post_id && isset($form_data['inserted_post_id'])) {
            $post_id = $form_data['inserted_post_id'];
            if ($post_id) {
                error_log("JetBookingSync: Found Post ID via request_data: $post_id");
            }
        }

        // Method 3: DB Fallback (Find recent post by this user)
        if (!$post_id) {
            $recent_posts = get_posts([
                'post_type' => 'reservation_record',
                'author' => $current_user_id,
                'posts_per_page' => 1,
                'date_query' => [
                    [
                        'after' => '10 seconds ago',
                    ],
                ],
                'orderby' => 'date',
                'order' => 'DESC',
                'fields' => 'ids',
            ]);

            if (!empty($recent_posts)) {
                $post_id = $recent_posts[0];
                error_log("JetBookingSync: Found Post ID via DB fallback (recent post): $post_id");
            }
        }

        // Fix Flight Date (handle timestamp vs Y-m-d)
        if (is_numeric($flight_date)) {
            $flight_date = date('Y-m-d', $flight_date);
        }

        if ($post_id) {
            // Form already created a post - UPDATE missing fields only
            error_log("JetBookingSync: Form created post $post_id, updating with grouped data");

            // Only update title if it looks incomplete (optional, or just skip title update entirely)
            // User request: "we need to target only this reservation_status check-in_status resv_booking_id resv_unit_id"

            // Note: reservation_status and check_in_status might be set by form or JetBooking internal logic.
            // We ensure our custom group fields are set.

        } else {
            // No post created by form - CREATE new one
            // This path should rarely happen if form has Insert Post action
            $post_title = sprintf(
                'Flight Booking - %d Passenger%s - %s',
                $booking_count,
                $booking_count > 1 ? 's' : '',
                $flight_date
            );

            $post_id = wp_insert_post([
                'post_type' => 'reservation_record',
                'post_title' => $post_title,
                'post_status' => 'publish',
                'post_author' => $current_user_id,
            ]);

            if (is_wp_error($post_id)) {
                error_log('JetBookingSync: Failed to create post - ' . $post_id->get_error_message());
                return;
            }

            error_log("JetBookingSync: Created new post $post_id");
        }

        // Store group-level metadata (ARRAYS and missing singles)
        update_post_meta($post_id, 'booking_group_id', $booking_group_id);
        update_post_meta($post_id, 'resv_flight_date', $flight_date); // Ensure normalized date format
        update_post_meta($post_id, 'apartment_id', $apartment_id);
        update_post_meta($post_id, 'resv_no_de_passageiros', $booking_count); // Override count with actual booking count

        // Store Coupon Code if used
        $coupon_code = '';
        if ( ! empty( $form_data['coupon_code'] ) ) {
            $coupon_code = sanitize_text_field( $form_data['coupon_code'] );
        } elseif ( ! empty( $form_data['form_voucher_code'] ) ) {
            $coupon_code = sanitize_text_field( $form_data['form_voucher_code'] );
        }

        if ( $coupon_code ) {
            update_post_meta( $post_id, '_used_coupon_code', $coupon_code );
            error_log( "JetBookingSync: Saved used coupon code '$coupon_code' to post $post_id" );
        }

        // Debug: Log first booking data
        $first_booking = $booking_ids[0];
        error_log('JetBookingSync: First Booking Data: ' . print_r($first_booking, true));

        // 1. Update IDs (Comma separated string for text field visibility)
        // User requested to see ALL IDs in the text field (e.g., "421, 422")
        $all_b_ids = array_map(function($b) { return $b->booking_id; }, $booking_ids);
        $all_u_ids = array_map(function($b) { return $b->apartment_unit; }, $booking_ids);
        
        $b_id_str = implode(', ', $all_b_ids);
        $u_id_str = implode(', ', $all_u_ids);
        
        update_post_meta( $post_id, 'resv_booking_id', $b_id_str );
        update_post_meta( $post_id, 'resv_unit_id', $u_id_str );

        // 2. Update Statuses (User Requested)
        // Map Booking Status directly
        $status = isset($first_booking->status) ? $first_booking->status : 'pending';
        update_post_meta($post_id, 'reservation_status', $status);

        // Set Check-in Status (Default to 'Pendente' if new, or logic if available)
        // Note: Key is 'check-in_status' as per user screenshot (typo included)
        // Set Check-in Status (Default to 'Pendente' as per user request)
        update_post_meta($post_id, 'check-in_status', 'Pendente');


        // --- VERIFICATION: Read back values ---
        $saved_booking_id = get_post_meta($post_id, 'resv_booking_id', true);
        $saved_unit_id = get_post_meta($post_id, 'resv_unit_id', true);
        $saved_status = get_post_meta($post_id, 'reservation_status', true);

        error_log("JetBookingSync: VERIFICATION -> Saved resv_booking_id: '$saved_booking_id' (Expected: '$b_id_str')");
        error_log("JetBookingSync: VERIFICATION -> Saved resv_unit_id: '$saved_unit_id' (Expected: '$u_id_str')");
        error_log("JetBookingSync: VERIFICATION -> Saved reservation_status: '$saved_status' (Expected: '$status')");

        // Store array of booking IDs
        $booking_id_array = array_map(function ($b) {
            return $b->booking_id; }, $booking_ids);
        update_post_meta($post_id, 'booking_ids', $booking_id_array);

        // Store array of unit IDs
        $unit_id_array = array_map(function ($b) {
            return $b->apartment_unit; }, $booking_ids);
        update_post_meta($post_id, 'resv_unit_ids', $unit_id_array);

        // Force update passenger count (Form might have set it to 1, but we know it's count($booking_ids))
        update_post_meta($post_id, 'resv_no_de_passageiros', $booking_count);

        // Build passenger repeater data from FORM DATA (not DB, because DB doesn't have custom fields yet)
        $passenger_repeater = [];
        $form_passengers = isset($form_data['passengers_details']) ? $form_data['passengers_details'] : [];

        // Debug form passenger data
        error_log('JetBookingSync: Form Passengers Data (Raw): ' . print_r($form_passengers, true));

        // Loop through booking IDs and try to map to passenger data
        // Assumption: The order of booking IDs matches the order of passengers in the repeater
        foreach ($booking_ids as $index => $booking_info) {

            // Handle different array structures (sometimes it's numeric index, sometimes associative?)
            $passenger_data = [];
            if (isset($form_passengers[$index])) {
                $passenger_data = $form_passengers[$index];
            } elseif (isset($form_passengers[0]) && $index === 0) {
                // Fallback if keys are weird
                $passenger_data = $form_passengers[0];
            }

            error_log("JetBookingSync: Processing Passenger $index: " . print_r($passenger_data, true));

            $repeater_row = [
                'resv_booking_id' => $booking_info->booking_id,
                'resv_unit_id' => $booking_info->apartment_unit,
                'resv_user_id' => $booking_info->user_id,

                // Map ALL fields from form data
                'resv_name' => isset($passenger_data['name']) ? $passenger_data['name'] : '',
                'resv_passenger_email' => isset($passenger_data['e_mail']) ? $passenger_data['e_mail'] : $booking_info->user_email,
                'resv_data_de_nascimento' => isset($passenger_data['data_de_nascimento']) ? $passenger_data['data_de_nascimento'] : '',
                'resv_telefone' => isset($passenger_data['telefone']) ? $passenger_data['telefone'] : '',
                'resv_gender' => isset($passenger_data['gender']) ? $passenger_data['gender'] : '',
                'resv_weight_kg' => isset($passenger_data['weight_kg']) ? $passenger_data['weight_kg'] : '',
                
                // Language & Communication Fields
                'resv_fala_ingles' => isset($passenger_data['fala_ingles']) ? $passenger_data['fala_ingles'] : '',
                'resv_idioma_preferencial' => isset($passenger_data['idioma_preferencial']) ? $passenger_data['idioma_preferencial'] : '',
                'resv_lingua_falada' => isset($passenger_data['lingua_falada']) ? $passenger_data['lingua_falada'] : '',
                
                // Health & Safety Fields
                'resv_tem_problemas_de_saude' => isset($passenger_data['tem_problemas_de_saude']) ? $passenger_data['tem_problemas_de_saude'] : '',
                'resv_qual_problema' => isset($passenger_data['qual_problema']) ? $passenger_data['qual_problema'] : '',
                'resv_sabe_nadar' => isset($passenger_data['sabe_nadar']) ? $passenger_data['sabe_nadar'] : '',
                
                // Additional Notes
                'resv_notas_adicionais' => isset($passenger_data['notas_adicionais']) ? $passenger_data['notas_adicionais'] : '',

                // Add unique ID for repeater row if needed (JetEngine sometimes likes this)
                '_id' => uniqid(),
            ];

            $passenger_repeater[] = $repeater_row;
        }

        // Save repeater data (JetEngine format)
        error_log("JetBookingSync: Final Repeater Array to Save: " . print_r($passenger_repeater, true));

        $result = update_post_meta($post_id, 'resv_passenger_repeater', $passenger_repeater);
        error_log("JetBookingSync: update_post_meta result: " . ($result ? 'Updated' : 'Not Updated (maybe same value)'));


        error_log("JetBookingSync: Successfully linked {$booking_count} bookings to post $post_id with " . count($passenger_repeater) . " passengers");
    }

    /**
     * Sync Status
     * 
     * @param int    $booking_id The ID from wp_jet_apartment_bookings
     * @param object $booking    The full booking object
     * @param array  $data       The data being updated (contains 'status')
     */
    public function sync_status_to_cpt($booking_id, $booking, $data)
    {

        // We only care if status is being changed
        if (!isset($data['status'])) {
            return;
        }

        $new_status = $data['status'];

        // Find the 'reservation_record' CPT post that holds this booking ID.
        // We check common meta keys including the user's 'resv_booking_id'
        $posts = get_posts([
            'post_type' => 'reservation_record',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'resv_booking_id', // User's specific key
                    'value' => $booking_id,
                ],
                [
                    'key' => 'booking_id',
                    'value' => $booking_id,
                ],
                [
                    'key' => '_booking_id',
                    'value' => $booking_id,
                ],
                [
                    'key' => 'jet_booking_id',
                    'value' => $booking_id,
                ]
            ],
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        if (empty($posts)) {
            return; // No linked CPT found
        }

        $cpt_id = $posts[0];

        // Update the CPT meta field 'reservation_status'
        update_post_meta($cpt_id, 'reservation_status', $new_status);

        // Also update 'resv_status' if that's the matching key pattern
        update_post_meta($cpt_id, 'resv_status', $new_status);
    }
}

new Jet_Booking_Sync();
