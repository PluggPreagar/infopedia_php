# Statistic Filter Bar — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a server-side global filter bar to `statistic.html` and the supporting PHP backend (`parse_filter`, `apply_filter`, `data_stats_respond` extension, `data.php` wiring), plus a client-side view sub-filter for log level.

**Architecture:** Filter params use a namespaced `f[key]=value` scheme — server validates and applies before aggregation. Filtered mode bypasses the byte-offset cache and always full-scans. Frontend uses design system components (`.filter-bar`, `.chip-filter`, `.subfilter-bar`, `.live-dot`) already in `components.css`.

**Tech Stack:** PHP 8.1+ procedural, vanilla JS (ES5-compatible), InfoPedia design system (`components.css` / `design-tokens.css`).

> **Branch base:** `feature/statistic-filter-bar` is rebased directly onto `feature/data-channel`. All prerequisite files (`util_data.php`, `data.php`, `statistic.html`, `test/util_data_test.php`) are already present in the working tree.

## Global Constraints

- CP1: No classes, no framework, no Composer in PHP — plain procedural only
- CP2: No new PHP route files — `data.php` is the only stats endpoint
- CA19: Global filter always BE-side; view sub-filter on detail data → client-side only; view sub-filter on aggregated data → BE-side (v1 has no aggregated sub-filter)
- Filter keys: `f[type]` comma-sep exact (row.type), `f[tid]` regex (row.tenant), `f[uri]` regex (row.uri), `f[method]` comma-sep exact (row.method)
- `apply_filter` receives the pre-validated filter from `parse_filter` — no regex validation inside `apply_filter`
- Filtered `data_stats_respond`: bypass cache, scan from offset 0, apply filter per-line, do NOT write cache, ignore `$client_offset`
- Filtered poll loop: never pass `offset` cursor to server; every poll is a fresh full scan
- Design system classes to use: `.chip-filter`, `.chip-group`, `.filter-bar[.open]`, `.filter-bar-body-inner`, `.filter-group`, `.filter-label`, `.filter-input`, `.filter-pills`, `.filter-pill`, `.filter-pill-dismiss`, `.subfilter-bar`, `.live-dot`, `.live-dot--amber`, `.input--invalid` — all defined in `components.css`
- Run `just lint` after every PHP file change; run `just unit` after every test change — all must pass before commit

---

### Task 1: CA19 constitution amendment + log_requests config

**Files:**
- Modify: `.ai/constitution.md`
- Modify: `infopedia.cfg`

**Interfaces:**
- Produces: nothing code-level; these are consumed as documentation by all later tasks

- [ ] **Step 1: Bump constitution version and add CA19**

In `.ai/constitution.md`, update the SYNC IMPACT REPORT header — change:

```
- Version: 1.9.0 -> 1.10.0  (add CG-DS1–CG-DS5: design-system guards)
- Numbering: CA1-CA18 (CA15-CA17 added 1.8.0; CA18 added 1.9.0), CC1-CC5 ...
```

to:

```
- Version: 1.10.0 -> 1.11.0  (add CA19: filter source contract)
- Numbering: CA1-CA19 (CA15-CA17 added 1.8.0; CA18 added 1.9.0; CA19 added 1.11.0), CC1-CC5 ...
```

Then find the line `- **CA14 -- Raw-first, replayable ingestion:**` in the Core Assumptions section and insert this block immediately before it:

```
- **CA19 -- Filter Source Contract:** Three tiers, unambiguous placement.
  **(1) Global filter** — always server-side; backend applies before aggregating and returns
  filtered data. The only path to accurate filtered aggregates over full log history.
  **(2) View sub-filter on detail data** (rows, individual records already in memory) —
  client-side, no round-trip.
  **(3) View sub-filter on aggregated data** (charts, totals computed from history) —
  server-side; backend returns a separately filtered aggregate. A client filtering
  aggregated data from a bounded row buffer is an accuracy violation of this principle.
  *Rationale: aggregates over full history cannot be accurately derived from a bounded
  client buffer. Detail data is already memory-resident — a server round-trip is waste.*

```

- [ ] **Step 2: Add log_requests to infopedia.cfg**

In `infopedia.cfg`, find the `[data]` section (added by the data-channel prerequisite) and add `log_requests = false`:

```ini
[data]
poll_timeout        = 25
log_viewer_max      = 50
ops_rotation_hours  = 3
log_requests        = false
```

- [ ] **Step 3: Lint and commit**

```bash
just lint
git add .ai/constitution.md infopedia.cfg
git commit -m "docs(constitution): CA19 — filter source contract, v1.11.0; cfg: log_requests default false"
```

Expected: `just lint` exits 0.

---

### Task 2: parse_filter() + apply_filter() + unit tests

