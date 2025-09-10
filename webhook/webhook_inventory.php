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
 * \file        talerbarr/webhook/webhook_inventory.php
 * \ingroup     talerbarr
 * \brief       Lightweight webhook listener for the Taler synchronisation.
 */

// Minimal Dolibarr init – no login, session, CSRF, etc.
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

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

global $db;

// Load Taler configuration
$cfgErr = null;
$cfg = TalerConfig::fetchSingletonVerified($db, $cfgErr);
if (!$cfg || !$cfg->verification_ok) {
	http_response_code(500);
	echo $cfgErr ?: 'No valid Taler configuration';
	exit;
}

// Validate authentication header
$rawAuth = $_SERVER['HTTP_X_AUTH_HEADER'] ?? '';
if (!preg_match('~^(\d{10,})[:|]([0-9a-f]{64})$~', $rawAuth, $m)) {
	http_response_code(400); // Malformed or missing header
	exit;
}
$ts  = (int) $m[1];
$mac = $m[2];

// Check replay window (±10 minutes)
if (abs(time() - $ts) > 600) {
	http_response_code(401);
	exit;
}

// Check expected HMAC: sha256(token + ts)
$expect = hash('sha256', $cfg->talertoken.$ts);
if (!hash_equals($expect, $mac)) {
	http_response_code(403);
	exit;
}

$user = new User($db);
$authorId = (int) ($cfg->fk_user_creat ?? 0);
if ($authorId > 0 && $user->fetch($authorId) > 0) {
	// ok – $user is the author of the config
} else {
	// fallback: anonymous/system
	$user->id    = 0;
	$user->login = 'taler-webhook';
}

// Parse and validate incoming JSON
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
	http_response_code(415); // Unsupported payload
	exit;
}

// Get webhook type and instance
$type = $payload['webhook_type'] ?? '';
$instance = (string) $cfg->username;

// Handle webhook types
switch ($type) {
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
		// Ignore unsupported webhook types
		break;
}

// Always return 204 to acknowledge receipt
http_response_code(204);
exit;
