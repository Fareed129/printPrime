<?php
/**
 * PrimePrint Database Migration Runner
 * Applies sequential SQL migrations idempotently.
 * Usage CLI: php database/migrate.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$isCli = (php_sapi_name() === 'cli');

function logMigrate($msg, $isSuccess = true) {
    global $isCli;
    if ($isCli) {
        $prefix = $isSuccess ? " [OK] " : " [ERROR] ";
        echo $prefix . $msg . PHP_EOL;
    } else {
        $color = $isSuccess ? "#10b981" : "#ef4444";
        echo "<div style='font-family: monospace; padding: 8px 12px; margin: 4px 0; border-radius: 4px; background: " . ($isSuccess ? "#ecfdf5" : "#fef2f2") . "; color: {$color}; border: 1px solid {$color};'><strong>" . ($isSuccess ? "✓ " : "✕ ") . "</strong>" . htmlspecialchars($msg) . "</div>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>PrimePrint Migrations</title></head><body style='font-family:sans-serif; max-width:800px; margin:40px auto; padding:20px; background:#f8fafc;'>";
    echo "<h2 style='color:#1e3a8a;'>PrimePrint Database Migration Runner</h2>";
}

try {
    $pdo = getDBConnection();
    logMigrate("Connected to database `" . DB_NAME . "` successfully.");

    // 1. Ensure migrations tracking table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Fetch applied migrations
    $stmt = $pdo->query("SELECT `migration` FROM `migrations`");
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 3. Scan migrations directory
    $migrationsDir = __DIR__ . '/migrations';
    if (!is_dir($migrationsDir)) {
        throw new Exception("Migrations directory not found at: {$migrationsDir}");
    }

    $files = glob($migrationsDir . '/*.sql');
    sort($files);

    $newAppliedCount = 0;
    foreach ($files as $file) {
        $migrationName = basename($file);
        if (in_array($migrationName, $applied, true)) {
            continue; // Already applied
        }

        $sql = file_get_contents($file);
        if (empty(trim($sql))) {
            continue;
        }

        // Execute migration SQL
        $pdo->exec($sql);

        // Record in migrations table
        $ins = $pdo->prepare("INSERT INTO `migrations` (`migration`) VALUES (:migration)");
        $ins->execute([':migration' => $migrationName]);

        logMigrate("Applied migration: {$migrationName}");
        $newAppliedCount++;
    }

    if ($newAppliedCount === 0) {
        logMigrate("Database is up-to-date. No new migrations to apply.");
    } else {
        logMigrate("Successfully applied {$newAppliedCount} migration(s).");
    }

} catch (Throwable $e) {
    logMigrate("Migration error: " . $e->getMessage(), false);
    if ($isCli) {
        exit(1);
    }
}

if (!$isCli) {
    echo "</body></html>";
}
