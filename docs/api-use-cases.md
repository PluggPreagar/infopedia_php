# InfoPedia API — Technical Use Cases

> **Audience:** Backend developers, API integrators, operations  
> **Status:** Living document  
> **Related:** [`api-operations.md`](./api-operations.md) · [`app2-use-cases.md`](./app2-use-cases.md) · [`api_spec.md`](../.ai/api_spec.md)

---

## Purpose

This document defines **technical use cases** for the InfoPedia API from a systems integration and operations perspective. For user-facing use cases, see [`app2-use-cases.md`](./app2-use-cases.md).

Each use case includes:
- **Trigger:** What initiates the scenario
- **Preconditions:** System state before scenario
- **Flow:** Step-by-step technical process
- **Postconditions:** System state after scenario
- **Error Cases:** What can go wrong and how to handle it

---

## Client-Side Use Cases

### UC-C1: Initial App Load

**Trigger:** User opens the app for the first time or after clearing browser storage.

**Preconditions:**
- Client has no stored `sid` or `latestTimestamp`
- Network connectivity available
- Tenant ID known (from URL or default)

**Flow:**
1. Client generates new session ID: `sid = crypto.randomUUID()`
2. Client sends `GET /entries?tid=<tenant>&sid=<sid>` (no `since` parameter)
3. Server responds with full dataset in JSON format
4. Client stores data in memory: `window.data = {}`
5. Client extracts `latestTimestamp` from response (newest outer timestamp)
6. Client sends `GET /votes?tid=<tenant>&sid=<sid>`
7. Server responds with aggregated votes
8. Client merges votes into data store
9. Client renders initial UI
10. Client establishes notify connection: `GET /notify?tid=<tenant>` (no `since`)
11. Notify connection held by server (≤25s)

**Postconditions:**
- `window.data` populated with all entries
- `window.votesData` populated with all votes
- `window.latestTimestamp` set
- `window.sid` persisted to localStorage
- `pollActive = true`, notify loop running
- UI showing current data

**Error Cases:**

| Error | Status | Client Action |
|-------|--------|---------------|
| Tenant not found | 404 | Show "Tenant does not exist" toast, allow creation on first write |
| Invalid tenant ID | 400 `INVALID_TID` | Show error, prompt for valid ID |
| Network error | — | Retry with exponential backoff (5s, 10s, 20s) |
| Server unavailable | 503 | Show "Server unavailable" toast, retry after 30s |

