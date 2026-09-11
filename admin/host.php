<?php
// admin/host.php
// The projector host screen where questions are displayed and admin controls transitions

session_start();
require_once '../db.php';

// Verify admin session
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}
session_write_close(); // Release session file lock immediately!

$sessionId = $_GET['session_id'] ?? null;
if (!$sessionId) {
    header('Location: index.php');
    exit;
}

// Fetch session and quiz details
$stmt = $pdo->prepare("
    SELECT gs.*, q.title as quiz_title 
    FROM game_sessions gs 
    JOIN quizzes q ON gs.quiz_id = q.id 
    WHERE gs.id = ?
");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    header('Location: index.php');
    exit;
}

// Pre-fetch participants if game is in waiting phase for instant render
$initialPlayers = [];
if ($session['status'] === 'waiting') {
    $stmt = $pdo->prepare("SELECT id, nickname FROM players WHERE session_id = ? ORDER BY id ASC");
    $stmt->execute([$sessionId]);
    $initialPlayers = $stmt->fetchAll();
}
$initialPlayerCount = count($initialPlayers);

// Handle local leaderboard fetch requests (before any HTML output!)
if (isset($_GET['action']) && $_GET['action'] === 'get_leaders') {
    $limit = (int)($_GET['limit'] ?? 5);
    $stmt = $pdo->prepare("SELECT nickname, score, streak FROM players WHERE session_id = ? ORDER BY score DESC, id ASC LIMIT $limit");
    $stmt->execute([$sessionId]);
    $leaders = $stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode($leaders);
    exit;
}

// Calculate base URL path for the application root (e.g., /kashoot or empty)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$adminDir = str_replace('\\', '/', dirname($scriptPath));
$appDir = str_replace('\\', '/', dirname($adminDir));
$appBasePath = ($appDir === '/' || $appDir === '.' || $appDir === '\\') ? '' : $appDir;