**Files:**
- Modify: `util_data.php`
- Modify: `test/util_data_test.php`

**Interfaces:**
- Consumes: nothing external
- Produces:
  - `parse_filter(array $f): array` — validates `$_GET['f']`; returns `['valid'=>bool, 'bad_key'=>string|null, 'filter'=>array]`; `filter['type']` and `filter['method']` are `array<string>`, `filter['tid']` and `filter['uri']` are `string`
  - `apply_filter(array $row, array $filter): bool` — AND-combines all active criteria; receives pre-validated filter; pure function

- [ ] **Step 1: Write parse_filter tests (RED)**

In `test/util_data_test.php`, before the final `test_summary();` call, add:

```php
// ─── parse_filter ─────────────────────────────────────────────────────────────

// T25: valid type — comma-split, trimmed, stored as array
$pf = parse_filter(['type' => 'entries,votes']);
assert_eq(true,               $pf['valid'],             'T25: type valid');
assert_eq(null,               $pf['bad_key'],           'T25: no bad_key');
assert_eq(['entries','votes'], $pf['filter']['type'],   'T25: type as array');

// T26: valid method — comma-split, trimmed
$pf = parse_filter(['method' => 'GET, POST']);
assert_eq(true,           $pf['valid'],              'T26: method valid');
assert_eq(['GET','POST'], $pf['filter']['method'],   'T26: method as array');

// T27: valid regex — plain string is a valid pattern
$pf = parse_filter(['tid' => 'demo', 'uri' => '^/science']);
assert_eq(true,        $pf['valid'],            'T27: regex valid');
assert_eq('demo',      $pf['filter']['tid'],    'T27: tid stored');
assert_eq('^/science', $pf['filter']['uri'],    'T27: uri stored');

// T28: invalid regex in f[uri]
$pf = parse_filter(['uri' => '[bad(']);
assert_eq(false, $pf['valid'],    'T28: invalid regex fails');
assert_eq('uri', $pf['bad_key'], 'T28: bad_key=uri');

// T29: invalid regex in f[tid]
$pf = parse_filter(['tid' => '(unclosed']);
assert_eq(false, $pf['valid'],    'T29: invalid tid fails');
assert_eq('tid', $pf['bad_key'], 'T29: bad_key=tid');

// T30: empty values discarded from comma-split
$pf = parse_filter(['type' => 'entries,,votes,']);
assert_eq(['entries','votes'], $pf['filter']['type'], 'T30: empty values discarded');

// T31: empty filter — all clear
$pf = parse_filter([]);
assert_eq(true, $pf['valid'],                  'T31: empty valid');
assert_eq([],   $pf['filter']['type']   ?? [], 'T31: type empty');
assert_eq([],   $pf['filter']['method'] ?? [], 'T31: method empty');
assert_eq('',   $pf['filter']['tid']    ?? '', 'T31: tid empty');
assert_eq('',   $pf['filter']['uri']    ?? '', 'T31: uri empty');
```

- [ ] **Step 2: Run — verify FAIL**

```bash
php test/util_data_test.php
```

Expected: FAIL — "Call to undefined function parse_filter()"

- [ ] **Step 3: Implement parse_filter in util_data.php**

Add a new "Filter helpers" section in `util_data.php` after the `merge_stats_chunk` function (before the cache helpers):

```php
// ─── Filter helpers ───────────────────────────────────────────────────────────

function parse_filter(array $f): array {
    $out      = ['type' => [], 'method' => [], 'tid' => '', 'uri' => ''];
    $csv_keys = ['type', 'method'];
    $re_keys  = ['tid', 'uri'];

    foreach ($csv_keys as $k) {
        if (isset($f[$k]) && $f[$k] !== '') {
            $out[$k] = array_values(array_filter(
                array_map('trim', explode(',', (string)$f[$k])),
                fn($v) => $v !== ''
            ));
        }
    }

    foreach ($re_keys as $k) {
        if (isset($f[$k]) && $f[$k] !== '') {
            $v = (string)$f[$k];
            if (@preg_match('/' . addcslashes($v, '/') . '/', '') === false) {
                return ['valid' => false, 'bad_key' => $k, 'filter' => []];
            }
            $out[$k] = $v;
        }
    }

    return ['valid' => true, 'bad_key' => null, 'filter' => $out];
}
```

- [ ] **Step 4: Run — verify T25–T31 PASS**

```bash
php test/util_data_test.php
```

Expected: T25–T31 pass; all earlier tests still pass.

- [ ] **Step 5: Write apply_filter tests (RED)**

In `test/util_data_test.php`, after the parse_filter tests and before `test_summary()`:

