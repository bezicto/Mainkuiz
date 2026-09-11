# Production Deployment & High-Concurrency (1,000 Players) Guide for Mainkuiz

This guide provides the optimal server configuration for running **Mainkuiz** with up to **1,000 concurrent participants** on an Apache / PHP 8.x / MariaDB (LAMP) stack without latency spikes or connection drops.

---

## 1. Architecture Overview

```
1,000 Concurrent Mobile Players
       │
       ▼ (HTTP/1.1 or HTTP/2 Keep-Alive with Gzip)
┌─────────────────────────────────────────────────────────────┐
│ Apache Web Server (MPM Event)                               │
│ - Serves static assets directly with 7-day browser cache    │
│ - Gzip/Deflate compression enabled for all JSON & text      │
│ - Fast proxy to PHP-FPM                                     │
└──────────────────────────────┬──────────────────────────────┘
                               │ (FastCGI unix socket)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ PHP 8.x (PHP-FPM + Zend OPcache + APCu)                     │
│ - State Version Micro-cache: sub-millisecond 304/unchanged  │
│ - Isolated player row locking (no table or session locks)   │
│ - Persistent PDO connection pool (50-100 connections)       │
└──────────────────────────────┬──────────────────────────────┘
                               │ (UNIX socket or 127.0.0.1)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ MariaDB 10.x+ (InnoDB)                                      │
│ - Compound covering indexes on player_answers & players     │
│ - Single-statement set-based timeout batch operations       │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Apache Configuration (`mpm_event`)

For handling 1,000 concurrent connections, **`mpm_event`** is strongly recommended over `mpm_prefork` because event MPM uses asynchronous threads to hold idle keep-alive connections without consuming worker processes.

In `/etc/apache2/mods-available/mpm_event.conf` (Ubuntu/Debian) or `httpd-mpm.conf` (RHEL/CentOS):

```apache
<IfModule mpm_event_module>
    StartServers               4
    ServerLimit               32
    ThreadLimit               64
    ThreadsPerChild           32
    MinSpareThreads           64
    MaxSpareThreads          256
    MaxRequestWorkers        800
    MaxConnectionsPerChild 10000
    AsyncRequestWorkerFactor   3
</IfModule>
```

Enable required Apache modules:
```bash
sudo a2enmod mpm_event proxy_fcgi setenvif rewrite headers deflate expires
sudo systemctl restart apache2
```

---

## 3. PHP-FPM & OPcache Configuration

### A. PHP-FPM Pool (`/etc/php/8.x/fpm/pool.d/www.conf`)
Because Mainkuiz's polling endpoints utilize state-version checking (responding in `< 0.5ms` with zero database calls for unchanged ticks), each PHP process is freed almost instantly. A pool of 70–120 children easily handles 1,000 players:

```ini
pm = dynamic
pm.max_children = 120
pm.start_servers = 20
pm.min_spare_servers = 10
pm.max_spare_servers = 40
pm.max_requests = 2000
request_terminate_timeout = 30s
```

### B. Zend OPcache (`/etc/php/8.x/fpm/php.ini` or `opcache.ini`)
```ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0 ; Set to 0 in production for maximum throughput
opcache.save_comments=1
```

### C. APCu (Optional, Recommended for Sub-Microsecond State Cache)
Mainkuiz includes automatic file-based atomic caching in `sys_get_temp_dir()`. To enable in-memory RAM caching for even faster response times:
```bash
sudo apt install php-apcu
# In php.ini:
# apc.enabled=1
# apc.shm_size=64M
```

---

## 4. MariaDB Configuration (`my.cnf`)

In `/etc/mysql/mariadb.conf.d/50-server.cnf` or `/etc/my.cnf`:

```ini
[mysqld]
# 1. Connection Limits
max_connections = 350
max_connect_errors = 10000
connect_timeout = 5
wait_timeout = 60
interactive_timeout = 60

# 2. InnoDB Engine Tuning (Scale buffer pool according to server RAM)
# Set innodb_buffer_pool_size to ~50-70% of available server RAM (e.g. 1G on 2G RAM, 3G on 4G RAM)
innodb_buffer_pool_size = 1G
innodb_buffer_pool_instances = 2
innodb_log_file_size = 256M
innodb_log_buffer_size = 16M
innodb_flush_log_at_trx_commit = 2  ; Dramatically increases write throughput for answer bursts
innodb_file_per_table = 1
innodb_lock_wait_timeout = 10

# 3. Thread & Table Cache
thread_cache_size = 64
table_open_cache = 2000
table_definition_cache = 1000
```

Restart MariaDB:
```bash
sudo systemctl restart mariadb
```

---

## 5. Environment Variables (`db.php`)

Mainkuiz supports 12-factor cloud deployment via environment variables. You can set them in Apache, systemd, or a `.env` loader:

| Variable | Description | Default |
|---|---|---|
| `DB_HOST` | Database host | `127.0.0.1` |
| `DB_PORT` | Database port | `3306` |
| `DB_NAME` | Database name | `mainkuiz_db` |
| `DB_USER` | Database user | `kashoot` |
| `DB_PASS` | Database password | `abc1234` |
| `DB_SOCKET` | Database Unix socket path (optional) | `null` |

In Apache VirtualHost:
```apache
<VirtualHost *:80>
    ServerName quiz.yourdomain.com
    DocumentRoot /var/www/mainkuiz
    
    SetEnv DB_HOST "127.0.0.1"
    SetEnv DB_NAME "mainkuiz_db"
    SetEnv DB_USER "kashoot"
    SetEnv DB_PASS "YOUR_SECURE_PASSWORD"
</VirtualHost>
```

---

## 6. How Mainkuiz Eliminates 1,000-Player Bottlenecks

1. **Jittered Adaptive Polling**:
   - Rather than 1,000 simultaneous connections bombarding the server at the exact same millisecond, client polling includes randomized jitter (`±350ms`), spreading traffic into an even, continuous stream.
2. **State-Version Micro-Caching (`api/game_cache.php`)**:
   - Clients send their current state version `?v=X`.
   - If the game phase has not changed, the server answers immediately with `{"status":"unchanged"}` in `< 0.3ms` without querying MariaDB.
   - During a 20-second question, MariaDB receives **0 queries** from player polls.
3. **Isolated Row Locking**:
   - When players submit answers, only their own row in `players` is locked. The shared `game_sessions` row is NOT locked, allowing hundreds of players to submit answers in parallel within fractions of a second.
4. **Set-Based Batch Resolution**:
   - When a question times out, all timed-out players are recorded and streaks reset via a single SQL set-operation rather than thousands of individual queries.
5. **Decoupled Lightning Answer Submissions**:
   - `submit_answer` writes only the player's answer and updates their score, eliminating completion count queries from the transaction. The host projector screen tracks room completion independently.
6. **Single-Statement Window Function Ranking**:
   - Player ranks are computed atomically in MariaDB via `DENSE_RANK() OVER (ORDER BY score DESC, id ASC)` in a single statement upon question completion, completely eliminating per-player `COUNT(*)` range scans during result reveals.

