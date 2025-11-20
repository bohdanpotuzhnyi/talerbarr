<?php
declare(strict_types=1);

/**
 * TalerMerchantResponseParser
 *
 * Lightweight validators/normalisers for responses returned by the GNU Taler
 * merchant backend. Each parser checks that the minimal contract described in
 * the public API documentation is present before the data is consumed by the
 * module.
 *
 * All helpers return the (possibly normalised) payload or throw an
 * InvalidArgumentException when required fields are missing or of the wrong
 * type.
 */
class TalerMerchantResponseParser
{
	/**
	 * Validate /config payload.
	 *
	 * @param array $payload Version response body
	 * @return array Normalised payload
	 */
	public static function parseVersion(array $payload): array
	{
		self::requireString($payload, 'version', 'version response');
		self::requireString($payload, 'currency', 'version response');

		return $payload;
	}

	/**
	 * Validate categories list payload.
	 *
	 * @param array $payload Categories list response body
	 * @return array Normalised payload
	 */
	public static function parseCategoryList(array $payload): array
	{
		$categories = self::requireArray($payload, 'categories', 'categories list');
		$normalised = [];
		foreach ($categories as $idx => $cat) {
			if (!is_array($cat)) {
				throw new InvalidArgumentException("categories[$idx] must be an array");
			}
			$normalised[] = [
				'category_id'   => self::requireIntLike($cat, 'category_id', 'category'),
				'name'          => self::requireString($cat, 'name', 'category'),
				'product_count' => self::requireIntLike($cat, 'product_count', 'category'),
				'name_i18n'     => isset($cat['name_i18n']) && is_array($cat['name_i18n']) ? $cat['name_i18n'] : null,
			];
		}
		$payload['categories'] = $normalised;
		return $payload;
	}

	/**
	 * Validate category detail payload.
	 *
	 * @param array $payload Category detail response body
	 * @return array Normalised payload
	 */
	public static function parseCategory(array $payload): array
	{
		self::requireString($payload, 'name', 'category detail');
		$products = self::requireArray($payload, 'products', 'category detail');
		foreach ($products as $idx => $prod) {
			if (!is_array($prod) || !array_key_exists('product_id', $prod)) {
				throw new InvalidArgumentException("products[$idx] must contain product_id");
			}
		}
		return $payload;
	}

	/**
	 * Validate products summary list payload.
	 *
	 * @param array $payload Inventory summary response body
	 * @return array Normalised payload
	 */
	public static function parseInventorySummary(array $payload): array
	{
		$products = self::requireArray($payload, 'products', 'inventory summary');
		$normalised = [];
		foreach ($products as $idx => $prod) {
			if (!is_array($prod)) {
				throw new InvalidArgumentException("products[$idx] must be an array");
			}
			$normalised[] = [
				'product_id'    => self::requireString($prod, 'product_id', 'inventory summary'),
				'product_serial'=> self::requireIntLike($prod, 'product_serial', 'inventory summary'),
			];
		}
		$payload['products'] = $normalised;
		return $payload;
	}

	/**
	 * Validate product detail payload.
	 *
	 * @param array $payload Product detail response body
	 * @return array Normalised payload
	 */
	public static function parseProduct(array $payload): array
	{
		self::requireString($payload, 'product_name', 'product detail');
		self::requireString($payload, 'description', 'product detail');
		self::requireString($payload, 'unit_total_stock', 'product detail');
		self::requireString($payload, 'unit', 'product detail');
		self::requireIntLike($payload, 'unit_precision_level', 'product detail');

		if (isset($payload['categories'])) {
			if (!is_array($payload['categories'])) {
				throw new InvalidArgumentException('product detail categories must be an array');
			}
		}

		return $payload;
	}

	/**
	 * Validate PostOrderResponse payload.
	 *
	 * @param array $payload Post order response body
	 * @return array Normalised payload
	 */
	public static function parsePostOrderResponse(array $payload): array
	{
		self::requireString($payload, 'order_id', 'post order response');
		return $payload;
	}

