# InfoPedia API — Server-Side Use Cases

> 📚 **Navigation:** [Docs Index](./README.md) → [API Use Cases](./api-use-cases.md) → **Server-Side**
>
> **Related Modules:** [Client-Side](./api-use-cases-client.md) · [Integration & DR](./api-use-cases-integration.md) · [Monitoring & Testing](./api-use-cases-monitoring.md)

Server-side technical use cases including tenant provisioning, data ingestion, notifications, and sumup operations. For operational context, see [API Operations § Server-Side](./api-operations-server.md).

---

## UC-S1: Tenant Provisioning

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

**Related:** [api-operations.md § 2.1](./api-operations-server.md#21-new-tenant-initialization)

---

## UC-S2: Entry Ingestion

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

**Related:** [api-operations.md § 2.2](./api-operations-server.md#22-add-entry)

---

## UC-S3: Vote Recording

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

**Related:** [api-operations.md § 2.3](./api-operations-server.md#23-add-vote)

---

## UC-S4: Notification Broadcasting

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

**Related:** [api-operations.md § 2.4](./api-operations-server.md#24-add-notification)

---

## UC-S5: Notification Partition Rotation

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
   - Lock both files in fixed order (A before B) to prevent deadlock
3. Swap partition pointers (stored in config or runtime state)
4. Truncate new inactive file (was old active)
5. Release locks and close files
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

**Related:** [api-operations.md § 2.5](./api-operations-server.md#25-switch-notification-partition)

---

## UC-S6: Full Data Export

**Trigger:** `GET /entries` or `GET /votes` without `since` parameter, or cache miss.

**Preconditions:**
- Tenant exists (CSV file present)
- OR: Empty tenant (404 response)

**Flow:**

### Entries (sumup disabled)
1. Server receives `GET /entries?tid=X&sid=Y`
2. Server checks cache: `isCacheValid("entries.cache", "X", $max_age)`
3. Cache invalid/missing → read full CSV, sort, dedup, format
4. Server calls `sortCsvData($csv, $dedup = true)`
5. Server calls `csv_to_json($csv)`
6. Server writes cache and responds

### Votes (always sumup)
1. Server receives `GET /votes?tid=X&sid=Y`
2. Server calls `sumup_update()` (no cache)
3. Server calls `votes_sumup_project($snapshot, "Y")`
4. Server calls `csv_to_json($csv)`
5. Server responds with aggregated votes

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

**Related:** [api-operations.md § 2.6](./api-operations-server.md#26-full-data-from-entriesvotes-log)

---

## UC-S7: SumUp Snapshot Management

**Status:** Implemented for votes (always on), entries (opt-in via config).

### UC-S7a: Init (First Read)

**Trigger:** First `GET` request for a tenant, or snapshot file missing.

**Flow:**
1. Server calls `sumup_update("votes_X.csv", "votes_X.sumup.json", $sid)`
2. Snapshot file check: `file_exists("votes_X.sumup.json")` → false
3. Full CSV read: `$csv = file_get_contents("votes_X.csv")`
4. Parse all rows, aggregate into snapshot structure
5. Write snapshot: `file_put_contents("votes_X.sumup.json", json_encode($snapshot))`
6. Return snapshot for projection

**Postconditions:**
- Snapshot file created with full aggregation
- Offset set to CSV EOF
- Future reads use incremental mode (O(tail))

---

### UC-S7b: Running Delta (Incremental Update)

**Trigger:** `GET` request when snapshot exists and is valid.

**Flow:**
1. Read snapshot: `$snapshot = json_decode(file_get_contents($sumup_file), true)`
2. Check offset validity: `$snapshot['offset'] <= filesize($csv_file)`
3. Open CSV, seek to offset, read tail only
4. Parse tail rows, merge into snapshot nodes
5. Update offset, lock + write snapshot back
6. Return updated snapshot

**Postconditions:**
- Snapshot updated with tail data only (O(tail) complexity)
- Offset advanced to new CSV EOF
- No full CSV re-read

---

### UC-S7c: Increment (Append on Write)

**Trigger:** `POST /votes` or `POST /entries` (if sumup enabled).

**Flow:**
1. After CSV append
2. Server calls `sumup_append_vote($tid, $parsed_entry)`
3. Lock snapshot: `fopen() + flock(LOCK_EX)`
4. Read, merge single entry, update offset
5. Truncate + write snapshot back
6. Unlock

**Postconditions:**
- Snapshot updated immediately (no stale reads)
- Next GET uses fresh snapshot

---

### UC-S7d: Re-create (Full Rebuild)

**Trigger:** `GET` request with `?refresh=1`, or snapshot corruption detected.

**Flow:**
1. Server receives `GET /votes?tid=X&sid=Y&refresh=1`
2. Server checks throttle (same as write throttle)
3. Server deletes snapshot: `unlink("votes_X.sumup.json")`
4. Server calls `sumup_update(...)` → triggers UC-S7a (Init) flow
5. Full rebuild from CSV, respond with fresh data

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

**Related:** [api-operations.md § 2.7](./api-operations-server.md#27-sumup-operations)

---

**Last Updated:** 2026-07-11  
**Related:** [API Operations § Server](./api-operations-server.md) · [Backend Communication](./backend-communication.md)

