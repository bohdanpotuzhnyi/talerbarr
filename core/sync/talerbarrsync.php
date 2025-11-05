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
 * \file        core/sync/talerbarrsync.php
 * \ingroup     talerbarr
 * \brief       Main file that handles the logic of sync between 2 systems
 *
 * @package    Application
 */

/**
 * Background synchroniser for the Taler-Barr module.
 *
 *  Runs from CLI or by an async `exec()` call.
 *  Refuses to start when another run is in progress (flock lock).
 *  Writes human-readable progress into   DOL_DATA_ROOT.'/talerbarr/sync.status.json'.
 *  Normally you are not supposed to call it directly, only through the lib TalerSyncUtil class.
 *
 * Usage examples
 *   php talerbarrsync.php              – normal run, auto-detect direction
 *   php talerbarrsync.php --force      – ignore stale lock file
 */

define('NOSESSION', 1);
// Load Dolibarr environment
$res = 0;
// Try master.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/master.inc.php";
}
// Try master.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/master.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/master.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/master.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/master.inc.php";
}
// Try master.inc.php using relative path
if (!$res && file_exists("../../master.inc.php")) {
	$res = @include "../../master.inc.php";
}
if (!$res && file_exists("../../../master.inc.php")) {
	$res = @include "../../../master.inc.php";
}

if (!$res && file_exists("../../../../master.inc.php")) {
	//For the location of custom/talerbarr/core/sync/talerbarrsync.php
	$res = @include "../../../../master.inc.php";
}

if (!$res) {
	die("Include of master fails");
}

if (php_sapi_name() !== 'cli') {
	header('HTTP/1.1 403 Forbidden');
	exit("This script must be run from CLI.\n");
}

dol_include_once('/talerbarr/class/talerconfig.class.php');
dol_include_once('/talerbarr/class/talerproductlink.class.php');
dol_include_once('/talerbarr/class/talerorderlink.class.php');
dol_include_once('/talerbarr/class/talermerchantclient.class.php');
dol_include_once('/commande/class/commande.class.php');

$lockFile   = DOL_DATA_ROOT.'/talerbarr/sync.lock';
$statusFile = DOL_DATA_ROOT.'/talerbarr/sync.status.json';
@dol_mkdir(dirname($lockFile));

$force   = in_array('--force', $argv, true);

