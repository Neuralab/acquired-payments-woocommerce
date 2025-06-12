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
use AcquiredComForWooCommerce\Api\Response\Card;
use AcquiredComForWooCommerce\Api\Response\Transaction;
use AcquiredComForWooCommerce\Api\IncomingData\WebhookData;
use Mockery;
use Mockery\MockInterface;
use Brain\Monkey\Functions;
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
	 * Mock tokenization setting.
	 *
	 * @param bool $setting
	 * @return void
	 */
	private function mock_tokenization_setting( bool $setting ) : void {
		$this->get_settings_service()
			->shouldReceive( 'is_enabled' )
			->once()
			->with( 'tokenization' )
			->andReturn( $setting );
	}

	/**
	 * Mock WC_Payment_Token_CC.
	 *
	 * @return MockInterface&\WC_Payment_Token_CC
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
	 * Test get_user_tokens success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_user_tokens
	 * @return void
	 */
	public function test_get_user_tokens_success() : void {
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
		$this->get_private_method_value( 'get_token_by_user_and_card_id', 456, 'token_123' );
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
		$this->assertFalse( $this->get_private_method_value( 'payment_token_exists', 456, 'token_123' ) );
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
	 * Test create_token success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::create_token
	 * @return void
	 */
	public function test_create_token_success() : void {
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
	 * Test create_token success with order.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::create_token
	 * @return void
	 */
	public function test_create_token_success_with_order() : void {
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
	 * Test update_token success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::update_token
	 * @return void
	 */
	public function test_update_token_success() : void {
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

	/**
	 * Test get_card success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_card
	 * @return void
	 */
	public function test_get_card_success() : void {
		// Mock Card.
		$card = Mockery::mock( Card::class );
		$card->shouldReceive( 'is_active' )
			->once()
			->andReturn( true );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_card' )
			->once()
			->with( 'token_123' )
			->andReturn( $card );

		// Test the method.
		$this->assertInstanceOf( Card::class, $this->get_private_method_value( 'get_card', 'token_123' ) );
	}

	/**
	 * Test get_card throws exception when request fails.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_card
	 * @return void
	 */
	public function test_get_card_throws_exception_when_request_fails() : void {
		// Mock Card.
		$card = Mockery::mock( Card::class );
		$card->shouldReceive( 'is_active' )
			->once()
			->andReturn( false );
		$card->shouldReceive( 'request_is_error' )
			->once()
			->andReturn( true );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_card' )
			->once()
			->with( 'token_123' )
			->andReturn( $card );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Card retrieval failed.' );
		$this->get_private_method_value( 'get_card', 'token_123' );
	}

	/**
	 * Test get_card throws exception when card is not active.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_card
	 * @return void
	 */
	public function test_get_card_throws_exception_when_card_not_active() : void {
		// Mock Card.
		$card = Mockery::mock( Card::class );
		$card->shouldReceive( 'is_active' )
			->once()
			->andReturn( false );
		$card->shouldReceive( 'request_is_error' )
			->once()
			->andReturn( false );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_card' )
			->once()
			->with( 'token_123' )
			->andReturn( $card );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Card is not active.' );
		$this->get_private_method_value( 'get_card', 'token_123' );
	}

	/**
	 * Test get_card_id_from_transaction success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_card_id_from_transaction
	 * @return void
	 */
	public function test_get_card_id_from_transaction_success() : void {
		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'request_is_error' )
			->once()
			->andReturn( false );
		$transaction->shouldReceive( 'get_card_id' )
			->twice()
			->andReturn( 'token_123' );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( 'transaction_123' )
			->andReturn( $transaction );

		// Test the method.
		$this->assertEquals( 'token_123', $this->get_private_method_value( 'get_card_id_from_transaction', 'transaction_123' ) );
	}

	/**
	 * Test get_card_id_from_transaction throws exception when request fails.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_card_id_from_transaction
	 * @return void
	 */
	public function test_get_card_id_from_transaction_throws_exception_when_request_fails() : void {
		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'request_is_error' )
			->once()
			->andReturn( true );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( 'transaction_123' )
			->andReturn( $transaction );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Card ID retrieval failed.' );
		$this->get_private_method_value( 'get_card_id_from_transaction', 'transaction_123' );
	}

	/**
	 * Test get_card_id_from_transaction throws exception when card ID not found.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::get_card_id_from_transaction
	 * @return void
	 */
	public function test_get_card_id_from_transaction_throws_exception_when_card_id_not_found() : void {
		// Mock Transaction.
		$transaction = Mockery::mock( Transaction::class );
		$transaction->shouldReceive( 'request_is_error' )
			->once()
			->andReturn( false );
		$transaction->shouldReceive( 'get_card_id' )
			->once()
			->andReturn( null );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_transaction' )
			->once()
			->with( 'transaction_123' )
			->andReturn( $transaction );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Card ID not found.' );
		$this->get_private_method_value( 'get_card_id_from_transaction', 'transaction_123' );
	}

	/**
	 * Test deactivate_card success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::deactivate_card
	 * @return void
	 */
	public function test_deactivate_card_success() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'get_token' )
			->once()
			->andReturn( 'token_123' );

		// Mock Card.
		$response = Mockery::mock( Card::class );
		$response->shouldReceive( 'request_is_success' )
			->once()
			->andReturn( true );
		$response->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'update_card' )
			->once()
			->with( 'token_123', [ 'is_active' => false ] )
			->andReturn( $response );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with( 'Payment method deletion successful.', 'debug', [] );

		// Test the method.
		$this->service->deactivate_card( $token );
	}

	/**
	 * Test deactivate_card failure.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::deactivate_card
	 * @return void
	 */
	public function test_deactivate_card_failure() : void {
		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
			$token->shouldReceive( 'get_token' )
			->once()
			->andReturn( 'token_123' );

		// Mock Card.
		$response = Mockery::mock( Card::class );
		$response->shouldReceive( 'request_is_success' )
			->once()
			->andReturn( false );
		$response->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'update_card' )
			->once()
			->with( 'token_123', [ 'is_active' => false ] )
			->andReturn( $response );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with( 'Payment method deletion failed.', 'error', [] );

		// Test the method.
		$this->service->deactivate_card( $token );
	}

	/**
	 * Test process_payment_method success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::process_payment_method
	 * @return void
	 */
	public function test_process_payment_method_success() : void {
		// Mock tokenization setting.
		$this->mock_tokenization_setting( true );

		// Mock WebhookData.
		$data = Mockery::mock( WebhookData::class );
		$data->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with( 'Test success message', 'debug', [] );

		// Test the method.
		$this->get_private_method_value(
			'process_payment_method',
			'saving',
			function( $data, $log ) {
				$log( 'Test success message' );
			},
			$data
		);
	}

	/**
	 * Test process_payment_method throws exception when tokenization disabled.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::process_payment_method
	 * @return void
	 */
	public function test_process_payment_method_throws_exception_when_tokenization_disabled() : void {
		// Mock tokenization setting.
		$this->mock_tokenization_setting( false );

		// Mock WebhookData.
		$data = Mockery::mock( WebhookData::class );
		$data->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with( 'Payment method saving failed. Tokenization is disabled.', 'error', [] );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Payment method saving failed. Tokenization is disabled.' );
		$this->get_private_method_value(
			'process_payment_method',
			'saving',
			function() {},
			$data
		);
	}

	/**
	 * Test process_payment_method throws exception when process fails.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::process_payment_method
	 * @return void
	 */
	public function test_process_payment_method_throws_exception_when_process_fails() : void {
		// Mock tokenization setting.
		$this->mock_tokenization_setting( true );

		// Mock WebhookData.
		$data = Mockery::mock( WebhookData::class );
		$data->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with( 'Payment method saving failed. Test error message.', 'error', [] );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Test error message.' );
		$this->get_private_method_value(
			'process_payment_method',
			'saving',
			function() {
				throw new Exception( 'Test error message.' );
			},
			$data
		);
	}

	/**
	 * Test schedule_save_payment_method success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::schedule_save_payment_method
	 * @return void
	 */
	public function test_schedule_save_payment_method_success() : void {
		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )
			->once()
			->andReturn( '456-add_payment_method_key' );
		$webhook->shouldReceive( 'get_incoming_data' )
			->once()
			->andReturn( [ 'test_data' ] );
		$webhook->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock WC_Customer.
		$customer = Mockery::mock( 'overload:WC_Customer' );
		$customer->shouldReceive( 'get_id' )
			->once()
			->andReturn( 456 );

		// Mock ScheduleService.
		$this->get_schedule_service()
			->shouldReceive( 'schedule' )
			->once()
			->with(
				'acfw_scheduled_save_payment_method',
				[
					'webhook_data' => json_encode( [ 'test_data' ] ),
					'hash'         => 'test_hash',
				]
			);

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Save payment method scheduled successfully from incoming webhook data. User ID: 456.',
				'debug',
				[]
			);

		// Test the method.
		$this->service->schedule_save_payment_method( $webhook, 'test_hash' );
	}

	/**
	 * Test schedule_save_payment_method throws exception when fails.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::schedule_save_payment_method
	 * @return void
	 */
	public function test_schedule_save_payment_method_throws_exception_when_fails() : void {
		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )
			->once()
			->andReturn( 'invalid_order_id' );
		$webhook->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with(
				'Error scheduling save payment method from incoming webhook data. Error: "No valid customer ID in incoming data.".',
				'error',
				[]
			);

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'No valid customer ID in incoming data.' );
		$this->service->schedule_save_payment_method( $webhook, 'test_hash' );
	}

	/**
	 * Test save_payment_method_from_customer success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::save_payment_method_from_customer
	 * @return void
	 */
	public function test_save_payment_method_from_customer_success() : void {
		// Mock tokenization setting.
		$this->mock_tokenization_setting( true );

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )
			->once()
			->andReturn( '456-add_payment_method_key' );
		$webhook->shouldReceive( 'get_card_id' )
			->once()
			->andReturn( 'token_123' );
		$webhook->shouldReceive( 'get_type' )
			->times( 3 )
			->andReturn( 'webhook' );
		$webhook->shouldReceive( 'get_log_data' )
			->times( 3 )
			->andReturn( [] );

		// Mock WC_Customer.
		$customer = Mockery::mock( 'overload:WC_Customer' );
		$customer->shouldReceive( 'get_id' )
			->times( 4 )
			->andReturn( 456 );

		// Mock Card.
		$card = Mockery::mock( Card::class );
		$card->shouldReceive( 'get_card_id' )
			->once()
			->andReturn( 'token_123' );
		$card->shouldReceive( 'get_card_data' )
			->once()
			->andReturn(
				$this->get_test_card_data( 'valid' )
			);
		$card->shouldReceive( 'is_active' )
			->once()
			->andReturn( true );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_card' )
			->once()
			->with( 'token_123' )
			->andReturn( $card );

		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'set_token' )->once()->with( 'token_123' );
		$token->shouldReceive( 'set_gateway_id' )->once();
		$token->shouldReceive( 'set_card_type' )->once()->with( 'visa' );
		$token->shouldReceive( 'set_last4' )->once()->with( '1234' );
		$token->shouldReceive( 'set_expiry_month' )->once()->with( '06' );
		$token->shouldReceive( 'set_expiry_year' )->once()->with( '2025' );
		$token->shouldReceive( 'set_user_id' )->once()->with( 456 );
		$token->shouldReceive( 'validate' )->once()->andReturn( true );
		$token->shouldReceive( 'save' )->once();

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->times( 3 )
			->withArgs(
				function( $message ) {
					return in_array(
						$message,
						[
							'User found successfully from incoming webhook data. User ID: 456.',
							'Payment method found successfully from incoming webhook data. User ID: 456.',
							'Payment method saved successfully from incoming webhook data. User ID: 456.',
						],
						true
					);
				}
			);

		// Test the method.
		$this->service->save_payment_method_from_customer( $webhook );
	}

	/**
	 * Test save_payment_method_from_order success.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::save_payment_method_from_order
	 * @return void
	 */
	public function test_save_payment_method_from_order_success() : void {
		// Mock tokenization setting.
		$this->mock_tokenization_setting( true );

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )
			->once()
			->andReturn( '789-wc_order_key' );
		$webhook->shouldReceive( 'get_card_id' )
			->once()
			->andReturn( 'token_123' );
		$webhook->shouldReceive( 'get_log_data' )
			->times( 3 )
			->andReturn( [] );

		// Mock WC_Order.

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->times( 3 )->andReturn( 789 );
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_key' );
		$order->shouldReceive( 'get_user_id' )->once()->andReturn( 456 );
		$order->shouldReceive( 'add_payment_token' )->once();
		$order->shouldReceive( 'save' )->once();

		Functions\expect( 'wc_get_order' )
			->once()
			->with( '789' )
			->andReturn( $order );

		// Mock Card.
		$card = Mockery::mock( Card::class );
		$card->shouldReceive( 'get_card_id' )
			->once()
			->andReturn( 'token_123' );
		$card->shouldReceive( 'get_card_data' )
			->once()
			->andReturn(
				$this->get_test_card_data( 'valid' )
			);
		$card->shouldReceive( 'is_active' )
			->once()
			->andReturn( true );

		// Mock ApiClient.
		$this->get_api_client()
			->shouldReceive( 'get_card' )
			->once()
			->with( 'token_123' )
			->andReturn( $card );

		// Mock WC_Payment_Token_CC.
		$token = $this->mock_wc_payment_token();
		$token->shouldReceive( 'set_token' )->once()->with( 'token_123' );
		$token->shouldReceive( 'set_gateway_id' )->once();
		$token->shouldReceive( 'set_card_type' )->once()->with( 'visa' );
		$token->shouldReceive( 'set_last4' )->once()->with( '1234' );
		$token->shouldReceive( 'set_expiry_month' )->once()->with( '06' );
		$token->shouldReceive( 'set_expiry_year' )->once()->with( '2025' );
		$token->shouldReceive( 'set_user_id' )->once()->with( 456 );
		$token->shouldReceive( 'validate' )->once()->andReturn( true );
		$token->shouldReceive( 'save' )->once();

		// Mock LoggerService.
		$this->get_logger_service()
		->shouldReceive( 'log' )
		->times( 3 )
		->withArgs(
			function( $message ) {
				return in_array(
					$message,
					[
						'Order found successfully from incoming webhook data. Order ID: 789.',
						'Payment method found successfully from incoming webhook data. Order ID: 789.',
						'Payment method saved successfully from incoming webhook data. Order ID: 789.',
					],
					true
				);
			}
		);

		// Test the method.
		$this->service->save_payment_method_from_order( $webhook );
	}

	/**
	 * Test save_payment_method_from_order throws exception when order not found.
	 *
	 * @covers \AcquiredComForWooCommerce\Services\PaymentMethodService::save_payment_method_from_order
	 * @return void
	 */
	public function test_save_payment_method_from_order_throws_exception_when_order_not_found() : void {
		// Mock tokenization setting.
		$this->mock_tokenization_setting( true );

		// Mock WebhookData.
		$webhook = Mockery::mock( WebhookData::class );
		$webhook->shouldReceive( 'get_order_id' )
			->once()
			->andReturn( 'invalid_order' );
		$webhook->shouldReceive( 'get_log_data' )
			->once()
			->andReturn( [] );

		// Mock LoggerService.
		$this->get_logger_service()
			->shouldReceive( 'log' )
			->once()
			->with( 'Payment method saving failed. No valid order ID in incoming data.', 'error', [] );

		// Test the method.
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'No valid order ID in incoming data.' );
		$this->service->save_payment_method_from_order( $webhook );
	}
}
