<?php
// ===========================
// Checkout Page Add login & Register Form
// ===========================
function checkout_login_popup_shortcode()
{

    if (is_user_logged_in()) {
        return ''; // show nothing if logged in
    }

    ob_start();

    echo '<div id="checkout-login-required" class="checkout-login-popup">';

    wc_get_template('myaccount/form-login.php');

    echo '</div>';

    return ob_get_clean();
}

add_shortcode('checkout_login_popup', 'checkout_login_popup_shortcode');
