# InfoPedia API — Client-Side Operations

> 📚 **Navigation:** [Docs Index](./README.md) → [API Operations](./api-operations.md) → **Client-Side Scenarios**
>
> **Related Modules:** [Server-Side](./api-operations-server.md) · [Architecture](./api-operations-architecture.md) · [Use Cases](./api-use-cases-client.md)

This module covers **how clients interact with the InfoPedia API**, from initial load through real-time synchronization. For detailed use case definitions with error handling, see [API Use Cases § Client-Side](./api-use-cases-client.md).

---

## 1.1 Initial Client Sync (Cold Start)

**Trigger:** User opens the app for the first time or after clearing browser storage.  
**Use Case:** [UC-C1: Initial App Load](./api-use-cases-client.md#uc-c1-initial-app-load)

### Process

1. Client generates or retrieves session ID (`sid`)
2. Client determines tenant ID (`tid`) from URL or settings
3. Client performs full data fetch
4. Client establishes notify connection

### Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Client (app2.html)
    participant E as entries.php
    participant V as votes.php
    participant N as notify.php
    participant D as data/ files

    Note over C: Cold start
    C->>C: Generate/retrieve sid
    C->>C: Determine tid (URL or default)
    
    C->>E: GET /entries?tid=X&sid=Y
    E->>D: Read entries_X.csv
    D-->>E: Full CSV
    E->>E: sortCsvData() + format
    E-->>C: 200 OK (all entries, JSON)
    
    C->>V: GET /votes?tid=X&sid=Y
    V->>D: sumup_update(votes_X.csv)
    D-->>V: Aggregated votes
    V->>V: Project per session
    V-->>C: 200 OK (aggregated votes, JSON)
    
    C->>C: Merge data into store
    C->>C: Render UI
    
    C->>N: GET /notify?tid=X (no since)
    Note over N: Hold connection ≤25s
    N->>D: Watch entries_X.csv, votes_X.csv, notify_X.jsonl
    Note over N: (blocks until change or timeout)
```

**Key Points:**
- No `since` parameter on first `/entries` and `/notify` calls
- Client stores `latestTimestamp` from entries response
- Notify connection established **after** initial data load
- `/votes` always returns full aggregated state (no incremental)

**Config:**
- `[entry] sumup_enabled = false` → full CSV read + sortCsvData()
- `[entry] sumup_enabled = true` → snapshot + tail merge

---

## 1.2 Catchup During Retention Time

**Trigger:** User returns to app after brief absence (seconds to minutes).  
**Use Case:** [UC-C2: Incremental Sync (Within Retention)](./api-use-cases-client.md#uc-c2-incremental-sync-within-retention)

### Process

1. Client has `latestTimestamp` stored (e.g., `2026-07-11 12:34:56`)
2. Notify connection returns `{"type":"entries"}` event
3. Client fetches incremental delta using `since`
4. Client merges delta into existing data

### Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Client
    participant N as notify.php
    participant E as entries.php
    participant D as data/

    Note over C: Returns after 2 minutes
    C->>N: GET /notify?tid=X&since=2026-07-11 12:34:56
    
    Note over N: Poll loop (≤25s)
    loop Every 2s
        N->>D: clearstatcache() + filemtime()
        D-->>N: entries_X.csv mtime > since
    end
    
    N-->>C: 200 OK [{"type":"entries"}]
    
    C->>E: GET /entries?tid=X&sid=Y&since=2026-07-11 12:34:56
    E->>D: Read entries_X.csv (full or tail)
    D-->>E: CSV rows
    E->>E: Filter ts > since, sortCsvData()
    E-->>C: 200 OK (delta entries only)
    
    C->>C: addData(delta) — merge by path
    C->>C: Update latestTimestamp
    C->>C: updateView()
```

**Key Points:**
- `since` parameter filters server-side (outer CSV timestamp)
- Client must merge by **path** (not append) to handle updates
- Vote changes trigger full re-fetch of `/votes` (no `since` support)
- Incremental notify files (future) will carry payloads directly

**Retention Window:**
- Currently: unlimited (full CSV always readable)
- Future (with incremental notify): ~50–75s (2–3× poll_timeout)

---

## 1.3 Catchup After Retention Time (Long Offline)

**Trigger:** User returns after hours/days offline.  
**Use Case:** [UC-C3: Full Re-Sync (Outside Retention)](./api-use-cases-client.md#uc-c3-full-re-sync-outside-retention)

### Process

1. Client attempts incremental sync with old `since` timestamp
2. Server determines client is outside retention window (future feature)
3. Server responds with status indicating full re-sync needed
4. Client discards old data and performs cold start flow

### Sequence Diagram (Current Implementation)

```mermaid
sequenceDiagram
    participant C as Client
    participant E as entries.php
    participant V as votes.php
    participant D as data/

    Note over C: Returns after 48 hours
    C->>E: GET /entries?tid=X&sid=Y&since=2026-07-09 12:00:00
    E->>D: Read entries_X.csv
    Note over E: No retention check yet<br/>(always returns full delta)
    E-->>C: 200 OK (all rows since 2026-07-09)
    
    Note over C: Works, but inefficient<br/>for very old timestamps
```

### Sequence Diagram (Future with Incremental Notify)

```mermaid
sequenceDiagram
    participant C as Client
    participant N as notify.php
    participant E as entries.php
    participant D as data/

    Note over C: Returns after 2 hours
    C->>N: GET /notify?tid=X&since=2026-07-11 10:00:00&msgid=42
    N->>D: Check notify_X_a.jsonl, notify_X_b.jsonl
    Note over N: since outside min_ts in both files
    N-->>C: 200 OK [{"type":"re_sync_required"}]
    
    C->>C: Discard latestTimestamp
    C->>E: GET /entries?tid=X&sid=Y (no since)
    E-->>C: 200 OK (full dataset)
    
    C->>C: Replace store completely
    C->>C: updateView()
```

**Key Points:**
- Current: no retention enforcement (always incremental-capable)
- Future: rotating notify files have finite history
- `re_sync_required` event triggers full cold start
- Client must **replace** data, not merge (paths may have been deleted)

---

## 1.4 Receiving Real-Time Notifications

**Trigger:** Server-side write (entry, vote, or explicit message).  
**Use Case:** [UC-C4: Real-Time Event Handling](./api-use-cases-client.md#uc-c4-real-time-event-handling)

### Process

1. Client has active long-poll connection to `/notify`
2. Server detects file mtime change or explicit notify write
3. Server responds with event array
4. Client handles each event type appropriately

### Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Client
    participant N as notify.php
    participant D as data/
    participant E as entries.php

    Note over C: Long-poll active
    C->>N: GET /notify?tid=X&since=2026-07-11 12:34:56
    Note over N: Holding... (≤25s)
    
    Note over D: Another user writes
    D->>D: Append to entries_X.csv
    D->>D: Append to notify_X.jsonl
    
    loop Every 2s
        N->>D: clearstatcache() + filemtime()
    end
    
    D-->>N: entries_X.csv mtime changed
    N->>D: Read notify_X.jsonl (lines with ts > since)
    D-->>N: [{"type":"entries","ts":"..."}]
    
    N-->>C: 200 OK [{"type":"entries"}]
    
    C->>E: GET /entries?tid=X&sid=Y&since=...
    E-->>C: 200 OK (new entry)
    C->>C: addData(), updateView()
    
    C->>N: GET /notify?tid=X&since=... (reconnect)
```

**Event Types:**

| Event | Trigger | Client Action |
|-------|---------|---------------|
| `{"type":"entries"}` | entries_X.csv mtime OR notify append | Fetch delta from `/entries?since=...` |
| `{"type":"votes"}` | votes_X.csv mtime OR notify append | Fetch full state from `/votes` |
| `{"type":"message","text":"..."}` | Explicit `append_notify()` call | Show toast (no data fetch) |

**Timeout Handling:**

```mermaid
sequenceDiagram
    participant C as Client
    participant N as notify.php

    C->>N: GET /notify?tid=X&since=...
    Note over N: Poll loop (25s max)
    Note over N: No changes detected
    N-->>C: 204 No Content
    
    Note over C: Reconnect immediately<br/>(server already waited)
    C->>N: GET /notify?tid=X&since=...
```

**Key Points:**
- `204 No Content` = timeout, not error
- Client must reconnect immediately after 204
- Server sleep(2) means max latency = 2s
- Multiple events batched in same response if they occur within 2s window

---

**Last Updated:** 2026-07-11  
**Related:** [API Use Cases § Client-Side](./api-use-cases-client.md) · [Backend Communication](./backend-communication.md)

