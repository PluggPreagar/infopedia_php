# TASK-P3-3 · Add raw-first persistence to `upload.php` (CA14)

**Step:** S3 (RED) + S4 (GREEN) + S5 (verify replay)
**Phase:** 3 — thin endpoints / raw-first
**~4 min** | Depends on: TASK-P1b-2 (util_file.php GREEN), TASK-P2-2 (BUG-06 fixed)

## Goal
Persist the raw incoming `$data` to an append-only log file **before** the Google POST.
If the POST fails (network, quota, outage), the data is preserved and can be replayed
after a system reset/fix — no data loss.

## Constitution refs
- `CA14` — raw-first, replayable ingestion: append raw before any processing
- `CA9` — prepare for caching: derived files are rebuildable from the raw log
- `CC2` — config-driven: `rawLog` path in `infopedia.cfg [general]`
- `CC3` — `log_return` records what happened (bytes queued)
- `CP1` — use `appendRaw()` from `util_file.php`, no new dependency

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md) REQ-S3-1, REQ-S3-2, REQ-S3-4
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-4, REQ-S4-13

## Files
| Action | File |
|---|---|
| CREATE | `util_upload_raw_test.php` (regression test) |
| EDIT | `upload.php` — add `appendRaw` before Google POST |
| EDIT | `infopedia.cfg` — add `rawLog = data/upload_raw.log` to `[general]` |

## S3 — Failing test (RED)

```php
<?php
// util_upload_raw_test.php — regression for CA14 raw-first in upload.php (CC1.3)
require_once 'util_test.php';

$src = file_get_contents('upload.php');
$lines = explode("\n", $src);

$appendRawLine  = null;
$googlePostLine = null;

foreach ($lines as $i => $line) {
    if ($appendRawLine === null && str_contains($line, 'appendRaw(')) {
        $appendRawLine = $i;
    }
    if ($googlePostLine === null && str_contains($line, 'file_get_contents($url')) {
        $googlePostLine = $i;
    }
}

assert_equals($appendRawLine !== null,  true, 'upload.php: appendRaw() call exists');
assert_equals($googlePostLine !== null, true, 'upload.php: Google POST exists');
assert_equals($appendRawLine < $googlePostLine, true,
    'upload.php: appendRaw called BEFORE Google POST (CA14)');

print_test_summary();
```

**Run before fix:**
```powershell
D:\_progs\xampp\php\php.exe util_upload_raw_test.php
# Expected: FAIL — RED confirmed ✓
```

## S4 — Fix (GREEN)

**`infopedia.cfg`** — add to `[general]` section:
```ini
rawLog = data/upload_raw.log
```

**`upload.php`** — add raw-first append immediately after `$data` is read, before the
Google POST options are built:

```php
// raw-first: persist before processing — enables replay if POST fails (CA14)
require_once 'util_file.php';
$rawLog = $config['rawLog'] ?? 'data/upload_raw.log';
appendRaw($rawLog, date('Y-m-d H:i:s') . ',' . $data);
```

This goes **before** the `$options = [ 'http' => [...] ]` block.

**Run after fix:**
```powershell
D:\_progs\xampp\php\php.exe util_upload_raw_test.php
# Expected: PASS: 3  FAIL: 0  exit 0  (GREEN ✓)
```

## S5 — Regression / replay verification

Simulate a failed POST: set an invalid `googlePostUrl`, make a request.
Verify `data/upload_raw.log` contains the entry even though the POST failed.
The entry can be replayed by re-POSTing from the raw log after the URL is fixed.

## Commit

```
feat(upload): raw-first append to rawLog before Google POST (CA14)

If the Google POST fails (network, quota, outage), the raw input is already
persisted in data/upload_raw.log (append-only, LOCK_EX). Re-runs after
a fix can replay from this log. rawLog path is config-driven (CC2).

Reproduction test: util_upload_raw_test.php (RED before, GREEN after).
Refs: CA14, CA9, CC2, CC3, CP1, REQ-S3-2, REQ-S3-4, REQ-S4-4, REQ-S4-13
```
SemVer: MINOR (new feature, backward-compatible) — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P3-4](TASK-P3-4-fix-statistic.md)

