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
 * \file        class/talerproductlink.class.php
 * \ingroup     talerbarr
 * \brief       CRUD class for product links between Dolibarr and Taler
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

require_once __DIR__.'/talerconfig.class.php';
require_once __DIR__.'/talermerchantclient.class.php';
require_once __DIR__.'/talererrorlog.class.php';

class TalerProductLink extends CommonObject
{
	/** @var string */
	public $module = 'talerbarr';

	/** @var string */
	public $element = 'talerproductlink';

	/** @var string */
	public $table_element = 'talerbarr_product_link';

	/** @var string */
	public $picto = 'fa-link';

	/** @var int */
	public $isextrafieldmanaged = 0;

	/** @var int|string */
	public $ismultientitymanaged = 1; // entity field is present

	// BEGIN FIELDS MAP (mirror of SQL)
	public $fields = array(
		'rowid'                 => array('type'=>'integer', 'label'=>'TechnicalID', 'visible'=>0, 'notnull'=>1, 'index'=>1, 'position'=>1),
		'entity'                => array('type'=>'integer', 'label'=>'Entity', 'visible'=>0, 'notnull'=>1, 'default'=>1, 'index'=>1, 'position'=>5),

		'fk_product'            => array('type'=>'integer:Product:product/class/product.class.php', 'label'=>'Product', 'visible'=>1, 'notnull'=>0, 'index'=>1, 'position'=>10, 'picto'=>'product'),
		'product_ref_snap'      => array('type'=>'varchar(64)', 'label'=>'ProductRefSnap', 'visible'=>1, 'notnull'=>0, 'position'=>12, 'enabled'=>'1'),
		'product_tms_snap'      => array('type'=>'datetime', 'label'=>'ProductTMSSnap', 'visible'=>0, 'notnull'=>0, 'position'=>13, 'enabled'=>'1'),

		'taler_instance'        => array('type'=>'varchar(64)', 'label'=>'TalerInstance', 'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>20, 'enabled'=>'1'),
		'taler_product_id'      => array('type'=>'varchar(128)', 'label'=>'TalerProductId', 'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>21, 'enabled'=>'1'),
		'taler_product_name' 	=> array('type'=>'varchar(128)', 'label'=>'TalerProdName', 'visible'=>1, 'notnull'=>1, 'position'=>22, 'enabled'=>'1'),
		'taler_description'     => array('type'=>'varchar(2048)', 'label'=>'TalerDescription', 'visible'=> 1, 'notnull'=> 1, 'position'=>23, 'enabled'=>'1'),

		'taler_amount_str'      => array('type'=>'varchar(64)', 'label'=>'TalerAmountStr', 'visible'=>1, 'notnull'=>0, 'position'=>30, 'help'=>'e.g. EUR:12.34', 'enabled'=>'1'),
		'taler_currency'        => array('type'=>'varchar(16)', 'label'=>'Currency', 'visible'=>1, 'notnull'=>0, 'position'=>31, 'enabled'=>'1'),
		'taler_value'           => array('type'=>'integer', 'label'=>'MajorUnits', 'visible'=>1, 'notnull'=>0, 'position'=>32, 'help'=>'Integer units'),
		'taler_fraction'        => array('type'=>'integer', 'label'=>'Fraction1e8', 'visible'=>1, 'notnull'=>0, 'position'=>33, 'help'=>'0..99,999,999'),
		'price_is_ttc'          => array('type'=>'boolean', 'label'=>'PriceTTC', 'visible'=>1, 'notnull'=>1, 'default'=>1, 'position'=>34),

		'fk_unit'               => array('type'=>'integer', 'label'=>'Unit', 'visible'=>1, 'notnull'=>0, 'position'=>40),
		'taler_total_stock'     => array('type'=>'integer', 'label'=>'TotalStock', 'visible'=>1, 'notnull'=>0, 'position'=>41, 'default'=>-1, 'help'=>'-1 means infinite', 'enabled'=>'1'),
		'taler_total_sold'      => array('type'=>'integer', 'label'=>'TotalSold', 'visible'=>1, 'notnull'=>0, 'position'=>42, 'enabled'=>'1'),
		'taler_total_lost'      => array('type'=>'integer', 'label'=>'TotalLost', 'visible'=>1, 'notnull'=>0, 'position'=>43, 'enabled'=>'1'),

		'taler_categories_json' => array('type'=>'text', 'label'=>'TalerCategoriesJSON', 'visible'=>1, 'notnull'=>0, 'position'=>50, 'enabled'=>'1'),
		'taler_taxes_json'      => array('type'=>'text', 'label'=>'TalerTaxesJSON', 'visible'=>1, 'notnull'=>0, 'position'=>51, 'enabled'=>'1'),
		'taler_address_json'    => array('type'=>'text', 'label'=>'TalerAddressJSON', 'visible'=>1, 'notnull'=>0, 'position'=>52, 'enabled'=>'1'),
		'taler_image_hash'      => array('type'=>'varchar(64)', 'label'=>'ImageHash', 'visible'=>0, 'notnull'=>0, 'position'=>53),
		'taler_next_restock'    => array('type'=>'datetime', 'label'=>'NextRestock', 'visible'=>1, 'notnull'=>0, 'position'=>54, 'enabled'=>'1'),
		'taler_minimum_age'     => array('type'=>'integer', 'label'=>'MinimumAge', 'visible'=>1, 'notnull'=>0, 'position'=>55, 'enabled'=>'1'),

		'sync_enabled'          => array('type'=>'boolean', 'label'=>'SyncEnabled', 'visible'=>1, 'notnull'=>1, 'default'=>1, 'position'=>60, 'index'=>1),
		'syncdirection_override'=> array('type'=>'integer', 'label'=>'SyncDirectionOverride', 'visible'=>1, 'notnull'=>0, 'position'=>61, 'arrayofkeyval'=>array('1'=>'PullTalerToDoli','0'=>'PushDoliToTaler')),

		'lastsync_is_push'      => array('type'=>'integer', 'label'=>'LastSyncIsPush', 'visible'=>0, 'notnull'=>0, 'position'=>70, 'arrayofkeyval'=>array('0'=>'Pull','1'=>'Push')),
		'last_sync_status'      => array('type'=>'varchar(16)', 'label'=>'LastSyncStatus', 'visible'=>1, 'notnull'=>0, 'position'=>71, 'arrayofkeyval'=>array('ok'=>'OK','error'=>'Error','conflict'=>'Conflict')),
		'last_sync_at'          => array('type'=>'datetime', 'label'=>'LastSyncAt', 'visible'=>1, 'notnull'=>0, 'position'=>72),
		'last_error_code'       => array('type'=>'varchar(64)', 'label'=>'LastErrorCode', 'visible'=>0, 'notnull'=>0, 'position'=>73),
		'last_error_message'    => array('type'=>'text', 'label'=>'LastErrorMessage', 'visible'=>0, 'notnull'=>0, 'position'=>74),

		'checksum_d_hex'        => array('type'=>'varchar(64)', 'label'=>'ChecksumD', 'visible'=>0, 'notnull'=>0, 'position'=>80),
		'checksum_t_hex'        => array('type'=>'varchar(64)', 'label'=>'ChecksumT', 'visible'=>0, 'notnull'=>0, 'position'=>81),

		'datec'                 => array('type'=>'datetime', 'label'=>'DateCreation', 'visible'=>-2, 'notnull'=>0, 'position'=>500),
		'tms'                   => array('type'=>'timestamp', 'label'=>'DateModification', 'visible'=>-2, 'notnull'=>1, 'position'=>501),
	);

	// Public properties generated from $fields (for IDE help)
	public $rowid, $entity, $fk_product, $product_ref_snap, $product_tms_snap;
	public $taler_instance, $taler_product_id, $taler_product_name, $taler_description;
	public $taler_amount_str, $taler_currency, $taler_value, $taler_fraction, $price_is_ttc;
	public $fk_unit, $taler_total_stock, $taler_total_sold, $taler_total_lost;
	public $taler_categories_json, $taler_taxes_json, $taler_address_json, $taler_image_hash, $taler_next_restock, $taler_minimum_age;
	public $sync_enabled, $syncdirection_override;
	public $lastsync_is_push, $last_sync_status, $last_sync_at, $last_error_code, $last_error_message;
	public $checksum_d_hex, $checksum_t_hex, $datec, $tms;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db  Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;

		// Hide rowid in UI unless MAIN_SHOW_TECHNICAL_ID
		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid'])) {
			$this->fields['rowid']['visible'] = 0;
		}

		// Drop fields disabled by conditions (none here)
		foreach ($this->fields as $k => $v) {
			if (isset($v['enabled']) && empty($v['enabled'])) unset($this->fields[$k]);
		}
	}

