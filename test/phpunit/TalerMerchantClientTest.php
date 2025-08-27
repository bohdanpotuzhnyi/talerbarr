<?php
// tests/TalerMerchantClientTest.php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers \TalerMerchantClient
 */
final class TalerMerchantClientTest extends TestCase
{
	private function queueResponse(int $code, string $body = '', int $curlErrNo = 0, string $curlErrMsg = ''): void
	{
		// Pushed into the stubbed getURLContent() queue
		_mock_http_queue_push([
			'http_code'      => $code,
			'content'        => $body,
			'curl_error_no'  => $curlErrNo,
			'curl_error_msg' => $curlErrMsg,
		]);
	}

	private function lastRequest(): array
	{
		return $GLOBALS['MOCK_HTTP_LAST'];
	}

	public function testConstructorBuildsBaseForAdmin(): void
	{
		$c = new TalerMerchantClient('https://merchant.example.com', 'tok', 'admin');

		// Prime a benign response so no exception is thrown when we call something.
		$this->queueResponse(200, '{"ok":true}');
		$c->get('config');

		$last = $this->lastRequest();
		$this->assertSame('GET', $last['method']);
		$this->assertSame('https://merchant.example.com/private/config', $last['url']);
	}

	public function testConstructorBuildsBaseForInstance(): void
	{
		$c = new TalerMerchantClient('https://merchant.example.com', 'tok', 'coffee');

		$this->queueResponse(200, '{"ok":true}');
		$c->get('config');

		$last = $this->lastRequest();
		$this->assertSame('https://merchant.example.com/instances/coffee/private/config', $last['url']);
	}

	public function testAuthorizationHeaderIsSent(): void
	{
		$c = new TalerMerchantClient(DEMO_BASE, DEMO_TOKEN, 'sandbox');

		$this->queueResponse(200, '{"hello":"world"}');
		$out = $c->get('ping');

		$last = $this->lastRequest();
		$this->assertContains('Authorization: Bearer '.DEMO_TOKEN, $last['headers']);
		$this->assertSame(['hello' => 'world'], $out);
	}

	public function testGetAppendsQueryString(): void
	{
		$c = new TalerMerchantClient('https://m.example/', 'tok', 'admin');

		$this->queueResponse(200, '{"ok":true}');
		$c->get('items', ['limit' => 10, 'offset' => 5]);

		$last = $this->lastRequest();
		$this->assertSame('https://m.example/private/items?limit=10&offset=5', $last['url']);
	}

	public function testPostSendsJsonBody(): void
	{
		$c = new TalerMerchantClient('https://m.example', 'tok', 'admin');

		$this->queueResponse(200, '{"id":123}');
		$c->post('things', ['a' => 1]);

		$last = $this->lastRequest();
		$this->assertSame('POST', $last['method']);
		$this->assertSame('{"a":1}', $last['payload']);
		$this->assertContains('Content-Type: application/json', $last['headers']);
	}

	public function testNon2xxThrowsException(): void
	{
		$c = new TalerMerchantClient('https://m.example', 'tok', 'admin');

		$this->queueResponse(404, 'not found');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('HTTP 404');
		$c->get('missing');
	}

	public function testListCategories_UsesBaseWithoutDuplicatingInstance(): void
	{
		// Given base ALREADY contains /instances/sandbox/private/
		$c = new TalerMerchantClient(DEMO_BASE, DEMO_TOKEN, 'sandbox');

		// Return a minimal valid payload
		$this->queueResponse(200, '{"categories": []}');
		$c->listCategories('sandbox');

		$last = $this->lastRequest();

		// ✅ Intended behavior:
		// should be "<base>categories" (no extra "/instances/sandbox/private/" prefix again)
		$this->assertSame(DEMO_BASE . 'categories', $last['url'],
			"When base already contains /instances/{instance}/private/, listCategories() must not duplicate it.");

		// If this assertion fails with URL like
		// ".../instances/sandbox/private//instances/sandbox/private/categories"
		// fix the client methods to use relative paths like 'categories' (without the /instances/.../private/ prefix).
	}

	public function testAddProduct_SendsToProductsOnCurrentBase(): void
	{
		$c = new TalerMerchantClient(DEMO_BASE, DEMO_TOKEN, 'sandbox');

		$this->queueResponse(204, '');
		$c->addProduct('sandbox', [
			'product_id'   => 'ABC',
			'product_name' => 'Coffee',
			'price'        => ['currency' => 'EUR', 'value' => '2.50'],
		]);

		$last = $this->lastRequest();
		$this->assertSame(DEMO_BASE . 'products', $last['url']);
		$this->assertSame('POST', $last['method']);
	}

	public function testUpdateProduct_HitsPatchOnCurrentBase(): void
	{
		$c = new TalerMerchantClient(DEMO_BASE, DEMO_TOKEN, 'sandbox');

		$this->queueResponse(204, '');
		$c->updateProduct('sandbox', 'ABC', ['product_name' => 'Espresso']);

		$last = $this->lastRequest();
		$this->assertSame(DEMO_BASE . 'products/ABC', $last['url']);
		$this->assertSame('PATCH', $last['method']);
	}

	public function testDeleteProduct_HitsDeleteOnCurrentBase(): void
	{
		$c = new TalerMerchantClient(DEMO_BASE, DEMO_TOKEN, 'sandbox');

		$this->queueResponse(204, '');
		$c->deleteProduct('sandbox', 'ABC');

		$last = $this->lastRequest();
		$this->assertSame(DEMO_BASE . 'products/ABC', $last['url']);
		$this->assertSame('DELETE', $last['method']);
	}
}
