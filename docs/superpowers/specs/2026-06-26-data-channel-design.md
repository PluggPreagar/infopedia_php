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
| `util_data.php` | Pure transform functions. No HTTP logic. Unit-testable in isolation. |
| `statistic.html` | SPA shell: two parallel poll loops, accumulates state, renders in-place |
| `statistic.php` | Kept as HTTP redirect to `statistic.html` |
| `test/util_data_test.php` | Unit tests for all transform functions |

### Entity dispatch table (inside `data.php`)

```php
$handlers = [
    'stats' => [
        'source'  => 'log_offset',
        'file'    => $logFile,
        'full'    => 'data_stats_full',
        'delta'   => 'data_stats_delta',
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

`data.php` selects source watcher and transform function based on entity.
Adding a new entity type means adding one entry to this table and two functions
to `util_data.php`. No changes to `data.php` itself.

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

All 200 responses share this envelope:

```json
{
  "entity": "stats",
  "mode":   "full | delta",
  "<cursor fields>": ...,
  "data":   { ... }
}
```

Cursor fields per entity:

| Entity | Cursor fields |
|--------|---------------|
| `stats` | `"offset": N` (byte position in log after last parsed line) |
| `ops`   | `"ts": N, "msgid": M` (last JSONL message coordinates) |

## Entity: `stats`

### Source and change trigger

Watches `infopedia.log` via `filesize()`. Change detected when
`filesize($logFile) > $offset`. `fseek` to `$offset`, read only new bytes.
O(1) seek — no re-scanning.

### First request (no `offset`)

Parse entire log. Return full aggregate. Include `offset` = current filesize.
Frontend stores offset for subsequent requests.

### Full payload

```json
{
  "entity": "stats", "mode": "full", "offset": 45678,
  "data": {
    "requests": 1250, "errors": 12, "warnings": 3,
    "sessions": 8, "tenants": 2,
    "avg_ms": 45.2, "max_ms": 1240.0,
    "first_ts": "2026-06-01 08:00:00",
    "last_ts":  "2026-06-26 16:00:00",
    "by_type": {
      "entries": { "get": 100, "post": 50, "avg_ms": 23.1, "max_ms": 180.0, "errors": 2 }
    },
    "by_hour":   [0, 0, 0, 0, 0, 0, 12, 42, 67, ...],
    "rt_buckets": { "<1ms": 80, "1-10ms": 200, "10-100ms": 900, "100ms-1s": 60, ">1s": 10 },
    "timeline":  [0, 5, 12, 8, ...],
    "tl_bucket": 3600,
    "tl_label":  "1h buckets",
    "tl_min_ts": 1719792000,
    "add_rows": [ ... ]
  }
}
```

`add_rows` on full: the most recent N rows for the log viewer (configurable,
default 50). Same structure as delta `add_rows`.

### Delta payload

```json
{
  "entity": "stats", "mode": "delta", "offset": 45890,
  "data": {
    "requests_delta": 2,
    "errors_delta":   0,
    "warnings_delta": 0,
    "by_type_delta": {
      "entries": { "post": 1 },
      "votes":   { "post": 1 }
    },
    "by_hour_delta":  { "16": 2 },
    "rt_delta":       { "10-100ms": 1, "1-10ms": 1 },
    "timeline_delta": { "42": 2 },   // bucket index; frontend maps via tl_min_ts + tl_bucket from full response
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

### Stale offset

If `$offset > filesize($logFile)`: log was rotated. Respond 400 `STALE_OFFSET`.
Frontend drops `offset`, restarts as first request (full).

## Entity: `ops`

### Source

Dual-write JSONL files per tenant: `data/ops_<tid>_a.jsonl`,
`data/ops_<tid>_b.jsonl`. Same rotation pattern as `notify_<tid>_a/b.jsonl`.
Written via `append_ops(string $tid, array $event): void` in `util_data.php`
(thin wrapper around `append_incr()`).

### Cursor

`(ts, msgid)` — identical mechanics to `notify.php`. First request (no cursor):
watermark current state, return all messages within rotation window as full.
With cursor: return only new messages since `(ts, msgid)`.

### Ops event shape (JSONL line)

```json
{ "ts": 1719139200, "msgid": 3, "type": "ops",
  "severity": "info | warn | critical",
  "op": "deploy | restart | config | alert | message",
  "text": "Human-readable description" }
```

Messages within the rotation window defined by `re_read_timespan` (same config
key as `notify.php`, default 75 seconds). Outside this window: 400 `STALE_CURSOR`.

### Full payload

```json
{
  "entity": "ops", "mode": "full", "ts": 1719139200, "msgid": 3,
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

### Delta payload

```json
{
  "entity": "ops", "mode": "delta", "ts": 1719139260, "msgid": 5,
  "data": {
    "add_rows": [
      { "ts": 1719139260, "msgid": 5, "severity": "critical",
        "op": "alert", "text": "Error rate exceeded 10%" }
    ]
  }
}
```

## Frontend: `statistic.html`

### Two parallel poll loops

```javascript
async function pollStats(cursor) {
    const params = { entity: 'stats', ...(cursor ?? {}) };
    const res = await fetch('data.php?' + new URLSearchParams(params));
    if (res.status === 200) {
        const body = await res.json();
        if (body.mode === 'full')  replaceStats(body.data);   // resets module-level state
        if (body.mode === 'delta') applyStatsDelta(body.data); // mutates module-level state
        pollStats({ offset: body.offset });
    } else if (res.status === 204) {
        pollStats(cursor);
    } else if (res.status === 400) {
        const err = await res.json();
        if (err.error.code === 'STALE_OFFSET') pollStats();  // restart full
    }
}

async function pollOps(cursor) {
    const params = { entity: 'ops', ...(cursor ?? {}) };
    const res = await fetch('data.php?' + new URLSearchParams(params));
    if (res.status === 200) {
        const body = await res.json();
        body.data.add_rows.forEach(appendOpsRow);
        pollOps({ ts: body.ts, msgid: body.msgid });
    } else if (res.status === 204) {
        pollOps(cursor);
    } else if (res.status === 400) {
        pollOps();  // stale cursor — restart
    }
}

// page load — no cursor on either → full fetch immediately
pollStats();
pollOps();
```

### State accumulation for stats delta

`state` is a module-level object initialised by `replaceStats()` on the first
full response. `applyStatsDelta` mutates it in place; `renderStats` re-renders
from it after each delta.

```javascript
function applyStatsDelta(delta) {  // state is module-level
    state.requests += delta.requests_delta;
    state.errors   += delta.errors_delta;
    state.warnings += delta.warnings_delta;

    for (const [type, d] of Object.entries(delta.by_type_delta ?? {})) {
        const t = state.by_type[type] ??= { get:0, post:0, errors:0, times:[] };
        if (d.get)    t.get    += d.get;
        if (d.post)   t.post   += d.post;
        if (d.errors) t.errors += d.errors;
    }
    for (const [h, n]   of Object.entries(delta.by_hour_delta  ?? {}))
        state.by_hour[h]   = (state.by_hour[h]   ?? 0) + n;
    for (const [b, n]   of Object.entries(delta.rt_delta       ?? {}))
        state.rt_buckets[b]= (state.rt_buckets[b] ?? 0) + n;
    for (const [i, n]   of Object.entries(delta.timeline_delta ?? {}))
        state.timeline[i]  = (state.timeline[i]   ?? 0) + n;

    delta.add_rows?.forEach(row => prependLogRow(row));
    renderStats(state);
}
```

## Backend transform functions (`util_data.php`)

| Function | Description |
|----------|-------------|
| `data_stats_full(string $logFile): array` | Parse entire log; return full aggregate |
| `data_stats_delta(string $logFile, int $offset): array` | `fseek` to offset; parse new lines only; return delta aggregate |
| `data_ops_full(string $file_a, string $file_b): array` | Return all messages within rotation window |
| `data_ops_delta(string $file_a, string $file_b, int $ts, int $msgid): array` | Return messages since `(ts, msgid)` cursor |
| `append_ops(string $tid, array $event): void` | Wrapper: sets `type='ops'`, delegates to `append_incr()` |

All functions are pure-ish (no HTTP side effects), making them unit-testable
without a running server.

## Config

Add to `infopedia.cfg` under `[general]`:

```ini
data_poll_timeout   = 25   ; seconds, same default as notify
data_log_viewer_max = 50   ; max add_rows in full stats response
```

## Testing

### Unit (`test/util_data_test.php`)

- `data_stats_full()` on a known log fixture → correct request/error/by_type counts
- `data_stats_delta()` with offset mid-file → only parses lines after offset
- `data_stats_delta()` with offset = filesize → returns empty delta, no error
- `data_ops_delta()` with `(ts, msgid)` cursor → returns only newer messages
- `append_ops()` writes a valid JSONL line with `ts`, `msgid`, `type='ops'`

### E2E

- `GET data.php` (no entity) → 400 `INVALID_ENTITY`
- `GET data.php?entity=stats` → 200 full payload with correct envelope
- `GET data.php?entity=stats&offset=999999999` → 400 `STALE_OFFSET`
- `GET data.php?entity=ops&tid=e2e` (no cursor) → 200 or 204
- `append_ops('e2e', [...])` then `GET data.php?entity=ops&tid=e2e&ts=<before>` → 200 with `add_rows`

## Files Changed

| File | Change |
|------|--------|
| `data.php` | Create — new route endpoint |
| `util_data.php` | Create — transform functions, `append_ops()` |
| `statistic.html` | Create — SPA monitoring frontend |
| `statistic.php` | Modify — redirect to `statistic.html` |
| `infopedia.cfg` | Add `data_poll_timeout`, `data_log_viewer_max` |
| `test/util_data_test.php` | Create — unit tests |

## Non-goals

- No changes to `notify.php`, `entries.php`, `votes.php`, `util.php`
- No multiplexed multi-entity connections (one entity per connection)
- No WebSockets, no SSE — long-poll pattern only (consistent with existing stack)
- No tenant filtering on `stats` (log is global; filtering by tenant is a future extension)