	/* *************** CRUD *************** */
	/**
	 * Create record in database
	 *
	 * @param User $user       User performing the action
	 * @param int  $notrigger  1=do not call triggers
	 * @return int             >0 if OK, <0 if error
	 */
	public function create(User $user, $notrigger = 0)
	{
		if (empty($this->entity)) $this->entity = (int) getEntity($this->element, 1);
		// Default datec
		if (empty($this->datec))  $this->datec = dol_now();
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch object from database
	 *
	 * @param int         $id             Rowid
	 * @param string|null $ref            Optional reference
	 * @param int         $noextrafields  1 = do not load extrafields
	 * @param int         $nolines        1 = do not load lines
	 * @return int                        >0 if OK, 0 if not found, <0 if error
	 */
	public function fetch($id, $ref = null, $noextrafields = 1, $nolines = 1)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Update object into database
	 *
	 * @param User $user       User performing the action
	 * @param int  $notrigger  1=do not call triggers
	 * @return int             >0 if OK, <0 if error
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object from database
	 *
	 * @param User $user       User performing the action
	 * @param int  $notrigger  1=do not call triggers
	 * @return int             >0 if OK, <0 if error
	 */
	public function delete(User $user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/* *************** Finders *************** */

	/**
	 * Fetch by Dolibarr product id
	 *
	 * @param int $fk_product  Dolibarr product rowid
	 * @return int             >0 if OK, 0 if not found, <0 if error
	 */
	public function fetchByProductId(int $fk_product): int
	{
		$sql  = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity($this->element).") AND fk_product = ".((int)$fk_product)." LIMIT 1";
		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return -1; }
		if ($this->db->num_rows($res) == 0) return 0;
		$obj = $this->db->fetch_object($res);
		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Fetch by Taler instance and product id
	 *
	 * @param string $instance  Taler instance
	 * @param string $pid       Taler product id
	 * @return int              >0 if OK, 0 if not found, <0 if error
	 */
	public function fetchByInstancePid(string $instance, string $pid): int
	{
		$sql  = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity($this->element).")";
		$sql .= " AND taler_instance='".$this->db->escape($instance)."'";
		$sql .= " AND taler_product_id='".$this->db->escape($pid)."'";
		$sql .= " LIMIT 1";
		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return -1; }
		if ($this->db->num_rows($res) == 0) return 0;
		$obj = $this->db->fetch_object($res);
		return $this->fetch((int) $obj->rowid);
	}


	/* ******** High-level upsert helpers *************** */

	/**
	 * Idempotent upsert driven by a *Dolibarr* product.
	 *
	 * @param DoliDB           $db
	 * @param Product          $prod
	 * @param User             $user
	 * @param ?TalerConfig     $cfg   (optional) pre-loaded config
	 *
	 * @return int  1 = OK, 0 = ignored (no active cfg / pull-only),
	 *             -1 = functional/SQL error (already logged)
	 */
	public static function upsertFromDolibarr(
		DoliDB        $db,
		Product       $prod,
		User          $user,
		?TalerConfig  $cfg = null
	): int
	{
		/* ---------------------------------------------------------
     	 * 1) Resolve / verify config
     	 * ------------------------------------------------------ */
		if ($cfg === null) {                                // legacy path
			$cfgErr = null;
			$cfg    = TalerConfig::fetchSingletonVerified($db, $cfgErr);
			if (!$cfg || !$cfg->verification_ok) {
				return 0;                                   // nothing to do
			}
		} else {
			// Caller supplied a config – make sure it is OK.
			if (empty($cfg->verification_ok)) {
				$err = null;
				if (!$cfg->verifyConfig($err)) {            // soft-check if not done
					return 0;
				}
			}
		}


		/*  syncdirection semantics:
     	 *    1 = pull-only (Taler → Dolibarr)  → ignore this push
     	 */
		if ((string)$cfg->syncdirection === '1') {
			return 0;                                       // pull-only
		}
		$instance = (string)$cfg->username;

		/* ---------------------------------------------------------
     	 * 2) Load or create the link row
     	 * ------------------------------------------------------ */
		$link = new self($db);
		$load = $link->fetchByProductId((int)$prod->id);
		if ($load < 0) {
			TalerErrorLog::recordArray($db, [
				'context'       => 'product',
				'operation'     => 'fetch',
				'fk_product'    => $prod->id,
				'error_message' => $link->error ?: 'DB error while fetchByProductId',
			], $user);
			return -1;
		}

		/* ---------------------------------------------------------
		 * 3) Mirror Dolibarr → link
		 * ------------------------------------------------------ */
		$detail = $link->talerDetailFromDolibarrProduct($prod, ['instance'=>$instance]);
		$link->prepareFromDolibarrAndTalerDetail($prod, $detail, ['instance'=>$instance]);

		/* ---------------------------------------------------------
     	 * 4) Persist link
     	 * ------------------------------------------------------ */
		$res = ($load > 0) ? $link->update($user, 1)
			: (function () use ($link, $user) {
				$link->entity       = (int) getEntity($link->element, 1);
				$link->sync_enabled = 1;
				return $link->create($user, 1);
			})();

		if ($res <= 0) {
			TalerErrorLog::recordArray($db, [
				'context' => 'product',
				'operation' => ($load > 0 ? 'update' : 'create'),
				'fk_product' => $prod->id,
				'error_message' => $link->error ?: 'DB error while saving link',
				'payload_json' => json_encode(['detail'=>$detail],
					JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
			], $user);
			return -1;
		}

		/* ---------------------------------------------------------
     	 * 5) Push the product to the Taler Merchant Backend
     	 * ------------------------------------------------------ */
		$newDHash = self::_checksumDoli($prod);

		$unchanged = ($load > 0)
			&& $link->checksum_d_hex === $newDHash
			&& $link->last_sync_status === 'ok';

		if ($unchanged)
			return 1;

		$link->checksum_d_hex = $newDHash;
		$link->update($user, 1);

		if ($link->syncdirection_override !== null && (string)$link->syncdirection_override === '1')
		  	return 1; // sync disabled for this product, treat as success(skip)

		$pushOk = $link->pushToTaler($user, $prod);

		return $pushOk ? 1 : -1;
	}

	/**
	 * Idempotent upsert driven by a *Taler* ProductDetail array/object.
	 *
	 * @param array|object $detail  – full JSON object returned by the backend
	 * @param array        $opts    – ['instance'=>string] REQUIRED, plus
	 *                                optional ['write_dolibarr'=>bool]
	 * @return int same semantics as above
	 */
	public static function upsertFromTaler(
		DoliDB $db,
			   $detail,
		User   $user,
		array  $opts = []
	): int
	{
		$instance = (string) ($opts['instance'] ?? '');
		if ($instance === '') return -1;

		$pid = (string) ($detail['product_id'] ?? $detail->product_id ?? '');
		if ($pid === '') return -1;

		// 1. Find or create the link row
		$link = new self($db);
		$load = $link->fetchByInstancePid($instance, $pid);
		if ($load < 0) {
			TalerErrorLog::recordArray($db, [
				'context' => 'product', 'operation' => 'fetch',
				'taler_instance' => $instance, 'taler_product_id' => $pid,
				'error_message' => $link->error ?: 'DB error while fetchByInstancePid',
			], $user);
			return -1;
		}

		// 2. Ensure Dolibarr product exists / is refreshed (if requested)
		$writeDoli = $opts['write_dolibarr'] ?? true;
		if ($writeDoli) {
			if ($link->fk_product) {
				$prod = new Product($db);
				if ($prod->fetch($link->fk_product) > 0) {
					// light refresh (price / stock) – extend as needed
					$fields = self::dolibarrArrayFromTalerDetail($detail);
					if ($fields['price_ttc'] !== null) {
						$prod->price_ttc = $fields['price_ttc'];
						$prod->price     = $fields['price'];
					}
					if (isset($detail['total_stock']))
						$prod->stock_reel = (int) $detail['total_stock'];
					$prod->update($user);
				}
			} else {
				$link->fk_product = $link
					->createDolibarrProductFromTalerDetail($detail, $user, [
						'instance'=>$instance,
						'taler_product_id'=>$pid,
						'create_link'=>false,
					]);
			}
		}

		// 3. Mirror remote state → link
		$link->taler_instance     = $instance;
		$link->taler_product_id   = $pid;
		$link->taler_product_name = isset($detail['product_name']) ? substr($detail['product_name'],0,128) : null;
		$link->taler_description  = array_key_exists('description',$detail)
			? substr((string)$detail['description'],0,2048)
			: null;
		$link->taler_amount_str   = $detail['price']        ?? null;
		$link->taler_total_stock  = $detail['total_stock']  ?? null;
		$link->taler_categories_json = json_encode($detail['categories'] ?? []);
		if (!empty($detail['taxes']) && is_array($detail['taxes']) && class_exists('TalerTaxMap')) {
			foreach ($detail['taxes'] as $oneTax) {
				TalerTaxMap::upsertFromTaler($db, $user, $instance, $oneTax);
			}
			$link->taler_taxes_json = json_encode($detail['taxes']);
		}

		$link->fillPriceFromAmountStr();

		$res = ($load > 0) ? $link->update($user, 1) : $link->create($user, 1);
		if ($res <= 0) {
			TalerErrorLog::recordArray($db, [
				'context'=>'product',
				'operation'=> ($load>0?'update':'create'),
				'taler_instance'=>$instance, 'taler_product_id'=>$pid,
				'error_message'=>$link->error ?: 'DB error while saving link',
			], $user);
			return -1;
		}
		return 1;
	}

	/* *************** Helpers for sync *************** */

	/**
	 * Parse Taler amount string
	 *
	 * @param string $amountStr  Amount string (aka "CUR:12.34")
	 * @return array             ['currency'=>string,'value'=>int|null,'fraction'=>int|null]
	 */
	public static function parseTalerAmount(string $amountStr): array
	{
		$amountStr = trim($amountStr);
		$currency = ''; $value = null; $fraction = null;

		if ($amountStr === '' || strpos($amountStr, ':') === false) {
			return array('currency'=>'', 'value'=>null, 'fraction'=>null);
		}
		list($cur, $amt) = explode(':', $amountStr, 2);
		$currency = strtoupper(trim($cur));
		$amt = trim($amt);

		if (!preg_match('~^\d+(\.\d+)?$~', $amt)) {
			return array('currency'=>$currency, 'value'=>null, 'fraction'=>null);
		}
		$parts = explode('.', $amt, 2);
		$maj = (int) $parts[0];
		$fracStr = isset($parts[1]) ? $parts[1] : '0';
		// scale fraction to 8 digits
		if (strlen($fracStr) > 8) $fracStr = substr($fracStr, 0, 8);
		$fracStr = str_pad($fracStr, 8, '0', STR_PAD_RIGHT);
		$frac = (int) $fracStr;

		return array('currency'=>$currency, 'value'=>$maj, 'fraction'=>$frac);
	}

	/**
	 * Populate price fields from taler_amount_str
	 *
	 * @return void
	 */
	public function fillPriceFromAmountStr(): void
	{
		if (!dol_strlen($this->taler_amount_str)) return;
		$parsed = self::parseTalerAmount($this->taler_amount_str);
		if ($parsed['currency']) $this->taler_currency = $parsed['currency'];
		if ($parsed['value'] !== null) $this->taler_value = (int) $parsed['value'];
		if ($parsed['fraction'] !== null) $this->taler_fraction = (int) $parsed['fraction'];
	}

	/**
	 * Save Dolibarr product reference + timestamp snapshot
	 *
	 * @param Product $prod  Dolibarr product
	 * @return void
	 */
	public function setDolibarrSnapshot($prod): void
	{
		// $prod is instance of Product
		if (is_object($prod)) {
			$this->fk_product       = (int) $prod->id;
			$this->product_ref_snap = (string) $prod->ref;
			// $prod->tms may be a timestamp or date string; rely on db->idate to normalize
			$this->product_tms_snap = $prod->tms ? $this->db->idate($prod->tms) : null;
		}
	}

	/**
	 * Compute SHA-256 hex of normalized data
	 *
	 * @param array|object|string $data  Input data
	 * @return string                    Hash string
	 */
	public static function computeSha256Hex($data): string
	{
		if (is_array($data) || is_object($data)) {
			$json = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_NUMERIC_CHECK);
		} else {
			$json = (string) $data;
		}
		return hash('sha256', $json);
	}

	/**
	 * Mark synchronization result
	 *
	 * @param bool      $isPush   True if push, false if pull
	 * @param string    $status   Sync status
	 * @param string|null $code   Optional error code
	 * @param string|null $message Optional error message
	 * @param int|null    $ts     Optional timestamp
	 * @return void
	 */
	public function markSyncResult(bool $isPush, string $status, ?string $code = null, ?string $message = null, ?int $ts = null): void
	{
		$this->lastsync_is_push = $isPush ? 1 : 0;
		$this->last_sync_status = $status;
		$this->last_sync_at     = $ts ? $this->db->idate($ts) : $this->db->idate(dol_now());
		$this->last_error_code  = $code;
		$this->last_error_message = $message;
	}

	/* *************** Helpers ******************************************* */

	/**
	 * Resolve instance from TalerConfig if available
	 *
	 * @return string  Instance or ''
	 */
	private function resolveInstanceFromConfig(): string
	{
		$instance = '';
		if (class_exists('TalerConfig')) {
			// Prefer a fetchSingleton-like helper when available
			if (method_exists('TalerConfig','fetchSingletonVerified')) {
				$err = null;
				$cfg = TalerConfig::fetchSingletonVerified($this->db, $err);
				if ($cfg && !empty($cfg->username)) $instance = (string)$cfg->username;
			} else {
				// Fallback: try to load the first active config row (best-effort)
				$sql = "SELECT username FROM ".$this->db->prefix()."talerbarr_talerconfig WHERE entity IN (".getEntity('talerconfig',1).") ORDER BY rowid DESC";
				$res = $this->db->query($sql);
				if ($res && ($obj = $this->db->fetch_object($res))) $instance = (string)$obj->username;
			}
		}
		return $instance;
	}

	/**
	 * Convert a numeric price to the Taler “amount” string, e.g. 12.5 EUR → "EUR:12.50".
	 *
	 * @param float  $price      Positive decimal amount (major units)
	 * @param string $currency   ISO-4217 code, case-insensitive
	 * @param int    $scale      Max decimals to keep (Taler allows up to 8)
	 * @return string            Formatted "<CUR>:<value>" string
	 */
	public static function amountStrFromPrice(float $price, string $currency, int $scale = 2): string
	{
		// Guard against negatives and scientific notation
		$price     = max(0.0, $price);
		$currency  = strtoupper(trim($currency)) ?: 'EUR';

		// Keep exactly $scale decimals, then trim trailing zeroes and the dot (".")
		$raw       = number_format($price, $scale, '.', '');
		$trimmed   = rtrim(rtrim($raw, '0'), '.');
		if ($trimmed === '') {
			$trimmed = '0';
		}

		return $currency . ':' . $trimmed;
	}


	/**
	 * Build Taler amount string from float
	 *
	 * @param float  $price
	 * @param string $currency
	 * @return string
	 */
	public static function talerAmountFromFloat(float $price, string $currency): string
	{
		return self::amountStrFromPrice($price, $currency); // uses 2 decimals
	}

	/**
	 * Get unit code from fk_unit
	 *
	 * @param int|null $fk_unit
	 * @return string
	 */
	private function resolveUnitCodeById(?int $fk_unit): string
	{
		if (empty($fk_unit)) return '';
		$sql = "SELECT code FROM ".$this->db->prefix()."c_units WHERE rowid=".(int)$fk_unit." AND active=1";
		$res = $this->db->query($sql);
		if ($res && ($o = $this->db->fetch_object($res))) return (string)$o->code;
		return '';
	}

	/**
	 * Get unit id from code
	 *
	 * @param string|null $code
	 * @return int|null
	 */
	private function resolveUnitIdByCode(?string $code): ?int
	{
		$code = trim((string)$code);
		if ($code === '') return null;
		$sql = "SELECT rowid FROM ".$this->db->prefix()."c_units WHERE code='".$this->db->escape($code)."' AND active=1";
		$res = $this->db->query($sql);
		if ($res && ($o = $this->db->fetch_object($res))) return (int)$o->rowid;
		return null;
	}

	/**
	 * Build a Taler ProductDetail array from a Dolibarr Product.
	 * Price is exported as TTC Amount (as required by Taler).
	 *
	 * @param Product         $prod
	 * @param array           $opts ['instance'=>string]  // optional, not embedded in ProductDetail but used for category mapping
	 * @return array ProductDetail
	 */
	public function talerDetailFromDolibarrProduct(Product $prod, array $opts = []): array
	{
		global $conf;

		$currency = !empty($conf->currency) ? strtoupper($conf->currency) : 'EUR';
		// Prefer price_ttc; if zero, fall back to HT (and treat as TTC if no VAT info)
		$priceTtc = isset($prod->price_ttc) ? (float)$prod->price_ttc : 0.0;
		$price    = ($priceTtc > 0) ? $priceTtc : (isset($prod->price) ? (float)$prod->price : 0.0);

		$unitCode = $this->resolveUnitCodeById(property_exists($prod,'fk_unit') ? (int)$prod->fk_unit : null);

		// Categories: best effort via mapping table, else empty
		$talerCats = [];
		$instance  = isset($opts['instance']) && $opts['instance'] !== '' ? (string)$opts['instance'] : $this->resolveInstanceFromConfig();
		if ($instance && class_exists('TalerCategoryMap')) {
			// Get local categories for this product
			$sql = "SELECT c.rowid as fk_categorie
				FROM ".$this->db->prefix()."categorie_product cp
				JOIN ".$this->db->prefix()."categorie c ON c.rowid=cp.fk_categorie
				WHERE cp.fk_product=".(int)$prod->id;
			$res = $this->db->query($sql);
			if ($res) {
				while ($o = $this->db->fetch_object($res)) {
					$map = new TalerCategoryMap($this->db);
					// Try a reasonable API; ignore failures
					if (method_exists($map,'fetchByDoliCatAndInstance')) {
						if ($map->fetchByDoliCatAndInstance((int)$o->fk_categorie, $instance) > 0 && !empty($map->taler_category_id)) {
							$talerCats[] = (int)$map->taler_category_id;
						}
					} elseif (method_exists($map,'fetchByDoliCatId')) {
						if ($map->fetchByDoliCatId((int)$o->fk_categorie, $instance) > 0 && !empty($map->taler_category_id)) {
							$talerCats[] = (int)$map->taler_category_id;
						}
					}
				}
			}
		}

		$detail = [
			'product_name'      => (string) (!empty($prod->label) ? $prod->label : $prod->ref),
			'description'       => (string) ($prod->description ?? ''),
			'description_i18n'  => (object)[], // left empty; multilingual handled elsewhere
			'unit'              => $unitCode,
			'categories'        => array_values(array_unique($talerCats)),
			'price'             => self::talerAmountFromFloat(max(0.0,$price), $currency), // TTC as required
			'image'             => null,        // not exported by default
			'taxes'             => $this->buildTaxesForDolibarrProduct($prod, $currency, $instance) ?: null,
			'total_stock'       => property_exists($prod,'stock_reel') ? (int)$prod->stock_reel : 0,
			'total_sold'        => 0,
			'total_lost'        => 0,
			'address'           => null,
			'next_restock'      => null,
			'minimum_age'       => null,
		];

		return $detail;
	}

	/**
	 * Create a Dolibarr product "field array" from a Taler ProductDetail.
	 * Since Taler price is TTC when non-zero, we set price_base_type='TTC' and compute HT if VAT is provided.
	 *
	 * @param array|object $detail  ProductDetail
	 * @param float|null   $vatRate Optional override VAT rate (percentage) if you want to force a specific VAT
	 * @return array  Dolibarr product fields
	 */
	public static function dolibarrArrayFromTalerDetail($detail, ?float $vatRate = null): array
	{
		$src = is_object($detail) ? (array)$detail : (array)$detail;

		$name   = isset($src['product_name']) ? (string)$src['product_name'] : '';
		$desc   = isset($src['description']) ? (string)$src['description'] : '';
		$priceS = isset($src['price']) ? (string)$src['price'] : ''; // Taler Amount as string "CUR:12.34"
		$parsed = self::parseTalerAmount($priceS);

		// VAT: try to derive from taxes[] if present and looks like a %; else use provided $vatRate; else null
		if ($vatRate === null && !empty($src['taxes']) && is_array($src['taxes'])) {
			// Heuristic: first tax that has 'percent' or 'rate' field
			$tax0 = (array) $src['taxes'][0];
			if (isset($tax0['percent'])) $vatRate = (float)$tax0['percent'];
			elseif (isset($tax0['rate'])) $vatRate = (float)$tax0['rate'];
		}

		$price_ttc = null; $price_ht = null;
		if ($parsed['value'] !== null) {
			$price_ttc = (float)$parsed['value'] + ((float)($parsed['fraction'] ?? 0))/100000000.0;
			if ($vatRate !== null && $vatRate > 0) {
				$price_ht = $price_ttc / (1.0 + $vatRate/100.0);
			} else {
				$price_ht = $price_ttc;
			}
		}

		$unitCode = isset($src['unit']) ? (string)$src['unit'] : '';

		return [
			'ref'             => dol_string_nospecial(trim($name)) ?: dol_string_nospecial(substr(sha1($name.microtime(true)),0,8)),
			'label'           => $name,
			'description'     => $desc,
			'price'           => ($price_ht   !== null ? price2num($price_ht,'MT')   : null),
			'price_ttc'       => ($price_ttc  !== null ? price2num($price_ttc,'MT')  : null),
			'price_base_type' => 'TTC',          // Taler price is TTC
			'tva_tx'          => ($vatRate !== null ? $vatRate : null),
			'type'            => defined('Product::TYPE_PRODUCT') ? Product::TYPE_PRODUCT : 0,
			// fk_unit resolved by caller because it requires DB; see createDolibarrProductFromTalerDetail()
			'_unit_code'      => $unitCode,
			'_categories'     => isset($src['categories']) && is_array($src['categories']) ? $src['categories'] : [],
			'_extras'         => [
				'price_amount_str' => $priceS,
				'address'          => isset($src['address']) ? $src['address'] : null,
				'next_restock'     => isset($src['next_restock']) ? $src['next_restock'] : null,
				'minimum_age'      => isset($src['minimum_age']) ? (int)$src['minimum_age'] : null,
				'taxes'            => isset($src['taxes']) ? $src['taxes'] : null,
			],
		];
	}

	/**
	 * Create a Dolibarr Product from a Taler ProductDetail and (optionally) create the link row.
	 * If no config/instance is available, the product is still created and we skip creating the link.
	 *
	 * @param array|object $detail   ProductDetail
	 * @param User         $user
	 * @param array        $opts     ['instance'=>string, 'taler_product_id'=>string, 'create_link'=>bool (true)]
	 * @return int                   Product ID (>0) or -1 on error
	 */
	public function createDolibarrProductFromTalerDetail($detail, User $user, array $opts = []): int
	{
		$this->db->begin();

		try {
			$fields = self::dolibarrArrayFromTalerDetail($detail);
			$prod = new Product($this->db);

			foreach (['ref','label','description','price','price_ttc','price_base_type','tva_tx','type'] as $k) {
				if (array_key_exists($k,$fields) && $fields[$k] !== null) $prod->$k = $fields[$k];
			}

			// Resolve fk_unit by unit code if present
			if (!empty($fields['_unit_code'])) {
				$fk = $this->resolveUnitIdByCode($fields['_unit_code']);
				if ($fk !== null) $prod->fk_unit = $fk;
			}

			$pid = $prod->create($user);
			if ($pid <= 0) {
				$this->db->rollback();
				$this->error = $prod->error ?: $this->db->lasterror();
				return -1;
			}

			// Assign local categories if we can map back from Taler categories
			$instance = isset($opts['instance']) && $opts['instance'] !== '' ? (string)$opts['instance'] : $this->resolveInstanceFromConfig();
			if ($instance && !empty($fields['_categories']) && class_exists('TalerCategoryMap')) {
				foreach ($fields['_categories'] as $talCatId) {
					$talCatId = (int)$talCatId;
					if ($talCatId <= 0) continue;

					$map = new TalerCategoryMap($this->db);
					// Try both likely APIs
					$dolicat = null;
					if (method_exists($map,'fetchByInstanceCatId') && $map->fetchByInstanceCatId($instance, $talCatId) > 0 && !empty($map->fk_categorie)) {
						$dolicat = (int)$map->fk_categorie;
					} elseif (method_exists($map,'fetchByTalerCatId') && $map->fetchByTalerCatId($talCatId, $instance) > 0 && !empty($map->fk_categorie)) {
						$dolicat = (int)$map->fk_categorie;
					}
					if ($dolicat) {
						$cat = new Categorie($this->db);
						if ($cat->fetch($dolicat) > 0) $cat->add_type($pid, 'product'); // best-effort
					}
				}
			}

			// Optionally create the link row
			$createLink = array_key_exists('create_link',$opts) ? (bool)$opts['create_link'] : true;
			if ($createLink && $instance !== '') {
				$this->fk_product = (int)$pid;
				$this->setDolibarrSnapshot($prod);

				$this->taler_instance     = $instance;
				$this->taler_product_id   = isset($fields['product_id']) ? (string)$fields['product_id'] : ''; // may be empty if unknown at creation time
				$this->taler_product_name = substr($fields['product'], 0, 128);
				$this->taler_description  = substr((string)$fields['description'], 0, 2048);
				$this->taler_amount_str   = isset($fields['_extras']['price_amount_str']) ? (string)$fields['_extras']['price_amount_str'] : null;
				$this->price_is_ttc       = 1; // ProductDetail.price is TTC
				$this->fillPriceFromAmountStr();

				// Persist optional extras into dedicated columns
				if (isset($fields['_extras']['taxes']) && $fields['_extras']['taxes'] !== null) {
					$this->taler_taxes_json = json_encode($fields['_extras']['taxes']);
				}
				if (isset($fields['_categories']) && is_array($fields['_categories']) && count($fields['_categories'])) {
					$this->taler_categories_json = json_encode(array_values($fields['_categories']));
				}
				if (isset($fields['_extras']['address']) && $fields['_extras']['address'] !== null) {
					$this->taler_address_json = json_encode($fields['_extras']['address']);
				}
				if (isset($fields['_extras']['minimum_age']) && $fields['_extras']['minimum_age'] !== null) {
					$this->taler_minimum_age = (int)$fields['_extras']['minimum_age'];
				}
				if (!empty($fields['_extras']['next_restock'])) {
					$ts = is_numeric($fields['_extras']['next_restock']) ? (int)$fields['_extras']['next_restock'] : dol_stringtotime($fields['_extras']['next_restock']);
					if ($ts) $this->taler_next_restock = $this->db->idate($ts);
				}

				if (!isset($this->sync_enabled)) $this->sync_enabled = 1;

				$lid = $this->create($user, 1);
				if ($lid <= 0) {
					// don't rollback the product; just record the error
					$this->error = $this->error ?: $this->db->lasterror();
				}
			}

			$this->db->commit();
			return (int)$pid;
		} catch (Throwable $e) {
			$this->db->rollback();
			$this->error = $e->getMessage();
			return -1;
		}
	}

	/**
	 * Prepare this link row from a Dolibarr product AND a Taler ProductDetail (partial or full).
	 * Useful if you have both objects and want to mirror extra fields (taxes, address, etc.).
	 *
	 * @param Product $prod
	 * @param array|object|null $detail  ProductDetail
	 * @param array $opts ['instance'=>string]
	 * @return $this
	 */
	public function prepareFromDolibarrAndTalerDetail(Product $prod, $detail = null, array $opts = [])
	{
		global $conf;

		$this->setDolibarrSnapshot($prod);
		$this->taler_instance = isset($opts['instance']) && $opts['instance'] !== '' ? (string)$opts['instance'] : $this->resolveInstanceFromConfig();
		$this->taler_product_name = dol_trunc($prod->label ?: $prod->ref, 128);
		$this->taler_description  = dol_trunc((string) ($prod->description ?? ''), 2048);

		if (empty($this->taler_product_id)) {
			// Prefer product ref; sanitize to keep it URL/ID friendly
			$base = dol_string_nospecial((string) ($prod->ref ?? ''));
			if ($base === '') {
				// absolute fallback if ref is empty (rare): use id or a short hash
				$base = $prod->id ? ('pid'.$prod->id) : substr(sha1('doli'.microtime(true)), 0, 8);
			}
			$placeholder = 'doli_' . $base;

			// Respect DB column length (VARCHAR(128))
			if (dol_strlen($placeholder) > 128) {
				$placeholder = substr($placeholder, 0, 128);
			}
			$this->taler_product_id = $placeholder;
		}

		$currency = !empty($conf->currency) ? strtoupper($conf->currency) : 'EUR';
		$priceTtc = isset($prod->price_ttc) ? (float)$prod->price_ttc : 0.0;
		$this->taler_amount_str = self::talerAmountFromFloat(max(0.0,$priceTtc), $currency);
		$this->price_is_ttc = 1;
		$this->fillPriceFromAmountStr();

		if ($detail) {
			$src = is_object($detail) ? (array)$detail : (array)$detail;
			if (!empty($src['product_name'])) $this->taler_product_name = substr($src['product_name'], 0, 128);
			if (array_key_exists('description', $src))
				$this->taler_description = substr((string)$src['description'], 0, 2048);
			if (isset($src['categories']) && is_array($src['categories'])) $this->taler_categories_json = json_encode(array_values($src['categories']));
			if (isset($src['taxes'])) $this->taler_taxes_json = json_encode($src['taxes']);
			if (isset($src['address'])) $this->taler_address_json = json_encode($src['address']);
			if (isset($src['minimum_age'])) $this->taler_minimum_age = (int)$src['minimum_age'];
			if (!empty($src['next_restock'])) {
				$ts = is_numeric($src['next_restock']) ? (int)$src['next_restock'] : dol_stringtotime($src['next_restock']);
				if ($ts) $this->taler_next_restock = $this->db->idate($ts);
			}
		}

		return $this;
	}

	/**
	 * Backward-compatible payload wrapper
	 *
	 * @param Product      $prod
	 * @param TalerConfig|null $cfg
	 * @param array        $opts
	 * @return array
	 */
	public static function talerPayloadFromDolibarr(Product $prod, ?TalerConfig $cfg = null, array $opts = []): array
	{
		// delegate to ProductDetail mapper; $cfg unused, kept for signature compat
		return (new self($prod->db))->talerDetailFromDolibarrProduct($prod, $opts);
	}

	/**
	 * Build a spec-compliant Tax[] array from a Dolibarr product line.
	 */
	private function buildTaxesForDolibarrProduct(Product $prod, string $currency, string $instance): array
	{
		global $langs;

		$rate = isset($prod->tva_tx) ? (float) $prod->tva_tx : 0.0;
		if ($rate <= 0) return [];

		$priceTtc = (float) ($prod->price_ttc ?? 0);
		if ($priceTtc <= 0) return [];

		$priceHt  = $priceTtc / (1 + $rate / 100);
		$taxValue = $priceTtc - $priceHt;

		if (class_exists('TalerTaxMap') && !empty($prod->tva_tx_id)) {
			TalerTaxMap::upsertFromDolibarr($this->db, $GLOBALS['user'], (int)$prod->tva_tx_id, $instance);
		}


		return [[
			'name' => $langs->trans("VAT") . ' ' . rtrim(rtrim(sprintf('%.3f', $rate), '0'), '.') . '%',
			'tax'  => self::talerAmountFromFloat($taxValue, $currency),
		]];
	}

	private static function _canon(array $a): string
	{
		ksort($a);
		return json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
	}

	/* Compute SHA-256 on a subset of Dolibarr product fields */
	private static function _checksumDoli(Product $p): string
	{
		return hash('sha256', self::_canon([
			'ref'        => $p->ref,
			'label'      => $p->label,
			'description'=> $p->description,
			'price_ttc'  => (float)$p->price_ttc,
			'price'      => (float)$p->price,
			'stock'      => property_exists($p,'stock_reel') ? (int)$p->stock_reel : 0,
			'tms'        => $p->tms,
		]));
	}


	/* *************** UI helpers (optional minimal stubs) *************** */

	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $langs;
		$label = $langs->trans("TalerProductLink");
		$url = dol_buildpath('/talerbarr/talerproductlink_card.php', 1).'?id='.(int)$this->id;
		$linkstart = ($option == 'nolink' || empty($url)) ? '<span>' : '<a href="'.$url.'">';
		$linkend = ($option == 'nolink' || empty($url)) ? '</span>' : '</a>';

		$out = $linkstart;
		if ($withpicto) $out .= img_object('', $this->picto, $withpicto != 2 ? 'class="paddingright"' : '');
		if ($withpicto != 2) {
			$ref = $this->taler_product_id ?: ('#'.(int)$this->id);
			$out .= dol_escape_htmltag($ref);
		}
		$out .= $linkend;
		return $out;
	}

