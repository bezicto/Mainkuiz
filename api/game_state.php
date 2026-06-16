<?php
// api/game_state.php
// Returns game state for hosts and players. Optimized for short-polling or fallback.

header('Content-Type: application/json');
require_once '../db.php';
require_once 'game_state_helper.php';

$pin = $_GET['pin'] ?? null;
$session_id = $_GET['session_id'] ?? null;
$player_id = $_GET['player_id'] ?? null;

if (!$pin && !$session_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing session identifier (pin or session_id)']);
    exit;
}

// Find session ID if PIN is provided
if ($pin && !$session_id) {
    $stmt = $pdo->prepare("SELECT id FROM game_sessions WHERE pin = ?");
    $stmt->execute([$pin]);
    $session_id = $stmt->fetchColumn();
    if (!$session_id) {
        echo json_encode(['status' => 'error', 'message' => 'Game session not found']);
        exit;
    }
}

$state = get_game_state_data($pdo, $session_id, $player_id);
echo json_encode($state);
