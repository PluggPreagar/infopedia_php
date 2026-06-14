<?php
require_once 'util_test.php';

$src = file_get_contents('upload.php');
$lines = explode("\n", $src);
$typeSetLine = null;
$includeLine = null;

foreach ($lines as $i => $line) {
    if ($typeSetLine === null && preg_match('/\$type\s*=\s*["\']upload["\']/', $line)) {
        $typeSetLine = $i;
    }
    if ($includeLine === null && preg_match('/include.*util\.php|require.*util\.php/', $line)) {
        $includeLine = $i;
    }
}

assert_equals($typeSetLine !== null, true, 'upload.php: $type="upload" exists');
assert_equals($includeLine !== null, true, 'upload.php: include util.php exists');
assert_equals($typeSetLine < $includeLine, true, 'upload.php: $type set before include');

print_test_summary();

