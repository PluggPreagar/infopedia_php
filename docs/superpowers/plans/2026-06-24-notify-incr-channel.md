# Incremental Notify Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the signal-based notify channel (`[{type:"entries"}]` → frontend re-fetches) with a data-delivery channel that returns actual entry/vote payloads in a rotating incremental JSONL file pair, keyed by a composite `(ts, msgid)` cursor.

**Architecture:** Two rotating JSONL files per tenant (`notify_<tid>_a.jsonl`, `notify_<tid>_b.jsonl`) receive every message via dual-write; `notify.php` reads both, deduplicates by `(ts, msgid)`, filters by the client cursor, and returns a JSON object. The frontend consumes entries payloads directly (no re-fetch of `/entries`) and uses votes presence as a trigger to re-fetch `/votes` (DESIGN-GAP — full vote aggregation stays on the server for now). `append_notify()` becomes a wrapper so existing call sites keep working unchanged.

**Tech Stack:** Plain procedural PHP 8.0+, vanilla JS in `app2.html`; no Composer, no classes. The spec is `docs/backend-communication-concept.md`.

## Global Constraints

- CP1: Plain procedural PHP 8.0+ — no classes, no namespaces, no Composer
- CP2: One file per route — do not create new PHP route files
- CA1: Simple first — no gap detection, no reconnect counters, no msgId hash
- Always lock file `_a` before file `_b` (fixed order prevents deadlock)
- `ts` in incr files is a **unix integer** (not an ISO string like the old notify JSONL)
- The `since` parameter on `notify.php` is **dropped** — only `ts` + `msgid` are supported
- DESIGN-GAP: votes payloads written to incr files; frontend ignores data and re-fetches `/votes`
- `append_notify()` must remain in `util.php` as a thin wrapper around `append_incr()`
- Tests: red → green → commit; run `just unit` and `just e2e` to verify

---

## File Map

| File | Change |
|------|--------|
| `infopedia.cfg` | Add `re_read_timespan = 75` and `max_incr_file_size = 51200` under `[entry]` |
| `util.php` | Add `append_incr()`; rewrite `append_notify()` as a one-line wrapper |
| `notify.php` | Full rewrite: new params, rotation, dual-file read, cursor filter, new response shape |
| `entries.php` | Replace `append_notify()` call with `append_incr()` carrying the parsed entry payload |
| `votes.php` | Replace `append_notify()` call with `append_incr()` carrying the raw vote entry string |
| `app2.html` | Update `startNotifyPoll()`: cursor storage, new params, new response handler, stale recovery |
| `test/util_notify_test.php` | Replace `append_notify` assertions with `append_incr` assertions |
| `test/e2e.php` | Rewrite notify section (lines 293–345) for new endpoint params and response shape |

---

## Task 1: `append_incr()` — dual-write with msgId + config keys + unit tests

**Files:**
- Modify: `infopedia.cfg`
- Modify: `util.php` (add `append_incr()`, rewrite `append_notify()`)
- Modify: `test/util_notify_test.php` (replace all assertions)

**Interfaces:**
- Produces: `append_incr(string $tid, array $event): void`
  - `$event` must contain `'type'` key; may contain `'data'` and/or `'text'` keys
  - The function adds `'ts'` (int unix) and `'msgid'` (int, 1-based per-ts bucket) in-place
  - Writes to `data/notify_<tid>_a.jsonl` and `data/notify_<tid>_b.jsonl` (or `data/notify_a.jsonl` / `data/notify_b.jsonl` when `$tid === ''`)
  - Always locks `_a` first (LOCK_EX via `fopen`/`flock`), reads existing lines to count same-ts messages, writes, unlocks; then appends to `_b` with `FILE_APPEND | LOCK_EX`
- Produces: `append_notify(string $tid, array $event): void` — single-line wrapper, delegates to `append_incr()`

---

- [ ] **Step 1: Update `infopedia.cfg`**

