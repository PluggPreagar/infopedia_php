# TASK-P2-4 · BUG-03 — `$header` undefined in `sortCsvData()`

**Step:** S4 — Implement (covered by existing GREEN test from P1-2)
**Phase:** 2 — bug fixes
**~2 min** | Depends on: [TASK-P1-2](TASK-P1-2-util-entry-green.md) complete

## Bug
`read.php:107` — `$header` is undefined inside `sortCsvData()`. PHP emits a notice and
the variable evaluates to `null`, prepending a null byte to the reconstructed CSV output.
The correct fix is to replace `sortCsvData()` with `sortAndDeduplicateCsv()` from
`util_entry.php` (which has no `$header` variable).

## Constitution refs
- `CC1.1–CC1.5` — reproduce, document, prove
- `CP3` — data-format fidelity: CSV output must be clean (no null prefix)
- `CA7` — re-use: `sortAndDeduplicateCsv()` already exists and is tested

## Test coverage
`util_entry_test.php` already covers `sortAndDeduplicateCsv()` (GREEN from TASK-P1-2).
No new test file needed — run `util_entry_test.php` as the regression proof.

## Step requirements
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-9

## Files
| Action | File |
|---|---|
| EDIT | `read.php` — add `require_once 'util_entry.php'`, replace `sortCsvData()` call |
| (later) | `read.php` — full refactor in TASK-P3-1; this task only removes the broken call |

## Fix

In `read.php`, after `require 'util.php'` add:

```php
require_once 'util_entry.php';
require_once 'util_file.php';
```

Replace the `sortCsvData()` call:

```php
// BEFORE (line ~158):
$response = sortCsvData($response);

// AFTER:
$response = sortAndDeduplicateCsv($response); // replaces broken sortCsvData (BUG-03, CP3)
```

Remove the entire `sortCsvData()` function definition (lines 21–120 of `read.php`).

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_entry_test.php
# Expected: all PASS (regression guard ✓)
# Manual: curl /entry/get?force_update=1 → no null byte at start of CSV output
```

## Commit

```
fix(read): replace undefined-$header sortCsvData with sortAndDeduplicateCsv

BUG-03: $header undefined in sortCsvData() caused null prefix in CSV output.
Replaced with sortAndDeduplicateCsv() from util_entry.php (CA7, CP3).
Regression guard: util_entry_test.php.

Refs: CC1.1–CC1.5, CP3, CA7, REQ-S4-1, REQ-S4-9
```
SemVer: PATCH — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P2-5](TASK-P2-5-bug04-echo-leak.md)

