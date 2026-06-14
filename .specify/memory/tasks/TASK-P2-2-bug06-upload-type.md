# TASK-P2-2 · BUG-06 — `upload.php` missing `$type` before include

**Step:** S3 (RED) + S4 (GREEN)
**Phase:** 2 — bug fixes
**~2 min** | Depends on: [TASK-P0-1](TASK-P0-1-util-test-harness.md)

## Bug
`upload.php` does not set `$type` before `include_once 'util.php'`. `util.php` line 6:
`$type = $_GET['type'] ?? $type ?? "none"` — falls back to `"none"`, so the `[upload]`
config section is never merged. Any upload-specific config keys are silently ignored.

## Constitution refs
- `CC1.1–CC1.5` — reproduce, document, prove
- `CC2` — config-driven: `$type` selects the correct config section
- `CP2` — one file = one route: every endpoint MUST set `$type` before `include 'util.php'`

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md) REQ-S3-1, REQ-S3-2
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-4

## Files
| Action | File |
|---|---|
| EDIT | `upload.php` — add `$type = "upload";` as very first line |

## S3 — Failing test (RED)

The simplest reproducible proof: parse `upload.php` source and assert `$type` is set
before the `include_once` line.

```php
<?php
// util_upload_type_test.php — regression for BUG-06 (CC1.3)
require_once 'util_test.php';

$src = file_get_contents('upload.php');
$lines = explode("\n", $src);

$typeSetLine   = null;
$includeLine   = null;
foreach ($lines as $i => $line) {
    if ($typeSetLine === null && preg_match('/\$type\s*=\s*["\']upload["\']/', $line)) {
        $typeSetLine = $i;
    }
    if ($includeLine === null && preg_match('/include.*util\.php/', $line)) {
        $includeLine = $i;
    }
}

assert_equals($typeSetLine !== null, true, 'upload.php: $type="upload" exists');
assert_equals($includeLine !== null, true, 'upload.php: include util.php exists');
assert_equals($typeSetLine < $includeLine, true, 'upload.php: $type set before include');

print_test_summary();
```

**Run before fix:**
```powershell
D:\_progs\xampp\php\php.exe util_upload_type_test.php
# Expected: FAIL ($type not found) — RED confirmed ✓
```

## S4 — Fix (GREEN)

Add `$type = "upload";` as **first line** of `upload.php` (before `include_once`):

```php
<?php
$type = "upload"; // CP2: set type before util.php bootstrap to merge [upload] config section
include_once 'util.php';
// ...rest of upload.php unchanged...
```

**Run after fix:**
```powershell
D:\_progs\xampp\php\php.exe util_upload_type_test.php
# Expected: PASS: 3  FAIL: 0  exit 0  (GREEN ✓)
```

## Commit

```
fix(upload): set $type="upload" before util.php include

BUG-06: missing $type caused [upload] config section to never be merged.
Any upload-specific config keys were silently ignored (CC2, CP2 violation).

Reproduction test: util_upload_type_test.php (RED before, GREEN after).
Refs: CC1.1–CC1.5, CC2, CP2, REQ-S3-2, REQ-S4-4
```
SemVer: PATCH — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P2-3](TASK-P2-3-bug07-infopedia-config.md)