Read `infopedia.cfg` first. Add two keys under `[entry]`:

```ini
re_read_timespan = 75
max_incr_file_size = 51200
```

Result should look like:
```ini
[entry]
cacheFile=data/entries.cache
dryRun=false
poll_timeout = 25
re_read_timespan = 75
max_incr_file_size = 51200
```

- [ ] **Step 2: Write the failing unit tests**

Replace the entire body of `test/util_notify_test.php` with:

```php
<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util.php';

// ─── append_incr ─────────────────────────────────────────────────────────────

$tid  = 'phpunit_incr_test';
$fa   = "data/notify_{$tid}_a.jsonl";
$fb   = "data/notify_{$tid}_b.jsonl";

// Cleanup before start
foreach ([$fa, $fb] as $f) { if (file_exists($f)) unlink($f); }

// T1: single write creates both files
append_incr($tid, ['type' => 'entries', 'data' => ['/a/b' => ['message' => 'hi.']]]);
assert_eq(true, file_exists($fa), 'append_incr creates _a file');
assert_eq(true, file_exists($fb), 'append_incr creates _b file');

// T2: line has ts (int), msgid (int), type (string), data (array)
$ev = json_decode(trim(file_get_contents($fa)), true);
assert_eq('entries', $ev['type'] ?? null,             'type field written');
assert_eq(true,      is_int($ev['ts'] ?? null),       'ts is integer');
assert_eq(1,         $ev['msgid'] ?? null,             'first msgid is 1');
assert_eq('/a/b',    array_key_first($ev['data'] ?? []),'data path key present');

// T3: both files have identical content
$lines_a = array_filter(explode("\n", trim(file_get_contents($fa))));
$lines_b = array_filter(explode("\n", trim(file_get_contents($fb))));
assert_eq(array_values($lines_a), array_values($lines_b), '_a and _b have identical lines');

// T4: second write at same second gets msgid=2
$ts_before = time();
append_incr($tid, ['type' => 'votes', 'data' => '/a/b | votes:s:1.']);
$lines_a2 = array_values(array_filter(explode("\n", trim(file_get_contents($fa)))));
assert_eq(2, count($lines_a2), 'two writes produce two lines');
$ev2 = json_decode($lines_a2[1], true);
assert_eq(2, $ev2['msgid'] ?? null, 'second write at same ts gets msgid=2');

// T5: message type stores text
foreach ([$fa, $fb] as $f) { if (file_exists($f)) unlink($f); }
append_incr($tid, ['type' => 'message', 'text' => 'hello world']);
$ev3 = json_decode(trim(file_get_contents($fa)), true);
assert_eq('message', $ev3['type'] ?? null, 'message type stored');
assert_eq('hello world', $ev3['text'] ?? null, 'message text stored');
assert_eq(1, $ev3['msgid'] ?? null, 'msgid resets on new ts bucket');

// T6: empty tid uses no suffix
$fa0 = 'data/notify_a.jsonl';
$fb0 = 'data/notify_b.jsonl';
foreach ([$fa0, $fb0] as $f) { if (file_exists($f)) unlink($f); }
append_incr('', ['type' => 'entries', 'data' => []]);
assert_eq(true, file_exists($fa0), 'empty tid writes data/notify_a.jsonl');
assert_eq(true, file_exists($fb0), 'empty tid writes data/notify_b.jsonl');

// T7: append_notify delegates to append_incr (backward compat)
foreach ([$fa, $fb] as $f) { if (file_exists($f)) unlink($f); }
append_notify($tid, ['type' => 'entries']);
$ev4 = json_decode(trim(file_get_contents($fa)), true);
assert_eq('entries', $ev4['type'] ?? null, 'append_notify delegates to append_incr');
assert_eq(true, is_int($ev4['ts'] ?? null), 'append_notify result has int ts');

// Cleanup
foreach ([$fa, $fb, $fa0, $fb0] as $f) { if (file_exists($f)) unlink($f); }
```

