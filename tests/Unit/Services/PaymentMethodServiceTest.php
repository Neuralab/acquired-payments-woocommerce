<?php
/**
 * PaymentMethodServiceTest.
 */

declare( strict_types = 1 );

namespace AcquiredComForWooCommerce\Tests\Unit\Services;

use AcquiredComForWooCommerce\Tests\Framework\TestCase;
use AcquiredComForWooCommerce\Tests\Framework\Traits\ApiClientMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\LoggerServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\Reflection;
use AcquiredComForWooCommerce\Services\PaymentMethodService;
use AcquiredComForWooCommerce\Tests\Framework\Traits\CustomerServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\ScheduleServiceMock;
use AcquiredComForWooCommerce\Tests\Framework\Traits\SettingsServiceMock;
use Mockery;
use Mockery\MockInterface;
use Exception;
use stdClass;

/**
 * PaymentMethodServiceTest class.
 *
 * @runTestsInSeparateProcesses
 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService
 */
class PaymentMethodServiceTest extends TestCase {
	/**
	 * Traits.
	 */
	use Reflection;
	use ApiClientMock;
	use CustomerServiceMock;
	use LoggerServiceMock;
	use ScheduleServiceMock;
	use SettingsServiceMock;

	/**
	 * PaymentMethodService class.
	 *
	 * @var PaymentMethodService
	 */
	private PaymentMethodService $service;

	/**
	 * Get test card data.
	 *
	 * @param string $status
	 * @return stdClass
	 */
	private function get_test_card_data( string $status ) : stdClass {
		$cards = [
			'valid'   => (object) [
				'scheme'       => 'visa',
				'number'       => 1234,
				'expiry_month' => 6,
				'expiry_year'  => 25,
			],
			'expired' => (object) [
				'scheme'       => 'visa',
				'number'       => 4567,
				'expiry_month' => 12,
				'expiry_year'  => 20,
			],
		];

		return $cards[ $status ] ?? $cards['valid'];
	}

	/**
	 * Mock WC_Payment_Token_CC.
	 *
	 * @return MockInterface
	 */
	private function mock_wc_payment_token() : MockInterface {
		// Mock WC_Payment_Token_CC.
		$token = Mockery::mock( 'overload:WC_Payment_Token_CC' );
		$token->shouldReceive( '__construct' )->once();

		return $token;
	}

	/**
	 * Mock WC_Payment_Tokens::get().
	 *
	 * @param int $token_id
	 * @param MockInterface|null $return
	 * @return MockInterface
	 */
	private function mock_wc_payment_tokens_get( int $token_id, MockInterface|null $return ) : MockInterface {
		// Mock WC_Payment_Tokens.
		$payment_tokens = Mockery::mock( 'overload:WC_Payment_Tokens' );

		$payment_tokens->shouldReceive( 'get' )
			->once()
			->with( $token_id )
			->andReturn( $return );

		return $payment_tokens;
	}

	/**
	 * Mock WC_Payment_Tokens::get().
	 *
	 * @param array $args
	 * @param array $return
	 * @return MockInterface
	 */
	private function mock_wc_payment_tokens_get_tokens( array $args, array $return ) : MockInterface {
		// Mock WC_Payment_Tokens.

		$payment_tokens = Mockery::mock( 'overload:WC_Payment_Tokens' );

		$payment_tokens->shouldReceive( 'get_tokens' )
			->once()
			->with( $args )
			->andReturn( $return );

		return $payment_tokens;
	}

	/**
	 * Mock WC_Payment_Token_CC instance creation.
	 *
	 * @param MockInterface $token Token mock to return
	 * @return void
	 */
	private function mock_wc_payment_token_instance( MockInterface $token ) : void {
		$this->set_private_property_value( 'payment_token_class', get_class( $token ) );
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
		$this->mock_schedule_service();
		$this->mock_settings_service();

		$this->service = new PaymentMethodService(
			$this->get_api_client(),
			$this->get_customer_service(),
			$this->get_logger_service(),
			$this->get_schedule_service(),
			$this->get_settings_service(),
			'WC_Payment_Token_CC',
			'WC_Payment_Tokens',
		);

		$this->initialize_reflection( $this->service );
	}

