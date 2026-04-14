-- Table de mapping des transactions bancaires — module SyncOdoo
-- Créée automatiquement à l'activation du module (voir modsyncodoo.class.php::init())
-- Ce fichier est conservé pour référence / migration manuelle.

CREATE TABLE IF NOT EXISTS llx_syncodoo_bank_map (
    rowid                   INT          NOT NULL AUTO_INCREMENT,
    datec                   DATETIME     NOT NULL,
    tms                     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    odoo_transaction_id     INT          NULL DEFAULT NULL
                            COMMENT 'ID de la transaction dans account.bank.statement.line Odoo',
    dolibarr_bank_line_id   INT          NULL DEFAULT NULL
                            COMMENT 'rowid dans llx_bank',
    dolibarr_bank_account_id INT         NULL DEFAULT NULL
                            COMMENT 'rowid du compte bancaire Dolibarr (llx_bank_account)',
    odoo_journal_id         INT          NULL DEFAULT NULL
                            COMMENT 'ID du journal Odoo (account.journal)',
    sync_direction          VARCHAR(20)  NOT NULL DEFAULT ''
                            COMMENT 'odoo_to_doli | doli_to_odoo',
    odoo_write_date         DATETIME     NULL DEFAULT NULL
                            COMMENT 'write_date de la transaction côté Odoo',
    PRIMARY KEY (rowid),
    UNIQUE KEY uk_syncodoo_bank_map_odoo (odoo_transaction_id),
    UNIQUE KEY uk_syncodoo_bank_map_doli (dolibarr_bank_line_id),
    INDEX idx_syncodoo_bank_map_account (dolibarr_bank_account_id),
    INDEX idx_syncodoo_bank_map_journal (odoo_journal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Mapping des transactions bancaires SyncOdoo';
