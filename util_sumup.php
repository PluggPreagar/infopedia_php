<?php
/*
 * util_sumup.php
 * Generic offset-based SumUp helper: load/save/read_tail primitives.
 * Pattern extracted from stats_aggregate.cache (util_data.php).
 * Plain procedural PHP 8.0+. No classes, no framework, no Composer.
 */

require_once __DIR__ . '/util_entry.php';

// ─── sumup_load ───────────────────────────────────────────────────────────────

/**
 * Load a SumUp snapshot. Returns null if missing, corrupt, for a different src,
 * or STALE (offset > filesize(src)).
 *
 * @param string $file  SumUp JSON path
 * @param string $src   Source CSV path
 * @return ?array       ['src'=>string, 'offset'=>int, 'nodes'=>array] or null
 */
function sumup_load(string $file, string $src): ?array {
    if (!file_exists($file)) {
        return null;
    }

    $fp = @fopen($file, 'r');
    if (!$fp) {
        return null;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return null;
    }

    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($raw === false || $raw === '') {
        return null;
    }

    $data = @json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    // Validate src and offset
    if (($data['src'] ?? '') !== $src) {
        return null;
    }

    $offset = $data['offset'] ?? -1;
    if (!is_int($offset) || $offset < 0) {
        return null;
    }

    // Stale check: if offset > filesize, rebuild.
    // Only check if source file exists; if not, assume cache is still valid.
    if (file_exists($src)) {
        clearstatcache(true, $src);
        $filesize = filesize($src);
        if ($offset > $filesize) {
            return null;  // stale
        }
    }

    $nodes = $data['nodes'] ?? [];
    if (!is_array($nodes)) {
        return null;
    }

    return ['src' => $src, 'offset' => $offset, 'nodes' => $nodes];
}

// ─── sumup_save ───────────────────────────────────────────────────────────────

/**
 * Persist a SumUp snapshot — flock LOCK_EX + newer-wins re-check (same-src only).
 * Pattern: set/create file, lock exclusive, re-read to check if newer offset
 * already on disk, only write if ours is newer or first write.
 *
 * @param string $file   SumUp JSON path
 * @param string $src    Source CSV path
 * @param int    $offset Byte offset in src
 * @param array  $nodes  Aggregated nodes
 */
function sumup_save(string $file, string $src, int $offset, array $nodes): void {
    // Write new snapshot.
    $data = [
        'src'    => $src,
        'offset' => $offset,
        'nodes'  => $nodes,
    ];
    $json_str = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Use atomic write: write to temp file, then rename
    $tmpfile = $file . '.tmp.' . getmypid();
    $wrote = @file_put_contents($tmpfile, $json_str, LOCK_EX);
    if ($wrote === false) {
        return;
    }

    // Check if file exists and compare offsets before replacing
    // (fopen+stream_get_contents instead of file_get_contents: avoids a spurious
    // "Permission denied" on Windows when a handle to $file was very recently closed.)
    if (file_exists($file)) {
        $existing = null;
        $fp = @fopen($file, 'r');
        if ($fp) {
            $raw = stream_get_contents($fp);
            fclose($fp);
            $existing = json_decode($raw, true);
        }
        if (is_array($existing)
            && ($existing['src'] ?? '') === $src
            && ($existing['offset'] ?? -1) >= $offset) {
            // Newer or equal offset already on disk — skip write.
            @unlink($tmpfile);
            return;
        }
    }

    // Atomically replace
    @rename($tmpfile, $file);
}

// ─── sumup_read_tail ──────────────────────────────────────────────────────────

/**
 * Read bytes from $offset to EOF, split on "\n", join wrapped quoted rows,
 * and return only COMPLETE logical lines (no partial trailing line, no
 * quote-unbalanced fragment). Offset math is in bytes (physical file positions).
 *
 * @param string        $src    Source file path
 * @param int           $offset Byte offset to start reading
 * @param ?callable     $joiner Line joiner: fn(string[] $physicalLines): string[].
 *                              Default (omitted / not passed) is `csv_join_wrapped_lines`
 *                              (CSV quote-aware joining, used by votes/entries).
 *                              Pass `null` explicitly for identity mode (plain lines,
 *                              e.g. the stats log which is not CSV-quoted).
 * @return array         ['lines'=>string[], 'new_offset'=>int]
 */
