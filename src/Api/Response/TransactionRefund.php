<?php
/**
 * TransactionRefund.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Api\Response;

/**
 * TransactionRefund class.
 */
class TransactionRefund extends TransactionAction {
	/**
	 * Check if transaction is refunded.
	 *
	 * @return bool
	 */
	public function is_refunded() : bool {
		return $this->action_is_successful();
	}
}
