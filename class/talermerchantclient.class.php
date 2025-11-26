<?php
/* Copyright (C) 2025       Bohdan Potuzhnyi
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once __DIR__.'/talermerchantresponseparser.class.php';

/**
 * TalerMerchantClient
 *
 * Thin HTTP client for the GNU Taler Merchant backend.
 * Provides convenience wrappers for the inventory management API (categories & products).
 *
 * This client expects an OAuth2 Bearer token with the appropriate permissions
 * (e.g., categories-read, categories-write, products-read, products-write).
 *
 * Notes:
 * - $baseUrl should point to the merchant backend root (for example: "https://merchant.example.com/").
 * - All methods throw \Exception on HTTP errors (non-2xx) or JSON encoding/decoding errors.
 * - Uses Dolibarr's getURL() under the hood (curl mode).
 *
 * @package TalerBarr
 * @author  Bohdan Potuzhnyi <bohdan.potuzhnyi@gmail.com>
 * @license https://www.gnu.org/licenses/  GNU Affero General Public License v3 or later
 */
class TalerMerchantClient
{
	private const USERNAME_REGEX = '~^[A-Za-z0-9][A-Za-z0-9_-]*$~';

	/** @var string Base URL of the merchant backend as entered by the user (no trailing slash). */
	private string $rootUrl;
	/** @var string Fully qualified API base (always ending with a trailing slash). */
	private string $apiBase;
	/** @var string OAuth2 bearer token used for Authorization header. */
	private string $token;
	/** @var string Instance identifier carried around for debugging/logging. */
	private string $instance;

