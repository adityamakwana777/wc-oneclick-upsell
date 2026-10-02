<?php
/**
 * Stripe gateway for the classic WooCommerce checkout.
 *
 * Flow: Stripe.js tokenises the card in the browser -> we create a Customer,
 * attach the PaymentMethod and confirm a PaymentIntent that saves the card
 * for later off-session use (the one-click upsell).
 *
 * @package OneClickUpsell
 */

defined( 'ABSPATH' ) || exit;

class OCU_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'ocu_stripe';
		$this->method_title       = __( 'Stripe (One-Click Upsell)', 'ocu' );
		$this->method_description = __( 'Card payments via Stripe. The card is saved so a post-purchase upsell can be charged in one click.', 'ocu' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_api_ocu_return', array( $this, 'handle_return' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Enable/Disable', 'ocu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Stripe (One-Click Upsell)', 'ocu' ),
				'default' => 'no',
			),
			'title'                => array(
				'title'   => __( 'Title', 'ocu' ),
				'type'    => 'text',
				'default' => __( 'Credit / Debit Card', 'ocu' ),
			),
			'description'          => array(
				'title'   => __( 'Description', 'ocu' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with your card.', 'ocu' ),
			),
			'testmode'             => array(
				'title'   => __( 'Test mode', 'ocu' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use Stripe test keys', 'ocu' ),
				'default' => 'yes',
			),
			'test_publishable_key' => array(
				'title' => __( 'Test publishable key', 'ocu' ),
				'type'  => 'text',
			),
			'test_secret_key'      => array(
				'title' => __( 'Test secret key', 'ocu' ),
				'type'  => 'password',
			),
			'live_publishable_key' => array(
				'title' => __( 'Live publishable key', 'ocu' ),
				'type'  => 'text',
			),
			'live_secret_key'      => array(
				'title' => __( 'Live secret key', 'ocu' ),
				'type'  => 'password',
			),
			'first_order_discount' => array(
				'title'       => __( 'First-order discount (%)', 'ocu' ),
				'type'        => 'number',
				'description' => __( 'Percentage taken off the cart for customers with no previous paid order. 0 disables it.', 'ocu' ),
				'default'     => '0',
				'desc_tip'    => true,
				'custom_attributes' => array( 'min' => '0', 'max' => '90', 'step' => '1' ),
			),
			'upsell_product_id'    => array(
				'title'       => __( 'Upsell product ID', 'ocu' ),
				'type'        => 'number',
				'description' => __( 'ID of a simple product offered after payment. Leave empty to disable the upsell.', 'ocu' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'upsell_discount'      => array(
				'title'       => __( 'Upsell discount (%)', 'ocu' ),
				'type'        => 'number',
				'description' => __( 'Percentage taken off the upsell product price.', 'ocu' ),
				'default'     => '0',
				'desc_tip'    => true,
				'custom_attributes' => array( 'min' => '0', 'max' => '90', 'step' => '1' ),
			),
		);
	}

	/**
	 * Read a saved gateway setting from anywhere in the plugin.
	 */
	public static function setting( $key, $default = '' ) {
		$settings = get_option( 'woocommerce_ocu_stripe_settings', array() );
		return ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	public static function is_test() {
		return 'yes' === self::setting( 'testmode', 'yes' );
	}

	public static function secret_key() {
		return self::is_test() ? self::setting( 'test_secret_key' ) : self::setting( 'live_secret_key' );
	}

	public static function publishable_key() {
		return self::is_test() ? self::setting( 'test_publishable_key' ) : self::setting( 'live_publishable_key' );
	}

	public function is_available() {
		return parent::is_available() && self::secret_key() && self::publishable_key();
	}

	/* ---------------------------------------------------------------------
	 * Checkout UI
	 * ------------------------------------------------------------------ */

	public function enqueue_scripts() {
		if ( ! is_checkout() || 'yes' !== $this->enabled || ! self::publishable_key() ) {
			return;
		}

		wp_enqueue_script( 'ocu-stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'ocu-checkout', OCU_URL . 'assets/js/checkout.js', array( 'jquery', 'ocu-stripe-js' ), OCU_VERSION, true );
		wp_localize_script( 'ocu-checkout', 'ocuData', array( 'publishableKey' => self::publishable_key() ) );
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		if ( self::is_test() ) {
			echo '<p><strong>' . esc_html__( 'Test mode: use card 4242 4242 4242 4242, any future date, any CVC.', 'ocu' ) . '</strong></p>';
		}
		echo '<div id="ocu-card-element" style="padding:10px;border:1px solid #ccc;border-radius:4px;background:#fff;"></div>';
		echo '<div id="ocu-card-errors" role="alert" style="color:#b32d2e;margin-top:6px;"></div>';
		echo '<input type="hidden" id="ocu_payment_method" name="ocu_payment_method" value="" />';
	}

	/* ---------------------------------------------------------------------
	 * Payment processing
	 * ------------------------------------------------------------------ */

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->fail( __( 'Order not found.', 'ocu' ) );
		}

		// WooCommerce has already verified its checkout nonce before calling this method.
		$pm_id = isset( $_POST['ocu_payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['ocu_payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! preg_match( '/^pm_[A-Za-z0-9]+$/', $pm_id ) ) {
			return $this->fail( __( 'Card details are missing. Please re-enter your card and try again.', 'ocu' ) );
		}

		$api = new OCU_Stripe_API( self::secret_key() );

		$customer = $api->post(
			'customers',
			array(
				'email'    => $order->get_billing_email(),
				'name'     => trim( $order->get_formatted_billing_full_name() ),
				'metadata' => array( 'order_id' => $order->get_id() ),
			)
		);
		if ( is_wp_error( $customer ) ) {
			return $this->fail_from_error( $customer, $order );
		}

		$attached = $api->post( 'payment_methods/' . $pm_id . '/attach', array( 'customer' => $customer['id'] ) );
		if ( is_wp_error( $attached ) ) {
			return $this->fail_from_error( $attached, $order );
		}

		$intent = $api->post(
			'payment_intents',
			array(
				'amount'             => OCU_Stripe_API::to_minor( $order->get_total(), $order->get_currency() ),
				'currency'           => strtolower( $order->get_currency() ),
				'customer'           => $customer['id'],
				'payment_method'     => $pm_id,
				'confirm'            => 'true',
				'setup_future_usage' => 'off_session',
				'return_url'         => add_query_arg(
					array(
						'wc-api'   => 'ocu_return',
						'order_id' => $order->get_id(),
						'key'      => $order->get_order_key(),
					),
					home_url( '/' )
				),
				'description'        => sprintf( 'Order #%s', $order->get_order_number() ),
				'metadata'           => array( 'order_id' => $order->get_id() ),
			),
			'ocu-order-' . $order->get_id() . '-' . $pm_id
		);
		if ( is_wp_error( $intent ) ) {
			return $this->fail_from_error( $intent, $order );
		}

		// Keep what the upsell needs to charge this card later.
		$order->update_meta_data( '_ocu_customer_id', $customer['id'] );
		$order->update_meta_data( '_ocu_payment_method', $pm_id );
		$order->update_meta_data( '_ocu_intent_id', $intent['id'] );
		if ( isset( $attached['card']['last4'] ) ) {
			$order->update_meta_data( '_ocu_card_last4', sanitize_text_field( $attached['card']['last4'] ) );
		}
		$order->save();

		if ( 'succeeded' === $intent['status'] ) {
			$this->finalize( $order, $intent['id'] );
			return array(
				'result'   => 'success',
				'redirect' => OCU_Upsell::offer_url( $order ),
			);
		}

		// 3-D Secure / SCA: send the customer to the bank, they come back to handle_return().
		if ( 'requires_action' === $intent['status'] && ! empty( $intent['next_action']['redirect_to_url']['url'] ) ) {
			$order->update_status( 'pending', __( 'Awaiting 3-D Secure authentication.', 'ocu' ) );
			return array(
				'result'   => 'success',
				'redirect' => $intent['next_action']['redirect_to_url']['url'],
			);
		}

		$order->add_order_note( sprintf( 'Stripe PaymentIntent %s ended with status "%s".', $intent['id'], $intent['status'] ) );
		return $this->fail( __( 'Your payment could not be completed. Please try another card.', 'ocu' ) );
	}

	/**
	 * Customer returns from 3-D Secure.
	 */
	public function handle_return() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( OCU_Upsell::offer_url( $order ) );
			exit;
		}

		$api    = new OCU_Stripe_API( self::secret_key() );
		$intent = $api->get( 'payment_intents/' . rawurlencode( (string) $order->get_meta( '_ocu_intent_id' ) ) );

		if ( ! is_wp_error( $intent ) && 'succeeded' === $intent['status'] ) {
			$this->finalize( $order, $intent['id'] );
			wp_safe_redirect( OCU_Upsell::offer_url( $order ) );
			exit;
		}

		$order->update_status( 'failed', __( '3-D Secure authentication failed or was cancelled.', 'ocu' ) );
		wc_add_notice( __( 'Payment authentication failed. Please try again.', 'ocu' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function finalize( WC_Order $order, $intent_id ) {
		if ( ! $order->is_paid() ) {
			$order->payment_complete( $intent_id );
			$order->add_order_note( sprintf( 'Stripe payment %s succeeded. Card saved for one-click upsell.', $intent_id ) );
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	private function fail( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	private function fail_from_error( WP_Error $error, WC_Order $order ) {
		$data = $error->get_error_data();
		wc_get_logger()->error( $error->get_error_message(), array( 'source' => 'ocu', 'order_id' => $order->get_id() ) );
		$order->add_order_note( 'Stripe error: ' . $error->get_error_message() );

		// Card errors (declines etc.) are safe and useful to show; anything else stays generic.
		if ( is_array( $data ) && isset( $data['type'] ) && 'card_error' === $data['type'] ) {
			return $this->fail( $error->get_error_message() );
		}
		return $this->fail( __( 'We could not process your payment right now. Please try again.', 'ocu' ) );
	}
}
