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
 *  \file       talerproductlink_list.php
 *  \ingroup    talerbarr
 *  \brief      List page for TalerProductLink
 *
 * @package    Application
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

dol_include_once('/talerbarr/class/talerproductlink.class.php');

// Translations
$langs->loadLangs(array("talerbarr@talerbarr", "other"));

// Parameters
$action      = GETPOST('action', 'aZ09') ?: 'view';
$massaction  = GETPOST('massaction', 'alpha');
$show_files  = GETPOSTINT('show_files');
$confirm     = GETPOST('confirm', 'alpha');
$cancel      = GETPOST('cancel', 'alpha');
$toselect    = GETPOST('toselect', 'array:int');
$contextpage = GETPOST('contextpage', 'aZ') ?: str_replace('_', '', basename(dirname(__FILE__)).basename(__FILE__, '.php'));
$backtopage  = GETPOST('backtopage', 'alpha');
$optioncss   = GETPOST('optioncss', 'aZ');
$mode        = GETPOST('mode', 'aZ');
$groupby     = GETPOST('groupby', 'aZ09');
$id          = GETPOSTINT('id');
$ref         = GETPOST('ref', 'alpha');

// Pagination
$limit     = GETPOSTINT('limit') ?: $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page      = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) $page = 0;
$offset   = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Init
$object = new TalerProductLink($db);
$extrafields = new ExtraFields($db);
$diroutputmassaction = $conf->talerbarr->dir_output.'/temp/massgeneration/'.$user->id;
$hookmanager->initHooks(array($contextpage));

// Extrafields
$extrafields->fetch_name_optionals_label($object->table_element);
$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Default sort
if (!$sortfield) { reset($object->fields); $sortfield = "t.".key($object->fields); }
if (!$sortorder) { $sortorder = "ASC"; }

// Search criteria
$search_all = trim(GETPOST('search_all', 'alphanohtml'));
$search = array();
foreach ($object->fields as $key => $val) {
	if (GETPOST('search_'.$key, 'alpha') !== '') $search[$key] = GETPOST('search_'.$key, 'alpha');
	if (preg_match('/^(date|timestamp|datetime)/', $val['type'])) {
		$search[$key.'_dtstart'] = dol_mktime(0, 0, 0, GETPOSTINT('search_'.$key.'_dtstartmonth'), GETPOSTINT('search_'.$key.'_dtstartday'), GETPOSTINT('search_'.$key.'_dtstartyear'));
		$search[$key.'_dtend']   = dol_mktime(23, 59, 59, GETPOSTINT('search_'.$key.'_dtendmonth'), GETPOSTINT('search_'.$key.'_dtendday'), GETPOSTINT('search_'.$key.'_dtendyear'));
	}
}

// Column definitions
$tableprefix = 't';
$arrayfields = array();
foreach ($object->fields as $key => $val) {
	if (!empty($val['visible'])) {
		$visible = (int) dol_eval((string) $val['visible'], 1);
		$arrayfields[$tableprefix.'.'.$key] = array(
			'label'    => $val['label'],
			'checked'  => (($visible < 0) ? '0' : '1'),
			'enabled'  => (string) (int) (abs($visible) != 3 && (bool) dol_eval((string) $val['enabled'], 1)),
			'position' => $val['position'],
			'help'     => isset($val['help']) ? $val['help'] : ''
		);
	}
}
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_array_fields.tpl.php';

$object->fields = dol_sort_array($object->fields, 'position');
$arrayfields    = dol_sort_array($arrayfields, 'position');

// Permissions
$enablepermissioncheck = getDolGlobalInt('TALERBARR_ENABLE_PERMISSION_CHECK');
if ($enablepermissioncheck) {
	$permissiontoread   = $user->hasRight('talerbarr', 'talerproductlink', 'read');
	$permissiontoadd    = $user->hasRight('talerbarr', 'talerproductlink', 'write');
	$permissiontodelete = $user->hasRight('talerbarr', 'talerproductlink', 'delete');
} else {
	$permissiontoread = $permissiontoadd = $permissiontodelete = 1;
}

