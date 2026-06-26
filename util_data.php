<?php
// util_data.php — data channel transform + cache helpers (no HTTP logic)

// ─── Log parsing ──────────────────────────────────────────────────────────────

function parse_log_line(string $raw): ?array {
    $p = explode(' ; ', $raw);
    if (count($p) < 6) return null;

    $timestamp = trim(trim($p[0]), '[]');
    $type      = trim($p[1]);
    $uri       = trim($p[2]);
    $method    = trim($p[3]);
    $st        = trim($p[4]);
    $details   = trim(implode(' ; ', array_slice($p, 6)));

    $at      = strrpos($st, '@');
    $session = $at !== false ? substr($st, 0, $at)      : $st;
    $tenant  = $at !== false ? substr($st, $at + 1)     : '';

    $level = 'INFO';
    if     (str_starts_with($details, 'ERROR:'))   $level = 'ERROR';
    elseif (str_starts_with($details, 'WARNING:')) $level = 'WARNING';
    elseif (str_starts_with($details, 'RETURN:'))  $level = 'RETURN';

    $ms = null;
    if ($level === 'RETURN' && preg_match('/in ([\d.]+) seconds/', $details, $m)) {
        $ms = round((float)$m[1] * 1000, 2);
    }

    return compact('timestamp', 'type', 'uri', 'method', 'session', 'tenant', 'details', 'level', 'ms');
}

// ─── Aggregate helpers ────────────────────────────────────────────────────────

function empty_stats_agg(): array {
    return [
        'requests'    => 0,
        'errors'      => 0,
        'warnings'    => 0,
        'sessions'    => [],
        'tenants'     => [],
        'times_sum'   => 0.0,
        'times_count' => 0,
        'max_ms'      => 0.0,
        'first_ts'    => null,
        'last_ts'     => null,
        'by_type'     => [],
        'by_hour'     => array_fill(0, 24, 0),
        'rt_buckets'  => ['<1ms'=>0,'1-10ms'=>0,'10-100ms'=>0,'100ms-1s'=>0,'>1s'=>0],
        'tl_min_ts'   => null,
        'tl_bucket'   => null,
        'tl_label'    => null,
        'timeline'    => [],
    ];
}

// Merge parsed log lines into an existing aggregate.
// $agg must have tl_min_ts + tl_bucket set before calling (done by data_stats_respond).
// Returns the updated aggregate.
function merge_stats_chunk(array $agg, array $new_lines): array {
    foreach ($new_lines as $r) {
        if ($r === null) continue;

        if ($agg['first_ts'] === null || $r['timestamp'] < $agg['first_ts'])
            $agg['first_ts'] = $r['timestamp'];
        if ($agg['last_ts'] === null || $r['timestamp'] > $agg['last_ts'])
            $agg['last_ts'] = $r['timestamp'];

        if ($r['level'] === 'ERROR') {
            $agg['errors']++;
            $t = $r['type'];
            $agg['by_type'][$t] ??= _empty_type_bucket();
            $agg['by_type'][$t]['errors']++;

        } elseif ($r['level'] === 'WARNING') {
            $agg['warnings']++;

        } elseif ($r['level'] === 'RETURN') {
            $agg['requests']++;
            $agg['sessions'][] = $r['session'];
            if ($r['tenant'] !== '') $agg['tenants'][] = $r['tenant'];

            $t = $r['type'];
            $agg['by_type'][$t] ??= _empty_type_bucket();
            $r['method'] === 'GET'
                ? $agg['by_type'][$t]['get']++
                : $agg['by_type'][$t]['post']++;

            if ($r['ms'] !== null) {
                $ms = $r['ms'];
                $agg['times_sum']               += $ms;
                $agg['times_count']++;
                $agg['max_ms']                   = max($agg['max_ms'], $ms);
                $agg['by_type'][$t]['times_sum'] += $ms;
                $agg['by_type'][$t]['times_count']++;
                $agg['by_type'][$t]['max_ms']    = max($agg['by_type'][$t]['max_ms'], $ms);

                if      ($ms < 1)    $agg['rt_buckets']['<1ms']++;
                elseif  ($ms < 10)   $agg['rt_buckets']['1-10ms']++;
                elseif  ($ms < 100)  $agg['rt_buckets']['10-100ms']++;
                elseif  ($ms < 1000) $agg['rt_buckets']['100ms-1s']++;
                else                 $agg['rt_buckets']['>1s']++;
            }

            if (preg_match('/ (\d{2}):\d{2}:\d{2}/', $r['timestamp'], $m))
                $agg['by_hour'][(int)$m[1]]++;

            if ($agg['tl_min_ts'] !== null && $agg['tl_bucket'] > 0) {
                $ts = strtotime($r['timestamp']);
                if ($ts !== false) {
                    $idx = (int)floor(($ts - $agg['tl_min_ts']) / $agg['tl_bucket']);
                    if ($idx >= 0)
                        $agg['timeline'][$idx] = ($agg['timeline'][$idx] ?? 0) + 1;
                }
            }
        }
    }
    return $agg;
}

function _empty_type_bucket(): array {
    return ['get'=>0,'post'=>0,'errors'=>0,'times_sum'=>0.0,'times_count'=>0,'max_ms'=>0.0];
}

// ─── Cache validity ───────────────────────────────────────────────────────────

function stats_cache_valid(array $cache, string $logFile): bool {
    if (($cache['log_file'] ?? '') !== $logFile) return false;
    if (!isset($cache['offset']))                return false;
    clearstatcache(true, $logFile);
    return file_exists($logFile) && $cache['offset'] <= filesize($logFile);
}

// ─── Cache I/O ────────────────────────────────────────────────────────────────

function load_stats_cache(string $cacheFile, string $logFile): ?array {
    if (!file_exists($cacheFile)) return null;
    $fp = fopen($cacheFile, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['agg'])) return null;
    return stats_cache_valid($data, $logFile) ? $data : null;
}

function save_stats_cache(string $cacheFile, string $logFile,
                          int $offset, array $agg): void {
    $fp = fopen($cacheFile, 'c');
    if (!$fp) return;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    // Re-read: another instance may have written a newer cache while we waited.
    $raw      = file_get_contents($cacheFile);
    $existing = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (is_array($existing)
        && ($existing['log_file'] ?? '') === $logFile
        && ($existing['offset'] ?? -1) >= $offset) {
        flock($fp, LOCK_UN); fclose($fp); return;
    }
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(['log_file' => $logFile,
                             'offset'   => $offset,
                             'agg'      => $agg],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
}
