CREATE TABLE IF NOT EXISTS `{{prefix}}core_media_upload_rate_limits` (
    `scopeKey` VARCHAR(80) NOT NULL,
    `windowStartedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `uploadCount` INT UNSIGNED NOT NULL DEFAULT 0,
    `lastUploadAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`scopeKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
