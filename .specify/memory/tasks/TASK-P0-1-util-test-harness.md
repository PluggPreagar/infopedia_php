# TASK-P0-1 · Create `util_test.php` — Test Harness

**Step:** S3 (write) + S4 (implement = same file here, harness IS the implementation)
**Phase:** 0 — prerequisite for all other tasks
**~2 min**

## Constitution refs
- `CP1` — no PHPUnit, no dependencies
- `CA11` — test-driven; this harness enables TDD for all subsequent tasks
- `CT1` — `assert_equals`, `log_test`, `print_test_summary` from `util_test.php`

## Step requirements
- [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md) → REQ-S3-5
- [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) → REQ-S4-1, REQ-S4-2

## Files
| Action | File |
|---|---|
| CREATE | `util_test.php` |

## Code

```php
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

// self-tests
assert_equals(1 + 1, 2, 'harness: basic math');
assert_equals('a', 'a', 'harness: string equality');
assert_contains('hello world', 'world', 'harness: contains');
echo "util_test.php self-test:\n";
print_test_summary();
```

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_test.php
# Expected: PASS: 3  FAIL: 0  exit 0
```

## Commit

```
test(util): add test harness util_test.php
Refs: CP1, CA11, CT1, REQ-S3-5
```
SemVer: PATCH (new test infra, no production change) — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P1-1](TASK-P1-1-util-entry-red.md)

