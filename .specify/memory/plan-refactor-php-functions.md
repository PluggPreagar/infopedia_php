# Plan: PHP-Function Refactoring — InfoPedia_PHP

**CW3 · writing-plans** | Design: `design-refactor-php-functions.md`
**Steps:** S1 Branch → S2 Plan (this) → S3 Failing Test → S4 Implement → S5 Green+Regression → S6 Merge
Constitution refs: CC1–CC5, CA6, CA7, CA14, CP1–CP3, CD1, CD3, CT1, CV2

Treat **MUST** as blocking gate. Each task is ~2–5 min.  
Run tests: `D:\_progs\xampp\php\php.exe <test-file>.php`

---

## Branch

```
git checkout -b refactor/php-functions
```
Baseline gate: all existing tests pass (none yet — Phase 0 establishes baseline).

---

## Phase 0 — Test Harness  *(prerequisite for everything)*

### TASK-P0-1 · Create `util_test.php`

**File:** `util_test.php` (new)  
**Test first:** n/a (this IS the harness — self-tests inline)  
**Code:**

```php
<?php
// Test harness — no PHPUnit, no dependencies (CP1)
$_test_results = ['pass' => 0, 'fail' => 0, 'errors' => []];

function assert_equals($got, $expected, string $label): bool {
    global $_test_results;
    $pass = ($got === $expected);
    $pass ? $_test_results['pass']++ : $_test_results['fail']++;
    if (!$pass) {
        $_test_results['errors'][] = "FAIL [$label]\n  got:      " . var_export($got, true)
            . "\n  expected: " . var_export($expected, true);
    }
    return $pass;
}

function assert_contains(string $haystack, string $needle, string $label): bool {
    return assert_equals(str_contains($haystack, $needle), true, $label);
}

function log_test(string $label, bool $pass): void {
    echo ($pass ? "  PASS" : "  FAIL") . " — $label\n";
}

function print_test_summary(): void {
    global $_test_results;
    echo "\n=== Test Summary ===\n";
    echo "PASS: {$_test_results['pass']}  FAIL: {$_test_results['fail']}\n";
    foreach ($_test_results['errors'] as $e) { echo "$e\n"; }
    exit($_test_results['fail'] > 0 ? 1 : 0);
}

// self-test the harness
assert_equals(1 + 1, 2, 'harness: basic math');
assert_equals('a', 'a', 'harness: string equality');
assert_contains('hello world', 'world', 'harness: contains');
echo "util_test.php self-test:\n";
print_test_summary();
```

**Verification:** `php.exe util_test.php` → `PASS: 3  FAIL: 0`, exit 0.  
**Commit:** `test(util): add test harness util_test.php` — CV2, CG3 PATCH

---

## Phase 1 — Extract `util_entry.php`

### TASK-P1-1 · Write failing tests for entry parsing (RED)

**File:** `util_entry_test.php` (new)  
**Imports:** `util_test.php`, then (will fail until Phase 1-2) `util_entry.php`

```php
<?php
require_once 'util_test.php';
// RED: util_entry.php does not exist yet → fatal error is expected
require_once 'util_entry.php';

// parseEntryLine — old Sheet format
$r = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | Some fact."');
assert_equals($r['topic'],   '/clima',     'parseEntryLine: topic');
assert_equals($r['node'],    'biz',        'parseEntryLine: node');
assert_equals($r['content'], 'Some fact.', 'parseEntryLine: content');
assert_equals($r['entry_type'], '.',       'parseEntryLine: entry_type dot');

// parseEntryLine — delete marker
$r2 = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | --"');
assert_equals($r2['delete'], true, 'parseEntryLine: delete marker');

// sortAndDeduplicateCsv — later entry wins, key = topic+node
$raw = implode("\n", [
    '01/01/2025 00:00:00,"/a | b | first."',
    '02/01/2025 00:00:00,"/a | b | second."',
]);
$out = sortAndDeduplicateCsv($raw);
assert_contains($out, 'second.', 'dedup: last entry wins');
assert_equals(substr_count($out, '/a | b'), 1, 'dedup: only one entry per key');

// buildMostRecentEntry — rewrites path prefix
$mre = buildMostRecentEntry('02/01/2025 00:00:00,"/a | b | hello."');
assert_contains($mre, '/_/menu/Most-Recent-Entry', 'MRE: prefix injected');

print_test_summary();
```

