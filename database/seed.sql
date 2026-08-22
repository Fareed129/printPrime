-- PrimePrint Seed Data (Development Environment)
-- Super Admin: admin@primeprint.local / ChangeMe123!
-- Demo Shop: ABC Digital Printing (slug: abc-digital-printing)
-- Shop User: shop@abcprinting.local / ChangeMe123!

USE `primeprint_db`;

-- 1. Insert Demo Shop
INSERT INTO `shops` (`id`, `name`, `slug`, `owner_name`, `phone`, `email`, `address`, `status`)
VALUES (1, 'ABC Digital Printing', 'abc-digital-printing', 'Ramesh Kumar', '+91 9876543210', 'shop@abcprinting.local', 'Shop #4, Metro Complex, MG Road, Bangalore', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 2. Insert Super Admin and Shop Users (Password: ChangeMe123!)
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `shop_id`, `status`)
VALUES 
(1, 'Super Admin', 'admin@primeprint.local', '$2y$10$yabUt88feglx7bLICUQx2..FTSbLe3Fwy2JfZSp/tFWKAuYyoWqK.', 'admin', NULL, 'active'),
(2, 'ABC Shop Admin', 'shop@abcprinting.local', '$2y$10$yabUt88feglx7bLICUQx2..FTSbLe3Fwy2JfZSp/tFWKAuYyoWqK.', 'shop', 1, 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. Insert Demo Pricing Tiers for ABC Digital Printing
-- A4 B&W single = ₹2.00
-- A4 B&W double = ₹3.00
-- A4 Color single = ₹10.00
-- A4 Color double = ₹15.00
-- A3 B&W single = ₹5.00
-- A3 Color single = ₹20.00
INSERT INTO `pricing` (`shop_id`, `paper_size`, `color_mode`, `side_mode`, `price_per_page`, `active`)
VALUES
(1, 'A4', 'BW', 'single', 2.00, 1),
(1, 'A4', 'BW', 'double', 3.00, 1),
(1, 'A4', 'COLOR', 'single', 10.00, 1),
(1, 'A4', 'COLOR', 'double', 15.00, 1),
(1, 'A3', 'BW', 'single', 5.00, 1),
(1, 'A3', 'COLOR', 'single', 20.00, 1)
ON DUPLICATE KEY UPDATE `price_per_page` = VALUES(`price_per_page`);

-- 4. Insert Demo Printer for Shop 1
INSERT INTO `printers` (`id`, `shop_id`, `printer_name`, `printer_identifier`, `status`, `last_seen`)
VALUES (1, 1, 'HP LaserJet Pro MFP M428fdw', 'HP-M428-MAIN', 'online', NOW())
ON DUPLICATE KEY UPDATE `printer_name` = VALUES(`printer_name`);

-- 5. Insert Demo Print Agent
INSERT INTO `print_agents` (`id`, `shop_id`, `agent_name`, `agent_token_hash`, `status`, `last_seen`, `version`)
VALUES (1, 1, 'Shop-Counter-Agent-1', SHA2('demo_agent_token_secret_123', 256), 'online', NOW(), '1.0.0-poc')
ON DUPLICATE KEY UPDATE `agent_name` = VALUES(`agent_name`);
