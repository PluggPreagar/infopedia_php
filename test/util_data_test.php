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

test_summary();
