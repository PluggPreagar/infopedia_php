<?php
// Test harness — shared by all *_test.php files

$GLOBALS['_test_pass'] = 0;
$GLOBALS['_test_fail'] = 0;

function assert_eq(mixed $expected, mixed $actual, string $msg): void {
    $exp = is_string($expected) ? $expected : json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $act = is_string($actual)   ? $actual   : json_encode($actual,   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($exp === $act) {
        $GLOBALS['_test_pass']++;
        // echo "  PASS: $msg\n";
    } else {
        $GLOBALS['_test_fail']++;
        echo "  FAIL: $msg\n";
        echo "    expected: $exp\n";
        echo "    actual:   $act\n";
    }
}

function test_summary(): void {
    $p = $GLOBALS['_test_pass'];
    $f = $GLOBALS['_test_fail'];
    echo ($f === 0 ? "OK" : "FAIL") . " — $p passed, $f failed\n";
}
