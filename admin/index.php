<?php
// admin/index.php
// Admin dashboard and login handler

session_start();
require_once '../db.php';

// Handle login post
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Simple admin credentials
    if ($username === 'admin' && $password === 'admin123') {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid admin username or password!';
    }
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_logged_in']);
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle create quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_quiz']) && isset($_SESSION['admin_logged_in'])) {
    $title = trim($_POST['title'] ?? '');
    if ($title !== '') {
        $stmt = $pdo->prepare("INSERT INTO quizzes (title) VALUES (?)");
        $stmt->execute([$title]);
        header('Location: index.php');
        exit;
    }
}

// Handle delete quiz
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_SESSION['admin_logged_in'])) {
    $quizId = (int) $_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
    $stmt->execute([$quizId]);
    header('Location: index.php');
    exit;
}

// Handle rename quiz
if (isset($_GET['action']) && $_GET['action'] === 'rename' && isset($_GET['id']) && isset($_SESSION['admin_logged_in'])) {
    $quizId = (int) $_GET['id'];
    $newTitle = trim($_POST['new_title'] ?? '');
    if ($newTitle !== '') {
        $stmt = $pdo->prepare("UPDATE quizzes SET title = ? WHERE id = ?");
        $stmt->execute([$newTitle, $quizId]);
        header('Location: index.php');
        exit;
    }
}

// Handle delete session history
if (isset($_GET['action']) && $_GET['action'] === 'delete_history' && isset($_GET['session_id']) && isset($_SESSION['admin_logged_in'])) {
    $historySessionId = (int) $_GET['session_id'];
    $stmt = $pdo->prepare("DELETE FROM game_sessions WHERE id = ? AND status = 'podium'");
    $stmt->execute([$historySessionId]);
    header('Location: index.php');
    exit;
}

// If not logged in, show login form
if (!isset($_SESSION['admin_logged_in'])):
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mainkuiz - Admin Login</title>
        <link rel="stylesheet" href="../assets/css/style.css">
    </head>

    <body>
        <div class="center-box">
            <div class="glass-container" style="width: 100%; max-width: 420px;">
                <div class="logo text-center mb-4" style="font-size: 2.5rem;">MAINKUIZ!</div>
                <h2 class="heading-md text-center mb-2">Admin Portal Login</h2>
                <p class="text-center mb-4" style="color: var(--text-muted);">Enter credentials to manage and host quizzes.
                </p>

                <?php if ($error): ?>
                    <div
                        style="background: rgba(255, 51, 85, 0.15); border: 1px solid var(--color-red); color: var(--color-red); padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-weight: 600; text-align: center;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index.php">
                    <input type="hidden" name="login" value="1">
                    <label
                        style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">USERNAME</label>
                    <input type="text" name="username" class="input-field" placeholder="e.g. admin" required
                        autocomplete="username">

                    <label
                        style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">PASSWORD</label>
                    <input type="password" name="password" class="input-field" placeholder="" required
                        autocomplete="current-password">

                    <button type="submit" class="btn-primary mt-2">Access Dashboard</button>
                </form>

                <div class="text-center mt-4">
                    <a href="../" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem;">&larr; Back to
                        Player Screen</a>
                </div>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
endif;

