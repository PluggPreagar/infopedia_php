# TASK-P2-1 · BUG-05 — `log_warn` silently dropped in production (RED→GREEN)

**Step:** S3 (RED) + S4 (GREEN)
**Phase:** 2 — bug fixes
**~3 min** | Depends on: [TASK-P0-1](TASK-P0-1-util-test-harness.md)

## Bug
`util.php:63-68` — `log_warn()` gated by `$debug` like `log_debug()`.
In production (`$debug=false`) warnings are silently swallowed — they never reach `infopedia.log`.

## Constitution refs
- `CC1` — assume bugs exist: reproduce → document → re-run → prove
- `CC1.1` — find: `log_warn` in prod with `$debug=false` → nothing logged
- `CC1.2` — document: symptom in commit body
- `CC1.3` — re-run: failing test first (RED)
- `CC1.4` — prove: test passes after fix (GREEN), kept as regression guard
- `CC1.5` — no bugfix merge without RED→GREEN test
- `CC3` — observability: warnings must always log (only `log_debug` should be gated)

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md) REQ-S3-1, REQ-S3-2
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-11

## Files
| Action | File |
|---|---|
| CREATE | `util_warn_test.php` (regression test, keep permanently) |
| EDIT | `util.php` — remove `$debug` gate from `log_warn()` |

## S3 — Failing test (RED)

```php
<?php
// util_warn_test.php — regression for BUG-05 (CC1.3, CC1.4)
require_once 'util_test.php';

$tmp = sys_get_temp_dir() . '/infopedia_warn_test_' . uniqid() . '.log';

// simulate production: debug=false, logFile points to tmp
$GLOBALS['debug'] = false;
$GLOBALS['logFile'] = $tmp;
$GLOBALS['type'] = 'test';
$GLOBALS['session_id'] = 'test-session';
$_SERVER['REQUEST_URI']  = '/test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']  = '/util_warn_test.php';

require_once 'util.php'; // defines log_warn etc.

// RED: log_warn should write to log even when $debug=false
log_warn('test warning message');

$logContent = file_get_contents($tmp);
assert_contains($logContent, 'WARNING',              'log_warn: writes WARNING to log');
assert_contains($logContent, 'test warning message', 'log_warn: message written');

@unlink($tmp);
print_test_summary();
```

**Run before fix:**
```powershell
D:\_progs\xampp\php\php.exe util_warn_test.php
# Expected: FAIL — RED confirmed ✓
```

## S4 — Fix (GREEN)

**File:** `util.php`

Remove the `$debug` guard from `log_warn()` only:

```php
// BEFORE (buggy):
function log_warn($message) {
    if (!$GLOBALS['debug']) {
        return; // BUG: silently drops warnings in production
    }
    log_to_file("WARNING: " . $message);
}

// AFTER (fixed — CC3: warnings always observable):
function log_warn($message) {
    log_to_file("WARNING: " . $message);
}
```

`log_debug()` keeps its `$debug` gate — that is intentional.

**Run after fix:**
```powershell
D:\_progs\xampp\php\php.exe util_warn_test.php
# Expected: PASS: 2  FAIL: 0  exit 0  (GREEN ✓)
```

## Commit

```
fix(util): log_warn always logs regardless of debug flag

BUG-05: log_warn was gated by $debug like log_debug — in production
(debug=false) warnings were silently dropped, violating CC3 observability.
Only log_debug should be conditional; log_warn/log_error are always on.

Reproduction test: util_warn_test.php (RED before, GREEN after).
Refs: CC1.1–CC1.5, CC3, REQ-S3-2, REQ-S4-11
```
SemVer: PATCH — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P2-2](TASK-P2-2-bug06-upload-type.md)

