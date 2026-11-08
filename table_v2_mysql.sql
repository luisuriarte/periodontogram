-- table_v2_mysql.sql - Periodontogram V2 migration (MySQL 8)
-- For MySQL only. MariaDB users: use table_v2.sql instead.
-- This file uses INFORMATION_SCHEMA + PREPARE to add columns idempotently
-- without stored procedures, so it can be run statement-by-statement.
-- Run each block separately in phpMyAdmin / mysql CLI.

SET NAMES utf8mb4;

-- =====================================================
-- 1) Header columns (run each block; ignore error if column exists)
-- =====================================================
-- Option A (fresh install, run once):
-- ALTER TABLE `form_periodontogram`
--   ADD COLUMN `chart_version` TINYINT(1) DEFAULT 2,
--   ADD COLUMN `avg_pd` DECIMAL(5,2) DEFAULT NULL,
--   ADD COLUMN `avg_al` DECIMAL(5,2) DEFAULT NULL,
--   ADD COLUMN `pi_total` INT DEFAULT 0,
--   ADD COLUMN `pi_sites` INT DEFAULT 0,
--   ADD COLUMN `pi_percent` DECIMAL(5,2) DEFAULT 0.00,
--   ADD COLUMN `teeth_present` TINYINT DEFAULT 0,
--   ADD COLUMN `teeth_implant` TINYINT DEFAULT 0;

-- Option B (idempotent via INFORMATION_SCHEMA, one per column):
-- Run the two statements below 8 times, changing the column name each time:
--   SET @col = 'chart_version';
--   SET @ddl = CONCAT('ALTER TABLE `form_periodontogram` ADD COLUMN `', @col, '` TINYINT(1) DEFAULT 2');
--   SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='form_periodontogram' AND COLUMN_NAME=@col);
--   IF @exists = 0 THEN PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt; END IF;

CREATE TABLE IF NOT EXISTS `form_periodontogram_tooth` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `periodontogram_id` INT NOT NULL,
    `pid` BIGINT(20) NOT NULL,
    `encounter` BIGINT(20) NOT NULL,
    `tooth_fdi` TINYINT(3) UNSIGNED NOT NULL,
    `status` ENUM('present','missing','implant') NOT NULL DEFAULT 'present',
    `mobility` TINYINT(1) UNSIGNED DEFAULT NULL,
    `furcation_buccal` TINYINT(1) UNSIGNED DEFAULT NULL,
    `furcation_lingual` TINYINT(1) UNSIGNED DEFAULT NULL,
    `furcation_palatal` TINYINT(1) UNSIGNED DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    KEY `idx_perio` (`periodontogram_id`),
    KEY `idx_pid_enc` (`pid`, `encounter`),
    UNIQUE KEY `uq_perio_tooth` (`periodontogram_id`, `tooth_fdi`),
    CONSTRAINT `fk_perio_tooth` FOREIGN KEY (`periodontogram_id`)
        REFERENCES `form_periodontogram` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `form_periodontogram_site` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `periodontogram_id` INT NOT NULL,
    `tooth_id` INT NOT NULL,
    `pid` BIGINT(20) NOT NULL,
    `encounter` BIGINT(20) NOT NULL,
    `tooth_fdi` TINYINT(3) UNSIGNED NOT NULL,
    `site` TINYINT(1) UNSIGNED NOT NULL,
    `probing_depth` TINYINT(3) UNSIGNED DEFAULT NULL,
    `gingival_margin` SMALLINT DEFAULT NULL,
    `attachment_level` SMALLINT DEFAULT NULL,
    `bleeding` TINYINT(1) DEFAULT 0,
    `plaque` TINYINT(1) DEFAULT 0,
    `suppuration` TINYINT(1) DEFAULT 0,
    KEY `idx_perio` (`periodontogram_id`),
    KEY `idx_tooth_site` (`tooth_id`, `site`),
    UNIQUE KEY `uq_perio_tooth_site` (`periodontogram_id`, `tooth_fdi`, `site`),
    CONSTRAINT `fk_perio_site_perio` FOREIGN KEY (`periodontogram_id`)
        REFERENCES `form_periodontogram` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_perio_site_tooth` FOREIGN KEY (`tooth_id`)
        REFERENCES `form_periodontogram_tooth` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'present', 'Present', 10, 1, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='present');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'missing', 'Missing', 20, 0, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='missing');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'implant', 'Implant', 30, 0, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='implant');
