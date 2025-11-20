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
 *  \file       talerorderlink_card.php
 *  \ingroup    talerbarr
 *  \brief      Card page to inspect a single TalerOrderLink row (raw DB snapshot).
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

dol_include_once('/talerbarr/class/talerorderlink.class.php');

$langs->loadLangs(array('talerbarr@talerbarr', 'other'));

$id   = GETPOSTINT('id');
$back = GETPOST('backtopage', 'alpha');

$enablepermissioncheck = getDolGlobalInt('TALERBARR_ENABLE_PERMISSION_CHECK');
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('talerbarr', 'talerorderlink', 'read');
} else {
	$permissiontoread = 1;
}

if ($user->socid > 0) accessforbidden();
if (!isModEnabled('talerbarr')) accessforbidden('Module talerbarr not enabled');
if (!$permissiontoread) accessforbidden();

$object = new TalerOrderLink($db);
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

// Force visibility of fields so the card shows the raw row.
foreach ($object->fields as &$def) {
	$def['visible'] = 1;
}
unset($def);

$societe  = null;
$commande = null;
$facture  = null;
$paiement = null;

if (!empty($object->fk_soc)) {
	$societeTmp = new Societe($db);
	if ($societeTmp->fetch((int) $object->fk_soc) > 0) {
		$societe = $societeTmp;
	}
}
if (!empty($object->fk_commande)) {
	$commandeTmp = new Commande($db);
	if ($commandeTmp->fetch((int) $object->fk_commande) > 0) {
		$commande = $commandeTmp;
	}
}
if (!empty($object->fk_facture)) {
	$factureTmp = new Facture($db);
	if ($factureTmp->fetch((int) $object->fk_facture) > 0) {
		$facture = $factureTmp;
	}
}
if (!empty($object->fk_paiement)) {
	$paiementTmp = new Paiement($db);
	if ($paiementTmp->fetch((int) $object->fk_paiement) > 0) {
		$paiement = $paiementTmp;
	}
}

$title = $langs->trans("TalerOrderLink").' #'.((int) $object->id);

llxHeader('', $title, '', '', 0, 0, array(), array(), '');

// Back link
if (!empty($back)) {
	print '<a class="button" href="'.dol_escape_htmltag($back).'">'.$langs->trans("Back").'</a>';
}

$stateLabels = array(
	10 => $langs->trans('Unpaid'),
	20 => $langs->trans('Claimed'),
	30 => $langs->trans('Paid'),
	40 => $langs->trans('Delivered'),
	50 => $langs->trans('Wired'),
	70 => $langs->trans('Refunded'),
	90 => $langs->trans('Expired'),
	91 => $langs->trans('Aborted'),
);
$talerStateLabel = $stateLabels[(int) $object->taler_state] ?? $langs->trans('Unknown');

$stateClass = 'flow-open';
if ((int) $object->taler_state >= 50) {
	$stateClass = 'flow-ok';
} elseif ((int) $object->taler_state >= 30) {
	$stateClass = 'flow-warn';
} elseif (in_array((int) $object->taler_state, array(90, 91), true)) {
	$stateClass = 'flow-missing';
}

