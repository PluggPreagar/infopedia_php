# Data Channel Design

**Date:** 2026-06-26
**Status:** Approved

## Goal

Introduce `data.php` — a general-purpose non-user data channel that serves
monitoring, observability, and operations data to the frontend. Complements the
existing `notify.php` (entries/votes/messages) without touching it.

Split `statistic.php` into a dynamic frontend (`statistic.html`) that consumes
`data.php` live — replacing the current full-page reload model.

## Scope

- `data.php` serves two entity types initially: `stats` and `ops`
- `statistic.html` replaces server-rendered `statistic.php` as the monitoring SPA
- `notify.php`, `util.php`, `append_incr()` — untouched

## Delivery model — two sections

Every 200 response carries at most two sections. The section present and the
value shape are self-describing; the frontend applies a fixed dispatch table
regardless of entity.

| Section | Server contract | FE contract | Value shapes |
|---------|----------------|-------------|-------------|
| `full` | Recomputed complete state — non-additive fields that require set operations or weighted averages | Replace state fields unconditionally | scalars, objects |
| `increments` | New activity since last cursor, shaped for arithmetic or append | Apply each field via its registered handler | number → `+=`, keyed object → bucket-merge, array → prepend |

**FE dispatch — entity-agnostic:**

```javascript
const APPLY = {
    // stats scalars
    requests: (s, v) => s.requests += v,
    errors:   (s, v) => s.errors   += v,
    warnings: (s, v) => s.warnings += v,
    // stats keyed buckets
    by_hour:  (s, v) => Object.entries(v).forEach(([h, n]) => s.by_hour[h]  = (s.by_hour[h]  ?? 0) + n),
    rt:       (s, v) => Object.entries(v).forEach(([b, n]) => s.rt[b]       = (s.rt[b]       ?? 0) + n),
    timeline: (s, v) => Object.entries(v).forEach(([i, n]) => s.timeline[i] = (s.timeline[i] ?? 0) + n),
    by_type:  (s, v) => mergeByType(s.by_type, v),
    // universal array append (stats log rows, ops messages)
    rows:     (s, v) => s.rows = [...v, ...s.rows],
};

function applyIncrements(inc) {
    for (const [k, v] of Object.entries(inc)) APPLY[k]?.(state, v);
}
```

First load and delta use the **same code path**. On first load `increments`
contains full historical counts; on delta it contains only the new activity.

## Architecture

### Channel topology

One long-poll connection per entity. The statistic page opens two parallel
connections:

```
statistic.html  ──┬──→  data.php?entity=stats&offset=N   (long-poll)
                  └──→  data.php?entity=ops&ts=N&msgid=M  (long-poll)
```

### File layout

| File | Role |
|------|------|
| `data.php` | Route handler: validates entity, resolves paths, runs long-poll loop, dispatches to handler functions, responds |
| `util_data.php` | Transform functions, cache helpers, `append_ops()`. No HTTP logic. |
| `util_cache.php` | Add `long_poll_files()`; refactor `long_poll()` as thin wrapper |
| `statistic.html` | SPA shell: two parallel poll loops, unified `applyIncrements` dispatch |
| `statistic.php` | HTTP redirect to `statistic.html` |
| `test/util_data_test.php` | Unit tests for transform and cache functions |

### Entity dispatch table (inside `data.php`)

```php
$handlers = [
    'stats' => [
        'source'     => 'log_mtime',
        'file'       => $logFile,
        'cache_file' => 'data/stats_aggregate.cache',
        'handler'    => 'data_stats_respond',
    ],
    'ops' => [
        'source' => 'jsonl',
        'file_a' => 'data/ops' . $suffix . '_a.jsonl',
        'file_b' => 'data/ops' . $suffix . '_b.jsonl',
        'full'   => 'data_ops_full',
        'delta'  => 'data_ops_delta',
    ],
];
```

Adding a new entity = one table entry + functions in `util_data.php`.

## Shared infrastructure: `long_poll_files()` in `util_cache.php`

Extract the core loop from `long_poll()` into a general function;
`long_poll()` becomes a thin backward-compat wrapper.

