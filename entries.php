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

// ─── Validate tenant id ───────────────────────────────────────────────────────
// util.php does a die() for invalid tids, but only when the value is non-empty
// AND fails the regex AND is not a special keyword AND is <= 30 chars — the
// condition is inverted (all must be true to die), so re-validate cleanly here.
if ($tenant_id !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,30}$/', $tenant_id)) {
    respond_error('INVALID_TID', 'Tenant ID must be 1–30 alphanumeric/-/_ characters.', 400);
}

// ─── Shared config ────────────────────────────────────────────────────────────
$cache_max_age     = (int)($config['cache_time']       ?? 3600);
$cache_delay       = (int)($config['cache_time_delay'] ?? 5);
$outdated_file     = $config['cacheOutdatedFile']       ?? null;
$google_sheet_url  = $config['googleSheetUrl']          ?? '';
$google_post_url   = $config['googlePostUrl']           ?? '';
$google_post_entry = $config['googlePostEntryId']       ?? 'entry.1234567890';
$throttle_max      = (int)($config['throttle_max']     ?? 0);
$throttle_window   = (int)($config['throttle_window']  ?? 60);

// Determine the cache file and data source from tenant_id.
$base_cache = $config['cacheFile'] ?? 'data/entries.cache';
if ($tenant_id !== '') {
    // Tenant data lives in a local CSV; cache is a parallel .cache file.
    $cache_file  = preg_replace('/\.cache$/', "_{$tenant_id}.cache", $base_cache);
    $source_file = preg_replace('/\.cache$/', "_{$tenant_id}.csv",   $base_cache);
    $is_tenant   = true;
    // Tenant caches expire quickly so long-poll works.
    $cache_delay = 5;
} else {
    $cache_file  = $base_cache;
    $source_file = $google_sheet_url;
    $is_tenant   = false;
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Throttle check.
    $throttle_key = $session_id;
    if (!checkThrottle('data/', $throttle_key, $throttle_max, $throttle_window)) {
        $retry = throttleRetryAfter('data/', $throttle_key, $throttle_window);
        header('Retry-After: ' . $retry);
        respond_error('THROTTLED', "Too many requests. Retry after {$retry} seconds.", 429);
    }

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
        $is_tenant   = true;
    }

    // 4. Append type suffix if last character is not a recognised type.
    $last_char = substr(rtrim($entry), -1);
    if (!in_array($last_char, ['.', '!', '?', '>', '-'], true)) {
        $entry .= '.';
    }

    // 5. Timestamp set by server.
    $timestamp = date('Y-m-d H:i:s');

    // 6. Write entry.
    if (!$is_tenant) {
        // Post to Google Forms.
        $post_data = http_build_query([$google_post_entry => $entry]);
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post_data,
            ],
        ]);
        $response = @file_get_contents($google_post_url, false, $ctx);
        if ($response === false) {
            $err = error_get_last();
            log_error('POST to Google Forms failed: ' . ($err['message'] ?? 'unknown'));
            respond_error('UPSTREAM_UNAVAILABLE', 'Could not reach upstream form. Try again later.', 503);
        }
        log_info('entry posted to Google Forms: ' . $google_post_url);
    } else {
        // Write to local tenant CSV, auto-create with header if needed.
        if (!file_exists($source_file) && ($config['tenantAutoCreationEnabled'] ?? false)) {
            log_warn('creating tenant file: ' . $source_file);
            @file_put_contents($source_file, "Timestamp,entry\n");
        }
        if (!file_exists($source_file)) {
            log_warn('unknown tenant, skipping write: ' . $source_file);
            respond_error('INVALID_TID', 'Unknown tenant.', 400);
        }
        // CSV-escape: wrap in quotes if entry contains comma, quote, or newline.
        if (strpbrk($entry, ',"' . "\n\r") !== false) {
            $entry_escaped = str_replace('"', '""', $entry);
            $csv_entry = '"' . $entry_escaped . '"';
        } else {
            $csv_entry = $entry;
        }
        @file_put_contents($source_file, $timestamp . ',' . $csv_entry . "\n", FILE_APPEND);
        log_info('entry saved to tenant: ' . $source_file);
    }

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
$valid_formats = ['json', 'csv', 'txt.0.2', 'txt.0.3'];
if (!in_array($format, $valid_formats, true)) {
    respond_error('INVALID_FORMAT', 'format must be one of: ' . implode(', ', $valid_formats) . '.', 400);
}

// 2. Set Content-Type early (before any potential long-poll delay).
set_content_type($format);

// 3. Check refresh throttle when ?refresh is set.
if ($refresh) {
    if (!checkThrottle('data/', $session_id, $throttle_max, $throttle_window)) {
        $retry = throttleRetryAfter('data/', $session_id, $throttle_window);
        header('Retry-After: ' . $retry);
        respond_error('THROTTLED', "Too many requests. Retry after {$retry} seconds.", 429);
    }
}

// 4. Serve from cache when valid and not forced-refresh.
if (!$refresh && isCacheValid($cache_file, $cache_max_age, $outdated_file, $cache_delay)) {
    $data = readCache($cache_file);
    log_return(strlen($data) . ' bytes from cache');
    echo _get_respond($data, $format, $since);
    exit;
}

// 5. Long-poll for tenant (local file): wait up to 50 s for new data after $since.
if ($since !== '' && $is_tenant && file_exists($source_file)) {
    $stop_at = time() + 50;
    while (time() < $stop_at) {
        clearstatcache(true, $source_file);
        if (filemtime($source_file) > $since_int) {
            break; // File changed — proceed to read.
        }
        sleep(2);
    }
}

// 6. Fetch from source.
$raw = @file_get_contents($source_file);
if ($raw === false) {
    // For tenant mode: if the file simply doesn't exist yet, return an empty dataset.
    if ($is_tenant && !file_exists($source_file)) {
        log_return('tenant file not yet created, returning empty dataset');
        echo _get_respond("Timestamp,entry\n", $format, $since);
        exit;
    }
    // Upstream unavailable — fall back to stale cache if present.
    $stale = readCache($cache_file);
    if ($stale !== '') {
        log_warn('upstream unavailable, serving stale cache: ' . $source_file);
        log_return(strlen($stale) . ' bytes from stale cache');
        echo _get_respond($stale, $format, $since);
        exit;
    }
    log_error('upstream unavailable and no cache: ' . $source_file);
    respond_error('UPSTREAM_UNAVAILABLE', 'Upstream data source is unavailable.', 503);
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

    return match ($format) {
        'json'    => json_encode(
                         csv_to_json($csv),
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                     ),
        'csv'     => $csv,
        'txt.0.2' => csv_to_txt02($csv),
        'txt.0.3' => csv_to_txt03($csv),
        default   => $csv,
    };
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
        if (strcmp($line, $since_a) > 0 && strcmp($line, $ts_max) <= 0) {
            $kept[] = $line;
        }
    }

    if (empty($kept)) {
        return "Timestamp,entry\n";
    }
    return $header . "\n" . implode("\n", $kept);
}
