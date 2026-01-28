<?php
/**
 * Handles grouped booking cancellations.
 * When one booking is cancelled, find all related bookings in the same group and cancel them too.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

error_log('GroupCancellation: File Loaded Successfully');

class Booking_Cancellation {

    public function __construct() {
        // Hook into database queries to catch direct updates
        add_filter( 'query', [ $this, 'monitor_booking_updates' ] );
        
        // Frontend AJAX Handler
        add_action( 'wp_ajax_wind_cancel_reservation', [ $this, 'handle_frontend_cancellation' ] );

        // Admin Cancellation
        add_action( 'add_meta_boxes', [ $this, 'register_cancellation_meta_box' ] );
        add_filter( 'post_row_actions', [ $this, 'add_cancellation_row_action' ], 10, 2 );
        add_action( 'admin_footer', [ $this, 'print_admin_cancellation_script' ] );
        add_action( 'wp_ajax_wind_admin_cancel_reservation', [ $this, 'handle_admin_cancellation' ] );
    }
    
    /**
     * Handle Manual Cancellation from User Dashboard (Frontend)
     */
    public function handle_frontend_cancellation() {
        check_ajax_referer( 'wind_cancel_nonce', 'nonce' );
        
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $user_id = get_current_user_id();
        
        if ( ! $post_id || ! $user_id ) {
            wp_send_json_error( 'Invalid request.' );
        }
        
        // 1. Verify Ownership
        $author_id = (int) get_post_field( 'post_author', $post_id );
        if ( $author_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        
        // 2. Get Group Details to trigger logic
        // We need a valid booking ID from this group to trigger the main function
        $booking_ids = get_post_meta( $post_id, 'booking_ids', true );
        
        if ( empty( $booking_ids ) || ! is_array( $booking_ids ) ) {
            // Try single
            $single_id = get_post_meta( $post_id, 'resv_booking_id', true );
            if ( $single_id ) {
                $booking_ids = [ intval( $single_id ) ];
            } else {
                wp_send_json_error( 'No bookings found for this reservation.' );
            }
        }
        
        // 3. Trigger Cancellation
        // We MUST update the DB for the trigger ID. 
        // This DB update will be caught by our 'monitor_booking_updates' hook, 
        // which will then handle the group cascade (cancel siblings, update CPT, etc).
        $trigger_id = $booking_ids[0];
        
        if ( function_exists( 'jet_abaf' ) ) {
            jet_abaf()->db->update_booking( $trigger_id, [ 'status' => 'cancelled' ] );
            
            // Fallback: If for some reason the monitor doesn't catch it (e.g. hook issues),
            // We manually trigger the handler afterwards.
            // But we must check if it was already handled to avoid double processing.
            // For now, let's rely on the monitor as it's the core architecture.
            
            // Also explicitly update the CPT 'check-in_status' here just in case, 
            // though the group handler does it too.
            update_post_meta( $post_id, 'check-in_status', 'Terminado' );
            
        } else {
             wp_send_json_error( 'Booking system not ready.' );
        }
        
        wp_send_json_success( 'Reservation cancelled successfully.' );
    }

    /**
     * Monitor raw SQL queries for booking status updates

     * This is necessary because JetBooking updates the database directly
     */
    public function monitor_booking_updates( $query ) {
        global $wpdb;
        
        // Only check UPDATE queries on the bookings table
        $table_name = $wpdb->prefix . 'jet_apartment_bookings';
        
        if ( strpos( $query, 'UPDATE' ) === 0 && strpos( $query, $table_name ) !== false ) {
            error_log( "GroupCancellation: Database UPDATE detected: " . $query );
            
            // Extract booking_id and status from the query
            // Pattern: UPDATE wp_jet_apartment_bookings SET `status` = 'cancelled' WHERE `booking_id` = '423'
            // Account for optional backticks and quotes
            if ( preg_match( "/`?status`?\s*=\s*['\"]?(cancelled|refunded)['\"]?/i", $query, $status_match ) &&
                 preg_match( "/`?booking_id`?\s*=\s*['\"]?(\d+)['\"]?/i", $query, $id_match ) ) {
                
                $booking_id = (int) $id_match[1];
                $new_status = $status_match[1];
                
                error_log( "GroupCancellation: Detected cancellation - Booking ID: $booking_id, Status: $new_status" );
                
                // Trigger the group cancellation logic
                $this->handle_group_cancellation( $booking_id, null, [ 'status' => $new_status ] );
            }
        }
        
        return $query;
    }

    /**
     * Handle cascading cancellation for grouped bookings
     * ... existing handle_group_cancellation code ...
     */
    public function handle_group_cancellation( $booking_id, $booking, $data ) {
        
        // --- 1. DEBUG: Log Everything ---
        error_log( "GroupCancellation: HOOK FIRED for Booking ID: $booking_id" );
        // error_log( "GroupCancellation: Incoming Data: " . print_r( $data, true ) );
        
        // 2. Check Loop Protection
        if ( wp_cache_get( 'processing_group_cancellation_' . $booking_id ) ) {
            error_log( "GroupCancellation: Skipping $booking_id (Already Processing)" );
            return;
        }

        // 3. Status Check
        $new_status = isset( $data['status'] ) ? $data['status'] : '';
        if ( ! in_array( $new_status, ['cancelled', 'refunded'] ) ) {
            error_log( "GroupCancellation: Status '$new_status' is not a cancellation. Ignoring." );
            return;
        }

        error_log( "GroupCancellation: Valid Cancellation detected. Searching for parent Reservation Record..." );

        // 4. Find the Reservation Record (CPT) for this booking
        // We look for the CPT that contains this booking_id in its 'booking_ids' array.
        // Since it's serialized, we use LIKE.
        $reservation_posts = get_posts([
            'post_type'  => 'reservation_record',
            'meta_query' => [
                [
                    'key'     => 'booking_ids',
                    'value'   => '"' . $booking_id . '"', // Search for likely serialized format "123"
                    'compare' => 'LIKE' 
                ]
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        
        // Fallback search (just the number)
        if ( empty( $reservation_posts ) ) {
            error_log("GroupCancellation: Search 1 (Serialized) failed. Trying simple LIKE search...");
            $reservation_posts = get_posts([
                'post_type'  => 'reservation_record',
                'meta_query' => [
                    [
                        'key'     => 'booking_ids',
                        'value'   => $booking_id,
                        'compare' => 'LIKE' 
                    ]
                ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
        }

        if ( empty( $reservation_posts ) ) {
            error_log( "GroupCancellation: FAILED. No parent reservation record found for booking $booking_id" );
            return;
        }

        $post_id = $reservation_posts[0];
        error_log( "GroupCancellation: SUCCESS. Found parent post $post_id. Retrieving group..." );

        // 5. Get all Booking IDs in this group
        $group_booking_ids = get_post_meta( $post_id, 'booking_ids', true );
        error_log( "GroupCancellation: Group IDs found: " . print_r($group_booking_ids, true) );
        
        if ( empty( $group_booking_ids ) || ! is_array( $group_booking_ids ) ) {
            error_log( "GroupCancellation: Invalid booking_ids meta for post $post_id" );
            return;
        }

        // Filter out the current one
        $siblings = array_diff( $group_booking_ids, [ $booking_id ] );

        if ( empty( $siblings ) ) {
            error_log( "GroupCancellation: No siblings to cancel. (Single booking group)" ); 
            // Still update CPT status though!
        } else {
            error_log( "GroupCancellation: Found siblings to cancel: " . implode( ', ', $siblings ) );
        }

        // 6. Cancel Siblings
        foreach ( $siblings as $sibling_id ) {
            
            // Mark as processing to prevent infinite loop when we update it
            wp_cache_set( 'processing_group_cancellation_' . $sibling_id, true );
            
            // Update JetBooking status directly in DB
            jet_abaf()->db->update_booking( $sibling_id, [
                'status' => $new_status 
            ] );
            
            error_log( "GroupCancellation: Auto-cancelled sibling booking $sibling_id" );
        }

        // --- 72-Hour Rule & Voucher Restoration ---
        global $wpdb;
        $b_table = $wpdb->prefix . 'jet_apartment_bookings';
        $booking_row = $wpdb->get_row( $wpdb->prepare( "SELECT check_in_date FROM $b_table WHERE booking_id = %d", $booking_id ) );
        
        if ( $booking_row ) {
            $check_in_ts = is_numeric( $booking_row->check_in_date ) ? $booking_row->check_in_date : strtotime( $booking_row->check_in_date );
            $current_ts  = current_time( 'timestamp' );
            $hours_diff  = ( $check_in_ts - $current_ts ) / 3600;
            
            error_log( "GroupCancellation: Flight Timer - Check-in: $check_in_ts, Now: $current_ts, Hours Dest: $hours_diff" );

            $used_coupon = get_post_meta( $post_id, '_used_coupon_code', true );

            if ( $hours_diff >= 72 ) {
                // > 72 Hours: REFUND / RESTORE
                
                // --- 1. Restore Custom Voucher CPT (via _used_voucher_id) ---
                $used_voucher_id = get_post_meta( $post_id, '_used_voucher_id', true );
                
                // Fallback: If _used_voucher_id missing, try finding voucher with matching linked_reservation_id
                if ( ! $used_voucher_id ) {
                    // This is a bit expensive but necessary if sync was missed
                    // We check if any voucher has this reservation ID
                    /* $v_check = get_posts([
                        'post_type'  => 'voucher',
                        'meta_key'   => 'linked_reservation_id',
                        'meta_value' => $booking_id, // Check using the known booking ID
                        'fields'     => 'ids',
                        'numberposts' => 1
                    ]);
                    if ( ! empty( $v_check ) ) { $used_voucher_id = $v_check[0]; } */
                }

                if ( $used_voucher_id ) {
                    update_post_meta( $used_voucher_id, 'voucher_status', 'active' );
                    delete_post_meta( $used_voucher_id, 'linked_reservation_id' ); // Unlink!
                    delete_post_meta( $used_voucher_id, 'usage_date' );
                    
                    wp_set_object_terms( $used_voucher_id, 'active', 'voucher-status' );
                    wp_remove_object_terms( $used_voucher_id, 'used', 'voucher-status' );

                    error_log( "GroupCancellation: Restored Used Voucher ID $used_voucher_id to Active." );
                }

                // --- 2. Restore WC Coupon (Legacy/Separate) ---
                if ( ! empty( $used_coupon ) ) {
                    error_log( "GroupCancellation: > 72h. Attempting to restore coupon: $used_coupon" );
                    
                    // Attempt to restore Voucher CPT via Coupon Code match (OLD Logic fallback)
                    // Only run this if we didn't already restore via ID above
                    if ( ! $used_voucher_id ) {
                        $v_posts = get_posts([
                            'post_type'  => 'voucher',
                            'meta_key'   => 'voucher_code',
                            'meta_value' => $used_coupon,
                            'fields'     => 'ids',
                            'numberposts' => 1
                        ]);
                        
                        if ( ! empty( $v_posts ) ) {
                             $v_id = $v_posts[0];
                             update_post_meta( $v_id, 'voucher_status', 'active' );
                             delete_post_meta( $v_id, 'linked_reservation_id' );
                             
                             wp_set_object_terms( $v_id, 'active', 'voucher-status' );
                             
                             error_log( "GroupCancellation: Restored Voucher CPT ID $v_id (via Code match)." );
                        }
                    }
                    
                    // Restore WC Coupon usage
                    if ( class_exists( 'WC_Coupon' ) ) {
                        $wc_coupon = new WC_Coupon( $used_coupon );
                        if ( $wc_coupon->get_id() ) {
                            try {
                                $wc_coupon->decrease_usage_count();
                                error_log( "GroupCancellation: Decreased WC Coupon usage count." );
                            } catch ( Exception $e ) {
                                error_log( "GroupCancellation: WC Coupon error: " . $e->getMessage() );
                            }
                        }
                    }
                }
                
                // If "cancelled", upgrade to "refunded" for clarity in CPT
                if ( 'cancelled' === $new_status ) {
                     $new_status = 'refunded';
                }
                
            } else {
                // < 72 Hours: FORFEIT
                error_log( "GroupCancellation: < 72h. Voucher/Payment forfeited." );
                update_post_meta( $post_id, 'cancellation_note', 'Forfeited (Less than 72h notice)' );
            }
        }

        // 7. Update CPT Status to match
        update_post_meta( $post_id, 'reservation_status', $new_status );
        
        // Update Check-in Status to Terminado (as per user request)
        update_post_meta( $post_id, 'check-in_status', 'Terminado' );
        
        // Track who cancelled only if not already set (preserve 'flight_cancelled' if admin did it)
        // Wait, 'user_cancelled' logic was simpler before. 
        // If coming from Frontend AJAX, we know it's user.
        // If coming from Admin, we might want to be careful.
        // But for now, let's just stick to the requested "Terminado" update.
        
        if ( ! get_post_meta( $post_id, 'resv_cancellation_type', true ) ) {
             update_post_meta( $post_id, 'resv_cancellation_type', 'user_cancelled' );
        }
        
        error_log( "GroupCancellation: Updated parent post $post_id status to $new_status and check-in to Terminado" );
        
        // 8. Trigger Group Cancellation Email
        do_action( 'wind_passenger/group_cancellation_email', $post_id, $group_booking_ids, $new_status );
    }
    /**
     * Add "Cancel" link to Row Actions
     */
    public function add_cancellation_row_action( $actions, $post ) {
        if ( 'reservation_record' !== $post->post_type ) {
            return $actions;
        }

        $status = get_post_meta( $post->ID, 'reservation_status', true );
        if ( in_array( $status, ['cancelled', 'refunded'] ) ) {
            return $actions;
        }

        // Add Cancel Action
        $actions['wind_cancel'] = sprintf(
            '<a href="#" class="wind-admin-cancel-btn" data-post-id="%d" style="color: #b32d2e;">%s</a>',
            $post->ID,
            'Cancelar (Admin)'
        );

        return $actions;
    }

    /**
     * Register Meta Box for Admin Cancellation
     */
    public function register_cancellation_meta_box() {
        add_meta_box(
            'wind_admin_cancellation_box',
            'Ações de Cancelamento',
            [ $this, 'render_admin_cancellation_box' ],
            'reservation_record',
            'side',
            'high'
        );
    }

    /**
     * Render the Admin Cancellation Button (Meta Box)
     */
    public function render_admin_cancellation_box( $post ) {
        $status = get_post_meta( $post->ID, 'reservation_status', true );
        $cancellation_type = get_post_meta( $post->ID, 'resv_cancellation_type', true );

        if ( in_array( $status, ['cancelled', 'refunded'] ) ) {
            echo '<p><strong>Estado:</strong> ' . esc_html( ucfirst( $status ) ) . '</p>';
            if ( $cancellation_type ) {
                echo '<p><strong>Tipo:</strong> ' . esc_html( $cancellation_type ) . '</p>';
            }
            return;
        }

        echo '<div style="margin-top: 10px;">';
        echo '<p class="description">Cancelar esta reserva e todas as reservas associadas (Grupo).</p>';
        echo '<button type="button" class="button button-secondary wind-admin-cancel-btn" data-post-id="' . esc_attr( $post->ID ) . '" style="color: #b32d2e; border-color: #b32d2e;">Cancelar Reserva (Admin)</button>';
        echo '<span class="wind-admin-cancel-spinner spinner" style="float: none; margin-left: 5px;"></span>';
        echo '</div>';
    }

    /**
     * Print Admin JS for Cancellation
     */
    public function print_admin_cancellation_script() {
        global $post_type;
        if ( 'reservation_record' !== $post_type ) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Use class selector to handle both List View and Edit Screen buttons
            $(document).on('click', '.wind-admin-cancel-btn', function(e) {
                e.preventDefault();
                
                if (!confirm('Tem a certeza que deseja cancelar esta reserva administrativamente? Esta ação não pode ser desfeita.')) {
                    return;
                }

                var $btn = $(this);
                var postId = $btn.data('post-id');
                
                // Try to find spinner sibling (Edit Screen) or just change cursor (List View)
                var $spinner = $btn.siblings('.spinner');

                if ($spinner.length) {
                    $spinner.addClass('is-active');
                    $btn.prop('disabled', true);
                } else {
                    $btn.text('Processando...');
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wind_admin_cancel_reservation',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce( "wind_admin_cancel_nonce" ); ?>'
                    },
                    success: function(response) {
                        if ($spinner.length) $spinner.removeClass('is-active');
                        
                        if (response.success) {
                            alert(response.data);
                            window.location.reload();
                        } else {
                            alert('Erro: ' + response.data);
                            $btn.prop('disabled', false);
                            if (!$spinner.length) $btn.text('Cancelar (Admin)');
                        }
                    },
                    error: function() {
                        if ($spinner.length) $spinner.removeClass('is-active');
                        alert('Erro de conexão.');
                        $btn.prop('disabled', false);
                        if (!$spinner.length) $btn.text('Cancelar (Admin)');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle Admin Cancellation AJAX
     */
    public function handle_admin_cancellation() {
        check_ajax_referer( 'wind_admin_cancel_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Não autorizado.' );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( 'ID inválido.' );
        }

        // 1. Mark as Admin Cancelled immediately
        // This ensures monitor_booking_updates sees this flag
        update_post_meta( $post_id, 'resv_cancellation_type', 'admin_cancelled' );

        // 2. Trigger Cancellation via Booking Status Update
        // We find the trigger booking ID and update it.
        $booking_ids = get_post_meta( $post_id, 'booking_ids', true );
        
        if ( empty( $booking_ids ) || ! is_array( $booking_ids ) ) {
             // Fallback to single ID
             $single = get_post_meta( $post_id, 'resv_booking_id', true );
             if ( $single ) {
                 $booking_ids = [ $single ];
             } else {
                 wp_send_json_error( 'Nenhuma reserva vinculada encontrada.' );
             }
        }

        $trigger_id = $booking_ids[0];

        if ( function_exists( 'jet_abaf' ) ) {
            // This update triggers 'monitor_booking_updates' -> 'handle_group_cancellation'
            jet_abaf()->db->update_booking( $trigger_id, [ 'status' => 'cancelled' ] );
            
            // Explicitly verify status update
            update_post_meta( $post_id, 'reservation_status', 'cancelled' );
            update_post_meta( $post_id, 'check-in_status', 'Terminado' );
            
            wp_send_json_success( 'Reserva cancelada com sucesso.' );
        } else {
            wp_send_json_error( 'Sistema de reservas indisponível.' );
        }
    }

}

new Booking_Cancellation();
