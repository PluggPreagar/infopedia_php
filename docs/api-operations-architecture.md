# InfoPedia API — Data Flow & Architecture

> 📚 **Navigation:** [Docs Index](./README.md) → [API Operations](./api-operations.md) → **Architecture & Configuration**
>
> **Related Modules:** [Client-Side](./api-operations-client.md) · [Server-Side](./api-operations-server.md) · [Troubleshooting](./api-operations-troubleshooting.md)

This module covers the overall data flow architecture, consistency guarantees, and configuration reference for the InfoPedia API.

---

## 3. Data Flow Architecture

### 3.1 Write Path Overview

```
Client
  │
  ├─ POST /entries ──┬─▶ entries.php
  │                  │    ├─ Validate + throttle
  │                  │    ├─ Append to entries_X.csv (flock)
  │                  │    ├─ touchOutdated(cache)
  │                  │    ├─ append_notify(['type'=>'entries'])
  │                  │    └─ sumup_append_entry() (if enabled)
  │                  │
  ├─ POST /votes ────┬─▶ votes.php
  │                  │    ├─ Validate + throttle
  │                  │    ├─ Append to votes_X.csv (flock)
  │                  │    ├─ sumup_append_vote() (always enabled)
  │                  │    └─ append_notify(['type'=>'votes'])
  │                  │
  └─ (Server code) ──┬─▶ util.php::append_notify()
                     │    └─ Append to notify_X.jsonl (flock)
                     │
                     ▼
               data/ files
```

**Flow Steps:**
1. **Validation** — Parse entry/vote, check throttle
2. **Atomic Append** — Write to CSV with exclusive lock
3. **Cache Invalidation** — Mark cache outdated
4. **Notification** — Write event to notify log
5. **Aggregation** — Update sumup snapshot (async)

---

### 3.2 Read Path Overview

```
Client
  │
  ├─ GET /entries ───┬─▶ entries.php
  │                  │    ├─ isCacheValid() ?
  │                  │    ├─ YES → readCache() → respond
  │                  │    └─ NO  → sumup_update() / sortCsvData()
  │                  │           → csv_to_json() → writeCache() → respond
  │                  │
  ├─ GET /votes ─────┬─▶ votes.php
  │                  │    ├─ sumup_update() (always, no cache)
  │                  │    ├─ votes_sumup_project(sid)
  │                  │    └─ csv_to_json() → respond
  │                  │
  └─ GET /notify ────┬─▶ notify.php
                     │    ├─ Poll loop (≤25s)
                     │    ├─ clearstatcache() + filemtime() every 2s
                     │    ├─ Check entries_X.csv, votes_X.csv mtime
                     │    ├─ Read notify_X.jsonl (lines with ts > since)
                     │    └─ Respond [events] or 204 on timeout
                     │
                     ▼
                Client processes events
```

**Read Optimizations:**
- **Two-tier caching:** Cache layer for entries, sumup for votes/entries (opt-in)
- **Incremental updates:** Sumup snapshots with offset tracking
- **Lazy materialization:** JSON/TXT conversion only when needed
- **Long-poll efficiency:** No CPU spin, server-side hold with 2s wake cycle

---

### 3.3 Consistency Guarantees

| Scenario | Guarantee | Mechanism |
|----------|-----------|-----------|
| Concurrent writes to same CSV | Last write wins (atomic append) | `flock(LOCK_EX)` on write, no lock on read |
| Read during write | Read sees old or new state, never partial | `flock(LOCK_SH)` optional (not enforced) |
| Sumup snapshot vs CSV tail | Snapshot eventually consistent | Offset-based tail merge + flock on save |
| Cache vs CSV | Cache valid until `touchOutdated()` | `.outdated` marker file checked on read |
| Notify events vs CSV mtime | Both fire (belt-and-suspenders) | Explicit append + mtime-based event |
| Multiple notify.php clients | All receive same events | Stateless file reads, filters by `since` |

**No Guarantees:**
- Global transaction ordering across tenants (not needed for isolated tenants)
- Exactly-once delivery of notify events (clients must deduplicate by path)
- Real-time precision < 2s (poll interval)
- Atomic visibility of multi-row updates (clients see partial state during update window)

