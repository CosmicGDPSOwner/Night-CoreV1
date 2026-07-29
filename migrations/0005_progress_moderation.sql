-- Universal progress, leaderboards, rating, rotations and list schema.

-- @ensure-column users creatorPoints INT NOT NULL DEFAULT 0
-- @ensure-column users globalRank INT NOT NULL DEFAULT 0

CREATE TABLE IF NOT EXISTS `{{prefix}}core_account_saves` (
    `accountID` INT UNSIGNED NOT NULL,
    `saveData` MEDIUMTEXT NOT NULL,
    `saveExtra` MEDIUMTEXT NOT NULL,
    `payloadBytes` INT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_level_scores` (
    `accountID` INT UNSIGNED NOT NULL,
    `userID` INT UNSIGNED NOT NULL,
    `levelID` BIGINT NOT NULL,
    `percent` INT NOT NULL DEFAULT 0,
    `coins` INT NOT NULL DEFAULT 0,
    `attempts` INT NOT NULL DEFAULT 0,
    `scoreTime` BIGINT NOT NULL DEFAULT 0,
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`, `levelID`),
    KEY `idx_core_level_scores_level` (`levelID`, `percent`, `updatedAt`),
    KEY `idx_core_level_scores_user` (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_moderator_roles` (
    `accountID` INT UNSIGNED NOT NULL,
    `roleLevel` INT NOT NULL DEFAULT 0,
    `roleName` VARCHAR(64) NOT NULL DEFAULT '',
    `canRate` TINYINT NOT NULL DEFAULT 0,
    `canFeature` TINYINT NOT NULL DEFAULT 0,
    `canEpic` TINYINT NOT NULL DEFAULT 0,
    `canModerateComments` TINYINT NOT NULL DEFAULT 0,
    `canBan` TINYINT NOT NULL DEFAULT 0,
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_star_suggestions` (
    `levelID` BIGINT NOT NULL,
    `accountID` INT UNSIGNED NOT NULL,
    `stars` INT NOT NULL DEFAULT 0,
    `feature` TINYINT NOT NULL DEFAULT 0,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`levelID`, `accountID`),
    KEY `idx_core_star_suggestions_created` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_rate_log` (
    `rateID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `levelID` BIGINT NOT NULL,
    `accountID` INT UNSIGNED NOT NULL,
    `stars` INT NOT NULL DEFAULT 0,
    `feature` INT NOT NULL DEFAULT 0,
    `epic` INT NOT NULL DEFAULT 0,
    `demon` TINYINT NOT NULL DEFAULT 0,
    `demonDifficulty` INT NOT NULL DEFAULT 0,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`rateID`),
    KEY `idx_core_rate_log_level` (`levelID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_daily_levels` (
    `slotType` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `slotID` INT UNSIGNED NOT NULL,
    `levelID` BIGINT NOT NULL,
    `startsAt` BIGINT NOT NULL DEFAULT 0,
    `endsAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`slotType`, `slotID`),
    KEY `idx_core_daily_current` (`slotType`, `startsAt`, `endsAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_gauntlets` (
    `gauntletID` INT UNSIGNED NOT NULL,
    `levelIDs` VARCHAR(255) NOT NULL DEFAULT '',
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`gauntletID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_map_packs` (
    `packID` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `levelIDs` VARCHAR(255) NOT NULL DEFAULT '',
    `stars` INT NOT NULL DEFAULT 0,
    `coins` INT NOT NULL DEFAULT 0,
    `difficulty` INT NOT NULL DEFAULT 0,
    `color1` VARCHAR(16) NOT NULL DEFAULT '255,255,255',
    `color2` VARCHAR(16) NOT NULL DEFAULT '255,255,255',
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`packID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_level_lists` (
    `listID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `accountID` INT UNSIGNED NOT NULL,
    `userID` INT UNSIGNED NOT NULL,
    `listName` VARCHAR(100) NOT NULL DEFAULT '',
    `listDesc` TEXT NOT NULL,
    `listVersion` INT NOT NULL DEFAULT 1,
    `levelIDs` TEXT NOT NULL,
    `difficulty` INT NOT NULL DEFAULT 0,
    `original` BIGINT NOT NULL DEFAULT 0,
    `downloads` BIGINT NOT NULL DEFAULT 0,
    `likes` BIGINT NOT NULL DEFAULT 0,
    `starFeatured` INT NOT NULL DEFAULT 0,
    `starStars` INT NOT NULL DEFAULT 0,
    `countForReward` INT NOT NULL DEFAULT 0,
    `unlisted` TINYINT NOT NULL DEFAULT 0,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`listID`),
    KEY `idx_core_level_lists_owner` (`accountID`, `updatedAt`),
    KEY `idx_core_level_lists_likes` (`likes`),
    KEY `idx_core_level_lists_downloads` (`downloads`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_list_downloads` (
    `listID` BIGINT UNSIGNED NOT NULL,
    `ipHash` CHAR(64) NOT NULL,
    `downloadedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`listID`, `ipHash`),
    KEY `idx_core_list_downloads_time` (`downloadedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
