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
