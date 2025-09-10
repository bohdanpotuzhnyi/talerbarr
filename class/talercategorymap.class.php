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
 * \file        class/talercategorymap.class.php
 * \ingroup     talerbarr
 * \brief       CRUD class for mapping Taler categories to Dolibarr categories
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
// require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

/**
 * Class TalerCategoryMap
 *
 * Maps Taler (Merchant Backend) category ids/names to Dolibarr categories.
 * Provides CRUD helpers and idempotent upsert by either side of the mapping.
 *
 * @package    TalerBarr
 */
class TalerCategoryMap extends CommonObject
{
	/** @var string */
	public $module = 'talerbarr';

	/** @var string */
	public $element = 'talercategorymap';

	/** @var string */
	public $table_element = 'talerbarr_category_map';

	/** @var string */
	public $picto = 'category';

	/** @var int */
	public $isextrafieldmanaged = 0;

	/** @var int|string */
	public $ismultientitymanaged = 1; // we have an entity field

	// Fields definition
	public $fields = array(
		'rowid'                => array('type'=>'integer',  'label'=>'TechnicalID',   'visible'=>0, 'notnull'=>1, 'index'=>1, 'position'=>1),
		'entity'               => array('type'=>'integer',  'label'=>'Entity',        'visible'=>0, 'notnull'=>1, 'default'=>1, 'index'=>1, 'position'=>5),

		'taler_instance'       => array('type'=>'varchar(64)',  'label'=>'TalerInstance',     'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>10),
		'taler_category_id'    => array('type'=>'integer',      'label'=>'TalerCategoryId',   'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>11),
		'taler_category_name'  => array('type'=>'varchar(255)', 'label'=>'TalerCategoryName', 'visible'=>1, 'notnull'=>0,                'position'=>12),

		'fk_categorie'         => array('type'=>'integer:Categorie:categories/class/categorie.class.php', 'label'=>'DolibarrCategory', 'visible'=>1, 'notnull'=>1, 'index'=>1, 'position'=>20, 'picto'=>'category'),
		'note'                 => array('type'=>'varchar(255)', 'label'=>'Note',               'visible'=>1, 'notnull'=>0,                'position'=>30),

		'datec'                => array('type'=>'datetime',     'label'=>'DateCreation',       'visible'=>-2, 'notnull'=>0, 'position'=>500),
		'tms'                  => array('type'=>'timestamp',    'label'=>'DateModification',   'visible'=>-2, 'notnull'=>1, 'position'=>501),
	);

	// Public properties for IDE hints
	public $rowid, $entity, $taler_instance, $taler_category_id, $taler_category_name, $fk_categorie, $note, $datec, $tms;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;

		// Hide technical id by default
		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid'])) {
			$this->fields['rowid']['visible'] = 0;
		}

		// Drop disabled fields (none here)
		foreach ($this->fields as $k => $v) {
			if (isset($v['enabled']) && empty($v['enabled'])) unset($this->fields[$k]);
		}
	}

