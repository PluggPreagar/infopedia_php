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
 * Hold the connection until the entries or votes CSV for tenant $tid is
 * modified after $now, or until $timeout seconds have elapsed.
 *
 * File paths are derived internally: data/entries[_tid].csv and
 * data/votes[_tid].csv.  Non-existent files are silently ignored.
 * Returns false immediately when no watchable files exist or timeout is 0.
 *
 * @param string $tid     Tenant ID; empty string → default (no suffix).
 * @param int    $now     Unix timestamp captured before entering the poll.
 * @param int    $timeout Maximum seconds to hold.
 * @return bool  true = at least one file changed; false = timeout (no change).
 */
function long_poll(string $tid, int $now, int $timeout = 25): bool {
    if ($timeout <= 0) {
        return false;
    }
    $suffix = $tid !== '' ? '_' . $tid : '';
    $files  = array_values(array_filter([
        'data/entries' . $suffix . '.csv',
        'data/votes'   . $suffix . '.csv',
    ], 'file_exists'));
    if (empty($files)) {
        return false;
    }
    $stop_at = $now + $timeout;
    while (time() < $stop_at) {
        clearstatcache();
        foreach ($files as $f) {
            if (filemtime($f) > $now) {
                return true;
            }
        }
        sleep(2);
    }
    return false;
}
