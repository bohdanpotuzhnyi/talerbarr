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
 *  \file       talerorderlink_card.php
 *  \ingroup    talerbarr
 *  \brief      Card page to inspect a single TalerOrderLink row (raw DB snapshot).
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

dol_include_once('/talerbarr/class/talerorderlink.class.php');
dol_include_once('/talerbarr/class/talerproductlink.class.php');
dol_include_once('/talerbarr/class/talerconfig.class.php');

$langs->loadLangs(array('talerbarr@talerbarr', 'other'));

$id   = GETPOSTINT('id');
$back = GETPOST('backtopage', 'alpha');
$action = GETPOST('action', 'aZ09');

$enablepermissioncheck = getDolGlobalInt('TALERBARR_ENABLE_PERMISSION_CHECK');
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('talerbarr', 'talerorderlink', 'read');
	$permissiontowrite = $user->hasRight('talerbarr', 'talerorderlink', 'write');
} else {
	$permissiontoread = 1;
	$permissiontowrite = 1;
}

if ($user->socid > 0) accessforbidden();
if (!isModEnabled('talerbarr')) accessforbidden('Module talerbarr not enabled');
if (!$permissiontoread) accessforbidden();

$object = new TalerOrderLink($db);
$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label($object->table_element);

if ($id > 0) {
	$result = $object->fetch($id);
	if ($result <= 0) {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	}
	$object->fetch_optionals($id);
}

if (empty($object->id)) {
	print $langs->trans("ErrorRecordNotFound");
	exit;
}

$redirectUrl = $_SERVER['PHP_SELF'].'?id='.(int) $object->id;
if (!empty($back)) {
	$redirectUrl .= '&backtopage='.urlencode($back);
}

$extractHttpStatus = static function (\Throwable $e): int {
	$msg = (string) $e->getMessage();
	if (preg_match('/HTTP\s+([0-9]{3})\s+for/i', $msg, $m)) {
		return (int) $m[1];
	}
	return 0;
};

$extractHttpHint = static function (\Throwable $e): string {
	$msg = (string) $e->getMessage();
	$jsonPos = strpos($msg, '{');
	if ($jsonPos !== false) {
		$decoded = json_decode(substr($msg, $jsonPos), true);
		if (is_array($decoded)) {
			$hint = trim((string) ($decoded['hint'] ?? $decoded['detail'] ?? ''));
			if ($hint !== '') {
				return $hint;
			}
		}
	}
	return '';
};

$isCurrencyConflictHint = static function (string $hint): bool {
	$normalized = strtolower(trim($hint));
	if ($normalized === '') {
		return false;
	}

	return (
		str_contains($normalized, 'currency specified in the operation')
		|| (str_contains($normalized, 'currency') && str_contains($normalized, 'current state'))
		|| str_contains($normalized, 'currency mismatch')
	);
};

$parsePositiveDecimal = static function (string $raw): ?float {
	$normalized = str_replace(',', '.', trim($raw));
	$normalized = preg_replace('/\s+/', '', $normalized);
	if ($normalized === '' || !preg_match('/^\d+(?:\.\d{1,8})?$/', $normalized)) {
		return null;
	}
	$value = (float) $normalized;
	if ($value <= 0) {
		return null;
	}
	return $value;
};

$mapCurrencyForTaler = static function (string $currency, ?TalerConfig $config = null): string {
	$normalized = strtoupper(trim($currency));
	if ($normalized === '') {
		return '';
	}
	$alias = '';
	if ($config instanceof TalerConfig && !empty($config->taler_currency_alias)) {
		$alias = strtoupper(trim((string) $config->taler_currency_alias));
	}
	if ($alias === '') {
		$alias = TalerConfig::getCurrencyAlias();
	}
	if ($alias !== '' && $normalized === TalerConfig::getDolibarrCurrency()) {
		return $alias;
	}
	return $normalized;
};

$resolveVerifiedConfigForInstance = static function (DoliDB $db, string $instance, ?string &$error = null): ?TalerConfig {
	$error = null;
	$instance = trim($instance);
	if ($instance !== '') {
		$cfgCandidate = new TalerConfig($db);
		$sqlCfg = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$cfgCandidate->table_element
			." WHERE username = '".$db->escape($instance)."'"
			.' AND entity IN ('.getEntity('talerconfig', true).') ORDER BY rowid DESC LIMIT 1';
		$resCfg = $db->query($sqlCfg);
		if ($resCfg && ($cfgObj = $db->fetch_object($resCfg))) {
			if ($cfgCandidate->fetch((int) $cfgObj->rowid) > 0 && !empty($cfgCandidate->verification_ok)) {
				$db->free($resCfg);
				return $cfgCandidate;
			}
		}
		if ($resCfg) {
			$db->free($resCfg);
		}
	}

	$cfgErr = null;
	$config = TalerConfig::fetchSingletonVerified($db, $cfgErr);
	if (!$config || empty($config->verification_ok)) {
		$error = $cfgErr ?: 'Configuration not verified';
		return null;
	}

	return $config;
};

$findLatestCreditNoteId = static function (DoliDB $db, int $sourceInvoiceId): int {
	if ($sourceInvoiceId <= 0) {
		return 0;
	}

	$creditNoteId = 0;
	$sqlCredit = sprintf(
		'SELECT rowid FROM %sfacture WHERE fk_facture_source = %d AND type = %d ORDER BY rowid DESC LIMIT 1',
		MAIN_DB_PREFIX,
		(int) $sourceInvoiceId,
		(int) Facture::TYPE_CREDIT_NOTE
	);
	$resCredit = $db->query($sqlCredit);
	if ($resCredit) {
		if ($objCredit = $db->fetch_object($resCredit)) {
			$creditNoteId = (int) $objCredit->rowid;
		}
		$db->free($resCredit);
	}

	if ($creditNoteId > 0) {
		return $creditNoteId;
	}

	$sqlCredit = sprintf(
		"SELECT f.rowid FROM %selement_element ee JOIN %sfacture f ON f.rowid = ee.fk_target WHERE ee.sourcetype = 'facture' AND ee.targettype = 'facture' AND ee.fk_source = %d AND f.type = %d ORDER BY ee.rowid DESC LIMIT 1",
		MAIN_DB_PREFIX,
		MAIN_DB_PREFIX,
		(int) $sourceInvoiceId,
		(int) Facture::TYPE_CREDIT_NOTE
	);
	$resCredit = $db->query($sqlCredit);
	if ($resCredit) {
		if ($objCredit = $db->fetch_object($resCredit)) {
			$creditNoteId = (int) $objCredit->rowid;
		}
		$db->free($resCredit);
	}

	return $creditNoteId;
};

