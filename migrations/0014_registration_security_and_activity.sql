CREATE TABLE IF NOT EXISTS `{{prefix}}core_registration_attempts` (
    `attemptID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ipHash` CHAR(64) NOT NULL,
    `subnetHash` CHAR(64) NOT NULL,
    `success` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `reason` VARCHAR(32) NOT NULL DEFAULT '',
    `attemptedAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`attemptID`),
    KEY `idx_core_registration_attempts_ip_time` (`ipHash`, `attemptedAt`),
    KEY `idx_core_registration_attempts_subnet_time` (`subnetHash`, `attemptedAt`),
    KEY `idx_core_registration_attempts_time` (`attemptedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_account_lifecycle` (
    `accountID` INT UNSIGNED NOT NULL,
    `lastActiveAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `retentionDays` SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    `deletionScheduledAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `softDeletedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`),
    KEY `idx_core_account_lifecycle_last_active` (`lastActiveAt`),
    KEY `idx_core_account_lifecycle_scheduled` (`deletionScheduledAt`),
    KEY `idx_core_account_lifecycle_soft_deleted` (`softDeletedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}core_account_lifecycle` (`accountID`, `lastActiveAt`, `retentionDays`, `updatedAt`)
SELECT `accountID`, COALESCE(NULLIF(`registerDate`, 0), UNIX_TIMESTAMP()), 14, UNIX_TIMESTAMP()
FROM `{{prefix}}accounts`;
