<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../class/talermerchantresponseparser.class.php';

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
}
