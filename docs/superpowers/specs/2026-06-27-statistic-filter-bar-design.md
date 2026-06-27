# Statistic Filter Bar — Design Spec

**Date:** 2026-06-27
**Branch target:** `feature/statistic-filter-bar` (from `dev`)
**Affects:** `statistic.html`, `data.php`, `util_data.php`, `infopedia.cfg`, `.ai/constitution.md`

---

## 1. Constitution Amendment — CA19

Version bumps from 1.10.0 → 1.11.0 (MINOR: new principle added).

> **CA19 — Filter Source Contract:** Three tiers, unambiguous placement.
> **(1) Global filter** — always server-side; backend applies before aggregating and returns filtered data. The only path to accurate filtered aggregates over full log history.
> **(2) View sub-filter on detail data** (rows, individual records already in memory) — client-side, no round-trip.
> **(3) View sub-filter on aggregated data** (charts, totals computed from history) — server-side; backend returns a separately filtered aggregate. A client filtering aggregated data from a bounded row buffer is an accuracy violation of this principle.
> *Rationale: aggregates over full history cannot be accurately derived from a bounded client buffer. Detail data is already memory-resident — a server round-trip is waste.*

Commit: `docs(constitution): CA19 — filter source contract, v1.11.0`

---

## 2. Filter Param Scheme

All filter criteria travel as a single namespaced array param `f[key]=value`. The server receives them as `$filter = $_GET['f'] ?? []` — one line, no individual param handling. Adding a new filter dimension is a new key; nothing else changes on either side.

### Defined keys (v1)

Filter keys align to the JSON field names in the data channel row response (`type`, `tenant`, `uri`, `method`). Each key filters the field of the same name in parsed log rows.

| Key | Row field | Type | Example |
|-----|-----------|------|---------|
| `f[type]` | `row.type` | comma-separated values | `f[type]=entries,votes` |
| `f[tenant]` | `row.tenant` | regex-capable string | `f[tenant]=demo` or `f[tenant]=demo.*` |
| `f[uri]` | `row.uri` | regex-capable string | `f[uri]=^/science` or `f[uri]=add$` |
| `f[method]` | `row.method` | comma-separated values | `f[method]=GET` or `f[method]=GET,POST` |

**`f[type]` and `f[method]` use comma-separation** (finite known sets). All other values are treated as regex patterns — a plain string like `demo` is a valid regex that matches literally.

> **Note on `tenant` vs `tid`:** `tid` is the existing codebase query param for tenant routing (ops entity, session identification). `tenant` is the JSON field name in log rows and in the data channel response. Filter keys use the row field names consistently — `f[tenant]` filters on `row.tenant`. The `?tid=` routing param is unrelated.

### Regex sanity check

`f[tenant]` and `f[uri]` values are validated as regex before use.

**Server:** `@preg_match('/' . addcslashes($v, '/') . '/', '')` — if the return value is `false`, the pattern is malformed. Respond `400 INVALID_FILTER` with body `{"error":{"code":"INVALID_FILTER","key":"<key>","message":"Invalid regex in filter"}}` before any log scan begins.

**Client:** `try { new RegExp(value) } catch(e) { /* mark invalid */ }` — live validation as the user types. Malformed regex → red border + tooltip "Invalid regular expression". Filter submission is blocked while any field is invalid.

### URL examples

With filter active:

```
data.php?entity=stats&f[type]=entries,votes&f[tenant]=demo&f[uri]=^%2Fscience
data.php?entity=stats&f[method]=GET&f[tenant]=demo.*
```

No filter active (normal load):

```
data.php?entity=stats
```

---

## 3. Request Logging Control

### Problem: self-triggering feedback loop

`statistic.html` polls `data.php` approximately every 25 s. Without protection, each poll appends a `RETURN:` line to `infopedia.log`. The stats aggregator reads that log → detects new bytes → returns an increment → the SPA updates → the next poll arrives. The observer disturbs the observed.

### Solution

Add to `infopedia.cfg` under `[data]`:

```ini
log_requests = false
```

`data.php` behaviour:

- `log_requests = false` (default) — skip `log_return()` on successful responses. Data channel requests are invisible to `infopedia.log`.
- `log_requests = true` — normal `log_return()` call (useful during development/debugging).
- **Errors always logged** — `log_error()` and `log_warn()` are unconditional regardless of this setting. The flag controls only success-path request logging.

---

## 4. Backend Changes

### 4.1 `infopedia.cfg`

Under `[data]` (extends existing section):

```ini
log_requests        = false
```

