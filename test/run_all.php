<?php
require_once __DIR__ . '/util_test.php';

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    if (basename($file) === 'util_test.php') continue; // harness, not a test suite
    echo "\n--- " . basename($file) . " ---\n";
    require_once $file;
}

echo "\n";
test_summary();