	/**
	 * Test constructor.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::__construct
	 * @return void
	 */
	public function test_constructor() : void {
		$this->assertSame( $this->get_api_client(), $this->get_private_property_value( 'api_client' ) );
		$this->assertSame( $this->get_customer_service(), $this->get_private_property_value( 'customer_service' ) );
		$this->assertSame( $this->get_logger_service(), $this->get_private_property_value( 'logger_service' ) );
		$this->assertSame( $this->get_schedule_service(), $this->get_private_property_value( 'schedule_service' ) );
		$this->assertSame( $this->get_settings_service(), $this->get_private_property_value( 'settings_service' ) );
		$this->assertEquals( 'WC_Payment_Token_CC', $this->get_private_property_value( 'payment_token_class' ) );
		$this->assertEquals( 'WC_Payment_Tokens', $this->get_private_property_value( 'payment_tokens_class' ) );
	}

	/**
	 * Test is_transaction_success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::is_transaction_success
	 * @return void
	 */
	public function test_is_transaction_success() : void {
		$this->assertTrue( $this->service->is_transaction_success( 'success' ) );
		$this->assertTrue( $this->service->is_transaction_success( 'settled' ) );
		$this->assertTrue( $this->service->is_transaction_success( 'executed' ) );
		$this->assertFalse( $this->service->is_transaction_success( 'failed' ) );
	}

	/**
	 * Test get_status_key returns correct key.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_status_key
	 * @return void
	 */
	public function test_get_status_key_returns_correct_key() : void {
		$this->assertEquals( 'acfw_payment_method_status', $this->service->get_status_key() );
	}

	/**
	 * Test get_scheduled_action_hook returns correct hook.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_scheduled_action_hook
	 * @return void
	 */
	public function test_get_scheduled_action_hook_returns_correct_hook() : void {
		$this->assertEquals( 'acfw_scheduled_save_payment_method', $this->service->get_scheduled_action_hook() );
	}

	/**
	 * Test create_token_instance returns correct instance.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::create_token_instance
	 * @return void
	 */
	public function test_create_token_instance() : void {
		// Mock WC_Payment_Token_CC.
		$this->mock_wc_payment_token();

		// Test the method.
		$this->assertInstanceOf( 'WC_Payment_Token_CC', $this->get_private_method_value( 'create_token_instance' ) );
	}

	/**
	 * Test get_token returns token when valid.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_token
	 * @return void
	 */
	public function test_get_token_success() : void {
		// Mock WC_Payment_Token_CC.

		$token = $this->mock_wc_payment_token();

		$token->shouldReceive( 'get_gateway_id' )
			->once()
			->andReturn( 'acfw' );

		// Mock WC_Payment_Tokens::get().
		$this->mock_wc_payment_tokens_get( 123, $token );

		// Test the method.
		$this->assertInstanceOf( 'WC_Payment_Token_CC', $this->get_private_method_value( 'get_token', 123 ) );
	}

	/**
	 * Test get_token returns null when token not found.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_token
	 * @return void
	 */
	public function test_get_token_token_not_found() : void {
		// Mock WC_Payment_Token_CC.
		$this->mock_wc_payment_tokens_get( 123, null );

		// Test the method.
		$this->assertNull( $this->get_private_method_value( 'get_token', 123 ) );
	}

	/**
	 * Test get_token returns null when gateway ID doesn't match.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_token
	 * @return void
	 */
	public function test_get_token_token_not_our_payment_gateway() : void {
		// Mock WC_Payment_Token_CC.

		$token = $this->mock_wc_payment_token();

		$token->shouldReceive( 'get_gateway_id' )
			->once()
			->andReturn( 'other_payment_method' );

		// Mock WC_Payment_Tokens::get().
		$this->mock_wc_payment_tokens_get( 123, $token );

		// Test the method.
		$this->assertNull( $this->get_private_method_value( 'get_token', 123 ) );
	}

	/**
	 * Test get_user_tokens returns tokens.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_user_tokens
	 * @return void
	 */
	public function test_get_user_tokens_returns_tokens() : void {
		// Mock WC_Payment_Token_CC
		$token = $this->mock_wc_payment_token();

		// Mock WC_Payment_Tokens.
		$this->mock_wc_payment_tokens_get_tokens(
			[
				'user_id'    => 456,
				'gateway_id' => 'acfw',
			],
			[ $token ]
		);

		// Test the method.
		$result = $this->get_private_method_value( 'get_user_tokens', 456 );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertInstanceOf( 'WC_Payment_Token_CC', $result[0] );
	}

