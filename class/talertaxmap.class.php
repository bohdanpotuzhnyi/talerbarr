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
 * \file        class/talertaxmap.class.php
 * \ingroup     talerbarr
 * \brief       CRUD class for mapping Taler taxes to Dolibarr VAT dictionary
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

/**
 * Class TalerTaxMap
 *
 * Maintains a mapping between Taler-side tax definitions and Dolibarr's VAT dictionary
 * (`c_tva`). Provides CRUD, finders, and idempotent upsert helpers used by sync flows.
 */
class TalerTaxMap extends CommonObject
{
	/** @var string */
	public $module = 'talerbarr';

	/** @var string */
	public $element = 'talertaxmap';

	/** @var string */
	public $table_element = 'talerbarr_tax_map';

	/** @var string */
	public $picto = 'fa-percent';

	/** @var int */
	public $isextrafieldmanaged = 0;

	/** @var int|string */
	public $ismultientitymanaged = 1; // entity field exists

	// ===== Fields definition (mirror SQL) =====
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'visible' => 0, 'notnull' => 1, 'index' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'visible' => 0, 'notnull' => 1, 'default' => 1, 'index' => 1, 'position' => 5),

		'taler_instance' => array('type' => 'varchar(64)', 'label' => 'TalerInstance', 'visible' => 1, 'notnull' => 1, 'index' => 1, 'position' => 10),
		'taler_tax_name' => array('type' => 'varchar(128)', 'label' => 'TalerTaxName', 'visible' => 1, 'notnull' => 1, 'index' => 1, 'position' => 11),
		'taler_tax_amount_hint' => array('type' => 'varchar(64)', 'label' => 'TalerTaxAmountHint', 'visible' => 1, 'notnull' => 0, 'position' => 12, 'help' => 'e.g. EUR:0.70'),

		'vat_rate' => array('type' => 'double(6,3)', 'label' => 'VatRate', 'visible' => 1, 'notnull' => 0, 'position' => 20, 'help' => 'e.g. 7.000'),
		'fk_c_tva' => array('type' => 'integer', 'label' => 'FkCTva', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 21),

		'datec' => array('type' => 'datetime', 'label' => 'DateCreation', 'visible' => -2, 'notnull' => 0, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'visible' => -2, 'notnull' => 1, 'position' => 501),
	);

	// Public props (for IDE)
	public $rowid, $entity, $taler_instance, $taler_tax_name, $taler_tax_amount_hint, $vat_rate, $fk_c_tva, $datec, $tms;

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
		if (empty($this->datec)) $this->datec = dol_now();
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch a record by id/ref.
	 *
	 * @param int|string $id       Row ID (preferred) or technical ref.
	 * @param string|null $ref     Optional ref if fetching by reference.
	 * @param int $noextrafields   1 to skip extrafields fetch for performance.
	 * @param int $nolines         Unused here (CommonObject compatibility).
	 * @return int                 >0 if OK, 0 if not found, <0 on error.
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
	 * @return int             >0 on success, 0 if no change, <0 on error.
	 */
	public function update(User $user, $notrigger = 1)
	{
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

	/* ================== Finders ================== */

	/**
	 * Fetch by unique key (entity, taler_instance, taler_tax_name).
	 *
	 * @param string $instance Taler instance identifier.
	 * @param string $taxName  Tax name on the Taler side.
	 * @return int             >0 if loaded, 0 if not found, <0 on SQL error.
	 */
	public function fetchByInstanceName(string $instance, string $taxName): int
	{
		$sql = "SELECT rowid FROM " . $this->db->prefix() . $this->table_element;
		$sql .= " WHERE entity IN (" . getEntity($this->element) . ")";
		$sql .= " AND taler_instance = '" . $this->db->escape($instance) . "'";
		$sql .= " AND taler_tax_name = '" . $this->db->escape($taxName) . "'";
		$sql .= " LIMIT 1";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->num_rows($res) == 0) return 0;
		$obj = $this->db->fetch_object($res);
		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Fetch first row for a given fk_c_tva (many-to-one; convenience).
	 *
	 * @param int $fkCTva Rowid in c_tva.
	 * @return int        >0 if loaded, 0 if not found, <0 on SQL error.
	 */
	public function fetchByCtvA(int $fkCTva): int
	{
		$sql = "SELECT rowid FROM " . $this->db->prefix() . $this->table_element;
		$sql .= " WHERE entity IN (" . getEntity($this->element) . ")";
		$sql .= " AND fk_c_tva = " . ((int) $fkCTva) . " LIMIT 1";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->num_rows($res) == 0) return 0;
		$obj = $this->db->fetch_object($res);
		return $this->fetch((int) $obj->rowid);
	}

	/* ================== Upsert ================== */

	/**
	 * Idempotent upsert by unique key (entity, instance, tax_name).
	 * Any parameter can be null to "leave as-is" on update.
	 *
	 * @param User    $user       User performing the upsert.
	 * @param string  $instance   Taler instance identifier.
	 * @param string  $taxName    Tax name on Taler side.
	 * @param ?string $amountHint Optional amount hint "CUR:amount" (e.g., "EUR:0.70").
	 * @param ?float  $vatRate    VAT rate in percent (e.g., 7.0) or null.
	 * @param ?int    $fkCTva     Optional FK to c_tva row.
	 * @return int                >0 row id, <0 on error.
	 */
	public function upsert(
		User    $user,
		string  $instance,
		string  $taxName,
		?string $amountHint = null,
		?float  $vatRate = null,
		?int    $fkCTva = null
	) {
		$this->db->begin();

		$tmp = new self($this->db);
		$load = $tmp->fetchByInstanceName($instance, $taxName);
		if ($load < 0) {
			$this->db->rollback();
			$this->error = $tmp->error;
			return -1;
		}

		if ($load > 0) {
			if ($amountHint !== null) $tmp->taler_tax_amount_hint = $amountHint;
			if ($vatRate !== null) $tmp->vat_rate = $this->normalizeRate($vatRate);
			if ($fkCTva !== null) $tmp->fk_c_tva = (int) $fkCTva;

			$res = $tmp->update($user, 1);
			if ($res <= 0) {
				$this->db->rollback();
				$this->error = $tmp->error;
				return -1;
			}
			$this->db->commit();
			return (int) $tmp->id;
		}

		// Insert new
		$this->entity = (int) getEntity($this->element, 1);
		$this->taler_instance = $instance;
		$this->taler_tax_name = $taxName;
		$this->taler_tax_amount_hint = $amountHint;
		$this->vat_rate = ($vatRate !== null) ? $this->normalizeRate($vatRate) : null;
		$this->fk_c_tva = $fkCTva;
		$this->datec = dol_now();

		$res = $this->create($user, 1);
		if ($res <= 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return (int) $this->id;
	}

	/* =============== High-level upsert helpers === */
	/**
	 * Sync one Dolibarr VAT line into the mapping table.
	 *
	 * @param DoliDB     $db         Database handler.
	 * @param User       $user       Current user.
	 * @param int        $fkCTva     Rowid in c_tva.
	 * @param string     $instance   Taler instance.
	 * @param string|null $amountHint Optional “CUR:amount” hint (rarely used).
	 * @return int                   >0 rowid of talerbarr_tax_map, 0 ignored, <0 error.
	 */
	public static function upsertFromDolibarr(
		DoliDB $db,
		User   $user,
		int    $fkCTva,
		string $instance,
		?string $amountHint = null
	): int {
		if ($fkCTva <= 0) return 0;

		// ── grab the VAT line ───────────────────────────────────────────────
		$sql = "SELECT taux, note FROM ".$db->prefix()."c_tva WHERE rowid=".((int) $fkCTva);
		$res = $db->query($sql);
		if (!$res || !($row = $db->fetch_object($res))) return -1;

		$rate = (float) $row->taux;
		$map  = new self($db);

		// Build a deterministic name to keep a 1-to-1 mapping
		global $langs;
		$taxName = $langs->transnoentitiesnoconv("VAT").' '.rtrim(rtrim(sprintf('%.3f', $rate), '0'), '.').'%';

		return $map->upsert($user, $instance, $taxName, $amountHint, $rate, $fkCTva);
	}

	/**
	 * Sync one Tax object coming from Taler into the mapping table.
	 *
	 * @param DoliDB $db       Database handler.
	 * @param User   $user     Current user.
	 * @param string $instance Taler instance.
	 * @param array|object $tax One element of Taler’s “taxes” array (name/tax fields expected).
	 * @return int             >0 rowid, 0 ignored, <0 error.
	 */
	public static function upsertFromTaler(
		DoliDB $db,
		User   $user,
		string $instance,
		$tax
	): int {
		$arr = is_object($tax) ? (array) $tax : (array) $tax;
		$name = trim((string) ($arr['name'] ?? ''));
		if ($name === '') return 0;

		$rate = self::guessVatRateFromName($name);
		$amountHint = isset($arr['tax']) ? (string) $arr['tax'] : null;

		$tmp = new self($db);
		return $tmp->upsert($user, $instance, $name, $amountHint, $rate, null);
	}

	/* ================== Helpers ================== */

	/**
	 * Parse a "CUR:amount" hint (e.g., "EUR:0.70").
	 *
	 * @param string|null $s Raw hint string or null.
	 * @return array{currency:string, amount:string}|null Parsed result or null if invalid.
	 */
	public static function parseAmountHint(?string $s)
	{
		if (!dol_strlen($s)) return null;
		$parts = explode(':', trim($s), 2);
		if (count($parts) !== 2) return null;
		$cur = strtoupper(trim($parts[0]));
		$amt = trim($parts[1]);
		if ($cur === '' || $amt === '') return null;
		return array('currency' => $cur, 'amount' => $amt);
	}

	/**
	 * Try to guess VAT rate (percent) from "VAT 7%"-like names.
	 *
	 * @param string $name Tax name possibly containing a percentage.
	 * @return float|null  Rate in percent (e.g., 7.0) or null if no match.
	 */
	public static function guessVatRateFromName(string $name): ?float
	{
		if (preg_match('~(\d+(?:[.,]\d+)?)\s*%~u', $name, $m)) {
			$val = str_replace(',', '.', $m[1]);
			return (float) $val;
		}
		return null;
	}

	/**
	 * Normalize a float rate to 3 decimals as stored in DB (e.g., 7 -> 7.000).
	 *
	 * @param float $rate Raw rate in percent.
	 * @return float      Normalized rate with 3 decimals.
	 */
	public function normalizeRate(float $rate): float
	{
		// Ensure consistent rounding to 3 decimals
		return (float) number_format((float) $rate, 3, '.', '');
	}

	/**
	 * Try to resolve a c_tva id by exact/nearest taux.
	 *
	 * @param float $ratePercent Rate in percent, e.g., 7.0.
	 * @param float $tolerance   Allowed absolute delta, e.g., 0.001 for exact.
	 * @return int|null          Matching c_tva.rowid or null if none within tolerance.
	 */
	public function resolveCtvAByRate(float $ratePercent, float $tolerance = 0.001): ?int
	{
		$rate = $this->normalizeRate($ratePercent);

		$sql = "SELECT rowid, taux FROM " . $this->db->prefix() . "c_tva";
		$sql .= " WHERE active = 1";
		// Some Dolibarr versions have recuperableonly; we ignore it for matching.
		$sql .= " ORDER BY ABS(taux - " . ((float) $rate) . ") ASC";
		$sql .= $this->db->plimit(1);

		$res = $this->db->query($sql);
		if (!$res) return null;

		$obj = $this->db->fetch_object($res);
		if (!$obj) return null;

		$diff = abs(((float) $obj->taux) - $rate);
		$this->db->free($res);

		return ($diff <= $tolerance) ? (int) $obj->rowid : null;
	}

	/* ================== UI helpers ================== */

	/**
	 * Build object link for lists and cards.
	 *
	 * @param int    $withpicto             0 = no picto, 1 = picto + label, 2 = picto only.
	 * @param string $option                'nolink' to disable anchor; anything else enables link.
	 * @param int    $notooltip             1 to disable tooltip (kept for API compatibility).
	 * @param string $morecss               Extra CSS classes to add to the link.
	 * @param int    $save_lastsearch_value Keep -1 to use default behavior.
	 * @return string                       HTML for object link.
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		$url = dol_buildpath('/talerbarr/talertaxmap_card.php', 1) . '?id=' . (int) $this->id;

		$linkstart = ($option == 'nolink' || empty($url)) ? '<span>' : '<a href="' . $url . '">';
		$linkend = ($option == 'nolink' || empty($url)) ? '</span>' : '</a>';

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
	 * @return string Readable label: "<instance> / <name> (<rate>%) → c_tva#<id>".
	 */
	public function getLabelForList(): string
	{
		$inst = $this->taler_instance ?: '?';
		$nm = $this->taler_tax_name ?: '?';
		$rt = ($this->vat_rate !== null && $this->vat_rate !== '') ? (rtrim(rtrim(sprintf('%.3f', (float) $this->vat_rate), '0'), '.')) . '%' : '';
		$fk = $this->fk_c_tva ? ' → c_tva#' . $this->fk_c_tva : '';
		return trim("$inst / $nm " . ($rt ? "($rt)" : '') . $fk);
	}
}
