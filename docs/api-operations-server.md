# InfoPedia API — Server-Side Operations

> 📚 **Navigation:** [Docs Index](./README.md) → [API Operations](./api-operations.md) → **Server-Side Scenarios**
>
> **Related Modules:** [Client-Side](./api-operations-client.md) · [Architecture](./api-operations-architecture.md) · [Use Cases](./api-use-cases-server.md)

This module covers **server-side operations**, including tenant provisioning, data ingestion, notification broadcasting, and sumup snapshot management. For detailed use case definitions with error handling, see [API Use Cases § Server-Side](./api-use-cases-server.md).

---

## 2.1 New Tenant Initialization

**Trigger:** First POST request to a tenant that has no data files yet.  
**Use Case:** [UC-S1: Tenant Provisioning](./api-use-cases-server.md#uc-s1-tenant-provisioning)

### Process

1. Client sends POST with new `tid`
2. Server validates tenant ID format (`[a-zA-Z0-9_-]{1,30}`)
3. Server creates CSV file on first write (atomic)
4. No explicit provisioning needed

### Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Client
    participant E as entries.php
    participant D as data/
    participant U as util.php

    C->>E: POST /entries?tid=newteam<br/>Body: "/welcome | Hello!"
    E->>U: sanitize_id('newteam')
    U-->>E: 'newteam' (valid)
    
    E->>D: Check data/entries_newteam.csv
    Note over D: File does not exist
    
    E->>U: log_info("Creating new tenant: newteam")
    E->>D: fopen('entries_newteam.csv', 'a')
    D-->>E: File handle
    
    E->>D: flock(LOCK_EX)
    E->>D: fwrite(CSV row)
    E->>D: flock(LOCK_UN)
    E->>D: fclose()
    
    E->>D: append_notify('newteam', ['type'=>'entries'])
    
    E-->>C: 201 Created<br/>{"status":"ok","timestamp":"..."}
    
    Note over D: Tenant now exists
```

**Key Points:**
- No pre-provisioning or admin action required
- Tenant ID validation prevents directory traversal
- First write creates file atomically with `flock()`
- Empty tenants (no files) appear as 404 on GET until first write
- Sumup snapshots created on-demand on first read

**Config:**
- No tenant-specific config needed
- All tenants share `infopedia.cfg` settings
- Data isolation via filename: `entries_<tid>.csv`, `votes_<tid>.csv`

---

## 2.2 Add Entry

**Trigger:** `POST /entries` from client or API consumer.  
**Use Case:** [UC-S2: Entry Ingestion](./api-use-cases-server.md#uc-s2-entry-ingestion)

### Process

1. Parse and validate entry format
2. Append to CSV with server-generated timestamp
3. Invalidate cache
4. Notify connected clients
5. Update sumup snapshot (if enabled)

### Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Client
    participant E as entries.php
    participant T as util_throttle.php
    participant U as util_entry.php
    participant D as data/
    participant S as util_sumup.php
    participant Ca as util_cache.php
    participant N as append_notify

    C->>E: POST /entries?tid=X&sid=Y<br/>Body: "/topic/node | author:bob | Hello."
    
    E->>T: checkThrottle('X', max, window)
    T-->>E: true (allowed)
    
    E->>U: parseEntry("/topic/node | author:bob | Hello.")
    U->>U: Validate path, extract attrs, normalize type
    U-->>E: ['path'=>'/topic/node', 'attrs'=>['author'=>'bob'], ...]
    
    E->>E: $timestamp = gmdate('Y-m-d H:i:s')
    E->>E: $csv_row = "$timestamp,\"$entry\"\n"
    
    E->>D: fopen('entries_X.csv', 'a')
    E->>D: flock(LOCK_EX)
    E->>D: fwrite($csv_row)
    E->>D: flock(LOCK_UN)
    E->>D: fclose()
    
    E->>Ca: touchOutdated('entries.cache', 'X')
    Ca->>D: touch('entries_X.cache.outdated')
    
    E->>N: append_notify('X', ['type'=>'entries'])
    N->>D: fwrite to notify_X.jsonl
    
    alt sumup enabled
        E->>S: sumup_append_entry(...)
        S->>D: flock + update entries_X.sumup.json
    end
    
    E-->>C: 201 Created<br/>{"status":"ok","timestamp":"2026-07-11 12:34:56"}
```

**Validation:**

```php
// In util_entry.php::parseEntry()
if (!preg_match('#^/[a-zA-Z0-9_/-]+$#', $path)) {
    throw new Exception("Invalid path format");
}
if (empty($content)) {
    throw new Exception("Content required");
}
// Type normalization: append '.' if last char not in [.!?>-]
```

**Throttling:**
- Config: `throttle_max`, `throttle_window`, `throttle_key` (sid or ip)
- State: `data/throttle_<key>.dat` — `<window_start>:<count>`
- On 429: `Retry-After: <seconds>` header + error envelope

**Cache Invalidation:**
- `touchOutdated()` creates `.outdated` marker file
- Next GET sees stale cache + outdated marker → rebuild
- Sumup snapshot updated incrementally (no rebuild unless `?refresh=1`)

---

## 2.3 Add Vote

**Trigger:** `POST /votes` from client.  
**Use Case:** [UC-S3: Vote Recording](./api-use-cases-server.md#uc-s3-vote-recording)

### Process

1. Validate vote format: `votes:<sid>:<n>` attribute
2. Append to votes CSV
3. Update sumup snapshot (incremental aggregation)
4. Notify connected clients

### Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Client
    participant V as votes.php
    participant T as util_throttle.php
    participant U as util_entry.php
    participant D as data/
    participant S as util_sumup.php
    participant N as append_notify

    C->>V: POST /votes?tid=X&sid=Y<br/>Body: "/poll/q1 | votes:Y:1 | Good?"
    
    V->>T: checkThrottle('Y', max, window)
    T-->>V: true
    
    V->>U: parseEntry(...)
    U->>U: Validate votes:<sid>:<n> format
    U-->>V: ['path'=>'/poll/q1', 'attrs'=>['votes'=>'Y:1'], ...]
    
    V->>D: fopen('votes_X.csv', 'a')
    V->>D: flock(LOCK_EX) + fwrite() + flock(LOCK_UN)
    
    V->>S: sumup_update('votes_X.csv', 'votes_X.sumup.json', $sid)
    Note over S: Read tail after last offset<br/>Aggregate into snapshot<br/>Save new offset
    S->>D: flock + update votes_X.sumup.json
    
    V->>N: append_notify('X', ['type'=>'votes'])
    
    V-->>C: 201 Created<br/>{"status":"ok","timestamp":"..."}
```

**Vote Aggregation (Sumup):**

```json
{
  "src": "votes_X.csv",
  "offset": 1234,
  "nodes": {
    "/poll/q1": {
      "votes": {
        "sid_abc": {"count": 3, "signers": ["sid_abc"]},
        "sid_def": {"count": -1, "signers": ["sid_def"]}
      },
      "newest_ts": "2026-07-11 12:34:56",
      "content": "Good?"
    }
  }
}
```

**Projection (in GET /votes):**
- Own session: `votes:<sid>:<own_total>`
- Others: `votes:others:<sum_of_all_other_sids>`
- Result: `/poll/q1 | votes:sid_abc:3 | votes:others:-1 | Good?`

**Key Points:**
- Votes CSV is append-only (no deletion)
- Sumup snapshot enables O(tail) aggregation instead of O(full CSV)
- Concurrent writes safe via `flock(LOCK_EX)`
- Vote changes always trigger full `/votes` re-fetch (no incremental client-side aggregation yet)

---

## 2.4 Add Notification

**Trigger:** Explicit server-side call to `append_notify()` or automatic on CSV write.  
**Use Case:** [UC-S4: Notification Broadcasting](./api-use-cases-server.md#uc-s4-notification-broadcasting)

### Process

1. Server code calls `append_notify($tid, $event)`
2. Event written to `notify_<tid>.jsonl` (one JSON object per line)
3. All connected long-poll clients receive event on next wake cycle

### Sequence Diagram

```mermaid
sequenceDiagram
    participant S as Server Code (any)
    participant U as util.php
    participant D as data/notify_X.jsonl
    participant N1 as notify.php (client 1)
    participant N2 as notify.php (client 2)
    participant C1 as Client 1
    participant C2 as Client 2

    Note over S: Admin script or custom endpoint
    S->>U: append_notify('X', ['type'=>'message', 'text'=>'Maintenance in 5 min'])
    
    U->>U: $ts = gmdate('Y-m-d H:i:s')
    U->>U: $event['ts'] = $ts
    U->>U: $json = json_encode($event)
    
    U->>D: fopen('notify_X.jsonl', 'a')
    U->>D: flock(LOCK_EX)
    U->>D: fwrite($json . "\n")
    U->>D: flock(LOCK_UN)
    U->>D: fclose()
    
    Note over N1,N2: Poll loops wake (next 2s cycle)
    
    par Client 1
        N1->>D: Read notify_X.jsonl (lines with ts > client_since)
        D-->>N1: {"type":"message","text":"...","ts":"..."}
        N1-->>C1: 200 OK [event]
        C1->>C1: showToast('Maintenance in 5 min', 'info')
    and Client 2
        N2->>D: Read notify_X.jsonl (lines with ts > client_since)
        D-->>N2: Same event
        N2-->>C2: 200 OK [event]
        C2->>C2: showToast(...)
    end
```

**Event Types:**

| Type | Payload | Trigger |
|------|---------|---------|
| `entries` | `{"type":"entries","ts":"..."}` | Automatic on POST /entries or CSV write |
| `votes` | `{"type":"votes","ts":"..."}` | Automatic on POST /votes or CSV write |
| `message` | `{"type":"message","text":"...","ts":"..."}` | Explicit `append_notify()` call |

**File Format (notify_X.jsonl):**

```
{"type":"entries","ts":"2026-07-11 12:34:56"}
{"type":"votes","ts":"2026-07-11 12:35:10"}
{"type":"message","text":"System maintenance in 5 minutes","ts":"2026-07-11 12:40:00"}
```

**Key Points:**
- JSONL format: one event per line, no commas, no array wrapper
- Events include `ts` field for filtering by `since`
- Automatic append on CSV writes (belt-and-suspenders with mtime check)
- No rotation yet (file grows unbounded) — future: see §2.5

---

## 2.5 Switch Notification Partition

**Trigger:** Notification file exceeds size limit or retention time.  
**Use Case:** [UC-S5: Notification Partition Rotation](./api-use-cases-server.md#uc-s5-notification-partition-rotation)  
**Status:** **Not yet implemented** — planned for incremental notify feature.

### Planned Process

1. Active partition: `notify_<tid>_a.jsonl`
2. Inactive partition: `notify_<tid>_b.jsonl`
3. Both partitions written in parallel (dual-write)
4. Rotation triggered when active partition is old + oversized
5. Swap: inactive becomes active, old active archived/deleted

### Planned Sequence Diagram

```mermaid
sequenceDiagram
    participant W as Writer (entries.php)
    participant U as util.php
    participant DA as notify_X_a.jsonl (active)
    participant DB as notify_X_b.jsonl (inactive)
    participant N as notify.php

    Note over W: Dual-write always
    W->>U: append_notify('X', event)
    U->>DA: fwrite(event) — active
    U->>DB: fwrite(event) — inactive (parallel)
    
    Note over N: Poll loop checks rotation condition
    N->>DA: filemtime() + filesize()
    Note over N: mtime > retention AND size > limit
    
    N->>N: Swap partition pointers<br/>active ← inactive<br/>inactive ← active
    
    opt Cleanup
        N->>DA: unlink() — old active
        N->>DB: truncate or keep as archive
    end
    
    Note over W,DB: Next writes go to<br/>new active (was inactive)
```

**Rotation Conditions:**

```php
// In notify.php (planned)
$retention = (int)($config['notify_retention'] ?? 60);  // seconds
$max_size = (int)($config['notify_max_size'] ?? 1048576);  // bytes

$active_file = "data/notify_{$tid}_a.jsonl";
$mtime = filemtime($active_file);
$size = filesize($active_file);

if ($size > 0 && (time() - $mtime > $retention) && $size > $max_size) {
    rotate_partition($tid);
}
```

**Key Points:**
- Dual-write ensures no message loss during rotation
- Inactive partition is pre-filled with messages beyond retention window
- Clients outside retention window get `re_sync_required` event
- Rotation is atomic from client perspective (no mid-rotation corruption)

---

## 2.6 Full Data from Entries/Votes Log

**Trigger:** GET request without `since` parameter, or cache rebuild.  
**Use Case:** [UC-S6: Full Data Export](./api-use-cases-server.md#uc-s6-full-data-export)

### Process (Entries)

1. Read full CSV file
2. Sort by path (column 1), dedup by latest timestamp
3. Apply delete markers (`--`)
4. Format and respond

### Sequence Diagram (Entries, sumup disabled)

```mermaid
sequenceDiagram
    participant C as Client
    participant E as entries.php
    participant Ca as util_cache.php
    participant U as util_entry.php
    participant F as util_format.php
    participant D as data/

    C->>E: GET /entries?tid=X&sid=Y
    
    E->>Ca: isCacheValid('entries.cache', 'X', max_age)
    Ca->>D: Check entries_X.cache + entries_X.cache.outdated
    Ca-->>E: false (outdated or missing)
    
    E->>D: file_get_contents('entries_X.csv')
    D-->>E: Full CSV (raw)
    
    E->>U: sortCsvData($csv, dedup=true)
    Note over U: Parse all rows<br/>Sort by path<br/>Dedup (latest wins)<br/>Remove delete markers
    U-->>E: Sorted, deduped CSV
    
    E->>F: csv_to_json($csv)
    F-->>E: JSON array
    
    E->>Ca: writeCache('entries_X.cache', $json)
    
    E-->>C: 200 OK
```

### Process (Votes)

1. Read votes CSV file
2. Load/update sumup snapshot (aggregate by path + sid)
3. Project for requesting session (`votes:<sid>:<n>` + `votes:others:<n>`)
4. Format and respond

### Sequence Diagram (Votes)

```mermaid
sequenceDiagram
    participant C as Client
    participant V as votes.php
    participant S as util_sumup.php
    participant F as util_format.php
    participant D as data/

    C->>V: GET /votes?tid=X&sid=Y
    
    V->>S: sumup_update('votes_X.csv', 'votes_X.sumup.json', 'Y')
    
    S->>D: fopen('votes_X.sumup.json', 'r+')
    alt Snapshot exists
        S->>D: json_decode()
        S->>D: Read votes_X.csv tail (after offset)
    else No snapshot
        S->>D: Read votes_X.csv (full)
    end
    
    Note over S: Aggregate votes by path + sid
    
    S->>D: flock(LOCK_EX) + fwrite(snapshot) + flock(LOCK_UN)
    S-->>V: Updated snapshot
    
    V->>S: votes_sumup_project($snapshot, 'Y')
    S-->>V: CSV string
    
    V->>F: csv_to_json($csv)
    F-->>V: JSON array
    
    V-->>C: 200 OK
```

**Key Points:**
- Sumup snapshots enable O(tail) instead of O(full file)
- Output is byte-compatible with `sortCsvData()` (verified by golden tests)
- `?refresh=1` forces snapshot discard and full rebuild (throttled)
- Cache sits on top of sumup (two-tier optimization)

---

## 2.7 SumUp Operations

**Use Case:** [UC-S7: SumUp Snapshot Management](./api-use-cases-server.md#uc-s7-sumup-snapshot-management)

SumUp snapshots enable incremental aggregation of CSV append-only logs. Four operations:

### 2.7.1 Init (First Read)

**Trigger:** First GET request to a tenant, or after `just sumup-clean`.

```mermaid
sequenceDiagram
    participant E as entries.php / votes.php
    participant S as util_sumup.php
    participant D as data/

    E->>S: sumup_update('votes_X.csv', 'votes_X.sumup.json', sid)
    S->>D: fopen('votes_X.sumup.json', 'r+')
    Note over D: File does not exist
    
    S->>D: Read full votes_X.csv
    Note over S: Parse all rows<br/>Aggregate into snapshot
    
    S->>D: fopen('votes_X.sumup.json', 'w')
    S->>D: flock(LOCK_EX)
    S->>D: fwrite(json_encode(['src'=>'votes_X.csv', 'offset'=><eof>, 'nodes'=>...]))
    S->>D: flock(LOCK_UN)
    
    S-->>E: Snapshot
```

**Key Points:**
- Full CSV read on first access (cold start)
- Subsequent reads use incremental mode
- Init is O(full file), but only happens once per tenant

---

### 2.7.2 Running Delta (Incremental Update)

**Trigger:** GET request when snapshot exists and is valid.

```mermaid
sequenceDiagram
    participant E as entries.php / votes.php
    participant S as util_sumup.php
    participant D as data/

    E->>S: sumup_update('votes_X.csv', 'votes_X.sumup.json', sid)
    S->>D: fopen('votes_X.sumup.json', 'r+')
    S->>D: json_decode()
    Note over S: Read offset = 1234
    
    S->>D: fopen('votes_X.csv', 'r')
    S->>D: fseek(1234)
    S->>D: Read tail only
    
    Note over S: Merge tail rows into<br/>existing snapshot nodes
    
    S->>D: fseek(0) on sumup file
    S->>D: flock(LOCK_EX)
    S->>D: ftruncate(0) + fwrite(updated snapshot)
    S->>D: flock(LOCK_UN)
    
    S-->>E: Updated snapshot
```

**Key Points:**
- Read CSV tail only (after stored offset)
- O(tail) complexity, not O(full file)
- Offset advances to new EOF
- Concurrent reads safe (older-snapshot-wins on conflict)

---

### 2.7.3 Increment (Append on Write)

**Trigger:** POST /votes or POST /entries (if sumup enabled for entries).

```mermaid
sequenceDiagram
    participant V as votes.php
    participant D as data/votes_X.csv
    participant S as util_sumup.php
    participant DS as data/votes_X.sumup.json

    V->>D: flock(LOCK_EX) + fwrite(new row) + flock(LOCK_UN)
    
    V->>S: sumup_append_vote($tid, $parsed_entry)
    
    S->>DS: fopen('votes_X.sumup.json', 'r+')
    S->>DS: flock(LOCK_EX) + json_decode()
    
    Note over S: Merge single row into snapshot<br/>Update vote count for path+sid<br/>Update newest_ts if newer
    
    S->>DS: fseek(0) + ftruncate(0) + fwrite()
    S->>DS: flock(LOCK_UN)
    
    S-->>V: Done
```

**Key Points:**
- Append updates snapshot immediately (no stale reads)
- Single row merge is O(1)
- Lock ordering: CSV first, then sumup (prevents deadlock)
- Offset incremented by byte count of appended row

---

### 2.7.4 Re-create (Full Rebuild)

**Trigger:** `?refresh=1` query param (throttled), or corrupted snapshot detected.

```mermaid
sequenceDiagram
    participant C as Client
    participant E as entries.php
    participant T as util_throttle.php
    participant S as util_sumup.php
    participant D as data/

    C->>E: GET /entries?tid=X&sid=Y&refresh=1
    
    E->>T: checkThrottle(sid, max, window)
    T-->>E: true (allowed)
    
    E->>D: unlink('entries_X.sumup.json')
    Note over D: Snapshot deleted
    
    E->>S: sumup_update('entries_X.csv', 'entries_X.sumup.json', null)
    Note over S: No snapshot → full rebuild
    
    S->>D: Read full entries_X.csv
    Note over S: Parse all rows<br/>Build snapshot from scratch
    
    S->>D: fopen('entries_X.sumup.json', 'w')
    S->>D: flock(LOCK_EX) + fwrite() + flock(LOCK_UN)
    
    E->>D: writeCache(fresh data)
    E-->>C: 200 OK (rebuilt data)
```

**Key Points:**
- Throttled to prevent abuse (same as cache refresh)
- Deletes snapshot before rebuild (ensures clean state)
- Full rebuild is O(full file), but rare
- Used for recovery after corruption or offset mismatch

**Offset Mismatch Detection:**

```php
// In util_sumup.php
$snapshot = json_decode(file_get_contents($sumup_file), true);
$csv_size = filesize($csv_file);

if ($snapshot['offset'] > $csv_size) {
    // CSV was truncated or replaced → full rebuild
    unlink($sumup_file);
    return sumup_init($csv_file, $sumup_file, $sid);
}
```

---

**Last Updated:** 2026-07-11  
**Related:** [API Use Cases § Server-Side](./api-use-cases-server.md) · [Backend Communication](./backend-communication.md)