	/**
	 * Build a concise label for lists.
	 */
	public function getLabelForList(): string
	{
		$left = $this->taler_instance ? $this->taler_instance.'/' : '';
		$pid  = $this->taler_product_id ?: '?';
		$ref  = $this->product_ref_snap ?: ($this->fk_product ? 'PID '.$this->fk_product : '');
		return trim("$left$pid  ".$ref);
	}

	/**
	 * Build a ready-to-use TalerMerchantClient for this link.
	 *
	 * @param TalerConfig|null $cfg  Receives the config row (on success)
	 * @param string|null      $err  Receives error message (on failure)
	 * @return TalerMerchantClient|null
	 */
	private function getMerchantClient(?TalerConfig &$cfg = null, ?string &$err = null): ?TalerMerchantClient
	{
		$cfg = TalerConfig::fetchSingletonVerified($this->db, $err);
		if (!$cfg || !$cfg->verification_ok) {
			if (!$err) $err = $cfg ? ($cfg->verification_error ?: 'Config not verified') : 'No valid TalerConfig';
			return null;
		}
		if (strcasecmp($cfg->username, $this->taler_instance) !== 0) {
			$err = 'Config username/instance mismatch ('.$cfg->username.' ≠ '.$this->taler_instance.')';
			return null;
		}
		try {
			return new TalerMerchantClient($cfg->talermerchanturl, $cfg->talertoken);
		} catch (Throwable $e) {
			$err = 'Could not create merchant client: '.$e->getMessage();
			return null;
		}
	}