```php
function long_poll_files(array $files, int $now, int $timeout = 25): bool {
    if ($timeout <= 0 || empty($files)) return false;
    $stop_at = $now + $timeout;
    while (time() < $stop_at) {
        clearstatcache();
        foreach ($files as $f) {
            if (file_exists($f) && filemtime($f) > $now) return true;
        }
        sleep(2);
    }
    return false;
}

function long_poll(string $tid, int $now, int $timeout = 25): bool {
    $suffix = $tid !== '' ? '_' . $tid : '';
    $files  = array_values(array_filter([
        'data/entries' . $suffix . '.csv',
        'data/votes'   . $suffix . '.csv',
    ], 'file_exists'));
    return long_poll_files($files, $now, $timeout);
}
```

Usage in `data.php`:
```php
long_poll_files([$logFile],         $now, $poll_timeout);  // stats
long_poll_files([$file_a, $file_b], $now, $poll_timeout);  // ops
```

## API Contract

### Parameters

| Parameter | Entity | Description |
|-----------|--------|-------------|
| `entity`  | both   | Required. `stats` or `ops`. Validated against dispatch table. |
| `offset`  | stats  | Byte offset into `infopedia.log`. Absent on first request. |
| `ts`      | ops    | Unix int cursor from last response. |
| `msgid`   | ops    | Message ID cursor from last response. |
| `tid`     | ops    | Optional tenant filter. Stats is always global (full log). |

### Responses

| Status | Body | Meaning |
|--------|------|---------|
| 200 | JSON object | Data available — apply and reconnect with new cursor |
| 204 | empty | Timeout — reconnect immediately with same cursor |
| 400 | `{"error":{"code":"INVALID_ENTITY"}}` | Unknown entity |
| 400 | `{"error":{"code":"STALE_OFFSET"}}` | Log rotated; drop offset, restart |
| 400 | `{"error":{"code":"STALE_CURSOR"}}` | Ops JSONL outside rotation window; restart |

### Response envelope

```json
{
  "entity":   "<stats|ops>",
  "<cursor>": "...",
  "full":       { "…": "…" },
  "increments": { "…": "…" }
}
```

Either section may be absent when empty. Cursor fields per entity:

| Entity | Cursor |
|--------|--------|
| `stats` | `"offset": N` |
| `ops`   | `"ts": N, "msgid": M` |

## Entity: `stats`

### Change trigger

`long_poll_files([$logFile], $now, $timeout)` — wakes when `infopedia.log`
mtime advances. `offset` is the **read cursor** (fseek position), not the
poll trigger.

### Server-side aggregate cache

History is read once; subsequent requests process only new bytes.

**Cache file:** `data/stats_aggregate.cache`

```json
{
  "log_file": "infopedia.log",
  "offset":   45678,
  "agg": {
    "requests":    1250,
    "errors":      12,
    "warnings":    3,
    "sessions":    ["abc", "def", "ghi"],
    "tenants":     ["demo", "test"],
    "times_sum":   56500.0,
    "times_count": 1240,
    "max_ms":      1240.0,
    "first_ts":    "2026-06-01 08:00:00",
    "last_ts":     "2026-06-26 16:00:00",
    "by_type": {
      "entries": { "get": 100, "post": 50, "errors": 2,
                   "times_sum": 2310.0, "times_count": 150, "max_ms": 180.0 }
    },
    "by_hour":    [0,0,0,12,42,67,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0],
    "rt_buckets": { "<1ms":80, "1-10ms":200, "10-100ms":900, "100ms-1s":60, ">1s":10 },
    "tl_min_ts":  1719792000,
    "tl_bucket":  3600,
    "timeline":   { "0":5, "1":12, "2":8 }
  }
}
```

**Non-additive fields correct via cached sets:**
- `sessions_uniq` = `count(array_unique(array_merge($cache['sessions'], $new)))`
- `avg_ms` = `($cache['times_sum'] + $new_sum) / ($cache['times_count'] + $new_count)`
- `max_ms` = `max($cache['max_ms'], $new_max)`

**Cache validity:** `cache.log_file === $logFile && cache.offset <= filesize($logFile)`.
`log_file` stored from `$config['general']['logFile']`; runtime config changes
not supported. Invalid = cold-start full re-read.

**Request flow:**
1. Load cache (LOCK_SH) — O(1)
2. Valid: `fseek` to `cache.offset`, read new bytes — O(new_lines)
3. Invalid/absent: read from 0 — O(full_log), one-time cold start
4. `merge_stats_chunk($cache_agg, $new_lines)` — pure, no I/O
5. **Only if new lines found:** save cache (LOCK_EX, re-read after lock)
6. Respond

**Cache write — concurrent tab safety:**

