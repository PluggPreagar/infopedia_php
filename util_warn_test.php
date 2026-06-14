<?php
require_once 'util_test.php';

$src = file_get_contents('util.php');
$start = strpos($src, 'function log_warn');
$end = strpos($src, 'function log_error', $start);
$body = $start === false || $end === false ? '' : substr($src, $start, $end - $start);

assert_equals($start !== false, true, 'log_warn function exists');
assert_equals(str_contains($body, '$GLOBALS[\'debug\']') || str_contains($body, '$GLOBALS["debug"]'), false, 'log_warn is not gated by debug');
assert_contains($body, 'WARNING:', 'log_warn writes WARNING prefix');

print_test_summary();

