<?php
/*
 * util_deploy.php — pure helpers for deploy.php. No I/O.
 */

/**
 * Commit time from the GitHub API JSON (GET /repos/{repo}/commits/{sha}).
 * Used instead of the ZIP entry mtime: ZIP stores times without a timezone,
 * so PHP read them in the server's zone (was 9 h off on fayf.info).
 *
 * @return int Unix timestamp (UTC), 0 when missing/invalid.
 */
function deploy_commit_time(string $json): int {
    $data = json_decode($json, true);
    $date = is_array($data) ? ($data['commit']['committer']['date'] ?? '') : '';
    if (!is_string($date) || $date === '') {
        return 0;
    }
    $ts = strtotime($date);
    return $ts === false ? 0 : $ts;
}

/**
 * Commit time from the ZIP's first local header, extra field 0x5455 ("UT",
 * Unix mtime, UTC). The DOS time fields are useless: GitHub writes them in
 * US Pacific time and PHP/libzip reads them in the server's zone (was 9 h off).
 *
 * @param string $bytes ZIP bytes (only the first local header is read)
 * @return int Unix timestamp (UTC), 0 when missing/invalid.
 */
function deploy_zip_ut(string $bytes): int {
    if (strlen($bytes) < 30 || substr($bytes, 0, 4) !== "PK\x03\x04") {
        return 0;
    }
    $h = unpack('vnameLen/vextraLen', substr($bytes, 26, 4));
    $extra = substr($bytes, 30 + $h['nameLen'], $h['extraLen']);
    for ($o = 0; $o + 4 <= strlen($extra); $o += 4 + $f['size']) {
        $f = unpack('vid/vsize', substr($extra, $o, 4));
        if ($f['id'] === 0x5455 && $f['size'] >= 5 && (ord($extra[$o + 4]) & 1)) {
            return unpack('V', substr($extra, $o + 5, 4))[1];
        }
    }
    return 0;
}
