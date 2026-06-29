<?php
/*
 * util_entry.php
 * Entry parsing, sorting, and vote aggregation helpers.
 * Plain procedural PHP 8.0+. No classes, no namespaces, no Composer.
 */

// ─── parseEntry ──────────────────────────────────────────────────────────────

/**
 * Parse a pipe-delimited entry string into its components.
 *
 * @param string $entry  The entry column value (quotes already stripped).
 * @return array{path:string, content:string, type:string, display_ts:string|null, attrs:array, votes:array}
 */
function parseEntry(string $entry): array {
    $result = [
        'path'       => '',
        'content'    => '',
        'type'       => '.',
        'display_ts' => null,
        'attrs'      => [],
        'votes'      => [],
        'signed'     => [],
    ];

    $columns = explode(' | ', $entry);

    $result['path'] = array_shift($columns);

    // Last column is always content.
    $raw_content = array_pop($columns);
    if ($raw_content === null) {
        // Only one column — treat as empty content.
        $raw_content = '';
    }

    // Detect delete marker.
    if (trim($raw_content) === '--') {
        $result['content'] = '--';
        $result['type']    = '--';
        return $result;
    }

    // Determine type from final character.
    $last_char = substr($raw_content, -1);
    if (in_array($last_char, ['.', '!', '?', '>', '-'], true)) {
        $result['content'] = $raw_content;
        $result['type']    = $last_char;
    } else {
        // Append '.' when no recognised type suffix.
        $result['content'] = $raw_content . '.';
        $result['type']    = '.';
    }

    // Process middle columns: display_ts, votes, attrs.
    foreach ($columns as $col) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $col)) {
            $result['display_ts'] = $col;
        } elseif (preg_match('/^votes:([^:]+):(-?\d+)$/', $col, $m)) {
            $result['votes'][$m[1]] = (int)$m[2];
        } elseif (preg_match('/^signed:([^:]+):(\d+)$/', $col, $m)) {
            $result['signed'][$m[1]] = (int)$m[2];
        } elseif (preg_match('/^([a-zA-Z_]+):(.+)$/', $col, $m)) {
            // Key is only the part before the first colon; value is the rest.
            $result['attrs'][$m[1]] = $m[2];
        } else {
            if (function_exists('log_warn')) {
                log_warn("parseEntry: unrecognised middle column: $col");
            }
        }
    }

    return $result;
}

// ─── sortCsvData ─────────────────────────────────────────────────────────────

/**
 * Normalise, sort, and deduplicate a raw CSV string.
 *
 * @param string $csv  Raw CSV string.
 * @return string      Normalised CSV, header + one line per surviving path.
 */
function sortCsvData(string $csv): string {
    $header = "Timestamp,entry\n";

    // Normalise line endings.
    $csv = str_replace("\r\n", "\n", $csv);
    $lines = explode("\n", $csv);

    // Consume the header (first non-empty line).
    while ($lines && trim($lines[0]) === '') {
        array_shift($lines);
    }
    if ($lines) {
        array_shift($lines); // discard header row
    }

    // Aggregate wrapped quoted lines.
    // A line that has an odd number of double-quotes continues on the next line.
    $complete = [];
    $buf = '';
    foreach ($lines as $line) {
        if ($buf === '') {
            $buf = $line;
        } else {
            // Join with a literal \n (the content contained a real newline).
            $buf .= "\\n" . $line;
        }
        if (substr_count($buf, '"') % 2 === 0) {
            if ($buf !== '') {
                $complete[] = $buf;
            }
            $buf = '';
        }
        // odd count → continue accumulating
    }
    if ($buf !== '') {
        $complete[] = $buf;
    }

    // groups[path] = ['ts' => normalised_ts, 'raw_entry_col' => string, 'deleted' => bool]
    // raw_entry_col is the entry portion of the CSV line exactly as it appeared
    // (including surrounding quotes if any), so we can round-trip it unchanged.
    $groups = [];

    foreach ($complete as $line) {
        if (trim($line) === '') {
            continue;
        }

        // Use str_getcsv to decode for path/delete detection only.
        $parts = str_getcsv($line, ',', '"', '\\');
        if (count($parts) < 2) {
            continue;
        }

        $ts_raw = $parts[0];
        $entry  = $parts[1]; // decoded entry (no surrounding quotes)

        $ts_norm = _normalise_ts($ts_raw);
        if ($ts_norm === null) {
            if (function_exists('log_warn')) {
                log_warn("sortCsvData: could not normalise timestamp: $ts_raw");
            }
            continue;
        }

        // Determine path (first ' | '-delimited segment of the decoded entry).
        $entry_parts = explode(' | ', $entry);
        $path = trim($entry_parts[0]);

        // Delete detection: content is the last segment.
        $last_decoded = trim(end($entry_parts));
        $is_delete = ($last_decoded === '--');

        // Extract the raw entry column from the line, preserving original quoting.
        // The timestamp ends at the first comma not inside a quoted field.
        // Since the timestamp itself never contains commas or quotes, we can
        // simply find the first comma and take everything after it.
        $first_comma = strpos($line, ',');
        $raw_entry_col = ($first_comma !== false) ? substr($line, $first_comma + 1) : $line;

        if (!isset($groups[$path]) || $ts_norm > $groups[$path]['ts']) {
            $groups[$path] = [
                'ts'            => $ts_norm,
                'raw_entry_col' => $raw_entry_col,
                'deleted'       => $is_delete,
            ];
        }
    }

    // Sort by path ascending.
    ksort($groups);

    // Reconstruct output — emit the raw entry column as-is (already correctly quoted).
    $rows = [];
    foreach ($groups as $path => $row) {
        if ($row['deleted']) {
            continue;
        }
        $rows[] = $row['ts'] . ',' . $row['raw_entry_col'];
    }

    // Header always ends with \n. Data rows are joined by \n, no trailing \n.
    if (empty($rows)) {
        return $header;
    }
    return $header . implode("\n", $rows);
}

