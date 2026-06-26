<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_cache.php';

// ─── long_poll_files ──────────────────────────────────────────────────────────

// T1: empty file list → false immediately
assert_eq(false, long_poll_files([], time(), 1), 'empty files → false');

// T2: non-existent file → false (timeout)
assert_eq(false, long_poll_files(['/tmp/no_such_file_xyzzy.txt'], time(), 0), 'missing file timeout=0 → false');

// T3: existing file modified before $now → false (timeout=0)
$f = tempnam(sys_get_temp_dir(), 'lp_');
file_put_contents($f, 'x');
$before = time() + 2;            // $now in the future → file is "old"
assert_eq(false, long_poll_files([$f], $before, 0), 'old file → false on timeout=0');
unlink($f);

// T4: existing file modified after $now → true immediately
$f2 = tempnam(sys_get_temp_dir(), 'lp_');
file_put_contents($f2, 'x');
$after = time() - 2;             // $now in the past → file is "new"
assert_eq(true,  long_poll_files([$f2], $after,  1), 'new file → true immediately');
unlink($f2);

require_once __DIR__ . '/../util_data.php';

// ─── parse_log_line ───────────────────────────────────────────────────────────

// T5: malformed line → null
assert_eq(null, parse_log_line('too short'), 'malformed → null');

// T6: RETURN line parsed correctly
$raw = '[2026-06-26 16:07:00] ;  entries ;  /entries?tid=demo ;  GET ;  abc@demo ;  entries.php ;  RETURN: ok in 0.0452 seconds';
$r = parse_log_line($raw);
assert_eq('2026-06-26 16:07:00', $r['timestamp'] ?? null, 'timestamp parsed');
assert_eq('entries',  $r['type']    ?? null, 'type parsed');
assert_eq('GET',      $r['method']  ?? null, 'method parsed');
assert_eq('abc',      $r['session'] ?? null, 'session parsed');
assert_eq('demo',     $r['tenant']  ?? null, 'tenant parsed');
assert_eq('RETURN',   $r['level']   ?? null, 'level=RETURN');
assert_eq(45.2,       $r['ms']      ?? null, 'ms parsed from seconds');

// T7: ERROR line
$rawE = '[2026-06-26 16:08:00] ;  entries ;  /entries ;  POST ;  xyz@ ;  entries.php ;  ERROR: bad input';
$e = parse_log_line($rawE);
assert_eq('ERROR', $e['level'] ?? null, 'level=ERROR');
assert_eq(null,    $e['ms'],            'ms=null on ERROR');
assert_eq('',      $e['tenant'] ?? null, 'empty tenant');

// ─── merge_stats_chunk ────────────────────────────────────────────────────────

$agg = empty_stats_agg();
// Set tl_min_ts before first merge so timeline indexing works
$agg['tl_min_ts'] = strtotime('2026-06-26 16:00:00');
$agg['tl_bucket'] = 3600;
$agg['tl_label']  = '1h buckets';

$lines = [
    parse_log_line('[2026-06-26 16:07:00] ;  entries ;  /entries_add ;  POST ;  abc@demo ;  entries.php ;  RETURN: ok in 0.0452 seconds'),
    parse_log_line('[2026-06-26 16:07:30] ;  votes ;  /votes_add ;  POST ;  abc@demo ;  votes.php ;  RETURN: ok in 0.0121 seconds'),
    parse_log_line('[2026-06-26 16:08:00] ;  entries ;  /entries ;  POST ;  xyz@ ;  entries.php ;  ERROR: bad input'),
];

