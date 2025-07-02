<?php
/**
 * AddressValidationService.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Services;

use WC_Customer;
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
		'billing_first_name'  => [
			[
				'rule'   => 'max_length',
				'length' => 22,
			],
			[
				'rule' => 'name',
			],
		],
		'billing_last_name'   => [
			[
				'rule'   => 'max_length',
				'length' => 22,
			],
			[
				'rule' => 'name',
			],
		],
		'billing_address_1'   => [
			[
				'rule'   => 'max_length',
				'length' => 50,
			],
			[
				'rule' => 'address',
			],
		],
		'billing_address_2'   => [
			[
				'rule'   => 'max_length',
				'length' => 50,
			],
			[
				'rule' => 'address',
			],
		],
		'billing_city'        => [
			[
				'rule'   => 'max_length',
				'length' => 40,
			],
			[
				'rule' => 'address',
			],
		],
		'billing_postcode'    => [
			[
				'rule'   => 'max_length',
				'length' => 40,
			],
		],
		'shipping_first_name' => [
			[
				'rule'   => 'max_length',
				'length' => 22,
			],
			[
				'rule' => 'name',
			],
		],
		'shipping_last_name'  => [
			[
				'rule'   => 'max_length',
				'length' => 22,
			],
			[
				'rule' => 'name',
			],
		],
		'shipping_address_1'  => [
			[
				'rule'   => 'max_length',
				'length' => 50,
			],
			[
				'rule' => 'address',
			],
		],
		'shipping_address_2'  => [
			[
				'rule'   => 'max_length',
				'length' => 50,
			],
			[
				'rule' => 'address',
			],
		],
		'shipping_city'       => [
			[
				'rule'   => 'max_length',
				'length' => 40,
			],
			[
				'rule' => 'address',
			],
		],
		'shipping_postcode'   => [
			[
				'rule'   => 'max_length',
				'length' => 40,
			],
		],
	];

	/**
	 * Get error message for a specific field and rule.
	 *
	 * @param string $field_key
	 * @param string $rule_name
	 * @return string
	 */
	private function get_error_message( string $field_key, string $rule_name ) : string {
		$messages = [
			'billing_first_name'  => [
				'max_length' => __( 'The first name provided for the billing address is invalid. Please ensure it\'s no longer than 22 characters.', 'acquired-com-for-woocommerce' ),
				'name'       => __( 'The first name provided for the billing address is invalid. Please ensure it\'s correctly formatted and does not include unsupported characters.', 'acquired-com-for-woocommerce' ),
			],
			'billing_last_name'   => [
				'max_length' => __( 'The last name provided for the billing address is invalid. Please ensure it\'s no longer than 22 characters.', 'acquired-com-for-woocommerce' ),
				'name'       => __( 'The last name provided for the billing address is invalid. Please ensure it\'s correctly formatted and does not include unsupported characters.', 'acquired-com-for-woocommerce' ),
			],
			'billing_address_1'   => [
				'max_length' => __( 'The first line of the billing address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
				'address'    => __( 'The first line of the billing address contains invalid characters.', 'acquired-com-for-woocommerce' ),
			],
			'billing_address_2'   => [
				'max_length' => __( 'The second line of the billing address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
				'address'    => __( 'The second line of the billing address contains invalid characters.', 'acquired-com-for-woocommerce' ),
			],
			'billing_city'        => [
				'max_length' => __( 'The city field in the billing address must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
				'address'    => __( 'The city field in the billing address contains invalid characters.', 'acquired-com-for-woocommerce' ),
			],
			'billing_postcode'    => [
				'max_length' => __( 'The postcode in the billing address must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
			],
			'shipping_first_name' => [
				'max_length' => __( 'The first name provided for the shipping address is invalid. Please ensure it\'s no longer than 22 characters.', 'acquired-com-for-woocommerce' ),
				'name'       => __( 'The first name provided for the shipping address is invalid. Please ensure it\'s correctly formatted and does not include unsupported characters.', 'acquired-com-for-woocommerce' ),
			],
			'shipping_last_name'  => [
				'max_length' => __( 'The last name provided for the shipping address is invalid. Please ensure it\'s no longer than 22 characters.', 'acquired-com-for-woocommerce' ),
				'name'       => __( 'The last name provided for the shipping address is invalid. Please ensure it\'s correctly formatted and does not include unsupported characters.', 'acquired-com-for-woocommerce' ),
			],
			'shipping_address_1'  => [
				'max_length' => __( 'The first line of the shipping address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
				'address'    => __( 'The first line of the shipping address contains invalid characters.', 'acquired-com-for-woocommerce' ),
			],
			'shipping_address_2'  => [
				'max_length' => __( 'The second line of the shipping address must be between 1 and 50 characters long.', 'acquired-com-for-woocommerce' ),
				'address'    => __( 'The second line of the shipping address contains invalid characters.', 'acquired-com-for-woocommerce' ),
			],
			'shipping_city'       => [
				'max_length' => __( 'The city field in the shipping address must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
				'address'    => __( 'The city field in the shipping address contains invalid characters.', 'acquired-com-for-woocommerce' ),
			],
			'shipping_postcode'   => [
				'max_length' => __( 'The postcode in the shipping address must be between 1 and 40 characters long.', 'acquired-com-for-woocommerce' ),
			],
		];

		return $messages[ $field_key ][ $rule_name ] ?? __( 'Invalid value', 'acquired-com-for-woocommerce' );
	}

	/**
	 * Validate name.
	 *
	 * @param string $value
	 * @return bool
	 */
	private function validate_name( string $value ) : bool {
		return (bool) preg_match( '/^[\p{L}\.\- `\']++$/u', $value );
	}

	/**
	 * Validate address.
	 *
	 * @param string $value
	 * @return bool
	 */
	private function validate_address( string $value ) : bool {
		return (bool) preg_match( '/^[\p{L}\p{N}\.,\/\-\& ]++$/u', $value );
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
	 * Validate field.
	 *
	 * @param string $field_key
	 * @param string $value
	 * @return array|null
	 */
	private function validate_field( string $field_key, string $value ) : ?array {
		$errors = [];

		foreach ( $this->validation_rules[ $field_key ] as $rule_config ) :
			$is_valid = false;
			$rule     = $rule_config['rule'];
			$method   = sprintf( 'validate_%s', $rule );

			if ( 'max_length' === $rule ) {
				$is_valid = $this->{$method}( $value, $rule_config['length'] );
			} else {
				$is_valid = $this->{$method}( $value );
			}

			if ( ! $is_valid ) {
				$errors[ $rule ] = $this->get_error_message( $field_key, $rule );
			}
		endforeach;

		return ! empty( $errors ) ? $errors : null;
	}

	/**
	 * Validate classic checkout address data.
	 *
	 * @param array $data
	 * @return array
	 */
	public function validate_checkout_classic_address_data( $data ) : array {
		$errors           = [];
		$validation_rules = $this->validation_rules;

		if ( empty( $data['ship_to_different_address'] ) ) {
			$validation_rules = array_filter(
				$validation_rules,
				function ( $key ) {
					return strpos( $key, 'shipping_' ) === false;
				},
				ARRAY_FILTER_USE_KEY
			);
		}

		foreach ( $validation_rules as $field_key => $rules ) {
			if ( empty( $data[ $field_key ] ) ) {
				continue;
			}

			$field_errors = $this->validate_field( $field_key, $data[ $field_key ] );

			if ( $field_errors ) {
				$errors[ $field_key ] = $field_errors;
			}
		}

		return $errors;
	}

	/**
	 * Validate block checkout address data.
	 *
	 * @param array $data
	 * @return array
	 */
	public function validate_checkout_block_address_data( array $data ) : array {
		$errors = [];

		return $errors;
	}

	/**
	 * Validate order address data.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public function validate_order_address_data( WC_Order $order ) : array {
		$errors = [];

		return $errors;
	}

	/**
	 * Validate customer address data.
	 *
	 * @param WC_Customer $customer
	 * @return array
	 */
	public function validate_customer_address_data( WC_Customer $customer ) : array {
		$errors = [];

		return $errors;
	}
}
