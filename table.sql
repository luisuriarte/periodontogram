-- table.sql - Periodontogram V2 - Fresh install (MariaDB 10.2+ / OpenEMR 8.x)
-- Creates the complete schema from zero. Safe to run multiple times.
-- English source strings (list_options titles are translated via xl() in PHP).
--
-- For existing installations that already have the legacy schema, prefer
-- table_v2.sql (upgrade script with ADD COLUMN IF NOT EXISTS).
-- This file merges both: it is the canonical fresh-install schema.

SET NAMES utf8mb4;

-- =====================================================
-- Header
-- =====================================================
CREATE TABLE IF NOT EXISTS `form_periodontogram` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `date`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time of clinical examination',
    `pid`           BIGINT(20) NOT NULL COMMENT 'Patient Internal ID',
    `encounter`     BIGINT(20) NOT NULL COMMENT 'Encounter / Visit ID',
    `user`          VARCHAR(255) DEFAULT NULL COMMENT 'Performing Clinician Username',
    `groupname`     VARCHAR(255) DEFAULT NULL COMMENT 'Clinician Group Name',
    `facility`      VARCHAR(255) DEFAULT NULL COMMENT 'Facility Name',
    `facility_id`   INT DEFAULT 0           COMMENT 'Facility ID',
    `authorized`    TINYINT(4) DEFAULT 1 COMMENT 'Authorization status (1=Yes)',
    `activity`      TINYINT(4) DEFAULT 1 COMMENT 'Activity status (1=Active)',
    `diagnosis`     VARCHAR(50) DEFAULT NULL COMMENT 'Global Diagnosis (FK list_options perio_diagnosis)',
    `grade`         VARCHAR(5)  DEFAULT NULL COMMENT 'Progression Grade (FK list_options perio_grade)',
    `bop_total`     INT DEFAULT 0           COMMENT 'Total number of sites with bleeding',
    `bop_sites`     INT DEFAULT 0           COMMENT 'Total number of evaluable sites',
    `bop_percent`   DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Calculated Bleeding on Probing Percentage',
    `notes`         TEXT DEFAULT NULL       COMMENT 'General clinical assessment notes',
    `chart_version` TINYINT(1) DEFAULT 2 COMMENT '1=legacy V/P, 2=perio-tools 6 sites',
    `avg_pd`        DECIMAL(5,2) DEFAULT NULL COMMENT 'Mean probing depth mm',
    `avg_al`        DECIMAL(5,2) DEFAULT NULL COMMENT 'Mean attachment level mm',
    `pi_total`      INT DEFAULT 0           COMMENT 'Sites with plaque',
    `pi_sites`      INT DEFAULT 0           COMMENT 'Evaluable sites for plaque',
    `pi_percent`    DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Plaque index percent',
    `teeth_present` TINYINT DEFAULT 0 COMMENT 'Count of present teeth',
    `teeth_implant` TINYINT DEFAULT 0 COMMENT 'Count of implant units',
    KEY `idx_pid`        (`pid`),
    KEY `idx_encounter`  (`encounter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores header metadata for periodontal examinations';

-- =====================================================
-- Legacy detail (kept for dual-write / migration)
-- =====================================================
CREATE TABLE IF NOT EXISTS `form_periodontogram_detail` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `periodontogram_id`   INT NOT NULL COMMENT 'FK referencing form_periodontogram.id',
    `pid`                 BIGINT(20) NOT NULL COMMENT 'Patient ID',
    `encounter`           BIGINT(20) NOT NULL COMMENT 'Encounter ID',
    `tooth_fdi`           TINYINT(3) UNSIGNED NOT NULL COMMENT 'FDI Tooth Number (11-48)',
    `surface`             ENUM('V','P') NOT NULL COMMENT 'Clinical Surface (V=Facial/Buccal, P=Lingual/Palatal)',
    `point`               TINYINT(1) NOT NULL COMMENT 'Measurement Point (1=Mesial, 2=Mid, 3=Distal)',
    `probing_depth`       TINYINT(3) UNSIGNED DEFAULT NULL COMMENT 'Probing Depth (PD) in mm',
    `recession`           TINYINT(3) UNSIGNED DEFAULT NULL COMMENT 'Gingival Margin/Recession (GM) in mm',
    `bleeding`            TINYINT(1) DEFAULT 0    COMMENT 'Bleeding on Probing (BOP) (0=No, 1=Yes)',
    `suppuration`         TINYINT(1) DEFAULT 0    COMMENT 'Presence of Suppuration (0=No, 1=Yes)',
    `furcation`           TINYINT(1) DEFAULT NULL COMMENT 'Furcation Degree (0=N/A, 1=I, 2=II, 3=III)',
    `mobility`            TINYINT(1) DEFAULT NULL COMMENT 'Miller Mobility Class (0-3)',
    KEY `idx_perio_id`        (`periodontogram_id`),
    KEY `idx_pid_encounter`   (`pid`, `encounter`),
    CONSTRAINT `fk_perio_detail` FOREIGN KEY (`periodontogram_id`)
        REFERENCES `form_periodontogram` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Legacy site-specific measurements (dual-write target)';

-- =====================================================
-- V2: Tooth table (one row per tooth)
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
-- V2: Site table (one row per site, 6 per tooth)
-- site: 1=MB, 2=B, 3=DB, 4=DL, 5=L, 6=ML (occlusal view, perio-tools order)
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
-- List options (idempotent inserts, no DELETE)
-- Titles are English source; PHP uses xl() to translate.
-- =====================================================
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_furcation', '0', 'N/A', 10, 1, 0.0, '', '', 'ICD10:K05.6', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_furcation' AND `option_id`='0');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_furcation', '1', 'Grade I', 20, 0, 0.0, '', '', 'ICD10:K05.6', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_furcation' AND `option_id`='1');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_furcation', '2', 'Grade II', 30, 0, 0.0, '', '', 'ICD10:K05.6', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_furcation' AND `option_id`='2');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_furcation', '3', 'Grade III', 40, 0, 0.0, '', '', 'ICD10:K05.6', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_furcation' AND `option_id`='3');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_mobility', '0', 'Grade 0 - No mobility', 10, 1, 0.0, '', '', 'ICD10:K08.8', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_mobility' AND `option_id`='0');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_mobility', '1', 'Grade I - < 1mm horizontal', 20, 0, 0.0, '', '', 'ICD10:K08.8', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_mobility' AND `option_id`='1');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_mobility', '2', 'Grade II - > 1mm horizontal', 30, 0, 0.0, '', '', 'ICD10:K08.8', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_mobility' AND `option_id`='2');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_mobility', '3', 'Grade III - Vertical mobility', 40, 0, 0.0, '', '', 'ICD10:K08.8', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_mobility' AND `option_id`='3');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'healthy', 'Periodontal Health', 5, 0, 0.0, '', '', 'CDT:D4910', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='healthy');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'gingivitis', 'Gingivitis', 10, 1, 0.0, '', '', 'CDT:D4340', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='gingivitis');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'perio_st1', 'Periodontitis Stage I', 20, 0, 0.0, '', '', 'CDT:D4341', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='perio_st1');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'perio_st2', 'Periodontitis Stage II', 30, 0, 0.0, '', '', 'CDT:D4341', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='perio_st2');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'perio_st3', 'Periodontitis Stage III', 40, 0, 0.0, '', '', 'CDT:D4341', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='perio_st3');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'perio_st4', 'Periodontitis Stage IV', 50, 0, 0.0, '', '', 'CDT:D4341', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='perio_st4');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'perio_agr', 'Aggressive Periodontitis', 60, 0, 0.0, '', '', 'CDT:D4341', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='perio_agr');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_diagnosis', 'none', 'No Diagnosis', 70, 0, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_diagnosis' AND `option_id`='none');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_grade', 'A', 'Grade A - Slow progression', 10, 1, 0.0, '', '', 'ICD10:K05.30', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_grade' AND `option_id`='A');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_grade', 'B', 'Grade B - Moderate progression', 20, 0, 0.0, '', '', 'ICD10:K05.31', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_grade' AND `option_id`='B');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_grade', 'C', 'Grade C - Rapid progression', 30, 0, 0.0, '', '', 'ICD10:K05.32', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_grade' AND `option_id`='C');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'present', 'Present', 10, 1, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='present');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'missing', 'Missing', 20, 0, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='missing');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`)
SELECT 'perio_tooth_status', 'implant', 'Implant', 30, 0, 0.0, '', '', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `list_options` WHERE `list_id`='perio_tooth_status' AND `option_id`='implant');
