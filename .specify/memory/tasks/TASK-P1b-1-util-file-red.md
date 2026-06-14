# TASK-P1b-1 · Write Failing Tests for `util_file.php` (RED)

**Step:** S3 — Failing Test
**Phase:** 1b — extract file/cache helper
**~2 min** | Depends on: [TASK-P0-1](TASK-P0-1-util-test-harness.md)

## Constitution refs
- `CA9` — prepare for caching, add later: stable keys, deterministic outputs, clear invalidation
- `CA14` — raw-first ingestion: `appendRaw` must be tested before implementing
- `CA11` — test-driven: write failing test first
- `CA6` — cache logic in isolated, testable functions

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md)
- REQ-S3-1: write test first, watch FAIL (RED)
- REQ-S3-5: use project harness

## Files
| Action | File |
|---|---|
| CREATE | `util_file_test.php` |
| NOT YET | `util_file.php` (created in TASK-P1b-2) |

## Code — `util_file_test.php`

```php
<?php
require_once 'util_test.php';
require_once 'util_file.php'; // RED: does not exist yet → fatal error expected

$tmp = sys_get_temp_dir() . '/infopedia_test_' . uniqid() . '.cache';

// writeCache + readCache round-trip
writeCache($tmp, "hello\nworld");
assert_equals(readCache($tmp), "hello\nworld", 'cache: write+read round-trip');

// isCacheValid — fresh file is valid
assert_equals(isCacheValid($tmp, 3600), true, 'cache: fresh file valid');

// isCacheValid — maxAge=0 always misses (force_update path)
assert_equals(isCacheValid($tmp, 0), false, 'cache: maxAge=0 forces miss');

// isCacheValid — missing file is invalid
assert_equals(isCacheValid($tmp . '.missing', 3600), false, 'cache: missing file invalid');

// appendRaw — appends, never overwrites (CA14)
$raw = $tmp . '.raw';
appendRaw($raw, 'line1');
appendRaw($raw, 'line2');
$content = file_get_contents($raw);
assert_contains($content, 'line1', 'appendRaw: line1 present');
assert_contains($content, 'line2', 'appendRaw: line2 present');
assert_equals(substr_count($content, "\n"), 2, 'appendRaw: two lines appended');

// cleanup
@unlink($tmp); @unlink($raw);

print_test_summary();
```

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_file_test.php
# Expected: PHP Fatal error (util_file.php not found) — RED confirmed ✓
```

## Commit

```
test(file): add failing tests for util_file.php (RED)
Refs: CA9, CA14, CA11, CA6, REQ-S3-1, REQ-S3-5
```

## Next task
→ [TASK-P1b-2](TASK-P1b-2-util-file-green.md)

