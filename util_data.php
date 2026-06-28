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

// ─── Filter helpers ───────────────────────────────────────────────────────────

function parse_filter(array $f): array {
    $out      = ['type' => [], 'method' => [], 'tid' => '', 'uri' => ''];
    $csv_keys = ['type', 'method'];
    $re_keys  = ['tid', 'uri'];

    foreach ($csv_keys as $k) {
        if (isset($f[$k]) && $f[$k] !== '') {
            $out[$k] = array_values(array_filter(
                array_map('trim', explode(',', (string)$f[$k])),
                fn($v) => $v !== ''
            ));
        }
    }

    foreach ($re_keys as $k) {
        if (isset($f[$k]) && $f[$k] !== '') {
            $v = (string)$f[$k];
            if (@preg_match('/' . addcslashes($v, '/') . '/', '') === false) {
                return ['valid' => false, 'bad_key' => $k, 'filter' => []];
            }
            $out[$k] = $v;
        }
    }

    return ['valid' => true, 'bad_key' => null, 'filter' => $out];
}

function apply_filter(array $row, array $filter): bool {
    if (!empty($filter['type'])) {
        if (!in_array($row['type'], $filter['type'], true)) return false;
    }
    if (!empty($filter['method'])) {
        if (!in_array($row['method'], $filter['method'], true)) return false;
    }
    if (!empty($filter['tid'])) {
        if (!preg_match('/' . addcslashes($filter['tid'], '/') . '/i', $row['tenant'])) return false;
    }
    if (!empty($filter['uri'])) {
        if (!preg_match('/' . addcslashes($filter['uri'], '/') . '/i', $row['uri'])) return false;
    }
    return true;
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

// ─── Stats respond ────────────────────────────────────────────────────────────

function data_stats_respond(string $logFile, string $cacheFile,
                            ?int $client_offset, int $log_viewer_max,
                            array $filter = []): array {
    clearstatcache(true, $logFile);
    $file_size = file_exists($logFile) ? filesize($logFile) : 0;
    $filtered  = !empty($filter);

    // Stale offset check (unfiltered only — filtered mode never provides a cursor)
    if (!$filtered && $client_offset !== null && $client_offset > $file_size) {
        return ['stale' => true];
    }

    // Load or init aggregate
    if ($filtered) {
        $agg         = empty_stats_agg();
        $from_offset = 0;
    } else {
        $cached      = load_stats_cache($cacheFile, $logFile);
        $agg         = $cached ? $cached['agg'] : empty_stats_agg();
        $from_offset = $cached ? $cached['offset'] : 0;
    }

    // Read new lines; in filtered mode apply filter per-line
    $new_lines = [];
    if ($from_offset < $file_size) {
        $fp = fopen($logFile, 'r');
        if ($fp) {
            fseek($fp, $from_offset);
            while (($raw = fgets($fp)) !== false) {
                $r = parse_log_line(trim($raw));
                if ($r !== null) {
                    if (!$filtered || apply_filter($r, $filter)) $new_lines[] = $r;
                }
            }
            fclose($fp);
        }
    }

    // Init timeline params on first ever build
    if ($agg['tl_min_ts'] === null && !empty($new_lines)) {
        $ts_vals = array_filter(array_map(
            fn($r) => $r['level'] === 'RETURN' ? strtotime($r['timestamp']) : null,
            $new_lines));
        if ($ts_vals) {
            $tl_min   = min($ts_vals);
            $tl_range = max($ts_vals) - $tl_min;
            if      ($tl_range < 7200)   { $tl_bucket = 300;   $tl_label = '5-min buckets'; }
            elseif  ($tl_range < 86400)  { $tl_bucket = 900;   $tl_label = '15-min buckets'; }
            elseif  ($tl_range < 604800) { $tl_bucket = 3600;  $tl_label = '1h buckets'; }
            else                         { $tl_bucket = 86400; $tl_label = '1-day buckets'; }
            $agg['tl_min_ts'] = $tl_min;
            $agg['tl_bucket'] = $tl_bucket;
            $agg['tl_label']  = $tl_label;
        }
    }

    // Merge; skip cache write in filtered mode
    if (!empty($new_lines)) {
        $agg = merge_stats_chunk($agg, $new_lines);
        if (!$filtered) save_stats_cache($cacheFile, $logFile, $file_size, $agg);
    }

    // Log viewer rows (new lines only; truncate on initial full-scan)
    $return_lines   = array_filter($new_lines, fn($r) => $r['level'] === 'RETURN');
    $rows_truncated = false;
    $rows           = array_values($return_lines);
    if ($from_offset === 0 && count($rows) > $log_viewer_max) {
        $rows = array_slice($rows, -$log_viewer_max);
        $rows_truncated = true;
    }

    return [
        'entity' => 'stats',
        'offset' => $file_size,
        'full'   => [
            'requests'       => $agg['requests'],
            'errors'         => $agg['errors'],
            'warnings'       => $agg['warnings'],
            'by_hour'        => $agg['by_hour'],
            'rt'             => $agg['rt_buckets'],
            'by_type'        => $agg['by_type'],
            'timeline'       => $agg['timeline'],
            'sessions_uniq'  => count(array_unique($agg['sessions'])),
            'tenants_uniq'   => count(array_filter(array_unique($agg['tenants']))),
            'avg_ms'         => $agg['times_count'] > 0
                                    ? round($agg['times_sum'] / $agg['times_count'], 2)
                                    : 0.0,
            'max_ms'         => round($agg['max_ms'], 2),
            'first_ts'       => $agg['first_ts'],
            'last_ts'        => $agg['last_ts'],
            'tl_min_ts'      => $agg['tl_min_ts'],
            'tl_bucket'      => $agg['tl_bucket'],
            'tl_label'       => $agg['tl_label'],
            'rows_truncated' => $rows_truncated,
        ],
        'increments' => [
            'rows' => $rows,
        ],
    ];
}

// ─── Ops channel ──────────────────────────────────────────────────────────────

function append_ops(string $tid, array $event): void {
    $event['type'] = 'ops';
    append_incr($tid !== '' ? 'ops_' . $tid : 'ops', $event);
}

// Read messages from both rotation files, deduplicate, filter by cursor, sort.
function data_ops_messages(string $fa, string $fb, int $ts, int $msgid): array {
    $msgs = [];
    $seen = [];
    foreach ([$fa, $fb] as $file) {
        if (!file_exists($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $m = json_decode($line, true);
            if (!is_array($m) || !isset($m['ts'], $m['msgid'])) continue;
            $key = $m['ts'] . ':' . $m['msgid'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if ($m['ts'] > $ts || ($m['ts'] === $ts && $m['msgid'] > $msgid))
                $msgs[] = $m;
        }
    }
    usort($msgs, fn($a, $b) => $a['ts'] === $b['ts']
        ? $a['msgid'] - $b['msgid']
        : $a['ts'] - $b['ts']);
    return $msgs;
}

function data_ops_respond(string $fa, string $fb,
                          ?int $ts, ?int $msgid, int $rotation_secs): array {
    $use_ts    = $ts    ?? 0;
    $use_msgid = $msgid ?? 0;

    // Stale detection: only when caller provided a cursor
    if ($ts !== null) {
        $all_msgs = data_ops_messages($fa, $fb, 0, 0);
        if (!empty($all_msgs) && $all_msgs[0]['ts'] > $ts + $rotation_secs) {
            return ['stale' => true];
        }
    }

    $msgs      = data_ops_messages($fa, $fb, $use_ts, $use_msgid);

    // Watermark for cursor in response
    $last     = !empty($msgs) ? end($msgs) : null;
    $resp_ts  = $last ? $last['ts']    : ($use_ts    ?: time());
    $resp_mid = $last ? $last['msgid'] : ($use_msgid ?: 0);

    return [
        'entity'     => 'ops',
        'ts'         => $resp_ts,
        'msgid'      => $resp_mid,
        'increments' => ['rows' => array_values($msgs)],
    ];
}