```php
// ─── apply_filter ─────────────────────────────────────────────────────────────

$af_row = [
    'timestamp' => '2026-06-27 10:00:00', 'type' => 'entries',
    'uri' => '/entries?tid=demo', 'method' => 'GET',
    'session' => 'abc', 'tenant' => 'demo',
    'details' => 'RETURN: ok in 0.045 seconds', 'level' => 'RETURN', 'ms' => 45.0
];

// T32: empty filter — all rows pass
assert_eq(true, apply_filter($af_row, []), 'T32: empty filter passes all');

// T33: type in list → included
$filt = parse_filter(['type' => 'entries,votes'])['filter'];
assert_eq(true, apply_filter($af_row, $filt), 'T33: type match included');

// T34: type not in list → excluded
$filt = parse_filter(['type' => 'votes'])['filter'];
assert_eq(false, apply_filter($af_row, $filt), 'T34: type mismatch excluded');

// T35: method match
$filt = parse_filter(['method' => 'GET'])['filter'];
assert_eq(true, apply_filter($af_row, $filt), 'T35: method GET included');

// T36: method mismatch
$filt = parse_filter(['method' => 'POST'])['filter'];
assert_eq(false, apply_filter($af_row, $filt), 'T36: method POST excluded');

// T37: tid regex match
$filt = parse_filter(['tid' => 'dem'])['filter'];
assert_eq(true, apply_filter($af_row, $filt), 'T37: tid regex matches demo');

// T38: tid regex no match
$filt = parse_filter(['tid' => '^prod'])['filter'];
assert_eq(false, apply_filter($af_row, $filt), 'T38: tid regex ^prod no match');

// T39: uri regex match
$filt = parse_filter(['uri' => '^/entries'])['filter'];
assert_eq(true, apply_filter($af_row, $filt), 'T39: uri regex match');

// T40: uri regex no match
$filt = parse_filter(['uri' => '^/votes'])['filter'];
assert_eq(false, apply_filter($af_row, $filt), 'T40: uri regex no match');

// T41: multi-criteria AND — all match
$filt = parse_filter(['type' => 'entries', 'method' => 'GET', 'tid' => 'demo'])['filter'];
assert_eq(true, apply_filter($af_row, $filt), 'T41: multi-criteria all match');

// T42: multi-criteria AND — one fails
$filt = parse_filter(['type' => 'entries', 'method' => 'POST'])['filter'];
assert_eq(false, apply_filter($af_row, $filt), 'T42: multi-criteria one fails');
```

- [ ] **Step 6: Run — verify FAIL**

```bash
php test/util_data_test.php
```

Expected: FAIL — "Call to undefined function apply_filter()"

- [ ] **Step 7: Implement apply_filter in util_data.php**

Add immediately after `parse_filter`:

```php
function apply_filter(array $row, array $filter): bool {
    if (!empty($filter['type'])) {
        if (!in_array($row['type'], $filter['type'], true)) return false;
    }
    if (!empty($filter['method'])) {
        if (!in_array($row['method'], $filter['method'], true)) return false;
    }
    if (!empty($filter['tid'])) {
        if (!preg_match('/' . $filter['tid'] . '/i', $row['tenant'])) return false;
    }
    if (!empty($filter['uri'])) {
        if (!preg_match('/' . $filter['uri'] . '/i', $row['uri'])) return false;
    }
    return true;
}
```

- [ ] **Step 8: Run all — verify all PASS**

```bash
php test/util_data_test.php
```

Expected: OK — all tests pass (T1–T42).

- [ ] **Step 9: Commit**

```bash
just lint
git add util_data.php test/util_data_test.php
git commit -m "feat(data): parse_filter() + apply_filter() + unit tests T25–T42"
```

---

### Task 3: data_stats_respond() — add $filter param + filtered mode

**Files:**
- Modify: `util_data.php`
- Modify: `test/util_data_test.php`

**Interfaces:**
- Consumes: `parse_filter()`, `apply_filter()` from Task 2
- Produces: `data_stats_respond(string $logFile, string $cacheFile, ?int $client_offset, int $log_viewer_max, array $filter = []): array` — when `$filter` non-empty: reads from offset 0, applies `apply_filter` per line, skips `load_stats_cache`/`save_stats_cache`, ignores `$client_offset`, response shape unchanged

- [ ] **Step 1: Write failing test T43**

In `test/util_data_test.php`, before `test_summary()`:

```php
// ─── data_stats_respond with filter ───────────────────────────────────────────

// T43: filtered mode — only matching rows returned; cache not written
$filt_log = tempnam(sys_get_temp_dir(), 'fstat_');
file_put_contents($filt_log, implode("\n", [
    '[2026-06-27 10:00:00] ;  entries ;  /entries?tid=demo ;  GET ;  abc@demo ;  entries.php ;  RETURN: ok in 0.050 seconds',
    '[2026-06-27 10:01:00] ;  votes   ;  /votes?tid=demo   ;  GET ;  abc@demo ;  votes.php   ;  RETURN: ok in 0.020 seconds',
    '[2026-06-27 10:02:00] ;  entries ;  /entries?tid=test ;  POST ;  xyz@test ;  entries.php ;  RETURN: ok in 0.080 seconds',
]) . "\n");

$fv   = parse_filter(['type' => 'entries']);
$resp = data_stats_respond($filt_log, '/dev/null', null, 50, $fv['filter']);

assert_eq(false,     $resp['stale'] ?? false,                  'T43: not stale');
assert_eq(2,         $resp['increments']['requests'] ?? 0,     'T43: 2 entries requests');
assert_eq(2,         count($resp['increments']['rows'] ?? []), 'T43: 2 rows returned');
assert_eq('entries', $resp['increments']['rows'][0]['type'] ?? null, 'T43: row type=entries');

unlink($filt_log);
```

- [ ] **Step 2: Run — verify FAIL**

```bash
php test/util_data_test.php
```

Expected: FAIL — `data_stats_respond` does not accept a 5th argument (fatal error).

- [ ] **Step 3: Replace data_stats_respond in util_data.php**

Replace the entire existing `data_stats_respond` function with the following (the only changes from the original are: added `array $filter = []` param, added `$filtered` flag, guarded stale check and cache calls, added `apply_filter` in the read loop):

```php
function data_stats_respond(string $logFile, string $cacheFile,
                            ?int $client_offset, int $log_viewer_max,
                            array $filter = []): array {
    clearstatcache(true, $logFile);
    $file_size = file_exists($logFile) ? filesize($logFile) : 0;
    $filtered  = !empty($filter);

    // Stale offset check (unfiltered only — filtered mode never provides a cursor)
    if (!$filtered && $client_offset !== null && $client_offset > $file_size) {
        return ['stale' => true];
    }

    // Load or init aggregate
    if ($filtered) {
        $agg         = empty_stats_agg();
        $from_offset = 0;
    } else {
        $cached      = load_stats_cache($cacheFile, $logFile);
        $agg         = $cached ? $cached['agg'] : empty_stats_agg();
        $from_offset = $cached ? $cached['offset'] : 0;
    }

    // Read new lines; in filtered mode apply filter per-line
    $new_lines = [];
    if ($from_offset < $file_size) {
        $fp = fopen($logFile, 'r');
        if ($fp) {
            fseek($fp, $from_offset);
            while (($raw = fgets($fp)) !== false) {
                $r = parse_log_line(trim($raw));
                if ($r !== null) {
                    if (!$filtered || apply_filter($r, $filter)) $new_lines[] = $r;
                }
            }
            fclose($fp);
        }
    }

    // Init timeline params on first ever build
    if ($agg['tl_min_ts'] === null && !empty($new_lines)) {
        $ts_vals = array_filter(array_map(
            fn($r) => $r['level'] === 'RETURN' ? strtotime($r['timestamp']) : null,
            $new_lines));
        if ($ts_vals) {
            $tl_min   = min($ts_vals);
            $tl_range = max($ts_vals) - $tl_min;
            if      ($tl_range < 7200)   { $tl_bucket = 300;   $tl_label = '5-min buckets'; }
            elseif  ($tl_range < 86400)  { $tl_bucket = 900;   $tl_label = '15-min buckets'; }
            elseif  ($tl_range < 604800) { $tl_bucket = 3600;  $tl_label = '1h buckets'; }
            else                         { $tl_bucket = 86400; $tl_label = '1-day buckets'; }
            $agg['tl_min_ts'] = $tl_min;
            $agg['tl_bucket'] = $tl_bucket;
            $agg['tl_label']  = $tl_label;
        }
    }

    // Merge; skip cache write in filtered mode
    if (!empty($new_lines)) {
        $agg = merge_stats_chunk($agg, $new_lines);
        if (!$filtered) save_stats_cache($cacheFile, $logFile, $file_size, $agg);
    }

    // Compute increments (what changed this cycle)
    $return_lines   = array_filter($new_lines, fn($r) => $r['level'] === 'RETURN');
    $rows_truncated = false;
    $rows           = array_values($return_lines);
    if ($from_offset === 0 && count($rows) > $log_viewer_max) {
        $rows = array_slice($rows, -$log_viewer_max);
        $rows_truncated = true;
    }

    $inc_requests = 0; $inc_errors = 0; $inc_warnings = 0;
    $inc_by_hour  = []; $inc_rt = []; $inc_by_type = []; $inc_timeline = [];
    foreach ($new_lines as $r) {
        if ($r['level'] === 'ERROR') {
            $inc_errors++;
            $t = $r['type'];
            $inc_by_type[$t] ??= ['get'=>0,'post'=>0,'errors'=>0,'times_sum'=>0.0,'times_count'=>0,'max_ms'=>0.0];
            $inc_by_type[$t]['errors']++;
        }
        if ($r['level'] === 'WARNING') { $inc_warnings++; }
        if ($r['level'] === 'RETURN') {
            $inc_requests++;
            $t = $r['type'];
            $inc_by_type[$t] ??= ['get'=>0,'post'=>0,'errors'=>0,'times_sum'=>0.0,'times_count'=>0,'max_ms'=>0.0];
            $r['method'] === 'GET' ? $inc_by_type[$t]['get']++ : $inc_by_type[$t]['post']++;
            if ($r['ms'] !== null) {
                $ms = $r['ms'];
                $inc_by_type[$t]['times_sum']  += $ms;
                $inc_by_type[$t]['times_count']++;
                $inc_by_type[$t]['max_ms'] = max($inc_by_type[$t]['max_ms'], $ms);
            }
            if (preg_match('/ (\d{2}):\d{2}:\d{2}/', $r['timestamp'], $m))
                $inc_by_hour[(int)$m[1]] = ($inc_by_hour[(int)$m[1]] ?? 0) + 1;
            if ($r['ms'] !== null) {
                $ms = $r['ms'];
                $bk = $ms < 1 ? '<1ms' : ($ms < 10 ? '1-10ms' : ($ms < 100 ? '10-100ms' : ($ms < 1000 ? '100ms-1s' : '>1s')));
                $inc_rt[$bk] = ($inc_rt[$bk] ?? 0) + 1;
            }
            if ($agg['tl_min_ts'] !== null && $agg['tl_bucket'] > 0) {
                $ts = strtotime($r['timestamp']);
                if ($ts !== false) {
                    $idx = (int)floor(($ts - $agg['tl_min_ts']) / $agg['tl_bucket']);
                    if ($idx >= 0) $inc_timeline[$idx] = ($inc_timeline[$idx] ?? 0) + 1;
                }
            }
        }
    }

    return [
        'entity' => 'stats',
        'offset' => $file_size,
        'full'   => [
            'sessions_uniq'  => count(array_unique($agg['sessions'])),
            'tenants_uniq'   => count(array_filter(array_unique($agg['tenants']))),
            'avg_ms'         => $agg['times_count'] > 0
                                    ? round($agg['times_sum'] / $agg['times_count'], 2)
                                    : 0.0,
            'max_ms'         => round($agg['max_ms'], 2),
            'first_ts'       => $agg['first_ts'],
            'last_ts'        => $agg['last_ts'],
            'tl_min_ts'      => $agg['tl_min_ts'],
            'tl_bucket'      => $agg['tl_bucket'],
            'tl_label'       => $agg['tl_label'],
            'rows_truncated' => $rows_truncated,
        ],
        'increments' => [
            'requests' => $inc_requests,
            'errors'   => $inc_errors,
            'warnings' => $inc_warnings,
            'by_hour'  => $inc_by_hour,
            'rt'       => $inc_rt,
            'by_type'  => $inc_by_type,
            'timeline' => $inc_timeline,
            'rows'     => $rows,
        ],
    ];
}
```

