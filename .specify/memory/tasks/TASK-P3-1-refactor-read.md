# TASK-P3-1 · Refactor `read.php` — use `util_file` + `util_entry`

**Step:** S4 (implement) + S5 (regression verify)
**Phase:** 3 — thin endpoints
**~5 min** | Depends on: TASK-P1-2 (GREEN), TASK-P1b-2 (GREEN), TASK-P2-4 (done)

## Goal
Replace inline cache logic and `sortCsvData()` with the helpers extracted in Phase 1.
Result: `read.php` becomes a thin orchestrator (~40 lines) with no local function definitions.

## Constitution refs
- `CP1` — procedural PHP, no classes
- `CP2` — one file = one route: `$type` set first, then `include 'util.php'`
- `CA7` — re-use over reinvent: `isCacheValid`, `readCache`, `writeCache`, `markCacheOutdated`, `sortAndDeduplicateCsv`
- `CD1`, `CD3` — output format MUST remain identical; no new fields, no removed fields
- `CC3` — diagnostics via `log_*`/`log_return` only
- `CC4` — fail fast on fetch error

## Step requirements
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-2, REQ-S4-5
→ [`S5-green-regression.md`](../../../.ai/requirements/S5-green-regression.md) REQ-S5-2, REQ-S5-4

## Files
| Action | File |
|---|---|
| EDIT | `read.php` — full replacement |

## Target `read.php`

```php
<?php
// Fetch → cache → sort → serve CSV/text/JSON (CP2: type set before include)
$type = "entry";
require 'util.php';
require_once 'util_entry.php';
require_once 'util_file.php';

$cacheMaxAge    = isset($_GET['force_update']) ? 0 : ($config['cache_time'] ?? 3600);
$googleSheetId  = $config['googleSheetId']    ?? '';
$googleSheetGid = $config['googleSheetGridId'] ?? '0';
$googleSheetUrl = "https://docs.google.com/spreadsheets/d/{$googleSheetId}/export?format=csv&gid={$googleSheetGid}";
$cacheFile      = $config['cacheFile'];
$cacheOutdatedFile = $config['cacheOutdatedFile'] ?? null;
$cacheDelaySeconds = $config['cache_time_delay'] ?? 10;

header('Content-Type: text/csv');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// outdated-signal: upload.php touched this file to request a refresh
$cacheOutdated = $cacheOutdatedFile
    && file_exists($cacheOutdatedFile) && file_exists($cacheFile)
    && (filemtime($cacheOutdatedFile) > filemtime($cacheFile) + $cacheDelaySeconds);

if (isCacheValid($cacheFile, $cacheMaxAge) && !$cacheOutdated) {
    echo readCache($cacheFile);
    log_return(filesize($cacheFile) . " bytes from cache");
    exit;
}

$response = @file_get_contents($googleSheetUrl);
if ($response === false) {
    http_response_code(500);
    log_warn("Failed to fetch Google Sheet: $googleSheetUrl");
    exit;
}

$response = sortAndDeduplicateCsv($response);
writeCache($cacheFile, $response);
echo $response;
log_return(strlen($response) . " bytes fetched+cached");
```

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_entry_test.php   # must stay GREEN
D:\_progs\xampp\php\php.exe util_file_test.php    # must stay GREEN
# Manual: curl "http://localhost/entry/get?sid=tst&tid=tst&force_update=1"
# → valid CSV, no null prefix, no PHP notices
```

## Commit

```
refactor(read): use util_file + util_entry, remove inline sortCsvData

Replaced 120-line sortCsvData() and manual cache logic with helpers from
util_entry.php + util_file.php. read.php is now a thin orchestrator (~40 lines).
Output format unchanged (CD1, CD3).

Refs: CP1, CP2, CA7, CD1, CD3, CC3, CC4, REQ-S4-1, REQ-S4-2, REQ-S5-4
```
SemVer: PATCH (refactor, no behavior change) — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P3-2](TASK-P3-2-refactor-infopedia.md)

