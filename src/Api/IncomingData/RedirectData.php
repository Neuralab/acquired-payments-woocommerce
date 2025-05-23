<?php
/**
 * RedirectData.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Api\IncomingData;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * RedirectData class.
 */
class RedirectData extends Data {
	/**
	 * Constructor.
	 *
	 * @param array $incoming_data
	 */
	public function __construct( array $incoming_data ) {
		$this->set_type( 'redirect' );
		$this->set_incoming_data( $incoming_data );
		$this->set_transaction_data( $incoming_data['transaction_id'], $incoming_data['status'], $incoming_data['order_id'] );
		$this->set_timestamp( (int) $incoming_data['timestamp'] );
	}
}