	/**
	 * Push (create or update) the linked Dolibarr product to the Taler inventory.
	 *
	 * @param User    $user
	 * @param Product $prod   Dolibarr product to export (already loaded)
	 * @return bool           true on success, false on failure (errors logged & set)
	 */
	public function pushToTaler(User $user, Product $prod): bool
	{
		$err = null;
		$client = $this->getMerchantClient($cfg, $err);
		if (!$client) {
			$this->markSyncResult(true, 'error', 'config', $err);
			return false;
		}

		// Build ProductAddDetail / ProductPatchDetail payload
		$detail = $this->talerDetailFromDolibarrProduct($prod, ['instance'=>$this->taler_instance]);
		$detail['product_id'] = $this->taler_product_id;

		$lastOkPush = ($this->lastsync_is_push === 1 && $this->last_sync_status === 'ok');

		try {
			if(!$lastOkPush){
				$client->addProduct( $detail);
			} else {
				unset($detail['product_id']);
				$client->updateProduct( $this->taler_product_id, $detail);
			}

			$this->markSyncResult(true, 'ok');

			$this->checksum_t_hex = self::computeSha256Hex($detail);
			$this->update($user, 1);

			return true;
		} catch (Exception $e) {
			// 409 means product exists – fall back to PATCH
			if (str_contains($e->getMessage(), 'HTTP 409') && !$lastOkPush) {
				try {
					unset($detail['product_id']); // PATCH must NOT include product_id
					$client->updateProduct( $this->taler_product_id, $detail);
					$this->markSyncResult(true, 'ok');
					return true;
				} catch (Exception $e2) {
					$err = $e2->getMessage();
				}
			// 404 means product doesn't exist - try to POST
			} elseif (str_contains($e->getMessage(), 'HTTP 404') && $lastOkPush) {
				try {
					$detail['product_id'] = $this->taler_product_id;
					$client->addProduct( $detail);
					$this->markSyncResult(true, 'ok');
					return true;
				} catch (Exception $e2) {
					$err = $e2->getMessage();
				}
			} else {
				$err = $e->getMessage();
			}
		}

		$this->markSyncResult(true, 'error', 'push', $err);
		$this->logTalerError('product', 'push', $err);
		return false;
	}

