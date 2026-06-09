<?php
// insert_mock_data.php
// Script to pre-populate the database with a sample quiz and questions

header('Content-Type: text/plain');
require_once 'db.php';

try {
    $pdo->beginTransaction();

    // 1. Create a general knowledge quiz
    $stmt = $pdo->prepare("INSERT INTO quizzes (title) VALUES (?)");
    $stmt->execute(['🇲🇾 Kuiz Trivia Malaysia']);
    $quizId = $pdo->lastInsertId();
    echo "Created Quiz ID: $quizId\n";

    // 2. Sample questions data
    $questions = [
        [
            'text' => 'Apakah ibu negara Malaysia?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => 'Kuala Lumpur', 'correct' => 1],
                ['text' => 'Putrajaya', 'correct' => 0],
                ['text' => 'George Town', 'correct' => 0],
                ['text' => 'Johor Bahru', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Apakah warna bunga kebangsaan Malaysia (Bunga Raya)?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => 'Kuning', 'correct' => 0],
                ['text' => 'Biru', 'correct' => 0],
                ['text' => 'Merah', 'correct' => 1],
                ['text' => 'Putih', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Pada tahun berapakah Malaysia (Tanah Melayu) mengisytiharkan kemerdekaan?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => '1957', 'correct' => 1],
                ['text' => '1963', 'correct' => 0],
                ['text' => '1945', 'correct' => 0],
                ['text' => '1969', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Apakah mata wang rasmi Malaysia?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => 'Dolar', 'correct' => 0],
                ['text' => 'Rupiah', 'correct' => 0],
                ['text' => 'Baht', 'correct' => 0],
                ['text' => 'Ringgit', 'correct' => 1]
            ]
        ],
        [
            'text' => 'Berapakah bilangan jalur pada bendera Malaysia (Jalur Gemilang)?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => '12', 'correct' => 0],
                ['text' => '14', 'correct' => 1],
                ['text' => '13', 'correct' => 0],
                ['text' => '15', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Apakah nama gunung tertinggi di Malaysia?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => 'Gunung Ledang', 'correct' => 0],
                ['text' => 'Gunung Tahan', 'correct' => 0],
                ['text' => 'Gunung Kinabalu', 'correct' => 1],
                ['text' => 'Gunung Jerai', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Bulan Ogos terkenal dengan sambutan apa di Malaysia?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => 'Hari Malaysia', 'correct' => 0],
                ['text' => 'Hari Kebangsaan / Merdeka', 'correct' => 1],
                ['text' => 'Hari Pekerja', 'correct' => 0],
                ['text' => 'Hari Raya', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Apakah nama menara berkembar yang terkenal di Kuala Lumpur?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => 'Menara KL', 'correct' => 0],
                ['text' => 'Menara Exchange 106', 'correct' => 0],
                ['text' => 'Menara Berkembar Petronas (KLCC)', 'correct' => 1],
                ['text' => 'Menara Merdeka 118', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Haiwan manakah yang merupakan haiwan kebangsaan/simbol negara Malaysia?',
            'time_limit' => 20,
            'points' => 1000,
            'answers' => [
                ['text' => 'Harimau Malaya', 'correct' => 1],
                ['text' => 'Gajah', 'correct' => 0],
                ['text' => 'Orang Utan', 'correct' => 0],
                ['text' => 'Badak Sumbu', 'correct' => 0]
            ]
        ],
        [
            'text' => 'Negeri manakah yang terbesar di Malaysia mengikut keluasan kawasan?',
            'time_limit' => 20,
            'points' => 2000,
            'answers' => [
                ['text' => 'Sabah', 'correct' => 0],
                ['text' => 'Sarawak', 'correct' => 1],
                ['text' => 'Pahang', 'correct' => 0],
                ['text' => 'Johor', 'correct' => 0]
            ]
        ]
    ];

    // 3. Insert questions and options
    foreach ($questions as $idx => $qData) {
        $stmt = $pdo->prepare("INSERT INTO questions (quiz_id, question_text, time_limit, points, order_num) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$quizId, $qData['text'], $qData['time_limit'], $qData['points'], $idx + 1]);
        $qId = $pdo->lastInsertId();
        echo " - Added question: " . $qData['text'] . " (ID: $qId)\n";

        foreach ($qData['answers'] as $ans) {
            $stmt = $pdo->prepare("INSERT INTO answers (question_id, answer_text, is_correct) VALUES (?, ?, ?)");
            $stmt->execute([$qId, $ans['text'], $ans['correct']]);
        }
    }

    $pdo->commit();
    echo "\nSuccess! Database has been populated with mock quiz questions.\n";
    echo "You can now login to http://localhost/admin/ with:\n";
    echo "Username: admin\nPassword: admin123\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error inserting mock data: " . $e->getMessage() . "\n";
}
