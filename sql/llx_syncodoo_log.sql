-- Table de journalisation du module SyncOdoo
-- Créée automatiquement à l'activation du module (voir modsyncodoo.class.php::init())
-- Ce fichier est conservé pour référence / migration manuelle.

CREATE TABLE IF NOT EXISTS llx_syncodoo_log (
    rowid       INT          NOT NULL AUTO_INCREMENT,
    datec       DATETIME     NOT NULL,
    level       VARCHAR(10)  NOT NULL DEFAULT 'INFO'
                             COMMENT 'DEBUG | INFO | WARNING | ERROR',
    direction   VARCHAR(30)  NOT NULL DEFAULT ''
                             COMMENT 'tiers | facture | suppression | connexion | global',
    entity_type VARCHAR(30)  NOT NULL DEFAULT ''
                             COMMENT 'partner | thirdparty | invoice | ...',
    entity_ref  VARCHAR(100) NOT NULL DEFAULT '',
    message     TEXT,
    PRIMARY KEY (rowid),
    INDEX idx_syncodoo_log_datec (datec),
    INDEX idx_syncodoo_log_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Journal de synchronisation SyncOdoo';
