<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2015       Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       talerbarr/talerbarrindex.php
 *	\ingroup    talerbarr
 *	\brief      Home page of talerbarr top menu
 *
 * @package    Application
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
dol_include_once('/societe/class/societe.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/compta/facture/class/facture.class.php');
dol_include_once('/compta/paiement/class/paiement.class.php');
dol_include_once('/product/class/product.class.php');
dol_include_once('/compta/bank/class/account.class.php');
dol_include_once('/talerbarr/class/talerorderlink.class.php');
dol_include_once('/talerbarr/class/talerproductlink.class.php');

// Load translation files required by the page
$langs->loadLangs(array("talerbarr@talerbarr"));

$action = GETPOST('action', 'aZ09');

$now = dol_now();
$max = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT', 5);

// Security check - Protection if external user
$socid = GETPOSTINT('socid');
if (!empty($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

// Initialize a technical object to manage hooks. Note that conf->hooks_modules contains array
//$hookmanager->initHooks(array($object->element.'index'));

// Security check (enable the most restrictive one)
//if ($user->socid > 0) accessforbidden();
//if ($user->socid > 0) $socid = $user->socid;
//if (!isModEnabled('talerbarr')) {
//	accessforbidden('Module not enabled');
//}
//if (! $user->hasRight('talerbarr', 'myobject', 'read')) {
//	accessforbidden();
//}
//restrictedArea($user, 'talerbarr', 0, 'talerbarr_myobject', 'myobject', '', 'rowid');
//if (empty($user->admin)) {
//	accessforbidden('Must be admin');
//}


/*
 * Actions
 */

include_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
dol_include_once('/core/lib/security2.lib.php');

if ($action === 'runsync' && $user->admin && GETPOST('token') === $_SESSION['newtoken']) {
	dol_include_once('/talerbarr/lib/talersync.lib.php');
	TalerSyncUtil::launchBackgroundSync($path_to_core = __DIR__.'/core');
	setEventMessages($langs->trans('SyncStartedInBackground'), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */
dol_include_once('/talerbarr/class/talerconfig.class.php');

$errmsg = null;
try {
	$singleton = TalerConfig::fetchSingletonVerified($db, $errmsg);
} catch (Throwable $t) {
	// Log + show a friendly error, then send user to creation page
	dol_syslog(__FILE__.': fetchSingletonVerified exception: '.$t->getMessage(), LOG_ERR);
	setEventMessages($langs->trans('ErrorInternal'), null, 'errors');
	$singleton = null;
}

if (!$singleton) {
	if ($errmsg) setEventMessages($errmsg, null, 'errors');
	// No config in DB -> go to create
	if (!headers_sent()) {
		header('Location: '.dol_buildpath('/talerbarr/talerconfig_card.php', 1).'?action=create', true, 302);
		exit;
	}
} else {
	// If invalid, prefill and redirect to create
	if (property_exists($singleton, 'verification_ok') && !$singleton->verification_ok) {
		if (!empty($singleton->verification_error)) {
			setEventMessages($singleton->verification_error, null, 'errors');
		} elseif ($errmsg) {
			setEventMessages($errmsg, null, 'errors');
		}

		// Prefill values for the create screen
		$_SESSION['talerconfig_prefill'] = array(
			'talermerchanturl' => !empty($singleton->talermerchanturl) ? $singleton->talermerchanturl : '',
			'username'         => !empty($singleton->username) ? $singleton->username : '',
			'talertoken'       => !empty($singleton->talertoken) ? $singleton->talertoken
				: (!empty($singleton->taler_token) ? $singleton->taler_token : ''),
		);

		if (!headers_sent()) {
			header('Location: '.dol_buildpath('/talerbarr/talerconfig_card.php', 1).'?action=create', true, 302);
			exit;
		}
	}
}

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("TalerBarrArea"), '', '', 0, 0, '', '', '', 'mod-talerbarr page-index');

print '<style>
.taler-home-wrap{margin-top:8px;}
.taler-home-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:14px;margin-bottom:14px;}
.taler-home-card{background:#fff;border:1px solid #dfe3eb;border-radius:10px;box-shadow:0 1px 2px rgba(16,24,40,0.04);padding:14px;}
.taler-card-heading{display:flex;align-items:center;justify-content:space-between;font-weight:600;margin:0 0 8px;font-size:15px;}
.taler-card-heading .fa{margin-right:6px;color:#3b4c70;}
.taler-card-heading .right-actions a{margin-left:6px;}
.taler-chip{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.taler-chip-open{background:#eef2f7;color:#2f3440;border:1px dashed #9ba4b5;}
.taler-chip-ok{background:#2f8f46;color:#fff;}
.taler-chip-warn{background:#c57b00;color:#fff;}
.taler-chip-missing{background:#c1121f;color:#fff;}
.taler-mini-card{border:1px solid #e6eaf2;border-radius:8px;padding:10px;margin-bottom:8px;background:#f9fbfd;}
.taler-mini-top{display:flex;justify-content:space-between;align-items:center;gap:8px;}
.taler-mini-title{font-weight:700;font-size:13px;display:flex;align-items:center;gap:6px;}
.taler-mini-fields{margin-top:6px;font-size:12px;}
.taler-field-row{display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px dashed #e2e7f0;}
.taler-field-row:last-child{border-bottom:none;}
.taler-field-label{color:#4a505c;}
.taler-field-value{text-align:right;font-weight:600;}
.taler-section-empty{padding:10px;border:1px dashed #e2e7f0;border-radius:8px;background:#f8fafc;color:#6b7280;font-size:13px;}
.taler-section-actions{margin-top:4px;}
.taler-section-actions a{margin-right:6px;}
.taler-meta{font-size:12px;color:#6b7280;margin-top:4px;}
.taler-highlight{font-weight:700;color:#1f2a44;}
.taler-plain-list{margin:0;padding:0;list-style:none;}
.taler-plain-list li{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #e2e7f0;}
.taler-plain-list li:last-child{border-bottom:none;}
.taler-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:8px;}
.taler-stat{border:1px dashed #e2e7f0;border-radius:8px;padding:10px;background:#f9fbfd;text-align:center;}
.taler-stat .label{color:#4a505c;font-size:12px;}
.taler-stat .value{font-size:18px;font-weight:700;color:#1f2a44;}
.taler-chart-card{grid-column:1 / -1;}
.taler-chart-legend{display:flex;flex-wrap:wrap;gap:10px;font-size:12px;margin-bottom:8px;}
.taler-chart-legend-item{display:flex;align-items:center;gap:6px;padding:4px 6px;border:1px solid #e2e7f0;border-radius:8px;background:#fff;}
.taler-chart-legend .swatch{width:12px;height:12px;border-radius:2px;margin-right:6px;display:inline-block;border:1px solid #dfe3eb;}
.taler-chart{display:flex;align-items:flex-end;gap:10px;height:240px;padding:8px 4px;border:1px dashed #e2e7f0;border-radius:10px;background:#f9fbfd;}
.taler-chart-col{flex:1 1 0;min-width:34px;text-align:center;}
.taler-chart-bar{display:flex;flex-direction:column-reverse;justify-content:flex-start;align-items:stretch;border-radius:6px 6px 0 0;overflow:hidden;background:linear-gradient(180deg,rgba(40,48,168,0.08),rgba(82,85,151,0.08));}
.taler-chart-seg{width:100%;position:relative;transition:transform 120ms ease, box-shadow 120ms ease, opacity 120ms ease;cursor:pointer;}
.taler-chart-seg:hover{transform:translateY(-2px) scaleX(1.06);box-shadow:0 0 0 2px rgba(37,99,235,0.35);opacity:0.95;}
.taler-chart-seg-empty{background:rgba(82,85,151,0.08);box-shadow:inset 0 0 0 1px #e2e7f0;cursor:default;}
.taler-chart-label{font-size:11px;color:#4a505c;margin-top:4px;word-break:break-word;}
.taler-chart-empty{padding:10px;border:1px dashed #e2e7f0;border-radius:8px;background:#f8fafc;color:#6b7280;font-size:13px;text-align:center;}
.taler-chart-tooltip{position:fixed;z-index:9999;padding:6px 8px;border-radius:6px;font-size:12px;line-height:1.3;background:rgba(15,23,42,0.95);color:#fff;box-shadow:0 8px 15px rgba(15,23,42,0.35);max-width:220px;pointer-events:none;transform:translate(-50%, calc(-100% - 8px));white-space:nowrap;}
.taler-sync-debug{margin-top:8px;padding:8px;border:1px dashed #e2e7f0;border-radius:8px;background:#f8fafc;font-size:11px;color:#374151;max-height:220px;overflow:auto;}
.taler-sync-debug pre{margin:0;white-space:pre-wrap;word-break:break-all;}
</style>';

print load_fiche_titre($langs->trans("TalerBarrArea"), '', 'cash-register');

print '<div class="taler-home-wrap">';

// --- Orders snapshot ---
$ordersSql = "SELECT rowid, taler_instance, taler_order_id, order_amount_str, taler_state, order_summary, fk_soc, fk_commande, fk_facture, fk_paiement, taler_status_url, taler_pay_deadline, tms";
$ordersSql .= " FROM ".MAIN_DB_PREFIX."talerbarr_order_link";
$ordersSql .= " WHERE entity IN (".getEntity('talerorderlink').")";
$ordersSql .= " ORDER BY tms DESC";
$ordersSql .= $db->plimit($max, 0);
$orders = array();
$resqlOrders = $db->query($ordersSql);
if ($resqlOrders) {
	while ($obj = $db->fetch_object($resqlOrders)) {
		$orders[] = $obj;
	}
	$db->free($resqlOrders);
} else {
	dol_print_error($db);
}

// --- Products snapshot ---
$productsSql = "SELECT rowid, taler_instance, taler_product_id, taler_product_name, taler_amount_str, taler_total_stock, taler_total_sold, last_sync_status, last_sync_at, sync_enabled, fk_product, tms";
$productsSql .= " FROM ".MAIN_DB_PREFIX."talerbarr_product_link";
$productsSql .= " WHERE entity IN (".getEntity('talerproductlink').")";
$productsSql .= " ORDER BY tms DESC";
$productsSql .= $db->plimit($max, 0);
$products = array();
$resqlProducts = $db->query($productsSql);
if ($resqlProducts) {
	while ($obj = $db->fetch_object($resqlProducts)) {
		$products[] = $obj;
	}
	$db->free($resqlProducts);
} else {
	dol_print_error($db);
}

// --- Orders chart dataset (for timeline) ---
$ordersChartRows = array();
$chartRanges = array('day', 'week', 'month', 'year');
$chartMinTs = null;
foreach ($chartRanges as $rangeKey) {
	$tmpBuckets = talerbarr_build_chart_buckets($rangeKey, $now);
	if (!empty($tmpBuckets)) {
		$firstStart = $tmpBuckets[0]['start'];
		if ($chartMinTs === null || $firstStart < $chartMinTs) {
			$chartMinTs = $firstStart;
		}
	}
}
if ($chartMinTs === null) {
	$chartMinTs = $now - (365 * 86400);
}
$chartMinDateSql = $db->idate($chartMinTs);

$ordersChartSql = "SELECT datec, tms, taler_claimed_at, taler_paid_at, wire_execution_time, taler_wired, order_value, order_fraction, order_amount_str, order_currency";
$ordersChartSql .= " FROM ".MAIN_DB_PREFIX."talerbarr_order_link";
$ordersChartSql .= " WHERE entity IN (".getEntity('talerorderlink').")";
$ordersChartSql .= " AND (";
$ordersChartSql .= " (datec IS NOT NULL AND datec >= '".$db->escape($chartMinDateSql)."')";
$ordersChartSql .= " OR (taler_claimed_at IS NOT NULL AND taler_claimed_at >= '".$db->escape($chartMinDateSql)."')";
$ordersChartSql .= " OR (taler_paid_at IS NOT NULL AND taler_paid_at >= '".$db->escape($chartMinDateSql)."')";
$ordersChartSql .= " OR (wire_execution_time IS NOT NULL AND wire_execution_time >= '".$db->escape($chartMinDateSql)."')";
$ordersChartSql .= " OR (tms IS NOT NULL AND tms >= '".$db->escape($chartMinDateSql)."')";
$ordersChartSql .= " )";
$ordersChartSql .= " ORDER BY datec DESC";
$resqlChart = $db->query($ordersChartSql);
if ($resqlChart) {
	while ($obj = $db->fetch_object($resqlChart)) {
		$ordersChartRows[] = $obj;
	}
	$db->free($resqlChart);
} else {
	dol_print_error($db);
}

/**
 * Build bucket definitions for a given range key.
 *
 * @param string $rangeKey Chart range key: day|week|month|year.
 * @param int    $nowTs    Current timestamp used as reference to anchor buckets.
 * @return array
 */
function talerbarr_build_chart_buckets($rangeKey, $nowTs)
{
	$buckets = array();

	if ($rangeKey === 'day') {
		$start = dol_mktime(0, 0, 0, (int) date('n', $nowTs), (int) date('j', $nowTs), (int) date('Y', $nowTs));
		for ($i = 0; $i < 24; $i++) {
			$buckets[] = array(
				'start' => $start + ($i * 3600),
				'end'   => $start + (($i + 1) * 3600),
				'label' => sprintf('%02d:00', $i),
			);
		}
	} elseif ($rangeKey === 'week') {
		$start = dol_mktime(0, 0, 0, (int) date('n', $nowTs), (int) date('j', $nowTs), (int) date('Y', $nowTs)) - (6 * 86400);
		for ($i = 0; $i < 7; $i++) {
			$bucketStart = $start + ($i * 86400);
			$buckets[] = array(
				'start' => $bucketStart,
				'end'   => $bucketStart + 86400,
				'label' => dol_print_date($bucketStart, '%a %d'),
			);
		}
	} elseif ($rangeKey === 'month') {
		$monthStart = dol_mktime(0, 0, 0, (int) date('n', $nowTs), 1, (int) date('Y', $nowTs));
		$daysInMonth = (int) date('t', $nowTs);
		for ($i = 0; $i < $daysInMonth; $i++) {
			$bucketStart = $monthStart + ($i * 86400);
			$buckets[] = array(
				'start' => $bucketStart,
				'end'   => $bucketStart + 86400,
				'label' => dol_print_date($bucketStart, '%d %b'),
			);
		}
	} elseif ($rangeKey === 'year') {
		$monthStart = dol_mktime(0, 0, 0, (int) date('n', $nowTs), 1, (int) date('Y', $nowTs));
		for ($i = 11; $i >= 0; $i--) {
			$ts = strtotime('-'.$i.' months', $monthStart);
			$year = (int) date('Y', $ts);
			$month = (int) date('n', $ts);
			$bucketStart = dol_mktime(0, 0, 0, $month, 1, $year);
			$nextMonth = $month + 1;
			$nextYear = $year;
			if ($nextMonth === 13) {
				$nextMonth = 1;
				$nextYear++;
			}
			$bucketEnd = dol_mktime(0, 0, 0, $nextMonth, 1, $nextYear);
			$buckets[] = array(
				'start' => $bucketStart,
				'end'   => $bucketEnd,
				'label' => dol_print_date($bucketStart, '%b %Y'),
			);
		}
	}

	return $buckets;
}

$ordersChartSeriesMeta = array(
	'created' => array(
		'color' => '#b9a5ff',
		'label' => 'Created orders',
		'tooltip' => 'Created orders',
	),
	'claimed' => array(
		'color' => '#647cda',
		'label' => 'Claimed orders',
		'tooltip' => 'Claimed orders',
	),
	'paid' => array(
		'color' => '#525597',
		'label' => 'Paid orders',
		'tooltip' => 'Paid orders',
	),
	'settled' => array(
		'color' => '#2830a8',
		'label' => 'Settled orders',
		'tooltip' => 'Settled orders',
	),
);

$ordersChartData = array();
foreach ($chartRanges as $rangeKey) {
	$buckets = talerbarr_build_chart_buckets($rangeKey, $now);
	$seriesData = array();
	foreach (array_keys($ordersChartSeriesMeta) as $seriesKey) {
		$seriesData[$seriesKey] = array_fill(0, count($buckets), 0);
	}

	foreach ($ordersChartRows as $row) {
		$createdTs = !empty($row->datec) ? $db->jdate($row->datec) : null;
		$claimedTs = !empty($row->taler_claimed_at) ? $db->jdate($row->taler_claimed_at) : null;
		$paidTs = !empty($row->taler_paid_at) ? $db->jdate($row->taler_paid_at) : null;
		$settledTs = null;
		if (!empty($row->wire_execution_time)) {
			$settledTs = $db->jdate($row->wire_execution_time);
		} elseif (!empty($row->taler_wired) && !empty($row->tms)) {
			$settledTs = $db->jdate($row->tms);
		}

		$events = array(
			'created' => $createdTs,
			'claimed' => $claimedTs,
			'paid'    => $paidTs,
			'settled' => $settledTs,
		);

		foreach ($events as $eventKey => $ts) {
			if (empty($ts)) {
				continue;
			}
			foreach ($buckets as $idx => $bucket) {
				if ($ts >= $bucket['start'] && $ts < $bucket['end']) {
					$seriesData[$eventKey][$idx]++;
					break;
				}
			}
		}
	}

	$ordersChartData[$rangeKey] = array(
		'labels' => array_map(function ($bucket) {
			return $bucket['label'];
		},
			$buckets),
	'series' => $seriesData,
	);
}

// Amount dataset
$ordersChartAmountData = array();
$ordersChartCurrency = '';
foreach ($chartRanges as $rangeKey) {
	$buckets = talerbarr_build_chart_buckets($rangeKey, $now);
	$seriesData = array();
	foreach (array_keys($ordersChartSeriesMeta) as $seriesKey) {
		$seriesData[$seriesKey] = array_fill(0, count($buckets), 0);
	}

	foreach ($ordersChartRows as $row) {
		$createdTs = !empty($row->datec) ? $db->jdate($row->datec) : null;
		$claimedTs = !empty($row->taler_claimed_at) ? $db->jdate($row->taler_claimed_at) : null;
		$paidTs = !empty($row->taler_paid_at) ? $db->jdate($row->taler_paid_at) : null;
		$settledTs = null;
		if (!empty($row->wire_execution_time)) {
			$settledTs = $db->jdate($row->wire_execution_time);
		} elseif (!empty($row->taler_wired) && !empty($row->tms)) {
			$settledTs = $db->jdate($row->tms);
		}

		// Resolve amount as float major units
		$amount = 0.0;
		if ($row->order_value !== null) {
			$amount = (float) $row->order_value;
			if ($row->order_fraction !== null) {
				$amount += ((float) $row->order_fraction) / 100000000;
			}
		} elseif (!empty($row->order_amount_str) && is_string($row->order_amount_str)) {
			if (preg_match('/^[A-Z]{3,}:(-?[0-9]+(?:\\.[0-9]+)?)/', $row->order_amount_str, $m)) {
				$amount = (float) $m[1];
			}
		}
		if ($amount < 0) {
			$amount = 0.0;
		}
		if ($ordersChartCurrency === '' && !empty($row->order_currency)) {
			$ordersChartCurrency = (string) $row->order_currency;
		} elseif ($ordersChartCurrency === '' && !empty($row->order_amount_str) && strpos($row->order_amount_str, ':') !== false) {
			$ordersChartCurrency = substr((string) $row->order_amount_str, 0, strpos((string) $row->order_amount_str, ':'));
		}

		$events = array(
			'created' => $createdTs,
			'claimed' => $claimedTs,
			'paid'    => $paidTs,
			'settled' => $settledTs,
		);

		foreach ($events as $eventKey => $ts) {
			if (empty($ts) || $amount <= 0) {
				continue;
			}
			foreach ($buckets as $idx => $bucket) {
				if ($ts >= $bucket['start'] && $ts < $bucket['end']) {
					$seriesData[$eventKey][$idx] += $amount;
					break;
				}
			}
		}
	}

	$ordersChartAmountData[$rangeKey] = array(
		'labels' => array_map(function ($bucket) {
			return $bucket['label'];
		},
			$buckets),
		'series' => $seriesData,
	);
}

$thirdpartyStatic = new Societe($db);
$commandeStatic = new Commande($db);
$factureStatic = new Facture($db);
$paiementStatic = new Paiement($db);
$productStatic = new Product($db);

// Row 1: timeline chart full width
print '<div class="taler-home-grid taler-home-chart" style="grid-template-columns:repeat(auto-fit,minmax(420px,1fr));">';
print '<div class="taler-home-card taler-chart-card">';
$rangeOptions = array(
	'day' => $langs->trans('Day'),
	'week' => $langs->trans('Week'),
	'month' => $langs->trans('Month'),
	'year' => $langs->trans('Year'),
);
print '<div class="taler-card-heading"><span><span class="fa fa-chart-bar"></span>Orders timeline</span><span class="right-actions">';
print '<select id="taler-orders-metric" style="margin-right:6px;"><option value="count" selected>'.dol_escape_htmltag($langs->transnoentities('NumberOfOrders', 'Number of orders')).'</option><option value="amount">'.dol_escape_htmltag($langs->transnoentities('OrderAmounts', 'Order amounts')).'</option></select>';
print '<select id="taler-orders-range">';
foreach ($rangeOptions as $key => $label) {
	$selected = ($key === 'week') ? ' selected' : '';
	print '<option value="'.dol_escape_htmltag($key).'"'.$selected.'>'.dol_escape_htmltag($label).'</option>';
}
print '</select>';
print '</span></div>';
print '<div class="taler-chart-legend">';
foreach ($ordersChartSeriesMeta as $key => $meta) {
	print '<span class="taler-chart-legend-item"><span class="swatch" style="background:'.dol_escape_htmltag($meta['color']).'"></span>'.dol_escape_htmltag($meta['label']).'</span>';
}
print '</div>';
print '<div id="taler-orders-chart" class="taler-chart"></div>';
print '<div id="taler-orders-chart-tooltip" class="taler-chart-tooltip" style="display:none;"></div>';
print '<div id="taler-orders-chart-empty" class="taler-chart-empty" style="display:none;">'.$langs->trans('NoData').'</div>';
print '</div>';
print '</div>';

// Row 2: orders + products glance
print '<div class="taler-home-grid taler-home-middle" style="grid-template-columns:repeat(auto-fit,minmax(360px,1fr));">';

// Orders card (glance numbers only)
$ordersTotal = count($orders);
$ordersPaid = 0;
$ordersOpen = 0;
$ordersFailed = 0;
$ordersWithInvoice = 0;
$ordersWithPayment = 0;
foreach ($orders as $row) {
	$state = (int) $row->taler_state;
	if (in_array($state, array(30, 40, 50, 70), true)) {
		$ordersPaid++;
	} elseif (in_array($state, array(90, 91), true)) {
		$ordersFailed++;
	} else {
		$ordersOpen++;
	}
	if (!empty($row->fk_facture)) {
		$ordersWithInvoice++;
	}
	if (!empty($row->fk_paiement)) {
		$ordersWithPayment++;
	}
}
print '<div class="taler-home-card">';
print '<div class="taler-card-heading"><span><span class="fa fa-shopping-cart"></span>'.$langs->trans('TalerOrdersOverview').'</span><span class="right-actions"><a class="button" href="'.dol_buildpath('/talerbarr/talerorderlink_list.php', 1).'">'.$langs->trans('TalerOrderLinks').'</a></span></div>';
if (empty($orders)) {
	print '<div class="taler-section-empty">'.$langs->trans('NoOrdersYet').'</div>';
} else {
	print '<div class="taler-stat-grid">';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('OrdersTotal').'</div><div class="value">'.(int) $ordersTotal.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('OrdersOpen').'</div><div class="value">'.(int) $ordersOpen.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('OrdersPaid').'</div><div class="value">'.(int) $ordersPaid.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('OrdersFailed').'</div><div class="value">'.(int) $ordersFailed.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('OrdersWithInvoice').'</div><div class="value">'.(int) $ordersWithInvoice.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('OrdersWithPayment').'</div><div class="value">'.(int) $ordersWithPayment.'</div></div>';
	print '</div>';
}
print '</div>'; // end orders card

// Products card (glance numbers only)
$productsTotal = count($products);
$productsSyncEnabled = 0;
$productsSyncError = 0;
$productsUnlimited = 0;
$productsSyncedOk = 0;
foreach ($products as $row) {
	if (!empty($row->sync_enabled)) {
		$productsSyncEnabled++;
	}
	if (!empty($row->last_sync_status) && $row->last_sync_status === 'error') {
		$productsSyncError++;
	}
	if (!empty($row->last_sync_status) && $row->last_sync_status === 'ok') {
		$productsSyncedOk++;
	}
	$stockVal = isset($row->taler_total_stock) ? (int) $row->taler_total_stock : null;
	if ($stockVal === null || $stockVal < 0) {
		$productsUnlimited++;
	}
}
print '<div class="taler-home-card">';
print '<div class="taler-card-heading"><span><span class="fa fa-cube"></span>'.$langs->trans('TalerProductsOverview').'</span><span class="right-actions"><a class="button" href="'.dol_buildpath('/talerbarr/talerproductlink_list.php', 1).'">'.$langs->trans('TalerProductLinks').'</a></span></div>';
if (empty($products)) {
	print '<div class="taler-section-empty">'.$langs->trans('NoProductsYet').'</div>';
} else {
	print '<div class="taler-stat-grid">';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('ProductsTotal').'</div><div class="value">'.(int) $productsTotal.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('ProductsSyncEnabled').'</div><div class="value">'.(int) $productsSyncEnabled.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('ProductsSyncedOk').'</div><div class="value">'.(int) $productsSyncedOk.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('ProductsSyncError').'</div><div class="value">'.(int) $productsSyncError.'</div></div>';
	print '<div class="taler-stat"><div class="label">'.$langs->trans('ProductsUnlimitedStock').'</div><div class="value">'.(int) $productsUnlimited.'</div></div>';
	print '</div>';
}
print '</div>'; // end products card

print '</div>'; // end middle grid

$statusFile = DOL_DATA_ROOT.'/talerbarr/sync.status.json';
// TODO: Would be very nice, if we can make some js that will listen, and will update this block,
//   on every update of this file
$status     = file_exists($statusFile) ? json_decode(@file_get_contents($statusFile), true) : null;

// Row 3: configuration + sync status
print '<div class="taler-home-grid taler-home-bottom" style="grid-template-columns:repeat(auto-fit,minmax(420px,1fr));">';

if (!empty($singleton) && (empty($singleton->verification_ok) || $singleton->verification_ok)) {
	$safeUrl  = dol_escape_htmltag($singleton->talermerchanturl);
	$safeUser = dol_escape_htmltag($singleton->username);
	$editUrl  = dol_buildpath('/talerbarr/talerconfig_card.php', 1).'?id='.(int) $singleton->id.'&action=edit';

	$directionKey = ((string) $singleton->syncdirection === '1') ? 'pull' : 'push';
	$directionLabel = $directionKey === 'pull'
		? $langs->trans('SyncDirectionTalerToDolibarr')
		: $langs->trans('SyncDirectionDolibarrToTaler');
	$directionDisplay = dol_escape_htmltag($directionLabel);

	$tokenExpiry = '';
	if (!empty($singleton->expiration)) {
		$expiresTs = is_numeric($singleton->expiration) ? (int) $singleton->expiration : strtotime((string) $singleton->expiration);
		if ($expiresTs > 0) {
			$tokenExpiry = dol_print_date($expiresTs, 'dayhour');
		}
	}
	$tokenExpiryHtml = $tokenExpiry !== '' ? dol_escape_htmltag($tokenExpiry) : '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>';

	$bankHtml = '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>';
	if (!empty($singleton->fk_bank_account)) {
		$account = new Account($db);
		if ($account->fetch((int) $singleton->fk_bank_account) > 0) {
			$bankHtml = $account->getNomUrl(1);
		}
	}

	$customerHtml = '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>';
	if (!empty($singleton->fk_default_customer)) {
		$thirdparty = new Societe($db);
		if ($thirdparty->fetch((int) $singleton->fk_default_customer) > 0) {
			$customerHtml = $thirdparty->getNomUrl(1);
		}
	}

	$syncTimingHtml = '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>';
	if (isset($singleton->syncdirection) && (int) $singleton->syncdirection === 1) {
		$syncTimingHtml = !empty($singleton->sync_on_paid) ? $langs->trans('SyncAtOrderPaid') : $langs->trans('SyncAtOrderCreated');
	}

	$verificationHtml = '<span class="badge badge-status1">'.$langs->trans('SyncVerificationFailed').'</span>';
	if (!empty($singleton->verification_ok)) {
		$verificationHtml = '<span class="badge badge-status4">'.$langs->trans('SyncVerificationOk').'</span>';
	} elseif (!empty($singleton->verification_error)) {
		$verificationHtml .= ' <span class="opacitymedium">'.dol_escape_htmltag($singleton->verification_error).'</span>';
	} elseif (!empty($errmsg)) {
		$verificationHtml .= ' <span class="opacitymedium">'.dol_escape_htmltag($errmsg).'</span>';
	}

	$configRows = array(
		$langs->trans('TalerMerchantURL') => '<a href="'.$safeUrl.'" target="_blank" rel="noopener">'.$safeUrl.'</a>',
		$langs->trans('TalerInstance') => $safeUser,
		$langs->trans('SyncDirection') => $directionDisplay,
		$langs->trans('SyncTiming') => dol_escape_htmltag($syncTimingHtml),
		$langs->trans('SyncTokenExpires') => $tokenExpiryHtml,
		$langs->trans('SyncBankAccount') => $bankHtml,
	$langs->trans('TalerDefaultCustomer') => $customerHtml,
	$langs->trans('SyncVerificationStatus') => $verificationHtml,
	);

	print '<div class="taler-home-card">';
	print '<div class="taler-card-heading"><span><span class="fa fa-cogs"></span>'.$langs->trans("TalerBarConfiguration").'</span><a class="button" href="'.$editUrl.'">'.$langs->trans("Modify").'</a></div>';
	print '<ul class="taler-plain-list">';
	foreach ($configRows as $label => $value) {
		print '<li><span>'.dol_escape_htmltag($label).'</span><span>'.$value.'</span></li>';
	}
	print '</ul>';
	print '</div>';
}

print '<div class="taler-home-card" id="taler-sync-card" data-sync-initial="'.dol_escape_htmltag(json_encode($status)).'">';
print '<div class="taler-card-heading">';
print '<span><span class="fa fa-sync-alt"></span>'.$langs->trans("TalerBarrSyncStatus").'</span>';
if ($user->admin) {
	print '<span class="right-actions"><form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="runsync"><input type="submit" class="button" value="'.$langs->trans("RunSyncNow").'"></form></span>';
}
print '</div>';
if (!$status) {
	print '<div class="taler-section-empty">'.$langs->trans("NoSyncYet").'</div>';
} else {
	$phaseKey = isset($status['phase']) ? (string) $status['phase'] : '';
	$phaseMap = array(
		'start' => $langs->trans('SyncPhaseStart'),
		'push-products' => $langs->trans('SyncPhasePushProducts'),
		'push-orders' => $langs->trans('SyncPhasePushOrders'),
		'pull-products' => $langs->trans('SyncPhasePullProducts'),
		'pull-orders' => $langs->trans('SyncPhasePullOrders'),
		'stale-recovery' => $langs->trans('SyncPhaseStaleRecovery'),
		'done' => $langs->trans('SyncPhaseDone'),
		'abort' => $langs->trans('SyncPhaseAbort'),
	);
	$phaseLabel = isset($phaseMap[$phaseKey]) ? $phaseMap[$phaseKey] : dol_escape_htmltag($phaseKey);
	if ($phaseLabel === '') {
		$phaseLabel = '?';
	}

	// Direction + summary
	$directionKey = strtolower((string) ($status['direction'] ?? ''));
	if ($directionKey === 'pull') {
		$directionLabel = dol_escape_htmltag($langs->trans('SyncDirectionTalerToDolibarr'));
	} elseif ($directionKey === 'push') {
		$directionLabel = dol_escape_htmltag($langs->trans('SyncDirectionDolibarrToTaler'));
	} else {
		$directionLabel = dol_escape_htmltag((string) ($status['direction'] ?? '?'));
	}
	if ($directionLabel === '') {
		$directionLabel = '?';
	}

	$tsRaw = $status['ts'] ?? '';
	$tsHtml = '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>';
	if ($tsRaw !== '') {
		$timestamp = is_numeric($tsRaw) ? (int) $tsRaw : strtotime((string) $tsRaw);
		if ($timestamp) {
			$tsHtml = dol_escape_htmltag(dol_print_date($timestamp, 'dayhour'));
		} else {
			$tsHtml = dol_escape_htmltag((string) $tsRaw);
		}
	}

	// Build lines like configuration block
	print '<ul class="taler-plain-list" id="taler-sync-list">';
	print '<li><span>'.dol_escape_htmltag($langs->trans("Phase")).'</span><span>'.$phaseLabel.'</span></li>';
	print '<li><span>'.dol_escape_htmltag($langs->trans("Direction")).'</span><span>'.$directionLabel.'</span></li>';
	print '<li><span>'.dol_escape_htmltag($langs->trans("Time")).'</span><span>'.$tsHtml.'</span></li>';

	$bucketLabels = array(
		'products' => $langs->trans('SyncMetricsProducts'),
		'orders' => $langs->trans('SyncMetricsOrders'),
	);
	$metricLabels = array(
		'processed' => $langs->trans('SyncMetricProcessed'),
		'synced' => $langs->trans('SyncMetricSynced'),
		'skipped_paid' => $langs->trans('SyncMetricSkippedPaid'),
		'total' => $langs->trans('SyncMetricTotal'),
	);

	foreach ($bucketLabels as $bucketKey => $bucketTitle) {
		if (empty($status[$bucketKey]) || !is_array($status[$bucketKey])) {
			continue;
		}
		$bucket = $status[$bucketKey];
		$parts = array();
		foreach ($metricLabels as $metricKey => $metricLabel) {
			if (!array_key_exists($metricKey, $bucket)) {
				continue;
			}
			$valueRaw = $bucket[$metricKey];
			if ($valueRaw === null || !is_numeric($valueRaw)) {
				continue;
			}
			$value = (int) $valueRaw;
			if ($metricKey === 'skipped_paid' && $value === 0) {
				continue;
			}
			$parts[] = dol_escape_htmltag($metricLabel).': '.$value;
		}
		if (!empty($parts)) {
			print '<li><span>'.dol_escape_htmltag($bucketTitle).'</span><span>'.implode(', ', $parts).'</span></li>';
		}
	}

	if (!empty($status['note'])) {
		print '<li><span>'.dol_escape_htmltag($langs->trans('Note')).'</span><span class="opacitymedium">'.dol_escape_htmltag($status['note']).'</span></li>';
	}

	if (!empty($status['error'])) {
		print '<li><span class="error">'.dol_escape_htmltag($langs->trans('Error')).'</span><span class="error">'.dol_escape_htmltag($status['error']).'</span></li>';
	}
	if (isset($status['processed'])) {
		$totalBits = array();
		$totalBits[] = dol_escape_htmltag($langs->trans('SyncMetricProcessed')).': '.((int) $status['processed']);
		if (array_key_exists('total', $status) && $status['total'] !== null) {
			$totalBits[] = dol_escape_htmltag($langs->trans('SyncMetricTotal')).': '.((int) $status['total']);
		}
		if (!empty($totalBits)) {
			print '<li><span>'.dol_escape_htmltag($langs->trans('Total')).'</span><span>'.implode(', ', $totalBits).'</span></li>';
		}
	}
	print '</ul>';
}
print '</div>';

print '</div>'; // end bottom grid

$ordersChartDataJson = json_encode($ordersChartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$ordersChartSeriesJson = json_encode($ordersChartSeriesMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$ordersChartSeriesKeysJson = json_encode(array_keys($ordersChartSeriesMeta), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$ordersChartAmountJson = json_encode($ordersChartAmountData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$ordersChartCurrency = dol_escape_js(dol_escape_htmltag($ordersChartCurrency));
$syncStatusJson = json_encode($status, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$syncStatusUrl = dol_escape_js(dol_buildpath('/talerbarr/ajax/syncstatus.php', 1));

print '<script>
(function() {
	var chartData = '.$ordersChartDataJson.';
	var chartAmountData = '.$ordersChartAmountJson.';
	var seriesMeta = '.$ordersChartSeriesJson.';
	var seriesKeys = '.$ordersChartSeriesKeysJson.';
	var chartEl = document.getElementById("taler-orders-chart");
	var emptyEl = document.getElementById("taler-orders-chart-empty");
	var selectEl = document.getElementById("taler-orders-range");
	var metricEl = document.getElementById("taler-orders-metric");
	var tooltipEl = document.getElementById("taler-orders-chart-tooltip");
	var chartHeight = 200;
	var currency = "'.$ordersChartCurrency.'";

	if (!chartEl || !selectEl || !metricEl) {
		return;
	}

	function showTooltip(text, evt) {
		if (!tooltipEl) return;
		tooltipEl.innerHTML = text;
		tooltipEl.style.display = "block";
		moveTooltip(evt);
	}

	function hideTooltip() {
		if (!tooltipEl) return;
		tooltipEl.style.display = "none";
	}

	function moveTooltip(evt) {
		if (!tooltipEl || !evt) return;
		var x = evt.clientX;
		var y = evt.clientY;
		tooltipEl.style.left = x + "px";
		tooltipEl.style.top = y + "px";
	}

	function render(rangeKey, metric) {
		var isAmount = metric === "amount";
		var data = isAmount ? chartAmountData[rangeKey] : chartData[rangeKey];
		hideTooltip();
		if (!data || !data.labels || !data.series) {
			chartEl.innerHTML = "";
			chartEl.style.display = "none";
			if (emptyEl) emptyEl.style.display = "block";
			return;
		}

		var labels = data.labels || [];
		var series = data.series || {};

		var totals = labels.map(function(_, idx) {
			var sum = 0;
			seriesKeys.forEach(function(key) {
				var arr = series[key] || [];
				var rawVal = arr[idx];
				var val = isAmount ? parseFloat(rawVal) : parseInt(rawVal, 10);
				if (!isNaN(val)) {
					sum += val;
				}
			});
			return sum;
		});

		var maxTotal = Math.max.apply(null, totals.concat([0]));
		chartEl.innerHTML = "";

		chartEl.style.display = "flex";
		if (emptyEl) emptyEl.style.display = "none";

		labels.forEach(function(label, idx) {
			var total = totals[idx] || 0;
			var col = document.createElement("div");
			col.className = "taler-chart-col";

			var bar = document.createElement("div");
			bar.className = "taler-chart-bar";

			var barHeight;
			if (maxTotal === 0) {
				barHeight = 12;
			} else {
				barHeight = total > 0 ? Math.max(8, Math.round((total / maxTotal) * chartHeight)) : 0;
			}
			var remainingHeight = barHeight;

			seriesKeys.forEach(function(key) {
				var rawVal = (series[key] && series[key][idx]) ? series[key][idx] : 0;
				var val = isAmount ? parseFloat(rawVal) : parseInt(rawVal, 10);
				if (!val) {
					return;
				}
				var seg = document.createElement("div");
				seg.className = "taler-chart-seg";

				var segHeight = total > 0 ? Math.round((val / total) * barHeight) : 0;
				if (segHeight < 4 && val > 0 && barHeight >= 8) {
					segHeight = 4;
				}
				if (segHeight > remainingHeight) {
					segHeight = remainingHeight;
				}
				remainingHeight -= segHeight;

				seg.style.height = segHeight + "px";
				seg.style.backgroundColor = seriesMeta[key] ? seriesMeta[key].color : "#b9a5ff";
				var suffix = isAmount ? ((currency ? " " + currency : "") ) : "";
				var titleBase = (seriesMeta[key] && seriesMeta[key].tooltip) ? seriesMeta[key].tooltip : (seriesMeta[key] ? seriesMeta[key].label : key);

				seg.dataset.seriesKey = key;
				seg.dataset.label = label;
				seg.dataset.value = val;
				seg.dataset.isAmount = isAmount ? "1" : "0";

				seg.addEventListener("mouseenter", function(e) {
					var text = "<strong>" + titleBase + "</strong>: " + val + suffix + "<br>" + label;
					showTooltip(text, e);
				});
				seg.addEventListener("mousemove", function(e) {
					moveTooltip(e);
				});
				seg.addEventListener("mouseleave", function() {
					hideTooltip();
				});
				bar.appendChild(seg);
			});

			if (bar.childNodes.length === 0) {
				var placeholder = document.createElement("div");
				placeholder.className = "taler-chart-seg taler-chart-seg-empty";
				placeholder.style.height = (barHeight > 0 ? barHeight : 8) + "px";
				bar.appendChild(placeholder);
			}

			bar.style.height = barHeight + "px";

			var labelEl = document.createElement("div");
			labelEl.className = "taler-chart-label";
			labelEl.textContent = label;

			col.appendChild(bar);
			col.appendChild(labelEl);
			chartEl.appendChild(col);
		});
	}

	selectEl.addEventListener("change", function(e) {
		render(e.target.value, metricEl.value || "count");
	});
	metricEl.addEventListener("change", function(e) {
		render(selectEl.value || "week", e.target.value);
	});

	render(selectEl.value || "week", metricEl.value || "count");
})();

(function() {
	var syncCard = document.getElementById("taler-sync-card");
	var syncUrl = "'.$syncStatusUrl.'";
	var syncStatus = '.$syncStatusJson.';
	var syncHeadingHtml = "";
	if (syncCard) {
		var headingEl = syncCard.querySelector(".taler-card-heading");
		if (headingEl) {
			syncHeadingHtml = headingEl.outerHTML;
		}
	}
	var phaseMap = {
		"start": "'.$langs->transnoentities('SyncPhaseStart').'",
		"push-products": "'.$langs->transnoentities('SyncPhasePushProducts').'",
		"push-orders": "'.$langs->transnoentities('SyncPhasePushOrders').'",
		"pull-products": "'.$langs->transnoentities('SyncPhasePullProducts').'",
		"pull-orders": "'.$langs->transnoentities('SyncPhasePullOrders').'",
		"stale-recovery": "'.$langs->transnoentities('SyncPhaseStaleRecovery').'",
		"done": "'.$langs->transnoentities('SyncPhaseDone').'",
		"abort": "'.$langs->transnoentities('SyncPhaseAbort').'"
	};
	var labels = {
		phase: "'.$langs->transnoentities('Phase').'",
		direction: "'.$langs->transnoentities('Direction').'",
		time: "'.$langs->transnoentities('Time').'",
		products: "'.$langs->transnoentities('SyncMetricsProducts').'",
		orders: "'.$langs->transnoentities('SyncMetricsOrders').'",
		processed: "'.$langs->transnoentities('SyncMetricProcessed').'",
		synced: "'.$langs->transnoentities('SyncMetricSynced').'",
		skipped_paid: "'.$langs->transnoentities('SyncMetricSkippedPaid').'",
		total: "'.$langs->transnoentities('SyncMetricTotal').'",
		note: "'.$langs->transnoentities('Note').'",
		error: "'.$langs->transnoentities('Error').'",
		notDefined: "'.$langs->transnoentities('NotDefined').'",
		noSync: "'.$langs->transnoentities('NoSyncYet').'"
	};
	var pollTimer = null;
	var ACTIVE_DELAY = 1000;
	var STABLE_DELAY = 60000;
	var fastPollRemaining = 3;

	function buildHtml(status) {
		if (!status) {
			return \'<div class="taler-section-empty">\' + labels.noSync + \'</div>\';
		}

		var phaseKey = (status.phase || "").toString();
		var phaseLabel = phaseMap[phaseKey] || phaseKey || "?";

		var dirKey = (status.direction || "").toString().toLowerCase();
		var dirLabel = dirKey === "pull" ? "'.$langs->transnoentities('SyncDirectionTalerToDolibarr').'" :
			(dirKey === "push" ? "'.$langs->transnoentities('SyncDirectionDolibarrToTaler').'" : (status.direction || "?"));

		var tsRaw = status.ts || "";
		var tsHtml = labels.notDefined;
		if (tsRaw !== "") {
			var dateObj = new Date(isNaN(tsRaw) ? tsRaw : parseInt(tsRaw, 10) * 1000);
			if (!isNaN(dateObj.getTime())) {
				tsHtml = dateObj.toLocaleString();
			} else {
				tsHtml = tsRaw;
			}
		}

		var html = [];
		html.push("<ul class=\"taler-plain-list\">");
		html.push("<li><span>" + labels.phase + "</span><span>" + phaseLabel + "</span></li>");
		html.push("<li><span>" + labels.direction + "</span><span>" + dirLabel + "</span></li>");
		html.push("<li><span>" + labels.time + "</span><span>" + tsHtml + "</span></li>");

		var buckets = {products: labels.products, orders: labels.orders};
		var metricLabels = {processed: labels.processed, synced: labels.synced, skipped_paid: labels.skipped_paid, total: labels.total};
		Object.keys(buckets).forEach(function(bucketKey) {
			var bucket = status[bucketKey];
			if (!bucket || typeof bucket !== "object") return;
			var parts = [];
			Object.keys(metricLabels).forEach(function(mKey) {
				if (bucket[mKey] === undefined || bucket[mKey] === null) return;
				if (mKey === "skipped_paid" && parseInt(bucket[mKey], 10) === 0) return;
				parts.push(metricLabels[mKey] + ": " + bucket[mKey]);
			});
			if (parts.length) {
				html.push("<li><span>" + buckets[bucketKey] + "</span><span>" + parts.join(", ") + "</span></li>");
			}
		});

		if (status.note) {
			html.push("<li><span>" + labels.note + "</span><span class=\"opacitymedium\">" + status.note + "</span></li>");
		}
		if (status.error) {
			html.push("<li><span class=\"error\">" + labels.error + "</span><span class=\"error\">" + status.error + "</span></li>");
		}
		if (status.processed !== undefined) {
			var totalBits = [];
			totalBits.push(labels.processed + ": " + status.processed);
			if (status.total !== undefined && status.total !== null) {
				totalBits.push(labels.total + ": " + status.total);
			}
			html.push("<li><span>" + labels.total + "</span><span>" + totalBits.join(", ") + "</span></li>");
		}

		html.push("</ul>");
		try {
			var pretty = JSON.stringify(status, null, 2);
			html.push("<div class=\"taler-sync-debug\"><pre>" + pretty + "</pre></div>");
		} catch(e) {
			/* ignore */
		}
		return html.join("");
	}

	function render(status) {
		if (!syncCard) return;
		var body = buildHtml(status);
		var heading = syncHeadingHtml || "";
		syncCard.innerHTML = heading + body;
	}

	function isStable(status) {
		if (!status || typeof status !== "object") return false;
		var phaseKey = (status.phase || "").toString().toLowerCase();
		return phaseKey === "done" || phaseKey === "abort";
	}

	function scheduleNext(status, fallbackDelay) {
		if (pollTimer) {
			clearTimeout(pollTimer);
		}
		var delay;
		if (fallbackDelay) {
			delay = fallbackDelay;
		} else if (fastPollRemaining > 0) {
			delay = ACTIVE_DELAY;
		} else {
			delay = isStable(status) ? STABLE_DELAY : ACTIVE_DELAY;
		}
		pollTimer = setTimeout(poll, delay);
	}

	function poll() {
		fetch(syncUrl, {credentials: "same-origin"})
			.then(function(resp) { return resp.json(); })
			.then(function(data) {
				if (data && data.status !== undefined) {
					syncStatus = data.status;
					render(syncStatus);
				}
				if (fastPollRemaining > 0) fastPollRemaining--;
				scheduleNext(syncStatus);
			})
			.catch(function() {
				// back off a bit on errors
				scheduleNext(syncStatus, 15000);
			});
	}

	if (syncStatus !== undefined) {
		render(syncStatus);
	}
	// Start polling immediately to catch changes right after clicking "Run sync"
	poll();
})();
</script>';

print '</div>'; // end taler-home-wrap

// End of page
llxFooter();
$db->close();
