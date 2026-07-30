CREATE TABLE IF NOT EXISTS `{{prefix}}core_events` (
    `eventID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `levelID` INT UNSIGNED NOT NULL,
    `startsAt` BIGINT UNSIGNED NOT NULL,
    `endsAt` BIGINT UNSIGNED NOT NULL,
    `rewardJson` TEXT NOT NULL,
    `status` ENUM('scheduled','active','ended','cancelled') NOT NULL DEFAULT 'scheduled',
    `createdBy` INT UNSIGNED NOT NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL,
    `updatedBy` INT UNSIGNED NOT NULL,
    `updatedAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`eventID`),
    KEY `idx_core_events_level` (`levelID`),
    KEY `idx_core_events_window` (`status`, `startsAt`, `endsAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_event_claims` (
    `eventID` BIGINT UNSIGNED NOT NULL,
    `accountID` INT UNSIGNED NOT NULL,
    `claimedAt` BIGINT UNSIGNED NOT NULL,
    `rewardJson` TEXT NOT NULL,
    PRIMARY KEY (`eventID`, `accountID`),
    KEY `idx_core_event_claims_account` (`accountID`, `claimedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_event_audit` (
    `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `eventID` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `levelID` INT UNSIGNED NOT NULL,
    `accountID` INT UNSIGNED NOT NULL,
    `action` VARCHAR(32) NOT NULL,
    `detailsJson` TEXT NOT NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`auditID`),
    KEY `idx_core_event_audit_event` (`eventID`, `createdAt`),
    KEY `idx_core_event_audit_account` (`accountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}core_staff_permissions` (`permissionKey`, `description`) VALUES
('rotations.daily', 'Schedule daily levels'),
('rotations.weekly', 'Schedule weekly levels'),
('events.create', 'Create level events'),
('events.change', 'Change existing level events'),
('events.set', 'Create or fully replace level events');