	// CRUD
	/**
	 * Create record in database.
	 *
	 * @param User $user      User performing the action
	 * @param int  $notrigger 1 = do not call triggers
	 * @return int            >0 if OK, <0 on error
	 */
	public function create(User $user, $notrigger = 0)
	{
		if (empty($this->entity)) $this->entity = (int) getEntity($this->element, 1);
		if (empty($this->datec))  $this->datec  = dol_now();
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch object from database.
	 *
	 * @param int         $id            Rowid
	 * @param string|null $ref           Optional reference
	 * @param int         $noextrafields 1 = do not load extrafields
	 * @param int         $nolines       1 = do not load lines
	 * @return int                       >0 if OK, 0 if not found, <0 on error
	 */
	public function fetch($id, $ref = null, $noextrafields = 1, $nolines = 1)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Update record in database.
	 *
	 * @param User $user      User performing the action
	 * @param int  $notrigger 1 = do not call triggers
	 * @return int            >0 if OK, <0 on error
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete record from database.
	 *
	 * @param User $user      User performing the action
	 * @param int $notrigger 1 = do not call triggers
	 * @return int            >0 if OK, <0 on error
	 */
	public function delete(User $user, int $notrigger = 0) : int
	{
		return $this->deleteCommon($user, $notrigger);
	}

	// Finders

	/**
	 * Fetch a mapping by Dolibarr category id (unique per entity).
	 *
	 * @param int $fk_categorie Dolibarr category rowid
	 * @return int              >0 if loaded, 0 if not found, <0 on SQL error
	 */
	public function fetchByCategorie(int $fk_categorie): int
	{
		$sql  = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity($this->element).")";
		$sql .= " AND fk_categorie = ".((int) $fk_categorie);
		$sql .= " LIMIT 1";

		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return -1; }
		if ($this->db->num_rows($res) == 0) return 0;
		$obj = $this->db->fetch_object($res);
		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Fetch a mapping by (taler_instance, taler_category_id) (unique per entity).
	 *
	 * @param string $instance        Taler instance (username/tenant)
	 * @param int    $talerCategoryId Taler category id
	 * @return int                    >0 if loaded, 0 if not found, <0 on SQL error
	 */
	public function fetchByInstanceCatId(string $instance, int $talerCategoryId): int
	{
		$sql  = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity($this->element).")";
		$sql .= " AND taler_instance = '".$this->db->escape($instance)."'";
		$sql .= " AND taler_category_id = ".((int) $talerCategoryId);
		$sql .= " LIMIT 1";

		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return -1; }
		if ($this->db->num_rows($res) == 0) return 0;
		$obj = $this->db->fetch_object($res);
		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Idempotent upsert helper respecting unique keys.
	 * - If (entity, fk_categorie) exists, updates Taler fields.
	 * - Else if (entity, instance, taler_category_id) exists, updates Dolibarr fields.
	 * - Else inserts a new row.
	 *
	 * @param User        $user          User performing the action
	 * @param string      $instance      Taler instance
	 * @param int         $talerCatId    Taler category id
	 * @param int         $fkCategorie   Dolibarr category rowid
	 * @param string|null $talerCatName  Optional Taler category name
	 * @param string|null $note          Optional free-form note
	 * @return int                       >0 row id on success, <0 on error
	 */
	public function upsert(User $user, string $instance, int $talerCatId, int $fkCategorie, ?string $talerCatName = null, ?string $note = null)
	{
		$this->db->begin();

		// Try by Dolibarr category
		$tmp = new self($this->db);
		$load = $tmp->fetchByCategorie($fkCategorie);
		if ($load < 0) { $this->db->rollback(); $this->error = $tmp->error; return -1; }

		if ($load > 0) {
			// Update existing row
			$tmp->taler_instance      = $instance;
			$tmp->taler_category_id   = $talerCatId;
			if ($talerCatName !== null) $tmp->taler_category_name = $talerCatName;
			if ($note !== null)         $tmp->note = $note;

			$res = $tmp->update($user, 1);
			if ($res <= 0) { $this->db->rollback(); $this->error = $tmp->error; return -1; }
			$this->db->commit();
			return (int) $tmp->id;
		}

		// Try by Taler composite key
		$tmp2 = new self($this->db);
		$load2 = $tmp2->fetchByInstanceCatId($instance, $talerCatId);
		if ($load2 < 0) { $this->db->rollback(); $this->error = $tmp2->error; return -1; }

		if ($load2 > 0) {
			$tmp2->fk_categorie = $fkCategorie;
			if ($talerCatName !== null) $tmp2->taler_category_name = $talerCatName;
			if ($note !== null)         $tmp2->note = $note;

			$res = $tmp2->update($user, 1);
			if ($res <= 0) { $this->db->rollback(); $this->error = $tmp2->error; return -1; }
			$this->db->commit();
			return (int) $tmp2->id;
		}

		// Insert fresh
		$this->entity              = (int) getEntity($this->element, 1);
		$this->taler_instance      = $instance;
		$this->taler_category_id   = $talerCatId;
		$this->taler_category_name = $talerCatName;
		$this->fk_categorie        = $fkCategorie;
		$this->note                = $note;
		$this->datec               = dol_now();

		$res = $this->create($user, 1);
		if ($res <= 0) { $this->db->rollback(); return -1; }

		$this->db->commit();
		return (int) $this->id;
	}

	// UI categories
	/**
	 * Build HTML link to the card page.
	 *
	 * @param int         $withpicto             0=No picto, 1=Include picto+label, 2=Picto only
	 * @param string      $option                'nolink' to return a span instead of a link
	 * @param int         $notooltip             1=Disable tooltip
	 * @param string      $morecss               Additional CSS classes on the <a> or <span>
	 * @param int|string  $save_lastsearch_value Pass-through for Dolibarr helpers
	 * @return string                            HTML
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $langs;
		$label = $langs->trans('TalerCategoryMap');
		$url = dol_buildpath('/talerbarr/talercategorymap_card.php', 1).'?id='.(int) $this->id;

		$linkstart = ($option == 'nolink' || empty($url)) ? '<span>' : '<a href="'.$url.'">';
		$linkend   = ($option == 'nolink' || empty($url)) ? '</span>' : '</a>';

		$out = $linkstart;
		if ($withpicto) $out .= img_object($label, $this->picto, $withpicto != 2 ? 'class="paddingright"' : '');
		if ($withpicto != 2) $out .= dol_escape_htmltag($this->getLabelForList());
		$out .= $linkend;

		return $out;
	}

	/**
	 * Build a concise label for list contexts.
	 *
	 * @return string Label like "instance/id (name) → #fk_categorie"
	 */
	public function getLabelForList(): string
	{
		$ti  = $this->taler_instance ?: '?';
		$tid = dol_strlen($this->taler_category_id) ? (string) $this->taler_category_id : '?';
		$nm  = $this->taler_category_name ?: '';
		$dol = $this->fk_categorie ? (' → #'.$this->fk_categorie) : '';
		return trim("$ti/$tid ".($nm ? "($nm)" : '').$dol);
	}
}
