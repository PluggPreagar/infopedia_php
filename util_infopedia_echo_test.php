<?php
require_once 'util_test.php';

$src = file_get_contents('infopedia.php');
$inParseData = false;
$braceDepth = 0;
$found = false;

foreach (explode("\n", $src) as $line) {
    if (!$inParseData && preg_match('/function\s+parseData\s*\(/', $line)) {
        $inParseData = true;
    }

    if ($inParseData) {
        if (preg_match('/echo\s+["\']topic:/', $line)) {
            $found = true;
        }
        $braceDepth += substr_count($line, '{') - substr_count($line, '}');
        if ($braceDepth <= 0 && str_contains($line, '}')) {
            $inParseData = false;
        }
    }
}

assert_equals($found, false, 'infopedia.php parseData: no diagnostic echo present');

print_test_summary();

