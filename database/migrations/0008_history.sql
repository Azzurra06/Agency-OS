-- Agency OS — migration 0008 : historique (instantanés quotidiens) et jetons de confirmation du compte (suppression D8)

-- Instantanés quotidiens, en insertion seule (cron « dès 02:15 », heure de Paris). Jamais d'écrasement.
-- Les valeurs financières et le contexte sont CHIFFRÉS (D4). Un KPI non calculable est NULL, jamais 0.
CREATE TABLE kpi_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    snapshot_date DATE NOT NULL,
    captured_at DATETIME NOT NULL,
    maturity_score DECIMAL(5,2) NULL,
    maturity_reliability DECIMAL(5,4) NULL,
    revenue_annualized_enc VARCHAR(255) NULL,
    total_costs_annualized_enc VARCHAR(255) NULL,
    estimated_result_enc VARCHAR(255) NULL,
    mrr_contracted_enc VARCHAR(255) NULL,
    recurring_share_declared_enc VARCHAR(255) NULL,
    hourly_cost_enc VARCHAR(255) NULL,
    action_progress DECIMAL(5,2) NULL,
    calc_version VARCHAR(10) NOT NULL,
    context_enc TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_snapshots_org_date (organization_id, snapshot_date),
    CONSTRAINT fk_snapshots_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liens à usage unique envoyés par email : confirmation et annulation de la suppression du compte (1 h).
CREATE TABLE account_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('delete_confirm','delete_cancel') NOT NULL,
    token_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_tokens_hash (token_hash),
    KEY idx_account_tokens_user (user_id, purpose),
    CONSTRAINT fk_account_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