$createCreditNoteFromRefund = static function (
	DoliDB $db,
	Facture $sourceInvoice,
	User $user,
	float $refundAmount,
	string $refundReason,
	string $orderId,
	string $refundUri,
	?int $refundTs = null
) use ($findLatestCreditNoteId): array {
	$result = array(
		'ok' => false,
		'created' => false,
		'validated' => false,
		'already_exists' => false,
		'id' => 0,
		'ref' => '',
		'error' => '',
	);

	$sourceInvoiceId = (int) ($sourceInvoice->id ?? 0);
	if ($sourceInvoiceId <= 0) {
		$result['error'] = 'Source invoice not found';
		dol_syslog('talerorderlink_card credit-note creation skipped: source invoice missing for order '.$orderId, LOG_WARNING);
		return $result;
	}

	$existingId = $findLatestCreditNoteId($db, $sourceInvoiceId);
	if ($existingId > 0) {
		$existing = new Facture($db);
		if ($existing->fetch($existingId) > 0) {
			$result['ok'] = true;
			$result['already_exists'] = true;
			$result['id'] = $existingId;
			$result['ref'] = (string) ($existing->ref ?? ('#'.$existingId));
			$result['validated'] = ((int) ($existing->status ?? $existing->statut ?? 0) > Facture::STATUS_DRAFT);
			dol_syslog('talerorderlink_card credit-note already exists for order '.$orderId.': #'.$existingId.' ref='.(string) ($existing->ref ?? ''), LOG_INFO);
			return $result;
		}
	}

	$refundAmount = (float) price2num($refundAmount, 'MU');
	if ($refundAmount <= 0.0) {
		$result['error'] = 'Refund amount must be positive';
		dol_syslog('talerorderlink_card credit-note creation skipped: non-positive refund amount for order '.$orderId, LOG_WARNING);
		return $result;
	}

	$credit = new Facture($db);
	$credit->socid = (int) ($sourceInvoice->socid ?? 0);
	$credit->type = Facture::TYPE_CREDIT_NOTE;
	$credit->fk_facture_source = $sourceInvoiceId;
	$credit->date = ($refundTs && $refundTs > 0) ? $refundTs : dol_now();
	if (!empty($sourceInvoice->date_pointoftax)) {
		$credit->date_pointoftax = $sourceInvoice->date_pointoftax;
	}
	$credit->cond_reglement_id = 0;
	if (!empty($sourceInvoice->mode_reglement_id)) {
		$credit->mode_reglement_id = (int) $sourceInvoice->mode_reglement_id;
	} elseif (!empty($sourceInvoice->fk_mode_reglement)) {
		$credit->mode_reglement_id = (int) $sourceInvoice->fk_mode_reglement;
	}
	if (!empty($sourceInvoice->fk_account)) {
		$credit->fk_account = (int) $sourceInvoice->fk_account;
	}
	if (!empty($sourceInvoice->fk_project)) {
		$credit->fk_project = (int) $sourceInvoice->fk_project;
	}

	$createId = $credit->create($user);
	if ($createId <= 0) {
		$result['error'] = $credit->error ?: $db->lasterror();
		dol_syslog('talerorderlink_card credit-note create failed for order '.$orderId.': '.$result['error'], LOG_ERR);
		return $result;
	}

	$creditId = (int) $createId;
	$result['id'] = $creditId;
	$result['created'] = true;

	$vatRate = 0.0;
	if (method_exists($sourceInvoice, 'fetch_lines') && (empty($sourceInvoice->lines) || !is_array($sourceInvoice->lines))) {
		$sourceInvoice->fetch_lines();
	}
	if (!empty($sourceInvoice->lines) && is_array($sourceInvoice->lines)) {
		foreach ($sourceInvoice->lines as $line) {
			if (isset($line->tva_tx) && is_numeric((string) $line->tva_tx)) {
				$vatRate = (float) $line->tva_tx;
				break;
			}
		}
	}

	$description = 'GNU Taler refund '.$orderId;
	$refundReasonClean = trim($refundReason);
	if ($refundReasonClean !== '') {
		$description .= ' - '.$refundReasonClean;
	}
	$lineRes = $credit->addline($description, 0, 1, $vatRate, 0, 0, 0, 0, '', '', 0, 0, 0, 'TTC', $refundAmount);
	if ($lineRes <= 0) {
		$result['error'] = $credit->error ?: 'Unable to create credit-note line';
		dol_syslog('talerorderlink_card credit-note line failed for order '.$orderId.' credit #'.$creditId.': '.$result['error'], LOG_ERR);
		return $result;
	}

	$credit->fetch($creditId);
	$credit->update_price(1);

	$noteMarker = '[taler-credit-note-refund '.((string) ($credit->ref ?? ('ID'.$creditId))).']';
	$note = $noteMarker;
	if ($refundUri !== '') {
		$note .= ' '.trim($refundUri);
	}
	if ($refundReasonClean !== '') {
		$note .= ' '.trim($refundReasonClean);
	}
	$credit->update_note_public(trim($note));

	$validateRes = $credit->validate($user);
	if ($validateRes <= 0) {
		$result['error'] = $credit->error ?: 'Unable to validate credit note';
		$credit->fetch($creditId);
		$result['ref'] = (string) ($credit->ref ?? ('#'.$creditId));
		$result['ok'] = true; // Created even if validation failed.
		dol_syslog('talerorderlink_card credit-note validate failed for order '.$orderId.' credit #'.$creditId.': '.$result['error'], LOG_WARNING);
		return $result;
	}

	$credit->fetch($creditId);
	$result['ref'] = (string) ($credit->ref ?? ('#'.$creditId));
	$result['validated'] = true;
	$result['ok'] = true;
	dol_syslog('talerorderlink_card credit-note created for order '.$orderId.' credit #'.$creditId.' ref='.$result['ref'], LOG_INFO);
	return $result;
};

$decodeJsonText = static function ($raw): array {
	$text = trim((string) $raw);
	if ($text === '') {
		return array('present' => false, 'valid' => false, 'pretty' => '', 'error' => '', 'decoded' => null);
	}
	$decoded = json_decode($text, true);
	if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
		return array(
			'present' => true,
			'valid' => false,
			'pretty' => $text,
			'error' => json_last_error_msg(),
			'decoded' => null,
		);
	}

	return array(
		'present' => true,
		'valid' => true,
		'pretty' => (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		'error' => '',
		'decoded' => $decoded,
	);
};

