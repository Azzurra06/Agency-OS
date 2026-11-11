-- Agency OS — migration 0003 : profil financier (champs sensibles chiffrés), journal des modifications, catalogue des prestations
-- Colonnes « *_enc » : valeurs chiffrées par FieldCrypto (AES-256-GCM ou XChaCha20-Poly1305), format « k1.gcm:<base64> ».
-- NULL signifie « non renseigné », jamais 0 (docs/03-formules-et-scoring.md §0).

CREATE TABLE financial_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    period_type ENUM('closed_fiscal_year','trailing_12_months') NOT NULL DEFAULT 'closed_fiscal_year',
    period_start DATE NULL,
    period_end DATE NULL,
    revenue_enc VARCHAR(255) NULL,
    fixed_costs_enc VARCHAR(255) NULL,
    personnel_costs_enc VARCHAR(255) NULL,
    variable_costs_attributable_enc VARCHAR(255) NULL,
    variable_costs_general_enc VARCHAR(255) NULL,
    owner_comp_total_cost_enc VARCHAR(255) NULL,
    owner_comp_included_amount_enc VARCHAR(255) NULL,
    hourly_cost_override_enc VARCHAR(255) NULL,
    revenue_target_enc VARCHAR(255) NULL,
    recurring_share_declared_enc VARCHAR(255) NULL,
    production_fte DECIMAL(5,2) NULL,
    annual_hours_per_fte DECIMAL(7,2) NULL,
    annual_hours_is_assumption TINYINT(1) NOT NULL DEFAULT 0,
    billable_rate DECIMAL(6,4) NULL,
    billable_rate_is_assumption TINYINT(1) NOT NULL DEFAULT 0,
    revenue_target_year SMALLINT UNSIGNED NULL,
    recurring_share_updated_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_profiles_public_id (public_id),
    UNIQUE KEY uq_financial_profiles_org (organization_id),
    CONSTRAINT fk_financial_profiles_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_financial_profiles_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_financial_period CHECK (period_start IS NULL OR period_end IS NULL OR period_start < period_end),
    CONSTRAINT chk_financial_fte CHECK (production_fte IS NULL OR production_fte >= 0),
    CONSTRAINT chk_financial_rate CHECK (billable_rate IS NULL OR (billable_rate > 0 AND billable_rate <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal léger des modifications financières importantes (insertion seule, valeurs chiffrées).
CREATE TABLE financial_profile_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    change_set_id CHAR(26) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    origin ENUM('onboarding','settings','accept_computed_share','demo_seed') NOT NULL,
    field VARCHAR(50) NOT NULL,
    old_value_enc VARCHAR(255) NULL,
    new_value_enc VARCHAR(255) NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_profile_changes_org (organization_id, changed_at),
    CONSTRAINT fk_profile_changes_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_changes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalogue des prestations ponctuelles (le prix actuel est la source unique du « prix pratiqué »).
CREATE TABLE services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    category ENUM('showcase','ecommerce','redesign','seo','custom','other') NOT NULL,
    current_price DECIMAL(12,2) NULL,
    annual_volume SMALLINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_public_id (public_id),
    UNIQUE KEY uq_services_id_org (id, organization_id),
    KEY idx_services_org (organization_id, is_active, position),
    CONSTRAINT fk_services_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT chk_services_price CHECK (current_price IS NULL OR current_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
