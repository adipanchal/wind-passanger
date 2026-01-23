<?php
/**
 * Voucher Manager
 *
 * Handles logic for transferring vouchers between users and activating gifted vouchers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Voucher_Manager {

	public function __construct() {
		// Transfer Voucher Action
		add_action( 'wp_ajax_transfer_voucher', [ $this, 'handle_transfer_voucher' ] );
		
		// Activate Voucher Action
		add_action( 'wp_ajax_activate_gift_voucher', [ $this, 'handle_activate_voucher' ] );
		add_action( 'wp_ajax_nopriv_activate_gift_voucher', [ $this, 'handle_activate_voucher' ] );
	}

	/**
	 * Handle Voucher Transfer (AJAX)
	 * Owner initiates transfer to an email.
	 */
	public function handle_transfer_voucher() {
		check_ajax_referer( 'voucher_transfer_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'You must be logged in to transfer a voucher.' ] );
		}

		$current_user_id = get_current_user_id();
		$voucher_id      = isset( $_POST['voucher_id'] ) ? absint( $_POST['voucher_id'] ) : 0;
		$recipient_email = isset( $_POST['recipient_email'] ) ? sanitize_email( $_POST['recipient_email'] ) : '';

		if ( ! $voucher_id || ! $recipient_email ) {
			wp_send_json_error( [ 'message' => 'Invalid voucher ID or recipient email.' ] );
		}

		// 1. Verify Ownership
		$owner_id = get_post_meta( $voucher_id, 'voucher_owner', true );
		
		// Handle array case if owner is stored as array
		if ( is_array( $owner_id ) ) { 
			$owner_id = isset( $owner_id[0] ) ? $owner_id[0] : 0;
		}
        
        $owner_id = (int) $owner_id;

		if ( $owner_id !== $current_user_id && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'You do not have permission to transfer this voucher.' ] );
		}

		// 2. Verify Voucher Status
		$status = get_post_meta( $voucher_id, 'voucher_status', true ); // active, used, etc.
		if ( 'active' !== $status && 'pending_transfer' !== $status ) {
			wp_send_json_error( [ 'message' => 'This voucher cannot be transferred (Status: ' . $status . ').' ] );
		}

		// 3. Update Voucher Meta
		update_post_meta( $voucher_id, 'voucher_status', 'pending_transfer' );
		update_post_meta( $voucher_id, '_pending_recipient_email', $recipient_email );

		// 4. Generate Activation Link (Optional helper for frontend)
		$voucher_code = get_post_meta( $voucher_id, 'voucher_code', true );
		
		wp_send_json_success( [
			'message' => 'Voucher set to pending transfer.',
			'code'    => $voucher_code
		] );
	}

	/**
	 * Handle Voucher Activation (AJAX)
	 * Recipient claims the voucher using the code.
	 */
	public function handle_activate_voucher() {
		check_ajax_referer( 'voucher_activation_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'You must be logged in to activate a voucher.' ] );
		}

		$current_user_id = get_current_user_id();
		$activation_code = isset( $_POST['activation_code'] ) ? sanitize_text_field( $_POST['activation_code'] ) : '';

		if ( empty( $activation_code ) ) {
			wp_send_json_error( [ 'message' => 'Please provide a valid activation code.' ] );
		}

		// 1. Find Voucher by Code
		$args = [
			'post_type'  => 'voucher',
			'meta_query' => [
				[
					'key'   => 'voucher_code',
					'value' => $activation_code,
				],
			],
			'posts_per_page' => 1,
			'fields'         => 'ids',
		];

		$query = new WP_Query( $args );

		if ( empty( $query->posts ) ) {
			wp_send_json_error( [ 'message' => 'Invalid voucher code.' ] );
		}

		$voucher_id = $query->posts[0];

		// 2. Check Status
		$status = get_post_meta( $voucher_id, 'voucher_status', true );
		
		// We allow claiming if it's 'active' (open transfer) or 'pending_transfer'
		if ( 'used' === $status ) {
			wp_send_json_error( [ 'message' => 'This voucher has already been used.' ] );
		}
		
		// Optional: Check if email matches pending recipient (if strict mode desired)
		// $pending_email = get_post_meta( $voucher_id, '_pending_recipient_email', true );
		// $current_user = wp_get_current_user();
		// if ( $pending_email && $pending_email !== $current_user->user_email ) { ... }

		// 3. Verify not already owner
		$current_owner = (int) get_post_meta( $voucher_id, 'voucher_owner', true );
		if ( $current_owner === $current_user_id ) {
			wp_send_json_error( [ 'message' => 'You already own this voucher.' ] );
		}

		// 4. Transfer Ownership
		update_post_meta( $voucher_id, 'voucher_owner', $current_user_id );
		update_post_meta( $voucher_id, 'voucher_status', 'active' ); // Reset to active once claimed
		delete_post_meta( $voucher_id, '_pending_recipient_email' );

		wp_send_json_success( [ 'message' => 'Voucher successfully added to your account!' ] );
	}

}

new Voucher_Manager();
