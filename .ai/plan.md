# Refactor Plan — refactor/202606

> Spec: `.ai/api_spec.md`  
> Workflow: RED → GREEN → REFACTOR per task (CW5)  
> Tests live in `test/` folder; run with `php test/run_all.php`

## Status — 2026-06-15

**T01–T18 IMPLEMENTED.** All implementation files are present and the test suite passes.

| Range | Status | Notes |
|-------|--------|-------|
| T01–T06 | DONE | Test harness + `util_entry.php` (parseEntry, sortCsvData, aggregateVotes) |
| T07–T08 | DONE | `util_format.php` (csv_to_json, csv_to_txt02, csv_to_txt03) |
| T09 | DONE | `util_cache.php` (isCacheValid, readCache, writeCache, touchOutdated) |
| T09b | DONE | `util_throttle.php` (checkThrottle, throttleRetryAfter) |
| T10 | DONE | `util_http.php` (set_content_type, respond_json, respond_error) |
| T11 | DONE | `util.php` refactored ($since, $refresh aliases kept during transition) |
| T12–T17 | DONE | Route files: entries.php, votes.php, dumps.php, files.php, health.php, index.php |
| T18 | DONE | `.htaccess` rewritten for new route structure |
| T19–T20 | PENDING | Old files (read.php, upload.php, download.php, util_file.php) not yet deleted — awaiting manual test verification |

---

## Phase 0 — Test Infrastructure

### T01 · Create test/ folder and harness

**Files:** `test/util_test.php`, `test/run_all.php`

Copy `util_test.php` logic (assert_equals, log_test, print_test_summary) to `test/util_test.php`.  
Keep the root `util_test.php` temporarily for reference, delete at Phase 8.

`test/run_all.php`:
```php
<?php
$tests = glob(__DIR__ . '/*_test.php');
foreach ($tests as $t) { require $t; }
```

**Verify:** `php test/run_all.php` → no output / 0 failures (empty suite).

---

## Phase 1 — Entry Parsing (`util_entry.php`)

Core of the refactor. New format: `/path/node | [attr:val ...] | [display-ts] | content<type>`

### T02 · RED — write parseEntry tests

**File:** `test/util_entry_test.php`

Write failing tests for `parseEntry(string $entry): array` which returns:
```php
[
  'path'       => '/climate/solutions',
  'content'    => 'Solar panels.',
  'type'       => '.',
  'display_ts' => '2025-09-07 20:44:54',  // null if absent
  'attrs'      => ['author' => 'martin'],  // empty array if none
  'votes'      => ['sid_abc' => 1],        // empty array if no votes attrs
]
```

Test cases:
- Minimal: `/climate/solutions | Solar panels.`
- With attr: `/climate/solutions | author:martin | Solar panels.`
- With display-ts: `/climate/solutions | 2025-09-07 20:44:54 | Solar panels.`
- With attr + ts: `/climate/solutions | author:martin | 2025-09-07 20:44:54 | Solar panels.`
- With vote: `/poll/q1 | votes:sid_abc:1 | Fair question?`
- With multiple votes: `/poll/q1 | votes:sid_abc:1 | votes:sid_def:2 | Fair question?`
- No type suffix → server appends `.`
- Delete marker: `/climate/solutions | --` → type = `--`

**Verify:** `php test/run_all.php` → failures (RED).

### T03 · GREEN — implement parseEntry

**File:** `util_entry.php` (rewrite, keep file name)

Parse rules (left to right, content is always last):
1. Column 1 → `path`
2. Last column → `content` + extract trailing type char
3. Middle columns:
   - matches `^[a-zA-Z_]+:\S` → attribute; if key is `votes` parse as `votes:<sid>:<n>`
   - matches `^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$` → `display_ts`

Keep old functions (`transform_0v02`, `cleanData`, `data_entry_line_sortable`) commented out until Phase 8.

**Verify:** `php test/run_all.php` → 0 failures (GREEN).

### T04 · RED — write sortCsvData tests

**File:** `test/util_entry_test.php` (append)

Test `sortCsvData(string $csv): string` — input: raw Google Sheets CSV, output: sorted+deduped CSV.

Test cases:
- Single row → passes through
- Two rows same path, different outer timestamps → keep newest
- Delete marker row → path removed from output
- Multiline quoted content (odd `"` count) → joined into single row
- Out-of-order paths → sorted ascending by path
- Mixed date formats in outer timestamp (`DD/MM/YYYY`, `YYYY-MM-DD`) → normalised to `YYYY-MM-DD HH:MM:SS`

### T05 · GREEN — implement sortCsvData

**File:** `util_entry.php`

Extract and simplify from `read.php::sortCsvData()`. Key simplification:  
Sort key is now just column 1 (`/path/node`) — no `##timeRev##` trick needed since we dedup by keeping the row with the highest outer timestamp (plain string compare on `YYYY-MM-DD HH:MM:SS`).

