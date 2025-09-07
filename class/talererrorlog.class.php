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
 * \file        class/talererrorlog.class.php
 * \ingroup     talerbarr
 * \brief       CRUD class for TalerBarr error log
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
// require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * Class TalerErrorLog
 *
 * Stores synchronization / integration errors for the TalerBarr module.
 * Extends Dolibarr's CommonObject for standard CRUD behavior.
 */
class TalerErrorLog extends CommonObject
{
	/** @var string */
	public $module = 'talerbarr';

	/** @var string */
	public $element = 'talererrorlog';

	/** @var string */
	public $table_element = 'talerbarr_error_log';

	/** @var string */
	public $picto = 'error';

	/** @var int */
	public $isextrafieldmanaged = 0;

	/** @var int|string */
	public $ismultientitymanaged = 1; // 'entity' field is present

	// ===== Fields (mirror of SQL) =====
	public $fields = array(
		'rowid'             => array('type'=>'integer',  'label'=>'TechnicalID', 'visible'=>0, 'notnull'=>1, 'index'=>1, 'position'=>1),
		'entity'            => array('type'=>'integer',  'label'=>'Entity',      'visible'=>0, 'notnull'=>1, 'default'=>1, 'index'=>1, 'position'=>5),

		'context'           => array('type'=>'varchar(32)', 'label'=>'Context', 'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>10,
			'arrayofkeyval'=>array(
				'product'=>'Product','category'=>'Category','order'=>'Order','tax'=>'Tax','image'=>'Image','auth'=>'Auth','other'=>'Other'
			)
		),
		'operation'         => array('type'=>'varchar(64)', 'label'=>'Operation', 'visible'=>1, 'notnull'=>0, 'position'=>11,
			'arrayofkeyval'=>array(
				'create'=>'Create','update'=>'Update','delete'=>'Delete','relink'=>'Relink','sync'=>'Sync','fetch'=>'Fetch'
			)
		),
		'direction_is_push' => array('type'=>'integer', 'label'=>'DirectionPush', 'visible'=>1, 'notnull'=>0, 'position'=>12,
			'arrayofkeyval'=>array('1'=>'PullTalerToDoli','0'=>'PushDoliToTaler')
		),

		'fk_product_link'   => array('type'=>'integer', 'label'=>'FkProductLink', 'visible'=>1, 'notnull'=>0, 'index'=>1, 'position'=>20),
		'fk_product'        => array('type'=>'integer:Product:product/class/product.class.php', 'label'=>'Product', 'visible'=>1, 'notnull'=>0, 'index'=>1, 'position'=>21, 'picto'=>'product'),
		'taler_instance'    => array('type'=>'varchar(64)',  'label'=>'TalerInstance',  'visible'=>1, 'notnull'=>0, 'index'=>1, 'position'=>22),
		'taler_product_id'  => array('type'=>'varchar(128)', 'label'=>'TalerProductId', 'visible'=>1, 'notnull'=>0, 'index'=>1, 'position'=>23),
		'external_ref'      => array('type'=>'varchar(128)', 'label'=>'ExternalRef',    'visible'=>1, 'notnull'=>0, 'position'=>24),

		'http_status'       => array('type'=>'integer',      'label'=>'HttpStatus',     'visible'=>1, 'notnull'=>0, 'position'=>30),
		'error_code'        => array('type'=>'varchar(64)',  'label'=>'ErrorCode',      'visible'=>1, 'notnull'=>0, 'position'=>31),
		'error_message'     => array('type'=>'text',         'label'=>'ErrorMessage',   'visible'=>1, 'notnull'=>1, 'position'=>32),
		'payload_json'      => array('type'=>'text',         'label'=>'PayloadJSON',    'visible'=>0, 'notnull'=>0, 'position'=>33),

		'datec'             => array('type'=>'datetime',     'label'=>'DateCreation',   'visible'=>-2, 'notnull'=>0, 'position'=>500),
		'tms'               => array('type'=>'timestamp',    'label'=>'DateModification','visible'=>-2, 'notnull'=>1, 'position'=>501),
	);