Existing keys (`poll_timeout`, `log_viewer_max`, `ops_rotation_hours`) unchanged.

### 4.2 `util_data.php` — two new pure functions

#### `parse_filter(array $f): array`

Validates filter input. Returns:

```php
[
  'valid'   => bool,
  'bad_key' => string|null,   // first offending key if invalid
  'filter'  => array,         // sanitised copy, ready to pass to apply_filter()
]
```

Rules:
- `f['type']`: split on comma, `trim()` each value, discard empty strings. No hardcoded type whitelist — unknown types produce empty results, not an error. Stored as an array in the returned `filter`.
- `f['method']`: split on comma, `trim()` each value, discard empty strings. Stored as an array.
- `f['tenant']`, `f['uri']`: validate as regex via `@preg_match('/' . addcslashes($v, '/') . '/', '')`. Invalid → `valid=false, bad_key=<key>`.
- Values are NOT further escaped — callers pass them directly to `preg_match` wrapped in `/…/i`.
- Return `filter['type']` and `filter['method']` as `array<string>`, `filter['tenant']` and `filter['uri']` as `string`.

#### `apply_filter(array $row, array $filter): bool`

Applies all active criteria to a single parsed log row. Returns `true` if the row passes (should be included), `false` if it should be excluded.

```php
function apply_filter(array $row, array $filter): bool {
    if (!empty($filter['type'])) {
        if (!in_array($row['type'], $filter['type'], true)) return false;
    }
    if (!empty($filter['method'])) {
        if (!in_array($row['method'], $filter['method'], true)) return false;
    }
    if (!empty($filter['tenant'])) {
        if (!preg_match('/' . $filter['tenant'] . '/i', $row['tenant'])) return false;
    }
    if (!empty($filter['uri'])) {
        if (!preg_match('/' . $filter['uri'] . '/i', $row['uri'])) return false;
    }
    return true;
}
```

`apply_filter` is pure and receives the already-validated filter from `parse_filter`. No regex validation inside — that is `parse_filter`'s responsibility. All criteria are AND-combined.

### 4.3 `util_data.php` — `data_stats_respond()` changes

Signature change:

```php
function data_stats_respond(
    string $logFile,
    string $cacheFile,
    ?int   $client_offset,
    int    $log_viewer_max,
    array  $filter = []        // NEW — empty = unfiltered (current behaviour)
): array
```

When `$filter` is non-empty:
- **Bypass aggregate cache** — do not call `load_stats_cache()` or `save_stats_cache()`. Always scan full log from offset 0.
- Apply `apply_filter($row, $filter)` to each parsed line before passing to `merge_stats_chunk()`.
- `$client_offset` is ignored in filtered mode (always `null` from client — see Section 5.3). No byte-offset cursor for filtered requests.
- Response shape unchanged — same `full` + `increments` envelope. `offset` field is still present but client does not use it for subsequent filtered requests.
- **Stale detection disabled in filtered mode** — `client_offset` is null, so no stale check applies.

When `$filter` is empty: existing behaviour unchanged (cache-backed, incremental).

### 4.4 `data.php` changes

```php
// Read and validate filter
$filter_raw = is_array($_GET['f'] ?? null) ? $_GET['f'] : [];
$fv = parse_filter($filter_raw);
if (!$fv['valid']) {
    respond_error('INVALID_FILTER',
        'Invalid regex in filter[' . $fv['bad_key'] . ']', 400);
}
$filter = $fv['filter'];
```

Pass `$filter` to `data_stats_respond()`. No other route logic changes.

**Logging control** — replace the unconditional `log_return()` call with:

```php
if (!empty($config['log_requests'])) {
    log_return('data/' . $entity . ': ...');
}
```

Errors keep calling `log_error()` / `log_warn()` unconditionally.

---

## 5. Frontend Changes — `statistic.html`

### 5.1 Filter state

Two objects, kept separate per CA19:

```js
// Global filter — sent to server on every poll
var gFilter = {
    type:   [],  // string[] — selected endpoint types (row.type)
    tenant: '',  // string   — tenant regex (row.tenant)
    uri:    '',  // string   — URI regex (row.uri)
    method: []   // string[] — selected methods, e.g. ['GET'] (row.method)
};

// View sub-filter — client-only, applied to state.rows on render
var vFilter = {
    level: ''    // 'ERROR' | 'WARNING' | 'RETURN' | '' (all)
};
```

### 5.2 Filter bar HTML

Sits between the `<nav>` and the summary strip. Collapsed by default (single "Filter" button). Expanding reveals:

