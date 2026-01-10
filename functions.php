<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

// ===========================
// Continue shoping button cart page
// ===========================
add_action('woocommerce_cart_actions' , function() {
  ?>
  <a class="button wc-backward continue-shopping" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>"> <?php _e( 'Continuar A Comprar', 'woocommerce' ) ?> </a>
  <?php
});

// ===========================
// bridge between WooCommerce and JetEngine
// ===========================
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

        if (!empty($existing)) {
            continue; // Voucher already created
        }

        /* ------------------------------------
         * Product Meta
         * ------------------------------------ */
        $passengers    = get_post_meta($product_id, 'voucher_passengers', true);
        $valid_months  = (int) get_post_meta($product_id, 'voucher_valid_months', true);
        $terms         = wp_get_post_terms($product_id, 'flight-type');

        if (!$passengers || empty($terms) || is_wp_error($terms)) {
            continue;
        }

        $flight_type = $terms[0]->slug;
        $expiry      = date('Y-m-d', strtotime("+{$valid_months} months"));

        /* ------------------------------------
         * Generate Voucher Code (Activation Code)
         * ------------------------------------ */
        $voucher_code = strtoupper(wp_generate_password(13, false, false));
        // Example: 9F4K7Q2MZXACF

        /* ------------------------------------
         * Create Voucher CPT
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

// ===========================
// Fetch user Email id insted of user id Admin Voucher Column 
// ===========================
function jet_engine_custom_cb_user_email( $value, $args = [] ) {

    $post_id = 0;

    // Case 1: Admin Table passes object_id
    if ( ! empty( $args['object_id'] ) ) {
        $post_id = (int) $args['object_id'];
    }

    // Case 2: Admin Table passes row object
    if ( ! $post_id && ! empty( $args['row']['ID'] ) ) {
        $post_id = (int) $args['row']['ID'];
    }

    // Case 3: Fallback – value itself is post ID
    if ( ! $post_id && is_numeric( $value ) ) {
        $post_id = (int) $value;
    }

    if ( ! $post_id ) {
        return '';
    }

    // Get voucher owner
    $user_id = get_post_meta( $post_id, 'voucher_owner', true );

    if ( is_array( $user_id ) ) {
        $user_id = reset( $user_id );
    }

    if ( ! is_numeric( $user_id ) ) {
        return '';
    }

    $user = get_user_by( 'ID', (int) $user_id );

    return $user ? esc_html( $user->user_email ) : '';
}
// ===========================
// Flight Filter based On Active Voucher
// ===========================
add_action( 'template_redirect', function () {

    /* -------------------------------------------------
     * REDIRECT RULE
     * /schedule-flight/ → /flights/
     * ------------------------------------------------- */
    if (
        is_page( 'schedule-flight' ) &&
        empty( $_GET )
    ) {
        wp_safe_redirect( home_url( '/flights/' ), 302 );
        exit;
    }

    /* -------------------------------------------------
     * USER MUST BE LOGGED IN
     * ------------------------------------------------- */
    if ( ! is_user_logged_in() ) {
        return; // no flights
    }

    /* -------------------------------------------------
     * FILTER LOGIC (ONLY WHEN voucher_id EXISTS)
     * ------------------------------------------------- */
    if ( empty( $_GET['voucher_id'] ) ) {
        return;
    }

    $voucher_id = absint( $_GET['voucher_id'] );
    $user_id    = get_current_user_id();

    /* -------------------------------------------------
     * VALIDATE VOUCHER (TYPE + STATUS)
     * ------------------------------------------------- */
    if (
        ! $voucher_id ||
        get_post_type( $voucher_id ) !== 'voucher' ||
        get_post_status( $voucher_id ) !== 'publish'
    ) {
        return;
    }

    /* -------------------------------------------------
     * CHECK VOUCHER OWNERSHIP
     * ------------------------------------------------- */
    $voucher_owner = (int) get_post_meta( $voucher_id, 'voucher_owner', true );

    if ( $voucher_owner !== $user_id ) {
        return; // voucher not owned by this user
    }

    /* ------------------------
     * Voucher Flight Type
     * ------------------------ */
    $flight_types = wp_get_post_terms( $voucher_id, 'flight-type', [
        'fields' => 'ids',
    ] );

    if ( empty( $flight_types ) || is_wp_error( $flight_types ) ) {
        return;
    }

    /* ------------------------
     * Passengers
     * ------------------------ */
    $passengers = (int) get_post_meta( $voucher_id, 'voucher_passengers', true );
    if ( $passengers <= 0 ) {
        return;
    }

    /* ------------------------
     * Expiry Date → TIMESTAMP (ROBUST)
     * ------------------------ */
    $expiry_raw = get_post_meta( $voucher_id, 'voucher_expiry_date', true );
    if ( ! $expiry_raw ) {
        return;
    }

    // Normalize expiry to timestamp (handles date or timestamp)
    if ( is_numeric( $expiry_raw ) ) {
        $expiry_ts = (int) $expiry_raw;
    } else {
        $expiry_ts = strtotime( $expiry_raw . ' 23:59:59' );
    }

    // Voucher expired
    if ( time() > $expiry_ts ) {
        return;
    }

    /* -------------------------------------------------
     * APPLY FILTERS (ALL CONDITIONS PASSED)
     * ------------------------------------------------- */
    set_query_var( 'voucher_flight_type', $flight_types );
    set_query_var( 'voucher_passengers', $passengers );
    set_query_var( 'voucher_expiry_ts', $expiry_ts );
});

