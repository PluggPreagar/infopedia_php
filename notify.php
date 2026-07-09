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
// Note: util.php also reads $_GET['ts'] into $since_int — that variable is unused in notify.php.
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
    $msgid_param = $watermark_ts > 0 ? $watermark_msgid : 0;
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
 * Filter: msg.ts > $ts  OR  (msg.ts === $ts AND msg.msgid > $msgid)
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
            if ($m['ts'] > $ts || ($m['ts'] === $ts && $m['msgid'] > $msgid)) {
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
