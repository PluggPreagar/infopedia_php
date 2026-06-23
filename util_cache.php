<?php
// util_cache.php — thin cache helpers (existence check, read, write, outdated signal)

// Returns true if $file exists, is younger than $maxAge seconds,
// and the outdated signal ($outdatedFile) has NOT been touched after $file+$delay.
function isCacheValid(string $file, int $maxAge, ?string $outdatedFile, int $delay): bool {
    clearstatcache(true, $file);
    if (!file_exists($file)) {
        return false;
    }
    $fileMtime = filemtime($file);
    if ((time() - $fileMtime) >= $maxAge) {
        return false;
    }
    // Outdated signal: external writer bumps this file to force a refresh.
    // Only counts if the signal is newer than when the cache was written + delay,
    // giving the cache writer a grace window to settle before the signal takes effect.
    if ($outdatedFile !== null && file_exists($outdatedFile)) {
        if (filemtime($outdatedFile) > $fileMtime + $delay) {
            return false;
        }
    }
    return true;
}

// Returns file contents, or '' if the file does not exist.
function readCache(string $file): string {
    if (!file_exists($file)) {
        return '';
    }
    return (string) file_get_contents($file);
}

// Writes $data to $file, creating it if needed.
function writeCache(string $file, string $data): void {
    file_put_contents($file, $data);
}

// Creates $file if missing, updates its mtime if it already exists.
function touchOutdated(string $file): void {
    touch($file);
}

/**
 * Hold the connection until any of $watch_files has been modified since
 * $since_int, or until $timeout seconds have elapsed.
 *
 * Non-existent files are silently ignored. Returns false immediately when no
 * watchable files exist, timeout is 0, or since_int is 0.
 *
 * @param string[] $watch_files Paths to watch.
 * @param int      $since_int   Unix timestamp of the client's last known state.
 * @param int      $timeout     Maximum seconds to hold.
 * @return bool    true = at least one file changed; false = timeout (no change).
 */
function long_poll(array $watch_files, int $since_int, int $timeout): bool {
    if ($timeout <= 0 || $since_int <= 0) {
        return false;
    }
    $watch_files = array_values(array_filter($watch_files, 'file_exists'));
    if (empty($watch_files)) {
        return false;
    }
    $stop_at = time() + $timeout;
    while (time() < $stop_at) {
        clearstatcache();
        foreach ($watch_files as $f) {
            if (filemtime($f) > $since_int) {
                return true;
            }
        }
        sleep(2);
    }
    return false;
}
