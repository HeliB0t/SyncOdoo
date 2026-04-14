#!/usr/bin/env php
<?php
/**
 * Generate simulation dataset on Dolibarr + Odoo:
 * - customers and suppliers on both sides
 * - invoices over a period (default last 365 days)
 * - at least 3 invoices/day by default
 * - VAT randomly 6% or 21%
 *
 * Usage examples:
 *   php tools/simulate_data.php
 *   php tools/simulate_data.php --execute
 *   php tools/simulate_data.php --execute --min-per-day=3 --max-per-day=4 --customers=30 --suppliers=25
 *   php tools/simulate_data.php --execute --start=2025-01-01 --end=2025-12-31 --seed=42
 */

define('DOLINC', 1);
define('NOLOGIN', 1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);

$dir = __DIR__;
$loaded = false;
for ($i = 0; $i < 6; $i++) {
    $dir = dirname($dir);
    if (is_file($dir.'/main.inc.php')) {
        require_once $dir.'/main.inc.php';
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "[ERROR] main.inc.php introuvable\n");
    exit(1);
}

require_once DOL_DOCUMENT_ROOT.'/custom/syncodoo/core/classes/SyncOdoo.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

function cliParseOptions(array $argv)
{
    $opts = [
        'start' => date('Y-m-d', strtotime('-364 days')),
        'end' => date('Y-m-d'),
        'min-per-day' => 3,
        'max-per-day' => 5,
        'customers' => 20,
        'suppliers' => 20,
        'seed' => (int) time(),
        'prefix' => 'SIMSYNC',
        'execute' => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--execute') {
            $opts['execute'] = true;
            continue;
        }
        if (substr($arg, 0, 2) !== '--' || strpos($arg, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($k, $opts)) {
            $opts[$k] = $v;
        }
    }

    $opts['min-per-day'] = max(1, (int) $opts['min-per-day']);
    $opts['max-per-day'] = max($opts['min-per-day'], (int) $opts['max-per-day']);
    $opts['customers'] = max(1, (int) $opts['customers']);
    $opts['suppliers'] = max(1, (int) $opts['suppliers']);
    $opts['seed'] = (int) $opts['seed'];

    return $opts;
}

function cliGetExecutionUser($db, $conf)
{
    global $user;
    if (!empty($user) && !empty($user->id)) {
        return $user;
    }

    $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE statut = 1';
    if (!empty($conf->entity)) {
        $sql .= ' AND entity IN (0, '.((int) $conf->entity).')';
    }
    $sql .= ' ORDER BY admin DESC, rowid ASC LIMIT 1';

    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            $u = new User($db);
            if ($u->fetch((int) $obj->rowid) > 0) {
                return $u;
            }
        }
    }

    $fallback = new User($db);
    $fallback->id = 0;
    $fallback->login = 'syncodoo-sim';
    $fallback->admin = 1;
    return $fallback;
}

function ensureDolibarrThirdparty($db, $execUser, $name, $isSupplier)
{
    $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE nom = '".$db->escape($name)."' LIMIT 1";
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            return (int) $obj->rowid;
        }
    }

    $soc = new Societe($db);
    $soc->nom = $name;
    $soc->name = $name;
    $soc->client = $isSupplier ? 0 : 1;
    $soc->fournisseur = $isSupplier ? 1 : 0;
    if ($isSupplier) {
        $soc->code_fournisseur = -1;
    } else {
        $soc->code_client = -1;
    }
    $newId = $soc->create($execUser);
    if ($newId <= 0) {
        throw new Exception('Création tiers Dolibarr impossible: '.($soc->error ?: $db->lasterror()));
    }

    return (int) $newId;
}

function ensureOdooThirdparty(SyncOdoo $sync, $name, $isSupplier)
{
    $rows = $sync->odooCallPublic(
        'res.partner',
        'search_read',
        [[['name', '=', $name]]]
    );

    if (!empty($rows[0]['id'])) {
        return (int) $rows[0]['id'];
    }

    $payload = [
        'name' => $name,
        'customer_rank' => $isSupplier ? 0 : 1,
        'supplier_rank' => $isSupplier ? 1 : 0,
    ];

    $id = $sync->odooCallPublic('res.partner', 'create', [$payload]);
    if (!(int) $id) {
        throw new Exception('Création partenaire Odoo impossible pour '.$name);
    }

    return (int) $id;
}

function findOdooTaxId(SyncOdoo $sync, $rate, $moveType)
{
    $typeUse = ($moveType === 'in_invoice') ? 'purchase' : 'sale';
    $rows = $sync->odooCallPublic(
        'account.tax',
        'search_read',
        [[['type_tax_use', '=', $typeUse], ['amount', '=', (float) $rate], ['active', '=', true]]]
    );

    if (!empty($rows[0]['id'])) {
        return (int) $rows[0]['id'];
    }

    return 0;
}

