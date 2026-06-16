<?php
// api/game_stream.php
// Server-Sent Events (SSE) stream for real-time game updates.

// Disable time limit
set_time_limit(0);

// Prevent buffering
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable buffering for Nginx/Apache proxy layers

// Disable output buffering
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

require_once '../db.php';
require_once 'game_state_helper.php';

$sessionId = $_GET['session_id'] ?? null;
$playerId = $_GET['player_id'] ?? null;

if (!$sessionId) {
    echo "event: error\n";
    echo "data: " . json_encode(['message' => 'Missing session_id']) . "\n\n";
    flush();
    exit;
}

// Track states to only push on changes
$lastStatus = '';
$lastQuestionId = null;
$lastTotalPlayers = -1;
$lastTotalSubmitted = -1;
$lastPlayerScore = -1;
$lastPlayerHasAnswered = null;

$lastHeartbeat = time();
$startTime = time();
$maxExecutionTime = 600; // 10 minutes session duration before forcing reconnect

while (true) {
    // Check if client aborted connection
    if (connection_aborted()) {
        break;
    }

    // Force periodic reconnect to prevent orphan PHP processes accumulating
    if (time() - $startTime > $maxExecutionTime) {
        echo "event: reconnect\n";
        echo "data: {}\n\n";
        flush();
        break;
    }

    // Fetch optimized game state
    $state = get_game_state_data($pdo, $sessionId, $playerId);

    if ($state['status'] === 'error') {
        echo "event: error\n";
        echo "data: " . json_encode(['message' => $state['message']]) . "\n\n";
        flush();
        break;
    }

    $session = $state['session'];
    $player = $state['player'];

    $changed = false;

    // Check if session status or active question has changed
    if ($session['status'] !== $lastStatus ||
        $session['current_question_id'] !== $lastQuestionId) {
        $changed = true;
    }

    // Check client specific changes
    if ($playerId) {
        if ($player) {
            if ($player['score'] !== $lastPlayerScore ||
                $player['has_answered'] !== $lastPlayerHasAnswered) {
                $changed = true;
            }
        }
    } else {
        // Host needs to check participant counts and answer submission counts
        if ($session['total_players'] !== $lastTotalPlayers ||
            $session['total_submitted'] !== $lastTotalSubmitted) {
            $changed = true;
        }
    }

    // Always push updates during an active question phase to sync the countdown timer
    if ($session['status'] === 'question') {
        $changed = true;
    }

    if ($changed) {
        echo "data: " . json_encode($state) . "\n\n";
        flush();

        // Update tracking variables
        $lastStatus = $session['status'];
        $lastQuestionId = $session['current_question_id'];
        
        if ($playerId && $player) {
            $lastPlayerScore = $player['score'];
            $lastPlayerHasAnswered = $player['has_answered'];
        } else {
            $lastTotalPlayers = $session['total_players'];
            $lastTotalSubmitted = $session['total_submitted'];
        }
        
        $lastHeartbeat = time();
    } else {
        // Send a keep-alive heartbeat comment every 15 seconds to prevent browser/proxy connection timeout
        if (time() - $lastHeartbeat > 15) {
            echo ": keep-alive\n\n";
            flush();
            $lastHeartbeat = time();
        }
    }

    // Wait 1 second before querying state again (lowers DB polling storm)
    sleep(1);
}
