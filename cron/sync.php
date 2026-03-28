#!/usr/bin/env php
<?php
/**
 * Script CLI pour l'exécution via crontab système.
 *
 * Usage :
 *   php /var/www/dolibarr/htdocs/syncodoo/cron/sync.php
 *
 * Exemple crontab (toutes les heures) :
 *   0 * * * * php /var/www/dolibarr/htdocs/syncodoo/cron/sync.php
 */

// ── Bootstrap Dolibarr ───────────────────────────────────
define('DOLINC', 1);
define('NOTOKENRENEWAL', 1);
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);

// Trouver le main.inc.php en remontant l'arborescence
$dir = __DIR__;
$found = false;
for ($i = 0; $i < 5; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir.'/main.inc.php')) {
        require_once $dir.'/main.inc.php';
        $found = true;
        break;
    }
}
if (!$found) {
    fwrite(STDERR, "[CRITICAL] main.inc.php introuvable\n");
    exit(1);
}

require_once DOL_DOCUMENT_ROOT.'/custom/syncodoo/core/classes/SyncOdoo.class.php';

// ── Exécution ────────────────────────────────────────────
$start = microtime(true);
echo date('[Y-m-d H:i:s]')." Début de la synchronisation Dolibarr ↔ Odoo\n";

try {
    $sync = new SyncOdoo($db);
    $ok   = $sync->runAll();

    $elapsed = round(microtime(true) - $start, 2);
    $stats   = $sync->stats;

    echo date('[Y-m-d H:i:s]')." Synchronisation ".($ok ? 'terminée' : 'terminée avec erreurs').
         " ({$elapsed}s)\n";

    foreach ($stats as $key => $val) {
        if ($val > 0) {
            echo "  {$key}: {$val}\n";
        }
    }

    exit($ok ? 0 : 1);

} catch (Throwable $e) {
    fwrite(STDERR, date('[Y-m-d H:i:s]')." [CRITICAL] ".$e->getMessage()."\n");
    exit(2);
} finally {
    $db->close();
}
