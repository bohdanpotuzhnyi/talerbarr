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

/**
 * \file        talerbarr/webhook/webhook.php
 * \ingroup     talerbarr
 * \brief       Lightweight webhook listener for the Taler synchronisation.
 *
 * @package    Application
 */

// Minimal Dolibarr init – no login, session, CSRF, etc.
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOSCANGETFORINJECTION', 1);
define('NOSCANPOSTFORINJECTION', 1);

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

// Load required classes
dol_include_once('/talerbarr/class/talerconfig.class.php');
dol_include_once('/talerbarr/class/talerproductlink.class.php');
dol_include_once('/talerbarr/class/talerorderlink.class.php');

/**
 * Emit a JSON error/success response and exit.
 *
 * @param int    $status  HTTP status code
 * @param string $message Human readable message
 * @param array  $extra   Optional extra payload
 *
 * @return void
 */
function talerbarrWebhookRespond(int $status, string $message, array $extra = []): void
{
	$statusText = ($status >= 400) ? 'error' : 'ok';
	if (!headers_sent()) {
		http_response_code($status);
		if ($status !== 204) {
			header('Content-Type: application/json');
		}
	}
	if ($status === 204) {
		exit;
	}
	$payload = array_merge(['status' => $statusText, 'message' => $message], $extra);
	echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

global $db;

// Load Taler configuration
$cfgErr = null;
$cfg = TalerConfig::fetchSingletonVerified($db, $cfgErr);
if (!$cfg || !$cfg->verification_ok) {
	talerbarrWebhookRespond(500, $cfgErr ?: 'No valid Taler configuration');
}


$rawBody = file_get_contents('php://input');
dol_syslog('talerbarr webhook body: '.dol_trunc((string) $rawBody, 2048), LOG_DEBUG);
$payload = json_decode((string) $rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
	$jsonErr = json_last_error_msg();
	$bodyPreview = dol_trunc((string) $rawBody, 1024, '...');
	dol_syslog('talerbarr webhook failed to decode JSON: '.$jsonErr.' body='.$bodyPreview, LOG_WARNING);
	$payload = null;
}

// Capture headers for diagnostics (DEBUG level to avoid noisy logs in production)
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
if (!empty($allHeaders)) {
	dol_syslog('talerbarr webhook headers: '.json_encode(array_keys($allHeaders)), LOG_DEBUG);
}

// Validate authentication header
$rawAuth = isset($_SERVER['HTTP_X_AUTH_HEADER']) ? trim((string) $_SERVER['HTTP_X_AUTH_HEADER']) : '';
if ($rawAuth === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
	$authLine = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
	if (stripos($authLine, 'Bearer ') === 0) {
		$rawAuth = trim(substr($authLine, 7));
	} elseif (stripos($authLine, 'Basic ') === 0) {
		$decoded = base64_decode(substr($authLine, 6));
		if ($decoded !== false && $decoded !== '') {
			$rawAuth = $decoded;
		}
	}
}
if ($rawAuth === '' && is_array($payload)) {
	if (!empty($payload['auth_signature']) && is_scalar($payload['auth_signature'])) {
		$rawAuth = (string) $payload['auth_signature'];
	} elseif (!empty($payload['auth_token']) && is_scalar($payload['auth_token'])) {
		$rawAuth = (string) $payload['auth_token'];
	}
}
if ($rawAuth === '') {
	dol_syslog('talerbarr webhook missing authentication credentials', LOG_DEBUG);
	talerbarrWebhookRespond(400, 'Missing authentication credentials');
}

$token = (string) $cfg->talertoken;
if ($token === '') {
	dol_syslog('talerbarr webhook missing configured token', LOG_ERR);
	talerbarrWebhookRespond(500, 'Webhook token not configured');
}

$staticHash = hash('sha256', $token);
$rawAuthFingerprint = $rawAuth !== '' ? hash('sha256', $rawAuth) : 'EMPTY';
dol_syslog('talerbarr webhook auth credential fingerprint='.$rawAuthFingerprint.' staticHash='.$staticHash, LOG_DEBUG);

if (preg_match('~^(\d{10,})[:|]([0-9a-f]{64})$~', $rawAuth, $m)) {
	$ts  = (int) $m[1];
	$mac = $m[2];

	// Check replay window (±10 minutes)
	if (abs(time() - $ts) > 600) {
		talerbarrWebhookRespond(401, 'Timestamp outside allowed window');
	}

	// Check expected HMAC: sha256(token + ts)
	$expect = hash('sha256', $token.$ts);
	if (!hash_equals($expect, $mac)) {
		talerbarrWebhookRespond(403, 'HMAC signature mismatch');
	}
} else {
	if (!hash_equals($staticHash, $rawAuth) && !hash_equals($token, $rawAuth)) {
		talerbarrWebhookRespond(403, 'Signature mismatch');
	}
}

$user = new User($db);
$authorId = (int) ($cfg->fk_user_creat ?? 0);
if ($authorId > 0 && $user->fetch($authorId) > 0) {
	// ok – $user is the author of the config
	$user->getrights();
} else {
	// fallback: anonymous/system
	$user->id    = 0;
	$user->login = 'taler-webhook';
}

// Parse and validate incoming JSON
if (!is_array($payload)) {
	dol_syslog('talerbarr webhook invalid request body', LOG_WARNING);
	talerbarrWebhookRespond(400, 'Request body must be valid JSON');
}

// Get webhook type and instance
$type = '';
if (isset($payload['webhook_type'])) {
	$type = is_string($payload['webhook_type']) ? trim($payload['webhook_type']) : '';
} elseif (isset($payload['event_type'])) {
	$type = is_string($payload['event_type']) ? trim($payload['event_type']) : '';
}
$instance = (string) $cfg->username;

if ($type === '') {
	dol_syslog('talerbarr webhook missing webhook_type/event_type in payload', LOG_WARNING);
	talerbarrWebhookRespond(400, 'Missing webhook type information in payload');
}

$syncDirection = (string) ($cfg->syncdirection ?? '0');
$syncFromTaler = ($syncDirection === '1');

if (!$syncFromTaler) {
	switch ($type) {
		case 'order_created':
		case 'refund':
		case 'category_added':
		case 'category_updated':
		case 'category_deleted':
		case 'inventory_added':
		case 'inventory_updated':
		case 'inventory_deleted':
			dol_syslog('talerbarr webhook received event '.$type.' but sync from Taler is disabled', LOG_DEBUG);
			talerbarrWebhookRespond(204, 'Sync from Taler is disabled');
			break;
	}
}

switch ($type) {
	case 'order_created':
		$orderId = (string) ($payload['order_id'] ?? '');
		dol_syslog('talerbarr webhook processing order_created event for order '.$orderId, LOG_DEBUG);

		if ($orderId === '') {
			talerbarrWebhookRespond(400, 'Missing order identifier');
		}

			$contractPayload = $payload['contract'] ?? ($payload['contract_terms'] ?? null);
		if ($contractPayload === null) {
			dol_syslog('talerbarr webhook missing contract payload for order '.$orderId, LOG_ERR);
			talerbarrWebhookRespond(400, 'Missing contract payload', ['order_id' => $orderId]);
		}

			$contractData = [];
		if (is_string($contractPayload) && $contractPayload !== '') {
			$decoded = json_decode($contractPayload, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$decoded = json_decode(html_entity_decode($contractPayload, ENT_QUOTES | ENT_HTML5), true);
			}
			if (json_last_error() !== JSON_ERROR_NONE) {
				$decoded = json_decode(stripslashes($contractPayload), true);
			}
			if (json_last_error() !== JSON_ERROR_NONE) {
				$decoded = json_decode(html_entity_decode(stripslashes($contractPayload), ENT_QUOTES | ENT_HTML5), true);
			}
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				$contractData = $decoded;
			} else {
				dol_syslog('talerbarr webhook received invalid contract JSON for order '.$orderId.' ('.json_last_error_msg().') payload='.dol_trunc($contractPayload, 512, '...'), LOG_ERR);
				talerbarrWebhookRespond(400, 'Invalid contract JSON payload', ['order_id' => $orderId]);
			}
		} elseif (is_array($contractPayload)) {
			$contractData = $contractPayload;
		} elseif (is_object($contractPayload)) {
			$contractData = json_decode(json_encode($contractPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: [];
		}

		if (!isset($payload['merchant_instance']) && isset($payload['instance_id'])) {
			$payload['merchant_instance'] = (string) $payload['instance_id'];
		}

		$statusPayload = $payload;
		if (!empty($contractData)) {
			$statusPayload['contract'] = $contractData;
			$statusPayload['contract_terms'] = $contractData;
		}

		$result = TalerOrderLink::upsertFromTalerOnOrderCreation($db, $statusPayload, $user, $contractData);
		if ($result < 0) {
			talerbarrWebhookRespond(500, 'Failed to ingest order creation event', ['order_id' => $orderId]);
		}
		if ($result === 0) {
			talerbarrWebhookRespond(202, 'Order creation event ignored', ['order_id' => $orderId]);
		}
		talerbarrWebhookRespond(202, 'Order creation event processed', ['order_id' => $orderId]);
		break;

	case 'pay':
		$orderId = (string) ($payload['order_id'] ?? '');
		dol_syslog('talerbarr webhook processing pay event for order '.$orderId, LOG_DEBUG);
		$result = TalerOrderLink::upsertFromTalerOfPayment($db, $payload, $user);
		if ($result < 0) {
			talerbarrWebhookRespond(500, 'Failed to ingest payment event', ['order_id' => $orderId]);
		}
		if ($result === 0) {
			talerbarrWebhookRespond(202, 'Payment event ignored', ['order_id' => $orderId]);
		}
		talerbarrWebhookRespond(202, 'Payment event processed', ['order_id' => $orderId]);
		break;

	case 'order_settled':
		$orderId = (string) ($payload['order_id'] ?? '');
		dol_syslog('talerbarr webhook processing order_settled event for order '.$orderId, LOG_DEBUG);
		$result = TalerOrderLink::upsertFromTalerOfWireTransfer($db, $payload, $user);
		if ($result < 0) {
			talerbarrWebhookRespond(500, 'Failed to ingest wire transfer event', ['order_id' => $orderId]);
		}
		if ($result === 0) {
			talerbarrWebhookRespond(202, 'Wire transfer event ignored', ['order_id' => $orderId]);
		}
		talerbarrWebhookRespond(202, 'Wire transfer event processed', ['order_id' => $orderId]);
		break;

	case 'refund':
	case 'category_added':
	case 'category_updated':
	case 'category_deleted':
		dol_syslog('talerbarr webhook received event '.$type.' (no-op)', LOG_DEBUG);
		break;

	case 'inventory_added':
	case 'inventory_updated':
		TalerProductLink::upsertFromTaler(
			$db,
			$payload,
			$user,
			['instance' => $instance, 'write_dolibarr' => true]
		);
		break;

	case 'inventory_deleted':
		$pid = (string) ($payload['product_id'] ?? '');
		if ($pid !== '') {
			$link = new TalerProductLink($db);
			if ($link->fetchByInstancePid($instance, $pid) > 0) {
				$link->sync_enabled = 0;
				$link->update($user, 1);
			}
		}
		break;

	default:
		dol_syslog('talerbarr webhook unsupported type '.$type, LOG_WARNING);
		talerbarrWebhookRespond(404, 'Unsupported webhook type: '.$type);
}

talerbarrWebhookRespond(202, 'Event processed');
