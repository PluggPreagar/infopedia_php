# TASK-P1-2 · Create `util_entry.php` (GREEN)

**Step:** S4 — Implement
**Phase:** 1 — extract entry helper
**~5 min** | Depends on: [TASK-P1-1](TASK-P1-1-util-entry-red.md) must be RED first

## Constitution refs
- `CP1` — procedural PHP, no classes, reuse pattern
- `CP3` — data-format fidelity: old Sheet format + 0v02, delete marker `--`, DOS line-endings, wrapped multi-line quotes
- `CA6` — algorithm in isolated, testable function
- `CA7` — extracted from `read.php:21-120` + `infopedia.php:84-137` (re-use, don't copy-paste)
- `CD1` — output identical to old `sortCsvData()` (backward compatible)
- `CC5` — self-documenting names, short `// why` comments only

## Step requirements
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md)
- REQ-S4-1: minimal code to pass GREEN
- REQ-S4-2: plain procedural PHP, reuse helpers
- REQ-S4-8: non-trivial logic in separate side-effect-free functions
- REQ-S4-9: parse robustly (doubled `""`, multi-line, date variants) via `formatEntry()`

## Files
| Action | File | Source |
|---|---|---|
| CREATE | `util_entry.php` | extracted from `read.php:21-120`, `infopedia.php:84-137` |

## Code — `util_entry.php`

```php
<?php
// Entry parsing and formatting helpers (CA6, CP3)

/**
 * Parse one CSV line in old Sheet format:
 *   timestamp,"/topic | node | message"
 * Returns array: timestamp, topic, node, content, entry_type, delete, raw
 */
function parseEntryLine(string $line): array {
    $parts = str_getcsv($line);
    if (count($parts) < 2) {
        return [];
    }
    $entry = $parts[1];
    if (str_starts_with($entry, '|')) {
        $entry = ' ' . $entry; // normalize leading pipe (seen in some exports)
    }
    $segments = explode(' | ', $entry, 3);
    if (count($segments) < 3) {
        return [];
    }
    [$topic, $node, $content] = $segments;
    $delete = str_ends_with(rtrim($line, '"'), '--');
    return [
        'timestamp'  => $parts[0],
        'topic'      => $topic,
        'node'       => $node,
        'content'    => $content,
        'entry_type' => substr($content, -1),
        'delete'     => $delete,
        'raw'        => $line,
    ];
}

/**
 * Aggregate lines by topic+node key (last wins), sort by key, strip deleted.
 * Handles DOS line-endings, wrapped multi-line quoted messages, delete marker "--".
 * Output is identical to the old sortCsvData() — backward-compatible (CD1, CP3).
 */
function sortAndDeduplicateCsv(string $csvData): string {
    $csvData = str_replace("\r\n", "\n", $csvData);
    $lines   = explode("\n", $csvData);
    $aggregated = [];
    $wrapped    = '';

    foreach ($lines as $raw) {
        $line = $wrapped . $raw;
        if (substr_count($line, '"') % 2 !== 0) {
            // odd quotes → line wraps to next row (CP3)
            $wrapped = $line . "\n";
            continue;
        }
        $wrapped = '';
        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue;
        }
        $segs = explode(' | ', $parts[1]);
        if (count($segs) < 2) {
            continue;
        }
        $key      = $segs[0] . ' | ' . $segs[1];
        $isDelete = str_ends_with($line, '--') || str_ends_with($line, '--"');
        $aggregated[$key] = $isDelete ? '' : $line;
    }

    $mostRecent = _findMostRecentEntry($aggregated);
    ksort($aggregated);

    $out = '';
    foreach ($aggregated as $line) {
        if ($line !== '') {
            $out .= $line . "\n";
        }
    }
    if ($mostRecent !== '') {
        $out .= $mostRecent . "\n";
    }
    return trim($out);
}

/**
 * Build synthetic /_/menu/Most-Recent-Entry line for easy client access (CD3).
 */
function buildMostRecentEntry(string $line): string {
    if ($line === '') {
        return '';
    }
    $mre = preg_replace('/ \| /', '/', $line, 1); // first " | " → "/" to flatten path
    $pos = strpos($mre, ',') + 1;
    if (isset($mre[$pos]) && $mre[$pos] === '"') {
        $pos++; // skip opening quote when present
    }
    return substr_replace($mre, '/_/menu/Most-Recent-Entry | ', $pos, 0);
}

/** @internal */
function _findMostRecentEntry(array $aggregated): string {
    foreach (array_reverse($aggregated, true) as $key => $line) {
        if (!str_starts_with($key, '/_') && $line !== '') {
            return buildMostRecentEntry($line);
        }
    }
    return '';
}

/**
 * Convert parsed entry array → 0v02 wire format (CP3, CD1).
 *   old: timestamp,"/topic | node | message"
 *   0v02: /topic/node,timestamp,message  (newlines as \n)
 */
function formatEntry(array $parsed): string {
    if (empty($parsed)) {
        return '';
    }
    return $parsed['topic'] . '/' . $parsed['node']
        . ',' . $parsed['timestamp']
        . ',' . str_replace("\n", '\n', $parsed['content']);
}
```

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_entry_test.php
# Expected: PASS: 7  FAIL: 0  exit 0  (GREEN ✓)
```

## Commit

```
feat(entry): add util_entry.php — parseEntryLine, sortAndDeduplicateCsv, formatEntry
Extracted from read.php + infopedia.php; backward-compatible output (CD1, CP3).
Refs: CP1, CP3, CA6, CA7, CD1, CC5, REQ-S4-1, REQ-S4-8, REQ-S4-9
```
SemVer: MINOR (new helper, no breaking change) — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P1b-1](TASK-P1b-1-util-file-red.md)