function createDolibarrInvoice($db, $execUser, $isSupplier, $socid, $invoiceDateTs, $commonRef, $ht, $vatRate)
{
    if ($isSupplier) {
        $inv = new FactureFournisseur($db);
        $inv->socid = (int) $socid;
        $inv->date = (int) $invoiceDateTs;
        $inv->ref_supplier = $commonRef;
        $inv->note_private = 'Simulation SyncOdoo '.$commonRef;
        $id = $inv->create($execUser);
        if ($id <= 0) {
            throw new Exception('Création facture fournisseur Dolibarr impossible: '.($inv->error ?: $db->lasterror()));
        }
        $resLine = $inv->addline('Simulation '.$commonRef, (float) $ht, (float) $vatRate, 0, 0, 1);
        if ($resLine <= 0) {
            throw new Exception('Ajout ligne facture fournisseur impossible: '.($inv->error ?: $db->lasterror()));
        }
        return (int) $id;
    }

    $inv = new Facture($db);
    $inv->socid = (int) $socid;
    $inv->date = (int) $invoiceDateTs;
    $inv->ref_customer = $commonRef;
    $inv->note_private = 'Simulation SyncOdoo '.$commonRef;
    $id = $inv->create($execUser);
    if ($id <= 0) {
        throw new Exception('Création facture client Dolibarr impossible: '.($inv->error ?: $db->lasterror()));
    }
    $resLine = $inv->addline('Simulation '.$commonRef, (float) $ht, 1, (float) $vatRate);
    if ($resLine <= 0) {
        throw new Exception('Ajout ligne facture client impossible: '.($inv->error ?: $db->lasterror()));
    }

    return (int) $id;
}

function createOdooInvoice(SyncOdoo $sync, $isSupplier, $partnerId, $invoiceDate, $commonRef, $ht, $vatRate, $taxMap)
{
    $moveType = $isSupplier ? 'in_invoice' : 'out_invoice';
    $taxId = (int) ($taxMap[$moveType][$vatRate] ?? 0);

    $line = [
        'name' => 'Simulation '.$commonRef,
        'quantity' => 1,
        'price_unit' => (float) $ht,
    ];
    if ($taxId > 0) {
        $line['tax_ids'] = [[6, 0, [$taxId]]];
    }

    $payload = [
        'move_type' => $moveType,
        'partner_id' => (int) $partnerId,
        'invoice_date' => $invoiceDate,
        'ref' => $commonRef,
        'payment_reference' => $commonRef,
        'invoice_origin' => $commonRef,
        'invoice_line_ids' => [[0, 0, $line]],
    ];

    $id = $sync->odooCallPublic('account.move', 'create', [$payload]);
    if (!(int) $id) {
        throw new Exception('Création facture Odoo impossible: '.$commonRef);
    }

    return (int) $id;
}

$opts = cliParseOptions($argv);
mt_srand($opts['seed']);

$startDate = DateTime::createFromFormat('Y-m-d', (string) $opts['start']);
$endDate = DateTime::createFromFormat('Y-m-d', (string) $opts['end']);

if (!$startDate || !$endDate) {
    fwrite(STDERR, "[ERROR] Paramètres de date invalides (format attendu YYYY-MM-DD).\n");
    exit(1);
}
if ($startDate > $endDate) {
    fwrite(STDERR, "[ERROR] start > end\n");
    exit(1);
}

$sync = new SyncOdoo($db);
$execUser = cliGetExecutionUser($db, $conf);

echo "[INFO] Simulation SyncOdoo\n";
echo "[INFO] Période: ".$startDate->format('Y-m-d')." -> ".$endDate->format('Y-m-d')."\n";
echo "[INFO] Min/jour: ".$opts['min-per-day'].", Max/jour: ".$opts['max-per-day']."\n";
echo "[INFO] Clients: ".$opts['customers'].", Fournisseurs: ".$opts['suppliers']."\n";
echo "[INFO] Seed: ".$opts['seed']."\n";
echo "[INFO] Mode: ".($opts['execute'] ? 'EXECUTE' : 'DRY-RUN')."\n";

if (!$sync->connectOdoo()) {
    fwrite(STDERR, "[ERROR] Connexion Odoo impossible: ".$sync->lastError."\n");
    exit(2);
}

$taxMap = [
    'out_invoice' => [6 => 0, 21 => 0],
    'in_invoice' => [6 => 0, 21 => 0],
];
foreach (['out_invoice', 'in_invoice'] as $moveType) {
    foreach ([6, 21] as $rate) {
        $taxMap[$moveType][$rate] = findOdooTaxId($sync, $rate, $moveType);
    }
}

