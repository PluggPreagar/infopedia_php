<?php
$type = 'vote';
require_once 'util.php';
require_once 'util_entry.php';
require_once 'util_format.php';
require_once 'util_cache.php';
require_once 'util_http.php';
require_once 'util_throttle.php';

// ── Config ────────────────────────────────────────────────────────────────────

$cacheFile         = $config['cacheFile']         ?? 'data/votes.cache';
$cacheOutdatedFile = $config['cacheOutdatedFile'] ?? null;
$cacheMaxAge       = (int)($config['cache_time']       ?? 3600);
$cacheDelay        = (int)($config['cache_time_delay'] ?? 5);

$throttle_max    = (int)($config['throttle_max']    ?? 0);
$throttle_window = (int)($config['throttle_window'] ?? 60);
$throttle_key    = ($config['throttle_key'] ?? 'sid') === 'ip'
    ? ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
    : $session_id;

// Local CSV for this tenant (or global default).
if ($tenant_id !== '') {
    $cacheFile = 'data/votes_' . $tenant_id . '.cache';
    $localCsv  = 'data/votes_' . $tenant_id . '.csv';
} else {
    $localCsv = preg_replace('/\.cache$/', '.csv', $cacheFile);
}

// ── GET ───────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $format = $_GET['format'] ?? 'json';
    validate_format($format);

    // refresh flag bypasses the disk cache (throttled).
    if ($refresh) {
        require_throttle('data', $throttle_key, $throttle_max, $throttle_window);
    }

    // Load from local CSV.
    $csv = @file_get_contents($localCsv) ?: "Timestamp,entry\n";
    $csv = sortCsvData($csv);

    // Aggregate votes — the key difference from entries.php.
    $csv = aggregateVotes($csv, $session_id);

    // Long-poll: if ?since= given and no new data yet, wait for any change.
    //    Cross-watching entries releases the votes connection when entries update,
    //    keeping both client polls in sync.
    $poll_timeout = (int)($config['poll_timeout'] ?? 25);
    $now          = time();
    if ($since !== '' && $since_int > 0 && !_votes_has_since($csv, $since)) {
        if (long_poll($tenant_id, $now, $poll_timeout)) {
            $csv = sortCsvData(@file_get_contents($localCsv) ?: "Timestamp,entry\n");
            $csv = aggregateVotes($csv, $session_id);
        }
    }
    if ($since !== '' && !_votes_has_since($csv, $since)) {
        http_response_code(204);
        log_return('votes GET 204 no new data since ' . $since);
        exit;
    }

    log_return('votes GET ' . $format . ' ' . strlen($csv) . ' bytes');
    respond_csv_as_format($csv, $format);
}

// ── POST ──────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Throttle all writes.
    require_throttle('data', $throttle_key, $throttle_max, $throttle_window);

    $raw_entry = $_POST['entry'] ?? '';
    if ($raw_entry === '') {
        respond_error('INVALID_ENTRY', 'entry body must not be empty', 400);
    }

    $max_entry_length = (int)($config['max_entry_length'] ?? 65536);
    if (strlen($raw_entry) > $max_entry_length) {
        respond_error('INVALID_ENTRY', 'entry body too large (max ' . $max_entry_length . ' bytes)', 400);
    }

    // Must have a path and at least one pipe-delimited column.
    $columns = explode(' | ', $raw_entry);
    if (count($columns) < 2) {
        respond_error('INVALID_ENTRY', 'entry must contain path and content separated by " | "', 400);
    }

    // Validate: must contain at least one votes: or signed: attribute.
    // SID in vote attributes is whitelisted to alphanumeric/underscore/hyphen.
    $hasVote = false;
    foreach ($columns as $col) {
        if (preg_match('/^votes:[a-zA-Z0-9_-]+:-?\d+$/', $col) || preg_match('/^signed:[a-zA-Z0-9_-]+:\d+$/', $col)) {
            $hasVote = true;
            break;
        }
    }
    if (!$hasVote) {
        respond_error('INVALID_ENTRY', 'vote entry must contain a votes:<sid>:<n> or signed:<sid>:<n> attribute', 400);
    }

    // Append type suffix if missing.
    $last = end($columns);
    if (!preg_match('/[.!?>-]$/', $last)) {
        $columns[count($columns) - 1] = $last . '.';
    }
    $entry = implode(' | ', $columns);

    $timestamp = date('Y-m-d H:i:s');

    // Write to local CSV file.
    if (!is_dir('data')) {
        mkdir('data', 0775, true);
    }

    $line = $timestamp . ',' . csv_quote($entry) . "\n";

    if (!file_exists($localCsv)) {
        if (file_put_contents($localCsv, "Timestamp,entry\n") === false) {
            log_error('votes POST failed to create ' . $localCsv);
            respond_error('WRITE_ERROR', 'Could not save vote.', 500);
        }
    }
    if (file_put_contents($localCsv, $line, FILE_APPEND) === false) {
        log_error('votes POST failed to append to ' . $localCsv);
        respond_error('WRITE_ERROR', 'Could not save vote.', 500);
    }

    if ($cacheOutdatedFile !== null) {
        touchOutdated($cacheOutdatedFile);
    }

    log_return('votes POST saved ' . strlen($line) . ' bytes to ' . $localCsv);

    respond_json(['status' => 'ok', 'timestamp' => $timestamp], 201);
}

// ── Fallback ──────────────────────────────────────────────────────────────────

respond_error('METHOD_NOT_ALLOWED', 'Only GET and POST accepted.', 405);

// ── Internal helpers ──────────────────────────────────────────────────────────

/** Return true if $csv contains any row with outer timestamp > $since. */
function _votes_has_since(string $csv, string $since): bool {
    $lines = explode("\n", $csv);
    array_shift($lines);
    foreach ($lines as $line) {
        if ($line !== '' && substr($line, 0, 19) > $since) {
            return true;
        }
    }
    return false;
}
