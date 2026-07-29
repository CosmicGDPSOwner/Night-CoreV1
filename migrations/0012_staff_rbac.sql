CREATE TABLE IF NOT EXISTS `{{prefix}}core_staff_roles` (
    `roleID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL,
    `priority` INT NOT NULL DEFAULT 0,
    `modBadgeLevel` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `badgeText` VARCHAR(24) NOT NULL DEFAULT '',
    `badgeColor` CHAR(7) NOT NULL DEFAULT '',
    `commentColor` CHAR(7) NOT NULL DEFAULT '',
    `usernameColor` CHAR(7) NOT NULL DEFAULT '',
    `createdAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`roleID`),
    UNIQUE KEY `uniq_staff_role_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_staff_permissions` (
    `permissionKey` VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`permissionKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_staff_role_permissions` (
    `roleID` INT UNSIGNED NOT NULL,
    `permissionKey` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`roleID`, `permissionKey`),
    KEY `idx_staff_permission_key` (`permissionKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_staff_assignments` (
    `accountID` INT UNSIGNED NOT NULL,
    `roleID` INT UNSIGNED NOT NULL,
    `assignedBy` INT UNSIGNED NOT NULL DEFAULT 0,
    `assignedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`),
    KEY `idx_staff_assignment_role` (`roleID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}core_staff_permissions` (`permissionKey`, `description`) VALUES
('staff.manage', 'Create roles, edit permissions and assign staff'),
('levels.suggest', 'Suggest star ratings'),
('levels.rate', 'Rate levels'),
('levels.feature', 'Feature levels'),
('levels.epic', 'Set epic/mythic/legendary rating'),
('levels.demon', 'Set demon difficulty'),
('levels.delete', 'Delete levels'),
('comments.moderate', 'Moderate and delete comments'),
('users.ban', 'Ban or unban users'),
('users.mute', 'Mute or unmute users'),
('users.manage', 'Manage user moderation state'),
('reports.view', 'View moderation reports'),
('media.manage', 'Manage local songs and SFX');