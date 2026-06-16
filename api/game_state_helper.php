<?php
// api/game_state_helper.php
// Optimized game state retrieval function to avoid redundant database queries.

function get_game_state_data($pdo, $sessionId, $playerId = null) {
    // 1. Fetch Game Session
    $stmt = $pdo->prepare("SELECT gs.*, q.title as quiz_title FROM game_sessions gs JOIN quizzes q ON gs.quiz_id = q.id WHERE gs.id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    
    if (!$session) {
        return ['status' => 'error', 'message' => 'Game session not found'];
    }
    
    $status = $session['status'];
    $quizId = $session['quiz_id'];
    $currentQuestionId = $session['current_question_id'];
    
    // Initialize common response variables
    $totalPlayers = 0;
    $playersList = [];
    $totalQuestions = 0;
    $question = null;
    $answers = [];
    $totalSubmitted = 0;
    $answersCount = [];
    $timeRemaining = 0;
    $playerData = null;

    $isHost = ($playerId === null);

    // Fetch total questions & player count (Only needed for Host, players don't display this)
    if ($isHost) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
        $stmt->execute([$quizId]);
        $totalQuestions = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $totalPlayers = (int)$stmt->fetchColumn();
        
        // Host only needs the lobby name list if game is in waiting phase
        if ($status === 'waiting') {
            $stmt = $pdo->prepare("SELECT id, nickname FROM players WHERE session_id = ? ORDER BY id DESC");
            $stmt->execute([$sessionId]);
            $playersList = $stmt->fetchAll();
        }
    }

    // Question-specific details
    if ($currentQuestionId) {
        if ($isHost) {
            // Host needs the question text and answer details
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
            $stmt->execute([$currentQuestionId]);
            $question = $stmt->fetch();
            
            if ($question) {
                // Fetch answers text and status
                $stmt = $pdo->prepare("SELECT id, answer_text, is_correct FROM answers WHERE question_id = ?");
                $stmt->execute([$currentQuestionId]);
                $rawAnswers = $stmt->fetchAll();
                
                // Anti-cheat: Hide correctness unless status is 'answers', 'leaderboard', or 'podium'
                $showCorrect = in_array($status, ['answers', 'leaderboard', 'podium']);
                foreach ($rawAnswers as $ans) {
                    $answers[] = [
                        'id' => (int)$ans['id'],
                        'answer_text' => $ans['answer_text'],
                        'is_correct' => $showCorrect ? (int)$ans['is_correct'] : null
                    ];
                }
                
                // Count total player answers submitted
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM player_answers pa 
                    JOIN players p ON pa.player_id = p.id 
                    WHERE pa.question_id = ? AND p.session_id = ?
                ");
                $stmt->execute([$currentQuestionId, $sessionId]);
                $totalSubmitted = (int)$stmt->fetchColumn();
                
                // Calculate answer breakdown graph values (only for reveal phases)
                if ($showCorrect) {
                    $stmt = $pdo->prepare("
                        SELECT pa.answer_id, COUNT(*) as count 
                        FROM player_answers pa
                        JOIN players p ON pa.player_id = p.id
                        WHERE pa.question_id = ? AND p.session_id = ?
                        GROUP BY pa.answer_id
                    ");
                    $stmt->execute([$currentQuestionId, $sessionId]);
                    $breakdown = $stmt->fetchAll();
                    
                    // Initialize count map
                    foreach ($answers as $ans) {
                        $answersCount[$ans['id']] = 0;
                    }
                    $answersCount['timeout'] = 0;
                    
                    foreach ($breakdown as $row) {
                        $key = $row['answer_id'] ?? 'timeout';
                        $answersCount[$key] = (int)$row['count'];
                    }
                }
            }
        } else {
            // Player request - only needs answer IDs if in 'question' status and they haven't answered yet
            if ($status === 'question') {
                // Check if player has already answered to decide if we need to return answer choice structure
                $stmt = $pdo->prepare("SELECT id FROM player_answers WHERE player_id = ? AND question_id = ?");
                $stmt->execute([$playerId, $currentQuestionId]);
                $hasAnswered = (bool)$stmt->fetch();
                
                if (!$hasAnswered) {
                    // Only return answer option IDs so player client can draw color/shape pads
                    $stmt = $pdo->prepare("SELECT id FROM answers WHERE question_id = ?");
                    $stmt->execute([$currentQuestionId]);
                    $rawAnswers = $stmt->fetchAll();
                    foreach ($rawAnswers as $ans) {
                        $answers[] = [
                            'id' => (int)$ans['id'],
                            'answer_text' => '', // Hide text to save DB fetch and bandwidth
                            'is_correct' => null
                        ];
                    }
                }
            }
        }
    }
    
    // Timer calculations
    if ($status === 'question' && $session['current_question_started_at']) {
        $timeLimit = 20; // Default
        
        if ($isHost && $question) {
            $timeLimit = (int)$question['time_limit'];
        } else {
            // Player needs time limit for calculations, fetch it directly
            $stmt = $pdo->prepare("SELECT time_limit FROM questions WHERE id = ?");
            $stmt->execute([$currentQuestionId]);
            $timeLimit = (int)$stmt->fetchColumn();
        }
        
        $nowMs = round(microtime(true) * 1000);
        $elapsedMs = $nowMs - (float)$session['current_question_started_at'];
        $limitMs = $timeLimit * 1000;
        
        $timeRemainingMs = $limitMs - $elapsedMs;
        $timeRemaining = ceil($timeRemainingMs / 1000);
        if ($timeRemaining < 0) {
            $timeRemaining = 0;
        }
    }
    
    // Player-specific info retrieval (if requested)
    if ($playerId) {
        $stmt = $pdo->prepare("SELECT id, nickname, score, streak, last_question_correct FROM players WHERE id = ? AND session_id = ?");
        $stmt->execute([$playerId, $sessionId]);
        $player = $stmt->fetch();
        
        if ($player) {
            $hasAnswered = false;
            $answerCorrect = false;
            $pointsEarned = 0;
            
            if ($currentQuestionId) {
                $stmt = $pdo->prepare("SELECT pa.*, a.is_correct FROM player_answers pa LEFT JOIN answers a ON pa.answer_id = a.id WHERE pa.player_id = ? AND pa.question_id = ?");
                $stmt->execute([$playerId, $currentQuestionId]);
                $pa = $stmt->fetch();
                if ($pa) {
                    $hasAnswered = true;
                    $answerCorrect = (bool)($pa['is_correct'] ?? false);
                    $pointsEarned = (int)$pa['points_earned'];
                }
            }
            
            // Only calculate player rank when showing results, leaderboard, or podium!
            // This avoids executing a count query for every player's poll during wait/question phases.
            $rank = 1;
            if (in_array($status, ['answers', 'leaderboard', 'podium'])) {
                $stmt = $pdo->prepare("SELECT COUNT(*) + 1 FROM players WHERE session_id = ? AND score > ?");
                $stmt->execute([$sessionId, $player['score']]);
                $rank = (int)$stmt->fetchColumn();
            }
            
            $playerData = [
                'id' => (int)$player['id'],
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
    
    return [
        'status' => 'success',
        'session' => [
            'id' => (int)$session['id'],
            'quiz_id' => (int)$session['quiz_id'],
            'quiz_title' => $session['quiz_title'],
            'pin' => $session['pin'],
            'status' => $session['status'],
            'current_question_id' => $currentQuestionId ? (int)$currentQuestionId : null,
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
            'players_list' => $playersList
        ],
        'player' => $playerData
    ];
}
