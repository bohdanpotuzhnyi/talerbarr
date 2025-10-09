<?php
/* Copyright (C) 2017       Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024-2025  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *    \file       talerconfig_card.php
 *    \ingroup    talerbarr
 *    \brief      Page to create/edit/view talerconfig
 */


// General defined Options
//if (! defined('CSRFCHECK_WITH_TOKEN'))     define('CSRFCHECK_WITH_TOKEN', '1');					// Force use of CSRF protection with tokens even for GET
//if (! defined('MAIN_AUTHENTICATION_MODE')) define('MAIN_AUTHENTICATION_MODE', 'aloginmodule');	// Force authentication handler
//if (! defined('MAIN_LANG_DEFAULT'))        define('MAIN_LANG_DEFAULT', 'auto');					// Force LANG (language) to a particular value
//if (! defined('MAIN_SECURITY_FORCECSP'))   define('MAIN_SECURITY_FORCECSP', 'none');				// Disable all Content Security Policies
//if (! defined('NOBROWSERNOTIF'))     		 define('NOBROWSERNOTIF', '1');					// Disable browser notification
//if (! defined('NOIPCHECK'))                define('NOIPCHECK', '1');						// Do not check IP defined into conf $dolibarr_main_restrict_ip
//if (! defined('NOLOGIN'))                  define('NOLOGIN', '1');						// Do not use login - if this page is public (can be called outside logged session). This includes the NOIPCHECK too.
//if (! defined('NOREQUIREAJAX'))            define('NOREQUIREAJAX', '1');       	  		// Do not load ajax.lib.php library
//if (! defined('NOREQUIREDB'))              define('NOREQUIREDB', '1');					// Do not create database handler $db
//if (! defined('NOREQUIREHTML'))            define('NOREQUIREHTML', '1');					// Do not load html.form.class.php
//if (! defined('NOREQUIREMENU'))            define('NOREQUIREMENU', '1');					// Do not load and show top and left menu
//if (! defined('NOREQUIRESOC'))             define('NOREQUIRESOC', '1');					// Do not load object $mysoc
//if (! defined('NOREQUIRETRAN'))            define('NOREQUIRETRAN', '1');					// Do not load object $langs
//if (! defined('NOREQUIREUSER'))            define('NOREQUIREUSER', '1');					// Do not load object $user
//if (! defined('NOSCANGETFORINJECTION'))    define('NOSCANGETFORINJECTION', '1');			// Do not check injection attack on GET parameters
//if (! defined('NOSCANPOSTFORINJECTION'))   define('NOSCANPOSTFORINJECTION', '1');			// Do not check injection attack on POST parameters
//if (! defined('NOSESSION'))                define('NOSESSION', '1');						// On CLI mode, no need to use web sessions
//if (! defined('NOSTYLECHECK'))             define('NOSTYLECHECK', '1');					// Do not check style html tag into posted data
//if (! defined('NOTOKENRENEWAL'))           define('NOTOKENRENEWAL', '1');					// Do not roll the Anti CSRF token (used if MAIN_SECURITY_CSRF_WITH_TOKEN is on)


// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
dol_include_once('/talerbarr/class/talerconfig.class.php');
dol_include_once('/talerbarr/lib/talerbarr_talerconfig.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("talerbarr@talerbarr", "other"));

// Get parameters
$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$lineid   = GETPOSTINT('lineid');

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'aZ09');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : str_replace('_', '', basename(dirname(__FILE__)).basename(__FILE__, '.php')); // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');					// if not set, a default page will be used
$backtopageforcancel = GETPOST('backtopageforcancel', 'alpha');	// if not set, $backtopage will be used
$optioncss = GETPOST('optioncss', 'aZ'); // Option for the css output (always '' except when 'print')
$dol_openinpopup = GETPOST('dol_openinpopup', 'aZ09');

// Initialize a technical objects
$object = new TalerConfig($db);
$extrafields = new ExtraFields($db);
$diroutputmassaction = $conf->talerbarr->dir_output.'/temp/massgeneration/'.$user->id;
$hookmanager->initHooks(array($object->element.'card', 'globalcard')); // Note that conf->hooks_modules contains array
$soc = null;

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);


$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Initialize array of search criteria
$search_all = trim(GETPOST("search_all", 'alpha'));
$search = array();
foreach ($object->fields as $key => $val) {
	if (GETPOST('search_'.$key, 'alpha')) {
		$search[$key] = GETPOST('search_'.$key, 'alpha');
	}
}

if (empty($action) && empty($id) && empty($ref)) {
	$action = 'view';
}

// Load object
include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php'; // Must be 'include', not 'include_once'.

// There is several ways to check permission.
// Set $enablepermissioncheck to 1 to enable a minimum low level of checks
$enablepermissioncheck = getDolGlobalInt('TALERBARR_ENABLE_PERMISSION_CHECK');
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('talerbarr', 'talerconfig', 'read');
	$permissiontoadd = $user->hasRight('talerbarr', 'talerconfig', 'write'); // Used by the include of actions_addupdatedelete.inc.php and actions_lineupdown.inc.php
	$permissiontodelete = $user->hasRight('talerbarr', 'talerconfig', 'delete') || ($permissiontoadd && isset($object->status) && $object->status == $object::STATUS_DRAFT);
	$permissionnote = $user->hasRight('talerbarr', 'talerconfig', 'write'); // Used by the include of actions_setnotes.inc.php
	$permissiondellink = $user->hasRight('talerbarr', 'talerconfig', 'write'); // Used by the include of actions_dellink.inc.php
} else {
	$permissiontoread = 1;
	$permissiontoadd = 1; // Used by the include of actions_addupdatedelete.inc.php and actions_lineupdown.inc.php
	$permissiontodelete = 1;
	$permissionnote = 1;
	$permissiondellink = 1;
}

