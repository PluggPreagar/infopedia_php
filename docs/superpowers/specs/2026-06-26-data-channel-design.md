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

## Architecture

### Channel topology

One long-poll connection per entity. The statistic page opens two parallel
connections:

```
statistic.html  ──┬──→  data.php?entity=stats&offset=N   (long-poll)
                  └──→  data.php?entity=ops&ts=N&msgid=M  (long-poll)
```

Each connection is independent: different cursor types, different sources,
different payloads.

### File layout

| File | Role |
|------|------|
| `data.php` | Route handler: validates entity, resolves paths, runs long-poll loop, dispatches to transform functions, responds |
| `util_data.php` | Pure transform + cache functions. No HTTP logic. Unit-testable in isolation. |
| `util_cache.php` | Add `long_poll_files()`; refactor `long_poll()` to delegate to it |
| `statistic.html` | SPA shell: two parallel poll loops, stateless for stats, accumulates ops rows |
| `statistic.php` | Kept as HTTP redirect to `statistic.html` |
| `test/util_data_test.php` | Unit tests for all transform and cache functions |

### Entity dispatch table (inside `data.php`)

```php
$handlers = [
    'stats' => [
        'source'      => 'log_mtime',
        'file'        => $logFile,
        'cache_file'  => 'data/stats_aggregate.cache',
        'handler'     => 'data_stats_respond',
    ],
    'ops' => [
        'source'  => 'jsonl',
        'file_a'  => 'data/ops' . $suffix . '_a.jsonl',
        'file_b'  => 'data/ops' . $suffix . '_b.jsonl',
        'full'    => 'data_ops_full',
        'delta'   => 'data_ops_delta',
    ],
];
```

Adding a new entity means one table entry + functions in `util_data.php`.
No changes to `data.php` itself.

## Shared infrastructure: `long_poll_files()` in `util_cache.php`

The existing `long_poll()` hardcodes `entries.csv` and `votes.csv`. Extract
the core loop into a general function; `long_poll()` becomes a thin wrapper.

```php
// General: watch any list of files by mtime. Reusable by data.php.
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

// Backward-compat wrapper — behaviour unchanged.
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
// stats — watches infopedia.log mtime
long_poll_files([$logFile], $now, $poll_timeout);

// ops — watches both rotation files
long_poll_files([$file_a, $file_b], $now, $poll_timeout);
```

## API Contract

### `GET data.php?entity=stats[&offset=N]`

| Parameter | Description |
|-----------|-------------|
| `entity`  | Required. `stats` or `ops`. Validated against dispatch table. |
| `offset`  | Stats only. Byte offset into `infopedia.log`. Absent on first request. |
| `ts`      | Ops only. Unix int cursor from last response. |
| `msgid`   | Ops only. Message ID cursor from last response. |
| `tid`     | Optional. Tenant filter for ops; stats is always global (full log). |

**Responses**

| Status | Body | Meaning |
|--------|------|---------|
| 200 | JSON object | Data available — handle and reconnect with new cursor |
| 204 | empty | Timeout — reconnect immediately with same cursor |
| 400 | `{"error":{"code":"INVALID_ENTITY",...}}` | Unknown entity param |
| 400 | `{"error":{"code":"STALE_OFFSET",...}}` | Log was rotated; drop offset, restart |
| 400 | `{"error":{"code":"STALE_CURSOR",...}}` | Ops JSONL outside rotation window; restart |

### Response envelope

```json
{
  "entity": "<stats|ops>",
  "<cursor fields>": "...",
  "data": { "..." }
}
```

Cursor fields per entity:

| Entity | Cursor fields |
|--------|---------------|
| `stats` | `"offset": N` (byte position after last parsed line) |
| `ops`   | `"ts": N, "msgid": M` (last JSONL message coordinates) |

## Entity: `stats`

### Change trigger

`long_poll_files([$logFile], $now, $timeout)` — wakes when `infopedia.log`
mtime advances. The `offset` parameter is the **read cursor**, not the poll
trigger; polling is always mtime-based.

### Server-side aggregate cache

History is read once and cached. Subsequent requests process only new bytes.

**Cache file:** `data/stats_aggregate.cache`

```json
{
  "offset": 45678,
  "agg": {
    "requests":     1250,
    "errors":       12,
    "warnings":     3,
    "sessions":     ["abc", "def", "ghi"],
    "tenants":      ["demo", "test"],
    "times_sum":    56500.0,
    "times_count":  1240,
    "max_ms":       1240.0,
    "first_ts":     "2026-06-01 08:00:00",
    "last_ts":      "2026-06-26 16:00:00",
    "by_type": {
      "entries": { "get": 100, "post": 50, "errors": 2,
                   "times_sum": 2310.0, "times_count": 150, "max_ms": 180.0 }
    },
    "by_hour":    [0, 0, 0, 12, 42, 67, 0, 0, 0, 0, 0, 0,
                   0, 0, 0, 0,  0,  0, 0, 0, 0, 0, 0, 0],
    "rt_buckets": { "<1ms": 80, "1-10ms": 200, "10-100ms": 900,
                    "100ms-1s": 60, ">1s": 10 },
    "tl_min_ts":  1719792000,
    "tl_bucket":  3600,
    "timeline":   { "0": 5, "1": 12, "2": 8 }
  }
}
```

