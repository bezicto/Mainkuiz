<?php
// api/player_actions.php
// Controller endpoint for player events (join room, submit answer).

header('Content-Type: application/json');
require_once '../db.php';
require_once 'game_actions_helper.php';

$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!$action) {
    echo json_encode(['status' => 'error', 'message' => 'Missing action parameter']);
    exit;
}

// Action: Join Game Session
if ($action === 'join') {
    $pin = $_POST['pin'] ?? null;
    $nickname = trim($_POST['nickname'] ?? '');
    
    if (!$pin || !$nickname) {
        echo json_encode(['status' => 'error', 'message' => 'Missing PIN or Nickname']);
        exit;
    }
    
    if (strlen($nickname) > 20) {
        echo json_encode(['status' => 'error', 'message' => 'Nickname must be 20 characters or less']);
        exit;
    }
    
    // Find active game session
    $stmt = $pdo->prepare("SELECT * FROM game_sessions WHERE pin = ?");
    $stmt->execute([$pin]);
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['status' => 'error', 'message' => 'Game PIN not found']);
        exit;
    }
    
    if ($session['status'] !== 'waiting') {
        echo json_encode(['status' => 'error', 'message' => 'Quiz has already started']);
        exit;
    }
    
    // Check participant limit configured in db.php
    $maxLimit = defined('MAX_PARTICIPANTS') ? MAX_PARTICIPANTS : ($max_participants ?? 50);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE session_id = ?");
    $stmt->execute([$session['id']]);
    $currentCount = (int)$stmt->fetchColumn();

    if ($currentCount >= $maxLimit) {
        echo json_encode([
            'status' => 'error',
            'message' => "Game lobby is full! Maximum limit of {$maxLimit} participants reached."
        ]);
        exit;
    }
    
    try {
        // Insert player
        $stmt = $pdo->prepare("INSERT INTO players (session_id, nickname, score, streak) VALUES (?, ?, 0, 0)");
        $stmt->execute([$session['id'], $nickname]);
        $playerId = $pdo->lastInsertId();
        
        echo json_encode([
            'status' => 'success',
            'player_id' => $playerId,
            'session_id' => $session['id'],
            'nickname' => $nickname,
            'pin' => $pin
        ]);
    } catch (\PDOException $e) {
        // Check for duplicate nickname
        if ($e->getCode() == 23000) {
            echo json_encode(['status' => 'error', 'message' => 'Nickname is already taken in this lobby']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
    exit;
}

// Action: Submit Answer
if ($action === 'submit_answer') {
    $playerId = $_POST['player_id'] ?? null;
    $questionId = $_POST['question_id'] ?? null;
    $answerId = $_POST['answer_id'] ?? null;
    
    if (!$playerId || !$questionId || !$answerId) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required answer details']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Lock ONLY the player row (Prevents global game_sessions serialization bottleneck)
        $stmt = $pdo->prepare("SELECT * FROM players WHERE id = ? FOR UPDATE");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();
        
        if (!$player) {
            throw new Exception('Player session not found');
        }

        // 2. Non-locking check on game session state
        $stmt = $pdo->prepare("SELECT status, current_question_started_at, current_question_id FROM game_sessions WHERE id = ?");
        $stmt->execute([$player['session_id']]);
        $session = $stmt->fetch();
        
        if (!$session || $session['current_question_id'] != $questionId || $session['status'] !== 'question') {
            throw new Exception('Question is not active or time is up');
        }
        
        // 3. Fast double submission check
        $stmt = $pdo->prepare("SELECT id FROM player_answers WHERE player_id = ? AND question_id = ?");
        $stmt->execute([$playerId, $questionId]);
        if ($stmt->fetch()) {
            throw new Exception('You have already submitted an answer for this question');
        }
        
        // 4. Combined question and answer lookup in a single query
        $stmt = $pdo->prepare("
            SELECT q.points, q.time_limit, a.is_correct 
            FROM questions q 
            JOIN answers a ON a.question_id = q.id 
            WHERE q.id = ? AND a.id = ?
        ");
        $stmt->execute([$questionId, $answerId]);
        $qa = $stmt->fetch();
        
        if (!$qa) {
            throw new Exception('Invalid question or answer');
        }
        
        $isCorrect = (int)$qa['is_correct'];
        $pointsMax = (int)$qa['points'];
        $timeLimit = (int)$qa['time_limit'];
        
        // Calculate response time in ms
        $nowMs = round(microtime(true) * 1000);
        $startedAtMs = (float)$session['current_question_started_at'];
        $responseTimeMs = max(0, $nowMs - $startedAtMs);
        $timeLimitMs = $timeLimit * 1000;
        
        $pointsEarned = 0;
        $newStreak = 0;
        
        if ($isCorrect) {
            // Point formula: baseline points, adjusted for response speed
            $fraction = min(1.0, max(0.0, $responseTimeMs / $timeLimitMs));
            $basePoints = round($pointsMax * (1 - ($fraction * 0.5)));
            
            // Streak bonus calculations: +100 points per streak level (max 500 bonus points)
            $currentStreak = (int)$player['streak'];
            $newStreak = $currentStreak + 1;
            $streakBonus = min(($newStreak - 1) * 100, 500);
            
            $pointsEarned = $basePoints + $streakBonus;
        } else {
            $newStreak = 0;
        }
        
        // Save player's answer
        $stmt = $pdo->prepare("
            INSERT INTO player_answers (player_id, question_id, answer_id, points_earned, response_time_ms) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$playerId, $questionId, $answerId, $pointsEarned, $responseTimeMs]);
        
        // Update player's aggregate score, streak, and correct state
        $stmt = $pdo->prepare("
            UPDATE players 
            SET score = score + ?, streak = ?, last_question_correct = ? 
            WHERE id = ?
        ");
        $stmt->execute([$pointsEarned, $newStreak, $isCorrect, $playerId]);

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'is_correct' => $isCorrect,
            'points_earned' => $pointsEarned,
            'new_score' => (int)$player['score'] + $pointsEarned,
            'streak' => $newStreak
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unsupported action']);
