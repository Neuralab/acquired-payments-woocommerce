<?php
/**
 * Validation.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Services;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Validation class.
 */
class Validation {
	/**
	 * Check if a string contains only letters, dots, hyphens, backticks, and single quotes.
	 *
	 * @param string $value
	 * @return bool
	 */
	private function is_valid_special_text( string $value ): bool {
		return (bool) preg_match( '/^[a-zA-Z\.\- `\']*$/', $value );
	}

	/**
	 * Check if a string length is less than or equal to the maximum length.
	 *
	 * @param string $value
	 * @param int    $max_length
	 * @return bool
	 */
	private function is_length_valid( string $value, int $max_length ): bool {
		return strlen( $value ) <= $max_length;
	}
}
