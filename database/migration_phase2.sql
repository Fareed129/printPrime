-- PrimePrint Phase 2 Migration: Public Order Tokens
-- Safe idempotent DDL for existing deployments

USE `primeprint_db`;

-- Add public_token column and unique index to print_jobs if not already present
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'print_jobs' 
      AND COLUMN_NAME = 'public_token'
);

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `print_jobs` ADD COLUMN `public_token` VARCHAR(64) NOT NULL UNIQUE AFTER `id`, ADD INDEX `idx_jobs_token` (`public_token`)', 
    'SELECT "public_token column already exists" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
