-- Compatibility hardening for databases that applied an earlier development
-- revision of 0004/0005 before the universal core baseline was finalized.

-- @ensure-column core_friendships newForLow TINYINT NOT NULL DEFAULT 0
-- @ensure-column core_friendships newForHigh TINYINT NOT NULL DEFAULT 0

-- @ensure-column core_level_lists listVersion INT NOT NULL DEFAULT 1
-- @ensure-column core_level_lists difficulty INT NOT NULL DEFAULT 0
-- @ensure-column core_level_lists original BIGINT NOT NULL DEFAULT 0
-- @ensure-column core_level_lists starFeatured INT NOT NULL DEFAULT 0
-- @ensure-column core_level_lists starStars INT NOT NULL DEFAULT 0
-- @ensure-column core_level_lists countForReward INT NOT NULL DEFAULT 0

CREATE TABLE IF NOT EXISTS `{{prefix}}core_list_downloads` (
    `listID` BIGINT UNSIGNED NOT NULL,
    `ipHash` CHAR(64) NOT NULL,
    `downloadedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`listID`, `ipHash`),
    KEY `idx_core_list_downloads_time` (`downloadedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