- [ ] **Step 3: Run test to verify it fails**

```bash
just unit 2>&1 | grep -A5 'append_incr\|FAIL\|Error'
```

Expected: PHP fatal `Call to undefined function append_incr()` or similar. The test file must fail before implementation.

- [ ] **Step 4: Implement `append_incr()` in `util.php`**

Read `util.php` first. Add `append_incr()` immediately before the existing `append_notify()` function (around line 153). Then replace `append_notify()` body with a one-line delegation.

```php
function append_incr(string $tid, array $event): void {
    $suffix = $tid !== '' ? '_' . $tid : '';
    $file_a = 'data/notify' . $suffix . '_a.jsonl';
    $file_b = 'data/notify' . $suffix . '_b.jsonl';

    $ts = time();

    // Lock _a exclusively, count same-ts lines to assign msgid, then write.
    $fp = fopen($file_a, 'a+');
    if ($fp === false) return;
    flock($fp, LOCK_EX);
    fseek($fp, 0);
    $existing = stream_get_contents($fp);
    $count    = 0;
    foreach (explode("\n", $existing) as $line) {
        if ($line === '') continue;
        $decoded = json_decode($line, true);
        if (is_array($decoded) && ($decoded['ts'] ?? 0) === $ts) {
            $count++;
        }
    }
    $event['ts']    = $ts;
    $event['msgid'] = $count + 1;
    $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    fwrite($fp, $json);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Mirror to _b (fixed lock order: always _a before _b).
    file_put_contents($file_b, $json, FILE_APPEND | LOCK_EX);
}

function append_notify(string $tid, array $event): void {
    append_incr($tid, $event);
}
```

- [ ] **Step 5: Run unit tests and verify they pass**

```bash
just unit
```

Expected: all `util_notify_test.php` assertions pass (8 checks across T1–T7). Full unit suite must be green.

- [ ] **Step 6: Commit**

```bash
git add infopedia.cfg util.php test/util_notify_test.php
git commit -m "feat(notify): add append_incr() dual-write with msgId; delegate append_notify()"
```

---

## Task 2: `notify.php` — full rewrite with rotation, cursor filter, new response shape

**Files:**
- Modify: `notify.php` (full rewrite)
- Modify: `test/e2e.php` (lines 293–345, the notify section)

**Interfaces:**
- Consumes: `append_incr()` (Task 1) — incr files must exist for tests
- Endpoint: `GET /notify?tid=X[&ts=T][&msgid=M]`
  - `ts`: optional integer unix timestamp; omit on first request
  - `msgid`: optional integer, defaults to `1` when omitted
  - `since`: **no longer supported** — ignored if present
- Produces responses:
  - `200 application/json` — `{"ts":int,"msgid":int[,"entries":{...}][,"votes":[...]]["message":[{...}]]}`
  - `204` — timeout, no new messages; reconnect immediately
  - `400 INVALID_TID` — tid missing or invalid (unchanged)
  - `400 STALE_CURSOR` — client `ts` is older than `re_read_timespan` seconds ago

---

- [ ] **Step 1: Write the failing e2e tests**

Read `test/e2e.php`. Replace lines 293–345 (the notify section) with:

```php
// ─── notify.php ──────────────────────────────────────────────────────────────
section('notify.php');

// GET without tid → 400
$r = get('notify.php', '');
ok($r['status'] === 400,                                  'GET notify without tid → 400');
ok(($r['json']['error']['code'] ?? '') === 'INVALID_TID', 'INVALID_TID code');

// Patch poll_timeout to 2 for timing tests
$orig_cfg2 = file_get_contents('infopedia.cfg');
register_shutdown_function(function() use ($orig_cfg2) {
    file_put_contents('infopedia.cfg', $orig_cfg2);
});
$patched2 = preg_replace('/^poll_timeout\s*=.*/m', 'poll_timeout = 2', $orig_cfg2);
file_put_contents('infopedia.cfg', $patched2);

// GET with no cursor (no incr files) → 204 after hold
$t0 = microtime(true);
$r  = get('notify.php', 'tid=notify_e2e');
$elapsed = microtime(true) - $t0;
ok($r['status'] === 204,  'GET notify no cursor no data → 204 after hold');
ok($elapsed >= 2.0,       'GET notify → held ≥ 2s', round($elapsed, 2) . 's');

// STALE_CURSOR: ts older than re_read_timespan → 400 immediately
$stale_ts = time() - 9999;
$r = get('notify.php', 'tid=notify_e2e&ts=' . $stale_ts);
ok($r['status'] === 400,                                       'stale ts → 400');
ok(($r['json']['error']['code'] ?? '') === 'STALE_CURSOR',     'STALE_CURSOR code');

// Write a message event directly to incr file _a (and _b)
$ts_msg    = time();
$msg_line  = json_encode(['ts' => $ts_msg, 'msgid' => 1, 'type' => 'message', 'text' => 'test-notice']);
file_put_contents('data/notify_notify_e2e_a.jsonl', $msg_line . "\n", FILE_APPEND | LOCK_EX);
file_put_contents('data/notify_notify_e2e_b.jsonl', $msg_line . "\n", FILE_APPEND | LOCK_EX);
sleep(1);
$r = get('notify.php', 'tid=notify_e2e&ts=' . ($ts_msg - 1));
ok($r['status'] === 200,                                   'GET notify with message → 200');
ok(isset($r['json']['ts']),                                'response has ts key');
ok(isset($r['json']['msgid']),                             'response has msgid key');
$msgs = $r['json']['message'] ?? [];
ok(count($msgs) > 0,                                       'message array non-empty');
ok(($msgs[0]['text'] ?? '') === 'test-notice',             'message text correct');

// Write an entries event directly to both incr files
$ts_entries = time();
$path       = '/test/notify';
$entry_data = [$path => ['timestamp' => date('Y-m-d H:i:s'), 'message' => 'hello.', 'attrs' => []]];
$entry_line = json_encode(['ts' => $ts_entries, 'msgid' => 2, 'type' => 'entries', 'data' => $entry_data]);
file_put_contents('data/notify_notify_e2e_a.jsonl', $entry_line . "\n", FILE_APPEND | LOCK_EX);
file_put_contents('data/notify_notify_e2e_b.jsonl', $entry_line . "\n", FILE_APPEND | LOCK_EX);
sleep(1);
$r = get('notify.php', 'tid=notify_e2e&ts=' . ($ts_entries - 1));
ok($r['status'] === 200,                                    'notify returns entries on incr write');
ok(isset($r['json']['entries'][$path]),                     'entries key contains path');
ok(($r['json']['entries'][$path]['message'] ?? '') === 'hello.', 'entry message correct');

// Cursor in response: ts and msgid match the last message in batch
ok(is_int($r['json']['ts'] ?? null),    'response ts is int');
ok(is_int($r['json']['msgid'] ?? null), 'response msgid is int');

// Restore cfg
file_put_contents('infopedia.cfg', $orig_cfg2);

// Cleanup
foreach ([
    'data/notify_notify_e2e_a.jsonl',
    'data/notify_notify_e2e_b.jsonl',
] as $f) {
    if (file_exists($f)) unlink($f);
}
```

- [ ] **Step 2: Run e2e tests to verify they fail**

```bash
just e2e 2>&1 | grep -A3 'notify'
```

Expected: several `FAIL` lines in the notify section (the old notify.php still uses `since` and returns an array).

- [ ] **Step 3: Rewrite `notify.php`**

Read `notify.php` first. Replace the entire file with:

```php
<?php
/*
 * notify.php — GET /notify
 * Long-polls incremental JSONL files; returns a data payload when new messages arrive.
 * Plain procedural PHP 8.0+. No classes, no framework, no Composer.
 *
 * Parameters:
 *   tid   (required) — tenant ID
 *   ts    (optional) — unix int cursor; omit on first request
 *   msgid (optional) — cursor within ts bucket; defaults to 1 when omitted
 *
 * Responses:
 *   200  {ts, msgid[, entries][, votes][, message]}
 *   204  timeout — no new messages; reconnect immediately
 *   400  INVALID_TID | STALE_CURSOR
 */

$type = 'notify';
require_once 'util.php';
require_once 'util_http.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('METHOD_NOT_ALLOWED', 'Only GET accepted', 405);
}

if (($_GET['tid'] ?? '') === '' || $tenant_id === '') {
    respond_error('INVALID_TID', 'tid is required', 400);
}

$poll_timeout     = (int)($config['poll_timeout']      ?? 25);
$re_read_timespan = (int)($config['re_read_timespan']  ?? 75);
$max_incr_size    = (int)($config['max_incr_file_size'] ?? 51200);

$ts_raw      = $_GET['ts'] ?? null;
$ts_param    = ($ts_raw !== null) ? (int)$ts_raw : null;
$msgid_param = isset($_GET['msgid']) ? (int)$_GET['msgid'] : 1;

// Stale cursor: ts provided and older than the re-read window.
if ($ts_param !== null && $ts_param < time() - $re_read_timespan) {
    respond_error('STALE_CURSOR', 'Client cursor is outside the re-read window; perform a full re-read.', 400);
}

$suffix = $tenant_id !== '' ? '_' . $tenant_id : '';
$file_a = 'data/notify' . $suffix . '_a.jsonl';
$file_b = 'data/notify' . $suffix . '_b.jsonl';

// Rotation: if the older file is stale and oversized, truncate it so it
// starts accumulating fresh messages while the newer file still covers history.
_rotate_incr_if_needed($file_a, $file_b, $re_read_timespan, $max_incr_size);

// First request (no cursor): take a watermark of current state so we only
// return messages written after the poll started.
if ($ts_param === null) {
    [$watermark_ts, $watermark_msgid] = _get_incr_watermark($file_a, $file_b);
    $ts_param    = $watermark_ts;
    $msgid_param = $watermark_ts > 0 ? $watermark_msgid + 1 : 1;
}

$stop_at = time() + $poll_timeout;
$msgs    = [];
while (time() < $stop_at) {
    clearstatcache();
    $msgs = _read_incr_messages($file_a, $file_b, $ts_param, $msgid_param);
    if (!empty($msgs)) break;
    sleep(2);
}

if (empty($msgs)) {
    http_response_code(204);
    exit;
}

log_return('notify: ' . count($msgs) . ' msg(s) for tid=' . $tenant_id);
respond_json(_assemble_notify_response($msgs));

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Truncate the older incr file when it is stale and oversized.
 * Both files receive all writes, so the newer file already covers recent history.
 */
function _rotate_incr_if_needed(string $file_a, string $file_b, int $re_read_timespan, int $max_size): void {
    $mtime_a = file_exists($file_a) ? filemtime($file_a) : 0;
    $mtime_b = file_exists($file_b) ? filemtime($file_b) : 0;

    if ($mtime_a <= $mtime_b) {
        $older = $file_a; $older_mtime = $mtime_a; $older_size = file_exists($file_a) ? filesize($file_a) : 0;
    } else {
        $older = $file_b; $older_mtime = $mtime_b; $older_size = file_exists($file_b) ? filesize($file_b) : 0;
    }

    if ($older_size > 0 && $older_mtime < time() - $re_read_timespan && $older_size > $max_size) {
        file_put_contents($older, '', LOCK_EX);
    }
}

/**
 * Return the highest (ts, msgid) pair present in either incr file.
 * Returns [0, 0] when both files are absent or empty.
 */
function _get_incr_watermark(string $file_a, string $file_b): array {
    $max_ts    = 0;
    $max_msgid = 0;
    foreach ([$file_a, $file_b] as $file) {
        if (!file_exists($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $m = json_decode($line, true);
            if (!is_array($m) || !isset($m['ts'], $m['msgid'])) continue;
            if ($m['ts'] > $max_ts || ($m['ts'] === $max_ts && $m['msgid'] > $max_msgid)) {
                $max_ts    = $m['ts'];
                $max_msgid = $m['msgid'];
            }
        }
    }
    return [$max_ts, $max_msgid];
}

/**
 * Read both incr files, deduplicate by (ts, msgid), apply cursor filter, sort.
 *
 * Filter: msg.ts > $ts  OR  (msg.ts === $ts AND msg.msgid >= $msgid)
 */
function _read_incr_messages(string $file_a, string $file_b, int $ts, int $msgid): array {
    $msgs = [];
    $seen = [];
    foreach ([$file_a, $file_b] as $file) {
        if (!file_exists($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $m = json_decode($line, true);
            if (!is_array($m) || !isset($m['ts'], $m['msgid'], $m['type'])) continue;
            $key = $m['ts'] . ':' . $m['msgid'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if ($m['ts'] > $ts || ($m['ts'] === $ts && $m['msgid'] >= $msgid)) {
                $msgs[] = $m;
            }
        }
    }
    usort($msgs, static fn($a, $b) => $a['ts'] === $b['ts'] ? $a['msgid'] - $b['msgid'] : $a['ts'] - $b['ts']);
    return $msgs;
}

/**
 * Collapse a sorted message list into the response object.
 * Empty arrays/objects are omitted per spec.
 */
function _assemble_notify_response(array $msgs): array {
    $entries = [];
    $votes   = [];
    $message = [];
    $last    = end($msgs);

    foreach ($msgs as $m) {
        switch ($m['type']) {
            case 'entries':
                if (!empty($m['data'])) {
                    $entries = array_merge($entries, (array)$m['data']);
                }
                break;
            case 'votes':
                // DESIGN-GAP: payload stored for future use; frontend re-fetches /votes.
                if (!empty($m['data'])) {
                    $votes[] = $m['data'];
                }
                break;
            case 'message':
                if (isset($m['text'])) {
                    $message[] = ['text' => $m['text']];
                }
                break;
        }
    }

    $response = ['ts' => $last['ts'], 'msgid' => $last['msgid']];
    if (!empty($entries)) $response['entries'] = $entries;
    if (!empty($votes))   $response['votes']   = $votes;
    if (!empty($message)) $response['message'] = $message;
    return $response;
}
```