$upload_dir = $conf->talerbarr->multidir_output[isset($object->entity) ? $object->entity : 1].'/talerconfig';

// Security check (enable the most restrictive one)
//if ($user->socid > 0) accessforbidden();
//if ($user->socid > 0) $socid = $user->socid;
//$isdraft = (isset($object->status) && ($object->status == $object::STATUS_DRAFT) ? 1 : 0);
//restrictedArea($user, $object->module, $object, $object->table_element, $object->element, 'fk_soc', 'rowid', $isdraft);
if (!isModEnabled($object->module)) {
	accessforbidden("Module ".$object->module." not enabled");
}
if (!$permissiontoread) {
	accessforbidden();
}

$error = 0;

/*
 * Preload config
 */
if ($action === 'view' && (empty($object->id))) {
	$errmsg = null;
	try {
		$singleton = TalerConfig::fetchSingletonVerified($db, $errmsg);
	} catch (Throwable $t) {
		echo '<hr>'; // And this one also
		$message = __FILE__ . ': fetchSingletonVerified exception: ' . $t->getMessage();
		dol_syslog($message, LOG_ERR);
		echo '<pre>' . htmlspecialchars($message) . '</pre>';
		setEventMessages('Internal error while loading configuration', null, 'errors');
		// Fall back to create to avoid a white page
		if (!headers_sent()) header('Location: '.$_SERVER['PHP_SELF'].'?action=create', true, 302);
		exit;
	}
	if (!$singleton) {
		// No config in DB: go to create
		if ($errmsg) setEventMessages($errmsg, null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?action=create');
		exit;
	}

	// If invalid, stash values to prefill the create form, then redirect there
	if (property_exists($singleton, 'verification_ok') && !$singleton->verification_ok) {
		if (!empty($singleton->verification_error)) {
			setEventMessages($singleton->verification_error, null, 'errors');
		} elseif ($errmsg) {
			setEventMessages($errmsg, null, 'errors');
		}

		// Prefill values for the create screen
		$_SESSION['talerconfig_prefill'] = array(
			// adjust keys to your actual field names
			'talermerchanturl' => isset($singleton->talermerchanturl) ? $singleton->talermerchanturl : '',
			'username'         => isset($singleton->username) ? $singleton->username : '',
			'talertoken'       => isset($singleton->talertoken) ? $singleton->talertoken : (isset($singleton->taler_token) ? $singleton->taler_token : ''),
		);

		header('Location: '.$_SERVER['PHP_SELF'].'?action=create');
		exit;
	}

	if (!empty($singleton->id)) {
		setEventMessages($langs->trans('TalerConfigIsValid'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int) $singleton->id);
		exit;
	}

	// Valid: use it and inform user
	$object = $singleton;
	setEventMessages($langs->trans('TalerConfigIsValid'), null, 'mesgs');
}



/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if (empty($backtopage) || ($cancel && empty($id))) {
		if (empty($backtopage) || ($cancel && strpos($backtopage, '__ID__'))) {
			if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
				$backtopage = dol_buildpath('/talerbarr/talerconfig_card.php', 1);
			} else {
				$backtopage = dol_buildpath('/talerbarr/talerconfig_card.php', 1).'?id='.((!empty($id) && $id > 0) ? $id : '__ID__');
			}
		}
	}

	$triggermodname = 'TALERBARR_MYOBJECT_MODIFY'; // Name of trigger action code to execute when we modify record

	// Actions cancel, add, update, update_extras, confirm_validate, confirm_delete, confirm_deleteline, confirm_clone, confirm_close, confirm_setdraft, confirm_reopen
	include DOL_DOCUMENT_ROOT.'/core/actions_addupdatedelete.inc.php';

	// Actions when linking object each other
	include DOL_DOCUMENT_ROOT.'/core/actions_dellink.inc.php';

	// Actions when printing a doc from card
	include DOL_DOCUMENT_ROOT.'/core/actions_printing.inc.php';

	// Action to move up and down lines of object
	//include DOL_DOCUMENT_ROOT.'/core/actions_lineupdown.inc.php';

	// Action to build doc
	include DOL_DOCUMENT_ROOT.'/core/actions_builddoc.inc.php';

	if ($action == 'set_thirdparty' && $permissiontoadd) {
		$object->setValueFrom('fk_soc', GETPOSTINT('fk_soc'), '', null, 'date', '', $user, $triggermodname);
	}
	if ($action == 'classin' && $permissiontoadd) {
		$object->setProject(GETPOSTINT('projectid'));
	}

	// Actions to send emails
	$triggersendname = 'TALERBARR_MYOBJECT_SENTBYMAIL';
	$autocopy = 'MAIN_MAIL_AUTOCOPY_MYOBJECT_TO';
	$trackid = 'talerconfig'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
}