**Run:** `php.exe util_entry_test.php` → **fatal/FAIL** (RED). ✓  
**Commit:** `test(entry): add failing tests for util_entry.php` — CV2

---

### TASK-P1-2 · Create `util_entry.php` (GREEN)

**File:** `util_entry.php` (new)  
**Extracted from:** `read.php:21-120`, `infopedia.php:84-137`

```php
<?php
// Entry parsing and formatting helpers (CA6, CP3)

/**
 * Parse one CSV line in old Sheet format:
 *   timestamp,"/topic | node | message"
 * Returns array with keys: timestamp, topic, node, content, entry_type, delete, raw
 */
function parseEntryLine(string $line): array {
    $parts = str_getcsv($line);
    if (count($parts) < 2) {
        return [];
    }
    $entry = $parts[1];
    if (str_starts_with($entry, '|')) {
        $entry = ' ' . $entry;
    }
    $segments = explode(' | ', $entry, 3);
    if (count($segments) < 3) {
        return [];
    }
    [$topic, $node, $content] = $segments;
    $delete = (str_ends_with(rtrim($line, '"'), '--'));
    return [
        'timestamp'  => $parts[0],
        'topic'      => $topic,
        'node'       => $node,
        'content'    => $content,
        'entry_type' => substr($content, -1),
        'delete'     => $delete,
        'raw'        => $line,
    ];
}

/**
 * Aggregate lines by topic+node key (last wins), then sort by key.
 * Handles: DOS line-endings, wrapped multi-line quotes, delete marker "--".
 * Output is identical to the old sortCsvData() — backward-compatible (CD1, CP3).
 */
function sortAndDeduplicateCsv(string $csvData): string {
    $csvData = str_replace("\r\n", "\n", $csvData);
    $lines = explode("\n", $csvData);
    $aggregated = [];
    $wrapped = '';
    $lineId = 0;

    foreach ($lines as $raw) {
        $line = $wrapped . $raw;
        $lineId++;
        if (substr_count($line, '"') % 2 !== 0) {
            $wrapped = $line . "\n";
            continue;
        }
        $wrapped = '';
        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue;
        }
        $segs = explode(' | ', $parts[1]);
        if (count($segs) < 2) {
            continue;
        }
        $key = $segs[0] . ' | ' . $segs[1];
        $isDelete = (str_ends_with($line, '--') || str_ends_with($line, '--"'));
        $aggregated[$key] = $isDelete ? '' : $line;
    }

    $mostRecent = _findMostRecentEntry($aggregated);
    ksort($aggregated);

    $out = '';
    foreach ($aggregated as $line) {
        if ($line !== '') {
            $out .= $line . "\n";
        }
    }
    if ($mostRecent !== '') {
        $out .= $mostRecent . "\n";
    }
    return trim($out);
}

/**
 * Build the synthetic /_/menu/Most-Recent-Entry line from the most recent non-hidden entry.
 */
function buildMostRecentEntry(string $line): string {
    if ($line === '') {
        return '';
    }
    // replace first " | " with "/"
    $mre = preg_replace('/ \| /', '/', $line, 1);
    $pos = strpos($mre, ',') + 1;
    if (isset($mre[$pos]) && $mre[$pos] === '"') {
        $pos++;
    }
    return substr_replace($mre, '/_/menu/Most-Recent-Entry | ', $pos, 0);
}

// --- internal helper ---
function _findMostRecentEntry(array $aggregated): string {
    foreach (array_reverse($aggregated, true) as $key => $line) {
        if (!str_starts_with($key, '/_') && $line !== '') {
            return buildMostRecentEntry($line);
        }
    }
    return '';
}

/**
 * Convert old Sheet format → 0v02 format (CP3, CD1).
 * Passthrough if already in 0v02 (starts with '/').
 */
function formatEntry(array $parsed): string {
    if (empty($parsed)) {
        return '';
    }
    // 0v02: /path/node,timestamp,message
    return $parsed['topic'] . '/' . $parsed['node']
        . ',' . $parsed['timestamp']
        . ',' . str_replace("\n", '\n', $parsed['content']);
}
```

**Run:** `php.exe util_entry_test.php` → **PASS all** (GREEN). ✓  
**Commit:** `feat(entry): add util_entry.php — parse, dedup, MRE, formatEntry` — CV2, CV1 MINOR

---

