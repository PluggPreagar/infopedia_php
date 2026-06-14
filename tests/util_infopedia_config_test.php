<?php
require_once __DIR__ . '/util_test.php';

$src = file_get_contents(__DIR__ . '/../infopedia.php');
$lines = explode("\n", $src);
$typeSetLine = null;
$requireLine = null;
$parseIniCount = 0;

foreach ($lines as $i => $line) {
    if ($typeSetLine === null && preg_match('/\$type\s*=\s*["\']web["\']/', $line)) {
        $typeSetLine = $i;
    }
    if ($requireLine === null && preg_match('/require\s+["\']util\.php["\']|include.*util\.php/', $line)) {
        $requireLine = $i;
    }
    if (str_contains($line, 'parse_ini_file') && !str_contains(trim($line), '//')) {
        $parseIniCount++;
    }
}

assert_equals($typeSetLine !== null, true, 'infopedia.php: $type="web" exists');
assert_equals($requireLine !== null, true, 'infopedia.php: require util.php exists');
assert_equals($typeSetLine < $requireLine, true, 'infopedia.php: $type before require');
assert_equals($parseIniCount, 0, 'infopedia.php: no duplicate parse_ini_file call');

print_test_summary();


