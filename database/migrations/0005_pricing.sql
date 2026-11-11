-- Agency OS — migration 0005 : pricing (simulations versionnées, scénarios, lignes de temps et de frais)
-- Brouillon : recalculé à chaque lecture, rien n'est stocké. Finalisée : hypothèses et résultats FIGÉS, chiffrés (D4).

CREATE TABLE pricing_simulations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    family_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    status ENUM('draft','finalized','superseded','archived') NOT NULL DEFAULT 'draft',
    version_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    parent_simulation_id BIGINT UNSIGNED NULL,
    superseded_by BIGINT UNSIGNED NULL,
    reference_scenario_id BIGINT UNSIGNED NULL,
    finalized_at DATETIME NULL,
    finalized_by BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    calc_version VARCHAR(10) NULL,
    frozen_context_enc TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    -- Au plus une simulation active (finalisée) par prestation. La colonne générée ne dérive que de status
    -- (MySQL 8 interdit ON DELETE CASCADE / SET NULL sur une colonne de base d'une colonne générée stockée).
    is_active_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'finalized', 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pricing_sim_public_id (public_id),
    UNIQUE KEY uq_pricing_sim_id_org (id, organization_id),
    UNIQUE KEY uq_pricing_sim_active (service_id, is_active_key),
    KEY idx_pricing_sim_org (organization_id, service_id, status),
    KEY idx_pricing_sim_family (organization_id, family_id),
    CONSTRAINT fk_pricing_sim_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    -- Prestation supprimée : la simulation reste lisible (« sur mesure »), l'appartenance est vérifiée par le service.
    CONSTRAINT fk_pricing_sim_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE SET NULL,
    CONSTRAINT fk_pricing_sim_user FOREIGN KEY (finalized_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pricing_scenarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    simulation_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(60) NOT NULL,
    hourly_cost_mode ENUM('profile','custom') NOT NULL DEFAULT 'profile',
    hourly_cost_custom DECIMAL(10,2) NULL,
    contingency_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
    target_margin_rate DECIMAL(6,4) NOT NULL,
    proposed_price DECIMAL(12,2) NULL,
    position TINYINT UNSIGNED NOT NULL,
    frozen_results_enc TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pricing_scen_public_id (public_id),
    UNIQUE KEY uq_pricing_scen_id_org (id, organization_id),
    KEY idx_pricing_scen_sim (simulation_id, position),
    CONSTRAINT fk_pricing_scen_sim FOREIGN KEY (simulation_id, organization_id) REFERENCES pricing_simulations (id, organization_id) ON DELETE CASCADE,
    CONSTRAINT chk_pricing_scen_rates CHECK (contingency_rate >= 0 AND contingency_rate <= 1 AND target_margin_rate >= 0 AND target_margin_rate <= 0.9)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pricing_scenario_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scenario_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('time','direct_cost') NOT NULL,
    label VARCHAR(120) NOT NULL,
    hours DECIMAL(8,2) NULL,
    amount DECIMAL(12,2) NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pricing_lines_scen (scenario_id, position),
    CONSTRAINT fk_pricing_lines_scen FOREIGN KEY (scenario_id, organization_id) REFERENCES pricing_scenarios (id, organization_id) ON DELETE CASCADE,
    CONSTRAINT chk_pricing_lines_kind CHECK ((kind = 'time' AND hours IS NOT NULL AND amount IS NULL) OR (kind = 'direct_cost' AND amount IS NOT NULL AND hours IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
