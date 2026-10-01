<?php
// database.php — shared PDO connection for the student portal

$config = require_once __DIR__ . '/config.php';

if (!isset($config['db'])) {
    die("Database Connection Failed: 'db' settings are missing from config.php.");
}

$host     = $config['db']['host'] ?? 'localhost';
$dbname   = $config['db']['name'] ?? 'student_portal_db';
$user     = $config['db']['user'] ?? 'root';
$pass     = $config['db']['pass'] ?? '';
$charset  = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>