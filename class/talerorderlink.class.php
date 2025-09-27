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
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';


dol_include_once('/talerbarr/class/talerconfig.class.php');
dol_include_once('/talerbarr/class/talerproductlink.class.php');

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
		return $global > 0 ? $global : null;
	}

	/**
	 * Resolve configured clearing bank account for incoming Taler payments.
	 *
	 * @return int|null Bank account identifier or null when not configured.
	 */
	private static function resolveClearingAccountId(): ?int
	{
		$global = (int) getDolGlobalInt('TALERBARR_CLEARING_BANK_ACCOUNT');
		return $global > 0 ? $global : null;
	}

	/**
	 * Resolve configured final bank account for transfers after clearing.
	 *
	 * @return int|null Bank account identifier or null when not configured.
	 */
	private static function resolveFinalAccountId(): ?int
	{
		$global = (int) getDolGlobalInt('TALERBARR_FINAL_BANK_ACCOUNT');
		return $global > 0 ? $global : null;
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
		$invoice->fetch($result);
		$invoice->note_public = trim(($invoice->note_public ?? '')."\n".'Taler payment: '.$link->taler_order_id);
		$datePaidTs = self::parseTimestamp($statusData['last_payment'] ?? $statusData['creation_time'] ?? null);
		if ($datePaidTs) {
			$invoice->date = $datePaidTs;
			$invoice->date_lim_reglement = $datePaidTs;
			$invoice->update($invoice->id, $user, 1);
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
		$paiement->addPaymentToBank(
			$user,
			'payment',
			'Taler payment '.$link->taler_order_id,
			$clearingAccountId,
			'',
			''
		);
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
		// TODO: implement mapping with rules as follows
		//   1. We anyway have to start with creation of the order in the Dolibarr
		//      Usually it requires a customer which of course in this case is really hard to define...
		// 		So we can just say that it is some default taler user, which we have to make sure exists on the import of
		// 		the module.
		// 		We also have to make sure that the payment method is Taler digital cash which we have to add the same on
		//      the import of the module.
		//      Otherwise I believe it is all for the first step.
		//      With date of now
		//      Ref we can make to match the taler order id
		//      and default doc template
		//      as taler do not support the tags/categories for this part, we can simply ignore them
		//
		//   2. As order (rather order template) is created at step 1, we have to add products to this order, from the
		//      products that we have received from taler, as taler can have the discounts for the product, we have to apply it
		//      If we doesn't have the product_id from taler, we can create a dummy product with some price that will match the
		//      price from taler, and add it to talet without reference to dolibarr.
		//
		//   3. We have to validate it, and that's it for this part.
		// Taler can provide us 2 versions:
		// 1. Contract Terms v0
		// 2. Contract Terms v1
		//type ContractTerms = (ContractTermsV1 | ContractTermsV0) & ContractTermsCommon;
		//interface ContractTermsV1 {
		//  // Version 1 supports the choices array, see
		//  // https://docs.taler.net/design-documents/046-mumimo-contracts.html.
		//  // @since protocol **vSUBSCRIBE**
		//  version: 1;
		//
		//  // List of contract choices that the customer can select from.
		//  // @since protocol **vSUBSCRIBE**
		//  choices: ContractChoice[];
		//
		//  // Map of storing metadata and issue keys of
		//  // token families referenced in this contract.
		//  // @since protocol **vSUBSCRIBE**
		//  token_families: { [token_family_slug: string]: ContractTokenFamily };
		//}
		//interface ContractTermsV0 {
		//  // Defaults to version 0.
		//  version?: 0;
		//
		//  // Total price for the transaction.
		//  // The exchange will subtract deposit fees from that amount
		//  // before transferring it to the merchant.
		//  amount: Amount;
		//
		//  // Maximum total deposit fee accepted by the merchant for this contract.
		//  // Overrides defaults of the merchant instance.
		//  max_fee: Amount;
		//}
		//interface ContractTermsCommon {
		//  // Human-readable description of the whole purchase.
		//  summary: string;
		//
		//  // Map from IETF BCP 47 language tags to localized summaries.
		//  summary_i18n?: { [lang_tag: string]: string };
		//
		//  // Unique, free-form identifier for the proposal.
		//  // Must be unique within a merchant instance.
		//  // For merchants that do not store proposals in their DB
		//  // before the customer paid for them, the order_id can be used
		//  // by the frontend to restore a proposal from the information
		//  // encoded in it (such as a short product identifier and timestamp).
		//  order_id: string;
		//
		//  // URL where the same contract could be ordered again (if
		//  // available). Returned also at the public order endpoint
		//  // for people other than the actual buyer (hence public,
		//  // in case order IDs are guessable).
		//  public_reorder_url?: string;
		//
		//  // URL that will show that the order was successful after
		//  // it has been paid for.  Optional, but either fulfillment_url
		//  // or fulfillment_message must be specified in every
		//  // contract terms.
		//  //
		//  // If a non-unique fulfillment URL is used, a customer can only
		//  // buy the order once and will be redirected to a previous purchase
		//  // when trying to buy an order with the same fulfillment URL a second
		//  // time. This is useful for digital goods that a customer only needs
		//  // to buy once but should be able to repeatedly download.
		//  //
		//  // For orders where the customer is expected to be able to make
		//  // repeated purchases (for equivalent goods), the fulfillment URL
		//  // should be made unique for every order. The easiest way to do
		//  // this is to include a unique order ID in the fulfillment URL.
		//  //
		//  // When POSTing to the merchant, the placeholder text "${ORDER_ID}"
		//  // is be replaced with the actual order ID (useful if the
		//  // order ID is generated server-side and needs to be
		//  // in the URL). Note that this placeholder can only be used once.
		//  // Front-ends may use other means to generate a unique fulfillment URL.
		//  fulfillment_url?: string;
		//
		//  // Message shown to the customer after paying for the order.
		//  // Either fulfillment_url or fulfillment_message must be specified.
		//  fulfillment_message?: string;
		//
		//  // Map from IETF BCP 47 language tags to localized fulfillment
		//  // messages.
		//  fulfillment_message_i18n?: { [lang_tag: string]: string };
		//
		//  // List of products that are part of the purchase (see Product).
		//  products: Product[];
		//
		//  // Time when this contract was generated.
		//  timestamp: Timestamp;
		//
		//  // After this deadline has passed, no refunds will be accepted.
		//  refund_deadline: Timestamp;
		//
		//  // After this deadline, the merchant won't accept payments for the contract.
		//  pay_deadline: Timestamp;
		//
		//  // Transfer deadline for the exchange.  Must be in the
		//  // deposit permissions of coins used to pay for this order.
		//  wire_transfer_deadline: Timestamp;
		//
		//  // Merchant's public key used to sign this proposal; this information
		//  // is typically added by the backend. Note that this can be an ephemeral key.
		//  merchant_pub: EddsaPublicKey;
		//
		//  // Base URL of the (public!) merchant backend API.
		//  // Must be an absolute URL that ends with a slash.
		//  merchant_base_url: string;
		//
		//  // More info about the merchant, see below.
		//  merchant: Merchant;
		//
		//  // The hash of the merchant instance's wire details.
		//  h_wire: HashCode;
		//
		//  // Wire transfer method identifier for the wire method associated with h_wire.
		//  // The wallet may only select exchanges via a matching auditor if the
		//  // exchange also supports this wire method.
		//  // The wire transfer fees must be added based on this wire transfer method.
		//  wire_method: string;
		//
		//  // Exchanges that the merchant accepts even if it does not accept any auditors that audit them.
		//  exchanges: Exchange[];
		//
		//  // Delivery location for (all!) products.
		//  delivery_location?: Location;
		//
		//  // Time indicating when the order should be delivered.
		//  // May be overwritten by individual products.
		//  delivery_date?: Timestamp;
		//
		//  // Nonce generated by the wallet and echoed by the merchant
		//  // in this field when the proposal is generated.
		//  nonce: string;
		//
		//  // Specifies for how long the wallet should try to get an
		//  // automatic refund for the purchase. If this field is
		//  // present, the wallet should wait for a few seconds after
		//  // the purchase and then automatically attempt to obtain
		//  // a refund.  The wallet should probe until "delay"
		//  // after the payment was successful (i.e. via long polling
		//  // or via explicit requests with exponential back-off).
		//  //
		//  // In particular, if the wallet is offline
		//  // at that time, it MUST repeat the request until it gets
		//  // one response from the merchant after the delay has expired.
		//  // If the refund is granted, the wallet MUST automatically
		//  // recover the payment.  This is used in case a merchant
		//  // knows that it might be unable to satisfy the contract and
		//  // desires for the wallet to attempt to get the refund without any
		//  // customer interaction.  Note that it is NOT an error if the
		//  // merchant does not grant a refund.
		//  auto_refund?: RelativeTime;
		//
		//  // Extra data that is only interpreted by the merchant frontend.
		//  // Useful when the merchant needs to store extra information on a
		//  // contract without storing it separately in their database.
		//  // Must really be an Object (not a string, integer, float or array).
		//  extra?: Object;
		//
		//  // Minimum age the buyer must have (in years). Default is 0.
		//  // This value is at least as large as the maximum over all
		//  // minimum age requirements of the products in this contract.
		//  // It might also be set independent of any product, due to
		//  // legal requirements.
		//  minimum_age?: Integer;
		//
		//}
		//interface ContractChoice {
		//  // Price to be paid for this choice. Could be 0.
		//  // The price is in addition to other instruments,
		//  // such as rations and tokens.
		//  // The exchange will subtract deposit fees from that amount
		//  // before transferring it to the merchant.
		//  amount: Amount;
		//
		//  // Human readable description of the semantics of the choice
		//  // within the contract to be shown to the user at payment.
		//  description?: string;
		//
		//  // Map from IETF 47 language tags to localized descriptions.
		//  description_i18n?: { [lang_tag: string]: string };
		//
		//  // List of inputs the wallet must provision (all of them) to
		//  // satisfy the conditions for the contract.
		//  inputs: ContractInput[];
		//
		//  // List of outputs the merchant promises to yield (all of them)
		//  // once the contract is paid.
		//  outputs: ContractOutput[];
		//
		//  // Maximum total deposit fee accepted by the merchant for this contract.
		//  max_fee: Amount;
		//}
		//// For now, only tokens are supported as inputs.
		//type ContractInput = ContractInputToken;
		//interface ContractInputToken {
		//  type: "token";
		//
		//  // Slug of the token family in the
		//  // token_families map on the order.
		//  token_family_slug: string;
		//
		//  // Number of tokens of this type required.
		//  // Defaults to one if the field is not provided.
		//  count?: Integer;
		//};
		//// For now, only tokens are supported as outputs.
		//type ContractOutput = ContractOutputToken | ContractOutputTaxReceipt;
		//interface ContractOutputToken {
		//  type: "token";
		//
		//  // Slug of the token family in the
		//  // 'token_families' map on the top-level.
		//  token_family_slug: string;
		//
		//  // Number of tokens to be issued.
		//  // Defaults to one if the field is not provided.
		//  count?: Integer;
		//
		//  // Index of the public key for this output token
		//  // in the ContractTokenFamily keys array.
		//  key_index: Integer;
		//
		//}
		//interface ContractOutputTaxReceipt {
		//
		//  // Tax receipt output.
		//  type: "tax-receipt";
		//
		//  // Array of base URLs of donation authorities that can be
		//  // used to issue the tax receipts. The client must select one.
		//  donau_urls: string[];
		//
		//  // Total amount that will be on the tax receipt.
		//  amount: Amount;
		//
		//}
		//interface ContractTokenFamily {
		//  // Human-readable name of the token family.
		//  name: string;
		//
		//  // Human-readable description of the semantics of
		//  // this token family (for display).
		//  description: string;
		//
		//  // Map from IETF BCP 47 language tags to localized descriptions.
		//  description_i18n?: { [lang_tag: string]: string };
		//
		//  // Public keys used to validate tokens issued by this token family.
		//  keys: TokenIssuePublicKey[];
		//
		//  // Kind-specific information of the token
		//  details: ContractTokenDetails;
		//
		//  // Must a wallet understand this token type to
		//  // process contracts that use or issue it?
		//  critical: boolean;
		//};
		//type TokenIssuePublicKey =
		//  | TokenIssueRsaPublicKey
		//  | TokenIssueCsPublicKey;
		//interface TokenIssueRsaPublicKey {
		//  cipher: "RSA";
		//
		//  // RSA public key.
		//  rsa_pub: RsaPublicKey;
		//
		//  // Start time of this key's signatures validity period.
		//  signature_validity_start: Timestamp;
		//
		//  // End time of this key's signatures validity period.
		//  signature_validity_end: Timestamp;
		//
		//}
		//interface TokenIssueCsPublicKey {
		//  cipher: "CS";
		//
		//  // CS public key.
		//  cs_pub: Cs25519Point;
		//
		//  // Start time of this key's signatures validity period.
		//  signature_validity_start: Timestamp;
		//
		//  // End time of this key's signatures validity period.
		//  signature_validity_end: Timestamp;
		//
		//}
		//type ContractTokenDetails =
		//  | ContractSubscriptionTokenDetails
		//  | ContractDiscountTokenDetails;
		//interface ContractSubscriptionTokenDetails {
		//  class: "subscription";
		//
		//  // Array of domain names where this subscription
		//  // can be safely used (e.g. the issuer warrants that
		//  // these sites will re-issue tokens of this type
		//  // if the respective contract says so).  May contain
		//  // "*" for any domain or subdomain.
		//  trusted_domains: string[];
		//};
		//interface ContractDiscountTokenDetails {
		//  class: "discount";
		//
		//  // Array of domain names where this discount token
		//  // is intended to be used.  May contain "*" for any
		//  // domain or subdomain.  Users should be warned about
		//  // sites proposing to consume discount tokens of this
		//  // type that are not in this list that the merchant
		//  // is accepting a coupon from a competitor and thus
		//  // may be attaching different semantics (like get 20%
		//  // discount for my competitors 30% discount token).
		//  expected_domains: string[];
		//};
		//The wallet must select an exchange that either the merchant accepts directly by listing it in the exchanges array, or for which the merchant accepts an auditor that audits that exchange by listing it in the auditors array.
		//
		//The Product object describes the product being purchased from the merchant. It has the following structure:
		//
		//interface Product {
		//
		//  // Merchant-internal identifier for the product.
		//  product_id?: string;
		//
		//  // Name of the product.
		//  // Since API version **v20**.  Optional only for
		//  // backwards-compatibility, should be considered mandatory
		//  // moving forward!
		//  product_name?: string;
		//
		//  // Human-readable product description.
		//  description: string;
		//
		//  // Map from IETF BCP 47 language tags to localized descriptions.
		//  description_i18n?: { [lang_tag: string]: string };
		//
		//  // The number of units of the product to deliver to the customer.
		//  quantity?: Integer;
		//
		//  // Unit in which the product is measured (liters, kilograms, packages, etc.).
		//  unit?: string;
		//
		//  // The price of the product; this is the total price for quantity times unit of this product.
		//  price?: Amount;
		//
		//  // An optional base64-encoded product image.
		//  image?: ImageDataUrl;
		//
		//  // A list of taxes paid by the merchant for this product. Can be empty.
		//  taxes?: Tax[];
		//
		//  // Time indicating when this product should be delivered.
		//  delivery_date?: Timestamp;
		//}
		//interface Tax {
		//  // The name of the tax.
		//  name: string;
		//
		//  // Amount paid in tax.
		//  tax: Amount;
		//}
		//interface Merchant {
		//  // The merchant's legal name of business.
		//  name: string;
		//
		//  // Email address for contacting the merchant.
		//  email?: string;
		//
		//  // Label for a location with the business address of the merchant.
		//  website?: string;
		//
		//  // An optional base64-encoded product image.
		//  logo?: ImageDataUrl;
		//
		//  // Label for a location with the business address of the merchant.
		//  address?: Location;
		//
		//  // Label for a location that denotes the jurisdiction for disputes.
		//  // Some of the typical fields for a location (such as a street address) may be absent.
		//  jurisdiction?: Location;
		//}
		//// Delivery location, loosely modeled as a subset of
		//// ISO20022's PostalAddress25.
		//interface Location {
		//  // Nation with its own government.
		//  country?: string;
		//
		//  // Identifies a subdivision of a country such as state, region, county.
		//  country_subdivision?: string;
		//
		//  // Identifies a subdivision within a country sub-division.
		//  district?: string;
		//
		//  // Name of a built-up area, with defined boundaries, and a local government.
		//  town?: string;
		//
		//  // Specific location name within the town.
		//  town_location?: string;
		//
		//  // Identifier consisting of a group of letters and/or numbers that
		//  // is added to a postal address to assist the sorting of mail.
		//  post_code?: string;
		//
		//  // Name of a street or thoroughfare.
		//  street?: string;
		//
		//  // Name of the building or house.
		//  building_name?: string;
		//
		//  // Number that identifies the position of a building on a street.
		//  building_number?: string;
		//
		//  // Free-form address lines, should not exceed 7 elements.
		//  address_lines?: string[];
		//}
		//interface Auditor {
		//  // Official name.
		//  name: string;
		//
		//  // Auditor's public key.
		//  auditor_pub: EddsaPublicKey;
		//
		//  // Base URL of the auditor.
		//  url: string;
		//}
		//interface Exchange {
		//  // The exchange's base URL.
		//  url: string;
		//
		//  // How much would the merchant like to use this exchange.
		//  // The wallet should use a suitable exchange with high
		//  // priority. The following priority values are used, but
		//  // it should be noted that they are NOT in any way normative.
		//  //
		//  // 0: likely it will not work (recently seen with account
		//  //    restriction that would be bad for this merchant)
		//  // 512: merchant does not know, might be down (merchant
		//  //    did not yet get /wire response).
		//  // 1024: good choice (recently confirmed working)
		//  priority: Integer;
		//
		//  // Master public key of the exchange.
		//  master_pub: EddsaPublicKey;
		//
		//  // Maximum amount that the merchant could be paid
		//  // using this exchange (due to legal limits).
		//  // New in protocol **v17**.
		//  // Optional, no limit if missing.
		//  max_contribution?: Amount;
		//}
		dol_syslog('TalerOrderLink::upsertFromTaler TODO', LOG_DEBUG);
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
		//TODO: This function will be called when the order was paid in taler and we have to reflect this in dolibarr
		// 1. We have to create the invoice for the created and validated order
		// 	  customer is re_used from the order
		//    type is standard invoice
		//    invoice date is date when the order has been paid
		//    payment terms are now required(I believe we have to state some default taler payment terms on the import of the module)
		//  2. Validate the invoice
		//  3. Add the information about the payment to the facture public notes
		//  3. Create the payment for this invoice
		//  4. We have to enter payment received from customer for this invoice
		//     Date of payment is date when the order has been paid
		//     Payment method is taler digital cash
		//     Account to credit, is something rather strange, let's for this moment say we have to create the Taler Bank Account Clearing
		//     on the import of the module, and use it here as it is part of the configuration
		//     We have to connect the invoices and set the amount that was paid.
		dol_syslog('TalerOrderLink::upsertFromTalerOfPayment TODO', LOG_DEBUG);
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
		//TODO: This function will be called when the order was wired in taler and we have to reflect this
		//  in dolibarr
		// 1. Basically, this means that we have to change the status of the paiement that was previously made for the
		//    Clearing account, and I believe now it is quite strange as normally the Bank Account that the transfer from
		//    taler should be done to is not a clearing account, but some real bank account, with IBAN and cookies but let's say this
		// 	  that is something that was made on the setting-up of the module, we just have to fetch it from the configuration.
		// 2.  We have to enter payment received from customer for array of invoices
		//     Date of payment is date when the order has been paid
		//     Payment method is taler digital cash
		//     Account to credit, is something rather strange, let's for this moment say we have to create the Taler Bank Account
		//     on the import of the module, and use it here.
		//     We have to connect the invoices and set the amount that was wired.
		dol_syslog('TalerOrderLink::upsertFromTalerOfWireTransfer TODO', LOG_DEBUG);
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
		// TODO: To create the order, we have to compose the json which is made as
		// interface PostOrderRequest {
		//  // The order must at least contain the minimal
		//  // order detail, but can override all.
		//  order: Order;
		//
		//  // If set, the backend will then set the refund deadline to the current
		//  // time plus the specified delay.  If it's not set, refunds will not be
		//  // possible.
		//  refund_delay?: RelativeTime;
		//
		//  // Specifies the payment target preferred by the client. Can be used
		//  // to select among the various (active) wire methods supported by the instance.
		//  payment_target?: string;
		//
		//  // The session for which the payment is made (or replayed).
		//  // Only set for session-based payments.
		//  // Since protocol **v6**.
		//  session_id?: string;
		//
		//  // Specifies that some products are to be included in the
		//  // order from the inventory.  For these inventory management
		//  // is performed (so the products must be in stock) and
		//  // details are completed from the product data of the backend.
		//  inventory_products?: MinimalInventoryProduct[];
		//
		//  // Specifies a lock identifier that was used to
		//  // lock a product in the inventory.  Only useful if
		//  // inventory_products is set.  Used in case a frontend
		//  // reserved quantities of the individual products while
		//  // the shopping cart was being built.  Multiple UUIDs can
		//  // be used in case different UUIDs were used for different
		//  // products (i.e. in case the user started with multiple
		//  // shopping sessions that were combined during checkout).
		//  lock_uuids?: string[];
		//
		//  // Should a token for claiming the order be generated?
		//  // False can make sense if the ORDER_ID is sufficiently
		//  // high entropy to prevent adversarial claims (like it is
		//  // if the backend auto-generates one). Default is 'true'.
		//  // Note: This is NOT related to tokens used for subscriptions or discounts.
		//  create_token?: boolean;
		//
		//  // OTP device ID to associate with the order.
		//  // This parameter is optional.
		//  otp_id?: string;
		//}
		//The Order object represents the starting point for new ContractTerms. After validating and sanitizing all inputs, the merchant backend will add additional information to the order and create a new ContractTerms object that will be stored in the database.
		//
		//type Order = (OrderV1 | OrderV0) & OrderCommon;
		//interface OrderV1 {
		//  // Version 1 order support discounts and subscriptions.
		//  // https://docs.taler.net/design-documents/046-mumimo-contracts.html
		//  // @since protocol **vSUBSCRIBE**
		//  version: 1;
		//
		//  // List of contract choices that the customer can select from.
		//  // @since protocol **vSUBSCRIBE**
		//  choices?: OrderChoice[];
		//}
		//interface OrderV0 {
		//  // Optional, defaults to 0 if not set.
		//  version?: 0;
		//
		//  // Total price for the transaction. The exchange will subtract deposit
		//  // fees from that amount before transferring it to the merchant.
		//  amount: Amount;
		//
		//  // Maximum total deposit fee accepted by the merchant for this contract.
		//  // Overrides defaults of the merchant instance.
		//  max_fee?: Amount;
		//}
		//interface OrderCommon {
		//  // Human-readable description of the whole purchase.
		//  summary: string;
		//
		//  // Map from IETF BCP 47 language tags to localized summaries.
		//  summary_i18n?: { [lang_tag: string]: string };
		//
		//  // Unique identifier for the order. Only characters
		//  // allowed are "A-Za-z0-9" and ".:_-".
		//  // Must be unique within a merchant instance.
		//  // For merchants that do not store proposals in their DB
		//  // before the customer paid for them, the order_id can be used
		//  // by the frontend to restore a proposal from the information
		//  // encoded in it (such as a short product identifier and timestamp).
		//  order_id?: string;
		//
		//  // URL where the same contract could be ordered again (if
		//  // available). Returned also at the public order endpoint
		//  // for people other than the actual buyer (hence public,
		//  // in case order IDs are guessable).
		//  public_reorder_url?: string;
		//
		//  // See documentation of fulfillment_url field in ContractTerms.
		//  // Either fulfillment_url or fulfillment_message must be specified.
		//  // When creating an order, the fulfillment URL can
		//  // contain ${ORDER_ID} which will be substituted with the
		//  // order ID of the newly created order.
		//  fulfillment_url?: string;
		//
		//  // See documentation of fulfillment_message in ContractTerms.
		//  // Either fulfillment_url or fulfillment_message must be specified.
		//  fulfillment_message?: string;
		//
		//  // Map from IETF BCP 47 language tags to localized fulfillment
		//  // messages.
		//  fulfillment_message_i18n?: { [lang_tag: string]: string };
		//
		//  // Minimum age the buyer must have to buy.
		//  minimum_age?: Integer;
		//
		//  // List of products that are part of the purchase.
		//  products?: Product[];
		//
		//  // Time when this contract was generated. If null, defaults to current
		//  // time of merchant backend.
		//  timestamp?: Timestamp;
		//
		//  // After this deadline has passed, no refunds will be accepted.
		//  // Overrides deadline calculated from refund_delay in
		//  // PostOrderRequest.
		//  refund_deadline?: Timestamp;
		//
		//  // After this deadline, the merchant won't accept payments for the contract.
		//  // Overrides deadline calculated from default pay delay configured in
		//  // merchant backend.
		//  pay_deadline?: Timestamp;
		//
		//  // Transfer deadline for the exchange. Must be in the deposit permissions
		//  // of coins used to pay for this order.
		//  // Overrides deadline calculated from default wire transfer delay
		//  // configured in merchant backend. Must be after refund deadline.
		//  wire_transfer_deadline?: Timestamp;
		//
		//  // Base URL of the (public!) merchant backend API.
		//  // Must be an absolute URL that ends with a slash.
		//  // Defaults to the base URL this request was made to.
		//  merchant_base_url?: string;
		//
		//  // Delivery location for (all!) products.
		//  delivery_location?: Location;
		//
		//  // Time indicating when the order should be delivered.
		//  // May be overwritten by individual products.
		//  // Must be in the future.
		//  delivery_date?: Timestamp;
		//
		//  // See documentation of auto_refund in ContractTerms.
		//  // Specifies for how long the wallet should try to get an
		//  // automatic refund for the purchase.
		//  auto_refund?: RelativeTime;
		//
		//  // Extra data that is only interpreted by the merchant frontend.
		//  // Useful when the merchant needs to store extra information on a
		//  // contract without storing it separately in their database.
		//  // Must really be an Object (not a string, integer, float or array).
		//  extra?: Object;
		//}
		//The OrderChoice object describes a possible choice within an order. The choice is done by the wallet and consists of in- and outputs. In the example of buying an article, the merchant could present the customer with the choice to use a valid subscription token or pay using a gift voucher. Available since protocol vSUBSCRIBE.
		//
		//interface OrderChoice {
		//  // Total price for the choice. The exchange will subtract deposit
		//  // fees from that amount before transferring it to the merchant.
		//  amount: Amount;
		//
		//  // Human readable description of the semantics of the choice
		//  // within the contract to be shown to the user at payment.
		//  description?: string;
		//
		//  // Map from IETF 47 language tags to localized descriptions.
		//  description_i18n?: { [lang_tag: string]: string };
		//
		//  // Inputs that must be provided by the customer, if this choice is selected.
		//  // Defaults to empty array if not specified.
		//  inputs?: OrderInput[];
		//
		//  // Outputs provided by the merchant, if this choice is selected.
		//  // Defaults to empty array if not specified.
		//  outputs?: OrderOutput[];
		//
		//  // Maximum total deposit fee accepted by the merchant for this contract.
		//  // Overrides defaults of the merchant instance.
		//  max_fee?: Amount;
		//}
		//// For now, only token inputs are supported.
		//type OrderInput = OrderInputToken;
		//interface OrderInputToken {
		//
		//  // Token input.
		//  type: "token";
		//
		//  // Token family slug as configured in the merchant backend. Slug is unique
		//  // across all configured tokens of a merchant.
		//  token_family_slug: string;
		//
		//  // How many units of the input are required.
		//  // Defaults to 1 if not specified. Output with count == 0 are ignored by
		//  // the merchant backend.
		//  count?: Integer;
		//
		//}
		//type OrderOutput = OrderOutputToken | OrderOutputTaxReceipt;
		//interface OrderOutputToken {
		//
		//  // Token output.
		//  type: "token";
		//
		//  // Token family slug as configured in the merchant backend. Slug is unique
		//  // across all configured tokens of a merchant.
		//  token_family_slug: string;
		//
		//  // How many units of the output are issued by the merchant.
		//  // Defaults to 1 if not specified. Output with count == 0 are ignored by
		//  // the merchant backend.
		//  count?: Integer;
		//
		//  // When should the output token be valid. Can be specified if the
		//  // desired validity period should be in the future (like selling
		//  // a subscription for the next month). Optional. If not given,
		//  // the validity is supposed to be "now" (time of order creation).
		//  valid_at?: Timestamp;
		//
		//}
		//interface OrderOutputTaxReceipt {
		//
		//  // Tax receipt output.
		//  type: "tax-receipt";
		//
		//}
		//The following MinimalInventoryProduct can be provided if the parts of the order are inventory-based, that is if the PostOrderRequest uses inventory_products. For such products, which must be in the backend’s inventory, the backend can automatically fill in the amount and other details about the product that are known to it from its products table. Note that the inventory_products will be appended to the list of products that the frontend already put into the order. So if the frontend can sell additional non-inventory products together with inventory_products. Note that the backend will NOT update the amount of the order, so the frontend must already have calculated the total price — including the inventory_products.
		//
		//// Note that if the frontend does give details beyond these,
		//// it will override those details (including price or taxes)
		//// that the backend would otherwise fill in via the inventory.
		//interface MinimalInventoryProduct {
		//
		//  // Which product is requested (here mandatory!).
		//  product_id: string;
		//
		//  // How many units of the product are requested.
		//  quantity: Integer;
		//}
		// As v0 most probably be deprecated, we have to creat the v1 order, with no tokens, and so on
		// As we have created the order and sent it to the taler backend, and as we have a token from the taler backend
		// we can create the Facture at the Dolibarr side, with the status validated
		// As paiement will be received we are following the upsertFromTalerOfPayment function
		dol_syslog('TalerOrderLink::upsertFromDolibarr TODO', LOG_DEBUG);
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
