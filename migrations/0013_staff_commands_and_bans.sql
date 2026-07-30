CREATE TABLE IF NOT EXISTS `{{prefix}}core_user_moderation` (
    `accountID` INT UNSIGNED NOT NULL,
    `accountBanned` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `bannedBy` INT UNSIGNED NOT NULL DEFAULT 0,
    `bannedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `leaderboardBanned` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `leaderboardBannedBy` INT UNSIGNED NOT NULL DEFAULT 0,
    `leaderboardBannedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`),
    KEY `idx_core_user_moderation_account_ban` (`accountBanned`),
    KEY `idx_core_user_moderation_leaderboard_ban` (`leaderboardBanned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}core_staff_permissions` (`permissionKey`, `description`) VALUES
('users.leaderboard_ban', 'Exclude or restore users in leaderboards');
