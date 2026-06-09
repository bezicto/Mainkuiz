<?php
// admin/quiz.php
// Edit questions and answers for a specific quiz

session_start();
require_once '../db.php';

// Verify admin session
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$quizId = $_GET['id'] ?? null;
if (!$quizId) {
    header('Location: index.php');
    exit;
}

// Fetch quiz details
$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    header('Location: index.php');
    exit;
}

// Handle Add Question Action
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $questionText = trim($_POST['question_text'] ?? '');
    $timeLimit = (int)($_POST['time_limit'] ?? 20);
    $points = (int)($_POST['points'] ?? 1000);
    
    $optRed = trim($_POST['opt_red'] ?? '');
    $optBlue = trim($_POST['opt_blue'] ?? '');
    $optYellow = trim($_POST['opt_yellow'] ?? '');
    $optGreen = trim($_POST['opt_green'] ?? '');
    
    $correctOpt = $_POST['correct_option'] ?? ''; // 'red', 'blue', 'yellow', 'green'
    
    if ($questionText === '' || $optRed === '' || $optBlue === '' || $optYellow === '' || $optGreen === '') {
        $error = 'Question text and all 4 options are required!';
    } elseif (!in_array($correctOpt, ['red', 'blue', 'yellow', 'green'])) {
        $error = 'Please select which option is correct!';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get order number
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(order_num), 0) + 1 FROM questions WHERE quiz_id = ?");
            $stmt->execute([$quizId]);
            $orderNum = (int)$stmt->fetchColumn();
            
            // Insert question
            $stmt = $pdo->prepare("INSERT INTO questions (quiz_id, question_text, time_limit, points, order_num) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$quizId, $questionText, $timeLimit, $points, $orderNum]);
            $questionId = $pdo->lastInsertId();
            
            // Insert 4 answers
            $opts = [
                'red' => $optRed,
                'blue' => $optBlue,
                'yellow' => $optYellow,
                'green' => $optGreen
            ];
            
            foreach ($opts as $color => $text) {
                $isCorrect = ($correctOpt === $color) ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO answers (question_id, answer_text, is_correct) VALUES (?, ?, ?)");
                $stmt->execute([$questionId, $text, $isCorrect]);
            }
            
            $pdo->commit();
            header("Location: quiz.php?id=$quizId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to save question: ' . $e->getMessage();
        }
    }
}

// Handle Edit Question Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_question'])) {
    $questionId = (int)$_POST['question_id'];
    $questionText = trim($_POST['question_text'] ?? '');
    $timeLimit = (int)($_POST['time_limit'] ?? 20);
    $points = (int)($_POST['points'] ?? 1000);
    
    $optRed = trim($_POST['opt_red'] ?? '');
    $optBlue = trim($_POST['opt_blue'] ?? '');
    $optYellow = trim($_POST['opt_yellow'] ?? '');
    $optGreen = trim($_POST['opt_green'] ?? '');
    
    $correctOpt = $_POST['correct_option'] ?? '';
    
    if ($questionText === '' || $optRed === '' || $optBlue === '' || $optYellow === '' || $optGreen === '') {
        $error = 'Question text and all 4 options are required!';
    } elseif (!in_array($correctOpt, ['red', 'blue', 'yellow', 'green'])) {
        $error = 'Please select which option is correct!';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update question
            $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, time_limit = ?, points = ? WHERE id = ? AND quiz_id = ?");
            $stmt->execute([$questionText, $timeLimit, $points, $questionId, $quizId]);
            
            // Update answers in order
            $stmt = $pdo->prepare("SELECT id FROM answers WHERE question_id = ? ORDER BY id ASC");
            $stmt->execute([$questionId]);
            $ansIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $opts = [
                'red' => $optRed,
                'blue' => $optBlue,
                'yellow' => $optYellow,
                'green' => $optGreen
            ];
            
            $colors = ['red', 'blue', 'yellow', 'green'];
            foreach ($colors as $idx => $color) {
                if (isset($ansIds[$idx])) {
                    $isCorrect = ($correctOpt === $color) ? 1 : 0;
                    $stmt = $pdo->prepare("UPDATE answers SET answer_text = ?, is_correct = ? WHERE id = ?");
                    $stmt->execute([$opts[$color], $isCorrect, $ansIds[$idx]]);
                }
            }
            
            $pdo->commit();
            header("Location: quiz.php?id=$quizId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to edit question: ' . $e->getMessage();
        }
    }
}