/**
 * Normalise a timestamp string to YYYY-MM-DD HH:MM:SS.
 * Handles DD/MM/YYYY HH:MM:SS and YYYY-MM-DD HH:MM:SS (and swapped YYYY-DD-MM).
 *
 * @internal
 */
function _normalise_ts(string $ts): ?string {
    $ts = trim($ts);

    // DD/MM/YYYY HH:MM:SS
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4}) (\d{2}:\d{2}:\d{2})$#', $ts, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1] . ' ' . $m[4];
    }

    // YYYY-MM-DD HH:MM:SS or YYYY-DD-MM HH:MM:SS
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})$#', $ts, $m)) {
        // If the second field > 12 it must be a day, not a month (YYYY-DD-MM).
        if ((int)$m[2] > 12) {
            return $m[1] . '-' . $m[3] . '-' . $m[2] . ' ' . $m[4];
        }
        return $ts;
    }

    return null;
}

// ─── aggregateVotes ──────────────────────────────────────────────────────────

/**
 * Aggregate vote rows in a sorted CSV string.
 *
 * Non-vote rows pass through unchanged. For each path that has vote rows:
 *   - own sid (matches $session_id) totals are preserved as votes:<sid>:<n>
 *   - all other sids are summed into votes:others:<n>
 * The aggregated row uses the latest outer timestamp and the content from that row.
 *
 * @param string $csv         Sorted CSV (output of sortCsvData).
 * @param string $session_id  The caller's session id.
 * @return string             Sorted CSV with vote rows aggregated.
 */
function aggregateVotes(string $csv, string $session_id): string {
    $header = "Timestamp,entry\n";

    $lines = explode("\n", $csv);

    // Consume header.
    $hdr = array_shift($lines);
    // (We always emit our own canonical header regardless.)

    // Separate vote rows from non-vote rows, preserving order.
    // $ordered_paths tracks insertion order for the output.
    $non_vote_rows  = []; // path => ['ts'=>..., 'entry'=>..., 'raw_line'=>...]
    $vote_groups    = []; // path => ['ts'=>..., 'content'=>..., 'own'=>n, 'others'=>n, 'signers'=>[sid=>1,...]]
    $ordered_paths  = []; // list of [type=>'vote'|'non_vote', path=>...]

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $parts = str_getcsv($line, ',', '"', '\\');
        if (count($parts) < 2) {
            continue;
        }

        $ts    = $parts[0];
        $entry = $parts[1];

        $parsed = parseEntry($entry);
        $path   = $parsed['path'];

        $is_vote_row = !empty($parsed['votes']) || !empty($parsed['signed']);

        if ($is_vote_row) {
            // Vote/signed row.
            if (!isset($vote_groups[$path])) {
                $vote_groups[$path] = [
                    'ts'      => $ts,
                    'content' => $parsed['content'],
                    'own'     => 0,
                    'others'  => 0,
                    'signers' => [],
                ];
                $ordered_paths[] = ['type' => 'vote', 'path' => $path];
            }

            // Update timestamp and content if this row is newer.
            if ($ts > $vote_groups[$path]['ts']) {
                $vote_groups[$path]['ts']      = $ts;
                $vote_groups[$path]['content'] = $parsed['content'];
            }

            foreach ($parsed['votes'] as $sid => $count) {
                if ($sid === $session_id) {
                    $vote_groups[$path]['own'] += $count;
                } else {
                    $vote_groups[$path]['others'] += $count;
                }
            }

            // Aggregate signers: track unique sids (latest value wins).
            foreach ($parsed['signed'] as $sid => $val) {
                if ($val > 0) {
                    $vote_groups[$path]['signers'][$sid] = 1;
                }
            }
        } else {
            // Non-vote row.
            if (!isset($non_vote_rows[$path])) {
                $ordered_paths[] = ['type' => 'non_vote', 'path' => $path];
            }
            $non_vote_rows[$path] = ['ts' => $ts, 'entry' => $entry];
        }
    }

    // Reconstruct output preserving order.
    $rows = [];
    foreach ($ordered_paths as $item) {
        $path = $item['path'];
        if ($item['type'] === 'non_vote') {
            $entry = $non_vote_rows[$path]['entry'];
            $ts    = $non_vote_rows[$path]['ts'];
            if (strpbrk($entry, ',"' . "\n") !== false) {
                $entry = '"' . str_replace('"', '""', $entry) . '"';
            }
            $rows[] = $ts . ',' . $entry;
        } else {
            $g       = $vote_groups[$path];
            $ts      = $g['ts'];
            $content = $g['content'];

            // Build vote columns.
            $vote_cols = '';
            if ($g['own'] !== 0) {
                $vote_cols .= ' | votes:' . $session_id . ':' . $g['own'];
            }
            if ($g['others'] !== 0) {
                $vote_cols .= ' | votes:others:' . $g['others'];
            }
            // Emit aggregated signer count as a regular attr so csv_to_json picks it up.
            $signed_count = count($g['signers']);
            if ($signed_count > 0) {
                $vote_cols .= ' | signed_count:' . $signed_count;
            }

            $entry = $path . $vote_cols . ' | ' . $content;
            if (strpbrk($entry, ',"' . "\n") !== false) {
                $entry = '"' . str_replace('"', '""', $entry) . '"';
            }
            $rows[] = $ts . ',' . $entry;
        }
    }

    if (empty($rows)) {
        return $header;
    }
    return $header . implode("\n", $rows);
}
