-- Agency OS — migration 0006 : revenus récurrents (formules et contrats signés = données RÉELLES ; scénarios = SIMULATIONS)
-- Le MRR contractualisé et le MRR simulé ne sont jamais additionnés. Les données figées des scénarios sont chiffrées (D4).

CREATE TABLE recurring_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    kind ENUM('maintenance','seo','hosting','other') NOT NULL,
    billing_period ENUM('monthly','annual') NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    hours_per_month DECIMAL(6,2) NOT NULL DEFAULT 0,
    external_costs_per_month DECIMAL(10,2) NOT NULL DEFAULT 0,
    active_contracts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    contracts_updated_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rec_offers_public_id (public_id),
    UNIQUE KEY uq_rec_offers_id_org (id, organization_id),
    KEY idx_rec_offers_org (organization_id, is_active, position),
    CONSTRAINT fk_rec_offers_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT chk_rec_offers_values CHECK (price >= 0 AND hours_per_month >= 0 AND external_costs_per_month >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prestations incluses dans une formule.
CREATE TABLE recurring_offer_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    offer_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rec_items_offer (offer_id, position),
    CONSTRAINT fk_rec_items_offer FOREIGN KEY (offer_id, organization_id) REFERENCES recurring_offers (id, organization_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_scenarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    family_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    horizon_months TINYINT UNSIGNED NOT NULL DEFAULT 12,
    status ENUM('draft','finalized','superseded','archived') NOT NULL DEFAULT 'draft',
    version_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    parent_scenario_id BIGINT UNSIGNED NULL,
    finalized_at DATETIME NULL,
    finalized_by BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    calc_version VARCHAR(10) NULL,
    frozen_context_enc TEXT NULL,
    frozen_results_enc MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rec_scen_public_id (public_id),
    UNIQUE KEY uq_rec_scen_id_org (id, organization_id),
    KEY idx_rec_scen_org (organization_id, status),
    KEY idx_rec_scen_family (organization_id, family_id),
    CONSTRAINT fk_rec_scen_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_rec_scen_user FOREIGN KEY (finalized_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_rec_scen_horizon CHECK (horizon_months IN (6, 12))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres d'un scénario par formule. offer_id devient NULL si la formule est supprimée :
-- un scénario figé reste lisible grâce à son contexte figé.
CREATE TABLE recurring_scenario_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scenario_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    offer_id BIGINT UNSIGNED NULL,
    starting_clients DECIMAL(8,2) NOT NULL DEFAULT 0,
    new_clients_per_month DECIMAL(6,2) NOT NULL DEFAULT 0,
    monthly_churn_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rec_scen_offer (scenario_id, offer_id),
    CONSTRAINT fk_rec_so_scenario FOREIGN KEY (scenario_id, organization_id) REFERENCES recurring_scenarios (id, organization_id) ON DELETE CASCADE,
    CONSTRAINT fk_rec_so_offer FOREIGN KEY (offer_id) REFERENCES recurring_offers (id) ON DELETE SET NULL,
    CONSTRAINT chk_rec_so_values CHECK (starting_clients >= 0 AND new_clients_per_month >= 0 AND monthly_churn_rate >= 0 AND monthly_churn_rate <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
