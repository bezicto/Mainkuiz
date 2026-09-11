<?php
// db.php
// Database connection file with environment variable support & connection pooling

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db = getenv('DB_NAME') ?: 'mainkuiz_db';
$user = getenv('DB_USER') ?: 'kashoot';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'abc1234';
$port = getenv('DB_PORT') ?: '3306';
$socket = getenv('DB_SOCKET') ?: null;
$charset = 'utf8mb4';

if ($socket) {
    $dsn = "mysql:unix_socket=$socket;dbname=$db;charset=$charset";
} else {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
}

// Optimized options for high-concurrency 1000-participant load
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => true, // Connection pooling: reuse database connections across requests
    PDO::ATTR_TIMEOUT => 3,       // Fast 3-second connection timeout to avoid hanging workers
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Auto-migrate schema: ensure 'rank' column exists in 'players' table
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $hasRank = $pdo->query("SHOW COLUMNS FROM players LIKE 'rank'")->fetch();
            if (!$hasRank) {
                $pdo->exec("ALTER TABLE players ADD COLUMN rank INT NOT NULL DEFAULT 1 AFTER streak, ADD INDEX idx_session_rank (session_id, rank)");
            }
        }
    } catch (\Throwable $migErr) {
        // Ignore if already present or permission denied
    }
} catch (\PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    header('Content-Type: application/json', true, 500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed. Please check server logs.'
    ]);
    exit;
}

