<?php
/**
 * Page de configuration du module SyncOdoo
 */

$res = @include_once '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include_once '../../../main.inc.php';
}
if (!$res) {
    die('main.inc.php introuvable');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

if (empty($conf->syncodoo->enabled)) {
    accessforbidden(syncodooText('Module SyncOdoo desactive', 'SyncOdoo-module uitgeschakeld'));
}
if (!$user->rights->syncodoo->config) {
    accessforbidden();
}

$langs->load('syncodoo@syncodoo');
$action = GETPOST('action', 'alpha');

$_set_lang = GETPOST('set_lang', 'alpha');
if ($_set_lang === 'fr' || $_set_lang === 'nl') {
    $_SESSION['syncodoo_lang'] = $_set_lang;
}

function syncodooText(string $fr, string $nl): string
{
    global $langs;
    $forced = $_SESSION['syncodoo_lang'] ?? '';
    if ($forced === 'nl') return $nl;
    if ($forced === 'fr') return $fr;
    $code = strtolower((string) ($langs->defaultlang ?? ''));
    return (strpos($code, 'nl') === 0) ? $nl : $fr;
}

// Auto-migrate legacy demo URL to HTTPS recommendation.
$legacyUrls = ['http://odoo.mondomaine.com', 'http://odoo.mondomaine.com/'];
if (!empty($conf->global->SYNCODOO_ODOO_URL) && in_array(trim((string) $conf->global->SYNCODOO_ODOO_URL), $legacyUrls, true)) {
    dolibarr_set_const($db, 'SYNCODOO_ODOO_URL', 'https://mondomaine.odoo.com', 'chaine', 1, '', $conf->entity);
    $conf->global->SYNCODOO_ODOO_URL = 'https://mondomaine.odoo.com';
}

// ── Sauvegarde ───────────────────────────────────────────
if ($action === 'save') {
    $fields = [
        'SYNCODOO_ODOO_URL'    => trim(GETPOST('SYNCODOO_ODOO_URL',    'restricthtml')),
        'SYNCODOO_ODOO_DB'     => trim(GETPOST('SYNCODOO_ODOO_DB',     'restricthtml')),
        'SYNCODOO_ODOO_USER'   => trim(GETPOST('SYNCODOO_ODOO_USER',   'restricthtml')),
        'SYNCODOO_ODOO_PASSWORD'   => GETPOST('SYNCODOO_ODOO_PASSWORD',   'restricthtml'),
        'SYNCODOO_DOLI_APIKEY' => GETPOST('SYNCODOO_DOLI_APIKEY', 'restricthtml'),
        'SYNCODOO_LIMIT'       => (int)GETPOST('SYNCODOO_LIMIT',  'int'),
        'SYNCODOO_LOG_LEVEL'   => GETPOST('SYNCODOO_LOG_LEVEL',   'alpha'),
        'SYNCODOO_IMPORT_INVOICE_FILE' => (int) GETPOST('SYNCODOO_IMPORT_INVOICE_FILE', 'int'),
    ];

    foreach ($fields as $const => $val) {
        if ($val !== '') {
            dolibarr_set_const($db, $const, $val, 'chaine', 1, '', $conf->entity);

            // Keep old key in sync for backward compatibility with existing deployments.
            if ($const === 'SYNCODOO_ODOO_PASSWORD') {
                dolibarr_set_const($db, 'SYNCODOO_ODOO_PASS', $val, 'chaine', 1, '', $conf->entity);
            }
        }
    }
    setEventMessages(syncodooText('Configuration enregistrée.', 'Instellingen opgeslagen.'), null, 'mesgs');
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ── Test de connexion Odoo ────────────────────────────
if ($action === 'test_odoo') {
    require_once __DIR__.'/../core/classes/SyncOdoo.class.php';
    $sync = new SyncOdoo($db);
    $testResult = $sync->testOdooConnectionDetailed();
    
    if ($testResult['success']) {
        setEventMessages(syncodooText('✓ Connexion Odoo réussie !', '✓ Odoo-verbinding gelukt!'), null, 'mesgs');
    } else {
        setEventMessages(syncodooText('✗ Échec : ', '✗ Mislukt: ').$testResult['error'], null, 'errors');
    }
    
    // Stocker les détails en session pour les afficher
    $_SESSION['syncodoo_test_result'] = $testResult;
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ── Cron : création de la tâche ─────────────────────────
if ($action === 'create_cron') {
    require_once DOL_DOCUMENT_ROOT.'/core/class/cronjob.class.php';
    $cron = new Cronjob($db);
    $cron->jobtype      = 'function';
    $cron->module_name  = 'syncodoo';
    $cron->classesname  = 'SyncOdoo';
    $cron->methodename  = 'runAll';
    $cron->label        = 'Sync Dolibarr ↔ Odoo';
    $cron->datestart    = dol_now();
    $cron->frequency    = 1;
    $cron->unitfrequency= 3600; // toutes les heures
    $cron->status       = 1;
    $cron->entity       = $conf->entity;
    $cron->create($user);
    setEventMessages(syncodooText('Tâche cron créée (1x/heure).', 'Cron-taak aangemaakt (1×/uur).'), null, 'mesgs');
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ════════════════════════════════════════════════════════
llxHeader('', syncodooText('SyncOdoo — Configuration', 'SyncOdoo — Instellingen'));
global $user;
print '<style>';
print '.butAction, .butActionDelete {';
print 'background:#1f8f43 !important;border-color:#1f8f43 !important;color:#fff !important;';
print '}';
print '.butAction:hover, .butActionDelete:hover {';
print 'background:#177336 !important;border-color:#177336 !important;color:#fff !important;';
print '}';
print '</style>';
print load_fiche_titre(syncodooText('Configuration SyncOdoo', 'SyncOdoo-instellingen'), '', 'setup');

$_syncoActiveLang = $_SESSION['syncodoo_lang'] ?? (strpos(strtolower((string) ($langs->defaultlang ?? '')), 'nl') === 0 ? 'nl' : 'fr');
$_syncoBase = dol_buildpath('/syncodoo/admin/config.php', 1);
print '<div style="text-align:right;margin:4px 0 6px 0;font-size:0.88em">';
print '<span style="color:#6c757d;margin-right:4px">'.syncodooText('Langue :', 'Taal:').'</span>';
print '<a href="'.$_syncoBase.'?set_lang=fr" style="padding:2px 10px;text-decoration:none;border-radius:3px 0 0 3px;border:1px solid #ccc;'.($_syncoActiveLang === 'fr' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">FR</a>';
print '<a href="'.$_syncoBase.'?set_lang=nl" style="padding:2px 10px;text-decoration:none;border-radius:0 3px 3px 0;border:1px solid #ccc;border-left:none;'.($_syncoActiveLang === 'nl' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">NL</a>';
print '</div>';

// Onglets
$head = [];
$head[0][0] = dol_buildpath('/syncodoo/index.php', 1).'?tab=dashboard';
$head[0][1] = 'Dashboard';
$head[0][2] = 'dashboard';
$head[1][0] = dol_buildpath('/syncodoo/divergences.php', 1);
$head[1][1] = 'Divergences';
$head[1][2] = 'divergences';
$head[2][0] = dol_buildpath('/syncodoo/index.php', 1).'?tab=log';
$head[2][1] = 'Journal';
$head[2][2] = 'log';
$head[3][0] = dol_buildpath('/syncodoo/admin/config.php', 1);
$head[3][1] = 'Configuration';
$head[3][2] = 'config';
$head[4][0] = dol_buildpath('/syncodoo/index.php', 1).'?tab=about';
$head[4][1] = 'À propos';
$head[4][2] = 'about';
print dol_get_fiche_head($head, 'config', 'SyncOdoo', 0, 'refresh');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

// ── Section Odoo ─────────────────────────────────────────
print '<table class="noborder centpercent"><tbody>';

print '<tr class="liste_titre"><th colspan="2">'.syncodooText('Connexion Odoo', 'Odoo-verbinding').'</th></tr>';

$fields = [
    'SYNCODOO_ODOO_URL' => [syncodooText('URL de base Odoo', 'Odoo basis-URL'), 'text', 'https://mondomaine.odoo.com', false],
    'SYNCODOO_ODOO_DB' => [syncodooText('Base de données Odoo', 'Odoo databasenaam'), 'text', 'odoo_production', false],
    'SYNCODOO_ODOO_USER' => [syncodooText('Utilisateur Odoo', 'Odoo-gebruiker'), 'text', 'admin', false],
    'SYNCODOO_ODOO_PASSWORD' => [syncodooText('Mot de passe Odoo', 'Odoo-wachtwoord'), 'password', '', true],
];

foreach ($fields as $const => [$label, $type, $placeholder, $isSecret]) {
    $current = $conf->global->$const ?? '';
    if ($const === 'SYNCODOO_ODOO_PASSWORD' && empty($current)) {
        $current = $conf->global->SYNCODOO_ODOO_PASS ?? '';
    }
    $display = ($isSecret && $current) ? '' : htmlspecialchars($current);
    $inputId = 'syncodoo_'.strtolower($const);
    print '<tr><td class="fieldrequired">'.$label.'</td>';
    print '<td>';
    if ($isSecret) {
        print '<div style="display:flex;align-items:center;gap:8px">';
    }
    print '<input type="'.$type.'" id="'.$inputId.'" name="'.$const.'" class="minwidth300"'.($isSecret ? '' : ' data-syncodoo-memory="1"').' ';
    print 'value="'.($isSecret ? '' : $display).'" placeholder="'.$placeholder.'"';
    if ($isSecret) {
        print ' autocomplete="new-password"';
        if ($current) {
            print ' placeholder="'.syncodooText('(configure — laisser vide pour ne pas modifier)', '(ingesteld — leeg laten om niet te wijzigen').'"';
        }
    }
    print '>';
    if ($isSecret) {
        print '<button type="button" class="button" onclick="syncodooTogglePassword(\''.$inputId.'\', this)"';
        print ' style="min-width:42px" aria-label="'.syncodooText('Afficher le mot de passe', 'Wachtwoord tonen').'">🙈</button>';
        print '</div>';
    }
    print '</td></tr>';
}

    print '<tr class="liste_titre"><th colspan="2">'.syncodooText('Paramètres généraux', 'Algemene instellingen').'</th></tr>';

// Dolibarr API-sleutel - Auto-detection
print '<tr><td>'.syncodooText('Cle API Dolibarr', 'Dolibarr API-sleutel').'</td>';
print '<td><div style="padding:10px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;color:#155724">';
print '<strong>✓ '.syncodooText('Automatique', 'Automatisch').'</strong> — '.syncodooText('La cle API de l\'utilisateur connecte est utilisee automatiquement.', 'De API-sleutel van de aangemelde gebruiker wordt automatisch gebruikt.');
if (!empty($user->api_key)) {
    print '<br><small>'.syncodooText('Utilisateur', 'Gebruiker').': <strong>'.htmlspecialchars($user->login).'</strong></small>';
} else {
    print '<br><small style="color:#d9534f">⚠ '.syncodooText('Attention: utilisateur ', 'Let op: gebruiker ').htmlspecialchars($user->login).syncodooText(' sans cle API. Generez-la dans Accueil -> Securite -> Cle API REST.', ' heeft geen API-sleutel. Genereer die in Home -> Beveiliging -> REST API-sleutel.').'</small>';
}
print '</div>';
$fallbackKey = $conf->global->SYNCODOO_DOLI_APIKEY ?? '';
if (!empty($fallbackKey)) {
    print '<br><small class="opacitymedium">'.syncodooText('Cle de secours en configuration (utilisee seulement si l\'utilisateur n\'a pas de cle).', 'Reservesleutel in configuratie (alleen gebruikt als de gebruiker geen sleutel heeft).').'</small>';
    print '<div style="display:flex;align-items:center;gap:8px;margin-top:6px">';
    print '<input type="password" id="syncodoo_doli_apikey" name="SYNCODOO_DOLI_APIKEY" class="minwidth300" placeholder="'.syncodooText('(optionnel - fallback uniquement)', '(optioneel - alleen fallback)').'" autocomplete="new-password">';
    print '<button type="button" class="button" onclick="syncodooTogglePassword(\'syncodoo_doli_apikey\', this)" style="min-width:42px" aria-label="'.syncodooText('Afficher la cle', 'Sleutel tonen').'">🙈</button>';
    print '</div>';
} else {
    print '<input type="hidden" name="SYNCODOO_DOLI_APIKEY" value="">';
}
print '</td></tr>';

// Limite
print '<tr><td>'.syncodooText('Limite de lignes par appel', 'Limiet records per oproep').'</td>';
print '<td><input type="number" name="SYNCODOO_LIMIT" min="10" max="5000" data-syncodoo-memory="1" 
       value="'.((int)($conf->global->SYNCODOO_LIMIT ?? 500)).'"></td></tr>';

// Logniveau
$logLevel = $conf->global->SYNCODOO_LOG_LEVEL ?? 'INFO';
print '<tr><td>'.syncodooText('Niveau de log', 'Logniveau').'</td><td><select name="SYNCODOO_LOG_LEVEL">';
foreach (['DEBUG', 'INFO', 'WARNING', 'ERROR'] as $lv) {
    $sel = ($lv === $logLevel) ? ' selected' : '';
    print '<option value="'.$lv.'"'.$sel.'>'.$lv.'</option>';
}
print '</select></td></tr>';

$importInvoiceFile = (int) ($conf->global->SYNCODOO_IMPORT_INVOICE_FILE ?? 0);
print '<tr><td>'.syncodooText('Importer le fichier de facture Odoo', 'Odoo-factuurbestand importeren').'</td>';
print '<td><label><input type="checkbox" name="SYNCODOO_IMPORT_INVOICE_FILE" value="1"'.($importInvoiceFile ? ' checked' : '').'> ';
print syncodooText('Joindre automatiquement le fichier de facture (PDF en priorite) lors de la creation dans Dolibarr.', 'Factuurbijlage automatisch toevoegen (PDF heeft voorrang) bij creatie in Dolibarr.');
print '</label></td></tr>';

print '</tbody></table>';
print '<br><button type="submit" class="butAction">'.syncodooText('Enregistrer', 'Opslaan').'</button>';
print '</form>';

// ── Test de connexion Odoo ──────────────────────────────────
print '<br><hr><h3>'.syncodooText('Test de connexion', 'Verbindingscontrole').'</h3>';
print '<p>'.syncodooText('Testez vos accès Odoo sans lancer une synchronisation complète.', 'Test uw Odoo-gegevens zonder een volledige synchronisatie te starten.').'</p>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test_odoo">';
print '<button type="submit" class="butAction" onclick="return confirm(\''.syncodooText('Tester la connexion Odoo ?', 'Odoo-verbinding testen?').'\');">'.syncodooText('🔗 Tester la connexion Odoo', '🔗 Odoo-verbinding testen').'</button>';
print '</form>';

if (!empty($_SESSION['syncodoo_test_result'])) {
    $testRes = $_SESSION['syncodoo_test_result'];
    print '<div style="margin-top:16px;border:1px solid #ccc;border-radius:6px;overflow:hidden">';
    print '<div style="background:#f8f9fa;padding:10px 16px;border-bottom:1px solid #ccc">';
    print '<strong>'.syncodooText('Résultats du test :', 'Testresultaten:').'</strong>';
    print '</div>';
    print '<div style="padding:10px 16px">';
    print '<table class="noborder" style="width:100%">';
    foreach ($testRes['steps'] as $step) {
        $icon = $step['status'] === 'ok' ? '✓' : '✗';
        $color = $step['status'] === 'ok' ? '#28a745' : '#dc3545';
        print '<tr>';
        print '<td style="padding:6px;color:'.$color.';font-weight:bold;min-width:80px">'.$icon.' '.htmlspecialchars($step['step']).'</td>';
        print '<td style="padding:6px">'.htmlspecialchars($step['msg']).'</td>';
        print '</tr>';
    }
    print '</table>';
    if (!$testRes['success']) {
        print '<div style="margin-top:10px;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;color:#856404">';
        print '<strong>⚠ '.syncodooText('Diagnostic:', 'Diagnose:').'</strong> '.htmlspecialchars($testRes['error']);
        print '</div>';
    }
    print '</div>';
    print '</div>';
    unset($_SESSION['syncodoo_test_result']);
}
print '<br>';

// ── Section Cron ─────────────────────────────────────────
print '<hr><h3>'.syncodooText('Automatisation (tâche cron Dolibarr)', 'Automatisering (Dolibarr-crontaak)').'</h3>';
print '<p>'.syncodooText('Crée une tâche dans Dolibarr pour exécuter la synchronisation chaque heure.', 'Maakt een taak in Dolibarr om de synchronisatie elk uur uit te voeren.').'</p>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="create_cron">';
print '<button type="submit" class="butActionDelete" 
         onclick="return confirm(\''.syncodooText('Créer la tâche cron automatique ?', 'Automatische crontaak aanmaken?').'\')">
     '.syncodooText('⏱ Créer la tâche cron (1x/heure)', '⏱ Crontaak aanmaken (1×/uur)').'
</button>';
print '</form>';
print '<p class="opacitymedium">'.syncodooText('Vous pouvez aussi ajouter cette ligne à votre crontab système :', 'U kunt ook deze regel toevoegen aan uw systeem-crontab:').'<br>';
print '<code>0 * * * * php '.DOL_DOCUMENT_ROOT.'/syncodoo/cron/sync.php >> /var/log/sync-doli-odoo.log 2>&1</code></p>';

print '<script>';
print 'function syncodooTogglePassword(inputId, buttonEl) {';
print '  var input = document.getElementById(inputId);';
print '  if (!input) return;';
print '  var isHidden = input.type === "password";';
print '  input.type = isHidden ? "text" : "password";';
print '  buttonEl.textContent = isHidden ? "👁" : "🙈";';
print '  buttonEl.setAttribute("aria-label", isHidden ? "'.syncodooText('Masquer le mot de passe', 'Wachtwoord verbergen').'" : "'.syncodooText('Afficher le mot de passe', 'Wachtwoord tonen').'");';
print '}';
print 'document.addEventListener("DOMContentLoaded", function () {';
print '  var keyPrefix = "syncodoo_cfg_";';
print '  var fields = document.querySelectorAll("[data-syncodoo-memory=\\"1\\"]");';
print '  fields.forEach(function (el) {';
print '    var name = el.getAttribute("name");';
print '    if (!name) return;';
print '    var storageKey = keyPrefix + name;';
print '    var saved = localStorage.getItem(storageKey);';
print '    if (saved !== null && (el.value === "" || el.value === null)) {';
print '      el.value = saved;';
print '    }';
print '    var eventName = (el.tagName === "SELECT") ? "change" : "input";';
print '    el.addEventListener(eventName, function () {';
print '      localStorage.setItem(storageKey, el.value || "");';
print '    });';
print '  });';
print '  var saveForm = document.querySelector("form input[name=\\"action\\"][value=\\"save\\"]");';
print '  if (saveForm && saveForm.form) {';
print '    saveForm.form.addEventListener("submit", function () {';
print '      fields.forEach(function (el) {';
print '        var name = el.getAttribute("name");';
print '        if (!name) return;';
print '        localStorage.setItem(keyPrefix + name, el.value || "");';
print '      });';
print '    });';
print '  }';
print '});';
print '</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
