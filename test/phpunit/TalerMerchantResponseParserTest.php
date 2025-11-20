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
