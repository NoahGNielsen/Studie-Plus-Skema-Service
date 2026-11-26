-- Studie Plus Skema Service — databaseskema (MySQL 5.7+/8 eller MariaDB 10.3+)
-- Køres én gang via phpMyAdmin i Simply.com-kontrolpanelet.

CREATE TABLE IF NOT EXISTS sps_users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lookup_hash         CHAR(64)     NOT NULL COMMENT 'HMAC af normaliseret brugernavn',
    username_enc        VARCHAR(512) NOT NULL COMMENT 'Krypteret (libsodium)',
    password_enc        VARCHAR(1024) NOT NULL COMMENT 'Krypteret (libsodium)',
    token_hash          CHAR(64)     NOT NULL COMMENT 'HMAC af feed-token; selve tokenet gemmes ikke',
    status              VARCHAR(16)  NOT NULL DEFAULT 'active',
    failure_count       INT UNSIGNED NOT NULL DEFAULT 0,
    empty_streak        INT UNSIGNED NOT NULL DEFAULT 0,
    last_error          VARCHAR(64)  NULL COMMENT 'Kun en fejlkode, aldrig detaljer',
    last_attempt_at     DATETIME     NULL,
    last_success_at     DATETIME     NULL,
    created_at          DATETIME     NOT NULL,
    updated_at          DATETIME     NOT NULL,
    token_created_at    DATETIME     NOT NULL,
    feed_last_access_on DATE         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lookup (lookup_hash),
    UNIQUE KEY uq_token (token_hash),
    KEY ix_sync (status, last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sps_events (
    user_id       INT UNSIGNED NOT NULL,
    uid           VARCHAR(100) NOT NULL,
    sequence      INT UNSIGNED NOT NULL DEFAULT 0,
    content_hash  CHAR(64)     NOT NULL,
    last_modified DATETIME     NOT NULL,
    payload       MEDIUMTEXT   NOT NULL COMMENT 'JSON med kalenderfelter (ingen fraværsdata)',
    PRIMARY KEY (user_id, uid),
    CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES sps_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sps_rate_limits (
    bucket_key   CHAR(64)     NOT NULL COMMENT 'Hash af handling + hashet IP/brugernavn',
    window_start INT UNSIGNED NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_key),
    KEY ix_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;
