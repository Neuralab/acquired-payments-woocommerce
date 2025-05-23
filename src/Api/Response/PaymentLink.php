<?php
/**
 * PaymentLink.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Api\Response;

use Exception;

/**
 * PaymentLink class.
 */
class PaymentLink extends Response {
	/**
	 * Validate data.
	 *
	 * @return void
	 * @throws Exception
	 */
	protected function validate_data() : void {
		parent::validate_data();

		if ( ! $this->get_body_field( 'link_id' ) ) {
			throw new Exception( 'Payment link ID not found in response.' );
		}
	}

	/**
	 * Get payment link ID.
	 *
	 * @return string|null
	 */
	public function get_link_id() : ?string {
		return $this->get_body_field( 'link_id' );
	}
}
