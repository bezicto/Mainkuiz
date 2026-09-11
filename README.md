# MAINKUIZ! 🚀

Mainkuiz is a fully responsive, visually engaging, real-time multiplayer quiz game (inspired by Kahoot!) built using **PHP 8.5**, **MariaDB**, and **Vanilla CSS & JavaScript**.

---

## 🌟 Key Features

* **⚡ Real-Time Synchronized Gameplay**: Responsive client-to-host synchronization using optimized, stateless polling endpoints.
* **🎵 Web Audio API Sound Synthesizer**: Programmatic 8-bit retro sound chimes, timers, and podium fanfares generated directly by the browser—eliminating bulky audio downloads and buffering lag.
* **📱 Mobile-First Answering Controller**: Big oversized color-coded shapes (Red/▲, Blue/◆, Yellow/●, Green/■) designed as touch targets for phone screens.
* **🏆 Engaging Gameplay Rules**: Kahoot-like point scaling based on response speeds (fast answers earn more) and correct answer streak bonus multipliers.
* **👑 Interactive Projection View**: Includes live countdown transitions, graphical answer breakdowns, floating lobby nicknames, score boards, and an animated 3D podium.
* **👥 Configurable Participant Limit**: Built-in room capacity enforcement defined directly in `db.php` (default: 50 players) to protect server resources, prevent room overloading, and provide immediate rejection alerts when a lobby is full.
* **📊 Host Control Panel**: Complete quiz configuration dashboard:
  * Create, delete, and rename quizzes.
  * Question Editor: Inline forms to add, edit, or delete questions, adjust time limits (10s to 60s), change score weight, and redefine correct answers.
  * Completed Session History report logs with collapsible full rankings.
  * Auto-prunes incomplete game rooms to maintain database size.
* **🧪 Easy Local Testing & Automated Test Suites**: State-saving via browser `sessionStorage` allows multiple browser tabs concurrently, accompanied by automated CLI test scripts.

---

## 🛠️ Installation & Setup

### 1. Database Configuration
Mainkuiz uses a MariaDB/MySQL backend database named `mainkuiz_db`.

1. Log into your database server and import the schema definition in `schema.sql`:
   ```bash
   mysql -u YOUR_USERNAME -p mainkuiz_db < schema.sql
   ```

### 2. Centralized Configuration (`db.php`)
All core application settings—database connection, admin credentials, and participant capacity—are centralized in `db.php`:

```php
// Database credentials (with environment variable fallback)
$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'mainkuiz_db';
$user = getenv('DB_USER') ?: 'kashoot';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'abc1234';

// Host / Admin Portal Access Password
$admin_pass = 'abc123';

// Participant limit per game session
$max_participants = (int)(getenv('MAX_PARTICIPANTS') ?: 50);
if (!defined('MAX_PARTICIPANTS')) {
    define('MAX_PARTICIPANTS', $max_participants);
}
```

#### 🔑 Admin Password
The admin portal password is now defined in `db.php` via `$admin_pass` (instead of being hardcoded in `admin/index.php`):
* **Default Password**: `abc123`
* **Default Username**: `admin`
* **Recommendation**: Change `$admin_pass` to a secure passphrase before hosting live games or deploying publicly.

#### 👥 Participant Limit System
To maintain high responsiveness and prevent server resource exhaustion, Mainkuiz includes an automated participant limit:
* **Why it's needed**: High-frequency polling and simultaneous submissions from hundreds of active connections can overwhelm web servers or database connection pools. Capping session sizes ensures smooth countdown timers and instant scoring calculations for all connected players.
* **Default Limit**: `50` concurrent players per game session room.
* **How to Adjust**: Change `$max_participants` in `db.php`, or set the `MAX_PARTICIPANTS` environment variable (e.g. `export MAX_PARTICIPANTS=100`).
* **Lobby Behavior**:
  * If a player enters a PIN for a room that is already full, the system warns them immediately: *"This game lobby is full (maximum 50 players allowed)."*
  * If a player attempts to submit a nickname into a full room, the API rejects the registration with: *"Game lobby is full! Maximum limit of 50 participants reached."*
  * The session state API exposes `max_players` and `total_players` in real time.
* **Testing**: Run the automated test suite to verify limit enforcement and boundary handling:
  ```bash
  php tests/test_participant_limit.php
  ```

### 3. Populate Test Data
We've included an automated mock data loader script to get you started immediately with a 5-question trivia quiz:
```bash
php insert_mock_data.php
```

### 4. Start Server
Ensure your Apache or PHP web server points to the project root directory. For local development with PHP's built-in server:
```bash
php -S 0.0.0.0:8000
```

---

## 🎮 How to Play

1. **Access the Admin Portal**: Navigate to `http://localhost/admin/` (or `http://localhost:8000/admin/`).
   * **Username**: `admin`
   * **Password**: `abc123` (configured in `db.php` via `$admin_pass`)
2. **Host a Game**: Click **Host Game** on any quiz. This creates an active session, generates a 6-digit PIN, and plays retro lobby music.
3. **Connect Players**: Players open `http://localhost/` on their phone or browser, enter the PIN, and pick a nickname (up to the configured participant limit).
4. **Run the Quiz**: Control the transitions from the Host screen (Start Quiz &rarr; Skip Timer &rarr; Next Board &rarr; Next Question) and watch the players submit answers in sync!

---

## 🔒 Security Note for Production
For simplicity in local development, credentials are set in `db.php`. For production deployments:
* Keep `db.php` protected or configure sensitive credentials via environment variables (`DB_PASS`, `ADMIN_PASS`, etc.).
* Update the default `$admin_pass = 'abc123';` in `db.php` to a strong passphrase.
* Use HTTPS and, for multi-user enterprise setups, hash passwords using PHP's `password_hash()` and `password_verify()`.

---

## 📄 License
This project is open-source and available under the [MIT License](LICENSE).
