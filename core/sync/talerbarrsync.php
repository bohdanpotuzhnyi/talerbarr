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
require __DIR__.'/../../../../../htdocs/master.inc.php';

require_once __DIR__.'/../../class/talerconfig.class.php';
require_once __DIR__.'/../../class/talerproductlink.class.php';
require_once __DIR__.'/../../class/talermerchantclient.class.php';

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
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product
            WHERE entity IN (".getEntity('product').")";
	$res = $GLOBALS['db']->query($sql);
	$total = $GLOBALS['db']->num_rows($res);
	$done  = 0;

	while ($obj = $GLOBALS['db']->fetch_object($res)) {
		$prod = new Product($GLOBALS['db']);
		if ($prod->fetch((int) $obj->rowid) <= 0) continue;

		// 1. mirror / create link row
		$r = TalerProductLink::upsertFromDolibarr($GLOBALS['db'], $prod, $user, $cfg);
		if ($r<0) { $done++; continue; }

		$done++;
		if ($done % 25 === 0) writeStatus(['phase'=>'push','processed'=>$done,'total'=>$total]);
	}
	writeStatus(['phase'=>'done','direction'=>'push','processed'=>$done,'total'=>$total]);
	dol_syslog("TalerBarrSync: finished {$direction} with $done items", LOG_NOTICE);
	exit(0);
}

/* -------------------------------------------------------------
 * 2) PULL  (Taler → Dolibarr)
 * ---------------------------------------------------------- */
try {
	$client = new TalerMerchantClient(
		$cfg->talermerchanturl,
		$cfg->talertoken,
		$cfg->username
	);
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
			writeStatus(['phase'=>'pull','processed'=>$done,'total'=>null]);
		}
	}

	$total += $count;
	$last   = end($items);
	$offset = ((int) $last['product_serial']) + 1;
} while ($count === $limit);

writeStatus(['phase'=>'done','direction'=>'pull','processed'=>$done,'total'=>$total]);
dol_syslog("TalerBarrSync: finished {$direction} with $done items", LOG_NOTICE);
exit(0);