**Consistency Model:** Eventual consistency with AP (Availability + Partition-tolerance) priorities. Writes always succeed (append-only); reads eventually see all writes.

---

## 4. Configuration Reference

**File:** `infopedia.cfg`

### General Section

```ini
[general]
throttle_max    = 10        ; max requests per window (0 = disabled)
throttle_window = 60        ; window in seconds
throttle_key    = sid       ; 'sid' (session) or 'ip' (IP address)
poll_timeout    = 25        ; seconds (notify.php hold time before 204)
debug           = false     ; enable debug logging
```

**Throttle Behavior:**
- `throttle_max = 0`: disabled, all requests allowed
- `throttle_max > 0`: enforced per `throttle_key` (sid or ip)
- State persisted: `data/throttle_<key>.dat`
- Applied to: POST /entries, POST /votes, POST /dumps, GET ?refresh=1

### Entry Section

```ini
[entry]
sumup_enabled = false       ; true = use snapshot for entries (burn-in phase)
data_dir      = data/       ; CSV file location (relative to project root)
cache_dir     = data/       ; Cache file location
cache_max_age = 300         ; seconds (5 min)
cache_delay   = 5           ; seconds (outdated marker grace period)
```

**Sumup Burn-in:**
- Default: `false` (full CSV read + sortCsvData())
- After 30 days production use with zero corruption: flip to `true`
- Backward compatible (old tenants rebuild snapshot on first read)

### Vote Section

```ini
[vote]
; sumup always enabled for votes (required for aggregation)
cache_max_age = 60          ; seconds (votes not cached currently)
```

**Vote Aggregation:**
- Sumup always on (no config option)
- Incremental merge on every POST /votes
- Full snapshot rebuild on GET with `?refresh=1`

### Notify Section (Planned)

```ini
[notify]
retention       = 60        ; seconds (incremental file coverage)
max_size        = 1048576   ; bytes (rotation trigger, ~1 MB)
poll_interval   = 2         ; seconds (wake cycle in notify.php)
```

**Future Feature:** Rotating notify files (dual-write guarantee).

---

### Session & Tenant ID Format

```ini
; Global defaults for ID validation
session_id_max  = 32        ; max session ID length
tenant_id_max   = 30        ; max tenant ID length
; Both sanitized: preg_replace('/[^a-zA-Z0-9_-]/', '', $val)
```

---

### Logging

```ini
; Output controlled by debug flag
log_file        = data/infopedia.log
log_level       = info      ; debug | info | warn | error
```

**Log Functions:**
- `log_info($msg)` — information (config loaded, tenant created, etc.)
- `log_error($msg)` — errors (validation failed, file write failed, etc.)
- `log_debug($msg)` — debugging (only if `debug = true`)

---

## Data Storage Layout

```
data/
├── entries_<tid>.csv              # Append-only log (source of truth)
├── entries_<tid>.cache            # JSON cache (optional, expires)
├── entries_<tid>.cache.outdated   # Marker: cache stale
├── entries_<tid>.sumup.json       # Snapshot (incremental, opt-in)
│
├── votes_<tid>.csv                # Append-only log (source of truth)
├── votes_<tid>.sumup.json         # Snapshot (always enabled)
│
├── notify_<tid>.jsonl             # Event log (append-only, future: rotating)
│
├── throttle_<key>.dat             # Rate limit state: <window_start>:<count>
│
└── infopedia.log                  # Application log
```

**File Naming Convention:**
- `<resource>_<tid>.csv` — CSV data files (entries, votes)
- `<resource>_<tid>.cache` — Materialized cache (JSON/TXT)
- `<resource>_<tid>.sumup.json` — Snapshot state
- `notify_<tid>.jsonl` — Event log (JSONL format)
- `throttle_<key>.dat` — Throttle state

---

**Last Updated:** 2026-07-11  
**Related:** [API Use Cases](./api-use-cases.md) (index) · [API Use Cases § Client](./api-use-cases-client.md) · [API Use Cases § Server](./api-use-cases-server.md) · [Configuration Template](../infopedia_template.cfg)

