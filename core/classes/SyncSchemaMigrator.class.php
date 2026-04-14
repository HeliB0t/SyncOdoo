<?php

class SyncSchemaMigrator
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function migrateToLatest()
    {
        $this->ensureMigrationsTable();

        $applied = $this->getAppliedVersions();
        $migrations = $this->getMigrations();
        ksort($migrations, SORT_NATURAL);

        foreach ($migrations as $version => $definition) {
            if (isset($applied[$version])) {
                continue;
            }

            $this->runMigration($version, $definition['name'], $definition['sql']);
        }

        return true;
    }

    private function ensureMigrationsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_migration (
            rowid INT NOT NULL AUTO_INCREMENT,
            version VARCHAR(50) NOT NULL,
            migration_name VARCHAR(255) NOT NULL,
            executed_at DATETIME NOT NULL,
            success TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY (rowid),
            UNIQUE KEY uk_syncodoo_migration_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if (!$this->db->query($sql)) {
            throw new Exception('Impossible de creer la table des migrations: '.$this->db->lasterror());
        }
    }

    private function getAppliedVersions()
    {
        $sql = "SELECT version FROM ".MAIN_DB_PREFIX."syncodoo_migration WHERE success = 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Impossible de lire l\'historique des migrations: '.$this->db->lasterror());
        }

        $versions = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $versions[(string) ($obj->version ?? '')] = true;
        }

        return $versions;
    }

    private function runMigration($version, $name, array $sqlStatements)
    {
        $this->beginTransaction();

        try {
            foreach ($sqlStatements as $sql) {
                if (!$this->db->query($sql)) {
                    throw new Exception('Migration '.$version.' ('.$name.') en echec: '.$this->db->lasterror());
                }
            }

            $insert = "INSERT INTO ".MAIN_DB_PREFIX."syncodoo_migration (version, migration_name, executed_at, success)
                VALUES ('".$this->db->escape((string) $version)."', '".$this->db->escape((string) $name)."', NOW(), 1)";

            if (!$this->db->query($insert)) {
                throw new Exception('Impossible d\'enregistrer la migration '.$version.': '.$this->db->lasterror());
            }

            $this->commitTransaction();
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    private function beginTransaction()
    {
        if (method_exists($this->db, 'begin')) {
            $this->db->begin();
            return;
        }

        $this->db->query('START TRANSACTION');
    }

    private function commitTransaction()
    {
        if (method_exists($this->db, 'commit')) {
            $this->db->commit();
            return;
        }

        $this->db->query('COMMIT');
    }

    private function rollbackTransaction()
    {
        if (method_exists($this->db, 'rollback')) {
            $this->db->rollback();
            return;
        }

        $this->db->query('ROLLBACK');
    }

    private function getMigrations()
    {
        return [
            '1.0.0' => [
                'name' => 'create_log_and_bank_map_tables',
                'sql' => [
                    "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_log (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                    "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_bank_map (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                ],
            ],
            '1.1.0' => [
                'name' => 'create_vat_rate_table',
                'sql' => [
                    "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."syncodoo_vat_rate (
                        rowid INT NOT NULL AUTO_INCREMENT,
                        country_code VARCHAR(10) NOT NULL,
                        vat_rate DECIMAL(8,4) NOT NULL,
                        confirmed TINYINT NOT NULL DEFAULT 0,
                        source VARCHAR(20) NOT NULL DEFAULT '',
                        first_ref VARCHAR(180) NOT NULL DEFAULT '',
                        last_ref VARCHAR(180) NOT NULL DEFAULT '',
                        first_seen DATETIME NOT NULL,
                        last_seen DATETIME NOT NULL,
                        correct_rate DECIMAL(8,4) NULL DEFAULT NULL,
                        PRIMARY KEY (rowid),
                        UNIQUE KEY uk_syncodoo_vat_country_rate (country_code, vat_rate),
                        INDEX idx_syncodoo_vat_confirmed (confirmed),
                        INDEX idx_syncodoo_vat_country (country_code)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                ],
            ],
        ];
    }
}
