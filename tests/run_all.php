<?php
$php = PHP_BINARY;
$testDir = __DIR__;
$tests = glob($testDir . '/*_test.php');
sort($tests);

$failures = 0;
foreach ($tests as $test) {
    echo "=== " . basename($test) . " ===\n";
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($test), $exitCode);
    if ($exitCode !== 0) {
        $failures++;
    }
}

echo "\nFAILED=$failures\n";
exit($failures > 0 ? 1 : 0);

