# Notification Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace long-polling on `entries.php`/`votes.php` with a single `notify.php` channel that pushes typed events (`entries`, `votes`, `message`) to the frontend.

**Architecture:** A new `notify.php` endpoint long-polls three per-tenant files (entries CSV, votes CSV, notify JSONL) and returns a JSON array of typed events. `entries.php` and `votes.php` become plain GETs; their POST handlers additionally append an explicit event to the notify JSONL file. The frontend reconnects to `notify.php` after each response and fetches entries/votes on demand.

**Tech Stack:** Procedural PHP 8.0, vanilla JS (no framework, no Composer).

## Global Constraints

- CP1: Plain procedural PHP — no classes, no framework, no Composer.
- CP2: One file = one route (`notify.php` → `/notify`).
- CA1: Simple first — inline the poll loop in `notify.php`; do not reuse `long_poll()` (it short-circuits on no files; `notify.php` must always hold for `poll_timeout` even for empty tenants).
- CA11: Test-driven — failing test before implementation.
- All tests runnable via `just unit` (no server) and `just e2e` (PHP subprocesses).
- `tid` is required for `notify.php`; absent or invalid tid → 400 `INVALID_TID`.
- `poll_timeout` comes from `$config['poll_timeout'] ?? 25` (same as `entries.php`).
- Notify file path: `data/notify_<tid>.jsonl` (or `data/notify.jsonl` for empty tid).
- Event types: `entries`, `votes`, `message` (with `text` field).

---

### Task 1: `append_notify()` helper + unit tests

**Background:** All writers (entries POST, votes POST, any future admin) call one function to append a typed event to the per-tenant notify JSONL file. This is pure file I/O — ideal for isolated unit testing before anything else is built.

**Files:**
- Modify: `util.php` (add function after the last existing function definition)
- Create: `test/util_notify_test.php`

**Interfaces:**
- Produces: `append_notify(string $tid, array $event): void`
  - Constructs path `data/notify<suffix>.jsonl` where `$suffix = $tid !== '' ? '_'.$tid : ''`
  - Adds `$event['ts'] = date('Y-m-d H:i:s')` before writing
  - Appends `json_encode($event) . "\n"` with `FILE_APPEND | LOCK_EX`

- [ ] **Step 1: Write the failing unit test**

Create `test/util_notify_test.php`:

```php
<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util.php';

// ─── append_notify ───────────────────────────────────────────────────────────

$notifyFile = 'data/notify_phpunit_test.jsonl';
if (file_exists($notifyFile)) unlink($notifyFile);

// creates file, writes valid JSON line with ts
append_notify('phpunit_test', ['type' => 'entries']);
assert_eq(true,      file_exists($notifyFile),              'creates notify file');
$ev = json_decode(trim(file_get_contents($notifyFile)), true);
assert_eq('entries', $ev['type'] ?? null,                   'type field written');
assert_eq(true,      isset($ev['ts']),                      'ts field added');
assert_eq(1,         preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ev['ts'] ?? ''), 'ts format YYYY-MM-DD HH:MM:SS');

// second call appends — two lines
append_notify('phpunit_test', ['type' => 'votes']);
$lines = array_values(array_filter(explode("\n", trim(file_get_contents($notifyFile)))));
assert_eq(2, count($lines), 'two calls produce two lines');

// message event preserves text field
if (file_exists($notifyFile)) unlink($notifyFile);
append_notify('phpunit_test', ['type' => 'message', 'text' => 'hello']);
$ev2 = json_decode(trim(file_get_contents($notifyFile)), true);
assert_eq('message', $ev2['type'] ?? null, 'message type preserved');
assert_eq('hello',   $ev2['text'] ?? null, 'message text preserved');

// empty tid uses no suffix
if (file_exists('data/notify.jsonl')) unlink('data/notify.jsonl');
append_notify('', ['type' => 'entries']);
assert_eq(true, file_exists('data/notify.jsonl'), 'empty tid writes data/notify.jsonl');

// cleanup
foreach (['data/notify_phpunit_test.jsonl', 'data/notify.jsonl'] as $f) {
    if (file_exists($f)) unlink($f);
}
```

- [ ] **Step 2: Run to confirm RED**

```bash
just unit 2>&1 | grep -E "FAIL|util_notify"
```

