<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
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
 * 	\defgroup   talerbarr     Module TalerBarr
 *  \brief      TalerBarr module descriptor.
 *
 *  \file       htdocs/talerbarr/core/modules/modTalerBarr.class.php
 *  \ingroup    talerbarr
 *  \brief      Description and activation file for module TalerBarr
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module TalerBarr
 */
class modTalerBarr extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 273000;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'talerbarr';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = 'interface';

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleTalerBarrName' not found (TalerBarr is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// DESCRIPTION_FLAG
		// Module description, used if translation string 'ModuleTalerBarrDesc' not found (TalerBarr is name of module).
		$this->description = "TalerBarrDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "TalerBarrDescription";

		// Author
		$this->editor_name = 'Taler';
		$this->editor_url = '';		// Must be an external online web site
		$this->editor_squarred_logo = '';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@talerbarr'

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '0.1.1';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where TALERBARR is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'talerbarr.svg@talerbarr';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 1,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/talerbarr/css/talerbarr.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/talerbarr/js/talerbarr.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			/* BEGIN MODULEBUILDER HOOKSCONTEXTS */
			'hooks' => array(
				//   'data' => array(
				//       'hookcontext1',
				//       'hookcontext2',
				//   ),
				//   'entity' => '0',
			),
			/* END MODULEBUILDER HOOKSCONTEXTS */
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if the module provides a website template into doctemplates/websites/website_template-mytemplate
			'websitetemplates' => 0,
			// Set this to 1 if the module provides a captcha driver
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/talerbarr/temp","/talerbarr/subdir");
		$this->dirs = array("/talerbarr");

		// Config pages. Put here list of php page, stored into talerbarr/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@talerbarr");

		// Dependencies
		// A condition to hide module
		$this->hidden = getDolGlobalInt('MODULE_TALERBARR_DISABLED'); // A condition to disable module;
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array();
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("talerbarr@talerbarr");

		// Prerequisites
		$this->phpmin = array(8, 0); // Minimum version of PHP required by module
		// $this->phpmax = array(8, 0); // Maximum version of PHP required by module
		$this->need_dolibarr_version = array(20, -3); // Minimum version of Dolibarr required by module
		// $this->max_dolibarr_version = array(19, -3); // Maximum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array(); // Warning to show when we activate module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		$this->warnings_activation_ext = array(); // Warning to show when we activate an external module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		//$this->automatic_activation = array('FR'=>'TalerBarrWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = true;								// If true, can't be disabled

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('TALERBARR_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('TALERBARR_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array();

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/

		if (!isModEnabled("talerbarr")) {
			$conf->talerbarr = new stdClass();
			$conf->talerbarr->enabled = 0;
		}

		// Array to add new pages in new tabs
		/* BEGIN MODULEBUILDER TABS */
		$this->tabs = array();
		/* END MODULEBUILDER TABS */
		// Example:
		// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data' => 'objecttype:+tabname1:Title1:mylangfile@talerbarr:$user->hasRight(\'talerbarr\', \'read\'):/talerbarr/mynewtab1.php?id=__ID__');
		// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data' => 'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@talerbarr:$user->hasRight(\'othermodule\', \'read\'):/talerbarr/mynewtab2.php?id=__ID__',
		// To remove an existing tab identified by code tabname
		// $this->tabs[] = array('data' => 'objecttype:-tabname:NU:conditiontoremove');
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'delivery'         to add a tab in delivery view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'invoice_supplier' to add a tab in supplier invoice view
		// 'member'           to add a tab in foundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in sale order view
		// 'order_supplier'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'payment_supplier' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view


		// Dictionaries
		/* Example:
		 $this->dictionaries=array(
		 'langs' => 'talerbarr@talerbarr',
		 // List of tables we want to see into dictionary editor
		 'tabname' => array("table1", "table2", "table3"),
		 // Label of tables
		 'tablib' => array("Table1", "Table2", "Table3"),
		 // Request to select fields
		 'tabsql' => array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table3 as f'),
		 // Sort order
		 'tabsqlsort' => array("label ASC", "label ASC", "label ASC"),
		 // List of fields (result of select to show dictionary)
		 'tabfield' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields to edit a record)
		 'tabfieldvalue' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields for insert)
		 'tabfieldinsert' => array("code,label", "code,label", "code,label"),
		 // Name of columns with primary key (try to always name it 'rowid')
		 'tabrowid' => array("rowid", "rowid", "rowid"),
		 // Condition to show each dictionary
		 'tabcond' => array(isModEnabled('talerbarr'), isModEnabled('talerbarr'), isModEnabled('talerbarr')),
		 // Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
		 'tabhelp' => array(array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), ...),
		 );
		 */
		/* BEGIN MODULEBUILDER DICTIONARIES */
		$this->dictionaries = array();
		/* END MODULEBUILDER DICTIONARIES */

		// Boxes/Widgets
		// Add here list of php file(s) stored in talerbarr/core/boxes that contains a class to show a widget.
		/* BEGIN MODULEBUILDER WIDGETS */
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'talerbarrwidget1.php@talerbarr',
			//      'note' => 'Widget provided by TalerBarr',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);
		/* END MODULEBUILDER WIDGETS */

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		$arraydate  = dol_getdate(dol_now());
		$datestart  = dol_mktime(
			1,
			0,
			0,
			$arraydate['mon'],
			($arraydate['hours'] >= 1 ? $arraydate['mday'] + 1 : $arraydate['mday']),
			$arraydate['year']
		);

		/* BEGIN MODULEBUILDER CRON */
		$this->cronjobs = array(
			0 => array(
				'label'         => 'TalerDailySync',
				'jobtype'       => 'method',
				'class'         => '/talerbarr/lib/talersync.lib.php',
				'objectname'    => 'TalerSyncUtil',
				'method'        => 'launchBackgroundSync',
				'parameters'    => '',
				'comment'       => 'Auto Sync of Dolibarr ↔ GNU Taler',
				'frequency'     => 1,
				'unitfrequency' => 86400,
				'datestart'     => $datestart,
				'status'        => 0,
				'test'          => 'isModEnabled("talerbarr")',
				'priority'      => 50,
			),
			//  0 => array(
			//      'label' => 'MyJob label',
			//      'jobtype' => 'method',
			//      'class' => '/talerbarr/class/talerconfig.class.php',
			//      'objectname' => 'TalerConfig',
			//      'method' => 'doScheduledJob',
			//      'parameters' => '',
			//      'comment' => 'Comment',
			//      'frequency' => 2,
			//      'unitfrequency' => 3600,
			//      'status' => 0,
			//      'test' => 'isModEnabled("talerbarr")',
			//      'priority' => 50,
			//  ),
		);
		/* END MODULEBUILDER CRON */
		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'isModEnabled("talerbarr")', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'isModEnabled("talerbarr")', 'priority'=>50)
		// );

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		// Add here entries to declare new permissions
		/* BEGIN MODULEBUILDER PERMISSIONS */
		/*
		$o = 1;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 1); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Read objects of TalerBarr'; // Permission label
		$this->rights[$r][4] = 'talerconfig';
		$this->rights[$r][5] = 'read'; // In php code, permission will be checked by test if ($user->hasRight('talerbarr', 'talerconfig', 'read'))
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 2); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Create/Update objects of TalerBarr'; // Permission label
		$this->rights[$r][4] = 'talerconfig';
		$this->rights[$r][5] = 'write'; // In php code, permission will be checked by test if ($user->hasRight('talerbarr', 'talerconfig', 'write'))
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 3); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Delete objects of TalerBarr'; // Permission label
		$this->rights[$r][4] = 'talerconfig';
		$this->rights[$r][5] = 'delete'; // In php code, permission will be checked by test if ($user->hasRight('talerbarr', 'talerconfig', 'delete'))
		$r++;
		*/
		/* END MODULEBUILDER PERMISSIONS */


		// Main menu entries to add
		$this->menu = array();
		$r = 0;
		// Add here entries to declare new menus
		$pictoUrl = dol_buildpath('/custom/talerbarr/img/talerbarr.svg', 1);
		/* BEGIN MODULEBUILDER TOPMENU */
		$this->menu[$r++] = array(
			'fk_menu' => '', // Will be stored into mainmenu + leftmenu. Use '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'top', // This is a Top menu entry
			'titre' => 'ModuleTalerBarrName',
			'prefix' => img_picto('', $pictoUrl, 'class="pictofixedwidth valignmiddle"', 1),
			'mainmenu' => 'talerbarr',
			'leftmenu' => '',
			'url' => '/talerbarr/talerbarrindex.php',
			'langs' => 'talerbarr@talerbarr', // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("talerbarr")', // Define condition to show or hide menu entry. Use 'isModEnabled("talerbarr")' if entry must be visible if module is enabled.
			'perms' => '1', // Use 'perms'=>'$user->hasRight("talerbarr", "talerconfig", "read")' if you want your menu with a permission rules
			'target' => '',
			'user' => 2, // 0=Menu for internal users, 1=external users, 2=both
		);
		/* END MODULEBUILDER TOPMENU */

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=talerbarr',
			'type'     => 'left',
			'titre'    => 'TalerInventory',
			'prefix'   => img_picto('', 'product', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'talerbarr',
			'leftmenu' => 'talerinventory',
			'url'      => '',
			'langs'    => 'talerbarr@talerbarr',
			'position' => 1000 + $r,
			'enabled'  => 'isModEnabled("talerbarr")',
			'perms'    => '1',
			'target'   => '',
			'user'     => 2,
		);

		/* Child 1: Product Links list */
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=talerbarr,fk_leftmenu=talerinventory',
			'type'     => 'left',
			'titre'    => 'TalerProductLinkList',
			'prefix'   => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'talerbarr',
			'leftmenu' => 'talerproductlink_list',
			'url'      => '/talerbarr/talerproductlink_list.php', // adjust if your filename differs
			'langs'    => 'talerbarr@talerbarr',
			'position' => 1000 + $r,
			'enabled'  => 'isModEnabled("talerbarr")',
			'perms'    => '1',
			'target'   => '',
			'user'     => 2,
		);

		/* Child 2: Category Map list */
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=talerbarr,fk_leftmenu=talerinventory',
			'type'     => 'left',
			'titre'    => 'TalerCategoryMapList',
			'prefix'   => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'talerbarr',
			'leftmenu' => 'talercategorymap_list',
			'url'      => '/talerbarr/talercategorymap_list.php', // adjust if your filename is talercategorymap.php
			'langs'    => 'talerbarr@talerbarr',
			'position' => 1000 + $r,
			'enabled'  => 'isModEnabled("talerbarr")',
			'perms'    => '1',
			'target'   => '',
			'user'     => 2,
		);

		/* BEGIN MODULEBUILDER LEFTMENU TALERCONFIG */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=talerbarr',
			'type' => 'left',
			'titre' => 'TalerConfig',
			'prefix' => img_picto('', 'cog', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'talerbarr',
			'leftmenu' => 'talerconfig',
			'url' => '/talerbarr/talerconfig_card.php',
			'langs' => 'talerbarr@talerbarr',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("talerbarr")',
			'perms' => '1',
			'target' => '',
			'user' => 2,
			'object' => 'TalerConfig'
		);
		/* END MODULEBUILDER LEFTMENU TALERCONFIG */
		/* BEGIN MODULEBUILDER LEFTMENU MYOBJECT */
		/*
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=talerbarr',      // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',                          // This is a Left menu entry
			'titre' => 'TalerConfig',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'talerbarr',
			'leftmenu' => 'talerconfig',
			'url' => '/talerbarr/talerbarrindex.php',
			'langs' => 'talerbarr@talerbarr',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("talerbarr")', // Define condition to show or hide menu entry. Use 'isModEnabled("talerbarr")' if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("talerbarr", "talerconfig", "read")',
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'TalerConfig'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=talerbarr,fk_leftmenu=talerconfig',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'New_TalerConfig',
			'mainmenu' => 'talerbarr',
			'leftmenu' => 'talerbarr_talerconfig_new',
			'url' => '/talerbarr/talerconfig_card.php?action=create',
			'langs' => 'talerbarr@talerbarr',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("talerbarr")', // Define condition to show or hide menu entry. Use 'isModEnabled("talerbarr")' if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
			'perms' => '$user->hasRight("talerbarr", "talerconfig", "write")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'TalerConfig'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=talerbarr,fk_leftmenu=talerconfig',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'List_TalerConfig',
			'mainmenu' => 'talerbarr',
			'leftmenu' => 'talerbarr_talerconfig_list',
			'url' => '/talerbarr/talerconfig_list.php',
			'langs' => 'talerbarr@talerbarr',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("talerbarr")', // Define condition to show or hide menu entry. Use 'isModEnabled("talerbarr")' if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("talerbarr", "talerconfig", "read")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'TalerConfig'
		);
		*/
		/* END MODULEBUILDER LEFTMENU MYOBJECT */


		// Exports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER EXPORT MYOBJECT */
		/*
		$langs->load("talerbarr@talerbarr");
		$this->export_code[$r] = $this->rights_class.'_'.$r;
		$this->export_label[$r] = 'TalerConfigLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->export_icon[$r] = $this->picto;
		// Define $this->export_fields_array, $this->export_TypeFields_array and $this->export_entities_array
		$keyforclass = 'TalerConfig'; $keyforclassfile='/talerbarr/class/talerconfig.class.php'; $keyforelement='talerconfig@talerbarr';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		//$this->export_fields_array[$r]['t.fieldtoadd']='FieldToAdd'; $this->export_TypeFields_array[$r]['t.fieldtoadd']='Text';
		//unset($this->export_fields_array[$r]['t.fieldtoremove']);
		//$keyforclass = 'TalerConfigLine'; $keyforclassfile='/talerbarr/class/talerconfig.class.php'; $keyforelement='talerconfigline@talerbarr'; $keyforalias='tl';
		//include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		$keyforselect='talerconfig'; $keyforaliasextra='extra'; $keyforelement='talerconfig@talerbarr';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$keyforselect='talerconfigline'; $keyforaliasextra='extraline'; $keyforelement='talerconfigline@talerbarr';
		//include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$this->export_dependencies_array[$r] = array('talerconfigline' => array('tl.rowid','tl.ref')); // To force to activate one or several fields if we select some fields that need same (like to select a unique key if we ask a field of a child to avoid the DISTINCT to discard them, or for computed field than need several other fields)
		//$this->export_special_array[$r] = array('t.field' => '...');
		//$this->export_examplevalues_array[$r] = array('t.field' => 'Example');
		//$this->export_help_array[$r] = array('t.field' => 'FieldDescHelp');
		$this->export_sql_start[$r]='SELECT DISTINCT ';
		$this->export_sql_end[$r]  =' FROM '.$this->db->prefix().'talerbarr_talerconfig as t';
		//$this->export_sql_end[$r]  .=' LEFT JOIN '.$this->db->prefix().'talerbarr_talerconfig_line as tl ON tl.fk_talerconfig = t.rowid';
		$this->export_sql_end[$r] .=' WHERE 1 = 1';
		$this->export_sql_end[$r] .=' AND t.entity IN ('.getEntity('talerconfig').')';
		$r++; */
		/* END MODULEBUILDER EXPORT MYOBJECT */

		// Imports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER IMPORT MYOBJECT */
		/*
		$langs->load("talerbarr@talerbarr");
		$this->import_code[$r] = $this->rights_class.'_'.$r;
		$this->import_label[$r] = 'TalerConfigLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->import_icon[$r] = $this->picto;
		$this->import_tables_array[$r] = array('t' => $this->db->prefix().'talerbarr_talerconfig', 'extra' => $this->db->prefix().'talerbarr_talerconfig_extrafields');
		$this->import_tables_creator_array[$r] = array('t' => 'fk_user_author'); // Fields to store import user id
		$import_sample = array();
		$keyforclass = 'TalerConfig'; $keyforclassfile='/talerbarr/class/talerconfig.class.php'; $keyforelement='talerconfig@talerbarr';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinimport.inc.php';
		$import_extrafield_sample = array();
		$keyforselect='talerconfig'; $keyforaliasextra='extra'; $keyforelement='talerconfig@talerbarr';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinimport.inc.php';
		$this->import_fieldshidden_array[$r] = array('extra.fk_object' => 'lastrowid-'.$this->db->prefix().'talerbarr_talerconfig');
		$this->import_regex_array[$r] = array();
		$this->import_examplevalues_array[$r] = array_merge($import_sample, $import_extrafield_sample);
		$this->import_updatekeys_array[$r] = array('t.ref' => 'Ref');
		$this->import_convertvalue_array[$r] = array(
			't.ref' => array(
				'rule'=>'getrefifauto',
				'class'=>(!getDolGlobalString('TALERBARR_MYOBJECT_ADDON') ? 'mod_talerconfig_standard' : getDolGlobalString('TALERBARR_MYOBJECT_ADDON')),
				'path'=>"/core/modules/talerbarr/".(!getDolGlobalString('TALERBARR_MYOBJECT_ADDON') ? 'mod_talerconfig_standard' : getDolGlobalString('TALERBARR_MYOBJECT_ADDON')).'.php',
				'classobject'=>'TalerConfig',
				'pathobject'=>'/talerbarr/class/talerconfig.class.php',
			),
			't.fk_soc' => array('rule' => 'fetchidfromref', 'file' => '/societe/class/societe.class.php', 'class' => 'Societe', 'method' => 'fetch', 'element' => 'ThirdParty'),
			't.fk_user_valid' => array('rule' => 'fetchidfromref', 'file' => '/user/class/user.class.php', 'class' => 'User', 'method' => 'fetch', 'element' => 'user'),
			't.fk_mode_reglement' => array('rule' => 'fetchidfromcodeorlabel', 'file' => '/compta/paiement/class/cpaiement.class.php', 'class' => 'Cpaiement', 'method' => 'fetch', 'element' => 'cpayment'),
		);
		$this->import_run_sql_after_array[$r] = array();
		$r++; */
		/* END MODULEBUILDER IMPORT MYOBJECT */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create tables of module at module activation
		//$result = $this->_load_tables('/install/mysql/', 'talerbarr');
		$result = $this->_load_tables('/talerbarr/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}

		// Create extrafields during init
		//include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		//$extrafields = new ExtraFields($this->db);
		//$result0=$extrafields->addExtraField('talerbarr_separator1', "Separator 1", 'separator', 1,  0, 'thirdparty',   0, 0, '', array('options'=>array(1=>1)), 1, '', 1, 0, '', '', 'talerbarr@talerbarr', 'isModEnabled("talerbarr")');
		//$result1=$extrafields->addExtraField('talerbarr_myattr1', "New Attr 1 label", 'boolean', 1,  3, 'thirdparty',   0, 0, '', '', 1, '', -1, 0, '', '', 'talerbarr@talerbarr', 'isModEnabled("talerbarr")');
		//$result2=$extrafields->addExtraField('talerbarr_myattr2', "New Attr 2 label", 'varchar', 1, 10, 'project',      0, 0, '', '', 1, '', -1, 0, '', '', 'talerbarr@talerbarr', 'isModEnabled("talerbarr")');
		//$result3=$extrafields->addExtraField('talerbarr_myattr3', "New Attr 3 label", 'varchar', 1, 10, 'bank_account', 0, 0, '', '', 1, '', -1, 0, '', '', 'talerbarr@talerbarr', 'isModEnabled("talerbarr")');
		//$result4=$extrafields->addExtraField('talerbarr_myattr4', "New Attr 4 label", 'select',  1,  3, 'thirdparty',   0, 1, '', array('options'=>array('code1'=>'Val1','code2'=>'Val2','code3'=>'Val3')), 1,'', -1, 0, '', '', 'talerbarr@talerbarr', 'isModEnabled("talerbarr")');
		//$result5=$extrafields->addExtraField('talerbarr_myattr5', "New Attr 5 label", 'text',    1, 10, 'user',         0, 0, '', '', 1, '', -1, 0, '', '', 'talerbarr@talerbarr', 'isModEnabled("talerbarr")');

		// Permissions
		$this->remove($options);

		$sql = array();

		// Document templates
		$moduledir = dol_sanitizeFileName('talerbarr');
		$myTmpObjects = array();
		$myTmpObjects['TalerConfig'] = array('includerefgeneration' => 0, 'includedocgeneration' => 0);

		foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
			if ($myTmpObjectArray['includerefgeneration']) {
				$src = DOL_DOCUMENT_ROOT.'/install/doctemplates/'.$moduledir.'/template_talerconfigs.odt';
				$dirodt = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/doctemplates/'.$moduledir;
				$dest = $dirodt.'/template_talerconfigs.odt';

				if (file_exists($src) && !file_exists($dest)) {
					require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
					dol_mkdir($dirodt);
					$result = dol_copy($src, $dest, '0', 0);
					if ($result < 0) {
						$langs->load("errors");
						$this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
						return 0;
					}
				}

				$sql = array_merge($sql,
					array(
						"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'standard_".strtolower($myTmpObjectKey)."' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
						"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('standard_".strtolower($myTmpObjectKey)."', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")",
						"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'generic_".strtolower($myTmpObjectKey)."_odt' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
						"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('generic_".strtolower($myTmpObjectKey)."_odt', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")"
					));
			}
		}

		if ($this->enableTalerPaymentMethod() < 0) {
			return -1;
		}

		if ($this->ensureTalerClearingAccount() < 0) {
			return -1;
		}

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		if ($this->disableTalerPaymentMethod() < 0) {
			return -1;
		}

		if ($this->disableTalerClearingAccount() < 0) {
			return -1;
		}

		return $this->_remove($sql, $options);
	}

	/**
	 * Ensure the Taler Digital Cash payment method exists and is active.
	 *
	 * @return int 1 if OK, <0 if error
	 */
	private function enableTalerPaymentMethod()
	{
		global $conf;

		$code = 'TLR';
		$label = 'Taler';
		$module = 'talerbarr';
		$type = 1;
		$targetEntity = (int) (!empty($conf->entity) ? $conf->entity : 1);

		$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."c_paiement SET active = 1, libelle = '".$this->db->escape($label)."', type = ".$type.", module = '".$this->db->escape($module)."' WHERE code = '".$this->db->escape($code)."'";
		if (!$this->db->query($sqlUpdate)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$sqlSelect = "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = '".$this->db->escape($code)."' AND entity = ".$targetEntity;
		$resSelect = $this->db->query($sqlSelect);
		if (!$resSelect) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if ($this->db->num_rows($resSelect) > 0) {
			$this->db->free($resSelect);
			return 1;
		}

		$this->db->free($resSelect);

		$sqlInsert = "INSERT INTO ".MAIN_DB_PREFIX."c_paiement (id, entity, code, libelle, type, active, accountancy_code, module, position) VALUES (271, ".$targetEntity.", '".$this->db->escape($code)."', '".$this->db->escape($label)."', ".$type.", 1, NULL, '".$this->db->escape($module)."', 0)";
		$resInsert = $this->db->query($sqlInsert);
		if (!$resInsert) {
			$errorMsg = $this->db->lasterror();
			if (stripos($errorMsg, 'duplicate') === false) {
				$this->error = $errorMsg;
				return -1;
			}

			$sqlInsertFallback = "INSERT INTO ".MAIN_DB_PREFIX."c_paiement (entity, code, libelle, type, active, accountancy_code, module, position) VALUES (".$targetEntity.", '".$this->db->escape($code)."', '".$this->db->escape($label)."', ".$type.", 1, NULL, '".$this->db->escape($module)."', 0)";
			if (!$this->db->query($sqlInsertFallback)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Disable the Taler Digital Cash payment method if present.
	 *
	 * @return int 1 if OK, <0 if error
	 */
	private function disableTalerPaymentMethod()
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."c_paiement SET active = 0 WHERE code = '".$this->db->escape('TLR')."'";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Ensure the GNU Taler clearing bank account exists and stays open.
	 *
	 * @return int 1 if OK, <0 if error
	 */
	private function ensureTalerClearingAccount()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

		$entity = (int) $conf->entity;
		$ref = 'TLRCLEAR';
		$label = 'GNU Taler Clearing';
		$constName = 'TALERBARR_CLEARING_ACCOUNT_ID';

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".$entity." AND ref = '".$this->db->escape($ref)."'";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$rowid = 0;
		if ($obj = $this->db->fetch_object($res)) {
			$rowid = (int) $obj->rowid;
		}
		$this->db->free($res);

		if ($rowid > 0) {
			$update = "UPDATE ".MAIN_DB_PREFIX."bank_account SET label = '".$this->db->escape($label)."', clos = 0, courant = ".Account::TYPE_CURRENT." WHERE rowid = ".$rowid;
			if (!$this->db->query($update)) {
				$this->error = $this->db->lasterror();
				return -1;
			}

			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			dolibarr_set_const($this->db, $constName, $rowid, 'chaine', 0, '', $entity);

			return 1;
		}

		$fkPays = (int) getDolGlobalInt('MAIN_INFO_COUNTRY');
		$currency = getDolGlobalString('MAIN_INFO_CURRENCY', '');
		if (empty($currency) && !empty($conf->currency)) {
			$currency = $conf->currency;
		}

		$probe = $this->db->query("SELECT fk_pays, currency_code FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".$entity." ORDER BY rowid ASC LIMIT 1");
		if ($probe) {
			$probeObj = $this->db->fetch_object($probe);
			if ($probeObj) {
				if (empty($fkPays) && (int) $probeObj->fk_pays > 0) {
					$fkPays = (int) $probeObj->fk_pays;
				}
				if (empty($currency) && !empty($probeObj->currency_code)) {
					$currency = $probeObj->currency_code;
				}
			}
			$this->db->free($probe);
		}

		if (empty($fkPays)) {
			$resCountry = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_pays WHERE active = 1 ORDER BY rowid ASC LIMIT 1");
			if ($resCountry) {
				$countryObj = $this->db->fetch_object($resCountry);
				if ($countryObj) {
					$fkPays = (int) $countryObj->rowid;
				}
				$this->db->free($resCountry);
			}
		}
		if (empty($fkPays)) {
			$fkPays = 1;
		}
		if (empty($currency)) {
			$currency = 'EUR';
		}

		$now = $this->db->idate(dol_now());
		$insert = "INSERT INTO ".MAIN_DB_PREFIX."bank_account (datec, ref, label, entity, fk_pays, currency_code, rappro, clos, courant, bank, comment) VALUES ('".$now."', '".$this->db->escape($ref)."', '".$this->db->escape($label)."', ".$entity.", ".$fkPays.", '".$this->db->escape($currency)."', 1, 0, ".Account::TYPE_CURRENT.", 'GNU Taler', 'Auto-created by TalerBarr module')";
		if (!$this->db->query($insert)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$rowid = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."bank_account");

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, $constName, $rowid, 'chaine', 0, '', $entity);

		return 1;
	}

	/**
	 * Mark the GNU Taler clearing account as closed and drop the tracking constant.
	 *
	 * @return int 1 if OK, <0 if error
	 */
	private function disableTalerClearingAccount()
	{
		global $conf;

		$entity = (int) $conf->entity;
		$constName = 'TALERBARR_CLEARING_ACCOUNT_ID';
		$accountId = (int) getDolGlobalInt($constName);

		if ($accountId <= 0) {
			$res = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".$entity." AND ref = '".$this->db->escape('TLRCLEAR')."'");
			if ($res) {
				$obj = $this->db->fetch_object($res);
				if ($obj) {
					$accountId = (int) $obj->rowid;
				}
				$this->db->free($res);
			}
		}

		if ($accountId > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."bank_account SET clos = 1 WHERE rowid = ".$accountId;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_del_const($this->db, $constName, $entity);

		return 1;
	}
}
