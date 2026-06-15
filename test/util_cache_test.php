<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_cache.php';

// ─── isCacheValid ─────────────────────────────────────────────────────────────
// Input:  cache file path, maxAge (s), outdatedFile path|null, delay (s)
// Output: bool — true if cache may be used, false if stale/missing/outdated

$dir = sys_get_temp_dir() . '/infopedia_cache_test_' . getmypid();
mkdir($dir);
$cache    = "$dir/test.cache";
$outdated = "$dir/test.cache.outdated";

function iv(bool $result, bool $expect, string $msg): void {
    assert_eq($expect, $result, "isCacheValid: $msg");
}

// missing cache file → invalid
iv(isCacheValid($cache, 3600, null, 5), false, 'missing cache → invalid');

// create cache file, age=0 → valid
file_put_contents($cache, 'data');
iv(isCacheValid($cache, 3600, null, 5), true, 'fresh cache → valid');

// simulate stale cache: touch with old mtime
touch($cache, time() - 3700);
iv(isCacheValid($cache, 3600, null, 5), false, 'stale cache (age > maxAge) → invalid');

// reset to fresh
touch($cache);

// outdated file missing → no effect
iv(isCacheValid($cache, 3600, $outdated, 5), true, 'no outdated file → valid');

// outdated file exists but older than cache + delay → no effect
touch($cache);
file_put_contents($outdated, '');
touch($outdated, time() - 10);   // outdated is 10s old, cache just written
iv(isCacheValid($cache, 3600, $outdated, 5), true, 'outdated older than cache+delay → valid');

// outdated file newer than cache + delay → invalid
touch($cache, time() - 20);      // cache is 20s old
touch($outdated);                 // outdated just touched (now > cache + 5s delay)
iv(isCacheValid($cache, 3600, $outdated, 5), false, 'outdated newer than cache+delay → invalid');

// ─── readCache ────────────────────────────────────────────────────────────────
// Input:  file path
// Output: file contents, or '' if file missing

assert_eq('',      readCache("$dir/readable.cache"),   'readCache: missing → empty string');
file_put_contents("$dir/readable.cache", 'hello');
assert_eq('hello', readCache("$dir/readable.cache"),   'readCache: returns file contents');

// ─── writeCache ───────────────────────────────────────────────────────────────
// Input:  file path, string data
// Effect: writes data to file (creates if missing)

writeCache("$dir/written.cache", 'written data');
assert_eq('written data', file_get_contents("$dir/written.cache"), 'writeCache: file created with content');

writeCache("$dir/written.cache", 'overwritten');
assert_eq('overwritten', file_get_contents("$dir/written.cache"), 'writeCache: overwrites existing file');

// ─── touchOutdated ────────────────────────────────────────────────────────────
// Input:  file path
// Effect: creates file if missing, updates mtime if exists

$tf = "$dir/outdated.signal";
assert_eq(false, file_exists($tf),                     'touchOutdated: file absent before');
touchOutdated($tf);
assert_eq(true,  file_exists($tf),                     'touchOutdated: file created');
$mt1 = filemtime($tf);
sleep(1);
touchOutdated($tf);
clearstatcache();
assert_eq(true, filemtime($tf) >= $mt1,                'touchOutdated: mtime updated');

// cleanup
array_map('unlink', glob("$dir/*"));
rmdir($dir);