// Handle Delete Question Action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['q_id'])) {
    $qId = (int)$_GET['q_id'];
    
    // Validate question belongs to this quiz
    $stmt = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND quiz_id = ?");
    $stmt->execute([$qId, $quizId]);
    if ($stmt->fetch()) {
        // Delete question (triggers cascade delete on answers table)
        $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
        $stmt->execute([$qId]);
        
        // Re-order remaining questions
        $stmt = $pdo->prepare("SELECT id FROM questions WHERE quiz_id = ? ORDER BY order_num ASC");
        $stmt->execute([$quizId]);
        $remainingQs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($remainingQs as $idx => $id) {
            $stmt = $pdo->prepare("UPDATE questions SET order_num = ? WHERE id = ?");
            $stmt->execute([$idx + 1, $id]);
        }
        
        header("Location: quiz.php?id=$quizId");
        exit;
    }
}

// Fetch edit details if edit mode is active
$editQId = $_GET['edit_q_id'] ?? null;
$editQuestion = null;
$editAnswers = [];
if ($editQId) {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ? AND quiz_id = ?");
    $stmt->execute([$editQId, $quizId]);
    $editQuestion = $stmt->fetch();
    
    if ($editQuestion) {
        $stmt = $pdo->prepare("SELECT * FROM answers WHERE question_id = ? ORDER BY id ASC");
        $stmt->execute([$editQId]);
        $editAnswers = $stmt->fetchAll();
    }
}

// Fetch all questions and their answers for this quiz
$stmt = $pdo->prepare("SELECT * FROM questions WHERE quiz_id = ? ORDER BY order_num ASC");
$stmt->execute([$quizId]);
$questions = $stmt->fetchAll();

