# Notification Channel Design

**Date:** 2026-06-23  
**Status:** Approved

## Goal

Replace the long-poll on `entries.php`/`votes.php` with a single dedicated
notification channel (`notify.php`). The channel pushes typed events to the
frontend; the frontend fetches entries/votes on demand and shows toasts for
messages. Entries/votes endpoints become plain GETs.

## Architecture

A new `notify.php` endpoint serves one open long-poll connection per client.
It watches three files per tenant and returns an array of typed events when
any file changes:

| Watched file | Event synthesised |
|---|---|
| `data/entries_<tid>.csv` mtime | `{"type":"entries"}` |
| `data/votes_<tid>.csv` mtime | `{"type":"votes"}` |
| `data/notify_<tid>.jsonl` new lines | `{"type":"message","text":"..."}` |

File-mtime watching (same `clearstatcache` + `sleep(2)` pattern as the
existing `long_poll()`) handles all writers — API clients, admin scripts,
cron jobs — without requiring them to call any notify helper.

`entries.php` and `votes.php` POST handlers also append an explicit event to
`notify_<tid>.jsonl` via `append_notify()`. This is belt-and-suspenders: the
CSV mtime would catch the change anyway, but the explicit log entry is
timestamped and useful for debugging.

## API Contract

### `GET notify.php?tid=X&since=Y`

**Parameters**
- `tid` — tenant ID, required, validated with `sanitize_id()`.
- `since` — ISO datetime string (`YYYY-MM-DD HH:MM:SS`), same format as
  `latestTimestamp` in the frontend. If absent or zero, returns immediately
  with whatever is currently new.

**Responses**

| Status | Body | Meaning |
|---|---|---|
| 200 | JSON array, ≥1 event | Something changed — handle each event |
| 204 | empty | Timeout, nothing happened — reconnect immediately |
| 400 | `{"error":{"code":"INVALID_TID",...}}` | Missing or invalid `tid` |

**Event shapes**
```json
[{"type":"entries"}]
[{"type":"votes"}]
[{"type":"entries"},{"type":"votes"}]
[{"type":"message","text":"Server wird neu gestartet."}]
```

Multiple events may appear in one response (e.g. entries + votes both
changed in the same 2s sleep window).

### `data/notify_<tid>.jsonl`

Append-only, one JSON object per line:
```
{"ts":"2026-06-23 14:00:00","type":"message","text":"..."}
```

Written by `append_notify(string $tid, array $event): void` in `util.php`.
The `ts` field is set by the helper to `date('Y-m-d H:i:s')`.

## Backend Implementation

### `notify.php` (~50 lines)

1. Require `util.php`, `util_http.php`.
2. Validate `tid` via `sanitize_id()`; 400 on failure.
3. Resolve watched files:
   - `$entries_file = 'data/entries' . ($tid ? '_'.$tid : '') . '.csv'`
   - `$votes_file   = 'data/votes'   . ($tid ? '_'.$tid : '') . '.csv'`
   - `$notify_file  = 'data/notify'  . ($tid ? '_'.$tid : '') . '.jsonl'`
4. Parse `since` → `$since_int` (strtotime); 0 if absent.
5. Long-poll loop: hold up to `poll_timeout` seconds (from config, default 25),
   wake every 2s with `clearstatcache()`. Exit when any watched file has
   `filemtime() > $since_int`. If notify file does not exist, skip its mtime
   check silently.
6. Collect events:
   - `filemtime($entries_file) > $since_int` → push `['type'=>'entries']`
   - `filemtime($votes_file)   > $since_int` → push `['type'=>'votes']`
   - Notify file newer than `$since_int`: read lines, decode JSON, filter
     `strtotime($line['ts']) > $since_int`, push each as-is.
7. If events → `respond_json($events, 200)`. If none (timeout) → `respond_json(null, 204)`.

### `util.php` — new helper

```php
function append_notify(string $tid, array $event): void {
    $suffix = $tid !== '' ? '_' . $tid : '';
    $file   = 'data/notify' . $suffix . '.jsonl';
    $event['ts'] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
}
```

