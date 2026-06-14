<?php
require_once 'util_test.php';

$src = file_get_contents('statistic.php');
$lines = explode("\n", $src);
$typeSetLine = null;
$includeLine = null;

foreach ($lines as $i => $line) {
    if ($typeSetLine === null && preg_match('/\$type\s*=\s*["\']stat["\']/', $line)) {
        $typeSetLine = $i;
    }
    if ($includeLine === null && preg_match('/include.*util\.php|require.*util\.php/', $line)) {
        $includeLine = $i;
    }
}

assert_equals($typeSetLine !== null, true, 'statistic.php: $type="stat" exists');
assert_equals($includeLine !== null, true, 'statistic.php: includes util.php');
assert_equals($typeSetLine < $includeLine, true, 'statistic.php: $type before include');
assert_equals(str_contains($src, "\$logFile = 'infopedia.log';"), false, 'statistic.php: no hardcoded single-quote logFile assignment');
assert_equals(str_contains($src, '$config[\'logFile\']') || str_contains($src, '$config["logFile"]'), true, 'statistic.php: logFile read from config');
assert_contains($src, 'log_return(', 'statistic.php: calls log_return');

print_test_summary();