// Handle stale lock check
$maxAgeSeconds = 180; // 3 minutes
if (file_exists($lockFile) && file_exists($statusFile)) {
	$age = time() - @filemtime($statusFile);
	if ($age > $maxAgeSeconds) {
		dol_syslog("TalerBarrSync: stale lock detected (age={$age}s), recovering", LOG_WARNING);
		@unlink($lockFile);
		file_put_contents($statusFile,
			json_encode([
			'phase' => 'stale-recovery',
			'ts'    => time(),
			'note'  => "Lock file was older than {$maxAgeSeconds} sec. Recovered.",
		],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
}

$lockHdl = fopen($lockFile, 'c+');
if (!$lockHdl) die("Cannot open $lockFile\n");

if (!flock($lockHdl, LOCK_EX | ($force ? 0 : LOCK_NB))) {
	dol_syslog("TalerBarrSync: already running, aborting", LOG_WARNING);
	exit(0);
}
register_shutdown_function(function () use ($lockHdl, $lockFile) {
	flock($lockHdl, LOCK_UN);
	fclose($lockHdl);
	@unlink($lockFile);
});

/**
 * Persist a human-readable sync status JSON file.
 *
 * @param array $s  Arbitrary status payload to serialize; function adds an RFC date in "ts".
 *
 * @return void
 */
function writeStatus(array $s)
{
	global $statusFile;
	$s['ts'] = dol_print_date(dol_now(), 'dayhourrfc');
	dol_syslog("TalerBarrSync: saving the status into ".$statusFile, LOG_DEBUG);
	file_put_contents($statusFile, json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}


/* -------------------------------------------------------------
 * 0) Pre-flight: load + verify the config row
 * ---------------------------------------------------------- */
dol_syslog("TalerBarrSync: started".($force ? " with --force" : ""), LOG_NOTICE);

$cfgErr = null;
$cfg    = TalerConfig::fetchSingletonVerified($GLOBALS['db'], $cfgErr);
if (!$cfg || empty($cfg->verification_ok)) {
	writeStatus(['phase'=>'abort', 'error'=>$cfgErr ?: 'No valid configuration']);
	dol_syslog("TalerBarrSync: config problem – ".$cfgErr, LOG_ERR);
	exit(1);
}

$direction = ((string) $cfg->syncdirection === '1') ? 'pull' : 'push';
writeStatus(['phase'=>'start', 'direction'=>$direction]);
dol_syslog("TalerBarrSync: sync direction is ".$direction, LOG_INFO);

$user = new User($GLOBALS['db']);
$user->fetch($cfg->fk_user_modif ?: $cfg->fk_user_creat ?: 0);
if (empty($user->id)) {
	dol_syslog("TalerBarrSync: user not found", LOG_ERR);
	$user->fetch(1);
}

/* -------------------------------------------------------------
 * 1) PUSH  (Dolibarr → Taler)
 * ---------------------------------------------------------- */
if ($direction === 'push') {
	$db = $GLOBALS['db'];

	// ---------------------------------------------------------
	// Products
	// ---------------------------------------------------------
	$productSql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product
            WHERE entity IN (".getEntity('product').")";
	$productRes = $db->query($productSql);
	$productTotal = $productRes ? $db->num_rows($productRes) : 0;
	$productProcessed = 0;
	$productSynced    = 0;

	while ($productRes && ($obj = $db->fetch_object($productRes))) {
		$productProcessed++;

		$prod = new Product($db);
		if ($prod->fetch((int) $obj->rowid) <= 0) {
			if ($productProcessed % 25 === 0) {
				writeStatus(['phase'=>'push-products','direction'=>'push','processed'=>$productProcessed,'total'=>$productTotal]);
			}
			continue;
		}

		$resUpsert = TalerProductLink::upsertFromDolibarr($db, $prod, $user, $cfg);
		if ($resUpsert >= 0) {
			$productSynced++;
		}

		if ($productProcessed % 25 === 0) {
			writeStatus(['phase'=>'push-products','direction'=>'push','processed'=>$productProcessed,'total'=>$productTotal]);
		}
	}
	if ($productRes) {
		$db->free($productRes);
	}

	// ---------------------------------------------------------
	// Orders (only unpaid ones)
	// ---------------------------------------------------------
	$orderTotal      = 0;
	$orderProcessed  = 0;
	$orderSynced     = 0;
	$orderSyncedPaid = 0;

	$orderSql = "SELECT c.rowid, c.total_ttc, c.facture, c.paye, c.fk_statut
            FROM ".MAIN_DB_PREFIX."commande AS c
            WHERE c.entity IN (".getEntity('commande').")
              AND c.facture = 0
              AND c.fk_statut IN (".Commande::STATUS_VALIDATED.",".Commande::STATUS_SHIPMENTONPROCESS.",".Commande::STATUS_CLOSED.")";
	$orderRes = $db->query($orderSql);
	$orderTotal = $orderRes ? $db->num_rows($orderRes) : 0;

	if ($orderTotal > 0) {
		writeStatus(['phase'=>'push-orders','direction'=>'push','processed'=>0,'total'=>$orderTotal]);
	}

	while ($orderRes && ($obj = $db->fetch_object($orderRes))) {
		$orderProcessed++;

		$cmd = new Commande($db);
		if ($cmd->fetch((int) $obj->rowid) <= 0) {
			if ($orderProcessed % 10 === 0) {
				writeStatus(['phase'=>'push-orders','direction'=>'push','processed'=>$orderProcessed,'total'=>$orderTotal]);
			}
			continue;
		}

		$totalTtc = (float) ($cmd->total_ttc ?? 0.0);
		if ($totalTtc <= 0.0) {
			if ($orderProcessed % 10 === 0) {
				writeStatus(['phase'=>'push-orders','direction'=>'push','processed'=>$orderProcessed,'total'=>$orderTotal]);
			}
			continue;
		}

		$link = new TalerOrderLink($db);
		$linkRes = $link->fetchByInvoiceOrOrder(0, (int) $cmd->id);
		if ($linkRes > 0 && (int) ($link->taler_state ?? 0) >= 30) {
			$orderSyncedPaid++;
			if ($orderProcessed % 10 === 0) {
				writeStatus(['phase'=>'push-orders','direction'=>'push','processed'=>$orderProcessed,'total'=>$orderTotal]);
			}
			continue;
		}

		$resOrder = TalerOrderLink::upsertFromDolibarr($db, $cmd, $user);
		if ($resOrder >= 0) {
			$orderSynced++;
		}

		if ($orderProcessed % 10 === 0) {
			writeStatus(['phase'=>'push-orders','direction'=>'push','processed'=>$orderProcessed,'total'=>$orderTotal]);
		}
	}
	if ($orderRes) {
		$db->free($orderRes);
	}

	$finalProcessed = $productProcessed + $orderProcessed;
	$finalTotal     = $productTotal + $orderTotal;

	writeStatus([
		'phase'      => 'done',
		'direction'  => 'push',
		'processed'  => $finalProcessed,
		'total'      => $finalTotal,
		'products'   => [
			'processed' => $productProcessed,
			'synced'    => $productSynced,
			'total'     => $productTotal,
		],
		'orders'     => [
			'processed' => $orderProcessed,
			'synced'    => $orderSynced,
			'skipped_paid' => $orderSyncedPaid,
			'total'     => $orderTotal,
		],
	]);

	dol_syslog(
		sprintf(
			"TalerBarrSync: finished %s with %d/%d products and %d/%d orders",
			$direction,
			$productSynced,
			$productTotal,
			$orderSynced,
			$orderTotal
		),
		LOG_NOTICE
	);
	exit(0);
}

/* -------------------------------------------------------------
 * 2) PULL  (Taler → Dolibarr)
 * ---------------------------------------------------------- */
try {
	$client = $cfg->talerMerchantClient();
} catch (Throwable $e) {
	writeStatus([
		'phase'=>'abort',
		'direction'=>'pull',
		'error'=>$e->getMessage()
	]);
	fwrite(
		STDERR,
		"API error: ".$e->getMessage()."\n"
	);
	exit(2);
}

$db     = $GLOBALS['db'];
$limit  = 1_000;
$offset = 0;
$done   = 0;
$total  = 0;

do {
	try {
		$page   = $client->listProducts($limit, $offset);
		$items  = $page['products'] ?? [];
	} catch (Throwable $e) {
		writeStatus(['phase'=>'abort','direction'=>'pull','error'=>$e->getMessage()]);
		fwrite(STDERR, "API error: ".$e->getMessage()."\n");
		exit(2);
	}

	$count = count($items);
	if ($count === 0) break;

	foreach ($items as $summary) {
		try {
			// fetch the full ProductDetail *now*
			$detail = $client->getProduct($summary['product_id']);
			$detail["product_id"] = $summary['product_id']; // of course it is missing from getProduct
			TalerProductLink::upsertFromTaler(
				$GLOBALS['db'],
				$detail,
				$user,
				['instance' => $cfg->username, 'write_dolibarr' => true]
			);
		} catch (Throwable $e) {
			writeStatus([
				'phase'     => 'abort',
				'direction' => 'pull',
				'error'     => $e->getMessage() . "\nProduct info:\n" . print_r($summary, true)
			]);
			fwrite(STDERR, "API error: ".$e->getMessage()."\n");
			exit(2);
		}

		$done++;
		if ($done % 25 === 0) {
			writeStatus(['phase'=>'pull-products','direction'=>'pull','processed'=>$done,'total'=>null]);
		}
	}

	$total += $count;
	$last   = end($items);
	$offset = ((int) $last['product_serial']) + 1;
} while ($count === $limit);

$productProcessed = $done;
$productTotal     = $total;

if ($productProcessed > 0) {
	writeStatus([
		'phase'      => 'pull-products',
		'direction'  => 'pull',
		'processed'  => $productProcessed,
		'total'      => $productTotal > 0 ? $productTotal : null,
	]);
}

// -------------------------------------------------------------
// Orders (Taler → Dolibarr)
// -------------------------------------------------------------
$orderLimit       = 200;
$orderOffset      = 0;
$orderProcessed   = 0;
$orderSynced      = 0;
$orderTotalKnown  = null;
$orderCount       = 0;

do {
	try {
		$orderParams = ['limit' => $orderLimit];
		if ($orderOffset > 0) {
			$orderParams['offset'] = $orderOffset;
		}
		$orderPage  = $client->listOrders($orderParams);
		$orderItems = $orderPage['orders'] ?? [];
	} catch (Throwable $e) {
		writeStatus(['phase'=>'abort','direction'=>'pull','error'=>$e->getMessage()]);
		fwrite(STDERR, "API error: ".$e->getMessage()."\n");
		exit(2);
	}

	$orderCount = count($orderItems);
	if ($orderCount === 0) {
		if ($orderOffset === 0) {
			writeStatus(['phase'=>'pull-orders','direction'=>'pull','processed'=>0,'total'=>0]);
		}
		break;
	}

	if ($orderTotalKnown === null) {
		if (isset($orderPage['total'])) {
			$orderTotalKnown = (int) $orderPage['total'];
		} elseif (isset($orderPage['count'])) {
			$orderTotalKnown = (int) $orderPage['count'];
		}
	}

	foreach ($orderItems as $orderSummary) {
		$orderProcessed++;

		$orderId = (string) ($orderSummary['order_id'] ?? $orderSummary['orderId'] ?? '');
		if ($orderId === '') {
			if ($orderProcessed % 10 === 0) {
				writeStatus(['phase'=>'pull-orders','direction'=>'pull','processed'=>$orderProcessed,'total'=>$orderTotalKnown]);
			}
			continue;
		}

		try {
			$statusPayload = $client->getOrderStatus($orderId);
			if (!isset($statusPayload['order_id']) || $statusPayload['order_id'] === '' || $statusPayload['order_id'] === null) {
				$statusPayload['order_id'] = $orderId;
			}
			if (!isset($statusPayload['orderId']) || $statusPayload['orderId'] === '' || $statusPayload['orderId'] === null) {
				$statusPayload['orderId'] = $orderId;
			}
			if (!isset($statusPayload['amount']) && isset($orderSummary['amount'])) {
				$statusPayload['amount'] = $orderSummary['amount'];
			}
			if (!isset($statusPayload['total_amount']) && isset($orderSummary['amount'])) {
				$statusPayload['total_amount'] = $orderSummary['amount'];
			}
			if (!isset($statusPayload['amount_str']) && isset($orderSummary['amount']) && is_string($orderSummary['amount'])) {
				$statusPayload['amount_str'] = $orderSummary['amount'];
			}
		} catch (Throwable $e) {
			writeStatus([
				'phase'     => 'abort',
				'direction' => 'pull',
				'error'     => $e->getMessage()."\nOrder id: ".$orderId,
			]);
			fwrite(STDERR, "API error: ".$e->getMessage()."\n");
			exit(2);
		}

		$contractTerms = $statusPayload['contract_terms'] ?? ($orderSummary['contract_terms'] ?? []);
		if (is_array($contractTerms)) {
			if (empty($contractTerms['order_id']) && isset($statusPayload['order_id'])) {
				$contractTerms['order_id'] = $statusPayload['order_id'];
			} elseif (empty($contractTerms['orderId']) && isset($statusPayload['order_id'])) {
				$contractTerms['orderId'] = $statusPayload['order_id'];
			}
		}
		$resCreate = TalerOrderLink::upsertFromTalerOnOrderCreation(
			$db,
			$statusPayload,
			$user,
			$contractTerms
		);
		if ($resCreate >= 0) {
			$orderSynced++;
		}

		$statusKey = strtolower((string) ($statusPayload['order_status'] ?? $statusPayload['status'] ?? ''));
		if ($statusKey === 'wired' || (!empty($statusPayload['wired']) && $statusKey !== 'refunded')) {
			TalerOrderLink::upsertFromTalerOfWireTransfer($db, $statusPayload, $user);
		} elseif (in_array($statusKey, ['paid', 'delivered', 'refunded'], true)) {
			TalerOrderLink::upsertFromTalerOfPayment($db, $statusPayload, $user);
		}

		if ($orderProcessed % 10 === 0) {
			writeStatus(['phase'=>'pull-orders','direction'=>'pull','processed'=>$orderProcessed,'total'=>$orderTotalKnown]);
		}
	}

	$orderOffset += $orderCount;
} while ($orderCount === $orderLimit);

$orderTotalForStatus = $orderTotalKnown;
if ($orderTotalForStatus === null) {
	$orderTotalForStatus = $orderProcessed;
}

$finalProcessed = $productProcessed + $orderProcessed;
$finalTotal     = $productTotal + $orderTotalForStatus;

writeStatus([
	'phase'      => 'done',
	'direction'  => 'pull',
	'processed'  => $finalProcessed,
	'total'      => $finalTotal,
	'products'   => [
		'processed' => $productProcessed,
		'total'     => $productTotal,
	],
	'orders'     => [
		'processed' => $orderProcessed,
		'synced'    => $orderSynced,
		'total'     => $orderTotalKnown,
	],
]);
dol_syslog(
	sprintf(
		"TalerBarrSync: finished %s with %d products and %d orders",
		$direction,
		$productProcessed,
		$orderProcessed
	),
	LOG_NOTICE
);
exit(0);
