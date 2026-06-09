<?php
// api/host_actions.php
// Controller endpoint for host events. Requires active admin session.

session_start();
header('Content-Type: application/json');
require_once '../db.php';

// Check admin session (we will verify credentials in admin login)
if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Admin session required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$sessionId = $_POST['session_id'] ?? $_GET['session_id'] ?? null;

if (!$action) {
    echo json_encode(['status' => 'error', 'message' => 'Missing action parameter']);
    exit;
}

// Action: Create Game Session
if ($action === 'create') {
    $quizId = $_POST['quiz_id'] ?? null;
    if (!$quizId) {
        echo json_encode(['status' => 'error', 'message' => 'Missing quiz_id']);
        exit;
    }
    
    // Check if quiz exists
    $stmt = $pdo->prepare("SELECT id FROM quizzes WHERE id = ?");
    $stmt->execute([$quizId]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Quiz not found']);
        exit;
    }

    // Clean up all incomplete sessions (status !== 'podium')
    $pdo->query("DELETE FROM game_sessions WHERE status != 'podium'");
    
    // Generate Unique PIN
    $pin = '';
    $attempts = 0;
    while ($attempts < 100) {
        $testPin = str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT id FROM game_sessions WHERE pin = ?");
        $stmt->execute([$testPin]);
        if (!$stmt->fetch()) {
            $pin = $testPin;
            break;
        }
        $attempts++;
    }
    
    if (!$pin) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to generate unique PIN']);
        exit;
    }
    
    // Create game session
    $stmt = $pdo->prepare("INSERT INTO game_sessions (quiz_id, pin, status) VALUES (?, ?, 'waiting')");
    $stmt->execute([$quizId, $pin]);
    $newSessionId = $pdo->lastInsertId();
    
    echo json_encode([
        'status' => 'success',
        'session_id' => $newSessionId,
        'pin' => $pin
    ]);
    exit;
}

// For all other actions, session_id is required
if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing session_id']);
    exit;
}

// Fetch session and quiz ID
$stmt = $pdo->prepare("SELECT * FROM game_sessions WHERE id = ?");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Session not found']);
    exit;
}

$quizId = $session['quiz_id'];

// Action: Start Game (Transition to Countdown for First Question)
if ($action === 'start_game') {
    // Get the first question
    $stmt = $pdo->prepare("SELECT id FROM questions WHERE quiz_id = ? ORDER BY order_num ASC LIMIT 1");
    $stmt->execute([$quizId]);
    $firstQuestionId = $stmt->fetchColumn();
    
    if (!$firstQuestionId) {
        echo json_encode(['status' => 'error', 'message' => 'Quiz has no questions']);
        exit;
    }
    
    $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'countdown', current_question_id = ?, current_question_started_at = NULL, current_question_ended_at = NULL WHERE id = ?");
    $stmt->execute([$firstQuestionId, $sessionId]);
    
    echo json_encode(['status' => 'success', 'message' => 'Game started, countdown phase active']);
    exit;
}

// Action: Start Question (Timer begins ticking)
if ($action === 'start_question') {
    if ($session['status'] !== 'countdown' && $session['status'] !== 'leaderboard') {
        echo json_encode(['status' => 'error', 'message' => 'Cannot start question from current state: ' . $session['status']]);
        exit;
    }
    
    $nowMs = round(microtime(true) * 1000);
    $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'question', current_question_started_at = ?, current_question_ended_at = NULL WHERE id = ?");
    $stmt->execute([$nowMs, $sessionId]);
    
    echo json_encode(['status' => 'success', 'message' => 'Question started']);
    exit;
}

