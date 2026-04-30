<?php
/* Copyright (C) 2023		Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2025		Bohdan Potuzhnyi
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    core/triggers/interface_99_modTalerBarr_TalerBarrTriggers.class.php
 * \ingroup talerbarr
 * \brief   Example of trigger file.
 *
 * You can create other triggered files by copying this one.
 * - File name should be either:
 *      - interface_99_modTalerBarr_MyTrigger.class.php
 *      - interface_99_all_MyTrigger.class.php
 * - The file must stay in core/triggers
 * - The class name must be InterfaceMyTrigger
 *
 * @package    TalerBarr
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

// Our classes
require_once dol_buildpath('/talerbarr/class/talerproductlink.class.php', 0);
require_once dol_buildpath('/talerbarr/class/talererrorlog.class.php', 0);
require_once dol_buildpath('/talerbarr/class/talerconfig.class.php', 0);
require_once dol_buildpath('/talerbarr/class/talerorderlink.class.php', 0);

/**
 *  Class of triggers for TalerBarr module
 *
 * @package    TalerBarr
 */
class InterfaceTalerBarrTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "demo";
		$this->description = "TalerBarr triggers.";
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'talerbarr@talerbarr';
	}

	/**
	 * Function called when a Dolibarr business event is done.
	 * All functions "runTrigger" are triggered if the file is inside the directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		Return integer <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('talerbarr')) {
			return 0; // If module is not enabled, we do nothing
		}

		// Put here code you want to execute when a Dolibarr business events occurs.
		// Data and type of action are stored into $object and $action

		// You can isolate code for each action in a separate method: this method should be named like the trigger in camelCase.
		// For example : COMPANY_CREATE => public function companyCreate($action, $object, User $user, Translate $langs, Conf $conf)
		$methodName = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($action)))));
		$callback = array($this, $methodName);
		if (is_callable($callback)) {
			dol_syslog(
				"Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". id=".$object->id
			);

			return call_user_func($callback, $action, $object, $user, $langs, $conf);
		}

		// Or you can execute some code here
		switch ($action) {  // @phan-suppress-current-line PhanNoopSwitchCases
			// Users
			//case 'USER_CREATE':
			//case 'USER_MODIFY':
			//case 'USER_NEW_PASSWORD':
			//case 'USER_ENABLEDISABLE':
			//case 'USER_DELETE':

			// Actions
			//case 'ACTION_MODIFY':
			//case 'ACTION_CREATE':
			//case 'ACTION_DELETE':

			// Groups
			//case 'USERGROUP_CREATE':
			//case 'USERGROUP_MODIFY':
			//case 'USERGROUP_DELETE':

			// Companies
			//case 'COMPANY_CREATE':
			//case 'COMPANY_MODIFY':
			//case 'COMPANY_DELETE':

			// Contacts
			//case 'CONTACT_CREATE':
			//case 'CONTACT_MODIFY':
			//case 'CONTACT_DELETE':
			//case 'CONTACT_ENABLEDISABLE':

			// Products
			case 'PRODUCT_CREATE':
			case 'PRODUCT_MODIFY':
			case 'PRODUCT_SET_MULTILANGS':
			case 'PRODUCT_PRICE_MODIFY':
			case 'PRODUCT_DEL_MULTILANGS':
				return $this->upsertProduct($object, $user);

			case 'PRODUCT_DELETE':
				return $this->deleteProduct($object, $user);

			//Stock movement
			case 'STOCK_MOVEMENT':
				return $this->touchProductFromMovement($object, $user);

			//MYECMDIR
			//case 'MYECMDIR_CREATE':
			//case 'MYECMDIR_MODIFY':
			//case 'MYECMDIR_DELETE':

			// Sales orders
			//case 'ORDER_CREATE':
			//case 'ORDER_MODIFY':
			case 'ORDER_VALIDATE':
				return $this->orderValidate($action, $object, $user, $langs, $conf);
			//case 'ORDER_DELETE':
			//case 'ORDER_CANCEL':
			//case 'ORDER_SENTBYMAIL':
			//case 'ORDER_CLASSIFY_BILLED':		// TODO Replace it with ORDER_BILLED
			//case 'ORDER_CLASSIFY_UNBILLED':	// TODO Replace it with ORDER_UNBILLED
			//case 'ORDER_SETDRAFT':
			//case 'LINEORDER_INSERT':
			//case 'LINEORDER_MODIFY':
			//case 'LINEORDER_DELETE':

			// Supplier orders
			//case 'ORDER_SUPPLIER_CREATE':
			//case 'ORDER_SUPPLIER_MODIFY':
			//case 'ORDER_SUPPLIER_VALIDATE':
			//case 'ORDER_SUPPLIER_DELETE':
			//case 'ORDER_SUPPLIER_APPROVE':
			//case 'ORDER_SUPPLIER_CLASSIFY_BILLED':		// TODO Replace with ORDER_SUPPLIER_BILLED
			//case 'ORDER_SUPPLIER_CLASSIFY_UNBILLED':		// TODO Replace with ORDER_SUPPLIER_UNBILLED
			//case 'ORDER_SUPPLIER_REFUSE':
			//case 'ORDER_SUPPLIER_CANCEL':
			//case 'ORDER_SUPPLIER_SENTBYMAIL':
			//case 'ORDER_SUPPLIER_RECEIVE':
			//case 'LINEORDER_SUPPLIER_DISPATCH':
			//case 'LINEORDER_SUPPLIER_CREATE':
			//case 'LINEORDER_SUPPLIER_MODIFY':
			//case 'LINEORDER_SUPPLIER_DELETE':

			// Proposals
			//case 'PROPAL_CREATE':
			//case 'PROPAL_MODIFY':
			//case 'PROPAL_VALIDATE':
			//case 'PROPAL_SENTBYMAIL':
			//case 'PROPAL_CLASSIFY_BILLED':		// TODO Replace it with PROPAL_BILLED
			//case 'PROPAL_CLASSIFY_UNBILLED':		// TODO Replace it with PROPAL_UNBILLED
			//case 'PROPAL_CLOSE_SIGNED':
			//case 'PROPAL_CLOSE_REFUSED':
			//case 'PROPAL_DELETE':
			//case 'LINEPROPAL_INSERT':
			//case 'LINEPROPAL_MODIFY':
			//case 'LINEPROPAL_DELETE':

			// SupplierProposal
			//case 'SUPPLIER_PROPOSAL_CREATE':
			//case 'SUPPLIER_PROPOSAL_MODIFY':
			//case 'SUPPLIER_PROPOSAL_VALIDATE':
			//case 'SUPPLIER_PROPOSAL_SENTBYMAIL':
			//case 'SUPPLIER_PROPOSAL_CLOSE_SIGNED':
			//case 'SUPPLIER_PROPOSAL_CLOSE_REFUSED':
			//case 'SUPPLIER_PROPOSAL_DELETE':
			//case 'LINESUPPLIER_PROPOSAL_INSERT':
			//case 'LINESUPPLIER_PROPOSAL_MODIFY':
			//case 'LINESUPPLIER_PROPOSAL_DELETE':

			// Contracts
			//case 'CONTRACT_CREATE':
			//case 'CONTRACT_MODIFY':
			//case 'CONTRACT_ACTIVATE':
			//case 'CONTRACT_CANCEL':
			//case 'CONTRACT_CLOSE':
			//case 'CONTRACT_DELETE':
			//case 'LINECONTRACT_INSERT':
			//case 'LINECONTRACT_MODIFY':
			//case 'LINECONTRACT_DELETE':

			// Bills
			//case 'BILL_CREATE':
			//case 'BILL_MODIFY':
			//case 'BILL_VALIDATE':
			//case 'BILL_UNVALIDATE':
			//case 'BILL_SENTBYMAIL':
			//case 'BILL_CANCEL':
			//case 'BILL_DELETE':
			//case 'BILL_PAYED':
			//case 'LINEBILL_INSERT':
			//case 'LINEBILL_MODIFY':
			//case 'LINEBILL_DELETE':

			// Recurring Bills
			//case 'BILLREC_MODIFY':
			//case 'BILLREC_DELETE':
			//case 'BILLREC_AUTOCREATEBILL':
			//case 'LINEBILLREC_MODIFY':
			//case 'LINEBILLREC_DELETE':

			//Supplier Bill
			//case 'BILL_SUPPLIER_CREATE':
			//case 'BILL_SUPPLIER_MODIFY':
			//case 'BILL_SUPPLIER_DELETE':
			//case 'BILL_SUPPLIER_PAYED':
			//case 'BILL_SUPPLIER_UNPAYED':
			//case 'BILL_SUPPLIER_VALIDATE':
			//case 'BILL_SUPPLIER_UNVALIDATE':
			//case 'LINEBILL_SUPPLIER_CREATE':
			//case 'LINEBILL_SUPPLIER_MODIFY':
			//case 'LINEBILL_SUPPLIER_DELETE':

			// Payments
			//case 'PAYMENT_CUSTOMER_CREATE':
			//case 'PAYMENT_SUPPLIER_CREATE':
			//case 'PAYMENT_ADD_TO_BANK':
			//case 'PAYMENT_DELETE':

			// Online
			//case 'PAYMENT_PAYBOX_OK':
			//case 'PAYMENT_PAYPAL_OK':
			//case 'PAYMENT_STRIPE_OK':

			// Donation
			//case 'DON_CREATE':
			//case 'DON_MODIFY':
			//case 'DON_DELETE':

			// Interventions
			//case 'FICHINTER_CREATE':
			//case 'FICHINTER_MODIFY':
			//case 'FICHINTER_VALIDATE':
			//case 'FICHINTER_CLASSIFY_BILLED':			// TODO Replace it with FICHINTER_BILLED
			//case 'FICHINTER_CLASSIFY_UNBILLED':		// TODO Replace it with FICHINTER_UNBILLED
			//case 'FICHINTER_DELETE':
			//case 'LINEFICHINTER_CREATE':
			//case 'LINEFICHINTER_MODIFY':
			//case 'LINEFICHINTER_DELETE':

			// Members
			//case 'MEMBER_CREATE':
			//case 'MEMBER_VALIDATE':
			//case 'MEMBER_SUBSCRIPTION':
			//case 'MEMBER_MODIFY':
			//case 'MEMBER_NEW_PASSWORD':
			//case 'MEMBER_RESILIATE':
			//case 'MEMBER_DELETE':

			// Categories
			//case 'CATEGORY_CREATE':
			//case 'CATEGORY_MODIFY':
			//case 'CATEGORY_DELETE':
			//case 'CATEGORY_SET_MULTILANGS':

			// Projects
			//case 'PROJECT_CREATE':
			//case 'PROJECT_MODIFY':
			//case 'PROJECT_DELETE':

			// Project tasks
			//case 'TASK_CREATE':
			//case 'TASK_MODIFY':
			//case 'TASK_DELETE':

			// Task time spent
			//case 'TASK_TIMESPENT_CREATE':
			//case 'TASK_TIMESPENT_MODIFY':
			//case 'TASK_TIMESPENT_DELETE':
			//case 'PROJECT_ADD_CONTACT':
			//case 'PROJECT_DELETE_CONTACT':
			//case 'PROJECT_DELETE_RESOURCE':

			// Shipping
			//case 'SHIPPING_CREATE':
			//case 'SHIPPING_MODIFY':
			//case 'SHIPPING_VALIDATE':
			//case 'SHIPPING_SENTBYMAIL':
			//case 'SHIPPING_BILLED':
			//case 'SHIPPING_CLOSED':
			//case 'SHIPPING_REOPEN':
			//case 'SHIPPING_DELETE':

			// and more...

			default:
				dol_syslog("Trigger '".$this->name."' for action '".$action."' launched by ".__FILE__.". id=".$object->id);
				break;
		}

		return 0;
	}

	/**
	 * Push a validated Dolibarr order to GNU Taler via TalerOrderLink helper.
	 *
	 * @param string     $action Triggered action code
	 * @param CommonObject $object Loaded business object (Commande expected)
	 * @param User       $user   Current user executing the trigger
	 * @param Translate  $langs  Translation handler
	 * @param Conf       $conf   Global configuration
	 *
	 * @return int 0 if ignored or an error prevented sync, >0 if handled
	 */
	public function orderValidate($action, $object, User $user, Translate $langs, Conf $conf): int
	{
		dol_syslog(__METHOD__.' triggered for order '.(isset($object->id) ? $object->id : 'n/a'), LOG_DEBUG);

		if (!($object instanceof Commande)) {
			return 0;
		}

		$targetPaymentModeId = $this->resolveTalerPaymentModeId($conf);
		if ($targetPaymentModeId <= 0) {
			dol_syslog(__METHOD__.' skipped because Taler payment mode TLR is not configured', LOG_WARNING);
			return 0;
		}

		$currentPaymentModeId = 0;
		if (!empty($object->mode_reglement_id)) {
			$currentPaymentModeId = (int) $object->mode_reglement_id;
		} elseif (!empty($object->fk_mode_reglement)) {
			$currentPaymentModeId = (int) $object->fk_mode_reglement;
		}
		if ($currentPaymentModeId !== $targetPaymentModeId) {
			dol_syslog(
				__METHOD__.' skipped because payment mode '.$currentPaymentModeId.' is not TLR '.$targetPaymentModeId,
				LOG_DEBUG
			);
			return 0;
		}

		try {
			return TalerOrderLink::upsertFromDolibarr($this->db, $object, $user);
		} catch (\Throwable $e) {
			dol_syslog(
				'TalerOrderLink::upsertFromDolibarr exception during ORDER_VALIDATE: '
				.$e->getMessage().' at '.$e->getFile().':'.$e->getLine(),
				LOG_ERR
			);
			return -1;
		}
	}

	/**
	 * Resolve the Dolibarr payment mode id used for GNU Taler payments.
	 *
	 * @param Conf $conf Global configuration
	 * @return int Payment mode id, or 0 when unavailable
	 */
	private function resolveTalerPaymentModeId(Conf $conf): int
	{
		$global = (int) getDolGlobalInt('TALERBARR_PAYMENT_MODE_ID');
		if ($global > 0) {
			return $global;
		}

		$entityId = !empty($conf->entity) ? (int) $conf->entity : 1;
		$sql = 'SELECT id FROM '.MAIN_DB_PREFIX."c_paiement WHERE code = '".$this->db->escape('TLR')."'";
		$sql .= ' AND entity IN ('.getEntity('c_paiement', true).')';
		$sql .= ' ORDER BY active DESC, entity = '.$entityId.' DESC LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' sql_error '.$this->db->lasterror(), LOG_ERR);
			return 0;
		}

		$id = 0;
		if ($obj = $this->db->fetch_object($resql)) {
			$id = (int) $obj->id;
		}
		$this->db->free($resql);

		return $id;
	}

	/**
	 * On credit-note validation, attempt native Taler refund and annotate the note.
	 *
	 * @param string       $action Triggered action code.
	 * @param CommonObject $object Invoice object.
	 * @param User         $user   Current user.
	 * @param Translate    $langs  Translation handler.
	 * @param Conf         $conf   Global configuration.
	 * @return int                 1 if handled/refund created, 0 if ignored or blocked.
	 */
	public function billValidate($action, $object, User $user, Translate $langs, Conf $conf): int
	{
		$langs->loadLangs(array('talerbarr@talerbarr'));

		if (!($object instanceof Facture)) {
			return 0;
		}
		if ((int) ($object->type ?? 0) !== (int) Facture::TYPE_CREDIT_NOTE) {
			return 0;
		}

		$creditNoteId = (int) ($object->id ?? 0);
		if ($creditNoteId <= 0) {
			return 0;
		}

		$creditRef = (string) ($object->ref ?? '');
		if ($creditRef === '') {
			$creditRef = 'ID'.$creditNoteId;
		}
		$marker = '[taler-credit-note-refund '.$creditRef.']';
		$payoutSummaryPrefix = $marker.' [taler-wallet-payout]';
		$creditAmount = abs((float) price2num($object->total_ttc ?? 0, 'MT'));
		if ($creditAmount <= 0.0) {
			$creditAmount = abs((float) price2num($object->total_ht ?? 0, 'MT'));
		}
		$alreadyRefunded = $this->invoiceNoteHasSuccessfulRefundMarker($object, $marker);

		$sourceInvoiceId = $this->resolveSourceInvoiceId($object);
		if ($sourceInvoiceId <= 0) {
			if ($alreadyRefunded) {
				return 0;
			}
			$this->appendInvoiceNoteLine($object, $marker.' Taler refund skipped: source invoice not found.');
			return 0;
		}

		$link = new TalerOrderLink($this->db);
		$resLink = $link->fetchByInvoiceOrOrder($sourceInvoiceId, 0);
		if ($resLink <= 0 || empty($link->taler_order_id)) {
			return 0;
		}
		if ($alreadyRefunded) {
			$statusUrl = trim((string) ($link->taler_status_url ?? ''));
			$summaryStatusPayload = null;
			$summaryCurrency = '';
			$cfgErr = null;
			$config = $this->fetchVerifiedConfigForInstance((string) ($link->taler_instance ?? ''), $cfgErr);
			if ($config && !empty($config->verification_ok)) {
				try {
					$client = $config->talerMerchantClient();
					$summaryStatusPayload = $client->getOrderStatus((string) $link->taler_order_id, array('allow_refunded_for_repurchase' => 'YES'));
					if (!is_array($summaryStatusPayload)) {
						$summaryStatusPayload = (array) $summaryStatusPayload;
					}
					$summaryStatusPayload['order_id'] = $summaryStatusPayload['order_id'] ?? $summaryStatusPayload['orderId'] ?? $link->taler_order_id;
					$summaryStatusPayload['merchant_instance'] = $summaryStatusPayload['merchant_instance']
						?? ($summaryStatusPayload['merchant']['instance'] ?? $summaryStatusPayload['merchant']['id'] ?? null)
						?? $link->taler_instance;
					$freshStatusUrl = trim((string) ($summaryStatusPayload['order_status_url'] ?? $summaryStatusPayload['status_url'] ?? ''));
					if ($freshStatusUrl !== '') {
						$statusUrl = $freshStatusUrl;
					}
					TalerOrderLink::upsertFromTalerOfRefund($this->db, $summaryStatusPayload, $user);
					$link->fetch((int) $link->id);
				} catch (Throwable $statusError) {
					dol_syslog(__METHOD__.' refund summary refresh failed: '.$statusError->getMessage(), LOG_WARNING);
				}
			}

			$policy = $link->getRefundPolicy();
			$summaryCurrency = strtoupper((string) ($policy['currency'] ?? $link->order_currency ?? ''));
			$summaryCurrency = $this->mapRefundCurrencyForTaler($summaryCurrency, $config ?? null);
			if ($summaryCurrency === '' && is_array($summaryStatusPayload)) {
				$summaryCurrency = self::extractCurrencyFromAmountString((string) ($summaryStatusPayload['refund_amount'] ?? $summaryStatusPayload['amount_refunded'] ?? ''));
			}

			if ($statusUrl !== '' && preg_match('~^https?://~i', $statusUrl)) {
				$currentNote = (string) ($object->note_public ?? '');
				if (strpos($currentNote, $statusUrl) === false) {
					$this->appendInvoiceNoteLine(
						$object,
						$marker.' '.$langs->trans('TalerRefundStatusURL').': '.$statusUrl
					);
				}
			}
			if ($creditAmount > 0.0 && $summaryCurrency !== '' && is_array($summaryStatusPayload)) {
				$payout = self::summarizeCreditNotePayoutFromStatus($summaryStatusPayload, $creditRef, $creditAmount);
				if (is_array($payout)) {
					$paidOutLabel = TalerOrderLink::toTalerAmountString($summaryCurrency, (float) $payout['paid_out']);
					$remainingLabel = TalerOrderLink::toTalerAmountString($summaryCurrency, (float) $payout['remaining']);
					$this->upsertInvoiceNoteLineByPrefix(
						$object,
						$payoutSummaryPrefix,
						$payoutSummaryPrefix.' '.$langs->trans('TalerRefundPayoutSummary', $paidOutLabel, $remainingLabel)
					);
					$syncResult = TalerOrderLink::syncCreditNotePayoutToDolibarr($this->db, $object, $user, (float) $payout['paid_out']);
					if ($syncResult < 0) {
						dol_syslog(__METHOD__.' failed to sync payout amounts to Dolibarr for credit note '.$creditRef, LOG_WARNING);
					}
				}
			}
			return 0;
		}

		$intendedCode = (string) ($link->intended_payment_code ?? '');
		if ($intendedCode !== '' && strcasecmp($intendedCode, 'TLR') !== 0) {
			return 0;
		}

		$cfgErr = null;
		$config = $this->fetchVerifiedConfigForInstance((string) ($link->taler_instance ?? ''), $cfgErr);
		if (!$config || empty($config->verification_ok)) {
			$message = $marker.' '.$langs->trans('TalerRefundConfigInvalid');
			if (!empty($cfgErr)) {
				$message .= ': '.$cfgErr;
			}
			$this->appendInvoiceNoteLine($object, $message);
			return 0;
		}

		try {
			$client = $config->talerMerchantClient();
		} catch (Throwable $e) {
			$this->appendInvoiceNoteLine(
				$object,
				$marker.' '.$langs->trans('TalerRefundConfigInvalid').': '.$e->getMessage()
			);
			return 0;
		}

		try {
			$statusPayload = $client->getOrderStatus((string) $link->taler_order_id, array('allow_refunded_for_repurchase' => 'YES'));
			if (!is_array($statusPayload)) {
				$statusPayload = (array) $statusPayload;
			}
			$statusPayload['order_id'] = $statusPayload['order_id'] ?? $statusPayload['orderId'] ?? $link->taler_order_id;
			$statusPayload['merchant_instance'] = $statusPayload['merchant_instance']
				?? ($statusPayload['merchant']['instance'] ?? $statusPayload['merchant']['id'] ?? null)
				?? $link->taler_instance;

			$statusKey = strtolower((string) ($statusPayload['order_status'] ?? $statusPayload['status'] ?? ''));
			if (TalerOrderLink::payloadHasRefundEvidence((array) $statusPayload)) {
				TalerOrderLink::upsertFromTalerOfRefund($this->db, $statusPayload, $user);
			} else {
				TalerOrderLink::upsertFromTalerOfPayment($this->db, $statusPayload, $user);
			}
			$link->fetch((int) $link->id);
		} catch (Throwable $e) {
			$this->appendInvoiceNoteLine(
				$object,
				$marker.' '.$langs->trans('TalerRefundPreflightFailed').': '.$e->getMessage()
			);
			return 0;
		}

		$policy = $link->getRefundPolicy();
		if (empty($policy['eligible'])) {
			$reasonKey = (string) ($policy['message_key'] ?? 'TalerRefundBlockedUnknown');
			$this->appendInvoiceNoteLine(
				$object,
				$marker.' '.$langs->trans($reasonKey).' '.$langs->trans('TalerRefundUseAlternativeMethod')
			);
			return 0;
		}

		if ($creditAmount <= 0.0) {
			$this->appendInvoiceNoteLine(
				$object,
				$marker.' '.$langs->trans('TalerRefundAmountInvalid').' '.$langs->trans('TalerRefundUseAlternativeMethod')
			);
			return 0;
		}

		$remaining = $policy['remaining_total'] ?? null;
		if ($remaining !== null && ($creditAmount - (float) $remaining) > 0.00000001) {
			$this->appendInvoiceNoteLine(
				$object,
				$marker.' '.$langs->trans('TalerRefundAmountTooHigh').' '.$langs->trans('TalerRefundUseAlternativeMethod')
			);
			return 0;
		}

		$currency = strtoupper((string) ($policy['currency'] ?? $link->order_currency ?? ''));
		$currency = $this->mapRefundCurrencyForTaler($currency, $config);
		if ($currency === '') {
			$this->appendInvoiceNoteLine(
				$object,
				$marker.' '.$langs->trans('TalerRefundBlockedMissingCurrency').' '.$langs->trans('TalerRefundUseAlternativeMethod')
			);
			return 0;
		}

		$refundPayload = array(
			'refund' => TalerOrderLink::toTalerAmountString($currency, (float) $creditAmount),
			'reason' => 'Dolibarr credit note '.$creditRef,
		);

		try {
			$refundResponse = $client->refundOrder((string) $link->taler_order_id, $refundPayload);
			$statusUrl = '';
			$summaryStatusPayload = null;

			try {
				$statusPayload = $client->getOrderStatus((string) $link->taler_order_id, array('allow_refunded_for_repurchase' => 'YES'));
				if (!is_array($statusPayload)) {
					$statusPayload = (array) $statusPayload;
				}
				$statusPayload['order_id'] = $statusPayload['order_id'] ?? $statusPayload['orderId'] ?? $link->taler_order_id;
				$statusPayload['merchant_instance'] = $statusPayload['merchant_instance']
					?? ($statusPayload['merchant']['instance'] ?? $statusPayload['merchant']['id'] ?? null)
					?? $link->taler_instance;
				$statusUrl = trim((string) ($statusPayload['order_status_url'] ?? $statusPayload['status_url'] ?? ''));
				$summaryStatusPayload = $statusPayload;
				TalerOrderLink::upsertFromTalerOfRefund($this->db, $statusPayload, $user);
			} catch (Throwable $statusError) {
				$fallbackPayload = array(
					'order_id' => (string) $link->taler_order_id,
					'merchant_instance' => (string) $link->taler_instance,
					'status' => 'refunded',
					'refund_amount' => $refundPayload['refund'],
					'reason' => $refundPayload['reason'],
				);
				$summaryStatusPayload = $fallbackPayload;
				TalerOrderLink::upsertFromTalerOfRefund($this->db, $fallbackPayload, $user);
				dol_syslog(__METHOD__.' refund post-refresh failed: '.$statusError->getMessage(), LOG_WARNING);
			}

			$line = $marker.' '.$langs->trans('TalerRefundCreated', $refundPayload['refund']);
			if (!empty($refundResponse['taler_refund_uri'])) {
				$line .= ' '.$langs->trans('TalerRefundURI').': '.(string) $refundResponse['taler_refund_uri'];
			}
			if ($statusUrl === '' && !empty($link->taler_status_url)) {
				$statusUrl = trim((string) $link->taler_status_url);
			}
			if ($statusUrl !== '' && preg_match('~^https?://~i', $statusUrl)) {
				$line .= ' '.$langs->trans('TalerRefundStatusURL').': '.$statusUrl;
			}
			$this->appendInvoiceNoteLine($object, $line);
			$payout = null;
			if (is_array($summaryStatusPayload)) {
				$payout = self::summarizeCreditNotePayoutFromStatus($summaryStatusPayload, $creditRef, $creditAmount);
			}
			if (is_array($payout)) {
				$paidOutLabel = TalerOrderLink::toTalerAmountString($currency, (float) $payout['paid_out']);
				$remainingLabel = TalerOrderLink::toTalerAmountString($currency, (float) $payout['remaining']);
				$this->upsertInvoiceNoteLineByPrefix(
					$object,
					$payoutSummaryPrefix,
					$payoutSummaryPrefix.' '.$langs->trans('TalerRefundPayoutSummary', $paidOutLabel, $remainingLabel)
				);
				$syncResult = TalerOrderLink::syncCreditNotePayoutToDolibarr($this->db, $object, $user, (float) $payout['paid_out']);
				if ($syncResult < 0) {
					dol_syslog(__METHOD__.' failed to sync payout amounts to Dolibarr for credit note '.$creditRef, LOG_WARNING);
				}
			}
			return 1;
		} catch (Throwable $e) {
			$httpStatus = self::extractHttpStatus($e);
			$hint = self::extractHttpHint($e);
			switch ($httpStatus) {
				case 410:
					$message = $langs->trans('TalerRefundBlockedDeadline');
					break;
				case 403:
					$message = $langs->trans('TalerRefundBlockedForbidden');
					break;
				case 409:
					$message = self::isCurrencyConflictHint($hint)
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
			$this->appendInvoiceNoteLine($object, $marker.' '.$message);
			return 0;
		}
	}

	/**
	 * Resolve the source invoice for a credit note.
	 *
	 * @param Facture $creditNote Credit note object.
	 * @return int                Source invoice id (0 if unknown).
	 */
	private function resolveSourceInvoiceId(Facture $creditNote): int
	{
		if (!empty($creditNote->fk_facture_source)) {
			return (int) $creditNote->fk_facture_source;
		}

		if (!empty($creditNote->linkedObjectsIds['facture']) && is_array($creditNote->linkedObjectsIds['facture'])) {
			$ids = array_keys($creditNote->linkedObjectsIds['facture']);
			if (!empty($ids)) {
				return (int) $ids[0];
			}
		}

		$creditNoteId = (int) ($creditNote->id ?? 0);
		if ($creditNoteId <= 0) {
			return 0;
		}

		$sql = "SELECT fk_target FROM ".MAIN_DB_PREFIX."element_element WHERE fk_source = ".((int) $creditNoteId).
			" AND sourcetype = 'facture' AND targettype = 'facture' ORDER BY rowid DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql) {
			if ($obj = $this->db->fetch_object($resql)) {
				$this->db->free($resql);
				return (int) $obj->fk_target;
			}
			$this->db->free($resql);
		}

		$sql = "SELECT fk_source FROM ".MAIN_DB_PREFIX."element_element WHERE fk_target = ".((int) $creditNoteId).
			" AND targettype = 'facture' AND sourcetype = 'facture' ORDER BY rowid DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql) {
			if ($obj = $this->db->fetch_object($resql)) {
				$this->db->free($resql);
				return (int) $obj->fk_source;
			}
			$this->db->free($resql);
		}

		return 0;
	}

	/**
	 * Resolve a verified Taler config, preferring the instance if provided.
	 *
	 * @param string      $instance Taler instance name.
	 * @param string|null $error    Filled with error details on failure.
	 * @return TalerConfig|null
	 */
	private function fetchVerifiedConfigForInstance(string $instance, ?string &$error = null): ?TalerConfig
	{
		$error = null;
		$instance = trim($instance);

		if ($instance !== '') {
			$candidate = new TalerConfig($this->db);
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$candidate->table_element.
				" WHERE username = '".$this->db->escape($instance)."'".
				' AND entity IN ('.getEntity('talerconfig', true).') ORDER BY rowid DESC LIMIT 1';
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				if ($candidate->fetch((int) $obj->rowid) > 0) {
					if (empty($candidate->verification_ok)) {
						$verifyErr = null;
						$isValid = $candidate->verifyConfig($verifyErr);
						$candidate->verification_ok = $isValid;
						$candidate->verification_error = $isValid ? null : $verifyErr;
					}
					if (!empty($candidate->verification_ok)) {
						$this->db->free($resql);
						return $candidate;
					}
					if (!empty($candidate->verification_error)) {
						$error = (string) $candidate->verification_error;
					}
				}
			}
			if ($resql) {
				$this->db->free($resql);
			}
		}

		$cfgErr = null;
		$config = TalerConfig::fetchSingletonVerified($this->db, $cfgErr);
		if ($config && !empty($config->verification_ok)) {
			return $config;
		}
		$error = $cfgErr ?: $error;
		return null;
	}

	/**
	 * Check if a note already contains a marker (plain or entity-decoded).
	 *
	 * @param Facture $invoice Invoice object.
	 * @param string  $marker  Marker text.
	 * @return bool
	 */
	private function invoiceNoteContains(Facture $invoice, string $marker): bool
	{
		$note = (string) ($invoice->note_public ?? '');
		if ($note === '' || $marker === '') {
			return false;
		}
		if (strpos($note, $marker) !== false) {
			return true;
		}
		$decoded = html_entity_decode($note, ENT_QUOTES | ENT_HTML5);
		return strpos($decoded, $marker) !== false;
	}

	/**
	 * Detect whether note already contains a successful refund marker for this credit note.
	 *
	 * Failed attempts should not block retries after config/code fixes.
	 *
	 * @param Facture $invoice Invoice object.
	 * @param string  $marker  Marker text.
	 * @return bool
	 */
	private function invoiceNoteHasSuccessfulRefundMarker(Facture $invoice, string $marker): bool
	{
		$note = (string) ($invoice->note_public ?? '');
		if ($note === '' || $marker === '') {
			return false;
		}
		$decoded = html_entity_decode($note, ENT_QUOTES | ENT_HTML5);
		$haystack = $note."\n".$decoded;

		// Must carry marker and clear success evidence.
		if (strpos($haystack, $marker) === false) {
			return false;
		}
		return (strpos($haystack, 'taler://refund/') !== false);
	}

	/**
	 * Append a line to credit-note public note without duplication.
	 *
	 * @param Facture $invoice Invoice object.
	 * @param string  $line    Line to append.
	 * @return void
	 */
	private function appendInvoiceNoteLine(Facture $invoice, string $line): void
	{
		$line = trim((string) preg_replace('/\s+/u', ' ', trim($line)));
		if ($line === '' || empty($invoice->id)) {
			return;
		}

		$existing = (string) ($invoice->note_public ?? '');
		$decoded = html_entity_decode($existing, ENT_QUOTES | ENT_HTML5);
		if (strpos($existing, $line) !== false || strpos($decoded, $line) !== false) {
			return;
		}

		$updated = rtrim($existing);
		if ($updated !== '') {
			$updated .= "\n";
		}
		$updated .= $line;

		$res = $invoice->update_note_public($updated);
		if ($res <= 0) {
			dol_syslog(__METHOD__.' failed to update invoice note for '.$invoice->id.': '.($invoice->error ?: 'unknown error'), LOG_WARNING);
			return;
		}
		$invoice->note_public = $updated;
	}

	/**
	 * Upsert a note line identified by a stable prefix.
	 *
	 * Existing lines starting with the same prefix are replaced.
	 *
	 * @param Facture $invoice Invoice object.
	 * @param string  $prefix  Stable line prefix.
	 * @param string  $line    Full line to store.
	 * @return void
	 */
	private function upsertInvoiceNoteLineByPrefix(Facture $invoice, string $prefix, string $line): void
	{
		$prefix = trim((string) preg_replace('/\s+/u', ' ', trim($prefix)));
		$line = trim((string) preg_replace('/\s+/u', ' ', trim($line)));
		if ($prefix === '' || $line === '' || empty($invoice->id)) {
			return;
		}

		$existing = (string) ($invoice->note_public ?? '');
		$lines = preg_split('/\R/u', $existing) ?: array();
		$kept = array();
		foreach ($lines as $rawLine) {
			$rawLine = (string) $rawLine;
			$trimmed = trim($rawLine);
			if ($trimmed === '') {
				continue;
			}
			$decoded = trim(html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5));
			if (strpos($trimmed, $prefix) === 0 || strpos($decoded, $prefix) === 0) {
				continue;
			}
			$kept[] = $trimmed;
		}
		$kept[] = $line;
		$updated = implode("\n", $kept);

		if (trim($updated) === trim($existing)) {
			return;
		}
		$res = $invoice->update_note_public($updated);
		if ($res <= 0) {
			dol_syslog(__METHOD__.' failed to update invoice note for '.$invoice->id.': '.($invoice->error ?: 'unknown error'), LOG_WARNING);
			return;
		}
		$invoice->note_public = $updated;
	}

	/**
	 * Build per-credit-note payout summary from Taler order status payload.
	 *
	 * @param array<string,mixed> $statusPayload Latest status payload.
	 * @param string              $creditRef     Credit-note reference.
	 * @param float               $creditAmount  Credit-note amount to refund.
	 * @return array<string,float>|null
	 */
	private static function summarizeCreditNotePayoutFromStatus(array $statusPayload, string $creditRef, float $creditAmount): ?array
	{
		$creditRef = trim($creditRef);
		if ($creditRef === '' || $creditAmount <= 0.0) {
			return null;
		}

		$matchedAny = false;
		$totalMatched = 0.0;
		$paidOut = 0.0;
		$details = $statusPayload['refund_details'] ?? null;
		if (is_array($details)) {
			foreach ($details as $detail) {
				if (!is_array($detail)) {
					continue;
				}
				$reason = trim((string) ($detail['reason'] ?? $detail['comment'] ?? ''));
				if ($reason !== '' && stripos($reason, $creditRef) === false) {
					continue;
				}
				$amountRaw = (string) ($detail['amount'] ?? $detail['refund_amount'] ?? '');
				$amountValue = TalerOrderLink::amountStringToFloat($amountRaw);
				if ($amountValue === null || $amountValue <= 0.0) {
					continue;
				}
				$matchedAny = true;
				$totalMatched += $amountValue;
				$isPending = !empty($detail['pending']);
				if (!$isPending) {
					$paidOut += $amountValue;
				}
			}
		}

		if (!$matchedAny) {
			$totalRaw = (string) ($statusPayload['refund_amount'] ?? $statusPayload['amount_refunded'] ?? '');
			$totalValue = TalerOrderLink::amountStringToFloat($totalRaw);
			if ($totalValue === null || $totalValue <= 0.0) {
				return null;
			}
			$totalMatched = $totalValue;
			$isPending = !empty($statusPayload['refund_pending']);
			$paidOut = $isPending ? 0.0 : $totalMatched;
		}

		$paidOut = max(0.0, min($creditAmount, $paidOut));
		$remaining = max(0.0, $creditAmount - $paidOut);

		return array(
			'paid_out' => $paidOut,
			'remaining' => $remaining,
		);
	}

	/**
	 * Extract currency prefix from CUR:value amount strings.
	 *
	 * @param string $amountString Amount candidate.
	 * @return string
	 */
	private static function extractCurrencyFromAmountString(string $amountString): string
	{
		$amountString = trim($amountString);
		if ($amountString === '' || strpos($amountString, ':') === false) {
			return '';
		}
		list($currency) = explode(':', $amountString, 2);
		return strtoupper(trim($currency));
	}

	/**
	 * Extract HTTP status code from merchant exception messages.
	 *
	 * @param Throwable $e Exception to inspect.
	 * @return int
	 */
	private static function extractHttpStatus(Throwable $e): int
	{
		$msg = (string) $e->getMessage();
		if (preg_match('/HTTP\s+([0-9]{3})\s+for/i', $msg, $m)) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Extract optional "hint/detail" from JSON payload embedded in exception text.
	 *
	 * @param Throwable $e Exception to inspect.
	 * @return string
	 */
	private static function extractHttpHint(Throwable $e): string
	{
		$msg = (string) $e->getMessage();
		$jsonPos = strpos($msg, '{');
		if ($jsonPos === false) {
			return '';
		}
		$decoded = json_decode(substr($msg, $jsonPos), true);
		if (!is_array($decoded)) {
			return '';
		}
		$hint = trim((string) ($decoded['hint'] ?? $decoded['detail'] ?? ''));
		return $hint;
	}

	/**
	 * Detect whether a refund conflict hint indicates currency/state mismatch.
	 *
	 * @param string $hint Hint extracted from backend error.
	 * @return bool
	 */
	private static function isCurrencyConflictHint(string $hint): bool
	{
		$normalized = strtolower(trim($hint));
		if ($normalized === '') {
			return false;
		}

		return (
			str_contains($normalized, 'currency specified in the operation')
			|| (str_contains($normalized, 'currency') && str_contains($normalized, 'current state'))
			|| str_contains($normalized, 'currency mismatch')
		);
	}

	/**
	 * Map a Dolibarr-facing currency to the Taler-facing one for refund calls.
	 *
	 * @param string            $currency Input currency candidate.
	 * @param TalerConfig|null  $config   Active configuration (for alias).
	 * @return string
	 */
	private function mapRefundCurrencyForTaler(string $currency, ?TalerConfig $config = null): string
	{
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
	}

	/**
	 * Resolve active Taler config (username + syncdirection).
	 * Returns ['username'=>'', 'syncdirection'=>null] if none found.
	 * We might want to change it to the fetchSingletonVerified from TalerConfig
	 *
	 * @return array
	 */
	private function getActiveTalerConfig(): array
	{
		$sql = "SELECT username, syncdirection
              	  FROM ".$this->db->prefix()."talerbarr_talerconfig
          		  ORDER BY rowid DESC
                  LIMIT 1";

		$res = $this->db->query($sql);
		if ($res && ($row = $this->db->fetch_object($res))) {
			return [
				'username'      => (string) $row->username,
				'syncdirection' => isset($row->syncdirection) ? (int) $row->syncdirection : null,
			];
		}

		return ['username' => '', 'syncdirection' => null];
	}


	/**
	 * Upsert or refresh a TalerProductLink row that corresponds to a Dolibarr
	 * Product (create if missing, update snapshot otherwise).
	 *
	 * @param Product $prod  Dolibarr product
	 * @param User    $user User performing the action
	 * @return int               1 OK, 0 nothing done, <0 SQL / functional error
	 */
	private function upsertProduct(Product $prod, User $user)
	{
		return TalerProductLink::upsertFromDolibarr($this->db, $prod, $user);
	}

	/**
	 * Delete the mapping row when a Dolibarr product is deleted.
	 *
	 * @param Product $prod Dolibarr product
	 * @param User    $user User performing the action
	 * @return int               1 OK, 0 not found, <0 error
	 */
	private function deleteProduct(Product $prod, User $user)
	{
		$cfg = $this->getActiveTalerConfig();

		// Ignore if no config or sync direction is Taler→Dolibarr
		if (empty($cfg['username']) || (string) $cfg['syncdirection'] === '1') {
			return 0;
		}

		$link = new TalerProductLink($this->db);
		$load = $link->fetchByProductId((int) $prod->id);
		if ($load <= 0) return $load;


		$res = $link->delete($user, 1);
		if ($res === 0 && $this->db->transaction_opened > 0) {
			$this->db->commit();
		}

		if ($res <= 0) {
			TalerErrorLog::recordArray(
				$this->db,
				[
				  'context'       => 'product',
				  'operation'     => 'delete',
				  'fk_product'    => $prod->id,
				  'error_message' => $link->error ?: 'DB error while delete'
				],
				$user
			);
			return -1;
		}
		return 1;
	}

	/**
	 * When a stock movement occurs we refresh the product snapshot so that the
	 * quantity cached in TalerProductLink stays coherent (optional but handy).
	 *
	 * @param MouvementStock $mvmt Movement of stock from Dolibarr
	 * @param User           $user User performing the action
	 * @return int                       same semantics as upsertProduct()
	 */
	private function touchProductFromMovement($mvmt, User $user)
	{
		$cfg = $this->getActiveTalerConfig();

		// Ignore if no config or sync direction is Taler→Dolibarr
		if (empty($cfg['username']) || (string) $cfg['syncdirection'] === '1') {
			return 0;
		}

		if (empty($mvmt->fk_product)) return 0;

		$prod = new Product($this->db);
		if ($prod->fetch((int) $mvmt->fk_product) <= 0) {
			TalerErrorLog::recordArray(
				$this->db,
				[
					'context'       => 'product',
					'operation'     => 'fetch',
					'fk_product'    => $mvmt->fk_product,
					'error_message' => $prod->error ?: 'Unable to fetch product from movement',
				],
				$user
			);
			return -1;
		}
		return $this->upsertProduct($prod, $user);
	}
}

if (!class_exists('modTalerBarr_TalerBarrTriggers', false) && class_exists('InterfaceTalerBarrTriggers', false)) {
	class_alias('InterfaceTalerBarrTriggers', 'modTalerBarr_TalerBarrTriggers');
}
