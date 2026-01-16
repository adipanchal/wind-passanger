<?php
/**
 * Flight Booking Engine
 * Handles real-time availability checks via REST API & Voucher Validation.
 * Also handles Admin Columns for live capacity.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPJ_Flight_Booking_Engine
{

    private static $instance = null;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        // Database Setup - Create tables immediately
        $this->maybe_create_reservations_table();
        $this->maybe_create_availability_table();
        
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        
        // Schedule cleanup cron if not already scheduled
        if (!wp_next_scheduled('wpj_cleanup_reservations')) {
            wp_schedule_event(time(), 'every_minute', 'wpj_cleanup_reservations');
        }
        add_action('wpj_cleanup_reservations', [$this, 'cleanup_expired_reservations']);
        
        // REST API
        add_action('rest_api_init', [$this, 'register_routes']);

        // Frontend Scripts & Shortcodes
        add_action('wp_enqueue_scripts', [$this, 'localize_scripts'], 99);
        add_shortcode('live_seats', [$this, 'shortcode_live_seats']);

        // Admin Columns (Tickets CPT)
        add_filter('manage_tickets_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_tickets_posts_custom_column', [$this, 'render_admin_columns'], 10, 2);

        // Admin AJAX & Footer Script
        add_action('wp_ajax_wpj_admin_capacity', [$this, 'ajax_admin_capacity']);
        add_action('admin_footer', [$this, 'admin_footer_script']);
        
        // Admin Tools Menu for Rebuilding Availability
        add_action('admin_menu', [$this, 'add_rebuild_menu_page']);
        
        // JetFormBuilder Validation (Prevent Race Condition)
        add_filter('jet-form-builder/custom-action/before-send', [$this, 'validate_booking_availability'], 10, 2);
        
        // AUTO-SYNC AVAILABILITY: Cron-based (reliable, runs every 5 minutes)
        if (!wp_next_scheduled('wpj_sync_availability_cron')) {
            wp_schedule_event(time(), 'wpj_five_minutes', 'wpj_sync_availability_cron');
        }
        add_action('wpj_sync_availability_cron', [$this, 'cron_sync_all_availability']);
        
        // Booking Completion Cleanup - DISABLED (causes critical errors)
        // Cron cleanup handles expired reservations reliably
        // add_action('jet-booking/db/booking-inserted', [$this, 'cleanup_reservation_after_booking'], 10, 2);
    }
    
    /**
     * Add custom cron interval (every minute)
     */
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __('Every Minute')
        ];
        $schedules['wpj_five_minutes'] = [
            'interval' => 300, // 5 minutes
            'display'  => __('Every 5 Minutes')
        ];
        return $schedules;
    }
    
    /**
     * Create Reservations Table
     */
    public function maybe_create_reservations_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpj_seat_reservations';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            return; // Table exists
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            flight_id BIGINT(20) UNSIGNED NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            seats_reserved INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY flight_id (flight_id),
            KEY session_id (session_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Cleanup Expired Reservations (Cron Job)
     * Runs every minute to delete ONLY expired reservations
     */
    public function cleanup_expired_reservations() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpj_seat_reservations';
        
        // Log before cleanup for debugging
        $count_before = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        // Delete only expired reservations
        $deleted = $wpdb->query("DELETE FROM $table WHERE expires_at < NOW()");
        
        if ($deleted > 0) {
            error_log("[WPJ Cron] Deleted {$deleted} expired reservations (Total before: {$count_before})");
        }
    }
    
    // ==========================================
    // AVAILABILITY TABLE & SYNC SYSTEM
    // ==========================================
    
    /**
     * Create dedicated availability table
     */
    public function maybe_create_availability_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpj_flight_availability';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            return;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            flight_id BIGINT(20) UNSIGNED NOT NULL,
            total_seats INT(11) NOT NULL DEFAULT 0,
            booked_seats INT(11) NOT NULL DEFAULT 0,
            available_seats INT(11) NOT NULL DEFAULT 0,
            last_updated DATETIME NOT NULL,
            PRIMARY KEY (flight_id),
            KEY available_seats (available_seats)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * CRON JOB: Sync all flight availability (runs every 5 minutes)
     * This is 100% reliable - no dependency on hooks
     */
    public function cron_sync_all_availability() {
        global $wpdb;
        
        $availability_table = $wpdb->prefix . 'wpj_flight_availability';
        $units_table = $wpdb->prefix . 'jet_apartment_units';
        $bookings_table = $wpdb->prefix . 'jet_apartment_bookings';
        
        // Get all unique flight IDs
        $flight_ids = $wpdb->get_col("SELECT DISTINCT apartment_id FROM $units_table");
        
        $updated_count = 0;
        foreach ($flight_ids as $flight_id) {
            // Count total
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $units_table WHERE apartment_id = %d",
                $flight_id
            ));
            
            // Count booked
            $booked = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $bookings_table 
                 WHERE apartment_id = %d 
                 AND status IN ('pending', 'processing', 'completed', 'wc-pending', 'wc-processing', 'wc-completed')",
                $flight_id
            ));
            
            // Calculate available
            $available = max(0, $total - $booked);
            
            // Update table
            $wpdb->replace($availability_table, [
                'flight_id' => $flight_id,
                'total_seats' => $total,
                'booked_seats' => $booked,
                'available_seats' => $available,
                'last_updated' => current_time('mysql')
            ]);
            
            // Update post meta for JetEngine compatibility
            update_post_meta($flight_id, '_jc_capacity', $available);
            
            $updated_count++;
        }
        
        if ($updated_count > 0) {
            error_log("[WPJ Cron Sync] Updated availability for {$updated_count} flights");
        }
    }
    
    /**
     * Add Tools menu page for rebuilding
     */
    public function add_rebuild_menu_page() {
        add_management_page(
            'Rebuild Flight Availability',
            'Rebuild Flights',
            'manage_options',
            'wpj-rebuild-flights',
            [$this, 'render_rebuild_page']
        );
    }
    
    /**
     * Render the rebuild page
     */
    public function render_rebuild_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpj_flight_availability';
        ?>
        <div class="wrap">
            <h1>Rebuild Flight Availability</h1>
            <p>This will rebuild both the <code><?php echo $table; ?></code> table and <code>_jc_capacity</code> post meta for all flights.</p>
            
            <form method="post">
                <?php wp_nonce_field('wpj_rebuild', 'wpj_nonce'); ?>
                <p>
                    <button type="submit" name="wpj_rebuild" class="button button-primary button-hero">
                        Rebuild All Flights Now
                    </button>
                </p>
            </form>
            
            <?php
            if (isset($_POST['wpj_rebuild']) && wp_verify_nonce($_POST['wpj_nonce'], 'wpj_rebuild')) {
                $count = $this->rebuild_all_flights();
                echo '<div class="notice notice-success"><p>';
                echo sprintf('<strong>✅ Success!</strong> Rebuilt data for <strong>%d flights</strong>.', $count);
                echo '</p></div>';
            }
            
            // Show current data
            $flights = $wpdb->get_results("SELECT * FROM $table ORDER BY available_seats ASC LIMIT 10");
            if ($flights) {
                echo '<h3>Current Data (First 10 flights):</h3>';
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>Flight ID</th><th>Total</th><th>Booked</th><th>Available</th><th>Meta Value</th><th>Last Updated</th></tr></thead>';
                echo '<tbody>';
                foreach ($flights as $row) {
                    $meta = get_post_meta($row->flight_id, '_jc_capacity', true);
                    echo '<tr>';
                    echo '<td>' . $row->flight_id . '</td>';
                    echo '<td>' . $row->total_seats . '</td>';
                    echo '<td>' . $row->booked_seats . '</td>';
                    echo '<td><strong>' . $row->available_seats . '</strong></td>';
                    echo '<td>' . ($meta !== '' ? $meta : '<em>Not set</em>') . '</td>';
                    echo '<td>' . $row->last_updated . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p><em>No data yet. Click "Rebuild" to populate.</em></p>';
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Rebuild all flights
     */
    public function rebuild_all_flights() {
        global $wpdb;
        
        $availability_table = $wpdb->prefix . 'wpj_flight_availability';
        $units_table = $wpdb->prefix . 'jet_apartment_units';
        $bookings_table = $wpdb->prefix . 'jet_apartment_bookings';
        
        // Clear existing data
        $wpdb->query("TRUNCATE TABLE $availability_table");
        
        // Get all unique flight IDs
        $flight_ids = $wpdb->get_col("SELECT DISTINCT apartment_id FROM $units_table");
        
        foreach ($flight_ids as $flight_id) {
            // Count total
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $units_table WHERE apartment_id = %d",
                $flight_id
            ));
            
            // Count booked
            $booked = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $bookings_table 
                 WHERE apartment_id = %d 
                 AND status IN ('pending', 'processing', 'completed', 'wc-pending', 'wc-processing', 'wc-completed')",
                $flight_id
            ));
            
            // Calculate available
            $available = max(0, $total - $booked);
            
            // Insert into table
            $wpdb->replace($availability_table, [
                'flight_id' => $flight_id,
                'total_seats' => $total,
                'booked_seats' => $booked,
                'available_seats' => $available,
                'last_updated' => current_time('mysql')
            ]);
            
            // ALSO update post meta
            update_post_meta($flight_id, '_jc_capacity', $available);
        }
        
        return count($flight_ids);
    }
    
    /**
     * Get Session ID (for tracking reservations)
     * Uses a cookie-based approach for REST API compatibility
     */
    private function get_session_id() {
        // Check for existing cookie
        if (isset($_COOKIE['wpj_session_id'])) {
            return sanitize_text_field($_COOKIE['wpj_session_id']);
        }
        
        // Generate new session ID
        $session_id = wp_generate_uuid4();
        
        // Set cookie (valid for 1 hour)
        if (!headers_sent()) {
            setcookie('wpj_session_id', $session_id, time() + 3600, '/');
        }
        
        return $session_id;
    }

    /**
     * Core Logic: Get Availability
     * Returns array [total, booked, reserved, available]
     */
    public function get_flight_data($flight_id, $exclude_session = null)
    {
        global $wpdb;

        $flight_id = absint($flight_id);
        if (!$flight_id)
            return ['total' => 0, 'booked' => 0, 'reserved' => 0, 'available' => 0];

        $units_table = $wpdb->prefix . 'jet_apartment_units';
        $bookings_table = $wpdb->prefix . 'jet_apartment_bookings';
        $reservations_table = $wpdb->prefix . 'wpj_seat_reservations';

        // 1. Get Total Capacity
        $total_seats = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$units_table} WHERE apartment_id = %d",
            $flight_id
        ));

        // 2. Get Booked Seats
        $booked_seats = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$bookings_table} 
             WHERE apartment_id = %d 
             AND status IN ('pending', 'processing', 'completed', 'wc-pending', 'wc-processing', 'wc-completed')",
            $flight_id
        ));

        // 3. Get Reserved Seats (only if table exists)
        $reserved_seats = 0;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$reservations_table'") === $reservations_table;
        
        if ($table_exists) {
            $session_id = $exclude_session ?? $this->get_session_id();
            $reserved_seats = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(seats_reserved), 0) FROM {$reservations_table} 
                 WHERE flight_id = %d 
                 AND session_id != %s
                 AND expires_at > NOW()",
                $flight_id, $session_id
            ));
        }

        $available = max(0, $total_seats - $booked_seats - $reserved_seats);

        return [
            'total' => $total_seats,
            'booked' => $booked_seats,
            'reserved' => $reserved_seats,
            'available' => $available
        ];
    }

    /**
     * REST API Registration
     */
    public function register_routes()
    {
        register_rest_route('wpj/v1', '/flight-status', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_flight_status'],
            'permission_callback' => '__return_true', // Public
            'args' => [
                'flight_id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);
        
        // Reserve Seats - MINIMAL TEST VERSION
        register_rest_route('wpj/v1', '/reserve-seats', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_reserve_seats_minimal'],
            'permission_callback' => '__return_true'
        ]);
        
        // Release Seats - For Beacon cleanup
        register_rest_route('wpj/v1', '/release-seats', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_release_seats'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * REST: Reserve Seats - WORKING VERSION
     */
    public function rest_reserve_seats_minimal($request) {
        global $wpdb;
        
        $flight_id = isset($_POST['flight_id']) ? absint($_POST['flight_id']) : 0;
        $seats = isset($_POST['seats']) ? absint($_POST['seats']) : 0;
        
        if (!$flight_id || !$seats) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing parameters'
            ], 200);
        }
        
        // Get or create persistent session ID via cookie
        // Include user ID for logged-in users to ensure uniqueness
        $user_id = get_current_user_id();
        $cookie_name = 'wpj_session_id';
        
        if (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
            $session_id = sanitize_text_field($_COOKIE[$cookie_name]);
        } else {
            // Generate unique session ID
            $unique_part = wp_generate_password(32, false);
            $timestamp = time();
            
            // Include user ID if logged in for better tracking
            if ($user_id > 0) {
                $session_id = "user_{$user_id}_" . $unique_part;
            } else {
                $session_id = "guest_{$timestamp}_" . $unique_part;
            }
            
            // Set cookie for 1 hour with security flags
            setcookie($cookie_name, $session_id, [
                'expires' => time() + 3600,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        
        $table = $wpdb->prefix . 'wpj_seat_reservations';
        
        // Delete old reservation for THIS session and flight ONLY
        // This ensures User A's reservation is NOT affected by User B
        $wpdb->delete($table, [
            'flight_id' => $flight_id,
            'session_id' => $session_id
        ]);
        
        // Now insert the NEW reservation
        $wpdb->insert($table, [
            'flight_id' => $flight_id,
            'session_id' => $session_id,
            'seats_reserved' => $seats,
            'created_at' => current_time('mysql'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 600) // 10 minutes
        ]);
        
        if ($wpdb->last_error) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'DB Error: ' . $wpdb->last_error
            ], 200);
        }
        
        // Log for debugging multi-user scenarios
        error_log("[WPJ] Reservation created: Flight {$flight_id}, Session {$session_id}, Seats {$seats}");
        
        return new WP_REST_Response([
            'success' => true,
            'reserved' => $seats,
            'expires_in' => 600,
            'session_id' => $session_id, // For debugging
            'message' => "Reserved $seats seats for 10 minutes"
        ], 200);
    }
    
    /**
     * REST: Reserve Seats
     */
    public function rest_reserve_seats($request) {
        global $wpdb;
        
        $flight_id = absint($request->get_param('flight_id'));
        $seats = absint($request->get_param('seats'));
        
        // Simple session ID (no cookies for now)
        $session_id = isset($_COOKIE['wpj_session_id']) 
            ? sanitize_text_field($_COOKIE['wpj_session_id']) 
            : 'session_' . time() . '_' . rand(1000, 9999);
        
        $table = $wpdb->prefix . 'wpj_seat_reservations';
        
        // Check table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            $this->maybe_create_reservations_table();
        }
        
        // Delete any existing reservation for this session/flight
        $wpdb->delete($table, [
            'flight_id' => $flight_id,
            'session_id' => $session_id
        ]);
        
        // Create new reservation (5 minutes expiry)
        $expires = gmdate('Y-m-d H:i:s', time() + 300);
        $created = gmdate('Y-m-d H:i:s', time());
        
        $result = $wpdb->insert($table, [
            'flight_id' => $flight_id,
            'session_id' => $session_id,
            'seats_reserved' => $seats,
            'created_at' => $created,
            'expires_at' => $expires
        ]);
        
        if ($result === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Database error: ' . $wpdb->last_error
            ], 200);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'reserved' => $seats,
            'expires_in' => 300,
            'message' => "Reserved $seats seats for 5 minutes"
        ], 200);
    }
    
    /**
     * REST: Release Seats
     */
    public function rest_release_seats($request) {
        global $wpdb;
        
        $flight_id = absint($request->get_param('flight_id'));
        $session_id = $this->get_session_id();
        $table = $wpdb->prefix . 'wpj_seat_reservations';
        
        $wpdb->delete($table, [
            'flight_id' => $flight_id,
            'session_id' => $session_id
        ]);
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Reservation released'
        ], 200);
    }

    /**
     * REST API Callback
     */
    public function rest_get_flight_status($request)
    {
        $flight_id = $request->get_param('flight_id');
        $data = $this->get_flight_data($flight_id);

        return new WP_REST_Response([
            'flight_id' => $flight_id,
            'total' => $data['total'],
            'booked' => $data['booked'],
            'available' => $data['available'],
            'timestamp' => time()
        ], 200);
    }

    /**
     * Frontend Scripts
     */
    public function localize_scripts()
    {
        wp_localize_script('hello-child-main', 'wpj_flight_obj', [
            'api_url' => esc_url_raw(rest_url('wpj/v1/flight-status')),
            'reserve_url' => esc_url_raw(rest_url('wpj/v1/reserve-seats')),
            'release_url' => esc_url_raw(rest_url('wpj/v1/release-seats')), // Added for Beacon
            'nonce' => wp_create_nonce('wp_rest'),
            // NOTE: flight_id is now determined dynamically from form data
            'texts' => [
                'locked' => __('Locked by Voucher', 'hello-elementor-child'),
                'seats_left' => __('seats available', 'hello-elementor-child'),
                'no_seats' => __('Sold Out', 'hello-elementor-child'),
                'exceed_error' => __('Not enough seats! Max available: ', 'hello-elementor-child'),
                'reserved' => __('seats reserved for you', 'hello-elementor-child'),
            ]
        ]);
    }

    /**
     * Shortcode [live_seats]
     */
    public function shortcode_live_seats()
    {
        return '<span class="wpj-live-seats-count">...</span>';
    }

    /* ======================================================
       ADMIN COLUMNS & AJAX
    ====================================================== */

    public function add_admin_columns($cols)
    {
        $cols['available_capacity'] = 'Available Capacity';
        return $cols;
    }

    public function render_admin_columns($col, $post_id)
    {
        if ($col !== 'available_capacity')
            return;

        $data = $this->get_flight_data($post_id);
        $total = $data['total'];
        $booked = $data['booked'];
        $reserved = $data['reserved'];
        $available = $data['available'];

        // Color coding
        $color = $available > 0 ? 'green' : 'red';
        if($available == 0 && $reserved > 0) $color = 'orange';

        echo "<div class='wpj-admin-capacity' 
                   data-flight-id='" . esc_attr($post_id) . "'
                   data-last='" . esc_attr($available) . "'>";
        
        // Main Big Number
        echo "<span style='font-size:18px; color:{$color}; font-weight:bold;'>{$available}</span> <small>Available</small><br>";
        
        // Detailed Breakdown
        echo "<span style='font-size:12px; color:#666;'>";
        echo "Total: <strong>{$total}</strong> | ";
        echo "Booked: <strong>{$booked}</strong>";
        
        if($reserved > 0) {
            echo " | <span style='color:orange;'>Reserved: <strong>{$reserved}</strong></span>";
        }
        
        echo "</span></div>";
    }

    public function ajax_admin_capacity()
    {
        // Permission Check
        if (!current_user_can('edit_posts')) wp_send_json_error();

        $flight_id = absint($_POST['flight_id'] ?? 0);
        if (!$flight_id) {
            wp_send_json_error();
        }

        $data = $this->get_flight_data($flight_id);

        ob_start();
        $this->render_admin_columns('available_capacity', $flight_id);
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'available' => $data['available']
        ]);
    }

    public function admin_footer_script()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'tickets')
            return;
        ?>
        <script>
            (() => {
                const cells = document.querySelectorAll('.wpj-admin-capacity');
                if (!cells.length) return;

                function refreshCapacities() {
                    cells.forEach(el => {
                        const flightId = el.dataset.flightId;
                        const last = parseInt(el.dataset.last, 10);

                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'wpj_admin_capacity',
                                flight_id: flightId
                            })
                        })
                            .then(r => r.json())
                            .then(res => {
                                if (!res.success) return;
                                
                                // Update HTML content directly for detailed view
                                if(res.data.html) {
                                     // Find parent td to replace content or update inner div
                                     const parentTd = el.closest('td');
                                     if(parentTd) parentTd.innerHTML = res.data.html;
                                }
                            });
                    });
                }

                setInterval(refreshCapacities, 15000); // 15s Interval
            })();
        </script>
        <?php
    }
    /* ======================================================
       JETFORMBUILDER VALIDATION (RACE CONDITION PROTECTION)
    ====================================================== */

    public function validate_booking_availability($request, $handler)
    {
        // Get form data
        $form_data = $request;
        
        // Check if this is a flight booking form (has flight_id and unit_number)
        if (empty($form_data['flight_id']) || empty($form_data['unit_number'])) {
            return $request; // Not a booking form, skip validation
        }

        $flight_id = absint($form_data['flight_id']);
        $requested_seats = absint($form_data['unit_number']);

        // Get CURRENT availability (fresh check at submission time)
        $availability_data = $this->get_flight_data($flight_id);
        $available_seats = $availability_data['available'];

        // CRITICAL CHECK: Reject if not enough seats
        if ($requested_seats > $available_seats) {
            // Throw exception to stop form processing
            throw new \Exception(
                sprintf(
                    'Booking failed: Only %d seat(s) currently available for this flight. Please refresh the page and adjust your booking.',
                    $available_seats
                )
            );
        }

        // Validation passed, allow booking to proceed
        return $request;
    }
    
    /* ======================================================
       BOOKING COMPLETION CLEANUP
    ====================================================== */
    
    /**
     * Delete reservation after successful booking
     * Triggered by JetBooking when a booking is inserted into the database
     */
    public function cleanup_reservation_after_booking($booking_id, $booking_data)
    {
        try {
            global $wpdb;
            
            // Get flight ID from booking data
            $flight_id = isset($booking_data['apartment_id']) ? absint($booking_data['apartment_id']) : 0;
            
            if (!$flight_id) {
                return;
            }
            
            // Try to get session ID from cookie (may not exist in all contexts)
            $session_id = null;
            if (isset($_COOKIE['wpj_session_id']) && !empty($_COOKIE['wpj_session_id'])) {
                $session_id = sanitize_text_field($_COOKIE['wpj_session_id']);
            }
            
            // If no session ID, try to get from user ID
            if (!$session_id) {
                $user_id = get_current_user_id();
                if ($user_id > 0) {
                    // Find any reservation for this user and flight
                    $table = $wpdb->prefix . 'wpj_seat_reservations';
                    $session_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT session_id FROM {$table} WHERE flight_id = %d AND session_id LIKE %s LIMIT 1",
                        $flight_id,
                        'user_' . $user_id . '_%'
                    ));
                }
            }
            
            if (!$session_id) {
                error_log("[WPJ] Could not find session ID for cleanup after booking #{$booking_id}");
                return;
            }
            
            // Delete the reservation for this session and flight
            $table = $wpdb->prefix . 'wpj_seat_reservations';
            
            $deleted = $wpdb->delete($table, [
                'flight_id' => $flight_id,
                'session_id' => $session_id
            ]);
            
            // Log for debugging
            if ($deleted) {
                error_log("[WPJ] Deleted reservation for flight {$flight_id}, session {$session_id} after booking #{$booking_id}");
            } else {
                error_log("[WPJ] No reservation found to delete for flight {$flight_id}, session {$session_id}");
            }
        } catch (\Exception $e) {
            // Log error but don't break the booking process
            error_log("[WPJ] Error in cleanup_reservation_after_booking: " . $e->getMessage());
        }
    }
    
    // ==========================================
    // AVAILABLE SEATS HELPERS (Safe Access Layer)
    // ==========================================
    
    /**
     * Get available seats for a specific flight from the table
     * 
     * @param int $flight_id The flight/apartment post ID
     * @return int|null Available seats count, or null if not found
     */
    public static function get_available_seats($flight_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpj_flight_availability';
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT available_seats FROM $table WHERE flight_id = %d",
            $flight_id
        ));
        
        return $result !== null ? (int)$result : null;
    }
    
    /**
     * Get full availability data for a flight
     * 
     * @param int $flight_id The flight/apartment post ID
     * @return object|null Object with total_seats, booked_seats, available_seats, last_updated
     */
    public static function get_flight_availability_data($flight_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpj_flight_availability';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE flight_id = %d",
            $flight_id
        ));
    }
    
    /**
     * Shortcode to display available seats
     * Usage: [wpj_available_seats id="123"]
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function shortcode_available_seats($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'format' => '{available} seats available', // Can customize: "{available}/{total} available"
            'class' => 'wpj-seat-count'
        ], $atts);
        
        $flight_id = absint($atts['id']);
        if (!$flight_id) {
            return '';
        }
        
        $data = self::get_flight_availability_data($flight_id);
        if (!$data) {
            return '';
        }
        
        // Replace placeholders
        $output = str_replace(
            ['{available}', '{total}', '{booked}'],
            [$data->available_seats, $data->total_seats, $data->booked_seats],
            $atts['format']
        );
        
        return sprintf('<span class="%s">%s</span>', esc_attr($atts['class']), esc_html($output));
    }
}

// Initialize
WPJ_Flight_Booking_Engine::instance();

// Register the shortcode globally for easy access
add_shortcode('wpj_available_seats', [WPJ_Flight_Booking_Engine::instance(), 'shortcode_available_seats']);
