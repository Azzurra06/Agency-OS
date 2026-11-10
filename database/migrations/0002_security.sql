-- Agency OS — migration 0002 : journal de sécurité, limitation des tentatives, registre des suppressions, exécutions du cron

-- Journal de sécurité : insertion seule, purge à 6 mois. Jamais de mot de passe, jeton, cookie ni montant.
CREATE TABLE security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event VARCHAR(60) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    organization_id BIGINT UNSIGNED NULL,
    ip VARBINARY(16) NULL,
    user_agent_hash BINARY(32) NULL,
    request_id CHAR(16) NULL,
    context JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_security_events_event (event, created_at),
    KEY idx_security_events_user (user_id, created_at),
    KEY idx_security_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limitation des tentatives : clés hachées (HMAC), aucune donnée en clair ; purge après 24 h.
CREATE TABLE rate_limit_hits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket VARCHAR(30) NOT NULL,
    key_hash BINARY(32) NOT NULL,
    hit_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rate_limit (bucket, key_hash, hit_at),
    KEY idx_rate_limit_hit_at (hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registre minimal des suppressions (réapplication après restauration d'une sauvegarde), sans données.
CREATE TABLE deletion_registry (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_public_id CHAR(26) NOT NULL,
    deleted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_deletion_registry_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exécutions des tâches planifiées : la paire (job, run_key) est unique, ce qui empêche les doublons.
CREATE TABLE cron_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job VARCHAR(50) NOT NULL,
    run_key VARCHAR(40) NOT NULL,
    status ENUM('running','succeeded','partial','failed') NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    items_ok INT UNSIGNED NOT NULL DEFAULT 0,
    items_failed INT UNSIGNED NOT NULL DEFAULT 0,
    error_ref CHAR(12) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_runs (job, run_key),
    KEY idx_cron_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
