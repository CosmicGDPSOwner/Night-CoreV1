CREATE TABLE IF NOT EXISTS `{{prefix}}core_account_lifecycle` (
    `accountID` INT UNSIGNED NOT NULL,
    `lastActiveAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `retentionDays` SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    `deletionScheduledAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `softDeletedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`),
    KEY `idx_account_lifecycle_scheduled` (`deletionScheduledAt`, `softDeletedAt`),
    KEY `idx_account_lifecycle_activity` (`lastActiveAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_account_deletion_audit` (
    `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `accountID` INT UNSIGNED NOT NULL,
    `action` ENUM('scheduled','cancelled','anonymized') NOT NULL,
    `retentionDays` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `scheduledAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `createdAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`auditID`),
    KEY `idx_account_deletion_audit_account_time` (`accountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}core_account_lifecycle`
    (`accountID`, `lastActiveAt`, `retentionDays`, `deletionScheduledAt`, `softDeletedAt`, `updatedAt`)
SELECT `accountID`, COALESCE(NULLIF(`registerDate`, 0), UNIX_TIMESTAMP()), 14, 0, 0, UNIX_TIMESTAMP()
FROM `{{prefix}}accounts`;
