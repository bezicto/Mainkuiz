<?php
// api/game_state.php
// Returns game state for hosts and players. Optimized with sub-millisecond micro-cache versioning.

header('Content-Type: application/json');
header('Cache-Control: private, no-cache');

require_once 'game_cache.php';

$pin = $_GET['pin'] ?? null;
$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;
$clientVersion = isset($_GET['v']) ? (int)$_GET['v'] : null;
$isForce = isset($_GET['force']);

if (!$pin && !$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing session identifier (pin or session_id)']);
    exit;
}

// 1. Ultra-fast path for players: 0 DATABASE QUERIES!
// If client has session_id, player_id, and version, check GameCache before opening any DB connection.
if ($sessionId && $playerId && $clientVersion !== null && !$isForce) {
    $currentVersion = GameCache::getVersion($sessionId);
    // Only return unchanged if version matches AND active question timer hasn't expired
    if ($clientVersion === $currentVersion && !GameCache::isQuestionExpired($sessionId)) {
        echo json_encode([
            'status' => 'unchanged',
            'version' => $currentVersion,
            'server_time' => round(microtime(true) * 1000)
        ]);
        exit;
    }
}

// 2. Full state retrieval path (requires database connection)
require_once '../db.php';
require_once 'game_state_helper.php';

// Find session ID if PIN is provided
if ($pin && !$sessionId) {
    $stmt = $pdo->prepare("SELECT id FROM game_sessions WHERE pin = ?");
    $stmt->execute([$pin]);
    $sessionId = $stmt->fetchColumn();
    if (!$sessionId) {
        echo json_encode(['status' => 'error', 'message' => 'Game session not found']);
        exit;
    }
    $sessionId = (int)$sessionId;
}

$state = get_game_state_data($pdo, $sessionId, $playerId, $clientVersion);
echo json_encode($state);