if ($user->socid > 0) accessforbidden();
if (!isModEnabled("talerbarr")) accessforbidden('Module talerbarr not enabled');
if (!$permissiontoread) accessforbidden();

/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) { $action = 'list'; $massaction = ''; }
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') $massaction = '';

$parameters = array('arrayfields' => &$arrayfields);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook)) {
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

	// Purge search
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
		foreach ($object->fields as $key => $val) {
			$search[$key] = '';
			if (preg_match('/^(date|timestamp|datetime)/', $val['type'])) {
				$search[$key.'_dtstart'] = '';
				$search[$key.'_dtend'] = '';
			}
		}
		$search_all = '';
		$toselect = array();
		$search_array_options = array();
	}
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
		|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
		$massaction = '';
	}

	// Mass actions
	$objectclass = 'TalerProductLink';
	$objectlabel = 'TalerProductLink';
	$uploaddir   = $conf->talerbarr->dir_output;

	include DOL_DOCUMENT_ROOT.'/core/actions_massactions.inc.php';
}

/*
 * View
 */

$form = new Form($db);

$title   = $langs->trans("TalerProductLinks");
$help_url = '';
$morejs  = array();
$morecss = array();

// Build SQL
$sql = "SELECT ".$object->getFieldList('t');
// Extrafields
if (!empty($extrafields->attributes[$object->table_element]['label'])) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $val) {
		$sql .= ($extrafields->attributes[$object->table_element]['type'][$key] != 'separate' ? ", ef.".$key." as options_".$key : "");
	}
}
// Hooks - select
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object, $action);
$sql .= $hookmanager->resPrint;
$sql = preg_replace('/,\s*$/', '', $sql);

$sqlfields = $sql;

$sql .= " FROM ".$db->prefix().$object->table_element." as t";
if (isset($extrafields->attributes[$object->table_element]['label']) && is_array($extrafields->attributes[$object->table_element]['label']) && count($extrafields->attributes[$object->table_element]['label'])) {
	$sql .= " LEFT JOIN ".$db->prefix().$object->table_element."_extrafields as ef on (t.rowid = ef.fk_object)";
}
// Hooks - from
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters, $object, $action);
$sql .= $hookmanager->resPrint;

if (!empty($object->ismultientitymanaged) && (int) $object->ismultientitymanaged == 1) {
	$sql .= " WHERE t.entity IN (".getEntity($object->element, (GETPOSTINT('search_current_entity') ? 0 : 1)).")";
} elseif (preg_match('/^\w+@\w+$/', (string) $object->ismultientitymanaged)) {
	$tmparray = explode('@', (string) $object->ismultientitymanaged);
	$sql .= " LEFT JOIN ".$object->db->prefix().$tmparray[1]." as pt ON t.".$db->sanitize($tmparray[0])." = pt.rowid";
	$sql .= " WHERE pt.entity IN (".getEntity($object->element, (GETPOSTINT('search_current_entity') ? 0 : 1)).")";
} else {
	$sql .= " WHERE 1=1";
}

// Apply searches
foreach ($search as $key => $val) {
	if (array_key_exists($key, $object->fields)) {
		if ($key == 'status' && $search[$key] == -1) continue;
		$field_spec = $object->fields[$key];
		if ($field_spec === null) continue;
		$mode_search = (($object->isInt($field_spec) || $object->isFloat($field_spec)) ? 1 : 0);
		if ((strpos($field_spec['type'], 'integer:') === 0) || (strpos($field_spec['type'], 'sellist:') === 0) || !empty($field_spec['arrayofkeyval'])) {
			if ($search[$key] == '-1' || ($search[$key] === '0' && (empty($field_spec['arrayofkeyval']) || !array_key_exists('0', $field_spec['arrayofkeyval'])))) {
				$search[$key] = '';
			}
			$mode_search = 2;
		}
		if ($field_spec['type'] === 'boolean') {
			$mode_search = 1;
			if ($search[$key] == '-1') $search[$key] = '';
		}
		if (empty($field_spec['searchmulti'])) {
			if (!is_array($search[$key]) && $search[$key] != '') {
				$sql .= natural_search("t.".$db->escape($key), $search[$key], $mode_search);
			}
		} else {
			if (is_array($search[$key]) && !empty($search[$key])) {
				$sql .= natural_search("t.".$db->escape($key), implode(',', $search[$key]), $mode_search);
			}
		}
	} else {
		if (preg_match('/(_dtstart|_dtend)$/', $key) && $search[$key] != '') {
			$columnName = preg_replace('/(_dtstart|_dtend)$/', '', $key);
			if (preg_match('/^(date|timestamp|datetime)/', $object->fields[$columnName]['type'])) {
				if (preg_match('/_dtstart$/', $key)) $sql .= " AND t.".$db->sanitize($columnName)." >= '".$db->idate($search[$key])."'";
				if (preg_match('/_dtend$/',   $key)) $sql .= " AND t.".$db->sanitize($columnName)." <= '".$db->idate($search[$key])."'";
			}
		}
	}
}
if ($search_all) {
	// You can enable quick search on fields here if desired.
}