// Action: Show Results (Force timer end / reveal answers)
if ($action === 'show_results') {
    if ($session['status'] !== 'question') {
        echo json_encode(['status' => 'error', 'message' => 'Cannot end question from state: ' . $session['status']]);
        exit;
    }
    
    $nowMs = round(microtime(true) * 1000);
    $questionId = $session['current_question_id'];
    
    // 1. Mark session as showing answers
    $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'answers', current_question_ended_at = ? WHERE id = ?");
    $stmt->execute([$nowMs, $sessionId]);
    
    // 2. Handle players who timed out (did not answer)
    // Find all players in this session who have no answer logged for this question
    $stmt = $pdo->prepare("
        SELECT id FROM players 
        WHERE session_id = ? 
          AND id NOT IN (
              SELECT player_id FROM player_answers WHERE question_id = ?
          )
    ");
    $stmt->execute([$sessionId, $questionId]);
    $timeoutPlayers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($timeoutPlayers)) {
        // Insert empty entries in player_answers for timeouts and break streaks
        $insertQuery = "INSERT INTO player_answers (player_id, question_id, answer_id, points_earned, response_time_ms) VALUES ";
        $placeholders = [];
        $values = [];
        foreach ($timeoutPlayers as $pId) {
            $placeholders[] = "(?, ?, NULL, 0, 0)";
            $values[] = $pId;
            $values[] = $questionId;
        }
        $insertQuery .= implode(", ", $placeholders);
        $stmt = $pdo->prepare($insertQuery);
        $stmt->execute($values);
        
        // Reset player streaks and last_question_correct for timeouts
        $resetQuery = "UPDATE players SET streak = 0, last_question_correct = 0 WHERE id IN (" . implode(",", array_fill(0, count($timeoutPlayers), "?")) . ")";
        $stmt = $pdo->prepare($resetQuery);
        $stmt->execute($timeoutPlayers);
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Question ended, showing results']);
    exit;
}

// Action: Show Leaderboard
if ($action === 'show_leaderboard') {
    if ($session['status'] !== 'answers') {
        echo json_encode(['status' => 'error', 'message' => 'Cannot show leaderboard from state: ' . $session['status']]);
        exit;
    }
    
    $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'leaderboard' WHERE id = ?");
    $stmt->execute([$sessionId]);
    
    echo json_encode(['status' => 'success', 'message' => 'Showing leaderboard']);
    exit;
}

// Action: Next Question
if ($action === 'next_question') {
    if ($session['status'] !== 'leaderboard') {
        echo json_encode(['status' => 'error', 'message' => 'Cannot advance to next question from state: ' . $session['status']]);
        exit;
    }
    
    // Get current question order_num
    $stmt = $pdo->prepare("SELECT order_num FROM questions WHERE id = ?");
    $stmt->execute([$session['current_question_id']]);
    $currOrder = (int)$stmt->fetchColumn();
    
    // Get next question
    $stmt = $pdo->prepare("SELECT id FROM questions WHERE quiz_id = ? AND order_num = ?");
    $stmt->execute([$quizId, $currOrder + 1]);
    $nextQuestionId = $stmt->fetchColumn();
    
    if ($nextQuestionId) {
        // Go to countdown for the next question
        $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'countdown', current_question_id = ?, current_question_started_at = NULL, current_question_ended_at = NULL WHERE id = ?");
        $stmt->execute([$nextQuestionId, $sessionId]);
        echo json_encode(['status' => 'success', 'phase' => 'countdown', 'message' => 'Advanced to next question']);
    } else {
        // No more questions -> show podium
        $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'podium', current_question_id = NULL, current_question_started_at = NULL, current_question_ended_at = NULL WHERE id = ?");
        $stmt->execute([$sessionId]);
        echo json_encode(['status' => 'success', 'phase' => 'podium', 'message' => 'Quiz finished, showing podium']);
    }
    exit;
}

// Action: End Game (Show Podium Immediately)
if ($action === 'end_game') {
    $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'podium', current_question_id = NULL, current_question_started_at = NULL, current_question_ended_at = NULL WHERE id = ?");
    $stmt->execute([$sessionId]);
    echo json_encode(['status' => 'success', 'message' => 'Game forced to podium']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unsupported action']);
