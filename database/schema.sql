-- KallioMicro base schema
--
-- The framework requires exactly two tables. Everything else belongs to the
-- downstream project. This file exists because `new Logger($db)` and
-- LocalAuthProvider both target tables the base never defined — a fresh
-- project silently fell back to file logging on every write until someone
-- reverse-engineered the column list out of Logger::writeToDatabase().
--
-- Both table names and every column LocalAuthProvider touches are
-- configurable (config/auth.php, the Logger constructor); this is the default
-- shape those defaults expect.
--
--   mysql -u root -p your_database < database/schema.sql

SET NAMES utf8mb4;

-- Authentication source for LocalAuthProvider.
--
-- `active` is read as a boolean and fails CLOSED: a NULL here disables the
-- account. Declared NOT NULL DEFAULT 1 so that cannot happen by accident.
-- `password` holds a password_hash() digest — 255 leaves room for algorithms
-- wider than bcrypt's 60 bytes, so PASSWORD_DEFAULT can move without a
-- migration.
CREATE TABLE IF NOT EXISTS `core_users` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(190)    NOT NULL,
    `password`   VARCHAR(255)    NOT NULL,
    `email`      VARCHAR(190)        NULL DEFAULT NULL,
    `name`       VARCHAR(190)        NULL DEFAULT NULL,
    `active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_core_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Destination for Logger's database path. The column list is the contract:
-- Logger::writeToDatabase() writes exactly these, and a mismatch makes every
-- insert fail 42S22 and fall back to file — twice per call, since the failure
-- is itself logged.
--
-- `user_id` is 0 for unauthenticated writes rather than NULL, matching the
-- int-typed value Logger passes, so it carries no foreign key.
-- `eventtype` mirrors Logger's level names (BYPASS, SUCCESS, INFO, WARNING,
-- ERROR). `context` holds the JSON-encoded remainder of the context array
-- after user_id/source_id/source have been lifted out of it.
CREATE TABLE IF NOT EXISTS `core_logs` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `origdate`         DATETIME        NOT NULL,
    `user_id`          INT UNSIGNED    NOT NULL DEFAULT 0,
    `rowtype`          VARCHAR(32)     NOT NULL DEFAULT 'log',
    `logsource`        VARCHAR(190)    NOT NULL,
    `logsourceid`      VARCHAR(190)        NULL DEFAULT NULL,
    `eventtype`        VARCHAR(32)     NOT NULL,
    `eventdescription` TEXT            NOT NULL,
    `context`          JSON                NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_core_logs_origdate` (`origdate`),
    KEY `ix_core_logs_eventtype` (`eventtype`),
    KEY `ix_core_logs_source` (`logsource`, `logsourceid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