// Extrafields where
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_sql.tpl.php';
// Hooks - where
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object, $action);
$sql .= $hookmanager->resPrint;

// Count
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$sqlforcount = preg_replace('/^'.preg_quote($sqlfields, '/').'/', 'SELECT COUNT(*) as nbtotalofrecords', $sql);
	$sqlforcount = preg_replace('/GROUP BY .*$/', '', $sqlforcount);
	$rescount = $db->query($sqlforcount);
	if ($rescount) {
		$objforcount = $db->fetch_object($rescount);
		$nbtotalofrecords = $objforcount->nbtotalofrecords;
		$db->free($rescount);
	}
	if (($page * $limit) > (int) $nbtotalofrecords) { $page = 0; $offset = 0; }
}

// Finalize request
$sql .= $db->order($sortfield, $sortorder);
if ($limit) $sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) { dol_print_error($db); exit; }
$num = $db->num_rows($resql);

// Direct open if only 1 and quick search
if ($num == 1 && getDolGlobalInt('MAIN_SEARCH_DIRECT_OPEN_IF_ONLY_ONE') && $search_all && !$page) {
	$obj = $db->fetch_object($resql);
	$id = $obj->rowid;
	header("Location: ".dol_buildpath('/talerbarr/talerproductlink_card.php', 1).'?id='.(int) $id);
	exit;
}

// Header
llxHeader('', $title, $help_url, '', 0, 0, $morejs, $morecss, '', 'mod-talerbarr page-list bodyforlist');

$arrayofselected = is_array($toselect) ? $toselect : array();

// Build $param back
$param = '';
if (!empty($mode)) $param .= '&mode='.urlencode($mode);
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param .= '&contextpage='.urlencode($contextpage);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit='.(int) $limit;
if ($optioncss != '') $param .= '&optioncss='.urlencode($optioncss);
if ($groupby != '') $param .= '&groupby='.urlencode($groupby);
foreach ($search as $key => $val) {
	if (is_array($search[$key])) {
		foreach ($search[$key] as $skey) if ($skey != '') $param .= '&search_'.$key.'[]='.urlencode($skey);
	} elseif (preg_match('/(_dtstart|_dtend)$/', $key) && !empty($val)) {
		$param .= '&search_'.$key.'month='.GETPOSTINT('search_'.$key.'month');
		$param .= '&search_'.$key.'day='.GETPOSTINT('search_'.$key.'day');
		$param .= '&search_'.$key.'year='.GETPOSTINT('search_'.$key.'year');
	} elseif ($search[$key] != '') {
		$param .= '&search_'.$key.'='.urlencode($search[$key]);
	}
}
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_param.tpl.php';
$parameters = array('param' => &$param);
$reshook = $hookmanager->executeHooks('printFieldListSearchParam', $parameters, $object, $action);
$param .= $hookmanager->resPrint;

// Mass actions
$arrayofmassactions = array();
if (!empty($permissiontodelete)) $arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans("Delete");
if (GETPOSTINT('nomassaction') || in_array($massaction, array('presend','predelete'))) $arrayofmassactions = array();
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

// Top buttons
print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';
print '<input type="hidden" name="page_y" value="">';
print '<input type="hidden" name="mode" value="'.$mode.'">';

