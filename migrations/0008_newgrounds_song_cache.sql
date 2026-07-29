-- Cache failed external song lookups so invalid IDs do not repeatedly hit upstream services.

CREATE TABLE IF NOT EXISTS `{{prefix}}core_song_fetch_failures` (
    `songID` INT UNSIGNED NOT NULL,
    `retryAfter` BIGINT NOT NULL DEFAULT 0,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`songID`),
    KEY `idx_core_song_fetch_retry` (`retryAfter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
