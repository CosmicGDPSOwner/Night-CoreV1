-- Media dashboard runtime settings. Values are stored as strings so new dashboard
-- settings can be added without schema changes.
CREATE TABLE IF NOT EXISTS `{{prefix}}core_media_settings` (
    `settingKey` VARCHAR(64) NOT NULL,
    `settingValue` VARCHAR(255) NOT NULL DEFAULT '',
    `updatedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`settingKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Owner-managed SFX assets. SFX use their own ID namespace and do not share
-- rows with custom songs.
CREATE TABLE IF NOT EXISTS `{{prefix}}core_local_sfx` (
    `sfxID` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `originalName` VARCHAR(255) NOT NULL DEFAULT '',
    `extension` VARCHAR(8) NOT NULL DEFAULT 'ogg',
    `sha256` CHAR(64) NOT NULL DEFAULT '',
    `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `download` VARCHAR(1024) NOT NULL DEFAULT '',
    `uploadedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`sfxID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