$newcardbutton = '';
$newcardbutton .= dolGetButtonTitle($langs->trans('ViewList'), '', 'fa fa-bars imgforviewmode', $_SERVER["PHP_SELF"].'?mode=common'.preg_replace('/(&|\?)*(mode|groupby)=[^&]+/', '', $param), '', ((empty($mode) || $mode == 'common') ? 2 : 1), array('morecss' => 'reposition'));
$newcardbutton .= dolGetButtonTitle($langs->trans('ViewKanban'), '', 'fa fa-th-list imgforviewmode', $_SERVER["PHP_SELF"].'?mode=kanban'.preg_replace('/(&|\?)*(mode|groupby)=[^&]+/', '', $param), '', ($mode == 'kanban' ? 2 : 1), array('morecss' => 'reposition'));
//$newcardbutton .= dolGetButtonTitleSeparator();
//$newcardbutton .= dolGetButtonTitle($langs->trans('New'), '', 'fa fa-plus-circle', dol_buildpath('/talerbarr/talerproductlink_card.php', 1).'?action=create&backtopage='.urlencode($_SERVER['PHP_SELF']), '', $permissiontoadd);

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, $object->picto, 0, $newcardbutton, '', $limit, 0, 0, 1);

// Massaction prezone
$topicmail = "SendTalerProductLinkRef";
$modelmail = "talerproductlink";
$objecttmp = new TalerProductLink($db);
$trackid = 'tpl'.$object->id;
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';

// Filters row
$moreforfilter = '';
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldPreListTitle', $parameters, $object, $action);
$moreforfilter = empty($reshook) ? $hookmanager->resPrint : $hookmanager->resPrint;

if (!empty($moreforfilter)) {
	print '<div class="liste_titre liste_titre_bydiv centpercent">'.$moreforfilter.'</div>';
}

$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
$htmlofselectarray = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, $conf->main_checkbox_left_column);
$selectedfields = (($mode != 'kanban' && $mode != 'kanbangroupby') ? $htmlofselectarray : '');
$selectedfields .= (count($arrayofmassactions) ? $form->showCheckAddButtons('checkforselect', 1) : '');

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal noborder liste'.($moreforfilter ? " listwithfilterbefore" : "").'">'."\n";

// Search inputs
print '<tr class="liste_titre_filter">';
if ($conf->main_checkbox_left_column) {
	print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>';
}
foreach ($object->fields as $key => $val) {
	$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
	if ($key == 'status' || in_array($val['type'], array('date','datetime','timestamp'))) $cssforfield .= ($cssforfield ? ' ' : '').'center';

	if (!empty($arrayfields['t.'.$key]['checked'])) {
		print '<td class="liste_titre'.($cssforfield ? ' '.$cssforfield : '').($key == 'status' ? ' parentonrightofpage' : '').'">';
		if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
			if (empty($val['searchmulti'])) {
				print $form->selectarray('search_'.$key, $val['arrayofkeyval'], ($search[$key] ?? ''), 1, 0, 0, '', 1, 0, 0, '', 'maxwidth100'.($key == 'status' ? ' search_status width100 onrightofpage' : ''), 1);
			} else {
				print $form->multiselectarray('search_'.$key, $val['arrayofkeyval'], ($search[$key] ?? ''), 0, 0, 'maxwidth100'.($key == 'status' ? ' search_status width100 onrightofpage' : ''), 1);
			}
		} elseif ((strpos($val['type'], 'integer:') === 0) || (strpos($val['type'], 'sellist:') === 0)) {
			print $object->showInputField($val, $key, ($search[$key] ?? ''), '', '', 'search_', $cssforfield.' maxwidth250', 1);
		} elseif (preg_match('/^(date|timestamp|datetime)/', $val['type'])) {
			print '<div class="nowrap">'.$form->selectDate(($search[$key.'_dtstart'] ?? ''), "search_".$key."_dtstart", 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From')).'</div>';
			print '<div class="nowrap">'.$form->selectDate(($search[$key.'_dtend'] ?? ''),   "search_".$key."_dtend",   0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')).'</div>';
		} elseif ($val['type'] === 'boolean') {
			print $form->selectyesno('search_'.$key, $search[$key] ?? '', 1, false, 1);
		} else {
			print '<input type="text" class="flat maxwidth'.(in_array($val['type'], array('integer','price')) ? '50' : '75').'" name="search_'.$key.'" value="'.dol_escape_htmltag($search[$key] ?? '').'">';
		}
		print '</td>';
	}
}
// Extrafields inputs
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_input.tpl.php';

// Hook cols
$parameters = array('arrayfields' => $arrayfields);
$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $object, $action);
print $hookmanager->resPrint;