```php
function save_stats_cache(string $cacheFile, string $logFile,
                          int $offset, array $agg): void {
    $fp = fopen($cacheFile, 'c');
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    // Re-read: another instance may have written a newer cache while we waited.
    $existing = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($existing) && ($existing['offset'] ?? -1) >= $offset) {
        flock($fp, LOCK_UN); fclose($fp); return;
    }
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(['log_file' => $logFile,
                             'offset'   => $offset, 'agg' => $agg]));
    flock($fp, LOCK_UN); fclose($fp);
}
```

### Stats response

**`full` section** — non-additive, always recomputed via merged cache:

```json
"full": {
  "sessions_uniq": 9,
  "tenants_uniq":  2,
  "avg_ms":        44.8,
  "max_ms":        1240.0,
  "first_ts":      "2026-06-01 08:00:00",
  "last_ts":       "2026-06-26 16:07:30",
  "tl_min_ts":     1719792000,
  "tl_bucket":     3600,
  "tl_label":      "1h buckets",
  "rows_truncated": true
}
```

`tl_min_ts`/`tl_bucket`/`tl_label` in `full` — stable reference values the
FE needs to map `increments.timeline` bucket indices to wall-clock times.
`rows_truncated: true` when `increments.rows` on first load is capped at
`data_log_viewer_max`; absent (or false) when all rows are present.

**`increments` section** — additive, fanned to all consumers:

```json
"increments": {
  "requests":  2,
  "errors":    0,
  "warnings":  0,
  "by_hour":   { "16": 2 },
  "rt":        { "10-100ms": 1, "1-10ms": 1 },
  "by_type":   { "entries": { "post": 1 }, "votes": { "post": 1 } },
  "timeline":  { "42": 2 },
  "rows": [
    { "timestamp":"2026-06-26 16:07:00", "type":"entries",
      "uri":"/entries_add", "method":"POST", "ms":45.2,
      "session":"abc", "tenant":"demo", "level":"RETURN" },
    { "timestamp":"2026-06-26 16:07:30", "type":"votes",
      "uri":"/votes_add", "method":"POST", "ms":12.1,
      "session":"abc", "tenant":"demo", "level":"RETURN" }
  ]
}
```

`increments.rows` = only lines processed this cycle. On first load, capped at
`data_log_viewer_max` (default 50); signalled by `full.rows_truncated`.

### Stale offset

`offset > filesize($logFile)` → 400 `STALE_OFFSET`. FE drops offset, cache
rebuilt on next request.

## Entity: `ops`

### Source

Dual-write JSONL: `data/ops_<tid>_a.jsonl`, `data/ops_<tid>_b.jsonl`.
Same rotation pattern as `notify_<tid>_a/b.jsonl`. Written via
`append_ops(string $tid, array $event)` in `util_data.php` — thin wrapper
around `append_incr()`.

### Cursor

`(ts, msgid)` — same mechanics as `notify.php`. Rotation window:
`ops_rotation_hours = 3` (ops keeps 3h of history; notify keeps 75s).
Outside window → 400 `STALE_CURSOR`.

### Ops event shape (JSONL line)

```json
{ "ts": 1719139200, "msgid": 3, "type": "ops",
  "severity": "info | warn | critical",
  "op": "deploy | restart | config | alert | message",
  "text": "Human-readable description" }
```

### Ops response

Ops carries only `increments` (no non-additive fields → `full` absent).

```json
{
  "entity": "ops", "ts": 1719139260, "msgid": 5,
  "increments": {
    "rows": [
      { "ts": 1719139260, "msgid": 5, "severity": "critical",
        "op": "alert", "text": "Error rate exceeded 10%" }
    ]
  }
}
```

First request (no cursor): `rows` contains all messages within rotation window.
With cursor: `rows` contains only new messages since `(ts, msgid)`.
Same FE code path — `APPLY.rows` prepends in both cases.

## Frontend: `statistic.html`

```javascript
async function pollStats(cursor) {
    const res = await fetch('data.php?' + new URLSearchParams(
        { entity: 'stats', ...(cursor ?? {}) }));
    if (res.status === 200) {
        const body = await res.json();
        Object.assign(state, body.full ?? {});      // replace non-additives
        applyIncrements(body.increments ?? {});      // apply all increment types
        renderStats(state);
        pollStats({ offset: body.offset });
    } else if (res.status === 204) {
        pollStats(cursor);
    } else if (res.status === 400) {
        pollStats();                                 // STALE_OFFSET → restart
    }
}

async function pollOps(cursor) {
    const res = await fetch('data.php?' + new URLSearchParams(
        { entity: 'ops', ...(cursor ?? {}) }));
    if (res.status === 200) {
        const body = await res.json();
        applyIncrements(body.increments ?? {});      // same applier
        renderOps(state);
        pollOps({ ts: body.ts, msgid: body.msgid });
    } else if (res.status === 204) {
        pollOps(cursor);
    } else if (res.status === 400) {
        pollOps();
    }
}

pollStats();
pollOps();
```

