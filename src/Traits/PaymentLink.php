<?php
/**
 * PaymentLink.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Traits;

use Exception;
use WC_Customer;
use WC_Order;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * PaymentLink trait.
 */
trait PaymentLink {
	/**
	 * Traits.
	 */
	use Order;

	/**
	 * Format order ID for payment link.
	 *
	 * @param WC_Order|WC_Customer $object
	 * @return string
	 */
	protected function format_order_id_for_payment_link( WC_Order|WC_Customer $object ) : string {
		switch ( true ) {
			case $object instanceof WC_Order:
				$id  = $object->get_id();
				$key = $object->get_order_key();
				break;
			case $object instanceof WC_Customer:
				$id  = $object->get_id();
				$key = 'add_payment_method_' . wp_generate_password( 13, false );
				break;
		}

		return sprintf( '%s-%s', $id, $key );
	}

	/**
	 * Get ID from order ID in incoming data.
	 *
	 * @param string $incoming_data_order_id
	 * @return int|null
	 */
	protected function get_id_from_incoming_data_order_id( string $incoming_data_order_id ) : ?int {
		$order_data = explode( '-', $incoming_data_order_id );

		return 2 === count( $order_data ) && isset( $order_data[0] ) ? intval( $order_data[0] ) : null;
	}

	/**
	 * Get WooCommerce order key from order ID in incoming data.
	 *
	 * @param string $incoming_data_order_id
	 * @return string|null
	 */
	protected function get_key_from_incoming_data_order_id( string $incoming_data_order_id ) : ?string {
		$order_data = explode( '-', $incoming_data_order_id );

		return 2 === count( $order_data ) && isset( $order_data[1] ) ? $order_data[1] : null;
	}

	/**
	 * Get WooCommerce order from incoming data.
	 *
	 * @param string $incoming_data_order_id
	 * @return WC_Order
	 * @throws Exception
	 */
	protected function get_wc_order_from_incoming_data( string $incoming_data_order_id ) : WC_Order {
		$order_id = $this->get_id_from_incoming_data_order_id( $incoming_data_order_id );

		if ( ! $order_id ) {
			throw new Exception( 'No valid order ID in incoming data.' );
		}

		$order = $this->get_wc_order( $order_id );

		if ( ! $order ) {
			throw new Exception( sprintf( 'Failed to find order. Order ID: %s.', $order_id ) );
		}

		if ( $order->get_order_key() !== $this->get_key_from_incoming_data_order_id( $incoming_data_order_id ) ) {
			throw new Exception( sprintf( 'Order key in incoming data is invalid. Order ID: %s.', $order_id ) );
		}

		return $order;
	}

	/**
	 * Get WooCommerce customer from incoming data.
	 *
	 * @param string $incoming_data_order_id
	 * @return WC_Customer
	 * @throws Exception
	 */
	protected function get_wc_customer_from_incoming_data( string $incoming_data_order_id ) : WC_Customer {
		$customer_id = $this->get_id_from_incoming_data_order_id( $incoming_data_order_id );

		if ( ! $customer_id ) {
			throw new Exception( 'No valid customer ID in incoming data.' );
		}

		$customer = $this->customer_factory->get_wc_customer( $customer_id );

		if ( ! $customer ) {
			throw new Exception( sprintf( 'Failed to find customer. Customer ID: %s.', $customer_id ) );
		}

		return $customer;
	}

	/**
	 * Check if the payment link was for a payment method.
	 *
	 * @param string $incoming_data_order_id
	 * @return bool
	 */
	public function is_for_payment_method( string $incoming_data_order_id ) : bool {
		$key = $this->get_key_from_incoming_data_order_id( $incoming_data_order_id );

		return $key && str_starts_with( $key, 'add_payment_method' );
	}

	/**
	 * Check if the payment link was for an order.
	 *
	 * @param string $incoming_data_order_id
	 * @return bool
	 */
	public function is_for_order( string $incoming_data_order_id ) : bool {
		return ! $this->is_for_payment_method( $incoming_data_order_id );
	}

