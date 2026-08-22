-- PrimePrint Phase 3 Migration: Razorpay Payment Attributes & Print Job Payment Status
-- Safe idempotent DDL for existing deployments

USE `primeprint_db`;

-- Update print_jobs.payment_status to support 'paid' and 'completed'
ALTER TABLE `print_jobs` MODIFY COLUMN `payment_status` ENUM('pending', 'paid', 'completed', 'failed') NOT NULL DEFAULT 'pending';

-- Add method, failure_reason, captured_at columns to payments if not present
SET @col_method = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'method');
SET @sql_method = IF(@col_method = 0, 'ALTER TABLE `payments` ADD COLUMN `method` VARCHAR(50) NULL AFTER `status`', 'SELECT "method exists" AS status');
PREPARE stmt1 FROM @sql_method; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @col_fail = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'failure_reason');
SET @sql_fail = IF(@col_fail = 0, 'ALTER TABLE `payments` ADD COLUMN `failure_reason` TEXT NULL AFTER `method`', 'SELECT "failure_reason exists" AS status');
PREPARE stmt2 FROM @sql_fail; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @col_cap = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'captured_at');
SET @sql_cap = IF(@col_cap = 0, 'ALTER TABLE `payments` ADD COLUMN `captured_at` DATETIME NULL AFTER `failure_reason`', 'SELECT "captured_at exists" AS status');
PREPARE stmt3 FROM @sql_cap; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;
