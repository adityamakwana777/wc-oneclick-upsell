<?php
/**
 * Post-purchase one-click upsell.
 *
 * After a successful payment the customer lands on an offer page. Clicking
 * "Yes" charges the card saved during checkout (off-session) and adds the
 * product to the same order. No card entry is needed again.
 *
 * @package OneClickUpsell
 */

defined( 'ABSPATH' ) || exit;

class OCU_Upsell {

	const QUERY_VAR = 'ocu_upsell';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * Where to send the customer after payment: the offer page, or straight to
	 * the normal thank-you page when there is nothing to offer.
	 */
	public static function offer_url( WC_Order $order ) {
		if ( ! self::get_offer( $order ) ) {
			return $order->get_checkout_order_received_url();
		}
		return add_query_arg(
			array(
				self::QUERY_VAR => 1,
				'order_id'      => $order->get_id(),
				'key'           => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Build the offer for an order, or null if no offer should be shown.
	 *
	 * @return array|null
	 */
	private static function get_offer( WC_Order $order ) {
		$product_id = absint( OCU_Gateway::setting( 'upsell_product_id' ) );
		if ( ! $product_id ) {
			return null;
		}

		// Only for paid orders made with our gateway that have a saved card, and only once.
		if ( 'ocu_stripe' !== $order->get_payment_method()
			|| ! $order->is_paid()
			|| ! $order->get_meta( '_ocu_customer_id' )
			|| ! $order->get_meta( '_ocu_payment_method' )
			|| $order->get_meta( '_ocu_upsell_done' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return null;
		}

		$discount = min( 90, max( 0, (float) OCU_Gateway::setting( 'upsell_discount', 0 ) ) );
		$regular  = (float) $product->get_price();
		$price    = round( $regular * ( 1 - $discount / 100 ), wc_get_price_decimals() );

		return array(
			'product'         => $product,
			'net'             => (float) wc_get_price_excluding_tax( $product, array( 'price' => $price ) ),
			'display'         => (float) wc_get_price_to_display( $product, array( 'price' => $price ) ),
			'regular_display' => (float) wc_get_price_to_display( $product, array( 'price' => $regular ) ),
			'discount'        => $discount,
		);
	}

	private static function get_order_from_request() {
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		return $order;
	}

	/* ---------------------------------------------------------------------
	 * Request handling
	 * ------------------------------------------------------------------ */

	public static function maybe_render() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$order = self::get_order_from_request();
		if ( ! $order ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$offer = self::get_offer( $order );
		if ( ! $offer ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$error = '';

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'ocu_upsell_' . $order->get_id() ) ) {
				wp_die( esc_html__( 'This page has expired. Please go back and try again.', 'ocu' ), '', array( 'response' => 403 ) );
			}

			$choice = isset( $_POST['ocu_choice'] ) ? sanitize_key( wp_unslash( $_POST['ocu_choice'] ) ) : '';

			if ( 'decline' === $choice ) {
				$order->update_meta_data( '_ocu_upsell_done', 'declined' );
				$order->save();
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}

			if ( 'accept' === $choice ) {
				$result = self::charge( $order, $offer );
				if ( ! is_wp_error( $result ) ) {
					wp_safe_redirect( $order->get_checkout_order_received_url() );
					exit;
				}
				$error = $result->get_error_message();
			}
		}

		self::render( $order, $offer, $error );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Charging
	 * ------------------------------------------------------------------ */

	/**
	 * Add the product to the order, work out the extra amount (tax included),
	 * charge the saved card, and roll everything back if the charge fails.
	 *
	 * @return true|WP_Error
	 */
	private static function charge( WC_Order $order, array $offer ) {
		$lock = 'ocu_lock_' . $order->get_id();
		// add_option() fails if the option exists, so a double-click cannot run this twice.
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			return new WP_Error( 'ocu_locked', __( 'Your offer is already being processed.', 'ocu' ) );
		}

		/** @var WC_Product $product */
		$product = $offer['product'];
		$before  = (float) $order->get_total();

		$item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => $offer['net'],
				'total'    => $offer['net'],
			)
		);
		$order->calculate_totals( true ); // also calculates tax from the order address.

		$extra = round( (float) $order->get_total() - $before, wc_get_price_decimals() );
		if ( $extra <= 0 ) {
			self::rollback( $order, $item_id, $lock, 'failed' );
			return new WP_Error( 'ocu_amount', __( 'We could not add this offer to your order.', 'ocu' ) );
		}

		$api    = new OCU_Stripe_API( OCU_Gateway::secret_key() );
		$intent = $api->post(
			'payment_intents',
			array(
				'amount'         => OCU_Stripe_API::to_minor( $extra, $order->get_currency() ),
				'currency'       => strtolower( $order->get_currency() ),
				'customer'       => $order->get_meta( '_ocu_customer_id' ),
				'payment_method' => $order->get_meta( '_ocu_payment_method' ),
				'confirm'        => 'true',
				'off_session'    => 'true',
				'description'    => sprintf( 'Upsell for order #%s', $order->get_order_number() ),
				'metadata'       => array(
					'order_id' => $order->get_id(),
					'type'     => 'upsell',
				),
			),
			'ocu-upsell-' . $order->get_id()
		);

		if ( is_wp_error( $intent ) || 'succeeded' !== $intent['status'] ) {
			$message = is_wp_error( $intent ) ? $intent->get_error_message() : 'status ' . $intent['status'];
			wc_get_logger()->error( 'Upsell charge failed: ' . $message, array( 'source' => 'ocu', 'order_id' => $order->get_id() ) );
			self::rollback( $order, $item_id, $lock, 'failed' );
			return new WP_Error(
				'ocu_charge',
				__( 'We could not charge your saved card for this offer (your bank may need extra verification). Your original order is unaffected.', 'ocu' )
			);
		}

		// Success: keep stock in sync and record what happened.
		if ( $product->managing_stock() ) {
			wc_update_product_stock( $product, 1, 'decrease' );
			$item = $order->get_item( $item_id );
			if ( $item ) {
				$item->add_meta_data( '_reduced_stock', 1, true ); // lets WooCommerce restore stock on cancel/refund.
				$item->save();
			}
		}

		$order->update_meta_data( '_ocu_upsell_done', 'accepted' );
		$order->update_meta_data( '_ocu_upsell_intent_id', $intent['id'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: product name 2: amount 3: Stripe id */
				__( 'One-click upsell accepted: %1$s added, %2$s charged (Stripe %3$s).', 'ocu' ),
				$product->get_name(),
				wp_strip_all_tags( wc_price( $extra, array( 'currency' => $order->get_currency() ) ) ),
				$intent['id']
			)
		);
		$order->save();
		delete_option( $lock );

		return true;
	}

