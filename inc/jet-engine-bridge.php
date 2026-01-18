<?php
// ===========================
// bridge between WooCommerce and JetEngine
// ===========================
add_action('woocommerce_order_status_completed', function ($order_id) {

    $order = wc_get_order($order_id);
    if (!$order)
        return;

    $user_id = $order->get_user_id();

    foreach ($order->get_items() as $item) {

        $product_id = $item->get_product_id();

        /* ------------------------------------
         * STOP if voucher already exists
         * ------------------------------------ */
        $existing = get_posts([
            'post_type' => 'voucher',
            'meta_query' => [
                [
                    'key' => 'linked_order_id',
                    'value' => $order_id,
                ],
                [
                    'key' => 'linked_product_id',
                    'value' => $product_id,
                ],
            ],
            'posts_per_page' => 1,
        ]);

        if (!empty($existing)) {
            continue; // Voucher already created
        }

        /* ------------------------------------
         * Product Meta
         * ------------------------------------ */
        $passengers = get_post_meta($product_id, 'voucher_passengers', true);
        $valid_months = (int) get_post_meta($product_id, 'voucher_valid_months', true);
        $terms = wp_get_post_terms($product_id, 'flight-type');

        if (!$passengers || empty($terms) || is_wp_error($terms)) {
            continue;
        }

        $flight_type = $terms[0]->slug;
        $expiry = date('Y-m-d', strtotime("+{$valid_months} months"));

        /* ------------------------------------
         * Generate Voucher Code (Activation Code)
         * ------------------------------------ */
        $voucher_code = strtoupper(wp_generate_password(13, false, false));
        // Example: 9F4K7Q2MZXACF

        /* ------------------------------------
         * Create Voucher CPT
         * ------------------------------------ */
        $voucher_id = wp_insert_post([
            'post_type' => 'voucher',
            'post_status' => 'publish',
            'post_title' => 'Voucher #' . $voucher_code,
        ]);

        if (is_wp_error($voucher_id))
            continue;

        /* ------------------------------------
         * Save Meta
         * ------------------------------------ */
        update_post_meta($voucher_id, 'voucher_code', $voucher_code);
        update_post_meta($voucher_id, 'voucher_passengers', $passengers);
        update_post_meta($voucher_id, 'voucher_expiry_date', $expiry);
        update_post_meta($voucher_id, 'voucher_owner', $user_id);

        // Order linking (IMPORTANT)
        update_post_meta($voucher_id, 'linked_order_id', $order_id);
        update_post_meta($voucher_id, 'linked_product_id', $product_id);

        /* ------------------------------------
         * Taxonomies
         * ------------------------------------ */
        wp_set_object_terms($voucher_id, 'active', 'voucher-status');
        wp_set_object_terms($voucher_id, $flight_type, 'flight-type');
    }
});