- [ ] **Step 4: Run all — verify PASS**

```bash
php test/util_data_test.php
```

Expected: OK — all tests pass including T43.

- [ ] **Step 5: Commit**

```bash
just lint
git add util_data.php test/util_data_test.php
git commit -m "feat(data): data_stats_respond — \$filter param, bypass cache in filtered mode (T43)"
```

---

### Task 4: data.php — wire filter reading + conditional log_return

**Files:**
- Modify: `data.php`

**Interfaces:**
- Consumes: `parse_filter()` from Task 2; `data_stats_respond(..., $filter)` from Task 3; `$config['log_requests']` from `infopedia.cfg` Task 1
- Produces: `GET /data?entity=stats&f[type]=entries&f[tid]=demo` returns filtered stats; malformed regex → `400 INVALID_FILTER`; successful data requests are not logged when `log_requests = false`

- [ ] **Step 1: Add filter reading after entity validation**

In `data.php`, after the `$allowed` / entity validation block and before the `$poll_timeout` line, insert:

```php
// Read and validate filter (f[key]=value — any combination)
$filter_raw = is_array($_GET['f'] ?? null) ? $_GET['f'] : [];
$fv = parse_filter($filter_raw);
if (!$fv['valid']) {
    respond_error('INVALID_FILTER',
        'Invalid regex in filter[' . $fv['bad_key'] . ']', 400);
}
$filter = $fv['filter'];
```

- [ ] **Step 2: Pass $filter to data_stats_respond**

In the `if ($entity === 'stats')` block, change:

