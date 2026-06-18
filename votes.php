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

// Validate tid early — applies to both GET and POST.
if (!empty($tenant_id) && !preg_match('/^[a-zA-Z0-9_-]{1,30}$/', $tenant_id)) {
    respond_error('INVALID_TID', 'Tenant ID must be alphanumeric (max 30 chars).', 400);
}

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
    $validFormats = ['json', 'csv', 'txt.0.2', 'txt.0.3'];
    if (!in_array($format, $validFormats, true)) {
        respond_error('INVALID_FORMAT', 'format must be one of: ' . implode(', ', $validFormats), 400);
    }

    // refresh flag bypasses the disk cache (throttled).
    if ($refresh) {
        if (!checkThrottle('data', $throttle_key, $throttle_max, $throttle_window)) {
            $retry = throttleRetryAfter('data', $throttle_key, $throttle_window);
            header("Retry-After: $retry");
            respond_error('THROTTLED', "Too many requests. Retry after $retry seconds.", 429);
        }
    }

    // Load from local CSV.
    $csv = @file_get_contents($localCsv) ?: "Timestamp,entry\n";
    $csv = sortCsvData($csv);

    // Aggregate votes — the key difference from entries.php.
    $csv = aggregateVotes($csv, $session_id);

    // Long-poll: if ?since= given and no new rows exist, wait up to 50 s.
    if (!empty($since) && file_exists($localCsv)) {
        $deadline = time() + 50;
        while (time() < $deadline) {
            $newCsv = sortCsvData(readCache($localCsv));
            $newCsv = aggregateVotes($newCsv, $session_id);
            // Any line with a timestamp strictly after $since qualifies.
            $lines = explode("\n", $newCsv);
            array_shift($lines); // skip header
            $found = false;
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $ts = substr($line, 0, 19);
                if ($ts > $since) { $found = true; break; }
            }
            if ($found) {
                $csv = $newCsv;
                break;
            }
            sleep(2);
            clearstatcache();
        }
        // If still no new data, return 204.
        $lines = explode("\n", $csv);
        array_shift($lines);
        $hasData = false;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $ts = substr($line, 0, 19);
            if ($ts > $since) { $hasData = true; break; }
        }
        if (!$hasData) {
            http_response_code(204);
            log_return('votes GET 204 no new data since ' . $since);
            exit;
        }
    }

    set_content_type($format);
    log_return('votes GET ' . $format . ' ' . strlen($csv) . ' bytes');

    switch ($format) {
        case 'json':
            respond_json(csv_to_json($csv));
        case 'csv':
            echo $csv;
            exit;
        case 'txt.0.2':
            echo csv_to_txt02($csv);
            exit;
        case 'txt.0.3':
            echo csv_to_txt03($csv);
            exit;
    }
}

// ── POST ──────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Throttle all writes.
    if (!checkThrottle('data', $throttle_key, $throttle_max, $throttle_window)) {
        $retry = throttleRetryAfter('data', $throttle_key, $throttle_window);
        header("Retry-After: $retry");
        respond_error('THROTTLED', "Too many requests. Retry after $retry seconds.", 429);
    }

    $raw_entry = $_POST['entry'] ?? '';
    if ($raw_entry === '') {
        respond_error('INVALID_ENTRY', 'entry body must not be empty', 400);
    }

    // Must have a path and at least one pipe-delimited column.
    $columns = explode(' | ', $raw_entry);
    if (count($columns) < 2) {
        respond_error('INVALID_ENTRY', 'entry must contain path and content separated by " | "', 400);
    }

    // Validate: must contain at least one votes: or signed: attribute.
    $hasVote = false;
    foreach ($columns as $col) {
        if (preg_match('/^votes:[^:]+:-?\d+$/', $col) || preg_match('/^signed:[^:]+:\d+$/', $col)) {
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

    // CSV-quote the entry if it contains commas, quotes, or newlines.
    $entry_csv = $entry;
    if (strpos($entry_csv, ',') !== false
        || strpos($entry_csv, '"') !== false
        || strpos($entry_csv, "\n") !== false) {
        $entry_csv = '"' . str_replace('"', '""', $entry_csv) . '"';
    }
    $line = $timestamp . ',' . $entry_csv . "\n";

    if (!file_exists($localCsv)) {
        file_put_contents($localCsv, "Timestamp,entry\n");
    }
    file_put_contents($localCsv, $line, FILE_APPEND);

    if ($cacheOutdatedFile !== null) {
        touchOutdated($cacheOutdatedFile);
    }

    log_return('votes POST saved ' . strlen($line) . ' bytes to ' . $localCsv);

    respond_json(['status' => 'ok', 'timestamp' => $timestamp], 201);
}

// ── Fallback ──────────────────────────────────────────────────────────────────

respond_error('METHOD_NOT_ALLOWED', 'Only GET and POST accepted.', 405);
