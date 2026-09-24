-- =================================================================
-- XRF Explorer 7000 Database Migration for labmineral
-- Target Database: MySQL / MariaDB (Laragon)
-- =================================================================

-- 1. Table for XRF Test/Measurement Header
CREATE TABLE IF NOT EXISTS `xrf_measurements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `device_id` VARCHAR(50) NOT NULL DEFAULT 'XRF-7000',
    `db_source` VARCHAR(50) NOT NULL COMMENT 'metal.db, alloy.db, or mineral.db',
    `report_id` INT NOT NULL COMMENT 'HistoryReportID from XRF SQLite',
    `sample_name` VARCHAR(100) NOT NULL,
    `sample_supplier` VARCHAR(100) DEFAULT NULL,
    `test_date` DATETIME DEFAULT NULL,
    `timestamp_ms` BIGINT DEFAULT NULL,
    `test_time` INT DEFAULT NULL COMMENT 'Test duration in seconds',
    `tub_voltage` FLOAT DEFAULT NULL,
    `tub_current` FLOAT DEFAULT NULL,
    `work_curve_name` VARCHAR(100) DEFAULT NULL COMMENT 'Mode e.g. Titanium 1, AuFp, Mineral Mode',
    `grade` VARCHAR(100) DEFAULT NULL COMMENT 'Matched Alloy Grade e.g. SS316, Ti-6Al-4V',
    `operator` VARCHAR(50) DEFAULT NULL,
    `gps` VARCHAR(100) DEFAULT '(0.0,0.0)',
    `longitude` DOUBLE DEFAULT 0,
    `latitude` DOUBLE DEFAULT 0,
    `altitude` DOUBLE DEFAULT 0,
    `cps` INT DEFAULT 0,
    `counts` INT DEFAULT 0,
    `temperature` FLOAT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_device_report` (`device_id`, `db_source`, `report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table for XRF Element Concentration Breakdown
CREATE TABLE IF NOT EXISTS `xrf_measurement_elements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `measurement_id` INT NOT NULL,
    `element_name` VARCHAR(10) NOT NULL COMMENT 'e.g. Au, Fe, Cu, Ni, Zn, Ti',
    `concentration` DOUBLE NOT NULL DEFAULT 0 COMMENT 'Element Concentration % or ppm',
    `element_error` DOUBLE DEFAULT 0 COMMENT 'Margin of Error +/-',
    `unit` VARCHAR(20) DEFAULT '%' COMMENT 'Unit e.g. %, ppm',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`measurement_id`) REFERENCES `xrf_measurements`(`id`) ON DELETE CASCADE,
    INDEX `idx_element_search` (`measurement_id`, `element_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