Algorithm:
1. Normalise line endings, split on `\n`
2. Aggregate wrapped lines (odd `"` count)
3. Parse each line with `str_getcsv`, normalise outer timestamp to `YYYY-MM-DD HH:MM:SS`
4. Group by `path` from column 2 (the entry column, first ` | `-delimited part)
5. Keep row with latest outer timestamp per path; skip if content ends with `--`
6. `ksort()` by path, reconstruct CSV

**Verify:** `php test/run_all.php` → 0 failures.

### T06 · RED + GREEN — aggregateVotes

**File:** `test/util_entry_test.php` + `util_entry.php`

`aggregateVotes(string $csv, string $session_id): string`

Test cases:
- Single vote row → emitted as-is (own sid visible, others anonymised)
- Two rows same path different sids → summed, own sid kept, others → `votes:others:<total>`
- Zero vote rows → CSV unchanged
- Mixed entry + vote rows → only vote rows aggregated

---

## Phase 2 — Format Conversion (`util_format.php`)

### T07 · RED — write util_format tests

**File:** `test/util_format_test.php`

All functions take a sorted+deduped CSV string (output of `sortCsvData`).

`csv_to_json(string $csv): array`
- Basic entry → `['/path/node' => ['timestamp'=>..., 'message'=>..., 'attrs'=>[...]]]`
- Vote entry → includes `'votes'` key
- Empty CSV → `[]`

`csv_to_txt02(string $csv): string`
- Basic entry → `/path/node | YYYY-MM-DD HH:MM:SS | content.`
- Multiple entries → sorted, newline-joined

`csv_to_txt03(string $csv): string`
- Depth 1 path → no indent
- Depth 2 path → 4-space indent
- Content only (path stripped)

### T08 · GREEN — implement util_format.php

**File:** `util_format.php` (new)

Extract and simplify from `read.php` format conversion blocks.  
Each function: split CSV lines → `parseEntry()` per line → build output.  
Use `parseEntry()` from `util_entry.php` — no duplicate parsing logic.

```php
<?php
require_once __DIR__ . '/util_entry.php';

function csv_to_json(string $csv): array { ... }
function csv_to_txt02(string $csv): string { ... }
function csv_to_txt03(string $csv): string { ... }
```

**Verify:** `php test/run_all.php` → 0 failures.

---

## Phase 3 — Cache Helpers (`util_cache.php`)

### T09 · RED + GREEN — util_cache

**Files:** `test/util_cache_test.php`, `util_cache.php`

Extract from `util_file.php`. Functions:

```php
function isCacheValid(string $file, int $maxAge, ?string $outdatedFile, int $delay): bool
function readCache(string $file): string        // returns '' if file missing
function writeCache(string $file, string $data): void
function touchOutdated(string $file): void
```

Tests use `sys_get_temp_dir()` for temp files — no real cache directory needed.

Test cases:
- Missing cache file → invalid
- Cache file newer than maxAge → valid
- Cache file older than maxAge → invalid
- outdatedFile newer than cacheFile + delay → invalid

---

## Phase 3b — Throttling (`util_throttle.php`)

### T09b · RED + GREEN — util_throttle

**Files:** `test/util_throttle_test.php` (already written), `util_throttle.php`

State file: `data/throttle_<key>.dat` — plain text `<window_start>:<count>`

```php
function checkThrottle(string $dir, string $key, int $max, int $window, int $now = 0): bool
function throttleRetryAfter(string $dir, string $key, int $window, int $now = 0): int
```

When `$now === 0`, use `time()`. All test calls pass explicit `$now`.

Algorithm for `checkThrottle`:
1. Read state file; parse `window_start:count` (defaults: start=now, count=0)
2. If `now - window_start >= window`: reset start=now, count=0
3. If `max === 0`: return true (disabled)
4. Increment count; write state file
5. Return `count <= max`

**Verify:** `php test/run_all.php`

Route files call: `if (!checkThrottle('data/', $key, $config['throttle_max'] ?? 0, $config['throttle_window'] ?? 60)) { respond_error('THROTTLED', ..., 429); }`

---

## Phase 4 — HTTP Helpers (`util_http.php`)

### T10 · Implement util_http.php (no unit tests — output functions)

**File:** `util_http.php` (new)

```php
function set_content_type(string $format): void
// 'json'|'csv'|'txt.0.2'|'txt.0.3' → correct Content-Type + charset=utf-8

function respond_json(mixed $data, int $status = 200): never
// http_response_code($status); header('Content-Type: application/json; charset=utf-8');
// echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;

function respond_error(string $code, string $message, int $status): never
// respond_json(['error' => ['code' => $code, 'message' => $message]], $status)
```

**Verify:** Manual — include in a test route, confirm headers in browser/curl.

---

## Phase 5 — Bootstrap (`util.php`)

### T11 · Refactor util.php

**File:** `util.php` (edit in place)