/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);
$formproject = new FormProjets($db);

$title = $langs->trans("TalerConfig")." - ".$langs->trans('Card');
//$title = $object->ref." - ".$langs->trans('Card');
if ($action == 'create') {
	$title = $langs->trans("NewObject", $langs->transnoentitiesnoconv("TalerConfig"));
}
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-talerbarr page-card');

print '<script>
jQuery(function($){
  var $form = $("form[action=\'"+'.json_encode($_SERVER["PHP_SELF"]).'+ "\']");
  if (!$form.length) return;

  // Track which submit button was clicked (Save vs Create), useful to restore classes
  var $clickedBtn = null;
  $form.on("click", "input[type=\'submit\'],button[type=\'submit\']", function(){ $clickedBtn = $(this); });

  function parseUrl(u){
    try { return new URL(/^https?:\/\//i.test(u) ? u : "https://"+u); } catch(e){ return null; }
  }

  function normalizeUrlAndExtractUsername(){
    var $url  = $("#talermerchanturl");
    var $user = $("#username");
    var v = ($url.val()||"").trim();
    if (!v) return;

    var U = parseUrl(v);
    if (!U) return;

    var path = (U.pathname || "/").replace(/\/+$/,"");
    // pull /instances/{user} into the username field if pasted
    var m = path.match(/\\/instances\\/([^\\/]+)/);
    if (m){
      if (!$user.val()) $user.val(decodeURIComponent(m[1]));
      path = path.replace(/\\/instances\\/[^\\/]+\\/?/,"/");
    }
    // drop trailing /private if present
    path = path.replace(/\\/private\\/?$/,"/");
    U.pathname = path || "/";
    var normalized = U.origin + (U.pathname.endsWith("/") ? U.pathname : U.pathname + "/");
    $url.val(normalized);
  }

  function ensureHidden(name){
    var $f = $form.find("input[name=\'"+name+"\']");
    if (!$f.length) {
      $f = $("<input>", { type:"hidden", name:name });
      $form.append($f);
    }
    return $f;
  }

  async function getTokenThenSubmit(ev, mode){
    if ($form.data("taler-submit-guard")) return;  // prevent loops
    ev.preventDefault();

    normalizeUrlAndExtractUsername();

    var baseUrl  = ($("#talermerchanturl").val()||"").trim();
    var username = ($("#username").val()||"").trim();
    var pwd      = ($("input[name=\'taler_password\']").val()||"");

    var isCreate = (mode === "add");
    var mustMint = isCreate || (!!pwd); // on update only if password is provided

    if (!mustMint){
      // Nothing to mint on update, let it pass
      $form.data("taler-submit-guard", true);
      $form.trigger("submit");
      return;
    }

    // UI: temporarily remove button class to avoid any built-in handler bounce
    var $btn = $clickedBtn && $clickedBtn.length ? $clickedBtn : $form.find(".button-add,.button-save").first();
    var originalClass = $btn.attr("class") || "";
    $btn.removeClass("button-add button-save");

    if (!baseUrl){ alert("Please provide Taler Merchant URL."); $btn.attr("class", originalClass); return; }
    if (!username){ alert("Please provide Username."); $btn.attr("class", originalClass); return; }
    if (!pwd){ alert("Please provide password to mint the token."); $btn.attr("class", originalClass); return; }

    // Build token URL:
    //  admin   -> {base}/private/token
    //  others  -> {base}/instances/{username}/private/token
    var tokenUrl = baseUrl.replace(/\\/+$/,"");
    if (username.toLowerCase() === "admin") {
      tokenUrl += "/private/token";
    } else {
      tokenUrl += "/instances/" + encodeURIComponent(username) + "/private/token";
    }

    try {
      var body = JSON.stringify({
        scope: "all:refreshable",
        duration: { d_us: "forever" }
      });

      var basic = btoa(username + ":" + pwd);

      var resp = await fetch(tokenUrl, {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "Authorization": "Basic " + basic
        },
        body
      });

      if (!resp.ok) {
        alert("Token endpoint returned HTTP " + resp.status);
        $btn.attr("class", originalClass);
        return;
      }

      var data = {};
      try { data = await resp.json(); } catch(e){}

      // Accept both legacy "token" and newer "access_token"
      var raw = "";
      if (typeof data.access_token === "string" && data.access_token.length) {
        raw = data.access_token;
      } else if (typeof data.token === "string" && data.token.length) {
        raw = data.token;
      }
      if (!raw) {
        alert("Token endpoint returned no token field.");
        $btn.attr("class", originalClass);
        return;
      }

      // Set token
      ensureHidden("talertoken").val(raw);

      // Set expiration
      var expTs = null;
      if (data.expiration) {
        if (data.expiration.t_s === "never") {
          // clamp to 2037-12-31 23:59:59 UTC to remain in MySQL DATETIME range and Dolibarr epoch logic
          expTs = Math.floor(Date.UTC(2037, 11, 31, 23, 59, 59) / 1000);
        } else if (typeof data.expiration.t_s === "number") {
          var TS_MAX = Math.floor(Date.UTC(2037, 11, 31, 23, 59, 59) / 1000);
          expTs = Math.min(data.expiration.t_s, TS_MAX);
        }
      }
      ensureHidden("expiration").val(expTs);

      // Clear password so it never reaches the server
      $("input[name=\'taler_password\']").val("");

      // Restore class and submit for real
      $btn.attr("class", originalClass);
      $form.data("taler-submit-guard", true);
      $form.trigger("submit");

    } catch (e) {
      alert("Error contacting token endpoint: " + (e && e.message ? e.message : e));
      $btn.attr("class", originalClass);
      return;
    }
  }

  // Intercept form submit (covers Enter key and button clicks)
  $form.on("submit", function(ev){
    var action = ($form.find("input[name=\'action\']").val()||"").toLowerCase();
    if ($form.data("taler-submit-guard")) return; // second pass
    if (action === "add")    return getTokenThenSubmit(ev, "add");
    if (action === "update") return getTokenThenSubmit(ev, "update");
    // otherwise let it pass
  });

  // Also explicitly guard direct clicks on Save/Create buttons
  $form.on("click", ".button-add,.button-save", function(ev){
    var action = ($form.find("input[name=\'action\']").val()||"").toLowerCase();
    if ($form.data("taler-submit-guard")) return;
    if (action === "add")    return getTokenThenSubmit(ev, "add");
    if (action === "update") return getTokenThenSubmit(ev, "update");
  });

  // Initial normalization on load
  normalizeUrlAndExtractUsername();
});
</script>';


// Part to create
if ($action == 'create') {
	if (empty($permissiontoadd)) {
		accessforbidden('NotEnoughPermissions', 0, 1);
	}

	print load_fiche_titre($title, '', $object->picto);

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	if ($backtopage) {
		print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
	}
	if ($backtopageforcancel) {
		print '<input type="hidden" name="backtopageforcancel" value="'.$backtopageforcancel.'">';
	}
	if ($dol_openinpopup) {
		print '<input type="hidden" name="dol_openinpopup" value="'.$dol_openinpopup.'">';
	}

	print dol_get_fiche_head(array(), '');


	print '<table class="border centpercent tableforfieldcreate">'."\n";

	// Not including unwanted(output fields)
	unset($object->fields['status']);
	unset($object->fields['syncdirection']);
	unset($object->fields['entity']);
	unset($object->fields['fk_bank_account']);
	unset($object->fields['fk_default_customer']);
	print '<style>.field_expiration{display:none !important;}</style>';
	print '<style>.field_talertoken{display:none !important;}</style>';

	// Common attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_add.tpl.php';

	// - transient password field -
	print '<tr>';
	print '  <td class="fieldrequired">'.$langs->trans("TalerPassword").'</td>';
	print '  <td><input type="password" class="flat" name="taler_password" /></td>';
	print '</tr>';

	// --- custom toggle ---
	print '<tr class="field_syncdirection">';
	print '  <td class="fieldrequired titlefieldcreate"><label id="syncdirection_label" for="syncdirection_toggle" class="block">'.$langs->trans("SyncFrom").' Dolibarr</label></td>';
	print '  <td class="valuefieldcreate">';
	print '    <input type="hidden" name="syncdirection" id="syncdirection_hidden" value="0">';
	print '    <label class="switch">';
	print '      <input type="checkbox" id="syncdirection_toggle" />';
	print '      <span class="slider round"></span>';
	print '    </label>';
	print '  </td>';
	print '</tr>';

	// --- manual fk_bank_account row ---
	$fk_account = GETPOSTINT('fk_bank_account');
	if (!$fk_account && !GETPOSTISSET('fk_bank_account') && !empty($object->fk_bank_account)) {
		$fk_account = (int) $object->fk_bank_account;
	}
	print '<tr class="field_fk_bank_account">';
	print '  <td class="fieldrequired">'.$langs->trans("BankAccount").'</td><td>';
	print img_picto('', 'bank_account', 'class="pictofixedwidth"');
	print $form->select_comptes($fk_account, 'fk_bank_account', 0, '', 1, '', 0, 'maxwidth250 widthcentpercentminusx', 1);
	print '</td></tr>';

	$defaultCustomerId = GETPOSTINT('fk_default_customer');
	if (!$defaultCustomerId && !GETPOSTISSET('fk_default_customer')) {
		if (!empty($object->fk_default_customer)) {
			$defaultCustomerId = (int) $object->fk_default_customer;
		} else {
			$defaultCustomerId = (int) getDolGlobalInt('TALERBARR_DEFAULT_SOCID');
		}
	}
	print '<tr class="field_fk_default_customer">';
	print '  <td class="fieldrequired">'.$langs->trans("TalerDefaultCustomer").'</td><td>';
	print img_picto('', 'company', 'class="pictofixedwidth"');
	$customerFilter = '((s.client:IN:1,2,3) AND (s.status:=:1))';
	print $form->select_company($defaultCustomerId, 'fk_default_customer', $customerFilter, 1, '', 0, array(), 0, 'maxwidth300 widthcentpercentminusx');
	print '</td></tr>';

	// style for the switch
	print '<style>
.switch{position:relative;display:inline-block;width:46px;height:24px;vertical-align:middle}
.switch input{opacity:0;width:0;height:0}
.switch .slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#263d5c;transition:.2s;border-radius:24px}
.switch .slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;transition:.2s;border-radius:50%}
.switch input:checked + .slider{background:#0042b3}
.switch input:checked + .slider:before{transform:translateX(22px)}
</style>';

	print '<script>
jQuery(function($){
  var $h = $("#syncdirection_hidden");
  var $t = $("#syncdirection_toggle");
  var $label = $("#syncdirection_label");

  function apply(v){
    $t.prop("checked", !!v);
    $h.val(v ? 1 : 0);
    $label.text("Sync from " + (v ? "Taler" : "Dolibarr"));
  }

  // init from object
  apply('.((!empty($object->syncdirection) && (int) $object->syncdirection === 1) ? 'true' : 'false').');

  $t.on("change", function(){ apply($(this).is(":checked")); });
});
</script>';


	// Other attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_add.tpl.php';

	print '</table>'."\n";

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel("Create");

	print '</form>';

	//dol_set_focus('input[name="ref"]');
}

// Part to edit record
if (($id || $ref) && $action == 'edit') {
	print load_fiche_titre($langs->trans("TalerConfig"), '', $object->picto);

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="'.$object->id.'">';
	if ($backtopage) {
		print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
	}
	if ($backtopageforcancel) {
		print '<input type="hidden" name="backtopageforcancel" value="'.$backtopageforcancel.'">';
	}

	print dol_get_fiche_head();

	print '<table class="border centpercent tableforfieldedit">'."\n";

	unset($object->fields['status']);
	unset($object->fields['syncdirection']);
	unset($object->fields['entity']);
	unset($object->fields['fk_bank_account']);
	unset($object->fields['fk_default_customer']);
	print '<style>.field_expiration{display:none !important;}</style>';
	print '<style>.field_talertoken{display:none !important;}</style>';

	// Common attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_edit.tpl.php';

	// — transient password field —
	print '<tr>';
	print '  <td class="fieldrequired">'.$langs->trans("TalerPassword").'</td>';
	print '  <td><input type="password" class="flat" name="taler_password" /></td>';
	print '</tr>';

	// --- custom toggle ---
	print '<tr class="field_syncdirection">';
	print '  <td class="fieldrequired titlefieldcreate"><label id="syncdirection_label" for="syncdirection_toggle" class="block">'.$langs->trans("SyncFrom").' Dolibarr</label></td>';
	print '  <td class="valuefieldcreate">';
	print '    <input type="hidden" name="syncdirection" id="syncdirection_hidden" value="0">';
	print '    <label class="switch">';
	print '      <input type="checkbox" id="syncdirection_toggle" />';
	print '      <span class="slider round"></span>';
	print '    </label>';
	print '  </td>';
	print '</tr>';

	// --- manual fk_bank_account row ---
	$fk_account = GETPOSTINT('fk_bank_account');
	if (!$fk_account && !GETPOSTISSET('fk_bank_account') && !empty($object->fk_bank_account)) {
		$fk_account = (int) $object->fk_bank_account;
	}
	print '<tr class="field_fk_bank_account">';
	print '  <td class="fieldrequired">'.$langs->trans("BankAccount").'</td><td>';
	print img_picto('', 'bank_account', 'class="pictofixedwidth"');
	print $form->select_comptes($fk_account, 'fk_bank_account', 0, '', 1, '', 0, 'maxwidth250 widthcentpercentminusx', 1);
	print '</td></tr>';

	$defaultCustomerId = GETPOSTINT('fk_default_customer');
	if (!$defaultCustomerId && !GETPOSTISSET('fk_default_customer')) {
		if (!empty($object->fk_default_customer)) {
			$defaultCustomerId = (int) $object->fk_default_customer;
		} else {
			$defaultCustomerId = (int) getDolGlobalInt('TALERBARR_DEFAULT_SOCID');
		}
	}
	print '<tr class="field_fk_default_customer">';
	print '  <td class="fieldrequired">'.$langs->trans("TalerDefaultCustomer").'</td><td>';
	print img_picto('', 'company', 'class="pictofixedwidth"');
	$customerFilter = '((s.client:IN:1,2,3) AND (s.status:=:1))';
	print $form->select_company($defaultCustomerId, 'fk_default_customer', $customerFilter, 1, '', 0, array(), 0, 'maxwidth300 widthcentpercentminusx');
	print '</td></tr>';

	// style for the switch
	print '<style>
.switch{position:relative;display:inline-block;width:46px;height:24px;vertical-align:middle}
.switch input{opacity:0;width:0;height:0}
.switch .slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#263d5c;transition:.2s;border-radius:24px}
.switch .slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;transition:.2s;border-radius:50%}
.switch input:checked + .slider{background:#0042b3}
.switch input:checked + .slider:before{transform:translateX(22px)}
</style>';

	print '<script>
jQuery(function($){
  var $h = $("#syncdirection_hidden");
  var $t = $("#syncdirection_toggle");
  var $label = $("#syncdirection_label");

  function apply(v){
    $t.prop("checked", !!v);
    $h.val(v ? 1 : 0);
    $label.text("Sync from " + (v ? "Taler" : "Dolibarr"));
  }

  // init from object
  apply('.((!empty($object->syncdirection) && (int) $object->syncdirection === 1) ? 'true' : 'false').');

  $t.on("change", function(){ apply($(this).is(":checked")); });
});
</script>';

	// Other attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_edit.tpl.php';

	print '</table>';

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel();

	print '</form>';
}

// Part to show record
if ($object->id > 0 && (empty($action) || ($action != 'edit' && $action != 'create'))) {
	$head = talerconfigPrepareHead($object);

	print dol_get_fiche_head($head, 'card', $langs->trans("TalerConfig"), -1, $object->picto, 0, '', '', 0, '', 1);

	$formconfirm = '';

	// Confirmation to delete (using preloaded confirm popup)
	if ($action == 'delete' || ($conf->use_javascript_ajax && empty($conf->dol_use_jmobile))) {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('DeleteTalerConfig'), $langs->trans('ConfirmDeleteObject'), 'confirm_delete', '', 0, 'action-delete');
	}
	// Confirmation to delete line
	if ($action == 'deleteline') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&lineid='.$lineid, $langs->trans('DeleteLine'), $langs->trans('ConfirmDeleteLine'), 'confirm_deleteline', '', 0, 1);
	}

	// Clone confirmation
	if ($action == 'clone') {
		// Create an array for form
		$formquestion = array();
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('ToClone'), $langs->trans('ConfirmCloneAsk', $object->ref), 'confirm_clone', $formquestion, 'yes', 1);
	}

	// Confirmation of action xxxx (You can use it for xxx = 'close', xxx = 'reopen', ...)
	// if ($action == 'xxx') {
	// 	$text = $langs->trans('ConfirmActionXxx', $object->ref);
	// 	if (isModEnabled('notification')) {
	// 		require_once DOL_DOCUMENT_ROOT . '/core/class/notify.class.php';
	// 		$notify = new Notify($db);
	// 		$text .= '<br>';
	// 		$text .= $notify->confirmMessage('MYOBJECT_CLOSE', $object->socid, $object);
	// 	}

	// 	$formquestion = array();

	// 	$forcecombo=0;
	// 	if ($conf->browser->name == 'ie') $forcecombo = 1;	// There is a bug in IE10 that make combo inside popup crazy
	// 	$formquestion = array(
	// 		// 'text' => $langs->trans("ConfirmClone"),
	// 		// array('type' => 'checkbox', 'name' => 'clone_content', 'label' => $langs->trans("CloneMainAttributes"), 'value' => 1),
	// 		// array('type' => 'checkbox', 'name' => 'update_prices', 'label' => $langs->trans("PuttingPricesUpToDate"), 'value' => 1),
	// 		// array('type' => 'other',    'name' => 'idwarehouse',   'label' => $langs->trans("SelectWarehouseForStockDecrease"), 'value' => $formproduct->selectWarehouses(GETPOST('idwarehouse')?GETPOST('idwarehouse'):'ifone', 'idwarehouse', '', 1, 0, 0, '', 0, $forcecombo))
	// 	);
	// 	$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('XXX'), $text, 'confirm_xxx', $formquestion, 0, 1, 220);
	// }

	// Call Hook formConfirm
	$parameters = array('formConfirm' => $formconfirm, 'lineid' => $lineid);
	$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	if (empty($reshook)) {
		$formconfirm .= $hookmanager->resPrint;
	} elseif ($reshook > 0) {
		$formconfirm = $hookmanager->resPrint;
	}

	// Print form confirm
	print $formconfirm;


	// Object card
	// ------------------------------------------------------------
	//$linkback = '<a href="'.dol_buildpath('/talerbarr/talerconfig_list.php', 1).'?restore_lastsearch_values=1'.(!empty($socid) ? '&socid='.$socid : '').'">'.$langs->trans("BackToList").'</a>';

	$morehtmlref = '<div class="refidno">';
	/*
		// Ref customer
		$morehtmlref .= $form->editfieldkey("RefCustomer", 'ref_client', $object->ref_client, $object, $usercancreate, 'string', '', 0, 1);
		$morehtmlref .= $form->editfieldval("RefCustomer", 'ref_client', $object->ref_client, $object, $usercancreate, 'string'.(getDolGlobalInt('THIRDPARTY_REF_INPUT_SIZE') ? ':'.getDolGlobalInt('THIRDPARTY_REF_INPUT_SIZE') : ''), '', null, null, '', 1);
		// Thirdparty
		$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1, 'customer');
		if (!getDolGlobalInt('MAIN_DISABLE_OTHER_LINK') && $object->thirdparty->id > 0) {
			$morehtmlref .= ' (<a href="'.DOL_URL_ROOT.'/commande/list.php?socid='.$object->thirdparty->id.'&search_societe='.urlencode($object->thirdparty->name).'">'.$langs->trans("OtherOrders").'</a>)';
		}
		// Project
		if (isModEnabled('project')) {
			$langs->load("projects");
			$morehtmlref .= '<br>';
			if ($permissiontoadd) {
				$morehtmlref .= img_picto($langs->trans("Project"), 'project', 'class="pictofixedwidth"');
				if ($action != 'classify') {
					$morehtmlref .= '<a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?action=classify&token='.newToken().'&id='.$object->id.'">'.img_edit($langs->transnoentitiesnoconv('SetProject')).'</a> ';
				}
				$morehtmlref .= $form->form_project($_SERVER['PHP_SELF'].'?id='.$object->id, $object->socid, $object->fk_project, ($action == 'classify' ? 'projectid' : 'none'), 0, 0, 0, 1, '', 'maxwidth300');
			} else {
				if (!empty($object->fk_project)) {
					$proj = new Project($db);
					$proj->fetch($object->fk_project);
					$morehtmlref .= $proj->getNomUrl(1);
					if ($proj->title) {
						$morehtmlref .= '<span class="opacitymedium"> - '.dol_escape_htmltag($proj->title).'</span>';
					}
				}
			}
		}
	*/
	$morehtmlref .= '</div>';


	//dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);


	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	//Hide underbanners
	print '<style>div.underbanner.clearboth{display:none!important}</style>';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">'."\n";

	// Common attributes
	unset($object->fields['entity']);
	unset($object->fields['fk_bank_account']);
	unset($object->fields['fk_default_customer']);
	//$keyforbreak='fieldkeytoswitchonsecondcolumn';	// We change column just before this field
	//unset($object->fields['fk_project']);				// Hide field already shown in banner
	//unset($object->fields['fk_soc']);					// Hide field already shown in banner
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_view.tpl.php';

	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$acc = new Account($db);
	if ($object->fk_bank_account && $acc->fetch($object->fk_bank_account) > 0) {
		print '<tr class="field_fk_bank_account">';
		print '  <td>'.$langs->trans("BankAccount").'</td><td>';
		print $acc->getNomUrl(1);
		print '</td></tr>';
	}

	$defaultCustomer = new Societe($db);
	if ($object->fk_default_customer && $defaultCustomer->fetch($object->fk_default_customer) > 0) {
		print '<tr class="field_fk_default_customer">';
		print '  <td>'.$langs->trans("TalerDefaultCustomer").'</td><td>';
		print $defaultCustomer->getNomUrl(1, 'customer');
		print '</td></tr>';
	}

	print '<script>
