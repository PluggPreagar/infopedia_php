<?php
// Test harness — no PHPUnit, no dependencies (CP1, CT1)
$_test_results = ['pass' => 0, 'fail' => 0, 'errors' => []];

function assert_equals($got, $expected, string $label): bool {
    global $_test_results;
    $pass = ($got === $expected);
    $pass ? $_test_results['pass']++ : $_test_results['fail']++;
    if (!$pass) {
        $_test_results['errors'][] = "FAIL [$label]\n  got:      " . var_export($got, true)
            . "\n  expected: " . var_export($expected, true);
    }
    return $pass;
}

function assert_contains(string $haystack, string $needle, string $label): bool {
    return assert_equals(str_contains($haystack, $needle), true, $label);
}

function log_test(string $label, bool $pass): void {
    echo ($pass ? '  PASS' : '  FAIL') . " — $label\n";
}

function print_test_summary(): void {
    global $_test_results;
    echo "\n=== Test Summary ===\n";
    echo "PASS: {$_test_results['pass']}  FAIL: {$_test_results['fail']}\n";
    foreach ($_test_results['errors'] as $e) { echo "$e\n"; }
    exit($_test_results['fail'] > 0 ? 1 : 0);
}

// self-tests only when run directly (not when require_once'd by another test)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    assert_equals(1 + 1, 2, 'harness: basic math');
    assert_equals('a', 'a', 'harness: string equality');
    assert_contains('hello world', 'world', 'harness: contains');
    echo "util_test.php self-test:\n";
    print_test_summary();
}