	/**
	 * Pull latest product data from Taler and (optionally) update Dolibarr.
	 *
	 * @param User  $user
	 * @param bool  $writeDoli  true = update or create Dolibarr product when missing
	 * @return bool             true on success, false on failure
	 */
	public function pullFromTaler(User $user, bool $writeDoli = true): bool
	{
		$err = null;
		$client = $this->getMerchantClient($cfg, $err);
		if (!$client) {
			$this->markSyncResult(false, 'error', 'config', $err);
			return false;
		}

		try {
			$detail = $client->getProduct( $this->taler_product_id);
		} catch (Exception $e) {
			$this->markSyncResult(false, 'error', 'fetch', $e->getMessage());
			$this->logTalerError('product', 'fetch', $e->getMessage());
			return false;
		}

		// If we have a linked Dolibarr product – refresh price/stock snapshot only.
		if ($this->fk_product && $writeDoli) {
			$prod = new Product($this->db);
			if ($prod->fetch($this->fk_product) > 0) {
				// minimal update (price + stock); extend as needed
				if (!empty($detail['price'])) {
					$p = self::parseTalerAmount($detail['price']);
					if ($p['value'] !== null) {
						$prod->price_ttc = $p['value'] + $p['fraction']/100000000.0;
						$prod->price     = $prod->price_ttc;   // keep TTC parity
					}
				}
				if (isset($detail['total_stock'])) $prod->stock_reel = (int)$detail['total_stock'];
				$prod->update($this->fk_product, $user);
				$this->setDolibarrSnapshot($prod);          // refresh cached ref + tms
			}
		} elseif (!$this->fk_product && $writeDoli) {
			// No product yet – create it and link
			$newProdId = $this->createDolibarrProductFromTalerDetail($detail, $user, [
				'instance'        => $this->taler_instance,
				'taler_product_id'=> $this->taler_product_id,
				'create_link'     => false,                // we’ll reuse this link row
			]);
			if ($newProdId > 0) {
				$this->fk_product = $newProdId;
			}
		}

		// Mirror essential remote fields into link row
		$this->taler_amount_str   = $detail['price'] ?? null;
		$this->taler_total_stock  = $detail['total_stock']     ?? null;
		$this->taler_total_sold   = $detail['total_sold']      ?? null;
		$this->taler_total_lost   = $detail['total_lost']      ?? null;
		$this->taler_categories_json = json_encode($detail['categories'] ?? []);
		$this->fillPriceFromAmountStr();

		$this->checksum_t_hex = self::computeSha256Hex($detail);

		$this->update($user, 1);

		$this->markSyncResult(false, 'ok');
		return true;
	}

