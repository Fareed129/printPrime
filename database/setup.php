<?php
/**
 * PrimePrint Database Setup & Seed Runner
 * Can be run via CLI: php database/setup.php
 * Or via browser: http://localhost:8000/database/setup.php
 */

require_once __DIR__ . '/../config/config.php';

$isCli = (php_sapi_name() === 'cli');

function outputMsg($msg, $isSuccess = true) {
    global $isCli;
    if ($isCli) {
        $prefix = $isSuccess ? " [OK] " : " [ERROR] ";
        echo $prefix . $msg . PHP_EOL;
    } else {
        $color = $isSuccess ? "#10b981" : "#ef4444";
        echo "<div style='font-family: sans-serif; padding: 10px 14px; margin-bottom: 8px; border-radius: 6px; background: " . ($isSuccess ? "#ecfdf5" : "#fef2f2") . "; color: {$color}; border: 1px solid {$color};'><strong>" . ($isSuccess ? "✓ " : "✕ ") . "</strong>" . htmlspecialchars($msg) . "</div>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>PrimePrint DB Setup</title><meta name='viewport' content='width=device-width, initial-scale=1'></head><body style='background:#f8fafc; font-family:sans-serif; max-width:800px; margin:40px auto; padding:20px;'>";
    echo "<h2 style='color:#1e3a8a;'>PrimePrint Database Setup Runner</h2>";
}

try {
    // 1. Connect without selecting database to create database if not exists
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    outputMsg("Connected to MySQL Server successfully.");

    // 2. Read and execute schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found at: {$schemaFile}");
    }
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    outputMsg("Database `primeprint_db` and tables created successfully.");

    // 3. Connect to the specific database
    $dbDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $dbPdo = new PDO($dbDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 3b. Migration Check: Ensure public_token exists in print_jobs
    $colCheck = $dbPdo->query("SHOW COLUMNS FROM `print_jobs` LIKE 'public_token'")->fetch();
    if (!$colCheck) {
        $dbPdo->exec("ALTER TABLE `print_jobs` ADD COLUMN `public_token` VARCHAR(64) NULL AFTER `id`");
        $existingJobs = $dbPdo->query("SELECT id FROM `print_jobs` WHERE public_token IS NULL OR public_token = ''")->fetchAll();
        foreach ($existingJobs as $ej) {
            $tok = 'PP-' . strtoupper(bin2hex(random_bytes(4)));
            $upStmt = $dbPdo->prepare("UPDATE `print_jobs` SET `public_token` = :tok WHERE `id` = :id");
            $upStmt->execute([':tok' => $tok, ':id' => $ej['id']]);
        }
        $dbPdo->exec("ALTER TABLE `print_jobs` MODIFY COLUMN `public_token` VARCHAR(64) NOT NULL, ADD UNIQUE KEY `uniq_public_token` (`public_token`)");
        outputMsg("Migrated `print_jobs` table: added unique `public_token` column.");
    }

    // 4. Seed Super Admin
    $adminPasswordHash = password_hash('ChangeMe123!', PASSWORD_BCRYPT);
    $stmt = $dbPdo->prepare("
        INSERT INTO users (id, name, email, password_hash, role, shop_id, status)
        VALUES (1, 'Super Admin', 'admin@primeprint.local', :pass, 'admin', NULL, 'active')
        ON DUPLICATE KEY UPDATE password_hash = :pass_up, name = 'Super Admin'
    ");
    $stmt->execute([':pass' => $adminPasswordHash, ':pass_up' => $adminPasswordHash]);
    outputMsg("Super Admin seeded: admin@primeprint.local / ChangeMe123!");

    // 5. Seed Demo Shop
    $stmt = $dbPdo->prepare("
        INSERT INTO shops (id, name, slug, owner_name, phone, email, address, status)
        VALUES (1, 'ABC Digital Printing', 'abc-digital-printing', 'Ramesh Kumar', '+91 9876543210', 'shop@abcprinting.local', 'Shop #4, Metro Complex, MG Road, Bangalore', 'active')
        ON DUPLICATE KEY UPDATE name = 'ABC Digital Printing', status = 'active'
    ");
    $stmt->execute();
    outputMsg("Demo Shop seeded: ABC Digital Printing (slug: abc-digital-printing)");

    // 6. Seed Shop User
    $shopUserPasswordHash = password_hash('ChangeMe123!', PASSWORD_BCRYPT);
    $stmt = $dbPdo->prepare("
        INSERT INTO users (id, name, email, password_hash, role, shop_id, status)
        VALUES (2, 'ABC Shop Admin', 'shop@abcprinting.local', :pass, 'shop', 1, 'active')
        ON DUPLICATE KEY UPDATE password_hash = :pass_up, shop_id = 1, status = 'active'
    ");
    $stmt->execute([':pass' => $shopUserPasswordHash, ':pass_up' => $shopUserPasswordHash]);
    outputMsg("Shop User seeded: shop@abcprinting.local / ChangeMe123!");

    // 7. Seed Pricing
    $pricingRows = [
        ['shop_id' => 1, 'paper_size' => 'A4', 'color_mode' => 'BW', 'side_mode' => 'single', 'price' => 2.00],
        ['shop_id' => 1, 'paper_size' => 'A4', 'color_mode' => 'BW', 'side_mode' => 'double', 'price' => 3.00],
        ['shop_id' => 1, 'paper_size' => 'A4', 'color_mode' => 'COLOR', 'side_mode' => 'single', 'price' => 10.00],
        ['shop_id' => 1, 'paper_size' => 'A4', 'color_mode' => 'COLOR', 'side_mode' => 'double', 'price' => 15.00],
        ['shop_id' => 1, 'paper_size' => 'A3', 'color_mode' => 'BW', 'side_mode' => 'single', 'price' => 5.00],
        ['shop_id' => 1, 'paper_size' => 'A3', 'color_mode' => 'COLOR', 'side_mode' => 'single', 'price' => 20.00]
    ];

    $priceStmt = $dbPdo->prepare("
        INSERT INTO pricing (shop_id, paper_size, color_mode, side_mode, price_per_page, active)
        VALUES (:shop_id, :paper_size, :color_mode, :side_mode, :price, 1)
        ON DUPLICATE KEY UPDATE price_per_page = :price_up, active = 1
    ");

    foreach ($pricingRows as $p) {
        $priceStmt->execute([
            ':shop_id' => $p['shop_id'],
            ':paper_size' => $p['paper_size'],
            ':color_mode' => $p['color_mode'],
            ':side_mode' => $p['side_mode'],
            ':price' => $p['price'],
            ':price_up' => $p['price']
        ]);
    }
    outputMsg("Sample pricing tiers seeded for ABC Digital Printing.");

    // 8. Seed Demo Printer & Agent
    $dbPdo->exec("
        INSERT INTO printers (id, shop_id, printer_name, printer_identifier, status, last_seen)
        VALUES (1, 1, 'HP LaserJet Pro MFP M428fdw', 'HP-M428-MAIN', 'online', NOW())
        ON DUPLICATE KEY UPDATE printer_name = VALUES(printer_name)
    ");
    outputMsg("Demo printer registered.");

    $dbPdo->exec("
        INSERT INTO print_agents (id, shop_id, agent_name, agent_token_hash, status, last_seen, version)
        VALUES (1, 1, 'Shop-Counter-Agent-1', SHA2('demo_agent_token_secret_123', 256), 'online', NOW(), '1.0.0-poc')
        ON DUPLICATE KEY UPDATE agent_name = VALUES(agent_name)
    ");
    outputMsg("Demo print agent token initialized.");

    outputMsg("SETUP COMPLETED SUCCESSFULLY! You can now login.", true);

    if (!$isCli) {
        echo "<br><div style='margin-top:20px;'><a href='../login.php' style='display:inline-block; padding:10px 20px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;'>Proceed to Login</a></div>";
        echo "</body></html>";
    }

} catch (Exception $e) {
    outputMsg("Setup Failure: " . $e->getMessage(), false);
    if (!$isCli) {
        echo "</body></html>";
    }
    exit(1);
}