Non-additive aggregations are correct because the cache holds full sets:
- `sessions_uniq` = `count(array_unique(array_merge($cache['sessions'], $new_sessions)))`
- `avg_ms` = `($cache['times_sum'] + $new_sum) / ($cache['times_count'] + $new_count)`
- `max_ms` = `max($cache['max_ms'], $new_max)`

**Cache validity:** `cache.offset <= filesize($logFile)`. Invalid when offset
exceeds filesize (log rotation) — cold-start re-read, cache rebuilt.

**Request flow:**
1. Load cache via `readCache()` from `util_cache.php` — O(1)
2. If valid: `fseek` to `cache.offset`, read only new bytes — O(new_lines)
3. If invalid (or absent): read from byte 0 — O(full_log), one-time cold start
4. Merge new lines into cached aggregate
5. Save updated cache via `writeCache()` from `util_cache.php` — O(1)
6. Respond with merged full aggregate + `add_rows` (new lines only)

### Response payload (always full — server merges internally)

The frontend is **stateless for stats**: it receives the complete merged
aggregate on every response and replaces its state. The expensive work
(history aggregation) is done once and cached server-side.

```json
{
  "entity": "stats", "offset": 45890,
  "data": {
    "requests":    1252,
    "errors":      12,
    "warnings":    3,
    "sessions_uniq": 9,
    "tenants_uniq":  2,
    "avg_ms":      44.8,
    "max_ms":      1240.0,
    "first_ts":    "2026-06-01 08:00:00",
    "last_ts":     "2026-06-26 16:07:30",
    "by_type": {
      "entries": { "get": 101, "post": 50, "avg_ms": 23.1, "max_ms": 180.0, "errors": 2 },
      "votes":   { "get": 0,   "post": 51, "avg_ms": 12.1, "max_ms": 45.0,  "errors": 0 }
    },
    "by_hour":    [0, 0, 0, 12, 42, 67, 0, 0, 0, 0, 0, 0,
                   0, 0, 0, 0,  0,  0, 0, 0, 2, 0, 0, 0],
    "rt_buckets": { "<1ms": 80, "1-10ms": 201, "10-100ms": 901,
                    "100ms-1s": 60, ">1s": 10 },
    "tl_min_ts":  1719792000,
    "tl_bucket":  3600,
    "tl_label":   "1h buckets",
    "timeline":   { "0": 5, "1": 12, "2": 8, "42": 2 },
    "add_rows": [
      { "timestamp": "2026-06-26 16:07:00", "type": "entries",
        "uri": "/entries_add", "method": "POST", "ms": 45.2,
        "session": "abc", "tenant": "demo", "level": "RETURN" },
      { "timestamp": "2026-06-26 16:07:30", "type": "votes",
        "uri": "/votes_add", "method": "POST", "ms": 12.1,
        "session": "abc", "tenant": "demo", "level": "RETURN" }
    ]
  }
}
```

`add_rows` = only lines processed this cycle (new since last offset).
Frontend prepends them to the log viewer without re-rendering the full table.

### Stale offset

`$offset > filesize($logFile)` → log rotated → 400 `STALE_OFFSET`.
Frontend drops offset, cache is rebuilt on next request.

## Entity: `ops`

### Source

Dual-write JSONL files per tenant: `data/ops_<tid>_a.jsonl`,
`data/ops_<tid>_b.jsonl`. Same rotation pattern as `notify_<tid>_a/b.jsonl`.
Written via `append_ops(string $tid, array $event): void` in `util_data.php`
(thin wrapper around `append_incr()`).

### Cursor

`(ts, msgid)` — identical mechanics to `notify.php`. First request (no
cursor): watermark current state, return all messages within `re_read_timespan`
window (same config key as `notify.php`, default 75s) as full. With cursor:
return only new messages since `(ts, msgid)`. Outside window → 400
`STALE_CURSOR`.

### Ops event shape (JSONL line)

```json
{ "ts": 1719139200, "msgid": 3, "type": "ops",
  "severity": "info | warn | critical",
  "op": "deploy | restart | config | alert | message",
  "text": "Human-readable description" }
```

### Full payload (first request or after stale cursor)

```json
{
  "entity": "ops", "ts": 1719139200, "msgid": 3,
  "data": {
    "add_rows": [
      { "ts": 1719139100, "msgid": 1, "severity": "info",
        "op": "deploy", "text": "v1.2.0 deployed" },
      { "ts": 1719139200, "msgid": 3, "severity": "warn",
        "op": "restart", "text": "PHP process restarted" }
    ]
  }
}
```

### Delta payload (with cursor)