function sumup_read_tail(string $src, int $offset, /* ?callable */ $joiner = -1): array {
    if ($joiner === -1) {
        $joiner = 'csv_join_wrapped_lines';
    }
    clearstatcache(true, $src);
    $filesize = file_exists($src) ? filesize($src) : 0;

    if ($offset >= $filesize) {
        return ['lines' => [], 'new_offset' => $offset];
    }

    $fp = @fopen($src, 'r');
    if (!$fp) {
        return ['lines' => [], 'new_offset' => $offset];
    }

    if (fseek($fp, $offset) !== 0) {
        fclose($fp);
        return ['lines' => [], 'new_offset' => $offset];
    }

    // Read remaining bytes, tracking byte positions.
    // Only complete lines (ending with \n) are considered complete.
    $physical_lines = [];
    $byte_pos = $offset;

    while (($line = fgets($fp)) !== false) {
        // fgets includes the \n if present
        $physical_lines[] = ['line' => rtrim($line, "\n"), 'bytes' => strlen($line)];
        $byte_pos += strlen($line);
    }
    fclose($fp);

    // Filter out lines without trailing newline (incomplete lines at EOF)
    // Keep only lines where fgets added the newline (i.e., bytes > len(rtrimmed_line))
    $complete_physical = [];
    foreach ($physical_lines as $item) {
        $trimmed_len = strlen($item['line']);
        // If bytes > trimmed_len, the newline was present (fgets read it)
        if ($item['bytes'] > $trimmed_len) {
            $complete_physical[] = $item;
        }
    }

    // Join wrapped quoted lines (odd quote count means line continues).
    // Identity mode ($joiner === null): each complete physical line is already a
    // logical line — no CSV quote joining (used for the plain-text stats log).
    if ($joiner === null) {
        $logical_lines = array_map(fn($x) => $x['line'], $complete_physical);
    } else {
        $logical_lines = $joiner(array_map(fn($x) => $x['line'], $complete_physical));
    }

    // Filter out incomplete (unbalanced) lines — keep only complete logical lines.
    // Identity mode: every physical line is complete by definition (no quote balancing).
    if ($joiner === null) {
        $complete_logical = $logical_lines;
    } else {
        $complete_logical = [];
        foreach ($logical_lines as $line) {
            // A complete line has an even number of quotes
            if (substr_count($line, '"') % 2 === 0) {
                $complete_logical[] = $line;
            }
        }
    }

    // Compute new_offset: only complete logical lines are consumed.
    // A logical line spans one or more physical lines.
    // We need to find how many physical lines were consumed to form the complete logical lines.
    $new_offset = $offset;
    $physical_idx = 0;

    if ($joiner === null) {
        // Identity mode: exactly 1 physical line == 1 logical line.
        foreach ($complete_logical as $logical_line) {
            $new_offset += $complete_physical[$physical_idx]['bytes'];
            $physical_idx++;
        }
    } else {
        foreach ($complete_logical as $logical_line) {
            // Count the physical lines that form this logical line.
            // A logical line's physical components sum to the logical line + (\n separators).
            // We use quote balance to know how many physical lines to consume.
            $quote_count = 0;
            $physical_consumed = 0;

            while ($physical_idx < count($complete_physical)) {
                $phys_line = $complete_physical[$physical_idx]['line'];
                $phys_bytes = $complete_physical[$physical_idx]['bytes'];

                // Count quotes in this physical line
                $quote_count += substr_count($phys_line, '"');
                $physical_consumed++;
                $new_offset += $phys_bytes;
                $physical_idx++;

                // If quote count is even, this logical line is complete
                if ($quote_count % 2 === 0) {
                    break;
                }
            }
        }
    }

    return ['lines' => $complete_logical, 'new_offset' => $new_offset];
}

// ─── sumup_update ─────────────────────────────────────────────────────────────

/**
 * Load-or-rebuild + tail-merge + conditional save. Returns current nodes.
 * Core invariant: incremental result == full-rebuild result.
 *
 * @param string   $file        SumUp JSON path
 * @param string   $src         Append-only source CSV
 * @param callable $merge_line  fn(array $nodes, string $logical_line): array
 * @param callable $init_nodes  fn(): array — initial nodes for fresh rebuild
 */
function sumup_update(string $file, string $src, callable $merge_line, callable $init_nodes): array {
    $loaded = sumup_load($file, $src);
    $nodes  = $loaded ? $loaded['nodes']  : $init_nodes();
    $offset = $loaded ? $loaded['offset'] : 0;

    $tail = sumup_read_tail($src, $offset);
    foreach ($tail['lines'] as $line) {
        if (str_starts_with($line, 'Timestamp,')) {
            continue;  // Skip header guard
        }
        $nodes = $merge_line($nodes, $line);
    }

    if ($tail['new_offset'] > $offset || $loaded === null) {
        sumup_save($file, $src, $tail['new_offset'], $nodes);
    }

    return $nodes;
}

// ─── Votes callbacks ──────────────────────────────────────────────────────────