	/**
	 * Test get_user_tokens returns empty array when no tokens.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_user_tokens
	 * @return void
	 */
	public function test_get_user_tokens_returns_empty_array() : void {
		// Mock WC_Payment_Tokens.
		$this->mock_wc_payment_tokens_get_tokens(
			[
				'user_id'    => 456,
				'gateway_id' => 'acfw',
			],
			[]
		);

		// Test the method.
		$result = $this->get_private_method_value( 'get_user_tokens', 456 );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_token_by_user_and_card_id returns token when found.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_token_by_user_and_card_id
	 * @return void
	 */
	public function test_get_token_by_user_and_card_id_success() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'get_token' )
			->once()
			->andReturn( 'token_123' );

		// Mock WC_Payment_Tokens.
		$this->mock_wc_payment_tokens_get_tokens(
			[
				'user_id'    => 456,
				'gateway_id' => 'acfw',
			],
			[ $token ]
		);

		// Test the method.
		$this->assertInstanceOf( 'WC_Payment_Token_CC', $this->get_private_method_value( 'get_token_by_user_and_card_id', 456, 'token_123' ) );
	}

	/**
	 * Test get_token_by_user_and_card_id throws exception when token id is not found.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_token_by_user_and_card_id
	 * @return void
	 */
	public function test_get_token_by_user_and_token_id_not_found() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'get_token' )
			->once()
			->andReturn( 'token_456' );

		// Mock WC_Payment_Tokens.
		$this->mock_wc_payment_tokens_get_tokens(
			[
				'user_id'    => 456,
				'gateway_id' => 'acfw',
			],
			[ $token ]
		);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Token not found.' );
		$this->get_private_method_value( 'get_token_by_user_and_card_id', 456, 'token_123' );
	}

	/**
	 * Test get_token_by_user_and_card_id throws exception when no tokens exist.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_token_by_user_and_card_id
	 * @return void
	 */
	public function test_get_token_by_user_and_card_id_no_tokens() : void {
		// Mock WC_Payment_Token_CC.
		$this->mock_wc_payment_tokens_get_tokens(
			[
				'user_id'    => 456,
				'gateway_id' => 'acfw',
			],
			[]
		);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Token not found.' );
		$this->get_private_method_value( 'get_token_by_user_and_card_id', 456, 'card_123' );
	}

	/**
	 * Test payment_token_exists returns true when token exists.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::payment_token_exists
	 * @return void
	 */
	public function test_payment_token_exists_returns_true() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'get_token' )
			->once()
			->andReturn( 'token_123' );

		// Mock WC_Payment_Tokens.
		$this->mock_wc_payment_tokens_get_tokens(
			[
				'user_id'    => 456,
				'gateway_id' => 'acfw',
			],
			[ $token ]
		);

		// Test the method.
		$this->assertTrue( $this->get_private_method_value( 'payment_token_exists', 456, 'token_123' ) );
	}

	/**
	 * Test payment_token_exists returns false when token doesn't exist.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::payment_token_exists
	 * @return void
	 */
	public function test_payment_token_exists_returns_false() : void {
		// Mock WC_Payment_Tokens.
		$this->mock_wc_payment_tokens_get_tokens(
			[
				'user_id'    => 456,
				'gateway_id' => 'acfw',
			],
			[]
		);

		// Test the method.
		$this->assertFalse( $this->get_private_method_value( 'payment_token_exists', 456, 'card_123' ) );
	}

	/**
	 * Test set_token_card_data sets card data correctly.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::set_token_card_data
	 * @return void
	 */
	public function test_set_token_card_data() : void {
		// Mock WC_Payment_Token_CC.

		$token = $this->mock_wc_payment_token();

		$token->shouldReceive( 'set_card_type' )
			->once();

		$token->shouldReceive( 'set_last4' )
			->once()
			->with( '1234' )
			->andReturnUsing(
				function( $value ) {
					$this->assertIsString( $value );
				}
			);

		$token->shouldReceive( 'set_expiry_month' )
			->once()
			->with( '06' )
			->andReturnUsing(
				function( $value ) {
					$this->assertIsString( $value );
				}
			);

		$token->shouldReceive( 'set_expiry_year' )
			->once()
			->with( '2025' )
			->andReturnUsing(
				function( $value ) {
					$this->assertIsString( $value );
				}
			);

		// Test the method.
		$this->get_private_method_value( 'set_token_card_data', $token, $this->get_test_card_data( 'valid' ) );
	}

	/**
	 * Test create_token.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::create_token
	 * @return void
	 */
	public function test_create_token() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'set_token' )->once()->with( 'token_123' );
		$token->shouldReceive( 'set_card_type' )->once()->with( 'visa' );
		$token->shouldReceive( 'set_last4' )->once()->with( '1234' );
		$token->shouldReceive( 'set_expiry_month' )->once()->with( '06' );
		$token->shouldReceive( 'set_expiry_year' )->once()->with( '2025' );
		$token->shouldReceive( 'set_gateway_id' )->once()->with( 'acfw' );
		$token->shouldReceive( 'set_user_id' )->once()->with( 456 );
		$token->shouldReceive( 'validate' )->once()->andReturn( true );
		$token->shouldReceive( 'save' )->once();

		// Mock create_token_instance.
		$this->mock_wc_payment_token_instance( $token );

		// Test the method.
		$this->get_private_method_value( 'create_token', 'token_123', $this->get_test_card_data( 'valid' ), 456 );
	}

	/**
	 * Test create_token with order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::create_token
	 * @return void
	 */
	public function test_create_token_with_order() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'set_token' )->once()->with( 'token_123' );
		$token->shouldReceive( 'set_card_type' )->once()->with( 'visa' );
		$token->shouldReceive( 'set_last4' )->once()->with( '1234' );
		$token->shouldReceive( 'set_expiry_month' )->once()->with( '06' );
		$token->shouldReceive( 'set_expiry_year' )->once()->with( '2025' );
		$token->shouldReceive( 'set_gateway_id' )->once()->with( 'acfw' );
		$token->shouldReceive( 'set_user_id' )->once()->with( 456 );
		$token->shouldReceive( 'validate' )->once()->andReturn( true );
		$token->shouldReceive( 'save' )->once();

		// Mock WC_Order.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'add_payment_token' )
			->once()
			->withArgs(
				function( $token ) {
					return $token instanceof MockInterface;
				}
			);
		$order->shouldReceive( 'save' )->once();

		// Mock create_token_instance.
		$this->mock_wc_payment_token_instance( $token );

		// Test the method.
		$this->get_private_method_value( 'create_token', 'token_123', $this->get_test_card_data( 'valid' ), 456, $order );
	}

	/**
	 * Test create_token throws exception on validation failure.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::create_token
	 * @return void
	 */
	public function test_create_token_throws_exception_on_validation_failure() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'set_token' )->once()->with( 'token_123' );
		$token->shouldReceive( 'set_card_type' )->once()->with( 'visa' );
		$token->shouldReceive( 'set_last4' )->once()->with( '4567' );
		$token->shouldReceive( 'set_expiry_month' )->once()->with( '12' );
		$token->shouldReceive( 'set_expiry_year' )->once()->with( '2020' );
		$token->shouldReceive( 'set_gateway_id' )->once()->with( 'acfw' );
		$token->shouldReceive( 'set_user_id' )->once()->with( 456 );
		$token->shouldReceive( 'validate' )->once()->andReturn( false );
		$token->shouldNotReceive( 'save' );

		// Mock create_token_instance.
		$this->mock_wc_payment_token_instance( $token );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to validate token.' );
		$this->get_private_method_value( 'create_token', 'token_123', $this->get_test_card_data( 'expired' ), 456 );
	}

	/**
	 * Test update_token.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::update_token
	 * @return void
	 */
	public function test_update_token() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'set_card_type' )->once()->with( 'visa' );
		$token->shouldReceive( 'set_last4' )->once()->with( '1234' );
		$token->shouldReceive( 'set_expiry_month' )->once()->with( '06' );
		$token->shouldReceive( 'set_expiry_year' )->once()->with( '2025' );
		$token->shouldReceive( 'validate' )->once()->andReturn( true );
		$token->shouldReceive( 'save' )->once();

		// Test the method.
		$this->get_private_method_value( 'update_token', $token, $this->get_test_card_data( 'valid' ) );
	}

	/**
	 * Test update_token throws exception on validation failure.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::update_token
	 * @return void
	 */
	public function test_update_token_throws_exception_on_validation_failure() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'set_card_type' )->once()->with( 'visa' );
		$token->shouldReceive( 'set_last4' )->once()->with( '4567' );
		$token->shouldReceive( 'set_expiry_month' )->once()->with( '12' );
		$token->shouldReceive( 'set_expiry_year' )->once()->with( '2020' );
		$token->shouldReceive( 'validate' )->once()->andReturn( false );
		$token->shouldNotReceive( 'save' );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to validate token.' );
		$this->get_private_method_value( 'update_token', $token, $this->get_test_card_data( 'expired' ) );
	}
}
