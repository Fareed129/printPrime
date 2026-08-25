-- Migration 001: Initial Core Schema (Shops, Users, Printers, Print Agents, Print Jobs, Invoices)
-- Safe & idempotent for both new and existing databases

CREATE TABLE IF NOT EXISTS `shops` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `owner_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `address` TEXT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_shop_slug` (`slug`),
  INDEX `idx_shop_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'shop') NOT NULL DEFAULT 'shop',
  `shop_id` INT UNSIGNED NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_email` (`email`),
  INDEX `idx_user_role` (`role`),
  INDEX `idx_user_shop` (`shop_id`),
  CONSTRAINT `fk_users_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `printers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `shop_id` INT UNSIGNED NOT NULL,
  `printer_name` VARCHAR(150) NOT NULL,
  `printer_identifier` VARCHAR(150) NULL,
  `status` ENUM('online', 'offline', 'idle', 'printing') NOT NULL DEFAULT 'offline',
  `last_seen` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_printers_shop` (`shop_id`),
  CONSTRAINT `fk_printers_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `print_agents` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `shop_id` INT UNSIGNED NOT NULL,
  `agent_name` VARCHAR(100) NOT NULL,
  `agent_token_hash` VARCHAR(255) NOT NULL,
  `status` ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
  `last_seen` DATETIME NULL,
  `version` VARCHAR(30) NOT NULL DEFAULT '1.0.0',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_agents_shop` (`shop_id`),
  CONSTRAINT `fk_agents_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `print_jobs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `public_token` VARCHAR(64) NOT NULL UNIQUE,
  `shop_id` INT UNSIGNED NOT NULL,
  `printer_id` INT UNSIGNED NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `stored_file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(50) NOT NULL,
  `page_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `copies` INT UNSIGNED NOT NULL DEFAULT 1,
  `paper_size` VARCHAR(20) NOT NULL DEFAULT 'A4',
  `color_mode` VARCHAR(20) NOT NULL DEFAULT 'BW',
  `side_mode` VARCHAR(20) NOT NULL DEFAULT 'single',
  `amount` DECIMAL(10, 2) NOT NULL DEFAULT '0.00',
  `status` ENUM('UPLOADED', 'PAYMENT_PENDING', 'PAID', 'QUEUED', 'DOWNLOADING', 'PRINTING', 'PRINTED', 'FAILED', 'CANCELLED') NOT NULL DEFAULT 'UPLOADED',
  `payment_status` ENUM('pending', 'paid', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `agent_job_id` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `printed_at` DATETIME NULL,
  INDEX `idx_jobs_token` (`public_token`),
  INDEX `idx_jobs_shop` (`shop_id`),
  INDEX `idx_jobs_printer` (`printer_id`),
  INDEX `idx_jobs_status` (`status`),
  INDEX `idx_jobs_created` (`created_at`),
  CONSTRAINT `fk_jobs_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_jobs_printer_id` FOREIGN KEY (`printer_id`) REFERENCES `printers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT UNSIGNED NOT NULL,
  `shop_id` INT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `amount` DECIMAL(10, 2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_invoices_job` (`job_id`),
  INDEX `idx_invoices_shop` (`shop_id`),
  CONSTRAINT `fk_invoices_job_id` FOREIGN KEY (`job_id`) REFERENCES `print_jobs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invoices_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