## Phase 1b — Extract `util_file.php`

### TASK-P1b-1 · Write failing tests (RED)

**File:** `util_file_test.php` (new)

```php
<?php
require_once 'util_test.php';
require_once 'util_file.php'; // RED until P1b-2

$tmp = sys_get_temp_dir() . '/infopedia_test_' . uniqid() . '.cache';

// writeCache + readCache round-trip
writeCache($tmp, "hello\nworld");
assert_equals(readCache($tmp), "hello\nworld", 'cache: write+read round-trip');

// isCacheValid — fresh file is valid
assert_equals(isCacheValid($tmp, 3600), true, 'cache: fresh file valid');

// isCacheValid — maxAge=0 forces miss
assert_equals(isCacheValid($tmp, 0), false, 'cache: maxAge=0 forces miss');

// appendRaw — appends lines
appendRaw($tmp . '.raw', 'line1');
appendRaw($tmp . '.raw', 'line2');
$raw = file_get_contents($tmp . '.raw');
assert_contains($raw, 'line1', 'appendRaw: line1 present');
assert_contains($raw, 'line2', 'appendRaw: line2 present');

// cleanup
@unlink($tmp); @unlink($tmp . '.raw');

print_test_summary();
```

**Run:** → **FAIL** (RED). ✓  
**Commit:** `test(file): add failing tests for util_file.php` — CV2

---

### TASK-P1b-2 · Create `util_file.php` (GREEN)

**File:** `util_file.php` (new)

```php
<?php
// Cache and file I/O helpers (CA9 — seam for future caching layer, CA14)

function isCacheValid(string $file, int $maxAge): bool {
    return $maxAge > 0
        && file_exists($file)
        && (time() - filemtime($file)) < $maxAge;
}

function readCache(string $file): string {
    return file_exists($file) ? file_get_contents($file) : '';
}

function writeCache(string $file, string $data): void {
    file_put_contents($file, $data);
}

function markCacheOutdated(string $file): void {
    if (file_exists($file)) {
        touch($file);
    }
}

/**
 * Append raw input line to a file — raw-first, append-only (CA14).
 * Ensures the directory exists; never overwrites existing data.
 */
function appendRaw(string $file, string $line): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($file, rtrim($line) . "\n", FILE_APPEND | LOCK_EX);
}
```

**Run:** `php.exe util_file_test.php` → **PASS all** (GREEN). ✓  
**Commit:** `feat(file): add util_file.php — cache I/O + raw-first appendRaw` — CV2, CV1 MINOR

---

## Phase 2 — Bug Fixes (one failing test per bug)

### TASK-P2-1 · BUG-05 — `log_warn` silently dropped in prod

**File:** `util_test_warn.php` (new, tiny, throwaway after fix)  
**RED test:** set `$debug=false`, call `log_warn("x")`, assert log file contains "WARNING".  
**Fix in `util.php`:** remove `if (!$GLOBALS['debug']) return;` from `log_warn()`.  
**GREEN, keep test as regression.**  
**Commit:** `fix(util): log_warn always logs regardless of debug flag` — CV2, CC1.5

---

### TASK-P2-2 · BUG-06 — `upload.php` missing `$type`

**Fix in `upload.php`:** add `$type = "upload";` as first line before `include_once`.  
**Test:** integration — check `$config` picks up `[upload]` section if present.  
**Commit:** `fix(upload): set $type="upload" before util.php include` — CV2

---

### TASK-P2-3 · BUG-07 — `infopedia.php` double config + missing `$type`

**Fix in `infopedia.php`:**
- Add `$type = "web";` before `require 'util.php'` (line 3).
- Remove the duplicate config block (lines 231–249) — `$config` is already set by `util.php`.
- Keep `$cacheTime`, `$topic` etc. reads (they come from `$config`).  
**Commit:** `fix(infopedia): set $type before require, remove duplicate config load` — CV2

---

### TASK-P2-4 · BUG-03 — `$header` undefined in `sortCsvData()`

**Test:** `php.exe util_entry_test.php` covers this via `sortAndDeduplicateCsv` (already GREEN).  
**Fix:** `read.php` now calls `sortAndDeduplicateCsv()` from `util_entry.php` — `sortCsvData()` removed.  
**Commit:** `fix(read): replace sortCsvData with sortAndDeduplicateCsv from util_entry` — CV2

---

