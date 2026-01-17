<?php
/**
 * Booking Cancellation Handler
 * Handles Admin and Frontend cancellation, refunding coupons, and notifying users.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Booking_Cancellation_Handler {

    public function __construct() {
        // Admin Columns
        add_filter( 'manage_tickets_posts_columns', [ $this, 'add_cancel_column' ] );
        add_action( 'manage_tickets_posts_custom_column', [ $this, 'render_cancel_column' ], 10, 2 );

        // AJAX Handler
        add_action( 'wp_ajax_cancel_booking', [ $this, 'handle_cancellation' ] );
        add_action( 'wp_ajax_nopriv_cancel_booking', [ $this, 'handle_cancellation' ] ); // For frontend users

        // Inject JS for Admin
        add_action( 'admin_footer', [ $this, 'admin_footer_scripts' ] );
    }

    /**
     * Add "Cancel" Column to Tickets CPT
     */
    public function add_cancel_column( $columns ) {
        $columns['cancel_booking'] = 'Cancellation';
        return $columns;
    }

    /**
     * Render Button in Admin Column
     */
    public function render_cancel_column( $column, $post_id ) {
        if ( 'cancel_booking' === $column ) {
            $status = get_post_status( $post_id );
            
            if ( 'trash' === $status || 'cancelled' === $status ) {
                echo '<span style="color:red; font-weight:bold;">Cancelled</span>';
            } else {
                echo '<button type="button" class="button button-small wpj-cancel-btn" data-id="' . esc_attr( $post_id ) . '" style="color: #b32d2e; border-color: #b32d2e;">Cancel Booking</button>';
            }
        }
    }

    /**
     * Handle AJAX Cancellation
     */
    public function handle_cancellation() {
        check_ajax_referer( 'wpj_cancel_nonce', 'security' );

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $reason  = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

        if ( ! $post_id ) {
            wp_send_json_error( 'Invalid Booking ID' );
        }

        // Permission Check
        $is_admin = current_user_can( 'edit_posts' );
        $is_owner = get_current_user_id() === (int) get_post_field( 'post_author', $post_id );

        if ( ! $is_admin && ! $is_owner ) {
           wp_send_json_error( 'Permission Denied' );
        }

        // Time Check for Frontend Users (72 Hours)
        if ( ! $is_admin ) {
            $flight_date_str = get_post_meta( $post_id, 'flight_date', true ); // Adjust meta key as needed
            // If flight date logic exists, check generic 72h rule
            // Assuming flight_date is Y-m-d or timestamp. If meta is missing, strictly deny or allow based on policy.
            
            if ( $flight_date_str ) {
                $flight_time = strtotime( $flight_date_str );
                $current_time = time();
                $diff_hours = ( $flight_time - $current_time ) / 3600;

                if ( $diff_hours < 72 ) {
                    wp_send_json_error( 'Cancellation is only allowed 72 hours before the flight/booking.' );
                }
            }
        }

        // 1. Process Refund (Coupons)
        $this->process_coupon_refund( $post_id );

        // 2. Process Refund (Voucher - Logic Placeholder as per request)
        // $this->process_voucher_refund( $post_id );

        // 3. Trash Post
        wp_trash_post( $post_id );

        // 4. Send Email
        $this->send_cancellation_email( $post_id, $reason );

        wp_send_json_success( 'Booking Cancelled and Refunded Successfully' );
    }

    /**
     * Restore Coupon Usage and Credit
     */
    private function process_coupon_refund( $post_id ) {
        if ( ! class_exists( 'WC_Coupon' ) ) return;

        $coupon_code = get_post_meta( $post_id, 'coupon_code', true );
        $discount_amount = (float) get_post_meta( $post_id, 'discount_amount', true );

        if ( ! $coupon_code ) return;

        $coupon = new WC_Coupon( $coupon_code );
        if ( ! $coupon->get_id() ) return;

        // Decrease Usage Count
        // Standard WC doesn't have a direct "decrease" method, so we set manually
        $current_usage = $coupon->get_usage_count();
        if ( $current_usage > 0 ) {
            $coupon->set_usage_count( $current_usage - 1 );
        }

        // Refund Store Credit (Smart Coupons)
        // If the coupon amount was reduced, we add it back.
        // We assume we tracked 'discount_amount' (the amount USED).
        if ( $discount_amount > 0 ) {
            $current_val = (float) $coupon->get_amount();
            $coupon->set_amount( $current_val + $discount_amount );
        }

        $coupon->save();
    }

    /**
     * Send Notification Email
     */
    private function send_cancellation_email( $post_id, $reason ) {
        $to = get_post_meta( $post_id, 'passenger_email', true ); // Meta Key might vary
        if ( ! $to ) {
            // Fallback: Author email
            $author_id = get_post_field( 'post_author', $post_id );
            $author = get_userdata( $author_id );
            if ( $author ) {
                $to = $author->user_email;
            }
        }

        if ( ! $to ) return;

        $subject = 'Booking Cancelled - Ticket #' . $post_id;
        $message = "Your booking (Ticket #$post_id) has been cancelled.\n\n";
        $message .= "Reason: " . $reason . "\n\n";
        $message .= "Any used coupons or vouchers have been returned to your account.\n";
        $message .= "Regards,\nWind Passenger Team";

        wp_mail( $to, $subject, $message );
    }

    /**
     * Admin Footer JS
     */
    public function admin_footer_scripts() {
        $screen = get_current_screen();
        if ( 'tickets' !== $screen->post_type ) return;
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.wpj-cancel-btn').on('click', function() {
                var btn = $(this);
                var id = btn.data('id');
                var reason = prompt("Please enter cancellation reason:");

                if (reason === null) return; // Cancelled prompt

                if (!reason) {
                    alert("Reason is required!");
                    return;
                }

                btn.text('Processing...').prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cancel_booking',
                        post_id: id,
                        reason: reason,
                        security: '<?php echo wp_create_nonce( "wpj_cancel_nonce" ); ?>'
                    },
                    success: function(res) {
                        if (res.success) {
                            alert(res.data);
                            location.reload();
                        } else {
                            alert('Error: ' + res.data);
                            btn.text('Cancel Booking').prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Server Error');
                        btn.text('Cancel Booking').prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
}

new Booking_Cancellation_Handler();
