-- Schema for Mainkuiz (Kahoot-like Quiz App)
-- Host: localhost | Database: mainkuiz_db

CREATE DATABASE IF NOT EXISTS mainkuiz_db;
USE mainkuiz_db;

-- Drop tables if they exist (clean setup)
DROP TABLE IF EXISTS player_answers;
DROP TABLE IF EXISTS players;
DROP TABLE IF EXISTS game_sessions;
DROP TABLE IF EXISTS answers;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS quizzes;

-- Quizzes Table
CREATE TABLE quizzes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Questions Table
CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    question_text TEXT NOT NULL,
    time_limit INT NOT NULL DEFAULT 20, -- in seconds
    points INT NOT NULL DEFAULT 1000, -- max points for speed scale
    order_num INT NOT NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Answers Table (up to 4 per question)
CREATE TABLE answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    answer_text TEXT NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Game Sessions Table (represents active rooms hosted by admin)
CREATE TABLE game_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    pin VARCHAR(6) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'waiting', -- waiting, countdown, question, answers, leaderboard, podium
    current_question_id INT NULL,
    current_question_started_at BIGINT NULL, -- Unix timestamp in milliseconds for time calculations
    current_question_ended_at BIGINT NULL, -- Unix timestamp in milliseconds when time limit runs out or all answered
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Players Table (participants inside a game session)
CREATE TABLE players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    nickname VARCHAR(50) NOT NULL,
    score INT NOT NULL DEFAULT 0,
    streak INT NOT NULL DEFAULT 0,
    last_question_correct TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_nick_session (session_id, nickname),
    INDEX idx_session_score (session_id, score DESC),
    FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Player Answers Table (tracks submissions and speeds)
CREATE TABLE player_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    question_id INT NOT NULL,
    answer_id INT NULL, -- NULL if time ran out
    points_earned INT NOT NULL DEFAULT 0,
    response_time_ms INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_player_question (player_id, question_id),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
