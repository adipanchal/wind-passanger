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



require_once get_stylesheet_directory() . '/inc/class-flight-booking-engine.php';
require_once get_stylesheet_directory() . '/inc/class-voucher-form-filler.php';
require_once get_stylesheet_directory() . '/inc/Jetformbuilder-and-coupon-sync.php';
require_once get_stylesheet_directory() . '/inc/class-booking-cancellation.php';
require_once get_stylesheet_directory() . '/inc/woocommerce-cart.php';
require_once get_stylesheet_directory() . '/inc/jet-engine-bridge.php';
require_once get_stylesheet_directory() . '/inc/admin-columns.php';
require_once get_stylesheet_directory() . '/inc/flight-filters.php';
require_once get_stylesheet_directory() . '/inc/checkout-auth.php';

require_once get_stylesheet_directory() . '/inc/my-account-filter.php';
require_once get_stylesheet_directory() . '/inc/class-jet-booking-sync.php';

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0');

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles()
{

    wp_enqueue_style(
        'hello-elementor-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        [
            'hello-elementor-theme-style',
        ],
        HELLO_ELEMENTOR_CHILD_VERSION
    );

    // Dynamic styles for voucher mode & FOUC prevention
    wp_add_inline_style(
        'hello-elementor-child-style',
        'body.voucher-active .ticket-info-wrapper .pt-price { display: none !important; }
         .st-green, .st-yellow, .st-red { display: none; }'
    );
}
add_action('wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20);

// ===========================
// Js Syncing
// ===========================
function hello_child_enqueue_scripts()
{
    // 1. Flight Booking Engine (Localized)
    wp_enqueue_script(
        'hello-child-flight-booking',
        get_stylesheet_directory_uri() . '/js/flight-booking.js',
        array('jquery'), 
        filemtime(get_stylesheet_directory() . '/js/flight-booking.js'),
        true
    );

    // 2. Flight Status Indicators (Depends on booking engine for object)
    wp_enqueue_script(
        'hello-child-flight-status',
        get_stylesheet_directory_uri() . '/js/flight-status.js',
        array('jquery', 'hello-child-flight-booking'), 
        filemtime(get_stylesheet_directory() . '/js/flight-status.js'),
        true
    );

    // 4. Passenger Titles
    wp_enqueue_script(
        'hello-child-passenger-titles',
        get_stylesheet_directory_uri() . '/js/passenger-titles.js',
        array('jquery'), // No special deps needed
        filemtime(get_stylesheet_directory() . '/js/passenger-titles.js'),
        true
    );

    // 5. Price Formatter
    wp_enqueue_script(
        'hello-child-price-formatter',
        get_stylesheet_directory_uri() . '/js/price-formatter.js',
        array('jquery'), 
        filemtime(get_stylesheet_directory() . '/js/price-formatter.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'hello_child_enqueue_scripts');

/**
 * Custom Logout Button - Instant Logout
 */
// 1. Server-Side: Filter the logout URL globally
add_filter( 'logout_url', function( $logout_url, $redirect ) {
    if ( empty( $redirect ) ) {
        $logout_url = add_query_arg( 'redirect_to', home_url('/'), $logout_url );
    }
    return html_entity_decode( $logout_url );
}, 10, 2 );

// 2. Client-Side: Fallback script for hardcoded links
add_action('wp_footer', function () {
    if ( is_user_logged_in() ) : ?>
        <script>
        (function() {
            const logoutUrl = "<?php echo html_entity_decode( esc_url( wp_logout_url( home_url('/') ) ) ); ?>";

            document.addEventListener('click', function(e) {
                const target = e.target;
                const link = target.closest('a[href*="logout"]');
                const btn = target.closest('.logout-btn');
                
                if (link || btn) {
                   e.preventDefault();
                   e.stopPropagation();
                   console.log('Force Logout Redirect');
                   window.location.href = logoutUrl;
                }
            }, true); 
        })();
        </script>
    <?php endif;
}, 99);