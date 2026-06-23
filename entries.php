<?php
/*
 * entries.php — GET + POST /entries
 * Handles reading and writing of wiki entries.
 * Plain procedural PHP 8.0+. No classes, no namespaces, no Composer.
 */

$type = 'entry';
require_once 'util.php';
require_once 'util_entry.php';
require_once 'util_format.php';
require_once 'util_cache.php';
require_once 'util_http.php';
require_once 'util_throttle.php';

// ─── Shared config ────────────────────────────────────────────────────────────
$cache_max_age     = (int)($config['cache_time']       ?? 3600);
$cache_delay       = (int)($config['cache_time_delay'] ?? 5);
$outdated_file     = $config['cacheOutdatedFile']       ?? null;
$throttle_max      = (int)($config['throttle_max']     ?? 0);
$throttle_window   = (int)($config['throttle_window']  ?? 60);

// Determine the cache file and source file from tenant_id.
$base_cache = $config['cacheFile'] ?? 'data/entries.cache';
if ($tenant_id !== '') {
    $cache_file  = preg_replace('/\.cache$/', "_{$tenant_id}.cache", $base_cache);
    $source_file = preg_replace('/\.cache$/', "_{$tenant_id}.csv",   $base_cache);
} else {
    $cache_file  = $base_cache;
    $source_file = preg_replace('/\.cache$/', '.csv', $base_cache);
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Throttle check.
    require_throttle('data', $session_id, $throttle_max, $throttle_window);

    // 2. Get and validate entry.
    $entry = $_POST['entry'] ?? $_GET['entry'] ?? null;
    if ($entry === null || $entry === '') {
        respond_error('INVALID_ENTRY', 'Missing entry parameter.', 400);
    }

    // Must have at least two ' | ' separators (path | [middle |] content).
    if (substr_count($entry, ' | ') < 1) {
        respond_error('INVALID_ENTRY', 'Entry must contain at least two pipe-separated columns.', 400);
    }

    // 3. Bug report routing: /_/bug | bug_* → special tenant.
    if (str_starts_with($entry, '/_/bug | bug_')) {
        // Strip the hidden-path prefix and append original tenant id for traceability.
        $entry     = preg_replace('/^\/_(.+?)(\r?\n|$)/', '$1 ' . $tenant_id . ' $2', $entry, 1);
        $tenant_id = 'fayfBug__1754128928';
        // Recalculate paths for the new tenant.
        $source_file = preg_replace('/\.cache$/', "_{$tenant_id}.csv",   $base_cache);
        $cache_file  = preg_replace('/\.cache$/', "_{$tenant_id}.cache", $base_cache);
    }

    // 4. Append type suffix if last character is not a recognised type.
    $last_char = substr(rtrim($entry), -1);
    if (!in_array($last_char, ['.', '!', '?', '>', '-'], true)) {
        $entry .= '.';
    }

    // 5. Timestamp set by server.
    $timestamp = date('Y-m-d H:i:s');

    // 6. Write entry to local CSV. Auto-create with header if needed.
    if (!file_exists($source_file) && ($tenant_id === '' || ($config['tenantAutoCreationEnabled'] ?? false))) {
        if (file_put_contents($source_file, "Timestamp,entry\n") === false) {
            log_error('entries POST failed to create ' . $source_file);
            respond_error('WRITE_ERROR', 'Could not save entry.', 500);
        }
    }
    if (!file_exists($source_file)) {
        log_warn('unknown tenant, skipping write: ' . $source_file);
        respond_error('INVALID_TID', 'Unknown tenant.', 400);
    }
    if (file_put_contents($source_file, $timestamp . ',' . csv_quote($entry) . "\n", FILE_APPEND) === false) {
        log_error('entries POST failed to append to ' . $source_file);
        respond_error('WRITE_ERROR', 'Could not save entry.', 500);
    }
    log_info('entry saved: ' . $source_file);

    // 7. Signal that the cache is outdated.
    if ($outdated_file !== null) {
        touchOutdated($outdated_file);
    }

    // 8. Respond.
    log_return('POST /entries ok');
    respond_json(['status' => 'ok', 'timestamp' => $timestamp], 201);
}

// ─── GET ──────────────────────────────────────────────────────────────────────

// 1. Validate format.
$format = $_GET['format'] ?? 'json';
validate_format($format);

// 2. Set Content-Type early (before any potential long-poll delay).
set_content_type($format);

