# Backend Communication Concept

How `app2.html` stays in sync with the server without continuous polling.

Related: [`app2-spec.md`](./app2-spec.md) · [`docs/superpowers/specs/2026-06-23-notify-channel-design.md`](./superpowers/specs/2026-06-23-notify-channel-design.md)

---

## Overview

The frontend maintains one long-lived connection to `notify.php`. The server holds that
connection, watches up to three files per tenant, and responds only when something changes.
`entries.php` and `votes.php` are plain GET endpoints — they never hold a connection.

```
app2.html                notify.php              data/
    │                        │                      │
    │── GET /notify ─────────▶│                      │
    │          (holds ≤ 25 s) ║ clearstatcache()      │
    │                         ║ sleep(2)        ×N    │
    │                         ║ clearstatcache()      │
    │                         ║◀── mtime > since ─────│
    │◀── 200 [{type:…}] ──────│                      │
    │                                                  │
    │── GET /entries?since=Y ──────────────────────────│  (on "entries" event)
    │── GET /votes ────────────────────────────────────│  (on "votes" event)
    │   showToast()                                     │  (on "message" event)
    │                                                  │
    │── GET /notify (next cycle) ─────────────────────▶│
```

On **timeout** (no change within `poll_timeout` seconds): server returns `204 No Content`.
The frontend reconnects immediately — the server already held the wait, so there is no
tight-loop problem even for empty tenants.

---

## Notify Channel

### Endpoint

`GET /notify?tid=X&since=Y`

| Parameter | Required | Description |
|-----------|----------|-------------|
| `tid`     | yes      | Tenant ID, validated with `sanitize_id()`. 400 on missing or invalid. |
| `since`   | no       | ISO datetime `YYYY-MM-DD HH:MM:SS`. Omit on first poll to get all current events. |

### Responses

| Status | Body | Meaning |
|--------|------|---------|
| `200`  | JSON array, ≥ 1 event | Something changed — handle each event. |
| `204`  | empty | Timeout, nothing happened — reconnect immediately. |
| `400`  | `{"error":{"code":"INVALID_TID",…}}` | Missing or invalid `tid`. |

### Watched files

The server checks `filemtime() > since_int` on each wake cycle:

| File | Event emitted |
|------|---------------|
| `data/entries_<tid>.csv` | `{"type":"entries"}` |
| `data/votes_<tid>.csv`   | `{"type":"votes"}` |
| `data/notify_<tid>.jsonl` | each line with `strtotime(ts) > since_int` as-is |

All three files may change in the same 2-second window; all matching events are batched
into one response array.

### Wake cycle

```
$stop_at = time() + $poll_timeout;   // default: 25 s
while (time() < $stop_at) {
    clearstatcache();
    if (any watched file mtime > $since_int) break;
    sleep(2);
}
```

The loop always runs — even when no data files exist yet — so empty tenants never cause
the frontend to spin. The `sleep(2)` interval means change detection latency is at most 2 s.

---

## Event Types

### `entries`

Emitted when `entries_<tid>.csv` mtime advances or an `append_notify()` line is written.

Frontend action:
1. `GET /entries?sid=…&tid=…&since=<latestTimestamp>`
2. `addData(response)` — merges delta into the in-memory store
3. `updateView()`

### `votes`

Emitted when `votes_<tid>.csv` mtime advances or an `append_notify()` line is written.

Frontend action:
1. `GET /votes?sid=…&tid=…` (no `since` — votes are always fully aggregated)
2. `addVotesData(response)`
3. `updateView()`

### `message`

Written explicitly to `data/notify_<tid>.jsonl` via `append_notify()` or a direct file write.
Never emitted from CSV mtime.

Frontend action:
- `showToast(ev.text, 'info')`

Event shape: `{"type":"message","text":"…","ts":"YYYY-MM-DD HH:MM:SS"}`

---

## Write Path — What Triggers Changes

| Writer | Effect |
|--------|--------|
| `POST /entries` | Appends row to `entries_<tid>.csv` (mtime advances); calls `append_notify($tid, ['type'=>'entries'])` |
| `POST /votes`   | Appends row to `votes_<tid>.csv` (mtime advances); calls `append_notify($tid, ['type'=>'votes'])` |
| Admin scripts   | Write CSV directly → mtime advances → detected on next `clearstatcache()` cycle |
| Any PHP code    | `append_notify($tid, ['type'=>'message','text'=>'…'])` to push a toast to all connected clients |

The CSV mtime and the JSONL `append_notify()` call both fire on each POST — the JSONL entry
is belt-and-suspenders for debugging; the mtime change alone would suffice.

---

## Frontend — `startNotifyPoll()`

Located in `app2.html`. Replaces the old `startLongPoll()`.

```javascript
async function startNotifyPoll() {
    pollActive = true;
    const gen = ++pollGeneration;          // invalidates any previous poll loop
    async function poll() {
        if (gen !== pollGeneration) return; // stale loop — stop
        if (!pollActive || document.hidden) { setTimeout(poll, 2000); return; }
        try {
            pollController = new AbortController();
            const res = await fetch(
                'notify?' + new URLSearchParams({
                    sid, tid: tenantId,
                    ...(latestTimestamp ? { since: latestTimestamp } : {})
                }),
                { signal: pollController.signal }
            );
            if (res.status === 200) {
                for (const ev of await res.json()) {
                    if (ev.type === 'entries') { /* fetch + addData + updateView */ }
                    else if (ev.type === 'votes')   { /* fetch + addVotesData + updateView */ }
                    else if (ev.type === 'message') { showToast(ev.text, 'info'); }
                }
            }
            // 204: backend already held poll_timeout seconds → reconnect immediately
        } catch (err) {
            if (err.name !== 'AbortError') await new Promise(r => setTimeout(r, 5000));
        }
        poll();
    }
    poll();
}
```

`stopLongPoll()` is unchanged — it sets `pollActive = false` and aborts the in-flight
request via `pollController.abort()`.

The `pollGeneration` counter ensures that tenant switches (`applySettings()`) and
visibility-change wakeups never spawn duplicate loops.

---

## Configuration

| Key | Default | Effect |
|-----|---------|--------|
| `poll_timeout` | `25` | Seconds the server holds before returning 204. Set to `2` in e2e tests. |

Configured in `infopedia.cfg`. Read by `notify.php` as `(int)($config['poll_timeout'] ?? 25)`.

---

## Files

| File | Role |
|------|------|
| `notify.php` | Endpoint — inline poll loop, event collection, response |
| `util.php` → `append_notify()` | Helper — appends JSON line to `notify_<tid>.jsonl` |
| `entries.php` POST | Calls `append_notify()` after write |
| `votes.php` POST | Calls `append_notify()` after write |
| `app2.html` → `startNotifyPoll()` | Frontend poll loop |
| `data/notify_<tid>.jsonl` | Append-only event log, one JSON object per line |