- [ ] **Step 4: Run e2e tests and verify notify section passes**

```bash
just e2e 2>&1 | grep -E 'notify|PASS|FAIL|OK'
```

Expected: all notify assertions pass. Full e2e suite must be green.

- [ ] **Step 5: Commit**

```bash
git add notify.php test/e2e.php
git commit -m "feat(notify): rewrite endpoint — incr files, (ts,msgid) cursor, data-delivery response"
```

---

## Task 3: `entries.php` + `votes.php` — pass payloads to `append_incr()`

**Files:**
- Modify: `entries.php` (line 94 area — replace `append_notify` call)
- Modify: `votes.php` (line 121 area — replace `append_notify` call)

**Interfaces:**
- Consumes: `append_incr()` (Task 1), `parseEntry()` from `util_entry.php`
- `entries.php` already requires `util_entry.php` — `parseEntry()` is available
- `votes.php` already requires `util_entry.php` — `parseEntry()` is available

---

- [ ] **Step 1: Update `entries.php` POST — pass parsed entry as payload**

Read `entries.php`. Find line 94:
```php
    append_notify($tenant_id, ['type' => 'entries']);
```

Replace with (uses `$entry` and `$timestamp` already in scope):
```php
    // Build incr payload matching the /entries JSON response format.
    $_pn  = parseEntry($entry);
    $_pnd = [
        'timestamp' => $_pn['display_ts'] ?? $timestamp,
        'message'   => $_pn['content'],
        'attrs'     => $_pn['attrs'],
    ];
    if (!empty($_pn['votes'])) { $_pnd['votes'] = $_pn['votes']; }
    append_incr($tenant_id, ['type' => 'entries', 'data' => [$_pn['path'] => $_pnd]]);
    unset($_pn, $_pnd);
```

