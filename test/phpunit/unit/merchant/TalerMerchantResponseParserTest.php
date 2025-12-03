<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../class/talermerchantresponseparser.class.php';

/**
 * Unit coverage for TalerMerchantResponseParser.
 */
class TalerMerchantResponseParserTest extends TestCase
{
	/**
	 * Ensure version payload requires version and currency.
	 *
	 * @return void
	 */
	public function testParseVersionRequiresFields(): void
	{
		$payload = [
			'version' => '24:0:0',
			'currency' => 'KUDOS',
		];
		$result = TalerMerchantResponseParser::parseVersion($payload);
		$this->assertSame('24:0:0', $result['version']);
		$this->assertSame('KUDOS', $result['currency']);
	}

	/**
	 * Missing version should raise an exception.
	 *
	 * @return void
	 */
	public function testParseVersionFailsWhenMissingVersion(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseVersion(['currency' => 'KUDOS']);
	}

	/**
	 * Missing currency should raise an exception.
	 *
	 * @return void
	 */
	public function testParseVersionFailsWhenMissingCurrency(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseVersion(['version' => '24:0:0']);
	}

	/**
	 * Category list entries are normalised and validated.
	 *
	 * @return void
	 */
	public function testParseCategoryListNormalisesEntries(): void
	{
		$payload = [
			'categories' => [
				['category_id' => 1, 'name' => 'Foo', 'product_count' => 2],
			],
		];
		$result = TalerMerchantResponseParser::parseCategoryList($payload);
		$this->assertSame(1, $result['categories'][0]['category_id']);
		$this->assertSame('Foo', $result['categories'][0]['name']);
	}

	/**
	 * Missing product_count should fail category list parsing.
	 *
	 * @return void
	 */
	public function testParseCategoryListRejectsMissingProductCount(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$payload = [
			'categories' => [
				['category_id' => 1, 'name' => 'Foo'],
			],
		];
		TalerMerchantResponseParser::parseCategoryList($payload);
	}

	/**
	 * Core product fields must exist.
	 *
	 * @return void
	 */
	public function testParseProductRequiresCoreFields(): void
	{
		$payload = [
			'product_name' => 'Widget',
			'description' => 'Sample',
			'unit_total_stock' => '12',
			'unit' => 'piece',
			'unit_precision_level' => 0,
		];
		$result = TalerMerchantResponseParser::parseProduct($payload);
		$this->assertSame('Widget', $result['product_name']);
	}

	/**
	 * total_stock can be used when unit_total_stock is missing.
	 *
	 * @return void
	 */
	public function testParseProductAcceptsTotalStockFallback(): void
	{
		$payload = [
			'product_name' => 'Widget',
			'description' => 'Sample',
			'total_stock' => '7',
			'unit' => 'piece',
			'unit_precision_level' => 0,
		];
		$result = TalerMerchantResponseParser::parseProduct($payload);
		$this->assertSame('7', $result['unit_total_stock']);
	}

	/**
	 * Numeric unit_total_stock is accepted and normalised to string.
	 *
	 * @return void
	 */
	public function testParseProductAcceptsNumericUnitStock(): void
	{
		$payload = [
			'product_name' => 'Widget',
			'description' => 'Sample',
			'unit_total_stock' => 5,
			'unit' => 'piece',
			'unit_precision_level' => 0,
		];
		$result = TalerMerchantResponseParser::parseProduct($payload);
		$this->assertSame('5', $result['unit_total_stock']);
	}

	/**
	 * Missing precision defaults to 0 and unit_allow_fraction defaults to false.
	 *
	 * @return void
	 */
	public function testParseProductDefaultsPrecisionAndFraction(): void
	{
		$payload = [
			'product_name' => 'Widget',
			'description' => 'Sample',
			'unit_total_stock' => '3',
			'unit' => 'piece',
		];
		$result = TalerMerchantResponseParser::parseProduct($payload);
		$this->assertSame(0, $result['unit_precision_level']);
		$this->assertFalse($result['unit_allow_fraction']);
	}

	/**
	 * Missing unit defaults to Piece.
	 *
	 * @return void
	 */
	public function testParseProductDefaultsUnitToPiece(): void
	{
		$payload = [
			'product_name' => 'Widget',
			'unit_total_stock' => '2',
		];
		$result = TalerMerchantResponseParser::parseProduct($payload);
		$this->assertSame('Piece', $result['unit']);
	}

	/**
	 * Missing description falls back to product name.
	 *
	 * @return void
	 */
	public function testParseProductDefaultsDescriptionToName(): void
	{
		$payload = [
			'product_name' => 'Widget',
			'description' => '',
			'unit_total_stock' => '2',
			'unit' => 'Piece',
		];
		$result = TalerMerchantResponseParser::parseProduct($payload);
		$this->assertSame('Widget', $result['description']);
	}

	/**
	 * Missing stock fields default to 0 instead of erroring.
	 *
	 * @return void
	 */
	public function testParseProductDefaultsStockToZero(): void
	{
		$payload = [
			'product_name' => 'Widget',
			'description' => 'Sample',
			'unit' => 'piece',
			'unit_precision_level' => 0,
		];
		$result = TalerMerchantResponseParser::parseProduct($payload);
		$this->assertSame('0', $result['unit_total_stock']);
	}

	/**
	 * Order history tolerates scalar amount by wrapping it.
	 *
	 * @return void
	 */
	public function testParseOrderHistoryWrapsScalarAmount(): void
	{
		$payload = [
			'orders' => [
				[
					'order_id' => 'abc',
					'amount' => 'EUR:5',
				],
			],
		];
		$result = TalerMerchantResponseParser::parseOrderHistory($payload);
		$this->assertSame('EUR:5', $result['orders'][0]['amount']['raw']);
	}

