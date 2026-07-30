CREATE TABLE IF NOT EXISTS `{{prefix}}core_registration_attempts` (
    `attemptID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ipHash` CHAR(64) NOT NULL,
    `subnetHash` CHAR(64) NOT NULL,
    `success` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `reason` VARCHAR(32) NOT NULL DEFAULT '',
    `attemptedAt` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`attemptID`),
    KEY `idx_registration_ip_time` (`ipHash`, `attemptedAt`),
    KEY `idx_registration_subnet_time` (`subnetHash`, `attemptedAt`),
    KEY `idx_registration_time` (`attemptedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