```
Type: [ entries ] [ votes ] [ health ]   Method: [ GET ] [ POST ]   Tenant: [_____]   URI: [_____]   [× Clear]
```

Type and Method chips are toggleable (active = filled, inactive = outline). Tenant and URI fields are text inputs with live regex validation. Active filters are shown as dismissible chips on the collapsed bar so the filter state is visible without expanding.

### 5.3 Filter change → poll restart

```js
function buildFilterParams() {
    var p = {};
    if (gFilter.type.length)   p['f[type]']   = gFilter.type.join(',');
    if (gFilter.method.length) p['f[method]'] = gFilter.method.join(',');
    if (gFilter.tenant)        p['f[tenant]'] = gFilter.tenant;
    if (gFilter.uri)           p['f[uri]']    = gFilter.uri;
    return p;
}
```

When any `gFilter` key changes (after debounce of ~400 ms for text inputs):
1. Reset accumulators (`state.requests = 0`, etc. — same reset used for stale restart)
2. Call `pollStats()` **without cursor** — filtered mode never passes `offset` to the server; every poll is a fresh full scan

Filtered `pollStats()` loop: always calls `pollStats()` with no cursor on the next tick (not `{ offset: body.offset }`). The long-poll mtime watch still works — the server holds the connection until `infopedia.log` changes, then returns updated filtered results.

### 5.4 Client-side regex validation

Each text input (`tenant`, `uri`) validates on `input` event:

```js
function isValidRegex(s) {
    if (!s) return true;
    try { new RegExp(s); return true; } catch(e) { return false; }
}
```

Invalid → red border on input, tooltip "Invalid regular expression", `gFilter` key not updated (old value or empty retained), filter not sent to server.

### 5.5 View sub-filter (log viewer)

Below the "Recent Requests" heading, a compact chip row for level:

```
Level: [ All ] [ ERROR ] [ WARNING ] [ RETURN ]
```

Active chip is visually selected. Changing a chip re-renders `state.rows` through `filteredRows()`:

```js
function filteredRows() {
    return state.rows.filter(function(r) {
        if (vFilter.level && r.level !== vFilter.level) return false;
        return true;
    });
}
```

`renderStats()` uses `filteredRows()` instead of `state.rows` directly for the log viewer table. No server request.

> Method and type filtering are global (CA19) — they live in the filter bar, not in view sub-filters.

### 5.6 Filtered mode indicator

When any `gFilter` key is active, the summary strip title changes to "Statistics (filtered)" and the live dot uses an amber colour instead of green, indicating the aggregates reflect a subset of the log.

---

## 6. Unit Tests

New tests in `test/util_data_test.php`:

| Test | Verifies |
|------|---------|
| `parse_filter` — valid type | comma-split, trimmed, stored as array |
| `parse_filter` — valid method | comma-split GET,POST stored as array |
| `parse_filter` — valid regex | tenant/uri with plain string → valid |
| `parse_filter` — invalid regex | `f[uri]=[unclosed` → valid=false, bad_key='uri' |
| `apply_filter` — type match | row type not in type list → excluded |
| `apply_filter` — type pass | row type in type list → included |
| `apply_filter` — method match | row method not in method list → excluded |
| `apply_filter` — tenant regex | row tenant matches pattern → included |
| `apply_filter` — uri regex | row uri matches pattern → included |
| `apply_filter` — no filter | empty filter → all rows pass |
| `apply_filter` — multi-criteria | all criteria AND-combined |
| `data_stats_respond` with filter | filtered response has only matching rows |

---

## 7. File Map

| File | Action | Change |
|------|--------|--------|
| `.ai/constitution.md` | Modify | Add CA19, bump to v1.11.0 |
| `infopedia.cfg` | Modify | Add `log_requests = false` under `[data]` |
| `util_data.php` | Modify | Add `parse_filter()`, `apply_filter()`; extend `data_stats_respond()` with `$filter` param |
| `data.php` | Modify | Read `$_GET['f']`, validate, pass to `data_stats_respond()`; conditional `log_return()` |
| `statistic.html` | Modify | Filter bar UI, `gFilter`/`vFilter` state, poll restart on filter change, view sub-filter level chip |
| `test/util_data_test.php` | Modify | Add `parse_filter` + `apply_filter` + filtered-respond unit tests |

No new files. No E2E test changes (existing E1–E5b remain valid; unfiltered path unchanged).

---

## 8. Non-Goals (v1)

- No filter persistence across page reloads (no localStorage)
- No sub-filters on charts or totals (global filter covers those via server)
- No ops entity filtering (ops events are already sparse; add in v2 if needed)
- No saved/named filter presets
