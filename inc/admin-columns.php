<?php
// ===========================
// Fetch user Email id insted of user id Admin Voucher Column 
// ===========================
function jet_engine_custom_cb_user_email($value, $args = [])
{

    $post_id = 0;

    // Case 1: Admin Table passes object_id
    if (!empty($args['object_id'])) {
        $post_id = (int) $args['object_id'];
    }

    // Case 2: Admin Table passes row object
    if (!$post_id && !empty($args['row']['ID'])) {
        $post_id = (int) $args['row']['ID'];
    }

    // Case 3: Fallback – value itself is post ID
    if (!$post_id && is_numeric($value)) {
        $post_id = (int) $value;
    }

    if (!$post_id) {
        return '';
    }

    // Get voucher owner
    $user_id = get_post_meta($post_id, 'voucher_owner', true);

    if (is_array($user_id)) {
        $user_id = reset($user_id);
    }

    if (!is_numeric($user_id)) {
        return '';
    }

    $user = get_user_by('ID', (int) $user_id);

    return $user ? esc_html($user->user_email) : '';
}

// ===========================
// Callback: Term ID → Term Name (Admin Column)
// ===========================
add_action( 'admin_enqueue_scripts', function () {

    $screen = get_current_screen();
    
    // Only load on your CPT list page
    // Relaxed check: Allow on any post list page to ensure it loads for 'reservation-record' (or variations)
    // The JS has safety checks so it won't break other pages.
    if ( empty( $screen->post_type ) ) {
        return;
    }

    wp_enqueue_script(
        'jetengine-admin-tax-fix',
        get_stylesheet_directory_uri() . '/js/jetengine-admin-tax-fetch.js',
        [ 'jquery' ],
        '1.0',
        true
    );

    // Pass taxonomy data to JS
    wp_localize_script(
        'jetengine-admin-tax-fix',
        'JetTaxMap',
        [
            'flightType' => get_terms( [
                'taxonomy'   => 'flight-type',
                'hide_empty' => false,
            ] ),
            'location' => get_terms( [
                'taxonomy'   => 'flight-location',
                'hide_empty' => false,
            ] ),
        ]
    );
});
