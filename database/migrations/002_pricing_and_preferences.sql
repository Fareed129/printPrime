-- Migration 002: Shop Dynamic Pricing Configuration
-- Safe & idempotent for both new and existing databases

CREATE TABLE IF NOT EXISTS `pricing` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `shop_id` INT UNSIGNED NOT NULL,
  `paper_size` ENUM('A4', 'A3', 'Legal') NOT NULL DEFAULT 'A4',
  `color_mode` ENUM('BW', 'COLOR') NOT NULL DEFAULT 'BW',
  `side_mode` ENUM('single', 'double') NOT NULL DEFAULT 'single',
  `price_per_page` DECIMAL(8, 2) NOT NULL DEFAULT '2.00',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_shop_pricing_tier` (`shop_id`, `paper_size`, `color_mode`, `side_mode`),
  INDEX `idx_pricing_shop` (`shop_id`),
  CONSTRAINT `fk_pricing_shop_id` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
