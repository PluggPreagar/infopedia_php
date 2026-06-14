# TASK-P2-6 · BUG-01+02 — `str_leng()` typo + undefined `$response` in `log_return`

**Step:** S3 (RED) + S4 (GREEN)
**Phase:** 2 — bug fixes
**~2 min**

## Bug
- **BUG-01** `upload.php:43`: `str_leng($response)` — `str_leng` is not a PHP function → Fatal error on every upload request.
- **BUG-02** `download.php:43`: same `str_leng($response)` + `$response` is undefined (the HTTP body was never stored in that variable).

Both cause a Fatal error at `log_return`, meaning the request always dies before the log
entry is written — defeating `CC3` observability entirely.

## Constitution refs
- `CC1.1–CC1.5` — reproduce, document, prove
- `CC3` — observability: `log_return` must execute on every request
- `CC4` — fail fast on bad config/input (not on typos in our own code)

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md) REQ-S3-1, REQ-S3-2
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1

## Files
| Action | File |
|---|---|
| CREATE | `util_str_leng_test.php` (regression test) |
| EDIT | `upload.php:43` |
| EDIT | `download.php:43` |

## S3 — Failing test (RED)

```php
<?php
// util_str_leng_test.php — regression for BUG-01 + BUG-02 (CC1.3)
require_once 'util_test.php';

foreach (['upload.php', 'download.php'] as $file) {
    $src = file_get_contents($file);
    assert_equals(
        str_contains($src, 'str_leng('),
        false,
        "$file: no str_leng() typo"
    );
    assert_equals(
        str_contains($src, 'strlen('),
        true,
        "$file: strlen() present"
    );
}

print_test_summary();
```

**Run before fix:**
```powershell
D:\_progs\xampp\php\php.exe util_str_leng_test.php
# Expected: FAIL (str_leng found) — RED confirmed ✓
```

## S4 — Fix (GREEN)

**`upload.php` — last line:**
```php
// BEFORE:
log_return( str_leng($response) . " bytes saved ( " . $data . ")"  );

// AFTER ($rawData holds what we actually sent; $response is the Google reply):
log_return( strlen($data) . " bytes queued (" . $data . ")" );
```

**`download.php` — last line:**
```php
// BEFORE:
log_return( str_leng($response) . " bytes saved ( " . $data . ")"  );

// AFTER (filesize is the correct metric for a download):
log_return( filesize($downloadFile) . " bytes sent (" . $downloadFile . ")" );
```

**Run after fix:**
```powershell
D:\_progs\xampp\php\php.exe util_str_leng_test.php
# Expected: PASS: 4  FAIL: 0  exit 0  (GREEN ✓)
```

## Commit

```
fix(upload,download): str_leng→strlen, fix undefined $response in log_return

BUG-01/02: str_leng() does not exist → Fatal error on every upload/download
request, silencing log_return entirely (CC3 violation).
Fixed to strlen($data) in upload.php and filesize($downloadFile) in download.php.

Reproduction test: util_str_leng_test.php (RED before, GREEN after).
Refs: CC1.1–CC1.5, CC3, REQ-S3-2, REQ-S4-1
```
SemVer: PATCH — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P3-1](TASK-P3-1-refactor-read.md)

