<?php
declare(strict_types=1);

/*
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
 * Hook actions for the TalerBarr module.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Class ActionsTalerBarr
 */
class ActionsTalerBarr extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler.
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Automatically refresh Taler payment status when opening an invoice card.
	 *
	 * @param array<string,mixed> $parameters Hook metadata (context, etc.).
	 * @param CommonObject        $object     Current object (Facture in this context).
	 * @param ?string             $action     Current action string (unused).
	 * @param HookManager         $hookmanager Hook manager instance.
	 *
	 * @return int 0 to continue default processing, <0 on error.
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager): int
	{
		global $user;

		if (empty($parameters['currentcontext']) || !in_array($parameters['currentcontext'], array('invoicecard'), true)) {
			return 0;
		}

		if (empty($object) || empty($object->id)) {
			return 0;
		}

		dol_include_once('/core/lib/date.lib.php');
		dol_include_once('/talerbarr/class/talerconfig.class.php');
		dol_include_once('/talerbarr/class/talerorderlink.class.php');
		dol_include_once('/talerbarr/class/talermerchantclient.class.php');

		$invoiceId = (int) $object->id;
		dol_syslog('ActionsTalerBarr::doActions invoicecard begin invoice_id='.$invoiceId, LOG_DEBUG);
		$commandeId = $this->resolveCommandeIdForInvoice($invoiceId, $object);

		$link = new TalerOrderLink($this->db);
		$resLink = $link->fetchByInvoiceOrOrder($invoiceId, $commandeId);
		if ($resLink <= 0 || empty($link->taler_order_id)) {
			dol_syslog('ActionsTalerBarr::doActions skip (no taler link) invoice_id='.$invoiceId, LOG_DEBUG);
			return 0;
		}

		$intendedCode = (string) ($link->intended_payment_code ?? '');
		if ($intendedCode !== '' && strcasecmp($intendedCode, 'TLR') !== 0) {
			dol_syslog('ActionsTalerBarr::doActions skip (payment code '.$intendedCode.' not TLR) invoice_id='.$invoiceId, LOG_DEBUG);
			return 0;
		}

		$lastCheckTs = 0;
		if (!empty($link->last_status_check_at)) {
			$lastCheckTs = (int) dol_stringtotime($link->last_status_check_at);
		}
		if ($lastCheckTs > 0 && (dol_now() - $lastCheckTs) < 60) {
			dol_syslog('ActionsTalerBarr::doActions skip (throttled) invoice_id='.$invoiceId, LOG_DEBUG);
			return 0;
		}

		$cfgErr = null;
		$config = TalerConfig::fetchSingletonVerified($this->db, $cfgErr);
		if (!$config || empty($config->verification_ok) || empty($config->talermerchanturl) || empty($config->talertoken)) {
			dol_syslog('ActionsTalerBarr::doActions skip config invalid invoice_id='.$invoiceId.' reason=' . ($cfgErr ?: 'unknown'), LOG_DEBUG);
			return 0;
		}

		$instance = (string) ($link->taler_instance ?? $config->username ?? '');
		if ($instance === '') {
			$instance = (string) $config->username;
		}

		try {
			$client = new TalerMerchantClient((string) $config->talermerchanturl, (string) $config->talertoken, $instance);
			$statusPayload = $client->getOrderStatus((string) $link->taler_order_id);
			if (!is_array($statusPayload)) {
				$statusPayload = (array) $statusPayload;
			}
			$statusPayload['order_id'] = $statusPayload['order_id'] ?? $statusPayload['orderId'] ?? $link->taler_order_id;
			$statusPayload['merchant_instance'] = $statusPayload['merchant_instance']
				?? ($statusPayload['merchant']['instance'] ?? $statusPayload['merchant']['id'] ?? null)
				?? $instance;
		} catch (Throwable $e) {
			dol_syslog('ActionsTalerBarr::doActions unable to refresh Taler order '.$link->taler_order_id.': '.$e->getMessage(), LOG_WARNING);
			return 0;
		}

		try {
			TalerOrderLink::upsertFromTalerOfPayment($this->db, $statusPayload, $user);
			dol_syslog('ActionsTalerBarr::doActions refreshed Taler order '.$link->taler_order_id.' for invoice '.$invoiceId, LOG_DEBUG);
		} catch (Throwable $e) {
			dol_syslog('ActionsTalerBarr::doActions unable to persist Taler order '.$link->taler_order_id.': '.$e->getMessage(), LOG_ERR);
		}

		dol_syslog('ActionsTalerBarr::doActions invoicecard end invoice_id='.$invoiceId, LOG_DEBUG);
		return 0;
	}

	/**
	 * Resolve related commande id for a given invoice.
	 *
	 * @param int          $invoiceId Facture rowid.
	 * @param CommonObject $invoice   Facture object.
	 *
	 * @return int Commande rowid if available, 0 otherwise.
	 */
	private function resolveCommandeIdForInvoice(int $invoiceId, $invoice): int
	{
		if (!empty($invoice->fk_commande)) {
			return (int) $invoice->fk_commande;
		}

		if (!empty($invoice->linkedObjectsIds['commande'])) {
			$ids = $invoice->linkedObjectsIds['commande'];
			if (is_array($ids) && count($ids) > 0) {
				$first = array_key_first($ids);
				if ($first !== null) {
					return (int) $first;
				}
			}
		}

		$sql = sprintf(
			"SELECT fk_target FROM %selement_element WHERE fk_source = %d AND sourcetype = 'facture' AND targettype = 'commande' ORDER BY rowid DESC LIMIT 1",
			MAIN_DB_PREFIX,
			$invoiceId
		);
		$resql = $this->db->query($sql);
		if ($resql) {
			if ($obj = $this->db->fetch_object($resql)) {
				$this->db->free($resql);
				return (int) $obj->fk_target;
			}
			$this->db->free($resql);
		}

		$sql = sprintf(
			"SELECT fk_source FROM %selement_element WHERE fk_target = %d AND targettype = 'facture' AND sourcetype = 'commande' ORDER BY rowid DESC LIMIT 1",
			MAIN_DB_PREFIX,
			$invoiceId
		);
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
}
