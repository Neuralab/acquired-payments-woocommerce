<?php
/**
 * OrderServiceTest.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Tests\Unit\Services;

use AcquiredComForWooCommerce\Tests\Framework\TestCase;
use AcquiredComForWooCommerce\Tests\Framework\Traits\ApiClientMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\LoggerServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\Reflection;
use AcquiredComForWooCommerce\Services\OrderService;
use AcquiredComForWooCommerce\Tests\Framework\Traits\CustomerFactoryMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\CustomerServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\PaymentMethodServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\ScheduleServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\SettingsServiceMock;

/**
 * OrderServiceTest class.
 *
 * @covers \AcquiredComForWooCommerce\Services\OrderService
 */
class OrderServiceTest extends TestCase {
	/**
	 * Traits.
	 */
	use Reflection;
	use ApiClientMock;
	use CustomerServiceMock;
	use LoggerServiceMock;
	use PaymentMethodServiceMock;
	use ScheduleServiceMock;
	use SettingsServiceMock;
	use CustomerFactoryMock;

	/**
	 * OrderService class.
	 *
	 * @var OrderService
	 */
	private OrderService $service;

	/**
	 * Set up the test case.
	 *
	 * @return void
	 */
	protected function setUp() : void {
		parent::setUp();

		$this->mock_api_client();
		$this->mock_logger_service();
		$this->mock_customer_service();
		$this->mock_payment_method_service();
		$this->mock_schedule_service();
		$this->mock_settings_service();
		$this->mock_customer_factory();

		$this->service = new OrderService(
			$this->get_api_client(),
			$this->get_customer_service(),
			$this->get_logger_service(),
			$this->get_payment_method_service(),
			$this->get_schedule_service(),
			$this->get_settings_service(),
			$this->get_customer_factory()
		);

		$this->initialize_reflection( $this->service );
	}

	/**
	 * Test constructor.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::__construct
	 * @return void
	 */
	public function test_constructor() : void {
		$this->assertSame( $this->get_api_client(), $this->get_private_property_value( 'api_client' ) );
		$this->assertSame( $this->get_customer_service(), $this->get_private_property_value( 'customer_service' ) );
		$this->assertSame( $this->get_logger_service(), $this->get_private_property_value( 'logger_service' ) );
		$this->assertSame( $this->get_payment_method_service(), $this->get_private_property_value( 'payment_method_service' ) );
		$this->assertSame( $this->get_schedule_service(), $this->get_private_property_value( 'schedule_service' ) );
		$this->assertSame( $this->get_settings_service(), $this->get_private_property_value( 'settings_service' ) );
		$this->assertSame( $this->get_customer_factory(), $this->get_private_property_value( 'customer_factory' ) );
	}
}
