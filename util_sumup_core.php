<?php
/*
 * util_sumup_core.php
 * Generic offset-based SumUp primitives: load/save/read_tail/update.
 * Plain procedural PHP 8.0+. No classes, no framework, no Composer.
 */

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

