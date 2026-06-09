<?php
// db.php
// Database connection file

$host = '127.0.0.1';
$db = 'mainkuiz_db';
$user = 'kashoot';
$pass = 'abc1234';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}
