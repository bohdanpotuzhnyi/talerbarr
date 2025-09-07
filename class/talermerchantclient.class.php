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
 * @author  Bohdan Potuzhnyi <bohdan.potuzhnyi@gmail.com>
 * @license https://www.gnu.org/licenses/  GNU Affero General Public License v3 or later
 */
class TalerMerchantClient
{
	/** @var string Base URL of the merchant backend, always ending with a single slash. */
	private $base;
	/** @var string OAuth2 bearer token used for Authorization header. */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $baseUrl  Base URL of the Taler Merchant backend; trailing slash optional.
	 * @param string $token    OAuth2 Bearer token.
	 * @param string $instance Instance name for the $token and $baseUrl
	 */
	public function __construct(string $baseUrl, string $token, string $instance = 'admin')
	{
		$baseUrl = rtrim(trim($baseUrl), '/');
		if (!preg_match('~/private/?$~', $baseUrl)) {
			$baseUrl .= ($instance === 'admin')
				? '/private/'
				: '/instances/'.rawurlencode($instance).'/private/';
		}
		$this->base = $baseUrl;
		$this->token = $token;
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
	 * @param string          $verb  HTTP verb ('GET','POST','PUT','PATCH','DELETE').
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
		$url = $this->base.ltrim($path, '/');
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
			$payload,                     // body for POST/PUT/PATCH, empty for GET/DELETE
			1,                            // follow redirects
			$headers,
			array('http','https'),
			2,                            // allow all URLs (same as in your verifyConfig)
			-1,                           // auto SSL verification (Dolibarr decides)
			5,                            // connect timeout (seconds)
			$timeoutSeconds               // overall timeout (seconds)
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
		return $this->get($path);
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
		return $this->get($path);
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
		return $this->get($path, $query);
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
		return $this->get($path);
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
}
