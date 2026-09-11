<?php
// api/game_state.php
// Returns game state for hosts and players. Optimized with sub-millisecond database-backed versioning.

header('Content-Type: application/json');
header('Cache-Control: private, no-cache');

require_once '../db.php';
require_once 'game_state_helper.php';

$pin = $_GET['pin'] ?? null;
$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;
$clientVersion = isset($_GET['v']) ? (int)$_GET['v'] : null;
$isForce = isset($_GET['force']);

if (!$pin && !$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing session identifier (pin or session_id)']);
    exit;
}

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

// Ultra-fast path for players: if status & question haven't changed, return 'unchanged' (0.03ms PK lookup)
// Hosts (playerId === null) and forced requests always bypass this check to receive complete fresh state
if ($playerId && $clientVersion !== null && !$isForce) {
    $stmt = $pdo->prepare("SELECT status, current_question_id, current_question_ended_at FROM game_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $sess = $stmt->fetch();
    if ($sess) {
        $currentVersion = crc32($sess['status'] . '_' . ($sess['current_question_id'] ?? 0) . '_' . ($sess['current_question_ended_at'] ?? 0));
        if ($clientVersion === $currentVersion) {
            echo json_encode([
                'status' => 'unchanged',
                'version' => $currentVersion,
                'server_time' => round(microtime(true) * 1000)
            ]);
            exit;
        }
    }
}

$state = get_game_state_data($pdo, $sessionId, $playerId, $clientVersion);
echo json_encode($state);
