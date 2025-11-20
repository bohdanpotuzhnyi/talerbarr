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
 *  \file       talerproductlink_card.php
 *  \ingroup    talerbarr
 *  \brief      Card page to inspect a single TalerProductLink row (raw DB snapshot).
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

dol_include_once('/talerbarr/class/talerproductlink.class.php');

$langs->loadLangs(array('talerbarr@talerbarr', 'other'));

$id   = GETPOSTINT('id');
$back = GETPOST('backtopage', 'alpha');

$enablepermissioncheck = getDolGlobalInt('TALERBARR_ENABLE_PERMISSION_CHECK');
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('talerbarr', 'talerproductlink', 'read');
} else {
	$permissiontoread = 1;
}

if ($user->socid > 0) accessforbidden();
if (!isModEnabled('talerbarr')) accessforbidden('Module talerbarr not enabled');
if (!$permissiontoread) accessforbidden();

$object = new TalerProductLink($db);
$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label($object->table_element);

if ($id > 0) {
	$result = $object->fetch($id);
	if ($result <= 0) {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	}
	$object->fetch_optionals($id);
}

if (empty($object->id)) {
	print $langs->trans("ErrorRecordNotFound");
	exit;
}

foreach ($object->fields as &$def) {
	$def['visible'] = 1;
}
unset($def);

$title = $langs->trans("TalerProductLink").' #'.((int) $object->id);

llxHeader('', $title, '', '', 0, 0, array(), array(), '');

if (!empty($back)) {
	print '<a class="button" href="'.dol_escape_htmltag($back).'">'.$langs->trans("Back").'</a>';
}

print load_fiche_titre($title, '', $object->picto);
print dol_get_fiche_head();

$orderedFields = dol_sort_array($object->fields, 'position');
print '<table class="border centpercent tableforfield">'."\n";
foreach ($orderedFields as $key => $val) {
	print '<tr>';
	print '<td class="titlefield">'.$langs->trans($val['label']).'</td>';
	$value = isset($object->{$key}) ? $object->{$key} : '';
	print '<td class="valuefield">'.$object->showOutputField($val, $key, $value, '').'</td>';
	print '</tr>';
}
if (!empty($extrafields->attributes[$object->table_element]['label'])) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $label) {
		print '<tr>';
		print '<td class="titlefield">'.dol_escape_htmltag($label).'</td>';
		$val = isset($object->array_options['options_'.$key]) ? $object->array_options['options_'.$key] : '';
		print '<td class="valuefield">'.$extrafields->showOutputField($key, $val, '', $object->table_element).'</td>';
		print '</tr>';
	}
}
print '</table>';

print '<div class="clearboth"></div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
