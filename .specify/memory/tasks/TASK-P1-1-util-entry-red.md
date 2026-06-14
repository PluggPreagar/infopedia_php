# TASK-P1-1 · Write Failing Tests for `util_entry.php` (RED)

**Step:** S3 — Failing Test
**Phase:** 1 — extract entry helper
**~2 min** | Depends on: [TASK-P0-1](TASK-P0-1-util-test-harness.md)

## Constitution refs
- `CA6` — testable algorithms: `parseEntryLine`, `sortAndDeduplicateCsv`, `buildMostRecentEntry`
- `CA11` — test-driven: write failing test first
- `CP3` — data-format fidelity: old Sheet format, delete marker, MRE
- `CC1.1`, `CC1.3` — capture behavior, turn into failing test (RED)

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md)
- REQ-S3-1: write test first, watch it FAIL
- REQ-S3-3: format/parse change → `*_test.php` case
- REQ-S3-5: use project harness (`assert_equals`, `print_test_summary`)

## Files
| Action | File |
|---|---|
| CREATE | `util_entry_test.php` |
| NOT YET | `util_entry.php` (created in TASK-P1-2) |

## Code — `util_entry_test.php`

```php
<?php
require_once 'util_test.php';
require_once 'util_entry.php'; // RED: does not exist yet → fatal error expected

// parseEntryLine — old Sheet format
$r = parseEntryLine('14/09/2025 07:17:33,"/clima | biz | Some fact."');
assert_equals($r['topic'],      '/clima',     'parseEntryLine: topic');
assert_equals($r['node'],       'biz',        'parseEntryLine: node');
assert_equals($r['content'],    'Some fact.', 'parseEntryLine: content');
assert_equals($r['entry_type'], '.',          'parseEntryLine: entry_type dot');

// parseEntryLine — delete marker "--"
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
assert_contains($fmt, '/clima/biz',   'formatEntry: topic/node path');
assert_contains($fmt, 'Some fact.',   'formatEntry: content preserved');

print_test_summary();
```

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_entry_test.php
# Expected: PHP Fatal error (util_entry.php not found) — RED confirmed ✓
```

## Commit

```
test(entry): add failing tests for util_entry.php (RED)
Refs: CA6, CA11, CP3, CC1.1, CC1.3, REQ-S3-1, REQ-S3-3, REQ-S3-5
```

## Next task
→ [TASK-P1-2](TASK-P1-2-util-entry-green.md) — implement `util_entry.php` (GREEN)

