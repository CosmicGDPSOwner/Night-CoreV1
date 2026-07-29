-- Tracks server-hosted custom songs separately from cached external song metadata.
-- songID starts in a high positive range so locally uploaded tracks do not collide
-- with normal Newgrounds-style IDs in typical GDPS installations.

CREATE TABLE IF NOT EXISTS `{{prefix}}core_local_songs` (
    `songID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `originalName` VARCHAR(255) NOT NULL DEFAULT '',
    `sha256` CHAR(64) NOT NULL DEFAULT '',
    `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `uploadedAt` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`songID`)
) ENGINE=InnoDB AUTO_INCREMENT=90000000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
