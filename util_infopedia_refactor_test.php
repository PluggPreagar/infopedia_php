<?php
require_once 'util_test.php';

$src = file_get_contents('infopedia.php');

assert_contains($src, "require_once 'util_file.php'", 'infopedia.php: requires util_file.php');
assert_contains($src, "require_once 'util_entry.php'", 'infopedia.php: requires util_entry.php');
assert_equals(str_contains($src, 'function downloadAndCacheGoogleSheet'), false, 'infopedia.php: no local downloadAndCacheGoogleSheet');
assert_equals(preg_match('/function\s+isCacheValid\s*\(/', $src) === 1, false, 'infopedia.php: no local isCacheValid');
assert_contains($src, 'parseEntryLine(', 'infopedia.php: parseData uses parseEntryLine');
assert_contains($src, 'writeCache(', 'infopedia.php: downloads write through util_file.php');

print_test_summary();

