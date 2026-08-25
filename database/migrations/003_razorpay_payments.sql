-- Migration 003: Razorpay Payments and Diagnostic Logging
-- Safe & idempotent for both new and existing databases

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT UNSIGNED NOT NULL,
  `shop_id` INT UNSIGNED NOT NULL,
  `razorpay_order_id` VARCHAR(100) NOT NULL,
  `razorpay_payment_id` VARCHAR(100) NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `status` ENUM('created', 'captured', 'failed') NOT NULL DEFAULT 'created',
  `method` VARCHAR(50) NULL,
  `failure_reason` TEXT NULL,
  `captured_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_payments_job` (`job_id`),
  INDEX `idx_payments_shop` (`shop_id`),
  INDEX `idx_payments_order` (`razorpay_order_id`),
  INDEX `idx_payments_payment` (`razorpay_payment_id`),
  CONSTRAINT `fk_payments_job_id` FOREIGN KEY (`job_id`) REFERENCES `print_jobs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
