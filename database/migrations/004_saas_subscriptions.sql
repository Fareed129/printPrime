-- PrimePrint Migration 004: SaaS Subscriptions & Licensing Suite
-- Enables Setup Fees, 3-Month Recurring Subscriptions, Self-Service Renewals, and Ledger

-- 1. Create Subscription Plans Table
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `duration_months` INT UNSIGNED NOT NULL DEFAULT 3,
  `setup_fee` DECIMAL(10,2) NOT NULL DEFAULT 1999.00,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 1499.00,
  `description` TEXT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create Shop Subscription Payment Ledger Table
CREATE TABLE IF NOT EXISTS `shop_subscriptions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `shop_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('razorpay', 'manual_offline', 'cash', 'upi') DEFAULT 'razorpay',
  `razorpay_payment_id` VARCHAR(100) NULL,
  `razorpay_order_id` VARCHAR(100) NULL,
  `starts_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sub_shop` (`shop_id`),
  INDEX `idx_sub_order` (`razorpay_order_id`),
  CONSTRAINT `fk_shop_subscriptions_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Seed Default Subscription Plans
INSERT INTO `subscription_plans` (`id`, `name`, `duration_months`, `setup_fee`, `price`, `description`, `is_default`, `status`)
VALUES 
  (1, '3-Month Quarterly Pro', 3, 1999.00, 1499.00, 'Standard 3-month recurring subscription plan for print shops. Includes unlimited customer QR uploads, dynamic pricing, and desktop auto-printing.', 1, 'active'),
  (2, 'Annual Elite (12 Months)', 12, 1999.00, 4999.00, 'Best value annual subscription plan. Save 20% compared to quarterly renewals with dedicated support.', 0, 'active')
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `duration_months` = VALUES(`duration_months`),
  `setup_fee` = VALUES(`setup_fee`),
  `price` = VALUES(`price`);

-- 4. Extend shops table with subscription fields (if not already added)
SET @dbname = DATABASE();
SET @tablename = "shops";
SET @columnname = "subscription_status";
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
     ADD COLUMN `plan_id` INT UNSIGNED NULL AFTER `status`,
     ADD COLUMN `plan_name` VARCHAR(100) DEFAULT '3-Month Quarterly Pro' AFTER `plan_id`,
     ADD COLUMN `subscription_status` ENUM('trial', 'active', 'expiring', 'expired', 'suspended') DEFAULT 'active' AFTER `plan_name`,
     ADD COLUMN `subscription_started_at` DATETIME NULL AFTER `subscription_status`,
     ADD COLUMN `subscription_expires_at` DATETIME NULL AFTER `subscription_started_at`,
     ADD COLUMN `setup_fee_paid` TINYINT(1) DEFAULT 1 AFTER `subscription_expires_at`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 5. Initialize existing shops with active 90-day subscription from today
UPDATE `shops` 
SET 
  `plan_id` = 1,
  `plan_name` = '3-Month Quarterly Pro',
  `subscription_status` = 'active',
  `subscription_started_at` = COALESCE(`subscription_started_at`, NOW()),
  `subscription_expires_at` = COALESCE(`subscription_expires_at`, DATE_ADD(NOW(), INTERVAL 90 DAY)),
  `setup_fee_paid` = 1
WHERE `subscription_expires_at` IS NULL;