if ($taxMap['out_invoice'][6] <= 0 && $taxMap['out_invoice'][21] <= 0) {
    echo "[WARN] Aucun taux de TVA vente 6/21 trouvé dans Odoo.\n";
}
if ($taxMap['in_invoice'][6] <= 0 && $taxMap['in_invoice'][21] <= 0) {
    echo "[WARN] Aucun taux de TVA achat 6/21 trouvé dans Odoo.\n";
}

$partners = [
    'customer' => [],
    'supplier' => [],
];

for ($i = 1; $i <= (int) $opts['customers']; $i++) {
    $name = $opts['prefix'].'-CUSTOMER-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $partners['customer'][] = [
        'name' => $name,
        'dol_id' => ensureDolibarrThirdparty($db, $execUser, $name, false),
        'odoo_id' => ensureOdooThirdparty($sync, $name, false),
    ];
}

for ($i = 1; $i <= (int) $opts['suppliers']; $i++) {
    $name = $opts['prefix'].'-SUPPLIER-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $partners['supplier'][] = [
        'name' => $name,
        'dol_id' => ensureDolibarrThirdparty($db, $execUser, $name, true),
        'odoo_id' => ensureOdooThirdparty($sync, $name, true),
    ];
}

$stats = [
    'days' => 0,
    'invoices_target' => 0,
    'dolibarr_created' => 0,
    'odoo_created' => 0,
    'customer' => 0,
    'supplier' => 0,
    'vat_6' => 0,
    'vat_21' => 0,
];

$cursor = clone $startDate;
$sequence = 1;

while ($cursor <= $endDate) {
    $stats['days']++;
    $dailyCount = mt_rand((int) $opts['min-per-day'], (int) $opts['max-per-day']);
    $stats['invoices_target'] += $dailyCount;

    for ($n = 0; $n < $dailyCount; $n++) {
        $isSupplier = (mt_rand(0, 1) === 1);
        $entityType = $isSupplier ? 'supplier' : 'customer';
        $pool = $partners[$entityType];
        $partner = $pool[array_rand($pool)];

        $vatRate = (mt_rand(0, 1) === 1) ? 6 : 21;
        $ht = round(mt_rand(5000, 250000) / 100, 2);
        $tva = round($ht * $vatRate / 100, 2);
        $ttc = round($ht + $tva, 2);

        $commonRef = $opts['prefix'].'-'.$cursor->format('Ymd').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        $sequence++;

        if (!$opts['execute']) {
            continue;
        }

        $invoiceDateTs = strtotime($cursor->format('Y-m-d'));
        createDolibarrInvoice(
            $db,
            $execUser,
            $isSupplier,
            (int) $partner['dol_id'],
            $invoiceDateTs,
            $commonRef,
            $ht,
            $vatRate
        );

        createOdooInvoice(
            $sync,
            $isSupplier,
            (int) $partner['odoo_id'],
            $cursor->format('Y-m-d'),
            $commonRef,
            $ht,
            $vatRate,
            $taxMap
        );

        $stats['dolibarr_created']++;
        $stats['odoo_created']++;
        $stats[$entityType]++;
        $stats[$vatRate === 6 ? 'vat_6' : 'vat_21']++;
    }

    $cursor->modify('+1 day');
}

if (!$opts['execute']) {
    // In dry-run we still expose expected random distribution.
    $estimatedCustomer = (int) floor($stats['invoices_target'] / 2);
    $estimatedSupplier = $stats['invoices_target'] - $estimatedCustomer;
    $estimatedVat6 = (int) floor($stats['invoices_target'] / 2);
    $estimatedVat21 = $stats['invoices_target'] - $estimatedVat6;
    $stats['customer'] = $estimatedCustomer;
    $stats['supplier'] = $estimatedSupplier;
    $stats['vat_6'] = $estimatedVat6;
    $stats['vat_21'] = $estimatedVat21;
}

echo "[DONE] Jours couverts: ".$stats['days']."\n";
echo "[DONE] Factures ciblées (min): ".$stats['invoices_target']."\n";
echo "[DONE] Répartition estimée/réelle client: ".$stats['customer'].", fournisseur: ".$stats['supplier']."\n";
echo "[DONE] Répartition TVA 6%: ".$stats['vat_6'].", TVA 21%: ".$stats['vat_21']."\n";
echo "[DONE] Factures créées Dolibarr: ".$stats['dolibarr_created']."\n";
echo "[DONE] Factures créées Odoo: ".$stats['odoo_created']."\n";

if (!$opts['execute']) {
    echo "[INFO] Aucun enregistrement réel (mode DRY-RUN). Relancer avec --execute pour écrire les données.\n";
}

$db->close();
