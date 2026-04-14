-- Table de suivi des taux de TVA par pays — module SyncOdoo
-- Créée à la demande (voir SyncOdoo::ensureVatRateTableExists())
-- Ce fichier est conservé pour référence / migration manuelle.

CREATE TABLE IF NOT EXISTS llx_syncodoo_vat_rate (
    rowid        INT              NOT NULL AUTO_INCREMENT,
    country_code VARCHAR(3)       NOT NULL DEFAULT ''
                 COMMENT 'Code ISO 3166-1 alpha-2 du pays (ex. BE, FR, NL)',
    vat_rate     DECIMAL(8,4)     NOT NULL DEFAULT 0.0000
                 COMMENT 'Taux de TVA observé (ex. 21.0000)',
    confirmed    TINYINT          NOT NULL DEFAULT 0
                 COMMENT '0=en attente | 1=confirmé correct | -1=incorrect',
    correct_rate DECIMAL(8,4)     NULL DEFAULT NULL
                 COMMENT 'Taux correct fourni par l\'utilisateur si confirmed=-1',
    source       VARCHAR(20)      NOT NULL DEFAULT ''
                 COMMENT 'dolibarr | odoo',
    first_ref    VARCHAR(100)     NOT NULL DEFAULT ''
                 COMMENT 'Référence de la première facture ayant déclenché l\'observation',
    last_ref     VARCHAR(100)     NOT NULL DEFAULT ''
                 COMMENT 'Référence de la dernière facture ayant déclenché l\'observation',
    first_seen   DATETIME         NOT NULL,
    last_seen    DATETIME         NOT NULL,
    PRIMARY KEY (rowid),
    UNIQUE KEY uk_syncodoo_vat_rate (country_code, vat_rate),
    INDEX idx_syncodoo_vat_rate_confirmed (confirmed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Taux de TVA observés par pays — SyncOdoo';