	/**
	 * Get pay URL.
	 *
	 * @param string $link_id
	 * @return string
	 */
	protected function get_pay_url( string $link_id ) : string {
		return $this->settings_service->get_pay_url() . $link_id;
	}

	/**
	 * Get payment link error message.
	 *
	 * @param array $invalid_parameters
	 * @return string
	 */
	public function get_payment_link_error_message( array $invalid_parameters ) : string {
		$message = __( 'Payment link creation failed.', 'acquired-com-for-woocommerce' );

		if ( empty( $invalid_parameters ) ) {
			return $message;
		}

		$error_messages = [
			'customer.first_name'                => [
				'customer.first_name validation failed' => __( 'The first name provided for the customer is invalid. Please ensure it\'s correctly formatted and does not include unsupported characters.', 'acquired-com-for-woocommerce' ),
				'customer.first_name length must be less than or equal to 22' => __( 'The first name provided for the customer is invalid. Please ensure it\'s no longer than 22 characters.', 'acquired-com-for-woocommerce' ),
			],
			'customer.last_name'                 => [
				'customer.last_name validation failed' => __( 'The last name provided for the customer is invalid. Please ensure it\'s correctly formatted and does not include unsupported characters.', 'acquired-com-for-woocommerce' ),
				'customer.last_name length must be less than or equal to 22' => __( 'The last name provided for the customer is invalid. Please ensure it\'s correctly formatted and does not include unsupported characters.', 'acquired-com-for-woocommerce' ),
			],
			'customer.billing.address.city'      => [
				'customer.billing.address.city length must be greater than or equal to 1 and less than or equal to 40' => __( 'The city field in the billing address must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
			],
			'customer.billing.address.line_1'    => [
				'customer.billing.address.line_1 length must be less than or equal to 50' => __( 'The first line of the billing address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
			],
			'customer.billing.address.line_2'    => [
				'customer.billing.address.line_2 length must be less than or equal to 50' => __( 'The second line of the billing address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
			],
			'customer.billing.address.postcode'  => [
				'customer.billing.address.postcode length must be greater than or equal to 1 and less than or equal to 40' => __( 'The postcode must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
				'Acceptable Characters: ^[a-zA-Z0-9,.-\'/_ ]*$' => __( 'The postcode in the billing address contains invalid characters. Please use only standard letters and numbers.', 'acquired-com-for-woocommerce' ),
			],
			'customer.shipping.address.city'     => [
				'customer.shipping.address.city length must be greater than or equal to 1 and less than or equal to 40' => __( 'The city field in the shipping address must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
			],
			'customer.shipping.address.line_1'   => [
				'customer.shipping.address.line_1 length must be less than or equal to 50' => __( 'The first line of the shipping address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
			],
			'customer.shipping.address.line_2'   => [
				'customer.shipping.address.line_2 length must be less than or equal to 50' => __( 'The second line of the shipping address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
			],
			'customer.shipping.address.postcode' => [
				'customer.shipping.address.postcode length must be greater than or equal to 1 and less than or equal to 40' => __( 'The postcode must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
				'Acceptable Characters: ^[a-zA-Z0-9,.-\'/_ ]*$' => __( 'The postcode in the shipping address contains invalid characters. Please use only standard letters and numbers.', 'acquired-com-for-woocommerce' ),
			],
		];

		$errors = [];

		foreach ( $invalid_parameters as $error ) :
			$parameter = $error->parameter ?? '';
			$reason    = $error->reason ?? '';

			if ( isset( $error_messages[ $parameter ][ $reason ] ) ) {
				$errors[] = $error_messages[ $parameter ][ $reason ];
			}
		endforeach;

		if ( ! empty( $errors ) ) {
			$message  = __( 'There was a problem with your details. Please check the information you entered and try again.', 'acquired-com-for-woocommerce' );
			$message .= ' ' . __( 'Issues:', 'acquired-com-for-woocommerce' ) . ' ';
			$message .= join( ' ', $errors );
		}

		return $message;
	}
}
