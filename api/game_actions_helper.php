<?php
// api/game_actions_helper.php
// Shared state transition helpers (ending questions, scoring timeouts).

require_once __DIR__ . '/game_cache.php';

function end_question_and_show_results($pdo, $sessionId) {
    try {
        $wasInTransaction = $pdo->inTransaction();
        if (!$wasInTransaction) {
            $pdo->beginTransaction();
        }

        // Lock session for update (FOR UPDATE on MySQL/MariaDB; omitted on SQLite)
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockClause = ($driver === 'sqlite') ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare("SELECT * FROM game_sessions WHERE id = ?" . $lockClause);
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            if (!$wasInTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        // Only transition if currently in 'question' status
        if ($session['status'] !== 'question') {
            if (!$wasInTransaction) {
                $pdo->rollBack();
            }
            return true; // Already transitioned
        }

        $nowMs = round(microtime(true) * 1000);
        $questionId = $session['current_question_id'];

        // 1. Mark session as showing answers
        $stmt = $pdo->prepare("UPDATE game_sessions SET status = 'answers', current_question_ended_at = ? WHERE id = ?");
        $stmt->execute([$nowMs, $sessionId]);

        // 2. High-performance set-based timeout handling (Single SQL statement instead of PHP array loops)
        if ($questionId) {
            $insertIgnore = ($driver === 'sqlite') ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
            // Insert timeout records (answer_id = NULL, points = 0) for all participants with no submitted answer
            $stmt = $pdo->prepare("
                $insertIgnore player_answers (player_id, question_id, answer_id, points_earned, response_time_ms)
                SELECT p.id, ?, NULL, 0, 0
                FROM players p
                LEFT JOIN player_answers pa ON pa.player_id = p.id AND pa.question_id = ?
                WHERE p.session_id = ? AND pa.id IS NULL
            ");
            $stmt->execute([$questionId, $questionId, $sessionId]);

            // Reset streaks and last_question_correct for players who timed out
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("
                    UPDATE players
                    SET streak = 0, last_question_correct = 0
                    WHERE id IN (
                        SELECT player_id FROM player_answers WHERE question_id = ? AND answer_id IS NULL
                    ) AND session_id = ?
                ");
                $stmt->execute([$questionId, $sessionId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE players p
                    JOIN player_answers pa ON pa.player_id = p.id
                    SET p.streak = 0, p.last_question_correct = 0
                    WHERE p.session_id = ? AND pa.question_id = ? AND pa.answer_id IS NULL
                ");
                $stmt->execute([$sessionId, $questionId]);
            }
        }

        // 3. Batch-calculate and persist player ranks in a single SQL statement
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("
                    UPDATE players 
                    SET rank = r.calculated_rank
                    FROM (
                        SELECT id, DENSE_RANK() OVER (ORDER BY score DESC, id ASC) as calculated_rank 
                        FROM players 
                        WHERE session_id = ?
                    ) r
                    WHERE players.id = r.id AND players.session_id = ?
                ");
                $stmt->execute([$sessionId, $sessionId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE players p
                    JOIN (
                        SELECT id, DENSE_RANK() OVER (ORDER BY score DESC, id ASC) as calculated_rank
                        FROM players
                        WHERE session_id = ?
                    ) r ON p.id = r.id
                    SET p.rank = r.calculated_rank
                    WHERE p.session_id = ?
                ");
                $stmt->execute([$sessionId, $sessionId]);
            }
        } catch (\Throwable $e) {
            error_log("Rank batch update notice: " . $e->getMessage());
        }

        if (!$wasInTransaction) {
            $pdo->commit();
        }

        // Invalidate cached state so all players immediately receive the new results phase
        GameCache::invalidate((int)$sessionId);

        return true;
    } catch (Exception $e) {
        if (!$wasInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in end_question_and_show_results: " . $e->getMessage());
        return false;
    }
}

