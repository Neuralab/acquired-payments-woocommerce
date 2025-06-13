<?php
/**
 * GetCustomerTraitTest.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Tests\Unit\Traits;

use AcquiredComForWooCommerce\Tests\Framework\TraitTestCase;
use AcquiredComForWooCommerce\Tests\Framework\TestClasses\GetCustomerTraitTest as TestClass;
use AcquiredComForWooCommerce\Tests\Framework\Traits\CustomerConstructorMock;

/**
 * Test GetCustomer trait.
 *
 * @covers \AcquiredComForWooCommerce\Traits\GetCustomer
 */
class GetCustomerTraitTest extends TraitTestCase {
	/**
	 * Traits
	 */
	use CustomerConstructorMock;

	/**
	 * Get the test class instance.
	 *
	 * @runInSeparateProcess
	 * @return object
	 */
	protected function get_test_class() : object {
		return new TestClass();
	}

	/**
	 * Test get WC customer with invalid ID.
	 *
	 * @runInSeparateProcess
	 * @covers \AcquiredComForWooCommerce\Traits\GetCustomer::get_wc_customer
	 */
	public function test_get_wc_customer_with_invalid_id() : void {
		$customer = $this->mock_wc_customer_constructor( 123, 'Invalid customer' );
		$customer->shouldReceive( 'get_id' )->never();

		$this->assertNull( $this->get_private_method_value( 'get_wc_customer', 123 ) );
	}

	/**
	 * Test get WC customer with valid ID.
	 *
	 * @runInSeparateProcess
	 * @covers \AcquiredComForWooCommerce\Traits\GetCustomer::get_wc_customer
	 */
	public function test_get_wc_customer_with_valid_id() : void {
		// Mock WC_Customer constructor.
		$customer = $this->mock_wc_customer_constructor( 123 );

		// Test the response.
		$customer->allows( 'get_id' )->andReturn( 123 );
		$result = $this->get_private_method_value( 'get_wc_customer', 123 );
		$this->assertInstanceOf( 'WC_Customer', $result );
		$this->assertEquals( 123, $result->get_id() );
	}
}
