<?php
// api/game_state.php
// Returns game state for hosts and players. Optimized for short-polling.

header('Content-Type: application/json');
require_once '../db.php';

$pin = $_GET['pin'] ?? null;
$session_id = $_GET['session_id'] ?? null;
$player_id = $_GET['player_id'] ?? null;

if (!$pin && !$session_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing session identifier (pin or session_id)']);
    exit;
}

// 1. Fetch Game Session
$session = null;
if ($session_id) {
    $stmt = $pdo->prepare("SELECT gs.*, q.title as quiz_title FROM game_sessions gs JOIN quizzes q ON gs.quiz_id = q.id WHERE gs.id = ?");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch();
} else {
    $stmt = $pdo->prepare("SELECT gs.*, q.title as quiz_title FROM game_sessions gs JOIN quizzes q ON gs.quiz_id = q.id WHERE gs.pin = ?");
    $stmt->execute([$pin]);
    $session = $stmt->fetch();
}

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Game session not found']);
    exit;
}

$sessionId = $session['id'];
$status = $session['status'];

// 2. Fetch Player count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE session_id = ?");
$stmt->execute([$sessionId]);
$totalPlayers = (int)$stmt->fetchColumn();

// 3. Fetch Player list (lobby names)
$players = [];
if ($status === 'waiting') {
    $stmt = $pdo->prepare("SELECT id, nickname FROM players WHERE session_id = ? ORDER BY id DESC");
    $stmt->execute([$sessionId]);
    $players = $stmt->fetchAll();
}

// 4. Fetch Question Detail if active
$question = null;
$answers = [];
$totalSubmitted = 0;
$answersCount = [];
$totalQuestions = 0;

// Get total questions count for the quiz
$stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
$stmt->execute([$session['quiz_id']]);
$totalQuestions = (int)$stmt->fetchColumn();

if ($session['current_question_id']) {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
    $stmt->execute([$session['current_question_id']]);
    $question = $stmt->fetch();
    
    if ($question) {
        // Fetch answers
        $stmt = $pdo->prepare("SELECT id, answer_text, is_correct FROM answers WHERE question_id = ?");
        $stmt->execute([$question['id']]);
        $rawAnswers = $stmt->fetchAll();
        
        // Anti-cheat: Hide correctness unless status is 'answers', 'leaderboard', or 'podium'
        foreach ($rawAnswers as $ans) {
            $answers[] = [
                'id' => $ans['id'],
                'answer_text' => $ans['answer_text'],
                'is_correct' => in_array($status, ['answers', 'leaderboard', 'podium']) ? (int)$ans['is_correct'] : null
            ];
        }
        
        // Count total player answers submitted for this session specifically
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM player_answers pa 
            JOIN players p ON pa.player_id = p.id 
            WHERE pa.question_id = ? AND p.session_id = ?
        ");
        $stmt->execute([$question['id'], $sessionId]);
        $totalSubmitted = (int)$stmt->fetchColumn();
        
        // Calculate breakdown of answers (if showing answers, leaderboard, or podium) for this session specifically
        if (in_array($status, ['answers', 'leaderboard', 'podium'])) {
            $stmt = $pdo->prepare("
                SELECT pa.answer_id, COUNT(*) as count 
                FROM player_answers pa
                JOIN players p ON pa.player_id = p.id
                WHERE pa.question_id = ? AND p.session_id = ?
                GROUP BY pa.answer_id
            ");
            $stmt->execute([$question['id'], $sessionId]);
            $breakdown = $stmt->fetchAll();
            
            // Initialize count map
            foreach ($answers as $ans) {
                $answersCount[$ans['id']] = 0;
            }
            // Add null count for players whose time ran out
            $answersCount['timeout'] = 0;
            
            foreach ($breakdown as $row) {
                $key = $row['answer_id'] ?? 'timeout';
                $answersCount[$key] = (int)$row['count'];
            }
        }
    }
}

// 5. Timer Calculations
$timeRemaining = 0;
if ($status === 'question' && $session['current_question_started_at'] && $question) {
    $nowMs = round(microtime(true) * 1000);
    $elapsedMs = $nowMs - (float)$session['current_question_started_at'];
    $limitMs = $question['time_limit'] * 1000;
    
    $timeRemainingMs = $limitMs - $elapsedMs;
    $timeRemaining = ceil($timeRemainingMs / 1000);
    if ($timeRemaining < 0) {
        $timeRemaining = 0;
    }
}

// 6. Fetch player specific data if requested
$playerData = null;
if ($player_id) {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE id = ? AND session_id = ?");
    $stmt->execute([$player_id, $sessionId]);
    $player = $stmt->fetch();
    
    if ($player) {
        // Check if player has answered the current question
        $hasAnswered = false;
        $answerCorrect = false;
        $pointsEarned = 0;
        
        if ($session['current_question_id']) {
            $stmt = $pdo->prepare("SELECT pa.*, a.is_correct FROM player_answers pa LEFT JOIN answers a ON pa.answer_id = a.id WHERE pa.player_id = ? AND pa.question_id = ?");
            $stmt->execute([$player['id'], $session['current_question_id']]);
            $pa = $stmt->fetch();
            if ($pa) {
                $hasAnswered = true;
                $answerCorrect = (bool)($pa['is_correct'] ?? false);
                $pointsEarned = (int)$pa['points_earned'];
            }
        }
        
        // Calculate player's current rank
        $stmt = $pdo->prepare("SELECT COUNT(*) + 1 FROM players WHERE session_id = ? AND score > ?");
        $stmt->execute([$sessionId, $player['score']]);
        $rank = (int)$stmt->fetchColumn();
        
        $playerData = [
            'id' => $player['id'],
            'nickname' => $player['nickname'],
            'score' => (int)$player['score'],
            'streak' => (int)$player['streak'],
            'last_question_correct' => (int)$player['last_question_correct'],
            'has_answered' => $hasAnswered,
            'answer_correct' => $answerCorrect,
            'points_earned' => $pointsEarned,
            'rank' => $rank
        ];
    }
}

// 7. Output Response
echo json_encode([
    'status' => 'success',
    'session' => [
        'id' => (int)$session['id'],
        'quiz_id' => (int)$session['quiz_id'],
        'quiz_title' => $session['quiz_title'],
        'pin' => $session['pin'],
        'status' => $session['status'],
        'current_question_id' => $session['current_question_id'] ? (int)$session['current_question_id'] : null,
        'current_question_started_at' => $session['current_question_started_at'] ? (float)$session['current_question_started_at'] : null,
        'current_question_ended_at' => $session['current_question_ended_at'] ? (float)$session['current_question_ended_at'] : null,
        'total_questions' => $totalQuestions,
        'order_num' => $question ? (int)$question['order_num'] : null,
        'question_text' => $question ? $question['question_text'] : null,
        'time_limit' => $question ? (int)$question['time_limit'] : null,
        'time_remaining' => (int)$timeRemaining,
        'answers' => $answers,
        'total_players' => $totalPlayers,
        'total_submitted' => $totalSubmitted,
        'answers_count' => $answersCount,
        'players_list' => $players
    ],
    'player' => $playerData
]);
