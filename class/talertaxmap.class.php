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

	public function create(User $user, $notrigger = 1)
	{
		if (empty($this->entity)) $this->entity = (int)getEntity($this->element, 1);
		if (empty($this->datec)) $this->datec = dol_now();
		return $this->createCommon($user, $notrigger);
	}

	public function fetch($id, $ref = null, $noextrafields = 1, $nolines = 1)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	public function update(User $user, $notrigger = 1)
	{
		return $this->updateCommon($user, $notrigger);
	}

	public function delete(User $user, $notrigger = 1)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/* ================== Finders ================== */

	/**
	 * Fetch by unique key (entity, taler_instance, taler_tax_name)
	 * @return int >0 if loaded, 0 if not found, <0 on SQL error
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
		return $this->fetch((int)$obj->rowid);
	}

	/**
	 * Fetch first row for a given fk_c_tva (many-to-one, but we give a convenience).
	 * @return int >0 if loaded, 0 if not found, <0 on SQL error
	 */
	public function fetchByCtvA(int $fkCTva): int
	{
		$sql = "SELECT rowid FROM " . $this->db->prefix() . $this->table_element;
		$sql .= " WHERE entity IN (" . getEntity($this->element) . ")";
		$sql .= " AND fk_c_tva = " . ((int)$fkCTva) . " LIMIT 1";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->num_rows($res) == 0) return 0;
		$obj = $this->db->fetch_object($res);
		return $this->fetch((int)$obj->rowid);
	}

	/* ================== Upsert ================== */

	/**
	 * Idempotent upsert by unique key (entity, instance, tax_name).
	 * Any param can be null to "leave as-is".
	 *
	 * @return int >0 row id, <0 on error
	 */
	public function upsert(
		User    $user,
		string  $instance,
		string  $taxName,
		?string $amountHint = null,
		?float  $vatRate = null,
		?int    $fkCTva = null
	)
	{
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
			if ($fkCTva !== null) $tmp->fk_c_tva = (int)$fkCTva;

			$res = $tmp->update($user, 1);
			if ($res <= 0) {
				$this->db->rollback();
				$this->error = $tmp->error;
				return -1;
			}
			$this->db->commit();
			return (int)$tmp->id;
		}

		// Insert new
		$this->entity = (int)getEntity($this->element, 1);
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
		return (int)$this->id;
	}

	/* ================== Helpers ================== */

	/**
	 * Parse a "CUR:amount" hint (e.g., "EUR:0.70").
	 * @return array{currency:string, amount:string}|null
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
	 * Returns float like 7.0 or null.
	 */
	public static function guessVatRateFromName(string $name): ?float
	{
		if (preg_match('~(\d+(?:[.,]\d+)?)\s*%~u', $name, $m)) {
			$val = str_replace(',', '.', $m[1]);
			return (float)$val;
		}
		return null;
	}

	/**
	 * Normalize a float rate to 3 decimals as stored in DB (e.g., 7 -> 7.000).
	 */
	public function normalizeRate(float $rate): float
	{
		// Ensure consistent rounding to 3 decimals
		return (float)number_format((float)$rate, 3, '.', '');
	}

	/**
	 * Try to resolve a c_tva id by exact/nearest taux.
	 * @param float $ratePercent e.g., 7.0
	 * @param float $tolerance e.g., 0.001 for exact, or 0.1 for near match
	 * @return int|null rowid or null if none
	 */
	public function resolveCtvAByRate(float $ratePercent, float $tolerance = 0.001): ?int
	{
		$rate = $this->normalizeRate($ratePercent);

		$sql = "SELECT rowid, taux FROM " . $this->db->prefix() . "c_tva";
		$sql .= " WHERE active = 1";
		// Some Dolibarr versions have recuperableonly; we ignore it for matching.
		$sql .= " ORDER BY ABS(taux - " . ((float)$rate) . ") ASC";
		$sql .= $this->db->plimit(1);

		$res = $this->db->query($sql);
		if (!$res) return null;

		$obj = $this->db->fetch_object($res);
		if (!$obj) return null;

		$diff = abs(((float)$obj->taux) - $rate);
		$this->db->free($res);

		return ($diff <= $tolerance) ? (int)$obj->rowid : null;
	}

	/* ================== UI helpers ================== */

	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		$url = dol_buildpath('/talerbarr/talertaxmap_card.php', 1) . '?id=' . (int)$this->id;

		$linkstart = ($option == 'nolink' || empty($url)) ? '<span>' : '<a href="' . $url . '">';
		$linkend = ($option == 'nolink' || empty($url)) ? '</span>' : '</a>';

		$out = $linkstart;
		if ($withpicto) $out .= img_object('', $this->picto, $withpicto != 2 ? 'class="paddingright"' : '');
		if ($withpicto != 2) $out .= dol_escape_htmltag($this->getLabelForList());
		$out .= $linkend;

		return $out;
	}

	public function getLabelForList(): string
	{
		$inst = $this->taler_instance ?: '?';
		$nm = $this->taler_tax_name ?: '?';
		$rt = ($this->vat_rate !== null && $this->vat_rate !== '') ? (rtrim(rtrim(sprintf('%.3f', (float)$this->vat_rate), '0'), '.')) . '%' : '';
		$fk = $this->fk_c_tva ? ' → c_tva#' . $this->fk_c_tva : '';
		return trim("$inst / $nm " . ($rt ? "($rt)" : '') . $fk);
	}
}

