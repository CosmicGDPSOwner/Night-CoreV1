CREATE TABLE IF NOT EXISTS `{{prefix}}core_staff_admin_login_attempts` (
    `attemptID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ipHash` CHAR(64) NOT NULL,
    `accountID` INT UNSIGNED NOT NULL DEFAULT 0,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `attemptedAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`attemptID`),
    KEY `idx_staff_admin_login_ip_time` (`ipHash`, `attemptedAt`),
    KEY `idx_staff_admin_login_account_time` (`accountID`, `attemptedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_staff_admin_audit` (
    `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actorAccountID` INT UNSIGNED NOT NULL,
    `targetAccountID` INT UNSIGNED NOT NULL DEFAULT 0,
    `roleID` INT UNSIGNED NOT NULL DEFAULT 0,
    `action` VARCHAR(48) NOT NULL,
    `beforeJson` MEDIUMTEXT NOT NULL,
    `afterJson` MEDIUMTEXT NOT NULL,
    `ipHash` CHAR(64) NOT NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`auditID`),
    KEY `idx_staff_admin_audit_actor` (`actorAccountID`, `createdAt`),
    KEY `idx_staff_admin_audit_target` (`targetAccountID`, `createdAt`),
    KEY `idx_staff_admin_audit_role` (`roleID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