### `entries.php` / `votes.php` POST path

After successful CSV write, before `respond_json(...)`:
```php
append_notify($tenant_id, ['type' => 'entries']); // or 'votes'
```

### `entries.php` / `votes.php` GET path

Remove the `long_poll()` call entirely. Plain read → format → respond.
The `since` parameter and `_filter_since()` logic stays — clients still
send `since` on the plain GET to receive only rows newer than their last
known timestamp (delta fetch on demand).

## Frontend Changes (`app2.html`)

### Replace `startLongPoll()` with `startNotifyPoll()`

```javascript
async function startNotifyPoll() {
    pollActive = true;
    const gen = ++pollGeneration;
    async function poll() {
        if (gen !== pollGeneration) return;
        if (!pollActive || document.hidden) { setTimeout(poll, 2000); return; }
        try {
            pollController = new AbortController();
            const res = await fetch(
                'notify?' + new URLSearchParams({ tid: tenantId, sid,
                    ...(latestTimestamp ? { since: latestTimestamp } : {}) }),
                { signal: pollController.signal }
            );
            if (res.status === 200) {
                const events = await res.json();
                for (const ev of events) {
                    if (ev.type === 'entries') {
                        addData(await safeJson(
                            await fetch(buildEntriesUrl(latestTimestamp ? { since: latestTimestamp } : {})),
                            'notify-entries'));
                        updateView();
                    } else if (ev.type === 'votes') {
                        addVotesData(await safeJson(
                            await fetch(buildVotesUrl()),
                            'notify-votes'));
                        updateView();
                    } else if (ev.type === 'message') {
                        showToast(ev.text, 'info');
                    }
                }
            }
            // 204 → reconnect immediately (backend already held poll_timeout seconds)
        } catch (err) {
            if (err.name !== 'AbortError') {
                console.error('[app2] notify poll error:', err);
                await new Promise(r => setTimeout(r, 5000));
            }
        }
        poll();
    }
    poll();
}
```

All existing call sites of `startLongPoll()` → `startNotifyPoll()`.
`stopLongPoll()` is unchanged (it only touches `pollActive`/`pollController`).

The empty-tenant 5s backoff added in `fix/poll-empty-tenant-flood` becomes
unnecessary: `notify.php` returns 204 on timeout for empty tenants, and 204
means reconnect immediately after the backend held `poll_timeout` seconds.
That branch can be removed from `startNotifyPoll()`.

## Testing

### Unit (`test/unit_notify_test.php` or existing suite)

- `append_notify('demo', ['type'=>'entries'])` creates the file and writes a
  valid JSON line with a `ts` field.
- Calling it twice appends two lines.
- Reading lines filtered by `since_int` returns only lines with
  `strtotime(ts) > since_int`.
- `since_int = 0` returns all lines.

### E2E (`test/e2e.php`)

- GET `notify.php` without `tid` → 400 `INVALID_TID`.
- GET `notify.php?tid=e2e&since=<future>` → 204 after poll_timeout (patched
  to 2s in config, same trick as existing long-poll e2e test).
- POST to `entries.php` while `notify.php` is holding → `notify.php` returns
  `[{"type":"entries"}]` within 4s (one 2s sleep cycle after the write).
- `append_notify('e2e', ['type'=>'message','text'=>'hello'])` directly, then
  GET `notify.php?tid=e2e&since=<1s ago>` → returns `[{"type":"message","text":"hello"}]`.

## Files Changed

| File | Change |
|---|---|
| `notify.php` | Create — new endpoint |
| `util.php` | Add `append_notify()` |
| `entries.php` | POST: call `append_notify`; GET: remove `long_poll()` call |
| `votes.php` | POST: call `append_notify`; GET: remove `long_poll()` call |
| `app2.html` | Replace `startLongPoll()` with `startNotifyPoll()`; remove empty-tenant backoff |
| `test/e2e.php` | Add notify e2e section |
| `test/` | Add unit tests for `append_notify` + line filtering |
