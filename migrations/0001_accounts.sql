-- Minimal Cvolton-compatible account layer for fresh Night Core installations.
-- Existing compatible tables are left untouched.

CREATE TABLE IF NOT EXISTS `{{prefix}}accounts` (
    `accountID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `userName` VARCHAR(20) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(254) NOT NULL DEFAULT '',
    `registerDate` BIGINT NOT NULL DEFAULT 0,
    `isActive` TINYINT NOT NULL DEFAULT 1,
    `gjp2` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`accountID`),
    UNIQUE KEY `uq_accounts_username` (`userName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}users` (
    `userID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `isRegistered` TINYINT NOT NULL DEFAULT 0,
    `extID` VARCHAR(100) NOT NULL DEFAULT '0',
    `userName` VARCHAR(20) NOT NULL DEFAULT '',
    `creatorPoints` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`userID`),
    KEY `idx_users_extid` (`extID`),
    KEY `idx_users_username` (`userName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_auth_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip` VARCHAR(45) NOT NULL,
    `accountID` INT UNSIGNED NULL,
    `attemptedAt` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_auth_attempts_ip_time` (`ip`, `attemptedAt`),
    KEY `idx_auth_attempts_account` (`accountID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
