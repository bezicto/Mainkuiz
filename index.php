<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mainkuiz! - Join Game</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .player-join-box {
            width: 90%;
            max-width: 380px;
            margin: 0 auto;
        }
        .logo-giant {
            font-size: 3.5rem;
            font-weight: 800;
            text-align: center;
            letter-spacing: 2px;
            margin-bottom: 2rem;
            text-shadow: 0 0 25px rgba(138, 43, 226, 0.4);
        }
    </style>
</head>
<body>
    <div class="center-box">
        <div class="player-join-box">
            <div class="logo logo-giant">MAINKUIZ!</div>
            
            <div class="glass-container">
                <h2 class="heading-md text-center" style="font-size: 1.4rem; margin-bottom: 0.75rem;" id="form-title">Enter Game PIN</h2>
                
                <div id="pin-badge" style="display: none; background: rgba(138, 43, 226, 0.2); border: 1px solid var(--primary-glow); color: #fff; padding: 0.35rem 0.9rem; border-radius: 20px; font-size: 0.9rem; font-weight: 700; margin: 0 auto 1.25rem auto; text-align: center; width: fit-content; box-shadow: 0 0 12px rgba(138, 43, 226, 0.3);">
                </div>

                <div id="error-message" style="display: none; background: rgba(255, 51, 85, 0.15); border: 1px solid var(--color-red); color: var(--color-red); padding: 0.75rem; border-radius: 10px; margin-bottom: 1.25rem; font-weight: 600; text-align: center; font-size: 0.95rem;">
                </div>

                <!-- Step 1: PIN Input -->
                <div id="step-pin">
                    <input type="number" id="game-pin" class="input-field text-center" placeholder="PIN Number" style="font-size: 1.5rem; font-weight: 700; letter-spacing: 2px;" pattern="[0-9]*" inputmode="numeric">
                    <button onclick="submitPin()" class="btn-primary" id="btn-enter-pin">Enter</button>
                </div>

                <!-- Step 2: Nickname Input (hidden initially) -->
                <div id="step-nickname" style="display: none;">
                    <input type="text" id="player-nickname" class="input-field text-center" placeholder="Nickname" maxlength="20" style="font-weight: 600;">
                    <button onclick="submitNickname()" class="btn-primary">OK, Go!</button>
                    <div style="text-align: center; margin-top: 1rem;">
                        <a href="javascript:void(0)" onclick="resetPin()" style="font-size: 0.85rem; color: var(--text-muted); text-decoration: underline;">Change PIN</a>
                    </div>
                </div>
            </div>
            
            <!-- Quick Link to Host Admin Portal -->
            <div class="text-center mt-4">
                <a href="admin/" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem; font-weight: 600;">Create or Host a Quiz &rarr;</a>
            </div>
        </div>
    </div>

    <script>
        let validatedPin = '';

        // Clear old sessions
        sessionStorage.clear();

        function showError(msg) {
            const errBox = document.getElementById('error-message');
            errBox.innerText = msg;
            errBox.style.display = 'block';
            setTimeout(() => {
                errBox.scrollIntoView({ behavior: 'smooth' });
            }, 50);
        }

        function hideError() {
            document.getElementById('error-message').style.display = 'none';
        }

        function resetPin() {
            hideError();
            validatedPin = '';
            document.getElementById('step-nickname').style.display = 'none';
            document.getElementById('step-pin').style.display = 'block';
            document.getElementById('pin-badge').style.display = 'none';
            document.getElementById('form-title').innerText = 'Enter Game PIN';
            const pinInput = document.getElementById('game-pin');
            pinInput.value = '';
            pinInput.focus();
        }

        function submitPin(isAuto = false) {
            hideError();
            const pinVal = document.getElementById('game-pin').value.trim();
            if (!pinVal) {
                showError('Please enter a Game PIN.');
                return;
            }

            if (isAuto) {
                document.getElementById('form-title').innerText = 'Verifying PIN...';
            }

            // Quick check against API
            fetch('api/game_state.php?pin=' + encodeURIComponent(pinVal))
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.session.status !== 'waiting') {
                        document.getElementById('form-title').innerText = 'Enter Game PIN';
                        showError('This quiz session has already started!');
                    } else if (data.session.max_players && data.session.total_players >= data.session.max_players) {
                        document.getElementById('form-title').innerText = 'Enter Game PIN';
                        showError('This game lobby is full (maximum ' + data.session.max_players + ' players allowed).');
                    } else {
                        validatedPin = pinVal;
                        // Swap inputs
                        document.getElementById('step-pin').style.display = 'none';
                        document.getElementById('step-nickname').style.display = 'block';
                        document.getElementById('form-title').innerText = 'Choose Nickname';
                        
                        const pinBadge = document.getElementById('pin-badge');
                        pinBadge.innerText = 'PIN: ' + pinVal + ' ✓';
                        pinBadge.style.display = 'inline-block';

                        document.getElementById('player-nickname').focus();
                    }
                } else {
                    document.getElementById('form-title').innerText = 'Enter Game PIN';
                    showError('Game PIN not found. Double-check your numbers.');
                }
            })
            .catch(err => {
                console.error(err);
                document.getElementById('form-title').innerText = 'Enter Game PIN';
                showError('Connection failed. Are you online?');
            });
        }

        function submitNickname() {
            hideError();
            const nicknameVal = document.getElementById('player-nickname').value.trim();
            if (!nicknameVal) {
                showError('Please choose a nickname.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'join');
            formData.append('pin', validatedPin);
            formData.append('nickname', nicknameVal);

            fetch('api/player_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Save session settings
                    sessionStorage.setItem('player_id', data.player_id);
                    sessionStorage.setItem('session_id', data.session_id);
                    sessionStorage.setItem('nickname', data.nickname);
                    sessionStorage.setItem('pin', data.pin);

                    // Redirect to gameplay interface
                    window.location.href = 'player/game.php';
                } else {
                    showError(data.message);
                }
            })
            .catch(err => {
                console.error(err);
                showError('Database registration failed.');
            });
        }

        // Allow 'Enter' key submit
        document.getElementById('game-pin').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') submitPin();
        });
        document.getElementById('player-nickname').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') submitNickname();
        });

        // Check for PIN in URL parameters (e.g., from QR code scan)
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const pinParam = urlParams.get('pin');
            if (pinParam) {
                document.getElementById('game-pin').value = pinParam.trim();
                submitPin(true);
            }
        });
    </script>
</body>
</html>