	// Public properties (for IDE hints)
	public $rowid, $entity, $context, $operation, $direction_is_push;
	public $fk_product_link, $fk_product, $taler_instance, $taler_product_id, $external_ref;
	public $http_status, $error_code, $error_message, $payload_json;
	public $datec, $tms;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler.
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		foreach ($this->fields as $k => $v) {
			if (isset($v['enabled']) && empty($v['enabled'])) unset($this->fields[$k]);
		}
	}

	/* ================== CRUD ================== */
	/**
	 * Create record.
	 *
	 * @param User $user       User performing the creation (used by triggers/audit).
	 * @param int  $notrigger  Set to 1 to disable triggers.
	 * @return int             >0 on success, <0 on error.
	 */
	public function create(User $user, $notrigger = 1)
	{
		if (empty($this->entity)) $this->entity = (int) getEntity($this->element, 1);
		if (empty($this->datec))  $this->datec  = dol_now();
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch a record by id/ref.
	 *
	 * @param int|string $id             Row ID (preferred) or technical ref.
	 * @param string|null $ref           Optional ref if you need to fetch by ref.
	 * @param int $noextrafields         1 to skip extrafields fetch for performance.
	 * @param int $nolines               Unused here (compat with CommonObject).
	 * @return int                       >0 if OK, <0 on error, 0 if not found.
	 */
	public function fetch($id, $ref = null, $noextrafields = 1, $nolines = 1)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Update record.
	 *
	 * @param User $user       User performing the update.
	 * @param int  $notrigger  Set to 1 to disable triggers.
	 * @return int             >0 on success, <0 on error, 0 if no change.
	 */
	public function update(User $user, $notrigger = 1)
	{
		// Normally you do not update logs; still provided for completeness.
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete record.
	 *
	 * @param User $user       User performing the deletion.
	 * @param int  $notrigger  Set to 1 to disable triggers.
	 * @return int             >0 on success, <0 on error.
	 */
	public function delete(User $user, $notrigger = 1)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	// ================== Helpers ==================

	/**
	 * Quick factory to record an error log (transaction-less).
	 * Provide only what you have; nulls are accepted for optional fields.
	 *
	 * @param DoliDB      $db              Database handler.
	 * @param User|null   $user            User performing the action (or null for system).
	 * @param string      $context         Logical context: product, category, order, tax, image, auth, other.
	 * @param string|null $operation       Operation name: create, update, delete, relink, sync, fetch.
	 * @param bool|null   $directionIsPush True if push Doli→Taler, false if pull Taler→Doli, null if n/a.
	 * @param int|null    $fkProductLink   FK to talerproductlink row if known.
	 * @param int|null    $fkProduct       FK to Dolibarr product if known.
	 * @param string|null $talerInstance   Taler instance identifier (string key).
	 * @param string|null $talerProductId  Taler-side product ID.
	 * @param string|null $externalRef     Any other external reference.
	 * @param int|null    $httpStatus      HTTP status code if from an HTTP call.
	 * @param string|null $errorCode       Optional module/provider error code.
	 * @param string      $errorMessage    Human-readable error message (required).
	 * @param string|null $payloadJson     Raw JSON payload that caused/illustrates error.
	 * @return int                         >0 row id on success, <0 on error.
	 */
	public static function record(
		DoliDB $db,
		User $user = null,
		string $context,
		?string $operation,
		?bool $directionIsPush,
		?int $fkProductLink,
		?int $fkProduct,
		?string $talerInstance,
		?string $talerProductId,
		?string $externalRef,
		?int $httpStatus,
		?string $errorCode,
		string $errorMessage,
		?string $payloadJson = null
	): int {
		$log = new self($db);
		$log->entity            = (int) getEntity($log->element, 1);
		$log->context           = $context;
		$log->operation         = $operation;
		$log->direction_is_push = is_null($directionIsPush) ? null : ($directionIsPush ? 1 : 0);
		$log->fk_product_link   = $fkProductLink;
		$log->fk_product        = $fkProduct;
		$log->taler_instance    = $talerInstance;
		$log->taler_product_id  = $talerProductId;
		$log->external_ref      = $externalRef;
		$log->http_status       = $httpStatus;
		$log->error_code        = $errorCode;
		$log->error_message     = $errorMessage;
		$log->payload_json      = $payloadJson;
		$log->datec             = dol_now();

		$res = $log->create($user ?: (object) array('id'=>0), 1);
		return ($res > 0) ? (int) $log->id : -1;
	}

	/**
	 * Convenience wrapper that accepts an associative array.
	 * Keys: context, operation, direction_is_push(bool|null), fk_product_link, fk_product,
	 * taler_instance, taler_product_id, external_ref, http_status, error_code, error_message, payload_json
	 *
	 * @param DoliDB    $db    Database handler.
	 * @param array     $data  Input data (see keys above).
	 * @param User|null $user  User performing the action (or null for system).
	 * @return int             >0 row id on success, <0 on error.
	 */
	public static function recordArray(DoliDB $db, array $data, User $user = null): int
	{
		return self::record(
			$db,
			$user,
			(string) ($data['context'] ?? 'other'),
			$data['operation'] ?? null,
			array_key_exists('direction_is_push', $data) ? (is_null($data['direction_is_push']) ? null : (bool) $data['direction_is_push']) : null,
			isset($data['fk_product_link']) ? (int) $data['fk_product_link'] : null,
			isset($data['fk_product']) ? (int) $data['fk_product'] : null,
			$data['taler_instance'] ?? null,
			$data['taler_product_id'] ?? null,
			$data['external_ref'] ?? null,
			isset($data['http_status']) ? (int) $data['http_status'] : null,
			$data['error_code'] ?? null,
			(string) ($data['error_message'] ?? 'Unknown error'),
			$data['payload_json'] ?? null
		);
	}

	/**
	 * Fetch last N logs, optionally filtered.
	 *
	 * @param int         $limit         Number of rows to return (default 50).
	 * @param string|null $context       Filter by context or null for all.
	 * @param int|null    $fkProductLink Filter by product link FK or null.
	 * @param int|null    $fkProduct     Filter by product FK or null.
	 * @param string|null $talerInstance Filter by Taler instance or null.
	 * @return array<int,self>|int       Array of TalerErrorLog indexed by id, or <0 on SQL error.
	 */
	public function fetchRecent(int $limit = 50, ?string $context = null, ?int $fkProductLink = null, ?int $fkProduct = null, ?string $talerInstance = null)
	{
		$out = array();

		$sql = "SELECT ".$this->getFieldList('t');
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as t";
		$sql .= " WHERE t.entity IN (".getEntity($this->element).")";

		if ($context !== null)       $sql .= " AND t.context = '".$this->db->escape($context)."'";
		if ($fkProductLink !== null) $sql .= " AND t.fk_product_link = ".((int) $fkProductLink);
		if ($fkProduct !== null)     $sql .= " AND t.fk_product = ".((int) $fkProduct);
		if ($talerInstance !== null) $sql .= " AND t.taler_instance = '".$this->db->escape($talerInstance)."'";

		$sql .= " ORDER BY t.datec DESC, t.rowid DESC";
		$sql .= $this->db->plimit(max(1, $limit), 0);

		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return -1; }

		while ($obj = $this->db->fetch_object($res)) {
			$rec = new self($this->db);
			$rec->setVarsFromFetchObj($obj);
			$out[$rec->id] = $rec;
		}
		$this->db->free($res);

		return $out;
	}

	/**
	 * Purge logs older than a given timestamp.
	 *
	 * @param int $olderThanTs UNIX timestamp; delete rows with datec older than this.
	 * @return int             >=0 number of deleted rows, <0 on error.
	 */
	public function purgeOlderThan(int $olderThanTs)
	{
		$sql = "DELETE FROM ".$this->db->prefix().$this->table_element.
			" WHERE entity IN (".getEntity($this->element).") AND datec < '".$this->db->idate($olderThanTs)."'";
		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return -1; }
		return (int) $this->db->affected_rows($res);
	}

	/* ================== UI helpers ================== */
	/**
	 * Build object link for lists and cards.
	 *
	 * @param int    $withpicto            0 = no picto, 1 = picto + label, 2 = picto only.
	 * @param string $option               'nolink' to disable anchor; anything else enables link.
	 * @param int    $notooltip            1 to disable tooltip (kept for API compatibility).
	 * @param string $morecss              Extra CSS classes to add to the link.
	 * @param int    $save_lastsearch_value Keep -1 to use default behavior.
	 * @return string                      HTML for object link.
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		$label = 'TalerErrorLog';
		$url = dol_buildpath('/talerbarr/talererrorlog_card.php', 1).'?id='.(int) $this->id;

		$linkstart = ($option == 'nolink' || empty($url)) ? '<span>' : '<a href="'.$url.'">';
		$linkend   = ($option == 'nolink' || empty($url)) ? '</span>' : '</a>';

		$out = $linkstart;
		if ($withpicto) {
			$out .= img_object('', $this->picto, $withpicto != 2 ? 'class="paddingright"' : '');
		}
		if ($withpicto != 2) {
			$out .= dol_escape_htmltag($this->getLabelForList());
		}
		$out .= $linkend;

		return $out;
	}

	/**
	 * Human-friendly label for list rows.
	 *
	 * @return string Readable label including id, context/operation and error metadata.
	 */
	public function getLabelForList(): string
	{
		$ctx  = $this->context ?: 'other';
		$op   = $this->operation ?: '';
		$http = $this->http_status !== null ? 'HTTP '.$this->http_status : '';
		$code = $this->error_code ?: '';
		$rid  = '#'.$this->rowid;
		$right = trim(($http ? $http.' ' : '').$code);
		$left  = trim($ctx.($op ? '/'.$op : ''));
		return trim($rid.' '.$left.($right ? ' — '.$right : ''));
	}
}