// Detect server LAN IPv4 address (e.g., 192.168.x.x) for seamless smartphone connectivity
$detectedLanIp = null;
$sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock) {
    if (@socket_connect($sock, '8.8.8.8', 53)) {
        @socket_getsockname($sock, $detectedLanIp);
    }
    @socket_close($sock);
}
if (!$detectedLanIp || $detectedLanIp === '127.0.0.1') {
    $detectedLanIp = !empty($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1' && $_SERVER['SERVER_ADDR'] !== '::1' 
        ? $_SERVER['SERVER_ADDR'] 
        : null;
}

$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostParts = explode(':', $httpHost);
$portSuffix = (isset($hostParts[1]) && $hostParts[1]) ? (':' . $hostParts[1]) : '';
$lanHost = $detectedLanIp ? ($detectedLanIp . $portSuffix) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosting: <?= htmlspecialchars($session['quiz_title']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="../assets/js/qrcode.min.js"></script>
    <style>
        /* Host Specific Styles */
        .host-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 1rem 2rem;
        }
        .pin-display {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--primary-glow);
            letter-spacing: 2px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px dashed var(--primary-glow);
            padding: 0.5rem 1.5rem;
            border-radius: 12px;
        }
        .host-lobby {
            text-align: center;
            padding: 3rem 1rem;
        }
        .lobby-stats {
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin: 2rem 0;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .lobby-stat-val {
            font-size: 3rem;
            font-weight: 800;
            color: var(--color-blue);
        }
        /* Countdown Overlay */
        .countdown-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: var(--bg-dark);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .countdown-number {
            font-size: 10rem;
            font-weight: 800;
            color: var(--primary-glow);
            animation: bounce-in 1s infinite alternate;
        }
        @keyframes bounce-in {
            0% { transform: scale(0.6); opacity: 0.2; }
            100% { transform: scale(1.1); opacity: 1; }
        }
        /* Question layout */
        .host-question-box {
            text-align: center;
            padding: 2rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .question-text-host {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 1rem auto 2rem auto;
            max-width: 900px;
            line-height: 1.3;
        }
        .timer-progress {
            font-size: 2.5rem;
            font-weight: 800;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 6px solid var(--primary-glow);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 15px rgba(138, 43, 226, 0.4);
            margin: 0 auto;
        }
        .answers-submitted-panel {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 1.5rem;
            width: fit-content;
            margin: 0 auto;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .answers-submitted-num {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--color-green);
        }
        
        /* Bar Chart Styles */
        .chart-container {
            display: flex;
            justify-content: center;
            align-items: flex-end;
            height: 280px;
            gap: 1.5rem;
            margin: 2rem auto;
            max-width: 700px;
        }
        .chart-bar-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100px;
        }
        .chart-bar {
            width: 100%;
            border-radius: 8px 8px 0 0;
            min-height: 10px;
            transition: height 1s ease-out;
            position: relative;
            box-shadow: var(--shadow-md);
        }
        .chart-bar.red { background: var(--color-red); }
        .chart-bar.blue { background: var(--color-blue); }
        .chart-bar.yellow { background: var(--color-yellow); }
        .chart-bar.green { background: var(--color-green); }
        .chart-bar-count {
            position: absolute;
            top: -30px;
            font-size: 1.3rem;
            font-weight: 800;
            width: 100%;
            text-align: center;
        }
        .chart-label {
            margin-top: 0.75rem;
            font-size: 1.5rem;
        }

        .correct-indicator {
            background: rgba(16, 211, 103, 0.15);
            border: 2px solid var(--color-green) !important;
        }
        .incorrect-fade {
            opacity: 0.25;
        }
    </style>
</head>
<body>
    <!-- Sound Toggle Option -->
    <div style="position: fixed; bottom: 1.5rem; left: 1.5rem; z-index: 2000;">
        <button onclick="toggleSound()" id="sound-btn" class="sound-toggle">
            🔊 Sound: ON
        </button>
    </div>

    <!-- 1. Lobby Phase (Rendered visible if status is waiting) -->
    <div id="phase-waiting" class="phase-section" style="<?= $session['status'] === 'waiting' ? 'display: block;' : 'display: none;' ?>">
        <div class="host-header" style="justify-content: center; border-bottom: none; background: transparent; padding-top: 2rem;">
            <div class="logo" style="font-size: 3rem; text-align: center;">MAINKUIZ!</div>
        </div>
        
        <div class="container host-lobby" style="padding-top: 1rem;">
            <!-- Giant Join Instructions & QR Code Card -->
            <div class="host-lobby-card">
                <div class="host-lobby-split">
                    <!-- Left: URL, Host switcher & PIN -->
                    <div class="host-join-info">
                        <div class="host-join-title">
                            <span>Join at</span>
                            <span id="join-url-text" class="host-join-url"><?= htmlspecialchars(($lanHost ?: $httpHost) . $appBasePath) ?></span>
                            <button type="button" onclick="copyJoinLink()" class="host-copy-btn" id="copy-btn">📋 Copy</button>
                        </div>
                        
                        <?php if ($lanHost && (str_starts_with($httpHost, 'localhost') || str_starts_with($httpHost, '127.0.0.1'))): ?>
                        <div class="host-ip-selector" id="host-ip-selector">
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Display:</span>
                            <button type="button" class="host-ip-chip active" id="chip-lan" onclick="switchHostMode('lan')">📶 Wi-Fi IP (<?= htmlspecialchars($lanHost) ?>)</button>
                            <button type="button" class="host-ip-chip" id="chip-local" onclick="switchHostMode('local')">💻 Localhost</button>
                        </div>
                        <?php endif; ?>

                        <div class="host-pin-label">with Game PIN:</div>
                        <div class="host-pin-box">
                            <?= htmlspecialchars($session['pin']) ?>
                        </div>
                    </div>

                    <!-- Right: QR Code for instant phone entry -->
                    <div class="host-qr-section">
                        <div class="host-qr-card" onclick="openQrModal()" title="Click to enlarge QR code">
                            <div id="lobby-qrcode" class="qr-canvas-holder"></div>
                        </div>
                        <div class="host-qr-subtext">📱 Scan to join directly</div>
                        <div class="host-qr-enlarge-hint" onclick="openQrModal()">🔍 Click to enlarge</div>
                    </div>
                </div>
            </div>

            <h1 class="heading-lg" style="font-size: 1.8rem; color: var(--text-muted); margin-bottom: 1rem;">Waiting for players to join...</h1>
            <div class="lobby-stats">
                <div>
                    <div id="lobby-player-count" class="lobby-stat-val"><?= $initialPlayerCount ?></div>
                    <div>Participants</div>
                </div>
            </div>
            
            <button onclick="startGame()" class="btn-primary" style="max-width: 300px; margin-top: 1rem;">Start Quiz</button>
            
            <div class="nickname-list" id="lobby-nicknames">
                <?php foreach ($initialPlayers as $idx => $p): ?>
                    <div class="nickname-badge" style="--delay: <?= ($idx % 5) * 0.5 ?>s;"><?= htmlspecialchars($p['nickname']) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 2. Countdown Phase -->
    <div id="phase-countdown" class="countdown-overlay" style="display: none;">
        <div class="countdown-number" id="countdown-timer-box">3</div>
    </div>

    <!-- 3. Question Phase -->
    <div id="phase-question" class="phase-section" style="display: none;">
        <header>
            <div class="logo">MAINKUIZ!</div>
            <div style="font-weight: 600; font-size: 1.1rem;" id="q-counter-display">Question 1 of 5</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button type="button" onclick="openQrModal()" class="host-copy-btn" style="padding: 0.45rem 0.8rem; font-size: 0.85rem;" title="Show QR Code">📱 PIN: <?= htmlspecialchars($session['pin']) ?></button>
                <button onclick="skipQuestion()" class="btn-primary" style="padding: 0.5rem 1.2rem; font-size: 0.9rem; width: auto;">Skip Timer</button>
            </div>
        </header>
        
        <div class="container host-question-box">
            <div>
                <div class="timer-progress" id="question-timer-circle">20</div>
                <h1 class="question-text-host" id="question-text-display">What is the capital of Malaysia?</h1>
            </div>
            
            <div class="answers-submitted-panel">
                <span class="answers-submitted-num" id="submitted-count">0</span> / <span id="lobby-active-count">0</span> Answers Submitted
            </div>
            
            <div class="game-grid" id="question-options-grid">
                <!-- Answer option shapes will be drawn here -->
            </div>
        </div>
    </div>

    <!-- 4. Answer Phase (Graph) -->
    <div id="phase-answers" class="phase-section" style="display: none;">
        <header>
            <div class="logo">MAINKUIZ!</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <h3 style="font-weight: 600; font-size: 1.1rem; margin: 0;">Answer Breakdown</h3>
                <button type="button" onclick="openQrModal()" class="host-copy-btn" style="padding: 0.35rem 0.75rem; font-size: 0.85rem;" title="Show QR Code">📱 PIN: <?= htmlspecialchars($session['pin']) ?></button>
            </div>
            <div>
                <button onclick="showLeaderboard()" class="btn-primary" style="padding: 0.5rem 1.2rem; font-size: 0.9rem; width: auto; background: linear-gradient(135deg, #0088ff 0%, #0055bb 100%);">Next &rarr;</button>
            </div>
        </header>
        
        <div class="container" style="text-align: center;">
            <h1 class="question-text-host" id="result-question-display" style="font-size: 2.5rem; font-weight: 800; margin: 1rem auto 2.5rem auto; max-width: 900px; line-height: 1.3;">What is the capital of Malaysia?</h1>
            
            <!-- Dynamic Graph -->
            <div class="chart-container" id="results-chart">
                <!-- Chart bars injected here -->
            </div>
            
            <div class="game-grid" id="result-options-grid">
                <!-- Options rendered, correct highlighted, incorrect faded -->
            </div>
        </div>
    </div>

    <!-- 5. Leaderboard Phase -->
    <div id="phase-leaderboard" class="phase-section" style="display: none;">
        <header>
            <div class="logo">MAINKUIZ!</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <h3 style="font-weight: 600; font-size: 1.1rem; margin: 0;">Leaderboard</h3>
                <button type="button" onclick="openQrModal()" class="host-copy-btn" style="padding: 0.35rem 0.75rem; font-size: 0.85rem;" title="Show QR Code">📱 PIN: <?= htmlspecialchars($session['pin']) ?></button>
            </div>
            <div>
                <button onclick="nextQuestion()" class="btn-primary" style="padding: 0.5rem 1.2rem; font-size: 0.9rem; width: auto;">Next Question &rarr;</button>
            </div>
        </header>
        
        <div class="container" style="max-width: 700px;">
            <h1 class="heading-lg" style="margin-top: 1rem;">Top Scores</h1>
            <div class="leaderboard-list" id="leaderboard-players-rows">
                <!-- Top 5 rows injected here -->
            </div>
            
            <!-- Ranks 6-10 Mini List -->
            <div id="leaderboard-mini-section" style="margin-top: 2rem; display: none;">
                <h3 style="font-size: 1.1rem; font-weight: 600; color: var(--text-muted); text-align: center; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 1px;">Runners Up (6-10)</h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem;" id="leaderboard-mini-players">
                    <!-- Ranks 6-10 mini badges injected here -->
                </div>
            </div>
        </div>
    </div>

    <!-- 6. Podium Phase -->
    <div id="phase-podium" class="phase-section" style="display: none;">
        <header>
            <div class="logo">MAINKUIZ!</div>
            <h3 style="font-weight: 600; font-size: 1.1rem;">Final Podium</h3>
            <div>
                <a href="index.php" class="btn-secondary" style="padding: 0.5rem 1.2rem; font-size: 0.9rem; width: auto; text-decoration: none;">End Session</a>
            </div>
        </header>
        
        <div class="container">
            <h1 class="heading-lg" style="margin-top: 1rem; background: linear-gradient(45deg, #ffd700, #ff5500); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">CONGRATULATIONS WINNERS!</h1>
            
            <!-- 3D Podium -->
            <div class="podium-container" id="podium-winners">
                <!-- Injected Podium Steps -->
            </div>
        </div>
    </div>

    <!-- Fullscreen Enlarged QR Modal -->
    <div id="qr-enlarge-modal" class="qr-modal-overlay" style="display: none;" onclick="closeQrModal(event)">
        <div class="qr-modal-card" onclick="event.stopPropagation()">
            <button type="button" class="qr-modal-close" onclick="closeQrModal()" title="Close">&times;</button>
            <h2 style="font-size: 1.8rem; font-weight: 800; margin-bottom: 0.3rem; letter-spacing: 0.5px;">Scan to Join</h2>
            <p style="color: var(--text-muted); font-size: 1.05rem;" id="modal-join-url-text">Join at ...</p>
            <div class="qr-modal-code-wrapper" id="modal-qrcode"></div>
            <div style="font-size: 1.1rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem;">Game PIN:</div>
            <div class="host-pin-box" style="font-size: 3.5rem; padding: 0.4rem 2rem;"><?= htmlspecialchars($session['pin']) ?></div>
            <div style="margin-top: 1.2rem;">
                <button type="button" onclick="copyJoinLink()" class="btn-primary" style="padding: 0.6rem 1.5rem; font-size: 0.95rem; width: auto; text-transform: none; display: inline-flex; align-items: center; gap: 0.5rem; margin: 0 auto;">
                    📋 Copy Direct Join Link
                </button>
            </div>
        </div>
    </div>

    <script src="../assets/js/audio.js"></script>
    <script>
        const sessionId = <?= $sessionId ?>;
        const gamePin = '<?= addslashes($session['pin']) ?>';
        const detectedLanHost = <?= json_encode($lanHost) ?>;
        const appBasePath = <?= json_encode($appBasePath) ?>;

        // Auto-select LAN IP when host accessed via localhost so players can join
        const browserHost = window.location.host;
        let activeHost = (browserHost.startsWith('localhost') || browserHost.startsWith('127.0.0.1')) && detectedLanHost
            ? detectedLanHost
            : browserHost;

        function getDisplayUrl() {
            return activeHost + appBasePath;
        }

        function getFullJoinUrl(withPin = true) {
            const protocol = window.location.protocol;
            const base = `${protocol}//${activeHost}${appBasePath}/`;
            return withPin ? `${base}?pin=${encodeURIComponent(gamePin)}` : base;
        }

        let lobbyQrInstance = null;
        let modalQrInstance = null;

        function renderQrCodes() {
            const qrUrl = getFullJoinUrl(true);
            const displayUrl = getDisplayUrl();

            const urlTextEl = document.getElementById('join-url-text');
            if (urlTextEl) urlTextEl.innerText = displayUrl;

            const modalUrlEl = document.getElementById('modal-join-url-text');
            if (modalUrlEl) modalUrlEl.innerText = 'Join at ' + displayUrl;

            // Render Lobby QR code
            const lobbyBox = document.getElementById('lobby-qrcode');
            if (lobbyBox && typeof QRCode !== 'undefined') {
                lobbyBox.innerHTML = '';
                try {
                    lobbyQrInstance = new QRCode(lobbyBox, {
                        text: qrUrl,
                        width: 170,
                        height: 170,
                        colorDark: "#0a041a",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } catch (e) {
                    console.error('Error rendering lobby QR:', e);
                }
            }

            // Render Modal QR code
            const modalBox = document.getElementById('modal-qrcode');
            if (modalBox && typeof QRCode !== 'undefined') {
                modalBox.innerHTML = '';
                try {
                    modalQrInstance = new QRCode(modalBox, {
                        text: qrUrl,
                        width: 280,
                        height: 280,
                        colorDark: "#0a041a",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } catch (e) {
                    console.error('Error rendering modal QR:', e);
                }
            }
        }

        function switchHostMode(mode) {
            if (mode === 'lan' && detectedLanHost) {
                activeHost = detectedLanHost;
                document.getElementById('chip-lan')?.classList.add('active');
                document.getElementById('chip-local')?.classList.remove('active');
            } else {
                activeHost = browserHost;
                document.getElementById('chip-local')?.classList.add('active');
                document.getElementById('chip-lan')?.classList.remove('active');
            }
            renderQrCodes();
        }

        function openQrModal() {
            const modal = document.getElementById('qr-enlarge-modal');
            if (modal) {
                modal.style.display = 'flex';
                renderQrCodes();
            }
        }

        function closeQrModal(e) {
            if (!e || e.target.id === 'qr-enlarge-modal' || e.target.classList.contains('qr-modal-close')) {
                const modal = document.getElementById('qr-enlarge-modal');
                if (modal) modal.style.display = 'none';
            }
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('qr-enlarge-modal');
                if (modal && modal.style.display !== 'none') modal.style.display = 'none';
            }
        });

        function copyJoinLink() {
            const link = getFullJoinUrl(true);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(() => {
                    showToast('Copied direct join link with PIN!');
                }).catch(() => {
                    prompt('Copy this join link:', link);
                });
            } else {
                prompt('Copy this join link:', link);
            }
        }

        function showToast(msg) {
            const oldToast = document.querySelector('.toast-msg');
            if (oldToast) oldToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast-msg';
            toast.innerText = '✓ ' + msg;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, 2500);
        }

        let currentStatus = '';
        let poller = null;
        let countdownTimer = null;
        
        // Track state sounds to trigger only ONCE per transition
        let lastPlayedState = '';
        let podiumRendered = false;

        function toggleSound() {
            const muted = gameAudio.toggleMute();
            const btn = document.getElementById('sound-btn');
            btn.innerHTML = muted ? '🔇 Sound: OFF' : '🔊 Sound: ON';
            
            // If lobby is active and we unmuted, start loop
            if (!muted && currentStatus === 'waiting') {
                gameAudio.startLobbyMusic();
            }
        }

        // 1. Host State Synchronization (Direct & Resilient)
        let eventSource = null;
        let fallbackPollTimer = null;
        let sseHealthy = false;
        let lastSsePacketTime = 0;

        function fetchStateDirect(force = false) {
            fetch('../api/game_state.php?session_id=' + sessionId + (force ? '&force=1' : ''))
                .then(res => res.json())
                .then(data => {
                    if (data && data.status === 'success' && data.session) {
                        handleStateTransition(data.session);
                    }
                })
                .catch(err => console.warn('Direct fetch state notice:', err));
        }

        // Resilient Fallback Poller: Runs every 1.5s to guarantee the host screen never gets stuck
        function startFallbackPolling() {
            if (fallbackPollTimer) return;
            fallbackPollTimer = setInterval(() => {
                fetchStateDirect(false);
            }, 1500);
        }

        function stopFallbackPolling() {
            if (fallbackPollTimer) {
                clearInterval(fallbackPollTimer);
                fallbackPollTimer = null;
            }
        }


        function startStreaming() {
            if (eventSource) {
                eventSource.close();
            }
            sseHealthy = false;

            // Start resilient fallback polling immediately
            startFallbackPolling();

            eventSource = new EventSource('../api/game_stream.php?session_id=' + sessionId);
            eventSource.onmessage = function(event) {
                try {
                    const data = JSON.parse(event.data);
                    if (data.status === 'success') {
                        sseHealthy = true;
                        lastSsePacketTime = Date.now();
                        handleStateTransition(data.session);
                    }
                } catch (e) {
                    console.error('Failed to parse SSE payload:', e);
                }
            };
            eventSource.addEventListener('reconnect', function() {
                startStreaming();
            });
            eventSource.onerror = function(err) {
                console.warn('SSE stream notice, utilizing fallback polling:', err);
                eventSource.close();
                fetchStateDirect();
                setTimeout(startStreaming, 3000);
            };
        }

        function stopStreaming() {
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
            stopFallbackPolling();
        }

        // 2. State Controller Transitions
        function handleStateTransition(session) {
            const newStatus = session.status;
            
            // Handle sound events on state changes
            if (newStatus !== lastPlayedState) {
                if (newStatus === 'waiting') {
                    gameAudio.startLobbyMusic();
                } else {
                    gameAudio.stopLobbyMusic();
                }
                
                if (newStatus === 'question') {
                    gameAudio.startQuestionMusic();
                } else {
                    gameAudio.stopQuestionMusic();
                }
                
                if (newStatus === 'countdown') {
                    runLobbyCountdown(session);
                }
                if (newStatus === 'answers') {
                    gameAudio.playTimeUp();
                }
                if (newStatus === 'podium') {
                    gameAudio.playFanfare();
                }
                lastPlayedState = newStatus;
            }

            if (newStatus !== 'podium') {
                podiumRendered = false;
            }
            if (newStatus !== 'question') {
                if (questionTimerInterval) {
                    clearInterval(questionTimerInterval);
                    questionTimerInterval = null;
                }
                isTransitioningToResults = false;
            }

            currentStatus = newStatus;
            
            // Hide all phases first
            document.querySelectorAll('.phase-section').forEach(el => el.style.display = 'none');
            document.getElementById('phase-countdown').style.display = 'none';

            if (newStatus === 'waiting') {
                document.getElementById('phase-waiting').style.display = 'block';
                updateLobbyUI(session.players_list, session.total_players);
            } 
            else if (newStatus === 'countdown') {
                document.getElementById('phase-countdown').style.display = 'flex';
            } 
            else if (newStatus === 'question') {
                document.getElementById('phase-question').style.display = 'block';
                updateQuestionUI(session);
            } 
            else if (newStatus === 'answers') {
                document.getElementById('phase-answers').style.display = 'block';
                updateAnswersUI(session);
            } 
            else if (newStatus === 'leaderboard') {
                document.getElementById('phase-leaderboard').style.display = 'block';
                updateLeaderboardUI();
            } 
            else if (newStatus === 'podium') {
                document.getElementById('phase-podium').style.display = 'block';
                if (!podiumRendered) {
                    updatePodiumUI();
                    podiumRendered = true;
                }
            }
        }

        // Phase: Lobby Wait
        let renderedPlayerIds = new Set();
        function updateLobbyUI(players, totalCount) {
            document.getElementById('lobby-player-count').innerText = totalCount;
            const container = document.getElementById('lobby-nicknames');
            if (!players || !Array.isArray(players)) return;

            // Reconcile badges smoothly without clearing container if unchanged
            const isDifferent = players.length !== renderedPlayerIds.size || players.some(p => !renderedPlayerIds.has(p.id));
            if (!isDifferent) return;

            container.innerHTML = '';
            renderedPlayerIds.clear();
            players.slice(0, 60).forEach((p, idx) => {
                renderedPlayerIds.add(p.id);
                const badge = document.createElement('div');
                badge.className = 'nickname-badge';
                badge.style.setProperty('--delay', (idx % 5) * 0.5);
                badge.innerText = p.nickname;
                container.appendChild(badge);
            });

            if (totalCount > 60) {
                const moreBadge = document.createElement('div');
                moreBadge.className = 'nickname-badge';
                moreBadge.style.opacity = '0.7';
                moreBadge.innerText = `+${totalCount - 60} more`;
                container.appendChild(moreBadge);
            }
        }


        // Phase: 3s Countdown Animation
        function runLobbyCountdown(session) {
            let val = 3;
            const timerBox = document.getElementById('countdown-timer-box');
            timerBox.innerText = val;
            gameAudio.playTick();

            if (countdownTimer) clearInterval(countdownTimer);
            countdownTimer = setInterval(() => {
                val--;
                if (val > 0) {
                    timerBox.innerText = val;
                    gameAudio.playTick();
                } else {
                    clearInterval(countdownTimer);
                    countdownTimer = null;
                    // Automatically trigger backend start_question
                    triggerHostAction('start_question');
                }
            }, 1000);
        }

        let questionTimerInterval = null;
        let activeTimerQuestionId = null;
        let isTransitioningToResults = false;
        let currentRenderedQuestionId = null;

        // Smooth local countdown timer (synchronizes with server start time and avoids buffering stutter)
        function syncQuestionTimer(session) {
            const timerBox = document.getElementById('question-timer-circle');
            if (!timerBox) return;

            const startedAt = Number(session.current_question_started_at) || Date.now();
            const timeLimit = Number(session.time_limit) || 20;
            const serverNow = Number(session.server_time) || Date.now();
            const clientOffset = Date.now() - serverNow; // offset between client and server


            function tick() {
                if (currentStatus !== 'question') {
                    if (questionTimerInterval) {
                        clearInterval(questionTimerInterval);
                        questionTimerInterval = null;
                    }
                    return;
                }

                const adjustedNow = Date.now() - clientOffset;
                const elapsedSec = Math.max(0, (adjustedNow - startedAt) / 1000);
                const remaining = Math.max(0, Math.ceil(timeLimit - elapsedSec));

                timerBox.innerText = remaining;

                // Tension tick
                if (remaining <= 5 && remaining > 0) {
                    gameAudio.playTick();
                }

                // Time expired!
                if (remaining <= 0) {
                    if (questionTimerInterval) {
                        clearInterval(questionTimerInterval);
                        questionTimerInterval = null;
                    }
                    if (currentStatus === 'question' && !isTransitioningToResults) {
                        isTransitioningToResults = true;
                        triggerHostAction('show_results');
                    }
                }
            }

            // Start a new interval when entering a new question
            if (activeTimerQuestionId !== session.current_question_id || !questionTimerInterval) {
                activeTimerQuestionId = session.current_question_id;
                isTransitioningToResults = false;
                if (questionTimerInterval) clearInterval(questionTimerInterval);
                tick();
                questionTimerInterval = setInterval(tick, 250);
            }
        }

        // Phase: Active Question Display
        function updateQuestionUI(session) {
            document.getElementById('q-counter-display').innerText = `Question ${session.order_num} of ${session.total_questions}`;
            document.getElementById('question-text-display').innerText = session.question_text;
            
            document.getElementById('submitted-count').innerText = session.total_submitted;
            document.getElementById('lobby-active-count').innerText = session.total_players;

            // Start or sync the smooth local countdown timer
            syncQuestionTimer(session);

            // Display options (only re-render DOM if question changed to avoid layout thrashing)
            if (currentRenderedQuestionId !== session.current_question_id) {
                currentRenderedQuestionId = session.current_question_id;
                const grid = document.getElementById('question-options-grid');
                grid.innerHTML = '';
                
                const colors = ['red', 'blue', 'yellow', 'green'];
                const shapes = ['▲', '◆', '●', '■'];
                
                session.answers.forEach((ans, idx) => {
                    const color = colors[idx] ?? 'red';
                    const shape = shapes[idx] ?? '▲';
                    
                    const card = document.createElement('div');
                    card.className = `answer-card ${color}`;
                    card.style.cursor = 'default';
                    card.innerHTML = `<span class="option-shape">${shape}</span> <span>${ans.answer_text}</span>`;
                    grid.appendChild(card);
                });
            }

            // Auto-trigger show_results when ALL active participants have submitted answers!
            if (session.total_players > 0 && session.total_submitted >= session.total_players) {
                if (currentStatus === 'question' && !isTransitioningToResults) {
                    isTransitioningToResults = true;
                    if (questionTimerInterval) {
                        clearInterval(questionTimerInterval);
                        questionTimerInterval = null;
                    }
                    triggerHostAction('show_results');
                }
            }
        }

        // Phase: Answers Reveal Graph
        function updateAnswersUI(session) {
            document.getElementById('result-question-display').innerText = session.question_text;
            
            // Chart distribution calculations
            const chart = document.getElementById('results-chart');
            chart.innerHTML = '';
            
            const grid = document.getElementById('result-options-grid');
            grid.innerHTML = '';
            
            const colors = ['red', 'blue', 'yellow', 'green'];
            const shapes = ['▲', '◆', '●', '■'];
            
            // Compute percentage scaling
            const counts = session.answers_count || {};
            const totalVotes = Object.values(counts).reduce((a, b) => a + b, 0) || 1; // avoid division by zero

            session.answers.forEach((ans, idx) => {
                const color = colors[idx] ?? 'red';
                const shape = shapes[idx] ?? '▲';
                const count = counts[ans.id] || 0;
                
                // Scale height up to max 240px
                const barHeight = Math.max(10, Math.round((count / totalVotes) * 240));

                // 1. Add Bar
                const barWrapper = document.createElement('div');
                barWrapper.className = 'chart-bar-wrapper';
                barWrapper.innerHTML = `
                    <div class="chart-bar ${color}" style="height: ${barHeight}px">
                        <div class="chart-bar-count">${count}</div>
                    </div>
                    <div class="chart-label">${shape}</div>
                `;
                chart.appendChild(barWrapper);

                // 2. Add answer key card (Highlight correct answer, fade others)
                const card = document.createElement('div');
                const isCorrect = ans.is_correct === 1;
                card.className = `answer-card ${color} ${isCorrect ? 'correct-indicator' : 'incorrect-fade'}`;
                card.style.cursor = 'default';
                card.innerHTML = `<span class="option-shape">${shape}</span> <span>${ans.answer_text} ${isCorrect ? '✓' : ''}</span>`;
                grid.appendChild(card);
            });
        }

        // Phase: Leaderboard Rank display
        function updateLeaderboardUI() {
            fetchLeaderboardData();
        }

        function fetchLeaderboardData() {
            const rowsContainer = document.getElementById('leaderboard-players-rows');
            rowsContainer.innerHTML = '';
            
            const miniSection = document.getElementById('leaderboard-mini-section');
            const miniContainer = document.getElementById('leaderboard-mini-players');
            miniContainer.innerHTML = '';
            miniSection.style.display = 'none';

            // Fetch top 10 leaders
            fetch('host.php?action=get_leaders&session_id=' + sessionId + '&limit=10')
            .then(res => res.json())
            .then(players => {
                players.forEach((p, idx) => {
                    if (idx < 5) {
                        // Ranks 1-5: Large rows
                        const row = document.createElement('div');
                        row.className = 'leaderboard-row';
                        
                        let rankBadge = `${idx + 1}`;
                        if (idx === 0) rankBadge = '👑 1';
                        
                        row.innerHTML = `
                            <div class="leaderboard-rank">${rankBadge}</div>
                            <div class="leaderboard-name">${p.nickname} ${p.streak > 1 ? `<span class="streak-container">🔥 ${p.streak}</span>` : ''}</div>
                            <div class="leaderboard-score">${p.score}</div>
                        `;
                        rowsContainer.appendChild(row);
                    } else {
                        // Ranks 6-10: Mini cards
                        miniSection.style.display = 'block';
                        const card = document.createElement('div');
                        card.style.cssText = "display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); padding: 0.6rem 1.2rem; border-radius: 10px; font-size: 0.95rem; font-weight: 500; transition: transform 0.2s;";
                        card.innerHTML = `
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span style="color: var(--text-muted); font-weight: 700;">#${idx + 1}</span>
                                <span>${p.nickname}</span>
                            </div>
                            <span style="color: var(--primary-glow); font-weight: 700;">${p.score}</span>
                        `;
                        miniContainer.appendChild(card);
                    }
                });
            });
        }

        // Phase: Podium Final Showcase
        function updatePodiumUI() {
            const container = document.getElementById('podium-winners');
            container.innerHTML = '';
            
            fetch('host.php?action=get_leaders&session_id=' + sessionId + '&limit=3')
            .then(res => res.json())
            .then(players => {
                // Podium order: Silver (2nd), Gold (1st), Bronze (3rd)
                const podiumSlots = [
                    { rank: 2, key: 1, class: 'silver', val: players[1] },
                    { rank: 1, key: 0, class: 'gold', val: players[0] },
                    { rank: 3, key: 2, class: 'bronze', val: players[2] }
                ];
                
                podiumSlots.forEach(slot => {
                    if (slot.val) {
                        const step = document.createElement('div');
                        step.className = `podium-step ${slot.class}`;
                        step.innerHTML = `
                            <div class="podium-name">${slot.val.nickname}</div>
                            <div class="podium-score">${slot.val.score} pts</div>
                            <div class="rank-number">${slot.rank}</div>
                        `;
                        container.appendChild(step);
                    }
                });
            });
        }

        // 3. Controller Actions (POST triggers)
        function startGame() {
            triggerHostAction('start_game');
        }

        function skipQuestion() {
            triggerHostAction('show_results');
        }

        function showLeaderboard() {
            triggerHostAction('show_leaderboard');
        }

        function nextQuestion() {
            triggerHostAction('next_question');
        }

        function triggerHostAction(actionName) {
            const formData = new FormData();
            formData.append('action', actionName);
            formData.append('session_id', sessionId);

            fetch('../api/host_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                // Immediately refresh host state to guarantee zero-latency UI transition
                fetchStateDirect(true);
            })
            .catch(err => {
                console.error('Network Error:', err);
                fetchStateDirect(true);
            });
        }


        // Initialize Audio context trigger on first click anywhere
        document.body.addEventListener('click', function() {
            gameAudio.init();
        }, { once: true });

        // Initial render of QR codes and URL display
        renderQrCodes();

        // Immediate direct state fetch (guarantees fast UI sync)
        fetchStateDirect();

        // Start SSE stream with automatic fallback polling
        startStreaming();
    </script>
</body>
</html>

