-- Agency OS — migration 0004 : diagnostic de maturité (brouillon unique, réponses, scores figés à la clôture)
-- Le questionnaire lui-même est défini dans le code (app/Domain/Diagnostic/Questionnaires/v1.php), versionné et immuable.

CREATE TABLE diagnostics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    questionnaire_version VARCHAR(10) NOT NULL,
    calc_version VARCHAR(10) NOT NULL,
    status ENUM('draft','completed') NOT NULL DEFAULT 'draft',
    completeness ENUM('complete','partial') NULL,
    started_by BIGINT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    -- Au plus un brouillon par organisation : colonne générée (1 pour un brouillon, NULL sinon) unique avec organization_id.
    -- Elle ne dérive que de status : MySQL 8 interdit ON DELETE CASCADE sur une colonne de base d'une colonne générée stockée.
    draft_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'draft', 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_diagnostics_public_id (public_id),
    UNIQUE KEY uq_diagnostics_id_org (id, organization_id),
    UNIQUE KEY uq_diagnostics_one_draft (organization_id, draft_key),
    KEY idx_diagnostics_org (organization_id, status, completed_at),
    CONSTRAINT fk_diagnostics_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_diagnostics_user FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diagnostic_answers (
    diagnostic_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    question_code VARCHAR(60) NOT NULL,
    option_code VARCHAR(20) NOT NULL,
    prefilled_from_profile TINYINT(1) NOT NULL DEFAULT 0,
    answered_at DATETIME NOT NULL,
    PRIMARY KEY (diagnostic_id, question_code),
    KEY idx_answers_org (organization_id),
    -- Clé étrangère composite : une réponse ne peut appartenir qu'au diagnostic de SA organisation.
    CONSTRAINT fk_answers_diagnostic FOREIGN KEY (diagnostic_id, organization_id) REFERENCES diagnostics (id, organization_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scores écrits une seule fois à la clôture (immuables). dimension_code = 'global' pour la synthèse.
CREATE TABLE diagnostic_scores (
    diagnostic_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    dimension_code VARCHAR(20) NOT NULL,
    score DECIMAL(5,2) NULL,
    is_scorable TINYINT(1) NOT NULL,
    applicable_count TINYINT UNSIGNED NOT NULL,
    scored_count TINYINT UNSIGNED NOT NULL,
    unknown_excluded_count TINYINT UNSIGNED NOT NULL,
    unknown_evaluable_count TINYINT UNSIGNED NOT NULL,
    reliability DECIMAL(5,4) NOT NULL,
    PRIMARY KEY (diagnostic_id, dimension_code),
    CONSTRAINT fk_scores_diagnostic FOREIGN KEY (diagnostic_id, organization_id) REFERENCES diagnostics (id, organization_id) ON DELETE CASCADE,
    CONSTRAINT chk_scores_range CHECK (score IS NULL OR (score >= 0 AND score <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
