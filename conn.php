<?php
require_once __DIR__ . '/../message.php';

$dbHost = getenv('GREEN_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('GREEN_DB_PORT') ?: '3307';
$dbName = getenv('GREEN_DB_NAME') ?: 'green_office_db';
$dbUser = getenv('GREEN_DB_USER') ?: 'root';
$dbPassword = getenv('GREEN_DB_PASSWORD') ?: '';

try {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName) || !ctype_digit($dbPort)) {
        throw new RuntimeException('Invalid database configuration.');
    }
    $conn = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    error_log('Green Office database connection failed: ' . $e->getMessage());
    show_message('Could not reach the database', 'Check the GREEN_DB connection settings and run php scripts/setup.php once MySQL is running.', null, 503);
}
