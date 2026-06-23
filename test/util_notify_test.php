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