```php
$resp = data_stats_respond($logFile, 'data/stats_aggregate.cache',
                           $client_offset, $log_viewer_max);
```

to:

```php
$resp = data_stats_respond($logFile, 'data/stats_aggregate.cache',
                           $client_offset, $log_viewer_max, $filter);
```

- [ ] **Step 3: Make log_return conditional for stats**

In the `if ($entity === 'stats')` block, replace:

```php
log_return('data/stats: offset=' . ($client_offset ?? 'first') . ' → ' . $resp['offset']);
```

with:

```php
if (!empty($config['log_requests'])) {
    log_return('data/stats: offset=' . ($client_offset ?? 'first') . ' → ' . $resp['offset']);
}
```

- [ ] **Step 4: Make log_return conditional for ops**

In the `if ($entity === 'ops')` block, replace:

```php
log_return('data/ops: ' . count($resp['increments']['rows']) . ' message(s)');
```

with:

```php
if (!empty($config['log_requests'])) {
    log_return('data/ops: ' . count($resp['increments']['rows']) . ' message(s)');
}
```

- [ ] **Step 5: Lint + unit tests**

```bash
just lint && just unit
```

Expected: lint exits 0; all unit tests pass (data.php has no unit tests — this confirms no regressions).

- [ ] **Step 6: Commit**

```bash
git add data.php
git commit -m "feat(data): wire f[key] filter params + INVALID_FILTER 400; conditional log_return"
```

---

### Task 5: statistic.html — filter bar UI + JS

**Files:**
- Modify: `statistic.html`

**Interfaces:**
- Consumes: `GET /data?entity=stats&f[type]=X&f[tid]=Y&f[uri]=Z&f[method]=W` from Task 4; design system classes from `components.css`
- Produces: working filter bar with chip toggles, regex inputs, debounced filter dispatch, filtered mode indicator, view sub-filter for level

- [ ] **Step 1: Migrate status-dot to live-dot**

In the inline `<style>` block, remove the three rules:

```css
.status-dot { display:inline-block; width:8px; height:8px; border-radius:50%;
              margin-right:var(--space-2); background:var(--color-neutral-300); }
.status-dot.live { background:#22c55e; animation:pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
```

and add in their place:

```css
.live-dot--live { animation:live-pulse 2s infinite; }
@keyframes live-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
```

In the `<nav>` HTML, change:

```html
<span id="live-dot" class="status-dot" title="Live updates"></span>
```

to:

```html
<span id="live-dot" class="live-dot" style="margin-right:var(--space-2)" title="Live updates"></span>
```

- [ ] **Step 2: Add filter bar HTML**

After `</nav>` and before `<div class="page">`, insert:

```html
<div class="filter-bar" id="filter-bar">
  <div class="filter-bar-summary">
    <button class="btn btn-ghost" id="filter-toggle"
            style="min-height:36px;font-size:var(--text-sm);padding:var(--space-1) var(--space-3)">Filter</button>
    <div class="filter-pills" id="filter-pills"></div>
    <button class="btn btn-ghost" id="filter-clear"
            style="display:none;min-height:36px;font-size:var(--text-sm);padding:var(--space-1) var(--space-3);color:var(--color-neutral-600)">× Clear</button>
  </div>
  <div class="filter-bar-body">
    <div class="filter-bar-body-inner">
      <div class="filter-group">
        <span class="filter-label">Type</span>
        <div class="chip-group" id="fg-type">
          <button class="chip chip-filter" data-val="entries">entries</button>
          <button class="chip chip-filter" data-val="votes">votes</button>
          <button class="chip chip-filter" data-val="health">health</button>
        </div>
      </div>
      <div class="filter-group">
        <span class="filter-label">Method</span>
        <div class="chip-group" id="fg-method">
          <button class="chip chip-filter" data-val="GET">GET</button>
          <button class="chip chip-filter" data-val="POST">POST</button>
        </div>
      </div>
      <div class="filter-group">
        <label class="filter-label" for="fg-tid">Tenant</label>
        <input class="input filter-input" id="fg-tid" placeholder="regex…">
      </div>
      <div class="filter-group">
        <label class="filter-label" for="fg-uri">URI</label>
        <input class="input filter-input" id="fg-uri" placeholder="regex…">
      </div>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Add level sub-filter bar HTML**

Inside the `<!-- Log viewer -->` section, after `<h2>Recent Requests</h2>` and before the `<p class="dim" id="rows-truncated-note"...>` element, insert:

```html
<div class="subfilter-bar">
  <span class="filter-label">Level</span>
  <div class="chip-group" id="vf-level">
    <button class="chip chip-filter active" data-val="">All</button>
    <button class="chip chip-filter" data-val="ERROR">ERROR</button>
    <button class="chip chip-filter" data-val="WARNING">WARNING</button>
    <button class="chip chip-filter" data-val="RETURN">RETURN</button>
  </div>
