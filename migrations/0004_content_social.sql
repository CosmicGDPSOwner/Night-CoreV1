-- Universal content and social schema. Core-owned tables avoid collisions with legacy GDPS schemas.

CREATE TABLE IF NOT EXISTS `{{prefix}}core_songs` (
    `songID` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `authorID` INT NOT NULL DEFAULT 0,
    `authorName` VARCHAR(255) NOT NULL DEFAULT '',
    `size` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `download` TEXT NOT NULL,
    `isDisabled` TINYINT NOT NULL DEFAULT 0,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`songID`),
    KEY `idx_core_songs_author` (`authorName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_comments` (
    `commentID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `accountID` INT UNSIGNED NOT NULL,
    `userID` INT UNSIGNED NOT NULL,
    `userName` VARCHAR(20) NOT NULL DEFAULT '',
    `targetType` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `targetID` BIGINT NOT NULL DEFAULT 0,
    `comment` TEXT NOT NULL,
    `percent` INT NOT NULL DEFAULT 0,
    `likes` BIGINT NOT NULL DEFAULT 0,
    `isSpam` TINYINT NOT NULL DEFAULT 0,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`commentID`),
    KEY `idx_core_comments_target` (`targetType`, `targetID`, `createdAt`),
    KEY `idx_core_comments_account` (`accountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_item_likes` (
    `accountID` INT UNSIGNED NOT NULL,
    `itemType` TINYINT UNSIGNED NOT NULL,
    `itemID` BIGINT NOT NULL,
    `value` TINYINT NOT NULL DEFAULT 1,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountID`, `itemType`, `itemID`),
    KEY `idx_core_item_likes_item` (`itemType`, `itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_reports` (
    `reportID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `accountID` INT UNSIGNED NOT NULL DEFAULT 0,
    `itemType` TINYINT UNSIGNED NOT NULL,
    `itemID` BIGINT NOT NULL,
    `reason` VARCHAR(255) NOT NULL DEFAULT '',
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`reportID`),
    KEY `idx_core_reports_item` (`itemType`, `itemID`, `createdAt`),
    KEY `idx_core_reports_account` (`accountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_friendships` (
    `accountLow` INT UNSIGNED NOT NULL,
    `accountHigh` INT UNSIGNED NOT NULL,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`accountLow`, `accountHigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_friend_requests` (
    `requestID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fromAccountID` INT UNSIGNED NOT NULL,
    `toAccountID` INT UNSIGNED NOT NULL,
    `message` VARCHAR(255) NOT NULL DEFAULT '',
    `isRead` TINYINT NOT NULL DEFAULT 0,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`requestID`),
    UNIQUE KEY `uq_core_friend_request` (`fromAccountID`, `toAccountID`),
    KEY `idx_core_friend_requests_to` (`toAccountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_blocks` (
    `ownerAccountID` INT UNSIGNED NOT NULL,
    `blockedAccountID` INT UNSIGNED NOT NULL,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`ownerAccountID`, `blockedAccountID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_messages` (
    `messageID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fromAccountID` INT UNSIGNED NOT NULL,
    `toAccountID` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(255) NOT NULL DEFAULT '',
    `body` TEXT NOT NULL,
    `isRead` TINYINT NOT NULL DEFAULT 0,
    `createdAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`messageID`),
    KEY `idx_core_messages_inbox` (`toAccountID`, `createdAt`),
    KEY `idx_core_messages_outbox` (`fromAccountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