Changes:
- Rename `$last_timestamp` → `$since`
- Accept `since` query param (keep reading `ts` as alias during transition, remove alias at end)
- Accept `refresh` query param (keep reading `force_update` as alias, remove at end)
- Remove `$type` global — route files set their own type
- Keep: config load, logging functions, `$session_id`, `$tenant_id`, `$since`, timezone

No unit test (side-effectful bootstrap). Verify by running a route file manually.

---

## Phase 6 — Route Files

Each route file: set `$type` if needed → `require 'util.php'` → validate → dispatch → `respond_*`.

### T12 · entries.php

**File:** `entries.php` (new, replaces read.php for GET + upload.php for POST)

```
GET  → validate format/tid → load+sort CSV (cache or upstream) → convert format → respond
POST → validate tid/entry → formatEntry() → write to upstream or tenant file → touchOutdated → respond 201
```

Uses: `util.php`, `util_entry.php`, `util_format.php`, `util_http.php`, `util_cache.php`

### T13 · votes.php

**File:** `votes.php` (new)

Same as `entries.php` but calls `aggregateVotes()` after `sortCsvData()` on GET.

### T14 · dumps.php

**File:** `dumps.php` (new)

POST only. Reads `dump` param → forwards to Google Forms dump endpoint (from config) → respond 201.

### T15 · files.php

**File:** `files.php` (new, replaces download.php)

GET `?file=filename` → check whitelist → `readfile()` with correct Content-Type → respond 200.  
Not on whitelist → `respond_error('NOT_FOUND', ..., 404)`.

### T16 · health.php

**File:** `health.php` (new)

GET → check entry + vote cache age → `respond_json(['status'=>'ok', ...])`.

### T17 · index.php

**File:** `index.php` (rename/copy from infopedia.php)

No logic change — just serves `infopedia.html` with timestamp substitution.

---

## Phase 7 — Routing

### T18 · Update .htaccess

**File:** `.htaccess` (rewrite)

```apache
RewriteEngine On

RewriteRule ^/?$                    index.php [QSA,L]
RewriteRule ^/?entries/?$           entries.php [QSA,L]
RewriteRule ^/?votes/?$             votes.php [QSA,L]
RewriteRule ^/?dumps/?$             dumps.php [QSA,L]
RewriteRule ^/?files/(.+)$          files.php?file=$1 [QSA,L]
RewriteRule ^/?health/?$            health.php [QSA,L]

RewriteRule ^(favicon.*|apple-touch-icon\.png|android-chrome-.*|site\.webmanifest|robots\.txt|styles.*\.css)$ $1 [NC,L]

RewriteRule ^(.*)$                  index.php?missed=$1 [QSA,L]
```

**Verify:** curl each route, confirm correct response.

---

## Phase 8 — Cleanup

### T19 · Delete old files

Remove: `read.php`, `upload.php`, `download.php`, `util_file.php`, root `util_test.php`, root `util_entry_test.php`, root `util_file_test.php`

### T20 · Full test suite + smoke test

```
php test/run_all.php
```

Then curl smoke tests:
```bash
curl http://localhost/entries?format=csv
curl http://localhost/entries?format=json
curl http://localhost/votes?format=json
curl http://localhost/health
curl -X POST http://localhost/entries -d "entry=/test/node | Hello."
```

---

## Task Summary

| ID | Phase | Task | File(s) |
|----|-------|------|---------|
| T01 | 0 | Test harness | `test/util_test.php`, `test/run_all.php` |
| T02 | 1 | RED parseEntry | `test/util_entry_test.php` |
| T03 | 1 | GREEN parseEntry | `util_entry.php` |
| T04 | 1 | RED sortCsvData | `test/util_entry_test.php` |
| T05 | 1 | GREEN sortCsvData | `util_entry.php` |
| T06 | 1 | RED+GREEN aggregateVotes | `test/util_entry_test.php`, `util_entry.php` |
| T07 | 2 | RED format funcs | `test/util_format_test.php` |
| T08 | 2 | GREEN util_format.php | `util_format.php` |
| T09 | 3 | RED+GREEN util_cache.php | `test/util_cache_test.php`, `util_cache.php` |
| T09b | 3b | RED+GREEN util_throttle.php | `test/util_throttle_test.php`, `util_throttle.php` |
| T10 | 4 | util_http.php | `util_http.php` |
| T11 | 5 | Refactor util.php | `util.php` |
| T12 | 6 | entries.php | `entries.php` |
| T13 | 6 | votes.php | `votes.php` |
| T14 | 6 | dumps.php | `dumps.php` |
| T15 | 6 | files.php | `files.php` |
| T16 | 6 | health.php | `health.php` |
| T17 | 6 | index.php | `index.php` |
| T18 | 7 | .htaccess | `.htaccess` |
| T19 | 8 | Delete old files | — |
| T20 | 8 | Full test + smoke | `test/run_all.php` |