print '<style>
.taler-flow-wrap{margin:12px 0 18px;}
.taler-flow-heading{font-weight:600;margin:6px 0 6px;font-size:15px;display:flex;align-items:center;gap:8px;}
.taler-flow-row{display:flex;gap:12px;align-items:stretch;flex-wrap:wrap;margin-bottom:10px;}
.taler-flow-step{flex:1 1 260px;min-width:240px;background:#f8fafc;border:1px solid #dfe3eb;border-radius:10px;padding:12px;position:relative;box-shadow:0 1px 2px rgba(16,24,40,0.04);font-size:13px;}
.taler-flow-step.flow-ok{border-color:#2f8f46;background:#f1f7f3;}
.taler-flow-step.flow-warn{border-color:#c57b00;background:#fff8ec;}
.taler-flow-step.flow-missing{border-color:#c1121f;background:#fff5f5;}
.taler-flow-step.flow-open{border-style:dashed;border-width:2px;}
.taler-flow-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.taler-flow-title{display:flex;align-items:center;gap:8px;}
.taler-flow-icon{font-size:18px;color:#3b4c70;}
.taler-flow-badge{display:inline-block;padding:3px 10px;font-size:12px;border-radius:20px;background:#eef2f7;margin-left:6px;}
.flow-ok .taler-flow-badge{background:#2f8f46;color:#fff;}
.flow-warn .taler-flow-badge{background:#c57b00;color:#fff;}
.flow-missing .taler-flow-badge{background:#c1121f;color:#fff;}
.taler-flow-label{font-size:13px;font-weight:700;margin:0;}
.taler-flow-lines{font-size:12px;color:#2f3440;line-height:1.35;word-break:break-word;}
.taler-flow-lines div{padding:2px 0;}
.taler-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:12px 0 18px;}
.taler-info-card{border:1px solid #dfe3eb;border-radius:8px;padding:10px 12px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,0.04);}
.taler-info-card h3{margin:0 0 6px;font-size:15px;}
.taler-info-list{list-style:none;padding:0;margin:0;}
.taler-info-list li{display:flex;justify-content:space-between;align-items:center;padding:3px 0;border-bottom:1px dashed #e5e9f0;}
.taler-info-list li:last-child{border-bottom:none;}
.taler-info-label{color:#4a505c;font-size:12px;}
.taler-info-value{font-size:12px;font-weight:600;text-align:right;}
.taler-section-title{font-size:15px;font-weight:600;margin:6px 0;}
</style>';

print load_fiche_titre($title, '', $object->picto);
print dol_get_fiche_head();

$flowSteps = array();
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeTalerOrder'),
	'icon'   => 'fa-shopping-cart',
	'status' => $stateClass,
	'badge'  => $talerStateLabel,
	'lines'  => array(
		$langs->trans('TalerInstance').': '.dol_escape_htmltag($object->taler_instance),
		$langs->trans('TalerOrderId').': '.dol_escape_htmltag($object->taler_order_id),
		$langs->trans('FlowAmount').': '.dol_escape_htmltag($object->order_amount_str ?: ($object->deposit_total_str ?: '-')),
		$langs->trans('FlowDeadlines').': '.(!empty($object->taler_pay_deadline) ? dol_print_date($object->taler_pay_deadline, 'dayhour') : $langs->trans('None')),
		$langs->trans('FlowLink').': '.(!empty($object->taler_status_url) ? '<a href="'.dol_escape_htmltag($object->taler_status_url).'" target="_blank" rel="noopener">'.$langs->trans('TalerStatusURL').'</a>' : $langs->trans('FlowMissing'))
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeCommande'),
	'icon'   => 'fa-file-text-o',
	'status' => $commande ? 'flow-ok' : 'flow-open',
	'badge'  => $commande ? $langs->trans('FlowDone') : $langs->trans('FlowPending'),
	'lines'  => array(
		$langs->trans('FlowOrderRef').': '.($commande ? $commande->getNomUrl(1) : dol_escape_htmltag($object->commande_ref_snap ?: '-')),
		$langs->trans('Customer').': '.($societe ? $societe->getNomUrl(1) : dol_escape_htmltag($object->fk_soc ?: '-')),
		$langs->trans('FlowPaymentIntent').': '.dol_escape_htmltag($object->intended_payment_code ?: '-'),
		$langs->trans('Date').': '.(!empty($object->commande_datec) ? dol_print_date($object->commande_datec, 'dayhour') : $langs->trans('None')),
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeInvoice'),
	'icon'   => 'fa-file-text-o',
	'status' => $facture ? 'flow-ok' : 'flow-open',
	'badge'  => $facture ? $langs->trans('FlowDone') : $langs->trans('FlowPending'),
	'lines'  => array(
		$langs->trans('Invoice').': '.($facture ? $facture->getNomUrl(1) : dol_escape_htmltag($object->facture_ref_snap ?: '-')),
		$langs->trans('FlowPaymentTerms').': '.dol_escape_htmltag($object->fk_cond_reglement ?: '-'),
		$langs->trans('Date').': '.(!empty($object->facture_datef) ? dol_print_date($object->facture_datef, 'dayhour') : $langs->trans('None')),
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodePayment'),
	'icon'   => 'fa-credit-card',
	'status' => $paiement ? 'flow-ok' : 'flow-warn',
	'badge'  => $paiement ? $langs->trans('FlowDone') : $langs->trans('FlowAttention'),
	'lines'  => array(
		$langs->trans('FlowPaymentStatus').': '.($paiement ? $paiement->getNomUrl(1) : $langs->trans('FlowPending')),
		$langs->trans('Date').': '.(!empty($object->paiement_datep) ? dol_print_date($object->paiement_datep, 'dayhour') : $langs->trans('None')),
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeRefund'),
	'icon'   => 'fa-undo',
	'status' => !empty($object->taler_refunded_total) ? 'flow-ok' : (!empty($object->taler_refund_pending) ? 'flow-warn' : 'flow-open'),
	'badge'  => !empty($object->taler_refunded_total) ? $langs->trans('FlowDone') : (!empty($object->taler_refund_pending) ? $langs->trans('FlowAttention') : $langs->trans('FlowPending')),
	'lines'  => array(
		$langs->trans('FlowRefundStatus').': '.(!empty($object->taler_refunded_total) ? dol_escape_htmltag($object->taler_refunded_total) : $langs->trans('FlowNone')),
		$langs->trans('FlowDeadlines').': '.(!empty($object->taler_refund_deadline) ? dol_print_date($object->taler_refund_deadline, 'dayhour') : $langs->trans('None')),
	),
);
$flowSteps[] = array(
	'label'  => $langs->trans('FlowNodeWire'),
	'icon'   => 'fa-exchange',
	'status' => !empty($object->taler_wired) ? 'flow-ok' : (!empty($object->taler_wtid) ? 'flow-warn' : 'flow-open'),
	'badge'  => !empty($object->taler_wired) ? $langs->trans('FlowDone') : (!empty($object->taler_wtid) ? $langs->trans('FlowAttention') : $langs->trans('FlowPending')),
	'lines'  => array(
		$langs->trans('FlowWireStatus').': '.(!empty($object->taler_wired) ? $langs->trans('Yes') : $langs->trans('No')),
		$langs->trans('WTID').': '.dol_escape_htmltag($object->taler_wtid ?: '-'),
	),
);

print '<div class="taler-flow-wrap">';
print '<div class="taler-flow-heading"><span class="fa fa-sitemap"></span>'.$langs->trans('OrderFlow').'</div>';
// Group steps into 3 rows: Taler order + Sales order, Invoice + Payment, Refund + Wire
$flowRows = array(
	array($flowSteps[0], $flowSteps[1]),
	array($flowSteps[2], $flowSteps[3]),
	array($flowSteps[4], $flowSteps[5]),
);
foreach ($flowRows as $row) {
	print '<div class="taler-flow-row">';
	foreach ($row as $step) {
		print '<div class="taler-flow-step '.dol_escape_htmltag($step['status']).'">';
		print '<div class="taler-flow-top">';
		print '<div class="taler-flow-title"><span class="fa '.dol_escape_htmltag($step['icon']).' taler-flow-icon"></span><span class="taler-flow-label">'.dol_escape_htmltag($step['label']).'</span></div>';
		print '<span class="taler-flow-badge">'.dol_escape_htmltag($step['badge']).'</span>';
		print '</div>';
		print '<div class="taler-flow-lines">';
		foreach ($step['lines'] as $line) {
			print '<div>'.$line.'</div>';
		}
		print '</div>';
		print '</div>';
	}
	print '</div>';
}
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
// Extrafields
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
