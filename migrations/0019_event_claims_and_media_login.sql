CREATE TABLE IF NOT EXISTS `{{prefix}}core_event_completion_state` (
    `accountID` INT UNSIGNED NOT NULL,
    `nonDemonCompleted` INT UNSIGNED NOT NULL DEFAULT 0,
    `demonCompleted` INT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_media_login_attempts` (
    `attemptID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ipHash` CHAR(64) NOT NULL,
    `accountID` INT UNSIGNED NOT NULL DEFAULT 0,
    `success` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `attemptedAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`attemptID`),
    KEY `idx_media_login_ip_time` (`ipHash`, `attemptedAt`),
    KEY `idx_media_login_account_time` (`accountID`, `attemptedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_media_upload_audit` (
    `uploadID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `accountID` INT UNSIGNED NOT NULL,
    `mediaType` ENUM('song','sfx') NOT NULL,
    `mediaID` INT UNSIGNED NOT NULL,
    `originalName` VARCHAR(255) NOT NULL DEFAULT '',
    `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `sha256` CHAR(64) NOT NULL DEFAULT '',
    `ipHash` CHAR(64) NOT NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`uploadID`),
    KEY `idx_media_upload_account_time` (`accountID`, `createdAt`),
    KEY `idx_media_upload_type_id` (`mediaType`, `mediaID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;