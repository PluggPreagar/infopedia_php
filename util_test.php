<?php
require_once __DIR__ . '/tests/util_test.php';
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    assert_equals(1 + 1, 2, 'harness wrapper: basic math');
    assert_equals('a', 'a', 'harness wrapper: string equality');
    assert_contains('hello world', 'world', 'harness wrapper: contains');
    echo "util_test.php wrapper self-test:\n";
    print_test_summary();
}