	/**
	 * Order status requires the order_status field.
	 *
	 * @return void
	 */
	public function testParseOrderStatusRequiresStatus(): void
	{
		$payload = [
			'order_status' => 'paid',
			'refund_amount' => ['currency' => 'KUDOS', 'value' => '0'],
		];
		$result = TalerMerchantResponseParser::parseOrderStatus($payload);
		$this->assertSame('paid', $result['order_status']);
	}

	/**
	 * Category detail requires name and product_id entries.
	 *
	 * @return void
	 */
	public function testParseCategoryRequiresProductsWithProductId(): void
	{
		$payload = [
			'name' => 'Drinks',
			'products' => [
				['product_id' => 'cola'],
			],
		];
		$result = TalerMerchantResponseParser::parseCategory($payload);
		$this->assertSame('Drinks', $result['name']);
		$this->assertSame('cola', $result['products'][0]['product_id']);
	}

	/**
	 * Category detail should reject product rows without product_id.
	 *
	 * @return void
	 */
	public function testParseCategoryRejectsMissingProductId(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseCategory([
			'name' => 'Drinks',
			'products' => [
				['name' => 'cola'],
			],
		]);
	}

	/**
	 * Inventory summary validates and normalises product rows.
	 *
	 * @return void
	 */
	public function testParseInventorySummaryNormalisesEntries(): void
	{
		$payload = [
			'products' => [
				['product_id' => 'prod-1', 'product_serial' => '17'],
			],
		];
		$result = TalerMerchantResponseParser::parseInventorySummary($payload);
		$this->assertSame('prod-1', $result['products'][0]['product_id']);
		$this->assertSame(17, $result['products'][0]['product_serial']);
	}

	/**
	 * Inventory summary should reject non-array product rows.
	 *
	 * @return void
	 */
	public function testParseInventorySummaryRejectsInvalidProductRow(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseInventorySummary([
			'products' => ['bad-row'],
		]);
	}

	/**
	 * Post order response requires order_id.
	 *
	 * @return void
	 */
	public function testParsePostOrderResponseRequiresOrderId(): void
	{
		$result = TalerMerchantResponseParser::parsePostOrderResponse(['order_id' => 'ORD-1']);
		$this->assertSame('ORD-1', $result['order_id']);
	}

	/**
	 * Post order response should fail without order_id.
	 *
	 * @return void
	 */
	public function testParsePostOrderResponseRejectsMissingOrderId(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parsePostOrderResponse([]);
	}

	/**
	 * Refund response requires taler_refund_uri and h_contract.
	 *
	 * @return void
	 */
	public function testParseRefundResponseRequiresFields(): void
	{
		$payload = [
			'taler_refund_uri' => 'taler://refund/example',
			'h_contract' => 'ABC123',
		];
		$result = TalerMerchantResponseParser::parseRefundResponse($payload);
		$this->assertSame('taler://refund/example', $result['taler_refund_uri']);
		$this->assertSame('ABC123', $result['h_contract']);
	}

	/**
	 * Refund response should fail when h_contract is missing.
	 *
	 * @return void
	 */
	public function testParseRefundResponseRejectsMissingContractHash(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseRefundResponse([
			'taler_refund_uri' => 'taler://refund/example',
		]);
	}

	/**
	 * Webhook list validates webhook rows.
	 *
	 * @return void
	 */
	public function testParseWebhookListNormalisesEntries(): void
	{
		$payload = [
			'webhooks' => [
				['webhook_id' => 'wh-1', 'event_type' => 'pay'],
			],
		];
		$result = TalerMerchantResponseParser::parseWebhookList($payload);
		$this->assertSame('wh-1', $result['webhooks'][0]['webhook_id']);
		$this->assertSame('pay', $result['webhooks'][0]['event_type']);
	}

	/**
	 * Webhook list should fail when event_type is missing.
	 *
	 * @return void
	 */
	public function testParseWebhookListRejectsMissingEventType(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseWebhookList([
			'webhooks' => [
				['webhook_id' => 'wh-1'],
			],
		]);
	}

	/**
	 * Webhook detail requires event_type, url and http_method.
	 *
	 * @return void
	 */
	public function testParseWebhookRequiresCoreFields(): void
	{
		$payload = [
			'event_type' => 'pay',
			'url' => 'https://example.test/hook',
			'http_method' => 'POST',
		];
		$result = TalerMerchantResponseParser::parseWebhook($payload);
		$this->assertSame('pay', $result['event_type']);
	}

	/**
	 * Webhook detail should reject missing url.
	 *
	 * @return void
	 */
	public function testParseWebhookRejectsMissingUrl(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseWebhook([
			'event_type' => 'pay',
			'http_method' => 'POST',
		]);
	}

	/**
	 * Order history should reject entries without order_id.
	 *
	 * @return void
	 */
	public function testParseOrderHistoryRejectsMissingOrderId(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseOrderHistory([
			'orders' => [
				['amount' => 'EUR:1'],
			],
		]);
	}

	/**
	 * Product detail should reject non-array categories.
	 *
	 * @return void
	 */
	public function testParseProductRejectsInvalidCategoriesType(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseProduct([
			'product_name' => 'Widget',
			'description' => 'Sample',
			'unit_total_stock' => '1',
			'unit' => 'Piece',
			'unit_precision_level' => 0,
			'categories' => 'wrong',
		]);
	}

	/**
	 * Product detail should reject non-numeric precision.
	 *
	 * @return void
	 */
	public function testParseProductRejectsInvalidPrecision(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TalerMerchantResponseParser::parseProduct([
			'product_name' => 'Widget',
			'description' => 'Sample',
			'unit_total_stock' => '1',
			'unit' => 'Piece',
			'unit_precision_level' => 'not-a-number',
		]);
	}
}