Expected: `FAIL` — `append_notify` is not yet defined.

- [ ] **Step 3: Add `append_notify()` to `util.php`**

Append this block at the end of `util.php` (after all existing code):

```php
// ─── Notify channel ──────────────────────────────────────────────────────────

function append_notify(string $tid, array $event): void {
    $suffix = $tid !== '' ? '_' . $tid : '';
    $file   = 'data/notify' . $suffix . '.jsonl';
    $event['ts'] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
}
```

- [ ] **Step 4: Run to confirm GREEN**

```bash
just unit 2>&1 | tail -3
```

Expected: `OK — N passed, 0 failed` (N increases by the new assertions).

- [ ] **Step 5: Commit**

```bash
git add util.php test/util_notify_test.php
git commit -m "feat(notify): append_notify() helper + unit tests"
```

---

### Task 2: `notify.php` endpoint + `.htaccess.dev` route + e2e tests

**Background:** The new endpoint long-polls three watched files and returns a JSON array of typed events. It uses an inline loop (not `long_poll()`) so it always holds for `poll_timeout` even when no data files exist yet (empty tenant). The router.php auto-routes `/notify` → `notify.php`; only `.htaccess.dev` needs an explicit rule.

**Files:**
- Create: `notify.php`
- Modify: `.htaccess.dev` (add route before the catch-all)
- Modify: `test/e2e.php` (add `notify.php` section before the Summary block)

**Interfaces:**
- Consumes: `append_notify()` from Task 1, `$tenant_id`/`$since_int` from `util.php`, `respond_json()`/`respond_error()` from `util_http.php`
- Produces: `GET /notify?tid=X&since=Y` → `200 [{type,…}]` or `204`

- [ ] **Step 1: Write the failing e2e tests**

In `test/e2e.php`, add this section just before the `// ─── Summary ───` block (line 304):

```php
// ─── notify.php ──────────────────────────────────────────────────────────────
section('notify.php');

// GET without tid → 400
$r = get('notify.php', '');
ok($r['status'] === 400,                                 'GET notify without tid → 400');
ok(($r['json']['error']['code'] ?? '') === 'INVALID_TID','INVALID_TID code');

// Patch poll_timeout to 2 for timing tests (config already patched in long-poll section above,
// but that section restored it — re-patch here)
$orig_cfg2  = file_get_contents('infopedia.cfg');
register_shutdown_function(function() use ($orig_cfg2) {
    file_put_contents('infopedia.cfg', $orig_cfg2);
});
$patched2 = preg_replace('/^poll_timeout\s*=.*/m', 'poll_timeout = 2', $orig_cfg2);
file_put_contents('infopedia.cfg', $patched2);

// GET with since=future → 204 after ~2s (no data files for this tid)
$t0 = microtime(true);
$r  = get('notify.php', 'tid=notify_e2e&since=2099-01-01+00:00:00');
$elapsed = microtime(true) - $t0;
ok($r['status'] === 204,  'GET notify since future → 204 after hold');
ok($elapsed >= 2.0,       'GET notify → held ≥ 2s', round($elapsed, 2) . 's');

// Write a message event directly to the notify file (append_notify is tested in unit tests)
$ts_msg = date('Y-m-d H:i:s');
file_put_contents('data/notify_notify_e2e.jsonl',
    json_encode(['type' => 'message', 'text' => 'test-notice', 'ts' => $ts_msg]) . "\n",
    FILE_APPEND | LOCK_EX);
sleep(1); // ensure ts > since
$since_msg = date('Y-m-d H:i:s', time() - 2);
$r = get('notify.php', 'tid=notify_e2e&since=' . urlencode($since_msg));
ok($r['status'] === 200,                           'GET notify with message event → 200');
$types = array_column($r['json'] ?? [], 'type');
ok(in_array('message', $types, true),              'response contains message event');
$texts = array_column($r['json'] ?? [], 'text');
ok(in_array('test-notice', $texts, true),          'message text correct');

// Touch entries CSV → GET returns entries event
file_put_contents('data/entries_notify_e2e.csv', "Timestamp,entry\n");
$since_touch = date('Y-m-d H:i:s', time() - 2);
$r = get('notify.php', 'tid=notify_e2e&since=' . urlencode($since_touch));
ok($r['status'] === 200,                           'notify returns entries event on CSV change');
$types2 = array_column($r['json'] ?? [], 'type');
ok(in_array('entries', $types2, true),             'entries event present');

// Restore cfg
file_put_contents('infopedia.cfg', $orig_cfg2);

// Cleanup
foreach (['data/notify_notify_e2e.jsonl', 'data/entries_notify_e2e.csv'] as $f) {
    if (file_exists($f)) unlink($f);
}
```