// ===========================
// Checkout Page Add login & Register Form
// ===========================
function checkout_login_popup_shortcode() {

    if ( is_user_logged_in() ) {
        return ''; // show nothing if logged in
    }

    ob_start();

    echo '<div id="checkout-login-required" class="checkout-login-popup">';

    wc_get_template( 'myaccount/form-login.php' );

    echo '</div>';

    return ob_get_clean();
}

add_shortcode( 'checkout_login_popup', 'checkout_login_popup_shortcode' );
// ===========================
// LIVE SEAT AVAILABILITY + OVERBOOKING PROTECTION
// ===========================
function wpj_get_live_available_seats( $flight_id ) {
    global $wpdb;

    $units_table    = $wpdb->prefix . 'jet_apartment_units';
    $bookings_table = $wpdb->prefix . 'jet_apartment_bookings';

    // Total units
    $total_units = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*)
         FROM $units_table
         WHERE apartment_id = %d",
        $flight_id
    ) );

    // Used units (pending + completed)
    $booked_units = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*)
         FROM $bookings_table
         WHERE apartment_id = %d
         AND status IN ('pending','completed')",
        $flight_id
    ) );

    return max( 0, $total_units - $booked_units );
}

/*-------------------------------------------------
	ALWAYS SYNC _jc_capacity FROM DB
------------------------------------------------- */
function wpj_sync_capacity_meta( $flight_id ) {
    update_post_meta(
        $flight_id,
        '_jc_capacity',
        wpj_get_live_available_seats( $flight_id )
    );
}

/*-------------------------------------------------
	AJAX ENDPOINT
------------------------------------------------- */
add_action( 'wp_ajax_wpj_live_seats', 'wpj_ajax_live_seats' );
add_action( 'wp_ajax_nopriv_wpj_live_seats', 'wpj_ajax_live_seats' );

function wpj_ajax_live_seats() {

    $flight_id = isset($_POST['flight_id']) ? absint($_POST['flight_id']) : 0;
    if ( ! $flight_id ) {
        wp_send_json_error();
    }

    $available = wpj_get_live_available_seats( $flight_id );

    // 🔑 SAFE: cache mirror
    update_post_meta( $flight_id, '_jc_capacity', $available );

    wp_send_json_success([
        'seats' => $available
    ]);
}



/*-------------------------------------------------
	FRONTEND AUTO-UPDATE (EVERY 5 SECONDS)
------------------------------------------------- */
add_action( 'wp_footer', function () {
?>
<script>
(function () {

    const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

    function updateAllSeats() {

        document.querySelectorAll('.jc-live-seats').forEach(el => {

            const flightId = el.getAttribute('data-flight-id');
            if (!flightId) return;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'wpj_live_seats',
                    flight_id: flightId
                })
            })
            .then(res => res.json())
            .then(res => {
                if (!res.success) return;

                el.textContent = res.data.seats;

                // OPTIONAL: update related quantity field inside same card
                const card = el.closest('.elementor-widget-container') || document;
                const qty  = card.querySelector('input[name="booking_units"]');

                if (qty) {
                    qty.max = res.data.seats;
                    if (parseInt(qty.value || 0) > res.data.seats) {
                        qty.value = res.data.seats;
                    }
                }
            });

        });
    }

    // Wait for Elementor + DOM
    function initWatcher() {
        updateAllSeats();
        setInterval(updateAllSeats, 5000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWatcher);
    } else {
        initWatcher();
    }

})();
</script>
<?php
});



/*-------------------------------------------------
	SHORTCODE OUTPUT
------------------------------------------------- */
add_shortcode( 'available_seats', function () {
    $id = get_the_ID();
    return '<span class="jc-live-seats" data-flight-id="' . esc_attr( $id ) . '">' 
         . wpj_get_live_available_seats( $id ) 
         . '</span>';
});
/*-------------------------------------------------
	SAFETY - prevents overbooking forever
------------------------------------------------- */
add_action( 'jet-booking/db/booking/before-create', function ( $data ) {

    if ( empty( $data['apartment_id'] ) ) return;

    $flight_id = (int) $data['apartment_id'];
    $requested = isset( $data['booking_units'] ) ? (int) $data['booking_units'] : 1;

    $available = wpj_get_live_available_seats( $flight_id );

    if ( $requested > $available ) {
        wp_die( 'Not enough seats available.' );
    }
});