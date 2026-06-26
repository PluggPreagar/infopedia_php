<?php
// End-to-end test runner — no HTTP server required.
// Each request is a PHP subprocess; output buffering in e2e_request.php
// captures the response and prepends "STATUS:xxx\n".
//
// Usage:
//   php test/e2e.php           run all tests
//   php test/e2e.php --debug   verbose: print every request + response

$debug = in_array('--debug', $argv);
$pass  = 0;
$fail  = 0;
$tid   = 'e2e';
$sid   = 'e2e_session';

// ─── HTTP helper (subprocess) ─────────────────────────────────────────────────

function req(string $method, string $path, string $qs = '', string $body = ''): array {
    global $debug;
    $runner = __DIR__ . '/e2e_request.php';
    $cmd    = [PHP_BINARY, $runner, $method, $path, $qs, $body];
    $proc   = proc_open($cmd, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
    fclose($pipes[0]);
    $raw    = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // First line is "STATUS:xxx", rest is the body
    $nl     = strpos($raw, "\n");
    $status = (int) substr($raw, 7, $nl - 7);   // "STATUS:" = 7 chars
    $body_s = $nl !== false ? substr($raw, $nl + 1) : '';
    $json   = json_decode($body_s, true);

    if ($debug) {
        echo "  > $method $path" . ($qs ? "?$qs" : '') . ($body ? " [$body]" : '') . "\n";
        $lines = explode("\n", rtrim($body_s));
        foreach (array_slice($lines, 0, 5) as $line) echo "  < $status  $line\n";
        if (count($lines) > 5) echo "  < ... (" . (count($lines) - 5) . " more lines)\n";
        if ($stderr) echo "  ! " . trim($stderr) . "\n";
    }
    return ['status' => $status, 'body' => $body_s, 'json' => $json];
}

function get(string $path, string $qs = ''): array        { return req('GET',  $path, $qs); }
function post(string $path, string $qs, string $b): array { return req('POST', $path, $qs, $b); }

// ─── Assertion helpers ────────────────────────────────────────────────────────

function ok(bool $cond, string $msg, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $msg\n"; }
    else        { $fail++; echo "  FAIL  $msg" . ($detail ? "  [$detail]" : '') . "\n"; }
}
function section(string $name): void { echo "\n── $name\n"; }

// ─── Setup ────────────────────────────────────────────────────────────────────

chdir(__DIR__ . '/..');
foreach (['data/entries_e2e.csv', 'data/votes_e2e.csv', 'data/entries_e2e.cache'] as $f) {
    if (file_exists($f)) unlink($f);
}
echo "E2E — PHP subprocess mode (" . PHP_BINARY . ")\n";

// ─── 1. Health ────────────────────────────────────────────────────────────────

section('Health');
$r = get('/health');
ok($r['status'] === 200,                       'GET /health → 200');
ok(($r['json']['status'] ?? '') === 'ok',      'status: ok');
ok(isset($r['json']['server_time']),           'server_time present');
ok(isset($r['json']['cache']),                 'cache info present');

// ─── 2. Entries — read ────────────────────────────────────────────────────────

section('Entries — read');
$r = get('/entries', "sid=$sid&tid=$tid&format=json");
ok($r['status'] === 200,                       'GET /entries json → 200');
ok(is_array($r['json']),                       'body is json object');

$r = get('/entries', "sid=$sid&tid=$tid&format=csv");
ok($r['status'] === 200,                       'GET /entries csv → 200');
ok(str_starts_with($r['body'], 'Timestamp,'),  'csv starts with header');

$r = get('/entries', "sid=$sid&tid=$tid&format=txt.0.2");
ok($r['status'] === 200,                       'GET /entries txt.0.2 → 200');

$r = get('/entries', "sid=$sid&tid=$tid&format=txt.0.3");
ok($r['status'] === 200,                       'GET /entries txt.0.3 → 200');

// ─── 3. Entries — write ───────────────────────────────────────────────────────

section('Entries — write');
$r = post('/entries', "sid=$sid&tid=$tid", 'entry=/e2e/hello | Hello world.');
ok($r['status'] === 201,                       'POST /entries → 201');
ok(($r['json']['status'] ?? '') === 'ok',      'response status: ok');
ok(isset($r['json']['timestamp']),             'timestamp in response');

$r = post('/entries', "sid=$sid&tid=$tid", 'entry=/e2e/note | A note-');
ok($r['status'] === 201,                       'POST second entry → 201');

// ─── 4. Entries — read back ───────────────────────────────────────────────────

section('Entries — read back');
$r = get('/entries', "sid=$sid&tid=$tid&format=json&refresh");
ok($r['status'] === 200,                       'GET /entries after write → 200');
ok(isset($r['json']['/e2e/hello']),            '/e2e/hello in json');
ok(($r['json']['/e2e/hello']['message'] ?? '') === 'Hello world.', 'message correct');
ok(isset($r['json']['/e2e/note']),             '/e2e/note in json');

$r = get('/entries', "sid=$sid&tid=$tid&format=csv&refresh");
ok(str_contains($r['body'], '/e2e/hello'),     'entry in csv');

$r = get('/entries', "sid=$sid&tid=$tid&format=txt.0.2&refresh");
ok(str_contains($r['body'], '/e2e/hello'),     'entry in txt.0.2');

// ─── 5. Validation errors ────────────────────────────────────────────────────

section('Validation');
$r = post('/entries', "sid=$sid&tid=$tid", 'entry=no-pipes-here');
ok($r['status'] === 400,                       'malformed entry → 400');
ok(($r['json']['error']['code'] ?? '') === 'INVALID_ENTRY', 'INVALID_ENTRY code');

$r = get('/entries', "sid=$sid&tid=bad tid!&format=json");
ok($r['status'] === 400,                       'invalid tid → 400');
ok(($r['json']['error']['code'] ?? '') === 'INVALID_TID', 'INVALID_TID code');

$r = get('/entries', "sid=$sid&tid=$tid&format=unknown");
ok($r['status'] === 400,                       'unknown format → 400');
ok(($r['json']['error']['code'] ?? '') === 'INVALID_FORMAT', 'INVALID_FORMAT code');

// ─── 6. Votes ─────────────────────────────────────────────────────────────────

section('Votes');
$r = post('/votes', "sid=$sid&tid=$tid", "entry=/e2e/poll | votes:$sid:1 | Good idea?");
ok($r['status'] === 201,                       'POST /votes → 201');

$r = get('/votes', "sid=$sid&tid=$tid&format=json&refresh");
ok($r['status'] === 200,                       'GET /votes → 200');
ok(isset($r['json']['/e2e/poll']),             '/e2e/poll in json');
ok(isset($r['json']['/e2e/poll']['votes']),    'votes key present');

// ─── 7. Dumps ─────────────────────────────────────────────────────────────────

section('Dumps');
$r = post('/dumps', "sid=$sid", 'dump=E2E test diagnostic');
ok($r['status'] === 201,                       'POST /dumps → 201');
ok(($r['json']['status'] ?? '') === 'ok',      'dumps response ok');

// ─── 8. Files ─────────────────────────────────────────────────────────────────

section('Files');
$r = get('/files/no-such-file.xyz');
ok($r['status'] === 404,                       'GET /files/unlisted → 404');
ok(($r['json']['error']['code'] ?? '') === 'NOT_FOUND', 'NOT_FOUND code');

// ─── 9. SPA shell ─────────────────────────────────────────────────────────────

section('SPA shell');
$r = get('/');
ok($r['status'] === 200,                       'GET / → 200');
ok(str_contains($r['body'], '<html'),          'body is HTML');

// ─── 10. Throttle ─────────────────────────────────────────────────────────────

section('Throttle (skipped if throttle_max=0)');
$throttled = false;
for ($i = 0; $i < 20; $i++) {
    $r = post('/entries', 'sid=e2e_throttle&tid=' . $tid, 'entry=/e2e/spam | Spam.');
    if ($r['status'] === 429) { $throttled = true; break; }
}
if ($throttled) {
    ok(true,                                   '429 after repeated POSTs');
    ok(($r['json']['error']['code'] ?? '') === 'THROTTLED', 'THROTTLED code');
} else {
    echo "  SKIP  throttle not triggered (throttle_max=0 or > 20)\n";
}

// ─── issue.php ────────────────────────────────────────────────────────────────

section('issue.php — save report');

// POST with report text → 201
$r = post('issue.php', "sid=$sid&tid=$tid", 'report=Test+Fehlerbericht+%3A%29');
ok($r['status'] === 201, 'POST report → 201');
ok(($r['json']['status'] ?? '') === 'ok', 'body status = ok');

// Verify file was written to data/issues/new/
$files = glob('data/issues/new/*.md');
ok(count($files) > 0, 'issue file created in data/issues/');
if (count($files) > 0) {
    $content = file_get_contents($files[0]);
    ok(str_contains($content, 'Test Fehlerbericht'), 'report text in file');
}

// POST with empty report → 400
$r = post('issue.php', "sid=$sid&tid=$tid", 'report=');
ok($r['status'] === 400, 'empty report → 400');

// GET → 405
$r = get('issue.php', "sid=$sid&tid=$tid");
ok($r['status'] === 405, 'GET → 405');

// cleanup
foreach (glob('data/issues/new/*.md') as $f) unlink($f);

// ─── issue.php — edit ────────────────────────────────────────────────────────

section('issue.php — edit existing issue');

$testDir  = 'data/issues/new';
if (!is_dir($testDir)) mkdir($testDir, 0755, true);
$testFile = $testDir . '/test_edit_e2e.md';
file_put_contents($testFile, "# Original Title\nOriginal body.");

// Edit with valid filename → 200, content updated
$editBody = http_build_query([
    'report'   => "# Updated Title\nUpdated body.",
    'filename' => 'new/test_edit_e2e.md',
]);
$r = post('issue.php', '', $editBody);
ok($r['status'] === 200,                           'POST edit with filename → 200');
ok(($r['json']['status'] ?? '') === 'ok',          'body status = ok');
ok(
    file_get_contents($testFile) === "# Updated Title\nUpdated body.",
    'file content updated on disk'
);

// Non-existent filename → 404
$r = post('issue.php', '', http_build_query(['report' => 'x', 'filename' => 'new/nonexistent.md']));
ok($r['status'] === 404, 'non-existent filename → 404');

// Path traversal → 404
$r = post('issue.php', '', http_build_query(['report' => 'x', 'filename' => '../../../etc/passwd']));
ok($r['status'] === 404, 'path traversal → 404');

// Creation path still works (no filename)
$r = post('issue.php', '', http_build_query(['report' => 'Creation still works']));
ok($r['status'] === 201, 'creation path (no filename) still returns 201');

// cleanup
if (file_exists($testFile)) unlink($testFile);
foreach (glob('data/issues/new/*.md') as $f) {
    if (str_contains($f, uniqid('', false))) unlink($f);
}
// clean up the creation test file
foreach (glob('data/issues/new/????-??-??_*.md') as $f) {
    $content = file_get_contents($f);
    if (str_contains($content, 'Creation still works')) unlink($f);
}

// ─── Teardown ─────────────────────────────────────────────────────────────────

foreach (['data/entries_e2e.csv', 'data/votes_e2e.csv', 'data/entries_e2e.cache'] as $f) {
    if (file_exists($f)) unlink($f);
}

// ─── entries.php — long-poll behaviour ───────────────────────────────────────
section('entries.php — long-poll');

// POST a known entry so the source file exists; sleep 2s so the entry ages past
// the 1-second grace window in _filter_since before since-based GETs run.
post('entries.php', "sid=$sid&tid=$tid", 'entry=/longpoll/test | Node for poll.');
sleep(2);

// GET without ?since — must return 200 immediately (under 1s).
$t0 = microtime(true);
$r  = get('entries.php', "tid=$tid");
$elapsed = microtime(true) - $t0;
ok($r['status'] === 200,    'GET without since → 200');
ok($elapsed < 1.0,          'GET without since → fast (no hold)', round($elapsed, 2) . 's');

// GET with ?since far in future — must return 204 immediately (no hold).
$t0 = microtime(true);
$r  = get('entries.php', "tid=$tid&since=2099-01-01+00:00:00");
$elapsed = microtime(true) - $t0;
ok($r['status'] === 204,    'GET since future → 204');
ok($elapsed < 1.0,          'GET since future → immediate (no hold)', round($elapsed, 2) . 's');

// GET with ?since=<very old> — data exists → 200 immediately.
$t0 = microtime(true);
$r  = get('entries.php', "tid=$tid&since=2000-01-01+00:00:00");
$elapsed = microtime(true) - $t0;
ok($r['status'] === 200,    'GET since past → 200 with data');
ok($elapsed < 1.0,          'GET since past → fast (data exists)', round($elapsed, 2) . 's');

// Cleanup long-poll test data.
foreach (['data/entries_e2e.csv', 'data/entries_e2e.cache'] as $f) {
    if (file_exists($f)) unlink($f);
}

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

// ─── data.php ────────────────────────────────────────────────────────────────
section('data.php');

// Patch poll_timeout to 0 so data.php tests don't long-poll
$orig_cfg3 = file_get_contents('infopedia.cfg');
register_shutdown_function(function() use ($orig_cfg3) {
    file_put_contents('infopedia.cfg', $orig_cfg3);
});
$patched3 = preg_replace('/^(\[data\].*?)poll_timeout\s*=\s*\d+/ms', '$1poll_timeout = 0', $orig_cfg3);
file_put_contents('infopedia.cfg', $patched3);

// Ensure infopedia.log exists (E2E may create it via prior requests)
if (!file_exists('infopedia.log')) file_put_contents('infopedia.log', '');

// E1: no entity → 400 INVALID_ENTITY
$r = get('/data');
ok($r['status'] === 400, 'data: no entity → 400');
ok(($r['json']['error']['code'] ?? '') === 'INVALID_ENTITY', 'data: error code INVALID_ENTITY');

// E2: entity=stats → 200, full + increments + offset
$r = get('/data', 'entity=stats');
ok($r['status'] === 200, 'data: stats first load → 200');
ok(isset($r['json']['offset']),           'data: stats has offset');
ok(isset($r['json']['full']),             'data: stats has full section');
ok(isset($r['json']['increments']),       'data: stats has increments section');
ok(isset($r['json']['full']['sessions_uniq']), 'data: full has sessions_uniq');
ok(isset($r['json']['increments']['requests']), 'data: increments has requests');
ok(is_array($r['json']['increments']['rows'] ?? null), 'data: increments has rows array');

// E3: stale offset → 400 STALE_OFFSET
$r = get('/data', 'entity=stats&offset=999999999');
ok($r['status'] === 400, 'data: stale offset → 400');
ok(($r['json']['error']['code'] ?? '') === 'STALE_OFFSET', 'data: error code STALE_OFFSET');

// E4: entity=ops, no cursor → 200 or 204
$r = get('/data', 'entity=ops&tid=' . $tid);
ok(in_array($r['status'], [200, 204], true), 'data: ops no cursor → 200 or 204');

// E5: append an ops event, then poll → 200 with row
// Write ops event directly to JSONL for deterministic E2E
$ops_file_a = 'data/notify_ops_' . $tid . '_a.jsonl';
$ops_file_b = 'data/notify_ops_' . $tid . '_b.jsonl';
foreach ([$ops_file_a, $ops_file_b] as $of) { if (file_exists($of)) unlink($of); }

$ts_e5 = time() - 5;
$ev_json = json_encode(['ts'=>$ts_e5,'msgid'=>1,'type'=>'ops',
                        'severity'=>'info','op'=>'deploy','text'=>'e2e test']) . "\n";
file_put_contents($ops_file_a, $ev_json);
file_put_contents($ops_file_b, $ev_json);

// Cursor: 10 seconds before the event; ts=0 would trigger stale detection
$cursor_ts = $ts_e5 - 10;
$r = get('/data', 'entity=ops&tid=' . $tid . '&ts=' . $cursor_ts . '&msgid=0');
ok($r['status'] === 200, 'data: ops with prior event → 200');
ok(count($r['json']['increments']['rows'] ?? []) >= 1, 'data: ops rows non-empty');
ok(($r['json']['increments']['rows'][0]['op'] ?? '') === 'deploy', 'data: ops row op=deploy');

// Cleanup ops files
foreach ([$ops_file_a, $ops_file_b] as $of) { if (file_exists($of)) unlink($of); }

// Restore cfg
file_put_contents('infopedia.cfg', $orig_cfg3);

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n";
echo ($fail === 0 ? "OK" : "FAIL") . " — $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