$agg = merge_stats_chunk($agg, $lines);
assert_eq(2,    $agg['requests'],  '2 RETURN lines counted');
assert_eq(1,    $agg['errors'],    '1 ERROR counted');
assert_eq(0,    $agg['warnings'],  '0 warnings');
assert_eq(1,    count(array_unique($agg['sessions'])), '1 unique session (abc)');
assert_eq(1,    count(array_unique(array_filter($agg['tenants']))), '1 unique tenant (demo)');
assert_eq(45.2, $agg['by_type']['entries']['times_sum'] ?? null, 'entries times_sum');
assert_eq(1,    $agg['by_type']['entries']['post'] ?? null, 'entries POST count');
assert_eq(1,    $agg['by_type']['entries']['errors'] ?? null, 'entries error count');
assert_eq(1,    $agg['by_type']['votes']['post'] ?? null, 'votes POST count');
assert_eq(2,    $agg['by_hour'][16] ?? null, 'by_hour slot 16 = 2 RETURN lines');
assert_eq(2,    $agg['rt_buckets']['10-100ms'] ?? null, 'both in 10-100ms bucket');

// T8: merge called twice — non-additive fields accumulate correctly
$agg2 = empty_stats_agg();
$agg2['tl_min_ts'] = strtotime('2026-06-26 16:00:00');
$agg2['tl_bucket'] = 3600;
$agg2['tl_label']  = '1h buckets';
$line1 = [parse_log_line('[2026-06-26 16:07:00] ;  entries ;  / ;  GET ;  s1@t1 ;  entries.php ;  RETURN: ok in 0.010 seconds')];
$line2 = [parse_log_line('[2026-06-26 16:07:30] ;  entries ;  / ;  GET ;  s2@t1 ;  entries.php ;  RETURN: ok in 0.030 seconds')];
$agg2 = merge_stats_chunk($agg2, $line1);
$agg2 = merge_stats_chunk($agg2, $line2);
assert_eq(2, $agg2['requests'], 'two merges → 2 requests');
// sessions must contain both s1 and s2 (for correct sessions_uniq later)
$uniq = count(array_unique($agg2['sessions']));
assert_eq(2, $uniq, 'two merges → 2 unique sessions');
// avg_ms = (10 + 30) / 2 = 20ms — computed from sum/count in cache, not stored directly
$avg = $agg2['times_count'] > 0 ? $agg2['times_sum'] / $agg2['times_count'] : 0;
assert_eq(20.0, round($avg, 1), 'avg_ms computed correctly from sum/count');

// ─── stats_cache_valid ────────────────────────────────────────────────────────

// T9: valid cache
$tmpLog = tempnam(sys_get_temp_dir(), 'log_');
file_put_contents($tmpLog, str_repeat('x', 100));
$cache_ok = ['log_file' => $tmpLog, 'offset' => 50, 'agg' => []];
assert_eq(true, stats_cache_valid($cache_ok, $tmpLog), 'valid cache → true');

// T10: offset > filesize → invalid (log rotated)
$cache_stale = ['log_file' => $tmpLog, 'offset' => 999, 'agg' => []];
assert_eq(false, stats_cache_valid($cache_stale, $tmpLog), 'offset > filesize → false');

// T11: wrong log_file → invalid
$cache_wrong = ['log_file' => '/other/path.log', 'offset' => 10, 'agg' => []];
assert_eq(false, stats_cache_valid($cache_wrong, $tmpLog), 'wrong log_file → false');
unlink($tmpLog);

// ─── load/save_stats_cache ────────────────────────────────────────────────────

$cacheFile = tempnam(sys_get_temp_dir(), 'sc_');
$logFile2  = tempnam(sys_get_temp_dir(), 'lg_');
file_put_contents($logFile2, str_repeat('a', 200));

// T12: load from absent file → null
unlink($cacheFile);
assert_eq(null, load_stats_cache($cacheFile, $logFile2), 'absent cache → null');

// T13: save then load → returns correct agg
$agg_save = empty_stats_agg();
$agg_save['requests'] = 42;
save_stats_cache($cacheFile, $logFile2, 100, $agg_save);
$loaded = load_stats_cache($cacheFile, $logFile2);
assert_eq(42, $loaded['agg']['requests'] ?? null, 'loaded agg matches saved');
assert_eq(100, $loaded['offset'] ?? null, 'loaded offset matches saved');

// T14: load with stale offset (offset > filesize) → null
save_stats_cache($cacheFile, $logFile2, 99999, $agg_save);
assert_eq(null, load_stats_cache($cacheFile, $logFile2), 'stale offset → null');

