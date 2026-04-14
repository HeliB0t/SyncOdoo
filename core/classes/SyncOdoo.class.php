<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

$syncodooRipcordPath = DOL_DOCUMENT_ROOT.'/custom/syncodoo/lib/ripcord/ripcord.php';
if (is_readable($syncodooRipcordPath)) {
    require_once $syncodooRipcordPath;
}

class SyncOdoo
{
    public $db;
    public $odoo_url;
    public $odoo_db;
    public $odoo_user;
    public $odoo_password;
    public $odoo_uid;
    public $stats;
    public $lastError;
    public $doli_api_key;

    private $executionUser;
    private $hasSocieteOdooIdColumn;

    private function odooJsonRpc($service, $method, $args)
    {
        $url = $this->odoo_url.'/jsonrpc';
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => uniqid('syncodoo_', true),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Erreur CURL Odoo: '.$err);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception('Erreur HTTP Odoo '.$httpCode.': '.$raw);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new Exception('Reponse JSON Odoo invalide.');
        }

        if (!empty($decoded['error'])) {
            $message = $decoded['error']['data']['message'] ?? ($decoded['error']['message'] ?? 'Erreur JSON-RPC Odoo');
            throw new Exception($message);
        }

        return $decoded['result'] ?? null;
    }

    private function odooExecuteKw($model, $method, array $args, array $kwargs = [])
    {
        $rpcArgs = [
            $this->odoo_db,
            (int) $this->odoo_uid,
            $this->odoo_password,
            $model,
            $method,
            $args,
        ];

        if (!empty($kwargs)) {
            $rpcArgs[] = $kwargs;
        }

        return $this->odooJsonRpc('object', 'execute_kw', $rpcArgs);
    }

    public function __construct($db)
    {
        global $conf, $user;

        $this->db = $db;

        $this->odoo_url      = rtrim($conf->global->SYNCODOO_ODOO_URL ?? '', '/');
        $this->odoo_db       = $conf->global->SYNCODOO_ODOO_DB ?? '';
        $this->odoo_user     = $conf->global->SYNCODOO_ODOO_USER ?? '';
        $this->odoo_password = $conf->global->SYNCODOO_ODOO_PASSWORD ?? ($conf->global->SYNCODOO_ODOO_PASS ?? '');
        
        // Auto-retrieve current user's API key from Dolibarr
        // Fallback to config if not available
        $this->doli_api_key = ($user && !empty($user->api_key)) ? $user->api_key : ($conf->global->SYNCODOO_DOLI_APIKEY ?? '');

        $this->stats = [
            'tiers_crees_odoo' => 0,
            'tiers_maj_odoo' => 0,
            'tiers_crees_doli' => 0,
            'tiers_maj_doli' => 0,
            'factures_crees_odoo' => 0,
            'factures_maj_odoo' => 0,
            'factures_crees_doli' => 0,
            'transactions_bancaires_creees_odoo' => 0,
            'transactions_bancaires_maj_odoo' => 0,
            'transactions_bancaires_creees_doli' => 0,
            'transactions_bancaires_maj_doli' => 0,
            'suppressions' => 0,
            'erreurs' => 0,
        ];
        $this->lastError = '';
        $this->executionUser = null;
        $this->hasSocieteOdooIdColumn = null;
    }

    private function hasSocieteOdooIdColumn()
    {
        if ($this->hasSocieteOdooIdColumn !== null) {
            return $this->hasSocieteOdooIdColumn;
        }

        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."societe_extrafields LIKE 'odoo_id'";
        $resql = $this->db->query($sql);
        $this->hasSocieteOdooIdColumn = ($resql && $this->db->num_rows($resql) > 0);

        return $this->hasSocieteOdooIdColumn;
    }

    private function normalizeLookupValue($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    private function isBankSyncEnabled()
    {
        global $conf;

        return ((int) ($conf->global->SYNCODOO_BANK_SYNC_ENABLED ?? 0)) === 1;
    }

    private function getBankSyncDirection()
    {
        global $conf;

        $direction = trim((string) ($conf->global->SYNCODOO_BANK_SYNC_DIRECTION ?? 'both'));
        if (!in_array($direction, ['odoo_to_dolibarr', 'dolibarr_to_odoo', 'both'], true)) {
            return 'both';
        }

        return $direction;
    }

    private function getConfiguredOdooBankJournalSelector()
    {
        global $conf;

        return trim((string) ($conf->global->SYNCODOO_ODOO_BANK_JOURNAL ?? ''));
    }

    private function getConfiguredDolibarrBankAccountId()
    {
        global $conf;

        return (int) ($conf->global->SYNCODOO_DOLI_BANK_ACCOUNT_ID ?? 0);
    }

    private function getConfiguredBankSyncStartDate()
    {
        global $conf;

        $value = trim((string) ($conf->global->SYNCODOO_BANK_SYNC_START_DATE ?? ''));
        if ($value === '') {
            return '';
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return '';
        }

        return date('Y-m-d', $ts);
    }

    private function ensureBankSyncTableExists()
    {
        $sql = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_bank_map (
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

        $this->db->query($sql);
    }

    public function getDolibarrBankAccounts()
    {
        $accounts = [];
        $sql = "SELECT rowid, ref, label, iban_prefix, currency_code, clos
                FROM ".MAIN_DB_PREFIX."bank_account
                WHERE entity IN (".getEntity('bank_account').")
                ORDER BY clos ASC, label ASC, ref ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $accounts;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $accounts[] = [
                'id' => (int) ($obj->rowid ?? 0),
                'ref' => (string) ($obj->ref ?? ''),
                'label' => (string) ($obj->label ?? ''),
                'iban' => (string) ($obj->iban_prefix ?? ''),
                'currency_code' => (string) ($obj->currency_code ?? ''),
                'closed' => (int) ($obj->clos ?? 0),
            ];
        }

        return $accounts;
    }

    private function getDolibarrBankAccountObject($accountId)
    {
        $accountId = (int) $accountId;
        if ($accountId <= 0) {
            return null;
        }

        $account = new Account($this->db);
        if ($account->fetch($accountId) <= 0) {
            return null;
        }

        return $account;
    }

    private function getBankSyncMappingByOdooId($odooTransactionId)
    {
        $this->ensureBankSyncTableExists();

        $odooTransactionId = (int) $odooTransactionId;
        if ($odooTransactionId <= 0) {
            return null;
        }

        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."syncodoo_bank_map WHERE odoo_transaction_id = ".$odooTransactionId." LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return [
                    'rowid' => (int) ($obj->rowid ?? 0),
                    'odoo_transaction_id' => (int) ($obj->odoo_transaction_id ?? 0),
                    'dolibarr_bank_line_id' => (int) ($obj->dolibarr_bank_line_id ?? 0),
                    'dolibarr_bank_account_id' => (int) ($obj->dolibarr_bank_account_id ?? 0),
                    'odoo_journal_id' => (int) ($obj->odoo_journal_id ?? 0),
                    'sync_direction' => (string) ($obj->sync_direction ?? ''),
                    'odoo_write_date' => (string) ($obj->odoo_write_date ?? ''),
                ];
            }
        }

        return null;
    }

    private function getBankSyncMappingByDolibarrLineId($bankLineId)
    {
        $this->ensureBankSyncTableExists();

        $bankLineId = (int) $bankLineId;
        if ($bankLineId <= 0) {
            return null;
        }

        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."syncodoo_bank_map WHERE dolibarr_bank_line_id = ".$bankLineId." LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return [
                    'rowid' => (int) ($obj->rowid ?? 0),
                    'odoo_transaction_id' => (int) ($obj->odoo_transaction_id ?? 0),
                    'dolibarr_bank_line_id' => (int) ($obj->dolibarr_bank_line_id ?? 0),
                    'dolibarr_bank_account_id' => (int) ($obj->dolibarr_bank_account_id ?? 0),
                    'odoo_journal_id' => (int) ($obj->odoo_journal_id ?? 0),
                    'sync_direction' => (string) ($obj->sync_direction ?? ''),
                    'odoo_write_date' => (string) ($obj->odoo_write_date ?? ''),
                ];
            }
        }

        return null;
    }

    private function saveBankSyncMapping($odooTransactionId, $bankLineId, $bankAccountId, $odooJournalId, $direction, $odooWriteDate = '')
    {
        $this->ensureBankSyncTableExists();

        $odooTransactionId = (int) $odooTransactionId;
        $bankLineId = (int) $bankLineId;
        $bankAccountId = (int) $bankAccountId;
        $odooJournalId = (int) $odooJournalId;
        $direction = trim((string) $direction);
        $odooWriteDate = trim((string) $odooWriteDate);

        $existing = null;
        if ($odooTransactionId > 0) {
            $existing = $this->getBankSyncMappingByOdooId($odooTransactionId);
        }
        if ($existing === null && $bankLineId > 0) {
            $existing = $this->getBankSyncMappingByDolibarrLineId($bankLineId);
        }

        if ($existing) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."syncodoo_bank_map SET";
            $sql .= " odoo_transaction_id = ".($odooTransactionId > 0 ? $odooTransactionId : 'NULL');
            $sql .= ", dolibarr_bank_line_id = ".($bankLineId > 0 ? $bankLineId : 'NULL');
            $sql .= ", dolibarr_bank_account_id = ".($bankAccountId > 0 ? $bankAccountId : 'NULL');
            $sql .= ", odoo_journal_id = ".($odooJournalId > 0 ? $odooJournalId : 'NULL');
            $sql .= ", sync_direction = '".$this->db->escape($direction)."'";
            $sql .= ", odoo_write_date = ".($odooWriteDate !== '' ? "'".$this->db->escape($odooWriteDate)."'" : 'NULL');
            $sql .= " WHERE rowid = ".((int) $existing['rowid']);
            $this->db->query($sql);
            return;
        }

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."syncodoo_bank_map (datec, odoo_transaction_id, dolibarr_bank_line_id, dolibarr_bank_account_id, odoo_journal_id, sync_direction, odoo_write_date)
                VALUES (NOW(), ".($odooTransactionId > 0 ? $odooTransactionId : 'NULL').", ".($bankLineId > 0 ? $bankLineId : 'NULL').", ".($bankAccountId > 0 ? $bankAccountId : 'NULL').", ".($odooJournalId > 0 ? $odooJournalId : 'NULL').", '".$this->db->escape($direction)."', ".($odooWriteDate !== '' ? "'".$this->db->escape($odooWriteDate)."'" : 'NULL').")";
        $this->db->query($sql);
    }

    private function odooSearchReadAll($model, array $domain, array $fields, array $kwargs = [])
    {
        global $conf;

        $limit = max(1, (int) ($kwargs['limit'] ?? ($conf->global->SYNCODOO_LIMIT ?? 500)));
        $offset = 0;
        $rows = [];

        while (true) {
            $batchKwargs = $kwargs;
            $batchKwargs['fields'] = $fields;
            $batchKwargs['limit'] = $limit;
            $batchKwargs['offset'] = $offset;

            $batch = $this->odooExecuteKw($model, 'search_read', [$domain], $batchKwargs);
            if (empty($batch) || !is_array($batch)) {
                break;
            }

            foreach ($batch as $row) {
                $rows[] = $row;
            }

            if (count($batch) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return $rows;
    }

    private function findMatchingDolibarrThirdparty(array $odoo, ?array $doliData = null)
    {
        $doliData = $doliData ?? $this->getDolibarrTiers();
        $vat = $this->normalizeLookupValue($odoo['vat'] ?? '');
        $email = $this->normalizeLookupValue($odoo['email'] ?? '');
        $name = $this->normalizeLookupValue($odoo['name'] ?? '');

        foreach ($doliData as $row) {
            if ($vat !== '' && $vat === $this->normalizeLookupValue($row['vat'] ?? '')) {
                return $row;
            }
        }

        foreach ($doliData as $row) {
            if ($email !== '' && $email === $this->normalizeLookupValue($row['email'] ?? '')) {
                return $row;
            }
        }

        foreach ($doliData as $row) {
            if ($name !== '' && $name === $this->normalizeLookupValue($row['name'] ?? '')) {
                return $row;
            }
        }

        return null;
    }

    private function findOdooPartnerByField($field, $value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $partners = $this->odooExecuteKw(
            'res.partner',
            'search_read',
            [[[$field, '=', $value]]],
            ['fields' => ['id', 'name', 'email', 'phone', 'zip', 'city', 'vat', 'customer_rank', 'supplier_rank'], 'limit' => 1]
        );

        if (empty($partners)) {
            return null;
        }

        $partner = $partners[0];

        return [
            '_id' => $partner['id'],
            '_ref' => $partner['name'],
            'odoo_id' => $partner['id'],
            'name' => $partner['name'],
            'email' => $partner['email'],
            'phone' => $partner['phone'],
            'zip' => $partner['zip'],
            'town' => $partner['city'],
            'vat' => $partner['vat'],
            'customer_rank' => $partner['customer_rank'],
            'supplier_rank' => $partner['supplier_rank'],
            'type_flags' => $this->getOdooTypeFlags($partner['customer_rank'], $partner['supplier_rank']),
        ];
    }

    private function findMatchingOdooThirdparty(array $doli, ?array $odooData = null)
    {
        $odooData = $odooData ?? $this->getOdooTiers();
        $vat = $this->normalizeLookupValue($doli['vat'] ?? '');
        $email = $this->normalizeLookupValue($doli['email'] ?? '');
        $name = $this->normalizeLookupValue($doli['name'] ?? '');

        foreach ($odooData as $row) {
            if ($vat !== '' && $vat === $this->normalizeLookupValue($row['vat'] ?? '')) {
                return $row;
            }
        }

        foreach ($odooData as $row) {
            if ($email !== '' && $email === $this->normalizeLookupValue($row['email'] ?? '')) {
                return $row;
            }
        }

        foreach ($odooData as $row) {
            if ($name !== '' && $name === $this->normalizeLookupValue($row['name'] ?? '')) {
                return $row;
            }
        }

        if ($vat !== '') {
            $partner = $this->findOdooPartnerByField('vat', $doli['vat'] ?? '');
            if ($partner) {
                return $partner;
            }
        }

        if ($email !== '') {
            $partner = $this->findOdooPartnerByField('email', $doli['email'] ?? '');
            if ($partner) {
                return $partner;
            }
        }

        if ($name !== '') {
            $partner = $this->findOdooPartnerByField('name', $doli['name'] ?? '');
            if ($partner) {
                return $partner;
            }
        }

        return null;
    }

    private function getOdooReferencedPartnerIds()
    {
        $partnerIds = [];

        foreach (['out_invoice', 'in_invoice'] as $moveType) {
            $moves = $this->odooExecuteKw(
                'account.move',
                'search_read',
                [[['move_type', '=', $moveType], ['state', '!=', 'cancel']]],
                ['fields' => ['partner_id']]
            );

            foreach ($moves as $move) {
                $partnerId = (int) ($move['partner_id'][0] ?? 0);
                if ($partnerId > 0) {
                    $partnerIds[$partnerId] = $partnerId;
                }
            }
        }

        return array_values($partnerIds);
    }

    private function fetchOdooPartnersByIds(array $partnerIds)
    {
        $partnerIds = array_values(array_filter(array_map('intval', $partnerIds)));
        if (empty($partnerIds)) {
            return [];
        }

        return $this->odooExecuteKw(
            'res.partner',
            'search_read',
            [[['id', 'in', $partnerIds]]],
            ['fields' => ['id', 'name', 'email', 'phone', 'zip', 'city', 'vat', 'customer_rank', 'supplier_rank']]
        );
    }

    private function getExecutionUser()
    {
        global $user, $conf;

        if (!empty($user) && !empty($user->id)) {
            return $user;
        }

        if ($this->executionUser instanceof User && !empty($this->executionUser->id)) {
            return $this->executionUser;
        }

        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user';
        $sql .= ' WHERE statut = 1';
        if (!empty($conf->entity)) {
            $sql .= ' AND entity IN (0, '.((int) $conf->entity).')';
        }
        $sql .= ' ORDER BY admin DESC, rowid ASC LIMIT 1';

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $syncUser = new User($this->db);
                if ($syncUser->fetch((int) $obj->rowid) > 0) {
                    $this->executionUser = $syncUser;
                    return $this->executionUser;
                }
            }
        }

        $fallbackUser = new User($this->db);
        $fallbackUser->id = 0;
        $fallbackUser->login = 'syncodoo';
        $fallbackUser->admin = 1;
        $this->executionUser = $fallbackUser;

        return $this->executionUser;
    }

    /**
     * Normalise une valeur pour la comparaison de divergences.
     * Gère false/null d'Odoo, trim, et normalisation par type de champ.
     */
    private function normalizeForComparison($value, string $field = ''): string
    {
        if ($value === false || $value === null) {
            return '';
        }
        $str = trim((string) $value);

        if ($field === 'email') {
            return strtolower($str);
        }
        if ($field === 'phone') {
            return preg_replace('/[\s\-\.\(\)\/]/', '', $str);
        }
        if ($field === 'vat') {
            return strtoupper(preg_replace('/[\s\-\.]/', '', $str));
        }

        return $str;
    }

    private function normalizeSyncValue($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private function mergeSyncValue($dolibarrValue, $odooValue)
    {
        $dolibarrValue = $this->normalizeSyncValue($dolibarrValue);
        $odooValue = $this->normalizeSyncValue($odooValue);

        if ($dolibarrValue !== '' && $dolibarrValue !== null) {
            return $dolibarrValue;
        }

        return $odooValue;
    }

    private function getDolibarrTypeFlags($clientValue, $supplierValue)
    {
        $clientValue = (int) $clientValue;

        return [
            'client' => in_array($clientValue, [1, 3], true),
            'prospect' => in_array($clientValue, [2, 3], true),
            'fournisseur' => ((int) $supplierValue > 0),
        ];
    }

    private function getOdooTypeFlags($customerRank, $supplierRank)
    {
        $customerRank = (int) $customerRank;
        $supplierRank = (int) $supplierRank;

        return [
            'client' => $customerRank > 0,
            'prospect' => ($customerRank <= 0 && $supplierRank <= 0),
            'fournisseur' => $supplierRank > 0,
        ];
    }

    private function buildDolibarrClientValue(array $types)
    {
        $hasClient = !empty($types['client']);
        $hasProspect = !empty($types['prospect']);

        if ($hasClient && $hasProspect) {
            return 3;
        }
        if ($hasProspect) {
            return 2;
        }
        if ($hasClient) {
            return 1;
        }

        return 0;
    }

    private function buildOdooTypePayload(array $types)
    {
        return [
            'customer_rank' => !empty($types['client']) ? 1 : 0,
            'supplier_rank' => !empty($types['fournisseur']) ? 1 : 0,
        ];
    }

    private function normalizeTypeSelection(array $types)
    {
        return [
            'client' => !empty($types['client']),
            'prospect' => !empty($types['prospect']),
            'fournisseur' => !empty($types['fournisseur']),
        ];
    }

    private function getDolibarrObjectError($object, $fallback = '')
    {
        $messages = [];

        if (is_object($object)) {
            if (!empty($object->error)) {
                $messages[] = trim((string) $object->error);
            }
            if (!empty($object->errors) && is_array($object->errors)) {
                foreach ($object->errors as $error) {
                    $error = trim((string) $error);
                    if ($error !== '') {
                        $messages[] = $error;
                    }
                }
            }
        }

        $dbError = trim((string) $this->db->lasterror());
        if ($dbError !== '') {
            $messages[] = $dbError;
        }

        if ($fallback !== '') {
            $messages[] = trim($fallback);
        }

        $messages = array_values(array_unique(array_filter($messages)));

        return !empty($messages) ? implode(' | ', $messages) : 'Aucun détail Dolibarr disponible';
    }

    private function getDolibarrThirdpartyIdByOdooId($odooId)
    {
        if (!$this->hasSocieteOdooIdColumn()) {
            return 0;
        }

        $sql = 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'societe_extrafields';
        $sql .= ' WHERE odoo_id = '.((int) $odooId).' LIMIT 1';
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return (int) $obj->fk_object;
            }
        }

        return 0;
    }

    private function updateThirdpartyMapping($dolId, $odooId)
    {
        $dolId = (int) $dolId;
        $odooId = (int) $odooId;

        if (!$this->hasSocieteOdooIdColumn()) {
            return;
        }

        $sql = 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'societe_extrafields WHERE fk_object = '.$dolId;
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $sql = 'UPDATE '.MAIN_DB_PREFIX.'societe_extrafields SET odoo_id = '.$odooId.' WHERE fk_object = '.$dolId;
        } else {
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'societe_extrafields (fk_object, odoo_id) VALUES ('.$dolId.', '.$odooId.')';
        }

        if (!$this->db->query($sql)) {
            throw new Exception('Erreur mapping tiers: '.$this->db->lasterror());
        }
    }

    private function syncThirdpartyPair(array $doli, array $odoo)
    {
        $user = $this->getExecutionUser();
        $dolId = (int) ($doli['dol_id'] ?? 0);
        $odooId = (int) ($odoo['odoo_id'] ?? 0);

        if ($dolId <= 0 || $odooId <= 0) {
            throw new Exception('Synchronisation tiers impossible: identifiants manquants');
        }

        $merged = [
            'name' => $this->mergeSyncValue($doli['name'] ?? '', $odoo['name'] ?? ''),
            'email' => $this->mergeSyncValue($doli['email'] ?? '', $odoo['email'] ?? ''),
            'phone' => $this->mergeSyncValue($doli['phone'] ?? '', $odoo['phone'] ?? ''),
            'zip' => $this->mergeSyncValue($doli['zip'] ?? '', $odoo['zip'] ?? ''),
            'town' => $this->mergeSyncValue($doli['town'] ?? '', $odoo['town'] ?? ''),
            'vat' => $this->mergeSyncValue($doli['vat'] ?? '', $odoo['vat'] ?? ''),
            'client' => !empty($doli['type_flags']['client']) || !empty($odoo['type_flags']['client']),
            'prospect' => !empty($doli['type_flags']['prospect']) || !empty($odoo['type_flags']['prospect']),
            'fournisseur' => !empty($doli['type_flags']['fournisseur']) || !empty($odoo['type_flags']['fournisseur']),
        ];

        $societe = new Societe($this->db);
        if ($societe->fetch($dolId) <= 0) {
            throw new Exception('Tiers Dolibarr introuvable: '.$dolId);
        }

        $hasDolibarrChanges = false;
        if (($societe->nom ?? '') !== $merged['name'] && $merged['name'] !== '') {
            $societe->nom = $merged['name'];
            $societe->name = $merged['name'];
            $hasDolibarrChanges = true;
        }
        if (($societe->email ?? '') !== $merged['email']) {
            $societe->email = $merged['email'];
            $hasDolibarrChanges = true;
        }
        if (($societe->phone ?? '') !== $merged['phone']) {
            $societe->phone = $merged['phone'];
            $hasDolibarrChanges = true;
        }
        if (($societe->zip ?? '') !== $merged['zip']) {
            $societe->zip = $merged['zip'];
            $hasDolibarrChanges = true;
        }
        if (($societe->town ?? '') !== $merged['town']) {
            $societe->town = $merged['town'];
            $hasDolibarrChanges = true;
        }
        if (($societe->tva_intra ?? '') !== $merged['vat']) {
            $societe->tva_intra = $merged['vat'];
            $hasDolibarrChanges = true;
        }
        $dolClientValue = $this->buildDolibarrClientValue($merged);
        if ((int) ($societe->client ?? 0) !== $dolClientValue) {
            $societe->client = $dolClientValue;
            $hasDolibarrChanges = true;
        }
        if ((int) ($societe->fournisseur ?? 0) !== (!empty($merged['fournisseur']) ? 1 : 0)) {
            $societe->fournisseur = !empty($merged['fournisseur']) ? 1 : 0;
            $hasDolibarrChanges = true;
        }

        if ($hasDolibarrChanges) {
            $res = $societe->update($societe->id, $user);
            if ($res <= 0) {
                throw new Exception('Erreur maj Dolibarr: '.($societe->error ?: $this->db->lasterror()));
            }
            $this->stats['tiers_maj_doli']++;
        }

        $odooChanges = [];
        if (($odoo['name'] ?? '') !== $merged['name'] && $merged['name'] !== '') $odooChanges['name'] = $merged['name'];
        if (($odoo['email'] ?? '') !== $merged['email']) $odooChanges['email'] = $merged['email'];
        if (($odoo['phone'] ?? '') !== $merged['phone']) $odooChanges['phone'] = $merged['phone'];
        if (($odoo['zip'] ?? '') !== $merged['zip']) $odooChanges['zip'] = $merged['zip'];
        if (($odoo['town'] ?? '') !== $merged['town']) $odooChanges['city'] = $merged['town'];
        if (($odoo['vat'] ?? '') !== $merged['vat']) $odooChanges['vat'] = $merged['vat'];
        $odooTypePayload = $this->buildOdooTypePayload($merged);
        if ((int) ($odoo['customer_rank'] ?? 0) !== (int) $odooTypePayload['customer_rank']) $odooChanges['customer_rank'] = $odooTypePayload['customer_rank'];
        if ((int) ($odoo['supplier_rank'] ?? 0) !== (int) $odooTypePayload['supplier_rank']) $odooChanges['supplier_rank'] = $odooTypePayload['supplier_rank'];

        if (!empty($odooChanges)) {
            $this->odooCallPublic('res.partner', 'write', [[(int) $odooId], $odooChanges]);
            $this->stats['tiers_maj_odoo']++;
        }

        if ($hasDolibarrChanges || !empty($odooChanges)) {
            $this->log('INFO', 'sync', 'thirdparty', $merged['name'] ?: ('#'.$dolId), 'Synchronisation bidirectionnelle tiers');
        }
    }

    private function getDolibarrInvoicePlaceholderLine($ref, $amount, $side)
    {
        $label = $side === 'odoo' ? 'Import Odoo' : 'Export Dolibarr';

        return [
            'desc' => $label.' '.$ref,
            'qty' => 1,
            'unit_price' => (float) $amount,
            'vat' => 0,
        ];
    }

    private function formatDateForOdoo($value)
    {
        if (empty($value)) {
            return date('Y-m-d');
        }

        if (is_numeric($value)) {
            return date('Y-m-d', (int) $value);
        }

        return substr((string) $value, 0, 10);
    }

    private function isInvoiceAttachmentImportEnabled()
    {
        global $conf;

        return ((int) ($conf->global->SYNCODOO_IMPORT_INVOICE_FILE ?? 0)) === 1;
    }

    private function isInvoiceAttachmentExportEnabled()
    {
        global $conf;

        return ((int) ($conf->global->SYNCODOO_EXPORT_INVOICE_FILE ?? 0)) === 1;
    }

    private function getDolibarrInvoiceUploadDir($dolInvoiceType, $dolInvoiceId, $dolInvoiceRef)
    {
        global $conf;

        $ref = dol_sanitizeFileName((string) $dolInvoiceRef);
        if ($ref === '') {
            return '';
        }

        if ((string) $dolInvoiceType === 'supplier') {
            $object = new FactureFournisseur($this->db);
            $object->id = (int) $dolInvoiceId;
            $object->ref = $ref;
            $baseDir = !empty($conf->fournisseur->facture->multidir_output[$conf->entity])
                ? $conf->fournisseur->facture->multidir_output[$conf->entity]
                : $conf->fournisseur->facture->dir_output;

            return rtrim((string) $baseDir, '/').'/'.get_exdir($object->id, 2, 0, 0, $object, 'invoice_supplier').$ref;
        }

        $baseDir = !empty($conf->facture->multidir_output[$conf->entity])
            ? $conf->facture->multidir_output[$conf->entity]
            : $conf->facture->dir_output;

        return rtrim((string) $baseDir, '/').'/'.$ref;
    }

    private function getOdooInvoiceAttachmentRecord(array $odooInv)
    {
        $odooId = (int) ($odooInv['id'] ?? 0);
        if ($odooId <= 0) {
            return null;
        }

        $attachments = $this->odooCallPublic(
            'ir.attachment',
            'search_read',
            [
                [
                    ['res_model', '=', 'account.move'],
                    ['res_id', '=', $odooId],
                    ['type', '=', 'binary'],
                ],
                ['id', 'name', 'datas_fname', 'mimetype', 'datas'],
            ]
        );

        if (empty($attachments) || !is_array($attachments)) {
            return null;
        }

        $preferred = null;
        foreach ($attachments as $attachment) {
            $mimetype = strtolower(trim((string) ($attachment['mimetype'] ?? '')));
            $filename = strtolower(trim((string) (($attachment['datas_fname'] ?? '') ?: ($attachment['name'] ?? ''))));
            $isPdf = ($mimetype === 'application/pdf' || substr($filename, -4) === '.pdf');

            if ($isPdf) {
                $preferred = $attachment;
                break;
            }

            if ($preferred === null) {
                $preferred = $attachment;
            }
        }

        if (empty($preferred)) {
            return null;
        }

        if (empty($preferred['datas'])) {
            $reloaded = $this->odooCallPublic(
                'ir.attachment',
                'read',
                [[(int) ($preferred['id'] ?? 0)], ['id', 'name', 'datas_fname', 'mimetype', 'datas']]
            );
            if (!empty($reloaded[0])) {
                $preferred = $reloaded[0];
            }
        }

        return !empty($preferred['datas']) ? $preferred : null;
    }

    private function importOdooInvoiceAttachmentToDolibarr(array $odooInv, $dolInvoiceId, $dolInvoiceRef, $dolInvoiceType)
    {
        if (!$this->isInvoiceAttachmentImportEnabled()) {
            return;
        }

        $attachment = $this->getOdooInvoiceAttachmentRecord($odooInv);
        if (empty($attachment)) {
            return;
        }

        $dir = $this->getDolibarrInvoiceUploadDir($dolInvoiceType, (int) $dolInvoiceId, (string) $dolInvoiceRef);
        if ($dir === '') {
            throw new Exception('Répertoire de documents facture introuvable');
        }

        if (dol_mkdir($dir) < 0 && !is_dir($dir)) {
            throw new Exception('Création du répertoire de documents impossible: '.$dir);
        }

        $baseName = trim((string) (($attachment['datas_fname'] ?? '') ?: ($attachment['name'] ?? '')));
        if ($baseName === '') {
            $baseName = 'odoo-invoice-attachment-'.$dolInvoiceRef.'.pdf';
        }

        $baseName = dol_sanitizeFileName($baseName);
        if ($baseName === '') {
            $baseName = 'odoo-invoice-attachment.pdf';
        }

        $content = base64_decode((string) $attachment['datas'], true);
        if ($content === false) {
            throw new Exception('Contenu de pièce jointe Odoo invalide (base64)');
        }

        $targetPath = $dir.'/'.$baseName;
        if (file_exists($targetPath)) {
            $targetPath = $dir.'/'.time().'_'.$baseName;
        }

        if (file_put_contents($targetPath, $content) === false) {
            throw new Exception('Écriture du fichier importé impossible: '.$targetPath);
        }

        $this->log('INFO', 'sync', 'invoice', (string) $dolInvoiceRef, 'Pièce jointe Odoo importée: '.basename($targetPath));
    }

    private function exportDolibarrInvoiceAttachmentToOdoo($dolInvoiceId, $dolInvoiceRef, $dolInvoiceType, $odooInvoiceId)
    {
        if (!$this->isInvoiceAttachmentExportEnabled()) {
            return;
        }

        $odooInvoiceId = (int) $odooInvoiceId;
        if ($odooInvoiceId <= 0) {
            return;
        }

        $dir = $this->getDolibarrInvoiceUploadDir($dolInvoiceType, (int) $dolInvoiceId, (string) $dolInvoiceRef);
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tiff', 'tif'];
        $mimeMap = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
        ];

        // Check if Odoo already has attachments for this invoice
        $existingAttachments = $this->odooExecuteKw(
            'ir.attachment',
            'search_read',
            [[['res_model', '=', 'account.move'], ['res_id', '=', $odooInvoiceId], ['type', '=', 'binary']]],
            ['fields' => ['name'], 'limit' => 100]
        );
        $existingNames = [];
        if (!empty($existingAttachments) && is_array($existingAttachments)) {
            foreach ($existingAttachments as $att) {
                $existingNames[] = strtolower(trim((string) ($att['name'] ?? '')));
            }
        }

        $files = scandir($dir);
        if (!is_array($files)) {
            return;
        }

        $uploaded = 0;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $dir.'/'.$file;
            if (!is_file($filePath)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions, true)) {
                continue;
            }

            // Skip if already uploaded
            if (in_array(strtolower($file), $existingNames, true)) {
                continue;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            $attachmentPayload = [
                'name' => $file,
                'type' => 'binary',
                'res_model' => 'account.move',
                'res_id' => $odooInvoiceId,
                'datas' => base64_encode($content),
                'mimetype' => $mimeMap[$ext] ?? 'application/octet-stream',
            ];

            $this->odooExecuteKw('ir.attachment', 'create', [$attachmentPayload]);
            $uploaded++;
        }

        if ($uploaded > 0) {
            $this->log('INFO', 'sync', 'invoice', (string) $dolInvoiceRef, $uploaded.' document(s) Dolibarr exporté(s) vers Odoo');
        }
    }

    private function createDolibarrInvoiceFromOdoo(array $odooInv, $requestedRef)
    {
        $user = $this->getExecutionUser();
        $partnerId = (int) ($odooInv['partner_id'][0] ?? 0);
        if ($partnerId <= 0) {
            throw new Exception('Partenaire Odoo absent pour la facture '.$requestedRef);
        }

        $socid = (int) $this->syncTiersOdooToDoli($partnerId);
        if ($socid <= 0) {
            $socid = $this->getDolibarrThirdpartyIdByOdooId($partnerId);
        }
        if ($socid <= 0) {
            throw new Exception('Tiers Dolibarr introuvable après synchronisation pour la facture '.$requestedRef);
        }

        $invoiceDate = !empty($odooInv['invoice_date']) ? strtotime((string) $odooInv['invoice_date']) : dol_now();
        $displayRef = $requestedRef ?: $this->getOdooInvoiceDisplayRef($odooInv);
        $proofRefs = $this->getOdooInvoiceMatchRefs($odooInv);
        $proofText = implode(' | ', $proofRefs);
        $line = $this->getDolibarrInvoicePlaceholderLine($displayRef, (float) ($odooInv['amount_total'] ?? 0), 'odoo');

        if (($odooInv['move_type'] ?? 'out_invoice') === 'in_invoice') {
            // Vérifier par ref_supplier avant de créer
            $safeDisplayRef = $this->db->escape($displayRef);
            $checkSql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn WHERE ref = '".$safeDisplayRef."' OR ref_supplier = '".$safeDisplayRef."' LIMIT 1";
            $checkRes = $this->db->query($checkSql);
            if ($checkRes && $this->db->fetch_object($checkRes)) {
                $this->log('INFO', 'sync', 'invoice', $displayRef, 'Facture fournisseur déjà présente dans Dolibarr — ignorée');
                return;
            }

            $invoice = new FactureFournisseur($this->db);
            $invoice->socid = $socid;
            $invoice->date = $invoiceDate;
            $invoice->ref_supplier = $displayRef;  // ref interne générée automatiquement par Dolibarr
            $invoice->note_private = 'Créée automatiquement depuis Odoo par SyncOdoo'.($proofText !== '' ? ' | Références Odoo: '.$proofText : '');

            $invoiceId = $invoice->create($user);
            if ($invoiceId <= 0) {
                throw new Exception('Erreur création facture fournisseur Dolibarr: '.($invoice->error ?: $this->db->lasterror()));
            }

            $lineRes = $invoice->addline($line['desc'], $line['unit_price'], $line['vat'], 0, 0, $line['qty']);
            if ($lineRes <= 0) {
                throw new Exception('Erreur ajout ligne facture fournisseur Dolibarr: '.($invoice->error ?: $this->db->lasterror()));
            }

            try {
                $this->importOdooInvoiceAttachmentToDolibarr($odooInv, (int) $invoiceId, (string) ($invoice->ref ?? $displayRef), 'supplier');
            } catch (Throwable $e) {
                $this->log('WARNING', 'sync', 'invoice', $displayRef, 'Import pièce jointe Odoo ignoré: '.$e->getMessage());
            }
        } else {
            $invoice = new Facture($this->db);
            $invoice->socid = $socid;
            $invoice->date = $invoiceDate;
            $invoice->ref_customer = $displayRef;
            $invoice->note_private = 'Créée automatiquement depuis Odoo par SyncOdoo'.($proofText !== '' ? ' | Références Odoo: '.$proofText : '');

            $invoiceId = $invoice->create($user);
            if ($invoiceId <= 0) {
                throw new Exception('Erreur création facture client Dolibarr: '.($invoice->error ?: $this->db->lasterror()));
            }

            $lineRes = $invoice->addline($line['desc'], $line['unit_price'], $line['qty'], $line['vat']);
            if ($lineRes <= 0) {
                throw new Exception('Erreur ajout ligne facture client Dolibarr: '.($invoice->error ?: $this->db->lasterror()));
            }

            try {
                $this->importOdooInvoiceAttachmentToDolibarr($odooInv, (int) $invoiceId, (string) ($invoice->ref ?? $displayRef), 'customer');
            } catch (Throwable $e) {
                $this->log('WARNING', 'sync', 'invoice', $displayRef, 'Import pièce jointe Odoo ignoré: '.$e->getMessage());
            }
        }

        $this->stats['factures_crees_doli']++;
        $this->log('INFO', 'sync', 'invoice', $displayRef, 'Création facture Dolibarr depuis Odoo');
    }

    private function createOdooInvoiceFromDolibarr($doli_inv)
    {
        $dolSocId = (int) ($doli_inv->socid ?? 0);
        if ($dolSocId <= 0) {
            throw new Exception('Tiers Dolibarr absent pour la facture '.$doli_inv->ref);
        }

        $remotePartnerId = (int) $this->syncTiersDoliToOdoo($dolSocId);

        if ($remotePartnerId <= 0) {
            $doliData = $this->getDolibarrTiers();
            $doliThirdparty = null;
            foreach ($doliData as $row) {
                if ((int) ($row['dol_id'] ?? 0) === $dolSocId) {
                    $doliThirdparty = $row;
                    break;
                }
            }

            $remotePartnerId = (int) ($doliThirdparty['odoo_id'] ?? 0);
        }
        if ($remotePartnerId <= 0) {
            throw new Exception('Partenaire Odoo introuvable après synchronisation pour la facture '.$doli_inv->ref);
        }

        $payload = [
            'move_type' => ($doli_inv->type ?? 'customer') === 'supplier' ? 'in_invoice' : 'out_invoice',
            'partner_id' => $remotePartnerId,
            'invoice_date' => $this->formatDateForOdoo($doli_inv->datef ?? ''),
            'ref' => $doli_inv->ref,
            'payment_reference' => $doli_inv->ref,
            'invoice_origin' => $doli_inv->ref,
            'invoice_line_ids' => [[0, 0, [
                'name' => 'Synchronisation Dolibarr '.$doli_inv->ref,
                'quantity' => 1,
                'price_unit' => (float) $doli_inv->total_ttc,
            ]]],
        ];

        $newOdooId = $this->odooCallPublic('account.move', 'create', [$payload]);
        if (!(int) $newOdooId) {
            throw new Exception('Création facture Odoo échouée pour '.$doli_inv->ref);
        }

        try {
            $dolInvoiceType = ($doli_inv->type ?? 'customer') === 'supplier' ? 'supplier' : 'customer';
            $this->exportDolibarrInvoiceAttachmentToOdoo((int) $doli_inv->rowid, (string) $doli_inv->ref, $dolInvoiceType, (int) $newOdooId);
        } catch (Throwable $e) {
            $this->log('WARNING', 'sync', 'invoice', $doli_inv->ref, 'Export pièce jointe vers Odoo ignoré: '.$e->getMessage());
        }

        $this->stats['factures_crees_odoo']++;
        $this->log('INFO', 'sync', 'invoice', $doli_inv->ref, 'Création facture Odoo depuis Dolibarr');
    }

    /* ============================================================
     *  ODOO CONNECTION
     * ============================================================ */

    public function testOdooConnectionDetailed()
    {
        $result = [
            'success' => false,
            'steps' => [],
            'error' => '',
        ];

        // Step 1: Validation des paramètres
        if (empty($this->odoo_url)) {
            $result['error'] = 'URL Odoo manquante';
            $result['steps'][] = ['step' => 'Params', 'status' => 'error', 'msg' => $result['error']];
            return $result;
        }
        if (empty($this->odoo_db)) {
            $result['error'] = 'Base Odoo manquante';
            $result['steps'][] = ['step' => 'Params', 'status' => 'error', 'msg' => $result['error']];
            return $result;
        }
        if (empty($this->odoo_user)) {
            $result['error'] = 'Utilisateur Odoo manquant';
            $result['steps'][] = ['step' => 'Params', 'status' => 'error', 'msg' => $result['error']];
            return $result;
        }
        $result['steps'][] = ['step' => 'Params', 'status' => 'ok', 'msg' => 'URL: '.$this->odoo_url.', Base: '.$this->odoo_db.', User: '.$this->odoo_user];

        // Step 2: Test HTTP
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->odoo_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            $result['error'] = 'Serveur inaccessible: '.$curlError;
            $result['steps'][] = ['step' => 'HTTP', 'status' => 'error', 'msg' => $result['error']];
            return $result;
        }

        if ($httpCode >= 400) {
            $result['error'] = 'HTTP '.$httpCode.' (serveur retourne une erreur)';
            $result['steps'][] = ['step' => 'HTTP', 'status' => 'error', 'msg' => $result['error']];
            return $result;
        }

        $result['steps'][] = ['step' => 'HTTP', 'status' => 'ok', 'msg' => 'HTTP '.$httpCode.' OK'];

        // Step 3: Auth
        try {
            $uid = $this->odooJsonRpc('common', 'authenticate', [
                $this->odoo_db,
                $this->odoo_user,
                $this->odoo_password,
                [],
            ]);

            if (!$uid || $uid === false || $uid === null || $uid === 0) {
                $result['error'] = 'Authentification refusée (base/user/pass incorrects?). UID reçu: '.var_export($uid, true);
                $result['steps'][] = ['step' => 'Auth', 'status' => 'error', 'msg' => 'Utilisateur/base/mot de passe non reconnus'];
                return $result;
            }

            $result['success'] = true;
            $result['steps'][] = ['step' => 'Auth', 'status' => 'ok', 'msg' => 'Connecté (UID='.$uid.')'];
            return $result;

        } catch (Throwable $e) {
            $result['error'] = 'Exception: '.$e->getMessage();
            $result['steps'][] = ['step' => 'Auth', 'status' => 'error', 'msg' => $result['error']];
            return $result;
        }
    }

    public function connectOdoo()
    {
        try {
            // Validation des paramètres de base
            if (empty($this->odoo_url) || empty($this->odoo_db) || empty($this->odoo_user)) {
                $this->lastError = 'Configuration incomplète Odoo (URL, base ou utilisateur manquant)';
                dol_syslog('SyncOdoo: '.$this->lastError, LOG_ERR);
                return false;
            }

            // Test de connectivité HTTP simple
            dol_syslog('SyncOdoo: Tentative de connexion à '.$this->odoo_url, LOG_DEBUG);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->odoo_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                $this->lastError = 'Serveur Odoo inaccessible: '.$curlError.' (vérifiez l\'URL '.$this->odoo_url.')';
                dol_syslog('SyncOdoo: '.$this->lastError, LOG_ERR);
                return false;
            }

            if ($httpCode >= 400) {
                $this->lastError = 'Serveur Odoo retourne HTTP '.$httpCode.' (vérifiez l\'URL '.$this->odoo_url.')';
                dol_syslog('SyncOdoo: '.$this->lastError, LOG_ERR);
                return false;
            }

            // Tentative d'authentification
            dol_syslog('SyncOdoo: Authentification utilisateur '.$this->odoo_user.' sur base '.$this->odoo_db, LOG_DEBUG);
            $uid = $this->odooJsonRpc('common', 'authenticate', [
                $this->odoo_db,
                $this->odoo_user,
                $this->odoo_password,
                [],
            ]);
            if (!$uid) {
                $this->lastError = 'Authentification Odoo échouée (vérifiez base="'.$this->odoo_db.'", utilisateur="'.$this->odoo_user.'", mot de passe)';
                dol_syslog('SyncOdoo: '.$this->lastError, LOG_ERR);
                return false;
            }

            $this->odoo_uid = $uid;
            $this->lastError = '';
            dol_syslog('SyncOdoo: Connexion Odoo établie (uid='.$uid.')', LOG_DEBUG);
            return true;

        } catch (Throwable $e) {
            $this->lastError = 'Erreur de connexion Odoo: '.$e->getMessage();
            dol_syslog('SyncOdoo: '.$this->lastError, LOG_ERR);
            return false;
        }
    }

    public function odooCallPublic($model, $method, $args)
    {
        if (empty($this->odoo_uid) && !$this->connectOdoo()) {
            throw new Exception('Connexion Odoo impossible: '.$this->lastError);
        }
        return $this->odooExecuteKw($model, $method, $args);
    }

    public function odooGetInvoiceState($id)
    {
        $result = $this->odooCallPublic('account.move', 'read', [[$id], ['state']]);
        return $result[0]['state'] ?? null;
    }

    public function doliGetPublic($endpoint)
    {
        return $this->doliCallPublic('GET', $endpoint, []);
    }

    public function doliPostPublic($endpoint, $data)
    {
        return $this->doliCallPublic('POST', $endpoint, $data);
    }

    public function doliPutPublic($endpoint, $data)
    {
        return $this->doliCallPublic('PUT', $endpoint, $data);
    }

    public function doliDeletePublic($endpoint)
    {
        return $this->doliCallPublic('DELETE', $endpoint, []);
    }

    private function doliCallPublic($method, $endpoint, $data)
    {
        global $conf;

        $url = rtrim($conf->global->MAIN_MODULE_API_REST, '/') . '/api/index.php/' . ltrim($endpoint, '/');
        $apiKey = $this->doli_api_key;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'DOLAPIKEY: ' . $apiKey
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            throw new Exception("Dolibarr API error {$httpCode}: {$response}");
        }
    }

    /* ============================================================
     *  TIERS — DOLIBARR
     * ============================================================ */

    private function getDolibarrTiers()
    {
        $sql = "SELECT s.rowid, s.nom as name, s.email, s.phone, s.zip, s.town, s.tva_intra,
                       s.client, s.fournisseur";
        if ($this->hasSocieteOdooIdColumn()) {
            $sql .= ", se.odoo_id";
        } else {
            $sql .= ", NULL as odoo_id";
        }
        $sql .= "
                FROM ".MAIN_DB_PREFIX."societe s";
        if ($this->hasSocieteOdooIdColumn()) {
            $sql .= "
                LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields se ON se.fk_object = s.rowid";
        }

        $resql = $this->db->query($sql);
        if (!$resql) return [];

        $list = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $key = $obj->odoo_id ?: 'dol-'.$obj->rowid;

            $list[$key] = [
                '_id'   => $obj->rowid,
                '_ref'  => $obj->name,
                'dol_id'   => $obj->rowid,
                'odoo_id'  => $obj->odoo_id,
                'name'     => $obj->name,
                'email'    => $obj->email,
                'phone'    => $obj->phone,
                'zip'      => $obj->zip,
                'town'     => $obj->town,
                'vat'      => $obj->tva_intra,
                'client'   => $obj->client,
                'fournisseur' => $obj->fournisseur,
                'type_flags' => $this->getDolibarrTypeFlags($obj->client, $obj->fournisseur),
            ];
        }
        return $list;
    }

    /* ============================================================
     *  TIERS — ODOO
     * ============================================================ */

    private function getOdooTiers()
    {
        $partners = $this->odooExecuteKw(
            'res.partner',
            'search_read',
            [['|', ['customer_rank', '>', 0], ['supplier_rank', '>', 0]]],
            ['fields' => ['id', 'name', 'email', 'phone', 'zip', 'city', 'vat', 'customer_rank', 'supplier_rank']]
        );

        $referencedPartnerIds = $this->getOdooReferencedPartnerIds();
        $knownPartnerIds = [];
        foreach ($partners as $partner) {
            $knownPartnerIds[(int) ($partner['id'] ?? 0)] = true;
        }

        $missingPartnerIds = [];
        foreach ($referencedPartnerIds as $partnerId) {
            if (empty($knownPartnerIds[(int) $partnerId])) {
                $missingPartnerIds[] = (int) $partnerId;
            }
        }

        if (!empty($missingPartnerIds)) {
            $partners = array_merge($partners, $this->fetchOdooPartnersByIds($missingPartnerIds));
        }

        $list = [];
        foreach ($partners as $p) {
            $list[$p['id']] = [
                '_id'  => $p['id'],
                '_ref' => $p['name'],
                'odoo_id' => $p['id'],
                'name'    => $p['name'],
                'email'   => $p['email'],
                'phone'   => $p['phone'],
                'zip'     => $p['zip'],
                'town'    => $p['city'],
                'vat'     => $p['vat'],
                'customer_rank' => $p['customer_rank'],
                'supplier_rank' => $p['supplier_rank'],
                'type_flags' => $this->getOdooTypeFlags($p['customer_rank'], $p['supplier_rank']),
            ];
        }
        return $list;
    }

    /* ============================================================
     *  COMPARAISON TIERS
     * ============================================================ */

    public function detecterDivergencesTiers()
    {
        $dol = $this->getDolibarrTiers();
        $odo = $this->getOdooTiers();

        $div = [
            'only_dolibarr' => [],
            'only_odoo'     => [],
            'differences'   => [],
        ];

        $matchedOdooIds = [];

        foreach ($dol as $d) {
            $matchedOdoo = null;
            $mappedOdooId = (int) ($d['odoo_id'] ?? 0);

            if ($mappedOdooId > 0 && isset($odo[$mappedOdooId])) {
                $matchedOdoo = $odo[$mappedOdooId];
            } else {
                $matchedOdoo = $this->findMatchingOdooThirdparty($d, $odo);
            }

            if (!$matchedOdoo) {
                $div['only_dolibarr'][] = $d;
                continue;
            }

            $matchedOdooIds[(int) ($matchedOdoo['odoo_id'] ?? 0)] = true;

            // Comparer les champs avec normalisation (Odoo retourne false pour vides,
            // formats téléphone/TVA/email peuvent différer sans être vraiment différents)
            $fieldsToCompare = [
                'name'  => '',
                'email' => 'email',
                'phone' => 'phone',
                'zip'   => '',
                'town'  => '',
                'vat'   => 'vat',
            ];
            $hasDiff    = false;
            $fieldDiffs = [];

            foreach ($fieldsToCompare as $field => $normType) {
                $dVal = $this->normalizeForComparison($d[$field] ?? '', $normType);
                $oVal = $this->normalizeForComparison($matchedOdoo[$field] ?? '', $normType);
                if ($dVal !== $oVal) {
                    $hasDiff     = true;
                    $fieldDiffs[] = $field;
                }
            }

            // Comparer types : uniquement client et fournisseur.
            // Le flag "prospect" est calculé automatiquement par Odoo (rank=0)
            // et différemment par Dolibarr → exclure de la comparaison.
            $dClient = !empty($d['type_flags']['client']);
            $dFourn  = !empty($d['type_flags']['fournisseur']);
            $oClient = !empty($matchedOdoo['type_flags']['client']);
            $oFourn  = !empty($matchedOdoo['type_flags']['fournisseur']);

            if ($dClient !== $oClient || $dFourn !== $oFourn) {
                $hasDiff      = true;
                $fieldDiffs[] = 'type_flags';
            }

            if ($hasDiff) {
                $div['differences'][] = [
                    'dolibarr'    => $d,
                    'odoo'        => $matchedOdoo,
                    'field_diffs' => $fieldDiffs,
                ];
            }
        }

        foreach ($odo as $id => $o) {
            if (!isset($matchedOdooIds[(int) $id])) {
                $div['only_odoo'][] = $o;
            }
        }

        return $div;
    }

    /* ============================================================
     *  FACTURES — DOLIBARR (Clients et Fournisseurs)
     * ============================================================ */

    private function getDolibarrInvoices()
    {
        $list = [];

        // Factures clients
        $sql = "SELECT f.rowid, f.ref, f.ref_client, f.total_ht, f.total_tva, f.total_ttc, f.fk_soc, f.datef, f.datec, f.fk_statut,
                   c.code as country_code,
                       s.nom as socid_name, 'customer' as type
                FROM ".MAIN_DB_PREFIX."facture f
            LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
            LEFT JOIN ".MAIN_DB_PREFIX."c_country c ON c.rowid = s.fk_pays";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $list[$obj->ref] = [
                    '_id'   => $obj->rowid,
                    '_ref'  => $obj->ref,
                    'ref'   => $obj->ref,
                    'type'  => $obj->type,
                    'total' => $obj->total_ttc,
                    'total_ht' => (float) $obj->total_ht,
                    'total_tva' => (float) $obj->total_tva,
                    'total_ttc' => $obj->total_ttc,
                    'socid' => $obj->fk_soc,
                    'socid_name' => $obj->socid_name,
                    'subject' => trim((string) ($obj->ref_client ?? '')),
                    'match_refs' => array_values(array_unique(array_filter([
                        trim((string) ($obj->ref ?? '')),
                        trim((string) ($obj->ref_client ?? '')),
                    ]))),
                    'country_code' => $obj->country_code,
                    'date'  => $obj->datef,
                    'date_creation' => $obj->datec,
                    'statut' => $obj->fk_statut,
                ];
            }
        }

        // Factures fournisseurs
        $sql = "SELECT f.rowid, f.ref, f.ref_supplier, f.total_ht, f.total_tva, f.total_ttc, f.fk_soc, f.datef, f.datec, f.fk_statut,
                   c.code as country_code,
                       s.nom as socid_name, 'supplier' as type
                FROM ".MAIN_DB_PREFIX."facture_fourn f
            LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
            LEFT JOIN ".MAIN_DB_PREFIX."c_country c ON c.rowid = s.fk_pays";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $list[$obj->ref] = [
                    '_id'   => $obj->rowid,
                    '_ref'  => $obj->ref,
                    'ref'   => $obj->ref,
                    'type'  => $obj->type,
                    'total' => $obj->total_ttc,
                    'total_ht' => (float) $obj->total_ht,
                    'total_tva' => (float) $obj->total_tva,
                    'total_ttc' => $obj->total_ttc,
                    'socid' => $obj->fk_soc,
                    'socid_name' => $obj->socid_name,
                    'subject' => trim((string) ($obj->ref_supplier ?? '')),
                    'match_refs' => array_values(array_unique(array_filter([
                        trim((string) ($obj->ref ?? '')),
                        trim((string) ($obj->ref_supplier ?? '')),
                    ]))),
                    'country_code' => $obj->country_code,
                    'date'  => $obj->datef,
                    'date_creation' => $obj->datec,
                    'statut' => $obj->fk_statut,
                ];
            }
        }

        return $list;
    }

    /* ============================================================
     *  FACTURES — ODOO (Clients et Fournisseurs)
     * ============================================================ */

    private function getOdooInvoices()
    {
        $list = [];

        // Factures clients (out_invoice)
        $customers = $this->odooExecuteKw(
            'account.move',
            'search_read',
            [[['move_type', '=', 'out_invoice'], ['state', '!=', 'cancel']]],
            ['fields' => ['id', 'name', 'ref', 'payment_reference', 'invoice_origin', 'amount_untaxed', 'amount_tax', 'amount_total', 'invoice_date', 'partner_id', 'state', 'move_type']]
        );

        $partnerIds = [];
        foreach ($customers as $inv) {
            $partnerId = (int) ($inv['partner_id'][0] ?? 0);
            if ($partnerId > 0) {
                $partnerIds[$partnerId] = $partnerId;
            }
        }

        // Factures fournisseurs (in_invoice)
        $suppliers = $this->odooExecuteKw(
            'account.move',
            'search_read',
            [[['move_type', '=', 'in_invoice'], ['state', '!=', 'cancel']]],
            ['fields' => ['id', 'name', 'ref', 'payment_reference', 'invoice_origin', 'amount_untaxed', 'amount_tax', 'amount_total', 'invoice_date', 'partner_id', 'state', 'move_type']]
        );

        foreach ($suppliers as $inv) {
            $partnerId = (int) ($inv['partner_id'][0] ?? 0);
            if ($partnerId > 0) {
                $partnerIds[$partnerId] = $partnerId;
            }
        }

        $partnerCountries = $this->getOdooPartnerCountryCodes(array_values($partnerIds));

        foreach ($customers as $inv) {
            $matchRefs = $this->getOdooInvoiceMatchRefs($inv);
            $displayRef = $this->getOdooInvoiceDisplayRef($inv, $matchRefs);
            $partnerId = (int) ($inv['partner_id'][0] ?? 0);
            $list['odoo-'.$inv['id']] = [
                '_id'   => $inv['id'],
                '_ref'  => $displayRef,
                'ref'   => $displayRef,
                'type'  => 'customer',
                'total' => $inv['amount_total'],
                'total_ht' => (float) ($inv['amount_untaxed'] ?? 0),
                'total_tva' => (float) ($inv['amount_tax'] ?? 0),
                'total_ttc' => (float) ($inv['amount_total'] ?? 0),
                'amount_total' => $inv['amount_total'],
                'date'  => $inv['invoice_date'],
                'invoice_date' => $inv['invoice_date'],
                'socid' => $inv['partner_id'][0] ?? null,
                'partner_id' => $inv['partner_id'] ?? [],
                'subject' => trim((string) (($inv['invoice_origin'] ?? '') ?: (($inv['payment_reference'] ?? '') ?: ($inv['name'] ?? '')))),
                'country_code' => $partnerCountries[$partnerId] ?? '',
                'state' => $inv['state'] ?? '',
                'move_type' => $inv['move_type'] ?? 'out_invoice',
                'name' => $inv['name'] ?? '',
                'odoo_ref' => $inv['ref'] ?? '',
                'payment_reference' => $inv['payment_reference'] ?? '',
                'invoice_origin' => $inv['invoice_origin'] ?? '',
                'match_refs' => $matchRefs,
            ];
        }

        foreach ($suppliers as $inv) {
            $matchRefs = $this->getOdooInvoiceMatchRefs($inv);
            $displayRef = $this->getOdooInvoiceDisplayRef($inv, $matchRefs);
            $partnerId = (int) ($inv['partner_id'][0] ?? 0);
            $list['odoo-'.$inv['id']] = [
                '_id'   => $inv['id'],
                '_ref'  => $displayRef,
                'ref'   => $displayRef,
                'type'  => 'supplier',
                'total' => $inv['amount_total'],
                'total_ht' => (float) ($inv['amount_untaxed'] ?? 0),
                'total_tva' => (float) ($inv['amount_tax'] ?? 0),
                'total_ttc' => (float) ($inv['amount_total'] ?? 0),
                'amount_total' => $inv['amount_total'],
                'date'  => $inv['invoice_date'],
                'invoice_date' => $inv['invoice_date'],
                'socid' => $inv['partner_id'][0] ?? null,
                'partner_id' => $inv['partner_id'] ?? [],
                'subject' => trim((string) (($inv['invoice_origin'] ?? '') ?: (($inv['payment_reference'] ?? '') ?: ($inv['name'] ?? '')))),
                'country_code' => $partnerCountries[$partnerId] ?? '',
                'state' => $inv['state'] ?? '',
                'move_type' => $inv['move_type'] ?? 'in_invoice',
                'name' => $inv['name'] ?? '',
                'odoo_ref' => $inv['ref'] ?? '',
                'payment_reference' => $inv['payment_reference'] ?? '',
                'invoice_origin' => $inv['invoice_origin'] ?? '',
                'match_refs' => $matchRefs,
            ];
        }

        return $list;
    }

    private function getOdooPartnerCountryCodes(array $partnerIds)
    {
        $result = [];
        $partnerIds = array_values(array_unique(array_filter(array_map('intval', $partnerIds))));

        if (empty($partnerIds)) {
            return $result;
        }

        $partners = $this->odooExecuteKw(
            'res.partner',
            'search_read',
            [[['id', 'in', $partnerIds]]],
            ['fields' => ['id', 'country_id']]
        );

        $countryIds = [];
        foreach ($partners as $partner) {
            $partnerId = (int) ($partner['id'] ?? 0);
            $countryId = (int) ($partner['country_id'][0] ?? 0);
            if ($partnerId > 0) {
                $result[$partnerId] = '';
            }
            if ($countryId > 0) {
                $countryIds[$countryId] = $countryId;
            }
        }

        $countryCodesById = [];
        if (!empty($countryIds)) {
            $countries = $this->odooExecuteKw(
                'res.country',
                'search_read',
                [[['id', 'in', array_values($countryIds)]]],
                ['fields' => ['id', 'code']]
            );
            foreach ($countries as $country) {
                $countryId = (int) ($country['id'] ?? 0);
                if ($countryId > 0) {
                    $countryCodesById[$countryId] = strtoupper(trim((string) ($country['code'] ?? '')));
                }
            }
        }

        foreach ($partners as $partner) {
            $partnerId = (int) ($partner['id'] ?? 0);
            $countryId = (int) ($partner['country_id'][0] ?? 0);
            if ($partnerId > 0 && $countryId > 0) {
                $result[$partnerId] = $countryCodesById[$countryId] ?? '';
            }
        }

        return $result;
    }

    private function getOdooInvoiceMatchRefs(array $invoice)
    {
        $refs = [];
        foreach (['name', 'ref', 'payment_reference', 'invoice_origin'] as $field) {
            $value = trim((string) ($invoice[$field] ?? ''));
            if ($value !== '' && $value !== '/') {
                $refs[$value] = $value;
            }
        }

        return array_values($refs);
    }

    private function getOdooInvoiceDisplayRef(array $invoice, array $matchRefs = [])
    {
        if (empty($matchRefs)) {
            $matchRefs = $this->getOdooInvoiceMatchRefs($invoice);
        }

        if (!empty($matchRefs)) {
            return $matchRefs[0];
        }

        return 'Odoo #'.((int) ($invoice['id'] ?? 0));
    }

    private function findOdooInvoiceByRef($ref, $odooId = 0)
    {
        $odooId = (int) $odooId;
        if ($odooId > 0) {
            $invoices = $this->odooExecuteKw(
                'account.move',
                'search_read',
                [[['id', '=', $odooId], ['move_type', 'in', ['out_invoice', 'in_invoice']]]],
                ['fields' => ['id', 'name', 'ref', 'payment_reference', 'invoice_origin', 'amount_untaxed', 'amount_tax', 'amount_total', 'invoice_date', 'partner_id', 'state', 'move_type'], 'limit' => 1]
            );

            if (!empty($invoices)) {
                return $invoices[0];
            }
        }

        if (preg_match('/^Odoo\s+#(\d+)$/i', (string) $ref, $matches)) {
            $fallbackId = (int) $matches[1];
            if ($fallbackId > 0) {
                return $this->findOdooInvoiceByRef('', $fallbackId);
            }
        }

        $fields = ['name', 'ref', 'payment_reference', 'invoice_origin'];
        foreach ($fields as $field) {
            $invoices = $this->odooExecuteKw(
                'account.move',
                'search_read',
                [[[$field, '=', $ref], ['move_type', 'in', ['out_invoice', 'in_invoice']], ['state', '!=', 'cancel']]],
                ['fields' => ['id', 'name', 'ref', 'payment_reference', 'invoice_origin', 'amount_untaxed', 'amount_tax', 'amount_total', 'invoice_date', 'partner_id', 'state', 'move_type'], 'limit' => 1]
            );

            if (!empty($invoices)) {
                return $invoices[0];
            }
        }

        return null;
    }

    /* ============================================================
     *  COMPARAISON FACTURES
     * ============================================================ */

    public function detecterDivergencesFactures()
    {
        $dol = $this->getDolibarrInvoices();
        $odo = $this->getOdooInvoices();
        $odoByRef = [];
        $dolByRef = [];

        foreach ($odo as $odooInvoice) {
            $invoiceType = (string) ($odooInvoice['type'] ?? '');
            foreach (($odooInvoice['match_refs'] ?? []) as $matchRef) {
                $normalizedRef = $this->normalizeLookupValue($matchRef);
                if ($normalizedRef === '') {
                    continue;
                }
                $typedKey = $invoiceType.'::'.$normalizedRef;
                if (!isset($odoByRef[$typedKey])) {
                    $odoByRef[$typedKey] = $odooInvoice;
                }
            }
        }

        foreach ($dol as $dolInvoice) {
            $invoiceType = (string) ($dolInvoice['type'] ?? '');
            foreach (($dolInvoice['match_refs'] ?? []) as $matchRef) {
                $normalizedRef = $this->normalizeLookupValue($matchRef);
                if ($normalizedRef === '') {
                    continue;
                }
                $typedKey = $invoiceType.'::'.$normalizedRef;
                if (!isset($dolByRef[$typedKey])) {
                    $dolByRef[$typedKey] = $dolInvoice;
                }
            }
        }

        $div = [
            'only_dolibarr' => [],
            'only_odoo'     => [],
            'differences'   => [],
        ];

        $matchedOdooIds = [];

        foreach ($dol as $d) {
            $matchedOdoo = null;
            $invoiceType = (string) ($d['type'] ?? '');
            foreach (($d['match_refs'] ?? []) as $matchRef) {
                $normalizedRef = $this->normalizeLookupValue($matchRef);
                if ($normalizedRef === '') {
                    continue;
                }
                $typedKey = $invoiceType.'::'.$normalizedRef;
                if (isset($odoByRef[$typedKey])) {
                    $matchedOdoo = $odoByRef[$typedKey];
                    break;
                }
            }

            if (!$matchedOdoo) {
                $div['only_dolibarr'][] = $d;
            } else {
                $matchedOdooIds[(int) ($matchedOdoo['_id'] ?? 0)] = true;
            }
        }

        foreach ($odo as $o) {
            $odooId = (int) ($o['_id'] ?? 0);
            $matchedDol = null;
            $invoiceType = (string) ($o['type'] ?? '');
            foreach (($o['match_refs'] ?? []) as $matchRef) {
                $normalizedRef = $this->normalizeLookupValue($matchRef);
                if ($normalizedRef === '') {
                    continue;
                }
                $typedKey = $invoiceType.'::'.$normalizedRef;
                if (isset($dolByRef[$typedKey])) {
                    $matchedDol = $dolByRef[$typedKey];
                    break;
                }
            }

            if (!isset($matchedOdooIds[$odooId]) && !$matchedDol) {
                $div['only_odoo'][] = $o;
            }
        }

        foreach ($dol as $d) {
            $o = null;
            $invoiceType = (string) ($d['type'] ?? '');
            foreach (($d['match_refs'] ?? []) as $matchRef) {
                $normalizedRef = $this->normalizeLookupValue($matchRef);
                if ($normalizedRef === '') {
                    continue;
                }
                $typedKey = $invoiceType.'::'.$normalizedRef;
                if (isset($odoByRef[$typedKey])) {
                    $o = $odoByRef[$typedKey];
                    break;
                }
            }

            if ($o !== null) {
                $ref = (string) ($d['ref'] ?? $d['_ref'] ?? '');

                $fieldDiffs = [];

                if (abs(((float) ($d['total_ht'] ?? 0)) - ((float) ($o['total_ht'] ?? 0))) > 0.01) {
                    $fieldDiffs[] = 'total_ht';
                }
                if (abs(((float) ($d['total_tva'] ?? 0)) - ((float) ($o['total_tva'] ?? 0))) > 0.01) {
                    $fieldDiffs[] = 'total_tva';
                }
                if (abs(((float) ($d['total_ttc'] ?? 0)) - ((float) ($o['total_ttc'] ?? 0))) > 0.01) {
                    $fieldDiffs[] = 'total_ttc';
                }

                if (!empty($fieldDiffs)) {
                        $odooId = (int) ($o['_id'] ?? 0);
                        // Mark this Odoo invoice as matched (even if divergent) to prevent appearing in both only_odoo and differences
                        $matchedOdooIds[$odooId] = true;
                    
                        $div['differences'][] = [
                        'ref'      => $ref,
                        'dolibarr' => $d,
                        'odoo'     => $o,
                        'field_diffs' => $fieldDiffs,
                    ];
                }
            }
        }

        $div['vat_checks'] = $this->buildVatChecksFromInvoices($dol, $odo);

        return $div;
    }

    public function analyserDivergences()
    {
        if (empty($this->odoo_uid) && !$this->connectOdoo()) {
            throw new Exception("Connexion Odoo impossible");
        }

        $tiers = $this->detecterDivergencesTiers();
        $factures = $this->detecterDivergencesFactures();

        $tiersOnlyOdoo = [];
        foreach ($tiers['only_odoo'] as $row) {
            $row['city'] = $row['town'] ?? '';
            $tiersOnlyOdoo[] = $row;
        }

        return [
            'tiers_only_doli' => $tiers['only_dolibarr'],
            'tiers_only_odoo' => $tiersOnlyOdoo,
            'invoices_only_doli' => $factures['only_dolibarr'],
            'invoices_only_odoo' => $factures['only_odoo'],
            'tiers' => [
                'differences' => $tiers['differences'],
            ],
            'factures' => [
                'differences' => $factures['differences'],
            ],
            'vat_checks' => $factures['vat_checks'] ?? [
                'missing_country' => [],
                'pending_rates' => [],
            ],
        ];
    }

    public function runAll()
    {
        $this->stats['erreurs'] = 0;

        if (!$this->connectOdoo()) {
            $this->stats['erreurs']++;
            $message = 'Connexion Odoo impossible';
            if (!empty($this->lastError)) {
                $message .= ' - '.$this->lastError;
            }
            $this->log('ERROR', 'sync', 'system', 'manual', $message);
            return false;
        }

        try {
            $divergences = $this->analyserDivergences();

            foreach ($divergences['tiers_only_odoo'] as $row) {
                try {
                    $this->syncTiersOdooToDoli((int) ($row['_id'] ?? 0));
                    $this->stats['tiers_crees_doli']++;
                } catch (Throwable $e) {
                    $this->stats['erreurs']++;
                    $this->log('ERROR', 'sync', 'thirdparty', (string) ($row['_ref'] ?? $row['name'] ?? $row['_id'] ?? ''), $e->getMessage());
                }
            }

            foreach ($divergences['tiers_only_doli'] as $row) {
                try {
                    $this->syncTiersDoliToOdoo((int) ($row['dol_id'] ?? $row['_id'] ?? 0));
                    $this->stats['tiers_crees_odoo']++;
                } catch (Throwable $e) {
                    $this->stats['erreurs']++;
                    $this->log('ERROR', 'sync', 'thirdparty', (string) ($row['_ref'] ?? $row['name'] ?? $row['_id'] ?? ''), $e->getMessage());
                }
            }

            foreach ($divergences['tiers']['differences'] as $diff) {
                try {
                    $this->syncThirdpartyPair($diff['dolibarr'], $diff['odoo']);
                } catch (Throwable $e) {
                    $this->stats['erreurs']++;
                    $this->log('ERROR', 'sync', 'thirdparty', (string) ($diff['dolibarr']['name'] ?? ''), $e->getMessage());
                }
            }

            foreach ($divergences['invoices_only_odoo'] as $row) {
                try {
                    $this->syncFacturesOdooToDoli(
                        (string) ($row['ref'] ?? $row['_ref'] ?? ''),
                        (int) ($row['_id'] ?? $row['id'] ?? 0)
                    );
                } catch (Throwable $e) {
                    $this->stats['erreurs']++;
                    $this->log('ERROR', 'sync', 'invoice', (string) ($row['ref'] ?? $row['_ref'] ?? $row['_id'] ?? ''), $e->getMessage());
                }
            }

            foreach ($divergences['invoices_only_doli'] as $row) {
                try {
                    $this->syncFacturesDoliToOdoo((string) ($row['ref'] ?? $row['_ref'] ?? ''));
                } catch (Throwable $e) {
                    $this->stats['erreurs']++;
                    $this->log('ERROR', 'sync', 'invoice', (string) ($row['ref'] ?? $row['_ref'] ?? $row['_id'] ?? ''), $e->getMessage());
                }
            }

            try {
                $this->syncBankTransactions();
            } catch (Throwable $e) {
                $this->stats['erreurs']++;
                $this->log('ERROR', 'sync', 'bank', 'transactions', $e->getMessage());
            }

            $this->log('INFO', 'sync', 'system', 'manual', 'Synchronisation automatique exécutée.');
            return ($this->stats['erreurs'] === 0);
        } catch (Throwable $e) {
            $this->stats['erreurs']++;
            $this->log('ERROR', 'sync', 'system', 'manual', 'Erreur runAll: '.get_class($e).': '.$e->getMessage());
            return false;
        }
    }

    /* ============================================================
     *  MÉTHODE GLOBALE
     * ============================================================ */

    public function log($level, $direction, $entity_type, $entity_ref, $message)
    {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."syncodoo_log (datec, level, direction, entity_type, entity_ref, message)
            VALUES (NOW(), '".$this->db->escape($level)."', '".$this->db->escape($direction)."', '".$this->db->escape($entity_type)."', '".$this->db->escape($entity_ref)."', '".$this->db->escape($message)."')";
        $this->db->query($sql);
    }

    public function getLogs($limit = 100)
    {
        $limit = max(1, (int) $limit);
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."syncodoo_log ORDER BY datec DESC LIMIT ".$limit;
        $resql = $this->db->query($sql);
        $logs = [];
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $logs[] = $obj;
            }
        }
        return $logs;
    }

    public function purgeLogs($days = 30)
    {
        $days = max(1, (int) $days);
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."syncodoo_log WHERE datec < DATE_SUB(NOW(), INTERVAL ".$days." DAY)";
        $this->db->query($sql);
    }

    public function clearLogs()
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."syncodoo_log";
        $this->db->query($sql);
    }

    public function clearDivergenceLogs()
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."syncodoo_log WHERE direction = 'divergence'";
        $this->db->query($sql);
    }

    private function resolveOdooBankJournal()
    {
        $selector = $this->getConfiguredOdooBankJournalSelector();
        if ($selector === '') {
            return null;
        }

        $fields = ['id', 'name', 'code', 'currency_id', 'bank_account_id'];

        if (ctype_digit($selector)) {
            $rows = $this->odooExecuteKw('account.journal', 'search_read', [[['id', '=', (int) $selector]]], ['fields' => $fields, 'limit' => 1]);
            return !empty($rows[0]) ? $rows[0] : null;
        }

        $rows = $this->odooExecuteKw('account.journal', 'search_read', [[['code', '=', $selector]]], ['fields' => $fields, 'limit' => 1]);
        if (!empty($rows[0])) {
            return $rows[0];
        }

        $rows = $this->odooExecuteKw('account.journal', 'search_read', [[['name', '=', $selector]]], ['fields' => $fields, 'limit' => 1]);
        if (!empty($rows[0])) {
            return $rows[0];
        }

        return null;
    }

    private function serializeBankTransactionDetails($details)
    {
        if (is_string($details)) {
            return trim($details);
        }

        if (is_array($details)) {
            $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : '';
        }

        if ($details === null || $details === false) {
            return '';
        }

        return trim((string) $details);
    }

    private function buildOdooBankTransactionLabel(array $row)
    {
        $parts = [];
        foreach (['payment_ref', 'ref', 'name'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        $partnerLabel = trim((string) ($row['partner_id'][1] ?? ''));
        if ($partnerLabel !== '' && !in_array($partnerLabel, $parts, true)) {
            $parts[] = $partnerLabel;
        }

        $label = trim(implode(' | ', $parts));
        if ($label === '') {
            $label = 'Transaction Odoo #'.((int) ($row['id'] ?? 0));
        }

        return dol_string_nohtmltag(substr($label, 0, 250));
    }

    private function normalizeOdooBankTransaction(array $row)
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'date' => trim((string) ($row['date'] ?? '')),
            'amount' => (float) ($row['amount'] ?? 0),
            'label' => $this->buildOdooBankTransactionLabel($row),
            'details' => $this->serializeBankTransactionDetails($row['transaction_details'] ?? ''),
            'payment_ref' => trim((string) ($row['payment_ref'] ?? '')),
            'partner_name' => trim((string) ($row['partner_id'][1] ?? '')),
            'journal_id' => (int) ($row['journal_id'][0] ?? 0),
            'journal_label' => trim((string) ($row['journal_id'][1] ?? '')),
            'statement_name' => trim((string) ($row['statement_id'][1] ?? '')),
            'is_reconciled' => !empty($row['is_reconciled']),
            'write_date' => trim((string) ($row['write_date'] ?? '')),
        ];
    }

    private function getOdooBankTransactions($journalId, $sinceDate = '')
    {
        $journalId = (int) $journalId;
        if ($journalId <= 0) {
            return [];
        }

        $domain = [['journal_id', '=', $journalId]];
        if ($sinceDate !== '') {
            $domain[] = ['date', '>=', $sinceDate];
        }

        $fields = ['id', 'date', 'amount', 'payment_ref', 'ref', 'name', 'partner_id', 'journal_id', 'statement_id', 'transaction_details', 'is_reconciled', 'write_date'];
        $rows = $this->odooSearchReadAll('account.bank.statement.line', $domain, $fields, ['order' => 'date asc, id asc']);

        $transactions = [];
        foreach ($rows as $row) {
            $tx = $this->normalizeOdooBankTransaction($row);
            if ($tx['id'] > 0 && $tx['date'] !== '') {
                $transactions[] = $tx;
            }
        }

        return $transactions;
    }

    private function getDolibarrBankTransactions($accountId, $sinceDate = '')
    {
        $accountId = (int) $accountId;
        if ($accountId <= 0) {
            return [];
        }

        $sql = "SELECT rowid, dateo, datev, amount, label, num_chq, num_releve, rappro, note, fk_account
                FROM ".MAIN_DB_PREFIX."bank
                WHERE fk_account = ".$accountId;
        if ($sinceDate !== '') {
            $sql .= " AND dateo >= '".$this->db->escape($sinceDate)."'";
        }
        $sql .= " ORDER BY dateo ASC, rowid ASC";

        $resql = $this->db->query($sql);
        $rows = [];
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = [
                    'id' => (int) ($obj->rowid ?? 0),
                    'date' => !empty($obj->dateo) ? date('Y-m-d', $this->db->jdate($obj->dateo)) : '',
                    'value_date' => !empty($obj->datev) ? date('Y-m-d', $this->db->jdate($obj->datev)) : '',
                    'amount' => (float) ($obj->amount ?? 0),
                    'label' => trim((string) ($obj->label ?? '')),
                    'num_chq' => trim((string) ($obj->num_chq ?? '')),
                    'num_releve' => trim((string) ($obj->num_releve ?? '')),
                    'rappro' => (int) ($obj->rappro ?? 0),
                    'note' => trim((string) ($obj->note ?? '')),
                    'fk_account' => (int) ($obj->fk_account ?? 0),
                ];
            }
        }

        return $rows;
    }

    private function determineDolibarrBankOperationCode($amount)
    {
        return ((float) $amount >= 0) ? 'VIR' : 'PRE';
    }

    private function updateDolibarrBankLineDetails($bankLineId, $label, $note)
    {
        $bankLineId = (int) $bankLineId;
        if ($bankLineId <= 0) {
            return;
        }

        $sql = "UPDATE ".MAIN_DB_PREFIX."bank SET label = '".$this->db->escape(substr((string) $label, 0, 255))."', note = ";
        $sql .= ($note !== '' ? "'".$this->db->escape($note)."'" : 'NULL');
        $sql .= " WHERE rowid = ".$bankLineId;
        $this->db->query($sql);
    }

    private function createDolibarrBankTransactionFromOdoo(array $transaction, Account $account)
    {
        $user = $this->getExecutionUser();
        $date = strtotime((string) $transaction['date']);
        if ($date === false) {
            throw new Exception('Date transaction Odoo invalide');
        }

        $numPayment = 'odoo:'.((int) $transaction['id']);
        $label = (string) ($transaction['label'] ?? '');
        $note = trim((string) ($transaction['details'] ?? ''));
        if (!empty($transaction['payment_ref'])) {
            $note = trim($note.($note !== '' ? "\n" : '').'Référence Odoo: '.$transaction['payment_ref']);
        }

        $lineId = $account->addline(
            $date,
            $this->determineDolibarrBankOperationCode($transaction['amount'] ?? 0),
            $label,
            (float) ($transaction['amount'] ?? 0),
            $numPayment,
            0,
            $user,
            (string) ($transaction['partner_name'] ?? ''),
            '',
            '',
            $date,
            (string) ($transaction['statement_name'] ?? '')
        );

        if ($lineId <= 0) {
            throw new Exception('Création de la ligne bancaire Dolibarr impossible'.(!empty($account->error) ? ': '.$account->error : ''));
        }

        $this->updateDolibarrBankLineDetails($lineId, $label, $note);

        return $lineId;
    }

    private function updateDolibarrBankTransactionFromOdoo($bankLineId, array $transaction)
    {
        $bankLine = new AccountLine($this->db);
        if ($bankLine->fetch((int) $bankLineId) <= 0) {
            return false;
        }

        if ((int) $bankLine->rappro === 1) {
            $this->log('WARNING', 'sync', 'bank', (string) $transaction['id'], 'Ligne bancaire Dolibarr rapprochée, mise à jour ignorée.');
            return false;
        }

        $targetDate = strtotime((string) $transaction['date']);
        if ($targetDate === false) {
            throw new Exception('Date transaction Odoo invalide');
        }

        $currentDate = !empty($bankLine->dateo) ? date('Y-m-d', (int) $bankLine->dateo) : '';
        $needsUpdate = (abs(((float) $bankLine->amount) - ((float) $transaction['amount'])) > 0.00001)
            || ($currentDate !== (string) $transaction['date']);

        if ($needsUpdate) {
            $bankLine->rowid = $bankLine->id;
            $bankLine->amount = (float) $transaction['amount'];
            $bankLine->dateo = $targetDate;
            $bankLine->datev = $targetDate;
            if ($bankLine->update($this->getExecutionUser()) <= 0) {
                throw new Exception('Mise à jour ligne bancaire Dolibarr impossible: '.$bankLine->error);
            }
        }

        $note = trim((string) ($transaction['details'] ?? ''));
        if (!empty($transaction['payment_ref'])) {
            $note = trim($note.($note !== '' ? "\n" : '').'Référence Odoo: '.$transaction['payment_ref']);
        }
        $this->updateDolibarrBankLineDetails((int) $bankLineId, (string) ($transaction['label'] ?? ''), $note);

        return $needsUpdate;
    }

    private function buildOdooBankPayloadFromDolibarr(array $transaction, $journalId)
    {
        return [
            'journal_id' => (int) $journalId,
            'date' => (string) ($transaction['date'] ?? ''),
            'payment_ref' => substr((string) ($transaction['label'] ?? ''), 0, 255),
            'amount' => (float) ($transaction['amount'] ?? 0),
        ];
    }

    private function getOdooBankTransactionById($transactionId)
    {
        $transactionId = (int) $transactionId;
        if ($transactionId <= 0) {
            return null;
        }

        $rows = $this->odooCallPublic('account.bank.statement.line', 'read', [[$transactionId], ['id', 'date', 'amount', 'payment_ref', 'is_reconciled', 'write_date']]);
        if (empty($rows[0])) {
            return null;
        }

        return $rows[0];
    }

    private function createOdooBankTransactionFromDolibarr(array $transaction, $journalId)
    {
        $payload = $this->buildOdooBankPayloadFromDolibarr($transaction, $journalId);
        $newId = (int) $this->odooCallPublic('account.bank.statement.line', 'create', [$payload]);
        if ($newId <= 0) {
            throw new Exception('Création de la transaction bancaire Odoo impossible');
        }

        return $newId;
    }

    private function updateOdooBankTransactionFromDolibarr($transactionId, array $transaction, $journalId)
    {
        $current = $this->getOdooBankTransactionById($transactionId);
        if (empty($current)) {
            return false;
        }

        if (!empty($current['is_reconciled'])) {
            $this->log('WARNING', 'sync', 'bank', (string) $transactionId, 'Transaction bancaire Odoo rapprochée, mise à jour ignorée.');
            return false;
        }

        $payload = $this->buildOdooBankPayloadFromDolibarr($transaction, $journalId);
        $changes = [];
        if (abs(((float) ($current['amount'] ?? 0)) - ((float) $payload['amount'])) > 0.00001) {
            $changes['amount'] = $payload['amount'];
        }
        if ((string) ($current['date'] ?? '') !== (string) $payload['date']) {
            $changes['date'] = $payload['date'];
        }
        if ((string) ($current['payment_ref'] ?? '') !== (string) $payload['payment_ref']) {
            $changes['payment_ref'] = $payload['payment_ref'];
        }
        if (empty($changes)) {
            return false;
        }

        $this->odooCallPublic('account.bank.statement.line', 'write', [[(int) $transactionId], $changes]);
        return true;
    }

    public function syncBankTransactions()
    {
        if (!$this->isBankSyncEnabled()) {
            return true;
        }

        if (empty($this->odoo_uid) && !$this->connectOdoo()) {
            throw new Exception('Connexion Odoo impossible pour la synchronisation bancaire: '.$this->lastError);
        }

        $accountId = $this->getConfiguredDolibarrBankAccountId();
        $account = $this->getDolibarrBankAccountObject($accountId);
        if (!$account) {
            throw new Exception('Compte bancaire Dolibarr non configuré ou introuvable pour la synchronisation bancaire');
        }

        $journal = $this->resolveOdooBankJournal();
        if (empty($journal['id'])) {
            throw new Exception('Journal bancaire Odoo non configuré ou introuvable');
        }

        $sinceDate = $this->getConfiguredBankSyncStartDate();
        $direction = $this->getBankSyncDirection();

        if ($direction === 'odoo_to_dolibarr' || $direction === 'both') {
            $transactions = $this->getOdooBankTransactions((int) $journal['id'], $sinceDate);
            foreach ($transactions as $transaction) {
                if (abs((float) $transaction['amount']) < 0.00001) {
                    continue;
                }

                $mapping = $this->getBankSyncMappingByOdooId((int) $transaction['id']);
                $bankLineId = (int) ($mapping['dolibarr_bank_line_id'] ?? 0);

                if ($bankLineId > 0) {
                    $updated = $this->updateDolibarrBankTransactionFromOdoo($bankLineId, $transaction);
                    if ($updated) {
                        $this->stats['transactions_bancaires_maj_doli']++;
                        $this->log('INFO', 'sync', 'bank', (string) $transaction['id'], 'Transaction bancaire mise à jour dans Dolibarr.');
                    }
                    $this->saveBankSyncMapping((int) $transaction['id'], $bankLineId, $accountId, (int) $journal['id'], 'odoo_to_dolibarr', (string) ($transaction['write_date'] ?? ''));
                    continue;
                }

                $newBankLineId = $this->createDolibarrBankTransactionFromOdoo($transaction, $account);
                $this->saveBankSyncMapping((int) $transaction['id'], $newBankLineId, $accountId, (int) $journal['id'], 'odoo_to_dolibarr', (string) ($transaction['write_date'] ?? ''));
                $this->stats['transactions_bancaires_creees_doli']++;
                $this->log('INFO', 'sync', 'bank', (string) $transaction['id'], 'Transaction bancaire créée dans Dolibarr.');
            }
        }

        if ($direction === 'dolibarr_to_odoo' || $direction === 'both') {
            $transactions = $this->getDolibarrBankTransactions($accountId, $sinceDate);
            foreach ($transactions as $transaction) {
                if (strpos((string) ($transaction['num_chq'] ?? ''), 'odoo:') === 0) {
                    continue;
                }

                $mapping = $this->getBankSyncMappingByDolibarrLineId((int) $transaction['id']);
                $odooTransactionId = (int) ($mapping['odoo_transaction_id'] ?? 0);

                if ($odooTransactionId > 0) {
                    $updated = $this->updateOdooBankTransactionFromDolibarr($odooTransactionId, $transaction, (int) $journal['id']);
                    if ($updated) {
                        $this->stats['transactions_bancaires_maj_odoo']++;
                        $this->log('INFO', 'sync', 'bank', (string) $transaction['id'], 'Transaction bancaire mise à jour dans Odoo.');
                    }
                    $current = $this->getOdooBankTransactionById($odooTransactionId);
                    $this->saveBankSyncMapping($odooTransactionId, (int) $transaction['id'], $accountId, (int) $journal['id'], 'dolibarr_to_odoo', (string) ($current['write_date'] ?? ''));
                    continue;
                }

                $newOdooTransactionId = $this->createOdooBankTransactionFromDolibarr($transaction, (int) $journal['id']);
                $current = $this->getOdooBankTransactionById($newOdooTransactionId);
                $this->saveBankSyncMapping($newOdooTransactionId, (int) $transaction['id'], $accountId, (int) $journal['id'], 'dolibarr_to_odoo', (string) ($current['write_date'] ?? ''));
                $this->stats['transactions_bancaires_creees_odoo']++;
                $this->log('INFO', 'sync', 'bank', (string) $transaction['id'], 'Transaction bancaire créée dans Odoo.');
            }
        }

        return true;
    }

    private function ensureVatRateTableExists()
    {
        $sql = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_vat_rate (
            rowid INT NOT NULL AUTO_INCREMENT,
            country_code VARCHAR(10) NOT NULL,
            vat_rate DECIMAL(8,4) NOT NULL,
            confirmed TINYINT NOT NULL DEFAULT 0,
            source VARCHAR(20) NOT NULL DEFAULT '',
            first_ref VARCHAR(180) NOT NULL DEFAULT '',
            last_ref VARCHAR(180) NOT NULL DEFAULT '',
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY (rowid),
            UNIQUE KEY uk_syncodoo_vat_country_rate (country_code, vat_rate),
            INDEX idx_syncodoo_vat_confirmed (confirmed),
            INDEX idx_syncodoo_vat_country (country_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->db->query($sql);

        // Add correct_rate column if not yet present
        $checkCol = $this->db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."syncodoo_vat_rate LIKE 'correct_rate'");
        if ($checkCol && $this->db->num_rows($checkCol) == 0) {
            $sqlAddCol = "ALTER TABLE ".MAIN_DB_PREFIX."syncodoo_vat_rate ADD COLUMN correct_rate DECIMAL(8,4) NULL DEFAULT NULL";
            $this->db->query($sqlAddCol);
        }
    }

    private function normalizeCountryCode($code)
    {
        $code = strtoupper(trim((string) $code));
        return $code;
    }

    public function getCountryOptions()
    {
        $options = [];

        $sql = "SELECT rowid, code, label\n";
        $sql .= "FROM ".MAIN_DB_PREFIX."c_country\n";
        $sql .= "WHERE active = 1 AND code IS NOT NULL AND code <> ''\n";
        $sql .= "ORDER BY label ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $options;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $code = strtoupper(trim((string) ($obj->code ?? '')));
            if ($code === '') {
                continue;
            }

            $options[] = [
                'id' => (int) ($obj->rowid ?? 0),
                'code' => $code,
                'label' => trim((string) ($obj->label ?? $code)),
            ];
        }

        return $options;
    }

    private function getDolibarrCountryIdByCode($countryCode)
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if ($countryCode === '') {
            return 0;
        }

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = '".$this->db->escape($countryCode)."' LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return (int) ($obj->rowid ?? 0);
            }
        }

        return 0;
    }

    private function getOdooCountryIdByCode($countryCode)
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if ($countryCode === '') {
            return 0;
        }

        $rows = $this->odooExecuteKw(
            'res.country',
            'search_read',
            [[['code', '=', $countryCode]]],
            ['fields' => ['id', 'code'], 'limit' => 1]
        );

        if (empty($rows)) {
            return 0;
        }

        return (int) ($rows[0]['id'] ?? 0);
    }

    private function updateDolibarrThirdpartyCountry($dolThirdpartyId, $dolCountryId)
    {
        $dolThirdpartyId = (int) $dolThirdpartyId;
        $dolCountryId = (int) $dolCountryId;

        if ($dolThirdpartyId <= 0 || $dolCountryId <= 0) {
            return false;
        }

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'societe SET fk_pays = '.$dolCountryId.' WHERE rowid = '.$dolThirdpartyId;
        if (!$this->db->query($sql)) {
            throw new Exception('Erreur mise à jour pays tiers Dolibarr: '.$this->db->lasterror());
        }

        return true;
    }

    private function updateOdooThirdpartyCountry($odooThirdpartyId, $odooCountryId)
    {
        $odooThirdpartyId = (int) $odooThirdpartyId;
        $odooCountryId = (int) $odooCountryId;

        if ($odooThirdpartyId <= 0 || $odooCountryId <= 0) {
            return false;
        }

        $this->odooCallPublic('res.partner', 'write', [[(int) $odooThirdpartyId], ['country_id' => $odooCountryId]]);

        return true;
    }

    private function getOdooThirdpartyIdByDolibarrId($dolThirdpartyId)
    {
        if (!$this->hasSocieteOdooIdColumn()) {
            return 0;
        }

        $dolThirdpartyId = (int) $dolThirdpartyId;
        if ($dolThirdpartyId <= 0) {
            return 0;
        }

        $sql = 'SELECT odoo_id FROM '.MAIN_DB_PREFIX.'societe_extrafields WHERE fk_object = '.$dolThirdpartyId.' LIMIT 1';
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return (int) ($obj->odoo_id ?? 0);
            }
        }

        return 0;
    }

    public function applyMissingCountrySelection(array $row, $countryCode)
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if ($countryCode === '') {
            return ['dolibarr' => false, 'odoo' => false];
        }

        $dolCountryId = $this->getDolibarrCountryIdByCode($countryCode);
        if ($dolCountryId <= 0) {
            throw new Exception('Pays introuvable dans Dolibarr pour le code '.$countryCode);
        }

        if (empty($this->odoo_uid) && !$this->connectOdoo()) {
            throw new Exception('Connexion Odoo impossible pour enregistrer le pays');
        }

        $odooCountryId = $this->getOdooCountryIdByCode($countryCode);
        if ($odooCountryId <= 0) {
            throw new Exception('Pays introuvable dans Odoo pour le code '.$countryCode);
        }

        $dolThirdpartyId = (int) ($row['dol_socid'] ?? 0);
        $odooThirdpartyId = (int) ($row['odoo_partner_id'] ?? 0);

        if ($dolThirdpartyId <= 0 && $odooThirdpartyId > 0) {
            $dolThirdpartyId = (int) $this->getDolibarrThirdpartyIdByOdooId($odooThirdpartyId);
        }
        if ($odooThirdpartyId <= 0 && $dolThirdpartyId > 0) {
            $odooThirdpartyId = (int) $this->getOdooThirdpartyIdByDolibarrId($dolThirdpartyId);
        }

        $updated = ['dolibarr' => false, 'odoo' => false];

        if ($dolThirdpartyId > 0) {
            $updated['dolibarr'] = $this->updateDolibarrThirdpartyCountry($dolThirdpartyId, $dolCountryId);
        }
        if ($odooThirdpartyId > 0) {
            $updated['odoo'] = $this->updateOdooThirdpartyCountry($odooThirdpartyId, $odooCountryId);
        }

        if (!$updated['dolibarr'] && !$updated['odoo']) {
            throw new Exception('Impossible de retrouver le tiers lié dans Dolibarr/Odoo');
        }

        return $updated;
    }

    private function computeVatRatePercent($totalHt, $totalTva)
    {
        $ht = (float) $totalHt;
        $tva = (float) $totalTva;

        if (abs($ht) < 0.00001) {
            return null;
        }

        return round(($tva / $ht) * 100, 2);
    }

    private function registerVatRateObservation($countryCode, $vatRate, $source, $invoiceRef)
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if ($countryCode === '' || $vatRate === null) {
            return;
        }

        $safeCountry = $this->db->escape($countryCode);
        $safeSource = $this->db->escape((string) $source);
        $safeRef = $this->db->escape((string) $invoiceRef);
        $safeRate = price2num((string) $vatRate, 'MU');

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."syncodoo_vat_rate (country_code, vat_rate, confirmed, source, first_ref, last_ref, first_seen, last_seen)
                VALUES ('".$safeCountry."', ".((float) $safeRate).", 0, '".$safeSource."', '".$safeRef."', '".$safeRef."', NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    source = VALUES(source),
                    last_ref = VALUES(last_ref),
                    last_seen = NOW()";

        $this->db->query($sql);
    }

    public function getPendingVatRateConfirmations()
    {
        $this->ensureVatRateTableExists();

        $sql = "SELECT rowid, country_code, vat_rate, confirmed, source, first_ref, last_ref, first_seen, last_seen
                FROM ".MAIN_DB_PREFIX."syncodoo_vat_rate
                WHERE confirmed = 0
                ORDER BY country_code ASC, vat_rate ASC";

        $resql = $this->db->query($sql);
        $rows = [];
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = [
                    'rowid' => (int) $obj->rowid,
                    'country_code' => (string) $obj->country_code,
                    'vat_rate' => (float) $obj->vat_rate,
                    'confirmed' => (int) $obj->confirmed,
                    'source' => (string) $obj->source,
                    'first_ref' => (string) $obj->first_ref,
                    'last_ref' => (string) $obj->last_ref,
                    'first_seen' => (string) $obj->first_seen,
                    'last_seen' => (string) $obj->last_seen,
                ];
            }
        }

        return $rows;
    }

    public function confirmVatRateByRowId($rowId, $isExact, $correctRate = null)
    {
        $this->ensureVatRateTableExists();

        $rowId = (int) $rowId;
        if ($rowId <= 0) {
            return;
        }

        $state = $isExact ? 1 : -1;
        $sql = "UPDATE ".MAIN_DB_PREFIX."syncodoo_vat_rate SET confirmed = ".((int) $state).", last_seen = NOW()";

        if (!$isExact && $correctRate !== null && $correctRate !== '' && is_numeric($correctRate)) {
            $cr = (float) $correctRate;
            $sql .= ", correct_rate = ".number_format($cr, 4, '.', '');
        }

        $sql .= " WHERE rowid = ".((int) $rowId);
        $this->db->query($sql);
    }

    private function buildVatChecksFromInvoices(array $dolInvoices, array $odooInvoices)
    {
        $this->ensureVatRateTableExists();

        $missingCountry = [];

        foreach ($dolInvoices as $invoice) {
            $ref = (string) ($invoice['ref'] ?? $invoice['_ref'] ?? '');
            $country = $this->normalizeCountryCode($invoice['country_code'] ?? '');

            if ($country === '') {
                $missingCountry[] = [
                    'source' => 'dolibarr',
                    'type' => (string) ($invoice['type'] ?? ''),
                    'ref' => $ref,
                    'dol_socid' => (int) ($invoice['socid'] ?? 0),
                    'odoo_partner_id' => 0,
                    'partner_label' => (string) ($invoice['socid_name'] ?? ''),
                ];
                continue;
            }

            $rate = $this->computeVatRatePercent($invoice['total_ht'] ?? 0, $invoice['total_tva'] ?? 0);
            if ($rate !== null) {
                $this->registerVatRateObservation($country, $rate, 'dolibarr', $ref);
            }
        }

        foreach ($odooInvoices as $invoice) {
            $ref = (string) ($invoice['ref'] ?? $invoice['_ref'] ?? '');
            $country = $this->normalizeCountryCode($invoice['country_code'] ?? '');

            if ($country === '') {
                $missingCountry[] = [
                    'source' => 'odoo',
                    'type' => (string) ($invoice['type'] ?? ''),
                    'ref' => $ref,
                    'dol_socid' => 0,
                    'odoo_partner_id' => (int) ($invoice['partner_id'][0] ?? 0),
                    'partner_label' => (string) ($invoice['partner_id'][1] ?? ''),
                ];
                continue;
            }

            $rate = $this->computeVatRatePercent($invoice['total_ht'] ?? 0, $invoice['total_tva'] ?? 0);
            if ($rate !== null) {
                $this->registerVatRateObservation($country, $rate, 'odoo', $ref);
            }
        }

        return [
            'missing_country' => $missingCountry,
            'pending_rates' => $this->getPendingVatRateConfirmations(),
        ];
    }

    /* ============================================================
     *  SYNCHRONISATION TIERS
     * ============================================================ */

    public function syncTiersOdooToDoli($dol_id)
    {
        global $user;

        // Get Odoo data
        $odoo_data = $this->getOdooTiers();
        $doli_data = $this->getDolibarrTiers();

        $odoo_id = (int) $dol_id;
        if (!$odoo_id || !isset($odoo_data[$odoo_id])) {
            throw new Exception("Partenaire Odoo non trouvé: {$odoo_id}");
        }

        $odoo = $odoo_data[$odoo_id];

        $dol_id_found = 0;
        foreach ($doli_data as $row) {
            if ((int) ($row['odoo_id'] ?? 0) === $odoo_id) {
                $dol_id_found = (int) ($row['dol_id'] ?? 0);
                break;
            }
        }

        if ($dol_id_found <= 0) {
            $matchedDoli = $this->findMatchingDolibarrThirdparty($odoo, $doli_data);
            if ($matchedDoli) {
                $dol_id_found = (int) ($matchedDoli['dol_id'] ?? 0);
                if ($dol_id_found > 0) {
                    $this->updateThirdpartyMapping($dol_id_found, $odoo_id);
                }
            }
        }

        $societe = new Societe($this->db);
        if ($dol_id_found > 0 && $societe->fetch($dol_id_found) > 0) {
            $hasChanges = false;
            if (($societe->email ?? '') !== ($odoo['email'] ?? '')) {
                $societe->email = $odoo['email'] ?? '';
                $hasChanges = true;
            }
            if (($societe->phone ?? '') !== ($odoo['phone'] ?? '')) {
                $societe->phone = $odoo['phone'] ?? '';
                $hasChanges = true;
            }
            if (($societe->zip ?? '') !== ($odoo['zip'] ?? '')) {
                $societe->zip = $odoo['zip'] ?? '';
                $hasChanges = true;
            }
            if (($societe->town ?? '') !== ($odoo['town'] ?? '')) {
                $societe->town = $odoo['town'] ?? '';
                $hasChanges = true;
            }
            if (($societe->tva_intra ?? '') !== ($odoo['vat'] ?? '')) {
                $societe->tva_intra = $odoo['vat'] ?? '';
                $hasChanges = true;
            }

            if ($hasChanges) {
                $res = $societe->update($societe->id, $user);
                if ($res <= 0) {
                    throw new Exception('Erreur maj Dolibarr: '.$this->getDolibarrObjectError($societe));
                }
                $this->log('INFO', 'sync', 'thirdparty', $odoo['name'], 'Mise à jour Dolibarr depuis Odoo');
            }
            return (int) $societe->id;
        } else {
            $societe->nom = $odoo['name'] ?? ('Odoo #'.$odoo_id);
            $societe->name = $societe->nom;
            $societe->email = $odoo['email'] ?? '';
            $societe->phone = $odoo['phone'] ?? '';
            $societe->zip = $odoo['zip'] ?? '';
            $societe->town = $odoo['town'] ?? '';
            $societe->tva_intra = $odoo['vat'] ?? '';
            $odooTypes = $this->getOdooTypeFlags($odoo['customer_rank'] ?? 0, $odoo['supplier_rank'] ?? 0);
            $societe->client = $this->buildDolibarrClientValue($odooTypes);
            $societe->fournisseur = !empty($odooTypes['fournisseur']) ? 1 : 0;
            if ($societe->client > 0) {
                $societe->code_client = -1;
            }
            if ($societe->fournisseur > 0) {
                $societe->code_fournisseur = -1;
            }

            $newId = $societe->create($user);
            if ($newId <= 0) {
                throw new Exception('Erreur creation tiers Dolibarr pour "'.$societe->name.'": '.$this->getDolibarrObjectError($societe));
            }

            $this->updateThirdpartyMapping($newId, $odoo_id);

            $this->log('INFO', 'sync', 'thirdparty', $odoo['name'], 'Création tiers Dolibarr depuis Odoo');
            return (int) $newId;
        }
    }

    public function syncTiersDoliToOdoo($odoo_id)
    {
        // Get data
        $odoo_data = $this->getOdooTiers();
        $doli_data = $this->getDolibarrTiers();

        $dol_id = (int) $odoo_id;
        $doli = null;
        foreach ($doli_data as $d) {
            if ((int) ($d['dol_id'] ?? 0) === $dol_id) {
                $doli = $d;
                break;
            }
        }

        if (!$doli) {
            throw new Exception("Tiers Dolibarr non trouvé: {$dol_id}");
        }

        $remoteId = (int) ($doli['odoo_id'] ?? 0);
        if ($remoteId <= 0) {
            $matchedOdoo = $this->findMatchingOdooThirdparty($doli, $odoo_data);
            if ($matchedOdoo) {
                $remoteId = (int) ($matchedOdoo['odoo_id'] ?? 0);
                if ($remoteId > 0) {
                    $this->updateThirdpartyMapping($dol_id, $remoteId);
                }
            }
        }

        $doliTypes = $this->getDolibarrTypeFlags($doli['client'] ?? 0, $doli['fournisseur'] ?? 0);
        $payload = [
            'name' => $doli['name'] ?? '',
            'email' => $doli['email'] ?? '',
            'phone' => $doli['phone'] ?? '',
            'zip' => $doli['zip'] ?? '',
            'city' => $doli['town'] ?? '',
            'vat' => $doli['vat'] ?? '',
            'customer_rank' => !empty($doliTypes['client']) ? 1 : 0,
            'supplier_rank' => !empty($doliTypes['fournisseur']) ? 1 : 0,
        ];

        if ($remoteId > 0 && isset($odoo_data[$remoteId])) {
            $odoo = $odoo_data[$remoteId];
            $update_data = [];
            if (($odoo['name'] ?? '') !== ($payload['name'] ?? '')) $update_data['name'] = $payload['name'];
            if (($odoo['email'] ?? '') !== ($payload['email'] ?? '')) $update_data['email'] = $payload['email'];
            if (($odoo['phone'] ?? '') !== ($payload['phone'] ?? '')) $update_data['phone'] = $payload['phone'];
            if (($odoo['zip'] ?? '') !== ($payload['zip'] ?? '')) $update_data['zip'] = $payload['zip'];
            if (($odoo['town'] ?? '') !== ($payload['city'] ?? '')) $update_data['city'] = $payload['city'];
            if (($odoo['vat'] ?? '') !== ($payload['vat'] ?? '')) $update_data['vat'] = $payload['vat'];

            if (!empty($update_data)) {
                $this->odooCallPublic('res.partner', 'write', [[(int) $remoteId], $update_data]);
                $this->log('INFO', 'sync', 'thirdparty', $doli['name'], 'Mise à jour Odoo depuis Dolibarr');
            }
            return (int) $remoteId;
        } else {
            $newOdooId = $this->odooCallPublic('res.partner', 'create', [$payload]);
            if (!(int) $newOdooId) {
                throw new Exception('Création partenaire Odoo échouée');
            }

            $this->updateThirdpartyMapping($dol_id, (int) $newOdooId);

            $this->log('INFO', 'sync', 'thirdparty', $doli['name'], 'Création partenaire Odoo depuis Dolibarr');
            return (int) $newOdooId;
        }
    }

    /* ============================================================
     *  SYNCHRONISATION FACTURES
     * ============================================================ */

    public function syncFacturesOdooToDoli($ref, $odooId = 0)
    {
        $odoo_inv = $this->findOdooInvoiceByRef($ref, (int) $odooId);

        if (empty($odoo_inv)) {
            throw new Exception("Facture Odoo $ref introuvable");
        }

        // Check existence in the matching Dolibarr invoice table only
        $safeRef = $this->db->escape($ref);
        $moveType = (string) ($odoo_inv['move_type'] ?? 'out_invoice');
        if ($moveType === 'in_invoice') {
            $sql = "SELECT rowid, total_ttc, 'supplier' as type FROM ".MAIN_DB_PREFIX."facture_fourn WHERE ref = '".$safeRef."' OR ref_supplier = '".$safeRef."' LIMIT 1";
        } else {
            $sql = "SELECT rowid, total_ttc, 'customer' as type FROM ".MAIN_DB_PREFIX."facture WHERE ref = '".$safeRef."' OR ref_client = '".$safeRef."' LIMIT 1";
        }
        $resql = $this->db->query($sql);
        
        if (!$resql) {
            throw new Exception("Erreur DB: ".$this->db->lasterror());
        }

        $doli_inv = $this->db->fetch_object($resql);

        if ($doli_inv) {
            $this->overwriteDolibarrInvoiceTotalsFromOdoo((int) $doli_inv->rowid, (string) $doli_inv->type, $odoo_inv, $ref);
        } else {
            $this->createDolibarrInvoiceFromOdoo($odoo_inv, $ref);
        }
    }

    public function syncFacturesDoliToOdoo($ref)
    {
        // Fetch invoice from Dolibarr by ref
        $safeRef = $this->db->escape($ref);
        $sql = "SELECT f.rowid, f.ref, f.total_ht, f.total_tva, f.total_ttc, f.datef, s.rowid as socid, 'customer' as type ";
        $sql .= "FROM ".MAIN_DB_PREFIX."facture f ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc ";
        $sql .= "WHERE f.ref = '".$safeRef."' ";
        $sql .= "UNION ALL ";
        $sql .= "SELECT f.rowid, f.ref, f.total_ht, f.total_tva, f.total_ttc, f.datef, s.rowid as socid, 'supplier' as type ";
        $sql .= "FROM ".MAIN_DB_PREFIX."facture_fourn f ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc ";
        $sql .= "WHERE f.ref = '".$safeRef."' ";
        $sql .= "LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception("Erreur DB: ".$this->db->lasterror());
        }

        $doli_inv = $this->db->fetch_object($resql);
        if (!$doli_inv) {
            throw new Exception("Facture Dolibarr $ref introuvable");
        }

        // Check if invoice exists in Odoo by name
        $odoo_inv = $this->findOdooInvoiceByRef($ref);

        if (!empty($odoo_inv)) {
            $this->overwriteOdooInvoiceFromDolibarr($odoo_inv, $doli_inv, $ref);
        } else {
            $this->createOdooInvoiceFromDolibarr($doli_inv);
        }
    }

    private function isVatConsistent($ht, $tva, $ttc)
    {
        return abs(((float) $ht + (float) $tva) - (float) $ttc) <= 0.02;
    }

    private function overwriteDolibarrInvoiceTotalsFromOdoo($dolId, $dolType, array $odooInv, $ref)
    {
        $ht = (float) ($odooInv['amount_untaxed'] ?? 0);
        $tva = (float) ($odooInv['amount_tax'] ?? 0);
        $ttc = (float) ($odooInv['amount_total'] ?? 0);

        if (!$this->isVatConsistent($ht, $tva, $ttc)) {
            $delta = (($ht + $tva) - $ttc);
            throw new Exception(
                'Incohérence TVA côté Odoo: HTVA + TVA != TVAC pour '.$ref
                .' (HTVA='.price($ht, 0, '', 1, 2, 2)
                .', TVA='.price($tva, 0, '', 1, 2, 2)
                .', TVAC='.price($ttc, 0, '', 1, 2, 2)
                .', écart='.price($delta, 0, '', 1, 2, 2).')'
            );
        }

        $sql = "UPDATE ".MAIN_DB_PREFIX.((string) $dolType === 'supplier' ? "facture_fourn" : "facture")." SET";
        $sql .= " total_ht = ".price2num((string) $ht, 'MU');
        $sql .= ", total_tva = ".price2num((string) $tva, 'MU');
        $sql .= ", total_ttc = ".price2num((string) $ttc, 'MU');
        $sql .= " WHERE rowid = ".((int) $dolId);

        if (!$this->db->query($sql)) {
            throw new Exception('Mise à jour facture Dolibarr impossible: '.$this->db->lasterror());
        }

        $this->log('INFO', 'sync', 'invoice', $ref, 'Facture Dolibarr alignée sur les données Odoo');
    }

    private function findOdooTaxIdByRate(float $rate, string $moveType): int
    {
        if ($rate <= 0) {
            return 0;
        }

        $typeUse = ($moveType === 'in_invoice') ? 'purchase' : 'sale';
        $rows = $this->odooExecuteKw(
            'account.tax',
            'search_read',
            [[['type_tax_use', '=', $typeUse], ['amount', '=', round($rate, 2)], ['active', '=', true]]],
            ['fields' => ['id'], 'limit' => 1]
        );

        if (!empty($rows[0]['id'])) {
            return (int) $rows[0]['id'];
        }

        return 0;
    }

    private function overwriteOdooInvoiceFromDolibarr(array $odooInv, $doliInv, string $ref): void
    {
        $odooId = (int) ($odooInv['id'] ?? 0);
        if ($odooId <= 0) {
            throw new Exception('Facture Odoo introuvable pour synchronisation: '.$ref);
        }

        $ht = (float) ($doliInv->total_ht ?? 0);
        $tva = (float) ($doliInv->total_tva ?? 0);
        $ttc = (float) ($doliInv->total_ttc ?? 0);

        if (!$this->isVatConsistent($ht, $tva, $ttc)) {
            $delta = (($ht + $tva) - $ttc);
            throw new Exception(
                'Incohérence TVA côté Dolibarr: HTVA + TVA != TVAC pour '.$ref
                .' (HTVA='.price($ht, 0, '', 1, 2, 2)
                .', TVA='.price($tva, 0, '', 1, 2, 2)
                .', TVAC='.price($ttc, 0, '', 1, 2, 2)
                .', écart='.price($delta, 0, '', 1, 2, 2).')'
            );
        }

        $moveType = (string) ($odooInv['move_type'] ?? (($doliInv->type ?? 'customer') === 'supplier' ? 'in_invoice' : 'out_invoice'));
        $rate = ($ht > 0.00001) ? round(($tva / $ht) * 100, 2) : 0;
        $taxId = $this->findOdooTaxIdByRate($rate, $moveType);

        $state = (string) ($odooInv['state'] ?? '');
        if ($state === 'posted') {
            $this->odooCallPublic('account.move', 'button_draft', [[$odooId]]);
        }

        $line = [
            'name' => 'Synchronisation Dolibarr '.$ref,
            'quantity' => 1,
            'price_unit' => $ht,
        ];
        if ($taxId > 0) {
            $line['tax_ids'] = [[6, 0, [$taxId]]];
        } else {
            $line['tax_ids'] = [[6, 0, []]];
        }

        $payload = [
            'invoice_line_ids' => [[5, 0, 0], [0, 0, $line]],
        ];

        $this->odooCallPublic('account.move', 'write', [[$odooId], $payload]);

        try {
            $dolType = (isset($doliInv->type) && (string) $doliInv->type === 'supplier') ? 'supplier' : 'customer';
            $this->exportDolibarrInvoiceAttachmentToOdoo((int) ($doliInv->rowid ?? 0), $ref, $dolType, $odooId);
        } catch (Throwable $e) {
            $this->log('WARNING', 'sync', 'invoice', $ref, 'Export pièce jointe vers Odoo ignoré: '.$e->getMessage());
        }

        $this->log('INFO', 'sync', 'invoice', $ref, 'Facture Odoo alignée sur les données Dolibarr');
    }

    public function updateDolibarrThirdpartyTypes($dolId, array $types)
    {
        $user = $this->getExecutionUser();
        $types = $this->normalizeTypeSelection($types);

        $societe = new Societe($this->db);
        if ($societe->fetch((int) $dolId) <= 0) {
            throw new Exception('Tiers Dolibarr introuvable: '.$dolId);
        }

        $societe->client = $this->buildDolibarrClientValue($types);
        $societe->fournisseur = !empty($types['fournisseur']) ? 1 : 0;
        if ($societe->client > 0 && empty($societe->code_client)) {
            $societe->code_client = -1;
        }
        if ($societe->fournisseur > 0 && empty($societe->code_fournisseur)) {
            $societe->code_fournisseur = -1;
        }

        $res = $societe->update($societe->id, $user);
        if ($res <= 0) {
            throw new Exception('Erreur maj types Dolibarr: '.$this->getDolibarrObjectError($societe));
        }
    }

    public function updateOdooThirdpartyTypes($odooId, array $types)
    {
        $types = $this->normalizeTypeSelection($types);
        $this->odooCallPublic('res.partner', 'write', [[(int) $odooId], $this->buildOdooTypePayload($types)]);
    }

    public function findOdooInvoiceIdByRef($ref)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return 0;
        }

        $invoices = $this->odooExecuteKw(
            'account.move',
            'search_read',
            [[['name', '=', $ref]]],
            ['fields' => ['id'], 'limit' => 1]
        );

        if (!empty($invoices[0]['id'])) {
            return (int) $invoices[0]['id'];
        }

        return 0;
    }

    public function findOdooInvoiceByRefPublic($ref, $odooId = 0)
    {
        return $this->findOdooInvoiceByRef($ref, (int) $odooId);
    }

    public function findOdooThirdpartyIdByName($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 0;
        }

        $partners = $this->odooExecuteKw(
            'res.partner',
            'search_read',
            [[['name', '=', $name]]],
            ['fields' => ['id'], 'limit' => 1]
        );

        if (!empty($partners[0]['id'])) {
            return (int) $partners[0]['id'];
        }

        return 0;
    }
}