$resolveSyslogFilePath = static function ($conf): string {
	$configured = '';
	if (isset($conf->global) && isset($conf->global->SYSLOG_FILE)) {
		$configured = trim((string) $conf->global->SYSLOG_FILE);
	}
	$configured = trim($configured, " \t\n\r\0\x0B'\"");
	if ($configured === '') {
		return DOL_DATA_ROOT.'/dolibarr.log';
	}

	$path = str_replace(
		array('DOL_DATA_ROOT', 'DOL_DOCUMENT_ROOT'),
		array((string) DOL_DATA_ROOT, (string) DOL_DOCUMENT_ROOT),
		$configured
	);
	if ($path === '') {
		return DOL_DATA_ROOT.'/dolibarr.log';
	}

	$isAbsolute = ($path[0] ?? '') === '/'
		|| (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
	if (!$isAbsolute) {
		$path = rtrim((string) DOL_DATA_ROOT, '/').'/'.ltrim($path, '/');
	}

	return $path;
};

$readLogExcerpt = static function (string $path, array $patterns, int $tailBytes = 524288, int $maxLines = 40): array {
	$result = array(
		'path' => $path,
		'readable' => false,
		'error' => '',
		'truncated' => false,
		'lines' => array(),
	);

	$patterns = array_values(array_filter(array_map(static function ($v) {
		return trim((string) $v);
	},
		$patterns),
		static function ($v) {
			return $v !== '';
		}));

	if ($path === '' || !is_file($path) || !is_readable($path)) {
		$result['error'] = 'Log file not readable';
		return $result;
	}
	$result['readable'] = true;

	$fp = @fopen($path, 'rb');
	if (!$fp) {
		$result['error'] = 'Unable to open log file';
		return $result;
	}

	$size = @filesize($path);
	$size = is_int($size) ? $size : 0;
	$offset = max(0, $size - $tailBytes);
	if ($offset > 0) {
		$result['truncated'] = true;
		@fseek($fp, $offset);
		// Drop first partial line from the tail chunk.
		@fgets($fp);
	}

	$content = stream_get_contents($fp);
	fclose($fp);

	if (!is_string($content) || $content === '') {
		return $result;
	}

	$lines = preg_split('/\r\n|\n|\r/', $content) ?: array();
	$matches = array();
	foreach ($lines as $line) {
		$line = trim((string) $line);
		if ($line === '') {
			continue;
		}
		if (empty($patterns)) {
			$matches[] = $line;
			continue;
		}
		foreach ($patterns as $pattern) {
			if (stripos($line, $pattern) !== false) {
				$matches[] = $line;
				break;
			}
		}
	}

	if (count($matches) > $maxLines) {
		$matches = array_slice($matches, -1 * $maxLines);
		$result['truncated'] = true;
	}
	$result['lines'] = $matches;
	return $result;
};

if ($action === 'create_taler_refund') {
	if (empty($permissiontowrite)) {
		accessforbidden();
	}

	$refundReasonRaw = trim((string) GETPOST('refund_reason', 'restricthtml'));
	$refundAmountInput = (string) GETPOST('refund_amount', 'none');
	$refundAmountValue = $parsePositiveDecimal($refundAmountInput);
	$sourceInvoice = null;
	if (!empty($object->fk_facture)) {
		$sourceInvoiceTmp = new Facture($db);
		if ($sourceInvoiceTmp->fetch((int) $object->fk_facture) > 0) {
			$sourceInvoice = $sourceInvoiceTmp;
		}
	}

	if ($refundReasonRaw === '') {
		setEventMessages($langs->trans('TalerRefundReasonRequired'), null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}
	if ($refundAmountValue === null) {
		setEventMessages($langs->trans('TalerRefundAmountInvalid'), null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}

	$cfgErr = null;
	$config = $resolveVerifiedConfigForInstance($db, (string) $object->taler_instance, $cfgErr);
	if (!$config || empty($config->verification_ok)) {
		setEventMessages($langs->trans('TalerRefundConfigInvalid').($cfgErr ? ': '.$cfgErr : ''), null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}

	try {
		$client = $config->talerMerchantClient();
	} catch (Throwable $e) {
		setEventMessages($langs->trans('TalerRefundConfigInvalid').': '.dol_escape_htmltag($e->getMessage()), null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}

	try {
		$statusPayload = $client->getOrderStatus((string) $object->taler_order_id, array('allow_refunded_for_repurchase' => 'YES'));
		if (!is_array($statusPayload)) {
			$statusPayload = (array) $statusPayload;
		}
		$statusPayload['order_id'] = $statusPayload['order_id'] ?? $statusPayload['orderId'] ?? $object->taler_order_id;
		$statusPayload['merchant_instance'] = $statusPayload['merchant_instance']
			?? ($statusPayload['merchant']['instance'] ?? $statusPayload['merchant']['id'] ?? null)
			?? $object->taler_instance;

		if (TalerOrderLink::payloadHasRefundEvidence((array) $statusPayload)) {
			TalerOrderLink::upsertFromTalerOfRefund($db, $statusPayload, $user);
		} else {
			TalerOrderLink::upsertFromTalerOfPayment($db, $statusPayload, $user);
		}
		$object->fetch((int) $object->id);
	} catch (Throwable $e) {
		setEventMessages($langs->trans('TalerRefundPreflightFailed').': '.dol_escape_htmltag($e->getMessage()), null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}

	$policy = $object->getRefundPolicy();
	if (empty($policy['eligible'])) {
		$message = $langs->trans((string) ($policy['message_key'] ?? 'TalerRefundBlockedUnknown')).' '.$langs->trans('TalerRefundUseAlternativeMethod');
		setEventMessages($message, null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}

	$remaining = $policy['remaining_total'];
	if ($remaining !== null && ($refundAmountValue - (float) $remaining) > 0.00000001) {
		setEventMessages($langs->trans('TalerRefundAmountTooHigh'), null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}

	$currency = strtoupper((string) ($policy['currency'] ?? $object->order_currency ?? ''));
	$currency = $mapCurrencyForTaler($currency, $config);
	if ($currency === '') {
		setEventMessages($langs->trans('TalerRefundBlockedMissingCurrency').' '.$langs->trans('TalerRefundUseAlternativeMethod'), null, 'errors');
		header('Location: '.$redirectUrl);
		exit;
	}

	$refundPayload = array(
		'refund' => TalerOrderLink::toTalerAmountString($currency, (float) $refundAmountValue),
		'reason' => $refundReasonRaw,
	);

	try {
		$refundResponse = $client->refundOrder((string) $object->taler_order_id, $refundPayload);
		$statusUrl = '';

		try {
			$statusPayload = $client->getOrderStatus((string) $object->taler_order_id, array('allow_refunded_for_repurchase' => 'YES'));
			if (!is_array($statusPayload)) {
				$statusPayload = (array) $statusPayload;
			}
			$statusPayload['order_id'] = $statusPayload['order_id'] ?? $statusPayload['orderId'] ?? $object->taler_order_id;
			$statusPayload['merchant_instance'] = $statusPayload['merchant_instance']
				?? ($statusPayload['merchant']['instance'] ?? $statusPayload['merchant']['id'] ?? null)
				?? $object->taler_instance;
			$statusUrl = trim((string) ($statusPayload['order_status_url'] ?? $statusPayload['status_url'] ?? ''));
			TalerOrderLink::upsertFromTalerOfRefund($db, $statusPayload, $user);
		} catch (Throwable $statusError) {
			$fallbackPayload = array(
				'order_id' => (string) $object->taler_order_id,
				'merchant_instance' => (string) $object->taler_instance,
				'status' => 'refunded',
				'refund_amount' => $refundPayload['refund'],
				'reason' => $refundReasonRaw,
			);
			TalerOrderLink::upsertFromTalerOfRefund($db, $fallbackPayload, $user);
			dol_syslog('talerorderlink_card refund post-refresh failed: '.$statusError->getMessage(), LOG_WARNING);
		}
		$object->fetch((int) $object->id);

		$refundUri = trim((string) ($refundResponse['taler_refund_uri'] ?? ''));
		$creditNoteResult = null;
		if ($sourceInvoice instanceof Facture && !empty($sourceInvoice->id)) {
			$creditNoteResult = $createCreditNoteFromRefund(
				$db,
				$sourceInvoice,
				$user,
				(float) $refundAmountValue,
				$refundReasonRaw,
				(string) $object->taler_order_id,
				$refundUri,
				dol_now()
			);
		}

		$successMessage = $langs->trans('TalerRefundCreated', $refundPayload['refund']);
		if ($refundUri !== '') {
			$successMessage .= ' '.$langs->trans('TalerRefundURI').': '.dol_escape_htmltag($refundUri);
		}
		if ($statusUrl === '' && !empty($object->taler_status_url)) {
			$statusUrl = trim((string) $object->taler_status_url);
		}
		if ($statusUrl !== '' && preg_match('~^https?://~i', $statusUrl)) {
			$successMessage .= ' '.$langs->trans('TalerRefundStatusURL').': '.dol_escape_htmltag($statusUrl);
		}
		if (is_array($creditNoteResult)) {
			if (!empty($creditNoteResult['already_exists'])) {
				$successMessage .= ' Credit note already exists: '.dol_escape_htmltag((string) ($creditNoteResult['ref'] ?: ('#'.$creditNoteResult['id'])));
			} elseif (!empty($creditNoteResult['created'])) {
				$successMessage .= ' Credit note: '.dol_escape_htmltag((string) ($creditNoteResult['ref'] ?: ('#'.$creditNoteResult['id'])));
				if (empty($creditNoteResult['validated']) && !empty($creditNoteResult['error'])) {
					setEventMessages(
						'Credit note created as draft but validation failed: '.dol_escape_htmltag((string) $creditNoteResult['error']),
						null,
						'warnings'
					);
				}
			} elseif (!empty($creditNoteResult['error'])) {
				setEventMessages('Unable to create credit note: '.dol_escape_htmltag((string) $creditNoteResult['error']), null, 'warnings');
			}
		} elseif (!$sourceInvoice) {
			setEventMessages('Refund was sent to Taler but no linked Dolibarr invoice was found to create a credit note.', null, 'warnings');
		}
		setEventMessages($successMessage, null, 'mesgs');
	} catch (Throwable $e) {
		$httpStatus = $extractHttpStatus($e);
		$hint = $extractHttpHint($e);
		switch ($httpStatus) {
			case 410:
				$message = $langs->trans('TalerRefundBlockedDeadline');
				break;
			case 403:
				$message = $langs->trans('TalerRefundBlockedForbidden');
				break;
			case 409:
				$message = $isCurrencyConflictHint($hint)
					? $langs->trans('TalerRefundBlockedCurrencyMismatch')
					: $langs->trans('TalerRefundAmountTooHigh');
				break;
			case 451:
				$message = $langs->trans('TalerRefundBlockedLegal');
				break;
			case 404:
				$message = $langs->trans('TalerRefundBlockedNotFound');
				break;
			default:
				$message = $langs->trans('TalerRefundFailedGeneric');
				break;
		}
		if ($hint !== '') {
			$message .= ' ('.$hint.')';
		}
		$message .= ' '.$langs->trans('TalerRefundUseAlternativeMethod');
		setEventMessages($message, null, 'errors');
	}

	header('Location: '.$redirectUrl);
	exit;
}

// Passive refresh so the order card reflects Taler-side changes (refund/wire/payment) without waiting for webhook.
$lastCheckTs = !empty($object->last_status_check_at) ? (int) dol_stringtotime((string) $object->last_status_check_at) : 0;
$refreshThrottleSeconds = 20;
$mustRefreshFromTaler = (!empty($object->taler_order_id) && ($lastCheckTs <= 0 || (dol_now() - $lastCheckTs) >= $refreshThrottleSeconds));
if ($mustRefreshFromTaler) {
	$cfgErr = null;
	$config = $resolveVerifiedConfigForInstance($db, (string) $object->taler_instance, $cfgErr);
	if ($config && !empty($config->verification_ok)) {
		try {
			$client = $config->talerMerchantClient();
			$statusPayload = $client->getOrderStatus((string) $object->taler_order_id, array('allow_refunded_for_repurchase' => 'YES'));
			if (!is_array($statusPayload)) {
				$statusPayload = (array) $statusPayload;
			}
			$statusPayload['order_id'] = $statusPayload['order_id'] ?? $statusPayload['orderId'] ?? $object->taler_order_id;
			$statusPayload['merchant_instance'] = $statusPayload['merchant_instance']
				?? ($statusPayload['merchant']['instance'] ?? $statusPayload['merchant']['id'] ?? null)
				?? $object->taler_instance;

			$statusKey = strtolower((string) ($statusPayload['order_status'] ?? $statusPayload['status'] ?? ''));
			$hasRefundSignal = TalerOrderLink::payloadHasRefundEvidence((array) $statusPayload);
			$hasWireEvidence = TalerOrderLink::payloadHasWireSettlementEvidence((array) $statusPayload);
			if (($statusKey === 'wired' || !empty($statusPayload['wired'])) && $hasWireEvidence) {
				TalerOrderLink::upsertFromTalerOfWireTransfer($db, $statusPayload, $user);
			} elseif ($hasRefundSignal) {
				TalerOrderLink::upsertFromTalerOfRefund($db, $statusPayload, $user);
			} else {
				TalerOrderLink::upsertFromTalerOfPayment($db, $statusPayload, $user);
			}
			$object->fetch((int) $object->id);
		} catch (Throwable $e) {
			dol_syslog('talerorderlink_card passive refresh failed for '.$object->taler_order_id.': '.$e->getMessage(), LOG_WARNING);
		}
	}
}

// Force visibility of fields so the card shows the raw row.
foreach ($object->fields as &$def) {
	$def['visible'] = 1;
}
unset($def);

$societe  = null;
$commande = null;
$facture  = null;
$paiement = null;
$creditNote = null;

if (!empty($object->fk_soc)) {
	$societeTmp = new Societe($db);
	if ($societeTmp->fetch((int) $object->fk_soc) > 0) {
		$societe = $societeTmp;
	}
}
if (!empty($object->fk_commande)) {
	$commandeTmp = new Commande($db);
	if ($commandeTmp->fetch((int) $object->fk_commande) > 0) {
		$commande = $commandeTmp;
	}
}
if (!empty($object->fk_facture)) {
	$factureTmp = new Facture($db);
	if ($factureTmp->fetch((int) $object->fk_facture) > 0) {
		$facture = $factureTmp;
	}
}
if (!empty($object->fk_paiement)) {
	$paiementTmp = new Paiement($db);
	if ($paiementTmp->fetch((int) $object->fk_paiement) > 0) {
		$paiement = $paiementTmp;
	}
}

if ($facture) {
	$creditNoteId = $findLatestCreditNoteId($db, (int) $facture->id);
	if (
		$creditNoteId <= 0
		&& $action === ''
		&& !empty($permissiontowrite)
	) {
		$refundedTotal = TalerOrderLink::amountStringToFloat((string) ($object->taler_refunded_total ?? ''));
		$isRefundedState = ((int) ($object->taler_state ?? 0) >= 70) || !empty($object->taler_refund_pending);
		if ($refundedTotal !== null && $refundedTotal > 0.00000001 && $isRefundedState) {
			$backfillReason = trim((string) ($object->taler_refund_last_reason ?? ''));
			if ($backfillReason === '') {
				$backfillReason = 'Backfill credit note from Taler refund status';
			}
			$backfillResult = $createCreditNoteFromRefund(
				$db,
				$facture,
				$user,
				(float) $refundedTotal,
				$backfillReason,
				(string) $object->taler_order_id,
				'',
				dol_now()
			);
			if (!empty($backfillResult['ok']) || !empty($backfillResult['created']) || !empty($backfillResult['already_exists'])) {
				$creditNoteId = $findLatestCreditNoteId($db, (int) $facture->id);
				if (!empty($backfillResult['created'])) {
					setEventMessages('Backfill created missing credit note: '.dol_escape_htmltag((string) ($backfillResult['ref'] ?: ('#'.$backfillResult['id']))), null, 'mesgs');
				}
			} elseif (!empty($backfillResult['error'])) {
				setEventMessages('Backfill failed to create credit note: '.dol_escape_htmltag((string) $backfillResult['error']), null, 'warnings');
			}
		}
	}
	if ($creditNoteId > 0) {
		$creditTmp = new Facture($db);
		if ($creditTmp->fetch($creditNoteId) > 0) {
			$creditNote = $creditTmp;
		}
	}
}

if ($commande && method_exists($commande, 'fetch_lines')) {
	if (empty($commande->lines) || !is_array($commande->lines)) {
		$commande->fetch_lines();
	}
}

$refundPolicy = $object->getRefundPolicy();
$refundRemainingValue = $refundPolicy['remaining_total'];
$refundRemainingLabel = $langs->trans('FlowNone');
if ($refundRemainingValue !== null && !empty($refundPolicy['currency'])) {
	$refundRemainingLabel = dol_escape_htmltag(TalerOrderLink::toTalerAmountString((string) $refundPolicy['currency'], (float) $refundRemainingValue));
}
$refundDeadlinePolicyLabel = !empty($refundPolicy['deadline_ts'])
	? dol_print_date((int) $refundPolicy['deadline_ts'], 'dayhour')
	: $langs->trans('FlowNone');
$refundDefaultAmountInput = '';
if ($refundRemainingValue !== null) {
	$refundDefaultAmountInput = rtrim(rtrim(number_format((float) $refundRemainingValue, 8, '.', ''), '0'), '.');
}
$alternativeMethodHint = $langs->trans('TalerRefundUseAlternativeMethod');
if ($facture) {
	$alternativeMethodHint .= ' '.$langs->trans('TalerRefundOpenInvoice').': '.$facture->getNomUrl(1);
}

// Show policy panel only when it is actionable:
// - order is paid (or beyond), and
// - no Taler refund is already registered for this order.
$statusRaw = strtolower(trim((string) ($object->merchant_status_raw ?? '')));
$isPaidOrBeyond = (
	(int) $object->taler_state >= 30
	|| in_array($statusRaw, array('paid', 'delivered', 'wired', 'refunded'), true)
	|| !empty($object->fk_paiement)
	|| !empty($object->paiement_datep)
	|| !empty($object->taler_paid_at)
);
$refundTotalForPolicy = TalerOrderLink::amountStringToFloat((string) ($object->taler_refunded_total ?? ''));
$refundTakenForPolicy = TalerOrderLink::amountStringToFloat((string) ($object->taler_refund_taken_total ?? ''));
$hasPositiveRefundForPolicy = ($refundTotalForPolicy !== null && $refundTotalForPolicy > 0.00000001)
	|| ($refundTakenForPolicy !== null && $refundTakenForPolicy > 0.00000001);
$hasTalerRefundAlready = (
	$hasPositiveRefundForPolicy
	|| !empty($object->taler_refund_last_at)
	|| !empty($object->taler_refund_last_reason)
	|| !empty($object->taler_refund_pending)
	|| (int) $object->taler_state >= 70
	|| $statusRaw === 'refunded'
	|| (($refundPolicy['remaining_total'] ?? null) !== null && (float) $refundPolicy['remaining_total'] <= 0.00000001)
);
$showRefundPolicyPanel = $isPaidOrBeyond && !$hasTalerRefundAlready;

$wireDetailsDiag = $decodeJsonText($object->wire_details_json ?? '');
$refundDetailsDiag = $decodeJsonText($object->taler_refund_details_json ?? '');

$wireDetailsDecoded = is_array($wireDetailsDiag['decoded'] ?? null) ? $wireDetailsDiag['decoded'] : array();
$wireDetailsList = is_array($wireDetailsDecoded['details'] ?? null) ? $wireDetailsDecoded['details'] : array();
$wirePrimaryDetail = (is_array($wireDetailsList[0] ?? null) ? $wireDetailsList[0] : array());

$effectiveWireWtid = trim((string) ($object->taler_wtid ?? ''));
if ($effectiveWireWtid === '') {
	$effectiveWireWtid = trim((string) ($wirePrimaryDetail['wtid'] ?? ''));
}

$effectiveWireExchangeUrl = trim((string) ($object->taler_exchange_url ?? ''));
if ($effectiveWireExchangeUrl === '') {
	$effectiveWireExchangeUrl = trim((string) ($wirePrimaryDetail['exchange_url'] ?? ''));
}

$wireConfirmed = null;
if (array_key_exists('confirmed', $wirePrimaryDetail)) {
	$wireConfirmed = !empty($wirePrimaryDetail['confirmed']);
}

$wireMetaSummary = array(
	'bank_line_from' => (int) ($wireDetailsDecoded['bank_line_from'] ?? 0),
	'bank_line_to' => (int) ($wireDetailsDecoded['bank_line_to'] ?? 0),
	'performed_now' => array_key_exists('performed_now', $wireDetailsDecoded) ? (string) ((int) !empty($wireDetailsDecoded['performed_now'])) : '',
	'wire_amount' => trim((string) ($wireDetailsDecoded['amount_str'] ?? '')),
	'wire_confirmed' => ($wireConfirmed === null ? '' : (string) ((int) $wireConfirmed)),
);
$wireAccountParts = array();
foreach (array('fk_bank_account', 'fk_bank_account_dest') as $wireAccountField) {
	$accountId = (int) ($object->{$wireAccountField} ?? 0);
	if ($accountId <= 0) {
		continue;
	}
	$accountObj = new Account($db);
	if ($accountObj->fetch($accountId) > 0) {
		$wireAccountParts[] = $accountObj->getNomUrl(1);
	} else {
		$wireAccountParts[] = '#'.$accountId;
	}
}
$wireAccountFlowText = $langs->trans('FlowNone');
if (count($wireAccountParts) === 2) {
	$wireAccountFlowText = $wireAccountParts[0].' &rarr; '.$wireAccountParts[1];
} elseif (count($wireAccountParts) === 1) {
	$wireAccountFlowText = $wireAccountParts[0];
}

$wireBankLineLinks = array();
if ($wireMetaSummary['bank_line_from'] > 0) {
	$wireLineId = (int) $wireMetaSummary['bank_line_from'];
	$wireBankLineLinks[] = '<a href="'.dol_escape_htmltag(dol_buildpath('/compta/bank/line.php', 1).'?rowid='.$wireLineId).'">#'.$wireLineId.'</a>';
}
if ($wireMetaSummary['bank_line_to'] > 0 && $wireMetaSummary['bank_line_to'] !== $wireMetaSummary['bank_line_from']) {
	$wireLineId = (int) $wireMetaSummary['bank_line_to'];
	$wireBankLineLinks[] = '<a href="'.dol_escape_htmltag(dol_buildpath('/compta/bank/line.php', 1).'?rowid='.$wireLineId).'">#'.$wireLineId.'</a>';
}
$wireBankLinesText = !empty($wireBankLineLinks) ? implode(' / ', $wireBankLineLinks) : $langs->trans('FlowNone');

$traceRows = array();
$traceRows[] = array('label' => $langs->trans('TalerInstance'), 'value' => dol_escape_htmltag((string) ($object->taler_instance ?? '')));
$traceRows[] = array('label' => $langs->trans('TalerOrderId'), 'value' => dol_escape_htmltag((string) ($object->taler_order_id ?? '')));
$traceRows[] = array('label' => $langs->trans('BackendState'), 'value' => dol_escape_htmltag((string) ($object->merchant_status_raw ?? '')));
$traceRows[] = array('label' => $langs->trans('LastStatusChk'), 'value' => !empty($object->last_status_check_at) ? dol_print_date($object->last_status_check_at, 'dayhour') : $langs->trans('None'));
$traceRows[] = array('label' => $langs->trans('IdempotencyKey'), 'value' => dol_escape_htmltag((string) ($object->idempotency_key ?? '')));
$traceRows[] = array('label' => 'WTID', 'value' => dol_escape_htmltag($effectiveWireWtid));
$traceRows[] = array('label' => 'Wire confirmed', 'value' => (
	$wireMetaSummary['wire_confirmed'] !== ''
		? dol_escape_htmltag(((int) $wireMetaSummary['wire_confirmed'] === 1 ? 'yes' : 'no'))
		: $langs->trans('None')
));
$traceRows[] = array('label' => 'Wire execution', 'value' => !empty($object->wire_execution_time) ? dol_print_date($object->wire_execution_time, 'dayhour') : $langs->trans('None'));
$traceRows[] = array('label' => 'Wire bank lines', 'value' => (
	(($wireMetaSummary['bank_line_from'] > 0) || ($wireMetaSummary['bank_line_to'] > 0))
		? dol_escape_htmltag(
			($wireMetaSummary['bank_line_from'] > 0 ? '#'.$wireMetaSummary['bank_line_from'] : '-')
			.' / '.
			($wireMetaSummary['bank_line_to'] > 0 ? '#'.$wireMetaSummary['bank_line_to'] : '-')
		)
		: $langs->trans('None')
) );
$traceRows[] = array('label' => 'Wire performed_now', 'value' => ($wireMetaSummary['performed_now'] !== '' ? dol_escape_htmltag($wireMetaSummary['performed_now']) : $langs->trans('None')));
$traceRows[] = array('label' => 'Wire amount', 'value' => ($wireMetaSummary['wire_amount'] !== '' ? dol_escape_htmltag($wireMetaSummary['wire_amount']) : $langs->trans('None')));

$traceUrlRows = array();
if (!empty($object->taler_status_url)) {
	$url = (string) $object->taler_status_url;
	$traceUrlRows[] = array(
		'label' => $langs->trans('TalerStatusURL'),
		'value' => '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($url).'</a>',
	);
}
if ($effectiveWireExchangeUrl !== '') {
	$url = $effectiveWireExchangeUrl;
	$traceUrlRows[] = array(
		'label' => $langs->trans('Exchange'),
		'value' => '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($url).'</a>',
	);
}
if (!empty($object->taler_pay_uri)) {
	$url = (string) $object->taler_pay_uri;
	$traceUrlRows[] = array(
		'label' => $langs->trans('TalerPayURI'),
		'value' => '<a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($url).'</a>',
	);
}

$logPatterns = array();
$talerOrderIdForLog = trim((string) ($object->taler_order_id ?? ''));
if ($talerOrderIdForLog !== '') {
	$logPatterns[] = '/orders/'.$talerOrderIdForLog;
	$logPatterns[] = 'GNU Taler wire '.$talerOrderIdForLog;
	$logPatterns[] = 'order '.$talerOrderIdForLog;
	$logPatterns[] = '"order_id":"'.$talerOrderIdForLog.'"';
	$logPatterns[] = '"order_id": "'.$talerOrderIdForLog.'"';
}
foreach (array(
	(string) ($object->commande_ref_snap ?? ''),
	(string) ($object->facture_ref_snap ?? ''),
	($commande ? (string) ($commande->ref ?? '') : ''),
	($facture ? (string) ($facture->ref ?? '') : ''),
	$effectiveWireWtid,
	(string) ($object->idempotency_key ?? ''),
) as $needle) {
	$needle = trim($needle);
	if ($needle === '') {
		continue;
	}
	if (strlen($needle) < 6 && preg_match('/^\d+$/', $needle)) {
		continue;
	}
	$logPatterns[] = $needle;
}
$logPatterns = array_values(array_unique($logPatterns));
$logExcerpt = $readLogExcerpt($resolveSyslogFilePath($conf), $logPatterns);

$title = $langs->trans("TalerOrderLink").' #'.((int) $object->id);

$morecss = array('/custom/talerbarr/css/talerbarr.css');
llxHeader('', $title, '', '', 0, 0, array(), $morecss, '', 'mod-talerbarr page-orderlink-card');

// Back link
if (!empty($back)) {
	print '<a class="button" href="'.dol_escape_htmltag($back).'">'.$langs->trans("Back").'</a>';
}

$stateLabels = array(
	10 => $langs->trans('Unpaid'),
	20 => $langs->trans('Claimed'),
	30 => $langs->trans('Paid'),
	40 => $langs->trans('Delivered'),
	50 => $langs->trans('Wired'),
	70 => $langs->trans('Refunded'),
	90 => $langs->trans('Expired'),
	91 => $langs->trans('Aborted'),
);
$talerStateLabel = $stateLabels[(int) $object->taler_state] ?? $langs->trans('Unknown');

$stateClass = 'flow-open';
$talerStateNum = (int) $object->taler_state;
if (in_array($talerStateNum, array(90, 91), true)) {
	$stateClass = 'flow-missing';
} elseif ($talerStateNum >= 30) {
	$stateClass = 'flow-ok';
} elseif ($talerStateNum > 0) {
	$stateClass = 'flow-warn';
}

$formatDiagAmount = static function (?float $amount, array $diag): string {
	if ($amount === null) return '-';
	$dec = isset($diag['decimals']) ? (int) $diag['decimals'] : 2;
	$dec = max(0, min(8, $dec));
	$prefix = !empty($diag['currency']) ? $diag['currency'].':' : '';
	return $prefix.number_format($amount, $dec, '.', '');
};

$taxWarnings = array();
if ($commande && !empty($commande->lines) && is_array($commande->lines)) {
	$seenProducts = array();
	foreach ($commande->lines as $line) {
		$pid = isset($line->fk_product) ? (int) $line->fk_product : 0;
		if ($pid <= 0 || isset($seenProducts[$pid])) continue;
		$seenProducts[$pid] = 1;

		$linkProd = new TalerProductLink($db);
		if ($linkProd->fetchByProductId($pid) <= 0) continue;
		if (strcasecmp((string) $linkProd->taler_instance, (string) $object->taler_instance) !== 0) continue;
		if (empty($linkProd->taler_taxes_json)) continue;

		$diag = TalerProductLink::buildVatDiagnosis($linkProd->taler_taxes_json, $linkProd->taler_amount_str, $db);
		if ($diag['taler_rate'] === null || $diag['matched_rate'] === null) continue;
		$delta = abs((float) $diag['taler_rate'] - (float) $diag['matched_rate']);
		if ($delta < 0.001) continue;

		$label = '';
		if (!empty($line->product_ref)) {
			$label = (string) $line->product_ref;
		} elseif (!empty($line->product_label)) {
			$label = (string) $line->product_label;
		} elseif (!empty($line->label)) {
			$label = (string) $line->label;
		} else {
			$label = $langs->trans('Product').' #'.$pid;
		}

		$warnText = $langs->trans(
			'TalerTaxWarningRateShort',
			round($diag['taler_rate'], 4),
			round($diag['matched_rate'], 3)
		);
		if ($diag['suggested_tax_amount'] !== null && $diag['taler_tax_amount'] !== null && $diag['taler_price_amount'] !== null) {
			$warnText .= ' '.$langs->trans(
				'TalerTaxWarningAdjustShort',
				$formatDiagAmount($diag['suggested_tax_amount'], $diag),
				$formatDiagAmount($diag['taler_tax_amount'], $diag),
				round($diag['matched_rate'], 3)
			);
		}

		$taxWarnings[] = array(
			'label' => $label,
			'message' => $warnText,
		);
	}
}

print load_fiche_titre($title, '', $object->picto);
print dol_get_fiche_head();

$hasTaxWarnings = !empty($taxWarnings);
if ($hasTaxWarnings) {
	print '<div class="taler-info-grid">';
	print '<div class="taler-info-card">';
	print '<h3><span class="fa fa-exclamation-triangle"></span>'.$langs->trans('TaxWarnings').'</h3>';
	print '<ul class="taler-info-list">';
	foreach ($taxWarnings as $warn) {
		print '<li><span class="taler-info-label">'.dol_escape_htmltag($warn['label']).'</span><span class="taler-info-value">'.dol_escape_htmltag($warn['message']).'</span></li>';
	}
	print '</ul>';
	print '</div>';
	print '</div>';
}

$refundPolicyMessage = $langs->trans((string) ($refundPolicy['message_key'] ?? 'TalerRefundBlockedUnknown'));
if (($refundPolicy['code'] ?? '') === 'blocked_deadline') {
	$refundPolicyMessage .= ' '.$langs->trans('TalerRefundDeadlineWas', $refundDeadlinePolicyLabel);
}

$refundPolicyPanelId = 'taler-refund-policy-panel-'.((int) $object->id);
$refundPolicyPanelHtml = '';
$refundPolicyToggleButtonHtml = '';
if ($showRefundPolicyPanel) {
	$refundPolicyToggleButtonHtml
		= sprintf(
			'<a href="#" class="butAction taler-refund-policy-toggle" role="button" data-target="%s" data-show-label="Refunds" data-hide-label="Hide refunds" aria-controls="%s" aria-expanded="false">Refunds</a>',
			dol_escape_htmltag($refundPolicyPanelId),
			dol_escape_htmltag($refundPolicyPanelId)
		);

	$refundPolicyPanelHtml .= '<div id="'.dol_escape_htmltag($refundPolicyPanelId).'" class="taler-refund-panel taler-refund-panel-toggleable '.(!empty($refundPolicy['eligible']) ? 'is-eligible' : 'is-blocked').'" hidden>';
	$refundPolicyPanelHtml .= '<div class="taler-refund-title"><span class="fa fa-undo"></span>'.$langs->trans('TalerRefundPolicyTitle').'</div>';
	$refundPolicyPanelHtml .= '<div class="taler-refund-meta">';
	$refundPolicyPanelHtml .= '<div><strong>'.$langs->trans('FlowDeadlines').':</strong> '.$refundDeadlinePolicyLabel.'</div>';
	$refundPolicyPanelHtml .= '<div><strong>'.$langs->trans('TalerRefundRemaining').':</strong> '.$refundRemainingLabel.'</div>';
	$refundPolicyPanelHtml .= '<div><strong>'.$langs->trans('State').':</strong> '.$talerStateLabel.'</div>';
	$refundPolicyPanelHtml .= '</div>';
	$refundPolicyPanelHtml .= '<div class="taler-refund-message">'.dol_escape_htmltag($refundPolicyMessage).'</div>';

	if (empty($refundPolicy['eligible'])) {
		$refundPolicyPanelHtml .= '<div class="taler-refund-fallback">'.$alternativeMethodHint.'</div>';
	}

	if (!empty($refundPolicy['eligible']) && !empty($permissiontowrite)) {
		$refundPolicyPanelHtml .= '<form method="POST" class="taler-refund-form" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
		$refundPolicyPanelHtml .= '<input type="hidden" name="token" value="'.newToken().'">';
		$refundPolicyPanelHtml .= '<input type="hidden" name="action" value="create_taler_refund">';
		$refundPolicyPanelHtml .= '<input type="hidden" name="id" value="'.((int) $object->id).'">';
		if (!empty($back)) {
			$refundPolicyPanelHtml .= '<input type="hidden" name="backtopage" value="'.dol_escape_htmltag($back).'">';
		}
		$refundPolicyPanelHtml .= '<div class="field-wrap">';
		$refundPolicyPanelHtml .= '<label for="refund_amount">'.$langs->trans('TalerRefundAmount').'</label>';
		$refundPolicyPanelHtml .= '<input type="text" id="refund_amount" name="refund_amount" value="'.dol_escape_htmltag($refundDefaultAmountInput).'" placeholder="0.00" required>';
		$refundPolicyPanelHtml .= '</div>';
		$refundPolicyPanelHtml .= '<div class="field-wrap">';
		$refundPolicyPanelHtml .= '<label for="refund_reason">'.$langs->trans('TalerRefundReason').'</label>';
		$refundPolicyPanelHtml .= '<input type="text" id="refund_reason" name="refund_reason" maxlength="255" required>';
		$refundPolicyPanelHtml .= '</div>';
		$refundPolicyPanelHtml .= '<div><input type="submit" class="button" value="'.$langs->trans('TalerRefundSubmit').'"></div>';
		$refundPolicyPanelHtml .= '</form>';
	} elseif (!empty($refundPolicy['eligible']) && empty($permissiontowrite)) {
		$refundPolicyPanelHtml .= '<div class="taler-refund-fallback">'.$langs->trans('TalerRefundNoPermission').'</div>';
	}
	$refundPolicyPanelHtml .= '</div>';
}

$flowPublicLinkLine = sprintf('%s: %s', $langs->trans('FlowLink'), $langs->trans('FlowMissing'));
if (!empty($object->taler_status_url)) {
	$flowPublicLinkLine = sprintf(
		'%s: <a href="%s" target="_blank" rel="noopener">%s</a>',
		$langs->trans('FlowLink'),
		dol_escape_htmltag((string) $object->taler_status_url),
		$langs->trans('TalerStatusURL')
	);
} elseif (!empty($object->taler_pay_uri)) {
	$flowPublicLinkLine = sprintf(
		'%s: <a href="%s">%s</a> <span class="opacitymedium">(status URL not stored)</span>',
		$langs->trans('FlowLink'),
		dol_escape_htmltag((string) $object->taler_pay_uri),
		$langs->trans('TalerPayURI')
	);
}

$flowSteps = array();
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeTalerOrder'),
	'icon'   => 'fa-shopping-cart',
	'status' => $stateClass,
	'badge'  => $talerStateLabel,
	'lines'  => array(
		$langs->trans('TalerInstance').': '.dol_escape_htmltag($object->taler_instance),
		$langs->trans('TalerOrderId').': '.dol_escape_htmltag($object->taler_order_id),
		$langs->trans('FlowAmount').': '.dol_escape_htmltag($object->order_amount_str ?: ($object->deposit_total_str ?: '-')),
		$langs->trans('FlowDeadlines').': '.(!empty($object->taler_pay_deadline) ? dol_print_date($object->taler_pay_deadline, 'dayhour') : $langs->trans('None')),
		$flowPublicLinkLine
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeCommande'),
	'icon'   => 'fa-file-alt',
	'status' => $commande ? 'flow-ok' : 'flow-open',
	'badge'  => $commande ? $langs->trans('FlowDone') : $langs->trans('FlowPending'),
	'lines'  => array(
		$langs->trans('FlowOrderRef').': '.($commande ? $commande->getNomUrl(1) : dol_escape_htmltag($object->commande_ref_snap ?: '-')),
		$langs->trans('Customer').': '.($societe ? $societe->getNomUrl(1) : dol_escape_htmltag($object->fk_soc ?: '-')),
		$langs->trans('FlowPaymentIntent').': '.dol_escape_htmltag($object->intended_payment_code ?: '-'),
		$langs->trans('Date').': '.(!empty($object->commande_datec) ? dol_print_date($object->commande_datec, 'dayhour') : $langs->trans('None')),
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeInvoice'),
	'icon'   => 'fa-file-invoice',
	'status' => $facture ? 'flow-ok' : 'flow-open',
	'badge'  => $facture ? $langs->trans('FlowDone') : $langs->trans('FlowPending'),
	'lines'  => array(
		$langs->trans('Invoice').': '.($facture ? $facture->getNomUrl(1) : dol_escape_htmltag($object->facture_ref_snap ?: '-')),
		$langs->trans('FlowPaymentTerms').': '.dol_escape_htmltag($object->fk_cond_reglement ?: '-'),
		$langs->trans('Date').': '.(!empty($object->facture_datef) ? dol_print_date($object->facture_datef, 'dayhour') : $langs->trans('None')),
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodePayment'),
	'icon'   => 'fa-credit-card',
	'status' => $paiement ? 'flow-ok' : 'flow-warn',
	'badge'  => $paiement ? $langs->trans('FlowDone') : $langs->trans('FlowAttention'),
	'lines'  => array(
		$langs->trans('FlowPaymentStatus').': '.($paiement ? $paiement->getNomUrl(1) : $langs->trans('FlowPending')),
		$langs->trans('Date').': '.(!empty($object->paiement_datep) ? dol_print_date($object->paiement_datep, 'dayhour') : $langs->trans('None')),
	),
);

$refundTotalFloat = TalerOrderLink::amountStringToFloat((string) ($object->taler_refunded_total ?? ''));
$refundTakenFloat = TalerOrderLink::amountStringToFloat((string) ($object->taler_refund_taken_total ?? ''));
$hasPositiveRefundTotal = ($refundTotalFloat !== null && $refundTotalFloat > 0.00000001);
$hasPositiveRefundTaken = ($refundTakenFloat !== null && $refundTakenFloat > 0.00000001);
$hasRefundData = $hasPositiveRefundTotal
	|| $hasPositiveRefundTaken
	|| !empty($object->taler_refund_pending)
	|| !empty($object->taler_refund_last_reason)
	|| !empty($object->taler_refund_last_at);
$hasWireExecutionEvidence = ($effectiveWireWtid !== '') || !empty($object->wire_execution_time) || !empty($wirePrimaryDetail);
$hasWireData   = !empty($object->taler_wired) || $hasWireExecutionEvidence;
$isRefundPending = !empty($object->taler_refund_pending);
$isRefundedState = ((int) $object->taler_state === 70) || strcasecmp((string) $object->merchant_status_raw, 'refunded') === 0;
$isRefundWindowOpen = !empty($refundPolicy['eligible']) && $isPaidOrBeyond && !$hasTalerRefundAlready;
$refundRemainingAmount = $refundPolicy['remaining_total'] ?? null;
$refundRemainingZero = ($refundRemainingAmount !== null && (float) $refundRemainingAmount <= 0.00000001);
$hasRefundProgress = $hasPositiveRefundTotal
	|| $hasPositiveRefundTaken
	|| !empty($object->taler_refund_last_at)
	|| !empty($object->taler_refund_last_reason)
	|| $isRefundedState;
$isFullyRefunded = $isRefundedState || ($hasRefundProgress && $refundRemainingZero);
$isPartiallyRefunded = $hasRefundProgress && !$isFullyRefunded;

$refundStatusClass = 'flow-open';
$refundBadge = 'N/A';
$refundStatusText = 'Not applicable';
if ($isRefundPending) {
	$refundStatusClass = 'flow-info';
	$refundBadge = 'Processing';
	$refundStatusText = 'Refund in progress';
} elseif ($isFullyRefunded) {
	$refundStatusClass = 'flow-ok';
	$refundBadge = $langs->trans('FlowDone');
	$refundStatusText = 'Fully refunded';
} elseif ($isPartiallyRefunded) {
	$refundStatusClass = 'flow-info';
	$refundBadge = 'Partial';
	$refundStatusText = 'Partially refunded';
} elseif ($isRefundWindowOpen) {
	$refundStatusClass = 'flow-info';
	$refundBadge = 'Available';
	$refundStatusText = 'Available to refund';
} elseif ($hasRefundData) {
	$refundStatusClass = 'flow-info';
	$refundBadge = 'Info';
	$refundStatusText = 'Refund activity recorded';
}
$refundTotalText = $hasPositiveRefundTotal ? dol_escape_htmltag((string) $object->taler_refunded_total) : $langs->trans('FlowNone');
$refundTakenText = $hasPositiveRefundTaken ? dol_escape_htmltag((string) $object->taler_refund_taken_total) : $langs->trans('FlowNone');
$refundDeadlineText = !empty($object->taler_refund_deadline) ? dol_print_date($object->taler_refund_deadline, 'dayhour') : $langs->trans('FlowNone');
$refundLastAtText = !empty($object->taler_refund_last_at) ? dol_print_date($object->taler_refund_last_at, 'dayhour') : $langs->trans('FlowNone');
$refundLastReasonText = !empty($object->taler_refund_last_reason) ? dol_escape_htmltag((string) $object->taler_refund_last_reason) : $langs->trans('FlowNone');
$refundCreditNoteText = $creditNote ? $creditNote->getNomUrl(1) : $langs->trans('FlowNone');
$refundFlowLines = array(
	$langs->trans('FlowRefundStatus').': '.$refundStatusText,
	'Refund total: '.$refundTotalText,
	'Credit note: '.$refundCreditNoteText,
	$langs->trans('RefundTaken').': '.$refundTakenText,
	$langs->trans('Date').': '.$refundLastAtText,
	$langs->trans('Reason').': '.$refundLastReasonText,
	$langs->trans('FlowDeadlines').': '.$refundDeadlineText,
);
if ($refundPolicyToggleButtonHtml !== '') {
	$refundFlowLines[] = '<span class="taler-flow-action-row">'.$refundPolicyToggleButtonHtml.'</span>';
}

$wireStatusClass = 'flow-open';
$wireBadge = 'N/A';
$wireStatusText = 'Not applicable';
if ($isRefundPending) {
	$wireStatusClass = 'flow-info';
	$wireBadge = 'Waiting';
	$wireStatusText = 'Refund in progress';
} elseif ($isFullyRefunded && !$hasWireExecutionEvidence) {
	$wireStatusClass = 'flow-info';
	$wireBadge = 'N/A';
	$wireStatusText = 'No settlement due (fully refunded)';
} elseif ($hasWireExecutionEvidence) {
	$wireStatusClass = 'flow-ok';
	$wireBadge = $isPartiallyRefunded ? 'Partial' : 'Settled';
	$wireStatusText = $isPartiallyRefunded ? 'Partially settled' : 'Settled';
} elseif ($hasWireData || (int) $object->taler_state >= 30) {
	$wireStatusClass = 'flow-info';
	$wireBadge = 'Waiting';
	$wireStatusText = 'Waiting settlement window';
}
$wireExecutionText = !empty($object->wire_execution_time) ? dol_print_date($object->wire_execution_time, 'dayhour') : $langs->trans('FlowNone');
$wireIdText = ($effectiveWireWtid !== '') ? dol_escape_htmltag($effectiveWireWtid) : $langs->trans('FlowNone');
$wireExchangeText = ($effectiveWireExchangeUrl !== '')
	? '<a href="'.dol_escape_htmltag($effectiveWireExchangeUrl).'" target="_blank" rel="noopener">'.dol_escape_htmltag($effectiveWireExchangeUrl).'</a>'
	: $langs->trans('FlowNone');
$wireConfirmationText = $langs->trans('FlowNone');
if ($wireConfirmed === true) {
	$wireConfirmationText = '<span class="fa fa-check-circle opacitymedium"></span> confirmed';
} elseif ($wireConfirmed === false) {
	$wireConfirmationText = '<span class="fa fa-exclamation-triangle opacitymedium"></span> confirmation pending on Taler exchange';
}

$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeRefund'),
	'icon'   => 'fa-undo',
	'status' => $refundStatusClass,
	'badge'  => $refundBadge,
	'lines'  => $refundFlowLines,
);

$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeWire'),
	'icon'   => 'fa-exchange-alt',
	'status' => $wireStatusClass,
	'badge'  => $wireBadge,
	'lines'  => array(
		$langs->trans('FlowWireStatus').': '.$wireStatusText,
		'Accounts: '.$wireAccountFlowText,
		'Transfer lines: '.$wireBankLinesText,
		'Settlement date: '.$wireExecutionText,
		$langs->trans('WTID').': '.$wireIdText,
		$langs->trans('Exchange').': '.$wireExchangeText,
		'Exchange confirmation: '.$wireConfirmationText,
	),
);

print '<div class="taler-flow-wrap">';
print '<div class="taler-flow-heading"><span class="fa fa-sitemap"></span>'.$langs->trans('OrderFlow').'</div>';
// Group steps into rows: base flow, then refund + wire row
$flowRows = array(
	array($flowSteps[0], $flowSteps[1]),
	array($flowSteps[2], $flowSteps[3]),
	array($flowSteps[4], $flowSteps[5]),
);
foreach ($flowRows as $row) {
	print '<div class="taler-flow-row">';
	foreach ($row as $step) {
		print '<div class="taler-flow-step '.dol_escape_htmltag($step['status']).'">';
		print '<div class="taler-flow-top">';
		print '<div class="taler-flow-title"><span class="fa '.dol_escape_htmltag($step['icon']).' taler-flow-icon"></span><span class="taler-flow-label">'.dol_escape_htmltag($step['label']).'</span></div>';
		print '<span class="taler-flow-badge">'.dol_escape_htmltag($step['badge']).'</span>';
		print '</div>';
		print '<div class="taler-flow-lines">';
		foreach ($step['lines'] as $line) {
			print '<div>'.$line.'</div>';
		}
		print '</div>';
		print '</div>';
	}
	print '</div>';
}
print '</div>';
if ($refundPolicyPanelHtml !== '') {
	print $refundPolicyPanelHtml;
	print '<script>
	(function() {
		var buttons = document.querySelectorAll(".taler-refund-policy-toggle");
		if (!buttons || !buttons.length) return;
		buttons.forEach(function(btn) {
			btn.addEventListener("click", function(evt) {
				if (evt && typeof evt.preventDefault === "function") evt.preventDefault();
				var targetId = btn.getAttribute("data-target") || "";
				if (!targetId) return;
				var panel = document.getElementById(targetId);
				if (!panel) return;
				var showLabel = btn.getAttribute("data-show-label") || "Refunds";
				var hideLabel = btn.getAttribute("data-hide-label") || "Hide refunds";
				var willOpen = panel.hasAttribute("hidden");
				if (willOpen) {
					panel.removeAttribute("hidden");
					btn.setAttribute("aria-expanded", "true");
					btn.textContent = hideLabel;
				} else {
					panel.setAttribute("hidden", "hidden");
					btn.setAttribute("aria-expanded", "false");
					btn.textContent = showLabel;
				}
			});
		});
	})();
	</script>';
}

$hasTraceUrls = !empty($traceUrlRows);
$hasWireDiagJson = !empty($wireDetailsDiag['present']);
$hasRefundDiagJson = !empty($refundDetailsDiag['present']);
$hasLogLines = !empty($logExcerpt['lines']);

print '<details class="taler-collapsible">';
print '<summary class="taler-section-title taler-collapsible-summary">Requests / Logs</summary>';
print '<div class="taler-collapsible-body">';
print '<div class="taler-info-grid">';

print '<div class="taler-info-card taler-debug-card">';
print '<h3><span class="fa fa-link"></span>Request / Status metadata</h3>';
print '<ul class="taler-info-list">';
foreach ($traceRows as $traceRow) {
	$valueHtml = trim((string) ($traceRow['value'] ?? ''));
	if ($valueHtml === '') {
		$valueHtml = $langs->trans('None');
	}
	print '<li><span class="taler-info-label">'.dol_escape_htmltag((string) ($traceRow['label'] ?? '')).'</span><span class="taler-info-value">'.$valueHtml.'</span></li>';
}
print '</ul>';
if ($hasTraceUrls) {
	print '<div class="taler-debug-subtitle">Endpoints</div>';
	print '<ul class="taler-info-list">';
	foreach ($traceUrlRows as $traceRow) {
		print '<li><span class="taler-info-label">'.dol_escape_htmltag((string) ($traceRow['label'] ?? '')).'</span><span class="taler-info-value taler-debug-url">'.$traceRow['value'].'</span></li>';
	}
	print '</ul>';
}
print '</div>';

print '<div class="taler-info-card taler-debug-card">';
print '<h3><span class="fa fa-file-code"></span>Stored request payloads</h3>';
if (!$hasWireDiagJson && !$hasRefundDiagJson) {
	print '<div class="taler-debug-meta">'.$langs->trans('None').'</div>';
}
if ($hasWireDiagJson) {
	print '<details class="taler-collapsible taler-debug-inner-collapse">';
	print '<summary class="taler-collapsible-summary">wire_details_json</summary>';
	print '<div class="taler-collapsible-body">';
	if (empty($wireDetailsDiag['valid']) && !empty($wireDetailsDiag['error'])) {
		print '<div class="taler-debug-meta error">JSON parse error: '.dol_escape_htmltag((string) $wireDetailsDiag['error']).'</div>';
	}
	print '<pre class="taler-debug-pre">'.dol_escape_htmltag((string) ($wireDetailsDiag['pretty'] ?? '')).'</pre>';
	print '</div>';
	print '</details>';
}
if ($hasRefundDiagJson) {
	print '<details class="taler-collapsible taler-debug-inner-collapse">';
	print '<summary class="taler-collapsible-summary">taler_refund_details_json</summary>';
	print '<div class="taler-collapsible-body">';
	if (empty($refundDetailsDiag['valid']) && !empty($refundDetailsDiag['error'])) {
		print '<div class="taler-debug-meta error">JSON parse error: '.dol_escape_htmltag((string) $refundDetailsDiag['error']).'</div>';
	}
	print '<pre class="taler-debug-pre">'.dol_escape_htmltag((string) ($refundDetailsDiag['pretty'] ?? '')).'</pre>';
	print '</div>';
	print '</details>';
}
print '</div>';

print '<div class="taler-info-card taler-debug-card">';
print '<h3><span class="fa fa-clipboard-list"></span>Dolibarr log excerpt</h3>';
print '<div class="taler-debug-meta">Source: <code>'.dol_escape_htmltag((string) ($logExcerpt['path'] ?? '')).'</code></div>';
if (!$logExcerpt['readable']) {
	print '<div class="taler-debug-meta error">'.dol_escape_htmltag((string) ($logExcerpt['error'] ?? 'Log file not readable')).'</div>';
} elseif (!$hasLogLines) {
	print '<div class="taler-debug-meta">No matching lines found in recent log tail.</div>';
} else {
	if (!empty($logExcerpt['truncated'])) {
		print '<div class="taler-debug-meta">Showing filtered matches from the recent tail of the log.</div>';
	}
	print '<pre class="taler-debug-pre">'.dol_escape_htmltag(implode("\n", (array) $logExcerpt['lines'])).'</pre>';
}
print '</div>';

print '</div>';
print '</div>';
print '</details>';

$orderedFields = dol_sort_array($object->fields, 'position');
print '<details class="taler-collapsible">';
print '<summary class="taler-section-title taler-collapsible-summary">'.$langs->trans('RawData').'</summary>';
print '<div class="taler-collapsible-body">';
print '<table class="border centpercent tableforfield">'."\n";
foreach ($orderedFields as $key => $val) {
	print '<tr>';
	print '<td class="titlefield">'.$langs->trans($val['label']).'</td>';
	$value = isset($object->{$key}) ? $object->{$key} : '';
	print '<td class="valuefield">'.$object->showOutputField($val, $key, $value, '').'</td>';
	print '</tr>';
}
// Extrafields
if (!empty($extrafields->attributes[$object->table_element]['label'])) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $label) {
		print '<tr>';
		print '<td class="titlefield">'.dol_escape_htmltag($label).'</td>';
		$val = isset($object->array_options['options_'.$key]) ? $object->array_options['options_'.$key] : '';
		print '<td class="valuefield">'.$extrafields->showOutputField($key, $val, '', $object->table_element).'</td>';
		print '</tr>';
	}
}
print '</table>';
print '</div>';
print '</details>';

print '<div class="clearboth"></div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
