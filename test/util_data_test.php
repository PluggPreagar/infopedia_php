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

test_summary();
