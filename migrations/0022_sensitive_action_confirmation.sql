CREATE TABLE IF NOT EXISTS `{{prefix}}core_account_security_preferences` (
    `accountID` INT UNSIGNED NOT NULL,
    `requireSensitivePassword` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`),
    KEY `idx_account_security_updated` (`updatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_account_security_audit` (
    `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `accountID` INT UNSIGNED NOT NULL,
    `requiredBefore` TINYINT UNSIGNED NOT NULL,
    `requiredAfter` TINYINT UNSIGNED NOT NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`auditID`),
    KEY `idx_account_security_audit_account_time` (`accountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}core_account_security_preferences`
    (`accountID`, `requireSensitivePassword`, `updatedAt`)
SELECT `accountID`, 1, UNIX_TIMESTAMP()
FROM `{{prefix}}accounts`;