	private static function rollback( WC_Order $order, $item_id, $lock, $status ) {
		if ( $item_id ) {
			$order->remove_item( $item_id );
		}
		$order->calculate_totals( true );
		$order->update_meta_data( '_ocu_upsell_done', $status ); // never retry automatically.
		$order->save();
		delete_option( $lock );
	}

	/* ---------------------------------------------------------------------
	 * Offer page (standalone, so it works with any theme, classic or block)
	 * ------------------------------------------------------------------ */

	private static function render( WC_Order $order, array $offer, $error ) {
		/** @var WC_Product $product */
		$product = $offer['product'];
		$last4   = $order->get_meta( '_ocu_card_last4' );
		$action  = self::offer_url_raw( $order );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> &ndash; <?php esc_html_e( 'Special offer', 'ocu' ); ?></title>
	<style>
		body{margin:0;background:#f5f6f8;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1d2327}
		.wrap{max-width:560px;margin:40px auto;padding:0 16px}
		.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:28px}
		h1{font-size:24px;margin:0 0 6px}
		.sub{color:#50575e;margin:0 0 20px}
		.product{display:flex;gap:16px;align-items:flex-start;margin-bottom:20px}
		.product img{width:120px;height:auto;border-radius:8px}
		.name{font-size:18px;font-weight:600;margin:0 0 6px}
		.price{font-size:22px;font-weight:700;color:#0a7d33}
		.was{color:#8c8f94;text-decoration:line-through;margin-left:8px;font-weight:400;font-size:16px}
		.btn{display:block;width:100%;padding:14px;border:0;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer}
		.yes{background:#0a7d33;color:#fff}
		.no{background:none;color:#50575e;margin-top:10px;font-weight:400;text-decoration:underline}
		.err{background:#fcf0f1;border-left:4px solid #b32d2e;padding:10px 12px;margin-bottom:16px;border-radius:4px}
		.note{font-size:13px;color:#646970;margin-top:14px;text-align:center}
	</style>
</head>
<body>
<div class="wrap"><div class="card">
	<h1><?php esc_html_e( 'Wait! One special offer for you', 'ocu' ); ?></h1>
	<p class="sub"><?php esc_html_e( 'Add this to your order in one click. No need to enter your card again.', 'ocu' ); ?></p>

	<?php if ( $error ) : ?>
		<div class="err"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<div class="product">
		<?php echo wp_kses_post( $product->get_image( 'woocommerce_single' ) ); ?>
		<div>
			<p class="name"><?php echo esc_html( $product->get_name() ); ?></p>
			<div class="price">
				<?php echo wp_kses_post( wc_price( $offer['display'], array( 'currency' => $order->get_currency() ) ) ); ?>
				<?php if ( $offer['discount'] > 0 ) : ?>
					<span class="was"><?php echo wp_kses_post( wc_price( $offer['regular_display'], array( 'currency' => $order->get_currency() ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<div><?php echo wp_kses_post( wpautop( $product->get_short_description() ) ); ?></div>
		</div>
	</div>

	<?php if ( ! $error ) : ?>
	<form method="post" action="<?php echo esc_url( $action ); ?>">
		<?php wp_nonce_field( 'ocu_upsell_' . $order->get_id() ); ?>
		<button class="btn yes" type="submit" name="ocu_choice" value="accept"><?php esc_html_e( 'Yes, add to my order', 'ocu' ); ?></button>
		<button class="btn no" type="submit" name="ocu_choice" value="decline"><?php esc_html_e( 'No thanks, continue', 'ocu' ); ?></button>
	</form>
	<p class="note">
		<?php
		echo $last4
			? esc_html( sprintf( /* translators: %s: last 4 card digits */ __( 'Charged to your card ending in %s. Taxes may apply.', 'ocu' ), $last4 ) )
			: esc_html__( 'Charged to the card you just used. Taxes may apply.', 'ocu' );
		?>
	</p>
	<?php else : ?>
	<a class="btn no" style="text-align:center" href="<?php echo esc_url( $order->get_checkout_order_received_url() ); ?>"><?php esc_html_e( 'Continue to order confirmation', 'ocu' ); ?></a>
	<?php endif; ?>
</div></div>
</body>
</html>
		<?php
	}

	/**
	 * Same as offer_url() but without the "is there an offer?" check (used as the form action).
	 */
	private static function offer_url_raw( WC_Order $order ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => 1,
				'order_id'      => $order->get_id(),
				'key'           => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}
}