$questionsList = [];
foreach ($questions as $q) {
    $stmt = $pdo->prepare("SELECT id, answer_text, is_correct FROM answers WHERE question_id = ?");
    $stmt->execute([$q['id']]);
    $answers = $stmt->fetchAll();
    
    $questionsList[] = [
        'id' => $q['id'],
        'question_text' => $q['question_text'],
        'time_limit' => $q['time_limit'],
        'points' => $q['points'],
        'order_num' => $q['order_num'],
        'answers' => $answers
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mainkuiz - Edit Quiz</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .quiz-q-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .q-opt-preview {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .q-opt-pill {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        .q-opt-pill.red { background: rgba(255, 51, 85, 0.1); border: 1px dashed var(--color-red); color: var(--color-red); }
        .q-opt-pill.blue { background: rgba(0, 136, 255, 0.1); border: 1px dashed var(--color-blue); color: var(--color-blue); }
        .q-opt-pill.yellow { background: rgba(255, 187, 0, 0.1); border: 1px dashed var(--color-yellow); color: var(--color-yellow); }
        .q-opt-pill.green { background: rgba(16, 211, 103, 0.1); border: 1px dashed var(--color-green); color: var(--color-green); }
        .q-opt-pill.correct {
            font-weight: 800;
            position: relative;
        }
        .q-opt-pill.correct::after {
            content: '✓';
            margin-left: auto;
            background: var(--color-green);
            color: white;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">MAINKUIZ! <span style="font-size: 0.9rem; font-weight: 400; color: var(--text-muted); vertical-align: middle;">QUIZ CREATOR</span></div>
        <div>
            <a href="index.php" class="btn-secondary" style="padding: 0.5rem 1.2rem; font-size: 0.9rem; width: auto; text-decoration: none;">&larr; Back to Dashboard</a>
        </div>
    </header>

    <main class="container">
        <div style="margin-bottom: 2rem;">
            <h1 class="heading-lg" style="text-align: left; margin-bottom: 0.5rem;"><?= htmlspecialchars($quiz['title']) ?></h1>
            <p style="color: var(--text-muted);">Manage quiz questions and set time limits.</p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(255, 51, 85, 0.15); border: 1px solid var(--color-red); color: var(--color-red); padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 2rem;">
            <!-- Questions List -->
            <div>
                <div class="glass-container">
                    <h2 class="heading-md" style="margin-bottom: 1.5rem;">Questions List (<?= count($questionsList) ?>)</h2>
                    
                    <?php if (empty($questionsList)): ?>
                        <p style="color: var(--text-muted); text-align: center; margin: 3rem 0;">No questions added yet. Use the creation form on the right!</p>
                    <?php else: ?>
                        <?php foreach ($questionsList as $q): ?>
                            <div class="quiz-q-card">
                                <div class="flex-between">
                                    <h3 style="font-size: 1.2rem; font-weight: 700;">
                                        <?= $q['order_num'] ?>. <?= htmlspecialchars($q['question_text']) ?>
                                    </h3>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <span class="badge-success" style="background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.15); color: var(--text-color);"><?= $q['time_limit'] ?>s</span>
                                        <span class="badge-success" style="background: rgba(138, 43, 226, 0.15); border-color: rgba(138, 43, 226, 0.3); color: var(--primary-glow);"><?= $q['points'] ?> pts</span>
                                        <a href="quiz.php?id=<?= $quizId ?>&edit_q_id=<?= $q['id'] ?>" style="color: var(--color-blue); text-decoration: none; font-size: 0.9rem; font-weight: 600; margin-left: 1rem;">Edit</a>
                                        <span style="color: var(--text-muted); font-size: 0.9rem;">|</span>
                                        <a href="quiz.php?id=<?= $quizId ?>&action=delete&q_id=<?= $q['id'] ?>" onclick="return confirm('Are you sure you want to delete this question?')" style="color: var(--color-red); text-decoration: none; font-size: 0.9rem; font-weight: 600; margin-left: 0.5rem;">Delete</a>
                                    </div>
                                </div>
                                
                                <div class="q-opt-preview">
                                    <?php 
                                    $colors = ['red', 'blue', 'yellow', 'green'];
                                    foreach ($q['answers'] as $idx => $ans): 
                                        $color = $colors[$idx] ?? 'red';
                                        $correctClass = $ans['is_correct'] ? 'correct' : '';
                                    ?>
                                        <div class="q-opt-pill <?= $color ?> <?= $correctClass ?>">
                                            <span style="margin-right: 0.5rem; font-size: 1.1rem;">
                                                <?php 
                                                    if ($color === 'red') echo '▲';
                                                    if ($color === 'blue') echo '◆';
                                                    if ($color === 'yellow') echo '●';
                                                    if ($color === 'green') echo '■';
                                                ?>
                                            </span>
                                            <?= htmlspecialchars($ans['answer_text']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add / Edit Question Form -->
            <div>
                <div class="glass-container" style="position: sticky; top: 2rem;">
                    <h2 class="heading-md" style="margin-bottom: 1.5rem;"><?= $editQuestion ? 'Edit Question #' . $editQuestion['order_num'] : 'Add New Question' ?></h2>
                    <form method="POST" action="quiz.php?id=<?= $quizId ?>">
                        <?php if ($editQuestion): ?>
                            <input type="hidden" name="edit_question" value="1">
                            <input type="hidden" name="question_id" value="<?= $editQuestion['id'] ?>">
                        <?php else: ?>
                            <input type="hidden" name="add_question" value="1">
                        <?php endif; ?>
                        
                        <label style="display: block; margin-bottom: 0.4rem; font-weight: 600; font-size: 0.9rem;">QUESTION TEXT</label>
                        <input type="text" name="question_text" class="input-field" placeholder="e.g. What is the capital of Malaysia?" required value="<?= $editQuestion ? htmlspecialchars($editQuestion['question_text']) : '' ?>">
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label style="display: block; margin-bottom: 0.4rem; font-weight: 600; font-size: 0.9rem;">TIME LIMIT</label>
                                <select name="time_limit" class="input-field">
                                    <option value="10" <?= ($editQuestion && $editQuestion['time_limit'] == 10) ? 'selected' : '' ?>>10 seconds</option>
                                    <option value="20" <?= (!$editQuestion || $editQuestion['time_limit'] == 20) ? 'selected' : '' ?>>20 seconds</option>
                                    <option value="30" <?= ($editQuestion && $editQuestion['time_limit'] == 30) ? 'selected' : '' ?>>30 seconds</option>
                                    <option value="60" <?= ($editQuestion && $editQuestion['time_limit'] == 60) ? 'selected' : '' ?>>60 seconds</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.4rem; font-weight: 600; font-size: 0.9rem;">MAX POINTS</label>
                                <select name="points" class="input-field">
                                    <option value="1000" <?= (!$editQuestion || $editQuestion['points'] == 1000) ? 'selected' : '' ?>>1000 pts</option>
                                    <option value="2000" <?= ($editQuestion && $editQuestion['points'] == 2000) ? 'selected' : '' ?>>2000 pts</option>
                                    <option value="0" <?= ($editQuestion && $editQuestion['points'] == 0) ? 'selected' : '' ?>>0 pts (no score)</option>
                                </select>
                            </div>
                        </div>

                        <hr style="border: 0; border-top: 1px solid var(--card-border); margin: 1.5rem 0;">
                        <h4 style="margin-bottom: 1rem; font-weight: 600;">Answer Options & Correct Selection</h4>
                        
                        <!-- Option Red -->
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <input type="radio" name="correct_option" value="red" id="correct_red" required style="width: 20px; height: 20px; accent-color: var(--color-red);" <?= ($editQuestion && isset($editAnswers[0]) && $editAnswers[0]['is_correct']) ? 'checked' : '' ?>>
                            <div style="flex-grow: 1;">
                                <input type="text" name="opt_red" class="input-field" placeholder="Option A (▲ Red)" required style="margin-bottom: 0; border-color: rgba(255, 51, 85, 0.3);" value="<?= ($editQuestion && isset($editAnswers[0])) ? htmlspecialchars($editAnswers[0]['answer_text']) : '' ?>">
                            </div>
                        </div>

                        <!-- Option Blue -->
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <input type="radio" name="correct_option" value="blue" id="correct_blue" style="width: 20px; height: 20px; accent-color: var(--color-blue);" <?= ($editQuestion && isset($editAnswers[1]) && $editAnswers[1]['is_correct']) ? 'checked' : '' ?>>
                            <div style="flex-grow: 1;">
                                <input type="text" name="opt_blue" class="input-field" placeholder="Option B (◆ Blue)" required style="margin-bottom: 0; border-color: rgba(0, 136, 255, 0.3);" value="<?= ($editQuestion && isset($editAnswers[1])) ? htmlspecialchars($editAnswers[1]['answer_text']) : '' ?>">
                            </div>
                        </div>

                        <!-- Option Yellow -->
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <input type="radio" name="correct_option" value="yellow" id="correct_yellow" style="width: 20px; height: 20px; accent-color: var(--color-yellow);" <?= ($editQuestion && isset($editAnswers[2]) && $editAnswers[2]['is_correct']) ? 'checked' : '' ?>>
                            <div style="flex-grow: 1;">
                                <input type="text" name="opt_yellow" class="input-field" placeholder="Option C (● Yellow)" required style="margin-bottom: 0; border-color: rgba(255, 187, 0, 0.3);" value="<?= ($editQuestion && isset($editAnswers[2])) ? htmlspecialchars($editAnswers[2]['answer_text']) : '' ?>">
                            </div>
                        </div>

                        <!-- Option Green -->
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem;">
                            <input type="radio" name="correct_option" value="green" id="correct_green" style="width: 20px; height: 20px; accent-color: var(--color-green);" <?= ($editQuestion && isset($editAnswers[3]) && $editAnswers[3]['is_correct']) ? 'checked' : '' ?>>
                            <div style="flex-grow: 1;">
                                <input type="text" name="opt_green" class="input-field" placeholder="Option D (■ Green)" required style="margin-bottom: 0; border-color: rgba(16, 211, 103, 0.3);" value="<?= ($editQuestion && isset($editAnswers[3])) ? htmlspecialchars($editAnswers[3]['answer_text']) : '' ?>">
                            </div>
                        </div>

                        <button type="submit" class="btn-primary"><?= $editQuestion ? 'Save Changes' : 'Add Question' ?></button>
                        
                        <?php if ($editQuestion): ?>
                            <a href="quiz.php?id=<?= $quizId ?>" class="btn-secondary" style="display: block; text-align: center; text-decoration: none; margin-top: 0.75rem;">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
