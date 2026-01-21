    <?php

    add_action( 'wp_ajax_get_coupon_discount', 'wp_get_coupon_discount' );
    add_action( 'wp_ajax_nopriv_get_coupon_discount', 'wp_get_coupon_discount' );

    function wp_get_coupon_discount() {

        if ( empty( $_POST['coupon_code'] ) ) {
            wp_send_json_success([ 'discount_amount' => 0 ]);
        }

        $code = sanitize_text_field( $_POST['coupon_code'] );
        // Get total price from frontend to cap the discount
        $total_price = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0;

        // 1. Check if WooCommerce is active
        if ( ! class_exists( 'WC_Coupon' ) ) {
            // Fallback to basic post meta check if WC not active
            $coupon = get_page_by_title( $code, OBJECT, 'shop_coupon' );
            if ( ! $coupon ) {
                wp_send_json_success([ 'discount_amount' => 0 ]);
            }
            $amount = (float) get_post_meta( $coupon->ID, 'coupon_amount', true );
            
            // Cap logic
            $final_discount = ($total_price > 0 && $amount > $total_price) ? $total_price : $amount;

            wp_send_json_success([ 'discount_amount' => $final_discount ]);
        }

        // 2. Initialize WC_Coupon
        $coupon = new WC_Coupon( $code );

        // 3. Validation Logic
        // Check if coupon exists (ID > 0)
        if ( ! $coupon->get_id() ) {
            wp_send_json_success([ 'discount_amount' => 0, 'msg' => 'Invalid Code' ]);
        }

        // Check Expiry
        if ( $coupon->get_date_expires() && time() > $coupon->get_date_expires()->getTimestamp() ) {
            wp_send_json_success([ 'discount_amount' => 0, 'msg' => 'Expired' ]);
        }

        // Check Usage Limit (if set)
        if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
            wp_send_json_success([ 'discount_amount' => 0, 'msg' => 'Usage Limit Reached' ]);
        }
        
        // Check Email Restrictions
        $email_restrictions = $coupon->get_email_restrictions();
        
        if ( ! empty( $email_restrictions ) ) {
            if ( ! is_user_logged_in() ) {
                wp_send_json_success([ 'discount_amount' => 0, 'msg' => 'Login Required' ]);
            }

            $current_user = wp_get_current_user();
            $user_email = $current_user->user_email;

            // Smart Coupon Check: Check strict match
            if ( ! in_array( $user_email, $email_restrictions ) ) {
                wp_send_json_success([ 'discount_amount' => 0, 'msg' => 'Invalid User' ]);
            }
        }

        // 4. Get Amount
        // Smart Coupons usually store credit as the amount.
        $coupon_amount = (float) $coupon->get_amount();

        // 5. Smart Logic: Cap Discount at Total Price
        // If user has 500 credit but total is 200, discount should be 200.
        if ( $total_price > 0 && $coupon_amount > $total_price ) {
            $final_discount = $total_price;
        } else {
            $final_discount = $coupon_amount;
        }

        wp_send_json_success([
            'discount_amount' => $final_discount,
            'original_amount' => $coupon_amount,
            'coupon_balance' => $coupon_amount - $final_discount // Remaining balance
        ]);
    }

    add_action( 'wp_footer', 'wp_coupon_sync_script' );
    function wp_coupon_sync_script() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                
                // Helper to find the total price from common JetForm fields
                function getFormTotalPrice($form) {
                    var price = 0;
                    
                    // 1. Get current "Total" (which might already have discount subtracted)
                    var $calcDisplay = $form.find('.jet-form-builder__calculated-field-val');
                    if ($calcDisplay.length) {
                        var val = parseFloat($calcDisplay.text().replace(/[^0-9.-]/g, '')); // Allow negative for safety, but usually positive
                        if (!isNaN(val)) price = val;
                    } else {
                        // Try hidden/input field named 'total_price' or 'price'
                        var $priceInput = $form.find('input[name="total_price"], input[name="price"]');
                        if ($priceInput.length) {
                            price = parseFloat($priceInput.val()) || 0;
                        }
                    }
                    
                    // 2. Add back the CURRENTLY applied discount to get the GROSS price
                    // This is critical because if discount is 200 and total is 0, the Real Price is 200.
                    // If we cap based on 0, we get 0 discount, which is wrong.
                    var $discountField = $form.find('[name="discount_amount"]');
                    var currentDiscount = 0;
                    if ($discountField.length) {
                        currentDiscount = parseFloat($discountField.val()) || 0;
                    }
                    
                    return price + currentDiscount;
                }

                // Delegate to body to support Popups and AJAX loaded forms
                $(document.body).on('change input', '.flight-booking-form #coupon_code', function() {
                    var couponCode = $(this).val();
                    var $field = $(this);
                    var $form = $(this).closest('form'); // More robust than class selector
                    
                    // If code is empty, reset discount
                    if (!couponCode) {
                        updateDiscount($form, 0);
                        showStatus($field, '', '');
                        return;
                    }

                    var totalPrice = getFormTotalPrice($form);

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'get_coupon_discount',
                            coupon_code: couponCode,
                            total_price: totalPrice
                        },
                        success: function(response) {
                            if (response.success && response.data.discount_amount > 0) {
                                var discount = response.data.discount_amount;
                                updateDiscount($form, discount);
                                showStatus($field, '✅ Coupon Applied: -' + discount, 'green');
                            } else {
                                var msg = (response.data && response.data.msg) ? response.data.msg : 'Invalid Code';
                                updateDiscount($form, 0);
                                showStatus($field, '❌ ' + msg, 'red');
                            }
                        }
                    });
                });

                function updateDiscount($form, value) {
                    var $discountField = $form.find('[name="discount_amount"]');
                    
                    if ($discountField.length) {
                        $discountField.val(value);
                        
                        // Force JetFormBuilder updates
                        $discountField.trigger('change').trigger('input');
                        
                        // Native events for deep frameworks
                        if ($discountField[0]) {
                            $discountField[0].dispatchEvent(new Event('change', { bubbles: true }));
                            $discountField[0].dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }
                }

                function showStatus($field, text, color) {
                    var $msg = $field.siblings('.coupon-status-msg');
                    if (!$msg.length) {
                        $field.after('<span class="coupon-status-msg" style="display:block; margin-top:5px; font-weight:bold;"></span>');
                        $msg = $field.siblings('.coupon-status-msg');
                    }
                    $msg.text(text).css('color', color);
                }
            });
        </script>
        <?php
    }

    // ==========================================
    // JetFormBuilder: Coupon Redemption Action
    // Actions/Hooks: 'jet-form-builder/custom-action/redeem_coupon'
    // ==========================================
    add_action( 'jet-form-builder/custom-action/redeem_coupon', 'wpj_handle_coupon_redemption', 10, 2 );

    function wpj_handle_coupon_redemption( $request, $action_handler ) {
        
        // Debug Logging
        error_log( 'JFB Coupon Redemption Triggered' );
        error_log( 'Request Data: ' . print_r( $request, true ) );

        // 1. Get Fields
        $coupon_code = isset( $request['coupon_code'] ) ? sanitize_text_field( $request['coupon_code'] ) : '';
        $discount_used = isset( $request['discount_amount'] ) ? floatval( $request['discount_amount'] ) : 0;
        
        if ( empty( $coupon_code ) ) {
            error_log( 'JFB Redemption: No coupon code found.' );
            return; 
        }

        if ( $discount_used <= 0 ) {
            error_log( 'JFB Redemption: Discount amount is 0 or missing.' );
            // We might still want to increment usage even if discount is 0? usually no.
            return;
        }

        if ( ! class_exists( 'WC_Coupon' ) ) {
            error_log( 'JFB Redemption: WooCommerce not active.' );
            return;
        }

        // 2. Load Coupon
        $coupon = new WC_Coupon( $coupon_code );
        
        if ( ! $coupon->get_id() ) {
            error_log( 'JFB Redemption: Invalid Coupon ID.' );
            return; 
        }

        // 3. Mark as Used (Increments usage count)
        $user_id = get_current_user_id();
        $used_by = $user_id ? $user_id : 'guest';
        
        error_log( "JFB Redemption: Increasing usage for code $coupon_code by user $used_by" );

        try {
            $coupon->increase_usage_count( $used_by );
        } catch ( Exception $e ) {
            error_log( 'JFB Redemption Error increasing usage: ' . $e->getMessage() );
        }

        // 4. Smart Coupon / Store Credit Deduction
        $current_amount = (float) $coupon->get_amount();
        error_log( "JFB Redemption: Current Amount: $current_amount, Deducting: $discount_used" );
        
        if ( $current_amount >= $discount_used ) {
            $new_amount = $current_amount - $discount_used;
            
            $coupon->set_amount( $new_amount );
            $coupon->save();
            error_log( "JFB Redemption: New Amount Saved: $new_amount" );
        } else {
            error_log( 'JFB Redemption: Not reducing amount (Current amount smaller than discount??)' );
        }
    }
