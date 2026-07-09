<?php
/*
 * data.php — GET /data
 * General non-user data channel: stats (log aggregate) and ops (operations events).
 *
 * Parameters:
 *   entity (required) — 'stats' or 'ops'
 *   offset (stats)    — byte offset cursor; absent on first request
 *   ts     (ops)      — unix int cursor
 *   msgid  (ops)      — message ID cursor
 *   tid    (ops)      — optional tenant filter
 */

$type = 'data';
require_once 'util.php';
require_once 'util_http.php';
require_once 'util_cache.php';
require_once 'util_data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('METHOD_NOT_ALLOWED', 'Only GET accepted', 405);
}

$entity = preg_replace('/[^a-z]/', '', $_GET['entity'] ?? '');
$allowed = ['stats', 'ops'];
if (!in_array($entity, $allowed, true)) {
    respond_error('INVALID_ENTITY', 'entity must be one of: ' . implode(', ', $allowed), 400);
}

$poll_timeout   = (int)($config['poll_timeout']      ?? 25);
$log_viewer_max = (int)($config['log_viewer_max']    ?? 50);
$ops_rot_secs   = (int)($config['ops_rotation_hours'] ?? 3) * 3600;
$now            = time();

if ($entity === 'stats') {
    $client_offset = isset($_GET['offset']) ? (int)$_GET['offset'] : null;

    // Stale check before polling
    if ($client_offset !== null) {
        clearstatcache(true, $logFile);
        if (file_exists($logFile) && $client_offset > filesize($logFile)) {
            respond_error('STALE_OFFSET', 'Log rotated; drop offset and restart.', 400);
        }
    }

    long_poll_files([$logFile], $now, $poll_timeout);

    $resp = data_stats_respond($logFile, 'data/stats_aggregate.cache',
                               $client_offset, $log_viewer_max);
    if (!empty($resp['stale'])) {
        respond_error('STALE_OFFSET', 'Log rotated; drop offset and restart.', 400);
    }
    log_return('data/stats: offset=' . ($client_offset ?? 'first') . ' → ' . $resp['offset']);
    respond_json($resp);
}

if ($entity === 'ops') {
    $ts    = isset($_GET['ts'])    ? (int)$_GET['ts']    : null;
    $msgid = isset($_GET['msgid']) ? (int)$_GET['msgid'] : null;

    $suffix = $tenant_id !== '' ? '_ops_' . $tenant_id : '_ops';
    $fa     = 'data/notify' . $suffix . '_a.jsonl';
    $fb     = 'data/notify' . $suffix . '_b.jsonl';

    long_poll_files([$fa, $fb], $now, $poll_timeout);

    $resp = data_ops_respond($fa, $fb, $ts, $msgid, $ops_rot_secs);
    if (!empty($resp['stale'])) {
        respond_error('STALE_OFFSET', 'Ops rotation window exceeded; drop cursor and restart.', 400);
    }
    if (empty($resp['increments']['rows'])) {
        http_response_code(204); exit;
    }
    log_return('data/ops: ' . count($resp['increments']['rows']) . ' message(s)');
    respond_json($resp);
}
