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
use AcquiredComForWooCommerce\Tests\Framework\Traits\CustomerFactoryMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\CustomerServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\PaymentMethodServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\ScheduleServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\SettingsServiceMock;
use AcquiredComForWooCommerce\Services\OrderService;
use AcquiredComForWooCommerce\Api\Response\PaymentLink;
use AcquiredComForWooCommerce\Api\Response\Transaction;
use AcquiredComForWooCommerce\Api\IncomingData\WebhookData;
use AcquiredComForWooCommerce\Api\IncomingData\RedirectData;
use Exception;
use Mockery;
use Mockery\MockInterface;
use Brain\Monkey\Functions;
use WC_Order;

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
	 * Mock get_payment_link_default_body creation.
	 *
	 * @return void
	 */
	private function mock_get_payment_link_default_body_creation() : void {
		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_payment_link_default_body' )
			->once()
			->andReturn(
				[
					'transaction' => [
						'currency' => 'gbp',
						'custom1'  => '2.0.0',
					],
					'payment'     => [
						'reference' => 'Test Store',
					],
					'count_retry' => 1,
				]
			);

		// Mock SettingsService.

		$this->get_settings_service()
			->shouldReceive( 'get_option' )
			->once()
			->with( 'transaction_type', 'capture' )
			->andReturn( 'capture' );

		$this->get_settings_service()
			->shouldReceive( 'get_wc_api_url' )
			->once()
			->with( 'redirect-new-order' )
			->andReturn( 'https://example.com/wc-api/acquired-com-for-woocommerce-redirect-new-order' );

		$this->get_settings_service()
			->shouldReceive( 'get_wc_api_url' )
			->once()
			->with( 'webhook' )
			->andReturn( 'https://example.com/wc-api/acquired-com-for-woocommerce-webhook' );

		$this->get_settings_service()
			->shouldReceive( 'get_option' )
			->with( 'submit_type', 'pay' )
			->once()
			->andReturn( 'pay' );

		$this->get_settings_service()
			->shouldReceive( 'get_wc_hold_stock_time' )
			->once()
			->andReturn( 120 );

		$this->get_settings_service()
			->shouldReceive( 'get_payment_link_max_expiration_time' )
			->once()
			->andReturn( 3600 );
	}

	/**
	 * Mock set additional order data.
	 *
	 * @param MockInterface&WC_Order $order
	 * @param MockInterface&Transaction $transaction
	 * @return void
	 */
	private function mock_set_additional_order_data( $order, $transaction ) : void {
		// Mock WC_Order.
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'update_meta_data' )->with( '_acfw_transaction_payment_method', 'card' );
		$order->shouldReceive( 'add_order_note' )->with( 'Transaction (ID: transaction_123) payment method: "card".' );
		$order->shouldReceive( 'delete_meta_data' )->with( '_acfw_transaction_decline_reason' );
		$order->shouldReceive( 'save' )->once();

		// Mock Transaction.
		$transaction->shouldReceive( 'get_payment_method' )->andReturn( 'card' );
		$transaction->shouldReceive( 'get_transaction_id' )->andReturn( 'transaction_123' );
		$transaction->shouldReceive( 'get_decline_reason' )->andReturn( null );
		$transaction->shouldReceive( 'get_log_data' )->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Order additional data set successfully. Order ID: 123.',
				'debug',
				[]
			);
	}

	/**
	 * Mock order for processing.
	 *
	 * @param string $transaction_type
	 * @param string $transaction_status
	 * @param int $order_id
	 * @param string $transaction_id
	 * @param int $timestamp
	 * @return MockInterface&WC_Order
	 */
	private function mock_order_for_processing( string $transaction_type, string $transaction_status, int $order_id, string $transaction_id, int $timestamp ) : MockInterface {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->times( 5 )->andReturn( $order_id );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( null );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( $transaction_id );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_order_time_updated' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_transaction_status' )->once()->andReturn( $transaction_status );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'failed', 'on-hold' ] )->andReturn( true );
		$order->shouldReceive( 'set_transaction_id' )->once()->with( $transaction_id );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_transaction_status', $transaction_status );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_time_updated', $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_version', '2.0.0' );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Order processed successfully from incoming webhook data.' );
		$order->shouldReceive( 'save' )->twice();

		if ( in_array( $transaction_status, [ 'success', 'settled' ], true ) ) {
			$order->shouldReceive( 'get_meta' )->with( '_acfw_transaction_type' )->once()->andReturn( $transaction_type );
		}

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( $order_id )
			->andReturn( $order );

		return $order;
	}

	/**
	 * Mock transaction for processing.
	 *
	 * @param string $transaction_id
	 * @param string $status
	 * @param int $timestamp
	 * @return MockInterface&Transaction
	 */
	private function mock_transaction_for_processing( string $transaction_id, string $status, int $timestamp ) : MockInterface {
		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$transaction->shouldReceive( 'get_status' )->once()->andReturn( $status );
		$transaction->shouldReceive( 'get_created_timestamp' )->twice()->andReturn( $timestamp );
		$transaction->shouldReceive( 'get_log_data' )->twice()->andReturn( [] );
		$transaction->shouldReceive( 'request_is_error' )->once()->andReturn( false );
		$transaction->shouldReceive( 'get_payment_method' )->twice()->andReturn( 'card' );
		$transaction->shouldReceive( 'get_decline_reason' )->once()->andReturn( '' );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( $transaction_id )
			->andReturn( $transaction );

		return $transaction;
	}

	/**
	 * Mock log for processing.
	 *
	 * @return void
	 */
	private function mock_log_for_processing( int $order_id ) : void {
		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->times( 3 )
			->withArgs(
				function( $message, $level ) use ( $order_id ) {
					return in_array(
						$message,
						[
							sprintf( 'Order found successfully from incoming webhook data. Order ID: %d.', $order_id ),
							sprintf( 'Transaction found successfully from incoming webhook data. Order ID: %d.', $order_id ),
							sprintf( 'Order processed successfully from incoming webhook data. Order ID: %d.', $order_id ),
						],
						true
					) && 'debug' === $level;
				}
			);
	}

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

	/**
	 * Test is_capture method.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::is_capture
	 * @return void
	 */
	public function test_is_capture() : void {
		// Mock SettingsService.
		$this->get_settings_service()
			->shouldReceive( 'get_option' )
			->once()
			->with( 'transaction_type', 'capture' )
			->andReturn( 'capture' );

		// Test the method.
		$this->assertTrue( $this->get_private_method_value( 'is_capture' ) );
	}

	/**
	 * Test is_capture method with authorization.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::is_capture
	 * @return void
	 */
	public function test_is_capture_with_authorization() : void {
		// Mock SettingsService.
		$this->get_settings_service()
			->shouldReceive( 'get_option' )
			->once()
			->with( 'transaction_type', 'capture' )
			->andReturn( 'authorization' );

		// Test the method.
		$this->assertFalse( $this->get_private_method_value( 'is_capture' ) );
	}

	/**
	 * Test set_transaction_type method.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::set_transaction_type
	 * @return void
	 */
	public function test_set_transaction_type() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_transaction_type', 'capture' );
		$order->shouldReceive( 'save' )->once();

		// Mock SettingsService.
		$this->get_settings_service()
			->shouldReceive( 'get_option' )
			->once()
			->with( 'transaction_type', 'capture' )
			->andReturn( 'capture' );

		// Test the method.
		$this->set_private_method_value( 'set_transaction_type', $order );
	}

	/**
	 * Test set_transaction_type method with authorization.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::set_transaction_type
	 * @return void
	 */
	public function test_set_transaction_type_with_authorization() : void {
		// Mock SettingsService.
		$this->get_settings_service()
			->shouldReceive( 'get_option' )
			->once()
			->with( 'transaction_type', 'capture' )
			->andReturn( 'authorization' );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_transaction_type', 'authorization' );
		$order->shouldReceive( 'save' )->once();

		// Test the method.
		$this->set_private_method_value( 'set_transaction_type', $order );
	}

	/**
	 * Test get_scheduled_action_hook method.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_scheduled_action_hook
	 * @return void
	 */
	public function test_get_scheduled_action_hook() : void {
		$this->assertEquals( 'acfw_scheduled_process_order', $this->service->get_scheduled_action_hook() );
	}

	/**
	 * Test order_transaction_id_processed method when transaction ID matches.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::order_transaction_id_processed
	 * @return void
	 */
	public function test_order_transaction_id_processed_with_matching_ids() : void {
		$this->assertTrue( $this->get_private_method_value( 'order_transaction_id_processed', 'transaction_123', 'transaction_123' ) );
	}

	/**
	 * Test order_transaction_id_processed method when transaction ID doesn't match.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::order_transaction_id_processed
	 * @return void
	 */
	public function test_order_transaction_id_processed_with_different_ids() : void {
		$this->assertFalse( $this->get_private_method_value( 'order_transaction_id_processed', 'transaction_123', 'transaction_456' ) );
	}

	/**
	 * Test order_transaction_id_processed method with null order transaction ID.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::order_transaction_id_processed
	 * @return void
	 */
	public function test_order_transaction_id_processed_with_null_order_id() : void {
		$this->assertFalse( $this->get_private_method_value( 'order_transaction_id_processed', 'transaction_123', null ) );
	}

	/**
	 * Test is_day_older method with older timestamp.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::is_day_older
	 * @return void
	 */
	public function test_is_day_older_with_older_timestamp() : void {
		$this->assertTrue( $this->service->is_day_older( strtotime( '-1 day' ) ) );
	}

	/**
	 * Test is_day_older method with same day timestamp.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::is_day_older
	 * @return void
	 */
	public function test_is_day_older_with_same_day() : void {
		$this->assertFalse( $this->service->is_day_older( strtotime( 'today' ) ) );
	}

	/**
	 * Test is_day_older method with future timestamp.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::is_day_older
	 * @return void
	 */
	public function test_is_day_older_with_future_timestamp() : void {
		$this->assertFalse( $this->service->is_day_older( strtotime( '+1 day' ) ) );
	}

	/**
	 * Test get_transaction method with successful response.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_transaction
	 * @return void
	 */
	public function test_get_transaction_with_successful_response() : void {
		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'request_is_error' )->once()->andReturn( false );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( 'transaction_123' )
			->andReturn( $transaction );

		// Test the method.
		$this->assertSame( $transaction, $this->get_private_method_value( 'get_transaction', 'transaction_123' ) );
	}

	/**
	 * Test get_transaction method with error response.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_transaction
	 * @return void
	 */
	public function test_get_transaction_with_error_response() : void {
		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'request_is_error' )->once()->andReturn( true );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( 'transaction_123' )
			->andReturn( $transaction );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to get transaction.' );
		$this->get_private_method_value( 'get_transaction', 'transaction_123' );
	}

	/**
	 * Test get_transaction_time_updated method with successful response.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_transaction_time_updated
	 * @return void
	 */
	public function test_get_transaction_time_updated_with_successful_response() : void {
		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'request_is_error' )->once()->andReturn( false );
		$transaction->shouldReceive( 'get_created_timestamp' )->once()->andReturn( 1234567890 );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( 'transaction_123' )
			->andReturn( $transaction );

		// Test the method.
		$this->assertEquals( 1234567890, $this->get_private_method_value( 'get_transaction_time_updated', 'transaction_123' ) );
	}

	/**
	 * Test get_transaction_time_updated method with error response.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_transaction_time_updated
	 * @return void
	 */
	public function test_get_transaction_time_updated_with_error_response() : void {
		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( 'transaction_123' )
			->andThrow( new Exception( 'Failed to get transaction.' ) );

		// Mock the time function to return a fixed timestamp.
		Functions\expect( 'time' )
			->once()
			->andReturn( 1234567890 );

		// Test the method.
		$this->assertEquals( 1234567890, $this->get_private_method_value( 'get_transaction_time_updated', 'transaction_123' ) );
	}

	/**
	 * Test can_be_processed method when order can be processed.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_processed
	 * @return void
	 */
	public function test_can_be_processed_with_processable_order() : void {
		// Mock WC_Order with 'authorised' state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( 'authorised' );
		$this->assertTrue( $this->get_private_method_value( 'can_be_processed', $order ) );

		// Mock WC_Order with 'failed' state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( 'failed' );
		$this->assertTrue( $this->get_private_method_value( 'can_be_processed', $order ) );

		// Mock WC_Order with no state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( '' );
		$this->assertTrue( $this->get_private_method_value( 'can_be_processed', $order ) );
	}

	/**
	 * Test can_be_processed method when order can't be processed.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_processed
	 * @return void
	 */
	public function test_can_be_processed_with_non_processable_states() : void {
		// Mock WC_Order with 'completed' state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( 'completed' );
		$this->assertFalse( $this->get_private_method_value( 'can_be_processed', $order ) );

		// Mock WC_Order with 'cancelled' state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( 'cancelled' );
		$this->assertFalse( $this->get_private_method_value( 'can_be_processed', $order ) );

		// Mock WC_Order with 'executed' state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( 'executed' );
		$this->assertFalse( $this->get_private_method_value( 'can_be_processed', $order ) );

		// Mock WC_Order with 'refunded_full' state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( 'refunded_full' );
		$this->assertFalse( $this->get_private_method_value( 'can_be_processed', $order ) );

		// Mock WC_Order with 'refunded_partial' state.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( 'refunded_partial' );
		$this->assertFalse( $this->get_private_method_value( 'can_be_processed', $order ) );
	}

	/**
	 * Test get_payment_link_expiration_time method when hold stock is disabled.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link_expiration_time
	 * @return void
	 */
	public function test_get_payment_link_expiration_time_with_disabled_hold_stock() : void {
		// Mock WC_Order
		$order = Mockery::mock( 'WC_Order' );

		// Mock SettingsService.

		$this->get_settings_service()
			->shouldReceive( 'get_wc_hold_stock_time' )
			->once()
			->andReturn( 0 );

		$this->get_settings_service()
			->shouldReceive( 'get_payment_link_expiration_time' )
			->once()
			->andReturn( 3600 );

		// Test the method.
		$this->assertEquals( 3600, $this->get_private_method_value( 'get_payment_link_expiration_time', $order ) );
	}

	/**
	 * Test get_payment_link_expiration_time method with hold stock enabled.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link_expiration_time
	 * @return void
	 */
	public function test_get_payment_link_expiration_time_with_enabled_hold_stock() : void {
		// Mock WC_DateTime.
		$modified_time = time() - 300; // 5 minutes ago.
		$date_modified = Mockery::mock( 'WC_DateTime' );
		$date_modified->shouldReceive( 'getTimestamp' )->once()->andReturn( $modified_time );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_date_modified' )->once()->andReturn( $date_modified );

		// Mock SettingsService.
		$this->get_settings_service()
			->shouldReceive( 'get_wc_hold_stock_time' )
			->once()
			->andReturn( 60 ); // 60 minutes.

		$this->get_settings_service()
			->shouldReceive( 'get_payment_link_max_expiration_time' )
			->once()
			->andReturn( 7200 ); // 2 hours.

		// Should be close to 55 minutes (60 minutes - 5 minutes elapsed).
		$this->assertEqualsWithDelta( 3300, $this->get_private_method_value( 'get_payment_link_expiration_time', $order ), 5 );
	}

	/**
	 * Test get_payment_link_expiration_time method with max expiration limit.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link_expiration_time
	 * @return void
	 */
	public function test_get_payment_link_expiration_time_with_max_limit() : void {
		// Mock WC_DateTime.
		$modified_time = time() - 300; // 5 minutes ago.
		$date_modified = Mockery::mock( 'WC_DateTime' );
		$date_modified->shouldReceive( 'getTimestamp' )->once()->andReturn( $modified_time );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_date_modified' )->once()->andReturn( $date_modified );

		// Mock SettingsService.

		$this->get_settings_service()
			->shouldReceive( 'get_wc_hold_stock_time' )
			->once()
			->andReturn( 120 ); // 120 minutes.

		$this->get_settings_service()
			->shouldReceive( 'get_payment_link_max_expiration_time' )
			->once()
			->andReturn( 3600 ); // 1 hour.

		// Test the method.
		$this->assertEquals( 3600, $this->get_private_method_value( 'get_payment_link_expiration_time', $order ) );
	}

	/**
	 * Test get_payment_link_body.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link_body
	 * @return void
	 */
	public function test_get_payment_link_body() : void {
		// Mock WC_DateTime.
		$modified_time = time() - 300; // 5 minutes ago.
		$date_modified = Mockery::mock( 'WC_DateTime' );
		$date_modified->shouldReceive( 'getTimestamp' )->once()->andReturn( $modified_time );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_total' )->once()->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->once()->andReturn( 'GBP' );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 123 );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_date_modified' )->once()->andReturn( $date_modified );

		// Mock link body creation.
		$this->mock_get_payment_link_default_body_creation();

		// Mock CustomerService.
		$this->get_customer_service()
			->shouldReceive( 'get_customer_data_for_checkout' )
			->with( $order )
			->once()
			->andReturn( [] );

		// Mock PaymentMethodService.
		$this->get_payment_method_service()
			->shouldReceive( 'get_payment_method_for_checkout' )
			->with( $order )
			->once()
			->andReturn( null );

		// Set expected response.
		$expected = [
			'transaction'  => [
				'currency' => 'gbp',
				'custom1'  => '2.0.0',
				'order_id' => '123-wc_order_key',
				'amount'   => 100.00,
				'capture'  => true,
			],
			'payment'      => [
				'reference' => 'Test Store',
			],
			'count_retry'  => 1,
			'redirect_url' => 'https://example.com/wc-api/acquired-com-for-woocommerce-redirect-new-order',
			'webhook_url'  => 'https://example.com/wc-api/acquired-com-for-woocommerce-webhook',
			'submit_type'  => 'pay',
			'expires_in'   => 3600,
		];

		$this->assertEquals( $expected, $this->get_private_method_value( 'get_payment_link_body', $order ) );
	}

	/**
	 * Test get_payment_link_body with customer data.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link_body
	 * @return void
	 */
	public function test_get_payment_link_body_with_customer_data() : void {
		// Mock WC_DateTime.
		$modified_time = time() - 300; // 5 minutes ago.
		$date_modified = Mockery::mock( 'WC_DateTime' );
		$date_modified->shouldReceive( 'getTimestamp' )->once()->andReturn( $modified_time );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_total' )->once()->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->once()->andReturn( 'GBP' );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 123 );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_date_modified' )->once()->andReturn( $date_modified );

		// Mock link body creation.
		$this->mock_get_payment_link_default_body_creation();

		// Mock CustomerService.
		$this->get_customer_service()
			->shouldReceive( 'get_customer_data_for_checkout' )
			->with( $order )
			->once()
			->andReturn( [ 'customer_id' => '789' ] );

		// Mock PaymentMethodService.
		$this->get_payment_method_service()
			->shouldReceive( 'get_payment_method_for_checkout' )
			->with( $order )
			->once()
			->andReturn( null );

		// Set expected response.
		$expected = [
			'transaction'  => [
				'currency' => 'gbp',
				'custom1'  => '2.0.0',
				'order_id' => '123-wc_order_key',
				'amount'   => 100.00,
				'capture'  => true,
			],
			'payment'      => [
				'reference' => 'Test Store',
			],
			'count_retry'  => 1,
			'redirect_url' => 'https://example.com/wc-api/acquired-com-for-woocommerce-redirect-new-order',
			'webhook_url'  => 'https://example.com/wc-api/acquired-com-for-woocommerce-webhook',
			'submit_type'  => 'pay',
			'expires_in'   => 3600,
			'customer'     => [ 'customer_id' => '789' ],
		];

		$this->assertEquals( $expected, $this->get_private_method_value( 'get_payment_link_body', $order ) );
	}

	/**
	 * Test get_payment_link_body with customer data.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link_body
	 * @return void
	 */
	public function test_get_payment_link_body_with_payment_method_data() : void {
		// Mock WC_DateTime.
		$modified_time = time() - 300; // 5 minutes ago.
		$date_modified = Mockery::mock( 'WC_DateTime' );
		$date_modified->shouldReceive( 'getTimestamp' )->once()->andReturn( $modified_time );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_total' )->once()->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->once()->andReturn( 'GBP' );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 123 );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_date_modified' )->once()->andReturn( $date_modified );

		// Mock link body creation.
		$this->mock_get_payment_link_default_body_creation();

		// Mock CustomerService.
		$this->get_customer_service()
			->shouldReceive( 'get_customer_data_for_checkout' )
			->with( $order )
			->once()
			->andReturn( [ 'customer_id' => '789' ] );

		// Mock PaymentMethodService.
		$this->get_payment_method_service()
			->shouldReceive( 'get_payment_method_for_checkout' )
			->with( $order )
			->once()
			->andReturn( 'token_123' );

		// Set expected response.
		$expected = [
			'transaction'  => [
				'currency' => 'gbp',
				'custom1'  => '2.0.0',
				'order_id' => '123-wc_order_key',
				'amount'   => 100.00,
				'capture'  => true,
			],
			'payment'      => [
				'reference' => 'Test Store',
				'card_id'   => 'token_123',
			],
			'count_retry'  => 1,
			'redirect_url' => 'https://example.com/wc-api/acquired-com-for-woocommerce-redirect-new-order',
			'webhook_url'  => 'https://example.com/wc-api/acquired-com-for-woocommerce-webhook',
			'submit_type'  => 'pay',
			'expires_in'   => 3600,
			'customer'     => [ 'customer_id' => '789' ],
		];

		$this->assertEquals( $expected, $this->get_private_method_value( 'get_payment_link_body', $order ) );
	}

	/**
	 * Test get_payment_link method with non-existent order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link
	 * @return void
	 */
	public function test_get_payment_link_with_non_existent_order() : void {
		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 123 )
			->andReturn( false );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Failed to find order. Order ID: 123.',
				'error'
			);

		// Test the method
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to find order.' );
		$this->service->get_payment_link( 123 );
	}

	/**
	 * Test get_payment_link method with already processed order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link
	 * @return void
	 */
	public function test_get_payment_link_with_processed_order() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 123 );
		$order->shouldReceive( 'get_meta' )->twice()->with( '_acfw_order_state' )->andReturn( 'completed' );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Payment link creation failed. Order has already been processed and can\'t be processed again.' );

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 123 )
			->andReturn( $order );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				"Payment link creation failed. Order has already been processed and can't be processed again. Order ID: 123.",
				'debug',
				[ 'order_state' => 'completed' ]
			);

		// Test the method
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'This order has already been processed and can\'t be processed again.' );
		$this->service->get_payment_link( 123 );
	}

	/**
	 * Test get_payment_link method with successful response.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link
	 * @return void
	 */
	public function test_get_payment_link_with_successful_response() : void {
		// Mock WC_DateTime.
		$modified_time = time() - 300; // 5 minutes ago.
		$date_modified = Mockery::mock( 'WC_DateTime' );
		$date_modified->shouldReceive( 'getTimestamp' )->once()->andReturn( $modified_time );

		// Mock WC_Order
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->twice()->andReturn( 123 );
		$order->shouldReceive( 'get_total' )->once()->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->once()->andReturn( 'GBP' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( '' );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_date_modified' )->once()->andReturn( $date_modified );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_transaction_type', 'capture' );
		$order->shouldReceive( 'save' )->once();

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 123 )
			->andReturn( $order );

		// Mock link body creation.
		$this->mock_get_payment_link_default_body_creation();

		// Mock SettingsService.

		$this->get_settings_service()
			->shouldReceive( 'get_option' )
			->once()
			->with( 'transaction_type', 'capture' )
			->andReturn( 'capture' );

		$this->get_settings_service()
			->shouldReceive( 'get_pay_url' )
			->once()
			->andReturn( 'https://pay.acquired.com/v1/' );

		// Mock CustomerService.
		$this->get_customer_service()
			->shouldReceive( 'get_customer_data_for_checkout' )
			->with( $order )
			->once()
			->andReturn( [] );

		// Mock PaymentMethodService.
		$this->get_payment_method_service()
			->shouldReceive( 'get_payment_method_for_checkout' )
			->with( $order )
			->once()
			->andReturn( null );

		// Mock PaymentLink.
		$response = Mockery::mock( PaymentLink::class );
		$response->shouldReceive( 'request_is_success' )->once()->andReturn( true );
		$response->shouldReceive( 'get_link_id' )->once()->andReturn( 'link_123' );
		$response->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_payment_link' )
			->once()
			->andReturn( $response );

		// Mock LoggerService.
		$this->get_logger_service()
		->shouldReceive( 'log' )
		->once()
		->with(
			'Payment link created successfully. Order ID: 123.',
			'debug',
			[]
		);

		// Test the method.
		$this->assertEquals( 'https://pay.acquired.com/v1/link_123', $this->service->get_payment_link( 123 ) );
	}

	/**
	 * Test get_payment_link method with error response.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::get_payment_link
	 * @return void
	 */
	public function test_get_payment_link_with_error_response() : void {
		// Mock WC_DateTime.
		$modified_time = time() - 300; // 5 minutes ago.
		$date_modified = Mockery::mock( 'WC_DateTime' );
		$date_modified->shouldReceive( 'getTimestamp' )->once()->andReturn( $modified_time );

		// Mock WC_Order
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->twice()->andReturn( 123 );
		$order->shouldReceive( 'get_total' )->once()->andReturn( '100.00' );
		$order->shouldReceive( 'get_currency' )->once()->andReturn( 'GBP' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_state' )->andReturn( '' );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_date_modified' )->once()->andReturn( $date_modified );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Payment link creation failed. Error message' );

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 123 )
			->andReturn( $order );

		// Mock link body creation.
		$this->mock_get_payment_link_default_body_creation();

		// Mock CustomerService.
		$this->get_customer_service()
			->shouldReceive( 'get_customer_data_for_checkout' )
			->with( $order )
			->once()
			->andReturn( [] );

		// Mock PaymentMethodService.
		$this->get_payment_method_service()
			->shouldReceive( 'get_payment_method_for_checkout' )
			->with( $order )
			->once()
			->andReturn( null );

		// Mock PaymentLink.
		$response = Mockery::mock( PaymentLink::class );
		$response->shouldReceive( 'request_is_success' )->once()->andReturn( false );
		$response->shouldReceive( 'get_error_message_formatted' )->once()->with( true )->andReturn( 'Error message' );
		$response->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_payment_link' )
			->once()
			->andReturn( $response );

		// Mock LoggerService.
		$this->get_logger_service()
		->shouldReceive( 'log' )
		->once()
		->with(
			'Payment link creation failed. Order ID: 123.',
			'error',
			[]
		);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Payment link creation failed.' );
		$this->service->get_payment_link( 123 );
	}

	/**
	 * Test set_additional_order_data method.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::set_additional_order_data
	 * @return void
	 */
	public function test_set_additional_order_data() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 123 );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_transaction_payment_method', 'card' );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Transaction (ID: transaction_123) payment method: "card".' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_acfw_transaction_decline_reason' );
		$order->shouldReceive( 'save' )->once();

		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'get_payment_method' )->twice()->andReturn( 'card' );
		$transaction->shouldReceive( 'get_transaction_id' )->once()->andReturn( 'transaction_123' );
		$transaction->shouldReceive( 'get_decline_reason' )->once()->andReturn( null );
		$transaction->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Order additional data set successfully. Order ID: 123.',
				'debug',
				[]
			);

		// Test the method.
		$this->set_private_method_value( 'set_additional_order_data', $order, $transaction );
	}

	/**
	 * Test set_additional_order_data method with decline reason.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::set_additional_order_data
	 * @return void
	 */
	public function test_set_additional_order_data_with_decline_reason() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 123 );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_transaction_payment_method', 'card' );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Transaction (ID: transaction_123) payment method: "card".' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_acfw_transaction_decline_reason' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_transaction_decline_reason', 'Insufficient funds' );
		$order->shouldReceive( 'add_order_note' )->once()->with( 'Transaction (ID: transaction_123) decline reason: "Insufficient funds".' );
		$order->shouldReceive( 'save' )->once();

		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'get_payment_method' )->twice()->andReturn( 'card' );
		$transaction->shouldReceive( 'get_transaction_id' )->twice()->andReturn( 'transaction_123' );
		$transaction->shouldReceive( 'get_decline_reason' )->times( 3 )->andReturn( 'Insufficient funds' );
		$transaction->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Order additional data set successfully. Order ID: 123.',
				'debug',
				[]
			);

		// Test the method.
		$this->set_private_method_value( 'set_additional_order_data', $order, $transaction );
	}

	/**
	 * Test schedule_process_order method when not for order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::schedule_process_order
	 * @return void
	 */
	public function test_schedule_process_order_not_for_order() : void {
		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->once()->andReturn( '456-add_payment_method' );

		// Test the method.
		$this->get_private_method_value( 'schedule_process_order', $webhook, 'hash_123' );
	}

	/**
	 * Test schedule_process_order method with successful scheduling.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::schedule_process_order
	 * @return void
	 */
	public function test_schedule_process_order_with_successful_scheduling() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 123 );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 123 )
			->andReturn( $order );

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( '123-wc_order_key' );
		$webhook->shouldReceive( 'get_incoming_data' )->once()->andReturn( [ 'transaction_id' => 'transaction_123' ] );
		$webhook->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock wp_json_encode.
		Functions\expect( 'wp_json_encode' )
			->once()
			->with( [ 'transaction_id' => 'transaction_123' ] )
			->andReturn( '{"transaction_id":"transaction_123"}' );

		// Mock ScheduleService.
		$this->get_schedule_service()
			->shouldReceive( 'schedule' )
			->once()
			->with(
				'acfw_scheduled_process_order',
				[
					'webhook_data' => '{"transaction_id":"transaction_123"}',
					'hash'         => 'hash_123',
				]
			);

		// Mock LoggerService.
			$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Order processing scheduled successfully from incoming webhook data. Order ID: 123.',
				'debug',
				[]
			);

		// Test the method.
		$this->service->schedule_process_order( $webhook, 'hash_123' );
	}

	/**
	 * Test schedule_process_order method with error.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::schedule_process_order
	 * @return void
	 */
	public function test_schedule_process_order_with_error() : void {
		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( 'invalid_id' );
		$webhook->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Error scheduling order processing from incoming webhook data. No valid order ID in incoming data.',
				'error',
				[]
			);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'No valid order ID in incoming data.' );
		$this->service->schedule_process_order( $webhook, 'hash_123' );
	}

	/**
	 * Test process_order method when not for order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_not_for_order() : void {
		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->once()->andReturn( '456-add_payment_method' );

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with already processed transaction.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_already_processed_transaction() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->once()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->once()->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->twice()->andReturn( [] );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->twice()->andReturn( $order_id );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( $transaction_id );

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( $order_id )
			->andReturn( $order );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->twice()
			->withArgs(
				function( $message, $level ) use ( $order_id ) {
					return in_array(
						$message,
						[
							sprintf( 'Order found successfully from incoming webhook data. Order ID: %d.', $order_id ),
							sprintf( 'Order transaction already processed. Skipping the processing. Order ID: %d.', $order_id ),
						],
						true
					) && 'debug' === $level;
				}
			);

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with already updated order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_already_updated_order() : void {
		// Set test data.
		$order_id               = 123;
		$transaction_order_id   = '123-wc_order_key';
		$transaction_id         = 'transaction_123';
		$timestamp              = 1234567890;
		$earlier_timestamp      = $timestamp - 1000;
		$earlier_transaction_id = 'transaction_456';

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $earlier_transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 3 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->twice()->andReturn( [] );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->times( 3 )->andReturn( $order_id );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( $transaction_id );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_time_updated' )->andReturn( $timestamp ); // Simulate that the order was updated after the transaction creation.

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( $order_id )
			->andReturn( $order );

		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'get_created_timestamp' )->once()->andReturn( $earlier_timestamp );
		$transaction->shouldReceive( 'get_log_data' )->once()->andReturn( [] );
		$transaction->shouldReceive( 'request_is_error' )->once()->andReturn( false );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( $earlier_transaction_id )
			->andReturn( $transaction );

		// Mock LoggerService.

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->times( 3 )
			->withArgs(
				function( $message, $level ) {
					return in_array(
						$message,
						[
							'Order found successfully from incoming webhook data. Order ID: 123.',
							'Transaction found successfully from incoming webhook data. Order ID: 123.',
							'Incoming webhook time created is not newer than the order time updated. Skipping the processing. Order ID: 123.',
						],
						true
					) && 'debug' === $level;
				}
			);

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with invalid order status.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_invalid_order_status() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->twice()->andReturn( [] );

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->times( 3 )->andReturn( $order_id );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( null );
		$order->shouldReceive( 'get_meta' )->once()->with( '_acfw_order_time_updated' )->andReturn( '' );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'failed', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_status' )->once()->andReturn( 'paid' );

		// Mock wc_get_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( $order_id )
			->andReturn( $order );

		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'get_created_timestamp' )->once()->andReturn( $timestamp );
		$transaction->shouldReceive( 'get_log_data' )->once()->andReturn( [] );
		$transaction->shouldReceive( 'request_is_error' )->once()->andReturn( false );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( $transaction_id )
			->andReturn( $transaction );

		// Mock LoggerService.

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->times( 2 )
			->withArgs(
				function( $message, $level ) {
					return in_array(
						$message,
						[
							'Order found successfully from incoming webhook data. Order ID: 123.',
							'Transaction found successfully from incoming webhook data. Order ID: 123.',
						],
						true
					) && 'debug' === $level;
				}
			);

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Error processing order from incoming webhook data. Received incoming webhook data for an order that can\'t be processed again. Order ID: 123, order status: paid.',
				'error',
				[]
			);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Received incoming webhook data for an order that can\'t be processed again. Order ID: 123, order status: paid.' );
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with success status and capture type.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_success_status_and_capture_type() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->times( 3 )->andReturn( [] );

		// Mock WC_Order.
		$order = $this->mock_order_for_processing( 'capture', 'success', $order_id, $transaction_id, $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_state', 'completed' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_time_completed', $timestamp );
		$order->shouldReceive( 'payment_complete' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( sprintf( 'Payment successful. Transaction ID: %s.', $transaction_id ) );

		// Mock Transaction.
		$transaction = $this->mock_transaction_for_processing( $transaction_id, 'success', $timestamp );
		$transaction->shouldReceive( 'get_created_timestamp' )->once()->andReturn( $timestamp );

		// Mock set_additional_order_data.
		$this->mock_set_additional_order_data( $order, $transaction );

		// Mock LoggerService.

		$this->mock_log_for_processing( $order_id );

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				sprintf( 'Payment complete for order. Order ID: %d.', $order_id ),
				'debug',
				[]
			);

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with settled status and capture type.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_settled_status_and_capture_type() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->times( 3 )->andReturn( [] );

		// Mock WC_Order.
		$order = $this->mock_order_for_processing( 'capture', 'settled', $order_id, $transaction_id, $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_state', 'completed' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_time_completed', $timestamp );
		$order->shouldReceive( 'payment_complete' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( sprintf( 'Payment successful. Transaction ID: %s.', $transaction_id ) );

		// Mock Transaction.
		$transaction = $this->mock_transaction_for_processing( $transaction_id, 'settled', $timestamp );
		$transaction->shouldReceive( 'get_created_timestamp' )->once()->andReturn( $timestamp );

		// Mock set_additional_order_data.
		$this->mock_set_additional_order_data( $order, $transaction );

		// Mock LoggerService.

		$this->mock_log_for_processing( $order_id );

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				sprintf( 'Payment complete for order. Order ID: %d.', $order_id ),
				'debug',
				[]
			);

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with success status and authorisation type.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_success_status_and_authorisation_type() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->times( 3 )->andReturn( [] );

		// Mock WC_Order.
		$order = $this->mock_order_for_processing( 'authorisation', 'success', $order_id, $transaction_id, $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_state', 'authorised' );
		$order->shouldReceive( 'update_status' )->once()->with( 'on-hold' );
		$order->shouldReceive( 'add_order_note' )->once()->with( sprintf( 'Payment authorised. Transaction ID: %s.', $transaction_id ) );

		// Mock Transaction.
		$transaction = $this->mock_transaction_for_processing( $transaction_id, 'success', $timestamp );

		// Mock set_additional_order_data.
		$this->mock_set_additional_order_data( $order, $transaction );

		// Mock LoggerService.

		$this->mock_log_for_processing( $order_id );

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				sprintf( 'Payment authorised for order. Order ID: %d.', $order_id ),
				'debug',
				[]
			);

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with executed status and capture type.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_executed_status_and_capture_type() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->times( 3 )->andReturn( [] );

		// Mock WC_Order.
		$order = $this->mock_order_for_processing( 'capture', 'executed', $order_id, $transaction_id, $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_state', 'executed' );
		$order->shouldReceive( 'update_status' )->once()->with( 'on-hold' );
		$order->shouldReceive( 'add_order_note' )->once()->with( sprintf( 'Bank payment executed. Transaction ID: %s.', $transaction_id ) );

		// Mock Transaction.
		$transaction = $this->mock_transaction_for_processing( $transaction_id, 'executed', $timestamp );

		// Mock set_additional_order_data.
		$this->mock_set_additional_order_data( $order, $transaction );

		// Mock LoggerService.

		$this->mock_log_for_processing( $order_id );

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				sprintf( 'Bank payment executed for order. Order ID: %d.', $order_id ),
				'debug',
				[]
			);

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with failed status and capture type.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_failed_status_and_capture_type() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->times( 3 )->andReturn( [] );

		// Mock WC_Order.
		$order = $this->mock_order_for_processing( 'capture', 'failed', $order_id, $transaction_id, $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_state', 'failed' );
		$order->shouldReceive( 'update_status' )->once()->with( 'failed' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_transaction_status' )->once()->andReturn( 'failed' );
		$order->shouldReceive( 'add_order_note' )->once()->with( sprintf( 'Payment failed with status "failed". Transaction ID: %s.', $transaction_id ) );

		// Mock Transaction.
		$transaction = $this->mock_transaction_for_processing( $transaction_id, 'failed', $timestamp );

		// Mock set_additional_order_data.
		$this->mock_set_additional_order_data( $order, $transaction );

		// Mock LoggerService.

		$this->mock_log_for_processing( $order_id );

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				sprintf( 'Payment failed for order. Order ID: %d.', $order_id ),
				'debug',
				[]
			);

		// Test the method.
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_order method with invalid order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_order
	 * @return void
	 */
	public function test_process_order_with_invalid_order() : void {
		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( 'invalid_order_id' );
		$webhook->shouldReceive( 'get_type' )->once()->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Error processing order from incoming webhook data. No valid order ID in incoming data.',
				'error',
				[]
			);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'No valid order ID in incoming data.' );
		$this->service->process_order( $webhook );
	}

	/**
	 * Test process_scheduled_order method success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_scheduled_order
	 * @return void
	 */
	public function test_process_scheduled_order_success() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( $transaction_order_id );
		$webhook->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$webhook->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )->times( 3 )->andReturn( [] );

		// Mock WC_Order.
		$order = $this->mock_order_for_processing( 'capture', 'success', $order_id, $transaction_id, $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_state', 'completed' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_time_completed', $timestamp );
		$order->shouldReceive( 'payment_complete' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( sprintf( 'Payment successful. Transaction ID: %s.', $transaction_id ) );

		// Mock Transaction.
		$transaction = $this->mock_transaction_for_processing( $transaction_id, 'success', $timestamp );
		$transaction->shouldReceive( 'get_created_timestamp' )->once()->andReturn( $timestamp );

		// Mock set_additional_order_data.
		$this->mock_set_additional_order_data( $order, $transaction );

		// Mock LoggerService.

		$this->mock_log_for_processing( $order_id );

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				sprintf( 'Payment complete for order. Order ID: %d.', $order_id ),
				'debug',
				[]
			);

		// Test the method.
		$this->service->process_scheduled_order( $webhook );
	}

	/**
	 * Test process_scheduled_order error.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::process_scheduled_order
	 * @return void
	 */
	public function test_process_scheduled_order_error() : void {
		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )->twice()->andReturn( 'invalid_id' );
		$webhook->shouldReceive( 'get_log_data' )->twice()->andReturn( [] );
		$webhook->shouldReceive( 'get_type' )->once()->andReturn( 'webhook' );

		// Mock LoggerService.

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Error processing order from incoming webhook data. No valid order ID in incoming data.',
				'error',
				[]
			);

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Error processing order from scheduled webhook data. Error: "No valid order ID in incoming data.".',
				'error',
				[]
			);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'No valid order ID in incoming data.' );
		$this->service->process_scheduled_order( $webhook );
	}

	/**
	 * Test confirm_order method success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::confirm_order
	 * @return void
	 */
	public function test_confirm_order_success() : void {
		// Set test data.
		$order_id             = 123;
		$transaction_order_id = '123-wc_order_key';
		$transaction_id       = 'transaction_123';
		$timestamp            = 1234567890;

		// Mock RedirectData.
		$redirect = Mockery::mock( RedirectData::class );
		$redirect->shouldReceive( 'get_order_id' )->times( 3 )->andReturn( $transaction_order_id );
		$redirect->shouldReceive( 'get_transaction_id' )->twice()->andReturn( $transaction_id );
		$redirect->shouldReceive( 'get_type' )->times( 4 )->andReturn( 'webhook' );
		$redirect->shouldReceive( 'get_log_data' )->times( 3 )->andReturn( [] );

		// Mock WC_Order.
		$order = $this->mock_order_for_processing( 'capture', 'success', $order_id, $transaction_id, $timestamp );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_state', 'completed' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_acfw_order_time_completed', $timestamp );
		$order->shouldReceive( 'payment_complete' )->once();
		$order->shouldReceive( 'add_order_note' )->once()->with( sprintf( 'Payment successful. Transaction ID: %s.', $transaction_id ) );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );

		// Mock wc_get_order once more in OrderService::confirm_order.
		Functions\expect( 'wc_get_order' )
			->once()
			->with( $order_id )
			->andReturn( $order );

		// Mock Transaction.
		$transaction = $this->mock_transaction_for_processing( $transaction_id, 'success', $timestamp );
		$transaction->shouldReceive( 'get_created_timestamp' )->once()->andReturn( $timestamp );

		// Mock set_additional_order_data.
		$this->mock_set_additional_order_data( $order, $transaction );

		// Mock LoggerService.

		$this->mock_log_for_processing( $order_id );

		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				sprintf( 'Payment complete for order. Order ID: %d.', $order_id ),
				'debug',
				[]
			);

		// Test the method.
		$this->assertSame( $order, $this->service->confirm_order( $redirect ) );
	}

	/**
	 * Test confirm_order method error.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::confirm_order
	 * @return void
	 */
	public function test_confirm_order_error() : void {
		// Mock WebhookData.
		$redirect = Mockery::mock( RedirectData::class );
		$redirect->shouldReceive( 'get_order_id' )->once()->andReturn( 'invalid_id' );
		$redirect->shouldReceive( 'get_log_data' )->once()->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Error processing order from incoming redirect data. No valid order ID in incoming data.',
				'error',
				[]
			);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'No valid order ID in incoming data.' );
		$this->service->confirm_order( $redirect );
	}

	/**
	 * Test can_be_captured method with valid order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_captured
	 * @return void
	 */
	public function test_can_be_captured_with_valid_order() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( 'transaction_123' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_transaction_type' )->once()->andReturn( 'authorisation' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_order_state' )->once()->andReturn( 'authorised' );
		$order->shouldReceive( 'get_total' )->once()->andReturn( '100.00' );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'acfw' );

		// Test the method.
		$this->assertTrue( $this->service->can_be_captured( $order ) );
	}

	/**
	 * Test can_be_captured method with non-ACFW payment method.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_captured
	 * @return void
	 */
	public function test_can_be_captured_with_non_acfw_payment_method() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'other_payment_method' );

		// Test the method.
		$this->assertFalse( $this->service->can_be_captured( $order ) );
	}

	/**
	 * Test can_be_captured method with missing transaction ID.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_captured
	 * @return void
	 */
	public function test_can_be_captured_with_missing_transaction_id() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'acfw' );

		// Test the method.
		$this->assertFalse( $this->service->can_be_captured( $order ) );
	}

	/**
	 * Test can_be_captured method with wrong transaction type.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_captured
	 * @return void
	 */
	public function test_can_be_captured_with_wrong_transaction_type() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_transaction_type' )->once()->andReturn( 'authorised' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( 'transaction_123' );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'acfw' );

		// Test the method.
		$this->assertFalse( $this->service->can_be_captured( $order ) );
	}

	/**
	 * Test can_be_captured method with wrong order state.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_captured
	 * @return void
	 */
	public function test_can_be_captured_with_wrong_order_state() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_order_state' )->once()->andReturn( 'completed' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_transaction_type' )->once()->andReturn( 'authorisation' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( 'transaction_123' );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'acfw' );

		// Test the method.
		$this->assertFalse( $this->service->can_be_captured( $order ) );
	}

	/**
	 * Test can_be_captured method with zero total.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\OrderService::can_be_captured
	 * @return void
	 */
	public function test_can_be_captured_with_zero_total() : void {
		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_total' )->once()->andReturn( '0.00' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_order_state' )->once()->andReturn( 'authorised' );
		$order->shouldReceive( 'get_meta' )->with( '_acfw_transaction_type' )->once()->andReturn( 'authorisation' );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( 'transaction_123' );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'acfw' );

		// Test the method.
		$this->assertFalse( $this->service->can_be_captured( $order ) );
	}
}
