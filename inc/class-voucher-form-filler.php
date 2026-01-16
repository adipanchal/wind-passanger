<?php
/**
 * Voucher Form Filler
 *
 * Auto-fills JetFormBuilder fields based on URL voucher_id parametre.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Voucher_Form_Filler {

	public function __construct() {
		add_action( 'wp_footer', [ $this, 'inject_autofill_script' ] );
	}

	public function inject_autofill_script() {
		// 1. Check if we have the voucher_id in URL
		if ( empty( $_GET['voucher_id'] ) ) {
			return;
		}

		$voucher_id = absint( $_GET['voucher_id'] );

		// 2. Validate Post Type
		if ( 'voucher' !== get_post_type( $voucher_id ) ) {
			return;
		}

		// 3. Get Meta Data
		$passengers           = get_post_meta( $voucher_id, 'voucher_passengers', true );
		$voucher_code         = get_post_meta( $voucher_id, 'voucher_code', true );
		
		// New Fields (Try both likely keys just in case)
		$gift_box             = get_post_meta( $voucher_id, '_gift_box', true );
		if ( '' === $gift_box ) $gift_box = get_post_meta( $voucher_id, 'gift_box', true );

		$after_ballooning     = get_post_meta( $voucher_id, '_after_ballooning', true );
		if ( '' === $after_ballooning ) $after_ballooning = get_post_meta( $voucher_id, 'after_ballooning', true );

		$accommodation_status = get_post_meta( $voucher_id, '_accommodation-status', true );
		if ( '' === $accommodation_status ) $accommodation_status = get_post_meta( $voucher_id, 'accommodation-status', true );

		// 4. Output Javascript & CSS
		?>
        <style>
            /* Force readonly look and behavior for voucher fields */
            .voucher-apply-field {
                pointer-events: none !important;
                background-color: #f5f5f5 !important;
                border: 1px solid #ddd !important;
                color: #777 !important;
                cursor: not-allowed !important;
            }
            /* Specifically for inputs to ensure override */
            input.voucher-apply-field, 
            select.voucher-apply-field, 
            textarea.voucher-apply-field {
                background-color: #f5f5f5 !important;
                color: #777 !important;
            }
        </style>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				const voucherData = {
					passengers: "<?php echo esc_js( $passengers ); ?>",
					code: "<?php echo esc_js( $voucher_code ); ?>",
					id: "<?php echo esc_js( $voucher_id ); ?>",
					gift_box: "<?php echo esc_js( $gift_box ); ?>",
					after_ballooning: "<?php echo esc_js( $after_ballooning ); ?>",
					accommodation_status: "<?php echo esc_js( $accommodation_status ); ?>"
				};

				// Helper to set value and trigger events
				const setField = (form, name, value) => {
					if (!value) return;
					const field = form.querySelector(`[name="${name}"]`);
					if (field) {
						field.value = value;
                        // Add class and readonly attribute
                        field.classList.add('voucher-apply-field');
                        field.setAttribute('readonly', 'readonly');
                        
						field.dispatchEvent(new Event('input', { bubbles: true }));
						field.dispatchEvent(new Event('change', { bubbles: true }));
						
						// Trigger jQuery events if available
						if (window.jQuery) {
							window.jQuery(field).trigger('input').trigger('change').trigger('keyup');
						}
					}
				};

				// Function to fill fields
				const fillFields = (form) => {
                    // 0. LOCK ANY EXISTING FIELDS with the class
                    const existingLocked = form.querySelectorAll('.voucher-apply-field');
                    existingLocked.forEach(el => {
                        el.setAttribute('readonly', 'readonly');
                        el.setAttribute('tabindex', '-1');
                        if (el.tagName === 'SELECT') {
                            // Selects don't support readonly, use disabled or CSS pointer-events
                             el.style.pointerEvents = 'none';
                             el.style.backgroundColor = '#f5f5f5';
                        }
                    });

					// 1. Fill Unit Number (Passengers)
					setField(form, 'unit_number', voucherData.passengers);

					// 2. Fill Voucher Code
					setField(form, 'form_voucher_code', voucherData.code);

					// 3. Fill Voucher ID
					setField(form, 'voucher_id', voucherData.id);

					// 4. Fill Gift Box
					setField(form, 'gift_box', voucherData.gift_box);

					// 5. Fill After Ballooning
					setField(form, 'after_ballooning', voucherData.after_ballooning);

					// 6. Fill Accommodation Status
					setField(form, 'accommodation-status', voucherData.accommodation_status);
				};
                
				// Observer to wait for Popup/Form to appear in DOM
				const observer = new MutationObserver((mutations) => {
					for (const mutation of mutations) {
						for (const node of mutation.addedNodes) {
							if (node.nodeType === 1) { // Element
								// Check if the node itself is the form or contains the form
								// Check for ANY inputs relevant to us
								if (node.querySelector('[name="form_voucher_code"]') || node.querySelector('[name="voucher_id"]') || node.querySelector('.voucher-apply-field')) {
									const form = node.closest('form') || node.querySelector('form');
									if (form) {
                                        // Slight delay to ensure other scripts allow the change
                                        setTimeout(() => fillFields(form), 100);
                                        
                                        // SECURITY: Force verify lock for 3 seconds to prevent overrides
                                        let attempts = 0;
                                        const enforcer = setInterval(() => {
                                            fillFields(form);
                                            attempts++;
                                            if (attempts > 15) clearInterval(enforcer);
                                        }, 200);
                                    }
								}
							}
						}
					}
				});

				observer.observe(document.body, {
					childList: true,
					subtree: true
				});

				// Also try immediately
				const staticForm = document.querySelector('form');
				if (staticForm && (staticForm.querySelector('[name="form_voucher_code"]') || staticForm.querySelector('[name="voucher_id"]'))) {
					fillFields(staticForm);
				}
			});
		</script>
		<?php
	}
}

new Voucher_Form_Filler();
