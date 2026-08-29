-- PrimePrint Migration 005: Per-Shop Direct Razorpay Credentials
-- Allows physical print shops to receive customer payments directly into their own bank accounts

SET @dbname = DATABASE();
SET @tablename = "shops";
SET @columnname = "razorpay_key_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `shops` 
     ADD COLUMN `razorpay_key_id` VARCHAR(100) NULL AFTER `setup_fee_paid`,
     ADD COLUMN `razorpay_key_secret` VARCHAR(100) NULL AFTER `razorpay_key_id`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
