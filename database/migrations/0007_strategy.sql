-- Agency OS — migration 0007 : stratégie (recommandations du moteur de règles, plan d'action)
-- Les preuves (evidence) et l'impact chiffré contiennent des valeurs financières : ils sont CHIFFRÉS (D4).
-- Le titre et l'explication sont produits à l'affichage à partir des preuves déchiffrées : aucun montant en clair en base.

CREATE TABLE recommendations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    rule_code VARCHAR(50) NOT NULL,
    subject_key VARCHAR(80) NOT NULL,
    provider VARCHAR(20) NOT NULL DEFAULT 'rules',
    rule_version SMALLINT UNSIGNED NOT NULL,
    status ENUM('new','accepted','dismissed','snoozed','auto_resolved','done') NOT NULL DEFAULT 'new',
    severity ENUM('info','medium','high','critical') NOT NULL,
    priority_score TINYINT UNSIGNED NOT NULL,
    dimension_code VARCHAR(10) NULL,
    evidence_enc TEXT NOT NULL,
    impact_kind ENUM('qualitative','quantified') NOT NULL,
    impact_amount_enc VARCHAR(255) NULL,
    data_quality ENUM('ok','partial','stale','partial_stale') NOT NULL DEFAULT 'ok',
    reappeared TINYINT(1) NOT NULL DEFAULT 0,
    snoozed_until DATE NULL,
    dismissed_severity ENUM('info','medium','high','critical') NULL,
    first_detected_at DATETIME NOT NULL,
    last_evaluated_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reco_public_id (public_id),
    UNIQUE KEY uq_reco_id_org (id, organization_id),
    UNIQUE KEY uq_reco_subject (organization_id, rule_code, subject_key),
    KEY idx_reco_status (organization_id, status, priority_score),
    CONSTRAINT fk_reco_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT chk_reco_priority CHECK (priority_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE action_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_id_org (id, organization_id),
    UNIQUE KEY uq_plans_org (organization_id),
    CONSTRAINT fk_plans_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE action_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    recommendation_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    status ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo',
    due_date DATE NULL,
    completed_at DATETIME NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_actions_public_id (public_id),
    UNIQUE KEY uq_actions_id_org (id, organization_id),
    KEY idx_actions_plan (organization_id, plan_id, status, position),
    CONSTRAINT fk_actions_plan FOREIGN KEY (plan_id, organization_id) REFERENCES action_plans (id, organization_id) ON DELETE CASCADE,
    -- Une recommandation supprimée laisse l'action en place (action libre).
    CONSTRAINT fk_actions_reco FOREIGN KEY (recommendation_id) REFERENCES recommendations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE action_item_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    action_item_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(200) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tasks_public_id (public_id),
    KEY idx_tasks_item (action_item_id, position),
    CONSTRAINT fk_tasks_item FOREIGN KEY (action_item_id, organization_id) REFERENCES action_items (id, organization_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