```json
{
  "entity": "ops", "ts": 1719139260, "msgid": 5,
  "data": {
    "add_rows": [
      { "ts": 1719139260, "msgid": 5, "severity": "critical",
        "op": "alert", "text": "Error rate exceeded 10%" }
    ]
  }
}
```

Frontend accumulates ops: `add_rows` are appended to the ops panel on each
response. No full-replace — ops history builds up over the session.

## Frontend: `statistic.html`

### Two parallel poll loops

```javascript
async function pollStats(cursor) {
    const params = { entity: 'stats', ...(cursor ?? {}) };
    const res = await fetch('data.php?' + new URLSearchParams(params));
    if (res.status === 200) {
        const body = await res.json();
        replaceStats(body.data);              // always full — replace state
        prependLogRows(body.data.add_rows);   // only new rows → log viewer
        pollStats({ offset: body.offset });
    } else if (res.status === 204) {
        pollStats(cursor);
    } else if (res.status === 400) {
        pollStats();                          // STALE_OFFSET → restart full
    }
}

async function pollOps(cursor) {
    const params = { entity: 'ops', ...(cursor ?? {}) };
    const res = await fetch('data.php?' + new URLSearchParams(params));
    if (res.status === 200) {
        const body = await res.json();
        body.data.add_rows.forEach(appendOpsRow);  // accumulate
        pollOps({ ts: body.ts, msgid: body.msgid });
    } else if (res.status === 204) {
        pollOps(cursor);
    } else if (res.status === 400) {
        pollOps();                            // STALE_CURSOR → restart
    }
}

pollStats();   // no cursor → full fetch immediately
pollOps();
```

## Backend functions (`util_data.php`)

| Function | Description |
|----------|-------------|
| `data_stats_respond(string $logFile, string $cacheFile, ?int $offset): array` | Load cache, fseek to offset, parse new lines, merge, save cache, return full aggregate + add_rows |
| `data_ops_full(string $fa, string $fb): array` | All messages within rotation window |
| `data_ops_delta(string $fa, string $fb, int $ts, int $msgid): array` | Messages since `(ts, msgid)` cursor |
| `append_ops(string $tid, array $event): void` | Sets `type='ops'`, delegates to `append_incr()` |
| `load_stats_cache(string $cacheFile, string $logFile): ?array` | `readCache()` + validity check; null on miss/invalid |
| `save_stats_cache(string $cacheFile, int $offset, array $agg): void` | `writeCache()` with `{offset, agg}` envelope |
| `stats_cache_valid(array $cache, string $logFile): bool` | `cache['offset'] <= filesize($logFile)` |

`load_stats_cache` and `save_stats_cache` delegate to `readCache()` /
`writeCache()` from `util_cache.php` (CA7 — reuse over reinvent).

## Config

Add to `infopedia.cfg` under `[general]`:

```ini
data_poll_timeout   = 25   ; seconds, same default as notify
data_log_viewer_max = 50   ; max add_rows in stats response
```

## Testing

### Unit (`test/util_data_test.php`)

- `data_stats_respond()` on full log fixture → correct request/error/session counts
- `data_stats_respond()` with warm cache + new lines appended → only new lines processed; merged totals correct
- `data_stats_respond()` with stale cache (offset > filesize) → cold-start re-read
- `stats_cache_valid()` with offset > filesize → false
- `data_ops_delta()` with `(ts, msgid)` cursor → only newer messages returned
- `append_ops()` → valid JSONL line with `ts`, `msgid`, `type='ops'`
- `long_poll_files()` with non-existent files → returns false immediately

### E2E

- `GET data.php` (no entity) → 400 `INVALID_ENTITY`
- `GET data.php?entity=stats` → 200 full payload, correct envelope, offset present
- `GET data.php?entity=stats&offset=999999999` → 400 `STALE_OFFSET`
- `GET data.php?entity=ops&tid=e2e` (no cursor) → 200 or 204
- `append_ops('e2e', [...])` then `GET data.php?entity=ops&tid=e2e&ts=<before>` → 200 with `add_rows`

## Files Changed

| File | Change |
|------|--------|
| `util_cache.php` | Add `long_poll_files()`; refactor `long_poll()` as thin wrapper |
| `data.php` | Create — new route endpoint |
| `util_data.php` | Create — transform + cache functions, `append_ops()` |
| `statistic.html` | Create — SPA monitoring frontend |
| `statistic.php` | Modify — redirect to `statistic.html` |
| `infopedia.cfg` | Add `data_poll_timeout`, `data_log_viewer_max` |
| `test/util_data_test.php` | Create — unit tests |

## Non-goals

- No changes to `notify.php`, `entries.php`, `votes.php`, `util.php`
- No multiplexed multi-entity connections (one entity per connection)
- No WebSockets, no SSE — long-poll pattern only (consistent with existing stack)
- No tenant filtering on `stats` (log is global; future extension)
- No cache TTL / maxAge on stats cache — validity is offset-based only
