<?php
/**
 * PaymentMethodServiceMock.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Tests\Framework\Traits;

use AcquiredComForWooCommerce\Api\ApiClient;
use AcquiredComForWooCommerce\Services\PaymentMethodService;
use AcquiredComForWooCommerce\Services\LoggerService;
use AcquiredComForWooCommerce\Services\CustomerService;
use AcquiredComForWooCommerce\Services\ScheduleService;
use AcquiredComForWooCommerce\Services\SettingsService;
use Mockery;
use Mockery\MockInterface;

/**
 * PaymentMethodServiceMock.
 */
trait PaymentMethodServiceMock {
	/**
	 * PaymentMethodService mock.
	 *
	 * @var MockInterface&PaymentMethodService
	 */
	protected MockInterface $payment_method_service;

	/**
	 * Create and configure PaymentMethodService mock.
	 *
	 * @return void
	 */
	protected function mock_payment_method_service() : void {
		$this->payment_method_service = Mockery::mock(
			PaymentMethodService::class,
			[
				Mockery::mock( ApiClient::class ),
				Mockery::mock( CustomerService::class ),
				Mockery::mock( LoggerService::class ),
				Mockery::mock( ScheduleService::class ),
				Mockery::mock( SettingsService::class ),
				'WC_Payment_Token_CC',
				'WC_Payment_Tokens',
			]
		);
	}

	/**
	 * Get PaymentMethodService.
	 *
	 * @return MockInterface&PaymentMethodService
	 */
	public function get_payment_method_service() : MockInterface {
		return $this->payment_method_service;
	}
}
