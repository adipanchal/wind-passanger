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
