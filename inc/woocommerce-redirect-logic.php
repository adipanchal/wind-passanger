<?php
add_action('template_redirect', function () {

    // Logged-in users → allow access
    if (is_user_logged_in()) {
        return;
    }

    $request_uri = $_SERVER['REQUEST_URI'];

    /*
     * 1️⃣ Allow only parent My Account page
     */
    if (
        strpos($request_uri, '/a-minha-conta/') === 0 &&
        untrailingslashit($request_uri) !== '/a-minha-conta'
    ) {
        wp_safe_redirect(site_url('/a-minha-conta/'));
        exit;
    }

    /*
     * 2️⃣ Block Voucher CPT (archive + single)
     */
    if (
        is_post_type_archive('voucher') ||
        is_singular('voucher')
    ) {
        wp_safe_redirect(site_url('/a-minha-conta/'));
        exit;
    }

    /*
     * 3️⃣ Block Schedule Flight page (with or without query string)
     */
    if (
        strpos($request_uri, '/schedule-flight') === 0
    ) {
        wp_safe_redirect(site_url('/a-minha-conta/'));
        exit;
    }

});