</div>
```

- [ ] **Step 4: Add filter state + utilities to JS**

At the top of the IIFE (right after `'use strict';`), before the `var state = {...}` block, add:

```js
// ── Filter state ──────────────────────────────────────────────────────────────
var gFilter = {
    type:   [],  // string[] — f[type] — row.type exact match
    tid:    '',  // string   — f[tid] regex — row.tenant
    uri:    '',  // string   — f[uri] regex — row.uri
    method: []   // string[] — f[method] — row.method exact match
};

var vFilter = {
    level: ''    // 'ERROR'|'WARNING'|'RETURN'|'' — client-only, no round-trip
};

function gFilterActive() {
    return gFilter.type.length > 0 || gFilter.tid !== '' ||
           gFilter.uri !== '' || gFilter.method.length > 0;
}

function isValidRegex(s) {
    if (!s) return true;
    try { new RegExp(s); return true; } catch(e) { return false; }
}

function buildFilterParams() {
    var p = {};
    if (gFilter.type.length)   p['f[type]']   = gFilter.type.join(',');
    if (gFilter.method.length) p['f[method]'] = gFilter.method.join(',');
    if (gFilter.tid)           p['f[tid]']    = gFilter.tid;
    if (gFilter.uri)           p['f[uri]']    = gFilter.uri;
    return p;
}

function filteredRows() {
    return state.rows.filter(function(r) {
        if (vFilter.level && r.level !== vFilter.level) return false;
        return true;
    });
}
```

- [ ] **Step 5: Add updateLiveDot + filter bar interaction handlers**

After `filteredRows`, add:

```js
// ── Live dot ──────────────────────────────────────────────────────────────────
function updateLiveDot() {
    var dot = document.getElementById('live-dot');
    dot.className = 'live-dot live-dot--live' + (gFilterActive() ? ' live-dot--amber' : '');
}

// ── Filter bar ────────────────────────────────────────────────────────────────
var _filterDebounce = null;

function onFilterChange() {
    state.requests = 0; state.errors = 0; state.warnings = 0;
    state.by_hour = Array(24).fill(0);
    state.rt = { '<1ms':0,'1-10ms':0,'10-100ms':0,'100ms-1s':0,'>1s':0 };
    state.by_type = {};
    state.timeline = {};
    state.rows = [];
    updateFilterPills();
    updateLiveDot();
    renderStats();
    pollStats();   // filtered mode — no cursor passed
}

function updateFilterPills() {
    var pills = document.getElementById('filter-pills');
    var clear = document.getElementById('filter-clear');
    pills.innerHTML = '';
    var active = gFilterActive();
    clear.style.display = active ? '' : 'none';
    if (!active) return;

    function pill(label, onDismiss) {
        var sp = document.createElement('span');
        sp.className = 'filter-pill';
        sp.textContent = label + ' ';
        var btn = document.createElement('button');
        btn.className = 'filter-pill-dismiss';
        btn.setAttribute('aria-label', 'Remove filter');
        btn.textContent = '×';
        btn.addEventListener('click', onDismiss);
        sp.appendChild(btn);
        pills.appendChild(sp);
    }

    if (gFilter.type.length)
        pill('type: ' + gFilter.type.join(','), function() { gFilter.type = []; syncChips('fg-type', gFilter.type); onFilterChange(); });
    if (gFilter.method.length)
        pill('method: ' + gFilter.method.join(','), function() { gFilter.method = []; syncChips('fg-method', gFilter.method); onFilterChange(); });
    if (gFilter.tid)
        pill('tid: ' + gFilter.tid, function() { gFilter.tid = ''; document.getElementById('fg-tid').value = ''; onFilterChange(); });
    if (gFilter.uri)
        pill('uri: ' + gFilter.uri, function() { gFilter.uri = ''; document.getElementById('fg-uri').value = ''; onFilterChange(); });
}

function syncChips(groupId, activeValues) {
    document.querySelectorAll('#' + groupId + ' .chip-filter').forEach(function(btn) {
        btn.classList.toggle('active', activeValues.indexOf(btn.dataset.val) !== -1);
    });
}

// Filter toggle open/close
document.getElementById('filter-toggle').addEventListener('click', function() {
    var fb = document.getElementById('filter-bar');
    fb.classList.toggle('open');
    this.textContent = fb.classList.contains('open') ? 'Filter ▲' : 'Filter';
});

// Clear all
document.getElementById('filter-clear').addEventListener('click', function() {
    gFilter.type = []; gFilter.tid = ''; gFilter.uri = ''; gFilter.method = [];
    syncChips('fg-type',   gFilter.type);
    syncChips('fg-method', gFilter.method);
    document.getElementById('fg-tid').value = '';
    document.getElementById('fg-uri').value = '';
    onFilterChange();
});

