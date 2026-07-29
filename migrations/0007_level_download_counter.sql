-- LevelRepository intentionally counts at most two download events per level/IP hash,
-- matching the legacy GDPS request pattern while avoiding unlimited counter inflation.
-- Earlier schema revisions made (levelID, ipHash) the primary key, which prevented the
-- second bounded event from being recorded.

ALTER TABLE `{{prefix}}core_level_downloads`
    DROP PRIMARY KEY,
    ADD COLUMN `downloadID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`downloadID`),
    ADD KEY `idx_core_level_downloads_dedupe` (`levelID`, `ipHash`);
