<?php
/**
 * First-order discount.
 *
 * Adds a negative fee to the cart for customers who have no earlier paid order.
 * Logged-in customers are matched by account, guests by billing email.
 *
 * @package OneClickUpsell
 */

defined( 'ABSPATH' ) || exit;

class OCU_First_Order_Discount {

	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply' ) );
	}

	public static function apply( WC_Cart $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$percent = min( 90, max( 0, (float) OCU_Gateway::setting( 'first_order_discount', 0 ) ) );
		if ( $percent <= 0 || ! self::is_first_order() ) {
			return;
		}

		$amount = round( (float) $cart->get_subtotal() * $percent / 100, wc_get_price_decimals() );
		if ( $amount > 0 ) {
			$cart->add_fee( __( 'First-order discount', 'ocu' ), -$amount, true );
		}
	}

	/**
	 * True when no paid order exists for this customer.
	 *
	 * A guest who has not typed an email yet gets the discount shown; it is
	 * checked again with the real email when the order is placed.
	 */
	private static function is_first_order() {
		$args = array(
			'status' => array_map(
				static function ( $status ) {
					return 'wc-' . $status;
				},
				wc_get_is_paid_statuses()
			),
			'limit'  => 1,
			'return' => 'ids',
		);

		if ( is_user_logged_in() ) {
			$args['customer_id'] = get_current_user_id();
		} else {
			$email = self::posted_email();
			if ( ! $email ) {
				return true;
			}
			$args['billing_email'] = $email;
		}

		return empty( wc_get_orders( $args ) );
	}

	/**
	 * Billing email from the checkout submit or from the "update order review" AJAX call.
	 */
	private static function posted_email() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_POST['billing_email'] ) ) {
			return sanitize_email( wp_unslash( $_POST['billing_email'] ) );
		}
		if ( ! empty( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! empty( $data['billing_email'] ) ) {
				return sanitize_email( $data['billing_email'] );
			}
		}
		// phpcs:enable
		return '';
	}
}
