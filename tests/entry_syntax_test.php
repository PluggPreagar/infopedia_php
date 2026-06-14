<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_entry.php';

// Old Google Sheet CSV: timestamp,"/topic | node | message"
$old = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | Some fact."');
assert_equals($old['timestamp'], '14/09/2025 07:17:33', 'old syntax: timestamp');
assert_equals($old['topic'], '/clima', 'old syntax: topic');
assert_equals($old['node'], 'biz', 'old syntax: node');
assert_equals($old['content'], 'Some fact.', 'old syntax: content');
assert_equals($old['entry_type'], '.', 'old syntax: entry type from last char');
assert_equals($old['delete'], false, 'old syntax: not delete');

// Old delete marker: content "--"
$delete = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | --"');
assert_equals($delete['delete'], true, 'old syntax: delete marker');

// 0v02 input/output: /path/node,timestamp,message[,vote]
$new = parseEntryLine('/clima/biz,2025-09-14 07:17:33,Some fact.');
assert_equals($new['topic'], '/clima', '0v02 syntax: topic');
assert_equals($new['node'], 'biz', '0v02 syntax: node');
assert_equals($new['timestamp'], '2025-09-14 07:17:33', '0v02 syntax: timestamp');
assert_equals($new['content'], 'Some fact.', '0v02 syntax: content');
assert_equals($new['entry_type'], '.', '0v02 syntax: entry type from last char');

// Optional fourth column is vote payload.
$vote = parseEntryLine('/clima/biz,2025-09-14 07:17:33,Some fact.,+1');
assert_equals($vote['vote'], '+1', '0v02 syntax: optional vote column');

// Literal \n in 0v02 input decodes to a real newline.
$multiline = parseEntryLine('/clima/biz,2025-09-14 07:17:33,"Line one\\nLine two"');
assert_equals($multiline['content'], "Line one\nLine two", '0v02 syntax: literal newline decoded');

// formatEntry emits old parsed row as 0v02 line and escapes real newlines as literal \n.
$formatted = formatEntry([
    'topic' => '/clima',
    'node' => 'biz',
    'timestamp' => '2025-09-14 07:17:33',
    'content' => "Line one\nLine two",
]);
assert_equals($formatted, '/clima/biz,2025-09-14 07:17:33,Line one\nLine two', 'formatEntry: 0v02 output syntax');

print_test_summary();