// 3. Check refresh throttle when ?refresh is set.
if ($refresh) {
    require_throttle('data', $session_id, $throttle_max, $throttle_window);
}

// 4. Serve from cache when valid and not forced-refresh.
//    When $since is set and cache has nothing new, fall through to the long-poll
//    wait below instead of returning 204 immediately.
if (!$refresh && isCacheValid($cache_file, $cache_max_age, $outdated_file, $cache_delay)) {
    $data = readCache($cache_file);
    $out  = _get_respond($data, $format, $since);
    if ($out !== '' || $since === '') {
        log_return(strlen($out) . ' bytes from cache');
        echo $out;
        exit;
    }
    // $since set but nothing new in cache — fall through to long-poll.
}

// 5. Long-poll: hold until entries or votes file changes.
//    Cross-watching votes releases the entries connection when votes update,
//    keeping both client polls in sync.
$poll_timeout   = (int)($config['poll_timeout'] ?? 25);
$votes_source   = $tenant_id !== '' ? 'data/votes_' . $tenant_id . '.csv' : 'data/votes.csv';
if ($since !== '' && $since_int > 0) {
    long_poll([$source_file, $votes_source], $since_int, $poll_timeout);
}

// 6. Fetch from source.
$raw = @file_get_contents($source_file);
if ($raw === false) {
    // File doesn't exist yet — return an empty dataset.
    if (!file_exists($source_file)) {
        log_return('data file not yet created, returning empty dataset');
        $out = _get_respond("Timestamp,entry\n", $format, $since);
        if ($since !== '' && $out === '') {
            http_response_code(204);
            exit;
        }
        echo $out;
        exit;
    }
    // File read failed — fall back to stale cache if present.
    $stale = readCache($cache_file);
    if ($stale !== '') {
        log_warn('source read failed, serving stale cache: ' . $source_file);
        log_return(strlen($stale) . ' bytes from stale cache');
        $out = _get_respond($stale, $format, $since);
        if ($since !== '' && $out === '') {
            http_response_code(204);
            exit;
        }
        echo $out;
        exit;
    }
    log_error('source read failed and no cache: ' . $source_file);
    respond_error('INTERNAL_ERROR', 'Could not read data source.', 500);
}

// 7. Sort, dedup, cache.
$sorted = sortCsvData($raw);
if ($sorted !== '' && $sorted !== "Timestamp,entry\n") {
    writeCache($cache_file, $sorted);
}

// 8. Delta filter for $since and respond.
$out = _get_respond($sorted, $format, $since);

// If delta filtering produced nothing, return 204.
if ($since !== '' && $out === '') {
    log_return('204 no new entries since ' . $since);
    http_response_code(204);
    exit;
}

log_return(strlen($out) . ' bytes');
echo $out;
exit;

// ─── Internal helpers ─────────────────────────────────────────────────────────

/**
 * Filter CSV to rows newer than $since (when set), then convert to $format.
 * Returns the formatted string; an empty string means "nothing to send".
 *
 * @internal
 */
function _get_respond(string $csv, string $format, string $since): string {
    // Delta filter: keep only rows with outer timestamp > $since.
    if ($since !== '') {
        $csv = _filter_since($csv, $since);
        if ($csv === '' || $csv === "Timestamp,entry\n") {
            // Nothing new.
            return '';
        }
    }

    return csv_as_format($csv, $format);
}

/**
 * Return a CSV string containing only rows whose outer timestamp is strictly
 * greater than $since.  The header row is always preserved.
 *
 * Timestamps that are too recent (within 1 second of now) are skipped to
 * avoid racing with concurrent writers — same guard the old read.php used.
 *
 * @internal
 */
function _filter_since(string $csv, string $since): string {
    // One second grace: exclude rows that may still be receiving concurrent writes.
    $ts_max = date('Y-m-d H:i:s', time() - 1);
    // Append 'a' so that string comparison is strictly-greater (avoids exact match).
    $since_a = $since . 'a';

    $lines  = explode("\n", $csv);
    $header = array_shift($lines);

    $kept = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        // Skip header-like guard lines.
        if (str_starts_with($line, 'Timestamp,')) {
            continue;
        }
        if (strcmp($line, $since_a) > 0 && strcmp(substr($line, 0, 19), $ts_max) <= 0) {
            $kept[] = $line;
        }
    }

    if (empty($kept)) {
        return "Timestamp,entry\n";
    }
    return $header . "\n" . implode("\n", $kept);
}
