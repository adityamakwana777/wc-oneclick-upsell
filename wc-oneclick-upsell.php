<?php
/**
 * Plugin Name:       One-Click Upsell Checkout for WooCommerce
 * Description:       Stripe card checkout with a first-order discount and a one-click post-purchase upsell.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Aditya Makwana
 * License:           MIT
 * Text Domain:       ocu
 *
 * @package OneClickUpsell
 */

defined( 'ABSPATH' ) || exit;

define( 'OCU_VERSION', '1.0.0' );
define( 'OCU_FILE', __FILE__ );
define( 'OCU_DIR', plugin_dir_path( __FILE__ ) );
define( 'OCU_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OCU_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once OCU_DIR . 'includes/class-ocu-stripe-api.php';
		require_once OCU_DIR . 'includes/class-ocu-gateway.php';
		require_once OCU_DIR . 'includes/class-ocu-upsell.php';
		require_once OCU_DIR . 'includes/class-ocu-first-order-discount.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'OCU_Gateway';
				return $gateways;
			}
		);

		OCU_Upsell::init();
		OCU_First_Order_Discount::init();
	}
);
