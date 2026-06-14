<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_file.php'; // RED: does not exist yet

$tmp = sys_get_temp_dir() . '/infopedia_test_' . uniqid() . '.cache';

writeCache($tmp, "hello\nworld");
assert_equals(readCache($tmp), "hello\nworld", 'cache: write+read round-trip');
assert_equals(isCacheValid($tmp, 3600), true, 'cache: fresh file valid');
assert_equals(isCacheValid($tmp, 0), false, 'cache: maxAge=0 forces miss');
assert_equals(isCacheValid($tmp . '.missing', 3600), false, 'cache: missing file invalid');

$raw = $tmp . '.raw';
appendRaw($raw, 'line1');
appendRaw($raw, 'line2');
$content = file_get_contents($raw);
assert_contains($content, 'line1', 'appendRaw: line1 present');
assert_contains($content, 'line2', 'appendRaw: line2 present');
assert_equals(substr_count($content, "\n"), 2, 'appendRaw: two lines appended');

@unlink($tmp);
@unlink($raw);

print_test_summary();


