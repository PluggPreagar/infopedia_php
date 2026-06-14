<?php
require_once 'util_test.php';
require_once 'util_entry.php'; // RED: does not exist yet

// parseEntryLine — old Sheet format
$r = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | Some fact."');
assert_equals($r['topic'],      '/clima',     'parseEntryLine: topic');
assert_equals($r['node'],       'biz',        'parseEntryLine: node');
assert_equals($r['content'],    'Some fact.', 'parseEntryLine: content');
assert_equals($r['entry_type'], '.',          'parseEntryLine: entry_type dot');

// parseEntryLine — delete marker
$r2 = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | --"');
assert_equals($r2['delete'], true, 'parseEntryLine: delete marker');

// sortAndDeduplicateCsv — last entry wins per topic+node key
$raw = implode("\n", [
    '01/01/2025 00:00:00,"/a | b | first."',
    '02/01/2025 00:00:00,"/a | b | second."',
]);
$out = sortAndDeduplicateCsv($raw);
assert_contains($out, 'second.',      'dedup: last entry wins');
assert_equals(substr_count($out, '/a | b'), 1, 'dedup: one entry per key');

// sortAndDeduplicateCsv — delete marker removes entry
$rawDel = implode("\n", [
    '01/01/2025 00:00:00,"/x | y | content."',
    '02/01/2025 00:00:00,"/x | y | --"',
]);
$outDel = sortAndDeduplicateCsv($rawDel);
assert_equals(str_contains($outDel, '/x | y'), false, 'dedup: delete removes entry');

// buildMostRecentEntry — injects /_/menu/Most-Recent-Entry prefix
$mre = buildMostRecentEntry('02/01/2025 00:00:00,"/a | b | hello."');
assert_contains($mre, '/_/menu/Most-Recent-Entry', 'MRE: prefix injected');

// formatEntry — converts parsed array to 0v02 string
$parsed = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | Some fact."');
$fmt = formatEntry($parsed);
assert_contains($fmt, '/clima/biz', 'formatEntry: topic/node path');
assert_contains($fmt, 'Some fact.', 'formatEntry: content preserved');

print_test_summary();

