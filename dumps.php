<?php
$type = 'dump';
require_once 'util.php';
require_once 'util_http.php';
require_once 'util_throttle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('METHOD_NOT_ALLOWED', 'Only POST accepted', 405);
}

$throttle_max    = (int)($config['throttle_max']    ?? 0);
$throttle_window = (int)($config['throttle_window'] ?? 60);
$throttle_key_type = $config['throttle_key'] ?? 'sid';
$throttle_key    = $throttle_key_type === 'ip'
    ? ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
    : $session_id;

if (!checkThrottle('data', $throttle_key, $throttle_max, $throttle_window)) {
    $retry = throttleRetryAfter('data', $throttle_key, $throttle_window);
    header("Retry-After: $retry");
    respond_error('THROTTLED', "Too many requests. Retry after $retry seconds.", 429);
}

$dump = $_POST['dump'] ?? $_GET['dump'] ?? $_POST['log'] ?? '';
if ($dump === '') {
    respond_error('INVALID_ENTRY', 'dump body must not be empty', 400);
}

$googlePostUrl     = $config['googlePostUrl']     ?? '';
$googlePostEntryId = $config['googlePostEntryId'] ?? 'entry.1234567890';

$options = [
    'http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query([$googlePostEntryId => $dump]),
        'timeout' => 10,
    ],
];
$context  = stream_context_create($options);
$response = @file_get_contents($googlePostUrl, false, $context);
if ($response === false) {
    // best-effort: log and continue — client must not retry dumps on failure
    log_warn('dump upstream failed: ' . (error_get_last()['message'] ?? 'unknown'));
}

log_return('dump posted (' . strlen($dump) . ' bytes)');
respond_json(['status' => 'ok'], 201);
