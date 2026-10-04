# Backend Communication — Concept (Design Draft)

Design direction for evolving the notify channel toward incremental reads.
This concept **replaces** the current signal-based implementation described in
[`backend-communication.md`](./backend-communication.md).

Status: **draft** — not yet implemented

---

## Read Strategies

### Init / Re-sync (unchanged)

Full read via dedicated endpoints — used on startup and after a client falls outside the
incremental re-read window:

- `GET /entries` — full dataset for the tenant
- `GET /votes` — full aggregated dataset for the tenant

---

### Incremental Read via `notify/`

Runs as a long-poll. On data available the server responds with a JSON object; on timeout
it returns `204 No Content` and the frontend reconnects immediately.

#### Response format

```json
{
  "ts":      1719230400,
  "msgid":   42,
  "entries": [ … ],
  "votes":   [ … ],
  "message": [ … ]
}
```

Empty arrays may be omitted. `ts` + `msgid` together form the cursor — the frontend stores
both and sends them on the next request. This is a **data delivery** model: notify/ carries
the actual payloads, not just change-type signals.

#### Incremental read

```
GET /notify?tid=X&ts=1719230400           # ts only — simple, may yield duplicates at boundary
GET /notify?tid=X&ts=1719230400&msgid=42  # precise cursor — no duplicates within window
```

First request (no prior cursor): omit both `ts` and `msgid` — backend returns whatever is current.

| Parameter | Default | Behaviour |
|-----------|---------|-----------|
| `ts`      | —       | Omit on first request |
| `msgid`   | `1`     | When omitted: include all messages at `ts == client_ts`; client may receive already-seen messages at the boundary |

Backend filter:

```
msg.ts > client_ts
OR (msg.ts == client_ts AND msg.msgid >= client_msgid_default_1)
```

Response includes `(ts, msgid)` of the last message in the batch as the new cursor.
`msgid` is meaningful only within a single `ts` bucket — the composite key `(ts, msgid)`
is the globally unique message pointer. `ts` must always accompany `msgid`.

Gap detection is **out of scope** for this design.

Limitations:
- `/!\ msgid is guaranteed valid only within ts` — the global key is `(ts, msgid)`
- `/!\ ts must always be sent` alongside msgid
- `/!\ When using ts-only: frontend must handle duplicates` — merge by entity key, not by append

---

## Votes — Backward-Compatible Transition

Incremental vote delivery requires the frontend to aggregate raw vote records locally
rather than consuming a pre-aggregated response from `/votes`.

**DESIGN-GAP-HERE**: The backend will be built for incremental vote delivery in the
notify response; the frontend will treat `votes` events as a trigger only and continue
fetching the full aggregated dataset from `/votes` (the existing approach). This gap
will be closed in a future iteration.

---

## General Limitations

- `/!\ Re-read timespan is limited` — clients outside the window fall back to full read
  via `/entries` + `/votes`

---

## Technical Approach

### Two Datasets

**Full dataset** (source of truth)
- One append-only file per entity: `entries_<tid>.csv`, `votes_<tid>.csv`
- Never rewritten; the authoritative record

**Incremental dataset** (delivery window)
- All messages (entries, votes, information) share two rotating files
- Active file covers the current re-read time span
- Inactive file is written in parallel and becomes the next active file on rotation

### Rotating Files

#### Dual-write guarantee

Both files are always written on every message — the inactive file accumulates content in
parallel. At the rotation point, the formerly inactive file is already prefilled with
messages whose `ts` exceeds the `poll_timeout` — so a client reconnecting right at the
rotation moment finds its messages in the new active file immediately.

#### Rotation trigger

A poll request triggers rotation when the active file is:
- non-empty, **and**
- last modified more than `re_read_timespan` ago, **and**
- over the configured size limit (`max_incr_file_size`)

Size-limit check is preferred over message-count: a single `stat()` call, no line count.

#### Write ordering and locking

All writes go through a single function. From the writer's perspective the active/inactive
distinction is irrelevant — both files are written with equal priority. Always acquire
locks in the same fixed order (file A before file B) to prevent deadlocks; the message
sequence is therefore identical across both files.

#### Worst-case fallback

If the rotating files are corrupted or absent, two recovery options:

1. **Direct read**: apply the same `ts` filter against the full data files and build the
   response from there (no re-init needed).
2. **Re-init rotating files**: filter the full data files by `re_read_timespan`, merge
   entities by `ts`, write fresh active + inactive files, then continue normally.

### msgId

- A per-`ts`-bucket counter assigned when a message is written to the incr file
- No global index or storage required — the composite key `(ts, msgid)` provides
  uniqueness and ordering within the delivery window
- Future: composite unique key `(ts, msg-hash)` for stronger deduplication

---

## Stale Client Recovery

| Situation | Outcome |
|-----------|---------|
| Nothing happened while offline | No new `ts` in incr files → respond with empty (or 204 after hold) |
| Some messages, within re-read window | Messages found in overlap portion of incr files → normal incr response |
| Client offline longer than re-read window | Discard incr state on client → full re-read via `/entries` + `/votes` |
| Optional | Use filter-and-merge-from-full-data (same code path as re-init-file-rotation) to serve even stale clients incrementally |

---

## Glossary

### long-poll (semi-socket + heartbeat)

- GET requests are answered only when a delta is available
- GET requests may be delayed by the backend waiting for a delta
- GET requests always respond before timeout
  - **Timeout response: `204 No Content`** — no body, frontend reconnects immediately
  - Frontend must spawn a new GET request after every 204

### re-read timespan

The window within which the incremental files guarantee coverage. Approximately 2–3×
`poll_timeout`. Clients reconnecting within this window receive an incremental response;
clients outside it must perform a full re-read.
