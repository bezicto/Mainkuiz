<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mainkuiz! - Active Game</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Mobile-first Overrides */
        body {
            justify-content: flex-start;
            padding: 0;
            background: #0d0221;
        }
        .player-game-container {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100vh;
            justify-content: space-between;
            padding: 1.25rem;
        }
        .player-bar {
            background: rgba(0, 0, 0, 0.4);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            border: 1px solid rgba(255,255,255,0.05);
        }
        /* Mobile Touch grid */
        .mobile-btn-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            flex-grow: 1;
            margin-bottom: 1rem;
        }
        .mobile-btn {
            border: none;
            border-radius: 16px;
            font-size: 3.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: transform 0.1s;
            box-shadow: var(--shadow-md);
        }
        .mobile-btn:active {
            transform: scale(0.95);
        }
        .mobile-btn.red { background-color: var(--color-red); }
        .mobile-btn.blue { background-color: var(--color-blue); }
        .mobile-btn.yellow { background-color: var(--color-yellow); color: #000; }
        .mobile-btn.green { background-color: var(--color-green); }

        /* Lock Screen */
        .locked-message {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 70vh;
            text-align: center;
        }
        .loading-pulse {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-glow);
            margin: 1.5rem 0;
            animation: pulse-ring 1.5s infinite ease-in-out;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 1; }
            100% { transform: scale(0.8); opacity: 0.5; }
        }

        /* Full-screen Results Feedback */
        .result-fullscreen {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 80vh;
            text-align: center;
            border-radius: 20px;
            padding: 2rem;
            animation: slide-fade-in 0.3s ease-out;
            box-shadow: var(--shadow-lg);
        }
        @keyframes slide-fade-in {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .result-fullscreen.correct { background: linear-gradient(135deg, #10d367 0%, #089443 100%); color: white; }
        .result-fullscreen.incorrect { background: linear-gradient(135deg, #ff3355 0%, #bb0022 100%); color: white; }
        .result-fullscreen.timeout { background: linear-gradient(135deg, #555555 0%, #222222 100%); color: white; }
        
        .result-title {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
        }
        .result-score {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 1rem 0;
        }
        .result-rank {
            background: rgba(0, 0, 0, 0.25);
            padding: 0.6rem 1.5rem;
            border-radius: 30px;
            font-weight: 600;
            margin-top: 1.5rem;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <!-- Sound Element for Player feed -->
    <div style="display: none;">
        <button id="sound-btn" onclick="toggleSound()">Mute</button>
    </div>

    <div class="player-game-container">
        <!-- Persistent Nickname & Score Footer/Header -->
        <div class="player-bar">
            <div style="font-weight: 800; font-size: 1.2rem;" id="player-nick-tag">Loading...</div>
            <div style="display: flex; gap: 1rem; font-weight: 700;">
                <div id="player-score-tag">Score: 0</div>
            </div>
        </div>

        <!-- 1. Lobby Phase Display -->
        <div id="ui-waiting" style="display: none;" class="locked-message">
            <h1 class="heading-lg" style="margin-bottom: 0.5rem;">You are in!</h1>
            <p style="color: var(--text-muted); font-size: 1.1rem;">Check your name on the host's screen.</p>
            <div class="loading-pulse"></div>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Waiting for the host to start...</p>
        </div>

        <!-- 2. Countdown Phase Display -->
        <div id="ui-countdown" style="display: none;" class="locked-message">
            <h1 class="heading-lg" style="font-size: 3rem;">Get Ready!</h1>
            <p style="color: var(--text-muted); font-size: 1.2rem;">Question loading on host screen...</p>
            <div class="loading-pulse" style="background-color: var(--color-yellow);"></div>
        </div>

        <!-- 3. Active Question Phase (Answer Choices Buttons) -->
        <div id="ui-question" style="display: none; flex-direction: column; flex-grow: 1;">
            <div class="mobile-btn-grid" id="options-btn-container">
                <!-- Answer buttons dynamically generated -->
            </div>
        </div>

        <!-- 4. Answer Locked Wait Screen -->
        <div id="ui-locked" style="display: none;" class="locked-message">
            <h1 class="heading-lg" style="font-size: 2.2rem; margin-bottom: 0.5rem;">Answer Submitted</h1>
            <p style="color: var(--text-muted); font-size: 1.1rem;">Waiting for timer or other players...</p>
            <div class="loading-pulse" style="background-color: var(--color-blue);"></div>
        </div>

        <!-- 5. Question Results Reveal Screen -->
        <div id="ui-answers" style="display: none;">
            <div id="result-feedback-card" class="result-fullscreen">
                <div class="result-title" id="result-text-header">Correct!</div>
                <div class="result-score" id="result-points-val">+950 pts</div>
                <div id="result-streak-flame" class="streak-container" style="color: #fff; font-size: 1.5rem;">🔥 Streak: 2</div>
                <div class="result-rank" id="result-rank-val">Rank 3</div>
            </div>
        </div>

        <!-- 6. Leaderboard Wait Screen -->
        <div id="ui-leaderboard" style="display: none;" class="locked-message">
            <h1 class="heading-lg" style="font-size: 2.2rem; margin-bottom: 0.5rem;">Check Host Screen</h1>
            <p style="color: var(--text-muted); font-size: 1.1rem;">Looking at the current leader standings...</p>
            <div class="loading-pulse" style="background-color: var(--primary-glow);"></div>
        </div>

        <!-- 7. Podium Game Over Screen -->
        <div id="ui-podium" style="display: none;" class="locked-message">
            <h1 class="heading-lg" style="font-size: 3rem; margin-bottom: 0.5rem;">Game Over!</h1>
            <p style="color: var(--text-muted); font-size: 1.2rem; margin-bottom: 1.5rem;" id="podium-final-rank">You finished in Rank 4</p>
            
            <div class="glass-container" style="padding: 1.5rem; width: 100%;">
                <h3 style="margin-bottom: 0.5rem; font-weight: 600;">Your Achievements:</h3>
                <p id="podium-final-score">Final Score: 4,500 pts</p>
            </div>
            
            <button onclick="leaveGame()" class="btn-primary mt-4">Play Again</button>
        </div>
    </div>

    <script src="../assets/js/audio.js"></script>
    <script>
        const playerId = sessionStorage.getItem('player_id');
        const sessionId = sessionStorage.getItem('session_id');
        const nickname = sessionStorage.getItem('nickname');

        // Redirect if session storage has cleared
        if (!playerId || !sessionId || !nickname) {
            window.location.href = '../index.php';
        }

        // Setup navbar tags
        document.getElementById('player-nick-tag').innerText = nickname;

        let currentStatus = '';
        let poller = null;
        let lastPlayedState = '';
        let hasSubmittedCurrent = false;

        function toggleSound() {
            gameAudio.toggleMute();
        }

        // 1. SSE Connection for Game State
        let eventSource = null;
        let currentQuestionId = null;

        function startStreaming() {
            if (eventSource) {
                eventSource.close();
            }
            eventSource = new EventSource(`../api/game_stream.php?session_id=${sessionId}&player_id=${playerId}`);
            eventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);
                if (data.status === 'success') {
                    handleStateTransition(data.session, data.player);
                } else {
                    stopStreaming();
                    alert('Session has closed.');
                    window.location.href = '../index.php';
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

        // 2. Client Phase UI Controller
        function handleStateTransition(session, player) {
            const newStatus = session.status;
            currentQuestionId = session.current_question_id;
            
            // Trigger audio on phase shifts
            if (newStatus !== lastPlayedState) {
                if (newStatus === 'answers') {
                    if (player.has_answered) {
                        if (player.answer_correct) {
                            gameAudio.playCorrect();
                        } else {
                            gameAudio.playIncorrect();
                        }
                    } else {
                        gameAudio.playTimeUp(); // Timeout buzz
                    }
                }
                lastPlayedState = newStatus;
            }

            currentStatus = newStatus;
            
            // Sync player score tag
            document.getElementById('player-score-tag').innerText = `Score: ${player.score}`;

            // Reset submission lock for a new question phase
            if (newStatus !== 'question') {
                hasSubmittedCurrent = false;
            }

            // Toggle HTML blocks
            const views = ['waiting', 'countdown', 'question', 'locked', 'answers', 'leaderboard', 'podium'];
            views.forEach(v => {
                const el = document.getElementById('ui-' + v);
                if (el) el.style.display = 'none';
            });

            if (newStatus === 'waiting') {
                document.getElementById('ui-waiting').style.display = 'flex';
            } 
            else if (newStatus === 'countdown') {
                document.getElementById('ui-countdown').style.display = 'flex';
            } 
            else if (newStatus === 'question') {
                // If player already submitted an answer, show locked screen instead of button pad
                if (player.has_answered || hasSubmittedCurrent) {
                    document.getElementById('ui-locked').style.display = 'flex';
                } else {
                    document.getElementById('ui-question').style.display = 'flex';
                    renderAnswerButtons(session.answers);
                }
            } 
            else if (newStatus === 'answers') {
                document.getElementById('ui-answers').style.display = 'block';
                showResultsFeedback(player);
            } 
            else if (newStatus === 'leaderboard') {
                document.getElementById('ui-leaderboard').style.display = 'flex';
            } 
            else if (newStatus === 'podium') {
                document.getElementById('ui-podium').style.display = 'flex';
                showPodiumFeedback(player);
            }
        }

        // Draw Mobile-friendly touch pads (Shapes only)
        function renderAnswerButtons(answers) {
            const container = document.getElementById('options-btn-container');
            container.innerHTML = '';
            
            const colors = ['red', 'blue', 'yellow', 'green'];
            const shapes = ['▲', '◆', '●', '■'];

            answers.forEach((ans, idx) => {
                const color = colors[idx] ?? 'red';
                const shape = shapes[idx] ?? '▲';
                
                const btn = document.createElement('button');
                btn.className = `mobile-btn ${color}`;
                btn.innerText = shape;
                btn.onclick = () => submitAnswer(ans.id);
                container.appendChild(btn);
            });
        }

        // POST Submission Handler
        function submitAnswer(answerId) {
            if (hasSubmittedCurrent) return;
            hasSubmittedCurrent = true;
            
            // Swap display instantly to prevent lag/double-taps
            document.getElementById('ui-question').style.display = 'none';
            document.getElementById('ui-locked').style.display = 'flex';

            // Use the cached currentQuestionId from the stream transition instead of fetching again!
            if (currentQuestionId) {
                const formData = new FormData();
                formData.append('action', 'submit_answer');
                formData.append('player_id', playerId);
                formData.append('question_id', currentQuestionId);
                formData.append('answer_id', answerId);

                fetch('../api/player_actions.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(result => {
                    if (result.status === 'success') {
                        // Points will update in stream transition
                    } else {
                        // Revert button pad on error
                        hasSubmittedCurrent = false;
                        document.getElementById('ui-locked').style.display = 'none';
                        document.getElementById('ui-question').style.display = 'flex';
                    }
                })
                .catch(err => {
                    console.error('Answer submission fail:', err);
                    hasSubmittedCurrent = false;
                    document.getElementById('ui-locked').style.display = 'none';
                    document.getElementById('ui-question').style.display = 'flex';
                });
            } else {
                hasSubmittedCurrent = false;
                document.getElementById('ui-locked').style.display = 'none';
                document.getElementById('ui-question').style.display = 'flex';
            }
        }

        // Show Correct/Incorrect/Timeout Feedback
        function showResultsFeedback(player) {
            const card = document.getElementById('result-feedback-card');
            const title = document.getElementById('result-text-header');
            const scoreVal = document.getElementById('result-points-val');
            const streakFlame = document.getElementById('result-streak-flame');
            const rankVal = document.getElementById('result-rank-val');

            // Reset CSS classes
            card.className = 'result-fullscreen';

            if (player.has_answered) {
                if (player.answer_correct) {
                    card.classList.add('correct');
                    title.innerText = 'Correct!';
                    scoreVal.innerText = `+${player.points_earned} pts`;
                    
                    if (player.streak > 1) {
                        streakFlame.style.display = 'inline-flex';
                        streakFlame.innerText = `🔥 Streak: ${player.streak}`;
                    } else {
                        streakFlame.style.display = 'none';
                    }
                } else {
                    card.classList.add('incorrect');
                    title.innerText = 'Incorrect';
                    scoreVal.innerText = '+0 pts';
                    streakFlame.style.display = 'none';
                }
            } else {
                card.classList.add('timeout');
                title.innerText = 'Time\'s Up!';
                scoreVal.innerText = '+0 pts';
                streakFlame.style.display = 'none';
            }

            rankVal.innerText = `Rank ${player.rank}`;
        }

        // Show Game Finished Overview
        function showPodiumFeedback(player) {
            document.getElementById('podium-final-rank').innerText = `You finished in Rank ${player.rank}`;
            document.getElementById('podium-final-score').innerText = `Final Score: ${player.score.toLocaleString()} pts`;
        }

        function leaveGame() {
            sessionStorage.clear();
            window.location.href = '../index.php';
        }

        // Initialize Audio Context on click
        document.body.addEventListener('click', () => {
            gameAudio.init();
        }, { once: true });

        // Start SSE stream
        startStreaming();
    </script>
</body>
</html>
