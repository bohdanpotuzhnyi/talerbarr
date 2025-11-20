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
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

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

$product = null;
if (!empty($object->fk_product)) {
	$productTmp = new Product($db);
	if ($productTmp->fetch((int) $object->fk_product) > 0) {
		$product = $productTmp;
	}
}

$title = $langs->trans("TalerProductLink").' #'.((int) $object->id);

llxHeader('', $title, '', '', 0, 0, array(), array(), '');

if (!empty($back)) {
	print '<a class="button" href="'.dol_escape_htmltag($back).'">'.$langs->trans("Back").'</a>';
}

$statusBadge = '';
if (!empty($object->sync_enabled)) {
	$statusBadge = '<span class="badge badge-status badge-active">'.$langs->trans('Enabled').'</span>';
} else {
	$statusBadge = '<span class="badge badge-status badge-inactive">'.$langs->trans('Disabled').'</span>';
}
$syncDirection = '';
if (isset($object->syncdirection_override)) {
	if ((int) $object->syncdirection_override === 1) {
		$syncDirection = $langs->trans('PullTalerToDoli');
	} elseif ((int) $object->syncdirection_override === 0) {
		$syncDirection = $langs->trans('PushDoliToTaler');
	}
}

print '<style>
.taler-card-wrap{margin:12px 0 18px;}
.taler-card-heading{font-weight:600;margin:6px 0 6px;font-size:15px;display:flex;align-items:center;gap:8px;}
.taler-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:12px 0 18px;}
.taler-info-card{border:1px solid #dfe3eb;border-radius:10px;padding:12px;background:#f9fbfd;box-shadow:0 1px 2px rgba(16,24,40,0.04);font-size:13px;}
.taler-info-card h3{margin:0 0 6px;font-size:15px;display:flex;align-items:center;gap:6px;}
.taler-info-list{list-style:none;padding:0;margin:0;}
.taler-info-list li{display:flex;justify-content:space-between;align-items:center;padding:3px 0;border-bottom:1px dashed #e5e9f0;font-size:12px;}
.taler-info-list li:last-child{border-bottom:none;}
.taler-info-label{color:#4a505c;font-size:12px;}
.taler-info-value{font-size:12px;font-weight:600;text-align:right;}
.badge-status{padding:3px 10px;border-radius:12px;font-size:11px;color:#fff;}
.badge-active{background:#2f8f46;}
.badge-inactive{background:#c1121f;}
.taler-section-title{font-size:15px;font-weight:600;margin:6px 0;}
</style>';

print load_fiche_titre($title, '', $object->picto);
print dol_get_fiche_head();

print '<div class="taler-card-wrap">';
print '<div class="taler-card-heading"><span class="fa fa-tags"></span>'.$langs->trans('ProductOverview').' '.$statusBadge.'</div>';
$blocks = array(
	array(
		'title' => $langs->trans('ProductOverview'),
		'icon'  => 'fa-cube',
		'items' => array(
			$langs->trans('Product') => ($product ? $product->getNomUrl(1) : dol_escape_htmltag($object->product_ref_snap ?: '-')),
			$langs->trans('TalerProdName') => dol_escape_htmltag($object->taler_product_name ?: '-'),
			$langs->trans('TalerDescription') => dol_escape_htmltag($object->taler_description ?: '-'),
		),
	),
	array(
		'title' => $langs->trans('Pricing'),
		'icon'  => 'fa-money',
		'items' => array(
			$langs->trans('TalerAmountStr') => dol_escape_htmltag($object->taler_amount_str ?: '-'),
			$langs->trans('Currency') => dol_escape_htmltag($object->taler_currency ?: '-'),
			$langs->trans('PriceTTC') => !empty($object->price_is_ttc) ? $langs->trans('Yes') : $langs->trans('No'),
		),
	),
	array(
		'title' => $langs->trans('Inventory'),
		'icon'  => 'fa-archive',
		'items' => array(
			$langs->trans('TotalStock') => dol_escape_htmltag(isset($object->taler_total_stock) ? (string) $object->taler_total_stock : '-'),
			$langs->trans('TotalSold') => dol_escape_htmltag(isset($object->taler_total_sold) ? (string) $object->taler_total_sold : '-'),
			$langs->trans('TotalLost') => dol_escape_htmltag(isset($object->taler_total_lost) ? (string) $object->taler_total_lost : '-'),
			$langs->trans('NextRestock') => (!empty($object->taler_next_restock) ? dol_print_date($object->taler_next_restock, 'dayhour') : $langs->trans('None')),
		),
	),
	array(
		'title' => $langs->trans('Sync'),
		'icon'  => 'fa-refresh',
		'items' => array(
			$langs->trans('TalerInstance') => dol_escape_htmltag($object->taler_instance),
			$langs->trans('TalerProductId') => dol_escape_htmltag($object->taler_product_id),
			$langs->trans('SyncDirectionOverride') => dol_escape_htmltag($syncDirection ?: '-'),
			$langs->trans('LastSyncAt') => (!empty($object->last_sync_at) ? dol_print_date($object->last_sync_at, 'dayhour') : $langs->trans('None')),
			$langs->trans('LastSyncStatus') => dol_escape_htmltag($object->last_sync_status ?: '-'),
		),
	),
);
print '<div class="taler-info-grid">';
foreach ($blocks as $block) {
	print '<div class="taler-info-card">';
	print '<h3><span class="fa '.dol_escape_htmltag($block['icon']).'"></span>'.dol_escape_htmltag($block['title']).'</h3>';
	print '<ul class="taler-info-list">';
	foreach ($block['items'] as $label => $value) {
		print '<li><span class="taler-info-label">'.dol_escape_htmltag($label).'</span><span class="taler-info-value">'.$value.'</span></li>';
	}
	print '</ul>';
	print '</div>';
}
print '</div>';
print '</div>';

$orderedFields = dol_sort_array($object->fields, 'position');
print '<div class="taler-section-title">'.$langs->trans('RawData').'</div>';
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