/**
 * Merge one logical CSV line into votes SumUp nodes.
 * Node shapes:
 *   vote node:     ['by_sid'=>[sid=>int,...], 'signers'=>[sid=>1,...], 'ts'=>str, 'content'=>str]
 *   non-vote node: ['row'=>['ts'=>str, 'entry'=>str]]
 * Totals are NOT stored — derived at projection time (array_sum(by_sid)).
 *
 * @param array  $nodes Current nodes
 * @param string $line   CSV line (decoded entry column only — from sumup_read_tail)
 */
function votes_sumup_merge_line(array $nodes, string $line): array {
    // Parse CSV line
    $parts = str_getcsv($line, ',', '"', '\\');
    if (count($parts) < 2) {
        return $nodes;
    }

    $ts = $parts[0];
    $entry = $parts[1];

    $parsed = parseEntry($entry);
    $path = $parsed['path'];

    $is_vote_row = !empty($parsed['votes']) || !empty($parsed['signed']);
    $is_delete = ($parsed['type'] === '--');

    // Delete marker removes node
    if ($is_delete) {
        unset($nodes[$path]);
        return $nodes;
    }

    if ($is_vote_row) {
        // Initialize vote node if absent
        if (!isset($nodes[$path]) || !isset($nodes[$path]['by_sid'])) {
            $nodes[$path] = [
                'by_sid' => [],
                'signers' => [],
                'ts' => $ts,
                'content' => $parsed['content'],
            ];
        }

        // Add vote counts
        foreach ($parsed['votes'] as $sid => $count) {
            $nodes[$path]['by_sid'][$sid] = ($nodes[$path]['by_sid'][$sid] ?? 0) + $count;
        }

        // Merge signers (unique per sid with val > 0)
        foreach ($parsed['signed'] as $sid => $val) {
            if ($val > 0) {
                $nodes[$path]['signers'][$sid] = 1;
            }
        }

        // Update ts/content if this row is newer
        if ($ts > $nodes[$path]['ts']) {
            $nodes[$path]['ts'] = $ts;
            $nodes[$path]['content'] = $parsed['content'];
        }
    } else {
        // Non-vote row: passthrough, newest wins
        if (!isset($nodes[$path]) || !isset($nodes[$path]['row'])) {
            $nodes[$path] = [
                'row' => [
                    'ts' => $ts,
                    'entry' => $entry,
                ],
            ];
        } elseif (isset($nodes[$path]['row']) && $ts > $nodes[$path]['row']['ts']) {
            $nodes[$path]['row'] = [
                'ts' => $ts,
                'entry' => $entry,
            ];
        }
    }

    return $nodes;
}

/**
 * Render votes SumUp nodes as sorted CSV for one session's view.
 * Output: Timestamp,entry CSV (header + path-sorted rows).
 * Vote rows: votes:<own-sid>:<n> + votes:others:... + signed_count:...
 * Non-vote rows: passthrough (newest per path).
 *
 * @param array  $nodes      SumUp nodes (from votes_sumup_merge_line)
 * @param string $session_id Client's session ID
 */
function votes_sumup_project(array $nodes, string $session_id): string {
    $header = "Timestamp,entry\n";

    if (empty($nodes)) {
        return $header;
    }

    ksort($nodes);  // path-sorted

    $rows = [];
    foreach ($nodes as $path => $node) {
        if (isset($node['by_sid'])) {
            // Vote node: assemble vote attributes
            $own = $node['by_sid'][$session_id] ?? 0;
            $total = array_sum($node['by_sid']);
            $others = $total - $own;

            $vote_cols = '';
            if ($own !== 0) {
                $vote_cols .= ' | votes:' . $session_id . ':' . $own;
            }
            if ($others !== 0) {
                $vote_cols .= ' | votes:others:' . $others;
            }

            $signed_count = count($node['signers']);
            if ($signed_count > 0) {
                $vote_cols .= ' | signed_count:' . $signed_count;
            }

            $entry = $path . $vote_cols . ' | ' . $node['content'];

            // CSV quote if needed
            if (strpbrk($entry, ',"' . "\n") !== false) {
                $entry = '"' . str_replace('"', '""', $entry) . '"';
            }

            $rows[] = $node['ts'] . ',' . $entry;
        } elseif (isset($node['row'])) {
            // Non-vote row: passthrough
            $entry = $node['row']['entry'];

            if (strpbrk($entry, ',"' . "\n") !== false) {
                $entry = '"' . str_replace('"', '""', $entry) . '"';
            }

            $rows[] = $node['row']['ts'] . ',' . $entry;
        }
    }

    if (empty($rows)) {
        return $header;
    }

    return $header . implode("\n", $rows);
}

// ─── Entries callbacks (T12) ──────────────────────────────────────────────────

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