jQuery(function($){
  var $row = $(".field_syncdirection");
  if(!$row.length) return;

  var $cb  = $row.find("input[type=checkbox]");
  var $val = $row.find("td.valuefield");

  if($cb.length){
    var isTaler = $cb.is(":checked");
    var text    = "Sync from " + (isTaler ? "Taler" : "Dolibarr");
    var color   = isTaler ? "#0042b3" : "#263d5c";

    $val.empty().append(
      $("<span>", {
        class: "badge-sync",
        text: text,
        css: {
          background: color,
          color: "#fff",
          display: "inline-block",
          padding: "2px 10px",
          "border-radius": "9999px",
          "font-weight": "600",
          "font-size": "90%"
        }
      })
    );
  }
});
</script>';

	// Other attributes. Fields from hook formObjectOptions and Extrafields.
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_view.tpl.php';

	print '</table>';
	print '</div>';
	print '</div>';

	print '<div class="clearboth"></div>';

	print dol_get_fiche_end();


	/*
	 * Lines
	 */

	if (!empty($object->table_element_line)) {
		// Show object lines
		$result = $object->getLinesArray();

		print '	<form name="addproduct" id="addproduct" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.(($action != 'editline') ? '' : '#line_'.GETPOSTINT('lineid')).'" method="POST">
		<input type="hidden" name="token" value="' . newToken().'">
		<input type="hidden" name="action" value="' . (($action != 'editline') ? 'addline' : 'updateline').'">
		<input type="hidden" name="mode" value="">
		<input type="hidden" name="page_y" value="">
		<input type="hidden" name="id" value="' . $object->id.'">
		';

		if (!empty($conf->use_javascript_ajax) && $object->status == 0) {
			include DOL_DOCUMENT_ROOT.'/core/tpl/ajaxrow.tpl.php';
		}

		print '<div class="div-table-responsive-no-min">';
		if (!empty($object->lines) || ($object->status == $object::STATUS_DRAFT && $permissiontoadd && $action != 'selectlines' && $action != 'editline')) {
			print '<table id="tablelines" class="noborder noshadow" width="100%">';
		}

		if (!empty($object->lines)) {
			$object->printObjectLines($action, $mysoc, null, GETPOSTINT('lineid'), 1);
		}

		// Form to add new line
		if ($object->status == 0 && $permissiontoadd && $action != 'selectlines') {
			if ($action != 'editline') {
				// Add products/services form

				$parameters = array();
				$reshook = $hookmanager->executeHooks('formAddObjectLine', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
				if ($reshook < 0) {
					setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
				}
				if (empty($reshook)) {
					$object->formAddObjectLine(1, $mysoc, $soc);
				}
			}
		}

		if (!empty($object->lines) || ($object->status == $object::STATUS_DRAFT && $permissiontoadd && $action != 'selectlines' && $action != 'editline')) {
			print '</table>';
		}
		print '</div>';

		print "</form>\n";
	}


	// Buttons for actions

	if ($action != 'presend' && $action != 'editline') {
		print '<div class="tabsAction">'."\n";
		$parameters = array();
		$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}

		if (empty($reshook)) {
			// Send
			//if (empty($user->socid)) {
			//	print dolGetButtonAction('', $langs->trans('SendMail'), 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=presend&token='.newToken().'&mode=init#formmailbeforetitle');
			//}

			// Back to draft
			//if ($object->status == $object::STATUS_VALIDATED) {
			//	print dolGetButtonAction('', $langs->trans('SetToDraft'), 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=confirm_setdraft&confirm=yes&token='.newToken(), '', $permissiontoadd);
			//}

			// Modify
			print dolGetButtonAction('', $langs->trans('Modify'), 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=edit&token='.newToken(), '', $permissiontoadd);

			// Validate
			//if ($object->status == $object::STATUS_DRAFT) {
			//	if (empty($object->table_element_line) || (is_array($object->lines) && count($object->lines) > 0)) {
			//		print dolGetButtonAction('', $langs->trans('Validate'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_validate&confirm=yes&token='.newToken(), '', $permissiontoadd);
			//	} else {
			//		$langs->load("errors");
			//		print dolGetButtonAction($langs->trans("ErrorAddAtLeastOneLineFirst"), $langs->trans("Validate"), 'default', '#', '', 0);
			//	}
			//}

			// Clone
			//if ($permissiontoadd) {
			//	print dolGetButtonAction('', $langs->trans('ToClone'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.(!empty($object->socid) ? '&socid='.$object->socid : '').'&action=clone&token='.newToken(), '', $permissiontoadd);
			//}

			/*
			// Disable / Enable
			if ($permissiontoadd) {
				if ($object->status == $object::STATUS_ENABLED) {
					print dolGetButtonAction('', $langs->trans('Disable'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=disable&token='.newToken(), '', $permissiontoadd);
				} else {
					print dolGetButtonAction('', $langs->trans('Enable'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=enable&token='.newToken(), '', $permissiontoadd);
				}
			}
			if ($permissiontoadd) {
				if ($object->status == $object::STATUS_VALIDATED) {
					print dolGetButtonAction('', $langs->trans('Cancel'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=close&token='.newToken(), '', $permissiontoadd);
				} else {
					print dolGetButtonAction('', $langs->trans('Re-Open'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reopen&token='.newToken(), '', $permissiontoadd);
				}
			}
			*/

			// Delete (with preloaded confirm popup)
			$deleteUrl = $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delete&token='.newToken();
			$buttonId = 'action-delete-no-ajax';
			if ($conf->use_javascript_ajax && empty($conf->dol_use_jmobile)) {	// We can use preloaded confirm if not jmobile
				$deleteUrl = '';
				$buttonId = 'action-delete';
			}
			$params = array();
			print dolGetButtonAction('', $langs->trans("Delete"), 'delete', $deleteUrl, $buttonId, $permissiontodelete, $params);
		}
		print '</div>'."\n";
	}


	// Select mail models is same action as presend
	if (GETPOST('modelselected')) {
		$action = 'presend';
	}

	if ($action != 'presend') {
		print '<div class="fichecenter"><div class="fichehalfleft">';
		print '<a name="builddoc"></a>'; // ancre

		$includedocgeneration = 0;

		// Documents
		if ($includedocgeneration) {
			$objref = dol_sanitizeFileName($object->ref);
			$relativepath = $objref.'/'.$objref.'.pdf';
			$filedir = $conf->talerbarr->dir_output.'/'.$object->element.'/'.$objref;
			$urlsource = $_SERVER["PHP_SELF"]."?id=".$object->id;
			$genallowed = $permissiontoread; // If you can read, you can build the PDF to read content
			$delallowed = $permissiontoadd; // If you can create/edit, you can remove a file on card
			print $formfile->showdocuments('talerbarr:TalerConfig', $object->element.'/'.$objref, $filedir, $urlsource, $genallowed, $delallowed, $object->model_pdf, 1, 0, 0, 28, 0, '', '', '', $langs->defaultlang);
		}

		// Show links to link elements
		//$tmparray = $form->showLinkToObjectBlock($object, array(), array('talerconfig'), 1);
		//if (is_array($tmparray)) {
		//	$linktoelem = $tmparray['linktoelem'];
		//	$htmltoenteralink = $tmparray['htmltoenteralink'];
		//	print $htmltoenteralink;
		//	$somethingshown = $form->showLinkedObjectBlock($object, $linktoelem);
		//} else {
			// backward compatibility
		//	$somethingshown = $form->showLinkedObjectBlock($object, $tmparray);
		//}

		print '</div><div class="fichehalfright">';

		$MAXEVENT = 10;

		$morehtmlcenter = dolGetButtonTitle($langs->trans('SeeAll'), '', 'fa fa-bars imgforviewmode', dol_buildpath('/talerbarr/talerconfig_agenda.php', 1).'?id='.$object->id);

		$includeeventlist = 0;

		// List of actions on element
		if ($includeeventlist) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/html.formactions.class.php';
			$formactions = new FormActions($db);
			$somethingshown = $formactions->showactions($object, $object->element.'@'.$object->module, (is_object($object->thirdparty) ? $object->thirdparty->id : 0), 1, '', $MAXEVENT, '', $morehtmlcenter);
		}

		print '</div></div>';
	}

	//Select mail models is same action as presend
	if (GETPOST('modelselected')) {
		$action = 'presend';
	}

	// Presend form
	$modelmail = 'talerconfig';
	$defaulttopic = 'InformationMessage';
	$diroutput = $conf->talerbarr->dir_output;
	$trackid = 'talerconfig'.$object->id;

	include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}

// End of page
llxFooter();
$db->close();