## Backend functions (`util_data.php`)

| Function | Layer | Description |
|----------|-------|-------------|
| `data_stats_respond(string $logFile, string $cacheFile, ?int $offset): array` | I/O shell | Load cache, fseek, call `merge_stats_chunk`, save if new lines, build two-section response |
| `merge_stats_chunk(array $agg, array $new_lines): array` | Pure | Merge parsed lines into aggregate; non-additive fields handled via sets/sums; unit-testable without filesystem |
| `load_stats_cache(string $cacheFile, string $logFile): ?array` | I/O | LOCK_SH read + validity check; null on miss/invalid |
| `save_stats_cache(string $cacheFile, string $logFile, int $offset, array $agg): void` | I/O | LOCK_EX write; re-reads after lock, skips write if existing cache is newer |
| `stats_cache_valid(array $cache, string $logFile): bool` | Pure | `cache['log_file'] === $logFile && cache['offset'] <= filesize($logFile)` |
| `data_ops_full(string $fa, string $fb): array` | I/O | All messages within ops rotation window → `increments.rows` |
| `data_ops_delta(string $fa, string $fb, int $ts, int $msgid): array` | I/O | Messages since cursor → `increments.rows` |
| `append_ops(string $tid, array $event): void` | I/O | Sets `type='ops'`, delegates to `append_incr()` |

## Config

Add to `infopedia.cfg` under `[general]`:

```ini
data_poll_timeout   = 25    ; seconds
data_log_viewer_max = 50    ; max rows in increments.rows on first stats load
ops_rotation_hours  = 3     ; ops JSONL history window (hours); notify uses re_read_timespan
```

## Testing

### Unit (`test/util_data_test.php`)

- `merge_stats_chunk()` on known lines → correct counts, sessions_uniq, avg_ms
- `merge_stats_chunk()` called twice → non-additive fields accumulate correctly
- `data_stats_respond()` warm cache + new lines → only new lines processed; cache updated; two-section response correct
- `data_stats_respond()` no new lines → cache NOT written
- `data_stats_respond()` stale cache (`offset > filesize`) → cold-start, cache rebuilt
- `data_stats_respond()` mismatched `log_file` in cache → cold-start
- `stats_cache_valid()` offset > filesize → false
- `stats_cache_valid()` mismatched log_file → false
- `data_ops_delta()` with cursor → only newer messages in `increments.rows`
- `append_ops()` → valid JSONL line with `ts`, `msgid`, `type='ops'`
- `long_poll_files()` empty/non-existent files → false immediately

### E2E

- `GET data.php` (no entity) → 400 `INVALID_ENTITY`
- `GET data.php?entity=stats` → 200, envelope has `full` + `increments`, `offset` present
- `GET data.php?entity=stats&offset=999999999` → 400 `STALE_OFFSET`
- `GET data.php?entity=ops&tid=e2e` (no cursor) → 200 or 204
- `append_ops('e2e', [...])` then poll with prior cursor → 200, `increments.rows` non-empty

## Files Changed

| File | Change |
|------|--------|
| `util_cache.php` | Add `long_poll_files()`; refactor `long_poll()` as thin wrapper |
| `data.php` | Create — route, dispatch, long-poll loop |
| `util_data.php` | Create — transform + cache functions, `append_ops()` |
| `statistic.html` | Create — SPA frontend, unified `applyIncrements` dispatch |
| `statistic.php` | Modify — redirect to `statistic.html` |
| `infopedia.cfg` | Add `data_poll_timeout`, `data_log_viewer_max`, `ops_rotation_hours` |
| `test/util_data_test.php` | Create — unit tests |

## Non-goals

- No changes to `notify.php`, `entries.php`, `votes.php`, `util.php`
- No multiplexed multi-entity connections (one entity per connection)
- No WebSockets, no SSE — long-poll only, consistent with existing stack
- No per-tenant stats filtering (log is global; future extension)
- No cache TTL — validity is offset + log_file match only