// T15: load with wrong log_file → null
save_stats_cache($cacheFile, '/other.log', 10, $agg_save);
assert_eq(null, load_stats_cache($cacheFile, $logFile2), 'wrong log_file → null');

// T16: save skips write when existing cache has higher offset
save_stats_cache($cacheFile, $logFile2, 100, $agg_save);   // write offset=100
$agg_low = empty_stats_agg();
$agg_low['requests'] = 1;
save_stats_cache($cacheFile, $logFile2, 50, $agg_low);     // attempt lower offset
$after = load_stats_cache($cacheFile, $logFile2);
assert_eq(42, $after['agg']['requests'] ?? null, 'lower-offset write skipped');

unlink($cacheFile);
unlink($logFile2);

// ─── data_stats_respond ───────────────────────────────────────────────────────

// Build a small fixture log file
$fixLog  = tempnam(sys_get_temp_dir(), 'fxlog_');
$fixCache = tempnam(sys_get_temp_dir(), 'fxcache_'); unlink($fixCache);
$lines = [
    '[2026-06-26 16:07:00] ;  entries ;  /entries_add ;  POST ;  s1@demo ;  entries.php ;  RETURN: ok in 0.0452 seconds',
    '[2026-06-26 16:07:30] ;  votes ;  /votes_add ;  POST ;  s2@demo ;  votes.php ;  RETURN: ok in 0.0121 seconds',
    '[2026-06-26 16:08:00] ;  entries ;  /entries ;  GET ;  s1@ ;  entries.php ;  ERROR: bad',
];
file_put_contents($fixLog, implode("\n", $lines) . "\n");

// T17: first request (null offset) → full response, all counts
$resp = data_stats_respond($fixLog, $fixCache, null, 50);
assert_eq('stats',  $resp['entity']                          ?? null, 'entity=stats');
assert_eq(2,        $resp['full']['sessions_uniq']           ?? null, 'sessions_uniq=2');
assert_eq(1,        $resp['full']['tenants_uniq']            ?? null, 'tenants_uniq=1');
assert_eq(2,        $resp['increments']['requests']          ?? null, 'requests=2 in increments');
assert_eq(1,        $resp['increments']['errors']            ?? null, 'errors=1 in increments');
assert_eq(2,        count($resp['increments']['rows'] ?? []), 'rows=2 RETURN lines');
assert_eq(true,     isset($resp['offset']),                           'offset present');

$saved_offset = $resp['offset'];

// T18: warm cache — no new lines → cache not rewritten, rows empty
$cache_mtime_before = filemtime($fixCache);
usleep(100000); // 100ms
$resp2 = data_stats_respond($fixLog, $fixCache, $saved_offset, 50);
assert_eq(0, $resp2['increments']['requests'] ?? -1, 'no new lines → requests_delta=0');
assert_eq(0, count($resp2['increments']['rows'] ?? [1]), 'no new lines → rows empty');
$cache_mtime_after = filemtime($fixCache);
assert_eq($cache_mtime_before, $cache_mtime_after, 'cache not rewritten when no new lines');

// T19: stale offset → ['stale' => true]
$resp3 = data_stats_respond($fixLog, $fixCache, 999999, 50);
assert_eq(true, $resp3['stale'] ?? false, 'stale offset → stale=true');

// T20: append new line, delta response has only new content
file_put_contents($fixLog,
    '[2026-06-26 17:00:00] ;  health ;  /health ;  GET ;  s3@demo ;  health.php ;  RETURN: ok in 0.001 seconds' . "\n",
    FILE_APPEND);
$resp4 = data_stats_respond($fixLog, $fixCache, $saved_offset, 50);
assert_eq(1, $resp4['increments']['requests'] ?? null, 'delta: 1 new request');
assert_eq(1, count($resp4['increments']['rows'] ?? []), 'delta: 1 new row');
assert_eq('health', $resp4['increments']['rows'][0]['type'] ?? null, 'delta row type=health');

unlink($fixLog);
unlink($fixCache);

test_summary();
