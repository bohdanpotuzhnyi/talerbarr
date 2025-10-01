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
 * \file class/talerorderlink.class.php
 * \ingroup talerbarr
 * \brief CRUD + helpers with sync logic
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';


dol_include_once('/talerbarr/class/talerconfig.class.php');
dol_include_once('/talerbarr/class/talerproductlink.class.php');
dol_include_once('/talerbarr/class/talermerchantclient.class.php');

/**
 * Class TalerOrderLink
 *
 * Maps one Taler order (API) to its Dolibarr artefacts (Commande, Facture, Paiement).
 *
 * @package    TalerBarr
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License v3 or later
 */
class TalerOrderLink extends CommonObject
{
	/** @var string */
	public $module = 'talerbarr';
	/** @var string */
	public $element = 'talerorderlink';
	/** @var string */
	public $table_element = 'talerbarr_order_link';
	/** @var string */
	public $picto = 'fa-file-invoice-dollar';

	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged  = 0;

	/* -------------------------------------------------- */
	/*                 Fields definition                  */
	/* -------------------------------------------------- */
	public $fields = array(
		'rowid'         => array('type' => 'integer', 'label' => 'TechnicalID', 'visible' => 0, 'notnull' => 1, 'index' => 1, 'position' => 1),
		'entity'        => array('type' => 'integer', 'label' => 'Entity',       'visible' => 0, 'notnull' => 1, 'default' => 1, 'index' => 1, 'position' => 5),

		// Taler identities
		'taler_instance'        => array('type' => 'varchar(64)',  'label' => 'TalerInstance',    'visible' => 1, 'notnull' => 1, 'index' => 1, 'position' => 10),
		'taler_order_id'        => array('type' => 'varchar(128)', 'label' => 'TalerOrderId',     'visible' => 1, 'notnull' => 1, 'index' => 1, 'position' => 11),
		'taler_session_id'      => array('type' => 'varchar(128)', 'label' => 'TalerSessionId',   'visible' => 0, 'notnull' => 0, 'position' => 12),
		'taler_pay_uri'         => array('type' => 'varchar(255)', 'label' => 'TalerPayURI',      'visible' => 1, 'notnull' => 0, 'position' => 13),
		'taler_status_url'      => array('type' => 'varchar(255)', 'label' => 'TalerStatusURL',   'visible' => 1, 'notnull' => 0, 'position' => 14),
		'taler_refund_deadline' => array('type' => 'datetime',     'label' => 'RefundDeadline',   'visible' => 0, 'notnull' => 0, 'position' => 15),
		'taler_pay_deadline'    => array('type' => 'datetime',     'label' => 'PayDeadline',      'visible' => 0, 'notnull' => 0, 'position' => 16),

		// Snapshot amount & summary
		'order_summary'     => array('type' => 'varchar(255)', 'label' => 'Summary',          'visible' => 1, 'notnull' => 0, 'position' => 20),
		'order_amount_str'  => array('type' => 'varchar(64)',  'label' => 'AmountStr',        'visible' => 1, 'notnull' => 0, 'position' => 21),
		'order_currency'    => array('type' => 'varchar(16)',  'label' => 'Currency',         'visible' => 0, 'notnull' => 0, 'position' => 22),
		'order_value'       => array('type' => 'integer',      'label' => 'MajorUnits',       'visible' => 0, 'notnull' => 0, 'position' => 23),
		'order_fraction'    => array('type' => 'integer',      'label' => 'Fraction1e8',      'visible' => 0, 'notnull' => 0, 'position' => 24),
		'deposit_total_str' => array('type' => 'varchar(64)',  'label' => 'DepositTotalStr',  'visible' => 0, 'notnull' => 0, 'position' => 25),

		// Dolibarr party
		'fk_soc'            => array('type' => 'integer:Societe:societe/class/societe.class.php', 'label' => 'Customer', 'visible' => 1, 'notnull' => 0, 'position' => 30, 'index' => 1),
		'order_ref_planned' => array('type' => 'varchar(64)', 'label' => 'OrderRefPlanned', 'visible' => 0, 'notnull' => 0, 'position' => 31),

		// Commande
		'fk_commande'           => array('type' => 'integer:Commande:commande/class/commande.class.php', 'label' => 'Commande', 'visible' => 1, 'notnull' => 0, 'position' => 40, 'index' => 1),
		'commande_ref_snap'     => array('type' => 'varchar(64)',  'label' => 'CommandeRef',         'visible' => 0, 'notnull' => 0, 'position' => 41),
		'commande_datec'        => array('type' => 'datetime',     'label' => 'CommandeDateC',       'visible' => 0, 'notnull' => 0, 'position' => 42),
		'commande_validated_at' => array('type' => 'datetime',     'label' => 'CommandeValidated',   'visible' => 0, 'notnull' => 0, 'position' => 43),
		'intended_payment_code' => array('type' => 'varchar(32)',  'label' => 'IntendedPayCode',     'visible' => 0, 'notnull' => 0, 'position' => 44),

		// Facture
		'fk_facture'           => array('type' => 'integer:Facture:compta/facture/class/facture.class.php', 'label' => 'Facture', 'visible' => 1, 'notnull' => 0, 'position' => 50, 'index' => 1),
		'facture_ref_snap'     => array('type' => 'varchar(64)', 'label' => 'FactureRef',        'visible' => 0, 'notnull' => 0, 'position' => 51),
		'facture_datef'        => array('type' => 'datetime',    'label' => 'FactureDate',       'visible' => 0, 'notnull' => 0, 'position' => 52),
		'fk_cond_reglement'    => array('type' => 'integer',     'label' => 'PaymentTerms',      'visible' => 0, 'notnull' => 0, 'position' => 53),
		'facture_validated_at' => array('type' => 'datetime',    'label' => 'FactureValidated',  'visible' => 0, 'notnull' => 0, 'position' => 54),

		// Payment & Bank
		'fk_paiement'       => array('type' => 'integer:Paiement:paiement/class/paiement.class.php', 'label' => 'CustomerPayment', 'visible' => 1, 'notnull' => 0, 'position' => 60, 'index' => 1),
		'paiement_datep'    => array('type' => 'datetime', 'label' => 'PaymentDate',   'visible' => 0, 'notnull' => 0, 'position' => 61),
		'fk_c_paiement'     => array('type' => 'integer',  'label' => 'ModeID',       'visible' => 0, 'notnull' => 0, 'position' => 62),
		'fk_bank'           => array('type' => 'integer',  'label' => 'BankLine',     'visible' => 0, 'notnull' => 0, 'position' => 63),
		'fk_bank_account'   => array('type' => 'integer',  'label' => 'BankAccount',  'visible' => 0, 'notnull' => 0, 'position' => 64),
		'fk_bank_account_dest' => array('type' => 'integer', 'label' => 'DestBankAccount', 'visible' => 0, 'notnull' => 0, 'position' => 65),

		// Wire settlement
		'taler_wired'         => array('type' => 'boolean',      'label' => 'Wired',          'visible' => 1, 'notnull' => 1, 'default' => 0, 'position' => 70),
		'taler_wtid'          => array('type' => 'varchar(64)',  'label' => 'WTID',           'visible' => 0, 'notnull' => 0, 'position' => 71),
		'taler_exchange_url'  => array('type' => 'varchar(255)', 'label' => 'ExchangeURL',    'visible' => 0, 'notnull' => 0, 'position' => 72),
		'wire_execution_time' => array('type' => 'datetime',     'label' => 'WireExecTime',   'visible' => 0, 'notnull' => 0, 'position' => 73),
		'wire_details_json'   => array('type' => 'text',         'label' => 'WireDetails',    'visible' => -1, 'notnull' => 0, 'position' => 74),

		// State machine
		'taler_state'          => array('type' => 'integer',  'label' => 'State',          'visible' => 1, 'notnull' => 0, 'position' => 80),
		'merchant_status_raw'  => array('type' => 'varchar(64)', 'label' => 'BackendState', 'visible' => 0, 'notnull' => 0, 'position' => 86),
		'taler_claimed_at'     => array('type' => 'datetime', 'label' => 'ClaimedAt',      'visible' => 0, 'notnull' => 0, 'position' => 81),
		'taler_paid_at'        => array('type' => 'datetime', 'label' => 'PaidAt',         'visible' => 0, 'notnull' => 0, 'position' => 82),
		'taler_refunded_total' => array('type' => 'varchar(64)', 'label' => 'RefundedTotal', 'visible' => 0, 'notnull' => 0, 'position' => 83),
		'taler_refund_pending' => array('type' => 'boolean', 'label' => 'RefundPending',  'visible' => 0, 'notnull' => 1, 'default' => 0, 'position' => 84),
		'last_status_check_at' => array('type' => 'datetime', 'label' => 'LastStatusChk', 'visible' => 0, 'notnull' => 0, 'position' => 87),

		// Meta
		'idempotency_key' => array('type' => 'varchar(128)', 'label' => 'IdempotencyKey', 'visible' => 0, 'notnull' => 0, 'position' => 90),
		'datec'           => array('type' => 'datetime',     'label' => 'DateCreation',  'visible' => -2, 'notnull' => 0, 'position' => 500),
		'tms'             => array('type' => 'timestamp',    'label' => 'DateModification', 'visible' => -2, 'notnull' => 1, 'position' => 501),
	);

	// We rely on magic __get/__set in CommonObject; declare few common ones for IDE
	public $rowid;
	public $entity;
	public $taler_instance;
	public $taler_order_id;
	public $fk_commande;
	public $fk_facture;
	public $fk_bank_account_dest;
	public $merchant_status_raw;

