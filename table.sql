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
    KEY `idx_pid`        (`pid`),
    KEY `idx_encounter`  (`encounter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores header metadata for periodontal examinations';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores site-specific measurements for periodontal exams';

DELETE FROM `list_options` WHERE `list_id` IN ('perio_furcation', 'perio_mobility', 'perio_diagnosis', 'perio_grade');

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`) VALUES
('perio_furcation', '0', 'N/A', 10, 1, 0.0, '', '', 'ICD10:K05.6', 1),
('perio_furcation', '1', 'Grade I', 20, 0, 0.0, '', '', 'ICD10:K05.6', 1),
('perio_furcation', '2', 'Grade II', 30, 0, 0.0, '', '', 'ICD10:K05.6', 1),
('perio_furcation', '3', 'Grade III', 40, 0, 0.0, '', '', 'ICD10:K05.6', 1);

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`) VALUES
('perio_mobility', '0', 'Grade 0 - No mobility', 10, 1, 0.0, '', '', 'ICD10:K08.8', 1),
('perio_mobility', '1', 'Grade I - < 1mm horizontal', 20, 0, 0.0, '', '', 'ICD10:K08.8', 1),
('perio_mobility', '2', 'Grade II - > 1mm horizontal', 30, 0, 0.0, '', '', 'ICD10:K08.8', 1),
('perio_mobility', '3', 'Grade III - Vertical mobility', 40, 0, 0.0, '', '', 'ICD10:K08.8', 1);

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`) VALUES
('perio_diagnosis', 'healthy', 'Periodontal Health', 5, 0, 0.0, '', '', 'CDT:D4910', 1),
('perio_diagnosis', 'gingivitis', 'Gingivitis', 10, 1, 0.0, '', '', 'CDT:D4340', 1),
('perio_diagnosis', 'perio_st1', 'Periodontitis Stage I', 20, 0, 0.0, '', '', 'CDT:D4341', 1),
('perio_diagnosis', 'perio_st2', 'Periodontitis Stage II', 30, 0, 0.0, '', '', 'CDT:D4341', 1),
('perio_diagnosis', 'perio_st3', 'Periodontitis Stage III', 40, 0, 0.0, '', '', 'CDT:D4341', 1),
('perio_diagnosis', 'perio_st4', 'Periodontitis Stage IV', 50, 0, 0.0, '', '', 'CDT:D4341', 1),
('perio_diagnosis', 'perio_agr', 'Aggressive Periodontitis', 60, 0, 0.0, '', '', 'CDT:D4341', 1),
('perio_diagnosis', 'none', 'No Diagnosis', 70, 0, 0.0, '', '', '', 1);

INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`, `activity`) VALUES
('perio_grade', 'A', 'Grade A - Slow progression', 10, 1, 0.0, '', '', 'ICD10:K05.30', 1),
('perio_grade', 'B', 'Grade B - Moderate progression', 20, 0, 0.0, '', '', 'ICD10:K05.31', 1),
('perio_grade', 'C', 'Grade C - Rapid progression', 30, 0, 0.0, '', '', 'ICD10:K05.32', 1);
