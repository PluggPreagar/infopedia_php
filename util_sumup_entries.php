<?php
/*
 * util_sumup_entries.php
 * Entries-specific SumUp callbacks: merge_line and projection.
 * Plain procedural PHP 8.0+. No classes, no framework, no Composer.
 */

require_once __DIR__ . '/util_entry.php';

// ─── Entries callbacks ────────────────────────────────────────────────────────

/**
 * Merge one logical CSV line into entries SumUp nodes.
 * Node shape: [path => ['ts'=>normalised_ts, 'raw'=>raw_entry_col, 'deleted'=>bool]]
 * One logical line of sortCsvData()'s grouping semantics (newest-per-path wins;
 * `deleted` is stored, not unset, so re-creation by an even newer non-delete row
 * works correctly — matches sortCsvData() exactly).
 *
 * @param array  $nodes Current nodes
 * @param string $line  Raw CSV line ("ts,entry", entry possibly quoted)
 */
function entries_sumup_merge_line(array $nodes, string $line): array {
    $parts = str_getcsv($line, ',', '"', '\\');
    if (count($parts) < 2) {
        return $nodes;
    }

    $ts_raw = $parts[0];
    $entry  = $parts[1]; // decoded entry (no surrounding quotes)

    $ts_norm = _normalise_ts($ts_raw);
    if ($ts_norm === null) {
        return $nodes;
    }

    $entry_parts = explode(' | ', $entry);
    $path = trim($entry_parts[0]);

    $last_decoded = trim(end($entry_parts));
    $is_delete = ($last_decoded === '--');

    // Raw entry column exactly as it appeared (preserves original quoting) —
    // everything after the first comma (timestamp never contains one).
    $first_comma = strpos($line, ',');
    $raw_entry_col = ($first_comma !== false) ? substr($line, $first_comma + 1) : $line;

    if (!isset($nodes[$path]) || $ts_norm > $nodes[$path]['ts']) {
        $nodes[$path] = [
            'ts'      => $ts_norm,
            'raw'     => $raw_entry_col,
            'deleted' => $is_delete,
        ];
    }

    return $nodes;
}

/**
 * Render entries SumUp nodes as sorted CSV — byte-identical to sortCsvData()'s
 * output for the same logical content: ksort by path, emit "ts,raw", skip
 * deleted nodes, header always present.
 *
 * @param array $nodes SumUp nodes (from entries_sumup_merge_line)
 */
function entries_sumup_project(array $nodes): string {
    $header = "Timestamp,entry\n";

    if (empty($nodes)) {
        return $header;
    }

    ksort($nodes);

    $rows = [];
    foreach ($nodes as $path => $node) {
        if (!$node['deleted']) {
            $rows[] = $node['ts'] . ',' . $node['raw'];
        }
    }

    if (empty($rows)) {
        return $header;
    }

    return $header . implode("\n", $rows);
}