- [ ] **Step 2: Run to confirm RED**

```bash
just e2e 2>&1 | grep -A2 "notify.php"
```

Expected: failures — `notify.php` does not exist yet.

- [ ] **Step 3: Create `notify.php`**

```php
<?php
/*
 * notify.php — GET /notify
 * Long-polls per-tenant files; returns typed events when any change is detected.
 * Plain procedural PHP 8.0+. No classes, no framework, no Composer.
 */

$type = 'notify';
require_once 'util.php';
require_once 'util_http.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('METHOD_NOT_ALLOWED', 'Only GET accepted', 405);
}

// tid is required for notify
if ($tenant_id === '' && ($_GET['tid'] ?? '') === '') {
    respond_error('INVALID_TID', 'tid is required', 400);
}

$poll_timeout = (int)($config['poll_timeout'] ?? 25);
$suffix       = $tenant_id !== '' ? '_' . $tenant_id : '';

$entries_file = 'data/entries' . $suffix . '.csv';
$votes_file   = 'data/votes'   . $suffix . '.csv';
$notify_file  = 'data/notify'  . $suffix . '.jsonl';

$stop_at = time() + $poll_timeout;

// Hold until a watched file changes, or timeout.
// Always loops (even with no files) so empty tenants don't cause tight re-polls.
while (time() < $stop_at) {
    clearstatcache();
    if (file_exists($entries_file) && filemtime($entries_file) > $since_int) break;
    if (file_exists($votes_file)   && filemtime($votes_file)   > $since_int) break;
    if (file_exists($notify_file)  && filemtime($notify_file)  > $since_int) break;
    sleep(2);
}

// Collect events from changed files.
$events = [];

if (file_exists($entries_file) && filemtime($entries_file) > $since_int) {
    $events[] = ['type' => 'entries'];
}
if (file_exists($votes_file) && filemtime($votes_file) > $since_int) {
    $events[] = ['type' => 'votes'];
}
if (file_exists($notify_file) && filemtime($notify_file) > $since_int) {
    foreach (file($notify_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $ev = json_decode($line, true);
        if (is_array($ev) && isset($ev['ts']) && strtotime($ev['ts']) > $since_int) {
            $events[] = $ev;
        }
    }
}

if (empty($events)) {
    http_response_code(204);
    exit;
}

log_return('notify: ' . count($events) . ' event(s) for tid=' . $tenant_id);
respond_json($events, 200);
```

- [ ] **Step 4: Add route to `.htaccess.dev`**

In `.htaccess.dev`, add after the `health` route line and before the catch-all:

```apache
RewriteRule ^/?notify/?$             notify.php [QSA,L]
```

The file currently has (lines 8–13):
```apache
RewriteRule ^/?health/?$            health.php [QSA,L]
RewriteRule ^/?stats/?$             statistic.php [QSA,L]

RewriteRule ^(favicon.*|…)$ $1 [NC,L]

RewriteRule ^(.*)$                  index.php?missed=$1 [QSA,L]
```

Add the notify line after health:
```apache
RewriteRule ^/?health/?$            health.php [QSA,L]
RewriteRule ^/?notify/?$            notify.php [QSA,L]
RewriteRule ^/?stats/?$             statistic.php [QSA,L]
```

- [ ] **Step 5: Run to confirm GREEN**

```bash
just e2e 2>&1 | grep -A2 "notify.php"
```

Expected: all notify assertions PASS.

```bash
just unit 2>&1 | tail -3
```

Expected: still `OK — N passed, 0 failed`.

- [ ] **Step 6: Commit**

```bash
git add notify.php .htaccess.dev test/e2e.php
git commit -m "feat(notify): notify.php endpoint + htaccess route + e2e tests"
```

---