	/**
	 * Fetch link row using (instance, order_id).
	 *
	 * @param string $instance Instance identifier as configured in TalerConfig::username.
	 * @param string $orderId  Taler order identifier.
	 * @return int             >0 if found, 0 if not found, <0 on error.
	 */
	public function fetchByInstanceOrderId(string $instance, string $orderId): int
	{
		$this->log('fetchByInstanceOrderId.begin', ['instance' => $instance, 'order_id' => $orderId]);
		$sql = sprintf(
			"SELECT rowid FROM %s%s WHERE taler_instance = '%s' AND taler_order_id = '%s' AND entity IN (%s)",
			MAIN_DB_PREFIX,
			$this->table_element,
			$this->db->escape($instance),
			$this->db->escape($orderId),
			getEntity($this->element, true)
		);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->log('fetchByInstanceOrderId.sql_error', ['error' => $this->error], LOG_ERR);
			return -1;
		}
		if ($obj = $this->db->fetch_object($resql)) {
			$r = $this->fetch((int) $obj->rowid);
			$this->db->free($resql);
			$this->log('fetchByInstanceOrderId.end', ['rowid' => (int) $this->id, 'res' => $r]);
			return $r;
		}
		$this->db->free($resql);
		$this->log('fetchByInstanceOrderId.end', ['rowid' => null, 'res' => 0]);
		return 0;
	}

	/**
	 * Convert mixed (array|object) payload into associative array.
	 *
	 * @param object|array|null $value Mixed payload to normalize.
	 * @return array<string, mixed>    Normalized associative array.
	 */
	private static function normalizeToArray(object|array|null $value): array
	{
		if (is_array($value)) {
			return $value;
		}
		if (is_object($value)) {
			return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: [];
		}
		return [];
	}

	/**
	 * Ensure the original order carries a public note with an accessible Taler link.
	 *
	 * @param Commande $order   Order to update.
	 * @param string   $linkUrl Public status (or payment) URL to surface.
	 * @return void
	 */
	private static function ensurePublicNoteHasPayLink(Commande $order, string $linkUrl): void
	{
		$linkUrl = trim($linkUrl);
		if ($linkUrl === '' || empty($order->id)) {
			return;
		}

		$existingNote = (string) ($order->note_public ?? '');
		$escapedUrl = dol_escape_htmltag($linkUrl);
		$noteLine = 'Taler payment status: <a href="' . $escapedUrl . '">' . $escapedUrl . '</a>';
		$linePattern = '/^Taler payment (?:link|status):.*$/mi';

		if (preg_match($linePattern, $existingNote)) {
			if (preg_match($linePattern, $existingNote, $matches)) {
				$matchedLine = trim((string) ($matches[0] ?? ''));
				if ($matchedLine === $noteLine) {
					return;
				}
			}
			$updatedNote = (string) preg_replace($linePattern, $noteLine, $existingNote);
		} else {
			$decodedNote = html_entity_decode($existingNote, ENT_QUOTES | ENT_HTML5);
			if (strpos($existingNote, $linkUrl) !== false || strpos($existingNote, $escapedUrl) !== false || strpos($decodedNote, $linkUrl) !== false) {
				return;
			}
			$updatedNote = rtrim($existingNote);
			if ($updatedNote !== '') {
				$updatedNote .= "\n";
			}
			$updatedNote .= $noteLine;
		}

		if ($updatedNote === $existingNote) {
			return;
		}

		$updateRes = $order->update_note_public($updatedNote);
		if ($updateRes <= 0) {
			dol_syslog('TalerOrderLink::ensurePublicNoteHasPayLink failed to update note: ' . ($order->error ?: 'unknown error'), LOG_WARNING);
		}
	}

	/**
	 * Synchronize invoice snapshot fields on the link object using the provided invoice.
	 *
	 * @param DoliDB         $db      Database connection for date formatting.
	 * @param TalerOrderLink $link    Link being hydrated.
	 * @param Facture        $invoice Invoice used as source of truth.
	 * @return void
	 */
	private static function hydrateInvoiceSnapshotFromFacture(DoliDB $db, TalerOrderLink $link, Facture $invoice): void
	{
		if (empty($invoice->id)) {
			return;
		}

		$link->fk_facture = (int) $invoice->id;
		if (!empty($invoice->ref)) {
			$link->facture_ref_snap = $invoice->ref;
		}
		if (!empty($invoice->date)) {
			$link->facture_datef = $db->idate($invoice->date);
		}
		if (!empty($invoice->date_validation)) {
			$link->facture_validated_at = $db->idate($invoice->date_validation);
		} elseif (!empty($invoice->date_valid)) {
			$link->facture_validated_at = $db->idate($invoice->date_valid);
		}
		if (!empty($invoice->cond_reglement_id)) {
			$link->fk_cond_reglement = (int) $invoice->cond_reglement_id;
		}
	}

	/**
	 * Locate an existing Dolibarr invoice already linked to the provided order.
	 *
	 * @param DoliDB  $db    Database connector.
	 * @param Commande $order Order whose invoice link we are searching for.
	 * @return Facture|null  First matching invoice (validated preferred), null if none.
	 */
	private static function findInvoiceLinkedToOrder(DoliDB $db, Commande $order): ?Facture
	{
		$orderId = (int) ($order->id ?? 0);
		if ($orderId <= 0) {
			return null;
		}

		$candidateIds = array();
		$sql = sprintf(
			"SELECT fk_target AS facture_id FROM %selement_element WHERE fk_source = %d AND sourcetype = 'commande' AND targettype = 'facture' ORDER BY rowid DESC",
			$db->prefix(),
			$orderId
		);
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$candidateIds[] = (int) $obj->facture_id;
			}
			$db->free($resql);
		} else {
			dol_syslog(__METHOD__.' failed to fetch facture links: '.$db->lasterror(), LOG_WARNING);
		}

		$sqlReverse = sprintf(
			"SELECT fk_source AS facture_id FROM %selement_element WHERE fk_target = %d AND targettype = 'commande' AND sourcetype = 'facture' ORDER BY rowid DESC",
			$db->prefix(),
			$orderId
		);
		$resReverse = $db->query($sqlReverse);
		if ($resReverse) {
			while ($obj = $db->fetch_object($resReverse)) {
				$candidateIds[] = (int) $obj->facture_id;
			}
			$db->free($resReverse);
		} else {
			dol_syslog(__METHOD__.' failed to fetch reverse facture links: '.$db->lasterror(), LOG_WARNING);
		}

		if (empty($candidateIds)) {
			dol_syslog(__METHOD__.' no linked invoices found for order '.$orderId, LOG_DEBUG);
			return null;
		}

		$candidateIds = array_unique(array_filter($candidateIds));
		dol_syslog(__METHOD__.' found candidate invoice ids '.implode(',', $candidateIds).' for order '.$orderId, LOG_DEBUG);
		$fallbackInvoice = null;
		foreach ($candidateIds as $invoiceId) {
			$invoice = new Facture($db);
			if ($invoice->fetch($invoiceId) <= 0) {
				dol_syslog(__METHOD__.' failed to fetch invoice '.$invoiceId.' for order '.$orderId, LOG_DEBUG);
				continue;
			}
			if ($invoice->status >= Facture::STATUS_VALIDATED) {
				return $invoice;
			}
			if ($fallbackInvoice === null) {
				$fallbackInvoice = $invoice;
			}
		}
		dol_syslog(__METHOD__.' returning fallback invoice id '.(int) ($fallbackInvoice?->id ?? 0).' for order '.$orderId, LOG_DEBUG);

		return $fallbackInvoice;
	}

	/**
	 * Make sure the invoice/order linkage is present so the relationship is visible from the UI.
	 *
	 * @param DoliDB  $db      Database connection.
	 * @param Facture $invoice Invoice to link.
	 * @param Commande $order  Order to link to.
	 * @return void
	 */
	private static function ensureInvoiceVisibleOnOrder(DoliDB $db, Facture $invoice, Commande $order): void
	{
		$invoiceId = (int) ($invoice->id ?? 0);
		$orderId = (int) ($order->id ?? 0);
		if ($invoiceId <= 0 || $orderId <= 0) {
			return;
		}

		$sql = sprintf(
			"SELECT rowid FROM %selement_element WHERE fk_source = %d AND sourcetype = 'commande' AND fk_target = %d AND targettype = 'facture' LIMIT 1",
			$db->prefix(),
			$orderId,
			$invoiceId
		);
		$resql = $db->query($sql);
		if ($resql) {
			$existing = $db->fetch_object($resql);
			$db->free($resql);
			if ($existing) {
				return;
			}
		} else {
			dol_syslog(__METHOD__.' failed to inspect link presence: '.$db->lasterror(), LOG_WARNING);
			return;
		}

		$resLink = $invoice->add_object_linked('commande', $orderId);
		if ($resLink <= 0) {
			dol_syslog(__METHOD__.' failed to create commande/facture link: ' . ($invoice->error ?: $db->lasterror()), LOG_WARNING);
		}
	}

	/**
	 * Helper returning the first non-empty scalar value.
	 *
	 * @param mixed ...$values Values to inspect in order.
	 * @return string          First non-empty scalar value (stringified) or empty string.
	 */
	private static function coalesceString(mixed ...$values): string
	{
		foreach ($values as $value) {
			if (is_string($value) && $value !== '') {
				return $value;
			}
			if ((is_int($value) || is_float($value)) && $value !== 0) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Parse various timestamp formats produced by the Taler backend.
	 *
	 * @param mixed $value Timestamp candidate from Taler payload.
	 * @return int|null    Unix timestamp when parseable, null otherwise.
	 */
	private static function parseTimestamp(mixed $value): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (is_numeric($value)) {
			$value = (int) $value;
			return $value > 0 ? $value : null;
		}
		if (is_array($value) && isset($value['t_s'])) {
			return self::parseTimestamp($value['t_s']);
		}
		try {
			$dt = new DateTime((string) $value);
			return $dt->getTimestamp();
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Parse a Taler amount representation into Dolibarr-friendly parts.
	 *
	 * @param mixed $amount Taler amount structure or string.
	 * @return array<string, int|string|null> Parsed amount components.
	 */
	private static function extractAmount(mixed $amount): array
	{
		if (is_string($amount)) {
			$parsed = TalerProductLink::parseTalerAmount($amount);
			$parsed['amount_str'] = $amount;
			return $parsed;
		}
		if (is_array($amount)) {
			if (isset($amount['amount'])) {
				return self::extractAmount($amount['amount']);
			}
			$currency = (string) ($amount['currency'] ?? '');
			$value    = isset($amount['value']) ? (int) $amount['value'] : null;
			$fraction = isset($amount['fraction']) ? (int) $amount['fraction'] : null;
			$amountStr = $currency;
			if ($currency !== '' && $value !== null) {
				$amountStr .= ':' . $value;
				if ($fraction !== null) {
					$amountStr .= '.' . str_pad((string) $fraction, 8, '0', STR_PAD_LEFT);
				}
			}
			return array(
				'currency'   => strtoupper($currency),
				'value'      => $value,
				'fraction'   => $fraction,
				'amount_str' => ($currency !== '' ? $amountStr : null),
			);
		}
		return array('currency' => '', 'value' => null, 'fraction' => null, 'amount_str' => null);
	}

	/**
	 * Convert parsed amount into float (major units, VAT excluded assumption).
	 *
	 * @param array<string, int|string|null> $parsed Parsed amount structure.
	 * @return float                              Monetary value in major units.
	 */
	private static function amountToFloat(array $parsed): float
	{
		$major = (float) ($parsed['value'] ?? 0);
		$fraction = (int) ($parsed['fraction'] ?? 0);
		if ($fraction !== 0) {
			$major += $fraction / 100000000;
		}
		return $major;
	}

	/**
	 * Format a decimal amount into Taler amount string (CUR:value[.fraction]).
	 *
	 * @param string $currency ISO currency code
	 * @param float  $amount   Decimal amount (major units)
	 * @return string          Formatted amount string accepted by Taler API
	 */
	private static function formatAmountString(string $currency, float $amount): string
	{
		global $conf;

		$cur = strtoupper($currency ?: ($conf->currency ?? 'EUR'));
		$negative = $amount < 0;
		$abs      = abs($amount);
		$value    = (int) floor($abs);
		$fraction = (int) round(($abs - $value) * 100000000);
		if ($fraction >= 100000000) {
			$value++;
			$fraction -= 100000000;
		}
		$body = $fraction > 0
			? sprintf('%s:%d.%08d', $cur, $value, $fraction)
			: sprintf('%s:%d', $cur, $value);
		return $negative ? '-' . $body : $body;
	}

	/**
	 * Normalize a candidate order identifier to Taler constraints.
	 *
	 * @param string $candidate Raw identifier.
	 * @return string           Sanitized identifier (may be empty).
	 */
	private static function sanitizeOrderIdCandidate(string $candidate): string
	{
		$candidate = trim($candidate);
		if ($candidate === '') {
			return '';
		}
		$candidate = preg_replace('~[^A-Za-z0-9._:-]+~', '-', $candidate);
		$candidate = trim($candidate, '-_.:');
		if ($candidate === '') {
			return '';
		}
		if (strlen($candidate) > 120) {
			$candidate = substr($candidate, 0, 120);
		}
		return $candidate;
	}

	/**
	 * Centralised logging helper for unexpected throwables.
	 *
	 * @param string    $context Describes the caller for easier troubleshooting.
	 * @param \Throwable $throwable Captured exception/error instance.
	 *
	 * @return void
	 */
	private static function logThrowable(string $context, \Throwable $throwable): void
	{
		dol_syslog(
			'TalerOrderLink::'.$context.' threw '.get_class($throwable)
			.': '.$throwable->getMessage().' at '.$throwable->getFile().':'.$throwable->getLine(),
			LOG_ERR
		);
		dol_syslog($throwable->getTraceAsString(), LOG_ERR);
	}

	/**
	 * Retrieve Taler configuration row by instance username.
	 *
	 * @param DoliDB $db       Active database handler.
	 * @param string $instance Taler instance identifier.
	 * @return TalerConfig|null Loaded configuration or null on failure.
	 */
	private static function fetchConfigForInstance(DoliDB $db, string $instance): ?TalerConfig
	{
		$cfg = new TalerConfig($db);
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$cfg->table_element.
			" WHERE username = '".$db->escape($instance)."'".
			' AND entity IN ('.getEntity('talerconfig', true).')';
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' sql_error '.$db->lasterror(), LOG_ERR);
			return null;
		}
		if ($obj = $db->fetch_object($resql)) {
			if ($cfg->fetch((int) $obj->rowid) > 0) {
				return $cfg;
			}
		}
		return null;
	}

	/**
	 * Resolve default thirdparty/customer identifier.
	 *
	 * @param TalerOrderLink $link Current order link context.
	 * @return int|null            Customer identifier or null if unavailable.
	 */
	private static function resolveCustomerId(TalerOrderLink $link): ?int
	{
		if (!empty($link->fk_soc)) {
			return (int) $link->fk_soc;
		}
		$global = (int) getDolGlobalInt('TALERBARR_DEFAULT_SOCID');
		return $global > 0 ? $global : null;
	}

	/**
	 * Resolve configured payment mode identifier for Taler payments.
	 *
	 * @return int|null Payment mode identifier or null when not configured.
	 */
	private static function resolvePaymentModeId(): ?int
	{
		$global = (int) getDolGlobalInt('TALERBARR_PAYMENT_MODE_ID');
		if ($global > 0) {
			return $global;
		}

		global $db, $conf;
		if (!isset($db) || !($db instanceof DoliDB)) {
			return null;
		}
		$entityId = 1;
		if (isset($conf) && is_object($conf) && !empty($conf->entity)) {
			$entityId = (int) $conf->entity;
		}

		$sql = 'SELECT id FROM '.MAIN_DB_PREFIX."c_paiement WHERE code = '".$db->escape('TLR')."'".
			' AND entity IN ('.getEntity('c_paiement', true).') ORDER BY active DESC, entity = '.$entityId.' DESC LIMIT 1';
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' sql_error '.$db->lasterror(), LOG_ERR);
			return null;
		}
		$id = null;
		if ($obj = $db->fetch_object($resql)) {
			$id = (int) $obj->id;
		}
		$db->free($resql);
		if ($id > 0) {
			// Ensure dictionary entry stays aligned with UI expectations (type 2 = customer credit payment).
			$updateSql = 'UPDATE '.MAIN_DB_PREFIX."c_paiement SET type = 2, active = 1, module = '".$db->escape('talerbarr')."' WHERE id = ".$id;
			if (!$db->query($updateSql)) {
				dol_syslog(__METHOD__.' failed to normalize payment mode: '.$db->lasterror(), LOG_WARNING);
			}

			if (!function_exists('dolibarr_set_const')) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			}
			dolibarr_set_const($db, 'TALERBARR_PAYMENT_MODE_ID', $id, 'chaine', 0, '', $entityId);
			return $id;
		}

		return null;
	}

	/**
	 * Resolve configured clearing bank account for incoming Taler payments.
	 *
	 * @return int|null Bank account identifier or null when not configured.
	 */
	private static function resolveClearingAccountId(): ?int
	{
		$global = (int) getDolGlobalInt('TALERBARR_CLEARING_BANK_ACCOUNT');
		if ($global > 0) {
			return $global;
		}
		$legacy = (int) getDolGlobalInt('TALERBARR_CLEARING_ACCOUNT_ID');
		return $legacy > 0 ? $legacy : null;
	}

	/**
	 * Resolve configured final bank account for transfers after clearing.
	 *
	 * @param TalerConfig|null $config Optional Taler configuration context.
	 * @return int|null Bank account identifier or null when not configured.
	 */
	private static function resolveFinalAccountId(?TalerConfig $config = null): ?int
	{
		$global = (int) getDolGlobalInt('TALERBARR_FINAL_BANK_ACCOUNT');
		if ($global > 0) {
			return $global;
		}
		if ($config && !empty($config->fk_bank_account)) {
			return (int) $config->fk_bank_account;
		}
		return null;
	}

	/**
	 * Build a concise human-readable summary for a Dolibarr customer order.
	 *
	 * @param Commande $cmd Source order instance.
	 * @return string Summary trimmed to 200 characters.
	 */
	private static function buildOrderSummary(Commande $cmd): string
	{
		$summary = '';
		$candidates = array(
			isset($cmd->note_public) ? dol_string_nohtmltag((string) $cmd->note_public, 0) : '',
			isset($cmd->ref_client) ? dol_string_nohtmltag((string) $cmd->ref_client, 0) : '',
			isset($cmd->ref) ? dol_string_nohtmltag((string) $cmd->ref, 0) : '',
		);
		foreach ($candidates as $candidate) {
			$candidate = trim((string) $candidate);
			if ($candidate !== '') {
				$summary = preg_replace('/\s+/u', ' ', $candidate) ?? $candidate;
				break;
			}
		}

		if ($summary === '') {
			$summary = 'Dolibarr order #' . (isset($cmd->id) ? (string) $cmd->id : '');
		}

		return trim(dol_trunc($summary, 200, 'right', 'UTF-8', 0));
	}

	/**
	 * Ensure a Dolibarr customer order exists and is validated.
	 *
	 * @param DoliDB         $db            Database connection.
	 * @param User           $user          Current user executing the sync.
	 * @param TalerOrderLink $link          Order link needing a Dolibarr order.
	 * @param array          $contractTerms Contract terms payload from Taler.
	 * @param array          $statusData    Latest Taler status payload.
	 * @return Commande|null Created or fetched order, null on failure.
	 */
	private static function ensureDolibarrOrder(
		DoliDB          $db,
		User            $user,
		TalerOrderLink  $link,
		array           $contractTerms,
		array           $statusData
	): ?Commande {
		$orderId = $link->fk_commande ? (int) $link->fk_commande : 0;
		if ($orderId > 0) {
			$commande = new Commande($db);
			if ($commande->fetch($orderId) > 0) {
				return $commande;
			}
		}

		$socid = self::resolveCustomerId($link);
		if ($socid === null) {
			dol_syslog(__METHOD__.' missing customer mapping for order '.$link->taler_order_id, LOG_WARNING);
			return null;
		}

		$commande = new Commande($db);
		$commande->socid = $socid;
		$commande->entity = $link->entity ?: getEntity('commande', 1);
		$commande->ref_client = $link->taler_order_id;
		$commande->date = self::parseTimestamp($contractTerms['timestamp'] ?? null) ?: dol_now();
		$commande->cond_reglement_id = (int) getDolGlobalInt('TALERBARR_DEFAULT_PAYMENT_TERMS') ?: null;
		$commande->mode_reglement_id = self::resolvePaymentModeId();
		$commande->note_public = (string) ($contractTerms['summary'] ?? $statusData['summary'] ?? '');
		$commande->note_private = 'Taler order '.$link->taler_order_id;
		$commande->origin = 'taler';
		$commande->origin_id = 0;

		$resCreate = $commande->create($user);
		if ($resCreate <= 0) {
			dol_syslog(__METHOD__.' create_failed '.$commande->error, LOG_ERR);
			return null;
		}

		$products = $contractTerms['products'] ?? [];
		if (!is_array($products)) {
			$products = [];
		}
		if (empty($products)) {
			$products = array(array(
				'description' => $contractTerms['summary'] ?? $link->taler_order_id,
				'quantity'    => 1,
				'price'       => $contractTerms['amount'] ?? ($statusData['amount'] ?? null),
			));
		}

		foreach ($products as $productLine) {
			if (!is_array($productLine)) {
				continue;
			}
			$desc = (string) ($productLine['description'] ?? $productLine['product_name'] ?? $link->taler_order_id);
			$qty = (float) ($productLine['quantity'] ?? 1);
			if ($qty <= 0) {
				$qty = 1.0;
			}
			$amountParsed = self::extractAmount($productLine['price'] ?? $contractTerms['amount'] ?? ($statusData['amount'] ?? null));
			$unitPrice = self::amountToFloat($amountParsed);
			$fkProd = 0;
			$talPid = (string) ($productLine['product_id'] ?? '');
			if ($talPid !== '') {
				$linkProd = new TalerProductLink($db);
				if ($linkProd->fetchByInstancePid($link->taler_instance, $talPid) > 0 && !empty($linkProd->fk_product)) {
					$fkProd = (int) $linkProd->fk_product;
				}
			}
			$resLine = $commande->addline(
				$desc,
				$unitPrice,
				$qty,
				0,
				0,
				0,
				$fkProd
			);
			if ($resLine <= 0) {
				dol_syslog(__METHOD__.' addline_failed '.$commande->error, LOG_ERR);
			}
		}

		$commande->update_price(1);
		$commande->valid($user);

		return $commande;
	}

	/**
	 * Ensure a validated Dolibarr invoice exists for the order link.
	 *
	 * @param DoliDB         $db         Database connection.
	 * @param User           $user       Current user executing the sync.
	 * @param TalerOrderLink $link       Order link referencing the invoice.
	 * @param Commande       $commande   Source order used to generate invoice lines.
	 * @param array          $statusData Latest Taler status payload.
	 * @return Facture|null  Created or fetched invoice, null on failure.
	 */
	private static function ensureInvoice(
		DoliDB          $db,
		User            $user,
		TalerOrderLink  $link,
		Commande        $commande,
		array           $statusData
	): ?Facture {
		dol_syslog(__METHOD__.' existing fk_facture='.(int) ($link->fk_facture ?? 0).' for order '.$link->taler_order_id, LOG_DEBUG);
		if (!empty($link->fk_facture)) {
			$invoice = new Facture($db);
			if ($invoice->fetch((int) $link->fk_facture) > 0) {
				return $invoice;
			}
		}

		$invoice = new Facture($db);
		$result = $invoice->createFromOrder($commande, $user);
		if ($result <= 0) {
			dol_syslog(__METHOD__.' createFromOrder_failed '.$invoice->error, LOG_ERR);
			return null;
		}
		if (!empty($invoice->id)) {
			$invoice->fetch($invoice->id);
		}
		$invoice->note_public = trim(($invoice->note_public ?? '')."\n".'Taler payment: '.$link->taler_order_id);
		$datePaidTs = self::parseTimestamp($statusData['last_payment'] ?? $statusData['creation_time'] ?? null);
		if ($datePaidTs) {
			$invoice->date = $datePaidTs;
			$invoice->date_lim_reglement = $datePaidTs;
			$invoice->update($user, 1);
		}
		$invoice->validate($user);
		return $invoice;
	}

	/**
	 * Ensure a Dolibarr payment exists and is linked to the invoice.
	 *
	 * @param DoliDB         $db         Database connection.
	 * @param User           $user       Current user executing the sync.
	 * @param TalerOrderLink $link       Order link referencing the payment.
	 * @param Facture        $invoice    Invoice to settle.
	 * @param array          $statusData Latest Taler status payload.
	 * @return Paiement|null Created or fetched payment, null on failure.
	 */
	private static function ensurePayment(
		DoliDB          $db,
		User            $user,
		TalerOrderLink  $link,
		Facture         $invoice,
		array           $statusData
	): ?Paiement {
		if (!empty($link->fk_paiement)) {
			$paiement = new Paiement($db);
			if ($paiement->fetch((int) $link->fk_paiement) > 0) {
				return $paiement;
			}
		}

		$paymentModeId = self::resolvePaymentModeId();
		$clearingAccountId = self::resolveClearingAccountId();
		if ($paymentModeId === null || $clearingAccountId === null) {
			dol_syslog(__METHOD__.' missing payment mode or clearing account for order '.$link->taler_order_id, LOG_WARNING);
			return null;
		}

		$paiement = new Paiement($db);
		$paiement->datepaye = self::parseTimestamp($statusData['last_payment'] ?? $statusData['creation_time'] ?? null) ?: dol_now();
		$paiement->paiementid = $paymentModeId;
		$paiement->fk_account = $clearingAccountId;
		$paiement->amounts = array($invoice->id => $invoice->total_ttc);
		$paiement->note_public = 'Taler payment '.$link->taler_order_id;
		$paymentId = $paiement->create($user, 1);
		if ($paymentId <= 0) {
			dol_syslog(__METHOD__.' create_failed '.$paiement->error, LOG_ERR);
			return null;
		}
		$paiement->id = $paymentId;
		dol_syslog(__METHOD__.' created paiement '.$paymentId.' for invoice '.$invoice->id, LOG_DEBUG);
		$bankLineId = $paiement->addPaymentToBank(
			$user,
			'payment',
			'Taler payment '.$link->taler_order_id,
			$clearingAccountId,
			'',
			''
		);
		$paiement->_taler_payment_mode_id = $paymentModeId;
		$paiement->_taler_clearing_account_id = $clearingAccountId;
		if ($bankLineId > 0) {
			$paiement->_taler_bank_line_id = (int) $bankLineId;
		}
		return $paiement;
	}

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database connector
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		// Hide rowid unless technical ids displayed
		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID')) {
			$this->fields['rowid']['visible'] = 0;
		}
	}

	/**
	 * Lightweight internal logger utility
	 *
	 * @param string $method Name of the calling method (for context)
	 * @param array  $ctx    Arbitrary context information to be serialized to JSON
	 * @param int    $lvl    Log level constant, defaults to LOG_DEBUG
	 *
	 * @return void
	 */
	private function log(string $method, array $ctx = [], int $lvl = LOG_DEBUG): void
	{
		dol_syslog('TalerOrderLink::' . $method . ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $lvl);
	}

	/**
	 * Create current object in database (wrapper around CommonObject::createCommon())
	 *
	 * @param User $user       Current user performing the action
	 * @param int  $notrigger  If true, bypass Dolibarr triggers (default 0)
	 *
	 * @return int             <0 if KO, >0 if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		if (empty($this->entity)) $this->entity = getEntity($this->element, 1);
		if (empty($this->datec))  $this->datec = dol_now();
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch object from database using rowid or ref
	 *
	 * @param int         $id             Rowid of the record to fetch
	 * @param string|null $ref            Optional natural reference
	 * @param int         $noextrafields  Disable extra fields loading (default 1)
	 * @param int         $nolines        Disable line objects loading  (default 1)
	 *
	 * @return int                        1 if loaded, 0 if not found, <0 on error
	 */
	public function fetch($id, $ref = null, $noextrafields = 1, $nolines = 1)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Update object in database (wrapper around CommonObject::updateCommon())
	 *
	 * @param User $user      Current user performing the action
	 * @param int  $notrigger If true, bypass Dolibarr triggers (default 0)
	 *
	 * @return int            <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object from database (wrapper around CommonObject::deleteCommon())
	 *
	 * @param User $user      Current user performing the action
	 * @param int  $notrigger If true, bypass Dolibarr triggers (default 0)
	 *
	 * @return int            <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Bare-bones upsert when event originates from Taler backend during order creation.
	 *
	 * @param DoliDB        $db             Active Dolibarr DB handler
	 * @param array|object  $statusResponse Raw MerchantOrderStatusResponse
	 * @param User          $user           Current user (for permissions & history)
	 * @param object|array  $contractTerms  Parsed contract terms payload received from Taler
	 *
	 * @return int 1=OK, 0=ignored, -1=error (logic TBD)
	 */
	public static function upsertFromTalerOnOrderCreation(DoliDB $db, $statusResponse, User $user, object|array $contractTerms): int
	{
		$statusData   = self::normalizeToArray($statusResponse);
		$contractData = self::normalizeToArray($contractTerms);

		$orderId = (string) ($statusData['order_id'] ?? $contractData['order_id'] ?? '');
		if ($orderId === '') {
			dol_syslog(__METHOD__.' missing order_id in payload', LOG_ERR);
			return -1;
		}

		$config = null;
		$instance = (string) ($statusData['merchant_instance']
			?? ($statusData['merchant']['instance'] ?? $statusData['merchant']['id'] ?? '')
			?? ($contractData['merchant']['instance'] ?? $contractData['merchant']['id'] ?? '')
		);
		if ($instance !== '') {
			$config = self::fetchConfigForInstance($db, $instance);
		}
		if (!$config) {
			$cfgErr = null;
			$config = TalerConfig::fetchSingletonVerified($db, $cfgErr);
			if (!$config || empty($config->verification_ok)) {
				dol_syslog(__METHOD__.' configuration not available: '.($cfgErr ?: 'unknown error'), LOG_ERR);
				return -1;
			}
			$instance = (string) $config->username;
		}

		$link = new self($db);
		$loaded = $link->fetchByInstanceOrderId($instance, $orderId);
		if ($loaded < 0) {
			dol_syslog(__METHOD__.' fetchByInstanceOrderId failed: '.$link->error, LOG_ERR);
			return -1;
		}

		$isNew = ($loaded === 0);
		if ($isNew) {
			$link->entity        = (int) getEntity($link->element, 1);
			$link->taler_instance = $instance;
			$link->taler_order_id = $orderId;
			$link->datec          = dol_now();
		}

		// Snapshot amount & summary
		$statusAmount = $statusData['amount'] ?? null;
		$contractAmount = $contractData['amount'] ?? ($contractData['amount_str'] ?? null);
		$parsedAmount = self::extractAmount($statusAmount ?? $contractAmount);
		if (!empty($parsedAmount['amount_str'])) {
			$link->order_amount_str = (string) $parsedAmount['amount_str'];
		}
		if (!empty($parsedAmount['currency'])) {
			$link->order_currency = (string) $parsedAmount['currency'];
		}
		if ($parsedAmount['value'] !== null) {
			$link->order_value = (int) $parsedAmount['value'];
		}
		if ($parsedAmount['fraction'] !== null) {
			$link->order_fraction = (int) $parsedAmount['fraction'];
		}

		$summary = self::coalesceString(
			$contractData['summary'] ?? null,
			$statusData['summary'] ?? null,
			$link->order_summary ?? ''
		);
		if ($summary !== '') {
			$link->order_summary = $summary;
		}

		if (isset($statusData['deposit_total'])) {
			$link->deposit_total_str = (string) $statusData['deposit_total'];
		} elseif (isset($contractData['max_fee'])) {
			$link->deposit_total_str = is_string($contractData['max_fee'])
				? $contractData['max_fee']
				: ($contractData['max_fee']['amount'] ?? null);
		}

		// Identity / URLs
		if (!empty($statusData['taler_pay_uri'])) {
			$link->taler_pay_uri = (string) $statusData['taler_pay_uri'];
		} elseif (!empty($statusData['pay_url'])) {
			$link->taler_pay_uri = (string) $statusData['pay_url'];
		}
		if (!empty($statusData['order_status_url'] ?? $statusData['status_url'] ?? '')) {
			$link->taler_status_url = (string) ($statusData['order_status_url'] ?? $statusData['status_url']);
		}
		if (!empty($statusData['session_id'] ?? $contractData['session_id'] ?? '')) {
			$link->taler_session_id = (string) ($statusData['session_id'] ?? $contractData['session_id']);
		}

		// Deadlines
		$refundTs = self::parseTimestamp($contractData['refund_deadline'] ?? $statusData['refund_deadline'] ?? null);
		if ($refundTs) {
			$link->taler_refund_deadline = $db->idate($refundTs);
		}
		$payTs = self::parseTimestamp($contractData['pay_deadline'] ?? $statusData['pay_deadline'] ?? null);
		if ($payTs) {
			$link->taler_pay_deadline = $db->idate($payTs);
		}

		// Status flags
		$statusText = (string) ($statusData['status'] ?? $statusData['order_status'] ?? $link->merchant_status_raw ?? '');
		if ($statusText !== '') {
			$link->merchant_status_raw = $statusText;
		}
		$stateMap = [
			'unpaid'   => 10,
			'claimed'  => 20,
			'paid'     => 30,
			'delivered'=> 40,
			'wired'    => 50,
			'refunded' => 70,
			'expired'  => 90,
			'aborted'  => 91,
		];
		if ($statusText !== '' && isset($stateMap[strtolower($statusText)])) {
			$link->taler_state = $stateMap[strtolower($statusText)];
		}

		if (isset($statusData['wired'])) {
			$link->taler_wired = (int) ((bool) $statusData['wired']);
		}
		if (!empty($statusData['wtid'])) {
			$link->taler_wtid = (string) $statusData['wtid'];
		}
		if (isset($statusData['exchange_url'])) {
			$link->taler_exchange_url = (string) $statusData['exchange_url'];
		}

		$claimTs = self::parseTimestamp($statusData['last_claimed'] ?? $statusData['claim_timestamp'] ?? null);
		if ($claimTs) {
			$link->taler_claimed_at = $db->idate($claimTs);
		}
		$paidTs = self::parseTimestamp($statusData['last_payment'] ?? null);
		if (!$paidTs && !empty($statusData['payments']) && is_array($statusData['payments'])) {
			$payments = array_values($statusData['payments']);
			$lastPayment = end($payments);
			$paidTs = self::parseTimestamp($lastPayment['timestamp'] ?? $lastPayment['time'] ?? null);
		}
		if ($paidTs) {
			$link->taler_paid_at = $db->idate($paidTs);
		}
		if (isset($statusData['refund_pending'])) {
			$link->taler_refund_pending = (int) ((bool) $statusData['refund_pending']);
		}
		if (!empty($statusData['refund_amount'] ?? $statusData['amount_refunded'] ?? '')) {
			$link->taler_refunded_total = (string) ($statusData['refund_amount'] ?? $statusData['amount_refunded']);
		}

		if (!empty($statusData['idempotency_key'])) {
			$link->idempotency_key = (string) $statusData['idempotency_key'];
		}
		$link->last_status_check_at = $db->idate(dol_now());

		// Link-level configuration defaults
		$defaultSoc = (int) getDolGlobalInt('TALERBARR_DEFAULT_SOCID');
		if (empty($link->fk_soc) && $defaultSoc > 0) {
			$link->fk_soc = $defaultSoc;
		}
		$clearingAccountId = self::resolveClearingAccountId();
		if ($clearingAccountId !== null) {
			$link->fk_bank_account = $clearingAccountId;
		}
		if (!empty($config->fk_bank_account)) {
			$link->fk_bank_account_dest = (int) $config->fk_bank_account;
		}

		// Ensure Dolibarr artefacts (order at minimum)
		$commande = self::ensureDolibarrOrder($db, $user, $link, $contractData, $statusData);
		if ($commande instanceof Commande) {
			$link->fk_commande = (int) $commande->id;
			$link->commande_ref_snap = $commande->ref ?? $commande->ref_client ?? $link->commande_ref_snap;
			if (!empty($commande->date_commande)) {
				$link->commande_datec = $db->idate($commande->date_commande);
			}
			if (!empty($commande->date_validation)) {
				$link->commande_validated_at = $db->idate($commande->date_validation);
			}
			if (!empty($commande->mode_reglement_id)) {
				$link->fk_c_paiement = (int) $commande->mode_reglement_id;
			}
		}

		// Persist
		$res = $isNew ? $link->create($user, 1) : $link->update($user, 1);
		if ($res <= 0) {
			dol_syslog(__METHOD__.' failed to save link: '.($link->error ?: $db->lasterror()), LOG_ERR);
			return -1;
		}

		dol_syslog(__METHOD__.' completed', LOG_INFO);
		return 1;
	}

	/**
	 * Upsert triggered when Taler notifies that the order has been *paid*.
	 *
	 * @param DoliDB       $db             DB handler
	 * @param array|object $statusResponse Raw MerchantOrderStatusResponse
	 * @param User         $user           Current user
	 *
	 * @return int 1=OK, 0=ignored, -1=error (logic TBD)
	 */
	public static function upsertFromTalerOfPayment(DoliDB $db, $statusResponse, User $user): int
	{
		$statusData = self::normalizeToArray($statusResponse);
		if (empty($statusData)) {
			dol_syslog(__METHOD__.' empty status payload', LOG_WARNING);
			return -1;
		}

		$orderId = (string) ($statusData['order_id'] ?? $statusData['orderId'] ?? '');
		if ($orderId === '') {
			dol_syslog(__METHOD__.' missing order_id in payload', LOG_ERR);
			return -1;
		}

		$contractData = self::normalizeToArray($statusData['contract_terms'] ?? $statusData['contract'] ?? null);
		$merchantData = self::normalizeToArray($statusData['merchant'] ?? null);
		$contractMerchantData = self::normalizeToArray($contractData['merchant'] ?? null);

		$instance = (string) (
			$statusData['merchant_instance']
			?? ($merchantData['instance'] ?? $merchantData['id'] ?? '')
			?? ($contractMerchantData['instance'] ?? $contractMerchantData['id'] ?? '')
		);

		$config = null;
		if ($instance !== '') {
			$config = self::fetchConfigForInstance($db, $instance);
		}
		if (!$config) {
			$cfgErr = null;
			$config = TalerConfig::fetchSingletonVerified($db, $cfgErr);
			if (!$config || empty($config->verification_ok)) {
				dol_syslog(__METHOD__.' configuration not available: '.($cfgErr ?: 'unknown error'), LOG_ERR);
				return -1;
			}
			$instance = (string) $config->username;
		}

		if (!empty($contractData)) {
			self::upsertFromTalerOnOrderCreation($db, $statusResponse, $user, $contractData);
		}

		$link = new self($db);
		$loaded = $link->fetchByInstanceOrderId($instance, $orderId);
		if ($loaded <= 0) {
			dol_syslog(__METHOD__.' unable to locate order link for '.$instance.'#'.$orderId, $loaded < 0 ? LOG_ERR : LOG_WARNING);
			return $loaded < 0 ? -1 : 0;
		}
		if (empty($link->id)) {
			$link->id = $link->rowid;
		}

		$commande = self::ensureDolibarrOrder($db, $user, $link, $contractData, $statusData);
		if (!$commande) {
			dol_syslog(__METHOD__.' cannot ensure customer order for '.$orderId, LOG_ERR);
			return -1;
		}

		$link->fk_commande = (int) $commande->id;
		if (!empty($commande->ref)) {
			$link->commande_ref_snap = $commande->ref;
		} elseif (!empty($commande->ref_client)) {
			$link->commande_ref_snap = $commande->ref_client;
		}
		if (!empty($commande->date_commande)) {
			$link->commande_datec = $db->idate($commande->date_commande);
		}
		if (!empty($commande->date_validation)) {
			$link->commande_validated_at = $db->idate($commande->date_validation);
		}
		if (!empty($commande->mode_reglement_id)) {
			$link->fk_c_paiement = (int) $commande->mode_reglement_id;
		}
		if (!empty($commande->cond_reglement_id)) {
			$link->fk_cond_reglement = (int) $commande->cond_reglement_id;
		}

		$invoice = self::ensureInvoice($db, $user, $link, $commande, $statusData);
		if (!$invoice) {
			dol_syslog(__METHOD__.' cannot ensure invoice for '.$orderId, LOG_ERR);
			return -1;
		}

		self::hydrateInvoiceSnapshotFromFacture($db, $link, $invoice);

		$paiement = null;
		$paymentModeId = self::resolvePaymentModeId();
		$clearingAccountId = self::resolveClearingAccountId();
		if ($paymentModeId !== null && $clearingAccountId !== null) {
			$paiement = self::ensurePayment($db, $user, $link, $invoice, $statusData);
		} else {
			dol_syslog(__METHOD__.' skipping payment creation (paymentModeId='.(int) ($paymentModeId ?? 0).', clearingAccountId='.(int) ($clearingAccountId ?? 0).') for '.$orderId, LOG_INFO);
		}
		if (!$paiement) {
			dol_syslog(__METHOD__.' payment record unavailable for '.$orderId.' link_fk_facture='.(int) ($link->fk_facture ?? 0), LOG_DEBUG);
			if ($paymentModeId !== null) {
				$link->fk_c_paiement = (int) $paymentModeId;
			}
		} else {
			$link->fk_paiement = (int) $paiement->id;
			if (!empty($paiement->datepaye)) {
				$link->paiement_datep = $db->idate($paiement->datepaye);
			}
			if (!empty($paiement->paiementid)) {
				$link->fk_c_paiement = (int) $paiement->paiementid;
			}
			if (!empty($paiement->_taler_clearing_account_id)) {
				$link->fk_bank_account = (int) $paiement->_taler_clearing_account_id;
			} elseif (!empty($paiement->fk_account)) {
				$link->fk_bank_account = (int) $paiement->fk_account;
			}
			if (!empty($paiement->_taler_bank_line_id)) {
				$link->fk_bank = (int) $paiement->_taler_bank_line_id;
			} elseif (!empty($paiement->fk_bank)) {
				$link->fk_bank = (int) $paiement->fk_bank;
			}

			$paidTs = self::parseTimestamp($statusData['last_payment'] ?? $statusData['paid_at'] ?? null);
			if (!$paidTs && !empty($paiement->datepaye)) {
				$paidTs = is_numeric($paiement->datepaye) ? (int) $paiement->datepaye : (int) strtotime((string) $paiement->datepaye);
			}
			if ($paidTs) {
				$link->taler_paid_at = $db->idate($paidTs);
			}
		}

		if (empty($link->fk_bank_account_dest)) {
			$finalAccountId = self::resolveFinalAccountId($config);
			if ($finalAccountId !== null) {
				$link->fk_bank_account_dest = $finalAccountId;
			}
		}

		$statusText = (string) ($statusData['status'] ?? $statusData['order_status'] ?? 'paid');
		$stateMap = [
			'unpaid'    => 10,
			'claimable' => 15,
			'claimed'   => 20,
			'paid'      => 30,
			'delivered' => 40,
			'wired'     => 50,
			'refunded'  => 70,
			'expired'   => 90,
			'aborted'   => 91,
		];
		$stateKey = strtolower($statusText);
		if ($stateKey !== '' && isset($stateMap[$stateKey])) {
			$link->taler_state = $stateMap[$stateKey];
		} elseif (empty($link->taler_state) || (int) $link->taler_state < 30) {
			$link->taler_state = 30;
		}
		$link->merchant_status_raw = $statusText;

		if (isset($statusData['wired'])) {
			$link->taler_wired = (int) ((bool) $statusData['wired']);
		}
		if (!empty($statusData['wtid'])) {
			$link->taler_wtid = (string) $statusData['wtid'];
		}
		if (!empty($statusData['exchange_url'])) {
			$link->taler_exchange_url = (string) $statusData['exchange_url'];
		}
		if (isset($statusData['refund_pending'])) {
			$link->taler_refund_pending = (int) ((bool) $statusData['refund_pending']);
		}
		if (!empty($statusData['refund_amount'] ?? $statusData['amount_refunded'] ?? '')) {
			$link->taler_refunded_total = (string) ($statusData['refund_amount'] ?? $statusData['amount_refunded']);
		}

		$link->last_status_check_at = $db->idate(dol_now());

		$resUpdate = $link->update($user, 1);
		if ($resUpdate <= 0) {
			dol_syslog(__METHOD__.' failed to persist link '.$orderId.': '.($link->error ?: $db->lasterror()), LOG_ERR);
			return -1;
		}

		dol_syslog(__METHOD__.' completed for order '.$orderId, LOG_INFO);
		return 1;
	}

	/**
	 * Upsert triggered when Taler notifies that the wire transfer has been executed.
	 *
	 * @param DoliDB       $db             DB handler
	 * @param array|object $statusResponse Raw MerchantOrderStatusResponse
	 * @param User         $user           Current user
	 *
	 * @return int 1=OK, 0=ignored, -1=error (logic TBD)
	 */
	public static function upsertFromTalerOfWireTransfer(DoliDB $db, $statusResponse, User $user): int
	{
		$statusData = self::normalizeToArray($statusResponse);
		if (empty($statusData)) {
			dol_syslog(__METHOD__.' empty status payload', LOG_WARNING);
			return -1;
		}

		$orderId = (string) ($statusData['order_id'] ?? $statusData['orderId'] ?? '');
		if ($orderId === '') {
			dol_syslog(__METHOD__.' missing order_id in payload', LOG_ERR);
			return -1;
		}

		$contractData = self::normalizeToArray($statusData['contract_terms'] ?? $statusData['contract'] ?? null);
		$merchantData = self::normalizeToArray($statusData['merchant'] ?? null);
		$contractMerchantData = self::normalizeToArray($contractData['merchant'] ?? null);

		$instance = (string) (
			$statusData['merchant_instance']
			?? ($merchantData['instance'] ?? $merchantData['id'] ?? '')
			?? ($contractMerchantData['instance'] ?? $contractMerchantData['id'] ?? '')
		);

		$config = null;
		if ($instance !== '') {
			$config = self::fetchConfigForInstance($db, $instance);
		}
		if (!$config) {
			$cfgErr = null;
			$config = TalerConfig::fetchSingletonVerified($db, $cfgErr);
			if (!$config || empty($config->verification_ok)) {
				dol_syslog(__METHOD__.' configuration not available: '.($cfgErr ?: 'unknown error'), LOG_ERR);
				return -1;
			}
			$instance = (string) $config->username;
		}

		// Ensure invoice and payment are aligned before handling the wire.
		$resPayment = self::upsertFromTalerOfPayment($db, $statusResponse, $user);
		if ($resPayment < 0) {
			dol_syslog(__METHOD__.' aborted because payment upsert failed for '.$orderId, LOG_ERR);
			return -1;
		}

		$link = new self($db);
		$loaded = $link->fetchByInstanceOrderId($instance, $orderId);
		if ($loaded <= 0) {
			dol_syslog(__METHOD__.' unable to locate order link for '.$instance.'#'.$orderId, $loaded < 0 ? LOG_ERR : LOG_WARNING);
			return $loaded < 0 ? -1 : 0;
		}
		if (empty($link->id)) {
			$link->id = $link->rowid;
		}

		$commande = self::ensureDolibarrOrder($db, $user, $link, $contractData, $statusData);
		$invoice = $commande ? self::ensureInvoice($db, $user, $link, $commande, $statusData) : null;
		if (!$invoice) {
			dol_syslog(__METHOD__.' cannot ensure invoice for '.$orderId, LOG_ERR);
			return -1;
		}

		$clearingAccountId = self::resolveClearingAccountId();
		$finalAccountId = $link->fk_bank_account_dest ?: self::resolveFinalAccountId($config);
		if ($clearingAccountId === null) {
			dol_syslog(__METHOD__.' clearing bank account not configured', LOG_ERR);
			return -1;
		}
		if ($finalAccountId === null) {
			dol_syslog(__METHOD__.' final bank account not configured', LOG_ERR);
			return -1;
		}
		if ($clearingAccountId === $finalAccountId) {
			dol_syslog(__METHOD__.' clearing and final bank accounts must differ', LOG_ERR);
			return -1;
		}

		$wireRawDetails = self::normalizeToArray($statusData['wire_details'] ?? $statusData['wire_transfer'] ?? $statusData['wire'] ?? null);
		$wireAmountCandidate = $wireRawDetails['amount']
			?? $wireRawDetails['amount_wire']
			?? $statusData['wire_amount']
			?? $statusData['wired_amount']
			?? ($statusData['amount'] ?? null);
		$parsedWireAmount = self::extractAmount($wireAmountCandidate ?? $link->order_amount_str ?? null);
		$wireAmount = self::amountToFloat($parsedWireAmount);
		if ($wireAmount <= 0 && isset($invoice->total_ttc)) {
			$wireAmount = (float) price2num($invoice->total_ttc, 'MT');
		}
		if ($wireAmount <= 0) {
			dol_syslog(__METHOD__.' cannot determine wire amount for '.$orderId, LOG_ERR);
			return -1;
		}

		$executionTs = self::parseTimestamp($statusData['wire_execution_time'] ?? $statusData['execution_time'] ?? $wireRawDetails['execution_time'] ?? $wireRawDetails['wired_at'] ?? null);
		if (!$executionTs) {
			$executionTs = dol_now();
		}

		$performedTransfer = false;
		$bankLineFromId = null;
		$bankLineToId = null;
		if (empty($link->taler_wired) || empty($link->wire_execution_time)) {
			$accountFrom = new Account($db);
			$accountTo = new Account($db);
			if ($accountFrom->fetch($clearingAccountId) <= 0) {
				dol_syslog(__METHOD__.' cannot fetch clearing account '.$clearingAccountId.': '.$accountFrom->error, LOG_ERR);
				return -1;
			}
			if ($accountTo->fetch($finalAccountId) <= 0) {
				dol_syslog(__METHOD__.' cannot fetch final account '.$finalAccountId.': '.$accountTo->error, LOG_ERR);
				return -1;
			}

			$typeFrom = ($accountFrom->type == Account::TYPE_CASH || $accountTo->type == Account::TYPE_CASH) ? 'LIQ' : 'PRE';
			$typeTo = ($accountFrom->type == Account::TYPE_CASH || $accountTo->type == Account::TYPE_CASH) ? 'LIQ' : 'VIR';
			$description = 'GNU Taler wire '.$orderId;
			$amountOut = -1 * (float) price2num($wireAmount, 'MT');
			$amountIn = (float) price2num($wireAmount, 'MT');

			$db->begin();
			$bankLineFromId = $accountFrom->addline($executionTs, $typeFrom, $description, $amountOut, '', 0, $user);
			if (!($bankLineFromId > 0)) {
				$db->rollback();
				dol_syslog(__METHOD__.' failed to add bank line on clearing account: '.$accountFrom->error, LOG_ERR);
				return -1;
			}
			$bankLineToId = $accountTo->addline($executionTs, $typeTo, $description, $amountIn, '', 0, $user);
			if (!($bankLineToId > 0)) {
				$db->rollback();
				dol_syslog(__METHOD__.' failed to add bank line on final account: '.$accountTo->error, LOG_ERR);
				return -1;
			}

			$url = DOL_URL_ROOT.'/compta/bank/line.php?rowid=';
			$label = '(banktransfert)';
			$type = 'banktransfert';
			if ($accountFrom->add_url_line($bankLineFromId, $bankLineToId, $url, $label, $type) <= 0) {
				$db->rollback();
				dol_syslog(__METHOD__.' failed to link bank lines (from)', LOG_ERR);
				return -1;
			}
			if ($accountTo->add_url_line($bankLineToId, $bankLineFromId, $url, $label, $type) <= 0) {
				$db->rollback();
				dol_syslog(__METHOD__.' failed to link bank lines (to)', LOG_ERR);
				return -1;
			}

			$db->commit();
			$performedTransfer = true;
		}

		$link->fk_bank_account_dest = $finalAccountId;
		$link->taler_wired = 1;
		$link->wire_execution_time = $db->idate($executionTs);
		if (!empty($statusData['wtid'])) {
			$link->taler_wtid = (string) $statusData['wtid'];
		}
		if (!empty($statusData['exchange_url'])) {
			$link->taler_exchange_url = (string) $statusData['exchange_url'];
		}

		$existingDetails = array();
		if (!empty($link->wire_details_json)) {
			$decoded = json_decode((string) $link->wire_details_json, true);
			if (is_array($decoded)) {
				$existingDetails = $decoded;
			}
		}
		$wireMeta = array_merge($existingDetails,
			array(
			'details'         => $wireRawDetails,
			'amount_str'      => $parsedWireAmount['amount_str'] ?? $link->order_amount_str ?? null,
			'amount_numeric'  => $wireAmount,
			'currency'        => $parsedWireAmount['currency'] ?? $link->order_currency ?? null,
			'bank_line_from'  => $bankLineFromId ?? ($existingDetails['bank_line_from'] ?? null),
			'bank_line_to'    => $bankLineToId ?? ($existingDetails['bank_line_to'] ?? null),
			'performed_now'   => $performedTransfer,
		));
		$link->wire_details_json = json_encode($wireMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$link->taler_state = 50;
		$link->merchant_status_raw = (string) ($statusData['status'] ?? $statusData['order_status'] ?? 'wired');
		if (isset($statusData['refund_pending'])) {
			$link->taler_refund_pending = (int) ((bool) $statusData['refund_pending']);
		}
		$link->last_status_check_at = $db->idate(dol_now());

		$resUpdate = $link->update($user, 1);
		if ($resUpdate <= 0) {
			dol_syslog(__METHOD__.' failed to persist link '.$orderId.': '.($link->error ?: $db->lasterror()), LOG_ERR);
			return -1;
		}

		dol_syslog(__METHOD__.' completed for order '.$orderId, LOG_INFO);
		return 1;
	}

	/**
	 * Bare-bones upsert when event originates from Dolibarr side (e.g., order creation).
	 *
	 * @param DoliDB   $db   DB handler
	 * @param Commande $cmd  Dolibarr customer order object
	 * @param User     $user Current user
	 *
	 * @return int 1=OK, 0=ignored, -1=error (logic TBD)
	 */
	public static function upsertFromDolibarr(DoliDB $db, Commande $cmd, User $user): int
	{
		dol_syslog('TalerOrderLink::upsertFromDolibarr.begin', LOG_DEBUG);

		if (empty($cmd->id)) {
			dol_syslog('TalerOrderLink::upsertFromDolibarr invalid commande object (missing id)', LOG_ERR);
			return -1;
		}

		// Ensure command lines are loaded for price extraction
		if (empty($cmd->lines) || !is_array($cmd->lines)) {
			if (method_exists($cmd, 'fetch_lines')) {
				$cmd->fetch_lines();
			}
		}

		$cfgErr = null;
		$config = TalerConfig::fetchSingletonVerified($db, $cfgErr);
		if (!$config || empty($config->verification_ok)) {
			dol_syslog('TalerOrderLink::upsertFromDolibarr missing verified config: '.($cfgErr ?: 'unknown'), LOG_ERR);
			return -1;
		}
		// If sync direction is "from Taler" only, skip pushing orders outwards.
		if (!empty($config->syncdirection)) {
			dol_syslog('TalerOrderLink::upsertFromDolibarr skipped because config syncdirection forbids Dolibarr->Taler push', LOG_INFO);
			return 0;
		}

		$instance = (string) $config->username;
		try {
			$client = new TalerMerchantClient($config->talermerchanturl, $config->talertoken, $instance);
		} catch (Throwable $e) {
			dol_syslog('TalerOrderLink::upsertFromDolibarr unable to instantiate merchant client: '.$e->getMessage(), LOG_ERR);
			return -1;
		}

		global $conf;
		$currency = !empty($cmd->multicurrency_code) ? strtoupper($cmd->multicurrency_code) : (!empty($conf->currency) ? strtoupper($conf->currency) : 'EUR');
		$currency = 'KUDOS'; // TODO: revert to real currency once Taler backend supports EUR payouts

		// Build order summary and fallback fulfillment message
		$summary = self::buildOrderSummary($cmd);
		$fulfillmentMessage = 'Dolibarr order ' . (isset($cmd->ref) && $cmd->ref !== '' ? $cmd->ref : ('#' . $cmd->id));

		// Determine total amount (fallback to sum of lines)
		$totalTtc = (float) price2num($cmd->total_ttc ?? 0.0, 'MT');
		if ($totalTtc <= 0 && !empty($cmd->lines)) {
			$lineTotal = 0.0;
			foreach ($cmd->lines as $line) {
				$lineTotal += (float) price2num($line->total_ttc ?? ($line->total_ht ?? 0), 'MT');
			}
			$totalTtc = (float) price2num($lineTotal, 'MT');
		}

		// Map lines into Taler order products
		$productCache = array();
		$products = array();
		if (!empty($cmd->lines)) {
			foreach ($cmd->lines as $line) {
				$lineAmount = (float) price2num($line->total_ttc ?? ($line->total_ht ?? 0), 'MT');
				$qtyRaw = isset($line->qty) ? (float) price2num($line->qty, 'MT') : 1.0;
				$qty = ($qtyRaw !== 0.0) ? $qtyRaw : 1.0;
				$unitAmount = $qty !== 0.0 ? $lineAmount / $qty : $lineAmount;
				$descCandidates = array(
					isset($line->label) ? $line->label : null,
					isset($line->desc) ? $line->desc : null,
					isset($line->product_label) ? $line->product_label : null,
					isset($line->product_ref) ? $line->product_ref : null,
				);
				$lineDesc = '';
				foreach ($descCandidates as $cand) {
					if ($cand === null) {
						continue;
					}
					$cand = trim(dol_string_nohtmltag((string) $cand, 0));
					if ($cand !== '') {
						$lineDesc = $cand;
						break;
					}
				}
				if ($lineDesc === '') {
					$lineDesc = 'Line '.$line->rowid;
				}

				$productEntry = array(
					'description' => $lineDesc,
					'quantity'    => $qty,
					'price'       => self::formatAmountString($currency, $unitAmount),
				);

				if (!empty($line->fk_product)) {
					$productId = (int) $line->fk_product;
					if (!array_key_exists($productId, $productCache)) {
						$linkProd = new TalerProductLink($db);
						if ($linkProd->fetchByProductId($productId) > 0 && !empty($linkProd->taler_product_id) && strcasecmp((string) $linkProd->taler_instance, $instance) === 0) {
							$productCache[$productId] = $linkProd->taler_product_id;
						} else {
							$productCache[$productId] = null;
						}
					}
					if (!empty($productCache[$productId])) {
						$productEntry['product_id'] = $productCache[$productId];
					}
				}

				$products[] = $productEntry;
			}
		}

		// Prepare order identifier with conflict resolution
		$candidateIds = array(
			self::sanitizeOrderIdCandidate((string) ($cmd->ref_client ?? '')),
			self::sanitizeOrderIdCandidate((string) ($cmd->ref ?? '')),
			self::sanitizeOrderIdCandidate('CMD-'.$cmd->id),
		);
		$orderId = '';
		foreach ($candidateIds as $cand) {
			if ($cand !== '') {
				$orderId = $cand;
				break;
			}
		}
		if ($orderId === '') {
			$orderId = 'CMD-'.$cmd->id;
		}

		$link = new self($db);
		$linkRowId = 0;
		$sqlLink = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$link->table_element.
			' WHERE fk_commande = '.((int) $cmd->id).
			' AND entity IN ('.getEntity($link->element, true).
			') ORDER BY rowid DESC LIMIT 1';
		$resLink = $db->query($sqlLink);
		if ($resLink && ($objLink = $db->fetch_object($resLink))) {
			$linkRowId = (int) $objLink->rowid;
			$link->fetch($linkRowId);
		}

		if (!$linkRowId) {
			// Ensure uniqueness of order id for this instance if the candidate is already linked elsewhere
			$tmpLink = new self($db);
			$finalOrderId = $orderId;
			$attempt = 1;
			while ($tmpLink->fetchByInstanceOrderId($instance, $finalOrderId) > 0 && (int) $tmpLink->fk_commande !== (int) $cmd->id) {
				$attempt++;
				$finalOrderId = self::sanitizeOrderIdCandidate($orderId.'-'.$attempt);
				if ($finalOrderId === '') {
					$finalOrderId = 'CMD-'.$cmd->id.'-'.$attempt;
				}
			}
			$orderId = $finalOrderId;
		}

		$postOrder = array(
			'order_id'             => $orderId,
			'summary'              => $summary,
			'amount'               => self::formatAmountString($currency, $totalTtc),
			'fulfillment_message'  => $fulfillmentMessage,
			'extra'                => array(
				'dolibarr_order_id' => (int) $cmd->id,
				'dolibarr_ref'      => (string) ($cmd->ref ?? ''),
				'dolibarr_entity'   => (int) (!empty($cmd->entity) ? $cmd->entity : (int) $conf->entity),
			),
		);
		if (!empty($products)) {
			$postOrder['products'] = $products;
		}
		if (!empty($cmd->date_livraison)) {
			$postOrder['delivery_date'] = dol_print_date($cmd->date_livraison, 'dayrfc');
		}
		if (!empty($cmd->date_lim_reglement)) {
			$postOrder['pay_deadline'] = dol_print_date($cmd->date_lim_reglement, 'dayrfc');
		}

		$requestPayload = array('order' => $postOrder);

		$response = array();
		try {
			$response = $client->createOrder($requestPayload);
		} catch (Throwable $e) {
			dol_syslog('TalerOrderLink::upsertFromDolibarr createOrder failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}

		$orderIdCreated = (string) ($response['order_id'] ?? $orderId);
		$statusData = array();
		try {
			$statusData = $client->getOrderStatus($orderIdCreated);
		} catch (Throwable $e) {
			dol_syslog('TalerOrderLink::upsertFromDolibarr warning: getOrderStatus failed '.$e->getMessage(), LOG_WARNING);
			$statusData = array('order_id' => $orderIdCreated);
		}
		$statusData = self::normalizeToArray($statusData);

		// Refresh or initialize link
		if ($linkRowId && (strcasecmp((string) $link->taler_order_id, $orderIdCreated) !== 0)) {
			$link->taler_order_id = $orderIdCreated;
		}
		if (!$linkRowId) {
			if ($link->fetchByInstanceOrderId($instance, $orderIdCreated) > 0) {
				// Existing link reused (possibly from earlier sync)
			} else {
				$link->taler_instance = $instance;
				$link->taler_order_id = $orderIdCreated;
				$link->entity = getEntity($link->element, 1);
				$link->datec = dol_now();
			}
		}

		$link->fk_commande = (int) $cmd->id;
		$link->fk_soc = !empty($cmd->socid) ? (int) $cmd->socid : $link->fk_soc;
		$link->order_summary = $summary;
		$link->order_amount_str = self::formatAmountString($currency, $totalTtc);
		$parsedAmount = self::extractAmount($link->order_amount_str);
		if (!empty($parsedAmount['currency'])) {
			$link->order_currency = (string) $parsedAmount['currency'];
		}
		if ($parsedAmount['value'] !== null) {
			$link->order_value = (int) $parsedAmount['value'];
		}
		if ($parsedAmount['fraction'] !== null) {
			$link->order_fraction = (int) $parsedAmount['fraction'];
		}
		$link->commande_ref_snap = isset($cmd->ref) ? (string) $cmd->ref : $link->commande_ref_snap;
		$link->commande_datec = !empty($cmd->date) ? $db->idate($cmd->date) : (!empty($cmd->date_commande) ? $db->idate($cmd->date_commande) : $link->commande_datec);
		if (!empty($cmd->date_valid)) {
			$link->commande_validated_at = $db->idate($cmd->date_valid);
		}
		if (!empty($cmd->mode_reglement_id)) {
			$link->fk_c_paiement = (int) $cmd->mode_reglement_id;
		}
		if (!empty($cmd->cond_reglement_id)) {
			$link->fk_cond_reglement = (int) $cmd->cond_reglement_id;
		}
		if (!empty($cmd->fk_bank_account)) {
			$link->fk_bank_account = (int) $cmd->fk_bank_account;
		}
		if (empty($link->fk_bank_account)) {
			$link->fk_bank_account = self::resolveClearingAccountId();
		}
		if (empty($link->fk_bank_account_dest)) {
			$link->fk_bank_account_dest = self::resolveFinalAccountId($config);
		}
		$link->intended_payment_code = 'TLR';
		$link->idempotency_key = $response['token'] ?? $link->idempotency_key ?? ('dolibarr-'.$cmd->id);

		// Transfer URLs and state from status data / response
		if (!empty($statusData['taler_pay_uri'] ?? $statusData['pay_url'] ?? $response['taler_pay_uri'] ?? '')) {
			$link->taler_pay_uri = (string) ($statusData['taler_pay_uri'] ?? $statusData['pay_url'] ?? $response['taler_pay_uri']);
		}
		if (!empty($statusData['order_status_url'] ?? $statusData['status_url'] ?? $response['order_status_url'] ?? '')) {
			$link->taler_status_url = (string) ($statusData['order_status_url'] ?? $statusData['status_url'] ?? $response['order_status_url']);
		}
		if (!empty($statusData['refund_deadline'])) {
			$rdTs = self::parseTimestamp($statusData['refund_deadline']);
			if ($rdTs) {
				$link->taler_refund_deadline = $db->idate($rdTs);
			}
		}
		if (!empty($statusData['pay_deadline'])) {
			$pdTs = self::parseTimestamp($statusData['pay_deadline']);
			if ($pdTs) {
				$link->taler_pay_deadline = $db->idate($pdTs);
			}
		}

		$statusText = (string) ($statusData['status'] ?? $statusData['order_status'] ?? 'unpaid');
		$stateMap = [
			'unpaid'   => 10,
			'claimable'=> 15,
			'claimed'  => 20,
			'paid'     => 30,
			'delivered'=> 40,
			'wired'    => 50,
			'refunded' => 70,
			'expired'  => 90,
			'aborted'  => 91,
		];
		$link->merchant_status_raw = $statusText;
		$link->taler_state = $stateMap[strtolower($statusText)] ?? 10;
		$link->taler_wired = (int) ((bool) ($statusData['wired'] ?? false));
		if (!empty($statusData['wtid'])) {
			$link->taler_wtid = (string) $statusData['wtid'];
		}
		if (!empty($statusData['exchange_url'])) {
			$link->taler_exchange_url = (string) $statusData['exchange_url'];
		}
		$link->last_status_check_at = $db->idate(dol_now());

		$publicLink = '';
		if (!empty($link->taler_status_url)) {
			$publicLink = (string) $link->taler_status_url;
		} elseif (!empty($link->taler_pay_uri)) {
			$publicLink = (string) $link->taler_pay_uri;
		}
		if ($publicLink !== '') {
			try {
				self::ensurePublicNoteHasPayLink($cmd, $publicLink);

				$invoice = null;
				if (!empty($link->fk_facture)) {
					$invoiceCandidate = new Facture($db);
					if ($invoiceCandidate->fetch((int) $link->fk_facture) > 0) {
						$invoice = $invoiceCandidate;
					}
				}
				if (!$invoice) {
					$invoice = self::findInvoiceLinkedToOrder($db, $cmd);
				}
				if (!$invoice) {
					$invoice = self::ensureInvoice($db, $user, $link, $cmd, $statusData);
				}

				if ($invoice) {
					dol_syslog(__METHOD__.' invoice detected for order '.$cmd->id.' facture_id='.(int) ($invoice->id ?? 0).' before_snapshot_fk='.((int) ($link->fk_facture ?? 0)), LOG_DEBUG);
					$beforeSnapshot = array(
						'fk_facture'           => (int) ($link->fk_facture ?? 0),
						'facture_ref_snap'     => (string) ($link->facture_ref_snap ?? ''),
						'facture_datef'        => (string) ($link->facture_datef ?? ''),
						'facture_validated_at' => (string) ($link->facture_validated_at ?? ''),
						'fk_cond_reglement'    => (int) ($link->fk_cond_reglement ?? 0),
					);

					self::hydrateInvoiceSnapshotFromFacture($db, $link, $invoice);
					self::ensureInvoiceVisibleOnOrder($db, $invoice, $cmd);
					dol_syslog(__METHOD__.' invoice snapshot hydrated fk='.((int) ($link->fk_facture ?? 0)), LOG_DEBUG);

					$afterSnapshot = array(
						'fk_facture'           => (int) ($link->fk_facture ?? 0),
						'facture_ref_snap'     => (string) ($link->facture_ref_snap ?? ''),
						'facture_datef'        => (string) ($link->facture_datef ?? ''),
						'facture_validated_at' => (string) ($link->facture_validated_at ?? ''),
						'fk_cond_reglement'    => (int) ($link->fk_cond_reglement ?? 0),
					);

					if ($afterSnapshot !== $beforeSnapshot) {
						dol_syslog(__METHOD__.' invoice snapshot changed, persisting', LOG_DEBUG);
						$resInvoiceUpdate = $link->update($user, 1);
						if ($resInvoiceUpdate <= 0) {
							dol_syslog('TalerOrderLink::upsertFromDolibarr failed to persist invoice snapshot: ' . ($link->error ?: $db->lasterror()), LOG_WARNING);
						} else {
							dol_syslog(__METHOD__.' invoice snapshot persisted fk_facture='.((int) ($link->fk_facture ?? 0)), LOG_DEBUG);
						}
					}
				}
			} catch (\Throwable $e) {
				self::logThrowable('upsertFromDolibarr.invoice', $e);
				return -1;
			}
		}

		dol_syslog(__METHOD__.' final link persist (rowid='.(int) $linkRowId.') fk_facture='.((int) ($link->fk_facture ?? 0)), LOG_DEBUG);
		$persistResult = $linkRowId ? $link->update($user, 1) : $link->create($user, 1);
		if ($persistResult <= 0) {
			dol_syslog('TalerOrderLink::upsertFromDolibarr failed to persist link: '.($link->error ?: $db->lasterror()), LOG_ERR);
			return -1;
		}
		dol_syslog(__METHOD__.' link persisted with fk_facture='.((int) ($link->fk_facture ?? 0)).' result='.$persistResult, LOG_DEBUG);
		if (!$linkRowId && !empty($link->id)) {
			$linkRowId = (int) $link->id;
			if (empty($link->rowid)) {
				$link->rowid = $linkRowId;
			}
		}

		dol_syslog('TalerOrderLink::upsertFromDolibarr.end order_id='.$orderIdCreated, LOG_INFO);
		return 1;
	}

	/**
	 * Return formatted label or link to the card of this object.
	 *
	 * @param int         $withpicto            0=no picto, 1=include picto, 2=picto only
	 * @param string      $option               Additional options (e.g., 'nolink')
	 * @param int         $notooltip            Disable tooltips if set to 1
	 * @param string      $morecss              Extra CSS classes for <a/>
	 * @param int|string  $save_lastsearch_value Manage lastsearch_url feature
	 *
	 * @return string  HTML <a> or <span> element with label
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		$url = dol_buildpath('/talerbarr/talerorderlink_card.php', 1) . '?id=' . (int) $this->id;
		$linkstart = ($option === 'nolink') ? '<span>' : '<a href="' . $url . '" class="' . $morecss . '">';
		$linkend   = ($option === 'nolink') ? '</span>' : '</a>';
		$label = $this->taler_order_id ?: '#' . $this->rowid;
		if ($withpicto) $label = img_object('', $this->picto, ($withpicto !== 2 ? 'class="paddingright"' : '')) . ($withpicto === 2 ? '' : $label);
		return $linkstart . $label . $linkend;
	}
}
