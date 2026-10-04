# InfoPedia API — Client-Side Use Cases

> 📚 **Navigation:** [Docs Index](./README.md) → [API Use Cases](./api-use-cases.md) → **Client-Side**
>
> **Related Modules:** [Server-Side](./api-use-cases-server.md) · [Integration & DR](./api-use-cases-integration.md) · [Monitoring & Testing](./api-use-cases-monitoring.md)

Client-side technical use cases with detailed flows and error handling. For operational context, see [API Operations § Client-Side](./api-operations-client.md).

---

## UC-C1: Initial App Load

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

**Related:** [api-operations.md § 1.1](./api-operations-client.md#11-initial-client-sync-cold-start)

---

## UC-C2: Incremental Sync (Within Retention)

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

**Related:** [api-operations.md § 1.2](./api-operations-client.md#12-catchup-during-retention-time)

---

## UC-C3: Full Re-Sync (Outside Retention)

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

**Related:** [api-operations.md § 1.3](./api-operations-client.md#13-catchup-after-retention-time-long-offline)

---

## UC-C4: Real-Time Event Handling

**Trigger:** Notify connection returns event array (200 OK).

**Preconditions:**
- Client has active notify long-poll connection
- Client is in foreground (not `document.hidden`)

**Flow:**

### Event Type: `entries`
1. Receive: `[{"type":"entries","ts":"2026-07-11 12:40:00"}]`
2. Execute **UC-C2 (Incremental Sync)** flow

### Event Type: `votes`
1. Receive: `[{"type":"votes","ts":"2026-07-11 12:40:05"}]`
2. Client sends `GET /votes?tid=<tenant>&sid=<sid>` (no `since`)
3. Server responds with full aggregated votes
4. Client calls `addVotesData(response)`:
   - Replace `window.votesData` completely (not merge)
   - Recalculate vote display on all visible cards
5. Client calls `updateView()`

### Event Type: `message`
1. Receive: `[{"type":"message","text":"System maintenance in 5 min","ts":"..."}]`
2. Client calls `showToast(event.text, 'info')`
3. No data fetch, no view update

### Multiple Events in Same Response
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

**Related:** [api-operations.md § 1.4](./api-operations-client.md#14-receiving-real-time-notifications)

---

## UC-C5: Notify Timeout (204 No Content)

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

**Related:** [api-operations.md § 1.4](./api-operations-client.md#14-receiving-real-time-notifications)

---

## UC-C6: Background/Foreground Transitions

**Trigger:** User switches tabs or minimizes browser.

**Preconditions:**
- Notify connection active
- `pollActive = true`

**Flow:**

### On Background (`document.hidden = true`)
1. `visibilitychange` event fires
2. Client checks: `if (document.hidden) stopLongPoll()`
3. Client aborts notify connection: `pollController.abort()`
4. Client sets `pollActive = false`
5. Poll loop exits: `if (!pollActive) return`

### On Foreground (`document.hidden = false`)
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

**Last Updated:** 2026-07-11  
**Related:** [API Operations § Client](./api-operations-client.md) · [Backend Communication](./backend-communication.md)