	/**
	 * Constructor.
	 *
	 * @param string $baseUrl  Base URL of the Taler Merchant backend; trailing slash optional.
	 * @param string $token    OAuth2 Bearer token.
	 * @param string $instance Instance name for the $token and $baseUrl
	 */
	public function __construct(string $baseUrl, string $token, string $instance = 'admin')
	{
		$normalized = rtrim(trim($baseUrl), '/');
		$this->rootUrl = $normalized;

		$instance = trim($instance);
		if ($instance === '') {
			throw new \InvalidArgumentException('Username must not be empty');
		}
		if (!preg_match(self::USERNAME_REGEX, $instance)) {
			throw new \InvalidArgumentException('Username must be URL-safe (letters, digits, _ and -)');
		}
		$this->instance = $instance;

		if (!preg_match('~/private/?$~', $normalized)) {
			$this->apiBase = ($instance === 'admin')
				? $normalized.'/private/'
				: $normalized.'/instances/'.rawurlencode($instance).'/private/';
		} else {
			$this->apiBase = $normalized.'/';
		}

		$this->token = trim($token);
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string     $path  Relative path (may start with '/').
	 * @param null|array $query Optional key=>value query parameters.
	 *
	 * @return array Decoded JSON response as associative array.
	 * @throws Exception On HTTP error or JSON parse failure.
	 */
	public function get(string $path, ?array $query = null): array
	{
		return $this->request('GET', $path, null, $query);
	}

	/**
	 * Perform a POST request with a JSON body.
	 *
	 * @param string     $path  Relative path (may start with '/').
	 * @param array      $json  Request payload to be JSON-encoded.
	 * @param null|array $query Optional key=>value query parameters.
	 *
	 * @return array Decoded JSON response as associative array (if any).
	 * @throws Exception On HTTP error or JSON encode/parse failure.
	 */
	public function post(string $path, array $json, ?array $query = null): array
	{
		return $this->request('POST', $path, $json, $query);
	}

	/**
	 * Perform a PUT request with a JSON body.
	 *
	 * @param string     $path  Relative path (may start with '/').
	 * @param array      $json  Request payload to be JSON-encoded.
	 * @param null|array $query Optional key=>value query parameters.
	 *
	 * @return array Decoded JSON response as associative array (if any).
	 * @throws Exception On HTTP error or JSON encode/parse failure.
	 */
	public function put(string $path, array $json, ?array $query = null): array
	{
		return $this->request('PUT', $path, $json, $query);
	}

	/**
	 * Perform a PATCH request with a JSON body.
	 *
	 * @param string     $path  Relative path (may start with '/').
	 * @param array      $json  Request payload to be JSON-encoded.
	 * @param null|array $query Optional key=>value query parameters.
	 *
	 * @return array Decoded JSON response as associative array (if any).
	 * @throws Exception On HTTP error or JSON encode/parse failure.
	 */
	public function patch(string $path, array $json, ?array $query = null): array
	{
		return $this->request('PATCHALREADYFORMATED', $path, $json, $query);
	}

	/**
	 * Perform a DELETE request.
	 *
	 * @param string     $path  Relative path (may start with '/').
	 * @param null|array $query Optional key=>value query parameters.
	 *
	 * @return array Decoded JSON response as associative array (if any).
	 * @throws Exception On HTTP error or JSON parse failure.
	 */
	public function delete(string $path, ?array $query = null): array
	{
		return $this->request('DELETE', $path, null, $query);
	}

	/**
	 * Low-level HTTP request helper around Dolibarr's getURL().
	 *
	 * @param string          $verb  HTTP verb ('GET','POST','PUT','PATCH','PATCHALREADYFORMATED','DELETE').
	 * @param string          $path  Relative path (may start with '/').
	 * @param null|array      $body  If given, JSON-encoded into request body.
	 * @param null|array      $query Optional query parameters to append to URL.
	 * @param int             $timeoutSeconds Request timeout in seconds (default 30).
	 *
	 * @return array Decoded JSON body as associative array (empty array on empty body).
	 * @throws Exception On HTTP error, JSON encode/parse failure, or transport error.
	 */
	private function request(string $verb, string $path, ?array $body = null, ?array $query = null, int $timeoutSeconds = 30): array
	{
		if ($this->token === '') {
			throw new \InvalidArgumentException('Missing merchant secret token in config');
		}

		$url = $this->apiBase.ltrim($path, '/');
		if (!empty($query)) {
			$q = http_build_query($query);
			$url .= (!str_contains($url, '?') ? '?' : '&').$q;
		}

		$headers = [
			'Accept: application/json',
			'Authorization: Bearer '.$this->token,
		];

		$payload = '';
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$payload   = json_encode($body, JSON_UNESCAPED_UNICODE);
			if ($payload === false) {
				throw new Exception('Failed to JSON-encode request body: '.json_last_error_msg());
			}
		}

		// Dolibarr-native HTTP call
		// getURLContent(
		//   $url, $method, $parameters, $followlocation, $addheaders,
		//   $allowed_schemes, $disablesslchecks, $proxy, $connecttimeout, $timeout
		// )
		$res = getURLContent(
			$url,
			$verb,
			$payload,              // body for POST/PUT/PATCH, empty for GET/DELETE
			1,          // follow redirects
			$headers,
			array('http','https'),
			2,              // allow all URLs (same as in your verifyConfig)
			-1,         // auto SSL verification (Dolibarr decides)
			5,        // connect timeout (seconds)
			$timeoutSeconds        // overall timeout (seconds)
		);

		$http = isset($res['http_code']) ? (int) $res['http_code'] : 0;
		if ($http < 200 || $http >= 300) {
			$netErr = '';
			if (!empty($res['curl_error_no'])) {
				$netErr = ' cURL#'.$res['curl_error_no'].(!empty($res['curl_error_msg']) ? ' '.$res['curl_error_msg'] : '');
			}
			$bodyTxt = isset($res['content']) ? (string) $res['content'] : '';
			throw new Exception("HTTP {$http} for {$verb} {$url}: ".($bodyTxt !== '' ? $bodyTxt : 'Transport/HTTP error'.$netErr));
		}

		$content = $res['content'] ?? '';
		if ($content === '' || $content === null) {
			return [];
		}

		$decoded = json_decode($content, true);
		if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Failed to decode JSON response: '.json_last_error_msg());
		}
		return $decoded ?? [];
	}

	/* =======================================================================
	 * Inventory: Categories (since API v16)
	 * =======================================================================
	 */

	/**
	 * List categories with product counts.
	 *
	 * Required permission: categories-read
	 *
	 * @return array{categories: array<int, array{category_id:int,name:string,product_count:int,name_i18n?:array<string,string>}>}
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function listCategories(): array
	{
		$path = "categories";
		$raw = $this->get($path);
		return TalerMerchantResponseParser::parseCategoryList($raw);
	}

	/**
	 * Get a single category with its product IDs.
	 *
	 * Required permission: categories-read
	 *
	 * @param int    $categoryId Category identifier.
	 * @return array{name:string,products:array<int,array{product_id:string}>,name_i18n?:array<string,string>}
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function getCategory(int $categoryId): array
	{
		$path = "categories/{$categoryId}";
		$raw = $this->get($path);
		return TalerMerchantResponseParser::parseCategory($raw);
	}

	/**
	 * Create a new category.
	 *
	 * Required permission: categories-write
	 *
	 * @param string               $name     Category name.
	 * @param array<string,string> $nameI18n Optional translations (lang_tag => name).
	 * @return int The newly created category_id.
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function createCategory(string $name, array $nameI18n = []): int
	{
		$path   = "categories";
		$body   = ['name' => $name];
		if (!empty($nameI18n)) {
			$body['name_i18n'] = $nameI18n;
		}
		$result = $this->post($path, $body);
		return (int) ($result['category_id'] ?? 0);
	}

	/**
	 * Update (edit) a category.
	 *
	 * Required permission: categories-write
	 * Returns 204 No Content on success.
	 *
	 * @param int                  $categoryId Category identifier.
	 * @param string               $name       New name.
	 * @param array<string,string> $nameI18n   Optional translations (lang_tag => name).
	 * @return void
	 * @throws Exception On HTTP errors.
	 */
	public function updateCategory(int $categoryId, string $name, array $nameI18n = []): void
	{
		$path = "categories/{$categoryId}";
		$body = ['name' => $name];
		if (!empty($nameI18n)) {
			$body['name_i18n'] = $nameI18n;
		}
		$this->patch($path, $body);
	}

	/**
	 * Delete a category.
	 *
	 * Required permission: categories-write
	 * Returns 204 No Content on success.
	 *
	 * @param int    $categoryId Category identifier.
	 * @return void
	 * @throws Exception On HTTP errors (404 if unknown).
	 */
	public function deleteCategory(int $categoryId): void
	{
		$path = "categories/{$categoryId}";
		$this->delete($path);
	}

	/* =======================================================================
	 * Inventory: Products
	 * =======================================================================
	 */

	/**
	 * Add a product to the inventory.
	 *
	 * Required permission: products-write
	 * Returns 204 No Content on success.
	 *
	 * @param array  $product  ProductAddDetail structure:
	 *                         [
	 *                         'product_id'   => string (required),
	 *                         'product_name' => string,           // v20+, should be treated as required going forward
	 *                         'description'  => string,
	 *                         'description_i18n' => array<string,string>,
	 *                         'categories'   => int[],            // v16+
	 *                         'unit'         => string,
	 *                         'price'        => array,            // Amount {currency:string, value:string} or backend-specific
	 *                         'image'        => string,           // data URL
	 *                         'taxes'        => array,            // Tax[]
	 *                         'total_stock'  => int,              // -1 for infinite
	 *                         'address'      => array,            // Location
	 *                         'next_restock' => int,              // Timestamp
	 *                         'minimum_age'  => int,
	 *                         ]
	 * @return void
	 * @throws Exception On HTTP errors (409 if product ID exists with different details).
	 */
	public function addProduct(array $product): void
	{
		$path = "products";
		$this->post($path, $product);
	}

	/**
	 * Update (patch) product details.
	 *
	 * Required permission: products-write
	 * Returns 204 No Content on success.
	 *
	 * @param string $productId Product identifier.
	 * @param array  $patch     ProductPatchDetail structure (all fields optional but validated by backend):
	 *                          [
	 *                          'product_name' => string,
	 *                          'description'  => string,
	 *                          'description_i18n' => array<string,string>,
	 *                          'unit'         => string,
	 *                          'categories'   => int[],
	 *                          'price'        => array,           // Amount
	 *                          'image'        => string,          // data URL
	 *                          'taxes'        => array,           // Tax[]
	 *                          'total_stock'  => int,
	 *                          'total_lost'   => int,
	 *                          'address'      => array,           // Location
	 *                          'next_restock' => int,             // Timestamp (use special values per API)
	 *                          'minimum_age'  => int,
	 *                          ]
	 * @return void
	 * @throws Exception On HTTP errors (404 unknown product, 409 conflict).
	 */
	public function updateProduct(string $productId, array $patch): void
	{
		$path = "products/".rawurlencode($productId);
		$this->patch($path, $patch);
	}

	/**
	 * List products in the inventory (summary).
	 *
	 * Required permission: products-read
	 *
	 * @param int      $limit    Optional limit. Negative => descending by row ID, positive => ascending. Default 20.
	 * @param int|null $offset   Optional starting product_serial_id for iteration.
	 * @return array{products: array<int, array{product_id:string,product_serial:int}>}
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function listProducts(int $limit = 20, ?int $offset = null): array
	{
		$path  = "products";
		$query = ['limit' => $limit];
		if ($offset !== null) {
			$query['offset'] = $offset;
		}
		$raw = $this->get($path, $query);
		return TalerMerchantResponseParser::parseInventorySummary($raw);
	}

	/**
	 * Get full details for a single product.
	 *
	 * Required permission: products-read
	 *
	 * @param string $productId Product identifier.
	 * @return array ProductDetail structure per API (description, categories, price, stocks, etc.).
	 * @throws Exception On HTTP/JSON errors (404 if unknown).
	 */
	public function getProduct(string $productId): array
	{
		$path = "products/".rawurlencode($productId);
		$raw = $this->get($path);
		try {
			return TalerMerchantResponseParser::parseProduct($raw);
		} catch (Throwable $e) {
			$payloadForLog = is_array($raw) ? $raw : array('raw_type' => gettype($raw));
			if (isset($payloadForLog['image'])) {
				unset($payloadForLog['image']); // avoid logging large blobs
			}
			$payloadSnippet = json_encode($payloadForLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
			if ($payloadSnippet === false) {
				$payloadSnippet = substr(print_r($payloadForLog, true), 0, 800);
			}
			$message = 'Error parsing product '.$productId.': '.$e->getMessage();
			if (!empty($payloadSnippet)) {
				$message .= ' Payload='.$payloadSnippet;
			}
			throw new InvalidArgumentException($message, 0, $e);
		}
	}

	/**
	 * Delete a product.
	 *
	 * Required permission: products-write
	 * Returns 204 No Content on success.
	 *
	 * @param string $productId Product identifier.
	 * @return void
	 * @throws Exception On HTTP errors (404 unknown, 409 locked).
	 */
	public function deleteProduct(string $productId): void
	{
		$path = "products/".rawurlencode($productId);
		$this->delete($path);
	}

	/* =======================================================================
	 * Payment: Orders
	 * =======================================================================
	 */

	/**
	 * Create a new order that a wallet can pay for.
	 *
	 * Required permission: orders-write
	 *
	 * @param $postOrderRequest payload per Taler merchant API.
	 * @return array{order_id:string,token?:string}
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function createOrder(array $postOrderRequest): array
	{
		$path = "orders";
		$raw = $this->post($path, $postOrderRequest);
		return TalerMerchantResponseParser::parsePostOrderResponse($raw);
	}

	/**
	 * List orders with optional filters (paid/refunded/wired/session/etc).
	 *
	 * Required permission: orders-read
	 *
	 * @param array<string, mixed> $query Optional query string parameters (leave values null to skip).
	 * @return array{orders: array<int, array<string, mixed>>}
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function listOrders(array $query = []): array
	{
		$path = "orders";
		$query = array_filter($query,
			static function ($value) {
				return $value !== null;
			});

		$raw = $this->get($path, $query);
		try {
			return TalerMerchantResponseParser::parseOrderHistory($raw);
		} catch (Throwable $e) {
			$payloadForLog = is_array($raw) ? $raw : array('raw_type' => gettype($raw));
			$payloadSnippet = json_encode($payloadForLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
			if ($payloadSnippet === false) {
				$payloadSnippet = substr(print_r($payloadForLog, true), 0, 800);
			}
			$message = 'Error parsing order history: '.$e->getMessage();
			if (!empty($payloadSnippet)) {
				$message .= ' Payload='.$payloadSnippet;
			}
			throw new InvalidArgumentException($message, 0, $e);
		}
	}

	/**
	 * Inspect a single order and its payment status.
	 *
	 * Required permission: orders-read
	 *
	 * @param string               $orderId Order identifier.
	 * @param array<string, mixed> $query   Optional query parameters (session_id, timeout_ms, allow_refunded_for_repurchase).
	 * @return array<string, mixed>
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function getOrderStatus(string $orderId, array $query = []): array
	{
		$path = "orders/".rawurlencode($orderId);
		$query = array_filter($query,
			static function ($value) {
				return $value !== null;
			});

		$raw = $this->get($path, $query);
		return TalerMerchantResponseParser::parseOrderStatus($raw);
	}

	/**
	 * Forget sensitive fields from an order contract.
	 *
	 * Required permission: orders-write
	 *
	 * @param string            $orderId Order identifier.
	 * @param array<int,string> $fields  JSON-path-like entries referencing forgettable fields.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When $fields is empty.
	 * @throws Exception On HTTP errors (400 malformed, 404 unknown order, 409 not forgettable).
	 */
	public function forgetOrderFields(string $orderId, array $fields): array
	{
		if (empty($fields)) {
			throw new \InvalidArgumentException('At least one forgettable field path must be provided.');
		}

		$path = "orders/".rawurlencode($orderId)."/forget";
		return $this->patch($path, ['fields' => array_values($fields)]);
	}

	/**
	 * Delete an order from the backend.
	 *
	 * Required permission: orders-write
	 *
	 * @param string $orderId Order identifier.
	 * @return void
	 * @throws Exception On HTTP errors (404 unknown order, 409 conflict).
	 */
	public function deleteOrder(string $orderId): void
	{
		$path = "orders/".rawurlencode($orderId);
		$this->delete($path);
	}

	/**
	 * Request a refund for a paid order.
	 *
	 * Required permission: orders-refund
	 *
	 * @param string $orderId       Order identifier.
	 * @param array  $refundRequest RefundRequest payload (refund amount and reason).
	 * @return array{taler_refund_uri:string,h_contract:string}
	 * @throws Exception On HTTP errors (403 forbidden, 404 unknown, 409 conflict, 410 gone, 451 unavailable).
	 */
	public function refundOrder(string $orderId, array $refundRequest): array
	{
		$path = "orders/".rawurlencode($orderId)."/refund";
		$raw = $this->post($path, $refundRequest);
		return TalerMerchantResponseParser::parseRefundResponse($raw);
	}

	/* =======================================================================
	 * Merchant: Webhooks
	 * =======================================================================
	 */

	/**
	 * List configured webhooks for the current instance.
	 *
	 * Required permission: webhooks-read
	 *
	 * @return array{webhooks: array<int, array{webhook_id:string,event_type:string}>}
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function listWebhooks(): array
	{
		$raw = $this->get('webhooks');
		return TalerMerchantResponseParser::parseWebhookList($raw);
	}

	/**
	 * Fetch full details for a specific webhook.
	 *
	 * Required permission: webhooks-read
	 *
	 * @param string $webhookId Identifier of the webhook.
	 * @return array<string, mixed>
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function getWebhook(string $webhookId): array
	{
		$raw = $this->get('webhooks/'.rawurlencode($webhookId));
		return TalerMerchantResponseParser::parseWebhook($raw);
	}

	/**
	 * Create a webhook.
	 *
	 * Required permission: webhooks-write
	 * Returns 204 No Content on success.
	 *
	 * @param array<string, mixed> $webhook WebhookAddDetails payload.
	 * @return void
	 * @throws Exception On HTTP/JSON errors (including 409 conflicts).
	 */
	public function createWebhook(array $webhook): void
	{
		$this->post('webhooks', $webhook);
	}

	/**
	 * Update an existing webhook.
	 *
	 * Required permission: webhooks-write
	 * Returns 204 No Content on success.
	 *
	 * @param string               $webhookId Webhook identifier.
	 * @param array<string, mixed> $patch     WebhookPatchDetails payload.
	 * @return void
	 * @throws Exception On HTTP/JSON errors (404 unknown, 409 conflict).
	 */
	public function updateWebhook(string $webhookId, array $patch): void
	{
		$this->patch('webhooks/'.rawurlencode($webhookId), $patch);
	}

	/**
	 * Delete a webhook.
	 *
	 * Required permission: webhooks-write
	 * Returns 204 No Content on success.
	 *
	 * @param string $webhookId Webhook identifier.
	 * @return void
	 * @throws Exception On HTTP errors (404 unknown webhook).
	 */
	public function deleteWebhook(string $webhookId): void
	{
		$this->delete('webhooks/'.rawurlencode($webhookId));
	}

	/**
	 * Fetch merchant /config from the root backend URL.
	 *
	 * @return array<string, mixed>
	 * @throws Exception On HTTP/JSON errors.
	 */
	public function getBackendConfig(): array
	{
		$url = $this->rootUrl.'/config';

		$res = getURLContent(
			$url,
			'GET',
			'',
			1,
			['Accept: application/json'],
			array('http', 'https'),
			2,
			-1,
			5,
			30
		);

		$http = isset($res['http_code']) ? (int) $res['http_code'] : 0;
		$netErr = '';
		if (!empty($res['curl_error_no'])) {
			$netErr = ' cURL#'.$res['curl_error_no'].(!empty($res['curl_error_msg']) ? ' '.$res['curl_error_msg'] : '');
		}
		$bodyTxt = isset($res['content']) ? (string) $res['content'] : '';

		if ($http < 200 || $http >= 300) {
			throw new Exception("HTTP {$http} for GET {$url}: ".($bodyTxt !== '' ? $bodyTxt : 'Transport/HTTP error'.$netErr));
		}

		if ($bodyTxt === '') {
			return [];
		}

		$decoded = json_decode($bodyTxt, true);
		if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Failed to decode JSON response: '.json_last_error_msg());
		}

		$parsed = $decoded ?? [];
		return TalerMerchantResponseParser::parseVersion($parsed);
	}
}
