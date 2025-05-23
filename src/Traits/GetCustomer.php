<?php
/**
 * GetCustomer.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Traits;

use Exception;
use WC_Customer;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * GetCustomer trait.
 */
trait GetCustomer {
	/**
	 * Get WooCommerce customer object.
	 *
	 * @param int $user_id The order ID
	 * @return WC_Customer|null WooCommerce customer object or null if invalid
	 */
	protected function get_wc_customer( $user_id ) : WC_Customer|null {
		try {
			$customer = new WC_Customer( $user_id );
			return $customer;
		} catch ( Exception $exception ) {
			return null;
		}
	}
}
