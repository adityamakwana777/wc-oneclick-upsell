<?php
/**
 * Minimal Stripe API client (no SDK needed).
 *
 * @package OneClickUpsell
 */

defined( 'ABSPATH' ) || exit;

class OCU_Stripe_API {

	const BASE_URL    = 'https://api.stripe.com/v1/';
	const API_VERSION = '2024-06-20';

	/** @var string */
	private $secret_key;

	public function __construct( $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * @param string      $path            e.g. "payment_intents".
	 * @param array       $body            Form fields (nested arrays are supported).
	 * @param string|null $idempotency_key Prevents duplicate charges on retries.
	 * @return array|WP_Error
	 */
	public function post( $path, $body = array(), $idempotency_key = null ) {
		return $this->request( 'POST', $path, $body, $idempotency_key );
	}

	/**
	 * @param string $path e.g. "payment_intents/pi_123".
	 * @return array|WP_Error
	 */
	public function get( $path ) {
		return $this->request( 'GET', $path );
	}

	private function request( $method, $path, $body = array(), $idempotency_key = null ) {
		if ( empty( $this->secret_key ) ) {
			return new WP_Error( 'ocu_no_key', 'Stripe secret key is not configured.' );
		}

		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
		);
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => $headers,
		);
		if ( 'POST' === $method ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( self::BASE_URL . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $data ) || isset( $data['error'] ) ) {
			$error   = ( is_array( $data ) && isset( $data['error'] ) ) ? $data['error'] : array();
			$message = isset( $error['message'] ) ? $error['message'] : 'Unexpected response from Stripe.';
			return new WP_Error( 'ocu_stripe_error', $message, $error );
		}

		return $data;
	}

	/**
	 * Convert a decimal amount to Stripe's smallest currency unit.
	 * (Three-decimal currencies such as BHD/KWD are not handled.)
	 *
	 * @param float  $amount   e.g. 19.99.
	 * @param string $currency ISO code, e.g. "USD".
	 * @return int
	 */
	public static function to_minor( $amount, $currency ) {
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

		if ( in_array( strtoupper( $currency ), $zero_decimal, true ) ) {
			return (int) round( $amount );
		}
		return (int) round( $amount * 100 );
	}
}
