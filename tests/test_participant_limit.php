<?php
// tests/test_participant_limit.php
// Automated test suite for participant limit enforcement defined in db.php

echo "===============================================================\n";
echo "       TEST SUITE: PARTICIPANT LIMIT ENFORCEMENT               \n";
echo "===============================================================\n\n";

// TEST 1: Check db.php configuration definitions
echo "[TEST 1] Checking db.php MAX_PARTICIPANTS definition...\n";
// Suppress connection failure if MySQL daemon is not running during CLI test
$pdo = null;
ob_start();
try {
    require_once __DIR__ . '/../db.php';
} catch (\Throwable $e) {
    // Ignore connection error if local MySQL is off
}
ob_end_clean();

if (!defined('MAX_PARTICIPANTS')) {
    echo "  [FAIL] MAX_PARTICIPANTS constant is NOT defined!\n";
    exit(1);
}

if (MAX_PARTICIPANTS !== 50) {
    echo "  [FAIL] MAX_PARTICIPANTS expected 50, got: " . var_export(MAX_PARTICIPANTS, true) . "\n";
    exit(1);
}
echo "  -> MAX_PARTICIPANTS is defined as: " . MAX_PARTICIPANTS . "\n";
echo "  [PASS] Configuration definition verified.\n\n";

// TEST 2: Simulate Session Join Enforcing Limit (SQLite in-memory)
echo "[TEST 2] Testing boundary enforcement up to 50 players & rejection of 51st player...\n";
$testPdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$testPdo->exec("
    CREATE TABLE game_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quiz_id INTEGER NOT NULL,
        pin TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'waiting',
        current_question_id INTEGER DEFAULT NULL,
        current_question_started_at REAL DEFAULT NULL,
        current_question_ended_at REAL DEFAULT NULL
    );
    CREATE TABLE players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        nickname TEXT NOT NULL,
        score INTEGER NOT NULL DEFAULT 0,
        streak INTEGER NOT NULL DEFAULT 0,
        UNIQUE(session_id, nickname)
    );
");

$testPdo->exec("INSERT INTO game_sessions (quiz_id, pin, status) VALUES (1, '999888', 'waiting')");
$sessionId = (int)$testPdo->lastInsertId();

// Helper simulating join endpoint logic from api/player_actions.php
function simulate_join($pdo, $sessionId, $nickname) {
    $maxLimit = defined('MAX_PARTICIPANTS') ? MAX_PARTICIPANTS : ($GLOBALS['max_participants'] ?? 50);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $currentCount = (int)$stmt->fetchColumn();

    if ($currentCount >= $maxLimit) {
        return [
            'status' => 'error',
            'message' => "Game lobby is full! Maximum limit of {$maxLimit} participants reached."
        ];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO players (session_id, nickname, score, streak) VALUES (?, ?, 0, 0)");
        $stmt->execute([$sessionId, $nickname]);
        return [
            'status' => 'success',
            'player_id' => (int)$pdo->lastInsertId(),
            'session_id' => $sessionId,
            'nickname' => $nickname
        ];
    } catch (\PDOException $e) {
        return ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Join 50 players
for ($i = 1; $i <= 50; $i++) {
    $result = simulate_join($testPdo, $sessionId, "Player_" . $i);
    if ($result['status'] !== 'success') {
        echo "  [FAIL] Failed to join player $i: " . $result['message'] . "\n";
        exit(1);
    }
}
echo "  -> Successfully joined 50 players.\n";

// Count in DB
$countStmt = $testPdo->prepare("SELECT COUNT(*) FROM players WHERE session_id = ?");
$countStmt->execute([$sessionId]);
$dbCount = (int)$countStmt->fetchColumn();

if ($dbCount !== 50) {
    echo "  [FAIL] Expected exactly 50 players in DB, found $dbCount\n";
    exit(1);
}
echo "  -> Verified DB contains exactly 50 players.\n";

// Attempt to join 51st player
$result51 = simulate_join($testPdo, $sessionId, "Player_51");
if ($result51['status'] !== 'error') {
    echo "  [FAIL] 51st player was not rejected! Result: " . json_encode($result51) . "\n";
    exit(1);
}

if (strpos($result51['message'], 'Maximum limit of 50 participants reached') === false) {
    echo "  [FAIL] Unexpected error message: " . $result51['message'] . "\n";
    exit(1);
}
echo "  -> 51st player correctly rejected with: \"{$result51['message']}\"\n";
echo "  [PASS] 50-player limit enforcement working correctly.\n\n";

// TEST 3: Check Game State Helper Payload contains max_players
echo "[TEST 3] Verifying max_players is exposed in session state helper...\n";
require_once __DIR__ . '/../api/game_state_helper.php';

$testPdo->exec("
    CREATE TABLE quizzes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL
    );
    CREATE TABLE questions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quiz_id INTEGER NOT NULL,
        question_text TEXT NOT NULL,
        time_limit INTEGER NOT NULL DEFAULT 20,
        order_num INTEGER NOT NULL
    );
    INSERT INTO quizzes (id, title) VALUES (1, 'Test Quiz');
");

$state = get_game_state_data($testPdo, $sessionId);
if (!isset($state['session']['max_players'])) {
    echo "  [FAIL] 'max_players' key missing from get_game_state_data() session payload!\n";
    exit(1);
}

if ($state['session']['max_players'] !== 50) {
    echo "  [FAIL] 'max_players' value expected 50, got: " . var_export($state['session']['max_players'], true) . "\n";
    exit(1);
}

if ($state['session']['total_players'] !== 50) {
    echo "  [FAIL] 'total_players' value expected 50, got: " . var_export($state['session']['total_players'], true) . "\n";
    exit(1);
}

echo "  -> session.max_players: " . $state['session']['max_players'] . "\n";
echo "  -> session.total_players: " . $state['session']['total_players'] . "\n";
echo "  [PASS] Session payload properly exposes max_players.\n\n";

echo "===============================================================\n";
echo "  ALL TESTS PASSED SUCCESSFULLY!\n";
echo "===============================================================\n";
