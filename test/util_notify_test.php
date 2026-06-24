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
