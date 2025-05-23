<?php
/**
 * TransactionCapture.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Api\Response;

/**
 * TransactionCapture class.
 */
class TransactionCapture extends TransactionAction {
	/**
	 * Check if transaction is captured.
	 *
	 * @return bool
	 */
	public function is_captured() : bool {
		return $this->action_is_successful();
	}
}
