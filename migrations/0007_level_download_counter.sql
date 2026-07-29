-- LevelRepository intentionally counts at most two download events per level/IP hash,
-- matching the legacy GDPS request pattern while avoiding unlimited counter inflation.
-- Some earlier 0003 revisions used (levelID, ipHash) as the primary key. Newer installs
-- already use an auto-increment `id` primary key, so this migration must be safe for both.

SET @core_downloads_has_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '{{prefix}}core_level_downloads'
      AND COLUMN_NAME = 'id'
);

SET @core_downloads_sql = IF(
    @core_downloads_has_id = 0,
    'ALTER TABLE `{{prefix}}core_level_downloads` DROP PRIMARY KEY, ADD COLUMN `id` BIGINT UNSIGNED NULL FIRST',
    'SELECT 1'
);
PREPARE core_downloads_stmt FROM @core_downloads_sql;
EXECUTE core_downloads_stmt;
DEALLOCATE PREPARE core_downloads_stmt;

SET @core_downloads_row = 0;
SET @core_downloads_sql = IF(
    @core_downloads_has_id = 0,
    'UPDATE `{{prefix}}core_level_downloads` SET `id` = (@core_downloads_row := @core_downloads_row + 1) ORDER BY `levelID`, `ipHash`, `downloadedAt`',
    'SELECT 1'
);
PREPARE core_downloads_stmt FROM @core_downloads_sql;
EXECUTE core_downloads_stmt;
DEALLOCATE PREPARE core_downloads_stmt;

SET @core_downloads_sql = IF(
    @core_downloads_has_id = 0,
    'ALTER TABLE `{{prefix}}core_level_downloads` MODIFY `id` BIGINT UNSIGNED NOT NULL, ADD PRIMARY KEY (`id`), ADD KEY `idx_core_level_downloads_level_ip` (`levelID`, `ipHash`)',
    'SELECT 1'
);
PREPARE core_downloads_stmt FROM @core_downloads_sql;
EXECUTE core_downloads_stmt;
DEALLOCATE PREPARE core_downloads_stmt;

SET @core_downloads_sql = IF(
    @core_downloads_has_id = 0,
    'ALTER TABLE `{{prefix}}core_level_downloads` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE core_downloads_stmt FROM @core_downloads_sql;
EXECUTE core_downloads_stmt;
DEALLOCATE PREPARE core_downloads_stmt;
