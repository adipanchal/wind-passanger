<?php
/**
 * Filter My Account Orders
 * 
 * Excludes orders that contain products from specific categories 
 * (e.g., Vouchers, Gift Cards) from the "My Orders" list.
 */

add_filter( 'woocommerce_my_account_my_orders_query', 'wpj_filter_my_account_orders', 10, 1 );

function wpj_filter_my_account_orders( $args ) {
    
    // 1. Define Categories to Hide (Slugs)
    $blocked_slugs = [
        'pack', 
        'balloon-gift-box', 
        'cheque-oferta', 
        'gift-card',
        'cheque-oferta-2',
        'gift-cards'
    ];

    global $wpdb;
    
    // 2. Prepare SQL for safety
    $blocked_slugs_sanitized = array_map('esc_sql', $blocked_slugs);
    $slugs_str = "'" . implode("','", $blocked_slugs_sanitized) . "'";

    // 3. Find Order IDs that contain products from these categories
    // We query the order_items -> itemmeta -> term_relationships -> terms tables.
    $exclude_ids = $wpdb->get_col("
        SELECT DISTINCT order_items.order_id
        FROM {$wpdb->prefix}woocommerce_order_items as order_items
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
        LEFT JOIN {$wpdb->term_relationships} as tr ON order_item_meta.meta_value = tr.object_id
        LEFT JOIN {$wpdb->term_taxonomy} as tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        LEFT JOIN {$wpdb->terms} as t ON tt.term_id = t.term_id
        WHERE order_items.order_item_type = 'line_item'
        AND order_item_meta.meta_key = '_product_id'
        AND tt.taxonomy = 'product_cat'
        AND (t.slug IN ($slugs_str) OR t.name IN ('Pack', 'Balloon Gift Box', 'Cheque Oferta', 'Gift Card'))
    ");

    // 4. Exclude these IDs from the text query
    if ( ! empty( $exclude_ids ) ) {
        if ( isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ) {
            $args['post__not_in'] = array_merge( $args['post__not_in'], $exclude_ids );
        } else {
            $args['post__not_in'] = $exclude_ids;
        }
    }

    return $args;
}
