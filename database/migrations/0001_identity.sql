-- Agency OS — migration 0001 : identité, organisations, vérification, réinitialisation, MFA (préparée)
-- Compatible MySQL 8.0.16+ et MariaDB 10.5+. Dates en UTC.

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    status ENUM('pending_verification','active','locked','deletion_scheduled') NOT NULL DEFAULT 'pending_verification',
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    session_epoch INT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at DATETIME NULL,
    deletion_scheduled_at DATETIME NULL,
    last_login_at DATETIME NULL,
    last_active_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_public_id (public_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status, deletion_scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organizations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    name VARCHAR(150) NOT NULL,
    activity_type ENUM('wordpress','web','digital','marketing','other') NULL,
    team_size SMALLINT UNSIGNED NULL,
    legal_status ENUM('micro','ei','eurl','sarl','sasu','sas','other') NULL,
    plan VARCHAR(30) NOT NULL DEFAULT 'free',
    status ENUM('active','demo_expired') NOT NULL DEFAULT 'active',
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    demo_expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organizations_public_id (public_id),
    KEY idx_organizations_demo (is_demo, demo_expires_at),
    CONSTRAINT chk_organizations_demo CHECK (is_demo = 0 OR demo_expires_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organization_members (
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner','admin','member') NOT NULL DEFAULT 'owner',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (organization_id, user_id),
    KEY idx_members_user (user_id),
    CONSTRAINT fk_members_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organization_settings (
    organization_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    locale VARCHAR(10) NOT NULL DEFAULT 'fr-FR',
    onboarding_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
    onboarding_completed_at DATETIME NULL,
    price_rounding ENUM('none','1','10','50','100') NOT NULL DEFAULT '10',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (organization_id),
    CONSTRAINT fk_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jetons : seul le SHA-256 est stocké ; usage unique ; expiration courte.
CREATE TABLE email_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verifications_token (token_hash),
    KEY idx_email_verifications_user (user_id, created_at),
    CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    requested_ip VARBINARY(16) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MFA : tables préparées, vides au MVP (docs/04-securite.md §3.6). Secret chiffré, codes hachés.
CREATE TABLE user_mfa_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('totp') NOT NULL,
    secret_enc VARBINARY(512) NOT NULL,
    activated_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_mfa_user (user_id),
    CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_recovery_user (user_id),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
