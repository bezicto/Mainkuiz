<?php
// api/game_stream.php
// Server-Sent Events (SSE) stream for real-time game updates.

// Disable script time limit if server allows
@set_time_limit(0);

// Disable compression and output buffering to prevent proxy/server buffering
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', '1');
ob_implicit_flush(true);

// Headers to prevent caching and buffering in Nginx, Apache, LiteSpeed, Cloudflare
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('X-LiteSpeed-Buffer: no');
header('Content-Encoding: none');

// Clear existing output buffers
while (ob_get_level() > 0) {
    ob_end_flush();
}

// Send 4KB initial padding comment to immediately clear proxy buffers (Nginx, LiteSpeed, Cloudflare)
echo ":" . str_repeat(" ", 4096) . "\n\n";
flush();

require_once '../db.php';
require_once 'game_state_helper.php';
require_once 'game_cache.php';

$sessionId = (int)($_GET['session_id'] ?? 0);
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;

if (!$sessionId) {
    echo "event: error\n";
    echo "data: " . json_encode(['message' => 'Missing session_id']) . "\n\n";
    flush();
    exit;
}

// Track states to only push on changes
$lastVersion = -1;
$lastStatus = '';
$lastQuestionId = null;
$lastTotalPlayers = -1;
$lastTotalSubmitted = -1;
$lastPlayerScore = -1;
$lastPlayerHasAnswered = null;

$lastHeartbeat = time();
$startTime = time();
// Set 25-second cycle before clean reconnect to stay safely under shared host 30s max_execution_time
$maxExecutionTime = 25;

while (true) {
    // Check if client aborted connection
    if (connection_aborted()) {
        break;
    }

    // Proactively cycle connection to prevent 504 gateway timeouts on shared hosting
    if (time() - $startTime > $maxExecutionTime) {
        echo "event: reconnect\n";
        echo "data: {}\n\n";
        echo ":" . str_repeat(" ", 4096) . "\n\n";
        flush();
        break;
    }

    // Fast primary key check for session state version
    $stmt = $pdo->prepare("SELECT status, current_question_id, current_question_ended_at FROM game_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $sess = $stmt->fetch();

    if (!$sess) {
        echo "event: error\n";
        echo "data: " . json_encode(['message' => 'Game session not found']) . "\n\n";
        flush();
        break;
    }

    $extraKey = '';
    // For host: track lobby participant count or active answer submissions
    if (!$playerId) {
        if ($sess['status'] === 'waiting') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE session_id = ?");
            $stmt->execute([$sessionId]);
            $extraKey = '_' . $stmt->fetchColumn();
        } else if ($sess['status'] === 'question' && $sess['current_question_id']) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM player_answers pa JOIN players p ON pa.player_id = p.id WHERE p.session_id = ? AND pa.question_id = ?");
            $stmt->execute([$sessionId, $sess['current_question_id']]);
            $extraKey = '_' . $stmt->fetchColumn();
        }
    }

    $currVersion = crc32($sess['status'] . '_' . ($sess['current_question_id'] ?? 0) . '_' . ($sess['current_question_ended_at'] ?? 0) . $extraKey);

    // Only query full database state when version changes or on initial connect
    if ($currVersion !== $lastVersion || $lastVersion === -1) {

        // Fetch optimized game state
        $state = get_game_state_data($pdo, $sessionId, $playerId);

        if ($state['status'] === 'error') {
            echo "event: error\n";
            echo "data: " . json_encode(['message' => $state['message']]) . "\n\n";
            flush();
            break;
        }

        echo "data: " . json_encode($state) . "\n\n";
        echo ":" . str_repeat(" ", 4096) . "\n\n";
        flush();

        $lastVersion = $currVersion;
        $lastHeartbeat = time();
    } else {
        // Send a keep-alive heartbeat comment every 10 seconds
        if (time() - $lastHeartbeat > 10) {
            echo ": keep-alive\n\n";
            echo ":" . str_repeat(" ", 4096) . "\n\n";
            flush();
            $lastHeartbeat = time();
        }
    }

    // High responsiveness with minimal CPU impact: check cache every 350ms
    usleep(350000);
}

