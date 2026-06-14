# TASK-P3-2 · Refactor `infopedia.php` — remove local duplicates, use helpers

**Step:** S4 (implement) + S5 (regression verify)
**Phase:** 3 — thin endpoints
**~5 min** | Depends on: TASK-P2-3 (BUG-07 fix done), TASK-P1-2, TASK-P1b-2

## Goal
Remove `downloadAndCacheGoogleSheet()` and `isCacheValid()` from `infopedia.php` (they
duplicate `util_file.php`). Replace inline `parseData()` CSV logic with `parseEntryLine()`
from `util_entry.php`. Result: no more duplicate function definitions.

## Constitution refs
- `CP2` — `$type = "web"` before `require` (done in TASK-P2-3, verify it's in place)
- `CA7` — re-use: `isCacheValid`, `readCache`, `writeCache` from `util_file.php`; `parseEntryLine` from `util_entry.php`
- `CC3` — no diagnostic echo in response (BUG-04 removed in TASK-P2-5)
- `CD4` — `infopedia.html` SPA: do NOT touch it; edit `infopedia.php` surgically
- `CA1` — simple first: minimal changes, keep existing HTML generation logic intact

## Step requirements
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-2, REQ-S4-7
→ [`S5-green-regression.md`](../../../.ai/requirements/S5-green-regression.md) REQ-S5-4, REQ-S5-5

## Files
| Action | File |
|---|---|
| EDIT | `infopedia.php` |

## Changes (surgical — do not rewrite wholesale, CD4)

**1. Add helpers after `require 'util.php'`:**
```php
require_once 'util_file.php';
require_once 'util_entry.php';
```

**2. Remove duplicate local functions** (search and delete):
- `downloadAndCacheGoogleSheet($url, $cacheFile)` — replaced by `writeCache()`
- `isCacheValid($cacheFile)` (local version) — replaced by `isCacheValid()` from `util_file.php`

**3. Update call sites** (lines ~272–299):
```php
// BEFORE:
if (!isCacheValid($cacheFile)) {
    if ($useReadPhp) { ... } else {
        downloadAndCacheGoogleSheet($googleSheetUrl, $cacheFile);
    }
}

// AFTER:
if (!isCacheValid($cacheFile, $cacheTime)) {
    if ($useReadPhp) {
        // call read.php endpoint (existing HTTP call logic unchanged)
    } else {
        $sheetData = @file_get_contents($googleSheetUrl);
        if ($sheetData !== false) {
            writeCache($cacheFile, $sheetData); // CA7: reuse writeCache
        } else {
            log_warn("Failed to fetch Google Sheet for infopedia");
        }
    }
}
```

**4. In `parseData()` — replace manual CSV split with `parseEntryLine()`:**
```php
// BEFORE (in foreach $lines):
$parts = str_getcsv($line);
$timestamp = $parts[0]; $entry = $parts[1];
list($topic, $node, $content) = explode(" | ", $entry);
$entryType = substr($content, -1);

// AFTER:
$parsed = parseEntryLine($line); // CA7, CP3
if (empty($parsed)) { continue; }
$timestamp = $parsed['timestamp'];
$topic     = $parsed['topic'];
$node      = $parsed['node'];
$content   = $parsed['content'];
$entryType = $parsed['entry_type'];
```

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_entry_test.php   # GREEN
D:\_progs\xampp\php\php.exe util_file_test.php    # GREEN
# Manual: open http://localhost/infopedia.php → page renders correctly, no leaked text
```

## Commit

```
refactor(infopedia): use util_file + util_entry, remove local duplicate functions

Removed downloadAndCacheGoogleSheet() and local isCacheValid() (duplicated
util_file.php). parseData() now uses parseEntryLine() from util_entry.php.
HTML output behavior unchanged (CA1, CD4).

Refs: CP2, CA7, CC3, CD4, CA1, REQ-S4-1, REQ-S4-2, REQ-S5-4
```
SemVer: PATCH — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P3-3](TASK-P3-3-raw-first-upload.md)

