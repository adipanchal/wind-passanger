<?php
// ===========================
// bridge between WooCommerce and JetEngine
// ===========================
function extract_first_number($value) {
    if (!$value) return '';
    preg_match('/\d+/', $value, $matches);
    return $matches[0] ?? '';
}

add_action('woocommerce_order_status_completed', function ($order_id) {

    $order = wc_get_order($order_id);
    if (!$order) return;

    $user_id = $order->get_user_id();

    foreach ($order->get_items() as $item) {

        $product_id = $item->get_product_id();

        /* ------------------------------------
         * STOP if voucher already exists
         * ------------------------------------ */
        $existing = get_posts([
            'post_type'  => 'voucher',
            'meta_query' => [
                [
                    'key'   => 'linked_order_id',
                    'value' => $order_id,
                ],
                [
                    'key'   => 'linked_product_id',
                    'value' => $product_id,
                ],
            ],
            'posts_per_page' => 1,
        ]);

        if (!empty($existing)) continue;

        /* ------------------------------------
         * READ ORDER ITEM META
         * ------------------------------------ */
        $raw_passengers = $item->get_meta('no-de-passageiros');
        $voucher_passengers = (int) extract_first_number($raw_passengers);
        $voucher_after_ballooning = $item->get_meta('extra-apres-ballooning');
        $voucher_gift_box         = $item->get_meta('pa_extra-gift-box');

        /* ------------------------------------
         * NEW: Flight Location (variation meta)
         * ------------------------------------ */
        $flight_location_raw = trim($item->get_meta('localizacao'));

        /* ------------------------------------
         * Flight Type (Optional now)
         * ------------------------------------ */
        $terms = wp_get_post_terms($product_id, 'flight-type');
        $flight_type = '';
        
        if ( ! empty($terms) && ! is_wp_error($terms) ) {
             $flight_type = $terms[0]->slug;
        } else {
            error_log( "VoucherGen: No flight-type found for Product $product_id. Proceeding anyway." );
        }

        /* ------------------------------------
         * Expiry
         * ------------------------------------ */
        $valid_months = (int) get_post_meta($product_id, 'voucher_valid_months', true);
        $expiry = $valid_months > 0
            ? strtotime("+{$valid_months} months", current_time('timestamp'))
            : 0;
        /* ------------------------------------
         * Voucher Code
         * ------------------------------------ */
        $voucher_code = strtoupper(wp_generate_password(13, false, false));

        /* ------------------------------------
         * Create Voucher
         * ------------------------------------ */
        $voucher_id = wp_insert_post([
            'post_type'   => 'voucher',
            'post_status' => 'publish',
            'post_title'  => 'Voucher #' . $voucher_code,
        ]);

        if (is_wp_error($voucher_id)) continue;

        /* ------------------------------------
         * Save Meta
         * ------------------------------------ */
        update_post_meta($voucher_id, 'voucher_code', $voucher_code);
        update_post_meta($voucher_id, 'voucher_passengers', $voucher_passengers);
        update_post_meta($voucher_id, 'voucher_after_ballooning', $voucher_after_ballooning);
        update_post_meta($voucher_id, 'voucher_gift_box', $voucher_gift_box);
        update_post_meta($voucher_id, 'voucher_accommodation_status', '');
        update_post_meta($voucher_id, 'voucher_expiry_date', $expiry);
        update_post_meta($voucher_id, 'voucher_owner', $user_id);
        update_post_meta($voucher_id, 'linked_order_id', $order_id);
        update_post_meta($voucher_id, 'linked_product_id', $product_id);

        // --- NEW: Populate Title & Category ---
        $product_obj = $item->get_product();
        if ( $product_obj ) {
            update_post_meta($voucher_id, 'voucher_title', $product_obj->get_name());
            
            $cats = wc_get_product_term_ids( $product_id, 'product_cat' );
            if ( ! empty($cats) ) {
                $term = get_term( $cats[0] ); 
                if ( $term && ! is_wp_error($term) ) {
                    update_post_meta($voucher_id, 'voucher_category', $term->name);
                }
            }
        }
        
        // --- NEW: System Fields ---
        update_post_meta($voucher_id, 'voucher_status', 'active');
        update_post_meta($voucher_id, 'original_buyer_id', $user_id);
        update_post_meta($voucher_id, 'current_owner_id', $user_id);

        wp_set_object_terms($voucher_id, 'active', 'voucher-status');
        if ( ! empty( $flight_type ) ) {
            wp_set_object_terms($voucher_id, $flight_type, 'flight-type');
        }
        error_log( "VoucherGen: Created Voucher $voucher_id for Order $order_id" );
        /* ------------------------------------
        * Flight Location Taxonomy Logic
        * ------------------------------------ */
        if (!empty($flight_location_raw)) {

            $location_name = sanitize_text_field($flight_location_raw);

            // 1️⃣ Try to find existing term by NAME
            $term = get_term_by('name', $location_name, 'flight-location');

            // 2️⃣ If not found, create new term
            if (!$term) {
                $term = wp_insert_term(
                    $location_name,
                    'flight-location',
                    [
                        'slug' => sanitize_title($location_name),
                    ]
                );
            }

            // 3️⃣ Assign term to voucher
            if (!is_wp_error($term)) {
                $term_id = is_object($term) ? $term->term_id : $term['term_id'];

                wp_set_object_terms(
                    $voucher_id,
                    (int) $term_id,
                    'flight-location',
                    false
                );
            }
        }
        /* ------------------------------------
        * Set Voucher Featured Image
        * ------------------------------------ */
        $product_obj = $item->get_product();

        if ( $product_obj ) {
            $thumbnail_id = $product_obj->get_image_id();

            if ( $thumbnail_id ) {
                set_post_thumbnail( $voucher_id, $thumbnail_id );
            }
        }
    }
});