	/**
	 * Delete the product on the Taler backend.
	 *
	 * @param User $user
	 * @return bool  true on 204, false otherwise
	 */
	public function deleteOnTaler(User $user): bool
	{
		$err = null;
		$client = $this->getMerchantClient($cfg, $err);
		if (!$client) {
			$this->markSyncResult(true, 'error', 'config', $err);
			return false;
		}
		try {
			$client->deleteProduct( $this->taler_product_id);
			$this->markSyncResult(true, 'ok');
			return true;
		} catch (Exception $e) {
			$this->markSyncResult(true, 'error', 'delete', $e->getMessage());
			$this->logTalerError('product', 'delete', $e->getMessage());
			return false;
		}
	}

	/**
	 * Lightweight wrapper to persist an error row (if the table/class exists).
	 *
	 * @param string $context    'product' | 'category' | ...
	 * @param string $operation  'push' | 'fetch' | ...
	 * @param string $message    human readable
	 * @return void
	 */
	private function logTalerError(string $context, string $operation, string $message): void
	{
		if (!class_exists('TalerErrorLog')) return;
		$err = new TalerErrorLog($this->db);
		$err->context          = $context;
		$err->operation        = $operation;
		$err->direction_is_push = ($operation === 'push' ? 1 : 0);
		$err->fk_product_link  = $this->id;
		$err->fk_product       = $this->fk_product;
		$err->taler_instance   = $this->taler_instance;
		$err->taler_product_id = $this->taler_product_id;
		$err->error_message    = dol_trunc($message, 65535);
		$err->datec            = dol_now();
		$err->create($GLOBALS['user'] ?? null, 1);   // no triggers
	}
}