- [ ] **Step 2: Update `votes.php` POST — pass raw entry string as payload**

Read `votes.php`. Find line 121:
```php
    append_notify($tenant_id, ['type' => 'votes']);
```

Replace with (uses `$entry` already in scope — the assembled vote entry string from line 97):
```php
    // DESIGN-GAP: payload written for future incr vote delivery; frontend re-fetches /votes.
    append_incr($tenant_id, ['type' => 'votes', 'data' => $entry]);
```

- [ ] **Step 3: Run the full test suite**

```bash
just ci
```

Expected: unit tests and e2e tests all pass. The `append_notify` function still exists (as a wrapper) so no old call sites break.

- [ ] **Step 4: Commit**

```bash
git add entries.php votes.php
git commit -m "feat(notify): pass entry/vote payloads to append_incr() on POST"
```

---

## Task 4: `app2.html` — cursor-based notify poll and new response handling

**Files:**
- Modify: `app2.html` (`startNotifyPoll()`, state variable, stale-client recovery)

**Interfaces:**
- Consumes: notify endpoint (Task 2) — `GET /notify?tid=X[&ts=T][&msgid=M]`
- Consumes: `/entries` GET and `/votes` GET (unchanged — used for init and stale recovery)
- `addData(entriesMap)` — merges a `{"/path": {...}}` object into `data`; already exists
- `addVotesData(votesResponse)` — merges aggregated votes; already exists
- `updateView()` — re-renders; already exists
- `showToast(text, level)` — shows toast notification; already exists
- `loadInitialData()` — fetches full entries + votes; already exists; used for stale recovery

---

- [ ] **Step 1: Read the relevant portion of `app2.html`**

Search for `startNotifyPoll` in `app2.html`. Read the surrounding ~80 lines covering:
- State variable declarations (find `latestTimestamp` to locate the block)
- The full `startNotifyPoll()` function
- How votes are fetched in `loadInitialData()` (to extract or reuse for `loadVotes()`)

You need these exact function names and variables before writing changes.

- [ ] **Step 2: Add `notifyCursor` state variable**

In the state variable declarations (near `latestTimestamp`), add:

```javascript
let notifyCursor = null; // {ts: int, msgid: int} — updated on every 200 response
```

`latestTimestamp` remains unchanged — it is still used by `/entries?since=` calls in `loadInitialData()`.

- [ ] **Step 3: Add `loadVotes()` helper if it does not already exist**

If `loadInitialData()` fetches votes inline (not via a named helper), extract the votes fetch into a reusable function:

```javascript
async function loadVotes() {
    const res = await fetch(buildVotesUrl());
    if (res.ok) addVotesData(await res.json());
}
```

If a votes-only fetch helper already exists under a different name, use that name instead of adding a duplicate.

- [ ] **Step 4: Rewrite `startNotifyPoll()`**

Replace the existing `startNotifyPoll()` body with:

```javascript
async function startNotifyPoll() {
    pollActive = true;
    const gen = ++pollGeneration;
    async function poll() {
        if (gen !== pollGeneration) return;
        if (!pollActive || document.hidden) { setTimeout(poll, 2000); return; }
        try {
            pollController = new AbortController();
            const params = { sid, tid: tenantId };
            if (notifyCursor) {
                params.ts    = notifyCursor.ts;
                params.msgid = notifyCursor.msgid;
            }
            const res = await fetch(
                'notify?' + new URLSearchParams(params),
                { signal: pollController.signal }
            );
            if (res.status === 200) {
                const body = await res.json();
                notifyCursor = { ts: body.ts, msgid: body.msgid };
                if (body.entries)  { addData(body.entries); updateView(); }
                if (body.votes)    { await loadVotes(); updateView(); }
                if (body.message)  { for (const m of body.message) showToast(m.text, 'info'); }
            } else if (res.status === 400) {
                const err = await res.json().catch(() => ({}));
                if ((err?.error?.code) === 'STALE_CURSOR') {
                    notifyCursor = null;
                    await loadInitialData();
                    updateView();
                }
            }
            // 204: backend already held poll_timeout — reconnect immediately
        } catch (err) {
            if (err.name !== 'AbortError') await new Promise(r => setTimeout(r, 5000));
        }
        poll();
    }
    poll();
}
```

Key changes from the old version:
- `since` param removed; `ts` + `msgid` sent when `notifyCursor` is set
- Response is an object, not an array — `body.entries`, `body.votes`, `body.message`
- `body.entries` is a `{"/path": {...}}` map → passed directly to `addData()`
- `body.votes` presence → triggers `loadVotes()` (DESIGN-GAP: ignores payload, re-fetches /votes)
- `body.message` is an array of `{text}` objects → each shown via `showToast()`
- 400 `STALE_CURSOR` → clear cursor, full re-read via `loadInitialData()`

- [ ] **Step 5: Reset `notifyCursor` on tenant switch**

Find where `startNotifyPoll()` is called after settings change (likely in `applySettings()` or the `pollGeneration` increment path). Ensure `notifyCursor` is reset there:

```javascript
notifyCursor = null;
```

Add this line wherever `pollGeneration` is incremented and a new poll starts fresh, so the new tenant starts without a stale cursor.

- [ ] **Step 6: Run unit tests**

```bash
just unit
```

Expected: all pass. The frontend change has no PHP unit tests, but this confirms no PHP was accidentally broken.

- [ ] **Step 7: Manual smoke test**

Start the dev server and open `app2.html?tid=demo`. Add an entry via the bottom sheet. Verify:
- The entry appears without a full page reload (incremental delivery worked)
- Browser DevTools → Network shows a `notify?tid=demo&ts=...&msgid=...` request (cursor sent)
- No request to `/entries?since=...` fires after the notify response (no re-fetch)
- Adding a vote triggers a `/votes?...` fetch (DESIGN-GAP working as designed)

If a dev server cannot be started, note this explicitly and mark the smoke test as skipped.

- [ ] **Step 8: Commit**

```bash
git add app2.html
git commit -m "feat(app2): cursor-based notify poll — consume entry payloads directly, stale recovery"
```

---

## Self-Review Checklist

**Spec coverage:**
- ✅ `append_incr()`: dual-write, msgId, fixed lock order — Task 1
- ✅ Config keys `re_read_timespan` + `max_incr_file_size` — Task 1
- ✅ `notify.php`: ts+msgid params, stale detection, rotation trigger, read+dedup, cursor in response — Task 2
- ✅ `entries.php` payload — Task 3
- ✅ `votes.php` payload + DESIGN-GAP comment — Task 3
- ✅ Frontend cursor storage, ts+msgid params, entries direct-consume, votes trigger, stale recovery — Task 4
- ✅ `append_notify()` preserved as wrapper — Task 1
- ✅ No-cursor first-request watermark behavior — Task 2

**Placeholder scan:** None found.

**Type consistency:**
- `append_incr(string $tid, array $event)` — used identically in Tasks 1, 3
- `_read_incr_messages(string, string, int, int): array` — defined and called in Task 2
- `notifyCursor: {ts: int, msgid: int}|null` — set in Task 4, sent in Task 4
- `body.entries` is `object` (map keyed by path) — produced in Task 2 `_assemble_notify_response`, consumed in Task 4 via `addData()`
