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
		'product_ref_snap'      => array('type'=>'varchar(64)', 'label'=>'ProductRefSnap', 'visible'=>1, 'notnull'=>0, 'position'=>12),
		'product_tms_snap'      => array('type'=>'datetime', 'label'=>'ProductTMSSnap', 'visible'=>0, 'notnull'=>0, 'position'=>13),

		'taler_instance'        => array('type'=>'varchar(64)', 'label'=>'TalerInstance', 'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>20),
		'taler_product_id'      => array('type'=>'varchar(128)', 'label'=>'TalerProductId', 'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>21),

		'taler_amount_str'      => array('type'=>'varchar(64)', 'label'=>'TalerAmountStr', 'visible'=>1, 'notnull'=>0, 'position'=>30, 'help'=>'e.g. EUR:12.34'),
		'taler_currency'        => array('type'=>'varchar(16)', 'label'=>'Currency', 'visible'=>1, 'notnull'=>0, 'position'=>31),
		'taler_value'           => array('type'=>'integer', 'label'=>'MajorUnits', 'visible'=>1, 'notnull'=>0, 'position'=>32, 'help'=>'Integer units'),
		'taler_fraction'        => array('type'=>'integer', 'label'=>'Fraction1e8', 'visible'=>1, 'notnull'=>0, 'position'=>33, 'help'=>'0..99,999,999'),
		'price_is_ttc'          => array('type'=>'boolean', 'label'=>'PriceTTC', 'visible'=>1, 'notnull'=>1, 'default'=>1, 'position'=>34),

		'fk_unit'               => array('type'=>'integer', 'label'=>'Unit', 'visible'=>1, 'notnull'=>0, 'position'=>40),
		'taler_total_stock'     => array('type'=>'integer', 'label'=>'TotalStock', 'visible'=>1, 'notnull'=>0, 'position'=>41, 'help'=>'-1 means infinite'),
		'taler_total_sold'      => array('type'=>'integer', 'label'=>'TotalSold', 'visible'=>0, 'notnull'=>0, 'position'=>42),
		'taler_total_lost'      => array('type'=>'integer', 'label'=>'TotalLost', 'visible'=>0, 'notnull'=>0, 'position'=>43),

		'taler_categories_json' => array('type'=>'text', 'label'=>'TalerCategoriesJSON', 'visible'=>0, 'notnull'=>0, 'position'=>50),
		'taler_taxes_json'      => array('type'=>'text', 'label'=>'TalerTaxesJSON', 'visible'=>0, 'notnull'=>0, 'position'=>51),
		'taler_address_json'    => array('type'=>'text', 'label'=>'TalerAddressJSON', 'visible'=>0, 'notnull'=>0, 'position'=>52),
		'taler_image_hash'      => array('type'=>'varchar(64)', 'label'=>'ImageHash', 'visible'=>0, 'notnull'=>0, 'position'=>53),
		'taler_next_restock'    => array('type'=>'datetime', 'label'=>'NextRestock', 'visible'=>0, 'notnull'=>0, 'position'=>54),
		'taler_minimum_age'     => array('type'=>'integer', 'label'=>'MinimumAge', 'visible'=>0, 'notnull'=>0, 'position'=>55),

		'sync_enabled'          => array('type'=>'boolean', 'label'=>'SyncEnabled', 'visible'=>1, 'notnull'=>1, 'default'=>1, 'position'=>60, 'index'=>1),
		'syncdirection_override'=> array('type'=>'integer', 'label'=>'SyncDirectionOverride', 'visible'=>1, 'notnull'=>0, 'position'=>61, 'arrayofkeyval'=>array('0'=>'PullTalerToDoli','1'=>'PushDoliToTaler')),

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
	public $taler_instance, $taler_product_id;
	public $taler_amount_str, $taler_currency, $taler_value, $taler_fraction, $price_is_ttc;
	public $fk_unit, $taler_total_stock, $taler_total_sold, $taler_total_lost;
	public $taler_categories_json, $taler_taxes_json, $taler_address_json, $taler_image_hash, $taler_next_restock, $taler_minimum_age;
	public $sync_enabled, $syncdirection_override;
	public $lastsync_is_push, $last_sync_status, $last_sync_at, $last_error_code, $last_error_message;
	public $checksum_d_hex, $checksum_t_hex, $datec, $tms;

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

	public function create(User $user, $notrigger = 0)
	{
		if (empty($this->entity)) $this->entity = (int) getEntity($this->element, 1);
		// Default datec
		if (empty($this->datec))  $this->datec = dol_now();
		return $this->createCommon($user, $notrigger);
	}

	public function fetch($id, $ref = null, $noextrafields = 1, $nolines = 1)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	public function delete(User $user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/* *************** Finders *************** */

	/**
	 * Fetch by Dolibarr product id (unique per entity per your schema)
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
	 * Fetch by (taler_instance, taler_product_id) (unique per entity)
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

	/* *************** Helpers for sync *************** */

	/**
	 * Parse "CUR:12.34" into currency + (value,fraction) with fraction scaled to 1e-8.
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
	 * Convenience to populate currency/value/fraction from taler_amount_str.
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
	 * Snap basic Dolibarr Product data (ref, tms) into *_snap columns.
	 * @param  Product $prod
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
	 * Compute hex SHA-256 of a normalized array/object.
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
	 * Mark sync outcome & (optionally) store last error snippet.
	 */
	public function markSyncResult(bool $isPush, string $status, ?string $code = null, ?string $message = null, ?int $ts = null): void
	{
		$this->lastsync_is_push = $isPush ? 1 : 0;
		$this->last_sync_status = $status;
		$this->last_sync_at     = $ts ? $this->db->idate($ts) : $this->db->idate(dol_now());
		$this->last_error_code  = $code;
		$this->last_error_message = $message;
	}

	/**
	 * Insert a row into llx_talerbarr_error_log for this link.
	 * Minimal dependencies; keep TEXT payload optional.
	 */
	public function logError(
		string $context,
		?string $operation,
		?bool $isPush,
		?int $httpStatus,
		?string $errCode,
		string $errMessage,
		?string $payloadJson = null,
		?string $externalRef = null
	): int {
		$table = $this->db->prefix().'talerbarr_error_log';
		$sql = "INSERT INTO $table(".
			"entity, context, operation, direction_is_push, fk_product_link, fk_product, taler_instance, taler_product_id, ".
			"http_status, error_code, error_message, payload_json, external_ref, datec".
			") VALUES (".
			((int) ($this->entity?:getEntity($this->element,1))).",".
			"'".$this->db->escape($context)."',".
			($operation !== null ? "'".$this->db->escape($operation)."'" : "NULL").",".
			($isPush === null ? "NULL" : ((int)$isPush)).",".
			($this->id ? (int)$this->id : "NULL").",".
			($this->fk_product ? (int)$this->fk_product : "NULL").",".
			($this->taler_instance ? "'".$this->db->escape($this->taler_instance)."'" : "NULL").",".
			($this->taler_product_id ? "'".$this->db->escape($this->taler_product_id)."'" : "NULL").",".
			($httpStatus !== null ? (int)$httpStatus : "NULL").",".
			($errCode ? "'".$this->db->escape($errCode)."'" : "NULL").",".
			"'".$this->db->escape($errMessage)."',".
			($payloadJson ? "'".$this->db->escape($payloadJson)."'" : "NULL").",".
			($externalRef ? "'".$this->db->escape($externalRef)."'" : "NULL").",".
			"'".$this->db->idate(dol_now())."'".
			")";
		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return -1; }
		return (int) $this->db->last_insert_id($table);
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
}
