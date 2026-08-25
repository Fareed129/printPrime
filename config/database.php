<?php
/**
 * PrimePrint Database Connection Handler (PDO)
 */

require_once __DIR__ . '/config.php';

function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            if (defined('APP_ENV') && APP_ENV === 'production') {
                http_response_code(500);
                die("Database service temporarily unavailable. Please contact the administrator.");
            } else {
                die("Database Connection Failed: " . htmlspecialchars($e->getMessage()));
            }
        }
    }

    return $pdo;
}