### TASK-P2-5 · BUG-04 — `echo` in `parseData()` (`infopedia.php:125`)

**Fix:** remove `echo "topic: …"` line.  
**Test:** call `parseData()` with sample data; assert no output leaked.  
**Commit:** `fix(infopedia): remove diagnostic echo from parseData (CC3)` — CV2

---

### TASK-P2-6 · BUG-01+02 — `str_leng()` + undefined `$response`

**Files:** `upload.php:43`, `download.php:43`  
**Fix `upload.php`:** `log_return(strlen($rawData) . " bytes queued (" . $data . ")");`  
**Fix `download.php`:** `log_return(filesize($downloadFile) . " bytes sent (" . $downloadFile . ")");`  
**Commit:** `fix(upload,download): str_leng→strlen, fix undefined $response in log_return` — CV2

---

## Phase 3 — Refactor Endpoints

### TASK-P3-1 · Refactor `read.php`

- `require_once 'util_entry.php'; require_once 'util_file.php';` after `require 'util.php'`.
- Replace inline cache logic with `isCacheValid`, `readCache`, `writeCache`, `markCacheOutdated`.
- Replace `sortCsvData()` with `sortAndDeduplicateCsv()`.
- Remove now-dead local functions.  
**Commit:** `refactor(read): use util_file + util_entry helpers` — CV2

---

### TASK-P3-2 · Refactor `infopedia.php`

- Keep `$type = "web"` (done in P2-3).
- `require_once 'util_file.php'; require_once 'util_entry.php';`
- Replace `downloadAndCacheGoogleSheet()` + `isCacheValid()` with `util_file.php` fns.
- Replace `parseData()` inline CSV logic with `parseEntryLine()`.  
**Commit:** `refactor(infopedia): use util_file + util_entry, remove local duplicates` — CV2

---

### TASK-P3-3 · Add raw-first to `upload.php` (CA14)

```php
// raw-first: persist before any processing so a re-run can replay (CA14)
$rawLog = $config['rawLog'] ?? 'data/upload_raw.log';
appendRaw($rawLog, date('Y-m-d H:i:s') . ',' . $data);
```
This line goes **before** the Google POST call.  
Add `rawLog = data/upload_raw.log` to `[general]` in `infopedia.cfg`.  
**Test:** verify `data/upload_raw.log` is written even when Google POST fails.  
**Commit:** `feat(upload): raw-first append before Google POST (CA14)` — CV2, CV1 MINOR

---

### TASK-P3-4 · Fix `statistic.php`

- Add `$type = "stat"; include_once 'util.php';` at top.
- Replace hardcoded `$logFile = 'infopedia.log'` with `$logFile = $config['logFile'] ?? 'infopedia.log';`.
- Add `log_return("statistic rendered")` at end.  
**Commit:** `fix(statistic): use util.php config for logFile (CC2, CC3)` — CV2

---

## Phase 4 — Regression & Compatibility Verification

### TASK-P4-1 · Full test suite

```powershell
D:\_progs\xampp\php\php.exe util_test.php
D:\_progs\xampp\php\php.exe util_entry_test.php
D:\_progs\xampp\php\php.exe util_file_test.php
```
All PASS, exit 0. ✓

### TASK-P4-2 · Format contract spot-checks (CD3)

```
/entry/get?sid=tst&tid=tst&force_update=1          → valid CSV
/entry/get?sid=tst&tid=tst&format=txt.0.2          → txt.0.2 shape
/entry/get?sid=tst&tid=tst&format=json.0.3         → valid JSON
```
No new fields, no removed fields. ✓

### TASK-P4-3 · Tenant file integrity (CD1, CD2)

Check `data/entries_tst.csv`, `data/entries_tst.cache` unmodified before/after.

---

## Merge Gate Checklist (S6)

- [ ] `REQ-S6-1` All bug-fix commits have a prior-RED test that is now GREEN
- [ ] `REQ-S6-2` All commits follow `type(scope): summary` Conventional Commits
- [ ] `REQ-S6-3` SemVer bumped: PATCH (bug fixes) + MINOR (new util helpers + raw-first feat)
- [ ] `REQ-S6-4` `AGENTS.md` + `copilot-instructions.md` updated if architecture changed
- [ ] `REQ-S6-5` Full test suite green, worktree clean
- [ ] `REQ-S6-6` Change reviewed against CP1–CP3, CC1–CC5, CD1–CD4