if (!$conf->main_checkbox_left_column) {
	print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
}
print '</tr>'."\n";

// Title row
$totalarray = array(); $totalarray['nbfield'] = 0;
print '<tr class="liste_titre">';
if ($conf->main_checkbox_left_column) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	$totalarray['nbfield']++;
}
foreach ($object->fields as $key => $val) {
		$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
		if ($key == 'status' || in_array($val['type'], array('date','datetime','timestamp'))) $cssforfield .= ($cssforfield ? ' ' : '').'center';
		$cssforfield = preg_replace('/small\s*/', '', $cssforfield);
	if (!empty($arrayfields['t.'.$key]['checked'])) {
		if ($key === 'fk_product') {
			$val['label'] = $val['label'].' (link)';
		}
		print getTitleFieldOfList($arrayfields['t.'.$key]['label'], 0, $_SERVER['PHP_SELF'], 't.'.$key, '', $param, ($cssforfield ? 'class="'.$cssforfield.'"' : ''), $sortfield, $sortorder, ($cssforfield ? $cssforfield.' ' : ''), 0, (empty($val['helplist']) ? '' : $val['helplist']))."\n";
		$totalarray['nbfield']++;
	}
}
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_title.tpl.php';
$parameters = array('arrayfields' => $arrayfields, 'param' => $param, 'sortfield' => $sortfield, 'sortorder' => $sortorder, 'totalarray' => &$totalarray);
$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $object, $action);
print $hookmanager->resPrint;
if (!$conf->main_checkbox_left_column) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	$totalarray['nbfield']++;
}
print '</tr>'."\n";

// Need fetch object for computed EF?
$needToFetchEachLine = 0;
if (!empty($extrafields->attributes[$object->table_element]['computed'])) {
	foreach ($extrafields->attributes[$object->table_element]['computed'] as $key => $val) {
		if (!is_null($val) && preg_match('/\$object/', $val)) $needToFetchEachLine++;
	}
}

