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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosting: <?= htmlspecialchars($session['quiz_title']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
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

    <!-- 1. Lobby Phase -->
    <div id="phase-waiting" class="phase-section" style="display: none;">
        <div class="host-header" style="justify-content: center; border-bottom: none; background: transparent; padding-top: 2rem;">
            <div class="logo" style="font-size: 3rem; text-align: center;">MAINKUIZ!</div>
        </div>
        
        <div class="container host-lobby" style="padding-top: 1rem;">
            <!-- Giant Centered Join Instructions & PIN -->
            <div style="background: var(--card-bg); border: 1px solid var(--card-border); padding: 2.5rem; border-radius: 24px; max-width: 650px; margin: 0 auto 2.5rem auto; box-shadow: var(--shadow-lg); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);">
                <p style="font-size: 1.6rem; font-weight: 600; color: var(--text-muted); margin-bottom: 1rem;">Join at <span style="color: #fff; font-weight: 800; border-bottom: 2px solid var(--primary-glow); padding-bottom: 2px;">mainkuiz.test</span></p>
                <p style="font-size: 1.3rem; font-weight: 600; color: var(--text-muted); margin-bottom: 1.5rem;">with Game PIN:</p>
                <div style="font-size: 5.5rem; font-weight: 800; color: var(--primary-glow); letter-spacing: 4px; background: rgba(0, 0, 0, 0.4); border: 3px dashed var(--primary-glow); padding: 0.75rem 3rem; border-radius: 20px; display: inline-block; box-shadow: 0 0 45px rgba(138, 43, 226, 0.35); text-shadow: 0 0 10px rgba(138, 43, 226, 0.5);">
                    <?= htmlspecialchars($session['pin']) ?>
                </div>
            </div>

            <h1 class="heading-lg" style="font-size: 1.8rem; color: var(--text-muted); margin-bottom: 1rem;">Waiting for players to join...</h1>
            <div class="lobby-stats">
                <div>
                    <div id="lobby-player-count" class="lobby-stat-val">0</div>
                    <div>Participants</div>
                </div>
            </div>
            
            <button onclick="startGame()" class="btn-primary" style="max-width: 300px; margin-top: 1rem;">Start Quiz</button>
            
            <div class="nickname-list" id="lobby-nicknames">
                <!-- Connected players will inject here dynamically -->
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
            <div>
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
            <h3 style="font-weight: 600; font-size: 1.1rem;">Answer Breakdown</h3>
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
            <h3 style="font-weight: 600; font-size: 1.1rem;">Leaderboard</h3>
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

    <script src="../assets/js/audio.js"></script>
    <script>
        const sessionId = <?= $sessionId ?>;
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

        // 1. SSE Connection for Game State
        let eventSource = null;

        function startStreaming() {
            if (eventSource) {
                eventSource.close();
            }
            eventSource = new EventSource('../api/game_stream.php?session_id=' + sessionId);
            eventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);
                if (data.status === 'success') {
                    handleStateTransition(data.session);
                }
            };
            eventSource.addEventListener('reconnect', function() {
                startStreaming();
            });
            eventSource.onerror = function(err) {
                console.error('SSE Stream error:', err);
                eventSource.close();
                setTimeout(startStreaming, 3000);
            };
        }

        function stopStreaming() {
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
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
        function updateLobbyUI(players, totalCount) {
            document.getElementById('lobby-player-count').innerText = totalCount;
            const container = document.getElementById('lobby-nicknames');
            container.innerHTML = '';
            
            players.forEach((p, idx) => {
                const badge = document.createElement('div');
                badge.className = 'nickname-badge';
                badge.style.setProperty('--delay', (idx % 5) * 0.5);
                badge.innerText = p.nickname;
                container.appendChild(badge);
            });
        }

        // Phase: 3s Countdown Animation
        function runLobbyCountdown(session) {
            stopStreaming(); // Pause state stream while hosting local countdown
            let val = 3;
            const timerBox = document.getElementById('countdown-timer-box');
            timerBox.innerText = val;
            gameAudio.playTick();

            countdownTimer = setInterval(() => {
                val--;
                if (val > 0) {
                    timerBox.innerText = val;
                    gameAudio.playTick();
                } else {
                    clearInterval(countdownTimer);
                    // Automatically trigger backend start_question
                    triggerHostAction('start_question');
                }
            }, 1000);
        }

        // Phase: Active Question Display
        function updateQuestionUI(session) {
            document.getElementById('q-counter-display').innerText = `Question ${session.order_num} of ${session.total_questions}`;
            document.getElementById('question-text-display').innerText = session.question_text;
            
            const timerBox = document.getElementById('question-timer-circle');
            timerBox.innerText = session.time_remaining;
            
            // Sound tick for tension
            if (session.time_remaining <= 5 && session.time_remaining > 0) {
                gameAudio.playTick();
            }

            document.getElementById('submitted-count').innerText = session.total_submitted;
            document.getElementById('lobby-active-count').innerText = session.total_players;

            // Display options (shapes only, matching classical Kahoot design)
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

            // Auto-trigger show_results when time runs out
            if (session.time_remaining <= 0 && currentStatus === 'question') {
                triggerHostAction('show_results');
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
                if (data.status === 'success') {
                    if (!eventSource) {
                        startStreaming();
                    }
                } else {
                    console.error('Action failed:', data.message);
                }
            })
            .catch(err => console.error('Network Error:', err));
        }

        // Initialize Audio context trigger on first click anywhere
        document.body.addEventListener('click', function() {
            gameAudio.init();
        }, { once: true });

        // Start SSE stream
        startStreaming();
    </script>
</body>
</html>

