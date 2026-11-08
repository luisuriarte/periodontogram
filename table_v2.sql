-- table_v2.sql - Periodontogram V2 migration (MariaDB)
-- Compatible with MariaDB 10.2+ / OpenEMR 8.x
-- Uses native MariaDB ADD COLUMN IF NOT EXISTS (idempotent, no stored procedure).
-- If you are on MySQL (not MariaDB), use table_v2_mysql.sql instead.
-- Run once via phpMyAdmin SQL tab or: mysql -u root -p openemr < table_v2.sql

SET NAMES utf8mb4;

-- =====================================================
-- 1) Header: new columns on form_periodontogram
-- =====================================================
ALTER TABLE `form_periodontogram`
  ADD COLUMN IF NOT EXISTS `chart_version` TINYINT(1) DEFAULT 2 COMMENT '1=legacy V/P, 2=perio-tools 6 sites',
  ADD COLUMN IF NOT EXISTS `avg_pd` DECIMAL(5,2) DEFAULT NULL COMMENT 'Mean probing depth mm',
  ADD COLUMN IF NOT EXISTS `avg_al` DECIMAL(5,2) DEFAULT NULL COMMENT 'Mean attachment level mm',
  ADD COLUMN IF NOT EXISTS `pi_total` INT DEFAULT 0 COMMENT 'Sites with plaque',
  ADD COLUMN IF NOT EXISTS `pi_sites` INT DEFAULT 0 COMMENT 'Evaluable sites for plaque',
  ADD COLUMN IF NOT EXISTS `pi_percent` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Plaque index percent',
  ADD COLUMN IF NOT EXISTS `teeth_present` TINYINT DEFAULT 0 COMMENT 'Count of present teeth',
  ADD COLUMN IF NOT EXISTS `teeth_implant` TINYINT DEFAULT 0 COMMENT 'Count of implant units';

-- =====================================================
-- 2) Tooth table (one row per tooth)
-- =====================================================
CREATE TABLE IF NOT EXISTS `form_periodontogram_tooth` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `periodontogram_id` INT NOT NULL COMMENT 'FK form_periodontogram.id',
    `pid` BIGINT(20) NOT NULL,
    `encounter` BIGINT(20) NOT NULL,
    `tooth_fdi` TINYINT(3) UNSIGNED NOT NULL COMMENT 'FDI 11..48',
    `status` ENUM('present','missing','implant') NOT NULL DEFAULT 'present',
    `mobility` TINYINT(1) UNSIGNED DEFAULT NULL COMMENT 'Miller 0-3',
    `furcation_buccal` TINYINT(1) UNSIGNED DEFAULT NULL COMMENT 'Hamp 0-3 buccal/vestibular',
    `furcation_lingual` TINYINT(1) UNSIGNED DEFAULT NULL COMMENT 'Hamp 0-3 lingual',
    `furcation_palatal` TINYINT(1) UNSIGNED DEFAULT NULL COMMENT 'Hamp 0-3 palatal (upper only)',
    `notes` VARCHAR(255) DEFAULT NULL,
    KEY `idx_perio` (`periodontogram_id`),
    KEY `idx_pid_enc` (`pid`, `encounter`),
    UNIQUE KEY `uq_perio_tooth` (`periodontogram_id`, `tooth_fdi`),
    CONSTRAINT `fk_perio_tooth` FOREIGN KEY (`periodontogram_id`)
        REFERENCES `form_periodontogram` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tooth-level status: present/missing/implant, mobility, furcation';

-- =====================================================
-- 3) Site table (one row per site, 6 per tooth)
--    site: 1=MB, 2=B, 3=DB, 4=DL, 5=L, 6=ML (occlusal view, perio-tools order)
-- =====================================================
CREATE TABLE IF NOT EXISTS `form_periodontogram_site` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `periodontogram_id` INT NOT NULL,
    `tooth_id` INT NOT NULL COMMENT 'FK form_periodontogram_tooth.id',
    `pid` BIGINT(20) NOT NULL,
    `encounter` BIGINT(20) NOT NULL,
    `tooth_fdi` TINYINT(3) UNSIGNED NOT NULL,
    `site` TINYINT(1) UNSIGNED NOT NULL COMMENT '1=MB, 2=B, 3=DB, 4=DL, 5=L, 6=ML',
    `probing_depth` TINYINT(3) UNSIGNED DEFAULT NULL COMMENT 'PD mm 0..12',
    `gingival_margin` SMALLINT DEFAULT NULL COMMENT 'GM mm signed -12..+12 (+hyperplasia, -recession)',
    `attachment_level` SMALLINT DEFAULT NULL COMMENT 'AL = PD - GM (computed in PHP)',
    `bleeding` TINYINT(1) DEFAULT 0 COMMENT 'BOP 0/1',
    `plaque` TINYINT(1) DEFAULT 0 COMMENT 'Plaque / PI 0/1',
    `suppuration` TINYINT(1) DEFAULT 0 COMMENT 'Suppuration 0/1',
    KEY `idx_perio` (`periodontogram_id`),
    KEY `idx_tooth_site` (`tooth_id`, `site`),
    UNIQUE KEY `uq_perio_tooth_site` (`periodontogram_id`, `tooth_fdi`, `site`),
    CONSTRAINT `fk_perio_site_perio` FOREIGN KEY (`periodontogram_id`)
        REFERENCES `form_periodontogram` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_perio_site_tooth` FOREIGN KEY (`tooth_id`)
        REFERENCES `form_periodontogram_tooth` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='6 sites per tooth: MB,B,DB,DL,L,ML - PD/GM/AL + BOP/PI/Supp';

-- =====================================================
-- 4) List options for tooth status (idempotent)
-- =====================================================
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'present', 'Present', 10, 1, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='present');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'missing', 'Missing', 20, 0, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='missing');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'implant', 'Implant', 30, 0, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='implant');

-- =====================================================
-- 5) Verification (optional, not executed)
-- =====================================================
-- SHOW COLUMNS FROM form_periodontogram;
-- SHOW TABLES LIKE 'form_periodontogram_%';
