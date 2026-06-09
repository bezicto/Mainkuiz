# MAINKUIZ! 🚀

Mainkuiz is a fully responsive, visually engaging, real-time multiplayer quiz game (inspired by Kahoot!) built using **PHP 8.5**, **MariaDB**, and **Vanilla CSS & JavaScript**.

---

## 🌟 Key Features

* **⚡ Real-Time Synchronized Gameplay**: Responsive client-to-host synchronization using optimized, stateless polling endpoints.
* **🎵 Web Audio API Sound Synthesizer**: Programmatic 8-bit retro sound chimes, timers, and podium fanfares generated directly by the browser—eliminating bulky audio downloads and buffering lag.
* **📱 Mobile-First Answering Controller**: Big oversized color-coded shapes (Red/▲, Blue/◆, Yellow/●, Green/■) designed as touch targets for phone screens.
* **🏆 Engaging Gameplay Rules**: Kahoot-like point scaling based on response speeds (fast answers earn more) and correct answer streak bonus multipliers.
* **👑 Interactive Projection View**: Includes live countdown transitions, graphical answer breakdowns, floating lobby nicknames, score boards, and a animated 3D podium.
* **📊 Host Control Panel**: Complete quiz configuration dashboard:
  * Create, delete, and rename quizzes.
  * Question Editor: Inline forms to add, edit, or delete questions, adjust time limits (10s to 60s), change score weight, and redefine correct answers.
  * Completed Session History report logs with collapsible full rankings.
  * Auto-prunes incomplete game rooms to maintain database size.
* **🧪 Easy Local Testing**: State-saving via browser `sessionStorage` allows you to open multiple browser tabs concurrently to simulate multiple players on the same machine.

---

## 🛠️ Installation & Setup

### 1. Database Configuration
Mainkuiz uses a MariaDB/MySQL backend database named `mainkuiz_db`.

1. Log into your database server and import the schema definition in `schema.sql`:
   ```bash
   mysql -u YOUR_USERNAME -p mainkuiz_db < schema.sql
   ```
2. Open `db.php` and verify/edit the database credentials to match your local setup:
   ```php
   $host = '127.0.0.1';
   $db   = 'mainkuiz_db';
   $user = 'YOUR_USERNAME';
   $pass = 'YOUR_PASSWORD';
   ```

### 2. Populate Test Data
We've included an automated mock data loader script to get you started immediately with a 5-question trivia quiz:
```bash
php insert_mock_data.php
```

### 3. Start Apache Server
Make sure your Apache/PHP server points to the project root directory.

---

## 🎮 How to Play

1. **Access the Admin Portal**: Navigate to `http://localhost/admin/`.
   * **Default Username**: `admin`
   * **Default Password**: `admin123` (you may change this in admin/index.php by editing the hardcoded username and password)
2. **Host a Game**: Click **Host Game** on the trivia quiz. This generates a 6-digit PIN and plays retro lobby music.
3. **Connect Players**: Open `http://localhost/` in separate windows or tabs. Enter the PIN and choose a nickname.
4. **Run the Quiz**: Control the transitions from the Host screen (Start Quiz &rarr; Skip Timer &rarr; Next Board &rarr; Next Question) and watch the players submit options in sync!

---

## 🔒 Security Note for Production
For simplicity in development, the database connection credentials and admin logins are hardcoded. If you deploy this publicly:
* Extract database configurations to environment variables (`.env`) or a separate config file excluded from git.
* Hash the admin password in the login script using PHP's `password_hash()` and check it with `password_verify()`.

---

## 📄 License
This project is open-source and available under the [MIT License](LICENSE).