// Type chips
document.querySelectorAll('#fg-type .chip-filter').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var v = this.dataset.val;
        var idx = gFilter.type.indexOf(v);
        if (idx === -1) gFilter.type.push(v); else gFilter.type.splice(idx, 1);
        this.classList.toggle('active', idx === -1);
        onFilterChange();
    });
});

// Method chips
document.querySelectorAll('#fg-method .chip-filter').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var v = this.dataset.val;
        var idx = gFilter.method.indexOf(v);
        if (idx === -1) gFilter.method.push(v); else gFilter.method.splice(idx, 1);
        this.classList.toggle('active', idx === -1);
        onFilterChange();
    });
});

// Text inputs: debounce + live regex validation
['fg-tid', 'fg-uri'].forEach(function(id) {
    document.getElementById(id).addEventListener('input', function() {
        var v = this.value.trim();
        var valid = isValidRegex(v);
        this.classList.toggle('input--invalid', !valid);
        if (!valid) return;
        var key = id === 'fg-tid' ? 'tid' : 'uri';
        clearTimeout(_filterDebounce);
        _filterDebounce = setTimeout(function() { gFilter[key] = v; onFilterChange(); }, 400);
    });
});

// Level sub-filter (client-side only — no server round-trip)
document.querySelectorAll('#vf-level .chip-filter').forEach(function(btn) {
    btn.addEventListener('click', function() {
        vFilter.level = this.dataset.val;
        document.querySelectorAll('#vf-level .chip-filter').forEach(function(b) {
            b.classList.toggle('active', b === btn);
        });
        renderStats();
    });
});
```

- [ ] **Step 6: Modify pollStats for filtered mode**

Replace the existing `function pollStats(cursor)` with:

```js
function pollStats(cursor) {
    var filterParams = buildFilterParams();
    var filtered = Object.keys(filterParams).length > 0;
    // Filtered mode never passes cursor — server always full-scans from offset 0
    var params = Object.assign({ entity: 'stats' }, filtered ? {} : (cursor || {}), filterParams);
    fetch('data?' + new URLSearchParams(params))
        .then(function(res) {
            if (res.status === 200) {
                return res.json().then(function(body) {
                    applyFull(body.full || {});
                    applyIncrements(body.increments);
                    if (body.full && body.full.rows_truncated) {
                        document.getElementById('rows-truncated-note').style.display = '';
                        document.getElementById('rows-truncated-n').textContent =
                            (body.increments && body.increments.rows)
                                ? body.increments.rows.length : '?';
                    }
                    renderStats();
                    updateLiveDot();
                    // Filtered: no cursor advance; unfiltered: advance offset
                    if (filtered) { pollStats(); } else { pollStats({ offset: body.offset }); }
                });
            } else if (res.status === 204) {
                pollStats(cursor);
            } else {
                state.requests = 0; state.errors = 0; state.warnings = 0;
                state.by_hour = Array(24).fill(0);
                state.rt = { '<1ms':0,'1-10ms':0,'10-100ms':0,'100ms-1s':0,'>1s':0 };
                state.by_type = {};
                state.timeline = {};
                state.rows = [];
                setTimeout(function() { pollStats(); }, 1000);
            }
        })
        .catch(function(err) {
            console.error('[statistic] stats poll error:', err);
            setTimeout(function() { pollStats(cursor); }, 5000);
        });
}
```

- [ ] **Step 7: Use filteredRows() in renderStats + update nav title**

In `renderStats()`, find the tbody rendering line for the log table. The original reads:

```js
rtbody.innerHTML = state.rows.map(function(r) {
```

Change it to:

```js
rtbody.innerHTML = filteredRows().map(function(r) {
```

At the end of `renderStats()`, add:

```js
document.querySelector('.nav-title').textContent =
    gFilterActive() ? 'Statistics (filtered)' : 'Statistics';
```

- [ ] **Step 8: Smoke test + commit**

Open `statistic.html` in a browser (via local server). Verify:
- Page loads, data fills, live-dot pulses green
- Click Filter → panel expands smoothly
- Click "entries" chip → pill appears in collapsed summary, dot turns amber, stats show only entries
- Type `demo` in Tenant → after ~400ms, stats update with tenant filter
- Type `[bad(` in URI → red border appears immediately; filter not sent
- Click ERROR in Level sub-filter → log table filters client-side instantly; no network request
- Click × Clear → all filters clear, green dot returns, full data resumes
- Close and reopen filter panel → state preserved

```bash
just lint
git add statistic.html
git commit -m "feat(statistic): filter bar — gFilter/vFilter, chip-filter, live-dot--amber, subfilter level"
```
