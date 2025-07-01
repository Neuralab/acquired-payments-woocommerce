<?php
/**
 * AddressValidationService.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Services;

use WC_Order;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * AddressValidationService class.
 */
class AddressValidationService {
	/**
	 * Validation rules for customer fields.
	 *
	 * @var array
	 */
	private array $validation_rules = [
		'billing'  => [
			'first_name' => [
				[
					'rule'   => 'max_length',
					'length' => 35,
				],
				[ 'rule' => 'special_chars' ],
			],
			'last_name'  => [
				[
					'rule'   => 'max_length',
					'length' => 35,
				],
				[ 'rule' => 'special_chars' ],
			],
			'address'    => [
				[
					'rule'   => 'max_length',
					'length' => 100,
				],
				[
					'rule' => 'special_chars',
				],
			],
		],
		'shipping' => [
			'first_name' => [
				[
					'rule'   => 'max_length',
					'length' => 22,
				],
				[
					'rule' => 'special_chars',
				],
			],
			'last_name'  => [
				[
					'rule'   => 'max_length',
					'length' => 22,
				],
				[
					'rule' => 'special_chars',
				],
			],
			'address'    => [
				[
					'rule'   => 'max_length',
					'length' => 50,
				],
				[
					'rule' => 'special_chars',
				],
			],
		],
	];

	/**
	 * Get error message for a specific field and rule.
	 *
	 * @param string $address_type
	 * @param string $field_name
	 * @param string $rule_name
	 * @return string
	 */
	private function get_error_message( string $address_type, string $field_name, string $rule_name ) : string {
		$messages = [
			'billing'  => [
				'first_name' => [
					'max_length'    => __( 'Billing first name is too long', 'acquired-com-for-woocommerce' ),
					'special_chars' => __( 'Billing first name contains invalid characters', 'acquired-com-for-woocommerce' ),
				],
				'last_name'  => [
					'max_length'    => __( 'Billing last name is too long', 'acquired-com-for-woocommerce' ),
					'special_chars' => __( 'Billing last name contains invalid characters', 'acquired-com-for-woocommerce' ),
				],
			],
			'shipping' => [
				'first_name' => [
					'max_length'    => __( 'Shipping first name is too long', 'acquired-com-for-woocommerce' ),
					'special_chars' => __( 'Shipping first name contains invalid characters', 'acquired-com-for-woocommerce' ),
				],
				'last_name'  => [
					'max_length'    => __( 'Shipping last name is too long', 'acquired-com-for-woocommerce' ),
					'special_chars' => __( 'Shipping last name contains invalid characters', 'acquired-com-for-woocommerce' ),
				],
			],
		];

		return $messages[ $address_type ][ $field_name ][ $rule_name ] ?? __( 'Invalid value', 'acquired-com-for-woocommerce' );
	}

	/**
	 * Validate text for special characters.
	 *
	 * @param string $value
	 * @return bool
	 */
	private function validate_special_chars( string $value ) : bool {
		return (bool) preg_match( '/^[a-zA-Z\.\- `\']*$/', $value );
	}

	/**
	 * Validate string length.
	 *
	 * @param string $value
	 * @param int    $max_length
	 * @return bool
	 */
	private function validate_max_length( string $value, int $max_length ) : bool {
		return strlen( $value ) <= $max_length;
	}

	/**
	 * Validate address data.
	 *
	 * @param array  $data
	 * @param string $address_type
	 * @return array
	 */
	private function validate_address_data( array $data, string $address_type ) : array {
		$errors = [];

		foreach ( $data as $field => $value ) :
			if ( ! isset( $this->validation_rules[ $address_type ][ $field ] ) ) {
				continue;
			}

			$rules        = $this->validation_rules[ $address_type ][ $field ];
			$field_errors = [];

			foreach ( $rules as $rule_config ) :
				$is_valid  = false;
				$rule_name = $rule_config['rule'];
				$method    = sprintf( 'validate_%s', $rule_name );

				if ( 'max_length' === $rule_name ) {
					$is_valid = $this->{$method}( $value, $rule_config['length'] );
				} else {
					$is_valid = $this->{$method}( $value );
				}

				if ( ! $is_valid ) {
					$field_errors[ $rule_name ] = $this->get_error_message( $address_type, $field, $rule_name );
				}
			endforeach;

			if ( ! empty( $field_errors ) ) {
				$errors[ $field ] = $field_errors;
			}
		endforeach;

		return $errors;
	}

	/**
	 * Validate order address data.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public function validate_order_address_data( WC_Order $order ) : array {
		$errors = [
			'billing' => $this->validate_address_data( $order->get_address( 'billing' ), 'billing' ),
		];

		if ( $order->has_shipping_address() ) {
			$errors['shipping'] = $this->validate_address_data( $order->get_address( 'shipping' ), 'shipping' );
		}

		return array_filter( $errors );
	}

	public function validate_checkout_classic() : array {
		$posted_data = $_POST;

		$errors = [];

		$fields = [
			'billing'  => [
				'first_name' => sanitize_text_field( wp_unslash( $posted_data['billing_first_name'] ?? '' ) ),
				'last_name'  => sanitize_text_field( wp_unslash( $posted_data['billing_last_name'] ?? '' ) ),
			],
		];

		$billing = array_filter( $fields['billing'] );

		if ( $billing ) {
			$errors = $this->validate_address_data( $billing, 'billing' );
		}

		return array_filter( $errors );
	}
}