// Fetch all quizzes and question counts
$stmt = $pdo->query("
    SELECT q.*, COUNT(qu.id) as question_count 
    FROM quizzes q 
    LEFT JOIN questions qu ON q.id = qu.quiz_id 
    GROUP BY q.id 
    ORDER BY q.id DESC
");
$quizzes = $stmt->fetchAll();

// Fetch completed session history (where status is 'podium')
$stmt = $pdo->query("
    SELECT gs.*, q.title as quiz_title,
           (SELECT COUNT(*) FROM players WHERE session_id = gs.id) as participant_count
    FROM game_sessions gs
    JOIN quizzes q ON gs.quiz_id = q.id
    WHERE gs.status = 'podium'
    ORDER BY gs.created_at DESC
");
$completedSessions = $stmt->fetchAll();

$historyData = [];
foreach ($completedSessions as $sess) {
    // Get all players and scores for this session
    $stmt = $pdo->prepare("SELECT nickname, score FROM players WHERE session_id = ? ORDER BY score DESC, id ASC");
    $stmt->execute([$sess['id']]);
    $players = $stmt->fetchAll();

    $historyData[] = [
        'session' => $sess,
        'players' => $players
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mainkuiz - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <header>
        <div class="logo">MAINKUIZ! <span
                style="font-size: 0.9rem; font-weight: 400; color: var(--text-muted); vertical-align: middle;">ADMIN
                PANEL</span></div>
        <div style="display: flex; gap: 1rem; align-items: center;">
            <span style="font-weight: 600;">Welcome, Host!</span>
            <a href="index.php?action=logout" class="sound-toggle"
                style="text-decoration: none; border-color: var(--color-red); color: var(--color-red);">Logout</a>
        </div>
    </header>

    <main class="container">
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-top: 1rem;">
            <!-- Quizzes List -->
            <div class="glass-container">
                <h2 class="heading-md">Your Quizzes</h2>
                <?php if (empty($quizzes)): ?>
                    <p style="color: var(--text-muted); margin: 2rem 0; text-align: center;">No quizzes created yet. Use the
                        panel on the right to start!</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Quiz Name</th>
                                <th>Questions</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizzes as $quiz): ?>
                                <tr>
                                    <td style="font-weight: 600; font-size: 1.1rem;"><?= htmlspecialchars($quiz['title']) ?>
                                    </td>
                                    <td><span class="badge-success"><?= $quiz['question_count'] ?> Qs</span></td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                            <?php if ($quiz['question_count'] > 0): ?>
                                                <button onclick="hostGame(<?= $quiz['id'] ?>)" class="btn-primary"
                                                    style="padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto; background: linear-gradient(135deg, #10d367 0%, #089443 100%); box-shadow: none;">Host
                                                    Game</button>
                                            <?php else: ?>
                                                <button class="btn-secondary"
                                                    style="padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto; cursor: not-allowed; border-color: rgba(255,255,255,0.05); color: rgba(255,255,255,0.2);"
                                                    disabled title="Add questions first!">Host Game</button>
                                            <?php endif; ?>
                                            <button
                                                onclick="renameQuiz(<?= $quiz['id'] ?>, '<?= htmlspecialchars(addslashes($quiz['title'])) ?>')"
                                                class="btn-secondary"
                                                style="padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto; text-align: center;">Rename</button>
                                            <a href="quiz.php?id=<?= $quiz['id'] ?>" class="btn-secondary"
                                                style="padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto; text-decoration: none; text-align: center;">Edit
                                                Qs</a>
                                            <a href="index.php?action=delete&id=<?= $quiz['id'] ?>"
                                                onclick="return confirm('Are you sure you want to delete this quiz?')"
                                                class="btn-secondary"
                                                style="padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto; text-decoration: none; border-color: var(--color-red); color: var(--color-red); text-align: center;">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Create Quiz Panel -->
            <div class="glass-container" style="height: fit-content;">
                <h2 class="heading-md">New Quiz</h2>
                <form method="POST" action="index.php">
                    <input type="hidden" name="create_quiz" value="1">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem;">QUIZ
                        TITLE</label>
                    <input type="text" name="title" class="input-field" placeholder="e.g. World History Trivia"
                        required>
                    <button type="submit" class="btn-primary mt-2">Create Quiz &rarr;</button>
                </form>
            </div>
        </div>

        <!-- Session History Section -->
        <div class="glass-container mt-4">
            <h2 class="heading-md">🏆 Host Session History</h2>
            <p style="color: var(--text-muted); margin-bottom: 1.5rem;">Completed game sessions and top winner
                scoreboards.</p>

            <?php if (empty($historyData)): ?>
                <p style="color: var(--text-muted); margin: 2rem 0; text-align: center;">No completed sessions found yet.
                    Run and finish a quiz to see its scoreboard here!</p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Quiz Title</th>
                            <th>PIN</th>
                            <th>Total Players</th>
                            <th>Winners Podium</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyData as $item):
                            $sess = $item['session'];
                            $players = $item['players'];
                            $total = count($players);

                            $gold = $players[0] ?? null;
                            $silver = $players[1] ?? null;
                            $bronze = $players[2] ?? null;
                            ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($sess['created_at'])) ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($sess['quiz_title']) ?></td>
                                <td><span
                                        style="font-family: monospace; font-weight: 700; color: var(--primary-glow); font-size: 1.1rem;"><?= $sess['pin'] ?></span>
                                </td>
                                <td><span class="badge-success"
                                        style="background: rgba(0, 136, 255, 0.1); border-color: rgba(0, 136, 255, 0.2); color: var(--color-blue);"><?= $total ?>
                                        players</span></td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.85rem;">
                                        <?php if ($gold): ?>
                                            <span style="font-weight: 700; color: #ffd700;">🥇
                                                <?= htmlspecialchars($gold['nickname']) ?> (<?= $gold['score'] ?> pts)</span>
                                        <?php endif; ?>
                                        <?php if ($silver): ?>
                                            <span style="font-weight: 600; color: #b0b3b8;">🥈
                                                <?= htmlspecialchars($silver['nickname']) ?> (<?= $silver['score'] ?> pts)</span>
                                        <?php endif; ?>
                                        <?php if ($bronze): ?>
                                            <span style="font-weight: 600; color: #cd7f32;">🥉
                                                <?= htmlspecialchars($bronze['nickname']) ?> (<?= $bronze['score'] ?> pts)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                        <button onclick="toggleDetails(<?= $sess['id'] ?>)" class="btn-secondary"
                                            style="padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto;">Show All
                                            Marks</button>
                                        <a href="index.php?action=delete_history&session_id=<?= $sess['id'] ?>"
                                            onclick="return confirm('Are you sure you want to permanently delete this game history record?')"
                                            class="btn-secondary"
                                            style="padding: 0.4rem 0.8rem; font-size: 0.85rem; width: auto; text-decoration: none; border-color: var(--color-red); color: var(--color-red); text-align: center;">Delete</a>
                                    </div>
                                </td>
                            </tr>

                            <!-- Collapsible Scoreboard Row -->
                            <tr id="details-row-<?= $sess['id'] ?>" style="display: none; background: rgba(0, 0, 0, 0.15);">
                                <td colspan="6" style="padding: 1.5rem; border-bottom: 1px solid var(--card-border);">
                                    <h4 style="margin-bottom: 1rem; font-weight: 600; color: var(--text-color);">Full scoreboard
                                        details (Session PIN: <?= htmlspecialchars($sess['pin']) ?>)</h4>
                                    <?php if ($total === 0): ?>
                                        <p style="color: var(--text-muted); font-size: 0.95rem;">No players joined this lobby.</p>
                                    <?php else: ?>
                                        <div
                                            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem;">
                                            <?php foreach ($players as $idx => $p): ?>
                                                <div
                                                    style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                                                    <span style="font-size: 0.95rem;">
                                                        <strong
                                                            style="color: var(--text-muted); margin-right: 0.4rem;">#<?= $idx + 1 ?></strong>
                                                        <?= htmlspecialchars($p['nickname']) ?>
                                                    </span>
                                                    <span
                                                        style="color: var(--primary-glow); font-weight: 800; font-size: 0.95rem;"><?= $p['score'] ?>
                                                        pts</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function hostGame(quizId) {
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('quiz_id', quizId);

            fetch('../api/host_actions.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Redirect to host host.php view
                        window.location.href = 'host.php?session_id=' + data.session_id;
                    } else {
                        alert('Error creating game session: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Connection failure to host controller.');
                });
        }

        function toggleDetails(sessionId) {
            const row = document.getElementById('details-row-' + sessionId);
            if (row.style.display === 'none') {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        }

        function renameQuiz(quizId, currentTitle) {
            const newTitle = prompt("Rename Quiz:", currentTitle);
            if (newTitle && newTitle.trim() !== "" && newTitle !== currentTitle) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'index.php?action=rename&id=' + quizId;

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'new_title';
                input.value = newTitle.trim();

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>

</html>