	/**
	 * Validate OrderHistory payload.
	 *
	 * @param array $payload Order history response body
	 * @return array Normalised payload
	 */
	public static function parseOrderHistory(array $payload): array
	{
		$orders = self::requireArray($payload, 'orders', 'order history');
		foreach ($orders as $idx => $order) {
			if (!is_array($order)) {
				throw new InvalidArgumentException("orders[$idx] must be an array");
			}
			self::requireString($order, 'order_id', 'order history entry');
			if (isset($order['amount']) && !is_array($order['amount'])) {
				throw new InvalidArgumentException("orders[$idx].amount must be an array when present");
			}
		}
		return $payload;
	}

	/**
	 * Validate MerchantOrderStatusResponse payload.
	 *
	 * @param array $payload Order status response body
	 * @return array Normalised payload
	 */
	public static function parseOrderStatus(array $payload): array
	{
		self::requireString($payload, 'order_status', 'order status');
		return $payload;
	}

	/**
	 * Validate MerchantRefundResponse payload.
	 *
	 * @param array $payload Refund response body
	 * @return array Normalised payload
	 */
	public static function parseRefundResponse(array $payload): array
	{
		self::requireString($payload, 'taler_refund_uri', 'refund response');
		self::requireString($payload, 'h_contract', 'refund response');
		return $payload;
	}

	/**
	 * Validate webhook summary payload.
	 *
	 * @param array $payload Webhooks list response body
	 * @return array Normalised payload
	 */
	public static function parseWebhookList(array $payload): array
	{
		$webhooks = self::requireArray($payload, 'webhooks', 'webhooks list');
		$normalised = [];
		foreach ($webhooks as $idx => $webhook) {
			if (!is_array($webhook)) {
				throw new InvalidArgumentException("webhooks[$idx] must be an array");
			}
			$normalised[] = [
				'webhook_id' => self::requireString($webhook, 'webhook_id', 'webhook summary'),
				'event_type' => self::requireString($webhook, 'event_type', 'webhook summary'),
			];
		}
		$payload['webhooks'] = $normalised;
		return $payload;
	}

	/**
	 * Validate webhook detail payload.
	 *
	 * @param array $payload Webhook detail response body
	 * @return array Normalised payload
	 */
	public static function parseWebhook(array $payload): array
	{
		self::requireString($payload, 'event_type', 'webhook detail');
		self::requireString($payload, 'url', 'webhook detail');
		self::requireString($payload, 'http_method', 'webhook detail');
		return $payload;
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */
	/**
	 * Require a key to exist and be an array.
	 *
	 * @param array  $payload Response payload
	 * @param string $key     Key to check
	 * @param string $context Human-readable context for error messages
	 * @return array          The array value
	 */
	private static function requireArray(array $payload, string $key, string $context): array
	{
		if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
			throw new InvalidArgumentException("Missing or invalid '{$key}' in {$context}");
		}
		return $payload[$key];
	}

	/**
	 * Require a key to exist and be a non-empty string.
	 *
	 * @param array  $payload Response payload
	 * @param string $key     Key to check
	 * @param string $context Human-readable context for error messages
	 * @return string         The string value
	 */
	private static function requireString(array $payload, string $key, string $context): string
	{
		if (!array_key_exists($key, $payload) || !is_string($payload[$key]) || $payload[$key] === '') {
			throw new InvalidArgumentException("Missing or invalid '{$key}' in {$context}");
		}
		return $payload[$key];
	}

	/**
	 * Require a key to exist and be an integer or numeric string.
	 *
	 * @param array  $payload Response payload
	 * @param string $key     Key to check
	 * @param string $context Human-readable context for error messages
	 * @return int            Integer value
	 */
	private static function requireIntLike(array $payload, string $key, string $context): int
	{
		if (!array_key_exists($key, $payload)) {
			throw new InvalidArgumentException("Missing '{$key}' in {$context}");
		}
		$value = $payload[$key];
		if (is_int($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return (int) $value;
		}
		throw new InvalidArgumentException("Invalid integer '{$key}' in {$context}");
	}
}
