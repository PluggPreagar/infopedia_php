<?php
/*
 * util_throttle.php
 * Rate-limiting helpers using a per-key state file on disk.
 *
 * State file: $dir/throttle_<key>.dat  — one line: "<window_start_unix>:<count>"
 */

// Sanitise $key to safe filename characters (prevent path traversal).
function _throttle_safe_key(string $key): string {
    return preg_replace('/[^a-zA-Z0-9]/', '', $key);
}

function _throttle_file(string $dir, string $key): string {
    return $dir . '/throttle_' . _throttle_safe_key($key) . '.dat';
}

// Read state file; returns [window_start, count] or [$now, 0] on missing/corrupt.
function _throttle_read(string $file, int $now): array {
    if (!file_exists($file)) {
        return [$now, 0];
    }
    $raw = trim(file_get_contents($file));
    if (!preg_match('/^(\d+):(\d+)$/', $raw, $m)) {
        return [$now, 0];
    }
    return [(int)$m[1], (int)$m[2]];
}

function _throttle_write(string $file, int $window_start, int $count): void {
    file_put_contents($file, "$window_start:$count");
}

/**
 * Check whether the request identified by $key is within the allowed rate.
 *
 * @param string $dir    Directory that holds state files (trailing slash optional).
 * @param string $key    Logical identifier (e.g. session id, IP).
 * @param int    $max    Maximum allowed requests per window; 0 = disabled (always allow).
 * @param int    $window Window length in seconds.
 * @param int    $now    Current Unix timestamp; 0 = use time().
 * @return bool  true = allowed, false = throttled.
 */
function checkThrottle(string $dir, string $key, int $max, int $window, int $now = 0): bool {
    if ($now === 0) {
        $now = time();
    }

    $file = _throttle_file($dir, $key);
    [$window_start, $count] = _throttle_read($file, $now);

    // Window expired: reset.
    if (($now - $window_start) >= $window) {
        $window_start = $now;
        $count = 0;
    }

    $count++;
    _throttle_write($file, $window_start, $count);

    if ($max === 0) {
        return true;
    }

    return $count <= $max;
}

/**
 * Seconds remaining in the current throttle window for $key.
 * Returns 0 if no state file exists or the window has already expired.
 *
 * @param string $dir    Directory that holds state files.
 * @param string $key    Logical identifier.
 * @param int    $window Window length in seconds.
 * @param int    $now    Current Unix timestamp; 0 = use time().
 * @return int   Seconds until the current window resets (>= 0).
 */
function throttleRetryAfter(string $dir, string $key, int $window, int $now = 0): int {
    if ($now === 0) {
        $now = time();
    }

    $file = _throttle_file($dir, $key);
    if (!file_exists($file)) {
        return 0;
    }

    $raw = trim(file_get_contents($file));
    if (!preg_match('/^(\d+):(\d+)$/', $raw, $m)) {
        return 0;
    }

    $window_start = (int)$m[1];
    return max(0, $window_start + $window - $now);
}
