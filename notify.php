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
if (($_GET['tid'] ?? '') === '' || $tenant_id === '') {
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
    $entries_ok = file_exists($entries_file) && filemtime($entries_file) > $since_int;
    $votes_ok   = file_exists($votes_file)   && filemtime($votes_file)   > $since_int;
    $notify_ok  = file_exists($notify_file)  && filemtime($notify_file)  > $since_int;
    if ($entries_ok || $votes_ok || $notify_ok) break;
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
