<?php
/**
 * Page principale du module SyncOdoo
 * Dashboard + déclenchement manuel de la synchronisation
 */

$res  = @include_once '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include_once '../../main.inc.php';
}
if (!$res) {
    die('main.inc.php introuvable');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/core/classes/SyncOdoo.class.php';

// ── Vérification droits ──────────────────────────────────
if (empty($conf->syncodoo->enabled)) {
    accessforbidden('Module SyncOdoo désactivé');
}
if (!$user->rights->syncodoo->lire) {
    accessforbidden();
}

$langs->load('syncodoo@syncodoo');
$tab    = GETPOST('tab', 'alpha') ?: 'dashboard';
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

$moduleName = 'SyncOdoo';
$moduleVersion = '0.2.0';
$paypalUrl = 'https://www.paypal.com/donate/?business=RBGCKNQF62C3E&no_recurring=0&currency_code=EUR';

// ── Actions ──────────────────────────────────────────────
$result  = null;
$stats   = null;
$elapsed = 0;

if ($action === 'sync' && $user->rights->syncodoo->lancer) {
    $sync  = new SyncOdoo($db);
    $start = microtime(true);
    $ok    = $sync->runAll();
    $elapsed = round(microtime(true) - $start, 2);
    $stats   = $sync->stats;
    $result  = $ok ? 'success' : 'error';
    unset($_SESSION['syncodoo_divergences_reset']);
    // Purge automatique des vieux logs
    $sync->purgeLogs(30);
}

if ($action === 'clear_log' && $tab === 'log' && $user->rights->syncodoo->lancer) {
    $sync = new SyncOdoo($db);
    $sync->clearLogs();
    setEventMessages(syncodooText('Journal SyncOdoo vide.', 'SyncOdoo-logboek geleegd.'), null, 'mesgs');
}

// ── Récupération du journal ──────────────────────────────
$logs = [];
if ($tab === 'log') {
    $sync = new SyncOdoo($db);
    $logs = $sync->getLogs(200);
}

// ════════════════════════════════════════════════════════
// AFFICHAGE
// ════════════════════════════════════════════════════════
llxHeader('', syncodooText('SyncOdoo — Synchronisation Dolibarr ↔ Odoo', 'SyncOdoo — Synchronisatie Dolibarr ↔ Odoo'));

print '<style>';
print '.butAction, .butActionDelete {';
print 'background:#1f8f43 !important;border-color:#1f8f43 !important;color:#fff !important;';
print '}';
print '.butAction:hover, .butActionDelete:hover {';
print 'background:#177336 !important;border-color:#177336 !important;color:#fff !important;';
print '}';
print '.syncodoo-about-box {';
print 'border:1px solid #e1e7ef;border-radius:8px;padding:16px;background:#fff;max-width:980px;';
print '}';
print '</style>';

print load_fiche_titre(syncodooText('SyncOdoo — Synchronisation Dolibarr ↔ Odoo', 'SyncOdoo — Synchronisatie Dolibarr ↔ Odoo'), '', 'refresh');

// ── Onglets ──────────────────────────────────────────────
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
if ($user->rights->syncodoo->config) {
    $head[3][0] = dol_buildpath('/syncodoo/admin/config.php', 1);
    $head[3][1] = 'Configuration';
    $head[3][2] = 'config';
    $head[4][0] = dol_buildpath('/syncodoo/index.php', 1).'?tab=about';
    $head[4][1] = 'À propos';
    $head[4][2] = 'about';
} else {
    $head[3][0] = dol_buildpath('/syncodoo/index.php', 1).'?tab=about';
    $head[3][1] = 'À propos';
    $head[3][2] = 'about';
}
print dol_get_fiche_head($head, $tab, 'SyncOdoo', 0, 'refresh');

// ════════════════════════════════════════════════════════
// TAB : TABLEAU DE BORD
// ════════════════════════════════════════════════════════
if ($tab === 'dashboard') {
    $_syncoActiveLang = $_SESSION['syncodoo_lang'] ?? (strpos(strtolower((string) ($langs->defaultlang ?? '')), 'nl') === 0 ? 'nl' : 'fr');
    $_syncoBase = dol_buildpath('/syncodoo/index.php', 1).'?tab=dashboard';
    print '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:6px 0 14px 0">';

    print '<form method="POST" action="'.dol_buildpath('/syncodoo/index.php', 1).'" style="margin:0">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="sync">';
    print '<input type="hidden" name="tab" value="dashboard">';

    $disabled = !$user->rights->syncodoo->lancer;
    print '<button type="submit" class="butAction"'.($disabled ? ' disabled' : '').'>'.syncodooText('▶ Lancer la synchronisation', '▶ Synchronisatie nu starten').'</button>';
    print '</form>';

    print '<div style="font-size:0.88em">';
    print '<span style="color:#6c757d;margin-right:4px">'.syncodooText('Langue :', 'Taal:').'</span>';
    print '<a href="'.$_syncoBase.'&set_lang=fr" style="padding:2px 10px;text-decoration:none;border-radius:3px 0 0 3px;border:1px solid #ccc;'.($_syncoActiveLang === 'fr' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">FR</a>';
    print '<a href="'.$_syncoBase.'&set_lang=nl" style="padding:2px 10px;text-decoration:none;border-radius:0 3px 3px 0;border:1px solid #ccc;border-left:none;'.($_syncoActiveLang === 'nl' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">NL</a>';
    print '</div>';

    print '</div>';

    print '<div style="border:1px solid #e1e7ef;border-radius:8px;padding:14px;background:#fff;margin:0 0 12px 0;line-height:1.5">';
    print '<p style="margin:0 0 8px 0"><strong>'.syncodooText('SyncOdoo synchronise Dolibarr et Odoo dans les deux sens.', 'SyncOdoo synchroniseert Dolibarr en Odoo in beide richtingen.').'</strong></p>';
    print '<p style="margin:0 0 8px 0">'.syncodooText('Le module couvre surtout les tiers, les factures clients, les factures fournisseurs et, si configuree, la synchronisation des transactions bancaires avec une approche orientee controle utilisateur.', 'De module behandelt vooral relaties, verkoopfacturen, aankoopfacturen en, indien ingesteld, de synchronisatie van banktransacties met focus op gebruikerscontrole.').'</p>';
    print '<p style="margin:0 0 6px 0"><strong>'.syncodooText('Version', 'Versie').' :</strong> 0.2.0</p>';
    print '<p style="margin:0"><strong>'.syncodooText('Statut', 'Status').' :</strong> '.syncodooText('experimental', 'experimenteel').'</p>';
    print '</div>';

    if ($disabled) {
        print '<p class="opacitymedium" style="margin:0 0 10px 0">'.syncodooText('Vous n\'avez pas le droit de lancer une synchronisation.', 'U hebt geen rechten om een synchronisatie te starten.').'</p>';
    }

    if ($result === 'success') {
        print '<div class="ok">'.syncodooText('Synchronisation terminee en '.$elapsed.'s.', 'Synchronisatie voltooid in '.$elapsed.'s.').'</div>';
    } elseif ($result === 'error') {
        print '<div class="error">'.syncodooText('Erreur critique pendant la synchronisation. Consultez le journal.', 'Kritieke fout tijdens de synchronisatie. Raadpleeg het logboek.').'</div>';
    }

    if (is_array($stats)) {
        $visibleStats = array_filter($stats, function ($value, $key) {
            return ((int) $value > 0 && $key !== 'erreurs') || ($key === 'erreurs' && (int) $value > 0);
        }, ARRAY_FILTER_USE_BOTH);

        if (!empty($visibleStats)) {
            print '<div style="border:1px solid #e1e7ef;border-radius:8px;padding:14px;background:#fff;margin:12px 0 0 0">';
            print '<strong>'.syncodooText('Resume de la derniere execution', 'Samenvatting van de laatste uitvoering').'</strong>';
            print '<table class="noborder" style="width:100%;margin-top:8px"><tbody>';
            foreach ($visibleStats as $key => $value) {
                print '<tr><td>'.htmlspecialchars($key).'</td><td style="text-align:right;font-weight:600">'.((int) $value).'</td></tr>';
            }
            print '</tbody></table>';
            print '</div>';
        }
    }
}

// ════════════════════════════════════════════════════════
// TAB : OVER
// ════════════════════════════════════════════════════════
if ($tab === 'about') {
    $_syncoActiveLang = $_SESSION['syncodoo_lang'] ?? (strpos(strtolower((string) ($langs->defaultlang ?? '')), 'nl') === 0 ? 'nl' : 'fr');
    $_syncoBase = dol_buildpath('/syncodoo/index.php', 1).'?tab=about';
    print '<div style="text-align:right;margin:0 0 10px 0;font-size:0.88em">';
    print '<span style="color:#6c757d;margin-right:4px">'.syncodooText('Langue :', 'Taal:').'</span>';
    print '<a href="'.$_syncoBase.'&set_lang=fr" style="padding:2px 10px;text-decoration:none;border-radius:3px 0 0 3px;border:1px solid #ccc;'.($_syncoActiveLang === 'fr' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">FR</a>';
    print '<a href="'.$_syncoBase.'&set_lang=nl" style="padding:2px 10px;text-decoration:none;border-radius:0 3px 3px 0;border:1px solid #ccc;border-left:none;'.($_syncoActiveLang === 'nl' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">NL</a>';
    print '</div>';

    print '<div class="syncodoo-about-box">';
    print '<div style="margin-bottom:14px">';
    print '<img src="'.dol_buildpath('/syncodoo/img/object_syncodoo.png', 1).'" alt="SyncOdoo" style="width:3cm;height:3cm;object-fit:contain;border:1px solid #e9ecef;border-radius:6px;padding:4px;background:#fff">';
    print '</div>';

    print '<h3 style="margin:0 0 10px 0">'.syncodooText('À propos de SyncOdoo', 'Over SyncOdoo').'</h3>';
    print '<p style="margin:0 0 10px 0"><strong>Module:</strong> '.htmlspecialchars($moduleName).'<br><strong>'.syncodooText('Version', 'Versie').':</strong> '.htmlspecialchars($moduleVersion).'<br><strong>PayPal :</strong> <a href="'.htmlspecialchars($paypalUrl).'" target="_blank" rel="noopener noreferrer">'.syncodooText('Lien PayPal', 'PayPal-link').'</a></p>';
    print '<p style="margin:0 0 12px 0"><strong>'.syncodooText('Documentation :', 'Documentatie:').'</strong> <a href="'.dol_buildpath('/syncodoo/README.md', 1).'" target="_blank" rel="noopener noreferrer">'.syncodooText('Lire le README du module', 'Lees de README van de module').'</a></p>';

    print '<p style="line-height:1.55;margin:0 0 12px 0">';
    print syncodooText(
        'L\'obligation Peppol (janvier 2026) impose la facturation électronique en Belgique. Pour lancer ma société, j\'ai dû trouver une solution gratuite : chaque euro compte.<br>J\'ai combiné le flux Peppol gratuit d\'Odoo avec l\'ERP open-source Dolibarr. Je ne suis pas développeur de métier, mais le besoin m\'a poussé à construire cette solution.',
        'De Peppol-verplichting (januari 2026) maakt e-facturatie verplicht in Belgie. Voor de opstart van mijn bedrijf moest ik een gratis oplossing vinden: elke euro telt.<br>Ik heb de gratis Peppol-stroom van Odoo gecombineerd met de open-source ERP Dolibarr. Ik ben geen ontwikkelaar van opleiding, maar de nood heeft mij ertoe gebracht dit te bouwen.'
    );
    print '</p>';

    print '<h4 style="margin:0 0 8px 0">'.syncodooText('Participer &amp; Soutenir', 'Deelnemen &amp; Ondersteunen').'</h4>';
    print '<ul style="margin:0 0 10px 18px;padding:0">';
    print '<li><strong>'.syncodooText('Améliorations :', 'Verbeteringen:').'</strong> '.syncodooText('N\'hésitez pas à modifier le code ou partager vos remarques.', 'Voel u vrij om code aan te passen of feedback te delen.').'</li>';
    print '<li><strong>'.syncodooText('Soutien:', 'Steun :').'</strong> '.syncodooText('Si ce module vous aide, vous pouvez soutenir mon lancement via ce ', 'Als deze module uw cashflow helpt, kunt u mijn opstart steunen via deze ').'<a href="'.htmlspecialchars($paypalUrl).'" target="_blank" rel="noopener noreferrer">'.syncodooText('lien PayPal', 'PayPal-link').'</a>.</li>';
    print '</ul>';
    print '<p style="margin:0 0 8px 0">'.syncodooText('Don direct :', 'Directe donatie:').'</p>';
    print '<form action="https://www.paypal.com/donate" method="post" target="_top" style="margin:0 0 12px 0">';
    print '<input type="hidden" name="business" value="RBGCKNQF62C3E">';
    print '<input type="hidden" name="no_recurring" value="0">';
    print '<input type="hidden" name="currency_code" value="EUR">';
    print '<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif" border="0" name="submit" title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button">';
    print '<img alt="" border="0" src="https://www.paypal.com/en_BE/i/scr/pixel.gif" width="1" height="1">';
    print '</form>';
    print '<p style="margin:0 0 14px 0"><em>'.syncodooText('Optimisons ensemble notre comptabilité sans coût de licence.', 'Laten we samen onze boekhouding optimaliseren zonder licentiekosten.').'</em></p>';

    print '<div class="div-table-responsive">';
    print '<table class="noborder" style="width:auto"><thead>';
    print '<tr class="liste_titre"><th>Parameter</th><th>Status</th></tr>';
    print '</thead><tbody>';
    $params = [
        'SYNCODOO_ODOO_URL' => 'URL Odoo',
            'SYNCODOO_ODOO_DB' => syncodooText('Base Odoo', 'Odoo-database'),
            'SYNCODOO_ODOO_USER' => syncodooText('Utilisateur Odoo', 'Odoo-gebruiker'),
            'SYNCODOO_ODOO_PASSWORD' => syncodooText('Mot de passe Odoo', 'Odoo-wachtwoord'),
            'SYNCODOO_DOLI_APIKEY' => syncodooText('Clé API Dolibarr', 'Dolibarr API-sleutel'),
    ];
    foreach ($params as $const => $label) {
        $val = $conf->global->$const ?? '';
        if ($const === 'SYNCODOO_ODOO_PASSWORD' && empty($val)) {
            $val = $conf->global->SYNCODOO_ODOO_PASS ?? '';
        }
        if ($const === 'SYNCODOO_DOLI_APIKEY') {
            $val = !empty($user->api_key) ? $user->api_key : ($conf->global->SYNCODOO_DOLI_APIKEY ?? '');
        }
        $ok = !empty($val);
        $ico = $ok ? 'OK' : 'KO';
        $col = $ok ? '#1f8f43' : '#bf1e2e';
        print '<tr><td>'.$label.'</td><td style="color:'.$col.';font-weight:600">'.$ico.'</td></tr>';
    }
    print '</tbody></table>';
    print '</div>';
    print '</div>';
}

// ════════════════════════════════════════════════════════
// TAB : JOURNAL/LOGBOEK
// ════════════════════════════════════════════════════════
if ($tab === 'log') {

    $levelColors = [
        'INFO'    => '#155724',
        'WARNING' => '#856404',
        'ERROR'   => '#721c24',
        'DEBUG'   => '#6c757d',
    ];
    $levelBg = [
        'INFO'    => '#d4edda',
        'WARNING' => '#fff3cd',
        'ERROR'   => '#f8d7da',
        'DEBUG'   => '#f8f9fa',
    ];

    if ($user->rights->syncodoo->lancer) {
        print '<div style="margin:0 0 12px 0">';
        print '<form method="POST" action="'.dol_buildpath('/syncodoo/index.php', 1).'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="tab" value="log">';
        print '<input type="hidden" name="action" value="clear_log">';
        print '<button type="submit" class="butActionDelete" onclick="return confirm(\''.syncodooText('Vider completement le journal SyncOdoo ?', 'SyncOdoo-logboek volledig wissen?').'\');">';
        print syncodooText('🧹 Vider le journal', '🧹 Logboek wissen');
        print '</button>';
        print '</form>';
        print '</div>';
    }

    print '<table class="noborder" style="width:100%"><thead>
        <tr class="liste_titre">
            <th>'.syncodooText('Date', 'Datum').'</th>
            <th>'.syncodooText('Niveau', 'Niveau').'</th>
            <th>Direction</th>
            <th>Type</th>
            <th>'.syncodooText('Reference', 'Referentie').'</th>
            <th>'.syncodooText('Message', 'Bericht').'</th>
        </tr></thead><tbody>';

    if (empty($logs)) {
        print '<tr><td colspan="6" class="center opacitymedium">'.syncodooText('Aucun journal disponible.', 'Geen logberichten beschikbaar.').'</td></tr>';
    }

    foreach ($logs as $row) {
        $bg    = $levelBg[$row->level]    ?? '';
        $color = $levelColors[$row->level] ?? '';
        print '<tr style="background:'.$bg.'">
            <td style="white-space:nowrap;font-size:0.85em">'.
                dol_print_date($db->jdate($row->datec), 'dayhour').'</td>
            <td><span style="color:'.$color.';font-weight:600">'.
                htmlspecialchars($row->level).'</span></td>
            <td>'.htmlspecialchars($row->direction).'</td>
            <td>'.htmlspecialchars($row->entity_type).'</td>
            <td><code>'.htmlspecialchars($row->entity_ref).'</code></td>
            <td>'.htmlspecialchars($row->message).'</td>
        </tr>';
    }

    print '</tbody></table>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
