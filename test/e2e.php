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

// Verify file was written to data/issues/
$files = glob('data/issues/*.txt');
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
foreach (glob('data/issues/*.txt') as $f) unlink($f);

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

// Lower poll_timeout to 2 for timing test.
$orig_cfg = file_get_contents('infopedia.cfg');
$patched  = preg_replace('/^poll_timeout\s*=.*/m', 'poll_timeout = 2', $orig_cfg);
file_put_contents('infopedia.cfg', $patched);

// GET without ?since — must return 200 immediately (under 1s).
$t0 = microtime(true);
$r  = get('entries.php', "tid=$tid");
$elapsed = microtime(true) - $t0;
ok($r['status'] === 200,    'GET without since → 200');
ok($elapsed < 1.0,          'GET without since → fast (no hold)', round($elapsed, 2) . 's');

// GET with ?since far in future — must hold ≥ 2s then return 204.
$t0 = microtime(true);
$r  = get('entries.php', "tid=$tid&since=2099-01-01+00:00:00");
$elapsed = microtime(true) - $t0;
ok($r['status'] === 204,    'GET since future → 204 after hold');
ok($elapsed >= 2.0,         'GET since future → held ≥ 2s', round($elapsed, 2) . 's');

// GET with ?since=<very old> — data exists → 200 immediately.
$t0 = microtime(true);
$r  = get('entries.php', "tid=$tid&since=2000-01-01+00:00:00");
$elapsed = microtime(true) - $t0;
ok($r['status'] === 200,    'GET since past → 200 with data');
ok($elapsed < 1.0,          'GET since past → fast (data exists)', round($elapsed, 2) . 's');

// Restore original cfg.
file_put_contents('infopedia.cfg', $orig_cfg);

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n";
echo ($fail === 0 ? "OK" : "FAIL") . " — $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
