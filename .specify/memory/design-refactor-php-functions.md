# Design: PHP-Function Refactoring — InfoPedia_PHP

**CW1 · brainstorming** | Status: approved → ready for CW3 plan
Constitution ref: CP1, CP2, CC1–CC5, CA6, CA7, CA14, CD1

---

## 1. Current-State Analysis

### 1.1 File inventory

| File | Lines | Role | Problem |
|---|---|---|---|
| `util.php` | 95 | Bootstrap, logging | `log_warn` gated by `$debug` (should always log) |
| `read.php` | 169 | Fetch + cache + sort + serve CSV | `$header` undefined in `sortCsvData()`; no `$type` before include |
| `upload.php` | 48 | Forward entry to Google Form | `str_leng()` typo; no `$type`; no raw-first persistence (CA14) |
| `download.php` | 45 | Whitelist file download | `str_leng()` typo; `$response` undefined in `log_return` |
| `echo.php` | 54 | Debug request dump | Redundant `$configFile` before include (minor) |
| `statistic.php` | 134 | Log viewer | Hardcodes `$logFile`; doesn't use `util.php` at all (CC2) |
| `infopedia.php` | 307 | HTML SPA server | Duplicates config loading; `echo` in `parseData()` (CC3); duplicates cache logic from `read.php` (CA7); no `$type` before `require` |

### 1.2 Missing helpers (referenced in constitution, do not exist yet)

| File | Purpose | Constitution ref |
|---|---|---|
| `util_file.php` | Cache I/O: valid?, read, write, mark-outdated | CP1, CA7, CA9 |
| `util_entry.php` | CSV parse, `formatEntry()`, sort/dedup | CP1, CA6, CP3 |
| `util_test.php` | Test harness: `assert_equals`, `log_test`, `print_test_summary` | CT1, CA11 |

---

## 2. Bug Catalogue (CC1 — Assume Bugs Exist)

Every item becomes a failing test before a fix.

| ID | File | Line | Bug | Constitution |
|---|---|---|---|---|
| BUG-01 | `upload.php` | 43 | `str_leng()` — function does not exist → fatal | CC1, CC4 |
| BUG-02 | `download.php` | 43 | `str_leng()` + `$response` undefined → fatal | CC1, CC4 |
| BUG-03 | `read.php` | 107 | `$header` undefined in `sortCsvData()` → null prepended to CSV | CC1, CP3 |
| BUG-04 | `infopedia.php` | 125 | `echo "topic: …"` inside `parseData()` — leaks into HTML response | CC3 |
| BUG-05 | `util.php` | 63-68 | `log_warn()` gated by `$debug` — warnings silently dropped in prod | CC3 |
| BUG-06 | `upload.php` | 1 | No `$type` before `include_once 'util.php'` → config section "none" merged | CC2, CP2 |
| BUG-07 | `infopedia.php` | 3+231 | `require 'util.php'` runs without `$type`; then re-parses config at line 231 with `$type="web"` — bootstrap inconsistent | CC2, CP2 |
| BUG-08 | `upload.php` | all | No raw-first persistence before Google POST — replay impossible on failure | CA14 |

---

## 3. Target Architecture

```
util.php            — bootstrap only (config, session, timezone, logging fns)
util_file.php       — cache I/O (isCacheValid, readCache, writeCache, markCacheOutdated, appendRaw)
util_entry.php      — entry logic (parseEntryLine, formatEntry, sortAndDeduplicateCsv, buildMostRecentEntry)
util_test.php       — test harness (assert_equals, log_test, print_test_summary)

read.php            — thin: $type="entry" → include util.php, use util_file + util_entry
upload.php          — thin: $type="upload" → include util.php, appendRaw first, then POST
download.php        — thin: $type="download" → include util.php, validate + stream
echo.php            — thin: $type="echo" → include util.php, dump request
statistic.php       — thin: $type="stat" → include util.php, parse log + render
infopedia.php       — thin: $type="web" before require, use util_file, use util_entry
```

### Design decisions

- **`util_file.php`** exposes: `isCacheValid($file, $maxAge)`, `readCache($file)`,
  `writeCache($file, $data)`, `markCacheOutdated($file)`, `appendRaw($file, $line)`.
  All cache state is encapsulated here (CA9 seam for future caching).
- **`util_entry.php`** exposes: `parseEntryLine($line)` → array, `sortAndDeduplicateCsv($raw)`,
  `buildMostRecentEntry($line)`, `formatEntry($parts)`. `formatEntry()` is the
  old→0v02 converter (CP3).
- **`util_test.php`** exposes: `assert_equals($got, $expected, $label)`,
  `log_test($label, $pass)`, `print_test_summary()`. Minimal, no output buffering tricks.
- **raw-first rule (CA14):** `upload.php` calls `appendRaw($rawLogFile, $data)` before
  the Google POST. `$rawLogFile = $config['rawLog'] ?? "data/upload_raw.log"`.
- **`log_warn` fix:** always logs regardless of `$debug` (same as `log_error`).
- **`$type` discipline (CP2):** every endpoint sets `$type` **before** `include/require 'util.php'`.

---

## 4. Refactoring Phases (to be detailed in plan)

### Phase 0 — Test harness (prerequisite)
Create `util_test.php` with harness. No production change. Tests: self-test the harness.

### Phase 1 — Extract helpers + write tests
1. Create `util_entry.php` — extract + test `parseEntryLine`, `sortAndDeduplicateCsv`,
   `buildMostRecentEntry` from `read.php` + `infopedia.php`.
2. Create `util_file.php` — extract + test `isCacheValid`, `readCache`, `writeCache`,
   `markCacheOutdated`, `appendRaw`.

### Phase 2 — Fix bugs (RED → GREEN per bug)
Fix BUG-01…BUG-07 in dedicated commits with failing tests first.

### Phase 3 — Refactor endpoints
Thin out each endpoint to use helpers; fix BUG-08 (raw-first in upload).

### Phase 4 — Verify backward compatibility
Run existing format contract tests (`?format=csv`, `txt.0.2`, `json.0.3`).
Confirm `data/<tid>.*` files unchanged. (CD1, CD3)

---

## 5. Compatibility constraints

- All existing `data/<tid>.csv|.cache|.log` MUST remain readable (CD1).
- `sortAndDeduplicateCsv()` MUST produce identical output to current `sortCsvData()` (CD3).
- `formatEntry()` MUST handle old Sheet format + 0v02 (CP3).
- No new library/dependency (CP1).
- `appendRaw` is append-only, idempotent-safe (CA14).

---

## 6. Open questions (resolved)

| Q | Decision |
|---|---|
| Should `statistic.php` use `util.php`? | Yes — set `$type="stat"`, include util.php, read `$config['logFile']` |
| Should `echo.php` use `log_return`? | Yes — add `log_return("echo done")` at end (CC3) |
| `util_entry.php` — old format only or also 0v02? | Both; `formatEntry()` converts old→0v02; parsers handle both |
| `rawLog` config key location? | `[general]` section, key `rawLog`, default `data/upload_raw.log` |