// Loop
$i = 0; $savnbfield = $totalarray['nbfield']; $totalarray = array(); $totalarray['nbfield'] = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) break;

	$object->setVarsFromFetchObj($obj);
	if (empty($object->id) && !empty($object->rowid)) {
		$object->id = (int) $object->rowid;
	}

	if ($mode == 'kanban' || $mode == 'kanbangroupby') {
		if ($i == 0) { print '<tr class="trkanban"><td colspan="'.$savnbfield.'"><div class="box-flex-container kanban">'; }
		$selected = ($massactionbutton || $massaction) ? (in_array($object->id, (array) $toselect) ? 1 : 0) : -1;
		print $object->getKanbanView('', array('selected'=>$selected));
		if ($i == ($imaxinloop - 1)) { print '</div></td></tr>'; }
	} else {
		print '<tr data-rowid="'.$object->id.'" class="oddeven">';

		if ($conf->main_checkbox_left_column) {
			print '<td class="nowrap center">';
			if ($massactionbutton || $massaction) {
				$selected = in_array($object->id, (array) $toselect) ? 1 : 0;
				print '<input id="cb'.$object->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$object->id.'"'.($selected ? ' checked="checked"' : '').'>';
			}
			print '</td>';
			if (!$i) $totalarray['nbfield']++;
		}

		foreach ($object->fields as $key => $val) {
			$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
			if (in_array($val['type'], array('date','datetime','timestamp')) || $key == 'status') $cssforfield .= ($cssforfield ? ' ' : '').'center';
			if ($key == 'ref' || $val['type'] == 'timestamp') $cssforfield .= ($cssforfield ? ' ' : '').'nowraponall';
			if (in_array($val['type'], array('double(24,8)','double(6,3)','integer','real','price')) && !in_array($key, array('id','rowid','ref','status')) && empty($val['arrayofkeyval'])) $cssforfield .= ($cssforfield ? ' ' : '').'right';

			if (!empty($arrayfields['t.'.$key]['checked'])) {
				print '<td'.($cssforfield ? ' class="'.$cssforfield.'"' : '').'>';
				if ($key == 'status') {
					print $object->getLibStatut(5);
				} elseif ($key == 'rowid' || $key === 'taler_product_id') {
					// Link to product link card
					print $object->getNomUrl(1);
				} elseif ($key == 'fk_product' && !empty($object->fk_product)) {
					$prod = new Product($db);
					if ($prod->fetch((int) $object->fk_product) > 0) {
						print $prod->getNomUrl(1);
					} else {
						print (int) $object->fk_product;
					}
				} else {
					if ($val['type'] == 'html') print '<div class="small lineheightsmall twolinesmax-normallineheight">';
					print $object->showOutputField($val, $key, (string) $object->$key, '');
					if ($val['type'] == 'html') print '</div>';
				}
				print '</td>';
				if (!$i) $totalarray['nbfield']++;

				if (!empty($val['isameasure']) && $val['isameasure'] == 1) {
					if (!$i) $totalarray['pos'][$totalarray['nbfield']] = 't.'.$key;
					if (!isset($totalarray['val'])) $totalarray['val'] = array();
					if (!isset($totalarray['val']['t.'.$key])) $totalarray['val']['t.'.$key] = 0;
					$totalarray['val']['t.'.$key] += $object->$key;
				}
			}
		}

		// Extrafields row
		include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_print_fields.tpl.php';

		// Hook cols
		$parameters = array('arrayfields' => $arrayfields, 'object' => $object, 'obj' => $obj, 'i' => $i, 'totalarray' => &$totalarray);
		$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $object, $action);
		print $hookmanager->resPrint;

		if (empty($conf->main_checkbox_left_column)) {
			print '<td class="nowrap center">';
			if ($massactionbutton || $massaction) {
				$selected = in_array($object->id, (array) $toselect) ? 1 : 0;
				print '<input id="cb'.$object->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$object->id.'"'.($selected ? ' checked="checked"' : '').'>';
			}
			print '</td>';
			if (!$i) $totalarray['nbfield']++;
		}

		print '</tr>'."\n";
	}

	$i++;
}

// Totals
include DOL_DOCUMENT_ROOT.'/core/tpl/list_print_total.tpl.php';

// No record
if ($num == 0) {
	$colspan = 1;
	foreach ($arrayfields as $key => $val) if (!empty($val['checked'])) $colspan++;
	print '<tr><td colspan="'.$colspan.'"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

$db->free($resql);

$parameters = array('arrayfields' => $arrayfields, 'sql' => $sql);
$reshook = $hookmanager->executeHooks('printFieldListFooter', $parameters, $object, $action);
print $hookmanager->resPrint;

print '</table></div>';
print '</form>';

// Documents area for mass build (kept standard)
if (in_array('builddoc', array_keys($arrayofmassactions)) && ($nbtotalofrecords === '' || $nbtotalofrecords)) {
	$hidegeneratedfilelistifempty = ($massaction == 'builddoc' || $action == 'remove_file' || $show_files) ? 0 : 1;
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
	$formfile = new FormFile($db);
	$urlsource = $_SERVER['PHP_SELF'].'?sortfield='.$sortfield.'&sortorder='.$sortorder.str_replace('&amp;', '&', $param);
	$filedir = $diroutputmassaction; $genallowed = $permissiontoread; $delallowed = $permissiontoadd;
	print $formfile->showdocuments('massfilesarea_'.$object->module, '', $filedir, $urlsource, 0, $delallowed, '', 1, 1, 0, 48, 1, $param, $title, '', '', '', null, $hidegeneratedfilelistifempty);
}

llxFooter();
$db->close();
