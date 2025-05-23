<?php
/**
 * TransactionCancel.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Api\Response;

/**
 * TransactionCancel class.
 */
class TransactionCancel extends TransactionAction {
	/**
	 * Check if transaction is cancelled.
	 *
	 * @return bool
	 */
	public function is_cancelled() : bool {
		return $this->action_is_successful();
	}
}
