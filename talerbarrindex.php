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
</style>';

print load_fiche_titre($langs->trans("TalerBarrArea"), '', 'cash-register');

print '<div class="taler-home-wrap">';
print '<div class="taler-home-grid taler-home-top">';

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
		$langs->trans('SyncTokenExpires') => $tokenExpiryHtml,
		$langs->trans('SyncBankAccount') => $bankHtml,
	$langs->trans('TalerDefaultCustomer') => $customerHtml,
	$langs->trans('SyncVerificationStatus') => $verificationHtml,
	);

	print '<div class="taler-home-card">';
	print '<div class="taler-card-heading">'.$langs->trans("TalerBarConfiguration").'<a class="button" href="'.$editUrl.'">'.$langs->trans("Modify").'</a></div>';
	print '<ul class="taler-plain-list">';
	foreach ($configRows as $label => $value) {
		print '<li><span>'.dol_escape_htmltag($label).'</span><span>'.$value.'</span></li>';
	}
	print '</ul>';
	print '</div>';
}

$statusFile = DOL_DATA_ROOT.'/talerbarr/sync.status.json';
// TODO: Would be very nice, if we can make some js that will listen, and will update this block,
//   on every update of this file
$status     = file_exists($statusFile) ? json_decode(@file_get_contents($statusFile), true) : null;

print '<div class="taler-home-card">';
print '<div class="taler-card-heading">';
print '<span>'.$langs->trans("TalerBarrSyncStatus").'</span>';
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
	print '<ul class="taler-plain-list">';
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
print '</div>';

print '</div>'; // close taler-home-top grid

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

$thirdpartyStatic = new Societe($db);
$commandeStatic = new Commande($db);
$factureStatic = new Facture($db);
$paiementStatic = new Paiement($db);
$productStatic = new Product($db);

print '<div class="taler-home-grid taler-home-bottom" style="grid-template-columns:repeat(auto-fit,minmax(360px,1fr));">';

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
print '<div class="taler-card-heading">'.$langs->trans('TalerOrdersOverview').'<span class="right-actions"><a class="button" href="'.dol_buildpath('/talerbarr/talerorderlink_list.php', 1).'">'.$langs->trans('TalerOrderLinks').'</a></span></div>';
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
print '<div class="taler-card-heading">'.$langs->trans('TalerProductsOverview').'<span class="right-actions"><a class="button" href="'.dol_buildpath('/talerbarr/talerproductlink_list.php', 1).'">'.$langs->trans('TalerProductLinks').'</a></span></div>';
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

print '</div>'; // end bottom grid

print '</div>'; // end taler-home-wrap

// End of page
llxFooter();
$db->close();
