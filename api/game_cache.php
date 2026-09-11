<?php
// api/game_cache.php
// High-concurrency game state caching layer supporting APCu and atomic file caching.

class GameCache {
    private static ?string $cacheDir = null;
    private static bool $useApcu = false;
    private static bool $initialized = false;

    private static function init(): void {
        if (self::$initialized) {
            return;
        }
        self::$useApcu = extension_loaded('apcu') && ini_get('apc.enabled');
        
        // Setup cache directory in system temp
        $sysTemp = sys_get_temp_dir();
        $dir = $sysTemp . DIRECTORY_SEPARATOR . 'mainkuiz_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        self::$cacheDir = $dir;
        self::$initialized = true;
    }

    /**
     * Get the current state version for a session
     */
    public static function getVersion(int $sessionId): int {
        self::init();
        $key = "mk_v_{$sessionId}";
        if (self::$useApcu) {
            $success = false;
            $val = apcu_fetch($key, $success);
            if ($success && is_int($val)) {
                return $val;
            }
        }
        
        $vFile = self::$cacheDir . DIRECTORY_SEPARATOR . "v_{$sessionId}.txt";
        if (file_exists($vFile)) {
            $content = @file_get_contents($vFile);
            if ($content !== false && is_numeric(trim($content))) {
                return (int)trim($content);
            }
        }
        return 1;
    }

    /**
     * Increment the state version for a session and invalidate cached state
     */
    public static function invalidate(int $sessionId): int {
        self::init();
        $key = "mk_v_{$sessionId}";
        $stateKey = "mk_state_{$sessionId}";
        
        if (self::$useApcu) {
            $newVersion = apcu_inc($key, 1, $success);
            if (!$success) {
                apcu_store($key, 2);
                $newVersion = 2;
            }
            apcu_delete($stateKey);
        }
        
        $vFile = self::$cacheDir . DIRECTORY_SEPARATOR . "v_{$sessionId}.txt";
        $tmpFile = self::$cacheDir . DIRECTORY_SEPARATOR . "v_{$sessionId}_tmp_" . uniqid() . ".txt";
        
        $current = 1;
        if (file_exists($vFile)) {
            $c = @file_get_contents($vFile);
            if ($c !== false && is_numeric(trim($c))) {
                $current = (int)trim($c);
            }
        }
        $newVersion = $current + 1;
        if (@file_put_contents($tmpFile, (string)$newVersion, LOCK_EX) !== false) {
            @rename($tmpFile, $vFile);
        }
        
        // Remove state snapshot file
        $stateFile = self::$cacheDir . DIRECTORY_SEPARATOR . "state_{$sessionId}.json";
        if (file_exists($stateFile)) {
            @unlink($stateFile);
        }
        
        self::clearQuestionExpiry($sessionId);

        return $newVersion;
    }

    /**
     * Set question expiration timestamp (milliseconds)
     */
    public static function setQuestionExpiry(int $sessionId, int $expiresAtMs): void {
        self::init();
        $key = "mk_exp_{$sessionId}";
        if (self::$useApcu) {
            apcu_store($key, $expiresAtMs, 120);
        }

        $expFile = self::$cacheDir . DIRECTORY_SEPARATOR . "exp_{$sessionId}.txt";
        @file_put_contents($expFile, (string)$expiresAtMs, LOCK_EX);
    }

    /**
     * Check if the active question has expired based on cached timestamp
     */
    public static function isQuestionExpired(int $sessionId): bool {
        self::init();
        $key = "mk_exp_{$sessionId}";
        $expiresAtMs = 0;

        if (self::$useApcu) {
            $success = false;
            $val = apcu_fetch($key, $success);
            if ($success && is_numeric($val)) {
                $expiresAtMs = (int)$val;
            }
        }

        if (!$expiresAtMs) {
            $expFile = self::$cacheDir . DIRECTORY_SEPARATOR . "exp_{$sessionId}.txt";
            if (file_exists($expFile)) {
                $c = @file_get_contents($expFile);
                if ($c !== false && is_numeric(trim($c))) {
                    $expiresAtMs = (int)trim($c);
                }
            }
        }

        if ($expiresAtMs > 0) {
            $nowMs = round(microtime(true) * 1000);
            return $nowMs >= $expiresAtMs;
        }

        return false;
    }

    /**
     * Clear question expiration
     */
    public static function clearQuestionExpiry(int $sessionId): void {
        self::init();
        if (self::$useApcu) {
            apcu_delete("mk_exp_{$sessionId}");
        }
        @unlink(self::$cacheDir . DIRECTORY_SEPARATOR . "exp_{$sessionId}.txt");
    }

    /**
     * Get cached session state (common to all players)
     */
    public static function getCachedSessionState(int $sessionId): ?array {
        self::init();
        $stateKey = "mk_state_{$sessionId}";
        if (self::$useApcu) {
            $success = false;
            $data = apcu_fetch($stateKey, $success);
            if ($success && is_array($data)) {
                return $data;
            }
        }
        
        $stateFile = self::$cacheDir . DIRECTORY_SEPARATOR . "state_{$sessionId}.json";
        if (file_exists($stateFile)) {
            $json = @file_get_contents($stateFile);
            if ($json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return null;
    }

    /**
     * Store cached session state
     */
    public static function setCachedSessionState(int $sessionId, array $sessionData, int $version): void {
        self::init();
        $sessionData['_version'] = $version;
        $stateKey = "mk_state_{$sessionId}";
        
        if (self::$useApcu) {
            apcu_store($stateKey, $sessionData, 60); // 60s TTL
        }
        
        $stateFile = self::$cacheDir . DIRECTORY_SEPARATOR . "state_{$sessionId}.json";
        $tmpFile = self::$cacheDir . DIRECTORY_SEPARATOR . "state_{$sessionId}_tmp_" . uniqid() . ".json";
        $json = json_encode($sessionData, JSON_UNESCAPED_UNICODE);
        if ($json && @file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $stateFile);
        }
    }

    /**
     * Clean up all cache files for a session
     */
    public static function purge(int $sessionId): void {
        self::init();
        if (self::$useApcu) {
            apcu_delete("mk_v_{$sessionId}");
            apcu_delete("mk_state_{$sessionId}");
            apcu_delete("mk_exp_{$sessionId}");
        }
        @unlink(self::$cacheDir . DIRECTORY_SEPARATOR . "v_{$sessionId}.txt");
        @unlink(self::$cacheDir . DIRECTORY_SEPARATOR . "state_{$sessionId}.json");
        @unlink(self::$cacheDir . DIRECTORY_SEPARATOR . "exp_{$sessionId}.txt");
    }
}