**Related:** [api-operations.md § 1.1](./api-operations.md#11-initial-client-sync-cold-start)

---

### UC-C2: Incremental Sync (Within Retention)

**Trigger:** Notify connection returns `{"type":"entries"}` event.

**Preconditions:**
- Client has `latestTimestamp` stored (e.g., `2026-07-11 12:34:56`)
- Client is online and has active notify connection
- Retention window not exceeded (currently unlimited, future: ~60s)

**Flow:**
1. Notify connection receives: `[{"type":"entries","ts":"2026-07-11 12:40:00"}]`
2. Client sends `GET /entries?tid=<tenant>&sid=<sid>&since=<latestTimestamp>`
3. Server filters CSV rows where outer timestamp > `since`
4. Server responds with delta entries only
5. Client calls `addData(delta)`:
   - For each entry: `data[path] = entry` (merge by path, overwrite)
   - Update `latestTimestamp` to newest in delta
6. Client calls `updateView()` to re-render affected cards
7. Client reconnects notify: `GET /notify?tid=<tenant>&since=<newLatestTimestamp>`

**Postconditions:**
- `window.data` updated with new/changed entries
- `window.latestTimestamp` advanced
- UI reflects changes
- Notify connection re-established

**Error Cases:**

| Error | Status | Client Action |
|-------|--------|---------------|
| Network error mid-fetch | — | Retry GET /entries with same `since` (idempotent) |
| Parse error in delta | — | Log error, skip malformed entry, continue with valid entries |
| Timestamp inversion | — | Use max(current, received) to prevent backward time travel |

**Related:** [api-operations.md § 1.2](./api-operations.md#12-catchup-during-retention-time)

---

### UC-C3: Full Re-Sync (Outside Retention)

**Trigger:** Notify connection returns `{"type":"re_sync_required"}` event (future), or client detects excessive staleness.

**Preconditions:**
- Client has `latestTimestamp` older than retention window
- OR: Notify response indicates re-sync needed
- OR: Client heuristic (e.g., offline > 10 minutes)

**Flow:**
1. Client detects re-sync condition
2. Client logs warning: `"Re-sync required, discarding local data"`
3. Client clears `window.data`, `window.votesData`, `window.latestTimestamp`
4. Client executes **UC-C1 (Initial App Load)** flow (steps 2–11)
5. Client re-renders entire UI (not incremental update)

**Postconditions:**
- Identical to UC-C1 postconditions
- User sees brief "Reloading data" toast
- No stale data in memory

**Error Cases:**
- Same as UC-C1

**Related:** [api-operations.md § 1.3](./api-operations.md#13-catchup-after-retention-time-long-offline)

---

### UC-C4: Real-Time Event Handling

**Trigger:** Notify connection returns event array (200 OK).

**Preconditions:**
- Client has active notify long-poll connection
- Client is in foreground (not `document.hidden`)

**Flow:**

#### Event Type: `entries`
1. Receive: `[{"type":"entries","ts":"2026-07-11 12:40:00"}]`
2. Execute **UC-C2 (Incremental Sync)** flow

#### Event Type: `votes`
1. Receive: `[{"type":"votes","ts":"2026-07-11 12:40:05"}]`
2. Client sends `GET /votes?tid=<tenant>&sid=<sid>` (no `since`)
3. Server responds with full aggregated votes
4. Client calls `addVotesData(response)`:
   - Replace `window.votesData` completely (not merge)
   - Recalculate vote display on all visible cards
5. Client calls `updateView()`

#### Event Type: `message`
1. Receive: `[{"type":"message","text":"System maintenance in 5 min","ts":"..."}]`
2. Client calls `showToast(event.text, 'info')`
3. No data fetch, no view update

#### Multiple Events in Same Response
1. Receive: `[{"type":"entries","ts":"..."},{"type":"votes","ts":"..."}]`
2. Process sequentially: entries first, then votes
3. Single `updateView()` call after all processed

**Postconditions:**
- All events processed
- UI updated to reflect changes
- Notify connection reconnected with updated `since`

**Error Cases:**

| Error | Client Action |
|-------|---------------|
| Unknown event type | Log warning, skip event, continue with next |
| Malformed event JSON | Log error, skip event, continue |
| Fetch failure during event handling | Retry fetch (same as UC-C2 error handling), reconnect notify |

**Related:** [api-operations.md § 1.4](./api-operations.md#14-receiving-real-time-notifications)

---

### UC-C5: Notify Timeout (204 No Content)

**Trigger:** Notify connection held for full `poll_timeout` with no changes.

**Preconditions:**
- Client has active notify connection
- No CSV mtime changes during hold period (~25s)

**Flow:**
1. Client sends `GET /notify?tid=<tenant>&since=<latestTimestamp>`
2. Server poll loop runs for 25 seconds, checking every 2s
3. No file mtime changes detected
4. Server responds: `204 No Content` (empty body)
5. Client fetch promise resolves with `res.status === 204`
6. Client immediately reconnects: `GET /notify?tid=<tenant>&since=<latestTimestamp>` (same `since`)
7. Loop continues

**Postconditions:**
- Notify connection re-established
- No data changes
- No user-visible effect (no toast, no flicker)

**Error Cases:**
- Network error: treat as AbortError, wait 5s, reconnect
- Server 503: escalate to error toast, wait 30s, reconnect

**Related:** [api-operations.md § 1.4](./api-operations.md#14-receiving-real-time-notifications)

---

### UC-C6: Background/Foreground Transitions

**Trigger:** User switches tabs or minimizes browser.

**Preconditions:**
- Notify connection active
- `pollActive = true`

**Flow:**

#### On Background (`document.hidden = true`)
1. `visibilitychange` event fires
2. Client checks: `if (document.hidden) stopLongPoll()`
3. Client aborts notify connection: `pollController.abort()`
4. Client sets `pollActive = false`
5. Poll loop exits: `if (!pollActive) return`

#### On Foreground (`document.hidden = false`)
1. `visibilitychange` event fires
2. Client checks: `if (!document.hidden) startNotifyPoll()`
3. Client sets `pollActive = true`
4. Client spawns new poll loop with fresh `pollGeneration`
5. Immediate reconnect: `GET /notify?tid=<tenant>&since=<latestTimestamp>`

**Postconditions:**
- Background: no active HTTP connections, battery-friendly
- Foreground: notify connection re-established, catchup happens automatically

**Error Cases:**
- If client was background for > retention window, UC-C3 (re-sync) triggered on foreground

**Related:** app2.html → `startNotifyPoll()`, `stopLongPoll()`

---

## Server-Side Use Cases

### UC-S1: Tenant Provisioning

**Trigger:** First `POST /entries` or `POST /votes` for a new tenant ID.

**Preconditions:**
- Tenant ID valid: `[a-zA-Z0-9_-]{1,30}`
- No existing `data/entries_<tid>.csv` file

**Flow:**
1. Client sends `POST /entries?tid=newteam` with entry body
2. Server validates `tid` with `sanitize_id()` (regex + length check)
3. Server checks `file_exists("data/entries_newteam.csv")` → false
4. Server calls `log_info("Creating new tenant: newteam")`
5. Server opens file: `fopen("data/entries_newteam.csv", "a")`
6. File created atomically by filesystem
7. Server acquires lock: `flock($fh, LOCK_EX)`
8. Server writes CSV row: `fwrite($fh, "$timestamp,\"$entry\"\n")`
9. Server releases lock: `flock($fh, LOCK_UN)`
10. Server closes file: `fclose($fh)`
11. Server calls `append_notify('newteam', ['type'=>'entries'])`
12. Server responds: `201 Created {"status":"ok","timestamp":"..."}`

**Postconditions:**
- File `data/entries_newteam.csv` exists with 1 row
- File `data/notify_newteam.jsonl` exists with 1 event
- Tenant ready for reads and writes
- No cache or sumup yet (created on first read)

**Error Cases:**

| Error | Status | Response |
|-------|--------|----------|
| Invalid tid (e.g., `../etc/passwd`) | 400 | `{"error":{"code":"INVALID_TID","message":"..."}}` |
| Filesystem error (permissions, disk full) | 500 | `{"error":{"code":"INTERNAL_ERROR","message":"..."}}` |
| Throttle limit exceeded | 429 | `{"error":{"code":"THROTTLED","message":"..."}}`<br/>`Retry-After: 42` |

**Related:** [api-operations.md § 2.1](./api-operations.md#21-new-tenant-initialization)

---

### UC-S2: Entry Ingestion

**Trigger:** `POST /entries` from client or API consumer.

**Preconditions:**
- Tenant exists (or will be created, see UC-S1)
- Throttle limit not exceeded
- Entry format valid

**Flow:**
1. Server receives POST with body: `/path/node | attr:value | timestamp | content.`
2. Server calls `checkThrottle($dir, $key, $max, $window)`:
   - Read `data/throttle_<key>.dat`
   - Check window + count
   - If exceeded: respond `429 Too Many Requests` + exit
   - Else: increment count, write back, continue
3. Server calls `parseEntry($body)`:
   - Validate path format: `/[a-zA-Z0-9_/-]+`
   - Extract attributes: `[a-zA-Z_]+:[^ ]`
   - Extract optional display timestamp
   - Extract content + type char
   - Normalize type (append `.` if missing)
   - Return structured array
4. Server generates outer timestamp: `$ts = gmdate('Y-m-d H:i:s')`
5. Server constructs CSV row: `"$ts,\"$entry\"\n"`
6. Server appends to CSV:
   - `fopen("data/entries_$tid.csv", "a")`
   - `flock(LOCK_EX)`
   - `fwrite($csv_row)`
   - `flock(LOCK_UN)`
   - `fclose()`
7. Server invalidates cache: `touchOutdated("entries.cache", $tid)`
   - Creates `data/entries_$tid.cache.outdated` marker file
8. Server notifies clients: `append_notify($tid, ['type'=>'entries'])`
   - Appends JSON line to `data/notify_$tid.jsonl`
9. If `sumup_enabled = true`:
   - `sumup_append_entry($tid, $parsed_entry)`
   - Update `data/entries_$tid.sumup.json` snapshot
10. Server responds: `201 Created {"status":"ok","timestamp":"$ts"}`

**Postconditions:**
- Entry appended to CSV
- Cache invalidated (will rebuild on next GET)
- Notify event written
- Sumup snapshot updated (if enabled)
- All connected clients receive `{"type":"entries"}` event within 2s

**Error Cases:**

| Error | Status | Response | Server Action |
|-------|--------|----------|---------------|
| Invalid path format | 400 | `INVALID_ENTRY` | Log warning, do not write |
| Empty content | 400 | `INVALID_ENTRY` | Log warning, do not write |
| Throttled | 429 | `THROTTLED` + `Retry-After` | Increment throttle count (already done in check) |
| Filesystem error | 500 | `INTERNAL_ERROR` | Log error, alert ops |

**Related:** [api-operations.md § 2.2](./api-operations.md#22-add-entry)

---

### UC-S3: Vote Recording

**Trigger:** `POST /votes` from client.

**Preconditions:**
- Tenant exists
- Throttle limit not exceeded
- Vote format valid: `votes:<sid>:<n>` attribute present

**Flow:**
1. Server receives POST with body: `/path/node | votes:<sid>:<n> | content.`
2. Server calls `checkThrottle(...)` (same as UC-S2 step 2)
3. Server calls `parseEntry($body)`:
   - Validate `votes:<sid>:<n>` format via regex: `votes:([a-zA-Z0-9_-]+):(-?\d+)`
   - Extract sid and vote value
   - Validate path and content (same as entries)
4. Server generates timestamp: `$ts = gmdate('Y-m-d H:i:s')`
5. Server constructs CSV row: `"$ts,\"$entry\"\n"`
6. Server appends to CSV:
   - `fopen("data/votes_$tid.csv", "a")`
   - `flock(LOCK_EX)`
   - `fwrite($csv_row)`
   - `flock(LOCK_UN)`
   - `fclose()`
7. Server updates sumup snapshot (always enabled for votes):
   - `sumup_append_vote($tid, $parsed_entry)`
   - Lock + read `data/votes_$tid.sumup.json`
   - Merge vote into path's vote count for sid
   - Update `newest_ts` and `content` if newer
   - Write back snapshot
8. Server notifies clients: `append_notify($tid, ['type'=>'votes'])`
9. Server responds: `201 Created {"status":"ok","timestamp":"$ts"}`

**Postconditions:**
- Vote appended to `votes_$tid.csv`
- Sumup snapshot updated (instant aggregation)
- Notify event written
- All connected clients receive `{"type":"votes"}` event within 2s
- Clients will re-fetch full `/votes` (no incremental aggregation yet)

**Error Cases:**

| Error | Status | Response | Server Action |
|-------|--------|----------|---------------|
| Invalid votes format (missing sid or n) | 400 | `INVALID_ENTRY` | Log warning, do not write |
| Non-numeric vote value | 400 | `INVALID_ENTRY` | Log warning, do not write |
| Throttled | 429 | `THROTTLED` | Return with `Retry-After` |
| Sumup corruption (offset mismatch) | 500 | `INTERNAL_ERROR` | Discard snapshot, rebuild on next read |

**Related:** [api-operations.md § 2.3](./api-operations.md#23-add-vote)

---

### UC-S4: Notification Broadcasting

**Trigger:** Explicit server-side call to `append_notify()` (e.g., admin script, custom endpoint).

**Preconditions:**
- Tenant exists (or notify file will be created on first append)
- Valid event structure: `['type'=>'message', 'text'=>'...']`

**Flow:**
1. Server code calls `append_notify($tid, $event)`
2. Function generates timestamp: `$event['ts'] = gmdate('Y-m-d H:i:s')`
3. Function encodes JSON: `$json = json_encode($event, JSON_UNESCAPED_UNICODE)`
4. Function opens file: `fopen("data/notify_$tid.jsonl", "a")`
5. Function locks: `flock($fh, LOCK_EX)`
6. Function writes: `fwrite($fh, $json . "\n")`
7. Function unlocks: `flock($fh, LOCK_UN)`
8. Function closes: `fclose($fh)`
9. All connected `notify.php` clients for this tenant wake on next 2s cycle
10. `notify.php` reads `notify_$tid.jsonl`, filters lines with `ts > client_since`
11. `notify.php` responds with matching events
12. Clients process event (e.g., show toast for `message` type)

**Postconditions:**
- Event persisted to `notify_$tid.jsonl`
- All connected clients receive event within 2s
- Event available for future clients (until file cleanup/rotation)

**Error Cases:**

| Error | Server Action |
|-------|---------------|
| Filesystem error (permissions) | Log error, fail silently (notification lost, clients unaware) |
| JSON encode error | Log error, skip event, continue |
| Invalid tid | Log warning, skip event |

**Usage Examples:**

```php
// Admin maintenance notification
append_notify('prod', ['type'=>'message', 'text'=>'Server restart in 10 minutes']);

// Custom event (future extension)
append_notify('team_x', ['type'=>'custom', 'action'=>'deploy_complete', 'version'=>'v2.1']);
```

**Related:** [api-operations.md § 2.4](./api-operations.md#24-add-notification)

---

### UC-S5: Notification Partition Rotation

**Trigger:** Active notify file exceeds size limit and retention time.

**Preconditions:**
- Incremental notify feature enabled (future)
- Active file: `data/notify_<tid>_a.jsonl`
- Inactive file: `data/notify_<tid>_b.jsonl`
- Active file size > `notify_max_size` (e.g., 1 MB)
- Active file age > `notify_retention` (e.g., 60s)

**Flow:**
1. `notify.php` poll loop checks rotation condition:
   ```php
   $active_file = "data/notify_{$tid}_a.jsonl";
   $mtime = filemtime($active_file);
   $size = filesize($active_file);
   $should_rotate = ($size > 0) && 
                    (time() - $mtime > $retention) && 
                    ($size > $max_size);
   ```
2. If rotation needed:
   - Lock both files in fixed order (A before B) to prevent deadlock:
     ```php
     $fh_a = fopen($active_file, 'r+');
     flock($fh_a, LOCK_EX);
     $fh_b = fopen($inactive_file, 'r+');
     flock($fh_b, LOCK_EX);
     ```
3. Swap partition pointers (stored in config or runtime state):
   ```php
   $temp = $active_file;
   $active_file = $inactive_file;
   $inactive_file = $temp;
   ```
4. Truncate new inactive file (was old active):
   ```php
   ftruncate($fh_a, 0);
   ```
5. Release locks: `flock(LOCK_UN)` on both, `fclose()` on both
6. Update runtime state to reflect new active/inactive
7. Future writes go to new active file (dual-write to both continues)

**Postconditions:**
- Old active file truncated (or archived)
- Inactive file promoted to active
- New inactive file ready for parallel writes
- No message loss (dual-write ensures coverage)
- Clients outside retention window will get `re_sync_required` event

**Error Cases:**

| Error | Server Action |
|-------|---------------|
| Lock acquisition timeout | Skip rotation this cycle, retry on next poll |
| Filesystem error during truncate | Log critical error, keep old files, alert ops |
| Corruption in either file | Discard both, rebuild from CSV mtime (fallback to current behavior) |

**Note:** This is a **planned feature**, not yet implemented. Current implementation: single `notify_<tid>.jsonl` file, no rotation.

**Related:** [api-operations.md § 2.5](./api-operations.md#25-switch-notification-partition)

---

### UC-S6: Full Data Export

**Trigger:** `GET /entries` or `GET /votes` without `since` parameter, or cache miss.

**Preconditions:**
- Tenant exists (CSV file present)
- OR: Empty tenant (404 response)

**Flow:**

#### Entries (sumup disabled)
1. Server receives `GET /entries?tid=X&sid=Y`
2. Server checks cache: `isCacheValid("entries.cache", "X", $max_age)`
   - Check mtime of `entries_X.cache` vs `entries_X.csv`
   - Check for `.outdated` marker file
   - If valid and no marker: serve from cache → exit
3. Cache invalid/missing:
4. Server reads full CSV: `$csv = file_get_contents("data/entries_X.csv")`
5. Server calls `sortCsvData($csv, $dedup = true)`:
   - Parse all rows
   - Sort by column 1 (path)
   - Dedup: keep latest per path
   - Remove delete markers (content ends with `--`)
   - Return reconstructed CSV
6. Server calls `csv_to_json($csv)`:
   - Parse CSV rows
   - Build JSON object: `{"/path": {"timestamp":"...", "message":"...", "attrs":{...}}}`
7. Server writes cache: `writeCache("entries_X.cache", $json)`
8. Server deletes outdated marker: `unlink("entries_X.cache.outdated")`
9. Server responds: `200 OK` + `Content-Type: application/json` + body

#### Entries (sumup enabled)
1. Steps 1-2 same as above
3. Cache invalid/missing:
4. Server calls `sumup_update("entries_X.csv", "entries_X.sumup.json", null)`:
   - Lock + read snapshot
   - If no snapshot: full CSV read → init snapshot
   - If snapshot exists: read CSV tail after `offset`
   - Merge tail into snapshot
   - Write snapshot back
5. Server calls `sumup_project($snapshot, 'entries')`:
   - Convert snapshot nodes back to CSV format
   - Output byte-identical to `sortCsvData()` result
6. Steps 6-9 same as above

#### Votes (always sumup)
1. Server receives `GET /votes?tid=X&sid=Y`
2. No cache check (votes not cached, always fresh from sumup)
3. Server calls `sumup_update("votes_X.csv", "votes_X.sumup.json", "Y")`:
   - Lock + read snapshot
   - If no snapshot: full CSV read → aggregate → save snapshot
   - If snapshot exists: read CSV tail after `offset`
   - Aggregate tail votes into snapshot
   - Write snapshot back
4. Server calls `votes_sumup_project($snapshot, "Y")`:
   - For each path in snapshot:
     - Sum votes for sid "Y" → `votes:Y:<total>`
     - Sum votes for all other sids → `votes:others:<total>`
   - Reconstruct CSV row with aggregated attrs
5. Server calls `csv_to_json($csv)`
6. Server responds: `200 OK` + JSON

**Postconditions:**
- Client receives full dataset (all entries or all aggregated votes)
- Cache written (entries only)
- Sumup snapshot updated to latest CSV offset

**Error Cases:**

| Error | Status | Response | Server Action |
|-------|--------|----------|---------------|
| CSV file not found | 404 | `{"error":{"code":"NOT_FOUND","message":"Tenant not found"}}` | No action (tenant doesn't exist) |
| CSV parse error (malformed line) | 500 | `INTERNAL_ERROR` | Log error, skip bad line, continue with valid lines |
| Sumup corruption (offset > CSV size) | — | — | Discard snapshot, full rebuild |
| Filesystem error (permissions) | 503 | `UPSTREAM_UNAVAILABLE` | Log error, serve from cache if available, else 503 |

**Related:** [api-operations.md § 2.6](./api-operations.md#26-full-data-from-entriesvotes-log)

---

### UC-S7: SumUp Snapshot Management

**Status:** Implemented for votes (always on), entries (opt-in via config).

#### UC-S7a: Init (First Read)

**Trigger:** First `GET` request for a tenant, or snapshot file missing.

**Flow:**
1. Server calls `sumup_update("votes_X.csv", "votes_X.sumup.json", $sid)`
2. Snapshot file check: `file_exists("votes_X.sumup.json")` → false
3. Full CSV read: `$csv = file_get_contents("votes_X.csv")`
4. Parse all rows, aggregate into snapshot structure:
   ```php
   $snapshot = [
       'src' => 'votes_X.csv',
       'offset' => strlen($csv),  // EOF
       'nodes' => [
           '/path' => [
               'votes' => ['sid_a' => ['count' => 3, 'signers' => [...]]],
               'newest_ts' => '...',
               'content' => '...'
           ]
       ]
   ];
   ```
5. Write snapshot: `file_put_contents("votes_X.sumup.json", json_encode($snapshot))`
6. Return snapshot for projection

**Postconditions:**
- Snapshot file created with full aggregation
- Offset set to CSV EOF
- Future reads use incremental mode (O(tail))

---

#### UC-S7b: Running Delta (Incremental Update)

**Trigger:** `GET` request when snapshot exists and is valid.

**Flow:**
1. Server calls `sumup_update(...)`
2. Read snapshot: `$snapshot = json_decode(file_get_contents($sumup_file), true)`
3. Check offset validity: `$snapshot['offset'] <= filesize($csv_file)` (else rebuild)
4. Open CSV: `$fh = fopen($csv_file, 'r')`
5. Seek to offset: `fseek($fh, $snapshot['offset'])`
6. Read tail: `$tail = stream_get_contents($fh)`
7. Parse tail rows, merge into snapshot nodes:
   - For votes: aggregate into existing sid counts
   - For entries: replace path node if newer timestamp
8. Update offset: `$snapshot['offset'] += strlen($tail)`
9. Lock + write snapshot: `flock(LOCK_EX)` + `file_put_contents()`
10. Return updated snapshot

**Postconditions:**
- Snapshot updated with tail data only (O(tail) complexity)
- Offset advanced to new CSV EOF
- No full CSV re-read

---

#### UC-S7c: Increment (Append on Write)

**Trigger:** `POST /votes` or `POST /entries` (if sumup enabled).

**Flow:**
1. After CSV append in UC-S2/UC-S3:
2. Server calls `sumup_append_vote($tid, $parsed_entry)` (or `sumup_append_entry()`)
3. Lock snapshot: `fopen($sumup_file, 'r+')` + `flock(LOCK_EX)`
4. Read snapshot: `json_decode()`
5. Merge single entry into snapshot:
   - For votes: add to sid's count, update signers list
   - For entries: replace path node if newer timestamp
6. Update offset: `+= strlen($csv_row)`
7. Truncate + write: `ftruncate(0)` + `fwrite(json_encode($snapshot))`
8. Unlock: `flock(LOCK_UN)` + `fclose()`

**Postconditions:**
- Snapshot updated immediately (no stale reads)
- Next GET uses fresh snapshot (O(0) for this write)

---

#### UC-S7d: Re-create (Full Rebuild)

**Trigger:** `GET` request with `?refresh=1`, or snapshot corruption detected.

**Flow:**
1. Server receives `GET /votes?tid=X&sid=Y&refresh=1`
2. Server checks throttle (same as write throttle)
3. Server deletes snapshot: `unlink("votes_X.sumup.json")`
4. Server calls `sumup_update(...)` → triggers UC-S7a (Init) flow
5. Full rebuild from CSV
6. Respond with fresh data

**Postconditions:**
- Snapshot rebuilt from scratch
- Any corruption resolved
- Offset reset to current CSV EOF

**Error Cases:**

| Error | Server Action |
|-------|---------------|
| Offset > CSV size (CSV truncated) | Auto-trigger rebuild (discard snapshot, run init) |
| JSON parse error in snapshot | Auto-trigger rebuild |
| Lock acquisition timeout | Retry with exponential backoff, max 3 attempts |

**Related:** [api-operations.md § 2.7](./api-operations.md#27-sumup-operations)

---

## Integration Use Cases

### UC-I1: External System Writes Entry

**Trigger:** External system (not app2.html) sends `POST /entries` via API.

**Preconditions:**
- External system has valid tenant ID
- API authentication (if implemented, future)

**Flow:**
1. External system sends:
   ```bash
   curl -X POST "https://infopedia.example.com/entries?tid=prod" \
     -H "Content-Type: text/plain; charset=utf-8" \
     -d "/events/deploy | author:ci_bot | 2026-07-11 12:00:00 | Deployed v2.1.0 successfully."
   ```
2. Server processes as UC-S2 (Entry Ingestion)
3. Server responds `201 Created`
4. All connected app2.html clients receive `{"type":"entries"}` event
5. Clients fetch delta, see new entry

**Postconditions:**
- Entry visible to all users
- No distinction between human and bot entries (unless marked via `author` attr)

**Use Cases:**
- CI/CD deployment notifications
- Monitoring system alerts
- Scheduled task reports

**Related:** [api-operations.md § 2.2](./api-operations.md#22-add-entry)

---

### UC-I2: External System Reads Data

**Trigger:** External system needs to analyze or export InfoPedia data.

**Flow:**

#### CSV Export
```bash
curl "https://infopedia.example.com/entries?tid=prod&format=csv" > entries_prod.csv
```

Server responds with:
```csv
Timestamp,entry
2026-07-11 12:00:00,"/events/deploy | author:ci_bot | Deployed v2.1.0."
```

#### JSON Export
```bash
curl "https://infopedia.example.com/entries?tid=prod&format=json" | jq .
```

Server responds with:
```json
{
  "/events/deploy": {
    "timestamp": "2026-07-11 12:00:00",
    "message": "Deployed v2.1.0.",
    "attrs": {"author": "ci_bot"},
    "votes": {}
  }
}
```

**Use Cases:**
- Data backup scripts
- Analytics pipelines
- Report generation
- External search indexing

---

### UC-I3: Tenant Migration

**Trigger:** Need to copy data from one tenant to another, or merge tenants.

**Flow:**
1. Export source tenant:
   ```bash
   curl "https://infopedia.example.com/entries?tid=source&format=csv" > source_entries.csv
   curl "https://infopedia.example.com/votes?tid=source&format=csv" > source_votes.csv
   ```
2. Transform CSV if needed (e.g., path prefix changes)
3. Import to target tenant (line by line):
   ```bash
   while IFS=',' read -r ts entry; do
     curl -X POST "https://infopedia.example.com/entries?tid=target" \
       -H "Content-Type: text/plain" \
       -d "$entry"
   done < source_entries.csv
   ```
4. Or: direct CSV file copy (if tenants on same server):
   ```bash
   cat data/entries_source.csv >> data/entries_target.csv
   cat data/votes_source.csv >> data/votes_target.csv
   rm data/entries_target.sumup.json  # Force rebuild
   rm data/votes_target.sumup.json    # Force rebuild
   ```

**Postconditions:**
- Target tenant has all source data
- Sumup snapshots rebuilt on next read
- Connected clients see full dataset after re-sync

**Caveats:**
- Direct CSV copy bypasses throttling (admin only)
- Duplicate paths will be deduped (latest timestamp wins)
- Session IDs in votes preserved (may cause sid collision)

---

## Disaster Recovery Use Cases

### UC-DR1: Restore from CSV Backup

**Trigger:** Data loss (accidental deletion, filesystem corruption).

**Preconditions:**
- Backup CSV files available
- Server filesystem writable

**Flow:**
1. Stop web server (to prevent concurrent writes)
2. Copy backup CSV to `data/` directory:
   ```bash
   cp backup/entries_prod.csv data/entries_prod.csv
   cp backup/votes_prod.csv data/votes_prod.csv
   ```
3. Delete sumup snapshots (will rebuild):
   ```bash
   rm data/entries_prod.sumup.json
   rm data/votes_prod.sumup.json
   ```
4. Delete caches:
   ```bash
   rm data/entries_prod.cache
   rm data/entries_prod.cache.outdated
   ```
5. Start web server
6. First read will rebuild sumup and cache
7. Connected clients will re-sync (via notify mtime change or manual refresh)

**Postconditions:**
- Data restored to backup point
- Sumup and cache rebuilt automatically
- Users see restored state

**Data Loss:**
- All writes between backup and restore time

---

### UC-DR2: Recover from Snapshot Corruption

**Trigger:** Vote counts or entry display incorrect, suspect snapshot corruption.

**Flow:**
1. Admin triggers rebuild:
   ```bash
   curl "https://infopedia.example.com/votes?tid=prod&refresh=1"
   ```
   (Throttled, may need to wait if limit exceeded)
2. OR: manual delete + auto-rebuild on next read:
   ```bash
   rm data/votes_prod.sumup.json
   ```
3. Next GET request rebuilds from CSV
4. Verify correctness via test queries

**Postconditions:**
- Snapshot rebuilt from authoritative CSV
- Correct aggregation restored
- Users see correct data

---

### UC-DR3: Clean All Caches and Snapshots

**Trigger:** Suspected global corruption, or major version upgrade.

**Flow:**
1. Run admin command:
   ```bash
   just sumup-clean  # Deletes all *.sumup.json
   just cache-clean  # Deletes all *.cache* files
   ```
2. Or manual:
   ```bash
   cd data/
   rm *.sumup.json
   rm *.cache*
   ```
3. Next read request per tenant rebuilds from CSV
4. Monitor logs for rebuild messages:
   ```bash
   tail -f data/infopedia.log | grep "sumup_init"
   ```

**Postconditions:**
- All cached/snapshot state cleared
- Authoritative CSV files untouched
- System recovers automatically on next use

---

## Monitoring Use Cases

### UC-M1: Monitor Notify Connection Health

**Trigger:** Ops dashboard, scheduled health check.

**Flow:**
1. Test notify endpoint:
   ```bash
   time curl "https://infopedia.example.com/notify?tid=test" -m 30
   ```
2. Expected: hold for ~25s, then 204 or 200
3. If immediate response: notify.php not holding (config error)
4. If timeout: notify.php hanging (deadlock, filesystem issue)

**Alerts:**
- Response time < 20s → config error
- Response time > 30s → hang, restart needed
- HTTP 500 → check logs

---

### UC-M2: Monitor Write Latency

**Trigger:** Performance testing, production monitoring.

**Flow:**
1. Write entry with timing:
   ```bash
   time curl -X POST "https://infopedia.example.com/entries?tid=test" \
     -d "/perf/test | Test entry."
   ```
2. Expected: < 50ms for empty tenant, < 200ms for large tenant
3. If > 500ms: investigate:
   - Sumup snapshot size (should be < 1MB per tenant)
   - CSV file size (should append in O(1))
   - Filesystem performance (NFS latency, disk I/O)

**Metrics:**
- p50, p95, p99 write latency
- Throttle 429 rate (should be < 1% of requests)

---

### UC-M3: Monitor Sumup Snapshot Size

**Trigger:** Daily cron job, capacity planning.

**Flow:**
1. Check snapshot sizes:
   ```bash
   du -h data/*.sumup.json | sort -h
   ```
2. Expected: < 1MB per tenant (thousands of paths)
3. If > 10MB: investigate:
   - Excessive vote attributes (thousands of unique sids)
   - Very large content fields (should be trimmed)
   - Memory consumption on next rebuild

**Alerts:**
- Snapshot > 5MB → review tenant data
- Snapshot > 10MB → manual cleanup recommended

---

## Testing Use Cases

### UC-T1: End-to-End Test (Write + Notify + Read)

**Flow:**
1. Write entry:
   ```bash
   curl -X POST "http://localhost/entries?tid=e2e" \
     -d "/test/e2e | E2E test entry."
   ```
2. Long-poll notify (separate terminal):
   ```bash
   curl "http://localhost/notify?tid=e2e&since=2020-01-01%2000:00:00"
   ```
   Expected: immediate response `[{"type":"entries","ts":"..."}]`
3. Read delta:
   ```bash
   curl "http://localhost/entries?tid=e2e&since=2020-01-01%2000:00:00"
   ```
   Expected: new entry in response

**Assertions:**
- Notify latency < 2s
- Entry returned in delta
- Entry timestamp matches notify event timestamp

**Related:** `test/e2e.php`, `just e2e`

---

### UC-T2: Throttle Enforcement Test

**Flow:**
1. Configure aggressive throttle:
   ```ini
   [general]
   throttle_max = 3
   throttle_window = 10
   throttle_key = sid
   ```
2. Send 4 requests in rapid succession:
   ```bash
   for i in {1..4}; do
     curl -X POST "http://localhost/entries?tid=test&sid=test_sid" \
       -d "/test/$i | Request $i."
     echo "Request $i done"
   done
   ```
3. Expected:
   - Requests 1-3: `201 Created`
   - Request 4: `429 Too Many Requests` + `Retry-After: <seconds>`
4. Wait for window expiry, retry:
   ```bash
   sleep 11
   curl -X POST "http://localhost/entries?tid=test&sid=test_sid" \
     -d "/test/5 | Request 5."
   ```
   Expected: `201 Created` (window reset)

**Assertions:**
- 429 on 4th request
- `Retry-After` header present
- Error envelope: `{"error":{"code":"THROTTLED",...}}`
- New window allows requests

**Related:** `test/util_throttle_test.php`, `just unit`

---

**Last Updated:** 2026-07-11  
**Maintainer:** InfoPedia Backend Team

