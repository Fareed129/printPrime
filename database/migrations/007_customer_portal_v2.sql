-- PrimePrint Migration 007: Customer Portal V2 Support
-- Multi-file uploads, Cash Payment approval workflow, ID Card mode metadata, and child files table

-- 1. Expand status ENUM on print_jobs to include cash approval states
ALTER TABLE `print_jobs` 
  MODIFY COLUMN `status` ENUM(
    'UPLOADED', 'PAYMENT_PENDING', 'PAID', 'QUEUED', 
    'DOWNLOADING', 'PRINTING', 'PRINTED', 'FAILED', 
    'CANCELLED', 'AWAITING_SHOP_APPROVAL', 'REJECTED'
  ) NOT NULL DEFAULT 'UPLOADED';

-- 2. Expand payment_status ENUM on print_jobs to include cash pending and rejected
ALTER TABLE `print_jobs` 
  MODIFY COLUMN `payment_status` ENUM(
    'pending', 'paid', 'completed', 'failed', 'pending_cash', 'rejected'
  ) NOT NULL DEFAULT 'pending';

-- 3. Add cash workflow and ID card columns to print_jobs if not already present
SET @dbname = DATABASE();
SET @tablename = "print_jobs";

-- payment_method
SET @columnname = "payment_method";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `payment_method` VARCHAR(20) NOT NULL DEFAULT 'ONLINE' AFTER `payment_status`;"
));
PREPARE stmt1 FROM @preparedStatement;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- cash_approved_by
SET @columnname = "cash_approved_by";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `cash_approved_by` INT UNSIGNED NULL AFTER `payment_method`;"
));
PREPARE stmt2 FROM @preparedStatement;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- cash_approved_at
SET @columnname = "cash_approved_at";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `cash_approved_at` DATETIME NULL AFTER `cash_approved_by`;"
));
PREPARE stmt3 FROM @preparedStatement;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- cash_rejected_by
SET @columnname = "cash_rejected_by";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `cash_rejected_by` INT UNSIGNED NULL AFTER `cash_approved_at`;"
));
PREPARE stmt4 FROM @preparedStatement;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- cash_rejected_at
SET @columnname = "cash_rejected_at";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `cash_rejected_at` DATETIME NULL AFTER `cash_rejected_by`;"
));
PREPARE stmt5 FROM @preparedStatement;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- cash_rejection_reason
SET @columnname = "cash_rejection_reason";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `cash_rejection_reason` VARCHAR(255) NULL AFTER `cash_rejected_at`;"
));
PREPARE stmt6 FROM @preparedStatement;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

-- is_id_card
SET @columnname = "is_id_card";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `is_id_card` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cash_rejection_reason`;"
));
PREPARE stmt7 FROM @preparedStatement;
EXECUTE stmt7;
DEALLOCATE PREPARE stmt7;

-- selected_pages
SET @columnname = "selected_pages";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE `print_jobs` ADD COLUMN `selected_pages` TEXT NULL AFTER `is_id_card`;"
));
PREPARE stmt8 FROM @preparedStatement;
EXECUTE stmt8;
DEALLOCATE PREPARE stmt8;

-- 4. Create print_job_files child table for multi-file print orders
CREATE TABLE IF NOT EXISTS `print_job_files` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(50) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `page_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `selected_pages` VARCHAR(255) NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pjf_job_id` (`job_id`),
  CONSTRAINT `fk_pjf_job_id` FOREIGN KEY (`job_id`) REFERENCES `print_jobs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