### Task 3: `app2.html` — `startNotifyPoll()` replaces `startLongPoll()`

**Background:** The frontend switches from polling `entries.php` to polling `notify.php`. On each event the frontend fetches entries or votes on demand, or shows a toast. All three call sites of `startLongPoll()` are renamed; `stopLongPoll()` is unchanged. The empty-tenant 5s backoff (added in `fix/poll-empty-tenant-flood`) is removed — `notify.php` handles the empty-tenant case by holding for `poll_timeout` and returning 204.

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `notify.php` from Task 2
- Produces: `startNotifyPoll()` — same signature as `startLongPoll()` (no args); `stopLongPoll()` unchanged

- [ ] **Step 1: Replace `startLongPoll` function body (lines 1011–1044)**

Replace the entire `startLongPoll` function:

```javascript
async function startNotifyPoll() {
    pollActive = true;
    const gen = ++pollGeneration;
    async function poll() {
        if (gen !== pollGeneration) return;
        if (!pollActive || document.hidden) { setTimeout(poll, 2000); return; }
        runtimeCheck("poll");
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
                const events = await res.json();
                for (const ev of events) {
                    if (ev.type === 'entries') {
                        const r = await fetch(buildEntriesUrl(latestTimestamp ? { since: latestTimestamp } : {}));
                        if (r.ok) { addData(await safeJson(r, 'notify-entries')); updateView(); }
                    } else if (ev.type === 'votes') {
                        const r = await fetch(buildVotesUrl());
                        if (r.ok) { addVotesData(await safeJson(r, 'notify-votes')); updateView(); }
                    } else if (ev.type === 'message') {
                        showToast(ev.text, 'info');
                    }
                }
            }
            // 204 → backend already held poll_timeout seconds, reconnect immediately
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

- [ ] **Step 2: Update the three `startLongPoll` call sites**

Line ~1054 (`visibilitychange` handler):
```javascript
    else if (tenantId) startNotifyPoll();
```

Line ~1200 (`applySettings` / tenant switch):
```javascript
        loadInitialData().then(() => startNotifyPoll());
```

Line ~1238 (`init()` bootstrap):
```javascript
        loadInitialData().then(() => startNotifyPoll());
```

- [ ] **Step 3: Run unit tests**

```bash
just unit 2>&1 | tail -3
```

Expected: `OK — N passed, 0 failed` (no JS tests cover `startNotifyPoll` directly; all existing tests still pass).

- [ ] **Step 4: Commit**

```bash
git add app2.html
git commit -m "feat(notify): startNotifyPoll() replaces startLongPoll() in frontend"
```

---

### Task 4: `entries.php` — POST adds `append_notify`; GET removes `long_poll()`

**Background:** After this task, `entries.php` is a pure data endpoint: GET reads and returns data immediately, POST writes and notifies. The existing e2e long-poll timing test must be updated: entries.php no longer holds, so "held ≥ 2s" becomes "returned 204 immediately".

**Files:**
- Modify: `entries.php`
- Modify: `test/e2e.php` (update the `entries.php — long-poll` section)

**Interfaces:**
- Consumes: `append_notify()` from Task 1

- [ ] **Step 1: Update `entries.php` POST path — add `append_notify` call**

In `entries.php`, after line 90 (`touchOutdated(...)`) and before line 94 (`log_return('POST /entries ok')`), add:

```php
    // Notify subscribers that entries data changed.
    append_notify($tenant_id, ['type' => 'entries']);
```

The block should read:
```php
    // 7. Signal that the cache is outdated.
    if ($outdated_file !== null) {
        touchOutdated($outdated_file);
    }

    // Notify subscribers that entries data changed.
    append_notify($tenant_id, ['type' => 'entries']);

    // 8. Respond.
    log_return('POST /entries ok');
    respond_json(['status' => 'ok', 'timestamp' => $timestamp], 201);
```

- [ ] **Step 2: Remove `long_poll()` call from `entries.php` GET path**

Remove these lines (approximately lines 126–133):
```php
// 5. Long-poll: hold until entries or votes file changes.
//    Cross-watching votes releases the entries connection when votes update,
//    keeping both client polls in sync.
$poll_timeout = (int)($config['poll_timeout'] ?? 25);
$now          = time();
if ($since !== '' && $since_int > 0) {
    long_poll($tenant_id, $now, $poll_timeout);
}
```

After removal the step numbering shifts; update the comment on the next block from `// 6. Fetch from source.` to `// 5. Fetch from source.`.

