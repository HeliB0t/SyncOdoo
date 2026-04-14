<?php
/* Copyright (C) 2024  HeliB0t
 * Module : modsyncodoo — Synchronisation Dolibarr ↔ Odoo
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module SyncOdoo
 */
class modSyncodoo extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 500002; // TODO Go on page https://wiki.dolibarr.org/index.php/List_of_modules_id to reserve an id number for your module

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'syncodoo';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "other";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleSyncodooName' not found (Syncodoo is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleSyncodooDesc' not found (Syncodoo is name of module).
		$this->description = "Bidirectionele synchronisatie tussen Dolibarr en Odoo (relaties + facturen)";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "Synchroniseert relaties en facturen tussen Dolibarr en Odoo, met detectie van divergenties en update-acties.";

		// Author
		$this->editor_name = 'HeliB0t';
		$this->editor_url = 'https://paypal.me/HeliB0t';

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '0.3.0';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where SYNCOO is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'syncodoo@syncodoo';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 0,
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
				//    '/syncodoo/css/syncodoo.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/syncodoo/js/syncodoo.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			'hooks' => array(
				//   'data' => array(
				//       'hookcontext1',
				//       'hookcontext2',
				//   ),
				//   'entity' => '0',
			),
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/syncodoo/temp","/syncodoo/subdir");
		$this->dirs = array("/syncodoo/temp");

		// Config pages. Put here list of php page, stored into syncodoo/admin directory, to use to setup module.
		$this->config_page_url = array("config.php@syncodoo");

		// Dependencies
		// A condition to hide module
		$this->hidden = false;
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array();
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("syncodoo@syncodoo");

		// Prerequisites
		$this->phpmin = array(7, 0); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(11, -3); // Minimum version of Dolibarr required by module

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, description, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('MYMODULE_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1, 'current', 1)
		$this->const = array(
			// 0 => array('SYNCODOO_VERSION', 'chaine', '1.0', '', 1, 'current', 0),
			0 => array('SYNCODOO_ODOO_URL', 'chaine', 'https://mondomaine.odoo.com', 'URL base de l\'instance Odoo', 1, 'current', 1),
			1 => array('SYNCODOO_ODOO_DB', 'chaine', 'odoo_db', 'Nom de la base de données Odoo', 1, 'current', 1),
			2 => array('SYNCODOO_ODOO_USER', 'chaine', 'admin', 'Utilisateur Odoo pour la connexion XML-RPC', 1, 'current', 1),
			3 => array('SYNCODOO_ODOO_PASSWORD', 'chaine', '', 'Mot de passe Odoo (stocké chiffré)', 0, 'current', 1),
			4 => array('SYNCODOO_DOLI_APIKEY', 'chaine', '', 'Clé API Dolibarr (générée dans Accueil > Sécurité)', 0, 'current', 1),
			5 => array('SYNCODOO_LIMIT', 'chaine', '500', 'Nombre maximum d\'enregistrements récupérés par appel API', 1, 'current', 1),
			6 => array('SYNCODOO_LOG_LEVEL', 'chaine', 'INFO', 'Niveau de log : DEBUG, INFO, WARNING, ERROR', 1, 'current', 1),
			7 => array('SYNCODOO_BANK_SYNC_ENABLED', 'chaine', '0', 'Activer la synchronisation des transactions bancaires', 1, 'current', 1),
			8 => array('SYNCODOO_ODOO_BANK_JOURNAL', 'chaine', '', 'Code, nom ou identifiant numérique du journal bancaire Odoo', 1, 'current', 1),
			9 => array('SYNCODOO_DOLI_BANK_ACCOUNT_ID', 'chaine', '', 'Identifiant du compte bancaire Dolibarr cible', 1, 'current', 1),
			10 => array('SYNCODOO_BANK_SYNC_DIRECTION', 'chaine', 'both', 'Sens de synchronisation bancaire: odoo_to_dolibarr, dolibarr_to_odoo, both', 1, 'current', 1),
			11 => array('SYNCODOO_BANK_SYNC_START_DATE', 'chaine', '', 'Date minimale AAAA-MM-JJ pour la synchronisation bancaire', 1, 'current', 1),
		);

		// Array to add new pages in new tabs
		$this->tabs = array();
		// Example:
		// $this->tabs[] = array('data'=>'objecttype:+tabname1:Title1:mylangfile@mymodule:$user->rights->mymodule->read:/mymodule/mynewtab1.php?id=__ID__');  					// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data'=>'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@mymodule:$user->rights->othermodule->read:/mymodule/mynewtab2.php?id=__ID__');   	// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data'=>'objecttype:-tabname:NU:conditiontoremove');                                                     										// To remove an existing tab identified by code tabname
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'invoice_supplier' to add a tab in supplier invoice view
		// 'member'           to add a tab in fundation member view
		// 'opensurveypoll'   to add a tab in opensurvey poll view
		// 'order'            to add a tab in customer order view
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
		$this->dictionaries = array();

		// Boxes/Widgets
		// Add here list of php file(s) stored in core/boxes that contains a class to show a widget.
		$this->boxes = array(
			//  0 => array('file'=>'mysyncodoowidget1.php','note'=>'Widget provided by SyncOdoo','enabledbydefaulton'=>'Home'),
			//  1 => array('file'=>'mysyncodoowidget2.php','note'=>'Widget provided by SyncOdoo','enabledbydefaulton'=>'Home'),
		);

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		$this->cronjobs = array(
			//  0 => array('label'=>'MyJob label', 'jobtype'=>'method', 'class'=>'/syncodoo/class/myjob.class.php', 'objectname'=>'MyJob', 'method'=>'doScheduledJob', 'parameters'=>'', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'$conf->syncodoo->enabled', 'priority'=>50),
		);

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		// Add here entries to declare new permissions
		// Example:
		//// Permission id (must not be already used)
		//$this->rights[$r][0] = $this->numero . $r;
		//// In php code, permission will be checked by test if ($user->rights->mymodule->level1)
		//$this->rights[$r][1] = 'MyModule access level 1';
		//// Permission label
		//$this->rights[$r][1] = 'Read objects of MyModule';
		//// In php code, permission will be checked by test if ($user->rights->mymodule->read)
		//$this->rights[$r][2] = 'r';
		//// Used in the interface, permission will be check by test if ($user->rights->mymodule->level1->read)
		//$this->rights[$r][3] = 1;
		//// Permission by default for new user (0/1)
		//$this->rights[$r][4] = 1;
		//// In php code, permission will be checked by test if ($user->rights->mymodule->level1->read)
		//$this->rights[$r][5] = '';

		$this->rights[$r][0] = $this->numero;
		$this->rights[$r][1] = 'Synchronisatielogboek raadplegen';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'lire';
		$r++;

		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = 'Handmatige synchronisatie starten';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'lancer';
		$r++;

		$this->rights[$r][0] = $this->numero + 2;
		$this->rights[$r][1] = 'Module-instellingen wijzigen';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'config';
		$r++;

		// Main menu entries to add
		$this->menu = array();
		$r = 0;

		// Add here entries to declare new menus
		// Example:
		//// Menu entry 1
		//$menu = new stdClass();
		//$menu->numero = 1;
		//$menu->module = 'MyModule';
		//$menu->position = '10';
		//$menu->url = '/mymodule/index.php';
		//$menu->target = '';
		//$menu->mainmenu = '';
		//$menu->leftmenu = '';
		//$menu->perms = '$user->rights->mymodule->read';
		//$menu->enabled = '$conf->mymodule->enabled';
		//$menu->usertype = 2;
		//$menu->title = 'MyModule';
		//$menu->prefix = '';
		//$this->menu[$r++] = $menu;

		// Entrée principale dans la barre du haut
		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'SyncOdoo',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth"'),
			'mainmenu' => 'syncodoo',
			'leftmenu' => '',
			'url' => '/syncodoo/index.php',
			'langs' => 'syncodoo@syncodoo',
			'position' => 15,
			'enabled' => '$conf->syncodoo->enabled',
			'perms' => '$user->rights->syncodoo->lire',
			'target' => '',
			'user' => 0,
		);

		// Sous-menu : Divergences
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=syncodoo',
			'type' => 'left',
			'titre' => 'Divergences',
			'mainmenu' => 'syncodoo',
			'leftmenu' => 'syncodoo_divergences',
			'url' => '/custom/syncodoo/divergences.php',
			'langs' => 'syncodoo@syncodoo',
			'position' => 115,
			'enabled' => '$conf->syncodoo->enabled',
			'perms' => '$user->rights->syncodoo->lire',
			'target' => '',
			'user' => 0,
		);

		// Sous-menu : Journal
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=syncodoo',
			'type' => 'left',
			'titre' => 'Journal',
			'mainmenu' => 'syncodoo',
			'leftmenu' => 'syncodoo_log',
			'url' => '/custom/syncodoo/index.php?tab=log',
			'langs' => 'syncodoo@syncodoo',
			'position' => 117,
			'enabled' => '$conf->syncodoo->enabled',
			'perms' => '$user->rights->syncodoo->lire',
			'target' => '',
			'user' => 0,
		);

		// Sous-menu : Configuration
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=syncodoo',
			'type' => 'left',
			'titre' => 'Configuration',
			'mainmenu' => 'syncodoo',
			'leftmenu' => 'syncodoo_config',
			'url' => '/custom/syncodoo/admin/config.php',
			'langs' => 'syncodoo@syncodoo',
			'position' => 119,
			'enabled' => '$conf->syncodoo->enabled',
			'perms' => '$user->rights->syncodoo->config',
			'target' => '',
			'user' => 0,
		);

		// Sous-menu : À propos
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=syncodoo',
			'type' => 'left',
			'titre' => 'À propos',
			'prefix' => '<img src="'.dol_buildpath('/syncodoo/img/object_syncodoo1.png', 1).'" alt="" class="paddingright pictofixedwidth" style="height:16px;width:16px;object-fit:contain">',
			'mainmenu' => 'syncodoo',
			'leftmenu' => 'syncodoo_about',
			'url' => '/custom/syncodoo/index.php?tab=about',
			'langs' => 'syncodoo@syncodoo',
			'position' => 120,
			'enabled' => '$conf->syncodoo->enabled',
			'perms' => '$user->rights->syncodoo->lire',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 * The init function adds sources, constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$sql = array();

		// Cleanup legacy menu entries that could keep SyncOdoo under Tools.
		$sql[] = "DELETE FROM ".MAIN_DB_PREFIX."menu
			WHERE module = 'syncodoo' AND (
				mainmenu IN ('tools', 'tool')
				OR (type = 'top' AND mainmenu <> 'syncodoo')
			)";

		// Table de log
		$sql[] = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_log (
			rowid INT NOT NULL AUTO_INCREMENT,
			datec DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL DEFAULT 'INFO',
			direction VARCHAR(30) NOT NULL DEFAULT '',
			entity_type VARCHAR(30) NOT NULL DEFAULT '',
			entity_ref VARCHAR(100) NOT NULL DEFAULT '',
			message TEXT,
			PRIMARY KEY (rowid),
			INDEX idx_syncodoo_log_datec (datec),
			INDEX idx_syncodoo_log_level (level)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_bank_map (
			rowid INT NOT NULL AUTO_INCREMENT,
			datec DATETIME NOT NULL,
			tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			odoo_transaction_id INT NULL DEFAULT NULL,
			dolibarr_bank_line_id INT NULL DEFAULT NULL,
			dolibarr_bank_account_id INT NULL DEFAULT NULL,
			odoo_journal_id INT NULL DEFAULT NULL,
			sync_direction VARCHAR(20) NOT NULL DEFAULT '',
			odoo_write_date DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (rowid),
			UNIQUE KEY uk_syncodoo_bank_map_odoo (odoo_transaction_id),
			UNIQUE KEY uk_syncodoo_bank_map_doli (dolibarr_bank_line_id),
			INDEX idx_syncodoo_bank_map_account (dolibarr_bank_account_id),
			INDEX idx_syncodoo_bank_map_journal (odoo_journal_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants, boxes and permissions from Dolibarr database.
	 * Data directories are not deleted
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		$sql[] = "DROP TABLE IF EXISTS ".MAIN_DB_PREFIX."syncodoo_log";
		$sql[] = "DROP TABLE IF EXISTS ".MAIN_DB_PREFIX."syncodoo_bank_map";

		return $this->_remove($sql, $options);
	}

}
