<?php
require_once 'util_test.php';

$src = file_get_contents('upload.php');
$lines = explode("\n", $src);
$appendRawLine = null;
$postLine = null;
$requireUtilFileLine = null;

foreach ($lines as $i => $line) {
    if ($requireUtilFileLine === null && str_contains($line, "util_file.php")) {
        $requireUtilFileLine = $i;
    }
    if ($appendRawLine === null && str_contains($line, 'appendRaw(')) {
        $appendRawLine = $i;
    }
    if ($postLine === null && str_contains($line, 'file_get_contents($url')) {
        $postLine = $i;
    }
}

assert_equals($requireUtilFileLine !== null, true, 'upload.php: util_file.php required');
assert_equals($appendRawLine !== null, true, 'upload.php: appendRaw() call exists');
assert_equals($postLine !== null, true, 'upload.php: Google POST exists');
assert_equals($appendRawLine < $postLine, true, 'upload.php: appendRaw before Google POST');
assert_contains(file_get_contents('infopedia.cfg'), 'rawLog', 'infopedia.cfg: rawLog configured');

print_test_summary();