- [ ] **Step 3: Update the long-poll e2e test for entries.php**

In `test/e2e.php`, find the `entries.php — long-poll` section (~line 260) and update the "held ≥ 2s" assertion:

Change:
```php
ok($r['status'] === 204,    'GET since future → 204 after hold');
ok($elapsed >= 2.0,         'GET since future → held ≥ 2s', round($elapsed, 2) . 's');
```
To:
```php
ok($r['status'] === 204,    'GET since future → 204');
ok($elapsed < 1.0,          'GET since future → immediate (no hold)', round($elapsed, 2) . 's');
```

Remove only the `poll_timeout = 2` config-patching lines — the `sleep(2)` must stay because `_filter_since` has a 1-second grace window that still applies. Remove:

```php
// $orig_cfg = file_get_contents('infopedia.cfg');
// register_shutdown_function(function() use ($orig_cfg) {
//     file_put_contents('infopedia.cfg', $orig_cfg);
// });
// $patched  = preg_replace('/^poll_timeout\s*=.*/m', 'poll_timeout = 2', $orig_cfg);
// file_put_contents('infopedia.cfg', $patched);
```

and the matching restore line:

```php
// file_put_contents('infopedia.cfg', $orig_cfg);
```

Keep `sleep(2)`, the POST, all three functional assertions, and the cleanup block.

- [ ] **Step 4: Run tests**

```bash
just e2e 2>&1 | grep -E "PASS|FAIL|entries.*long" | head -20
just unit 2>&1 | tail -3
```

Expected: all PASS, unit still OK.

- [ ] **Step 5: Commit**

```bash
git add entries.php test/e2e.php
git commit -m "feat(notify): entries.php POST notifies; GET drops long_poll"
```

---

### Task 5: `votes.php` — POST adds `append_notify`; GET removes `long_poll()`

**Background:** Same as Task 4 for votes. The GET path in `votes.php` has a more complex long-poll block (it re-reads and re-aggregates after waking). All of that is removed; the data is returned immediately.

**Files:**
- Modify: `votes.php`

**Interfaces:**
- Consumes: `append_notify()` from Task 1

- [ ] **Step 1: Add `append_notify` call to `votes.php` POST path**

In `votes.php`, after line 132 (`touchOutdated(...)`) and before line 135 (`log_return(...)`), add:

```php
    // Notify subscribers that votes data changed.
    append_notify($tenant_id, ['type' => 'votes']);
```

The block should read:
```php
    if ($cacheOutdatedFile !== null) {
        touchOutdated($cacheOutdatedFile);
    }

    // Notify subscribers that votes data changed.
    append_notify($tenant_id, ['type' => 'votes']);

    log_return('votes POST saved ' . strlen($line) . ' bytes to ' . $localCsv);
    respond_json(['status' => 'ok', 'timestamp' => $timestamp], 201);
```

- [ ] **Step 2: Remove `long_poll()` block from `votes.php` GET path**

Remove these lines (approximately lines 50–59):
```php
    // Long-poll: if ?since= given and no new data yet, wait for any change.
    //    Cross-watching entries releases the votes connection when entries update,
    //    keeping both client polls in sync.
    $poll_timeout = (int)($config['poll_timeout'] ?? 25);
    $now          = time();
    if ($since !== '' && $since_int > 0 && !_votes_has_since($csv, $since)) {
        if (long_poll($tenant_id, $now, $poll_timeout)) {
            $csv = sortCsvData(@file_get_contents($localCsv) ?: "Timestamp,entry\n");
            $csv = aggregateVotes($csv, $session_id);
        }
    }
```

The 204 return that follows (checking `!_votes_has_since`) stays as-is — it correctly returns 204 when the client is up to date.

- [ ] **Step 3: Run full test suite**

```bash
just ci 2>&1 | tail -10
```

Expected:
```
OK — N passed, 0 failed

OK — M passed, 0 failed
```

- [ ] **Step 4: Commit**

```bash
git add votes.php
git commit -m "feat(notify): votes.php POST notifies; GET drops long_poll"
```
