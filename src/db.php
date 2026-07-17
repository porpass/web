<?php
/**
 * db.php — PDO database connection helper.
 *
 * Returns a shared PDO instance using credentials from the project .env file.
 * Call get_db() from any page that needs a database connection.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
/**
 * Apply debugging to page if $_ENV['APP_ENV'] === 'development'
 */
if (in_array($_ENV['APP_ENV'], ['development-local', 'development'])) { 
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

/**
 * Returns a singleton PDO connection to the PORPASS database.
 *
 * @return PDO
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_DATABASE']
        );
        $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // MariaDB 10.11 InnoDB R-tree spatial-index scans misreport their
            // lock state under REPEATABLE-READ and throw ER_READ_ONLY_TRANSACTION
            // (1207) inside ST_Intersects. READ COMMITTED sidesteps the buggy
            // code path — the gis/ FastAPI service does the same thing.
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
        ]);
    }
    return $pdo;
}
